<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Approving a suggestion that has no value to apply — a failed generation whose row was
 * neither hand-edited nor refilled by a retry run. The caller should surface it as a
 * user-facing refusal, not as a crash.
 */
#[Exclude]
final class SuggestionValueMissingException extends \RuntimeException implements ExceptionInterface
{
    public function __construct()
    {
        parent::__construct('The suggestion has no value to apply — edit it (or retry the generation) before approving.');
    }
}
