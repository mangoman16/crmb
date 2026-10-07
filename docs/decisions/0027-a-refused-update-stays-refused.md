---
status: accepted
date: 2026-10-07
---

# 0027. A refused update stays refused

> **Accepted** on 2026-10-07. Decided by the architect at the project manager's request (ROADMAP
> item 6), from `database-engineer`'s finding of the same day. It amends ADR 0004, whose note says
> where. It needs no migration, no setting, no new file in `app/` and no dependency.

## Context

`database-engineer`, 2026-10-07, verified with the application's own `schema_apply()` on MariaDB
10.11.14:

> "The guard keeps the portal closed for one request only. At 2788c4f, a file that DELETEs from
> contacts is refused on request 1 and the portal opens on request 2. With today's fix, a DROP of a
> guarded table is refused on request 1 and opens on request 2. Cause: each file is recorded in the
> ledger as it runs; the next request finds nothing pending, counts after the loss, and passes.
> UPDATING.md's 'stays closed' is not true here."

The path a page view takes, read from `app/schema.php` and `app/bootstrap.php`:

- `boot_http()` calls `schema_ensure_current()`. It returns at once when `schema_is_current()` finds
  the stamp file, or else the settings row, matching these files, or when the maintenance flag
  exists.
- Otherwise `schema_apply()` takes the advisory lock and runs `schema_refuse_unsafe()`: older files,
  an incomplete upload, and a backup when something is pending. It counts the guarded tables into
  `$before`, applies each pending migration and records it in `schema_migrations` straight away,
  then calls `schema_verify_counts($before)`. Only after that does it write the version, the
  fingerprint and the stamp.
- A refused run therefore leaves every migration in the ledger, and the stamp and the settings as
  they were. The next page view finds the database not current and runs `schema_apply()` again. It
  finds nothing pending, counts **after** the loss, compares that with itself, passes, and opens the
  portal.

Two more holes of the same shape, found by reading the code, not by running it:

- **A migration that stops partway takes a copy on every page view.** A `SchemaError` leaves its
  migration unrecorded, so it is pending again on the next page view, and `schema_refuse_unsafe()`
  writes a new backup before trying again. `backup_prune()` keeps the newest five (`BACKUP_KEEP`),
  so five page views after the failure, whoever makes them, the copy taken before the update is
  deleted. The page goes on saying it is in `storage/backups`. Each retry also counts again, after
  whatever the stopped migration's first statements removed.
- **A request killed partway**, by a timeout or the memory limit, throws nothing. The next request
  counts again, after whatever ran.

The common cause: the counts that mean "before the update" are taken again by every run, and so is
the copy. Both belong to the update, not to the request.

What constrains the answer:

- Whoever runs a portal may have no shell. They update by uploading files, and the first request
  after the upload runs the migrations. They have the hosting panel's phpMyAdmin and its file
  manager, which is how INSTALL.md already restores a backup.
- The beta holds test data, but the guard exists for when families' money is in the database, and
  ADR 0026 §2 keeps it for every install. It has to be right before real families arrive.
- The owner's rule: the smallest mechanism that is correct. No new subsystem, no new dependency, one
  place.

## Decision

### 1. The numbers from before are kept in a file until the update passes

- **Where:** `storage/update-unfinished.json`, beside `schema.stamp`, the maintenance flag and the
  backups. `schema_unfinished_file()` in `app/schema.php` returns
  `dirname(maintenance_file()) . '/update-unfinished.json'`, so it moves with the shared storage of
  a release-folder layout and with a test run's own folder, as every stored file does.
- **What:** JSON that can be read in the file manager:
  - `started`: when it was written, in UTC (`now()`);
  - `from`: the version the database was on (`database_version()`), and `to`: the version of the
    files (`app_version()`);
  - `backup`: the name of the copy taken before, **without its random part and extension**, such as
    `2026-10-07-123210-vor-update`, or `null` when the update ran with `skip-backup`;
  - `counts`: the rows of each guarded table that existed, as `schema_counts()` returns them;
  - `note`: one sentence in German and one in English saying what the file is, and that UPDATING.md's
    "A refused update" says what to do.

  The copy's random part stays out. This file's name is fixed, so on a server that fails to deny
  `storage/` it can be fetched; the random part is what keeps the copy itself from being fetched
  then.
- **When it is written:** by `schema_apply()`, inside the lock, on a run with the safeguards (not a
  first install), when something is pending and no such file exists. That is after the backup and
  the count, and before the first statement of the first pending migration. It is written beside its
  place and renamed into it, so it is never half there. **If it cannot be written, nothing is
  migrated**, as without a backup. Without the file the guard is blind from the second request on.
- **When it goes:** at the end of the run that passes: after the counts are compared, the defaults
  filled in and the version recorded, still inside the lock. Nothing else in the portal deletes it.
  If it cannot be deleted, that run refuses with a sentence naming it. Left behind, its old numbers
  would refuse a later update for rows somebody removed on purpose since.
