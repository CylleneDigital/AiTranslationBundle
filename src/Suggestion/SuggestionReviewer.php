<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Event\SuggestionApprovedEvent;
use CylleneDigital\AiTranslationBundle\Event\SuggestionRejectedEvent;
use CylleneDigital\AiTranslationBundle\Override\AuthorProviderInterface;
use CylleneDigital\AiTranslationBundle\Override\OverrideChange;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueInvalidException;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turning a human decision on a suggestion into an override (approve) or into nothing
 * (reject).
 *
 * The guards live here rather than in whatever surface asks: a value that lost a source
 * placeholder, or that breaks its catalogue's declared syntax, is refused whoever calls —
 * the CLI, an integration, a batch. Generation records those problems without refusing
 * them; approval is where they block, because that is the moment a value reaches users.
 */
final class SuggestionReviewer
{
    public function __construct(
        private readonly TranslationSuggestionRepository $suggestionRepository,
        private readonly OverrideWriter $writer,
        private readonly AuthorProviderInterface $authorProvider,
        private readonly PlaceholderConsistencyChecker $placeholderChecker,
        private readonly TranslationValueValidator $valueValidator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly OverrideReader $overrides,
    ) {
    }

    /**
     * Approving applies the value in the suggestion's scope (visible immediately): an
     * override written, or — when the entry already shows that value — none, or the stored
     * one removed when the value is its baseline ({@see OverrideChange}). `$finalValue` lets the reviewer fix the proposal before applying it;
     * the applied value must preserve the source placeholders.
     *
     * The override and the suggestion's status are written in one transaction, after
     * checking in the database that the suggestion is still pending.
     *
     * @throws PlaceholderMismatchException       when the applied value loses or adds placeholders (see $missing / $unexpected)
     * @throws SuggestionValueMissingException    when there is no value to apply (errored row)
     * @throws TranslationValueInvalidException   when the applied value breaks the catalogue's declared syntax
     * @throws SuggestionAlreadyReviewedException when the suggestion is no longer pending
     */
    public function approve(TranslationSuggestion $suggestion, ?string $finalValue = null): void
    {
        // Trimmed only to tell a blank edit from a real one: a translation may need its
        // leading or trailing space (a label concatenated in a template).
        $value = null !== $finalValue && '' !== trim($finalValue) ? $finalValue : $suggestion->getSuggestedValue();

        // A failed generation has no value: it must be edited (or retried) first.
        if (null === $value || '' === $value) {
            throw new SuggestionValueMissingException();
        }

        $placeholderDiff = $this->placeholderChecker->diff($suggestion->getSourceValue(), $value);
        if ([] !== $placeholderDiff) {
            $explained = $this->placeholderChecker->explain($suggestion->getSourceValue(), $value);

            throw new PlaceholderMismatchException($placeholderDiff, $explained['missing'], $explained['unexpected']);
        }

        // The applied value must also match the catalogue's declared syntax (ICU/legacy).
        $syntaxIssues = $this->valueValidator->validate($value, $suggestion->getKey(), $suggestion->getCatalogue(), $suggestion->getLocale());

        if ([] !== $syntaxIssues) {
            throw new TranslationValueInvalidException($syntaxIssues);
        }

        $reviewer = $this->getReviewer();
        $changed = false;

        try {
            // The override's cache invalidation and event wait for the commit.
            // Closures, not arrow functions: an arrow function captures $changed by value.
            $this->writer->withEffectsAfter(function () use ($suggestion, $value, $reviewer, &$changed): void {
                $this->suggestionRepository->transactional(function () use ($suggestion, $value, $reviewer, &$changed): void {
                    if ([] === $this->suggestionRepository->lockStillPending([$suggestion])) {
                        throw new SuggestionAlreadyReviewedException();
                    }

                    $changed = true;

                    if ($value !== $suggestion->getSuggestedValue()) {
                        $metadata = $suggestion->getMetadata() ?? [];
                        $metadata['edited_on_approve'] = true;
                        $suggestion->setMetadata($metadata);
                    }

                    // Approved before the write: the writer closes the pending suggestions of the
                    // key it sets, and this one is the source of the value, not superseded by it.
                    $suggestion->approve($reviewer);

                    [$key, $catalogue, $locale, $scope] = [$suggestion->getKey(), $suggestion->getCatalogue(), $suggestion->getLocale(), $suggestion->getScope()];

                    // A value the entry already shows (an "every key" run proposing the file value)
                    // is approved all the same — the decision is recorded — but writes no override,
                    // which would only shadow the file.
                    $inherited = $this->overrides->getInheritedValue($key, $catalogue, $locale, $scope);
                    $change = OverrideChange::decide($value, $inherited ?? $this->overrides->getFileValue($key, $catalogue, $locale), $this->overrides->getOverrideValue($key, $catalogue, $locale, $scope));
                    $this->recordChange($suggestion, $change, null !== $inherited);

                    match ($change) {
                        // The file value is recorded as the original, as the editor does.
                        OverrideChange::Write => $this->writer->save($key, $catalogue, $locale, $value, $this->overrides->getFileValue($key, $catalogue, $locale), $scope),
                        OverrideChange::Revert => $this->writer->remove($key, $catalogue, $locale, $scope),
                        OverrideChange::None => null,
                    };

                    $this->suggestionRepository->save($suggestion);
                });
            });
        } catch (\Throwable $e) {
            // The rollback restored the database, not the entity: still approved in memory,
            // the host's next flush would record an approval nothing applied.
            if ($changed) {
                $this->suggestionRepository->restore($suggestion);
            }

            throw $e;
        }

        $this->eventDispatcher->dispatch(new SuggestionApprovedEvent($suggestion, $value));
    }

