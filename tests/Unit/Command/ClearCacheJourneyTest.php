<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Command;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Command\Journey\ClearCacheJourney;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ClearCacheJourneyTest extends TestCase
{
    public function testClearingOneLocaleDropsThatLocalesVersionTokenOnly(): void
    {
        [$journey, $cacheManager] = $this->journey(['fr', 'en']);

        $frBefore = $cacheManager->getVersion('fr');
        $enBefore = $cacheManager->getVersion('en');

        $output = new BufferedOutput();
        self::assertSame(Command::SUCCESS, $journey->run($this->io($output, ['fr'])));

        self::assertNotSame($frBefore, $cacheManager->getVersion('fr'));
        self::assertSame($enBefore, $cacheManager->getVersion('en'));
        self::assertStringContainsString('cleared for locale "fr"', $output->fetch());
    }

    /** A plain enter answers "(all locales)" — the default. */
    public function testAPlainEnterClearsEveryLocale(): void
    {
        [$journey, $cacheManager] = $this->journey(['fr', 'en']);

        $before = ['fr' => $cacheManager->getVersion('fr'), 'en' => $cacheManager->getVersion('en')];

        $output = new BufferedOutput();
        self::assertSame(Command::SUCCESS, $journey->run($this->io($output, [''])));

        self::assertNotSame($before['fr'], $cacheManager->getVersion('fr'));
        self::assertNotSame($before['en'], $cacheManager->getVersion('en'));
        self::assertStringContainsString('all locales (fr, en)', $output->fetch());
    }

    /** Nothing to pick from means no question and no failure — an empty project is not an error. */
    public function testAProjectWithoutLocalesSaysSoAndSucceeds(): void
    {
        [$journey] = $this->journey([]);

        $output = new BufferedOutput();
        self::assertSame(Command::SUCCESS, $journey->run($this->io($output, [])));

        self::assertStringContainsString('No locale available', $output->fetch());
    }

    /**
     * @param list<string> $locales
     *
     * @return array{ClearCacheJourney, TranslationCacheManager}
     */
    private function journey(array $locales): array
    {
        $cacheManager = new TranslationCacheManager(new ArrayAdapter());

        $localeProvider = new class($locales) implements LocaleProviderInterface {
            /** @param list<string> $locales */
            public function __construct(private readonly array $locales)
            {
            }

            public function getAvailableLocales(): array
            {
                return $this->locales;
            }
        };

        $catalogues = new CatalogueRegistry(new TranslationFileScanner(__DIR__.'/../../Fixtures/translations'), $localeProvider);

        return [new ClearCacheJourney($cacheManager, $catalogues), $cacheManager];
    }

    /**
     * @param list<string> $inputs
     */
    private function io(BufferedOutput $output, array $inputs): SymfonyStyle
    {
        $input = new ArrayInput([]);
        $input->setInteractive(true);

        $stream = fopen('php://memory', 'r+');
        \assert(false !== $stream);
        fwrite($stream, implode(\PHP_EOL, $inputs).\PHP_EOL);
        rewind($stream);
        $input->setStream($stream);

        return new SymfonyStyle($input, $output);
    }
}
