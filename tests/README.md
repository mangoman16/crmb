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
installation. Against a database you manage yourself:

```bash
CRM_TEST_DRIVER=mysql CRM_CONFIG=/path/to/test-config.php php tests/run.php
```

The harness drops and recreates every table in that database on each run, and
refuses to start if its name does not end in `_test`.

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

## The other two suites

`integration.py` and `smtp_integration.py` drive a running server over HTTP and
need a live database and a local SMTP capture server. See the instructions at
the top of each. They are slower and cover the request path; the PHP suite here
covers the rules.
