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

To prove the SQL, run against a real database whose name ends in `_test`:

```bash
CRM_TEST_DRIVER=mysql CRM_CONFIG=/path/to/test-config.php php tests/run.php
```

The harness refuses to run if that database name does not end in `_test`.

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

Available: `ok`, `is_same`, `is_equal`, `throws`, `does_not_throw`,
`fixture`, `make_account`, `make_student`, `make_tariff`, `make_class`,
`sign_in_as`, `sign_out`, `test_reset`.

## The other two suites

`integration.py` and `smtp_integration.py` drive a running server over HTTP and
need a live database and a local SMTP capture server. See the instructions at
the top of each. They are slower and cover the request path; the PHP suite here
covers the rules.
