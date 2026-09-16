# Updates with minimal risk to existing data

**The short version: upload the new files over the old ones and open the portal.**
The database applies any new migrations on the first page view. There is no
second step, and nothing to remember.

This application does not manage backups; that stays with the operator. The rest
of this document explains what that first page view actually does, what happens
when it fails, and how to run the same thing deliberately on a server that has a
shell.

## What the first page view after an upload does

Each request compares the migration files on disk against what the database
records as applied. On the common path that is one file read and nothing else.
When they differ, an advisory database lock is taken first, so two visitors
arriving together cannot both migrate — the second one waits, then finds nothing
left to do. Then, inside that lock and **before the database is touched at all**:

1. **Are these files newer than the database?** If the database records
   migrations these files do not contain, this is a downgrade: the wrong package,
   or an older one put back. It stops. Without this check nothing would be
   pending, the update would look like a success, and the portal would serve old
   code against a newer schema — the shape of problem that loses data quietly
   rather than failing loudly.
2. **Did the whole upload arrive?** The package ships a `MANIFEST` of every PHP
   and SQL file with its checksum. A file manager extracts a ZIP one file at a
   time and an FTP client in text mode rewrites the line endings of everything it
   copies; both leave a directory that lists perfectly. A mismatch stops the
   update and names the files. A git checkout ships no manifest and is skipped.
