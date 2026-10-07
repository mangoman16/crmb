---
status: accepted, amended by 0026
date: 2026-10-06
---

# 0023. Every student has a login, a wizard adds one, and one-time sign-in links

> **Superseded in part by ADR 0026 (2026-10-07).** Custom fields, the printed data sheet and
> „An mehrere schreiben" are gone. These parts no longer hold:
>
> - in §3, `views/print.php` among the readings of "has a login";
> - in §5, "custom fields at `'edit'`" among what a family writes;
> - in §9, that „An mehrere schreiben" is still on its own page;
> - in Consequences, that 0020 is not rewritten: since 2026-10-07 its status line names 0023, and a
>   note of its own says which parts this record changed.
>
> With the owner's word on 0026's †, "presence as the viewer may see it" in §8's „Schüler" card goes
> too. Everything else stands.

> **Accepted** on 2026-10-06. The owner approved the schema changes in advance, to the project manager:
> "make the database change if you deem it necessary"; 028–030 are the ones this record needs. They
> change the database, and she has no way to undo a migration that has run (`CLAUDE.md`, "Stop and ask"). That usernames come back is her
> own decision of 2026-10-05, not this record's. This record partly reverses ADR 0021, and 0021's note
> says which parts.

## Context

### What the owner asked for

The owner, on 2026-10-05:

> "Also make sure creating a student has its own dedicated button (multiple quick action creates on
> the start page) / it should be a wizard that guides a trainer through the registration / one
> option should be to use email, where the person then receives an invitation link and can enter or
> change all information the trainer has entered or left out / or create an account with username
> only, where a QR code will be shown (option to share) and an SSO LINK, which then allows 1 time
> login and can be shared for login to complete and registration to complete / option to create sso
> logins should also be possible / impersonating should also be possible / the accounts section
> should then contain categories: trainers, admins, students / there should be no student ever in a
> course without an account, but it should also be possible that she completes the registration
> herself and is a dummy account, that should be possible too, and now that usernames are allowed,
> no email login should also be possible, but ideally discouraged. / the chat function should be the
> absolute basics … the program is ai made and should keep the highest security standards / make
> sure all markdowns and plans are adjusted and accurate"

Asked what the start page should offer: "start page buttons should be useful for the trainer — for
now only creating a new student".

The chat half of her message is ADR 0022 §11. This record covers the rest.

### What the design must answer to

- **`ui-ux-designer`** specified the screens on 2026-10-05 (`docs/design/2026-10-05-accounts-and-chat-screens.md`). The specification lists eleven server rules it assumed, A1–A11, for this record to
  confirm or overrule. §12 does so, one by one.
- **The project manager** settled the designer's open issues, binding unless
  the owner overrules them:
  - sign-in links last 48 hours;
  - first and last name are required;
  - the password minimum stays 12;
  - staff logins get no sign-in links;
  - the start page has one quick action;
  - chats between two students close;
  - students without a login get placeholder logins in the update;
  - the portal has no undo. `app/history.php`'s `tracked()` records history only.

  §12 confirms each, or says where this record differs.

### What the code does today

Read from the code, not assumed:

- **Logins.**
  - `accounts.email` is `VARCHAR(254) NOT NULL UNIQUE` (001, index `email`). The username column went
    with 024.
  - States are `invited`, `active` and `suspended` (`account_state`). `current_user()` keeps a
    session only for an `active` login with `verified_at` set.
  - `invite_login()` makes staff logins and logins by address alone, `create_admin_account()` makes
    the first administrator, and `demo_fill()` makes the example logins.
- **Students and logins.**
  - `students.account_id` is nullable, `ON DELETE SET NULL` (001), and unique
    (`student_one_account`, 019).
  - A student without a login is ordinary. 13 of `demo_fill()`'s 15 example students have none, and
    the create form makes one unless „Gleich einladen" is ticked (0020 §6).
  - „Zugang löschen" on the access card deletes a student's login; the foreign key then empties the
    student's `account_id`.
- **Enrolment.** There are two copies of `INSERT INTO class_students … ON DUPLICATE KEY UPDATE`, in
  `class_member_add` and in `decide_request()`. Neither asks whether the student has a login.
- **Links.**
  - `make_token()` stores only the `sha256` of 32 random bytes. It replaces the login's earlier link
    of the same purpose.
  - Opening `?page=activate&token=…` is a GET. It stores the hash in the session and redirects to
    the address without the token. Only the `activate` POST uses a link up.
  - Every response carries `Referrer-Policy: no-referrer` and `Cache-Control: no-store`.
  - 0020 rejected "letting staff copy a reset or invitation link to send another way", because "a
    link that sets a password is as good as the password".
- **Viewing as somebody.** `may_impersonate()` refuses the viewer's own login and any login not
  `active`. An administrator may view anybody else; a trainer anybody who is not staff.
- **QR codes.** `qr_svg()` (`app/qr.php`) draws an inline SVG with `bacon/bacon-qr-code`, which is
  already a dependency.
- **The start page.** Staff land on the Übersicht (`dashboard`). `?page=start` is the
  administrator's setup checklist (ADR 0011), which a trainer never sees.

## Decision

### 1. A username, as the sign-in name of a login without an address

**What a username is.** It is the alphabet of 0019 §1, with a shorter limit:

- `a–z`, `0–9`, `.` and `-`;
- 3 to 30 characters;
- it starts with a letter and ends with a letter or a digit;
- no two separators in a row;
- as a pattern: `/^[a-z](?:[a-z0-9]|[.-](?=[a-z0-9])){2,29}$/D`.

There is no `@`, so a username and an address can never be the same text. There is no underscore
(§12, A1). It is stored lower case, always.

**Unique, ignoring case.** Nothing but lower case is ever written: `username_value()` is the only
way a typed username reaches a write. The unique index compares under `utf8mb4_unicode_ci` as a
second guard, not as the rule.

