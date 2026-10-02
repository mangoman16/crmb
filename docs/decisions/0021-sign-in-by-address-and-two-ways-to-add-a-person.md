---
status: accepted
date: 2026-10-01
---

# 0021. Sign in by address, and two ways to add a person

## Context

The owner, on 2026-10-01: "reverse the username support, also drop sqlite support, mariadb is
enough … each human: 1 email address … option 1: send invitation link, person registers and opts
into a course themself. option 2: trainer adds information and email, email needs to be verified
and a password must be set, but the trainer could then opt them in directly." The portal is in
testing, with no real families yet. She asked that the project stop moving in circles, so this
record states each decision once, with its reason, and does not retell 0019 or 0020.

`accounts.email` is unique (001), and one login is one student (`student_one_account`, 019).
022 and 023 added `accounts.username`, `NOT NULL DEFAULT ''`, with the unique index
`account_username`; they may have run on her test server, so they stay. A student login with no
student is allowed, and `orphan_logins()` calls every one "left behind by a deleted student".

## Decision

### 1. The address is the only name a login has

- Sign-in, „Passwort vergessen" and the confirmation for deleting a login take the e-mail
  address. Usernames leave the database, the code, the mails and the pages.
- What 0020 §3–§4 built around the lookup stays, for the address alone: the dot-atom gate, the
  exact match, one `password_verify()` on every path, no row lock on sign-in, the conditional
  rehash, one refusal text, and the throttle counted under the typed address, never the row. A
  login has one bucket again: 10 sign-ins a quarter hour, 3 mails an hour.
- The sign-in box keeps `autocomplete="username"`, which is what iOS saves a password against;
  the activation and reset pages show the address read-only beside the new password.
- **Migration `024_sign_in_by_address_only.sql`**, one statement:
  `ALTER TABLE accounts DROP INDEX account_username, DROP COLUMN username;`
  The column cannot stay unused: unique with a default of `''`, it would refuse the second login
  made without a username (23000). No foreign key uses it; no row is removed.
- `database/defaults.php` stops calling `give_every_account_a_username()` in the same commit.
  After 024 that call would fail on every request and keep the portal closed.

### 2. One person, one login, one own address

Unchanged from 0020 §1 and §5, for every role: `refuse_address_in_use()` is the only refusal of
an address, and nobody sets another person's password.

### 3. Two ways to add a student

Both are for staff, administrator or trainer. Both end with the person opening a link at the
address, which proves it, and choosing their own password.

**Option 1, „Per E-Mail einladen".** Staff type an address and the invitation language.

- New case `email_invite`, `require_staff()`. Before any write it refuses an invalid address, an
  address in use, mail that is not ready, and an address that a student without a login already
  carries. That last refusal names the student and points to their „Einladung senden";
  otherwise one person ends up with two records.
- It makes a `role='student'` login with `name=''` and no student, and sends the invitation.
- The activation page decides from the locked token, never from the post: a student login with
  no student is also asked for first name, last name and birth date. `create_own_student()` then,
  in the action's one transaction, inserts the student with those three posted values, the
  login's stored address, the token's `account_id` and the create form's defaults
  (`new_student_defaults()`, which the form uses too); records it with `tracked_insert()`, naming
  the token's login as actor; and sets `accounts.name`. A staff login, or a student login that
  already has its student, never creates one, whatever is posted.
- Once they are signed in, `notify_staff()` names them and links to their student page.
- They land on their own page, where `family_next_steps()` lists „Kurs wählen" until they have a
  current enrolment or a pending join request. Choosing is the existing join request
  (`enrolment_request`), which the trainer approves: joining creates charges (ADR 0011), so
  billing stays her decision.
- Open invitations are listed for staff, with resend (`account_state` `reinvite`) and withdraw
  (`account_state` `delete`). The designer decides where.
- There is no public sign-up: every link is made by staff for one address, opens once, and lapses
  after 48 hours.

**Option 2, „Schüler anlegen".** As today (0020 §6): staff create the student with details and
address, may enrol them directly, and invite. The activation page asks for no details.

