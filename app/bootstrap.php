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
// Pure colour arithmetic, no database or settings. setting_validate() in
// defaults.php below needs its contrast check to refuse an unreadable
// background, and nothing it needs comes later (ADR 0013).
require __DIR__ . '/colour.php';
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
require __DIR__ . '/shell.php';
// avatar() in shell.php above calls picture_of(), picture_stored() and
// may_see_picture() from here, and current_user() in auth.php calls
// child_picture_columns(); from here, upload_types() calls message_upload_types()
// in messaging.php below and square_picture() calls jpeg_orientation() in
// brand.php below. Each at request time only - never while loading - so this
// order is safe as it stands (ADR 0031 §10). Do not "tidy" it by moving a file
// without checking those calls.
require __DIR__ . '/uploads.php';
// The club's colours and logo. Needs setting() and setting_colour_refusal()
// from defaults.php, colour.php, is_staff() from auth.php and uploads.php's
// folders and cache headers above; before portal_icon.php, whose web_manifest()
// reads brand_theme_colour(). Its own calls into portal_icon.php happen at
// request time only. accent_for() in shell.php above calls brand_chosen() from
// here, at request time only - never while loading; otherwise only the router,
// the actions, app/ui.php and the layout call this file, per request
// (ADR 0013, 0014).
require __DIR__ . '/brand.php';
// Needs uploads.php above: upload_dir(), upload_version(), the download and
// cache headers. Nothing loaded earlier calls it: only the router and the
// layout do, per request.
require __DIR__ . '/portal_icon.php';
require __DIR__ . '/pdf.php';
require __DIR__ . '/invoices.php';
require __DIR__ . '/demo.php';
// demo_fill() above makes a course's group and a chat with
// course_group_thread() and direct_thread() from here, at request time only -
// never while loading - so this order is safe as it stands.
require __DIR__ . '/messaging.php';
require __DIR__ . '/mail.php';
// The start checklist asks the course, billing, invoice, mail and account rules
// above whether each step is done, so it comes after all of them. Nothing loaded
// earlier calls it: only the router, the actions, app/ui.php and the views do,
// at request time (ADR 0011).
require __DIR__ . '/start.php';
require __DIR__ . '/tick.php';
if (is_file(ROOT . '/vendor/autoload.php')) { require ROOT . '/vendor/autoload.php'; }
require __DIR__ . '/qr.php';

/**
 * Where sign-in sessions are kept: beside the maintenance flag, like every other
 * file the portal stores, which is storage/sessions unless the configuration
 * moved it.
 */
function session_dir(): string { return dirname(maintenance_file()) . '/sessions'; }

/**
 * Keep this portal's sessions in session_dir() rather than in the host's
 * default folder, and make sure something empties it.
 *
 * On shared hosting the default is often one folder for every customer on the
 * machine (/tmp, /var/lib/php/sessions), where a neighbour's script can list
 * the session files and read or plant one. Returns the folder, or '' when the
 * sessions stayed where the host keeps them: a host that keeps them somewhere
 * other than files, or a folder that could not be made, which goes in the error
 * log rather than leaving nobody able to sign in.
 */
function use_own_session_folder(): string {
    // Only the files handler keeps sessions in a folder. A host that stores them
    // in Redis or Memcached names a server in save_path, and a folder in its
    // place would lose every session on the next request.
    if (ini_get('session.save_handler') !== 'files') return '';
    $dir = session_dir();
    // 0700: this account's alone. A umask can only take bits away from it.
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('CRM sessions: cannot create ' . $dir . '; sessions stay in the host\'s folder ('
            . (session_save_path() ?: sys_get_temp_dir()) . '). Make storage/ writable to move them.');
        return '';
    }
    // storage/ already denies itself; this is the copy of that rule that travels
    // with the folder, as the backups carry theirs.
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    ini_set('session.save_path', $dir);
    // Debian and Ubuntu switch PHP's own clean-up off and leave it to a cron job
    // that only knows the default folder, so nothing else would ever empty this
    // one. On one session start in a hundred, PHP removes what has been idle
    // longer than anybody may stay signed in - never less, or the host's 24
    // minutes would sign people out inside the portal's own limit.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', (string)max((int)ini_get('session.gc_maxlifetime'), (int)config('session_idle_minutes') * 60));
    return $dir;
}

/**
 * The path the portal's cookies are set for: app_url's own, with the slash a
 * cookie path needs, so a portal under /verein keeps its cookies to itself.
 */
function portal_cookie_path(): string {
    $path = (string)(parse_url((string)config('app_url'), PHP_URL_PATH) ?: '/');
    return str_ends_with($path, '/') ? $path : $path . '/';
}

/**
 * The cookie a redirect to HTTPS leaves behind.
 *
 * It is Secure, and a browser sends a Secure cookie over HTTPS only, so a
 * request that carries it was encrypted, whatever the server can see.
 */
