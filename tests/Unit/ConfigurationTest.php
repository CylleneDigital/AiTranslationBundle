<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit;

use CylleneDigital\AiTranslationBundle\Tests\InspectsUntypedData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The config tree's guard rails (config/definition.php) — the ones easy to trip on
 * from a real host project's YAML.
 */
final class ConfigurationTest extends TestCase
{
    use InspectsUntypedData;

    public function testAdditionalPathsAcceptsTheLabelToDirectoryMap(): void
    {
        $config = $this->process([
            'additional_paths' => ['BootstrapTheme' => '/app/themes/BootstrapTheme/translations'],
        ]);

        self::assertSame(['BootstrapTheme' => '/app/themes/BootstrapTheme/translations'], $config['additional_paths']);
    }

    public function testAdditionalPathsRefusesTheListForm(): void
    {
        // "- /path" in YAML yields integer keys, which would silently become
        // "@0/…" catalogue labels.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/must be a map of label => directory/');

        $this->process([
            'additional_paths' => ['/app/themes/BootstrapTheme/translations'],
        ]);
    }

    public function testAdditionalPathsRefusesAnIllegalLabel(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/labels may only contain/');

        $this->process([
            'additional_paths' => ['bad label!' => '/app/themes/BootstrapTheme/translations'],
        ]);
    }

    public function testWorkspaceIdIsAcceptedOnTheAnthropicProviderType(): void
    {
        $config = $this->process([
            'providers' => [
                'claude' => ['type' => 'anthropic', 'api_key' => 'sk-ant-test', 'workspace_id' => 'wrkspc_test123'],
            ],
        ]);

        self::assertSame('wrkspc_test123', self::at($config, 'providers', 'claude', 'workspace_id'));
    }

    public function testWorkspaceIdIsRefusedOnTheOtherProviderTypes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"workspace_id" option only applies to the "anthropic" provider type/');

        $this->process([
            'providers' => [
                'mistral' => ['type' => 'openai', 'api_key' => 'sk-test', 'workspace_id' => 'wrkspc_test123'],
            ],
        ]);
    }

    public function testTheDefaultTypeRequiresAModelAndAnEndpoint(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"model" option is required for the "default" provider type/');

        $this->process([
            'providers' => ['local' => ['type' => 'default', 'base_uri' => 'http://localhost:11434/v1']],
        ]);
    }

    public function testTheDefaultTypeRefusesAMissingBaseUri(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"base_uri" option is required for the "default" provider type/');

        $this->process([
            'providers' => ['local' => ['type' => 'default', 'model' => 'llama3']],
        ]);
    }

    public function testTheDefaultTypeNeedsNoApiKey(): void
    {
        $config = $this->process([
            'providers' => ['local' => ['type' => 'default', 'model' => 'llama3', 'base_uri' => 'http://localhost:11434/v1']],
        ]);

        self::assertNull(self::at($config, 'providers', 'local', 'api_key'));
    }

    public function testAProviderNameLongerThanTheStoredColumnIsRefusedWithoutLeakingTheKeys(): void
    {
        $name = str_repeat('a', 65);

        try {
            $this->process([
                'providers' => [$name => ['type' => 'openai', 'api_key' => 'sk-secret-value']],
            ]);
            self::fail('The provider name was expected to be refused.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString(\sprintf('The provider name "%s" exceeds 64 characters.', $name), $e->getMessage());
            self::assertStringNotContainsString('sk-secret-value', $e->getMessage());
        }
    }

    public function testTheOpenAiTypeRequiresAnApiKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"api_key" option is required for the "openai", "anthropic" and "deepl" provider types/');

        $this->process([
            'providers' => ['gpt' => ['type' => 'openai']],
        ]);
    }

    public function testTheOpenAiOnlyOptionsAreAcceptedOnThatType(): void
    {
        $config = $this->process([
            'providers' => [
                'gpt' => ['type' => 'openai', 'api_key' => 'sk-test', 'organization' => 'org-123', 'project' => 'proj-456', 'structured_output' => false],
            ],
        ]);

        self::assertSame('org-123', self::at($config, 'providers', 'gpt', 'organization'));
        self::assertSame('proj-456', self::at($config, 'providers', 'gpt', 'project'));
        self::assertFalse(self::at($config, 'providers', 'gpt', 'structured_output'));
    }

    public function testTheOpenAiOnlyOptionsAreRefusedOnTheOtherTypes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/only apply to the "openai" provider type/');

        $this->process([
            'providers' => [
                'local' => ['type' => 'default', 'model' => 'llama3', 'base_uri' => 'http://localhost:11434/v1', 'structured_output' => true],
            ],
        ]);
    }

    public function testAProviderTimeoutIsAccepted(): void
    {
        $config = $this->process([
            'providers' => ['ollama' => ['type' => 'default', 'model' => 'llama3', 'base_uri' => 'http://localhost:11434/v1', 'timeout' => 600]],
        ]);

        self::assertSame(600, self::at($config, 'providers', 'ollama', 'timeout'));
    }

    public function testAProviderTimeoutBelowOneSecondIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/too small for path "cyllene_digital_ai_translation\.providers\.ollama\.timeout"/');

        $this->process([
            'providers' => ['ollama' => ['type' => 'default', 'model' => 'llama3', 'base_uri' => 'http://localhost:11434/v1', 'timeout' => 0]],
        ]);
    }

    /**
     * Where the lock lives is the host's decision (framework.lock), not the bundle's:
     * only how long a run may hold it without refreshing is configured here.
     */
    public function testTheGenerationLockTtlDefaultsToAValueCoveringOneProviderCall(): void
    {
        self::assertSame(1800, $this->process([])['generation_lock_ttl']);
    }

    public function testTheGenerationLockTtlCanBeRaisedForASlowBackend(): void
    {
        self::assertSame(7200, $this->process(['generation_lock_ttl' => 7200])['generation_lock_ttl']);
    }

    public function testAnAbsurdlyShortGenerationLockTtlIsRefused(): void
    {
        // Shorter than a single provider call: the lock would expire mid-run and a
        // second run would bill the provider again.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/too small for path "cyllene_digital_ai_translation\.generation_lock_ttl"/');

        $this->process(['generation_lock_ttl' => 10]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $treeBuilder = new TreeBuilder('cyllene_digital_ai_translation');
        $definitionFile = \dirname(__DIR__, 2).'/config/definition.php';

        $definition = new DefinitionConfigurator(
            $treeBuilder,
            new DefinitionFileLoader($treeBuilder, new FileLocator(), new ContainerBuilder()),
            $definitionFile,
            $definitionFile,
        );

        $configure = require $definitionFile;
        self::assertIsCallable($configure);
        $configure($definition);

        /** @var array<string, mixed> $processed */
        $processed = (new Processor())->process($treeBuilder->buildTree(), [$config]);

        return $processed;
    }
}
