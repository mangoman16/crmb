---
status: superseded by 0021
date: 2026-09-29
---

# 0019. Sign in with a username, and an address may be shared

> **Superseded by ADR 0021 (2026-10-01).** The owner reversed the usernames as well, so neither
> half of the title holds: every login has its own address (0020), and the address is the only
> sign-in (0021). 022 and 023 stay as shipped; 024 removes the column. What 0020 §8 kept stands as
> 0021 §1 carries it for the address: the address checks, the dummy hash, the sender's checks, the
> reset audit, the change-log exclusions, the throttles, the isolation assertion and `held_for()`.

> **Partly superseded by ADR 0020 (2026-09-30), before any of it shipped.** The owner reversed
> the shared address:
>
> - every login has its own address again;
> - sign-in takes the username **or** the address, in one box;
> - nobody sets another person's password.
>
> Migration 024 is deleted; 022 and 023 stand.
>
> **These parts no longer hold:**
>
> - the second half of the title;
> - in Context, the owner's words and the clarifications about shared addresses, staff addresses
>   [M3], and sign-in by username only;
> - §2: `staff_address_conflict()`, `logins_on_address()`, `accounts_sharing_address()` and
>   `send_sign_in_details()`;
> - §3: migration 024 and the SQLite rebuild;
> - §4: the paragraph on staff addresses already shared [M3, R10];
> - §5:
>   - step 1, the `@` refusal;
>   - "No code path signs in by address";
>   - the sign-in flash after a reset [R4], with its staff sentence;
> - §6: the one mail listing every login on an address, and leaving invited logins out;
> - §7: the sharing count on Mein Konto, and the direct-login flash;
> - §9: the staff rule as a rule of its own [M3], „Gleiche Familie" [S5], and the data-sheet label;
> - §10: the siblings hint, the sharing count and direct creation;
> - in Rejected:
>   - "Signing in by username *or* address";
>   - "Keeping `accounts.email` unique";
>   - "Refusing every shared address except within one family";
>   - "Refusing, rather than confirming, a new address that other logins use";
>   - "One reset mail per account on the address";
>   - "Listing invited accounts in the reset mail";
> - in Consequences and "In plain words", everything about shared addresses, 024, R10, S5 and the
>   privacy sentence on shared mailboxes;
> - "Open for the owner": closed, because the data sheet prints no username.
>
> **Everything else stands**, as ADR 0020 §8 lists it:
>
> - the username and its rules;
> - the address checks [M1, R2];
> - the sign-in timing [M2, R6, R9];
> - the sender [S1, R7];
> - the reset audit, and the 14-day window on Mein Konto and the access card [S4, I1, I2];
> - the change log [R5, S6];
> - the throttles [S2, S3, S7];
> - locking [R8, I3];
> - every creator calling the guards [R1], with `refuse_address_in_use()` in place of
>   `staff_address_conflict()`;
> - `held_for()` and `token_record()` [I4];
> - the runner's backfill.

*Amended twice on 2026-09-30, before any username code was written, and a third time the same
day, during implementation.*

- **First amendment.** The first design **failed security review**. The owner decided two
  findings: staff logins need their own address (M3), and the lock-out risk is accepted
  (S3). The rest were adopted.
- **Second amendment.** The re-review failed narrowly, on R1 and R2. R3 to R10 are adopted as
  well.
- **Third amendment.** Four gaps found while building it. None changes a decision the owner
  made:
  - one reset window, 14 days, everywhere [I1];
  - staff read a different last sentence in the reset notice [I2];
  - the isolation check names both server variables [I3];
  - the designer's backend asks, checked against this record [I4].

Each change is marked **[M1]** … **[R10]** and **[I1]** … **[I4]** where it lands.

## Context

Today an email address is the sign-in. `accounts.email` is `NOT NULL UNIQUE` (migration 001,
line 10: an inline constraint, so the index MariaDB and MySQL create is named `email`).

ADR 0010 built on that: one login is one student, and **"an address already in use is
refused"**.

The owner has changed the second half. Her words: "every child has their own account, 1
person = 1 account, families need more accounts. Add login with username; each person is
automatically given a username from first and last name + a number, which they can change
themselves. Change logging in from email-based to username-based; email is used if you forgot
your username or password."

Clarified with her:

- **Shared addresses.** Several student accounts may share one address: siblings use a
  parent's.
- **Staff addresses [M3, owner].** A trainer's or administrator's address may not be shared
  with any other login, in either direction.
- **Sign-in** is by username only.
- **„Benutzername oder Passwort vergessen"** sends **one** email to the address, listing every
  username on it with its own reset link. It never says whether the address exists.
- **Format.** `lena.mueller`: lower case, `ä→ae`, `ö→oe`, `ü→ue`, `ß→ss`, other accents
  stripped. A number is added only when the name is taken: `lena.mueller2`.
- **Changing it.** Everyone can change their own username later.

ADR 0010's first half stands: one login is one student (`student_one_account`).

What the code does now, and what this touches:

- **Sign-in and reset.**
  - `login` and `forgot` (`app/actions.php`) look accounts up `WHERE email=?`.
  - The throttle resolves the typed address to a row first (`attempted_identity()`). That
    leaves ADR 0007's one-bit oracle.
  - `login` verifies a missing row against a **hard-coded `$2y$10$` dummy hash**, while real
    hashes follow `PASSWORD_DEFAULT`. PHP 8.4 raised its cost to 12 [M2].
- **The mail sender.** For a `security` job, `process_mail()` checks only the **first**
  `token=` in the body, and compares addresses as stored strings [S1, R7].
- **`queue_mail()`** calls `email_value()` on its recipient. A throw there rolls back the whole
  action, such as a newsletter to every family [R2].
- **Where accounts are created:**
  - `create_admin_account()` (`app/auth.php:101`, called by `public/setup.php` and by
    `bin/console.php`, also with `--force`);
  - `account_invite` and `account_create`;
  - `student_invite` (invite and direct);
  - `demo_fill()` [R1].
