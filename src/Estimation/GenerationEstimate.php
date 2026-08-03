<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Estimation;

use CylleneDigital\AiTranslationBundle\Provider\ProviderRunEstimate;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Rough volume/cost forecast of one generation run, computed from the exact key
 * selection the run would use. Token counts apply to token-billed providers only;
 * cost comes from the LiteLLM public price list (USD list prices); the quota fields
 * come from DeepL's /v2/usage. Every optional field is null when its source cannot
 * answer — the consumer displays what it gets.
 */
#[Exclude]
final readonly class GenerationEstimate
{
    public function __construct(
        public string $provider,
        public int $keys,
        /** Source characters of the selected texts (what character-billed providers charge). */
        public int $characters,
        /** Provider API calls the run would make (batching caps). */
        public int $chunks,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?float $cost,
        public ?string $currency,
        /** DeepL: characters left on the plan before this run. */
        public ?int $quotaRemaining = null,
        /** DeepL: total characters of the plan. */
        public ?int $quotaLimit = null,
        /**
         * How many keys were left out of the figures because a catalogue exceeded
         * {@see \CylleneDigital\AiTranslationBundle\Suggestion\TranslationAiService::MAX_KEYS_PER_RUN}
         * — the run would stop at the cap too, so they belong to a later run, not to
         * this estimate.
         */
        public int $keysBeyondCap = 0,
        /** True when the cost is a floor, not a forecast — see {@see ProviderRunEstimate::$lowerBound}. */
        public bool $costIsLowerBound = false,
    ) {
    }

    public function isPriced(): bool
    {
        return null !== $this->cost;
    }

    /** Whether the per-run cap left keys out of these figures. */
    public function isCapped(): bool
    {
        return $this->keysBeyondCap > 0;
    }
}