- **A file that cannot be read**, not JSON or not this shape, refuses the update. Without the numbers
  there is nothing to compare, and opening would be a guess.
- While the file exists, **an update is unfinished**. `schema_is_unfinished()` says whether it
  exists: one `is_file()`, no query.

**A file, not a settings row.** The restore it has to judge is the one thing it must survive. A
settings row is written after the copy is taken, so importing the copy deletes it. Afterwards the
portal no longer knows an update was refused, cannot check that the import was complete, and with
the new files still in place runs the same migrations again. A file in `storage/` is outside
everything phpMyAdmin touches. It costs a stat per page view where a row would cost a query, needs
no declaration in `app/defaults.php`, and needs nothing not already required: `storage/` must be
writable for the backup, and setup will not install without it.

### 2. While an update is unfinished, every run starts by counting

`schema_apply()`, inside the lock, for every caller: a page view, the console, the installer.

1. **Read the file.** Unreadable: refuse.
2. **Compare first.** Every table in the file that is **still in `schema_guarded_tables()`** is
   counted now, and a table that is gone counts as 0. If one has fewer rows than the file says,
   refuse, before any other check and before any migration. Nothing runs on top of a loss: not a
   newer upload's migrations, not the retry of a migration that stopped partway.
3. **The usual checks**: older files and an incomplete upload, unchanged. **No copy is written.** The
   update has its copy already; one taken now could only be of a half-updated database, and it would
   push the copy from before out of the five that are kept. A `skip-backup` file is consumed as it
   is today when something is pending, so its meaning does not change.
4. **Carry on.** The numbers in the file are this run's "before". Whatever is pending runs, a
   migration that stopped partway from its first statement, as today. At the end the counts are
   compared again, against the same numbers.
5. **Pass:** the defaults, the version and the fingerprint are written, then the file is deleted and
   the stamp written. **Refuse:** the file stays.

Three rules come with it:

- **`schema_is_current()` is false while the file exists**, before it looks at the stamp or the
  settings row. Both are written only by a run that passes, but after an import with the previous
  files either matches the files again, and must not open the portal without the count.
- **Only tables still on the list are compared**, in `schema_verify_counts()`, whichever run is
  comparing. Outside an unfinished update this changes nothing, because the counts were taken with
  the list they are compared against. Inside one it is how a release reopens a portal (§5 b).
- **A table counted before and gone now counts as emptied** (0026 §7), unchanged.

### 3. Nothing else changes the database while an update is unfinished

The numbers are now compared across hours or days, not inside one locked request. Anything else
that adds rows meanwhile can make up a count that fell and hide the loss: `billing:run` on the 1st
adds charges, an administrator adds a child. So while the file exists, only the update writes.

- **Pages.** Every address that goes through `public/index.php` gets the closed page (§4), so no
  action runs. The background work is registered after `boot_http()` and never starts.
- **Maintenance mode lets nobody in.** `boot_http()`'s maintenance gate admits an administrator only
  when no update is unfinished: `!schema_is_unfinished() && is_admin()`. Everybody, administrators
  included, gets the maintenance notice, and nothing is counted or run until the flag is gone. In
  the web flow the flag can only appear during an unfinished update because somebody created it in
  the file manager, and that is where it is removed again; the upload window of 0026 §8 is not
  affected (§6).
- **The console** runs only `check`, `status`, `migrate`, `update`, `maintenance:on` and
  `maintenance:off`, besides `help`, `key` and `version`, which load nothing. Every other command,
  `mail:work`, `billing:run`, `maintenance`, `backup`, `demo:fill`, `create-admin` and any added
  later, stops with one sentence on STDERR and exit code 1, as `mail:work` already does in
  maintenance mode. It is an allowlist, so a new command is refused until somebody decides otherwise.
- **`public/setup.php`** creates an administrator only after `schema_apply()` has passed, so on a
  database that a restore left without one, it is refused like every other run.

### 4. What every request sees

The closed page, `schema_blocked_page()`, keeps its status 503, `Retry-After: 300`,
`Cache-Control: no-store`, both languages and no SQL. It gains a sentence for a family and a label
for the person the reason is meant for, for every refusal, not only this one:

> **Das Portal ist vorübergehend geschlossen.**
> Du musst nichts tun. Bitte versuche es später noch einmal.
> Für die Person, die das Portal betreut: *{the reason in German}*
>
> **The portal is temporarily closed.**
> There is nothing you need to do. Please try again later.
> For whoever looks after the portal: *{the reason in English}*

One page for everybody. Telling an administrator from a family needs the accounts table, which may
be the one that lost its rows, and nothing on the page is secret.

The reasons this record adds, every value through `e()`. `{lost}` lists each table still on the list
that has fewer rows, counted on this request, as `contacts (vorher 120, jetzt 0)` /
`contacts (120 before, 0 now)`. `{backup}` is the file's `backup`. With `{from}` empty, the text says
„der vorherigen Version“ / "the previous version".

