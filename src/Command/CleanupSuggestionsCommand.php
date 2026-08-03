<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Repository\GenerationLogRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention of the review history, cron-friendly: purge the reviewed suggestions
 * (pending ones are never touched) and the generation-log entries older than the
 * threshold — worth a cron entry when generation runs regularly, so the tables keep
 * the recent audit trail without growing forever.
 */
#[AsCommand(
    name: 'cyllene:ai-translation:cleanup-suggestions',
    description: 'Purge the reviewed suggestions and generation-log entries older than --before (cron-friendly)',
)]
final class CleanupSuggestionsCommand
{
    public function __construct(
        private readonly TranslationSuggestionRepository $suggestionRepository,
        private readonly GenerationLogRepository $generationLogRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Age threshold — an age such as "30 days", "2 weeks", "6 months" or "1 year" (not a date: "yesterday" or "2026-09-01" is refused)', shortcut: 'b')]
        string $before = '30 days',
        #[Option(description: 'Show what would be deleted without deleting it')]
        bool $dryRun = false,
    ): int {
        $now = $this->clock->now();

        // An age and nothing else: modify() reads far more than that, and wrongly here. A
        // unitless "30" is a timezone (no shift), "1 week ago" a future date — either would
        // purge the whole history — and an ISO date "2026-09-01" becomes the year -2026: in
        // the past, accepted, and silently deleting nothing.
        $threshold = 1 === preg_match('/^\d+\s*(day|week|month|year)s?$/i', trim($before)) ? $now->modify('-'.trim($before)) : null;

        if (null === $threshold || $threshold >= $now) {
            $io->error(\sprintf('Invalid --before value "%s" (expected an interval in the past, e.g. "30 days", "6 months").', $before));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $count = $this->suggestionRepository->countReviewedBefore($threshold);
            $io->info(\sprintf('%d reviewed suggestion(s) older than %s would be deleted.', $count, $threshold->format('Y-m-d')));

            // The run purges the journal too: the dry run says so.
            $logs = $this->generationLogRepository->countOlderThan($threshold);

            if ($logs > 0) {
                $io->info(\sprintf('%d generation-log entry(ies) would be purged.', $logs));
            }

            return Command::SUCCESS;
        }

        $deleted = $this->suggestionRepository->deleteReviewedBefore($threshold);
        // The generation journal shares the same retention window (feedback surface, not audit).
        $logsDeleted = $this->generationLogRepository->deleteOlderThan($threshold);

        $io->success(\sprintf('%d reviewed suggestion(s) older than %s deleted (pending ones are never touched).', $deleted, $threshold->format('Y-m-d')));

        if ($logsDeleted > 0) {
            $io->info(\sprintf('%d generation-log entry(ies) purged.', $logsDeleted));
        }

        return Command::SUCCESS;
    }
}
