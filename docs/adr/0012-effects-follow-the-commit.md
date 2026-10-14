# 0012. Effects follow the commit

- Status: accepted
- Date: 2026-10-14

## Context

Writing an override has effects outside the database: the bundle's cache is invalidated, and
`OverrideSavedEvent` lets the host purge its HTTP caches or call a webhook. Many writes happen
inside a transaction - a review locks its row, an override closes the pending suggestions of its
key. An effect run before the commit announces a value a rollback may discard: a purged page
rebuilt from the old rows before the commit, a webhook for a change that never happened.

## Decision

A write made inside a transaction of the bundle goes through `OverrideWriter::withEffectsAfter()`:
the cache invalidations and the events are collected while the transaction runs, and only run
once it is committed. A rollback drops them.

One exception: `SuggestionRejectedEvent` is dispatched right before the suggestion's row is
deleted, inside the transaction. Once the row is deleted, Doctrine resets the entity's id, and
the listeners would no longer see which suggestion it was.

## Consequences

- Inside a transaction of the host, the effects follow the bundle's nested commit, not the
  host's: the documentation says so, and a host that needs the outer commit defers its own
  effect.
- A listener of `SuggestionRejectedEvent` that must only act on a committed rejection defers its
  effect (`kernel.terminate`, a Messenger message); the event's documentation says so.
- The cache is also invalidated once more when the request, command or message is over: a host
  writing inside its own transaction invalidates before its commit, and a map a concurrent
  request rebuilt from the old rows in between would otherwise be served for the whole cache
  TTL.
- A new write path inside a transaction goes through the same method, or it announces values a
  rollback can take back.

## Related

- [0013. A stale entity never decides](0013-stale-entities-never-decide.md)
- [0015. The bundle's transactions are hand-made](0015-hand-made-transactions.md)
- [0017. Savepoints on DBAL 3 are the host's setting](0017-dbal-3-savepoints-are-the-hosts-setting.md)
