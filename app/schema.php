<?php
declare(strict_types=1);

/**
 * Applying the database migrations.
 *
 * One copy of this, used by three callers: the browser installer, the console,
 * and the first web request after new files are uploaded. The operator has no
 * shell, so "replace the files and open the portal" has to be the whole upgrade;
 * that only holds if the code that upgrades the schema is the same code the
 * console runs, rather than a second implementation that drifts.
 *
 * An update that starts migrating is unfinished until a run of it passes, and
 * the numbers from before it are kept in a file until then, so a loss keeps the
 * portal closed on every request rather than only the first (ADR 0027).
 */

/**
 * Migration files in the order they must be applied.
 *
 * Every caller in the application reads the shipped directory. $dir exists for
 * the install suite, which has to watch the fingerprint react to a new file:
 * a file written into the real directory, even for a moment, is one the next
 * page view applies to the live database and records in the ledger, and once
 * the test deletes it again the ledger names a migration the files no longer
 * have and the portal stays closed.
 */
function migration_files(?string $dir = null): array {
    $dir ??= ROOT . '/database/migrations';
    $files = array_map(fn(string $name): string => $dir . '/' . $name, dir_entries($dir, '.sql'));
    sort($files);
    return $files;
}

/**
 * One value identifying the exact set of migrations shipped in these files.
 *
 * Contents rather than names, so an edited migration is noticed and refused by
 * schema_apply() instead of being silently treated as already applied.
 */
function schema_fingerprint(?string $dir = null): string {
    $parts = [];
    foreach (migration_files($dir) as $file) $parts[] = basename($file) . ':' . hash_file('sha256', $file);
    return hash('sha256', implode("\n", $parts));
}

/**
 * The exact release these files are, migrations and version together.
 *
 * The version is part of it so that a release carrying no migration still
 * records itself in the database. Without that, schema_written_by would name
 * whichever older release last happened to change the schema, and the marker
 * the operator is asked to trust would be quietly wrong.
 */
function schema_state(): string { return schema_fingerprint() . ' ' . app_version(); }

/**
 * Where the "already up to date" marker lives.
 *
 * Beside the maintenance flag, which is the path an operator running separate
 * release folders already points at shared storage, so switching releases does
 * not lose it.
 *
 * Its content is the state the database was last brought to: the page path's
 * cache. Its existence says that a run has passed on this folder (ADR 0029 §1).
 * Only a run that passes writes it - or schema_is_current(), from the settings
 * row such a run wrote - and nothing in the portal deletes it, so an empty
 * database beside it is one somebody emptied, not one to install into
 * (schema_restore_refusal()). Deleting it by hand is how a new portal starts on
 * a folder an old one used.
 */
function schema_stamp_file(): string { return dirname(maintenance_file()) . '/schema.stamp'; }

/**
 * Where the numbers from before an update are kept until it has passed.
 *
 * Beside the stamp, the maintenance flag and the backups, so it moves with the
 * shared storage of a release-folder layout and with a test run's own folder.
 * A file rather than a settings row because the restore it has to judge is the
 * one thing it must survive: a row written after the copy was taken is gone
 * once the copy is imported, while phpMyAdmin touches nothing in storage/
 * (ADR 0027 §1).
 */
function schema_unfinished_file(): string { return dirname(maintenance_file()) . '/update-unfinished.json'; }

/** Whether an update is unfinished: one is_file(), no query, because every page view asks. */
function schema_is_unfinished(): bool { return is_file(schema_unfinished_file()); }

/**
 * The record of the unfinished update, or null when no update is unfinished.
 *
 * A file that is there but cannot be read - not JSON, or not the shape
 * schema_mark_unfinished() writes - refuses: without the numbers there is
 * nothing to compare against, and opening would be a guess. Only what the
 * portal reads is checked; the note is for the person in the file manager.
 */
function schema_unfinished(): ?array {
    $path = schema_unfinished_file();
    if (!is_file($path)) return null;
    $record = json_decode((string)@file_get_contents($path), true);
    $readable = is_array($record) && is_array($record['counts'] ?? null)
        && is_string($record['started'] ?? null) && is_string($record['from'] ?? null) && is_string($record['to'] ?? null)
        && array_key_exists('backup', $record) && ($record['backup'] === null || is_string($record['backup']));
    foreach ($readable ? $record['counts'] : [] as $table => $rows)
        if (!is_string($table) || !is_int($rows) || $rows < 0) $readable = false;
    if ($readable) return $record;
    throw new UpdateBlocked(
        'Die Datei storage/update-unfinished.json mit den Zahlen von vor der Aktualisierung lässt sich nicht lesen. Deshalb bleibt das Portal geschlossen. Bitte die Sicherung von vorher aus dem Ordner storage/backups einspielen, wie INSTALL.md unter „Wiederherstellen“ beschreibt, und danach diese Datei löschen.',
        'The file storage/update-unfinished.json, which holds the numbers from before the update, cannot be read. That is why the portal stays closed. Please import the copy from before the update from the storage/backups folder, as INSTALL.md describes under „Wiederherstellen“, then delete that file.',
        'Cannot read ' . $path . ' (not JSON, or not the shape the update writes): the counts from before the update are unknown, so nothing is compared or run. Import the copy taken before the update, then delete that file (UPDATING.md, "A refused update").');
}

