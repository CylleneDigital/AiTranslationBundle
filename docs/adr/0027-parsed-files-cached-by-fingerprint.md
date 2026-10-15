# 0027. Parsed translation files are cached by their fingerprint, never the walk of the directories

- Status: accepted
- Date: 2026-10-15

## Context

The bundle parses the host's translation files itself, since the translator's compiled
catalogues mix in messages it does not expose. A view over every catalogue parses every file,
in every new process: under PHP-FPM, on every request. Files change without any event the
bundle could listen to: a deployment, an edit in development, a theme package updated.

## Decision

Each parsed file is kept in a cache pool, under a key made of its path, a hash of its content
and a format version. Nothing invalidates an entry: a file that changes gets a new key, and the pool's lifetime removes what is left behind. The walk of the
directories is not cached, so a file added or removed is seen at once. A file that cannot be
read is reported without being cached: making it readable changes nothing in its key. The cache is on by
default, the pool is the bundle's own, built on `cache.app`, and the scanner's in-memory memo
is reset between two Messenger messages.

## Alternatives rejected

- **A cache emptied at deployment**: no file access at all, but a file edited in place is never
  seen, which is the usual case in development.
- **Symfony's `ConfigCache`**, as the translator does: resources are only checked in debug, so
  production has the same blind spot, for more code written into the cache directory.
- **The date of modification and the size**: no read of the file, but a file rewritten within
  the same second at the same size keeps the previous entry, and so does a release deployed
  under the same path, with the dates a build froze, on a pool shared between releases.
  Reading and hashing a file costs a fraction of reading its cache entry, and far less than
  parsing it.

## Consequences

- A request reads and hashes every file of the catalogues it reads instead of parsing them.
- A shared pool needs nothing more, on several servers or between releases: an entry only
  matches the content it was parsed from.
- What the scanner stores changes only with a new format version in the key. The version of
  the loaders is not part of it: a parsing change in a new release of the translation
  component is served once its entries expire, or the pool is emptied.

## Related

- [0001. Overrides are a database delta, the translation files are never written](0001-overrides-are-a-database-delta.md)
