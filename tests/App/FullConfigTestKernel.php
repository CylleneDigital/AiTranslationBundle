<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\App;

use CylleneDigital\AiTranslationBundle\Override\TranslationManager;
use CylleneDigital\AiTranslationBundle\Override\TranslationManagerInterface;
use CylleneDigital\AiTranslationBundle\Suggestion\TranslationAiServiceInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The host application with every configuration key the bundle offers: one provider
 * of each type, an additional translation root (and one whose directory is missing), the TTLs, and an in-memory Messenger
 * transport (symfony/doctrine-messenger is not a dependency of the suite) — so the
 * wiring of each piece is compiled and resolved, not only the minimal one.
 *
 * The facades are unused inside the bundle, so a container would drop them: they are
 * made public here, the way a host autowiring them into its own service keeps them.
 */
final class FullConfigTestKernel extends TestKernel implements CompilerPassInterface
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('cyllene_digital_ai_translation', [
            'default_provider' => 'gpt',
            'additional_paths' => [
                'theme' => \dirname(__DIR__).'/Fixtures/theme-translations',
                // Not deployed here: its catalogues cannot be scanned.
                'gone_theme' => \dirname(__DIR__).'/Fixtures/no-such-theme',
            ],
            'default_locale' => 'en',
            'coverage_cache_ttl' => 60,
            'llm_context' => 'An online shop.',
            'model_prices_url' => false,
            'generation_lock_ttl' => 600,
            'messenger_transport_dsn' => 'in-memory://',
            'providers' => [
                'local' => ['type' => 'default', 'base_uri' => 'http://localhost:11434/v1', 'model' => 'llama3', 'timeout' => 600],
                'claude' => ['type' => 'anthropic', 'api_key' => 'sk-ant-test', 'workspace_id' => 'ws-1'],
                'deepl' => ['type' => 'deepl', 'api_key' => 'deepl-test:fx'],
            ],
        ]);
    }

    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition(TranslationManager::class)->setPublic(true);
        $container->getAlias(TranslationManagerInterface::class)->setPublic(true);
        $container->getAlias(TranslationAiServiceInterface::class)->setPublic(true);
    }
}
