<?php
declare(strict_types=1);

/**
 * Decides whether tests/existing-database.sh may run the suite against a
 * database it did not create, and writes the configuration for that run.
 *
 *   php tests/existing-database.php setup <saved> <portal-config> <work>
 *       first run: the details come from CRM_DB_* in the environment, and are
 *       saved to <saved> only once every check has passed
 *   php tests/existing-database.php reuse <saved> <portal-config> <work>
 *       every later run: the details come from <saved>
 *
 * Every rule about which database may be used is in this file, so a first run
 * and a later one are held to the same ones. Prints the server version when the
 * database may be used; exits 2 with the reason when it may not.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/run-config.php';
require __DIR__.'/database-name.php';

function refuse(string $why): never { fwrite(STDERR, $why."\n"); exit(2); }

[, $mode, $saved, $portal, $work] = $argv + ['', '', '', '', ''];
if (!in_array($mode, ['setup', 'reuse'], true) || $saved === '' || $work === '')
    refuse('Usage: php tests/existing-database.php setup|reuse <saved> <portal-config> <work>');

if ($mode === 'setup') {
    // An answer left empty takes the default shown in the prompt.
    $port = (string)getenv('CRM_DB_PORT');
    if ($port === '') $port = '3306';
    if (!ctype_digit($port)) refuse("Refusing: the port must be a number, not \"$port\".");
    $host = (string)getenv('CRM_DB_HOST');
    $db = ['host' => $host === '' ? 'localhost' : $host, 'port' => (int)$port,
           'database' => (string)getenv('CRM_DB_NAME'), 'username' => (string)getenv('CRM_DB_USER'),
           'password' => (string)getenv('CRM_DB_PASSWORD')];
} else {
    $db = require $saved;
    if (!is_array($db)) refuse("Refusing: $saved does not hold database details. Delete it and run again.");
}
$name = (string)($db['database'] ?? '');

// The harness's own rule, which existing-database.sh repeats in bash so that a
// wrong name is refused before the password is typed. The server name is held
// to the same standard: nothing in it may change the connection string.
if (!test_database_name_allowed($name))
    refuse("Refusing: \"$name\" is not a name ending in _test (letters, digits and underscores only).");
if (!preg_match('/^[A-Za-z0-9.-]+$/D', (string)($db['host'] ?? '')))
    refuse('Refusing: "'.($db['host'] ?? '').'" is not a server name.');
if (!is_int($db['port'] ?? null) || $db['port'] < 1 || $db['port'] > 65535)
    refuse('Refusing: the port must be a number from 1 to 65535.');

if (is_file($portal)) {
    if (strcasecmp((string)((require $portal)['db']['database'] ?? ''), $name) === 0)
        refuse("Refusing: $name is the database the portal itself uses ($portal).");
} else {
    // Not a refusal: a checkout that is not a portal has nothing to compare
    // with. The checks on the name and on what the database holds still apply.
    fwrite(STDERR, "Note: there is no portal configuration at $portal, so $name could not be compared\n"
        ."with the portal's own database. The _test name, the empty-database check on the first run and\n"
        ."the check for real email addresses still apply.\n");
}

try {
    $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname=$name;charset=utf8mb4",
        (string)($db['username'] ?? ''), (string)($db['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) { refuse("Could not connect to $name: ".$e->getMessage()); }

$tables = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
// Empty is the one proof that nobody uses it. From the second run on the tables
// are the suite's own, left by the run before, so this is a first-run check.
if ($mode === 'setup' && $tables > 0)
    refuse("Refusing: $name is not empty ($tables ".($tables === 1 ? 'table' : 'tables')."), and the suite deletes every table in the database it uses.\n"
          ."Create a new, empty database for the tests.");
// Checked on every run, because the empty check is not: every address the suite
// creates is in the reserved .test domain, so any other one is a real person,
// and the database is a copy of a portal rather than the suite's own.
$accounts = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'accounts'")->fetchColumn();
$real = $accounts ? (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE LOWER(email) NOT LIKE '%.test'")->fetchColumn() : 0;
if ($real > 0)
    refuse("Refusing: $name holds $real ".($real === 1 ? 'account' : 'accounts')." with a real email address, so it is a copy of a portal, not the\n"
          ."suite's own. The suite deletes everything in it. Use a new, empty database for the tests.");

// A warning rather than a refusal: many hosts give one user every database on
// the account, and the checks above already keep the suite on this one.
$others = array_values(array_diff(array_column($pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_NUM), 0), ['information_schema', $name]));
if ($others)
    fwrite(STDERR, "Warning: the user {$db['username']} can also reach ".implode(', ', $others).".\n"
        ."If one of those is the portal's database, a mistake in the tests could reach it too.\n"
        ."A database user that can reach only $name is safer; the hosting panel can create one.\n");

// Both files hold the password or lead to it, so only this user may read them.
umask(077);
if ($mode === 'setup') {
    if (file_put_contents($saved, "<?php\n// The database tests/existing-database.sh runs the suite against. It was\n"
        ."// empty when it was set up. Delete this file to use a different one.\nreturn ".var_export($db, true).";\n") === false)
        refuse("Could not write $saved.");
    chmod($saved, 0600);
}
try { write_run_config("$work/config.php", $saved, $work); }
catch (RuntimeException $e) { refuse($e->getMessage()); }
echo $pdo->query('SELECT VERSION()')->fetchColumn();
