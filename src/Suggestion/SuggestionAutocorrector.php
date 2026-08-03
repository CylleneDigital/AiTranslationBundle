<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;

/**
 * Deterministic repair of the mistakes the review warnings detect — no AI involved,
 * the source value is the reference:
 *
 *  - a renamed placeholder (the model translated "%name%" into "%nom%", or wrote
 *    "{{limit}}" for "{{ limit }}"): when exactly one marker was lost and exactly one
 *    appeared, the swap back is mechanical;
 *  - a translated ICU skeleton ("pluriel", "autre"…): the keywords are invariant ICU
 *    vocabulary, so the argument name, function keyword and selectors are realigned on
 *    the source's — positionally — while the translated branch contents are kept.
 *
 * A correction is only returned when the result passes BOTH guards (placeholder
 * consistency against the source, catalogue-syntax validation): a partial fix is
 * never proposed. A lost placeholder with no renamed counterpart stays uncorrectable —
 * nobody can know where it belongs in the translated sentence.
 */
final class SuggestionAutocorrector
{
    public function __construct(
        private readonly PlaceholderConsistencyChecker $placeholderChecker,
        private readonly TranslationValueValidator $valueValidator,
    ) {
    }

    public function correct(TranslationSuggestion $suggestion): ?AutocorrectResult
    {
        $source = $suggestion->getSourceValue();
        $original = $suggestion->getSuggestedValue();

        if (null === $original) {
            return null;
        }

        $applied = [];
        $value = $this->realignIcuSkeleton($source, $original, $applied);
        $value = $this->swapRenamedPlaceholder($source, $value, $applied);

        if ([] === $applied || $value === $original) {
            return null;
        }

        // Only a fully clean result is proposed — both guards re-run on it.
        if (!$this->placeholderChecker->isConsistent($source, $value)) {
            return null;
        }

        if ([] !== $this->valueValidator->validate($value, $suggestion->getKey(), $suggestion->getCatalogue(), $suggestion->getLocale())) {
            return null;
        }

        return new AutocorrectResult($value, $applied);
    }

    /**
     * Realigns the suggestion's top-level ICU tokens on the source's: argument name,
     * function keyword and selectors come from the source (matched positionally). The
     * tokens are replaced IN PLACE — the suggestion's own layout (newlines,
     * indentation) and translated branch contents are untouched. Applies only when
     * both sides parse with the same number of branches.
     *
     * @param list<string> $applied
     */
    private function realignIcuSkeleton(string $source, string $value, array &$applied): string
    {
        $sourceIcu = $this->parseIcu($source);
        $valueIcu = $this->parseIcu($value);

        if (null === $sourceIcu || null === $valueIcu || \count($sourceIcu['selectors']) !== \count($valueIcu['selectors'])) {
            return $value;
        }

        // [start, length, replacement] — spliced right to left so offsets stay valid.
        $replacements = [];

        if ($valueIcu['arg']['value'] !== $sourceIcu['arg']['value']) {
            $applied[] = \sprintf('{%s} → {%s}', $valueIcu['arg']['value'], $sourceIcu['arg']['value']);
            $replacements[] = [$valueIcu['arg']['start'], $valueIcu['arg']['length'], $sourceIcu['arg']['value']];
        }

        if ($valueIcu['function']['value'] !== $sourceIcu['function']['value']) {
            $applied[] = \sprintf('%s → %s', $valueIcu['function']['value'], $sourceIcu['function']['value']);
            $replacements[] = [$valueIcu['function']['start'], $valueIcu['function']['length'], $sourceIcu['function']['value']];
        }

        foreach ($sourceIcu['selectors'] as $i => $sourceSelector) {
            $valueSelector = $valueIcu['selectors'][$i];

            if ($valueSelector['value'] !== $sourceSelector['value']) {
                $applied[] = \sprintf('%s → %s', $valueSelector['value'], $sourceSelector['value']);
                $replacements[] = [$valueSelector['start'], $valueSelector['length'], $sourceSelector['value']];
            }
        }

        foreach (array_reverse($replacements) as [$start, $length, $replacement]) {
            $value = substr_replace($value, $replacement, $start, $length);
        }

        return $value;
    }