- **Five refusals of a shared address:**
  - `own_address_needed()` in `student_invite`, `student_save` (twice) and
    `change_login_address()`;
  - „Diese Adresse hat schon ein Konto" in `account_invite` and `account_create`;
  - the check in `email_change`.

  Before each insert, `account_using_email()` holds the address `FOR UPDATE`.
- **Code that exists only because an address could not be shared:**
  - in `app/domain.php`: `account_with_address()`, `own_address_missing_sql()`,
    `students_needing_own_address()`, `own_address_taken_by()`, and one branch of
    `student_next_steps()`;
  - in `app/ui.php`: `own_address_notice()`, and one confirmation in
    `login_delete_details()`.
- **Names.** `accounts.name` is one field. A student's login is made from
  `students.first_name` and `last_name`.
- **The migration runner** applies SQL only, then always requires `database/defaults.php`,
  which already repairs data in PHP. The operator has no shell.
- **Stored addresses.**
  - Every address was written through `email_value()`: `FILTER_VALIDATE_EMAIL` without the
    Unicode flag.
  - It **accepts a quoted local part**, which may hold control characters.
  - `database-engineer` confirmed on **MariaDB 10.11.14** that `utf8mb4_unicode_ci` ignores
    control characters, so two different typed addresses can reach one row [M1]. The
    username alphabet held.
- **Locking.** `account_using_email()`'s range lock, like the ones below, relies on InnoDB's
  default isolation, **REPEATABLE READ**. Under it, a `FOR UPDATE` read also locks the gap
  where a missing row would go. Under READ COMMITTED it would not [R8].

## Decision

### 1. What a username is

- **Alphabet:** `a–z`, `0–9`, `.` and `-`.
  - It starts with a letter and ends with a letter or digit.
  - No two separators in a row.
  - 3 to 40 characters.
  - As a pattern: `/^[a-z](?:[a-z0-9]|[.-](?=[a-z0-9])){2,39}$/D`.
- **No underscore.** `_` is a `LIKE` wildcard, and §2 builds a `LIKE` pattern from a
  username. Without it the pattern is literal, with no escaping. It is also one character
  fewer to spell out on the phone.
- **Stored lower case, always.** Uniqueness ignores case because nothing else is ever
  written. The unique index also compares case-insensitively under `utf8mb4_unicode_ci`: a
  second guard, not the rule.
- **No reserved names.** A username is shown only to its holder and to staff (§8), so there
  is nobody for `trainerin` or `admin` to deceive. A list would be one more rule to keep, and
  it would turn the first administrator's own name, typed as "Admin", into `admin2`.
- **Not a secret.** `first.last` is guessable by design. The password and the throttles
  protect the account.
- **[S3, owner] The lock-out risk that follows is accepted for now.**
  - Anyone who guesses `lena.mueller` can lock that sign-in for the throttle window by typing
    ten wrong passwords.
  - Nothing is disclosed by it, and „vergessen" has its own throttle.
  - `VALIDATION.md` records it (`docs-writer`).

### 2. The rules, in one place each

**In `app/core.php`, next to `email_normalised()`.** These are pure functions, loaded first,
usable by everything including the runner.

**Two address checks, for two jobs [M1, R2]:**

- **`email_deliverable(string $normalised): bool`** asks whether a mail can be sent to this
  address.
  - It is `FILTER_VALIDATE_EMAIL` and ≤ 254 bytes: today's check, unchanged.
  - **`queue_mail()` uses it, not `email_value()`.** Every stored address was written through
    exactly this check, so no stored address, a legacy quoted one included, can make
    `queue_mail()` throw and roll back a newsletter to every other family.
- **`EMAIL_DOT_ATOM` and `email_is_dot_atom(string $normalised): bool`** ask whether this
  address may be **written** or **looked up**. It is defined once.
  - It allows a dot-atom local part of RFC 5322 `atext`, then `@`, then dot-separated LDH
    labels.
  - The whole address is ASCII, lower case and at most 254 bytes.
  - No quotes, spaces, control characters or IP literal.
- **`email_value()`** is the write check.
  - It runs `email_normalised()`, then `email_deliverable()`, then `email_is_dot_atom()`, with
    one message: „Ungültige E-Mail-Adresse."
  - A legacy address that is not dot-atom keeps receiving mail. It cannot be used for
    „vergessen" (§6), and saving its record requires replacing it.

**The username functions:**

- **`username_normalised(string $typed): string`**
  - It trims, applies `mb_strtolower`, then one explicit transliteration table: `ä ae`,
    `ö oe`, `ü ue`, `ß ss` (and `ẞ`), Latin-1 and Latin Extended-A letters to their base
    letter, plus `æ→ae`, `œ→oe`, `þ→th`, `ð→d`, `đ→d`, `ı→i`.
  - It keeps every other character. The result can then fail the pattern, and is never looked
    up (§5).
  - So `Lena.Müller`, typed on an iPhone that capitalised the first letter, signs in as
    `lena.mueller`.
- **`username_value(string $typed): string`**
  - It normalises, then applies the pattern.
  - On failure it throws `UserError` with the rule in words.
  - It is the only way a typed username reaches a write.
- **`username_from_name(string $first, string $last): string`** builds the base, with no
  number:
  1. Transliterate.
  2. Inside a part, a run of spaces, hyphens or dots becomes one `-`. Apostrophes are
     dropped. Anything else outside the alphabet is dropped.
  3. Join the parts with `.` and trim separators.
  4. Cut at a boundary to 36 characters, leaving room for a number up to 9999.
  5. An empty last name gives the first part alone.
  6. A result shorter than 3, or not starting with a letter, becomes `konto`. That covers
     names written only in Cyrillic, Greek or CJK script.

  | First name | Last name | Username |
  | --- | --- | --- |
  | `Anna Lena` | `von der Heide` | `anna-lena.von-der-heide` |
  | `Hans-Peter` | `O'Neill` | `hans-peter.oneill` |
  | `Jürgen` | `Groß` | `juergen.gross` |
  | `Łukasz` | `Wałęsa` | `lukasz.walesa` |
  | `王` | `芳` | `konto` |
