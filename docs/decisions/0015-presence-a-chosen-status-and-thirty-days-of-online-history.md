---
status: accepted
date: 2026-09-29
---

# 0015. Presence: a chosen status, and thirty days of when somebody was online

> **Updated 2026-09-29, twice.**
>
> - **The owner's answer about families.** Presence is for staff only. A family sees no dot,
>   not even their own, and no status block. Their account menu holds „Mein Konto" and
>   „Abmelden". Families' online periods are still recorded, and staff still see them.
> - **What `backend-dev` shipped.**
>   - `presence_visible_to()` is simply `is_staff($viewer)`.
>   - The `max()` chaining of thresholds is gone.
>   - Two helpers were added, `presence_days()` and `presence_recorded_since()`.
>   - A new core helper, `form_return()`.
>
> The sections below describe the code as it is. See "Families: decided" and "What shipped
> beyond the first draft".

## Context

The owner wants a coloured dot on the avatar in the top bar:

- green: online;
- blue: recently online;
- yellow: away;
- grey: offline for longer.

She also wants a status the person chooses themselves, and a record of when each account
was online over the last 30 days, for trainer and administrator, then pruned. She **approved
the schema change** (migration 020). Her words were:

- „trainer and admin can see the history of when last online … 30 days";
- on a hidden status: „administrator should see when last online".

What existed before:

- `accounts.last_seen_at` (migration 004), written by `touch_last_seen()` in `app/auth.php`
  at most once a minute, on the counter connection (`run_counter()`), so an action that
  rolls back does not erase the visit;
- `is_online()`, which compared it with `online_window_minutes` (default 5) and was used
  only by `views/accounts.php`.

Two facts shape the design:

- **Only staff see a dot.** Staff see their own and everybody else's. A family sees none,
  not even their own (the owner, 2026-09-29). A chosen status can therefore only change what
  **staff** see, and only staff choose one.
- **An existing fault.** While staff look through a family's eyes, `$_SESSION['user_id']` is
  the family's, and `touch_last_seen($user)` records **the family** as online.

## Decision

### Schema: `database/migrations/020_when_somebody_was_online.sql` (shipped)

```sql
CREATE TABLE IF NOT EXISTS online_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    hidden TINYINT NOT NULL DEFAULT 0,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX online_period_of_account (account_id, last_seen_at),
    INDEX online_period_age (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE accounts ADD COLUMN presence VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER last_seen_at;
```

- **Restartable order.** The table comes first, with `IF NOT EXISTS`. The `ALTER` comes last,
  because it is the one statement that cannot run twice. A run that stops partway starts
  again from the first statement on the next page view, and nothing that follows the `ALTER`
  has to be got past.
- **`TINYINT`, not `TINYINT(1)`.** That is the house style of the existing flag columns.
- **`presence`** is one of:
  - `auto`, shown from activity;
  - `away`, „Abwesend";
  - `hidden`, „Als offline anzeigen".

  An unknown value is read as `auto`. There is no backfill.
- **One row per period online.** A touch extends the newest row with the same `hidden` flag
  whose `last_seen_at` is within the online window plus 60 s. Otherwise it starts a new row.
  Because `hidden` is per period, choosing „Als offline anzeigen" starts a new period.
- **Index names** are unique across the schema, because SQLite's index names are global.
- **Not guarded.** `online_periods` is **not** in `schema_guarded_tables()`: it loses rows
  every night by design, and a prune between an update's before-count and after-count would
  keep the portal closed for nothing a family would miss. `accounts` is guarded already.
- **The file name is load-bearing.** `presence_recorded_since()` reads this migration's
  `applied_at` from `schema_migrations` by its name. ADR 0004 guarantees a shipped
  migration is never renamed.
- Migration 021, the newsletter default, is ADR 0018.

### Code: `app/presence.php` (shipped)

`app/presence.php` is required directly after `shell.php`. It needs:

- `run_counter()`, `rows()` and `scalar()` from core;
- `setting()` from defaults;
- `is_staff()` and `is_admin()` from auth;
- `impersonator()` from shell.

Nothing earlier calls it. Only the router, the actions, `app/ui.php`, the views and
`prune_expired()` in the later `tick.php` do, all at request time.

`touch_last_seen()` and `is_online()` **move out of `app/auth.php` and are deleted there**.

