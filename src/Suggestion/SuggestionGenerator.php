<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use CylleneDigital\AiTranslationBundle\Entity\GenerationLog;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Event\SuggestionRejectedEvent;
use CylleneDigital\AiTranslationBundle\Locale\LocaleFallback;
use CylleneDigital\AiTranslationBundle\Override\InvalidOverrideException;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use CylleneDigital\AiTranslationBundle\Provider\ContextAwareProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\TranslationAiProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use CylleneDigital\AiTranslationBundle\Provider\TranslationResult;
use CylleneDigital\AiTranslationBundle\Repository\GenerationLogRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeParametersManager;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turning catalogue entries into pending suggestions: pick the keys to translate, batch
 * them through a provider, store what comes back.
 *
 * Everything here spends money, which shapes the design. A run is guarded against
 * double-billing on two fronts — the {@see GenerationLock}, so a second worker skips
 * rather than re-calling the paid provider, and a hard cap on the keys one run may
 * handle, so an oversized catalogue cannot fan out into hundreds of billed API calls.
 * Suggestions are flushed batch by batch, so an interrupted run keeps what it paid for, and
 * the keys of a batch are reserved before the provider is called, so a run killed during the
 * call never re-sends them on its own.
 *
 * What happens to a suggestion afterwards belongs to the {@see SuggestionReviewer}.
 */
final class SuggestionGenerator
{
    /** Batch caps sent to the providers: small enough for reliable LLM JSON replies. */
    private const int CHUNK_MAX_ITEMS = 20;

    /** Cumulative source-text length cap per batch (long values would overflow the LLM reply). */
    private const int CHUNK_MAX_CHARS = 6000;

    /** Hard ceiling on keys handled by a single run — a runaway-cost guard rail. */
    public const int MAX_KEYS_PER_RUN = 500;

    /** The metadata key of an errored row: whether the run that failed on it was after the missing keys only. */
    private const string ONLY_MISSING = 'only_missing';

    /** The metadata key of a reserved row while its batch is with the provider: when it was reserved. */
    public const string IN_FLIGHT = 'in_flight';

    /** The error of a reserved row, which it keeps when the run is killed before the reply is stored. */
    public const string INTERRUPTED = 'Interrupted before the reply was stored: the run stopped while the provider was called.';

