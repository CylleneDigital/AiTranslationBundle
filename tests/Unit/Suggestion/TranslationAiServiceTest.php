<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Suggestion;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Entity\GenerationLog;
use CylleneDigital\AiTranslationBundle\Entity\ScopeParameters;
use CylleneDigital\AiTranslationBundle\Entity\SuggestionStatus;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Event\OverrideSavedEvent;
use CylleneDigital\AiTranslationBundle\Event\SuggestionApprovedEvent;
use CylleneDigital\AiTranslationBundle\Event\SuggestionRejectedEvent;
use CylleneDigital\AiTranslationBundle\Override\AuthorProviderInterface;
use CylleneDigital\AiTranslationBundle\Override\InvalidOverrideException;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueInvalidException;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use CylleneDigital\AiTranslationBundle\Provider\ContextAwareProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use CylleneDigital\AiTranslationBundle\Provider\TranslationResult;
use CylleneDigital\AiTranslationBundle\Repository\GenerationLogRepository;
use CylleneDigital\AiTranslationBundle\Repository\ScopeParametersRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeParametersManager;
use CylleneDigital\AiTranslationBundle\Suggestion\GenerationLock;
use CylleneDigital\AiTranslationBundle\Suggestion\GenerationOutcome;
use CylleneDigital\AiTranslationBundle\Suggestion\PlaceholderConsistencyChecker;
use CylleneDigital\AiTranslationBundle\Suggestion\PlaceholderMismatchException;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionGenerator;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionReviewer;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionValueMissingException;
use CylleneDigital\AiTranslationBundle\Suggestion\TranslationAiService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[AllowMockObjectsWithoutExpectations]
final class TranslationAiServiceTest extends TestCase
{
    /** @var list<string> every temp dir createService() made — a test may call it twice */
    private array $translationsDirs = [];

    private TranslationOverrideRepository&MockObject $overrideRepository;

    private TranslationSuggestionRepository&MockObject $suggestionRepository;

    private GenerationLogRepository&MockObject $generationLogRepository;

    /** @var list<GenerationLog> */
    private array $recordedLogs = [];

    private AuthorProviderInterface&Stub $author;

    private EventDispatcher $eventDispatcher;

    /** @var list<object> */
    private array $dispatchedEvents = [];

    /**
     * What the repositories answer, read at call time: a mock keeps the FIRST stub that
     * matches, so re-stubbing in createService() would silently be ignored by a test
     * calling it twice.
     *
     * @var list<TranslationSuggestion>
     */
    private array $pending = [];

    /** @var (\Closure(string, string, string): list<TranslationOverride>)|null */
    private ?\Closure $overrides = null;

    /** @var list<array<array-key, string>> the chunks the stub provider received (numeric keys become ints) */
    private array $providerCalls = [];

    /** @var list<?string> the additional context carried by the provider at each translate() call */
    private array $providerContexts = [];

    protected function setUp(): void
    {
        $this->overrideRepository = $this->createMock(TranslationOverrideRepository::class);
        $this->suggestionRepository = $this->createMock(TranslationSuggestionRepository::class);
        // The database side of a review, as a real repository behaves on fresh rows: the
        // transaction runs its operation, every suggestion is still pending.
        $this->suggestionRepository->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $this->suggestionRepository->method('lockStillPending')->willReturnArgument(0);
        $this->suggestionRepository->method('findPending')->willReturnCallback(fn (): array => $this->pending);
        $this->overrideRepository->method('findByLocaleAndCatalogue')->willReturnCallback(fn (string $locale, string $catalogue, string $scope = ''): array => null !== $this->overrides ? ($this->overrides)($locale, $catalogue, $scope) : []);
        // The chain read behind the inherited values, served from the same per-test rows.
        $this->overrideRepository->method('findInheritable')->willReturnCallback(function (array $locales, string $scope, ?string $catalogue = null): array {
            $rows = [];
            $overrides = $this->overrides;

            if (null === $overrides || null === $catalogue) {
                return [];
            }

            foreach (array_filter($locales, is_string(...)) as $locale) {
                foreach (array_unique(['', $scope]) as $candidateScope) {
                    foreach ($overrides($locale, $catalogue, $candidateScope) as $override) {
                        $rows[] = ['key' => $override->getKey(), 'catalogue' => $override->getCatalogue(), 'locale' => $override->getLocale(), 'scope' => $override->getScope(), 'value' => (string) $override->getValue()];
                    }
                }
            }

            return $rows;
        });
        $this->author = $this->createStub(AuthorProviderInterface::class);
        $this->author->method('getAuthorIdentifier')->willReturn(null);

        $this->recordedLogs = [];
        $this->generationLogRepository = $this->createMock(GenerationLogRepository::class);
        $this->generationLogRepository
            ->method('record')
            ->willReturnCallback(function (GenerationLog $log): void {
                $this->recordedLogs[] = $log;
            });

        $this->dispatchedEvents = [];
        $this->providerCalls = [];
        $this->providerContexts = [];
        $this->eventDispatcher = new EventDispatcher();
        foreach ([OverrideSavedEvent::class, SuggestionApprovedEvent::class, SuggestionRejectedEvent::class] as $eventClass) {
            $this->eventDispatcher->addListener($eventClass, function (object $event): void {
                $this->dispatchedEvents[] = $event;
            });
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->translationsDirs);
        $this->translationsDirs = [];
    }

