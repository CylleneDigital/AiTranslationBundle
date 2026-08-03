<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Event\OverrideRemovedEvent;
use CylleneDigital\AiTranslationBundle\Event\OverrideSavedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The in-memory cache layer's invalidation: the translator's map of overrides.
 *
 * {@see TranslationCacheManager} owns the layer it can reach — the cache.app entry —
 * but the map lives in a property of
 * {@see OverrideAwareTranslator}, another service, which the manager must not depend on
 * (the translator already depends on the manager; injecting it back would close the
 * circle). The event the writer dispatches on every change is the seam that was already
 * there for exactly this kind of wiring.
 *
 * Why it matters: `getOverride()` only reloads when the "locale|scope" key is ABSENT
 * from the map. Once a request or a process has translated anything, the key is present
 * — even when it holds no override at all — so an override written afterwards stays
 * invisible for the rest of that process. HTTP hides it (Symfony's LocaleAwareListener
 * calls setLocale() on every request, which clears the map), consoles and Messenger
 * workers do not: they have no kernel.request.
 */
#[AsEventListener(event: OverrideSavedEvent::class)]
#[AsEventListener(event: OverrideRemovedEvent::class)]
final readonly class TranslatorOverrideCacheListener
{
    public function __construct(
        private OverrideAwareTranslator $translator,
    ) {
    }

    public function __invoke(OverrideSavedEvent|OverrideRemovedEvent $event): void
    {
        // Only the touched locale: the other locales' maps are still backed by valid
        // cache.app entries, and dropping them would cost a needless reload.
        $this->translator->resetOverrides(
            $event instanceof OverrideSavedEvent ? $event->override->getLocale() : $event->locale,
        );
    }
}
