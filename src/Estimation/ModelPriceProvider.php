<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Estimation;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Per-token USD prices of the LLM models, resolved by model id. No provider exposes a
 * pricing API, so the default source is LiteLLM's continuously-maintained public price
 * list — the de-facto standard of the ecosystem (~3000 models, updated several times a
 * week).
 *
 * That default means one outgoing HTTPS request from the host application, which not
 * every deployment allows: `model_prices_url` points it at an internal mirror, or set it
 * to false to disable the lookup entirely and keep volume-only estimates.
 *
 * The reduced map (model => input/output price) is cached for 24 h in cache.app; a
 * fetch failure is cached for 5 min only and degrades gracefully: getPrices() returns
 * null and the estimator falls back to volume-only figures. List prices: negotiated
 * rates or non-USD billing will differ.
 */
final readonly class ModelPriceProvider
{
    public const string DEFAULT_SOURCE_URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    private const string CACHE_KEY = 'cyllene_translation_model_prices';

    private const int CACHE_TTL = 86400;

    private const int FAILURE_CACHE_TTL = 300;

    public function __construct(
        #[Autowire(service: 'cyllene_digital_ai_translation.http_client')]
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
        private LoggerInterface $logger,
        /** Source of the price list, or false when the host disabled the lookup. */
        #[Autowire(param: 'cyllene_digital_ai_translation.model_prices_url')]
        private string|false $sourceUrl = self::DEFAULT_SOURCE_URL,
    ) {
    }

    /**
     * @return array{input: float, output: float}|null USD per token, null when unknown
     */
    public function getPrices(string $model): ?array
    {
        if (false === $this->sourceUrl) {
            return null;
        }

        $prices = $this->getAllPrices();

        if (isset($prices[$model])) {
            return $prices[$model];
        }

        // LiteLLM prefixes some ids with the provider ("mistral/mistral-large-latest").
        // Written as a plain loop on purpose: array_find() is PHP 8.4 and the bundle
        // supports PHP 8.3 (the floor a Sylius 2.1 host runs on).
        foreach ($prices as $id => $price) {
            if (str_ends_with($id, '/'.$model)) {
                return $price;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{input: float, output: float}>
     */
    private function getAllPrices(): array
    {
        /** @var array<string, array{input: float, output: float}> $prices */
        $prices = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            try {
                $data = $this->httpClient->request('GET', (string) $this->sourceUrl, ['timeout' => 20, 'max_duration' => 60])->toArray();
            } catch (\Throwable $e) {
                // Do not blank the estimator for a day because of one network hiccup.
                $item->expiresAfter(self::FAILURE_CACHE_TTL);
                $this->logger->warning('Unable to fetch the LiteLLM model price list — cost estimation degrades to volumes only.', ['error' => $e->getMessage()]);

                return [];
            }

            // A third-party file, read from its main branch: a price that is not a finite,
            // non-negative number would let a run under --max-cost through (a negative
            // estimate is always below the ceiling), so it is ignored like a missing one.
            $prices = [];
            foreach ($data as $model => $entry) {
                if (!\is_string($model) || !\is_array($entry)) {
                    continue;
                }

                $input = $entry['input_cost_per_token'] ?? null;
                $output = $entry['output_cost_per_token'] ?? null;

                if (is_numeric($input) && is_numeric($output)
                    && is_finite((float) $input) && is_finite((float) $output)
                    && (float) $input >= 0 && (float) $output >= 0) {
                    $prices[$model] = ['input' => (float) $input, 'output' => (float) $output];
                }
            }

            return $prices;
        });

        return $prices;
    }
}
