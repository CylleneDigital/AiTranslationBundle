# Design decision: Bridges instantiated at compile time

## Context

Several translation backends coexist (an LLM for contextual quality, DeepL for volume, a local
Ollama for offline use…), and a given type can exist **in multiple instances** (two Mistral
accounts, or one Mistral + one Groq, all of type `default`). They must be nameable, selectable at
runtime (the interactive console asks when several are declared; from PHP, the API takes the
name), and auditable (the name is stamped on every suggestion).

## The decision: one named instance per config entry (Symfony Mailer pattern)

Each entry under `cyllene_digital_ai_translation.providers` is turned **at container compile time**
into a concrete service definition: the class is picked by `match($type)`, the arguments
(`api_key`, `model`, `base_uri`, and the type's own options) are set explicitly:

```
providers:
    claude:  { type: anthropic, api_key: ... }   →  cyllene_digital_ai_translation.provider.claude
    mistral: { type: default, base_uri: ... }    →  cyllene_digital_ai_translation.provider.mistral
```

All instances carry the `cyllene_digital_ai_translation.provider` tag;
`TranslationProviderRegistry` collects them (tagged iterator) and resolves by `getName()`.

What this buys:

- **declarative**: a provider is added in YAML, without writing a service definition;
- natural **multi-instance** support for the same type;
- **compile-time validation**: `api_key` required for `openai`/`anthropic`/`deepl`, `model`
  and `base_uri` required for `default`, an option of another type refused (`workspace_id` is
  `anthropic`'s, `organization`/`project`/`structured_output` are `openai`'s), unknown
  `type`, nonexistent `default_provider`: everything breaks at container build time, not on
  the first request (a `default_provider` naming a custom provider excepted: its name is only
  known at runtime, so the registry checks it then);
- **lazy runtime resolution**: `%env(...)%` values remain placeholders in the definition; a
  missing key only breaks the call to the provider concerned.

The **escape-hatch tag** covers the rest: a home-grown provider implements
`TranslationAiProviderInterface`, autoconfiguration applies the tag, and it joins the registry
just like the bridges (see [provider_bridge/custom.md](../provider_bridge/custom.md)).

## Alternatives rejected

- **One service per bridge + selection by alias** (`app.translation_provider: '@my_service'`):
  single-instance per type, and pushes onto the host the writing of service definitions for the
  most common case ("I want Claude with this key").
- **A runtime factory** (`ProviderFactory::create($config)` on every call): moves config errors
  to runtime and re-creates the HTTP clients on every use; compilation does both for free.
- **A Mailer-style DSN** (`translation://deepl?key=...`): compact but opaque; the YAML tree
  documents and validates every option, and `config:dump-reference` works.

## Accepted costs

- A new bridge type requires a bundle release (the `match($type)` is closed); the escape-hatch
  tag covers the wait.
- Two providers carrying the same name (a bridge + a custom one with an identical
  `getName()`) are refused when the registry is built (`LogicException`). Convention: prefix
  your custom providers (`app_glossary`).

## See also

- [Configuration reference](../configuration-reference.md)
- [Custom provider](../provider_bridge/custom.md)
