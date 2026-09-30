---
status: accepted
date: 2026-09-29
---

# 0019. Sign in with a username, and an address may be shared

*Amended on 2026-09-30, before any username code was written.* The first design **failed
security review**. The owner decided two findings: staff logins need their own address (M3),
and the lock-out risk is accepted (S3). The rest are adopted as below. Each change is marked
**[M1]** … **[N10]** where it lands.

## Context

Today an email address is the sign-in. `accounts.email` is `NOT NULL UNIQUE` (migration 001,
line 10: an inline constraint, so the index MariaDB and MySQL create is named `email`).
ADR 0010 built on that: one login is one student, and **"an address already in use is
refused"**, so a brother or sister needs an address of their own.

The owner has changed the second half. In her words: "every child has their own account, 1
person = 1 account, families need more accounts. Add login with username; each person is
automatically given a username from first and last name + a number, which they can change
themselves. Change logging in from email-based to username-based; email is used if you forgot
your username or password." Clarified with her:

- **Several student accounts may share one address.** Siblings use a parent's address. This
  reverses ADR 0010's refusal of an address already in use.
- **A staff login needs an address of its own [M3, owner].** A trainer's or administrator's
  address may not be shared with any other login, in either direction.
- **Sign-in is by username only.** An address no longer identifies one person.
- **„Benutzername oder Passwort vergessen"** sends **one** email to the address, listing
  every username on it, each with its own reset link. The page never says whether the
  address exists.
- **Format:** `lena.mueller` — first name, a dot, last name.
  - Lower case.
  - `ä→ae`, `ö→oe`, `ü→ue`, `ß→ss`; other accents are stripped; spaces and hyphens are
    handled sensibly.
  - A number is appended **only** when the name is taken: `lena.mueller2`, `lena.mueller3`.
- **Everyone can change their own username later.**

ADR 0010's first half stands: one login is one student, enforced by the unique index
`student_one_account` on `students.account_id`.

What the code does now, and what this touches:

- **Sign-in and reset.**
  - `login` and `forgot` (`app/actions.php`) look accounts up `WHERE email=?`.
  - The throttle resolves the typed address to a row first (`attempted_identity()`), because
    `utf8mb4_unicode_ci` folds spellings that PHP does not fold. ADR 0007 records the
    one-bit oracle this leaves.
  - `login` verifies a missing row against a **hard-coded `$2y$10$` dummy hash**, while real
    hashes follow `PASSWORD_DEFAULT`, which PHP 8.4 raised to cost 12 [M2].
- **The mail sender.** For a `security` job, `process_mail()` (`app/mail.php`) checks the
  **first** `token=` in the body, using `preg_match`, against the job's account and
  recipient [S1].
- **Five refusals of a shared address.**
  - `own_address_needed()` in `student_invite`, in `student_save` (two places), and in
    `change_login_address()`;
  - „Diese Adresse hat schon ein Konto" in `account_invite` and `account_create`;
  - the uniqueness check in `email_change`.

  Before each insert, `account_using_email()` (`app/auth.php`) holds the address
  `FOR UPDATE`.
- **Code that exists only because an address could not be shared.**
  - In `app/domain.php`: `account_with_address()`, `own_address_missing_sql()`,
    `students_needing_own_address()`, `own_address_taken_by()`, and the „Eigene
    E-Mail-Adresse eintragen" branch of `student_next_steps()`.
  - In `app/ui.php`: `own_address_notice()` and the address confirmation in
    `login_delete_details()`.
- **Where names come from.** `accounts.name` is one field. A student's login is created by
  `student_invite` from `students.first_name` and `students.last_name`. Staff logins have
  only the one field.
- **The migration runner** (`app/schema.php`) applies SQL files only. After them it always
  requires `database/defaults.php`, which already repairs data in PHP (`students.level_id`).
  The operator has no shell.
- **Stored addresses.** Every address reaches the database through `email_value()`
  (`app/core.php`), which is `FILTER_VALIDATE_EMAIL` without the Unicode flag. That filter
  **accepts a quoted local part, which may hold control characters**.
  - `database-engineer` confirmed on **MariaDB 10.11.14** that `utf8mb4_unicode_ci` ignores
    control characters. Two different typed addresses can therefore reach one row [M1].
  - The same check found that the username alphabet held.

## Decision

### 1. What a username is

