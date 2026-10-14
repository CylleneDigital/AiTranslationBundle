# 0026. PHPStan runs on the highest dependencies only

- Status: accepted
- Date: 2026-10-14

## Context

The bundle supports a range of each dependency, from the committed floors to the latest
versions, and CI runs the suite at both ends. Some code bridges two library versions whose APIs
differ - the schema listener uses DBAL's in-place setters below 4.5 and its schema editors from
4.5 - and such code cannot type-check against both.

## Decision

PHPStan, at level max, runs on the highest dependencies only. The lowest ones are covered by the
suite.

## Consequences

- A type error that only exists with the lowest dependencies is caught by the tests, not by the
  analysis.
- The bridging code needs no baseline entry nor ignore rule per version.
- A new PHPStan release can turn the highest job red without a change in the bundle; that is
  where it is fixed.

## Related

- [0018. Keys, catalogues, locales and scopes are compared byte for byte](0018-byte-for-byte-comparison.md)
