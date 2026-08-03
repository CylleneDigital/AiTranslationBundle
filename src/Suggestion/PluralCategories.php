<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

/**
 * The CLDR plural categories a language uses, and the ones a translated ICU message
 * lacks for it.
 *
 * Languages do not share their categories: a French ordinal has two ("1er", "2e"), an
 * English one four ("1st", "2nd", "3rd", "4th"), a Polish plural one, few and many. A
 * translation keeping the source's branches is valid ICU — the syntax check passes — but
 * every number of a missing category falls back to "other": "your 2th order". Nothing
 * else catches it, so the LLM prompt names the target's categories and the review card
 * warns about the missing ones.
 *
 * Read from intl itself (formatting a probe message over 0–199), so no CLDR table is
 * copied here; without intl every method answers "unknown" and nothing is reported.
 * Categories only larger numbers reach — the French "many" of millions — are left out:
 * a message counting items would be warned about them for nothing.
 */
final class PluralCategories
{
    /** CLDR order, which is also the order the categories are listed in. */
    private const array CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other'];

    private const int SAMPLE = 200;

    /** @var array<string, array<string, list<int>>|null> "locale|kind" => category => integers */
    private static array $members = [];

    /**
     * The categories $locale uses for $kind ("plural" or "selectordinal"), "other" always
     * included (ICU requires it), in CLDR order; null without intl.
     *
     * @return list<string>|null
     */
    public static function of(string $locale, string $kind): ?array
    {
        $members = self::members($locale, $kind);

        if (null === $members) {
            return null;
        }

        return array_values(array_filter(self::CATEGORIES, static fn (string $category): bool => 'other' === $category || isset($members[$category])));
    }

    /**
     * The plural and selectordinal arguments of $message — nested ones included — that
     * lack a category $locale needs. A category is not missing when explicit "=N"
     * branches cover every number of it ("=1" covers the English "one", which is 1 alone).
     *
     * @return list<array{argument: string, kind: string, missing: list<string>}>
     */
    public static function missingIn(string $message, string $locale): array
    {
        if (false === preg_match_all('/\{\s*([a-zA-Z0-9_]+)\s*,\s*(plural|selectordinal)\s*,/', $message, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $reports = [];

        foreach ($matches as $match) {
            [$argument, $kind] = [$match[1][0], $match[2][0]];
            $members = self::members($locale, $kind);

            if (null === $members) {
                continue;
            }

            [$selectors, $exact] = self::branches($message, $match[0][1] + \strlen($match[0][0]));
            $missing = [];

            foreach ($members as $category => $numbers) {
                if ('other' !== $category && !\in_array($category, $selectors, true) && [] !== array_diff($numbers, $exact)) {
                    $missing[] = $category;
                }
            }

            if ([] !== $missing) {
                $reports[] = ['argument' => $argument, 'kind' => $kind, 'missing' => array_values(array_intersect(self::CATEGORIES, $missing))];
            }
        }

        return $reports;
    }

    /**
     * @return array<string, list<int>>|null category => the integers of 0–199 it covers
     */
    private static function members(string $locale, string $kind): ?array
    {
        if (!class_exists(\MessageFormatter::class)) {
            return null;
        }

        $cacheKey = $locale.'|'.$kind;

        if (\array_key_exists($cacheKey, self::$members)) {
            return self::$members[$cacheKey];
        }

        $probe = \MessageFormatter::create($locale, \sprintf('{n, %s, %s}', $kind, implode(' ', array_map(static fn (string $category): string => $category.'{'.$category.'}', self::CATEGORIES))));

        if (null === $probe) {
            return self::$members[$cacheKey] = null;
        }

        $members = [];

        for ($number = 0; $number < self::SAMPLE; ++$number) {
            $category = $probe->format(['n' => $number]);

            if (\is_string($category)) {
                $members[$category][] = $number;
            }
        }

        return self::$members[$cacheKey] = $members;
    }

    /**
     * The branch selectors of the argument whose header ends at $offset, and the numbers
     * of its "=N" branches. Stops quietly on a malformed message: the syntax check
     * reports those.
     *
     * @return array{list<string>, list<int>}
     */
    private static function branches(string $message, int $offset): array
    {
        $selectors = [];
        $exact = [];
        $length = \strlen($message);

        while ($offset < $length) {
            $offset += strspn($message, " \t\r\n", $offset);

            if (1 === preg_match('/\Goffset\s*:\s*\d+/', $message, $skip, 0, $offset)) {
                $offset += \strlen($skip[0]);

                continue;
            }

            if (1 !== preg_match('/\G(=\d+|[a-zA-Z]+)\s*\{/', $message, $branch, 0, $offset)) {
                break;
            }

            if (str_starts_with($branch[1], '=')) {
                $exact[] = (int) substr($branch[1], 1);
            } else {
                $selectors[] = $branch[1];
            }

            // Skip the branch body, braces balanced.
            $offset += \strlen($branch[0]);

            for ($depth = 1; $offset < $length && $depth > 0; ++$offset) {
                $depth += match ($message[$offset]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
            }
        }

        return [$selectors, $exact];
    }
}
