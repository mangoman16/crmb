#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Apply the migrations in two halves, with old-shape data written in between.
 *
 * The suite builds its database by applying every migration to an empty one, so
 * the statements that carry data across have never run against a single row:
 * a tariff's price into tariff_rates, its discount onto every enrolment that
 * was getting it, an address onto every child. "Nobody's next invoice changes"
 * was a claim with nothing behind it.
 *
 * Its own process and its own file, because the database the suite is using has
 * all the migrations applied already and this needs to stop half way. Prints
 * what it found as JSON; tests/suites/migrations.php does the asserting, so the
 * failures read like every other failure in the run.
 *
 *   php tests/migration-data.php <stop-after> <sqlite-file>
 */

require_once __DIR__ . '/harness.php';
// sqlite_translate() splits statements with the application's own split_sql(),
// which normally arrives with the rest of the bootstrap. This process has no
// config and no database to boot against, so the one file is taken on its own.
require_once APP_ROOT . '/app/core.php';

$stopAfter = $argv[1] ?? '014';
$file = $argv[2] ?? (sys_get_temp_dir() . '/crm-migration-' . getmypid() . '.sqlite');
@unlink($file);

$pdo = new TestSqlitePdo('sqlite:' . $file, null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=OFF');   // the halves are applied out of order for nobody

/** Apply the migration files whose number falls in [$from, $to]. */
function apply_migrations(PDO $pdo, string $from, string $to): void {
    foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $number = substr(basename($path), 0, 3);
        if ($number < $from || $number > $to) continue;
        foreach (sqlite_translate((string)file_get_contents($path))['statements'] as $statement) {
            try { $pdo->exec($statement); }
            catch (Throwable $e) {
                fwrite(STDERR, basename($path) . ': ' . $e->getMessage() . "\n  "
                    . substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 160) . "\n");
                exit(2);
            }
        }
    }
}

$insert = function (string $table, array $row) use ($pdo): int {
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES ('
        . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
};

apply_migrations($pdo, '001', $stopAfter);

// --- a portal as it stood before 015, with the cases that matter ------------
$now = gmdate('Y-m-d H:i:s');

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

// --- and now the rest of them -----------------------------------------------
apply_migrations($pdo, sprintf('%03d', (int)$stopAfter + 1), '999');

$fetch = fn(string $sql) => $pdo->query($sql)->fetchAll();
echo json_encode([
    'rates' => $fetch('SELECT tariff_id, interval_months, price_cents FROM tariff_rates ORDER BY tariff_id, interval_months'),
    'templates' => $fetch('SELECT tariff_id, name, months, kind, value FROM tariff_discounts ORDER BY tariff_id'),
    'enrolments' => $fetch('SELECT student_id, tariff_id, interval_months, discount_months, discount_kind,'
        . ' discount_value, discount_note, price_cents FROM class_students ORDER BY student_id'),
    'students' => $fetch('SELECT id, first_name, account_id, email FROM students ORDER BY id'),
    'tariff_columns' => array_column($pdo->query('PRAGMA table_info(tariffs)')->fetchAll(), 'name'),
    'ids' => ['giving' => $giving, 'plain' => $plain,
              'on_account' => $onAccount, 'by_contact' => $byContact, 'neither' => $neither],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
@unlink($file);