**Who has one.** Only a student's login that signs in without an address. Staff logins keep a
required address: an administrator recovers her login by mail, and the owner's words were about
students. An address and a username on one login is possible in one case only: a username login
whose holder later adds an address under Mein Konto (below).

**How it is made.** The wizard and the access card suggest one; staff may change the suggestion
before saving.

- The suggestion is first name, dot, last name, with `ä→ae`, `ö→oe`, `ü→ue`, `ß→ss`, and a number
  only when the name is taken. This is 0019 §2's `username_from_name()` and
  `username_first_free()`.
- Those come back to `app/core.php` with `username_normalised()` and `username_value()`, as pure
  functions.
- `username_for_new_account()` comes back to `app/auth.php`. It reads with a lock and must be
  called inside a transaction. Without `_` in the alphabet, its `LIKE` pattern needs no escaping.
- A taken username is refused with a free one suggested. Staff see every username anyway, so this
  answer tells them nothing new.

**Who sees it.** The holder and staff. Never another family (0019 §8).

**Who changes it.**

- Before the login is first used, staff do it by deleting the login on the access card, which
  leaves a placeholder (§4), and giving a new username.
- After that, nobody, in this round. 0019 §8's `username_change` is designed and can come back if
  she asks.

**E-mail stays the recommended way**, and a username is "ideally discouraged":

- the wizard puts e-mail first, marked „Empfohlen";
- the username card says, before anybody taps, what is lost without an address: no „Passwort
  vergessen", and no invoices, reminders or notices by e-mail;
- Mein Konto of a username login offers „E-Mail-Adresse hinzufügen". This is `email_change` as it
  is today, confirmed from the new mailbox. Afterwards the address and the username both sign in.

### 2. Migration 028: one `ALTER`

```sql
ALTER TABLE accounts
    MODIFY email VARCHAR(254) NULL DEFAULT NULL,
    ADD COLUMN username VARCHAR(30) NULL DEFAULT NULL AFTER email,
    ADD UNIQUE KEY account_username (username);
```

- **One table, one statement.** An interrupted run leaves it either applied or not. ADR 0022 §10's
  rule is against two statements in one file, which is what a restart cannot recover from.
- **`NULL` means "none", for both columns.** A unique index lets many rows share `NULL`, and only
  `NULL`. `''` would refuse the second login without a username (0021 §1).

  `CLAUDE.md` forbids a NULL "the new code has to guess about". This one has a single meaning.
  Every row written by the previous version keeps its address and gets `username = NULL`, which is
  true of it.
- **`email` keeps its unique index from 001.** The name `account_username` was freed by 024.
  `VARCHAR(30)` holds what the pattern allows and no more.
- **Nothing is deleted.**

### 3. A fourth state: `placeholder`

| State | Signs in with | Password | Reached by | Can sign in |
| --- | --- | --- | --- | --- |
| `placeholder` (new) | nothing: no address, no username | none | the wizard's „Ohne Anmeldung anlegen"; every student without a login, given one by the update (§4); a student's login deleted on the access card (§4) | never |
| `invited` | its address, or its username | none yet | an invitation by e-mail, or a username given with a sign-in link | not yet: first the link |
| `active` | address, username, or both | its own | the invitation or a sign-in link used | yes |
| `suspended` | as it was | kept | „Zugang sperren" | no; its links are deleted |

- **`current_user()` already refuses a placeholder**, as it refuses anything that is not `active`.
  Nothing about sessions changes.
- **`verified_at` means "set up by its holder".** The holder sets it up from their mailbox through
  the invitation, or through a sign-in link for a username login. Nothing clears it (0021 §4).
- **The badges are worked out, not stored.** „Eingeladen" is an `invited` login with an address.
  „Noch nicht angemeldet" is an `invited` login with a username. „Ohne Anmeldung" is a placeholder.
  `login_state_badge()` decides it from state and columns, so there is no fifth state.
- **A placeholder becomes a login in one of two ways**, each from the wizard or later from the access
  card, each locking the login and refusing anything but a placeholder:
  - `invite_student()` writes the address and sends the invitation. It no longer inserts a login; it
    turns the student's placeholder into one;
  - `give_student_username()` writes the username and makes a sign-in link (§6).
- **"Has a login" is asked in one place.** Every reading of `account_id IS NULL` that means "has no
  login" moves to one function and its SQL form. Today those readings are:
  - `$uninvited` in `app/start.php`;
  - `student_next_steps()`, `student_without_login_at()` and the list at `app/domain.php:340`;
  - the access card in `views/student.php`;
  - `views/print.php`.

  `student_without_login_at()` becomes "a student whose login is still a placeholder and whose record
  carries this address".
- **The database default for `state` stays `invited` (001).** Every insert names its state.

### 4. Every student has a login

**Making a student.** `create_student()` (`app/actions.php`) makes the placeholder with
`placeholder_login()` (`app/auth.php`) and inserts the student with that `account_id`, in the
caller's transaction.

- Its callers are `student_create` (the wizard) and `demo_fill()`, whose example students without a
  login now get placeholders, marked `is_demo`.
- `create_own_student()` inserts a student for a login that already exists, and stays as it is.
- A placeholder's name is the student's (`login_name_for()`). Its address and username are `NULL`.

**Students made before.** The runner's PHP step (`database/defaults.php`) calls
`give_every_student_a_login()` after every update. This is "placeholder logins in the update", as the
project manager asked; here is how.

- One transaction locks the students whose `account_id` is `NULL`. Each gets `placeholder_login()`
  and the link.
- Running it again changes nothing, and it is one indexed query once every student has a login.
- It is not `tracked()`: no person made the change (0019 §4).
- **Why PHP, not SQL.** An `INSERT … SELECT` makes logins with nothing that ties each one to its
  student, so linking them back would need a marker column or a guess. It would also be a second
  copy of how a placeholder is made, which could never be corrected once shipped. 0019 rejected its
  SQL backfill for the same reason.
