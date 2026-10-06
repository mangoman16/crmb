<?php
declare(strict_types=1);

/**
 * Everything needed before there is a configuration file.
 *
 * public/setup.php loads this on a server where config/config.php does not exist
 * yet, and app/bootstrap.php loads it to decide whether there is anything to
 * boot at all. Nothing here may depend on app/core.php, so this file carries its
 * own escaping and its own translation helper rather than borrowing e() and t().
 *
 * The operator this is written for has a hosting panel, a file manager and no
 * shell. Every failure therefore has to say what to click, not what to run.
 */

if (!defined('ROOT')) define('ROOT', __DIR__ . '/..');

// ---------------------------------------------------------------------------
// Text and escaping, standalone versions of t() and e()
// ---------------------------------------------------------------------------

function install_locale(?string $set = null): string {
    static $locale = 'de';
    if ($set !== null && in_array($set, ['de', 'en'], true)) $locale = $set;
    return $locale;
}

function install_t(string $de, string $en): string { return install_locale() === 'en' ? $en : $de; }

/** The setup page's escape function. Every value it prints goes through this. */
function install_e(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Reading what a form sent
// ---------------------------------------------------------------------------

/**
 * A submitted field as every form here reads it: text, without the spaces
 * around it. null when it is not text at all, which only a crafted request sends.
 *
 * The one copy of the rule. It lives here because the installer runs before the
 * rest of the application is loaded; post() uses it for every other form, sign-in
 * included. Setup once kept the spaces around the first administrator's password
 * while sign-in removed them, so a password typed with a stray space was stored
 * as one thing and checked as another, and she could never sign in.
 */
function form_text(mixed $value): ?string { return is_scalar($value) ? trim((string)$value) : null; }

/**
 * The text the installer's form sent, read the way every other form is read.
 *
 * Except the database password, which arrives exactly as typed: the database
 * server checks it, not the portal, and the hosting panel decides what it is.
 */
function install_submission(array $post, array $form): array {
    foreach (array_keys($form) as $field) $form[$field] = form_text($post[$field] ?? $form[$field]) ?? $form[$field];
    return ['form' => $form,
            'password' => form_text($post['admin_password'] ?? '') ?? '',
            'repeat' => form_text($post['admin_password2'] ?? '') ?? '',
            'db_password' => is_string($post['db_password'] ?? null) ? $post['db_password'] : ''];
}

// ---------------------------------------------------------------------------
// Where things are
// ---------------------------------------------------------------------------

function config_path(): string { return getenv('CRM_CONFIG') ?: ROOT . '/config/config.php'; }

/**
 * Where a file that ships in public/assets/ is, seen from public/, with a hash
 * of its bytes in the address: "assets/app.css?v=3f9c0a1b2c4d".
 *
 * Its bytes, not the release number and not the file's date. VERSION stayed the
 * same across the release that brought the account menu's styles, so app.css
 * kept its address, browsers went on using the copy they had, and the menu
 * opened unstyled across the page while the bell jumped as it used to. An FTP
 * client can also keep a file's old date on new bytes. The hash changes exactly
 * when the file does, so an unchanged file stays cached and a changed one is
 * fetched on the next page, whatever the server says about caching.
 *
 * Here rather than in app/core.php because setup.php links the stylesheet too,
 * before there is a configuration to take the portal's address from. Read once
 * per file per request. A file that is missing gets no hash; its link answers
 * 404 either way.
 */
function asset_path(string $file): string {
    static $paths = [];
    if (!isset($paths[$file])) {
        $bytes = ROOT . '/public/assets/' . $file;
        $hash = is_file($bytes) ? hash_file('sha256', $bytes) : false;
        $paths[$file] = 'assets/' . $file . ($hash !== false ? '?v=' . substr($hash, 0, 12) : '');
    }
    return $paths[$file];
}

/**
 * The address the portal answers on, derived from the request that is asking.
 *
 * This ends up in config as app_url, which builds every link and every email
 * link, so it has to be right for all three layouts that occur in practice:
 * the web root pointing at public/, the web root pointing at the project with
 * the root .htaccess rewriting into public/, and neither, with the operator
 * opening /public/setup.php by hand.
 */
function install_base_url(array $server): string {
    $host = (string)($server['HTTP_HOST'] ?? '');
    // The Host header is whatever the client sent and would be echoed into every
    // invitation email, so it has to look like a host name before it is used.
    if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?(:\d{1,5})?$/D', $host))
        $host = (string)($server['SERVER_NAME'] ?? 'localhost');
    $path = (string)(parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    // /verein/setup.php and /verein/index.php both describe a portal at /verein.
    if (str_ends_with($path, '.php')) $path = substr($path, 0, (int)strrpos($path, '/'));
    return (install_is_https($server) ? 'https' : 'http') . '://' . $host . rtrim($path, '/');
}

/**
 * Whether this request arrived over HTTPS.
 *
 * The forwarded headers can be set by a client that is not behind a proxy. The
 * only thing that buys an attacker is an https guess in a field the operator is
 * looking at and can correct, and guessing https is the safe direction.
 */
function install_is_https(array $server): bool {
    $https = strtolower((string)($server['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') return true;
    if ((int)($server['SERVER_PORT'] ?? 0) === 443) return true;
    if (strtolower((string)($server['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SCHEME'] as $header)
        if (str_starts_with(strtolower((string)($server[$header] ?? '')), 'https')) return true;
    return false;
}

function install_setup_url(array $server): string { return install_base_url($server) . '/setup.php'; }

/**
 * Whether a host, as a Host header or an address gives it, is the computer the
 * portal runs on: the one place it may be tried out over plain HTTP.
 *
 * The root and public .htaccess files leave the same hosts on http:// when they
 * send everything else to https://; the install suite holds both to one list.
 */
function install_host_is_local(string $host): bool {
    $host = strtolower(trim($host));
    if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/D', $host, $m)) $host = $m[1];        // [::1]:8080
    elseif (substr_count($host, ':') === 1) $host = explode(':', $host)[0];              // localhost:8080
    if ($host === 'localhost' || $host === '::1' || preg_match('/^[a-z0-9.-]+\.localhost$/D', $host)) return true;
    return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
}

/**
 * Whether this request may carry an installation: over HTTPS, or on this
 * computer. Over plain HTTP anywhere else, the database password and hers
 * would cross the network readable, and the portal would be written down as an
 * http:// one with a cookie anybody on the same Wi-Fi could copy.
 *
 * Refused rather than warned about with a box to tick: a box is ticked to make
 * the warning go away, and what it costs is permanent, because app_url and
 * secure_cookies are written into the configuration once and not looked at again.
 */
function install_request_secure(array $server): bool {
    return install_is_https($server) || install_host_is_local((string)($server['HTTP_HOST'] ?? ''));
}

/** The same setup page, over https://. */
function install_secure_setup_url(array $server): string {
    return 'https://' . (string)preg_replace('#^https?://#', '', install_setup_url($server));
}

// ---------------------------------------------------------------------------
// Does this server have what the application needs
// ---------------------------------------------------------------------------

/**
 * Files in this release that do not match the manifest it shipped with.
 *
 * A file manager extracts a ZIP one file at a time, so an upload interrupted
 * halfway leaves a release that is half old and half new, and an FTP client in
 * text mode rewrites the line endings of every PHP file it touches. Both look
 * like a working directory listing. The manifest is what tells them apart, and
 * it is checked before a migration runs rather than on every page view.
 *
 * A git checkout ships no manifest and is skipped entirely: there, the files
 * being different from a release is the normal state of affairs.
 */
function release_mismatches(?string $manifest = null, ?string $root = null): array {
    $manifest ??= ROOT . '/MANIFEST';
    $root ??= ROOT;
    if (!is_file($manifest)) return [];
    $mismatched = [];
    foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        // The manifest is ours, but it is also just a file on disk in the same
        // upload; a line that is not the expected shape is itself a mismatch.
        if (!preg_match('#^([a-f0-9]{64})  ([a-zA-Z0-9_./-]+)$#D', $line, $m) || str_contains($m[2], '..')) {
            $mismatched[] = 'MANIFEST';
            continue;
        }
        $path = $root . '/' . $m[2];
        if (!is_file($path) || !hash_equals($m[1], hash_file('sha256', $path))) $mismatched[] = $m[2];
    }
    return $mismatched;
}

/** Writable means: the file if it is already there, otherwise the directory. */
function install_writable(string $path): bool {
    return is_file($path) ? is_writable($path) : is_writable(dirname($path));
}

/**
 * One row per requirement: what it is, whether it holds, and what to do if not.
 *
 * A row marked fatal stops the installation; the rest are reported and the
 * operator decides. Missing dependencies are deliberately not fatal — the portal
 * runs without them, it just cannot send email or draw a payment QR code yet.
 *
 * $server is the request asking, which decides the HTTPS row; without a web
 * request - a command-line run - there is no connection to judge.
 */
function install_requirements(?array $server = null): array {
    $server ??= $_SERVER;
    $checks = [];
    $add = function (string $de, string $en, bool $ok, string $fixDe = '', string $fixEn = '', bool $fatal = true) use (&$checks): void {
        $checks[] = ['label' => install_t($de, $en), 'ok' => $ok, 'fatal' => $fatal,
                     'fix' => $ok ? '' : install_t($fixDe, $fixEn)];
    };
    // First, because it is the one she fixes in a different place: not on this
    // server's PHP, but by switching on the certificate and opening https://.
    if (isset($server['REQUEST_METHOD']))
        $add('Verschlüsselte Verbindung (HTTPS)', 'Encrypted connection (HTTPS)',
             install_request_secure($server),
             'Diese Seite ist über http:// geöffnet, also unverschlüsselt: das Passwort der Datenbank und dein eigenes wären unterwegs lesbar, und das Portal liefe danach genauso. Im Hosting-Panel das SSL-Zertifikat für diese Domain einschalten (meist „Let’s Encrypt“, kostenlos) und die Einrichtung dann über https:// öffnen.',
             'This page was opened over http://, unencrypted: the database password and your own would be readable on the way, and the portal would run the same way afterwards. Switch on the SSL certificate for this domain in the hosting panel (usually “Let’s Encrypt”, free), then open setup over https://.');
    $add('PHP 8.2 oder neuer (installiert: ' . PHP_VERSION . ')',
         'PHP 8.2 or newer (installed: ' . PHP_VERSION . ')',
         PHP_VERSION_ID >= 80200,
         'Im Hosting-Panel unter „PHP-Version“ eine neuere Version auswählen.',
         'Choose a newer version under “PHP version” in the hosting panel.');
    foreach (['pdo_mysql' => ['Datenbankzugriff (pdo_mysql)', 'Database access (pdo_mysql)'],
              'mbstring'  => ['Umlaute und Zeichensätze (mbstring)', 'Character handling (mbstring)'],
              'openssl'   => ['Verschlüsselung (openssl)', 'Encryption (openssl)'],
              'session'   => ['Anmeldungen (session)', 'Sign-in sessions (session)']] as $extension => $label)
        $add($label[0], $label[1], extension_loaded($extension),
             'Die PHP-Erweiterung „' . $extension . '“ im Hosting-Panel aktivieren.',
             'Enable the “' . $extension . '” PHP extension in the hosting panel.');
    $add('Der Ordner config/ ist beschreibbar', 'The config/ folder is writable',
         install_writable(config_path()),
         'Im Dateimanager für den Ordner config die Rechte auf 755 setzen. Die Einstellungen lassen sich sonst unten von Hand anlegen.',
         'Set the config folder to 755 in the file manager. Otherwise the settings can be created by hand below.',
         false);
    // Where the portal will really keep its files: beside the maintenance flag
    // the configuration names, once there is one.
    $storage = install_storage_dir(install_read_config(config_path()));
    $add('Der Ordner storage/ ist beschreibbar', 'The storage/ folder is writable',
         is_dir($storage) && is_writable($storage),
         'Im Dateimanager für den Ordner storage die Rechte auf 755 setzen.',
         'Set the storage folder to 755 in the file manager.');
    $add('E-Mail- und QR-Bibliotheken sind vorhanden', 'The email and QR libraries are present',
         is_file(ROOT . '/vendor/autoload.php'),
         'Das vollständige Installationspaket verwenden. Ohne vendor/ kann das Portal keine E-Mails versenden.',
         'Use the complete distribution package. Without vendor/ the portal cannot send email.',
         false);
    return $checks;
}

/** Requirements that must hold before anything is written. */
function install_blockers(?array $server = null): array {
    return array_values(array_filter(install_requirements($server), fn($c) => !$c['ok'] && $c['fatal']));
}

// ---------------------------------------------------------------------------
// The database the operator typed in
// ---------------------------------------------------------------------------

function install_connect(array $db): PDO {
    return new PDO(
        'mysql:host=' . $db['host'] . ';port=' . (int)$db['port'] . ';dbname=' . $db['database'] . ';charset=utf8mb4',
        (string)$db['username'], (string)$db['password'],
        // A wrong host name must fail in seconds rather than hanging the page
        // until the web server gives up on it.
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

/** Try the credentials and report the result in words the operator can act on. */
function install_probe(array $db): array {
    try {
        $pdo = install_connect($db);
        return ['ok' => true, 'message' => '', 'server' => (string)$pdo->query('SELECT VERSION()')->fetchColumn()];
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => install_db_message($e), 'server' => ''];
    }
}

/**
 * Turn a MySQL connection error into an instruction.
 *
 * "SQLSTATE[HY000] [1045] Access denied" tells the operator nothing; "the
 * password is wrong" tells her exactly which field to look at.
 */
function install_db_message(PDOException $e): string {
    $code = (int)($e->errorInfo[1] ?? 0);
    // A connection that never opened has no errorInfo, only the driver code in
    // the message, so read it from there as well.
    if (!$code && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $code = (int)$m[1];
    return match ($code) {
        1045 => install_t('Benutzername oder Passwort der Datenbank stimmt nicht.',
                          'The database user name or password is not correct.'),
        // The server answers 1044 for both causes and deliberately does not say
        // which, so neither may the message: naming only one sends the operator
        // looking in the wrong half of the panel.
        1044, 1049 => install_t('Diese Datenbank gibt es nicht, oder dieser Benutzer ist ihr nicht zugeordnet. Beides wird im Hosting-Panel unter „MySQL-Verwaltung“ eingerichtet.',
                                'That database does not exist, or this user is not assigned to it. Both are set up under “MySQL Management” in the hosting panel.'),
        2002, 2003, 2005 => install_t('Der Datenbankserver ist unter diesem Namen nicht erreichbar. Bei den meisten Hostern lautet er „localhost“.',
                                      'The database server cannot be reached under that name. On most hosting it is “localhost”.'),
        default => install_t('Die Datenbank hat die Verbindung abgelehnt: ', 'The database refused the connection: ') . $e->getMessage(),
    };
}

/**
 * A note about the database server, or an empty string when it is a version
 * these migrations are known to run on.
 */
function install_server_note(string $server): string {
    if ($server === '') return '';
    if (stripos($server, 'mariadb') !== false)
        return version_compare($server, '10.4', '>=') ? ''
            : install_t('MariaDB 10.4 oder neuer wird empfohlen.', 'MariaDB 10.4 or newer is recommended.');
    return version_compare($server, '5.7', '>=') ? ''
        : install_t('MySQL 5.7 oder neuer wird benötigt.', 'MySQL 5.7 or newer is required.');
}

// ---------------------------------------------------------------------------
// Writing the configuration
// ---------------------------------------------------------------------------

/**
 * The keys a configuration file carries.
 *
 * Named once so the generated file and config/config.example.php cannot drift
 * apart; the structure suite compares both against this list.
 */
function install_config_keys(): array {
    return ['app_url', 'app_key', 'db', 'timezone', 'secure_cookies', 'session_idle_minutes', 'maintenance_file'];
}

function install_config_source(array $v): string {
    $q = static fn(mixed $x): string => var_export($x, true);
    return "<?php\ndeclare(strict_types=1);\n\n"
        . "// Written by the browser setup. Keep app_key exactly as it is: it decrypts the\n"
        . "// saved SMTP password and anything still waiting in the mail queue, and a backup\n"
        . "// restored without it cannot get those back.\n"
        . "return [\n"
        . "    'app_url' => " . $q($v['app_url']) . ", // No trailing slash. A subdirectory is supported.\n"
        . "    'app_key' => " . $q($v['app_key']) . ",\n"
        . "    'db' => [\n"
        . "        'host' => " . $q($v['db']['host']) . ",\n"
        . "        'port' => " . $q((int)$v['db']['port']) . ",\n"
        . "        'database' => " . $q($v['db']['database']) . ",\n"
        . "        'username' => " . $q($v['db']['username']) . ",\n"
        . "        'password' => " . $q($v['db']['password']) . ",\n"
        . "    ],\n"
        . "    'timezone' => " . $q($v['timezone']) . ",\n"
        . "    'secure_cookies' => " . $q((bool)$v['secure_cookies']) . ", // false ONLY for a local HTTP test.\n"
        . "    'session_idle_minutes' => " . $q((int)$v['session_idle_minutes']) . ",\n"
        . "    // For deployments with separate release folders, point to one shared file.\n"
        . "    'maintenance_file' => " . $q($v['maintenance_file']) . ",\n"
        . "];\n";
}

/**
 * Put the finished configuration in place in one step.
 *
 * A half-written config file takes the whole portal down with "Konfiguration
 * fehlt", so it is written beside its destination and moved over.
 */
function install_write_config(string $path, string $source): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true))
        throw new RuntimeException(install_t('Der Ordner config/ kann nicht angelegt werden.', 'Cannot create the config/ folder.'));
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $source) === false)
        throw new RuntimeException(install_t('Die Datei config/config.php kann nicht geschrieben werden.', 'Cannot write config/config.php.'));
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException(install_t('Die Datei config/config.php kann nicht ersetzt werden.', 'Cannot replace config/config.php.'));
    }
    // Without this the next request can still be served the previous file from
    // the opcode cache, which on a fresh install is no file at all.
    if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
}

/** The stored configuration, or null when there is none that parses. */
function install_read_config(string $path): ?array {
    if (!is_file($path)) return null;
    try { $config = include $path; } catch (Throwable) { return null; }
    return is_array($config) ? $config : null;
}

/**
 * The encryption key to write.
 *
 * Re-running setup must never invent a new one while a usable key exists: it is
 * what decrypts the SMTP password and the queued mail, and replacing it loses
 * both silently.
 */
function install_app_key(string $path): string {
    $stored = (string)(install_read_config($path)['app_key'] ?? '');
    return strlen((string)base64_decode($stored, true)) === 32 ? $stored : base64_encode(random_bytes(32));
}

function install_config_usable(?array $config): bool {
    return is_array($config)
        && strlen((string)base64_decode((string)($config['app_key'] ?? ''), true)) === 32
        && is_array($config['db'] ?? null)
        && (string)($config['db']['database'] ?? '') !== '';
}

/**
 * How far this installation has got.
 *
 *   fresh        no usable configuration; ask for everything
 *   configured   configuration written and the database answers, with no
 *                administrator in it yet; finish the last step
 *   installed    done, and setup must refuse to run again
 *   unreachable  a configuration whose database does not answer; refuse
 *                everything until it does
 *
 * A database that does not answer is not "configured". It once was, and on an
 * installed portal a moment's outage then turned the setup page back into an
 * offer to create an administrator, with the migrations run first and their
 * safeguards skipped. The one failure that does mean "not finished" is the
 * accounts table not being there yet: the configuration was written and the
 * schema was not.
 */
function install_state(?string $path = null): string {
    $config = install_read_config($path ?? config_path());
    if (!install_config_usable($config)) return 'fresh';
    try {
        $pdo = install_connect($config['db']);
        return (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE role='admin'")->fetchColumn() > 0
            ? 'installed' : 'configured';
    } catch (PDOException $e) {
        return $e->getCode() === '42S02' ? 'configured' : 'unreachable';
    } catch (Throwable) {
        return 'unreachable';
    }
}

// ---------------------------------------------------------------------------
// Proof that whoever finishes an installation can reach its files
// ---------------------------------------------------------------------------

/**
 * The folder the portal keeps its own files in, before the application is
 * loaded: beside the maintenance flag a configuration names, where
 * maintenance_file() puts everything else, and storage/ while there is none.
 */
function install_storage_dir(?array $config = null): string {
    $flag = is_array($config) ? (string)($config['maintenance_file'] ?? '') : '';
    return $flag !== '' ? dirname($flag) : ROOT . '/storage';
}

/** The cookie that hands the setup code to the browser that wrote the configuration. */
const INSTALL_CODE_COOKIE = 'badminton_setup';

/**
 * Where the setup code is written.
 *
 * In the storage folder rather than beside the configuration: config/ may be
 * read-only, in which case the operator creates config.php by hand, while a
 * storage/ that cannot be written stops the installation before this is asked.
 */
function install_code_file(?array $config): string { return install_storage_dir($config) . '/setup-code.txt'; }

/** The code in the file, or null when there is none. */
function install_code_read(string $file): ?string {
    return preg_match('/\b[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}\b/', (string)@file_get_contents($file), $m) ? $m[0] : null;
}

/**
 * The setup code, written into its file the first time it is asked for.
 *
 * Once a configuration exists, somebody wrote it, and finishing the
 * installation creates the administrator of whatever database it names. Only
 * the person who can open the portal's files should be able to do that; the
 * code is how she shows it. Twelve characters with no 0/O or 1/I to confuse,
 * so it can be read off a phone and typed, and far too many to guess.
 *
 * Null when the file cannot be written, which only happens when storage/ is not
 * writable - and that already stops the installation with its own instruction.
 */
function install_code(?array $config): ?string {
    $file = install_code_file($config);
    if (($code = install_code_read($file)) !== null) return $code;
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 12; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $code = implode('-', str_split($code, 4));
    // Created exclusively, so two people opening the page at once cannot each
    // be told about a different code; whoever is second reads the first one's.
    $handle = @fopen($file, 'x');
    if ($handle === false) return install_code_read($file);
    fwrite($handle, $code . "\n\n"
        . "Einrichtungscode für das Badminton-Portal. Auf der Einrichtungsseite eingeben.\n"
        . "Setup code for the badminton portal. Enter it on the setup page.\n"
        . "Die Datei wird nach der Einrichtung gelöscht. / This file is removed once setup has finished.\n");
    fclose($handle);
    // Readable by the owner, because the operator opens it in her file manager,
    // which on per-user PHP hosting is the same account; not by anybody else.
    @chmod($file, 0640);
    return $code;
}

/** Whether what was typed, or what the cookie carries, is the code in the file. */
function install_code_matches(?array $config, mixed $typed): bool {
    $code = install_code_read(install_code_file($config));
    if ($code === null || !is_string($typed)) return false;
    // However it was typed: lower case, with spaces, with or without the dashes.
    $typed = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $typed));
    return hash_equals(str_replace('-', '', $code), $typed);
}

/** Once there is an administrator the code opens nothing, so it goes. */
function install_code_forget(?array $config): void { @unlink(install_code_file($config)); }

/**
 * The file named the way the operator finds it in her file manager: from the
 * portal's own folder, or by name alone when it lies elsewhere, so the page
 * does not print a server path to whoever happens to open it.
 */
function install_code_location(?array $config): string {
    $file = install_code_file($config);
    $root = realpath(ROOT);
    $dir = realpath(dirname($file));
    return $root !== false && $dir !== false && str_starts_with($dir . '/', $root . '/')
        ? ltrim(substr($dir, strlen($root)) . '/' . basename($file), '/')
        : basename($file);
}

/**
 * What to do when a request arrives and there is no configuration.
 *
 * On the web that is a fresh upload, so it goes to the setup page rather than
 * showing a dead end. On the command line it says where the setup page is.
 */
function install_redirect(): never {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Not installed yet. Open setup.php in a browser to install, or copy\n"
            . "config/config.example.php to config/config.php and run: php bin/console.php migrate\n");
        exit(1);
    }
    header('Location: ' . install_setup_url($_SERVER), true, 303);
    header('Cache-Control: no-store');
    exit;
}