- **`username_from_full_name(string $name): string`** is for staff and logins without a
  student. It takes the first and the last word; middle words are dropped.
- **`username_first_free(string $base, array $taken): string`** returns `$base`, or else
  `$base.'2'`, `$base.'3'` and so on, the lowest free one first. So a number appears only
  when the name is taken, and a number freed by a deleted account is used again.

**A refused form, asked for by name [I4]:**

- **`held_for(string $action): array`**, beside `holding_input()`, returns the held fields when
  the last refused form was `$action` on this page, record and tab, and `[]` otherwise.
- **`holding_input()` becomes `held_for(form_context()) !== []`**, so the page/id/tab match
  exists once. `remember_input()` never holds an empty set, so the two cannot disagree.
- Views use it to react to a refusal before they open the form: the `@` hint on the sign-in
  page, the open „Benutzernamen ändern" on Mein Konto, the „Gleiche Familie" tick on the
  student page. It reads the session only. Views still write nothing (ADR 0003).

**No `ext-intl` and no `iconv('…//TRANSLIT')`.** The first is not in `composer.json` and a
shared host may lack it. The second depends on the locale and gives different answers on
glibc and musl. One table in the file gives the same username on every host, and the default
suite can test it.

**In `app/auth.php`, replacing `account_using_email()`:**

- **`username_for_new_account($first, $last)`**
  - It refuses outside a transaction.
  - It reads `SELECT username … WHERE username=? OR username LIKE ? FOR UPDATE`.
  - It keeps `$base` and `$base` followed by digits, and returns `username_first_free()`.
  - A second "Lena Müller" invited in the same second waits, then sees the first.
- **`staff_address_conflict(string $email, bool $newIsStaff, ?int $exceptId = null): void`
  [M3].**
  - It refuses outside a transaction.
  - It reads `SELECT id, role FROM accounts WHERE email=? AND id<>? FOR UPDATE`.
  - It throws `UserError` when a staff login would share the address: „Ein Konto für
    Trainerin oder Administrator braucht eine eigene E-Mail-Adresse."
  - It also throws when a student login would share a staff login's address: „Diese Adresse
    gehört zu einem Mitarbeiterkonto."
  - It is the only remaining refusal of a shared address.
- **REPEATABLE READ [R8, I3].** Both functions carry a comment saying they rely on it.
  - `tests/suites/migrations.php` asserts it on every run against a real engine, not in
    `tests/mariadb-local.sh`, so `tests/existing-database.sh` asks a hosting provider's
    server too.
  - The server variable has two names. **MariaDB 10.11.14 has only `@@tx_isolation`**:
    `database-engineer` found that `@@transaction_isolation` fails there with error 1193.
    **MySQL 8.0 has only `@@transaction_isolation`**, because it removed `tx_isolation`.
  - The check tries both and expects `REPEATABLE-READ`. The MySQL half follows MySQL's
    documentation and has not been run.
  - The portal itself never reads or changes the level.
- **`logins_on_address($email, $exceptId)`** returns the other logins on an address, oldest
  first [S5].
  - **Row shape [I4]:**
    - `id`, the **account's** id;
    - `username`;
    - `name`, as `login_holder_name()` gives it;
    - `student_id` (`?int`, null for a login with no student);
    - `first_name` and `last_name`, the student's, `''` without one.
  - `id` stays the account's because every caller in `app/` is about logins, and some have no
    student. The access card maps the rows with a `student_id` to `students_notice()`'s `id`,
    `first_name` and `last_name` in the view. It adds „und ein Zugang ohne Schüler" for the
    rest.
  - One `LEFT JOIN students` may replace today's query per row, provided `name` stays what
    `login_holder_name()` gives.
  - It names people, so it is called for staff only (§8). A view that calls it with a held,
    typed address first gates that address on `email_is_dot_atom(email_normalised(…))`, like
    every other lookup.
- **`sign_in_dummy_hash(): string` [M2, R9]** returns the setting `sign_in_dummy_hash` (`raw`,
  `internal`, `system`, default `''`).
  - **It is created and refreshed by the runner step (§4)**, and refreshed nightly by
    `prune_expired()`, with `password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)`
    whenever it is empty or `password_needs_rehash()` says so.
  - A request never refreshes it.
  - **Fallback, once only:** if a request finds it missing (a restore without settings), the
    request creates it once, stores it and logs that it did.
- **`accounts_sharing_address($accountId)`** [S4].
- **`password_resets_for($accountId, $days)`**: the `account.password_reset` audit entries
  [S4]. Every caller passes 14 [I1].
- **`give_every_account_a_username()`** (§4) and **`send_sign_in_details()`** (§6).

**No new file.** Nothing here needs anything later than `auth.php`. The runner's call (§4)
happens at request time, after everything is loaded.

**In `app/history.php` [S6, R5].** `history_never_recorded(string $entity): array` returns,
for `accounts`, `password_hash`, `auth_version` and `last_seen_at`. `history_record()` strips
them from both snapshots **for every operation**: insert, update and delete.

### 3. Schema: three migrations, each with at most one statement that cannot run twice

A run that stops partway restarts from statement 1 on the next page view (ADR 0015). MySQL 8.0
has no `IF [NOT] EXISTS` for indexes, so each statement that cannot run twice gets a file of
its own and comes last in it.

1. **`022_a_username_for_every_account.sql`**

   ```sql
   ALTER TABLE accounts ADD COLUMN username VARCHAR(40) NOT NULL DEFAULT '' AFTER email;
   ```

   `''` means "not given one yet". It can never sign in, because it fails the pattern, and §4
   replaces it.
