# AI suggestions: lifecycle

A **suggestion** is a row in `cyllene_translation_suggestion`: a translation proposed by a
provider, **awaiting a human decision**. It never affects the application until it is approved
(see the [design note](../design/human-review.md)).

## Generation

`TranslationAiServiceInterface::generateSuggestions(catalogue, targetLocale, sourceLocale, provider?, onlyMissing?, scope?, retryErrors?)`,
triggered by the [interactive console](../operations/commands.md)'s generation entry
(or by whatever the host wires to `GenerateSuggestionsMessage`):

1. **Estimation**: before launching, `GenerationCostEstimator` (which the interactive
   console runs as a confirmation step) quantifies the exact selection the generation would process: keys,
   characters, API calls, tokens, and the estimated cost, based on per-model prices pulled from the
   public LiteLLM list (24 h cache, degrading to volumes only if unreachable; the console then
   says the cost is not estimated, rather than showing none); for DeepL, the account's
   remaining quota via `/v2/usage`.
2. **Collection**: the catalogue's keys in the source locale (effective value: its own
   override, else the one it inherits, i.e. the parent language's or the global one in a scope,
   else the file). Excluded: empty keys, keys longer than the override tables hold (400
   characters; left out of the run and its bill, logged as a `warning`), keys that **already have a pending
   suggestion** in the same scope (no duplicates, errored rows included, unless the run asks
   to *retry errors*), and, in `onlyMissing` mode (the default), keys that already have a
   value in the target locale, read through the language chain, like the files and the
   runtime: an override saved on `fr` covers `fr_FR` exactly as a `fr` file does (to
   generate a regional variant anyway, run *every key*). An empty value is no value: like the coverage report, the
   generation counts `""` (in the file or in an override) as missing, since the translator
   renders it as a blank label. A run that *retries errors* collects the keys of the errored
   rows and nothing else (a key that went missing since was not part of the failed run),
   each treated the way the run that failed on it would have treated it: an errored key of an *every key* run
   is re-sent as it is (it has a target value by definition), one of a *missing keys* run
   only while it is still missing (see [Errored suggestions](#errored-suggestions)).

   **Scope**: a run generates *for* one override scope (`''` = global; an opaque
   code the host defines: a site, a sales channel…). The effective values are then the scope's: its own override, else
   the inherited one (the global override, the parent language's), else the file. The suggestions carry the scope and are
   reviewed per scope; approving one writes a **scoped** override. A key missing globally is
   missing in every scope, so a scoped run fills that scope only; the global value stays
   missing by design.

   **Scope context**: a scope can carry its own free-text context (table
   `cyllene_translation_scope_parameters`, `ScopeParametersManager`; an integration package
   typically owns its editing). When set, the run's provider gets it on top of its
   configuration (`ContextAwareProviderInterface::withAdditionalContext()`, a clone; the
   shared service is untouched): the LLM bridges append a "Scope context" section after the
   project context, DeepL sends it as its `context` parameter. The cost estimate measures
   the same prompts. Nothing is added for the global scope or a scope without context.
3. **Batches**: sent to the provider in batches capped both by count (20) **and** by source
   text volume (~6000 characters): small enough for reliable JSON responses from LLMs, without
   the risk of overflowing on long values. A key the provider leaves out of its reply becomes an
   errored suggestion and the run is reported as failed (the other keys are kept); a
   hallucinated key (absent from the request) is discarded.
4. **Storage**: one `pending` suggestion per translated key, with the source value, the
   source locale, the provider name, the **confidence** and the raw metadata (model, token
   usage of the call: `batch_usage`, with `batch_size` and `batch_id`, the same on every
   suggestion of a provider call; see [Token usage](#token-usage)) for the reviewer (a failed run stores **errored** rows instead, see below). The
   translation's placeholders are compared against the source's (`%x%`, `{{ x }}`, `{x}`, HTML
   tags): a divergence is recorded in `metadata.placeholder_mismatch`, a snapshot of what the
   provider returned, informational: the review card and the approval judge the current value
   again, so a stale entry (after a checker fix, an edit) never decides. Variables are compared by
   presence (a plural repeats them once per branch, and Polish or Russian have more branches
   than English), while HTML tags are compared by count, since a lost closing tag breaks the
   markup. The text of an ICU branch is not a placeholder, even one word between braces
   (`{Madame}` in `{gender, select, female {Madame} other {}}`): it is translated text, and the
   arguments inside it (`{name}`, a nested select) are compared like any other. A validator
   placeholder is compared verbatim (Symfony replaces `{{ limit }}` with its exact spelling, so
   `{{limit}}` is a different, lost placeholder), while the spacing inside an ICU argument
   (`{ count }`) is not significant.
5. **Log**: every run (success, provider failure, or any other failure midway; the keys it
   never sent then become errored rows too) is recorded in
   `cyllene_translation_generation_log`, with the scope it generated for: since generation is
   *fire-and-forget* (Messenger, provider errors deliberately not retried), the journal is the
   trace to query when a run seems to have vanished. If a run fails midway, the count of
   suggestions already created is preserved. The one exception: a failed flush closes the
   entity manager, and nothing more can be written; that run has no entry. Retention: 30
   days by default (`cleanup-suggestions --before`, which purges the journal too).

   A run refuses to start inside a database transaction (a plain `LogicException`, before
   any provider call, not journaled): a rollback would discard what the provider already
   billed.

### Cost guardrails (lock & cap)

Three protections bound the billing of a run, on top of batching:

- **A target the tables can hold**: before the lock and before any provider call, the run
  refuses (`InvalidOverrideException`) a target locale that is malformed, longer than 32
  characters or a case variant of an available locale (`FR_fr` where `fr_FR` exists: the
  message gives the spelling to use; a `-` separator is read as `_` first, so `pt-BR` is
  `pt_BR`), an empty catalogue or one longer than 255, a scope longer than 64 (the rule
  `OverrideWriter::assertStorable()` applies to every write), and a source locale that is
  malformed or longer than 32. Such a run would otherwise bill the provider, then fail its
  first flush, or store suggestions no approval can ever apply (a malformed locale is never
  an override). It has not started: it is not journaled, and the caller gets the exception.
  The CLI already refuses unknown locales and catalogues upstream; this guards the PHP API
  and `GenerateSuggestionsMessage`.

- **Lock per (catalogue, target locale, scope)**: a run takes a non-blocking lock through
  `symfony/lock`. A second run targeting the same triple **skips its turn** (logged as `info`)
  instead of calling (and billing) the provider again. It says so: the returned
  `GenerationOutcome` has `completed = false`, which a caller must not report as "nothing to
  translate" (the catalogue is being translated right now, by someone else). Where the lock is held is the host's
  decision (`framework.lock`): the default store covers any number of workers on one machine,
  a multi-host deployment points it at a shared store; see
  [Generation locks](../configuration-reference.md#generation-locks). A lock store that cannot
  be reached does not stop the translation: the run goes ahead unguarded and logs a
  `warning` (the pending unique index still prevents duplicate suggestions; only the double
  spend becomes possible again).
- **Key cap per message**: `TranslationAiServiceInterface::MAX_KEYS_PER_RUN` (500) bounds the number
  of keys processed in a single message; beyond that, the run stops at the limit (logged as
  `warning`) and the rest is left for a later run. A giant catalogue therefore cannot turn
  into hundreds of billed calls at once. The **estimate applies the same cap** and reports
  what it left out (`GenerationEstimate::$keysBeyondCap`), so the forecast describes the run
  that will actually happen.

On the Messenger handler side, a provider failure **and** a non-provider failure (e.g. a
database write after the paid call) are both logged and rethrown as an
`UnrecoverableMessageHandlingException`: the provider has already been billed, so a Messenger
*retry* would call it again for nothing (the bundle's transport is declared with
`max_retries: 0` for the same reason, and the unrecoverable marker holds even if the host
changes that strategy). The message lands in the **failure transport** when the host declares
one (`messenger:failed:show` is where a vanished run is explained) instead of disappearing
into the log. Re-running the generation later remains idempotent (keys already `pending` are
skipped), which is a deliberate decision rather than something a worker redoes on its own.

### Errored suggestions

Which keys end up errored depends on what failed:

- **the request itself** (quota, authentication, network): the run stops (the next calls
  would fail too) and every key not yet translated is errored;
- **a reply that cannot be read** (not JSON, truncated, another shape): only that batch is
  errored, the next batches are still sent;
- **a reply that leaves keys out**: only those keys are errored.

In the last two cases the run still ends as a failure, in the journal and for the caller.

Each such key is stored as an **errored pending suggestion**: no value yet (`suggested_value` NULL), confidence 0, the
requested provider, and the full provider error in `generation_error`. The reviewer sees the
failure **per row**, can write the translation by hand before approving it, or reject the row. Approving an errored row **without a value is refused**
(`SuggestionValueMissingException`); batch approval skips such rows and reports them. A later
run skips errored rows like any pending suggestion; a run with *retry errors* re-sends their
keys (those keys only) and **refills the very same rows** on success (value, confidence,
the current source text and provider, error cleared); a new
failure just refreshes the stored error, never duplicates the row.

An errored row records the mode of the run that failed on it (`metadata.only_missing`), and
the retry treats its key the same way. One of an *every key* run is re-sent whatever its
value. One of a *missing keys* run is re-sent only while the key is still missing: if the
translation files have filled it since, sending it would bill a key nobody asks for, and approving
the result would overwrite the value just delivered, so it is not sent, and its row is
closed, rejected as superseded by the file (`SuggestionRejectedEvent::$supersededBy` =
`file`, author `system`). A row stored without the mode counts as *missing keys*: not
billing is the safe side. The estimate and the dry-run count the same keys, and close
nothing.

### Token usage

The LLM bridges store what the provider reported for each call: `batch_usage` (the token
usage of the whole call, in the provider's own shape), `batch_size` (the keys the call
covered) and `batch_id` (a random id, one per call). Every suggestion of a call carries the
same three, so the usage is counted **once per distinct `batch_id`**; summed per row, it
would be counted once per key. On MariaDB or MySQL (8.0.21 and later), for the
`/chat/completions` bridges:

```sql
SELECT SUM(tokens) FROM (
    SELECT DISTINCT JSON_VALUE(metadata, '$.batch_id') AS batch_id,
           JSON_VALUE(metadata, '$.batch_usage.total_tokens') AS tokens
    FROM cyllene_translation_suggestion
    WHERE JSON_VALUE(metadata, '$.batch_id') IS NOT NULL
) AS calls
```

On PostgreSQL:

```sql
SELECT SUM(tokens) FROM (
    SELECT DISTINCT metadata->>'batch_id' AS batch_id,
           (metadata->'batch_usage'->>'total_tokens')::int AS tokens
    FROM cyllene_translation_suggestion
    WHERE metadata->>'batch_id' IS NOT NULL
) AS calls
```

Anthropic reports `input_tokens` and `output_tokens` instead of `total_tokens`. The figure is a
floor, not a bill: rejected suggestions are deleted with their metadata, and a batch whose
reply could not be read stored errored rows without usage. The provider's own dashboard is
the reference for what was billed.

### Save-time syntax validation

The generated value is also checked against the catalogue's declared format
(`TranslationValueValidator`, see [overrides](overrides.md)): at generation time a
mismatch is only recorded on the suggestion (`metadata.syntax_issues`), never refused (the
provider answered what it answered); at **approval** it blocks, exactly like a placeholder
mismatch (edit the value before approving, or reject the row). Batch approval skips and reports such rows.

### Plural categories

Languages do not share their plural categories: a French ordinal has two (`1er`, `2e`), an
English one four (`1st`, `2nd`, `3rd`, `4th`), a Polish plural `one`, `few` and `many`. A
translation keeping the source's branches is valid ICU, but every number of a missing
category falls back to `other`: "your 2th order". The LLM prompt therefore names the
target language's categories, and the review card warns when a `plural` or `selectordinal`
of the proposal lacks one (`PluralCategories`, read from `intl`; explicit `=N` branches
covering every number of a category count as covering it). Only the categories numbers
0–199 reach are required; the French `many` of millions is not. Without `intl`, neither
the hint nor the warning appears. It is a warning, not a refusal: the reviewer decides.

> **`PlaceholderConsistencyChecker` is not a security control.** It checks placeholder/tag
> *consistency*, not content safety: it neither blocks nor sanitises malicious HTML/script that
> a provider (or an imported override) might introduce. Output escaping remains the host's
> responsibility: never emit a translated value through Twig's `|raw`, into an HTML attribute
> or a JavaScript/JSON context without escaping.

## Statuses

```
pending ──approve──▶ approved   (override written, reverted or left alone: metadata.override_change; the row is kept)
   ├──────reject───▶ deleted    (nothing applied; the key is eligible again for a later run)
   └──superseded───▶ deleted    (an override was written for the key some other way, or the files filled an errored key)
```

- **Approval**: stamps `reviewed_by` / `reviewed_at` and applies the value to the
  (locale, catalogue, key, scope) tuple through `OverrideWriter` (so cache invalidation
  included), both in one transaction (the writer's transaction and the
  ORM flushes nest inside it: on DBAL 3, set `doctrine.dbal.use_savepoints: true`, see
  [Doctrine DBAL 3](../operations/doctrine-dbal-3.md)). A value the entry already shows
  writes nothing: equal to its baseline (the override it inherits, i.e. the global one in a
  scope or the parent language's for a regional locale, else the file value), no override is
  created, and one stored is removed, back to that baseline; equal to the stored
  override, it is left as it is. The suggestion is approved all the same: the decision is
  recorded (`OverrideChange`, the rule the editor and the import share), and
  `metadata.override_change` says what the approval did: `write`, `revert` or `none`; a
  `revert` with `metadata.reverted_to`, `inherited` or `file`, what the entry shows from then
  on. A batch approval decides each suggestion against the state the batch leaves: approving
  the global suggestion of a key and its scoped one together, the scoped one is compared to
  the new global value. If the write fails, the suggestion is put back as it was stored,
  pending, its metadata untouched, unless the failure closed the entity manager (a failed
  flush), after which no later flush can write it anyway. The reviewer can **fix the value
  before applying it** (the console's `edit` decision, or `approve($suggestion, $finalValue)`;
  tracked by `metadata.edited_on_approve`). Approval fails in
  four cases: a row with **no value to apply** (an errored generation,
  `SuggestionValueMissingException`), a value whose **placeholders differ from the source's**,
  missing or unexpected (`PlaceholderMismatchException`, `$missing` / `$unexpected`), a value that **breaks the catalogue's declared syntax**
  (`TranslationValueInvalidException`), or a suggestion **reviewed meanwhile**: the status
  is read back from the database, so a double submission or a stale entity cannot write over
  the override again (`SuggestionAlreadyReviewedException`; batch approval skips it).
- **Rejection**: deletes the row (`SuggestionRejectedEvent` is dispatched first, with the
  full suggestion). A rejected key becomes eligible again for a future generation (only
  `pending` blocks duplicates). Like approval, it checks the database first: a suggestion
  approved meanwhile keeps its row, the audit trail of its override
  (`SuggestionAlreadyReviewedException`; batch rejection leaves it as it is).
- **Superseded**: an override written for the key in any other way than approving the
  suggestion (the console's edit, an import, the host's own code through
  `TranslationManagerInterface` or `OverrideWriter`) rejects the pending suggestions of the
  same (locale, catalogue, key, scope), errored ones included, in the same transaction as
  the override, their rows locked and their status read back from the database. Someone
  chose the value: left pending, the proposal would still be counted as awaiting review,
  approving it later would overwrite the chosen value, and a *retry errors* run would bill a
  key already translated. It is a rejection like any other: the row is deleted,
  `reviewed_by` is the override's author (`system` when none is known), and
  `SuggestionRejectedEvent` is dispatched first, with `$supersededBy` = `override`. An
  approved row is never touched, even one approved by another process since the entity was
  loaded: it is the audit trail of an earlier decision. A *retry errors* run closes, the same
  way, the errored rows whose key the files have filled since (`$supersededBy` = `file`, see
  [Errored suggestions](#errored-suggestions)).

The bundle ships one review surface, the CLI: the
[interactive console](../operations/commands.md)'s "Review the pending suggestions"
entry walks through the pending rows: each card shows the source, the value the entry
shows today when it has one (`Current value (file|override|inherited)`, the case of an
*every key* run) and the proposal (approve / edit / reject / skip, plus
`approve autocorrect` when the detected mistakes are deterministically repairable),
with every safeguard applied. A host integration that builds its own review surface calls
the same `TranslationAiService::approve()` / `reject()`, and gets the same refusals.

Approved suggestions are purged after a while with
[`cyllene:ai-translation:cleanup-suggestions`](../operations/commands.md) (`pending` ones are never
touched).

## Events

To hook host-side effects (HTTP cache purge, audit log, webhook…), four Symfony events are
dispatched: `OverrideSavedEvent`, `OverrideRemovedEvent` (by the `OverrideWriter`),
`SuggestionApprovedEvent` (with the value actually applied) and `SuggestionRejectedEvent` (by
the `SuggestionReviewer`; by the `OverrideWriter` or a *retry errors* run for a superseded
suggestion; `$supersededBy` tells them apart: `null`, `override` or `file`). They carry
managed entities: read them, do not modify them; the next flush would write the change.

The override events, and `SuggestionApprovedEvent`, are dispatched once the bundle's
transaction has committed; when you call the bundle inside a transaction of your own, that
commit only closes a nested level, and the events come before yours. `SuggestionRejectedEvent` is the exception: every rejection, the superseded ones
included, is dispatched right before the row is deleted (so before the commit) because
the listeners need the full row, and once it is deleted Doctrine resets the entity's id. A
listener that must only act on a committed rejection defers its own effect (on
`kernel.terminate`, or through a Messenger message).

## Confidence

`confidence` is the **model's self-assessment** for the LLM bridges (requested per key in the
JSON response, clamped to 0..1), and the bridge's fixed default (0.9) for DeepL or an LLM
response without a score. It is only meant to order and prioritise a review queue; it stays
indicative: no automatic approval
is wired to it (see the [design note](../design/human-review.md)).

Do not wire one in your integration either. The score is whatever the model writes, and the
source strings are part of its prompt: a crafted source value (imported, or typed in a back
office) can talk the model into altering the other keys of its batch and reporting 1.0 for
them. The prompt tells the model to treat the strings as data, which lowers the odds, not
the possibility; the human review is the guard.
