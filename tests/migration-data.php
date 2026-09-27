#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Apply the migrations with a portal's data written in at the points where
 * migrations move it.
 *
 * The suite builds its database by applying every migration to an empty one, so
 * the statements that carry data across have never run against a single row:
 * a tariff's price into tariff_rates, its discount onto every enrolment that
 * was getting it, an address onto every child, a login holding three children
 * keeping only one of them. "Nobody's next invoice changes" was a claim with
 * nothing behind it.
 *
 * Two pauses. Before 015, a portal as it stood with prices on the tariff and
 * addresses on the contacts. Before 019, a portal where one login holds several
 * brothers and sisters and a child's address has drifted from its login's. 019
 * is then applied three ways: straight through; stopped after each of its
 * statements in turn and started again from the first, which is what the next
 * page view does after an interrupted update, each on a fresh copy; and once
 * more after it has finished.
 *
 * Its own process and its own database, because the database the suite is using
 * has all the migrations applied already and this needs to stop half way. Prints
 * what it found as JSON; tests/suites/migrations.php does the asserting, so the
 * failures read like every other failure in the run.
 *
 *   php tests/migration-data.php [<sqlite-file>]     on a SQLite file, rebuilt for each run
 *   php tests/migration-data.php --mysql=<config>    on the MySQL or MariaDB database
 *                                                    that config names
 *
 * The second is the one that proves the dialect. The database must be empty and
 * its name must end in _test, because every table in it is dropped again and
 * again; empty is the one thing that proves it is not a database somebody uses.
 */

require_once __DIR__ . '/harness.php';
// sqlite_translate() splits statements with the application's own split_sql(),
// and connect() is how the MySQL target is reached. This process has no
// database to boot against, so the files are taken on their own.
require_once APP_ROOT . '/app/core.php';
// schema_guarded_tables(): what an update must not lose a row of, read from the
// runner rather than listed a second time here.
require_once APP_ROOT . '/app/schema.php';

$mysql = null;
$sqliteFile = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--mysql=')) $mysql = substr($arg, 8);
    else $sqliteFile ??= $arg;
}
$sqliteFile ??= sys_get_temp_dir() . '/crm-migration-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';

if ($mysql !== null) {
    $GLOBALS['config'] = is_file($mysql) ? require $mysql : [];
    $name = (string)(config('db')['database'] ?? '');
    if (!str_ends_with($name, '_test')) {
        fwrite(STDERR, "Refusing: the database in $mysql must have a name ending in _test.\n");
        exit(2);
    }
    $tables = (int)connect()->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    if ($tables > 0) {
        fwrite(STDERR, "Refusing: $name is not empty ($tables tables), and this drops every table in it. Give it an empty database.\n");
        exit(2);
    }
    // Empty when it started, so everything in it is this run's own - also when
    // a migration fails half way, which would otherwise leave the next run
    // refusing a database that is no longer empty.
    register_shutdown_function(fn() => drop_every_table(connect()));
}

/**
 * An empty database to build a portal in, and the connection to it.
 *
 * MySQL through the application's own connect(), so the session is set up the
 * way the runner's is: UTC, real prepares.
 */
function fresh_database(): PDO {
    global $mysql, $sqliteFile;
    if ($mysql === null) {
        @unlink($sqliteFile);
        $pdo = new TestSqlitePdo('sqlite:' . $sqliteFile, null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=OFF');   // the halves are applied out of order for nobody
        return $pdo;
    }
    $pdo = connect();
    drop_every_table($pdo);
    return $pdo;
}

function drop_every_table(PDO $pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll() as $t)
        $pdo->exec('DROP TABLE `' . sql_name($t['name'], 'table') . '`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

/** The statements of one migration file, in the dialect of the target. */
function migration_statements(string $path): array {
    global $mysql;
    $sql = (string)file_get_contents($path);
    return $mysql === null ? sqlite_translate($sql)['statements'] : split_sql($sql);
}

/** Run statements, stopping the whole process with the file named if one fails. */
function run_statements(PDO $pdo, string $path, array $statements): void {
    foreach ($statements as $statement) {
        try { $pdo->exec($statement); }
        catch (Throwable $e) {
            fwrite(STDERR, basename($path) . ': ' . $e->getMessage() . "\n  "
                . substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 160) . "\n");
            exit(2);
        }
    }
}

/** Apply the migration files whose number falls in [$from, $to]. */
function apply_migrations(PDO $pdo, string $from, string $to): void {
    foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $number = substr(basename($path), 0, 3);
        if ($number < $from || $number > $to) continue;
        run_statements($pdo, $path, migration_statements($path));
    }
}

