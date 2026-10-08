---
status: proposed
date: 2026-10-08
---

# 0032. How long the portal keeps what it holds

> **Proposed on 2026-10-08; the owner decides.** Drafted by the architect at the project manager's
> request, after the whole-portal security audit of the same day, from the working tree as read that
> day. Each period below is a proposal and becomes one setting, which can be changed later; nothing
> is built until the owner has chosen. Two lines are already decided and are here so that the list
> is whole: accounting records stay seven years (BAO § 132), and the bodies of mails that are not
> security mails are emptied after 90 days, which is being built.

## Context

### What the portal keeps today

Read from the working tree on 2026-10-08:

- **For ever:**
  - messages and their photos, a removed message included: it keeps its text and photo so that
    staff can put it back (0022 §4);
  - absences, sickness among them, which is health data (Art. 9 DSGVO);
  - attendance, with its notes;
  - the notices in the bell, of which a chat notice quotes the message (0022 §9);
  - the audit log (`audit_log`: who did what to which record, and when, with no content), kept on
    purpose as evidence, as the comment in `prune_expired()` says;
  - the consent records (`consent_log`), which go only with their login (`ON DELETE CASCADE`);
  - the rows of the mail sent (`mail_jobs`: recipient, subject, status), and the bodies of mails
    that are not security mails;
  - payment proofs, the photos or PDFs of a transfer that families upload, and their files;
  - the accounting records: charges, payments, invoices and their charges.
- **Already with a horizon:** a link or token at its expiry; throttle counters after a day; form
  identifiers after a week; problem reports 30 days after they are marked done, and automatic error
  entries 30 days after they last happened (0009, 0012); the change log „Änderungen" after
  `history_months`, 24 by default; a security mail's body once it is sent; unsubscribe links after
  90 days; and the copies in `storage/backups`, of which the newest five stay (`BACKUP_KEEP`).
- **The privacy drafts** have no periods: their section „6." is a bracket asking for "specific
  retention periods or clear criteria". `ROADMAP.md` keeps "How long `audit_log` and `consent_log`
  are kept" under „Later, not scheduled".

### How a horizon is built here

A setting and one line in `prune_expired()` (`app/tick.php`), which the background work runs once a
day and the console's nightly job runs too. `history_months` and `history_prune()` are the pattern.
The prune deletes rows first and files last (`prune_uploads()`), so a file whose row has gone goes
the same night.

### What Austrian law says

One period applies here by law: books, records and the receipts that belong to them are kept seven
years, from the end of the calendar year they concern, and longer while a pending proceeding needs
them (BAO § 132 Abs. 1). For everything else the rule is the GDPR's: kept no longer than its purpose
needs (Art. 5 Abs. 1 lit. e DSGVO), and health data only as far as strictly needed (Art. 9 DSGVO).
Where a period below leans on a limitation period, it is the three years in which a claim for
damages can be brought once the harm and who caused it are known (§ 1489 ABGB).

## Decision (proposed; the owner decides)

### 1. The periods

| What | Where | Proposed | Why |
| --- | --- | --- | --- |
| Messages and their photos | `messages`; `message_files` and `storage/uploads/message` go with them | 12 months after sending | A season: what a course's group needs to read back. No law sets a period. |
| A removed message | the same, where `removed_at` is set | 30 days after it was removed | Its text is kept only so staff can put it back (0022 §4). A month is time enough to notice a mistake. |
| Absences | `absences` | 3 months after the absence ended | Sickness is health data. It is needed to plan the next trainings and explain a missed one, not to keep a child's history of illness. One period for every reason, because a club adds reasons of its own (`absence_reasons`), and a prune could only know `sick`. |
| Attendance | `attendance` | 24 months after the session | This season and the last, to compare. If a funding body asks the club for attendance lists, its period applies instead; the owner knows whether one does. |
| Notices in the bell | `notifications` | 90 days after they were made | A notice points at something kept elsewhere, and a chat notice quotes the message. |
| Mail sent | `mail_jobs` | 12 months after it was queued | Enough to answer "did the invitation, or the reminder, go out?" for a season. The body goes earlier (next row). |
| Mail bodies | `mail_jobs.payload` | a security mail's when it is sent (built); any other after 90 days (decided, being built) | A body holds what the mail said, a link that signs in included. |
| Payment proofs | `payment_proofs`; `storage/uploads/proof` goes with them | 24 months after the upload | A proof only speeds up confirming a payment; the club's own receipt for a transfer is its bank statement. Two years cover the year it was paid in and the next, in which the club's accounts for it are drawn up and checked (VerG § 21). If the club's accountant counts a proof as a receipt, it stays seven years with the payment instead. |
| Audit log | `audit_log` | 3 years | Who did what and when, with no content: the evidence for as long as a claim can be brought (§ 1489 ABGB). Its entries about money could instead follow the accounting records' seven years. |
| Consent records | `consent_log` | the current answer, as long as its login exists; an answer replaced by a later one, 3 years after it was replaced | The club must be able to show a consent it relied on (Art. 7 Abs. 1 DSGVO), for as long as a claim can be brought (§ 1489 ABGB). |
| Accounting records | `charges`, `payments`, `invoices`, `invoice_charges` | 7 years from the end of the calendar year they concern, longer while a proceeding needs them | BAO § 132 Abs. 1. Decided. Nothing deletes them by itself (§2). |

### 2. How it is built, once chosen

- **One setting per period**, declared in `app/defaults.php` beside `history_months`: kind `int`,
  with bounds, group `system`, `advanced`, a label such as „Nachrichten aufbewahren (Monate)" and a
  hint that says what goes. Named like `history_months`: `messages_months`, `removed_messages_days`,
  `absences_months`, `attendance_months`, `notices_days`, `mail_months`, `proofs_months`,
  `audit_months` and `consent_months`. No setting for the accounting records: seven years is the
  law, not a club's choice. No migration: a setting needs none (`CLAUDE.md`), and every column a
  line reads is there (`created_at`, `removed_at`, `ends_on`, `session_on`).
