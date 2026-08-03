<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Catalogue;

/**
 * The locales offered for browsing, overriding and AI generation. The bundle default
 * ({@see ScannedLocaleProvider}) derives them from the scanned translation files; an
 * integration package typically re-aliases this interface to the host's own locale
 * source (e.g. the locales defined in the host's admin).
 */
interface LocaleProviderInterface
{
    /**
     * @return list<string> locale codes, e.g. ["en_US", "fr_FR"]
     */
    public function getAvailableLocales(): array;
}
