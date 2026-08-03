<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\DependencyInjection;

use CylleneDigital\AiTranslationBundle\DependencyInjection\DefaultProviderPass;
use CylleneDigital\AiTranslationBundle\Provider\TranslationAiProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class DefaultProviderPassTest extends TestCase
{
    public function testADefaultAmongTheConfiguredBridgesPasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new DefaultProviderPass())->process($this->container('claude', ['claude', 'deepl']));
    }

    public function testATypoIsReportedAtCompileTimeWhenOnlyBridgesAreRegistered(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The default provider "cluade" is neither declared under "cyllene_digital_ai_translation.providers" (declared: claude, deepl) nor a custom provider.');

        (new DefaultProviderPass())->process($this->container('cluade', ['claude', 'deepl']));
    }

    public function testACustomProviderDefersTheCheckToTheRegistry(): void
    {
        // Its name only exists at runtime (getName()): "custom" may well be it.
        $container = $this->container('custom', ['claude']);
        $container->setDefinition('app.custom_provider', (new Definition())->addTag(TranslationAiProviderInterface::TAG));

        $this->expectNotToPerformAssertions();

        (new DefaultProviderPass())->process($container);
    }

    /**
     * @param list<string> $bridges
     */
    private function container(?string $default, array $bridges): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('cyllene_digital_ai_translation.default_provider', $default);

        foreach ($bridges as $name) {
            $container->setDefinition('cyllene_digital_ai_translation.provider.'.$name, (new Definition())->addTag(TranslationAiProviderInterface::TAG, ['name' => $name]));
        }

        return $container;
    }
}
