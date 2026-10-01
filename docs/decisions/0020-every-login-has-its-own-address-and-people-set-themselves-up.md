---
status: accepted
date: 2026-09-30
---

# 0020. Every login has its own address, and people set themselves up

*Amended on 2026-09-30, after commit `7f891a5`, from the designer's revised screen specification:*

- *the forgot page keeps „Oder frag deine Trainerin – sie kann dir deinen Benutzernamen sagen.",
  which is the owner's decision;*
- *the sign-in refusal is reworded in plain words (§3);*
- *five asks of the specification are decided in §10.*

*Amended on 2026-10-01, after the code and security reviews of `722b8f9`:*

- *the sign-in lookup no longer locks the row, and the rehash is conditional (§3, security F2);*
- *the "taken" answer for a username is the one bounded exception to "never tell a family whether
  a username has a login" (§2, security N3);*
- *a username chosen on the activation page names its chooser in the change log (§2, code
  review 3);*
- *four rules each get one function, and `demo_fill()` is named as the exception it is (§11).*

This record supersedes the shared-address parts of ADR 0019, its uncommitted third amendment
included, before any of them shipped. §8 lists what of 0019 still stands.

## Context

On 2026-09-30 the owner changed direction and asked for "a final robust solution". Her words:

- "Even having a username should not mean an email is not used; first time setting a password
  should still be done via email."
- "Invitation is sent to an email and they set their own password."
- "My mother setting passwords back or setting initial passwords is pointless."
- "The point of the whole thing is as little administrative work as possible, people do it
  themselves. Manual registrations might be needed because we are scared of bots and spam, but
  even then she can send an invitation link and the person can create the profile matching
  their needs."
- "The fact to expect children not to have emails is weird … most of the kids have an email
  nowadays anyways and if not it's their own problem."

The project manager decided five requirements from those words, **R-a** to **R-e**. This record
takes them as given, checks them against the code and works out what follows.

The project manager has also settled two points:

- families write their own postal address and phone;
- R-e's "undo" means setting a value back by hand (§7).

### The tree this starts from

- The branch `claude/exciting-knuth-8atux8` is at `67383e7`, pushed and green.
- On top of it, uncommitted, is the whole of ADR 0019 as built: migrations 022–024, the username
  functions and the shared-address code.
- The project manager reports the whole suite at 5203 passed, 0 failed, on SQLite.
- Nothing of 0019 has reached the owner's portal.
- This record was written from the working tree as read. No suite was run for it.

### Why 0019's objections to "username or address" no longer hold

0019 rejected signing in by either one for four reasons (its first "Rejected" entry):

1. **The owner chose username only.** She now asks for both.
2. **An address several logins share cannot say who is signing in.** No address is shared any
   more (R-a).
3. **A sign-in by address would stop working the day a sibling is invited.** The same answer.
4. **It would bring the collation oracle back.** §3 still counts every input under its typed
   value and never resolves it to a row first. It adds an exact-match check that makes a
   folded spelling useless.

### What already exists for families

All of this was read in the working tree, not assumed.

- **The page.** The family's menu item „Profil" opens `page=student&id=<their own>` (ADR 0010).
  It is reached through `student()`, which adds `AND s.account_id=?` for anybody who is not
  staff.
- **Names and birth date.** On that page's „Profil" tab a family already edits first name, last
  name and birth date. `student_save` has a non-staff branch that writes exactly those three
  columns. **It is not `tracked()`.**
- **Postal address and phone.**
  - `students.address` (`VARCHAR(200) NOT NULL DEFAULT ''`) and `students.phone`
    (`VARCHAR(60) NOT NULL DEFAULT ''`) came with migration 018.
  - Today only staff see and write them: in `views/student.php`, and in the staff branch of
    `student_save`.
  - Two places read them: `invoice_recipient()` reads the address, and the printed data sheet
    (`views/print.php`) reads both.
- **Invoices are frozen.**
  - `create_invoice()` copies `invoice_recipient()`, address included, into `snapshot_json`
    when an invoice is issued.
  - `invoice_pdf()` builds every download and every mailed copy from that copy.
  - Nothing in `app/` updates `snapshot_json` afterwards.
- **Custom fields.** The same form lets the family edit every custom field whose `visibility` is
  `'edit'` and read those at `'view'`. It does not show the `'internal'` ones
  (`views/student.php`, `save_custom_fields()`).
  - The trainer sets this per field under Einstellungen → Felder, as „Berechtigung für Schüler:
    Nur intern / Ansehen / Ansehen und bearbeiten".
  - The column is in 001: `visibility VARCHAR(20) NOT NULL DEFAULT 'internal'`.
- **Contacts.** On the „Kontakte" tab a family already adds, edits and removes its emergency
  contacts. `contact_add`, `contact_save` and `contact_delete` call `student()` and nothing
  staff-only. **None of them is `tracked()`.**
- **Custom-field history.** `field_values` has a composite primary key, `(student_id, field_id)`,
  and no `id`. `entity_snapshot()` reads `WHERE id=?`, so it cannot track that table as an
  entity. No custom-field change reaches the change log today, the trainer's included.
- **Labels in the change log.** `history_field_label()` has no label for `address`, `phone`,
  `owner_name`, `relation_label` or `is_primary`. A tracked change to any of them would show the
  raw column name.
- **Required fields.** `save_custom_fields(int $id, bool $new)` never reads `$new`.
  - Creating a student posts no custom fields. As read, a field marked required therefore
    refuses the creation of every student.
  - No test covers a required custom field.
- **No undo.** There is no undo, on purpose.
  - `app/history.php` removed it because "a later edit to the same row is silently undone with
    it".
  - `views/history.php` says „Zum Nachlesen, nicht zum Zurücknehmen".
  - `tests/suites/history.php` asserts `!function_exists('revert_version')`.
  - The change log is for administrators only (`public/index.php`).
- **The outbox** never shows the body of a `security` mail (`views/outbox.php`). Staff cannot read
  a link that sets a password.
- **The start checklist** already has the two steps that `account_mail_ready()` asks for: SMTP
  tested and the privacy notice released (`app/start.php`).
- **Billing** reads neither birth date nor age group (`app/billing.php`). A birth date that a
  family types changes nobody's charges.

### Where a password is set today

- `activate`, for an invitation and for a reset;
- `password_change`;
- the rehash in `login`;
- `create_admin_account()`, from setup and from the console;
- `demo_fill()`;
- two places where staff type one: `account_create`, and `student_invite` with `mode=direct`.

## Decision

### 1. One person, one login, one own address (R-a)

**The index stays.** `accounts.email` stays `UNIQUE`: the inline index from 001, named `email`.
**Migration 024 is deleted, not shipped.**

**`refuse_address_in_use(string $email, ?int $exceptId = null): void`**, in `app/auth.php`,
replaces `staff_address_conflict()`. It is the only refusal of an address.

- It refuses outside a transaction, like `lock_row()`.
- It reads `SELECT id, role, name FROM accounts WHERE email=? AND id<>? FOR UPDATE`.
- It throws „Diese E-Mail-Adresse gehört schon zu einem anderen Zugang. Jede Person braucht
  ihre eigene."
  - When staff are asking (`is_staff()`), it adds whose, through `login_holder_name()`.
  - A family is never told a name.
- **Locking [R8].** It relies on REPEATABLE READ for a clean sentence under a race.
  - The index is now the backstop: under any isolation level, a second row is refused with
    23000.
  - The router already turns 23000 into its generic duplicate message.
  - The sentence stays the refusal a person sees (ADR 0010, "Must not").

**It is called in every block that inserts into `accounts`** [R1 stands]. Each call is inside the
block's transaction and before its `INSERT`, beside `username_for_new_account()`:

- `create_admin_account()`: setup, and the console, also with `--force`;
- `account_invite`;
- `invite_student()` (§6), for `student_invite` and for creating a student with an invitation;
- `demo_fill()`, for each of its three logins.

**It is called in every re-addressing**, through `change_account_email()`. That covers
`student_save` for an invited login, and confirming `email_change`.

**`email_change` no longer asks at request time.**

- The link goes to the new address. Opening it runs `change_account_email()`, which refuses an
  address in use.
- Now that the address signs in, "does this address have a login?" is half a credential. The
  sign-in and „vergessen" pages are built not to answer it; a third page that did would undo
  them.
- Whoever reads the refusal is whoever reads that mailbox, who knows already.

**`student_save`** refuses a student's address that is new or changed and already another login's.
This restores ADR 0010's rule.

- A clash already stored is tolerated, so her other edits still save.
- The hint in the view says where a parent's address belongs: on the „Kontakte" tab.

**A student without a login** may carry the same address as another student without a login. An
address becomes a login's only once `invite_student()` makes one. A second invitation to it is
refused with the sentence above.

**`account_with_address()`**, a plain read in `app/domain.php`, **is kept**. The access card and
`student_next_steps()` use it to say, before she taps, that the address is somebody's login and
the student needs their own. `own_address_missing_sql()`, `students_needing_own_address()` and
`own_address_taken_by()` stay deleted.

### 2. Usernames stay (R-b)

Every login gets one, made exactly as 0019 §1–§2 says. The functions are already built in
`app/core.php`, together with `username_for_new_account()`. The runner's backfill (0019 §4)
stays.

