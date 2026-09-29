---
status: accepted
date: 2026-09-29
---

# 0019. Sign in with a username, and an address may be shared

## Context

Today an email address is the sign-in. `accounts.email` is `NOT NULL UNIQUE` (migration 001,
line 10: an inline constraint, so the index MariaDB and MySQL create is named `email`).
ADR 0010 built on that: one login is one student, and **"an address already in use is
refused"**, so a brother or sister needs an address of their own.

The owner has changed the second half. Her words: "every child has their own account, 1
person = 1 account, families need more accounts. Add login with username; each person is
automatically given a username from first and last name + a number, which they can change
themselves. Change logging in from email-based to username-based; email is used if you forgot
your username or password." Clarified with her:

- **Several accounts may share one address.** Siblings use a parent's address. This reverses
  ADR 0010's refusal of an address already in use.
- **Sign-in is by username only.** An address no longer identifies one person.
- **„Benutzername oder Passwort vergessen"** sends **one** email to the address, listing
  every username on it, each with its own reset link. The page never says whether the
  address exists.
- **Format:** `lena.mueller`. First name, a dot, last name. Lower case. `ä→ae`, `ö→oe`,
  `ü→ue`, `ß→ss`, other accents stripped, spaces and hyphens handled sensibly. A number is
  appended **only** when the name is taken: `lena.mueller2`, `lena.mueller3`.
- **Everyone can change their own username later.**

ADR 0010's first half stands: one login is one student, enforced by the unique index
`student_one_account` on `students.account_id`.

What the code does now, and what this touches:

- **Sign-in and reset.** `login` and `forgot` (`app/actions.php`) look accounts up
  `WHERE email=?`. The throttle resolves the typed address to a row first
  (`attempted_identity()`), because `utf8mb4_unicode_ci` folds spellings that PHP does not
  fold. ADR 0007 records the one-bit oracle this leaves and puts the choice to the owner.
- **Five refusals of a shared address.** `own_address_needed()` in `student_invite`,
  `student_save` (two places) and `change_login_address()`; „Diese Adresse hat schon ein
  Konto" in `account_invite` and `account_create`; the uniqueness check in `email_change`.
  Before each insert, `account_using_email()` (`app/auth.php`) holds the address
  `FOR UPDATE`.
- **Code that exists only because an address could not be shared.** In `app/domain.php`:
  `account_with_address()`, `own_address_missing_sql()`, `students_needing_own_address()`,
  `own_address_taken_by()` and the „Eigene E-Mail-Adresse eintragen" branch of
  `student_next_steps()`. In `app/ui.php`: `own_address_notice()` (dashboard and students
  list) and the address confirmation in `login_delete_details()`.
- **Where names come from.** `accounts.name` is one field. A student's login is created by
  `student_invite` from `students.first_name` and `students.last_name`, which are separate.
  Staff logins (`create_admin_account()`, `account_invite`, `account_create`) have only the
  one field.
- **The migration runner** (`app/schema.php`) applies SQL files only. After them it always
  requires `database/defaults.php`, whose first part is *"checked on every schema update …
  because a release that introduces one has to bring it to a portal that already exists"*.
  It already repairs data there in PHP (`students.level_id`). The operator has no shell.
- **Stored addresses are ASCII.** Every address reaches the database through `email_value()`
  (`app/core.php`). `FILTER_VALIDATE_EMAIL` without `FILTER_FLAG_EMAIL_UNICODE` refuses
  anything else. This matters for the throttles below.

## Decision

### 1. What a username is

- **Alphabet:** `a–z`, `0–9`, `.` and `-`.
  - It starts with a letter and ends with a letter or digit.
  - No two separators in a row.
  - 3 to 40 characters.
  - As a pattern: `/^[a-z](?:[a-z0-9]|[.-](?=[a-z0-9])){2,39}$/D`.
- **No underscore.** `_` is a `LIKE` wildcard, and §3 builds a `LIKE` pattern from a
  username. Without it, the pattern is literal with no escaping. It is also one character
  fewer to spell out on the phone.
- **Stored lower case, always.** Uniqueness ignores case because nothing else is ever
  written. The unique index compares under `utf8mb4_unicode_ci` on MariaDB and MySQL, so it
  would refuse `Lena.Mueller` beside `lena.mueller` anyway. That is a second guard, not the
  rule.
