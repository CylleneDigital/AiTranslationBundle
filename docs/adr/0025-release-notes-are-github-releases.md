# 0025. Release notes are GitHub Releases, there is no changelog file

- Status: accepted
- Date: 2026-10-14

## Context

A changelog file duplicates what the pull requests and the release tags already say, and drifts
from them. The sibling packages of the same organisation publish their notes as GitHub Releases.

## Decision

There is no `CHANGELOG.md`. The notes of a version are its GitHub Release, built from the pull
requests it contains. `UPGRADE.md` lists the breaking changes per major version and what to do
about them.

## Consequences

- A pull request describes its change for the reader of the release notes: its description is
  what they are made of.
- A breaking change still needs its `UPGRADE.md` entry: the release notes say what changed, the
  upgrade guide what to do.
- The notes are not in the distributed package; they are on the repository's releases page.

## Related

- [0020. The public contract is one page, method by method](0020-public-contract-is-one-page.md)
