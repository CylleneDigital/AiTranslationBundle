<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle;

use CylleneDigital\AiTranslationBundle\Bridge\AnthropicProvider;
use CylleneDigital\AiTranslationBundle\Bridge\CappedRetryStrategy;
use CylleneDigital\AiTranslationBundle\Bridge\DeeplProvider;
use CylleneDigital\AiTranslationBundle\Bridge\DefaultProvider;
use CylleneDigital\AiTranslationBundle\Bridge\OpenAiProvider;
use CylleneDigital\AiTranslationBundle\DependencyInjection\DefaultProviderPass;
use CylleneDigital\AiTranslationBundle\Estimation\ModelPriceProvider;
use CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessage;
use CylleneDigital\AiTranslationBundle\Provider\CostEstimatingProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\TranslationAiProviderInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Translation management engine, framework-agnostic: catalogue browsing from the host's
 * translations/ files, database overrides, import/export and AI suggestions. Each
 * `providers` config entry becomes a concrete bridge service at compile time (Symfony
 * Mailer style: one named instance per entry, all tagged
 * `cyllene_digital_ai_translation.provider` and collected by the registry). User interfaces
 * live in separate integration packages; this bundle ships none.
 */
#[Exclude]
final class CylleneDigitalAiTranslationBundle extends AbstractBundle
{
    /**
     * Factory of the default transport scheme. Referenced as a plain string: the package
     * that ships it (symfony/doctrine-messenger) is a suggestion, not a requirement.
     */
    private const string DOCTRINE_TRANSPORT_FACTORY = 'Symfony\\Component\\Messenger\\Bridge\\Doctrine\\Transport\\DoctrineTransportFactory';

    protected string $extensionAlias = 'cyllene_digital_ai_translation';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DefaultProviderPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    /**
     * @param array{default_provider: ?string, translations_path: string, additional_paths: array<string, string>, default_locale: ?string, coverage_cache_ttl: ?int, llm_context: ?string, model_prices_url: string|false, generation_lock_ttl: int, messenger_transport_dsn: string, providers: array<string, array{type: string, api_key: ?string, model: ?string, base_uri: ?string, workspace_id: ?string, organization: ?string, project: ?string, structured_output: ?bool, timeout: ?int}>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $container->parameters()
            ->set('cyllene_digital_ai_translation.translations_path', $config['translations_path'])
            ->set('cyllene_digital_ai_translation.additional_paths', $config['additional_paths'])
            ->set('cyllene_digital_ai_translation.default_locale', $config['default_locale'])
            ->set('cyllene_digital_ai_translation.coverage_cache_ttl', $config['coverage_cache_ttl'])
            ->set('cyllene_digital_ai_translation.generation_lock_ttl', $config['generation_lock_ttl'])
            ->set('cyllene_digital_ai_translation.model_prices_url', $config['model_prices_url'])
            ->set('cyllene_digital_ai_translation.default_provider', $config['default_provider'] ?? array_key_first($config['providers']));

        // A retrying client shared by all bridges: rate limits (429), an unavailable
        // provider (503) and Anthropic's "overloaded" (529) are retried twice with exponential backoff before failing the
        // catalogue being translated. Only codes saying the request was NOT processed:
        // after a 500/502/504 the provider may well have finished — and billed — the
        // call, and a retry would pay for it twice. Every wait is capped at 30 s, the
        // provider's Retry-After included: a longer one is not retried (CappedRetryStrategy).
        $builder->setDefinition('cyllene_digital_ai_translation.http_client', new Definition(RetryableHttpClient::class, [
            new Reference('http_client'),
            // [statusCodes, delayMs, multiplier, maxDelayMs]
            new Definition(CappedRetryStrategy::class, [[429, 503, 529], 1000, 2.0, 30000]),
            2,
        ]));

        foreach ($config['providers'] as $name => $provider) {
            $builder->setDefinition(
                'cyllene_digital_ai_translation.provider.'.$name,
                $this->createProviderDefinition($name, $provider, $config['llm_context']),
            );
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Asynchronous generation, when the host has a transport for it: the bundle
        // declares its own Messenger transport and routes GenerateSuggestionsMessage to it
        // — the host only has to run `messenger:consume cyllene_ai_translation`. Without
        // one (the default doctrine:// DSN and no symfony/doctrine-messenger), nothing is
        // declared and the message is handled where it is dispatched. Failures are never retried by
        // Messenger (a retry would re-bill the provider for the same keys; failed keys
        // become errored suggestions instead). The DSN comes from the bundle's
        // `messenger_transport_dsn` config, read raw here (prepend runs before the
        // config is processed): last explicit value wins, like the merge would.
        $dsn = 'doctrine://default?queue_name=cyllene_ai_translation';

        foreach ($builder->getExtensionConfig($this->extensionAlias) as $config) {
            if (isset($config['messenger_transport_dsn']) && \is_string($config['messenger_transport_dsn'])) {
                $dsn = $config['messenger_transport_dsn'];
            }
        }

        // Nothing to prepend into an extension the host does not have: a kernel without
        // FrameworkBundle (or with Messenger left out) would blow up on an unknown
        // "framework" config instead of simply running the generation synchronously.
        // Same for the default doctrine:// DSN when symfony/doctrine-messenger is not
        // installed — declaring a transport no factory supports would turn every
        // dispatch into "no transport supports the given DSN", where an unrouted message
        // is merely handled in-process (correct, just slower).
        if ($builder->hasExtension('framework') && $this->supportsTransportDsn($dsn)) {
            $builder->prependExtensionConfig('framework', [
                'messenger' => [
                    'transports' => [
                        'cyllene_ai_translation' => [
                            'dsn' => $dsn,
                            'retry_strategy' => ['max_retries' => 0],
                        ],
                    ],
                    'routing' => [
                        GenerateSuggestionsMessage::class => 'cyllene_ai_translation',
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'CylleneDigitalAiTranslationBundle' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'CylleneDigital\AiTranslationBundle\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * Whether a factory exists for the transport DSN the bundle is about to declare.
     * Only the default doctrine:// scheme is checked: it is the one the bundle picks on
     * the host's behalf, and symfony/doctrine-messenger is a suggested dependency, not a
     * required one. An explicitly configured DSN is the host's own responsibility.
     */
    private function supportsTransportDsn(string $dsn): bool
    {
        if (!str_starts_with($dsn, 'doctrine://')) {
            return true;
        }

        return ContainerBuilder::willBeAvailable('symfony/doctrine-messenger', self::DOCTRINE_TRANSPORT_FACTORY, ['cyllene-digital/ai-translation-bundle']);
    }