/**
 * Write down the numbers from before an update, before its first migration.
 *
 * $backup is the path backup_database() returned, or null when the update runs
 * without a copy (skip-backup). Only the copy's name without its random part is
 * kept: this file's name is fixed, so a server that fails to deny storage/ would
 * hand it out, and the random part is what keeps the copy itself from being
 * fetched then. backup_database() puts it last, after the last hyphen:
 * 2026-10-07-123210-vor-update is what is kept. Returns the record written,
 * which is the run's "before".
 */
function schema_mark_unfinished(array $counts, ?string $backup): array {
    if ($backup !== null) {
        $backup = basename($backup, '.sql');
        $backup = substr($backup, 0, (int)strrpos($backup, '-'));
    }
    $record = [
        'note' => ['Diese Datei hält fest, wie viele Datensätze es vor der Aktualisierung gab, bis die Aktualisierung fertig ist; was zu tun ist, steht in UPDATING.md unter „A refused update“.',
                   'This file holds how many records there were before the update until the update has finished; UPDATING.md, "A refused update", says what to do.'],
        'started' => now(),
        'from' => database_version(),
        'to' => app_version(),
        'backup' => $backup,
        'counts' => $counts,
    ];
    if (schema_unfinished_write($record)) return $record;
    throw schema_unwritable();
}

/**
 * The refusal for an update that cannot keep its numbers. schema_refuse_unsafe()
 * gives it before the copy is taken; schema_mark_unfinished() after, should the
 * write still fail.
 */
function schema_unwritable(): UpdateBlocked {
    return new UpdateBlocked(
        'Vor der Aktualisierung konnte das Portal im Ordner storage nicht schreiben. Ohne die Zahlen von vorher fängt es nicht an, und es hat nichts geändert. Bitte im Dateimanager dem Ordner storage Schreibrechte geben (755) und die Seite neu laden.',
        'Before updating, the portal could not write into the storage folder. Without the numbers from before it does not start, and it has changed nothing. Please make the storage folder writable in the file manager (755), then reload the page.',
        'Cannot write ' . schema_unfinished_file() . ': without the counts from before the update, nothing is migrated.');
}

/**
 * The refusal for an update whose copy beforehand could not be taken. What went
 * wrong is for the log only: a BackupError names the folder of copies, whose
 * path on shared hosting holds the hosting account's name, or carries the
 * database's own error, which can name the database user and a table; the two
 * texts are on the closed page, which anybody can open (security finding 5).
 */
function schema_backup_failed(BackupError $e): UpdateBlocked {
    return new UpdateBlocked(
        'Vor der Aktualisierung konnte keine Sicherung angelegt werden. Der Grund steht im Fehlerprotokoll des Hostings. Bitte im Hosting-Panel selbst eine Sicherung der Datenbank anlegen und danach im Ordner storage eine leere Datei namens skip-backup erstellen.',
        'No backup could be taken before updating. The reason is in the hosting error log. Please export the database from the hosting panel yourself, then create an empty file named skip-backup in the storage folder.',
        'Pre-update backup failed: ' . $e->getMessage());
}

/**
 * Put the record in place: written beside it, flushed to disk and renamed in, so
 * it is never half there and a power cut cannot leave an empty file that the
 * next run would have to refuse.
 */
function schema_unfinished_write(array $record): bool {
    $path = schema_unfinished_file();
    $shown = $record;
    // An object even when empty, so the file always reads as table => rows.
    $shown['counts'] = (object)$record['counts'];
    $json = json_encode($shown, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || !($handle = @fopen($path . '.part', 'wb'))) return false;
    $whole = fwrite($handle, $json . "\n") === strlen($json) + 1 && fflush($handle);
    // Best effort: a file system that cannot sync still has the bytes.
    @fsync($handle);
    if (fclose($handle) && $whole && @rename($path . '.part', $path)) return true;
    @unlink($path . '.part');
    return false;
}

/**
 * The update has passed: its numbers go.
 *
 * Only the end of a run that passed calls this, inside the lock. A file that
 * cannot be deleted refuses that run: left behind, its old numbers would refuse
 * a later update for rows somebody removed on purpose since.
 */
function schema_mark_finished(): void {
    $path = schema_unfinished_file();
    if (@unlink($path) || !is_file($path)) return;
    throw new UpdateBlocked(
        'Die Aktualisierung ist fertig, aber das Portal kann die Datei storage/update-unfinished.json nicht löschen. Bitte im Dateimanager löschen und die Seite neu laden.',
        'The update has finished, but the portal cannot delete the file storage/update-unfinished.json. Please delete it in the file manager, then reload the page.',
        'The update passed, but ' . $path . ' cannot be deleted. Delete it by hand: left in place, its counts would refuse a later update for rows removed on purpose since.');
}

