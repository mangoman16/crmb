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
 */

/** Migration files in the order they must be applied. */
function migration_files(): array {
    $files = glob(ROOT . '/database/migrations/*.sql') ?: [];
    sort($files);
    return $files;
}

/**
 * One value identifying the exact set of migrations shipped in these files.
 *
 * Contents rather than names, so an edited migration is noticed and refused by
 * schema_apply() instead of being silently treated as already applied.
 */
function schema_fingerprint(): string {
    $parts = [];
    foreach (migration_files() as $file) $parts[] = basename($file) . ':' . hash_file('sha256', $file);
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
 */
function schema_stamp_file(): string { return dirname(maintenance_file()) . '/schema.stamp'; }

/**
 * Whether the database already has every migration in these files.
 *
 * The stamp file is the fast path and answers without touching the database at
 * all, which matters because this runs on every page view. The settings row is
 * the durable answer for hosting where the stamp cannot be written; the file is
 * only ever a cache of it.
 */
function schema_is_current(): bool {
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
 * Everything a family would notice the loss of. Migrations in this project only
 * ever add, so a count going down means something went wrong rather than
 * something being cleaned up, and the portal stays closed until a person looks.
 */
function schema_guarded_tables(): array {
    return ['accounts', 'students', 'contacts', 'field_values', 'absences',
            'charges', 'payments', 'threads', 'messages', 'news'];
}

/**
 * Row counts for those tables, skipping any that do not exist yet.
 *
 * A first install has none of them, and comparing against a table that was
 * created by the very migration under test would report a fall from nothing.
 */
function schema_counts(): array {
    $counts = [];
    foreach (schema_guarded_tables() as $table) {
        try { $counts[$table] = (int)scalar('SELECT COUNT(*) FROM ' . sql_name($table, 'table')); }
        catch (PDOException) { /* not created yet */ }
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
 */
class SchemaError extends UpdateBlocked {
    public function __construct(
        public readonly string $version,
        public readonly int $statement,
        public readonly int $statements,
        public readonly string $sql,
        public readonly string $reason,
    ) {
        $where = $version . ' (' . $statement . '/' . $statements . ')';
        parent::__construct(
            'Eine Datenbankänderung ist fehlgeschlagen: ' . $where . '. Die Sicherung von vorher liegt im Ordner storage/backups.',
            'A database change failed: ' . $where . '. The copy taken beforehand is in storage/backups.',
            $version . ' failed at statement ' . $statement . ' of ' . $statements . ":\n\n"
            . substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 300) . "\n\n" . $reason . "\n\n"
            . 'Statements 1 to ' . ($statement - 1) . " were applied and this migration is NOT recorded as done,\n"
            . "so re-running would start it again from the beginning. Restore your backup, or\n"
            . "finish this migration by hand and add the row to schema_migrations yourself.");
    }

    /** The same fact without the SQL, for a page a visitor might be looking at. */
    public function summary(): string {
        return $this->version . ': ' . $this->statement . '/' . $this->statements;
    }
}

/**
 * Bring the database up to the schema these files describe.
 *
 * Safe to call repeatedly and safe to call from two requests at once: the
 * advisory lock serialises them and the second one finds nothing left to do.
 * Returns the names of the migrations it applied, which is empty on the common
 * path where everything was already there.
 */
function schema_apply(?callable $log = null, bool $safeguards = true): array {
    $log ??= static fn(string $line) => null;
    run('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Wait rather than refuse: two page views arriving together during an upgrade
    // should both end up served, not one of them erroring.
    if ((int)scalar("SELECT GET_LOCK('badminton_crm_migrate',30)") !== 1)
        throw new UpdateBlocked(
            'Eine andere Aktualisierung läuft gerade. Bitte die Seite in einer Minute neu laden.',
            'Another update is running right now. Please reload the page in a minute.',
            'Another migration held the lock for 30 seconds.');
    $applied = [];
    try {
        // Checked inside the lock and before anything is written, so two requests
        // cannot both decide the release is fine and then disagree.
        if ($safeguards) schema_refuse_unsafe($log);
        $before = schema_counts();
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
                try { db()->exec($statement); }
                catch (Throwable $e) { throw new SchemaError($version, $i + 1, count($statements), $statement, $e->getMessage()); }
            }
            run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$version, $hash, now()]);
            $applied[] = $version;
            $log('Applied ' . $version . ' (' . count($statements) . ' statements)');
        }
        schema_verify_counts($before, $log);
        require ROOT . '/database/defaults.php';
        setting_cache_clear();
        set_setting('schema_fingerprint', schema_fingerprint());
        // Recorded before the marker is overwritten, so the change log can say
        // which release the portal came from as well as which it is on.
        $was = database_version();
        if ($was !== app_version()) version_history_add($was, app_version());
        set_setting('schema_written_by', app_version());
        if ($applied) set_setting('schema_last_update', ['at' => now(), 'applied' => $applied]);
    } finally {
        run("SELECT RELEASE_LOCK('badminton_crm_migrate')");
    }
    schema_write_stamp();
    return $applied;
}

