# Caches

Two layers sit between a stored override and the text on screen. Both are invalidated
**automatically** on every save/remove; this page is mostly here to explain what happens and
to know when to step in manually. A third one, under the bundle's own screens and commands,
keeps the parsed translation files: see [The parsed translation files](#the-parsed-translation-files).

## The layers

| Layer | Who | Invalidation |
| --- | --- | --- |
| In-memory map per (locale, scope) | `OverrideAwareTranslator` | `TranslatorOverrideCacheListener`, on `OverrideSavedEvent`/`OverrideRemovedEvent`, plus `setLocale()`, which HTTP triggers on every request. |
| `cache.app`: `cyllene_translation_overrides_<locale>_<version>[_s_<hash of the scope>]`, TTL 1 h | `OverrideAwareTranslator` | `TranslationCacheManager::invalidate($locale)` on every change (it drops the locale's version token, which orphans every scope variant at once), repeated once the request, command or message is over, for the writes made inside a transaction. |

Symfony's compiled catalogues are not a layer of the overrides: they hold the file values
only, the overrides are applied on top of them, so the bundle never deletes them.

The one-hour TTL on `cache.app` is a safety net, not the main mechanism: in normal operation
the entry is deleted on change and rebuilt on the next read (at worst one `findForRuntime`
query per (locale, scope) per hour).

## When to step in manually

- **Direct database writes** (SQL import, fixture, replication): the bundle did not see them; use
  the interactive console's "Clear the translation caches" entry
  (`bin/console cyllene:ai-translation`).
- **Multi-server with a node-local `cache.app`** (filesystem/APCu per node): the invalidation
  only reaches the node that handled the change; the others serve the old value for up to 1 h.
  Two options: a shared `cache.app` (Redis…), or accepting the TTL (see
  [Running on several servers](multi-server.md)).
- **Long-running processes** (console command, Messenger worker, FrankenPHP worker mode): the
  in-memory map lives as long as the process. Two things drop it: `setLocale()`, which
  Symfony's `LocaleAwareListener` calls on every HTTP request (so the HTTP path is covered even
  in worker mode), and the `TranslatorOverrideCacheListener` on every override change. A
  console command or a Messenger worker never sees a `kernel.request`, so **the listener is
  what keeps them correct**: without it, a process that translated anything before writing an
  override would keep serving the pre-write value until it exits.
- **Direct writes bypassing `OverrideWriter`** (raw SQL, a fixture): no event is dispatched, so
  the in-memory map of an already-running process is not dropped either (see the entry above).

## The parsed translation files

The bundle reads the host's translation files itself, to list the catalogues, compare the
locales and find the missing keys: the translator's compiled catalogues mix in the framework's
and the other bundles' messages. Parsing them is most of the time of a view over every
catalogue, so each parsed file is kept in the pool
`cyllene_digital_ai_translation.translation_files_cache` (`translation_files_cache: true`, the
default).

| What | How |
| --- | --- |
| Key | The file's path, a hash of its content, and a format version the bundle bumps when it changes what it stores |
| Invalidation | None needed: a file whose content changes gets a new key, the old entry is orphaned |
| Lifetime | 7 days (`default_lifetime` of the pool), which bounds the orphaned entries |
| Not cached | The walk of the directories: a file added or removed is seen on the next request. A file that cannot be read: once readable again, it is read on the next request |
| Long-running processes | The scanner is reset between two Messenger messages (`kernel.reset`): a worker sees an edited file without a restart |

To empty the pool:
`bin/console cache:pool:clear cyllene_digital_ai_translation.translation_files_cache`.
`cache:clear` is not enough when `APP_SHARE_DIR` is set, as the Symfony 7.4 and 8 skeletons do
(`var/share`): the filesystem `cache.app`, and this pool with it, then lives in that directory,
which `cache:clear` leaves alone. Changing the adapter or the lifetime: see the
[configuration reference](../configuration-reference.md#notes). To parse the files in every new process instead, set `translation_files_cache: false`.

## What the bundle does not touch

The HTTP cache, Varnish, or any application-side Twig fragment cache: an overridden label on an
HTTP-cached page will only show up when that page expires; that is the host's responsibility.
