<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Scope;

use CylleneDigital\AiTranslationBundle\Entity\ScopeParameters;
use CylleneDigital\AiTranslationBundle\Repository\ScopeParametersRepository;

/**
 * Reads and writes the per-scope settings ({@see ScopeParameters}). A scope without a
 * row has no settings; a row emptied by a write is deleted rather than kept blank.
 *
 * `$flush = false` on the writers stages the change for a flush the caller owns — an
 * integration package can write from its own form handling, inside its controller's
 * flush, so an invalid form never leaves a stray row behind.
 */
final class ScopeParametersManager
{
    public function __construct(
        private readonly ScopeParametersRepository $repository,
    ) {
    }

    /** The free-text context appended to the AI prompts of the scope's runs, or null. */
    public function getPromptContext(string $scope): ?string
    {
        return $this->repository->find($scope)?->getPromptContext();
    }

    public function setPromptContext(string $scope, ?string $context, bool $flush = true): void
    {
        $existing = $this->repository->find($scope);
        $parameters = $existing ?? new ScopeParameters($scope);
        $parameters->setPromptContext($context);

        if ($parameters->isEmpty()) {
            if (null !== $existing) {
                $flush ? $this->repository->remove($existing) : $this->repository->removeDeferred($existing);
            }

            return;
        }

        $flush ? $this->repository->save($parameters) : $this->repository->saveDeferred($parameters);
    }

    /** Drops every setting of a scope — when the scope itself disappears (a channel deleted). */
    public function remove(string $scope, bool $flush = true): void
    {
        $parameters = $this->repository->find($scope);

        if (null !== $parameters) {
            $flush ? $this->repository->remove($parameters) : $this->repository->removeDeferred($parameters);
        }
    }
}
