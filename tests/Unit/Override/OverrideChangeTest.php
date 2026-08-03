<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Override;

use CylleneDigital\AiTranslationBundle\Override\OverrideChange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverrideChangeTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?string, OverrideChange}> */
    public static function cases(): iterable
    {
        yield 'a new value' => ['Pilotage', 'Tableau de bord', null, OverrideChange::Write];
        yield 'a changed override' => ['Cockpit', 'Tableau de bord', 'Pilotage', OverrideChange::Write];
        yield 'a key the file does not have' => ['Bienvenue', null, null, OverrideChange::Write];
        yield 'the baseline, nothing stored' => ['Tableau de bord', 'Tableau de bord', null, OverrideChange::None];
        yield 'the baseline over an override' => ['Tableau de bord', 'Tableau de bord', 'Pilotage', OverrideChange::Revert];
        yield 'the stored override again' => ['Pilotage', 'Tableau de bord', 'Pilotage', OverrideChange::None];
    }

    #[DataProvider('cases')]
    public function testTheChangeAValueCalls(string $value, ?string $baseline, ?string $stored, OverrideChange $expected): void
    {
        self::assertSame($expected, OverrideChange::decide($value, $baseline, $stored));
    }
}
