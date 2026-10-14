# 0018. Keys, catalogues, locales and scopes are compared byte for byte

- Status: accepted
- Date: 2026-10-14

## Context

Symfony, SQLite and PostgreSQL compare translation keys byte for byte. The usual MySQL and
MariaDB collations ignore case, accents and trailing spaces: `Save` and `save` would share one
override row, and saving the second would overwrite the first. Even `utf8mb4_bin` is not enough,
being a PAD SPACE collation for which `Save` and `Save ` are equal. Catalogue identifiers are
file paths, case-sensitive too, and scopes are opaque codes the host defines: `FR` and `fr` are
two scopes. A collation on the mapping would be rendered on every platform, and means nothing to
PostgreSQL or SQLite.

## Decision

`BinaryKeyCollationListener` sets a NO PAD binary collation on the generated schema, on MySQL
and MariaDB only, for every column compared byte for byte: the key, the catalogue, the locale and
the scope. The mapping carries no collation.

## Consequences

- `Save`, `save` and `Save ` are three overrides, `FR` and `fr` two scopes, on every platform.
- `schema:update` and `migrations:diff` pick the collation up like any other column change.
- A new column compared byte for byte has to be added to the listener's list.
- The listener follows the schema API of each supported DBAL version, which differs.

## Related

- [0021. The entity mapping is public surface](0021-entity-mapping-is-public-surface.md)
