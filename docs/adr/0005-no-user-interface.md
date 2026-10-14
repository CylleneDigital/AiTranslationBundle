# 0005. The bundle has no user interface, and assumes none

- Status: accepted
- Date: 2026-10-14

## Context

The bundle is consumed by platforms that each have their own back office, permissions and
design: Sylius, a plain Symfony administration, a headless setup. A screen shipped by the bundle
would fit none of them, and a documentation written around one would mislead the others.

## Decision

The bundle is an engine. It ships no controller, route, template or asset, and neither its code
nor its documentation assumes a screen: they describe a contract - what a method returns, what
an event carries - never a button, a badge or a page. Its console commands are the only way a
person drives it without an integration. A platform integration is a separate package built on
the public contract.

## Consequences

- An integration builds its screens on the facades, the events and the services the public
  contract lists; what it needs and cannot find there is a request to the bundle, not a
  workaround in the integration.
- A change described in terms of a screen is rewritten in terms of the contract before it lands
  here.
- Anything specific to a platform (Sylius channels, its admin, its permissions) belongs in the
  integration package.

## Related

- [0020. The public contract is one page, method by method](0020-public-contract-is-one-page.md)
