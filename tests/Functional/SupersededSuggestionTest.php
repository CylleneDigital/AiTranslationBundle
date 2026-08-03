<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\SuggestionStatus;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Event\SuggestionRejectedEvent;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionGenerator;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionReviewer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * An override written for a key by any other way than approving its suggestion — an
 * edit, an import, the host's own code — closes the pending suggestions of that key:
 * someone chose the value, the proposal is rejected.
 */
final class SupersededSuggestionTest extends DatabaseTestCase
{
    /** @var list<SuggestionRejectedEvent> */
    private array $rejections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rejections = [];

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(SuggestionRejectedEvent::class, function (SuggestionRejectedEvent $event): void {
            $this->rejections[] = $event;
        });
    }

    public function testAManualOverrideClosesThePendingSuggestionOfItsKey(): void
    {
        $this->store(new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.9));
        $this->store(new TranslationSuggestion('app.dashboard', 'messages', 'fr_FR', 'Tableau de bord', 'Dashboard', 'en_US', 'stub', 0.9));

        $this->writer()->save('app.welcome', 'messages', 'fr_FR', 'Bienvenue chez nous');

        self::assertSame(0, $this->pendingCount('app.welcome'), 'The value someone chose supersedes the proposal.');
        self::assertSame(1, $this->pendingCount('app.dashboard'), 'Another key keeps its suggestion.');

        self::assertCount(1, $this->rejections);
        $event = $this->rejections[0];
        self::assertSame(SuggestionRejectedEvent::SUPERSEDED_BY_OVERRIDE, $event->supersededBy);
        self::assertSame('app.welcome', $event->suggestion->getKey());
        self::assertSame(SuggestionStatus::Rejected, $event->suggestion->getStatus());
        // The default NullAuthorProvider leaves the author anonymous → "system".
        self::assertSame('system', $event->suggestion->getReviewedBy());
    }

    /** Left pending, an errored row of a key translated by hand would be billed again by a retry. */
    public function testAnErroredSuggestionIsClosedTooAndNoRetryResendsItsKey(): void
    {
        $this->store(TranslationSuggestion::failed('app.welcome', 'messages', 'fr_FR', 'Welcome to the shop', 'en_US', 'stub', 'boom'));

        $this->writer()->save('app.welcome', 'messages', 'fr_FR', 'Bienvenue chez nous');

        self::assertSame(0, $this->pendingCount('app.welcome'));

        /** @var SuggestionGenerator $generator */
        $generator = self::getContainer()->get(SuggestionGenerator::class);
        self::assertSame([], $generator->collectTexts('messages', 'fr_FR', 'en_US', true, retryErrors: true));
    }

    /**
     * The files filled the key a "missing keys" run failed on: the estimate counts nothing
     * and closes nothing, the retry closes the row without calling the provider (the test
     * HTTP client has no response queued: a call would fail loudly).
     */
    public function testARetryClosesTheErroredRowOfAKeyTheFilesFilledSince(): void
    {
        $errored = TranslationSuggestion::failed('app.dashboard', 'messages', 'fr', 'Dashboard', 'en', 'gpt', 'quota exceeded');
        $errored->setMetadata(['only_missing' => true]);
        $this->store($errored);

        /** @var SuggestionGenerator $generator */
        $generator = self::getContainer()->get(SuggestionGenerator::class);

        self::assertSame([], $generator->collectTexts('messages', 'fr', 'en', true, retryErrors: true));
        self::assertSame(1, $this->pendingCount('app.dashboard'), 'An estimate closes nothing.');

        self::assertSame(0, $generator->generateSuggestions('messages', 'fr', 'en', retryErrors: true)->created);
        self::assertSame(0, $this->pendingCount('app.dashboard'));
        self::assertCount(1, $this->rejections);
        self::assertSame(SuggestionRejectedEvent::SUPERSEDED_BY_FILE, $this->rejections[0]->supersededBy);
    }

    public function testOnlyTheSuggestionsOfTheSameCatalogueLocaleAndScopeAreClosed(): void
    {
        $this->store(new TranslationSuggestion('app.welcome', 'shop/Product/messages', 'fr_FR', 'Bienvenue', 'Welcome', 'en_US', 'stub', 0.9));
        $this->store(new TranslationSuggestion('app.welcome', 'messages', 'de_DE', 'Willkommen', 'Welcome', 'en_US', 'stub', 0.9));
        $this->store(new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome', 'en_US', 'stub', 0.9, scope: 'b2b'));

        $this->writer()->save('app.welcome', 'messages', 'fr_FR', 'Bienvenue chez nous');

        self::assertSame(3, $this->pendingCount('app.welcome'));
        self::assertSame([], $this->rejections);
    }

    /** The import and the host's batch writes go through saveMany(): every key it sets is closed. */
    public function testABatchWriteClosesThePendingSuggestionsOfEveryKeyItSets(): void
    {
        $this->store(new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.9));
        $this->store(new TranslationSuggestion('app.dashboard', 'messages', 'fr_FR', 'Tableau de bord', 'Dashboard', 'en_US', 'stub', 0.9, scope: 'b2b'));
        $this->store(new TranslationSuggestion('app.other', 'messages', 'fr_FR', 'Autre', 'Other', 'en_US', 'stub', 0.9));

        $this->writer()->saveMany([
            ['key' => 'app.welcome', 'catalogue' => 'messages', 'locale' => 'fr_FR', 'value' => 'Bienvenue chez nous'],
            ['key' => 'app.dashboard', 'catalogue' => 'messages', 'locale' => 'fr_FR', 'value' => 'Accueil', 'scope' => 'b2b'],
        ]);

        self::assertSame(0, $this->pendingCount('app.welcome'));
        self::assertSame(0, $this->pendingCount('app.dashboard'));
        self::assertSame(1, $this->pendingCount('app.other'));
        self::assertCount(2, $this->rejections);
    }

    /** The approval writes through the same writer: the suggestion it approves is the source of the value. */
    public function testApprovingASuggestionStillApprovesIt(): void
    {
        $suggestion = $this->store(new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.9));

        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);
        $reviewer->approve($suggestion, 'Bienvenue à vous');

        self::assertTrue($suggestion->isApproved());
        self::assertSame(1, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE translation_key = 'app.welcome' AND status = 'approved'"));
        self::assertSame('Bienvenue à vous', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'fr_FR'));
        self::assertSame([], $this->rejections);
    }

    public function testApprovingInBatchStillApprovesEverySuggestion(): void
    {
        $welcome = $this->store(new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.9));
        $dashboard = $this->store(new TranslationSuggestion('app.dashboard', 'messages', 'fr_FR', 'Tableau de bord', 'Dashboard', 'en_US', 'stub', 0.9));

        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);

        self::assertSame([], $reviewer->approveMany([$welcome, $dashboard]));
        self::assertSame(2, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE status = 'approved'"));
        self::assertSame([], $this->rejections);
    }

    /** An approved row is the audit trail of its override: a later edit of the value leaves it. */
    public function testALaterManualOverrideLeavesAnApprovedSuggestionAlone(): void
    {
        $suggestion = $this->store(new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.9));

        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);
        $reviewer->approve($suggestion);

        $this->writer()->save('app.welcome', 'messages', 'fr_FR', 'Bienvenue chez nous');

        self::assertSame(1, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE translation_key = 'app.welcome' AND status = 'approved'"));
        self::assertSame([], $this->rejections);
    }

    /**
     * The entity in hand says pending, but another process approved the row since: the
     * write reads the status in the database, row locked, and leaves the approval's audit
     * trail alone.
     */
    public function testASuggestionApprovedMeanwhileIsNeitherDeletedNorRejected(): void
    {
        $suggestion = $this->store(new TranslationSuggestion('app.welcome', 'messages', 'fr_FR', 'Bienvenue', 'Welcome to the shop', 'en_US', 'stub', 0.9));
        $this->entityManager()->getConnection()->executeStatement("UPDATE cyllene_translation_suggestion SET status = 'approved', pending_key = NULL");

        $this->writer()->save('app.welcome', 'messages', 'fr_FR', 'Bienvenue chez nous');

        self::assertTrue($suggestion->isPending(), 'The stale entity is not taken for the truth.');
        self::assertSame(1, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE translation_key = 'app.welcome' AND status = 'approved'"));
        self::assertSame([], $this->rejections);
    }

    private function store(TranslationSuggestion $suggestion): TranslationSuggestion
    {
        $this->entityManager()->persist($suggestion);
        $this->entityManager()->flush();

        return $suggestion;
    }

    private function pendingCount(string $key): int
    {
        return $this->fetchInt(\sprintf("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE translation_key = '%s' AND status = 'pending'", $key));
    }
}
