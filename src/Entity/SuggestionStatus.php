<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Entity;

/**
 * Lifecycle status of a {@see TranslationSuggestion}. A rejected suggestion is deleted,
 * not kept: the only states a stored row can be in are pending and approved.
 */
enum SuggestionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';

    /**
     * Never stored: the entity in hand carries it once its row is deleted. Without it a
     * deleted suggestion, whose id Doctrine resets, would look pending and new again.
     */
    case Rejected = 'rejected';
}
