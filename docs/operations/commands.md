# CLI commands

The console splits in two, on purpose:

- **`cyllene:ai-translation`**, the **interactive console**: one entry point, a menu of
  guided tasks, no option to memorise. Everything a human does at a terminal goes
  through it.
- **Five option-driven commands** for the machines (CI, cron, scripts):
  [`coverage`](#coverage), [`generate`](#generate), [`export-overrides`](#export-overrides),
  [`import-overrides`](#import-overrides) and
  [`cleanup-suggestions`](#cleanup-suggestions). The `--scope` option of `coverage`,
  `generate` and `export-overrides` is validated: when the host declares scopes, an unknown code is
  **refused** with the declared list; an unnoticed typo would otherwise render the
  global figures dressed as the scope's.

A host integration can also run generation asynchronously, by dispatching
`GenerateSuggestionsMessage` (see [AI suggestions](../concepts/suggestions.md)). This console
runs it synchronously (the journey and `generate` alike), so the progress and the exit code
reflect the actual outcome.

## `cyllene:ai-translation`: the interactive console

```bash
bin/console cyllene:ai-translation
```

Opens the menu; every entry is a guided flow that asks its own questions, and only the
ones that make sense: a host without scopes never sees a scope question, a single
configured provider is announced instead of asked, a review queue spanning one locale
skips the locale filter. After each task the menu comes back, until *Quit*. Without a
TTY the command refuses and points at the automation commands.

### Generate AI translation suggestions

Pick the source and target locales, a catalogue (or all), the provider, the scope, and
the key set: the missing keys (default), every key, or the previously errored ones
(retry). The **cost estimate is a built-in step**, not an option: keys and estimated
price per catalogue (LiteLLM list prices for the LLMs, remaining account quota for
DeepL) are on screen before the launch confirmation, `no` by default: `yes` has to be
typed, a plain enter (or an input that ran dry) sends nothing.

- keys that already have a **pending** suggestion (in the same scope) are always skipped
  (re-running is idempotent); errored suggestions count as pending too, unless the retry
  mode re-sends their keys, and then only their keys, each treated the way the run that
  failed on it would have treated it (see [`generate`](#generate));
- with a scope, a key missing globally is missing in the scope too: the run fills **that
  scope only**, the global value stays missing by design (the scope inherits whatever
  the global value becomes later);
- a failed request (quota, authentication, network) **stops the current catalogue**, and an
  unusable reply only loses its own batch; either way the other catalogues are processed and
  the summary reports the failure count (429/503/529 are retried automatically first, see
  the bridge pages);
- batches are capped in count (20) **and** in source-text volume (~6000 characters), so
  long values cannot overflow the model's reply.

### Review the pending suggestions

The pending queue as a table, an optional narrowing step (locale, catalogue, scope,
each proposed only when the queue actually spans several values), then a choice: *Review
them all*, *Pick one suggestion by id* (from the table), *Delete all errored suggestions*
(after a confirmation, default no) or *Nothing for now*; a queue of one asks "Review this
suggestion now?" (default yes) instead. Then one decision per suggestion: `approve`, `edit`
(fix the value before applying), `reject`, `skip` (the default) or `quit`; an errored row,
which has no value yet, offers `edit`, `reject`, `skip` and `quit` only. The session ends on
a tally ("N approved, N rejected, N skipped, N left for later"), and each approval says
what it did to the override: "override written", "same as the current value, no override
written" or "same as the file / inherited value, override removed". An approval the bundle refuses (a lost placeholder, a broken syntax, no
value) **asks again on the same card**, so the value can be fixed there. Each card shows
the source text, the **current value** of the entry when it has one ("Current value (file /
override / inherited)", the case of an *every key* run, with a note when the proposal is
that very value: approving then writes no override), the proposal, provider, confidence
and generation error, plus three warnings when they apply: a **live placeholder diff**
(computed as the approval computes it, not read from the metadata the generation
recorded), a **live syntax check** of the value against the catalogue's declared format,
and the **plural categories** a `plural` / `selectordinal` of the proposal lacks for the
target language ("… lacks the en_US categories two, few", needs `intl`). When the detected mistakes are deterministically
repairable, such as a renamed placeholder ("%nom%" for "%name%") or a translated ICU skeleton
("pluriel", "autre"…, realigned on the source since ICU keywords are invariant), the
card also shows the **autocorrected value** with the exact replacements applied, and an
extra `approve autocorrect` decision applies it directly; `edit` then starts from the
corrected value. A fix is only proposed when the result passes both guards in full: a
lost placeholder with no renamed counterpart stays a human's call. Approvals go through
the same safeguards as any other caller: a refusal leaves the suggestion pending.
Rejecting deletes the row, making the key eligible for a next run.

### Browse and edit translations

One entry, two doors onto the same action surface: finding a translation, then editing
it through `OverrideWriter` with all of its effects (author, cache invalidation,
`OverrideSavedEvent` / `OverrideRemovedEvent`, and the pending suggestions of the key
closed as superseded). A value the entry already shows saves nothing ("Same as the file
value — nothing saved", or "the inherited value"), and typing it over an override removes
that override.

**"I know the key"**: everything starts from the key, and deciding comes after seeing:

- the key input is **autocompleted** on every key the bundle can see (file entries and
  stored overrides, orphans included);
- the key's **whole landscape** is displayed per catalogue and per locale (the
  resolution cascade itself: the file value (shown even when missing, so a gap reads
  as a gap), the global override, then each scoped override), with untruncated values,
  author and update date: the only place the complete stored values of a key can be
  read, and the shadowing order made visible;
- the locale answer re-displays that locale's cascade as a recap before the next
  questions, and nothing else: a locale left out of the answer is not shown, and never
  flagged as gone;
- the catalogue is **inferred** when a single one knows the key, **picked** when
  several do, and picked among every catalogue for a brand-new key; a key absent from
  the files is accepted: that is how a missing translation gets its value.

**"Let me browse and filter"**: for when the key is what you are looking for:

- the **source** is asked first, and is not a filter: *the stored overrides* is a small
  set one query brings back whole, while *every translation of one catalogue* is a
  catalogue × locale walk with no upper bound; answering the coordinates before
  loading is what keeps the second branch affordable;
- that second branch **splits the locale in two**, the way the coverage report does: a
  **reference locale** decides which keys are listed, a **target locale** carries what
  is displayed and edited, and the table reads them side by side
  (`Key | en | fr | Override | Scope`). Listing from the reference is what puts the
  keys the target has *never* translated on screen: browsing the target alone showed
  only what it already had, which is the opposite of the work to do. A key the target
  holds and the reference does not is left out on purpose: that is drift, and
  [Inspect the default locale keys and drift](#inspect-the-default-locale-keys-and-drift)
  is where it belongs;
- the selection is narrowed by the same contextual filters as the review, **override
  presence** and **missing in the target locale** (an empty value included, shown as
  `(empty — counted as missing)`) being two of them (each skipped
  without a word when every row answers the same, which is what makes the
  overrides-only view free rather than a question); the catalogue filter is an
  **autocompleted input**, since identifiers can be long paths;
- the table is a menu, not the working surface: past **50 rows** it shows the head of
  the selection and says how much it is hiding; the actions still cover every row.

Both doors end in the same action, on the exact combination: **edit**, **remove**
(after a confirmation) or **nothing** when an override exists there,
straight to the value prompt otherwise (Enter there keeps the current value). From the browsing door,
`Show or edit them all` walks the whole selection one translation at a time (every row,
with or without an override, asks what to do first (`Edit the value`, `Nothing`, `Quit`,
plus `Remove it` when an override exists), with a `Quit` that stops the walk and a tally
of what was left for later), and `Edit one key`
jumps straight to one row by name (a key the selection holds in several locales,
catalogues or scopes is followed by a "Which one?" question).

When the value being edited already spans several lines (a legal text, an e-mail body), the
new one is typed **line by line** (here and in the review's `edit` decision alike), then a
line holding a single `.` ends it; a blank line is kept (paragraphs), and Enter on the first
line keeps the current value. Plain one-line questions: a script piping its answers works
the same way. A one-line or missing value keeps the one-line prompt: to give such a key a
value of several lines, use an [import](#import-overrides).

A value failing the catalogue-syntax validation (ICU vs legacy placeholders) is not
saved blindly: the issue is shown and saving anyway is an **explicit confirmation**.
Removing from a gone catalogue is allowed (that is an orphan cleanup).

### Export the overrides to a file / Import an overrides file

Two menu entries, one idea (the same delta backup as the option-driven commands,
guided): the export asks the
format, the filters (locale, scope, catalogue: a full identifier or any prefix,
autocompleted) and the destination; the import validates the file on the spot,
**previews its counts** (the same diff-against-baseline rules as the real import), names
the entries it would skip (the first ten, then how many more), and writes only after a confirmation ("Apply these changes now?",
default yes). Scripted backups and promotions stay on
[`export-overrides`](#export-overrides) / [`import-overrides`](#import-overrides).

Both prompts keep the file **inside the project directory**: the export defaults to
`var/export/`, and the import asks for the file after suggesting `var/import/`. The file is
read and written as the user running the console, so a path outside the project (another
user's home, a system directory) is the usual source of permission errors.

### Coverage report

The same figures as the `coverage` command: default locale picker, scope, an optional single
target locale, and the missing keys listed on demand (keys covered by a pending
suggestion are flagged). The CI gate
(`--min`) stays on the option-driven command: a threshold question makes no sense on a
human run.

### Inspect the default locale keys and drift

Two perspectives, picked at the start. The **default locale's translatable keys** per
catalogue: the exact denominator the coverage percentages count against. Or the **drift**:
the keys another locale carries beyond the default locale, which the coverage figures cannot
see by construction (a renamed key whose old translations linger, a stray override, a
translation whose source in the default locale was deleted).

The drift mode compares **one locale** to the default one, inside the chosen scope, on
effective values (the override if there is one, the file value otherwise); the console
recaps that in a few lines before asking which locale to compare. An empty listing means no
drift; a listing is a report, not a cleanup. Full explanation:
[coverage.md](../concepts/coverage.md#drift-the-mirror-question).

### Purge the orphan overrides

Lists the overrides whose **catalogue no longer exists** in the translation files
(deleted file, renamed directory, removed theme): inert (the translator only shadows
loaded catalogues) but clutter for the "modified only" view. Purged only after an
explicit confirmation (default: no), in one pass (a single flush, one cache
invalidation per touched locale, one `OverrideRemovedEvent` per override).

Two cases are not judged, because the scan cannot tell a gone catalogue from one it could
not read: with **no catalogue found at all** (a wrong `translations_path`) the entry refuses
to run, and the catalogues of an **additional root whose directory is missing** (`@label/…`,
a theme not deployed on this machine) are left out of the list.

### Clear the translation caches

Invalidates the overrides' `cache.app` entry, for one
locale or all of them. Normally unnecessary (invalidation is automatic on every
change); see [Caches](cache.md) for the cases where it helps.

## `coverage`: per-locale coverage (CI gate) <a id="coverage"></a>

```bash
bin/console cyllene:ai-translation:coverage [-d|--default-locale=fr_FR] [-l|--locale=…] [-s|--scope=…] [-m|--min=95] [--list-missing]
```

Measures, for each locale, the share of the **default locale**'s keys (option
`--default-locale`, else the `default_locale` config, else the framework's
`default_locale` matched against the available locales, else the first available
locale, else the framework's `default_locale` as it is) that have a value (a locale given
with `-` is read with `_`, as for `generate`):
translated, missing, coverage %, plus, as an indicator, the number of suggestions **awaiting
review** for the locale (a key they cover still counts as missing: nothing is applied yet). The computation
is always **fresh** on the CLI (`coverage_cache_ttl` only caches `getReport()`, which the
CLI does not use).

`--min=95` turns the command into a **CI gate**: exit `1` as soon as a locale falls under
the threshold ("the build fails when German drops below 95%"). The comparison is made on
the exact ratio, and the displayed percentage is rounded **down** (2499 keys of 2500 show
99.9 %, and fail `--min=100`). A threshold outside 0–100 is refused (exit 1) before
anything is computed: no locale can reach 150 %. A default locale that has no translation file is refused
(exit 1): it would have no key to miss, and every locale would pass at 100 %. `--locale` narrows the
measure to one target locale (exit 1 when it is not available); `--min` with no target locale
measured at all exits 1 too, rather than passing on nothing. `--scope` computes the figures **as one scope sees them**: its own
overrides on top of the inherited global ones (and its own pending suggestions). The command also reports configured `additional_paths` that point at
no existing directory.

`--list-missing` names the missing keys after the table, per locale and catalogue: the
listing behind the percentages, ready to hand to a translator or attach to a failing CI
gate. Keys already covered by a **pending suggestion** are flagged `(pending review)`,
so work in flight is not dispatched twice.

## `generate`: scripted AI generation <a id="generate"></a>

```bash
bin/console cyllene:ai-translation:generate -t|--target=fr [--target=de] [-o|--source=en] \
    [-c|--catalogue=shop/Product/messages] [-p|--provider=…] [-s|--scope=…] \
    [--all | --retry-errors] [--max-cost=5] [--dry-run]
```

The generation journey driven by options, for cron and CI: the same key selection, the
same estimate, the same run and the same guarantees (per-run cap, lock, errored rows).

- `--target` is required and repeatable; `--source` defaults to the resolved default
  locale (the coverage one); `--catalogue` is repeatable and defaults to every catalogue;
  `--provider` defaults to `default_provider`. A locale written with `-` is read with `_`
  (`pt-BR` is the `pt_BR` of `messages.pt-BR.yaml`). Every value is checked before anything
  is estimated: an unknown locale, catalogue, provider or scope exits 1, and so does a
  source locale given as a target too. A run sends at most 500 keys per catalogue
  (`MAX_KEYS_PER_RUN`, logged as a `warning`): the rest is left for a later run.
- The key set is the missing keys by default; `--all` re-translates every key,
  `--retry-errors` re-sends the keys whose previous run failed, and only those, each
  treated the way the run that failed on it would have treated it: an errored key of an
  `--all` run is re-sent although it has a value; one of a missing-keys run only while it
  is still missing; once the files have filled it, it is not billed again and its errored
  row is closed
  ([Errored suggestions](../concepts/suggestions.md#errored-suggestions)). The three key
  sets exclude one another, hence `--all --retry-errors` is refused.
- The estimate is always printed first. `--dry-run` stops there. A negative `--max-cost`
  is refused (exit 1) before anything is estimated. `--max-cost` refuses the
  run (exit 1) when the estimated total exceeds the amount, or when it **cannot be
  priced** (DeepL, a model absent from the LiteLLM list): a ceiling that cannot be
  checked fails closed. For a reasoning model (OpenAI o-series, gpt-5) the estimate is
  shown as a floor (`≥`): the reasoning tokens are billed on top and cannot be forecast,
  so the ceiling only catches the runs whose floor already exceeds it.
- Interactive, the launch waits for the same confirmation as the journey.
  Non-interactive (`-n`, cron) it goes ahead: `--max-cost` is the guard rail there.
- Exit code 1 when a catalogue failed the provider; its unfulfilled keys are stored as
  errored suggestions, for the review or a later `--retry-errors` run. A catalogue locked
  by another run is a warning only. A failure that is not the provider's (a custom
  provider throwing something else, a bug) is journaled and leaves errored rows too, then
  stops the command with the exception.

The suggestions still wait for a human in the review; nothing is applied by this command.

## `cleanup-suggestions`: retention of the review history <a id="cleanup-suggestions"></a>

```bash
bin/console cyllene:ai-translation:cleanup-suggestions [-b|--before="30 days"] [--dry-run]
```

Deletes the **reviewed** suggestions older than `--before` (default: 30 days)
(`pending` ones are never touched, and rejected ones are deleted on the spot anyway),
along with the generation-log entries of the same age. Worth a cron entry when
generation runs regularly: the tables keep the recent audit trail without growing
forever. `--dry-run` counts the reviewed suggestions and the generation-log entries that
would be deleted, without deleting anything.

`--before` is an **age**, a number and a unit among days, weeks, months and years:
`"30 days"`, `"2 weeks"`, `"6 months"`, `"1 year"` (at least 1). Anything else is
refused with exit 1: a unitless `30`, a `"1 week ago"`, a date such as `yesterday` or
`2026-09-01` (which PHP's date parser would read as the year −2026, deleting nothing
without a word).

## `export-overrides`: the delta backup (XLIFF / CSV) <a id="export-overrides"></a>

```bash
bin/console cyllene:ai-translation:export-overrides [path] [-f|--format=xlf|csv] \
    [-l|--locale=…] [-s|--scope=…] [-c|--catalogue=shop]
```

Exports the stored overrides (**the deltas only**) to a file (default:
`var/export/translation_overrides.<format>`) via `OverrideExporter`. This is the
backup/migration artifact: re-imported as-is it restores exactly the stored overrides, every
locale and every scope in one file. (`ViewExporter` produces a different artifact: a snapshot
of one browsed view, originals included, for a host that offers "export what I am looking at".)
A `--locale` written with `-` is read with `_`, the spelling the overrides are stored under.

- **xlf** (default): XLIFF 1.2, one `<file>` per (locale, catalogue, scope) triple: the
  locale in `target-language` (the BCP 47 `fr-FR` a translation tool writes is read as
  `fr_FR`, in CSV too), the catalogue identifier in `original`, the key in `resname`, the
  scope in the standard `category` attribute (absent = global);
- **csv**: one line per override, `locale,catalogue,translation_key,value,scope` header
  (empty `scope` column = global override). A cell starting with `=`, `+`, `-`, `@`, a tab or
  a carriage return (behind any apostrophes), in any column (an additional root's catalogue is `@label/…`), is prefixed with an
  apostrophe so a spreadsheet reads it as text instead of running it as a formula; the
  importer strips that prefix back, so a round trip returns the exact stored strings.

Without `--format`, the **extension of the path** names the format (`.xlf`/`.xliff`, `.csv`,
the same detection as the import), else xlf. A format contradicting the extension
(`--format=xlf` into `export.csv`, or the same answers on a TTY) is refused: the file is
read back by its extension, and XLIFF in a `.csv` would fail the import.

`--locale`, `--scope` (`""` for the global overrides only) and `--catalogue` (a full
identifier or any prefix, e.g. `shop`) narrow the export.

On a TTY, format and destination are **asked** with the defaults pre-filled: a plain
enter on each prompt behaves exactly like the bare invocation. An explicit `--format`
or path argument skips its prompt (a path whose extension names a format skips the
format prompt too), and nothing is asked when the store holds no override.

Typical use case: bring the validated customisations back into real
`translations/` files at release time, then delete the now-redundant overrides.

## `import-overrides`: the export's counterpart <a id="import-overrides"></a>

```bash
bin/console cyllene:ai-translation:import-overrides <file> [--dry-run]
```

Imports a file in the export formats (xlf/xliff or csv, detected by the extension), cache invalidation and events included. On a TTY a missing
file argument becomes a prompt (path validated on the spot); without one, an actionable
error. Put the file inside the project (`var/import/` for instance): it is read as the user
running the command, so an outside path is the usual permission error. Serves to restore a backup, promote
overrides between environments, or apply a corrected **view snapshot** (the 6-column CSV
layout with its read-only `original` column is accepted).

The import **diffs against the effective baseline**, what the entry inherits once the
import is applied: the global override for a scoped entry, the parent language's for a
regional locale, else the file value. A value identical to it is ignored, and so are empty
values (the untranslated rows of a snapshot). A file that changes the global value and
repeats the old one for a scope writes the scoped entry: the parents are decided first. A full snapshot therefore re-imports into
exactly the overrides that differ from that baseline. When such a value lands on an entry
that **has** a stored override, the file sets it back to its baseline: that override is
**removed** (counted apart in the output), not silently kept. A value identical to the
override already stored is **unchanged** too: re-importing an untouched export writes
nothing (no `updated_at` moves, no `OverrideSavedEvent` reaches the host's purges).

Structurally invalid entries, and entries whose locale, catalogue or scope the project
does not know, whose key exceeds 400 characters, or whose value does not match the
catalogue's syntax (ICU or legacy), are skipped and **each named** (`row 12 — app.cart.title: unknown catalogue "shop/carte"`, the
first ten, the CSV row being the one a spreadsheet shows): the valid ones are still applied, but the command
**exits 1**, so a CI step notices. A file with no recognised entry at all (an XLIFF 2.0, a
missing namespace) exits 1 too; an unintelligible file fails the command.

CSV files may come straight from a spreadsheet: a UTF-8 BOM and `;` separators (a
French-locale Excel) are accepted, and the export writes a BOM so Excel reads the accents
right. A file in another encoding is refused with a "save it as CSV UTF-8" message.
