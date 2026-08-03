<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\GenerationLog;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The cron-friendly cleanup command against a real database: reviewed suggestions
 * older than the threshold go away, pending ones never do.
 */
final class CleanupSuggestionsCommandTest extends DatabaseTestCase
{
    public function testDeletesOldReviewedSuggestionsButNeverPendingOnes(): void
    {
        $entityManager = $this->createSuggestions();

        $tester = $this->executeCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 reviewed suggestion(s)', $tester->getDisplay());
        self::assertSame(1, $this->countSuggestions($entityManager));
    }

    public function testDryRunCountsWithoutDeleting(): void
    {
        $entityManager = $this->createSuggestions();

        $entityManager->persist(GenerationLog::success('messages', 'fr', 'en', 'stub', 1));
        $entityManager->flush();
        $entityManager->getConnection()->executeStatement("UPDATE cyllene_translation_generation_log SET created_at = '2020-01-01 00:00:00'");

        $tester = $this->executeCommand(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 reviewed suggestion(s) older than', $tester->getDisplay());
        self::assertStringContainsString('would be deleted', $tester->getDisplay());
        // What the real run would purge too.
        self::assertStringContainsString('1 generation-log entry(ies) would be purged', $tester->getDisplay());
        self::assertSame(2, $this->countSuggestions($entityManager));
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_generation_log'));
    }

    public function testRefusesAMalformedThreshold(): void
    {
        $tester = $this->executeCommand(['--before' => 'whenever']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Invalid --before value "whenever"', $tester->getDisplay());
    }

    /**
     * A unit forgotten or an "ago" suffix would put the threshold at now or in the
     * future — a purge of the whole history from a single crontab typo.
     */
    #[DataProvider('thresholdsNotInThePast')]
    public function testRefusesAThresholdThatIsNotInThePast(string $before): void
    {
        $entityManager = $this->createSuggestions();

        $tester = $this->executeCommand(['--before' => $before]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('expected an interval in the past', $tester->getDisplay());
        self::assertSame(2, $this->countSuggestions($entityManager));
    }

    /** @return iterable<string, array{string}> */
    public static function thresholdsNotInThePast(): iterable
    {
        yield 'unitless number' => ['30'];
        yield 'zero' => ['0'];
        yield 'zero days' => ['0 days'];
        yield '"ago" suffix' => ['1 week ago'];
        // Read by modify() as the year -2026: in the past, so it used to pass and delete nothing.
        yield 'ISO date' => ['2026-09-01'];
        yield 'relative date' => ['yesterday'];
    }

    /** One approved suggestion reviewed long ago, one pending — the cleanup fodder. */
    private function createSuggestions(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $approved = new TranslationSuggestion('app.welcome', 'messages', 'fr', 'Bienvenue', 'Welcome to the shop', 'en', 'stub', 0.95);
        $approved->approve('tester');
        $entityManager->persist($approved);

        $entityManager->persist(new TranslationSuggestion('product.out_of_stock', 'shop/Product/messages', 'fr', 'Épuisé', 'Out of stock', 'en', 'stub', 0.9));
        $entityManager->flush();

        // Age the review far beyond the default 30-day threshold.
        $entityManager->getConnection()->executeStatement("UPDATE cyllene_translation_suggestion SET reviewed_at = '2020-01-01 00:00:00' WHERE reviewed_at IS NOT NULL");
        $entityManager->clear();

        return $entityManager;
    }

    private function countSuggestions(EntityManagerInterface $entityManager): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion');
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function executeCommand(array $input): CommandTester
    {
        $tester = $this->commandTester('cyllene:ai-translation:cleanup-suggestions');
        $tester->execute($input);

        return $tester;
    }
}
