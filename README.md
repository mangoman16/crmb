# Badminton CRM

Version **0.6.0**, in beta. A self-hosted portal for a badminton club: students, courses,
attendance, fees and invoices, and a chat. It is built for a trainer working on a phone, and
for children and families who read little. German first, English available. PHP 8.2 or newer
with MariaDB; MySQL is meant to work but has never been run.

## What it includes

- **Signing in.** Everybody signs in with their own e-mail address: one person, one address,
  one login. Every student has a login of their own, one student per login; until the child's
  own address is entered on their page and the invitation sent, it is a placeholder nobody
  signs in with, „Ohne Anmeldung". An administrator invites staff by address. Invitations and
  reset links go out only once the mail connection has passed a test and the German privacy
  notice is released. Administrator, trainer and student roles.
- **Adding a student** in two steps: who is joining and into which course, then how they sign
  in — an invitation by e-mail, or no sign-in for now. Somebody known only by their address
  can be invited from the wizard's first step and fills in their own details. A student's
  login is replaced, never deleted.
- **„Dein Portal einrichten"**, nine steps from an empty portal to the first invitation, each
  ticked from the data. An administrator lands there at every sign-in until it is done or
  hidden.
- **A menu of seven**: Übersicht, Schüler, Kurse, Anwesenheit, Geld, Nachrichten, and
  Einstellungen for an administrator or Verwaltung for a trainer. On a phone a bar at the
  bottom holds Übersicht · Schüler · Anwesend · Chats · Mehr for staff, „Mehr" being a page
  with the rest of the menu, Mein Konto, the privacy notice and „Abmelden", and Übersicht ·
  Beiträge · Chats · Profil for a family.
- **Courses** with a timetable of several days a week, each with its own time and place, and
  their own tariffs and bank account. A child can be in several courses, and can ask to join,
  leave or change tariff; staff accept or decline.
- **Attendance** on one screen: pick the course and the day, one tap per child, one save. A
  child reported absent carries the reason beside their name.
- **Charges** monthly, every two, three or six months, or yearly, with a rule for a first
  part period, discounts for a number of months, due days, overdue days, a pause per child,
  and a preview before anything is created. Automatic monthly charges are switched on by hand.
- **Invoices** with what § 11 Abs 1 UStG asks for, numbered per year, as a PDF made on
  request, with the Kleinunternehmer note or with net, rate and tax.
- **Paying** by bank transfer, with a QR code for what is still owed; a family can upload a
  receipt. Payment reminders by e-mail.
- **Levels and age groups**, renameable and extendable; the age group follows the date of
  birth alone, by one rule that the child's page, the list and its filter all share.
  **Schüler** finds a child by name, narrows the list with „Alle | Überfällig | Krank" and
  „Filter", and lists the children „Nach Alter", a card per group with its count.
- **Contacts** are the people to ring about a child. The portal writes to the child's own
  login.
- **A chat**: a group for every course, whose children are whoever is enrolled now, and a
  chat between a child and one member of staff. The administrators can read every chat and
  write only in their own; nothing records their reading. A family writes to the coaching
  team only; a chat two children had earlier stays to read and takes no new messages. A
  message is text and photos; voice notes
  and files sent earlier stay. Messages are deleted a year after they were sent. A photo is
  stored without where, when and with what it was taken. Everybody appears by name, and by
  picture or initials.
- **Profile pictures**, for the children and the team: a family adds its child's, the
  trainer a child's at training too, each team member their own. The trainers and
  administrators see every child's; the other families in a course only once the child's
  family agrees, from 14 the child itself (ADR 0031). Kept as a small square, without where
  and when it was taken; PHP's gd makes it.
- **News**, and club news by e-mail, which starts switched on and can be switched off in
  **Mein Konto** or from every such mail.
- **Mail** through SMTP, with the password stored encrypted, a queue that retries, and an
  outbox to look at.
- **Privacy drafts** in German and English, filled in from the club's details. Only the German
  notice must be released.
- **The look of an iPhone app**: the phone's own font at the reader's text size, white grouped
  lists on a grey ground, capsule buttons that show a tap and a spinner while sending,
  switches, sheets for anything that deletes, a back button with the parent page's name, and
  pages that fade into each other where the browser can. So far seen in Chromium only, not
  on a real iPhone.
- **The club's own look** within that: colours, a logo and a home-screen icon. Light and dark
  follow the device; text size, language and a personal colour are per person, in Mein Konto.
