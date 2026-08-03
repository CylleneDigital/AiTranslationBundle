# Contributing

Thanks for your interest in this bundle. Issues and pull requests are welcome.

**A security flaw is not reported through an issue**: see [SECURITY.md](SECURITY.md).

## Running the test suite

You need PHP 8.3+ with the `pdo_sqlite` extension, and Composer. `intl` is optional, like for
the bundle itself: without it, the few tests about ICU formatting are skipped. The suite runs on
SQLite by default, so no service has to be started:

```bash
composer install
vendor/bin/phpunit
```

The platform-specific behaviour (unique indexes, MySQL collations, InnoDB's index size
ceiling, the SQL the ORM renders per server) is only proven on a real engine. CI runs the
suite against MariaDB, MySQL and PostgreSQL on every pull request; `compose.yaml` has the same
three servers to do it locally (the matching `pdo_mysql` / `pdo_pgsql` extension is needed):

```bash
docker compose up -d --wait mysql
DATABASE_URL='mysql://app:app@127.0.0.1:3307/ai_translation_test?serverVersion=8.4&charset=utf8mb4' vendor/bin/phpunit

docker compose up -d --wait mariadb
DATABASE_URL='mysql://app:app@127.0.0.1:3306/ai_translation_test?serverVersion=mariadb-11.8.0&charset=utf8mb4' vendor/bin/phpunit

docker compose up -d --wait postgres
DATABASE_URL='postgresql://app:app@127.0.0.1:5432/ai_translation_test?serverVersion=17&charset=utf8' vendor/bin/phpunit
```

A port already taken on your machine moves with `MARIADB_PORT`, `MYSQL_PORT` or
`POSTGRES_PORT` (e.g. `POSTGRES_PORT=55432 docker compose up -d postgres`); adjust the
`DATABASE_URL` accordingly.

The suite never reaches the network: the HTTP client is mocked, so no API key is needed.

## What has to pass before a pull request

```bash
vendor/bin/phpunit                                   # unit and functional tests
vendor/bin/phpstan analyse --memory-limit=1G         # level max on src/ and tests/
vendor/bin/php-cs-fixer check --diff                 # @Symfony, @Symfony:risky, PHP 8.3 migration
```

`vendor/bin/php-cs-fixer fix` applies the coding standard for you.

The suite fails on a deprecation, a notice or an output that the bundle's own code causes. A
functional test that runs a command, counts rows, fakes a provider or needs a temporary file has
a helper for it in `tests/Functional/DatabaseTestCase.php`.

## Proposing a change

`main` is protected. Every change goes through a pull request, a one-line documentation fix
included, and the maintainers work the same way.

1. **Fork** the repository, then clone your fork.
2. Branch off `main`: `git switch -c fix/short-description`.
3. Make the change, with its tests and its documentation.
4. Run the checks of the previous section. They are the ones the CI runs.
5. Push to your fork and open a pull request against `main`, explaining the *problem* first and
   the fix second.

Two checks have to be green before a pull request can be merged:

| Check | What it covers |
|---|---|
| **`Build complete`** | every job, aggregated: PHP 8.3 to 8.5 with the highest and lowest dependencies (PHPStan on the highest only), MariaDB, MySQL and PostgreSQL, and a run without `intl` |
| **`Composer audit`** | known vulnerabilities in the dependencies |

If this is your first contribution, the workflows will not start until a maintainer approves the
run. That is GitHub's default on public repositories, not something you did wrong.

## Conventions

- **PHPStan stays at level `max`** on `src/` and `tests/`. A pull request lowering the level or adding an
  `ignoreErrors` entry must explain why in its description: the type is usually the thing to
  fix.
- **Tests are mandatory** for any bug fix: the test must fail before the fix.
- **`UPGRADE.md`** is updated when the public contract breaks: the configuration tree, the
  container services, the entities, the events, the exceptions, the interfaces under
  `Provider/`, `Catalogue/`, `Override/`, `Scope/`, `Suggestion/` and `Exception/`. It only
  breaks in a major version. A change to the entity mapping (a column, an index, a
  constraint) is one too: every host has to generate a migration for it.
- **Money is a correctness concern.** Generation calls a paid API. Anything that can make a run
  happen twice, or happen without the caller knowing, is a bug, not an optimisation.
- **A provider bridge change states its source**: a page of the provider's API documentation,
  or a real exchange you observed. The suite never reaches the network, so a wire format
  written from memory cannot be checked.
- **Comments say why, not what**: the constraint behind a number, the alternative that was
  rejected, what breaks if the rule is skipped.
- **Never a real API key** in the code, the tests, the fixtures or an issue.
- **The codebase is written in English**, comments and commit messages included.

## Documentation

`docs/` is part of the change, not a follow-up:

- `docs/concepts/`: what a thing is and how it behaves
- `docs/design/`: a decision, its rejected alternatives, and its accepted costs
- `docs/operations/`: running it (commands, caches, multi-server)

## What does not belong here

This bundle is an engine: **no user interface, and no assumption of one**. Its code and its
documentation describe a contract (what a method returns, what an event carries), never a
screen, a button or a badge. Anything specific to a platform, such as Sylius, belongs in the
integration package built on top of it.
