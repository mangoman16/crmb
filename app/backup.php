<?php
declare(strict_types=1);

/**
 * A copy of the database, written before anything changes it.
 *
 * MySQL cannot roll back a schema change, so a migration that fails partway
 * leaves the database in a state nothing in this application can undo. On a
 * server with a shell that is what the operator's backup is for. Here there is
 * no shell and there may be no backup, so the portal takes one itself before it
 * migrates, and refuses to migrate if it could not.
 *
 * The file is plain SQL because that is what the import screen in every hosting
 * panel accepts. Restoring is deliberately not automated: putting several
 * megabytes of a family's data back over a live database is a decision, and the
 * panel already does it better than anything written here would.
 */

/** Something went wrong before the data was safe, so nothing may proceed. */
class BackupError extends RuntimeException {}

/**
 * No copy was written because the database holds nothing (ADR 0029 §3): its
 * ledger records no migration and every guarded table is empty. A class of its
 * own, because the two callers answer it differently: the runner goes on - a
 * first install has nothing to restore - where any other BackupError refuses the
 * update, and the console stops with the sentence, as it does for any error.
 */
class NothingToCopy extends BackupError {}

/**
 * Where the copies live: beside the maintenance flag, which is already the path
 * an operator with separate release folders points at shared storage, so a
 * release switch does not leave the backups behind in the old one.
 */
function backup_dir(): string { return dirname(maintenance_file()) . '/backups'; }

/** How many copies are kept. Older ones are removed as new ones are written. */
const BACKUP_KEEP = 5;

/**
 * Write a full copy of the database and return the path.
 *
 * $reason becomes part of the file name, so the operator opening the folder can
 * see what each copy was taken before.
 */
function backup_database(string $reason = 'update'): string {
    // Its own connection, unbuffered: a table with years of messages in it is
    // streamed row by row rather than loaded into memory all at once. Opened
    // before anything is written, so a database it cannot reach leaves no folder
    // and no empty half-file behind; its error is not wrapped, because the page
    // an update shows when it stops is public and a connection error names the
    // database user. Opened before the question below as well, so that a
    // database this cannot connect to is reported as that, not as empty.
    $reader = connect();
    $reader->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    // No copy of a database that holds nothing, whoever asks (ADR 0029 §3): a
    // first install has nothing to restore, and the copy would push an older one
    // out of the BACKUP_KEEP that are kept. Decided here, once, for the runner,
    // the console and anything that calls this later. The question is asked on
    // the main connection, not the reader, and it reads a connection error as
    // "no ledger, no rows". That is safe here because both callers' main
    // connection has already answered in this request - the runner's lock and
    // its refusal checks, the console's restore check - each of which stops on
    // its own error if it has not.
    if (schema_first_install())
        throw new NothingToCopy('No copy written: the database holds nothing - its ledger records no migration and every guarded table is empty - and a copy of nothing could only push an older one out (ADR 0029 §3).');

    $dir = backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true))
        throw new BackupError('Cannot create ' . $dir);
    // The folder holds every family's data in the clear. storage/ already denies
    // itself, and this is the copy of that rule that travels with the folder.
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");

    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($reason)) ?: 'backup';
    // Sortable first so the file manager lists them in order, random last so a
    // server that ever fails to deny this folder still does not hand them out.
    // Seconds, not minutes: two copies in the same minute would otherwise be
    // ordered by their random part, and pruning would pick a victim at random.
    $path = $dir . '/' . gmdate('Y-m-d-His') . '-' . $slug . '-' . bin2hex(random_bytes(4)) . '.sql';
    $handle = @fopen($path . '.part', 'wb');
    if (!$handle) throw new BackupError('Cannot write into ' . $dir);
    try {
        backup_write($handle, $reader, $reason);
    } catch (Throwable $e) {
        fclose($handle);
        @unlink($path . '.part');
        throw new BackupError('The backup could not be completed: ' . $e->getMessage(), 0, $e);
    }
    // fflush before fclose: PHP buffers writes, so the last block of the dump is
    // still in memory here and a full disk would otherwise be reported - if at
    // all - by fclose, after the file has already been named as complete.
    if (!fflush($handle)) { fclose($handle); @unlink($path . '.part'); throw new BackupError('The backup could not be written to disk; it may be full.'); }
    if (!fclose($handle)) throw new BackupError('The backup could not be closed; the disk may be full.');
    // Named only once it is complete, so a half-written file can never be
    // mistaken for something restorable.
    if (!@rename($path . '.part', $path)) { @unlink($path . '.part'); throw new BackupError('Cannot finish ' . $path); }
    @chmod($path, 0640);
    backup_prune();
    return $path;
}

/**
 * The table a copy makes before it drops its first table, and drops as its last
 * statement: while it exists, a copy is being imported into this database, or its
 * import stopped, and nothing but the import touches it (ADR 0029). It is not
 * schema: no migration makes it, the ledger never records it, and the portal
 * itself never makes, fills or drops it. Its name is spelled here only.
 */
const IMPORT_UNFINISHED_TABLE = 'import_unfinished';

/** Whether a copy is being imported into this database, or its import stopped. */
function import_unfinished(): bool {
    return (bool)scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                        [IMPORT_UNFINISHED_TABLE]);
}

