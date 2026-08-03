<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Entity;

use CylleneDigital\AiTranslationBundle\Repository\ScopeParametersRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The settings of one override scope ('' = global; otherwise an opaque code the host
 * defines — a site, a sales channel… — the same string the override and suggestion
 * tables carry). Today a
 * free-text context appended to the AI prompts of that scope's generation runs; the
 * row is the natural home for whatever else a scope may want later (a preferred
 * model…). The integration package owns the editing UI; the engine only reads it.
 */
#[ORM\Entity(repositoryClass: ScopeParametersRepository::class)]
#[ORM\Table(name: 'cyllene_translation_scope_parameters')]
#[ORM\HasLifecycleCallbacks]
class ScopeParameters
{
    use TimestampableTrait;

    #[ORM\Column(name: 'prompt_context', type: Types::TEXT, nullable: true)]
    private ?string $promptContext = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: ScopeRegistry::MAX_SCOPE_LENGTH)]
        private string $scope,
    ) {
        $this->initializeTimestamps();
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getPromptContext(): ?string
    {
        return $this->promptContext;
    }

    /** Stored trimmed; an empty text is stored as null (no context). */
    public function setPromptContext(?string $promptContext): self
    {
        $promptContext = null === $promptContext ? null : trim($promptContext);
        $this->promptContext = '' === $promptContext ? null : $promptContext;

        return $this;
    }

    /** True when the row carries nothing any more — the caller may delete it. */
    public function isEmpty(): bool
    {
        return null === $this->promptContext;
    }
}
