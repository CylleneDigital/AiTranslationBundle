# Overrides: the decorated translator

An **override** is a row in the `cyllene_translation_override` table: for a
(locale, catalogue, key) triplet, a value that **takes precedence over the translation files**,
whether they come from third-party bundles or from the application. Upgrading the application
or its dependencies never loses the customisation: no file is ever modified.

## The decorator

`OverrideAwareTranslator` decorates the `translator` service (priority `-10`, so it runs
**after** any other decorators: themes, pseudo-localisation…):

```
trans(id, params, domain, locale)
    ├── override stored for (base locale, domain, id) ? → overridden value (+ ICU formatting)
    └── otherwise → decorated translator (YAML catalogues, themes, …)
```

Behaviour notes:

- **Lookup scoped by (locale, domain)**: the lookup is scoped by the **Symfony domain**
  (`messages`, `validators`, `admin`…), not by the key alone. The stored catalogue identifier
  (`shop/Product/messages`) carries that domain in its `type` (the last segment), which is
  exactly the `domain` the framework passes to `trans()`. An override in the `validators`
  domain therefore no longer "leaks" into a `messages` lookup, and two overrides in distinct
  domains sharing the same key no longer clobber each other. The hot path remains an in-memory
  array lookup (`domain => key => value`). See the
  [design note](../design/translator-decorator.md).
- **Residual ambiguity (by design)**: several catalogue files can share the same domain
  (`shop/Product/messages` and `shop/messages` both feed `messages`, which Symfony merges into
  a single catalogue per locale). If two such catalogues override the **same key**, the
  translator only knows the domain, not the source file: the first match wins (additional-root
  `@label/…` catalogues first, then alphabetical order) and the collision is logged (`warning`). Third-party bundles are typically unaffected: their
  keys are namespaced (`some_bundle.ui.name`), so they do not collide within a domain.
- **The language chain**: a lookup in `fr_FR` reads the overrides saved on `fr` **and**
  those saved on `fr_FR`, the more specific winning, exactly as Symfony serves a `fr_FR`
  request from `messages.fr.yaml` when there is no `messages.fr_FR.yaml`. This matters for
  a plain Symfony host: files named `messages.fr.yaml` make `fr` the only locale the
  bundle offers for browsing, so `fr` is the locale its overrides are saved under, even
  when the application runs on `fr_FR`. Hosts whose locales are already regional (Sylius
  channels are `fr_FR`) store and read the same code on both sides and never see the
  difference. There is **no cross-language fallback**: `fr_BE` never reads `fr_FR`, and
  `fr` never reads `en`.
- **Locale variants**: `fr_FR@euro` shares the overrides of `fr_FR` (the `@variant` suffix is
  stripped before the lookup).
- **Formatting re-applied**: the overridden value bypasses the catalogue's formatting, so
  it is re-applied: a key the files declare in a `+intl-icu` domain is formatted exactly
  like its file value (Symfony's `IntlFormatter`, `%count%`-style parameters and ICU quotes
  included); any other key gets intl when it references a parameter by its bare name
  (`{count}`), plain replacement otherwise (`%name%`).

## The two cache layers

| Layer | Scope | Invalidation |
| --- | --- | --- |
| `cache.app`, key `cyllene_translation_overrides_<locale>_<version>[_s_<hash of the scope>]`, TTL 1 h | Shared across requests | `TranslationCacheManager` on every save/remove |
| In-memory map (per locale and scope) | The HTTP request / CLI process | `TranslatorOverrideCacheListener` on every save/remove (a write on `fr` also drops the `fr_FR` map, which was built from the `fr` rows), plus `setLocale()` (HTTP calls it on every request) |

On every override change, `TranslationCacheManager` invalidates the shared layer: the
locale's `cache.app` entry, by dropping the version token its keys embed, so *removing* an
override immediately falls back to the value the entry inherits, else the file value,
without a manual `cache:clear`. It does
it twice: right away, and again once the request, command or Messenger message is over, so
a write made inside a transaction cannot leave a map rebuilt from the uncommitted rows.
Symfony's compiled catalogues are never touched: they hold the file values only, the
overrides being applied on top of them.

The map belongs to the `OverrideAwareTranslator` service itself, which the manager must not
depend on (the translator already depends on it; injecting it back would close the circle).
The `TranslatorOverrideCacheListener`, listening on `OverrideSavedEvent`/`OverrideRemovedEvent`,
drops it instead. That listener is what makes a write visible to the **same** process: the
case of a console command or a Messenger worker, which never get the `setLocale()` call an
HTTP request brings.

Operational details (when to step in manually, clearing the caches):
[operations/cache.md](../operations/cache.md).

## When the database is unavailable

Loading the overrides happens inside **every** `trans()` call, so it is deliberately
fail-open: an unreachable database or cache backend (a migration not yet run right after
`composer require`, a failover, a pool being replaced) logs an `error` and serves the file
catalogue value. The overrides are lost for the duration; the pages are not.

The empty result is memoised for the rest of the process, so one outage costs one failed
connection per (locale, scope), not one per translated label.

## Save-time syntax validation

The runtime can only guess a stored value's format; the save is where certainty lives.
`TranslationValueValidator` compares the value against the catalogue's **declared** format
(the `+intl-icu` file naming convention, exposed per key by the scanner) and refuses what
would display broken: an invalid ICU pattern on an ICU key (a check that needs `intl`;
without it, every pattern passes), legacy placeholders (`%name%`) on an ICU key (a `|` is
not judged, it is ordinary text in an ICU message) and an unambiguous ICU construct
(`{x, plural, …}`) on a legacy key; a bare `{name}` stays tolerated as possible literal text. A key with
no file in the target locale yet (a missing translation being written) follows the
format of the locale that declares it: living in an `+intl-icu` variant makes it ICU,
whatever locale that variant belongs to. The console editor asks for an explicit
confirmation before saving such a value ("Save it anyway?", default no); suggestion approval
refuses it (single and bulk; skipped rows are reported); the import skips the entry and
names it; an orphan catalogue is not judged.

## Lifecycle of an override

- **Creation / modification**: from the [interactive console](../operations/commands.md), by
  [approving an AI suggestion](suggestions.md), or programmatically via `OverrideWriter::save()`
  (`TranslationManager::saveOverride()` delegates to it). Every write goes through that one
  class, which owns what none of them may skip: refusing a target the tables cannot hold
  (key, locale (malformed, dashed, or a case variant of an available one), catalogue,
  scope) before anything is
  written, stamping the author (`updated_by`, from `AuthorProviderInterface`), invalidating
  the caches, dispatching `OverrideSavedEvent`, and closing the pending suggestions of the
  key it sets ([suggestions.md](suggestions.md), "Statuses"). The original catalogue value is kept (`original_value`) for the diff;
  an override created by an approval records it too.
- **Removal**: `OverrideWriter::remove()`, explicit: `OverrideWriter::save()` with the
  original value does not remove the override. The doors do: the console editor, an
  approval and an import that set an entry back to its baseline (inherited override, else
  file value) remove its override (`OverrideChange`).
- **Export / import**: `cyllene:ai-translation:export-overrides` produces an XLIFF (or
  CSV) file of the stored overrides (for instance to move the customisations back into real
  translation files at release time); `import-overrides` goes the other way (restore,
  promotion between environments).
- **Events**: every save/remove dispatches `OverrideSavedEvent` / `OverrideRemovedEvent`,
  the hook point for side effects the bundle cannot know about (HTTP cache purge, audit log,
  webhook…).
