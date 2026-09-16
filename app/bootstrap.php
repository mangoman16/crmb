<?php
declare(strict_types=1);

// install.php defines ROOT and knows where the configuration lives, because it
// is also the one file that runs when there is no configuration at all.
require_once __DIR__ . '/install.php';
$configPath = config_path();
// A fresh upload is not an error, it is an installation waiting to happen, so a
// browser goes to the setup page instead of a dead end.
if (!is_file($configPath)) install_redirect();
$config = require $configPath;
// config() reads the global, so publish it explicitly rather than relying on
// this file happening to be required at global scope. Required from inside a
// function - as a test harness or an installer might - every config() lookup
// would otherwise return null, and the failures would surface far from here.
$GLOBALS['config'] = $config;
date_default_timezone_set($config['timezone'] ?? 'Europe/Vienna');
if (strlen((string) base64_decode($config['app_key'] ?? '', true)) !== 32) {
    throw new RuntimeException('APP key must be a base64 encoded 32-byte key.');
}
if (!filter_var($config['app_url'], FILTER_VALIDATE_URL) || !in_array(parse_url($config['app_url'], PHP_URL_SCHEME), ['https','http'], true)) {
    throw new RuntimeException('Invalid app_url.');
}
require __DIR__ . '/core.php';
require __DIR__ . '/tx.php';
require __DIR__ . '/validate.php';
require __DIR__ . '/history.php';
require __DIR__ . '/defaults.php';
require __DIR__ . '/version.php';
require __DIR__ . '/backup.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/domain.php';
require __DIR__ . '/classes.php';
require __DIR__ . '/enrolment.php';
require __DIR__ . '/groups.php';
require __DIR__ . '/attendance.php';
require __DIR__ . '/billing.php';
require __DIR__ . '/demo.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/tick.php';
if (is_file(ROOT . '/vendor/autoload.php')) { require ROOT . '/vendor/autoload.php'; }
require __DIR__ . '/qr.php';

/**
 * Everything a web request needs that a command-line run does not: the session,
 * the response headers, an up-to-date schema and the maintenance gate.
 *
 * Kept out of the file body so the installer can load the application in order
 * to migrate and create the first administrator, without a session being
 * started or headers being sent from underneath its own page.
 */
function boot_http(): void {
    $config = $GLOBALS['config'];
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('badminton_session');
    session_set_cookie_params(['lifetime'=>0, 'path'=>(parse_url($config['app_url'], PHP_URL_PATH) ?: '/') . (str_ends_with(parse_url($config['app_url'], PHP_URL_PATH) ?: '/', '/') ? '' : '/'), 'secure'=>(bool)$config['secure_cookies'], 'httponly'=>true, 'samesite'=>'Lax']);
    if(!session_start())throw new RuntimeException('PHP session storage is unavailable.');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    if ($config['secure_cookies']) { header('Strict-Transport-Security: max-age=31536000'); }
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['de','en'], true)) { $_SESSION['locale'] = $_GET['lang']; }
    // Newly uploaded files may bring migrations the database has not seen. This
    // is what lets an update be "replace the files"; it costs one file read on
    // the common path, where the schema is already current.
    schema_ensure_current();
    // Maintenance mode holds everyone except an administrator, who needs a way
    // back in to switch it off without shell access. The flag file is checked
    // first and the admin lookup is guarded, because maintenance mode is exactly
    // when the database may be mid-migration - a failed lookup must still serve
    // the maintenance page rather than a database error.
    if (is_file(maintenance_file())) {
        $bypass = false;
        try { $bypass = is_admin(); } catch (Throwable) { $bypass = false; }
        if (!$bypass) {
            http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); header('Retry-After: 120');
            exit("Das Portal wird gerade aktualisiert. Bitte versuche es in wenigen Minuten erneut.\nThe portal is being updated. Please try again in a few minutes.\n");
        }
    }
}
