<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Command\Command;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "I know the key" door of the edit journey, through the hub: everything starts from the key — its
 * whole landscape is shown per catalogue and locale (the resolution cascade: file
 * value even when missing, global override, scoped overrides — untruncated), the
 * answers narrow it — the locale answer recapping its own slice — and the action
 * (edit, remove, create) is decided at the end, after seeing: it repeats no value the
 * landscape or its recap already put on screen.
 */
final class KeyLandscapeTest extends HubTestCase
{
    private const string MENU = 'Browse and edit translations';
    private const string DOOR = 'I know the key';

    public function testCreatesAnOverrideAppliedByTheTranslator(): void
    {
        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard', // key — known by the "messages" catalogue only
            'fr',            // locale
            'Pilotage',      // value (no override at the combination → straight to the prompt)
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('File value:', $display); // the landscape shows the cascade even without any override
        self::assertStringContainsString('created', $display);

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertSame('Pilotage', $translator->trans('app.dashboard', [], 'messages', 'fr'));
    }

    public function testTheLandscapeShowsTheFullCascadePerLocale(): void
    {
        $longValue = 'Le pilotage vous permet de suivre l\'ensemble de vos indicateurs au quotidien, sans exception ni raccourci.';
        $this->writer()->save('app.dashboard', 'messages', 'fr', $longValue);

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Nothing',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^messages\n=+$/m', $display); // the catalogue section
        self::assertStringContainsString($longValue, $display); // the whole point: no truncation
        self::assertStringContainsString('File value:', $display);
        self::assertStringContainsString('Tableau de bord', $display); // the fr file value, in regard
        self::assertStringContainsString('Override — no scope (global) (updated', $display); // the label names the scope dimension, the metadata follows it
        self::assertStringContainsString('Nothing changed.', $display);
        self::assertSame($longValue, $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testTheLocaleAnswerRedisplaysItsOwnCascadeAndNothingElseDoes(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $display = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Nothing',
        ])->getDisplay();

