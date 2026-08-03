<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Set at construction rather than on PrePersist: an entity is never without its dates,
 * so the columns are NOT NULL and the getters never return null.
 */
trait TimestampableTrait
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    protected \DateTimeImmutable $updatedAt;

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function initializeTimestamps(): void
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }
}
