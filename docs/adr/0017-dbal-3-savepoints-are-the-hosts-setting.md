# 0017. Savepoints on DBAL 3 are the host's setting, not the bundle's

- Status: accepted
- Date: 2026-10-14

## Context

The bundle's transactions nest: every flush opens one inside the bundle's, the writer's inside a
review's, and the bundle's inside the host's when the host writes from one. DBAL 4 always nests
with a savepoint. DBAL 3 only does with `use_savepoints: true`; otherwise it emulates the nesting
and raises a deprecation, and a refusal caught inside a host's transaction marks that whole
transaction rollback-only.

## Decision

The bundle supports DBAL 3 and documents the one setting it needs there, `use_savepoints: true`
(the default of the DoctrineBundle recipe). It does not set it.

## Alternatives rejected

- **Prepending `use_savepoints` from the bundle**: it would silently change how every transaction
  of the host application nests.
- **Enabling savepoints for the bundle's transactions only**: restoring the host's value calls
  `setNestTransactionsWithSavepoints(false)`, which raises the very same deprecation, and DBAL 4
  deprecates both methods and refuses `false`.
- **Not nesting**: the nesting is inherent to "lock the row, then flush"; Doctrine's own
  `wrapInTransaction()` nests the same way.

## Consequences

- A DBAL 3 host without savepoints sees one deprecation per process that reviews a suggestion or
  writes an override, and a refusal it catches inside its own transaction fails that
  transaction's commit.
- The bundle's own transactions behave the same with or without savepoints.
- Dropping DBAL 3 support makes this record obsolete: a new record supersedes it then.

## Related

- [0012. Effects follow the commit](0012-effects-follow-the-commit.md)
- [0015. The bundle's transactions are hand-made](0015-hand-made-transactions.md)
