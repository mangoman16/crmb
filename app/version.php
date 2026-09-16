<?php
declare(strict_types=1);

/**
 * Which release is installed, and whether the files and the database agree.
 *
 * The version is deliberately written down twice: VERSION ships with the files,
 * and the database records which release last wrote it. One copy cannot tell
 * you that an upload half-succeeded; two copies that disagree can, and that is
 * the whole reason for keeping both.
 *
 * Nothing here decides anything - schema.php still refuses an unsafe update on
 * its own. This is the part the operator can look at and understand.
 */

/** The version these files ship. Read once; it cannot change mid-request. */
function app_version(): string {
    static $version;
    return $version ??= trim((string)file_get_contents(ROOT . '/VERSION'));
}

/** The version that last wrote the database, or '' if none ever has. */
function database_version(): string {
    try { return (string)setting('schema_written_by'); } catch (Throwable) { return ''; }
}

/**
 * How files and database stand relative to one another.
 *
 * state is one of:
 *   fresh    the database has never been written by any release
 *   current  both sides agree and nothing is waiting
 *   pending  the files are newer and carry migrations not yet applied
 *   ahead    the files are newer but bring no migration; the marker is stale
 *   older    the database has migrations these files do not contain
 *
 * Only 'older' is a fault. It is the shape of an upload that put an earlier
 * release back, where nothing looks wrong because there is nothing to apply.
 */
function version_status(): array {
    $files = app_version();
    $database = database_version();
    $pending = [];
    $extra = [];
    try { $pending = schema_pending(); } catch (Throwable) { $pending = array_map('basename', migration_files()); }
    try { $extra = schema_extra(); } catch (Throwable) { $extra = []; }

    if ($extra)                 $state = 'older';
    elseif ($database === '')   $state = 'fresh';
    elseif ($pending)           $state = 'pending';
    elseif ($database !== $files) $state = 'ahead';
    else                        $state = 'current';

    return [
        'state'    => $state,
        'ok'       => in_array($state, ['current', 'fresh'], true),
        'files'    => $files,
        'database' => $database,
        'pending'  => $pending,
        'extra'    => $extra,
        'applied'  => version_applied_count(),
        'history'  => version_history(),
    ];
}

function version_applied_count(): int {
    try { return (int)scalar('SELECT COUNT(*) FROM schema_migrations'); } catch (Throwable) { return 0; }
}

/** One sentence about that status, for a person rather than a log. */
function version_status_text(array $status): string {
    return match ($status['state']) {
        'fresh'   => t('Die Datenbank wurde noch von keiner Version geschrieben.', 'No release has written this database yet.'),
        'current' => t('Dateien und Datenbank gehören zur selben Version.', 'Files and database belong to the same release.'),
        'pending' => t('Die Datenbank wird beim nächsten Seitenaufruf nachgezogen.', 'The database will catch up on the next page view.'),
        'ahead'   => t('Die Dateien sind neuer, brauchen aber keine Datenbankänderung.', 'The files are newer but need no database change.'),
        'older'   => t('Die Dateien sind älter als die Datenbank. Bitte die neueste Version einspielen.', 'The files are older than the database. Please install the newest release.'),
        default   => '',
    };
}

/**
 * The releases this database has been through, newest first.
 *
 * Appended by schema_apply() whenever the recorded version changes, so the
 * change log can say when the portal moved from one release to the next without
 * a table of its own.
 */
function version_history(): array {
    try { $history = setting('version_history'); } catch (Throwable) { return []; }
    return is_array($history) ? $history : [];
}

/** Record a move from one release to another. Called only when they differ. */
function version_history_add(string $from, string $to): void {
    $history = version_history();
    array_unshift($history, ['at' => now(), 'from' => $from, 'to' => $to]);
    set_setting('version_history', array_slice($history, 0, 40));
}
