<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use CylleneDigital\AiTranslationBundle\Suggestion\AutocorrectResult;
use CylleneDigital\AiTranslationBundle\Suggestion\PlaceholderConsistencyChecker;
use CylleneDigital\AiTranslationBundle\Suggestion\PluralCategories;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console rendering of AI suggestions — the overview table and the one-suggestion
 * review card, with the same two warnings any review surface must show:
 * the placeholder diff recorded at generation time, and a live syntax check of the
 * value against the catalogue's declared format.
 */
final class SuggestionPresenter
{
    public function __construct(
        private readonly TranslationValueValidator $valueValidator,
        private readonly ValueBlock $valueBlock,
        private readonly OverrideReader $overrides,
        private readonly PlaceholderConsistencyChecker $placeholderChecker,
    ) {
    }

    /**
     * The overview table. Keys are shown whole — reading one in full is what tells
     * apart two suggestions of the same catalogue — and only values are shortened; the
     * card below carries the untruncated value anyway.
     *
     * @param list<TranslationSuggestion> $pending
     */
    public function pendingTable(SymfonyStyle $io, array $pending): void
    {
        $rows = [];

        foreach ($pending as $suggestion) {
            $value = null !== $suggestion->getSuggestedValue() ? $this->truncate($suggestion->getSuggestedValue(), 40) : '(errored — no value)';

            if ([] !== $this->placeholderMismatch($suggestion)) {
                $value .= ' ⚠ placeholders';
            }

            if ([] !== $this->syntaxIssues($suggestion)) {
                $value .= ' ⚠ syntax';
            }

            $rows[] = [
                $suggestion->getId(),
                $suggestion->getLocale(),
                $suggestion->getCatalogue(),
                $suggestion->getKey(),
                $value,
                \sprintf('%d %%', (int) round($suggestion->getConfidence() * 100)),
                '' === $suggestion->getScope() ? '-' : $suggestion->getScope(),
            ];
        }

        $io->table(['Id', 'Locale', 'Catalogue', 'Key', 'Suggested value', 'Confidence', 'Scope'], $rows);
    }

