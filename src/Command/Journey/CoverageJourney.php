<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Command\CoverageDisplay;
use CylleneDigital\AiTranslationBundle\Coverage\CoverageCalculator;
use CylleneDigital\AiTranslationBundle\Coverage\LocaleCoverage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The coverage report, guided: default locale, scope, and whether to list the missing keys.
 * The CI gate (fail under a percentage) lives in the option-driven `coverage`
 * command — a threshold question makes no sense on a human run.
 */
#[AsTaggedItem(priority: 60)]
final class CoverageJourney implements JourneyInterface
{
    private const string ALL_LOCALES = '(all locales)';

    public function __construct(
        private readonly CoverageCalculator $coverageCalculator,
        private readonly CatalogueRegistry $catalogues,
        private readonly CoverageDisplay $display,
        private readonly ConsolePicker $picker,
    ) {
    }

    public function getLabel(): string
    {
        return 'Coverage report';
    }

    public function run(SymfonyStyle $io): int
    {
        // A mistyped additional_paths entry silently disappears from the figures —
        // surface it here rather than reporting a falsely complete coverage.
        foreach ($this->catalogues->getMissingAdditionalPaths() as $label => $path) {
            $io->warning(\sprintf('The additional translation root "%s" does not exist: %s — its catalogues are missing from this report.', $label, $path));
        }

        $locales = $this->catalogues->getAvailableLocales();

        if ([] === $locales) {
            $io->error('No locale available — the scanned translations/ directory is empty.');

            return Command::FAILURE;
        }

        $defaultLocale = $io->choice('Default locale', $locales, $this->coverageCalculator->getDefaultLocale());
        $defaultLocale = \is_string($defaultLocale) ? $defaultLocale : $locales[0];
        $scope = $this->picker->scope($io);
        $locale = $this->askTargetLocale($io, $locales, $defaultLocale);
        $listMissing = $io->confirm('List the missing keys after the table?', false);

        $report = $this->coverageCalculator->compute($defaultLocale, $scope, collectMissingKeys: $listMissing);
        $coverages = $report->locales;

        if (null !== $locale) {
            $coverages = array_values(array_filter(
                $coverages,
                static fn (LocaleCoverage $coverage): bool => $coverage->locale === $locale,
            ));
        }

        $io->title(\sprintf('Translation coverage (default locale: %s%s)', $report->defaultLocale, '' !== $scope ? ', scope: '.$scope : ''));

        if ([] === $coverages) {
            $io->info('No target locale to report — the default locale is the only available one.');

            return Command::SUCCESS;
        }

        $this->display->table($io, $coverages);

        if ($listMissing) {
            $this->display->missingKeys($io, $coverages, $scope);
        }

        return Command::SUCCESS;
    }

    /**
     * One target locale, or null for all of them — asked only when there are at least
     * two targets to choose from.
     *
     * @param list<string> $locales
     */
    private function askTargetLocale(SymfonyStyle $io, array $locales, string $defaultLocale): ?string
    {
        $targets = array_values(array_filter($locales, static fn (string $locale): bool => $locale !== $defaultLocale));

        if (\count($targets) < 2) {
            return null;
        }

        $choice = $io->choice('Locale', array_merge([self::ALL_LOCALES], $targets), self::ALL_LOCALES);

        return \is_string($choice) && self::ALL_LOCALES !== $choice ? $choice : null;
    }
}