- **No reserved names.** A username is shown only to its holder and to staff (§8). No other
  family ever sees one, so there is nobody for `trainerin` or `admin` to deceive. A list
  would be one more rule to keep. It would also turn the first administrator's own name,
  typed as "Admin", into `admin2`.
- **A username is not a secret.** `first.last` is guessable by design. The password and the
  throttles protect the account, as they always have.

### 2. The rules, in one place each

**In `app/core.php`, next to `email_normalised()` and `email_value()`.** These are pure
functions, loaded first, usable by everything, including the runner.

- **`username_normalised(string $typed): string`**
  - Trims, then `mb_strtolower`, then transliterates with one explicit table.
  - The table maps `ä ae`, `ö oe`, `ü ue`, `ß ss` (including capital `ẞ`), and the Latin-1
    and Latin Extended-A letters to their base letter: `é→e`, `ł→l`, `ø→o`, `č→c`, `ñ→n`,
    `å→a` and so on. It also maps `æ→ae`, `œ→oe`, `þ→th`, `ð→d`, `đ→d` and `ı→i`.
  - It keeps every other character. The result can then fail the format, so it is never
    looked up (§5).
  - The sign-in form and the change form both use it. So `Lena.Müller`, typed on an iPhone
    that capitalised the first letter, signs in as `lena.mueller`.
- **`username_value(string $typed): string`**
  - Runs `username_normalised()`, then the pattern.
  - On failure it throws `UserError` with the rule in words: „Erlaubt sind Kleinbuchstaben
    a–z, Ziffern, Punkt und Bindestrich, 3 bis 40 Zeichen, am Anfang ein Buchstabe."
  - It is the only way a typed username reaches a write.
- **`username_from_name(string $first, string $last): string`** builds the base, with no
  number.
  1. Transliterate each part as above.
  2. Inside a part, a run of spaces, hyphens, dots or apostrophes becomes one `-`, or
     nothing for an apostrophe. Anything else outside the alphabet is dropped.
  3. Join the two parts with `.`, and trim separators from both ends.
  4. Cut the result to 36 characters at a boundary, leaving room for a number up to 9999.
  5. With an empty last name, the result is the first part alone.
  6. If the result is shorter than 3, or does not start with a letter, it is `konto`. That
     covers a name written only in Cyrillic, Greek or CJK script.

  Examples:

  | First name | Last name | Username |
  | --- | --- | --- |
  | `Anna Lena` | `von der Heide` | `anna-lena.von-der-heide` |
  | `Hans-Peter` | `O'Neill` | `hans-peter.oneill` |
  | `Jürgen` | `Groß` | `juergen.gross` |
  | `Łukasz` | `Wałęsa` | `lukasz.walesa` |
  | `王` | `芳` | `konto` |
- **`username_from_full_name(string $name): string`** is for staff and for a login with no
  student. The first whitespace-separated word is the first name and the last word is the
  last name. Middle words are dropped. One word means no last name.
- **`username_first_free(string $base, array $taken): string`** returns `$base` if it is
  not in `$taken`. Otherwise it returns `$base.'2'`, `$base.'3'` and so on, lowest free
  first. So the number appears only when taken, and a number freed by a deleted account is
  used again.

**No `ext-intl` and no `iconv('…//TRANSLIT')`.** The first is not in `composer.json` and a
shared host may lack it (ADR 0007). The second depends on the locale and gives different
answers on glibc and musl. One table in the file gives the same username on every host, and
the default suite can test it.

**In `app/auth.php`, which replaces `account_using_email()`.** This is the database half.

- **`username_for_new_account(string $first, string $last): string`**
  - It refuses outside a transaction, for the reason `account_using_email()` gave.
  - It reads `SELECT username FROM accounts WHERE username=? OR username LIKE ? FOR UPDATE`
    with `$base` and `$base.'%'`, and keeps only `$base` and `$base` followed by digits.
  - It returns `username_first_free()`.
  - The locking read takes next-key locks on that range of `account_username`. A second
    "Lena Müller" invited in the same second waits, then sees the first. It does not meet
    the index.
- **`give_every_account_a_username(): int`** is the backfill in §4.
- **`send_sign_in_details(string $email): void`** is the reset mail in §6.

