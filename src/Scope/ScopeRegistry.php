<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Scope;

/**
 * The scope dimension seen from the outside: which codes exist, and whether one is
 * acceptable.
 *
 * A scope is an opaque host-defined code ('' = global) — a site, a sales channel, a
 * brand. The bundle never interprets it; it only needs to know the declared set, so a
 * typo does not silently produce overrides no runtime lookup will ever read.
 */
final readonly class ScopeRegistry
{
    /**
     * Same ceiling as the scope columns of the override and suggestion tables. Public
     * because the import has to refuse an oversized code before the writer ever sees it,
     * and a second literal 64 would be a second truth.
     */
    public const int MAX_SCOPE_LENGTH = 64;

    public function __construct(
        private ScopeProviderInterface $scopeProvider,
    ) {
    }

    /**
     * The scopes the host may offer for selection (code => human label). Empty when the
     * host has no scope dimension — every override is then global.
     *
     * @return array<string, string>
     */
    public function getAvailableScopes(): array
    {
        return $this->scopeProvider->getAvailableScopes();
    }

    /**
     * Whether one scope code is acceptable: the global '' always is; when the host
     * declares scopes, the code must be one of them; without a declared list any
     * reasonably-sized opaque code passes — there is nothing to validate against, and
     * refusing would break the hosts that resolve scopes dynamically.
     */
    public function isKnownScope(string $scope): bool
    {
        if ('' === $scope) {
            return true;
        }

        if (\strlen($scope) > self::MAX_SCOPE_LENGTH) {
            return false;
        }

        $available = $this->getAvailableScopes();

        return [] === $available || isset($available[$scope]);
    }
}
