<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Command\Command;

/**
 * The guided generation through the hub, up to its point of no return: the cost
 * estimate is a step of the flow, and declining the launch confirmation must leave
 * the providers uncalled (the fixture provider is keyless — an actual call would
 * fail loudly). The generation itself is covered by the suggestion workflow test.
 */
final class GenerateSuggestionsJourneyTest extends HubTestCase
{
    private const string MENU = 'Generate AI translation suggestions';

    public function testTheEstimateIsShownAndDecliningLaunchesNothing(): void
    {
        $tester = $this->runHub([
            self::MENU,
            'en', // Source locale
            'fr', // Target locale
            '',   // Catalogue: (all catalogues)
            '',   // Which keys? the missing ones (default)
            'no', // Send these N key(s) …?
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Provider: gpt', $display); // single provider: announced, never asked
        self::assertStringContainsString('suggestion(s) to generate', $display);
        self::assertStringContainsString('Nothing generated.', $display);
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion'));
    }

    /** The one paid action of the bundle must never be what a plain enter, or no answer at all, triggers. */
    public function testAPlainEnterOnTheLaunchConfirmationSendsNothing(): void
    {
        $tester = $this->runHub([
            self::MENU,
            'en',
            'fr',
            '',
            '',
            '', // Send these N key(s) …? — no answer
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/now\? \(yes\/no\) \[no\]/', $display);
        self::assertStringContainsString('Nothing generated.', $display);
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion'));
    }

    public function testASingleProviderAsksNoProviderQuestion(): void
    {
        $display = $this->runHub([
            self::MENU,
            'en',
            'fr',
            '',
            '',
            'no',
        ])->getDisplay();

        self::assertStringNotContainsString('Provider [', $display);
    }

    public function testTheSourceLocaleIsExcludedFromTheTargetChoices(): void
    {
        $display = $this->runHub([
            self::MENU,
            'en',
            'fr',
            '',
            '',
            'no',
        ])->getDisplay();

        // The target picker must not offer "en" again.
        preg_match('/Target locale.*?(?=Catalogue)/s', $display, $match);
        $targetQuestion = $match[0] ?? self::fail('The target locale question was not asked.');

        self::assertMatchesRegularExpression('/\[\d+\] fr\b/', $targetQuestion);
        self::assertDoesNotMatchRegularExpression('/\[\d+\] en\b/', $targetQuestion);
    }
}
