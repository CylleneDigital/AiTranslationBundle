<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Entity\SuggestionStatus;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Event\OverrideSavedEvent;
use CylleneDigital\AiTranslationBundle\Override\InvalidOverrideException;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionAlreadyReviewedException;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * End-to-end on a real database: catalogue discovery from the host translations/ files
 * (tests/App/translations), then the suggestion → approve → override →
 * translation-applied chain.
 */
final class SuggestionWorkflowTest extends DatabaseTestCase
{
    public function testCataloguesAreDiscoveredFromTheHostTranslationFiles(): void
    {
        /** @var CatalogueRegistry $catalogues */
        $catalogues = self::getContainer()->get(CatalogueRegistry::class);
        /** @var OverrideReader $overrides */
        $overrides = self::getContainer()->get(OverrideReader::class);

        $identifiers = $catalogues->getCatalogueIdentifiers();

        self::assertContains('messages', $identifiers);
        self::assertContains('shop/Product/messages', $identifiers);
        self::assertContains('admin/Dashboard/flashes', $identifiers);

        $catalogue = $catalogues->getCatalogue('shop/Product/messages');
        self::assertNotNull($catalogue);
        self::assertSame('shop', $catalogue->category);
        self::assertSame('Product', $catalogue->domain);
        self::assertSame('messages', $catalogue->type);

        // Without a host locale registry, the locales are the ones seen in the files.
        self::assertSame(['en', 'fr'], $catalogues->getAvailableLocales());

        $translations = $overrides->getTranslationsForCatalogue('messages', 'en_US');
        self::assertArrayHasKey('app.dashboard', $translations);
        self::assertSame('Dashboard', $translations['app.dashboard']['original']);

        // A key missing from the French files is reported as missing (AI suggestion input).
        $french = $overrides->getTranslationsForCatalogue('messages', 'fr_FR');
        self::assertArrayNotHasKey('app.welcome', $french);
    }

    public function testApprovingASuggestionCreatesAnOverrideAppliedByTheTranslator(): void
    {
        $container = self::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SuggestionReviewer $reviewer */
        $reviewer = $container->get(SuggestionReviewer::class);

        $suggestion = new TranslationSuggestion(
            'app.welcome',
            'messages',
            'fr_FR',
            'Bienvenue dans la boutique',
            'Welcome to the shop',
            'en_US',
            'stub',
            0.95,
        );
        $entityManager->persist($suggestion);
        $entityManager->flush();

        $reviewer->approve($suggestion);

        self::assertTrue($suggestion->isApproved());
        // The default NullAuthorProvider leaves the reviewer anonymous → "system".
        self::assertSame('system', $suggestion->getReviewedBy());

        /** @var TranslationOverrideRepository $overrideRepository */
        $overrideRepository = $container->get(TranslationOverrideRepository::class);
        $override = $overrideRepository->findOneByKey('app.welcome', 'messages', 'fr_FR');

        self::assertInstanceOf(TranslationOverride::class, $override);
        self::assertSame('Bienvenue dans la boutique', $override->getValue());

        /** @var TranslatorInterface $translator */
        $translator = $container->get(TranslatorInterface::class);
        self::assertSame('Bienvenue dans la boutique', $translator->trans('app.welcome', [], 'messages', 'fr_FR'));
    }

    /**
     * A suggestion identical to the file value (an "every key" run on a translated key)
     * is approved — the decision is recorded — but no override is written: it would only
     * shadow the file, and hide its next correction.
     */
    public function testApprovingTheFileValueWritesNoOverride(): void
    {
        $suggestion = $this->storeSuggestion('app.dashboard', 'Tableau de bord');

        $this->reviewer()->approve($suggestion);

        self::assertTrue($suggestion->isApproved());
        self::assertFalse($this->overrides()->hasOverride('app.dashboard', 'messages', 'fr'));
        // The approved row — the audit trail of the decision — says what it did.
        self::assertSame('none', ($suggestion->getMetadata() ?? [])['override_change'] ?? null);
    }

    /** Approving the file value over an override goes back to the file. */
    public function testApprovingTheFileValueOverAnOverrideRemovesIt(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $suggestion = $this->storeSuggestion('app.dashboard', 'Tableau de bord');

        $this->reviewer()->approve($suggestion);

        self::assertTrue($suggestion->isApproved());
        self::assertFalse($this->overrides()->hasOverride('app.dashboard', 'messages', 'fr'));
        self::assertSame('revert', ($suggestion->getMetadata() ?? [])['override_change'] ?? null);
        self::assertSame('file', ($suggestion->getMetadata() ?? [])['reverted_to'] ?? null);
    }

