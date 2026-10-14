# 0003. Missing keys are computed within one language only

- Status: accepted
- Date: 2026-10-14

## Context

Two needs pull in opposite directions:

- **Complete catalogues**: convention puts most resources under the short locale
  (`messages.fr.yaml`), while the locales a host declares are often regional (`fr_FR`). Reading
  only the exact locale's files would return near-empty catalogues.
- **Missing keys**: generation relies on "this key has no value in the target locale".
  Following a cross-language fallback (the usual `fr` to `en` of `framework.translator.fallbacks`)
  would make every key present in French as soon as it exists in English: nothing would ever be
  left to translate.

## Decision

For a requested locale, the files whose locale is a prefix of it are merged, the most specific
winning: `fr` then `fr_FR` for `fr_FR`, never `en`. Stored overrides follow the same chain, at
runtime and everywhere a value is compared or counted: an override saved on `fr` answers for
`fr_FR`. There is no cross-language fallback, for the files nor for the overrides.

Discovering which catalogues exist, on the other hand, reads every language: a catalogue is a
catalogue, whichever language declares it.

## Alternatives rejected

- **No merging at all**: near-empty catalogues for regional locales.
- **Replicating the framework's `fallbacks` configuration** to decide where to stop: fragile,
  the host's configuration varies, and "same language" states the intent directly.

## Consequences

- A key only defined in English is missing in French, which is what the generation looks for.
- An intra-language fallback that does not share the prefix (an arbitrary alias rather than
  `fr_CA` to `fr`) is out of scope.
- A key deliberately inherited from another language is proposed for translation on every
  generation for as long as it has neither a value nor a pending suggestion. Rejecting the
  suggestion takes it out of the review, not out of the count.

## Related

- [0002. `translator` is decorated at priority `-10`](0002-translator-decorated-at-priority-minus-10.md)
- [0011. No suggestion is applied without a human decision](0011-no-suggestion-applied-without-a-human.md)
