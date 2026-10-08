---
status: accepted
date: 2026-10-08
---

# 0030. One person, one address: usernames and sign-in links go

> **Amended on 2026-10-08, after the reviews of 039 and of the screens.** Four points, and the
> owner's answer to the question in Consequences:
>
> 1. **§2: 039 turns suspended username logins into placeholders too**, not only invited and active
>    ones: its `UPDATE` reads `state<>'placeholder'`. „Gesperrt" becomes „Ohne Anmeldung". That
>    widens nobody's rights: a trainer can already restore a student's login.
> 2. **§2, decided by the project manager: 039 also takes a converted login out of its chats and
>    empties its bell.** Three statements between the `UPDATE` and the `ALTER` delete the converted
>    logins' rows in `thread_participants`, `thread_reads` and `notifications`; each must be able to
>    run twice, because only the `ALTER` may not. Their exact condition is `database-engineer`'s,
>    and this note quotes it once it is reported. The reason is this record's own rule, "a row that
>    lies about itself": a placeholder holds nothing a fresh placeholder could not, read marks
>    included. Otherwise any member of staff could invite an address into a converted login, and
>    its new holder would read and write the child's old chats. The threads and their messages stay,
>    for the other side. None of the three tables is guarded, and in the beta these are test chats.
>    Rejected with it: refusing `invite_student()` for staff while a placeholder still takes part in
>    a conversation, which is permanent code for a state 039 can simply not leave behind.
> 3. **§8 and Tests 6 name three chips; the code has four:** „Alle", „Eingeladen", „Ohne
>    Anmeldung" and „Gesperrt". „Eingeladen" is the project manager's decision, recorded in the
>    addendum to `docs/design/2026-10-05-accounts-and-chat-screens.md`. This note brings the record
>    in line with the code.
> 4. **Consequences, for `docs-writer`:** UPDATING says that suspended username logins become „Ohne
>    Anmeldung" too, and that a converted login's side of its chats goes. None of 039's conversions
>    shows in „Änderungen": a migration writes no line there.
>
> **The owner's answer, 2026-10-08:** "Yes, invite later". A child may exist without an address, as
> „Ohne Anmeldung", and is invited once the address is known. §3 stands as written, and the question
> in Consequences and "In plain words" is answered.

> **Accepted** on 2026-10-08. The owner, verbatim: "Drop once again the username support, mainly
> email login support / 1 admin 1 email / 1 person 1 email / 1 trainer 1 email / 1 student 1 email".
> Decided by the architect at the project manager's request, at `78799a0`. The reading of the words
> is the project manager's, confirmed below; what this record decides beyond them is marked as its
> own. It supersedes ADR 0019 for good and the username and sign-in-link halves of ADR 0023, amends
> 0010, 0020, 0021 and 0023, and leaves 0020's rule, every login has its own address, as the whole
> rule. One migration, 039. No new file, no setting, no dependency.

## Context

The history, in one paragraph. 0019 (2026-09-29) added usernames and let siblings share an
address. 0020 (2026-09-30) kept the usernames and gave every login its own address. 0021
(2026-10-01) removed the usernames ("reverse the username support"), with 024 dropping the column.
0023 (2026-10-06) brought them back for a student without an address, with 028, and added one-time
sign-in links so that such a login could be set up without a mailbox. Today the owner reverses that
again, for every role.

What the code does at `78799a0`, read, not assumed:

- **The column.** `accounts.username VARCHAR(30) NULL`, unique index `account_username` (028).
  `accounts.email` is nullable since 028, for the placeholder.
- **States and badges.** `placeholder`, `invited`, `active`, `suspended`. `placeholder_login()`
  writes `NULL` for both the address and the username. `login_state_badge()` says „Eingeladen" for
  `invited` with an address, „Noch nicht angemeldet" for `invited` with a username
  (`username_login_waiting()`), „Ohne Anmeldung" for a placeholder.
- **Sign-in.** One box, „E-Mail oder Benutzername". `attempted_sign_in()` decides by the `@`;
  `account_for_sign_in($kind, $value, $lock)` sends one of two literal statements; the throttle has
  a bucket per kind (`sign_in_identity()`, `address_identity()`, `username_identity()`), and
  `forget_attempts_after_success()` clears both.
