<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The anti-double-billing guard of a generation run: a non-blocking exclusive lock per
 * (catalogue, target locale, scope). A second worker — or a second dispatch of the same
 * work — skips instead of re-calling, and re-paying, the provider for the keys the first
 * run is already translating.
 *
 * The lock is taken through symfony/lock, so WHERE it is held is the host's decision, not
 * the bundle's:
 *
 *  - out of the box, the framework's default store is local to the machine (semaphore, or
 *    flock). That is enough for any number of workers on ONE host — which covers the usual
 *    "several messenger:consume under a process manager" setup;
 *  - workers spread over several hosts need a shared store, and that is one line of
 *    standard Symfony configuration on the host side (`framework.lock` pointing at Redis, a
 *    database…). No code change here.
 *
 * Deliberately degrading: the lock is a cost safeguard, not a correctness one. Correctness
 * is enforced by the database — one PENDING suggestion per (locale, catalogue, key, scope)
 * — so a run that slips through the lock cannot corrupt anything, it only costs money.
 * A store failure therefore lets the run proceed rather than refusing it.
 */
final class GenerationLock
{
    public function __construct(
        private readonly LockFactory $lockFactory,
        /**
         * Seconds a run may hold the lock without refreshing it. Only expiring stores
         * (Redis, PDO…) use it: the default local stores hold until the process ends,
         * crash included. Refreshed between batches, so it has to cover ONE provider
         * call — not the whole run.
         */
        #[Autowire(param: 'cyllene_digital_ai_translation.generation_lock_ttl')]
        private readonly int $ttl = 1800,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Runs $run under the lock of one (catalogue, target locale, scope) triple.
     *
     * $run receives a "keep alive" callable to invoke between two long steps (after each
     * provider batch): it pushes the expiration back, so a run longer than the TTL does
     * not lose its lock halfway through on an expiring store. It is a no-op on the
     * non-expiring default stores.
     *
     * @template T
     *
     * @param callable(callable(): void): T $run
     *
     * @return T|null null when another run holds the lock — nothing was executed
     */
    public function run(string $catalogue, string $targetLocale, string $scope, callable $run): mixed
    {
        $lock = $this->lockFactory->createLock(
            'cyllene_ai_translation_'.hash('xxh128', $catalogue.'|'.$targetLocale.'|'.$scope),
            (float) $this->ttl,
            autoRelease: false,
        );

        try {
            $acquired = $lock->acquire();
        } catch (LockExceptionInterface $e) {
            // An unreachable store must not stop a translation run: the database
            // constraint still prevents duplicate suggestions, only the double spend
            // becomes possible again.
            $this->logger->warning('The AI translation generation lock could not be acquired — the run proceeds unguarded.', [
                'catalogue' => $catalogue,
                'target_locale' => $targetLocale,
                'scope' => $scope,
                'error' => $e->getMessage(),
            ]);

            return $run(static function (): void {});
        }

        if (!$acquired) {
            $this->logger->info('AI translation generation skipped: another run already holds the lock.', [
                'catalogue' => $catalogue,
                'target_locale' => $targetLocale,
                'scope' => $scope,
            ]);

            return null;
        }

        try {
            return $run(function () use ($lock, $catalogue, $targetLocale): void {
                try {
                    $lock->refresh();
                } catch (LockExceptionInterface $e) {
                    // The provider has already been called for the batches done so far:
                    // aborting would throw that away without preventing anything the
                    // database does not prevent. Carry on, loudly.
                    $this->logger->warning('The AI translation generation lock could not be refreshed — the rest of the run proceeds unguarded.', [
                        'catalogue' => $catalogue,
                        'target_locale' => $targetLocale,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        } finally {
            try {
                $lock->release();
            } catch (LockExceptionInterface $e) {
                // The run is over — billed and journaled: an unreachable store must not
                // turn it into a failure. An expiring store frees the lock with its TTL.
                $this->logger->warning('The AI translation generation lock could not be released — it expires with its TTL.', [
                    'catalogue' => $catalogue,
                    'target_locale' => $targetLocale,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
