# 0001. Overrides are a database delta, the translation files are never written

- Status: accepted
- Date: 2026-10-14

## Context

The translation files belong to the host project and to the packages it installs: a package
update or a deployment rewrites them. A label fix should not need a deployment, and should not
be lost at the next `composer update`. Symfony compiles the files into catalogues in its own
cache.

## Decision

An override is a row in the database, applied over the compiled catalogues at runtime. The
bundle never writes a translation file, and never clears Symfony's translation cache.

## Alternatives rejected

- **Editing the files**: the customisation is lost on every update of the package that owns the
  file, a vendor file cannot be edited at all, and a label change needs a deployment.
- **Recompiling the catalogues with the overrides baked in**: see
  [0002](0002-translator-decorated-at-priority-minus-10.md).

## Consequences

- Upgrading the application or its dependencies never loses an override.
- Removing an override gives back what the entry inherits - the global override in a scope, the
  parent language's for a regional locale - else the file value.
- The compiled catalogues hold the file values only: whatever reads them directly does not see
  the overrides ([0002](0002-translator-decorated-at-priority-minus-10.md)).
- The cache the bundle invalidates is its own, never the framework's.
- An override can outlive its key or its file: drift and orphans are reported, and purged on
  demand, never removed silently.
- Moving overrides back into real files is an export, done by a person, not a write by the
  bundle.

## Related

- [0002. `translator` is decorated at priority `-10`](0002-translator-decorated-at-priority-minus-10.md)
- [0011. No suggestion is applied without a human decision](0011-no-suggestion-applied-without-a-human.md)