**Only the holder changes it**, in two places and through one function.

**`change_own_username(array $account, string $typed, ?int $actor = null): string`** lives in
`app/actions.php`, beside `change_account_email()`. It is `username_change`'s body today, moved:

1. `username_value()`.
2. `'unchanged'` when the value is the same.
3. `lock_row('accounts', …)` [S6].
4. `SELECT id … WHERE username=? FOR UPDATE`. When the name is taken:
   - `throttle('username-taken', account_identity(…), 5, 86400)` [S2];
   - `audit('account.username_taken', …, $actor)`;
   - it returns `'taken'`, without a throw [R3].
5. Otherwise `tracked('accounts', …, $actor)` around the `UPDATE`, then
   `audit('account.username_changed', …, $actor)`, then `'changed'`.

The caller flashes, holds the form and chooses the page.

**The two places:**

- **Mein Konto** (`username_change`) is unchanged. It asks for `current_password` [N5] and
  refuses while staff are impersonating. It passes no `$actor`: the session names the holder.
- **The activation page, invite branch.** The username is an editable box, filled in with the
  generated one.
  - The token proves the holder, so no current password is asked.
  - Before the activating `UPDATE`, a changed value goes through `change_own_username()`, with
    **`$actor = (int)$r['account_id']`**, the token's own login.
  - At that moment nobody is signed in, so without it the change log and the audit log would
    say „automatisch". With it, the line names the person who chose the username.
  - On `'taken'`, the action returns to the activation page with the flash and the form held.
    It has activated nothing.
  - The password boxes come back empty, because `is_secret_field()` never holds them.
- The **reset** branch keeps its read-only box [N10].

**The explicit actor [code review 3].**

- `tracked()`, `history_record()` and `audit()` each gain an optional last parameter,
  `?int $actor = null`.
- `null` means what it means today: the impersonator, otherwise the signed-in login, otherwise
  nobody.
- **Only the activation page passes one**, and only the token's `account_id`, read from the
  locked `token_record()`. It is never taken from posted input.
- Every other caller is unchanged.

**The "taken" answer is the one bounded exception [N3, S2].** Saying „Diesen Benutzernamen hat
schon jemand" tells the person that some login has that username. It is allowed, and only here:

- only to somebody proven to be a login's holder: signed in with their password on Mein Konto,
  or holding a live invitation token on the activation page;
- at most five "taken" answers a day per login (`username-taken`), each audited with its actor;
- a username is not a secret (0019 §1), while an address never gets such an answer.

The sign-in and „vergessen" pages still never answer it, and the "Must not" line says so with
this exception.

Staff never change another person's username (0019 §8).

### 3. Signing in: one box, username or address (R-b)

**The box.** One field, labelled „Benutzername oder E-Mail-Adresse".

- It is posted as `username`, so held input and the existing tests keep working.
- Its attributes are `autocomplete="username"`, `autocapitalize="none"`, `autocorrect="off"` and
  `spellcheck="false"`.

**One derivation**, `attempted_sign_in(): array` in `app/actions.php`, returns `[$kind, $value]`:

- an `@` anywhere in the typed value gives `['address', email_normalised($typed)]`;
- anything else gives `['username', username_normalised($typed)]`.

A username can never contain `@`, because its alphabet is `a–z 0–9 . -`. The two kinds cannot
overlap, and the input's shape alone decides.

**One lookup**, `account_for_sign_in(string $kind, string $value, bool $lock = false): ?array` in
`app/auth.php`:

1. **Format gate [M1].** An address that fails `email_is_dot_atom()`, or a username that fails
   `USERNAME_PATTERN`, is never looked up. It gives `null`.
2. **Two literal statements**, with no column name interpolated:
   - `SELECT * FROM accounts WHERE email=?`;
   - `SELECT * FROM accounts WHERE username=?`.

   ` FOR UPDATE` is appended, as a literal, only when `$lock` is true, the way `token_record()`
   does it.
3. **Exact match.** A row is returned only if `email_normalised($row['email']) === $value`, or
   `$row['username'] === $value`. Otherwise the answer is `null`.
   - A spelling that reaches a row only through `utf8mb4_unicode_ci`, which is the fold ADR 0007
     describes, finds nothing and signs nobody in.
   - So the address path is safe without the per-character collation measurement that 0019
     asked for.
   - A legacy stored address with capitals still matches, because both sides are normalised.
   - A legacy quoted address can never pass the gate. Its holder signs in with the username.

**Sign-in does not lock [F2].**

- **The problem.** Under InnoDB's REPEATABLE READ, `FOR UPDATE` on a row that exists takes an
  exclusive record lock. On a row that is missing it takes only a gap lock, and gap locks do not
  conflict. The lock was held through `password_verify()` until commit. So concurrent sign-ins
  queued behind each other only when the login existed, and the time to answer told an outsider
  whether a username or an address belonged to a member.
- **The fix.** `login` calls `account_for_sign_in(…)` without `$lock`: a plain, non-locking read.
  Known and unknown inputs then take the same path through the engine.
- **The rehash is conditional:**
  `UPDATE accounts SET password_hash=? WHERE id=? AND password_hash=?`, with the hash just
  verified as the last value. If the password changed in between, the newer hash is kept and the
  rehash waits for the next sign-in.
- **Nothing that the lock protected is lost.** A password change, a suspension or a reset that
  commits while a sign-in is between its read and its answer raises `auth_version`. The session
  that sign-in opens carries the old `auth_version`, and `current_user()` ends it on the next
  request.
- **„Vergessen" keeps its lock**: it calls `account_for_sign_in(…, true)` (§4). Its known inputs
  already cost a token and a mail more than unknown ones, which §4 accepts. The lock stops two
  requests at once from leaving two live links.

**The `login` case:**

1. `$a = account_for_sign_in(...attempted_sign_in())`, without a lock.
2. **Exactly one `password_verify()` on every path [M2].**
   - It checks against `$a['password_hash']` when there is one.
   - Otherwise it checks against `sign_in_dummy_hash()`: no row, a format failure, an inexact
     match, or an invitation without a hash.
3. **One refusal for every failure**: a wrong password, no row, a suspended login and an
   unverified one all get the same text.
   - „Anmeldung nicht möglich. Bitte Benutzername oder E-Mail-Adresse und Passwort prüfen. Noch
     nicht eingerichtet? Dann zuerst den Link in der Einladung öffnen."
   - "Could not sign you in. Please check your username or email address and your password. Not
     set up yet? Open the link in your invitation first."
   - It replaces „Zugangsdaten und Einladung prüfen", whose first word is jargon to her and to
     parents.
   - The last sentence keeps what „Einladung" was for: somebody invited but not yet set up is
     told where to go.
   - It is shown for every failure, so it says nothing about whether the login exists, is
     invited or is suspended.
4. **On success** it runs the conditional rehash if needed, then `sign_in()` and
   `landing_after_sign_in()`. There is **no reset flash** (§8, R4).

0019's step 1, which refused any input containing `@`, is deleted. So is the `@` hint on the
sign-in page.

**Throttles**, in `handle_post()` before the transaction, as today:

- `throttle('auth-ip', $ip, 60)` for `login`, `forgot` and `activate`: unchanged.
- `throttle('login', sign_in_identity(attempted_sign_in()), 10)`.
  - `sign_in_identity()` is either `address_identity($value)`, which is `'address:'.$value`, or
    `username_identity($value)`, which is `'username:'.$value`. Each is spelled once, in
    `app/actions.php`.
  - **The typed value, normalised, never the row.** Known and unknown inputs of either kind are
    counted the same way.
  - Resolving the input to its account first would bring back ADR 0007's oracle. It would also
    add a new one: fill the username's bucket, try the address once, and the answer says
    whether the two belong to one login.
- `forget_attempts_after_success()` clears **both** of the proven account's `login` buckets:
  - `username_identity($who['username'])`;
  - `address_identity(email_normalised($who['email']))`.

  It never clears `auth-ip`, `forgot` or `forgot-ip`, as today.

**What this costs, plainly:**

- **Two buckets per login.** Somebody who knows both the username and the address gets 10 + 10
  guesses a quarter hour at one login, instead of 10. That is still inside the per-IP 60. Against
  a 12-byte minimum with the weak list refused, it is accepted.
- **S3 softens.** Typing wrong passwords to lock `lena.mueller` leaves the address path open, and
  the other way round. Lena signs in with the other one.
- **R6 stands, for both paths.** A dormant login with an older, cheaper hash answers measurably
  faster until its next sign-in.

### 4. „Passwort vergessen" (R-b)

**The box** is the same one, with the same derivation as sign-in. It takes a username or an
address, is posted as `username`, and has `autocomplete="username"`.

**Throttles [S7].** Both are counted every time, and neither is ever cleared:

- `throttle_all([['forgot', sign_in_identity(attempted_sign_in()), 3, 3600], ['forgot-ip', $ip, 10, 3600]])`.
- Because a login has two identifiers, up to **6 mails an hour** can reach one login's mailbox,
  and at most 10 an hour from one IP. That is accepted, and `VALIDATION.md` records it.

**The case:**

