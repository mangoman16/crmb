<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
$configPath = getenv('CRM_CONFIG') ?: ROOT . '/config/config.php';
if (!is_file($configPath)) {
    if (PHP_SAPI !== 'cli') { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); }
    exit("Konfiguration fehlt. Bitte INSTALL.md befolgen. / Configuration missing; see INSTALL.md.\n");
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Europe/Vienna');
if (strlen((string) base64_decode($config['app_key'] ?? '', true)) !== 32) {
    throw new RuntimeException('APP key must be a base64 encoded 32-byte key.');
}
if (!filter_var($config['app_url'], FILTER_VALIDATE_URL) || !in_array(parse_url($config['app_url'], PHP_URL_SCHEME), ['https','http'], true)) {
    throw new RuntimeException('Invalid app_url.');
}
require __DIR__ . '/core.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/domain.php';
require __DIR__ . '/mail.php';
if (is_file(ROOT . '/vendor/autoload.php')) { require ROOT . '/vendor/autoload.php'; }
if (PHP_SAPI !== 'cli') {
    if(is_file(maintenance_file())) {
        http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Retry-After: 120');
        exit("Das Portal wird gerade aktualisiert. Bitte versuche es in wenigen Minuten erneut.\nThe portal is being updated. Please try again in a few minutes.\n");
    }
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
    if ($config['secure_cookies']) { header('Strict-Transport-Security: max-age=31536000'); }
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['de','en'], true)) { $_SESSION['locale'] = $_GET['lang']; }
}
