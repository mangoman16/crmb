# Updates with minimal risk to existing data

This application does not manage backups. Existing backup arrangements remain the operator’s responsibility. The update procedure below keeps application releases separate from the database and configuration, blocks new writes during a change, and provides checks before reopening the portal.

## Keep these three things separate

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

## Before the maintenance window

1. Read the new release’s change notes, including the schema versions it supports.
2. Put the new release in its own directory. Do not unzip it over the running application.
3. Run `composer install --no-dev --prefer-dist --optimize-autoloader` if dependencies are not included.
4. Test the release using a separate database and separate configuration. Do not point the test mail worker at real recipients. The integration suite deliberately creates and deletes test records and must never use the live database.
5. Check the existing hosting recovery arrangement and who can restore it. No database export, restore or automatic backup is performed by this application.

## Apply an update

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

No scheduled billing or destructive automatic schema updater is enabled in 0.1.0.
