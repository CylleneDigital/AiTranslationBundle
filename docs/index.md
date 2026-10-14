# Documentation: Cyllene Digital AI Translation

Start with the **[installation and overview](../README.md)** in the main README, then open a
concept page only for the piece you need to understand or customise.

## Concepts (what each piece is + how it works)

- **[Overrides](concepts/overrides.md)**: the decorated translator, the cache layers,
  automatic invalidation.
- **[Catalogues & missing keys](concepts/catalogues.md)**: the `translations/` scan into
  Category/Domain/Type, where the locales come from, the fallback-chain merge, what
  "missing" means exactly.
- **[Coverage](concepts/coverage.md)**: the default locale, what counts as translated,
  what the percentage cannot see, the CI gate.
- **[AI suggestions](concepts/suggestions.md)**: a suggestion's lifecycle
  (generation → review → override or rejection), confidence, the audit trail.
- **[Manual override](concepts/manual-override.md)**: editing one translation; the four
  doors (CLI, host integration, approval, import), what every save guarantees, orphans.
- **[Scopes](concepts/scopes.md)**: per-site/brand/channel values; the shadowing order,
  the host-side `ScopeProviderInterface`, the scope in every workflow.

## Provider bridges (reference by type)

One page per `providers.<name>.type` value, with every option, the wire format and the
error behaviour:

- **[`default`](provider_bridge/default.md)**: any `/chat/completions` API (Mistral, Groq,
  OpenRouter, a local Ollama…), the field's one interoperable contract. `model` and
  `base_uri` required.
- **[`openai`](provider_bridge/openai.md)**: OpenAI itself, the same bridge with its
  endpoint and model prefilled; `api_key` required.
- **[`anthropic`](provider_bridge/anthropic.md)**: Claude's Messages API (a dedicated
  bridge, its format differs from the `/chat/completions` contract).
- **[`deepl`](provider_bridge/deepl.md)**: the DeepL v2 API (not an LLM: no prompt, locale
  mapping, quotas).
- **[Custom provider](provider_bridge/custom.md)**: implement
  `TranslationAiProviderInterface` and become selectable by name.

## Reference

- **[Public contract](public-contract.md)**: what semantic versioning covers (the
  configuration, the entities, the interfaces, the value objects and the services an
  integration may inject, method by method) and what stays internal.
- **[Configuration reference](configuration-reference.md)**: every
  `cyllene_digital_ai_translation` key, its type, its default and its meaning.
- **Operations** (`operations/`), one file per topic:
  - [CLI commands](operations/commands.md): the interactive console
    (`cyllene:ai-translation`, every guided task) and the automation commands
    (`coverage`, `generate`, `export-overrides`, `import-overrides`, `cleanup-suggestions`,
    `purge-scopes`).
  - [Caches](operations/cache.md): the cache layers at play, what invalidates them, when
    to step in manually.
  - [Running on several servers](operations/multi-server.md): the two settings a multi-node
    deployment needs, and everything that needs none.
  - [Doctrine DBAL 3](operations/doctrine-dbal-3.md): the one setting a DBAL 3 host needs
    (`use_savepoints`), and why the bundle does not set it itself.

## Decision records

Why the engine is built the way it is, and what was left as it is on purpose, one file per
decision: the **[index of the decision records](adr/README.md)**. Among them:

- [The translator decorator](adr/0002-translator-decorated-at-priority-minus-10.md): why a
  decorator rather than a custom loader or compiled-file edits.
- [The same-language fallback](adr/0003-missing-keys-within-one-language.md): why `fr` files are
  merged under `fr_FR`, and what "missing" means as a result.
- [Compile-time bridges](adr/0004-providers-built-at-compile-time.md): why providers become
  container services at compile time.
- [Mandatory human review](adr/0011-no-suggestion-applied-without-a-human.md): why nothing
  AI-generated is ever applied without an explicit approval.
- [No retry that can bill twice](adr/0006-no-retry-that-can-bill-twice.md) and
  [one generation at a time](adr/0008-one-generation-at-a-time.md): what keeps a paid API from
  being billed twice for the same keys.
