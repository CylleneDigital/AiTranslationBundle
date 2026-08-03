<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Override\OverrideChange;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;

/**
 * Parses an override file (the {@see OverrideExporter} formats) and applies it through
 * the {@see OverrideWriter}, in one flush — so an import invalidates the caches and
 * dispatches the events like any other write. Invalid entries are skipped instead of
 * failing the whole file, each reported with where it is and why
 * ({@see OverrideImportIssue}); an unusable file raises
 * {@see InvalidImportFileException}.
 */
final class OverrideImporter
{
    /** Upper bound on an imported XLIFF payload — a sanity guard against oversized uploads. */
    private const int MAX_XML_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly CatalogueRegistry $catalogues,
        private readonly OverrideReader $overrides,
        private readonly ScopeRegistry $scopes,
        private readonly OverrideWriter $writer,
        private readonly TranslationValueValidator $valueValidator,
    ) {
    }

    /** Maps a file extension to a format name, or null when unsupported. */
    public function guessFormat(string $filename): ?string
    {
        return OverrideExporter::formatForPath($filename);
    }

    /**
     * @throws InvalidImportFileException when the content cannot be parsed at all
     */
    public function import(string $content, string $format, bool $dryRun = false): OverrideImportResult
    {
        $entries = $this->parse($content, $format);
        ['kept' => $kept, 'reverted' => $reverted, 'unchanged' => $unchanged, 'invalid' => $invalid] = $this->classify($entries['valid']);
        $issues = [...$entries['skipped'], ...$invalid];

        if (!$dryRun) {
            // The written values and the overrides set back to their baseline, in one flush:
            // a failure leaves the import unapplied rather than half applied. classify()
            // never puts the same entry in both.
            $this->writer->saveAndRemoveMany(array_map(static function (array $entry): array {
                unset($entry['where']);

                return $entry;
            }, $kept), $reverted);
        }

        return new OverrideImportResult(\count($kept), \count($issues), $unchanged, \count($reverted), $issues);
    }

    /**
     * Describes what an import would do, without writing anything — what a caller shows
     * before asking for a confirmation.
     *
     * @throws InvalidImportFileException when the content cannot be parsed at all
     */
    public function preview(string $content, string $format): OverrideImportPreview
    {
        $entries = $this->parse($content, $format);
        ['kept' => $kept, 'reverted' => $reverted, 'unchanged' => $unchanged, 'invalid' => $invalid] = $this->classify($entries['valid']);
        $issues = [...$entries['skipped'], ...$invalid];

        $byLocale = [];
        foreach ($kept as ['locale' => $locale]) {
            $byLocale[$locale] = ($byLocale[$locale] ?? 0) + 1;
        }

        ksort($byLocale);

        return new OverrideImportPreview(\count($kept), \count($issues), $byLocale, $unchanged, \count($reverted), $issues);
    }

    /**
     * Sorts the entries into the values to write, the overrides to revert, the unchanged
     * and the invalid ones.
     *
     * Unchanged: empty values (the untranslated rows of a view snapshot) and values
     * identical to their effective baseline — what the entry inherits once the import is
     * applied (the global override in a scope, the parent language's for a regional
     * locale, else the file value): a full view snapshot re-imports into exactly the
     * overrides that differ from that baseline ({@see OverrideReader::planChanges()}).
     *
     * A value equal to its baseline while an override is stored for that very entry is
     * the opposite case — the file says "back to the original" — so that override is
     * returned for removal ("reverted") rather than silently kept. A value equal to the
     * override already stored is unchanged too: re-importing an untouched export must not
     * rewrite every row, move its updated_at and fire OverrideSavedEvent for nothing
     * ({@see OverrideChange}, the rule every door shares).
     *
     * Values whose syntax does not match their catalogue's declared format
     * ({@see TranslationValueValidator}), and entries carrying a locale, catalogue or
     * scope the host does not know, join the skipped count instead of being written.
     *
     * @param list<array{key: string, catalogue: string, locale: string, value: string, scope: string, where: string}> $entries
     *
     * @return array{kept: list<array{key: string, catalogue: string, locale: string, value: string, scope: string, where: string, original: ?string}>, reverted: list<TranslationOverride>, unchanged: int, invalid: list<OverrideImportIssue>}
     */
    private function classify(array $entries): array
    {
        // An entry repeated in the file: its last occurrence wins, as it would have had the
        // rows been applied one by one — and one entry cannot end up both written and
        // reverted.
        $last = [];

        foreach ($entries as $entry) {
            $last[$entry['locale'].'|'.$entry['catalogue'].'|'.$entry['key'].'|'.$entry['scope']] = $entry;
        }

        $entries = array_values($last);

        $unchanged = 0;
        $invalid = [];
        $writable = [];
        $locales = $this->catalogues->getAvailableLocales();

        foreach ($entries as $index => $entry) {
            ['key' => $key, 'catalogue' => $catalogue, 'locale' => $locale, 'value' => $value, 'scope' => $scope, 'where' => $where] = $entry;

            if ('' === trim($value)) {
                ++$unchanged;

                continue;
            }

            // A locale, catalogue or scope the host does not know produces overrides no
            // runtime lookup will ever read — the silent lie ScopeOptionGuard refuses on
            // the CLI.
            $unknown = match (true) {
                !\in_array($locale, $locales, true) => \sprintf('unknown locale "%s"', $locale),
                null === $this->catalogues->getCatalogue($catalogue) => \sprintf('unknown catalogue "%s"', $catalogue),
                !$this->scopes->isKnownScope($scope) => \sprintf('unknown scope "%s"', $scope),
                default => null,
            };

            if (null !== $unknown) {
                $invalid[] = new OverrideImportIssue($where, $key, $unknown);

                continue;
            }

            // A key the tables cannot store is a broken row of the file, not a reason to
            // abort the import: counted with the other skipped entries.
            if (!$this->writer->acceptsKey($key)) {
                $invalid[] = new OverrideImportIssue($where, mb_substr($key, 0, 60).'…', \sprintf('the key exceeds %d characters', TranslationOverride::MAX_KEY_LENGTH));

                continue;
            }

            if ([] !== $syntaxIssues = $this->valueValidator->validate($value, $key, $catalogue, $locale)) {
                $invalid[] = new OverrideImportIssue($where, $key, \sprintf('the value does not match the catalogue syntax (%s)', implode(', ', $syntaxIssues)));

                continue;
            }

            $writable[$index] = $entry;
        }

        $kept = [];
        $reverted = [];

        // Decided against the state after the import: a parent the file changes is what its
        // scoped or regional entries inherit. The file's order is kept for what is written.
        foreach ($this->overrides->planChanges($writable) as $index => ['change' => $change, 'stored' => $override, 'fileValue' => $fileValue]) {
            match ($change) {
                // The file value is recorded as the original of an override the import
                // creates, as the editor and the approval record it.
                OverrideChange::Write => $kept[$index] = $writable[$index] + ['original' => $fileValue],
                // decide() only reverts a stored override.
                OverrideChange::Revert => $reverted[$index] = $override ?? throw new \LogicException('Nothing stored to revert.'),
                OverrideChange::None => ++$unchanged,
            };
        }

        ksort($kept);
        ksort($reverted);
        $kept = array_values($kept);
        $reverted = array_values($reverted);

        return ['kept' => $kept, 'reverted' => $reverted, 'unchanged' => $unchanged, 'invalid' => $invalid];
    }

    /**
     * @return array{valid: list<array{key: string, catalogue: string, locale: string, value: string, scope: string, where: string}>, skipped: list<OverrideImportIssue>}
     */
    private function parse(string $content, string $format): array
    {
        return match ($format) {
            'xlf' => $this->fromXliff($content),
            'csv' => $this->fromCsv($content),
            default => throw new \InvalidArgumentException(\sprintf('Unknown import format "%s" (supported: %s).', $format, implode(', ', OverrideExporter::FORMATS))),
        };
    }

    /**
     * Flat table with a `locale,catalogue,translation_key,value[,scope]` header row.
     *
     * @return array{valid: list<array{key: string, catalogue: string, locale: string, value: string, scope: string, where: string}>, skipped: list<OverrideImportIssue>}
     */
    private function fromCsv(string $content): array
    {
        // What a spreadsheet saves: "CSV UTF-8" starts with a BOM, and a French-locale
        // Excel separates with ";". Any other encoding would only fail at the INSERT.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidImportFileException('The CSV file is not UTF-8 encoded — save it as "CSV UTF-8".');
        }

        $firstLine = strstr($content, "\n", true);
        $firstLine = false === $firstLine ? $content : $firstLine;
        $separator = str_contains($firstLine, ';') && !str_contains($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://temp', 'r+');
        \assert(false !== $handle);
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, null, $separator, escape: '');

        $normalizedHeader = \is_array($header) ? array_map(static fn (?string $cell): string => strtolower(trim((string) $cell)), $header) : [];

        // Three accepted layouts: the legacy 4-column, the 5-column with scope, and the
        // 6-column view snapshot whose read-only "original" column is simply ignored.
        $hasOriginal = ['locale', 'catalogue', 'translation_key', 'original', 'value', 'scope'] === $normalizedHeader;

        if (!$hasOriginal && !\in_array($normalizedHeader, [
            ['locale', 'catalogue', 'translation_key', 'value'],
            ['locale', 'catalogue', 'translation_key', 'value', 'scope'],
        ], true)) {
            fclose($handle);

            throw new InvalidImportFileException('The CSV file must start with a "locale,catalogue,translation_key[,original],value[,scope]" header row.');
        }

        $valueIndex = $hasOriginal ? 4 : 3;

        $valid = [];
        $skipped = [];
        // The header is row 1, as in the spreadsheet the file was edited in: a quoted
        // cell spanning several lines is still one row there.
        $rowNumber = 1;

        while (\is_array($row = fgetcsv($handle, null, $separator, escape: ''))) {
            $where = 'row '.++$rowNumber;

            if ([null] === $row) {
                continue; // blank line
            }

            if (\count($row) <= $valueIndex || null === $row[$valueIndex]) {
                $skipped[] = new OverrideImportIssue($where, trim((string) ($row[2] ?? '')), 'the value column is missing');

                continue;
            }

            if (\in_array('', [trim((string) $row[0]), trim((string) $row[1]), trim((string) $row[2])], true)) {
                $skipped[] = new OverrideImportIssue($where, trim((string) $row[2]), 'a locale, a catalogue and a key are required');

                continue;
            }

            // The export prefixes formula-looking cells with an apostrophe; strip it back
            // so a round trip returns the exact stored strings.
            $row = array_map(static fn (?string $cell): string => CsvFormulaGuard::strip((string) $cell), $row);
            $scope = trim($row[$valueIndex + 1] ?? '');

            if (\strlen($scope) > ScopeRegistry::MAX_SCOPE_LENGTH) {
                $skipped[] = new OverrideImportIssue($where, $row[2], \sprintf('the scope exceeds %d characters', ScopeRegistry::MAX_SCOPE_LENGTH));

                continue;
            }

            $valid[] = ['key' => $row[2], 'catalogue' => trim($row[1]), 'locale' => str_replace('-', '_', trim($row[0])), 'value' => $row[$valueIndex], 'scope' => $scope, 'where' => $where];
        }

        fclose($handle);

        return ['valid' => $valid, 'skipped' => $skipped];
    }

    /**
     * XLIFF 1.2 with one <file> per (locale, catalogue): the locale is the
     * `target-language`, the catalogue the `original` attribute, the key the
     * trans-unit `resname` (fallback: <source>).
     *
     * @return array{valid: list<array{key: string, catalogue: string, locale: string, value: string, scope: string, where: string}>, skipped: list<OverrideImportIssue>}
     */
    private function fromXliff(string $content): array
    {
        if (\strlen($content) > self::MAX_XML_BYTES) {
            throw new InvalidImportFileException(\sprintf('The XLIFF file is too large (max %d bytes).', self::MAX_XML_BYTES));
        }

        $document = new \DOMDocument();

        $previous = libxml_use_internal_errors(true);

        try {
            // External entities are never loaded: neither LIBXML_NOENT nor LIBXML_DTDLOAD is
            // passed. LIBXML_NONET additionally forbids any network access while parsing.
            if (!$document->loadXML($content, \LIBXML_NONET)) {
                $error = libxml_get_last_error();

                throw new InvalidImportFileException('Unable to parse the XLIFF file: '.trim(false !== $error ? $error->message : 'invalid XML'));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $valid = [];
        $skipped = [];

        foreach ($document->getElementsByTagNameNS('urn:oasis:names:tc:xliff:document:1.2', 'file') as $file) {
            // Translation tools write BCP 47 ("fr-FR"), Symfony locales use "_".
            $locale = str_replace('-', '_', $file->getAttribute('target-language'));
            $catalogue = $file->getAttribute('original');
            // The scope travels in the standard `category` attribute ('' = global).
            $scope = $file->getAttribute('category');

            $fileWhere = \sprintf('<file original="%s" target-language="%s">', $catalogue, $locale);

            if ('' === $locale || '' === $catalogue || \strlen($scope) > ScopeRegistry::MAX_SCOPE_LENGTH) {
                $skipped[] = new OverrideImportIssue($fileWhere, null, '' === $locale || '' === $catalogue
                    ? 'a <file> needs its "original" (the catalogue) and "target-language" attributes'
                    : \sprintf('the scope exceeds %d characters', ScopeRegistry::MAX_SCOPE_LENGTH));

                continue;
            }

            foreach ($file->getElementsByTagNameNS('urn:oasis:names:tc:xliff:document:1.2', 'trans-unit') as $unit) {
                $source = $unit->getElementsByTagNameNS('urn:oasis:names:tc:xliff:document:1.2', 'source')->item(0);
                $target = $unit->getElementsByTagNameNS('urn:oasis:names:tc:xliff:document:1.2', 'target')->item(0);

                $key = '' !== $unit->getAttribute('resname') ? $unit->getAttribute('resname') : $source?->textContent;

                $where = \sprintf('%s trans-unit %s', $fileWhere, '' !== $unit->getAttribute('id') ? '"'.$unit->getAttribute('id').'"' : 'without id');

                if (null === $key || '' === $key || null === $target) {
                    $skipped[] = new OverrideImportIssue($where, $key, 'a trans-unit needs a key (resname or source) and a <target>');

                    continue;
                }

                $valid[] = ['key' => $key, 'catalogue' => $catalogue, 'locale' => $locale, 'value' => $target->textContent, 'scope' => $scope, 'where' => $where];
            }
        }

        return ['valid' => $valid, 'skipped' => $skipped];
    }
}
