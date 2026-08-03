<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Provider;

use CylleneDigital\AiTranslationBundle\Estimation\GenerationCostEstimator;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The provider-specific half of a generation forecast: what a run of prepared chunks
 * would cost on THIS backend. Token-billed providers fill the token/cost fields;
 * quota-billed ones (DeepL) fill the quota fields; a field is null when the provider
 * cannot answer (no price known, quota endpoint unreachable). The
 * {@see GenerationCostEstimator} composes it with the volume figures (keys, characters,
 * chunks) it computed itself.
 */
#[Exclude]
final readonly class ProviderRunEstimate
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?float $cost = null,
        public ?string $currency = null,
        public ?int $quotaRemaining = null,
        public ?int $quotaLimit = null,
        /** True when the real bill can be well above the figure (reasoning tokens billed as output). */
        public bool $lowerBound = false,
    ) {
    }
}