- **Alphabet:** `a–z`, `0–9`, `.` and `-`.
  - It starts with a letter and ends with a letter or digit.
  - No two separators in a row.
  - 3 to 40 characters.
  - `/^[a-z](?:[a-z0-9]|[.-](?=[a-z0-9])){2,39}$/D`.
- **No underscore.** It is a `LIKE` wildcard, and §3 builds a `LIKE` pattern from a
  username.
- **Stored lower case, always.** The unique index compares under `utf8mb4_unicode_ci`, which
  is a second guard, not the rule.
- **No reserved names.** A username is shown only to its holder and to staff (§8).
- **A username is not a secret.** `first.last` is guessable by design. The password and the
  throttles protect the account.
  - **[S3, owner] The lock-out risk that follows is accepted for now.** Anybody who can guess
    `lena.mueller` can lock that sign-in for the throttle window by typing ten wrong
    passwords.
  - Nothing is disclosed, and the holder can still use „vergessen" (§6), whose throttle is
    separate.
  - `docs-writer` records the risk in `VALIDATION.md`. It is re-decided if it ever happens in
    practice.

### 2. The rules, in one place each

**In `app/core.php`, next to `email_normalised()` and `email_value()`.** These are pure
functions, loaded first.

- **`EMAIL_DOT_ATOM` and `email_is_dot_atom(string $normalised): bool` [M1].** They define,
  **once**, the only address shape the portal writes or looks up:
  - a dot-atom local part of RFC 5322 `atext` (`a–z 0–9 ! # $ % & ' * + / = ? ^ _ { | } ~ -`
    and the backtick), with single dots between atoms;
  - `@`;
  - a domain of dot-separated LDH labels;
  - all ASCII, lower case, at most 254 bytes.

  There are no quotes, no spaces, no control characters and no IP-literal. It is applied to
  `email_normalised()` output.
- **`email_value()`** additionally refuses anything `email_is_dot_atom()` rejects, with the
  same „Ungültige E-Mail-Adresse." **Every new write** is therefore dot-atom.
  - A stored address that predates this and fails it keeps receiving mail.
  - It cannot be used for „vergessen" (§6).
  - Staff re-address it through `student_save`, or the holder through `email_change`.
- **`username_normalised(string $typed): string`**
  - Trims, lower-cases with `mb_strtolower`, then transliterates with one explicit table.
  - The table maps `ä ae`, `ö oe`, `ü ue`, `ß ss` (and `ẞ`), and Latin-1 and Latin
    Extended-A letters to their base letter. It also maps `æ→ae`, `œ→oe`, `þ→th`, `ð→d`,
    `đ→d` and `ı→i`.
  - It keeps every other character, so the result can fail the pattern and is then never
    looked up (§5).
- **`username_value(string $typed): string`** runs `username_normalised()`, then the pattern.
  On failure it throws `UserError` with the rule in words. It is the only way a typed
  username reaches a write.
- **`username_from_name(string $first, string $last): string`** builds the base, with no
  number:
  1. Transliterate each part.
  2. Runs of spaces, hyphens or dots become one `-`, and apostrophes are dropped.
  3. Drop anything else outside the alphabet.
  4. Join the parts with `.` and trim separators from both ends.
  5. Cut at a boundary to 36 characters.
  6. With an empty last name, use the first part alone.
  7. If the result is shorter than 3 or does not start with a letter, use `konto`.

  | First name | Last name | Username |
  | --- | --- | --- |
  | `Anna Lena` | `von der Heide` | `anna-lena.von-der-heide` |
  | `Hans-Peter` | `O'Neill` | `hans-peter.oneill` |
  | `Jürgen` | `Groß` | `juergen.gross` |
  | `Łukasz` | `Wałęsa` | `lukasz.walesa` |
  | `王` | `芳` | `konto` |
- **`username_from_full_name(string $name): string`**: the first word is the first name, the
  last word is the last name, and middle words are dropped.
- **`username_first_free(string $base, array $taken): string`**: `$base`, else `$base.'2'`,
  `$base.'3'`, …, lowest free first.

**No `ext-intl` and no `iconv('…//TRANSLIT')`.** Both depend on the host.

**In `app/auth.php`** (replacing `account_using_email()`). This is the database half.

- **`username_for_new_account(string $first, string $last): string`**
  - It refuses outside a transaction.
  - It reads `SELECT username FROM accounts WHERE username=? OR username LIKE ? FOR UPDATE`.
  - It keeps `$base` and `$base` followed by digits only, and returns
    `username_first_free()`.