2. **`023_usernames_are_unique.sql`**

   ```sql
   UPDATE accounts SET username = CONCAT('#', id) WHERE username = '';
   CREATE UNIQUE INDEX account_username ON accounts (username);
   ```

   The placeholder `#<id>` is unique because ids are. It is outside the alphabet, so it can
   never collide with a real username or pass the sign-in lookup, and §4 finds it easily. The
   `UPDATE` is idempotent, and the index comes last.
3. **`024_an_address_may_be_shared.sql`**

   ```sql
   ALTER TABLE accounts DROP INDEX email;
   ```

   It runs last, so a half-applied update never leaves accounts without a unique identifier.
   No foreign key uses the index.

Also:

- **Nothing is deleted.** `schema_guarded_tables()` is not widened, and 001 is not edited
  (ADR 0004).
- **SQLite.** The inline `UNIQUE` from 001 is an autoindex that `DROP INDEX` cannot remove.
  The translation must rebuild `accounts` without it, not mark it unsupported. Every sibling
  test depends on two accounts sharing an address. This is proved by inserting two such
  accounts after 024.
- **No schema for the staff rule [M3].** A constraint cannot express "unique among staff and
  across the staff/student divide". The rule is enforced in PHP, under `FOR UPDATE`.

### 4. Existing accounts get their username from the runner, in PHP

The first part of `database/defaults.php` runs after every `schema_apply()`: for the
installer, the console and the first request after an upload alike.

It calls `give_every_account_a_username()` inside `transactional()`, which:

1. selects the accounts whose username is `''` or starts with `#`, `LEFT JOIN students`, in id
   order;
2. builds the base from the student's names, or from `a.name`;
3. picks with `username_first_free()` against a set read once, so the oldest account gets the
   plain name;
4. runs `UPDATE` for each.

- **Not `tracked()`.** No person made the change.
- **Idempotent.** Once every account has a username, the select is one indexed query that
  returns nothing.
- **On failure** the stamp is not written, and the next request retries. Meanwhile the portal
  shows the blocked page rather than a sign-in nobody can use.

The same step creates or refreshes `sign_in_dummy_hash` [M2, R9].

**Staff addresses already shared with another login [M3, R10].**

- None can exist before 024, because the unique index forbade it. After 024, one could only be
  entered by hand.
- The rule governs writes, and the runner never changes an address.
- The Konten page flags such a pair to the administrator: „Diese Adresse nutzt auch ‹Name›".

**Nobody is mailed their username by the update.** The owner decided this: the portal is in
beta. Somebody who types an address on the sign-in page is pointed to „vergessen" (§5).

### 5. Signing in

**The form** asks for „Benutzername", with `name="username"`, `autocomplete="username"`,
`autocapitalize="none"`, `autocorrect="off"` and `spellcheck="false"`.

`attempted_username()` is `username_normalised(post('username'))`: one derivation for the
lookup and for the throttle.

**The `login` case:**

1. **An `@` in the typed value.** It refuses before any lookup, pointing to „vergessen". The
   answer depends only on the input's shape.
2. **A pattern failure.** There is no lookup. `password_verify()` runs against
   `sign_in_dummy_hash()`, then the usual refusal [M2].
3. **Otherwise,** `SELECT * FROM accounts WHERE username=? FOR UPDATE`.
   - With no row, or an invited login without a hash, it verifies against
     `sign_in_dummy_hash()`.
   - With a suspended login, or an invited one with a hash, it verifies against the stored
     hash.
   - Then the same refusal.
   - On success it rehashes if needed and signs in.
4. **A recent reset [S4, R4].**
   - On **every** successful sign-in, while the account has an `account.password_reset` from
     the **last 14 days**, the flash names the newest: „Dein Passwort wurde am ‹Datum,
     Uhrzeit› über „Benutzername oder Passwort vergessen" neu gesetzt."
   - **The last sentence depends on who reads it [I2].**
     - A **student login** reads „Warst du das nicht, wende dich an die Trainerin."
     - A **staff login** (`is_staff()`: trainer or administrator) reads „Warst du das nicht,
       ändere dein Passwort gleich unter „Mein Konto“." The family's sentence would send the
       trainer to herself.
     - English, from the designer's specification (§2.4): "If that was not you, please
       contact your coach." and "If that was not you, change your password under “My
       account” straight away."
   - The exception is the sign-in the reset itself performs. The `activate` reset branch
     passes its audit id in the session, and that entry is skipped once.
   - `last_seen_at` is not the condition, because any visit in between would hide the notice.

**No code path signs in by address.**

**The throttle** is `throttle('login', username_identity(attempted_username()), 10)`, with
`forget_attempts_after_success()` clearing the same bucket.

- **No row is resolved first.** The lookup only ever runs with an ASCII string in normal form.
  The collation compares that as bytes (verified on MariaDB 10.11.14). An existing username
  and a missing one are counted identically, so **the form is not an oracle**.
- A comment at the function says so, so that nobody "restores" the lookup.
- The accepted lock-out (S3) follows from this.

**A residual timing difference [R6].**

- After a PHP upgrade raises `PASSWORD_DEFAULT`'s cost, a login nobody has used since keeps
  its old, cheaper hash until its next sign-in. The dummy is refreshed at once.
- A failed sign-in for a **dormant existing** username is therefore measurably faster than
  one for a missing username.
- This is known and accepted. `VALIDATION.md` records it (`docs-writer`). It closes as each
  account signs in.

### 6. „Benutzername oder Passwort vergessen"

**The `forgot` case:**

1. `$typed = attempted_email()`.
2. **Throttles [S7].** Both are counted for every request:
   - `throttle('forgot', 'address:'.$typed, 3, 3600)`;
   - `throttle('forgot-ip', $_SERVER['REMOTE_ADDR'] ?? 'local', 10, 3600)`.
3. **The lookup is gated on `email_is_dot_atom($typed)` [M1].** A value that fails is never
   looked up and sends nothing. Only plain ASCII `atext` is ever looked up. With no ignorable
   or folded characters (checked on MariaDB, see Consequences), one mailbox cannot be reached
   under several spellings, each with a fresh throttle bucket. That was the abuse `bcfa796`
   closed.
