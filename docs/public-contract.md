# Public contract

What [semantic versioning](https://semver.org/) covers in this bundle: everything on this page
only breaks in a major version, with an entry in [`UPGRADE.md`](../UPGRADE.md).

A class or a method that is not listed here is **internal**, even when PHP declares it
`public`. Nothing hides it and calling it works; it simply carries no promise, and may change
in a minor version. If your integration needs one, open an issue: listing it here is how it
becomes stable.

Three rules apply to everything below:

- **A listed method is its whole signature**: its name, its parameters, their order and
  defaults, its return type and the exceptions it documents. Not only the service that carries
  it.
- **The constructor of a service is not part of the contract.** A service gains a dependency
  in a minor version: take it from the container, never build it with `new`. The constructor
  of a value object, of an event or of the Messenger message is part of it.
- **Adding is not breaking**: a new method on a service, a new optional parameter, a new
  property on a value object, a new configuration key with a default.

`tests/Unit/Docs/PublicContractDocsTest.php` checks every class and member the tables of this
page name against the code: a rename goes red until the page, and `UPGRADE.md`, follow.

## Configuration and container

- **The configuration tree** under `cyllene_digital_ai_translation`: every key of the
  [configuration reference](configuration-reference.md), its type and its default.
- **The Messenger transport `cyllene_ai_translation`**, and the routing of
  `GenerateSuggestionsMessage` to it: what a host's `messenger:consume` and an integration's
  worker configuration name.
- **The container parameter `cyllene_digital_ai_translation.default_provider`**: the name of
  the provider a run uses when none is given. It is the `default_provider` key, else the first
  provider declared under `providers`, else `null` (no bridge declared, custom providers only).
- **The `cyllene_digital_ai_translation.provider` tag**, autoconfigured from
  `TranslationAiProviderInterface`: how a [custom provider](provider_bridge/custom.md) becomes
  selectable by name.
- **The service identifiers**: each service listed below is registered under its class name,
  and each interface is an alias a host can point at its own implementation or decorate.
- **The console commands**: the names, options and exit codes documented in
  [CLI commands](operations/commands.md). The wording of the output and the prompts of the
  interactive console are not.

## Entities

The mapping is part of the contract as much as the classes: a column, an index or a constraint
that changes forces every host to generate a migration.

| Class | Covered |
|---|---|
| `Entity\TranslationOverride` | the mapping, every public method, `MAX_KEY_LENGTH` |
| `Entity\TranslationSuggestion` | the mapping, every public method |
| `Entity\GenerationLog` | the mapping, every public method |
| `Entity\ScopeParameters` | the mapping, every public method |
| `Entity\SuggestionStatus` | `Pending`, `Approved`, `Rejected` |

The `metadata` of a suggestion is free-form, the provider's own keys included, with two
exceptions: an approved suggestion carries `override_change`, one of the `OverrideChange`
values (`write`, `revert`, `none`), and `reverted_to` for a revert; an errored suggestion whose
batch is with the provider, or was when its run was killed, carries `in_flight`, the time the
batch was reserved.

## Interfaces

Every method and constant of the interfaces under `Provider/`, `Catalogue/`, `Override/`,
`Scope/`, `Suggestion/` and `Exception/`:

| Interface | Role |
|---|---|
| `Provider\TranslationAiProviderInterface` | a provider, with its `TAG` |
| `Provider\ContextAwareProviderInterface` | a provider that takes the prompt context |
| `Provider\CostEstimatingProviderInterface` | a provider that prices a run before it starts |
| `Catalogue\LocaleProviderInterface` | where the available locales come from |
| `Override\AuthorProviderInterface` | who an override is attributed to |
| `Override\TranslationManagerInterface` | the catalogues and the overrides behind one service |
| `Scope\ScopeProviderInterface` | the scopes of the host |
| `Scope\ScopeLocalesProviderInterface` | the locales each scope exposes, optional, on the same service |
| `Suggestion\TranslationAiServiceInterface` | generation and review behind one service, with `MAX_KEYS_PER_RUN` |
| `Exception\ExceptionInterface` | the marker of every exception a host is expected to handle |

Two array shapes these interfaces return are part of it:

- `TranslationManagerInterface::getTranslationsForCatalogue()`: one entry per key, with
  `original`, `override`, `hasOverride`, `inherited` and `inheritedFrom` (`locale`, `scope`,
  or `null`). `OverrideReader::getTranslationsForCatalogues()` (a service, below) returns
  those entries for each catalogue asked for, indexed by catalogue, an empty one included.
- `PlaceholderConsistencyChecker::explain()` (a service, below): `missing` and `unexpected`.

## Events and exceptions

- **The events** under `Event/` (`OverrideSavedEvent`, `OverrideRemovedEvent`,
  `SuggestionApprovedEvent`, `SuggestionRejectedEvent`): their class, their public members,
  and the moment they are dispatched, as the concept pages describe it.
- **The exceptions** implementing `Exception\ExceptionInterface`: their class, the SPL
  exception they extend and their public members. Their message is not.

## Value objects and enums

What the services and interfaces hand over, constructors included:

| Class | Members |
|---|---|
| `Catalogue\TranslationCatalogue` | `$identifier`, `$category`, `$domain`, `$type`, `fromIdentifier()`, `getDomainLabel()` |
| `Message\GenerateSuggestionsMessage` | `$catalogue`, `$targetLocale`, `$sourceLocale`, `$provider`, `$onlyMissing`, `$scope`, `$retryErrors`, `$dispatchedAt` |
| `Override\OverrideChange` | `Write`, `Revert`, `None`, `decide()` |
| `Provider\TranslationResult` | `$translation`, `$confidence`, `$metadata` |
| `Provider\ProviderRunEstimate` | `$inputTokens`, `$outputTokens`, `$cost`, `$currency`, `$quotaRemaining`, `$quotaLimit`, `$lowerBound` |
| `Suggestion\GenerationOutcome` | `$completed`, `$created` |
| `Scope\ScopeData` | `$overrides`, `$suggestions`, `$parameters`, `isEmpty()` |
| `Suggestion\PluralCategories` | `missingIn()` |
| `Coverage\CoverageReport` | `$defaultLocale`, `$locales`, `getLocale()` |
| `Coverage\LocaleCoverage` | `$locale`, `$translated`, `$missing`, `$pendingSuggestions`, `$missingKeys`, `getTotal()`, `getPercent()`, `isBelow()`, `isComplete()` |
| `Estimation\GenerationEstimate` | `$provider`, `$keys`, `$characters`, `$chunks`, `$keysBeyondCap`, `$inputTokens`, `$outputTokens`, `$cost`, `$currency`, `$costIsLowerBound`, `$quotaRemaining`, `$quotaLimit`, `isPriced()`, `isCapped()` |
| `Transfer\OverrideImportPreview` | `$importable`, `$skipped`, `$unchanged`, `$reverted`, `$byLocale`, `$issues` |
| `Transfer\OverrideImportResult` | `$imported`, `$skipped`, `$unchanged`, `$reverted`, `$issues` |
| `Transfer\OverrideImportIssue` | `$where`, `$key`, `$reason`, `summarize()` |
| `Transfer\OverrideImportTemplates` | `get()` |

`GenerateSuggestionsMessage` is serialized into the transport: a message queued before a
deployment is consumed after it, so its properties keep their name and their meaning within a
major version.

## Services

The concrete services an integration may inject, method by method. Prefer the two facades
(`TranslationManagerInterface`, `TranslationAiServiceInterface`) wherever they offer the same
thing: an interface can be decorated, these classes are `final`.

| Service | Methods and constants |
|---|---|
| `Override\OverrideReader` | `getBaselineValue()`, `getInheritedValue()`, `getFileValue()`, `getTranslationsForCatalogues()` |
| `Override\TranslationValueValidator` | `validate()` |
| `Repository\TranslationSuggestionRepository` | `find()`, `findBy()`, `countPendingFiltered()`, `createPendingQueryBuilder()` |
| `Transfer\OverrideExporter` | `supports()`, `FORMATS`, `FORMAT_CSV`, `FORMAT_XLIFF` |
| `Transfer\OverrideImporter` | `guessFormat()`, `preview()`, `import()` |
| `Transfer\ViewExporter` | `export()` |
| `Coverage\CoverageCalculator` | `getDefaultLocale()`, `getReport()` |
| `Suggestion\PlaceholderConsistencyChecker` | `isConsistent()`, `explain()` |
| `Provider\TranslationProviderRegistry` | `getNames()`, `has()` |
| `Estimation\GenerationCostEstimator` | `estimate()` |
| `Scope\ScopeParametersManager` | `getPromptContext()`, `setPromptContext()`, `remove()` |
| `Scope\ScopePurger` | `purge()`, `findStoredScopes()` |

`createPendingQueryBuilder()` returns an open query builder for a caller to filter, sort and
paginate: its root alias `o` and the field names of `TranslationSuggestion` are what such a
caller relies on.

## What a generation run guarantees

A generation calls a paid API. These behaviours keep a run from billing the same keys twice,
and only change in a major version:

- **One run at a time per (catalogue, target locale, scope).** A second run started while one
  holds the lock is skipped: `GenerationOutcome::$completed` is `false`, and nothing is sent.
  The lock goes through `symfony/lock`, held where the host's lock store is: a store local to
  one machine does not guard workers on several machines
  ([Running on several servers](operations/multi-server.md)). When the store cannot be
  reached, the run proceeds unguarded and a warning is logged.
- **A run for a scope the host does not declare is refused** before the provider is called
  (`InvalidOverrideException`), when the host declares scopes: a message queued for a scope
  deleted since bills nothing.
- **A key that has a pending suggestion is not sent**, an errored one included, unless the run
  retries errors.
- **The keys of a batch are reserved before the provider is called**: stored as errored
  suggestions carrying `metadata.in_flight`. A run killed during the call leaves them so, and
  the run that starts again (a redelivered message, a relaunched command) does not send them.
- **A run that retries errors** sends the keys of the errored suggestions, except those
  reserved after `GenerateSuggestionsMessage::$dispatchedAt`: the ones its own run reserved
  before it was interrupted.
- **In `onlyMissing` mode**, a key given a value since the run started is left out of the
  batches that follow, neither reserved nor sent. The check runs before each batch: a value
  written while the key's own batch is with the provider comes too late to save that call,
  and the reply for that key is then discarded: the value written closes its suggestion (last
  point).
- **A failed run is never retried by Messenger**: the handler marks every failure
  unrecoverable, whatever the transport, and the bundle's transport retries nothing. The
  bundle's bridges retry an HTTP request only on a response saying the provider did not
  process it; a custom provider uses its own client, which the bundle promises nothing about.
- **A suggestion reviewed while its batch is with the provider** keeps that decision: neither
  the reply nor a failure writes on it.

Two concurrent runs a lock store does not guard can both call the provider. The database still
holds one pending suggestion per key: the run that reserves a key the other holds fails before
paying for that batch, and has no journal entry, its entity manager being closed by then
([Running on several servers](operations/multi-server.md)).

## What is not covered

- The classes under `Bridge/`, `Command/`, `DependencyInjection/` and `Schema/`, and every
  class or method this page does not name.
- The prompt sent to a provider and the parsing of its answer: they change with the models.
- The compiled container beyond the identifiers above: private services, their arguments,
  the order of the compiler passes.
- `tests/`: the kernels and helpers of the suite are not shipped in the package.

The [decision records](adr/README.md) explain choices, some of them about internals: a record
promises nothing, only this page says what is stable.
