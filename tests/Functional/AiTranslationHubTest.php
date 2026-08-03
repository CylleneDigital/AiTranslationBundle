<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Command\Command;

/**
 * The hub itself: the TTY requirement, the menu content, quitting, and chaining two
 * journeys in one session (the menu comes back after each one).
 */
final class AiTranslationHubTest extends HubTestCase
{
    public function testWithoutATtyThePointerGoesToTheOptionDrivenCommands(): void
    {
        $tester = $this->runHubWithoutTty();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('This console is interactive', $tester->getDisplay());
        self::assertStringContainsString('cyllene:ai-translation:coverage', $tester->getDisplay());
        self::assertStringContainsString('cyllene:ai-translation:cleanup-suggestions', $tester->getDisplay());
    }

    public function testTheMenuListsEveryJourneyAndQuitsByDefault(): void
    {
        $tester = $this->runHub([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        foreach ([
            'Generate AI translation suggestions',
            'Review the pending suggestions',
            'Browse and edit translations',
            'Export the overrides to a file',
            'Import an overrides file',
            'Coverage report',
            'Inspect the default locale keys and drift',
            'Purge the orphan overrides',
            'Clear the translation caches',
            'Quit',
        ] as $entry) {
            self::assertStringContainsString($entry, $display);
        }
    }

    public function testTheMenuComesBackAfterAJourney(): void
    {
        $tester = $this->runHub([
            'Clear the translation caches',
            '', // (all locales)
            'Clear the translation caches',
            'fr',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Translation cache cleared for all locales (en, fr).', $display);
        self::assertStringContainsString('Translation cache cleared for locale "fr".', $display);
    }
}
