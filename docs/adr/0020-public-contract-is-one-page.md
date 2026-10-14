# 0020. The public contract is one page, method by method

- Status: accepted
- Date: 2026-10-14

## Context

PHP's `public` says what can be called, not what is promised. An integration needs to know which
classes and methods it may rely on across minor versions, and the maintainers need to know what
they may change. Marking the internals with `@internal` scatters that answer over every file, and
says nothing about what is promised.

## Decision

`docs/public-contract.md` is the only list of what semantic versioning covers: the configuration,
the transport, the entities and their mapping, the interfaces, the events and exceptions, the
value objects, and a whitelist of services, method by method.

- A listed method is its whole signature: name, parameters, their order and defaults, return
  type, documented exceptions.
- A service constructor is not covered; a value object's is.
- Whatever the page does not name is internal, without an `@internal` in the code.
- A test checks every class and member the page's tables name against the code.

## Consequences

- Renaming, removing or re-typing a listed symbol is a major version, with an entry in
  `UPGRADE.md`.
- Making something stable is adding it to the page; an integration that needs an internal opens
  an issue rather than calling it.
- A rename goes red in the suite until the page follows.
- The decision records explain choices, some of them about internals: they promise nothing.

## Related

- [0005. The bundle has no user interface, and assumes none](0005-no-user-interface.md)
- [0021. The entity mapping is public surface](0021-entity-mapping-is-public-surface.md)
- [0022. No `countPendingForKey()`](0022-no-count-pending-for-key.md)
