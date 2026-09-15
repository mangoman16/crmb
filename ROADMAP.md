# Roadmap

Where the project actually stands and what to do next, in priority order.
Findings referenced as (A#) are numbered in [AUDIT.md](AUDIT.md). The reasoning
behind this order — phases, decisions taken, and the questions still open for
her — is in [PROJECT.md](PROJECT.md).

Nothing has ever been deployed and no real student data exists yet, so the
whole list is still cheap to reorder. Say what matters to her and it moves.

---

## Done

### v0.1.0 — as received
Invitation-only accounts with verified email; admin / manager / student roles;
students with contacts, membership dates, absences and configurable custom
fields; tariffs, manual charges and partial/confirmed/voided payments; in-app
conversations with recipient filters and templates; news with an optional
newsletter; SMTP with a sealed password and an outgoing queue; editable
bilingual privacy notice with consent records; versioned migrations, a
maintenance switch and a `check` command.

### This review
**Security** — rate limits no longer refund themselves on failure (A1);
whitelisted the interpolated column name (A6); password blocklist (A9);
`unseal()` no longer aborts a mail run from inside a catch block (A8);
COOP/CORP headers (A20); per-directory deny rules (A21).

**Correctness** — timestamps show the right calendar day (A2); failed email
retries with backoff (A3); `current_user()` resolved once per request (A4);
the web mail run respects `max_execution_time` (A5); `fmt_date()` no longer
fatal on bad input (A7); indexes for the queries actually issued (A14);
migration splitter handles quoted semicolons (A15); plus four smaller fixes
(A16–A19).

**Design** — dark mode following the device, with three contrast defects fixed
that only showed up in rendered screenshots (A10); home-screen install with a
real app icon and standalone chrome (A12); unread-message markers (A11); nine
touch targets raised to Apple's 44pt minimum (A13); appearance and text-size
settings stored per account.

### 0.2.0 — roles, classes, payments, skills
**Roles** administrator / trainer / student, with configuration reserved to the
administrator and day-to-day work open to the trainer.

**Classes** with schedule, tariff, bank details and members; a student can be in
several, and leaving is recorded rather than erased.

**Skill assessment**, trainer-only: configurable scales, areas, skills, dated
values with notes, progress charts, and banding by area whose thresholds are a
setting rather than code. Verified that a parent account sees none of it.

**Payment QR codes** for outstanding amounts, from an editable payload template
defaulting to SEPA EPC069-12, generated on the server. IBANs validated by
checksum. A charge finds its recipient from the charge, then the class, then the
default.

**Defaults registry** — every setting declared once with a type and a default,
rendered from that declaration, so nothing is undefined and a new setting needs
no migration.

**Update path** — one `console.php update` command, verified idempotent, with a
checksum guard against edited migrations and an actionable message when one
fails partway.

Also: online status, maintenance mode from the UI with an administrator bypass,
payment reminder emails, plainer wording, and a simplified parent dashboard.

### 0.3.0 — billing, attendance, mobile
**Monthly charges** on the 1st, first month after joining free, absence
irrelevant to billing, per-student pause. Preview before creating; idempotent,
so cron and the button cannot double-charge.

**Attendance** per class and session, with configurable statuses, built for a
phone: whole class on one screen, one tap each, one save, bulk-set to correct
from.

**Mobile-first pass** on the densest screens, driven by measurement rather than
taste: the attendance control was rebuilt after five labels were found
truncating and overlapping at 390px.

### 0.4.0 — transactions, undo, tests
**One transaction per write**, nesting safely through savepoints, so a failure
halfway leaves nothing half-applied and a repeated submission creates nothing.

**Undo.** Changes to fourteen tables record what the row looked like before and
after, with a button to put each one back — including undeleting a student under
their original number. This closes the old item 8 below.

**A test suite that runs without MySQL.** `php tests/run.php` boots the real
application against a disposable database built from the real migrations,
covering dates, transactions, billing, security, attendance, settings, history,
query counts, the rendered pages, and the shape of the source. It proves the PHP
logic, not the MySQL dialect — item 1 below still stands.

**Query counts held down where they grow with the roll**: the student list went
from 56 queries at sixty students to 6, and the suite fails if that comes back.

---

## Next — before she uses it

These are the things that stand between "the code is good" and "her data is
safe in it". Nothing below is optional.

1. **Run the migrations and the test suites against MariaDB or MySQL.**
   Migrations 002 to 006 have never executed against MySQL or MariaDB anywhere.
   Use a disposable database, then `CRM_TEST_DRIVER=mysql php tests/run.php`,
   which runs the same checks against the real engine and reports what the
   SQLite driver could not cover. Then `tests/integration.py` and
   `tests/smtp_integration.py` per `tests/README.md`. The update runner is
   verified idempotent, but only against SQLite.
2. **Backups.** You said you will handle these yourself, so this is not on my
   list — one thing to know: the config file holds `app_key`, which is what
   decrypts the stored SMTP password and any queued mail. A database dump
   without that key restores your records but not those. Back it up too, and
   separately.
3. **Re-run `composer audit`** somewhere with network access. It could not be
   verified here.
4. **Finish the privacy notice.** The drafts are placeholders. Real operator
   details, the hosting and SMTP providers, retention periods, and a decision
   on how sickness absences and minors' data are handled — the draft flags
   these as open. Invitations stay disabled until both languages are complete,
   which is the right default.
5. **Set up the cron jobs and confirm they run.** `mail:work` every minute and
   `maintenance` nightly, per `INSTALL.md`. Without the first, no email is ever
   sent, and the retry backoff added in this review needs it to do anything.
6. **Send yourself a test invitation end to end** — invite, receive, activate,
   set a password, sign in, reply to a message — before inviting a parent.

## Then — the things she will ask for

Ordered by how likely I think each is to come up in her first month.

7. **Export.** CSV of students, payments and assessments, so her data is never
   hostage to this app and she can hand her accountant a file. Use a library
   rather than hand-rolled CSV escaping.
8. **A calendar or term view.** "Who is at training on Thursday" is a question
    the absence data can already answer but nothing asks.
9. **Push notifications for a new message.** Web push works in standalone
    iOS web apps from iOS 16.4, so the manifest added in this review is the
    prerequisite. Email notification already exists and may well be enough —
    worth asking before building.

## Later — worth doing, not worth doing first

10. **Search across messages and notes.** Fine without it at her scale.
11. **Rename and edit saved filters.** Currently create and delete only (A22).
12. **Two-factor authentication** for the admin account. Password plus
    invitation-only access is a reasonable posture for a family app; this is
    hardening, not a gap.
13. **An audit-log viewer.** Everything is recorded in `audit_log` but nothing
    displays it. The "Änderungen" page added in 0.4.0 shows record versions,
    which is the part an operator acts on; this is the separate, never-rewritten
    log of what happened.
14. **Prune `audit_log` and `consent_log`.** They grow without bound. Not a
    problem for years at this scale, and both are records you may want to keep
    deliberately rather than expire — decide the retention period as part of
    item 4.
15. **Batch attendance entry.** Only worth it if she starts tracking
    per-session attendance, which today she does not.

## Explicitly not planned

- **Online card payments.** A payment processor brings PCI scope, a contract
  and a fraud surface. Bank transfer recorded by hand is the right answer here.
- **Replacing this with an off-the-shelf CRM.** Reasoning in AUDIT.md.
- **A JavaScript framework.** Server-rendered HTML with ~60 lines of
  JavaScript is why this app is fast on an old iPhone and will still run in
  five years. Keep it.
- **Multi-tenancy.** One coach, one install.

---

## Keeping it running

- Read [UPDATING.md](UPDATING.md) before any update. Take a backup, switch on
  maintenance mode, migrate, switch it off, and compare `console.php check`
  counts before and after.
- Keep `app_key` stable across updates. Rotating it makes sealed SMTP
  credentials and any queued mail unreadable (and, per A8, used to abort the
  mail run outright).
- Stay on a supported PHP version. 8.2 receives security fixes until December
  2026; 8.3 or 8.4 buys more runway on a new server.
- After any change to the interface, re-check a real phone in both light and
  dark. Three of the defects found in this review were invisible in code and
  obvious in a screenshot.
