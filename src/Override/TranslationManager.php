<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationCatalogue;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;

/**
 * One entry point over the whole translation engine, for integrations that would rather
 * inject a single service than four.
 *
 * It owns no logic of its own: every method forwards to the service that does. Reach for
 * that one instead when you can — a class that only reads catalogues should say so by
 * depending on the {@see CatalogueRegistry}, not on everything:
 *
 *   {@see CatalogueRegistry} — catalogues, locales, file values, ICU formatting
 *   {@see OverrideReader}    — stored overrides, merged views, orphans, counts
 *   {@see OverrideWriter}    — every write, with the side effects none of them may skip
 *   {@see ScopeRegistry}     — the declared scopes, and whether a code is one of them
 *
 * The bundle's own code depends on those directly; this facade exists for consumers.
 */
final readonly class TranslationManager implements TranslationManagerInterface
{
    public function __construct(
        private CatalogueRegistry $catalogues,
        private OverrideReader $reader,
        private OverrideWriter $writer,
        private ScopeRegistry $scopes,
    ) {
    }

    // ------------------------------------------------------------------ catalogues

    /**
     * @return list<TranslationCatalogue>
     */
    public function getCatalogues(): array
    {
        return $this->catalogues->getCatalogues();
    }

    public function getCatalogue(string $identifier): ?TranslationCatalogue
    {
        return $this->catalogues->getCatalogue($identifier);
    }

    /**
     * @return list<string>
     */
    public function getCatalogueIdentifiers(): array
    {
        return $this->catalogues->getCatalogueIdentifiers();
    }

    /**
     * @return list<string>
     */
    public function getAvailableLocales(): array
    {
        return $this->catalogues->getAvailableLocales();
    }

    /**
     * @return array<string, string> absolute path => reason
     */
    public function getIgnoredFiles(): array
    {
        return $this->catalogues->getIgnoredFiles();
    }

    /**
     * @return array<string, string> key => file value
     */
    public function getOriginalValues(string $catalogue, string $locale): array
    {
        return $this->catalogues->getOriginalValues($catalogue, $locale);
    }

    public function getOriginalValue(string $key, string $catalogue, string $locale): ?string
    {
        return $this->catalogues->getOriginalValue($key, $catalogue, $locale);
    }

    public function usesIntlIcuFormatting(string $key, string $catalogue, string $locale): bool
    {
        return $this->catalogues->usesIntlIcuFormatting($key, $catalogue, $locale);
    }

    // ------------------------------------------------------------------ scopes

    /**
     * @return array<string, string>
     */
    public function getAvailableScopes(): array
    {
        return $this->scopes->getAvailableScopes();
    }

    public function isKnownScope(string $scope): bool
    {
        return $this->scopes->isKnownScope($scope);
    }

    // ------------------------------------------------------------------ reading overrides

    /**
     * @return array<string, array{original: ?string, override: ?string, hasOverride: bool, inherited: ?string, inheritedFrom: array{locale: string, scope: string}|null}>
     */
    public function getTranslationsForCatalogue(string $catalogue, string $locale, string $scope = ''): array
    {
        return $this->reader->getTranslationsForCatalogue($catalogue, $locale, $scope);
    }

    /**
     * @return array<string, array<string, string>> catalogue => key => effective value
     */
    public function getEffectiveOverridesByCatalogue(string $locale, string $scope = ''): array
    {
        return $this->reader->getEffectiveOverridesByCatalogue($locale, $scope);
    }

    public function hasOverride(string $key, string $catalogue, string $locale, string $scope = ''): bool
    {
        return $this->reader->hasOverride($key, $catalogue, $locale, $scope);
    }

    public function getOverrideValue(string $key, string $catalogue, string $locale, string $scope = ''): ?string
    {
        return $this->reader->getOverrideValue($key, $catalogue, $locale, $scope);
    }

    /**
     * @return list<TranslationOverride>
     */
    public function getOverridesForLocale(string $locale, string $scope = ''): array
    {
        return $this->reader->getOverridesForLocale($locale, $scope);
    }

    /**
     * @return list<string>
     */
    public function findCataloguesForKey(string $key): array
    {
        return $this->reader->findCataloguesForKey($key);
    }

    /**
     * @return list<TranslationOverride>
     */
    public function findOrphanOverrides(): array
    {
        return $this->reader->findOrphanOverrides();
    }

    /** @see OverrideReader::countOrphanOverrides() */
    public function countOrphanOverrides(): int
    {
        return $this->reader->countOrphanOverrides();
    }

    public function countOverrides(): int
    {
        return $this->reader->countOverrides();
    }

    public function countOverridesByLocale(string $locale): int
    {
        return $this->reader->countOverridesByLocale($locale);
    }

    /**
     * @see OverrideReader::countOverridesPerLocale()
     *
     * @return array<string, int> locale => count, sorted by locale
     */
    public function countOverridesPerLocale(string $scope = ''): array
    {
        return $this->reader->countOverridesPerLocale($scope);
    }

    /**
     * @return array<string, array<string, array<string, string>>> locale => catalogue => key => value
     */
    public function exportOverrides(): array
    {
        return $this->reader->exportOverrides();
    }

    // ------------------------------------------------------------------ writing overrides

    /** Whether a key fits the override table — the caller's chance to refuse it with a message of its own. */
    public function acceptsKey(string $key): bool
    {
        return $this->writer->acceptsKey($key);
    }

    /**
     * @throws TranslationKeyTooLongException when the key exceeds what the table stores
     * @throws InvalidOverrideException       when the locale, the catalogue or the scope cannot be stored
     */
    public function saveOverride(string $key, string $catalogue, string $locale, string $value, ?string $originalValue = null, string $scope = ''): TranslationOverride
    {
        return $this->writer->save($key, $catalogue, $locale, $value, $originalValue, $scope);
    }

    /**
     * @param list<array{key: string, catalogue: string, locale: string, value: string, scope?: string, original?: ?string}> $entries ('' or no scope = global; original = the file value a new override is created over, recorded once)
     *
     * @return int number of distinct overrides written
     *
     * @throws TranslationKeyTooLongException raised before any write, so the batch is all or nothing
     * @throws InvalidOverrideException       likewise, for a locale, a catalogue or a scope
     */
    public function saveOverrides(array $entries): int
    {
        return $this->writer->saveMany($entries);
    }

    public function removeOverride(string $key, string $catalogue, string $locale, string $scope = ''): void
    {
        $this->writer->remove($key, $catalogue, $locale, $scope);
    }

    /**
     * Deletes every orphan in one pass — the one operation that genuinely spans reading
     * and writing, and the reason this facade is not purely mechanical. Only the orphans
     * are loaded, and each removal still dispatches its event.
     *
     * With no catalogue found at all, nothing is deleted: every override would count as
     * an orphan, and an empty scan is far likelier a misconfigured translations_path than
     * a project that dropped all its files.
     *
     * @return int the number of removed overrides
     */
    public function purgeOrphanOverrides(): int
    {
        if ([] === $this->catalogues->getCatalogueIdentifiers()) {
            return 0;
        }

        return $this->writer->removeMany($this->reader->findOrphanOverrides());
    }
}
