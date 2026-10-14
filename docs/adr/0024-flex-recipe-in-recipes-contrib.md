# 0024. The Flex recipe lives in `symfony/recipes-contrib`

- Status: accepted
- Date: 2026-10-14

## Context

Symfony Flex installs the recipes of third-party packages from the `symfony/recipes-contrib`
repository. A recipe kept in the package's own repository is not read by Flex, unless every host
declares a private recipe endpoint.

## Decision

The recipe is maintained in `symfony/recipes-contrib`, under the package's name and version. This
repository holds none.

## Consequences

- `composer require` registers the bundle and writes its configuration, once the host accepts
  contrib recipes.
- A change to the configuration tree, to the bundle class or to the installation steps needs a
  pull request there too, and a breaking one a new recipe version folder.
- Those pull requests follow that repository's review, on its maintainers' schedule: a release
  of the bundle does not wait for its recipe, and the README gives the installation steps
  without Flex too.

## Related

- [0025. Release notes are GitHub Releases](0025-release-notes-are-github-releases.md)
