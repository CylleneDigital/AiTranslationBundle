<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

/**
 * Serialises a browser VIEW: every displayed row with its effective value (override,
 * inherited or file value), untranslated rows included as empty values so a
 * translator can fill them in. CSV additionally carries the original file value —
 * the source text a translator works from; XLIFF puts it in <source>, its natural
 * place. The output re-imports through {@see OverrideImporter}, whose baseline diff
 * turns only the actually-changed values into overrides.
 *
 * The full multi-locale/multi-scope override backup (deltas only, restored as-is)
 * stays with {@see OverrideExporter} and the export-overrides command.
 */
final class ViewExporter
{
    /**
     * @param list<array{catalogue: string, key: string, original: ?string, value: ?string}> $rows
     */
    public function export(string $format, array $rows, string $locale, string $scope = ''): string
    {
        return match ($format) {
            'xlf' => $this->toXliff($rows, $locale, $scope),
            'csv' => $this->toCsv($rows, $locale, $scope),
            default => throw new \InvalidArgumentException(\sprintf('Unknown export format "%s" (supported: %s).', $format, implode(', ', OverrideExporter::FORMATS))),
        };
    }

    /**
     * @param list<array{catalogue: string, key: string, original: ?string, value: ?string}> $rows
     */
    private function toCsv(array $rows, string $locale, string $scope): string
    {
        $handle = fopen('php://temp', 'r+');
        \assert(false !== $handle);

        // UTF-8 BOM: without it Excel reads the accents as Windows-1252 (the import strips it).
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['locale', 'catalogue', 'translation_key', 'original', 'value', 'scope'], escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(CsvFormulaGuard::escape(...), [
                $locale,
                $row['catalogue'],
                $row['key'],
                $row['original'] ?? '',
                $row['value'] ?? '',
                $scope,
            ]), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param list<array{catalogue: string, key: string, original: ?string, value: ?string}> $rows
     */
    private function toXliff(array $rows, string $locale, string $scope): string
    {
        $document = new \DOMDocument('1.0', 'utf-8');
        $document->formatOutput = true;

        $xliff = $document->createElementNS('urn:oasis:names:tc:xliff:document:1.2', 'xliff');
        $xliff->setAttribute('version', '1.2');
        $document->appendChild($xliff);

        $byCatalogue = [];
        foreach ($rows as $row) {
            $byCatalogue[$row['catalogue']][] = $row;
        }

        foreach ($byCatalogue as $catalogue => $catalogueRows) {
            $file = $document->createElement('file');
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
            foreach ($catalogueRows as $row) {
                $unit = $document->createElement('trans-unit');
                $unit->setAttribute('id', (string) ++$id);
                $unit->setAttribute('resname', $row['key']);

                $source = $document->createElement('source');
                $source->appendChild($document->createTextNode($row['original'] ?? $row['key']));
                $unit->appendChild($source);

                $target = $document->createElement('target');
                $target->appendChild($document->createTextNode($row['value'] ?? ''));
                $unit->appendChild($target);

                $body->appendChild($unit);
            }
        }

        return (string) $document->saveXML();
    }
}
