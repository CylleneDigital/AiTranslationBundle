<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Event;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a suggestion is approved and its value applied — the override written,
 * removed (back to its baseline) or left alone when the entry already showed that value:
 * `metadata.override_change` on the suggestion says which. `finalValue` is the value
 * approved (the reviewer may have edited it).
 *
 * The suggestion is the managed entity: read it, do not modify it — the next flush
 * would write the change.
 */
#[Exclude]
final class SuggestionApprovedEvent extends Event
{
    public function __construct(
        public readonly TranslationSuggestion $suggestion,
        public readonly string $finalValue,
    ) {
    }
}
