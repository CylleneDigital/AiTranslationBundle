<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Command\Command;

/**
 * The orphan purge through the hub: listed first, deleted only on an explicit yes.
 */
final class PurgeOrphansJourneyTest extends HubTestCase
{
    private const string MENU = 'Purge the orphan overrides';

    protected function setUp(): void
    {
        parent::setUp();

        // An override whose catalogue exists in no translation file — the orphan.
        $this->writer()->save('old.key', 'gone/messages', 'fr', 'Disparu');
    }

    public function testDecliningTheConfirmationKeepsTheOrphans(): void
    {
        $tester = $this->runHub([self::MENU, 'no']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing was deleted.', $tester->getDisplay());
        self::assertSame('Disparu', $this->overrides()->getOverrideValue('old.key', 'gone/messages', 'fr'));
    }

    public function testConfirmingPurgesTheOrphans(): void
    {
        $tester = $this->runHub([self::MENU, 'yes']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 orphan override(s) purged.', $tester->getDisplay());
        self::assertNull($this->overrides()->getOverrideValue('old.key', 'gone/messages', 'fr'));
    }

    public function testWithoutOrphansTheJourneyReportsTheCleanState(): void
    {
        $this->writer()->remove('old.key', 'gone/messages', 'fr');

        $tester = $this->runHub([self::MENU]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No orphan override', $tester->getDisplay());
    }
}