**The explicit actor.** `activate`, with nobody signed in, stays the only code that names one,
now for the record it creates instead of a username. `tracked_insert()` gains the optional
`?int $actor` that `tracked()` has; `activate` passes the locked token's `account_id`.

### 4. An open invitation is not an orphan

Both are student logins no student points to. `verified_at` says which: the first activation sets
it, and nothing clears it.

- Open invitation, `open_invitations()`: `a.role='student' AND a.verified_at IS NULL AND NOT
  EXISTS (SELECT 1 FROM students s WHERE s.account_id=a.id)`.
- Orphan, `orphan_logins()`: the same, with `a.verified_at IS NOT NULL`.

That holds only while a login never set up cannot lose its student. So `student_delete` also
deletes the student's login when its `verified_at IS NULL`, in the same transaction, with its
link and waiting mail. Left standing, the live link would let the person rebuild the record she
had just deleted. A login that was set up stays, as an orphan on Konten (ADR 0010).

### 5. Staff logins

An administrator invites them on Konten, as today, with name and address. `account_invite`,
`invite_student()` and `email_invite` insert the invited login through one function,
`invite_login()`, which asks `refuse_address_in_use()` and whether mail is ready.

### 6. The tests run on MariaDB only

`tests/sqlite-driver.php` and every SQLite branch go. The suite runs through
`tests/mariadb-local.sh` or `tests/existing-database.sh`; without a database, `php tests/run.php`
stops with a sentence naming both. The documents say what has been tested: MariaDB 10.11 on PHP
8.4. No MariaDB-only SQL is written until the owner has said which engine her host runs.

## Rejected

- **`DROP COLUMN IF EXISTS`, or 024 as two statements.** The first could run twice on MariaDB,
  but MySQL refuses it (§6); a stop between two statements leaves a file that cannot run again.
- **A sign-up page, or one link for everybody.** Bots and spam (0020 §5).
- **Joining a course without the trainer's yes.** Joining bills.
- **Asking for the details after sign-in, on a page of their own.** A second way to create a
  student, open to every signed-in login without one, orphans included. On the activation page
  the token proves the person, and the record is made in the transaction that activates.
- **A column marking an invitation without a student, or telling the two apart by
  `state='invited'`.** `verified_at` already says it, and by state a suspended invitation would be
  listed as left behind by a deleted student.
- **Making the student at invitation, with placeholder names.** Names nobody typed would sit in
  her lists, counts and reports.
- **Keeping SQLite as a quick first run.** The owner chose one engine; a translation proves the
  PHP, not the SQL, and keeping it in step has cost work in nearly every migration.

## Consequences

- **Records and actions.** 0019 is superseded; 0020, 0010 and 0005 are amended, each with a note.
  The number of actions is unchanged: `username_change` goes, `email_invite` comes.
- **Load order.** No new file. `create_own_student()` and `invite_login()` are in
  `app/actions.php`; `open_invitations()` and `new_student_defaults()` in `app/domain.php`, which
  calls `level_default()` (`app/groups.php`) at request time only, with a comment saying so.
- **`accounts.name` is `''` until setup.** The mail's greeting then names nobody, and staff see
  the address where a name would be. Old change-log lines keep their „Benutzername" label.
- **Her test server.** The new list may show invitations whose student was deleted earlier. She
  withdraws any she does not recognise.
- **Must stay true:**
  - only a student login with no student creates one, only on the activation page, and from that
    post only first name, last name and birth date are read;
  - only `activate` names an actor, and only the token's own login;
  - a login never set up never outlives its student;
  - `students.account_id` is written only by `invite_student()`, `create_own_student()` and
    `demo_fill()`'s example rows;
  - every `INSERT INTO accounts` is in `invite_login()`, `create_admin_account()` or
    `demo_fill()`, each asking `refuse_address_in_use()` first;
  - 022 and 023 are never edited.

## In plain words, for the owner

- Everybody signs in with their own e-mail address and their password. There are no usernames.
- To add somebody, either send an invitation to their address, and they type their own name and
  birth date and ask for a course, which you approve; or create them yourself, put them in a
  course, and send the invitation. Either way they choose their own password.
- The update needs nothing from you. Tests now run only on MariaDB.
