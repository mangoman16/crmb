<?php
declare(strict_types=1);

/**
 * Test harness.
 *
 * Builds a disposable database from the real migration files and boots the real
 * application code against it, so a test exercises what ships rather than a
 * re-implementation.
 *
 * Two drivers:
 *   sqlite  (default) no server needed, so the suite runs anywhere. The
 *           migrations are translated on the way in - see sqlite_translate().
 *           This proves the PHP logic, not the MySQL dialect.
 *   mysql   set CRM_TEST_DRIVER=mysql and point CRM_CONFIG at a config whose
 *           database name ends in _test. This is the one that proves the SQL.
 *
 * Usage:  php tests/run.php            all suites, sqlite
 *         php tests/run.php billing    one suite
 *         CRM_TEST_DRIVER=mysql php tests/run.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__.'/sqlite-driver.php';

const TEST_ROOT = __DIR__;
const APP_ROOT  = __DIR__ . '/..';

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

final class TestFailure extends RuntimeException {}

/** Counters and the name of the check being run, for the report. */
function test_state(): object {
    static $s;
    return $s ??= new class { public int $passed=0; public int $failed=0; public array $failures=[];
                              public string $suite=''; public string $case=''; };
}

function ok(bool $condition, string $what): void {
    $s = test_state();
    if ($condition) { $s->passed++; return; }
    $s->failed++;
    $s->failures[] = $s->suite.' / '.$s->case.': '.$what;
}

function is_same(mixed $expected, mixed $actual, string $what): void {
    if ($expected === $actual) { ok(true, $what); return; }
    ok(false, $what.' — expected '.test_show($expected).', got '.test_show($actual));
}

function is_equal(mixed $expected, mixed $actual, string $what): void {
    if ($expected == $actual) { ok(true, $what); return; }
    ok(false, $what.' — expected '.test_show($expected).', got '.test_show($actual));
}

/** Assert the callable throws, optionally that the message contains a fragment. */
function throws(callable $fn, string $what, ?string $contains=null): void {
    try { $fn(); }
    catch (Throwable $e) {
        if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
            ok(false, $what.' — threw, but message lacked '.test_show($contains).': '.$e->getMessage());
            return;
        }
        ok(true, $what);
        return;
    }
    ok(false, $what.' — expected a throw, nothing was thrown');
}

function does_not_throw(callable $fn, string $what): void {
    try { $fn(); ok(true, $what); }
    catch (Throwable $e) { ok(false, $what.' — threw '.get_class($e).': '.$e->getMessage()); }
}

function test_show(mixed $v): string {
    if (is_string($v)) return "'".$v."'";
    if (is_bool($v)) return $v ? 'true' : 'false';
    if ($v === null) return 'null';
    if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return (string)$v;
}

/** Group the assertions that follow under a readable name. */
function case_(string $name): void { test_state()->case = $name; }

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

function test_driver(): string { return getenv('CRM_TEST_DRIVER') ?: 'sqlite'; }

/**
 * Translate the MySQL migrations into something SQLite accepts.
 *
 * Deliberately narrow: it handles only the constructs these migrations use, and
 * anything it cannot express is reported rather than skipped silently, so the
 * suite can never quietly stop covering a table.
 */