| When | German | English |
| --- | --- | --- |
| Rows are missing, the first time and every time after | Nach der Aktualisierung auf Version {to} fehlen Datensätze: {lost}. Deshalb bleibt das Portal geschlossen. So kommen sie zurück: zuerst die Dateien von Version {from} wieder hochladen, dann die Sicherung „{backup}-…“ aus dem Ordner storage/backups einspielen, wie INSTALL.md unter „Wiederherstellen“ beschreibt. Beim nächsten Aufruf zählt das Portal nach und öffnet sich, wenn nichts mehr fehlt. | Records are missing after the update to version {to}: {lost}. That is why the portal stays closed. To bring them back: first upload the files of version {from} again, then import the copy "{backup}-…" from the storage/backups folder, as INSTALL.md describes under „Wiederherstellen“. On the next page view the portal counts again and opens once nothing is missing. |
| The same, after an update run with `skip-backup` | As above, with „die Sicherung, die vor der Aktualisierung im Hosting-Panel angelegt wurde,“ for the copy. | As above, with "the copy exported from the hosting panel before the update," for the copy. |
| The file cannot be written | Vor der Aktualisierung konnte das Portal im Ordner storage nicht schreiben. Ohne die Zahlen von vorher fängt es nicht an, und es hat nichts geändert. Bitte im Dateimanager dem Ordner storage Schreibrechte geben (755) und die Seite neu laden. | Before updating, the portal could not write into the storage folder. Without the numbers from before it does not start, and it has changed nothing. Please make the storage folder writable in the file manager (755), then reload the page. |
| The file cannot be read | Die Datei storage/update-unfinished.json mit den Zahlen von vor der Aktualisierung lässt sich nicht lesen. Deshalb bleibt das Portal geschlossen. Bitte die Sicherung von vorher aus dem Ordner storage/backups einspielen, wie INSTALL.md unter „Wiederherstellen“ beschreibt, und danach diese Datei löschen. | The file storage/update-unfinished.json, which holds the numbers from before the update, cannot be read. That is why the portal stays closed. Please import the copy from before the update from the storage/backups folder, as INSTALL.md describes under „Wiederherstellen“, then delete that file. |
| The file cannot be deleted after a run that passed | Die Aktualisierung ist fertig, aber das Portal kann die Datei storage/update-unfinished.json nicht löschen. Bitte im Dateimanager löschen und die Seite neu laden. | The update has finished, but the portal cannot delete the file storage/update-unfinished.json. Please delete it in the file manager, then reload the page. |

The log line, for the hosting error log and the console, names the file's full path, the time the
update began, and the ways back of §5. `ui-ux-designer` reads the texts before they are built: their
meaning is decided here, their last word is not.

**What stays reachable.** Nothing through `public/index.php`: the sign-in, the icon, the manifest,
the colours and the logo get the closed page too, or the maintenance notice while the flag exists.
`public/setup.php` is unchanged. On an installed portal it offers nothing; on a database without an
administrator it asks for the setup code as today, and its run is then refused like any other (§3).
There is no update page, because the update is the first page view. The copies were never reachable
over the web; they are reached in the file manager, as for every restore.

### 5. How the portal reopens

By itself, on the first page view whose run passes. There is nothing to press and nothing needs a
shell.

**(a) The loss was a mistake, and the rows come back.**

1. Upload the files of the version the page names. The portal stays closed meanwhile: the count in
   step 2 of §2 still finds the rows missing and says so, rather than reporting older files.
2. Import the copy the page names with phpMyAdmin, as INSTALL.md's „Wiederherstellen“ says.
3. Open the portal. The run counts every guarded table and finds none below the numbers in the
   file. It finds nothing pending, because the ledger is the one from before, compares again, writes
   the version and deletes the file.

That is how the portal knows the restore worked: every table still guarded has at least as many rows
as when the update began. An import that stopped partway leaves a table short or missing, and the
page goes on naming it. It cannot know more than counts tell, and nothing before this could either.

The order matters. The newer files, left in place, apply their migrations again on the next page
view and take the same rows again. That costs a second import and nothing more: no copy is written
while the update is unfinished, so the one the page names is still there.

At step 1 a newer release that does not lose the rows can be uploaded instead of the previous
version. After the import its migrations run against the restored rows, and the run passes or
refuses like any other.

**(b) The loss was intended, and the guard was not narrowed.** That is a bug in the release: the
migration should have taken its table off `schema_guarded_tables()` in the same commit (0004). The
release that fixes it does so, with the reason beside it. **Uploading it reopens the portal, without
an import:** step 2 of §2 no longer compares that table, the others still have their rows, anything
else the release brings runs, and the run passes. Whether a loss is intended is decided in the code,
by whoever makes the releases, never by the portal and never by a button.