- **Why no address on a placeholder.** Two siblings may carry a parent's address while neither has
  a login (0020 §1). On the unique index they would collide and stop the update.

**Enrolment refuses a student without a login.** `enrol_student()` (`app/enrolment.php`) becomes
the one copy of the `INSERT INTO class_students` that `class_member_add` and `decide_request()` each
spell today.

- It locks the student and refuses one with no `account_id`.
- It also refuses a course the student was removed from (ADR 0024), whose way back is „Wieder
  aufnehmen", not a new row.
- `demo_fill()` is the one named exception: it writes its example enrolments with their logins
  already in place.

**A login a student points to cannot be deleted.** Migrations 029 and 030 change the foreign key on
`students.account_id` from `ON DELETE SET NULL` to `ON DELETE RESTRICT`:

```sql
-- 029, as built: finds the key by what it is, not by its name (see "The name")
SET @drop = (SELECT … FROM information_schema.REFERENTIAL_CONSTRAINTS … DELETE_RULE='SET NULL' …);
PREPARE drop_key FROM @drop; EXECUTE drop_key; DEALLOCATE PREPARE drop_key;
-- 030
ALTER TABLE students ADD CONSTRAINT student_login FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE RESTRICT;
```

- **Why two files.** MySQL 8.0 cannot drop and add a foreign key in one `ALTER` when it has to copy
  the table, which it does while foreign-key checks are on. ADR 0021 §6 rules out SQL that only
  MariaDB runs. Each file is one statement, the drop first. An update that stops between them
  continues with 030 on the next request. The portal is closed for the update in between anyway.
- **The name.** 001 declared the key without a name, and InnoDB named it `students_ibfk_1`.
  - On MariaDB 10.11.14 the key is indeed `students_ibfk_1`. 029 does not depend on it: it looks up
    every key from `students.account_id` to `accounts` that is `ON DELETE SET NULL` and drops it, and
    runs `DO 0` when there is none, so a name that differed on another engine cannot close the portal,
    and running it again - even after 030 - is harmless. That makes 029 four statements (SET, PREPARE,
    EXECUTE, DEALLOCATE) rather than one; each can be restarted.
  - The migrations suite asserts afterwards that `student_login` exists with `DELETE_RULE` RESTRICT.
  - MySQL 8.0 names unnamed keys the same way by its documentation; that half is unverified.
- **The index.** `student_one_account` (019) is the index the key uses, and it stays.
- **Why RESTRICT.**
  - `SET NULL` breaks the rule silently.
  - `CASCADE` would delete the child — attendance, contacts, absences — whenever no charge happened
    to stop it.
  - RESTRICT refuses. Billing history is untouched: charges are `RESTRICT` on the student, and
    nothing about them changes.

**What happens instead of deleting a student's login.** On Konten, a student's row leads to the
access card (§8). The access card offers:

- „Zugang sperren" and „Zugang entsperren": no sign-in and no links, everything kept, one tap back.
- „Link zurückziehen" or „Einladung zurückziehen" for a login not yet used, and „Anmeldung löschen"
  for one in use. „Anmeldung löschen" asks for the sign-in name typed, as the designer specified.

  All of these go through one function, `replace_login_with_placeholder()`. In one transaction it:
  1. makes a new placeholder and moves the student onto it, inside `tracked('students', …)`;
  2. then calls `delete_login()` on the old login. That removes its links, its waiting mail and, by
     the foreign keys, its private conversations, as „Zugang löschen" does today.

  The student is never without a login, even for one statement. The student's record, address,
  courses, charges and invoices stay. `delete_login()` refuses, in words she can read, a login a
  student still points to; the database's 1451 is the backstop, not what she reads.
- **Deleting the student itself** stays on the student page, as today. It is refused while charges
  exist, and „Mitgliedschaft beenden" is offered instead. Afterwards, a login that was never set up
  goes with the student (`login_goes_with_student()`, ADR 0021 §4). A login that was set up stays
  under „Zugänge ohne Schüler", where it can be deleted because no student points to it.
  `demo_clear()` already deletes students before logins.

### 5. The wizard „Schüler anlegen"

**Where it lives.** A new route, `student_new`, for staff only, with `nav_owner()` → `students`.
`?page=student` without an id redirects there.

- The create branch of `student_save` goes with „Gleich einladen", „Anlegen und weiter" and the
  create form in `views/student.php`. The wizard is the one way staff make a student. Two ways to
  make one would mean two places to remember the login, and the second is the one that forgets.
- The invitation by address alone (`email_invite`, ADR 0021 option 1) stays as it is, folded on the
  students page. Step 1 links to it.

**Two steps, as the designer specified them.**

1. **Who.** First and last name (required), birth date (optional), course and tariff or „Noch keinen
   Kurs" (full courses not offered), and membership status. It posts `student_draft`.
2. **How they sign in.** Three cards, each its own form, each posting `student_create`:
   - **(a) „Per E-Mail einladen", „Empfohlen".** Offered while `account_mail_ready()`; otherwise
     the card says what is missing.
   - **(b) „Ohne E-Mail, mit Benutzername", „Nur wenn nötig".** Offered while the privacy notice is
     released, because the person acknowledges it on first sign-in; otherwise the card says that.
   - **(c) „Ohne Anmeldung anlegen".**

**Where the draft lives between the steps.** In the session, under a random key:
`$_SESSION['student_drafts'][KEY]`. The address carries the key (`&draft=KEY`) and never the details.

- Only `student_draft` writes a draft, after checking its fields. GET pages read the draft and write
  nothing (ADR 0003).
