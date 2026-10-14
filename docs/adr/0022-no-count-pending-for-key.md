# 0022. No `countPendingForKey()`: `createPendingQueryBuilder()` is the extension point

- Status: accepted
- Date: 2026-10-14

## Context

An integration asked for a method counting the pending suggestions of one key, to show it beside
the key. The repository already exposes `createPendingQueryBuilder()`, an open query builder on
the pending suggestions that a caller filters, sorts and paginates.

## Decision

No dedicated counting method is added. The query builder is the documented extension point: its
root alias `o` and the field names of `TranslationSuggestion` are part of the public contract.

## Consequences

- A count per key, per locale or per scope is a few lines on the integration's side, without a
  release of the bundle.
- The repository's public surface does not grow with every view an integration builds.
- The alias and the field names cannot change outside a major version.

## Related

- [0020. The public contract is one page, method by method](0020-public-contract-is-one-page.md)