4. If the check passes, `send_sign_in_details($typed)` runs.
5. The answer is always „Wenn zu dieser Adresse ein aktives Konto gehört, ist eine E-Mail an
   sie unterwegs." It never names the address.

Existing and unknown addresses behave identically. **This closes ADR 0007's channel**, and
0007 is superseded.

**`send_sign_in_details()` [S1]:**

1. It selects the active, verified accounts with that address, **in id order**. Invited
   accounts are left out: their way in is the invitation, which already names the username
   (§7).
2. It calls `make_token($id,'reset')` for each. Each call replaces only that account's earlier
   link.
3. It builds **one** mail with a block per account: name, username and link, valid one hour.
   The mail is in `locale()`.
4. It queues the mail once, to **the stored `a.email` of the lowest id**, never the typed
   string.
5. With `account_mail_ready()` false, it sends nothing, and the answer is unchanged.

**The sender re-checks every link [S1, R7].** For a `security` job, `process_mail()` extracts
every `token=` with `preg_match_all`. It sends only if there is at least one, and each one:

- is live;
- belongs to an account that is not suspended;
- belongs to an account whose address matches the job's recipient, compared as
  `email_normalised()` of **both sides**, or as the token's `target_email` for the `email`
  purpose.

Otherwise the job is cancelled.

**The `activate` reset branch:**

- It keeps raising `auth_version`.
- It writes `audit('account.password_reset')` and remembers its id for §5 [S4, R4].
- It shows the username as a read-only `autocomplete="username"` field [N10].
- **`token_record()` also selects `a.username` [I4]**, which is where the page reads it from.
  Whoever holds the link is the holder, or reads the mailbox that could reset the login
  anyway, so this discloses nothing new.

### 7. Telling a person their username

- **The invitation mail** names the username and says it can be changed under „Mein Konto".
- **The activation page** shows it as the read-only field [N10], and the flash repeats it.
- **„Mein Konto"**:
  - the username, and „Benutzernamen ändern";
  - **[S4]** „Diese E-Mail-Adresse nutzen auch N weitere Konten." (the count only);
  - the resets of the last **14 days** [I1], the same window as the sign-in flash (§5) and the
    access card (§10). Its last sentence splits like the flash [I2]. Families read „Warst du
    das nicht? Ändere dein Passwort gleich hier unten und sag deiner Trainerin Bescheid."
    Staff read the same sentence without its second half.
- **A direct login:** the flash names the username, so the administrator can tell the person
  next to her.
- **Setup:**
  - `create_admin_account()` generates the username;
  - the finished page (large, monospace) and `bin/console.php` show it;
  - there is no username field, because the owner said "automatically given".

### 8. Who sees a username, and who changes it

- **Shown** to the holder and to staff: the access card, the Konten page and the change log.
  **Never to another family.**
- **Changed only by the holder**, with `username_change` in `app/actions_settings.php`:
  1. `require_user()`. It refuses while impersonating.
  2. It checks the password posted as **`current_password`** [N5].
  3. `username_value(post('username'))`.
  4. An unchanged value gets a flash and no write.
  5. **`lock_row('accounts', $id)` [S6].**
  6. `SELECT id FROM accounts WHERE username=? FOR UPDATE`. If the name is taken [S2, R3]:
     - `throttle('username-taken', account_identity($id), 5, 86400)`;
     - `audit('account.username_taken')`;
     - **`flash(…,'error')`, `remember_input('username_change')` and a return to
       `profile`**, not a throw. A throw would roll back the audit row.
  7. `tracked('accounts', …)` around the `UPDATE`. Because of S6, the line holds only
     `username`.
  8. `audit('account.username_changed')`, and a flash naming the new username.
  - `auth_version` is not raised: a username is not a secret.
  - The action stays in the `account-security` list as well.
  - It is changeable but not undoable: `history.php` has no undo, and the holder changes it
    back the same way.
- **Staff do not change anybody else's username.** A trainer who renamed a family's login
  would lock them out without their knowing. A typo before the invitation is accepted is fixed
  by withdrawing the invitation and inviting again.
- **A username does not follow name changes.** A login that renamed itself would lock its
  holder out.

### 9. The address, now that it is not the sign-in

`accounts.email` is where invitations, sign-in details, reset links, invoices, reminders and
notifications go. It is the **recovery channel**. ADR 0010's "One address" rule stands.

- **`change_login_address()` is renamed `change_account_email()`.**
  - It keeps updating both copies, raising `auth_version`, deleting tokens and cancelling
    mail.
  - It calls `staff_address_conflict()`.
  - `refuse_unless_student_login()` stays.
- **`email_change`** keeps the password check and the confirmation mail. It calls
  `staff_address_conflict()` both when asked and at confirmation [M3].
  - The refusal tells a signed-in person that an address belongs to staff. That is one bit,
    attributable and throttled, about addresses families write to anyway.
  - Staff still cannot re-address an active or suspended login: a trainer who could move a
    family's recovery channel could then ask „vergessen" for a link to her own mailbox.
- **The staff rule is enforced [M3, R1] in every block that creates an account**, inside its
  transaction and next to `username_for_new_account()`:
  - `create_admin_account()` (true), which covers setup and the console with `--force`;
  - `account_invite` and `account_create` (true);
  - `student_invite`, both modes (false);
  - `demo_fill()` (true for its trainer, false for its students).
- **It is also enforced in every re-addressing:**
  - `email_change`;
  - `student_save`, through `change_account_email()`.

  **No other refusal of a shared address remains.** A future role change from student to staff
  must pass the same check. No such path exists today.
