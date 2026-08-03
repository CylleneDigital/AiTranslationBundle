<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueInvalidException;

/**
 * One entry point over the suggestion workflow, for integrations that would rather inject
 * a single service than two.
 *
 * It owns no logic: {@see SuggestionGenerator} produces suggestions, {@see SuggestionReviewer}
 * turns a human decision into an override. Those two have nothing in common but the table
 * they share — one spends money and talks to providers, the other enforces guards and
 * writes overrides — so depend on the one you need when you can.
 */
final readonly class TranslationAiService implements TranslationAiServiceInterface
{
    public function __construct(
        private SuggestionGenerator $generator,
        private SuggestionReviewer $reviewer,
    ) {
    }

    /**
     * @see SuggestionGenerator::generateSuggestions()
     */
    public function generateSuggestions(
        string $catalogue,
        string $targetLocale,
        string $sourceLocale,
        ?string $providerName = null,
        bool $onlyMissing = true,
        string $scope = '',
        bool $retryErrors = false,
    ): GenerationOutcome {
        return $this->generator->generateSuggestions($catalogue, $targetLocale, $sourceLocale, $providerName, $onlyMissing, $scope, $retryErrors);
    }

    /**
     * @throws PlaceholderMismatchException       when the applied value loses or adds placeholders (see $missing / $unexpected)
     * @throws SuggestionValueMissingException    when there is no value to apply (errored row)
     * @throws TranslationValueInvalidException   when the applied value breaks the catalogue's declared syntax
     * @throws SuggestionAlreadyReviewedException when the suggestion is no longer pending
     *
     * @see SuggestionReviewer::approve()
     */
    public function approve(TranslationSuggestion $suggestion, ?string $finalValue = null): void
    {
        $this->reviewer->approve($suggestion, $finalValue);
    }

    /**
     * @throws SuggestionAlreadyReviewedException when the suggestion is no longer pending
     *
     * @see SuggestionReviewer::reject()
     */
    public function reject(TranslationSuggestion $suggestion): void
    {
        $this->reviewer->reject($suggestion);
    }

    /**
     * @param iterable<TranslationSuggestion> $suggestions
     *
     * @return list<TranslationSuggestion> the suggestions skipped (no value, or a failed guard)
     *
     * @see SuggestionReviewer::approveMany()
     */
    public function approveMany(iterable $suggestions): array
    {
        return $this->reviewer->approveMany($suggestions);
    }

    /**
     * @param iterable<TranslationSuggestion> $suggestions
     *
     * @see SuggestionReviewer::rejectMany()
     */
    public function rejectMany(iterable $suggestions): void
    {
        $this->reviewer->rejectMany($suggestions);
    }
}
