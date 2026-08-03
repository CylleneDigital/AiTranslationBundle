<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A deterministic correction of a flawed suggestion: the corrected value, and the
 * list of replacements applied ("pluriel → plural", "%nom% → %name%") so the reviewer
 * sees the exact delta before approving it.
 */
#[Exclude]
final readonly class AutocorrectResult
{
    /**
     * @param list<string> $applied
     */
    public function __construct(
        public string $value,
        public array $applied,
    ) {
    }
}
