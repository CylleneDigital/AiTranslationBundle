<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Transfer\OverrideImporter;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideImportIssue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Guided import of an overrides file (the export formats): the preview is a built-in
 * step, not an option — the counts are on screen before anything is written, and the
 * write is a confirmation.
 */
#[AsTaggedItem(priority: 63)]
final class ImportOverridesJourney implements JourneyInterface
{
    public function __construct(
        private readonly OverrideImporter $importer,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getLabel(): string
    {
        return 'Import an overrides file';
    }

    public function run(SymfonyStyle $io): int
    {
        $io->text([
            \sprintf('Put the file inside the project first — %s/var/import for instance.', $this->projectDir),
            'The file is read as the user running this command, so a path outside the project',
            "(another user's home, a system directory) often fails on permissions.",
        ]);

        $question = new Question('Path of the file to import (a .xlf/.xliff or .csv file)');
        $question->setValidator(function (mixed $answer): string {
            $answer = \is_string($answer) ? trim($answer) : '';

            if (!is_file($answer)) {
                throw new \InvalidArgumentException(\sprintf('File "%s" not found.', $answer));
            }

            if (null === $this->importer->guessFormat($answer)) {
                throw new \InvalidArgumentException(\sprintf('Unsupported file extension for "%s" — use xlf/xliff or csv.', $answer));
            }

            return $answer;
        });

        $file = $io->askQuestion($question);
        $file = \is_string($file) ? $file : '';
        $format = (string) $this->importer->guessFormat($file);
        $content = (string) file_get_contents($file);

        // Preview first — the same diff-against-baseline rules as the real import.
        try {
            $dryRun = $this->importer->import($content, $format, dryRun: true);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $skippedSuffix = $dryRun->skipped > 0 ? \sprintf(' (%d invalid entry(ies) skipped)', $dryRun->skipped) : '';

        if (0 === $dryRun->imported + $dryRun->reverted + $dryRun->unchanged + $dryRun->skipped) {
            $io->error('No entry recognised in the file — expected the export formats (XLIFF 1.2, or CSV with a header row).');

            return Command::FAILURE;
        }

        // Each skipped entry is named — where it is in the file, its key, why — so the
        // file can be fixed instead of searched.
        if ($dryRun->skipped > 0) {
            $io->warning(\sprintf('%d invalid entry(ies) will be skipped:', $dryRun->skipped));
            $io->listing(OverrideImportIssue::summarize($dryRun->issues));
        }

        if (0 === $dryRun->imported + $dryRun->reverted) {
            $io->info(0 === $dryRun->skipped ? 'Nothing to import — every entry matches the effective baseline.' : 'Nothing to import.');

            return Command::SUCCESS;
        }

        $revertedSuffix = $dryRun->reverted > 0 ? \sprintf(', %d override(s) would be removed (set back to their baseline: the inherited value, else the file)', $dryRun->reverted) : '';
        $io->info(\sprintf('%d override(s) would be imported%s%s.', $dryRun->imported, $revertedSuffix, $skippedSuffix));

        if (!$io->confirm('Apply these changes now?', true)) {
            $io->note('Nothing imported.');

            return Command::SUCCESS;
        }

        $result = $this->importer->import($content, $format, dryRun: false);

        $io->success(\sprintf('%d override(s) imported%s — caches invalidated.', $result->imported, $result->reverted > 0 ? \sprintf(', %d removed', $result->reverted) : ''));

        return Command::SUCCESS;
    }
}
