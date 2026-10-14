# 0015. The bundle's transactions are hand-made, not `wrapInTransaction()`

- Status: accepted
- Date: 2026-10-14

## Context

The bundle writes inside transactions of its own: a review locks its row, an override locks the
pending suggestions it closes. Some of these transactions end in a refusal the caller is
expected to handle: a suggestion already reviewed, a value the tables cannot hold. Doctrine's
`EntityManager::wrapInTransaction()` closes the entity manager on any exception it sees, and
the entity manager is the host's, still needed by the rest of its request.

## Decision

`TranslationSuggestionRepository::transactional()` begins, commits and rolls back the
transaction itself, and leaves the entity manager open whatever the exception.

## Consequences

- A refused approval answers with its exception, and the host's request goes on with a working
  entity manager.
- The rollback restores the database, not the entities: a review that changed an entity in
  memory puts it back itself ([0013](0013-stale-entities-never-decide.md)).
- A write failing on the database itself (a unique index) still closes the entity manager:
  Doctrine does that at the flush, not the transaction.
- Replacing the method by `wrapInTransaction()` would look like a simplification and break every
  host that catches a refusal.

## Related

- [0012. Effects follow the commit](0012-effects-follow-the-commit.md)
- [0016. The race on the first write of an override stays a documented limit](0016-first-write-race-is-a-documented-limit.md)
- [0017. Savepoints on DBAL 3 are the host's setting](0017-dbal-3-savepoints-are-the-hosts-setting.md)
