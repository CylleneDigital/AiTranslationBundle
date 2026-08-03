<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Catalogue;

/**
 * Default locale source: every locale that appears in the scanned translation files.
 * Good enough for a plain Symfony host; hosts with a locale registry of their own
 * override the {@see LocaleProviderInterface} alias instead.
 */
final readonly class ScannedLocaleProvider implements LocaleProviderInterface
{
    public function __construct(
        private TranslationFileScanner $scanner,
    ) {
    }

    public function getAvailableLocales(): array
    {
        return $this->scanner->getLocales();
    }
}
