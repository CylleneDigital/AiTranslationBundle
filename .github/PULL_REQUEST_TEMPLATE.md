## What this pull request does



## Points to watch

- [ ] Tests pass (`vendor/bin/phpunit`)
- [ ] PHPStan and PHP-CS-Fixer are green (`vendor/bin/phpstan analyse`, `vendor/bin/php-cs-fixer check`)
- [ ] `UPGRADE.md` is up to date if the public contract changes — the configuration tree, the
      container services, the entities, the events, or the interfaces under `Provider/`,
      `Catalogue/`, `Override/` and `Scope/`
- [ ] No real API key appears in the code, the tests or this description

## If the pull request touches the entity mapping

Say so explicitly: the bundle ships mapping, not migrations, so every host has to generate one.
A column length, an index or a unique constraint that changes needs a line in `UPGRADE.md` and a
run against MySQL, MariaDB and PostgreSQL — SQLite proves none of it.

## If the pull request touches a provider bridge

State what you are relying on: a page of the provider's API documentation, or a real exchange you
observed. The suite never reaches the network, so a wire-format change based on an assumption
cannot be merged.
