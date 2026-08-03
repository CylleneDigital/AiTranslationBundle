<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Override;

use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TranslationCacheManagerTest extends TestCase
{
    /**
     * Written inside a transaction, an override is invalidated before its commit: a
     * request in between rebuilds the map from the old rows under a fresh version. The
     * second invalidation, once the request is over, must drop that version too.
     */
    public function testTheInvalidationIsRepeatedOnceTheRequestIsOver(): void
    {
        $manager = new TranslationCacheManager(new ArrayAdapter());
        $manager->getVersion('en');

        $manager->invalidate('fr');
        $rebuiltBeforeCommit = $manager->getVersion('fr');
        $en = $manager->getVersion('en');

        $manager->invalidateAgain();

        self::assertNotSame($rebuiltBeforeCommit, $manager->getVersion('fr'));
        self::assertSame($en, $manager->getVersion('en'));
    }

    public function testNothingIsRepeatedTwice(): void
    {
        $manager = new TranslationCacheManager(new ArrayAdapter());

        $manager->invalidate('fr');
        $manager->invalidateAgain();
        $version = $manager->getVersion('fr');

        $manager->invalidateAgain();

        self::assertSame($version, $manager->getVersion('fr'));
    }
}
