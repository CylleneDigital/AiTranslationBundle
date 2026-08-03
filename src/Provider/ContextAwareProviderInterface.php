<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Provider;

/**
 * A provider able to take a run-specific context on top of its configuration — the
 * per-scope prompt context ({@see \CylleneDigital\AiTranslationBundle\Entity\ScopeParameters}).
 * Optional: providers that cannot use free text (or custom host providers) simply do not
 * implement it and run as configured.
 */
interface ContextAwareProviderInterface extends TranslationAiProviderInterface
{
    /**
     * A copy of this provider carrying the additional context; the original is left
     * untouched (providers are shared services). Null or blank = no additional context.
     */
    public function withAdditionalContext(?string $context): static;
}
