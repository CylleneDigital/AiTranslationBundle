<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The value being saved does not match its catalogue's declared syntax
 * ({@see TranslationValueValidator}) — surfaced to the user as a refusal, not a crash.
 */
#[Exclude]
final class TranslationValueInvalidException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param non-empty-list<string> $issues {@see TranslationValueValidator} ISSUE_* codes
     */
    public function __construct(
        public readonly array $issues,
    ) {
        parent::__construct('The translation value does not match the catalogue syntax: '.implode(', ', $issues));
    }
}