/**
 * How far this update has got with a migration that stopped: the furthest
 * statement any attempt reached, kept in the record.
 *
 * Every attempt starts the migration at its first statement, so a retry often
 * stops on statement 1, on a table the first attempt already made. Reported as
 * such, the retry would say nothing was applied when four statements were. Best
 * effort: if the record cannot be rewritten, its numbers stay as they were and
 * only this report is the poorer for it.
 */
function schema_record_stop(?array &$unfinished, string $version, int $statement, int $statements): int {
    if ($unfinished === null) return $statement;
    $stopped = $unfinished['stopped'] ?? null;
    if (is_array($stopped) && ($stopped['migration'] ?? null) === $version && ($stopped['statements'] ?? null) === $statements
        && is_int($stopped['statement'] ?? null) && $stopped['statement'] >= $statement)
        return $stopped['statement'];
    $unfinished['stopped'] = ['migration' => $version, 'statement' => $statement, 'statements' => $statements];
    schema_unfinished_write($unfinished);
    return $statement;
}

/**
 * Whether the copy an unfinished update took is still among the copies: one
 * named as its record names it, then a hyphen and the random part
 * (schema_mark_unfinished()). Compared as text, never as a pattern: the name
 * is read from a file a person may have edited.
 */
function schema_copy_exists(?string $backup): bool {
    if ($backup === null || $backup === '') return false;
    foreach (backups() as $copy)
        if (str_starts_with($copy['name'], $backup . '-')) return true;
    return false;
}

/**
 * Whether the database already has every migration in these files.
 *
 * The stamp file is the fast path and answers without touching the database at
 * all, which matters because this runs on every page view. The settings row is
 * the durable answer for hosting where the stamp cannot be written; the file is
 * only ever a cache of it.
 *
 * Never while an update is unfinished, whatever the stamp and the settings say:
 * both are written only by a run that passes, but after an import with the
 * previous files either matches the files again, and must not open the portal
 * before a run has counted (ADR 0027 §2).
 */
function schema_is_current(): bool {
    if (schema_is_unfinished()) return false;
    $want = schema_state();
    if (@file_get_contents(schema_stamp_file()) === $want) return true;
    try { $have = (string)setting('schema_fingerprint') . ' ' . database_version(); } catch (Throwable) { return false; }
    if ($have !== $want) return false;
    schema_write_stamp($want);
    return true;
}

/** Best effort: a read-only storage directory costs a query per request, not correctness. */
function schema_write_stamp(?string $state = null): void {
    @file_put_contents(schema_stamp_file(), $state ?? schema_state());
}

/** Migration files that have not been recorded as applied. */
function schema_pending(): array {
    $applied = array_column(rows('SELECT version FROM schema_migrations'), 'version');
    return array_values(array_filter(array_map('basename', migration_files()),
        fn($version) => !in_array($version, $applied, true)));
}

/**
 * Migrations the database has run that these files do not contain.
 *
 * This is how a downgrade shows up: the wrong ZIP uploaded, or an older one put
 * back to undo something. Without this check it passes silently, because there
 * is nothing pending to apply - and the portal then serves old code against a
 * newer schema, which is the shape of problem that corrupts data quietly rather
 * than failing loudly.
 */
function schema_extra(): array {
    $shipped = array_map('basename', migration_files());
    $applied = array_column(rows('SELECT version FROM schema_migrations'), 'version');
    sort($applied);
    return array_values(array_diff($applied, $shipped));
}

/**
 * The tables whose row count must not drop across an update.
 *
 * Everything a family would notice the loss of. A migration that removes rows
 * from one of them on purpose takes the table off this list in the same commit,
 * with the reason written beside it, so a count going down means something went
 * wrong rather than something being cleaned up, and the update is refused.
 */
function schema_guarded_tables(): array {
    return ['accounts', 'students', 'contacts', 'absences',
            // field_values left with 032, which drops it and every custom-field
            // value in it on purpose: the owner asked for the data to go (ADR
            // 0026 §7). Left on, it would refuse that update on every portal
            // that held a value.
            'charges', 'payments', 'threads', 'messages', 'message_files', 'news',
            // Added as the portal grew. A table left off this list is a table an
            // update may quietly empty, so anything a family, the tax office or a
            // consent record would miss belongs here. schema_counts() skips a
            // table that does not exist yet, so naming one early is free.
            'class_students', 'attendance', 'invoices', 'invoice_charges',
            'payment_proofs', 'consent_log',
            // A tariff with no rates is a tariff that bills nobody, and a lost
            // discount template is a price she has to remember again.
            'tariff_rates', 'tariff_discounts'];
}

/**
 * Row counts for those tables, skipping any that do not exist.
 *
 * Before an update, that is a table not created yet: a first install has none
 * of them, and comparing against a table that was created by the very migration
 * under test would report a fall from nothing. After it, a table counted before
 * and skipped now is one the update dropped, which schema_verify_counts() reads
 * as emptied.
 */
function schema_counts(): array {
    $counts = [];
    foreach (schema_guarded_tables() as $table) {
        try { $counts[$table] = (int)scalar('SELECT COUNT(*) FROM ' . sql_name($table, 'table')); }
        catch (PDOException) { /* not created yet, or dropped by the update */ }
    }
    return $counts;
}

