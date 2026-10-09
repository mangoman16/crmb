---
status: accepted
date: 2026-10-08
---

# 0029. A restore keeps the portal closed until its import is done

> **Amended on 2026-10-08: the texts as built, and a page that reloads itself.** `ui-ux-designer`
> read §5's three texts as the owner reads them, mid-restore on a phone, and found three faults.
> „Öffnet sich von selbst" is untrue: the closed page sends `Retry-After: 300`, which no browser acts
> on, so a tab left open never changes and the owner waits on it. (b)'s „Liste der
> Datenbankänderungen" is nothing the owner can find in phpMyAdmin, where the table
> `schema_migrations` is. (c)'s „Dateien des alten Portals" reads as the program files. The texts
> built are the designer's, in the form the code review settled: the state first, then the action in
> phpMyAdmin's terms, „diese Seite neu laden" in place of „von selbst", (a) saying that a second
> import doubles nothing, and (c) naming receipts and photos as what goes and the copies as what
> stays. §5's table keeps its wording as the record of what was decided; these supersede it:
>
> | | German | English |
> | --- | --- | --- |
> | (a) | Gerade wird eine Sicherung eingespielt, oder das Einspielen ist abgebrochen. Solange bleibt das Portal geschlossen. Meldet phpMyAdmin, dass das Einspielen fertig ist: diese Seite neu laden. Ist es abgebrochen: dieselbe Datei in phpMyAdmin noch einmal einspielen – dabei wird nichts doppelt. | A copy is being imported, or the import stopped. The portal stays closed meanwhile. Once phpMyAdmin says the import has finished: reload this page. If it stopped: import the same file again in phpMyAdmin; nothing is doubled. |
> | (b) | In der Datenbank fehlt die Tabelle schema_migrations, die jedes Portal hat. Wird gerade eine Sicherung eingespielt: warten, bis phpMyAdmin fertig meldet, dann diese Seite neu laden. Ist das Einspielen abgebrochen: dieselbe Datei noch einmal einspielen. Wird nichts eingespielt, nennt config/config.php eine fremde Datenbank. Das Portal hat nichts verändert. | The database is missing the table schema_migrations, which every portal has. If a copy is being imported: wait until phpMyAdmin says it has finished, then reload this page. If the import stopped: import the same file again. If nothing is being imported, config/config.php names a database that is not the portal's. The portal has changed nothing. |
> | (c) | Die Datenbank ist leer, aber in diesem Ordner lief schon ein Portal. Zum Wiederherstellen: die Sicherung in phpMyAdmin einspielen, dann diese Seite neu laden. Soll hier ein neues, leeres Portal entstehen: im Dateimanager die Datei storage/schema.stamp löschen und diese Seite neu laden. Vorsicht: Belege und Fotos des alten Portals werden dann gelöscht; seine Sicherungen in storage/backups bleiben. | The database is empty, but a portal has run in this folder before. To restore: import the copy in phpMyAdmin, then reload this page. If a new, empty portal is meant to start here: delete the file storage/schema.stamp in the file manager and reload this page. Careful: the old portal's receipts and photos are deleted then; its copies in storage/backups stay. |
>
> Two lines join the closed page's `<head>` (`schema_blocked_html()`, ADR 0027 §4), for every
> refusal and not only these three. `<meta http-equiv="refresh" content="300">`, the same five
> minutes as `Retry-After`: a tab left open reloads by itself, so once the import is done the portal
> does open without anybody pressing anything, and the texts above tell the owner how to be quicker.
> Every reload is a GET that a refused run answers without writing, so an open tab costs a few
> queries every five minutes and nothing more; it is HTML, and needs no JavaScript (ADR 0002).
> `<meta name="color-scheme" content="light dark">`: the page carries no stylesheet, and without it a
> phone in dark mode draws it white at full brightness. Rejected with it: a JavaScript poll, which a
> browser without JavaScript would not run; and a shorter refresh, which would count the guarded
> tables on every open tab that often, for nothing while a long import runs.
>
> The sentences in the table are the code's, in `schema_restore_refusal()` (`app/schema.php`), word
> for word: the `install` and `migrations` suites pin them, so a change to one is a change here too.
>
> Built with it, from the same review, two things the record had left to the owner's flag:
>
> - **The cron job honours maintenance mode, always.** Consequences left open whether
>   `console.php maintenance` should honour the flag outside a restore; the build decided that it
>   does, as `mail:work` and the background work do, and the item is closed. The flag is the owner's
>   one switch meaning "nothing changes for now", and INSTALL.md's restore with the files kept (§6)
>   rests on it, because the code cannot see that restore: the cron job was the one writer that ran
>   through the switch, and it would sweep the uploads whose rows are gone for the duration. Why the
>   flag is on is the owner's business, not the job's, so it does not look for a reason: with the
>   flag on it stops with its sentence and exit code 1, as `mail:work` does, and sweeps on its next
>   run after the flag is gone. Nothing is lost by the wait; expired tokens and records only grow
>   older.
> - **The background work does nothing while there is a reason of §1.** `tick_work()` returns before
>   its jobs, so a restore that keeps the files is not mailed, swept or billed on by the tick that
>   follows a page view, whether or not the owner set the flag. It costs two small queries, at most
>   once a minute.