- **`staff_address_conflict(string $email, bool $newIsStaff, ?int $exceptId = null): void`
  [M3].**
  - It refuses outside a transaction.
  - It reads `SELECT id, role FROM accounts WHERE email=? AND id<>? FOR UPDATE`.
  - It throws `UserError` if either of these holds:
    - the login being given the address is staff and any other login has the address:
      „Ein Konto für Trainerin oder Administrator braucht eine eigene E-Mail-Adresse.";
    - it is a student login and a staff login has the address: „Diese Adresse gehört zu
      einem Mitarbeiterkonto."

  It is the one place the rule is spelled, and the only remaining refusal of a shared
  address.
- **`logins_on_address(string $email, ?int $exceptId = null): array` [S5].** It returns id,
  username and student name for the other logins on an address, and is used for the
  „gleiche Familie" confirmation (§9).
- **`sign_in_dummy_hash(): string` [M2].** It returns the setting `sign_in_dummy_hash`:
  `kind raw`, `internal`, group `system`, default `''`.
  - The runner step (§4) and `prune_expired()` regenerate it with `password_hash(<32 random
    bytes, hex>, PASSWORD_DEFAULT)` when it is empty or `password_needs_rehash()` says so.
    Its cost therefore follows `PASSWORD_DEFAULT` and every real hash.
  - It is **never regenerated inside a sign-in**, because hashing there would make a failed
    sign-in measurably slower than a real one.
  - If it is missing on a request, it is created once and stored.
- **`accounts_sharing_address(int $accountId): int`** counts the other logins on this
  account's address [S4].
- **`password_resets_for(int $accountId, int $days = 30): array`** returns the
  `account.password_reset` audit entries for this account [S4].
- **`give_every_account_a_username(): int`** is the backfill in §4.
- **`send_sign_in_details(string $email): void`** is the reset mail in §6.

**No new file.** Nothing here needs anything later than `auth.php`.

**In `app/history.php` [S6].** `history_never_recorded(string $entity): array` returns, for
`accounts`, `password_hash`, `auth_version` and `last_seen_at`. `history_record()` drops
those columns from both snapshots before comparing and storing. A change-log line can
therefore never carry a password hash, whatever `tracked()` is wrapped around.

### 3. Schema: three migrations, each with at most one statement that cannot run twice

A run that stops partway restarts from statement 1 on the next page view. MySQL 8.0 has no
`IF [NOT] EXISTS` for indexes, so each non-repeatable statement gets a file of its own and
comes last in it.

1. **`022_a_username_for_every_account.sql`**

   ```sql
   ALTER TABLE accounts ADD COLUMN username VARCHAR(40) NOT NULL DEFAULT '' AFTER email;
   ```
2. **`023_usernames_are_unique.sql`**

   ```sql
   UPDATE accounts SET username = CONCAT('#', id) WHERE username = '';
   CREATE UNIQUE INDEX account_username ON accounts (username);
   ```

   `#<id>` is unique, outside the alphabet, never passes the sign-in lookup, and is easy for
   §4 to find.
3. **`024_an_address_may_be_shared.sql`**

   ```sql
   ALTER TABLE accounts DROP INDEX email;
   ```

   - It runs last.
   - No non-unique index replaces it.
   - No foreign key uses the dropped index.

Further points:

- **Nothing is deleted.** `schema_guarded_tables()` is not widened.
- **Each file's header points here.** 001 is not edited (ADR 0004).
- **The SQLite translation must really drop the uniqueness.**
  - The inline `UNIQUE` from 001 is an autoindex, so `accounts` is rebuilt without it when
    the translation meets this statement.
  - How is `database-engineer`'s call.
  - It is proved by inserting two accounts with one address after 024.
- **No schema for the staff rule [M3].** It is enforced in PHP under `FOR UPDATE`. A
  database constraint cannot express "unique among staff and across the staff/student
  divide".

### 4. Existing accounts get their username from the runner, in PHP

The first part of `database/defaults.php` runs after every `schema_apply()`. It calls
`give_every_account_a_username()`, which:

1. runs inside `transactional()`;
2. selects accounts whose `username` is `''` or starts with `#`, `LEFT JOIN students s ON
   s.account_id = a.id`, in id order;
3. builds the base with `username_from_name(s.first_name, s.last_name)`, or with
   `username_from_full_name(a.name)`;
4. reads the set of real usernames once, and picks each name with `username_first_free()`,
   so the oldest account gets the plain name;
