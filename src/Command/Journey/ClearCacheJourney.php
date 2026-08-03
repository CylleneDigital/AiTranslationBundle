<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Clears the translation override caches (the cache.app entries),
 * for every locale or a single one.
 */
#[AsTaggedItem(priority: 30)]
final class ClearCacheJourney implements JourneyInterface
{
    private const string ALL_LOCALES = '(all locales)';

    public function __construct(
        private readonly TranslationCacheManager $cacheManager,
        private readonly CatalogueRegistry $catalogues,
    ) {
    }

    public function getLabel(): string
    {
        return 'Clear the translation caches';
    }

    public function run(SymfonyStyle $io): int
    {
        $locales = $this->catalogues->getAvailableLocales();

        if ([] === $locales) {
            $io->info('No locale available — nothing to clear.');

            return Command::SUCCESS;
        }

        $choice = $io->choice('Locale', array_merge([self::ALL_LOCALES], $locales), self::ALL_LOCALES);

        if (\is_string($choice) && self::ALL_LOCALES !== $choice) {
            $this->cacheManager->invalidate($choice);
            $io->success(\sprintf('Translation cache cleared for locale "%s".', $choice));

            return Command::SUCCESS;
        }

        foreach ($locales as $locale) {
            $this->cacheManager->invalidate($locale);
        }

        $io->success(\sprintf('Translation cache cleared for all locales (%s).', implode(', ', $locales)));

        return Command::SUCCESS;
    }
}
