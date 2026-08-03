<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessage;
use CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessageHandler;
use CylleneDigital\AiTranslationBundle\Override\InvalidOverrideException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * The Messenger side of the generation: a failing run must never be retried (the
 * provider has already been billed) but must not vanish either — it belongs in the
 * failure transport, not only in the log.
 */
final class GenerateSuggestionsHandlerTest extends DatabaseTestCase
{
    public function testAFailedRunIsReportedAsUnrecoverableRatherThanSwallowed(): void
    {
        self::bootKernel();

        /** @var GenerateSuggestionsMessageHandler $handler */
        $handler = self::getContainer()->get(GenerateSuggestionsMessageHandler::class);

        try {
            // An unknown provider name: the run cannot even start.
            $handler(new GenerateSuggestionsMessage('messages', 'fr', 'en', 'no-such-provider'));
            self::fail('The handler was expected to report the failure.');
        } catch (UnrecoverableExceptionInterface $e) {
            self::assertStringContainsString('messages', $e->getMessage());
            self::assertStringContainsString('no-such-provider', (string) $e->getPrevious()?->getMessage());
        }
    }

    /**
     * What the doctrine_transaction middleware does around the handler: a rollback would
     * discard the suggestions already paid for, so the run must not start at all.
     */
    public function testARunInsideATransactionIsRefusedBeforeCallingTheProvider(): void
    {
        self::bootKernel();

        $requests = 0;
        self::getContainer()->set('http_client', new MockHttpClient(static function () use (&$requests): JsonMockResponse {
            ++$requests;

            return new JsonMockResponse([]);
        }));

        /** @var GenerateSuggestionsMessageHandler $handler */
        $handler = self::getContainer()->get(GenerateSuggestionsMessageHandler::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $handler(new GenerateSuggestionsMessage('messages', 'fr', 'en'));
            self::fail('The handler was expected to refuse the run.');
        } catch (UnrecoverableExceptionInterface $e) {
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
            self::assertStringContainsString('doctrine_transaction', $e->getPrevious()->getMessage());
        } finally {
            $connection->rollBack();
        }

        self::assertSame(0, $requests);
    }

    public function testAScopeLongerThanTheStoredColumnIsRefusedBeforeCallingTheProvider(): void
    {
        self::bootKernel();

        $requests = 0;
        self::getContainer()->set('http_client', new MockHttpClient(static function () use (&$requests): JsonMockResponse {
            ++$requests;

            return new JsonMockResponse([]);
        }));

        /** @var GenerateSuggestionsMessageHandler $handler */
        $handler = self::getContainer()->get(GenerateSuggestionsMessageHandler::class);

        try {
            $handler(new GenerateSuggestionsMessage('messages', 'fr', 'en', scope: str_repeat('s', 65)));
            self::fail('The handler was expected to refuse the run.');
        } catch (UnrecoverableExceptionInterface $e) {
            self::assertStringContainsString('exceeds 64 characters', (string) $e->getPrevious()?->getMessage());
            // One of the exceptions a host is told to expect, not a bare SPL one.
            self::assertInstanceOf(ExceptionInterface::class, $e->getPrevious());
        }

        self::assertSame(0, $requests);
    }

    /** @return iterable<string, array{GenerateSuggestionsMessage, string}> */
    public static function unstorableRuns(): iterable
    {
        // No "fr.UTF8" file exists: every key would be sent — then stored, and refused by
        // every approval (a malformed locale is never an override).
        yield 'malformed target locale' => [new GenerateSuggestionsMessage('messages', 'fr.UTF8', 'en'), '"fr.UTF8" is not a valid locale code'];
        // Too long for the 32-character columns: the flush would fail after the bill.
        // "fr" is available: "FR" would be stored, and paid for, but never served to its visitors.
        yield 'case variant of an available locale' => [new GenerateSuggestionsMessage('messages', 'FR', 'en'), 'write "fr"'];
        yield 'target locale too long' => [new GenerateSuggestionsMessage('messages', 'fr_'.str_repeat('X', 40), 'en'), 'is not a valid locale code'];
        // Starts with "en_": the same-language fallback finds the "en" texts, so the
        // provider WOULD be called — and the 32-character source_locale columns overflow.
        yield 'source locale too long' => [new GenerateSuggestionsMessage('messages', 'fr', 'en_'.str_repeat('X', 40)), 'is not a valid source locale code'];
        yield 'catalogue too long' => [new GenerateSuggestionsMessage(str_repeat('c', 256), 'fr', 'en'), 'catalogue identifier must hold 1 to 255 characters'];
    }

    /**
     * A target the tables cannot hold, or that no approval could ever apply, is refused
     * before the provider is called: paid translations nobody can store or use.
     */
    #[DataProvider('unstorableRuns')]
    public function testAnUnstorableRunIsRefusedBeforeCallingTheProvider(GenerateSuggestionsMessage $message, string $reason): void
    {
        self::bootKernel();

        $requests = 0;
        self::getContainer()->set('http_client', new MockHttpClient(static function () use (&$requests): JsonMockResponse {
            ++$requests;

            return new JsonMockResponse([]);
        }));

        /** @var GenerateSuggestionsMessageHandler $handler */
        $handler = self::getContainer()->get(GenerateSuggestionsMessageHandler::class);

        try {
            $handler($message);
            self::fail('The handler was expected to refuse the run.');
        } catch (UnrecoverableExceptionInterface $e) {
            self::assertInstanceOf(InvalidOverrideException::class, $e->getPrevious());
            self::assertStringContainsString($reason, $e->getPrevious()->getMessage());
        }

        self::assertSame(0, $requests, 'The provider was never called.');
        // The run never started: no journal entry, nothing stored.
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_generation_log'));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion'));
    }

    public function testASuccessfulRunStoresTheSuggestions(): void
    {
        $this->answerProviderCallsInFrench();

        /** @var GenerateSuggestionsMessageHandler $handler */
        $handler = self::getContainer()->get(GenerateSuggestionsMessageHandler::class);
        $handler(new GenerateSuggestionsMessage('messages', 'fr', 'en'));

        self::assertSame('FR Welcome to the shop', $this->entityManager()->getConnection()->fetchOne('SELECT suggested_value FROM cyllene_translation_suggestion'));
    }
}
