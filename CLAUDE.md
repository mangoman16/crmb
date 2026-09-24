# Working on this codebase

A self-hosted CRM for one badminton coach and her students. It is used by a
trainer who is not technical, on a phone, with real families' data in it. That
is the whole design constraint, and it decides most arguments.

## This session is the project manager

The work on this project is split across eleven agents in `.claude/agents/`, each with a
narrow remit and a tool list that matches it. **The main session does not do the
specialist work itself.** It breaks a request into tasks, delegates, and puts the results
together. Doing a specialist's job in the main session is how a boundary quietly stops
being a boundary.

| Agent | Does | May write |
| --- | --- | --- |
| `architect` | structure, boundaries, conventions, dependencies | `docs/decisions/` only |
| `ui-ux-designer` | specifies a screen or flow before it is built; measures | nothing |
| `database-engineer` | migrations, the runner, the SQLite translation, backups | `database/`, `app/schema.php` |
| `backend-dev` | PHP in `app/`, `bin/`, `public/` | code |
| `frontend-dev` | `views/`, `app/ui.php`, `app.css`, `app.js` | code |
| `qa-tester` | runs the suites, writes the missing ones, walks `TESTING.md` | `tests/`, `TESTING.md` |
| `mobile-tester` | measures at 320 and 390, both roles, light and dark | nothing |
| `code-reviewer` | the conventions below, applied to a diff | nothing |
| `security-reviewer` | injection, escaping, authorisation, secrets, uploads | nothing |
| `devops-engineer` | install, update, release, console, CI if ever adopted | delivery files |
| `docs-writer` | README, INSTALL, UPDATING, VALIDATION, CHANGELOG, … | documents |

The stack, the conventions and the commands each agent needs are the rest of this file —
they are not repeated in the agent prompts, and they should not be repeated here either.

### The workflow

1. **The project manager breaks the request into tasks and shows the plan** before any of
   it starts.
2. **`architect`** approves the approach if the change touches structure: a new file in
   `app/`, anything crossing the view/action boundary, the schema, or a dependency.
3. **`ui-ux-designer`** specifies the screen if the change is visible to anyone.
4. **`database-engineer`, `backend-dev`, `frontend-dev`** implement, in that order where
   more than one is involved — the schema exists before the code that reads it.
5. **`qa-tester` and `mobile-tester`** test. Neither reports a result it did not watch.
6. **`code-reviewer` and `security-reviewer`** review. **Any `FAIL` goes back to the
   implementer**, not to the project manager to argue with.
7. **`docs-writer`** brings the documents back in line with what is now true.
8. **The project manager summarises and proposes the commit message.**

A step whose agent has nothing to do says so and takes no turn. A step is not skipped
because the change looks small — the 36-byte truncation of `app/actions_config.php` looked
small too.

### Git

- **Work on a feature branch.** Never push to `main` directly.
- **Small commits with clear messages**, one change each, in the style already in the log:
  a subject line that says what changed for the operator, then prose explaining why.
- **Never commit secrets.** `config/config.php` holds the database password and the mail
  credentials; `.gitignore` already covers it and `/.env`, and it stays that way. A new
  file that holds a credential goes into `.gitignore` in the commit that creates it.

### Stop and ask

Stop and ask the owner whenever a decision is **ambiguous**, **destructive**, or **changes
the database schema**. Say what you would do and why, and wait. She has no staging copy,
no shell and no way to undo a migration that has already run against her families' data —
the cost of asking is a minute and the cost of guessing is hers to carry.

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
- **The operator has no shell.** She installs by opening `setup.php` and updates
  by uploading files, so a change that needs a command run afterwards is not
  finished. The migration runner in `app/schema.php` is the one copy the
  installer, the console and the first request after an upload all use; adding a
  second path for any of them is how they start disagreeing.
- **An update refuses rather than guesses.** Older files than the database, an
  incomplete upload, a database it could not back up first, or a result with
  fewer rows in `schema_guarded_tables()` than it started with: each one keeps
  the portal closed. A migration that genuinely has to remove rows needs that
  guard widened in the same commit, with the reason written down.

## Before you say something works

```bash
php tests/run.php                  # the whole suite, seconds, no database server needed
php tests/run.php billing views    # one or more suites
php -l <file>                      # after any edit that a test might not reach
```

[TESTING.md](TESTING.md) is the other half: the feature list with the steps to
walk by hand, for the things no suite can reach — a PDF opened in a real reader,
mail through a real provider, a phone at 320px. Add the checks for what you
build to it in the same commit.

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

The default suite runs against a SQLite translation of the schema
(`tests/sqlite-driver.php`). **That proves the PHP logic, not the SQL dialect.**
It also prints, at the end of a run, whatever it could not cover.

The whole suite has been run against **MariaDB 10.11.14**, where all eighteen
migrations apply and all assertions pass. Before claiming a change works on the
real engine, run it there yourself:

```bash
tests/mariadb-local.sh            # throwaway server, whole suite, then shuts down
tests/mariadb-local.sh billing    # one suite
```

**MySQL 8.0 itself is still unverified** — MariaDB is one of the two supported
engines, not both. Say which engine you actually ran on rather than implying
coverage that does not exist: the operator is making decisions about her
family's data based on what you claim.

## The person using this

A middle-aged trainer, iOS user, not technical. Mostly on a phone.

- **Mobile first, and measured.** 44pt minimum touch targets. Check 320px, not
  just 390px. Measure the dense screens rather than eyeballing them — the
  attendance control had to be rebuilt after five labels were found overlapping.
- **Plain language, no jargon**, in German by default.
- **Nothing should look machine-written to a parent.** Students and parents see
  a deliberately small portion of the app.
- **Destructive actions need a way back**, not just a confirmation box.
