# `anthropic` bridge: Claude's Messages API

A **dedicated** bridge because the Messages API format differs from the OpenAI contract:
`x-api-key` + `anthropic-version` headers, a root-level `system` field, a response made of
**content blocks**, and **no sampling parameter** (sending `temperature` would be rejected by
recent models).

## Options

```yaml
cyllene_digital_ai_translation:
    providers:
        claude:
            type: anthropic
            api_key: '%env(ANTHROPIC_API_KEY)%'
            model: claude-sonnet-5      # optional, this is the default
```

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `api_key` | string | — | **Required** (validated at config compile time). |
| `model` | string | `claude-sonnet-5` | Claude model id. Pick a larger model (an Opus) for a locale where quality matters more than the bill, or a smaller one (a Haiku) for bulk UI labels. |
| `base_uri` | string | `https://api.anthropic.com` | The bridge appends `/v1/messages`. |
| `workspace_id` | string | `null` | Sent as the `anthropic-workspace-id` header. **Required when `api_key` is an identity-linked key**: the API then refuses the request with "anthropic-workspace-id is required when authenticating with an identity-linked API key" until told which workspace it acts in. Useless (and harmless to omit) for a regular workspace-bound key. |
| `timeout` | int | `120` | Idle timeout in seconds. |

## Wire format

Same strict JSON contract as the [`default`](default.md) bridge (shared system prompt:
placeholders preserved, the target language's plural categories, UI tone, a `{"t": ..., "c": confidence}` JSON object per key,
`llm_context` and the scope's own context when it has one injected). Specifics:

- `anthropic-version: 2023-06-01`, `max_tokens: 8192` (generous for batches of 20 labels);
- the `text` blocks of the response are concatenated (`thinking` blocks are ignored);
- a `stop_reason: refusal` (safety classifiers) is treated as an invalid response (a clear
  exception rather than a silently empty batch); so are a `stop_reason: max_tokens` ("the
  reply hit the 8192-token output cap and was truncated") and a reply with no text block:
  each costs that batch only;
- metadata stored: `type`, `model`, `batch_usage` (input/output tokens of the whole call),
  `batch_size` (the keys the call covered) and `batch_id` (one per call). Every suggestion
  of a call carries the same three: count the usage once per `batch_id`, not once per
  suggestion ([Token usage](../concepts/suggestions.md#token-usage)). The generation adds
  `placeholder_mismatch` and `syntax_issues` when they apply (and `only_missing` on an
  errored row), the approval `edited_on_approve`, `override_change` and `reverted_to` (see
  [suggestions](../concepts/suggestions.md)).

## Error behaviour

Identical to the `default` bridge: 429/503/529 ("overloaded") retried automatically (2 retries, backoff, a
`Retry-After` above 30 s not waited for), then an
exception isolated to the current catalogue; re-running is idempotent. On top of that comes
the `refusal`, `max_tokens` and empty-reply cases above.
