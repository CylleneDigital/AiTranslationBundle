<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Entity;

use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An AI-generated translation waiting for a human review: approving it turns it into a
 * {@see TranslationOverride} (the row is kept as the audit trail of the approval);
 * rejecting it deletes the row — the key becomes eligible again for a later run.
 *
 * A suggestion carries the override scope it was generated for ('' = global, otherwise
 * an opaque host-defined code): approving writes the override in that scope, and the
 * suggestions are listed per scope.
 *
 * A row may also represent a FAILED generation: no suggested value, confidence 0 and the
 * provider error kept in `generation_error`. It surfaces like any other pending row — hand-editable then approvable — and a later "retry errors" run refills
 * it instead of duplicating it.
 */
#[ORM\Entity(repositoryClass: TranslationSuggestionRepository::class)]
#[ORM\Table(name: 'cyllene_translation_suggestion')]
// Every lookup filters on the status first (the pending queue, the reviewed rows the
// cleanup purges), then narrows by locale, catalogue and scope.
#[ORM\Index(name: 'idx_translation_suggestion_status_lookup', columns: ['status', 'locale', 'catalogue', 'scope'])]
#[ORM\UniqueConstraint(name: 'uniq_translation_suggestion_pending', columns: ['pending_key'])]
#[ORM\HasLifecycleCallbacks]
class TranslationSuggestion
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, enumType: SuggestionStatus::class, length: 20)]
    private SuggestionStatus $status = SuggestionStatus::Pending;

    /**
     * Uniqueness guard for the PENDING rows: a hash of (locale, catalogue, key, scope)
     * while the row awaits review, NULL once it is approved.
     *
     * Why a column rather than a plain unique constraint on the four fields: an approved
     * suggestion is KEPT as the audit trail of its approval, so a "re-translate every
     * key" run legitimately creates a new pending row for a key that already has an
     * approved one — a constraint on the four fields would refuse it. Only one row per
     * key may be pending at a time; approved rows accumulate freely (NULLs never collide
     * in a unique index, on MySQL/MariaDB as on PostgreSQL). This is the portable
     * equivalent of PostgreSQL's partial index, which MySQL does not have.
     *
     * Hashed rather than concatenated so the index stays small whatever the key length
     * (a 400-character key would otherwise push the entry past InnoDB's 3072-byte limit).
     * The value is never read — it exists for the database to arbitrate on.
     */
    #[ORM\Column(name: 'pending_key', type: Types::STRING, length: 32, nullable: true)]
    private ?string $pendingKey = null;

    /**
     * What the provider reported — `type`, `model` (DeepL: `detected_source_language`), and
     * for the LLM bridges `batch_usage`,
     * `batch_size` and `batch_id`, the same on every suggestion of a call — plus the keys
     * the bundle writes: `placeholder_mismatch` and `syntax_issues` (list<string>, what the
     * generated value lost or breaks), `only_missing` (bool, on an errored row: the mode of
     * the run that failed on it), and on approval `edited_on_approve` (bool),
     * `override_change` (`write`, `revert` or `none`) and, for a revert, `reverted_to`
     * (`inherited` or `file`).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    /**
     * The provider failure that produced this row instead of a value — null for a normal
     * suggestion, cleared when a later run fills the row.
     */
    #[ORM\Column(name: 'generation_error', type: Types::TEXT, nullable: true)]
    private ?string $generationError = null;

    #[ORM\Column(name: 'reviewed_by', type: Types::STRING, length: 255, nullable: true)]
    private ?string $reviewedBy = null;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct(
        // Same ceiling as the override table: a suggestion that could not become an
        // override would be a dead end.
        #[ORM\Column(name: 'translation_key', type: Types::STRING, length: TranslationOverride::MAX_KEY_LENGTH)]
        private string $key,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $catalogue,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $locale,
        #[ORM\Column(name: 'suggested_value', type: Types::TEXT, nullable: true)]
        private ?string $suggestedValue,
        #[ORM\Column(name: 'source_value', type: Types::TEXT)]
        private string $sourceValue,
        #[ORM\Column(name: 'source_locale', type: Types::STRING, length: 32)]
        private string $sourceLocale,
        #[ORM\Column(type: Types::STRING, length: TranslationProviderRegistry::MAX_NAME_LENGTH)]
        private string $provider,
        #[ORM\Column(type: Types::FLOAT)]
        private float $confidence,
        // '' = global, never NULL — same convention as TranslationOverride.
        #[ORM\Column(type: Types::STRING, length: ScopeRegistry::MAX_SCOPE_LENGTH, options: ['default' => ''])]
        private string $scope = '',
    ) {
        $this->initializeTimestamps();
        $this->refreshPendingKey();
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getCatalogue(): string
    {
        return $this->catalogue;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSuggestedValue(): ?string
    {
        return $this->suggestedValue;
    }

    public function getSourceValue(): string
    {
        return $this->sourceValue;
    }

    public function getSourceLocale(): string
    {
        return $this->sourceLocale;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getStatus(): SuggestionStatus
    {
        return $this->status;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    /**
     * @return array<string, mixed>|null see {@see $metadata} for the keys the bundle writes
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getReviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function isPending(): bool
    {
        return SuggestionStatus::Pending === $this->status;
    }

    public function isApproved(): bool
    {
        return SuggestionStatus::Approved === $this->status;
    }

    public function getGenerationError(): ?string
    {
        return $this->generationError;
    }

    public function hasGenerationError(): bool
    {
        return null !== $this->generationError;
    }

    /**
     * A run that failed before producing a value for this key: the row still surfaces in
     * with the other pending ones — hand-editable, and refillable by a later retry-errors run.
     */
    public static function failed(string $key, string $catalogue, string $locale, string $sourceValue, string $sourceLocale, string $provider, string $error, string $scope = ''): self
    {
        $suggestion = new self($key, $catalogue, $locale, null, $sourceValue, $sourceLocale, $provider, 0.0, $scope);
        $suggestion->generationError = $error;

        return $suggestion;
    }

    /** A later run succeeded for this key: the errored row becomes a normal suggestion. */
    public function fill(string $suggestedValue, float $confidence, string $provider, string $sourceValue): void
    {
        $this->suggestedValue = $suggestedValue;
        $this->confidence = $confidence;
        $this->provider = $provider;
        $this->sourceValue = $sourceValue;
        $this->generationError = null;
    }

    /** A later run failed again: same row, refreshed failure. */
    public function recordGenerationError(string $error, string $provider, string $sourceValue): void
    {
        $this->generationError = $error;
        $this->provider = $provider;
        $this->sourceValue = $sourceValue;
        $this->confidence = 0.0;
    }

    /** Marks the entity of a suggestion about to be deleted, so that it is not taken for a pending one. */
    public function reject(string $reviewedBy): void
    {
        $this->status = SuggestionStatus::Rejected;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->refreshPendingKey();
    }

    public function approve(string $reviewedBy): void
    {
        $this->status = SuggestionStatus::Approved;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = new \DateTimeImmutable();

        // The row leaves the pending set: it no longer competes for the key, so a later
        // run may propose a fresh suggestion for it.
        $this->refreshPendingKey();
    }

    /**
     * Keeps {@see $pendingKey} in sync with the status. Called wherever the status is
     * set — the constructor (always pending) and {@see approve()}; a rejected suggestion
     * is deleted, never transitioned.
     */
    private function refreshPendingKey(): void
    {
        $this->pendingKey = SuggestionStatus::Pending === $this->status
            ? hash('xxh128', $this->locale.'|'.$this->catalogue.'|'.$this->key.'|'.$this->scope)
            : null;
    }
}