1. `$a = account_for_sign_in(...attempted_sign_in(), true)`, **with** the lock (§3).
2. If there is an `$a` and `account_mail_ready()` is true:
   - an **active, verified** login gets `send_account_token($a, 'reset')`. That is a link valid
     for an hour, with its username, sent to `$a['email']` as stored and never to what was
     typed [S1];
   - an **invited** login gets **its invitation again**: `cancel_account_mail()`, then
     `send_account_token($a, 'invite')`. The invitation is its way in and records the privacy
     acknowledgement. Sending nothing, as 0019 did, means a call to the trainer;
   - a **suspended** login gets nothing.
3. The answer is always the same and names no address: „Wenn es dazu einen Zugang gibt, ist eine
   E-Mail an dessen Adresse unterwegs."

**`send_sign_in_details()` is deleted.** With one login per address, `send_account_token()`
already writes this mail and names the username. That leaves one mail builder, not two.

A known input costs a token and a queued mail more than an unknown one, and its row lock can make
a second request for it wait. That is a few inserts and a short wait, the same kind of difference
0019 had, and the mail itself tells the mailbox. It is accepted.

### 5. Nobody sets another person's password (R-c, R-d)

**Deleted:**

- the `account_create` case, its form on the Konten page, and `audit('account.created_directly')`;
- `student_invite`'s `mode=direct`, with:
  - its `require_admin()`;
  - its password box on the access card;
  - the sentence „Bis dahin kann eine Administratorin das Konto direkt mit Passwort anlegen."

A post from an old page with `mode=direct` gets an ordinary invitation, or the refusal for mail
that is not ready. It never creates an active login.

**Logins are made only by invitation:** `account_invite` for staff (administrators only, as
today), and `invite_student()` (§6) for a student.

- The person sets their own password on the activation page.
- When mail is not ready, `send_account_token()` refuses with `account_mail_missing()`, which
  names the checklist steps.

**What staff may do:**

- **Resend an invitation**, with `account_state` `reinvite`, as today.
- **Have a reset link mailed**, with a new `account_state` mode, `reset_link`:
  - only for an **active, verified** login. An invited one is re-invited; a suspended one is
    restored first;
  - `account_state`'s existing rules decide who may. An administrator may do it for anybody but
    herself, and a trainer only for a student's login. Nobody does it for their own login, which
    uses Mein Konto or „vergessen";
  - **`reset_link_possible(array $account): bool`** in `app/auth.php` is the one rule for which
    logins may get one: active and verified (§11). The action refuses by it, and the access card
    and Konten show the button by it;
  - it runs `send_account_token($a, 'reset')` to the login's own stored address, then
    `audit('account.reset_link')`;
  - staff see neither the password nor the link, because the outbox hides `security` bodies.

**Exceptions, unchanged:**

- `create_admin_account()` from `public/setup.php`, for the first administrator;
- `bin/console.php create-admin`, for the owner's recovery, also with `--force`;
- `demo_fill()`'s example logins, whose password is shown once.

**R-d.** There is no public sign-up, and nothing is built toward one: no "request access" form,
and no unauthenticated `INSERT`.

### 6. Inviting a family in one step (R-e)

**`invite_student(array $student): array`** lives in `app/actions.php`. It is `student_invite`'s
body today, moved, without `mode=direct` and without `confirm_same_family()`. It returns
`['account_id' => …, 'username' => …]`.

It makes every refusal before its first write:

1. a student who already has a login;
2. `email_value()` of the student's address;
3. `refuse_address_in_use()`;
4. `account_mail_ready()`, refusing with `account_mail_missing()`.

Then it writes:

5. `username_for_new_account()`;
6. the `INSERT` of the invited login;
7. `tracked('students', …)` around the update of `account_id` and `email`;
8. `send_account_token(…, 'invite')`;
9. `audit('account.invited')`.

It is **the only code in `app/` that sets `students.account_id` for a real student**. ADR 0010's
"only `student_invite`" moves here.

- **The one exception is `demo_fill()`** (`app/demo.php`), which inserts its two example
  students with their example logins already set. It writes only `is_demo=1` rows, which
  `demo_clear()` removes, and it never touches a real student.
- Migration 019 and the foreign key's `ON DELETE SET NULL` stay as 0010 describes them.

**`student_invite`**, the access card's button, is `require_staff()`, then `lock_row()` on the
student through `student()`, then `invite_student()`.

**Creating a student with an invitation.** The create form (staff, no id) asks what it asks
today: first name, last name, the student's own address, membership and „dabei seit". It adds one
tick, `invite=1`, „Gleich einladen".

- **With the tick,** `student_save` refuses **before any write** when:
  - the address is empty;
  - the address is invalid;
  - the address is in use;
  - mail is not ready. The refusal gives `account_mail_missing()`, and says that without the
    tick the student is saved now and invited later.

  It then runs `tracked_insert()` for the student and `invite_student()`, all in the action's one
  transaction. The flash names the username.
- **Without the tick,** creating a student works as it does today.
- The designer decides whether the tick starts ticked when `account_mail_ready()` is true. When
  mail is not ready, the tick is not offered, and the card says what is missing.

**The invitation mail** names the username. It says the username can be changed while setting up,
and that the address signs in too.

### 7. The family completes its own details (R-e)

#### Where

On the family's own student page, reached through „Profil" in their menu (ADR 0010):

- the **„Profil" tab**, for names, birth date, postal address, phone and custom fields;
- the **„Kontakte" tab**, for emergency contacts.

There is no new page, no new action and no new view file.

#### What they are prompted to fill in

**`family_next_steps(int $studentId): array`** lives in `app/domain.php`, beside
`student_next_steps()`. It has the same shape, so `next_steps_card()` draws it. It lists what is
still missing:

- a birth date;
- a postal address, by `postal_address_missing()` (below);
- an emergency contact, when `primary_contact()` is null;
- every custom field with `visibility='edit'` that `custom_field_required_of()` (below) says the
  family must fill in and that is still empty.

**The phone is not on the list.** It is the member's own number, and a child may have none; the
emergency contacts are who gets rung. An item that some families can never tick off teaches every
family to ignore the card. The address is on the list, because every family has one and an
invoice above 400 € needs it (below).

The family sees the list on their dashboard and on their student page. The staff page keeps
`student_next_steps()`.

The invite branch of `activate` lands the family on their student page when the list is not
empty. Every later sign-in lands where `landing_after_sign_in()` says.

#### Which data a family writes, and nothing more

| Data | Family | Staff |
| --- | --- | --- |
| First name, last name | edits (as today) | edits |
| Birth date | edits (as today) | edits |
| Postal address (`students.address`), phone (`students.phone`) | **edits** | edits |
| Emergency contacts (`contacts`) | adds, edits, removes; one must remain (as today) | the same |
| Custom field at `visibility='edit'` | edits | edits |
| Custom field at `'view'` | reads | edits |
| Custom field at `'internal'` | does not see it | edits |
| Status, membership dates, level, pinned age group, courses, tariff, price, billing, internal notes, the student's email | does not write | edits; the email only while there is no login, or while it is still invited |
| The login's own address, username and password | on Mein Konto, as today | never |

- A family's `student_save` writes `first_name`, `last_name`, `birth_date`, `address` and
  `phone`, and nothing else.
  - `address` and `phone` get the limits the staff branch already uses: `text_limit('address', 200)`
    and `text_limit('phone', 60)`.
  - Both columns are `NOT NULL DEFAULT ''` (018), so an empty box is `''`, never NULL.
- For a family, `save_custom_fields()` skips every field that is not at `'edit'`, as today.
- A new birth date can move the age group worked out from it. It cannot move a pinned age group.
  Billing reads neither.

**Schema: none.**

- "The family fills this in" is already `field_definitions.visibility = 'edit'` (001). The
  settings page offers it as „Ansehen und bearbeiten".
- "Required" is already `field_definitions.required` (001).
- A second flag would be a second answer to the same question, and the two could disagree.
- The address and phone columns exist since 018.

#### The address a family writes, and invoices

`students.address` is the recipient's address on an invoice (`invoice_recipient()`). The phone
is on no invoice.

- **Issued invoices do not change.**
  - `create_invoice()` copies the recipient, address included, into `snapshot_json` at issue.
  - `invoice_pdf()` builds every download and every mailed copy from that copy.
  - Nothing writes `snapshot_json` again.

  A family that moves changes the address on the next invoice and on no earlier one. That is
  what the header of `app/invoices.php` already promises.
- **The next invoice uses whatever is on the student when she issues it.** The family is the
  recipient, so the family typing its own address is where that address should come from.
- **Above 400 € gross** (`INVOICE_ADDRESS_FROM_CENTS`), `create_invoice()` refuses without an
  address, as today.
  - A family that empties the box makes such an invoice wait until somebody fills it in.
  - Staff can, on the same page; the refusal already says where.
  - The address is on „Noch zu ergänzen" for exactly this reason.
- **`postal_address_missing(array $student): bool`** in `app/invoices.php`, beside
  `INVOICE_ADDRESS_FROM_CENTS`, is the one "address missing" rule: the address, trimmed, is
  empty. `create_invoice()`'s refusal and `family_next_steps()` both ask it (§11).
- **The printed data sheet** (`views/print.php`) prints the current address and phone. It is a
  form to fill in or check, not a record of what was billed, so it follows the student.
- Nothing else in `app/` or `views/` reads either column.

#### Required custom fields