- **Problem reports** from any page, with a screenshot and the last eight steps, and
  **unexpected errors** that write themselves down under **Einstellungen → Rückmeldungen**
  with a text to copy for whoever helps. Both are deleted 30 days after they are done or last
  happened.
- **Viewing the portal as somebody else**: an administrator as anybody, a trainer as a
  student, with a bar saying so and a way back. It is for looking only: nothing can be written
  or changed in that view. If the viewer's own login is deleted, suspended, demoted or given a
  new password meanwhile, the view ends and that browser is signed out.
- **„Änderungen"**, a change log that says what changed, field by field, and who changed it.
  It informs; there is no undo.
- **A period for each kind of data**: the daily cleanup deletes chat messages a year after
  they were sent, absences three months after they ended, attendance and payment proofs
  after two years, and the rest by a period of its own, each a setting under
  Einstellungen → System (ADR 0032). Charges, payments and invoices are never deleted by it.
- **Example data** at the press of a button, and out again: one course, four children and
  three sign-ins that work for 14 days.
- **Installing from a browser** on hosting without a shell, migrations that apply themselves
  after an upload, and an update that refuses rather than guesses: older files than the
  database, an incomplete upload, a database it could not back up first, or fewer rows
  afterwards each keep the portal closed. After a loss it stays closed on every page view,
  for everybody, until the rows are back (ADR 0027).
- **Background work** — queued mail, the daily cleanup and, when switched on, the monthly
  charges on the first page view of a month — runs just after a page has been served, with
  no cron job needed.

## Install and update

On ordinary web hosting: create an empty database in the hosting panel, upload the release
ZIP, unpack it and open the address. [INSTALL.md](INSTALL.md) has every step and what to do
when one fails. No ZIP is published: there is no release yet, and `bin/release.sh` builds one
from a checkout.

To update, upload the new files over the old ones and open the portal.
[UPDATING.md](UPDATING.md) says what that first page view checks, and how to recover when it
refuses.

With a shell:

```bash
git clone https://github.com/mangoman16/crmb.git /path/to/webroot
cd /path/to/webroot
composer install --no-dev --prefer-dist --optimize-autoloader --ignore-platform-req=ext-gd
# then open the address in a browser and finish the setup page
```

`--ignore-platform-req=ext-gd` lets Composer install where PHP's gd is missing, which only the
profile pictures need; it waives gd alone and needs Composer 2.0 or later.
`bin/update.sh --clone` installs the same way by itself, and `bin/update.sh` updates such a
checkout, files and database together; `bin/update.sh --help` lists its options.
`php bin/console.php help` lists the console's commands.

## Where things are

| | |
|---|---|
| [ROADMAP.md](ROADMAP.md) | The plan: what is being done now, what is decided, what is open |
| [CLAUDE.md](CLAUDE.md) | How the code is written, and how the work on it is organised |
| [docs/decisions/](docs/decisions/README.md) | The structural decisions, one per file |
| [TESTING.md](TESTING.md) | The checks to walk by hand |
| [tests/README.md](tests/README.md) | The automated suites and the browser walk |
| [VALIDATION.md](VALIDATION.md) | What was verified, on what, and what was not |
| [CHANGELOG.md](CHANGELOG.md) | What changed, release by release |

The package that `bin/release.sh` builds leaves out ROADMAP.md, CLAUDE.md, `tests/` and the
agents' prompts in `.claude/`, so the links to those three work in the Git copy only.

Beta: no portal holds real families' data. Everything verified so far ran on MariaDB 10.11.14
and in Chromium; MySQL 8.0, Safari on a real iPhone, a real mail provider and a real hosting
account have not been tried.

## Layout

| Directory | Purpose |
|---|---|
| `public/` | The only web-facing directory, with the browser installer |
| `app/` | Database, sign-in, actions, billing, mail |
| `views/` | Server-rendered pages |
| `config/` | The configuration example; the real one is not in Git |
| `database/migrations/` | Ordered, checksummed schema changes |
| `bin/` | The console, `release.sh` and `update.sh`, for a server with a shell |
| `docs/` | Decisions, screen specifications, the privacy drafts and the nginx example |
| `tests/` | The suites, the browser walk and the phone-width sweep |

The web root may be the project folder or `public/`. The `.htaccess` at the top sends every
request into `public/`, and every other folder denies itself.