- **Sign-in links.** `auth_tokens` rows with `purpose='signin'`: no table or column of their own.
  `make_signin_link()`, `signin_link_possible()`, `username_login_waiting()`, `signin_links_for()`,
  `signin_link_shown()` and `forget_signin_link()` with `$_SESSION['signin_links']` (`app/auth.php`),
  `may_create_signin_link()` (`app/shell.php`), `give_student_username()`, `username_to_give()` and
  the `signin_link` case (`app/actions.php`), the `signin` branch of `activate`, the audit kinds
  `account.signin_link`, `account.signin_link_used` and `account.signin_link_withdrawn`, the
  throttle `signin-link`, `views/_signin_link.php` with the QR code, „Teilen" (`navigator.share()`
  in `app.js`) and „Link kopieren". `token_lifetime()` gives `invite` and `signin` 48 hours and the
  rest an hour; it is a function, not a setting.
- **The wizard.** Step 2 has three cards, `method` `email`, `username` or `none` (`student_create`).
- **The username functions.** In `app/core.php`: `USERNAME_PATTERN`, `USERNAME_LETTERS`,
  `username_transliterated()`, `username_normalised()`, `username_value()`, `username_from_name()`,
  `username_first_free()`. In `app/auth.php`: `usernames_taken_near()`, `username_for_new_account()`,
  `username_suggested()`. `token_record()` selects `a.username` for the page a link opens.
- **"username" names other things too.** The SMTP user (the `smtp` setting's `username`,
  `app/mail.php`, `app/actions_settings.php`, `views/settings.php`); the database user
  (`config/config.php`, read by `connect()` in `app/core.php`, written by `app/install.php` and
  `public/setup.php`, printed by `bin/console.php`); the HTML token `autocomplete="username"` on the
  sign-in and activation boxes, which tells a phone which field the password belongs to (0021 §1);
  and the change log's label for lines written before 024 (`history_field_label()`, 0021).
- **Counts.** 757 occurrences of `username` or `signin` across 47 files, 15 test suites among them.
- **In flight.** `frontend-dev`'s screens for 0023 (`scratchpad/review/frontend-0023.diff`), built
  and reviewed, not committed.

## Decision

### 1. The address is the only name a login has, for everybody

0021 §1 holds again in full, for every role:

- one box, „E-Mail-Adresse", with `autocomplete="username"` as before;
- `account_for_sign_in(string $email, bool $lock = false)`: the dot-atom gate, one literal statement,
  the exact match after normalising both sides, one `password_verify()` on every path and one
  refusal text;
- the throttle counts the typed address, one bucket per login, and `forget_attempts_after_success()`
  clears that one;
- „vergessen" takes the address and mails the stored one. Its sentence about a login without an
  address getting a new link from the trainer goes.

`attempted_sign_in()`, `username_identity()` and the username halves go. Staff were never
different; the owner's words name them so that nobody asks.

### 2. Migration 039: the username column goes, and a login that signed in by one becomes a placeholder

`039_sign_in_by_address_only_again.sql`, two statements:

```sql
UPDATE accounts SET state='placeholder', password_hash=NULL, verified_at=NULL WHERE email IS NULL AND state<>'placeholder';
ALTER TABLE accounts DROP INDEX account_username, DROP COLUMN username;
```

- **The `UPDATE` first**, because it can run twice; the `ALTER` last, one statement that cannot, as
  024 was (0021 §1) and as 025 is built (0022 §10). A run that stops between them starts again at
  the `UPDATE`, which then changes nothing.
- **A login with no address** is, from now on, exactly what a placeholder is: a student's login
  that cannot sign in until staff give it an address. Today such a login is `invited` or `active`
  by a username. Its password went with the username that set it, and `verified_at` means "set up
  by its holder" (0023 §3), which `login_goes_with_student()` and the badges read; both are cleared
  so that the row says what it is. Its sessions end, because `current_user()` keeps only `active`.
  In the beta these rows are test data.
- **Rows stay.** `accounts` is guarded, and every login is some student's (RESTRICT, 0023 §4).
  `email` stays nullable: a placeholder needs it.
- **Sign-in links need no statement.** `link_usable()` names `invite`, `reset` and `email` only from
  this release, so no `signin` row can be used; the nightly prune removes them as they lapse, within
  48 hours.
