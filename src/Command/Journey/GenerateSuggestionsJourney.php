<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Command\GenerationRunner;
use CylleneDigital\AiTranslationBundle\Coverage\CoverageCalculator;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Guided AI generation: pick the locales, catalogue, provider, scope and key set, see
 * the cost estimate, confirm, generate. The estimate is not an option any more — it is
 * a step of the flow: nothing is sent to a provider before the figures are on screen
 * and the run is confirmed.
 */
#[AsTaggedItem(priority: 100)]
final class GenerateSuggestionsJourney implements JourneyInterface
{
    private const string MODE_MISSING = 'The missing keys only';
    private const string MODE_ALL = 'Every key (re-translate the existing ones too)';
    private const string MODE_RETRY = 'The previously errored keys (retry)';

    public function __construct(
        private readonly CatalogueRegistry $catalogues,
        private readonly TranslationProviderRegistry $providerRegistry,
        private readonly GenerationRunner $runner,
        private readonly CoverageCalculator $coverageCalculator,
        private readonly ConsolePicker $picker,
    ) {
    }

    public function getLabel(): string
    {
        return 'Generate AI translation suggestions';
    }

    public function run(SymfonyStyle $io): int
    {
        $locales = $this->catalogues->getAvailableLocales();

        if (\count($locales) < 2) {
            $io->error([] === $locales
                ? 'No locale available — the scanned translations/ directory is empty.'
                : 'Only one locale available — generation needs a source and a target locale.');

            return Command::FAILURE;
        }

        $providers = $this->providerRegistry->getNames();

        if ([] === $providers) {
            $io->error('No AI provider configured — declare one under "cyllene_digital_ai_translation.providers".');

            return Command::FAILURE;
        }

        // One usually translates FROM the project's default locale — propose it, don't impose it.
        $resolvedDefault = $this->coverageCalculator->getDefaultLocale();
        $sourceLocale = $this->choice($io, 'Source locale', $locales, \in_array($resolvedDefault, $locales, true) ? $resolvedDefault : null);
        $targetLocale = $this->choice($io, 'Target locale', array_values(array_filter(
            $locales,
            static fn (string $locale): bool => $locale !== $sourceLocale,
        )));
        $catalogue = $this->picker->catalogueOrAll($io);
        $provider = \count($providers) > 1 ? $this->choice($io, 'Provider', $providers) : $providers[0];
        $scope = $this->picker->scope($io);
        $mode = $this->choice($io, 'Which keys?', [self::MODE_MISSING, self::MODE_ALL, self::MODE_RETRY]);

        $missingOnly = self::MODE_ALL !== $mode;
        $retryErrors = self::MODE_RETRY === $mode;

        $io->info(array_filter([
            \sprintf('Source locale: %s', $sourceLocale),
            \sprintf('Target locale: %s', $targetLocale),
            \sprintf('Provider: %s', $provider),
            '' !== $scope ? \sprintf('Scope: %s', $scope) : null,
        ]));

        $catalogues = null !== $catalogue ? [$catalogue] : $this->catalogues->getCatalogueIdentifiers();

        // Nothing is sent to a provider before the figures are on screen and confirmed.
        $preview = $this->runner->preview($io, $catalogues, $targetLocale, $sourceLocale, $provider, $missingOnly, $scope, $retryErrors);

        if (0 === $preview->keys) {
            return Command::SUCCESS;
        }

        // "no" by default: the one paid action of the bundle must never be what a plain
        // enter — or an input that ran dry, which answers every question with its
        // default — triggers. "yes" has to be typed.
        if (!$io->confirm(\sprintf('Send these %d key(s) to "%s" now?', $preview->keys, $provider), false)) {
            $io->note('Nothing generated.');

            return Command::SUCCESS;
        }

        return $this->runner->run($io, $catalogues, $targetLocale, $sourceLocale, $provider, $missingOnly, $scope, $retryErrors, \sprintf('re-run picking "%s"', self::MODE_RETRY));
    }

    /**
     * @param list<string> $choices
     */
    private function choice(SymfonyStyle $io, string $label, array $choices, ?string $default = null): string
    {
        $default ??= $choices[0];
        $choice = $io->choice($label, $choices, $default);

        return \is_string($choice) ? $choice : $default;
    }
}
