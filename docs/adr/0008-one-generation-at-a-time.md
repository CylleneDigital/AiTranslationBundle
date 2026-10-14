# 0008. One generation at a time per catalogue, locale and scope, under a lock that degrades

- Status: accepted; the symptom of an unshared lock store is superseded by
  [0024](0024-keys-reserved-before-the-provider-is-called.md)
- Date: 2026-10-14

## Context

The same generation can be started twice: two workers, a message dispatched twice, a cron
overlapping a person in the console. Two concurrent runs of the same catalogue, target locale
and scope would both send the keys neither has stored yet, and bill them twice. The database
already prevents the duplicate itself: it holds at most one pending suggestion per key.

## Decision

`GenerationLock` runs a generation under an exclusive lock per (catalogue, target locale,
scope), through `symfony/lock`:

- **non-blocking**: a second run does not wait, it is skipped and says so;
- **degraded, not refused**: when the lock store cannot be reached, the run proceeds unguarded
  and a warning is logged. The lock protects the money; the database protects the data;
- **held where the host decides**: the store is the host's lock configuration, refreshed between
  chunks on an expiring store.

A run also skips every key that already has a pending suggestion.

## Consequences

- Any number of workers on one machine need nothing: the framework's default store is local to
  the machine.
- Workers spread over several machines need a shared lock store; without one, of two concurrent
  runs, the one that flushes a key the other already stored fails on the database's unique
  index, after paying for that chunk.
- A crashed worker releases its lock: the process' end on a local store, the TTL on an expiring
  one.
- An integration adds no lock of its own: it would duplicate this one without covering more.
- A key whose suggestion was reviewed in between is billed again by a run that covers it: that is
  what asking again means.

## Related

- [0006. No retry that can bill the same keys twice](0006-no-retry-that-can-bill-twice.md)
- [0009. Every run is journaled](0009-every-run-is-journaled.md)
