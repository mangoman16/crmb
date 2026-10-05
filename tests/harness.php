<?php
declare(strict_types=1);

/**
 * Test harness.
 *
 * Builds a disposable database from the real migration files and boots the real
 * application code against it, so a test exercises what ships rather than a
 * re-implementation - on the engine the portal runs on, MariaDB or MySQL, so
 * what passes here is the SQL that ships as well as the PHP.
 *
 * CRM_CONFIG names the configuration of a database whose name ends in _test.
 * The two scripts write one and run the suite with it:
 *
 *   tests/mariadb-local.sh                 a throwaway server, then stops it
 *   tests/existing-database.sh             an empty *_test database from the hosting panel
 *   CRM_CONFIG=<config> php tests/run.php  a *_test database you already have
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__.'/database-name.php';
require_once __DIR__.'/run-config.php';
require_once __DIR__.'/css.php';

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

/**
 * What this run could not check, for the report footer.
 *
 * A suite that has to reach outside the run's own database - another process,
 * a second database, node - and cannot, says so here rather than passing as
 * though it had checked.
 */
function test_unsupported(?array $set=null): array {
    static $held = [];
    if ($set !== null) $held = $set;
    return $held;
}


/**
 * A path with every part that exists resolved, and the rest appended as written.
 *
 * realpath() answers false for a folder nobody has created yet, and most of the
 * storage folders are created on first use - so "is this inside the portal" has
 * to be answerable before they exist, not only afterwards.
 */
function test_resolved_path(string $path): string {
    $rest = [];
    while (($real = realpath($path)) === false) {
        $parent = dirname($path);
        if ($parent === $path) return $path;
        $rest[] = basename($path);
        $path = $parent;
    }
    return rtrim($real, '/') . ($rest ? '/' . implode('/', array_reverse($rest)) : '');
}

/** Whether a path lies inside a folder, both resolved first. */
function test_path_inside(string $path, string $folder): bool {
    return str_starts_with(test_resolved_path($path) . '/', rtrim(test_resolved_path($folder), '/') . '/');
}

/** The system temp directory, written the way dirname() will give it back. */
function test_temp_base(): string { return rtrim(sys_get_temp_dir(), '/') ?: '/'; }

/**
 * A folder of this run's own, for everything the application writes to disk.
 *
 * The owner runs the suite on her hosting, possibly inside the very folder the
 * live portal is served from. Uploads, backups, invoice proofs, the maintenance
 * flag and the schema marker are all placed beside the maintenance file, so
 * wherever that points is where a test run deletes "orphaned" uploads and
 * "stale" backups. Pointing it here - a fresh folder under the system temp
 * directory, readable by this user alone and removed when the run ends - is
 * what keeps a run from touching hers, and from touching another run's.
 */
function test_run_dir(): string {
    static $dir;
    if ($dir !== null) return $dir;
    $base = test_temp_base();
    if (test_path_inside($base, APP_ROOT)) {
        fwrite(STDERR, "Refusing to run: the temporary directory ($base) is inside the portal's own folder.\n"
            ."Set TMPDIR to a folder outside it.\n");
        exit(2);
    }
    $candidate = $base . '/crm-test-' . getmypid() . '-' . bin2hex(random_bytes(6));
    if (!@mkdir($candidate, 0700)) {
        fwrite(STDERR, "Cannot create a folder for this run under $base.\n");
        exit(2);
    }
    $dir = $candidate;
    register_shutdown_function('test_remove_run_dir', $dir);
    return $dir;
}

/**
 * Remove the run's folder and everything in it, and nothing else.
 *
 * Refuses any path that is not one test_run_dir() made, so a bug here can never
 * become a recursive delete somewhere that matters. Links are removed, never
 * followed.
 */
function test_remove_run_dir(string $dir): void {
    if (!preg_match('~/crm-test-\d+-[0-9a-f]{12}$~D', $dir) || dirname($dir) !== test_temp_base() || !is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                           RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item)
        ($item->isDir() && !$item->isLink()) ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($dir);
}

/**
 * Boot the application against a fresh database and apply every migration.
 *
 * Called once per run; each suite then resets the data with test_reset().
 */
