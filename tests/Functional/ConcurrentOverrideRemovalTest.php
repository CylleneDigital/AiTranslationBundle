<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Event\OverrideRemovedEvent;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Two admins reset the same entry at once: one row is deleted, so one removal is announced.
 * The other connection is a second PHP process, which deletes the row in a transaction
 * left open while this one removes it. Row locks only: SQLite locks the whole database.
 */
final class ConcurrentOverrideRemovalTest extends DatabaseTestCase
{
    /** How long the other process keeps its deletion uncommitted, once it holds the row. */
    private const int HOLD_MICROSECONDS = 500_000;

    public function testARemovalThatWaitedForAConcurrentOneAnnouncesNothing(): void
    {
        $connection = $this->entityManager()->getConnection();

        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite has no row lock: run it on MariaDB or PostgreSQL (DATABASE_URL).');
        }

        $this->writer()->save('app.reset', 'messages', 'fr', 'Réinitialiser');

        $removed = 0;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(OverrideRemovedEvent::class, static function () use (&$removed): void {
            ++$removed;
        });

        $process = proc_open(
            [\PHP_BINARY, '-r', self::OTHER_PROCESS],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__, 2),
            ['CONNECTION_PARAMS' => base64_encode(serialize($connection->getParams())), 'HOLD' => (string) self::HOLD_MICROSECONDS],
        );
        self::assertIsResource($process);

        // The other process holds the row from here until its commit. Its error output is
        // only read on failure: reading it waits for the process to end.
        if ("deleted\n" !== fgets($pipes[1])) {
            self::fail((string) stream_get_contents($pipes[2]));
        }

        $this->writer()->remove('app.reset', 'messages', 'fr');

        self::assertSame(0, proc_close($process));
        self::assertSame(0, $removed, 'The other process removed the row: this removal announces nothing.');
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_override'));
    }

    private const string OTHER_PROCESS = <<<'PHP'
        require 'vendor/autoload.php';
        $connection = Doctrine\DBAL\DriverManager::getConnection(unserialize(base64_decode((string) getenv('CONNECTION_PARAMS'))));
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM cyllene_translation_override WHERE translation_key = ?', ['app.reset']);
        fwrite(STDOUT, "deleted\n");
        usleep((int) getenv('HOLD'));
        $connection->commit();
        PHP;
}
