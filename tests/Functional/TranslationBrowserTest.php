<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Command\Command;

/**
 * The "let me browse" door of the edit journey: the source question (a small stored set
 * against an unbounded catalogue walk), the contextual filters — override presence being
 * one of them — the capped table, and the actions that follow from it.
 */
final class TranslationBrowserTest extends HubTestCase
{
    private const string MENU = 'Browse and edit translations';
    private const string DOOR = 'Let me browse and filter';
    private const string SOURCE_OVERRIDES = 'The stored overrides';
    private const string SOURCE_ALL = 'Every translation of one catalogue';
    private const string NOTHING = 'Nothing';

    public function testAnEmptyStoreEndsTheJourneyPolitely(): void
    {
        $tester = $this->runHub([self::MENU, self::DOOR, self::SOURCE_OVERRIDES]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No translation overrides stored.', $tester->getDisplay());
    }

    public function testASingleLocaleStoreListsWithoutAnyQuestion(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([self::MENU, self::DOOR, self::SOURCE_OVERRIDES, self::NOTHING]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('app.dashboard', $display);
        self::assertStringContainsString('Pilotage', $display);
        self::assertStringContainsString('1 translation(s) selected.', $display);
    }

    public function testTheOverridePresenceQuestionIsSkippedWhenEveryRowCarriesOne(): void
    {
        // On the overrides branch the filter would have a single answer — ContextualFilter
        // drops it, which is what makes "only the overrides" free rather than a question.
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $display = $this->runHub([self::MENU, self::DOOR, self::SOURCE_OVERRIDES, self::NOTHING])->getDisplay();

        self::assertDoesNotMatchRegularExpression('/^ Overrides \[/m', $display); // the filter question
    }

    public function testTheCatalogueFilterIsAnAutocompletedInput(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $this->writer()->save('product.out_of_stock', 'shop/Product/messages', 'fr', 'Épuisé');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_OVERRIDES,
            'shop/Product/messages', // Catalogue (enter for all) — typed, autocompleted
            self::NOTHING,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Épuisé', $display);
        self::assertStringNotContainsString('Pilotage', $display);
        self::assertStringContainsString('1 translation(s) selected.', $display);
    }

    public function testAPlainEnterOnTheCatalogueFilterKeepsEverything(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $this->writer()->save('product.out_of_stock', 'shop/Product/messages', 'fr', 'Épuisé');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_OVERRIDES,
            '', // Catalogue (enter for all)
            self::NOTHING,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('2 translation(s) selected.', $tester->getDisplay());
    }

    public function testTheReferenceLocaleListsKeysTheTargetNeverTranslated(): void
    {
        // The whole point of the split: "messages" in fr holds app.dashboard alone, so
        // browsing fr used to hide app.welcome — the very key needing a translation.
        // Listing from the reference brings it back, marked as missing.
        $display = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_ALL,
            'messages',
            'en', // Default locale — which keys to work through
            'fr', // Locale to edit
            '',   // Missing filter — (all); the overrides one is skipped, no row has one
            self::NOTHING,
        ])->getDisplay();

        self::assertStringContainsString('app.welcome', $display); // untranslated in fr, listed anyway
        self::assertStringContainsString('Welcome to the shop', $display); // read from the reference
        self::assertStringContainsString('(missing)', $display); // and what fr has to show for it
        self::assertStringContainsString('Tableau de bord', $display); // the key fr does hold
        self::assertStringContainsString('2 translation(s) selected.', $display);
    }

