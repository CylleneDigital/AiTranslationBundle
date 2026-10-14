<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessage;
use CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessageHandler;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionGenerator;

/**
 * A run killed while the provider is called (a worker's OOM, a SIGKILL at a deployment)
 * never fails its message: the transport delivers it again, and a cron starts the console
 * again. The keys of the batch in flight are reserved before the call, so the next run
 * skips them instead of billing them twice.
 */
final class InterruptedGenerationTest extends DatabaseTestCase
{
    public function testTheKeysOfABatchAreReservedWhileTheProviderIsCalled(): void
    {
        $reserved = [];
        $this->answerProviderCallsInFrench(function (array $keys) use (&$reserved): void {
            foreach ($keys as $key) {
                $reserved[$key] = $this->entityManager()->getConnection()->fetchAssociative(
                    'SELECT suggested_value, generation_error, metadata FROM cyllene_translation_suggestion WHERE translation_key = ?',
                    [$key],
                );
            }
        });

        $this->handler()(new GenerateSuggestionsMessage('messages', 'fr', 'en'));

        self::assertSame(['app.welcome'], array_keys($reserved));
        $row = $reserved['app.welcome'];
        self::assertIsArray($row, 'The key is in the database before the provider answers.');
        self::assertNull($row['suggested_value']);
        self::assertSame(SuggestionGenerator::INTERRUPTED, $row['generation_error']);
        self::assertIsString($row['metadata']);
        self::assertStringContainsString(SuggestionGenerator::IN_FLIGHT, $row['metadata']);

        $stored = $this->suggestion('app.welcome');
        self::assertSame('FR Welcome to the shop', $stored->getSuggestedValue());
        self::assertFalse($stored->hasGenerationError());
        self::assertArrayNotHasKey(SuggestionGenerator::IN_FLIGHT, $stored->getMetadata() ?? []);
    }

    public function testARunStartedAgainAfterAKillDoesNotResendTheKeysOfTheBatchInFlight(): void
    {
        $this->leaveAnInterruptedRun(new \DateTimeImmutable('-2 hours'));
        $requests = $this->countProviderCalls();

        $this->handler()(new GenerateSuggestionsMessage('messages', 'fr', 'en'));

        self::assertSame(0, $requests->calls);
        self::assertSame(SuggestionGenerator::INTERRUPTED, $this->suggestion('app.welcome')->getGenerationError());
    }

    public function testARetryAskedForAfterTheKillResendsThem(): void
    {
        $this->leaveAnInterruptedRun(new \DateTimeImmutable('-2 hours'));
        $this->answerProviderCallsInFrench();

        $this->handler()(new GenerateSuggestionsMessage('messages', 'fr', 'en', retryErrors: true));

        self::assertSame('FR Welcome to the shop', $this->suggestion('app.welcome')->getSuggestedValue());
    }

    public function testARedeliveredRetryDoesNotResendTheKeysItsOwnRunReserved(): void
    {
        $this->leaveAnInterruptedRun(new \DateTimeImmutable('-2 hours'));
        $requests = $this->countProviderCalls();

        // Dispatched before its run reserved the keys: the message of that very run.
        $this->handler()(new GenerateSuggestionsMessage('messages', 'fr', 'en', retryErrors: true, dispatchedAt: new \DateTimeImmutable('-3 hours')));

        self::assertSame(0, $requests->calls);
        self::assertSame(SuggestionGenerator::INTERRUPTED, $this->suggestion('app.welcome')->getGenerationError());
    }

    public function testAKeyClosedWhileItsBatchIsWithTheProviderStaysClosed(): void
    {
        // Another process writes an override for the key meanwhile: its pending suggestion goes.
        $this->answerProviderCallsInFrench(function (): void {
            $this->entityManager()->getConnection()->executeStatement(
                'DELETE FROM cyllene_translation_suggestion WHERE translation_key = ?',
                ['app.dashboard'],
            );
        });

        $this->handler()(new GenerateSuggestionsMessage('messages', 'fr', 'en', onlyMissing: false));

        self::assertSame(0, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE translation_key = 'app.dashboard'"));
        self::assertSame('FR Welcome to the shop', $this->suggestion('app.welcome')->getSuggestedValue());
    }

    /** What a run killed during its provider call leaves: the reserved row, still in flight. */
    private function leaveAnInterruptedRun(\DateTimeImmutable $reservedAt): void
    {
        $row = TranslationSuggestion::failed('app.welcome', 'messages', 'fr', 'Welcome to the shop', 'en', 'gpt', SuggestionGenerator::INTERRUPTED);
        $row->setMetadata(['only_missing' => true, SuggestionGenerator::IN_FLIGHT => $reservedAt->format('Y-m-d\TH:i:s.uP')]);
        $this->entityManager()->persist($row);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    private function countProviderCalls(): \stdClass
    {
        $requests = new \stdClass();
        $requests->calls = 0;
        $this->answerProviderCallsInFrench(static function () use ($requests): void {
            ++$requests->calls;
        });

        return $requests;
    }

    private function suggestion(string $key): TranslationSuggestion
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(TranslationSuggestion::class)->findOneBy(['key' => $key])
            ?? self::fail(\sprintf('No suggestion for "%s".', $key));
    }

    private function handler(): GenerateSuggestionsMessageHandler
    {
        $handler = self::getContainer()->get(GenerateSuggestionsMessageHandler::class);
        self::assertInstanceOf(GenerateSuggestionsMessageHandler::class, $handler);

        return $handler;
    }
}
