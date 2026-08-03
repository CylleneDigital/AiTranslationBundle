<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Command;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\ScannedLocaleProvider;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Command\Journey\SuggestionPresenter;
use CylleneDigital\AiTranslationBundle\Command\Journey\ValueBlock;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Suggestion\PlaceholderConsistencyChecker;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What a reviewer must be able to see before approving. The two warnings are the point:
 * approval refuses both cases downstream ({@see \CylleneDigital\AiTranslationBundle\Suggestion\SuggestionReviewer}),
 * so a card that stayed silent about them would send the reviewer into a dead end.
 */
final class SuggestionPresenterTest extends TestCase
{
    public function testTheReviewCardShowsTheSourceAndTheProposedValue(): void
    {
        $output = $this->render($this->suggestion('app.greeting', 'Hello %name%', 'Bonjour %name%'));

        self::assertStringContainsString('Source (en)', $output);
        self::assertStringContainsString('Hello %name%', $output);
        self::assertStringContainsString('Suggested by gpt (fr)', $output);
        self::assertStringContainsString('Bonjour %name%', $output);
        self::assertStringContainsString('Confidence: 90 %', $output);
    }

    /** An "every key" run proposes values for translated keys: the card shows what approving replaces. */
    public function testTheCardShowsTheCurrentFileValue(): void
    {
        $output = $this->render($this->suggestion('app.only_in_french', 'Only in French', 'Uniquement en français'));

        self::assertStringContainsString('Current value (file)', $output);
        self::assertStringContainsString('Seulement en français', $output);
    }

    public function testTheCardShowsTheCurrentOverride(): void
    {
        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('findOneByKey')->willReturn((new TranslationOverride('app.only_in_french', 'messages', 'fr'))->setValue('Juste en français'));

        $output = $this->render($this->suggestion('app.only_in_french', 'Only in French', 'Uniquement en français'), $repository);

        self::assertStringContainsString('Current value (override)', $output);
        self::assertStringContainsString('Juste en français', $output);
    }

    public function testAMissingKeyShowsNoCurrentValue(): void
    {
        self::assertStringNotContainsString('Current value', $this->render($this->suggestion('app.greeting', 'Hello %name%', 'Bonjour %name%')));
    }

    public function testASuggestionTheEntryAlreadyShowsSaysApprovingWritesNothing(): void
    {
        $output = $this->render($this->suggestion('app.only_in_french', 'Only in French', 'Seulement en français'));

        self::assertStringContainsString('Same as the current value: approving records the decision and writes no override.', $output);
    }

    #[RequiresPhpExtension('intl')]
    public function testAPluralMissingTheTargetLanguagesCategoriesIsWarnedAboutOnTheCard(): void
    {
        $suggestion = new TranslationSuggestion('app.rank', 'messages', 'en', 'Your {n, selectordinal, one {#st} other {#th}} order', 'Votre {n, selectordinal, one {#re} other {#e}} commande', 'fr', 'gpt', 0.9);

        $output = (string) preg_replace('/\s+/', ' ', $this->render($suggestion));

        self::assertStringContainsString('"n" (selectordinal) lacks the en categories two, few', $output);
    }

    /**
     * The warning is computed live, not read from the metadata: a mismatch the generation
     * recorded with an older checker (the ICU branch misread as a placeholder) must not
     * announce a refusal the approval no longer makes.
     */
    public function testAStaleMismatchFromTheMetadataIsNotWarnedAbout(): void
    {
        $suggestion = $this->suggestion('app.title', '{g, select, female {Madame} other {}} {name}', '{g, select, female {Señora} other {}} {name}');
        $suggestion->setMetadata(['placeholder_mismatch' => ['{Madame']]);

        $output = $this->render($suggestion);

        self::assertStringNotContainsString('loses or alters placeholders', $output);
        self::assertStringNotContainsString('Approving as-is will be refused', $output);
    }

    public function testAMismatchTheMetadataMissedIsWarnedAboutAnyway(): void
    {
        // A value edited since the generation, with no metadata at all.
        $output = $this->render($this->suggestion('app.greeting', 'Hello %name%', 'Bonjour'));

        self::assertStringContainsString('loses or alters placeholders', $output);
        self::assertStringContainsString('%name%', $output);
    }

