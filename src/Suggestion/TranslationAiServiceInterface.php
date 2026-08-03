<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Suggestion;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Override\InvalidOverrideException;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueInvalidException;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Provider\UnknownProviderException;

/**
 * The suggestion workflow behind one service: generating suggestions, then approving or
 * rejecting them. Type-hint this interface rather than the implementation, which an
 * integration can then decorate.
 */
interface TranslationAiServiceInterface
{
    /** @see SuggestionGenerator::MAX_KEYS_PER_RUN */
    public const int MAX_KEYS_PER_RUN = SuggestionGenerator::MAX_KEYS_PER_RUN;

    /**
     * @see SuggestionGenerator::generateSuggestions()
     *
     * @throws \LogicException              when called inside a database transaction (a programming error)
     * @throws InvalidOverrideException     when the target locale, the catalogue, the scope or the source locale cannot be stored
     * @throws UnknownProviderException     when the provider name is unknown
     * @throws TranslationProviderException when the provider failed, after the unfulfilled keys were stored as errored suggestions
     */
    public function generateSuggestions(
        string $catalogue,
        string $targetLocale,
        string $sourceLocale,
        ?string $providerName = null,
        bool $onlyMissing = true,
        string $scope = '',
        bool $retryErrors = false,
    ): GenerationOutcome;

    /**
     * @throws PlaceholderMismatchException       when the applied value loses or adds placeholders (see $missing / $unexpected)
     * @throws SuggestionValueMissingException    when there is no value to apply (errored row)
     * @throws TranslationValueInvalidException   when the applied value breaks the catalogue's declared syntax
     * @throws SuggestionAlreadyReviewedException when the suggestion is no longer pending
     *
     * @see SuggestionReviewer::approve()
     */
    public function approve(TranslationSuggestion $suggestion, ?string $finalValue = null): void;

    /**
     * @throws SuggestionAlreadyReviewedException when the suggestion is no longer pending
     *
     * @see SuggestionReviewer::reject()
     */
    public function reject(TranslationSuggestion $suggestion): void;

    /**
     * @param iterable<TranslationSuggestion> $suggestions
     *
     * @return list<TranslationSuggestion> the suggestions skipped (no value, or a failed guard)
     *
     * @see SuggestionReviewer::approveMany()
     */
    public function approveMany(iterable $suggestions): array;

    /**
     * @param iterable<TranslationSuggestion> $suggestions
     *
     * @see SuggestionReviewer::rejectMany()
     */
    public function rejectMany(iterable $suggestions): void;
}
