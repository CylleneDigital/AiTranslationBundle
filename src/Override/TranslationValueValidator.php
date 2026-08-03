<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;

/**
 * Save-time syntax guard for a translation value: the runtime can only guess a stored
 * text's format, but at the moment of writing it we know the catalogue's declared
 * format (the +intl-icu file naming convention) and may refuse a value that would
 * display broken. Three checks:
 *
 *  - an ICU-formatted key must carry a pattern intl actually parses;
 *  - an ICU-formatted key must not carry legacy placeholders ("%name%") — intl would
 *    render them literally. A "|" is not judged: it is ordinary text in an ICU message,
 *    so a pipe plural there cannot be told from a literal bar;
 *  - a legacy-formatted key must not carry an ICU construct ("{x, plural, …}") — the
 *    legacy formatter would render it literally. A bare "{name}" is tolerated: it can
 *    be literal text.
 *
 * An unknown catalogue (orphan override) or an empty value is not judged. Placeholder
 * *consistency* against the source text is a separate concern
 * ({@see \CylleneDigital\AiTranslationBundle\Suggestion\PlaceholderConsistencyChecker}).
 */
final readonly class TranslationValueValidator
{
    public const string ISSUE_ICU_INVALID = 'syntax_icu_invalid';

    public const string ISSUE_LEGACY_IN_ICU = 'syntax_legacy_in_icu';

    public const string ISSUE_ICU_IN_LEGACY = 'syntax_icu_in_legacy';

    private const string LEGACY_PLACEHOLDER = '/%[a-zA-Z0-9_.]+%/';

    private const string ICU_CONSTRUCT = '/\{\s*[a-zA-Z0-9_]+\s*,\s*(?:plural|select|selectordinal|choice|number|date|time)\b/';

    public function __construct(
        private CatalogueRegistry $catalogues,
    ) {
    }

    /**
     * @return list<self::ISSUE_*> empty when the value is acceptable for this key
     */
    public function validate(string $value, string $key, string $catalogue, string $locale): array
    {
        if ('' === $value || null === $this->catalogues->getCatalogue($catalogue)) {
            return [];
        }

        if ($this->catalogues->usesIntlIcuFormatting($key, $catalogue, $locale)) {
            $issues = [];

            if (!$this->isValidIcuPattern($value, $locale)) {
                $issues[] = self::ISSUE_ICU_INVALID;
            }

            if (1 === preg_match(self::LEGACY_PLACEHOLDER, $value)) {
                $issues[] = self::ISSUE_LEGACY_IN_ICU;
            }

            return $issues;
        }

        return 1 === preg_match(self::ICU_CONSTRUCT, $value) ? [self::ISSUE_ICU_IN_LEGACY] : [];
    }

    private function isValidIcuPattern(string $value, string $locale): bool
    {
        if (!\extension_loaded('intl')) {
            return true;
        }

        try {
            new \MessageFormatter($locale, $value);

            return true;
        } catch (\IntlException) {
            return false;
        }
    }
}
