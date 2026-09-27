---
status: accepted
date: 2026-09-27
---

# 0012. Unexpected errors report themselves

## Context

The owner: "I'm not technical enough for debugging and need something that just works." She
approved this: when the portal hits an unexpected error, it saves the error, with the steps
the person took before it, and shows it to administrators under Einstellungen →
Rückmeldungen with a way to copy it for support.

- Families see the friendly page, as now.
- Entries are deleted after 30 days.
- Passwords are never saved.

Today an unexpected throwable ends in `error_log()`, which she cannot read, and in one of
two pages: the 503 page (`public/index.php`, the final `catch (Throwable)`) or „Speichern
fehlgeschlagen" (the POST branch's `PDOException` catch). Background jobs log and continue
(`app/tick.php`).

What already exists:

- the trail and its redaction (ADR 0009: `record_step()`, `recent_steps()`,
  `is_secret_field()`, `report_url()`);
- the `feedback` table, its admin view and its notifications;
- the done-and-30-days deletion rule.

## Decision

**Storage: the `feedback` table, with no schema change.** An automatic entry is a row where:

- `account_id` is `NULL`, because the entry gathers everyone who hit the error;
- `page` is the page it happened on;
- `message` is a short generated line, such as „Fehler auf Seite *students*: TypeError";
- `context_json` **begins with** `{"kind":"error","fingerprint":"<hex>",…`.

That fixed prefix makes an automatic entry recognisable by a prefix `LIKE` with a bound value.
A human report's context begins with `{"page":`, so it can never match, whatever someone
typed. The table, the admin view, the unread count, the notification and the deletion are all
reused as they are.

**Code: `app/shell.php`, in the "This is broken" section**, beside the trail it reuses. No new
file.

- **`capture_error(Throwable $e, string $where = 'request'): void`** does the following, in
  order:
  1. It returns at once for a `UserError`, which includes `NotFound` and every refusal the
     router answers with 403.
  2. It allows one capture per request.
  3. It writes a one-line `error_log()` entry **first**, so the log still has it if the rest
     fails.
  4. It runs `tx_abandon_open('error')`.
  5. It writes inside `transactional()` on the main connection.

  The whole body is wrapped in `try { … } catch (Throwable) { error_log(…) }`, so **it cannot
  throw**.
- **It is called from four places**, named in a `structure` rule:
  - the final `catch (Throwable)` in `public/index.php`;
  - the POST branch's `PDOException` catch, except the `form_requests` replay, which is a
    handled double tap;
  - the two catches in `app/tick.php`, with `$where = 'background'`.

  It is not called from `schema.php`, the mail sender (whose failures already show in the
  outbox), `setup.php` or the console. Like `tick`, it is a named writer outside an action
  (ADR 0003).
- **`capture_fatal_error(): void`** is registered with `register_shutdown_function` in
  `public/index.php`, after `boot_http()`. When `error_get_last()` shows a fatal error, such
  as a timeout or running out of memory, it passes an `ErrorException` to
  `capture_error()`. It takes an optional array parameter so a test can hand it a fatal
  error directly.

**When nothing is written to the database**, only `error_log()`:

- the maintenance flag exists;
- the main connection was never opened (`!db_connected()`);
- the throwable is a `PDOException` whose SQLSTATE class is `08`, or whose driver code says
  the connection is gone: 2002, 2003, 2006 or 2013.

During an update, `schema_ensure_current()` shows its own page and never reaches the capture.

**What is captured:**

- the class, and for a `PDOException` the SQLSTATE and driver code;
- file and line, relative to `ROOT`;
- up to 20 stack frames, each only `file`, `line`, `class`, `type` and `function`, **never
  `args`**;
- the class of any previous exception;
- the method and `report_url()`;
- the role that was signed in, and that account's id for the admin view;
- `recent_steps()`, which exists only while signed in;
- the user agent, the portal version, the PHP version, and `$where`.

**Never captured:** the IP address, or anything outside the trail's own redaction.

**Messages:**

