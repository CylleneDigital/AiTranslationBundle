# Design decision: Decorating `translator`, (domain, key) lookup

## Context

Overrides must take precedence over the YAML catalogues for **every** `trans()` call in the
application: templates, third-party bundles, components, emails. `trans()` is called hundreds of
times per page: the mechanism must cost close to zero on the hot path.

## The decision: a decorator at priority `-10`

`OverrideAwareTranslator` decorates the `translator` service (`#[AsDecorator(decorates: 'translator',
priority: -10)]`): it checks its overrides, otherwise delegates. The negative priority places
it **above** the existing chain (theme layers included): it has the last word, whichever
decorator produced the value below.

The lookup is done by **(domain, key)**, not by (catalogue, key) nor by key alone: the
stored catalogue identifier (`shop/Product/messages`) carries the Symfony domain as its last
segment, which is exactly the `$domain` the framework passes to `trans()`.

- it costs nothing more on the hot path: still **one array per locale** in memory (loaded
  once per request from `cache.app`), just nested one level deeper;
- it removes the cross-domain collision a key-only lookup had: an override of the
  `validators` domain no longer leaks onto a `messages` lookup.

Residual ambiguity, by design: several file catalogues can feed the same domain
(`shop/Product/messages` and `shop/messages` both feed `messages`, which the framework
merges into one per-locale catalogue). The translator only knows the domain, so the first
catalogue in order wins and the collision is logged.

Formatting is **re-applied** on the overridden value: ICU keys through Symfony's
`IntlFormatter`, legacy plurals (`%count%` and `|`) through `IdentityTranslator`, a value
referencing a bare `{param}` through intl, plain replacement otherwise: by short-circuiting the
catalogue you also short-circuit its formatter.

## Every interface of the decorated service is re-exposed

A decorator takes the decorated service's id, so **everything that type-checks the `translator`
service now sees the decorator**. `OverrideAwareTranslator` therefore implements the four
interfaces `translator.default` implements (`TranslatorInterface`, `TranslatorBagInterface`,
`LocaleAwareInterface` and `WarmableInterface`) and delegates what it does not handle itself.

`WarmableInterface` is the one that bites when it is forgotten: the framework's
`TranslationsCacheWarmer` does `if ($this->translator instanceof WarmableInterface)`, so a
decorator that drops it silently disables the catalogue warmup. `cache:warmup` stops producing
`var/cache/<env>/translations/catalogue.*.php`, and the first request of a fresh deployment has
to compile them itself, which is impossible on a read-only cache directory.

## Alternatives rejected

- **Recompiling the catalogues with the overrides baked in** (a Doctrine loader added to the
  translator): elegant on paper, but every override change would require a catalogue
  recompilation (slow, locked), where the decorator takes effect on the next request for the
  cost of a cache entry.
- **Lookup by (catalogue, key)**: fully unambiguous, but `trans()` never receives the
  catalogue (only the domain), so the translator would have to guess which file the value
  came from, at a permanent cost on the hot path.
- **Editing the YAML files**: loses the customisation on every update of the package that owns
  the file, and requires a deployment for a label change.

## Accepted costs

- An identical key in two catalogues of the **same domain** with different meanings resolves
  to the first catalogue's override (logged). With namespaced keys this is theoretical.
- The decorator loads a locale's overrides **on the first translation** of the request: at
  worst one `findForRuntime` query per (locale, scope) per hour (cache TTL), negligible.
- **`getCatalogue()` returns the file catalogue, without the overrides.** The decorator
  applies them in `trans()`, not in the compiled `MessageCatalogue` it hands back, so
  anything reading the catalogue directly sees the YAML values: `debug:translation`, the
  profiler's translation panel, and any code consuming `TranslatorBagInterface` (extraction
  tooling, completeness checks).

  Baking them in would mean rebuilding a catalogue per (locale, scope) on every call
  (cloning it first, since the inner translator caches and reuses that object), which puts
  a per-locale rebuild on a path whose whole point is to cost nothing. The trade was made
  knowingly: `trans()` is what an application renders with, and it is always correct.

  If you need the effective values as data, read them through `TranslationManager`
  (`getTranslationsForCatalogue()`, `getEffectiveOverridesByCatalogue()`), which is what
  the coverage report and the exporters do.

## See also

- [Concepts: Overrides](../concepts/overrides.md)
- [Operations: Caches](../operations/cache.md)
