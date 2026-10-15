# 0027. Parsed translation files are cached by their fingerprint, never the walk of the directories

- Status: accepted
- Date: 2026-10-15

## Context

The bundle parses the host's translation files itself, since the translator's compiled
catalogues mix in messages it does not expose. A view over every catalogue parses every file,
in every new process: under PHP-FPM, on every request. Files change without any event the
bundle could listen to: a deployment, an edit in development, a theme package updated.

## Decision

Each parsed file is kept in a cache pool, under a key made of its path, its date of
modification, its size and a format version. Nothing invalidates an entry: a file that changes
gets a new key, and the pool's lifetime removes what is left behind. The walk of the
directories is not cached, so a file added or removed is seen at once. The cache is on by
default, the pool is the bundle's own, built on `cache.app`, and the scanner's in-memory memo
is reset between two Messenger messages.

## Alternatives rejected

- **A cache emptied at deployment**: no file access at all, but a file edited in place is never
  seen, which is the usual case in development.
- **Symfony's `ConfigCache`**, as the translator does: resources are only checked in debug, so
  production has the same blind spot, for more code written into the cache directory.
- **The date of modification alone**: two writes within the same second would keep the first
  one; the size tells most of them apart.

## Consequences

- A request stats every file of the catalogues it reads instead of parsing them.
- A file rewritten within the same second with the same size keeps the previous entry, which
  the documentation says, with the command that empties the pool.
- A shared pool needs nothing more on several servers: an entry only matches the file it was
  written for.
- What the scanner stores changes only with a new format version in the key.

## Related

- [0001. Overrides are a database delta, the translation files are never written](0001-overrides-are-a-database-delta.md)
