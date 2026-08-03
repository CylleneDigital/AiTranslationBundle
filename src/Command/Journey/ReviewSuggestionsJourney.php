<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Override\OverrideChange;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueInvalidException;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Suggestion\PlaceholderMismatchException;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionAlreadyReviewedException;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionAutocorrector;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionReviewer;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionValueMissingException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Guided review of the pending suggestions: an overview table, an optional narrowing
 * step (a filter is only proposed when it actually discriminates — one locale in the
 * queue means no locale question), then one decision per suggestion: approve, edit,
 * reject, skip or quit. Every safeguard of the approval flow applies — the
 * placeholder guard and the catalogue-syntax validation run inside approve().
 */
#[AsTaggedItem(priority: 90)]
final class ReviewSuggestionsJourney implements JourneyInterface
{
    private const string GLOBAL_SCOPE = 'No scope (global)';
    private const string REVIEW_ALL = 'Review them all';
    private const string REVIEW_ONE = 'Pick one suggestion by id';
    private const string REVIEW_DELETE_ERRORED = 'Delete all errored suggestions';
    private const string REVIEW_NONE = 'Nothing for now';

    /** What one approval attempt came to — a refusal keeps the card open, the others close it. */
    private const string APPROVED = 'approved';
    private const string REFUSED = 'refused';
    private const string GONE = 'gone';

    public function __construct(
        private readonly TranslationSuggestionRepository $suggestionRepository,
        private readonly SuggestionReviewer $reviewer,
        private readonly SuggestionPresenter $presenter,
        private readonly SuggestionAutocorrector $autocorrector,
        private readonly ContextualFilter $filter,
        private readonly ValuePrompt $valuePrompt,
    ) {
    }

    public function getLabel(): string
    {
        return 'Review the pending suggestions';
    }

    public function run(SymfonyStyle $io): int
    {
        $pending = $this->suggestionRepository->findPending(null, null, null);

        if ([] === $pending) {
            $io->info('No pending suggestion to review.');

            return Command::SUCCESS;
        }

        $pending = $this->narrow($io, $pending);

        if ([] === $pending) {
            $io->info('No pending suggestion matches these filters.');

            return Command::SUCCESS;
        }

        $this->presenter->pendingTable($io, $pending);

        // A single row deserves a plain yes/no; a longer queue can also be entered on
        // one precise suggestion, straight from the table's ids.
        if (1 === \count($pending)) {
            return $io->confirm('Review this suggestion now?', true)
                ? $this->review($io, $pending)
                : Command::SUCCESS;
        }

        $choice = $io->choice(
            'How do you want to proceed?',
            [self::REVIEW_ALL, self::REVIEW_ONE, self::REVIEW_DELETE_ERRORED, self::REVIEW_NONE],
            self::REVIEW_ALL,
        );

        if (self::REVIEW_NONE === $choice) {
            return Command::SUCCESS;
        }

        if (self::REVIEW_DELETE_ERRORED === $choice) {
            return $this->deleteErrored($io, $pending);
        }

        if (self::REVIEW_ONE === $choice) {
            $pending = [$this->askOneById($io, $pending)];
        }

        return $this->review($io, $pending);
    }

    /**
     * One suggestion of the narrowed queue, picked by its id from the table.
     *
     * @param list<TranslationSuggestion> $pending
     */
    private function askOneById(SymfonyStyle $io, array $pending): TranslationSuggestion
    {
        $byId = [];

        foreach ($pending as $suggestion) {
            $byId[(int) $suggestion->getId()] = $suggestion;
        }

        $question = new Question('Suggestion id');
        $question->setValidator(static function (mixed $answer) use ($byId): int {
            $answer = \is_string($answer) ? trim($answer) : '';

            if (!ctype_digit($answer) || !isset($byId[(int) $answer])) {
                throw new \InvalidArgumentException(\sprintf('No suggestion with id "%s" in the table above.', $answer));
            }

            return (int) $answer;
        });

        $id = $io->askQuestion($question);

        return $byId[\is_int($id) ? $id : 0];
    }

    /**
     * The narrowing step: locale, catalogue and scope filters, each proposed only when
     * the queue spans more than one value for it.
     *
     * @param list<TranslationSuggestion> $pending
     *
     * @return list<TranslationSuggestion>
     */
    private function narrow(SymfonyStyle $io, array $pending): array
    {
        $pending = $this->filter->apply($io, $pending, 'Locale', static fn (TranslationSuggestion $s): string => $s->getLocale());
        $pending = $this->filter->apply($io, $pending, 'Catalogue', static fn (TranslationSuggestion $s): string => $s->getCatalogue());

        return $this->filter->apply(
            $io,
            $pending,
            'Scope',
            static fn (TranslationSuggestion $s): string => $s->getScope(),
            static fn (string $scope): string => '' === $scope ? self::GLOBAL_SCOPE : $scope,
        );
    }

