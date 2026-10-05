# Badminton CRM

Version **0.6.0**. A self-hosted PHP/MySQL application for a badminton coach and her students, built for mobile use, with German and English interfaces.

## Included

- Invitation-only email/password accounts; verified invitation links, password reset and verified email changes. Invitations and reset links are only sent once the mail connection has passed a test, so none is queued behind a server that has never answered.
- One login is one student: a student's own email address is their login, and it is invited, suspended, re-sent or deleted on that student's page. Brothers and sisters each need an address of their own, and the database refuses a second student on one login. Administrator, trainer and student roles. An administrator can do everything a trainer can; the lists the trainer works with sit under **Verwaltung**, away from the technical settings, and **Konten** lists the team.
- A start checklist, „Dein Portal einrichten“, with the nine things a new portal needs before families come. Each step is ticked from the data itself, example data never counts, and an administrator lands there at sign-in until it is done or hidden.
- A menu of seven entries — Übersicht, Schüler, Kurse, Anwesenheit, Geld, Nachrichten, Einstellungen (Verwaltung for a trainer). Everything else is reached from the page it belongs to, and „Mehr“ on the phone opens the menu without JavaScript.
- Courses with a timetable rather than a weekday: several days a week, each with its own time and place, its own tariffs and its own bank details. A student can be in several courses.
- Students can ask to join, leave or change tariff; the trainer accepts or declines.
- Attendance on one screen, designed for one hand on a phone: pick the course, pick the day, one tap per child, one save. A child reported absent that day carries the reason beside their name.
- Charges monthly, every two, three or six months, or yearly; a first period prorated, charged whole or skipped; a discount for a number of months as a percentage or a fixed amount; a due day from the tariff or from the child; overdue a set number of days later; per-student pausing and a preview before anything is created.
- Invoices that carry what § 11 Abs 1 UStG asks for, numbered consecutively per year, as a PDF generated on request — with the Kleinunternehmer note or with net, rate and tax.
- Levels (Anfänger, Fortgeschritten, Könner) and age groups worked out from the date of birth, both renameable and extendable, with one of each as the default.
- Payment profiles with a transfer QR code for anything a parent still owes, generated on the server from an editable payload template. Families can upload a proof of payment, optionally.
- Students with contact people — one of them the standard contact, with an address invoices and reminders go to — membership dates, configurable statuses and dated absences.
- Editable custom fields: types, options, defaults, sections, ordering, student permissions and archiving.
- Messages in the shape people already know one: conversations, bubbles, pictures, PDFs and voice notes. Writing to the trainer needs nobody's permission; writing to another family needs theirs. A conversation between two families is private — neither the trainer nor the administrator can read it. Group messages with filters, saved views, templates and a review step are a separate page.
- News, and club news by email, which starts switched on for a new account and can be switched off under **Mein Konto** or through the link in every such email; separate private-message notifications; unsubscribe links. The printed sign-up form asks for the no rather than the yes.
- SMTP settings, encrypted SMTP password, a mail queue with automatic retry and an outgoing-mail overview.
- Editable privacy drafts filled in from the operator's own details, acknowledgement and subscription records. Only the German notice has to be released; somebody using the portal in English is shown it with a line saying it exists in German only.
- A pinned top bar with a bell for notifications, and a menu behind your picture with „Mein Konto" and „Abmelden" that works without JavaScript. Profile pictures are kept by the browser for up to seven days, and a family can fetch only its own child's picture and the staff's. Light and dark follow the device, text size is adjustable, and each person can override the portal colour for themselves. Installable to a phone home screen, with the club's own icon.
- The club's own look under **Einstellungen → Portal**: a main, menu, highlight and background colour, with dark-mode shades worked out and a colour too pale or dark for readable text used in a readable shade of the same hue; and a logo (PNG, JPEG or WebP, at most 1 MB) in place of the „B", with switches to hide the portal's name beside it. A portal with nothing set looks as before and loads nothing extra.
- Any page can report that something is wrong on it, with a screenshot. The report arrives with the page, the device, the version and the last eight steps before it, form fields included but never a password or a token. Marking it done removes what was typed, and a done report is deleted 30 days later.
- Unexpected errors report themselves: whoever hit one sees a friendly page, and the administrator finds the error under **Einstellungen → Rückmeldungen**, counted rather than repeated, with a block of text to copy for whoever helps her. No password, typed value, IP address or database message goes into it, and an entry is deleted 30 days after the error last happened.
- An administrator or trainer can view the portal as somebody else, with a bar saying so and a way back — and what they change is recorded against them.
- Example data at the press of a button, and out again, so the portal can be tried before it holds anybody real.
- A browser installer for hosting without a shell, versioned migrations that apply themselves when new files are uploaded, `bin/update.sh` for a server with one, and a maintenance switch with an administrator bypass.
- An update that refuses to run against an older package, an incomplete upload, a database it could not back up first, or a result with fewer rows than it started with.
- Queued email, cleanup and optional charge creation run without a cron job, just after a page has been served.
- Every operator setting declared once with a type and a default, editable from the settings screen, so no value is ever undefined.
- For trainers and administrators: a status to choose („Automatisch", „Abwesend", „Als offline anzeigen"), a coloured dot on each picture, and when each account was online over the last 30 days — date and time only, deleted after 30 days. Families see none of it.
- Email reminders for outstanding payments.
- A change log that says what changed, field by field, in the words she uses.
- Every write runs in one transaction that either completes or leaves nothing behind, with nesting handled by savepoints.
- A test suite on the engine her server runs: `tests/mariadb-local.sh` starts a throwaway MariaDB and runs about 5900 assertions in under two minutes, writing only into a temporary folder of its own. `tests/e2e.sh` walks the first evening in a real browser. [TESTING.md](TESTING.md) is the list to walk by hand after a change.

## Install

There are two ways in, and which one you want depends on whether the server has
a shell.

**With a shell (Git).** This is the supported route for a server you can log
into, and the one `bin/update.sh` keeps up to date afterwards:

```bash
git clone https://github.com/mangoman16/crmb.git /path/to/webroot
cd /path/to/webroot
composer install --no-dev --prefer-dist --optimize-autoloader
# then open the address in a browser and finish the setup page
```

`bin/update.sh --clone /path/to/webroot` does the same in one command.

**Without a shell (ZIP).** On ordinary web hosting: create an empty database in
the hosting panel, upload the distribution ZIP into the domain's folder, unpack
it, and open the address in a browser. The setup page asks for the four database
details the panel gave you and for the first account, then installs everything
itself.

> **Where is that ZIP?** It is not published anywhere yet — this repository has
> no GitHub release. The ZIP is built from a checkout with `bin/release.sh`,
> which bundles the dependencies so the upload needs nothing else. Until a
> release is published, somebody with a shell has to build it and hand over the
> file.

Full guide, including what to do when a step fails: [INSTALL.md](INSTALL.md).

The web root may point at the project folder or at `public/`; both work. The
`.htaccess` at the top rewrites every request into `public/`, and each other
folder denies itself, so `app/`, `config/` and `storage/` stay unreachable even
when they sit inside the published directory. Hidden folders such as `.git`
answer 404 through `mod_alias` even where `mod_rewrite` is off; `.well-known/`
stays reachable for certificate renewal. That rule is checked by reading the
file, not on a real Apache.

No cron job is required. Waiting work — sending queued email, removing expired
links, and optionally creating the monthly charges once a month — runs just after
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

Then sign in. An administrator lands on „Dein Portal einrichten“, which lists
what is still missing and links to where each thing is done. Invitations stay
disabled until the SMTP connection has passed a test under **Einstellungen →
SMTP** and the German privacy notice is released under **Einstellungen →
Datenschutz**.

## Update

Upload the new files over the old ones and open the portal. The database applies
any new migrations on the first page view, guarded by a lock so two visitors
arriving together cannot run them twice.

Before the database is touched at all, four things have to hold, and each one
stops the update rather than proceeding on a guess:

1. **The files are newer than the database.** An older package is a downgrade,
   which would otherwise pass silently — nothing is pending, so the update would
   look like a success while the portal ran old code against a newer schema.
2. **The upload is complete.** The package ships a `MANIFEST` of every PHP and
   SQL file with its checksum, so an extract that stopped halfway, or an FTP
   client in text mode, is caught rather than migrated against.
3. **A backup was written.** A full SQL dump goes to `storage/backups` first. No
   backup, no migration. The last five are kept and older ones pruned.
4. **No rows disappeared.** Counts across nineteen tables are compared before
   and after; a count that fell keeps the portal closed.

If anything fails the portal answers 503 and says in German and English what went
wrong and what to do — never SQL, because that address is public and a parent may
be the one reading it.

`config/config.php` is not in the distribution package, so an upload cannot
overwrite it. Keep its `app_key` forever: it decrypts the stored SMTP password
and anything still in the mail queue.

Full guide, including separate release directories and how to recover from a
failed migration: [UPDATING.md](UPDATING.md). An existing portal moving to this
version should read [its section there](UPDATING.md#updating-an-existing-portal-to-060)
first: migration 019 takes every child but the first off a shared login, and
invitations and reset links wait until **„Nur Verbindung prüfen“** under
**Einstellungen → SMTP** has passed once.

```bash
php bin/console.php update            # the same thing from a shell, with before/after counts
php bin/console.php maintenance:on    # close the portal by hand
php bin/console.php maintenance:off   # reopen it
php bin/console.php backup            # a full SQL copy into storage/backups, on demand
php bin/console.php check             # counts, totals and pending migrations, as JSON
php bin/console.php version           # which release this directory is
```

Building the distribution ZIP, which bundles the dependencies so the upload is
self-contained:

```bash
bin/release.sh
```

Updating a checkout, files and database together:

```bash
bin/update.sh                  # pull, install dependencies, migrate, report
bin/update.sh --check          # say what would happen, change nothing
bin/update.sh --ref v0.6.0     # pin to a tag
php bin/console.php status     # do the files and the database agree?
```

## For developers

```bash
tests/mariadb-local.sh          # starts a throwaway MariaDB, runs every suite, stops it
tests/mariadb-local.sh billing  # one suite
```

The suite runs on MariaDB only, the engine her server runs: it builds the
schema from the real migrations and boots the real application against it — see
[tests/README.md](tests/README.md). It ends by naming anything it could not
reach, rather than leaving that to be assumed. `tests/mariadb-local.sh` also
makes a second, empty `_test` database, on which the data that migrations carry
across is checked from one version to the next. It has been run on MariaDB
10.11.14 with PHP 8.4.26, everything passing; MySQL 8.0 is not verified.

On shared hosting, where there is no database server to start, make an empty
database whose name ends in `_test` in the hosting panel and run the suite
against it from the git checkout. It refuses the portal's own database, one that
is not empty the first time, and one holding real email addresses:

```bash
tests/existing-database.sh      # asks for the details once, keeps them in tests/.test-database.php
```

Against a database you manage yourself, whose name must end in `_test`:

```bash
CRM_CONFIG=/path/to/test-config.php php tests/run.php
```

Every run keeps what it writes — uploads, backups, the maintenance flag — in a
folder of its own under the system temp directory, prints that folder on its
second line, and removes it at the end, so running it inside a live checkout no
longer touches the portal's files.

The first evening, end to end in Chromium at phone width against a throwaway
MariaDB, a local mail server and `php -S` (needs `mariadbd`, `node` and
Playwright's Chromium; details in [tests/README.md](tests/README.md)):

```bash
tests/e2e.sh                    # must end in RESULT: PASS
```

Conventions for changing this code are in [CLAUDE.md](CLAUDE.md). Where the
project is going, and what has to be true before it holds real data, is in
[PROJECT.md](PROJECT.md).

## Start here

1. Follow [INSTALL.md](INSTALL.md): empty database, files in place, open the address.
2. Sign in. „Dein Portal einrichten“ lists the nine steps — name and address, bank account, a course, a price for it, the children, charges, email, privacy notice, invitations — and ticks each one as it is done.
3. Each child is invited from their own page, to their own email address.
4. To try it before real families are in it: tick **Beispieldaten anlegen** on the setup page, or later **Einstellungen → System → Beispieldaten anlegen**. Example data never ticks a checklist step, and comes out again with one button.
5. To update, upload the new files — or run `bin/update.sh` on a checkout. Nothing else.

The distribution ZIP includes PHPMailer, BaconQrCode and the Composer autoloader, so an upload needs nothing else. A Git checkout uses `composer install --no-dev --prefer-dist --optimize-autoloader` to install the exact versions in `composer.lock`.

## Scope of this version

Recurring charges are created from the tariff each enrolment names, for periods anchored to the calendar year, either from the payments screen with a preview or automatically once a month on the first page view of that month. One-off tariffs are entered by hand, because they do not recur. There is no online payment processor. SMTP sends email; replies belong in the app. No mailbox reader or backup system is included.

Custom content, tariff names and message templates are entered by the operator; switching the interface language does not translate their content. The privacy notice is an editable draft that still needs the actual operator and service-provider details. Invitations stay disabled until the SMTP connection has passed a test and the German privacy text is released; an English text is optional, and is checked the same way when there is one.

This release has not been deployed. Validation results and remaining hosting
checks are in [VALIDATION.md](VALIDATION.md). A full bug, security and design
review is in [AUDIT.md](AUDIT.md), and what is done versus outstanding is in
[ROADMAP.md](ROADMAP.md) — start there. All twenty-one migrations and the whole
test suite have been run against **MariaDB 10.11.14**. The first evening was
walked end to end in Chromium against it, last at commit `2be0a61`, before the
account menu, online status and the club's colours and logo were added; that
walk has not been repeated since them; **MySQL 8.0 itself has not been
tried**, and neither has a real iPhone, a real mail provider or a real hosting
account. Either way, run `php bin/console.php update` against a disposable
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
| `tests/` | `tests/mariadb-local.sh` — the whole suite on a throwaway MariaDB; `tests/e2e.sh` — the first evening in a real browser |

See [GITHUB.md](GITHUB.md) for publishing the source and its version tag to a new private repository.
