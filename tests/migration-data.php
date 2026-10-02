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
 * Three pauses. Before 015, a portal as it stood with prices on the tariff and
 * addresses on the contacts. Before 019, a portal where one login holds several
 * brothers and sisters and a child's address has drifted from its login's. 019
 * is then applied three ways: straight through; stopped after each of its
 * statements in turn and started again from the first, which is what the next
 * page view does after an interrupted update, each on a fresh copy; and once
 * more after it has finished. Before 020, logins with news by email both off
 * and on, which 020 and 021 must leave as they are; 020 is stopped and started
 * again the same way, and 021 run twice. Before 022, logins whose names make
 * the same username, a staff login with a one-word name, a name with umlauts and
 * a hyphen, a login whose student is gone, one still at the invitation and one
 * whose address has a quoted local part, which 022 and 023 must keep as they were
 * but for a unique placeholder username; 023 is stopped and started again the
 * same way, and its UPDATE run twice. After 023, a second login on an address
 * that is already a login's, which the database must still refuse.
 *
 * Its own process and its own database, because the database the suite is using
 * has all the migrations applied already and this needs to stop half way. Prints
 * what it found as JSON; tests/suites/migrations.php does the asserting, so the
 * failures read like every other failure in the run.
 *
 *   php tests/migration-data.php <config>     on the database that config names
 *
 * tests/mariadb-local.sh makes that database and passes its config to the suite
 * as CRM_MIGRATION_CONFIG. It must be empty and its name must end in _test,
 * because every table in it is dropped again and again; empty is the one thing
 * that proves it is not a database somebody uses.
 */

require_once __DIR__ . '/harness.php';
// The statements are split with the application's own split_sql(), and
// connect() is how the database is reached, with the session set up the way the
// runner's is: UTC, real prepares. This process has no database to boot
// against, so the files are taken on their own.
require_once APP_ROOT . '/app/core.php';
// schema_guarded_tables(): what an update must not lose a row of, read from the
// runner rather than listed a second time here.
require_once APP_ROOT . '/app/schema.php';
// The runner's step after the files (database/defaults.php): the usernames and
// the sign-in comparison hash, with what those need to write and to read a
// setting.
require_once APP_ROOT . '/app/tx.php';
require_once APP_ROOT . '/app/defaults.php';
require_once APP_ROOT . '/app/auth.php';

$target = (string)($argv[1] ?? '');
if ($target === '' || !is_file($target)) {
    fwrite(STDERR, "Usage: php tests/migration-data.php <config of an empty *_test database>\n");
    exit(2);
}
$GLOBALS['config'] = require $target;
$name = (string)(config('db')['database'] ?? '');
// The harness's own rule: a name that merely ends in _test can still carry a
// second dbname into the connection string.
if (!test_database_name_allowed($name)) {
    fwrite(STDERR, "Refusing: the database in $target must have a name of letters, digits and underscores ending in _test.\n");
    exit(2);
}
$tables = (int)connect()->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
if ($tables > 0) {
    fwrite(STDERR, "Refusing: $name is not empty ($tables tables), and this drops every table in it. Give it an empty database.\n");
    exit(2);
}
// Empty when it started, so everything in it is this run's own - also when a
// migration fails half way, which would otherwise leave the next run refusing a
// database that is no longer empty.
register_shutdown_function(fn() => drop_every_table(connect()));