function test_boot(): void {
    // Before anything reads a configuration: the application falls back to
    // config/config.php when CRM_CONFIG is empty, and run from the folder the
    // portal is served from, that is hers.
    if ((string)getenv('CRM_CONFIG') === '') {
        fwrite(STDERR, "Refusing to run: no test database is set up. Run tests/mariadb-local.sh, which starts a throwaway MariaDB, or tests/existing-database.sh to use an empty *_test database.\n");
        exit(2);
    }

    $_SESSION = ['locale' => 'de'];
    require APP_ROOT.'/app/bootstrap.php';

    // Whatever the configuration said. A config copied from the live one still
    // names the live storage/, and the suites delete uploads no test record
    // points at and prune backups there - which, against her folder, is every
    // photograph and every copy she has. config() reads the global on every
    // call, so this reaches every path derived from it.
    $GLOBALS['config']['maintenance_file'] = test_run_dir().'/maintenance.flag';

    // Both refusals come before the first statement: below them is the step
    // that drops every table in the database the configuration names.
    if (!test_database_name_allowed((string)(config('db')['database'] ?? ''))) {
        fwrite(STDERR, "Refusing to run: the configured database name is not letters, digits and underscores ending in _test.\n");
        exit(2);
    }
    // A _test suffix on the portal's own database is unlikely, but it is her
    // families' data on the other side of "unlikely", so it is checked rather
    // than assumed. The same rule tests/existing-database.sh applies before it
    // gets this far.
    $live = APP_ROOT.'/config/config.php';
    if (is_file($live)) {
        $liveConfig = (static fn() => require $live)();
        if (test_resolved_path((string)getenv('CRM_CONFIG')) === test_resolved_path($live)
            || strcasecmp((string)($liveConfig['db']['database'] ?? ''), (string)config('db')['database']) === 0) {
            fwrite(STDERR, "Refusing to run: that is the database config/config.php gives the portal itself.\n");
            exit(2);
        }
    }

    /* Dropping in a hand-kept order means getting the foreign keys right by
       hand, and the engine refuses to drop a parent while a child still points
       at it (error 1451). The list is therefore read from the database, and the
       constraints are switched off for the duration: on a fresh database the
       drops are all no-ops and any order looks correct, so this only shows up
       on the second run. */
    db()->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (test_tables() as $table) db()->exec('DROP TABLE IF EXISTS `'.sql_name($table, 'table').'`');
    } finally {
        db()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    foreach (glob(APP_ROOT.'/database/migrations/*.sql') as $file)
        foreach (split_sql((string)file_get_contents($file)) as $statement) db()->exec($statement);
}

/**
 * Every table in the test database.
 *
 * Read from the database rather than kept as a list here, because a list here
 * silently stops matching the schema the first time a migration adds a table.
 */
function test_tables(): array {
    return array_column(rows('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()'), 'name');
}

/**
 * Empty every data table, keeping the schema, then re-seed the defaults.
 *
 * The list of tables is read from the database rather than kept here. A
 * hand-kept list stops matching the schema the first time a migration adds a
 * table, and it does so silently: rows from one suite survive into the next, and
 * the failure turns up somewhere unrelated as a count that is one too high.
 */
function test_reset(): void {
    // Emptying a parent before its children is a foreign-key violation, and the
    // list comes from the database in no particular order, so the constraints
    // are off while it is emptied.
    db()->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (test_tables() as $table) {
            // schema_migrations is the record of what this database is, not data
            // a suite put there.
            if ($table === 'schema_migrations') continue;
            db()->exec('DELETE FROM ' . sql_name($table, 'table'));
        }
    } finally {
        db()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    // A running portal always has the migrations ledger: schema_apply() creates
    // it before anything else, and pages read it (presence_recorded_since()).
    // The harness applies the migration files directly, and the install suite
    // drops the ledger on purpose, so it is put back - empty - for every suite.
    run('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)');
    // The counter connection is separate by design, so clear it through itself.
    run_counter('DELETE FROM rate_limits');
    setting_cache_clear();
    $_SESSION = ['locale' => 'de'];
    require APP_ROOT . '/database/defaults.php';
    setting_cache_clear();
    // Request-scoped memos outlive a request here, because a test run is one
    // process. Emptying them keeps every suite measuring a cold page, the way
    // a real first request would be - and lets each suite capture its own
    // error, since a request captures only one.
    payment_cache_clear();
    setup_cache_clear();
    error_capture_reset();
}

