<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Suggestion;

use CylleneDigital\AiTranslationBundle\Suggestion\PluralCategories;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('intl')]
final class PluralCategoriesTest extends TestCase
{
    public function testTheCategoriesAreTheTargetLanguages(): void
    {
        self::assertSame(['one', 'other'], PluralCategories::of('fr_FR', 'plural'));
        self::assertSame(['one', 'two', 'few', 'other'], PluralCategories::of('en_US', 'selectordinal'));
        self::assertSame(['one', 'few', 'many', 'other'], PluralCategories::of('pl_PL', 'plural'));
        // Categories only very large numbers reach (French "many" for millions) are left
        // out: a message counting items would be warned about them for nothing.
        self::assertNotContains('many', PluralCategories::of('fr_FR', 'plural'));
    }

    /** The French ordinal kept in English: ICU falls back to "other" — "2th", "3th". */
    public function testAnOrdinalMissingEnglishCategoriesIsReported(): void
    {
        self::assertSame(
            [['argument' => 'position', 'kind' => 'selectordinal', 'missing' => ['two', 'few']]],
            PluralCategories::missingIn('This is your {position, selectordinal, one {#st} other {#th}} purchase', 'en_US'),
        );
    }

    public function testExplicitBranchesCoveringACategoryAreEnough(): void
    {
        // English "one" is 1 alone: "=1" covers it.
        self::assertSame([], PluralCategories::missingIn('{count, plural, =1 {One item} other {# items}}', 'en_US'));
    }

    public function testANestedPluralIsCheckedToo(): void
    {
        self::assertSame(
            [['argument' => 'n', 'kind' => 'plural', 'missing' => ['few', 'many']]],
            PluralCategories::missingIn('{g, select, female {{n, plural, offset:1 one {# córka} other {# córek}}} other {Brak}}', 'pl_PL'),
        );
    }

    public function testACompleteMessageOrAnUnknownLocaleReportsNothing(): void
    {
        self::assertSame([], PluralCategories::missingIn('{position, selectordinal, one {#st} two {#nd} few {#rd} other {#th}}', 'en_US'));
        self::assertSame([], PluralCategories::missingIn('{count, plural, one {# item} other {# items}}', 'xx_YY'));
        self::assertSame([], PluralCategories::missingIn('No ICU at all, {name}.', 'en_US'));
    }
}