- 028 stays as shipped. The engine: MariaDB 10.11.14, as for every file; MySQL 8.0 unverified, and
  the index and the column dropped in one `ALTER` is what 024 did.

### 3. The placeholder stays: a student's login before it has an address

*The architect's call.* The `placeholder` state (0023 §3) stays, meaning "no address yet":

- **„Every student has a login" rests on it** (0023 §4): `placeholder_login()` and the RESTRICT key.
  The update step, `give_every_student_a_login()`, cannot give an address to a student who has none.
- **An invitation needs the set-up done**: `account_mail_ready()`, that is SMTP tested and the
  privacy notice released. Without the placeholder nobody could add a child before that, and the
  start checklist invites children after they exist.
- **The owner asked for it** on 2026-10-05: "it should also be possible that she completes the
  registration herself and is a dummy account". Today's words are about signing in, and a
  placeholder never signs in.

What changes: a placeholder becomes a login one way only, `invite_student()`, which writes the
address and sends the invitation; `give_student_username()` goes. The states are `placeholder`,
`invited`, `active` and `suspended`, the badges „Ohne Anmeldung", „Eingeladen" and the states'
own; „Noch nicht angemeldet" goes with `username_login_waiting()`.
`replace_login_with_placeholder()` stays for „Einladung zurückziehen" and „Anmeldung löschen"
(0023 §4, as corrected on 2026-10-07); „Benutzernamen zurückziehen" goes.

### 4. One-time sign-in links go

The project manager's recommendation, confirmed: they existed for a login with no mailbox, as
0023 §6 says, "the way back from a forgotten password without a mailbox", and a placeholder's first
sign-in. Every login that signs in now has an address, so the invitation and the reset mail cover
every way in: a child not yet set up gets the invitation, or gets it again; a child who forgot the
password uses „vergessen", or staff send a reset link (`reset_link_possible()`). One way in, less to
keep, and the key carried in a chat that 0023 accepted ends with it. A trainer in the hall types the
address into the wizard, and the invitation is on the parent's phone after the next background run,
a minute after a page view, or the cron job.

What goes: the `signin_link` case; `make_signin_link()`, `signin_link_possible()`,
`may_create_signin_link()`, `signin_links_for()`, `signin_link_shown()`, `forget_signin_link()` and
`$_SESSION['signin_links']`; `username_to_give()` and `refuse_signin_link_until_privacy_released`;
`signin` in `token_lifetime()`, `link_usable()`, `token_record()`'s username column and `activate`;
the throttle `signin-link`; `views/_signin_link.php`; „Teilen" and its `[data-share]` block in
`app.js`, with `tests/topbar-menus.mjs`'s share checks; the `structure` suite's privileged-action
entry for `signin_link`; `student_logins()`'s `link_expires_at` reads `invite` alone; Mein Konto's
list of passwords set through a link keeps `password_resets_for()` only.

What stays: `token_lifetime()` and `token_lifetime_words()`, with 48 hours for an invitation and an
hour for the rest; the invitation's flow unchanged; the audit log's old `account.signin_link*`
entries, readable as every entry is; `qr_svg()` and `bacon/bacon-qr-code`, for the payment code,
which is what they were there for before 0023.

### 5. "1 person 1 email" means one own address per login, not one address per family

Already the rule: `refuse_address_in_use()` is the one refusal of an address, before every write of
`accounts.email` (0020 §1), and two students never share one. A parent with two children keeps two
logins with two addresses (0010, 0020 §1); the parent's own address goes on the children's emergency
contacts. Said here so that nobody reads the owner's words as sharing: 0019's shared addresses stay
superseded.

### 6. The wizard and the access card

- **Step 1** is unchanged. **Step 2** has two cards: **(a)** „Per E-Mail einladen", „Empfohlen", with
  the address and the invitation's language, offered while `account_mail_ready()`; **(b)** „Ohne
  Anmeldung anlegen". `student_create`'s `method` is `email` or `none`; `choose()` refuses the
  rest. Whether the address becomes an optional box on step 1 instead is `ui-ux-designer`'s to
  decide; the rules do not move: every refusal before the first write (`invitation_address()`,
  `refuse_address_on_student_without_sign_in()`, `account_mail_ready()`), one transaction, the draft
  in the session (0023 §5).
