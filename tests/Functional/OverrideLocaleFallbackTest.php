<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionGenerator;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionReviewer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The language chain, end to end — through the real DQL, which is the half a stubbed
 * repository cannot prove.
 *
 * The situation this guards is the ordinary one for a plain Symfony host: the files are
 * named "messages.fr.yaml", so "fr" is the only locale the bundle offers for browsing and
 * the only locale its overrides can be saved under — while the application itself runs on
 * "fr_FR". The file value was already served through the chain; the override meant to
 * replace it has to travel the same way.
 *
 * Hosts that expose regional locales directly (Sylius, whose channels are "fr_FR") store
 * and read the same locale on both sides and never depended on this — the tests below
 * pin that this stays true too.
 */
final class OverrideLocaleFallbackTest extends DatabaseTestCase
{
    public function testAnOverrideSavedOnTheParentLanguageIsServedToTheRegionalLocale(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Mon tableau de bord');

        self::assertSame('Mon tableau de bord', $this->translator()->trans('app.dashboard', [], 'messages', 'fr_FR'));
    }

    public function testTheRegionalOverrideWinsOverTheParentLanguageOne(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Depuis fr');
        $this->writer()->save('app.dashboard', 'messages', 'fr_FR', 'Depuis fr_FR');

        self::assertSame('Depuis fr_FR', $this->translator()->trans('app.dashboard', [], 'messages', 'fr_FR'));
        // "fr" itself does not see the more specific row.
        self::assertSame('Depuis fr', $this->translator()->trans('app.dashboard', [], 'messages', 'fr'));
    }

    public function testASiblingRegionalOverrideDoesNotLeakAcrossRegions(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr_BE', 'Depuis fr_BE');

        // Untouched: the file value of the "fr" catalogue, served through the chain.
        self::assertSame('Tableau de bord', $this->translator()->trans('app.dashboard', [], 'messages', 'fr_FR'));
    }

    /**
     * The regional entry is built FROM the parent rows, so a write on the parent has to
     * reach it — in the same process, without a kernel.request to drop the maps.
     */
    public function testWritingOnTheParentLanguageInvalidatesTheRegionalLookup(): void
    {
        $translator = $this->translator();

        self::assertSame('Tableau de bord', $translator->trans('app.dashboard', [], 'messages', 'fr_FR'));

        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Mon tableau de bord');

        self::assertSame('Mon tableau de bord', $translator->trans('app.dashboard', [], 'messages', 'fr_FR'));

        $this->writer()->remove('app.dashboard', 'messages', 'fr');

        self::assertSame('Tableau de bord', $translator->trans('app.dashboard', [], 'messages', 'fr_FR'));
    }

    /**
     * Coverage counts what a visitor of the locale actually sees, so it reads the
     * overrides through the same chain the translator does.
     */
    public function testTheEffectiveOverridesOfARegionalLocaleIncludeTheParentOnes(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Depuis fr');

        self::assertSame(
            ['messages' => ['app.dashboard' => 'Depuis fr']],
            $this->overrides()->getEffectiveOverridesByCatalogue('fr_FR'),
        );
    }

    private function translator(): TranslatorInterface
    {
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');

        return $translator;
    }

    /**
     * The other readers follow the same chain as the translator and the coverage: the
     * value a "fr_FR" visitor sees through a "fr" override is not missing, it is what the
     * entry inherits. They used to read the exact locale only — a "fr" override counted
     * for the coverage but not for the generation, which sent (and billed) the key again.
     */
    public function testTheMissingKeysOfARegionalLocaleHonourTheParentOverrides(): void
    {
        // "app.welcome" has no fr file value: only the "fr" override translates it.
        $this->writer()->save('app.welcome', 'messages', 'fr', 'Bienvenue');

        /** @var SuggestionGenerator $generator */
        $generator = self::getContainer()->get(SuggestionGenerator::class);

        self::assertArrayNotHasKey('app.welcome', $generator->collectTexts('messages', 'fr_FR', 'en', true), 'Not missing: a fr_FR visitor sees "Bienvenue".');
        // A regional variant is still one "every key" run away.
        self::assertArrayHasKey('app.welcome', $generator->collectTexts('messages', 'fr_FR', 'en', false));
    }

    public function testTheBrowsedEntryOfARegionalLocaleNamesWhatItInherits(): void
    {
        $this->writer()->save('app.welcome', 'messages', 'fr', 'Bienvenue');

        $entry = $this->overrides()->getTranslationsForCatalogue('messages', 'fr_FR')['app.welcome'] ?? null;

        self::assertNotNull($entry);
        self::assertNull($entry['override'], 'No fr_FR override of its own.');
        self::assertFalse($entry['hasOverride']);
        self::assertSame('Bienvenue', $entry['inherited']);
        self::assertSame(['locale' => 'fr', 'scope' => ''], $entry['inheritedFrom']);
    }

    /** The baseline OverrideChange compares with: a value the parent override already gives writes nothing. */
    public function testTheBaselineOfARegionalLocaleIsTheParentOverride(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Depuis fr');

        self::assertSame('Depuis fr', $this->overrides()->getBaselineValue('app.dashboard', 'messages', 'fr_FR'));
        self::assertSame('Tableau de bord', $this->overrides()->getBaselineValue('app.dashboard', 'messages', 'fr'), '"fr" itself falls back to its file.');
    }

    /**
     * The runtime's precedence, for a scope of a regional locale: the regional global row
     * shadows the parent's scoped one, which shadows the parent's global one.
     */
    public function testTheInheritedValueFollowsTheRuntimePrecedence(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'fr global');
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'fr b2b', scope: 'b2b');
        self::assertSame('fr b2b', $this->overrides()->getBaselineValue('app.dashboard', 'messages', 'fr_FR', 'b2b'));

        $this->writer()->save('app.dashboard', 'messages', 'fr_FR', 'fr_FR global');
        self::assertSame('fr_FR global', $this->overrides()->getBaselineValue('app.dashboard', 'messages', 'fr_FR', 'b2b'));
        // What a b2b visitor of fr_FR actually sees, without a fr_FR b2b row of its own.
        self::assertSame('fr_FR global', $this->translator()->trans('app.dashboard', [], 'messages', 'fr_FR'));
    }

    public function testApprovingTheValueAParentOverrideGivesWritesNothing(): void
    {
        $this->writer()->save('app.welcome', 'messages', 'fr', 'Bienvenue');
        $suggestion = new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en', 'stub', 0.9);
        $this->entityManager()->persist($suggestion);
        $this->entityManager()->flush();

        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);
        $reviewer->approve($suggestion);

        self::assertTrue($suggestion->isApproved());
        self::assertNull($this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr_FR'), 'It would only duplicate the fr override.');
    }
}
