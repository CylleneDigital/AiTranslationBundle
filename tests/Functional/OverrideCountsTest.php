<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Event\OverrideRemovedEvent;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationManager;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The counting reads the integrations display, and the orphan purge, against a real
 * database: the orphan criterion is SQL now, so only a real engine proves it — the
 * case-sensitivity of a catalogue identifier included.
 */
final class OverrideCountsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /** @var OverrideWriter $writer */
        $writer = self::getContainer()->get(OverrideWriter::class);
        $writer->saveMany([
            ['key' => 'app.dashboard', 'catalogue' => 'messages', 'locale' => 'fr', 'value' => 'Pilotage'],              // alive
            ['key' => 'old.key', 'catalogue' => 'gone/messages', 'locale' => 'fr', 'value' => 'Disparu'],                // orphan
            ['key' => 'old.key', 'catalogue' => 'gone/messages', 'locale' => 'en', 'value' => 'Gone', 'scope' => 'b2b'],            // orphan, scoped
            ['key' => 'app.dashboard', 'catalogue' => 'messages', 'locale' => 'fr', 'value' => 'Pilotage B2B', 'scope' => 'b2b'],   // alive, scoped
            ['key' => 'app.dashboard', 'catalogue' => 'Messages', 'locale' => 'fr', 'value' => 'Casse'],                 // orphan: identifiers are case-sensitive
        ]);
    }

    public function testOrphansAreCountedAndListedAcrossEveryScope(): void
    {
        $manager = $this->manager();

        self::assertSame(3, $manager->countOrphanOverrides());
        self::assertCount(3, $manager->findOrphanOverrides());
    }

    public function testOverridesAreCountedPerLocaleForExactlyOneScope(): void
    {
        $manager = $this->manager();

        self::assertSame(['fr' => 3], $manager->countOverridesPerLocale());
        // The scope's own overrides, not the global ones it inherits.
        self::assertSame(['en' => 1, 'fr' => 1], $manager->countOverridesPerLocale('b2b'));
        self::assertSame([], $manager->countOverridesPerLocale('unknown'));
    }

    public function testThePurgeRemovesTheOrphansOnlyAndDispatchesTheirEvents(): void
    {
        $removed = 0;
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(OverrideRemovedEvent::class, static function () use (&$removed): void {
            ++$removed;
        });

        $manager = $this->manager();

        self::assertSame(3, $manager->purgeOrphanOverrides());
        self::assertSame(3, $removed);
        self::assertSame(0, $manager->countOrphanOverrides());
        self::assertSame(2, $manager->countOverrides());
    }

    /** The facade is unused inside the bundle, so the container drops it: built from its parts. */
    private function manager(): TranslationManager
    {
        $container = self::getContainer();

        /** @var CatalogueRegistry $catalogues */
        $catalogues = $container->get(CatalogueRegistry::class);
        /** @var OverrideReader $reader */
        $reader = $container->get(OverrideReader::class);
        /** @var OverrideWriter $writer */
        $writer = $container->get(OverrideWriter::class);
        /** @var ScopeRegistry $scopes */
        $scopes = $container->get(ScopeRegistry::class);

        return new TranslationManager($catalogues, $reader, $writer, $scopes);
    }
}