function sqlite_translate(string $sql): array {
    $sql = preg_replace('/ENGINE=InnoDB[^;]*/', '', $sql) ?? $sql;
    $sql = str_replace(
        ['BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'LONGTEXT', 'DATETIME', 'TINYINT', 'DECIMAL(6,2)', 'TIME'],
        ['INTEGER PRIMARY KEY AUTOINCREMENT', 'TEXT', 'TEXT', 'INTEGER', 'REAL', 'TEXT'],
        $sql);
    $out = ['statements' => [], 'unsupported' => []];
    foreach (split_sql($sql) as $statement) {
        $statement = preg_replace('/\s+AFTER\s+`?\w+`?/', '', $statement) ?? $statement;
        $statement = preg_replace('/,\s*INDEX\s+\w+\s*\([^)]*\)/', '', $statement) ?? $statement;
        $statement = preg_replace('/,\s*UNIQUE KEY\s+\w+\s*\(([^)]*)\)/', ', UNIQUE ($1)', $statement) ?? $statement;
        // SQLite cannot add a foreign key to an existing table. The column and
        // its index are created; only the constraint is missing, which is
        // recorded so a reader knows what this driver does not cover.
        if (preg_match('/^ALTER TABLE\s+(\w+)\s+ADD CONSTRAINT/i', $statement, $m)) {
            $out['unsupported'][] = 'foreign key on '.$m[1];
            continue;
        }
        $out['statements'][] = $statement;
    }
    return $out;
}

/** Statements the sqlite driver could not represent, for the report footer. */
function test_unsupported(?array $set=null): array {
    static $held = [];
    if ($set !== null) $held = $set;
    return $held;
}


/**
 * Boot the application against a fresh database and apply every migration.
 *
 * Called once per run; each suite then resets the data with test_reset().
 */
function test_boot(): void {
    $driver = test_driver();
    if ($driver === 'sqlite') {
        $file = sys_get_temp_dir().'/crm-test-'.getmypid().'.sqlite';
        @unlink($file);
        putenv('CRM_TEST_SQLITE='.$file);
        // A config the application will accept, pointing at nothing real: the
        // sqlite driver replaces connect() below.
        $config = TEST_ROOT.'/.config.generated.php';
        file_put_contents($config, '<?php return '.var_export([
            'app_url' => 'http://localhost',
            'app_key' => base64_encode(str_repeat('k', 32)),
            'db' => ['host'=>'127.0.0.1','port'=>3306,'database'=>'unused_test','username'=>'u','password'=>''],
            'timezone' => 'Europe/Vienna',
            'secure_cookies' => false,
            'session_idle_minutes' => 120,
            'maintenance_file' => sys_get_temp_dir().'/crm-test-maintenance-'.getmypid().'.flag',
        ], true).';');
        putenv('CRM_CONFIG='.$config);
    } elseif (!getenv('CRM_CONFIG')) {
        fwrite(STDERR, "CRM_TEST_DRIVER=mysql needs CRM_CONFIG pointing at a *_test database config.\n");
        exit(2);
    }

    if ($driver === 'sqlite') {
        // Production opens one connection per connect() call, and the rate-limit
        // counter deliberately gets its own so its writes survive the rollback of
        // the action they guard. The harness has to do the same or that property
        // is untestable. WAL plus a busy timeout lets the two coexist on one file.
        $GLOBALS['crm_connect_override'] = function (): PDO {
            $pdo = new TestSqlitePdo('sqlite:'.getenv('CRM_TEST_SQLITE'), null, null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            // Functions the application's SQL uses that SQLite lacks.
            $pdo->sqliteCreateFunction('CONCAT', fn(...$a) => implode('', $a), -1);
            $pdo->sqliteCreateFunction('GREATEST', fn(...$a) => max($a), -1);
            $pdo->sqliteCreateFunction('LEAST', fn(...$a) => min($a), -1);
            // Advisory locks are a MySQL concept; a single-connection test always
            // "holds" the lock, which is the behaviour the code expects.
            $pdo->sqliteCreateFunction('GET_LOCK', fn($n, $t) => 1, 2);
            $pdo->sqliteCreateFunction('RELEASE_LOCK', fn($n) => 1, 1);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA busy_timeout=4000');
            $pdo->exec('PRAGMA foreign_keys=ON');
            return $pdo;
        };
    }

    $_SESSION = ['locale' => 'de'];
    require APP_ROOT.'/app/bootstrap.php';

    if ($driver === 'mysql' && !str_ends_with(config('db')['database'], '_test')) {
        fwrite(STDERR, "Refusing to run: the configured database name does not end in _test.\n");
        exit(2);
    }

    $sql = '';
    foreach (glob(APP_ROOT.'/database/migrations/*.sql') as $file) $sql .= file_get_contents($file)."\n";

    if ($driver === 'sqlite') {
        $t = sqlite_translate($sql);
        test_unsupported($t['unsupported']);
        db()->exec('PRAGMA foreign_keys = ON');
        foreach ($t['statements'] as $statement) {
            try { db()->exec($statement); }
            catch (Throwable $e) {
                fwrite(STDERR, "Migration statement failed:\n  ".substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 140)."\n  ".$e->getMessage()."\n");
                exit(2);
            }
        }
    } else {
        /* Dropping in a hand-kept order means getting the foreign keys right by
           hand, and MySQL refuses to drop a parent while a child still points at
           it (error 1451). The list is therefore read from the database, and the
           constraints are switched off for the duration: on a fresh database the
           drops are all no-ops and any order looks correct, so this only shows up
           on the second run. */
        db()->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (test_tables() as $table) db()->exec('DROP TABLE IF EXISTS `'.sql_name($table, 'table').'`');
        } finally {
            db()->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        foreach (split_sql($sql) as $statement) db()->exec($statement);
    }
}

/**
 * Every table in the test database.
 *
 * Read from the database rather than kept as a list here, because a list here
 * silently stops matching the schema the first time a migration adds a table.
 */
function test_tables(): array {
    if (test_driver() === 'sqlite')
        return array_column(rows("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"), 'name');
    return array_column(rows('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()'), 'name');
}

/** Empty every data table, keeping the schema, then re-seed the defaults. */
function test_reset(): void {
    $tables = ['attendance','assessments','skills','skill_areas','rating_scales','class_students','classes',
               'payment_profiles','consent_log','audit_log','form_requests','thread_reads','messages','threads',
               'mail_jobs','saved_filters','message_templates','news','payments','charges','absences',
               'field_values','field_definitions','contacts','students','tariffs','rate_limits','auth_tokens',
               'accounts','settings'];
    if (test_has_table('record_versions')) array_unshift($tables, 'record_versions');
    // Emptying parents before children is a foreign-key violation on MySQL just
    // as it is on SQLite, so both engines get the constraints switched off here
    // rather than only the one the suite usually runs on.
    $sqlite = test_driver() === 'sqlite';
    db()->exec($sqlite ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) db()->exec('DELETE FROM '.sql_name($table, 'table'));
        if ($sqlite) db()->exec('DELETE FROM sqlite_sequence');
    } finally {
        db()->exec($sqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS=1');
    }
    // The counter connection is separate by design, so clear it through itself.
    run_counter('DELETE FROM rate_limits');
    setting_cache_clear();
    $_SESSION = ['locale' => 'de'];
    require APP_ROOT.'/database/defaults.php';
    setting_cache_clear();
    // Request-scoped memos outlive a request here, because a test run is one
    // process. Emptying them keeps every suite measuring a cold page, the way
    // a real first request would be.
    payment_cache_clear();
}

function test_has_table(string $name): bool {
    try {
        if (test_driver() === 'sqlite') return (bool)scalar("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", [$name]);
        return (bool)scalar('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$name]);
    } catch (Throwable) { return false; }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** Insert a row from an associative array and return its id. */
function fixture(string $table, array $row): int {
    run('INSERT INTO '.$table.' ('.implode(',', array_keys($row)).') VALUES ('
        .implode(',', array_fill(0, count($row), '?')).')', array_values($row));
    return (int)db()->lastInsertId();
}

function make_account(array $over=[]): int {
    static $n = 0; $n++;
    return fixture('accounts', array_merge([
        'name' => 'Account '.$n, 'email' => 'a'.$n.'@example.test',
        'password_hash' => password_hash('Test-Only-Password-2026', PASSWORD_DEFAULT),
        'role' => 'student', 'state' => 'active', 'verified_at' => now(),
        'locale' => 'de', 'theme' => 'auto', 'text_scale' => 'normal',
        'auth_version' => 1, 'newsletter' => 0, 'notifications' => 1, 'payment_notices' => 1,
        'created_at' => now(),
    ], $over));
}

function make_student(array $over=[]): int {
    static $n = 0; $n++;
    return fixture('students', array_merge([
        'first_name' => 'Kind'.$n, 'last_name' => 'Test', 'status' => 'active',
        'joined_on' => '2025-01-01', 'price_cents' => 4500, 'price_note' => '',
        'billing_paused' => 0, 'billing_note' => '', 'internal_notes' => '',
        'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
    ], $over));
}

function make_tariff(array $over=[]): int {
    static $n = 0; $n++;
    return fixture('tariffs', array_merge([
        'name' => 'Tarif '.$n, 'price_cents' => 4500, 'period' => 'monthly',
        'due_days' => 14, 'archived' => 0,
    ], $over));
}

function make_class(array $over=[]): int {
    static $n = 0; $n++;
    return fixture('classes', array_merge([
        'name' => 'Kurs '.$n, 'description' => '', 'weekday' => 1,
        'starts_at' => '16:00:00', 'ends_at' => '17:30:00', 'location' => '',
        'capacity' => 0, 'sort_order' => 0, 'archived' => 0, 'created_at' => now(),
    ], $over));
}

/** Pretend a given account is signed in, for code that calls current_user(). */
function sign_in_as(int $accountId): array {
    $a = one('SELECT * FROM accounts WHERE id=?', [$accountId]);
    if (!$a) throw new RuntimeException('No such account: '.$accountId);
    $_SESSION['user_id'] = $accountId;
    $_SESSION['auth_version'] = (int)$a['auth_version'];
    $_SESSION['last_seen'] = time();
    current_user(true);
    return $a;
}

function sign_out(): void {
    unset($_SESSION['user_id'], $_SESSION['auth_version'], $_SESSION['last_seen']);
    current_user(true);
}

/**
 * How many statements $fn causes the application to prepare.
 *
 * Counted by the driver rather than by instrumenting the application, so the
 * measurement cannot drift from what actually runs.
 */
function query_count(callable $fn): int {
    $pdo = db();
    if (!$pdo instanceof TestSqlitePdo) { $fn(); return 0; }
    $before = $pdo->statementsPrepared();
    $fn();
    return $pdo->statementsPrepared() - $before;
}

/**
 * Render a real view and return the HTML it produced.
 *
 * A check that renders the actual page catches what a test re-implementing the
 * page's logic would miss, because the re-implementation drifts. The view's
 * dependencies are loaded the way public/index.php loads them, and the same
 * variables are in scope: $page, $public and $user.
 */
function render_view(string $page, array $query = []): string {
    test_load_actions();
    $file = APP_ROOT . '/views/' . $page . '.php';
    if (!is_file($file)) throw new RuntimeException('No such view: ' . $page);

    $public = in_array($page, ['login', 'forgot', 'activate', 'unsubscribe', 'privacy', 'not_found'], true);
    $user = current_user();
    if (!$public && !$user) throw new RuntimeException('View ' . $page . ' needs a signed-in account.');

    // The query string and the current page belong to this render only; anything
    // checked afterwards should see what it set up, not the leftovers of a page.
    // public/index.php holds $page in a global, and start_form() reads it from
    // there, so the harness has to publish it the same way.
    $restore = $_GET;
    $restorePage = $GLOBALS['page'] ?? null;
    $_GET = $query;
    $GLOBALS['page'] = $page;
    $level = ob_get_level();
    ob_start();
    try {
        require $file;
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        while (ob_get_level() > $level) ob_end_clean();
        throw $e;
    } finally {
        $_GET = $restore;
        if ($restorePage === null) unset($GLOBALS['page']); else $GLOBALS['page'] = $restorePage;
    }
}

/** Populate $_POST for an action, including the fields handle_post() requires. */
function post_data(array $fields): void {
    $_POST = $fields;
}

/** Load the action and interface units the way public/index.php loads them. */
function test_load_actions(): void {
    static $loaded = false;
    if ($loaded) return;
    foreach (['actions', 'actions_settings', 'actions_messages', 'actions_config', 'ui'] as $unit)
        require_once APP_ROOT . '/app/' . $unit . '.php';
    $loaded = true;
}

/**
 * Run one action the way a form submission would, and return where it goes next.
 *
 * The real dispatcher, so a suite exercises what ships rather than a description
 * of it. The CSRF token, the throttles and the duplicate-submission claim belong
 * to handle_post() and are left out on purpose: they are the request's business,
 * not the action's, and they have their own checks in the security suite.
 */
function act(string $action, array $fields = []): array {
    test_load_actions();
    $_POST = $fields;
    return dispatch_action($action);
}