A required field is required of whoever fills it in.

- **Fields at `'edit'` are the family's.**
  - A family's save is refused while one is empty, with „Pflichtfeld: …" as today.
  - A staff save never refuses an empty `'edit'` field. She may fill it in, and need not.
  - Until it is filled, the field is on the family's list above.
- **Fields at `'view'` and `'internal'` are staff's.** A staff save of an existing student is
  refused while one is empty, as today.
- **Creating a student** refuses none. The create form carries no custom fields, because "a form
  with twenty boxes is a form somebody abandons". That is what the unused `$new` was for.
- **`custom_field_required_of(array $field, bool $staff): bool`** in `app/domain.php` is the one
  "required of whom" rule (§11):
  - true for a family only when the field is required and at `'edit'`;
  - true for staff only when the field is required and not at `'edit'`.

  `save_custom_fields()`, `family_next_steps()` and the view's `required` attribute and mark all
  ask it. Creation passes nothing to it, because it validates no emptiness.
- `validate_custom()` is told whether an empty value is refused. The type checks (date, number,
  option) apply to every value that is not empty, for everybody.

#### What a family sees

**One change from today:** the „Profil" tab shows the family its own postal address and phone,
in the student form, editable.

**Everything else stays as today**, read from `views/student.php`, `student()` and the router:

- their own student only;
- the tabs Profil, Kontakte, Beiträge, Rechnungen, Kurse and Abwesenheit, but not Anwesenheit;
- no access card, no internal notes and no `'internal'` fields;
- no change log, which is for administrators only.

#### Authorisation

Every family write names its student by id. It is authorised by `student()`'s scoping,
`s.account_id = <the signed-in login>`, and by nothing else.

- `student_save` calls `student($id)` before anything, and requires staff when there is no id, as
  today.
- The contact cases call `student()` and match `WHERE id=? AND student_id=?`, as today.
- No student id is kept in the session. No action trusts a posted `student_id` without `student()`
  (ADR 0010, "Must not").

#### Every change is `tracked()`, staff's and families' alike

- **`student_save`, both branches.**
  - The `students` `UPDATE`, address and phone included, and `save_custom_fields()` run inside
    **one** `tracked('students', …)`, after the revision check.
  - One save is then one line in the change log.
  - A stale form is refused as a whole, custom fields included.
  - Creating a student runs `save_custom_fields()` inside its `tracked_insert()`.
- **Custom fields, as part of the student.** For `students` only, `entity_snapshot()` in
  `app/history.php` adds every `field_values` row of that student as a pseudo-column
  `field:<field_id>` holding its `value_json`.
  - `history_field_label()` names `field:<id>` through `field_label()`. That is a call into
    `app/domain.php`, made only at request time and never while loading. It is the same pattern
    as `avatar()` calling `upload_version()`, and it carries the same kind of comment.
  - `history_value()` decodes the JSON: a list is joined with commas, and a checkbox reads ja or
    nein.
  - A deleted student's snapshot now keeps their custom values, which is what a deletion's row is
    for.
  - `field:` keys never reach SQL, because there is no undo to write them back.
- **Contacts:**
  - `contact_add` uses `tracked_insert('contacts', …)`;
  - `contact_save` uses `tracked('contacts', …)`;
  - `contact_delete` uses `tracked('contacts', …, 'delete')`.

  The label is the student's name and the contact's. Moving `is_primary` off another contact stays
  bookkeeping and is not tracked.
- **Labels.** `history_field_label()` gains, in both languages:

  | Column | German | English |
  | --- | --- | --- |
  | `address` | Anschrift | Postal address |
  | `phone` | Telefonnummer | Telephone number |
  | `owner_name` | Name | Name |
  | `relation_label` | Beziehung | Relationship |
  | `is_primary` | Standardkontakt | Standard contact |

  `phone` serves the student and the contact alike.
- **The actor** is the family's login, or the staff member who is impersonating it. That is how
  `history_record()` already works. The one change made with nobody signed in, a username chosen
  on the activation page, names its chooser through the explicit actor (§2).

#### Undo: she sets the value back

R-e, as the project manager has settled it:

- **She sees the old value in the change log and types it back.** The log names who changed what,
  and when, with the value before and after.
- **Every family-writable field stays staff-writable**, on the same page. That covers names, birth
  date, postal address, phone, every custom field and every contact. Nothing a family can write is
  ever beyond her reach.
- **A deleted contact** she re-adds from the log's deletion line, which keeps the whole row.
- **There is no undo button,** and this record does not build one. The undo was removed on purpose
  (Context).

No code is needed beyond the tracking above.

### 8. What of ADR 0019 survives

| 0019 | Under 0020 |
| --- | --- |
| §1: the username alphabet, lower case, no reserved names, not a secret | **Unchanged.** |
| §2: the username functions in `core.php`, and `username_for_new_account()` | **Unchanged.** |
| `username_change`: R3 (answer rather than throw), S2 (five "taken" a day), S6 (`lock_row` before `tracked`), N5 (`current_password`) | **Unchanged.** They now live inside `change_own_username()`, so the activation page follows the same rules. S2's "taken" answer is the one bounded exception to "never tell" (§2). |
| M1, the dot-atom gate for writes and lookups; R2, `email_deliverable()` in `queue_mail()` | **Unchanged.** M1 now also gates sign-in. The exact-match check (§3) makes a collation fold harmless without it. |
| M2, one `password_verify()` per failure path against the dummy hash; R9, the hash made by the runner and the nightly prune and repaired once by a request | **Unchanged**, and extended to the address path. |
| 0019 §5's `FOR UPDATE` on the sign-in lookup | **Removed** (§3, F2). Sign-in reads without a lock, and the rehash is conditional. |
| R6, the timing residual of a dormant hash | **Stands**, on both paths. |
| R7, the sender compares normalised addresses; S1, the sender checks every token and mails the stored address | **Unchanged.** A forgot mail now carries one link, and the sender still checks every one. |
| S4, `audit('account.password_reset')` on every reset | **Unchanged.** It also makes a link that staff asked for attributable. |
| S4, "N other accounts use this address" on Mein Konto and the access card; `accounts_sharing_address()` | **Removed.** No address can be shared. |
| R4, the red sign-in flash for 14 days after a reset; `own_password_reset` | **Removed.** With an own mailbox, the reader is the holder. The flash's only reader would be the person who just reset it, warned for two weeks that something may be wrong. |
| I1, one 14-day window (`PASSWORD_RESET_SHOWN_DAYS`) | **Stands**, for the list on Mein Konto and the line on the access card. The wording becomes „per E-Mail-Link", since staff can now ask for one. |
| I2, staff read a different last sentence | **Stands** on Mein Konto. The flash it also split is gone. |
| I3, the isolation assertion trying both variable names; the R8 comments | **Unchanged.** |
| I4: `held_for()`, `token_record()` with `username`, the delete confirmation through `username_normalised()` | **Unchanged**, except that `held_for()` now also matches the record a form edits (§10a). The row shape of `logins_on_address()` is **removed**. The data sheet's label changes again (`views/print.php`, under `frontend-dev`). |
| R5, `history_never_recorded()` for every operation | **Unchanged.** |
| S3, the lock-out risk the owner accepted | **Stands, softened** (§3). |
| S7, both forgot throttles counted every time | **Unchanged** in form. The identity is the typed value of either kind. |
| R1, every creator calls the guards | **Unchanged** in form. The guards are `refuse_address_in_use()` and `username_for_new_account()`. |
| M3, staff need their own address | **Superseded** by R-a: everybody does. |
| R10, the Konten flag for a staff login and a family on one address | **Removed.** The index forbids the pair. |
| S5, „Gleiche Familie": `confirm_same_family()`, `same_family`, `logins_on_address()` | **Removed.** |
| §3, migration 024 and the SQLite rebuild without the inline `UNIQUE` | **Removed.** 022 and 023 stay. |
| §4, the runner's backfill, and no mail from the update | **Unchanged.** Now nobody needs telling: whoever signed in with an address still does. |
| §5, sign-in by username only, the `@` refusal and its hint | **Replaced** by §3. |
| §6, `send_sign_in_details()` and its one mail listing every login | **Replaced** by §4. |
| N10, the read-only username on the reset page | **Stands.** The invitation page makes it editable (§2). |
| §9, `change_account_email()` and its duties; staff never re-address an active login | **Unchanged**, with `refuse_address_in_use()`. |
| §11, the demo: usernames read back, no address shared | **Unchanged**, with `refuse_address_in_use()`. |
| 0019's open question for the owner: should the data sheet print the username? | **Closed: no.** The address signs in, so the sheet needs no username. |
| 0019's owner item: the privacy sentence on shared mailboxes | **Withdrawn.** |

### 9. Schema

**No schema change** beyond what the owner approved for 0019.

- `022_a_username_for_every_account.sql` and `023_usernames_are_unique.sql` stay. Their SQL does
  not change.
- `024_an_address_may_be_shared.sql` is deleted before it ships.
- `students.address` and `students.phone` already exist (018).
- `schema_guarded_tables()` is not widened, and nothing is deleted.
- The update needs no command. The runner applies 022 and 023, then names every login in
  `database/defaults.php`.

### 10. The screen specification's asks, decided

