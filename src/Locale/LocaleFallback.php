<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Locale;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The one definition of "which locales answer for this locale", shared by the two sides
 * of the engine so they cannot drift apart.
 *
 * Symfony resolves a translation through the language chain: a request in "fr_FR" is
 * served by the "fr" resources as well as the "fr_FR" ones, the more specific winning.
 * {@see \CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner} has always
 * applied that rule to the FILES; the stored overrides used to be looked up on an exact
 * locale match, so an override saved on "fr" was invisible to a "fr_FR" request even
 * though the "fr" file value it was meant to replace was being served.
 *
 * Only the language chain: there is no cross-LANGUAGE fallback here. A key present in
 * another language is genuinely missing, which is what the coverage and generation flows
 * detect.
 */
#[Exclude]
final class LocaleFallback
{
    /** The shape of a locale code the bundle accepts: a language, then optional "_" or "-" parts. */
    public const string PATTERN = '/^[a-z]{2,3}([_-][A-Za-z0-9]+)*$/i';

    /**
     * The locales that answer for $locale, least specific first — the merge order, where
     * the last one merged wins:
     *
     *   "fr"          → ["fr"]
     *   "fr_FR"       → ["fr", "fr_FR"]
     *   "zh_Hant_TW"  → ["zh", "zh_Hant", "zh_Hant_TW"]
     *
     * @return non-empty-list<string>
     */
    public static function chain(string $locale): array
    {
        $chain = [];
        $prefix = '';

        foreach (self::segments($locale) as $segment) {
            $prefix = '' === $prefix ? $segment : $prefix.'_'.$segment;
            $chain[] = $prefix;
        }

        return [] === $chain ? [$locale] : $chain;
    }

    /**
     * How specific a locale is — its number of segments. Used as a merge rank: a higher
     * rank shadows a lower one for the same key.
     */
    public static function rank(string $locale): int
    {
        return \count(self::segments($locale));
    }

    /**
     * Whether $candidate answers for $locale: the locale itself, or one of the less
     * specific locales above it in the chain. "fr" covers "fr_FR"; "fr_FR" does not
     * cover "fr", and "fr" does not cover "frs" (a segment is never a text prefix).
     */
    public static function covers(string $candidate, string $locale): bool
    {
        // Compared case-insensitively, like the file scanner matches "messages.fr.yaml"
        // against a "fr_FR" request: the casing convention of a locale code is not
        // something either side gets to depend on.
        $candidateSegments = array_map(strtolower(...), self::segments($candidate));
        $localeSegments = array_map(strtolower(...), self::segments($locale));

        return $candidateSegments === \array_slice($localeSegments, 0, \count($candidateSegments));
    }

    /**
     * The locale's segments, with "fr-FR" normalised to "fr_FR" but the casing left
     * alone: {@see chain()} feeds an SQL comparison against stored locale codes, which a
     * case-sensitive collation would then miss.
     *
     * @return list<string>
     */
    private static function segments(string $locale): array
    {
        return array_values(array_filter(explode('_', str_replace('-', '_', $locale)), static fn (string $segment): bool => '' !== $segment));
    }
}
