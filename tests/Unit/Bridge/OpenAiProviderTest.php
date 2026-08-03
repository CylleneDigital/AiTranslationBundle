<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Bridge;

use CylleneDigital\AiTranslationBundle\Bridge\OpenAiProvider;
use CylleneDigital\AiTranslationBundle\Provider\TranslationResult;
use CylleneDigital\AiTranslationBundle\Tests\InspectsUntypedData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The OpenAI specialisation: the prefilled endpoint and model, the strict schema built
 * on each batch, the account-routing headers and the temperature the reasoning models
 * refuse. The shared wire format is covered by {@see DefaultProviderTest}.
 */
final class OpenAiProviderTest extends TestCase
{
    use InspectsUntypedData;

    public function testTheEndpointAndModelDefaultToOpenAi(): void
    {
        $capturedUrl = null;
        $capturedBody = null;
        $capturedAuth = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedBody, &$capturedAuth): JsonMockResponse {
            $capturedUrl = $url;
            $capturedBody = self::jsonBody($options);
            $capturedAuth = self::header($options, 'authorization');

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"app.hello": "Bonjour"}']]]]);
        });

        $provider = new OpenAiProvider($httpClient, 'gpt', 'sk-test');
        $results = $provider->translate(['app.hello' => 'Hello'], 'en_US', 'fr_FR', 'messages');

        self::assertSame('https://api.openai.com/v1/chat/completions', $capturedUrl);
        self::assertSame('Authorization: Bearer sk-test', $capturedAuth);
        self::assertIsArray($capturedBody);
        self::assertSame(OpenAiProvider::DEFAULT_MODEL, $capturedBody['model']);
        self::assertSame('Bonjour', $results['app.hello']->translation);
        self::assertSame('openai', $results['app.hello']->metadata['type']);
        self::assertSame(0.2, $capturedBody['temperature']);
        self::assertSame(8192, $capturedBody['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $capturedBody);
    }

    public function testTheBatchKeysBecomeAStrictJsonSchema(): void
    {
        $capturedBody = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): JsonMockResponse {
            $capturedBody = self::jsonBody($options);

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"app.hello": {"t": "Bonjour", "c": 0.9}, "app.bye": {"t": "Au revoir", "c": 0.8}}']]]]);
        });

        $provider = new OpenAiProvider($httpClient, 'gpt', 'sk-test');
        $provider->translate(['app.hello' => 'Hello', 'app.bye' => 'Goodbye'], 'en_US', 'fr_FR', 'messages');

        self::assertSame('json_schema', self::at($capturedBody, 'response_format', 'type'));
        self::assertTrue(self::at($capturedBody, 'response_format', 'json_schema', 'strict'));

        $schema = self::at($capturedBody, 'response_format', 'json_schema', 'schema');
        $properties = self::at($schema, 'properties');
        self::assertIsArray($properties);

        self::assertSame(['app.hello', 'app.bye'], array_keys($properties));
        self::assertSame(['app.hello', 'app.bye'], self::at($schema, 'required'));
        self::assertFalse(self::at($schema, 'additionalProperties'));
        self::assertSame(['$ref' => '#/$defs/translation'], $properties['app.hello']);
        self::assertSame(['t', 'c'], self::at($schema, '$defs', 'translation', 'required'));
    }

    /**
     * PHP stores "0" and "1" as int keys: without care the batch and the schema's
     * properties would go out as JSON lists and the required names as numbers.
     */
    public function testNumericKeysStayJsonObjectMembers(): void
    {
        $rawBody = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$rawBody): JsonMockResponse {
            self::assertIsString($options['body']);
            $rawBody = $options['body'];

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"0": {"t": "Zéro", "c": 0.9}, "1": {"t": "Un", "c": 0.9}}']]]]);
        });

        $provider = new OpenAiProvider($httpClient, 'gpt', 'sk-test');
        // @phpstan-ignore argument.type (PHP turns the numeric-string keys into ints: the very case under test)
        $results = $provider->translate(['0' => 'Zero', '1' => 'One'], 'en_US', 'fr_FR', 'messages');

        self::assertIsString($rawBody);
        self::assertStringContainsString('"properties":{"0":', $rawBody, 'An object, not a JSON list.');

        $body = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['0', '1'], self::at($body, 'response_format', 'json_schema', 'schema', 'required'));
        $userPrompt = self::at($body, 'messages', 1, 'content');
        self::assertIsString($userPrompt);
        self::assertStringContainsString('"0": "Zero"', $userPrompt);

        self::assertSame([0 => 'Zéro', 1 => 'Un'], array_map(static fn (TranslationResult $result): string => $result->translation, $results));
    }

    /** The reasoning tokens are billed as output but unknowable: the figure is a floor. */
    public function testTheEstimateOfAReasoningModelIsALowerBound(): void
    {
        $chunks = [['app.a' => 'Hello']];

        self::assertTrue((new OpenAiProvider(new MockHttpClient(), 'gpt', 'sk-test', 'gpt-5.1'))->estimateRun($chunks, 'en', 'fr', 'messages')->lowerBound);
        self::assertFalse((new OpenAiProvider(new MockHttpClient(), 'gpt', 'sk-test', 'gpt-4o-mini'))->estimateRun($chunks, 'en', 'fr', 'messages')->lowerBound);
    }

    public function testAnOversizedBatchFallsBackToPlainJsonMode(): void
    {
        $capturedBody = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): JsonMockResponse {
            $capturedBody = self::jsonBody($options);

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{}']]]]);
        });

        $texts = [];
        for ($i = 0; $i < 101; ++$i) {
            $texts['app.key_'.$i] = 'Text '.$i;
        }

        $provider = new OpenAiProvider($httpClient, 'gpt', 'sk-test');
        $provider->translate($texts, 'en_US', 'fr_FR', 'messages');

        self::assertIsArray($capturedBody);
        self::assertSame(['type' => 'json_object'], $capturedBody['response_format']);
    }

    public function testTheStrictSchemaCanBeTurnedOffForAGateway(): void
    {
        $capturedBody = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): JsonMockResponse {
            $capturedBody = self::jsonBody($options);

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"k": "v"}']]]]);
        });

        $provider = new OpenAiProvider($httpClient, 'gpt', 'sk-test', structuredOutput: false);
        $provider->translate(['k' => 'value'], 'en_US', 'fr_FR', 'messages');

        self::assertIsArray($capturedBody);
        self::assertArrayNotHasKey('response_format', $capturedBody);
    }

    public function testTheAccountRoutingHeadersAreSentWhenConfigured(): void
    {
        $capturedHeaders = [];

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedHeaders): JsonMockResponse {
            $capturedHeaders = self::at($options, 'normalized_headers');

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"k": "v"}']]]]);
        });

        $provider = new OpenAiProvider($httpClient, 'gpt', 'sk-test', organization: 'org-123', project: 'proj-456');
        $provider->translate(['k' => 'value'], 'en_US', 'fr_FR', 'messages');

        self::assertSame('OpenAI-Organization: org-123', self::at($capturedHeaders, 'openai-organization', 0));
        self::assertSame('OpenAI-Project: proj-456', self::at($capturedHeaders, 'openai-project', 0));

        // Nothing is sent when nothing is configured — a key bound to one org needs neither.
        $provider = new OpenAiProvider($httpClient, 'gpt', 'sk-test');
        $provider->translate(['k' => 'value'], 'en_US', 'fr_FR', 'messages');

        self::assertIsArray($capturedHeaders);
        self::assertArrayNotHasKey('openai-organization', $capturedHeaders);
        self::assertArrayNotHasKey('openai-project', $capturedHeaders);
    }

    public function testTemperatureIsOmittedForTheReasoningModels(): void
    {
        $bodies = [];

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): JsonMockResponse {
            $bodies[] = self::jsonBody($options);

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"k": "v"}']]]]);
        });

        foreach (['o3-mini', 'gpt-5.1', 'gpt-4o-mini'] as $model) {
            (new OpenAiProvider($httpClient, 'gpt', 'sk-test', $model))->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
        }

        self::assertArrayNotHasKey('temperature', $bodies[0]);
        self::assertArrayNotHasKey('temperature', $bodies[1]);
        self::assertSame(0.2, self::at($bodies, 2, 'temperature'));
        // The reasoning models think inside the same cap: they get more room.
        self::assertSame(32768, self::at($bodies, 0, 'max_completion_tokens'));
        self::assertSame(8192, self::at($bodies, 2, 'max_completion_tokens'));
    }

    public function testAnAzureOrProxyEndpointStillOverridesTheDefaults(): void
    {
        $capturedUrl = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$capturedUrl): JsonMockResponse {
            $capturedUrl = $url;

            return new JsonMockResponse(['choices' => [['message' => ['content' => '{"k": "v"}']]]]);
        });

        $provider = new OpenAiProvider($httpClient, 'gpt', 'sk-test', 'gpt-4o', 'https://gateway.internal/openai/v1');
        $provider->translate(['k' => 'value'], 'en_US', 'fr_FR', 'messages');

        self::assertSame('https://gateway.internal/openai/v1/chat/completions', $capturedUrl);
    }
}