- Each draft has its own key, so two tabs cannot mix two children.
- A draft is dropped by `student_create`, and after two hours. A session holds at most ten drafts;
  making an eleventh drops the oldest.
- A draft that is gone sends step 2 back to step 1, with the designer's sentence.
- The session already holds what a refused form typed (`remember_input()`), so this is no new kind
  of storage.

**`student_create`, one transaction.** Every refusal comes before the first write:

- the draft is still there;
- names and status;
- the course is still running and still has a place;
- for (a): `email_value()`, `refuse_address_in_use()`, `student_without_login_at()` and
  `account_mail_ready()`;
- for (b): `username_value()`, the locking read of `username_for_new_account()`, and the released
  privacy notice.

Then it writes:

1. `create_student()`, inside `tracked_insert()`;
2. `enrol_student()`, when a course was chosen;
3. for (a), `invite_student()`; for (b), `give_student_username()`;
4. the audit entries.

A refusal comes back to step 2 with the draft whole.

**Afterwards: the done page**, `student_new&step=done&id=…`, as the designer specified it.

- (a) says where the invitation went.
- (b) shows the link card (§6).
- (c) points to the access card.

It says, too, that a student added by mistake is deleted at the bottom of their page while there are
no charges. There is no undo; that is the way back.

**"Can enter or change all information the trainer has entered or left out."** After setting up,
by mail or by link, the person always lands on their own student page, with the designer's welcome.
That happens on the first set-up only, and now even when `family_next_steps()` is empty. There they
write everything 0020 §7 lets a family write:

- names, birth date, postal address and phone;
- contacts;
- custom fields at `'edit'`.

They ask for courses through a request. Status, prices and courses stay staff's.

### 6. One-time sign-in links („Anmeldelink")

**The name.** The owner wrote "SSO link". It is not single sign-on, which means one login shared by
several services. It is a link that signs one person in once. On screen it is „Anmeldelink", in the
code `signin`.

**What it is.**

- An `auth_tokens` row with `purpose='signin'`.
- `make_token()` makes it: 32 random bytes, 256 bits, as 64 hex characters in the link. Only their
  `sha256` is stored.
- It lasts 48 hours (§12) and works once.
- Making one deletes that login's earlier sign-in link: `make_token()` already replaces per purpose.
- The nightly prune removes it when it lapses.

**For which logins.** `signin_link_possible(array $account)` is the one rule, asked by the action and
by every button.

- Only a **student's** login. Staff keep the reset link by mail, and viewing as somebody covers
  looking.
- Not a suspended one.
- One of:
  - a **placeholder**, which gets its username in the same POST (the designer's fold with a
    username box);
  - a **username login not yet signed in**;
  - a **login in use**, with an address or a username. For a login in use, the link is the way back
    from a forgotten password without a mailbox.
- **Not an invitation by e-mail.** A link there would set up a login whose address nobody
  confirmed, and invoices and reminders would then go to it. A mistyped address would send a child's
  invoices to a stranger, and nobody would notice. Staff send the invitation again, or check the
  address, or delete the login and give a username. The done page's line „…oder einen Anmeldelink
  zum Teilen erstellen" changes accordingly (§12).

**Who may make one.** `may_create_signin_link(array $actor, array $target)`:

- staff, for a student's login **not yet signed in**: a placeholder, or a username login waiting;
- **only an administrator** for a student's login **already in use**.

The rule underneath: a link opens nothing its maker cannot already read.

- A login not yet signed in has nothing private in it. Staff can write to a student only once the
  student is active, and every member of staff reads the course groups.
- An administrator reads every chat (0022 §11).
- A trainer viewing a child is narrowed by `thread_seen_sql()` and writes nothing as the child. With
  a link she would read the child's chats with other staff, unnarrowed, and could write as the
  child.

`may_impersonate()`'s rule, as first suggested, would have let her do that. Whether trainers should be
able to anyway is the owner's question below. It is never the maker's own login, because staff logins
get no links.

**Making one: the action `signin_link`.** Modes `create` and `withdraw`.

- `require_staff()`, then a lock on the target, then `may_create_signin_link()`.
- A throttle of 20 an hour per member of staff (`signin-link`).
- `make_token()`, and `audit('account.signin_link')` with the maker as actor.
- Withdrawing deletes the link and writes `audit('account.signin_link_withdrawn')`.

**Showing it (A5).** The readable link goes into the maker's own session,
`$_SESSION['signin_links'][account_id]`, and nowhere else: not the database, not a log, not a mail,
not an address.

- The done page and the access card show it through the partial `_signin_link.php`:
  - the link in a read-only field;
  - its QR code by `qr_svg()`, inline, with nothing leaving the server;
  - the sign-in name, and when it lapses;
  - „Teilen", by `navigator.share()` in `app.js`, shown only where the browser has it;
  - „Link kopieren", with the existing copy code;
  - the warning that whoever holds it can sign in once.
- The link is shown only while `token_record()` finds it live. It is dropped from the session when it
  is withdrawn, replaced, found used or lapsed, and at sign-out.
- **Why not show it once.** An iPhone reloads a tab after a switch to WhatsApp, and a link shown once
  would be lost the moment she went to share it.
- **The cost.** Until then the readable link sits in the PHP session store on the server. Whoever can
  read that store can already take over sessions. **`security-reviewer` checks this before it ships.**

**Using one.** The link is `?page=activate&token=…`.

- **The GET.** It is what it is for an invitation today: throttled per address, the hash into the
  session, a redirect to the address without the token, then the page. A GET uses nothing up, so a
  WhatsApp or iMessage preview, or a mail scanner, does nothing.
- **The page** shows whose link it is, „Du bist nicht {name}?", and the sign-in name read-only with
  `autocomplete="username"`, so the phone saves the password under it. It warns when somebody else
  is signed in on that device. It asks for a new password twice, and on first sign-in for the privacy
  acknowledgement. The mail ticks show only for a login with an address.
