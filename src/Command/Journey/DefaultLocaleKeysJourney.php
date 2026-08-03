<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Coverage\CoverageCalculator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Two perspectives on the default locale's key set: the translatable keys themselves,
 * or the drift — the keys another locale carries beyond the default locale, which the
 * coverage figures cannot see.
 */
#[AsTaggedItem(priority: 50)]
final class DefaultLocaleKeysJourney implements JourneyInterface
{
    private const string MODE_KEYS = 'The translatable keys of the default locale';
    private const string MODE_DRIFT = 'The drift: keys another locale carries beyond the default locale';

    public function __construct(
        private readonly CoverageCalculator $coverageCalculator,
        private readonly CatalogueRegistry $catalogues,
        private readonly ConsolePicker $picker,
    ) {
    }

    public function getLabel(): string
    {
        return 'Inspect the default locale keys and drift';
    }

    public function run(SymfonyStyle $io): int
    {
        $locales = $this->catalogues->getAvailableLocales();

        if ([] === $locales) {
            $io->error('No locale available — the scanned translations/ directory is empty.');

            return Command::FAILURE;
        }

        $defaultLocale = $io->choice('Default locale', $locales, $this->coverageCalculator->getDefaultLocale());
        $defaultLocale = \is_string($defaultLocale) ? $defaultLocale : $locales[0];

        $mode = $io->choice('What do you want to see?', [self::MODE_KEYS, self::MODE_DRIFT], self::MODE_KEYS);
        $extraIn = null;

        if (self::MODE_DRIFT === $mode) {
            $others = array_values(array_filter($locales, static fn (string $locale): bool => $locale !== $defaultLocale));

            if ([] === $others) {
                $io->error('The default locale is the only available one — there is no other locale to compare.');

                return Command::FAILURE;
            }

            $io->text([
                'Coverage asks what a locale is missing. Drift asks the opposite question: what does it',
                'carry that the default locale does not?',
                'A key is listed below when the compared locale has a value for it and the default locale',
                'has none — a key renamed or deleted on the default side, or an override left behind.',
                'Overrides count as values on both sides, so an override alone can create drift.',
                'One locale at a time: run this again for every locale you want to check.',
                'Nothing is changed here — this is a report. Removing a listed key means editing the',
                'translation files or deleting its override.',
            ]);

            $choice = $io->choice('Locale to compare', $others, $others[0]);
            $extraIn = \is_string($choice) ? $choice : $others[0];
        }

        $catalogue = $this->picker->catalogueOrAll($io);
        $scope = $this->picker->scope($io);

        $keysByCatalogue = null !== $extraIn
            ? $this->coverageCalculator->getExtraKeys($extraIn, $defaultLocale, $scope)
            : $this->coverageCalculator->getDefaultLocaleKeys($defaultLocale, $scope);

        if (null !== $catalogue) {
            $keysByCatalogue = array_intersect_key($keysByCatalogue, [$catalogue => true]);
        }

        $io->title(null !== $extraIn
            ? \sprintf('Keys in %s beyond the default locale %s%s', $extraIn, $defaultLocale, '' !== $scope ? ' (scope: '.$scope.')' : '')
            : \sprintf('Translatable keys of the default locale %s%s', $defaultLocale, '' !== $scope ? ' (scope: '.$scope.')' : ''));

        if ([] === $keysByCatalogue) {
            $io->info(null !== $extraIn
                ? \sprintf('No key in %s beyond the default locale — no drift.', $extraIn)
                : 'The default locale has no translatable key.');

            return Command::SUCCESS;
        }

        $total = 0;

        foreach ($keysByCatalogue as $catalogueIdentifier => $keys) {
            $io->section($catalogueIdentifier);
            $io->listing($keys);
            $total += \count($keys);
        }

        $io->success(\sprintf('%d key(s) in %d catalogue(s).', $total, \count($keysByCatalogue)));

        return Command::SUCCESS;
    }
}
