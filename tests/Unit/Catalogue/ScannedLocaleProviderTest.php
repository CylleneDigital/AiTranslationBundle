<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Catalogue;

use CylleneDigital\AiTranslationBundle\Catalogue\ScannedLocaleProvider;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use PHPUnit\Framework\TestCase;

/**
 * The default locale source — the one a plain Symfony host gets. It is also the reason
 * the runtime override lookup has to follow the language chain: a project whose files are
 * named "messages.fr.yaml" only ever exposes "fr" here, whatever locale it runs on.
 */
final class ScannedLocaleProviderTest extends TestCase
{
    public function testTheAvailableLocalesAreTheOnesFoundInTheFileNames(): void
    {
        $provider = new ScannedLocaleProvider(new TranslationFileScanner(__DIR__.'/../../Fixtures/translations'));

        self::assertSame(['en', 'en_US', 'fr'], $provider->getAvailableLocales());
    }

    public function testAnEmptyDirectoryYieldsNoLocale(): void
    {
        $provider = new ScannedLocaleProvider(new TranslationFileScanner(__DIR__.'/does-not-exist'));

        self::assertSame([], $provider->getAvailableLocales());
    }
}