> **Accepted** on 2026-10-08. Decided by the architect at the project manager's request, from
> `security-reviewer`'s finding on round 2 (`8e5ce48`) and its re-review: "before real families, a
> restore must keep the portal closed until its import is done". It adds to ADR 0027 and amends no
> record. It needs no migration, no setting, no new file in `app/` and no dependency.

## Context

INSTALL.md's „Wiederherstellen": upload the files that belong to the copy, then in phpMyAdmin delete
every table and import the copy from `storage/backups`. Read from the code at `5235195`:

- **The empty moment.** Between the deletion and the import, a request finds no ledger.
  `schema_extra()` has nothing to compare and `schema_first_install()` says first install, so
  `schema_apply()` makes the tables afresh, runs `database/defaults.php` and writes the stamp. A page
  view runs it with the safeguards (only setup passes `safeguards: false`), so it also writes a
  `vor-update` copy of the empty database, and `backup_prune()` keeps five: the copy being restored
  can be pushed out. `prune_uploads(600)` in the update's step found no row naming any file and
  deleted every upload. It now does nothing while `accounts` is empty (`backend-dev`); its
  `ponytail:` names the halfway import as the ceiling.
- **The halfway import.** `backup_write()` dumps the tables in alphabetical order (`backup_tables()`
  sorts them), each after its own `DROP TABLE IF EXISTS`, one `INSERT` per row. phpMyAdmin on shared
  hosting stops a long import partway. If the empty moment ran first, the tables not reached yet are
  its fresh, empty ones, with its ledger, and the portal opens on half the data. `accounts` comes
  before `message_files` and `payment_proofs`, so the sweep's guard passes, and the next nightly
  sweep deletes every receipt and chat photo whose rows did not arrive. `console.php maintenance`,
  the cron job, runs `prune_expired()` whatever `prune_last_run` and the maintenance flag say.
- **Other routes to the same moment.** `public/setup.php` pointed at a new database over an old
  `storage/`, and two portals sharing one `storage/` folder.
- **A restore that keeps the files.** With unchanged files `schema_is_current()` finds the stamp
  matching, and the runner never runs: pages are served while the tables are gone and while they
  come back. TESTING.md 21.8 deletes the stamp to reach the moment above.

What constrains the answer:

- The owner has no shell. What they do must be possible in the file manager, in phpMyAdmin or in the
  portal, or happen by itself.
- ADR 0027 keeps the page path to one `is_file()` and no query, and rejected judging a restore by the
  ledger: an import that stopped after `schema_migrations` looks restored.
- The copy stays plain SQL that any panel imports (`app/backup.php`).

## Decision

### 1. Three states in which nothing touches the database

`schema_restore_refusal(): ?UpdateBlocked`, in `app/schema.php`, gives the reason, or `null`:

| | The state | What it means |
| --- | --- | --- |
| (a) | The table `import_unfinished` exists | A copy is being imported, or its import stopped (§2). |
| (b) | The ledger records no migration, and a guarded table has rows | A copy without the marker is being imported (§4), or `config/config.php` names a database the portal did not make. |
| (c) | The ledger records no migration, the guarded tables are empty, and `storage/schema.stamp` exists | An empty database over a folder on which a run has passed: a restore between the deletion and the import, setup pointed at a new database, or a second portal on the same folder. |

In every other state the database is the portal's, because its ledger records migrations, or new
over a new folder, and nothing changes. "The ledger records no migration" is what
`schema_first_install()` already asks: `schema_migrations` missing or empty.

