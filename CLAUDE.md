# Working on this codebase

A self-hosted portal for a badminton club: students, courses, attendance, fees and invoices,
and a chat. It is in **beta**: no portal holds real families' data yet.

- **The owner** is the administrator and builds the portal together with Claude. Use
  they/them for the owner.
- **The trainer** is not technical and works on a phone, an iPhone.
- **Students and families** are not technical, work on phones and read little. They see a
  deliberately small part of the portal.

## Which document, when

| When | Read |
| --- | --- |
| Starting a session | [ROADMAP.md](ROADMAP.md) — the plan, what is decided, what is open |
| Before a structural change | [docs/decisions/README.md](docs/decisions/README.md), then the records it lists as holding; ADR 0026 holds the beta rules and what was removed |
| Before building a screen | its specification in `docs/design/` |
| Before a schema change | ADR 0004 and [UPDATING.md](UPDATING.md) |
| After building anything | [TESTING.md](TESTING.md), and the unreleased section of [CHANGELOG.md](CHANGELOG.md) |
| Before a release | [VALIDATION.md](VALIDATION.md), [UPDATING.md](UPDATING.md), [INSTALL.md](INSTALL.md) and `VERSION` |
| A change that touches personal data | `docs/privacy-draft-de.txt` and `docs/privacy-draft-en.txt` |
| Tests | [tests/README.md](tests/README.md) |

ROADMAP.md is updated in the commit that finishes or adds a task.

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
| `database-engineer` | migrations, the runner, the test databases, backups | `database/`, `app/schema.php` |
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
  a subject line that says what changed for the people using the portal, then prose
  explaining why.
- **Never commit secrets.** `config/config.php` holds the database password and the mail
  credentials; `.gitignore` already covers it and `/.env`, and it stays that way. A new
  file that holds a credential goes into `.gitignore` in the commit that creates it.

### Beta, and when to ask the owner

Schema changes and larger changes no longer wait for the owner's approval: the project
manager decides, and writes under „For the owner to test or deploy" in ROADMAP.md what the
owner has to test or deploy. Ask the owner when a decision is genuinely theirs — what the
portal is for, what the trainer or the families should see, what may go, anything that costs
money or takes back something they asked for — and say what you would do and why. What protects any install still holds: the conventions below, the
checksum ledger, an update that refuses rather than guesses, and a backup before every
update.

## Leave the code better than you found it — without being asked

Code quality is the priority here, above speed of delivery. When you touch a
file, improve what you touch: this is standing permission, not something to ask
about each time.

What that means concretely:

- **Fix the cause, not the symptom.** A bug that could recur elsewhere gets the
  general fix and a test that fails without it.
- **Remove duplication when you are already in the file.** Two copies of a rule
  will diverge; the second one is always the one that gets forgotten.
- **Name things after what they mean to the people using it**, not after their
  mechanism. `billing_free_period()`, not `calc_bp()`.
- **Delete dead code** rather than commenting it out. Git remembers it.
- **Write the comment that says why**, never the one that restates the code. If
  a line looks wrong but is right, that is the comment worth having.
- **Do not widen scope silently.** Refactoring the file you are in is expected;
  rewriting a subsystem nobody asked about is not. If a larger change is the
  right call, say so and make the case.

What "better" is not: a framework, a build step, a new dependency, or a
rewrite of something that works. Every dependency is a thing somebody must keep
patched. The bar for adding one is high and the reason goes in the commit.

### The owner's rule: the best code is the code never written

Understand the problem first: read the task and the code it touches, and trace
the real flow end to end. Then stop at the first rung that holds: does it need
to be built at all; does it already exist here (reuse the helper); does PHP or
the browser already do it; does an installed dependency do it; can it be one
line; only then write the least code that works. Deletion over addition, boring
over clever, the fewest files, no abstraction nobody asked for. A bug is fixed
in the shared function once, not in each caller. A deliberate shortcut with a
known ceiling gets a `ponytail:` comment naming the ceiling and the way up.

None of this excuses less care for input validation, errors that could lose
data, security or accessibility — and logic that is not trivial leaves one
check behind that fails if it breaks.

## Conventions this codebase already follows

Match them; do not introduce a second style alongside one that works.

- **PHP 8.2+, procedural, server-rendered.** No JS framework, no build step.
  Progressive enhancement only — every page works without JavaScript.
- **One front controller**, `public/index.php?page=...`, dispatching to
  `views/<page>.php`. Actions chain through `app/actions*.php`.
- **Every query is parameterised.** `ATTR_EMULATE_PREPARES` is off. If a table
  or column name must be interpolated, validate it against an allowlist first —
  `tracked_entity()` before `entity_snapshot()` puts a table name into SQL
  (`app/history.php`), and `sql_name()` for any other identifier.
