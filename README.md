# Badminton CRM

Version **0.4.0**. A self-hosted PHP/MySQL application for a badminton coach and her students, built for mobile use, with German and English interfaces.

## Included

- Invitation-only email/password accounts; verified invitation links, password reset and verified email changes.
- One account can manage multiple students. Administrator, trainer and student roles; account suspension and deletion. Administrators configure the portal; trainers run the day-to-day work.
- Training classes with their own schedule, tariff and bank details. A student can be in several classes.
- Attendance per training session, designed for one hand on a phone: the class on one screen, one tap each, one save.
- Monthly charges on the 1st, with the first month after joining free, per-student pausing, and a preview before anything is created.
- Skill assessment: configurable rating scales, skill areas, dated values per student, progress charts and automatic grouping by level. Visible to staff only.
- Payment profiles with a transfer QR code for anything a parent still owes, generated on the server from an editable payload template.
- Students, named contact people, membership dates, configurable statuses and dated absences.
- Editable custom fields: types, options, defaults, sections, ordering, student permissions and archiving.
- Named tariffs, default prices, individual prices and manual charges with coverage dates. Partial, confirmed and voided payments.
- In-app conversations with unread markers, recipient filters, saved filters, message templates and a preview before group sending.
- News, optional newsletter emails, separate private-message notifications, and unsubscribe links.
- SMTP settings, encrypted SMTP password, a mail queue with automatic retry and an outgoing-mail overview.
- Editable German/English privacy drafts, acknowledgement and subscription records.
- Light and dark appearance following the device, adjustable text size, and installable to a phone home screen.
- Versioned database migrations, a maintenance switch with an administrator bypass, and `console.php update` as a single, repeatable upgrade step.
- Every operator setting declared once with a type and a default, editable from the settings screen, so no value is ever undefined.
- Online status for accounts, and email reminders for outstanding payments.
- Undo: changes to the main records are versioned, listed under **Änderungen**, and can be put back — including restoring a deleted student under their original number.
- Every write runs in one transaction that either completes or leaves nothing behind, with nesting handled by savepoints.
- A test suite that needs no database server: `php tests/run.php` runs the whole suite in a few seconds.

## Install

Full guide with hosting specifics: [INSTALL.md](INSTALL.md). The short version,
run from the project directory, with `public/` as the web root:

```bash
# 1. Dependencies (the distribution ZIP already includes them; a Git checkout does not)
composer install --no-dev --prefer-dist --optimize-autoloader

# 2. Configuration, kept outside the release directory so updates preserve it
mkdir -p /srv/badminton/shared
cp config/config.example.php /srv/badminton/shared/config.php
ln -s /srv/badminton/shared/config.php config/config.php

# 3. Generate the encryption key, then paste it into config.php as app_key
php bin/console.php key
```

Edit `/srv/badminton/shared/config.php`: set `app_key` to the key just
generated, `app_url` to the exact address without a trailing slash, the database
credentials, and `maintenance_file` to a path outside the release directory.
Keep that `app_key` forever — it decrypts the stored SMTP password and any
queued mail, and a database backup without it will not restore them.

Create the database first (see INSTALL.md for grants), then:

```bash
# 4. Create the schema, the first administrator, and verify the result
php bin/console.php migrate
php bin/console.php create-admin
php bin/console.php check
```

`create-admin` asks for a name, email address and password, and only works while
no administrator exists.

```bash
# 5. Cron: mail delivery, nightly cleanup, and monthly charges on the 1st
* * * * * /usr/bin/php /srv/badminton/current/bin/console.php mail:work 25 >> /srv/badminton/shared/mail-worker.log 2>&1
15 3 * * * /usr/bin/php /srv/badminton/current/bin/console.php maintenance >> /srv/badminton/shared/maintenance.log 2>&1
30 4 1 * * /usr/bin/php /srv/badminton/current/bin/console.php billing:run >> /srv/badminton/shared/billing.log 2>&1
```

Without the first line no email is ever sent. The third line is optional — leave
it out to create the monthly charges by hand from **Beiträge → Monatsbeiträge**,
which shows the full list before creating anything. It is safe on cron either
way: a second run in the same month creates nothing.

Finally, sign in and configure **Einstellungen → SMTP**, then complete both
privacy drafts under **Datenschutz**. Invitations stay disabled until both are
done.

## Update

Full guide, including how to recover from a failed migration:
[UPDATING.md](UPDATING.md). Releases live in their own directories and `current`
is a symlink, so a switch is atomic and reversible.

