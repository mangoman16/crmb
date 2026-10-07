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
 * Eight pauses. Before 015, a portal as it stood with prices on the tariff and
 * addresses on the contacts. Before 019, a portal where one login holds several
 * brothers and sisters and a child's address has drifted from its login's. 019
 * is then applied three ways: straight through; stopped after each of its
 * statements in turn and started again from the first, which is what the next
 * page view does after an interrupted update, each on a fresh copy; and once
 * more after it has finished. Before 020, logins with news by email both off
 * and on, which 020 and 021 must leave as they are; 020 is stopped and started
 * again the same way, and 021 run twice. Before 022, logins as the version
 * before usernames wrote them - a staff login, a name with umlauts and a hyphen,
 * a login whose student is gone, one still at the invitation and one whose
 * address has a quoted local part - which 022, 023 and 024 together must bring
 * through with every value they had; 023 is stopped and started again the same
 * way, and its UPDATE run twice. Before 024, the same portal with the usernames
 * the previous version gave it and logins that version wrote itself, which 024
 * must keep with every value but the username; afterwards two logins made
 * without one must both be taken, a second on an address that is already a
 * login's must still be refused, and the runner's step after the files must
 * change no login. Before 025, on that same portal, the chats the previous
 * version wrote - a trainer's chat with a student, one between two students and
 * a desk thread the trainer answered - which 025 must sort into the right kind
 * and owner and lose nothing of; 025 is stopped and started again the same way,
 * and its UPDATE run twice. Before 028, on that portal with 025 to 027 applied,
 * the people ADR 0023 §4 is about - students without a login, two brothers on one
 * parent's address, an example student, a student whose invitation is not yet
 * taken up, a login whose student is gone - and enrolments on terms of their own,
 * one of them a past membership. 028 to 031 are applied one at a time, each run
 * a second time straight after; 029 is also stopped after each statement and
 * started again on a new connection, and run on a key with another name. Then
 * the runner's step, stopped part way after each placeholder but the last, then
 * through, then again; and what the database refuses afterwards. Before 032, on
 * a portal 028 to 031 were applied to, custom fields with values, saved views,
 * templates and the change-log lines that name them, which 032 and 033 must drop
 * and touch nothing else; each file is run twice, and stopped after its first
 * statement and started again. Then the update this release brings, through the
 * application's own runner on a release of this run's own, and the mistakes ADR
 * 0027 is about, one file at a time, one page view after another: a file that
 * drops a guarded table, refused on every page view; the stamp and the settings
 * forged as a pass would write them; a newer upload on top; the copy imported
 * with the new files still in place, then broken off at contacts, then whole; a
 * release that takes the table off the list; a file that empties the table, one
 * that loses a row and stops, one that stops every time; and the record of the
 * unfinished update unwritable, then unreadable.
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
// The runner's step after the files (database/defaults.php): the sign-in
// comparison hash among it, with what that needs to write and to read a
// setting.
require_once APP_ROOT . '/app/tx.php';
require_once APP_ROOT . '/app/defaults.php';
require_once APP_ROOT . '/app/auth.php';
// ... and course_groups_fill(), which gives courses from before 025 their group.
require_once APP_ROOT . '/app/messaging.php';

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

/**
 * Put a copy back as INSTALL.md has it done: every table dropped, then the copy
 * run statement by statement. With $until, the import breaks off at the first
 * statement of that table, as one that stopped partway would. Not phpMyAdmin;
 * TESTING.md walks the same with phpMyAdmin.
 */