5. runs `UPDATE accounts SET username=? WHERE id=?` and returns the count.

- It is not `tracked()`.
- It is idempotent.
- On failure the stamp is not written, and the next request retries.

The same step ensures `sign_in_dummy_hash` exists and is current [M2].

**Existing staff addresses shared with another login [M3].** The rule governs new writes. An
existing conflict, possible only through data entered by hand, is not repaired by the
runner, which never changes an address. `database-engineer` adds a check to
`tests/migration-data.php` that such a pair survives the update untouched. The Konten page
flags it to the administrator with „Diese Adresse nutzt auch ‹Name›", so she re-addresses
one of them.

**Nobody is mailed their username by the update** (see Rejected).

### 5. Signing in

- **The form.** `views/login.php` asks for „Benutzername" (`name="username"`,
  `autocomplete="username"`, `autocapitalize="none"`, `autocorrect="off"`,
  `spellcheck="false"`).
- **`attempted_username(): string`** is `username_normalised(post('username'))`.
- **The `login` case:**
  1. **A typed `@`.** It refuses before any lookup, with a sentence pointing to „Benutzername
     oder Passwort vergessen".
  2. **A value that fails the pattern.** No lookup. `password_verify()` runs against
     `sign_in_dummy_hash()`, and the answer is the usual „Anmeldung nicht möglich" [M2].
  3. **Otherwise:** `SELECT * FROM accounts WHERE username=? FOR UPDATE`.
     - **No row, or a row that is invited (`verified_at` empty) or suspended:**
       `password_verify()` still runs, against `sign_in_dummy_hash()` for no row and for an
       invited login without a hash, and against the stored hash otherwise. Then the same
       refusal [M2].
     - **Success:** as today, `password_needs_rehash()` rehashes, and the sign-in follows.
  4. **After a successful sign-in [S4].** If `password_resets_for()` has an entry newer than
     the account's `last_seen_at` as it was before this sign-in, the flash says: „Dein
     Passwort wurde am ‹Datum› über „Benutzername oder Passwort vergessen" neu gesetzt. Warst
     du das nicht, wende dich an die Trainerin."

  **No code path signs in by address.**
- **Throttle.** `throttle('login', username_identity(attempted_username()), 10)`, where
  `username_identity($u)` is `'username:'.$u`.
  - `forget_attempts_after_success()` clears the same bucket.
  - **No row is resolved first.** The lookup only ever runs with an ASCII string in normal
    form, which the collation compares as bytes (verified on MariaDB 10.11.14). A username
    that exists and one that does not are therefore counted identically, and **the sign-in
    form is no longer an oracle.** A comment at the function says so.
  - The lock-out a guessable username allows is accepted (§1, S3).

### 6. „Benutzername oder Passwort vergessen"

- **The `forgot` case:**
  1. `$typed = attempted_email()`.
  2. **Throttles [S7].** Both are counted for every request, whether or not any account
     has the address:
     - `throttle('forgot', 'address:'.$typed, 3, 3600)`: 3 per hour per address;
     - `throttle('forgot-ip', $_SERVER['REMOTE_ADDR'] ?? 'local', 10, 3600)`.
  3. **The lookup is gated on `email_is_dot_atom($typed)` [M1].** A typed value that fails it
     is never looked up and sends nothing.
     - Every value that is looked up is plain ASCII atext, with no character
       `utf8mb4_unicode_ci` ignores or folds. The collation therefore compares it as bytes.
     - One mailbox cannot be reached under several spellings, each with a fresh bucket.
  4. If it passes, `send_sign_in_details($typed)` runs.
  5. The answer is always the same: „Wenn zu dieser Adresse ein aktives Konto gehört, ist
     eine E-Mail an sie unterwegs." It never names the address.

  Existing and unknown addresses behave identically. **This closes the channel ADR 0007
  describes.** 0007 is superseded by this record.
- **`send_sign_in_details(string $email)` [S1]:**
  1. It selects every account whose `email` equals the value, with `state='active'` and
     `verified_at` set, **ordered by id**. If there are none, it sends nothing.
  2. For each account it calls `make_token($id,'reset')`.
  3. It builds **one** mail with one block per account, in id order: the name, the username
     and that account's link, valid for one hour.
  4. It queues that mail once to **the stored `a.email` of the lowest-id account**, never
     the typed string: `queue_mail(<lowest id>, <its stored email>, …, 'security')`.
     Because the lookup matched as bytes, the two are equal. The stored value is used so
     that no future change to the gate can send a mail to an address that no row holds.
  5. If `account_mail_ready()` is false, it sends nothing.