- **„Gleiche Familie" [S5].**
  - `student_invite` (invite or direct) and `student_save`'s re-addressing check
    `logins_on_address()`. When it returns other logins, the action refuses unless
    `post('same_family')==='1'`.
  - The message names those logins and says that whoever reads the address can reset each of
    them. `remember_input()` brings the form back with the tick.
  - This is a confirmation, not a refusal. Only staff see it.
  - `email_change` by the holder shows no names.
- **Deleted:** `own_address_needed()`, `account_using_email()`, the four `domain.php` helpers,
  the „Eigene E-Mail-Adresse eintragen" branch, and `own_address_notice()` with its calls.
- **Deleting a login** is confirmed by typing the **username**. An address shared by three
  logins confirms nothing about which one is going.
  - The comparison is `username_normalised(post('confirmation')) === $a['username']` [I4]. It
    is the sign-in's own normalisation, so an iPhone's capital first letter still matches, and
    what signs in also confirms.
- **The printed data sheet [I4]** (`views/print.php`) labels the field „E-Mail-Adresse (für
  Einladung und Rechnungen)" / "Email address (for the invitation and invoices)". The old „…
  für das Portal" says the address signs in, which is no longer true. The sheet prints no
  username. Whether a filled sheet should is open for the owner (below).

### 10. The student page's access card

The rule is unchanged: one login per student, given only by `student_invite`.

- **No login:** the invitation is offered whenever there is an address. The hint says that
  siblings may share one.
- **Invited:** the address and the username.
- **Active and suspended:**
  - username, then address, then last seen;
  - **[S4]** „Diese Adresse nutzen auch N weitere Konten";
  - the latest reset, if it is from the last 14 days [I1].
- The delete confirmation asks for the username.

### 11. Demo data

- `demo_fill()` calls `username_for_new_account()` and `staff_address_conflict()` for each
  login [R1].
- It returns the usernames it made, because a forced fill beside real data could produce
  `lena.hofer2`.
- The demo trainer's address is used by no other login.
- `public/setup.php` and `demo_password_notice()` read usernames from
  `accounts WHERE is_demo=1`.

## Rejected

**Signing in by username *or* address.**

- The owner chose username only.
- An address that several accounts share cannot say which one is signing in.
- Accepting an address "when it happens to be unique" would make a sign-in stop working the
  day a sibling is invited.
- It would bring the collation oracle back.

**Keeping `accounts.email` unique.** The owner reversed it for student logins: siblings use a
parent's address.

**Letting staff logins share an address [M3].** A trainer's recovery channel would be readable
by a family, or a family's by a trainer. „Vergessen" would then hand one of them the other's
sign-in. The owner refused it.

**Enforcing the staff rule only in the five portal actions [R1].** Setup, `console --force` and
the demo fill create staff logins too. A rule that three creators skip is not a rule.

**Refusing every shared address except within one family.** The portal has no family entity.
A „gleiche Familie" confirmation by staff is what the portal can know.

**Refusing, rather than confirming, a new address that other logins use [S5].** It would undo
the owner's decision that siblings share.

**One address check for sending, writing and looking up [R2].** Tightening it for writes made
`queue_mail()` throw on legacy stored addresses, and so roll back whole newsletters. Sending
needs only deliverability. Writing and lookup need the strict shape.

**Keeping `FILTER_VALIDATE_EMAIL` as the write and lookup check [M1].** It accepts quoted local
parts. The collation ignores control characters inside them, so one mailbox has unboundedly
many spellings, each with a fresh throttle bucket.

**Stripping control characters instead of refusing them.** The stored value would silently
differ from what was typed.

**A constant dummy hash [M2].** It drifts from `PASSWORD_DEFAULT`, and PHP 8.4 already moved
it.

**A new dummy hash per failed sign-in, or refreshed in the request path [M2, R9].** Hashing is
slower than verifying, which is a timing oracle. A request may only repair a missing hash,
once.

**Throwing on "username taken" [R3].** It rolls back the audit row that makes each test
attributable.

**Flashing a reset only when it is newer than `last_seen_at` [R4].** Any visit between the
reset and the next sign-in would hide it.

**A longer window on Mein Konto than for the flash, 30 days against 14 [I1].** A list is a
record rather than a reminder, which is the one argument for it. Nothing in the reviews or in
the owner's words asked for it, and "In plain words" below promises two weeks. Two numbers are
two things to explain to a family and two things to test.

**One reset sentence for everybody [I2].** „Wende dich an die Trainerin" sends the trainer to
herself, and an administrator to somebody who cannot help her.

**Asserting `@@transaction_isolation` only, or `@@tx_isolation` only [I3].** The first fails
on MariaDB 10.11.14, the one engine that has been run. The second fails on MySQL 8.0.

**Making `logins_on_address()`'s `id` the student's [I4].** Konten and the actions' refusals
name logins, and some logins have no student. An `id` that means an account in one caller and
a student in another is a bug waiting for the next caller.

**Comparing the delete confirmation byte for byte [I4].** An iPhone capitalises the first
letter, so the holder's own username, typed correctly, would be refused.

**Stripping never-recorded columns on update only [R5].** An insert or delete snapshot would
still carry the hash.

**Comparing addresses byte for byte in the sender [R7].** A stored address with capitals,
written before `email_value()` lower-cased, would cancel a real reset mail.

**Queuing the reset mail to the typed address [S1].** It is correct only as long as the gate
holds. The stored address is right by construction.

**Checking only the first token in a multi-account mail [S1].** One stale link in three would
go out.

**One throttle for both username-change refusals [S2].** Ten per quarter hour lets a signed-in
account test 960 names a day.

**A per-address forgot throttle only [S7].** One requester could cycle through many addresses.

**Hiding a password reset from its holder [S4].** With shared addresses, whoever reads the
mailbox can reset a child's password. The holder should find out.

**Letting `tracked()` snapshot every column [S6].** A future `tracked('accounts', …)` around a
password write would put the hash in the change log.

**Guarding against lock-out with a per-IP throttle only [S3].** It lets one guesser try every
username at 10 attempts each. The owner accepted the lock-out risk instead.

