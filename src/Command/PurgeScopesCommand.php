<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Scope\ScopePurger;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The cleanup of the scopes the host no longer declares: what a deleted channel left behind
 * before its deletion purged it, or when that purge failed. Read-only unless --force: it
 * deletes overrides someone wrote.
 */
#[AsCommand(
    name: 'cyllene:ai-translation:purge-scopes',
    description: 'List the scopes stored but no longer declared by the host, and purge them with --force',
)]
final class PurgeScopesCommand
{
    public function __construct(
        private readonly ScopePurger $purger,
        private readonly ScopeRegistry $scopes,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Purge the scopes listed: their overrides, suggestions and parameters (the journal stays)')]
        bool $force = false,
    ): int {
        // Without a declared list, every code is accepted: nothing tells a deleted scope
        // from a live one, and purging on a guess would delete live overrides.
        if ([] === $this->scopes->getAvailableScopes()) {
            $io->info('The host declares no scopes: no stored scope can be told apart as deleted. Nothing to do.');

            return Command::SUCCESS;
        }

        $undeclared = array_filter(
            $this->purger->findStoredScopes(),
            fn (string $scope): bool => !$this->scopes->isKnownScope($scope),
            \ARRAY_FILTER_USE_KEY,
        );

        if ([] === $undeclared) {
            $io->success('Every stored scope is still declared by the host. Nothing to purge.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($undeclared as $scope => $data) {
            $rows[] = [(string) $scope, $data->overrides, $data->suggestions, $data->parameters ? 'yes' : 'no'];
        }

        $io->table(['Scope', 'Overrides', 'Suggestions', 'Parameters'], $rows);

        if (!$force) {
            $io->note(\sprintf('%d scope(s) stored but no longer declared. Run again with --force to purge them; the generation journal stays.', \count($undeclared)));

            return Command::SUCCESS;
        }

        foreach (array_keys($undeclared) as $scope) {
            $purged = $this->purger->purge((string) $scope);
            $io->writeln(\sprintf('Scope "%s": %d override(s), %d suggestion(s)%s purged.', $scope, $purged->overrides, $purged->suggestions, $purged->parameters ? ' and its parameters' : ''));
        }

        $io->success(\sprintf('%d scope(s) purged.', \count($undeclared)));

        return Command::SUCCESS;
    }
}
