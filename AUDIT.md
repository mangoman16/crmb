# Review of v0.1.0, and what 0.2.0 to 0.4.0 added

Bug, security and design review of the v0.1.0 source, and what was changed in
response. Every line of `app/`, `views/`, `public/`, `bin/` and
`database/` was read. Findings are grouped by what they mean for the operator,
not by file.

Verification available in this environment is stated honestly per finding. No
MySQL or MariaDB server could be installed here, so nothing was exercised
against the real database engine. What *was* exercised: the pure helper
functions directly, and the real templates rendered by the real PHP against a
SQLite translation of the schema, driven in Chromium at six viewport widths in
both colour schemes.

## Summary

The code that was there is better than its size suggests. Every query is
parameterised, every template output goes through `e()`, the session and cookie
setup is sensible, the CSP has no `unsafe-inline`, secrets are sealed with
AES-256-GCM, and the concurrency thinking is real — row locks before decisions,
an advisory lock around the mail worker, optimistic revision checks on student
edits. I found **no SQL injection, no XSS and no broken access control**. The
authorisation helpers (`student()`, `thread_record()`) scope by account
consistently, and every action calls one.

What I did find: one security control that silently did not work, a date bug
that displayed the wrong day, a performance problem that grows with the number
of students, a queue that gave up permanently on a temporary failure, and an
interface with no dark mode on a device family that has had system-wide dark
mode since 2019.

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | Rate limits refunded their own counters on failure | High | Fixed |
| 2 | Timestamps displayed the wrong calendar day | High | Fixed |
| 3 | Failed email never retried | High | Fixed |
| 4 | `current_user()` query storm | Medium | Fixed |
| 5 | Web mail run outlives `max_execution_time` | Medium | Fixed |
| 6 | Column name interpolated into SQL | Medium | Fixed |
| 7 | `fmt_date()` fatal on unparseable input | Medium | Fixed |
| 8 | `unseal()` throwing inside a catch block aborted the run | Medium | Fixed |
| 9 | Password policy accepted `passwordpassword` | Medium | Fixed |
| 10 | No dark mode | Medium | Fixed |
| 11 | No way to see an unread reply | Medium | Fixed |
| 12 | Cannot install to the home screen | Medium | Fixed |
| 13 | Nine touch targets below 44pt | Medium | Fixed |
| 14 | Missing indexes for the queries actually run | Medium | Fixed |
| 15 | Migration splitter breaks on a quoted semicolon | Low | Fixed |
| 16 | Duplicate submit reported the wrong error | Low | Fixed |
| 17 | Empty student status accepted on create | Low | Fixed |
| 18 | Delete confirmation was case-sensitive | Low | Fixed |
| 19 | `console.php check` threw before first migrate | Low | Fixed |
| 20 | Missing COOP/CORP headers | Low | Fixed |
| 21 | Document root misconfiguration exposure | Low | Hardened |
| 22 | `README.md` overstates what is implemented | Low | See roadmap |
| 23 | Mail to a null account can never send | Low | Documented |

---

## Security

### 1. Rate limits refunded their own counters — High

`throttle()` wrote its counter with `run()`, the same connection the guarded
action runs on. `handle_post()` opens a transaction around the action, so when
an action threw, the rollback also reverted the hit that was supposed to count
the failure. Every throttle called from inside `dispatch_action()` therefore
counted only *successes* — the opposite of the intent.

Concretely, `email_change` verifies the current password:

```php
case 'email_change':
    $u=require_user();throttle('email-change',(string)$u['id'],5,3600);
    if(!password_verify(post('password'),$u['password_hash'])) throw new UserError(...);
```

A wrong password throws, the transaction rolls back, and the counter returns to
where it started.

This was not a complete bypass. `handle_post()` separately throttles
`password_change` and `email_change` under an `account-security` bucket at
10 per 15 minutes *before* opening the transaction, so that limit did hold, and
an attacker needs the session cookie of a signed-in victim to reach either
endpoint at all. The `smtp-test` and `message` throttles were likewise
ineffective against failures only.

Fixed by giving rate limiting a second PDO connection, outside the action's
transaction, so a counter survives the rollback of what it is counting.

### 6. Column name interpolated into SQL — Medium

```php
run('UPDATE accounts SET '.$category.'=0 WHERE id=?',[$id]);
```

