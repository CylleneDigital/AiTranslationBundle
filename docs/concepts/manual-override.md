# Editing a single override

The atomic gesture of the whole bundle: **store one value that takes precedence over the
translation files** for a (locale, catalogue, key, and optionally [scope](scopes.md))
tuple. The files are never modified; the override is a database delta applied on top of
them, which survives every upgrade of the application and its dependencies.

## Where a single override comes from

Four doors, all converging on the same `OverrideWriter` (`save()`, or `saveMany()` for a
batch; `TranslationManagerInterface::saveOverride()` delegates to it):

- **the CLI**: the interactive console (`bin/console cyllene:ai-translation`, entry
  "Browse and edit translations"), either from the key (autocompletion, then the key's
  whole landscape shown untruncated per catalogue and locale, with the file value even when missing,
  global override, scoped overrides) or by browsing a filtered selection; both
  end in edit / remove / create on the narrowed combination;
- **a host integration**: the same edit through `TranslationManagerInterface::saveOverride()`;
- **approving an [AI suggestion](suggestions.md)**: the approval writes the override;
- **an [import](../operations/commands.md)**: each restored entry becomes an override.

## What one save guarantees

Every door shares the effects of `OverrideWriter`; the console editor, the approval and the
import add two checks of their own: the syntax validation and the "already shown" rule
below. A host calling `TranslationManagerInterface::saveOverride()` / `saveOverrides()`, or
`OverrideWriter` itself, writes what it is given:

- the **target must be storable**, refused before anything is written
  (`TranslationKeyTooLongException`, `InvalidOverrideException`): a key over 400
  characters, a catalogue empty or over 255, a scope over 64, a malformed locale, one
  written with `-` (`pt-BR`: the runtime looks `pt_BR` up), or a case variant of an
  available locale (`FR_fr` where `fr_FR` exists); the message gives the spelling to use.
  The casing itself is the host's convention: `en_us` is stored and served as written, and a
  locale with no file yet is accepted;

- the **identity is the full tuple**: a key is only unique *within* its catalogue (a
  `messages` and a `validators` key may collide), which is why the catalogue is part of
  the row. On the CLI it is **inferred** when a single catalogue knows the key, and
  ambiguity becomes an explicit choice;
- the **value is validated** against the catalogue's declared syntax (ICU vs legacy
  placeholders; see [overrides.md](overrides.md), "Save-time syntax validation") by the
  doors, not by `OverrideWriter`: each of them turns a refusal into what its user
  understands; the console editor asks for an explicit confirmation ("Save it anyway?",
  default no), the approval refuses, the import skips the entry and names it. Code of your
  own that writes through `TranslationManagerInterface` or `OverrideWriter` has to run
  `TranslationValueValidator::validate()` itself if it wants the same guard;
- the **author is recorded** (`updated_by`, via the host's `AuthorProviderInterface`),
  along with a snapshot of the original file value when the override is created; the
  editor, the approval and the import pass it; a host passes it as `$originalValue`
  (`null` otherwise);
- the **caches are invalidated** for the touched locale: the change is visible on the
  very next `trans()` call, no `cache:clear` needed;
- an **`OverrideSavedEvent`** (or `OverrideRemovedEvent`) is dispatched for the host to
  react to (audit, webhooks, cache warmers…);
- a value the entry **already shows writes nothing**: equal to its baseline (the override
  it inherits, i.e. the global one in a scope or the parent language's for a regional locale,
  else the file value), no override is created, and a stored one is removed, back to the
  value it inherits (the global override, the parent language's, else the file); equal to
  the stored override, nothing is rewritten.
  An override identical to the file would only shadow its next correction. The editor,
  the approval and the import apply that rule (`OverrideChange::decide()`); a host
  writing through `OverrideWriter` directly writes what it is given;
- the **pending suggestions of the key are closed** (same catalogue, locale and scope,
  errored ones included): the value written supersedes them, so they are rejected (rows
  deleted, `SuggestionRejectedEvent` with `$supersededBy` = `override`), in the same
  transaction as the override, their rows locked and their status read back from the
  database; one approved meanwhile by another process is left alone. Removing an override
  closes nothing: going back to the value it inherits is not a choice between the
  proposals. See [suggestions.md](suggestions.md), "Statuses".

### A save flushes the whole entity manager

`OverrideWriter::save()`, `remove()`, `saveMany()` and `removeMany()` end with an
`EntityManager::flush()`. Doctrine ORM 3 dropped the single-entity flush, so that call
commits **everything** the current entity manager has pending, including the changes
your own controller or command staged before it. `save()` and the batch writes run in a
transaction of their own, which nests in yours when you call them inside one (on DBAL 3,
see [Doctrine DBAL 3](../operations/doctrine-dbal-3.md)).

That is the standard behaviour of any Doctrine-backed bundle, and it is harmless when the
save is the last thing a request does. It is worth knowing about in two cases:

- a controller that stages entities of its own and then writes an override: those entities
  are committed too, whether or not the request goes on to succeed;
- a batch: prefer `saveMany()` / `removeMany()`, which flush **once** for the whole set and
  invalidate the caches once per touched locale, over a loop of unit saves.

`ScopeParametersManager` exposes `$flush = false` on its writers for the same reason: an
integration package writing from its own form handling keeps ownership of the flush.

### Two first writes of the same override at once

A save looks the row up, then inserts it when there is none. Two processes creating the
**same new** override at the same instant (two admins on the same key, an approval during an
import) can both see no row: the second insert hits the unique index and fails with a
`UniqueConstraintViolationException`, which also closes that request's entity manager. Nothing
is corrupted (the first value is stored, the second write is refused), and replaying it
updates the row. It takes a write race on a key that has no override yet, so it is left as a
known limit rather than paid for with a platform-specific upsert on every save.

## Living with overrides

- the interactive console's "Browse and edit translations" entry inventories what is
  stored and, with the *every translation of one catalogue* source, what is **not**
  overridden yet;
- removing an override immediately falls back to the value the entry inherits (the global
  override in a scope, the parent language's for a regional locale), else the file value;
- an override whose catalogue's files disappeared (deleted file, renamed directory,
  removed theme) becomes an **orphan**, inert but noisy; the console's "Purge the
  orphan overrides" entry cleans up;
- at release time, `export-overrides` turns the accumulated deltas into a file you can
  fold back into `translations/`, then delete the now-redundant rows.

The runtime side (how the stored value shadows the file on every `trans()` call) is
the decorated translator's job: [overrides.md](overrides.md).