    /** A regional entry goes back to the parent language's override, not to the file: the row says so. */
    public function testApprovingTheParentValueOverARegionalOverrideRecordsWhatItFallsBackTo(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $this->writer()->save('app.dashboard', 'messages', 'fr_FR', 'Cockpit');
        $single = new TranslationSuggestion('app.dashboard', 'messages', 'fr_FR', 'Pilotage', 'Dashboard', 'en', 'stub', 0.9);
        $this->entityManager()->persist($single);
        $this->entityManager()->flush();

        $this->reviewer()->approve($single);

        self::assertSame('revert', ($single->getMetadata() ?? [])['override_change'] ?? null);
        self::assertSame('inherited', ($single->getMetadata() ?? [])['reverted_to'] ?? null);

        $this->writer()->save('app.dashboard', 'messages', 'fr_FR', 'Cockpit');
        $batch = new TranslationSuggestion('app.dashboard', 'messages', 'fr_FR', 'Pilotage', 'Dashboard', 'en', 'stub', 0.9);
        $this->entityManager()->persist($batch);
        $this->entityManager()->flush();

        self::assertSame([], $this->reviewer()->approveMany([$batch]));
        self::assertSame('inherited', ($batch->getMetadata() ?? [])['reverted_to'] ?? null);
    }

    public function testApprovingInBatchWritesOnlyTheValuesThatDifferFromTheFile(): void
    {
        $same = $this->storeSuggestion('app.dashboard', 'Tableau de bord');
        $new = $this->storeSuggestion('app.welcome', 'Bienvenue dans la boutique');

        self::assertSame([], $this->reviewer()->approveMany([$same, $new]));

        self::assertTrue($same->isApproved());
        self::assertTrue($new->isApproved());
        self::assertSame('none', ($same->getMetadata() ?? [])['override_change'] ?? null);
        self::assertSame('write', ($new->getMetadata() ?? [])['override_change'] ?? null);
        self::assertFalse($this->overrides()->hasOverride('app.dashboard', 'messages', 'fr'));
        self::assertSame('Bienvenue dans la boutique', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr'));
    }

    /** The override keeps the file value it was approved over, as one created in the editor does. */
    public function testApprovingRecordsTheFileValueAsTheOriginal(): void
    {
        $this->reviewer()->approve($this->storeSuggestion('app.dashboard', 'Pilotage'));

        $override = $this->overrideRepository()->findOneByKey('app.dashboard', 'messages', 'fr');
        self::assertNotNull($override);
        self::assertSame('Tableau de bord', $override->getOriginalValue());
    }

    public function testApprovingInBatchRecordsTheFileValueAsTheOriginal(): void
    {
        self::assertSame([], $this->reviewer()->approveMany([$this->storeSuggestion('app.dashboard', 'Pilotage')]));

        $override = $this->overrideRepository()->findOneByKey('app.dashboard', 'messages', 'fr');
        self::assertNotNull($override);
        self::assertSame('Tableau de bord', $override->getOriginalValue());
    }

    /** A host listener (HTTP purge, webhook) must only hear of a committed override. */
    public function testTheOverrideEventOfAnApprovalIsDispatchedAfterTheCommit(): void
    {
        $container = self::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');

        $inTransaction = [];
        $dispatcher->addListener(OverrideSavedEvent::class, static function () use ($entityManager, &$inTransaction): void {
            $inTransaction[] = $entityManager->getConnection()->isTransactionActive();
        });

        $suggestion = new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.95);
        $entityManager->persist($suggestion);
        $entityManager->flush();

        /** @var SuggestionReviewer $reviewer */
        $reviewer = $container->get(SuggestionReviewer::class);
        $reviewer->approve($suggestion);

        self::assertSame([false], $inTransaction);
    }

