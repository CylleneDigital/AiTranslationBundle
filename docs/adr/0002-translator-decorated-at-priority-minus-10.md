# 0002. `translator` is decorated at priority `-10`, every interface of the decorated service re-exposed

- Status: accepted
- Date: 2026-10-14

## Context

Overrides must win for every `trans()` call of the application: templates, third-party bundles,
components, emails. `trans()` is called hundreds of times per page, so the mechanism has to
cost close to nothing there. Other decorators may already sit on `translator` (themes,
pseudo-localisation).

## Decision

`OverrideAwareTranslator` decorates `translator` at priority `-10`: above the existing chain,
it has the last word whichever decorator produced the value below. It looks an override up by
(domain, key): `trans()` receives the domain, which is the last segment of a stored catalogue
identifier. The overridden value is formatted again, since short-circuiting the catalogue also
short-circuits its formatter.

A decorator takes the decorated service's id, so everything that type-checks `translator` sees
the decorator: it implements every interface `translator.default` implements, and delegates
what it does not handle.

## Alternatives rejected

- **A Doctrine loader added to the translator**, the catalogues recompiled with the overrides
  baked in: every override change would need a recompilation, slow and locked, where the
  decorator takes effect on the next request for the cost of a cache entry.
- **A lookup by (catalogue, key)**: `trans()` never receives the catalogue, so the translator
  would have to guess which file a value came from, on the hot path.
- **A lookup by key alone**: an override of one domain would leak onto another domain's
  identical key.

## Consequences

- The hot path is one in-memory array per locale and scope, loaded once per request from the
  application cache.
- Several catalogues can feed one domain: the same key overridden in two of them resolves to the
  first catalogue's override, and the collision is logged.
- `getCatalogue()` returns the file catalogue, without the overrides: `debug:translation`, the
  profiler and any consumer of `TranslatorBagInterface` see the file values. Baking the
  overrides in would rebuild a catalogue per locale and scope on a path whose point is to cost
  nothing; the effective values are read through the manager instead.
- Dropping an interface breaks something silently. `WarmableInterface` is the one that bites:
  the framework's cache warmer only warms a translator that implements it, so without it
  `cache:warmup` stops compiling the catalogues, and the first request of a deployment on a
  read-only cache directory cannot compile them either.
- An interface a future Symfony version adds to `translator.default` has to be added to the
  decorator.

## Related

- [0001. Overrides are a database delta](0001-overrides-are-a-database-delta.md)
- [0003. Missing keys are computed within one language only](0003-missing-keys-within-one-language.md)
