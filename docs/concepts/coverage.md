# Coverage: how translated is each locale?

Coverage answers one question per locale: **out of everything that should be
translated, how much actually is?** It powers the reports a host displays, the
`cyllene:ai-translation:coverage` command and the CI gate.

## The default locale

"Everything that should be translated" needs a reference: the project's **default
locale**. A key counts as *translatable* when the default locale has a non-empty
effective value for it; every other locale is then measured against that key set.

The default locale is resolved in order:

1. the configured `default_locale` (the bundle's own key);
2. else the framework's `default_locale`, matched against the locales that actually
   have files, exactly or by prefix (`fr` picks `fr_FR`);
3. else the first available locale;
4. else, with no locale available at all, the framework's `default_locale` as it is.

`--default-locale` overrides it per run, useful to reverse the perspective ("what does
English miss compared to French?").

## What counts as translated

For each of the default locale's keys, a target locale scores **translated** when it has a
non-empty **effective value**: override applied over the file value. This is the exact
definition the AI generation uses to pick the missing keys, and the interactive browser's
*missing* filter. An empty value, in the file or in an override, counts as missing: the
translator renders it as a blank label. The overrides are read through the language chain
(an override saved on `fr` counts for `fr_FR`), and with `--scope=X` the effective value
is the scoped override, else the inherited global one, else the file: the figures are
*what a visitor of that scope actually sees* ([scopes.md](scopes.md)).

A key covered by a **pending suggestion** still counts as **missing** (nothing is applied
yet), and the percentage is translated / (translated + missing). The `Pending review` column
is an indicator: the number of pending suggestions of that locale and scope, errored rows and
the suggestions of an *every key* run on already translated keys included
([suggestions.md](suggestions.md)).

## What the percentage does NOT see

Coverage is **relative to the default locale** by construction:

- a key the default locale itself misses is invisible: it belongs to no denominator;
- a key another locale carries *beyond* the default locale (renamed key, stray
  override, deleted source) is invisible too.

That second blind spot has a name, **drift**, and its own tool: the interactive
console's "Inspect the default locale keys and drift" entry, which lists the exact key
set behind the percentages, and the drift alongside it.

Only the application's own keys count: third-party bundle catalogues (usually shipped
already translated) are out of scope; see [catalogues.md](catalogues.md) for what
"missing" means exactly.

## Drift: the mirror question

Coverage asks *what is this locale missing?* Drift asks the opposite: *what does it carry
that the default locale does not?*

A key is in drift when the compared locale has a non-empty **effective value** for it and
the default locale has none, "effective value" meaning the same thing as everywhere else:
the override if there is one, the file value otherwise. The usual causes are mundane: a key
renamed in the default locale whose old translations linger, a translation whose source was
deleted, an override saved on a key that no longer exists.

Three consequences of measuring effective values, rather than files:

- an override alone can create drift: no file has changed, someone simply saved a value
  for a key the default locale does not have;
- an override that *empties* a key in the default locale puts every translation of that key
  in drift at once;
- an empty value is never drift: an empty key counts as absent on both sides.

And two things to keep in mind when reading a listing:

- **it compares one locale to the default one, in one direction.** "No drift in `fr_FR`"
  says nothing about `de_DE`: check the locales you care about, one run each;
- **it is read in a scope.** Both sides are compared inside the chosen scope, so the same
  pair of locales can drift in one scope and be clean in the global one
  ([scopes.md](scopes.md)).

Drift is a report: nothing is changed. Removing a listed key means editing the translation
files, or deleting its override from the "Browse and edit translations" journey. The
orphan purge does not cover this: it only removes overrides whose whole catalogue is gone
([commands.md](../operations/commands.md#purge-the-orphan-overrides)).

## Reading and acting on it

```bash
bin/console cyllene:ai-translation:coverage                  # the per-locale table
bin/console cyllene:ai-translation:coverage --list-missing   # …plus the missing keys, named
bin/console cyllene:ai-translation:coverage --min=95         # CI gate: exit 1 under 95 %
```

`--list-missing` turns the figures into a work list (per locale and catalogue, with
pending suggestions flagged), ready to hand to a translator or to feed the AI
generation. `--min` makes the build fail when a locale drops below the threshold. The
command also warns when a configured `additional_paths` entry points nowhere: a typo
there would silently shrink the denominator.

## Freshness

The CLI always **recounts from scratch**: a CI gate must not trust a cache. The
repeated display, which would recompute every time, is cached instead
(`coverage_cache_ttl`, short TTL, dropped on every override change); see the
[configuration reference](../configuration-reference.md).

A recount reads the translation files through the scanner (memoised for the process) and
the overrides in **one query per locale**, not one per (catalogue, locale) pair: the
report walks every catalogue of every locale, so the difference is a few queries instead
of several hundred on a large project.

Full option list: [operations/commands.md](../operations/commands.md).
