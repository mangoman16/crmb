# Badminton CRM

Version **0.5.0**. A self-hosted PHP/MySQL application for a badminton coach and her students, built for mobile use, with German and English interfaces.

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
- A browser installer for hosting without a shell, versioned migrations that apply themselves when new files are uploaded, and a maintenance switch with an administrator bypass.
- Queued email, cleanup and optional monthly charges run without a cron job, just after a page has been served.
- Every operator setting declared once with a type and a default, editable from the settings screen, so no value is ever undefined.
- Online status for accounts, and email reminders for outstanding payments.
- Undo: changes to the main records are versioned, listed under **Änderungen**, and can be put back — including restoring a deleted student under their original number.
- Every write runs in one transaction that either completes or leaves nothing behind, with nesting handled by savepoints.
- A test suite that needs no database server: `php tests/run.php` runs 947 assertions in a few seconds.

## Install

On ordinary web hosting, with no shell: create an empty database in the hosting
panel, upload the distribution ZIP into the domain's folder, unpack it, and open
the address in a browser. The setup page asks for the four database details the
panel gave you and for the first account, then installs everything itself.

Full guide, including what to do when a step fails: [INSTALL.md](INSTALL.md).

The web root may point at the project folder or at `public/`; both work. The
`.htaccess` at the top rewrites every request into `public/`, and each other
folder denies itself, so `app/`, `config/` and `storage/` stay unreachable even
when they sit inside the published directory.

No cron job is required. Waiting work — sending queued email, removing expired
links, and optionally creating the monthly charges on the 1st — runs just after
a page has been delivered, at most once a minute. A real cron job can take over
instead; see INSTALL.md.

With shell access the browser installer is unnecessary:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader   # only after a git clone
cp config/config.example.php config/config.php
php bin/console.php key            # paste the result into config.php as app_key
php bin/console.php migrate
php bin/console.php create-admin
php bin/console.php check
```

Then sign in and complete **Einstellungen → SMTP** and **Einstellungen →
Datenschutz**. Invitations stay disabled until both are done.

## Update

Upload the new files over the old ones and open the portal. The database applies
any new migrations on the first page view, guarded by a lock so two visitors
arriving together cannot run them twice. If a migration fails the portal stays
closed and names the file and statement that stopped, rather than opening half
migrated.

`config/config.php` is not in the distribution package, so an upload cannot
overwrite it. Keep its `app_key` forever: it decrypts the stored SMTP password
and anything still in the mail queue.

Full guide, including separate release directories and how to recover from a
failed migration: [UPDATING.md](UPDATING.md).

```bash
php bin/console.php update            # the same thing from a shell, with before/after counts
php bin/console.php maintenance:on    # close the portal by hand
php bin/console.php maintenance:off   # reopen it
php bin/console.php check             # counts, totals and pending migrations, as JSON
php bin/console.php version           # which release this directory is
```

Building the distribution ZIP, which bundles the dependencies so the upload is
self-contained:

```bash
bin/release.sh
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

1. Follow [INSTALL.md](INSTALL.md): empty database, upload, open the address.
2. Configure the portal under **Einstellungen**. Create tariffs and edit student fields.
3. Add students, invite the account holders, and link each student to the appropriate account.
4. To update, upload the new files. Nothing else.

The distribution ZIP includes PHPMailer, BaconQrCode and the Composer autoloader, so an upload needs nothing else. A Git checkout uses `composer install --no-dev --prefer-dist --optimize-autoloader` to install the exact versions in `composer.lock`.

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
| `public/` | The only web-accessible directory, including the browser installer |
| `app/` | Database, authorization, actions and email handling |
| `views/` | Server-rendered HTML |
| `config/` | Configuration example; actual configuration is excluded from Git |
| `database/migrations/` | Ordered, checksummed schema changes |
| `bin/console.php` | Migrations, mail processing and maintenance, for a server with a shell |
| `bin/release.sh` | Builds the distribution ZIP, dependencies included |
| `docs/` | Editable privacy drafts and hosting examples |
| `tests/` | `php tests/run.php` — runs against a disposable database |

See [GITHUB.md](GITHUB.md) for publishing the source and its version tag to a new private repository.
