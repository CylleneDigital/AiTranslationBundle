# 0007. What was paid for is committed chunk by chunk, never inside a caller's transaction

- Status: accepted
- Date: 2026-10-14

## Context

A run sends its keys to the provider in chunks, each one billed. Whatever rolls back the
suggestions of a chunk throws away what was paid for, and the next run pays for it again. A run
can be called from inside a transaction it does not own: Messenger's `doctrine_transaction`
middleware (Sylius' default bus has it), or a host's `wrapInTransaction()`.

## Decision

- Each chunk is flushed as soon as its answer is stored: a later failure cannot undo it.
- A run refuses to start inside a database transaction, before any provider call: a rollback of
  that transaction would discard every chunk it committed. The refusal is a programming error,
  not an exception for the host to handle.
- The rows a run creates are detached from the entity manager once the run is over.

## Consequences

- A run failing midway keeps the suggestions of the chunks before the failure, and the journal
  counts them.
- An integration dispatches the generation message on a bus without `doctrine_transaction`.
- A long-running worker does not accumulate the rows of every run it handled, and the host's
  next flush does not write them again.

## Related

- [0006. No retry that can bill the same keys twice](0006-no-retry-that-can-bill-twice.md)
- [0009. Every run is journaled](0009-every-run-is-journaled.md)
- [0015. The bundle's transactions are hand-made](0015-hand-made-transactions.md)