`presence.php` holds:

- **`presence_choices(): array`**: `auto`, `away` and `hidden`, with their labels through
  `t()`.
- **`presence_choice(array $account): string`**: the stored choice, or `auto` for anything
  that is not one of the three.
  - It is the same for every role. A family's column stays `auto`, because the only writer
    of `accounts.presence` is `presence_save`, and that is staff-only (below).
- **`presence_bands(): array`**: `online`, `recent` and `away` in seconds.
  - Each is measured **from the last activity**, not from the end of the band before it.
  - `online` is at least one minute.
  - There is **no `max()` chaining** (see "What shipped").
- **`presence_history_days(): int`**: the setting, clamped to 1–30 here as well as on the
  form. A value written into the settings table by hand still cannot keep more.
- **`presence_touch(array $user): void`**
  - It has the same once-a-minute throttle through `$_SESSION['seen_written']`.
  - It records `impersonator() ?? $user`, which **fixes the impersonation fault**.
  - The `hidden` flag it writes is `presence_choice($who) === 'hidden'`.
  - All its writes use `run_counter()`:
    1. `UPDATE accounts SET last_seen_at=?`
    2. `SELECT id FROM online_periods WHERE account_id=? AND hidden=? AND last_seen_at>=? ORDER BY last_seen_at DESC LIMIT 1`
    3. `UPDATE … WHERE id=?`, or `INSERT` if step 2 found nothing. A race between two
       devices can leave two overlapping rows, which is harmless.
- **`presence_state(array $subject, ?int $now = null): string`**, the dot as others see it.
  - `hidden` gives `offline` at once. No timestamp gives `offline`.
  - Otherwise the bands are **tested in order**: `online`, then `recent`, then `away`. Past
    all three is `offline`.
  - Then `away` turns `online` or `recent` into `away`.
- **`presence_seconds_since(?string $utc, int $now): ?int`**.
- **`presence_state_label(string $state): string`**.
- **`presence_visible_to(array $viewer, array $subject): bool`** is `is_staff($viewer)`, and
  nothing else. A family is shown no presence at all, **their own included**. Every function
  below that answers about a subject asks this first, so the rule lives in one place.
- **`presence_last_seen_for(array $viewer, array $subject): ?string`**
  - Anybody `presence_visible_to()` refuses gets `null`.
  - Administrators, and a staff member asking about themselves, get
    `accounts.last_seen_at`.
  - Trainers get the same, unless the subject is `hidden`. Then they get the newest
    `last_seen_at` among the subject's `hidden=0` periods, or `null`.
- **`presence_history(array $viewer, array $accountIds, ?int $now = null): array`**
  - It runs one query for the whole list.
  - It refuses the whole request if `presence_visible_to()` refuses any id, which means a
    viewer who is not staff is always refused.
  - It returns periods from `presence_window_start()` onwards, newest first, with every
    asked-for id as a key.
  - Trainers see `hidden=0` rows **and their own hidden rows**. Administrators see every
    row, with `hidden` marked.
- **`presence_window_start(?int $now = null): string`**: the oldest moment of the history
  window, in UTC.
- **`presence_days(array $periods, ?int $now = null): array`**: one account's periods from
  `presence_history()`, laid out as calendar days for the day strip and the list.
  - It covers exactly `presence_history_days()` days, oldest first, ending today in the
    portal's time zone.
  - A period belongs to the local day it started on, so 23:40–00:20 is listed once.
  - Each day's `state` is:
    - `on` if it has a period a trainer would see;
    - `hidden` if all its periods are hidden, which only an administrator's history can
      contain;
    - `none` otherwise.
  - Each period carries its local `from` and `to` as `H:i`.
  - Views print these, and compute none of it themselves.
- **`presence_recorded_since(?int $now = null): ?string`**: when recording began, taken
  from migration 020's `applied_at`, if that falls inside the window, and `null` otherwise.
  - For the first month after the update the history is shorter than it claims.
  - „In den letzten 30 Tagen nicht online" would be untrue about somebody who simply had not
    been recorded yet.
  - With `null` the page says nothing extra.
- **`presence_prune(int $days): int`**, called from `prune_expired()`. It clamps to 30.