3. **Can a backup be written?** A full SQL dump goes to `storage/backups` before
   anything is migrated. If it cannot be written, nothing is migrated. See
   [Backups](#backups) for the way past this when you have taken your own.
4. Each unrecorded migration is then applied in name order and recorded with the
   checksum of the file it came from. A migration that was edited after being
   applied is refused **by name**, because the checksum no longer matches.
5. **Is everything still there?** Rows in `accounts`, `students`, `contacts`,
   `field_values`, `absences`, `charges`, `payments`, `threads`, `messages` and
   `news` are counted before and after. A count that fell stops the update. Counts
   do not prove an update was correct, but a count that fell proves it was not —
   and this catches it while the backup is still the newest thing that happened.
6. The seeded defaults are refreshed for anything new, and the page is served.

If any step fails the portal answers 503 and stays closed, saying in German and
English what went wrong and what to do. It never prints SQL: that address is
public and a parent may be the one looking at it. The full database error goes to
the hosting error log.

While maintenance mode is on all of this is skipped, so an operator applying a
migration by hand from a shell cannot race the web request.

## Backups

A full SQL dump of every table, written before any migration runs. Plain SQL
because that is what the import screen in every hosting panel accepts.

- **Where:** `storage/backups`, beside the maintenance flag, so a deployment with
  separate release folders keeps them across a switch. The folder denies itself
  over HTTP twice — `storage/.htaccess` and a second deny file written into the
  folder itself — and each name carries four random bytes, so a server that ever
  failed to deny it still could not be walked.
- **How many:** the last five. Older ones are removed as new ones arrive, because
  a portal nobody prunes eventually fills the disk quota, which is its own outage.
- **On demand:** `php bin/console.php backup [reason]`, or look at
  **Einstellungen → System**, which lists every copy with its date and size.
- **Restoring** is deliberately not automated. Putting several megabytes of a
  family's data back over a live database is a decision, and phpMyAdmin already
  does it better than anything written here would. The procedure is in
  [INSTALL.md](INSTALL.md#wiederherstellen).
- **When it cannot be written** the update stops. If you have exported the
  database from the panel yourself, create an empty file named `skip-backup` in
  the `storage` folder; the next update proceeds without one and consumes the
  file, so it cannot quietly disable the safeguard for every future update.

A first install writes no backup: an empty database has nothing worth copying.

## Keep these three things separate

On shared hosting this happens by itself: `config/config.php` is not in the
distribution package, so unpacking over the old files cannot touch it, and the
database is untouched apart from the migrations. The table below is the layout
for a server where you keep each release in its own directory.

| Component | Location in the suggested layout | Update behavior |
|---|---|---|
| Source and dependencies | `/srv/badminton/releases/0.1.0/` | Put each new version in a new directory |
| Configuration and encryption key | `/srv/badminton/shared/config.php` | Preserve the file and its `app_key` |
| Students, fields, tariffs, payments, messages | The existing MySQL/MariaDB database | Apply only the new migrations |
| Active web root | `/srv/badminton/current/public/` | `current` points to the selected release |

Each release can contain a symlink `config/config.php` to the shared configuration. Set one shared `maintenance_file` path in that configuration. Do not keep a different maintenance flag inside each release, because a directory switch would then bypass the pause.

## Versioning policy

- `VERSION`, the Git tag and the ZIP filename identify the same release.
- `CHANGELOG.md` records behavior and any compatibility notes.
- `composer.lock` fixes dependency versions. Deployment uses **install**, never **update**.
- Database migrations are ordered SQL files. The migration ledger stores each file’s checksum. Do not edit a migration that has already been applied; add a new file.
- Future schema changes should first add compatible structures, then migrate values and verify them. Remove obsolete structures only in a separate later release after checking that no current code needs them.
- Renaming a custom field keeps its numeric ID. Archiving retains its values. A field type with stored data cannot be changed silently.

## The same thing from a shell

On a server with a command line, where you want the record counts compared
before and after:

```bash
php bin/console.php update
```

It switches maintenance mode on, applies any new migrations, compares record
counts before and after, and only then switches maintenance off. If anything
fails it stops and **leaves the portal closed**. It runs exactly the same
migration code the web request does, so the two cannot disagree about what has
been applied.

An administrator can still sign in while maintenance mode is on, and a banner
at the top of every page offers to switch it off. That is deliberate: switching
it on from **Einstellungen → System** must not be able to lock you out.

### What re-running is guaranteed to do

The migration ledger records each applied file and its checksum, so the runner
is safe to run repeatedly. Verified behaviour:

| Situation | What happens |
|---|---|
| Opening the portal with nothing new uploaded | One file read, no database work |
| Running `update` again with nothing new | Applies nothing, reopens the portal |
| Uploading an older package over a newer one | Refused by name; the database is not touched |
| An extract that stopped halfway | Refused, naming the files that do not match |
| `storage/` not writable when a migration is pending | Refused; no backup, no migration |
| A migration that removes rows from a guarded table | Refused after the fact; the portal stays closed |
| A new migration added in a later version | Applies only that one |
| A column added later to an existing table | Existing rows get the column's default, never NULL |
| A migration file edited after being applied | Refused by name, with the reason |
| A migration failing partway | Stops at that statement, does **not** record the migration, names the statement number |

The last row is the case that needs you. MySQL cannot roll back DDL, so a
migration that fails at statement 5 of 12 leaves the first four applied and the
migration unrecorded — re-running would start it from the beginning and fail
again on the work already done. The error says exactly which statement stopped
and what to do: restore your backup, or finish that migration by hand and add
its row to `schema_migrations` yourself.

## Adding a feature after going live

New settings do **not** need a migration. Every operator setting is declared in
`app/defaults.php` with a type and a default, so a key with no stored row reads
as its declared default. Adding one there makes it appear in the settings form
automatically, with an existing install picking up the default on first read.

Schema changes do need a migration. Add a new numbered file in
`database/migrations/`; never edit one that has shipped. Give every new column a
`DEFAULT` where the type allows, so rows written by the previous version cannot
leave a NULL the new code has to guess about.

A migration must not reduce the row count of any table in
`schema_guarded_tables()`. The update refuses one that does, on purpose: every
migration in this project so far only adds, so a count going down means something
went wrong rather than something being cleaned up. A future release that
genuinely has to remove rows — merging duplicates, say — needs that guard
widened deliberately, in the same commit, with the reason written down.

## With release directories: before the maintenance window

Everything from here on describes a server with a shell where releases live in
separate directories and `current` is a symlink. On hosting where you upload
into one folder, the two sections above are the whole procedure.

1. Read the new release’s change notes, including the schema versions it supports.
2. Put the new release in its own directory. Do not unzip it over the running application.
3. Run `composer install --no-dev --prefer-dist --optimize-autoloader` if dependencies are not included.
4. Test the release using a separate database and separate configuration. Do not point the test mail worker at real recipients. The integration suite deliberately creates and deletes test records and must never use the live database.
5. Check the existing hosting recovery arrangement and who can restore it. No database export, restore or automatic backup is performed by this application.

## With release directories: apply an update

Example paths below are a layout template; `0.2.0` is an example of a future release, not an existing version.

1. Pause the mail cronjob. Enable maintenance in the active release:

   ```bash
   php /srv/badminton/current/bin/console.php maintenance:on
   ```

   New web requests receive HTTP 503; new mail workers refuse to start. Already-running requests must finish before migration. Wait for the current PHP requests and mail worker to finish, using the process manager or hosting panel.

2. Record the current counts and totals:

   ```bash
   php /srv/badminton/current/bin/console.php check > /srv/badminton/shared/before-update.json
   ```

   This is a verification report, not a backup.

3. Link the shared configuration into the new release, then apply its pending migrations:

   ```bash
   ln -s /srv/badminton/shared/config.php /srv/badminton/releases/0.2.0/config/config.php
   php /srv/badminton/releases/0.2.0/bin/console.php migrate
   php /srv/badminton/releases/0.2.0/bin/console.php check > /srv/badminton/shared/after-update.json
   ```

   Stop if a command fails. The migration command does not erase the database or rerun successful migrations. MySQL schema changes may commit individually: a failed multi-statement migration can leave partial changes, so do not assume it rolled back automatically.

4. Compare the before/after student counts, custom-value counts, charges, payments and total cents. Expected changes must be stated in the release notes. Counts alone do not prove completeness: also inspect representative linked students, custom fields, prices, payment periods and conversation ownership in the test deployment.

5. Switch the code using an atomic symlink replacement on the same filesystem:

   ```bash
   ln -s /srv/badminton/releases/0.2.0 /srv/badminton/current-next
   mv -Tf /srv/badminton/current-next /srv/badminton/current
   ```

   This example uses GNU/Linux `mv`. Keep `current-next` unused before running it. Do not use `rsync --delete` over shared data. Reload PHP-FPM or clear its opcode cache through the hosting controls so workers load the new code.

6. Disable maintenance, sign in, open a student, verify their custom fields and payments, and check a student account’s access. Then resume the mail cronjob and send a test mail to your own address.

   ```bash
   php /srv/badminton/current/bin/console.php maintenance:off
   ```

7. Keep the previous release directory until the new release is accepted. Document the version, migration IDs, verification result and deployment time.

## If something goes wrong

- Keep maintenance enabled and mail processing paused while investigating.
- If only code changed, or the new database schema is explicitly compatible with the previous release, switch the `current` symlink back and reload PHP-FPM.
- Do not reverse a migration by guessing SQL, importing the initial schema over existing tables, or dropping/recreating the database.
- If a schema change is incompatible, use a reviewed forward fix or the operator’s existing recovery procedure. Restoring older data may discard newer records; identify that time window and preserve/reconcile legitimate new writes before considering a restore.
- A lost or replaced `app_key` prevents decryption of existing encrypted mail settings and queue contents. Restore the correct configuration rather than generating a new key during an update.
- SMTP cannot guarantee exactly-once delivery across a crash after server acceptance. Inspect uncertain/failed sends before manually retrying; a duplicate email is possible in that failure window.

## Planned development sequence

1. **0.1.x:** hosting feedback, accessibility corrections and bug fixes; retain the current data model where practical.
2. **Next feature release:** confirm actual billing rules before implementing recurring charges, including unique billing-period keys to prevent duplicates.
3. **Before any data conversion:** define the mapping, test on a separate copy, report affected rows, preserve source values and verify totals before activation.
4. **Before removing old fields or tables:** use a separate cleanup release with a documented compatibility boundary and an explicit migration review.

The schema updater only ever moves forward: it applies migration files that the
ledger has not recorded, and refuses one whose contents changed after it ran. It
never drops a table, never reverses a migration, and never runs while maintenance
mode is on.