**(c) The last way out: deleting the file.** It tells the portal to accept the database as it is.
The next page view finds no unfinished update, counts afresh and opens, and whatever is missing
stays missing. It is needed only where (a) and (b) cannot work:

- the owner imported a copy they exported themselves (`skip-backup`), which may be older than the
  numbers;
- a row was added to a guarded table during the seconds the copy took, after the copy had passed
  that table, so the copy is a row short of the numbers (Consequences);
- the copy itself is damaged.

UPDATING.md describes it with its cost. The closed page does not offer it: the page shows the way
that loses nothing.

**The setup code has no role.** It proves that somebody can open the portal's files, and every way
above needs that already: the upload, phpMyAdmin, or the file itself. A form that took the code
would be a public page able to lift the guard, and `public/setup.php` stays closed on an installed
portal (f70f8ce). Setup runs no migration of its own: its one call to `schema_apply()` obeys §2
and §3.

### 6. With the maintenance switch, the version, the stamp and a second upload

| | While an update is unfinished |
| --- | --- |
| The maintenance flag | Pauses the check, as it pauses every update: nothing is counted or run until the flag is gone. Nobody is let in (§3). Switching it off does not reopen the portal; the next page view counts. |
| The upload window (0026 §8) | Unchanged. The file is written only when an update starts, and a web update waits until maintenance mode is off. |
| `console.php update` | Switches maintenance mode on and runs the same `schema_apply()`. On a refusal it leaves both the flag and the file; running it again counts first, as a page view does. |
| Version and fingerprint | Written only by a run that passes. The database keeps naming the version it was on, and `console.php status` says the files are newer than the database. |
| `schema.stamp` | Written only by a run that passes, and not read while the file exists. |
| Older files | Checked after the count, so during step 1 of (a) the page goes on naming the missing rows. Once the rows are back, a database newer than the files is refused as today. |
| A second upload on top | Runs nothing while rows are missing. Once they are back, or once it is (b)'s release, its migrations run as part of the same update, with the same copy and the same numbers. The release history records one move, from the version before the first upload to the last. |
| A migration that stopped partway | Leaves the file. Each page view counts, then tries the migration again from its first statement, without a copy, until it passes or rows go missing. |
| A request killed partway | Leaves the file. The next run carries on against the same numbers. |

## Rejected

- **A settings row,** `database-engineer`'s second option. Importing the copy deletes it, because the
  copy is taken first: the portal could then neither check the import nor remember the refusal while
  the new files are still in place. Written before the copy, it would come back with the import; but
  an import that stopped before `settings` leaves it missing or stale, and the record would depend on
  the completeness it exists to judge.
- **A table of its own.** The settings row's faults, and a migration as well.
- **Recording migrations in the ledger only once the update passes.** The next request would run
  them again. DDL that ran cannot run twice, and a `DROP TABLE IF EXISTS` that can would pass on the
  second request, exactly as now.
- **Remembering only that an update was refused, without numbers.** Nothing could then tell a
  complete import from one that stopped partway, or a release that allows the loss from one that
  does not.
- **Telling a restore by the ledger or the version.** An import that stopped after `schema_migrations`
  and before `students` would look restored.
- **Taking the numbers from the copy itself.** `backup_write()` counts each table's rows as it dumps
  them, and using those would make the check after an import exact even with a write during the
  seconds of the backup. It ties the runner to the dump's internals and changes `backup_database()`
  for a race that needs a write in those seconds and then a refused update. Revisit if it is ever
  seen.
- **Starting afresh, with a new copy and new numbers, once the counts are back.** A migration that
  keeps failing would again write a copy on every page view, and push the copy from before out after
  five.
- **Refusing after an import to run the files that lost the rows.** A loss that was not the files'
  fault could then never be retried, leaving only the last way out. The order the page gives avoids
  the second run, and the second run costs one more import when it happens.
- **Letting an administrator in through maintenance mode** while an update is unfinished, as today.
  What they add changes the numbers the guard compares, and enough new rows hide a loss. There is
  nothing to do inside: the way back is the file manager and phpMyAdmin.
- **A button or a form to reopen**, for an administrator or behind the setup code. A button in front
  of accepting a loss is the confirmation box `CLAUDE.md` rules out for destructive actions; a form
  behind the setup code would put the guard behind a public page (§5).
- **A page for staff and another for families.** Telling them apart needs the accounts table, which
  may be the damaged one. One page with a line for the person who looks after the portal says the
  same to everybody, and nothing on it is secret.
- **Restoring automatically.** Restoring is deliberately not automated (`app/backup.php`): putting a
  family's data back over a live database is a decision, and phpMyAdmin does it better.
- **Offering the last way out on the closed page.** The page offers the way that loses nothing. The
  other is in UPDATING.md, with its cost, for the cases that need it.
- **Falling back to a settings row when `storage/` cannot be written.** Two places that can disagree,
  for a folder that has to be writable already.

## Consequences