- **The sender re-checks every link [S1].** For a `security` job, `process_mail()` extracts
  **every** `token=` in the body with `preg_match_all`. The mail is sent only if there is at
  least one, and **each** of them:
  - is a live token (`token_record()`);
  - belongs to an account whose current `email` equals the job's recipient (or whose
    `target_email` does, for the `email` purpose);
  - belongs to an account that is not suspended.

  Otherwise the job is cancelled.
  - A mail listing three children is not sent if one of them has since been re-addressed,
    suspended or deleted. The parent asks again.
  - The existing single-token rule for the other purposes is the same check with one token.
- **The `activate` case's `reset` branch:**
  - The token is still one account's.
  - It **keeps raising `auth_version`** [S4], which signs out every other session of that
    login.
  - It writes `audit('account.password_reset', 'account', $id)` [S4].
  - The reset page shows the username as a **read-only field**, with `name="username"` and
    `autocomplete="username"`, next to the new-password field. A password manager then
    saves the new password against the right username [N10].

### 7. Telling a person their username

- **The invitation mail** gains a line naming the username.
- **The activation page** shows the username for `invite` and `reset`, as the read-only
  `autocomplete="username"` field of §6 [N10]. The flash after activating repeats it.
- **„Mein Konto"** (`views/profile.php`) gets a sign-in section at the top:
  - the username;
  - a `<details>` „Benutzernamen ändern";
  - **[S4]** „Diese E-Mail-Adresse nutzen auch N weitere Konten." when
    `accounts_sharing_address()` > 0. Only the count is shown, never whose.
  - **[S4]** the password resets of the last 30 days from `password_resets_for()`, with
    date and time.
- **A login created directly with a password:** the flash names the username.
- **Setup:** `create_admin_account()` generates the username, the finished page and
  `bin/console.php` show it, and the setup form gets no username field.

### 8. Who sees a username, and who changes it

- **Shown** to the holder and to staff: the access card, the Konten page and the change log.
  **Never to another family.**
- **Changed only by the holder**, with a new case, `username_change`, in
  `app/actions_settings.php` after `email_change`:
  1. `require_user()`. It refuses while `impersonator()` is set.
  2. It checks the current password, posted as **`current_password`** like `password_change`,
     so a password manager does not offer to save it as a new one [N5].
  3. `username_value(post('username'))`.
  4. An unchanged value gets a flash and no write.
  5. **`lock_row('accounts', $id)` [S6]**, so the snapshot `tracked()` takes cannot race
     another write to the same row.
  6. `SELECT id FROM accounts WHERE username=? FOR UPDATE`. If another holder has it:
     - count it in **its own bucket [S2]**:
       `throttle('username-taken', account_identity($id), 5, 86400)`, 5 per day per
       account;
     - `audit('account.username_taken', 'account', $id)`;
     - refuse with „Dieser Benutzername ist schon vergeben."
  7. `tracked('accounts', $id, $name, fn() => run('UPDATE accounts SET username=? WHERE id=?', …))`.
     With S6 the change-log line can only ever hold `username`.
  8. `audit('account.username_changed', 'account', $id)`, and a flash.
  - `auth_version` is not raised.
  - The case is still also in `handle_post()`'s `account-security` list (10 per quarter
    hour). The taken bucket bounds how many names a login can test to 5 a day, and each
    test is attributable in the audit.
- **Staff do not change anybody else's username.**
- **A username does not follow name changes.**

### 9. The address, now that it is not the sign-in

`accounts.email` is the account's **recovery channel**. ADR 0010's "One address" rule
stands: `students.email` equals it once a login exists.

- **`change_login_address()` is renamed `change_account_email()`.** It keeps:
  - updating both copies;
  - raising `auth_version`;
  - deleting tokens;
  - calling `cancel_account_mail()`.

  It calls `staff_address_conflict()` [M3]. `refuse_unless_student_login()` stays.
- **`email_change`** keeps the password check and the confirmation from the new mailbox. It
  refuses an address equal to the current one, and calls `staff_address_conflict()` **both
  when asked and when the confirmation link is used**, because a staff login may have taken
  the address in between [M3].
  - The refusal tells a signed-in person that an address belongs to staff. That is one bit,
    it is attributable, it is throttled by `account-security`, and staff addresses are no
    secret to the families who write to them.
  - Staff still cannot re-address an active or suspended login.
