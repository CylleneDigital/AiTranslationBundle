<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Bridge;

use CylleneDigital\AiTranslationBundle\Bridge\DeeplProvider;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Tests\InspectsUntypedData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class DeeplProviderTest extends TestCase
{
    use InspectsUntypedData;

    public function testTranslatesABatchAndMapsResultsBackByIndex(): void
    {
        $capturedUrl = null;
        $capturedBody = null;
        $capturedAuth = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedBody, &$capturedAuth): JsonMockResponse {
            $capturedUrl = $url;
            $capturedBody = self::jsonBody($options);
            $capturedAuth = self::header($options, 'authorization');

            return new JsonMockResponse([
                'translations' => [
                    ['detected_source_language' => 'FR', 'text' => 'Hello'],
                    ['detected_source_language' => 'FR', 'text' => 'Goodbye'],
                ],
            ]);
        });

        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123');
        $results = $provider->translate(
            ['app.hello' => 'Bonjour', 'app.bye' => 'Au revoir'],
            'fr_FR',
            'en_US',
            'messages',
        );

        self::assertSame('https://api.deepl.com/v2/translate', $capturedUrl);
        self::assertSame('Authorization: DeepL-Auth-Key key-123', $capturedAuth);
        self::assertIsArray($capturedBody);
        self::assertSame(['Bonjour', 'Au revoir'], $capturedBody['text']);
        self::assertSame('FR', $capturedBody['source_lang']);
        self::assertSame('EN-US', $capturedBody['target_lang']);

        self::assertSame('Hello', $results['app.hello']->translation);
        self::assertSame('Goodbye', $results['app.bye']->translation);
        self::assertSame('deepl', $results['app.hello']->metadata['type']);
    }

    public function testTheScopeContextTravelsAsDeeplContextParameter(): void
    {
        $bodies = [];

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): JsonMockResponse {
            $bodies[] = self::jsonBody($options);

            return new JsonMockResponse(['translations' => [['text' => 'Hi']]]);
        });

        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123');

        $provider->withAdditionalContext('Luxury fashion store.')->translate(['k' => 'Salut'], 'fr_FR', 'en_US', 'messages');
        $provider->translate(['k' => 'Salut'], 'fr_FR', 'en_US', 'messages');

        self::assertSame('Luxury fashion store.', self::at($bodies, 0, 'context'));
        self::assertArrayNotHasKey('context', $bodies[1]);
    }

    public function testFreeApiKeyTargetsTheFreeEndpoint(): void
    {
        $capturedUrl = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$capturedUrl): JsonMockResponse {
            $capturedUrl = $url;

            return new JsonMockResponse(['translations' => [['text' => 'Hi']]]);
        });

        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123:fx');
        $provider->translate(['k' => 'Salut'], 'fr_FR', 'en_US', 'messages');

        self::assertSame('https://api-free.deepl.com/v2/translate', $capturedUrl);
    }

    public function testBareEnglishAndPortugueseGetARegionalVariant(): void
    {
        $captured = [];

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): JsonMockResponse {
            $body = self::jsonBody($options);
            $captured[] = [$body['source_lang'], $body['target_lang']];

            return new JsonMockResponse(['translations' => [['text' => 'x']]]);
        });

        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123');
        $provider->translate(['k' => 'v'], 'fr', 'en', 'messages');
        $provider->translate(['k' => 'v'], 'en_GB', 'pt', 'messages');
        $provider->translate(['k' => 'v'], 'en', 'de', 'messages');

        self::assertSame([
            ['FR', 'EN-GB'],
            ['EN', 'PT-PT'],
            ['EN', 'DE'],
        ], $captured);
    }

    public function testRegionalVariantsCollapseForUnsupportedTargets(): void
    {
        $captured = [];

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): JsonMockResponse {
            $body = self::jsonBody($options);
            $captured[] = [$body['source_lang'], $body['target_lang']];

            return new JsonMockResponse(['translations' => [['text' => 'Hola']]]);
        });

        $provider = new DeeplProvider($httpClient, 'deepl', 'key');
        // DeepL rejects ES-ES: only EN and PT keep their region as target, and only
        // EN-US/EN-GB and PT-BR/PT-PT exist — the other regions take the European variant.
        foreach (['es_ES', 'pt_BR', 'en_US', 'en_GB', 'en_CA', 'en_AU', 'pt_PT', 'pt_AO'] as $target) {
            $provider->translate(['k' => 'Hello'], 'en_US', $target, 'shop/messages');
        }

        self::assertSame([
            ['EN', 'ES'],
            ['EN', 'PT-BR'],
            ['EN', 'EN-US'],
            ['EN', 'EN-GB'],
            ['EN', 'EN-GB'],
            ['EN', 'EN-GB'],
            ['EN', 'PT-PT'],
            ['EN', 'PT-PT'],
        ], $captured);
    }

    /** Taiwan, Hong Kong and the Hant script read Traditional Chinese — DeepL's ZH is Simplified. */
    public function testChineseTargetsKeepTheirScript(): void
    {
        $captured = [];

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): JsonMockResponse {
            $captured[] = self::jsonBody($options)['target_lang'];

            return new JsonMockResponse(['translations' => [['text' => '你好']]]);
        });

        $provider = new DeeplProvider($httpClient, 'deepl', 'key');

        foreach (['zh', 'zh_CN', 'zh_Hans', 'zh_TW', 'zh_HK', 'zh_Hant', 'zh_Hant_TW'] as $locale) {
            $provider->translate(['k' => 'Hello'], 'en', $locale, 'messages');
        }

        self::assertSame(['ZH-HANS', 'ZH-HANS', 'ZH-HANS', 'ZH-HANT', 'ZH-HANT', 'ZH-HANT', 'ZH-HANT'], $captured);
    }

    public function testTheApiErrorBodyIsSurfaced(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(
            ['message' => "Value for 'target_lang' not supported."],
            ['http_code' => 400],
        ));

        $provider = new DeeplProvider($httpClient, 'deepl', 'key');

        try {
            $provider->translate(['k' => 'Hello'], 'en_US', 'es_ES', 'shop/messages');
            self::fail('A TranslationProviderException was expected.');
        } catch (TranslationProviderException $e) {
            self::assertStringContainsString("DeepL says: Value for 'target_lang' not supported.", $e->getMessage());
        }
    }

    public function testQuotaErrorGetsAnActionableMessage(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['message' => 'Quota exceeded'], ['http_code' => 456]));
        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123');

        $this->expectException(TranslationProviderException::class);
        $this->expectExceptionMessage('quota exhausted');

        $provider->translate(['k' => 'v'], 'fr_FR', 'en_US', 'messages');
    }

    public function testTheStatusCodeDrivesTheQuotaMessageNotTheErrorText(): void
    {
        // A plain 400 whose body happens to mention "429" must not be reported as a
        // rate limit: the status code is the only thing that says so.
        $httpClient = new MockHttpClient(new JsonMockResponse(
            ['message' => 'Value for "text" is too long, max 429 characters'],
            ['http_code' => 400],
        ));
        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123');

        try {
            $provider->translate(['k' => 'v'], 'fr_FR', 'en_US', 'messages');
            self::fail('A 400 was expected to fail the batch.');
        } catch (TranslationProviderException $e) {
            self::assertStringNotContainsString('rate limited', $e->getMessage());
            self::assertStringContainsString('DeepL says: Value for "text" is too long', $e->getMessage());
        }
    }

    public function testUsageIsFetchedOnceAndMemoised(): void
    {
        $calls = 0;
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$calls): JsonMockResponse {
            ++$calls;
            self::assertSame('GET', $method);
            self::assertSame('https://api.deepl.com/v2/usage', $url);

            return new JsonMockResponse(['character_count' => 120000, 'character_limit' => 500000]);
        });

        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123');

        self::assertSame(['count' => 120000, 'limit' => 500000], $provider->getUsage());
        $provider->getUsage();
        self::assertSame(1, $calls, 'The second call is served from memory.');
    }

    public function testUsageDegradesToNullOnFailure(): void
    {
        // Counted: a second request would fail too (and degrade to null the same way),
        // so only the number of calls proves the failure is memoised.
        $calls = 0;
        $httpClient = new MockHttpClient(static function () use (&$calls): JsonMockResponse {
            ++$calls;

            return new JsonMockResponse(['message' => 'nope'], ['http_code' => 403]);
        });
        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123');

        self::assertNull($provider->getUsage());
        // The failure is memoised too — no retry storm within one run.
        self::assertNull($provider->getUsage());
        self::assertSame(1, $calls);
    }

    public function testEstimateRunAnswersWithTheAccountQuota(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['character_count' => 120000, 'character_limit' => 500000]));
        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123');

        $estimate = $provider->estimateRun([['app.a' => 'Bonjour']], 'fr_FR', 'en_US', 'messages');

        self::assertNull($estimate->inputTokens);
        self::assertNull($estimate->cost);
        self::assertSame(380000, $estimate->quotaRemaining);
        self::assertSame(500000, $estimate->quotaLimit);
    }

    public function testEstimateRunDegradesToAnEmptyEstimateWhenUsageIsUnavailable(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([], ['http_code' => 403]));
        $provider = new DeeplProvider($httpClient, 'deepl', 'key-123');

        $estimate = $provider->estimateRun([['app.a' => 'Bonjour']], 'fr_FR', 'en_US', 'messages');

        self::assertNull($estimate->quotaRemaining);
        self::assertNull($estimate->cost);
    }
}