- **Code.** No new file, and the load order does not change.
  - `app/schema.php` (`database-engineer`): the file, §2's order, the comparison of tables still on
    the list, the page's two new lines and the texts of §4.
  - `app/bootstrap.php` (`backend-dev`): the maintenance gate's condition, with a comment naming this
    record.
  - `bin/console.php` (`backend-dev`): the allowlist, after the bootstrap.
- **Load order.** Every new function is in `app/schema.php` and needs only what is loaded before it:
  `maintenance_file()`, `now()` and `e()` from `app/core.php`, the backup functions from
  `app/backup.php`, `app_version()` and `database_version()` from `app/version.php`. The new callers,
  `boot_http()` and `bin/console.php`, run after every file is loaded.
- **Schema, settings, dependencies:** none.
- **`storage/` must be writable for an update that migrates.** It already had to be, for the backup.
  With `skip-backup` on a `storage/` the portal cannot write, an update now refuses where it used to
  run without a copy.
- **While an update is unfinished:** no page, no administrator, no background work, and no console
  job that writes or sends. Queued mail waits. A cron job set up in the panel fails each run with one
  line, as `mail:work` does in maintenance mode, and a panel that mails cron output will mail it.
- **One line per page view** in the hosting error log while the portal is refused, and the eighteen
  guarded tables are counted on each. Cheap at a club's size.
- **Deleting `storage/`** loses the file together with the copies. The guard cannot outlive the
  folder that holds the copy it protects.
- **An old `storage/` reused for a fresh database** carries a stale file, and setup's run is refused
  until it is deleted. The page names the file.
- **A race remains.** The numbers are counted right after the copy, inside the lock. A request
  already running, or a cron job, that adds a row to a guarded table during the seconds the copy
  takes, after the copy has passed that table, leaves the copy a row short of the numbers. After a
  refusal and an import that table stays one row short, and only the last way out opens the portal.
  It needs that write and a refused update together; Rejected says what would close it.
- **Records.** ADR 0004 is amended; its note says where. ADR 0026 §7 is completed, not changed: a
  dropped guarded table now stays refused across requests too. `docs/decisions/README.md` lists this
  record.
- **Not decided here.** How a release stops a shipped migration that lost rows from running again,
  when ADR 0004 never edits a shipped migration. It needs its own record the first time it happens;
  until then the way back is (a) with the previous version.
- **Must stay true:**
  - the numbers from before an update are kept from before its first migration until a run passes,
    and only a run that passes deletes them;
  - while they are kept, only the update changes the database: no migration runs while a guarded
    table is short, no page is served, nobody is let in, and the console runs only `check`, `status`,
    `migrate`, `update`, `maintenance:on` and `maintenance:off`;
  - one copy per update: nothing writes a backup while an update is unfinished;
  - only tables still in `schema_guarded_tables()` are compared, and a table that is gone counts as
    empty;
  - the file holds no copy's random part;
  - the installer, the console and a page view go through the one `schema_apply()`.

### The build

`database-engineer`, in `app/schema.php`:

- `schema_unfinished_file(): string`, the path.
- `schema_is_unfinished(): bool`, whether the file exists.
- `schema_unfinished(): ?array`, the file read and its shape checked: `null` when there is none,
  `UpdateBlocked` when it cannot be read.
- `schema_mark_unfinished(array $counts, ?string $backup): array`, written beside its place and
  renamed in; `UpdateBlocked` when it cannot be.
- `schema_mark_finished(): void`, deletes it when it is there; `UpdateBlocked` when it cannot.
- `schema_is_current()`: `if (schema_is_unfinished()) return false;` first.
- `schema_refuse_unsafe()`: told whether an update is unfinished; it then writes no copy. It returns
  the copy's path, or `null` when none was written, so `schema_apply()` can record its name without
  the random part.
- `schema_apply()`: the order of §2.
- `schema_verify_counts(array $before, callable $log, ?array $unfinished = null)`: only tables still
  on the list; the texts of §4 when `$unfinished` is given.
- `schema_blocked_page()`: the family's sentence and the label. Its HTML comes from a function the
  suite can call without the `exit`.
- The docblocks of `schema_is_current()`, `schema_ensure_current()` and `schema_apply()` say what the
  file does to each.

`backend-dev`: `boot_http()`'s gate and `bin/console.php`'s allowlist (§3). Both land in the same
change as `app/schema.php`: without them the numbers can be made up while the portal is closed.

### Tests

Each rule below is broken once, on purpose, and seen to fail; the report names each break. Then the
whole suite runs, `tests/mariadb-local.sh`, not only the suites touched.

Where: 1 to 9 in `tests/migration-data.php`'s runner section, after its `$mistake`, on the same
release folder, with the assertions in `tests/suites/migrations.php`. The unit half of 7, and 8, 9
and 12, can live in `tests/suites/install.php`. 10 and 11 run out of process, as `$startRequest` in
`tests/suites/install.php` and `$console` in `tests/suites/structure.php` already start a request
and the console. An import in the suite drops every table and runs the copy statement by statement,
as INSTALL.md has it done; that is not phpMyAdmin, so TESTING.md walks the same with phpMyAdmin.

