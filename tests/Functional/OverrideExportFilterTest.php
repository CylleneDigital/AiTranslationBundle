<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Transfer\OverrideExporter;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideExportFilter;

/**
 * The narrowing of a filtered export, against a real database.
 *
 * It used to be a PHP filter over every hydrated row, which a unit test could cover with
 * a stubbed repository. Now that the criteria are DQL, only a real query proves them —
 * and the previous unit tests would have kept passing over an implementation nothing
 * calls any more.
 */
final class OverrideExportFilterTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $writer = $this->writer();
        $writer->save('app.dashboard', 'messages', 'fr', 'Tableau de bord');
        $writer->save('app.dashboard', 'messages', 'en', 'Dashboard');
        $writer->save('product.count', 'shop/Product/messages', 'fr', '{count, plural, one {# produit} other {# produits}}');
        $writer->save('app.dashboard', 'messages', 'fr', 'Tableau de bord B2B', scope: 'b2b');
    }

    public function testTheLocaleFilterNarrowsToThatLocale(): void
    {
        self::assertSame(
            [['fr', 'messages', 'app.dashboard', 'Tableau de bord', '']],
            $this->csv(new OverrideExportFilter(locale: 'fr', scope: '', catalogue: 'messages')),
        );
    }

    public function testAnEmptyScopeExportsTheGlobalOverridesOnly(): void
    {
        // '' and null are NOT the same criterion: '' means global, null means every scope.
        $scopes = array_column($this->csv(new OverrideExportFilter(scope: '')), 4);

        self::assertSame([''], array_values(array_unique($scopes)));
    }

    public function testANamedScopeExportsThatScopeOnly(): void
    {
        self::assertSame(
            [['fr', 'messages', 'app.dashboard', 'Tableau de bord B2B', 'b2b']],
            $this->csv(new OverrideExportFilter(scope: 'b2b')),
        );
    }

    public function testTheCatalogueFilterAlsoTakesAPrefix(): void
    {
        // "shop" must take "shop/Product/messages" without taking "messages".
        $catalogues = array_column($this->csv(new OverrideExportFilter(catalogue: 'shop')), 1);

        self::assertSame(['shop/Product/messages'], $catalogues);
    }

    public function testTheCatalogueFilterDoesNotMatchAPartialSegment(): void
    {
        // "mess" is a prefix of the string "messages" but not of the catalogue path.
        self::assertSame([], $this->csv(new OverrideExportFilter(catalogue: 'mess')));
    }

    public function testTheSearchFilterMatchesKeysAndValuesCaseInsensitively(): void
    {
        self::assertSame(
            [['fr', 'messages', 'app.dashboard', 'Tableau de bord', '']],
            $this->csv(new OverrideExportFilter(locale: 'fr', scope: '', search: 'TABLEAU')),
        );

        self::assertSame(
            [['fr', 'shop/Product/messages', 'product.count', '{count, plural, one {# produit} other {# produits}}', '']],
            $this->csv(new OverrideExportFilter(scope: '', search: 'PRODUCT.COUNT')),
        );
    }

    public function testLikeWildcardsInTheFiltersAreLiteral(): void
    {
        // "_" and "%" would otherwise match any character: "sho_" took "shop/…", "%" took everything.
        self::assertSame([], $this->csv(new OverrideExportFilter(catalogue: 'sho_')));
        self::assertSame([], $this->csv(new OverrideExportFilter(scope: '', search: '%')));
        self::assertSame([], $this->csv(new OverrideExportFilter(scope: '', search: 'app_dashboard')));
    }

    public function testHasOverridesHonoursTheFilter(): void
    {
        $exporter = $this->exporter();

        self::assertTrue($exporter->hasOverrides());
        self::assertTrue($exporter->hasOverrides(new OverrideExportFilter(locale: 'fr')));
        self::assertFalse($exporter->hasOverrides(new OverrideExportFilter(locale: 'de')));
    }

    /**
     * @return list<list<string|null>> the CSV rows, header excluded
     */
    private function csv(OverrideExportFilter $filter): array
    {
        $lines = array_values(array_filter(explode("\n", $this->exporter()->export(OverrideExporter::FORMAT_CSV, $filter))));
        array_shift($lines);

        return array_map(static fn (string $line): array => str_getcsv($line, escape: ''), $lines);
    }

    private function exporter(): OverrideExporter
    {
        /** @var OverrideExporter $exporter */
        $exporter = self::getContainer()->get(OverrideExporter::class);

        return $exporter;
    }
}
