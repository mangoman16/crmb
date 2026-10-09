---
status: accepted, amended by 0019, 0020, 0021, 0023, 0030
date: 2026-09-28
---

# 0010. One account is one student

> **Amended a fifth time by ADR 0030 (2026-10-08).** Usernames are gone again, for every role. In
> the 0023 note below, "Until it is given an address or a username" reads "until it is given an
> address": a placeholder becomes a login only by an invitation to its address. Everything else in
> that note, and in the ones below it, stands.

> **Amended a fourth time by ADR 0023 (2026-10-06, proposed until the owner approves migrations
> 028–030).** The owner: "there should be no student ever in a course without an account".
>
> - **Every student has a login from the moment the student exists.** Until it is given an address
>   or a username, it is a placeholder, which cannot sign in. The update gives every older student
>   without a login one.
> - **`students.account_id`'s foreign key becomes `ON DELETE RESTRICT`** (migrations 029 and 030). A
>   login a student points to cannot be deleted. „Zugang löschen" on the access card gives the
>   student a fresh placeholder in the same transaction, then deletes the old login and its private
>   conversations.
> - **`students.account_id` is written** when the student is made (`create_student()`,
>   `create_own_student()`, `demo_fill()`), by the update for older students, and when a login is
>   replaced. It is no longer written by `invite_student()`, which now turns the placeholder into an
>   invited login.
> - **„Before an account exists, `students.email` is the address"** now reads "while the login has no
>   address". Nothing is mailed to `students.email`, as before.
> - **The Konten page („Zugänge") lists students too**, in a third card. Each row leads to the
>   student's access card, where every action on that login is.
>
> Everything else stands as the notes below leave it.

> **Amended a third time by ADR 0021 (2026-10-01).** Sign-in is by the address only; usernames
> are gone. `students.account_id` is also written by `create_own_student()`, when somebody invited
> by address alone sets up their own record. „Zugänge ohne Schüler" on Konten lists only logins
> that were set up; open invitations are listed apart, and `student_delete` removes a login that
> was never set up. Everything else stands as the notes below leave it.

> **Amended by ADR 0019 (2026-09-29).** The owner reversed one half of this record. Several
> accounts may now share one email address; siblings use a parent's. Sign-in is by username.
>
> **These parts no longer hold:**
>
> - the owner's words "simply they need new email addresses";
> - under "What already holds", "`accounts.email` is `UNIQUE`". Migration 024 drops that
>   index;
> - under "One address", every refusal of an address because another account holds it:
>   - `student_save` "refuses if the new address belongs to any account";
>   - the "No account" bullet's refusal and its tolerated clash;
> - under "Granting and removing access", `student_invite`'s "or if the address belongs to
>   **any** account";
> - the whole of "Read-side functions in `app/domain.php`": `account_with_address()`,
>   `students_needing_own_address()` and the „Eigene E-Mail-Adresse eintragen" step;
> - in Rejected, the reasons given under "Clearing `students.email` on detached siblings".
>   They rested on the refusal;
> - in Consequences, the `qa-tester` items that test a refusal of a shared address;
> - `change_login_address()` is renamed `change_account_email()`.
>
> **Everything else stands:**
>
> - one login is one student;
> - the index `student_one_account` and migration 019;
> - `accounts.email` and `students.email` kept equal once a login exists;
> - staff never re-address an active login;
> - the access card on the student page;
> - `greeting_name()`;
> - the Konten page.

> **Amended again by ADR 0020 (2026-09-30).** 0020 reverses 0019's shared addresses before
> they shipped. The owner's words: "one person = one login = one own e-mail address".
>
> **The parts the note above lists as no longer holding hold again:**
>
> - `accounts.email` is `UNIQUE`. Migration 024 is deleted, not written;
> - `student_save` and `student_invite` refuse an address that is already a login's, with the
>   tolerated clash as described;
> - `account_with_address()` stays, for the access card and `student_next_steps()`;
> - the qa items that test a refusal of an address in use.
>
> **Still different from this record:**
>
> - the refusal is `refuse_address_in_use()`, in place of `account_using_email()`;
> - `change_login_address()` stays renamed `change_account_email()`;
> - `students_needing_own_address()`, `own_address_missing_sql()` and the „Eigene
>   E-Mail-Adresse eintragen" notices on the dashboard and the students list stay deleted;
> - `student_invite` has no `mode=direct`, and `account_create` is gone: nobody sets another
>   person's password;
> - `students.account_id` is set in `app/` only by `invite_student()`, which both
>   `student_invite` and `student_save` (creating with an invitation) call. In Must not,
>   "any code except `student_invite`" now reads "any code except `invite_student()`";
> - sign-in takes the username or the address, in one box;
> - a family's own edits of its student, custom fields and contacts are `tracked()`.

## Context

Until now, one `accounts` row could manage several `students`. There were three ways to link
them:

- the "Bestehendes Konto verknüpfen" select, posted as `account_id` by `student_save`;
- `student_invite` joining a new child to an address that already had an account;
- `account_create` with role `student` attaching every unlinked child whose `email` matched.

Migration 016's header reasons from that model: "the account it invites is the account that
manages the child". It cannot be edited (ADR 0004). This file replaces that reasoning.

The owner has decided, in her words: "1 account = 1 student, done, no linking, no adding more
than 1 to 1 account, simply they need new email addresses", and "A Schülerkonto = actual
account in the platform". She chose a database rule over merging the two tables and over a
rule enforced only in the application.

She has also said that the portal is not live yet and will start fresh, so migration 019 will
find no real families sharing a login. Its statements that move data stay anyway: they are
correct on any install, and they are tested with data in place. The rule "the lowest
`students.id` keeps the login" stands.

What already holds and is relied on here:

- `accounts.email` is `UNIQUE` and is compared under `utf8mb4_unicode_ci`.
- `students.account_id` is nullable, with `ON DELETE SET NULL`.
- Every family-side read is scoped by `s.account_id=?`: `student()`, `filtered_students()`,
  `invoice()` and `dashboard.php`.
- `history.php` no longer has an undo. `record_versions` is a change log.

## Decision

### The rule, in the database

Migration `019_one_account_one_student.sql` runs these steps in this order. Every step is
idempotent, because a failed run is retried from statement 1 on the next request. Every
timestamp is `UTC_TIMESTAMP()`, which is UTC whatever the session's time zone is.

1. For every linked student that is **not** the lowest `students.id` on its account, it
   writes a `record_versions` row:
   - `entity='students'`, `operation='update'`
   - label is the first and last name
   - `before_json` is `JSON_OBJECT('account_id', <id>)`, `after_json` is
     `JSON_OBJECT('account_id', NULL)`
   - `actor_id` is `NULL`
   - It skips a student that already has this row, so an interrupted run does not log a
     child twice. That check compares through `HEX()`, because MySQL compares a JSON value
     with a text column as JSON, and a plain `=` would never find the row.
2. It detaches those students: `account_id=NULL`, `revision+1`, `updated_at`. The rows to
   keep are `MIN(id)` per `account_id`, read through a grouped derived table. The grouping
   forces MySQL to materialise the table instead of refusing a self-referencing `UPDATE`
   (error 1093).
3. For every student still linked whose `email` differs **byte for byte** from its
   account's, it writes a `record_versions` row with the old and new `email`, built with
   `JSON_OBJECT`. Both the difference and the already-logged check compare through `HEX()`.
4. It copies the account's address onto every linked student whose `email` differs byte for
   byte, and raises `revision` and `updated_at` on each row it changes, so a form left open
   during the update is refused when saved.
   - The comparison is `HEX(email) <> HEX(account's email)`. Under `utf8mb4_unicode_ci`,
     `Gruber@…` equals `gruber@…`, so a plain `<>` would leave two spellings in place.
5. It runs `CREATE UNIQUE INDEX student_one_account ON students (account_id)`.
   - Several `NULL`s are allowed on InnoDB (MariaDB and MySQL) and in SQLite, where
     `NULL`s are distinct in a `UNIQUE` index.
   - `sqlite_translate()` leaves a `CREATE UNIQUE INDEX` statement untouched.
   - On MariaDB and MySQL, the index InnoDB created implicitly for the foreign key is
     **replaced** by `student_one_account`. InnoDB drops an implicit foreign-key index once
     another index can enforce the key, so `student_one_account` now does both jobs. Found
     on MariaDB by `database-engineer`. A later migration that drops this unique index must
     give the foreign key an index again in the same file.

Nothing is deleted. Row counts in `schema_guarded_tables()` are unchanged, so the guard is
**not** widened. The way back is the backup the runner takes before any migration. The
`before_json` rows say which account each detached child was on. The same link cannot
simply be restored, because the rule forbids it. The file's header states that 016's model
is replaced by this ADR.

### One address

- **`accounts.email` is the address once an account exists.** `students.email` is kept equal
  to it.
- **Before an account exists, `students.email` is the address.** It is where the invitation
  goes, and it becomes the login's address when access is granted.
  - **Nothing else is ever mailed to it.** Every mail goes through `queue_mail()`, which
    needs an account row. That includes `notify_invoice()`, `payment_remind`, reminders and
    notices, so email to a student starts only once a login exists.
  - Without a login, invoices and charges exist only in the portal and on paper.
- `student_email()` stays the one reader and keeps preferring the account's address.

The login address changes in one place only: `change_login_address(int $accountId, string
$email)`, a helper at the top of `app/actions.php`, which is loaded after `mail.php`. In one
transaction it:

- updates `accounts.email` and `students.email` together;
- runs `auth_version+1`, which ends every session on that account;
- deletes all of the account's `auth_tokens`, so a link sent to the old address stops working;
- calls `cancel_account_mail()`.

Its callers:

- **Staff change the address on the student page of a student whose account is still
  invited** (`state='invited'`, `verified_at IS NULL`).
  - `student_save` refuses if the new address belongs to any account.
  - It calls the helper inside the same `tracked()` call, so the change log shows the new
    address.
  - It then runs `send_account_token(…,'invite')` to the new address.
  - If SMTP or the privacy notice is not ready, the whole save rolls back with that message.
- **The account is active or suspended.** The field is read-only for staff, as today. A
  posted change is **refused**. It is not ignored, because a stale page from before the
  family signed up must say why.
- **The family changes it under „Mein Konto"**, using the existing `email_change` flow. The
  `activate` case with purpose `email` calls the helper, then sets `verified_at`, then signs
  in again as it already does.
- **`student_invite`** writes both addresses when it creates the account. That is a
  creation, not a change.
- **No account.** `student_save` writes `students.email` alone. It refuses only when the
  address is *changed to* one that is an account's login. An existing clash, such as a
  detached sibling holding the parent's address, is tolerated so her other edits still save.
  The notice below reports it.

### Granting and removing access, all on the student page

- **`student_invite`**
  - Refuses if the student already has an account, or if the address belongs to **any**
    account ("Jede Schülerin und jeder Schüler braucht eine eigene E-Mail-Adresse …").
  - Always inserts a new `role='student'` account. The linking branch and the
    management-account branch are removed.
  - With `mode=direct` it needs `require_admin()` and a password, and creates the account
    active and verified. This replaces `account_create` with role `student`.
- **`student_save`** stops reading and writing `account_id` altogether. The only writers left
  are `student_invite`, the foreign key's `SET NULL` and migration 019.
- **`assignable_roles()`** returns `['trainer','admin']` for an administrator and `[]` for
  anyone else.
  - `account_invite` becomes `require_admin()`.
  - `account_create` loses its line that linked students.
- **`account_state`** keeps its rules. Its forms for student accounts (suspend, restore,
  resend, delete, view as this person) move to the student page's access card. For a
  student account it returns `['student', ['id'=>…]]`, looking the student up **before** a
  delete.

### Read-side functions in `app/domain.php`

- **`account_with_address(string $email): ?array`** is a plain read for views, with no
  `FOR UPDATE`. The actions keep using `account_using_email()`.
- **`students_needing_own_address(): array`** lists students whose membership has not ended,
  who have no account and a non-empty `email`, where that `email` is an account's login or is
  shared with another student. It is shown to staff on the dashboard and the students list,
  and is bilingual because it is worked out from the data.
- **`student_next_steps()`** says "Eigene E-Mail-Adresse eintragen" instead of "Zugang
  einladen" for those students.

### The Konten page

It lists `role IN ('admin','trainer','manager')`. A second section, shown only when it is not
empty, lists **student accounts that no student points to**, with suspend and delete only.
Without that section those logins would be invisible and unmanageable.

### Settled with the owner

- **The family's menu item goes straight to their own student page and is labelled
  „Profil".**
  - The route stays `page=student&id=…`, reached through `student()` and its
    `s.account_id=?` scoping. It is not a new page.
  - „Mein Konto" (`page=profile`) stays the page for the sign-in settings.
- **Greetings use the student's name.**
  - One function, `greeting_name(array $account): string` in `app/mail.php`, decides the
    name every mail opens with.
  - For a student account that is the student's name, from `students`. For staff it is
    `accounts.name`.
- **No message is sent to detached families**, by the migration or afterwards. The derived
  notice for staff is the only signal.

## Rejected

**Merging `accounts` into `students`.** The owner declined it. It would also cost a migration
moving every login, token, thread and consent row, which is the largest possible change for
the same rule.

**A rule in the application only.** It would be one missed `UPDATE` away from two children on
one login, and the `structure` suite cannot see that. The index can.

**A `UNIQUE` index on `students.email`.** Existing rows share addresses and hold many `''`
values. The unique fact is the login, which `accounts.email` and the new index already cover
between them.

**Triggers to keep the two addresses equal.** Shared hosting often withholds the `TRIGGER`
privilege, and the SQLite translation would need a second dialect.

**Letting staff re-address an active login.** A staff member could send a family's login to
a mailbox of their choosing. Today's rule stays: the person confirms from the new mailbox.

**A stored notification from the migration.** SQL cannot call `t()`. A derived notice stays
bilingual and disappears once fixed.

**Clearing `students.email` on detached siblings.** No mail depends on it: a detached child
receives no email either way until it has a login of its own. The reasons to keep it are
about the trainer's work, not delivery:

- 019 deletes nothing, and the address is the one record of which family's login the child
  came from. It sits next to the `record_versions` row, where she will look.
- `students_needing_own_address()` finds these children *because* the address is still the
  parent's login, and can say whose. A cleared field would drop them into the generic "no
  email address" list, without that context.
- The address cannot be used by mistake. `student_invite` refuses it, because it already
  belongs to an account.

## Consequences

- **`database-engineer`**
  - Writes 019.
  - Extends `tests/migration-data.php` with a second pause after 018. Rows written there
    include an account with three children, where the lowest id was not inserted first,
    and a linked child whose email differs from its account's in case alone.
  - Registers the SQLite functions `CONCAT` and `UTC_TIMESTAMP` and, if the test build lacks
    it, `JSON_OBJECT`, through the one function the harness uses.
  - Runs `tests/mariadb-local.sh`. MySQL 8.0 stays unverified, and the report says so.
- **`backend-dev`** changes:
  - `app/actions.php`: `student_invite`, `student_save`, `account_create`, `account_invite`,
    the `activate` email branch, `account_state` and the new helper;
  - `app/auth.php` (`assignable_roles`);
  - `app/domain.php`;
  - `app/mail.php`: `greeting_name()`, used by every mail that greets somebody;
  - `app/actions_messages.php`: the preview and send work per student; the sibling
    concatenation is dead and is deleted;
  - `app/actions_config.php`: the `payment_remind` comment, which was already wrong;
  - `app/demo.php`: fix the "siblings on one login" comment and name the demo accounts after
    their student;
  - `app/history.php`: `account_id` is labelled "Konto (Zugang)", and the duplicate `match`
    arms go.
- **`frontend-dev`**, from `ui-ux-designer`'s specification:
  - `views/student.php`: one address field, and an access card for each state (none,
    invited, active);
  - `views/accounts.php`;
  - the family's „Profil" menu item, the family dashboard and its "Meine Schüler";
  - the wording in `views/compose.php`;
  - the delete texts.
- **`docs-writer`**: both privacy drafts, which only seed new installs, plus `CHANGELOG`,
  `UPDATING` and `TESTING.md`.
- **`qa-tester`** writes, and breaks each on purpose:
  - A second student on one account is refused by the database (23000).
  - The same is refused by `student_invite` with its sentence, before any write. The
    `structure` orderings entry for `student_invite` moves to the new refusal.
  - `student_save` with a posted `account_id` changes nothing.
  - `account_invite` and `account_create` with role `student` are refused.
  - Re-addressing an invited account kills the old token (`token_record` returns null) and
    queues a new invitation.
  - A posted change for an active account is refused.
  - After every path, no linked student's `email` differs from its account's, compared
    **byte for byte**, not through the collation.
  - A `structure` rule names the only places that write `students.email` for a linked
    student.
  - Migration 019, with data in place (two-halves style):
    - the keeper keeps its account and the others are `NULL`;
    - `record_versions` holds each old `account_id`, once each, even after a re-run;
    - the email that differed only in case is replaced, and its `revision` has risen;
    - the student count is unchanged;
    - the index exists and refuses a duplicate.
  - Rewrite `contacts.php` 124–154, `views.php` "two children", and `integration.py`
    "groups siblings into one account".
- **Must not:**
  - edit 016;
  - delete any row in 019;
  - add a table or column that links siblings;
  - let any code except `student_invite` set `account_id`;
  - use the 23000 from the index as the refusal a person sees;
  - change the `s.account_id=?` scoping to anything cleverer, such as a student id kept in
    the session;
  - send detached families any message about the change.
