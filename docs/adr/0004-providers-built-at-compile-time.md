# 0004. Providers are built at compile time, one named service per configuration entry

- Status: accepted
- Date: 2026-10-14

## Context

Several translation backends coexist (an LLM for contextual quality, DeepL for volume, a local
model for offline use), and one type can be needed several times: two accounts of the same
provider, or two providers speaking the same API. They must be nameable, selectable at runtime,
and auditable: the name is stamped on every suggestion.

## Decision

Each entry under `providers` becomes, when the container is compiled, a service of its own,
named after the entry, with its class picked from its `type` and its arguments set explicitly
(the Symfony Mailer pattern). Every instance carries the provider tag, and a registry resolves
them by name. A provider the bundle has no bridge for implements the provider interface and
joins the same registry through the tag.

## Alternatives rejected

- **One service per bridge, selected by alias**: one instance per type, and the host writes
  service definitions for the most common case ("this provider, with this key").
- **A runtime factory** building the provider on each call: configuration errors move to
  runtime, and the HTTP clients are rebuilt on every use.
- **A DSN** (`translation://deepl?key=...`): compact but opaque, where the configuration tree
  documents and validates every option, and `config:dump-reference` shows it.

## Consequences

- A provider is added in YAML, without a service definition, as many times as needed.
- A configuration error - a missing key or model, an option of another type, an unknown type, a
  default provider that does not exist - fails the container build, not the first request. A
  default provider naming a custom provider is the exception: that name is only known at
  runtime, where the registry checks it.
- Environment variables stay placeholders until the call: a missing key only breaks the provider
  that needs it.
- A new bridge type needs a release of the bundle; the tag covers the wait.
- Two providers with the same name - a bridge and a custom one - are refused when the registry is
  built: a programming error, not a runtime one.

## Related

- [0005. The bundle has no user interface, and assumes none](0005-no-user-interface.md)
