<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Coverage\LocaleCoverage;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console rendering of a coverage report — shared between the option-driven
 * `coverage` command (the CI gate) and the interactive coverage journey, so the two
 * displays can never drift apart.
 */
final class CoverageDisplay
{
    public function __construct(
        private readonly TranslationSuggestionRepository $suggestionRepository,
    ) {
    }

    /**
     * @param list<LocaleCoverage> $coverages
     */
    public function table(SymfonyStyle $io, array $coverages): void
    {
        $io->table(
            ['Locale', 'Translated', 'Missing', 'Pending review', 'Coverage'],
            array_map(static fn (LocaleCoverage $coverage): array => [
                $coverage->locale,
                $coverage->translated,
                $coverage->missing,
                $coverage->pendingSuggestions,
                \sprintf('%.1F %%', $coverage->getPercent()),
            ], $coverages),
        );
    }

    /**
     * The missing keys per locale, grouped by catalogue. A pending suggestion is work
     * already in flight — those keys are flagged so a translator handoff does not
     * duplicate a review in progress.
     *
     * @param list<LocaleCoverage> $coverages
     */
    public function missingKeys(SymfonyStyle $io, array $coverages, string $scope): void
    {
        foreach ($coverages as $coverage) {
            if ([] === $coverage->missingKeys) {
                continue;
            }

            $pending = [];
            foreach ($this->suggestionRepository->findPending($coverage->locale, null, $scope) as $suggestion) {
                $pending[$suggestion->getCatalogue()][$suggestion->getKey()] = true;
            }

            $io->section(\sprintf('Missing in %s (%d)', $coverage->locale, $coverage->missing));

            foreach ($coverage->missingKeys as $catalogue => $keys) {
                $io->text(\sprintf('<info>%s</info>', $catalogue));

                foreach ($keys as $key) {
                    $io->text('  '.$key.(isset($pending[$catalogue][$key]) ? ' <comment>(pending review)</comment>' : ''));
                }
            }

            $io->newLine();
        }
    }
}
