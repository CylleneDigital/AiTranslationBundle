<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Catalogue;

/**
 * The catalogues and locales the bundle exposes, read from the host project's
 * translations/ files. No database, no writes: this is the file side of the engine.
 *
 * Deliberately narrow — the framework's own catalogues and those of other bundles are out
 * of scope. Only the host's translations are browsable and overridable, which is what
 * keeps a catalogue identifier ("shop/Product/messages") meaningful as a stored value.
 *
 * Locales come from the {@see LocaleProviderInterface} alias rather than from the scan, so
 * a host with its own locale registry answers for itself.
 */
final readonly class CatalogueRegistry
{
    public function __construct(
        private TranslationFileScanner $scanner,
        private LocaleProviderInterface $localeProvider,
    ) {
    }

    /**
     * @return list<TranslationCatalogue>
     */
    public function getCatalogues(): array
    {
        return $this->scanner->getCatalogues();
    }

    public function getCatalogue(string $identifier): ?TranslationCatalogue
    {
        return $this->scanner->getCatalogue($identifier);
    }

    /**
     * @return list<string>
     */
    public function getCatalogueIdentifiers(): array
    {
        return array_map(static fn (TranslationCatalogue $catalogue): string => $catalogue->identifier, $this->getCatalogues());
    }

    /**
     * @return list<string>
     */
    public function getAvailableLocales(): array
    {
        return $this->localeProvider->getAvailableLocales();
    }

    /**
     * The configured additional roots whose directory does not exist — their "@label/…"
     * catalogues are missing from the scan, which says nothing about whether they still
     * exist on a properly deployed server.
     *
     * @return array<string, string> label => configured path
     */
    public function getMissingAdditionalPaths(): array
    {
        return $this->scanner->getMissingAdditionalPaths();
    }

    /**
     * Files that could not be interpreted, with the reason — for the caller to surface
     * rather than let them vanish from the figures.
     *
     * @return array<string, string> absolute path => reason
     */
    public function getIgnoredFiles(): array
    {
        return $this->scanner->getIgnoredFiles();
    }

    /**
     * The catalogue's file entries for one locale, language fallback chain included
     * ("fr_FR" sees the "fr" files), no override applied. No cross-LANGUAGE fallback: a
     * key absent from the whole chain is genuinely missing, which is what the generation
     * flow detects.
     *
     * @return array<string, string> key => file value
     */
    public function getOriginalValues(string $catalogue, string $locale): array
    {
        return $this->scanner->getMessages($catalogue, $locale);
    }

    /** The file value of one entry, or null when the key is missing from the files. */
    public function getOriginalValue(string $key, string $catalogue, string $locale): ?string
    {
        return $this->scanner->getMessages($catalogue, $locale)[$key] ?? null;
    }

    /** Whether the translator formats this key with intl ({@see TranslationFileScanner::usesIntlIcu()}). */
    public function usesIntlIcuFormatting(string $key, string $catalogue, string $locale): bool
    {
        return $this->scanner->usesIntlIcu($key, $catalogue, $locale);
    }
}
