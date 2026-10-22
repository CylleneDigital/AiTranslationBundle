<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Event\OverrideRemovedEvent;
use CylleneDigital\AiTranslationBundle\Event\OverrideSavedEvent;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A write of the bundle meeting another connection's write of the same table. The other
 * connection is a second PHP process, which writes in a transaction left open for
 * HOLD_MICROSECONDS while this one writes. Row locks only: SQLite locks the whole database.
 */
final class ConcurrentOverrideWritesTest extends DatabaseTestCase
{
    /** How long the other process keeps its statement uncommitted. */
    private const int HOLD_MICROSECONDS = 1_000_000;

    /** Below it, a write did not wait for the other process: the test would prove nothing. */
    private const float WAITED_SECONDS = 0.2;

    /** For the other process: app.two saved by the bundle's OverrideWriter::save(), not by SQL. */
    private const string SAVE_TWO = 'save app.two';

    private const string DELETE_RESET = "DELETE FROM cyllene_translation_override WHERE translation_key = 'app.reset'";

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->entityManager()->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite has no row lock: run it on MariaDB, MySQL or PostgreSQL (DATABASE_URL).');
        }
    }

    /** Two admins reset the same entry at once: one row is deleted, so one removal is announced. */
    public function testARemovalThatWaitedForAConcurrentOneAnnouncesNothing(): void
    {
        $this->writer()->save('app.reset', 'messages', 'fr', 'Réinitialiser');
        $removed = 0;
        $this->dispatcher()->addListener(OverrideRemovedEvent::class, static function () use (&$removed): void {
            ++$removed;
        });

        $deleted = null;
        $waited = $this->whileTheOtherProcessHolds(self::DELETE_RESET, function () use (&$deleted): void {
            $deleted = $this->writer()->remove('app.reset', 'messages', 'fr');
        });

        self::assertGreaterThan(self::WAITED_SECONDS, $waited, 'The removal did not wait for the other process.');
        self::assertFalse($deleted, 'The other process removed the row: this removal reports it deleted nothing.');
        self::assertSame(0, $removed, 'The other process removed the row: this removal announces nothing.');
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_override'));
    }

    /** An admin edits an entry another one is resetting: the value announced is the one stored. */
    public function testASaveThatWaitedForAConcurrentRemovalStoresItsValue(): void
    {
        $this->writer()->save('app.reset', 'messages', 'fr', 'Réinitialiser');
        $saved = [];
        $this->dispatcher()->addListener(OverrideSavedEvent::class, static function (OverrideSavedEvent $event) use (&$saved): void {
            $saved[] = $event->override->getValue();
        });

        $waited = $this->whileTheOtherProcessHolds(self::DELETE_RESET, fn () => $this->writer()->save('app.reset', 'messages', 'fr', 'Remettre à zéro'));

        self::assertGreaterThan(self::WAITED_SECONDS, $waited, 'The save did not wait for the other process.');
        self::assertSame(['Remettre à zéro'], $saved);
        self::assertSame(
            'Remettre à zéro',
            $this->entityManager()->getConnection()->fetchOne("SELECT value FROM cyllene_translation_override WHERE translation_key = 'app.reset'"),
            'The UPDATE of the deleted row matched nothing: the value has to land in a new row.',
        );
    }

    /**
     * Two admins create overrides of different keys, both through save(): neither waits. A
     * locked read of an absent key would take an InnoDB gap lock, and the second insert
     * would wait for the first commit, or deadlock.
     */
    public function testTwoFirstWritesOfDifferentKeysDoNotWaitForEachOther(): void
    {
        $waited = $this->whileTheOtherProcessHolds(self::SAVE_TWO, fn () => $this->writer()->save('app.one', 'messages', 'fr', 'Un'));

        self::assertLessThan(self::WAITED_SECONDS, $waited, 'The first write of app.one waited for the insert of app.two.');
        self::assertSame(2, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_override'));
    }

    /**
     * @param string $statement SQL, or SAVE_TWO for the bundle's own save() of app.two
     *
     * @return float how long $write took, in seconds
     */
    private function whileTheOtherProcessHolds(string $statement, callable $write): float
    {
        $process = proc_open(
            [\PHP_BINARY, '-r', self::OTHER_PROCESS],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__, 2),
            // The whole environment: the kernel the other process boots reads DATABASE_URL.
            [
                ...getenv(),
                'CONNECTION_PARAMS' => base64_encode(serialize($this->entityManager()->getConnection()->getParams())),
                'STATEMENT' => $statement,
                'HOLD' => (string) self::HOLD_MICROSECONDS,
            ],
        );
        self::assertIsResource($process);

        // The other process holds its rows from here until its commit. Its error output is
        // only read on failure: reading it waits for the process to end.
        if ("done\n" !== fgets($pipes[1])) {
            self::fail((string) stream_get_contents($pipes[2]));
        }

        $start = hrtime(true);
        $write();
        $waited = (hrtime(true) - $start) / 1e9;

        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));

        return $waited;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private const string OTHER_PROCESS = <<<'PHP'
        require 'vendor/autoload.php';
        if ('save app.two' === getenv('STATEMENT')) {
            $kernel = new CylleneDigital\AiTranslationBundle\Tests\App\TestKernel('test', true);
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $connection = $container->get('doctrine.dbal.default_connection');
            $connection->beginTransaction();
            $container->get(CylleneDigital\AiTranslationBundle\Override\OverrideWriter::class)->save('app.two', 'messages', 'fr', 'Deux');
        } else {
            $connection = Doctrine\DBAL\DriverManager::getConnection(unserialize(base64_decode((string) getenv('CONNECTION_PARAMS'))));
            $connection->beginTransaction();
            $connection->executeStatement((string) getenv('STATEMENT'));
        }
        fwrite(STDOUT, "done\n");
        usleep((int) getenv('HOLD'));
        $connection->commit();
        PHP;
}
