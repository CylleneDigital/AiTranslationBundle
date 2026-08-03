<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Repository;

use CylleneDigital\AiTranslationBundle\Entity\GenerationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GenerationLog>
 */
class GenerationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GenerationLog::class);
    }

    public function record(GenerationLog $log): void
    {
        $this->getEntityManager()->persist($log);
        $this->getEntityManager()->flush();
        // Written once, never changed: no reason to keep it in a long process's unit of work.
        $this->getEntityManager()->detach($log);
    }

    /**
     * Purges journal entries older than the threshold. The journal is a feedback surface,
     * not an audit trail — this retention purge is driven by the cleanup command (cron),
     * kept off the write path so recording a run is a single INSERT.
     *
     * @return int number of deleted rows
     */
    public function countOlderThan(\DateTimeImmutable $threshold): int
    {
        /** @var int|numeric-string $count */
        $count = $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->where('g.createdAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        /** @var int|numeric-string $deleted */
        $deleted = $this->createQueryBuilder('g')
            ->delete()
            ->where('g.createdAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();

        return (int) $deleted;
    }
}
