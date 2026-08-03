<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared refusal of an unknown --scope value. Every command accepting the option runs
 * it through here: computing "the view of scope X" when X does not exist yields
 * plausible-looking figures (everything inherited from the global level) — a silent
 * lie a typo should never produce.
 */
final class ScopeOptionGuard
{
    public function __construct(
        private readonly ScopeRegistry $scopes,
    ) {
    }

    /** True when the scope is acceptable; otherwise the refusal is printed and false returned. */
    public function accept(SymfonyStyle $io, string $scope): bool
    {
        if ($this->scopes->isKnownScope($scope)) {
            return true;
        }

        $io->error(\sprintf('Unknown scope "%s".', $scope));

        $available = $this->scopes->getAvailableScopes();

        if ([] !== $available) {
            $io->listing(array_keys($available));
        }

        return false;
    }
}
