<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Locale\LocaleFallback;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;

/**
 * Reading the stored overrides, and merging them onto the file values.
 *
 * The counterpart of {@see OverrideWriter}: everything that answers "what does this
 * locale/catalogue/scope actually say" without writing anything. Where a question spans
 * both sides — a catalogue's entries with their overrides applied — the file half comes
 * from the {@see CatalogueRegistry}.
 */
final readonly class OverrideReader
{
    public function __construct(
        private TranslationOverrideRepository $repository,
        private CatalogueRegistry $catalogues,
    ) {
    }

    /**
     * The catalogue's file entries for one locale, merged with the stored overrides.
     *
     * `override` is the entry's own row — the one an edit changes and a removal deletes.
     * `inherited` is what the entry shows without it, read through the same chain as the
     * runtime: in a scope, the global override; for a regional locale ("fr_FR"), the
     * parent language's ("fr"). `inheritedFrom` says which row that is. So
     * `override ?? inherited ?? original` is what a visitor sees — the definition the
     * generation's missing keys, the coverage and the browser share; browsing shows the
     * value an entry falls back to, which makes "this changes nothing here" visible.
     *
     * @return array<string, array{original: ?string, override: ?string, hasOverride: bool, inherited: ?string, inheritedFrom: array{locale: string, scope: string}|null}>
     */
    public function getTranslationsForCatalogue(string $catalogue, string $locale, string $scope = ''): array
    {
        $empty = ['original' => null, 'override' => null, 'hasOverride' => false, 'inherited' => null, 'inheritedFrom' => null];
        $translations = [];

        foreach ($this->catalogues->getOriginalValues($catalogue, $locale) as $key => $value) {
            $translations[$key] = ['original' => $value] + $empty;
        }

        foreach ($this->repository->findByLocaleAndCatalogue($locale, $catalogue, $scope) as $override) {
            $key = $override->getKey();
            $translations[$key] ??= $empty;
            $translations[$key]['override'] = $override->getValue();
            $translations[$key]['hasOverride'] = true;
        }

        foreach ($this->inherited($locale, $scope, $catalogue)[$catalogue] ?? [] as $key => $row) {
            $translations[$key] ??= $empty;
            $translations[$key]['inherited'] = $row['value'];
            $translations[$key]['inheritedFrom'] = ['locale' => $row['locale'], 'scope' => $row['scope']];
        }

        ksort($translations);

        return $translations;
    }

    /**
     * Every override that applies in one locale, indexed by catalogue then key — ONE
     * query for the whole locale, where {@see getTranslationsForCatalogue()} costs one per
     * catalogue and is called in a catalogues x locales loop by the coverage report.
     * The scope's own rows shadow the global ones, exactly as at runtime — and so do the
     * more specific locales of the language chain ("fr_FR" over "fr"), which is what
     * makes the coverage figures match what a visitor of that locale actually sees.
     *
     * @return array<string, array<string, string>> catalogue => key => effective value
     */
    public function getEffectiveOverridesByCatalogue(string $locale, string $scope = ''): array
    {
        $overrides = $this->repository->findForRuntime($locale, $scope);

        // Least specific locale first, and within a locale the global rows before the
        // scope's own ones: the later passes overwrite.
        usort($overrides, static fn (TranslationOverride $a, TranslationOverride $b): int => [LocaleFallback::rank($a->getLocale()), '' === $a->getScope() ? 0 : 1] <=> [LocaleFallback::rank($b->getLocale()), '' === $b->getScope() ? 0 : 1]);

        $byCatalogue = [];

        foreach ($overrides as $override) {
            $byCatalogue[$override->getCatalogue()][$override->getKey()] = $override->getValue();
        }

        return $byCatalogue;
    }

    /**
     * What writing each of these values changes — {@see OverrideChange::decide()} for a
     * whole batch (an import, a batch approval) — with the entry's stored override and its
     * file value. The baseline of an entry is the state AFTER the batch: a parent the batch
     * writes is what a child inherits, and one it reverts no longer counts, though still
     * stored. So the parents are decided first — the least specific locale, then, within a
     * locale, the global entry before the scoped one — and each later entry reads their
     * outcome, then the database for the levels the batch leaves alone, then its file.
     *
     * Only the rows of the batch's keys are read: an import of three lines must not load —
     * and leave managed by the host's entity manager — every override of the locale.
     *
     * @param array<int, array{key: string, catalogue: string, locale: string, scope: string, value: string}> $entries one per (locale, catalogue, key, scope)
     *
     * @return array<int, array{change: OverrideChange, stored: ?TranslationOverride, fileValue: ?string, inherits: bool}> keyed like $entries, `inherits` = the baseline is an override, not the file value
     */
    public function planChanges(array $entries): array
    {
        /** @var array<string, array{locale: string, scope: string, keys: list<string>}> $groups */
        $groups = [];

        foreach ($entries as $entry) {
            $group = &$groups[$entry['locale']."\0".$entry['scope']];
            $group ??= ['locale' => $entry['locale'], 'scope' => $entry['scope'], 'keys' => []];
            $group['keys'][] = $entry['key'];
            unset($group);
        }

        /** @var array<string, TranslationOverride> $stored locale|scope|catalogue|key => the entry's own row */
        $stored = [];
        /** @var array<string, string> $database locale|scope|catalogue|key => value, every level an entry inherits from */
        $database = [];

        foreach ($groups as ['locale' => $locale, 'scope' => $scope, 'keys' => $keys]) {
            foreach ($this->repository->findIndexedByKeys($locale, $scope, $keys) as $override) {
                $stored[$locale."\0".$scope."\0".$override->getCatalogue()."\0".$override->getKey()] = $override;
            }

            foreach ($this->repository->findInheritableByKeys(LocaleFallback::chain($locale), $scope, $keys) as $row) {
                $database[$row['locale']."\0".$row['scope']."\0".$row['catalogue']."\0".$row['key']] = $row['value'];
            }
        }

        $order = array_keys($entries);
        usort($order, static fn (int $a, int $b): int => [LocaleFallback::rank($entries[$a]['locale']), '' === $entries[$a]['scope'] ? 0 : 1] <=> [LocaleFallback::rank($entries[$b]['locale']), '' === $entries[$b]['scope'] ? 0 : 1]);

        // What the batch leaves at each level it decides: the written value, or false for a
        // reverted override — gone, though the database still holds it.
        /** @var array<string, string|false> $planned */
        $planned = [];
        $plan = [];

        foreach ($order as $index) {
            ['key' => $key, 'catalogue' => $catalogue, 'locale' => $locale, 'scope' => $scope, 'value' => $value] = $entries[$index];
            $own = $locale."\0".$scope."\0".$catalogue."\0".$key;
            $fileValue = $this->catalogues->getOriginalValue($key, $catalogue, $locale);
            $baseline = $fileValue;
            $inherits = false;

            // The runtime's precedence: the more specific locale first, and within a locale
            // the scope's row before the global one — the entry's own row aside.
            foreach (array_reverse(LocaleFallback::chain($locale)) as $level) {
                foreach ('' === $scope ? [''] : [$scope, ''] as $levelScope) {
                    $at = $level."\0".$levelScope."\0".$catalogue."\0".$key;

                    if ($at === $own) {
                        continue;
                    }

                    $found = \array_key_exists($at, $planned) ? $planned[$at] : ($database[$at] ?? false);

                    if (false !== $found) {
                        $baseline = $found;
                        $inherits = true;
                        break 2;
                    }
                }
            }

            $change = OverrideChange::decide($value, $baseline, ($stored[$own] ?? null)?->getValue());

            match ($change) {
                OverrideChange::Write => $planned[$own] = $value,
                OverrideChange::Revert => $planned[$own] = false,
                OverrideChange::None => null,
            };

            $plan[$index] = ['change' => $change, 'stored' => $stored[$own] ?? null, 'fileValue' => $fileValue, 'inherits' => $inherits];
        }

        return $plan;
    }

    /** The file value of an entry, null when the file has none — what an override records as its original. */
    public function getFileValue(string $key, string $catalogue, string $locale): ?string
    {
        return $this->catalogues->getOriginalValue($key, $catalogue, $locale);
    }

    /**
     * What an entry shows without its own override: the override it inherits through the
     * runtime's chain — in a scope, the global one; for a regional locale, the parent
     * language's — else its file value. A value equal to it needs no override
     * ({@see OverrideChange}).
     */
    public function getBaselineValue(string $key, string $catalogue, string $locale, string $scope = ''): ?string
    {
        return $this->getInheritedValue($key, $catalogue, $locale, $scope) ?? $this->catalogues->getOriginalValue($key, $catalogue, $locale);
    }

    /**
     * The override an entry inherits through the runtime's chain — in a scope, the global
     * one; for a regional locale, the parent language's — null when it inherits none and
     * falls back to its file value.
     */
    public function getInheritedValue(string $key, string $catalogue, string $locale, string $scope = ''): ?string
    {
        return $this->inherited($locale, $scope, $catalogue, $key)[$catalogue][$key]['value'] ?? null;
    }

    public function hasOverride(string $key, string $catalogue, string $locale, string $scope = ''): bool
    {
        return null !== $this->repository->findOneByKey($key, $catalogue, $locale, $scope);
    }

    /** The stored override value of one entry, or null when none is stored. */
    public function getOverrideValue(string $key, string $catalogue, string $locale, string $scope = ''): ?string
    {
        return $this->repository->findOneByKey($key, $catalogue, $locale, $scope)?->getValue();
    }

    /**
     * Every override of one locale, whatever its catalogue.
     *
     * @return list<TranslationOverride>
     */
    public function getOverridesForLocale(string $locale, string $scope = ''): array
    {
        return $this->repository->findByLocale($locale, $scope);
    }

    /**
     * The catalogues that know one key — in their files (any available locale, fallback
     * chain included) or through a stored override, so an override-only key, orphans
     * included, is found too. Powers catalogue inference: a key known to a single
     * catalogue needs no explicit choice.
     *
     * @return list<string>
     */
    public function findCataloguesForKey(string $key): array
    {
        $found = [];
        $locales = $this->catalogues->getAvailableLocales();

        foreach ($this->catalogues->getCatalogueIdentifiers() as $catalogue) {
            foreach ($locales as $locale) {
                if (isset($this->catalogues->getOriginalValues($catalogue, $locale)[$key])) {
                    $found[] = $catalogue;
                    break;
                }
            }
        }

        foreach ($this->repository->findCataloguesOfKey($key) as $catalogue) {
            $found[] = $catalogue;
        }

        return array_values(array_unique($found));
    }

    /**
     * The overrides whose catalogue no longer exists as translation files — leftovers of a
     * deleted file, a renamed directory or a removed theme. They are inert (the translator
     * only shadows keys of loaded catalogues) but clutter the "modified only" views until
     * someone cleans them up. The catalogues of an additional root whose directory is
     * missing here are left out: unverifiable is not orphaned.
     *
     * @return list<TranslationOverride>
     */
    public function findOrphanOverrides(): array
    {
        return $this->repository->findOutsideCatalogues($this->catalogues->getCatalogueIdentifiers(), $this->unscannedRoots());
    }

    /**
     * How many overrides {@see findOrphanOverrides()} returns — every scope included, and
     * every override when no catalogue exists — counted without hydrating them.
     */
    public function countOrphanOverrides(): int
    {
        return $this->repository->countOutsideCatalogues($this->catalogues->getCatalogueIdentifiers(), $this->unscannedRoots());
    }

    /**
     * The additional roots whose directory is missing here: their "@label/…" overrides
     * are not orphans, only unverifiable — a theme not deployed on this machine, a typo in
     * additional_paths. Counting them as orphans would offer them for deletion.
     *
     * @return list<string>
     */
    private function unscannedRoots(): array
    {
        return array_map(strval(...), array_keys($this->catalogues->getMissingAdditionalPaths()));
    }

    public function countOverrides(): int
    {
        return $this->repository->countOverrides();
    }

    public function countOverridesByLocale(string $locale): int
    {
        return $this->repository->countOverridesByLocale($locale);
    }

    /**
     * The overrides stored under each locale for exactly one scope, in one query. '' counts
     * the global overrides; a scope counts its own, not the global ones it inherits. Keys
     * are the STORED locale codes — "fr" and "fr_FR" are two entries — and only the
     * locales holding at least one override appear. The scope is not validated: an
     * unknown one simply holds nothing.
     *
     * @return array<string, int> locale => count, sorted by locale
     */
    public function countOverridesPerLocale(string $scope = ''): array
    {
        return $this->repository->countPerLocale($scope);
    }

    /**
     * @return array<string, array<string, array<string, string>>> locale => catalogue => key => value
     */
    public function exportOverrides(): array
    {
        return $this->repository->findAllGrouped();
    }

    /**
     * The override each entry of (locale, scope) inherits — the one the runtime would
     * serve if the entry had no row of its own — with the row it comes from. The runtime's
     * precedence, first one wins: the more specific locale of the chain, then, within a
     * locale, the scope's row before the global one. For a "fr_FR" entry of scope "b2b":
     * the global "fr_FR" row, then the "b2b" "fr" row, then the global "fr" row.
     *
     * @return array<string, array<string, array{value: string, locale: string, scope: string}>> catalogue => key => row
     */
    private function inherited(string $locale, string $scope, ?string $catalogue = null, ?string $key = null): array
    {
        $rows = array_values(array_filter(
            $this->repository->findInheritable(LocaleFallback::chain($locale), $scope, $catalogue, $key),
            // The entry's own row is its override, not something it inherits.
            static fn (array $row): bool => $row['locale'] !== $locale || $row['scope'] !== $scope,
        ));

        usort($rows, static fn (array $a, array $b): int => [-LocaleFallback::rank($a['locale']), '' === $a['scope']] <=> [-LocaleFallback::rank($b['locale']), '' === $b['scope']]);

        $inherited = [];

        foreach ($rows as $row) {
            $inherited[$row['catalogue']][(string) $row['key']] ??= ['value' => $row['value'], 'locale' => $row['locale'], 'scope' => $row['scope']];
        }

        return $inherited;
    }
}