    /**
     * @param list<TranslationSuggestion> $pending
     */
    private function review(SymfonyStyle $io, array $pending): int
    {
        $total = \count($pending);
        $approved = 0;
        $rejected = 0;
        $seen = 0;

        foreach ($pending as $index => $suggestion) {
            ++$seen;
            $autocorrect = $this->autocorrector->correct($suggestion);
            $this->presenter->reviewCard($io, $suggestion, $index + 1, $total, $autocorrect);

            // An errored row has no value to approve as-is: editing is the way in.
            // "approve autocorrect" only exists when a full deterministic fix is on
            // screen — it applies the corrected value, not the flawed one.
            $choices = null === $suggestion->getSuggestedValue()
                ? ['edit', 'reject', 'skip', 'quit']
                : array_values(array_filter([
                    'approve',
                    null !== $autocorrect ? 'approve autocorrect' : null,
                    'edit',
                    'reject',
                    'skip',
                    'quit',
                ]));

            // A refused approval (a lost placeholder, a broken syntax, no value) asks
            // again on the same card: the reviewer fixes the value there, instead of
            // being walked past it and finding it counted as "skipped".
            while (true) {
                $decision = $io->choice('Decision', $choices, 'skip');

                if ('quit' === $decision) {
                    --$seen;

                    break 2;
                }

                if ('reject' === $decision) {
                    try {
                        $this->reviewer->reject($suggestion);
                        ++$rejected;
                    } catch (SuggestionAlreadyReviewedException) {
                        $io->warning('Already reviewed meanwhile (by someone else, or in another window) — left as it is.');
                    }

                    continue 2;
                }

                if ('skip' === $decision) {
                    continue 2;
                }

                $finalValue = null;

                if ('approve autocorrect' === $decision && null !== $autocorrect) {
                    $finalValue = $autocorrect->value;
                }

                if ('edit' === $decision) {
                    // The autocorrected value, when one exists, is the better starting point.
                    $fallback = null !== $autocorrect ? $autocorrect->value : $suggestion->getSuggestedValue();
                    $finalValue = $this->valuePrompt->ask($io, 'Final value', $fallback, null !== $autocorrect ? 'the autocorrected value' : 'the suggestion');
                }

                $outcome = $this->tryApprove($io, $suggestion, $finalValue);

                if (self::APPROVED === $outcome) {
                    ++$approved;
                }

                // Reviewed elsewhere meanwhile: nothing left to decide on this card.
                if (self::REFUSED !== $outcome) {
                    continue 2;
                }
            }
        }

        $notSeen = $total - $seen;
        $io->success(\sprintf(
            'Review finished: %d approved, %d rejected, %d skipped%s.',
            $approved,
            $rejected,
            $seen - $approved - $rejected,
            $notSeen > 0 ? \sprintf(', %d left for later', $notSeen) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Funnel of every approval: the service applies the override with the same guards
     * as any other caller (placeholders, catalogue syntax) — a refusal becomes a
     * warning and the suggestion stays pending.
     *
     * @return self::APPROVED|self::REFUSED|self::GONE
     */
    private function tryApprove(SymfonyStyle $io, TranslationSuggestion $suggestion, ?string $finalValue): string
    {
        try {
            $this->reviewer->approve($suggestion, $finalValue);
        } catch (SuggestionValueMissingException) {
            $io->warning('No value to apply (errored row) — pick "edit" to write one, or re-generate with the retry mode.');

            return self::REFUSED;
        } catch (PlaceholderMismatchException $e) {
            // The exception says which way each placeholder differs: "loses" was printed
            // for an added one too.
            $io->warning(\sprintf('Refused: %s — still pending.', $e->getMessage()));

            return self::REFUSED;
        } catch (TranslationValueInvalidException $e) {
            $io->warning(\sprintf('Refused: %s — still pending.', $e->getMessage()));

            return self::REFUSED;
        } catch (SuggestionAlreadyReviewedException) {
            $io->warning('Already reviewed meanwhile (by someone else, or in another window) — left as it is.');

            return self::GONE;
        }

        // The approval writes no override for a value the entry already shows: the message
        // says what it actually did, as recorded on the approved row.
        $metadata = $suggestion->getMetadata() ?? [];
        $recorded = $metadata['override_change'] ?? null;
        $effect = match (\is_string($recorded) ? OverrideChange::tryFrom($recorded) : null) {
            OverrideChange::None => 'same as the current value, no override written',
            // The global override in a scope, or the parent language's for a regional locale.
            OverrideChange::Revert => \sprintf('same as %s, override removed', 'inherited' === ($metadata['reverted_to'] ?? null) ? 'the inherited value' : 'the file value'),
            default => 'override written',
        };

        $io->success(\sprintf('Suggestion #%d approved — %s for "%s" (%s, %s).', (int) $suggestion->getId(), $effect, $suggestion->getKey(), $suggestion->getCatalogue(), $suggestion->getLocale()));

        return self::APPROVED;
    }

    /**
     * Deletes the errored suggestions of the narrowed queue — exactly the rows whose
     * ids were listed and confirmed, never a filter re-derived afterwards: the two
     * would part ways as soon as a narrowing question was skipped for lack of choice.
     *
     * @param list<TranslationSuggestion> $pending
     */
    private function deleteErrored(SymfonyStyle $io, array $pending): int
    {
        $errored = array_values(array_filter($pending, static fn (TranslationSuggestion $s): bool => $s->hasGenerationError()));

        if ([] === $errored) {
            $io->info('No errored suggestions in the current selection.');

            return Command::SUCCESS;
        }

        $ids = array_map(static fn (TranslationSuggestion $s): string => (string) $s->getId(), $errored);
        $io->text(\sprintf('Found %d errored suggestion(s): #%s', \count($errored), implode(', #', $ids)));

        if (!$io->confirm(\sprintf('Delete these %d errored suggestion(s)?', \count($errored)), false)) {
            $io->note('Nothing deleted.');

            return Command::SUCCESS;
        }

        $this->reviewer->rejectMany($errored);

        $io->success(\sprintf('%d errored suggestion(s) deleted.', \count($errored)));

        return Command::SUCCESS;
    }
}
