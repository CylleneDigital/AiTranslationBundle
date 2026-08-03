<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Tests\App\ScopedTestKernel;
use Symfony\Component\Console\Command\Command;

/**
 * The journey paths that only exist when the host declares scopes — booted on the
 * {@see ScopedTestKernel}, whose FixedScopeProvider replaces the null one the way a
 * real integration package would.
 */
final class ScopedJourneysTest extends HubTestCase
{
    protected static function getKernelClass(): string
    {
        return ScopedTestKernel::class;
    }

    public function testTheOverrideJourneyOffersTheScopePickerAndWritesAScopedOverride(): void
    {
        $tester = $this->runHub([
            'Browse and edit translations',
            'I know the key',
            'app.welcome', // key
            'fr',          // locale
            'FASHION_WEB', // scope
            'Bienvenue mode',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^ Scope \[/m', $display); // the scope was asked for
        self::assertSame('Bienvenue mode', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr', 'FASHION_WEB'));
        self::assertNull($this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr'));
    }

    /**
     * "Enter to keep the current one" in a scope keeps what that scope shows — the
     * inherited global override — not the file value, which used to be written over it.
     */
    public function testAPlainEnterInAScopeKeepsTheInheritedGlobalValue(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage'); // the fr file says "Tableau de bord"

        $tester = $this->runHub([
            'Browse and edit translations',
            'I know the key',
            'app.dashboard',
            'fr',
            'FASHION_WEB',
            '', // New value (enter to keep the current one)
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Same as the inherited value — nothing saved.', $tester->getDisplay());
        self::assertNull($this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr', 'FASHION_WEB'), 'The scope still inherits "Pilotage".');
        self::assertSame('Pilotage', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testTheGlobalSentinelWritesAGlobalOverride(): void
    {
        $tester = $this->runHub([
            'Browse and edit translations',
            'I know the key',
            'app.welcome',
            'fr',
            'No scope (global)',
            'Bienvenue',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('Bienvenue', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr'));
        self::assertNull($this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr', 'FASHION_WEB'));
    }

    public function testTheCascadeNamesTheScopeOfEachOverride(): void
    {
        $this->writer()->save('app.welcome', 'messages', 'fr', 'Bienvenue');
        $this->writer()->save('app.welcome', 'messages', 'fr', 'Bienvenue mode', scope: 'FASHION_WEB');

        $display = $this->runHub([
            'Browse and edit translations',
            'I know the key',
            'app.welcome',
            'fr',
            'No scope (global)',
            'Nothing',
        ])->getDisplay();

        // A bare "FASHION_WEB" would read as a random word next to "File value" — the
        // label has to name the dimension it belongs to, and say when there is none.
        self::assertStringContainsString('Override — no scope (global) (updated', $display);
        self::assertStringContainsString('Override — scope FASHION_WEB (updated', $display);
    }

    public function testTheGenerationJourneyOffersTheScopePicker(): void
    {
        $display = $this->runHub([
            'Generate AI translation suggestions',
            'en',          // source
            'fr',          // target
            '',            // catalogue: all
            'FASHION_WEB', // scope
            '',            // which keys? missing only
            'no',          // launch?
        ])->getDisplay();

        self::assertStringContainsString('Scope: FASHION_WEB', $display);
        self::assertStringContainsString('Nothing generated.', $display);
    }

    public function testTheOptionDrivenCommandsStillRefuseAnUnknownScope(): void
    {
        // An unknown scope silently rendered the global figures as the scope's — every
        // automation command carrying --scope must refuse it when the host declares scopes.
        foreach (['cyllene:ai-translation:coverage', 'cyllene:ai-translation:export-overrides'] as $command) {
            $tester = $this->commandTester($command);
            $tester->execute(['--scope' => 'dff']);

            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $command);
            self::assertStringContainsString('Unknown scope "dff"', $tester->getDisplay(), $command);
            self::assertStringContainsString('FASHION_WEB', $tester->getDisplay(), $command);
        }
    }
}
