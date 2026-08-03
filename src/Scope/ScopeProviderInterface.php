<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Scope;

/**
 * Host-facing source of the override scope — a generic dimension the integration
 * package maps to its own concept (a site, a brand, a tenant, a sales channel…). The default implementation
 * ({@see NullScopeProvider}) knows no scope: every override stays global.
 *
 * At runtime a scoped override shadows the global one for the same key; where no
 * scope is resolvable (CLI, workers, admin) the global overrides apply.
 */
interface ScopeProviderInterface
{
    /** Scope of the current execution context, or null when none is resolvable. */
    public function getScope(): ?string;

    /**
     * The scopes the host may offer for selection (code => human label). Empty = the
     * scope dimension is hidden entirely.
     *
     * @return array<string, string>
     */
    public function getAvailableScopes(): array;
}