The designer's specification (`accounts-ui-spec.md`) asked for five things this record had not
covered. **Each is accepted.** None needs a schema change.

**a. A held form belongs to the record it edits** (spec §1.3). **Yes.**

- **The bug, reproduced by the designer.** A refused `contact_save` of one contact is written
  into every contact's edit form on the page. Saving the wrong one then overwrites a person.
- **The cause.** `form_context()` holds the action's name only, and `held_for()` matches page,
  page id and tab, never the record the form edits.
- **The fix**, in `app/core.php`:
  - `remember_input()` also stores the form's own record: the posted `id`, when it is a
    positive integer.
  - `form_open()` records the hidden `id` it writes alongside the action, in the form context.
  - `held_for(string $action, ?int $record = null)` refuses a match when both the held
    submission and the form have a record and they differ.
  - `holding_input()` and `held_input()` pass the form context's record.
  - A form without an `id` (`contact_add`, the create form) matches as before.
- **Why it is the right place.** Every page with one form per record (contacts, enrolments,
  charges) is then safe, not only the contacts tab. It is the general fix to the cause, in the
  one function 0019 I4 made the single place for that match.
- **The view** opens the refused contact's `<details>` with `held_for('contact_save', $contact['id'])`.

**b. „Von Familien" in Änderungen** (spec §10). **Yes.** It is how she sees a family's changes
(R-e), on the page she already has.

- `history_recent()` and `history_for()` select the actor's role, as `a.role AS actor_role`, from
  the join they already make.
- `history_recent(int $limit = 60, bool $familiesOnly = false)`: when true, it adds the literal
  condition `a.role='student'`. No value is interpolated.
  - A change made while staff viewed the portal as a family is recorded under the staff member
    (`history_record()`), so it is not "by a family". That is correct.
  - A version whose login has since been deleted has no actor, and falls out of the tab. That is
    accepted: the change still shows under „Alle".
- **The role is read when the page is read, not stored with the change.** Storing it would be a
  column on `record_versions`, and a migration, for a filter.
- **`student_id` is hidden on a contact's line**, because the label already names the child.
  - It is hidden **for `contacts` only.** On another entity, such as a charge, `student_id` would
    be the only thing saying whose it is.
  - `tracked_entities()` gains an optional `hidden` list per entity, `'contacts' => ['student_id']`.
    `version_changes()` skips those columns beside its bookkeeping ones.
- **`history_value()` shows a value shaped exactly `YYYY-MM-DD` through `fmt_date()`.**
  - `local_time()` does not shift a DATE and requires the value to round-trip, so a calendar date
    stays the day it was.
  - A custom date field, stored the same way, reads the same.
  - A `DATETIME` has a time part, and is left alone.

**c. The invited-login line reads the invitation's dates** (spec §6.6). **Yes.** It is what she
needs when a parent says "the link doesn't work".

- `invitation_dates(int $accountId): ?array` in `app/auth.php`, beside `token_record()`.
  - It reads `SELECT created_at, expires_at FROM auth_tokens WHERE account_id=? AND purpose='invite' ORDER BY id DESC LIMIT 1`.
  - **It never selects `token_hash`.** The view reads the dates through this function and does
    not query `auth_tokens` itself.
- **Expired tokens are deleted every night** (`prune_expired()` in `app/tick.php`). So there is
  usually no row for an expired invitation.
  - The line then says the invitation is no longer valid, **without a date**.
  - It does not guess one from `accounts.created_at`, which a later re-invitation would make
    wrong.
- The card is for staff only, as today.

**d. Four changes in `app/ui.php`** (spec §1.1). **Yes to each.** They replace this record's
"only comments change" for that file.

- **`check_field()` gains a `$hint`**, printed inside the label through `e()`. It is a parameter,
  not a new escaping helper, so the `structure` suite needs no new name.
- **`select_field()` prints the required mark as `input()` does.** That is one look for one
  meaning.
- **`mail_not_ready_notice(array $user): void`** is one wording for the three places an invitation
  can be blocked, instead of three.
  - It prints `account_mail_missing()` through `e()`.
  - It links to `start` for an administrator only, since that page is administrator-only.
  - It only reads.
- **`students_notice()` loses `$also`.** Its only caller was the siblings notice, and the two calls
  left in `views/students.php` do not pass it. It is dead code, and deleted.

**e. The picture card moves below the student form**, for both roles (spec §6.0). **Yes.** It is
a layout choice for the designer; the rule it has to keep is the one it keeps. The card stays
outside the `student_save` form, because a form inside a form is thrown away. Nothing else moves
with it.

### 11. One rule, one function

Each of these rules is asked in more than one place. Each is written once, and every place asks
the function. A second spelling is the one that gets forgotten.

| Rule | Function | Asked by |
| --- | --- | --- |
| Which logins may get a reset link: active and verified | `reset_link_possible(array $account): bool`, `app/auth.php` | `account_state` `reset_link`; the access card; Konten |
| The postal address is missing: trimmed, it is empty | `postal_address_missing(array $student): bool`, `app/invoices.php`, beside `INVOICE_ADDRESS_FROM_CENTS` | `create_invoice()`'s refusal above 400 €; `family_next_steps()` |
| Whom a custom field is required of | `custom_field_required_of(array $field, bool $staff): bool`, `app/domain.php` | `save_custom_fields()`; `family_next_steps()`; the view's `required` attribute and mark |
| Who sets `students.account_id` | `invite_student()`, `app/actions.php`, with `demo_fill()` as the one named exception for its example students (§6) | `student_invite`; `student_save` when creating with an invitation |

None of the first three exists at `722b8f9`; each is new.

**Load order.** `family_next_steps()` in `app/domain.php` calls `postal_address_missing()` in
`app/invoices.php`, which is loaded later.

- The call happens at request time only, never while loading. That is the same pattern as
  `avatar()` calling `upload_version()`, and it carries the same kind of comment.
- Neither file moves in the load order.

## Rejected

**Keeping a shared address for students only.** The owner reversed it: "one person = one login =
one own e-mail address" (R-a), and a child without an address is "their own problem".

**Signing in by username only, with an `@` hint (0019 §5).** Her words: "Even having a username
should not mean an email is not used." A family that remembers one of the two should not need the
other.

**Two boxes, or a switch between username and address.** Two things to explain instead of one.
The `@` decides, because a username cannot contain one.

**Counting a sign-in against the account rather than the typed value.** This is ADR 0007's
oracle. It would also link a username to an address in about eleven requests. Not doing it costs
two buckets per login (§3), which is accepted.

**Relying on the per-character collation measurement [M1] for lookups by address.** Nobody has
automated it. The exact-match check closes the fold by construction on every engine, and the
default suite can test it.

**Locking the row on sign-in, as 0019 §5 did [F2].** A found row takes a record lock and a missing
one only a gap lock, so concurrent sign-ins wait only for a member's login. The lock protected
nothing that `auth_version` does not already protect.

**Locking only after the password has verified.** The lock itself would still be taken only for
an existing login, and the rehash needs no lock once it is conditional.

**A silent per-login cap on forgot mails.** A second counting mechanism, to turn six mails an hour
into three.

**Keeping direct creation for "the first evening"** (`account_create`, `mode=direct`). The owner:
"setting initial passwords is pointless". The demo logins and setup's own administrator cover
trying the portal out.

**A temporary password, changed at the first sign-in.** It is still a password somebody else knew,
and said or sent. The invitation does the same job without one.

**Letting staff copy a reset or invitation link to send another way.** A link that sets a password
is as good as the password. The outbox hides `security` bodies for exactly that reason.

**A public sign-up with manual approval, or one invitation link shared by everybody.** R-d rules
out both, because of bots and spam. A shared link lets whoever holds it register. A per-person
invitation ties one address to one person.

**Leaving invited logins out of „vergessen" (0019 §6).** A family that lost the mail would ring
the trainer. Sending the invitation again keeps the privacy acknowledgement that activation
records.

**Refusing an address in use when `email_change` is asked.** It would tell a signed-in family
whether an address has a login. Refusing at confirmation tells only the reader of that mailbox.

**Refusing the "taken" answer altogether [N3].** A holder choosing a username must learn that the
name is taken, or the change silently fails. The answer is bounded, audited and given only to a
proven holder, about a value that is not a secret.

**Signing in before the activation page changes the username, to get an actor from the
session.** A `'taken'` answer must activate nothing, and a session opened before a refusal is not
undone by the rollback. The explicit actor is one optional parameter and touches no other caller.

**Asking for more at creation (birth date, contacts, address, fields).** R-e: the family fills
them in.

**A new column such as `family_editable` on `field_definitions`.** `visibility='edit'` already says
it. A second flag could contradict the first, and it would be a migration for nothing.

**A separate „Meine Angaben" page.** The family's student page is that page already, and
`student()` already scopes it. A second page would be a second copy of the form and of its
authorisation.

**Keeping the postal address staff-only because it prints on invoices.** An issued invoice is
frozen in `snapshot_json`, so a family's edit cannot reach one. The family is the recipient; its
own address is best typed by it.

**A separate billing address, or freezing the address once an invoice exists.** It would be a
second address to keep in step, and a schema change for a problem the snapshot already solves.

**Putting an empty phone on „Noch zu ergänzen".** A child may have no number of their own, and an
item that can never be ticked off teaches families to ignore the card. The emergency contacts are
who gets rung.

