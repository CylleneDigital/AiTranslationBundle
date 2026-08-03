# Configuration reference

Every option under the `cyllene_digital_ai_translation` root key, what it does, its default value
and where it is used. You can always regenerate the raw tree with:

```bash
bin/console config:dump-reference cyllene_digital_ai_translation
```

## Full tree

```yaml
cyllene_digital_ai_translation:

    # Provider used when none is explicitly requested: the interactive console's
    # generation entry asks which one when several are declared; from PHP, the API
    # takes the name. null = the first declared provider.
    default_provider:     null

    # Directory of the host project's translation files, scanned recursively and
    # presented as category/domain/type (see concepts/catalogues.md).
    translations_path:    '%kernel.project_dir%/translations'

    # Default locale of the project: the coverage reference and the default
    # generation source. null = automatic: the framework's default_locale when it matches an
    # available locale (exactly, or by prefix: "fr" picks fr_FR), else the first
    # available locale, else the framework's default_locale as-is.
    default_locale:       null

    # Coverage report cache, in seconds. 0 = no cache (always-fresh figures);
    # null = automatic: no cache in debug (dev), 300 s otherwise. Minimum 0. The
    # coverage command always recounts from scratch, whatever this setting.
    coverage_cache_ttl:   null

    # Additional translation roots (label => directory), browsed as "@label/…"
    # catalogues, e.g. the translations/ of theme packages. Within a domain, a
    # "@label" override takes precedence over the main root's (as theme files do
    # at runtime). A missing directory is skipped by the scan; the coverage
    # command warns about it. Must be a MAP: the list form ("- /path") is
    # refused at compile time, since its integer keys would become "@0/…"
    # labels. Labels may only hold letters, digits, ".", "-" and "_".
    additional_paths:
        # ex. BootstrapTheme: '%kernel.project_dir%/themes/BootstrapTheme/translations'

    # DSN of the Messenger transport the bundle declares and routes its generation
    # messages to; consume it with "messenger:consume cyllene_ai_translation".
    # Failures are never retried (max_retries: 0): failed keys become errored
    # suggestions. The default needs symfony/doctrine-messenger; point it at any
    # other scheme (amqp://, redis://) to change the backend. Without that package
    # installed, the bundle declares no transport at all and the generation message
    # is handled in-process (correct, just synchronous) instead of failing on a
    # transport nothing can build.
    messenger_transport_dsn: 'doctrine://default?queue_name=cyllene_ai_translation'

    # Free-text project context appended to the LLM system prompts: audience,
    # tone, product vocabulary, imposed terminology ("always translate Cart as
    # Panier"). Ignored by "deepl". Global: a scope can add its
    # own on top (see "Scope context" in concepts/suggestions.md).
    llm_context:          null

    # Source of the per-token LLM prices the cost estimator uses, or false to
    # disable the lookup (the estimate then reports volumes only, i.e. keys, characters
    # and API calls, and never leaves the network). Default: LiteLLM's public price
    # list, fetched once and cached 24 h (5 min on failure). A failing lookup is
    # never fatal: the cost figures are simply omitted. Must be a non-empty string,
    # or false.
    model_prices_url:     'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json'

    # Seconds a generation run may hold its lock without refreshing it. The lock
    # stops two concurrent runs of the same (catalogue, locale, scope) from calling
    # — and paying — the provider twice. WHERE the lock is held is decided by the
    # host through the standard "framework.lock" configuration, not here. Minimum
    # 60. See "Generation locks" below.
    generation_lock_ttl:  1800

    # Named translation backends. Each entry becomes a service
    # `cyllene_digital_ai_translation.provider.<name>` tagged
    # `cyllene_digital_ai_translation.provider`, collected by the registry.
    providers:

        # Prototype: the key is the provider name (CLI, audit, default_provider),
        # at most 64 characters, since it is stored on every suggestion and journal entry.
        <name>:

            # default | openai | anthropic | deepl (required). Determines the bridge
            # class. "default" speaks the /chat/completions contract every modern LLM
            # API exposes and assumes nothing: give it a model and an endpoint.
            type:                 ~

            # API key. Required for "openai", "anthropic" and "deepl" (validated at
            # compile time); optional for "default" (keyless local servers, e.g. Ollama).
            api_key:              null

            # LLM model id. Required for "default", ignored by "deepl".
            # Defaults: claude-sonnet-5 (anthropic), gpt-4o-mini (openai).
            model:                null

            # API root. Required for "default". Defaults: https://api.openai.com/v1
            # (openai), https://api.anthropic.com (anthropic), auto-detected for "deepl"
            # (free endpoint when the key ends with ":fx").
            base_uri:             null

            # "anthropic" only (refused on the other types). Sent as the
            # anthropic-workspace-id header, required when api_key is an
            # identity-linked key; useless for a regular workspace-bound key.
            workspace_id:         null

            # "openai" only (refused on the other types). Sent as the
            # OpenAI-Organization / OpenAI-Project headers: which account the
            # call is attributed to and billed on.
            organization:         null
            project:              null

            # "openai" only, on by default. Sends a strict JSON schema built on
            # each batch (response_format), so the API enforces the reply shape
            # instead of the prompt asking for it. Turn it off for a gateway that
            # speaks the endpoint but not the parameter.
            structured_output:    null

            # Idle timeout of the provider calls, in seconds — how long the
            # response may stay silent before failing, not the total duration.
            # Raise it for slow local servers (Ollama on CPU…). Minimum 1.
            # Defaults: 120 (default, openai, anthropic), 60 (deepl).
            timeout:              null
```

