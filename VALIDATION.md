# Validation

## 0.6.0 — the update path, drilled

Executed against PHP **8.4.19** and MariaDB **10.11.14**, on a real installed
copy rather than the test harness: a database created the way a hosting panel
creates one, the portal installed into it, filled with example data, and then
put through the update path step by step.

- **A real update.** A new migration arrived; `php bin/console.php update`
  switched maintenance mode on, wrote a copy, applied it, compared the row
  counts of all seventeen guarded tables and opened the portal again.
- **The copy is restorable, and was restored.** The dump written before that
  migration was imported into a second, empty database: **39 of 42 tables came
  back byte-identical**, the three that differed being the ones the migration
  itself changed afterwards (the new table, the migration ledger and the
  settings row). Umlauts and the privacy text survived intact.
- **Each refusal was triggered on purpose.** An applied migration edited after
  the fact, an older release put back over a newer database, a copy that could
  not be written: each one stopped the update, left the portal closed, and
  recorded nothing as applied. `storage/skip-backup` let one through and was
  consumed.
- **The row-count guard was proven by breaking something.** A migration that
  deletes every attendance row was written and run: the update stopped with
  "attendance 64 → 0", the portal stayed closed, and the copy taken moments
  earlier had all 64 rows. Before this release, `attendance` was not on the
  guarded list and the same migration passed silently.
- **Two updates at once.** Two processes ran the migration simultaneously: one
  applied it, the other waited on the advisory lock and found nothing left to
  do. The table was created once and recorded once.
- **The whole suite** passes on both engines: **1915 assertions on SQLite, 1931
  against MariaDB 10.11.14**, with all fourteen migrations applying there.

**Not covered here.** No PDF was opened in Adobe Reader, no mail was sent
through a real provider, and nothing was rendered on a real iPhone;
[TESTING.md](TESTING.md) is the list of checks that exist for exactly that
reason. **MySQL 8.0 itself remains unverified.** No real hosting account has
been used.

## 0.6.0 — the trainer's half of the portal

Executed against PHP **8.4.19** and MariaDB **10.11.14-MariaDB-0ubuntu0.24.04.1**.

- The whole suite passes on both engines: **1621 assertions on SQLite, 1634
  against MariaDB** (the extra ones being the foreign keys and the MySQL-dialect
  backup that the SQLite translation cannot express), with all thirteen
  migrations applying on MariaDB — including `013_standard_contact.sql`, whose
  back-fill updates `contacts` from a derived table because MySQL will not read
  from the table it is updating.
- Each new rule was verified by breaking what it guards and watching the test
  fail: the first contact no longer becoming the standard one, the standard
  contact no longer needing an email address, the last contact becoming
  removable, and the reported-absence note disappearing from the attendance
  screen. Each was restored and re-run green.
- The invoice PDF was parsed with an **independent** library (pypdf) rather than
  with the code that wrote it: one page, readable metadata, and the text
  extracted with umlauts and the euro sign intact.
- A conversation between two families was checked from five sides — both
  participants, the trainer, the administrator and an unrelated family — through
  the thread, the conversation list, the unread count and the attachment
  download route. Only the participants can reach any of them.

**Not covered here.** No PDF was opened in Adobe Reader, no mail was sent
through a real provider, and nothing was rendered on a real iPhone;
[TESTING.md](TESTING.md) is the list of checks that exist for exactly that
reason. **MySQL 8.0 itself remains unverified** — MariaDB is one of the two
supported engines, not both. No real hosting account has been used.

## 0.5.0 — installing and updating

Executed against PHP **8.4.19** and MariaDB **10.11.14**.

- The whole test suite — **1003 assertions on SQLite, 1016 against MariaDB
  10.11.14** (the extra ones being the dump, whose dialect SQLite cannot run) —
  passes on both, with all six migrations applying on MariaDB.
- The browser installer was driven with real HTTP requests against a real,
  empty MariaDB database created the way a hosting panel creates one:
  a fresh upload redirects to setup; a wrong database password, an unassigned
  database and mismatched account passwords are each reported in words and write
  nothing; the correct details write `config/config.php`, record all six
  migrations, seed the example defaults and create exactly one administrator;
  the portal then serves its sign-in page; and setup afterwards answers 403 to
  both GET and POST, creating no second account and leaving `app_url` untouched.