/**
 * The three things that must be true before a migration may run.
 *
 * All of them are about the upload rather than the SQL, because by the time
 * this runs the old files are already gone and the only remaining choice is
 * whether to touch the database as well.
 */
function schema_refuse_unsafe(callable $log): void {
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
    if (!schema_pending()) return;
    if (backup_override_claimed()) { $log('Backup skipped: storage/skip-backup was present.'); return; }
    try {
        $log('Writing a backup to ' . backup_database('vor-update'));
    } catch (BackupError $e) {
        throw new UpdateBlocked(
            'Vor der Aktualisierung konnte keine Sicherung angelegt werden: ' . $e->getMessage()
            . ' Bitte im Hosting-Panel selbst eine Sicherung der Datenbank anlegen und danach im Ordner storage eine leere Datei namens skip-backup erstellen.',
            'No backup could be taken before updating: ' . $e->getMessage()
            . ' Please export the database from the hosting panel yourself, then create an empty file named skip-backup in the storage folder.',
            'Pre-update backup failed: ' . $e->getMessage());
    }
}

/**
 * Compare what was there before with what is there now.
 *
 * Counts alone do not prove an update was correct, but a count that fell proves
 * it was not, and that is worth catching while the backup is still the most
 * recent thing that happened.
 */
function schema_verify_counts(array $before, callable $log): void {
    $lost = [];
    foreach (schema_counts() as $table => $now) {
        if (!array_key_exists($table, $before)) continue;
        $log(sprintf('    %-12s %d -> %d', $table, $before[$table], $now));
        if ($now < $before[$table]) $lost[] = $table . ' ' . $before[$table] . ' -> ' . $now;
    }
    if (!$lost) return;
    throw new UpdateBlocked(
        'Nach der Aktualisierung fehlen Datensätze (' . implode(', ', $lost) . '). Das Portal bleibt geschlossen. Die Sicherung von vorher liegt im Ordner storage/backups.',
        'Records are missing after the update (' . implode(', ', $lost) . '). The portal stays closed. The copy taken beforehand is in storage/backups.',
        'Row counts dropped: ' . implode(', ', $lost));
}

/**
 * Apply anything outstanding before serving a page, and say so plainly if that
 * fails.
 *
 * This is what makes an update "replace the files". It is skipped while
 * maintenance mode is on, because that is an operator doing the same thing by
 * hand and the two must not race.
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
 * The page shown when the database could not be brought up to date.
 *
 * Whoever opens the portal next is either the operator, who needs to know what
 * to do, or a parent, who needs to know it is not their fault. So it says what
 * went wrong in both languages and never prints SQL: this address is public,
 * and the full text is in the hosting error log.
 */
function schema_blocked_page(Throwable $e): never {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 300');
    header('Cache-Control: no-store');
    [$de, $en] = $e instanceof UpdateBlocked
        ? [$e->de, $e->en]
        : ['Die Datenbank konnte nicht aktualisiert werden. Die genaue Meldung steht im Fehlerprotokoll des Hostings.',
           'The database could not be updated. The full message is in the hosting error log.'];
    echo '<!doctype html><html lang="de"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Badminton</title>'
        . '<p><strong>Das Portal ist vorübergehend geschlossen.</strong></p><p>' . e($de) . '</p>'
        . '<p><strong>The portal is temporarily closed.</strong></p><p>' . e($en) . '</p></html>';
    exit;
}