/**
 * An update that must not proceed, with the reason in the operator's words.
 *
 * Everything that stops an update ends up here so the page she sees can say
 * what to do about it. The long message is for the error log and the console;
 * the pair of sentences is what goes on a page a parent might also be looking
 * at, which is why it never carries SQL.
 */
class UpdateBlocked extends RuntimeException {
    public function __construct(public readonly string $de, public readonly string $en, string $log = '') {
        parent::__construct($log !== '' ? $log : $de);
    }
}

/**
 * A migration that stopped partway.
 *
 * MySQL cannot roll back DDL, so this is the one failure the operator has to
 * decide about rather than retry. It carries which file and which statement so
 * every caller can say that much without re-parsing an error message.
 *
 * $statement is where this attempt stopped; $reached, where the furthest
 * attempt of the same update stopped (schema_record_stop()). What it reports as
 * applied is the furthest: a retry starts at statement 1 and often stops there,
 * on what an earlier attempt already did.
 *
 * $copied: whether the copy from before the update is there. An update run
 * with skip-backup has none, nor does a first install, and the sentence that
 * sends her to it is said only when it is.
 */
class SchemaError extends UpdateBlocked {
    public function __construct(
        public readonly string $version,
        public readonly int $statement,
        public readonly int $statements,
        public readonly string $sql,
        public readonly string $reason,
        public readonly int $reached = 0,
        public readonly bool $copied = false,
    ) {
        $furthest = max($statement, $reached);
        $where = $version . ' (' . $furthest . '/' . $statements . ')';
        parent::__construct(
            'Eine Datenbankänderung ist fehlgeschlagen: ' . $where . '.' . ($copied ? ' Die Sicherung von vorher liegt im Ordner storage/backups.' : ''),
            'A database change failed: ' . $where . '.' . ($copied ? ' The copy taken beforehand is in storage/backups.' : ''),
            $version . ' failed at statement ' . $statement . ' of ' . $statements . ":\n\n"
            . substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 300) . "\n\n" . $reason . "\n\n"
            . ($furthest > $statement ? 'An earlier attempt of this update stopped at statement ' . $furthest . ".\n" : '')
            . 'Statements 1 to ' . ($furthest - 1) . " were applied and this migration is NOT recorded as done,\n"
            . "so re-running would start it again from the beginning. Restore your backup, or\n"
            . "finish this migration by hand and add the row to schema_migrations yourself.");
    }

    /** The same fact without the SQL, for a page a visitor might be looking at. */
    public function summary(): string {
        return $this->version . ': ' . max($this->statement, $this->reached) . '/' . $this->statements;
    }
}

/** Whether the ledger records no migration: schema_migrations missing, or empty. */
function schema_ledger_is_empty(): bool {
    try { return (int)scalar('SELECT COUNT(*) FROM schema_migrations') === 0; }
    catch (PDOException) { return true; /* no ledger yet, which is as empty as it gets */ }
}

/**
 * Whether this database holds nothing: no migration recorded in its ledger, and
 * nothing in any of the tables an update must not lose. A first install may
 * migrate it without the safeguards, and no run takes a copy of it: there is
 * nothing in it to restore, and the copy would push out one that has something
 * (ADR 0029 §3).
 *
 * Asked by schema_apply() itself rather than left to whoever calls it, because
 * the caller cannot know. The setup page skipped them whenever it believed it
 * was installing, and it believed that whenever the database had failed to
 * answer it a moment earlier - on a portal with families in it. Over a folder
 * on which a run has passed, the same empty database is one somebody emptied,
 * and the run is refused before it asks this (schema_restore_refusal()).
 */
function schema_first_install(): bool {
    return schema_ledger_is_empty() && array_sum(schema_counts()) === 0;
}

/**
 * Why nothing may touch this database now, or null (ADR 0029 §1):
 *
 * (a) a copy is being imported, or its import stopped: the table its first
 *     statement makes and its last drops is there (import_unfinished());
 * (b) the ledger records no migration, yet a guarded table has rows: a copy
 *     without that table is being imported, or the configuration names a
 *     database this portal did not make;
 * (c) the ledger records no migration, the guarded tables are empty, and a run
 *     has passed on this folder (schema_stamp_file() exists): a restore between
 *     deleting the tables and importing the copy, setup pointed at a new
 *     database, or a second portal on the same folder.
 *
 * The one place the three are decided. The runner asks before it writes
 * anything, the sweep before it deletes anything, the console before it runs
 * anything that writes. It writes nothing itself. On the portal's own database
 * it asks two things, whether the marker is there and whether the ledger
 * records a migration, and returns null; only an empty ledger has the guarded
 * tables counted and the stamp looked for.
 */
