---
status: accepted
date: 2026-09-29
---

# 0015. Presence: a chosen status, and thirty days of when somebody was online

## Context

The owner wants a coloured dot on the avatar in the top bar:

- green: online;
- blue: recently online;
- yellow: away;
- grey: offline for longer.

She also wants two things written down:

- a status the person chooses themselves;
- a record of when each account was online over the last 30 days, for trainer and
  administrator, then pruned.

She **approved the schema change** (migration 020). Her words:

- „trainer and admin can see the history of when last online … 30 days";
- on a hidden status: „administrator should see when last online".

What exists today:

- `accounts.last_seen_at` (migration 004) is written by `touch_last_seen()` in
  `app/auth.php`, at most once a minute.
  - It is written on the counter connection (`run_counter()`), so an action that rolls back
    does not erase the visit.
- `is_online()` compares it with `online_window_minutes` (default 5). Only
  `views/accounts.php` uses it.

Two facts shape the design:

- **Only the person and staff see a dot.** A family never sees anybody else's. A status
  the person chooses can therefore only change what **staff** see.
- **An existing fault.** While staff look through a family's eyes (`start_impersonation()`),
  `$_SESSION['user_id']` is the family's. `touch_last_seen($user)` then records **the
  family** as online. Any history built on top would inherit this.

## Decision

### Schema: `database/migrations/020_when_somebody_was_online.sql`