**Backfilling usernames in SQL.**

- SQL cannot strip arbitrary accents without a second transliteration table.
- Numbering duplicates needs window functions that the SQLite translation would have to
  imitate.
- Above all, it would be a second copy of `username_from_name()` in another language. The two
  would drift, and the migration's copy could never be fixed.

**Giving usernames lazily at the next sign-in.** Sign-in is by username, so an account without
one cannot sign in to be given one. An invited account needs its username before anyone can
tell it to them.

**A PHP migration kind (`022_….php`).**

- It would be a second mechanism beside `migration_files()` and the checksum ledger, for one
  backfill.
- `database/defaults.php` is already the runner's PHP step.
- `CLAUDE.md`: "adding a second path for any of them is how they start disagreeing".

**A nullable `username` with `DEFAULT NULL`.** It is the NULL-to-guess-about that `CLAUDE.md`
forbids. `''` plus the `#<id>` placeholder gives every state a visible value that cannot sign
in.

**One migration file for all of it.** Three statements that cannot run twice would sit in one
file. An interruption after the first would block every later run, and the operator has no
shell.

**A non-unique index on `accounts.email` in place of the unique one.** It is a fourth statement
and a fourth file, for a lookup that runs a few times a month against tens of rows.

**A binary collation on `username`.** The values are ASCII lower case by construction, so every
collation agrees on them. It would cost the SQLite translation a new clause, and lose the
case-insensitive second guard.

**`ext-intl` / `iconv` transliteration.** It depends on the host (§2).

**Reserved usernames.** Nobody outside staff sees a username (§1).

**An underscore in the alphabet.** It is a `LIKE` wildcard (§1).

**Numbering every username (`lena.mueller1`), or numbering from 1.** The owner said a number is
added only when the name is taken.

**Staff changing a family's username, or the username following edits to the name.** Either
would lock the holder out without their knowing (§8).

**Holding a changed username back from reuse.** Usernames are never shown to other families,
and this is one club. It would be a table or a column for a problem nobody here has.

**Changing a username without the password.** Anyone holding an unlocked phone could rename a
child's login. `email_change` asks for the password for the same reason.

**One reset mail per account on the address.** The owner asked for one mail listing them all,
which is also easier for a parent with three children.

**Listing invited accounts in the reset mail.** A reset link for a never-activated account would
skip the privacy acknowledgement that activation records.

**Mailing every existing account its username when the update runs.**

- The runner is the wrong place to send mail, and SMTP may not be tested yet.
- The portal is in beta, and the owner decided against it.
- ADR 0010 set the precedent that an update does not write to families.
- The `@` hint and „vergessen" cover anyone who does not know their username.

**Keeping the throttle resolution (`attempted_identity()` looking the row up).** It existed
because the collation folded spellings PHP could not. With ASCII normal forms on both sides,
nothing is left to fold, and the lookup was the source of ADR 0007's oracle.

**ADR 0007's identity table, or `WEIGHT_STRING`.** Both close the oracle, at the cost of a
schema change or an unstable engine function. The gates above close it with neither.

## Consequences

- **Owner. Decided:**
  - username sign-in, including migrations 022–024 (approved; the portal is in beta);
  - staff logins need their own address (M3);
  - the lock-out risk is accepted (S3);
  - existing accounts are **not** emailed their username by the update.
- **Owner. Still to come:** `docs-writer` drafts the privacy-notice sentence on shared
  addresses, and she releases it. The sentence must say that whoever reads a shared mailbox
  can set a new password for every student account on it. §5, §7 and §10 make that visible;
  they do not prevent it.
- **Supersedes:**
  - ADR 0007, in full;
  - ADR 0010's refusal of an address in use, **except for staff logins**.
- **Action count:** +1 (`username_change`).
- **`database-engineer`:**
  - migrations 022–024, the SQLite rebuild, and the runner step (backfill and dummy hash, R9);
  - `tests/migration-data.php`:
    - the username cases;
    - **one legacy quoted address**, inserted before the update, proving that news still
      queues for every other account and that the legacy account keeps its address [R2];
    - **a staff/student pair on one address, inserted after 024**, proving that the runner
      leaves it and that the Konten page flags it [R10];
  - **the MariaDB 10.11.14 check [M1]:** for every character of the username alphabet and of
    `EMAIL_DOT_ATOM`, no two distinct characters compare equal, and **none compares equal to
    `''`**. If it fails, this record is amended before release;
  - the isolation assertion [R8, I3], in `tests/suites/migrations.php`, trying both variable
    names;
  - MySQL 8.0 stays unverified.
- **`backend-dev`:**
  - `core.php`: `email_deliverable()`, `EMAIL_DOT_ATOM`, `email_is_dot_atom()`, the
    `email_value()` change, the username functions, and `held_for()` with `holding_input()`
    rebuilt on it [I4];
  - `mail.php`: `queue_mail()` uses `email_deliverable()` [R2]; `process_mail()` checks every
    token and normalises both sides [R7];
  - `auth.php`: the §2 functions with the R8 comments, `create_admin_account()` calling both
    creation guards [R1], `logins_on_address()`'s row shape and `token_record()`'s `username`
    [I4];
  - `history.php`: the columns never recorded, for every operation [R5];
  - `tick.php`: the dummy-hash refresh;
  - `actions.php`: `login` (R4, and the staff sentence, I2), `forgot`, `activate`, the creators
    and re-addressers (M3, R1, S5), and the delete confirmation (I4);
  - `actions_settings.php`: `username_change` (R3, N5, S2, S6) and `email_change`;
  - `demo.php` (R1), `domain.php`, `bin/console.php` and `public/setup.php`.
- **`frontend-dev`**, from `ui-ux-designer`'s specification: the login, forgot, activate
  (read-only username), profile, student, accounts, dashboard, students and print [I4] views,
  and `app/ui.php`.
