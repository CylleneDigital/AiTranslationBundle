<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Bridge;

use CylleneDigital\AiTranslationBundle\Bridge\DefaultProvider;
use CylleneDigital\AiTranslationBundle\Estimation\ModelPriceProvider;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Tests\InspectsUntypedData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The generic /chat/completions bridge: nothing is assumed about the backend, so every
 * test names its own endpoint and model. OpenAI's own defaults live in
 * {@see OpenAiProviderTest}.
 */
final class DefaultProviderTest extends TestCase
{
    use InspectsUntypedData;

    private const string BASE_URI = 'https://api.example.test/v1';

    /** The usage is the call's: the suggestions of one call share an id, two calls never do. */
    public function testEachCallCarriesItsOwnBatchId(): void
    {
        $httpClient = new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse([
            'choices' => [['message' => ['content' => '{"app.hello": "Bonjour", "app.bye": "Au revoir"}']]],
            'usage' => ['total_tokens' => 42],
        ]));
        $provider = new DefaultProvider($httpClient, 'mistral', 'mistral-small', self::BASE_URI, 'sk-test');

        $first = $provider->translate(['app.hello' => 'Hello', 'app.bye' => 'Goodbye'], 'en_US', 'fr_FR', 'messages');
        $second = $provider->translate(['app.hello' => 'Hello', 'app.bye' => 'Goodbye'], 'en_US', 'fr_FR', 'messages');