1. **A loss stays refused, request after request.** The existing file that drops `contacts`:
   requests 1, 2 and 3 are refused, each with the same German and English naming
   `contacts (vorher N, jetzt 0)`. The file exists after each: its `counts` are the counts before,
   `from` and `to` are right, and `backup` names the copy without its random part. Requests 2 and 3
   change neither the ledger, nor the number of copies, nor the stamp. The same with a file that
   `DELETE`s contacts.
   *Break:* take "before" from `schema_counts()` even when the file exists. Request 2 passes.
2. **A run that passes deletes the file.** This release's update, 032 and 033, leaves none. After
   test 5's import none is left either, `schema_is_current()` is true, and the next request is the
   fast path, with no query.
   *Break:* delete the file only when the run applied a migration. After the import it stays.
3. **The stamp and the settings row cannot open an unfinished update.** During a refusal, write
   `schema_state()` into the stamp and the fingerprint and version into the settings, as a pass
   would: `schema_is_current()` is false, and the next run is refused.
   *Break:* drop the file check from `schema_is_current()`.
4. **Nothing runs on top of a loss.** During a refusal, a further file that creates a table: refused,
   not in the ledger, no such table. A file that deletes one contact and then fails: request 1 is a
   `SchemaError` and the file is written; request 2 is refused for missing records, not a second
   `SchemaError`, and writes no copy.
   *Break:* compare only after the migrations.
5. **A complete import reopens, a partial one does not.** The previous files, the `contacts` file
   removed from the release folder; every table dropped; the copy imported up to `contacts` and no
   further: refused, naming contacts. Imported in full: the run passes, contacts are back, the ledger
   is the one from before the update, the version is unchanged, and the release history has no new
   entry.
   *Break:* skip a table that cannot be counted, undoing 0026 §7. The partial import passes.
6. **One copy per update.** The import with the new files still in place: refused again, no new copy,
   and the copy the file names still holds every contact. A file that creates a table and then fails,
   run six times: six `SchemaError`s, no new copy, and the copy from before still there.
   *Break:* let the copy be written while the file exists.
7. **Only tables still guarded are compared.** Unit: `schema_verify_counts()` with
   `['no_such_table' => 5, 'students' => 0]` passes, and with `['contacts' => N]` while contacts is
   gone it throws. The description at `tests/suites/install.php` line 260 says why it passes.
   End to end: during a refusal, rename `contacts` among the file's counts to a name not on the
   list, which is what a release that takes contacts off the list looks like to the file. The run
   passes and deletes the file.
   *Break:* compare every table the file names.
8. **No file, no migration.** The file's path taken by a directory, which holds for root too, where
   a read-only folder does not: refused with the sentence for a file that cannot be written; the
   ledger and the tables are unchanged.
   *Break:* ignore the write's result.
9. **An unreadable file refuses.** `{` in the file, and a file whose `counts` is a string: refused,
   nothing runs.
   *Break:* read an unreadable file as no file.
10. **Nobody gets in through maintenance mode while an update is unfinished.** The file and the
    flag: an administrator's request gets 503 and the maintenance notice. Without the file, the same
    administrator gets in.
    *Break:* drop `!schema_is_unfinished()` from the gate.
11. **The console writes nothing while an update is unfinished.** With the file, `billing:run` exits
    1 with its sentence and adds no charge; `status` runs.
    *Break:* drop the allowlist.
12. **The page.** Its HTML holds the family's sentence and the label in both languages and the loss
    text; a reason containing `<script>` comes out escaped; there is no `SQLSTATE`.
13. **`structure`.** `schema_unfinished_file()` joins the stored files that a test run keeps in its
    own folder.

### Who else

- `ui-ux-designer`: the texts of §4, which families see.
- `mobile-tester`: the closed page at 320 px.
- `qa-tester`: the whole suite; in TESTING.md, a walk on a test install with phpMyAdmin: an update
  that empties a guarded table, the page twice, the previous ZIP, the import, the portal open, the
  file gone from `storage/`.
- `code-reviewer` and `security-reviewer`: the diff. The page prints the counts of the tables that
  fell, on a public address, as it does today; the file holds counts and versions, not a copy's
  random part.
- `docs-writer`: CHANGELOG; VALIDATION.md with the engine actually run; and word for word, below.

### The documents, word for word

**`CLAUDE.md`**, the bullet „An update refuses rather than guesses“ becomes:

```markdown
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
```

**`UPDATING.md`**, „What the first page view after an upload does“: from „3. **Can a backup be
written?**“ to „… cannot race the web request.“ becomes:

```markdown
3. **Can a backup be written?** A full SQL dump goes to `storage/backups` before
   anything is migrated. If it cannot be written, nothing is migrated. See
   [Backups](#backups) for the way past this when you have taken your own.
4. **The numbers from before are written down.** The rows of the eighteen tables
   in `schema_guarded_tables()` are counted — `accounts`, `students`,
   `contacts`, `absences`, `charges`, `payments`, `threads`, `messages`,
   `message_files`, `news`, `class_students`, `attendance`, `invoices`,
   `invoice_charges`, `payment_proofs`, `consent_log`, `tariff_rates` and
   `tariff_discounts` — and kept in `storage/update-unfinished.json`, with both
   version numbers and which copy step 3 wrote, until the update has passed. If
   that file cannot be written, nothing is migrated.
5. Each unrecorded migration is then applied in name order and recorded with the
   checksum of the file it came from. A migration that was edited after being
   applied is refused **by name**, because the checksum no longer matches.
6. **Is everything still there?** Every table still on the list is counted again
   and compared with the numbers from step 4; a table that is gone counts as
   empty. A count that fell stops the update. Counts do not prove an update was
   correct, but a count that fell proves it was not.
7. What the portal cannot work without is filled in where it is missing — the
   lists it needs, a group chat for every course, a login for every student —
   the file from step 4 is deleted, and the page is served.

If any step fails the portal answers 503 and stays closed. The page tells a
family there is nothing for them to do, and tells whoever looks after the portal,
in German and English, what went wrong and what to do. It never prints SQL: that
address is public and a parent may be the one looking at it. The full database
error goes to the hosting error log.

Steps 1 to 4 change nothing in the database, so when one of them refuses, the
next page view starts again once the cause is put right: the newest files,
uploaded completely, and a `storage/` folder the portal can write to. From step 4
on, the update is **unfinished** until step 7 has run, and every page view starts
by counting again and comparing with the numbers in the file:

- While a table still on the list has fewer rows than before, nothing more runs
  and the portal stays closed — on every page view, not only the first. See
  [A refused update](#a-refused-update).
- Otherwise the update carries on where it stopped. A migration that stopped
  partway is tried again from its first statement, against the same numbers, and
  no second copy is written: the one from step 3 stays the newest.

While maintenance mode is on all of this is skipped, so an operator applying a
migration by hand from a shell cannot race the web request. While an update is
unfinished, maintenance mode lets nobody in, administrators included: only the
update may change the rows the numbers describe. Deleting the maintenance flag,
`storage/maintenance.flag`, lets the next page view count again.
```

**`UPDATING.md`**, a new section before „## Backups“:

```markdown
## A refused update

When an update finds fewer rows in a guarded table than before, the portal stays
closed until one of two things is true. It counts again on every page view and
opens by itself once one of them is; there is nothing to press. Keep the ZIP of
the version you are running until the next update has opened the portal: the
first way back needs it.

**The rows come back.** The way when the loss was a mistake, and the safe way
whenever you are not sure which it was:

1. Upload the files of the version the closed page names — the one you came
   from — over the new ones. The portal stays closed meanwhile.
2. Import the copy the closed page names, from `storage/backups`, as
   [INSTALL.md](INSTALL.md#wiederherstellen) describes.
3. Open the portal. It counts every guarded table again and opens once none has
   fewer rows than before the update. If one still has, the page says which: the
   import stopped partway, or it was a different file.

In this order, because the newer files, left in place, apply their migrations
again on the next page view and take the same rows again. If that happens anyway,
nothing more is lost: no copy is written while an update is unfinished, so the
one the page names is still there. Upload the files and import it again.

**A release says the rows may go.** If a migration removed rows on purpose and
its table was not taken off `schema_guarded_tables()` in the same commit, the
release has a bug. The release that fixes it takes the table off the list, with
the reason beside it, and uploading it opens the portal without importing
anything: the tables still on the list are compared, and they have their rows.
Whether a loss is intended is decided in the code, by whoever makes the
releases — never by the portal.

**While it is closed** nobody gets in: families, trainers and administrators
alike, and maintenance mode does not change that. The console runs only `check`,
`status`, `migrate`, `update`, `maintenance:on` and `maintenance:off`; mail,
billing, the nightly cleanup and `backup` wait. Anything else that added rows
could make up a count that fell and hide what is missing.

**The last way out.** `storage/update-unfinished.json` holds the numbers from
before. Deleting it tells the portal to accept the database as it is: the next
page view counts afresh and opens, and whatever is missing stays missing. Do it
only when the missing rows are meant to be gone or are back some other way — for
instance after importing a copy you exported yourself before the update (with
`skip-backup`), which can be older than the numbers in the file, so that the
portal cannot tell it from a loss. Deleting the whole `storage/` folder does the
same, and takes the copies with it.
```

**`UPDATING.md`**, „## Backups“, a bullet after „**How many:**“:

```markdown
- **One per update.** While an update is unfinished no further copy is written,
  by a page view or by `php bin/console.php backup`, so pruning never removes the
  copy taken before it.
```