/**
 * The dump itself. Split out so the failure path above has one place to clean up.
 *
 * It opens and closes with the marker of an import in progress: made after the
 * SET lines, so its comment is read as utf8mb4, and before the first table is
 * dropped; dropped as the very last statement, so that only an import that got
 * through every row takes it away. IF NOT EXISTS, because a copy whose import
 * stopped is imported again from its first statement, as INSTALL.md says it can.
 */
function backup_write($handle, PDO $reader, string $reason): void {
    // fwrite reports a short write by returning fewer bytes than it was given,
    // not by returning false, and a disk that fills up mid-dump does exactly
    // that. Counting the bytes is the difference between a backup that refuses
    // and a truncated file that looks restorable until the day it is needed.
    $put = function (string $text) use ($handle): void {
        $written = fwrite($handle, $text);
        if ($written === false || $written !== strlen($text))
            throw new RuntimeException('Writing the backup failed; the disk may be full.');
    };
    // The release whose database this is. A copy taken before an update holds
    // the previous release's tables, and naming the release about to replace
    // them sent a restore to the wrong files. A database no release has
    // written yet is this release's.
    $put("-- Badminton CRM " . (database_version() !== '' ? database_version() : app_version()) . " — " . $reason . "\n"
       . "-- " . gmdate('Y-m-d H:i:s') . " UTC\n"
       . "-- Restore by importing this file into an EMPTY database in the hosting panel.\n"
       . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n"
       . "CREATE TABLE IF NOT EXISTS `" . IMPORT_UNFINISHED_TABLE . "` (importing TINYINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
       . " COMMENT='Eine Sicherung wird gerade eingespielt. Ihre letzte Zeile löscht diese Tabelle wieder."
       . " A copy is being imported. Its last statement drops this table.';\n\n");
    foreach (backup_tables($reader) as $table) {
        $quoted = '`' . sql_name($table, 'table') . '`';
        $create = $reader->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
        $put("DROP TABLE IF EXISTS " . $quoted . ";\n" . $create[1] . ";\n");
        $rows = $reader->query('SELECT * FROM ' . $quoted);
        $count = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) $values[] = $value === null ? 'NULL' : $reader->quote((string)$value);
            // One statement per row: an import that stops partway then says which
            // row it stopped on, which a single enormous statement cannot.
            $put("INSERT INTO " . $quoted . " VALUES (" . implode(',', $values) . ");\n");
            $count++;
        }
        $put("-- " . $count . " rows\n\n");
    }
    $put("SET FOREIGN_KEY_CHECKS=1;\n");
    $put("DROP TABLE IF EXISTS `" . IMPORT_UNFINISHED_TABLE . "`;\n");
}

/**
 * Base tables only; a view would be dumped as data it does not own. Never the
 * marker of an import in progress: a copy makes it first and drops it last, and
 * nowhere else (ADR 0029 §2).
 */
function backup_tables(PDO $reader): array {
    $tables = [];
    foreach ($reader->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'") as $row)
        if (($name = (string)array_values($row)[0]) !== IMPORT_UNFINISHED_TABLE) $tables[] = $name;
    sort($tables);
    return $tables;
}

/**
 * The copies that exist, newest first.
 *
 * By modification time rather than by name, because the name is what pruning
 * would otherwise sort on and two copies written in the same second differ only
 * in their random part - which would make the choice of what to delete a coin
 * toss, including the copy that was just taken before a migration.
 */
function backups(): array {
    $dir = backup_dir();
    $files = array_filter(array_map(fn(string $name): string => $dir . '/' . $name, dir_entries($dir, '.sql')), 'is_file');
    usort($files, fn($a, $b) => [(int)@filemtime($b), basename($b)] <=> [(int)@filemtime($a), basename($a)]);
    return array_map(fn($f) => ['path' => $f, 'name' => basename($f), 'bytes' => (int)@filesize($f),
                                'made_at' => gmdate('Y-m-d H:i:s', (int)@filemtime($f))], $files);
}

/**
 * Keep the most recent few.
 *
 * Shared hosting sells disk by the gigabyte and a portal nobody prunes would
 * eventually fill it, which is its own kind of outage.
 */
function backup_prune(int $keep = BACKUP_KEEP): void {
    foreach (array_slice(backups(), max(1, $keep)) as $old) @unlink($old['path']);
    $dir = backup_dir();
    foreach (dir_entries($dir, '.sql.part') as $stale)
        if (@filemtime($dir . '/' . $stale) < time() - 3600) @unlink($dir . '/' . $stale);
}

/**
 * The file an operator creates when she has taken her own backup in the panel
 * and wants the portal to stop insisting.
 *
 * A file rather than a button, because the portal is closed at the moment she
 * needs it and there is nothing to sign in to. It is consumed on use, so it
 * cannot sit there quietly disabling the safeguard for every future update.
 */
function backup_override_file(): string { return dirname(maintenance_file()) . '/skip-backup'; }

/**
 * Whether the file is there, consuming it if so.
 *
 * One that cannot be removed is refused with a BackupError, neither claimed nor
 * ignored: claimed, it would skip the copy before every later update too;
 * ignored, an update whose backup fails would ask her for the very file that is
 * already there.
 */
function backup_override_claimed(): bool {
    $file = backup_override_file();
    if (!is_file($file)) return false;
    if (@unlink($file)) return true;
    throw new BackupError('Cannot remove ' . $file);
}
