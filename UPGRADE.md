# Upgrading

Breaking changes per major version, and what to do about them. A minor or patch version asks
nothing of the host: its [release notes](https://github.com/CylleneDigital/AiTranslationBundle/releases)
say what changed.

## From 1.x to 2.0

No configuration, mapping or migration change: the Flex recipe and the schema stay as they are.

### Before deploying

- **Drain the `cyllene_ai_translation` transport** (`messenger:consume cyllene_ai_translation`
  until it is empty, or `messenger:stats`). A `GenerateSuggestionsMessage` serialized by 1.0
  has no `$dispatchedAt`: 1.x ran it all the same, 2.0 fails on it.

### `TranslationManagerInterface`

A host that only calls the interface has nothing to change but the points below. A host that
**implements or decorates** it has to follow every one, or its class no longer compiles.

- **`removeOverride()` returns `bool`**, `void` before: whether this call deleted the
  override, `false` when there was none, a concurrent removal included. A decorator returns
  what it delegates to. Inside a transaction of the host, the deletion is final only once the
  host commits.
- **Three methods are added**, each delegating to `OverrideReader`: `getTranslationsForCatalogues()`,
  `getInheritedValue()` and `getBaselineValue()`. A decorator now sees the batch read of
  several catalogues and the fallback values: call them on the interface rather than on
  `OverrideReader` to go through it.
- **`countOverridesByLocale()` is removed**: it counted the overrides of every scope at once.
  Use `countOverridesPerLocale($scope)[$locale] ?? 0` for one scope.

### `TranslationAiServiceInterface`

- **`rejectMany()` returns the suggestions it skipped**, `void` before: the ones no longer
  pending in the database, reviewed meanwhile. A caller reporting how many were rejected
  subtracts them; an implementation or a decorator returns them.

### `ScopeParametersManager`

- **`remove()` is removed.** When a scope disappears, `ScopePurger::purge()` deletes its
  parameters along with its overrides and suggestions.
