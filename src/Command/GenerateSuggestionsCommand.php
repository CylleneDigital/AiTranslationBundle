<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Coverage\CoverageCalculator;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The scripted counterpart of the generation journey, for cron and CI: the same
 * estimate, the same run, driven by options. Interactive, the launch still waits for
 * a confirmation; non-interactive (-n, cron), it goes ahead — --max-cost is then the
 * guard rail, and --dry-run stops after the estimate.
 */
#[AsCommand(
    name: 'cyllene:ai-translation:generate',
    description: 'Generate AI translation suggestions for one or more target locales (cron/CI-friendly)',
)]
final class GenerateSuggestionsCommand
{
    public function __construct(
        private readonly CatalogueRegistry $catalogues,
        private readonly TranslationProviderRegistry $providerRegistry,
        private readonly CoverageCalculator $coverageCalculator,
        private readonly GenerationRunner $runner,
        private readonly ScopeOptionGuard $scopeGuard,
    ) {
    }

    /**
     * @param list<string> $target
     * @param list<string> $catalogue
     */
    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option(description: 'Target locale (repeatable)', shortcut: 't')]
        array $target = [],
        #[Option(description: 'Source locale (default: the resolved default locale, as the coverage report uses it)', shortcut: 'o')]
        ?string $source = null,
        #[Option(description: 'Restrict the run to this catalogue, e.g. "shop/Product/messages" (repeatable; default: every catalogue)', shortcut: 'c')]
        array $catalogue = [],
        #[Option(description: 'Provider name (default: the configured default_provider)', shortcut: 'p')]
        ?string $provider = null,
        #[Option(description: 'Generate for one override scope — an opaque code the host defines (default: the global overrides)', shortcut: 's')]
        string $scope = '',
        #[Option(description: 'Re-translate every key, not only the missing ones')]
        bool $all = false,
        #[Option(name: 'retry-errors', description: 'Re-send the keys whose previous run failed (errored suggestions)')]
        bool $retryErrors = false,
        #[Option(name: 'max-cost', description: 'Refuse the run (exit 1) when the estimated cost exceeds this amount (USD list prices) or cannot be priced')]
        ?float $maxCost = null,
        #[Option(name: 'dry-run', description: 'Print the estimate and stop — nothing is sent to the provider')]
        bool $dryRun = false,
    ): int {
        if ($all && $retryErrors) {
            $io->error('--all and --retry-errors are mutually exclusive.');

            return Command::FAILURE;
        }

        // A negative ceiling refuses every run whatever the estimate: a typo, not a limit.
        if (null !== $maxCost && $maxCost < 0) {
            $io->error(\sprintf('--max-cost cannot be negative (got %s).', $maxCost));

            return Command::FAILURE;
        }

        if ([] === $target) {
            $io->error('At least one --target locale is required.');

            return Command::FAILURE;
        }

        // Symfony locales use "_": "pt-BR", as a translation tool or a URL writes it, is the
        // locale of the "messages.pt-BR.yaml" files the scan lists as "pt_BR".
        $target = array_map(static fn (string $locale): string => str_replace('-', '_', $locale), $target);
        $locales = $this->catalogues->getAvailableLocales();
        $source = null !== $source ? str_replace('-', '_', $source) : $this->coverageCalculator->getDefaultLocale();

        foreach ([$source, ...$target] as $locale) {
            if (!\in_array($locale, $locales, true)) {
                $io->error(\sprintf('Locale "%s" is not available (available: %s).', $locale, [] === $locales ? '(none)' : implode(', ', $locales)));

                return Command::FAILURE;
            }
        }

        if (\in_array($source, $target, true)) {
            $io->error(\sprintf('The source locale "%s" cannot be a target too.', $source));

            return Command::FAILURE;
        }

        $known = $this->catalogues->getCatalogueIdentifiers();
        $unknown = array_diff($catalogue, $known);

        if ([] !== $unknown) {
            $io->error(\sprintf('Unknown catalogue(s): %s.', implode(', ', $unknown)));

            return Command::FAILURE;
        }

        try {
            $provider = $this->providerRegistry->get($provider)->getName();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$this->scopeGuard->accept($io, $scope)) {
            return Command::FAILURE;
        }

        $catalogues = [] !== $catalogue ? array_values(array_unique($catalogue)) : $known;
        $target = array_values(array_unique($target));
        $missingOnly = !$all;

        $keys = 0;
        $cost = null;
        $currency = null;
        $unpriced = false;
        $lowerBound = false;

        foreach ($target as $targetLocale) {
            $io->section(\sprintf('%s → %s (provider: %s%s)', $source, $targetLocale, $provider, '' !== $scope ? ', scope: '.$scope : ''));

            $preview = $this->runner->preview($io, $catalogues, $targetLocale, $source, $provider, $missingOnly, $scope, $retryErrors);
            $keys += $preview->keys;
            $lowerBound = $lowerBound || $preview->costIsLowerBound;

            // Partly priced is unpriced: the keys left out would be billed all the same.
            if ($preview->keys > 0 && (null === $preview->cost || $preview->unpricedKeys > 0)) {
                $unpriced = true;
            }

            if (null !== $preview->cost) {
                $cost = ($cost ?? 0.0) + $preview->cost;
                $currency = $preview->currency;
            }
        }

        if (0 === $keys) {
            return Command::SUCCESS;
        }

        if (null !== $maxCost) {
            // A ceiling that cannot be checked is no ceiling: fail closed.
            if ($unpriced) {
                $io->error(\sprintf('--max-cost is set but the run cannot be priced for provider "%s" (character-billed or model absent from the price list).', $provider));

                return Command::FAILURE;
            }

            if ((float) $cost > $maxCost) {
                $io->error(\sprintf('Estimated cost %.4F %s exceeds --max-cost %.4F — nothing sent.', $cost, $currency, $maxCost));

                return Command::FAILURE;
            }

            // Under the ceiling, a floor proves nothing: the reasoning tokens come on top.
            if ($lowerBound) {
                $io->warning(\sprintf('The estimate is a floor (the model reasons before answering): the real cost can exceed --max-cost %.4F.', $maxCost));
            }
        }

        if ($dryRun) {
            $io->note('Dry run — nothing sent to the provider.');

            return Command::SUCCESS;
        }

        // Interactive: the same confirmation as the journey, "no" by default — a plain
        // enter, or an input that ran dry, must not be what bills the provider.
        // Non-interactive (-n, cron): the options are the decision.
        if ($input->isInteractive() && !$io->confirm(\sprintf('Send these %d key(s) to "%s" now?', $keys, $provider), false)) {
            $io->note('Nothing generated.');

            return Command::SUCCESS;
        }

        $exitCode = Command::SUCCESS;

        foreach ($target as $targetLocale) {
            $io->title(\sprintf('Generating %s → %s', $source, $targetLocale));

            if (Command::SUCCESS !== $this->runner->run($io, $catalogues, $targetLocale, $source, $provider, $missingOnly, $scope, $retryErrors, 're-run with --retry-errors')) {
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
    }
}