`$category` came from `post('category')`. It was *not* exploitable: the
preceding `valid_unsubscribe()` requires an `in_array($category,
['newsletter','notifications'], true)` match and the code throws otherwise.
But the statement depended on a guarantee made in another function, and an HMAC
check that happens to also validate the category is a fragile place to rest a
SQL-injection defence. Now mapped through an explicit whitelist at the query
itself.

### 9. Password policy accepted `passwordpassword` — Medium

`strong_password()` checked length only, so any 12 bytes passed, including
`passwordpassword`, `123456789012` and `abababababab`. Added a small blocklist
of the passwords that a 12-character minimum specifically fails to exclude, and
a check for short repeated patterns. Verified against the real function:
`Correct-Horse-Battery-9`, `Sommer2026!Wien` and `Tr0mmelwirbel!` are accepted;
`passwordpassword`, `abababababab`, `aaaaaaaaaaaa`, `badminton123` and
`123412341234` are rejected.

The German message said "Zeichen" (characters) while the English said "bytes";
the limit is `strlen()`, so bytes. Both now say bytes and note that accented
characters count double.

### 8. `unseal()` throwing inside a catch block — Medium

`process_mail()` redacts the SMTP password from error messages:

```php
} catch(Throwable $ex) {
    $message=mb_substr($ex->getMessage(),0,1000);
    if(!empty($s['password'])) $message=str_replace(unseal($s['password']),'[redacted]',$message);
```

`unseal()` throws when the app key does not match what sealed the value — after
a key rotation or a restore with the wrong config. That throw escapes the catch
block, aborts the whole run, and leaves the job not marked as failed. Redaction
now degrades to `[redacted]` instead of throwing.

### 20, 21. Headers and document root — Low

Added `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` and
`X-Permitted-Cross-Domain-Policies`. The existing set was already good:
a strict CSP with no `unsafe-inline`, `frame-ancestors 'none'`, HSTS,
`Referrer-Policy: no-referrer`, `nosniff` and `Cache-Control: no-store`.

The root `.htaccess` has `Require all denied`, which fails closed on Apache if
the document root is pointed at the project root by mistake. Added the same
rule per directory for hosts that do not inherit it, so `app/` and `config/`
cannot be served even under a partial misconfiguration. Note this is Apache
syntax and does nothing on nginx — on nginx the `root` directive in
`docs/nginx.conf.example` is what keeps those directories out of reach.

### What I looked for and did not find

- **SQL injection.** Every statement is a prepared statement with bound
  parameters. `ATTR_EMULATE_PREPARES` is off. The only dynamic SQL fragments
  are whitelisted column names, `LIMIT`/`OFFSET` from `(int)` casts, and
  conditional `WHERE` clauses built from fixed strings.
- **XSS.** Every template interpolation passes through `e()`
  (`htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE`). `$content` is echoed
  raw in the layout but is assembled entirely from escaped output. Message and
  news bodies render as escaped text in `.prewrap`, not HTML.
- **Broken access control.** `student()` and `thread_record()` add
  `AND account_id=?` for non-staff. Role checks are consistent, invitations
  cannot escalate role (`$u['role']!=='admin' && $role!=='student'` is
  rejected), and the last administrator cannot be removed — with all
  administrator rows locked first so two concurrent requests cannot both pass
  the check.
- **CSRF.** Per-session token compared with `hash_equals`, plus
  `SameSite=Lax`.
- **Session fixation.** `session_regenerate_id(true)` on sign-in, plus an
  `auth_version` column that invalidates other sessions on password change.
- **Token handling.** Tokens are 32 random bytes, stored only as SHA-256,
  single-purpose, deleted on use, and expiring (48h invite, 1h reset).
- **Enumeration.** Login verifies against a dummy hash when no account matches,
  so the timing does not distinguish. Password reset always reports the same
  message.
- **Dependencies.** PHPMailer 7.1.1 and, added in 0.2.0, bacon/bacon-qr-code
  3.1.1 with dasprid/enum, all pinned in `composer.lock`. Composer's advisory
  check ran during the 0.2.0 install and reported **no known advisories**. This
  supersedes the earlier note in this file that it could not be verified. It is
  still worth re-running `composer audit` at install time, since advisories are
  published after the fact.

---

## Correctness

### 2. Timestamps displayed the wrong calendar day — High