- **The done page:** (a) says where the invitation went; (b) points to the access card. Its lines
  that offered a username or a link go.
- **The access card:** „Ohne Anmeldung": the address and „Einladung senden", as 0021 had it;
  „Eingeladen": „Einladung erneut senden" and „Einladung zurückziehen"; in use: „Link zum
  Zurücksetzen senden", „Zugang sperren" and „Anmeldung löschen", confirmed by typing the address.
- **Mein Konto:** the address, „E-Mail-Adresse ändern", the password, the mail switches for
  everybody who reaches the page, because everybody who does has an address.

### 7. Where "username" may still appear

The word stays in four places, each for something that is not a login's name, and nowhere else in
`app/`, `views/`, `public/` or `bin/`:

1. `autocomplete="username"` on the sign-in and activation boxes: the HTML token for the field a
   password belongs to;
2. the SMTP user: the `smtp` setting's key, `app/mail.php`, `app/actions_settings.php` and
   `views/settings.php`;
3. the database user: `config/config.php`'s key, read by `connect()`, written by setup and the
   installer, named by `bin/console.php`;
4. `history_field_label()`'s label „Benutzername" for change-log lines written before 024.

Migration comments and the records stay as written. The `structure` suite holds the list (Tests, 8).

### 8. The screens batch in flight

`frontend-dev`'s batch for 0023 (`scratchpad/review/frontend-0023.diff`) is trimmed, not dropped.

**Stands:** Zugänge's groups „Trainer", „Administratoren", „Schüler", „Einladungen ohne Namen" and
„Zugänge ohne Schüler", the team rows that fold open to their actions, a student's row leading to the
access card with the address, the chips „Alle", „Ohne Anmeldung" and „Gesperrt" with their counts
and the paging; `login_state_badge()` without its username branch; the renames to „Zugänge" in
`views/layout.php`, `views/manage.php` and `views/settings.php`; the impersonation bar on the public
pages (`views/layout.php`); `token_lifetime_words()` in `views/activate.php`, `views/student.php` and
`views/students.php` for the invitation and the reset; the wizard's „made" step and its „Die Angaben
waren nicht mehr da" handling; the `.account-group`, `.member-row`, `.next-steps` and `.text-links`
CSS; the `.qr-box`/`.qr-plate` rename, which the payment code uses; the mail switches on Mein Konto,
now shown always.

