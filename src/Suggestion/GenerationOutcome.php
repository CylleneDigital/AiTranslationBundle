<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What a call to {@see TranslationAiService::generateSuggestions()} actually did.
 *
 * A bare count could not say it: "0 suggestions created" covers two situations a caller
 * must not confuse — the run went through and found nothing to translate, or the run
 * never happened because another one already holds the lock for that (catalogue, target
 * locale, scope). Reporting the second as the first tells whoever asked that everything
 * is translated, while a paid run is in flight next to them.
 *
 * A failed run is neither: the provider exception propagates, so it never reaches here.
 */
#[Exclude]
final readonly class GenerationOutcome
{
    private function __construct(
        /** Whether the run was executed at all — false only when another run holds the lock. */
        public bool $completed,
        /** Suggestions created (or refilled). Always 0 on a run that did not happen. */
        public int $created,
    ) {
    }

    public static function completed(int $created): self
    {
        return new self(true, $created);
    }

    /**
     * Nothing was executed: another run is already translating this (catalogue, target
     * locale, scope). Deliberately not an error — skipping is the lock doing its job.
     */
    public static function alreadyRunning(): self
    {
        return new self(false, 0);
    }
}
