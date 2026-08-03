<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\App;

use CylleneDigital\AiTranslationBundle\Scope\ScopeProviderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The host application WITH a scope dimension: same fixtures as {@see TestKernel},
 * plus the {@see FixedScopeProvider} plugged over the ScopeProviderInterface alias —
 * exactly the way a real integration package overrides it with #[AsAlias].
 */
final class ScopedTestKernel extends TestKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $services = $container->services();
        $services->set(FixedScopeProvider::class);
        $services->alias(ScopeProviderInterface::class, FixedScopeProvider::class);
    }
}
