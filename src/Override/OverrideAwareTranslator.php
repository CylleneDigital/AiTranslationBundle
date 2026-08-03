<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Catalogue\TranslationCatalogue;
use CylleneDigital\AiTranslationBundle\Locale\LocaleFallback;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\Formatter\IntlFormatter;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Applies the database overrides on top of whatever the decorated translator would
 * return (YAML catalogues, themes, …).
 *
 * Overrides are looked up scoped by (locale, Symfony domain): the stored `catalogue`
 * identifier ("shop/Product/messages") carries the Symfony translation domain as its
 * `type` — the last segment ("messages", "validators", "admin", …) — which is exactly
 * the `$domain` the framework passes to {@see trans()}. Scoping by domain removes the
 * cross-catalogue collision the flat "lookup by key alone" had: an override of the
 * "validators" domain no longer leaks onto a "messages" lookup, and two overrides of
 * distinct domains sharing the same key no longer shadow each other.
 *
 * Residual ambiguity (by design, mirroring Symfony itself): several file catalogues can
 * share one domain — "shop/Product/messages" and "shop/messages" both feed the "messages"
 * domain, which the framework merges into a single per-locale catalogue. When two such
 * catalogues override the same key, the translator only knows the domain, not the source
 * file, so the first match (catalogues ordered ASC) wins and the collision is logged. Third-party
 * bundles are typically unaffected: their keys are namespaced ("some_bundle.ui.name"),
 * so they do not collide within a domain.
 *
 * Locales resolve through the language chain, exactly like the files do: a "fr_FR"
 * request is answered by the overrides saved on "fr" as well as those saved on "fr_FR",
 * the more specific winning ({@see LocaleFallback}). Without it, a project whose files
 * are named "messages.fr.yaml" — so whose browsable locale is "fr" — but which runs on
 * "fr_FR" would store overrides no runtime lookup ever reads.
 *
 * The served value passes two cache layers: the cache.app entry (1 h) and this service's
 * in-memory map (per request). The TranslationCacheManager invalidates the shared one
 * whenever an override is saved or removed; the TranslatorOverrideCacheListener drops
 * the map.
 *
 * Loading is fail-open: this runs inside every trans() call of the application, so a
 * database or cache backend that cannot answer degrades to the file catalogues (logged
 * as an error) rather than failing the page.
 *
 * Every interface the decorated `translator` implements is re-exposed here, WarmableInterface
 * included: the framework's TranslationsCacheWarmer receives this decorator under the
 * `translator` id and only warms up what it recognises as warmable — without it,
 * cache:warmup would silently stop compiling the catalogues and the first request of a
 * fresh deployment would have to write them itself (impossible on a read-only cache).
 */
