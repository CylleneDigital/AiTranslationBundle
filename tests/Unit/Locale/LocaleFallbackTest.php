<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Locale;

use CylleneDigital\AiTranslationBundle\Locale\LocaleFallback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleFallbackTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('chains')]
    public function testTheChainGoesFromTheLanguageDownToTheFullLocale(string $locale, array $expected): void
    {
        self::assertSame($expected, LocaleFallback::chain($locale));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function chains(): iterable
    {
        yield 'language only' => ['fr', ['fr']];
        yield 'language and region' => ['fr_FR', ['fr', 'fr_FR']];
        yield 'script and region' => ['zh_Hant_TW', ['zh', 'zh_Hant', 'zh_Hant_TW']];
        // The dash form is the same locale; the casing is NOT normalised, because the
        // chain feeds an SQL comparison against the stored locale codes.
        yield 'dash form' => ['fr-FR', ['fr', 'fr_FR']];
        yield 'casing preserved' => ['pt_br', ['pt', 'pt_br']];
    }

    public function testALocaleCoversItselfAndTheMoreSpecificOnesBelowIt(): void
    {
        self::assertTrue(LocaleFallback::covers('fr', 'fr'));
        self::assertTrue(LocaleFallback::covers('fr', 'fr_FR'));
        self::assertTrue(LocaleFallback::covers('zh_Hant', 'zh_Hant_TW'));
    }

    public function testAMoreSpecificLocaleDoesNotCoverItsParent(): void
    {
        self::assertFalse(LocaleFallback::covers('fr_FR', 'fr'));
    }

    public function testSiblingsNeverCoverEachOther(): void
    {
        self::assertFalse(LocaleFallback::covers('fr_BE', 'fr_FR'));
    }

    /** A segment is a segment, never a text prefix: "fr" must not reach "frs" (Saterland Frisian). */
    public function testASegmentIsNotATextPrefix(): void
    {
        self::assertFalse(LocaleFallback::covers('fr', 'frs'));
        self::assertFalse(LocaleFallback::covers('fr', 'frs_DE'));
    }

    /** Comparing is case-insensitive, the way the file scanner matches file names. */
    public function testCoverageIgnoresTheCasingOfTheCodes(): void
    {
        self::assertTrue(LocaleFallback::covers('FR', 'fr_fr'));
        self::assertTrue(LocaleFallback::covers('fr', 'fr-FR'));
    }

    public function testTheRankIsTheNumberOfSegments(): void
    {
        self::assertSame(1, LocaleFallback::rank('fr'));
        self::assertSame(2, LocaleFallback::rank('fr_FR'));
        self::assertSame(3, LocaleFallback::rank('zh_Hant_TW'));

        // What the merge orders rely on.
        self::assertGreaterThan(LocaleFallback::rank('fr'), LocaleFallback::rank('fr_FR'));
    }

    /** Degenerate input must not produce an empty chain the SQL IN clause would choke on. */
    public function testAnEmptyLocaleStillYieldsAChain(): void
    {
        self::assertSame([''], LocaleFallback::chain(''));
    }
}