        self::assertIsString($first['app.hello']->metadata['batch_id']);
        self::assertSame($first['app.hello']->metadata['batch_id'], $first['app.bye']->metadata['batch_id']);
        self::assertNotSame($first['app.hello']->metadata['batch_id'], $second['app.hello']->metadata['batch_id']);
    }

    public function testTranslatesABatchThroughTheChatCompletionsEndpoint(): void
    {
        $capturedUrl = null;
        $capturedBody = null;
        $capturedAuth = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedBody, &$capturedAuth): JsonMockResponse {
            $capturedUrl = $url;
            $capturedBody = self::jsonBody($options);
            $capturedAuth = self::header($options, 'authorization');

            return new JsonMockResponse([
                'choices' => [
                    ['message' => ['content' => '{"app.hello": "Bonjour", "app.bye": "Au revoir"}']],
                ],
                'usage' => ['total_tokens' => 42],
            ]);
        });

        $provider = new DefaultProvider($httpClient, 'mistral', 'mistral-small', 'https://api.mistral.ai/v1', 'sk-test');

        $results = $provider->translate(
            ['app.hello' => 'Hello', 'app.bye' => 'Goodbye'],
            'en_US',
            'fr_FR',
            'messages',
        );

        self::assertSame('https://api.mistral.ai/v1/chat/completions', $capturedUrl);
        self::assertSame('Authorization: Bearer sk-test', $capturedAuth);
        self::assertIsArray($capturedBody);
        self::assertSame('mistral-small', $capturedBody['model']);
        self::assertIsArray($capturedBody['messages']);
        self::assertCount(2, $capturedBody['messages']);
        self::assertSame('system', self::at($capturedBody, 'messages', 0, 'role'));
        self::assertStringContainsString('"en_US" to "fr_FR"', self::stringAt($capturedBody, 'messages', 0, 'content'));
        self::assertStringContainsString('app.hello', self::stringAt($capturedBody, 'messages', 1, 'content'));

        self::assertCount(2, $results);
        self::assertSame('Bonjour', $results['app.hello']->translation);
        self::assertSame('Au revoir', $results['app.bye']->translation);
        self::assertSame(0.2, $capturedBody['temperature']);
        self::assertSame(8192, $capturedBody['max_tokens']);
        // No response_format: the servers speaking this contract disagree on it, and one
        // that chokes on the parameter fails the whole call.
        self::assertArrayNotHasKey('response_format', $capturedBody);
        self::assertSame('mistral-small', $results['app.hello']->metadata['model']);
        // The usage is the whole call's: named as such, with the number of keys it covers,
        // never presented as one key's (summing the rows counted it once per key).
        self::assertSame(['total_tokens' => 42], $results['app.hello']->metadata['batch_usage']);
        self::assertSame(2, $results['app.hello']->metadata['batch_size']);
        self::assertArrayNotHasKey('usage', $results['app.bye']->metadata);
        // The metadata names the wire contract, not a vendor: this bridge is not OpenAI.
        self::assertSame('chat_completions', $results['app.hello']->metadata['type']);
    }

    /** A reply cut at the output cap says so, instead of a misleading "not valid JSON". */
    public function testATruncatedReplyIsReportedAsSuch(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'choices' => [['message' => ['content' => '{"app.hello": {"t": "Bonj'], 'finish_reason' => 'length']],
        ]));

        $provider = new DefaultProvider($httpClient, 'mistral', 'mistral-small', 'https://api.mistral.ai/v1', 'sk-test');

        try {
            $provider->translate(['app.hello' => 'Hello'], 'en_US', 'fr_FR', 'messages');
            self::fail('A truncated reply must be refused.');
        } catch (TranslationProviderException $e) {
            self::assertTrue($e->isInvalidResponse());
            self::assertStringContainsString('output cap', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableReplies(): iterable
    {
        yield 'not JSON' => ['{"app.hello": Bonjour}', 'not valid JSON'];
        yield 'no object at all' => ['"Bonjour"', 'no JSON object found'];
    }

    #[DataProvider('unusableReplies')]
    public function testAnUnusableReplyIsAnInvalidResponse(string $content, string $reason): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['choices' => [['message' => ['content' => $content]]]]));
        $provider = new DefaultProvider($httpClient, 'mistral', 'mistral-small', 'https://api.mistral.ai/v1', 'sk-test');

        try {
            $provider->translate(['app.hello' => 'Hello'], 'en_US', 'fr_FR', 'messages');
            self::fail('An unusable reply must be refused.');
        } catch (TranslationProviderException $e) {
            // Only its own batch is lost: the run goes on with the next ones.
            self::assertTrue($e->isInvalidResponse());
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    public function testTheConfiguredTimeoutReachesTheRequest(): void
    {
        $capturedTimeout = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedTimeout): JsonMockResponse {
            $capturedTimeout = $options['timeout'] ?? null;

            return new JsonMockResponse([
                'choices' => [['message' => ['content' => '{"app.hello": "Bonjour"}']]],
            ]);
        });

        $provider = new DefaultProvider($httpClient, 'ollama', 'llama3', 'http://localhost:11434/v1', timeout: 600);
        $provider->translate(['app.hello' => 'Hello'], 'en_US', 'fr_FR', 'messages');

        self::assertSame(600.0, $capturedTimeout);
    }

    /** A redirect would only come from a misconfigured base_uri, and would carry the key. */
    public function testRedirectsAreNotFollowed(): void
    {
        $capturedRedirects = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedRedirects): JsonMockResponse {
            $capturedRedirects = $options['max_redirects'] ?? null;

            return new JsonMockResponse([
                'choices' => [['message' => ['content' => '{"app.hello": "Bonjour"}']]],
            ]);
        });

        (new DefaultProvider($httpClient, 'mistral', 'mistral-small', 'https://api.mistral.ai/v1', 'sk-test'))
            ->translate(['app.hello' => 'Hello'], 'en_US', 'fr_FR', 'messages');

        self::assertSame(0, $capturedRedirects);
    }

    public function testCustomBaseUriAndKeylessServer(): void
    {
        $capturedUrl = null;
        $hadAuthHeader = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$hadAuthHeader): JsonMockResponse {
            $capturedUrl = $url;
            $hadAuthHeader = null !== self::header($options, 'authorization');

            return new JsonMockResponse([
                'choices' => [['message' => ['content' => '{"k": "v"}']]],
            ]);
        });

        $provider = new DefaultProvider($httpClient, 'ollama', 'llama3', 'http://localhost:11434/v1/');
        $provider->translate(['k' => 'value'], 'en_US', 'fr_FR', 'messages');

        self::assertSame('http://localhost:11434/v1/chat/completions', $capturedUrl);
        self::assertFalse($hadAuthHeader);
    }

    public function testParsesThePerKeyConfidenceShape(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'choices' => [
                ['message' => ['content' => '{"a": {"t": "Alpha", "c": 0.72}, "b": {"t": "Beta", "c": 7}, "c": "Gamma"}']],
            ],
        ]));

        $provider = new DefaultProvider($httpClient, 'llm', 'a-model', self::BASE_URI, 'sk-test');
        $results = $provider->translate(['a' => 'A', 'b' => 'B', 'c' => 'C'], 'en_US', 'fr_FR', 'messages');

        self::assertSame(0.72, $results['a']->confidence);
        self::assertSame(1.0, $results['b']->confidence);   // clamped
        self::assertSame(0.9, $results['c']->confidence);   // plain string tolerated → bridge default
        self::assertSame('Gamma', $results['c']->translation);
    }

    public function testTheContextIsInjectedIntoTheSystemPrompt(): void
    {
        $capturedSystem = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedSystem): JsonMockResponse {
            $capturedSystem = self::stringAt(self::jsonBody($options), 'messages', 0, 'content');

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"k": "v"}']]]]);
        });

        $provider = new DefaultProvider(
            $httpClient,
            'llm',
            'a-model',
            self::BASE_URI,
            'sk-test',
            llmContext: 'B2B video-games marketplace, formal tone. Always translate Cart as Panier.',
        );
        $provider->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');

        self::assertIsString($capturedSystem);
        self::assertStringContainsString("Project context:\nB2B video-games marketplace, formal tone. Always translate Cart as Panier.", $capturedSystem);
    }

    /**
     * The prompt used to demand the source's branch labels: an English ordinal translated
     * from French kept "one"/"other" and rendered "2th", "3th".
     */
    #[RequiresPhpExtension('intl')]
    public function testThePromptAsksForTheTargetLanguagesPluralCategories(): void
    {
        $capturedSystem = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedSystem): JsonMockResponse {
            $capturedSystem = self::stringAt(self::jsonBody($options), 'messages', 0, 'content');

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"k": "v"}']]]]);
        });

        (new DefaultProvider($httpClient, 'llm', 'a-model', self::BASE_URI, 'sk-test'))->translate(['k' => 'v'], 'fr_FR', 'en_US', 'messages');

        self::assertIsString($capturedSystem);
        self::assertStringContainsString('the plural categories of the TARGET language', $capturedSystem);
        self::assertStringNotContainsString('same branch labels', $capturedSystem);
        self::assertStringContainsString('Plural categories of "en_US": plural → one, other; selectordinal → one, two, few, other.', $capturedSystem);
    }

    public function testTheScopeContextIsAppendedToTheSystemPromptOfTheCloneOnly(): void
    {
        $prompts = [];

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$prompts): JsonMockResponse {
            $prompts[] = self::stringAt(self::jsonBody($options), 'messages', 0, 'content');

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"k": "v"}']]]]);
        });

        $provider = new DefaultProvider($httpClient, 'llm', 'a-model', self::BASE_URI, 'sk-test', llmContext: 'Project-wide context.');
        $scoped = $provider->withAdditionalContext('  Outlet channel: playful tone.  ');

        $scoped->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
        $provider->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');

        self::assertStringContainsString("Project context:\nProject-wide context.", $prompts[0]);
        self::assertStringContainsString("Scope context (the channel or site these strings are for):\nOutlet channel: playful tone.", $prompts[0]);
        // The shared instance is untouched — and a blank context adds no section.
        self::assertStringNotContainsString('Scope context', $prompts[1]);
        $provider->withAdditionalContext('   ')->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
        self::assertStringNotContainsString('Scope context', $prompts[2]);
    }

    public function testToleratesCodeFencesAndSkipsHallucinatedKeys(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'choices' => [
                ['message' => ['content' => "Here you go:\n```json\n{\"real.key\": \"Vrai\", \"made.up\": \"Inventé\"}\n```"]],
            ],
        ]));

        $provider = new DefaultProvider($httpClient, 'llm', 'a-model', self::BASE_URI, 'sk-test');
        $results = $provider->translate(['real.key' => 'Real'], 'en_US', 'fr_FR', 'messages');

        self::assertSame(['real.key'], array_keys($results));
        self::assertSame('Vrai', $results['real.key']->translation);
    }

    public function testThrowsOnHttpError(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['error' => 'nope'], ['http_code' => 401]));
        $provider = new DefaultProvider($httpClient, 'llm', 'a-model', self::BASE_URI, 'bad-key');

        $this->expectException(TranslationProviderException::class);
        $this->expectExceptionMessage('[llm] Request failed');

        $provider->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
    }

    public function testTheApiErrorBodyIsSurfaced(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(
            ['error' => ['message' => 'The model `gpt-5-nope` does not exist.', 'type' => 'invalid_request_error', 'param' => null, 'code' => 'model_not_found']],
            ['http_code' => 404],
        ));

        $provider = new DefaultProvider($httpClient, 'llm', 'a-model', self::BASE_URI, 'sk-test');

        try {
            $provider->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
            self::fail('A TranslationProviderException was expected.');
        } catch (TranslationProviderException $e) {
            self::assertStringContainsString('the API says: The model `gpt-5-nope` does not exist.', $e->getMessage());
        }
    }

    public function testMistralStyleErrorBodiesAreSurfacedToo(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(
            ['detail' => 'Invalid API Key'],
            ['http_code' => 401],
        ));

        $provider = new DefaultProvider($httpClient, 'mistral', 'mistral-small', 'https://api.mistral.ai/v1', 'bad-key');

        try {
            $provider->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
            self::fail('A TranslationProviderException was expected.');
        } catch (TranslationProviderException $e) {
            self::assertStringContainsString('the API says: Invalid API Key', $e->getMessage());
        }
    }

    public function testThrowsWhenTheReplyIsNotJson(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'choices' => [['message' => ['content' => 'Sorry, I cannot help with that.']]],
        ]));

        $provider = new DefaultProvider($httpClient, 'llm', 'a-model', self::BASE_URI, 'sk-test');

        $this->expectException(TranslationProviderException::class);
        $this->expectExceptionMessage('no JSON object found');

        $provider->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
    }

    public function testEmptyBatchShortCircuits(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            self::fail('No HTTP request expected for an empty batch.');
        });

        $provider = new DefaultProvider($httpClient, 'llm', 'a-model', self::BASE_URI, 'sk-test');

        self::assertSame([], $provider->translate([], 'en_US', 'fr_FR', 'messages'));
    }

    public function testEstimateRunMeasuresTheRealPromptsAndPricesTheModel(): void
    {
        $priceProvider = new ModelPriceProvider(
            new MockHttpClient(new JsonMockResponse([
                'gpt-4o-mini' => ['input_cost_per_token' => 1.5e-07, 'output_cost_per_token' => 6e-07],
            ])),
            new ArrayAdapter(),
            new NullLogger(),
        );

        $provider = new DefaultProvider(new MockHttpClient(), 'gpt', 'gpt-4o-mini', self::BASE_URI, 'sk-test', priceProvider: $priceProvider);

        $chunks = [['app.a' => 'Bonjour'], ['app.b' => 'Au revoir']];
        $estimate = $provider->estimateRun($chunks, 'fr_FR', 'en_US', 'messages');

        self::assertNotNull($estimate->inputTokens);
        self::assertNotNull($estimate->outputTokens);
        // Two API calls → the real (~660 chars) system prompt is counted twice.
        self::assertGreaterThan(300, $estimate->inputTokens);
        self::assertSame('USD', $estimate->currency);
        self::assertEqualsWithDelta(
            $estimate->inputTokens * 1.5e-07 + $estimate->outputTokens * 6e-07,
            (float) $estimate->cost,
            1e-12,
        );
    }

    /** Tokenizers split CJK or cyrillic far finer than latin: the reply costs more tokens. */
    public function testTheOutputEstimateGrowsForNonLatinTargets(): void
    {
        $provider = new DefaultProvider(new MockHttpClient(), 'llm', 'a-model', self::BASE_URI, 'sk-test');
        $chunks = [['app.a' => 'Welcome to the shop, enjoy your visit']];

        $latin = $provider->estimateRun($chunks, 'en', 'fr_FR', 'messages')->outputTokens;
        $japanese = $provider->estimateRun($chunks, 'en', 'ja_JP', 'messages')->outputTokens;
        $russian = $provider->estimateRun($chunks, 'en', 'ru', 'messages')->outputTokens;

        self::assertGreaterThan(2 * (int) $latin, (int) $japanese);
        self::assertGreaterThan((int) $latin, (int) $russian);
        self::assertFalse($provider->estimateRun($chunks, 'en', 'fr', 'messages')->lowerBound);
    }

    public function testEstimateRunWithoutPriceSourceOrModelPriceDegradesToTokensOnly(): void
    {
        // No price provider injected at all (e.g. a hand-built instance).
        $provider = new DefaultProvider(new MockHttpClient(), 'llm', 'a-model', self::BASE_URI, 'sk-test');
        $estimate = $provider->estimateRun([['app.a' => 'Bonjour']], 'fr_FR', 'en_US', 'messages');

        self::assertNotNull($estimate->inputTokens);
        self::assertNull($estimate->cost);

        // Price source available but the model is unknown to the list (self-hosted).
        $priceProvider = new ModelPriceProvider(new MockHttpClient(new JsonMockResponse([])), new ArrayAdapter(), new NullLogger());
        $provider = new DefaultProvider(new MockHttpClient(), 'ollama', 'my-local-model', 'http://localhost:11434/v1', priceProvider: $priceProvider);
        $estimate = $provider->estimateRun([['app.a' => 'Bonjour']], 'fr_FR', 'en_US', 'messages');

        self::assertNotNull($estimate->inputTokens);
        self::assertNull($estimate->cost);
    }
}