The router calls `presence_touch($user)` where it called `touch_last_seen($user)`, but
**not** for `icon`, `manifest`, `brand` and `logo`. It does so for families too, because
their periods are recorded.

`presence_dot(array $viewer, array $subject): string` goes in `app/ui.php`.

- It returns `''` unless `presence_visible_to()`, so a family never gets one, not even in
  their own top bar.
- Otherwise it returns `<span class="presence-dot is-{state}">` holding a `visually-hidden`
  label.
- It joins the `structure` suite's HTML-helper list.
- Views never compute a state themselves.

### Settings (`app/defaults.php`)

| Key | Kind | Default | Bounds | Group |
| --- | --- | --- | --- | --- |
| `online_window_minutes` | `int` (exists) | 5 | 1–120 | `portal`; the label now names green |
| `presence_recent_minutes` | `int` | 60 | 5–1440 | `portal`, advanced |
| `presence_away_hours` | `int` | 24 | 1–720 | `portal`, advanced |
| `presence_history_days` | `int` | 30 | 1–**30** | `system`, advanced |

Retention can be shortened, never lengthened. Longer would be a new decision about
families' data, not a setting.

A band set shorter than the one before it simply never shows. For example, „blau" shorter
than „grün" means no blue. The dot never turns blue and then green again as time passes.

### Who sees what

| | Own dot | Status menu | Others' dot | Others' "last online" | Others' 30-day history |
| --- | --- | --- | --- | --- | --- |
| Family | no | no | no | no | no |
| Trainer | yes | yes | yes, as chosen (`hidden` is grey) | yes; for a `hidden` account, only up to when they hid | yes, `hidden` periods left out |
| Administrator | yes | yes | yes, as chosen | always the true time | every period, hidden ones marked |

A trainer's own history includes their own hidden periods: it is their own business, and
hiding them from themselves would make the list look wrong to the one person who knows
better.

„Als offline anzeigen" therefore means that trainers see you as offline, while
administrators still see when you were online. The status menu (ADR 0016) says so in those
words.

**This split is the default, and the owner can change it.** She said trainer and admin both
see the history. Because nobody outside staff sees a dot, this split is the only reading
under which „Als offline anzeigen" does anything. If she decides instead that trainers see
through it:

- `presence_history()` stops filtering `hidden=0` for trainers;
- `presence_last_seen_for()` returns the true time to all staff;
- the menu sentence changes, or `hidden` is dropped from `presence_choices()`, because it
  would no longer hide anything.

That change needs no migration.

**Families: decided (the owner, 2026-09-29).** The own-status dot and the status block in
the account menu are **for staff only**. A family's account menu holds „Mein Konto" and
„Abmelden".

- `presence_visible_to()` is `is_staff($viewer)`, so `presence_dot()`,
  `presence_last_seen_for()` and `presence_history()` give a family nothing.
- The menu's status block is shown only to staff (ADR 0016).
- `presence_save` begins with `require_staff()`, so a stale page or a hand-made POST from a
  family is refused before anything is written.
  - While staff view the portal as a family, the session is the family's, so the same line
    refuses. No separate `impersonator()` check is needed.
- **Families' periods are still recorded**, always `hidden=0` because their choice stays
  `auto`. Staff see families' dots, last-online times and history exactly as in the table.

The status is changed by `presence_save` (ADR 0016).

### What shipped beyond the first draft

- **The `max()` chaining of thresholds was dropped as redundant.**
  - The first draft made each band at least the one before it.
  - Because `presence_state()` tests the bands in order, a later band shorter than an
    earlier one is never reached, which gives the same visible result with less code.
  - It is tested: a `recent` band set shorter than `online` shows no blue.
- **`presence_days()` and `presence_recorded_since()`** were added so the history page lays
  out days and states its own start without doing date arithmetic in a view (above).
- **`form_return(string $fallback='dashboard'): array`**, a new helper in `app/core.php`.
  - It returns `[page, params]` for `go()`: the page, record id and tab that `start_form()`
    posts as `return_page`, `return_id` and `return_tab`.
  - A page name not shaped like one (`/^[a-z_]{1,40}$/D`) falls back to `$fallback`.
  - **It is never an open redirect.** `go()` builds the address from `url()`, and the front
    controller also checks the page against its allow-list. The most a forged value can do
    is name a page of this portal.
  - It is used by:
    - `presence_save`;
    - `notifications_read`: the pane is on every page;
    - `public/index.php`'s way back after a refused action.
  - It **fixed a fault.** „Alle gelesen" on a student's page used to return
    `page=student` with no id, which is „Nicht gefunden". It now returns to that student
    and tab.
  - It lives in core because the front controller and actions in three files use it, and it
    needs only `post()`. Nothing earlier than core exists to need it.

