# 0010. Generation runs on the bundle's own `cyllene_ai_translation` transport

- Status: accepted
- Date: 2026-10-14

## Context

A generation message can take minutes and calls a paid API. Routed to the host's usual async
transport, it would share that transport's retry strategy and its workers with messages of a
completely different nature. The transport's name is also what a host's worker command and its
process manager configuration name.

## Decision

The bundle declares a transport of its own, `cyllene_ai_translation`, with no retry, and routes
the generation message to it. Its DSN is configurable; the default one is only declared when
`symfony/doctrine-messenger` is installed, otherwise the message is handled in-process. The name
is kept as it is, although it does not carry the bundle's `cyllene_digital_ai_translation`
prefix.

## Consequences

- Generation workers are started, scaled and monitored separately from the host's.
- The retry strategy of the bundle's messages does not depend on the host's transports
  ([0006](0006-no-retry-that-can-bill-twice.md)).
- The transport's name is public contract: renaming it would break every deployment's worker
  command for a naming consistency nobody needs.
- Without `symfony/doctrine-messenger` and with the default DSN, a generation runs synchronously,
  inside the request or command that dispatched it.

## Related

- [0006. No retry that can bill the same keys twice](0006-no-retry-that-can-bill-twice.md)
- [0020. The public contract is one page, method by method](0020-public-contract-is-one-page.md)
