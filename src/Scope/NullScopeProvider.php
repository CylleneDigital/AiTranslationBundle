<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Scope;

/**
 * Default {@see ScopeProviderInterface}: no scope dimension — every override is global.
 */
final class NullScopeProvider implements ScopeProviderInterface
{
    public function getScope(): ?string
    {
        return null;
    }

    public function getAvailableScopes(): array
    {
        return [];
    }
}
