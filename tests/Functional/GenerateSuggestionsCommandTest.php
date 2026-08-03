<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Bridge\OpenAiProvider;
use CylleneDigital\AiTranslationBundle\Entity\GenerationLog;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Lock\LockFactory;

/**
 * The scripted generation: the option checks refuse before anything is estimated, the
 * estimate and the cost ceiling stop before anything is sent, and a non-interactive
 * run goes all the way to the provider.
 */
final class GenerateSuggestionsCommandTest extends DatabaseTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRefusedBeforeAnyEstimate(array $input, string $message): void
    {
        $tester = $this->generate($input);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertStringNotContainsString('suggestion(s) to generate', $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'no target' => [[], 'At least one --target'];
        yield 'unknown target' => [['--target' => ['de']], 'Locale "de" is not available'];
        yield 'source as target' => [['--target' => ['en']], 'cannot be a target too'];
        yield 'unknown catalogue' => [['--target' => ['fr'], '--catalogue' => ['nope']], 'Unknown catalogue(s): nope'];
        yield 'unknown provider' => [['--target' => ['fr'], '--provider' => 'nope'], 'Unknown AI translation provider "nope"'];
        yield 'conflicting modes' => [['--target' => ['fr'], '--all' => true, '--retry-errors' => true], 'mutually exclusive'];
        // A negative ceiling refuses every run, whatever the estimate: a typo, not a limit.
        yield 'negative cost ceiling' => [['--target' => ['fr'], '--max-cost' => '-1'], '--max-cost cannot be negative'];
    }

    public function testADryRunEstimatesAndSendsNothing(): void
    {
        $tester = $this->generate(['--target' => ['fr'], '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('suggestion(s) to generate', $tester->getDisplay());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        // The suite never reaches the price list: the run says it is not priced.
        self::assertStringContainsString('cost not estimated: no list price for this', $tester->getDisplay());
        self::assertSame(0, $this->countSuggestions());
    }

    public function testAnUnpricedRunIsRefusedUnderACostCeiling(): void
    {
        // The price list is unreachable in the suite: a ceiling that cannot be checked
        // must not let the run through.
        $tester = $this->generate(['--target' => ['fr'], '--max-cost' => '1']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('cannot be priced', $tester->getDisplay());
        self::assertSame(0, $this->countSuggestions());
    }

    /** A priced estimate above the ceiling stops the run before any provider call. */
    public function testAPricedRunAboveTheCostCeilingIsRefused(): void
    {
        $requests = [];
        self::getContainer()->set('http_client', new MockHttpClient(static function (string $method, string $url) use (&$requests): JsonMockResponse {
            $requests[] = $method;

            // Only the LiteLLM price list may be read: a POST would be a billed call.
            return new JsonMockResponse([OpenAiProvider::DEFAULT_MODEL => ['input_cost_per_token' => 1.0, 'output_cost_per_token' => 1.0]]);
        }));

        $tester = $this->generate(['--target' => ['fr'], '--max-cost' => '0.01']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('exceeds --max-cost', $tester->getDisplay());
        self::assertSame(['GET'], array_values(array_unique($requests)));
        self::assertSame(0, $this->countSuggestions());
    }

    public function testANonInteractiveRunGeneratesWithoutAskingForConfirmation(): void
    {
        $this->answerProviderCallsInFrench();

        $tester = $this->generate(['--target' => ['fr'], '--catalogue' => ['messages']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('Send these', $tester->getDisplay());
        self::assertSame(1, $this->countSuggestions());
        self::assertSame('FR Welcome to the shop', $this->entityManager()->getConnection()->fetchOne('SELECT suggested_value FROM cyllene_translation_suggestion'));

        // A console run walks every catalogue: what it wrote must not stay managed and
        // weigh on each later flush.
        $identityMap = $this->entityManager()->getUnitOfWork()->getIdentityMap();
        self::assertSame([], $identityMap[TranslationSuggestion::class] ?? []);
        self::assertSame([], $identityMap[GenerationLog::class] ?? []);
    }

    /** Interactive, a plain enter on the launch confirmation sends nothing: "yes" has to be typed. */
    public function testAPlainEnterOnTheLaunchConfirmationSendsNothing(): void
    {
        $tester = $this->commandTester('cyllene:ai-translation:generate');
        $tester->setInputs(['']);
        $tester->execute(['--target' => ['fr'], '--catalogue' => ['messages']], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('(yes/no) [no]', $tester->getDisplay());
        self::assertSame(0, $this->countSuggestions());
    }

    /**
     * One catalogue locked by another run, the other with nothing to translate: the
     * closing line must not say "every key already has a value" — nobody looked at the
     * locked one.
     */
    public function testACatalogueSkippedByTheLockIsNotReportedAsTranslated(): void
    {
        $this->writer()->save('product.out_of_stock', 'shop/Product/messages', 'fr', 'Épuisé'); // nothing left to translate there

        /** @var LockFactory $locks */
        $locks = self::getContainer()->get('lock.factory');
        $held = $locks->createLock('cyllene_ai_translation_'.hash('xxh128', 'messages|fr|'), autoRelease: false);
        self::assertTrue($held->acquire(), 'The test holds the lock of "messages", as a concurrent run would.');

        try {
            $tester = $this->generate(['--target' => ['fr'], '--catalogue' => ['messages', 'shop/Product/messages']]);
        } finally {
            $held->release();
        }

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringNotContainsString('Nothing to generate — every key already has a value', $display);
        self::assertStringContainsString('Nothing to generate in the 1 catalogue(s) that ran', $display);
        self::assertStringContainsString('1 catalogue(s) skipped', $display);
    }

    public function testAProviderFailureExitsWithAnErrorAndStoresErroredRows(): void
    {
        // The suite's HTTP client has no queued response: every provider call fails.
        $tester = $this->generate(['--target' => ['fr'], '--catalogue' => ['messages']]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--retry-errors', $tester->getDisplay());
        self::assertStringNotContainsString('Nothing to generate', $tester->getDisplay());
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE generation_error IS NOT NULL'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function generate(array $input): CommandTester
    {
        $tester = $this->commandTester('cyllene:ai-translation:generate');
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function countSuggestions(): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion');
    }
}
