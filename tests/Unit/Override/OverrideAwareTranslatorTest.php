<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Override;

use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Locale\LocaleFallback;
use CylleneDigital\AiTranslationBundle\Override\OverrideAwareTranslator;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Scope\NullScopeProvider;
use CylleneDigital\AiTranslationBundle\Scope\ScopeProviderInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OverrideAwareTranslatorTest extends TestCase
{
    public function testAnOverrideWinsOverTheDecoratedTranslator(): void
    {
        $translator = $this->createTranslator(['app.greeting' => 'Bonjour tout le monde']);

        self::assertSame('Bonjour tout le monde', $translator->trans('app.greeting', [], null, 'fr_FR'));
    }

    public function testFallsBackToTheDecoratedTranslatorWhenNoOverrideExists(): void
    {
        $translator = $this->createTranslator([]);

        self::assertSame('inner:app.greeting', $translator->trans('app.greeting', [], null, 'fr_FR'));
    }

    public function testParametersAreAppliedToTheOverriddenMessage(): void
    {
        $translator = $this->createTranslator(['app.greeting' => 'Bonjour %name%']);

        self::assertSame('Bonjour Aymeric', $translator->trans('app.greeting', ['%name%' => 'Aymeric'], null, 'fr_FR'));
    }

    public function testParametersAreReplacedInOnePassLikeSymfonyDoes(): void
    {
        // A value containing another placeholder stays as is, as in the file value.
        $translator = $this->createTranslator(['app.pair' => 'a=%a% b=%b%']);
        $parameters = ['%a%' => '%b%', '%b%' => 'X'];

        self::assertSame(strtr('a=%a% b=%b%', $parameters), $translator->trans('app.pair', $parameters, null, 'fr_FR'));
        self::assertSame('a=%b% b=X', $translator->trans('app.pair', $parameters, null, 'fr_FR'));
    }

    public function testALegacyPluralOverrideSelectsTheRightBranch(): void
    {
        $translator = $this->createTranslator([
            'app.apples' => '{0} Aucune pomme|{1} Une pomme|]1,Inf[ %count% pommes',
        ]);

        self::assertSame('Aucune pomme', $translator->trans('app.apples', ['%count%' => 0], null, 'fr_FR'));
        self::assertSame('Une pomme', $translator->trans('app.apples', ['%count%' => 1], null, 'fr_FR'));
        self::assertSame('3 pommes', $translator->trans('app.apples', ['%count%' => 3], null, 'fr_FR'));
    }

    public function testASimplePipePluralOverrideSelectsTheRightBranch(): void
    {
        $translator = $this->createTranslator(['app.apples' => 'une pomme|%count% pommes']);

        self::assertSame('une pomme', $translator->trans('app.apples', ['%count%' => 1], null, 'fr_FR'));
        self::assertSame('4 pommes', $translator->trans('app.apples', ['%count%' => 4], null, 'fr_FR'));
    }

    #[RequiresPhpExtension('intl')]
    public function testAnIcuPluralOverrideIsFormattedByIntl(): void
    {
        $translator = $this->createTranslator([
            'app.apples' => '{count, plural, one {# pomme} other {# pommes}}',
        ]);

        self::assertSame('1 pomme', $translator->trans('app.apples', ['count' => 1], null, 'fr_FR'));
        self::assertSame('3 pommes', $translator->trans('app.apples', ['count' => 3], null, 'fr_FR'));
    }

    /**
     * The override of a key the files declare in a "+intl-icu" domain is ICU, whatever
     * the call looks like: Symfony formats the file value with intl even for
     * "%count%"-style parameters (it strips the percent signs) and even without any
     * parameter (ICU quotes are unescaped) — the override must render the same way.
     */
    #[RequiresPhpExtension('intl')]
    public function testTheOverrideOfAnIcuKeyIsFormattedLikeTheFileValue(): void
    {
        $inner = new Translator('fr_FR');
        $inner->addLoader('array', new ArrayLoader());
        $inner->addResource('array', [
            'app.items' => '{count, plural, one {# objet} other {# objets}}',
            'app.quote' => "L''objet",
        ], 'fr_FR', 'messages+intl-icu');

        $items = new TranslationOverride('app.items', 'messages', 'fr_FR');
        $items->setValue('{count, plural, one {# article} other {# articles}}');
        $quote = new TranslationOverride('app.quote', 'messages', 'fr_FR');
        $quote->setValue("L''article");

        $translator = $this->createTranslatorForInner($inner, [$items, $quote]);

        self::assertSame('3 articles', $translator->trans('app.items', ['%count%' => 3], null, 'fr_FR'));
        self::assertSame('3 articles', $translator->trans('app.items', ['{count}' => 3], null, 'fr_FR'));
        self::assertSame("L'article", $translator->trans('app.quote', [], null, 'fr_FR'));
    }

    /** A broken ICU override must not take the page down: it is served as stored. */
    #[RequiresPhpExtension('intl')]
    public function testAMalformedIcuOverrideIsServedAsStored(): void
    {
        $inner = new Translator('fr_FR');
        $inner->addLoader('array', new ArrayLoader());
        $inner->addResource('array', ['app.items' => '{count, plural, one {# objet} other {# objets}}'], 'fr_FR', 'messages+intl-icu');

        $items = new TranslationOverride('app.items', 'messages', 'fr_FR');
        $items->setValue('{count, plural, one {# article}');

        $translator = $this->createTranslatorForInner($inner, [$items]);

        self::assertSame('{count, plural, one {# article}', $translator->trans('app.items', ['%count%' => 3], null, 'fr_FR'));
    }

    public function testALiteralBraceIsNotMistakenForIcu(): void
    {
        // "{votre code}" references no parameter: the text must survive untouched,
        // with the legacy-style placeholder still replaced.
        $translator = $this->createTranslator(['app.hint' => 'Utilisez {votre code}, %name%']);

        self::assertSame('Utilisez {votre code}, Aymeric', $translator->trans('app.hint', ['%name%' => 'Aymeric'], null, 'fr_FR'));
    }

    public function testALiteralPipeWithoutCountStaysIntact(): void
    {
        $translator = $this->createTranslator(['app.choice' => 'Pommes | Poires au choix']);

        self::assertSame('Pommes | Poires au choix', $translator->trans('app.choice', ['%name%' => 'x'], null, 'fr_FR'));
    }

    public function testLocaleVariantsShareTheBaseLocaleOverrides(): void
    {
        $translator = $this->createTranslator(['app.greeting' => 'Bonjour']);

        self::assertSame('Bonjour', $translator->trans('app.greeting', [], null, 'fr_FR@special'));
    }

    public function testOverridesAreScopedByDomainAndDoNotCollideAcrossCatalogues(): void
    {
        // The SAME key is overridden in two catalogues of distinct Symfony domain
        // ("flashes" vs "messages"): each must only apply within its own domain.
        $flashes = new TranslationOverride('shared.key', 'admin/Dashboard/flashes', 'fr_FR');
        $flashes->setValue('Depuis flashes');
        $messages = new TranslationOverride('shared.key', 'messages', 'fr_FR');
        $messages->setValue('Depuis messages');

        $translator = $this->createTranslatorForEntities([$flashes, $messages]);

        self::assertSame('Depuis flashes', $translator->trans('shared.key', [], 'flashes', 'fr_FR'));
        self::assertSame('Depuis messages', $translator->trans('shared.key', [], 'messages', 'fr_FR'));
        // A domain without an override for the key falls through to the inner translator.
        self::assertSame('inner:shared.key', $translator->trans('shared.key', [], 'validators', 'fr_FR'));
        // A null domain resolves to Symfony's default "messages" domain.
        self::assertSame('Depuis messages', $translator->trans('shared.key', [], null, 'fr_FR'));
    }

    public function testAScopedOverrideShadowsTheGlobalOneWhenItsScopeIsActive(): void
    {
        $global = new TranslationOverride('app.greeting', 'messages', 'fr_FR');
        $global->setValue('Bonjour');

        $scoped = new TranslationOverride('app.greeting', 'messages', 'fr_FR', 'b2b');
        $scoped->setValue('Bonjour (B2B)');

        // Without a scope provider, only the global override applies.
        self::assertSame('Bonjour', $this->createTranslatorForEntities([$global, $scoped])->trans('app.greeting', [], null, 'fr_FR'));

        // With the "b2b" scope active, the scoped override shadows the global one.
        $scopeProvider = new class implements ScopeProviderInterface {
            public function getScope(): string
            {
                return 'b2b';
            }

            public function getAvailableScopes(): array
            {
                return ['b2b' => 'B2B'];
            }
        };

        self::assertSame(
            'Bonjour (B2B)',
            $this->createTranslatorForEntities([$global, $scoped], $scopeProvider)->trans('app.greeting', [], null, 'fr_FR'),
        );
    }

    /** Two scope codes that only differ by a punctuation sign must not share a cache entry. */
    public function testScopesDifferingByPunctuationDoNotShareTheirCachedOverrides(): void
    {
        $scoped = new TranslationOverride('app.greeting', 'messages', 'fr_FR', 'FR/B2B');
        $scoped->setValue('Bonjour (B2B)');

        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('findForRuntime')->willReturnCallback(
            static fn (string $locale, string $scope): array => 'FR/B2B' === $scope ? [$scoped] : [],
        );

        // One pool, as cache.app is shared by every request.
        $pool = new ArrayAdapter();
        $translator = fn (string $scope): OverrideAwareTranslator => new OverrideAwareTranslator(
            $this->createInnerTranslator(),
            $repository,
            $pool,
            new TranslationCacheManager($pool),
            new class($scope) implements ScopeProviderInterface {
                public function __construct(private readonly string $scope)
                {
                }

                public function getScope(): string
                {
                    return $this->scope;
                }

                public function getAvailableScopes(): array
                {
                    return ['FR/B2B' => 'B2B', 'FR-B2B' => 'Other'];
                }
            },
        );

        self::assertSame('Bonjour (B2B)', $translator('FR/B2B')->trans('app.greeting', [], null, 'fr_FR'));
        self::assertSame('inner:app.greeting', $translator('FR-B2B')->trans('app.greeting', [], null, 'fr_FR'));
    }

    public function testAdditionalRootOverridesShadowMainRootOnesInTheSameDomain(): void
    {
        $app = new TranslationOverride('shared.key', 'shop/messages', 'fr_FR');
        $app->setValue('Depuis le projet');

        $theme = new TranslationOverride('shared.key', '@theme/shop/messages', 'fr_FR');
        $theme->setValue('Depuis le thème');

        // The main-root override is deliberately passed first: the precedence must not
        // depend on the repository (or collation) ordering.
        $translator = $this->createTranslatorForEntities([$app, $theme]);

        self::assertSame('Depuis le thème', $translator->trans('shared.key', [], null, 'fr_FR'));
    }

    /**
     * The regression this exists for: a project whose files are named "messages.fr.yaml"
     * only exposes "fr" as a browsable locale, so that is the locale its overrides are
     * saved under — while the application itself runs on "fr_FR". The file value WAS
     * served through the language chain; the override replacing it was not.
     */
    public function testAnOverrideOfTheParentLanguageAppliesToTheRegionalLocale(): void
    {
        $override = new TranslationOverride('app.greeting', 'messages', 'fr');
        $override->setValue('Bonjour depuis fr');

        $translator = $this->createTranslatorForStoredRows([$override]);

        self::assertSame('Bonjour depuis fr', $translator->trans('app.greeting', [], null, 'fr_FR'));
    }

    public function testTheRegionalOverrideShadowsTheParentLanguageOne(): void
    {
        $parent = new TranslationOverride('app.greeting', 'messages', 'fr');
        $parent->setValue('Bonjour depuis fr');

        $regional = new TranslationOverride('app.greeting', 'messages', 'fr_FR');
        $regional->setValue('Bonjour depuis fr_FR');

        // The parent row is deliberately passed first: precedence must not depend on the
        // order the repository (or the collation) happens to return.
        $translator = $this->createTranslatorForStoredRows([$parent, $regional]);

        self::assertSame('Bonjour depuis fr_FR', $translator->trans('app.greeting', [], null, 'fr_FR'));
        // Seen from "fr" itself, the regional row is NOT in the chain.
        self::assertSame('Bonjour depuis fr', $translator->trans('app.greeting', [], null, 'fr'));
    }

    public function testAnOverrideOfASiblingRegionalLocaleDoesNotLeak(): void
    {
        $belgian = new TranslationOverride('app.greeting', 'messages', 'fr_BE');
        $belgian->setValue('Bonjour depuis fr_BE');

        $translator = $this->createTranslatorForStoredRows([$belgian]);

        self::assertSame('inner:app.greeting', $translator->trans('app.greeting', [], null, 'fr_FR'));
    }

    /**
     * Writing on "fr" must also drop the "fr_FR" map: that entry was built FROM the "fr"
     * rows, so leaving it in place would serve the pre-write value for the rest of the
     * process (a console command or a worker never calls setLocale()).
     */
    public function testResettingAParentLanguageAlsoDropsItsRegionalMaps(): void
    {
        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('findForRuntime')->willReturn([]);

        $pool = new ArrayAdapter();
        $translator = new OverrideAwareTranslator(
            $this->createInnerTranslator(),
            $repository,
            $pool,
            new TranslationCacheManager($pool),
            new NullScopeProvider(),
        );

        $translator->trans('app.greeting', [], null, 'fr_FR');
        $translator->trans('app.greeting', [], null, 'fr_BE');
        $translator->trans('app.greeting', [], null, 'en_GB');

        $translator->resetOverrides('fr');

        $map = (new \ReflectionProperty(OverrideAwareTranslator::class, 'overridesCache'))->getValue($translator);
        self::assertIsArray($map);
        $loaded = array_keys($map);

        self::assertSame(['en_GB|'], $loaded);
    }

    /**
     * The translator runs inside every trans() of the application: an unreachable
     * database — a migration not yet run after a `composer require`, a failover — must
     * cost the overrides, not the page.
     */
    public function testAnUnreachableRepositoryDegradesToTheFileCatalogues(): void
    {
        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('findForRuntime')->willThrowException(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                Assert::assertIsString($level);
                $this->records[] = $level;
            }
        };

        $pool = new ArrayAdapter();
        $translator = new OverrideAwareTranslator(
            $this->createInnerTranslator(),
            $repository,
            $pool,
            new TranslationCacheManager($pool),
            new NullScopeProvider(),
            $logger,
        );

        self::assertSame('inner:app.greeting', $translator->trans('app.greeting', [], null, 'fr_FR'));
        // Loudly: the failure is degraded, never swallowed.
        self::assertSame(['error'], $logger->records);

        // And memoised — retrying on every trans() would turn one outage into thousands
        // of failed connections.
        self::assertSame('inner:app.other', $translator->trans('app.other', [], null, 'fr_FR'));
        self::assertSame(['error'], $logger->records);
    }

    public function testTheWarmUpIsDelegatedToTheDecoratedTranslator(): void
    {
        $inner = new class implements TranslatorInterface, WarmableInterface {
            /** @param array<string, mixed> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return 'inner:'.$id;
            }

            public function getLocale(): string
            {
                return 'fr_FR';
            }

            public function warmUp(string $cacheDir, ?string $buildDir = null): array
            {
                return [$cacheDir.'/catalogue.fr_FR.php'];
            }
        };

        $translator = $this->createTranslatorForInner($inner);

        // The framework's TranslationsCacheWarmer only warms up what it recognises as
        // warmable — the decorator must not swallow that capability.
        self::assertInstanceOf(WarmableInterface::class, $translator);
        self::assertSame(['/tmp/cache/catalogue.fr_FR.php'], $translator->warmUp('/tmp/cache'));
    }

    public function testTheWarmUpIsANoOpWhenTheDecoratedTranslatorCannotWarmUp(): void
    {
        self::assertSame([], $this->createTranslator([])->warmUp('/tmp/cache'));
    }

    /**
     * The in-memory map is the one cache layer TranslationCacheManager cannot reach, so
     * a value written mid-process stayed invisible until the map happened to be dropped
     * (setLocale(), i.e. the next HTTP request — never in a console or a worker).
     * resetOverrides() is what the TranslatorOverrideCacheListener calls on every
     * override change.
     */
    public function testResetOverridesSurfacesAValueWrittenAfterTheMapWasLoaded(): void
    {
        $written = new TranslationOverride('app.greeting', 'messages', 'fr_FR');
        $written->setValue('Bonjour tout le monde');

        $pool = new ArrayAdapter();
        $cacheManager = new TranslationCacheManager($pool);

        $repository = $this->createStub(TranslationOverrideRepository::class);
        // Nothing stored yet, then the row an override write would have created.
        $repository->method('findForRuntime')->willReturn([], [$written]);

        $translator = new OverrideAwareTranslator($this->createInnerTranslator(), $repository, $pool, $cacheManager, new NullScopeProvider());

        // A first lookup — in a real process, anything rendering a label does this.
        $first = $translator->trans('app.greeting', [], null, 'fr_FR');
        self::assertSame('inner:app.greeting', $first);

        // The write: the row is in the database and the shared caches are invalidated…
        $cacheManager->invalidate('fr_FR');

        // …but the map still holds the "fr_FR|" key, so no reload is triggered.
        self::assertSame('inner:app.greeting', $translator->trans('app.greeting', [], null, 'fr_FR'));

        $translator->resetOverrides('fr_FR');

        self::assertSame('Bonjour tout le monde', $translator->trans('app.greeting', [], null, 'fr_FR'));
    }

    public function testResettingOneLocaleLeavesTheOtherLocalesLoaded(): void
    {
        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('findForRuntime')->willReturn([]);

        $pool = new ArrayAdapter();
        $translator = new OverrideAwareTranslator(
            $this->createInnerTranslator(),
            $repository,
            $pool,
            new TranslationCacheManager($pool),
            new NullScopeProvider(),
        );

        $translator->trans('app.greeting', [], null, 'fr_FR');
        $translator->trans('app.greeting', [], null, 'en_GB');

        $translator->resetOverrides('fr_FR');

        $map = (new \ReflectionProperty(OverrideAwareTranslator::class, 'overridesCache'))->getValue($translator);
        self::assertIsArray($map);
        $loaded = array_keys($map);

        self::assertSame(['en_GB|'], $loaded);
    }

    /**
     * @param array<string, string> $overrides key => overridden value, stored for fr_FR
     */
    private function createTranslator(array $overrides): OverrideAwareTranslator
    {
        $entities = [];
        foreach ($overrides as $key => $value) {
            $entity = new TranslationOverride($key, 'messages', 'fr_FR');
            $entity->setValue($value);
            $entities[] = $entity;
        }

        return $this->createTranslatorForEntities($entities);
    }

    /**
     * @param list<TranslationOverride> $entities
     */
    private function createTranslatorForEntities(array $entities, ?ScopeProviderInterface $scopeProvider = null): OverrideAwareTranslator
    {
        return $this->createTranslatorForInner($this->createInnerTranslator(), $entities, $scopeProvider);
    }

    /** @param list<TranslationOverride> $rows */
    private function createTranslatorForStoredRows(array $rows): OverrideAwareTranslator
    {
        return $this->createTranslatorForInner($this->createInnerTranslator(), $rows);
    }

    /**
     * A translator over a repository that filters like the real one: only the rows whose
     * locale answers for the requested locale, and whose scope is global or the active
     * one, come back. A stub returning every row regardless would pass whatever the
     * lookup asks for.
     *
     * @param list<TranslationOverride> $rows
     */
    private function createTranslatorForInner(TranslatorInterface $inner, array $rows = [], ?ScopeProviderInterface $scopeProvider = null): OverrideAwareTranslator
    {
        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('findForRuntime')->willReturnCallback(
            static fn (string $locale, string $scope): array => array_values(array_filter(
                $rows,
                static fn (TranslationOverride $row): bool => LocaleFallback::covers($row->getLocale(), $locale)
                    && ('' === $row->getScope() || $row->getScope() === $scope),
            )),
        );

        $pool = new ArrayAdapter();

        return new OverrideAwareTranslator(
            $inner,
            $repository,
            $pool,
            new TranslationCacheManager($pool),
            $scopeProvider ?? new NullScopeProvider(),
        );
    }

    private function createInnerTranslator(): TranslatorInterface&LocaleAwareInterface
    {
        return new class implements TranslatorInterface, LocaleAwareInterface {
            private string $locale = 'fr_FR';

            /** @param array<string, mixed> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return 'inner:'.$id;
            }

            public function getLocale(): string
            {
                return $this->locale;
            }

            public function setLocale(string $locale): void
            {
                $this->locale = $locale;
            }
        };
    }
}
