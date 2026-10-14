<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessage;
use CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessageHandler;
use CylleneDigital\AiTranslationBundle\Override\InvalidOverrideException;
use CylleneDigital\AiTranslationBundle\Tests\App\ScopedTestKernel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * A message queued for a scope the host has deleted since (a sales channel): the run is
 * refused before the provider is called, so nothing is billed for a scope nobody reviews.
 */
final class UndeclaredScopeGenerationTest extends DatabaseTestCase
{
    protected static function getKernelClass(): string
    {
        return ScopedTestKernel::class;
    }

    public function testARunForAnUndeclaredScopeIsRefusedBeforeCallingTheProvider(): void
    {
        $requests = 0;
        self::getContainer()->set('http_client', new MockHttpClient(static function () use (&$requests): JsonMockResponse {
            ++$requests;

            return new JsonMockResponse([]);
        }));

        try {
            $this->handler()(new GenerateSuggestionsMessage('messages', 'fr', 'en', scope: 'GONE'));
            self::fail('The run was expected to be refused.');
        } catch (UnrecoverableExceptionInterface $e) {
            self::assertInstanceOf(InvalidOverrideException::class, $e->getPrevious());
            self::assertStringContainsString('GONE', $e->getPrevious()->getMessage());
        }

        self::assertSame(0, $requests);
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion'));
    }

    public function testARunForADeclaredScopeGoesThrough(): void
    {
        $this->answerProviderCallsInFrench();

        $this->handler()(new GenerateSuggestionsMessage('messages', 'fr', 'en', scope: 'B2B'));

        self::assertSame(1, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE scope = 'B2B'"));
    }

    private function handler(): GenerateSuggestionsMessageHandler
    {
        $handler = self::getContainer()->get(GenerateSuggestionsMessageHandler::class);
        self::assertInstanceOf(GenerateSuggestionsMessageHandler::class, $handler);

        return $handler;
    }
}
