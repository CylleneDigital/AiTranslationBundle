<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The pickers every journey shares. Each one is context-aware: a question is only
 * asked when there is an actual choice to make — a host without scopes never sees a
 * scope prompt, a single-provider setup never picks a provider.
 */
final class ConsolePicker
{
    private const string ALL_CATALOGUES = '(all catalogues)';
    private const string GLOBAL_SCOPE = 'No scope (global)';

    public function __construct(
        private readonly CatalogueRegistry $catalogues,
        private readonly ScopeRegistry $scopes,
    ) {
    }

    /** One catalogue identifier, or null for "all of them" (the default). */
    public function catalogueOrAll(SymfonyStyle $io): ?string
    {
        $identifiers = $this->catalogues->getCatalogueIdentifiers();

        if ([] === $identifiers) {
            return null;
        }

        $choice = $io->choice('Catalogue', array_merge([self::ALL_CATALOGUES], $identifiers), self::ALL_CATALOGUES);

        return \is_string($choice) && self::ALL_CATALOGUES !== $choice ? $choice : null;
    }

    /** One scope code, or '' for the global overrides — silently '' when the host declares no scope. */
    public function scope(SymfonyStyle $io): string
    {
        $available = $this->scopes->getAvailableScopes();

        if ([] === $available) {
            return '';
        }

        $choice = $io->choice('Scope', array_merge([self::GLOBAL_SCOPE], array_keys($available)), self::GLOBAL_SCOPE);

        return \is_string($choice) && self::GLOBAL_SCOPE !== $choice ? $choice : '';
    }
}
