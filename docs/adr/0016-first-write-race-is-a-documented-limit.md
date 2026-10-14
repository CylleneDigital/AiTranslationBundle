# 0016. The race on the first write of an override stays a documented limit

- Status: accepted
- Date: 2026-10-14

## Context

A save looks the override's row up, then inserts it when there is none. Two processes creating
the same new override at the same instant - two people on the same key, an approval during an
import - can both see no row: the second insert hits the unique index and fails, which also
closes that request's entity manager. Closing the race takes an upsert, which every platform
writes differently.

## Decision

The race is left as a known limit, documented with the manual override, rather than paid for
with a platform-specific upsert on every save.

## Consequences

- Nothing is corrupted: the first value is stored, the second write is refused, and replaying it
  updates the row.
- It takes two writes of a key that has no override yet, within the same instant: rare for
  people, and an import or an approval meeting it fails loudly rather than silently.
- The write path stays one portable lookup and one insert or update, on every supported
  database.

## Related

- [0015. The bundle's transactions are hand-made](0015-hand-made-transactions.md)
- [0018. Keys, catalogues, locales and scopes are compared byte for byte](0018-byte-for-byte-comparison.md)
