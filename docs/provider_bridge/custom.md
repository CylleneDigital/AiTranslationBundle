# Custom provider

An in-house backend (another API, an internal engine, a business glossary…) plugs in by
implementing the interface, with **no configuration**: the tag is applied by autoconfiguration and
the provider becomes selectable by its name, just like the built-in bridges.

## The interface

```php
use CylleneDigital\AiTranslationBundle\Provider\TranslationAiProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Provider\TranslationResult;

final class GlossaryProvider implements TranslationAiProviderInterface
{
    public function getName(): string
    {
        return 'glossary'; // the name used to select it (console menu, API) and in the audit trail
    }

    /**
     * @param array<string, string> $texts translation key => source text
     *
     * @return array<string, TranslationResult>
     */
    public function translate(array $texts, string $sourceLocale, string $targetLocale, string $catalogue): array
    {
        $results = [];
        foreach ($texts as $key => $text) {
            // A key left out of the reply becomes an errored suggestion and the run is
            // reported as failed: only leave out what you genuinely cannot translate.
            if ($translated = $this->lookup($text, $sourceLocale, $targetLocale)) {
                $results[$key] = new TranslationResult($translated, confidence: 1.0, metadata: ['type' => 'glossary']);
            }
        }

        return $results;
    }
}
```

## The contract

- **Batches**: `translate()` receives at most 20 entries (the service's batch size) and
  should return every one of them. A key left out is stored as an errored suggestion and the
  run ends as a failure; keys that were not requested are discarded.
- **Errors**: throw `TranslationProviderException`, choosing the factory by what failed:
  - `::requestFailed()` when the call itself failed (unreachable backend, authentication,
    quota): the next calls would fail too, so the run stops and every key not yet
    translated is errored;
  - `::invalidResponse()` when the call went through but the reply cannot be used: only
    this batch is errored, the next ones are still sent.
- **`TranslationResult`**: `translation` (the value), `confidence` (default `0.9`, see
  [Suggestions](../concepts/suggestions.md)), `metadata` (free-form context displayed in
  review).

## Registration

Nothing to do if the service is autoconfigured: the interface carries the
`cyllene_digital_ai_translation.provider` tag, which `TranslationProviderRegistry` collects.
Without autoconfiguration, tag it manually:

```yaml
services:
    App\Translation\GlossaryProvider:
        tags: ['cyllene_digital_ai_translation.provider']
```

The provider can then serve as `default_provider` in the config, just like a built-in bridge,
and it is the default without any setting when it is the only provider.
Its `getName()` must be unique (a provider named like a configured bridge or another custom
provider is refused) and at most 64 characters long, the size of the column it is stored in.

## Cost estimate (optional)

Additionally implement `CostEstimatingProviderInterface` so the cost estimate shown
before a generation run can price it on your backend:

```php
public function estimateRun(array $chunks, string $sourceLocale, string $targetLocale, string $catalogue): ProviderRunEstimate;
```

You receive the exact batches `translate()` would receive and respond with whatever your
billing can express: tokens + cost (`cost`/`currency`), or quota (`quotaRemaining`/`quotaLimit`),
or nothing (every field is nullable). Without this interface, the estimate falls back to
volumes alone (keys, characters, API calls): nothing to do for a free/local backend.
