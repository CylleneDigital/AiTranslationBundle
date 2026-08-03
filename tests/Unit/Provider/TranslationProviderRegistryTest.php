<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Provider;

use CylleneDigital\AiTranslationBundle\Provider\TranslationAiProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderRegistry;
use CylleneDigital\AiTranslationBundle\Provider\TranslationResult;
use CylleneDigital\AiTranslationBundle\Provider\UnknownProviderException;
use PHPUnit\Framework\TestCase;

final class TranslationProviderRegistryTest extends TestCase
{
    public function testResolvesByNameAndFallsBackToTheDefault(): void
    {
        $claude = $this->createNamedProvider('claude');
        $deepl = $this->createNamedProvider('deepl');

        $registry = new TranslationProviderRegistry([$claude, $deepl], 'deepl');

        self::assertSame($claude, $registry->get('claude'));
        self::assertSame($deepl, $registry->get());
        self::assertTrue($registry->has('claude'));
        self::assertFalse($registry->has('mistral'));
        self::assertSame(['claude', 'deepl'], $registry->getNames());
    }

    public function testUnknownNameListsTheAvailableProviders(): void
    {
        $registry = new TranslationProviderRegistry([$this->createNamedProvider('claude')], 'claude');

        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('Unknown AI translation provider "mistral". Available providers: claude.');

        $registry->get('mistral');
    }

    public function testEmptyRegistryExplainsHowToConfigureAProvider(): void
    {
        $registry = new TranslationProviderRegistry([], null);

        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('No AI translation provider configured');

        $registry->get();
    }

    public function testASingleProviderIsTheDefaultWithoutConfiguration(): void
    {
        // A host with one custom provider and no "providers" entry: no default_provider.
        $custom = $this->createNamedProvider('custom');

        self::assertSame($custom, (new TranslationProviderRegistry([$custom], null))->get());
    }

    public function testSeveralProvidersWithoutADefaultAskForOne(): void
    {
        $registry = new TranslationProviderRegistry([$this->createNamedProvider('a'), $this->createNamedProvider('b')], null);

        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('Several AI translation providers are available (a, b)');

        $registry->get();
    }

    public function testTwoProvidersWithTheSameNameAreRefused(): void
    {
        // A custom provider named like a configured bridge used to shadow it silently.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Two AI translation providers are named "claude"');

        new TranslationProviderRegistry([$this->createNamedProvider('claude'), $this->createNamedProvider('claude')], 'claude');
    }

    public function testANameLongerThanTheStoredColumnIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('exceeds 64 characters');

        new TranslationProviderRegistry([$this->createNamedProvider(str_repeat('a', 65))], null);
    }

    private function createNamedProvider(string $name): TranslationAiProviderInterface
    {
        return new class($name) implements TranslationAiProviderInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function translate(array $texts, string $sourceLocale, string $targetLocale, string $domain): array
            {
                return array_map(static fn (string $text): TranslationResult => new TranslationResult($text), $texts);
            }
        };
    }
}
