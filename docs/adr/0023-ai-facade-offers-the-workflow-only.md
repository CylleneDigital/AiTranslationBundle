# 0023. The AI facade only offers the workflow, not its steps

- Status: accepted
- Date: 2026-10-14

## Context

Before the first release, `TranslationAiServiceInterface` also exposed the inner steps of a
generation: collecting the texts to send (`collectTexts()`), cutting them into chunks
(`chunkTexts()`) and resolving the provider (`resolveProvider()`). An integration combining them
with the provider would assemble a run of its own, outside what the run guarantees: the lock,
the per-chunk commit, the journal. No known integration called them.

## Decision

They are removed from the facade. They stay public methods of `SuggestionGenerator`, which the
public contract does not list, for the cost estimator to price the very keys and prompts a run
would send. The facade offers the workflow: generate the suggestions, approve, reject, one at a
time or in batch.

## Consequences

- An integration cannot assemble a run that bypasses the guards against double billing.
- The steps of a run can change in a minor version: they are internal.
- An integration needing something in between asks for a service of its own, listed in the
  public contract, as `GenerationCostEstimator` is for an estimate.

## Related

- [0007. What was paid for is committed chunk by chunk](0007-paid-results-committed-chunk-by-chunk.md)
- [0008. One generation at a time per catalogue, locale and scope](0008-one-generation-at-a-time.md)
- [0020. The public contract is one page, method by method](0020-public-contract-is-one-page.md)