function test_has_table(string $name): bool {
    try { return (bool)scalar('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$name]); }
    catch (Throwable) { return false; }
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
        // newsletter is left to the schema's default (on since 021, ADR 0018),
        // so a fixture starts a login the way the portal does.
        'auth_version' => 1, 'notifications' => 1, 'payment_notices' => 1,
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

/**
 * A tariff and its rates.
 *
 * 'price_cents' is a convenience for the common case of one rate at the tariff's
 * normal interval; 'rates' takes an interval => cents map for a tariff that
 * offers a choice. The price lives in tariff_rates, never on the tariff itself,
 * so a suite that sets both cannot describe a tariff the application could not.
 */
function make_tariff(array $over=[]): int {
    static $n = 0; $n++;
    $rates = $over['rates'] ?? null;
    $price = (int)($over['price_cents'] ?? 4500);
    unset($over['rates'], $over['price_cents']);
    $id = fixture('tariffs', array_merge([
        'name' => 'Tarif '.$n, 'description' => '', 'class_id' => null,
        'period' => 'recurring', 'interval_months' => 1,
        'due_day' => 1, 'grace_days' => 7, 'first_period' => 'prorate',
        'due_days' => 14, 'sort_order' => 0, 'archived' => 0, 'is_demo' => 0,
    ], $over));
    $interval = (int)($over['interval_months'] ?? 1);
    foreach ($rates ?? [$interval => $price] as $months => $cents)
        fixture('tariff_rates', ['tariff_id' => $id, 'interval_months' => (int)$months, 'price_cents' => (int)$cents]);
    return $id;
}

/**
 * A course, and by default the one meeting day most tests assume.
 *
 * 'days' is lifted out before the row is written: a course's pattern lives in
 * class_days now, and a fixture that still passed weekday would fail in a way
 * that says "no such column" rather than "this test is out of date".
 */
function make_class(array $over=[]): int {
    static $n = 0; $n++;
    $days = $over['days'] ?? [['weekday'=>1, 'starts_at'=>'16:00:00', 'ends_at'=>'17:30:00']];
    unset($over['days']);
    $id = fixture('classes', array_merge([
        'name' => 'Kurs '.$n, 'description' => '', 'location' => '',
        'capacity' => 0, 'sort_order' => 0, 'archived' => 0, 'created_at' => now(), 'is_demo' => 0,
    ], $over));
    foreach ($days as $order => $day)
        fixture('class_days', array_merge(['class_id'=>$id, 'weekday'=>1, 'starts_at'=>null,
                                           'ends_at'=>null, 'location'=>'', 'sort_order'=>$order*10], $day));
    return $id;
}

/**
 * A conversation, with its participants.
 *
 * Who is in a thread decides who may read it, so a fixture that wrote the thread
 * and not its participants would be a conversation nobody can open - including
 * the person it belongs to.
 */
function make_thread(array $accountIds, array $over=[]): int {
    $id = fixture('threads', array_merge([
        'account_id' => $accountIds[0] ?? null, 'kind' => 'staff',
        'subject' => 'Unterhaltung', 'updated_at' => now(),
    ], $over));
    foreach ($accountIds as $accountId)
        fixture('thread_participants', ['thread_id'=>$id, 'account_id'=>$accountId, 'joined_at'=>now()]);
    return $id;
}

/** A student in a course, on a tariff. Returns the course id for chaining. */
function make_enrolment(int $classId, int $studentId, array $over=[]): int {
    fixture('class_students', array_merge([
        'class_id' => $classId, 'student_id' => $studentId, 'joined_on' => '2025-01-01',
        'left_on' => null, 'tariff_id' => null, 'price_cents' => null, 'price_note' => '', 'due_day' => 0,
    ], $over));
    return $classId;
}

/**
 * Mail as a portal on its first evening has it (false), or as a set-up one does
 * (true): a saved server, a connection test that passed, and a released notice.
 * The one fixture for it, because account_mail_ready() asks all three, and a
 * suite that set two of them by hand would be testing a portal that refuses.
 */
function mail_ready(bool $on): void {
    set_setting('smtp', $on ? ['host'=>'mail.example.test','port'=>587,'from_email'=>'portal@example.test','from_name'=>'B'] : []);
    set_setting('smtp_last_test', $on ? ['ok'=>true, 'summary'=>'', 'transcript'=>'', 'sent_to'=>'', 'at'=>now()] : []);
    set_setting('privacy_ready', $on);
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
 * Counted by the server, from this session's Com_stmt_prepare, rather than by
 * instrumenting the application, so the measurement cannot drift from what
 * actually runs. connect() turns emulated prepares off, so every run(), rows(),
 * one() and scalar() is one prepare on the server; exec(), which is how
 * transactional() sets its savepoints, is none. The rate-limit counter has a
 * connection of its own and is not in the count.
 *
 * Reading the counter is a prepared statement itself. What one reading adds is
 * measured once, from two in a row, rather than assumed - the performance suite
 * checks the result against statements it can count by hand.
 */
function query_count(callable $fn): int {
    static $reading;
    $read = static fn(): int => (int)db()->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];
    if ($reading === null) { $first = $read(); $reading = $read() - $first; }
    $before = $read();
    $fn();
    return $read() - $before - $reading;
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
    // A warning from a view is printed into the page - between two table cells,
    // where nobody reads it - so unless it is turned into a failure here, an
    // undefined index or a division by zero renders green for ever.
    set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
        throw new RuntimeException($message . ' @ ' . basename($file) . ':' . $line);
    });
    try {
        require $file;
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        while (ob_get_level() > $level) ob_end_clean();
        throw $e;
    } finally {
        restore_error_handler();
        $_GET = $restore;
        if ($restorePage === null) unset($GLOBALS['page']); else $GLOBALS['page'] = $restorePage;
    }
}

/**
 * A signed-in page whole: the view inside views/layout.php, as public/index.php
 * draws it - the bell, the account menu, and the bar that says whose eyes you
 * are looking through. render_view() is the view alone. A warning in the frame
 * fails the check, as one in the view does.
 */
function render_page(string $page, array $query = []): string {
    $content = render_view($page, $query);
    $public = false; $user = current_user();
    $restore = $_GET; $restorePage = $GLOBALS['page'] ?? null;
    $_GET = $query; $GLOBALS['page'] = $page;
    $level = ob_get_level();
    ob_start();
    set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
        throw new RuntimeException($message . ' @ ' . basename($file) . ':' . $line);
    });
    try {
        require APP_ROOT . '/views/layout.php';
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        while (ob_get_level() > $level) ob_end_clean();
        throw $e;
    } finally {
        restore_error_handler();
        $_GET = $restore;
        if ($restorePage === null) unset($GLOBALS['page']); else $GLOBALS['page'] = $restorePage;
    }
}

