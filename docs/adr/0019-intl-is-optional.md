# 0019. `intl` is optional

- Status: accepted
- Date: 2026-10-14

## Context

ICU message formatting and the validation of an ICU pattern need the `intl` extension. Many
hosts have it, some do not - a minimal image, a shared hosting - and most of the bundle does not
need it: storing, serving and reviewing overrides.

## Decision

`intl` is suggested, not required. Everything that needs it is guarded: without it, an ICU
override is served as stored, and an invalid ICU pattern is not caught at save time.

## Consequences

- The bundle installs and runs on a PHP without `intl`, with a documented loss.
- A new use of ICU formatting or of `\Locale` is guarded the same way.

## Related

- [0002. `translator` is decorated at priority `-10`](0002-translator-decorated-at-priority-minus-10.md)
