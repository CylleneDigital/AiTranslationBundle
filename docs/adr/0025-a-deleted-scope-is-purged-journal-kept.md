# 0025. A deleted scope is purged in a transaction of its own, its journal kept

- Status: accepted
- Date: 2026-10-14

## Context

A host deletes a scope (a sales channel, a site): its overrides, suggestions and parameters
stay in the database, invisible, and apply again to a new scope created with the same code,
which nobody wrote them for. The host cannot remove them itself without writing to the
bundle's tables, outside the public contract. Removing an override has effects outside the
database: the cache invalidation, and `OverrideRemovedEvent`, which a host turns into HTTP
purges and webhooks.

## Decision

A service purges a scope: its overrides, its suggestions whatever their status, and its
parameters. It opens its own transaction, takes no `flush` argument, and is called once the
host's deletion is committed. The overrides are removed through the writer, one event each,
the effects after the commit. The global scope is refused. The generation journal stays.

## Alternatives rejected

- **A `flush: false` argument**, to run inside the host's transaction: the bundle would not
  commit, so the invalidation and the events would leave before a commit that may never
  come ([0012](0012-effects-follow-the-commit.md)).
- **A bulk `DELETE` of the overrides**: no event per row, so the host's purges never hear of
  the removal.
- **Purging the journal too**: it records what was paid for
  ([0009](0009-every-run-is-journaled.md)), and is never served, so it cannot reappear
  anywhere.

## Consequences

- A purge that fails after the host's deletion leaves dead rows: the cleanup command finds
  the scopes stored but no longer declared, and purges them.
- A run of that scope still with the provider finds its reserved rows gone, and stores
  nothing ([0024](0024-keys-reserved-before-the-provider-is-called.md)).
- Deleting a pending suggestion with its scope dispatches no rejection event: nobody
  rejected it.

## Related

- [0009. Every run is journaled](0009-every-run-is-journaled.md)
- [0012. Effects follow the commit](0012-effects-follow-the-commit.md)
- [0024. The keys of a batch are reserved before the provider is called](0024-keys-reserved-before-the-provider-is-called.md)