The stamp's **content** stays what it was: the state the database was last brought to, the page
path's cache. Its **existence** now also says that a run has passed on this folder. Only a run that
passes writes it (and `schema_is_current()`, from the settings row), and nothing in the portal
deletes it. Changing its content forces a check; deleting it says that no portal has used the
folder.

### 2. The portal's own copy says when its import is done

`backup_write()`, in `app/backup.php`:

- after its `SET` lines, before the first table is dropped:
  `CREATE TABLE IF NOT EXISTS import_unfinished (...)`, one column, with a table comment that
  phpMyAdmin shows: a copy is being imported, and the file drops this table last;
- as its last statement: `DROP TABLE IF EXISTS import_unfinished;`.

`backup_tables()` never lists `import_unfinished`, so no copy carries it in the middle. With
`IF NOT EXISTS`, a second import of a copy whose first stopped runs from its first statement, as
INSTALL.md promises ("lässt sich auch zweimal einspielen").

**It is not schema.** No migration makes it, the ledger never records it, `schema_guarded_tables()`
never names it, and the portal never writes it: only a copy's first statement makes it and its last
removes it. `import_unfinished(): bool`, beside `backup_write()`, says whether it exists. The
table's name is one constant there, shared by the writer and every reader.

### 3. Who obeys the three states

- **The runner.** `schema_apply()`, inside the lock, after ADR 0027's comparison and before anything
  is written, the ledger's own table included, throws the reason. A page view gets the closed page,
  the console its sentence and exit 1, setup its error line. No copy, no record of an update, no
  stamp, no table.
- **Setup** shows an `UpdateBlocked`'s own sentence in the page's language, not its log line
  (`public/setup.php`), because (c) is what somebody pointing setup at a new database meets.
- **The sweep.** `prune_uploads()` deletes nothing while there is a reason, whoever calls it: the
  background work, the console, the update's step. Its check on `accounts` stays as the last line,
  and its `ponytail:` names what §6 leaves.
- **The console.** ADR 0027's allowlist (`check`, `status`, `migrate`, `update`, `maintenance:on`,
  `maintenance:off`) applies while there is a reason too, with the reason as the sentence. So
  `backup` cannot copy an empty or half database over the copy being restored, `maintenance` cannot
  sweep, and `billing:run` and `mail:work` cannot work on half the rows.
- **No copy of a database that holds nothing.** A run on a database whose ledger records nothing and
  whose guarded tables are empty writes no copy, whoever calls: there is nothing in it to restore,
  and the copy would push out one that has something.

### 4. How a restore ends, and how a new portal starts

- **A restore, as INSTALL.md says.** Deleting the tables puts the portal in (c): closed, nothing
  swept or copied. The copy's first statement puts it in (a), and its last takes it out. The next
  page view finds the copy's ledger and runs as usual: with the copy's files nothing is pending,
  ADR 0027's counts are compared if an update was unfinished, the defaults are filled in and the
  stamp written. The portal opens by itself.
- **An import that stopped.** (a) holds: closed, and the page says to import the file again. Each
  table in the copy is dropped before it is made, so the second import replaces what the first left.
- **A copy without the marker**, written before this release or exported in the panel for
  `skip-backup`, is judged by its ledger: (b) until `schema_migrations` arrives (§6).
- **A new, empty portal on a used folder.** The page says how: delete `storage/schema.stamp` in the
  file manager and reload. The run installs and writes no copy. The old portal's uploads are then
  orphans, removed by the nightly sweep once an administrator exists; its copies in
  `storage/backups` stay until five newer ones push them out. A leftover
  `storage/update-unfinished.json` names itself, as ADR 0027 says.
- **The last way out of (a)**, for a damaged copy whose last statement never arrives: drop
  `import_unfinished` in phpMyAdmin, accepting the database as it is. It goes in UPDATING.md, as
  ADR 0027 §5 (c) does; the closed page offers the way that loses nothing.

### 5. What the page says

The closed page of ADR 0027 §4, with three new reasons. `ui-ux-designer` reads them before they are
built. The log line of each names what was found (the table, the empty ledger, the stamp's full
path) and the ways of §4.

