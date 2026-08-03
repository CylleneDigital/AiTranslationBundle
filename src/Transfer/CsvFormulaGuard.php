<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Neutralises the spreadsheet formula injection of the CSV exports.
 *
 * A translation is free text written by a human or an AI, and the export is opened in
 * Excel/LibreOffice by a translator: a value starting with "=", "+", "-", "@" or a
 * leading tab/carriage return is executed as a formula there ("=1+1", but also
 * "=HYPERLINK(...)" or a DDE call). Prefixing it with an apostrophe is the standard
 * defence — the spreadsheet then treats the cell as text.
 *
 * Every cell is concerned, not only the values: a catalogue of an additional root is
 * named "@label/…", and nothing stops a key from starting with "=".
 *
 * The prefix is part of the file, so the importer strips it back ({@see strip()}): a
 * cell exported and re-imported must come back byte for byte, which is what the
 * "export the view, translate it, import it back" workflow relies on.
 */
#[Exclude]
final class CsvFormulaGuard
{
    /**
     * A formula trigger in first position, or behind apostrophes: "'=x" is a legitimate
     * value too, and escaping it as well is what lets strip() remove exactly one
     * apostrophe without mistaking it for a prefix the export added.
     */
    private const string ESCAPED = "/^'*[=+\\-@\t\r]/";

    public static function escape(string $value): string
    {
        return 1 === preg_match(self::ESCAPED, $value) ? "'".$value : $value;
    }

    /** The exact inverse of {@see escape()} — never touches an apostrophe a value starts with legitimately. */
    public static function strip(string $value): string
    {
        return str_starts_with($value, "'") && 1 === preg_match(self::ESCAPED, substr($value, 1)) ? substr($value, 1) : $value;
    }
}
