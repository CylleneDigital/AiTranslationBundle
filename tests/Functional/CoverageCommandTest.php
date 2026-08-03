<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The coverage command's --list-missing option against the fixture translations/
 * files (default locale: en): the missing keys are named per locale and catalogue, and the
 * ones already covered by a pending suggestion are flagged.
 */
final class CoverageCommandTest extends DatabaseTestCase
{
    public function testListMissingNamesTheMissingKeysPerCatalogue(): void
    {
        $tester = $this->executeCommand(['--list-missing' => true]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Missing in fr (3)', $display);
        self::assertStringContainsString('app.welcome', $display);
        self::assertStringContainsString('product.out_of_stock', $display);
        self::assertStringContainsString('dashboard.saved', $display);
    }

    /** The CI gate the README advertises: below the threshold, the command fails and names the locale. */
    public function testTheMinimumGateFailsBelowTheThresholdAndPassesAtIt(): void
    {
        $failing = $this->executeCommand(['--min' => '100']);

        self::assertSame(Command::FAILURE, $failing->getStatusCode());
        self::assertMatchesRegularExpression('/Coverage below 100\.0 % for: fr \(\d+\.\d %\)/', $failing->getDisplay());

        $passing = $this->executeCommand(['--min' => '0']);

        self::assertSame(Command::SUCCESS, $passing->getStatusCode());
        self::assertStringContainsString('Every locale is at or above 0.0 % coverage.', $passing->getDisplay());
    }

    /** No locale can reach 150 %: such a gate would fail every build without saying why. */
    public function testAMinimumOutsideZeroToAHundredIsRefused(): void
    {
        foreach (['150', '-5'] as $min) {
            $tester = $this->executeCommand(['--min' => $min]);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('--min is a percentage: between 0 and 100', $tester->getDisplay());
            self::assertStringNotContainsString('Locale', $tester->getDisplay(), 'Refused before any figure is computed.');
        }
    }

    public function testTheLocaleOptionNarrowsTheReportAndRefusesAnUnknownLocale(): void
    {
        $tester = $this->executeCommand(['--locale' => 'fr', '--min' => '100']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^\s+fr\s+\d+/m', $tester->getDisplay());

        $unknown = $this->executeCommand(['--locale' => 'de']);

        self::assertSame(Command::FAILURE, $unknown->getStatusCode());
        self::assertStringContainsString('Locale "de" is not available', $unknown->getDisplay());
    }

    /** A typo'd reference locale has no key to miss: every locale would pass at 100 %. */
    public function testAnUnavailableDefaultLocaleIsRefused(): void
    {
        $tester = $this->executeCommand(['--default-locale' => 'fr-FR', '--min' => '100']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // "fr-FR" is read as "fr_FR", the spelling of the scanned files — not available here either.
        self::assertStringContainsString('The default locale "fr_FR" is not available', $tester->getDisplay());
    }

    /**
     * An empty value is missing for the report and for the generation alike: generating
     * and approving the missing keys brings the locale to 100 %, the empty one included.
     * Before, the generation counted "" as present, and the gate could never pass.
     */
    public function testAnEmptyValueIsGeneratedLikeAMissingOneUntilTheGatePasses(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', '');

        self::assertStringContainsString('app.dashboard', $this->executeCommand(['--locale' => 'fr', '--list-missing' => true])->getDisplay());

        $this->answerProviderCallsInFrench();
        $generate = $this->commandTester('cyllene:ai-translation:generate');
        $generate->execute(['--target' => ['fr']], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $generate->getStatusCode(), $generate->getDisplay());

        self::assertSame('FR Dashboard', $this->entityManager()->getConnection()->fetchOne("SELECT suggested_value FROM cyllene_translation_suggestion WHERE translation_key = 'app.dashboard'"));

        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);
        /** @var TranslationSuggestionRepository $suggestions */
        $suggestions = self::getContainer()->get(TranslationSuggestionRepository::class);
        self::assertSame([], $reviewer->approveMany($suggestions->findPending('fr')));

        $gate = $this->executeCommand(['--locale' => 'fr', '--min' => '100']);
        self::assertSame(Command::SUCCESS, $gate->getStatusCode(), $gate->getDisplay());
    }

    public function testAKeyCoveredByAPendingSuggestionIsFlagged(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TranslationSuggestion(
            'app.welcome',
            'messages',
            'fr',
            'Bienvenue dans la boutique',
            'Welcome to the shop',
            'en',
            'stub',
            0.95,
        ));
        $entityManager->flush();

        $display = $this->executeCommand(['--list-missing' => true])->getDisplay();

        self::assertStringContainsString('app.welcome (pending review)', $display);
        self::assertStringNotContainsString('product.out_of_stock (pending review)', $display);
    }

    public function testWithoutTheOptionOnlyTheCountsAreShown(): void
    {
        $tester = $this->executeCommand([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^\s+fr\s+\d+\s+\d+/m', $display); // the counts are there…
        self::assertStringNotContainsString('app.welcome', $display); // …the key names are not
        self::assertStringNotContainsString('Missing in fr', $display);
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function executeCommand(array $input): CommandTester
    {
        $tester = $this->commandTester('cyllene:ai-translation:coverage');
        $tester->execute($input);

        return $tester;
    }
}
