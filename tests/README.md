# Tests

```bash
php tests/run.php            # every suite
php tests/run.php billing    # one suite
```

No database server is needed. The suite builds a disposable SQLite database from
the real files in `database/migrations/` and boots the real application against
it, so a test exercises the code that ships rather than a copy of it.

## What the default driver does and does not prove

The application is written for MySQL. `tests/harness.php` translates the dialect
on the way in — upserts, `FOR UPDATE`, `IF()` — by rewriting statements as they
are prepared, so the application's own SQL strings are what run. That proves the
PHP logic and the shape of the data. It does **not** prove the SQL runs on MySQL.

Anything the translation cannot represent is printed at the end of a run rather
than skipped quietly, so coverage cannot silently shrink.

To prove the SQL, run against a real engine. On a machine with no database
server, this starts a throwaway one, runs the suite and stops it again:

```bash
tests/mariadb-local.sh            # the whole suite
tests/mariadb-local.sh billing    # one suite
```

It keeps its data in a temporary directory and never touches an existing
installation.

On shared hosting there is no server to start, but the hosting panel can create
another database. Create an empty one whose name ends in `_test` (the panel adds
the account prefix, as in `konto_crm_test`), then, in the git checkout that
serves the portal (the release ZIP has no `tests/`):

```bash
tests/existing-database.sh            # the whole suite
tests/existing-database.sh billing    # one suite
```

The first run asks for the database name, user, password, server and port, and
saves them in `tests/.test-database.php` (git ignores it; delete it to use
another database). It refuses a database that is not empty, one holding
accounts with real email addresses, and the one the portal's own
`config/config.php` uses. It warns when the same user can reach other
databases: a user that can reach only the test database is safer.

Against a database you manage yourself:

```bash
CRM_TEST_DRIVER=mysql CRM_CONFIG=/path/to/test-config.php php tests/run.php
```

The harness drops and recreates every table in that database on each run. It
refuses to start unless `CRM_TEST_DRIVER` is exactly `mysql` (or `sqlite`), the
name is letters, digits and underscores ending in `_test`, and it is not the
database `config/config.php` gives the portal. Whatever that configuration says
about `maintenance_file`, a run writes its uploads, backups and flags into a
folder of its own under the system temp directory, prints it on its second line,
and removes it at the end.

The suite has been run against **MariaDB 10.11.14** with everything passing.
**MySQL 8.0 has not been tried**, so do not claim it.

## Writing a test

Suites are plain PHP files in `tests/suites/`, run in alphabetical order with a
freshly emptied database and the seeded defaults. Group related assertions with
`case_()` and describe the behaviour, not the mechanics:

```php
case_('A parent reaches only their own children');
sign_in_as($parentA);
does_not_throw(fn() => student($kidA), 'their own child is visible');
throws(fn() => student($kidB), 'another parent\'s child is not');
```

Assertions: `ok`, `is_same`, `is_equal`, `throws`, `does_not_throw`.

Fixtures: `fixture`, `make_account`, `make_student`, `make_tariff`,
`make_class`, `sign_in_as`, `sign_out`, `test_reset`.

Two more are worth knowing about:

`render_view('dashboard')` renders a real page and returns its HTML, with the
signed-in account deciding what the page shows. Checking the page itself catches
what a test re-implementing its logic cannot, because the re-implementation
drifts — and it is how the "a parent sees only their own children" rule is
pinned.

`query_count(fn() => ...)` reports how many statements the application prepared,
counted by the driver rather than by instrumenting the code. Use it on anything
that renders a list, so that one query per row is caught while it is cheap to
fix.

## The suites

Most cover a part of the domain — `billing`, `attendance`, `settings`,
`security`, `dates`, `history`, `transactions`. Three are different in kind:

- `views` renders the real pages and reads what came out.
- `performance` counts queries, so a page that grows a query per row fails.
- `structure` reads the source files themselves: every function called is
  defined, the action dispatch chain is intact, nothing printed to a page is
  unescaped, and no file has been truncated. That last one exists because a bad
  edit once reduced a dispatch file to 36 bytes while every other test stayed
  green — nothing else was reading it.

## The first evening, end to end, in a browser

```bash
tests/e2e.sh                          # the working tree as it is now
CRM_E2E_REF=HEAD tests/e2e.sh         # exactly one commit, whatever the tree holds
CRM_E2E_PHP=php8.5 tests/e2e.sh       # another PHP on the same machine
tests/e2e.sh --stop-after "7 mail"    # stop once that step has run
```

What the owner does on her first evening and what a family does the next
morning, pressed through the real forms in Chromium at 390px, against a real
MariaDB, with nothing reaching into the application from the side. No step
calls an action directly: if a form has no button, the walk cannot press it,
which is how it found the tariff form of fd0d179 that every suite here passed.

It walks `setup.php`, signing in to „Dein Portal einrichten“ at 0 of 9, each of
the nine steps from its own button and back through „Zurück zur Einrichtung“
with the tick checked every time, up to 9 of 9 and „Alles eingerichtet“. Then
the family opens the invitation link out of the captured mail, sets a password,
sees their child under „Profil“ and the charge under „Beiträge“, uploads a
payment proof and sends „Etwas funktioniert hier nicht“. Then the trainer sees
the charge and the proof, records the payment as confirmed, issues an invoice
and checks the PDF, and reads the report with its trail. Last, a table is
renamed under the running portal: the family must see only the friendly page,
Rückmeldungen must show it once with „2×“ and a support text holding no names,
and the table is put back. Every page a role opened is then opened again at
320px.

On every page it records, and fails on: an HTTP 5xx the walk did not provoke,
a JavaScript error or failed request, a warning, notice or deprecation in PHP's
error log, anything wider than the screen, a tap target under 44px (the rules
of `mobile.mjs`), PHP source printed as text — and a page without `app.css`
applied, because a sweep of unstyled pages once reported clean. Things seen
that the design intends but that are worth a decision are printed as **Notes**
and do not fail the run.

It needs `mariadbd`, `python3`, `openssl`, `node` and Playwright's Chromium
(`PLAYWRIGHT_PATH`, as for `mobile.mjs`). Each run copies the portal into a
fresh `$TMPDIR/crm-e2e-<port>` - what an upload does - and starts its own
MariaDB, an SMTP sink (`e2e_smtp.py`, STARTTLS with a certificate made for the
run) and `php -S` on ports `CRM_E2E_PORT` (8765), +1 and +2. It refuses to start
if any of them is taken: a server left from an earlier run would answer instead
of this one's, about files this run has replaced. `CRM_E2E_KEEP=1` leaves all
three running afterwards to look around; stop them before the next run.

Mail is not sent by a command. Like on her host, the queue is worked by the
background task after page views, at most once a minute, so the invitation takes
up to a minute to arrive and the walk waits for it. A full run takes about two
minutes.

To prove the checks can fail, `CRM_E2E_AFTER_COPY` runs a command inside the
copy, never the checkout - for example
`CRM_E2E_AFTER_COPY=': > public/assets/app.css'` must fail on every page with
„app.css applied“, and `echo ".setup-progress{min-width:600px}" >>
public/assets/app.css` must report the checklist as wider than the screen.

What it does not prove: Safari (it is Chromium with an iPhone's size and user
agent), a real mail provider, the PDF in a reader other than a parser, Apache or
a host's PHP settings (it is `php -S`), and MySQL.

## The other two suites

`integration.py` and `smtp_integration.py` drive a running server over HTTP and
need a live database and a local SMTP capture server. See the instructions at
the top of each. They are slower and cover the request path; the PHP suite here
covers the rules.
