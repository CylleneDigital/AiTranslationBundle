# 0026. A new capability asked of the host is a separate optional interface

- Status: accepted
- Date: 2026-10-14

## Context

The interfaces of the public contract are aliases a host points at its own service: an
integration implements the scope provider, the locale provider, the author provider, and may
decorate the two facades. A method added to one of them breaks every implementation at the
next update, which semantic versioning only allows in a major version. The bundle still needs
to ask hosts for more: which locales a scope exposes, for instance.

## Decision

A new capability asked of the host is a separate interface, implemented by the same service
when the host wants it, and checked with `instanceof`. A host that does not implement it keeps
the behaviour it had. A new method the bundle offers, rather than asks for, goes on a `final`
service of the contract, where adding is not breaking.

## Consequences

- The capability ships in a minor version, and hosts adopt it when they need it.
- The bundle keeps the code path of a host without it, and tests both.
- The interfaces of the contract only grow in a major version.

## Related

- [0020. The public contract is one page, method by method](0020-public-contract-is-one-page.md)