    /**
     * Rejecting deletes the suggestion: nothing is applied, and the key becomes eligible
     * again for a later run. The event is dispatched before the removal so listeners see
     * the full row.
     *
     * @throws SuggestionAlreadyReviewedException when the suggestion is no longer pending
     */
    public function reject(TranslationSuggestion $suggestion): void
    {
        // An approved row is the audit trail of its override: a stale or repeated
        // rejection must not delete it.
        $this->suggestionRepository->transactional(function () use ($suggestion): void {
            if ([] === $this->suggestionRepository->lockStillPending([$suggestion])) {
                throw new SuggestionAlreadyReviewedException();
            }

            $suggestion->reject($this->getReviewer());
            $this->eventDispatcher->dispatch(new SuggestionRejectedEvent($suggestion));
            $this->suggestionRepository->remove($suggestion);
        });
    }

    /**
     * Batch approval: the overrides are written in one shot ({@see OverrideWriter::saveMany()}
     * invalidates each touched locale's cache once) and the suggestions are flushed once instead
     * of once per item, in one transaction. Suggestions with no value to apply (failed
     * generations), whose placeholders differ from the source's or that breaks its catalogue's syntax, or that
     * are no longer pending in the database are skipped and returned so the caller can report them —
     * the batch never aborts midway.
     *
     * @param iterable<TranslationSuggestion> $suggestions
     *
     * @return list<TranslationSuggestion> the suggestions skipped (no value, placeholder or syntax mismatch, already reviewed)
     */
    public function approveMany(iterable $suggestions): array
    {
        $reviewer = $this->getReviewer();
        $candidates = [];
        $skipped = [];

        foreach ($suggestions as $suggestion) {
            $value = $suggestion->getSuggestedValue();

            if (null === $value || '' === $value
                || [] !== $this->placeholderChecker->diff($suggestion->getSourceValue(), $value)
                || [] !== $this->valueValidator->validate($value, $suggestion->getKey(), $suggestion->getCatalogue(), $suggestion->getLocale())
            ) {
                $skipped[] = $suggestion;

                continue;
            }

            $candidates[] = $suggestion;
        }

        // The suggestions changed in memory so far, put back if the batch fails.
        $changed = [];

        try {
            $approved = $this->writer->withEffectsAfter(function () use ($candidates, $reviewer, &$skipped, &$changed): array {
                return $this->suggestionRepository->transactional(function () use ($candidates, $reviewer, &$skipped, &$changed): array {
                    $pending = $this->suggestionRepository->lockStillPending($candidates);
                    $overrides = [];
                    $removals = [];
                    $approved = [];
                    $entries = [];

                    foreach ($candidates as $index => $suggestion) {
                        if (!\in_array($suggestion, $pending, true)) {
                            $skipped[] = $suggestion;

                            continue;
                        }

                        // Validated above: a candidate always has a value.
                        $entries[$index] = ['key' => $suggestion->getKey(), 'catalogue' => $suggestion->getCatalogue(), 'locale' => $suggestion->getLocale(), 'scope' => $suggestion->getScope(), 'value' => (string) $suggestion->getSuggestedValue()];
                    }

                    // Same rule as a single approval — only a value the entry does not show yet
                    // is written — decided for the batch as a whole: approving the global
                    // suggestion and the scoped one of a key changes what the scoped one inherits.
                    foreach ($this->overrides->planChanges($entries) as $index => ['change' => $change, 'stored' => $override, 'fileValue' => $fileValue, 'inherits' => $inherits]) {
                        $suggestion = $candidates[$index];
                        $entry = $entries[$index];
                        $changed[] = $suggestion;
                        $this->recordChange($suggestion, $change, $inherits);

                        match ($change) {
                            OverrideChange::Write => $overrides[] = ['key' => $entry['key'], 'catalogue' => $entry['catalogue'], 'locale' => $entry['locale'], 'value' => $entry['value'], 'scope' => $entry['scope'], 'original' => $fileValue],
                            // decide() only reverts a stored override.
                            OverrideChange::Revert => $removals[] = $override ?? throw new \LogicException('Nothing stored to revert.'),
                            OverrideChange::None => null,
                        };

                        $suggestion->approve($reviewer);
                        $this->suggestionRepository->saveDeferred($suggestion);
                        $approved[$index] = [$suggestion, $entry['value']];
                    }

                    // The events in the order the suggestions were given.
                    ksort($approved);

                    if ([] !== $overrides || [] !== $removals) {
                        $this->writer->saveAndRemoveMany($overrides, $removals);
                    }

                    $this->suggestionRepository->flush();

                    return $approved;
                });
            });
        } catch (\Throwable $e) {
            foreach ($changed as $suggestion) {
                $this->suggestionRepository->restore($suggestion);
            }

            throw $e;
        }

        foreach ($approved as [$suggestion, $value]) {
            $this->eventDispatcher->dispatch(new SuggestionApprovedEvent($suggestion, $value));
        }

        return $skipped;
    }