    public function testAPlaceholderMismatchIsWarnedAboutOnTheCard(): void
    {
        $suggestion = $this->suggestion('app.greeting', 'Hello %name%', 'Bonjour %nom%');
        $suggestion->setMetadata(['placeholder_mismatch' => ['%name%', '%nom%']]);

        $output = $this->render($suggestion);

        self::assertStringContainsString('loses or alters placeholders', $output);
        self::assertStringContainsString('Approving as-is will be refused', $output);
    }

    /**
     * The syntax check is re-run live, not read from the metadata: the value may have
     * been hand-edited since the generation recorded it.
     */
    public function testAnIcuConstructInALegacyCatalogueIsWarnedAboutOnTheCard(): void
    {
        $suggestion = $this->suggestion('legacy.hello', 'Hello', '{count, plural, other {# pommes}}', catalogue: 'messages');

        $output = $this->render($suggestion);

        self::assertStringContainsString(TranslationValueValidator::ISSUE_ICU_IN_LEGACY, $output);
    }

    public function testAnErroredSuggestionShowsItsGenerationErrorAndNoProposal(): void
    {
        $suggestion = TranslationSuggestion::failed('app.greeting', 'messages', 'fr', 'Hello', 'en', 'gpt', 'HTTP 429 rate limited');

        $output = $this->render($suggestion);

        self::assertStringContainsString('Generation error', $output);
        self::assertStringContainsString('HTTP 429 rate limited', $output);
        self::assertStringNotContainsString('Suggested by', $output);
        self::assertStringContainsString('Confidence: 0 %', $output);
    }

    public function testTheScopeOfAScopedSuggestionIsCalledOut(): void
    {
        $suggestion = $this->suggestion('app.greeting', 'Hello', 'Bonjour', scope: 'b2b');

        $output = $this->render($suggestion);

        // Present twice on purpose: in the section title, and on its own line — the
        // title alone is too discreet for what approval will write.
        self::assertStringContainsString('scope b2b', $output);
        self::assertStringContainsString('Scope: b2b', $output);
    }

    public function testTheTableFlagsTheRowsAReviewerShouldLookAtFirst(): void
    {
        $clean = $this->suggestion('app.greeting', 'Hello', 'Bonjour');

        $broken = $this->suggestion('app.other', 'Hello %name%', 'Bonjour %nom%');
        $broken->setMetadata(['placeholder_mismatch' => ['%name%']]);

        $errored = TranslationSuggestion::failed('app.failed', 'messages', 'fr', 'Hello', 'en', 'gpt', 'boom');

        $io = $this->io($output = new BufferedOutput());
        $this->presenter()->pendingTable($io, [$clean, $broken, $errored]);

        $rendered = $output->fetch();

        self::assertStringContainsString('⚠ placeholders', $rendered);
        self::assertStringContainsString('(errored — no value)', $rendered);
        self::assertStringContainsString('Bonjour', $rendered);
    }

    /** Long keys and values are truncated so the table stays readable in a terminal. */
    public function testTheTableTruncatesOverlongValues(): void
    {
        $suggestion = $this->suggestion('app.long', 'Hello', str_repeat('a', 80));

        $io = $this->io($output = new BufferedOutput());
        $this->presenter()->pendingTable($io, [$suggestion]);

        $rendered = $output->fetch();

        self::assertStringContainsString('…', $rendered);
        self::assertStringNotContainsString(str_repeat('a', 80), $rendered);
    }

    private function render(TranslationSuggestion $suggestion, ?TranslationOverrideRepository $repository = null): string
    {
        $io = $this->io($output = new BufferedOutput());
        $this->presenter($repository)->reviewCard($io, $suggestion, 1, 1);

        return $output->fetch();
    }

    private function presenter(?TranslationOverrideRepository $repository = null): SuggestionPresenter
    {
        $catalogues = new CatalogueRegistry(
            $scanner = new TranslationFileScanner(__DIR__.'/../../Fixtures/translations'),
            new ScannedLocaleProvider($scanner),
        );

        return new SuggestionPresenter(
            new TranslationValueValidator($catalogues),
            new ValueBlock(),
            new OverrideReader($repository ?? $this->createStub(TranslationOverrideRepository::class), $catalogues),
            new PlaceholderConsistencyChecker(),
        );
    }

    private function suggestion(string $key, string $source, string $value, string $catalogue = 'messages', string $scope = ''): TranslationSuggestion
    {
        return new TranslationSuggestion($key, $catalogue, 'fr', $value, $source, 'en', 'gpt', 0.9, $scope);
    }

    private function io(BufferedOutput $output): SymfonyStyle
    {
        $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);

        return new SymfonyStyle(new ArrayInput([]), $output);
    }
}
