<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Estimation\GenerationCostEstimator;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The two halves of a generation from the console — the pre-flight estimate and the
 * run itself — shared between the option-driven `generate` command (cron, CI) and the
 * interactive generation journey, so the figures and the outcome reporting can never
 * drift apart. Asking for the launch confirmation stays with the caller.
 */
final class GenerationRunner
{
    public function __construct(
        private readonly GenerationCostEstimator $costEstimator,
        private readonly SuggestionGenerator $generator,
    ) {
    }

    /**
     * Prints the keys and estimated cost per catalogue, then the totals, and returns
     * them for the caller's confirmation (or cost ceiling).
     *
     * @param list<string> $catalogues
     */
    public function preview(SymfonyStyle $io, array $catalogues, string $targetLocale, string $sourceLocale, string $provider, bool $missingOnly, string $scope, bool $retryErrors): GenerationPreview
    {
        $totalKeys = 0;
        $totalCost = null;
        $costCurrency = null;
        $quota = null;
        $beyondCap = 0;
        $unpricedKeys = 0;
        $measured = false;
        $lowerBound = false;

        foreach ($catalogues as $catalogue) {
            $estimate = $this->costEstimator->estimate($catalogue, $targetLocale, $sourceLocale, $provider, $missingOnly, $scope, $retryErrors);

            if (0 === $estimate->keys) {
                continue;
            }

            $io->text(\sprintf(
                '%s — %d key(s)%s%s',
                $catalogue,
                $estimate->keys,
                $estimate->isPriced() ? \sprintf(', estimated cost %s %.4F %s', $estimate->costIsLowerBound ? '≥' : '≈', $estimate->cost, $estimate->currency) : '',
                $estimate->isCapped() ? \sprintf(' <comment>(capped: %d more key(s) left for a later run)</comment>', $estimate->keysBeyondCap) : '',
            ));
            $totalKeys += $estimate->keys;
            $beyondCap += $estimate->keysBeyondCap;
            $lowerBound = $lowerBound || $estimate->costIsLowerBound;

            if ($estimate->isPriced()) {
                $totalCost = ($totalCost ?? 0.0) + (float) $estimate->cost;
                $costCurrency = $estimate->currency;
            } else {
                $unpricedKeys += $estimate->keys;
            }

            $measured = $measured || null !== $estimate->inputTokens;

            if (null !== $estimate->quotaRemaining && null !== $estimate->quotaLimit) {
                $quota = [$estimate->quotaRemaining, $estimate->quotaLimit];
            }
        }

        if (0 === $totalKeys) {
            $io->info('Nothing to translate — every key already has a value or a pending suggestion.');

            return new GenerationPreview(0, null, null);
        }

        $io->newLine();
        // Saying nothing about the cost would read as "free": an unpriced run says why.
        $io->info(\sprintf(
            '%d suggestion(s) to generate%s%s.',
            $totalKeys,
            match (true) {
                null !== $totalCost => \sprintf(' — total estimated cost %s %.4F %s (LiteLLM list prices)', $lowerBound ? '≥' : '≈', $totalCost, $costCurrency),
                null !== $quota => ' — billed by characters, see the quota below',
                $measured => ' — cost not estimated: no list price for this model, or the price list is unreachable',
                default => ' — cost not estimated: this provider gives no estimate',
            },
            null !== $totalCost && $unpricedKeys > 0 ? \sprintf(', %d key(s) left out because they could not be priced', $unpricedKeys) : '',
        ));

        if ($lowerBound && null !== $totalCost) {
            $io->note('This model reasons before answering: the reasoning tokens are billed as output and cannot be forecast — the real cost can be several times this floor.');
        }

        if (null !== $quota) {
            $io->info(\sprintf('DeepL quota: %d characters remaining of %d.', $quota[0], $quota[1]));
        }

        if ($beyondCap > 0) {
            $io->warning(\sprintf(
                '%d key(s) are beyond the %d-key per-run cap and are NOT part of these figures — run the generation again afterwards to pick them up.',
                $beyondCap,
                SuggestionGenerator::MAX_KEYS_PER_RUN,
            ));
        }

        return new GenerationPreview($totalKeys, $totalCost, $costCurrency, $lowerBound, $unpricedKeys);
    }

    /**
     * Generates catalogue by catalogue and reports the outcome. FAILURE when a
     * catalogue failed the provider; a catalogue locked by another run is only a
     * warning — nothing was lost, the other run is doing the work.
     *
     * @param list<string> $catalogues
     * @param string       $retryHint  how the caller re-runs the errored keys, for the failure message
     */
    public function run(SymfonyStyle $io, array $catalogues, string $targetLocale, string $sourceLocale, string $provider, bool $missingOnly, string $scope, bool $retryErrors, string $retryHint): int
    {
        $totalSuggestions = 0;
        $totalErrors = 0;
        $totalSkipped = 0;

        foreach ($catalogues as $catalogue) {
            $io->section(\sprintf('Catalogue "%s"', $catalogue));

            try {
                $outcome = $this->generator->generateSuggestions($catalogue, $targetLocale, $sourceLocale, $provider, $missingOnly, $scope, $retryErrors);

                // A run that did not happen is not a run that found nothing: saying
                // "nothing to translate" here would report a catalogue as fully
                // translated while another run is paying to translate it.
                if (!$outcome->completed) {
                    ++$totalSkipped;
                    $io->warning(\sprintf('Skipped: a generation is already running for this catalogue in %s%s. Wait for it to finish, then run this again.', $targetLocale, '' !== $scope ? ' (scope '.$scope.')' : ''));

                    continue;
                }

                $totalSuggestions += $outcome->created;

                $io->text($outcome->created > 0 ? \sprintf('%d suggestion(s) generated.', $outcome->created) : 'Nothing to translate.');
            } catch (TranslationProviderException $e) {
                ++$totalErrors;
                $io->error($e->getMessage());
                // A failed catalogue is not an empty one: the batches before the failure
                // — and, after an unusable reply, the ones after it — are stored.
                $io->text('The suggestions of the batches that succeeded are stored; the other keys are errored suggestions.');
            }
        }

        $io->newLine();

        if ($totalSuggestions > 0) {
            $io->success(\sprintf('%d suggestion(s) generated — review them from the "Review the pending suggestions" menu.', $totalSuggestions));
        } elseif ($totalSkipped === \count($catalogues)) {
            // Every catalogue was locked: nothing ran at all, which the "nothing to
            // generate" wording would have hidden.
            $io->note('Nothing was generated: every selected catalogue is already being translated by another run.');
        } elseif (0 === $totalErrors && 0 === $totalSkipped) {
            $io->success('Nothing to generate — every key already has a value or a pending suggestion.');
        } elseif (0 === $totalErrors) {
            // Some catalogues were locked: "every key already has a value" would cover them
            // too, though nobody looked at them. The warning below names the skipped count.
            $io->success(\sprintf('Nothing to generate in the %d catalogue(s) that ran — every key there already has a value or a pending suggestion.', \count($catalogues) - $totalSkipped));
        }

        if ($totalSkipped > 0 && $totalSkipped !== \count($catalogues)) {
            $io->warning(\sprintf('%d catalogue(s) skipped — a generation was already running for them.', $totalSkipped));
        }

        if ($totalErrors > 0) {
            $io->warning(\sprintf('%d catalogue(s) failed — the unfulfilled keys are stored as errored suggestions: edit them in the review, or %s.', $totalErrors, $retryHint));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
