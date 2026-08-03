<?php

declare(strict_types=1);

use CylleneDigital\AiTranslationBundle\Bridge\AnthropicProvider;
use CylleneDigital\AiTranslationBundle\Bridge\OpenAiProvider;
use CylleneDigital\AiTranslationBundle\Estimation\ModelPriceProvider;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/*
 * cyllene_digital_ai_translation:
 *     default_provider: claude            # optional — falls back to the first declared provider
 *     translations_path: '%kernel.project_dir%/translations'   # host translations dir (the default)
 *     providers:
 *         claude:
 *             type: anthropic
 *             api_key: '%env(ANTHROPIC_API_KEY)%'
 *             model: claude-sonnet-5      # optional
 *         mistral:
 *             type: default               # any /chat/completions API — model and base_uri required
 *             api_key: '%env(MISTRAL_API_KEY)%'
 *             model: mistral-large-latest
 *             base_uri: 'https://api.mistral.ai/v1'
 *         gpt:
 *             type: openai                # OpenAI itself — endpoint and model default
 *             api_key: '%env(OPENAI_API_KEY)%'
 *         deepl:
 *             type: deepl
 *             api_key: '%env(DEEPL_API_KEY)%'   # ":fx" suffix → free endpoint, auto-detected
 */
return static function (DefinitionConfigurator $definition): void {
    /** @var ArrayNodeDefinition $rootNode */
    $rootNode = $definition->rootNode();

    $rootNode
        ->children()
            ->scalarNode('default_provider')
                ->info('Provider used when none is explicitly requested: a "providers" entry or a custom provider\'s name. Defaults to the first declared provider, or to the custom provider when it is the only one.')
                ->defaultNull()
            ->end()
            ->scalarNode('translations_path')
                ->info('Directory of the host project\'s translation files, browsed as category/domain/type.')
                ->cannotBeEmpty()
                ->defaultValue('%kernel.project_dir%/translations')
            ->end()
            ->arrayNode('additional_paths')
                ->info('Extra translation roots (label => directory), browsed as "@label/…" catalogues — e.g. theme translations. Their overrides take precedence over the main root within a domain.')
                ->useAttributeAsKey('label')
                ->scalarPrototype()->cannotBeEmpty()->end()
                ->validate()
                    // The YAML list form ("- /path") yields integer keys and would
                    // silently produce "@0/…" catalogues — refuse it loudly instead.
                    ->ifTrue(static fn (array $paths): bool => [] !== array_filter(array_keys($paths), is_int(...)))
                    ->thenInvalid('additional_paths must be a map of label => directory (e.g. "BootstrapTheme: .../themes/BootstrapTheme/translations"), not a list of paths — the label names the "@label/…" catalogues.')
                ->end()
                ->validate()
                    ->ifTrue(static fn (array $paths): bool => [] !== array_filter(
                        array_keys($paths),
                        static fn (string|int $label): bool => 1 !== preg_match('/^[A-Za-z0-9_.-]+$/', (string) $label),
                    ))
                    ->thenInvalid('additional_paths labels may only contain letters, digits, dots, dashes and underscores.')
                ->end()
            ->end()
            ->integerNode('coverage_cache_ttl')
                ->info('Coverage report cache in seconds: 0 = no cache (always live), null = automatic (no cache in debug, 300 s otherwise).')
                ->min(0)
                ->defaultNull()
            ->end()
            ->scalarNode('default_locale')
                ->info('The project\'s default locale: the coverage reference and the default generation source. Falls back to the framework default_locale matched against the available locales, then to the first available locale.')
                ->defaultNull()
            ->end()
            ->scalarNode('messenger_transport_dsn')
                ->info('DSN of the Messenger transport the bundle declares and routes its generation messages to (consume it with "messenger:consume cyllene_ai_translation"). Failures are never retried — failed keys become errored suggestions.')
                ->cannotBeEmpty()
                ->defaultValue('doctrine://default?queue_name=cyllene_ai_translation')
            ->end()
            ->scalarNode('llm_context')
                ->info('Free-text project context appended to the LLM system prompts: audience, tone, product vocabulary, imposed terminology ("always translate Cart as Panier"). Ignored by "deepl".')
                ->defaultNull()
            ->end()
            ->scalarNode('model_prices_url')
                ->info(<<<'INFO'
                    Source of the per-token LLM prices the cost estimator uses, or false to
                    disable the lookup entirely.

                    No provider publishes a pricing API, so the default is LiteLLM's public
                    list — which means one outgoing HTTPS request from the application
                    (cached 24 h, 5 min on failure). Two reasons to change it:

                      * false — the estimator then reports volumes only (keys, characters,
                        API calls) and never leaves the network. Use it where outgoing
                        traffic is filtered or has to be declared;
                      * a URL — an internal mirror of the same JSON, for an air-gapped or
                        egress-restricted deployment.

                    A failing lookup is never fatal: cost figures are simply omitted.
                    INFO)
                ->defaultValue(ModelPriceProvider::DEFAULT_SOURCE_URL)
                ->validate()
                    ->ifTrue(static fn (mixed $value): bool => false !== $value && (!is_string($value) || '' === trim($value)))
                    ->thenInvalid('model_prices_url must be a URL, or false to disable the price lookup.')
                ->end()
            ->end()
            ->integerNode('generation_lock_ttl')
                ->info(<<<'INFO'
                    Seconds a generation run may hold its lock without refreshing it. The lock
                    stops two concurrent runs of the same (catalogue, locale, scope) from calling
                    — and paying — the provider twice.

                    WHERE the lock is held is decided by the host, through the standard
                    "framework.lock" configuration, not by this bundle:

                      * the framework default store is local to the machine (semaphore or flock).
                        That already covers any number of workers on ONE host, which is the usual
                        "several messenger:consume under a process manager" setup;
                      * workers spread over SEVERAL hosts need a shared store — one line of
                        standard configuration on the host side:

                            framework:
                                lock: '%env(REDIS_URL)%'

                    This TTL only matters for expiring stores (Redis, PDO…): the local default
                    stores hold the lock until the process ends, a crash included, and ignore it.
                    The lock is refreshed after every provider batch, so the value has to cover a
                    single provider call — not the whole run. That call is itself bounded: a bridge
                    caps one request at four times its own "timeout", and a 429/503/529 is retried
                    twice after a wait of at most 30 s (a longer Retry-After is not retried), so
                    the rule of thumb is

                        generation_lock_ttl > 3 attempts x 4 x (the slowest provider's timeout) + 2 x 30 s

                    which the 1800 s default covers for the 120 s bridges (1500 s at worst). Raise it for a slow
                    backend — a local Ollama at timeout: 6000 needs far more.

                    Err on the high side: too short means the lock expires mid-run and a second
                    run bills the provider again; too long only means a crashed run keeps that
                    one catalogue locked until the TTL passes, losing nothing.
                    INFO)
                ->min(60)
                ->defaultValue(1800)
            ->end()
            ->arrayNode('providers')
                ->info('Named translation backends. Each entry becomes a service tagged "cyllene_digital_ai_translation.provider".')
                ->useAttributeAsKey('name')
                ->arrayPrototype()
                    ->children()
                        ->enumNode('type')
                            ->values(['default', 'openai', 'anthropic', 'deepl'])
                            ->info('"default" speaks the /chat/completions contract any modern LLM API exposes (Mistral, Groq, OpenRouter, Ollama…) and assumes nothing: give it "model" and "base_uri". The others are vendor bridges.')
                            ->isRequired()
                        ->end()
                        ->scalarNode('api_key')
                            ->info('Required for "openai", "anthropic" and "deepl"; optional for "default" (keyless local servers such as Ollama).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('model')
                            ->info('LLM model id. Required for "default" (no default is guessed for an arbitrary API), ignored by "deepl". Defaults: '.AnthropicProvider::DEFAULT_MODEL.' (anthropic), '.OpenAiProvider::DEFAULT_MODEL.' (openai).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('base_uri')
                            ->info('API endpoint. Required for "default". Defaults: '.OpenAiProvider::DEFAULT_BASE_URI.' (openai), '.AnthropicProvider::DEFAULT_BASE_URI.' (anthropic), auto-detected for DeepL.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('workspace_id')
                            ->info('"anthropic" only. Sent as the anthropic-workspace-id header — required when the api_key is an identity-linked key (the API asks which workspace the request acts in); useless for a regular workspace-bound key.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('organization')
                            ->info('"openai" only. Sent as the OpenAI-Organization header — which organisation the call is attributed to and billed on. Useless for a key already bound to one.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('project')
                            ->info('"openai" only. Sent as the OpenAI-Project header — same idea, one level down.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('structured_output')
                            ->info('"openai" only, on by default. Sends a strict JSON schema built on each batch (response_format), so the API enforces the reply shape instead of the prompt asking for it. Turn it off for a gateway that speaks the endpoint but not the parameter.')
                            ->defaultNull()
                        ->end()
                        ->integerNode('timeout')
                            ->info('Idle timeout of the provider calls, in seconds — how long the response may stay silent before failing, not the total duration. Raise it for slow local servers (Ollama on CPU…). Defaults: 120 (default, openai, anthropic), 60 (deepl).')
                            ->min(1)
                            ->defaultNull()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $provider): bool => in_array($provider['type'], ['openai', 'anthropic', 'deepl'], true) && (null === $provider['api_key'] || '' === $provider['api_key']))
                        ->thenInvalid('The "api_key" option is required for the "openai", "anthropic" and "deepl" provider types.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $provider): bool => 'default' === $provider['type'] && (null === $provider['model'] || '' === $provider['model']))
                        ->thenInvalid('The "model" option is required for the "default" provider type — it targets any /chat/completions API, so there is no model to guess. Use type "openai" for OpenAI\'s own default.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $provider): bool => 'default' === $provider['type'] && (null === $provider['base_uri'] || '' === $provider['base_uri']))
                        ->thenInvalid('The "base_uri" option is required for the "default" provider type — it targets any /chat/completions API, so there is no endpoint to guess. Use type "openai" for OpenAI\'s own endpoint.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $provider): bool => 'anthropic' !== $provider['type'] && null !== $provider['workspace_id'])
                        ->thenInvalid('The "workspace_id" option only applies to the "anthropic" provider type.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $provider): bool => 'openai' !== $provider['type'] && (null !== $provider['organization'] || null !== $provider['project'] || null !== $provider['structured_output']))
                        ->thenInvalid('The "organization", "project" and "structured_output" options only apply to the "openai" provider type.')
                    ->end()
                ->end()
                ->validate()
                    // The name is stored on every suggestion and journal entry: too long,
                    // the first flush would fail AFTER the provider billed the call.
                    // Not thenInvalid('%s'): it would print the whole tree, API keys included.
                    ->always(static function (array $providers): array {
                        foreach (array_keys($providers) as $name) {
                            if (strlen((string) $name) > TranslationProviderRegistry::MAX_NAME_LENGTH) {
                                throw new InvalidConfigurationException(sprintf('The provider name "%s" exceeds %d characters.', $name, TranslationProviderRegistry::MAX_NAME_LENGTH));
                            }
                        }

                        return $providers;
                    })
                ->end()
            ->end()
        ->end()
    ;
};
