<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\App;

use CylleneDigital\AiTranslationBundle\Scope\ScopeProviderInterface;

/**
 * The test double of an integration package's scope adapter (the Sylius plugin maps
 * scopes to sales channels): two fixed scopes, no resolvable runtime scope.
 */
final class FixedScopeProvider implements ScopeProviderInterface
{
    public function getScope(): ?string
    {
        return null;
    }

    public function getAvailableScopes(): array
    {
        return [
            'FASHION_WEB' => 'Fashion Web',
            'B2B' => 'B2B',
        ];
    }
}