**Tracking `field_values` as an entity of its own.** It has no `id`, so it would need a migration
adding one: schema for the sake of a log. It would also put two lines in the log for one save.

**Logging custom fields from inside `save_custom_fields()` with `history_record()`.** That
bypasses `tracked()`, and one save becomes two lines that can disagree about when it happened and
who did it.

**Rebuilding an undo.** `app/history.php` records why it went: a later edit to the same row would
be silently undone with it. Typing the value back cannot do that, and every family-writable field
is staff-writable, so it always can be typed back.

**Keeping the reset flash [R4].** With one's own mailbox, its reader is the person who just reset
the password. Two weeks of a red warning would bring the calls the owner wants gone.

**Keeping `send_sign_in_details()` for a list of one.** That is two mail builders for one link.

**Restoring `students_needing_own_address()` and its lists.** A clash is now refused when the
address is written. The one kind that can still arise takes two unlucky steps: a sibling saved
first, then the other invited. The access card says so for that student, and a dashboard list for
it would be noise.

**Confirming a deleted login by address again.** 0019 I4 kept the username. A unique address would
work too, but changing it back is churn for the same safety.

**Printing the username on the data sheet.** It is not needed, because the address signs in.

**„Zugangsdaten" in the sign-in refusal.** It is jargon to her and to parents. The replacement
(§3) names the two things to check, and is equally silent about whether the login exists.

**Fixing the held-contact bug in the view** (spec §10a), for example by opening only one
`<details>`. The values would still be written into every form's fields. Only `held_for()` can
tell the forms apart.

**Storing the actor's role on each change-log line** (§10b). It is a column on `record_versions`
and a migration, for a filter the join already answers.

**Hiding `student_id` on every entity's line** (§10b). On a charge's line it is the only thing
saying whose charge it was.

**Guessing an expired invitation's date from `accounts.created_at`** (§10c). A later
re-invitation makes it wrong, and a wrong date is worse than none.

## Consequences

- **Owner.**
  - Decided by the project manager from her words: R-a to R-e, family-writable address and phone,
    and the undo by typing the value back.
  - Already approved: 022 and 023.
  - **Nothing to decide in the design.**
  - **One release, not blocking during the beta.** `docs-writer` drafts a privacy-notice sentence
    about the change log, and she releases it. This touches how her families' data is handled.
    Changes to a student's details are now kept in the change log with their value before and
    after. That covers names, birth date, postal address, phone, contacts and custom fields, and
    custom fields and contacts may hold health notes. They stay for as long as „Änderungen
    aufbewahren (Monate)" says, 24 by default. Only administrators read it.
- **Records.**
  - ADR 0019 becomes `accepted, amended by 0020`, with a note naming the parts that no longer hold.
  - ADR 0010 becomes `accepted, amended by 0019, 0020`. Its address rules hold again, and its note
    says what still differs.
  - ADR 0007 stays superseded by 0019, with one line saying 0020 keeps the channel closed.
- **Actions:** one fewer case (`account_create`). `reset_link` is a mode of `account_state`, not a
  new case.
- **Load order:** no new file.
  - New functions in `auth.php`: `refuse_address_in_use()`, `account_for_sign_in()`,
    `invitation_dates()` and `reset_link_possible()`.
  - New in `domain.php`: `family_next_steps()` and `custom_field_required_of()`.
  - New in `invoices.php`: `postal_address_missing()`.
  - New in `actions.php`: `attempted_sign_in()`, `address_identity()`, `sign_in_identity()`,
    `change_own_username()` and `invite_student()`.
  - New in `ui.php`: `mail_not_ready_notice()`.
  - `history.php` calls into `domain.php`, and `domain.php` into `invoices.php`, at request time
    only, each with a comment saying so.
- **Dependencies:** none.

### `ui-ux-designer`

The 0019 screen specification needs respecifying before `frontend-dev` starts. It is
`username-ui-spec.md` in the session's scratchpad, and its replacement, `accounts-ui-spec.md`,
has been checked against this record (§10).

- **Keep:** §1.1, §1.2, §1.3, §1.4 and §1.6.
- **Superseded:**
  - §2, the sign-in page: one box, and no `@` notice;
  - §3, „vergessen": one box, no sentence about siblings, and one mail with one link;
  - §4, activation: the username is editable on the invite branch;
  - §5, Mein Konto: no sharing lines, and the reset list reworded;
  - §6.1–6.3, the student page: no „Gleiche Familie" tick, no direct creation and no sharing count;
  - §6.4: a family's view of its student page changes (below);
  - §7, Konten: no direct form and no R10 flag;
  - §9, the data sheet;
  - §11.
- **New:**
  - the create form's „Gleich einladen" tick, and what it says when mail is not ready;
  - the access card's line when the address is somebody's login;
  - the `reset_link` button on the access card and on Konten;
  - the family's „Noch zu ergänzen" card on their dashboard and their student page;
  - the family's postal address and phone on their „Profil" tab. That includes the address hint
    about invoices above 400 €, worded for a family rather than for her.
- **Its open issues:**
  - 1 is decided by the owner: the forgot page keeps „Oder frag deine Trainerin – sie kann dir
    deinen Benutzernamen sagen.";
  - 2 is closed: no;
  - 3 is settled at 14 days;
  - 4 is settled: I2 stands on Mein Konto.
- **Measure on an iPhone** whether `inputmode="email"` on the one sign-in box helps. It brings `@`
  and `.` to the keyboard. Keep it only if typing a username does not suffer.

### `database-engineer`

- Delete `database/migrations/024_an_address_may_be_shared.sql`.
- Keep the SQL of 022 and 023 byte for byte. For the header comment of 022, see Open issue 1.
- In `tests/sqlite-driver.php`, delete `dropIndex()` and `dropInlineUnique()` and the dispatch to
  them. With 024 gone, no migration drops an index, and git keeps the code.
- In `tests/migration-data.php`:
  - delete the R10 pair, and every row that needs two logins on one address;
  - keep the username backfill cases and R2's legacy quoted address;
  - add one case: after 023, a second login on an existing address is refused with 23000.
- In `tests/suites/migrations.php`:
  - "logins may share an address and never a username" becomes "never an address, never a
    username", on SQLite and on the real engine;
  - delete the R10 runner case;
  - keep R8/I3 and R9.
- In `database/defaults.php`, change only the comments: 022 to 023, and the address still signs in.
- Recreate, rather than repair, any development database that ran the uncommitted 024.
  `console status` reports it as "extra", and the portal would refuse it.
- Run the whole suite on MariaDB 10.11.14 before anybody claims it works there. MySQL 8.0 stays
  unverified.

### `backend-dev`

**`app/auth.php`**

- **Delete:** `staff_address_conflict()`, `logins_on_address()`, `accounts_sharing_address()` and
  `send_sign_in_details()`.
- **Add:** `refuse_address_in_use()` (§1), `account_for_sign_in()` with `bool $lock = false`
  (§3), `invitation_dates()` (§10c) and `reset_link_possible()` (§11).
- **Keep:**
  - `username_for_new_account()`: its block comment now names `refuse_address_in_use()` as the
    other guard;
  - `login_holder_name()`, now called by `refuse_address_in_use()`;
  - `PASSWORD_RESET_SHOWN_DAYS` and `password_resets_for()`;
  - `sign_in_dummy_hash()` and `refresh_sign_in_dummy_hash()`;
  - `give_every_account_a_username()`, with its comment changed to "022 to 023";
  - `throttle_all()` and `token_record()`;
  - `send_account_token()`: the invitation text changes (§6).
- **Change:** `create_admin_account()` calls `refuse_address_in_use()` in place of
  `staff_address_conflict()`.

**`app/actions.php`**

- **Delete:**
  - `confirm_same_family()`;
  - `recent_password_reset_notice()` and both its calls;
  - `$_SESSION['own_password_reset']`;
  - `attempted_email()` and `attempted_username()`;
  - the `account_create` case and the comment above it.
- **Add:** `attempted_sign_in()`, `address_identity()`, `sign_in_identity()`,
  `change_own_username()` with `?int $actor = null`, and `invite_student()`.
- **Keep:** `staff_role_posted()`, `refuse_unless_student_login()`, `account_identity()`,
  `username_identity()`, and `change_account_email()`, which now calls `refuse_address_in_use()`.
- **Change:**
  - `handle_post()`: the throttles in §3 and §4;
  - `forget_attempts_after_success()`: both buckets;
  - `login` (§3): the lookup without a lock, the conditional rehash, and the new refusal text;
  - `forgot` (§4): the lookup with the lock;
  - `activate`:
    - invite branch: the username through `change_own_username(…, (int)$r['account_id'])`, the
      flash naming the username and the address, and the landing (§7);
    - reset branch: the audit entry stays, with no id in the session;
  - `account_invite`: calls `refuse_address_in_use()`;
  - `student_invite`: calls `invite_student()`;
  - `account_state`: adds `reset_link`, refused unless `reset_link_possible()`;
  - `student_save`:
    - the address refusal (§1);
    - creating with an invitation (§6);
    - the family branch writes `address` and `phone` too, with the staff branch's limits (§7);
    - tracking both branches, custom fields inside (§7);
    - the call to `confirm_same_family()` goes;
  - `contact_add`, `contact_save` and `contact_delete`: tracked.

