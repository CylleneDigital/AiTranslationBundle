<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Estimation;

use CylleneDigital\AiTranslationBundle\Estimation\ModelPriceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class ModelPriceProviderTest extends TestCase
{
    private const array LITELLM_SAMPLE = [
        'claude-sonnet-5' => ['input_cost_per_token' => 5e-06, 'output_cost_per_token' => 2.5e-05, 'max_tokens' => 32000],
        'mistral/mistral-large-latest' => ['input_cost_per_token' => 5e-07, 'output_cost_per_token' => 1.5e-06],
        'free-text-model' => ['max_tokens' => 8192],
    ];

    public function testResolvesExactAndProviderPrefixedModelIds(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new JsonMockResponse(self::LITELLM_SAMPLE)));

        self::assertSame(['input' => 5e-06, 'output' => 2.5e-05], $provider->getPrices('claude-sonnet-5'));
        self::assertSame(['input' => 5e-07, 'output' => 1.5e-06], $provider->getPrices('mistral-large-latest'));
        self::assertNull($provider->getPrices('unknown-model'));
        // Entries without both prices are ignored.
        self::assertNull($provider->getPrices('free-text-model'));
    }

    public function testThePriceListIsFetchedOnceThanksToTheCache(): void
    {
        $calls = 0;
        $httpClient = new MockHttpClient(static function () use (&$calls): JsonMockResponse {
            ++$calls;

            return new JsonMockResponse(self::LITELLM_SAMPLE);
        });

        $provider = $this->createProvider($httpClient);
        $provider->getPrices('claude-sonnet-5');
        $provider->getPrices('mistral-large-latest');

        self::assertSame(1, $calls);
    }

    public function testAFetchFailureDegradesToNullInsteadOfThrowing(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new JsonMockResponse([], ['http_code' => 503])));

        self::assertNull($provider->getPrices('claude-sonnet-5'));
    }

    private function createProvider(MockHttpClient $httpClient): ModelPriceProvider
    {
        return new ModelPriceProvider($httpClient, new ArrayAdapter(), new NullLogger());
    }

    public function testDisablingTheSourceKeepsTheEstimatorOffTheNetwork(): void
    {
        // Egress-restricted deployments: no price, no request, no failure to log. Counted
        // rather than failed from inside the client: the provider catches every
        // Throwable a fetch raises, a self::fail() included.
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): JsonMockResponse {
            ++$calls;

            return new JsonMockResponse(self::LITELLM_SAMPLE);
        });

        $provider = new ModelPriceProvider($client, new ArrayAdapter(), new NullLogger(), sourceUrl: false);

        self::assertNull($provider->getPrices('gpt-4o-mini'));
        self::assertSame(0, $calls);
    }

    /**
     * The list is a third-party file read from its main branch: a negative price would
     * put a run below any --max-cost ceiling, and a numeric model id used to crash the
     * suffix lookup.
     */
    public function testUnusablePricesAreIgnored(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new JsonMockResponse([
            '0' => ['input_cost_per_token' => 1e-06, 'output_cost_per_token' => 1e-06],
            'negative' => ['input_cost_per_token' => -0.01, 'output_cost_per_token' => 1e-06],
            'text' => ['input_cost_per_token' => 'free', 'output_cost_per_token' => 1e-06],
            'claude-sonnet-5' => ['input_cost_per_token' => 5e-06, 'output_cost_per_token' => 2.5e-05],
        ])));

        self::assertNull($provider->getPrices('negative'));
        self::assertNull($provider->getPrices('text'));
        self::assertNull($provider->getPrices('unknown'));
        self::assertSame(['input' => 5e-06, 'output' => 2.5e-05], $provider->getPrices('claude-sonnet-5'));
    }
}