    /**
     * @param array{type: string, api_key: ?string, model: ?string, base_uri: ?string, timeout: ?int, workspace_id?: ?string, organization?: ?string, project?: ?string, structured_output?: ?bool} $provider
     */
    private function createProviderDefinition(string $name, array $provider, ?string $llmContext): Definition
    {
        $definition = new Definition(match ($provider['type']) {
            'default' => DefaultProvider::class,
            'openai' => OpenAiProvider::class,
            'anthropic' => AnthropicProvider::class,
            'deepl' => DeeplProvider::class,
            default => throw new InvalidConfigurationException(\sprintf('Unknown provider type "%s".', $provider['type'])),
        });

        $definition->setArgument('$httpClient', new Reference('cyllene_digital_ai_translation.http_client'));
        $definition->setArgument('$name', $name);
        $definition->setArgument('$apiKey', $provider['api_key']);

        if (null !== $provider['base_uri']) {
            $definition->setArgument('$baseUri', $provider['base_uri']);
        }

        if ('deepl' !== $provider['type']) {
            if (null !== $provider['model']) {
                $definition->setArgument('$model', $provider['model']);
            }

            $definition->setArgument('$llmContext', $llmContext);
            // Feeds the bridge's own cost forecast (CostEstimatingProviderInterface).
            $definition->setArgument('$priceProvider', new Reference(ModelPriceProvider::class));
        }

        if ('anthropic' === $provider['type'] && null !== ($provider['workspace_id'] ?? null)) {
            $definition->setArgument('$workspaceId', $provider['workspace_id']);
        }

        if ('openai' === $provider['type']) {
            foreach (['organization' => '$organization', 'project' => '$project', 'structured_output' => '$structuredOutput'] as $option => $argument) {
                if (null !== ($provider[$option] ?? null)) {
                    $definition->setArgument($argument, $provider[$option]);
                }
            }
        }

        if (null !== ($provider['timeout'] ?? null)) {
            $definition->setArgument('$timeout', $provider['timeout']);
        }

        $definition->addTag(TranslationAiProviderInterface::TAG, ['name' => $name]);

        return $definition;
    }
}
