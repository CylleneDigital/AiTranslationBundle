<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Bridge\AnthropicProvider;
use CylleneDigital\AiTranslationBundle\Bridge\DeeplProvider;
use CylleneDigital\AiTranslationBundle\Bridge\DefaultProvider;
use CylleneDigital\AiTranslationBundle\Bridge\OpenAiProvider;
use CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessage;
use CylleneDigital\AiTranslationBundle\Override\TranslationManager;
use CylleneDigital\AiTranslationBundle\Override\TranslationManagerInterface;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use CylleneDigital\AiTranslationBundle\Suggestion\TranslationAiService;
use CylleneDigital\AiTranslationBundle\Suggestion\TranslationAiServiceInterface;
use CylleneDigital\AiTranslationBundle\Tests\App\FullConfigTestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What the bundle wires into a host using every configuration key: the container
 * compiles, each provider type resolves to its bridge, generation messages leave on
 * the bundle's transport and are never retried, and the HTTP client only retries the
 * statuses that guarantee the provider did not bill the call.
 */
final class BundleWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return FullConfigTestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testEveryProviderTypeResolvesToItsBridge(): void
    {
        $registry = $this->registry();

        self::assertInstanceOf(OpenAiProvider::class, $registry->get('gpt'));
        self::assertInstanceOf(DefaultProvider::class, $registry->get('local'));
        self::assertInstanceOf(AnthropicProvider::class, $registry->get('claude'));
        self::assertInstanceOf(DeeplProvider::class, $registry->get('deepl'));
        self::assertSame('gpt', $registry->get()->getName());
    }

    public function testTheFacadeIsAutowirableByTheHost(): void
    {
        self::assertInstanceOf(TranslationManager::class, self::getContainer()->get(TranslationManager::class));
    }

    /** The interfaces are what a host type-hints, and what an integration decorates. */
    public function testTheFacadeInterfacesResolveToTheBundleImplementations(): void
    {
        self::assertInstanceOf(TranslationManager::class, self::getContainer()->get(TranslationManagerInterface::class));
        self::assertInstanceOf(TranslationAiService::class, self::getContainer()->get(TranslationAiServiceInterface::class));
    }

    /**
     * A retried generation would bill the provider a second time for the same keys:
     * the message goes to the bundle's own transport, whose strategy never retries.
     */
    public function testGenerationMessagesLeaveOnTheBundleTransportAndAreNeverRetried(): void
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new GenerateSuggestionsMessage('messages', 'fr', 'en'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.cyllene_ai_translation');
        self::assertCount(1, $transport->getSent());

        /** @var RetryStrategyInterface $retry */
        $retry = self::getContainer()->get('messenger.retry.multiplier_retry_strategy.cyllene_ai_translation');
        self::assertFalse($retry->isRetryable(new Envelope(new GenerateSuggestionsMessage('messages', 'fr', 'en'))));
    }

    /** After a 500 the provider may have finished — and billed — the call: no retry. */
    public function testAServerErrorIsNotRetried(): void
    {
        $calls = 0;
        $this->mockHttp(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{}', ['http_code' => 500]);
        });

        try {
            $this->registry()->get('gpt')->translate(['app.hello' => 'Hello'], 'en', 'fr', 'messages');
            self::fail('A 500 must fail the call.');
        } catch (TranslationProviderException) {
        }

        self::assertSame(1, $calls);
    }

    /**
     * A 429, a 503 or Anthropic's 529 ("overloaded") say the call was not processed:
     * retrying it bills nothing twice.
     */
    #[DataProvider('notProcessedStatuses')]
    public function testACallTheProviderDidNotProcessIsRetried(int $status): void
    {
        $calls = 0;
        $this->mockHttp(static function () use (&$calls, $status): MockResponse {
            return 1 === ++$calls
                ? new MockResponse('{}', ['http_code' => $status])
                : new JsonMockResponse(['choices' => [['message' => ['content' => '{"app.hello": "Bonjour"}']]]]);
        });

        $results = $this->registry()->get('gpt')->translate(['app.hello' => 'Hello'], 'en', 'fr', 'messages');

        self::assertSame(2, $calls);
        self::assertSame('Bonjour', $results['app.hello']->translation);
    }

    /** @return iterable<string, array{int}> */
    public static function notProcessedStatuses(): iterable
    {
        yield 'rate limited' => [429];
        yield 'unavailable' => [503];
        yield 'anthropic overloaded' => [529];
    }

    private function registry(): TranslationProviderRegistry
    {
        /** @var TranslationProviderRegistry $registry */
        $registry = self::getContainer()->get(TranslationProviderRegistry::class);

        return $registry;
    }

    /** Swapped before any bridge is instantiated: they receive it through the retrying client. */
    private function mockHttp(callable $factory): void
    {
        self::getContainer()->set('http_client', new MockHttpClient($factory));
    }
}
