<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Transfer;

use CylleneDigital\AiTranslationBundle\Transfer\CsvFormulaGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard is one of the few genuinely security-relevant pieces of the bundle: a
 * translation is free text, written by a human or an AI, and the CSV export is opened in
 * a spreadsheet by a translator. It is covered end to end by the export tests; these
 * pin the two properties the round trip depends on — every trigger is neutralised, and
 * strip() is the exact inverse of escape().
 */
final class CsvFormulaGuardTest extends TestCase
{
    #[DataProvider('formulaTriggers')]
    public function testAValueStartingWithAFormulaTriggerIsPrefixed(string $value): void
    {
        self::assertSame("'".$value, CsvFormulaGuard::escape($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formulaTriggers(): iterable
    {
        yield 'equals' => ['=1+1'];
        yield 'hyperlink' => ['=HYPERLINK("http://evil.example","clic")'];
        yield 'plus' => ['+1'];
        // A perfectly ordinary translation ("- Aucun") is a formula to a spreadsheet.
        yield 'minus' => ['- Aucun'];
        yield 'at' => ['@SUM(A1)'];
        yield 'tab' => ["\tIndenté"];
        yield 'carriage return' => ["\rRetour"];
        // A value that already looks escaped: prefixed once more, so strip() removes
        // exactly the apostrophe the export added.
        yield 'apostrophe before a trigger' => ["'=x"];
        yield 'apostrophes before a trigger' => ["''-1"];
    }

    #[DataProvider('harmlessValues')]
    public function testAnOrdinaryValueIsLeftAlone(string $value): void
    {
        self::assertSame($value, CsvFormulaGuard::escape($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmlessValues(): iterable
    {
        yield 'plain text' => ['Rupture de stock'];
        yield 'empty' => [''];
        yield 'trigger not in first position' => ['Total = 12'];
        yield 'leading space' => [' =1+1'];
        yield 'already an apostrophe' => ["'Bonjour"];
    }

    #[DataProvider('formulaTriggers')]
    public function testStripUndoesEscapeExactly(string $value): void
    {
        self::assertSame($value, CsvFormulaGuard::strip(CsvFormulaGuard::escape($value)));
    }

    #[DataProvider('harmlessValues')]
    public function testStripUndoesEscapeExactlyForHarmlessValues(string $value): void
    {
        self::assertSame($value, CsvFormulaGuard::strip(CsvFormulaGuard::escape($value)));
    }

    /**
     * The half that makes the round trip lossless: an apostrophe a translation legitimately
     * starts with is not a prefix this guard added, and must survive the import.
     */
    public function testAnApostropheThatIsPartOfTheValueIsKept(): void
    {
        self::assertSame("'Bonjour", CsvFormulaGuard::strip("'Bonjour"));
        self::assertSame("'", CsvFormulaGuard::strip("'"));
        // Only an apostrophe followed by an actual trigger is one the export added.
        self::assertSame('=1+1', CsvFormulaGuard::strip("'=1+1"));
        self::assertSame("'=1+1", CsvFormulaGuard::strip("''=1+1"));
    }
}