    public function testGeneratesSuggestionsOnlyForMissingKeysAndSkipsPendingOnes(): void
    {
        // fr_FR is complete; en_US only has "a". "b" already has a pending suggestion.
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta', 'd' => 'Delta'],
            enMessages: ['a' => 'Existing A'],
            pending: [new TranslationSuggestion('b', 'messages', 'en_US', 'x', 'Beta', 'fr_FR', 'stub', 0.9)],
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        $created = $service->generateSuggestions('messages', 'en_US', 'fr_FR')->created;

        self::assertSame(1, $created);
        self::assertCount(1, $saved);
        self::assertSame('d', $saved[0]->getKey());
        self::assertSame('T-Delta', $saved[0]->getSuggestedValue());
        self::assertSame('Delta', $saved[0]->getSourceValue());
        self::assertSame('fr_FR', $saved[0]->getSourceLocale());
        self::assertSame('en_US', $saved[0]->getLocale());
        self::assertSame('stub', $saved[0]->getProvider());
        self::assertTrue($saved[0]->isPending());
    }

    public function testBatchesAreCappedByItemCountAndByCumulativeLength(): void
    {
        // 25 short keys → 20 + 5. Then 3 long keys (~3000 chars) → 2 + 1.
        $short = [];
        for ($i = 1; $i <= 25; ++$i) {
            $short['short.'.$i] = 'value '.$i;
        }

        $service = $this->createService(frMessages: $short, enMessages: [], pending: []);
        $service->generateSuggestions('messages', 'en_US', 'fr_FR');

        self::assertSame([20, 5], array_map(count(...), $this->providerCalls));

        $this->providerCalls = [];
        $long = [
            'long.1' => str_repeat('a', 3000),
            'long.2' => str_repeat('b', 3000),
            'long.3' => str_repeat('c', 3000),
        ];

        $service = $this->createService(frMessages: $long, enMessages: [], pending: []);
        $service->generateSuggestions('messages', 'en_US', 'fr_FR');

        self::assertSame([2, 1], array_map(count(...), $this->providerCalls));
    }

    public function testASuccessfulGenerationIsRecordedInTheJournal(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: [],
            pending: [],
        );

        $service->generateSuggestions('messages', 'en_US', 'fr_FR');

