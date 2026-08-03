<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Coverage;

use CylleneDigital\AiTranslationBundle\Coverage\LocaleCoverage;
use PHPUnit\Framework\TestCase;

final class LocaleCoverageTest extends TestCase
{
    /** One key short of 2500 must neither show 100 % nor pass a 100 % gate. */
    public function testThePercentageNeverRoundsUpToAnUnreachedFigure(): void
    {
        $coverage = new LocaleCoverage('fr', translated: 2499, missing: 1, pendingSuggestions: 0);

        self::assertSame(99.9, $coverage->getPercent());
        self::assertTrue($coverage->isBelow(100.0));
        self::assertFalse($coverage->isBelow(99.9));
        self::assertFalse((new LocaleCoverage('fr', 0, 0, 0))->isBelow(100.0));
    }
}
