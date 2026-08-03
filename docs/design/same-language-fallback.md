# Design decision: Same-language fallback only

## Context

Two needs pull in opposite directions:

1. **Seeing complete catalogues**: convention puts most resources under the short locale
   (`messages.fr.yaml`), while the locales the host declares are regionalised (`fr_FR`).
   Reading only the `*.fr_FR.*` files would return near-empty catalogues.
2. **Detecting missing keys**: AI generation relies on "this key has no value in the target
   locale". Following a cross-language fallback (the typical `fr → en` of
   `framework.translator.fallbacks`) would make **every** key "present" in French as soon as it
   exists in English: nothing left to translate, ever.

## The decision: merge files as long as they stay within the same language

For a requested locale, `TranslationFileScanner` merges the catalogue files whose locale is a
**prefix of the requested locale**: the most specific wins, and at equal locale the
`+intl-icu` variant takes precedence (as in the translator):

```
fr_FR request:    messages.fr.yaml  ──▶  messages+intl-icu.fr.yaml  ──▶  messages.fr_FR.yaml
                  ╰───────────────────── merged, the last one wins ─────────────────────╯
en / en_US files: never read for fr_FR; a key only there is MISSING in French
```

**Catalogue** discovery (category/domain/type), on the other hand, aggregates files from every
language: a catalogue is a catalogue, whichever language declares it.

The **stored overrides follow the same chain** (`LocaleFallback`), everywhere: an override
saved on `fr` answers for `fr_FR` (at runtime, in the coverage, in the generation's missing
keys, in the console's browser, and as the baseline the editor, the approval and the import compare a
value with), the more specific locale winning, as for the files. There is no cross-language
fallback for them either.

## Alternatives rejected

- **No merging at all** (read only the exact locale's file): near-empty catalogues for
  regionalised locales (see context).
- **Replicating the framework's `fallbacks` config** to decide where to cut: fragile (the
  host's config varies) and unnecessary: the "same language" criterion expresses the intent
  directly.

## Accepted costs

- A host using an exotic *intra-language* fallback that is not prefixed the same way (`fr_CA` →
  `fr` works; an arbitrary alias would not) falls outside the scope, which is accepted.
- A key deliberately "inherited" from English (never translated anywhere, by design) will be
  proposed for translation on every generation as long as it has neither a value nor a pending
  suggestion. Rejecting the suggestion removes it from the review flow, not from the count.

## See also

- [Concepts: Catalogues & missing keys](../concepts/catalogues.md)