## Notes

- **HTTP retries**: all bridges share a `RetryableHttpClient`; 429, 503 and 529 (Anthropic overloaded) are retried
  twice with exponential backoff (1 s then 2 s) before failing. Every wait is capped at 30 s,
  the provider's `Retry-After` included: a longer one is not retried, and the batch's keys
  become errored suggestions. Not 500/502/504: the provider may have processed (and billed)
  the call anyway, and a retry would pay twice. No call follows a redirect.

- **`default_provider`** must name a provider: an entry of `providers`, or a custom provider's
  `getName()`. A name matching neither throws an `InvalidConfigurationException` at container
  compile time, unless a custom provider is registered, whose name is only known at runtime:
  the unknown name then fails on the first generation, with the list of available providers.
  Left unset, it falls back to the first entry of `providers`, or to the custom provider when
  it is the only one.
- **`providers` may stay empty**: the override workflow works without AI; the interactive
  console's generation entry explains that no provider is configured, and a host surface
  listing suggestions can show its own warning.
- **Custom providers** (services implementing `TranslationAiProviderInterface`) do not go
  through this tree; see [provider_bridge/custom.md](provider_bridge/custom.md).
- The details of each type's options (wire formats, error behaviours, DeepL locale mapping…)
  are in the [provider_bridge/](index.md#provider-bridges-reference-by-type) pages.

## Parameters exposed to the container

| Parameter | Content |
| --- | --- |
| `cyllene_digital_ai_translation.default_provider` | The configured `default_provider`, else the first provider declared under `providers`; `null` when none is declared (a custom provider is then resolved by the registry at runtime). |
| `cyllene_digital_ai_translation.translations_path` | The path as-is (parameters resolved). |
| `cyllene_digital_ai_translation.default_locale` | The configured default locale (or `null`). |
| `cyllene_digital_ai_translation.coverage_cache_ttl` | The configured TTL (or `null` = automatic). |
| `cyllene_digital_ai_translation.additional_paths` | The label => path array as-is. |
| `cyllene_digital_ai_translation.generation_lock_ttl` | The generation lock TTL, in seconds. |
| `cyllene_digital_ai_translation.model_prices_url` | The price-list URL, or `false` when the lookup is disabled. |

## Messenger transport

Generation runs asynchronously only when **the host's code dispatches**
`GenerateSuggestionsMessage` **and** a transport is configured **and** a worker consumes it.
Two separate questions decide it: who triggers the generation, and where a dispatched message
runs:

| Who triggers | No transport | Transport configured (`symfony/doctrine-messenger` installed, or `messenger_transport_dsn` set) |
|---|---|---|
| The console (`cyllene:ai-translation`, `cyllene:ai-translation:generate`) | Synchronous | **Synchronous all the same**: the console never dispatches |
| The host's code dispatching `GenerateSuggestionsMessage` | Synchronous, handled on the spot | **Asynchronous**: queued, run by `messenger:consume` |

Installing `symfony/doctrine-messenger` alone changes nothing until something dispatches the
message, and the bundle never dispatches it by itself. What the host decides:

| Setting | Effect |
|---|---|
| `messenger_transport_dsn` | Any DSN Messenger knows: `doctrine://` (the default), `amqp://`, `redis://`, `in-memory://`… |
| `framework.messenger.routing` (host) | Route the message to your own transport instead of the bundle's. |
| `framework.messenger.failure_transport` (host) | Where failed runs land (`messenger:failed:show`). |
| `generation_lock_ttl` | TTL of the anti-double-billing lock per (catalogue, locale, scope); see [Generation locks](#generation-locks). |
| `framework.lock` (host) | Where that lock is held: local by default, a shared store (Redis…) on several machines. |

And what the host has to run: a worker, `bin/console messenger:consume cyllene_ai_translation`;
the bundle starts none.

The details follow.

**What the bundle declares by itself.** When `symfony/doctrine-messenger` is installed, it
prepends this to the host's Messenger configuration:

```yaml
framework:
    messenger:
        transports:
            cyllene_ai_translation:
                dsn: '<messenger_transport_dsn>'
                retry_strategy: { max_retries: 0 }
        routing:
            'CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessage': cyllene_ai_translation
```

Consume it with `bin/console messenger:consume cyllene_ai_translation`.

**When it declares nothing.** If `symfony/doctrine-messenger` is absent and the DSN still
points at `doctrine://`, the bundle prepends nothing at all: declaring a transport no factory
can build would turn *every* dispatch in the application into an error. The message then stays
unrouted, which Messenger handles in-process: correct, but synchronous, inside whatever
dispatched it. Check with `debug:messenger`: a routed message names its transport.

**Taking over.** Prepended configuration has the LOWEST priority, so anything the host writes
wins. Two ways to take control:

```yaml
# 1. keep the bundle's transport, change where it points
cyllene_digital_ai_translation:
    messenger_transport_dsn: '%env(MESSENGER_TRANSPORT_DSN)%'

# 2. or ignore it entirely and route the message yourself
framework:
    messenger:
        routing:
            'CylleneDigital\AiTranslationBundle\Message\GenerateSuggestionsMessage': async
```

**Retries stay disabled either way.** `retry_strategy: { max_retries: 0 }` is only a second
line of defence: the handler wraps every failure in `UnrecoverableMessageHandlingException`,
which Messenger honours *before* consulting any transport's retry strategy. So even routed to a
transport configured with three retries, a failed generation is never re-delivered: a retry
would call, and bill, the provider again for the same keys. The message goes to the failure
transport instead (`messenger:failed:show`), and the keys it could not translate are stored as
errored suggestions.

**Never on a transactional bus.** A run stores its suggestions chunk by chunk, so that a
failure halfway keeps what the provider has already billed. Inside a database transaction, that
failure would roll every stored chunk back, and replaying the failed message would bill the
whole run again. The generation therefore refuses to start, before any provider call, when a
transaction is open: dispatch `GenerateSuggestionsMessage` on a bus **without** the
`doctrine_transaction` middleware. Sylius' default bus (`sylius.command_bus`) has it: declare a
bus of your own for this message.

```yaml
framework:
    messenger:
        buses:
            app.ai_translation.bus: ~   # default middleware only, no doctrine_transaction
```

```php
public function __construct(
    #[Target('app.ai_translation.bus')] private MessageBusInterface $bus,
) {
}
```

**The interactive console does not use any of this.** `bin/console cyllene:ai-translation`
calls the generation service directly and synchronously: it shows progress per catalogue,
surfaces provider errors as they happen, and returns a meaningful exit code. Nothing is
dispatched, so no transport is involved; configuring one changes nothing for the CLI.

## Generation locks

`GenerationLock` takes a non-blocking lock per (catalogue, target locale, scope) before a
run: a second worker (or a second dispatch of the same work) skips instead of re-calling,
and re-paying, the provider for the keys the first run is already translating.

The lock goes through `symfony/lock`, so **where it is held is the host's decision**,
configured the standard way and not through this bundle:

| Deployment | What to do | Why |
| --- | --- | --- |
| One machine, any number of workers | **Nothing.** | The framework's default store (semaphore, else flock) already arbitrates between every process of the same host. |
| Several machines or containers | Point `framework.lock` at a shared store. | A machine-local store only arbitrates between processes reading the same filesystem: two hosts would each acquire "their" lock and both bill the provider. |

```yaml
# config/packages/framework.yaml: only needed for multi-host deployments
framework:
    lock: '%env(REDIS_URL)%'
```

### The TTL

`generation_lock_ttl` (default 1800 s) is how long a run may hold the lock **without
refreshing it**. The lock is refreshed after every provider batch, so the value only has to
cover a single provider call, not the whole run.

That call is bounded too. A provider's `timeout` is an *idle* timeout, which on its own
caps nothing: a backend trickling bytes never trips it. Each bridge therefore also sets
`max_duration` to four times its `timeout`, so one request has a real ceiling. A 429, 503 or
529 is retried twice, each attempt with its own ceiling, after a wait of at most 30 s, the
provider's `Retry-After` included: a longer one is not retried, the keys of that batch are
stored as errored suggestions instead. Which gives the TTL something to be calibrated against:

```
generation_lock_ttl  >  3 attempts x 4 x (the slowest provider's timeout) + 2 x 30 s
```

The 1800 s default covers the 120 s bridges (1440 s + 60 s = 1500 s at worst). A local Ollama at
`timeout: 6000` needs far more, or a smaller timeout.

It is ignored by the default local stores, which hold the lock until the process ends, a
crash included, since the kernel releases it. It only matters for expiring stores (Redis,
PDO…), i.e. exactly the multi-host case.

Err on the high side. Too short means the lock expires mid-run and a second run bills the
provider again; too long only means a crashed run keeps that one catalogue locked until the
TTL passes, losing nothing (the suggestions already flushed are in the database, and a later run
skips them).

### What the lock does NOT guarantee

It is a cost safeguard, not a correctness one, and it is deliberately degrading: an
unreachable store lets the run proceed rather than refusing it. Correctness is enforced by
the database instead: at most one PENDING suggestion per (locale, catalogue, key, scope),
so a run that slips past the lock cannot produce duplicates, it can only cost money.

## Scopes

Overrides carry an optional `scope` dimension (`''` = global). The scope active at runtime
comes from `ScopeProviderInterface`: the default alias (`NullScopeProvider`) knows no scope;
an integration package replaces it with its own runtime context (the current site, brand
or sales channel). A scoped override masks
the global override for the same key **when its scope is active**; without a resolvable
context (CLI, Messenger workers, admin), global overrides apply: an email rendered
asynchronously can therefore fall back to the global value. AI suggestions carry the scope they
were generated for, and approving one writes a scoped override.
