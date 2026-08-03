<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use Symfony\Component\Console\Command\Command;

/**
 * The guided review through the hub: the overview table, the contextual narrowing
 * (a filter question only when the queue spans several values), and the four
 * decisions with their safeguards — placeholder and syntax refusals included.
 */
final class ReviewSuggestionsJourneyTest extends HubTestCase
{
    private const string MENU = 'Review the pending suggestions';

    public function testAnEmptyQueueEndsTheJourneyPolitely(): void
    {
        $tester = $this->runHub([self::MENU]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No pending suggestion to review.', $tester->getDisplay());
    }

    public function testTheSessionApprovesEditsAndRejects(): void
    {
        // findPending orders by confidence DESC — the confidences pin the order.
        $this->createPendingSuggestion('app.welcome', 'messages', 'Bienvenue dans la boutique', 0.95);
        $this->createPendingSuggestion('product.out_of_stock', 'shop/Product/messages', 'Épuisé', 0.9);
        $this->createPendingSuggestion('dashboard.saved', 'admin/Dashboard/flashes', 'Tableau enregistré', 0.8);

        $tester = $this->runHub([
            self::MENU,
            '', // Catalogue filter: (all) — three catalogues in the queue
            '', // Review these 3 suggestion(s) now? yes
            'approve',
            'edit',
            'Épuisé (relu)',
            'reject',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Suggested by stub (fr):', $display);
        self::assertStringContainsString('2 approved, 1 rejected, 0 skipped', $display);
        self::assertSame('Bienvenue dans la boutique', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr'));
        self::assertSame('Épuisé (relu)', $this->overrides()->getOverrideValue('product.out_of_stock', 'shop/Product/messages', 'fr'));
        self::assertNull($this->overrides()->getOverrideValue('dashboard.saved', 'admin/Dashboard/flashes', 'fr'));
        self::assertSame(2, $this->countSuggestions()); // the approved ones remain (audit), the rejected one is gone
    }

    /** The approval writes no override for a value the entry already shows: the message must not claim one. */
    public function testApprovingTheCurrentValueSaysNoOverrideWasWritten(): void
    {
        $this->createPendingSuggestion('app.dashboard', 'messages', 'Tableau de bord', 0.9); // the fr file value

        // The console wraps long lines: compared with the whitespace collapsed.
        $display = (string) preg_replace('/\s+/', ' ', $this->runHub([self::MENU, '', 'approve'])->getDisplay());

        self::assertStringContainsString('approved — same as the current value, no override written for "app.dashboard"', $display);
        self::assertStringNotContainsString('override written for "app.dashboard"', str_replace('no override written', '', $display));
        self::assertFalse($this->overrides()->hasOverride('app.dashboard', 'messages', 'fr'));
    }

    public function testApprovingTheFileValueOverAnOverrideSaysItWasRemoved(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $this->createPendingSuggestion('app.dashboard', 'messages', 'Tableau de bord', 0.9);

        $display = (string) preg_replace('/\s+/', ' ', $this->runHub([self::MENU, '', 'approve'])->getDisplay());

        self::assertStringContainsString('approved — same as the file value, override removed for "app.dashboard"', $display);
        self::assertFalse($this->overrides()->hasOverride('app.dashboard', 'messages', 'fr'));
    }

    /** A suggestion of several lines (a legal text) is edited line by line in the review too, not cut to its first line. */
    public function testEditingAMultilineSuggestionKeepsEveryLine(): void
    {
        $this->createPendingSuggestion('app.welcome', 'messages', "Ligne 1\nLigne 2", 0.9);

        $tester = $this->runHub([
            self::MENU,
            '',     // review now? yes
            'edit',
            'Nouvelle ligne 1',
            'Nouvelle ligne 2',
            '.',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame("Nouvelle ligne 1\nNouvelle ligne 2", $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr'));
    }

    public function testTheOverviewTableShowsKeysWhole(): void
    {
        // 68 characters — two suggestions of one catalogue are told apart by their key,
        // so the overview must not cut it.
        $key = 'app.dashboard.widgets.revenue.comparison_with_previous_period.title';
        $this->createPendingSuggestion($key, 'messages', 'Comparaison', 0.9);

        $display = $this->runHub([
            self::MENU,
            'no', // Review this suggestion now?
        ])->getDisplay();

        self::assertStringContainsString($key, $display);
        self::assertStringNotContainsString('…', $display);
    }

    public function testTheCatalogueFilterNarrowsTheSession(): void
    {
        $this->createPendingSuggestion('app.welcome', 'messages', 'Bienvenue dans la boutique', 0.95);
        $this->createPendingSuggestion('product.out_of_stock', 'shop/Product/messages', 'Épuisé', 0.9);

        $tester = $this->runHub([
            self::MENU,
            'messages', // Catalogue filter
            '',         // review now? yes
            'approve',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1/1 — app.welcome', $display);
        self::assertStringContainsString('1 approved, 0 rejected, 0 skipped', $display);
        self::assertNull($this->overrides()->getOverrideValue('product.out_of_stock', 'shop/Product/messages', 'fr'));
    }

    public function testASingleValueQueueAsksNoFilterQuestion(): void
    {
        $this->createPendingSuggestion('app.welcome', 'messages', 'Bienvenue dans la boutique', 0.95);

        $tester = $this->runHub([
            self::MENU,
            '', // review now? yes — no locale/catalogue/scope question before
            'skip',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        // No narrowing question: the queue spans a single locale, catalogue and scope.
        self::assertStringNotContainsString('Catalogue [(all)]', $display);
        self::assertStringNotContainsString('Locale [(all)]', $display);
        self::assertStringContainsString('1/1 — app.welcome', $display);
    }

    public function testDecliningTheSessionLeavesEverythingPending(): void
    {
        $this->createPendingSuggestion('app.welcome', 'messages', 'Bienvenue dans la boutique', 0.95);

        $tester = $this->runHub([
            self::MENU,
            'no', // Review this suggestion now?
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, $this->countSuggestions());
        self::assertNull($this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr'));
    }

    public function testALongerQueueCanBeDeclinedFromTheProceedMenu(): void
    {
        $this->createPendingSuggestion('app.welcome', 'messages', 'Bienvenue dans la boutique', 0.95);
        $this->createPendingSuggestion('product.out_of_stock', 'shop/Product/messages', 'Épuisé', 0.9);

        $tester = $this->runHub([
            self::MENU,
            '', // Catalogue filter: (all)
            'Nothing for now',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(2, $this->countSuggestions());
    }

    public function testAPlaceholderMismatchIsSurfacedAndBlocksTheApproval(): void
    {
        // A small model flattening an ICU plural into a plain sentence — the mismatch
        // recorded at generation must show up in the table and the card, and approving
        // as-is must be refused (the suggestion stays pending).
        $entityManager = $this->entityManager();
        $suggestion = new TranslationSuggestion(
            'app.welcome',
            'messages',
            'fr',
            'Nous vous suggérons d’ajouter {nb_products} boîtes.',
            '{products, plural, =1 {Add {nb_products} box.} other {Add {nb_products} boxes.}}',
            'en',
            'ollama',
            0.98,
        );
        $suggestion->setMetadata(['placeholder_mismatch' => ['{nb_products', '{products']]);
        $entityManager->persist($suggestion);
        $entityManager->flush();

        $tester = $this->runHub([
            self::MENU,
            '', // review now? yes
            'approve',
        ]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('⚠ placeholders', $display);
        self::assertStringContainsString('loses or alters placeholders', $display);
        self::assertStringNotContainsString('Autocorrected value', $display); // several markers lost: no deterministic fix
        self::assertStringContainsString('{products…', $display);
        self::assertStringContainsString('Approving as-is will be refused', $display);
        self::assertStringContainsString('missing: {products}', (string) preg_replace('/\s+/', ' ', $display));
        self::assertStringContainsString('0 approved, 0 rejected, 1 skipped', $display);
        self::assertNull($this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr'));
        self::assertSame(1, $this->countSuggestions());
    }

    /** A refused approval asks again on the same card: the reviewer fixes the value without leaving the session. */
    public function testARefusedApprovalAsksAgainOnTheSameCard(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(new TranslationSuggestion('app.welcome', 'messages', 'fr', 'Bonjour !', 'Hello %name%!', 'en', 'stub', 0.9));
        $entityManager->flush();

        $tester = $this->runHub([
            self::MENU,
            '',        // review now? yes
            'approve', // refused: %name% is lost
            'edit',    // asked again, same card
            'Bonjour %name% !',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(1, substr_count($display, '1/1 — app.welcome'), 'The card is not walked past.');
        self::assertSame(2, substr_count($display, 'Decision [skip]'));
        self::assertStringContainsString('1 approved, 0 rejected, 0 skipped', $display);
        self::assertSame('Bonjour %name% !', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr'));
    }

    /** A placeholder the source does not have is not "lost": the refusal says it is unexpected. */
    public function testARefusalNamesAnAddedPlaceholderAsUnexpected(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(new TranslationSuggestion('app.welcome', 'messages', 'fr', 'Bonjour %name% {foo}', 'Hello %name%!', 'en', 'stub', 0.9));
        $entityManager->flush();

        $display = (string) preg_replace('/\s+/', ' ', $this->runHub([self::MENU, '', 'approve'])->getDisplay());

        self::assertStringContainsString('not in the source: {foo}', $display);
        self::assertStringNotContainsString('missing:', $display);
        self::assertStringNotContainsString('loses', substr($display, (int) strpos($display, 'Refused')));
    }

    public function testARenamedPlaceholderGetsAnAutocorrectProposedAndApproved(): void
    {
        // The model translated the placeholder name — the exact swap is on screen
        // and "approve autocorrect" applies the corrected value, not the flawed one.
        $entityManager = $this->entityManager();
        $entityManager->persist(new TranslationSuggestion('app.welcome', 'messages', 'fr', 'Bienvenue %nom% !', 'Welcome %name%!', 'en', 'stub', 0.9));
        $entityManager->flush();

        $tester = $this->runHub([
            self::MENU,
            '', // Review this suggestion now? yes
            'approve autocorrect',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Autocorrected value', $display);
        self::assertStringContainsString('applied: %nom% → %name%', $display);
        self::assertStringContainsString('Bienvenue %name% !', $display);
        self::assertStringContainsString('1 approved, 0 rejected, 0 skipped', $display);
        self::assertSame('Bienvenue %name% !', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr'));
    }

    public function testASyntaxBreakingValueIsSurfacedInTheReview(): void
    {
        // "messages" is a legacy-format catalogue: an ICU construct in the suggested
        // value would render literally — the live syntax check must warn before the
        // reviewer approves.
        $this->createPendingSuggestion('app.welcome', 'messages', '{count, plural, one {# accueil} other {# accueils}}', 0.9);

        $tester = $this->runHub([
            self::MENU,
            '', // review now? yes
            'skip',
        ]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('⚠ syntax', $display);
        self::assertStringContainsString('syntax_icu_in_legacy', $display);
        self::assertStringContainsString('breaks the catalogue', $display);
    }

    public function testAScopedSuggestionAnnouncesItsScopeOnTheCard(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(new TranslationSuggestion('app.welcome', 'messages', 'fr', 'Bienvenue boutique', 'Welcome to the shop', 'en', 'stub', 0.9, scope: 'SHOP'));
        $entityManager->flush();

        $display = $this->runHub([
            self::MENU,
            '', // review now? yes
            'skip',
        ])->getDisplay();

        self::assertStringContainsString('Scope: SHOP', $display);
    }

    public function testOneSuggestionCanBePickedByItsId(): void
    {
        $this->createPendingSuggestion('app.welcome', 'messages', 'Bienvenue dans la boutique', 0.95);
        $this->createPendingSuggestion('product.out_of_stock', 'shop/Product/messages', 'Épuisé', 0.9);
        $id = $this->fetchInt(
            "SELECT id FROM cyllene_translation_suggestion WHERE translation_key = 'product.out_of_stock'",
        );

        $tester = $this->runHub([
            self::MENU,
            '',                          // Catalogue filter: (all)
            'Pick one suggestion by id', // How do you want to proceed?
            (string) $id,
            'approve',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1/1 — product.out_of_stock', $display);
        self::assertSame('Épuisé', $this->overrides()->getOverrideValue('product.out_of_stock', 'shop/Product/messages', 'fr'));
        self::assertNull($this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr')); // the other one untouched
        self::assertSame(2, $this->countSuggestions());
    }

    public function testQuitStopsTheSessionAndCountsTheRest(): void
    {
        $this->createPendingSuggestion('app.welcome', 'messages', 'Bienvenue dans la boutique', 0.95);
        $this->createPendingSuggestion('product.out_of_stock', 'shop/Product/messages', 'Épuisé', 0.9);

        $tester = $this->runHub([
            self::MENU,
            '', // Catalogue filter: (all)
            '', // review now? yes
            'quit',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('0 approved, 0 rejected, 0 skipped, 2 left for later', $display);
        self::assertSame(2, $this->countSuggestions());
    }

    public function testDeletingTheErroredRowsOnlyTouchesTheOnesOnScreen(): void
    {
        // Two errored rows of a scoped run, plus a global one in another catalogue.
        // Narrowing on the catalogue leaves the two scoped rows and skips the scope
        // question (a single value does not discriminate) — the deletion must follow
        // the listed ids, not a filter guessed from the unanswered questions.
        $this->createErroredSuggestion('app.welcome', 'messages', 'SHOP');
        $this->createErroredSuggestion('app.bye', 'messages', 'SHOP');
        $this->createErroredSuggestion('product.out_of_stock', 'shop/Product/messages', '');

        $tester = $this->runHub([
            self::MENU,
            'messages',                       // Catalogue filter
            'Delete all errored suggestions', // How do you want to proceed?
            'yes',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('2 errored suggestion(s) deleted.', $display);
        self::assertSame(1, $this->countSuggestions());
        self::assertSame(
            'product.out_of_stock',
            $this->entityManager()->getConnection()->fetchOne('SELECT translation_key FROM cyllene_translation_suggestion'),
        );
    }

    private function createErroredSuggestion(string $key, string $catalogue, string $scope): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(TranslationSuggestion::failed($key, $catalogue, 'fr', 'Source text', 'en', 'stub', 'provider exploded', $scope));
        $entityManager->flush();
    }

    private function createPendingSuggestion(string $key, string $catalogue, string $suggestedValue, float $confidence): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(new TranslationSuggestion($key, $catalogue, 'fr', $suggestedValue, 'Source text', 'en', 'stub', $confidence));
        $entityManager->flush();
    }

    private function countSuggestions(): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion');
    }
}
