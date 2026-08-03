# Scopes: one translation per site, brand or channel

A **scope** is an optional dimension on every override: an opaque code (`FASHION_WEB`,
`B2B`…) that the host maps to its own concept: a site, a brand, a sales channel, a
tenant. The engine never interprets it; it only guarantees the shadowing order:

```
scoped override  ➜  global override  ➜  file value
```

combined with the language chain (an override saved on `fr` answers for `fr_FR`): the more
specific locale wins first, then, within a locale, the scoped row before the global one;
for a `fr_FR` visitor of scope `b2b`: `fr_FR`/`b2b`, then `fr_FR`/global, then `fr`/`b2b`,
then `fr`/global, then the file.

`''` (the empty scope) is the **global** level, the one every command and API call uses
when no scope is given.

## Where scopes come from

The host declares them through `CylleneDigital\AiTranslationBundle\Scope\ScopeProviderInterface`:

- `getAvailableScopes()`: the `code => label` map the pickers offer (CLI wizards, back
  office). Empty (the default `NullScopeProvider`) hides the scope dimension entirely;
- `getScope()`: the scope of the **current runtime context** (the visited site, the
  active channel), or `null` when none is resolvable.

An integration package re-aliases the interface to its own adapter: a Sylius integration
maps scopes to sales channels, for instance.

## At runtime

When `getScope()` resolves, the decorated translator looks the key up in that scope
first, then falls back to the global override, then to the file. Where **no scope is
resolvable** (CLI, Messenger workers, back-office requests, an email rendered asynchronously),
the global overrides apply. Design consequence: give a value a global baseline before
scoping it, so the contexts without a scope never regress to the raw file value
unexpectedly.

## In every workflow

The scope threads through the whole editorial chain: as the `--scope` option (`-s`) on
the commands that take it, as a question in the interactive console, and as a field in
the import files:

- **[single overrides](manual-override.md)**: a scoped save shadows the global value
  for that scope only; the CLI wizard offers a scope picker when the host declares any;
- **[AI suggestions](suggestions.md)**: generating for a scope reads that scope's
  *effective* values (scoped, else inherited, i.e. the global override or the parent language's,
  else file) as source material, and approving
  writes scoped overrides. A scope can also carry its own **prompt context**
  (`ScopeParameters`) appended to the LLM instructions: per-brand tone or vocabulary;
- **coverage**: `--scope=X` measures the figures *as that scope sees them*: its own
  overrides on top of the inherited global ones;
- **exports / imports**: the scope travels with each entry (XLIFF `category`
  attribute, CSV `scope` column), so a backup restores every scope faithfully.

Configuration side: nothing to configure in the bundle itself; the whole dimension is
driven by the host's provider. See the
[configuration reference](../configuration-reference.md) § Scopes for the runtime notes.
