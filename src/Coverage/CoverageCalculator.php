<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Coverage;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Translation coverage per locale, against the project's default locale (the
 * configured `default_locale`; else the framework's `kernel.default_locale` matched
 * against the available locales — exactly, or by prefix so "fr" picks "fr_FR" —; else
 * the first available locale; else the framework default itself).
 *
 * A key counts as translatable when the default locale has a non-empty effective value for it
 * (override applied over the file value — the same definition the AI generation uses);
 * it counts as translated in a target locale when that locale has a non-empty effective
 * value too. The coverage is therefore relative to it: keys the default locale itself
 * misses are invisible, which is documented behaviour.
 *
 * Figures can be computed for one override scope ('' = global): the effective value is
 * then the scoped override, else the inherited global override, else the file value —
 * what a visitor of that scope actually sees.
 *
 * The resolved-default reports are cached (cache.app, short TTL) because computing one
 * loads every catalogue for every locale; every (scope, locale set) variant hangs off a
 * single version token the {@see TranslationCacheManager} drops whenever an override
 * changes. {@see compute()} always recounts — the CLI gate wants fresh figures.
 */
final class CoverageCalculator
{
    public const string CACHE_KEY = 'cyllene_translation_coverage';

    /** Suggestions land pending and files change without events: keep the cache short. */
    private const int DEFAULT_CACHE_TTL = 300;

    public function __construct(
        private readonly CatalogueRegistry $catalogues,
        private readonly OverrideReader $overrides,
        private readonly TranslationSuggestionRepository $suggestionRepository,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
        #[Autowire(param: 'cyllene_digital_ai_translation.default_locale')]
        private readonly ?string $configuredDefaultLocale = null,
        // null = automatic: no cache in debug (live figures while developing), 5 min
        // otherwise. 0 disables the cache explicitly, whatever the environment.
        #[Autowire(param: 'cyllene_digital_ai_translation.coverage_cache_ttl')]
        private readonly ?int $cacheTtl = null,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug = false,
        // The host project's declared main locale (framework.default_locale) — often
        // the short form ("fr"), hence the prefix matching in getDefaultLocale().
        #[Autowire(param: 'kernel.default_locale')]
        private readonly string $frameworkDefaultLocale = 'en',
    ) {
    }

    /**
     * The configured `default_locale` always wins. Otherwise it must be a locale that
     * actually has files: the one matching the framework's default locale when there is
     * one (exactly, else by prefix — "fr" picks "fr_FR"), else the first available. Only
     * a project with no translation files at all falls back to the framework default as-is.
     */
    public function getDefaultLocale(): string
    {
        if (null !== $this->configuredDefaultLocale && '' !== $this->configuredDefaultLocale) {
            return $this->configuredDefaultLocale;
        }

        $available = $this->catalogues->getAvailableLocales();

        if (\in_array($this->frameworkDefaultLocale, $available, true)) {
            return $this->frameworkDefaultLocale;
        }

        foreach ($available as $locale) {
            if (str_starts_with($locale, $this->frameworkDefaultLocale.'_')) {
                return $locale;
            }
        }

        return $available[0] ?? $this->frameworkDefaultLocale;
    }

    /**
     * Report for the resolved default locale, cached per the resolved TTL — for the callers
     * that display coverage figures repeatedly.
     *
     * @param string            $scope   the override scope the figures are computed for ('' = global)
     * @param list<string>|null $locales restrict the target locales (null = every available locale)
     */
    public function getReport(string $scope = '', ?array $locales = null): CoverageReport
    {
        $ttl = $this->getCacheTtl();

        if (0 === $ttl) {
            return $this->compute(null, $scope, $locales);
        }

        // The version token lives under CACHE_KEY — the entry the cache manager deletes
        // on every override change — so dropping it orphans every variant at once, with
        // no need to know which scopes exist (their TTL reclaims them).
        $version = $this->cache->get(self::CACHE_KEY, static fn (): string => bin2hex(random_bytes(4)));
        $key = self::CACHE_KEY.'_'.$version.'_'.hash('xxh128', $scope.'|'.(null === $locales ? '*' : implode(',', $locales)));

        return $this->cache->get($key, function (ItemInterface $item) use ($ttl, $scope, $locales): CoverageReport {
            $item->expiresAfter($ttl);

            return $this->compute(null, $scope, $locales);
        });
    }

    /** The configured coverage_cache_ttl, defaulting to "no cache in debug, 5 min otherwise". */
    private function getCacheTtl(): int
    {
        return $this->cacheTtl ?? ($this->debug ? 0 : self::DEFAULT_CACHE_TTL);
    }