function migration_path(string $number): string {
    $found = glob(APP_ROOT . '/database/migrations/' . $number . '_*.sql') ?: [];
    if (count($found) !== 1) { fwrite(STDERR, "No single migration numbered $number.\n"); exit(2); }
    return $found[0];
}

function insert_row(PDO $pdo, string $table, array $row): int {
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES ('
        . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return (int)($row['id'] ?? $pdo->lastInsertId());
}

/**
 * Migrations 001 to 018 with a portal's data written in at both pauses.
 *
 * Returns the connection and the ids a reader needs to find each case again.
 */
function build_portal_before_019(): array {
    $pdo = fresh_database();
    $insert = fn(string $table, array $row): int => insert_row($pdo, $table, $row);
    $now = gmdate('Y-m-d H:i:s');

    apply_migrations($pdo, '001', '014');

    // --- a portal as it stood before 015, with the cases that matter --------
    $account = $insert('accounts', ['name' => 'Familie Hofer', 'email' => 'familie@beispiel.test',
        'role' => 'student', 'state' => 'active', 'verified_at' => $now, 'created_at' => $now]);
    $class = $insert('classes', ['name' => 'Kindertraining', 'description' => '', 'location' => 'Halle Nord',
        'capacity' => 0, 'sort_order' => 0, 'archived' => 0, 'created_at' => $now]);

    // A tariff that was giving a welcome discount, and one that was not.
    $giving = $insert('tariffs', ['class_id' => $class, 'name' => 'Monatsbeitrag', 'description' => '',
        'price_cents' => 4500, 'period' => 'recurring', 'interval_months' => 1, 'due_day' => 1,
        'grace_days' => 7, 'first_period' => 'prorate', 'discount_months' => 3, 'discount_kind' => 'percent',
        'discount_value' => 30, 'due_days' => 14, 'sort_order' => 0, 'archived' => 0]);
    $plain = $insert('tariffs', ['class_id' => $class, 'name' => 'Halbjahr', 'description' => '',
        'price_cents' => 24000, 'period' => 'recurring', 'interval_months' => 6, 'due_day' => 1,
        'grace_days' => 7, 'first_period' => 'prorate', 'discount_months' => 0, 'discount_kind' => 'percent',
        'discount_value' => 0, 'due_days' => 14, 'sort_order' => 10, 'archived' => 0]);

    // Three children: one on an account, one with only a contact to write to, one
    // with neither - which is the row that must come out empty rather than wrong.
    $onAccount = $insert('students', ['account_id' => $account, 'first_name' => 'Lena', 'last_name' => 'Hofer',
        'status' => 'active', 'joined_on' => '2026-01-01', 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $byContact = $insert('students', ['account_id' => null, 'first_name' => 'Tobias', 'last_name' => 'Berger',
        'status' => 'active', 'joined_on' => '2026-01-01', 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $neither = $insert('students', ['account_id' => null, 'first_name' => 'Niemand', 'last_name' => 'Nirgends',
        'status' => 'active', 'joined_on' => '2026-01-01', 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);

    // The one on an account also has a contact, with a different address on it. The
    // account is what they sign in with, so the account has to win.
    $insert('contacts', ['student_id' => $onAccount, 'owner_name' => 'Oma Hofer', 'relation_label' => 'Großmutter',
        'phone' => '+43 660 1', 'email' => 'oma@beispiel.test', 'is_primary' => 1]);
    // And this one has two contacts, only the second of which is the standard one.
    $insert('contacts', ['student_id' => $byContact, 'owner_name' => 'Opa Berger', 'relation_label' => 'Großvater',
        'phone' => '+43 660 2', 'email' => 'opa@beispiel.test', 'is_primary' => 0]);
    $insert('contacts', ['student_id' => $byContact, 'owner_name' => 'Maria Berger', 'relation_label' => 'Mutter',
        'phone' => '+43 660 3', 'email' => 'maria@beispiel.test', 'is_primary' => 1]);
    $insert('contacts', ['student_id' => $neither, 'owner_name' => 'Ohne Mail', 'relation_label' => 'Vater',
        'phone' => '+43 660 4', 'email' => '', 'is_primary' => 1]);

    $insert('class_students', ['class_id' => $class, 'student_id' => $onAccount, 'joined_on' => '2026-01-01',
        'tariff_id' => $giving, 'price_cents' => null, 'price_note' => '', 'due_day' => 0]);
    $insert('class_students', ['class_id' => $class, 'student_id' => $byContact, 'joined_on' => '2026-01-01',
        'tariff_id' => $plain, 'price_cents' => null, 'price_note' => '', 'due_day' => 0]);
    // An enrolment on no tariff at all: nothing to inherit, and it must not be given
    // somebody else's discount by an UPDATE that forgot its WHERE.
    $insert('class_students', ['class_id' => $class, 'student_id' => $neither, 'joined_on' => '2026-01-01',
        'tariff_id' => null, 'price_cents' => 3000, 'price_note' => 'Sonderpreis', 'due_day' => 0]);

    apply_migrations($pdo, '015', '018');

    // --- a portal as it stood before 019 ------------------------------------
    // Written on a fixed day long before the update, so a row the migration
    // touches is told apart from one it left alone by its updated_at as well as
    // its revision, and every build writes the same rows.
    $earlier = '2025-09-01 08:00:00';
    $login = fn(string $name, string $email): int => $insert('accounts', ['name' => $name, 'email' => $email,
        'role' => 'student', 'state' => 'active', 'verified_at' => $earlier, 'created_at' => $earlier]);
    $child = fn(int $id, ?int $account, string $first, string $last, string $email): int => $insert('students', [
        'id' => $id, 'account_id' => $account, 'first_name' => $first, 'last_name' => $last, 'email' => $email,
        'status' => 'active', 'joined_on' => '2025-09-01', 'revision' => 3, 'created_at' => $earlier, 'updated_at' => $earlier]);

    // Three on one login, and the lowest id is not the one written first - so
    // "keeps the login" has to mean the lowest id, not whichever row comes back
    // first. The keeper's address differs from the login's only in its capitals,
    // which the tables' collation calls equal and a family's mail client does not.
    $gruber = $login('Familie Gruber', 'gruber@beispiel.test');
    $mia  = $child(40, $gruber, 'Mia', 'Gruber', 'gruber@beispiel.test');
    $paul = $child(20, $gruber, 'Paul', 'Gruber', 'Gruber@Beispiel.test');
    // A name longer than the change log's label, which a strict server refuses
    // rather than cuts.
    $emma = $child(30, $gruber, str_repeat('Emma-Charlotte ', 6) . 'Theresia', 'von Gruber-Hohenwarth-' . str_repeat('Liechtenstein-', 4) . 'Österreich', 'gruber@beispiel.test');
    // A line the portal itself wrote about Mia, by a staff account deleted since:
    // no actor, like the migration's own. It must not be taken for one.
    $insert('record_versions', ['entity' => 'students', 'entity_id' => $mia, 'operation' => 'update', 'label' => 'Mia Gruber',
        'before_json' => json_encode(['account_id' => null, 'revision' => 1, 'updated_at' => $earlier]),
        'after_json' => json_encode(['account_id' => $gruber, 'revision' => 2, 'updated_at' => $earlier]),
        'actor_id' => null, 'created_at' => $earlier]);
    // Mia is taken off the login and keeps her course, her charge and the
    // payment against it.
    $insert('class_students', ['class_id' => $class, 'student_id' => $mia, 'joined_on' => '2025-09-01',
        'tariff_id' => $giving, 'price_cents' => null, 'price_note' => '', 'due_day' => 0]);
    $charge = $insert('charges', ['student_id' => $mia, 'class_id' => $class, 'label' => 'Monatsbeitrag September',
        'amount_cents' => 4500, 'period_from' => '2025-09-01', 'period_to' => '2025-09-30', 'due_on' => '2025-09-14',
        'created_at' => $earlier]);
    $insert('payments', ['charge_id' => $charge, 'amount_cents' => 4500, 'paid_on' => '2025-09-10',
        'method' => 'Überweisung', 'confirmed_at' => $earlier]);

    // Two on one login, both with an address of their own that has drifted. The
    // one taken off keeps hers, for when she has a login of her own.
    $huber = $login('Familie Huber', 'huber@beispiel.test');
    $jonas = $child(50, $huber, 'Jonas', 'Huber', 'jonas.alt@beispiel.test');
    $lisa  = $child(51, $huber, 'Lisa', 'Huber', 'lisa.huber@beispiel.test');

    // One child on one login, the address already the same: nothing to do.
    $novak = $login('Familie Novak', 'novak@beispiel.test');
    $jakob = $child(60, $novak, 'Jakob', 'Novák', 'novak@beispiel.test');

    // One child on one login, the address one the family has since moved away from.
    $weiss = $login('Familie Weiß', 'weiss@beispiel.test');
    $sara  = $child(70, $weiss, 'Sára', 'Weiß', 'sära.alt@beispiel.test');

    // No login at all, with an address that happens to be somebody's login. The
    // copy is for children on a login, and must not reach this one.
    $ida = $child(80, null, 'Ida', 'Novak', 'novak@beispiel.test');

    return [$pdo, [
        'giving' => $giving, 'plain' => $plain,
        'on_account' => $onAccount, 'by_contact' => $byContact, 'neither' => $neither,
        'gruber' => $gruber, 'mia' => $mia, 'paul' => $paul, 'emma' => $emma,
        'huber' => $huber, 'jonas' => $jonas, 'lisa' => $lisa,
        'novak' => $novak, 'jakob' => $jakob, 'weiss' => $weiss, 'sara' => $sara, 'ida' => $ida,
        'hofer' => $account,
    ]];
}

/** What 019 is about, read back: every child, every change-log line, every count. */
function portal_state(PDO $pdo): array {
    $counts = [];
    foreach (schema_guarded_tables() as $table) {
        try { $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
        catch (PDOException) { /* not created by these migrations */ }
    }
    return [
        'students' => $pdo->query('SELECT id, first_name, last_name, account_id, email, revision, updated_at FROM students ORDER BY id')->fetchAll(),
        'versions' => $pdo->query('SELECT entity, entity_id, operation, label, before_json, after_json, actor_id, created_at'
            . ' FROM record_versions ORDER BY id')->fetchAll(),
        'counts' => $counts,
    ];
}

/** The index 019 ends with, as the engine describes it. */
function one_account_index(PDO $pdo): array {
    global $mysql;
    if ($mysql !== null) {
        $rows = $pdo->query("SELECT non_unique AS non_unique, column_name AS name FROM information_schema.statistics"
            . " WHERE table_schema = DATABASE() AND table_name = 'students' AND index_name = 'student_one_account'"
            . ' ORDER BY seq_in_index')->fetchAll();
        return ['exists' => $rows !== [], 'unique' => $rows !== [] && (int)$rows[0]['non_unique'] === 0,
                'columns' => array_column($rows, 'name')];
    }
    foreach ($pdo->query('PRAGMA index_list(students)')->fetchAll() as $index) {
        if ($index['name'] !== 'student_one_account') continue;
        return ['exists' => true, 'unique' => (int)$index['unique'] === 1,
                'columns' => array_column($pdo->query('PRAGMA index_info(student_one_account)')->fetchAll(), 'name')];
    }
    return ['exists' => false, 'unique' => false, 'columns' => []];
}

function table_columns(PDO $pdo, string $table): array {
    global $mysql;
    return $mysql === null
        ? array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name')
        : array_column($pdo->query("SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE()"
            . " AND table_name = '" . $table . "' ORDER BY ordinal_position")->fetchAll(), 'name');
}

$nineteen = migration_path('019');
$statements = migration_statements($nineteen);

// --- 019 straight through, then everything after it --------------------------
[$pdo, $ids] = build_portal_before_019();
$before = portal_state($pdo);
run_statements($pdo, $nineteen, $statements);
$after = portal_state($pdo);
$index = one_account_index($pdo);
// A second run of everything but the index, which is the one statement that
// says so when it runs twice. Nothing may change.
run_statements($pdo, $nineteen, array_slice($statements, 0, -1));
$again = portal_state($pdo);
apply_migrations($pdo, '020', '999');

$fetch = fn(string $sql) => $pdo->query($sql)->fetchAll();
$result = [
    'rates' => $fetch('SELECT tariff_id, interval_months, price_cents FROM tariff_rates ORDER BY tariff_id, interval_months'),
    'templates' => $fetch('SELECT tariff_id, name, months, kind, value FROM tariff_discounts ORDER BY tariff_id'),
    'enrolments' => $fetch('SELECT student_id, tariff_id, interval_months, discount_months, discount_kind,'
        . ' discount_value, discount_note, price_cents FROM class_students ORDER BY student_id'),
    'students' => $fetch('SELECT id, first_name, account_id, email FROM students ORDER BY id'),
    'tariff_columns' => table_columns($pdo, 'tariffs'),
    'ids' => $ids,
    'engine' => $mysql === null ? 'sqlite' : (string)$pdo->query('SELECT VERSION()')->fetchColumn(),
    'nineteen' => ['statements' => count($statements), 'before' => $before, 'after' => $after,
                   'index' => $index, 'again' => $again, 'retried' => []],
];

// --- 019 interrupted after each statement, then started again from the first --
for ($stopped = 1; $stopped < count($statements); $stopped++) {
    [$pdo] = build_portal_before_019();
    run_statements($pdo, $nineteen, array_slice($statements, 0, $stopped));
    run_statements($pdo, $nineteen, $statements);
    $result['nineteen']['retried'][$stopped] = portal_state($pdo) + ['index' => one_account_index($pdo)];
}

if ($mysql === null) @unlink($sqliteFile);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
