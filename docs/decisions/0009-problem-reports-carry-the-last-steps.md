---
status: accepted
date: 2026-09-24
---

# 0009. Problem reports carry the last steps the person took

## Context

`feedback_context()` in `app/shell.php` records the page name, `$_GET`, the Referer, the
user agent and the IP address. Two of those fields have been empty in every report ever
filed:

- `query` is empty because it is read inside the `feedback_send` POST, and forms post to
  `index.php` with no query string.
- `referer` is empty because `boot_http()` sends `Referrer-Policy: no-referrer`, so the
  browser never sends one.

Actions follow post/redirect/get, so the page a report is filed from is almost always a GET
that followed a POST. The owner wants each report to show:

- the exact URL the person was on,
- the URL before it,
- the method of each request,
- for a POST, the data that was posted.

Reports go only to administrators, who can already see all data. A report must never
contain a password or a token.

## Decision

**One recorder, one call site.** `record_step(string $page): void` lives in `app/shell.php`,
in the "This is broken" section. It is called once, from `public/index.php`, directly after
the allow-list check and before the POST branch, so it sees POSTs before they redirect.
Nothing else writes the trail. `recent_steps(): array` reads it.

`feedback_context()` changes:

- It gains `steps`.
- It gains `on`: the `return_page`, `return_id` and `return_tab` of the report's own POST.
  The back button can show a page without making a request, and `on` is where the form
  actually was.
- It loses `query` and `referer`, which were always empty. `views/_feedback.php` loses the
  line that printed `query`.

**Whose trail.** Steps are recorded only while `$_SESSION['user_id']` is set, so the login
POST and anonymous token links are never recorded. The trail belongs to one account: if
`$_SESSION['steps_account']` differs from the current id, the trail starts again. That
covers an idle expiry followed by somebody else signing in on the same phone, and the start
of impersonation. Logout already empties the session.

**What is not recorded:**

- the pages `download`, `icon` and `manifest`. Avatars and inline attachments load through
  `download` on every page and would push real steps out of the trail.
- a POST whose action is `feedback_send`.

**Size.** The trail keeps the last **8** steps, oldest first.

**Each step records:**

- `at`: `now()`
- `method`: `GET` or `POST`
- `url`: the path of `REQUEST_URI`, then `?` and the redacted `$_GET`, at most 300 characters
- for a POST:
  - `action`
  - `from`: `return_page`, `return_id` and `return_tab`, meaning the page the form was on
  - `fields`: the redacted `$_POST`
  - `files`: for each file field, the bytes, the type the browser declared and the upload
    error code. Never the file name, never the content.
- for a GET: `flash` (kind, plus text of at most 200 characters) when one is waiting. That
  is the outcome of the POST before it, recorded without a second call site.

**Redaction: one rule, shared.** `is_secret_field(string $key): bool` in `app/core.php`
returns true when the key is `csrf`, or when it contains (case-insensitively) `password`,
`passwort`, `token`, `secret` or `signature`. `remember_input()` uses the same function
instead of its own `str_contains($key,'password')`, so both copies of "never keep a
password" are one rule.

- Secret values are replaced with the fixed string `***`, whatever was typed, even when
  nothing was typed.
- The rule applies to `$_GET` and `$_POST` keys at every depth. `$_GET` needs it for the
  email-change `token` and the unsubscribe `signature`, which can be opened while signed in.
- Some fields are dropped from a POST's `fields` before redaction applies, because their
  value is known or useless:
  - `csrf` and `request_id` are the same kind of noise on every line. `csrf` would
    otherwise print `***` in every POST step.
  - `action` and `return_*` have their own fields.
- `is_secret_field()` still names `csrf`. That keeps it safe on any path that does not drop
  it: a `$_GET` key, a nested key, and `remember_input()`.

**Deliberately not redacted:**

- `iban` and `bic`: the only IBAN in the schema is the club's own receiving account
  (`payment_profiles`), and it is printed on every invoice.
- email addresses, message text and notes: administrators can already read these.

**Truncation:**

- Each value goes through `mb_scrub()` and is cut to 200 characters, ending in `…`.
- At most 40 fields per step.
- Arrays keep 20 items per level and two levels; anything deeper becomes `…`.

**Storage.** `feedback.context_json` is `LONGTEXT` (`database/migrations/011_shell.sql`), and
8 steps come to roughly 25 KB at most. No migration. `feedback_send` encodes with
`JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE`, so one malformed byte cannot turn the
whole context into `false`.

