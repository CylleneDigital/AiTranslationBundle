<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Bridge;

use CylleneDigital\AiTranslationBundle\Bridge\AnthropicProvider;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Tests\InspectsUntypedData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class AnthropicProviderTest extends TestCase
{
    use InspectsUntypedData;

    /** The usage is the call's: the suggestions of one call share an id, two calls never do. */
    public function testEachCallCarriesItsOwnBatchId(): void
    {
        $httpClient = new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse([
            'content' => [['type' => 'text', 'text' => '{"app.hello": "Bonjour", "app.bye": "Au revoir"}']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]));
        $provider = new AnthropicProvider($httpClient, 'claude', 'sk-ant-test');

        $first = $provider->translate(['app.hello' => 'Hello', 'app.bye' => 'Goodbye'], 'en_US', 'fr_FR', 'messages');
        $second = $provider->translate(['app.hello' => 'Hello', 'app.bye' => 'Goodbye'], 'en_US', 'fr_FR', 'messages');

        self::assertIsString($first['app.hello']->metadata['batch_id']);
        self::assertSame($first['app.hello']->metadata['batch_id'], $first['app.bye']->metadata['batch_id']);
        self::assertNotSame($first['app.hello']->metadata['batch_id'], $second['app.hello']->metadata['batch_id']);
    }

    public function testTranslatesABatchThroughTheMessagesApi(): void
    {
        $capturedUrl = null;
        $capturedBody = null;
        $capturedApiKey = null;
        $capturedVersion = null;
        $capturedWorkspace = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedBody, &$capturedApiKey, &$capturedVersion, &$capturedWorkspace): JsonMockResponse {
            $capturedUrl = $url;
            $capturedBody = self::jsonBody($options);
            $capturedApiKey = self::header($options, 'x-api-key');
            $capturedVersion = self::header($options, 'anthropic-version');
            $capturedWorkspace = self::header($options, 'anthropic-workspace-id');

            return new JsonMockResponse([
                'content' => [
                    ['type' => 'text', 'text' => '{"app.hello": "Bonjour"}'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]);
        });

        $provider = new AnthropicProvider($httpClient, 'claude', 'sk-ant-test');
        $results = $provider->translate(['app.hello' => 'Hello'], 'en_US', 'fr_FR', 'messages');

        self::assertSame('https://api.anthropic.com/v1/messages', $capturedUrl);
        self::assertSame('x-api-key: sk-ant-test', $capturedApiKey);
        self::assertSame('anthropic-version: 2023-06-01', $capturedVersion);
        self::assertNull($capturedWorkspace);
        self::assertIsArray($capturedBody);
        self::assertSame('claude-sonnet-5', $capturedBody['model']);
        self::assertArrayHasKey('max_tokens', $capturedBody);
        self::assertArrayHasKey('system', $capturedBody);
        self::assertArrayNotHasKey('temperature', $capturedBody);
        self::assertIsArray($capturedBody['messages']);
        self::assertCount(1, $capturedBody['messages']);
        self::assertSame('user', self::at($capturedBody, 'messages', 0, 'role'));

        self::assertSame('Bonjour', $results['app.hello']->translation);
        self::assertSame('anthropic', $results['app.hello']->metadata['type']);
        self::assertSame(['input_tokens' => 10, 'output_tokens' => 5], $results['app.hello']->metadata['batch_usage']);
        self::assertSame(1, $results['app.hello']->metadata['batch_size']);
        self::assertArrayNotHasKey('usage', $results['app.hello']->metadata);
    }

    /** Symfony strips Authorization on a cross-host redirect, not x-api-key: none is followed. */
    public function testRedirectsAreNotFollowed(): void
    {
        $capturedRedirects = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedRedirects): JsonMockResponse {
            $capturedRedirects = $options['max_redirects'] ?? null;

            return new JsonMockResponse([
                'content' => [['type' => 'text', 'text' => '{"app.hello": "Bonjour"}']],
                'stop_reason' => 'end_turn',
            ]);
        });

        (new AnthropicProvider($httpClient, 'claude', 'sk-ant-test'))->translate(['app.hello' => 'Hello'], 'en_US', 'fr_FR', 'messages');

        self::assertSame(0, $capturedRedirects);
    }

    public function testAConfiguredWorkspaceIdIsSentAsTheWorkspaceHeader(): void
    {
        // Identity-linked API keys are refused without the anthropic-workspace-id header.
        $capturedWorkspace = null;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedWorkspace): JsonMockResponse {
            $capturedWorkspace = self::header($options, 'anthropic-workspace-id');

            return new JsonMockResponse([
                'content' => [
                    ['type' => 'text', 'text' => '{"app.hello": "Bonjour"}'],
                ],
                'stop_reason' => 'end_turn',
            ]);
        });

        $provider = new AnthropicProvider($httpClient, 'claude', 'sk-ant-test', workspaceId: 'wrkspc_test123');
        $provider->translate(['app.hello' => 'Hello'], 'en_US', 'fr_FR', 'messages');

        self::assertSame('anthropic-workspace-id: wrkspc_test123', $capturedWorkspace);
    }

    public function testConcatenatesMultipleTextBlocks(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'content' => [
                ['type' => 'thinking', 'thinking' => ''],
                ['type' => 'text', 'text' => '{"a": '],
                ['type' => 'text', 'text' => '"Alpha"}'],
            ],
            'stop_reason' => 'end_turn',
        ]));

        $provider = new AnthropicProvider($httpClient, 'claude', 'sk-ant-test');
        $results = $provider->translate(['a' => 'A'], 'en_US', 'fr_FR', 'messages');

        self::assertSame('Alpha', $results['a']->translation);
    }

    public function testThrowsOnRefusal(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'content' => [],
            'stop_reason' => 'refusal',
        ]));

        $provider = new AnthropicProvider($httpClient, 'claude', 'sk-ant-test');

        $this->expectException(TranslationProviderException::class);
        $this->expectExceptionMessage('refused');

        $provider->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
    }

    public function testTheApiErrorBodyIsSurfaced(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(
            [
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens: Field required'],
                'request_id' => 'req_test',
            ],
            ['http_code' => 400],
        ));

        $provider = new AnthropicProvider($httpClient, 'claude', 'sk-ant-test');

        try {
            $provider->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
            self::fail('A TranslationProviderException was expected.');
        } catch (TranslationProviderException $e) {
            self::assertStringContainsString('Anthropic says: max_tokens: Field required', $e->getMessage());
        }
    }

    public function testThrowsOnHttpError(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['error' => 'overloaded'], ['http_code' => 529]));
        $provider = new AnthropicProvider($httpClient, 'claude', 'sk-ant-test');

        $this->expectException(TranslationProviderException::class);
        $this->expectExceptionMessage('[claude] Request failed');

        $provider->translate(['k' => 'v'], 'en_US', 'fr_FR', 'messages');
    }
}