    /**
     * The entity in hand says pending, but another process approved it since and an admin
     * then corrected the override: approving it again must not write over that correction.
     */
    public function testASuggestionReviewedMeanwhileIsNotApprovedAgain(): void
    {
        $container = self::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SuggestionReviewer $reviewer */
        $reviewer = $container->get(SuggestionReviewer::class);

        $suggestion = new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue IA', 'Welcome to the shop', 'en_US', 'stub', 0.95);
        $entityManager->persist($suggestion);
        $entityManager->flush();

        $entityManager->getConnection()->executeStatement("UPDATE cyllene_translation_suggestion SET status = 'approved', pending_key = NULL");
        $this->writer()->save('app.welcome', 'messages', 'fr_FR', 'Correction manuelle');

        try {
            $reviewer->approve($suggestion);
            self::fail('The suggestion was expected to be refused as already reviewed.');
        } catch (SuggestionAlreadyReviewedException) {
        }

        self::assertTrue($suggestion->isPending(), 'The entity in hand is left untouched.');
        self::assertSame('Correction manuelle', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr_FR'));
        self::assertSame([$suggestion], $reviewer->approveMany([$suggestion]), 'The batch skips it too.');
        self::assertSame('Correction manuelle', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr_FR'));

        // Rejecting it would delete the approval's audit trail.
        try {
            $reviewer->reject($suggestion);
            self::fail('The rejection was expected to be refused as already reviewed.');
        } catch (SuggestionAlreadyReviewedException) {
        }

        $reviewer->rejectMany([$suggestion]);
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion'), 'The row is still there.');
    }

    /**
     * Once its row is deleted, Doctrine resets the entity's id: without a status of its
     * own it would look like a suggestion never stored, still pending — and be rejected,
     * and announced, a second time.
     */
    public function testARejectedSuggestionCannotBeRejectedOrApprovedAgain(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);

        $suggestion = new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.95);
        $entityManager->persist($suggestion);
        $entityManager->flush();

        $reviewer->reject($suggestion);
        self::assertSame(SuggestionStatus::Rejected, $suggestion->getStatus());

        foreach ([static fn () => $reviewer->reject($suggestion), static fn () => $reviewer->approve($suggestion)] as $review) {
            try {
                $review();
                self::fail('A rejected suggestion was expected to be refused.');
            } catch (SuggestionAlreadyReviewedException) {
            }
        }

        self::assertNull($this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr_FR'));
    }

    /**
     * A write refused after the suggestion was approved in memory: the rollback restores
     * the database, and the entity is put back too — else the host's next flush would
     * record an approval nothing applied.
     */
    public function testARefusedWriteLeavesTheSuggestionPending(): void
    {
        // A locale the override table refuses ("-" separator), stored directly as an old row could be.
        $suggestion = new TranslationSuggestion('app.welcome', 'messages', 'fr-FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.95);
        $this->entityManager()->persist($suggestion);
        $this->entityManager()->flush();

        try {
            $this->reviewer()->approve($suggestion, 'Bienvenue à vous');
            self::fail('The write was expected to be refused.');
        } catch (InvalidOverrideException) {
        }

        self::assertTrue($suggestion->isPending());
        self::assertNull($suggestion->getReviewedBy());
        self::assertNull($suggestion->getMetadata(), 'Neither edited_on_approve nor override_change is left behind.');

        $this->entityManager()->flush();
        self::assertSame(1, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE status = 'pending'"), 'A later flush records no approval.');
    }

    public function testARefusedBatchWriteLeavesEverySuggestionPending(): void
    {
        $refused = new TranslationSuggestion('app.welcome', 'messages', 'fr-FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.95);
        $accepted = new TranslationSuggestion('app.dashboard', 'messages', 'fr_FR', 'Tableau de bord', 'Dashboard', 'en_US', 'stub', 0.95);
        $this->entityManager()->persist($refused);
        $this->entityManager()->persist($accepted);
        $this->entityManager()->flush();

        try {
            $this->reviewer()->approveMany([$accepted, $refused]);
            self::fail('The batch write was expected to be refused.');
        } catch (InvalidOverrideException) {
        }

        self::assertTrue($accepted->isPending());
        self::assertTrue($refused->isPending());
        self::assertNull($accepted->getMetadata());

        $this->entityManager()->flush();
        self::assertSame(2, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE status = 'pending'"));
        self::assertNull($this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr_FR'));
    }

    /** Nothing in the database to race with: an entity not stored yet is approved as it stands. */
    public function testASuggestionNotStoredYetCanBeApproved(): void
    {
        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);

        $suggestion = new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.95);
        $reviewer->approve($suggestion);

        self::assertTrue($suggestion->isApproved());
        self::assertSame('Bienvenue', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr_FR'));
    }

    public function testRemovingTheOverrideFallsBackToTheFileValue(): void
    {
        $container = self::getContainer();

        /** @var OverrideReader $overrides */
        $overrides = $container->get(OverrideReader::class);
        /** @var OverrideWriter $writer */
        $writer = $container->get(OverrideWriter::class);
        $writer->save('app.dashboard', 'messages', 'en_US', 'Overridden');
        $writer->remove('app.dashboard', 'messages', 'en_US');

        $translations = $overrides->getTranslationsForCatalogue('messages', 'en_US');
        self::assertFalse($translations['app.dashboard']['hasOverride']);
        self::assertSame('Dashboard', $translations['app.dashboard']['original']);
    }

    private function storeSuggestion(string $key, string $value): TranslationSuggestion
    {
        $suggestion = new TranslationSuggestion($key, 'messages', 'fr', $value, 'source', 'en', 'stub', 0.9);
        $this->entityManager()->persist($suggestion);
        $this->entityManager()->flush();

        return $suggestion;
    }

    private function reviewer(): SuggestionReviewer
    {
        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);

        return $reviewer;
    }

    private function overrideRepository(): TranslationOverrideRepository
    {
        /** @var TranslationOverrideRepository $repository */
        $repository = self::getContainer()->get(TranslationOverrideRepository::class);

        return $repository;
    }
}
