# 0014. A batch is decided against the state it leaves

- Status: accepted
- Date: 2026-10-14

## Context

Whether writing a value creates, changes or removes an override depends on what the entry would
show without it: the inherited override (the global one in a scope, the parent language's for a
regional locale), else the file value. A value equal to that baseline needs no override of its
own. An import or a batch approval can write a parent and its children in the same batch:
compared with the stored parent, a child would be decided against a value the batch is about to
replace.

## Decision

`OverrideReader::planChanges()` decides a whole batch against the state it leaves: the parents
first - the least specific locale, then, within a locale, the global entry before the scoped
one - and each later entry reads their outcome, then the database for the levels the batch
leaves alone, then its file. It only reads the rows of the batch's keys, never the whole locale.

## Consequences

- An import produces the same overrides whatever the order of its lines.
- A child equal to its parent's new value is not stored as a redundant override, and one equal to
  the parent's old value is not removed by mistake.
- An import of three lines reads three keys' rows: it does not load, and leave managed by the
  host's entity manager, every override of the locale.
- A new batch operation on overrides goes through the same planning, or it decides against a
  state that will not exist.

## Related

- [0003. Missing keys are computed within one language only](0003-missing-keys-within-one-language.md)
- [0011. No suggestion is applied without a human decision](0011-no-suggestion-applied-without-a-human.md)
