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
// Where things are
// ---------------------------------------------------------------------------

function config_path(): string { return getenv('CRM_CONFIG') ?: ROOT . '/config/config.php'; }

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

// ---------------------------------------------------------------------------
// Does this server have what the application needs
// ---------------------------------------------------------------------------

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
 */
function install_requirements(): array {
    $checks = [];
    $add = function (string $de, string $en, bool $ok, string $fixDe = '', string $fixEn = '', bool $fatal = true) use (&$checks): void {
        $checks[] = ['label' => install_t($de, $en), 'ok' => $ok, 'fatal' => $fatal,
                     'fix' => $ok ? '' : install_t($fixDe, $fixEn)];
    };
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
    $add('Der Ordner storage/ ist beschreibbar', 'The storage/ folder is writable',
         is_dir(ROOT . '/storage') && is_writable(ROOT . '/storage'),
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
function install_blockers(): array {
    return array_values(array_filter(install_requirements(), fn($c) => !$c['ok'] && $c['fatal']));
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
 *   fresh       no usable configuration; ask for everything
 *   configured  configuration written but no administrator; finish the last step
 *   installed   done, and setup must refuse to run again
 *
 * A database that is merely unreachable reads as "configured", which is safe:
 * the only thing setup will then offer is creating the first administrator, and
 * create_admin_account() refuses that as soon as one exists.
 */
function install_state(): string {
    $config = install_read_config(config_path());
    if (!install_config_usable($config)) return 'fresh';
    try {
        $pdo = install_connect($config['db']);
        return (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE role='admin'")->fetchColumn() > 0
            ? 'installed' : 'configured';
    } catch (Throwable) {
        return 'configured';
    }
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