| | German | English |
| --- | --- | --- |
| (a) | Eine Sicherung wird gerade eingespielt, oder das Einspielen ist nicht fertig geworden. Bis sie ganz eingespielt ist, bleibt das Portal geschlossen, danach öffnet es sich von selbst. Ist das Einspielen abgebrochen, die Datei in phpMyAdmin noch einmal einspielen, wie INSTALL.md unter „Wiederherstellen“ beschreibt. | A copy is being imported, or its import did not finish. The portal stays closed until the whole copy is in, then opens by itself. If the import stopped, import the file again in phpMyAdmin, as INSTALL.md describes under „Wiederherstellen“. |
| (b) | Die Datenbank enthält Daten, aber nicht die Liste der Datenbankänderungen des Portals. Entweder wird gerade eine ältere oder fremde Sicherung eingespielt – dann das Einspielen zu Ende bringen –, oder config/config.php nennt eine andere Datenbank. Das Portal hat nichts geändert. | The database holds data but not the portal's list of database changes. Either an older or outside copy is being imported, so finish the import, or config/config.php names another database. The portal has changed nothing. |
| (c) | Die Datenbank ist leer, aber der Ordner storage gehört schon zu einem Portal. Wird eine Sicherung zurückgespielt: einfach einspielen, danach öffnet sich das Portal von selbst. Soll hier ein neues, leeres Portal entstehen: im Dateimanager storage/schema.stamp löschen und die Seite neu laden. Die Dateien des alten Portals werden danach gelöscht, seine Sicherungen in storage/backups bleiben. | The database is empty, but the storage folder already belongs to a portal. If you are restoring a copy: import it, and the portal opens by itself afterwards. If a new, empty portal is meant to start here: delete storage/schema.stamp in the file manager and reload the page. The old portal's files are deleted afterwards; its copies in storage/backups stay. |

*Amended 2026-10-08:* the note under the title carries the texts as built, and the page's refresh;
this table is the record of what was decided.

### 6. What this leaves

- **A restore that keeps the files.** The page path does not look at the database (ADR 0027), so
  pages are served while the tables are gone and while they come back, and they fail or show part of
  the data. Nothing is swept, copied or run by the console meanwhile (§3). INSTALL.md's restore
  therefore starts with maintenance mode on (Einstellungen → System, or `storage/maintenance.flag`)
  and ends with it off; the flag stops the background work too.
  - *Built 2026-10-08:* the flag now stops the cron job as well, and the background work stops by
    itself while there is a reason (the note under the title).
- **Copies without the marker** (§4). An import of one that stops after `schema_migrations` can open
  on part of the data. What remains then is the sweep's check on `accounts`, and ADR 0027's counts
  when an update was unfinished. Every copy from the release that ships this carries the marker.
- **Two portals that share a folder** are refused at the second install. If the stamp is deleted
  anyway, nothing tells them apart: each sweeps the other's uploads and prunes the other's copies.
  INSTALL.md says one `storage/` folder per portal.

## Rejected

- **Telling a used folder by its uploads or its copies**, as first asked. Every upload folder and
  `backups` would be listed on each such check; a folder with neither yet, such as a second portal's
  before the first stored anything, would pass; and starting afresh would mean deleting the old
  files, the very loss this prevents. The stamp says exactly that a run has passed here, costs one
  `is_file()`, and deleting it changes nothing in any other state.
- **Refusing whenever `storage/` holds anything.** It ships with `.htaccess` and `.gitkeep`, and every
  page view writes a session there.
