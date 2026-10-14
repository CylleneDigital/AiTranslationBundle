<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\DependencyInjection;

use CylleneDigital\AiTranslationBundle\CylleneDigitalAiTranslationBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `cyllene_digital_ai_translation.default_provider` is public contract: an integration reads it
 * to preset the provider a run will use. Its value for each configuration a host can write.
 */
final class DefaultProviderParameterTest extends TestCase
{
    private const array LOCAL = ['type' => 'default', 'model' => 'mistral', 'base_uri' => 'http://localhost:11434/v1'];

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('configurations')]
    public function testTheParameterNamesTheProviderARunUsesWhenNoneIsGiven(array $config, ?string $expected): void
    {
        $builder = new ContainerBuilder();
        $extension = (new CylleneDigitalAiTranslationBundle())->getContainerExtension() ?? self::fail('The bundle has no extension.');
        $extension->load([$config], $builder);

        self::assertTrue($builder->hasParameter('cyllene_digital_ai_translation.default_provider'));
        self::assertSame($expected, $builder->getParameter('cyllene_digital_ai_translation.default_provider'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function configurations(): iterable
    {
        yield 'the default_provider key' => [['default_provider' => 'second', 'providers' => ['first' => self::LOCAL, 'second' => self::LOCAL]], 'second'];
        yield 'else the first provider declared' => [['providers' => ['first' => self::LOCAL, 'second' => self::LOCAL]], 'first'];
        yield 'else null: no bridge declared, custom providers only' => [[], null];
    }
}
