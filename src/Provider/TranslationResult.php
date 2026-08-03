<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Provider;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One translated string as returned by a provider. Confidence is the provider's
 * self-assessment when it exposes one, otherwise the bridge's fixed default — a sorting
 * hint for the review, never an approval criterion.
 */
#[Exclude]
final readonly class TranslationResult
{
    /**
     * @param array<string, mixed> $metadata provider-specific context (model, usage, …) kept for the reviewer
     */
    public function __construct(
        public string $translation,
        public float $confidence = 0.9,
        public array $metadata = [],
    ) {
    }
}