`now()` writes `gmdate()` (UTC). `fmt_date()` read it back with
`date($fmt, strtotime($v))`, and `strtotime()` interprets a bare datetime
string as *local* time. So a UTC value was relabelled as Vienna time rather
than converted, and anything stored after 22:00 UTC in summer showed the
previous day. Verified before the fix:

```
stored by now()   : 2026-09-15 22:30:00 (UTC)
local Vienna time : 2026-09-16 00:30:00 CEST
fmt_date() showed : 15.09.2026      <-- wrong day
```

A message sent just after midnight appeared to have been sent yesterday. Fixed
with `local_time()`, which converts DATETIME values to the configured timezone
and deliberately leaves DATE columns (`due_on`, `paid_on`) unshifted, since a
due date is a calendar date and must not move. Added `fmt_datetime()` for the
places that should show a time of day. The outbox previously printed raw UTC
strings labelled "UTC"; it now shows local time.

`now()` (UTC) and `today()` (local) remain deliberately different: `today()`
feeds DATE comparisons, where local is correct.

### 3. Failed email never retried — High

`process_mail()` selected `WHERE status='queued'` only. A job that failed once
became `status='failed'` and was never looked at again. A brief SMTP outage, a
DNS blip or a provider rate-limit therefore meant a permanently undelivered
invitation or newsletter unless somebody opened the outbox and pressed retry —
which nobody does unless they already know something is wrong.

Added `retry_after` with bounded backoff: 1 minute, 5, 15, 60, then stop at 5
attempts. Security mail stays excluded from automatic retries on purpose, since
its token may have expired by then; the user requests a fresh link instead,
which the UI already tells them to do.

### 5. Web mail run outlives `max_execution_time` — Medium

"Warteschlange senden" in the outbox calls `process_mail()` synchronously with
a limit of 25 and a PHPMailer timeout of 15s — up to 375 seconds, against a
typical `max_execution_time` of 30. The request was killed mid-loop. No data
was corrupted, because each job commits in its own transaction, but the page
never came back. The run now takes a time budget derived from
`max_execution_time`, stops cleanly, and reports how many are still queued for
the cron worker.

### 4. `current_user()` query storm — Medium

`current_user()` ran `SELECT * FROM accounts WHERE id=?` on every call, and
`is_staff()` calls it whenever invoked without an argument. The dashboard loops
every student calling `balance()` twice and an absence count, and
`student_card()` calls `balance()` again. Each of those paths re-resolves the
current user. On a 60-student portal that is several hundred identical lookups
per page load. Now resolved once per request, with the cache dropped on
`sign_in()` and logout so a session change is never served stale.

### 14. Missing indexes — Medium

The balance and outstanding-amount subqueries filter `charges` by
`student_id, cancelled` and `payments` by `charge_id, voided, confirmed_at`,
and neither had an index — a full scan per student card. The nightly
`console.php maintenance` deletes filtered on `auth_tokens.expires_at`,
`rate_limits.window_start` and `form_requests.created_at`, none indexed.
Migration 002 adds indexes for the queries the application actually issues.

### 7. `fmt_date()` fatal on unparseable input — Medium

`strtotime()` returns `false` on failure and `date()` rejects `false` for its
timestamp parameter with a `TypeError` on PHP 8, which would 503 the page.
Reproduced:

```
PHP Fatal error: Uncaught TypeError: date(): Argument #2 ($timestamp)
must be of type ?int, false given
```

Not reachable through validated input, since every value passed to it comes
from a DATE or DATETIME column, and `date_value()` rejects bad dates on write.
It is reachable from a legacy or hand-edited row. `local_time()` now requires
the value to round-trip, so MySQL zero dates and rollovers like `2026-02-30`
render as an em dash rather than `30.11.-0001` or silently becoming 2 March.

### 15–19. Smaller correctness fixes

- **Migration splitter.** `preg_split('/;\s*(?:\r?\n|$)/', $sql)` splits on
  every semicolon, including one inside a string default, an enum or a trigger
  body. 001 and 002 contain none, so nothing was broken, but the next migration
  to include one would have been silently cut in half and half-applied.
  Replaced with a splitter that tracks quotes, escapes, doubled quotes,
  backtick identifiers and both comment styles. Verified against eight cases
  plus the real migration files.
