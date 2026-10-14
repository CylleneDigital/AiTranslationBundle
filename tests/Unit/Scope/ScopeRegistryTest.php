<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Scope;

use CylleneDigital\AiTranslationBundle\Scope\NullScopeProvider;
use CylleneDigital\AiTranslationBundle\Scope\ScopeLocalesProviderInterface;
use CylleneDigital\AiTranslationBundle\Scope\ScopeProviderInterface;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether an override in a locale is served in a scope: through the language chain, as the
 * runtime serves it, and without restriction when the host says nothing about locales.
 */
final class ScopeRegistryTest extends TestCase
{
    #[DataProvider('locales')]
    public function testALocaleIsExposedThroughTheLanguageChain(string $scope, string $locale, bool $expected): void
    {
        $registry = new ScopeRegistry(new class implements ScopeProviderInterface, ScopeLocalesProviderInterface {
            public function getScope(): ?string
            {
                return null;
            }

            public function getAvailableScopes(): array
            {
                return ['web' => 'Web', 'b2b' => 'B2B', 'fr' => 'France'];
            }

            public function getScopeLocales(string $scope): ?array
            {
                return match ($scope) {
                    'web' => ['en_US', 'fr_FR'],
                    'fr' => ['fr'],
                    default => null,
                };
            }
        });

        self::assertSame($expected, $registry->exposesLocale($scope, $locale));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function locales(): iterable
    {
        yield 'a locale the scope exposes' => ['web', 'fr_FR', true];
        yield 'its parent language, served to it' => ['web', 'fr', true];
        yield 'another language' => ['web', 'de_DE', false];
        yield 'a regional variant of an exposed language' => ['fr', 'fr_FR', false];
        yield 'a scope exposing every locale' => ['b2b', 'de_DE', true];
        yield 'the global scope, never restricted' => ['', 'de_DE', true];
    }

    public function testAProviderSayingNothingAboutLocalesRestrictsNothing(): void
    {
        self::assertTrue((new ScopeRegistry(new NullScopeProvider()))->exposesLocale('web', 'de_DE'));
    }
}
