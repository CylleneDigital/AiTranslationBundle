<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Provider;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A translation backend. The built-in bridges (default, openai, anthropic, deepl) are instantiated
 * at compile time from the `cyllene_digital_ai_translation.providers` config; custom
 * implementations register themselves with the `cyllene_digital_ai_translation.provider` tag
 * (added automatically through autoconfiguration) and become selectable by name.
 */
#[AutoconfigureTag(TranslationAiProviderInterface::TAG)]
interface TranslationAiProviderInterface
{
    /** The tag every provider carries — autoconfigured from this interface, set on the configured bridges. */
    public const string TAG = 'cyllene_digital_ai_translation.provider';

    /** The name used in the config, the CLI and the suggestion audit trail. */
    public function getName(): string;

    /**
     * Translates a batch of catalogue entries. Return what could be translated: a key
     * absent from the returned array is stored as an errored suggestion (no value, the
     * reason on the row, retried by a later "retry errors" run), and the run is
     * reported as failed — the other keys of the batch are kept. Throw only when nothing
     * could be done (backend unreachable, garbage reply).
     *
     * @param array<string, string> $texts translation key => source text
     *
     * @return array<string, TranslationResult> translation key => result
     *
     * @throws TranslationProviderException when the backend is unreachable or replies garbage
     */
    public function translate(array $texts, string $sourceLocale, string $targetLocale, string $catalogue): array;
}
