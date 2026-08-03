<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The totals of a generation estimate, as {@see GenerationRunner::preview()} printed
 * them: what the caller confirms, or checks against a cost ceiling.
 */
#[Exclude]
final readonly class GenerationPreview
{
    public function __construct(
        public int $keys,
        /** Null when the provider's model is not priced (DeepL, a model absent from the list). */
        public ?float $cost,
        public ?string $currency,
        /** The cost is a floor: the model reasons, and those tokens are billed on top. */
        public bool $costIsLowerBound = false,
        /**
         * Keys left out of $cost because their catalogue could not be priced — the price
         * list can become unreachable between two catalogues: the total is then too low.
         */
        public int $unpricedKeys = 0,
    ) {
    }
}