- **The staff rule [M3, owner]** is enforced by `staff_address_conflict()` in exactly these
  five places, and no other refusal of a shared address remains:
  - `account_invite`;
  - `account_create`;
  - `email_change`;
  - `student_invite` (both modes);
  - the re-addressing branch of `student_save` (through `change_account_email()`).

  **If a role change ever turns a student login into a staff login, it must pass the same
  check.** No such path exists today.
- **„Gleiche Familie" [S5].** When `student_invite` (invite or direct login) or `student_save`'s
  re-addressing would put an address on a login while `logins_on_address()` returns others:
  - The action refuses **unless** `post('same_family')==='1'`, with: „Diese Adresse nutzen
    schon: Lena Hofer (lena.hofer), Jonas Hofer (jonas.hofer). Wer diese Adresse liest, kann
    für jedes dieser Konten ein neues Passwort setzen. Gehört das neue Konto zur selben
    Familie?"
  - `remember_input()` brings the form back with a „gleiche Familie" tick shown.
  - This is a confirmation, not a refusal. Only staff ever see it; the names are ones staff
    already see.
  - `email_change` by the holder shows no names, because a family must not learn other
    families' names. The confirmation mail is what proves the mailbox.
- **Deletion.** `own_address_needed()` and `account_using_email()` are deleted. So are, in
  `app/domain.php`, `account_with_address()`, `own_address_missing_sql()`,
  `students_needing_own_address()` and `own_address_taken_by()`. The „Eigene
  E-Mail-Adresse eintragen" branch of `student_next_steps()` goes, and `own_address_notice()`
  goes with its calls.
- **Deleting a login** is confirmed by typing the **username**.

### 10. The student page's access card

- **No login:** the invitation is offered whenever the record has an address. The hint says
  brothers and sisters may share an address, each with their own login and username.
- **Invited:** „Eingeladen an ‹Adresse› · Benutzername ‹username›, noch nicht angenommen."
- **Active and suspended:** the username, then the address, then last seen.
  - **[S4]** „Diese Adresse nutzen auch N weitere Konten", with the count only. The names are
    on the other students' own cards.
  - The latest password reset from `password_resets_for()`.
- The delete confirmation asks for the username.

`ui-ux-designer` specifies the wording and layout.

### 11. Demo data

- `demo_fill()` calls `username_for_new_account()` for its three logins, and returns the
  usernames. The demo trainer's address is used by no other login [M3].
- `public/setup.php` and `demo_password_notice()` read usernames from
  `accounts WHERE is_demo=1`.

## Rejected

**Signing in by username *or* address.** An address that several accounts share cannot say
which one is signing in, and it would bring the collation oracle back.

**Keeping `accounts.email` unique.** The owner reversed it for student logins.

**Letting staff logins share an address [M3].** A trainer's recovery channel would be readable
by a family, or a family's by a trainer. „Vergessen" would then hand one of them the other's
sign-in. The owner refused it.

**Refusing every shared address except among one family.** The portal has no family entity.
„Gleiche Familie" is a confirmation by staff, which is what the portal can know [S5].

**Refusing, rather than confirming, a new address that other logins use [S5].** It would undo
the owner's decision that siblings share.

**Keeping `FILTER_VALIDATE_EMAIL` as the only address check [M1].** It accepts quoted local
parts. The collation ignores control characters inside them, so one mailbox has unboundedly
many spellings, each with a fresh throttle bucket.

**Stripping control characters instead of refusing them.** The stored value would then differ
from what was typed, silently.

**A constant dummy hash, or a new hash per failed sign-in [M2].** The first drifts from
`PASSWORD_DEFAULT`, and PHP 8.4 already moved it. The second is slower than a real
verification, which is a timing oracle.

**Queuing the reset mail to the typed address [S1].** Correct only as long as the gate holds.
The stored address is right by construction.

**Checking only the first token in a multi-account mail [S1].** One stale link in three would
go out.

**One throttle for both kinds of username-change refusal [S2].** 10 per quarter hour lets a
signed-in account test 960 names a day.

**A per-account forgot throttle only [S7].** One requester could still cycle through many
addresses.

**Hiding the password-reset event from the holder [S4].** With shared addresses, whoever reads
the mailbox can reset a child's password. The holder should find out.

**Letting `tracked()` snapshot every column [S6].** A future `tracked('accounts', …)` around a
password write would put the hash in the change log.

