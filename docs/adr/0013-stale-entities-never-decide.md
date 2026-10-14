# 0013. A stale entity never decides

- Status: accepted
- Date: 2026-10-14

## Context

A suggestion's status can change between the moment an entity is loaded and the moment it is
acted on: a double submission, a second console session, an import running beside a review, a
worker. Doctrine's identity map does not refresh an entity it already holds: reading the status
from it reads the past.

## Decision

Whatever changes a suggestion because of its status - a review, an override closing the pending
suggestions of its key, a retry closing an errored one - reads that status again in the
database, the rows locked, inside the transaction that changes them.

A review that fails after changing the suggestion in memory puts the entity back as it was: the
rollback restores the database, not the entity, and the host's next flush would write the
change.

## Consequences

- A suggestion approved meanwhile is never approved again, nor rejected: the second decision is
  refused, or skipped in a batch, and the approved row stays as the audit trail of its override.
- Two reviewers never write over each other's decision; the second one is told.
- Every such write needs a transaction and a row lock, hence the bundle's own transactions
  ([0015](0015-hand-made-transactions.md)) and, on DBAL 3, savepoints
  ([0017](0017-dbal-3-savepoints-are-the-hosts-setting.md)).
- A new operation that reads a status to change it locks and re-reads it the same way.

## Related

- [0011. No suggestion is applied without a human decision](0011-no-suggestion-applied-without-a-human.md)
- [0012. Effects follow the commit](0012-effects-follow-the-commit.md)