**No new file.** Nothing here needs anything later than `auth.php` in the load order. The
runner's call (§4) happens at request time, after everything is loaded, like the existing
`students.level_id` repair.

### 3. Schema: three migrations, each with at most one statement that cannot run twice

A run that stops partway restarts from statement 1 on the next page view (ADR 0015's rule).
MySQL 8.0 has no `IF [NOT] EXISTS` for indexes, so each non-repeatable statement gets a file
of its own and comes last in it.

1. **`022_a_username_for_every_account.sql`**

   ```sql
   ALTER TABLE accounts ADD COLUMN username VARCHAR(40) NOT NULL DEFAULT '' AFTER email;
   ```

   It has a `DEFAULT`, as `CLAUDE.md` requires. `''` means "not given one yet". It can never
   sign in, because it fails the pattern, and §4 replaces it.
2. **`023_usernames_are_unique.sql`**

   ```sql
   UPDATE accounts SET username = CONCAT('#', id) WHERE username = '';
   CREATE UNIQUE INDEX account_username ON accounts (username);
   ```

   - The placeholder `#<id>` is unique because ids are.
   - `#` is outside the alphabet, so a placeholder can never collide with a real username,
     never pass the sign-in lookup, and is easy for §4 to find.
   - The `UPDATE` is idempotent, and the index is last.
   - The index name is unique across the schema, which SQLite requires.
3. **`024_an_address_may_be_shared.sql`**

   ```sql
   ALTER TABLE accounts DROP INDEX email;
   ```

   - It runs last, so a half-applied update never leaves accounts without a unique
     identifier.
   - **No non-unique index on `email` replaces it.** The table holds tens of rows, and the
     only lookup by address left is „vergessen", which is rare and throttled.
   - No foreign key uses the index, so nothing else has to be rebuilt. That differs from the
     note in ADR 0010 about `student_one_account`.

- **Nothing is deleted.** `accounts` keeps its row count, so `schema_guarded_tables()` is not
  widened.
- **Each file's header points here.** 001 is not edited (ADR 0004).
- **The SQLite translation must really drop the uniqueness.** It must not mark it
  unsupported. In SQLite the inline `UNIQUE` from 001 is an autoindex that `DROP INDEX`
  cannot remove, so `tests/harness.php` or `tests/sqlite-driver.php` has to rebuild
  `accounts` without it when it meets this exact statement. Every sibling test depends on
  two accounts sharing an address, so a translation that skipped it would leave the default
  suite unable to test this ADR at all. How the rebuild is done is `database-engineer`'s
  call. It is proved by a test that inserts two accounts with one address after 024.

### 4. Existing accounts get their username from the runner, in PHP

The first part of `database/defaults.php`, which runs after every `schema_apply()` for the
installer, the console and the first request after an upload alike, calls
`give_every_account_a_username()`:

1. It runs inside `transactional()`.
2. It selects accounts whose `username` is `''` or starts with `#`, `LEFT JOIN students s ON
   s.account_id = a.id` (at most one row each, because of `student_one_account`), in id
   order.
3. The base is `username_from_name(s.first_name, s.last_name)` for a login with a student,
   and `username_from_full_name(a.name)` otherwise.
4. It reads the set of real usernames once, and picks each name with
   `username_first_free()`, adding it to the set. So the oldest account gets the plain name.
5. It writes `UPDATE accounts SET username=? WHERE id=?` and returns the count.

- **Not `tracked()`.** No person made the change, and there is no earlier value worth a line
  in the change log.
- **Idempotent.** Once every account has a username, the select is one indexed query that
  returns nothing.
- **On failure the stamp is not written.** The next request runs `schema_apply()` again,
  which finds the migrations done and retries the backfill. Meanwhile the portal shows the
  blocked page rather than a sign-in nobody can use.

**Nobody is mailed their username by the update** (see Rejected). Somebody who types their
address on the sign-in page is told the sign-in has changed and pointed to „vergessen"
(§5).

### 5. Signing in

- **The form.** `views/login.php` asks for „Benutzername" (`name="username"`,
  `autocomplete="username"`, `autocapitalize="none"`, `autocorrect="off"`,
  `spellcheck="false"`). It is written out like the password field, because `input()` has no
  parameter for these. A short hint gives the format; `ui-ux-designer` words it.