- A migration file added after installation applied itself on the next page view
  and was recorded, with the encryption key unchanged.
- A migration edited after it ran, and a migration containing a broken statement,
  each left the portal closed with 503. The failing one was not recorded as done,
  and the page named the file and statement without printing the SQL. Removing
  the file reopened the portal.
- Maintenance mode still holds the portal closed, and suppresses the automatic
  migration rather than racing it.
- The distribution ZIP built by `bin/release.sh` (about 540 KB, 306 files) was
  unpacked into a web directory and installed from there, using nothing from the
  source tree: the bundled PHPMailer and BaconQrCode were found, the install
  completed, the administrator signed in through the real form with cookies and
  a CSRF token, the settings screen reported the database version with nothing
  pending, and an expired token was cleaned up by the background worker with no
  cron job configured.
- Every safeguard on the update path was exercised against MariaDB over real
  HTTP: a new migration wrote exactly one backup before it applied; that backup
  imported into a second, empty database **with no errors and every table, row,
  setting, apostrophe, umlaut and backslash intact**; an older release was
  refused and did not mark itself current; a single altered byte in `app/domain.php`
  against the shipped manifest was refused and named; a backup that could not be
  written left the migration unapplied; `storage/skip-backup` let it through once
  and was consumed; and a seventh copy pruned the folder back to five without
  removing the one just written.
- Each new structural rule was verified by breaking what it guards: a directory
  losing its deny file, a new unguarded top-level directory, the rewrite loop
  guard being dropped, an unescaped value on the setup page, a truncated module,
  a trusted `Host` header, a replaced `app_key`, a second administrator, the
  downgrade guard, the row-count check, a manifest that stopped comparing
  contents, a manifest path allowed to leave the release, a `skip-backup` file
  that was not consumed, guessable backup file names, a backup folder moved into
  the web directory, and pruning by name instead of by age.

Not covered: a real hosting account. The layouts, the `.htaccess` rewrite and the
LiteSpeed-specific `litespeed_finish_request()` path have not been exercised on
ecomDATA or any other shared host. **MySQL 8.0 itself remains unverified.**

## 0.1.0

Executed against PHP **8.2.32**, MariaDB **10.11.18**, and PHPMailer **7.1.1** with a disposable database and synthetic test accounts.

### Completed

- All application PHP files passed syntax checks.
- The initial migration ran successfully and a second run left it unchanged.
- **61 application integration assertions** passed: sign-in, invitation verification and replay rejection, private-route protection, account isolation, role checks, CSRF, safe text rendering, duplicate payment prevention, confirmed/partial balances, tariff price preservation, archived field values, stale-form protection, contacts, filters, messaging, subscriptions, suspension and deletion.
- **14 additional assertions** passed: password reset invalidates sessions, email changes require verification, expired invitation rejection, authenticated STARTTLS SMTP sending and queue status, expired-security-mail cancellation, no-reply notice, signed unsubscribe confirmation, maintenance mode and unchanged counts/totals.
- English server-rendered routes returned successfully; German was used for the application workflow tests.
- Composer audit returned no known advisories or abandoned packages for the locked production dependency at the time of testing.

The SMTP test used a local capture server and a dedicated temporary trusted certificate. It checked actual SMTP/TLS interaction without sending messages to real people.

### Still to check on the target hosting

- The browser security policy blocked local visual previews in the build environment. Responsive CSS is implemented, but visual inspection on an actual phone and desktop remains outstanding.
- PHP-FPM/Apache/LiteSpeed/Nginx configuration, HTTPS redirects, session storage, actual document root, and any proxy or caching rules.
- The chosen mail provider's credentials, allowed sender, DNS authentication, inbox delivery and actual cron execution.
- The exact MySQL or MariaDB version used by the target host. MariaDB 10.11 was exercised; MySQL 8 is the intended compatible target but was not independently run in this environment.
- The operator-specific privacy wording and process for minors/health-related absence information.

No public deployment, independent security audit, production load test or live-data migration has been performed. The release is prepared for self-hosted installation and review.