    private const string IN_FLIGHT_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private readonly TranslationProviderRegistry $registry,
        private readonly OverrideReader $overrides,
        private readonly OverrideWriter $writer,
        private readonly TranslationSuggestionRepository $suggestionRepository,
        private readonly GenerationLogRepository $generationLogRepository,
        private readonly PlaceholderConsistencyChecker $placeholderChecker,
        private readonly ScopeParametersManager $scopeParameters,
        private readonly TranslationValueValidator $valueValidator,
        private readonly GenerationLock $lock,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ScopeRegistry $scopes,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Generates pending suggestions for one catalogue/locale pair. The returned
     * {@see GenerationOutcome} says whether the run happened at all — another run holding
     * the lock for the same triple skips this one — and how many suggestions it created:
     * a bare count could not tell "nothing to translate" from "nothing was run".
     *
     * `$onlyMissing` (the default) restricts the work to keys that exist
     * in the source catalogue but have no effective value in the target locale — an
     * empty one included, as in the coverage report; keys with a suggestion already
     * pending are always skipped.
     *
     * `$scope` ('' = global) is the override scope the run generates FOR: source and
     * target values are the scope's effective ones (scoped override, else global
     * override, else file), the suggestions carry the scope, and approving them writes
     * scoped overrides. A key missing globally is missing in every scope too, so a
     * scoped run fills that scope only — the global value stays missing by design.
     *
     * On a provider failure, every planned-but-unfulfilled key is stored as an ERRORED
     * pending suggestion (no value, confidence 0, the error message on the row): the
     * reviewer sees the failure per key and can translate by hand. Those rows are
     * skipped by later runs like any pending suggestion, unless `$retryErrors` re-sends
     * them — success then refills the very same rows. A retry is a key set of its own:
     * the errored keys and no other (`$onlyMissing` does not apply), each treated the way
     * the run that failed on it would have: one from an "every key" run is re-sent as it
     * is; one from a "missing keys" run only while the key is still missing — a key that
     * has a value since (the files were updated) is not billed again, and its errored
     * row is closed, rejected as superseded by the file.
     *
     * Every run — success, provider failure or any other failure midway — is recorded in
     * the generation journal, unless a failed flush closed the entity manager.
     *
     * The keys of a batch are stored as errored rows marked in flight before the provider
     * is called, and filled from its reply: a run killed during the call (a worker's OOM,
     * a SIGKILL at a deployment) leaves them errored, and the run that starts again — a
     * redelivered message, a relaunched cron — skips them like any pending suggestion
     * instead of billing them twice. `$requestedAt` is when the run was asked for (the
     * message's dispatch time, null = now): a retry leaves out the rows reserved after it,
     * the ones its own run reserved before being killed.
     */
    public function generateSuggestions(
        string $catalogue,
        string $targetLocale,
        string $sourceLocale,
        ?string $providerName = null,
        bool $onlyMissing = true,
        string $scope = '',
        bool $retryErrors = false,
        ?\DateTimeImmutable $requestedAt = null,
    ): GenerationOutcome {
        // The per-chunk flush only keeps what was paid for if it commits. Inside the
        // caller's transaction (Messenger's doctrine_transaction middleware — the default
        // bus of Sylius has it — or a wrapInTransaction()), one failing chunk rolls back
        // every stored suggestion and the journal entry, and replaying the failed message
        // bills the whole run again. Refused before the provider is ever called.
        if ($this->suggestionRepository->isTransactionActive()) {
            throw new \LogicException('AI translation generation cannot run inside a database transaction: a rollback would discard suggestions the provider has already billed. Dispatch GenerateSuggestionsMessage on a bus without the "doctrine_transaction" middleware.');
        }

        // Symfony locales use "_": "pt-BR" — a message dispatched with the locale a URL or a
        // translation tool writes — is the "pt_BR" of the scanned files, and of the overrides.
        $targetLocale = str_replace('-', '_', $targetLocale);
        $sourceLocale = str_replace('-', '_', $sourceLocale);

        // Refused before the provider is ever called: a target the tables cannot hold would
        // fail the first flush after the provider billed the call, and one no override can
        // carry (a malformed locale) would store paid suggestions no approval can apply.
        // The writer's own rule, so the run refuses exactly what the approval would.
        $this->writer->assertStorable($catalogue, $targetLocale, $scope);

        // The source locale is never an override, but it is stored on every suggestion and
        // journal entry — and the same-language fallback finds texts for "en_<anything>".
        if (\strlen($sourceLocale) > TranslationOverride::MAX_LOCALE_LENGTH || 1 !== preg_match(LocaleFallback::PATTERN, $sourceLocale)) {
            throw new InvalidOverrideException(\sprintf('"%s" is not a valid source locale code.', mb_substr($sourceLocale, 0, 60)));
        }

        // A scope the host no longer declares — a channel deleted while its message waited
        // in the queue: its rows are purged, or about to be, and nobody would review what
        // the run bills. Refused before the provider is called. A host that declares no
        // scopes accepts every code.
        if (!$this->scopes->isKnownScope($scope)) {
            throw new InvalidOverrideException(\sprintf('The scope "%s" is not declared by the host: the run is refused before the provider is called.', mb_substr($scope, 0, 60)));
        }

        // null = another run already holds the lock for this triple: skip rather than
        // pay the provider twice for the same keys.
        $requestedAt ??= new \DateTimeImmutable();

        return $this->lock->run($catalogue, $targetLocale, $scope, function (callable $keepAlive) use ($catalogue, $targetLocale, $sourceLocale, $providerName, $onlyMissing, $scope, $retryErrors, $requestedAt): GenerationOutcome {
            $provider = $this->resolveProvider($providerName, $scope);
            $pending = $this->suggestionRepository->findPending($targetLocale, $catalogue, $scope);
            ['texts' => $texts, 'delivered' => $delivered] = $this->selectTexts($catalogue, $targetLocale, $sourceLocale, $onlyMissing, $scope, $retryErrors, $pending, $requestedAt);

            if ([] !== $delivered) {
                $this->closeDelivered($delivered);
            }

            // The keys whose previous run failed: success refills their rows (and a new
            // failure refreshes them) instead of duplicating them.
            $errored = [];

            foreach ($pending as $suggestion) {
                if ($suggestion->hasGenerationError()) {
                    $errored[$suggestion->getKey()] = $suggestion;
                }
            }

            if (\count($texts) > self::MAX_KEYS_PER_RUN) {
                $this->logger->warning('AI translation run capped at the per-run key limit — the rest is left for a later run.', [
                    'catalogue' => $catalogue,
                    'target_locale' => $targetLocale,
                    'requested' => \count($texts),
                    'cap' => self::MAX_KEYS_PER_RUN,
                ]);
                $texts = \array_slice($texts, 0, self::MAX_KEYS_PER_RUN, true);
            }

            $created = 0;
            $done = [];
            $invalidReply = null;
            $scopeGone = false;

            // The rows this run creates are let go once it is over: in a long process (the
            // console walking every catalogue, a worker) they would otherwise stay managed
            // and weigh on every later flush. Rows loaded from the database are left alone —
            // the caller may hold them.
            $new = [];

            try {
                try {
                    $selectedNow = true;

                    foreach ($this->chunkTexts($texts) as $chunk) {
                        // The scope was checked when the run started: one deleted and purged
                        // while the earlier batches were with the provider would have every
                        // next batch reserved and billed for nobody. Checked again before
                        // every batch but the first; the run stops there, before reserving.
                        if (!$selectedNow && !$this->scopes->isKnownScope($scope)) {
                            $scopeGone = true;

                            break;
                        }

                        // The selection was made when the run started: a key given a value
                        // since — an override written while the earlier batches were with the
                        // provider — is nobody's request any more. Read again before every
                        // batch but the first, which the selection has just read. Not on a
                        // retry: an override written for an errored key closes its row, and
                        // reserve() leaves out a row that is no longer pending.
                        if ($onlyMissing && !$retryErrors && !$selectedNow) {
                            $chunk = $this->leaveOutDelivered($chunk, $done, $catalogue, $targetLocale, $scope);
                        }
                        $selectedNow = false;

                        if ([] === $chunk) {
                            continue;
                        }

                        $chunk = $this->reserve($chunk, $done, $new, $errored, $catalogue, $targetLocale, $sourceLocale, $provider->getName(), $scope, $onlyMissing);

                        if ([] === $chunk) {
                            continue;
                        }

                        try {
                            $results = $provider->translate($chunk, $sourceLocale, $targetLocale, $catalogue);
                        } catch (TranslationProviderException $e) {
                            // A request failure (quota, auth, network) would hit every next
                            // call too: the outer catch stops the run. An unusable reply only
                            // loses its own batch — the next ones are still worth sending.
                            if (!$e->isInvalidResponse()) {
                                throw $e;
                            }

                            $this->storeFailures($chunk, $done, $new, $errored, $e->getMessage(), $catalogue, $targetLocale, $sourceLocale, $provider->getName(), $scope, $onlyMissing);
                            $invalidReply = $e;
                            $keepAlive();

                            continue;
                        }

                        // Stored under the rows' locks, re-read: an override written for a key
                        // while its batch was with the provider closed its reserved row, and a
                        // stale entity never decides.
                        $created += $this->suggestionRepository->transactional(function () use ($chunk, $results, &$done, $errored, &$invalidReply, $catalogue, $targetLocale, $provider, $scope): int {
                            return $this->storeReply($chunk, $results, $done, $errored, $invalidReply, $catalogue, $targetLocale, $provider->getName(), $scope);
                        });

                        // A provider call just took however long it took: push the lock's
                        // expiration back so a long run does not lose it halfway through
                        // (a no-op on the non-expiring local stores).
                        $keepAlive();
                    }
                } catch (TranslationProviderException $e) {
                    $this->storeFailures($texts, $done, $new, $errored, $e->getMessage(), $catalogue, $targetLocale, $sourceLocale, $provider->getName(), $scope, $onlyMissing);

                    // $created keeps the suggestions stored before the failing chunk.
                    $this->generationLogRepository->record(GenerationLog::failure($catalogue, $targetLocale, $sourceLocale, $provider->getName(), $e->getMessage(), $created, $scope));

                    throw $e;
                } catch (\Throwable $e) {
                    // Not the provider's failure — a custom provider throwing something else, a
                    // listener, a bug — but the batches before it were paid all the same. The
                    // run is journaled and the keys it never sent become errored rows a retry
                    // can refill, as for a provider failure; then the failure goes on.
                    $this->recordUnexpectedFailure($e, $texts, $done, $new, $errored, $created, $catalogue, $targetLocale, $sourceLocale, $provider->getName(), $scope, $onlyMissing);

                    throw $e;
                }

                // Stopped before a batch: the keys left are not reserved, so no errored row
                // is left in a scope that is gone. Journaled, as every run that started.
                if ($scopeGone) {
                    $error = \sprintf('The scope "%s" is no longer declared by the host: the run stopped before its next batch.', mb_substr($scope, 0, 60));
                    $this->generationLogRepository->record(GenerationLog::failure($catalogue, $targetLocale, $sourceLocale, $provider->getName(), $error, $created, $scope));

                    throw new InvalidOverrideException($error);
                }

                // The run went to the end, but some batches came back unusable: still a
                // failure for the journal and the caller, with every other batch stored.
                if (null !== $invalidReply) {
                    $this->generationLogRepository->record(GenerationLog::failure($catalogue, $targetLocale, $sourceLocale, $provider->getName(), $invalidReply->getMessage(), $created, $scope));

                    throw $invalidReply;
                }

                $this->generationLogRepository->record(GenerationLog::success($catalogue, $targetLocale, $sourceLocale, $provider->getName(), $created, $scope));

                return GenerationOutcome::completed($created);
            } finally {
                $this->suggestionRepository->detach(...$new);
            }
        }) ?? GenerationOutcome::alreadyRunning();
    }

    /**
     * The keys of a batch still missing in the target locale, read again: the effective
     * value the selection uses (the key's own override, else the inherited one, else the
     * file). A key with a value now is left out of the batch, neither reserved nor sent.
     *
     * @param array<string, string> $chunk
     * @param array<string, true>   $done
     *
     * @return array<string, string>
     */
    private function leaveOutDelivered(array $chunk, array &$done, string $catalogue, string $targetLocale, string $scope): array
    {
        $target = $this->overrides->getTranslationsForCatalogue($catalogue, $targetLocale, $scope);

        foreach ($chunk as $key => $sourceValue) {
            $key = (string) $key;

            if ($this->hasTargetValue($target[$key] ?? null)) {
                unset($chunk[$key]);
                $done[$key] = true;
                $this->logLeftOut('AI translation key left out of its batch: it was given a value since the run started.', $catalogue, $targetLocale, $scope, $key);
            }
        }

        return $chunk;
    }

    /**
     * Stores the keys of a batch as errored rows marked in flight, before the provider is
     * called, and returns the keys still to send. A key whose previous run failed has that
     * row reserved instead of a new one, keeping the mode of the run that first failed on
     * it — re-read in the database and locked first: a row someone reviewed since (filled by
     * hand and approved, rejected) is left as it is, and its key is not sent, nor paid for.
     * Flushed: the reservation has to be in the database while the call runs — a kill during
     * the call leaves it there, and an unguarded concurrent run that reserved the same key
     * first fails this flush on the pending unique index before paying for it.
     *
     * @param array<string, string>                   $chunk
     * @param array<string, true>                     $done
     * @param list<TranslationSuggestion>             $new     the rows this run created
     * @param array<array-key, TranslationSuggestion> $errored the errored rows, the reserved ones added
     *
     * @return array<string, string> the keys of the batch still to send
     */
    private function reserve(array $chunk, array &$done, array &$new, array &$errored, string $catalogue, string $targetLocale, string $sourceLocale, string $providerName, string $scope, bool $onlyMissing): array
    {
        return $this->suggestionRepository->transactional(function () use ($chunk, &$done, &$new, &$errored, $catalogue, $targetLocale, $sourceLocale, $providerName, $scope, $onlyMissing): array {
            $stillErrored = $this->lockStillPendingByKey(array_intersect_key($errored, $chunk));
            $reservedAt = (new \DateTimeImmutable())->format(self::IN_FLIGHT_FORMAT);

            foreach ($chunk as $key => $sourceValue) {
                $key = (string) $key;

                if (isset($errored[$key]) && !isset($stillErrored[$key])) {
                    unset($chunk[$key]);
                    $done[$key] = true;
                    $this->logLeftOut('AI translation key left out of its batch: its errored suggestion was reviewed since the run started.', $catalogue, $targetLocale, $scope, $key);

                    continue;
                }

                if (isset($errored[$key])) {
                    $row = $errored[$key];
                    $row->recordGenerationError(self::INTERRUPTED, $providerName, $sourceValue);
                    $row->setMetadata([...$row->getMetadata() ?? [], self::IN_FLIGHT => $reservedAt]);
                } else {
                    $row = $new[] = $errored[$key] = TranslationSuggestion::failed($key, $catalogue, $targetLocale, $sourceValue, $sourceLocale, $providerName, self::INTERRUPTED, $scope);
                    $row->setMetadata([self::ONLY_MISSING => $onlyMissing, self::IN_FLIGHT => $reservedAt]);
                }

                $this->suggestionRepository->saveDeferred($row);
            }

            $this->suggestionRepository->flush();

            return $chunk;
        });
    }

    /**
     * Which of these rows are still pending in the database, locked until the end of the
     * caller's transaction, by key.
     *
     * @param array<array-key, TranslationSuggestion> $rows
     *
     * @return array<string, TranslationSuggestion>
     */
    private function lockStillPendingByKey(array $rows): array
    {
        $pending = [];

        foreach ($this->suggestionRepository->lockStillPending(array_values($rows)) as $row) {
            $pending[$row->getKey()] = $row;
        }

        return $pending;
    }

    private function logLeftOut(string $message, string $catalogue, string $targetLocale, string $scope, string $key): void
    {
        $this->logger->info($message, [
            'catalogue' => $catalogue,
            'target_locale' => $targetLocale,
            'scope' => $scope,
            'key' => $key,
        ]);
    }

    /**
     * Fills the reserved rows of a batch from the provider's reply, inside the caller's
     * transaction: only the rows still pending in the database, locked. A row closed while
     * the batch was with the provider (an override written for its key, a rejection) is
     * left as it is — the person decided — and its paid translation dropped. A key the reply
     * left out was paid for all the same: its row stays errored, with the reason, rather
     * than a silent gap the next run re-bills while the journal says "success".
     *
     * @param array<string, string>                   $chunk
     * @param iterable<array-key, TranslationResult>  $results
     * @param array<string, true>                     $done
     * @param array<array-key, TranslationSuggestion> $errored the errored rows, the batch's reserved ones included
     *
     * @return int the suggestions filled
     */
    private function storeReply(array $chunk, iterable $results, array &$done, array $errored, ?TranslationProviderException &$invalidReply, string $catalogue, string $targetLocale, string $providerName, string $scope): int
    {
        $reserved = $this->lockStillPendingByKey(array_intersect_key($errored, $chunk));

        foreach (array_diff_key($chunk, $reserved) as $key => $sourceValue) {
            $done[(string) $key] = true;
            $this->logLeftOut('AI translation reply dropped: the key was reviewed while its batch was with the provider.', $catalogue, $targetLocale, $scope, (string) $key);
        }

        $created = 0;

        foreach ($results as $key => $result) {
            // PHP turns a numeric key ("404") into an int array key.
            $key = (string) $key;

            if (!isset($reserved[$key]) || isset($done[$key])) {
                continue;
            }

            $suggestion = $reserved[$key];
            $suggestion->fill($result->translation, $result->confidence, $providerName, $chunk[$key]);

            $metadata = $result->metadata;
            $placeholderDiff = $this->placeholderChecker->diff($chunk[$key], $result->translation);
            if ([] !== $placeholderDiff) {
                $metadata['placeholder_mismatch'] = $placeholderDiff;
            }

            // Informational only (the provider is not refused): it is kept in
            // the metadata for the reviewer, approval is where it blocks.
            $syntaxIssues = $this->valueValidator->validate($result->translation, $key, $catalogue, $targetLocale);
            if ([] !== $syntaxIssues) {
                $metadata['syntax_issues'] = $syntaxIssues;
            }
            $suggestion->setMetadata([] !== $metadata ? $metadata : null);

            $this->suggestionRepository->saveDeferred($suggestion);
            $done[$key] = true;
            ++$created;
        }

        // A reply holding none of the keys (a wrapped object, another shape) is an invalid
        // reply outright.
        $missing = array_diff_key($chunk, $done);

        if ([] !== $missing) {
            $invalidReply = TranslationProviderException::invalidResponse($providerName, \count($missing) === \count($chunk)
                ? 'the reply held none of the requested keys'
                : \sprintf('the reply left out %d of the %d requested keys', \count($missing), \count($chunk)));

            foreach ($missing as $key => $sourceValue) {
                $this->recordFailure($reserved[(string) $key], $invalidReply->getMessage(), $providerName, $sourceValue);
                $done[(string) $key] = true;
            }
        }

        // One flush per chunk: the suggestions of every completed chunk are persisted even
        // if a later chunk fails the provider.
        $this->suggestionRepository->flush();

        return $created;
    }

    /**
     * Every key of $texts not yet handled becomes an errored pending row: the failure is
     * visible per key at review time, hand-editable, and a later retry-errors run
     * refills these rows. The keys are marked handled, so a later failure of the same
     * run does not store them twice. A new row records the run's mode
     * (`metadata.only_missing`): the retry treats its key the way that run would have. A
     * row a retry fails on again keeps the mode of the run that first failed on it. A row
     * already stored — reserved, or left errored by a previous run — is only updated while it
     * is still pending in the database.
     *
     * @param array<array-key, string>                $texts
     * @param array<string, true>                     $done
     * @param list<TranslationSuggestion>             $new     the rows this run created
     * @param array<array-key, TranslationSuggestion> $errored the errored rows, the reserved ones included
     */
    private function storeFailures(array $texts, array &$done, array &$new, array $errored, string $error, string $catalogue, string $targetLocale, string $sourceLocale, string $providerName, string $scope, bool $onlyMissing): void
    {
        $texts = array_diff_key($texts, $done);

        if ([] === $texts) {
            return;
        }

        $this->suggestionRepository->transactional(function () use ($texts, &$done, &$new, $errored, $error, $catalogue, $targetLocale, $sourceLocale, $providerName, $scope, $onlyMissing): void {
            // The rows already stored are re-read and locked: one reviewed while the run
            // went on (filled by hand and approved, rejected) is never written over.
            $stillErrored = $this->lockStillPendingByKey(array_intersect_key($errored, $texts));

            foreach ($texts as $key => $sourceValue) {
                $key = (string) $key;
                $done[$key] = true;

                if (isset($stillErrored[$key])) {
                    $this->recordFailure($stillErrored[$key], $error, $providerName, $sourceValue);
                } elseif (isset($errored[$key])) {
                    $this->logLeftOut('AI translation failure not recorded: the errored suggestion was reviewed while the run went on.', $catalogue, $targetLocale, $scope, $key);
                } else {
                    $failed = $new[] = TranslationSuggestion::failed($key, $catalogue, $targetLocale, $sourceValue, $sourceLocale, $providerName, $error, $scope);
                    $failed->setMetadata([self::ONLY_MISSING => $onlyMissing]);
                    $this->suggestionRepository->saveDeferred($failed);
                }
            }

            $this->suggestionRepository->flush();
        });
    }

    /** An errored row that failed (again): its reservation, if any, is over. */
    private function recordFailure(TranslationSuggestion $row, string $error, string $providerName, string $sourceValue): void
    {
        $row->recordGenerationError($error, $providerName, $sourceValue);
        $metadata = $row->getMetadata() ?? [];
        unset($metadata[self::IN_FLIGHT]);
        $row->setMetadata([] !== $metadata ? $metadata : null);
        $this->suggestionRepository->saveDeferred($row);
    }

    /**
     * The journal entry and the errored rows of a run that failed for another reason than
     * the provider. Only while the entity manager is open: a failed flush closes it, and
     * nothing more can be written — the documented case of a run with no journal entry.
     * A failure while recording is logged and left aside: the caller must get the
     * original failure, not a secondary one.
     *
     * @param array<array-key, string>                $texts
     * @param array<string, true>                     $done
     * @param list<TranslationSuggestion>             $new
     * @param array<array-key, TranslationSuggestion> $errored
     */
    private function recordUnexpectedFailure(\Throwable $failure, array $texts, array &$done, array &$new, array $errored, int $created, string $catalogue, string $targetLocale, string $sourceLocale, string $providerName, string $scope, bool $onlyMissing): void
    {
        if (!$this->suggestionRepository->isOpen()) {
            return;
        }

        $error = \sprintf('%s: %s', get_debug_type($failure), $failure->getMessage());

        try {
            $this->storeFailures($texts, $done, $new, $errored, $error, $catalogue, $targetLocale, $sourceLocale, $providerName, $scope, $onlyMissing);
            $this->suggestionRepository->flush();
            $this->generationLogRepository->record(GenerationLog::failure($catalogue, $targetLocale, $sourceLocale, $providerName, $error, $created, $scope));
        } catch (\Throwable $secondary) {
            $this->logger->error('AI translation generation failed, and its failure could not be journaled.', [
                'catalogue' => $catalogue,
                'target_locale' => $targetLocale,
                'scope' => $scope,
                'failure' => $error,
                'journal_error' => $secondary->getMessage(),
            ]);
        }
    }

    /**
     * The provider a run uses, ready for its scope: the configured provider, carrying
     * the scope's prompt context when one is set and the provider can take it
     * ({@see ContextAwareProviderInterface}). Public so the cost estimator prices the
     * very prompts the run would send.
     */
    public function resolveProvider(?string $providerName, string $scope = ''): TranslationAiProviderInterface
    {
        $provider = $this->registry->get($providerName);

        if ('' === $scope || !$provider instanceof ContextAwareProviderInterface) {
            return $provider;
        }

        $context = $this->scopeParameters->getPromptContext($scope);

        return null === $context ? $provider : $provider->withAdditionalContext($context);
    }

    /**
     * The texts a generation run would send to the provider — public so the cost
     * estimator and the dry-run share the exact selection logic of the real run.
     * Values are the scope's effective ones: scoped override, else the inherited global
     * override, else the file value.
     *
     * @return array<string, string> key => source text
     */
    public function collectTexts(string $catalogue, string $targetLocale, string $sourceLocale, bool $onlyMissing, string $scope = '', bool $retryErrors = false): array
    {
        $targetLocale = str_replace('-', '_', $targetLocale);
        $sourceLocale = str_replace('-', '_', $sourceLocale);

        // An estimate closes nothing: the errored rows whose key was delivered since are
        // left for the run itself.
        return $this->selectTexts($catalogue, $targetLocale, $sourceLocale, $onlyMissing, $scope, $retryErrors, $this->suggestionRepository->findPending($targetLocale, $catalogue, $scope), new \DateTimeImmutable())['texts'];
    }

    /**
     * The texts to send, and — on a retry — the errored suggestions of a "missing keys"
     * run whose key has a value since: not sent, closed by the run.
     *
     * @param list<TranslationSuggestion> $pending     the pending suggestions of this (catalogue, locale, scope)
     * @param \DateTimeImmutable          $requestedAt when the run was asked for
     *
     * @return array{texts: array<string, string>, delivered: list<TranslationSuggestion>}
     */
    private function selectTexts(string $catalogue, string $targetLocale, string $sourceLocale, bool $onlyMissing, string $scope, bool $retryErrors, array $pending, \DateTimeImmutable $requestedAt): array
    {
        $source = $this->overrides->getTranslationsForCatalogue($catalogue, $sourceLocale, $scope);
        $target = $this->overrides->getTranslationsForCatalogue($catalogue, $targetLocale, $scope);

        $pendingKeys = [];
        // key => the errored row, whose run was after the missing keys only or not.
        $errored = [];
        foreach ($pending as $suggestion) {
            // With $retryErrors, the keys whose previous run failed re-enter the selection —
            // but not the rows reserved after the retry was asked for: those are the retry's
            // own, reserved before it was killed, and its redelivery must not bill them again.
            if ($retryErrors && $suggestion->hasGenerationError() && !$this->reservedSince($suggestion, $requestedAt)) {
                $errored[$suggestion->getKey()] = $suggestion;

                continue;
            }

            $pendingKeys[$suggestion->getKey()] = true;
        }

        $texts = [];
        $delivered = [];

        foreach ($source as $key => $entry) {
            // PHP turns a numeric key ("404") into an int array key.
            $key = (string) $key;
            $sourceValue = $entry['override'] ?? $entry['inherited'] ?? $entry['original'];

            if (null === $sourceValue || '' === $sourceValue || isset($pendingKeys[$key])) {
                continue;
            }

            // A key too long for the tables could never be stored as a suggestion, let
            // alone approved into an override: left out of the run (and of its bill)
            // rather than failing the INSERT after the provider was paid.
            if (!$this->writer->acceptsKey($key)) {
                $this->logger->warning('Translation key skipped: too long for the override tables.', [
                    'catalogue' => $catalogue,
                    'key_length' => mb_strlen($key),
                    'max_length' => TranslationOverride::MAX_KEY_LENGTH,
                ]);

                continue;
            }

            if ($retryErrors) {
                // Only the keys that failed: a key missing since was not part of the failed
                // run, the retry is not the place to bill it.
                if (!isset($errored[$key])) {
                    continue;
                }

                // Each the way its failed run would have treated it. One of an "every key"
                // run has a target value by definition: the missing-only filter would leave
                // it for good. One of a "missing keys" run with a value since — the files
                // were updated — would be billed for nothing, and its approval would
                // overwrite the value just delivered. A row from before the mode was
                // recorded counts as "missing keys": not billing is the safe side.
                if (false !== ($errored[$key]->getMetadata()[self::ONLY_MISSING] ?? true) && $this->hasTargetValue($target[$key] ?? null)) {
                    $delivered[] = $errored[$key];

                    continue;
                }
            } elseif ($onlyMissing && $this->hasTargetValue($target[$key] ?? null)) {
                continue;
            }

            $texts[$key] = $sourceValue;
        }

        return ['texts' => $texts, 'delivered' => $delivered];
    }

    /** Whether a row is in flight, reserved at or after that moment. */
    private function reservedSince(TranslationSuggestion $suggestion, \DateTimeImmutable $moment): bool
    {
        $reservedAt = $suggestion->getMetadata()[self::IN_FLIGHT] ?? null;
        $reservedAt = \is_string($reservedAt) ? \DateTimeImmutable::createFromFormat(self::IN_FLIGHT_FORMAT, $reservedAt) : false;

        return false !== $reservedAt && $reservedAt >= $moment;
    }

    /**
     * Whether an entry has a value: "" counts as missing, exactly as in the coverage
     * report — the translator renders it as a blank label, and a key the generation never
     * fills would keep the coverage gate failing for good.
     *
     * @param array{original: ?string, override: ?string, inherited: ?string}|null $entry
     */
    private function hasTargetValue(?array $entry): bool
    {
        $value = null !== $entry ? ($entry['override'] ?? $entry['inherited'] ?? $entry['original']) : null;

        return null !== $value && '' !== $value;
    }

    /**
     * Closes the errored suggestions a retry found delivered by the files: rejected as
     * superseded, rows locked and re-read in the database like any rejection, so a row
     * reviewed meanwhile is left alone.
     *
     * @param list<TranslationSuggestion> $suggestions
     */
    private function closeDelivered(array $suggestions): void
    {
        $this->suggestionRepository->transactional(function () use ($suggestions): void {
            foreach ($this->suggestionRepository->lockStillPending($suggestions) as $suggestion) {
                $suggestion->reject('system');
                $this->eventDispatcher->dispatch(new SuggestionRejectedEvent($suggestion, SuggestionRejectedEvent::SUPERSEDED_BY_FILE));
                $this->suggestionRepository->removeDeferred($suggestion);
            }

            $this->suggestionRepository->flush();
        });
    }

    /**
     * Splits the batch on two caps — item count AND cumulative source length — so a
     * catalogue of long values cannot overflow the model's reply (truncated JSON would
     * fail the whole chunk).
     *
     * @param array<string, string> $texts
     *
     * @return iterable<array<string, string>>
     */
    public function chunkTexts(array $texts): iterable
    {
        $chunk = [];
        $chars = 0;

        foreach ($texts as $key => $text) {
            $length = mb_strlen($text);

            if ([] !== $chunk && (\count($chunk) >= self::CHUNK_MAX_ITEMS || $chars + $length > self::CHUNK_MAX_CHARS)) {
                yield $chunk;
                $chunk = [];
                $chars = 0;
            }

            $chunk[$key] = $text;
            $chars += $length;
        }

        if ([] !== $chunk) {
            yield $chunk;
        }
    }
}
