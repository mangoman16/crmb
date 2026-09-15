# Working on this codebase

A self-hosted CRM for one badminton coach and her students. It is used by a
trainer who is not technical, on a phone, with real families' data in it. That
is the whole design constraint, and it decides most arguments.

## Leave the code better than you found it — without being asked

Code quality is the priority here, above speed of delivery. When you touch a
file, improve what you touch: this is standing permission, not something to ask
about each time.

What that means concretely:

- **Fix the cause, not the symptom.** A bug that could recur elsewhere gets the
  general fix and a test that fails without it.
- **Remove duplication when you are already in the file.** Two copies of a rule
  will diverge; the second one is always the one that gets forgotten.
- **Name things after what they mean to the operator**, not after their
  mechanism. `billing_free_period()`, not `calc_bp()`.
- **Delete dead code** rather than commenting it out. Git remembers it.
- **Write the comment that says why**, never the one that restates the code. If
  a line looks wrong but is right, that is the comment worth having.
- **Do not widen scope silently.** Refactoring the file you are in is expected;
  rewriting a subsystem nobody asked about is not. If a larger change is the
  right call, say so and make the case.

What "better" is not: a framework, a build step, a new dependency, or a
rewrite of something that works. Every dependency is a thing she must keep
patched. The bar for adding one is high and the reason goes in the commit.

## Conventions this codebase already follows

Match them; do not introduce a second style alongside one that works.

- **PHP 8.2+, procedural, server-rendered.** No JS framework, no build step.
  Progressive enhancement only — every page works without JavaScript.
- **One front controller**, `public/index.php?page=...`, dispatching to
  `views/<page>.php`. Actions chain through `app/actions*.php`.
- **Every query is parameterised.** `ATTR_EMULATE_PREPARES` is off. If a table
  or column name must be interpolated, validate it against an allowlist first —
  see `tracked_entities()` and `revert_version()` for the pattern.
- **Every write goes through `transactional()`** (`app/tx.php`), which nests
  safely via savepoints. Writes worth undoing go through `tracked()`
  (`app/history.php`) so the operator can reverse a mis-tap.
- **Every value printed to a page goes through `e()`.** Truncating a string or
  looking a code up in a settings array does not make it safe. The `structure`
  test suite enforces this; if you add an escaping helper, add it there too.
- **Money is integer cents**, always. Never a float.
- **Timestamps are UTC** via `now()`; display goes through `fmt_date()` /
  `fmt_datetime()`, which convert. DATE columns are calendar dates and are
  deliberately not shifted.
- **All operator-facing text is bilingual** via `t('Deutsch', 'English')`.
  German is the default.
- **Every setting is declared once** in `app/defaults.php` with a type and a
  default, so no value is ever undefined and a new setting needs no migration.
- **Schema changes are new numbered files** in `database/migrations/`. Never
  edit one that has shipped — the ledger stores checksums and will refuse it.
  Give every new column a `DEFAULT` so rows written by the previous version
  cannot leave a NULL the new code has to guess about.

## Before you say something works

```bash
php tests/run.php                  # 719 assertions, ~3s, no database server needed
php tests/run.php billing views    # one or more suites
php -l <file>                      # after any edit that a test might not reach
```

Two things that have actually gone wrong here, so check for them:

1. **A test suite can stay green while a file is destroyed.** A bad edit once
   truncated `app/actions_config.php` to 36 bytes and every behavioural test
   still passed, because none of them read it. The `structure` suite exists for
   this. Run the whole suite, not just the one you are working on.
2. **Verify the measurement before trusting the measurement.** A UI sweep once
   reported clean while serving unstyled pages. When a check reports a result
   you like, confirm the check was actually looking at the right thing.

When you add a rule to a test, break the thing it guards and watch it fail. A
test that has never failed has not been tested.

## Honesty about what has been verified

The test suite runs against a SQLite translation of the schema
(`tests/sqlite-driver.php`). **This proves the PHP logic, not the MySQL
dialect.** Migrations 002–006 have never executed against MySQL or MariaDB.
Say so rather than implying coverage that does not exist — the operator is
making decisions about her family's data based on what you claim.

`CRM_TEST_DRIVER=mysql php tests/run.php` runs the same assertions against a
real engine and reports what the SQLite driver could not cover.

## The person using this

A middle-aged trainer, iOS user, not technical. Mostly on a phone.

- **Mobile first, and measured.** 44pt minimum touch targets. Check 320px, not
  just 390px. Measure the dense screens rather than eyeballing them — the
  attendance control had to be rebuilt after five labels were found overlapping.
- **Plain language, no jargon**, in German by default.
- **Nothing should look machine-written to a parent.** Students and parents see
  a deliberately small portion of the app.
- **Destructive actions need a way back**, not just a confirmation box.