- **`attempted_username(): string`** is `username_normalised(post('username'))`: one
  derivation for the lookup and for the throttle.
- **The `login` case.**
  1. If the typed value contains `@`, it refuses with a sentence before any lookup: the
     sign-in now uses the username, with a link to „Benutzername oder Passwort vergessen".
     The answer depends only on the shape of the input, so it reveals nothing about any
     account.
  2. If `attempted_username()` fails the pattern, it runs no lookup, runs `password_verify()`
     against the fixed dummy hash, and gives the usual „Anmeldung nicht möglich".
  3. Otherwise it runs `SELECT * FROM accounts WHERE username=? FOR UPDATE`, and the rest is
     unchanged.

  **No code path signs in by address.**
- **Throttle.** `throttle('login', username_identity(attempted_username()), 10)`, where
  `username_identity($u)` is `'username:'.$u` and is spelled once, like `account_identity()`.
  - Counting and clearing agree: `forget_attempts_after_success()` clears
    `username_identity($who['username'])`.
  - **No row is resolved first, and that is deliberate.** Every stored username is already in
    normal form and ASCII, and a lookup only ever runs with a string in that form. The
    collation therefore compares the lookup exactly as bytes: there is no second spelling
    that reaches the same row under a different bucket.
  - So a username that exists and one that does not are counted identically, and **the
    sign-in form is no longer an oracle.**
  - A comment at the function says this, so nobody later "restores" the lookup.

### 6. „Benutzername oder Passwort vergessen"

- **The `forgot` case.**
  - It takes an address, as now.
  - The throttle is `throttle('forgot', 'address:'.attempted_email(), 10)` for everybody,
    whether or not any account has that address.
  - The lookup runs only if `attempted_email()` passes the same check as `email_value()`. By
    §Context every stored address does, so the collation again compares exactly as bytes.
  - A typed spelling with an accent or a ligature is never looked up and sends nothing. It
    therefore cannot mint fresh buckets that mail the family, which is the abuse `bcfa796`
    closed.
  - Existing and unknown addresses behave identically. **This closes the channel ADR 0007
    describes, without the identity table or `WEIGHT_STRING`.** 0007 is superseded by this
    record.
- **The answer is always the same.** „Wenn zu dieser Adresse ein aktives Konto gehört, ist
  eine E-Mail an sie unterwegs." `ui-ux-designer` words the final text. It never names the
  address.
- **`send_sign_in_details(string $email)`** in `app/auth.php`:
  1. It selects every account with that address that is `state='active'` and has
     `verified_at` set, in id order. If there are none, it sends nothing. Invited accounts
     are left out, as today: their way in is the invitation, which already names the
     username (§7).
  2. For each account it calls `make_token($id,'reset')`. That replaces that account's
     earlier reset link and no other account's.
  3. It builds **one** mail with one block per account: the name (`greeting_name()`), the
     username, and that account's link. It says the links are valid for one hour.
  4. It is written in `locale()`, the language of the page the request came from, and queued
     once with `queue_mail(<lowest id>, $email, …, 'security')`.
     - Attributing it to the lowest id means deleting that account also removes the queued
       mail, which is acceptable.
     - What actually protects every other account is `make_token()` and
       `change_account_email()` deleting tokens. A link in a mail that should no longer work
       does not work.
  5. If `account_mail_ready()` is false it sends nothing, and the answer is unchanged, as
     today.
- **The `activate` case's `reset` branch is unchanged.** A token is still one account's.

### 7. Telling a person their username

- **The invitation mail** (`send_account_token(…,'invite')`) gains a line: „Dein
  Benutzername: lena.mueller – damit meldest du dich künftig an. Ändern kannst du ihn unter
  „Mein Konto"."
- **The activation page** shows the username for `invite` and `reset` (`token_record()`
  also selects `a.username`). The flash after activating repeats it.
- **„Mein Konto"** (`views/profile.php`) gets a sign-in section at the top: the username,
  and a `<details>` „Benutzernamen ändern" with a new username and the current password.
- **A login created directly with a password** (`account_create`, `student_invite`
  `mode=direct`): the flash names the username, so the administrator can tell the person
  standing next to her.
