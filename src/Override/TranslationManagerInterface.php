<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Catalogue\TranslationCatalogue;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;

/**
 * The single entry point over the catalogues and the overrides: browsing the file
 * translations, reading and writing the database overrides. Type-hint this interface
 * rather than the implementation, which an integration can then decorate.
 */
interface TranslationManagerInterface
{
    /**
     * @return list<TranslationCatalogue>
     */
    public function getCatalogues(): array;

    public function getCatalogue(string $identifier): ?TranslationCatalogue;

    /**
     * @return list<string>
     */
    public function getCatalogueIdentifiers(): array;

    /**
     * @return list<string>
     */
    public function getAvailableLocales(): array;

    /**
     * @return array<string, string> absolute path => reason
     */
    public function getIgnoredFiles(): array;

    /**
     * @return array<string, string> key => file value
     */
    public function getOriginalValues(string $catalogue, string $locale): array;

    public function getOriginalValue(string $key, string $catalogue, string $locale): ?string;

    public function usesIntlIcuFormatting(string $key, string $catalogue, string $locale): bool;

    /**
     * @return array<string, string>
     */
    public function getAvailableScopes(): array;

    public function isKnownScope(string $scope): bool;

    /**
     * `override ?? inherited ?? original` is what a visitor sees: `override` is the entry's
     * own row, `inherited` the override it falls back to through the runtime chain (the
     * global one in a scope, the parent language's for a regional locale), named by
     * `inheritedFrom`.
     *
     * @see OverrideReader::getTranslationsForCatalogue()
     *
     * @return array<string, array{original: ?string, override: ?string, hasOverride: bool, inherited: ?string, inheritedFrom: array{locale: string, scope: string}|null}>
     */
    public function getTranslationsForCatalogue(string $catalogue, string $locale, string $scope = ''): array;

    /**
     * @return array<string, array<string, string>> catalogue => key => effective value
     */
    public function getEffectiveOverridesByCatalogue(string $locale, string $scope = ''): array;

    public function hasOverride(string $key, string $catalogue, string $locale, string $scope = ''): bool;

    public function getOverrideValue(string $key, string $catalogue, string $locale, string $scope = ''): ?string;

    /**
     * @return list<TranslationOverride>
     */
    public function getOverridesForLocale(string $locale, string $scope = ''): array;

    /**
     * @return list<string>
     */
    public function findCataloguesForKey(string $key): array;

    /**
     * @return list<TranslationOverride>
     */
    public function findOrphanOverrides(): array;

    /** @see OverrideReader::countOrphanOverrides() */
    public function countOrphanOverrides(): int;

    public function countOverrides(): int;

    public function countOverridesByLocale(string $locale): int;

    /**
     * @see OverrideReader::countOverridesPerLocale()
     *
     * @return array<string, int> locale => count, sorted by locale
     */
    public function countOverridesPerLocale(string $scope = ''): array;

    /**
     * @return array<string, array<string, array<string, string>>> locale => catalogue => key => value
     */
    public function exportOverrides(): array;

    /** Whether a key fits the override table — the caller's chance to refuse it with a message of its own. */
    public function acceptsKey(string $key): bool;

    /**
     * Writes what it is given, through OverrideWriter: author, cache invalidation,
     * OverrideSavedEvent, and the pending suggestions of the key closed as superseded. The
     * value is not checked against the catalogue syntax (run TranslationValueValidator
     * first if you want that guard), and a value equal to the baseline is written all the
     * same — the console editor, the approval and the import apply OverrideChange, this does
     * not. `$originalValue` is recorded when the override is created.
     *
     * @throws TranslationKeyTooLongException when the key exceeds what the table stores
     * @throws InvalidOverrideException       when the locale, the catalogue or the scope cannot be stored
     */
    public function saveOverride(string $key, string $catalogue, string $locale, string $value, ?string $originalValue = null, string $scope = ''): TranslationOverride;

    /**
     * @param list<array{key: string, catalogue: string, locale: string, value: string, scope?: string, original?: ?string}> $entries ('' or no scope = global; original = the file value a new override is created over, recorded once)
     *
     * @return int number of distinct overrides written
     *
     * @throws TranslationKeyTooLongException raised before any write, so the batch is all or nothing
     * @throws InvalidOverrideException       likewise, for a locale, a catalogue or a scope
     */
    public function saveOverrides(array $entries): int;

    public function removeOverride(string $key, string $catalogue, string $locale, string $scope = ''): void;

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
    public function purgeOrphanOverrides(): int;
}
