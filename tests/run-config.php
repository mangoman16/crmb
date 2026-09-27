<?php
declare(strict_types=1);

/**
 * The configuration file for one suite run against a real database.
 *
 * Shared by tests/mariadb-local.sh and tests/existing-database.sh, so a key the
 * application starts to require is added once rather than in each script, and
 * no value is ever pasted into PHP source without var_export() quoting it.
 * tests/harness.php still writes its own copy for the sqlite driver.
 *
 * $db is either the connection itself or the path of a file that returns it.
 * A path keeps a saved password in the one file that already holds it.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function write_run_config(string $path, array|string $db, string $work): void {
    $source = "<?php\nreturn [\n"
        ."    'app_url' => 'http://127.0.0.1:4192',\n"
        ."    'app_key' => ".var_export(base64_encode(random_bytes(32)), true).",\n"
        ."    'db' => ".(is_string($db) ? 'require '.var_export($db, true) : var_export($db, true)).",\n"
        ."    'timezone' => 'Europe/Vienna',\n"
        ."    'secure_cookies' => false,\n"
        ."    'session_idle_minutes' => 120,\n"
        // Uploads and backups are written beside the maintenance file. On a host
        // where the suite runs inside the live checkout, anywhere under storage/
        // would put the suite's files among the portal's own.
        ."    'maintenance_file' => ".var_export($work.'/maintenance.flag', true).",\n"
        ."];\n";
    if (file_put_contents($path, $source) === false) throw new RuntimeException('Could not write '.$path.'.');
}
