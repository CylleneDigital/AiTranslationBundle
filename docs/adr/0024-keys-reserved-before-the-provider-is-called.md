# 0024. The keys of a batch are reserved before the provider is called

- Status: accepted
- Date: 2026-10-14
- Supersedes: the consequence of [0006](0006-no-retry-that-can-bill-twice.md) about a worker
  killed mid-run, and the symptom of an unshared lock store in
  [0008](0008-one-generation-at-a-time.md)

## Context

A run killed while a provider call is in flight (a worker's OOM, a `SIGKILL` at a deployment,
a lost container) never fails its message: no exception is raised, so neither the bundle's
retry strategy nor the unrecoverable marker applies. The transport delivers the message again
(doctrine and Redis after their redelivery timeout, AMQP as soon as the connection drops), and
a cron starts a killed console run again. The next run skipped the batches already stored, but
the batch in flight had nothing stored: its keys were sent, and billed, again. The interrupted
run left no trace either.

## Decision

Before each provider call, the keys of the batch are stored as errored suggestions marked in
flight (when the batch was reserved), and flushed. The reply fills them; a failure records its
error on them. Either way the in-flight mark goes. Every write on a reserved or errored row -
the reservation of a row a previous run left errored, the reply, a failure - re-reads it in the
database, locked, and leaves alone a row someone reviewed meanwhile: a reserved row is in the
review queue, and can be filled by hand and approved while its batch is with the provider.

A run that starts again skips the reserved keys, like any pending suggestion. Sending them
again is a run that retries errors, decided by someone. A retry carries the time it was asked
for, and leaves out the rows reserved after it: those are its own run's, so a redelivered
retry does not bill them twice.

## Alternatives rejected

- **Idempotency per message**, refusing a message whose run already started: it needs an id
  in the serialized message and a "started" state in the journal, a mapping change, and does
  not cover a console run.
- **A very long redelivery timeout** on the bundle's transport: the message stays stuck, and
  nobody knows the run never finished - a silent lost run in place of a double bill.

## Consequences

- No batch is sent twice without an explicit retry, whatever the transport, with or without
  Messenger.
- An interrupted run is visible: its in-flight keys are errored rows saying why. The journal
  still has no entry for it; the rows are its trace.
- While a batch is with the provider, its reserved rows are in the review queue like other
  errored rows; the in-flight mark lets an integration tell them apart.
- A row reviewed while its batch is with the provider (filled by hand and approved, closed by
  an override written for its key, rejected) stays as the person left it, and its paid
  translation is dropped, or its failure not recorded.
- An errored row reviewed after a retry started and before its batch is reserved is left out
  of the batch: its key is not sent, nor paid for.
- Of two runs an unshared lock store let through, the one that reserves a key the other holds
  fails on the pending unique index before paying for that batch, not after.
- One more flush per batch, negligible next to the provider call, and no mapping change.
- The comparison of a retry's time with a reservation's assumes the clocks of the machine that
  dispatched the retry and of the worker agree within the time a message waits in the queue.

## Related

- [0006. No retry that can bill the same keys twice](0006-no-retry-that-can-bill-twice.md)
- [0007. What was paid for is committed chunk by chunk](0007-paid-results-committed-chunk-by-chunk.md)
- [0008. One generation at a time per catalogue, locale and scope](0008-one-generation-at-a-time.md)
- [0013. A stale entity never decides](0013-stale-entities-never-decide.md)
