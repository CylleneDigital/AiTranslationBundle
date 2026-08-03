<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Transfer\OverrideExporter;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideExportFilter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Interact;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'cyllene:ai-translation:export-overrides',
    description: 'Export the translation overrides to an XLIFF or CSV file',
)]
final class ExportOverridesCommand
{
    public function __construct(
        private readonly OverrideExporter $exporter,
        private readonly Filesystem $filesystem,
        private readonly ScopeOptionGuard $scopeGuard,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * On a TTY, format and destination are asked — a plain enter keeps the defaults,
     * so the interactive run stays as fast as the bare one. Explicit --format/-f or a
     * path argument skip their prompt.
     */
    #[Interact]
    public function interact(InputInterface $input, OutputInterface $output): void
    {
        // An empty store gets the __invoke warning straight away — no point in asking
        // for a destination first.
        if (!$this->exporter->hasOverrides(null)) {
            return;
        }

        $io = new SymfonyStyle($input, $output);

        // Format first — the proposed output path depends on it. A path whose extension
        // names a format has answered the question already.
        $path = $input->getArgument('output');
        $named = \is_string($path) ? OverrideExporter::formatForPath($path) : null;

        if (!$input->hasParameterOption(['--format', '-f'], true) && null !== $named) {
            $input->setOption('format', $named);
        } elseif (!$input->hasParameterOption(['--format', '-f'], true)) {
            $choice = $io->choice('Format', OverrideExporter::FORMATS, OverrideExporter::FORMAT_XLIFF);

            if (\is_string($choice)) {
                $input->setOption('format', $choice);
            }
        }

        if (null === $input->getArgument('output')) {
            $format = $input->getOption('format');
            $default = \sprintf('%s/var/export/translation_overrides.%s', $this->projectDir, \is_string($format) ? $format : OverrideExporter::FORMAT_XLIFF);

            $question = new Question('Output file', $default);
            $question->setValidator(static function (mixed $answer) use ($format): string {
                if (!\is_string($answer) || '' === trim($answer)) {
                    throw new \InvalidArgumentException('An output path is required.');
                }

                if (\is_string($format) && null !== $contradiction = OverrideExporter::contradiction($answer, $format)) {
                    throw new \InvalidArgumentException($contradiction);
                }

                return $answer;
            });

            $input->setArgument('output', $io->askQuestion($question));
        }
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Output file path (default: var/export/translation_overrides.<format>)')]
        ?string $output = null,
        #[Option(description: 'Export format ('.OverrideExporter::FORMATS_LIST.'; default: the output file\'s extension, else xlf)', shortcut: 'f')]
        ?string $format = null,
        #[Option(description: 'Restrict to one locale', shortcut: 'l')]
        ?string $locale = null,
        #[Option(description: 'Restrict to one scope ("" for the global overrides only)', shortcut: 's')]
        ?string $scope = null,
        #[Option(description: 'Restrict to a catalogue identifier or any prefix of one (e.g. "shop")', shortcut: 'c')]
        ?string $catalogue = null,
    ): int {
        // Without --format the output file's extension decides: "export-overrides
        // file.csv" used to write XLIFF into the .csv.
        $format ??= (null !== $output ? OverrideExporter::formatForPath($output) : null) ?? OverrideExporter::FORMAT_XLIFF;

        if (!$this->exporter->supports($format)) {
            $io->error(\sprintf('Unknown format "%s" — use one of: %s.', $format, implode(', ', OverrideExporter::FORMATS)));

            return Command::FAILURE;
        }

        // null = every scope, '' = the global overrides only — both legitimate; an
        // actual code must exist when the host declares scopes.
        if (null !== $scope && !$this->scopeGuard->accept($io, $scope)) {
            return Command::FAILURE;
        }

        $filter = null === $locale && null === $scope && null === $catalogue
            ? null
            // Stored locales use "_": "pt-BR" means the "pt_BR" overrides.
            : new OverrideExportFilter(locale: null !== $locale ? str_replace('-', '_', $locale) : null, scope: $scope, catalogue: $catalogue);

        if (!$this->exporter->hasOverrides($filter)) {
            $io->warning('No translation overrides to export.');

            return Command::SUCCESS;
        }

        $output ??= \sprintf('%s/var/export/translation_overrides.%s', $this->projectDir, $format);

        if (null !== $contradiction = OverrideExporter::contradiction($output, $format)) {
            $io->error($contradiction);

            return Command::FAILURE;
        }

        $this->filesystem->mkdir(\dirname($output));
        $this->filesystem->dumpFile($output, $this->exporter->export($format, $filter));

        $io->success(\sprintf('Overrides exported to %s.', $output));

        return Command::SUCCESS;
    }
}
