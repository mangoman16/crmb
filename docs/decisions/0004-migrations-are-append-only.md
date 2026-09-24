---
status: accepted
date: 2026-09-22
---

# 0004. Migrations are append-only, and an update refuses rather than guesses

## Context

There are 18 migrations in `database/migrations/`, applied by `app/schema.php`. The same
runner is used by the browser installer, by `bin/console.php` and by the first request
after files are uploaded — one copy, because a second path is how two of them start
disagreeing.

The operator updates by uploading files. Nobody watches the migration run. If it goes
wrong, there is no shell to go and fix it from, and the data is a family's membership and
payment records.

## Decision

A schema change is a **new numbered file**. A shipped migration is never edited — the
ledger stores checksums and refuses it.

Every new column gets a `DEFAULT`, so rows written by the previous version cannot leave a
NULL the new code has to guess about.

An update **refuses and keeps the portal closed** rather than guessing, on any of: files
older than the database, an incomplete upload, a database it could not back up first, or a
result with fewer rows in `schema_guarded_tables()` than it started with. A migration that
genuinely has to remove rows widens that guard in the same commit, with the reason written
down.

A migration that moves data is tested **against data**: `tests/migration-data.php` applies
the migrations in two halves with rows in between, and `tests/suites/migrations.php`
asserts on what came out. Applying migrations to an empty database proves the DDL parses
and nothing else.

## Rejected

**Editing a migration to fix it before release.** Tempting while nothing is deployed, and
it trains the habit that breaks the one install that already ran it.

**A down migration for every up.** A rollback that has itself never been run against real
rows is a false promise. The guard is the refusal plus the pre-update backup.

**Trusting the DDL to speak for the data.** It does not. Two migrations here moved prices
and addresses across, and both passed an empty-database run while their `UPDATE` statements
had never touched a row.

## Consequences

- `database-engineer` owns new migration files and never touches an old one.
- Any data-carrying statement needs a case in `tests/suites/migrations.php`.
- MariaDB 10.11.14 is verified (`tests/mariadb-local.sh`). **MySQL 8.0 is not.** Claims
  about "the database" name the engine actually run.
