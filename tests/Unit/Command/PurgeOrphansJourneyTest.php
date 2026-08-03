<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Command;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Command\Journey\PurgeOrphansJourney;
use CylleneDigital\AiTranslationBundle\Override\NullAuthorProvider;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The journey's own guard — the happy paths run through the hub in the functional
 * PurgeOrphansJourneyTest. An empty catalogue scan would list every override as an
 * orphan: the journey must refuse before offering to delete them all.
 */
final class PurgeOrphansJourneyTest extends TestCase
{
    public function testAnEmptyCatalogueScanOffersNothingForPurge(): void
    {
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->expects(self::never())->method('findOutsideCatalogues');
        $repository->expects(self::never())->method('removeDeferred');

        $catalogues = new CatalogueRegistry(
            new TranslationFileScanner(__DIR__.'/does-not-exist'),
            $this->createStub(LocaleProviderInterface::class),
        );

        $journey = new PurgeOrphansJourney(
            new OverrideReader($repository, $catalogues),
            new OverrideWriter($repository, new TranslationCacheManager(new ArrayAdapter()), new NullAuthorProvider(), new EventDispatcher(), $this->createStub(TranslationSuggestionRepository::class), $this->createStub(LocaleProviderInterface::class)),
            $catalogues,
        );

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        self::assertSame(Command::FAILURE, $journey->run(new SymfonyStyle($input, $output)));
        self::assertStringContainsString('No translation catalogue found', $output->fetch());
    }
}