```bash
# 1. Unpack the new release into its own directory — never over the running one
unzip badminton-crm-v0.5.0.zip -d /srv/badminton/releases/
NEW=/srv/badminton/releases/badminton-crm-v0.5.0     # adjust to the directory just created

# 2. Reuse the shared configuration, and install dependencies if the package omits them
ln -s /srv/badminton/shared/config.php "$NEW/config/config.php"
cd "$NEW" && composer install --no-dev --prefer-dist --optimize-autoloader

# 3. Record the current state for comparison. This is a check, not a backup
php /srv/badminton/current/bin/console.php check > /srv/badminton/shared/before-update.json

# 4. Run the update: maintenance on, migrations, verification, maintenance off
php "$NEW/bin/console.php" update

# 5. Switch the active release atomically, then reload PHP-FPM
ln -s "$NEW" /srv/badminton/current-next
mv -Tf /srv/badminton/current-next /srv/badminton/current
```

Step 4 is the whole upgrade. It switches maintenance mode on, applies only the
new migrations, compares record counts before and after, and switches
maintenance off only if everything passed. **If anything fails it stops and
leaves the portal closed**, so nobody sees a half-migrated portal. An
administrator can still sign in while maintenance is on, and a banner offers to
switch it off.

Re-running `update` with nothing new applies nothing and reopens the portal. A
migration edited after being applied is refused by name. Keep the previous
release directory until the new one is accepted.

```bash
php bin/console.php maintenance:on    # close the portal by hand
php bin/console.php maintenance:off   # reopen it
php bin/console.php check             # counts and totals, as JSON
php bin/console.php version           # which release this directory is
```

## For developers

```bash
php tests/run.php              # every suite, seconds, no database server needed
php tests/run.php billing      # one suite
```

The suite builds a disposable SQLite database from the real migrations and boots
the real application against it. That proves the PHP logic, **not** the SQL
dialect — see [tests/README.md](tests/README.md). To prove the SQL:

```bash
tests/mariadb-local.sh          # starts a throwaway MariaDB, runs the suite, stops it
```

It has been run against MariaDB 10.11.14 with everything passing. Against a
database you manage yourself, whose name must end in `_test`:

```bash
CRM_TEST_DRIVER=mysql CRM_CONFIG=/path/to/test-config.php php tests/run.php
```

Conventions for changing this code are in [CLAUDE.md](CLAUDE.md). Where the
project is going, and what has to be true before it holds real data, is in
[PROJECT.md](PROJECT.md).

## Start here

1. Follow the install steps above, or [INSTALL.md](INSTALL.md) for the detail.
2. Configure the portal under **Einstellungen**. Create tariffs and edit student fields.
3. Add students, invite the account holders, and link each student to the appropriate account.
4. Before future updates, follow the update steps above or [UPDATING.md](UPDATING.md).

The distribution ZIP includes PHPMailer and its Composer autoloader. A Git checkout uses `composer install --no-dev --prefer-dist --optimize-autoloader` to install the exact dependency in `composer.lock`.

## Scope of this version

Monthly charges are generated on the 1st from each student's agreed price or monthly tariff. One-time and fixed-period tariffs are still entered by hand, because they do not recur. There is no online payment processor. SMTP sends email; replies belong in the app. No mailbox reader or backup system is included.

Custom content, tariff names and message templates are entered by the operator; switching the interface language does not translate their content. The privacy notice is an editable draft that still needs the actual operator and service-provider details. Invitations stay disabled until SMTP is configured and both privacy texts are completed in settings.

This release has not been deployed. Validation results and remaining hosting
checks are in [VALIDATION.md](VALIDATION.md). A full bug, security and design
review is in [AUDIT.md](AUDIT.md), and what is done versus outstanding is in
[ROADMAP.md](ROADMAP.md) — start there. All six migrations and the whole test
suite have been run against **MariaDB 10.11.14**; **MySQL 8.0 itself has not
been tried**. Either way, run `php bin/console.php update` against a disposable
copy of the database before touching anything real.

## Layout

| Directory | Purpose |
|---|---|
| `public/` | The only web-accessible directory |
| `app/` | Database, authorization, actions and email handling |
| `views/` | Server-rendered HTML |
| `config/` | Configuration example; actual configuration is excluded from Git |
| `database/migrations/` | Ordered, checksummed schema changes |
| `bin/console.php` | Installation, mail processing and maintenance commands |
| `docs/` | Editable privacy drafts and hosting examples |
| `tests/` | `php tests/run.php` — runs against a disposable database |

See [GITHUB.md](GITHUB.md) for publishing the source and its version tag to a new private repository.
