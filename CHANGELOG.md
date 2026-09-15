# Changelog

## 0.4.0 — unreleased

Transactions, undo, and a test suite that runs anywhere.

- Every write is wrapped in one transaction that either completes or leaves
  nothing behind. Nesting uses savepoints, so a helper does not need to know
  whether its caller already opened a transaction, and an inner failure a caller
  chooses to handle no longer discards the outer work. Leaving a request with a
  transaction still open is logged rather than silently rolled back on teardown.
- A repeated form submission is refused by the database, not by a check that two
  simultaneous submissions could both pass.
- Record versioning with undo. Changes to fourteen tables store what the row
  looked like before and after, shown on an "Änderungen" page with a button to
  put each one back. An undo writes back only the columns that change touched,
  so undoing an old edit does not also undo every later one. Deleting a student
  is now a mistake to reverse rather than a restore from backup: the row is
  re-inserted under its original number so everything referring to it lines up
  again. The undo is itself recorded, so the history stays complete.
- Rate-limit counters are kept on a second connection, so a failed action still
  counts against the limit instead of rolling its own counter back.
- Balances for a list of students are resolved in one query. The student list
  went from one query per card — 56 with sixty students — to six for the page.
  The billing preview and run, and the dashboard's absent-students tile, had the
  same shape and were batched the same way.
- A test suite that needs no MySQL: `php tests/run.php` boots the real
  application against a disposable database built from the real migrations, and
  covers dates, transactions, billing, security, attendance, settings, history,
  query counts, the rendered pages and the shape of the source itself. This
  proves the PHP logic, not the MySQL dialect — see AUDIT.md.
- The rule for what counts as a received payment — confirmed and not voided —
  was written out in six places across three files, where every balance, the
  overdue filter and the payments screen each carried their own copy. It is now
  `payment_counts_sql()` / `charge_paid_sql()` in one place, producing the same
  SQL, and a test fails if it is spelled out again.
- One `sql_name()` replaces four hand-written checks on table, column and alias
  names that had drifted into three different patterns; the one guarding
  `lock_row()` rejected any table name containing a digit.
- `console.php version` no longer needs a configuration file. Asking which
  release a directory holds is something you do *before* linking a config into
  it, which is exactly when the old version refused to answer.
- README gained install and update guides as runnable bash, and CLAUDE.md
  records the conventions for changing this code.
- Migration 006.

## 0.3.0 — unreleased

Monthly charges, attendance, and a mobile-first pass.

- Monthly charges on the 1st of each month. The first time the calendar reaches
  a 1st after a student joins, that month is free; billing starts the month
  after. Being away changes nothing - absence and billing are deliberately
  unlinked. The trainer can pause billing for one student without ending their
  membership. Nothing is created until somebody runs it, from the payments
  screen after a preview, or from cron; every generated charge carries a unique
  key so a repeat run creates nothing.
- `billing:plan` shows what would happen and changes nothing; `billing:run`
  does it.
- Attendance per class and session date, with configurable statuses. Built for
  a phone held in one hand: the whole class on one screen, one tap per student,
  one save, and a bulk "everyone present" to correct from. Summary and recent
  sessions on each student's page.
- Mobile-first pass: the attendance control was rebuilt after measuring that
  five operator-defined labels truncated and overlapped at 390px. Choices now
  wrap instead of clipping, "not recorded" moved next to the name so the
  statuses fit one row, and the default status set is three so every label fits
  on one line at a readable size. A student's row went from 370px to 132px.
- Migration 005.

## 0.2.0 — unreleased

Roles, classes, payment QR codes and skill assessment.

- Roles are administrator, trainer and student. Administrators configure
  everything; trainers do the day-to-day work. `manager` is renamed to `trainer`
  by migration and still accepted on read.
- Training classes with a schedule, tariff, bank details and members. A student
  can belong to several; leaving is recorded rather than erased.
- Skill assessment for staff: configurable rating scales, skill areas, skills,
  dated values with notes, inline progress charts, and grouping into bands whose
  thresholds are a setting. Not visible to student accounts.
- Payment profiles with IBAN checked by its mod-97 checksum, and a transfer QR
  code for outstanding amounts. The payload comes from an editable template
  defaulting to the SEPA EPC069-12 format. Generated on the server; no external
  service is contacted.
- Payment reminder email as a third notification category, with its own
  preference and unsubscribe link.
- Online status per account.
- Maintenance mode switchable from the settings screen, with an administrator
  bypass so it cannot lock the operator out.
- `php bin/console.php update`: maintenance on, migrate, compare record counts,
  maintenance off, refusing to reopen if any count dropped. `create-admin` gains
  `--force` for a deliberate second administrator.
- Every operator setting is declared once in `app/defaults.php` with a type and
  a default, and rendered from that declaration, so a missing row is never
  undefined and a new setting needs no migration.
- Plainer wording throughout, and a parent's dashboard reduced to what concerns
  them.
- Migrations 004.

## 0.1.0 review — included in 0.2.0

Review of 0.1.0: bug, security and design findings and their fixes. Full detail
in AUDIT.md, priorities in ROADMAP.md.

Security:

- Rate limit counters are written on a separate connection, so a failed attempt
  no longer rolls back the hit that counts it.
- The unsubscribe category is mapped through a whitelist instead of being
  interpolated into the SET clause.
- Passwords are checked against a small blocklist and a repetition rule, not
  length alone.
- SMTP password redaction no longer aborts a mail run when the app key differs.
- Added Cross-Origin-Opener-Policy, Cross-Origin-Resource-Policy and
  X-Permitted-Cross-Domain-Policies, plus per-directory deny rules.

Fixed:

- Stored UTC timestamps were relabelled rather than converted, so records
  written late in the day showed the previous date.
- Failed email stayed failed; it now retries with backoff, security mail
  excepted.
- current_user() is resolved once per request instead of per call.
- A mail run started from the outbox now respects max_execution_time.
- fmt_date() no longer raises a TypeError on an unparseable value.
- The migration runner no longer splits on a semicolon inside a quoted string.
- A repeated form submission reports a repeated submission.
- An empty student status is rejected on create; account deletion accepts a
  correctly typed email in any capitalisation; `check` works before the first
  migrate.

Added:

- Dark appearance following the device, with per-account appearance and text
  size settings.
- Web app manifest, Apple touch icons and meta tags, so the portal installs to
  a phone home screen as an app.
- Unread markers per account in the sidebar, mobile tab bar and conversation
  list.
- Migration 002 (mail retry state, read markers, indexes for the queries the
  application issues) and migration 003 (appearance preferences).
- Touch targets raised to a 44pt minimum at phone widths.

## 0.1.0 — 2026-09-15

Initial PHP/MySQL release for self-hosting.

- Invitation-only verified accounts, multiple students per account, roles, suspension and account deletion.
- Student records, contacts, dates, absence reporting, configurable fields and archived values.
- Tariffs, individual prices, manual charges and confirmed/partial payments.
- Private messages, filtered recipient previews, editable templates and news subscriptions.
- SMTP queue, outgoing status, encrypted credentials and unsubscribe links.
- German/English responsive interface and editable privacy drafts.
- Migration `001_initial.sql`, maintenance switch, count/total checks and documented update procedure.

The initial migration is for an empty application database. No migration from a previous JavaScript/Sites implementation is included. No live student data has been imported into this release.
