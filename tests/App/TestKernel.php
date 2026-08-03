<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\App;

use CylleneDigital\AiTranslationBundle\CylleneDigitalAiTranslationBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Minimal host application: FrameworkBundle + DoctrineBundle + the bundle under test,
 * browsing the fixture translations/ directory with one (keyless, never called)
 * OpenAI-compatible provider.
 *
 * The HTTP client is a MockHttpClient with no queued response: the suite must never
 * reach the network — not a provider, and not the LiteLLM price list the cost estimator
 * reads. Any outgoing request fails loudly here instead of making the tests depend on a
 * third party's availability.
 */
class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new CylleneDigitalAiTranslationBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->services()
            ->set('http_client', MockHttpClient::class)
            ->args([[]])
            ->public();

        // The framework's fallback logger writes error-level entries to stderr whatever
        // SHELL_VERBOSITY says: the failures the suite provokes on purpose would show up
        // as unexpected output. Tests that check a log entry use their own logger.
        $container->services()->set('logger', NullLogger::class);

        $container->extension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'translator' => [
                'default_path' => __DIR__.'/translations',
                'fallbacks' => ['en'],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(resolve:DATABASE_URL)%',
            ],
            'orm' => [],
        ]);

        $container->extension('cyllene_digital_ai_translation', [
            'translations_path' => __DIR__.'/translations',
            'providers' => [
                'gpt' => [
                    'type' => 'openai',
                    'api_key' => 'sk-test',
                ],
            ],
        ]);
    }
}