function schema_restore_refusal(): ?UpdateBlocked {
    if (import_unfinished())
        return new UpdateBlocked(
            'Gerade wird eine Sicherung eingespielt, oder das Einspielen ist abgebrochen. Solange bleibt das Portal geschlossen. Meldet phpMyAdmin, dass das Einspielen fertig ist: diese Seite neu laden. Ist es abgebrochen: dieselbe Datei in phpMyAdmin noch einmal einspielen – dabei wird nichts doppelt.',
            'A copy is being imported, or the import stopped. The portal stays closed meanwhile. Once phpMyAdmin says the import has finished: reload this page. If it stopped: import the same file again in phpMyAdmin; nothing is doubled.',
            'The table ' . IMPORT_UNFINISHED_TABLE . ' exists: a copy is being imported, or its import stopped. Nothing is run, copied or swept until'
            . ' the copy\'s last statement drops it. If the import stopped, import the same file again: each table in it is dropped before it is made.'
            . ' For a damaged copy whose last statement never arrives, drop ' . IMPORT_UNFINISHED_TABLE . ' in phpMyAdmin, accepting the database as'
            . ' it is (UPDATING.md).');
    if (!schema_ledger_is_empty()) return null;
    $rows = array_filter(schema_counts());
    if ($rows)
        return new UpdateBlocked(
            'In der Datenbank fehlt die Tabelle schema_migrations, die jedes Portal hat. Wird gerade eine Sicherung eingespielt: warten, bis phpMyAdmin fertig meldet, dann diese Seite neu laden. Ist das Einspielen abgebrochen: dieselbe Datei noch einmal einspielen. Wird nichts eingespielt, nennt config/config.php eine fremde Datenbank. Das Portal hat nichts verändert.',
            "The database is missing the table schema_migrations, which every portal has. If a copy is being imported: wait until phpMyAdmin says it has finished, then reload this page. If the import stopped: import the same file again. If nothing is being imported, config/config.php names a database that is not the portal's. The portal has changed nothing.",
            'schema_migrations records no migration, but guarded tables have rows ('
            . implode(', ', array_map(fn(string $table, int $count): string => $table . ' ' . $count, array_keys($rows), $rows))
            . '): a copy without ' . IMPORT_UNFINISHED_TABLE . ' is being imported, so finish the import, or the configuration names a database this'
            . ' portal did not make. Nothing was changed.');
    if (is_file(schema_stamp_file()))
        return new UpdateBlocked(
            'Die Datenbank ist leer, aber in diesem Ordner lief schon ein Portal. Zum Wiederherstellen: die Sicherung in phpMyAdmin einspielen, dann diese Seite neu laden. Soll hier ein neues, leeres Portal entstehen: im Dateimanager die Datei storage/schema.stamp löschen und diese Seite neu laden. Vorsicht: Belege und Fotos des alten Portals werden dann gelöscht; seine Sicherungen in storage/backups bleiben.',
            "The database is empty, but a portal has run in this folder before. To restore: import the copy in phpMyAdmin, then reload this page. If a new, empty portal is meant to start here: delete the file storage/schema.stamp in the file manager and reload this page. Careful: the old portal's receipts and photos are deleted then; its copies in storage/backups stay.",
            'The database holds nothing and schema_migrations records no migration, but ' . schema_stamp_file() . ' exists: a run has passed on'
            . ' this folder. Restoring a copy: import it, and the next page view opens the portal. Starting a new, empty portal here: delete that'
            . ' file and reload; the old portal\'s uploads are removed by the nightly sweep once an administrator exists, and its copies in'
            . ' storage/backups stay.');
    return null;
}

/**
 * Bring the database up to the schema these files describe.
 *
 * Safe to call repeatedly and safe to call from two requests at once: the
 * advisory lock serialises them and the second one finds nothing left to do.
 * Returns the names of the migrations it applied, which is empty on the common
 * path where everything was already there.
 *
 * $safeguards false is honoured only on a first install (schema_first_install());
 * on any other database the checks run whatever the caller asked.
 *
 * An update is unfinished from just before its first migration until a run of
 * it passes (ADR 0027 §2), and while it is, every run - a page view, the console,
 * the installer - starts by comparing the counts with the record's and refuses
 * on a shortfall before anything else. The record's counts are each run's
 * "before", no further copy is written, and only the run that passes deletes
 * the record, after the version, before the stamp.
 *
 * Right after that comparison, schema_restore_refusal() is asked, and its reason
 * thrown: while a copy is being imported, while the database holds data but no
 * ledger, or while it is empty over a folder on which a run has passed, nothing
 * is written - not the ledger's own table, no copy, no record, no stamp - with
 * the safeguards waived or not (ADR 0029). A database that holds nothing gets no
 * copy, whoever calls.
 */