- A `PDOException` message is **not stored**: MariaDB puts values into it ("Duplicate entry
  'familie@…'"). The SQLSTATE, the code and file:line identify the error.
- Any other message is kept after `error_message_scrub()`. That function:
  - replaces email addresses, runs of 6 or more digits and runs of 20 or more letters and
    digits with `…`;
  - replaces the SMTP password, the database password and `app_key` wherever they appear
    literally;
  - cuts the result to 300 characters.

**Merging repeats.** `fingerprint` is `sha1(class | SQLSTATE-or-code | file:line)`.

- A match that is not done gets `count+1` and a new `last_seen`, and its last occurrence
  (request and trail) is replaced.
- A match that is done is set back to `new`, counted and re-notified. The error came back,
  which is news.
- Otherwise a new row is written, with `first_seen = last_seen = now()` and `count=1`.
- Every write repeats the context it read (`WHERE id=? AND context_json=?`). A race can cost
  one count, or at worst two rows. That is accepted.

**Caps:**

- at most **50** automatic entries not marked done; past that, only existing ones count up
  and anything new goes to `error_log()`;
- at most **64 KB** of context per entry, dropping step values first and then steps;
- one capture per request.

**Retention.** A constant, `ERROR_KEEP_DAYS = 30`, and `prune_quiet_errors()`, run by
`prune_expired()`. An automatic entry is deleted 30 days after its `last_seen`, whatever its
state, or by the existing done rule, whichever comes first. Marking an entry done drops typed
values exactly as it does for a report.

**Telling the administrators.** `notify()` goes to every active administrator (kind
`problem`, linking to Rückmeldungen) **only** when a row is created or re-opened by a repeat.
A count going up is silent.

**„Für den Support kopieren".** `support_text(array $entry): string` builds plain text for
someone outside the club. It contains:

- the version, PHP, first and last seen, the count, the class, SQLSTATE and code, the scrubbed
  message, file:line and the frames;
- the method and URL of the last occurrence. Query values other than `page`, `id`, `tab` and
  `what` are shown as `…`, because a search term can be a child's name;
- the trail's methods, actions and **field names only, never field values**;
- the user agent.

It never contains names, email addresses, the IP address or typed values. That text is
leaving the club; the admin view inside the portal may keep showing values, as it does for
reports.

The page prints it in a `<textarea readonly>` through `e()`, which can be selected and copied
without JavaScript. `app.js` adds a copy button, but only when `navigator.clipboard` exists.

## Rejected

**A new `errors` table.** It would need a migration and the owner's yes, plus a second admin
view, a second deletion job and a second notification, all for rows the feedback machinery
already handles.

**Marking automatic entries with a new column.** The same migration, for a flag the fixed
JSON prefix already carries.

**Keeping PDO messages and masking the values.** A masking pattern that misses one engine's
wording leaks a family's address into a support paste.

**Writing on the counter connection.** It opens a second connection at the worst possible
moment. After `tx_abandon_open()` the main connection is clean, or else it is dead, and dead
is handled.

**Capturing PHP warnings.** They are noise at this volume. Fatal errors are captured, because
they are the timeouts she would actually hit.

**Storing the IP address.** It adds nothing to debugging and is personal data.

## Consequences

- **`backend-dev`**:
  - `app/shell.php`: `capture_error`, `capture_fatal_error`, `error_message_scrub`,
    `support_text`, `prune_quiet_errors`, `ERROR_KEEP_DAYS`;
  - the four call sites;
  - `prune_expired()`.
- **`frontend-dev`**: `views/_feedback.php` shows an automatic entry distinctly („Automatisch
  erfasst", with the count and first and last seen), plus the textarea and the copy button
  in `app.js`.
- **`qa-tester`** writes, and breaks each on purpose:
  1. A `RuntimeException` from a view gives one automatic row, with class, file:line, frames
     without `args`, and version. The response is still the friendly 503.
  2. The same error twice gives one row with count 2, a later `last_seen`, and one
     notification per administrator.
  3. A repeat after done re-opens the entry and notifies once.
  4. `UserError`, `NotFound`, a 403 refusal and the `form_requests` replay each give **no**
     row. Break it by removing the `UserError` guard.
  5. A `PDOException` from a duplicate entry with a known email: the raw `context_json`
     holds neither the message nor the email; the SQLSTATE and code are there.
  6. A POST carrying `password` at the moment of the error: the password is absent from
     the raw `context_json` and from `support_text()`.
  7. `support_text()` holds field names and no field value, no email, no IP address, and `…`
     for a `q=` search.
  8. An anonymous request gives no steps, a `NULL` `account_id` and no IP address.
  9. A database connection failure (connect override throws), maintenance mode, or a capture
     whose own insert fails: `capture_error()` does not throw, `error_log()` is called, and
     the friendly page is still sent.
  10. The 51st distinct open error is not stored, and existing ones still count up.
  11. `prune_quiet_errors()` deletes by `last_seen` whatever the state, and leaves human
      reports alone.
  12. A background job's exception is captured with `where=background` and no steps.
  13. `capture_fatal_error()` with an injected fatal error array captures; with a warning it
      does not.
  14. The `structure` rule: `capture_error()` is called in exactly the four named places.
- **`docs-writer`**: `TESTING.md` gains walking a real timeout on the hosting, and the
  privacy notice may need a sentence on automatic error records.
- **Must not:**
  - add a column or table;
  - store a `PDOException` message, `args`, or the IP address;
  - throw from, or recurse into, the handler;
  - capture a `UserError`;
  - write to the database during maintenance or with the connection gone;
  - put a typed value into `support_text()`.