    /**
     * A minimal, brace-aware parse of "{arg, function, selector {…} selector {…}}",
     * recording each token's exact span in the original string so a realignment can
     * splice it in place. Null on anything that does not match that shape.
     *
     * @return array{arg: array{value: string, start: int, length: int}, function: array{value: string, start: int, length: int}, selectors: non-empty-list<array{value: string, start: int, length: int}>}|null
     */
    private function parseIcu(string $text): ?array
    {
        $length = \strlen($text);
        $start = strspn($text, " \t\r\n");
        $end = $length - 1;

        while ($end >= 0 && str_contains(" \t\r\n", $text[$end])) {
            --$end;
        }

        if ($start >= $end || '{' !== $text[$start] || '}' !== $text[$end]) {
            return null;
        }

        $firstComma = strpos($text, ',', $start + 1);
        $secondComma = false === $firstComma ? false : strpos($text, ',', $firstComma + 1);

        if (false === $firstComma || false === $secondComma || $secondComma >= $end) {
            return null;
        }

        $arg = $this->token($text, $start + 1, $firstComma);
        $function = $this->token($text, $firstComma + 1, $secondComma);

        if (null === $arg || null === $function
            || 1 !== preg_match('/^[a-zA-Z0-9_]+$/', $arg['value'])
            || 1 !== preg_match('/^[a-zA-Z]+$/u', $function['value'])) {
            return null;
        }

        $selectors = [];
        $offset = $secondComma + 1;

        while ($offset < $end) {
            $open = strpos($text, '{', $offset);

            if (false === $open || $open >= $end) {
                return '' === trim(substr($text, $offset, $end - $offset)) && [] !== $selectors
                    ? ['arg' => $arg, 'function' => $function, 'selectors' => $selectors]
                    : null;
            }

            $selector = $this->token($text, $offset, $open);

            if (null === $selector || 1 !== preg_match('/^(=\d+|[\p{L}0-9_]+)$/u', $selector['value'])) {
                return null;
            }

            $selectors[] = $selector;
            $depth = 1;
            $cursor = $open + 1;

            while ($cursor < $end && $depth > 0) {
                if ('{' === $text[$cursor]) {
                    ++$depth;
                } elseif ('}' === $text[$cursor]) {
                    --$depth;
                }

                ++$cursor;
            }

            if (0 !== $depth) {
                return null;
            }

            $offset = $cursor;
        }

        return [] !== $selectors ? ['arg' => $arg, 'function' => $function, 'selectors' => $selectors] : null;
    }

    /**
     * The trimmed token between two offsets, with its exact span in the full string.
     *
     * @return array{value: string, start: int, length: int}|null
     */
    private function token(string $text, int $from, int $to): ?array
    {
        $raw = substr($text, $from, $to - $from);
        $value = trim($raw);

        if ('' === $value) {
            return null;
        }

        $start = $from + (int) strpos($raw, $value[0]);

        return ['value' => $value, 'start' => $start, 'length' => \strlen($value)];
    }

    /**
     * When exactly one marker of the source disappeared and exactly one unknown marker
     * appeared, with matching counts and the same style, the model most likely
     * translated the placeholder name — swap it back.
     *
     * @param list<string> $applied
     */
    private function swapRenamedPlaceholder(string $source, string $value, array &$applied): string
    {
        // Read through the checker: a correction has to see the markers exactly as the
        // guard that re-checks it does, branch texts included.
        $sourceCounts = array_count_values(array_column($this->placeholderChecker->markers($source), 'marker'));
        $valueMarkers = $this->placeholderChecker->markers($value);
        $valueCounts = array_count_values(array_column($valueMarkers, 'marker'));

        $lostMarker = null;
        $lostDelta = 0;
        $gainedMarker = null;
        $gainedDelta = 0;
        $lostSeen = 0;
        $gainedSeen = 0;

        foreach ($sourceCounts + $valueCounts as $marker => $unused) {
            $delta = ($sourceCounts[$marker] ?? 0) - ($valueCounts[$marker] ?? 0);

            if ($delta > 0) {
                ++$lostSeen;
                $lostMarker = (string) $marker;
                $lostDelta = $delta;
            } elseif ($delta < 0) {
                ++$gainedSeen;
                $gainedMarker = (string) $marker;
                $gainedDelta = -$delta;
            }
        }

        if (1 !== $lostSeen || 1 !== $gainedSeen || null === $lostMarker || null === $gainedMarker
            || $lostDelta !== $gainedDelta || $this->markerStyle($lostMarker) !== $this->markerStyle($gainedMarker)) {
            return $value;
        }

        // HTML-tag markers: renaming a tag back is unlikely to be a "translation slip" —
        // leave those to a human.
        if ('<' === $this->markerStyle($gainedMarker)) {
            return $value;
        }

        $applied[] = '{' === $this->markerStyle($gainedMarker)
            ? \sprintf('%s} → %s}', $gainedMarker, $lostMarker)
            : \sprintf('%s → %s', $gainedMarker, $lostMarker);

        // Only the occurrences the checker read as this marker are rewritten, right to left
        // so the offsets stay valid: a branch text spelled like the renamed argument
        // ("{role, select, admin {usuario} other {{usuario}}}") is translated text, kept.
        foreach (array_reverse($valueMarkers) as $occurrence) {
            if ($occurrence['marker'] === $gainedMarker) {
                // The lost marker is the replacement text itself: "%name%", "{{ limit }}",
                // or "{name" over the brace and name of an argument (its "," or "}" stays).
                $value = substr_replace($value, $lostMarker, $occurrence['offset'], $occurrence['length']);
            }
        }

        return $value;
    }

    /**
     * "%" for "%name%", "{{" for a validator's "{{ limit }}", "{" for an ICU argument, "<"
     * for a tag: a swap only happens between two markers of the same style.
     */
    private function markerStyle(string $marker): string
    {
        return str_starts_with($marker, '{{') ? '{{' : $marker[0];
    }
}
