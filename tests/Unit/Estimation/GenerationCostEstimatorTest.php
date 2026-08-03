<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Estimation;

use CylleneDigital\AiTranslationBundle\Bridge\DeeplProvider;
use CylleneDigital\AiTranslationBundle\Bridge\OpenAiProvider;
use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Entity\ScopeParameters;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Estimation\GenerationCostEstimator;
use CylleneDigital\AiTranslationBundle\Estimation\ModelPriceProvider;
use CylleneDigital\AiTranslationBundle\Override\AuthorProviderInterface;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use CylleneDigital\AiTranslationBundle\Provider\TranslationAiProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use CylleneDigital\AiTranslationBundle\Repository\GenerationLogRepository;
use CylleneDigital\AiTranslationBundle\Repository\ScopeParametersRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeParametersManager;
use CylleneDigital\AiTranslationBundle\Suggestion\GenerationLock;
use CylleneDigital\AiTranslationBundle\Suggestion\PlaceholderConsistencyChecker;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class GenerationCostEstimatorTest extends TestCase
{
    private const array LITELLM_SAMPLE = [
        'gpt-4o-mini' => ['input_cost_per_token' => 1.5e-07, 'output_cost_per_token' => 6e-07],
    ];

    private ?string $translationsDir = null;

    protected function tearDown(): void
    {
        if (null !== $this->translationsDir) {
            (new Filesystem())->remove($this->translationsDir);
            $this->translationsDir = null;
        }
    }

    public function testTheEstimateMeasuresTheScopeContextTheRunWouldSend(): void
    {
        $estimator = $this->createEstimator(['a' => 'Alpha', 'b' => 'Beta'], scopeContexts: ['b2b' => str_repeat('Long wholesale context. ', 40)]);

        $global = $estimator->estimate('messages', 'en_US', 'fr_FR', 'gpt');
        $scoped = $estimator->estimate('messages', 'en_US', 'fr_FR', 'gpt', scope: 'b2b');

        self::assertSame($global->keys, $scoped->keys);
        self::assertNotNull($global->inputTokens);
        self::assertNotNull($scoped->inputTokens);
        self::assertGreaterThan($global->inputTokens, $scoped->inputTokens, 'The scope context is part of the prompts being priced.');
    }

    public function testACostEstimatingProviderIsAskedWithThePreparedChunks(): void
    {
        $estimator = $this->createEstimator(['app.a' => 'Bonjour tout le monde', 'app.b' => 'Au revoir']);

        $estimate = $estimator->estimate('messages', 'en_US', 'fr_FR', 'gpt');

        self::assertSame(2, $estimate->keys);
        self::assertSame(1, $estimate->chunks);
        self::assertSame(mb_strlen('Bonjour tout le monde') + mb_strlen('Au revoir'), $estimate->characters);
        self::assertNotNull($estimate->inputTokens);
        self::assertNotNull($estimate->outputTokens);
        self::assertGreaterThan($estimate->outputTokens, $estimate->inputTokens, 'The real system prompt makes the input side heavier.');
        self::assertTrue($estimate->isPriced());
        self::assertSame('USD', $estimate->currency);
        self::assertEqualsWithDelta(
            $estimate->inputTokens * 1.5e-07 + $estimate->outputTokens * 6e-07,
            (float) $estimate->cost,
            1e-12,
        );
    }

    public function testDeeplAnswersWithTheAccountQuotaInsteadOfAPrice(): void
    {
        $estimator = $this->createEstimator(['app.a' => str_repeat('x', 1000)]);

        $estimate = $estimator->estimate('messages', 'en_US', 'fr_FR', 'deepl');

        self::assertNull($estimate->inputTokens);
        self::assertNull($estimate->outputTokens);
        self::assertFalse($estimate->isPriced());
        self::assertSame(1000, $estimate->characters);
        self::assertSame(380_000, $estimate->quotaRemaining);
        self::assertSame(500_000, $estimate->quotaLimit);
    }

    public function testAProviderWithoutTheCapabilityDegradesToVolumesOnly(): void
    {
        $estimator = $this->createEstimator(['app.a' => 'Bonjour']);

        $estimate = $estimator->estimate('messages', 'en_US', 'fr_FR', 'basic');

        self::assertSame(1, $estimate->keys);
        self::assertSame(1, $estimate->chunks);
        self::assertNull($estimate->inputTokens);
        self::assertFalse($estimate->isPriced());
        self::assertNull($estimate->quotaRemaining);
    }

    public function testNullCatalogueSumsEveryCatalogueAndNullProviderFallsBackToTheDefault(): void
    {
        $estimator = $this->createEstimator(
            frMessages: ['app.a' => 'Bonjour'],
            frShopMessages: ['product.a' => 'Panier'],
        );

        $estimate = $estimator->estimate(null, 'en_US', 'fr_FR');

        self::assertSame('gpt', $estimate->provider);
        self::assertSame(2, $estimate->keys);
        self::assertSame(2, $estimate->chunks, 'One API call per catalogue.');
        self::assertTrue($estimate->isPriced());
    }

    /** A host with one custom provider and no default_provider: the run uses it, so does the estimate. */
    public function testTheOnlyProviderIsTheDefaultLikeForTheRun(): void
    {
        $estimator = $this->createEstimator(['app.a' => 'Bonjour'], basicOnly: true);

        $estimate = $estimator->estimate('messages', 'en_US', 'fr_FR');

        self::assertSame('basic', $estimate->provider);
        self::assertSame(1, $estimate->keys);
    }

    public function testAnUnknownProviderStillGetsTheVolumes(): void
    {
        $estimator = $this->createEstimator(['app.a' => 'Bonjour']);

        $estimate = $estimator->estimate('messages', 'en_US', 'fr_FR', 'nope');

        self::assertSame('nope', $estimate->provider);
        self::assertSame(1, $estimate->keys);
        self::assertFalse($estimate->isPriced());
    }

    public function testNothingToTranslateYieldsZeroVolumesAndNoCost(): void
    {
        $estimator = $this->createEstimator([]);

        $estimate = $estimator->estimate('messages', 'en_US', 'fr_FR', 'gpt');

        self::assertSame(0, $estimate->keys);
        self::assertSame(0, $estimate->chunks);
        self::assertNull($estimate->inputTokens);
        self::assertFalse($estimate->isPriced());
    }

    public function testTheEstimateFollowsTheScopeSelection(): void
    {
        // A b2b override already covers "b" in en_US: the scoped run has one key less.
        $scoped = (new TranslationOverride('b', 'messages', 'en_US', 'b2b'))->setValue('B for B2B');
        $estimator = $this->createEstimator(
            ['a' => 'Alpha', 'b' => 'Beta'],
            overrides: static fn (string $locale, string $catalogue, string $scope = ''): array => 'en_US' === $locale && 'b2b' === $scope ? [$scoped] : [],
        );

        self::assertSame(2, $estimator->estimate('messages', 'en_US', 'fr_FR', 'basic')->keys);
        self::assertSame(1, $estimator->estimate('messages', 'en_US', 'fr_FR', 'basic', scope: 'b2b')->keys);
    }

    public function testTheEstimateStopsAtThePerRunCapLikeTheRunDoes(): void
    {
        $messages = [];
        for ($i = 0; $i < SuggestionGenerator::MAX_KEYS_PER_RUN + 20; ++$i) {
            $messages['key_'.$i] = 'Value '.$i;
        }

        $estimate = $this->createEstimator($messages)->estimate('messages', 'en_US', 'fr_FR', 'gpt');

        // Quoting 520 keys for a run that translates 500 would overstate the bill and
        // hide the fact that a second run is needed.
        self::assertSame(SuggestionGenerator::MAX_KEYS_PER_RUN, $estimate->keys);
        self::assertSame(20, $estimate->keysBeyondCap);
        self::assertTrue($estimate->isCapped());
    }

    /**
     * @param array<string, string> $frMessages
     * @param array<string, string> $frShopMessages
     * @param array<string, string> $scopeContexts  scope => prompt context
     */
    private function createEstimator(array $frMessages, array $frShopMessages = [], ?callable $overrides = null, array $scopeContexts = [], bool $basicOnly = false): GenerationCostEstimator
    {
        $this->translationsDir = sys_get_temp_dir().'/cyllene_estimator_test_'.bin2hex(random_bytes(4));
        mkdir($this->translationsDir.'/shop/Product', 0o777, true);
        file_put_contents($this->translationsDir.'/messages.fr_FR.json', json_encode($frMessages, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT));
        file_put_contents($this->translationsDir.'/messages.en_US.json', '{}');
        if ([] !== $frShopMessages) {
            file_put_contents($this->translationsDir.'/shop/Product/messages.fr_FR.json', json_encode($frShopMessages, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT));
        }

        $localeProvider = $this->createStub(LocaleProviderInterface::class);
        $localeProvider->method('getAvailableLocales')->willReturn(['fr_FR', 'en_US']);

        $author = $this->createStub(AuthorProviderInterface::class);
        $author->method('getAuthorIdentifier')->willReturn(null);

        $overrideRepository = $this->createStub(TranslationOverrideRepository::class);
        $overrideRepository->method('findByLocaleAndCatalogue')->willReturnCallback($overrides ?? static fn (): array => []);

        $suggestionRepository = $this->createStub(TranslationSuggestionRepository::class);
        $suggestionRepository->method('findPending')->willReturn([]);

        $writer = new OverrideWriter(
            $overrideRepository,
            new TranslationCacheManager(new ArrayAdapter()),
            $author,
            new EventDispatcher(),
            $this->createStub(TranslationSuggestionRepository::class),
            $this->createStub(LocaleProviderInterface::class),
        );

        $catalogues = new CatalogueRegistry(new TranslationFileScanner($this->translationsDir), $localeProvider);
        $overrideReader = new OverrideReader($overrideRepository, $catalogues);

        $llmProvider = new OpenAiProvider(
            new MockHttpClient(), // never called during estimation
            'gpt',
            'sk-test',
            priceProvider: new ModelPriceProvider(
                new MockHttpClient(new JsonMockResponse(self::LITELLM_SAMPLE)),
                new ArrayAdapter(),
                new NullLogger(),
            ),
        );

        $deeplProvider = new DeeplProvider(
            new MockHttpClient(new JsonMockResponse(['character_count' => 120_000, 'character_limit' => 500_000])),
            'deepl',
            'key-123',
        );

        // A backend without the cost-estimating capability (like a custom provider).
        $basicProvider = new class implements TranslationAiProviderInterface {
            public function getName(): string
            {
                return 'basic';
            }

            public function translate(array $texts, string $sourceLocale, string $targetLocale, string $catalogue): array
            {
                return [];
            }
        };

        $registry = $basicOnly
            ? new TranslationProviderRegistry([$basicProvider], null)
            : new TranslationProviderRegistry([$llmProvider, $deeplProvider, $basicProvider], 'gpt');

        $scopeParametersRepository = $this->createStub(ScopeParametersRepository::class);
        $scopeParametersRepository->method('find')->willReturnCallback(
            static fn (string $scope): ?ScopeParameters => isset($scopeContexts[$scope]) ? (new ScopeParameters($scope))->setPromptContext($scopeContexts[$scope]) : null,
        );

        $aiService = new SuggestionGenerator(
            $registry,
            $overrideReader,
            $writer,
            $suggestionRepository,
            $this->createStub(GenerationLogRepository::class),
            new PlaceholderConsistencyChecker(),
            new ScopeParametersManager($scopeParametersRepository),
            new TranslationValueValidator($catalogues),
            new GenerationLock(new LockFactory(new InMemoryStore())),
            new EventDispatcher(),
        );

        return new GenerationCostEstimator($aiService, $catalogues);
    }
}
