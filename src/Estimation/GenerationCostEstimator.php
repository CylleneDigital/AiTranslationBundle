<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Estimation;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Provider\CostEstimatingProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\UnknownProviderException;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionGenerator;

/**
 * Forecasts the volume and cost of a generation run BEFORE calling the provider, from
 * the exact key selection the run would use ({@see SuggestionGenerator::collectTexts}).
 *
 * The estimator only computes the workflow side (keys, source characters, API calls);
 * the billing side is polymorphic: providers implementing
 * {@see CostEstimatingProviderInterface} receive the prepared chunks and answer with
 * their own knowledge (the LLM bridges measure their real prompts and price them
 * against the LiteLLM list, DeepL reports the account quota). Providers without the
 * capability — e.g. a custom backend — degrade to volume-only figures.
 */
final class GenerationCostEstimator
{
    public function __construct(
        private readonly SuggestionGenerator $generator,
        private readonly CatalogueRegistry $catalogues,
    ) {
    }

    /**
     * @param string|null $catalogue null = every catalogue of the host project
     * @param string      $scope     the override scope the run would generate for ('' = global)
     */
    public function estimate(
        ?string $catalogue,
        string $targetLocale,
        string $sourceLocale,
        ?string $providerName = null,
        bool $onlyMissing = true,
        string $scope = '',
        bool $retryErrors = false,
    ): GenerationEstimate {
        $catalogues = null !== $catalogue ? [$catalogue] : $this->catalogues->getCatalogueIdentifiers();

        // The same provider the run would use — resolved the same way (the configured
        // default, else the only provider), scope context included, so the measured
        // prompts are the real ones. An unknown name still gets the volumes.
        try {
            $provider = $this->generator->resolveProvider($providerName, $scope);
            $providerName = $provider->getName();
        } catch (UnknownProviderException) {
            $provider = null;
            $providerName ??= '';
        }
        $estimating = $provider instanceof CostEstimatingProviderInterface ? $provider : null;

        $keys = 0;
        $characters = 0;
        $chunkCount = 0;
        $keysBeyondCap = 0;
        $inputTokens = null;
        $outputTokens = null;
        $cost = null;
        $currency = null;
        $quotaRemaining = null;
        $quotaLimit = null;
        $lowerBound = false;

        foreach ($catalogues as $currentCatalogue) {
            $texts = $this->generator->collectTexts($currentCatalogue, $targetLocale, $sourceLocale, $onlyMissing, $scope, $retryErrors);

            if ([] === $texts) {
                continue;
            }

            // The run stops at the per-run cap, so the forecast must too — otherwise a
            // 1200-key catalogue is quoted for 1200 keys and translates 500.
            if (\count($texts) > SuggestionGenerator::MAX_KEYS_PER_RUN) {
                $keysBeyondCap += \count($texts) - SuggestionGenerator::MAX_KEYS_PER_RUN;
                $texts = \array_slice($texts, 0, SuggestionGenerator::MAX_KEYS_PER_RUN, true);
            }

            $keys += \count($texts);

            foreach ($texts as $text) {
                $characters += mb_strlen($text);
            }

            $chunks = [];
            foreach ($this->generator->chunkTexts($texts) as $chunk) {
                $chunks[] = $chunk;
            }
            $chunkCount += \count($chunks);

            if (null === $estimating) {
                continue;
            }

            $runEstimate = $estimating->estimateRun($chunks, $sourceLocale, $targetLocale, $currentCatalogue);

            if (null !== $runEstimate->inputTokens) {
                $inputTokens = ($inputTokens ?? 0) + $runEstimate->inputTokens;
            }

            if (null !== $runEstimate->outputTokens) {
                $outputTokens = ($outputTokens ?? 0) + $runEstimate->outputTokens;
            }

            if (null !== $runEstimate->cost) {
                $cost = ($cost ?? 0.0) + $runEstimate->cost;
                $currency = $runEstimate->currency;
            }

            $lowerBound = $lowerBound || $runEstimate->lowerBound;

            // The quota is account-wide, not per catalogue: keep the last answer.
            $quotaRemaining = $runEstimate->quotaRemaining ?? $quotaRemaining;
            $quotaLimit = $runEstimate->quotaLimit ?? $quotaLimit;
        }

        return new GenerationEstimate(
            $providerName,
            $keys,
            $characters,
            $chunkCount,
            $inputTokens,
            $outputTokens,
            $cost,
            $currency,
            $quotaRemaining,
            $quotaLimit,
            $keysBeyondCap,
            $lowerBound,
        );
    }
}