- **The maintenance flag alone, set by hand** (`security-reviewer`'s first option). Two steps to
  remember, and a clean import judged by eye: an import that stopped, with the flag then removed,
  opens on half the data, and the cron sweep ignores the flag. It stays in INSTALL.md for what the
  code cannot see (§6), not as the safety.
  - *Built 2026-10-08:* the cron job honours the flag now (the note under the title). The first two
    reasons stand, and the flag is still not the safety.
- **An install id in `settings` and in a file in `storage/`** (its third option). A setting, a file,
  two writes and a comparison, and it protects less. Only one id fits in the file, so of two portals
  sharing a folder one still sweeps the other's uploads. An import passes the comparison as soon as
  `settings` arrives, which in the alphabet comes before `students`, `tariff_*` and `threads`. The
  stamp at install and the marker in the copy cover the same cases.
- **The ledger as the marker**, dropped first and written last. It is filled one row per statement,
  so it exists half-filled for a while, and the runner would take the rest as pending and run it on a
  full database. It would also give the ledger a second meaning. A table that only says "unfinished"
  is made in one statement and removed in one.
- **A copy that empties the database itself**, dropping every table `information_schema` lists, to
  spare the owner the deletion. Imported into the wrong database by mistake, it would delete another
  application's tables.
- **A query on every page view** for the marker and the ledger, so that a restore which keeps the
  files gets the closed page as well. ADR 0027 keeps the page path to one `is_file()`. What it costs
  here is pages failing during a restore, not data, and maintenance mode closes that. If the project
  manager wants it closed without the owner's step, this is the way up, and it amends ADR 0027.
- **The closed page from the error handler whenever a table is missing**, as a free half of the
  above. It catches only pages that touch a missing table, and it adds a second door to the runner's
  answer.
- **A button, or a form behind the setup code**, to start afresh or to finish a restore. As ADR 0027
  rejected it: a public page able to lift the guard. The file manager and phpMyAdmin need nothing the
  owner lacks.

## Consequences

- **Code.** No new file, and the load order does not change.
  - `app/backup.php`: the table's name, `import_unfinished()`, `backup_write()`'s first and last
    statements, and `backup_tables()` leaving the table out.
  - `app/schema.php` (`database-engineer`, as for ADR 0027): `schema_restore_refusal()` and its
    texts, its call in `schema_apply()`, no copy of a database that holds nothing, and the docblocks
    of `schema_apply()`, `schema_first_install()` and `schema_stamp_file()`, which say what the
    stamp's existence now means.
  - `app/uploads.php`: `prune_uploads()`'s gate and its `ponytail:`.
  - `bin/console.php`: the allowlist's condition.
  - `public/setup.php`: an `UpdateBlocked`'s sentence in the page's language.
  - `database/defaults.php`: nothing. Its `prune_uploads(600)` obeys the gate like every caller.
- **Load order.** `import_unfinished()` needs only `app/core.php`'s query helpers.
  `schema_restore_refusal()` needs it, `schema_counts()` and `schema_stamp_file()`, all loaded before
  or beside it. `app/uploads.php` and `bin/console.php` call it at request time, after every file is
  loaded.
- **Schema, settings, dependencies:** none. The marker is not schema (§2).
- **The suite.** A first install in a test starts without a stamp, as a real one does. A test that
  builds an empty database in a folder with a stamp, or data without a ledger, now meets (c) or (b)
  by design, and says which it means.
- **Not decided here.** Whether `console.php maintenance` should honour maintenance mode outside a
  restore, as `mail:work` does. During one, §3 already stops it.
  - *Built 2026-10-08:* decided, and no longer open: it does, always, as `mail:work` and the
    background work do. The reason is in the note under the title.
- **Must stay true:**
  - the three states are decided only by `schema_restore_refusal()`, and the table's name is spelled
    only in `app/backup.php`;
  - while there is a reason, nothing changes the database or `storage/` but the import: the runner
    refuses before writing anything, the console runs only its allowlist, `prune_uploads()` deletes
    nothing, and no copy is written;
  - every copy the portal writes makes `import_unfinished` before its first `DROP` and drops it as its
    last statement, and no copy lists it among its tables;
  - the portal itself never makes, fills or drops `import_unfinished`;
  - no copy is written of a database that holds nothing;
  - only a run that passes writes the stamp, and nothing in the portal deletes it.
  - *Built 2026-10-08:* the background work does nothing while there is a reason, and the cron job
    `maintenance` nothing while the flag is on (the note under the title).

### Tests

Each rule is broken once on purpose and seen to fail; the report names each break. Then the whole
suite, `tests/mariadb-local.sh`. Where, as for ADR 0027: the runner and the imports in
`tests/migration-data.php`'s runner section and `tests/suites/migrations.php`, the unit half in
`tests/suites/install.php`, the console out of process as `$console` already is. An import in the
suite runs the copy statement by statement (ADR 0027); TESTING.md walks the same in phpMyAdmin.

1. **An empty database over a used folder refuses, and writes nothing.** Stamp present, no table: a
   page view's run and setup's `schema_apply(null, safeguards: false)` each throw (c). Afterwards no
   table exists, `schema_migrations` included; `storage/backups` holds the same files; the stamp's
   content is unchanged; there is no `update-unfinished.json`; an upload ten minutes old is still
   there.
   *Break:* drop the stamp from (c). The run makes the tables, and the page view a copy.
2. **A new install still installs, and copies nothing.** No stamp, no table: the run passes, the
   ledger is complete, the stamp is written, and `storage/backups` holds no new copy.
   *Break:* copy as before. One new file.
3. **A new portal on a used folder.** Stamp deleted, five old copies: the run passes, and the five
   are still there.
4. **A copy keeps the portal closed until its last statement.** A copy written by
   `backup_database()`; every table dropped; the copy run statement by statement. A run tried after
   the marker's `CREATE`, after `accounts`, after `payment_proofs`, after the ledger's last row and
   before the final `DROP` is each refused with (a) and changes nothing. After the last statement the
   run passes and writes the stamp.
   *Break:* leave (a) out. The run after the ledger's last row passes with `settings` and `students`
   missing, or stops on a migration.
5. **An import that stopped stays closed, and a second one opens.** The copy run up to
   `payment_proofs` and stopped: refused with (a). The whole copy run again: the run passes, and
   every row is there once.
   *Break:* make the marker without `IF NOT EXISTS`. The second import stops at its first statement.
6. **Data without a ledger refuses.** A portal with data, `schema_migrations` dropped: refused with
   (b); no copy, no table made.
   *Break:* leave (b) out. The run copies and runs the first migration.
7. **The sweep deletes nothing while there is a reason.** With the marker, rows in `accounts` and
   `payment_proofs` empty, `prune_uploads()` returns 0 and an old proof file stays. The same with no
   table and the stamp present.
   *Break:* drop the gate. The file is deleted.
8. **The console runs only what reads.** With the marker, `backup`, `maintenance` and `billing:run`
   exit 1 with the sentence and change nothing: no new copy, the old upload still there. `status`
   runs.
   *Break:* drop the condition from the allowlist.
   *Built 2026-10-08:* and with the maintenance flag alone, `maintenance` exits 1 and says so; with
   neither marker nor flag it runs. The background work, with the marker, leaves a due prune unrun,
   and without it the same tick runs it.
9. **The marker is never inside a copy.** With the table present, `backup_write()`'s output has the
   marker's `CREATE` after the `SET` lines, its `DROP` last, and no other line naming it.
   *Break:* let `backup_tables()` list it.
10. **Setup says it in words.** `public/setup.php` over an empty database with a stamp shows (c) in
    the page's language, not the log line, and makes no administrator.

### Who else

- `ui-ux-designer`: the texts of §5.
- `qa-tester`: the whole suite. TESTING.md 21.8 is rewritten in the same commit, because deleting the
  stamp now starts a new portal by design: the walk changes the stamp's text instead, expects the
  closed page with (c), no new table and no new copy, then imports and finds every upload. A new
  walk: a copy cut off in the middle and imported in phpMyAdmin leaves the portal closed with (a);
  the whole copy imported opens it, with every upload there.
- `code-reviewer` and `security-reviewer`: the diff, and the marker's two statements in every copy.
- `docs-writer`:
  - INSTALL.md „Wiederherstellen": maintenance mode on before deleting the tables and off after the
    import; an import that stopped is imported again; one `storage/` folder per portal; the three
    texts in the troubleshooting table;
  - UPDATING.md: the three states in "What the first page view after an upload does", a new portal
    on a used folder, and the last way out of (a);
  - `CLAUDE.md`, the bullet „An update refuses rather than guesses": after "fewer rows in
    `schema_guarded_tables()` than it started with", add "an empty database over a `storage/` folder
    a portal has used, a database with data and no ledger, or a copy whose import is unfinished
    (ADR 0029)";
  - CHANGELOG, and VALIDATION.md with the engine actually run.

## In plain words, for the owner

- Restoring a copy no longer puts the uploaded files or the copies at risk. From the moment the
  tables are deleted until the copy is fully imported, the portal stays closed, cleans up nothing and
  takes no copy. It opens by itself afterwards.
- If the import stops halfway, the portal stays closed. Import the same file again.
- Switch maintenance mode on before you restore and off afterwards, so families see a notice rather
  than errors.
  - *Built 2026-10-08:* while it is on, the nightly tidy-up waits too. If your host mails you what
    cron prints, you will see one line per run until you switch it off.
- To start a brand-new portal on a folder that held one before, delete `storage/schema.stamp`. The
  page tells you when.
- One `storage/` folder per portal.
- Nothing to test until it is built.
