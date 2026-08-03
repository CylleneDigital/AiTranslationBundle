# Doctrine DBAL 3: enable savepoints

The bundle supports DBAL 3 and 4. On **DBAL 3**, enable savepoints (the default of the
DoctrineBundle recipe, and what DBAL 4 always does):

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        use_savepoints: true
```

## Why

The bundle writes in **transactions of its own** (`TranslationSuggestionRepository::transactional()`):
approving or rejecting a suggestion locks its row and writes in one, so a suggestion
reviewed meanwhile (a double submission, a second console session) is never approved
twice; writing an override locks the pending suggestions it closes in another. Every ORM
flush opens a transaction too, which then **nests** inside the bundle's, and the writer's
transaction nests inside a review's, or inside the host's own when the host writes from
one.

DBAL 4 always nests with a savepoint. DBAL 3 does so only with `use_savepoints: true`;
otherwise it emulates the nesting and triggers:

```
Nesting transactions without enabling savepoints is deprecated.
Call Doctrine\DBAL\Connection::setNestTransactionsWithSavepoints(true) to enable savepoints.
```

## What it changes

- **Behaviour**: unchanged for the bundle's own transactions; the locks, the writes and
  the rollback on a refusal work the same with or without savepoints. Inside a transaction
  of yours, they do not: without savepoints, a refusal you catch (a suggestion already
  reviewed, an override the tables cannot hold) rolls back a nested level, which marks
  your whole transaction rollback-only; its commit then fails. With savepoints, only the
  bundle's part is rolled back.
- **The logs**: `doctrine/deprecations` reports a deprecation once per PHP process (a
  request, a command, a worker), and only when Doctrine deprecations are enabled
  (`DOCTRINE_DEPRECATIONS=trigger` or `track`, or `Deprecation::enableWith…()`). In debug,
  DoctrineBundle then logs it as a PHP deprecation (`deprecation.INFO` in
  `var/log/dev.log`): one entry per request or command that reviews a suggestion or writes
  an override.
- **The tests**: a test suite failing on deprecations fails once per run, on the first test
  that reviews a suggestion or writes an override, unless deduplication is disabled
  (`Deprecation::withoutDeduplication()`), then on each of them.

## Why the bundle does not set it itself

- The nesting is inherent to "lock the row, then flush": Doctrine's own
  `EntityManager::wrapInTransaction()` nests the same way.
- Switching savepoints on for the bundle's transaction only does not work: restoring the
  host's value calls `setNestTransactionsWithSavepoints(false)`, which raises this very
  deprecation, and DBAL 4 deprecates both methods and refuses `false`.
- Prepending `use_savepoints` from the bundle would silently change how **every**
  transaction of the host application nests: that is the host's decision.

## See also

- [AI suggestions: Statuses](../concepts/suggestions.md#statuses): the approval transaction