## Rejected

**One row per request, or per minute.** A busy month would be tens of thousands of rows, all
saying the same thing.

**Writing the history on the main connection.** A refused save would erase the fact that
the person was here.

**Putting `online_periods` in `schema_guarded_tables()`.** The table shrinks by design, and
an exception list inside the guard is worse than leaving it off.

**`CREATE TABLE` after the `ALTER`, or without `IF NOT EXISTS`.** A run interrupted after the
first statement could then never be retried past it.

**`hidden` hiding everything from everybody.** It contradicts „administrator should see when
last online".

**`hidden` hiding nothing from trainers.** The status would be a label with no effect. This is
the owner's to choose instead; see "Who sees what".

**Storing the hidden flag only on `accounts`.** A trainer's view of past periods would then
change with today's choice.

**Recording the impersonated account.** A trainer checking a family's view would appear as
that child being online at 23:00.

**Retention as an open-ended setting.** It would allow keeping presence data longer than
the notice says.

**Families keeping their own dot but no menu.** The owner answered for both together. A dot
the person cannot change would also invite the question of how to change it.

**Not recording families' periods once they cannot see presence.** Staff still see
families' history. That was the owner's original request, and this answer does not change
it.

**Reading `auto` for every family account, whatever is stored.** It was considered as a
guard against a stale `hidden` hiding a family from trainers. It is not needed: nothing but
the staff-only `presence_save` writes the column, and the `structure` rule below keeps it
that way. It would have been a second rule about who may have a status, beside
`require_staff()`.

**Keeping the `max()` chaining.** It enforced an order that testing the bands in order
already gives.

**Returning to the page name alone after a form sent from anywhere.** That is the
„Nicht gefunden" fault `form_return()` fixed.

## Consequences

- **Owner:**
  - the schema change is approved;
  - families get no dot and no status (decided 2026-09-29);
  - the trainer/admin split is still hers to change, and it needs no migration;
  - the privacy notice must say that when each account was online is kept for 30 days and
    is visible to trainers and administrators. `docs-writer` drafts the sentence; she
    releases it with 020.
- **`qa-tester`:**
  - a rolled-back action leaves the period;
  - touches 2 minutes apart extend one row, and 20 minutes apart start two;
  - switching to `hidden` starts a `hidden=1` row;
  - `presence_state()` over each band and choice, including a later band set shorter than
    an earlier one, which never shows;
  - a trainer's history omits other people's hidden rows and keeps their own, and an
    administrator's omits none;
  - a family asking for any history, including their own, is refused;
  - a family's own top bar has no dot, and their menu has no status block;
  - `presence_save` from a family is refused and writes nothing;
  - a family's visit is still recorded as a `hidden=0` period;
  - `presence_days()` places a period that crosses midnight under the day it started, and
    returns exactly `presence_history_days()` days;
  - `presence_recorded_since()` is the applied time within the window and `null` outside it;
  - `form_return()` returns to the student and tab it was sent from, and falls back for a
    malformed page name;
  - impersonation records the impersonator;
  - prune removes exactly the rows older than the horizon;
  - 020 applies on SQLite, and **run twice after an interruption** it still applies;
  - then `tests/mariadb-local.sh`.
- **`structure`:**
  - `app/presence.php` is in the expected files;
  - `touch_last_seen` and `is_online` are gone from `auth.php`;
  - `presence_dot` is in the HTML-helper list;
  - `presence_save` is the only writer of `accounts.presence`, and it calls
    `require_staff()` before its `UPDATE`.
- **Must not:**
  - show another account's dot, last-online time or history except through
    `presence_visible_to()` / `presence_history()`;
  - show a family any presence, their own included;
  - write presence on the main connection;
  - touch presence from the asset routes;
  - let `presence_history_days` exceed 30;
  - rename migration 020.
