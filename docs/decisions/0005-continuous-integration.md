---
status: proposed
date: 2026-09-22
---

# 0005. What continuous integration is for on a project with no deployment

## Context

There is **no `.github/` directory**. Nothing runs on push. The test suite is good — 22
suites, ~2,443 assertions, ~16s with no database server — and it runs only when somebody
remembers.

That was tolerable with one person committing. It is not tolerable now: work is being
split across several agents, and nothing independent checks their output before it reaches
a branch.

"Deployment" here does not mean what it usually means. There is no server we push to. The
operator installs by opening `setup.php` and updates by uploading files, or by
`bin/update.sh` on a host with a shell. `bin/release.sh` builds the distribution ZIP, and
**no GitHub release has ever been published** — the ZIP the README points at does not
exist.

## Decision — proposed, needs the owner

Add `.github/workflows/test.yml` running on every push and pull request:

1. PHP 8.2 and 8.4, `composer install`, `php tests/run.php` — the fast SQLite run.
2. A MariaDB 10.11 service container, `CRM_TEST_DRIVER=mysql`, the same suite — the run
   that proves the SQL dialect.
3. `php -l` over every changed PHP file, and `composer audit`, which **has never been run
   with network access**.

Explicitly *not* in scope for a first workflow: `tests/mobile.mjs` (needs Chromium and a
running portal), and the two Python integration scripts (need a live SMTP capture server).
Both are named here so their absence is a known gap rather than an oversight.

Whether a tag also builds a release ZIP with `bin/release.sh` is a **separate** question
and deliberately not folded in.

## Rejected

**No CI, keep running it by hand.** Works for one person who already does. Does not
survive several contributors, and the value of a suite nobody is forced to run decays.

**One job, SQLite only.** Cheap and fast, and it would have missed every dialect problem
the MariaDB run exists to catch.

**Running everything, including the browser and SMTP tests.** A workflow that is slow and
flaky gets ignored, and being ignored is worse than being absent.

## Consequences

- `devops-engineer` owns this file once the shape is agreed.
- Until it exists, no automated check stands between an agent's work and a branch, and
  every claim of "tests pass" in this repository rests on somebody having run them locally
  and said so honestly.

## Open for the owner

1. GitHub Actions on push and PR — yes?
2. Both engines, or SQLite only to start?
3. Should a tag build the release ZIP, or is that a later decision?
