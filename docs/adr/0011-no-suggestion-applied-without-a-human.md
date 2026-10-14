# 0011. No suggestion is applied without a human decision

- Status: accepted
- Date: 2026-10-14

## Context

Applying AI translations directly is tempting: one command, and the locale is complete. But an
interface label carries business meaning ("Approve" on a suggestion is not "Approve" on an
order), placeholders to preserve and a house tone: three things an automatic translator degrades
silently, and a mistake applied in production is noticed after the fact, by customers.

## Decision

A generation only produces pending suggestions, with no effect on the application. Only a human
decision turns a suggestion into an override (approval) or discards it (rejection). Nothing is
approved automatically, whatever the confidence the provider reports.

Writing a value by hand is a decision too: an override written for a key some other way than
approving its suggestion - an edit, an import, the host's code - closes the pending suggestions
of that key. A suggestion is discarded that way, never used.

## Alternatives rejected

- **Applying above a confidence threshold**: the score is the model's self-assessment (or a
  fixed default when the provider gives none), a crafted source string can talk a model into
  reporting full confidence, and even a confident provider can mangle a placeholder.
- **Provisional overrides**, applied but flagged for review: the mistake is in production while
  it waits for its review - review after application, the worst of both.
- **A multi-step workflow** (translator, reviewer, publication): too much for a team fixing its
  own labels. The statuses and the audit trail are there to build one on.

## Consequences

- Only a pending suggestion blocks a new one for its key: a rejected key can be proposed again,
  the context or the provider having changed.
- Confidence sorts a review queue, nothing more; an integration does not wire an approval to it
  either.
- An approval keeps its audit trail: the source value, the proposal, the provider, its metadata,
  who approved and when. A rejection deletes the row.
- A suggestion left pending beside a value someone chose would count as awaiting review, could
  overwrite that value if approved later, and, errored, would be billed again by a retry: hence
  the closing.
- Completing a locale takes a human pass. On a large catch-up the review becomes a job of its
  own: the batch approval and rejection are there for it.
- Pending suggestions wait with no time limit; their volume is bounded by the catalogues.

## Related

- [0001. Overrides are a database delta](0001-overrides-are-a-database-delta.md)
- [0013. A stale entity never decides](0013-stale-entities-never-decide.md)
- [0014. A batch is decided against the state it leaves](0014-batch-decided-against-the-state-it-leaves.md)
