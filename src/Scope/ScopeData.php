<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Scope;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What the bundle stores for one scope, counted: what {@see ScopePurger::purge()} removed,
 * or what {@see ScopePurger::findStoredScopes()} found. The generation journal is not
 * counted: it is the record of what was paid for, and stays.
 */
#[Exclude]
final readonly class ScopeData
{
    public function __construct(
        public int $overrides,
        public int $suggestions,
        /** Whether the scope has its own parameters (a prompt context). */
        public bool $parameters,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->overrides && 0 === $this->suggestions && !$this->parameters;
    }
}
