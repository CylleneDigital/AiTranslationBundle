<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Provider;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves a provider by its config name. Collects everything tagged
 * `cyllene_digital_ai_translation.provider`: the bridges built from the config tree plus
 * any custom implementation (the interface is autoconfigured with that tag).
 */
final class TranslationProviderRegistry
{
    /** Length of the provider column on the suggestions and the journal. */
    public const int MAX_NAME_LENGTH = 64;

    /** @var array<string, TranslationAiProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<TranslationAiProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(TranslationAiProviderInterface::TAG)]
        iterable $providers,
        #[Autowire(param: 'cyllene_digital_ai_translation.default_provider')]
        private readonly ?string $defaultProvider = null,
    ) {
        foreach ($providers as $provider) {
            $name = $provider->getName();

            // A custom provider's getName() escapes the config checks. Failing here, before
            // any call, beats a flush failing after the provider billed it — or a provider
            // silently shadowing another one, depending on the order the tag collects them.
            if (\strlen($name) > self::MAX_NAME_LENGTH) {
                throw new \LogicException(\sprintf('The AI translation provider name "%s" (%s) exceeds %d characters.', $name, $provider::class, self::MAX_NAME_LENGTH));
            }

            if (isset($this->providers[$name])) {
                throw new \LogicException(\sprintf('Two AI translation providers are named "%s" (%s and %s): rename one.', $name, $this->providers[$name]::class, $provider::class));
            }

            $this->providers[$name] = $provider;
        }
    }

    /**
     * @throws UnknownProviderException when the name (or the configured default) is unknown, or no default can be picked
     */
    public function get(?string $name = null): TranslationAiProviderInterface
    {
        // No configured default happens with custom providers only (the config tree
        // falls back to its first entry): a single one is unambiguous.
        $name ??= $this->defaultProvider ?? (1 === \count($this->providers) ? array_key_first($this->providers) : null);

        if (null === $name) {
            throw new UnknownProviderException([] === $this->providers ? 'No AI translation provider configured: declare at least one entry under "cyllene_digital_ai_translation.providers".' : \sprintf('Several AI translation providers are available (%s): name one, or set "cyllene_digital_ai_translation.default_provider".', implode(', ', array_keys($this->providers))));
        }

        return $this->providers[$name] ?? throw new UnknownProviderException(\sprintf('Unknown AI translation provider "%s". Available providers: %s.', $name, [] === $this->providers ? '(none)' : implode(', ', array_keys($this->providers))));
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->providers);
    }
}