- **The POST**, `activate` with purpose `signin`:
  1. throttled (`auth-ip`);
  2. the link locked, and refused when it is gone, lapsed or suspended;
  3. `strong_password()`, at least 12 characters;
  4. whatever session the browser had is ended (security review F1);
  5. the password set, state `active`, `verified_at` if empty, privacy and consents on first sign-in;
  6. `auth_version + 1`, so every other session of that login ends, because its password changed;
  7. all of the login's links deleted, so this one cannot be used twice;
  8. `sign_in()`, with a new session id and a new CSRF token;
  9. `audit('account.signin_link_used')`, with the holder as actor;
  10. the login's throttle buckets cleared, by `forget_attempts_after_success()`.
- **Where it lands.** On first sign-in, the person's own student page (§5). Otherwise,
  `landing_after_sign_in()`.
- **A login with an address keeps its address, preferences and consents.** Only the password
  changes.

**Every use is visible.**

- The access card says when the last link was made, **by whom**, and until when it works, or when it
  was used. It reads that from the audit through `signin_links_for()`, beside `password_resets_for()`.
- The holder's Mein Konto lists a password set through a sign-in link in its 14-day list, with who
  made the link. Whoever used it, the holder's old password stops working, so a link used by anybody
  but the holder cannot go unnoticed.

**Why the audit is not "recording what an admin does".** Her "no it should not be recorded" answered
whether an administrator's *reading of a chat* should be recorded, and nothing about reading is
recorded (0022 §11.2). A sign-in link is different: somebody makes a key to another person's login.
The entry saying who made it is how anybody finds out who could have used it. It is the same kind of
entry as `account.invited`, `account.reset_link` and `impersonation.started`, which exist already.
Nothing about what the person then reads or writes is recorded.

**What is left, plainly.**

- Whoever holds the link can use it once within 48 hours: the member of staff who made it, or
  anybody in the chat it was sent to.
- One link per login, a withdraw button, single use, a new password on use, and the entries above
  make that visible and short-lived. They do not make it impossible.
- It is accepted because the owner asked for the link. The card tells her to send it only to the
  child or the parents, never to a group.

### 7. Signing in, and „vergessen"

0020 §3–§4 return for logins that have a username.

- **One box**, „E-Mail oder Benutzername". It is `type="text"` with `inputmode="email"`; its posted
  name is `backend-dev`'s call.
- **One derivation**, `attempted_sign_in()`. A typed `@` means an address, anything else a username.
- **One lookup**, `account_for_sign_in($kind, $value, $lock)`:
  - a format gate first;
  - two literal statements;
  - an exact match after normalising both sides.
- **One `password_verify()` on every path**, and one refusal text, the designer's.
- **The throttle counts the typed value**, `address:` or `username:`. A success clears both of the
  proven login's buckets. Resolving the typed value to a login first is ADR 0007's oracle, and it
  stays out.
- **„Vergessen" takes either.** It mails the stored address when there is one. For a username login
  without one it sends nothing, and gives the same answer either way. The page says that a login
  without an address gets a new link from the trainer.
- **The lock-out risk the owner accepted for usernames in 0019 (S3) applies again.** Somebody who
  types ten wrong passwords for `lena.hofer` blocks that sign-in for a quarter of an hour.
  `VALIDATION.md` says so.
- **Mail never reaches a login without an address.** `queue_mail()` takes `?string`, and queues
  nothing for a missing recipient, rather than throwing and rolling back a newsletter to everybody
  else (0019 R2). `send_account_token()` refuses such a login before it makes a link.

### 8. „Zugänge" in three categories

The owner's "trainers, admins, students", as the designer specified it, on one page with three cards
in her order: „Trainer", „Administratoren", „Schüler". „Zugänge ohne Schüler" is unchanged.

- **The two team cards** are today's rows and actions. Inviting team members stays for
  administrators.
- **„Schüler"** has one row per student. Each row is a link to that student's access card. It shows:
  - the sign-in name, or „Ohne Anmeldung";
  - the badge (§3);
  - presence as the viewer may see it;
  - the link's date while the login is not yet signed in.

  The card has filter chips and 50 rows to a page.
- **No action on a student's login happens on Konten.** Everything is on the access card, where the
  student is (ADR 0010). So Konten offers no deletion of a student's login at all. The access card
  offers what §4 lists.
- The page only reads. The router keeps it `require_staff()`.

### 9. The Übersicht's one quick action

The owner: "start page buttons should be useful for the trainer — for now only creating a new
student". The page she lands on is the Übersicht. `?page=start` is the administrator's checklist,
which a trainer never sees.

- Its one quick action is „+ Schüler anlegen", which opens the wizard. It shows once a real course
  exists, as today; a portal with no course first leads to one (ADR 0011).
- The news card's „Neuigkeit schreiben" and „An mehrere schreiben", and „Termin ändern", go from the
  Übersicht. Each is still on its own page.
- „Anwesenheit" stays on today's training row, because it belongs to that row and is not a
  shortcut.
- The checklist's „Kinder eintragen" and the students page's button open the wizard too.

### 10. Viewing as somebody: unchanged

`may_impersonate()`, starting, stopping, its audit entry, and 0022 §9's narrowing all stay as they
are. A placeholder and a login not yet signed in cannot be viewed (§12, A6).

### 11. Records and functions, where they go

No new file in `app/`; the load order is unchanged.

- **`core.php`:** the username's pure functions.
- **`auth.php`:**
  - `placeholder_login()`;
  - `give_every_student_a_login()`;
  - `username_for_new_account()`;
  - `account_for_sign_in()` with a kind;
  - `signin_link_possible()` and `signin_links_for()`.
