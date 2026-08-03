<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Coverage;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Coverage\CoverageCalculator;
use CylleneDigital\AiTranslationBundle\Coverage\LocaleCoverage;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Override\AuthorProviderInterface;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CoverageCalculatorTest extends TestCase
{
    // Stub, not MockObject: most tests only need canned answers, and the few that
    // assert on calls reassign a full mock (a MockObject IS a Stub).
    private TranslationOverrideRepository&Stub $overrideRepository;

    private TranslationSuggestionRepository&Stub $suggestionRepository;

    protected function setUp(): void
    {
        $this->overrideRepository = $this->createStub(TranslationOverrideRepository::class);
        $this->overrideRepository->method('findForRuntime')->willReturn([]);

        $this->suggestionRepository = $this->createStub(TranslationSuggestionRepository::class);
        $this->suggestionRepository->method('countPendingFiltered')->willReturn(0);
    }

    public function testDefaultLocaleDefaultsToTheFirstAvailableLocaleWhenTheFrameworkDefaultMatchesNone(): void
    {
        self::assertSame('fr_FR', $this->createCalculator()->getDefaultLocale());
    }

    public function testDefaultLocalePrefersTheFrameworkDefaultWhenItIsAvailable(): void
    {
        self::assertSame('en_US', $this->createCalculator(frameworkDefaultLocale: 'en_US')->getDefaultLocale());
    }

    public function testDefaultLocaleMatchesTheFrameworkDefaultByPrefix(): void
    {
        // framework.default_locale is usually the short form: "en" must pick en_US.
        self::assertSame('en_US', $this->createCalculator(frameworkDefaultLocale: 'en')->getDefaultLocale());
    }

    public function testDefaultLocaleFallsBackToTheFrameworkDefaultWithoutAnyTranslationFile(): void
    {
        self::assertSame('fr', $this->createCalculator(frameworkDefaultLocale: 'fr', availableLocales: [])->getDefaultLocale());
    }

    public function testConfiguredDefaultLocaleWins(): void
    {
        self::assertSame('en_US', $this->createCalculator(configuredDefaultLocale: 'en_US')->getDefaultLocale());
    }

    public function testCoverageAgainstTheFrenchDefaultLocale(): void
    {
        // fr_FR carries 4 translatable keys (app.only_in_french, shop_level,
        // product.add_to_cart, review.title); none of them exists in the en/en_US files.
        $report = $this->createCalculator()->compute();

        self::assertSame('fr_FR', $report->defaultLocale);
        $coverage = $report->getLocale('en_US');
        self::assertNotNull($coverage);
        self::assertSame(0, $coverage->translated);
        self::assertSame(4, $coverage->missing);
        self::assertSame(0.0, $coverage->getPercent());
        self::assertFalse($coverage->isComplete());
    }

    public function testAnOverrideCountsAsTranslated(): void
    {
        $override = new TranslationOverride('product.add_to_cart', 'shop/Product/messages', 'en_US');
        $override->setValue('Add to cart (customised)');

        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('findForRuntime')->willReturnCallback(
            static fn (string $locale): array => 'en_US' === $locale ? [$override] : [],
        );
        $this->overrideRepository = $repository;

        $coverage = $this->createCalculator()->compute()->getLocale('en_US');

        self::assertNotNull($coverage);
        self::assertSame(1, $coverage->translated);
        self::assertSame(3, $coverage->missing);
        self::assertSame(25.0, $coverage->getPercent());
    }

    public function testAScopedReportSeesTheScopedOverridesOnTopOfTheGlobalOnes(): void
    {
        $global = (new TranslationOverride('product.add_to_cart', 'shop/Product/messages', 'en_US'))->setValue('Add to cart');
        $scoped = (new TranslationOverride('review.title', 'shop/Product/Review/messages', 'en_US', 'b2b'))->setValue('Avis clients (B2B)');

        $repository = $this->createStub(TranslationOverrideRepository::class);
        // findForRuntime() answers with the global rows plus, when a scope is active,
        // that scope's own — exactly what the runtime translator reads.
        $repository->method('findForRuntime')->willReturnCallback(
            static fn (string $locale, string $scope): array => match (true) {
                'en_US' !== $locale => [],
                'b2b' === $scope => [$global, $scoped],
                default => [$global],
            },
        );
        $this->overrideRepository = $repository;
        $calculator = $this->createCalculator();

        // Globally: the scoped override is invisible.
        $coverage = $calculator->compute()->getLocale('en_US');
        self::assertNotNull($coverage);
        self::assertSame(1, $coverage->translated);
        self::assertSame(3, $coverage->missing);

        // For b2b: the inherited global override AND the scoped one count.
        $coverage = $calculator->compute(scope: 'b2b')->getLocale('en_US');
        self::assertNotNull($coverage);
        self::assertSame(2, $coverage->translated);
        self::assertSame(2, $coverage->missing);
    }

    public function testTheLocaleRestrictionNarrowsTheTargets(): void
    {
        $calculator = $this->createCalculator();

        // An unknown locale is ignored, not reported as empty.
        $report = $calculator->compute(locales: ['en_US', 'de_DE']);
        self::assertSame(['en_US'], array_map(static fn (LocaleCoverage $c): string => $c->locale, $report->locales));

        // The default locale alone leaves nothing to report.
        self::assertSame([], $calculator->compute(locales: ['fr_FR'])->locales);
    }

    public function testPendingSuggestionsAreCountedPerScope(): void
    {
        $this->suggestionRepository = $this->createStub(TranslationSuggestionRepository::class);
        $this->suggestionRepository->method('countPendingFiltered')
            ->willReturnCallback(static fn (?string $locale, ?string $catalogue, ?string $search, ?string $scope = null): int => 'b2b' === $scope ? 3 : 1);
        $calculator = $this->createCalculator();

        self::assertSame(1, $calculator->compute()->getLocale('en_US')?->pendingSuggestions);
        self::assertSame(3, $calculator->compute(scope: 'b2b')->getLocale('en_US')?->pendingSuggestions);
    }

    public function testTheReportCacheIsPerScope(): void
    {
        $this->suggestionRepository = $this->createMock(TranslationSuggestionRepository::class);
        // Two distinct scopes → two computations; the repeats hit the cache.
        $this->suggestionRepository->expects(self::exactly(2))->method('countPendingFiltered')->willReturn(0);
        $calculator = $this->createCalculator(cacheTtl: 300);

        $calculator->getReport();
        $calculator->getReport('b2b');
        $calculator->getReport();
        $calculator->getReport('b2b');
    }

    public function testExplicitDefaultLocaleReversesThePerspective(): void
    {
        // en_US as default locale: app.a, app.b, dashboard.title and checkout.pay — none of them
        // is translated in fr_FR.
        $report = $this->createCalculator()->compute('en_US');

        $coverage = $report->getLocale('fr_FR');
        self::assertNotNull($coverage);
        self::assertSame(0, $coverage->translated);
        self::assertSame(4, $coverage->missing);
    }

    public function testPendingSuggestionsAreReported(): void
    {
        $this->suggestionRepository = $this->createStub(TranslationSuggestionRepository::class);
        $this->suggestionRepository->method('countPendingFiltered')
            ->willReturnCallback(static fn (?string $locale): int => 'en_US' === $locale ? 2 : 0);

        $coverage = $this->createCalculator()->compute()->getLocale('en_US');

        self::assertNotNull($coverage);
        self::assertSame(2, $coverage->pendingSuggestions);
    }

    public function testDebugModeRecomputesOnEveryCall(): void
    {
        $this->suggestionRepository = $this->createMock(TranslationSuggestionRepository::class);
        // 2 getReport() calls × 1 target locale: without a cache the count runs twice.
        $this->suggestionRepository->expects(self::exactly(2))->method('countPendingFiltered')->willReturn(0);

        $calculator = $this->createCalculator(debug: true);
        $calculator->getReport();
        $calculator->getReport();
    }

    public function testAnExplicitTtlCachesEvenInDebug(): void
    {
        $this->suggestionRepository = $this->createMock(TranslationSuggestionRepository::class);
        $this->suggestionRepository->expects(self::once())->method('countPendingFiltered')->willReturn(0);

        $calculator = $this->createCalculator(cacheTtl: 300, debug: true);
        $calculator->getReport();
        $calculator->getReport();
    }

    public function testMissingKeysAreCollectedOnlyOnDemand(): void
    {
        $calculator = $this->createCalculator();

        self::assertSame([], $calculator->compute()->getLocale('en_US')?->missingKeys);

        $coverage = $calculator->compute(collectMissingKeys: true)->getLocale('en_US');
        self::assertNotNull($coverage);

        $missingKeys = $coverage->missingKeys;
        ksort($missingKeys);
        self::assertSame([
            'messages' => ['app.only_in_french'],
            'shop/Product/Review/messages' => ['review.title'],
            'shop/Product/messages' => ['product.add_to_cart'],
            'shop/messages' => ['shop_level'],
        ], $missingKeys);
    }

    public function testDefaultLocaleKeysAreListedPerCatalogue(): void
    {
        $defaultKeys = $this->createCalculator()->getDefaultLocaleKeys();
        ksort($defaultKeys);

        self::assertSame([
            'messages' => ['app.only_in_french'],
            'shop/Product/Review/messages' => ['review.title'],
            'shop/Product/messages' => ['product.add_to_cart'],
            'shop/messages' => ['shop_level'],
        ], $defaultKeys);
    }

    public function testExtraKeysExposeTheDriftInvisibleToCoverage(): void
    {
        $calculator = $this->createCalculator();

        // Everything en_US carries is unknown to the fr_FR default locale — the mirror
        // of the explicit-default-locale report above, but with the keys named.
        $extra = $calculator->getExtraKeys('en_US');
        ksort($extra);
        self::assertSame([
            'admin/Dashboard/messages' => ['dashboard.title'],
            'messages' => ['app.a', 'app.b'],
            'shop/Checkout/checkout' => ['checkout.pay'],
        ], $extra);

        // The default locale has nothing beyond itself.
        self::assertSame([], $calculator->getExtraKeys('fr_FR'));
    }

    public function testAStrayOverrideCountsAsExtra(): void
    {
        $override = (new TranslationOverride('app.stray', 'messages', 'en_US'))->setValue('Stray value');

        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('findForRuntime')->willReturnCallback(
            static fn (string $locale): array => 'en_US' === $locale ? [$override] : [],
        );
        $this->overrideRepository = $repository;

        self::assertContains('app.stray', $this->createCalculator()->getExtraKeys('en_US')['messages']);
    }

    /** @param list<string> $availableLocales */
    private function createCalculator(?string $configuredDefaultLocale = null, ?int $cacheTtl = null, bool $debug = false, string $frameworkDefaultLocale = 'de', array $availableLocales = ['fr_FR', 'en_US']): CoverageCalculator
    {
        $localeProvider = $this->createStub(LocaleProviderInterface::class);
        $localeProvider->method('getAvailableLocales')->willReturn($availableLocales);

        $author = $this->createStub(AuthorProviderInterface::class);
        $author->method('getAuthorIdentifier')->willReturn(null);

        $catalogues = new CatalogueRegistry(new TranslationFileScanner(\dirname(__DIR__, 2).'/Fixtures/translations'), $localeProvider);
        $overrides = new OverrideReader($this->overrideRepository, $catalogues);

        return new CoverageCalculator($catalogues, $overrides, $this->suggestionRepository, new ArrayAdapter(), $configuredDefaultLocale, $cacheTtl, $debug, $frameworkDefaultLocale);
    }
}