        self::assertCount(1, $this->recordedLogs);
        $log = $this->recordedLogs[0];
        self::assertFalse($log->isFailure());
        self::assertSame('messages', $log->getCatalogue());
        self::assertSame('en_US', $log->getTargetLocale());
        self::assertSame('fr_FR', $log->getSourceLocale());
        self::assertSame('stub', $log->getProvider());
        self::assertSame(2, $log->getSuggestionsCreated());
        self::assertNull($log->getErrorMessage());
    }

    public function testAProviderFailureIsRecordedInTheJournalAndRethrown(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha'],
            enMessages: [],
            pending: [],
            providerFailure: TranslationProviderException::requestFailed('stub', 'quota exceeded'),
        );

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR');
            self::fail('The provider exception must be rethrown.');
        } catch (TranslationProviderException) {
        }

        self::assertCount(1, $this->recordedLogs);
        $log = $this->recordedLogs[0];
        self::assertTrue($log->isFailure());
        self::assertStringContainsString('quota exceeded', (string) $log->getErrorMessage());
        self::assertSame(0, $log->getSuggestionsCreated());
    }

    public function testAProviderFailureStoresAnErroredSuggestionPerUnfulfilledKey(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: [],
            pending: [],
            providerFailure: TranslationProviderException::requestFailed('stub', 'quota exceeded'),
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR');
            self::fail('The provider exception must be rethrown.');
        } catch (TranslationProviderException) {
        }

        self::assertCount(2, $saved);
        self::assertSame(['a', 'b'], array_map(static fn (TranslationSuggestion $s): string => $s->getKey(), $saved));

        foreach ($saved as $suggestion) {
            self::assertNull($suggestion->getSuggestedValue());
            self::assertSame(0.0, $suggestion->getConfidence());
            self::assertSame('stub', $suggestion->getProvider());
            self::assertTrue($suggestion->hasGenerationError());
            self::assertStringContainsString('quota exceeded', (string) $suggestion->getGenerationError());
            self::assertTrue($suggestion->isPending());
            self::assertSame(['only_missing' => true], $suggestion->getMetadata(), 'The mode of the run that failed, for the retry.');
        }

        self::assertSame('Alpha', $saved[0]->getSourceValue());
    }

    public function testAFailedEveryKeyRunRecordsItsModeOnTheErroredRows(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha'],
            enMessages: ['a' => 'Existing A'],
            pending: [],
            providerFailure: TranslationProviderException::requestFailed('stub', 'quota exceeded'),
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR', onlyMissing: false);
            self::fail('The provider exception must be rethrown.');
        } catch (TranslationProviderException) {
        }

        self::assertCount(1, $saved);
        self::assertSame(['only_missing' => false], $saved[0]->getMetadata());
    }

    /**
     * PHP turns a numeric key ("404", "0") into an int array key: under strict_types
     * it must not reach the string-typed writer and entity as one.
     */
    public function testNumericKeysAreGeneratedLikeAnyOtherKey(): void
    {
        $service = $this->createService(
            // @phpstan-ignore argument.type (PHP turns the numeric-string keys into ints: the very case under test)
            frMessages: ['404' => 'Page introuvable', '0' => 'Zéro'],
            enMessages: [],
            pending: [],
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        self::assertSame(2, $service->generateSuggestions('messages', 'en_US', 'fr_FR')->created);
        self::assertEqualsCanonicalizing(['404', '0'], array_map(static fn (TranslationSuggestion $s): string => $s->getKey(), $saved));
    }

    public function testNumericKeysBecomeErroredRowsOnAProviderFailure(): void
    {
        $service = $this->createService(
            // @phpstan-ignore argument.type (PHP turns the numeric-string keys into ints: the very case under test)
            frMessages: ['404' => 'Page introuvable'],
            enMessages: [],
            pending: [],
            providerFailure: TranslationProviderException::requestFailed('stub', 'quota exceeded'),
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR');
            self::fail('The provider exception must be rethrown.');
        } catch (TranslationProviderException) {
        }

        self::assertCount(1, $saved);
        self::assertSame('404', $saved[0]->getKey());
        self::assertTrue($saved[0]->hasGenerationError());
    }

    /**
     * An unusable reply loses its own batch only: the next batches are still sent and
     * stored, and the run still ends as a failure for the caller and the journal.
     */
    public function testAnInvalidReplyOnlyFailsItsOwnBatch(): void
    {
        $messages = [];
        for ($i = 0; $i < 25; ++$i) {
            $messages[\sprintf('k%02d', $i)] = \sprintf('V%02d', $i);
        }

        $service = $this->createService(
            frMessages: $messages,
            enMessages: [],
            pending: [],
            // The first batch (20 keys) gets a malformed reply.
            translator: static fn (string $text): string => $text < 'V20' ? throw TranslationProviderException::invalidResponse('stub', 'not JSON') : 'T-'.$text,
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[$suggestion->getKey()] = $suggestion;
            });

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR');
            self::fail('The invalid reply must still fail the run.');
        } catch (TranslationProviderException $e) {
            self::assertTrue($e->isInvalidResponse());
        }

        self::assertCount(2, $this->providerCalls);
        self::assertCount(25, $saved);
        self::assertTrue($saved['k00']->hasGenerationError());
        self::assertFalse($saved['k20']->hasGenerationError());
        self::assertSame('T-V24', $saved['k24']->getSuggestedValue());
    }

    /**
     * A key the reply left out was billed all the same: it must surface as an errored row
     * and the run as a failure — not as a "success" whose gap the next run re-bills.
     */
    public function testAKeyLeftOutOfTheReplyBecomesAnErroredRow(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: [],
            pending: [],
            translator: static fn (string $text): string => 'Beta' === $text ? '' : 'T-'.$text,
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[$suggestion->getKey()] = $suggestion;
            });

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR');
            self::fail('A partial reply must fail the run.');
        } catch (TranslationProviderException $e) {
            self::assertStringContainsString('left out 1 of the 2', $e->getMessage());
        }

        self::assertSame('T-Alpha', $saved['a']->getSuggestedValue());
        self::assertTrue($saved['b']->hasGenerationError());
        self::assertTrue($this->recordedLogs[0]->isFailure());
    }

    public function testAReplyWithNoneOfTheKeysSaysSo(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: [],
            pending: [],
            translator: static fn (string $text): string => '',
        );

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR');
            self::fail('An empty reply must fail the run.');
        } catch (TranslationProviderException $e) {
            self::assertStringContainsString('the reply held none of the requested keys', $e->getMessage());
        }
    }

    /** The runaway-cost guard rail: whatever the catalogue size, one run bills at most the cap. */
    public function testARunStopsAtThePerRunKeyCap(): void
    {
        $messages = [];
        for ($i = 0; $i <= SuggestionGenerator::MAX_KEYS_PER_RUN; ++$i) {
            $messages['key.'.$i] = 'Value '.$i;
        }

        $service = $this->createService(frMessages: $messages, enMessages: [], pending: []);

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion->getKey();
            });

        self::assertSame(SuggestionGenerator::MAX_KEYS_PER_RUN, $service->generateSuggestions('messages', 'en_US', 'fr_FR')->created);
        self::assertCount(SuggestionGenerator::MAX_KEYS_PER_RUN, $saved);
        self::assertCount(1, array_diff(array_keys($messages), $saved), 'The key beyond the cap is left for a later run.');
    }

    /** A key the override table cannot store could never be approved: it is not paid for. */
    public function testAKeyTooLongForTheOverrideTableIsLeftOutOfTheRun(): void
    {
        $tooLong = str_repeat('k', TranslationOverride::MAX_KEY_LENGTH + 1);
        $sent = [];

        $service = $this->createService(
            frMessages: [$tooLong => 'Long', 'short' => 'Short'],
            enMessages: [],
            pending: [],
            translator: static function (string $text) use (&$sent): string {
                $sent[] = $text;

                return 'T-'.$text;
            },
        );

        self::assertSame(1, $service->generateSuggestions('messages', 'en_US', 'fr_FR')->created);
        self::assertSame(['Short'], $sent);
    }

    /**
     * The translator renders "" as a blank label, and the coverage report counts it as
     * missing: the generation has to be able to fill it, or the coverage gate never passes.
     */
    public function testAnEmptyTargetValueCountsAsMissing(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: ['a' => '', 'b' => 'Existing B'],
            pending: [],
        );

        self::assertSame(1, $service->generateSuggestions('messages', 'en_US', 'fr_FR')->created);
        self::assertSame([['a' => 'Alpha']], $this->providerCalls);
    }

    /** The effective value is the override's, even an empty one: it hides the file value. */
    public function testAnEmptyOverrideCountsAsMissing(): void
    {
        $emptyB = (new TranslationOverride('b', 'messages', 'en_US'))->setValue('');

        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: ['a' => 'Existing A', 'b' => 'Existing B'],
            pending: [],
            overrides: static fn (string $locale): array => 'en_US' === $locale ? [$emptyB] : [],
        );

        self::assertSame(1, $service->generateSuggestions('messages', 'en_US', 'fr_FR')->created);
        self::assertSame([['b' => 'Beta']], $this->providerCalls);
    }

    /** Through the PHP API too — the path a host integration calls — nothing is sent for a malformed locale. */
    public function testAMalformedTargetLocaleIsRefusedBeforeCallingTheProvider(): void
    {
        $service = $this->createService(frMessages: ['a' => 'Alpha'], enMessages: [], pending: []);

        try {
            $service->generateSuggestions('messages', 'en.UTF8', 'fr_FR');
            self::fail('The run was expected to be refused.');
        } catch (InvalidOverrideException $e) {
            self::assertStringContainsString('"en.UTF8" is not a valid locale code', $e->getMessage());
        }

        self::assertSame([], $this->providerCalls);
        self::assertSame([], $this->recordedLogs, 'A run refused before it started is not journaled.');
    }

    public function testErroredKeysAreSkippedByDefaultAndRefilledWithRetryErrors(): void
    {
        $errored = TranslationSuggestion::failed('b', 'messages', 'en_US', 'Beta', 'fr_FR', 'stub', 'boom');
        $makeService = fn (): TranslationAiService => $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: ['a' => 'Existing A'],
            pending: [$errored],
        );

        // Default run: "a" is translated, "b" (errored, still pending) is skipped.
        self::assertSame(0, $makeService()->generateSuggestions('messages', 'en_US', 'fr_FR')->created);
        self::assertSame([], $this->providerCalls);

        // Retry run: "b" is re-sent and its errored row is refilled — not duplicated.
        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        self::assertSame(1, $makeService()->generateSuggestions('messages', 'en_US', 'fr_FR', retryErrors: true)->created);
        self::assertSame([['b' => 'Beta']], $this->providerCalls);
        self::assertSame([$errored], $saved);
        self::assertSame('T-Beta', $errored->getSuggestedValue());
        self::assertSame(0.9, $errored->getConfidence());
        self::assertFalse($errored->hasGenerationError());
    }

    /**
     * A run re-translating every key fails on keys that already have a value: the retry
     * re-sends them anyway — that run asked for those keys whatever their value.
     */
    public function testRetryErrorsReSendsAnErroredKeyThatAlreadyHasATargetValue(): void
    {
        $errored = TranslationSuggestion::failed('b', 'messages', 'en_US', 'Beta', 'fr_FR', 'stub', 'boom');
        $errored->setMetadata(['only_missing' => false]);
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: ['a' => 'Existing A', 'b' => 'Existing B'],
            pending: [$errored],
        );

        self::assertSame(1, $service->generateSuggestions('messages', 'en_US', 'fr_FR', retryErrors: true)->created);
        self::assertSame([['b' => 'Beta']], $this->providerCalls);
        self::assertSame('T-Beta', $errored->getSuggestedValue());
        self::assertFalse($errored->hasGenerationError());
    }

    /**
     * A "missing keys" run failed on a key the files have filled since: the retry does not
     * bill it, and closes its errored row — else it would stay pending for good, and an
     * approval would overwrite the value just delivered.
     */
    public function testRetryErrorsClosesAMissingKeyErrorWhoseKeyWasDeliveredSince(): void
    {
        $errored = TranslationSuggestion::failed('b', 'messages', 'en_US', 'Beta', 'fr_FR', 'stub', 'boom');
        $errored->setMetadata(['only_missing' => true]);
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: ['a' => 'Existing A', 'b' => 'Delivered B'],
            pending: [$errored],
        );

        $this->suggestionRepository->expects(self::once())->method('removeDeferred')->with($errored);

        self::assertSame(0, $service->generateSuggestions('messages', 'en_US', 'fr_FR', retryErrors: true)->created);
        self::assertSame([], $this->providerCalls);

        $rejections = array_values(array_filter($this->dispatchedEvents, static fn (object $event): bool => $event instanceof SuggestionRejectedEvent));
        self::assertCount(1, $rejections);
        self::assertSame(SuggestionRejectedEvent::SUPERSEDED_BY_FILE, $rejections[0]->supersededBy);
        self::assertSame('system', $errored->getReviewedBy());
    }

    /** A row stored before the mode was recorded counts as "missing keys": not billing is the safe side. */
    public function testRetryErrorsTreatsAnErroredRowWithoutModeAsAMissingKeyError(): void
    {
        $errored = TranslationSuggestion::failed('b', 'messages', 'en_US', 'Beta', 'fr_FR', 'stub', 'boom');
        $service = $this->createService(
            frMessages: ['b' => 'Beta'],
            enMessages: ['b' => 'Delivered B'],
            pending: [$errored],
        );

        self::assertSame(0, $service->generateSuggestions('messages', 'en_US', 'fr_FR', retryErrors: true)->created);
        self::assertSame([], $this->providerCalls);
    }

    public function testRetryErrorsReSendsAMissingKeyErrorStillMissing(): void
    {
        $errored = TranslationSuggestion::failed('b', 'messages', 'en_US', 'Beta', 'fr_FR', 'stub', 'boom');
        $errored->setMetadata(['only_missing' => true]);
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta'],
            enMessages: ['a' => 'Existing A'],
            pending: [$errored],
        );

        $this->suggestionRepository->expects(self::never())->method('removeDeferred');

        self::assertSame(1, $service->generateSuggestions('messages', 'en_US', 'fr_FR', retryErrors: true)->created);
        self::assertSame([['b' => 'Beta']], $this->providerCalls);
    }

    /** The retry bills the keys that failed, and nothing else: a key missing since is left for a regular run. */
    public function testRetryErrorsLeavesTheOtherMissingKeysAlone(): void
    {
        $errored = TranslationSuggestion::failed('b', 'messages', 'en_US', 'Beta', 'fr_FR', 'stub', 'boom');
        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'],
            enMessages: ['a' => 'Existing A'],
            pending: [$errored],
        );

        self::assertSame(1, $service->generateSuggestions('messages', 'en_US', 'fr_FR', retryErrors: true)->created);
        self::assertSame([['b' => 'Beta']], $this->providerCalls);
    }

    /**
     * A failure that is not the provider's (a custom provider throwing something else, a
     * listener, a bug) used to escape the journal: paid suggestions stored, no entry, and
     * the keys never sent left with no errored row a retry could find.
     */
    public function testAnUnexpectedFailureMidRunIsJournaledAndLeavesErroredRows(): void
    {
        // 25 keys: a first batch of 20 succeeds, the second one blows up.
        $messages = [];
        for ($i = 1; $i <= 25; ++$i) {
            $messages[\sprintf('k%02d', $i)] = \sprintf('Text %02d', $i);
        }

        $service = $this->createService(
            frMessages: $messages,
            enMessages: [],
            pending: [],
            translator: static fn (string $text): string => 'Text 21' === $text ? throw new \RuntimeException('custom provider bug') : 'T-'.$text,
        );

        $this->suggestionRepository->method('isOpen')->willReturn(true);
        $saved = [];
        $this->suggestionRepository->method('saveDeferred')->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
            $saved[$suggestion->getKey()] = $suggestion;
        });

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR');
            self::fail('The failure must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('custom provider bug', $e->getMessage());
        }

        self::assertCount(1, $this->recordedLogs);
        self::assertTrue($this->recordedLogs[0]->isFailure());
        self::assertSame(20, $this->recordedLogs[0]->getSuggestionsCreated(), 'The paid batch is counted.');
        self::assertStringContainsString('custom provider bug', (string) $this->recordedLogs[0]->getErrorMessage());

        self::assertCount(25, $saved);
        self::assertFalse($saved['k01']->hasGenerationError());
        self::assertTrue($saved['k21']->hasGenerationError(), 'The keys never sent become errored rows a retry can refill.');
        self::assertTrue($saved['k25']->hasGenerationError());
    }

    /** With the entity manager closed (a failed flush), nothing more can be written: the failure goes out as is. */
    public function testAnUnexpectedFailureWithAClosedEntityManagerIsRethrownUntouched(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha'],
            enMessages: [],
            pending: [],
            translator: static fn (): string => throw new \RuntimeException('database gone'),
        );

        $this->suggestionRepository->method('isOpen')->willReturn(false);
        $this->suggestionRepository->expects(self::never())->method('saveDeferred');

        $this->expectExceptionMessage('database gone');

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR');
        } finally {
            self::assertSame([], $this->recordedLogs);
        }
    }

    public function testARepeatedFailureRefreshesTheErroredRowInsteadOfDuplicatingIt(): void
    {
        $errored = TranslationSuggestion::failed('b', 'messages', 'en_US', 'Beta', 'fr_FR', 'stub', 'boom');
        $service = $this->createService(
            frMessages: ['b' => 'Beta'],
            enMessages: [],
            pending: [$errored],
            providerFailure: TranslationProviderException::requestFailed('stub', 'still down'),
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        try {
            $service->generateSuggestions('messages', 'en_US', 'fr_FR', retryErrors: true);
            self::fail('The provider exception must be rethrown.');
        } catch (TranslationProviderException) {
        }

        self::assertSame([$errored], $saved);
        self::assertStringContainsString('still down', (string) $errored->getGenerationError());
    }

    public function testApproveRefusesASuggestionWithoutValue(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);
        $this->overrideRepository->expects(self::never())->method('save');

        $suggestion = TranslationSuggestion::failed('app.hello', 'messages', 'en_US', 'Bonjour', 'fr_FR', 'stub', 'boom');

        $this->expectException(SuggestionValueMissingException::class);

        $service->approve($suggestion);
    }

    public function testApproveAppliesAHandWrittenValueOnAnErroredSuggestion(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);

        $savedOverride = null;
        $this->overrideRepository->method('findOneByKey')->willReturn(null);
        $this->overrideRepository
            ->method('save')
            ->willReturnCallback(static function (TranslationOverride $override) use (&$savedOverride): void {
                $savedOverride = $override;
            });

        $suggestion = TranslationSuggestion::failed('app.hello', 'messages', 'en_US', 'Bonjour', 'fr_FR', 'stub', 'boom');
        $service->approve($suggestion, 'Hello');

        self::assertNotNull($savedOverride);
        self::assertSame('Hello', $savedOverride->getValue());
        self::assertTrue($suggestion->isApproved());
    }

    public function testApproveManySkipsValuelessSuggestions(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);

        $staged = [];
        $this->overrideRepository->method('findOneByKey')->willReturn(null);
        $this->overrideRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationOverride $override) use (&$staged): void {
                $staged[] = $override;
            });

        $failed = TranslationSuggestion::failed('app.b', 'messages', 'en_US', 'B (fr)', 'fr_FR', 'stub', 'boom');
        $skipped = $service->approveMany([
            new TranslationSuggestion('app.a', 'messages', 'en_US', 'A', 'A (fr)', 'fr_FR', 'stub', 0.9),
            $failed,
        ]);

        self::assertSame([$failed], $skipped);
        self::assertCount(1, $staged);
        self::assertSame('A', $staged[0]->getValue());
        self::assertTrue($failed->isPending());
    }

    public function testAPlaceholderMismatchIsRecordedOnTheSuggestionMetadata(): void
    {
        $service = $this->createService(
            frMessages: ['greeting' => 'Bonjour %name%'],
            enMessages: [],
            pending: [],
            // The stub prefixes with "T-", so "%name%" survives — force a broken reply.
            translator: static fn (string $text): string => str_replace('%name%', 'name', 'T-'.$text),
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        $service->generateSuggestions('messages', 'en_US', 'fr_FR');

        self::assertCount(1, $saved);
        self::assertSame(['%name%'], $saved[0]->getMetadata()['placeholder_mismatch'] ?? null);
    }

    public function testApproveStoresAnOverrideMarksTheSuggestionAndDispatchesEvents(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);

        $savedOverride = null;
        $this->overrideRepository->method('findOneByKey')->willReturn(null);
        $this->overrideRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (TranslationOverride $override) use (&$savedOverride): void {
                $savedOverride = $override;
            });

        $this->suggestionRepository->expects(self::once())->method('save');

        $suggestion = new TranslationSuggestion('app.hello', 'messages', 'en_US', 'Hello', 'Bonjour', 'fr_FR', 'stub', 0.95);
        $service->approve($suggestion);

        self::assertNotNull($savedOverride);
        self::assertSame('app.hello', $savedOverride->getKey());
        self::assertSame('Hello', $savedOverride->getValue());
        self::assertTrue($suggestion->isApproved());
        self::assertSame('system', $suggestion->getReviewedBy());

        $eventClasses = array_map(get_class(...), $this->dispatchedEvents);
        self::assertContains(OverrideSavedEvent::class, $eventClasses);
        self::assertContains(SuggestionApprovedEvent::class, $eventClasses);
    }

    public function testApproveWithAnEditedValueAppliesTheEditedValue(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);

        $savedOverride = null;
        $this->overrideRepository->method('findOneByKey')->willReturn(null);
        $this->overrideRepository
            ->method('save')
            ->willReturnCallback(static function (TranslationOverride $override) use (&$savedOverride): void {
                $savedOverride = $override;
            });

        $suggestion = new TranslationSuggestion('app.hello', 'messages', 'en_US', 'Helo', 'Bonjour', 'fr_FR', 'stub', 0.95);
        $service->approve($suggestion, 'Hello');

        self::assertNotNull($savedOverride);
        self::assertSame('Hello', $savedOverride->getValue());
        self::assertTrue($suggestion->getMetadata()['edited_on_approve'] ?? false);
    }

    /** A label concatenated in a template may need its trailing space: an edit keeps it. */
    public function testAnEditedValueKeepsItsSurroundingSpaces(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);

        $savedOverride = null;
        $this->overrideRepository->method('findOneByKey')->willReturn(null);
        $this->overrideRepository
            ->method('save')
            ->willReturnCallback(static function (TranslationOverride $override) use (&$savedOverride): void {
                $savedOverride = $override;
            });

        $suggestion = new TranslationSuggestion('app.total', 'messages', 'en_US', 'Total:', 'Total :', 'fr_FR', 'stub', 0.95);
        $service->approve($suggestion, 'Total: ');

        self::assertNotNull($savedOverride);
        self::assertSame('Total: ', $savedOverride->getValue());
    }

    public function testApproveRefusesAValueWhoseSyntaxDoesNotMatchTheCatalogue(): void
    {
        $service = $this->createService(frMessages: ['product.count' => '{count} pommes'], enMessages: [], pending: []);
        $this->overrideRepository->expects(self::never())->method('save');

        // The fixture catalogue is legacy (no +intl-icu file): an ICU plural must be refused.
        $suggestion = new TranslationSuggestion('product.count', 'messages', 'en_US', '{count, plural, one {# apple} other {# apples}}', '{count} pommes', 'fr_FR', 'stub', 0.9);

        try {
            $service->approve($suggestion);
            self::fail('A syntax-mismatched value must be refused.');
        } catch (TranslationValueInvalidException $e) {
            self::assertSame([TranslationValueValidator::ISSUE_ICU_IN_LEGACY], $e->issues);
        }
    }

    public function testApproveManySkipsSyntaxMismatchedValues(): void
    {
        $service = $this->createService(frMessages: ['product.count' => '{count} pommes'], enMessages: [], pending: []);

        $staged = [];
        $this->overrideRepository->method('findOneByKey')->willReturn(null);
        $this->overrideRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationOverride $override) use (&$staged): void {
                $staged[] = $override;
            });

        $bad = new TranslationSuggestion('product.count', 'messages', 'en_US', '{count, plural, other {# apples}}', '{count} pommes', 'fr_FR', 'stub', 0.9);
        $skipped = $service->approveMany([
            new TranslationSuggestion('app.a', 'messages', 'en_US', 'A', 'A (fr)', 'fr_FR', 'stub', 0.9),
            $bad,
        ]);

        self::assertSame([$bad], $skipped);
        self::assertCount(1, $staged);
    }

    public function testGenerationRecordsSyntaxIssuesInTheSuggestionMetadata(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha'],
            enMessages: [],
            pending: [],
            // The provider answers with an ICU construct on a legacy catalogue.
            translator: static fn (string $text): string => '{count, plural, other {'.$text.'}}',
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        $service->generateSuggestions('messages', 'en_US', 'fr_FR');

        self::assertCount(1, $saved);
        self::assertSame([TranslationValueValidator::ISSUE_ICU_IN_LEGACY], $saved[0]->getMetadata()['syntax_issues'] ?? null);
    }

    public function testApproveRefusesAValueThatLosesPlaceholders(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);
        $this->overrideRepository->expects(self::never())->method('save');

        $suggestion = new TranslationSuggestion('greeting', 'messages', 'en_US', 'Hello name!', 'Bonjour %name% !', 'fr_FR', 'stub', 0.95);

        $this->expectException(PlaceholderMismatchException::class);

        $service->approve($suggestion);
    }

    public function testAScopedRunUsesTheScopeEffectiveValuesAndStampsTheSuggestions(): void
    {
        // fr_FR has a, b, c; the en_US file only has a. A GLOBAL en_US override covers b,
        // which the b2b scope inherits (so b is not missing there). A b2b override rewords
        // c in French: the scoped source wording is what gets sent to the provider.
        $globalB = (new TranslationOverride('b', 'messages', 'en_US'))->setValue('Global B');
        $scopedC = (new TranslationOverride('c', 'messages', 'fr_FR', 'b2b'))->setValue('Gamma B2B');

        $service = $this->createService(
            frMessages: ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'],
            enMessages: ['a' => 'Existing A'],
            pending: [],
            overrides: static fn (string $locale, string $catalogue, string $scope = ''): array => match ([$locale, $scope]) {
                ['en_US', ''] => [$globalB],
                ['fr_FR', 'b2b'] => [$scopedC],
                default => [],
            },
        );

        $saved = [];
        $this->suggestionRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$saved): void {
                $saved[] = $suggestion;
            });

        $created = $service->generateSuggestions('messages', 'en_US', 'fr_FR', scope: 'b2b')->created;

        self::assertSame(1, $created);
        self::assertCount(1, $saved);
        self::assertSame('c', $saved[0]->getKey());
        self::assertSame('Gamma B2B', $saved[0]->getSourceValue());
        self::assertSame('T-Gamma B2B', $saved[0]->getSuggestedValue());
        self::assertSame('b2b', $saved[0]->getScope());
        self::assertSame('b2b', $this->recordedLogs[0]->getScope());
    }

    public function testAScopedRunHandsTheScopePromptContextToTheProvider(): void
    {
        $service = $this->createService(
            frMessages: ['a' => 'Alpha'],
            enMessages: [],
            pending: [],
            scopeContexts: ['b2b' => 'Wholesale buyers, terse wording.'],
        );

        $service->generateSuggestions('messages', 'en_US', 'fr_FR', scope: 'b2b');
        self::assertSame(['Wholesale buyers, terse wording.'], $this->providerContexts);

        // A scope without parameters, and the global run, translate with the bare provider.
        $this->providerContexts = [];
        $service->generateSuggestions('messages', 'en_US', 'fr_FR', scope: 'retail');
        $service->generateSuggestions('messages', 'en_US', 'fr_FR');
        self::assertSame([null, null], $this->providerContexts);
    }

    public function testApproveWritesTheOverrideInTheSuggestionScope(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);

        $savedOverride = null;
        $this->overrideRepository->method('findOneByKey')->willReturn(null);
        $this->overrideRepository
            ->method('save')
            ->willReturnCallback(static function (TranslationOverride $override) use (&$savedOverride): void {
                $savedOverride = $override;
            });

        $suggestion = new TranslationSuggestion('app.hello', 'messages', 'en_US', 'Hello', 'Bonjour', 'fr_FR', 'stub', 0.95, 'b2b');
        $service->approve($suggestion);

        self::assertNotNull($savedOverride);
        self::assertSame('b2b', $savedOverride->getScope());
        self::assertSame('Hello', $savedOverride->getValue());
    }

    public function testApproveManyKeepsEachSuggestionScope(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);

        $staged = [];
        $this->overrideRepository->method('findOneByKey')->willReturn(null);
        $this->overrideRepository
            ->method('saveDeferred')
            ->willReturnCallback(static function (TranslationOverride $override) use (&$staged): void {
                $staged[] = $override;
            });

        $skipped = $service->approveMany([
            new TranslationSuggestion('app.a', 'messages', 'en_US', 'A', 'A (fr)', 'fr_FR', 'stub', 0.9),
            new TranslationSuggestion('app.a', 'messages', 'en_US', 'A for B2B', 'A (fr)', 'fr_FR', 'stub', 0.9, 'b2b'),
        ]);

        self::assertSame([], $skipped);
        // Same key, two scopes: two distinct overrides, not one overwritten twice.
        self::assertSame(['', 'b2b'], array_map(static fn (TranslationOverride $o): string => $o->getScope(), $staged));
        self::assertSame(['A', 'A for B2B'], array_map(static fn (TranslationOverride $o): string => $o->getValue(), $staged));
    }

    public function testRejectDeletesTheSuggestionAfterDispatchingTheEvent(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);

        $suggestion = new TranslationSuggestion('app.hello', 'messages', 'en_US', 'Hello', 'Bonjour', 'fr_FR', 'stub', 0.95);

        $this->suggestionRepository->expects(self::never())->method('save');
        $this->suggestionRepository
            ->expects(self::once())
            ->method('remove')
            ->with($suggestion)
            ->willReturnCallback(function (): void {
                // The listeners already saw the suggestion when the row goes away.
                self::assertContains(SuggestionRejectedEvent::class, array_map(get_class(...), $this->dispatchedEvents));
            });

        $service->reject($suggestion);

        // The entity in hand no longer passes for a pending suggestion.
        self::assertSame(SuggestionStatus::Rejected, $suggestion->getStatus());
    }

    public function testRejectManyDeletesTheWholeSetInOneFlush(): void
    {
        $service = $this->createService(frMessages: [], enMessages: [], pending: []);

        $removed = [];
        $this->suggestionRepository
            ->method('removeDeferred')
            ->willReturnCallback(static function (TranslationSuggestion $suggestion) use (&$removed): void {
                $removed[] = $suggestion->getKey();
            });
        $this->suggestionRepository->expects(self::once())->method('flush');

        $service->rejectMany([
            new TranslationSuggestion('app.a', 'messages', 'en_US', 'A', 'A', 'fr_FR', 'stub', 0.9),
            new TranslationSuggestion('app.b', 'messages', 'en_US', 'B', 'B', 'fr_FR', 'stub', 0.9),
        ]);

        self::assertSame(['app.a', 'app.b'], $removed);
        self::assertCount(2, array_filter($this->dispatchedEvents, static fn (object $e): bool => $e instanceof SuggestionRejectedEvent));
    }

    public function testASecondRunOnTheSameTripleIsSkippedWhileTheFirstHoldsTheLock(): void
    {
        // InMemoryStore stands for whatever store the host configured under
        // framework.lock — the arbitration is the same, only its reach differs.
        $lock = new GenerationLock(new LockFactory(new InMemoryStore()));

        // The nested call is what a concurrent worker (or a second dispatch) does.
        $inner = 'not run';
        $other = $lock->run('messages', 'en_US', '', static function () use ($lock, &$inner): int {
            $inner = $lock->run('messages', 'en_US', '', static fn (): int => 1);

            // A different triple is not blocked by this one.
            return (int) $lock->run('messages', 'de_DE', '', static fn (): int => 1);
        });

        self::assertNull($inner, 'The second run of the same triple must be skipped.');
        self::assertSame(1, $other, 'Another triple must run while the first is held.');
    }

    public function testABlockedRunIsReportedAsSuchAndNotAsNothingToTranslate(): void
    {
        // "0 created" alone cannot tell a caller whether the catalogue is fully
        // translated or whether another run is paying to translate it right now.
        $lock = new GenerationLock(new LockFactory(new InMemoryStore()));
        $service = $this->createService(['app.greeting' => 'Bonjour'], [], [], lock: $lock);

        $outcome = $lock->run('messages', 'en_US', '', static fn (): GenerationOutcome => $service->generateSuggestions('messages', 'en_US', 'fr_FR'));

        self::assertNotNull($outcome);
        self::assertFalse($outcome->completed, 'A run blocked by the lock must not report itself as completed.');
        self::assertSame(0, $outcome->created);
        self::assertSame([], $this->providerCalls, 'A blocked run must not call — nor bill — the provider.');
    }

    public function testACompletedRunWithNothingToDoIsDistinctFromABlockedOne(): void
    {
        // Same zero, opposite meaning: here the run did happen and found nothing.
        $outcome = $this->createService([], [], [])->generateSuggestions('messages', 'en_US', 'fr_FR');

        self::assertTrue($outcome->completed);
        self::assertSame(0, $outcome->created);
    }

    /**
     * @param array<string, string>                                              $frMessages
     * @param array<string, string>                                              $enMessages
     * @param list<TranslationSuggestion>                                        $pending
     * @param (callable(string): string)|null                                    $translator      how the stub provider "translates" a source text
     * @param \Throwable|null                                                    $providerFailure thrown by the stub provider on every translate() call
     * @param (callable(string, string, string): list<TranslationOverride>)|null $overrides       the stored overrides per (locale, catalogue, scope)
     * @param array<string, string>                                              $scopeContexts   scope => prompt context stored for it
     */
    private function createService(array $frMessages, array $enMessages, array $pending, ?callable $translator = null, ?\Throwable $providerFailure = null, ?callable $overrides = null, array $scopeContexts = [], ?GenerationLock $lock = null): TranslationAiService
    {
        $localeProvider = $this->createStub(LocaleProviderInterface::class);
        $localeProvider->method('getAvailableLocales')->willReturn(['fr_FR', 'en_US']);

        // The manager reads real files: materialise the two message sets in a temp dir.
        $translationsDir = $this->translationsDirs[] = sys_get_temp_dir().'/cyllene_ai_service_test_'.bin2hex(random_bytes(4));
        mkdir($translationsDir, 0o777, true);
        file_put_contents($translationsDir.'/messages.fr_FR.json', json_encode($frMessages, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_FORCE_OBJECT));
        file_put_contents($translationsDir.'/messages.en_US.json', json_encode($enMessages, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_FORCE_OBJECT));

        $this->overrides = null !== $overrides ? $overrides(...) : null;
        $this->pending = $pending;

        $catalogues = new CatalogueRegistry(new TranslationFileScanner($translationsDir), $localeProvider);
        $overrideReader = new OverrideReader($this->overrideRepository, $catalogues);
        $writer = new OverrideWriter(
            $this->overrideRepository,
            new TranslationCacheManager(new ArrayAdapter()),
            $this->author,
            $this->eventDispatcher,
            $this->suggestionRepository,
            $localeProvider,
        );

        $translator ??= static fn (string $text): string => 'T-'.$text;
        $recordCall = function (array $texts, ?string $context): void {
            $chunk = [];

            foreach ($texts as $key => $text) {
                self::assertIsString($text);
                $chunk[$key] = $text;
            }

            $this->providerCalls[] = $chunk;
            $this->providerContexts[] = $context;
        };

        $stubProvider = new class($translator, $recordCall, $providerFailure) implements ContextAwareProviderInterface {
            private ?string $context = null;

            /**
             * @param callable(string): string                       $translator
             * @param callable(array<string, string>, ?string): void $recordCall
             */
            public function __construct(
                private $translator,
                private $recordCall,
                private readonly ?\Throwable $failure,
            ) {
            }

            public function getName(): string
            {
                return 'stub';
            }

            public function withAdditionalContext(?string $context): static
            {
                $clone = clone $this;
                $clone->context = $context;

                return $clone;
            }

            public function translate(array $texts, string $sourceLocale, string $targetLocale, string $catalogue): array
            {
                ($this->recordCall)($texts, $this->context);

                if (null !== $this->failure) {
                    throw $this->failure;
                }

                // An empty translation stands for a key the reply left out.
                $results = array_filter(
                    array_map(fn (string $text): TranslationResult => new TranslationResult(($this->translator)($text), 0.9), $texts),
                    static fn (TranslationResult $result): bool => '' !== $result->translation,
                );
                // A key the caller never asked for: must be ignored.
                $results['hallucinated.key'] = new TranslationResult('Bogus');

                return $results;
            }
        };

        $scopeParametersRepository = $this->createStub(ScopeParametersRepository::class);
        $scopeParametersRepository->method('find')->willReturnCallback(
            static fn (string $scope): ?ScopeParameters => isset($scopeContexts[$scope]) ? (new ScopeParameters($scope))->setPromptContext($scopeContexts[$scope]) : null,
        );

        $checker = new PlaceholderConsistencyChecker();
        $validator = new TranslationValueValidator($catalogues);

        // The facade, so this suite keeps covering generation and review together — they
        // share a table, and several tests walk a suggestion from one to the other.
        return new TranslationAiService(
            new SuggestionGenerator(
                new TranslationProviderRegistry([$stubProvider], 'stub'),
                $overrideReader,
                $writer,
                $this->suggestionRepository,
                $this->generationLogRepository,
                $checker,
                new ScopeParametersManager($scopeParametersRepository),
                $validator,
                $lock ?? new GenerationLock(new LockFactory(new InMemoryStore())),
                $this->eventDispatcher,
            ),
            new SuggestionReviewer(
                $this->suggestionRepository,
                $writer,
                $this->author,
                $checker,
                $validator,
                $this->eventDispatcher,
                $overrideReader,
            ),
        );
    }
}