#[AsDecorator(decorates: 'translator', priority: -10)]
final class OverrideAwareTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    private const string CACHE_KEY_PREFIX = 'cyllene_translation_overrides_';

    private const int CACHE_TTL = 3600;

    /** Symfony's implicit domain when a caller passes none. */
    private const string DEFAULT_DOMAIN = 'messages';

    /** @var array<string, array<string, array<string, string>>> "locale|scope" => domain => key => value */
    private array $overridesCache = [];

    private ?IntlFormatter $intlFormatter = null;

    public function __construct(
        #[AutowireDecorated]
        private readonly TranslatorInterface $translator,
        private readonly TranslationOverrideRepository $repository,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
        private readonly TranslationCacheManager $cacheManager,
        private readonly ScopeProviderInterface $scopeProvider,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $locale ??= $this->getLocale();

        $override = $this->getOverride($id, $this->extractBaseLocale($locale), $domain ?? self::DEFAULT_DOMAIN);

        if (null !== $override) {
            $domain ??= self::DEFAULT_DOMAIN;

            // The files say the key is ICU: format it exactly as Symfony formats the file
            // value — "%count%" keys included, and even without parameters (ICU quotes).
            if (\extension_loaded('intl') && $this->isIcuKey($id, $domain, $locale)) {
                try {
                    return ($this->intlFormatter ??= new IntlFormatter())->formatIntl($override, $locale, $parameters);
                } catch (\InvalidArgumentException) {
                    // Malformed ICU pattern — served as stored rather than failing the page.
                    return $override;
                }
            }

            if ([] !== $parameters) {
                return $this->formatMessage($override, $parameters, $locale);
            }

            return $override;
        }

        return $this->translator->trans($id, $parameters, $domain, $locale);
    }

    /**
     * Whether the decorated translator's files declare the key in the "+intl-icu"
     * variant of the domain — walking the fallback catalogues the way Symfony does to
     * find the file value the override replaces.
     */
    private function isIcuKey(string $id, string $domain, string $locale): bool
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return false;
        }

        try {
            $catalogue = $this->translator->getCatalogue($locale);
        } catch (\Throwable) {
            return false;
        }

        while (!$catalogue->defines($id, $domain)) {
            $catalogue = $catalogue->getFallbackCatalogue();

            if (null === $catalogue) {
                return false;
            }
        }

        return $catalogue->defines($id, $domain.MessageCatalogueInterface::INTL_DOMAIN_SUFFIX);
    }

    public function getLocale(): string
    {
        if ($this->translator instanceof LocaleAwareInterface) {
            return $this->translator->getLocale();
        }

        // intl is optional (see the ICU branch above): same fallback as Symfony's Translator.
        return class_exists(\Locale::class) ? \Locale::getDefault() : 'en';
    }

    public function setLocale(string $locale): void
    {
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }

        $this->resetOverrides();
    }

    /**
     * Drops the in-memory map so the next lookup reloads it — the in-memory layer,
     * which {@see TranslationCacheManager} cannot reach (it belongs to this service, not
     * to cache.app). Called by the {@see TranslatorOverrideCacheListener} on every
     * override change, so a value written mid-process is served right away.
     *
     * Without it, `getOverride()` keeps answering from the map it loaded BEFORE the
     * write: the key is present in $overridesCache, so no reload is triggered. HTTP
     * hides this — Symfony's LocaleAwareListener calls setLocale() on every request —
     * but a console command or a Messenger worker has no kernel.request, and would
     * serve the stale value until the process ends.
     *
     * @param string|null $locale that locale's entries and those of every locale it
     *                            answers for (writing on "fr" also reaches the "fr_FR"
     *                            map), every scope — or all of them when null
     */
    public function resetOverrides(?string $locale = null): void
    {
        if (null === $locale) {
            $this->overridesCache = [];

            return;
        }

        foreach (array_keys($this->overridesCache) as $cacheKey) {
            // "locale|scope" — the scope is opaque and may contain anything, the locale
            // never contains a pipe.
            if (LocaleFallback::covers($locale, strstr($cacheKey, '|', true) ?: $cacheKey)) {
                unset($this->overridesCache[$cacheKey]);
            }
        }
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        if ($this->translator instanceof TranslatorBagInterface) {
            return $this->translator->getCatalogue($locale);
        }

        throw new \LogicException('The inner translator does not implement TranslatorBagInterface.');
    }

    public function getCatalogues(): array
    {
        if ($this->translator instanceof TranslatorBagInterface) {
            return $this->translator->getCatalogues();
        }

        return [];
    }

    /**
     * Delegated verbatim: the overrides live in the database and need no warmup, but the
     * decorated translator's compiled catalogues do.
     *
     * @return string[]
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->translator instanceof WarmableInterface) {
            return $this->translator->warmUp($cacheDir, $buildDir);
        }

        return [];
    }

    private function getOverride(string $key, string $locale, string $domain): ?string
    {
        $scope = $this->scopeProvider->getScope() ?? '';
        $cacheKey = $locale.'|'.$scope;

        if (!isset($this->overridesCache[$cacheKey])) {
            $this->loadOverrides($locale, $scope);
        }

        return $this->overridesCache[$cacheKey][$domain][$key] ?? null;
    }

    /**
     * Builds the (domain => key => value) map of one locale. Overrides are grouped by the
     * Symfony domain carried by their catalogue identifier's `type`; the repository orders
     * by catalogue then key, so within a domain the first-seen catalogue wins on a key
     * collision (logged — see the class docblock).
     *
     * Never fatal. The overrides are an enhancement over the file catalogues, and this
     * runs inside every single trans() call of the application: an unreachable database
     * or cache backend — a migration not yet run after a `composer require`, a failover —
     * must degrade to the file value, not take down every page that renders a label.
     */
    private function loadOverrides(string $locale, string $scope): void
    {
        try {
            $overrides = $this->fetchOverrides($locale, $scope);
        } catch (\Throwable $e) {
            $this->logger->error('The translation overrides could not be loaded — falling back to the file catalogues for this locale.', [
                'locale' => $locale,
                'scope' => $scope,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            // Memoised as empty: retrying on every trans() of the request would turn one
            // outage into thousands of failed connections.
            $overrides = [];
        }

        $this->overridesCache[$locale.'|'.$scope] = $overrides;
    }

    /**
     * @return array<string, array<string, string>> domain => key => value
     */
    private function fetchOverrides(string $locale, string $scope): array
    {
        // The version token (bumped on every override change) is part of the key, so
        // one invalidation orphans every scope variant of the locale at once. The whole
        // language chain contributes its own token: a "fr" override reaches the "fr_FR"
        // entry, which is built from the "fr" rows too.
        $version = implode('.', array_map($this->cacheManager->getVersion(...), LocaleFallback::chain($locale)));
        $poolKey = self::CACHE_KEY_PREFIX.$locale.'_'.$version
            // Hashed, not sanitised: "FR/B2B" and "FR-B2B" must not share an entry.
            .('' === $scope ? '' : '_s_'.hash('xxh128', $scope));

        /** @var array<string, array<string, string>> $overrides */
        $overrides = $this->cache->get($poolKey, function (ItemInterface $item) use ($locale, $scope): array {
            $item->expiresAfter(self::CACHE_TTL);

            $overrides = $this->repository->findForRuntime($locale, $scope);

            // Explicit precedence, collation-proof — first merged wins:
            //   1. the more specific locale of the chain shadows the less specific one
            //      ("fr_FR" over "fr"), mirroring how the files themselves resolve;
            //   2. scoped overrides shadow global ones for the same key;
            //   3. within a rank, additional-root catalogues ("@label/…" — themes and
            //      friends, whose files also win at runtime) shadow the main root's;
            //   4. catalogue/key order otherwise.
            usort($overrides, static fn ($a, $b): int => [-LocaleFallback::rank($a->getLocale()), '' === $a->getScope(), !str_starts_with($a->getCatalogue(), '@'), $a->getCatalogue(), $a->getKey()] <=> [-LocaleFallback::rank($b->getLocale()), '' === $b->getScope(), !str_starts_with($b->getCatalogue(), '@'), $b->getCatalogue(), $b->getKey()]);

            $result = [];
            /** @var array<string, array<string, string>> $winningLocale domain => key => the locale that answered */
            $winningLocale = [];

            foreach ($overrides as $override) {
                // Defense in depth: only the global rows and the active scope's apply,
                // whatever the repository returned.
                if ('' !== $override->getScope() && $override->getScope() !== $scope) {
                    continue;
                }

                $domain = TranslationCatalogue::fromIdentifier($override->getCatalogue())->type;
                $key = $override->getKey();

                if (isset($result[$domain][$key])) {
                    // A less specific locale of the chain being shadowed ("fr" under
                    // "fr_FR") is the expected resolution, not an ambiguity: only two
                    // rows of the SAME locale are a collision the author should know about.
                    $sameLocale = $winningLocale[$domain][$key] === $override->getLocale();

                    if ($sameLocale && ($override->getScope() === $scope || '' === $scope)) {
                        $this->logger->warning('Two translation overrides collide on the same domain and key; the first one wins.', [
                            'locale' => $override->getLocale(),
                            'domain' => $domain,
                            'key' => $key,
                            'catalogue' => $override->getCatalogue(),
                        ]);
                    }

                    continue;
                }

                $result[$domain][$key] = $override->getValue();
                $winningLocale[$domain][$key] = $override->getLocale();
            }

            return $result;
        });

        return $overrides;
    }

    /** Strips a variant suffix ("fr_FR@euro" → "fr_FR") before the override lookup. */
    private function extractBaseLocale(string $locale): string
    {
        $atPosition = mb_strpos($locale, '@');

        if (false !== $atPosition) {
            return mb_substr($locale, 0, $atPosition);
        }

        return $locale;
    }

    /**
     * Overridden messages bypass the catalogue formatting, so it is re-applied here,
     * following the same conventions as the framework:
     *
     *  1. a pipe with a legacy-style `%count%` parameter is Symfony's own plural syntax
     *     ("{0} None|{1} One|]1,Inf[ %count% apples") — delegated to IdentityTranslator,
     *     which applies exactly the framework semantics (branch selection + %param%
     *     replacement), so an override behaves like the file text it replaces;
     *  2. an ICU pattern ("{count, plural, …}") is formatted by intl — but only when it
     *     actually references one of the bare-named parameters: a stray literal brace is
     *     not enough to be mistaken for ICU;
     *  3. anything else gets the plain parameter replacement.
     *
     * @param array<string, mixed> $parameters
     */
    private function formatMessage(string $message, array $parameters, string $locale): string
    {
        if (isset($parameters['%count%']) && str_contains($message, '|')) {
            return (new IdentityTranslator())->trans($message, $parameters, null, $locale);
        }

        if (\extension_loaded('intl') && $this->referencesIcuArgument($message, $parameters)) {
            try {
                $result = (new \MessageFormatter($locale, $message))->format($parameters);

                if (false !== $result) {
                    return $result;
                }
            } catch (\IntlException) {
                // Malformed ICU pattern — fall through to the plain replacement.
            }
        }

        $replacements = [];

        foreach ($parameters as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replacements[(string) $key] = (string) $value;
            }
        }

        // One pass, like Symfony's own formatter: a value containing another placeholder
        // must not be replaced again.
        return strtr($message, $replacements);
    }

    /**
     * True when the message looks like an ICU pattern FOR the given call: it must
     * reference at least one parameter by its bare name ("{count}", "{count, plural…}").
     * Legacy-style keys ("%count%") never match — their braces, if any, are interval
     * syntax or literal text.
     *
     * @param array<string, mixed> $parameters
     */
    private function referencesIcuArgument(string $message, array $parameters): bool
    {
        if (!str_contains($message, '{')) {
            return false;
        }

        foreach (array_keys($parameters) as $key) {
            $key = (string) $key;

            if (!str_contains($key, '%') && 1 === preg_match('/\{\s*'.preg_quote($key, '/').'\b/', $message)) {
                return true;
            }
        }

        return false;
    }
}
