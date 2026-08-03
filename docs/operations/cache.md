# Caches

Two layers sit between a stored override and the text on screen. Both are invalidated
**automatically** on every save/remove; this page is mostly here to explain what happens and
to know when to step in manually.

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

## What the bundle does not touch

The HTTP cache, Varnish, or any application-side Twig fragment cache: an overridden label on an
HTTP-cached page will only show up when that page expires; that is the host's responsibility.
