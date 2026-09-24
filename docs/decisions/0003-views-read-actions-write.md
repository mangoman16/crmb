---
status: accepted
date: 2026-09-22
---

# 0003. Views read, actions write, and every write is one transaction

## Context

With no framework there is no controller layer handed to you, so "where does a write
live" has to be decided once and held, or it ends up wherever the person was typing.

What holds today, verified rather than assumed:

- 14 of the 30 files in `views/` query the database. **None** contains an `INSERT`,
  `UPDATE` or `DELETE`.
- Every write is a `case '…':` in one of four dispatchers — `app/actions.php`,
  `actions_config.php`, `actions_messages.php`, `actions_settings.php` — 68 cases in all,
  reached by one POST through `public/index.php`.
- `transactional()` (`app/tx.php`) wraps writes, nesting by savepoint. Writes worth undoing
  also go through `tracked()` (`app/history.php`) so a mis-tap can be reversed.
- Interpolated identifiers pass `sql_name()` (`app/core.php`); values are always bound and
  `ATTR_EMULATE_PREPARES` is off.
- The `structure` suite asserts that every offered form action has a handler and every
  handler is offered by some view, so neither side can drift into being unreachable.

## Decision

A write happens in an action, inside `transactional()`, or it does not happen. A view
renders and may read; it never mutates. A GET never changes data.

New behaviour that writes adds a `case` to the dispatcher that already owns that area —
`actions.php` for students, contacts and accounts; `actions_config.php` for courses,
tariffs and enrolments; `actions_messages.php` for conversations; `actions_settings.php`
for settings, SMTP and duplication. A fifth dispatcher needs an ADR.

## Rejected

**Writing from views where it is convenient.** It is convenient exactly once. After that
nobody can answer "what can change this row", and a `structure` rule that counts handlers
has nothing to count.

**A router with controller classes.** It would formalise what four `switch` statements
already do, and the switch is greppable: one `case 'student_save':` is the whole answer to
where a student is saved.

## Consequences

- `backend-dev` adds cases to existing dispatchers and writes the suite rule alongside.
- A form in a view posts to a named action; the pair is enforced by the `structure` suite.
- Anything reading `$_POST` outside an action, or writing outside `transactional()`, is a
  FAIL for `code-reviewer` with this ADR as grounds.
- The four files are long, and that is accepted: they are a dispatch table, and a dispatch
  table is easier to read whole than split across a directory.
