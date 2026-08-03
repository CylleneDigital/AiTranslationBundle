# `deepl` bridge: the DeepL v2 API

**Not an LLM**: no prompt, no JSON. The texts are sent as-is in `text[]` and come back **in
the same order**, remapped onto the keys by index.

## Options

```yaml
cyllene_digital_ai_translation:
    providers:
        deepl:
            type: deepl
            api_key: '%env(DEEPL_API_KEY)%'
```

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `api_key` | string | — | **Required**. A free-plan key (`:fx` suffix) automatically targets the free endpoint. |
| `base_uri` | string | auto | `https://api-free.deepl.com` when the key ends with `:fx`, `https://api.deepl.com` otherwise. Can be overridden. |
| `model` | — | — | Ignored (no notion of model). |
| `timeout` | int | `60` | Idle timeout of a translation call, in seconds. |

## Locale mapping

DeepL does not speak ICU locales:

| Side | Rule | Examples |
| --- | --- | --- |
| `source_lang` | Language only, uppercased | `fr_FR` → `FR`, `en` → `EN` |
| `target_lang` | Region dropped, except for the only regional targets DeepL offers (EN-US/EN-GB, PT-BR/PT-PT); any other English or Portuguese region gets the European variant. Chinese maps to `ZH-HANT` for Taiwan, Hong Kong, Macau or the Hant script, `ZH-HANS` otherwise | `en_US` → `EN-US`, `en`/`en_CA` → `EN-GB`, `pt`/`pt_AO` → `PT-PT`, `zh_TW` → `ZH-HANT`, `de_AT` → `DE` |

`preserve_formatting: true` is sent, and in real-world tests Symfony placeholders (`%count%`,
`%max%`) came back intact. **However**, unlike the LLM bridges, DeepL has no instruction
channel: `llm_context` does not apply, and there is no contractual guarantee
about placeholders. The one steering input DeepL accepts is its `context` parameter (text
that influences the translation without being translated): the bridge sends the **scope
context** there when the run targets a scope that has one. Two safety nets exist: a suggestion that alters a placeholder is
**flagged** (`placeholder_mismatch`) and **blocked at approval** until the
value is fixed (`edit` in the review, or a `$finalValue` passed to `approve()`).

## Error behaviour

| Situation | Result |
| --- | --- |
| HTTP 429 / 503 | **Retried automatically** (2 retries, backoff 1 s then 2 s, a `Retry-After` above 30 s not waited for); if it persists, `TranslationProviderException` with an actionable message ("rate limited by DeepL (HTTP 429) — retry later or reduce the batch frequency"). |
| HTTP 456 | `TranslationProviderException`: "DeepL quota exhausted (HTTP 456) — the plan character limit has been reached" (DeepL-specific: monthly character quota reached, **not** retried). |
| Other non-2xx / network error (60 s idle timeout, configurable per provider via `timeout`) | `TranslationProviderException` "Request failed". |
| `translations` missing from the response | `TranslationProviderException` "Invalid response". |

Metadata stored on the suggestion: `type`, `detected_source_language` (DeepL detects the
actual language of the source text). The generation adds `placeholder_mismatch` and
`syntax_issues` when they apply (and `only_missing` on an errored row), the approval
`edited_on_approve`, `override_change` and `reverted_to` (see
[suggestions](../concepts/suggestions.md)).
