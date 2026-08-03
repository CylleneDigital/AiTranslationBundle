<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Command\Command;

/**
 * The coverage report through the hub — the CI gate itself (--min) stays on the
 * option-driven coverage command and keeps its own test.
 */
final class CoverageJourneyTest extends HubTestCase
{
    private const string MENU = 'Coverage report';

    public function testTheReportShowsTheFiguresForTheDefaultLocale(): void
    {
        $tester = $this->runHub([
            self::MENU,
            '',   // Default locale: the resolved default (en)
            'no', // List the missing keys after the table?
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Translation coverage (default locale: en)', $display);
        // A table row: the locale, its three counts and its percentage.
        self::assertMatchesRegularExpression('/^\s+fr\s+\d+\s+\d+\s+\d+\s+\d+\.\d %/m', $display);
    }

    public function testTheMissingKeysAreListedOnDemand(): void
    {
        $tester = $this->runHub([
            self::MENU,
            '',    // default locale (resolved)
            'yes', // list the missing keys
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Missing in fr', $display);
    }
}
