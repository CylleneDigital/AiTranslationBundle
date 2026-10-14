# Architecture decision records

One file per decision: its context, what was decided, what follows from it. A decision is not
edited once accepted: a new record supersedes it, and the old one says so in its status.

A record holds the decision and its reasons, which do not move with the code. What does - file
paths, figures, the full rule - is in the concept and operations pages ([index](../index.md)),
the [public contract](../public-contract.md) and `CLAUDE.md`, which follow the code: a record that
quoted them would be wrong at the next refactoring, and could not be corrected. A class or method
name is fine: a rename supersedes nothing.

The first records were written together, after the decisions they record: their date is the
date they were written, not the date the decision was taken.

A record explains a choice, it promises nothing: only the [public contract](../public-contract.md)
says what is stable.

A new record takes the next number, the template of the others (Status, Date, Context, Decision,
Alternatives rejected when there were some, Consequences, Related) and a line in the table below.

| # | Decision |
|---|---|
| 0001 | [Overrides are a database delta, the translation files are never written](0001-overrides-are-a-database-delta.md) |
| 0002 | [`translator` is decorated at priority `-10`, every interface of the decorated service re-exposed](0002-translator-decorated-at-priority-minus-10.md) |
| 0003 | [Missing keys are computed within one language only](0003-missing-keys-within-one-language.md) |
| 0004 | [Providers are built at compile time, one named service per configuration entry](0004-providers-built-at-compile-time.md) |
| 0005 | [The bundle has no user interface, and assumes none](0005-no-user-interface.md) |
| 0006 | [No retry that can bill the same keys twice](0006-no-retry-that-can-bill-twice.md) |
| 0007 | [What was paid for is committed chunk by chunk, never inside a caller's transaction](0007-paid-results-committed-chunk-by-chunk.md) |
| 0008 | [One generation at a time per catalogue, locale and scope, under a lock that degrades](0008-one-generation-at-a-time.md) |
| 0009 | [Every run is journaled, a run with nothing to translate included](0009-every-run-is-journaled.md) |
| 0010 | [Generation runs on the bundle's own `cyllene_ai_translation` transport](0010-own-messenger-transport.md) |
| 0011 | [No suggestion is applied without a human decision](0011-no-suggestion-applied-without-a-human.md) |
| 0012 | [Effects follow the commit](0012-effects-follow-the-commit.md) |
| 0013 | [A stale entity never decides](0013-stale-entities-never-decide.md) |
| 0014 | [A batch is decided against the state it leaves](0014-batch-decided-against-the-state-it-leaves.md) |
| 0015 | [The bundle's transactions are hand-made, not `wrapInTransaction()`](0015-hand-made-transactions.md) |
| 0016 | [The race on the first write of an override stays a documented limit](0016-first-write-race-is-a-documented-limit.md) |
| 0017 | [Savepoints on DBAL 3 are the host's setting, not the bundle's](0017-dbal-3-savepoints-are-the-hosts-setting.md) |
| 0018 | [Keys, catalogues, locales and scopes are compared byte for byte](0018-byte-for-byte-comparison.md) |
| 0019 | [`intl` is optional](0019-intl-is-optional.md) |
| 0020 | [The public contract is one page, method by method](0020-public-contract-is-one-page.md) |
| 0021 | [The entity mapping is public surface](0021-entity-mapping-is-public-surface.md) |
| 0022 | [No `countPendingForKey()`: `createPendingQueryBuilder()` is the extension point](0022-no-count-pending-for-key.md) |
| 0023 | [The AI facade only offers the workflow, not its steps](0023-ai-facade-offers-the-workflow-only.md) |
| 0024 | [The keys of a batch are reserved before the provider is called](0024-keys-reserved-before-the-provider-is-called.md) |
| 0025 | [A deleted scope is purged in a transaction of its own, its journal kept](0025-a-deleted-scope-is-purged-journal-kept.md) |
| 0026 | [A new capability asked of the host is a separate optional interface](0026-new-host-capabilities-are-optional-interfaces.md) |