- **`shell.php`:** `may_create_signin_link()`, beside `may_impersonate()`.
- **`domain.php`:** the "has a login" rule, and a read of a draft for the wizard's views.
- **`enrolment.php`:** `enrol_student()`.
- **`actions.php`:**
  - `create_student()`, `give_student_username()` and `replace_login_with_placeholder()`;
  - the cases `student_draft`, `student_create` and `signin_link`.
- **Views:** `views/student_new.php` and `views/_signin_link.php`. Both only read.

Every call that crosses the load order is made at request time, never while files load.

### 12. The specification's assumptions and the project manager's decisions

| | Assumed or decided | Here | Why |
| --- | --- | --- | --- |
| A1 | username 3–30 of `a–z 0–9 . - _`, unique ignoring case, never `@`; staff keep an address | **Confirmed, without `_`** | `_` is a `LIKE` wildcard, and `username_for_new_account()` builds a `LIKE` pattern from a username (0019 §1). The specification's own hint already says "Kleinbuchstaben, Ziffern, Punkt und Bindestrich". Also: it starts with a letter, ends with a letter or digit, and has no two separators in a row. The column is `VARCHAR(30)`. |
| A2 | placeholder: student, no address, no username, no password, badge „Ohne Anmeldung" | **Confirmed** | State `placeholder` (§3). The grey „Kein Zugang" of the specification's §6 remains only as `login_state_badge(null)`. After the update no student is without a login, so it should never show. |
| A3 | `student_draft` keeps step 1 in the session under a key; `student_create` writes everything in one transaction | **Confirmed** | Drafts are dropped after two hours, ten at most per session (§5). Everything is checked again in `student_create`. |
| A4 | purpose `signin`, `?page=activate`, the GET only shows, the POST uses up; 48 h, once; newest replaces; can be withdrawn | **Confirmed** | §6. |
| A5 | the readable link kept in the staff session until used, replaced, withdrawn or lapsed | **Confirmed, for `security-reviewer` to check** | §6, "Showing it". Only the maker's session; only shown while live; never written anywhere else. |
| A6 | `may_impersonate()` also allows a placeholder and a username login not yet signed in | **Overruled** | A view is a session as that login, and `current_user()` is the one check on every request: an active, set-up login, or no session. An exception for a preview would widen the check that matters most, for a login with nothing of its own to show that the student page does not already show staff. The owner asked for impersonation to be possible, and it is. |
| A7 | one box, one refusal, the throttle counts the typed value | **Confirmed** | §7. |
| A8 | signed in by link with a password already set: a new password may be set without the old one, once, in that session | **Overruled** | The link page always asks for the new password, in the POST that signs in (§6). A link that leaves the old password working lets whoever holds it sign in as the child without the child ever knowing. A one-off "change without the old password" in the session would be a second way to set a password, with a state every page has to respect. The forgotten-password case needs a new password anyway. |
| A9 | chat uploads JPEG, PNG, WebP; no voice notes or files; student–student chats closed; administrators read every chat | **Confirmed, except students: JPEG only** | ADR 0022 §11.4. |
| A10 | deleting a student deletes a login never set up | **Confirmed; no change needed** | `login_goes_with_student()` already asks `verified_at IS NULL`, which an open invitation, a placeholder and a username login not yet signed in all have. |
| A11 | route `student_new`, staff only, `nav_owner()` → students; `?page=student` without an id goes there | **Confirmed** | §5. |
| PM | sign-in links last 48 hours | **Confirmed** | One lifetime for every link that leads to a password, which a parent already knows from the invitation. The ordinary use is a QR code scanned at training. A lapsed link costs her two taps for a new one, and a link lying in a family chat is a key for two days, not a week. This replaces the seven days first suggested. |
| PM | first and last name required | **Confirmed** | A placeholder and a username both need a name (0021 rejected names nobody typed). "Only the address" stays `email_invite`, linked from step 1. |
| PM | voice notes and files already in chats keep showing | **Confirmed** | ADR 0022 §11.4. |
| PM | password minimum stays 12 | **Confirmed** | `strong_password()` unchanged. The page tells them to let the phone save it. |
| PM | no sign-in links for staff logins | **Confirmed** | §6. An administrator recovers by mail, and a link for a trainer would be a way into a login with staff rights. |
| PM | one quick action; „Anwesenheit" on today's row stays | **Confirmed** | §9. |
| PM | student–student chats closed | **Confirmed** | ADR 0022 §11.3. |
| PM | placeholder logins in a migration | **Confirmed, in the update's PHP step** | §4. Not a numbered SQL file: nothing in SQL ties a new login to its student. |
| PM | no undo; `tracked()` records only | **Confirmed** | Nothing here relies on one. Every way back is its own action: withdraw a link or an invitation, delete a login back to a placeholder, unsuspend, delete a student added by mistake, „Wieder aufnehmen" (ADR 0024). |
| Spec §3 | „Ansehen" on a placeholder and on a login not yet signed in | **Overruled** | A6. |
| Spec §3 | the sign-in-link fold for an invitation by e-mail; the done page's „…oder einen Anmeldelink zum Teilen erstellen" | **Overruled** | §6, "For which logins". The line points to checking the address, sending again, or a username instead. |
| Spec §3 | the fold for a username login in use, for every member of staff | **Narrowed to administrators** | §6, "Who may make one"; the owner may widen it. |

## Rejected

- **Calling it SSO, or building single sign-on.** That means a third party between a child and the
  portal, and a dependency she has to keep patched. She asked for a link that works once.
- **A link that signs in on a GET.** A WhatsApp or iMessage preview, or a mail scanner, would use it
  up or sign in with it.
- **A link that signs in without a new password** (A8). Silent, and so invisible to the holder when
  it is misused.
- **A link that signs in again and again, instead of a password.** Every sign-in would need the
  trainer, and every chat holding a link would hold a key to a login.
- **Keeping the readable link in the database, to show it again.** A read of the database would
  then hand out sign-ins. Only the hash goes there.