function schema_apply(?callable $log = null, bool $safeguards = true): array {
    $log ??= static fn(string $line) => null;
    // Wait rather than refuse: two page views arriving together during an upgrade
    // should both end up served, not one of them erroring.
    if ((int)scalar("SELECT GET_LOCK('badminton_crm_migrate',30)") !== 1)
        throw new UpdateBlocked(
            'Eine andere Aktualisierung läuft gerade. Bitte die Seite in einer Minute neu laden.',
            'Another update is running right now. Please reload the page in a minute.',
            'Another migration held the lock for 30 seconds.');
    $applied = [];
    try {
        // Nothing runs on top of a loss: not a newer upload's migrations, not the
        // retry of one that stopped partway, not even the ledger's own table.
        $unfinished = schema_unfinished();
        if ($unfinished !== null) {
            $log('An update is unfinished since ' . $unfinished['started'] . ' UTC; comparing with the counts from before it');
            schema_verify_counts($unfinished['counts'], $log, $unfinished);
        }
        // Nor into a copy being imported, a database this portal did not make, or
        // one somebody emptied (ADR 0029).
        if ($refusal = schema_restore_refusal()) throw $refusal;
        run('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        // Checked inside the lock and before anything is written, so two requests
        // cannot both decide the release is fine and then disagree.
        $guarded = $safeguards || !schema_first_install();
        $copy = $guarded ? schema_refuse_unsafe($log, $unfinished !== null) : null;
        // After the copy and the count, before the first statement: from here on
        // the update is unfinished until a run of it passes. A first install has
        // nothing to lose and writes none.
        if ($unfinished === null && $guarded && schema_pending())
            $unfinished = schema_mark_unfinished(schema_counts(), $copy);
        $before = $unfinished['counts'] ?? schema_counts();
        foreach (migration_files() as $file) {
            $version = basename($file);
            $hash = hash_file('sha256', $file);
            $old = one('SELECT * FROM schema_migrations WHERE version=?', [$version]);
            if ($old) {
                if (!hash_equals($old['checksum'], $hash))
                    throw new UpdateBlocked(
                        'Die Datei ' . $version . ' wurde nach dem Anwenden verändert. Bitte die unveränderten Dateien der Version hochladen.',
                        'The file ' . $version . ' was changed after it had been applied. Please upload the release files unmodified.',
                        'Migration ' . $version . ' has changed since it was applied. Never edit an applied migration; add a new one instead. To accept a deliberate change, update its checksum in schema_migrations.');
                continue;
            }
            $statements = split_sql((string)file_get_contents($file));
            foreach ($statements as $i => $statement) {
                try { run_migration_statement($statement); }
                catch (Throwable $e) {
                    $reached = schema_record_stop($unfinished, $version, $i + 1, count($statements));
                    $copied = schema_copy_exists($unfinished['backup'] ?? null);
                    throw new SchemaError($version, $i + 1, count($statements), $statement, $e->getMessage(), $reached, $copied);
                }
            }
            run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$version, $hash, now()]);
            $applied[] = $version;
            $log('Applied ' . $version . ' (' . count($statements) . ' statements)');
        }
        schema_verify_counts($before, $log, $unfinished);
        require ROOT . '/database/defaults.php';
        setting_cache_clear();
        set_setting('schema_fingerprint', schema_fingerprint());
        // Recorded before the marker is overwritten, so the change log can say
        // which release the portal came from as well as which it is on.
        $was = database_version();
        if ($was !== app_version()) version_history_add($was, app_version());
        set_setting('schema_written_by', app_version());
        if ($applied) set_setting('schema_last_update', ['at' => now(), 'applied' => $applied]);
        // Last, and whether or not this run applied anything: the run that passes
        // after an import applies nothing, and it is the one that has to reopen.
        schema_mark_finished();
    } finally {
        run("SELECT RELEASE_LOCK('badminton_crm_migrate')");
    }
    schema_write_stamp();
    return $applied;
}

/**
 * Run one statement from a migration file.
 *
 * Not db()->exec(): a statement that returns rows - a SELECT to check something
 * before altering it, a SHOW, a stored-routine call - leaves its result set open
 * on the connection, and every query after it in the same run fails with
 * "Cannot execute queries while other unbuffered queries are active". The
 * migration that did it is then blamed for a failure two statements later, and
 * the recorded state of the update becomes hard to reason about. So the rows are
 * drained and the cursor closed, whatever kind of statement it was.
 */
function run_migration_statement(string $statement): void {
    $result = db()->query($statement);
    if (!$result instanceof PDOStatement) return;
    $result->fetchAll();
    // nextRowset() is how a CALL that returns several result sets is drained,
    // and an error raised after the first one arrives here. It is let through:
    // caught, the statement would count as run and the migration be recorded
    // as applied when it failed half way.
    while ($result->nextRowset()) $result->fetchAll();
    $result->closeCursor();
}

/**
 * What must be true before a migration may run.
 *
 * All of it is about the upload and the storage folder rather than the SQL,
 * because by the time this runs the old files are already gone and the only
 * remaining choice is whether to touch the database as well.
 *
 * Returns the copy it wrote, or null when it wrote none: nothing was pending,
 * the operator's skip-backup was there, an update is $unfinished, or the
 * database holds nothing. That update has its copy already; one taken now could
 * only be of a half-updated database, and it would push the copy from before
 * out of the BACKUP_KEEP that are kept (ADR 0027 §2). A database that holds
 * nothing is backup_database()'s own refusal, NothingToCopy, which this goes on
 * from where any other BackupError stops the update (ADR 0029 §3).
 */
