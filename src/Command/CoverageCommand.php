<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Coverage\CoverageCalculator;
use CylleneDigital\AiTranslationBundle\Coverage\LocaleCoverage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cyllene:ai-translation:coverage',
    description: 'Report the translation coverage per locale against the default locale (CI gate via --min)',
)]
final class CoverageCommand
{
    public function __construct(
        private readonly CoverageCalculator $coverageCalculator,
        private readonly CoverageDisplay $display,
        private readonly ScopeOptionGuard $scopeGuard,
        private readonly CatalogueRegistry $catalogues,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Default (reference) locale of the report — falls back to the configured default_locale, then the framework default_locale matched against the available locales, then the first available locale', shortcut: 'd')]
        ?string $defaultLocale = null,
        #[Option(description: 'Restrict the report to one target locale', shortcut: 'l')]
        ?string $locale = null,
        #[Option(description: 'Fail (exit 1) when any reported locale is below this coverage percentage', shortcut: 'm')]
        ?float $min = null,
        #[Option(description: 'Compute the figures for one override scope — an opaque code the host defines (default: the global overrides)', shortcut: 's')]
        string $scope = '',
        #[Option(name: 'list-missing', description: 'List the missing keys per locale after the table (keys covered by a pending suggestion are flagged)')]
        bool $listMissing = false,
    ): int {
        // No locale can score above 100 % or below 0 %: such a gate would pass or fail
        // every build whatever the figures — a typo, refused before any computation.
        if (null !== $min && ($min < 0 || $min > 100)) {
            $io->error(\sprintf('--min is a percentage: between 0 and 100 (got %s).', $min));

            return Command::FAILURE;
        }

        // A mistyped additional_paths entry silently disappears from the figures —
        // surface it here rather than reporting a falsely complete coverage.
        foreach ($this->catalogues->getMissingAdditionalPaths() as $label => $path) {
            $io->warning(\sprintf('The additional translation root "%s" does not exist: %s — its catalogues are missing from this report.', $label, $path));
        }

        // An unknown scope would silently yield the global figures dressed as the
        // scope's — refuse it instead.
        if (!$this->scopeGuard->accept($io, $scope)) {
            return Command::FAILURE;
        }

        // A reference locale without files has no key to miss: every locale would show
        // 100 % and the gate would pass on a typo ("fr-FR", a stale default_locale).
        // Symfony locales use "_": "pt-BR" is the "pt_BR" of the scanned files.
        $defaultLocale = null !== $defaultLocale ? str_replace('-', '_', $defaultLocale) : $this->coverageCalculator->getDefaultLocale();
        $locale = null !== $locale ? str_replace('-', '_', $locale) : null;
        $available = $this->catalogues->getAvailableLocales();

        if ([] !== $available && !\in_array($defaultLocale, $available, true)) {
            $io->error(\sprintf('The default locale "%s" is not available (available: %s).', $defaultLocale, implode(', ', $available)));

            return Command::FAILURE;
        }

        // Always a fresh count: a CI gate must not trust a five-minute-old cache entry.
        $report = $this->coverageCalculator->compute($defaultLocale, $scope, collectMissingKeys: $listMissing);

        $coverages = $report->locales;

        if (null !== $locale) {
            $coverages = array_values(array_filter(
                $coverages,
                static fn (LocaleCoverage $coverage): bool => $coverage->locale === $locale,
            ));

            if ([] === $coverages) {
                $io->error(\sprintf('Locale "%s" is not available (default locale: %s).', $locale, $report->defaultLocale));

                return Command::FAILURE;
            }
        }

        $io->title(\sprintf('Translation coverage (default locale: %s%s)', $report->defaultLocale, '' !== $scope ? ', scope: '.$scope : ''));

        if ([] === $coverages) {
            // Nothing measured is not "every locale above the threshold": a gate must not
            // turn green on an empty scan (a wrong translations_path, a missing volume).
            if (null !== $min) {
                $io->error('No target locale was measured, so --min cannot be checked — is "translations_path" right, and does the project have translations besides the default locale?');

                return Command::FAILURE;
            }

            $io->info('No target locale to report — the default locale is the only available one.');

            return Command::SUCCESS;
        }

        $this->display->table($io, $coverages);

        if ($listMissing) {
            $this->display->missingKeys($io, $coverages, $scope);
        }

        if (null === $min) {
            return Command::SUCCESS;
        }

        $failing = array_values(array_filter(
            $coverages,
            static fn (LocaleCoverage $coverage): bool => $coverage->isBelow($min),
        ));

        if ([] !== $failing) {
            $io->error(\sprintf(
                'Coverage below %.1F %% for: %s.',
                $min,
                implode(', ', array_map(
                    static fn (LocaleCoverage $coverage): string => \sprintf('%s (%.1F %%)', $coverage->locale, $coverage->getPercent()),
                    $failing,
                )),
            ));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Every locale is at or above %.1F %% coverage.', $min));

        return Command::SUCCESS;
    }
}
