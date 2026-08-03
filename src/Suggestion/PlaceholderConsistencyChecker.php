<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

/**
 * Compares the placeholders of a source string and its translation: Symfony style
 * (%name%, and the validators' {{ limit }}), ICU style ({count}, {count, plural, ...})
 * and HTML tags. A translation
 * that lost or altered a placeholder breaks at runtime — this is the guard rail the
 * review flow and the approval rely on (DeepL especially has no instruction
 * channel to protect them).
 *
 * NOT a security control. This checks placeholder/tag *consistency* only; it does not
 * sanitise, and does not reject, malicious markup or scripts a provider (or an imported
 * override) might introduce. Preventing XSS remains the host's responsibility at render
 * time: never emit a translated value through Twig's `|raw`, into an HTML attribute or a
 * JavaScript/JSON context without the proper escaping. A translation containing
 * "<strong>" is perfectly "consistent" here as long as the source had it too.
 */
final class PlaceholderConsistencyChecker
{
    // The markers that can start at a given offset. "{{ limit }}" is tried before the ICU
    // argument so that its inner brace is not read as an argument of its own.
    private const string MARKER_AT_OFFSET = '/\G(?:%[a-zA-Z0-9_.]+%|<\/?[a-zA-Z][a-zA-Z0-9-]*|\{\{\s*[a-zA-Z0-9_.]+\s*\}\}|\{\s*([a-zA-Z0-9_]+)\s*(?=[,}]))/';

    private const string ARGUMENT = 'argument';
    private const string BRANCH = 'branch';

    /**
     * The placeholders lost or added by the translation, and the HTML tags whose number
     * of occurrences differs.
     *
     * Variables are compared by presence, not by count: a plural repeats them once per
     * branch, and languages do not have the same number of branches — "{count}" appears
     * twice in an English "one/other" message and four times in its correct Polish
     * "one/few/many/other" translation. Tags keep their count: a lost "</strong>" breaks
     * the markup whatever the language.
     *
     * @return list<string>
     */
    public function diff(string $source, string $translation): array
    {
        $sourceCounts = $this->count($source);
        $translationCounts = $this->count($translation);

        $diff = [];
        foreach ($sourceCounts + $translationCounts as $placeholder => $unused) {
            $inSource = $sourceCounts[$placeholder] ?? 0;
            $inTranslation = $translationCounts[$placeholder] ?? 0;

            $differs = str_starts_with((string) $placeholder, '<')
                ? $inSource !== $inTranslation
                : (0 === $inSource) !== (0 === $inTranslation);

            if ($differs) {
                $diff[] = (string) $placeholder;
            }
        }

        sort($diff);

        return $diff;
    }

    /**
     * The same differences as {@see diff()}, told apart by direction and written the way
     * they appear in a value ("{name}", "<strong>"): what the translation lacks, and what
     * it has that the source does not. A refusal saying a placeholder was "lost" when the
     * translation ADDED one sent the reviewer looking for the wrong mistake.
     *
     * @return array{missing: list<string>, unexpected: list<string>}
     */
    public function explain(string $source, string $translation): array
    {
        $sourceCounts = $this->count($source);
        $translationCounts = $this->count($translation);
        $explained = ['missing' => [], 'unexpected' => []];

        foreach ($this->diff($source, $translation) as $marker) {
            $direction = ($sourceCounts[$marker] ?? 0) > ($translationCounts[$marker] ?? 0) ? 'missing' : 'unexpected';
            $explained[$direction][] = match (true) {
                str_starts_with($marker, '{{'), str_starts_with($marker, '%') => $marker,
                str_starts_with($marker, '<') => $marker.'>',
                default => $marker.'}',
            };
        }

        return $explained;
    }

    public function isConsistent(string $source, string $translation): bool
    {
        return [] === $this->diff($source, $translation);
    }

    /**
     * Every placeholder occurrence of the text, in order, with its span: "%name%",
     * "{{ limit }}" (verbatim), "{count" for an ICU argument (simple or not, whatever its
     * inner spacing) and "<strong" for both "<strong>" and "</strong>". The span covers
     * what was matched — for an ICU argument, the brace, the name and the spaces after
     * it, up to its "," or "}" — so that a
     * correction can rewrite one occurrence in place.
     *
     * The text of an ICU branch is not a placeholder, even when it is one word between
     * braces: "{Madame}" in "{gender, select, female {Madame} other {}}" has exactly the
     * shape of the argument "{lastname}", and only its position tells them apart. Braces
     * are therefore followed one by one: a brace opened directly inside an argument
     * ("{gender, select, …" or "{count, plural, …") opens one of its branches, any other
     * opens an argument — at the message level as inside a branch, where "{name}" and
     * nested selects are arguments again.
     *
     * ICU apostrophe quoting ("'{'" for a literal brace) is deliberately not followed:
     * the checker does not know the catalogue's format, and an apostrophe before a brace
     * is ordinary French in a legacy message ("l'{{ value }}"), where following it would
     * hide the placeholder. A quoted brace is rare in a source, and appears on both sides
     * when it is kept.
     *
     * @return list<array{marker: string, offset: int, length: int}>
     */
    public function markers(string $text): array
    {
        $markers = [];
        // What each brace still open was opened as: an argument or a branch text.
        $openBraces = [];
        $length = \strlen($text);
        $offset = strcspn($text, '%<{}');

        while ($offset < $length) {
            $char = $text[$offset];
            $step = 1;

            if ('}' === $char) {
                // An extra closing brace (a broken value) leaves nothing to pop: the syntax
                // validator reports it, the checker keeps answering.
                array_pop($openBraces);
            } elseif ('{' === $char && self::ARGUMENT === end($openBraces)) {
                $openBraces[] = self::BRANCH;
            } elseif (1 === preg_match(self::MARKER_AT_OFFSET, $text, $match, 0, $offset)) {
                $step = \strlen($match[0]);

                if (isset($match[1])) {
                    $markers[] = ['marker' => '{'.$match[1], 'offset' => $offset, 'length' => $step];
                    $openBraces[] = self::ARGUMENT;
                } else {
                    // "</strong" pairs with "<strong".
                    $markers[] = ['marker' => str_replace('</', '<', $match[0]), 'offset' => $offset, 'length' => $step];
                }
            } elseif ('{' === $char) {
                // Not a valid argument name ("{ }", "{#"): still an opening, so that the
                // braces it contains are read as its branches and the closing one pops it.
                $openBraces[] = self::ARGUMENT;
            }

            $offset += $step;
            $offset += strcspn($text, '%<{}', $offset);
        }

        // In a pipe-separated plural, "{0}" and "{1,2}" are interval markers, not
        // placeholders: a language with other plural forms legitimately drops them.
        if (str_contains($text, '|')) {
            $markers = array_values(array_filter($markers, static fn (array $m): bool => 1 !== preg_match('/^\{\d+$/', $m['marker'])));
        }

        return $markers;
    }

    /**
     * @return array<string, int>
     */
    private function count(string $text): array
    {
        return array_count_values(array_column($this->markers($text), 'marker'));
    }
}
