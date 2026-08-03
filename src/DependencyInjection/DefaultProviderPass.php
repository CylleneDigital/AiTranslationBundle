<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\DependencyInjection;

use CylleneDigital\AiTranslationBundle\Provider\TranslationAiProviderInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Checks `default_provider` once every provider is tagged. A custom provider is only
 * named by its getName(), at runtime: as soon as one is registered, the name may well
 * be its own and the TranslationProviderRegistry decides. Without one, the configured
 * bridges are the whole list, and a typo still breaks the container build rather than
 * the first generation.
 *
 * @internal
 */
#[Exclude]
final class DefaultProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('cyllene_digital_ai_translation.default_provider')) {
            return;
        }

        $default = $container->getParameter('cyllene_digital_ai_translation.default_provider');

        if (!\is_string($default)) {
            return;
        }

        $configured = [];

        foreach ($container->findTaggedServiceIds(TranslationAiProviderInterface::TAG) as $tags) {
            $attributes = $tags[0] ?? [];
            $name = \is_array($attributes) ? ($attributes['name'] ?? null) : null;

            // A tag without a name is a custom provider (autoconfigured from the interface).
            if (!\is_string($name)) {
                return;
            }

            $configured[] = $name;
        }

        if (!\in_array($default, $configured, true)) {
            throw new InvalidConfigurationException(\sprintf('The default provider "%s" is neither declared under "cyllene_digital_ai_translation.providers" (declared: %s) nor a custom provider.', $default, [] === $configured ? '(none)' : implode(', ', $configured)));
        }
    }
}
