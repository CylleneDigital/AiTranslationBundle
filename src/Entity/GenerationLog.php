<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Entity;

use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use CylleneDigital\AiTranslationBundle\Repository\GenerationLogRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Outcome of one suggestion generation run (one catalogue/locale pair, for one override
 * scope). The generation itself is fire-and-forget — dispatched to Messenger, provider
 * errors deliberately not retried — so this journal is the trace to query when a run
 * seems to have vanished, failures first-class.
 */
#[ORM\Entity(repositoryClass: GenerationLogRepository::class)]
#[ORM\Table(name: 'cyllene_translation_generation_log')]
#[ORM\Index(name: 'idx_generation_log_created_at', columns: ['created_at'])]
class GenerationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $catalogue,
        #[ORM\Column(name: 'target_locale', type: Types::STRING, length: 32)]
        private string $targetLocale,
        #[ORM\Column(name: 'source_locale', type: Types::STRING, length: 32)]
        private string $sourceLocale,
        #[ORM\Column(type: Types::STRING, length: TranslationProviderRegistry::MAX_NAME_LENGTH)]
        private string $provider,
        /** How many suggestions were stored before the run ended (fully, or on error). */
        #[ORM\Column(name: 'suggestions_created', type: Types::INTEGER)]
        private int $suggestionsCreated,
        #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
        private ?string $errorMessage,
        /** The override scope the run generated for ('' = global). */
        #[ORM\Column(type: Types::STRING, length: ScopeRegistry::MAX_SCOPE_LENGTH, options: ['default' => ''])]
        private string $scope = '',
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function success(string $catalogue, string $targetLocale, string $sourceLocale, string $provider, int $suggestionsCreated, string $scope = ''): self
    {
        return new self($catalogue, $targetLocale, $sourceLocale, $provider, $suggestionsCreated, null, $scope);
    }

    public static function failure(string $catalogue, string $targetLocale, string $sourceLocale, string $provider, string $errorMessage, int $suggestionsCreated = 0, string $scope = ''): self
    {
        return new self($catalogue, $targetLocale, $sourceLocale, $provider, $suggestionsCreated, $errorMessage, $scope);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCatalogue(): string
    {
        return $this->catalogue;
    }

    public function getTargetLocale(): string
    {
        return $this->targetLocale;
    }

    public function getSourceLocale(): string
    {
        return $this->sourceLocale;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getSuggestionsCreated(): int
    {
        return $this->suggestionsCreated;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function isFailure(): bool
    {
        return null !== $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
