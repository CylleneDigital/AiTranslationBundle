<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The overrides whose catalogue no longer exists as files: listed first, purged only
 * after an explicit confirmation.
 */
#[AsTaggedItem(priority: 40)]
final class PurgeOrphansJourney implements JourneyInterface
{
    public function __construct(
        private readonly OverrideReader $overrides,
        private readonly OverrideWriter $writer,
        private readonly CatalogueRegistry $catalogues,
    ) {
    }

    public function getLabel(): string
    {
        return 'Purge the orphan overrides';
    }

    public function run(SymfonyStyle $io): int
    {
        // No catalogue at all makes every override an orphan: far likelier a
        // misconfigured translations_path than a project that dropped all its files —
        // the same guard as TranslationManager::purgeOrphanOverrides().
        if ([] === $this->catalogues->getCatalogueIdentifiers()) {
            $io->error('No translation catalogue found — check "translations_path". Nothing is offered for purge: every stored override would look orphaned.');

            return Command::FAILURE;
        }

        $orphans = $this->overrides->findOrphanOverrides();

        if ([] === $orphans) {
            $io->success('No orphan override: every stored override matches an existing catalogue.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($orphans as $override) {
            $rows[] = [
                $override->getLocale(),
                $override->getCatalogue(),
                $override->getKey(),
                '' === $override->getScope() ? '-' : $override->getScope(),
            ];
        }

        $io->title(\sprintf('%d orphan override(s) — their catalogue no longer exists as files', \count($orphans)));
        $io->table(['Locale', 'Catalogue', 'Key', 'Scope'], $rows);

        if (!$io->confirm(\sprintf('Purge these %d orphan override(s) now?', \count($orphans)), false)) {
            $io->note('Nothing was deleted.');

            return Command::SUCCESS;
        }

        // The rows that were listed and confirmed, not a second lookup that could have
        // moved in between.
        $purged = $this->writer->removeMany($orphans);
        $io->success(\sprintf('%d orphan override(s) purged.', $purged));

        return Command::SUCCESS;
    }
}
