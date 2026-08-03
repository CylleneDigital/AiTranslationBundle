# Catalogues & missing keys

The bundle reads **the host project's `translations/` directory directly**; the catalogues of
third-party bundles are deliberately out of scope: it is the **application's** translations
we want to see and edit. `TranslationManagerInterface` is the single entry point; the scan is done by
`TranslationFileScanner`.

## The three-level convention

Each file is identified by its relative path without locale or extension (its
**catalogue identifier**), which breaks down into three navigation levels:

| File | Identifier | Category | Domain | Type |
| --- | --- | --- | --- | --- |
| `translations/shop/Product/messages.fr.yaml` | `shop/Product/messages` | shop | Product | messages |
| `translations/shop/Product/Review/messages.fr.yaml` | `shop/Product/Review/messages` | shop | Product > Review | messages |
| `translations/shop/messages.fr.yaml` | `shop/messages` | shop | *default* | messages |
| `translations/messages.fr.yaml` | `messages` | *default* | *default* | messages |

- **Category** = the first directory level (`default` implied for files at the root);
- **Domain** = the following levels, possibly nested (`Product/Review`, displayed
  "Product > Review"; `default` implied when there are none);
- **Type** = the file name (`messages`, `flashes`, `validators`…).

The file name follows the Symfony convention, parsed from the right:
`{type}(+intl-icu).{locale}.{extension}`. The recognised extensions are **`yaml` / `yml`,
`xlf` / `xliff`, `json`** (via the Translation component's loaders). The `+intl-icu` suffix is
absorbed: `messages+intl-icu.fr.yaml` and `messages.fr.yaml` belong to the same catalogue, with
the ICU variant taking precedence (as in the translator). A locale written with `-`, as
translation tools name their files (`messages.pt-BR.yaml`), is read with `_`: it is the
`pt_BR` locale, the spelling Symfony and the stored overrides use.

**Nothing is silently ignored**: a file whose name or content cannot be interpreted is listed
with its reason (`getIgnoredFiles()`), for the caller to surface as a warning.

The scanned directory is configured via `translations_path` (default:
`%kernel.project_dir%/translations`). `additional_paths` adds more roots (a theme, a
vendor package), whose catalogues are identified as `@label/…`; a root whose directory
does not exist is skipped by the scan (the `coverage` command warns about it) and the
orphan purge leaves the catalogues of such a root alone.

## Where the locales come from

From the `LocaleProviderInterface` alias. By default (`ScannedLocaleProvider`), they are the
locales seen in the scanned files. An integration package re-aliases the interface to the
host's own locale registry (typically a database-backed provider rather than one frozen
at compile time).

## The merged view of a catalogue

`getTranslationsForCatalogue(catalogue, locale, scope)` returns, for each key:

```php
['original' => ?string, 'override' => ?string, 'hasOverride' => bool, 'inherited' => ?string,
 'inheritedFrom' => ['locale' => string, 'scope' => string] | null]
```

- **`original`** merges the files of the **same-language fallback chain**: for `fr_FR`, the
  `*.fr.*` files first, overlaid by the more specific `*.fr_FR.*` files. No **cross-language**
  fallback: a key only defined in English is correctly reported as **missing** in French,
  which is exactly what the AI suggestion flow detects. See the
  [design note](../design/same-language-fallback.md).
- **`override`** is the entry's **own** row in the table (the one an edit changes and a
  removal deletes), including for a key that has disappeared from the file (`original` is
  then `null`: drift, see [coverage](coverage.md#drift-the-mirror-question); an *orphan*
  is an override whose whole catalogue is gone, which the purge removes).
- **`inherited`**: the override the entry falls back to without its own row, read through
  the same chain as the runtime: in a scope, the global override; for a regional locale
  (`fr_FR`), the parent language's (`fr`), in the global view too. The more specific
  locale wins, then, within a locale, the scope's row before the global one: for `fr_FR`
  in scope `b2b`, the global `fr_FR` row, then the `b2b` `fr` row, then the global `fr` row.
  **`inheritedFrom`** names that row (`null` when nothing is inherited).

`override ?? inherited ?? original` is therefore what a visitor sees: the definition the
generation's missing keys, the coverage and the console's browser share.

## What "missing" means

A key is missing in the target locale when it has **no non-empty effective value** for that
catalogue: neither its own override, nor an inherited one (the parent language's, the global
one in a scope), nor the files (same-language chain) provide one. An empty value (`""`, in a
file or an override) counts as missing: the translator would render a blank label. The comparison is file to file: a key present in
`messages.en.yaml` but absent from `messages.fr.yaml` is a candidate for AI generation into
French.
