<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Override\TranslationManager;
use CylleneDigital\AiTranslationBundle\Tests\App\FullConfigTestKernel;

/**
 * An additional root whose directory is missing on this machine (a theme not deployed
 * here, a typo in additional_paths) hides its catalogues from the scan. Their overrides
 * are unverifiable, not orphaned: neither counted nor purged.
 */
final class UnscannedRootOrphansTest extends DatabaseTestCase
{
    protected static function getKernelClass(): string
    {
        return FullConfigTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer()->saveMany([
            ['key' => 'app.hello', 'catalogue' => '@gone_theme/messages', 'locale' => 'fr', 'value' => 'Bonjour'],   // unscanned root: kept
            ['key' => 'app.hello', 'catalogue' => '@goneXtheme/messages', 'locale' => 'fr', 'value' => 'Bonjour'],   // "_" is no wildcard: orphan
            ['key' => 'app.hello', 'catalogue' => 'gone/messages', 'locale' => 'fr', 'value' => 'Bonjour'],          // orphan
        ]);
    }

    public function testTheOverridesOfAnUnscannedRootAreNotOrphans(): void
    {
        $manager = $this->manager();

        self::assertSame(2, $manager->countOrphanOverrides());
        self::assertSame(2, $manager->purgeOrphanOverrides());
        self::assertSame('Bonjour', $this->overrides()->getOverrideValue('app.hello', '@gone_theme/messages', 'fr'));
    }

    private function manager(): TranslationManager
    {
        /** @var TranslationManager $manager */
        $manager = self::getContainer()->get(TranslationManager::class);

        return $manager;
    }
}
