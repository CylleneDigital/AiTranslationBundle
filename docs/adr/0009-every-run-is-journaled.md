# 0009. Every run is journaled, a run with nothing to translate included

- Status: accepted
- Date: 2026-10-14

## Context

Generation is fire-and-forget: a message handled by a worker, possibly at night, with failures
deliberately not retried. Whoever was not watching the console needs to know that a run
happened, what it produced and why it failed. A run with nothing to translate looks like a run
that never happened, unless it leaves a trace.

## Decision

Every run that starts writes a journal entry: its outcome, the number of suggestions it created,
the provider, the scope, the error when it failed. A run that created no suggestion writes one
too. A run skipped because another holds the lock did not run, and says so to its caller
instead.

## Consequences

- "It ran and found nothing" and "it did not run" are told apart, from the journal and from the
  outcome the caller receives.
- A run failing midway records the suggestions it had already created.
- The journal grows with every scheduled run, empty ones included; a retention purge, driven by
  the cleanup command, keeps it bounded.
- One run leaves no entry: one whose flush failed on the pending unique index (a lock store not
  shared between machines, and the other run stored the key first), the entity manager being
  closed by then. The multi-server documentation says so.

## Related

- [0007. What was paid for is committed chunk by chunk](0007-paid-results-committed-chunk-by-chunk.md)
- [0008. One generation at a time per catalogue, locale and scope](0008-one-generation-at-a-time.md)
