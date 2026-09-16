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
    $want = schema_fingerprint();
    if (@file_get_contents(schema_stamp_file()) === $want) return true;
    try { $have = (string)setting('schema_fingerprint', ''); } catch (Throwable) { return false; }
    if ($have !== $want) return false;
    schema_write_stamp($want);
    return true;
}

/** Best effort: a read-only storage directory costs a query per request, not correctness. */
function schema_write_stamp(?string $fingerprint = null): void {
    @file_put_contents(schema_stamp_file(), $fingerprint ?? schema_fingerprint());
}

/** Migration files that have not been recorded as applied. */
function schema_pending(): array {
    $applied = array_column(rows('SELECT version FROM schema_migrations'), 'version');
    return array_values(array_filter(array_map('basename', migration_files()),
        fn($version) => !in_array($version, $applied, true)));
}

/**
 * A migration that stopped partway.
 *
 * MySQL cannot roll back DDL, so this is the one failure the operator has to
 * decide about rather than retry. It carries which file and which statement so
 * every caller can say that much without re-parsing an error message.
 */
class SchemaError extends RuntimeException {
    public function __construct(
        public readonly string $version,
        public readonly int $statement,
        public readonly int $statements,
        public readonly string $sql,
        public readonly string $reason,
    ) {
        parent::__construct($version . ' failed at statement ' . $statement . ' of ' . $statements . ":\n\n"
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
function schema_apply(?callable $log = null): array {
    run('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Wait rather than refuse: two page views arriving together during an upgrade
    // should both end up served, not one of them erroring.
    if ((int)scalar("SELECT GET_LOCK('badminton_crm_migrate',30)") !== 1)
        throw new RuntimeException('Another migration is still running. Try again in a moment.');
    $applied = [];
    try {
        foreach (migration_files() as $file) {
            $version = basename($file);
            $hash = hash_file('sha256', $file);
            $old = one('SELECT * FROM schema_migrations WHERE version=?', [$version]);
            if ($old) {
                if (!hash_equals($old['checksum'], $hash))
                    throw new RuntimeException('Migration ' . $version . ' has changed since it was applied. Never edit an applied migration; add a new one instead. To accept a deliberate change, update its checksum in schema_migrations.');
                continue;
            }
            $statements = split_sql((string)file_get_contents($file));
            foreach ($statements as $i => $statement) {
                try { db()->exec($statement); }
                catch (Throwable $e) { throw new SchemaError($version, $i + 1, count($statements), $statement, $e->getMessage()); }
            }
            run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$version, $hash, now()]);
            $applied[] = $version;
            if ($log) $log('Applied ' . $version . ' (' . count($statements) . ' statements)');
        }
        require ROOT . '/database/defaults.php';
        setting_cache_clear();
        set_setting('schema_fingerprint', schema_fingerprint());
    } finally {
        run("SELECT RELEASE_LOCK('badminton_crm_migrate')");
    }
    schema_write_stamp();
    return $applied;
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
 * It names the migration and statement but not the SQL: this address is public,
 * and the full text is in the server error log where the operator's hosting
 * panel shows it.
 */
function schema_blocked_page(Throwable $e): never {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 300');
    header('Cache-Control: no-store');
    $where = $e instanceof SchemaError ? ' (' . $e->summary() . ')' : '';
    echo '<!doctype html><html lang="de"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Badminton</title>'
        . '<p>Die Datenbank konnte nicht aktualisiert werden' . e($where) . '. Das Portal bleibt so lange geschlossen. '
        . 'Die genaue Meldung steht im Fehlerprotokoll des Hostings.</p>'
        . '<p>The database could not be updated' . e($where) . '. The portal stays closed until it is. '
        . 'The full message is in the hosting error log.</p></html>';
    exit;
}