- **Setup:**
  - `create_admin_account()` generates the username with `username_from_full_name()`.
  - `public/setup.php`'s finished page shows it large and in monospace, as it shows the demo
    password, and says to sign in with the username and password.
  - `bin/console.php` prints it.
  - The setup form gets **no** username field: the owner said "automatically given".

### 8. Who sees a username, and who changes it

- **Shown** to the holder (activation, „Mein Konto", their mails) and to staff: the student
  page's access card, the Konten page, and the change log. **Never to another family**:
  messages, news and threads show names, as now.
- **Changed only by the holder**, with a new case `username_change` in
  `app/actions_settings.php`, directly after `email_change`:
  1. `require_user()`. It refuses while `impersonator()` is set.
  2. It checks the current password, like `email_change`.
  3. `username_value(post('username'))`.
  4. An unchanged value gets a flash and no write.
  5. `SELECT id FROM accounts WHERE username=? FOR UPDATE`. Another holder is refused with
     „Dieser Benutzername ist schon vergeben."
  6. `tracked('accounts', $id, $name, fn() => run('UPDATE accounts SET username=? WHERE id=?', …))`
     writes the change. Only `username` differs, so the change log line is
     `{"username": old} → {"username": new}` and never touches `password_hash`.
     `history_field_label()` gains `'username' => Benutzername`.
  7. `audit('account.username_changed', 'account', $id)`, and a flash naming the new
     username.

  Further details:
  - **`auth_version` is not raised.** A username is not a secret, and other sessions stay
    valid.
  - **Throttled** by adding `username_change` to the `account-security` list in
    `handle_post()`. The "already taken" answer tells a signed-in person that a username
    exists. That is one bit, needs a login, is attributable, and is capped at 10 per quarter
    hour. The person needs the answer to choose a free name.
  - **Changeable, but not undoable.** `history.php` has no undo (ADR 0010). The person
    changes it back the same way.
- **Staff do not change anybody else's username.** A trainer who renamed a family's login
  would lock them out without their knowing. A typo in a name before the invitation is
  accepted is fixed by withdrawing the invitation and inviting again from the corrected
  record: the access card already has both buttons.
- **A username does not follow name changes.** A login that renamed itself would lock its
  holder out.

### 9. The address, now that it is not the sign-in

`accounts.email` is where invitations, sign-in details, reset links, invoices, reminders and
notifications go. It is the account's **recovery channel**, and ADR 0010's "One address"
rule stands: `students.email` equals it once a login exists.

- **`change_login_address()` is renamed `change_account_email()`.** It still:
  - updates both copies;
  - raises `auth_version`;
  - deletes the account's `auth_tokens`;
  - calls `cancel_account_mail()`.

  It loses the holder check: another account holding the address is now allowed.
  `refuse_unless_student_login()` stays, with its wording changed from "die Anmeldung eines
  Mitarbeiterkontos" to "die Adresse eines Mitarbeiterkontos".
- **`email_change`** keeps the password check and the confirmation from the new mailbox. It
  refuses only an address equal to the current one. **Staff still cannot re-address an
  active or suspended login.** With the address as the recovery channel, a trainer who could
  move it could then ask „vergessen" for a link to her own mailbox. The confirmation link
  stays what proves the new mailbox belongs to the holder.
- **`own_address_needed()` and `account_using_email()` are deleted, and every refusal of a
  shared address goes:**
  - `student_invite`;
  - `student_save`, both branches;
  - `account_invite` and `account_create`;
  - `email_change`.

  In `app/domain.php`, `account_with_address()`, `own_address_missing_sql()`,
  `students_needing_own_address()` and `own_address_taken_by()` are deleted. So is the
  „Eigene E-Mail-Adresse eintragen" branch of `student_next_steps()`: any student with an
  address and no login gets „Zugang einladen". In `app/ui.php`, `own_address_notice()` goes
  with its calls in `views/dashboard.php` and `views/students.php`.
- **Deleting a login** (`account_state` `mode=delete`, `login_delete_details()`) is confirmed
  by typing the **username**. An address shared by three logins confirms nothing about which
  of them is being deleted.

### 10. The student page's access card

The rule is unchanged: one login per student, given only by `student_invite`. What changes:

- **No login:** the invitation is offered whenever the record has an address. The „Diese
  Adresse nutzt schon …" notice is gone. The address hint says that brothers and sisters may
  share an address, and that each still gets a login and username of their own.
- **Invited:** „Eingeladen an ‹Adresse› · Benutzername ‹username›, noch nicht angenommen."
- **Active and suspended:** the username comes first, then the address, then last seen.
- The delete confirmation asks for the username (§9).

`ui-ux-designer` specifies the wording and layout. A list of the other logins sharing the
address is not part of this decision.

### 11. Demo data

- `demo_fill()` calls `username_for_new_account()` for its three logins: `trainerin.beispiel`,
  `lena.hofer` and `jonas.berger` on an empty portal.
- It returns the usernames it made. A forced fill beside real data could produce
  `lena.hofer2`, so no page hard-codes them.
- `public/setup.php`'s demo list and `demo_password_notice()` in `app/ui.php` show usernames,
  read from `accounts WHERE is_demo=1`, instead of the three fixed addresses.
- The addresses stay.

## Rejected

**Signing in by username *or* address.** The owner chose username only, and an address that
several accounts share cannot say which one is signing in. Accepting an address "when it
happens to be unique" would make an account's sign-in stop working the day a sibling is
invited. It would also bring the collation oracle back.

**Keeping `accounts.email` unique.** The owner reversed it: siblings use a parent's address.

**Backfilling usernames in SQL.**
- SQL cannot strip arbitrary accents without a second transliteration table.
- Numbering duplicates needs window functions that the SQLite translation would have to
  imitate.
- Above all, it would be a second copy of the rule in `username_from_name()`, in another
  language. The two would drift, and the copy in the migration could never be fixed.

**Giving usernames lazily at the next sign-in.** Sign-in is by username, so an account
without one cannot sign in to be given one. An invited account needs its username before
anyone can tell it to them.

**A PHP migration kind (`database/migrations/022_….php`).**
- It would be a second mechanism beside `migration_files()` and the checksum ledger, for one
  backfill.
- `database/defaults.php` is already the runner's PHP step, and it already repairs rows in
  exactly this way.
- `CLAUDE.md`: "adding a second path for any of them is how they start disagreeing".

**A nullable `username` with `DEFAULT NULL`.** Several `NULL`s would sit happily under the
unique index with no placeholder, but that is the NULL-to-guess-about that `CLAUDE.md`
forbids. `''` plus the `#<id>` placeholder gives every state a visible, non-signable value.

**One migration file for all of it.** Three statements that cannot run twice would be in one
file, and an interruption after the first would block every later run. The operator has no
shell to finish it by hand.

**A non-unique index on `accounts.email` in place of the unique one.** It is a fourth
statement and a fourth file, for a lookup that runs a few times a month against tens of
rows.

**A binary collation (`utf8mb4_bin` or `ascii_bin`) on `username`.** It would make the
engine compare the way SQLite does. It is not needed: the values are ASCII lower case by
construction, so every collation agrees on them. It would also cost the SQLite translation a
new clause to strip, and lose the case-insensitive second guard on MariaDB.

**`ext-intl` / `iconv` transliteration.** It depends on the host (§2).

**Reserved usernames.** Nobody outside staff sees a username (§1).

**An underscore in the alphabet.** It is a `LIKE` wildcard (§1).

**Numbering every username (`lena.mueller1`), or numbering from 1.** The owner said a number
is added only when the name is taken.

**Staff changing a family's username.** It would lock them out silently (§8).

**Letting the username follow edits to the name.** The same reason.

**Holding a changed username back from reuse for a while.** Usernames are never shown to
other families, and this is one club. It would be a table or a column for a problem nobody
here has.

**Changing a username without the password.** Anyone holding an unlocked phone could rename
a child's login. `email_change` asks for the password for the same reason.

**One reset mail per account on the address.** The owner asked for one mail listing them
all. That is also easier for a parent with three children.

**Listing invited accounts in the reset mail.** A reset link for an account that has never
been activated would skip the privacy acknowledgement that activation records.

**Mailing every existing account its new username when the update runs.**
- The runner is the wrong place to send mail, and SMTP may not be tested yet.
- The portal is in beta without real families (the owner).
- ADR 0010 already set the precedent that an update does not write to families.
- The sign-in page's `@` hint and „vergessen" cover a person who does not know their
  username.

**Keeping the throttle resolution (`attempted_identity()` looking the row up).** It was
there because the collation folded spellings PHP could not. With ASCII normal forms on both
sides there is nothing left to fold. The lookup was also the source of ADR 0007's oracle.

**ADR 0007's identity table or `WEIGHT_STRING`.** Both close the oracle at the cost of a
schema change or an unstable engine function. The design above closes it with neither.

## Consequences

- **Owner:**
  - Migrations 022–024 change the schema. Under `CLAUDE.md` "stop and ask",
    `database-engineer` does not write them until she has said yes to the paragraph below.
  - **A shared address means that whoever reads that mailbox can set a new password for
    every account on it**, including a teenager's, and with it read their private messages
    with the trainer. That follows from her decision and is recorded so it is known. A
    family that wants a child's messages private gives the child their own address.
    `docs-writer` drafts a sentence for the privacy notice and she releases it.
- **Supersedes:**
  - ADR 0007 in full.
  - ADR 0010's refusal of an address in use, its `account_with_address()`,
    `students_needing_own_address()` and next-step rule, and the matching test items. Both
    files carry a note.
  - Everything else in 0010 stands.
- **Action count:** +1 (`username_change`).
- **`database-engineer`:**
  - writes 022, 023 and 024 as in §3, with headers that point here;
  - teaches the SQLite translation to drop the inline unique index;
  - adds `give_every_account_a_username()`'s call to `database/defaults.php`;
  - extends `tests/migration-data.php` with a pause after 021, holding:
    - two accounts whose names give the same username;
    - a staff account with a one-word name;
    - a name with umlauts and a hyphen;
    - an orphan student login;
  - on **MariaDB 10.11.14**:
    - confirms with `SHOW INDEX FROM accounts` that the index 024 drops is named `email`;
    - checks that the collation treats `lena.mueller`, `lena-mueller` and `lenamueller` as
      three different values;
    - checks that no two distinct characters of the `email_value()` alphabet compare equal
      under `utf8mb4_unicode_ci`. §5 and §6 rest on that. If it does not hold, the `forgot`
      bucket keeps resolving the row, and this record is amended;
  - runs `tests/mariadb-local.sh`. **MySQL 8.0 stays unverified**, and the report says so.
- **`backend-dev`:**
  - `app/core.php`: the four functions in §2;
  - `app/auth.php`:
    - `username_for_new_account()`, `give_every_account_a_username()` and
      `send_sign_in_details()`;
    - `account_using_email()` deleted;
    - `create_admin_account()` and `token_record()` changed;
    - the invite mail's line;
  - `app/actions.php`:
    - `login`, `forgot`, `activate` and `handle_post()`;
    - `attempted_username()` and `username_identity()`, with `attempted_identity()`
      rewritten;
    - `forget_attempts_after_success()`;
    - `change_account_email()`;
    - `account_invite`, `account_create`, `student_invite` and `student_save`;
    - `account_state`'s delete confirmation;
    - `own_address_needed()` deleted;
  - `app/actions_settings.php`: `username_change` and `email_change`;
  - `app/domain.php`: the deletions in §9;
  - `app/history.php`: the label;
  - `app/demo.php`: the usernames;
  - `bin/console.php`: prints the username;
  - `public/setup.php`: the finished page and the demo list.
- **`frontend-dev`**, from `ui-ux-designer`'s specification:
  - `views/login.php`, `forgot.php`, `activate.php` (including „Melde dich mit deinem
    Benutzernamen an" for the `email` purpose), `profile.php`, `student.php`, `accounts.php`,
    `dashboard.php` and `students.php`;
  - `app/ui.php` (`own_address_notice()` deleted, `login_delete_details()`,
    `demo_password_notice()`).
- **`qa-tester`**, breaking each rule once on purpose:
  - **Generation:**
    - the table in §2, plus `ÄNNE` → `aenne` and `ẞ` → `ss`;
    - a second and third Lena Müller get `…2` and `…3`;
    - a freed `…2` is used again;
    - `konto` is the fallback.
  - **The database:**
    - two accounts on one address are allowed after 024, on SQLite too;
    - two with the same username are refused with 23000.
    - `tests/suites/errors.php` used the email index to provoke a PDOException and needs
      another unique key.
  - **Sign-in:**
    - `Lena.Müller` signs in as `lena.mueller`;
    - an address is refused with the hint;
    - ten failures at `Lena.Mueller` lock `lena.mueller`;
    - **an unknown username is locked at the eleventh try exactly like a known one**;
    - success clears the bucket.
  - **„Vergessen":**
    - one mail for a shared address lists both active usernames, each with a link that works
      alone;
    - the invited sibling is not listed;
    - an unknown address and an accented spelling both queue nothing and give the same flash;
    - the throttle treats known and unknown alike.
  - **`username_change`:**
    - needs the password;
    - refuses a taken name in another case;
    - transliterates;
    - refuses bad characters with the sentence;
    - writes one change-log line holding only `username`;
    - leaves `auth_version` alone;
    - is throttled;
    - is refused while impersonating.
  - **Backfill:**
    - every account ends with a username matching the pattern;
    - a student login uses the student's names;
    - the older account keeps the plain name;
    - a second run changes nothing;
    - the account count is unchanged.
  - **`structure`:**
    - every `INSERT INTO accounts` names `username` and its block calls
      `username_for_new_account()`, which replaces the `account_using_email` rule, with the
      same five named handlers;
    - `username_for_new_account()` reads `FOR UPDATE` and refuses outside a transaction;
    - `username` is written only by those inserts, `username_change` and
      `give_every_account_a_username()`;
    - the `login` case's SQL reads `WHERE username=?` and never `email`;
    - `views/login.php` has no field named `email`;
    - `['username']` is printed only in `views/profile.php`, `student.php`, `accounts.php`,
      `activate.php` and `app/ui.php`;
    - `own_address_needed`, `account_using_email`, `students_needing_own_address`,
      `own_address_missing_sql`, `own_address_taken_by`, `account_with_address` and
      `own_address_notice` are not defined anywhere;
    - the `students.email` writers list names `change_account_email`;
    - the orderings entries for `student_invite` and `account_invite`, which anchored on the
      removed refusals, are re-anchored or removed with a reason.
  - `tests/suites/security.php` 279–294 are rewritten for §5 and §6.
- **`docs-writer`:** `CHANGELOG`, `UPDATING` (sign-in changes, and where people find their
  username), `TESTING.md` (walk the invitation, sign-in on an iPhone with auto-capitalisation,
  „vergessen" with two children on one address, and changing a username), both privacy
  drafts, and `VALIDATION.md` (drop the ADR 0007 weakness once the MariaDB check above has
  passed, not before).
- **`security-reviewer`** reviews §5 and §6 in particular: they close a known finding by
  reasoning about alphabets rather than by a new mechanism.
- **Must not:**
  - sign anybody in by address;
  - look up an account with a string that has failed its format;
  - let staff write another person's username;
  - send a mail from the runner;
  - edit 001 or any shipped migration;
  - show a username to another family;
  - reintroduce a refusal of a shared address.

## In plain words, for the owner

Everybody will sign in with a username instead of an email address, such as `lena.mueller`.
It is made automatically from the first and last name; if two people have the same name, the
second gets a 2 on the end. Each person can change their own under „Mein Konto". Brothers and
sisters can now all use their parent's email address, and each still has their own login.
Someone who forgets their username or password types the email address and gets one email
listing every username on that address, each with a link to set a new password. Existing
accounts are given their usernames automatically when the update is installed; nothing needs
to be run afterwards.

To do this the database needs three small changes to the accounts table: a new column for
the username, a rule that no two usernames are the same, and removing the rule that no two
accounts share an address. Nothing is deleted, and a copy of the database is taken before
they run, as always. **They cannot be undone once they have run, so we need your yes before
they are written.**

One thing to know: whoever reads a shared mailbox can set a new password for every account
on it, including an older child's. If a teenager's messages with you should stay private
from their parents, that child needs an address of their own.

## Open for the owner

1. Yes to the three database changes (022–024)?
2. Is it right that the update does **not** email existing accounts their new username? The
   sign-in page tells anyone who types an address what to do instead. Recommended while the
   portal is in beta.
