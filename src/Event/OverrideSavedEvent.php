<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Event;

use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after an override is created or updated (and the bundle's own caches are
 * invalidated). Hook point for host-side effects the bundle cannot know about:
 * HTTP cache purge, audit log, webhook, ….
 *
 * The override is the managed entity: a listener changing it would have the change
 * written by the next flush. Read it, do not modify it.
 */
#[Exclude]
final class OverrideSavedEvent extends Event
{
    public function __construct(
        public readonly TranslationOverride $override,
    ) {
    }
}