- **Showing the link once only.** An iPhone reloads the tab when she switches to WhatsApp to send it.
- **Mailing a sign-in link.** For an address, the invitation and the reset link already do that job;
  a mailed sign-in link would be a reset link under another name.
- **A sign-in link for an invitation by e-mail.** A login would be set up at an address nobody
  confirmed, and invoices would go there.
- **Sign-in links for staff logins.** Staff recover by mail. A link to a login with staff rights is
  the most valuable key the portal could hand out.
- **`may_impersonate()`'s rule for links, unchanged.** A link is not narrowed the way a view is, and
  writes as the child. A trainer could read the child's chats with other staff.
- **Seven days, or 30.** A key lying in a family chat for a week or a month. Forty-eight hours is one
  rule for every link that sets a password.
- **A page of its own for using a link.** It would be a second copy of what the activation page
  already does: the throttles, the hash in the session, the F1 sign-out and the suspended refusal.
- **A username for every login (0019, 0020 §2).** She wants e-mail as the default and usernames
  "ideally discouraged". Two sign-in names for every login means two throttle buckets each, and the
  runner's backfill again.
- **An underscore in the alphabet** (A1). It is a `LIKE` wildcard.
- **`''` for "no username" or "no address".** The unique index would refuse the second login without
  one.
- **A made-up address on a login without one, such as `lena-17@invalid`.** It passes every check an
  address passes, something sooner or later mails it, and it shows wherever an address is shown.
- **A `CHECK` that a login has an address or a username.** A placeholder has neither, so the check
  would have to know the state, which the code owns. MySQL 8.0 before 8.0.16 also parses `CHECK` and
  ignores it, so the guarantee would differ by version.
- **Usernames for staff.** Recovery would depend on another administrator, and the owner's words
  were about students.
- **A "dummy" role instead of a state.** A role says what somebody may do; a placeholder is a student
  who cannot sign in yet. `is_staff()`, `role_label()`, the scoping and every `role='student'` query
  would need a third answer.
- **Viewing as a placeholder** (A6). It widens `current_user()`, for nothing the student page does
  not already show.
- **`students.account_id NOT NULL`.** The runner applies every migration before its PHP step, so a
  NOT NULL migration would meet students the step had not yet given a login, and stop the update. It
  would do that on this release, and on any install that skips one. RESTRICT, `create_student()` and
  the step hold the rule without it.
- **`ON DELETE CASCADE`.** Deleting a login would delete the child whenever no charge stopped it.
- **Keeping `SET NULL` and guarding in PHP alone.** One missed path makes a student without a login,
  silently. The database can refuse it outright, for two one-line migrations.
- **One migration to drop and add the key.** MySQL 8.0 cannot do both in one `ALTER` when it copies
  the table.
- **The backfill in SQL.** Nothing ties each new login to its student, and it would be a second copy
  of how a placeholder is made.
- **A placeholder carrying the student's address.** Siblings sharing a parent's address would collide
  on the unique index and stop the update.
- **„Anmeldung löschen" that leaves the student without a login, or that only clears the password.**
  The first breaks the rule. The second would hand the next holder the previous holder's
  conversations. A fresh placeholder does neither.
- **Deleting a student's login from Konten.** It belongs on the student's page (ADR 0010), where the
  student and the consequences are.
- **The wizard's draft as rows in the database.** Half-made students would appear in lists, counts,
  the checklist and the billing preview, and would need clearing up.
- **The draft in the address, or in hidden fields carried by GET forms.** Names, birth dates and
  addresses would land in the web server's log and the browser's history.
- **One long form, or a wizard that needs JavaScript.** She asked for a guide, and every page works
  without JavaScript (ADR 0002).
- **Keeping the old create form beside the wizard.** That is two ways to make a student, and the
  second is the one that forgets the login.
- **Asking for more in the wizard** (address, contacts, membership dates). A form with twenty boxes is
  one somebody abandons. The family completes those (0020 §7), and staff can on the student page.
- **More quick actions on the Übersicht.** The owner: "for now only creating a new student".
- **Letting the holder change their username now.** Nobody asked; 0019 §8 has the design ready.

## Consequences

- **Records.**
  - ADR 0021 becomes `accepted, amended by 0023`, and its note names the parts that no longer hold.
    The note also records that ADR 0020's rejection of copyable links no longer holds for this one
    kind of link.
  - ADR 0010 gets a note too: every student has a login, and the key is RESTRICT.
  - 0020 itself is not rewritten. Adding its status line would mean rewriting all 1,483 lines of an
    accepted record, so 0021's note, which already governs 0020's sign-in sections, carries it.
  - 0019 stays superseded.
- **Actions.** Three are added: `student_draft`, `student_create` and `signin_link`. With 0022 §11's
  two removed, that is 75 today, 73, then 76.
  - `student_save` loses its create branch.
  - `account_state`'s `delete` and `withdraw` replace a student's login with a placeholder, and still
    delete one no student points to.
  - `class_member_add` and `decide_request()` go through `enrol_student()`.
- **Pages.** `student_new` joins `$allowed` and the router's staff list. No existing page changes its
  classification.
- **Dependencies.** None. `bacon/bacon-qr-code` is already there.
- **Schema.** 028 and 030 one statement each, 029 four restartable ones; never edited once shipped.
  - Nothing is deleted. `accounts` grows by one row per student without a login, from the runner's
    step.
  - `schema_guarded_tables()` is not widened. It refuses fewer rows, and there are more.
  - The update needs no command.