function import_copy(PDO $pdo, string $path, ?string $until = null): void {
    drop_every_table($pdo);
    foreach (split_sql((string)file_get_contents($path)) as $statement) {
        if ($until !== null && str_starts_with($statement, 'DROP TABLE IF EXISTS `' . $until . '`')) break;
        $pdo->exec($statement);
    }
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

/** The rows of every table schema_guarded_tables() names, leaving out any that is not there. */
function guarded_counts(PDO $pdo): array {
    $counts = [];
    foreach (schema_guarded_tables() as $table) {
        try { $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
        catch (PDOException) { /* not created by these migrations, or not there any more */ }
    }
    return $counts;
}

/** What 019 is about, read back: every child, every change-log line, every count. */
function portal_state(PDO $pdo): array {
    return [
        'students' => $pdo->query('SELECT id, first_name, last_name, account_id, email, revision, updated_at FROM students ORDER BY id')->fetchAll(),
        'versions' => $pdo->query('SELECT entity, entity_id, operation, label, before_json, after_json, actor_id, created_at'
            . ' FROM record_versions ORDER BY id')->fetchAll(),
        'counts' => guarded_counts($pdo),
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
 * Logins as the version before usernames wrote them, for 022, 023 and 024.
 *
 * A staff login, a name with umlauts and a hyphen, a student login whose student
 * is gone, a login still at the invitation and an address with a quoted local
 * part. Their addresses are lower-case ASCII, because every address the portal
 * stores has been through email_value().
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

/** Every login, every column, in id order. */
function every_login(PDO $pdo): array {
    return $pdo->query('SELECT * FROM accounts ORDER BY id')->fetchAll();
}

/**
 * Every index on accounts but the primary key, keyed by name: whether it is
 * unique, and its columns.
 *
 * The name is kept as well as the columns, because it shows the address is
 * still guarded by the index 001 made, which the engine called email after its
 * column.
 */
function login_indexes(PDO $pdo): array {
    $indexes = [];
    foreach ($pdo->query("SELECT index_name AS name, non_unique AS non_unique, column_name AS col FROM information_schema.statistics"
        . " WHERE table_schema = DATABASE() AND table_name = 'accounts' AND index_name <> 'PRIMARY'"
        . ' ORDER BY index_name, seq_in_index')->fetchAll() as $row) {
        $indexes[$row['name']]['unique'] = (int)$row['non_unique'] === 0;
        $indexes[$row['name']]['columns'][] = $row['col'];
    }
    ksort($indexes);
    return $indexes;
}

/**
 * What the database says to a login written with these values, as its
 * SQLSTATE, or null if it took it.
 *
 * The write is undone again, so the next one meets the logins as they were.
 */
function refusal(PDO $pdo, array $row): ?string {
    try {
        $id = insert_row($pdo, 'accounts', $row + ['role' => 'student', 'created_at' => gmdate('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        return (string)$e->getCode();
    }
    $pdo->exec('DELETE FROM accounts WHERE id = ' . $id);
    return null;
}

/**
 * Two logins written the way the portal writes one after 024, naming no
 * username, each on an address of its own: what the database said to each.
 *
 * Both are undone again. With a username column still unique at '', the second
 * is the one refused - which is what shows this looks at the right thing.
 */
function two_logins_without_a_username(PDO $pdo): array {
    [$said, $made] = [[], []];
    foreach (['erste.neue@beispiel.test', 'zweite.neue@beispiel.test'] as $email) {
        try {
            $made[] = insert_row($pdo, 'accounts', ['name' => '', 'email' => $email, 'role' => 'student', 'created_at' => gmdate('Y-m-d H:i:s')]);
            $said[] = null;
        } catch (PDOException $e) {
            $said[] = (string)$e->getCode();
        }
    }
    foreach ($made as $id) $pdo->exec('DELETE FROM accounts WHERE id = ' . $id);
    return $said;
}

/** 001 to 021 with a portal's data, and the logins above written in. */
function build_portal_before_022(): array {
    [$pdo] = build_portal_before_019();
    apply_migrations($pdo, '019', '021');
    return [$pdo, add_logins_before_022($pdo)];
}

/**
 * The same portal as the previous version left it, for 024 to run on.
 *
 * 022 and 023 applied, and every login given a username the way that version's
 * update gave them - written here as data, since the code that made them is
 * gone. The cases above get the names its rule made of theirs; the logins from
 * before 015 and 019 one after their id, which is as unique and is all 024 can
 * tell apart. One is left at its '#' placeholder, as an update to that version
 * that stopped in its runner step would have left it. Beside them, logins that
 * version wrote itself, named as they were made: an invitation never taken up,
 * with its link, and a suspended trainer. And a change-log line about a username
 * change, which the history page keeps reading under its old label.
 */
function build_portal_before_024(): array {
    [$pdo, $logins] = build_portal_before_022();
    apply_migrations($pdo, '022', '023');
    $name = $pdo->prepare('UPDATE accounts SET username = ? WHERE id = ?');
    foreach (['mueller' => 'lena.mueller', 'mueller2' => 'lena.mueller2', 'gross' => 'hans-juergen.gross-oezdemir',
              'staff' => 'trainerin', 'invited' => 'eva.hofer', 'legacy' => 'familie.alt'] as $key => $username)
        $name->execute([$username, $logins[$key]]);
    $pdo->prepare("UPDATE accounts SET username = CONCAT('login.', id) WHERE username LIKE '#%' AND id <> ?")->execute([$logins['orphan']]);
    $later = '2026-09-15 08:00:00';
    $logins['invitation'] = insert_row($pdo, 'accounts', ['name' => 'Jana Berger', 'email' => 'jana@beispiel.test',
        'username' => 'jana.berger', 'role' => 'student', 'state' => 'invited', 'locale' => 'en', 'created_at' => $later]);
    insert_row($pdo, 'auth_tokens', ['account_id' => $logins['invitation'], 'token_hash' => hash('sha256', 'einladung-jana'),
        'purpose' => 'invite', 'expires_at' => '2026-09-17 08:00:00', 'created_at' => $later]);
    $logins['suspended'] = insert_row($pdo, 'accounts', ['name' => 'Co-Trainer', 'email' => 'co@beispiel.test',
        'username' => 'co-trainer', 'role' => 'trainer', 'state' => 'suspended',
        'password_hash' => '$2y$10$abcdefghijklmnopqrstuuOLDHASHkeptbytheupdateYYYYYYYYYY', 'verified_at' => $later, 'created_at' => $later]);
    insert_row($pdo, 'record_versions', ['entity' => 'accounts', 'entity_id' => $logins['mueller'], 'operation' => 'update',
        'label' => 'Familie Müller', 'before_json' => json_encode(['username' => 'lena.m']),
        'after_json' => json_encode(['username' => 'lena.mueller']), 'actor_id' => $logins['mueller'], 'created_at' => $later]);
    return [$pdo, $logins];
}

/**
 * Chats as the version before 025 wrote them, for 025 to sort (ADR 0022 §5).
 *
 * A two-person chat a trainer started with a student, and so owned; then one
 * for each of the four things 025 asks before it changes a chat, each failing
 * that one alone: a thread of the old shared desk that the trainer answered,
 * which made her a participant - two people, a student and staff, exactly like
 * the first, and still not its kind; a chat between two students, with nobody
 * from staff; one between two trainers, with no student; and one of three
 * people, two students and a trainer. Each with a message from everybody in it,
 * so a message lost would show.
 */
function add_chats_before_025(PDO $pdo, array $logins): array {
    $at = '2026-09-20 08:00:00';
    $chat = function (string $kind, int $owner, array $people) use ($pdo, $at): int {
        $id = insert_row($pdo, 'threads', ['account_id' => $owner, 'kind' => $kind, 'subject' => '', 'updated_at' => $at]);
        foreach ($people as $person) {
            insert_row($pdo, 'thread_participants', ['thread_id' => $id, 'account_id' => $person, 'joined_at' => $at]);
            insert_row($pdo, 'messages', ['thread_id' => $id, 'sender_id' => $person, 'body' => 'Hallo', 'created_at' => $at]);
        }
        return $id;
    };
    return ['trainer_and_student' => $chat('direct', $logins['staff'], [$logins['staff'], $logins['mueller']]),
            'two_students' => $chat('direct', $logins['mueller2'], [$logins['mueller2'], $logins['gross']]),
            'desk' => $chat('staff', $logins['gross'], [$logins['gross'], $logins['staff']]),
            'two_staff' => $chat('direct', $logins['suspended'], [$logins['suspended'], $logins['staff']]),
            'three_people' => $chat('direct', $logins['mueller'], [$logins['mueller'], $logins['gross'], $logins['staff']])];
}

/** What 025 is held to, read back: every chat's kind and owner, and how many chats, messages and people in them. */
function chat_state(PDO $pdo): array {
    $count = fn(string $table): int => (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    return ['threads' => $pdo->query('SELECT id, kind, account_id FROM threads ORDER BY id')->fetchAll(),
            'counts' => ['threads' => $count('threads'), 'messages' => $count('messages'),
                         'thread_participants' => $count('thread_participants')]];
}

/**
 * What 024 is held to, read back: every login, the table's columns and indexes,
 * every guarded count, the sign-in links and the change log about logins.
 */
function login_state(PDO $pdo): array {
    return ['accounts' => every_login($pdo), 'columns' => table_columns($pdo, 'accounts'), 'indexes' => login_indexes($pdo),
            'counts' => guarded_counts($pdo),
            'tokens' => $pdo->query('SELECT * FROM auth_tokens ORDER BY id')->fetchAll(),
            'versions' => $pdo->query("SELECT * FROM record_versions WHERE entity = 'accounts' ORDER BY id")->fetchAll()];
}

/**
 * The people 028 to 031 and the runner's step after them are about, written the
 * way the version before 028 writes them (ADR 0023 §4, 0024 §1).
 *
 * The portal 024 left has most of them already: students with a login, students
 * the earlier updates left without one (019 took brothers and sisters off a
 * shared login), one whose name is longer than a login's name holds, Ida, whose
 * address is the Novak family's login, a login whose student is gone, and
 * invitations by address that no student points to. Beside them: two brothers
 * on one parent's address, neither with a login, whom a placeholder carrying
 * the address would make collide; an example student without a login, whose
 * placeholder has to go with demo_clear(); a student invited by e-mail who has
 * not accepted yet, whose invitation has to stay exactly as it is; and a course
 * with enrolments of students with and without a login, one of them a past
 * membership, each on terms of its own so a term lost would show.
 */
function add_people_before_028(PDO $pdo): array {
    $at = '2026-09-25 08:00:00';
    $child = fn(?int $account, string $first, string $last, string $email, int $demo = 0): int => insert_row($pdo, 'students', [
        'account_id' => $account, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'status' => 'active',
        'joined_on' => '2026-09-01', 'revision' => 2, 'is_demo' => $demo, 'created_at' => $at, 'updated_at' => $at]);
    $people = ['max' => $child(null, 'Max', 'Wagner', 'familie.wagner@beispiel.test'),
               'moritz' => $child(null, 'Moritz', 'Wagner', 'familie.wagner@beispiel.test'),
               'example' => $child(null, 'Beispiel', 'Kind', '', 1)];
    // As invite_student() writes one: the login first, with its link, then the
    // student pointing at it.
    $people['clara_login'] = insert_row($pdo, 'accounts', ['name' => 'Clara Fuchs', 'email' => 'clara@beispiel.test',
        'role' => 'student', 'state' => 'invited', 'locale' => 'de', 'created_at' => $at]);
    insert_row($pdo, 'auth_tokens', ['account_id' => $people['clara_login'], 'token_hash' => hash('sha256', 'einladung-clara'),
        'purpose' => 'invite', 'expires_at' => '2026-09-27 08:00:00', 'created_at' => $at]);
    $people['clara'] = $child($people['clara_login'], 'Clara', 'Fuchs', 'clara@beispiel.test');
    $people['course'] = insert_row($pdo, 'classes', ['name' => 'Jugendtraining', 'description' => '', 'location' => 'Halle Süd',
        'capacity' => 12, 'sort_order' => 10, 'archived' => 0, 'created_at' => $at]);
    $enrol = fn(int $student, int $price, ?string $left, int $discount) => insert_row($pdo, 'class_students', [
        'class_id' => $people['course'], 'student_id' => $student, 'joined_on' => '2026-02-01', 'left_on' => $left,
        'price_cents' => $price, 'price_note' => 'vereinbart', 'due_day' => 15, 'interval_months' => 3,
        'discount_months' => $discount ? 2 : 0, 'discount_kind' => 'amount', 'discount_value' => $discount,
        'discount_note' => $discount ? 'Geschwister' : '']);
    $enrol(20, 3900, null, 0);            // Paul, on his family's login
    $enrol(51, 3600, null, 500);          // Lisa, taken off it by 019
    $enrol(50, 3900, '2026-06-30', 0);    // Jonas, who has left: a past membership
    $enrol($people['max'], 3600, null, 500);
    $enrol($people['moritz'], 3600, null, 500);
    $enrol($people['example'], 3000, null, 0);
    $enrol($people['clara'], 3900, null, 0);
    return $people;
}

/** 001 to 027 with every portal above written in, and the people 028 is about. */
function build_portal_before_028(): array {
    [$pdo, $logins] = build_portal_before_024();
    apply_migrations($pdo, '024', '027');
    return [$pdo, $logins + add_people_before_028($pdo)];
}

/**
 * The keys from students to other tables, as the engine describes them: the
 * name, the column, the table it points at, and what deleting a row there does.
 */
function student_keys(PDO $pdo): array {
    return $pdo->query('SELECT k.constraint_name AS name, k.column_name AS col, k.referenced_table_name AS refers_to, r.delete_rule AS on_delete'
        . ' FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r'
        . ' ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name'
        . " WHERE k.table_schema = DATABASE() AND k.table_name = 'students' AND k.referenced_table_name IS NOT NULL"
        . ' ORDER BY k.column_name, k.constraint_name')->fetchAll();
}

/**
 * What the engine says of one column: its type, whether it may be NULL, its
 * default, its collation and its place, or null when there is no such column.
 */
function column_facts(PDO $pdo, string $table, string $column): ?array {
    $query = $pdo->prepare('SELECT column_type AS type, is_nullable AS nullable, column_default AS default_value,'
        . ' collation_name AS collation, ordinal_position AS position FROM information_schema.columns'
        . ' WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $query->execute([$table, $column]);
    $facts = $query->fetch();
    if (!$facts) return null;
    // MariaDB reports a default of NULL as the text 'NULL', MySQL 8.0 as NULL.
    if ($facts['default_value'] !== null && strtoupper((string)$facts['default_value']) === 'NULL') $facts['default_value'] = null;
    $facts['position'] = (int)$facts['position'];
    return $facts;
}

/**
 * What 028 to 031 and the step after them are held to, read back: every login,
 * student, enrolment and link, the columns and keys they change, the indexes,
 * every guarded count and the mail waiting.
 */
function login_rule_state(PDO $pdo): array {
    return ['accounts' => every_login($pdo),
            'students' => $pdo->query('SELECT id, account_id, first_name, last_name, email, is_demo, revision, updated_at FROM students ORDER BY id')->fetchAll(),
            'enrolments' => $pdo->query('SELECT * FROM class_students ORDER BY class_id, student_id')->fetchAll(),
            'tokens' => $pdo->query('SELECT * FROM auth_tokens ORDER BY id')->fetchAll(),
            'columns' => ['email' => column_facts($pdo, 'accounts', 'email'), 'username' => column_facts($pdo, 'accounts', 'username'),
                          'left_on' => column_facts($pdo, 'class_students', 'left_on'),
                          'removed_on' => column_facts($pdo, 'class_students', 'removed_on')],
            'keys' => student_keys($pdo), 'indexes' => login_indexes($pdo), 'one_account' => one_account_index($pdo),
            'counts' => guarded_counts($pdo),
            'mail' => (int)$pdo->query('SELECT COUNT(*) FROM mail_jobs')->fetchColumn()];
}

/**
 * Run a file's statements again on a connection of its own, as the next page
 * view does after an update that applied the file but stopped before the ledger
 * recorded it: the SQLSTATE the engine refused it with, or null if it ran.
 */
function run_again(string $path): ?string {
    // A new connection: no variable or prepared statement of the first run survives into it.
    $next = connect();
    try { foreach (migration_statements($path) as $statement) $next->exec($statement); return null; }
    catch (PDOException $e) { return (string)$e->getCode(); }
}

/**
 * What the database said to these writes, as [SQLSTATE, the engine's own error
 * number] for the first it refused, or null when it took them all. Undone either
 * way, so the next attempt meets the portal as it was.
 */
function attempt(PDO $pdo, array $writes): ?array {
    $pdo->beginTransaction();
    $said = null;
    try { foreach ($writes as [$sql, $params]) $pdo->prepare($sql)->execute($params); }
    catch (PDOException $e) { $said = [(string)$e->getCode(), (int)($e->errorInfo[1] ?? 0)]; }
    $pdo->rollBack();
    return $said;
}

/**
 * What the version before 032 and 033 keeps in the four tables they drop, and
 * the change-log lines that name them (ADR 0026 §7, §8).
 *
 * Two custom fields, one of them archived the way field_save leaves a field
 * that held data, with values on three students, one of whom has a value in
 * each; a change-log line about a student's value, under the field:<id> key the
 * change log gives one, and one about a field. Two saved views. The two
 * templates a first install seeded, one the trainer wrote, and a change-log
 * line about one of them.
 */
function add_custom_fields_views_and_templates(PDO $pdo): array {
    $at = '2026-09-30 08:00:00';
    $field = fn(string $label, string $type, string $options, string $default, int $required, string $visibility, int $archived): int =>
        insert_row($pdo, 'field_definitions', ['label' => $label, 'label_en' => '', 'field_type' => $type, 'section_name' => '',
            'options_json' => $options, 'default_json' => $default, 'required' => $required, 'visibility' => $visibility,
            'sort_order' => 10, 'archived' => $archived]);
    $shirt = $field('T-Shirt-Größe', 'select', '["S","M","L"]', '""', 1, 'edit', 0);
    $school = $field('Schule', 'text', '[]', '""', 0, 'internal', 1);
    // Paul, Lisa and Ida, from the portal before 019.
    foreach ([[20, $shirt, '"M"'], [20, $school, '"VS Nord"'], [51, $shirt, '"S"'], [80, $shirt, '"L"']] as [$student, $fieldId, $value])
        insert_row($pdo, 'field_values', ['student_id' => $student, 'field_id' => $fieldId, 'value_json' => $value]);
    $line = fn(string $entity, int $id, string $label, array $before, array $after) => insert_row($pdo, 'record_versions', [
        'entity' => $entity, 'entity_id' => $id, 'operation' => 'update', 'label' => $label,
        'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE), 'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE),
        'actor_id' => null, 'created_at' => $at]);
    $line('students', 20, 'Paul Gruber', ['field:' . $shirt => '"S"'], ['field:' . $shirt => '"M"']);
    $line('field_definitions', $school, 'Schule', ['archived' => 0], ['archived' => 1]);
    $views = [insert_row($pdo, 'saved_filters', ['name' => 'Überfällig', 'criteria_json' => '{"overdue":"1"}']),
              insert_row($pdo, 'saved_filters', ['name' => 'Jugend, krank', 'criteria_json' => '{"sick":"1","q":"Jugend"}'])];
    $template = fn(string $name, string $subject, string $body): int =>
        insert_row($pdo, 'message_templates', ['name' => $name, 'subject' => $subject, 'body' => $body]);
    $reminder = $template('Zahlungserinnerung', 'Dein Badminton-Beitrag', "Hallo {{first_name}},\n\nbei deinen Badminton-Beiträgen sind derzeit {{outstanding}} offen.\n\n{{portal_url}}");
    $templates = [$reminder, $template('Training – Information', 'Information zum Training', "Hallo {{first_name}},\n\n{{portal_url}}"),
                  $template('Hallenwechsel', 'Training in der Halle Süd', "Hallo {{first_name}}, ab Montag in der Halle Süd.")];
    $line('message_templates', $reminder, 'Zahlungserinnerung', ['subject' => 'Beitrag'], ['subject' => 'Dein Badminton-Beitrag']);
    return ['fields' => [$shirt, $school], 'views' => $views, 'templates' => $templates];
}

/** 001 to 031 with every portal above written in, and rows in the four tables 032 and 033 drop. */
function build_portal_before_032(): array {
    [$pdo] = build_portal_before_028();
    apply_migrations($pdo, '028', '031');
    return [$pdo, add_custom_fields_views_and_templates($pdo)];
}

/**
 * Every table, its rows counted and checksummed, and every foreign key, as the
 * engine describes them: what 032 and 033 are held to, which must take their
 * own tables away and leave every other table, row and key as it was. CHECKSUM
 * TABLE reads every row, so a row changed or lost anywhere shows, in a table
 * schema_guarded_tables() names or not.
 */
function every_table(PDO $pdo): array {
    $tables = array_column($pdo->query('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(), 'name');
    sort($tables);
    $quoted = array_map(fn(string $table): string => '`' . sql_name($table, 'table') . '`', $tables);
    $rows = $sums = [];
    foreach ($tables as $i => $table) $rows[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $quoted[$i])->fetchColumn();
    foreach ($pdo->query('CHECKSUM TABLE ' . implode(', ', $quoted))->fetchAll() as $row)
        $sums[substr((string)$row['Table'], strpos((string)$row['Table'], '.') + 1)] = (string)$row['Checksum'];
    ksort($sums);
    return ['tables' => $tables, 'rows' => $rows, 'sums' => $sums,
            'keys' => $pdo->query('SELECT table_name AS on_table, constraint_name AS name, referenced_table_name AS refers_to, delete_rule AS on_delete'
                . ' FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() ORDER BY table_name, constraint_name')->fetchAll()];
}

/**
 * The change-log lines about a custom field, a value in one or a template:
 * what the history page goes on reading once 032 and 033 have dropped the
 * tables they name.
 */
function lines_about_what_goes(PDO $pdo): array {
    return $pdo->query("SELECT * FROM record_versions WHERE entity IN ('field_definitions', 'message_templates')"
        . " OR before_json LIKE '%\"field:%' OR after_json LIKE '%\"field:%' ORDER BY id")->fetchAll();
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
$twentyBefore = ['accounts' => login_choices($pdo), 'counts' => guarded_counts($pdo)];
apply_migrations($pdo, '020', '999');
$twentyAfter = ['accounts' => login_choices($pdo), 'counts' => guarded_counts($pdo),
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

// --- 022, 023 and 024 on logins from before usernames ---------------------------
// A portal still on 021 runs all three in one update. The column 022 adds is the
// one 024 drops, so every login the version before usernames wrote must come out
// exactly as it went in (ADR 0021 §1).
$twentyThree = migration_path('023');
$twentyThreeStatements = migration_statements($twentyThree);
$twentyFour = migration_path('024');
$twentyFourStatements = migration_statements($twentyFour);
[$pdo, $logins] = build_portal_before_022();
$usernamesBefore = login_state($pdo);
apply_migrations($pdo, '022', '024');
$result['usernames'] = ['logins' => $logins, 'statements' => count($twentyThreeStatements),
                        'before' => $usernamesBefore, 'after' => login_state($pdo), 'retried' => []];

// --- 023 interrupted after each statement and started again, then 024 ----------
// The last round lets 023 finish and runs its UPDATE once more, as a retry after
// the file finished but before the ledger recorded it would.
for ($stopped = 1; $stopped <= count($twentyThreeStatements); $stopped++) {
    [$pdo] = build_portal_before_022();
    apply_migrations($pdo, '022', '022');
    run_statements($pdo, $twentyThree, array_slice($twentyThreeStatements, 0, $stopped));
    run_statements($pdo, $twentyThree, $stopped < count($twentyThreeStatements)
        ? $twentyThreeStatements : array_slice($twentyThreeStatements, 0, -1));
    run_statements($pdo, $twentyFour, $twentyFourStatements);
    $result['usernames']['retried'][$stopped] = ['accounts' => every_login($pdo), 'indexes' => login_indexes($pdo)];
}

// --- 024 on the logins the previous version left --------------------------------
// Every login keeps every value but its username; the column and its index go and
// nothing else does; afterwards a login can be made without a username, twice,
// and the address still refuses a second login.
[$pdo, $logins] = build_portal_before_024();
$twentyFourBefore = login_state($pdo) + ['two_new' => two_logins_without_a_username($pdo)];
run_statements($pdo, $twentyFour, $twentyFourStatements);
$address = fn(string $email): ?string => refusal($pdo, ['name' => 'Zweiter Zugang', 'email' => $email]);
$result['twentyfour'] = [
    'logins' => $logins, 'statements' => count($twentyFourStatements), 'before' => $twentyFourBefore,
    'after' => login_state($pdo) + ['two_new' => two_logins_without_a_username($pdo)],
    // A second login on the address of a login 024 carried through: as typed, in
    // other capitals, and on the legacy quoted address [R2]. The negative: the
    // same write on an address of its own is taken, so what refused the others
    // was the address.
    'same_address' => $address('mueller@beispiel.test'),
    'address_other_case' => $address('Mueller@Beispiel.test'),
    'legacy_address_taken' => $address('"familie..alt"@beispiel.test'),
    'own_address' => $address('zweiter.zugang@beispiel.test'),
];
// A second run, as the next page view would make it after an update that
// applied the file but stopped before the ledger recorded it.
$refused = null;
try { foreach ($twentyFourStatements as $statement) $pdo->exec($statement); }
catch (PDOException $e) { $refused = (string)$e->getCode(); }
$result['twentyfour']['again'] = ['refused' => $refused, 'state' => login_state($pdo)];

// --- 025 on the chats the previous version wrote ----------------------------------
// It turns a two-person chat between a student and staff into 'staff_direct',
// owned by the student, and leaves every other chat as it was (ADR 0022 §5). Its
// UPDATE comes before its ALTER so that an update stopped between them can start
// the file again from the top (§10). Straight through here, on the portal 024
// left; stopped and started again at the end, where building a portal for each
// round cannot take this one away from the runner's step below.
$twentyFive = migration_path('025');
$twentyFiveStatements = migration_statements($twentyFive);
$chats = add_chats_before_025($pdo, $logins);
$twentyFiveBefore = chat_state($pdo);
run_statements($pdo, $twentyFive, $twentyFiveStatements);
$result['twentyfive'] = ['chats' => $chats, 'logins' => $logins, 'statements' => count($twentyFiveStatements),
                         'before' => $twentyFiveBefore, 'after' => chat_state($pdo), 'retried' => []];

// --- the runner's step after 024 -------------------------------------------------
// database/defaults.php, which schema_apply() requires after the files on every
// update, run through the application's own functions on the application's own
// connection to this portal's database, twice, the second time as the next
// update would. The portal is marked as set up, as every portal being updated is,
// so only the part that runs on every update runs. Up to 023 that step gave out
// usernames; after 024 it must not reach for the column at all, or every request
// would meet a failed update and the portal would stay closed.
$pdo->exec("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('defaults_initialized', 'true', '2025-10-03 08:00:00')");
// The step runs after the newest file, so the files after 025 go in first, as
// an update applies them.
foreach (glob(APP_ROOT . '/database/migrations/*.sql') as $file)
    if (strcmp(basename($file), '026') >= 0)
        foreach (split_sql((string)file_get_contents($file)) as $statement) $pdo->exec($statement);
$runnerState = function () use ($pdo): array {
    setting_cache_clear();
    return ['accounts' => every_login($pdo), 'counts' => guarded_counts($pdo),
            'without_login' => (int)$pdo->query('SELECT COUNT(*) FROM students WHERE account_id IS NULL')->fetchColumn(),
            'mail' => (int)$pdo->query('SELECT COUNT(*) FROM mail_jobs')->fetchColumn(),
            'courses' => (int)$pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn(),
            'dummy_hash' => (string)setting('sign_in_dummy_hash')];
};
// What it threw, if anything, so a failure reads as an assertion in the suite
// rather than as this process stopping.
$runnerStep = static function (): string {
    try { require APP_ROOT . '/database/defaults.php'; return ''; }
    catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
};
$runner = ['before' => $runnerState(), 'first_error' => $runnerStep()];
$runner['first'] = $runnerState();
$runner['second_error'] = $runnerStep();
$runner['second'] = $runnerState();
$result['twentyfour']['runner'] = $runner;

// --- 025 interrupted after each statement and started again from the first -------
// The last round lets it finish and runs its UPDATE once more, as a retry after
// the file finished but before the ledger recorded it would. Each round starts
// from a portal of its own, built and brought to 024 the same way as above.
for ($stopped = 1; $stopped <= count($twentyFiveStatements); $stopped++) {
    [$pdo, $logins] = build_portal_before_024();
    run_statements($pdo, $twentyFour, $twentyFourStatements);
    add_chats_before_025($pdo, $logins);
    run_statements($pdo, $twentyFive, array_slice($twentyFiveStatements, 0, $stopped));
    run_statements($pdo, $twentyFive, $stopped < count($twentyFiveStatements)
        ? $twentyFiveStatements : array_slice($twentyFiveStatements, 0, -1));
    $result['twentyfive']['retried'][$stopped] = chat_state($pdo);
}

// --- 028 to 031 and the runner's step after them ----------------------------------
// A login may have a username and no address (028); the key that emptied a
// student's login on delete goes (029) and comes back as RESTRICT (030); an
// enrolment gets removed_on (031); and the runner's step gives every student
// without a login a placeholder (ADR 0023 §2-§4, 0024 §1). One portal, one file
// at a time, with each file run a second time straight after, as the next page
// view does after an update that applied it but stopped before the ledger
// recorded it: 029 must then do nothing, the others be refused and change
// nothing.
$files = [];
foreach (['028', '029', '030', '031'] as $number) $files[$number] = migration_path($number);
[$pdo, $people] = build_portal_before_028();
$state = ['before' => login_rule_state($pdo)];
$refused = [];
foreach ($files as $number => $path) {
    run_statements($pdo, $path, migration_statements($path));
    $state[$number] = login_rule_state($pdo);
    $refused[$number] = run_again($path);
    $state[$number . '_again'] = login_rule_state($pdo);
    // And 029 once more after 030 has added the key it must never take.
    if ($number === '030') {
        $refused['029_after_030'] = run_again($files['029']);
        $state['029_after_030'] = login_rule_state($pdo);
    }
}

// The runner's step on that portal: database/defaults.php on the application's
// own connection, as schema_apply() requires it after the files. First stopped
// part way, after each placeholder but the last, by a trigger that refuses the
// next one, the way a lost connection or a full disk would stop it: each time,
// every student and every login must be as before the step. Then through, then
// once more as the next update would run it.
$pdo->exec("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('defaults_initialized', 'true', '2025-10-03 08:00:00')");
$withoutLogin = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE account_id IS NULL')->fetchColumn();
$step = ['without_login' => $withoutLogin, 'stopped' => []];
for ($stopped = 1; $stopped < $withoutLogin; $stopped++) {
    $pdo->exec("CREATE TRIGGER stop_the_step BEFORE INSERT ON accounts FOR EACH ROW"
        . " IF NEW.state = 'placeholder' AND (SELECT COUNT(*) FROM accounts WHERE state = 'placeholder') >= $stopped"
        . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stopped by the test'; END IF");
    setting_cache_clear();
    $error = $runnerStep();
    $pdo->exec('DROP TRIGGER stop_the_step');
    $step['stopped'][$stopped] = ['error' => $error, 'state' => login_rule_state($pdo)];
}
setting_cache_clear();
$step['first_error'] = $runnerStep();
$step['first'] = login_rule_state($pdo);
setting_cache_clear();
$step['second_error'] = $runnerStep();
$step['second'] = login_rule_state($pdo);

// What the database says afterwards, each write undone again. A student's login
// of every kind - a placeholder, one in use, an invitation not yet taken up - is
// refused; a login nobody points to, and a student's login once the student is
// gone, are not; a student cannot be pointed at a login that does not exist.
// And the address and username rules 028 sets: a login with neither, twice; one
// with a username alone; a username taken in other capitals; an address taken.
$loginOf = fn(int $studentId): int => (int)$pdo->query('SELECT account_id FROM students WHERE id = ' . $studentId)->fetchColumn();
$delete = fn(int $account): array => [['DELETE FROM accounts WHERE id = ?', [$account]]];
$login = fn(string $name, ?string $email, ?string $username): array =>
    ['INSERT INTO accounts (name, email, username, role, state, created_at) VALUES (?, ?, ?, ?, ?, ?)',
     [$name, $email, $username, 'student', 'invited', '2026-10-01 08:00:00']];
$step['attempts'] = [
    'placeholder' => attempt($pdo, $delete($loginOf((int)$people['max']))),
    // Paul's family login, in use since before 019.
    'in_use' => attempt($pdo, $delete($loginOf(20))),
    'invitation_of_a_student' => attempt($pdo, $delete((int)$people['clara_login'])),
    'orphan' => attempt($pdo, $delete((int)$people['orphan'])),
    'invitation_by_address' => attempt($pdo, $delete((int)$people['invitation'])),
    'student_first' => attempt($pdo, [['DELETE FROM students WHERE id = ?', [$people['max']]],
                                      ['DELETE FROM accounts WHERE id = ?', [$loginOf((int)$people['max'])]]]),
    'missing_login' => attempt($pdo, [['UPDATE students SET account_id = ? WHERE id = ?', [987654, $people['max']]]]),
    'student_without_login' => attempt($pdo, [['INSERT INTO students (first_name, last_name, status, revision, created_at, updated_at)'
        . " VALUES ('Ohne', 'Zugang', 'active', 1, '2026-10-01 08:00:00', '2026-10-01 08:00:00')", []]]),
    'neither_twice' => attempt($pdo, [$login('Erster Platzhalter', null, null), $login('Zweiter Platzhalter', null, null)]),
    'username_alone' => attempt($pdo, [$login('Lena Hofer', null, 'lena.hofer')]),
    'username_other_case' => attempt($pdo, [$login('Lena Hofer', null, 'lena.hofer'), $login('Lena Hofer', null, 'Lena.Hofer')]),
    'username_own' => attempt($pdo, [$login('Lena Hofer', null, 'lena.hofer'), $login('Lena Hofer', null, 'lena.hofer2')]),
    'address_taken' => attempt($pdo, [$login('Zweiter Zugang', 'mueller@beispiel.test', null)]),
];
$step['after_attempts'] = login_rule_state($pdo);

// --- 029 stopped after each statement and started again on a new connection --------
// The next page view is a new connection, with neither the first one's variable
// nor its prepared statement. Each round on a portal of its own; then 030, which
// must add its key as it does after one run.
$twentyNine = migration_statements($files['029']);
$retried = [];
for ($stopped = 1; $stopped < count($twentyNine); $stopped++) {
    [$pdo] = build_portal_before_028();
    apply_migrations($pdo, '028', '028');
    $before = login_rule_state($pdo);
    run_statements($pdo, $files['029'], array_slice($twentyNine, 0, $stopped));
    $pdo = null;
    $next = connect();
    run_statements($next, $files['029'], $twentyNine);
    $retried[$stopped] = ['before' => $before, 'after' => login_rule_state($next)];
    run_statements($next, $files['030'], migration_statements($files['030']));
    $retried[$stopped]['keys_after_030'] = student_keys($next);
}

// --- 029 on a key that has another name ----------------------------------------------
// The name was the engine's choice (001 gave none). A database whose key is
// called something else - another engine, a restore by a tool that names keys
// its own way - must come out the same.
[$pdo] = build_portal_before_028();
$pdo->exec('ALTER TABLE students DROP FOREIGN KEY students_ibfk_1');
$pdo->exec('ALTER TABLE students ADD CONSTRAINT login_of_this_student FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE SET NULL');
$renamed = ['before' => student_keys($pdo)];
apply_migrations($pdo, '028', '030');
$renamed['after'] = student_keys($pdo);

$result['twentyeight'] = ['people' => $people, 'statements' => array_map(fn($path) => count(migration_statements($path)), $files),
                          'state' => $state, 'refused' => $refused, 'step' => $step, 'retried' => $retried, 'renamed' => $renamed];

// --- 032 and 033 on custom fields, saved views and templates ------------------------
// Both only drop: 032 the custom fields with every value, 033 the saved views and
// the templates (ADR 0026 §7, §8). On the portal 028 to 031 were applied to, with
// rows in all four tables and change-log lines that name them, each file is held
// to taking its own tables away and nothing else, then run a second time on a
// connection of its own, as the next page view does after an update that applied
// it but stopped before the ledger recorded it.
$dropping = ['032' => migration_path('032'), '033' => migration_path('033')];
[$pdo, $written] = build_portal_before_032();
$dropState = fn(): array => every_table($pdo) + ['lines' => lines_about_what_goes($pdo)];
$drops = ['written' => $written, 'before' => $dropState(),
          'statements' => array_map(fn(string $path): int => count(migration_statements($path)), $dropping)];
foreach ($dropping as $number => $path) {
    run_statements($pdo, $path, migration_statements($path));
    $drops[$number] = $dropState();
    $drops['refused'][$number] = run_again($path);
    $drops[$number . '_again'] = $dropState();
}

// --- 032 and 033 stopped after each statement and started again from the first -----
// Each round on a portal of its own, built and filled the same way, 033's with 032
// applied first.
foreach ($dropping as $number => $path) {
    $statements = migration_statements($path);
    for ($stopped = 1; $stopped < count($statements); $stopped++) {
        [$pdo] = build_portal_before_032();
        if ($number === '033') run_statements($pdo, $dropping['032'], migration_statements($dropping['032']));
        $round = ['before' => every_table($pdo)];
        run_statements($pdo, $path, array_slice($statements, 0, $stopped));
        $round['stopped'] = every_table($pdo);
        run_statements($pdo, $path, $statements);
        $drops['retried'][$number][$stopped] = $round + ['after' => every_table($pdo)];
    }
}
$result['dropping'] = $drops;

// --- this release's update, then one with a mistake in it, through the runner -------
// schema_apply() itself, the one copy the installer, the console and the first
// request after an upload all use, on a portal as the previous version leaves it:
// 001 to 031 in its ledger, its runner step run, and rows in the four tables 032
// and 033 drop. The runner reads ROOT/database/migrations, so ROOT is a release of
// this run's own, a copy of the shipped files that one more file can be put into
// without the portal's own folder ever seeing it; the stamp and the backups go to
// this run's folder, beside the maintenance flag. First the update this release
// brings, which must pass the guard and drop what 032 and 033 drop. Then a file
// that drops contacts, a guarded table with rows in it, which the guard must
// refuse: before ADR 0026 §7 it compared only the tables it could still count, and
// let such a file through.
$release = test_run_dir() . '/release';
mkdir($release . '/database/migrations', 0700, true);
foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $file) copy($file, $release . '/database/migrations/' . basename($file));
copy(APP_ROOT . '/database/defaults.php', $release . '/database/defaults.php');
copy(APP_ROOT . '/VERSION', $release . '/VERSION');
// Defined once, here: had anything defined it already, the runner would read the
// shipped files rather than this release, and nothing below would be what it says.
if (defined('ROOT')) { fwrite(STDERR, "ROOT is already defined, so the runner would not read this run's own release.\n"); exit(2); }
define('ROOT', $release);
// What schema_apply() needs beyond the files above: the manifest check, the backup
// and the version it records.
require_once APP_ROOT . '/app/install.php';
require_once APP_ROOT . '/app/backup.php';
require_once APP_ROOT . '/app/version.php';
$GLOBALS['config']['maintenance_file'] = test_run_dir() . '/maintenance.flag';

[$pdo, $written] = build_portal_before_032();
$runner = ['written' => $written,
           'shipped' => array_combine(array_map('basename', $dropping), array_map(fn(string $path): string => hash_file('sha256', $path), $dropping))];
// The ledger as schema_apply() makes it, filled as the previous version's update
// left it, and that update's step after the files run.
$pdo->exec('CREATE TABLE schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$record = $pdo->prepare('INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?, ?, ?)');
foreach (migration_files() as $file)
    if (strcmp(basename($file), '032') < 0) $record->execute([basename($file), hash_file('sha256', $file), '2026-10-01 08:00:00']);
$pdo->exec("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('defaults_initialized', 'true', '2025-10-03 08:00:00')");
setting_cache_clear();
$runner['previous_step_error'] = $runnerStep();
// What the update said, rather than this process stopping on it.
$update = static function (): ?array {
    try { schema_apply(); return null; }
    catch (Throwable $e) {
        return ['class' => get_class($e), 'log' => $e->getMessage()]
            + ($e instanceof UpdateBlocked ? ['de' => $e->de, 'en' => $e->en] : [])
            + ($e instanceof SchemaError ? ['statement' => $e->statement, 'summary' => $e->summary()] : []);
    }
};
// Null when there is no ledger at all, as after an import that broke off before it.
$ledger = function () use ($pdo): ?array {
    try { return $pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll(); }
    catch (PDOException) { return null; }
};
$runner['before'] = every_table($pdo) + ['counts' => guarded_counts($pdo), 'ledger' => $ledger()];
$runner['release'] = ['refused' => $update(), 'current' => schema_is_current(), 'backups' => count(backups())];
$runner['release']['state'] = every_table($pdo) + ['counts' => guarded_counts($pdo), 'ledger' => $ledger()];

// --- a refused update stays refused (ADR 0027) ----------------------------------------
// One schema_apply() is one page view, with the caches a request starts without;
// after it, what it said and everything ADR 0027 holds it to: the ledger, the
// copies, the stamp, the record of an unfinished update, the guarded counts, the
// tables, the version and the release history. Each mistake below is a file of
// this run's release, and each ends where it began: its file taken out again,
// the copy imported, and a run that passes. The copies that are no longer
// needed are removed, so pruning never has to choose between two taken in the
// same second.
$record = function (): ?array {
    clearstatcache();
    if (!is_file(schema_unfinished_file())) return null;
    $text = (string)file_get_contents(schema_unfinished_file());
    return ['text' => $text, 'data' => json_decode($text, true)];
};
$state = function () use ($pdo, $ledger, $record): array {
    clearstatcache();
    setting_cache_clear();
    return ['ledger' => $ledger(), 'copies' => array_column(backups(), 'name'),
            'stamp' => is_file(schema_stamp_file()) ? (string)file_get_contents(schema_stamp_file()) : null,
            'record' => $record(), 'counts' => guarded_counts($pdo),
            'tables' => array_column($pdo->query('SELECT table_name AS name FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE() ORDER BY table_name')->fetchAll(), 'name'),
            'version' => database_version(), 'history' => count(version_history())];
};
$request = function () use ($update, $state): array {
    clearstatcache();
    setting_cache_clear();
    return ['said' => $update()] + $state();
};
$migrations = $release . '/database/migrations';
$add = fn(string $name, string $sql) => file_put_contents($migrations . '/' . $name, $sql);
$take = fn(string $name) => unlink($migrations . '/' . $name);
// The copy a request wrote: the names in the folder it added.
$written = fn(array $before, array $after): array => array_values(array_diff($after['copies'], $before['copies']));
$contactsIn = fn(string $name): int => is_file(backup_dir() . '/' . $name)
    ? substr_count((string)file_get_contents(backup_dir() . '/' . $name), 'INSERT INTO `contacts` VALUES') : -1;
// The portal as this release's update left it, taken outside the copies folder
// so that neither pruning nor a scenario can touch it: what each scenario that
// does not import its own copy goes back to.
$untouched = test_run_dir() . '/as-this-release-left-it.sql';
$handle = fopen($untouched, 'wb');
backup_write($handle, connect(), 'test');
fclose($handle);
$putBack = function (string $mistake, string $copy) use ($take, $pdo, $request): array {
    $take($mistake);
    import_copy($pdo, $copy);
    return $request();
};
// Every scenario after the first starts with no update unfinished. Code that
// leaves a record behind fails the scenario that left it; removing it here keeps
// that one failure from turning each scenario after it into a crash that hides
// which rule broke.
$clean = function (): void {
    clearstatcache();
    if (is_file(schema_unfinished_file())) unlink(schema_unfinished_file());
};
$runner['app_version'] = app_version();
$runner['release']['record'] = $record();

// 1. A loss stays refused, request after request: a file that drops contacts.
$add('999_a_mistake_drops_the_contacts.sql', "DROP TABLE contacts;\n");
$mistake = ['before' => $state(), 'requests' => []];
foreach ([1, 2, 3] as $n) $mistake['requests'][$n] = $request();
$mistake['copy'] = $written($mistake['before'], $mistake['requests'][1]);
$named = backup_dir() . '/' . ($mistake['copy'][0] ?? 'none');
$mistake['copy_contacts'] = $contactsIn($mistake['copy'][0] ?? 'none');

// 3. The stamp and the settings written as a run that passes would write them.
file_put_contents(schema_stamp_file(), schema_state());
set_setting('schema_fingerprint', schema_fingerprint());
set_setting('schema_written_by', app_version());
clearstatcache();
setting_cache_clear();
$mistake['forged'] = ['stamp' => schema_state(), 'current' => schema_is_current(), 'next' => $request()];

// 4. A newer upload on top of the loss: its migration must not run.
$add('999_b_a_newer_release_makes_a_table.sql', "CREATE TABLE made_by_a_newer_release (id INT PRIMARY KEY) ENGINE=InnoDB;\n");
$mistake['newer'] = $request();
$take('999_b_a_newer_release_makes_a_table.sql');

// 6. The copy imported with the new files still in place: they take the same
//    rows again, and still no second copy is written.
import_copy($pdo, $named);
$mistake['imported'] = ['contacts' => guarded_counts($pdo)['contacts'] ?? 0, 'request' => $request(),
                        'copy_contacts' => $contactsIn($mistake['copy'][0] ?? 'none')];

// 5. The previous files - the release without the file that lost the rows -
//    then the copy the record names, first broken off at contacts, then whole.
$take('999_a_mistake_drops_the_contacts.sql');
import_copy($pdo, $named, 'contacts');
$mistake['partial'] = $request();
import_copy($pdo, $named);
$mistake['complete'] = $request();
// 2. ... after which the next page view takes the fast path, asking nothing.
clearstatcache();
setting_cache_clear();
$mistake['afterwards'] = ['current' => schema_is_current(), 'queries' => query_count(fn() => schema_is_current())];
@unlink($named);

// 7. A release that takes contacts off the list, as the record sees it: the
//    table it counted is one no longer guarded. The run passes and deletes it.
$clean();
$add('999_a_mistake_drops_the_contacts.sql', "DROP TABLE contacts;\n");
$offList = ['before' => $state()];
$offList['refused'] = $request();
$offList['copy'] = $written($offList['before'], $offList['refused']);
$kept = $offList['refused']['record']['data']['counts'] ?? null;
if (is_array($kept)) {
    $renamed = $offList['refused']['record']['data'];
    $renamed['counts'] = array_combine(array_map(fn(string $table): string => $table === 'contacts' ? 'contacts_no_longer_guarded' : $table,
                                                  array_keys($kept)), $kept);
    file_put_contents(schema_unfinished_file(), json_encode($renamed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
$offList['released'] = $request();
$offList['restored'] = $putBack('999_a_mistake_drops_the_contacts.sql', $untouched);
foreach ($offList['copy'] as $name) @unlink(backup_dir() . '/' . $name);

// 1. The same with a file that deletes every contact and drops nothing.
$clean();
$add('999_c_mistake_empties_the_contacts.sql', "DELETE FROM contacts;\n");
$emptied = ['before' => $state(), 'requests' => []];
foreach ([1, 2, 3] as $n) $emptied['requests'][$n] = $request();
$emptied['copy'] = $written($emptied['before'], $emptied['requests'][1]);
$emptied['restored'] = $putBack('999_c_mistake_empties_the_contacts.sql', $untouched);
foreach ($emptied['copy'] as $name) @unlink(backup_dir() . '/' . $name);

// 4. A file that deletes one contact and then stops: the retry must not run.
$clean();
$add('999_d_mistake_deletes_a_contact_and_stops.sql', "DELETE FROM contacts ORDER BY id LIMIT 1;\nINSERT INTO no_such_table VALUES (1);\n");
$halfway = ['before' => $state(), 'requests' => []];
foreach ([1, 2] as $n) $halfway['requests'][$n] = $request();
$halfway['copy'] = $written($halfway['before'], $halfway['requests'][1]);
$halfway['restored'] = $putBack('999_d_mistake_deletes_a_contact_and_stops.sql', $untouched);
foreach ($halfway['copy'] as $name) @unlink(backup_dir() . '/' . $name);

// 6. A file that makes a table and then stops, six page views in a row: six
//    times as many as the copies that are kept, plus one.
$clean();
$add('999_e_mistake_makes_a_table_and_stops.sql', "CREATE TABLE made_before_stopping (id INT PRIMARY KEY) ENGINE=InnoDB;\nINSERT INTO no_such_table VALUES (1);\n");
$stopping = ['before' => $state(), 'requests' => []];
for ($n = 1; $n <= BACKUP_KEEP + 1; $n++) $stopping['requests'][$n] = $request();
$stopping['copy'] = $written($stopping['before'], $stopping['requests'][1]);
$stopping['copy_contacts'] = $contactsIn($stopping['copy'][0] ?? 'none');
$stopping['restored'] = $putBack('999_e_mistake_makes_a_table_and_stops.sql', $untouched);
foreach ($stopping['copy'] as $name) @unlink(backup_dir() . '/' . $name);

// 8. No record, no migration: its place taken by a folder, which no write can
//    replace, for root as for anybody (a read-only folder stops nobody as root).
$clean();
mkdir(schema_unfinished_file());
$add('999_f_a_release_makes_a_table.sql', "CREATE TABLE made_without_the_numbers (id INT PRIMARY KEY) ENGINE=InnoDB;\n");
$unwritable = ['before' => $state(), 'request' => $request(), 'part_left' => is_file(schema_unfinished_file() . '.part')];
rmdir(schema_unfinished_file());

// 9. A record that cannot be read: half a JSON object, then one whose counts are
//    a sentence. Nothing may run either time.
file_put_contents(schema_unfinished_file(), "{\n");
$unreadable = ['before' => $state(), 'broken' => $request()];
file_put_contents(schema_unfinished_file(), json_encode(['started' => now(), 'from' => app_version(), 'to' => app_version(),
                                                         'backup' => null, 'counts' => 'alle Kontakte']));
$unreadable['counts_text'] = $request();
$clean();
$take('999_f_a_release_makes_a_table.sql');
$unreadable['after'] = $request();
foreach ($written($unwritable['before'], $unreadable['after']) as $name) @unlink(backup_dir() . '/' . $name);

$result['runner'] = $runner + ['mistake' => $mistake, 'off_list' => $offList, 'emptied' => $emptied, 'halfway' => $halfway,
                               'stopping' => $stopping, 'unwritable' => $unwritable, 'unreadable' => $unreadable];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