    /**
     * The full review card of one suggestion: header, source and proposed values read
     * side by side, confidence, generation error, the two warnings — and, when the
     * detected mistakes are deterministically repairable, the autocorrected value with
     * the exact replacements applied.
     */
    public function reviewCard(SymfonyStyle $io, TranslationSuggestion $suggestion, int $position, int $total, ?AutocorrectResult $autocorrect = null): void
    {
        $scope = $suggestion->getScope();

        $io->section(\sprintf('%d/%d — %s (%s, %s%s)', $position, $total, $suggestion->getKey(), $suggestion->getCatalogue(), $suggestion->getLocale(), '' === $scope ? '' : ', scope '.$scope));

        // A scoped suggestion writes a scoped override on approval — the section
        // title mentions it, but too discreetly to catch a reviewing eye.
        if ('' !== $scope) {
            $io->text(\sprintf('<comment>Scope: %s</comment>', $scope));
            $io->newLine();
        }

        // One value block each: source and proposal are read side by side, they must
        // be easy to tell apart — and translation content displays verbatim.
        $this->valueBlock->render($io, \sprintf('Source (%s)', $suggestion->getSourceLocale()), $suggestion->getSourceValue());

        // An "every key" run proposes values for keys that already have one: without what
        // the entry shows today, the reviewer approves a replacement blind.
        [$current, $origin] = $this->currentValue($suggestion);

        if (null !== $current) {
            $this->valueBlock->render($io, \sprintf('Current value (%s)', $origin), $current);
        }

        if (null !== $suggestion->getSuggestedValue()) {
            $this->valueBlock->render($io, \sprintf('Suggested by %s (%s)', $suggestion->getProvider(), $suggestion->getLocale()), $suggestion->getSuggestedValue());

            if ($suggestion->getSuggestedValue() === $current) {
                $io->text('<comment>Same as the current value: approving records the decision and writes no override.</comment>');
                $io->newLine();
            }
        }

        $io->text(\sprintf('Confidence: %d %%', (int) round($suggestion->getConfidence() * 100)));

        if (null !== $suggestion->getGenerationError()) {
            $io->newLine();
            $this->valueBlock->render($io, 'Generation error', $suggestion->getGenerationError());
        }

        $mismatch = $this->placeholderMismatch($suggestion);

        if ([] !== $mismatch) {
            $io->warning(array_merge(
                ['The suggestion loses or alters placeholders of the source — markers whose occurrences differ:'],
                array_map(static fn (string $marker): string => \sprintf('    %s…', $marker), $mismatch),
                ['Approving as-is will be refused. Pick "edit" to fix the value, or "reject".'],
            ));
        }

        // Valid ICU, so the syntax check passes — but each number of a missing category
        // falls back to "other": "your 2th order".
        $lacking = null !== $suggestion->getSuggestedValue() ? PluralCategories::missingIn($suggestion->getSuggestedValue(), $suggestion->getLocale()) : [];

        if ([] !== $lacking) {
            $io->warning(array_merge(
                array_map(static fn (array $report): string => \sprintf('"%s" (%s) lacks the %s categories %s.', $report['argument'], $report['kind'], $suggestion->getLocale(), implode(', ', $report['missing'])), $lacking),
                ['ICU falls back to "other" for their numbers ("2th", "3th"…). Pick "edit" to add the branches.'],
            ));
        }

        $syntaxIssues = $this->syntaxIssues($suggestion);

        if ([] !== $syntaxIssues) {
            $io->warning(\sprintf(
                'The value breaks the catalogue\'s declared syntax (%s) — e.g. translated ICU keywords ("pluriel", "autre") or an ICU construct in a legacy catalogue. Approving as-is will be refused; pick "edit" or "reject".',
                implode(', ', $syntaxIssues),
            ));
        }

        if (null !== $autocorrect) {
            $this->valueBlock->render($io, 'Autocorrected value', $autocorrect->value, 'applied: '.implode(', ', $autocorrect->applied));
        }
    }

    /**
     * The placeholder diff of the source and the suggested value, computed now — as the
     * approval computes it — rather than read from `metadata.placeholder_mismatch`, which
     * the generation recorded once: after a fix of the checker (an ICU branch no longer
     * misread as a placeholder) a stored diff announced a refusal the approval no longer
     * made, and a value edited since was judged on the old one.
     *
     * @return list<string>
     */
    private function placeholderMismatch(TranslationSuggestion $suggestion): array
    {
        $value = $suggestion->getSuggestedValue();

        return null !== $value ? $this->placeholderChecker->diff($suggestion->getSourceValue(), $value) : [];
    }

    /**
     * @return list<string> {@see TranslationValueValidator} ISSUE_* codes
     */
    private function syntaxIssues(TranslationSuggestion $suggestion): array
    {
        $value = $suggestion->getSuggestedValue();

        return null === $value
            ? []
            : $this->valueValidator->validate($value, $suggestion->getKey(), $suggestion->getCatalogue(), $suggestion->getLocale());
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }

    /**
     * What the suggestion's entry shows today, and where it comes from: its own override,
     * else the inherited global one (scoped entry) or the file.
     *
     * @return array{?string, string}
     */
    private function currentValue(TranslationSuggestion $suggestion): array
    {
        [$key, $catalogue, $locale, $scope] = [$suggestion->getKey(), $suggestion->getCatalogue(), $suggestion->getLocale(), $suggestion->getScope()];
        $override = $this->overrides->getOverrideValue($key, $catalogue, $locale, $scope);

        if (null !== $override) {
            return [$override, 'override'];
        }

        $baseline = $this->overrides->getBaselineValue($key, $catalogue, $locale, $scope);

        // Inherited when it is not the file's: a scope's global override, or the parent
        // language's for a regional locale.
        return [$baseline, $baseline === $this->overrides->getFileValue($key, $catalogue, $locale) ? 'file' : 'inherited'];
    }
}
