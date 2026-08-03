<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Event;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a suggestion is rejected — right before its row is deleted, so the
 * listeners still see the full suggestion (key, catalogue, locale, scope, value), with
 * who rejected it and when. Before the deletion is committed, then: a listener that must
 * only act on a committed rejection defers its own effect (kernel.terminate, a Messenger
 * message). Every rejection works this way, the superseded ones included — once the row
 * is deleted, Doctrine resets the entity's id.
 *
 * A suggestion is rejected by a reviewer, or superseded, and `$supersededBy` says by what:
 *
 *  - {@see SUPERSEDED_BY_OVERRIDE}: an override written for its key by any other way (an
 *    edit, an import, the host's own code) closes the pending suggestions of that key,
 *    since the value someone chose is not the proposal. The rejecting author is the
 *    override's (`system` when no author is known).
 *  - {@see SUPERSEDED_BY_FILE}: a retry of the errored suggestions finds that the key a
 *    "missing keys" run failed on has a value since — the translation files were updated
 *    in the meantime — and closes the errored row instead of billing the key again. The
 *    rejecting author is `system`.
 *
 * Read it, do not modify it: it is the managed entity about to be removed.
 */
#[Exclude]
final class SuggestionRejectedEvent extends Event
{
    public const string SUPERSEDED_BY_OVERRIDE = 'override';
    public const string SUPERSEDED_BY_FILE = 'file';

    /**
     * @param self::SUPERSEDED_BY_*|null $supersededBy null = rejected by a reviewer
     */
    public function __construct(
        public readonly TranslationSuggestion $suggestion,
        public readonly ?string $supersededBy = null,
    ) {
    }
}
