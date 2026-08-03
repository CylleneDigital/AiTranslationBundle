# Design decision: Mandatory human review

## Context

The temptation to apply AI translations directly is strong: one command, and the locale is
complete. But an interface label carries business meaning ("Approve" on a suggestion ≠
"Approve" on an order), placeholders to preserve, and a house tone: three things an automatic
translator degrades silently.

## The decision: a suggestion is never applied on its own

Generation produces `pending` rows in a dedicated table (`cyllene_translation_suggestion`),
with no effect whatsoever on the application. Only a **human decision** turns a suggestion
into an override (approve) or files it away (reject). Every approval is stamped: who and when;
a rejection deletes the row.

Structural consequences:

- **duplicate prevention** applies to the `pending` status only: a rejected suggestion leaves
  the key re-proposable (the context may have changed, and so may the provider);
- **confidence is a sorting tool, not an application threshold**: it is the provider's
  self-assessment when one exists, otherwise a fixed default (0.9). No automatic approval is
  wired to it: even at "high confidence", DeepL can mangle a placeholder;
- **the audit trail of an approval outlives the decision**: the row keeps the source value,
  the proposed translation, the provider, the metadata (model, tokens), who approved it and
  when. A rejection leaves no row: the key simply becomes eligible again;
- **writing the value by hand is a decision too**: an override written for a key some other
  way than approving its suggestion (an edit, an import, the host's code) rejects the
  pending suggestions of that key, in the same transaction, their rows locked. The person
  chose another value; a proposal left pending beside it would be counted as awaiting
  review, could overwrite the chosen value if approved later, and, if errored, would be
  billed again by a retry. Likewise, a retry closes an errored suggestion whose key the
  translation files have filled since, rather than billing it again.
  Nothing is applied without a human either way: the suggestion is discarded, never used.

## Alternatives rejected

- **Direct application with a confidence threshold** (`>= 0.9` → automatic override): the score
  is the model's own self-assessment (DeepL's, and a reply without one, is a fixed 0.9), a
  crafted source string can talk the model into reporting 1.0, and a mistake applied in
  production is noticed *after* the fact, by customers.
- **"Provisional" overrides** (applied but flagged for review): the mistake is already visible
  in production while it is being reviewed: that is review *after* application, the worst of
  both worlds.
- **Multi-step validation workflow** (translator → reviewer → publication): over-engineered for
  the target audience (a team fixing its own labels); the day the need arises, the table
  already carries the statuses and the audit trail to build on.

## Accepted costs

- Completing a locale requires a human pass. That is the point, but on a large catch-up
  (a generation run across every catalogue) the review becomes a job in itself; the batch
  operations (`approveMany()` / `rejectMany()`) are there for that.
- Between generation and review, suggestions sit in the table with no time limit; there is no
  automatic purge. `findPending` is served by the (status, locale, catalogue, scope) index, and
  the volume stays bounded by the size of the catalogues.

## See also

- [Concepts: AI suggestions](../concepts/suggestions.md)
- [Operations: CLI commands](../operations/commands.md)