/** An empty database to build a portal in, and a connection of its own to it. */
function fresh_database(): PDO {
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

/** The statements of one migration file, split as the runner splits them. */
function migration_statements(string $path): array {
    return split_sql((string)file_get_contents($path));
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
    $rows = $pdo->query("SELECT non_unique AS non_unique, column_name AS name FROM information_schema.statistics"
        . " WHERE table_schema = DATABASE() AND table_name = 'students' AND index_name = 'student_one_account'"
        . ' ORDER BY seq_in_index')->fetchAll();
    return ['exists' => $rows !== [], 'unique' => $rows !== [] && (int)$rows[0]['non_unique'] === 0,
            'columns' => array_column($rows, 'name')];
}

/** A table's columns, in the order the engine keeps them. */
function table_columns(PDO $pdo, string $table): array {
    $columns = $pdo->prepare('SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE()'
        . ' AND table_name = ? ORDER BY ordinal_position');
    $columns->execute([$table]);
    return array_column($columns->fetchAll(), 'name');
}

/** Each login's news-by-email choice, and its status once 020 has given it one. */
function login_choices(PDO $pdo): array {
    $presence = in_array('presence', table_columns($pdo, 'accounts'), true) ? ', presence' : '';
    return $pdo->query('SELECT id, email, newsletter' . $presence . ' FROM accounts ORDER BY id')->fetchAll();
}

/** A login from before 020 that had chosen news by email. */
function add_subscribed_login(PDO $pdo): void {
    insert_row($pdo, 'accounts', ['name' => 'Familie Pichler', 'email' => 'pichler@beispiel.test', 'role' => 'student',
        'state' => 'active', 'verified_at' => '2025-09-01 08:00:00', 'newsletter' => 1, 'created_at' => '2025-09-01 08:00:00']);
}

/** A login written the way the portal writes one, naming neither column. */
function new_login(PDO $pdo, string $email): array {
    $id = insert_row($pdo, 'accounts', ['name' => 'Neu', 'email' => $email, 'role' => 'student', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $row = $pdo->query('SELECT newsletter, presence FROM accounts WHERE id = ' . $id)->fetch();
    $pdo->exec('DELETE FROM accounts WHERE id = ' . $id);
    return $row;
}

/** The indexes 020 gives online_periods, as the engine describes them. */
function online_period_indexes(PDO $pdo): array {
    $indexes = [];
    foreach ($pdo->query("SELECT index_name AS name, column_name AS col FROM information_schema.statistics"
        . " WHERE table_schema = DATABASE() AND table_name = 'online_periods' ORDER BY index_name, seq_in_index")->fetchAll() as $row)
        $indexes[$row['name']][] = $row['col'];
    return $indexes;
}

/**
 * Logins as the previous version wrote them, for 022 and 023 to give usernames to.
 *
 * The cases ADR 0019 names for the backfill that follows these migrations: two
 * people whose names make the same username, a staff login with a one-word name,
 * a name with umlauts and a hyphen, a student login whose student is gone, and a
 * login still at the invitation. Their addresses are lower-case ASCII, because
 * every address the portal stores has been through email_value().
 */
function add_logins_before_022(PDO $pdo): array {
    $earlier = '2025-10-01 08:00:00';
    $login = fn(string $name, string $email, string $role = 'student', string $state = 'active'): int => insert_row($pdo, 'accounts', [
        'name' => $name, 'email' => $email, 'role' => $role, 'state' => $state,
        'password_hash' => $state === 'invited' ? null : '$2y$10$abcdefghijklmnopqrstuuOLDHASHkeptbytheupdateXXXXXXXXXX',
        'verified_at' => $state === 'invited' ? null : $earlier, 'locale' => 'de', 'newsletter' => 0, 'created_at' => $earlier]);
    $child = fn(int $id, int $account, string $first, string $last, string $email): int => insert_row($pdo, 'students', [
        'id' => $id, 'account_id' => $account, 'first_name' => $first, 'last_name' => $last, 'email' => $email,
        'status' => 'active', 'joined_on' => '2025-10-01', 'revision' => 1, 'created_at' => $earlier, 'updated_at' => $earlier]);
    $mueller = $login('Familie Müller', 'mueller@beispiel.test');
    $child(90, $mueller, 'Lena', 'Müller', 'mueller@beispiel.test');
    $mueller2 = $login('Lena Mueller', 'lena.m@beispiel.test');
    $child(91, $mueller2, 'Lena', 'Mueller', 'lena.m@beispiel.test');
    $gross = $login('Familie Groß', 'gross@beispiel.test');
    $child(92, $gross, 'Hans-Jürgen', 'Groß-Özdemir', 'gross@beispiel.test');
    return ['mueller' => $mueller, 'mueller2' => $mueller2, 'gross' => $gross,
            'staff' => $login('Trainerin', 'trainerin@beispiel.test', 'trainer'),
            'orphan' => $login('Kurt Novák', 'kurt@beispiel.test'),
            'invited' => $login('Eva Hofer', 'eva@beispiel.test', 'student', 'invited'),
            // [R2] An address with a quoted local part. FILTER_VALIDATE_EMAIL, the
            // only check there was, let it through, so a portal may hold one; the
            // stricter write check of ADR 0019 would refuse it today. The update
            // must leave it exactly as it is, so the family keeps receiving mail.
            'legacy' => $login('Familie Alt', '"familie..alt"@beispiel.test')];
}

/**
 * A login at "", as 022 leaves every login and 023 leaves none.
 *
 * The runner's step looks for both "" and a '#' placeholder. Without a row at
 * each, a step that stopped looking for one of them would fail nothing. On an
 * address of its own, like every login.
 */
function add_login_never_named(PDO $pdo): int {
    return insert_row($pdo, 'accounts', ['name' => 'Familie Leer', 'email' => 'leer@beispiel.test', 'username' => '',
        'role' => 'student', 'state' => 'active', 'verified_at' => '2025-10-03 08:00:00', 'created_at' => '2025-10-03 08:00:00']);
}

/** Every login, every column, in id order: what 022 and 023 must not change but for username. */
function every_login(PDO $pdo): array {
    return $pdo->query('SELECT * FROM accounts ORDER BY id')->fetchAll();
}

/**
 * The unique indexes on accounts, each as the list of its columns, keyed by name.
 *
 * The name is kept as well as the columns, because it shows the address is
 * still guarded by the index 001 made, which the engine called email after its
 * column.
 */
function login_unique_indexes(PDO $pdo): array {
    $indexes = [];
    foreach ($pdo->query("SELECT index_name AS name, column_name AS col FROM information_schema.statistics"
        . " WHERE table_schema = DATABASE() AND table_name = 'accounts' AND non_unique = 0 AND index_name <> 'PRIMARY'"
        . ' ORDER BY index_name, seq_in_index')->fetchAll() as $row)
        $indexes[$row['name']][] = $row['col'];
    ksort($indexes);
    return $indexes;
}

/**
 * What the database says to a write, as its SQLSTATE, or null if it took it.
 *
 * Each write is undone again, so the next one meets the logins as 023 left them.
 */
function refusal(PDO $pdo, string $name, string $email, string $username): ?string {
    try {
        $id = insert_row($pdo, 'accounts', ['name' => $name, 'email' => $email, 'username' => $username,
            'role' => 'student', 'created_at' => gmdate('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        return (string)$e->getCode();
    }
    $pdo->exec('DELETE FROM accounts WHERE id = ' . $id);
    return null;
}

/**
 * 001 to 021 with the logins above, then 022.
 *
 * Also returns every login as it was before 022, which is what 022 and 023 are
 * held to afterwards.
 */
function build_portal_before_023(): array {
    [$pdo] = build_portal_before_019();
    apply_migrations($pdo, '019', '021');
    $logins = add_logins_before_022($pdo);
    $before = ['accounts' => every_login($pdo), 'counts' => portal_state($pdo)['counts'], 'unique' => login_unique_indexes($pdo)];
    apply_migrations($pdo, '022', '022');
    // A login that already has a username when 023 runs. An update cannot make
    // one - it applies 022 and 023 before any code writes a username - so it is
    // here only as the row an UPDATE that forgot its WHERE would overwrite:
    // "touched only the rows still at ''" is then checked against a row it must
    // leave alone, not only against rows it was meant to change.
    $logins['named'] = insert_row($pdo, 'accounts', ['name' => 'Schon Benannt', 'email' => 'benannt@beispiel.test',
        'username' => 'schon.benannt', 'role' => 'student', 'created_at' => '2025-10-02 08:00:00']);
    return [$pdo, $logins, $before];
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

// --- 020 and 021 on logins that already exist ---------------------------------
// Neither moves data, and that is exactly what is held to here: every login
// written by the previous version keeps its news-by-email choice when the
// default changes under it - one that had it off above all, since a stray UPDATE
// would sign that family up behind their back - and is given 'auto' rather than
// a NULL for its status. One with news on is added so that "unchanged" is not
// only ever checked against zeros.
add_subscribed_login($pdo);
$twentyBefore = ['accounts' => login_choices($pdo), 'counts' => portal_state($pdo)['counts']];
apply_migrations($pdo, '020', '999');
$twentyAfter = ['accounts' => login_choices($pdo), 'counts' => portal_state($pdo)['counts'],
                'online_periods' => table_columns($pdo, 'online_periods'),
                'online_period_rows' => (int)$pdo->query('SELECT COUNT(*) FROM online_periods')->fetchColumn(),
                'indexes' => online_period_indexes($pdo), 'new_login' => new_login($pdo, 'neu@beispiel.test')];

$fetch = fn(string $sql) => $pdo->query($sql)->fetchAll();
$result = [
    'rates' => $fetch('SELECT tariff_id, interval_months, price_cents FROM tariff_rates ORDER BY tariff_id, interval_months'),
    'templates' => $fetch('SELECT tariff_id, name, months, kind, value FROM tariff_discounts ORDER BY tariff_id'),
    'enrolments' => $fetch('SELECT student_id, tariff_id, interval_months, discount_months, discount_kind,'
        . ' discount_value, discount_note, price_cents FROM class_students ORDER BY student_id'),
    'students' => $fetch('SELECT id, first_name, account_id, email FROM students ORDER BY id'),
    'tariff_columns' => table_columns($pdo, 'tariffs'),
    'ids' => $ids,
    'engine' => (string)$pdo->query('SELECT VERSION()')->fetchColumn(),
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

// --- 020 interrupted after each statement, then started again from the first --
// And 021 run a second time, as a retry after it finished but before the ledger
// recorded it would.
$twenty = migration_path('020');
$twentyOne = migration_path('021');
$twentyStatements = migration_statements($twenty);
$result['twenty'] = ['statements' => count($twentyStatements), 'before' => $twentyBefore, 'after' => $twentyAfter, 'retried' => []];
for ($stopped = 1; $stopped < count($twentyStatements); $stopped++) {
    [$pdo] = build_portal_before_019();
    run_statements($pdo, $nineteen, $statements);
    add_subscribed_login($pdo);
    run_statements($pdo, $twenty, array_slice($twentyStatements, 0, $stopped));
    run_statements($pdo, $twenty, $twentyStatements);
    run_statements($pdo, $twentyOne, migration_statements($twentyOne));
    run_statements($pdo, $twentyOne, migration_statements($twentyOne));
    $result['twenty']['retried'][$stopped] = ['accounts' => login_choices($pdo),
        'online_periods' => table_columns($pdo, 'online_periods'), 'new_login' => new_login($pdo, 'neu@beispiel.test')];
}

// --- 022 and 023 on logins that already exist --------------------------------
// Every login the previous version wrote keeps every value it had, and gains a
// placeholder username that is unique. The rule that one address is one login
// stays, and the rule that one username is one login comes (ADR 0020 §1, §9).
// The usernames made from names are the runner's PHP step afterwards, below.
$twentyThree = migration_path('023');
$twentyThreeStatements = migration_statements($twentyThree);
[$pdo, $logins, $twentyTwoBefore] = build_portal_before_023();
$withUsername = ['accounts' => every_login($pdo), 'columns' => table_columns($pdo, 'accounts')];
run_statements($pdo, $twentyThree, $twentyThreeStatements);
$result['twentytwo'] = [
    'logins' => $logins, 'statements' => count($twentyThreeStatements),
    'before' => $twentyTwoBefore, 'after_022' => $withUsername,
    'after' => ['accounts' => every_login($pdo), 'counts' => portal_state($pdo)['counts'], 'unique' => login_unique_indexes($pdo)],
    // A second login on the address of a login the update carried through,
    // with a username nobody has: only the address can be what refuses it. As
    // typed, in other capitals, and on the legacy quoted address [R2].
    'same_address' => refusal($pdo, 'Zweiter Zugang', 'mueller@beispiel.test', 'zweiter.zugang'),
    'address_other_case' => refusal($pdo, 'Zweiter Zugang', 'Mueller@Beispiel.test', 'zweiter.zugang'),
    'legacy_address_taken' => refusal($pdo, 'Zweiter Zugang', '"familie..alt"@beispiel.test', 'zweiter.zugang'),
    // The negative: the same write on an address of its own is taken, so the
    // refusals above are the address's and not every write's.
    'own_address' => refusal($pdo, 'Zweiter Zugang', 'zweiter.zugang@beispiel.test', 'zweiter.zugang'),
    'same_username' => refusal($pdo, 'Doppelt', 'doppelt@beispiel.test', 'schon.benannt'),
    'other_case' => refusal($pdo, 'Doppelt', 'doppelt@beispiel.test', 'Schon.Benannt'),
    'placeholder_taken' => refusal($pdo, 'Doppelt', 'doppelt@beispiel.test', '#' . $logins['mueller']),
    'retried' => [],
];
// A second run of 023's UPDATE, as a retry after the file finished but before
// the ledger recorded it: every login is at a placeholder or a name by now.
run_statements($pdo, $twentyThree, array_slice($twentyThreeStatements, 0, -1));
$result['twentytwo']['again'] = every_login($pdo);

// --- the runner's step after 023, on the logins carried through ----------------
// The placeholders become usernames in database/defaults.php, which
// schema_apply() requires after the files on every update (ADR 0019 §4, kept by
// 0020), not in a migration. So it is run here, through the application's own
// functions on the application's own connections to this portal's database -
// the one configuration this process has - twice, the second time as the next
// update would. The portal is marked as set up, as every portal being updated
// is, so only the part that runs on every update runs. One login at '' is
// written in first, beside the placeholders 023 made.
$result['twentytwo']['runner'] = ['never_named' => add_login_never_named($pdo)];
$pdo->exec("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('defaults_initialized', 'true', '2025-10-03 08:00:00')");
$runnerState = function () use ($pdo): array {
    setting_cache_clear();
    return ['accounts' => every_login($pdo), 'counts' => portal_state($pdo)['counts'],
            'mail' => (int)$pdo->query('SELECT COUNT(*) FROM mail_jobs')->fetchColumn(),
            'dummy_hash' => (string)setting('sign_in_dummy_hash')];
};
$runnerStep = static function (): void { require APP_ROOT . '/database/defaults.php'; };
$result['twentytwo']['runner']['before'] = $runnerState();
$runnerStep();
$result['twentytwo']['runner']['first'] = $runnerState();
$runnerStep();
$result['twentytwo']['runner']['second'] = $runnerState();

// --- 023 interrupted after each statement, then started again from the first --
for ($stopped = 1; $stopped < count($twentyThreeStatements); $stopped++) {
    [$pdo] = build_portal_before_023();
    run_statements($pdo, $twentyThree, array_slice($twentyThreeStatements, 0, $stopped));
    run_statements($pdo, $twentyThree, $twentyThreeStatements);
    $result['twentytwo']['retried'][$stopped] = ['accounts' => every_login($pdo), 'unique' => login_unique_indexes($pdo)];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