```sql
ALTER TABLE accounts ADD COLUMN presence VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER last_seen_at;

CREATE TABLE online_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX online_period_of_account (account_id, last_seen_at),
    INDEX online_period_age (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `presence` holds one of three values:
  - `auto`: shown from activity;
  - `away`: „Abwesend";
  - `hidden`: „Als offline anzeigen".

  An unknown value is read as `auto`. There is no backfill, because there is no history to
  backfill.
- **One row per period online**, not one per request. A touch extends the newest row with
  the same `hidden` flag whose `last_seen_at` is within the online window plus 60 seconds.
  Otherwise it starts a new row.
  - A long evening is one row, so a year of use stays in the thousands of rows.
  - `hidden` is per period, so choosing „Als offline anzeigen" starts a new period, and a
    period is wholly one or the other.
- The index names are unique across the schema, because SQLite's index namespace is global.
  `database-engineer` makes the statements safe to re-run under the runner's rules, as 019
  describes.
- **`online_periods` is not added to `schema_guarded_tables()`.** It loses rows by design,
  every night. A prune that lands between an update's before-count and after-count would
  keep the portal closed for no reason. It is a log that expires, not something a family
  would miss. `accounts`, which gains the column, is already guarded.
- Migration 021 (the newsletter default) is a separate decision, recorded in ADR 0018.

### Code: `app/presence.php`, new

It is required directly after `shell.php`. It needs:

- `run_counter()` and `rows()` from core;
- `setting()` from defaults;
- `is_staff()` and `is_admin()` from auth;
- `impersonator()` from shell.

Nothing earlier calls it. Only the router, the actions, `app/ui.php`, the views and
`prune_expired()` in `tick.php` (which loads later) do, all at request time.

`touch_last_seen()` and `is_online()` **move out of `app/auth.php` and are deleted there**.
The rule lives in one place. It holds:

- `presence_choices(): array`. It returns `auto`, `away` and `hidden`, each with a label
  through `t()`.
- `presence_choice(array $account): string`.
- `presence_touch(array $user): void`.
  - It replaces `touch_last_seen()`, with the same once-a-minute throttle through
    `$_SESSION['seen_written']`.
  - It records `impersonator() ?? $user`, which **fixes the impersonation fault**: the
    person really at the keyboard is the one who was online.
  - All writes go through `run_counter()`, in this order:
    1. `UPDATE accounts SET last_seen_at=?`;
    2. `SELECT id FROM online_periods WHERE account_id=? AND hidden=? AND last_seen_at>=? ORDER BY last_seen_at DESC LIMIT 1`;
    3. `UPDATE … WHERE id=?`, or `INSERT` when step 2 found nothing.

    A race between two devices can leave two overlapping rows, which is harmless.
- `presence_state(array $subject, ?int $now = null): string`. It returns `online`,
  `recent`, `away` or `offline`. This is the dot as **others** see it.
  - The time since `last_seen_at` is sorted into one of four bands:
    - within `online_window_minutes`: `online`;
    - within `presence_recent_minutes`: `recent`;
    - within `presence_away_hours`: `away`;
    - beyond that: `offline`.
  - Each threshold is taken as at least the one before it (`max()`), so settings saved in
    the wrong order cannot produce nonsense.
  - The chosen status is then applied:
    - `hidden` gives `offline`;
    - `away` turns `online` or `recent` into `away`.
- `presence_state_label(string $state): string`.
- `presence_visible_to(array $viewer, array $subject): bool`. It is true when the viewer
  is the subject or `is_staff($viewer)`.
- `presence_last_seen_for(array $viewer, array $subject): ?string`. It returns:
  - `accounts.last_seen_at` for the subject themselves or an administrator;
  - the same for a trainer when the subject is not `hidden`;
  - for a trainer when the subject is `hidden`, the newest `last_seen_at` of the subject's
    `hidden=0` periods, or `null`;
  - `null` for a family viewing anybody else.
- `presence_history(array $viewer, array $accountIds): array`. It is **one query** for a
  whole list, never one per account. It refuses a viewer who is neither staff nor the
  subject. It returns periods from the last `presence_history_days`:
  - trainers get `hidden=0` rows only;
  - administrators and the subject get every row, with `hidden` marked.
- `presence_prune(int $days): int`. It runs
  `DELETE FROM online_periods WHERE last_seen_at < ?` on the main connection, and is called
  from `prune_expired()`.

The router calls `presence_touch($user)` where it calls `touch_last_seen($user)` today, and
**not** for `icon`, `manifest`, `brand` and `logo`. A browser fetches those on its own, and
they are not a person being here.

`presence_dot(array $viewer, array $subject): string` goes in `app/ui.php`, owned by
`frontend-dev`. It returns `''` unless `presence_visible_to()`. Otherwise it returns
`<span class="presence-dot is-{state}">` holding a `visually-hidden` label: the colour is
never the only signal. It is added to the `structure` suite's list of helpers that return
HTML. **Views never compute a state themselves.**

### Settings (`app/defaults.php`)

| Key | Kind | Default | Bounds | Group |
| --- | --- | --- | --- | --- |
| `online_window_minutes` | `int` (exists) | 5 | 1–120 | `portal`. Its label now names green. |
| `presence_recent_minutes` | `int` | 60 | 5–1440 | `portal`, advanced |
| `presence_away_hours` | `int` | 24 | 1–720 | `portal`, advanced |
| `presence_history_days` | `int` | 30 | 1–**30** | `system`, advanced |

The retention can be shortened, never lengthened. 30 days is what the owner approved.
Longer is a new decision about families' data, not a setting.

### Who sees what

| | Own dot | Others' dot | Others' "last online" | Others' 30-day history |
| --- | --- | --- | --- | --- |
| Family | yes | no | no | no |
| Trainer | yes | yes, as chosen (`hidden` is grey) | yes; for a `hidden` account, only up to when they hid | yes, `hidden` periods left out |
| Administrator | yes | yes, as chosen | always the true time | every period, hidden ones marked |

„Als offline anzeigen" therefore means that trainers see you as offline and administrators
still see when you were online. The status menu (ADR 0016) says so in those words. It
promises nothing it does not do.

**This split is the default, and the owner can change it.** She said trainer and admin both
see the 30-day history. Nobody outside staff sees a dot, so the split is the only reading
under which „Als offline anzeigen" does anything. If she decides instead that trainers see
through it as administrators do, the code change is small:

- `presence_history()` stops filtering `hidden=0` for trainers;
- `presence_last_seen_for()` returns the true time to all staff;
- the menu's sentence changes, or the `hidden` choice is dropped from
  `presence_choices()` altogether, because it would no longer hide anything.

The per-period `hidden` column stays useful either way, because it records what was true at
the time. Changing the split needs no migration.

The status is changed by `presence_save` (ADR 0016).

## Rejected

**One row per request, or per minute.** A busy month is tens of thousands of rows, all
saying the same thing, pruned every night.

**Writing the history on the main connection.** A refused save would erase the fact that
the person was here, which is exactly why `last_seen_at` went to the counter connection.

**Putting `online_periods` in `schema_guarded_tables()`.** The table shrinks by design.
Widening the guard to allow that would mean an exception list inside the guard. Leaving it
off costs nothing a family would notice.

**`hidden` hiding everything from everybody.** It contradicts the owner's „administrator
should see when last online". And because families see no dots, a status that hid nothing
from trainers either would do nothing at all.

**`hidden` hiding nothing from trainers.** Then the status is a label with no effect, and
the menu would be promising something it does not do. This is the owner's to choose
instead; see "Who sees what".

**Storing the hidden flag only on `accounts`.** A trainer would then see, or not see, past
periods depending on today's choice. Per-period `hidden` records what was true at the time.

**Recording the impersonated account.** A trainer checking a family's view would appear as
that child being online at 23:00.

**Retention as an open-ended setting.** It would allow keeping presence data longer than
the notice families were given says.

## Consequences

- **Schema change: approved by the owner.** The value and table names above are final
  unless she objects.
- **The trainer/admin split is her call** (see "Who sees what"). Implement the default. A
  change later needs no migration.
- **The privacy notice must say this is recorded:** when each account was online, kept 30
  days, visible to trainers and administrators. The notice is her text. `docs-writer`
  drafts the sentence, and she releases it with the release that ships 020.
- **`qa-tester`:**
  - a rolled-back action leaves the period;
  - touches 2 minutes apart extend one row, and 20 minutes apart start a second;
  - switching to `hidden` starts a new row with `hidden=1`;
  - `presence_state()` over each band and choice;
  - a trainer's `presence_history()` omits hidden rows, and an administrator's does not;
  - a family calling `presence_history()` for another account is refused;
  - impersonation records the impersonator;
  - `presence_prune()` removes exactly the rows older than the horizon;
  - the `migrations` suite applies 020 on SQLite.
  - Then run `tests/mariadb-local.sh` for 020 on the real engine.
- **`structure`:**
  - `app/presence.php` is in the expected files;
  - `touch_last_seen` and `is_online` are no longer defined in `auth.php`;
  - `presence_dot` is in the HTML-helper list.
- **Must not:**
  - render another account's dot, last-online time or history to a non-staff viewer except
    through `presence_visible_to()` / `presence_history()`;
  - write presence on the main connection;
  - touch presence from the asset routes;
  - let `presence_history_days` exceed 30.
