<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Entity;

use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A stored override of one translation catalogue entry (locale + catalogue + key).
 * The catalogue is the relative path of the host's translation file without locale nor
 * extension ("shop/Product/messages" — see {@see \CylleneDigital\AiTranslationBundle\Catalogue\TranslationCatalogue}).
 * Applied at runtime by the {@see \CylleneDigital\AiTranslationBundle\Override\OverrideAwareTranslator}
 * on top of the file catalogues — updating the files never loses the customisation, no
 * file is touched.
 */
#[ORM\Entity(repositoryClass: TranslationOverrideRepository::class)]
#[ORM\Table(name: 'cyllene_translation_override')]
// Its leading columns also serve the lookups by locale, and by locale and catalogue: a
// separate index on either would only slow the writes down.
#[ORM\UniqueConstraint(name: 'uniq_translation_override_key', columns: ['locale', 'catalogue', 'translation_key', 'scope'])]
#[ORM\HasLifecycleCallbacks]
class TranslationOverride
{
    use TimestampableTrait;
    /**
     * Longest translation key the tables accept. Symfony allows any string as a key —
     * including a whole source sentence, the convention of the validation catalogues —
     * so the limit is a real one, not a formality. 400 utf8mb4 characters keep the
     * (locale 32, catalogue 255, translation_key, scope 64) unique index at 3004 bytes,
     * under the 3072-byte ceiling of a MySQL/MariaDB InnoDB index.
     */
    public const int MAX_KEY_LENGTH = 400;

    /** Catalogue identifiers are relative file paths. */
    public const int MAX_CATALOGUE_LENGTH = 255;

    public const int MAX_LOCALE_LENGTH = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $value = '';

    /** The catalogue value at override time, kept for the "reset to original" diff. */
    #[ORM\Column(name: 'original_value', type: Types::TEXT, nullable: true)]
    private ?string $originalValue = null;

    #[ORM\Column(name: 'updated_by', type: Types::STRING, length: 255, nullable: true)]
    private ?string $updatedBy = null;

    public function __construct(
        #[ORM\Column(name: 'translation_key', type: Types::STRING, length: self::MAX_KEY_LENGTH)]
        private string $key,
        #[ORM\Column(type: Types::STRING, length: self::MAX_CATALOGUE_LENGTH)]
        private string $catalogue,
        #[ORM\Column(type: Types::STRING, length: self::MAX_LOCALE_LENGTH)]
        private string $locale,
        // '' = global. Never null: a nullable column would break the unique constraint
        // (MySQL treats NULLs as always distinct).
        #[ORM\Column(type: Types::STRING, length: ScopeRegistry::MAX_SCOPE_LENGTH, options: ['default' => ''])]
        private string $scope = '',
    ) {
        $this->initializeTimestamps();
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

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getOriginalValue(): ?string
    {
        return $this->originalValue;
    }

    public function setOriginalValue(?string $originalValue): self
    {
        $this->originalValue = $originalValue;

        return $this;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?string $updatedBy): self
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}
