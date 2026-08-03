<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Approving a suggestion that is no longer pending — reviewed meanwhile by someone else,
 * or submitted twice. Approving it again would write its value over whatever the
 * override became since. The caller should surface it as a user-facing refusal.
 */
#[Exclude]
final class SuggestionAlreadyReviewedException extends \RuntimeException implements ExceptionInterface
{
    public function __construct()
    {
        parent::__construct('The suggestion has already been reviewed — reload the pending suggestions.');
    }
}