- **Every write goes through `transactional()`** (`app/tx.php`), which nests
  safely via savepoints. Writes worth recording go through `tracked()` or
  `tracked_insert()` (`app/history.php`), so „Änderungen" shows what changed,
  field by field, and who changed it. It informs and puts nothing back — there is
  no undo, and the `history` suite checks that `revert_version()` stays gone — so
  a destructive action needs a way back of its own.
- **Every value printed to a page goes through `e()`.** Truncating a string or
  looking a code up in a settings array does not make it safe. The `structure`
  test suite enforces this; if you add an escaping helper, add it there too.
- **Money is integer cents**, always. Never a float.
- **Timestamps are UTC** via `now()`; display goes through `fmt_date()` /
  `fmt_datetime()`, which convert. DATE columns are calendar dates and are
  deliberately not shifted.
- **All text people read is bilingual** via `t('Deutsch', 'English')`.
  German is the default.
- **Every setting is declared once** in `app/defaults.php` with a type and a
  default, so no value is ever undefined and a new setting needs no migration.
- **Schema changes are new numbered files** in `database/migrations/`. Never
  edit one that has shipped — the ledger stores checksums and will refuse it.
  Give every new column a `DEFAULT` so rows written by the previous version
  cannot leave a NULL the new code has to guess about.
- **Whoever runs a portal may have no shell.** A portal is installed by opening
  `setup.php` and updated by uploading files, so a change that needs a command
  run afterwards is not finished. The migration runner in `app/schema.php` is the
  one copy the installer, the console and the first request after an upload all
  use; adding a second path for any of them is how they start disagreeing.
- **An update refuses rather than guesses.** Older files than the database, an
  incomplete upload, a database it could not back up first, or a result with
  fewer rows in `schema_guarded_tables()` than it started with: each one keeps
  the portal closed. The counts from before an update stay in
  `storage/update-unfinished.json` from its first migration until a run
  passes, so a loss keeps the portal closed on every request, not just the
  first. While that file exists nothing changes the database but the update:
  no migration runs on top of a loss, nobody gets in, administrators included,
  and the console runs nothing that writes (ADR 0027). The portal reopens by
  itself once every table still on the list has its rows back, or a release
  takes the table off the list. A migration that genuinely has to remove rows
  takes its table off that list in the same commit, with the reason written
  down.

## Before you say something works

```bash
tests/mariadb-local.sh                 # the whole suite on a throwaway MariaDB, about four minutes
tests/mariadb-local.sh billing views   # one or more suites
tests/mariadb-local.sh robustness      # every action, every role, unexpected values
tests/e2e.sh                           # the first evening, end to end, in a real browser
php -l <file>                          # after any edit that a test might not reach
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

The suite runs on **MariaDB only**. There is no SQLite translation any more:
`php tests/run.php` refuses without a `*_test` database, and
`tests/mariadb-local.sh` starts a throwaway one. A run prints, at the end,
whatever it could not cover. The suite and the browser walk have been run on
**MariaDB 10.11.14 with PHP 8.4.26** ([VALIDATION.md](VALIDATION.md) has each run
and its date); the server the portal is meant for runs MariaDB 10.11.19 with PHP
8.4.24.

**MySQL 8.0 has never been run** — MariaDB is one of the two engines meant to
work, not both. Say which engine you actually ran on rather than implying
coverage that does not exist: the owner decides what to rely on from what you
claim.

## The people using it

- **Mobile first, and measured.** 44pt minimum touch targets. Check 320px, not
  just 390px, as staff and as a family, light and dark. Measure the dense screens rather than eyeballing them — the
  attendance control had to be rebuilt after five labels were found overlapping.
- **Plain language, no jargon**, in German by default.
- **Wizards and visual guidance** for anything with more than one step: one
  question per step, the progress shown, an icon beside a short word. People
  here read little.
- **Expect any value from anyone**: a missing field, an array where a string
  belongs, a huge string, a negative number, an impossible date, somebody else's
  id, a double tap, the Back button. Every input is checked where it enters; a
  refusal is one plain sentence on the same page; never a 500, a blank page or a
  PHP warning. The `robustness` suite sends such values to every action as every
  role and draws every page with them in the address; it reads the actions, the
  fields and the pages from the code, so a new one is covered without being
  listed.
- **Another club could run it too.** Nothing specific to one club is hard-coded:
  it is a setting declared in `app/defaults.php`, and the settings stay
  comprehensive. No fields defined by users: a new field is a real column.
- **Nothing should look machine-written to a family.** Students and families
  see a deliberately small part of the portal.
- **Destructive actions need a way back**, not just a confirmation box.