const HTTPS_SEEN_COOKIE = 'badminton_https';

/**
 * The https:// address a request should have used, or null when it is right as
 * it is.
 *
 * Only for a portal whose address is an https:// one, so app_url decides and
 * not the web server: a portal set up without a certificate goes on working
 * exactly as before an update, which no rule in .htaccess could promise. Not
 * for a request that arrived encrypted - by the server's word, a proxy's
 * (install_is_https()), or the browser's (HTTPS_SEEN_COOKIE) - and not on this
 * computer, which setup lets run over plain HTTP too.
 *
 * The cookie is what keeps a proxy that ends TLS without saying so from being
 * sent round in a circle: such a browser is redirected once, to the address it
 * was already on, and comes back carrying the cookie. Only a browser that
 * refuses every cookie would go round, and it could not sign in anyway.
 *
 * Always on app_url's own host and port, never the one the request named, so a
 * request line cannot point the redirect anywhere else.
 */
function https_address(array $server, array $cookies, string $appUrl): ?string {
    if (strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) !== 'https') return null;
    if (install_is_https($server) || ($cookies[HTTPS_SEEN_COOKIE] ?? null) === '1') return null;
    if (install_host_is_local((string)($server['HTTP_HOST'] ?? ''))) return null;
    $uri = str_replace(' ', '%20', (string)preg_replace('/[\x00-\x1F\x7F]/', '', (string)($server['REQUEST_URI'] ?? '/')));
    // A request line may carry a whole address ("GET http://elsewhere/x");
    // only its path and query are kept.
    if (!str_starts_with($uri, '/')) {
        $parts = parse_url($uri) ?: [];
        $uri = '/' . ltrim((string)($parts['path'] ?? ''), '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
    $port = parse_url($appUrl, PHP_URL_PORT);
    return 'https://' . (string)parse_url($appUrl, PHP_URL_HOST) . ($port ? ':' . $port : '') . $uri;
}

/**
 * Answer a request that came over plain HTTP to a portal with an https://
 * address, and end it, before a session or anything else has gone out.
 *
 * A page is sent on, permanently. A form is refused rather than followed: what
 * it carried has already crossed the network readable, and passing it on would
 * act on it as though nothing had happened.
 */
function send_to_https(string $address): never {
    header('Cache-Control: no-store');
    if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        $start = rtrim((string)config('app_url'), '/') . '/';
        exit("Dieses Portal nimmt Eingaben nur verschlüsselt an. Bitte die Seite über $start neu öffnen und noch einmal senden.
"
            . "This portal only accepts input encrypted. Please open the page again from $start and send it once more.
");
    }
    setcookie(HTTPS_SEEN_COOKIE, '1', ['expires' => time() + 31536000, 'path' => portal_cookie_path(),
                                       'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . $address, true, 301);
    exit;
}

/**
 * The Strict-Transport-Security header, or null for a portal that is not an
 * HTTPS one: an https:// address, or secure cookies, which promise the same.
 *
 * Sent whatever the server can tell about the connection, because a browser
 * honours it only from a response that reached it encrypted and ignores it on
 * any other (RFC 6797, 8.1) - and behind a proxy that does not say it used
 * HTTPS, the browser knows and the server does not. A year; not the subdomains,
 * which may be somebody else's, and not the preload list, which is slow to leave.
 */
function strict_transport_security(array $config): ?string {
    $https = strtolower((string)parse_url((string)($config['app_url'] ?? ''), PHP_URL_SCHEME)) === 'https';
    return $https || !empty($config['secure_cookies']) ? 'Strict-Transport-Security: max-age=31536000' : null;
}

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
    // First: nothing - no session cookie, no page, no form - goes out over plain
    // HTTP for a portal whose address is an https:// one.
    if (($secure = https_address($_SERVER, $_COOKIE, (string)$config['app_url'])) !== null) send_to_https($secure);
    use_own_session_folder();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('badminton_session');
    session_set_cookie_params(['lifetime'=>0, 'path'=>portal_cookie_path(), 'secure'=>(bool)$config['secure_cookies'], 'httponly'=>true, 'samesite'=>'Lax']);
    if(!session_start())throw new RuntimeException('PHP session storage is unavailable.');
    // After session_start(), whose own Cache-Control the list's replaces.
    foreach (security_headers() as $header) header($header);
    if ($hsts = strict_transport_security($config)) header($hsts);
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
        // Nobody, administrators included, while an update is unfinished: what
        // they add would change the numbers the update compares, and enough new
        // rows hide a loss. The way back is the file manager (ADR 0027 §3).
        try { $bypass = !schema_is_unfinished() && is_admin(); } catch (Throwable) { $bypass = false; }
        if (!$bypass) {
            http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); header('Retry-After: 120');
            exit("Das Portal wird gerade aktualisiert. Bitte versuche es in wenigen Minuten erneut.\nThe portal is being updated. Please try again in a few minutes.\n");
        }
    }
}