- **Duplicate submit.** Two submissions of the same form collide on the
  `form_requests` primary key, and the generic `23000` handler reported
  "email already used or related records exist" — alarming and wrong. Now
  reports a repeated submission, matching the message the non-racing path
  already used.
- **Empty student status.** `if(!isset(statuses()[$status]) && $status!==($existing['status']??''))`
  compares against an `$existing` record that does not exist when creating, so
  an empty status passed. It also threw an untranslated `'Invalid status'`.
- **Delete confirmation.** Emails are stored lower-cased by `email_value()`,
  but the typed confirmation was compared raw, so a correctly typed address
  with different capitalisation was rejected with no explanation.
- **`console.php check`** queried `schema_migrations` unconditionally and threw
  before the first `migrate`, exactly when an operator is most likely to run it.

### 23. Mail to a null account can never send — Low, documented

`queue_mail()` accepts `?int $accountId`, but `process_mail()` sets
`$eligible = $a && ...`, so a job with no account is always cancelled. No
current caller passes null, so this is latent rather than broken. Left as is,
because the strict behaviour is the safe one; noted here so a future caller
does not lose mail to it.

---

## Design and usability

Reviewed against the intended user: a non-technical adult whose habits come
from iOS. Verified by driving the real templates in Chromium at 320, 390, 768,
1440 and 1600px in light and dark, checking every page for PHP notices,
JavaScript errors, horizontal overflow and touch-target size.

**What was already good.** The layout is genuinely modern and holds up on a
phone: a bottom tab bar, `env(safe-area-inset-bottom)` for the home indicator,
`prefers-reduced-motion`, a print stylesheet, a skip link, `aria-current` on
navigation, 16px form controls (which is what stops iOS Safari zooming when a
field is focused), and a 44px minimum on buttons. Empty states are written in
plain language. The colours are calm and the contrast on body text is strong.
Somebody thought about this.

### 10. No dark mode — Medium

The stylesheet had no `prefers-color-scheme` block. iOS has had system-wide
dark mode since iOS 13; a phone in dark mode opening a stark white app at
night is the single most obvious "this is a website, not an app" signal. The 64
hardcoded colours were nearly all single-use, so dark mode as overrides would
have meant 64 of them. They are now semantic tokens and dark mode is one token
swap.

Three contrast defects surfaced only once the screenshots existed, which is the
argument for rendering rather than reasoning:

- `#c9d5de` was doing two unrelated jobs — a border colour *and* label text on
  the navy tile. Collapsed into one token, those labels became dark-on-dark.
  Split into a separate `--on-navy` role.
- The decorative racket outline used a body-text token and became glaring.
- Scoping dark button text to `.button` hit `.secondary` and `.subtle`, whose
  background is now dark, making them nearly invisible.

### 12. Cannot install to the home screen — Medium

No manifest, no `apple-touch-icon`, no `apple-mobile-web-app-*` tags. Adding it
to the home screen produced a generic bookmark that opened in browser
furniture. Added a manifest with relative URLs (so a subdirectory install still
resolves), icons generated from the existing brand mark at 180/192/512px, and
the Apple meta tags for iOS versions that predate manifest `display` support.
Both server examples now serve the `.webmanifest` media type. Verified the
manifest and all icons return 200 with correct content types.

### 11. No way to see an unread reply — Medium

The core loop is a coach messaging parents and parents replying. Nothing marked
a thread as having a new message. She would have had to open each conversation
to check, and would miss replies. Added a per-account read marker — per account
deliberately, so one manager reading a thread does not hide it from another —
with a count in the sidebar and the mobile tab bar, and a dot in the
conversation list. Own replies never mark a thread unread.

### 13. Touch targets below 44pt — Medium

Measured at 390px, nine controls sat between 23px and 42px against Apple's
44pt guidance, including the `tel:` link used to call a parent at 23px, the
privacy link at 24px, "Alle ansehen" at 26px, and the account avatar at 34px.
All are now at least 44px at phone widths. Inline prose links are deliberately
untouched, since padding those out breaks the text flow. One fix needed
`details summary` specificity to beat an existing 36px rule — the bare
selector silently lost.

### Customisation