function schema_refuse_unsafe(callable $log, bool $unfinished = false): ?string {
    // 1. Older files than the database. Nothing is pending, so without this the
    //    update looks like a success and the portal opens on the wrong code.
    if ($extra = schema_extra())
        throw new UpdateBlocked(
            'Die hochgeladenen Dateien sind älter als die Datenbank. Sie gehören zu einer früheren Version und würden die vorhandenen Daten falsch lesen. Bitte die neueste Version hochladen.',
            'The uploaded files are older than the database. They belong to an earlier version and would read the existing data wrongly. Please upload the newest release.',
            'Database has migrations these files do not contain: ' . implode(', ', $extra));

    // 2. A half-finished extract, or an FTP upload that mangled the files. The
    //    manifest ships in the package; a git checkout has none and is skipped.
    if ($bad = release_mismatches()) {
        $shown = implode(', ', array_slice($bad, 0, 3)) . (count($bad) > 3 ? ' …' : '');
        throw new UpdateBlocked(
            'Die hochgeladenen Dateien sind unvollständig (' . count($bad) . ' stimmen nicht: ' . $shown . '). Bitte das Paket noch einmal vollständig hochladen und entpacken.',
            'The uploaded files are incomplete (' . count($bad) . ' do not match: ' . $shown . '). Please upload and unpack the package again, completely.',
            'Release manifest mismatch: ' . implode(', ', $bad));
    }

    // 3. Nothing to migrate means nothing to protect, and a fresh install has no
    //    data yet either.
    if (!schema_pending()) return null;
    // Somewhere to keep the numbers from before (ADR 0027 §1), asked before the
    // copy: refused after it, every page view would write one more, and five
    // later the copies from before earlier updates would be pruned. Before the
    // skip-backup is used up, too. A record already there was read before this
    // runs, so anything in its place now is not one.
    $record = schema_unfinished_file();
    if (!$unfinished && (file_exists($record) || !@touch($record . '.part') || !@unlink($record . '.part')))
        throw schema_unwritable();
    // Consumed whenever something is pending, an update unfinished or not, so
    // that its meaning does not change.
    try { $skipped = backup_override_claimed(); }
    catch (BackupError $e) {
        throw new UpdateBlocked(
            'Im Ordner storage liegt eine Datei skip-backup, die das Portal nicht löschen kann. Sie gilt nur für eine Aktualisierung, deshalb fängt das Portal nicht an, und es hat nichts geändert. Bitte im Dateimanager dem Ordner storage Schreibrechte geben (755) und die Seite neu laden.',
            'There is a file named skip-backup in the storage folder that the portal cannot delete. It is meant for one update only, so the portal does not start, and it has changed nothing. Please make the storage folder writable in the file manager (755), then reload the page.',
            $e->getMessage() . ': one the portal cannot consume would skip the copy before every later update too.');
    }
    if ($skipped) { $log('Backup skipped: storage/skip-backup was present.'); return null; }
    if ($unfinished) { $log('No backup: this update has its copy from before it began.'); return null; }
    try {
        $copy = backup_database('vor-update');
        $log('Writing a backup to ' . $copy);
        return $copy;
    } catch (NothingToCopy $e) {
        // A first install: nothing to restore, so nothing to refuse over.
        $log('No backup: ' . $e->getMessage());
        return null;
    } catch (BackupError $e) {
        throw schema_backup_failed($e);
    }
}

/**
 * Compare what was there before with what is there now.
 *
 * Counts alone do not prove an update was correct, but a count that fell proves
 * it was not, and that is worth catching while the backup is still the most
 * recent thing that happened.
 *
 * Every table counted before that is still in schema_guarded_tables() is
 * compared, and one that can no longer be counted counts as 0: a guarded table a
 * migration dropped has lost every row it had. Comparing only what could still
 * be counted let such a migration through (ADR 0026 §7). A table that was not
 * there before, which the update creates, had nothing to lose and is not
 * compared; nor is one a release has taken off the list since the counts were
 * taken, which is how that release reopens a portal its predecessor closed
 * (ADR 0027 §5 b). Inside one run the list cannot change, so there this skips
 * nothing.
 *
 * $unfinished is the record of the update, whose versions and copy the refusal
 * names, with the way back (ADR 0027 §4).
 */