- **`qa-tester`**, breaking each rule once on purpose:
  - **Generation:** the §2 table, plus `ÄNNE` → `aenne` and `ẞ` → `ss`; a second and third
    Lena Müller get `…2` and `…3`; a freed `…2` is reused; `konto` is the fallback.
  - **Database:** two accounts on one address after 024, SQLite included; the same username
    is refused with 23000; `tests/suites/errors.php` needs another unique key to provoke a
    PDOException.
  - **Backfill:** every account matches the pattern; a student login uses the student's names;
    the older account keeps the plain name; a second run changes nothing; the account count is
    unchanged.
  - **[R1] structure:** every block with `INSERT INTO accounts` calls both
    `staff_address_conflict()` and `username_for_new_account()`. **Separately**, no refusal of
    a shared address exists outside `staff_address_conflict()`.
  - **[R1] behaviour:** `create_admin_account()`, with and without `--force`, refuses an
    address a student login uses; `demo_fill()` refuses to give its trainer a student's
    address.
  - **[R2]:** `queue_mail()` accepts a legacy quoted address; `email_value()` refuses it; a
    newsletter with one such recipient queues for all the others.
  - **[R3]:** a "taken" answer leaves an audit row and a flash, without a throw.
    `username_change` is in `structure.php`'s throttling list.
  - **[R4]:** a reset 10 days ago flashes on two sign-ins with a visit between them; one 15
    days ago does not; the reset's own sign-in does not.
  - **[I1]:** Mein Konto lists a reset from 13 days ago and not one from 15.
  - **[I2]:** a trainer's and an administrator's flash do not say „wende dich an die
    Trainerin"; a student's does.
  - **[I4]:** the delete confirmation accepts `Lena.Mueller` for `lena.mueller`;
    `holding_input()` and `held_for(form_context())` agree; `logins_on_address()` gives a
    null `student_id` for a login with no student.
  - **[R5]:** an insert and a delete of an account record none of the three columns.
  - **[R7]:** a stored `Lena@Example.at` still receives its reset mail.
  - **[R9]:** after the runner, the dummy hash exists and matches `PASSWORD_DEFAULT`; a request
    refreshes nothing.
  - **[M1]:** `"a b"@x.at` and control characters are refused for writes and never looked up,
    and `forgot` gates before any `SELECT`.
  - **[M2]:** each failure path calls `password_verify()` once, and there is no literal `$2y$`
    in `login`.
  - **[M3]:** every creator and re-addresser refuses a staff overlap in both directions; two
    students on one address are allowed; `email_change` re-checks at confirmation.
  - **[S1]:** the mail goes to the stored address, in id order, and one stale token of three
    cancels it.
  - **[S2]:** the sixth "taken" answer in a day is throttled.
  - **[S4]:** a reset raises `auth_version` and is audited; Mein Konto shows the count and
    the resets.
  - **[S5]:** without the tick the action refuses, naming the other logins; with it, it
    proceeds.
  - **[S6]:** `lock_row` comes before `tracked`, and no hash is recorded.
  - **[S7]:** the fourth request per address in an hour, and the eleventh per IP, are
    throttled; known and unknown addresses behave alike.
  - **[N5]:** `username_change` reads `current_password`.
  - **[N10]:** the reset page has the read-only field.
  - **Sign-in:** `Lena.Müller` signs in as `lena.mueller`; an address gets the hint; an
    unknown username locks at the eleventh try exactly like a known one; success clears the
    bucket.
  - **Structure:** `login` reads `WHERE username=?`; `views/login.php` has no `email` field;
    `['username']` is printed only in the views named in §8; the deleted functions are
    defined nowhere.
- **`docs-writer`:**
  - `CHANGELOG`, `UPDATING` and `TESTING.md`;
  - the privacy draft above;
  - `VALIDATION.md`: S3's accepted lock-out, **R6's dormant-account timing residual**, and
    dropping the ADR 0007 weakness once the MariaDB check has passed.
- **`security-reviewer`** re-reviews after implementation.
- **Must not:**
  - sign anybody in by address;
  - look up with a string that failed its format;
  - hash inside a sign-in, except R9's one-off repair;
  - create an account without `staff_address_conflict()`;
  - refuse a shared address anywhere else;
  - use `email_value()` to decide whether to send;
  - let staff write another person's username;
  - send a mail from the runner;
  - edit a shipped migration;
  - show a username or another family's name to a family;
  - record `password_hash`, `auth_version` or `last_seen_at` in the change log for any
    operation.

## In plain words, for the owner

- **Signing in.** Everybody will sign in with a username instead of an email address, such as
  `lena.mueller`. It is made automatically from the first and last name; if two people have
  the same name, the second gets a 2 on the end. Each person can change their own under
  „Mein Konto".
- **Shared addresses.** Brothers and sisters can all use their parent's email address, each
  with their own login. **Trainers and administrators always keep an address of their own.**
- **Forgetting.** Someone who forgets their username or password types the address and gets
  one email listing every username on it, each with a link to set a new password.
- **The update.** Existing accounts get their usernames automatically when the update is
  installed. As you decided, nobody is emailed about it; the sign-in page tells anyone who
  types an address what to do instead. You approved the three database changes this needs.
- **Shared mailboxes.** Whoever reads a shared mailbox can set a new password for every account
  on it. For two weeks afterwards, the portal tells the account holder each time they sign in,
  and it shows you and the family how many accounts share an address. If a teenager's
  messages with you should stay private from their parents, that child needs an address of
  their own.
- **A risk you accepted.** Because usernames are easy to guess, somebody could lock a child out
  of signing in for a short while by typing wrong passwords on purpose. Nothing is revealed by
  it, and „vergessen" still works.

## Open for the owner

- **[I4] Whether a filled data sheet prints the username**, so that a family takes it home on
  paper. A username is not a secret (§1), and the sheet goes to its holder. This record does
  not decide it. Until she does, the sheet prints none.

The privacy-notice sentence comes to her as a draft from `docs-writer`, for release.
