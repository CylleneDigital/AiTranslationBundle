<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;

/**
 * Serialises the stored overrides for the export-overrides command and host download surfaces.
 * xlf is an XLIFF 1.2 document with one <file> element per (locale, catalogue, scope) —
 * the catalogue travels in the standard `original` attribute, the scope in `category`;
 * csv is a flat table (one override per row, empty scope = global). Both formats
 * round-trip through the {@see OverrideImporter}.
 */
final class OverrideExporter
{
    public const string FORMAT_XLIFF = 'xlf';

    public const string FORMAT_CSV = 'csv';

    public const array FORMATS = [self::FORMAT_XLIFF, self::FORMAT_CSV];

    /**
     * The same list, ready to be interpolated where only a constant expression is
     * allowed — a console #[Option] description, typically. Derived from the constants
     * above so a new format cannot be advertised in one place and missing in the other.
     */
    public const string FORMATS_LIST = self::FORMAT_XLIFF.', '.self::FORMAT_CSV;

    private const array EXTENSION_TO_FORMAT = [
        'xlf' => self::FORMAT_XLIFF,
        'xliff' => self::FORMAT_XLIFF,
        'csv' => self::FORMAT_CSV,
    ];

    public function __construct(
        private readonly TranslationOverrideRepository $repository,
    ) {
    }

    public function supports(string $format): bool
    {
        return \in_array($format, self::FORMATS, true);
    }

    /** The format a file name's extension names, or null when it names none — the import detects it the same way. */
    public static function formatForPath(string $path): ?string
    {
        return self::EXTENSION_TO_FORMAT[strtolower(pathinfo($path, \PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * Why writing $format into $path would mislead, or null when it would not: an export
     * file is read back by its extension, so XLIFF in a ".csv" fails the import and
     * opens as garbage in a spreadsheet. An extension naming no format is accepted.
     */
    public static function contradiction(string $path, string $format): ?string
    {
        $named = self::formatForPath($path);

        return null !== $named && $named !== $format
            ? \sprintf('The extension of "%s" names the %s format, not %s — change the file name or the format.', basename($path), $named, $format)
            : null;
    }

    public function hasOverrides(?OverrideExportFilter $filter = null): bool
    {
        if (null === $filter) {
            return $this->repository->countOverrides() > 0;
        }

        // One row is enough to answer, where collect() would load the whole export.
        return $this->repository->hasFilteredRows($filter);
    }

    public function export(string $format, ?OverrideExportFilter $filter = null): string
    {
        [$grouped, $scoped] = $this->collect($filter);

        return match ($format) {
            'xlf' => $this->toXliff($grouped, $scoped),
            'csv' => $this->toCsv($grouped, $scoped),
            default => throw new \InvalidArgumentException(\sprintf('Unknown export format "%s" (supported: %s).', $format, implode(', ', self::FORMATS))),
        };
    }

    /**
     * The overrides to serialise, grouped the way every format consumes them: the
     * global ones as `locale => catalogue => key => value`, the scoped ones with the
     * scope as the outer level. With no filter this matches the repository's own
     * grouped queries; a filter narrows to a caller's current view.
     *
     * @return array{0: array<string, array<string, array<string, string>>>, 1: array<string, array<string, array<string, array<string, string>>>>}
     */
    private function collect(?OverrideExportFilter $filter): array
    {
        if (null === $filter) {
            return [$this->repository->findAllGrouped(), $this->repository->findScopedGrouped()];
        }

        $grouped = [];
        $scoped = [];

        // Scalar rows, narrowed in SQL: hydrating the whole table to filter it in PHP
        // was the most expensive path for the query returning the fewest rows.
        foreach ($this->repository->findFilteredRowsForExport($filter) as $row) {
            if ('' === $row['scope']) {
                $grouped[$row['locale']][$row['catalogue']][$row['key']] = $row['value'];
            } else {
                $scoped[$row['scope']][$row['locale']][$row['catalogue']][$row['key']] = $row['value'];
            }
        }

        return [$grouped, $scoped];
    }

    /**
     * @param array<string, array<string, array<string, string>>>                $grouped
     * @param array<string, array<string, array<string, array<string, string>>>> $scoped
     */
    private function toCsv(array $grouped, array $scoped): string
    {
        $handle = fopen('php://temp', 'r+');
        \assert(false !== $handle);

        // UTF-8 BOM: without it Excel reads the accents as Windows-1252 (the import strips it).
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['locale', 'catalogue', 'translation_key', 'value', 'scope'], escape: '');

        foreach ($grouped as $locale => $catalogues) {
            foreach ($catalogues as $catalogue => $keys) {
                foreach ($keys as $key => $value) {
                    fputcsv($handle, array_map(CsvFormulaGuard::escape(...), [$locale, $catalogue, (string) $key, $value, '']), escape: '');
                }
            }
        }

        foreach ($scoped as $scope => $locales) {
            foreach ($locales as $locale => $catalogues) {
                foreach ($catalogues as $catalogue => $keys) {
                    foreach ($keys as $key => $value) {
                        fputcsv($handle, array_map(CsvFormulaGuard::escape(...), [$locale, $catalogue, (string) $key, $value, $scope]), escape: '');
                    }
                }
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param array<string, array<string, array<string, string>>>                $grouped
     * @param array<string, array<string, array<string, array<string, string>>>> $scoped
     */
    private function toXliff(array $grouped, array $scoped): string
    {
        $document = new \DOMDocument('1.0', 'utf-8');
        $document->formatOutput = true;

        $xliff = $document->createElementNS('urn:oasis:names:tc:xliff:document:1.2', 'xliff');
        $xliff->setAttribute('version', '1.2');
        $document->appendChild($xliff);

        foreach ($grouped as $locale => $catalogues) {
            foreach ($catalogues as $catalogue => $keys) {
                $this->appendXliffFile($document, $xliff, $locale, $catalogue, $keys, '');
            }
        }

        foreach ($scoped as $scope => $locales) {
            foreach ($locales as $locale => $catalogues) {
                foreach ($catalogues as $catalogue => $keys) {
                    $this->appendXliffFile($document, $xliff, $locale, $catalogue, $keys, $scope);
                }
            }
        }

        return (string) $document->saveXML();
    }

    /**
     * @param array<string, string> $keys
     */
    private function appendXliffFile(\DOMDocument $document, \DOMElement $xliff, string $locale, string $catalogue, array $keys, string $scope): void
    {
        $file = $document->createElement('file');
        // The overrides have no tracked source language: the target is all we know.
        $file->setAttribute('source-language', $locale);
        $file->setAttribute('target-language', $locale);
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('original', $catalogue);

        if ('' !== $scope) {
            // Standard XLIFF 1.2 attribute, reused to carry the override scope.
            $file->setAttribute('category', $scope);
        }

        $xliff->appendChild($file);

        $body = $document->createElement('body');
        $file->appendChild($body);

        $id = 0;
        foreach ($keys as $key => $value) {
            $key = (string) $key;
            $unit = $document->createElement('trans-unit');
            $unit->setAttribute('id', (string) ++$id);
            $unit->setAttribute('resname', $key);

            $source = $document->createElement('source');
            $source->appendChild($document->createTextNode($key));
            $unit->appendChild($source);

            $target = $document->createElement('target');
            $target->appendChild($document->createTextNode($value));
            $unit->appendChild($target);

            $body->appendChild($unit);
        }
    }
}
