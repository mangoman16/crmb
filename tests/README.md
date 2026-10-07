# Tests

```bash
tests/mariadb-local.sh            # the whole suite, on a throwaway MariaDB
tests/mariadb-local.sh billing    # one suite
```

The suite runs on MariaDB, the engine the portal's server runs. It builds the schema from
the real files in `database/migrations/` and boots the real application against
it, so a test exercises the code and the SQL that ship rather than a copy of
them. There is no SQLite translation any more: `php tests/run.php` on its own
refuses to start without a `*_test` database to run on, and says which of the
two scripts below to use. Anything a run could not cover is printed at its end
rather than skipped quietly, so coverage cannot silently shrink.

`tests/mariadb-local.sh`, on a machine that has `mariadbd` but no server for
this, starts a throwaway one, runs the suite and stops it again. It also makes a
second, empty `_test` database, on which the data migrations carry across is
checked from one version to the next.

It keeps its data in a temporary directory and never touches an existing
installation.

**Two runs at the same time must not share that directory.** By default every
run uses `$TMPDIR/crm-mariadb` and port 3307. A second run that finds a server
already answering there uses it rather than starting its own, and both then
drop and recreate the tables of the same `badminton_crm_test` database under
each other, and the run that started the server stops it when it finishes,
whether the other is done or not. Give each concurrent run a folder and a port of its own:

```bash
CRM_MARIADB_WORK=/tmp/crm-mdb-a CRM_MARIADB_PORT=3391 tests/mariadb-local.sh
CRM_MARIADB_WORK=/tmp/crm-mdb-b CRM_MARIADB_PORT=3392 tests/mariadb-local.sh security
```

Keep the folder's path short. The server's socket is `run/mysql.sock` inside
it, and MariaDB refuses to start when that whole path is longer than 107
characters („The socket file path is too long (> 107)"); the script then stops
with „Server did not start" and the reason in the folder's `server.log`.

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
CRM_CONFIG=/path/to/test-config.php php tests/run.php
```

The harness drops and recreates every table in that database on each run. It
refuses to start unless the name is letters, digits and underscores ending in
`_test`, and it is not the
database `config/config.php` gives the portal. Whatever that configuration says
about `maintenance_file`, a run writes its uploads, backups and flags into a
folder of its own under the system temp directory, prints it on its second line,
and removes it at the end.

The suite has been run against **MariaDB 10.11.14** with PHP 8.4.26, everything
passing; [VALIDATION.md](../VALIDATION.md) has the latest run and its date.
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
`make_class`, `make_enrolment`, `make_thread`, `create_through_wizard`,
`sign_in_as`, `sign_out`, `test_reset`. `act('action', [...])` dispatches an
action inside one transaction, as a request does; `submit()` also goes through
`handle_post()`, so the CSRF check, the rate limits and the duplicate-submission
claim apply.

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

A test about the stylesheet reads it with `tests/css.php` — `css_rules()`,
`css_matching()`, `css_specificity()`, `css_wins()` — rather than matching
strings, so it holds however `app.css` is formatted; the file's header lists
what those functions get wrong.

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

`shell` also runs `topbar-menus.mjs` with `node`, which loads the real
`public/assets/app.js` into a page of stand-in elements and taps, swipes and
presses Escape on the top bar's menus. No browser and no Playwright: only
`node`. Without it those checks are listed at the end of the run as not
covered, rather than passed.

## The first evening, end to end, in a browser

```bash
tests/e2e.sh                          # the working tree as it is now
CRM_E2E_REF=HEAD tests/e2e.sh         # exactly one commit, whatever the tree holds
CRM_E2E_PHP=php8.5 tests/e2e.sh       # another PHP on the same machine
tests/e2e.sh --stop-after "7 mail"    # stop once that step has run
CRM_E2E_VERBOSE=1 tests/e2e.sh        # list every check that passed, not only the failures
```

What an administrator does on the first evening and what a family does the next
morning, pressed through the real forms in Chromium at 390px, against a real
MariaDB, with nothing reaching into the application from the side. No step
calls an action directly, and there is no fallback: a form without a visible
button stops the walk there, as it would stop her. That is how it found the
tariff form of fd0d179 that every suite here passed.

It walks `setup.php`, signing in to „Dein Portal einrichten“ at 0 of 9, each of
the nine steps from its own button and back through „Zurück zur Einrichtung“
with the tick checked every time, up to 9 of 9 and „Alles eingerichtet“. The
children are added through the wizard, „Schüler anlegen“, and one more person
is invited by address alone („Per E-Mail einladen“). One
child joins the course on the day of the run and the other part-way through
the month (its „Dabei seit“ set to a day before today); both charges must cover
from the day they joined, be due no earlier than that day or the day they were
written, and not be overdue on the day they appear, and the family's card must
name the same period as the invoice. Then the family opens the invitation link out of the captured mail, sets a password,
sees their child under „Profil“ and the charge under „Beiträge“, uploads a
payment proof and sends „Etwas funktioniert hier nicht“. Then the trainer sees
the charge and the proof, records the payment as confirmed, issues an invoice
and checks the PDF, and reads the report with its trail; the report must bring
the family back to the same child and tab. Last, the news table is renamed under
the running portal and the family opens Neuigkeiten three times: they must see
only the friendly page, Rückmeldungen must show it once with „3×“, one
notification and a support text holding no name, address or IP; marked
„Erledigt“ and broken once more it must be new again at „4×“, with a second
notification. The table is put back each time. Every page a role opened is then opened again at
320px. A check that stands for a numbered one in `TESTING.md` names it in a
comment in `e2e.mjs`, such as U.20 or U.46.

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

## Two older scripts

`integration.py` and `smtp_integration.py` (with `db.php`) drive a running server
over HTTP and need a live database and a local SMTP capture server; the
instructions are at the top of each. No run of either has been recorded since
0.1.0, and as written they cannot pass: `integration.py` makes its students by posting
`student_save` without an id, which the portal now refuses — students are made
by the wizard — and `smtp_integration.py` builds on what `integration.py` made.
Whether they are mended or deleted is ADR 0026's to decide.
