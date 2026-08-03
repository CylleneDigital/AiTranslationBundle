<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Repository;

use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Locale\LocaleFallback;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideExportFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TranslationOverride>
 */
class TranslationOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TranslationOverride::class);
    }

    public function save(TranslationOverride $override): void
    {
        $this->getEntityManager()->persist($override);
        $this->getEntityManager()->flush();
    }

    /**
     * Stages an override without flushing — for batch import, where a single
     * {@see flush()} at the end replaces N per-item round trips (and lets the cache be
     * invalidated once per touched locale).
     */
    public function saveDeferred(TranslationOverride $override): void
    {
        $this->getEntityManager()->persist($override);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function remove(TranslationOverride $override): void
    {
        $this->getEntityManager()->remove($override);
        $this->getEntityManager()->flush();
    }

    /** Stages a removal without flushing — the bulk counterpart of {@see saveDeferred()}. */
    public function removeDeferred(TranslationOverride $override): void
    {
        $this->getEntityManager()->remove($override);
    }

    public function findOneByKey(string $key, string $catalogue, string $locale, string $scope = ''): ?TranslationOverride
    {
        return $this->findOneBy(['key' => $key, 'catalogue' => $catalogue, 'locale' => $locale, 'scope' => $scope]);
    }

    /**
     * @return list<TranslationOverride>
     */
    public function findByLocaleAndCatalogue(string $locale, string $catalogue, string $scope = ''): array
    {
        /** @var list<TranslationOverride> $result */
        $result = $this->createQueryBuilder('t')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.catalogue = :catalogue')
            ->andWhere('t.scope = :scope')
            ->setParameter('locale', $locale)
            ->setParameter('catalogue', $catalogue)
            ->setParameter('scope', $scope)
            ->orderBy('t.key', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<TranslationOverride>
     */
    public function findByLocale(string $locale, string $scope = ''): array
    {
        /** @var list<TranslationOverride> $result */
        $result = $this->createQueryBuilder('t')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.scope = :scope')
            ->setParameter('locale', $locale)
            ->setParameter('scope', $scope)
            ->orderBy('t.catalogue', 'ASC')
            ->addOrderBy('t.key', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The overrides the runtime translator must consider for one request: the global
     * ones plus, when a scope is active, the scoped ones (which shadow the global) —
     * across the whole language chain of the locale ({@see LocaleFallback::chain()}), so
     * a "fr_FR" request also sees the overrides saved on "fr", exactly as it already
     * sees the values of the "messages.fr.yaml" files.
     *
     * The rows come back unsorted on the locale dimension: it is the caller that merges
     * them, because the precedence rules differ (the translator wants one value per key,
     * the coverage report one per catalogue). {@see LocaleFallback::rank()} is the rank
     * to merge them on.
     *
     * The rows come back as DETACHED objects, built from a scalar result: this runs on a
     * cache miss of the translator, inside whatever request needed a translation, and
     * managed entities would stay in the host's unit of work — every later flush() of
     * that request would compute a changeset over thousands of them. Read them, never
     * persist them (their id is null).
     *
     * @return list<TranslationOverride>
     */
    public function findForRuntime(string $locale, string $scope): array
    {
        /** @var list<array{key: string, catalogue: string, locale: string, scope: string, value: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.key', 't.catalogue', 't.locale', 't.scope', 't.value')
            ->andWhere('t.locale IN (:locales)')
            ->andWhere('t.scope IN (:scopes)')
            ->setParameter('locales', LocaleFallback::chain($locale))
            ->setParameter('scopes', '' === $scope ? [''] : ['', $scope])
            ->orderBy('t.catalogue', 'ASC')
            ->addOrderBy('t.key', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): TranslationOverride => (new TranslationOverride($row['key'], $row['catalogue'], $row['locale'], $row['scope']))->setValue($row['value']),
            $rows,
        );
    }

    /**
     * The overrides a (locale, scope) entry can inherit from, as scalar rows: every locale
     * of the language chain, in the global scope and in $scope — the entry's own rows
     * included, the caller sets them apart. Narrowed to one catalogue, and to one key, when
     * given: the reads behind a catalogue view, a baseline, an import.
     *
     * @param non-empty-list<string> $locales
     *
     * @return list<array{key: string, catalogue: string, locale: string, scope: string, value: string}>
     */
    public function findInheritable(array $locales, string $scope, ?string $catalogue = null, ?string $key = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.key', 't.catalogue', 't.locale', 't.scope', 't.value')
            ->andWhere('t.locale IN (:locales)')
            ->andWhere('t.scope IN (:scopes)')
            ->setParameter('locales', $locales)
            ->setParameter('scopes', '' === $scope ? [''] : ['', $scope]);

        if (null !== $catalogue) {
            $qb->andWhere('t.catalogue = :catalogue')->setParameter('catalogue', $catalogue);
        }

        if (null !== $key) {
            $qb->andWhere('t.key = :key')->setParameter('key', $key);
        }

        /** @var list<array{key: string, catalogue: string, locale: string, scope: string, value: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * {@see findInheritable()} for many keys at once, every catalogue: what a batch needs
     * to tell each of its entries' baseline without loading the whole locale.
     *
     * @param non-empty-list<string> $locales
     * @param list<string>           $keys
     *
     * @return list<array{key: string, catalogue: string, locale: string, scope: string, value: string}>
     */
    public function findInheritableByKeys(array $locales, string $scope, array $keys): array
    {
        $rows = [];

        // Bounded IN lists: some engines cap the bound parameters of one statement.
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            /** @var list<array{key: string, catalogue: string, locale: string, scope: string, value: string}> $found */
            $found = $this->createQueryBuilder('t')
                ->select('t.key', 't.catalogue', 't.locale', 't.scope', 't.value')
                ->andWhere('t.locale IN (:locales)')
                ->andWhere('t.scope IN (:scopes)')
                ->andWhere('t.key IN (:keys)')
                ->setParameter('locales', $locales)
                ->setParameter('scopes', '' === $scope ? [''] : ['', $scope])
                ->setParameter('keys', $chunk)
                ->getQuery()
                ->getArrayResult();

            array_push($rows, ...$found);
        }

        return $rows;
    }

    /**
     * The global overrides, ready to serialise. Scalar rows, filtered in SQL: the export
     * reads the whole table and has no use for managed entities.
     *
     * @return array<string, array<string, array<string, string>>> locale => catalogue => key => value
     */
    public function findAllGrouped(): array
    {
        $grouped = [];

        foreach ($this->findRowsForExport(scoped: false) as $row) {
            $grouped[$row['locale']][$row['catalogue']][$row['key']] = $row['value'];
        }

        return $grouped;
    }

    /**
     * The scoped overrides only, with the scope as the outer level.
     *
     * @return array<string, array<string, array<string, array<string, string>>>> scope => locale => catalogue => key => value
     */
    public function findScopedGrouped(): array
    {
        $grouped = [];

        foreach ($this->findRowsForExport(scoped: true) as $row) {
            $grouped[$row['scope']][$row['locale']][$row['catalogue']][$row['key']] = $row['value'];
        }

        return $grouped;
    }

    /**
     * @return list<array{scope: string, locale: string, catalogue: string, key: string, value: string}>
     */
    private function findRowsForExport(bool $scoped): array
    {
        /** @var list<array{scope: string, locale: string, catalogue: string, key: string, value: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.scope', 't.locale', 't.catalogue', 't.key', 't.value')
            ->where($scoped ? "t.scope <> ''" : "t.scope = ''")
            ->orderBy('t.locale', 'ASC')
            ->addOrderBy('t.catalogue', 'ASC')
            ->addOrderBy('t.key', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * The rows matching an export filter, narrowed in SQL. The catalogue criterion is a
     * prefix match ("shop" also takes "shop/Product/messages"), and $search matches the
     * key or the value, case-insensitively — the same semantics
     * {@see OverrideExportFilter::matches()}
     * applies in PHP for the callers that already hold entities.
     *
     * @return list<array{scope: string, locale: string, catalogue: string, key: string, value: string}>
     */
    public function findFilteredRowsForExport(OverrideExportFilter $filter): array
    {
        $qb = $this->filteredQueryBuilder($filter)
            ->select('t.scope', 't.locale', 't.catalogue', 't.key', 't.value')
            ->orderBy('t.locale', 'ASC')
            ->addOrderBy('t.catalogue', 'ASC')
            ->addOrderBy('t.key', 'ASC');

        /** @var list<array{scope: string, locale: string, catalogue: string, key: string, value: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $rows;
    }

    public function hasFilteredRows(OverrideExportFilter $filter): bool
    {
        return [] !== $this->filteredQueryBuilder($filter)->select('t.id')->setMaxResults(1)->getQuery()->getScalarResult();
    }

    /**
     * Every stored override as scalar rows, for listing: hydrating the whole table would
     * leave all of it managed by the host's entity manager.
     *
     * @return list<array{key: string, catalogue: string, locale: string, scope: string, value: string}>
     */
    public function findAllRows(): array
    {
        /** @var list<array{key: string, catalogue: string, locale: string, scope: string, value: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.key', 't.catalogue', 't.locale', 't.scope', 't.value')
            ->orderBy('t.locale', 'ASC')
            ->addOrderBy('t.catalogue', 'ASC')
            ->addOrderBy('t.key', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /** @return list<string> every key that has at least one stored override */
    public function findDistinctKeys(): array
    {
        /** @var list<string> $keys */
        $keys = $this->createQueryBuilder('t')->select('DISTINCT t.key')->getQuery()->getSingleColumnResult();

        return $keys;
    }

    /** @return list<string> the catalogues holding an override of this key */
    public function findCataloguesOfKey(string $key): array
    {
        /** @var list<string> $catalogues */
        $catalogues = $this->createQueryBuilder('t')
            ->select('DISTINCT t.catalogue')
            ->where('t.key = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getSingleColumnResult();

        return $catalogues;
    }

    private function filteredQueryBuilder(OverrideExportFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t');

        if (null !== $filter->locale) {
            $qb->andWhere('t.locale = :locale')->setParameter('locale', $filter->locale);
        }

        if (null !== $filter->scope) {
            $qb->andWhere('t.scope = :scope')->setParameter('scope', $filter->scope);
        }

        if (null !== $filter->catalogue) {
            $qb->andWhere("t.catalogue = :catalogue OR t.catalogue LIKE :cataloguePrefix ESCAPE '!'")
                ->setParameter('catalogue', $filter->catalogue)
                ->setParameter('cataloguePrefix', self::escapeLike($filter->catalogue).'/%');
        }

        if (null !== $filter->search && '' !== $filter->search) {
            $qb->andWhere("LOWER(t.key) LIKE :search ESCAPE '!' OR LOWER(t.value) LIKE :search ESCAPE '!'")
                ->setParameter('search', '%'.self::escapeLike(mb_strtolower($filter->search)).'%');
        }

        return $qb;
    }

    /**
     * The stored overrides of these keys under exactly one (locale, scope), indexed by
     * catalogue and key: a batch writing three keys must not load — and leave managed —
     * every override of the locale.
     *
     * @param list<string> $keys
     *
     * @return array<string, TranslationOverride> "catalogue|key" => override
     */
    public function findIndexedByKeys(string $locale, string $scope, array $keys): array
    {
        $indexed = [];

        // Bounded IN lists: some engines cap the bound parameters of one statement.
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            /** @var list<TranslationOverride> $overrides */
            $overrides = $this->createQueryBuilder('t')
                ->andWhere('t.locale = :locale')
                ->andWhere('t.scope = :scope')
                ->andWhere('t.key IN (:keys)')
                ->setParameter('locale', $locale)
                ->setParameter('scope', $scope)
                ->setParameter('keys', $chunk)
                ->getQuery()
                ->getResult();

            foreach ($overrides as $override) {
                $indexed[$override->getCatalogue().'|'.$override->getKey()] = $override;
            }
        }

        return $indexed;
    }

    /**
     * The overrides whose catalogue is not one of $catalogues, every scope included —
     * with an empty list, every override. The catalogues of the $unscannedRoots labels
     * ("@label/…") are left out: their files could not be read, so nothing is known
     * about them.
     *
     * @param list<string> $catalogues
     * @param list<string> $unscannedRoots
     *
     * @return list<TranslationOverride>
     */
    public function findOutsideCatalogues(array $catalogues, array $unscannedRoots = []): array
    {
        /** @var list<TranslationOverride> $result */
        $result = $this->outsideCatalogues($catalogues, $unscannedRoots)
            ->orderBy('t.locale', 'ASC')
            ->addOrderBy('t.catalogue', 'ASC')
            ->addOrderBy('t.key', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * How many overrides {@see findOutsideCatalogues()} would return, without hydrating them.
     *
     * @param list<string> $catalogues
     * @param list<string> $unscannedRoots
     */
    public function countOutsideCatalogues(array $catalogues, array $unscannedRoots = []): int
    {
        return (int) $this->outsideCatalogues($catalogues, $unscannedRoots)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The number of overrides stored under each locale for exactly one scope ('' = the
     * global ones; a scope's inherited global overrides are not counted). Only the
     * locales holding at least one override appear.
     *
     * @return array<string, int> locale => count, sorted by locale
     */
    public function countPerLocale(string $scope): array
    {
        /** @var list<array{locale: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.locale AS locale', 'COUNT(t.id) AS total')
            ->andWhere('t.scope = :scope')
            ->setParameter('scope', $scope)
            ->groupBy('t.locale')
            ->orderBy('t.locale', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_combine(
            array_column($rows, 'locale'),
            array_map(static fn (array $row): int => (int) $row['total'], $rows),
        );
    }

    /**
     * @param list<string> $catalogues
     * @param list<string> $unscannedRoots
     */
    private function outsideCatalogues(array $catalogues, array $unscannedRoots = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t');

        if ([] !== $catalogues) {
            $qb->andWhere('t.catalogue NOT IN (:catalogues)')
                ->setParameter('catalogues', $catalogues);
        }

        foreach (array_values($unscannedRoots) as $i => $label) {
            $qb->andWhere(\sprintf("t.catalogue NOT LIKE :root%d ESCAPE '!'", $i))
                ->setParameter('root'.$i, '@'.self::escapeLike($label).'/%');
        }

        return $qb;
    }

    /** "%" and "_" are LIKE wildcards, and labels, catalogues or a search may contain them. */
    private static function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    public function countOverrides(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOverridesByLocale(string $locale): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.locale = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