/**
 * The fields one time box posts, written as the time a person would say.
 *
 * The form posts an hour and a minute separately, because <input type="time">
 * renders in the language of the device rather than of the page. A suite should
 * still read as "16:00", so it says that and this splits it.
 *
 *   act('x', ['a'=>1] + time_post('starts_at', '16:00'));
 *   act('y', time_post('day_starts_at', ['16:00', '18:00', '']));
 */
function time_post(string $name, string|array $value): array {
    if (is_array($value)) {
        $hours = []; $minutes = [];
        foreach ($value as $one) { [$h, $m] = time_parts((string)$one); $hours[] = $h; $minutes[] = $m; }
        return [$name.'_h' => $hours, $name.'_m' => $minutes];
    }
    [$hour, $minute] = time_parts($value);
    return [$name.'_h' => $hour, $name.'_m' => $minute];
}

/**
 * The discount one family was given on one enrolment.
 *
 * It lives on the enrolment rather than on the tariff, because a discount on
 * the tariff is a property of the price list: giving one child three months at
 * half price used to mean inventing a tariff nobody else could be put on.
 */
function give_discount(int $classId, int $studentId, int $months, string $kind, int $value, string $note=''): void {
    run('UPDATE class_students SET discount_months=?, discount_kind=?, discount_value=?, discount_note=?'
        .' WHERE class_id=? AND student_id=?', [$months, $kind, $value, $note, $classId, $studentId]);
}

/** Which of its tariff's intervals this enrolment is billed on; 0 = the usual. */
function bill_every(int $classId, int $studentId, int $months): void {
    run('UPDATE class_students SET interval_months=? WHERE class_id=? AND student_id=?', [$months, $classId, $studentId]);
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
 *
 * The transaction is not left out. Every action that reaches dispatch_action()
 * in the running portal is already inside one, and a locking read outside a
 * transaction locks nothing - so a suite that dispatched bare would be proving
 * the handlers work in a situation they are never in.
 */
function act(string $action, array $fields = []): array {
    test_load_actions();
    $_POST = $fields;
    return without_session_id_warning(fn() => transactional(fn() => dispatch_action($action)));
}

/**
 * Run one action the way the browser does: through handle_post(), so the CSRF
 * check, the rate limits and the duplicate-submission claim all apply.
 *
 * act() leaves those out on purpose. A defect that lives in the seam between
 * the request's guards and the action itself - a throttle counted before the
 * password is known and never cleared afterwards - is invisible to anything
 * that exercises only one side of it.
 */
function submit(string $action, array $fields = []): array {
    test_load_actions();
    $_POST = $fields + ['action' => $action, 'csrf' => csrf(), 'request_id' => bin2hex(random_bytes(32))];
    return without_session_id_warning(fn() => handle_post());
}

/**
 * Signing in and out regenerate the session id, which PHP cannot do in a
 * command-line run that has already printed a line. That is a property of the
 * runner, not of the code under test, so only that one warning is swallowed and
 * everything else still reports.
 */
function without_session_id_warning(callable $fn): mixed {
    $previous = set_error_handler(static function (int $no, string $message) use (&$previous) {
        if (str_contains($message, 'session_regenerate_id')) return true;
        return $previous ? $previous(...func_get_args()) : false;
    });
    try { return $fn(); }
    finally { restore_error_handler(); }
}