- **One line per period in `prune_expired()`**, before `prune_uploads()`: one `DELETE`, its cutoff
  a parameter worked out from its setting. `message_files` goes with its message (`ON DELETE
  CASCADE`), and the files with `prune_uploads()` the same night. `ends_on` and `session_on` are
  calendar dates, so their cutoff is a date counted back from `today()`, not a UTC time. The
  consent line keeps the newest answer of each login and purpose, counting the picture switch's two
  purposes (0031) as one.
- **The guard.** `messages`, `message_files`, `absences`, `attendance`, `payment_proofs` and
  `consent_log` are in `schema_guarded_tables()`, and they stay there. The guard compares the counts
  across one update; the prune never runs inside one. The update's own step calls `prune_uploads()`,
  not `prune_expired()`; the background work does not run while the portal is closed or a copy is
  being imported (0027, 0029); and the console then runs nothing that writes. Fewer rows between two
  updates is what deleting a student already causes.
- **Nothing deletes accounting records by itself.** No line in `prune_expired()` touches `charges`,
  `payments`, `invoices` or `invoice_charges`. They go only with their student, by a person, and a
  student with charges cannot be deleted today (`ON DELETE RESTRICT`).
- **The copies.** A row the prune deletes stays in the copies in `storage/backups` until five newer
  copies have replaced it (`BACKUP_KEEP`). A copy is made before every update, so that can take
  months. The privacy notice says so.
- **The first prune after the release** deletes everything already past its period, at most a day
  after the upload. `UPDATING.md` says so, and that the periods can be set right after the upload.

### 3. Not in this record

- **The member's record and the login:** names, birth date, contacts, enrolments, requests to join
  a course. A member's record is what the accounting records hang on, and it is deleted by a person,
  never by a prune. When a former member's record may go, after the accounting years, is a decision
  of its own, with a screen of its own, after this one.
- **A consent record outliving its login.** Today it goes with the login (`ON DELETE CASCADE`).
  Whether a deleted login's consents must stay as proof is part of the legal check before real
  families (0031's note, 3).
- **What is decided and built already** (Context): problem reports, error entries, the change log,
  links, counters, form identifiers, and the mail bodies. This record changes none of them.

## Rejected

- **Keeping everything, as today.** It keeps data longer than its purpose needs (Art. 5 Abs. 1
  lit. e DSGVO), and a sickness from three years ago is nobody's business.
- **One period for everything.** Seven years for a chat message, or one year for an invoice: either
  breaks a rule.
- **A shorter period for sickness than for other absences.** The reasons are each club's own list, so
  the prune could know only `sick`, and a club's „Verletzt" would be kept for ever. One short period
  for every absence protects all of them.
- **Deleting accounting records after seven years by a prune line.** A pending proceeding extends the
  period (BAO § 132 Abs. 1), and a charge cannot go without its student; a person decides.
- **Anonymising instead of deleting**, such as a message with its sender blanked. The text itself
  names people and says things about them; no rule can anonymise a chat.
- **Periods as constants in the code.** A club in another country, or one whose accountant asks for
  more, could not change them without a release (`CLAUDE.md`, "Another club could run it too").
- **A prune of its own, in a new file or a job of its own.** `prune_expired()` already runs nightly,
  outside updates and imports, and deletes files after rows. A second job is a second place to keep
  those rules.

## Consequences

- **When the owner has chosen**, this record becomes `accepted` with the chosen numbers, and:
  - `backend-dev`: the nine settings and the nine lines, and the comment above `prune_expired()`,
    which says that nothing there removes a message and that the audit log is kept for ever;
  - `ui-ux-designer`: what a chat whose messages are all past their period shows;
  - `qa-tester`: for each line, a row a day past its period goes and a row a day short of it stays,
    nothing else in the table changes, and its files go with `prune_uploads()`; the newest consent of
    a login and purpose stays however old; nothing is pruned while the portal is closed or a copy is
    imported; `structure`: no `DELETE` of `charges`, `payments`, `invoices` or `invoice_charges` in
    `prune_expired()`, and every period is a setting declared in `app/defaults.php`;
  - `docs-writer`: the privacy drafts' section „6.", in German and English, from the table, with the
    copies; `UPDATING.md`, with the first prune; `TESTING.md`;
  - ADR 0022 §4 is amended: a removed message can be put back for 30 days, not for ever;
  - `ROADMAP.md`: "How long `audit_log` and `consent_log` are kept" leaves „Later, not scheduled".
- **Load order:** unchanged. No new file, no dependency, no migration.
- **Must stay true:**
  - a prune line deletes only by its own setting;
  - accounting records are never pruned;
  - a pruned row's files go the same night;
  - the prune never runs inside an update or while a copy is imported.

## In plain words, for the owner

- Today the portal keeps nearly everything for ever. The law asks that personal data is kept only as
  long as it is needed, and health data, such as „krank", as short as possible.
- Proposed: chat messages and photos a year; a message the trainer took down, a month; absences
  three months after they end; attendance two years; the bell's notices three months; the record of
  sent mail a year; payment receipts two years; who did what in the portal three years; and each
  consent three years after it was changed.
- Invoices, charges and payments stay seven years, as Austrian tax law requires (BAO § 132). The
  portal never deletes them by itself.
- Every period becomes a setting you can change later. Say which numbers you want, or that these
  are fine, and the project manager has it built.
- What is deleted stays in the last five safety copies until newer ones replace them. The privacy
  notice will say so.
