<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Tests\App\FullConfigTestKernel;
use CylleneDigital\AiTranslationBundle\Tests\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * translation_files_cache is on by default and hands the scanner the bundle's pool; off, the
 * scanner parses on every new process. Either way the scanner is reset between two
 * Messenger messages, so a worker sees an edited file.
 */
final class TranslationFilesCacheWiringTest extends KernelTestCase
{
    public function testTheScannerUsesTheBundlePoolByDefault(): void
    {
        static::$class = TestKernel::class;
        self::bootKernel();

        self::assertSame(
            self::getContainer()->get('cyllene_digital_ai_translation.translation_files_cache'),
            $this->filesCache($this->scanner()),
        );
    }

    public function testTheScannerHasNoPoolWhenTheCacheIsOff(): void
    {
        static::$class = FullConfigTestKernel::class;
        self::bootKernel();

        self::assertNull($this->filesCache($this->scanner()));
    }

    public function testTheScannerIsResetWithTheOtherServices(): void
    {
        static::$class = TestKernel::class;
        self::bootKernel();

        $scanner = $this->scanner();
        $scanner->getCatalogues();

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertNull((new \ReflectionProperty(TranslationFileScanner::class, 'catalogues'))->getValue($scanner), 'The next call walks the directories again.');
    }

    private function scanner(): TranslationFileScanner
    {
        $scanner = self::getContainer()->get(TranslationFileScanner::class);
        self::assertInstanceOf(TranslationFileScanner::class, $scanner);

        return $scanner;
    }

    private function filesCache(TranslationFileScanner $scanner): mixed
    {
        return (new \ReflectionProperty(TranslationFileScanner::class, 'filesCache'))->getValue($scanner);
    }
}