**`UPDATING.md`**, „### What re-running is guaranteed to do“. Three rows change and two are added
after the second:

```markdown
| Running `update` again with nothing new | Applies nothing and reopens the portal — unless an update is unfinished and a guarded table still has fewer rows than before it |
| A migration that removes rows from a guarded table | Refused after the fact; the portal stays closed on every page view until the rows are back or a release takes the table off the list |
| A page view, or a newer upload, after that refusal | Counts again; runs nothing and stays closed while a guarded table has fewer rows than before the update |
| The previous version's files and the copy from before, after that refusal | Counts again and opens |
| A migration failing partway | Stops at that statement, does **not** record the migration, names the statement number; each page view tries it again from its first statement, against the numbers from before the update, without writing another copy |
```

and the paragraph after the table, „The last row is the case that needs you. …“, becomes:

```markdown
A migration failing partway is the case that needs you. MySQL cannot roll back
DDL, so a migration that fails at statement 5 of 12 leaves the first four applied
and the migration unrecorded — trying it again starts it from the beginning, and
fails again on the work already done unless each of its statements can run
twice. The error says which statement stopped. The way back is the one for
[a refused update](#a-refused-update): the previous version's files, then the
copy from before. Finishing the migration by hand in phpMyAdmin and adding its
row to `schema_migrations` works too; the next page view then compares and opens.
```

**`UPDATING.md`**, the paragraph „An administrator can still sign in while maintenance mode is on …“
becomes:

```markdown
An administrator can still sign in while maintenance mode is on, and a banner
at the top of every page offers to switch it off. That is deliberate: switching
it on from **Einstellungen → System** must not be able to lock you out. The one
exception is an unfinished update ([A refused update](#a-refused-update)): then
nobody is let in, and deleting `storage/maintenance.flag` in the file manager, or
`php bin/console.php maintenance:off`, switches maintenance mode off.
```

**`UPDATING.md`**, „## Adding a feature after going live“, the paragraph „A migration must not reduce
the row count …“ becomes:

```markdown
A migration must not reduce the row count of any table in
`schema_guarded_tables()`. The update refuses one that does, on purpose, and the
portal stays closed until the rows are back: a count going down means something
went wrong rather than something being cleaned up. A release that genuinely has
to remove rows — merging duplicates, say — takes the table off that list in the
same commit, with the reason written beside it. A release that forgot to is fixed
by the release that does: uploading it reopens the portal its predecessor closed
([A refused update](#a-refused-update)).
```

**`INSTALL.md`**, the row „Sind danach noch alle Datensätze da?“ becomes:

```markdown
| Sind danach noch alle Datensätze da? | Fehlen Schüler, Beiträge, Zahlungen oder Nachrichten, bleibt das Portal geschlossen – bei jedem Aufruf, bis sie wieder da sind (siehe „Wiederherstellen“). |
```

and „### Wiederherstellen“, whose order this record reverses, becomes:

```markdown
### Wiederherstellen

Zuerst die Programmdateien hochladen, die zur Sicherung gehören: eine Sicherung
von vor einer Aktualisierung gehört zur vorherigen Version, also deren ZIP. Das
Portal bleibt dabei geschlossen. Erst die Dateien, dann die Datenbank:
andersherum würden die neueren Dateien ihre Datenbankänderungen beim nächsten
Aufruf noch einmal anwenden.

Dann im Hosting-Panel **phpMyAdmin** öffnen, die Datenbank auswählen, unter
**Exportieren** zur Sicherheit den aktuellen Stand herunterladen, dann alle
Tabellen löschen und unter **Importieren** die gewünschte Datei aus
`storage/backups` einspielen. Die Datei bringt ihre eigenen Tabellen mit und
lässt sich auch zweimal einspielen.

Danach das Portal öffnen. Wurde eine Aktualisierung abgelehnt, weil Datensätze
fehlten, zählt das Portal nach: sind alle wieder da, öffnet es sich von selbst;
sonst nennt die Seite, was noch fehlt.
```

The list of eighteen tables is the one in `schema_guarded_tables()` on 2026-10-07; `docs-writer` takes
it from the code as it is when this lands.

## In plain words, for the owner

- Until now, if an update lost records, the portal closed for one page view and then opened as if
  nothing had happened. From now on it stays closed until the records are back.
- The closed page says what is missing and what to do: upload the files of your previous version,
  then import the copy the portal took before the update, in phpMyAdmin. The portal counts again and
  opens by itself. Families see one line: nothing for them to do, try again later.
- If the records were meant to go, that is a mistake in the release, and the release that fixes it
  says so: uploading it opens the portal.
- While it is closed nobody gets in, you included, and nothing is sent or charged, so nothing can
  cover up what went missing.
- Keep the ZIP of the version you are running until the next update has opened the portal.
- Nothing to test yet. Once it is built, the project manager tells you what to try on a test copy.