Added, per account so it follows her between devices: appearance
(light / dark / match-my-device) and four text sizes. Text only scales up;
below 16px iOS Safari zooms on every field focus, which is worse than small
text. The existing per-operator customisation — custom fields with types,
options, sections, ordering and per-role visibility; statuses; absence reasons;
tariffs; payment methods; message templates; the portal name — is genuinely
extensive and needed no work.

### 22. `README.md` overstates what is implemented — Low

The feature list reads as a description of a mature product. Most items are
real, but some are thinner than the wording implies, and the honest picture is
in `ROADMAP.md`. Two examples: "a mail queue" was a queue that never retried
(finding 3), and "saved filters" cannot be renamed or edited, only created and
deleted. `VALIDATION.md` is, by contrast, admirably candid about what was not
tested — it says plainly that no visual inspection, public deployment or
independent security audit had happened.

---

## Verified in 0.2.0

Added as the feature work went in, all against the SQLite preview rather than
MySQL (see the section below):

- **Assessments do not leak to a parent account.** A signed-in parent opening
  their own child's page was probed for nine different markers — every skill
  name, the area names, an assessment note, and the entry form's action — and
  every one returned zero occurrences. The tab is also absent from the
  non-staff whitelist, so typing the URL gets the profile tab instead.
- **Role boundaries hold.** A trainer gets 403 on settings; a parent gets 403
  on classes, payments, accounts and settings.
- **The payment QR is correct end to end.** The SVG was read back out of the
  rendered parent page, rasterised and decoded with OpenCV: it carries a valid
  EPC069-12 payload whose amount is the **outstanding 25.00, not the full
  45.00** of a partly paid charge, with the profile's IBAN, BIC and a reference
  naming the charge and student.
- **IBAN validation.** mod-97 checked against four published IBANs (AT, DE, GB,
  CH) and six deliberate corruptions: 10/10 correct.
- **The migration runner is idempotent.** Three consecutive runs on a fresh
  database apply once then nothing; a migration added later applies alone; a
  column added later gives existing rows its default rather than NULL; editing
  an applied migration is refused by name; a migration failing at statement 2
  of 3 stops there, is not recorded, and does not run statement 3.
- **Every page renders clean for every role.** 20+ pages across administrator,
  trainer and parent, at 320-1600px in light and dark: no PHP notices, no
  JavaScript errors, no horizontal overflow, and every visible touch target at
  44px or more. Four regressions found this way and fixed, including 17px
  student-name links in the new class view.

## Verified in 0.3.0

- **The billing rule matches the specification, including the edges.** A student
  joining on the 1st gets that month free and is charged from the next; joining
  on any other day makes the following month the free one. Checked across a year
  boundary (15 Dec 2026 -> free Jan 2027, first charge Feb 2027) and a leap year
  (Feb 2028 coverage ends 29.02.2028). Invalid periods are rejected.
- **Billing cannot double-charge.** Three consecutive runs of the same month
  created 3, then 0, then 0. Every automatic charge carries a unique
  `billing_key`; a race that gets past the preview is refused by the database
  rather than duplicated.
- **Skipped students say why.** The preview lists everyone, with a reason:
  already created, billing paused, not a member yet, membership ended, the month
  they joined, the free first month, or no monthly price. Silence about somebody
  who should have been charged is the failure worth designing against.
- **Attendance defaults to the right day.** A Monday class opened on a Tuesday
  suggests the previous Monday, not today.
- **The mobile attendance control was rebuilt from measurement.** At 390px the
  five operator-defined labels truncated and overlapped. Text widths were
  measured at three font sizes against the available 76px per button; 11px would
  have fitted but is too small for the intended user. The fix was structural
  instead: "not recorded" moved out of the choice grid to sit beside the name,
  the default status set trimmed to three so each label fits one line at a
  readable size, and the grid wraps rather than clipping if more are added. A
  student's row went from about 370px tall to 132px, and the page for four
  students from 3648px to 1564px.

## Verified in 0.4.0

The previous rounds were verified by rendering pages and calling helpers by
hand. This round the checks were made repeatable: `php tests/run.php` boots the
real application against a disposable database built from the real migrations
and runs the whole suite in a few seconds. What that established:

- **A failing action leaves nothing behind.** An insert followed by an error
  rolls back completely. An inner scope that fails and is handled by its caller
  rolls back to its savepoint without taking the outer work with it — the
  behaviour a caller that catches the error is entitled to expect, and the
  reason nesting uses savepoints rather than a counter that only counts.
