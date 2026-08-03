<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\ScannedLocaleProvider;
use CylleneDigital\AiTranslationBundle\Override\AuthorProviderInterface;
use CylleneDigital\AiTranslationBundle\Override\NullAuthorProvider;
use CylleneDigital\AiTranslationBundle\Override\TranslationManager;
use CylleneDigital\AiTranslationBundle\Override\TranslationManagerInterface;
use CylleneDigital\AiTranslationBundle\Scope\NullScopeProvider;
use CylleneDigital\AiTranslationBundle\Scope\ScopeProviderInterface;
use CylleneDigital\AiTranslationBundle\Suggestion\TranslationAiService;
use CylleneDigital\AiTranslationBundle\Suggestion\TranslationAiServiceInterface;

/*
 * Services are wired with PHP 8 attributes directly on the classes (#[AsDecorator],
 * #[Autowire], #[AutoconfigureTag], #[AsMessageHandler], #[AsCommand]); this file only
 * enables discovery and picks the default implementations of the host-facing
 * interfaces (an integration package overrides these
 * aliases with #[AsAlias] adapters, since it is registered after this bundle).
 *
 * Excluded from auto-registration:
 *  - Entity/  → data, not services
 *  - Bridge/  → instantiated at compile time from the config tree
 *
 * Everything else that is not a service says so on itself, with #[Exclude]: value
 * objects, events, exceptions and messages. Keeping that on the class rather than in a
 * list here means a new one cannot be forgotten — an unlisted value object is registered
 * as a service, and only survives because the container drops unused private definitions
 * before reporting that it cannot autowire them.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('CylleneDigital\\AiTranslationBundle\\', '../src/')
        ->exclude([
            '../src/CylleneDigitalAiTranslationBundle.php',
            '../src/Entity/',
            '../src/Bridge/',
        ]);

    $services->alias(LocaleProviderInterface::class, ScannedLocaleProvider::class);
    $services->alias(AuthorProviderInterface::class, NullAuthorProvider::class);
    $services->alias(ScopeProviderInterface::class, NullScopeProvider::class);

    $services->alias(TranslationManagerInterface::class, TranslationManager::class);
    $services->alias(TranslationAiServiceInterface::class, TranslationAiService::class);
};
