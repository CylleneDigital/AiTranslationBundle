<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Repository;

use CylleneDigital\AiTranslationBundle\Entity\SuggestionStatus;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TranslationSuggestion>
 */
class TranslationSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TranslationSuggestion::class);
    }

    public function save(TranslationSuggestion $suggestion): void
    {
        $this->getEntityManager()->persist($suggestion);
        $this->getEntityManager()->flush();
    }

    /**
     * Stages a suggestion without flushing — for batch generation, where a single
     * {@see flush()} at the end of a chunk/run replaces N per-item round trips.
     */
    public function saveDeferred(TranslationSuggestion $suggestion): void
    {
        $this->getEntityManager()->persist($suggestion);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /** Stops tracking these suggestions: later flushes no longer compute their changes. */
    public function detach(TranslationSuggestion ...$suggestions): void
    {
        foreach ($suggestions as $suggestion) {
            $this->getEntityManager()->detach($suggestion);
        }
    }

    /**
     * False once a failed flush closed the entity manager: nothing more can be written
     * through it — what a failing generation checks before journaling its failure.
     */
    public function isOpen(): bool
    {
        return $this->getEntityManager()->isOpen();
    }

    public function isTransactionActive(): bool
    {
        return $this->getEntityManager()->getConnection()->isTransactionActive();
    }

    /**
     * Runs $operation in a database transaction, rolled back if it throws. Not
     * EntityManager::wrapInTransaction(): that one also closes the entity manager on any
     * exception, a refused approval included — and the manager is the host's, still
     * needed by the rest of its request.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->beginTransaction();

        try {
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * Which of these suggestions are still pending IN DATABASE, rows locked until the
     * end of the caller's transaction: an entity loaded earlier (another request, a
     * double submission) may say pending although someone reviewed it since.
     *
     * @param list<TranslationSuggestion> $suggestions
     *
     * @return list<TranslationSuggestion>
     */
    public function lockStillPending(array $suggestions): array
    {
        $ids = array_values(array_filter(array_map(static fn (TranslationSuggestion $suggestion): ?int => $suggestion->getId(), $suggestions)));
        $pendingIds = [];

        if ([] !== $ids) {
            /** @var list<int|string> $rows */
            $rows = $this->createQueryBuilder('s')
                ->select('s.id')
                ->where('s.id IN (:ids)')
                ->andWhere('s.status = :pending')
                ->setParameter('ids', $ids)
                ->setParameter('pending', SuggestionStatus::Pending->value)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleColumnResult();

            $pendingIds = array_map(intval(...), $rows);
        }

        // Not stored yet: no other process can have reviewed it, the entity is the truth.
        return array_values(array_filter($suggestions, static fn (TranslationSuggestion $suggestion): bool => null === $suggestion->getId()
            ? $suggestion->isPending()
            : \in_array($suggestion->getId(), $pendingIds, true)));
    }

    public function remove(TranslationSuggestion $suggestion): void
    {
        $this->getEntityManager()->remove($suggestion);
        $this->getEntityManager()->flush();
    }

    /** Stages a removal without flushing — for batch rejection (one {@see flush()} for the set). */
    public function removeDeferred(TranslationSuggestion $suggestion): void
    {
        $this->getEntityManager()->remove($suggestion);
    }

    /**
     * The pending suggestions of one scope as an open query builder (root alias `o`),
     * for a caller to filter, sort and paginate — the integration packages' grids; the
     * bundle itself does not call it.
     *
     * @param string $scope '' = the global suggestions
     */
    public function createPendingQueryBuilder(string $scope = ''): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->andWhere('o.scope = :scope')
            ->setParameter('status', SuggestionStatus::Pending->value)
            ->setParameter('scope', $scope);
    }

    /**
     * The suggestions of these keys under exactly one (locale, scope), every catalogue,
     * still pending IN DATABASE (errored ones included), rows locked until the end of the
     * caller's transaction — what an override write supersedes. Like
     * {@see lockStillPending()}: an entity loaded earlier may say pending although someone
     * approved it since, and the identity map would not refresh it. The caller matches the
     * catalogue: a key is only unique within its catalogue.
     *
     * @param list<string> $keys
     *
     * @return list<TranslationSuggestion>
     */
    public function lockPendingByKeys(string $locale, string $scope, array $keys): array
    {
        $ids = [];

        // Bounded IN lists: some engines cap the bound parameters of one statement.
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            /** @var list<int|string> $rows */
            $rows = $this->createQueryBuilder('s')
                ->select('s.id')
                ->where('s.status = :status')
                ->andWhere('s.locale = :locale')
                ->andWhere('s.scope = :scope')
                ->andWhere('s.key IN (:keys)')
                ->setParameter('status', SuggestionStatus::Pending->value)
                ->setParameter('locale', $locale)
                ->setParameter('scope', $scope)
                ->setParameter('keys', $chunk)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleColumnResult();

            array_push($ids, ...array_map(intval(...), $rows));
        }

        if ([] === $ids) {
            return [];
        }

        $locked = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            array_push($locked, ...$this->findBy(['id' => $chunk]));
        }

        return $locked;
    }

    /**
     * Puts $suggestion back to its stored state — what a review that failed after changing
     * it in memory owes its caller: the rollback restored the database, not the entity, and
     * the host's next flush would write the change back. Nothing to do once the entity
     * manager is closed: no later flush can happen.
     */
    public function restore(TranslationSuggestion $suggestion): void
    {
        $entityManager = $this->getEntityManager();

        if ($entityManager->isOpen() && $entityManager->contains($suggestion)) {
            $entityManager->refresh($suggestion);
        }
    }

    /**
     * @param string|null $scope null = every scope; '' = the global suggestions only
     *
     * @return list<TranslationSuggestion>
     */
    public function findPending(?string $locale = null, ?string $catalogue = null, ?string $scope = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', SuggestionStatus::Pending->value)
            ->orderBy('s.confidence', 'DESC')
            ->addOrderBy('s.createdAt', 'ASC');

        if (null !== $locale) {
            $qb->andWhere('s.locale = :locale')->setParameter('locale', $locale);
        }

        if (null !== $catalogue) {
            $qb->andWhere('s.catalogue = :catalogue')->setParameter('catalogue', $catalogue);
        }

        if (null !== $scope) {
            $qb->andWhere('s.scope = :scope')->setParameter('scope', $scope);
        }

        /** @var list<TranslationSuggestion> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Number of pending suggestions matching the filters. $search matches the key, source
     * value or suggested value (case-insensitive LIKE).
     *
     * @param string|null $scope null = every scope; '' = the global suggestions only
     */
    public function countPendingFiltered(?string $locale, ?string $catalogue, ?string $search, ?string $scope = null): int
    {
        return (int) $this->pendingQuery($locale, $catalogue, $search, $scope)
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function pendingQuery(?string $locale, ?string $catalogue, ?string $search, ?string $scope): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', SuggestionStatus::Pending->value);

        if (null !== $scope) {
            $qb->andWhere('s.scope = :scope')->setParameter('scope', $scope);
        }

        if (null !== $locale && '' !== $locale) {
            $qb->andWhere('s.locale = :locale')->setParameter('locale', $locale);
        }

        if (null !== $catalogue && '' !== $catalogue) {
            $qb->andWhere('s.catalogue = :catalogue')->setParameter('catalogue', $catalogue);
        }

        if (null !== $search && '' !== $search) {
            // "%" and "_" are LIKE wildcards: a search for "50%" means those characters.
            $qb->andWhere("LOWER(s.key) LIKE :search ESCAPE '!' OR LOWER(s.sourceValue) LIKE :search ESCAPE '!' OR LOWER(s.suggestedValue) LIKE :search ESCAPE '!'")
                ->setParameter('search', '%'.strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']).'%');
        }

        return $qb;
    }

    /** Approved suggestions (the only reviewed rows kept) older than the threshold. */
    public function countReviewedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.status != :pending')
            ->andWhere('s.reviewedAt < :before')
            ->setParameter('pending', SuggestionStatus::Pending->value)
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return int number of deleted rows */
    public function deleteReviewedBefore(\DateTimeImmutable $before): int
    {
        /** @var int|numeric-string $deleted a DQL DELETE returns the affected row count */
        $deleted = $this->createQueryBuilder('s')
            ->delete()
            ->where('s.status != :pending')
            ->andWhere('s.reviewedAt < :before')
            ->setParameter('pending', SuggestionStatus::Pending->value)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        return (int) $deleted;
    }
}
