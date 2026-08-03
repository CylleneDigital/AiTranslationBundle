<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Repository;

use CylleneDigital\AiTranslationBundle\Entity\ScopeParameters;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScopeParameters>
 */
class ScopeParametersRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScopeParameters::class);
    }

    public function save(ScopeParameters $parameters): void
    {
        $this->getEntityManager()->persist($parameters);
        $this->getEntityManager()->flush();
    }

    /** Stages a write for a flush the caller owns — an integration package's own form handling. */
    public function saveDeferred(ScopeParameters $parameters): void
    {
        $this->getEntityManager()->persist($parameters);
    }

    public function remove(ScopeParameters $parameters): void
    {
        $this->getEntityManager()->remove($parameters);
        $this->getEntityManager()->flush();
    }

    /** Stages a removal without flushing — the counterpart of {@see saveDeferred()}. */
    public function removeDeferred(ScopeParameters $parameters): void
    {
        $this->getEntityManager()->remove($parameters);
    }
}
