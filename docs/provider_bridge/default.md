# `default` bridge: any `/chat/completions` API

The **generic** bridge, and the one to reach for first. It speaks
`POST {base_uri}/chat/completions` with a Bearer token, the one contract the whole field
agrees on: Mistral, Groq, Together, xAI, OpenRouter, a LiteLLM proxy, a local Ollama or
vLLM all expose it, and so do the OpenAI-compatible endpoints of the big clouds.

It assumes **nothing** about the backend: `model` and `base_uri` are required (there is no
sensible default for "any API"), `api_key` is optional for keyless local servers. For OpenAI
itself, the [`openai`](openai.md) bridge is this one with the endpoint and model prefilled.

## Options

```yaml
cyllene_digital_ai_translation:
    providers:
        mistral:
            type: default
            api_key: '%env(MISTRAL_API_KEY)%'
            model: mistral-large-latest
            base_uri: 'https://api.mistral.ai/v1'
        ollama:                                   # local server, no key
            type: default
            model: llama3
            base_uri: 'http://localhost:11434/v1'
```

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `model` | string | — | **Required.** The model id, passed to the API as-is. |
| `base_uri` | string | — | **Required.** API root (the bridge appends `/chat/completions`). |
| `api_key` | string\|null | `null` | Sent as `Authorization: Bearer`. **Optional**: a keyless local server (Ollama) works without it. |
| `timeout` | int | `120` | Idle timeout in seconds. Raise it for a slow local server. |

Both required options are validated at compile time: a `default` provider without a model
or an endpoint fails the container build with a message naming the missing one.

## Wire format

The contract with the model is strict: **one JSON object in, one JSON object out, same
keys**. Each expected value is an object `{"t": translation, "c": confidence 0..1}`; the
model's self-assessment feeds the ordering of the pending suggestions; a plain string value is
tolerated (confidence then falls back to the default `0.9`). The prompt asks for an
**honestly calibrated** confidence (0.95+ only when certain, 0.7-0.9 for a normal
translation, below 0.7 on real doubt). Self-assessment stays approximate, small local
models especially tend to overconfidence, so treat it as a review-ordering hint, not a
probability. The system prompt requires
preserving placeholders (`%name%`, `{count}`, ICU syntax, HTML tags), giving ICU plurals and
ordinals the plural categories of the **target** language rather than the source's (named
in the prompt, read from `intl` when it is available: `Plural categories of "en_US": plural
→ one, other; selectordinal → one, two, few, other.`; without `intl` that line is left out,
the rule stays), keeping the tone of a UI
label, and answering with no commentary or markdown. Parsing nonetheless tolerates
```` ```json ```` fences and prose around the object.

The system prompt also embeds, when configured:

- **`llm_context`**: the free-form project context (audience, tone, vocabulary, imposed
  terminology written in prose), plus the scope's own context when the run targets a scope
  that has one ("Scope context" section).

Other behaviours:

- `temperature: 0.2` is sent (reasonable determinism: it is a standard parameter of the
  contract; the `anthropic` bridge sends none, see its page). A handful of reasoning models
  reject it: use a model that accepts it, or a vendor bridge.
- No `response_format` is sent: the servers speaking this contract disagree on it, and one
  that chokes on the parameter fails the whole call. The shape is asked for in the prompt
  and enforced at parsing. The [`openai`](openai.md) bridge, knowing its backend, sends a
  strict schema instead.
- The reply is capped at **8192 output tokens** (`max_tokens`), far above what a batch
  needs, so a model that loops cannot bill up to its own maximum. The [`openai`](openai.md)
  bridge sends `max_completion_tokens` instead, with 32768 for the reasoning models, which
  think inside the same cap.
- Keys missing from the response become errored suggestions and the run ends as a failure;
  invented keys are discarded.
- Metadata stored on the suggestion: `type` (`chat_completions`: the wire contract, not a
  vendor), `model`, `batch_usage` (the tokens the API reported for the whole call),
  `batch_size` (the keys that call covered) and `batch_id` (one per call). Every suggestion
  of a call carries the same three, so count the usage once per `batch_id`
  ([Token usage](../concepts/suggestions.md#token-usage)). The generation adds
  `placeholder_mismatch` and `syntax_issues` when they apply (and `only_missing` on an
  errored row), the approval `edited_on_approve`, `override_change` and `reverted_to` (see
  [suggestions](../concepts/suggestions.md)).

## Error behaviour

| Situation | Result |
| --- | --- |
| HTTP 429 / 503 / 529 | **Retried automatically** (2 retries, backoff 1 s then 2 s) before failing; a `Retry-After` above 30 s is not waited for. |
| Persistent non-2xx HTTP, network error, idle timeout (120 s, configurable per provider via `timeout`) | `TranslationProviderException` "Request failed"; the current catalogue stops, the command moves on to the next catalogue and exits with `FAILURE` at the end. |
| Reply cut at the output cap (`finish_reason: length`) | `TranslationProviderException` "Invalid response: the reply hit the 8192-token output cap" (32768 for the `openai` bridge's reasoning models): that batch only. |
| Response without `choices[0].message.content` | `TranslationProviderException` "Invalid response": that batch only. |
| Content with no JSON object or invalid JSON | `TranslationProviderException` "no JSON object found" / "not valid JSON": that batch only. |

Beyond the HTTP retries, re-running the generation is idempotent (keys that already have a
pending suggestion are skipped).
