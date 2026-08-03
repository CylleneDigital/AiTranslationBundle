<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Provider;

/**
 * Optional capability of a translation provider: forecasting what a generation run
 * would cost, from the exact chunks the run would send. Each provider owns its billing
 * knowledge — the LLM bridges measure their real prompts and price them against the
 * LiteLLM list, DeepL reports the account's character quota. Providers that do not
 * implement this interface simply yield volume-only estimates.
 */
interface CostEstimatingProviderInterface
{
    /**
     * @param list<array<string, string>> $chunks the batches translate() would receive, in order
     */
    public function estimateRun(array $chunks, string $sourceLocale, string $targetLocale, string $catalogue): ProviderRunEstimate;
}
