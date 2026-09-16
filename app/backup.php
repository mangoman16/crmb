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
 * Where the copies live: beside the maintenance flag, which is already the path
 * an operator with separate release folders points at shared storage, so a
 * release switch does not leave the backups behind in the old one.
 */
function backup_dir(): string { return dirname(maintenance_file()) . '/backups'; }

/** How many copies are kept. Older ones are removed as new ones are written. */
const BACKUP_KEEP = 5;

/**
 * Whether this connection is one the dump understands.
 *
 * The dump is written in MySQL's own dialect, read back by MySQL's own import.
 * Rather than translate it for the SQLite the test suite runs on, it says so.
 */
function backup_supported(): bool {
    try { return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
    catch (Throwable) { return false; }
}

/**
 * Write a full copy of the database and return the path.
 *
 * $reason becomes part of the file name, so the operator opening the folder can
 * see what each copy was taken before.
 */
function backup_database(string $reason = 'update'): string {
    if (!backup_supported())
        throw new BackupError('A backup can only be written from MySQL or MariaDB.');
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

    // Its own connection, unbuffered: a table with years of messages in it is
    // streamed row by row rather than loaded into memory all at once.
    $reader = connect();
    $reader->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
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

/** The dump itself. Split out so the failure path above has one place to clean up. */
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
    $put("-- Badminton CRM " . trim((string)file_get_contents(ROOT . '/VERSION')) . " — " . $reason . "\n"
       . "-- " . gmdate('Y-m-d H:i:s') . " UTC\n"
       . "-- Restore by importing this file into an EMPTY database in the hosting panel.\n"
       . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
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
}

/** Base tables only; a view would be dumped as data it does not own. */
function backup_tables(PDO $reader): array {
    $tables = [];
    foreach ($reader->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'") as $row)
        $tables[] = (string)array_values($row)[0];
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
    $files = glob(backup_dir() . '/*.sql') ?: [];
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
    foreach (glob(backup_dir() . '/*.sql.part') ?: [] as $stale)
        if (@filemtime($stale) < time() - 3600) @unlink($stale);
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

function backup_override_claimed(): bool {
    if (!is_file(backup_override_file())) return false;
    @unlink(backup_override_file());
    return true;
}
