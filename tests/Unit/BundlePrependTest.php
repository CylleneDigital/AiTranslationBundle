<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit;

use CylleneDigital\AiTranslationBundle\CylleneDigitalAiTranslationBundle;
use CylleneDigital\AiTranslationBundle\Tests\InspectsUntypedData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * What the bundle prepends into the host's configuration — and, more importantly, what
 * it must not prepend into a kernel that has no such extension.
 */
final class BundlePrependTest extends TestCase
{
    use InspectsUntypedData;

    public function testNothingIsPrependedIntoExtensionsTheHostDoesNotHave(): void
    {
        $builder = new ContainerBuilder();

        // A kernel with neither FrameworkBundle nor DoctrineBundle: prepending an
        // unknown extension's config would fail the build ("There is no extension able
        // to load the configuration for framework").
        $this->prepend($builder);

        self::assertSame([], $builder->getExtensionConfig('framework'));
        self::assertSame([], $builder->getExtensionConfig('doctrine'));
    }

    public function testTheDoctrineMappingIsPrependedWhenDoctrineIsRegistered(): void
    {
        $builder = new ContainerBuilder();
        $builder->registerExtension($this->createExtension('doctrine'));

        $this->prepend($builder);

        $config = $builder->getExtensionConfig('doctrine');

        $mappings = self::at($config, 0, 'orm', 'mappings');
        self::assertIsArray($mappings);
        self::assertArrayHasKey('CylleneDigitalAiTranslationBundle', $mappings);
    }

    private function prepend(ContainerBuilder $builder): void
    {
        $bundle = new CylleneDigitalAiTranslationBundle();
        $instanceof = [];
        $configurator = new ContainerConfigurator(
            $builder,
            new PhpFileLoader($builder, new FileLocator()),
            $instanceof,
            __FILE__,
            __FILE__,
        );

        $bundle->prependExtension($configurator, $builder);
    }

    private function createExtension(string $alias): ExtensionInterface
    {
        return new class($alias) implements ExtensionInterface {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getNamespace(): string
            {
                return '';
            }

            public function getXsdValidationBasePath(): false
            {
                return false;
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