**`app/actions_settings.php`**

- `username_change` calls `change_own_username()`, with no actor. It keeps the impersonation
  refusal and the `current_password` check. The flash says the person signs in with the new
  username "oder mit deiner E-Mail-Adresse".
- `email_change` drops its `staff_address_conflict()` call. A comment says why the check happens at
  confirmation.

**`app/core.php`**

- `remember_input()`, `form_open()` and `form_context()`, `held_for()`, `holding_input()` and
  `held_input()`: the record match (§10a).
- `held_for()`'s docblock no longer mentions „Gleiche Familie".
- `audit()`: the optional `?int $actor = null` (§2).

**`app/domain.php`**

- **Delete:** `own_address_missing_sql()`, `students_needing_own_address()`,
  `own_address_taken_by()`, and the comment block above them.
- **Keep:** `account_with_address()`, with a docblock naming its two callers.
- **Add:** `family_next_steps()`, with the postal address and without the phone (§7), and
  `custom_field_required_of()` (§11).
- **Change:**
  - `student_next_steps()`: before „Zugang einladen", a step for an address that is somebody's
    login;
  - `save_custom_fields()` and `validate_custom()`: the rules in §7, through
    `custom_field_required_of()`.

**`app/history.php`**

- **Change:**
  - `tracked()` and `history_record()`: the optional `?int $actor = null` (§2);
  - `entity_snapshot()`;
  - `history_field_label()`: `field:<id>`, and the five labels in §7;
  - `history_value()`: `field:` JSON, and `YYYY-MM-DD` through `fmt_date()` (§10b);
  - `tracked_entities()`: the `hidden` list for `contacts` (§10b);
  - `version_changes()`: skips the entity's hidden columns (§10b);
  - `history_recent()`, with `$familiesOnly`, and `history_for()`: `actor_role` (§10b);
  - the comment on `history_login()`.
- **Keep:** `history_never_recorded()`.

**`app/invoices.php`**

- **Add:** `postal_address_missing()` beside `INVOICE_ADDRESS_FROM_CENTS`. `create_invoice()`'s
  refusal above 400 € asks it (§11).
- `invoice_recipient()` keeps reading `students.address`.
- The comment above its return says "for a child is a parent's address". It becomes: the address
  the family keeps on its own „Profil" tab, frozen into each invoice when it is issued.

**Other files**

- **`app/mail.php`:** only the comment above `security_mail_links_live()`.
- **`app/demo.php`:**
  - calls `refuse_address_in_use()`;
  - a comment at its `INSERT INTO students` says it is the one named exception to "only
    `invite_student()` sets `account_id`", for example rows only (§6);
  - the other comments change;
  - the example students get addresses of their own, such as `vorname.nachname@beispiel.test`,
    not `eltern.…`.
- **`public/setup.php` and `bin/console.php`:** the wording says to sign in with this username or
  the email address.

### `frontend-dev`

Working from the designer's new specification:

- **`views/login.php`:** one box. Delete `$typedAddress` and the `@` notice.
- **`views/forgot.php`:** one box, for a username or an address. Delete the sentence about
  siblings. **Keep** „Keine E-Mail bekommen? Schau auch im Spam-Ordner nach. Oder frag deine
  Trainerin – sie kann dir deinen Benutzernamen sagen." as it is; the owner decided.
- **`views/activate.php`:**
  - invite: the username is editable;
  - reset: it stays read-only;
  - email branch: "mit Benutzernamen oder E-Mail-Adresse".
- **`views/profile.php`:** delete the `$sharing` block and reword the reset block. Username, email
  and password stay.
- **`views/student.php`:**
  - **delete:**
    - both `same_family` ticks;
    - the call to `logins_on_address()`;
    - the siblings `students_notice`;
    - the „Ohne E-Mail anlegen" details;
    - the sharing count;
  - **add:**
    - the create form's tick;
    - the access card's line saying the address is somebody's login, with no invitation button;
    - the invited-login line from `invitation_dates()` (§10c);
    - `reset_link` for a login where `reset_link_possible()` says so;
    - for a family, `next_steps_card(family_next_steps($id))`;
    - for a family, the address and phone inputs inside the student form. They leave the
      `if($staff)` block but stay inside the form, so one save carries them;
    - the `required` attribute and mark on custom fields through `custom_field_required_of()`;
    - the refused contact's `<details>` open through `held_for('contact_save', $contact['id'])`
      (§10a);
  - **move** the picture card below the student form, still outside it (§10e);
  - **fix** every hint and comment that says siblings may share an address.
- **`views/accounts.php`:**
  - delete the `account_create` form, `$sharedAddresses` and the `$alongside` notice;
  - add `reset_link` for staff logins other than her own, where `reset_link_possible()` says so;
  - keep the username line.
- **`views/dashboard.php`:** the family's card.
- **`views/history.php`:** the „Alle / Von Familien" tabs on the unscoped list, and „(Familie)"
  after a family actor's name (§10b).
- **`views/print.php`:**
  - the label becomes „E-Mail-Adresse (Anmeldung, Einladung, Rechnungen)";
  - the hint becomes „Die eigene Adresse der Schülerin oder des Schülers – die der Eltern gehört zu
    den Kontakten", replacing „Bei einem Kind die Adresse eines Elternteils".
- **`views/students.php`:** the comment only.
- **`app/ui.php`:**
  - the four changes in §10d;
  - `username_attributes()`, `login_facts()`, `login_delete_details()` and
    `demo_password_notice()` stay, and only their comments change.

### `qa-tester`

Break each of these once on purpose and watch it fail.

1. **One address per login.**
   - A second login on an address is refused by `refuse_address_in_use()` with its sentence,
     before any write.
   - Bypassing that, the database refuses it with 23000, on SQLite and on MariaDB.
   - Every block with `INSERT INTO accounts` calls both guards [R1].
   - No other refusal of an address exists.
2. **Sign-in.**
   - The username signs in; the address signs in; `Lena.Müller` signs in as `lena.mueller`; and so
     does `LENA@Beispiel.AT`.
   - An address that reaches a row only through the collation does not sign in. This needs the
     real collation, so SQLite declares it unsupported.
   - A non-dot-atom address and a malformed username reach no `SELECT`.
   - Every failure path runs one `password_verify()`, and `login` contains no literal `$2y$`.
   - Every failure path gets the one refusal text, and it no longer contains „Zugangsdaten".
   - A known and an unknown input, of each kind, lock at the eleventh try exactly alike.
   - With the username bucket full, the address still signs in.
   - Success clears both buckets.
3. **Sign-in takes no lock [F2].**
   - **Structure:** the `login` path's SQL contains no `FOR UPDATE`; `forgot`'s does.
   - **On MariaDB, not SQLite:**
     - hold the row of an existing login with `SELECT … FOR UPDATE` on a second connection, with
       a short `innodb_lock_wait_timeout`;
     - sign in for that login and for a missing one;
     - both answer without waiting for the lock and without a lock-wait timeout.
     - Declare it unsupported on SQLite.
   - **The conditional rehash:** a hash that changed between the read and the rehash is kept, not
     overwritten.
   - **A password change between read and answer:** a session opened with the old
     `auth_version` is ended on its next request.
4. **„Vergessen".**
   - A username and an address each mail the stored address.
   - Known and unknown inputs get the same answer.
   - An invited login gets its invitation again; a suspended one gets nothing.
   - When mail is not ready, nothing is queued and the answer is the same.
   - The fourth request per input in an hour, and the eleventh per IP, are throttled.
5. **R-c.**
   - The action `account_create` does not exist.
   - `mode=direct` never makes an active login.
   - A `structure` rule names the only writers of `password_hash`: `activate`, `password_change`,
     the rehash in `login`, `create_admin_account()` and `demo_fill()`.
   - `reset_link` works only where `reset_link_possible()` is true, never for one's own login, and
     a trainer may use it only on student logins.
   - The outbox shows no `security` body.
6. **Creating with an invitation.**
   - It makes a student, a login, a token and a queued mail, and the flash names the username.
   - Mail not ready, an address in use, or an empty address each refuse with **nothing** created.
   - Without the tick, only the student is made.
   - A `structure` rule: `students.account_id` is written only in `invite_student()` and in
     `demo_fill()`'s example rows.
7. **Activation.**
   - The username can be changed, and the change is tracked.
   - **Its change-log line and its audit rows name the invited login as the actor**, not
     „automatisch".
   - A taken username leaves an audit row with that actor and a flash, activates nothing, and
     brings the password boxes back empty.
   - The reset page's box is read-only.
   - No caller but `activate` passes an explicit actor.
8. **Self-service.**
   - A family saving names, birth date, address, phone and `'edit'` fields makes one version
     row, with the family as the actor.
   - Posted `'view'` and `'internal'` fields, and posted staff-only columns, change nothing.
   - Another student's id gives NotFound.
   - The three contact cases are tracked and scoped.
   - A `structure` rule pins the column list of the family's `UPDATE`: `first_name`, `last_name`,
     `birth_date`, `address`, `phone`.
   - Staff can write every column and field a family can.
9. **Address and invoices.**
   - An invoice issued before a family changes its address still downloads, and mails, with the
     old one.
   - The next invoice has the new one.
   - With the address emptied, an invoice above 400 € is refused and one below is issued.
   - An address of spaces only counts as missing, for the invoice and for the card alike.
