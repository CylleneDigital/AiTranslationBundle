<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Suggestion;

use CylleneDigital\AiTranslationBundle\Suggestion\GenerationLock;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The lock against double billing, on a store that records what it is asked and can be
 * made to fail: a refresh must really reach the store, and a failing store must never
 * stop a run — only make it unguarded, loudly.
 */
final class GenerationLockTest extends TestCase
{
    public function testTheKeepAlivePushesBackTheExpirationInTheStore(): void
    {
        $store = $this->recordingStore();
        $lock = new GenerationLock(new LockFactory($store), 60);

        $lock->run('messages', 'en_US', '', static function (callable $keepAlive): int {
            $keepAlive();
            $keepAlive();

            return 1;
        });

        self::assertSame(2, $store->refreshes);
    }

    public function testAnUnreachableStoreLetsTheRunProceedUnguarded(): void
    {
        $store = $this->recordingStore(failOnSave: true);
        $logs = [];
        $lock = new GenerationLock(new LockFactory($store), 60, $this->logger($logs));

        $result = $lock->run('messages', 'en_US', '', static function (callable $keepAlive): int {
            $keepAlive(); // a no-op: there is no lock to refresh

            return 42;
        });

        self::assertSame(42, $result);
        self::assertSame(0, $store->refreshes);
        self::assertStringContainsString('warning: The AI translation generation lock could not be acquired', implode("\n", $logs));
    }

    public function testAFailedRefreshDoesNotAbortTheRun(): void
    {
        $store = $this->recordingStore(failOnRefresh: true);
        $logs = [];
        $lock = new GenerationLock(new LockFactory($store), 60, $this->logger($logs));

        $batches = 0;
        $lock->run('messages', 'en_US', '', static function (callable $keepAlive) use (&$batches): int {
            foreach ([1, 2] as $ignored) {
                ++$batches;
                $keepAlive();
            }

            return $batches;
        });

        self::assertSame(2, $batches);
        self::assertStringContainsString('warning: The AI translation generation lock could not be refreshed', implode("\n", $logs));
    }

    /** The run is over, billed and journaled: a store failing on release must not fail it. */
    public function testAFailedReleaseKeepsTheRunResult(): void
    {
        $logs = [];
        $lock = new GenerationLock(new LockFactory($this->recordingStore(failOnRelease: true)), 60, $this->logger($logs));

        self::assertSame(42, $lock->run('messages', 'en_US', '', static fn (callable $keepAlive): int => 42));
        self::assertStringContainsString('warning: The AI translation generation lock could not be released', implode("\n", $logs));
    }

    /**
     * An InMemoryStore that counts the refreshes and fails on demand. Acquiring a lock
     * with a TTL already calls putOffExpiration() once: only the later calls — the
     * keep-alive — count as refreshes, and only they fail.
     */
    /**
     * @return PersistingStoreInterface&object{refreshes: int}
     */
    private function recordingStore(bool $failOnSave = false, bool $failOnRefresh = false, bool $failOnRelease = false): PersistingStoreInterface
    {
        return new class($failOnSave, $failOnRefresh, $failOnRelease) implements PersistingStoreInterface {
            public int $refreshes = 0;

            private InMemoryStore $inner;

            private bool $acquired = false;

            public function __construct(private readonly bool $failOnSave, private readonly bool $failOnRefresh, private readonly bool $failOnRelease)
            {
                $this->inner = new InMemoryStore();
            }

            public function save(Key $key): void
            {
                if ($this->failOnSave) {
                    throw new LockStorageException('Connection refused');
                }

                $this->inner->save($key);
            }

            public function delete(Key $key): void
            {
                if ($this->failOnRelease) {
                    throw new LockReleasingException('Connection lost');
                }

                $this->inner->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->inner->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                if ($this->acquired) {
                    if ($this->failOnRefresh) {
                        throw new LockStorageException('Connection lost');
                    }

                    ++$this->refreshes;
                }

                $this->acquired = true;
                $this->inner->putOffExpiration($key, $ttl);
            }
        };
    }

    /**
     * @param list<string> $logs
     */
    private function logger(array &$logs): AbstractLogger
    {
        return new class($logs) extends AbstractLogger {
            /** @param list<string> $logs */
            public function __construct(public array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                // The level is part of the promise: a lock that cannot be held is a warning.
                Assert::assertIsString($level);
                $this->logs[] = $level.': '.$message;
            }
        };
    }
}