**Backfilling usernames in SQL.** It would be a second copy of `username_from_name()`, and the
migration's copy could never be fixed.

**Giving usernames lazily at the next sign-in.** An account without one cannot sign in.

**A PHP migration kind.** It would be a second mechanism beside the checksum ledger.

**A nullable `username`.** It would be a NULL for the code to guess about.

**One migration file for all three statements.** An interruption would block every later run.

**A non-unique index on `email`.** It is a fourth file for a rare, throttled lookup.

**A binary collation on `username`.** It is not needed, and it would cost the translation a
clause.

**`ext-intl` / `iconv`, reserved usernames, an underscore, numbering from 1, staff changing a
family's username, usernames that follow name edits, holding changed usernames back from
reuse, changing a username without the password.** The reasons are in §1, §2 and §8.

**One reset mail per account on the address.** The owner asked for one mail.

**Listing invited accounts in the reset mail.** It would skip the privacy acknowledgement.

**Mailing every existing account its new username when the update runs.** The runner is the
wrong place to send mail. The portal is in beta, and the `@` hint and „vergessen" cover it.

**Keeping the throttle resolution, or ADR 0007's identity table or `WEIGHT_STRING`.** With
the dot-atom gate and the ASCII username alphabet, there is nothing left to fold.

**Guarding against lock-out by throttling per IP only [S3].** It lets a guesser try every
username at 10 each. The owner accepted the lock-out risk instead.

## Consequences

- **Owner:**
  - **Decided [M3]:** staff logins need their own address.
  - **Decided [S3]:** the lock-out risk from guessable usernames is accepted.
  - **Still to give:** her yes to migrations 022–024 before `database-engineer` writes them.
  - **Still to give:** her release of the privacy-notice sentence, drafted by `docs-writer`.
    **A shared address means that whoever reads that mailbox can set a new password for every
    student account on it**, including a teenager's, and so read their messages with the
    trainer. §7, §9 and §10 make this visible to the holder and to staff. It does not
    prevent it. A child who needs privacy needs their own address.
- **Supersedes:**
  - ADR 0007 in full;
  - ADR 0010's refusal of an address in use, **except for staff logins**.
- **Action count:** +1 (`username_change`).
- **`database-engineer`:**
  - writes 022–024;
  - handles the SQLite rebuild;
  - makes the runner step call the backfill and ensure `sign_in_dummy_hash`;
  - extends `tests/migration-data.php`, including an existing staff/student shared pair
    that survives untouched.
  - **The MariaDB 10.11.14 check is amended [M1].** For every character of both the
    username alphabet and the `EMAIL_DOT_ATOM` alphabet:
    - no two distinct characters compare equal under `utf8mb4_unicode_ci`;
    - **no character compares equal to the empty string**, i.e. none is ignorable.

    If either fails, this record is amended before release.
  - runs `tests/mariadb-local.sh`. MySQL 8.0 stays unverified.
- **`backend-dev`:**
  - `app/core.php`:
    - `EMAIL_DOT_ATOM`, `email_is_dot_atom()` and the `email_value()` change;
    - the username functions.
  - `app/auth.php`:
    - `username_for_new_account()`, `staff_address_conflict()` and `logins_on_address()`;
    - `sign_in_dummy_hash()`, `accounts_sharing_address()` and `password_resets_for()`;
    - `give_every_account_a_username()` and `send_sign_in_details()`;
    - the invite mail.
  - `app/history.php`: `history_never_recorded()` and the label.
  - `app/mail.php`: the `preg_match_all` re-check of every token.
  - `app/tick.php`: the dummy-hash refresh in `prune_expired()`.
  - `app/actions.php`:
    - `login`, `forgot` and `activate` (audit, `auth_version`);
    - the throttles;
    - `change_account_email()`;
    - `account_invite`, `account_create`, `student_invite` and `student_save` (M3, S5);
    - the delete confirmation.
  - `app/actions_settings.php`: `username_change` (N5, S2, S6) and `email_change` (M3 twice).
  - `app/domain.php`: the deletions.
  - `app/demo.php`, `bin/console.php` and `public/setup.php`.
