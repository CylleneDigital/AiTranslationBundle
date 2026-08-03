<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\App;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * A host whose translation files are named the way translation tools write a locale —
 * "messages.pt-BR.yaml" — while the bundle stores and the runtime looks up "pt_BR".
 */
final class DashedLocaleTestKernel extends TestKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $translations = \dirname(__DIR__).'/Fixtures/dashed-locale';

        $container->extension('framework', ['translator' => ['default_path' => $translations]]);
        $container->extension('cyllene_digital_ai_translation', ['translations_path' => $translations]);
    }
}
