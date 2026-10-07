# Validation

What was verified, on what, and what was not, newest first. A figure in a record was run
and watched by whoever wrote the record, unless the record says it was reported. The
records from before the suite ran on MariaDB only — until commit `f82289c` on 2026-10-01,
when it also ran on a SQLite translation — are in git history; they describe code that has
changed since.

## 0.6.0, unreleased — at `bfeb592` and `21f02c8`: a refused update stays refused, and any value from anyone

Recorded 2026-10-07 by docs-writer. Both runs on MariaDB 10.11.14
(`10.11.14-MariaDB-0ubuntu0.24.04.1`) with PHP 8.4.26, started by `tests/mariadb-local.sh`
with a work folder of its own on port 3431, each on a copy exported with `git archive` and
`vendor/` copied in, so the `structure` suite counts one check fewer than in a checkout.
MySQL 8.0 was not run.

- **At `bfeb592`, ADR 0027 built: 6835 passed, 0 failed**, in 208 seconds. The commit
  reports 6836 from its own run in a checkout, which is the one check for `.git/`. The run
  listed one thing as not covered: *a skip-backup and a record the portal cannot delete
  (this run is root, whom a read-only folder does not stop)*. So the two refusals for a
  file the portal cannot delete — a `skip-backup` left in `storage`, and
  `update-unfinished.json` after a run that passed — have not run here; they were read in
  `app/schema.php`, not watched.
- **What it fixes was watched before the fix.** On a copy of `c77348b`, a migration that
  deleted every `news` row was refused on the first `schema_apply()`, and the second, with
  nothing left pending, opened the portal with the rows still missing (a scratch check of
  ten assertions; with the deletion taken out, five of them failed).
- **At `21f02c8`, the robustness batch: 7608 passed, 0 failed**, in 250 seconds, the
  `robustness` suite among them with 751 passed; on its own it passed 751 in 41 seconds.
  The commit message reports 7452 passed for its run; the difference is not explained
  here. The run listed as not covered: the same file the portal cannot delete; actions no
  page draws a form for (`login`, `activate`, `unsubscribe`, `student_invite`,
  `attendance_clear`, `contact_decide`, `setup_visibility`), which get every value but no
  form sent twice; a form sent twice where the form as drawn is refused (28 actions and
  roles, `avatar_save` as a family first); and what an action does with an uploaded file,
  because only a real upload passes `is_uploaded_file()`.

Reported and **not** reproduced here: `tests/e2e.sh` passed for each commit; each of ADR
0027's thirteen tests and each new robustness guard was broken once and watched to fail.
Not walked by anybody yet: TESTING.md G.1–G.9, the refused update and its restore with
phpMyAdmin on a test install, which is the only check of the import as a hosting panel
does it — the suite drops every table and replays the copy statement by statement.

## 0.6.0, unreleased — at `d095ca4`: every student has a login, a wizard, sign-in links

Recorded 2026-10-07 by docs-writer.

- **The whole suite: 6702 passed, 0 failed**, in 191 seconds, on MariaDB 10.11.14
  (`10.11.14-MariaDB-0ubuntu0.24.04.1`) with PHP 8.4.26, started by
  `tests/mariadb-local.sh` with a work folder and port of its own, on a copy exported with
  `git archive d095ca4` and `vendor/` copied in. All thirty-one migrations apply. The run
  listed nothing as not covered: the data the migrations carry across was checked on the
  second, empty `_test` database, and the top-bar menus under `node`. The same run on a
  copy of the working tree with this change's documents — every suite seeds its database
  from the privacy drafts — gave 6702 passed, 0 failed too.
- **In a git checkout the same commit gives 6703.** The `structure` suite checks that every
  top-level folder is refused over the web, `.git/` included, and an exported copy has no
  `.git/`: 936 passed in the checkout, 935 in the copy, both watched.
- **The browser walk, `tests/e2e.sh`, on the working tree at `d095ca4`** (no code changed,
  documents edited): `RESULT: PASS`, 372 checks, 0 failed, in 122 seconds, against MariaDB
  10.11.14 under `php -S` with PHP 8.4.26, in Chromium at 390px, with a 320px pass over
  every page each role opened: 39 for the administrator, 6 for the family, 3 for the person
  invited by address alone. No PHP warning, notice or deprecation; no JavaScript error,
  failed request or unexpected 4xx/5xx; no layout failure; four 503s, all provoked on
  purpose; four mails captured — the SMTP test and three invitations, one of them in
  English. The children were added through the wizard. The invoice's Leistungszeitraum and
  the family's charge card both read „04.10.2026 – 31.10.2026".

Reported and **not** reproduced here: the project manager's walk with `CRM_E2E_REF=d095ca4`
passed, with no PHP warning, no console error, no layout problem and only the four
provoked 503s; backend-dev's whole-suite run in the checkout gave 6703 passed, 0 failed,
and each fix in `d095ca4` was reverted once and watched to fail.

**Accepted risks**, from the security review of ADR 0023 (reported by the project
manager; how each comes about was read in the code).

- **A login with two names has two counts.** A login that has both a username and an
  address can be guessed at under each: ten attempts a quarter of an hour under the
  username and ten under the address, twenty against one password, still inside the sixty
  a quarter of an hour allowed from one internet connection. The count follows what was
  typed, never the login it names (`sign_in_identity()`, `app/actions.php`), which is what
  keeps the form from telling anybody whether a login exists.