    /**
     * Fresh, uncached count — the CI gate and any explicit-default-locale report.
     *
     * @param string            $scope              the override scope the figures are computed for ('' = global)
     * @param list<string>|null $locales            restrict the target locales (null = every available locale);
     *                                              locales the host does not expose are ignored
     * @param bool              $collectMissingKeys also record WHICH keys are missing, per catalogue,
     *                                              in each {@see LocaleCoverage::$missingKeys}
     */
    public function compute(?string $defaultLocale = null, string $scope = '', ?array $locales = null, bool $collectMissingKeys = false): CoverageReport
    {
        $defaultLocale ??= $this->getDefaultLocale();
        $catalogues = $this->catalogues->getCatalogueIdentifiers();
        $available = $this->catalogues->getAvailableLocales();

        $targetLocales = array_values(array_filter(
            $locales ?? $available,
            static fn (string $locale): bool => $locale !== $defaultLocale && \in_array($locale, $available, true),
        ));

        $translated = array_fill_keys($targetLocales, 0);
        $missing = array_fill_keys($targetLocales, 0);
        $missingKeys = array_fill_keys($targetLocales, []);

        // One query per locale for the whole report, instead of one (or two) per
        // (catalogue, locale) pair: a 40-catalogue project in 10 locales used to issue
        // hundreds of queries here, and this runs on every CI gate.
        $overrides = [$defaultLocale => $this->overrides->getEffectiveOverridesByCatalogue($defaultLocale, $scope)];

        foreach ($targetLocales as $locale) {
            $overrides[$locale] = $this->overrides->getEffectiveOverridesByCatalogue($locale, $scope);
        }

        foreach ($catalogues as $catalogue) {
            $defaultKeys = $this->getEffectiveKeys($catalogue, $defaultLocale, $overrides[$defaultLocale][$catalogue] ?? []);

            if ([] === $defaultKeys) {
                continue;
            }

            foreach ($targetLocales as $locale) {
                $effective = $this->getEffectiveKeys($catalogue, $locale, $overrides[$locale][$catalogue] ?? []);

                foreach (array_keys($defaultKeys) as $key) {
                    if (isset($effective[$key])) {
                        ++$translated[$locale];
                    } else {
                        ++$missing[$locale];

                        if ($collectMissingKeys) {
                            $missingKeys[$locale][$catalogue][] = $key;
                        }
                    }
                }
            }
        }

        $locales = [];
        foreach ($targetLocales as $locale) {
            $locales[] = new LocaleCoverage(
                $locale,
                $translated[$locale],
                $missing[$locale],
                $this->suggestionRepository->countPendingFiltered($locale, null, null, $scope),
                $missingKeys[$locale],
            );
        }

        return new CoverageReport($defaultLocale, $locales);
    }

    /**
     * The default locale's translatable keys, per catalogue — the exact key set the
     * coverage figures count against.
     *
     * @return array<string, list<string>> catalogue identifier => sorted keys
     */
    public function getDefaultLocaleKeys(?string $defaultLocale = null, string $scope = ''): array
    {
        $defaultLocale ??= $this->getDefaultLocale();
        $keys = [];

        $overrides = $this->overrides->getEffectiveOverridesByCatalogue($defaultLocale, $scope);

        foreach ($this->catalogues->getCatalogueIdentifiers() as $catalogue) {
            $catalogueKeys = $this->getEffectiveKeys($catalogue, $defaultLocale, $overrides[$catalogue] ?? []);

            if ([] !== $catalogueKeys) {
                $keys[$catalogue] = array_keys($catalogueKeys);
            }
        }

        return $keys;
    }

    /**
     * The keys one locale carries that the default locale lacks — invisible to the
     * coverage figures by construction (coverage is relative to the default locale): a
     * renamed key, a stray override, a translation whose source in the default locale
     * was deleted.
     *
     * @return array<string, list<string>> catalogue identifier => sorted keys
     */
    public function getExtraKeys(string $locale, ?string $defaultLocale = null, string $scope = ''): array
    {
        $defaultLocale ??= $this->getDefaultLocale();
        $extra = [];
        $localeOverrides = $this->overrides->getEffectiveOverridesByCatalogue($locale, $scope);
        $defaultOverrides = $this->overrides->getEffectiveOverridesByCatalogue($defaultLocale, $scope);

        foreach ($this->catalogues->getCatalogueIdentifiers() as $catalogue) {
            $keys = array_keys(array_diff_key(
                $this->getEffectiveKeys($catalogue, $locale, $localeOverrides[$catalogue] ?? []),
                $this->getEffectiveKeys($catalogue, $defaultLocale, $defaultOverrides[$catalogue] ?? []),
            ));

            if ([] !== $keys) {
                $extra[$catalogue] = $keys;
            }
        }

        return $extra;
    }

    /**
     * The keys of one catalogue with a non-empty effective value in one locale — the
     * catalogue's file entries plus the overrides of that (locale, scope), which the
     * caller has already loaded for the whole locale.
     *
     * @param array<string, string> $overrides key => effective override value
     *
     * @return array<string, true>
     */
    private function getEffectiveKeys(string $catalogue, string $locale, array $overrides): array
    {
        $keys = [];

        foreach ($this->catalogues->getOriginalValues($catalogue, $locale) as $key => $value) {
            if ('' !== $value) {
                $keys[$key] = true;
            }
        }

        foreach ($overrides as $key => $value) {
            if ('' !== $value) {
                $keys[$key] = true;
            } else {
                unset($keys[$key]);
            }
        }

        return $keys;
    }
}