- **`frontend-dev`**, from `ui-ux-designer`'s specification:
  - `views/login.php`, `forgot.php` and `activate.php` (with the read-only username field);
  - `profile.php` (sign-in section, shared-address count, resets);
  - `student.php` (access card: count, last reset, „gleiche Familie" tick);
  - `accounts.php` (flags an existing staff address shared with another login);
  - `dashboard.php` and `students.php`;
  - `app/ui.php`.
- **`qa-tester`**, breaking each rule once:
  - **Generation, database and backfill:** as before.
  - **[M1]:**
    - `email_value()` refuses `"a b"@x.at`, a quoted local part holding `\x01`, and any
      control character;
    - `forgot` with such a value queues nothing and gives the same flash;
    - `email_is_dot_atom` is defined only in `core.php`, and `forgot` calls it before any
      `SELECT`.
  - **[M2]:**
    - the dummy hash's `password_get_info()` matches `PASSWORD_DEFAULT`'s algorithm and cost;
    - a no-row sign-in, a failed-pattern sign-in and an invited sign-in each call
      `password_verify()` once;
    - the `login` case contains no literal `$2y$`.
  - **[M3]:**
    - each of the five paths refuses a staff/other overlap in both directions;
    - two students on one address are still allowed;
    - `email_change` re-checks at confirmation.
  - **[S1]:**
    - the mail goes to the stored address;
    - blocks are in id order;
    - with one of three tokens stale, the job is cancelled.
  - **[S2]:** the sixth "taken" answer in a day is throttled, and each one is audited.
  - **[S4]:**
    - a reset raises `auth_version` and writes the audit;
    - the next sign-in flashes it;
    - Mein Konto shows the count and the resets.
  - **[S5]:** a second login on an address is refused without `same_family=1`, with the
    names, and allowed with it.
  - **[S6]:**
    - `username_change` calls `lock_row` before `tracked`;
    - a `tracked('accounts', …)` that also changes `password_hash` records no hash.
  - **[S7]:**
    - the fourth `forgot` for one address in an hour is throttled;
    - the eleventh from one IP is throttled;
    - a known address and an unknown one behave identically.
  - **[N5]:** `username_change` reads `current_password`.
  - **[N10]:** the reset page has a read-only `autocomplete="username"` field.
  - **Sign-in:** an unknown username is locked at the eleventh try exactly like a known one.
  - **`structure`:**
    - the rules above;
    - `staff_address_conflict` is called in exactly the five named handlers, and no other
      refusal of a shared address exists;
    - the earlier list of deleted functions.
- **`docs-writer`:**
  - `CHANGELOG`, `UPDATING` and `TESTING.md`: invitation, iPhone sign-in, „vergessen" with
    two children, the „gleiche Familie" tick, a staff address refused, and a password
    manager on the reset page;
  - both privacy drafts;
  - `VALIDATION.md`: record S3's accepted lock-out risk, and drop the ADR 0007 weakness
    once the amended MariaDB check has passed.
- **`security-reviewer`** re-reviews §5, §6 and §9 after implementation.
- **Must not:**
  - sign anybody in by address;
  - look up an account with a string that failed its format, username or dot-atom;
  - hash inside the sign-in request;
  - let staff write another person's username;
  - send a mail from the runner;
  - edit a shipped migration;
  - show a username or another family's name to a family;
  - refuse a shared address anywhere except through `staff_address_conflict()`;
  - record `password_hash`, `auth_version` or `last_seen_at` in the change log.

## In plain words, for the owner

- **Signing in.** Everybody will sign in with a username instead of an email address, such as
  `lena.mueller`. It is made automatically from the first and last name. If two people have
  the same name, the second gets a 2 on the end. Each person can change their own under
  „Mein Konto".
- **Shared addresses.** Brothers and sisters can all use their parent's email address, and
  each still has their own login. **Trainers and administrators keep an address of their own.**
- **Forgetting.** Someone who forgets their username or password types the email address and
  gets one email listing every username on that address, each with a link to set a new
  password.
- **The update.** Existing accounts are given their usernames automatically when it is
  installed; nothing needs to be run afterwards.

The database needs three small changes to the accounts table. They cannot be undone once
they have run, so we need your yes before they are written.

Whoever reads a shared mailbox can set a new password for every account on it. The portal now
tells the account holder when that happened, and shows you and the family how many accounts
share an address. If a teenager's messages with you should stay private from their parents,
that child needs an address of their own.

You accepted one risk: because usernames are easy to guess, somebody could type wrong
passwords on purpose and lock a child out of signing in for a short while. Nothing is revealed
by it, and „vergessen" still works.

## Open for the owner

1. Yes to the three database changes (022–024)?
2. Is it right that the update does **not** email existing accounts their new username?
   Recommended while the portal is in beta.