- **S3: somebody else can lock a username.** Ten wrong passwords for `lena.hofer` block
  signing in as `lena.hofer` for a quarter of an hour, whoever typed them — accepted by the
  owner under ADR 0019 and again in ADR 0023 §7. `lena.hofer` and `lena-hofer` are two
  different usernames that can both exist.
- **A sign-in link is a key.** Whoever holds it can sign in once within 48 hours and choose
  the password, and staff share it through a messenger by design. The card warns not to
  send it to a group; it can be withdrawn; who made it, and when it was used, is kept.
  Until it is used, withdrawn, replaced or lapsed, the readable link is held in its maker's
  session on the server, where whoever can read the session store could already take over
  sessions (ADR 0023 §6).

**Known limit.** A logo photo that a phone stored sideways, with a note telling the viewer
to turn it (EXIF orientation), is measured as stored and can be refused as too tall.
Saving it again from an image editor, or as a screenshot, fixes it. TESTING.md says so
under 6.20; ROADMAP.md has it in the security batch.

**Not covered here.** **MySQL 8.0 has never been run.** The phone sweep,
`tests/mobile.mjs`, has not been run on this change. The browser walk is Chromium with an
iPhone's size and user agent, not Safari on an iPhone; its mail server is a sink on the
same machine, not a real provider; its invoice PDF was read by a parser, not opened in a
reader; and `php -S` reads no `.htaccess`, so the rules there are checked only by the
`structure` suite reading the file. The deletions after 30 days are tested by ageing the
stored dates; nobody has waited. No real hosting account has been used.

## 0.6.0 — runs reported since the suite became MariaDB-only

From the commit messages; none was reproduced for this file. All on MariaDB 10.11.14 with
PHP 8.4.26.

- `6a5cfc6`, signing in by address and the chat: whole suite 5882 passed, 0 failed; the
  browser walk passed, 361 checks. Neither run opens the chat; its checks by hand are C.1
  to C.20 in TESTING.md.
- `cb164a8`: whole suite 5911 passed, 0 failed, and the browser walk passed (project
  manager).
- `d38713f`, the billing review: whole suite 6256 passed, 0 failed (backend-dev).
- `f70f8ce`, setup and https: whole suite 6281 passed, 0 failed, and the browser walk passed
  (devops-engineer); the redirect to https was also checked with real requests. Not
  verified: nginx itself, and a real Apache or LiteSpeed behind a proxy.
- `9a71675`, the invoices page: whole suite 6283 passed, 0 failed, and the screens swept at
  320, 390 and 1280 pixels in light and dark, with nothing to fix below 761 pixels
  (frontend-dev). This is the most recent sweep at phone width on record; the wizard and
  the sign-in screens of `e9aff6e` came after it, and only the browser walk's 320px pass has
  looked at them since.

## The install and update paths, drilled on MariaDB

The most recent drills of their kind, from September 2026. The code has changed since —
`setup.php` and the migration runner in `f70f8ce` — and neither drill has been repeated.

**Updating** (0.6.0 work in progress), on PHP 8.4.19 and MariaDB 10.11.14, on a real
installed copy rather than the test harness: a database created the way a hosting panel
creates one, the portal installed into it, filled with example data, then put through the
update path step by step.

- **A real update.** A new migration arrived; `php bin/console.php update` switched
  maintenance mode on, wrote a copy, applied it, compared the row counts of the guarded
  tables and opened the portal again.
- **The copy is restorable, and was restored.** The dump written before that migration was
  imported into a second, empty database: 39 of 42 tables came back byte-identical, the
  three that differed being the ones the migration itself changed afterwards. Umlauts and
  the privacy text survived intact.
- **Each refusal was triggered on purpose.** An applied migration edited after the fact, an
  older release put back over a newer database, a copy that could not be written: each one
  stopped the update, left the portal closed, and recorded nothing as applied.
  `storage/skip-backup` let one through and was consumed.
- **The row-count guard was proven by breaking something.** A migration that deletes every
  attendance row stopped the update with "attendance 64 → 0", the portal stayed closed, and
  the copy taken moments earlier had all 64 rows.
- **Two updates at once.** Two processes ran the migration simultaneously: one applied it,
  the other waited on the advisory lock and found nothing left to do.

**Installing** (0.5.0), on PHP 8.4.19 and MariaDB 10.11.14, with real HTTP requests against
an empty database created the way a hosting panel creates one.

- A fresh upload redirects to setup. A wrong database password, an unassigned database and
  mismatched account passwords are each reported in words and write nothing. The correct
  details write `config/config.php`, record the migrations, seed the defaults and create
  exactly one administrator; setup afterwards answers 403 and creates nothing.
- A migration added after installation applied itself on the next page view. One edited
  after it ran, and one with a broken statement, each left the portal closed with 503; the
  failing one was not recorded, and the page named the file and the statement without
  printing the SQL.
- The ZIP built by `bin/release.sh` was unpacked into a web directory and installed from
  there, using nothing from the source tree: the bundled PHPMailer and BaconQrCode were
  found, the administrator signed in through the real form, and an expired token was
  cleaned up by the background work with no cron job.
- A backup written before a migration imported into a second, empty database with every
  table, row, setting, apostrophe, umlaut and backslash intact; a single altered byte in
  `app/domain.php` against the shipped manifest was refused and named; a seventh copy pruned
  the folder back to five without removing the one just written.

Not covered by either: a real hosting account. The `.htaccess` rewrite and LiteSpeed's
`litespeed_finish_request()` path have not run on any shared host.

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
