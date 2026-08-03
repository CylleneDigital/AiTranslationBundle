<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Command;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Command\CoverageCommand;
use CylleneDigital\AiTranslationBundle\Command\CoverageDisplay;
use CylleneDigital\AiTranslationBundle\Command\ScopeOptionGuard;
use CylleneDigital\AiTranslationBundle\Coverage\CoverageCalculator;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Scope\NullScopeProvider;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CI gate on a scan that found nothing — the case the functional suite, whose
 * kernel always has translations, cannot reach. The rest runs in the functional
 * CoverageCommandTest.
 */
final class CoverageCommandTest extends TestCase
{
    public function testTheGateFailsWhenNothingWasMeasured(): void
    {
        [$exitCode, $display] = $this->runCoverage(min: 95.0);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--min cannot be checked', $display);
    }

    public function testWithoutAGateAnEmptyReportIsNotAnError(): void
    {
        [$exitCode] = $this->runCoverage(min: null);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * @return array{int, string}
     */
    private function runCoverage(?float $min): array
    {
        $localeProvider = $this->createStub(LocaleProviderInterface::class);
        $localeProvider->method('getAvailableLocales')->willReturn([]);

        $scanner = new TranslationFileScanner(__DIR__.'/does-not-exist');
        $catalogues = new CatalogueRegistry($scanner, $localeProvider);
        $suggestions = $this->createStub(TranslationSuggestionRepository::class);

        $command = new CoverageCommand(
            new CoverageCalculator($catalogues, new OverrideReader($this->createStub(TranslationOverrideRepository::class), $catalogues), $suggestions, new ArrayAdapter()),
            new CoverageDisplay($suggestions),
            new ScopeOptionGuard(new ScopeRegistry(new NullScopeProvider())),
            $catalogues,
        );

        $output = new BufferedOutput();
        $exitCode = $command(new SymfonyStyle(new ArrayInput([]), $output), min: $min);

        return [$exitCode, $output->fetch()];
    }
}