**Retention, decided by the owner.** Marking a report „Erledigt" drops `fields` and `files`
from its steps, keeps the URLs, and stores the time in `context_json.values_dropped_at`.
Later markings do not change that value, because it records the first drop.

Every marking rewrites `context_json.done_at`. A done report is **deleted 30 days after its
latest `done_at`**:

- The job is `prune_done_feedback()` in `app/shell.php`, called from `prune_expired()`.
- The period is the constant `FEEDBACK_DONE_KEEP_DAYS = 30`.
- It counts from the latest marking, so re-opening a report and closing it again starts the
  30 days over.

Further rules:

- A report that is not in the done state is never deleted, however old, including one that
  was re-opened.
- A done report with no `done_at`, such as one closed before this rule existed, is stamped
  with the current time and kept for 30 days from then. Its age is not guessed from
  `created_at`.
- Its screenshot goes with the next `prune_uploads()` sweep once the row is gone.
  `upload_references()` already lists `feedback.screenshot_name`.
- No schema change. The times live in `context_json`, which already exists.

**A named exception to ADR 0003.** Reading `$_POST` outside an action is otherwise a FAIL.
`record_step()` and `remember_input()` are the two functions allowed to do it. Both only copy
input into the session for display, and neither acts on it.

## Rejected

**A trail kept by JavaScript and posted with the form.** Every page must work without JS,
and the client would be reporting on itself.

**Relaxing `Referrer-Policy`.** It gives one step at best. It would also send portal URLs,
with student ids in them, to every external site a link in a message points at.

**A database table of requests.** It would put a write on every GET, against ADR 0003's "a
GET never changes data". It would also need a migration and a retention job, all for data
read only when something breaks.

**Recording in `handle_post()` and in the layout.** That is two call sites. The layout never
runs for a POST or a redirect, so the trail would have holes exactly where PRG happens.

**A per-action allowlist of fields to record.** It is safer in theory and does not work in
practice. There are 68 cases, and the action nobody updated, the new one, is the one most
likely to be reported. The secret fields here are few and consistently named: `password`,
`current_password`, `password_confirm` and `smtp_password` in POSTs, `csrf` in every form
(dropped from the trail but still named by the rule), and `token` and `signature` in GET
links. A test pins them.

**Recording anonymous requests.** That would capture the login email, and token URLs before
redaction had a chance to be wrong. Anonymous visitors cannot file a report anyway.

**Keeping `csrf` in the trail as `***`.** It was noise on every line, it told an
administrator nothing, and it pushed real fields towards the 40-field cap.

**Counting the 30 days from the first time a report was marked done**, or from
`created_at`. A report re-opened because the problem came back would be deleted while
somebody was still looking at it.

**Guessing a done time for old reports that have none.** Any guess can only be earlier than
the truth, and an earlier guess deletes sooner than the owner agreed.

## Consequences

- Every POST's values sit in the session for up to 8 further requests. `remember_input()`
  already keeps up to 200 KB of posted data there after a refusal, so this is a precedent
  widened, not a new kind of storage.
- Reports hold copies of what people typed. The values last only until the report is marked
  done. The report itself lasts only 30 days after that, though an open or re-opened report
  stays indefinitely. `feedback` rows still survive account deletion (`ON DELETE SET NULL`)
  until then. The privacy notice may need a sentence (`docs-writer`).
- `qa-tester` writes the redaction test. It submits real actions carrying `password`,
  `current_password`, `password_confirm`, `smtp_password`, `csrf`, and a GET `token` and
  `signature`, files a report, then searches the **raw** `context_json` for each value,
  not the decoded keys. Remove one keyword from `is_secret_field()` and watch the test fail.
  It also asserts that no POST step carries a `csrf` or `request_id` key at all.
- `qa-tester` writes the retention test:
  - Mark a report done, re-open it and mark it done again: the report survives 30 days
    after the first marking, is deleted after 30 days from the second, and
    `values_dropped_at` still holds the first time.
  - A re-opened report older than 30 days survives.
  - A done report with no `done_at` is stamped, not deleted, on the first run.
  - Its screenshot is gone after the next `prune_uploads()`.
  - Break the rule by counting from `values_dropped_at` and watch the test fail.
- `views/_feedback.php` shows the steps, every value through `e()`. `frontend-dev` builds
  it to `ui-ux-designer`'s specification.
- `security-reviewer` reviews `is_secret_field()` and the call site. A new form field that
  holds a secret must match that rule or be added to it.
