<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Transfer\OverrideImporter;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideImportIssue;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Interact;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cyllene:ai-translation:import-overrides',
    description: 'Import translation overrides from an XLIFF or CSV file (the export-overrides format)',
)]
final class ImportOverridesCommand
{
    public function __construct(
        private readonly OverrideImporter $importer,
    ) {
    }

    /** On a TTY, a missing file argument becomes a prompt validating the path on the spot. */
    #[Interact]
    public function interact(InputInterface $input, OutputInterface $output): void
    {
        if (null !== $input->getArgument('file')) {
            return;
        }

        $io = new SymfonyStyle($input, $output);

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

        $input->setArgument('file', $io->askQuestion($question));
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Path of the file to import (format detected from the extension: xlf/xliff, csv)')]
        ?string $file = null,
        #[Option(description: 'List what would be imported without writing')]
        bool $dryRun = false,
    ): int {
        // Reached without a TTY only — the interactive prompt asks for it.
        if (null === $file) {
            $io->error('A file to import is required.');
            $io->text('Usage: bin/console cyllene:ai-translation:import-overrides <file> [--dry-run]');

            return Command::FAILURE;
        }

        if (!is_file($file)) {
            $io->error(\sprintf('File "%s" not found.', $file));

            return Command::FAILURE;
        }

        $format = $this->importer->guessFormat($file);

        if (null === $format) {
            $io->error(\sprintf('Unsupported file extension for "%s" — use xlf/xliff or csv.', $file));

            return Command::FAILURE;
        }

        try {
            $result = $this->importer->import((string) file_get_contents($file), $format, $dryRun);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // A parseable file with no entry at all is the wrong file (an XLIFF 2.0, a missing
        // namespace), not a successful no-op.
        if (0 === $result->imported + $result->reverted + $result->unchanged + $result->skipped) {
            $io->error('No entry recognised in the file — expected the export formats (XLIFF 1.2, or CSV with a header row).');

            return Command::FAILURE;
        }

        $suffix = ($result->unchanged > 0 ? \sprintf(', %d unchanged', $result->unchanged) : '')
            .($result->reverted > 0 ? \sprintf(', %d override(s) %s (set back to their baseline: the inherited value, else the file)', $result->reverted, $dryRun ? 'would be removed' : 'removed') : '');

        if ($dryRun) {
            $io->info(\sprintf('Dry run: %d override(s) would be imported%s.', $result->imported, $suffix));
        } elseif (0 === $result->imported + $result->reverted) {
            // Nothing was written, so nothing was invalidated: "caches invalidated" would
            // claim an effect that did not happen — the menu's import says the same. And
            // "every entry matches" only holds when none was skipped.
            if (0 === $result->skipped) {
                $io->success(\sprintf('Nothing to import — every entry matches what is already in place (%d unchanged).', $result->unchanged));
            } else {
                $io->note(\sprintf('Nothing imported%s.', $suffix));
            }
        } else {
            $io->success(\sprintf('%d override(s) imported%s — caches invalidated.', $result->imported, $suffix));
        }

        // The valid entries are applied; the invalid ones still fail a CI step.
        if ($result->skipped > 0) {
            $io->error(\sprintf('%d invalid entry(ies) skipped — fix them in the file and import it again:', $result->skipped));
            $io->listing(OverrideImportIssue::summarize($result->issues));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