        // Landscape (every locale) then the recap of the answered one: the fr cascade
        // shows up exactly twice, the en block once, and the action repeats neither.
        self::assertSame(2, substr_count($display, 'Tableau de bord')); // the fr file value
        self::assertSame(2, substr_count($display, 'Pilotage')); // the fr override
        self::assertSame(1, substr_count($display, 'Dashboard')); // the en file value, landscape only
        self::assertStringNotContainsString('Current override', $display);
    }

    public function testTheRecapShowsTheAnsweredLocaleAndNoOther(): void
    {
        // An override on the locale that is *not* picked must stay out of the recap,
        // and above all must not be flagged as gone: the "no longer available" list is
        // the host's, never "every locale this particular display leaves out".
        $this->writer()->save('app.dashboard', 'messages', 'en', 'Cockpit');
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $display = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'en', // the landscape covered both locales, the recap must cover en alone
            'Nothing',
        ])->getDisplay();

        self::assertSame(2, substr_count($display, 'Cockpit')); // landscape + recap
        self::assertSame(1, substr_count($display, 'Pilotage')); // landscape only
        self::assertStringNotContainsString('locale no longer available', $display);
    }

    public function testAnOverrideOnAGoneLocaleIsFlaggedInTheLandscapeAndLeftOutOfTheRecap(): void
    {
        // "de" is not among the locales the host exposes — the landscape must still
        // surface the override rather than hide it, the recap of "fr" must not.
        $this->writer()->save('app.dashboard', 'messages', 'de', 'Übersicht');

        $display = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Pilotage', // no override on (messages, fr) → create prompt
        ])->getDisplay();

        self::assertSame(1, substr_count($display, 'Übersicht'));
        self::assertSame(1, substr_count($display, 'locale no longer available'));
        self::assertSame('Übersicht', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'de'));
    }

    public function testEditUpdatesTheOverrideAndReportsThePreviousValue(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Edit the value',
            'Cockpit',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Override — no scope (global) (updated', $display); // the recap's cascade carried the previous value
        self::assertStringNotContainsString('Current override', $display); // and the action did not repeat it
        self::assertStringContainsString('New value:', $display);
        self::assertSame('Cockpit', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testAPlainEnterOnTheValuePromptKeepsTheCurrentOne(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Edit the value',
            '', // New value (enter to keep the current one)
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('Pilotage', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    /** A label concatenated in a template needs its spaces: the prompt keeps them. */
    public function testTheValuePromptKeepsLeadingAndTrailingSpaces(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Edit the value',
            ' Panier ',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(' Panier ', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testAnAnswerOfSpacesOnlyKeepsTheCurrentValue(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Edit the value',
            '   ',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('Pilotage', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    /** A plain enter on a key without override used to store a copy of the file value. */
    public function testAPlainEnterOnAKeyWithoutOverrideWritesNothing(): void
    {
        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            '', // New value (enter to keep the current one)
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Same as the file value — nothing saved.', $tester->getDisplay());
        self::assertFalse($this->overrides()->hasOverride('app.dashboard', 'messages', 'fr'));
    }

    /** Typing the file value back is going back to it: the override goes, nothing shadows the file. */
    public function testTypingTheFileValueRemovesTheOverride(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Edit the value',
            'Tableau de bord', // the fr file value
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Same as the file value — override removed', $tester->getDisplay());
        self::assertFalse($this->overrides()->hasOverride('app.dashboard', 'messages', 'fr'));
    }

    /**
     * A value of several lines (legal texts, e-mail bodies) is typed line by line and ended
     * by a single "." — plain one-line questions, so a terminal, a piped script and this
     * test read it the same way (Symfony's own multiline mode aborted on a pipe).
     */
    public function testAMultilineValueIsEditedOverSeveralLines(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', "Ligne 1\nLigne 2");

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Edit the value',
            'Nouvelle ligne 1',
            '',                 // a blank line between paragraphs is kept
            'Nouvelle ligne 2',
            '.',                // a line with a single "." ends the value
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame("Nouvelle ligne 1\n\nNouvelle ligne 2", $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testEnterOnTheFirstLineKeepsAMultilineValue(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', "Ligne 1\nLigne 2");

        $tester = $this->runHub([self::MENU, self::DOOR, 'app.dashboard', 'fr', 'Edit the value', '']);

        self::assertStringContainsString('Same as the current override — nothing saved.', $tester->getDisplay());
        self::assertSame("Ligne 1\nLigne 2", $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testAMarkupLookingValueDisplaysVerbatim(): void
    {
        // A translation containing markup-looking text must not style the output.
        $this->writer()->save('app.dashboard', 'messages', 'fr', '<info>Piégé</info> & <b>gras</b>');

        $display = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Nothing',
        ])->getDisplay();

        self::assertStringContainsString('<info>Piégé</info> & <b>gras</b>', $display);
    }

    public function testRemoveDeletesAfterConfirmation(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Remove it',
            '', // Remove the override …? yes
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Override removed for "app.dashboard" (messages, fr).', $tester->getDisplay());
        self::assertNull($this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testDecliningTheRemovalKeepsTheOverride(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'Remove it',
            'no',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing removed.', $tester->getDisplay());
        self::assertSame('Pilotage', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testDisambiguatesAnAmbiguousKeyWithAPicker(): void
    {
        // The key exists in the "messages" files AND as an override in another
        // catalogue: two candidates, the journey must ask.
        $this->writer()->save('app.dashboard', 'shop/Product/messages', 'fr', 'Pilotage produit');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            'messages', // Catalogue picker
            'Pilotage', // no override on (messages, fr) → create prompt
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('shop/Product/messages', $display); // the existing override's catalogue section was shown
        self::assertStringContainsString('Pilotage produit', $display);
        self::assertSame('Pilotage', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
        self::assertSame('Pilotage produit', $this->overrides()->getOverrideValue('app.dashboard', 'shop/Product/messages', 'fr'));
    }

    public function testANewKeyGetsACataloguePicker(): void
    {
        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'brand.new_key',
            'fr',
            'messages', // The key is new — pick its catalogue
            'Toute neuve',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('The key "brand.new_key" is new — pick its catalogue.', $display);
        self::assertSame('Toute neuve', $this->overrides()->getOverrideValue('brand.new_key', 'messages', 'fr'));
    }

    public function testASyntaxBreakingValueNeedsAnExplicitYes(): void
    {
        // "messages" is a legacy-format catalogue: an ICU construct would render literally.
        $icuValue = '{count, plural, one {# vue} other {# vues}}';

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            $icuValue,
            'no', // Save it anyway?
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('syntax_icu_in_legacy', $display);
        self::assertStringContainsString('Nothing saved.', $display);
        self::assertNull($this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'app.dashboard',
            'fr',
            $icuValue,
            'yes', // Save it anyway?
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($icuValue, $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testAnOrphanOverrideKeyIsReachableForRemoval(): void
    {
        // The override's catalogue exists in no translation file — the key only
        // lives in the store, and the journey must still reach it.
        $this->writer()->save('old.key', 'gone/messages', 'fr', 'Disparu');

        $tester = $this->runHub([
            self::MENU,
            self::DOOR,
            'old.key',
            'fr',
            'Remove it',
            '',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('gone/messages', $display);
        self::assertStringContainsString('Disparu', $display);
        self::assertStringContainsString('(missing — no value for this locale)', $display); // no file behind an orphan
        self::assertNull($this->overrides()->getOverrideValue('old.key', 'gone/messages', 'fr'));
    }
}
