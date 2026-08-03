<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Coverage\CoverageCalculator;
use CylleneDigital\AiTranslationBundle\Locale\LocaleFallback;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Invalidates the cache.app entries an override change must reach: the override maps
 * read by the OverrideAwareTranslator, and the coverage figures. Symfony's compiled
 * catalogue files are left alone — overrides are applied on top of them, never inside,
 * so recompiling them would cost a rebuild under live traffic for nothing.
 *
 * Each invalidation is repeated once the request, command or message is over. A caller
 * writing inside a transaction (wrapInTransaction(), Messenger's doctrine_transaction
 * middleware) invalidates BEFORE its commit: a concurrent request could rebuild the map
 * from the old rows in between and keep serving them for the whole cache TTL. The
 * second invalidation, after the commit, drops that stale entry.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'invalidateAgain')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'invalidateAgain')]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'invalidateAgain')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'invalidateAgain')]
final class TranslationCacheManager
{
    /** One version token per locale — bumping it orphans every scope variant at once. */
    private const string VERSION_KEY_PREFIX = 'cyllene_translation_overrides_version_';

    /** @var array<string, true> the locales invalidated since the last invalidateAgain() ('' = no locale) */
    private array $invalidated = [];

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
    ) {
    }

    public function invalidate(?string $locale = null): void
    {
        $this->drop($locale);
        $this->invalidated[$locale ?? ''] = true;
    }

    /** Repeats the invalidations of the request, command or message just finished. */
    public function invalidateAgain(): void
    {
        $locales = $this->invalidated;
        $this->invalidated = [];

        foreach (array_keys($locales) as $locale) {
            $this->drop('' === $locale ? null : (string) $locale);
        }
    }

    private function drop(?string $locale): void
    {
        if (null !== $locale) {
            // The locale ends up in a cache key: the shape the file scanner accepts, nothing else.
            if (1 !== preg_match(LocaleFallback::PATTERN, $locale)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a valid locale code.', $locale));
            }

            // The translator entries embed the locale version: deleting the version
            // token orphans every (locale, scope) entry in one shot — no need to know
            // which scopes exist (their TTL reclaims them).
            $this->cache->delete(self::VERSION_KEY_PREFIX.$locale);
        }

        // An override change moves the coverage figures whatever the locale.
        $this->cache->delete(CoverageCalculator::CACHE_KEY);
    }

    /**
     * Version token of one locale's override entries — part of the translator's cache
     * keys, regenerated whenever {@see invalidate()} drops it.
     */
    public function getVersion(string $locale): string
    {
        return $this->cache->get(self::VERSION_KEY_PREFIX.$locale, static fn (): string => bin2hex(random_bytes(4)));
    }
}