    public function testTheMissingFilterKeepsOnlyTheUntranslatedKeys(): void
    {
        $display = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_ALL,
            'messages',
            'en',
            'fr',
            'missing in fr',
            self::NOTHING,
        ])->getDisplay();

        self::assertStringContainsString('app.welcome', $display);
        self::assertStringNotContainsString('app.dashboard', $display); // translated, filtered out
        self::assertStringContainsString('1 translation(s) selected.', $display);
    }

    /** "" is a blank label on the site and a missing key for the coverage: the browser says so. */
    public function testAnEmptyValueReadsAsMissingAndIsFilteredAsSuch(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', '');

        $display = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_ALL,
            'messages',
            'en',
            'fr',
            '',  // Overrides filter — (all): one row has one, the other has not
            self::NOTHING,
        ])->getDisplay();

        self::assertStringContainsString('(empty — counted as missing)', $display);
        // Both rows are missing in fr now — the empty one too: the Missing filter would
        // have a single answer, so it is not even asked.
        self::assertDoesNotMatchRegularExpression('/^ Missing \[/m', $display);
        self::assertStringContainsString('2 translation(s) selected.', $display);
    }

    public function testTheOverridePresenceFilterKeepsOnlyTheOverriddenRows(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $display = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_ALL,
            'messages',
            'en',
            'fr',
            'with an override', // one row has one, the other has not — so the filter is offered
            self::NOTHING,      // down to a single row, every later filter skips itself
        ])->getDisplay();

        self::assertMatchesRegularExpression('/^ Overrides \[/m', $display); // the filter was offered
        self::assertStringContainsString('Pilotage', $display);
        self::assertStringNotContainsString('app.welcome', $display);
        self::assertStringContainsString('1 translation(s) selected.', $display);
    }

    public function testEditOneKeyRunsTheEditorOnThatRow(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_OVERRIDES,
            'Edit one key',
            'app.dashboard',
            'Edit the value',
            'Cockpit',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1/1 — app.dashboard (messages, fr)', $display); // which row the editor is on
        self::assertSame('Cockpit', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    /** A key held by several locales: "Edit one key" asks which row, instead of always opening the first. */
    public function testEditOneKeyAsksWhichRowWhenTheKeyIsSeveralRows(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'en', 'Cockpit');
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_OVERRIDES,
            '', // Locale filter — (all)
            'Edit one key',
            'app.dashboard',
            'fr (messages)', // Which one?
            'Edit the value',
            'Tableau de pilotage',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('en (messages)', $tester->getDisplay()); // both rows were offered
        self::assertSame('Tableau de pilotage', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
        self::assertSame('Cockpit', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'en'));
    }

    public function testEditingThemAllWalksTheSelectionAndCanBeQuit(): void
    {
        // Walking a selection without a way out would be worse than the dead-end table
        // the journey used to be: "Quit" stops it and the tally says what was left.
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $this->writer()->save('app.dashboard', 'messages', 'en', 'Cockpit');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_OVERRIDES,
            '', // Locale filter — (all)
            'Show or edit them all',
            'Quit', // on the very first row
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1/2 — app.dashboard', $display);
        self::assertStringContainsString('Done: 0 saved, 0 removed, 0 untouched, 2 left for later.', $display);
        self::assertSame('Pilotage', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    /** A row without override offers the same ways past it and out of it as the others. */
    public function testEditingThemAllLetsARowWithoutOverrideBeSkippedOrQuit(): void
    {
        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_ALL,
            'messages',
            'en',
            'fr',
            '', // Missing filter — (all)
            'Show or edit them all',
            self::NOTHING, // first row: skipped
            'Quit',        // second row: out
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Done: 0 saved, 0 removed, 1 untouched, 1 left for later.', $display);
        self::assertStringNotContainsString('Remove it', $display); // nothing to remove on these rows
        self::assertSame(0, $this->overrides()->countOverrides(), 'Neither answer became a translation.');
    }

    public function testTheTableShowsKeysWhole(): void
    {
        // 68 characters — the key is what "Edit one key" is answered with, so a "…"
        // in that column would make the row unreachable.
        $key = 'app.dashboard.widgets.revenue.comparison_with_previous_period.title';
        $this->writer()->save($key, 'messages', 'fr', 'Comparaison');

        $display = $this->runHub([self::MENU, self::DOOR, self::SOURCE_OVERRIDES, self::NOTHING])->getDisplay();

        self::assertStringContainsString($key, $display);
        self::assertStringNotContainsString('…', $display);
    }

    public function testTheTableCapsItsRowsButTheActionsStillCoverThemAll(): void
    {
        for ($i = 0; $i < 51; ++$i) {
            $this->writer()->save(\sprintf('app.key_%02d', $i), 'messages', 'fr', \sprintf('Valeur %02d', $i));
        }

        $display = $this->runHub([self::MENU, self::DOOR, self::SOURCE_OVERRIDES, self::NOTHING])->getDisplay();

        self::assertStringContainsString('Valeur 00', $display);
        self::assertStringNotContainsString('Valeur 50', $display); // past the cap
        self::assertStringContainsString('1 more row(s) not shown', $display);
        self::assertStringContainsString('cover all 51', $display); // SymfonyStyle wraps the note, so match inside one line
        self::assertStringContainsString('51 translation(s) selected.', $display);
    }

    public function testTheLocaleFilterNarrowsTheListing(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $this->writer()->save('app.dashboard', 'messages', 'en', 'Cockpit');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            self::SOURCE_OVERRIDES,
            'fr', // Locale filter — two locales stored
            self::NOTHING,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Pilotage', $display);
        self::assertStringNotContainsString('Cockpit', $display);
        self::assertStringContainsString('1 translation(s) selected.', $display);
    }
}
