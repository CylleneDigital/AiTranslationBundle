<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Scope;

use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Repository\ScopeParametersRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;

/**
 * Removing what the bundle stores for a scope that no longer exists: a sales channel
 * deleted, a site closed. Left in place, its overrides would apply again to a new scope
 * created with the same code, which nobody wrote them for.
 *
 * The generation journal stays: it records what was paid for, and is never served.
 */
final readonly class ScopePurger
{
    public function __construct(
        private TranslationOverrideRepository $overrides,
        private TranslationSuggestionRepository $suggestions,
        private ScopeParametersRepository $parameters,
        private OverrideWriter $writer,
    ) {
    }

    /**
     * Deletes the overrides, the suggestions and the parameters of one scope, in a
     * transaction of its own: call it once the scope is gone (after the host's deletion is
     * committed), not inside the host's transaction. The overrides go through the writer,
     * one OverrideRemovedEvent each, and the cache invalidation and the events follow the
     * commit. The suggestions are deleted whatever their status, without events: a pending
     * one of a deleted scope is nobody's decision any more.
     *
     * @throws \InvalidArgumentException for the global scope '', which holds every
     *                                   unscoped override
     */
    public function purge(string $scope): ScopeData
    {
        if ('' === $scope) {
            throw new \InvalidArgumentException('The global scope cannot be purged: it holds every unscoped override.');
        }

        return $this->writer->withEffectsAfter(fn (): ScopeData => $this->suggestions->transactional(function () use ($scope): ScopeData {
            $overrides = $this->writer->removeMany($this->overrides->findByScope($scope));
            $suggestions = $this->suggestions->deleteByScope($scope);
            $parameters = $this->parameters->find($scope);

            if (null !== $parameters) {
                $this->parameters->remove($parameters);
            }

            return new ScopeData($overrides, $suggestions, null !== $parameters);
        }));
    }

    /**
     * Every scope the bundle stores something for, the global one aside, with what it
     * holds: what a cleanup compares with the scopes the host still declares.
     *
     * @return array<string, ScopeData> scope => its data, sorted by scope
     */
    public function findStoredScopes(): array
    {
        $overrides = $this->overrides->countPerScope();
        $suggestions = $this->suggestions->countPerScope();
        $parameters = array_flip($this->parameters->findScopes());

        $scopes = array_keys($overrides + $suggestions + $parameters);
        sort($scopes, \SORT_STRING);

        $stored = [];

        foreach ($scopes as $scope) {
            $scope = (string) $scope;

            if ('' !== $scope) {
                $stored[$scope] = new ScopeData($overrides[$scope] ?? 0, $suggestions[$scope] ?? 0, isset($parameters[$scope]));
            }
        }

        return $stored;
    }
}