function schema_verify_counts(array $before, callable $log, ?array $unfinished = null): void {
    $guarded = schema_guarded_tables();
    $after = schema_counts();
    $lost = [];
    foreach ($before as $table => $was) {
        if (!in_array($table, $guarded, true)) continue;
        $now = $after[$table] ?? 0;
        $log(sprintf('    %-16s %d -> %d', $table, $was, $now));
        if ($now < $was) $lost[$table] = [$was, $now];
    }
    if (!$lost) return;
    $listed = fn(string $template): string => implode(', ', array_map(
        fn(string $table, array $counts): string => sprintf($template, $table, $counts[0], $counts[1]), array_keys($lost), $lost));
    $de = $listed('%s (vorher %d, jetzt %d)');
    $en = $listed('%s (%d before, %d now)');
    $dropped = 'Row counts dropped: ' . $listed('%s %d -> %d') . '.';
    if ($unfinished === null)
        throw new UpdateBlocked(
            'Nach der Aktualisierung fehlen Datensätze: ' . $de . '. Deshalb bleibt das Portal geschlossen. Die Sicherung von vorher liegt im Ordner storage/backups.',
            'Records are missing after the update: ' . $en . '. That is why the portal stays closed. The copy taken beforehand is in storage/backups.',
            $dropped);
    [$from, $to, $backup] = [$unfinished['from'], $unfinished['to'], $unfinished['backup']];
    throw new UpdateBlocked(
        'Nach der Aktualisierung auf Version ' . $to . ' fehlen Datensätze: ' . $de . '. Deshalb bleibt das Portal geschlossen. So kommen sie zurück: zuerst '
        . ($from === '' ? 'die Dateien der vorherigen Version' : 'die Dateien von Version ' . $from) . ' wieder hochladen, dann '
        . ($backup === null ? 'die Sicherung, die vor der Aktualisierung im Hosting-Panel angelegt wurde,' : 'die Sicherung „' . $backup . '-…“ aus dem Ordner storage/backups')
        . ' einspielen, wie INSTALL.md unter „Wiederherstellen“ beschreibt. Beim nächsten Aufruf zählt das Portal nach und öffnet sich, wenn nichts mehr fehlt.',
        'Records are missing after the update to version ' . $to . ': ' . $en . '. That is why the portal stays closed. To bring them back: first upload '
        . ($from === '' ? 'the files of the previous version' : 'the files of version ' . $from) . ' again, then import '
        . ($backup === null ? 'the copy exported from the hosting panel before the update,' : 'the copy “' . $backup . '-…” from the storage/backups folder,')
        . ' as INSTALL.md describes under „Wiederherstellen“. On the next page view the portal counts again and opens once nothing is missing.',
        $dropped . ' The counts from before the update from ' . ($from === '' ? 'the previous version' : $from) . ' to ' . $to . ', which began at '
        . $unfinished['started'] . ' UTC, are kept in ' . schema_unfinished_file() . ', and every run compares with them until one passes.'
        . ' Ways back (UPDATING.md, "A refused update"): upload the files of ' . ($from === '' ? 'the previous version' : $from) . ', then import '
        . ($backup === null ? 'the copy exported before the update' : 'storage/backups/' . $backup . '-*.sql')
        . '; or upload a release that takes the table off schema_guarded_tables(); or, accepting that whatever is missing stays missing, delete that file.');
}

/**
 * Apply anything outstanding before serving a page, and say so plainly if that
 * fails.
 *
 * This is what makes an update "replace the files". It is skipped while
 * maintenance mode is on, because that is an operator doing the same thing by
 * hand and the two must not race.
 *
 * While an update is unfinished schema_is_current() is false, so every page
 * view runs schema_apply(), which counts first and refuses while rows are
 * missing: every address through index.php gets the closed page until a run
 * passes, and the work registered after boot_http() never starts (ADR 0027 §3).
 */
function schema_ensure_current(): void {
    if (schema_is_current() || is_file(maintenance_file())) return;
    try {
        schema_apply();
    } catch (Throwable $e) {
        error_log('CRM schema update: ' . $e->getMessage());
        schema_blocked_page($e);
    }
}

/**
 * The page shown when the database could not be brought up to date: 503, and
 * the end of the request.
 */
function schema_blocked_page(Throwable $e): never {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 300');
    header('Cache-Control: no-store');
    echo schema_blocked_html($e);
    exit;
}

/**
 * That page's HTML, apart from the exit so the suite can read it.
 *
 * Whoever opens the portal next is either a family, who needs to know there is
 * nothing for them to do, or the person who looks after the portal, who needs to
 * know what to do. Telling them apart would need the accounts table, which may
 * be the one that lost its rows, so it is one page for everybody with a line
 * addressed to that person (ADR 0027 §4). Both languages, German first, and
 * never SQL: this address is public, and the full text is in the hosting error
 * log.
 */
function schema_blocked_html(Throwable $e): string {
    [$de, $en] = $e instanceof UpdateBlocked
        ? [$e->de, $e->en]
        : ['Die Datenbank konnte nicht aktualisiert werden. Die genaue Meldung steht im Fehlerprotokoll des Hostings.',
           'The database could not be updated. The full message is in the hosting error log.'];
    // A tab left open asks again when Retry-After has passed, so the portal
    // reopens in it by itself: one run and one log line per open tab every
    // five minutes while it is closed.
    return '<!doctype html><html lang="de"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="300">'
        . '<meta name="color-scheme" content="light dark"><title>Badminton</title>'
        . '<p><strong>Das Portal ist vorübergehend geschlossen.</strong></p>'
        . '<p>Du musst nichts tun. Bitte versuche es später noch einmal.</p>'
        . '<p>Für die Person, die das Portal betreut: ' . e($de) . '</p>'
        . '<div lang="en"><p><strong>The portal is temporarily closed.</strong></p>'
        . '<p>There is nothing you need to do. Please try again later.</p>'
        . '<p>For whoever looks after the portal: ' . e($en) . '</p></div></html>';
}