10. **Required fields.**
    - **First**, a test that creating a student succeeds while a required field exists, of each
      visibility. Expect it to fail on today's code.
    - A required `'edit'` field refuses the family's save but not staff's.
    - A required `'internal'` field refuses staff's save of an existing student.
    - `custom_field_required_of()` gives all four combinations of visibility and role.
11. **History.**
    - `field:<id>` is shown with its label and a readable value.
    - `address`, `phone`, `owner_name`, `relation_label` and `is_primary` are shown with their
      labels, not their column names.
    - A birth date reads „27.01.2019", not „2019-01-27", and a `DATETIME` is left alone.
    - A contact's line shows no `student_id`, and a charge's line still does.
    - „Von Familien" lists a family's change, not a change made while staff viewed the portal as
      that family, and not staff's own changes.
    - A deleted student's row keeps its custom values.
    - No hash is recorded [R5].
12. **Held forms (§10a).**
    - A refused edit of the second of two contacts is held in the second form only.
    - The first form shows its own stored values.
    - A refused `contact_add` is still held in the add form.
13. **The invited line (§10c).**
    - A live invitation shows its two dates.
    - With the token pruned, the line says it has expired and names no date.
    - No view selects from `auth_tokens`.
14. **`email_change`.** An address in use is accepted when asked, refused when the link is opened,
    and the holder's address is unchanged.
15. **Gone.** None of these is defined anywhere, and none is used by any view:
    - `staff_address_conflict`, `logins_on_address`, `accounts_sharing_address`;
    - `send_sign_in_details`, `confirm_same_family`, `recent_password_reset_notice`;
    - `own_address_missing_sql`, `students_needing_own_address`, `own_address_taken_by`;
    - `same_family`.

    Migration 024 does not exist.
16. **Rewritten for the new rules:** `tests/suites/usernames.php`, `accounts.php`, `security.php`,
    `structure.php` (the throttled list names `change_own_username`; the orderings for
    `student_invite` and `account_invite` name `refuse_address_in_use`), `pages.php`, `forms.php`
    and `transactions.php`.
17. **`TESTING.md`:** the walks for all of the above on a real iPhone. They include the Keychain
    offering the username, or the address, in the one box.

### `mobile-tester`

Measure at 320 and 390, as both roles, in light and dark:

- the sign-in page, including the three-sentence refusal;
- „vergessen";
- activation, invite branch, with a 40-character editable username;
- the create form with its tick;
- every state of the access card, including the address clash, the invited dates and
  `reset_link`;
- the family's Profil tab, with the card, the address and phone, and a required field;
- Kontakte, including a refused edit of one of two contacts;
- Konten;
- Änderungen with its two tabs.

### `code-reviewer`

- The dead code named above is gone, `students_notice()`'s `$also` included.
- There is one mail builder for a reset.
- No call to `staff_address_conflict()` remains.
- `tracked()` wraps every family write.
- Each rule in §11 is spelled only in its function.

### `security-reviewer`

Re-reviews after implementation, specifically:

- §3's exact match and throttle identities;
- §3's lock-free sign-in and conditional rehash [F2];
- §4's handling of invited logins;
- that no path is left where staff set a password;
- who may use `reset_link`;
- `student()`'s scoping on every family write, and the column list of the family's `UPDATE`;
- a family-written address reaching invoices: new ones only, and never an issued one;
- custom-field values in the change log, which only administrators read;
- `email_change`'s check, now made at confirmation;
- the "taken" answer given only to a proven holder, five a day [N3];
- the explicit actor passed by `activate` only, from the token, never from input;
- `invitation_dates()` selecting no `token_hash`;
- held input never crossing from one record's form to another's.

### `docs-writer`

- **`CHANGELOG`:**
  - sign in with the username or the address;
  - invitations only, and direct creation removed;
  - one-step invitation;
  - families complete their details, the postal address and phone included;
  - staff reset link;
  - „Von Familien" in Änderungen.
- **`UPDATING`:** nothing to do. Everybody who signed in with an address still does, and now has a
  username as well.
- **`TESTING.md`,** together with `qa-tester`.
- **`VALIDATION.md`:**
  - drop the ADR 0007 weakness once 0020 is built and its tests pass;
  - keep S3 (softened) and R6;
  - add the forgot bound of 6 mails an hour per login, and the insert-timing note in §4;
  - add the bounded "taken" answer [N3], as accepted;
  - name the engine the tests actually ran on.
- **The privacy drafts, in German and English:**
  - the change-log sentence for the owner to release;
  - check that the list of what is processed names the postal address and the phone;
  - drop any shared-mailbox sentence that 0019 had begun.
- **`README` and `INSTALL`:** remove every mention of creating a login directly with a password.

### `devops-engineer`

Nothing. There is no new file to deliver and no command to run.

### Must not

- Let two logins share an address, drop the `email` index, or write migration 024 in any form.
- Refuse an address in use anywhere but `refuse_address_in_use()`, or show a person the 23000.
- Tell anybody who is not staff whether an address or a username has a login. That covers the
  sign-in, „vergessen" and `email_change` pages, and every other page. **The one exception** is
  the "taken" answer when a proven holder chooses a username, on Mein Konto or the activation
  page, five a day per login and audited (§2).
- Word the sign-in refusal differently for different failures.
- Lock the account row on sign-in, or overwrite a hash that changed since it was read.
- Look up a sign-in or „vergessen" input that failed its format.
- Use a row that does not match the typed value exactly.
- Resolve an input to a row before it is counted.
- Interpolate the column name in `account_for_sign_in()`.
- Hash inside a sign-in, except R9's one-off repair.
- Make a login other than through `account_invite`, `invite_student()`, `create_admin_account()` or
  `demo_fill()`.
- Let staff type, set, see or send a password, or see a link that sets one.
- Read `auth_tokens.token_hash` anywhere but `app/auth.php`, or query `auth_tokens` from a view.
- Add a public sign-up, a request-access form, a shared invitation link, or any unauthenticated
  `INSERT`.
- Let staff write another person's username.
- Set `students.account_id` in `app/` anywhere but `invite_student()`, or `demo_fill()` for its
  example rows.
- Pass an explicit actor anywhere but `activate`, or take one from posted input.
- Spell a rule in §11 anywhere but its function.
- Let a family write any of these: status, membership dates, level, age group, courses, tariff,
  price, billing, internal notes, the student's email, or a custom field not at `'edit'`.
- Make any field a family can write read-only for staff. Typing the value back is the undo.
- Authorise a family's write by anything but `student()`'s scoping, or keep a student id in the
  session.
- Hand one record's refused input to another record's form.
- Write a student, contact or custom-field change outside `tracked()`.
- Write `invoices.snapshot_json` after issue, or build an issued invoice from the live student row.
- Write a `record_versions` key, a `field:` one included, back into a table.
- Record `password_hash`, `auth_version` or `last_seen_at` in the change log.
- Send a mail from the runner.
- Change the SQL of 022 or 023, or change either file at all once it has shipped.

## In plain words, for the owner

- **One box to sign in.** Everybody types their username, such as `lena.mueller`, or their own
  email address, whichever they remember, and then their password.
- **Everybody has their own email address.** Two accounts can never share one. A child with no
  email address yet needs one before they can be invited. A parent's address goes on the child's
  emergency contacts instead.
- **Nobody sets anybody else's password.**
  - Your mother adds a student with first name, last name and the student's own email address,
    and ticks „Gleich einladen".
  - The student gets an email, chooses their own password, and can change the suggested username
    on the same page.
  - They then fill in their birth date, postal address, phone number, emergency contacts and any
    extra field she has marked „Ansehen und bearbeiten".
  - Trainers and administrators are invited the same way.
- **When somebody is stuck:**
  - they use „Passwort vergessen" with their username or address;
  - somebody who lost their invitation gets it again from the same page;
  - or your mother presses a button that emails them a link. She never sees a password or the
    link.
- **No sign-up page.** Only people she invites get in.
- **She sees every change a family makes** in the change log: who changed it, when, and what it was
  before. A „Von Familien" tab shows only theirs. There is no undo button. To put something back,
  she types the old value in on the student's page. She can change everything a family can.
- **Invoices already sent never change.** A family that moves changes the address on the next
  invoice, not on any earlier one.
- **Families still cannot change** their status, level, age group, courses, prices, payments or
  internal notes. They only ever see their own child.
- **Nothing changes for anybody who already signs in with an address.** They carry on, and have a
  username as well.
- **No new database change.** The two you approved stay; the third, for shared addresses, is not
  written.
- **One thing for you, when it suits you.** The privacy notice should say that changes to a
  student's details, contacts and extra fields are kept in a change log for 24 months. You can set
  a shorter time. A draft will come to you to release.

## Open

1. **Project manager.** 022's header comment says brothers and sisters may share an address, and
   names 024.
   - The comment is part of the checksummed file, so once shipped it can never be corrected.
   - Recommended: correct the comment, and not one byte of the SQL, before the first commit.
   - This reads "stay as written" as meaning the statement.
2. **Owner, not blocking during the beta.** Release the change-log sentence in the privacy notice
   once `docs-writer` has drafted it.
