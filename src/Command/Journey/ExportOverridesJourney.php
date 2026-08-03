<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideExporter;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideExportFilter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Guided export of the stored overrides — the same delta backup as the option-driven
 * `export-overrides` command (which stays the scripted path), with the format, the
 * filters and the destination asked instead of optioned. A plain enter everywhere
 * exports everything to the default file.
 */
#[AsTaggedItem(priority: 66)]
final class ExportOverridesJourney implements JourneyInterface
{
    private const string ALL_LOCALES = '(all locales)';
    private const string ALL_SCOPES = '(all scopes)';
    private const string GLOBAL_ONLY = '(global only)';

    public function __construct(
        private readonly OverrideExporter $exporter,
        private readonly CatalogueRegistry $catalogues,
        private readonly ScopeRegistry $scopes,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getLabel(): string
    {
        return 'Export the overrides to a file';
    }

    public function run(SymfonyStyle $io): int
    {
        if (!$this->exporter->hasOverrides(null)) {
            $io->info('No translation overrides stored — nothing to export.');

            return Command::SUCCESS;
        }

        $format = $io->choice('Format', OverrideExporter::FORMATS, OverrideExporter::FORMAT_XLIFF);
        $format = \is_string($format) ? $format : OverrideExporter::FORMAT_XLIFF;

        $locale = $this->askLocale($io);
        $scope = $this->askScope($io);
        $catalogue = $this->askCataloguePrefix($io);

        $filter = null === $locale && null === $scope && null === $catalogue
            ? null
            : new OverrideExportFilter(locale: $locale, scope: $scope, catalogue: $catalogue);

        if (!$this->exporter->hasOverrides($filter)) {
            $io->warning('No translation override matches these filters — nothing to export.');

            return Command::SUCCESS;
        }

        $io->text([
            'The default destination is inside the project — keeping the file there avoids the write',
            "permission errors a path outside it (another user's home, a system directory) usually brings.",
        ]);

        $question = new Question('Output file', \sprintf('%s/var/export/translation_overrides.%s', $this->projectDir, $format));
        $question->setValidator(static function (mixed $answer) use ($format): string {
            if (!\is_string($answer) || '' === trim($answer)) {
                throw new \InvalidArgumentException('An output path is required.');
            }

            // The file is read back by its extension: XLIFF in a ".csv" would fail the import.
            if (null !== $contradiction = OverrideExporter::contradiction($answer, $format)) {
                throw new \InvalidArgumentException($contradiction);
            }

            return $answer;
        });

        $output = $io->askQuestion($question);
        $output = \is_string($output) ? $output : '';

        $this->filesystem->mkdir(\dirname($output));
        $this->filesystem->dumpFile($output, $this->exporter->export($format, $filter));

        $io->success(\sprintf('Overrides exported to %s.', $output));

        return Command::SUCCESS;
    }

    private function askLocale(SymfonyStyle $io): ?string
    {
        $locales = $this->catalogues->getAvailableLocales();

        if (\count($locales) < 2) {
            return null;
        }

        $choice = $io->choice('Locale', array_merge([self::ALL_LOCALES], $locales), self::ALL_LOCALES);

        return \is_string($choice) && self::ALL_LOCALES !== $choice ? $choice : null;
    }

    /** null = every scope, '' = the global overrides only, a code = that scope. */
    private function askScope(SymfonyStyle $io): ?string
    {
        $available = $this->scopes->getAvailableScopes();

        if ([] === $available) {
            return null;
        }

        $choice = $io->choice('Scope', array_merge([self::ALL_SCOPES, self::GLOBAL_ONLY], array_keys($available)), self::ALL_SCOPES);

        return match ($choice) {
            self::ALL_SCOPES => null,
            self::GLOBAL_ONLY => '',
            default => \is_string($choice) ? $choice : null,
        };
    }

    /** A catalogue identifier or any prefix of one (e.g. "shop"), autocompleted; enter = all. */
    private function askCataloguePrefix(SymfonyStyle $io): ?string
    {
        $identifiers = $this->catalogues->getCatalogueIdentifiers();

        if (\count($identifiers) < 2) {
            return null;
        }

        $question = new Question('Catalogue — a full identifier or any prefix (enter for all)');
        $question->setAutocompleterValues($identifiers);

        $answer = $io->askQuestion($question);
        $answer = \is_string($answer) ? trim($answer) : '';

        return '' === $answer ? null : $answer;
    }
}
