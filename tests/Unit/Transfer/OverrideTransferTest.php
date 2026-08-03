<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Transfer;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Override\AuthorProviderInterface;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Scope\NullScopeProvider;
use CylleneDigital\AiTranslationBundle\Scope\ScopeProviderInterface;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use CylleneDigital\AiTranslationBundle\Transfer\InvalidImportFileException;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideExporter;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideImporter;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideImportTemplates;
use CylleneDigital\AiTranslationBundle\Transfer\ViewExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class OverrideTransferTest extends TestCase
{
    /**
     * Deliberately hostile values: separators, quotes, newlines, accents, braces. The
     * catalogues and locales exist in the fixture files — an import refuses unknown ones.
     */
    private const array OVERRIDES = [
        'fr_FR' => [
            'messages' => [
                'app.dashboard' => 'Tableau de bord',
                'app.tricky' => "Une \"valeur\", avec ; des pièges\net un retour à la ligne",
            ],
            'shop/Product/messages' => [
                'product.count' => '{count} produit(s) | %count% {au total}',
            ],
        ],
        'en_US' => [
            'messages' => [
                'app.dashboard' => 'Dashboard',
            ],
        ],
    ];

    /** Scoped overrides travel with the file too (scope => locale => catalogue => key => value). */
    private const array SCOPED_OVERRIDES = [
        'b2b' => [
            'fr_FR' => [
                'messages' => [
                    'app.dashboard' => 'Tableau de bord B2B',
                ],
            ],
        ],
    ];

    private TranslationOverrideRepository&Stub $repository;

    /** @var list<array{string, string, string, string, string}> locale, catalogue, key, value, scope */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->repository = $this->createStub(TranslationOverrideRepository::class);
        $this->repository->method('findAllGrouped')->willReturn(self::OVERRIDES);
        $this->repository->method('findScopedGrouped')->willReturn(self::SCOPED_OVERRIDES);
        $this->repository->method('countOverrides')->willReturn(5);
        $this->repository->method('findOneByKey')->willReturn(null);

        $this->saved = [];
        // Imports now stage overrides through the batch API (saveDeferred + one flush).
        $this->repository
            ->method('saveDeferred')
            ->willReturnCallback(function (TranslationOverride $override): void {
                $this->saved[] = [$override->getLocale(), $override->getCatalogue(), $override->getKey(), $override->getValue(), $override->getScope()];
            });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formats(): iterable
    {
        foreach (OverrideExporter::FORMATS as $format) {
            yield $format => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testEveryFormatRoundTrips(string $format): void
    {
        $exporter = new OverrideExporter($this->repository);
        $importer = $this->createImporter();

        $result = $importer->import($exporter->export($format), $format);

        self::assertSame(0, $result->skipped);
        self::assertSame(5, $result->imported);

        $expected = [];
        foreach (self::OVERRIDES as $locale => $catalogues) {
            foreach ($catalogues as $catalogue => $keys) {
                foreach ($keys as $key => $value) {
                    $expected[] = [$locale, $catalogue, $key, $value, ''];
                }
            }
        }

        foreach (self::SCOPED_OVERRIDES as $scope => $locales) {
            foreach ($locales as $locale => $catalogues) {
                foreach ($catalogues as $catalogue => $keys) {
                    foreach ($keys as $key => $value) {
                        $expected[] = [$locale, $catalogue, $key, $value, $scope];
                    }
                }
            }
        }

        // Order is not part of the contract.
        sort($expected);
        sort($this->saved);
        self::assertSame($expected, $this->saved);
    }

    public function testABcp47LocaleWrittenByATranslationToolIsAccepted(): void
    {
        // Trados, memoQ or Phrase write "fr-FR" where Symfony uses "fr_FR".
        $xliff = <<<'XLF'
            <?xml version="1.0" encoding="utf-8"?>
            <xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
              <file source-language="en-US" target-language="fr-FR" datatype="plaintext" original="messages">
                <body>
                  <trans-unit id="1" resname="app.xliff"><source>app.xliff</source><target>Depuis XLIFF</target></trans-unit>
                </body>
              </file>
            </xliff>
            XLF;

        $this->createImporter()->import($xliff, 'xlf');
        $this->createImporter()->import("locale,catalogue,translation_key,value\nfr-FR,messages,app.csv,Depuis CSV\n", 'csv');

        self::assertSame([
            ['fr_FR', 'messages', 'app.xliff', 'Depuis XLIFF', ''],
            ['fr_FR', 'messages', 'app.csv', 'Depuis CSV', ''],
        ], $this->saved);
    }

    public function testCsvNeutralisesSpreadsheetFormulasAndTheImportRestoresThem(): void
    {
        // The export is opened in Excel/LibreOffice by a translator: a value starting
        // with "=" would be executed there, so it travels prefixed — and comes back
        // byte for byte through the importer.
        $csv = (new ViewExporter())->export('csv', [
            ['catalogue' => 'messages', 'key' => 'app.formula', 'original' => null, 'value' => '=HYPERLINK("http://evil.example","clic")'],
        ], 'fr_FR');

        self::assertStringContainsString("'=HYPERLINK", $csv);

        $this->createImporter()->import($csv, 'csv');

        self::assertSame([['fr_FR', 'messages', 'app.formula', '=HYPERLINK("http://evil.example","clic")', '']], $this->saved);
    }

    public function testEveryCellIsNeutralisedNotOnlyTheValue(): void
    {
        // An additional root's catalogue starts with "@", and nothing stops a key from
        // starting with "=": both are formulas to a spreadsheet.
        $csv = (new ViewExporter())->export('csv', [
            ['catalogue' => '@theme/messages', 'key' => '=SUM(A1)', 'original' => '-1', 'value' => 'ok'],
        ], 'fr_FR', '+scope');

        self::assertStringContainsString("fr_FR,'@theme/messages,'=SUM(A1),'-1,ok,'+scope", $csv);
    }

    public function testEscapedKeysAndApostrophesComeBackByteForByte(): void
    {
        $csv = (new ViewExporter())->export('csv', [
            ['catalogue' => 'messages', 'key' => '=SUM(A1)', 'original' => null, 'value' => "'=x"],
        ], 'fr_FR');

        $this->createImporter()->import($csv, 'csv');

        self::assertSame([['fr_FR', 'messages', '=SUM(A1)', "'=x", '']], $this->saved);
    }

    public function testAValueThatLegitimatelyStartsWithAnApostropheSurvivesTheRoundTrip(): void
    {
        $csv = (new ViewExporter())->export('csv', [
            ['catalogue' => 'messages', 'key' => 'app.quote', 'original' => null, 'value' => "'Bonjour', dit-il"],
        ], 'fr_FR');

        $this->createImporter()->import($csv, 'csv');

        self::assertSame([['fr_FR', 'messages', 'app.quote', "'Bonjour', dit-il", '']], $this->saved);
    }

    public function testImportSkipsValuesWhoseSyntaxDoesNotMatchTheCatalogue(): void
    {
        // The validator needs the real files to know the catalogue's declared format.
        [$catalogues, $overrides, $scopes] = $this->createServices();
        $importer = new OverrideImporter($catalogues, $overrides, $scopes, $this->createWriter($this->authorStub()), new TranslationValueValidator($catalogues));

        // The fixtures' "messages" catalogue is legacy for fr_FR: an ICU plural is refused.
        $csv = "locale,catalogue,translation_key,value\nfr_FR,messages,app.bad,\"{count, plural, one {# a} other {# b}}\"\nfr_FR,messages,app.ok,\"Bonjour\"\n";
        $result = $importer->import($csv, 'csv', dryRun: true);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->skipped);
    }

    public function testDryRunCountsWithoutWriting(): void
    {
        $exporter = new OverrideExporter($this->repository);
        $importer = $this->createImporter();

        $result = $importer->import($exporter->export('csv'), 'csv', dryRun: true);

        self::assertSame(5, $result->imported);
        self::assertSame([], $this->saved);
    }

    public function testStructurallyInvalidCsvRowsAreSkippedNotFatal(): void
    {
        $importer = $this->createImporter();

        // A row without a locale, then a row too short to carry a value.
        $result = $importer->import(
            "locale,catalogue,translation_key,value,scope\nfr_FR,messages,app.ok,Valide,\n,messages,app.bad,Sans locale,\nfr_FR,messages\n",
            'csv',
        );

        self::assertSame(1, $result->imported);
        self::assertSame(2, $result->skipped);
        self::assertSame([['fr_FR', 'messages', 'app.ok', 'Valide', '']], $this->saved);
    }

    public function testStructurallyInvalidXliffUnitsAreSkippedNotFatal(): void
    {
        $importer = $this->createImporter();

        // A <file> without target-language, then a <trans-unit> without <target>.
        $result = $importer->import(<<<'XLF'
            <?xml version="1.0" encoding="utf-8"?>
            <xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
              <file source-language="fr_FR" datatype="plaintext" original="messages">
                <body>
                  <trans-unit id="1" resname="app.no_locale"><source>app.no_locale</source><target>Perdu</target></trans-unit>
                </body>
              </file>
              <file source-language="fr_FR" target-language="fr_FR" datatype="plaintext" original="messages">
                <body>
                  <trans-unit id="1" resname="app.ok"><source>app.ok</source><target>Valide</target></trans-unit>
                  <trans-unit id="2" resname="app.no_target"><source>app.no_target</source></trans-unit>
                </body>
              </file>
            </xliff>
            XLF, 'xlf');

        self::assertSame(1, $result->imported);
        self::assertSame(2, $result->skipped);
        self::assertSame([['fr_FR', 'messages', 'app.ok', 'Valide', '']], $this->saved);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function droppedFormats(): iterable
    {
        yield 'yaml' => ['yaml'];
        yield 'json' => ['json'];
    }

    /** YAML and JSON transfers were removed: neither side may silently keep accepting them. */
    #[DataProvider('droppedFormats')]
    public function testDroppedFormatsAreRejectedByBothSides(string $format): void
    {
        $exporter = new OverrideExporter($this->repository);
        $importer = $this->createImporter();

        self::assertFalse($exporter->supports($format));
        self::assertNull($importer->guessFormat('export.'.$format));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown import format');

        $importer->import('', $format);
    }

    public function testCsvRequiresTheHeaderRow(): void
    {
        $importer = $this->createImporter();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('header row');

        $importer->import("fr_FR,messages,app.key,Valeur\n", 'csv');
    }

    public function testInvalidXmlIsRejected(): void
    {
        $importer = $this->createImporter();

        $this->expectException(InvalidImportFileException::class);
        $this->expectExceptionMessage('Unable to parse the XLIFF file');

        $importer->import('<xliff', 'xlf');
    }

    /**
     * The downloadable templates must stay importable as-is: every entry valid, nothing
     * skipped. A format drift (here or in the importer) fails the build, not the user.
     */
    #[DataProvider('formats')]
    public function testEveryImportTemplateImportsCleanly(string $format): void
    {
        $importer = $this->createImporter();

        $template = OverrideImportTemplates::get($format);
        self::assertIsString($template);

        $result = $importer->import($template, $format, dryRun: true);

        self::assertGreaterThan(0, $result->imported);
        self::assertSame(0, $result->skipped);
    }

    public function testUnknownTemplateFormatYieldsNull(): void
    {
        self::assertNull(OverrideImportTemplates::get('docx'));
    }

    public function testPreviewCountsWithoutWritingAndBreaksDownPerLocale(): void
    {
        $exporter = new OverrideExporter($this->repository);
        $importer = $this->createImporter();

        $preview = $importer->preview($exporter->export('xlf'), 'xlf');

        self::assertSame(5, $preview->importable);
        self::assertSame(0, $preview->skipped);
        // 3 global fr_FR + 1 scoped fr_FR (b2b) + 1 en_US.
        self::assertSame(['en_US' => 1, 'fr_FR' => 4], $preview->byLocale);
        self::assertSame([], $this->saved);
    }

    /** The fixture rows of a browser view: one overridden, one untouched, one missing. */
    private const array VIEW_ROWS = [
        ['catalogue' => 'shop/Product/messages', 'key' => 'product.add_to_cart', 'original' => 'Ajouter au panier', 'value' => 'Ajouter au panier (modifié)'],
        ['catalogue' => 'shop/Product/messages', 'key' => 'product.out_of_stock', 'original' => 'Rupture de stock', 'value' => 'Rupture de stock'],
        ['catalogue' => 'shop/Product/messages', 'key' => 'product.new', 'original' => null, 'value' => null],
    ];

    public function testTheViewExportCarriesEveryRowWithItsOriginal(): void
    {
        $exporter = new ViewExporter();

        $csv = $exporter->export('csv', self::VIEW_ROWS, 'fr_FR');
        self::assertStringContainsString('locale,catalogue,translation_key,original,value,scope', $csv);
        self::assertStringContainsString('fr_FR,shop/Product/messages,product.add_to_cart,"Ajouter au panier","Ajouter au panier (modifié)",', $csv);
        self::assertStringContainsString('product.new,,,', $csv);

        $xlf = $exporter->export('xlf', self::VIEW_ROWS, 'fr_FR');
        self::assertStringContainsString('<source>Ajouter au panier</source>', $xlf);
        self::assertStringContainsString('<target>Ajouter au panier (modifié)</target>', $xlf);
        // An untranslated row: the key stands in for the missing original, the target is empty.
        self::assertStringContainsString('<source>product.new</source>', $xlf);
        self::assertStringContainsString('<target></target>', $xlf);
    }

    public function testAScopedViewExportsUnderItsScope(): void
    {
        $rows = self::csvRows((new ViewExporter())->export('csv', self::VIEW_ROWS, 'fr_FR', 'b2b'));

        self::assertCount(3, $rows);
        self::assertSame(['b2b'], array_values(array_unique(array_column($rows, 5))));

        $xlf = (new ViewExporter())->export('xlf', self::VIEW_ROWS, 'fr_FR', 'b2b');
        self::assertStringContainsString('category="b2b"', $xlf);
    }

    /**
     * The point of the whole design: a view snapshot re-imports into exactly the
     * overrides it displayed — unchanged and empty values are ignored, not stored.
     */
    #[DataProvider('formats')]
    public function testAViewSnapshotReimportsOnlyTheActualChanges(string $format): void
    {
        [$catalogues, $overrides, $scopes] = $this->createServices();
        $importer = new OverrideImporter($catalogues, $overrides, $scopes, $this->createWriter($this->authorStub()), new TranslationValueValidator($catalogues));

        $fileValue = $catalogues->getOriginalValue('product.add_to_cart', 'shop/Product/messages', 'fr_FR');
        self::assertNotNull($fileValue);

        $frenchOnlyValue = $catalogues->getOriginalValue('app.only_in_french', 'messages', 'fr_FR');
        self::assertNotNull($frenchOnlyValue);

        $snapshot = (new ViewExporter())->export($format, [
            // Differs from the file value => becomes an override.
            ['catalogue' => 'shop/Product/messages', 'key' => 'product.add_to_cart', 'original' => $fileValue, 'value' => $fileValue.' (modifié)'],
            // Identical to the file value => ignored.
            ['catalogue' => 'messages', 'key' => 'app.only_in_french', 'original' => $frenchOnlyValue, 'value' => $frenchOnlyValue],
            // Untranslated row => ignored.
            ['catalogue' => 'shop/Product/messages', 'key' => 'product.new', 'original' => null, 'value' => null],
        ], 'fr_FR');

        $result = $importer->import($snapshot, $format, dryRun: true);

        self::assertSame(1, $result->imported);
        self::assertSame(2, $result->unchanged);
        self::assertSame(0, $result->skipped);
    }

    public function testASkippedXliffEntryIsNamedByItsFileAndTransUnit(): void
    {
        $xliff = <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
              <file source-language="en" target-language="fr-FR" datatype="plaintext" original="messages">
                <body>
                  <trans-unit id="u1" resname="app.no_target"><source>app.no_target</source></trans-unit>
                </body>
              </file>
            </xliff>
            XML;

        $result = $this->createImporter()->import($xliff, 'xlf', dryRun: true);

        self::assertSame(1, $result->skipped);
        self::assertCount(1, $result->issues);
        self::assertSame(
            '<file original="messages" target-language="fr_FR"> trans-unit "u1" — app.no_target: a trans-unit needs a key (resname or source) and a <target>',
            (string) $result->issues[0],
        );
    }

    public function testEmptyValuesAreIgnoredNotImported(): void
    {
        $importer = $this->createImporter();

        $result = $importer->import("locale,catalogue,translation_key,value,scope\nfr_FR,messages,app.empty,\"\",\n", 'csv', dryRun: true);

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->unchanged);
    }

    public function testAnEntryCarryingAnUndeclaredScopeIsSkipped(): void
    {
        // Importing it would store overrides no runtime lookup ever reads — the silent
        // lie ScopeOptionGuard refuses on the CLI, applied to files too.
        $scopeProvider = $this->createStub(ScopeProviderInterface::class);
        $scopeProvider->method('getAvailableScopes')->willReturn(['b2b' => 'B2B']);

        [$catalogues, $overrides, $scopes] = $this->createServices($scopeProvider);
        $importer = new OverrideImporter($catalogues, $overrides, $scopes, $this->createWriter($this->authorStub()), new TranslationValueValidator($catalogues));

        $result = $importer->import(
            "locale,catalogue,translation_key,value,scope\nfr_FR,messages,app.a,Valeur,b2b\nfr_FR,messages,app.b,Valeur,typo\n",
            'csv',
            dryRun: true,
        );

        self::assertSame(1, $result->imported, 'The declared scope goes through.');
        self::assertSame(1, $result->skipped, 'The undeclared one is counted, not written.');
    }

    public function testAScopedValueEqualToTheGlobalOverrideIsIgnored(): void
    {
        // Fresh stub: setUp() already pinned findOneByKey to null on the shared one.
        $this->repository = $this->createStub(TranslationOverrideRepository::class);
        // The baseline of a scoped entry is read once per (locale, scope), not once per row.
        $this->repository->method('findInheritableByKeys')->willReturn([
            ['key' => 'product.add_to_cart', 'catalogue' => 'shop/Product/messages', 'locale' => 'fr_FR', 'scope' => '', 'value' => 'Ajouter (global)'],
        ]);

        $importer = $this->createImporter();

        $result = $importer->import(
            "locale,catalogue,translation_key,value,scope\nfr_FR,shop/Product/messages,product.add_to_cart,\"Ajouter (global)\",b2b\n",
            'csv',
            dryRun: true,
        );

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->unchanged);
    }

    public function testTheSnapshotCsvLayoutIsAccepted(): void
    {
        $importer = $this->createImporter();

        $result = $importer->import(
            "locale,catalogue,translation_key,original,value,scope\nfr_FR,messages,app.hello,\"Bonjour\",\"Salut\",\n",
            'csv',
            dryRun: true,
        );

        self::assertSame(1, $result->imported);
        self::assertSame(0, $result->skipped);
    }

    /**
     * The file sets an overridden entry back to its file value: the stored override is
     * removed, not silently kept behind an "unchanged" count.
     */
    public function testAValueBackToTheFileValueRemovesTheStoredOverride(): void
    {
        $this->repository = $this->createStub(TranslationOverrideRepository::class);
        $stored = (new TranslationOverride('product.add_to_cart', 'shop/Product/messages', 'fr_FR'))->setValue('Ajouter !');
        $this->repository->method('findIndexedByKeys')->willReturn(['shop/Product/messages|product.add_to_cart' => $stored]);

        $removed = [];
        $this->repository->method('removeDeferred')->willReturnCallback(static function (TranslationOverride $override) use (&$removed): void {
            $removed[] = $override;
        });

        $csv = "locale,catalogue,translation_key,value\nfr_FR,shop/Product/messages,product.add_to_cart,Ajouter au panier\n";

        $dryRun = $this->createImporter()->import($csv, 'csv', dryRun: true);
        self::assertSame(1, $dryRun->reverted);
        self::assertSame(0, $dryRun->unchanged);
        self::assertSame([], $removed);

        $result = $this->createImporter()->import($csv, 'csv');
        self::assertSame(1, $result->reverted);
        self::assertSame([$stored], $removed);
    }

    /** Written values and reverted overrides land together, or not at all. */
    public function testAnImportWritesAndRemovesInASingleFlush(): void
    {
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $stored = (new TranslationOverride('product.add_to_cart', 'shop/Product/messages', 'fr_FR'))->setValue('Ajouter !');
        $repository->method('findIndexedByKeys')->willReturn(['shop/Product/messages|product.add_to_cart' => $stored]);
        $repository->expects(self::once())->method('saveDeferred');
        $repository->expects(self::once())->method('removeDeferred')->with($stored);
        $repository->expects(self::once())->method('flush');
        $this->repository = $repository;

        $result = $this->createImporter()->import(
            "locale,catalogue,translation_key,value\nfr_FR,shop/Product/messages,product.add_to_cart,Ajouter au panier\nfr_FR,shop/Product/messages,product.new_key,Nouveau\n",
            'csv',
        );

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->reverted);
    }

    /**
     * The same entry twice in a file, back to the file value first and changed after: the
     * last occurrence wins — never both a write and a removal of the same row.
     */
    public function testARepeatedEntryKeepsItsLastValue(): void
    {
        $this->repository = $this->createStub(TranslationOverrideRepository::class);
        $stored = (new TranslationOverride('product.add_to_cart', 'shop/Product/messages', 'fr_FR'))->setValue('Ajouter !');
        $this->repository->method('findIndexedByKeys')->willReturn(['shop/Product/messages|product.add_to_cart' => $stored]);

        $result = $this->createImporter()->import(
            "locale,catalogue,translation_key,value\nfr_FR,shop/Product/messages,product.add_to_cart,Ajouter au panier\nfr_FR,shop/Product/messages,product.add_to_cart,Mettre au panier\n",
            'csv',
            dryRun: true,
        );

        self::assertSame(1, $result->imported);
        self::assertSame(0, $result->reverted);
    }

    public function testAValueEqualToTheFileValueWithoutStoredOverrideStaysUnchanged(): void
    {
        $result = $this->createImporter()->import(
            "locale,catalogue,translation_key,value\nfr_FR,shop/Product/messages,product.add_to_cart,Ajouter au panier\n",
            'csv',
            dryRun: true,
        );

        self::assertSame(0, $result->reverted);
        self::assertSame(1, $result->unchanged);
    }

    /** What a French-locale Excel saves as "CSV UTF-8": a BOM and ";" separators. */
    public function testAnExcelCsvWithBomAndSemicolonsIsAccepted(): void
    {
        $result = $this->createImporter()->import(
            "\xEF\xBB\xBFlocale;catalogue;translation_key;value\nfr_FR;messages;app.hello;\"Salut, toi\"\n",
            'csv',
            dryRun: true,
        );

        self::assertSame(1, $result->imported);
        self::assertSame(0, $result->skipped);
    }

    public function testTheCsvExportStartsWithABomForSpreadsheets(): void
    {
        self::assertStringStartsWith("\xEF\xBB\xBFlocale,", (new OverrideExporter($this->repository))->export('csv'));
    }

    public function testANonUtf8CsvIsRefusedWithAnActionableMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV UTF-8');

        $this->createImporter()->import("locale,catalogue,translation_key,value\nfr_FR,messages,app.hello,\xE9t\xE9\n", 'csv', dryRun: true);
    }

    public function testUnknownLocalesAndCataloguesAreSkipped(): void
    {
        $result = $this->createImporter()->import(
            "locale,catalogue,translation_key,value\nde_DE,messages,app.hello,Salut\nfr_FR,messsages,app.hello,Salut\n",
            'csv',
            dryRun: true,
        );

        self::assertSame(0, $result->imported);
        self::assertSame(2, $result->skipped);
    }

    public function testGuessFormatMapsTheSupportedExtensions(): void
    {
        $importer = $this->createImporter();

        self::assertSame('xlf', $importer->guessFormat('export.xliff'));
        self::assertSame('xlf', $importer->guessFormat('export.XLF'));
        self::assertSame('xlf', $importer->guessFormat('export.xlf'));
        self::assertSame('csv', $importer->guessFormat('export.csv'));
        self::assertNull($importer->guessFormat('export.txt'));
    }

    /**
     * The data rows of a CSV export (header dropped), each as its raw cell list.
     *
     * @return list<list<string>>
     */
    private static function csvRows(string $csv): array
    {
        $lines = array_filter(explode("\n", $csv), static fn (string $line): bool => '' !== trim($line));
        array_shift($lines);

        return array_values(array_map(static fn (string $line): array => array_map('strval', str_getcsv($line, escape: '')), $lines));
    }

    /** The importer under test, over the fixture catalogues. */
    private function createImporter(): OverrideImporter
    {
        [$catalogues, $overrides, $scopes] = $this->createServices();

        return new OverrideImporter($catalogues, $overrides, $scopes, $this->createWriter($this->authorStub()), new TranslationValueValidator($catalogues));
    }

    private function authorStub(): AuthorProviderInterface
    {
        $author = $this->createStub(AuthorProviderInterface::class);
        $author->method('getAuthorIdentifier')->willReturn(null);

        return $author;
    }

    private function createWriter(AuthorProviderInterface $author): OverrideWriter
    {
        return new OverrideWriter(
            $this->repository,
            new TranslationCacheManager(new ArrayAdapter()),
            $author,
            new EventDispatcher(),
            $this->suggestionRepository(),
            $this->createStub(LocaleProviderInterface::class),
        );
    }

    /**
     * The services an importer needs, over the fixture catalogues unless told otherwise.
     *
     * @return array{CatalogueRegistry, OverrideReader, ScopeRegistry}
     */
    private function createServices(?ScopeProviderInterface $scopeProvider = null, ?string $translationsDir = null): array
    {
        $localeProvider = $this->createStub(LocaleProviderInterface::class);
        $localeProvider->method('getAvailableLocales')->willReturn(['fr_FR', 'en_US']);

        $catalogues = new CatalogueRegistry(
            new TranslationFileScanner($translationsDir ?? \dirname(__DIR__, 2).'/Fixtures/translations'),
            $localeProvider,
        );

        return [$catalogues, new OverrideReader($this->repository, $catalogues), new ScopeRegistry($scopeProvider ?? new NullScopeProvider())];
    }

    /** Transactions run their operation: the writer wraps every write in one. */
    private function suggestionRepository(): TranslationSuggestionRepository
    {
        $repository = $this->createStub(TranslationSuggestionRepository::class);
        $repository->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return $repository;
    }
}
