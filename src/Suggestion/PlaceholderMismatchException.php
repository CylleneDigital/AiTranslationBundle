<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Raised when approving a suggestion whose translation lost, added or altered placeholders —
 * `$missing` and `$unexpected` tell the two directions apart.
 * Fix the value (edit & approve) or reject the suggestion.
 */
#[Exclude]
final class PlaceholderMismatchException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param list<string> $placeholders the differing markers, as {@see PlaceholderConsistencyChecker::diff()} lists them
     * @param list<string> $missing      the source placeholders the translation lacks
     * @param list<string> $unexpected   the placeholders the translation has and the source does not
     */
    public function __construct(
        array $placeholders,
        public readonly array $missing = [],
        public readonly array $unexpected = [],
    ) {
        $details = array_filter([
            [] !== $missing ? 'missing: '.implode(', ', $missing) : null,
            [] !== $unexpected ? 'not in the source: '.implode(', ', $unexpected) : null,
        ]);

        parent::__construct(\sprintf(
            'The placeholders differ from the source (%s). Edit the value before approving, or reject the suggestion.',
            [] !== $details ? implode('; ', $details) : implode(', ', $placeholders),
        ));
    }
}