- **A repeated submission is refused by the primary key**, not by a select that
  two simultaneous submissions could both pass.
- **A failed action still counts against its rate limit.** Counters live on a
  second connection, so the rollback of the guarded action cannot refund them.
  Verified by failing an action inside the transaction and reading the counter
  after.
- **Undo puts back what a change touched, and nothing else.** An undo of an old
  edit leaves a later edit to a different column standing. An undelete restores
  the row under its original number. The undo is itself recorded, and a version
  cannot be applied twice. Column names are validated before they are written
  back, on both paths.
- **Money cannot drift.** A hundred additions of 0.07 come to exactly 7.00 in
  stored cents, and do not in floating point — which is why cents are stored.
- **A parent sees their own children and nobody else.** Checked by rendering the
  real dashboard with two families' students present and reading the HTML: the
  other family's names are absent, and so is the club's outstanding total, which
  the fixture deliberately makes a different number from the parent's own.
- **Pages cost a flat number of queries.** The student list issued one balance
  query per card: 56 with sixty students. It is now 6 for the whole page, and
  the suite fails if the per-row pattern returns — verified by reinstating it.
- **Nothing reaches a page unescaped.** The rule reads each printed expression
  down to the parts that can actually be printed, so a ternary is judged by its
  branches rather than its condition, and it uses PHP's tokenizer, which covers
  the 55 `echo` statements in views as well as the 493 short-echo tags. Helpers
  that merely truncate a string or look a code up in an editable setting are not
  counted as escaping; with them excluded the views still pass, which is the
  useful result — they were already wrapped in `e()`.
- **The source still has the shape it should.** A suite reads the files
  themselves: every function called is defined, the action dispatch chain is
  intact, and no file has been truncated. This exists because a bad extraction
  during this work silently reduced `app/actions_config.php` to 36 bytes and
  every behavioural test stayed green, since none of them touched it. That is
  the failure mode worth a test.
- **The rendered pages are clean.** 260 page loads — three roles across five
  viewport and colour-scheme combinations — with no HTTP error, no PHP notice,
  no horizontal overflow, no touch target under 44pt and no clipped label. The
  stylesheet was confirmed served as `text/css` for that run, after an earlier
  run was found to have been measuring unstyled pages because the preview
  server handed static files to the front controller.

## Not done, and why

- **Nothing was run against MySQL or MariaDB.** No server could be installed
  here and there is no container runtime. The SQLite translation used for
  rendering and for the test suite required rewriting the upsert idioms,
  `FOR UPDATE`, `GET_LOCK`, `IF()`, `GREATEST`/`LEAST`, the inline index syntax
  and `ALTER TABLE ... ADD CONSTRAINT`, so it proves the templates and PHP logic
  work — not that the SQL runs on the target engine. Run
  `CRM_TEST_DRIVER=mysql php tests/run.php` against a disposable database to
  close that gap; the runner lists what the SQLite driver could not cover, which
  today is the foreign key on `charges`.
  **Migrations 002 to 006 have never been executed against MySQL or
  MariaDB.** Run `php bin/console.php update` against a disposable copy before
  touching anything real. The two `ADD CONSTRAINT` statements in 004 are the
  ones SQLite could not exercise at all.
- **`tests/integration.py` and `tests/smtp_integration.py` were not run**, for
  the same reason. They need a live database and a local SMTP capture server.
  They are the right next step on a machine that has both.
- **No penetration testing.** This is a source review.

## On reusing an existing open-source CRM

Worth stating plainly, since it was asked: I would not replace this with
EspoCRM, SuiteCRM, Krayin or Monica.

Those are built around leads, deals and pipelines. A badminton coach needs
students, a tariff, who has paid for which period, who is absent, and a message
thread with a parent. Mapping that onto opportunity stages means either
maintaining a translation layer forever or teaching her vocabulary that has
nothing to do with her work. They also bring a much larger attack surface,
a heavier hosting footprint and an upgrade treadmill, for a single-operator app
that will never be commercial — and the customisation she actually needs
(her own student fields, her own statuses) already exists here and is simpler
than the equivalent in any of them.

The place to reuse rather than write is narrower and genuinely worth it:
PHPMailer is already vendored for SMTP, and a future CSV or PDF export should
use an established library rather than hand-rolled formatting.