- **Order of work.**
  1. `ui-ux-designer` revises the specification for §12's overrules:
     - the link page always asks for a password;
     - no „Ansehen" on a login that is not active;
     - no link for an invitation by e-mail;
     - the fold for a login in use is for administrators only;
     - the access card says who made the last link, and Mein Konto lists a password set through a
       link.
  2. `database-engineer` writes:
     - 028–030;
     - the step's call in `database/defaults.php`;
     - the `tests/migration-data.php` cases: a student without a login; two siblings on one
       address, neither with a login; an example student; an orphan login; an open invitation;
       after the update every student has a login, the student count is unchanged, and the database
       refuses to delete a student's login (1451);
     - the `information_schema` check of the key's name.
  3. `backend-dev`, then `frontend-dev`.
  4. `qa-tester` and `mobile-tester`.
  5. `code-reviewer` and `security-reviewer`.
  6. `docs-writer`.
- **`qa-tester`**, breaking each rule once:
  - no student without a login after any path, or after the update;
  - enrolment refuses a student without a login;
  - `delete_login()` refuses a student's login, and the database refuses it too;
  - „Anmeldung löschen" leaves a placeholder and removes the old login's private chats;
  - `Lena.Hofer` signs in as `lena.hofer`;
  - a username with `_` or `@` is refused;
  - a known and an unknown username are throttled alike;
  - „vergessen" sends nothing for a username login without an address, and says the same;
  - a sign-in link GET uses nothing up;
  - a link's POST without a new password is refused;
  - a used link fails a second time;
  - a new link kills the old one, and so does suspending;
  - a trainer cannot make a link for a login in use, nor for any staff login;
  - nobody can make a link for an invitation by e-mail;
  - `queue_mail()` with no recipient queues nothing and throws nothing;
  - the wizard writes nothing to the database before `student_create`, and a GET of any step writes
    nothing;
  - `structure`:
    - every `INSERT INTO students` is in `create_student()`, `create_own_student()` or `demo_fill()`;
    - every `INSERT INTO class_students` is in `enrol_student()` or `demo_fill()`;
    - `send_account_token()` is never called with `signin`.
- **`mobile-tester`**, at 320 and 390 px, light and dark:
  - the wizard;
  - the link card: a second phone scans the QR code off the screen, „Teilen" opens the share sheet,
    and the copy field works without JavaScript;
  - the link page;
  - Konten's three cards;
  - a WhatsApp preview of a link does not use it up;
  - iCloud Keychain saves the password under the username.
- **`security-reviewer`**, before anything ships:
  - the readable link in the staff session (A5);
  - the sign-in link as a key carried in a chat;
  - who may make one;
  - the `signin` branch of `activate`;
  - that no mail is ever queued to a login without an address.
- **`docs-writer`:**
  - `README`, `UPDATING`, `CHANGELOG` and `TESTING.md`;
  - `VALIDATION.md`: S3 for usernames, and the sign-in link's accepted risk;
  - the privacy draft, which says:
    - a child may sign in with a username and no address;
    - sign-in links are made by staff and may be sent through a messenger;
    - who made a link, and when it was used, is kept;
    - every student has a login, even one that never signs in;
  - a check that no other document still says "there are no usernames".
- **Must stay true:**
  - every student has a login, from the moment the student exists:
    - every `INSERT INTO students` names its `account_id`;
    - `give_every_student_a_login()` runs after every update;
    - the foreign key is RESTRICT;
    - a student's login is only ever replaced, never removed;
  - every `INSERT INTO class_students` goes through `enrol_student()`, which refuses a student
    without a login. `demo_fill()` is the one named exception;
  - every write of `accounts.email` asks `refuse_address_in_use()` first;
  - every write of `accounts.username` goes through `username_value()` and the locking read;
  - staff logins always have an address and never a sign-in link;
  - sign-in looks nothing up that fails its format, matches exactly, and counts the typed value;
  - a sign-in link:
    - is stored only as its hash;
    - is never mailed, logged or put in an address staff open;
    - is used up only by the `activate` POST, which always sets a new password;
    - lasts 48 hours, one per login;
    - whether it may be made is decided only by `signin_link_possible()` and
      `may_create_signin_link()`;
  - nothing is mailed to a login without an address;
  - only `student_draft` writes a draft, and only `student_create` writes the database for the
    wizard;
  - 028–030 are never edited.

### For the owner

1. **Approve migrations 028–030.** They let a login have a username and no address, and stop a
   student's login from being deleted from under the student.
2. **Should a trainer be able to make a new sign-in link for a child who already uses the portal?**
   That is the case where the child forgot the password and has no e-mail address.
   - Built: only an administrator can. A trainer can for a child not yet signed in.
   - What a yes means: the trainer could, once, sign in as the child herself. She would then see the
     child's chats with you and other trainers, which her "view as" does not show. The child would
     notice, because the password changes, and the child's page says who made the link.
3. **Release the privacy sentences** `docs-writer` drafts.

## In plain words, for the owner

- **„Schüler anlegen"** is the one button on your start page. It walks you through two steps:
  1. who the child is, and which course;
  2. how they will sign in.
- **Three ways to sign in:**
  - **By e-mail, the recommended way.** They get an invitation, choose a password, and can then
    correct or add anything you entered or left out.
  - **With a username, for a child without an e-mail address.** You get a QR code and a link to
    share. They open it within two days, choose a password, and complete their details. Without an
    address there is no „Passwort vergessen" and no e-mail from the club; for a new password they
    need a new link.
  - **Without signing in at all.** You fill everything in yourself, and give them a way to sign in
    whenever you like.
- **Every child in the portal now has a login**, even one that never signs in. Children already in
  the portal get one automatically when you install the update. A child's login can no longer be
  deleted on its own. Deleting it gives the child a fresh, empty one.
- **A sign-in link works once, for two days.** You can withdraw it. The child's page shows who made
  it and when it was used. Send it only to the child or the parents, never to a group.
- **„Zugänge"** now shows trainers, administrators and students. A student's row takes you to that
  student's page, where everything about their login is done.
- **The update needs nothing from you** beyond your yes to the database change.
