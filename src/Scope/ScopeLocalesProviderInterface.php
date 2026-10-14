<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Scope;

/**
 * Optional, for a scope provider whose scopes expose only some locales (a sales channel
 * offers a few languages): implemented on the same service as {@see ScopeProviderInterface}.
 * A separate interface, so a host that does not need it keeps its provider as it is.
 *
 * An import then skips an entry in a locale its scope does not expose: it would be stored, and
 * never served nor shown.
 */
interface ScopeLocalesProviderInterface
{
    /**
     * The locales a declared scope exposes, or null when it exposes every locale. An entry in
     * a parent language counts as exposed: "fr" for a scope exposing "fr_FR", since an
     * override saved on "fr" is served to "fr_FR".
     *
     * @return list<string>|null
     */
    public function getScopeLocales(string $scope): ?array;
}