**Goes:** `views/_signin_link.php` and everything of it; the `[data-share]` block in `app.js` and the
share checks in `tests/topbar-menus.mjs` (the shell suite's count goes back); `sign_in_name_label()`
and the username row of `login_facts()`; the username branch of `login_delete_details()`;
`signin_link_details()` and `.action-fold.is-primary`; `mail_not_ready_notice()`'s `$missing` and
`$title` parameters, whose only caller was the link card; the wizard's username card and its privacy
variant; „oder deinem Benutzernamen" in `views/activate.php` and `tests/suites/pages.php`; Mein
Konto's username sentences, „E-Mail-Adresse hinzufügen" and the hidden-field branch for a login
without an address; on the access card the `waiting` state, the username fold, „Benutzernamen
zurückziehen", the link rows and „Link zurückziehen"; the chip „Noch nicht angemeldet" and „Link
gilt bis" for a sign-in link; the logins suite's username and link cases.

## Rejected

- **Keeping usernames for students only, discouraged, as 0023 §1 had it.** The owner's words name
  every role, and "drop once again the username support" names the feature.
- **Keeping sign-in links as the way in without a mailbox.** No login that signs in is without one
  any more. As a second way back from a forgotten password they would be a reset link that staff
  carry into a chat, for a case the mailed reset covers.
- **Removing the placeholder state.** It carries "every student has a login" (0023 §4), it lets a
  child be added before SMTP is set up, and the owner asked for it. If the owner meant that no
  student may exist without an address, that is their call to make, and it costs exactly that:
  no child before the mail set-up is done. The project manager asks (Consequences).
- **Deleting the username logins' rows in 039.** `accounts` is guarded, and every one of them is a
  student's login behind a RESTRICT key. They become placeholders, which is what they are.
- **Leaving `password_hash` and `verified_at` on a login turned placeholder.** A placeholder with a
  password it cannot use, and a `verified_at` that `login_goes_with_student()` reads as "set up", is
  a row that lies about itself.
- **Keeping `accounts.username` in place, unused.** A column nothing reads is a column somebody will
  read, and 024 already set the precedent of dropping it.
- **A statement deleting the `signin` rows of `auth_tokens`.** Nothing accepts one, and the prune
  removes them within two days. A statement for that is one more thing to test.
- **Keeping `views/_signin_link.php`, the share code and the username functions "in case".** Dead
  code; git remembers it (`CLAUDE.md`). 0021 §1 removed them once already and 0023 brought them back
  in two days; a third time would be as cheap.
- **Mailing the former username logins about the change.** They have no address to mail.
- **Checking "username appears nowhere" with a bare search.** The SMTP user, the database user and
  the HTML token would fail it. The rule names its exceptions (§7), so the next one has to be argued.

## Consequences

- **Records.**
  - 0019 stays `superseded by 0021`; its note gains a line: the functions 0023 reused go for good.
  - 0023 becomes `accepted, amended by 0026, 0030`, with a note naming what no longer holds: §1,
    §6 and §7 whole; §2's reasoning, while 028 stays as shipped and 039 undoes it; in §3 the
    username in the table, the badge „Noch nicht angemeldet" and `give_student_username()`; in §5
    card (b) and the done page's link card; in §8 the sign-in name's username and the link's date;
    in §11 the username functions, `signin_link_possible()`, `signin_links_for()`,
    `may_create_signin_link()`, `give_student_username()`, the `signin_link` case and
    `views/_signin_link.php`; in §12 rows A1, A4, A5, A8, the PM rows on links and the spec rows on
    folds; Rejected's entries about links and usernames; in Consequences the three added actions
    (two remain), the `qa-tester`, `mobile-tester`, `security-reviewer` and `docs-writer` items
    about usernames and links, and "Must stay true" on `accounts.username` and sign-in links; "For
    the owner" 2; in "In plain words" the second way to sign in and the link.
  - 0021 becomes `accepted, amended by 0023, 0026, 0030`: the username parts of its 0023 note no
    longer hold, and its §1 holds in full again. 0020 becomes `accepted, amended by 0021, 0023,
    0026, 0030`: its §3 and §4 hold for the address alone again, as its 0021 note says. 0010 gains
    a line: "an address or a username" reads "an address".
  - `docs/decisions/README.md`: the rows of 0010, 0019, 0020, 0021 and 0023, and this record's.
- **Actions.** One fewer, `signin_link`. **Pages:** none. **Settings:** none. **Dependencies:** none
  added, none removed; `bacon/bacon-qr-code` stays for the payment code.
- **Schema.** 039. 028 is never edited.
- **Load order.** Unchanged: functions go, none moves, no file is added or removed but
  `views/_signin_link.php`, which is a view.
- **The owner is told**, by the project manager: usernames are gone again; a child without an
  address is „Ohne Anmeldung" until the address is known, and gets the invitation from the access
  card; sign-in links are gone, the invitation and „vergessen" are the ways in; and whether a child
  may exist without an address at all (Rejected, "Removing the placeholder state").
- **`ui-ux-designer`** revises `docs/design/2026-10-05-accounts-and-chat-screens.md`: §2 with two
  cards, §3 and §4 removed, §5 with one box for the address, §6 without usernames.
- **`database-engineer`:** 039; `tests/migration-data.php` as Tests 2 says; the run on MariaDB
  10.11.14.
- **`backend-dev`:** §1, §3, §4 and §6 in `app/`, the step in `database/defaults.php` unchanged;
  `app/demo.php` unchanged (every example login has an address).
- **`frontend-dev`:** the batch as §8 trims it, then the views in §6.
- **`qa-tester`:** Tests; TESTING.md loses the walks for usernames and links.
- **`security-reviewer`:** the sign-in path, back to 0021 §1's rules; that no readable link is kept
  in any session; `activate` without its `signin` branch.
- **`docs-writer`:** the privacy drafts (line 7's "oder einen Benutzernamen" / "or a username" out,
  and 0023's sentences on usernames, sign-in links sent through a messenger and who made a link);
  README, INSTALL, UPDATING (039: a login that signed in by a username is „Ohne Anmeldung" afterwards
  and needs an address and an invitation), CHANGELOG, VALIDATION.md (S3 for usernames and the
  sign-in link's accepted risk out), TESTING.md with `qa-tester`.
- **Must stay true:**
  - every login that can sign in has an address, and signs in with it alone;
  - a placeholder is the only login without an address, and it never signs in;
  - every write of `accounts.email` asks `refuse_address_in_use()` first, and no two logins share
    one;
  - `account_for_sign_in()` sends one literal statement, after the format gate, and the throttle
    counts the typed address;
  - `link_usable()` names `invite`, `reset` and `email`, and nothing writes an `auth_tokens` row
    with another purpose;
  - the word `username` appears in `app/`, `views/`, `public/` and `bin/` only in the four places of
    §7;
  - every student has a login (0023 §4), and only `invite_student()` turns a placeholder into one;
  - 028 and 039 are never edited.

### Tests

Each rule is broken once on purpose and seen to fail; then the whole suite, `tests/mariadb-local.sh`.

1. **Sign-in by address alone.** The address signs in, `LENA@Beispiel.AT` as `lena@beispiel.at`; a
   value without `@` reaches no `SELECT`, runs one `password_verify()` against the dummy hash and
   gets the one refusal; the throttle locks a known and an unknown address alike at the eleventh try;
   success clears one bucket. *Break:* let `account_for_sign_in()` look a username up.
2. **039, with data in place** (`tests/migration-data.php`): before it, an invited username login,
   an active username login with a hash and `verified_at`, an invited address login, a placeholder
   and the staff. After it: the column and `account_username` are gone (`information_schema`); the
   two username logins are placeholders with `NULL` for the hash and `verified_at`; every other
   login is unchanged byte for byte; the count of `accounts` is unchanged; run again from the
   `UPDATE` after a stop, it changes nothing; a second insert without the column works.
   *Break:* drop the `UPDATE`. The active username login stays `active` with no way in.
3. **A sign-in link from before the update signs nobody in.** A `signin` row left in place: the GET
   shows „Link nicht mehr gültig", the POST is refused, nothing is written; the prune removes it
   once lapsed. *Break:* leave `signin` in `link_usable()`.
4. **The wizard.** `method=username` is refused by `choose()` and writes nothing; step 2 renders two
   cards and no `name="username"`; (a) refuses before the first write for an invalid address, one
   in use, one a student without sign-in carries, and mail not ready; (b) makes a placeholder.
5. **The access card and Mein Konto.** A placeholder's card offers the address and „Einladung
   senden" and no link fold; Mein Konto shows the address, „E-Mail-Adresse ändern" and the three
   mail switches.
6. **Zugänge.** The chips are „Alle", „Ohne Anmeldung" and „Gesperrt"; a row shows the address; no
   „Noch nicht angemeldet" anywhere.
7. **Gone.** `signin_link` is an unknown action; none of the functions §4 and Context name is defined
   anywhere; `views/_signin_link.php` does not exist; `app.js` has no `navigator.share`.
8. **`structure`.** `username` appears in `app/`, `views/`, `public/` and `bin/` only in §7's four
   places, each named by file and form; `accounts.username` is read or written nowhere;
   `account_for_sign_in()` holds one literal `SELECT * FROM accounts WHERE email=?`; `'signin'`
   appears nowhere in `app/`. *Break:* add `username` to any `SELECT` in `app/auth.php`.
9. **Documents.** No `Benutzername` in the privacy drafts but none; no "there are no usernames" claim
   is wrong anywhere (0023 asked for the reverse check; this is its mirror).
10. **The demo suite** passes unchanged: every example login has an address.

## In plain words, for the owner

- Everybody signs in with their own e-mail address and their password, as you asked. Usernames are
  gone, and so are the sign-in links with the QR code: the invitation by e-mail and „Passwort
  vergessen" are the two ways in.
- One person, one address, one login. A parent with two children has two logins with two addresses;
  the parent's own address goes on the children's emergency contacts.
- A child whose address you do not know yet can still be added: the child is „Ohne Anmeldung" until
  you enter the address on the child's page and send the invitation. If you would rather that no
  child exists without an address, say so: then nobody can be added before the e-mail set-up is
  done.
- After the update, a test login that signed in with a username is „Ohne Anmeldung" and needs an
  address and an invitation. Real families are not affected: there are none yet.
- The update needs nothing from you.
