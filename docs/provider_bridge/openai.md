# `openai` bridge: OpenAI itself

The [`default`](default.md) bridge plus what only OpenAI offers: the endpoint and a model
prefilled, a **strict JSON schema** enforcing the reply shape, the account-routing headers,
and the `temperature` dropped on the models that refuse it. The wire format and the error
behaviour are the shared ones, so **everything not listed here is on the
[`default`](default.md) page**.

Reach for it when you talk to OpenAI. For any other `/chat/completions` backend (Mistral,
Groq, OpenRouter, a local Ollama), use `default`, which assumes nothing.

## Options

```yaml
cyllene_digital_ai_translation:
    providers:
        gpt:
            type: openai
            api_key: '%env(OPENAI_API_KEY)%'
            model: gpt-4o-mini                   # optional
            organization: '%env(OPENAI_ORG_ID)%' # optional
```

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `api_key` | string | — | **Required** (validated at compile time): there is no keyless OpenAI. Sent as `Authorization: Bearer`. |
| `model` | string | `gpt-4o-mini` | The model id, passed to the API as-is. |
| `base_uri` | string | `https://api.openai.com/v1` | API root. Override it to go through a gateway or a proxy that keeps the OpenAI contract. |
| `organization` | string\|null | `null` | Sent as `OpenAI-Organization`: which organisation the call is attributed to and billed on. Useless for a key already bound to one. |
| `project` | string\|null | `null` | Sent as `OpenAI-Project`: same idea, one level down. |
| `structured_output` | bool | `true` | Sends the strict schema below. Turn it off for a gateway that speaks the endpoint but not the parameter. |
| `timeout` | int | `120` | Idle timeout in seconds. |

Suggestions produced here carry `type: openai` in their metadata, where the generic bridge
writes `chat_completions`.

## The strict schema

The generic bridge can only *ask* for the reply shape in the prompt, because the servers
speaking the contract disagree on `response_format` and one that chokes on it fails the
whole call. OpenAI enforces it, so this bridge builds a schema **from the batch's own
keys** and sends it as `response_format: {type: "json_schema", strict: true}`:

- one required property per requested key, each an object `{"t": …, "c": …}`;
- `additionalProperties: false`, at both levels.

The model can then neither invent a key, nor drop one, nor wrap the object in prose, the
three failure modes the prompt could only discourage. The tolerant parsing stays in place
(it still serves the generic bridge, and gateways where the schema is off).

Past 100 keys in a single batch the API's own schema limits apply, so the call falls back
to plain JSON mode (`{"type": "json_object"}`): still parseable, shape back on the prompt.
The generator chunks at 20 keys, so this is a floor for hosts calling the bridge directly.

## Reasoning models

The o-series and GPT-5 families refuse `temperature` with a 400 instead of ignoring it, so
the bridge sends none for those model ids and keeps `0.2` for the others. Nothing else
changes: they answer the same `/chat/completions` contract.

## What is deliberately not here

The **Responses API** (`/v1/responses`) is OpenAI-only and built for stateful, tool-using
conversations; this bundle sends stateless batches of UI strings and would gain nothing.
The **Batch API** (half price, 24 h window) would fit the workload, but it is an
asynchronous protocol of its own, and Anthropic has an equivalent, so it belongs to a
shared mechanism rather than to this class.
