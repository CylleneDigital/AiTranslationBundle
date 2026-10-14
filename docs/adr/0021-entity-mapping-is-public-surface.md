# 0021. The entity mapping is public surface

- Status: accepted
- Date: 2026-10-14

## Context

The bundle ships an entity mapping, not migrations: each host generates its own migration from
the mapping, against its own database. A column, an index or a constraint that changes in the
bundle is a migration every host has to generate, review and run.

## Decision

The mapping is part of the public contract as much as the classes. A change to a column, an
index or a constraint is a major version, with an entry in `UPGRADE.md`.

## Consequences

- A minor or patch version never makes `doctrine:schema:validate` fail on a host.
- A pull request touching the mapping says so, and is proven on MySQL, MariaDB and PostgreSQL:
  SQLite proves neither a unique index on a nullable column, nor a key length, nor a collation.
- What the schema listener adds on MySQL and MariaDB ([0018](0018-byte-for-byte-comparison.md))
  is held to the same rule.

## Related

- [0018. Keys, catalogues, locales and scopes are compared byte for byte](0018-byte-for-byte-comparison.md)
- [0020. The public contract is one page, method by method](0020-public-contract-is-one-page.md)