    /**
     * Batch rejection: the rows are deleted in one flush instead of one per item. A
     * suggestion no longer pending in the database is left as it is.
     *
     * @param iterable<TranslationSuggestion> $suggestions
     */
    public function rejectMany(iterable $suggestions): void
    {
        $suggestions = [...$suggestions];

        if ([] === $suggestions) {
            return;
        }

        $this->suggestionRepository->transactional(function () use ($suggestions): void {
            $reviewer = $this->getReviewer();

            foreach ($this->suggestionRepository->lockStillPending(array_values($suggestions)) as $suggestion) {
                $suggestion->reject($reviewer);
                $this->eventDispatcher->dispatch(new SuggestionRejectedEvent($suggestion));
                $this->suggestionRepository->removeDeferred($suggestion);
            }

            $this->suggestionRepository->flush();
        });
    }

    /**
     * What the approval did to the override — written, removed, or nothing — kept on the
     * approved row, the audit trail of the decision, and read by the surfaces that report
     * it: "approved" alone no longer says whether an override was written. A removal also
     * says what the entry shows from then on: the override it inherits, or its file value.
     */
    private function recordChange(TranslationSuggestion $suggestion, OverrideChange $change, bool $inherits): void
    {
        $metadata = $suggestion->getMetadata() ?? [];
        $metadata['override_change'] = $change->value;

        if (OverrideChange::Revert === $change) {
            $metadata['reverted_to'] = $inherits ? 'inherited' : 'file';
        }

        $suggestion->setMetadata($metadata);
    }

    private function getReviewer(): string
    {
        return $this->authorProvider->getAuthorIdentifier() ?? 'system';
    }
}
