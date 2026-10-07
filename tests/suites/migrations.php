<?php
/**
 * The migrations that move data, run against data.
 *
 * Every other suite gets its database by applying all the migrations to an
 * empty one, so the statements that carry something across have never touched a
 * row: a tariff's price into tariff_rates, its discount onto every enrolment
 * that was getting it, an address onto every child, a login holding three
 * children keeping only one. "Nobody's next invoice changes" and "nobody is
 * signed out" were claims with nothing behind them, and they are the two claims
 * a trainer is trusting with her families' money.
 *
 * tests/migration-data.php builds a portal as it stood before 015, 019, 020, 022,
 * 024, 025 and 028, applies the rest, and prints what it finds. This reads that
 * and holds it to the promise.
 *
 * What it does not yet hold to it: 017 backfills covered_from and covered_to on
 * every charge that carries a period, and no case below looks at a charge. The
 * columns are covered by the billing and invoices suites, on rows this version
 * wrote; the statement that fills in the rows written by the previous one is
 * proven only by its having applied. Said here rather than left to be assumed.
 *
 * It runs in another process against a database of its own, because the one
 * this suite is connected to has every migration applied already, and this has
 * to stop half way. CRM_MIGRATION_CONFIG names the config of that database: an
 * empty one whose name ends in _test. tests/mariadb-local.sh makes it; a run
 * without one - tests/existing-database.sh, where the hosting panel gave one
 * database - says so in the footer rather than glossing over it.
 */

// ---------------------------------------------------------------------------
// First, what the migrations leave behind, on this run's own database. It needs
// neither exec nor a second database, so it runs wherever the suite does -
// tests/existing-database.sh on a hosting provider's server included - and
// only the run with data in between, after it, can be left to the footer.

case_('On this run’s own engine, a new login has news on and status auto, and its history goes with it');
run("INSERT INTO accounts (name, email, role, created_at) VALUES ('Neu', 'neu@example.test', 'student', ?)", [now()]);
$fresh = one("SELECT id, newsletter, presence FROM accounts WHERE email = 'neu@example.test'");
is_same([1, 'auto'], [(int)$fresh['newsletter'], $fresh['presence']], 'news by email on, status auto');
run('INSERT INTO online_periods (account_id, started_at, last_seen_at, hidden) VALUES (?, ?, ?, 0)', [$fresh['id'], now(), now()]);
run('DELETE FROM accounts WHERE id = ?', [$fresh['id']]);
is_same(0, (int)scalar('SELECT COUNT(*) FROM online_periods WHERE account_id = ?', [$fresh['id']]),
        'deleting a login deletes when it was online, rather than leaving periods nobody can be named for');

case_('On this run’s own engine, no two logins share an address');
// On the run's own database, so a run on MariaDB proves this on MariaDB.
// refuse_address_in_use() gives the sentence a person reads; this is the
// database behind it, which refuses under any isolation level (ADR 0020 §1).
make_account(['email' => 'eigene.adresse@example.test']);
$refusal = null;
try { make_account(['email' => 'eigene.adresse@example.test']); } catch (PDOException $e) { $refusal = $e; }
is_same('23000', (string)$refusal?->getCode(), 'a second login on an address that is taken is refused');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['eigene.adresse@example.test']), 'and only the first is there');
does_not_throw(fn() => make_account(['email' => 'eigene.zwei@example.test']),
               'the same login on an address of its own is taken, so what refused it was the address');
$refusal = null;
try { make_account(['email' => 'Eigene.Adresse@Example.TEST']); } catch (PDOException $e) { $refusal = $e; }
is_same('23000', (string)$refusal?->getCode(), 'the taken address in other capitals is refused too, under the tables’ collation');
$refusal = null;
try { make_account(['email' => 'eigene-adresse@example.test']); make_account(['email' => 'eigeneadresse@example.test']); }
catch (PDOException $e) { $refusal = $e; }
is_same(null, $refusal, 'while a dot, a hyphen and nothing at all are three different addresses to it');

case_('On this run’s own engine, a login may have an address, a username, both or neither, and logins without one do not collide [ADR 0023 §2]');
// 022 and 023 left a username column unique at a default of '', which refused
// the second login made without one; 024 dropped it. 028 brings it back with
// NULL as "none", and lets the address be NULL too: a unique index lets any
// number of rows share NULL and nothing else.
$column = fn(string $name): ?array => one('SELECT is_nullable AS nullable, column_type AS type FROM information_schema.columns'
    . " WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = ?", [$name]);
is_same(['nullable' => 'YES', 'type' => 'varchar(30)'], $column('username'), 'accounts has a username column of 30 characters, which may be empty');
is_same(['nullable' => 'YES', 'type' => 'varchar(254)'], $column('email'), 'and its address may be empty too');
is_same([['non_unique' => 0, 'col' => 'username']], array_map(fn($r) => ['non_unique' => (int)$r['non_unique'], 'col' => $r['col']],
    rows("SELECT non_unique, column_name AS col FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'accounts' AND index_name = 'account_username'")),
        'the index account_username keeps a username to one login');
$unnamed = [];
does_not_throw(function () use (&$unnamed) {
    foreach (['ohne.namen.eins@example.test', 'ohne.namen.zwei@example.test'] as $email) {
        run("INSERT INTO accounts (name, email, role, created_at) VALUES ('', ?, 'student', ?)", [$email, now()]);
        $unnamed[] = (int)db()->lastInsertId();
    }
}, 'two logins written the way an invitation by address writes them, naming only name, address, role and time, are both taken');
is_same(2, count(array_filter($unnamed)), 'and both are there');
is_same([null, null], array_column(rows('SELECT username FROM accounts WHERE id IN (?, ?) ORDER BY id', array_pad($unnamed, 2, 0)), 'username'),
        'each with no username, rather than an empty one');
$neither = [];
does_not_throw(function () use (&$neither) {
    foreach (['Platzhalter Eins', 'Platzhalter Zwei'] as $name) {
        run("INSERT INTO accounts (name, role, state, created_at) VALUES (?, 'student', 'placeholder', ?)", [$name, now()]);
        $neither[] = (int)db()->lastInsertId();
    }
}, 'two logins with neither an address nor a username are both taken');
is_same([[null, null], [null, null]], array_map(fn($r) => [$r['email'], $r['username']],
    rows('SELECT email, username FROM accounts WHERE id IN (?, ?) ORDER BY id', array_pad($neither, 2, 0))), 'and both have NULL for each');
$refusal = null;
try {
    make_account(['email' => null, 'username' => 'lena.eigen']);
    make_account(['email' => null, 'username' => 'Lena.Eigen']);
} catch (PDOException $e) { $refusal = $e; }
is_same('23000', (string)$refusal?->getCode(), 'a username taken in other capitals is refused, under the tables’ collation');
does_not_throw(fn() => make_account(['email' => null, 'username' => 'lena.eigen2']), 'while one of its own is taken, so what refused it was the username');

case_('On this run’s own engine, a locking read also locks the gap where a row would go [R8]');
// refuse_address_in_use() reads FOR UPDATE and relies on REPEATABLE READ
// locking the gap a missing row would fill; under
// READ COMMITTED two requests could both see "free" and both write, and the
// second would meet the unique index's 23000 rather than a sentence. The
// portal never changes the level, so this checks the server's own - here,
// not in tests/mariadb-local.sh, so that tests/existing-database.sh asks a
// hosting provider's server too. The variable has two names: MySQL 8.0 knows
// only transaction_isolation, MariaDB 10.11 only tx_isolation.
$isolation = null;
foreach (['@@transaction_isolation', '@@tx_isolation'] as $variable) {
    try { $isolation = (string)scalar('SELECT ' . $variable); break; }
    catch (PDOException) { /* this engine's other name */ }
}
is_same('REPEATABLE-READ', $isolation, 'the connection the portal opens reads at REPEATABLE READ');

case_('On this run’s own engine, the runner’s step leaves every login as it was');
// What runs after every update's migrations is database/defaults.php, required
// here the way schema_apply() requires it, and in a scope of its own so its loop
// variables do not land in this file's. Up to 023 it gave out usernames; now it
// writes to no login, a set-up one or an invitation (ADR 0021 §1).
// migration-data.php runs it on a portal 024 was applied to.
$runnerStep = static function (): void { require ROOT . '/database/defaults.php'; setting_cache_clear(); };
$setUpTrainer = make_account(['role' => 'trainer', 'name' => 'Trainerin Benannt', 'email' => 'trainerin.benannt@example.test']);
$invitation = make_account(['role' => 'student', 'name' => '', 'email' => 'eingeladen@example.test',
                            'state' => 'invited', 'password_hash' => null, 'verified_at' => null]);
$pairOf = fn() => rows('SELECT * FROM accounts WHERE id IN (?, ?) ORDER BY id', [$setUpTrainer, $invitation]);
$pairBefore = $pairOf();
$runnerStep();
is_same($pairBefore, $pairOf(), 'both logins have every value they had, the invitation’s empty name included');

case_('After the runner the sign-in comparison hash exists at today’s cost, and a request refreshes nothing [R9]');
// Hashing is slower than verifying, so a sign-in that hashed would say by its
// time whether an address has a login. The runner and the nightly
// prune make and refresh the hash; a request may only repair a missing one
// (security.php).
run("DELETE FROM settings WHERE setting_key='sign_in_dummy_hash'"); setting_cache_clear();
$runnerStep();
$dummy = (string)setting('sign_in_dummy_hash');
is_same(PASSWORD_DEFAULT, password_get_info($dummy)['algo'], 'the runner made one, with PASSWORD_DEFAULT');
ok(!password_needs_rehash($dummy, PASSWORD_DEFAULT), 'at today’s cost');
$runnerStep();
is_same($dummy, (string)setting('sign_in_dummy_hash'), 'the next update keeps a current one rather than hashing again');
$outdated = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
set_setting('sign_in_dummy_hash', $outdated);
throws(fn() => submit('login', ['email' => 'niemand@hier.test', 'password' => 'falsch']), 'a sign-in with no such address is refused', 'Anmeldung nicht möglich');
setting_cache_clear();
is_same($outdated, (string)setting('sign_in_dummy_hash'), 'and leaves even an outdated hash alone: a request refreshes nothing');
throttle_clear('login', address_identity('niemand@hier.test'));
$runnerStep();
ok(!password_needs_rehash((string)setting('sign_in_dummy_hash'), PASSWORD_DEFAULT), 'the next update brings that one to today’s cost');

case_('One login cannot hold a second child, whatever code tries it');
// On the run's own database, so a run on MariaDB proves this on MariaDB.
$login = make_account();
$first = make_student(['account_id' => $login, 'email' => 'a@example.test']);
$refusal = null;
try { make_student(['account_id' => $login]); } catch (PDOException $e) { $refusal = $e; }
is_same('23000', (string)$refusal?->getCode(), 'a second child written onto the login is refused by the database');
$second = make_student();
$refusal = null;
try { run('UPDATE students SET account_id=? WHERE id=?', [$login, $second]); } catch (PDOException $e) { $refusal = $e; }
is_same('23000', (string)$refusal?->getCode(), 'and so is moving a child onto a login that is taken');
is_same([$first], array_map('intval', array_column(rows('SELECT id FROM students WHERE account_id=?', [$login]), 'id')),
        'the login still holds its one child');
// The database still takes a child written without a login: the code that makes
// a student and the update's step hold that rule, because a NOT NULL would meet
// the students the step has not reached yet and stop the update (ADR 0023 §4).
does_not_throw(function () { foreach (range(1, 3) as $_) make_student(['account_id' => null]); },
               'any number of children can be written with no login, and the unique index lets them');
$refusal = null;
try { run('DELETE FROM accounts WHERE id=?', [$login]); } catch (PDOException $e) { $refusal = $e; }
is_same(['23000', 1451], [(string)$refusal?->getCode(), (int)($refusal?->errorInfo[1] ?? 0)],
        'deleting the login a child points to is refused by the database, where it used to leave the child without one [ADR 0023 §4]');
is_same($login, (int)scalar('SELECT account_id FROM students WHERE id=?', [$first]), 'the child keeps their login');
does_not_throw(function () use ($first, $login) {
    run('DELETE FROM students WHERE id=?', [$first]);
    run('DELETE FROM accounts WHERE id=?', [$login]);
}, 'once the child is gone, their login can go');

case_('On this run’s own engine, the one key on a student’s login is student_login, and it refuses [ADR 0023 §4]');
// 029 drops the key 001 made, which emptied account_id when a login went; 030
// adds it back as RESTRICT under a name of its own.
is_same([['name' => 'student_login', 'refers_to' => 'accounts', 'on_delete' => 'RESTRICT']],
        rows('SELECT k.constraint_name AS name, k.referenced_table_name AS refers_to, r.delete_rule AS on_delete'
           . ' FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r'
           . ' ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name'
           . " WHERE k.table_schema = DATABASE() AND k.table_name = 'students' AND k.column_name = 'account_id'"
           . ' AND k.referenced_table_name IS NOT NULL'),
        'students.account_id has one key, student_login, to accounts, ON DELETE RESTRICT, and the one that emptied it is gone');
$refusal = null;
try { make_student(['account_id' => 987654321]); } catch (PDOException $e) { $refusal = $e; }
is_same(1452, (int)($refusal?->errorInfo[1] ?? 0), 'and a child cannot be pointed at a login that does not exist');

case_('On this run’s own engine, an enrolment written without naming removed_on is not removed [ADR 0024 §1]');
$column = one("SELECT is_nullable AS nullable, data_type AS type, column_default AS default_value FROM information_schema.columns"
    . " WHERE table_schema = DATABASE() AND table_name = 'class_students' AND column_name = 'removed_on'");
// MariaDB reports a default of NULL as the text 'NULL', MySQL 8.0 as NULL.
is_same(['nullable' => 'YES', 'type' => 'date', 'default_value' => null],
        $column === null ? null : ['nullable' => $column['nullable'], 'type' => $column['type'],
            'default_value' => strtoupper((string)$column['default_value']) === 'NULL' ? null : $column['default_value']],
        'class_students has removed_on, a calendar date that may be empty and is empty unless set');
$course = make_class();
make_enrolment($course, $enrolled = make_student());
is_same(null, scalar('SELECT removed_on FROM class_students WHERE class_id=? AND student_id=?', [$course, $enrolled]),
        'an enrolment written the way the previous version wrote one is not removed');
run('UPDATE class_students SET removed_on=? WHERE class_id=? AND student_id=?', ['2026-10-06', $course, $enrolled]);
is_same('2026-10-06', scalar('SELECT removed_on FROM class_students WHERE class_id=? AND student_id=?', [$course, $enrolled]),
        'and one that was removed keeps the calendar day, unshifted');

case_('On this run’s own engine, the runner’s step gives every student without a login a placeholder, and nobody else anything [ADR 0023 §4]');
$keeps = make_student(['first_name' => 'Hat', 'last_name' => 'Zugang', 'account_id' => $hasLogin = make_account(['name' => 'Hat Zugang'])]);
// Students the previous version wrote, without a login: make_student() gives
// every other one the placeholder it has from its first moment.
$gets = make_student(['first_name' => 'Jürgen', 'last_name' => 'Groß-Özdemir', 'email' => 'eltern@example.test', 'account_id' => null]);
$sibling = make_student(['first_name' => 'Anna', 'last_name' => 'Groß-Özdemir', 'email' => 'eltern@example.test', 'account_id' => null]);
$example = make_student(['first_name' => 'Beispiel', 'last_name' => 'Kind', 'is_demo' => 1, 'account_id' => null]);
$studentsBefore = rows('SELECT id, account_id, revision FROM students ORDER BY id');
$accountsBefore = rows('SELECT * FROM accounts ORDER BY id');
$runnerStep();
$after = array_column(rows('SELECT id, account_id, revision FROM students ORDER BY id'), null, 'id');
is_same(0, (int)scalar('SELECT COUNT(*) FROM students WHERE account_id IS NULL'), 'afterwards no student is without a login');
is_same($hasLogin, (int)$after[$keeps]['account_id'], 'a student who had a login keeps it');
// The placeholders the step made: those make_student() gave earlier students are older.
$placeholders = rows("SELECT a.*, s.id AS student_id FROM accounts a JOIN students s ON s.account_id = a.id WHERE a.state = 'placeholder' AND a.id > ? ORDER BY s.id",
                     [(int)end($accountsBefore)['id']]);
is_same(count(array_filter($studentsBefore, fn($s) => $s['account_id'] === null)), count($placeholders),
        'one placeholder for each student who had none');
is_same(count($accountsBefore) + count($placeholders), (int)scalar('SELECT COUNT(*) FROM accounts'), 'and not one login more');
$of = array_column($placeholders, null, 'student_id');
is_same(['Jürgen Groß-Özdemir', null, null, null, null, 'student', 'placeholder', 0],
        [$of[$gets]['name'], $of[$gets]['email'], $of[$gets]['username'], $of[$gets]['password_hash'], $of[$gets]['verified_at'],
         $of[$gets]['role'], $of[$gets]['state'], (int)$of[$gets]['is_demo']],
        'named after the child, with no address, no username, no password, not set up, a student’s, a placeholder');
ok(isset($of[$sibling]) && $of[$sibling]['email'] === null && (int)$of[$sibling]['id'] !== (int)$of[$gets]['id'],
   'a sister on the same parent’s address gets a placeholder of her own, which no more carries the address than her brother’s');
is_same(1, (int)($of[$example]['is_demo'] ?? 0), 'an example student’s placeholder is example data, for demo_clear() to take with them');
$revisionOf = array_column($studentsBefore, 'revision', 'id');
is_same((int)$revisionOf[$gets] + 1, (int)$after[$gets]['revision'], 'a student given a login has their revision raised, as invite_student() raises it');
is_same((int)$revisionOf[$keeps], (int)$after[$keeps]['revision'], 'and one who had a login has not');
is_same($accountsBefore, rows('SELECT * FROM accounts WHERE id <= ? ORDER BY id', [(int)end($accountsBefore)['id']]),
        'every login that was there has every value it had');
$again = [rows('SELECT * FROM accounts ORDER BY id'), rows('SELECT * FROM students ORDER BY id')];
$runnerStep();
is_same($again, [rows('SELECT * FROM accounts ORDER BY id'), rows('SELECT * FROM students ORDER BY id')],
        'run again, as the next update runs it, it changes nothing');

case_('The functions 019 calls behave on this engine as 019 needs them to');
// 019 builds its change-log lines and compares addresses with these. A CONCAT
// that gave '' for a NULL part, or a JSON_OBJECT that lost the null, would write
// a line that reads as a value nobody had.
is_same('Mia Gruber', scalar("SELECT CONCAT('Mia', ' ', 'Gruber')"), 'CONCAT joins');
is_same(null, scalar("SELECT CONCAT('Mia', NULL)"), 'and gives NULL when any part is NULL, as MySQL does');
is_same(['account_id' => null], json_decode((string)scalar("SELECT JSON_OBJECT('account_id', NULL)"), true),
        'JSON_OBJECT writes a NULL as null');
is_same(['email' => 'sära@beispiel.test'], json_decode((string)scalar("SELECT JSON_OBJECT('email', ?)", ['sära@beispiel.test']), true),
        'and keeps an umlaut intact');
is_same('C3A4', scalar('SELECT HEX(?)', ['ä']), 'HEX gives the bytes of the text, which is how 019 compares addresses');
ok(abs(strtotime((string)scalar('SELECT UTC_TIMESTAMP()') . ' UTC') - time()) < 60, 'UTC_TIMESTAMP is now, in UTC');

case_('A line 019 writes reads sensibly on the history page');
// Written with the migration's own functions on this run's engine, whose
// JSON_OBJECT spells its output its own way (MariaDB puts a space after the
// colon), and read back through the real page.
$staff = make_account(['role' => 'admin', 'name' => 'Trainerin']);
$taken = make_student(['first_name' => 'Mia', 'last_name' => 'Gruber']);
$keeper = make_account(['name' => 'Familie Gruber']);
run("INSERT INTO record_versions (entity, entity_id, operation, label, before_json, after_json, actor_id, created_at)"
    . " VALUES ('students', ?, 'update', 'Mia Gruber', JSON_OBJECT('account_id', ?), JSON_OBJECT('account_id', NULL), NULL, UTC_TIMESTAMP())",
    [$taken, $keeper]);
sign_in_as($staff);
$page = render_view('history', ['entity' => 'students', 'record' => (string)$taken]);
sign_out();
ok(str_contains($page, 'Mia Gruber'), 'it names the child');
ok(str_contains($page, e(t('automatisch', 'automatically'))), 'says it was done automatically, since nobody did it');
ok(str_contains($page, e(history_field_label('account_id'))), 'names the field in her words, not as a column');
$readable = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($page), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
ok(str_contains($readable, history_field_label('account_id') . ' ' . history_value($keeper, 'account_id') . ' → ' . history_value(null)),
   'and shows which login it was, and that there is none now');

// ---------------------------------------------------------------------------
// Then the run with data in between, in a process and a database of its own.

if (!function_exists('exec')) {
    // Shared hosting often lists exec in disable_functions. The run says what it
    // could not do rather than stopping on an undefined function.
    test_unsupported(array_merge(test_unsupported(),
        ['the data moved or kept by migrations 015, 016 and 019 to 031 (this PHP disables exec, which the run with data in between needs)']));
    return;
}
$target = (string)getenv('CRM_MIGRATION_CONFIG');
if ($target === '') {
    test_unsupported(array_merge(test_unsupported(),
        ['the data moved or kept by migrations 015, 016 and 019 to 031 (set CRM_MIGRATION_CONFIG to the config of a'
         . ' second, empty *_test database; tests/mariadb-local.sh does)']));
    return;
}
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tests/migration-data.php') . ' '
    . escapeshellarg($target) . ' 2>&1', $out, $code);
$raw = implode("\n", $out);
is_same(0, $code, 'the migrations apply with data written in between');
$after = json_decode($raw, true);
if (!is_array($after)) {
    ok(false, 'the run with data in between printed a result: ' . mb_substr($raw, 0, 300));
    return;
}

$ids = $after['ids'];
$by = fn(array $rows, string $key, int $id) => array_values(array_filter($rows, fn($r) => (int)$r[$key] === $id));

// ---------------------------------------------------------------------------
case_('015 turns every tariff price into the first of its rates');
is_same(2, count($after['rates']), 'one rate per tariff, and not one more');
is_same([['tariff_id' => $ids['giving'], 'interval_months' => 1, 'price_cents' => 4500]],
        $by($after['rates'], 'tariff_id', $ids['giving']), 'the monthly one at its monthly price');
is_same([['tariff_id' => $ids['plain'], 'interval_months' => 6, 'price_cents' => 24000]],
        $by($after['rates'], 'tariff_id', $ids['plain']), 'and the half-yearly one at its own interval');
// If this were wrong nobody would notice until the next billing run charged
// everybody the wrong amount, so it is worth saying out loud.
foreach ($after['rates'] as $rate)
    ok((int)$rate['price_cents'] > 0, 'no tariff came out of the migration free');

case_('And the welcome discount becomes a template, only where there was one');
is_same(1, count($after['templates']), 'one template, from the one tariff that was giving a discount');
$template = $after['templates'][0];
is_same($ids['giving'], (int)$template['tariff_id'], 'on that tariff');
is_same(3, (int)$template['months'], 'for the three months it ran');
is_same('percent', $template['kind'], 'in the shape it had');
is_same(30, (int)$template['value'], 'at the size it was');
is_same([], $by($after['templates'], 'tariff_id', $ids['plain']), 'and a tariff giving nothing gets no template');

case_('Every family already getting a discount keeps it, to the cent');
$enrolments = [];
foreach ($after['enrolments'] as $row) $enrolments[(int)$row['student_id']] = $row;
$kept = $enrolments[$ids['on_account']];
is_same(3, (int)$kept['discount_months'], 'the same three months');
is_same('percent', $kept['discount_kind'], 'the same shape');
is_same(30, (int)$kept['discount_value'], 'the same thirty per cent');
is_same('Willkommensrabatt', $kept['discount_note'], 'under a name that can go on an invoice');
is_same(0, (int)$kept['interval_months'], 'and on the tariff’s usual interval, which is what it was on before');

case_('And nobody else is given one by an UPDATE that forgot its WHERE');
$untouched = $enrolments[$ids['by_contact']];
is_same(0, (int)$untouched['discount_months'], 'a family on a tariff that gave nothing gets nothing');
is_same(0, (int)$untouched['discount_value'], 'at no value');
is_same('', $untouched['discount_note'], 'and no name');
$noTariff = $enrolments[$ids['neither']];
is_same(0, (int)$noTariff['discount_months'], 'nor does an enrolment on no tariff at all');
is_same('', $noTariff['discount_note'], 'which would otherwise be given a name for a gift it never got');
is_same(3000, (int)$noTariff['price_cents'], 'whose agreed price of its own is still there');

case_('The columns the code no longer reads are gone');
// Left behind they would be a second answer waiting to be believed: the rows
// above are the first, and two answers is how the wrong one gets used.
foreach (['price_cents', 'discount_months', 'discount_kind', 'discount_value'] as $dropped)
    ok(!in_array($dropped, $after['tariff_columns'], true), 'tariffs.'.$dropped.' was dropped');
foreach (['name', 'interval_months', 'due_day', 'grace_days', 'first_period'] as $kept)
    ok(in_array($kept, $after['tariff_columns'], true), 'and tariffs.'.$kept.' was not');

// ---------------------------------------------------------------------------
case_('016 fills in where the portal was already writing to each child');
$students = [];
foreach ($after['students'] as $row) $students[(int)$row['id']] = $row;
// The account wins over the contact: that is the address they actually sign in
// with, and a contact's address would have been a second one nobody chose.
is_same('familie@beispiel.test', $students[$ids['on_account']]['email'],
        'a child on an account gets the account’s address, not their grandmother’s');
is_same('maria@beispiel.test', $students[$ids['by_contact']]['email'],
        'a child with no account gets the standard contact’s');
ok($students[$ids['by_contact']]['email'] !== 'opa@beispiel.test',
   'and not the other contact’s, which is on the same child and is not the standard one');
is_same('', $students[$ids['neither']]['email'],
        'and a child with nowhere to write comes out empty rather than wrong');

case_('Nobody is signed out by it');
is_same($ids['on_account'], (int)$students[$ids['on_account']]['id'], 'the child is still there');
ok($students[$ids['on_account']]['account_id'] !== null, 'still on their account');
is_same(null, $students[$ids['by_contact']]['account_id'], 'and one who had none still has none');

// ---------------------------------------------------------------------------
// 019: one login is one member. Read against the state straight after it, so a
// later migration that touches students cannot make these pass or fail.
$n = $after['nineteen'];
$id = fn(string $key): int => (int)$ids[$key];
$was = array_column($n['before']['students'], null, 'id');
$now = array_column($n['after']['students'], null, 'id');
$loginOf = fn(string $key): ?int => $now[$id($key)]['account_id'] === null ? null : (int)$now[$id($key)]['account_id'];
$json = fn(?string $text) => $text === null ? null : json_decode($text, true);
// The lines 019 wrote are the ones that were not there before it ran.
$written = array_slice($n['after']['versions'], count($n['before']['versions']));
$linesFor = fn(int $studentId): array => array_values(array_filter($written, fn($v) => (int)$v['entity_id'] === $studentId));
$children = ['on_account', 'by_contact', 'neither', 'paul', 'emma', 'mia', 'jonas', 'lisa', 'jakob', 'sara', 'ida'];

case_('019 leaves each login with the child whose record is oldest');
is_same($id('gruber'), $loginOf('paul'), 'Paul, the lowest id on the Gruber login, keeps it though he was not written first');
is_same(null, $loginOf('mia'), 'Mia, who was written first, is taken off it');
is_same(null, $loginOf('emma'), 'and so is Emma');
is_same($id('huber'), $loginOf('jonas'), 'on a login with two, the lower id keeps it');
is_same(null, $loginOf('lisa'), 'and the other is taken off');
is_same($id('novak'), $loginOf('jakob'), 'a login with one child keeps that child');
is_same($id('weiss'), $loginOf('sara'), 'whatever address the child had');
is_same($id('hofer'), $loginOf('on_account'), 'as does the child from before 015');
is_same(null, $loginOf('ida'), 'and a child with no login is not given one');
$perLogin = array_count_values(array_map('intval', array_filter(array_column($n['after']['students'], 'account_id'),
    fn($account) => $account !== null)));
is_same([], array_filter($perLogin, fn($count) => $count > 1), 'no login holds two children afterwards');

case_('019 writes down which login each child was taken off');
foreach (['mia', 'emma', 'lisa'] as $key) {
    $lines = $linesFor($id($key));
    is_same(1, count($lines), $key . ' has exactly one line');
    $line = $lines[0] ?? [];
    is_same(['account_id' => (int)$was[$id($key)]['account_id']], $json($line['before_json'] ?? null),
            $key . '’s line says which login it was');
    is_same(['account_id' => null], $json($line['after_json'] ?? null), 'and that there is none now');
    is_same(['students', 'update'], [$line['entity'] ?? null, $line['operation'] ?? null],
            'as a change to the child, which is where the history page looks');
    ok(array_key_exists('actor_id', $line) && $line['actor_id'] === null,
       'with nobody named as having done it, so the page says "automatisch"');
}
is_same('Mia Gruber', $linesFor($id('mia'))[0]['label'] ?? null, 'the line is labelled with the child’s name');
$emma = $was[$id('emma')];
is_same(mb_substr($emma['first_name'] . ' ' . $emma['last_name'], 0, 160), $linesFor($id('emma'))[0]['label'] ?? null,
        'a name longer than the label is cut the way the portal cuts it, where a strict server would refuse it');
foreach ($written as $line)
    ok(abs(strtotime($line['created_at'] . ' UTC') - time()) < 600,
       'each line is dated with the time of the update, in UTC: ' . $line['created_at']);
is_same($n['before']['versions'][0] ?? null, $n['after']['versions'][0] ?? null,
        'a line the portal wrote about Mia earlier, with no actor either, is left as it was and not taken for 019’s own');
is_same([], array_values(array_filter(array_merge($linesFor($id('paul')), $linesFor($id('jonas'))),
    fn($v) => array_key_exists('account_id', $json($v['before_json']) ?? []))),
        'a child who keeps the login has no line saying it was taken off');
is_same([], $linesFor($id('jakob')), 'a child with nothing to change has no line at all');
is_same([], $linesFor($id('ida')), 'nor has a child with no login');
is_same(6, count($written), 'three taken off and three addresses copied, and not one line more');

case_('019 deletes nothing');
is_same($n['before']['counts'], $n['after']['counts'],
        'every table an update must not lose a row of has as many rows as before, so the guard stays as it is');
ok(($n['before']['counts']['charges'] ?? 0) > 0 && ($n['before']['counts']['payments'] ?? 0) > 0,
   'and there was a charge and a payment on a child taken off a login, to lose');
foreach (['mia', 'emma', 'lisa'] as $key)
    is_same($was[$id($key)]['email'], $now[$id($key)]['email'], $key . ' keeps the address on her record, for a login of her own');

case_('019 gives a child who keeps a login that login’s address, to the letter');
$loginAddress = ['paul' => 'gruber@beispiel.test', 'jonas' => 'huber@beispiel.test', 'sara' => 'weiss@beispiel.test',
                 'jakob' => 'novak@beispiel.test', 'on_account' => 'familie@beispiel.test'];
foreach ($loginAddress as $key => $email) is_same($email, $now[$id($key)]['email'], $key . ' has the login’s address');
is_same('Gruber@Beispiel.test', $was[$id('paul')]['email'],
        'Paul’s differed only in its capitals, which the tables’ collation calls equal');
foreach (['paul', 'jonas', 'sara'] as $key) {
    $lines = $linesFor($id($key));
    is_same(1, count($lines), $key . ' has one line for the address');
    is_same(['email' => $was[$id($key)]['email']], $json($lines[0]['before_json'] ?? null), 'saying what it was');
    is_same(['email' => $loginAddress[$key]], $json($lines[0]['after_json'] ?? null), 'and what it is now');
}
is_same('novak@beispiel.test', $now[$id('ida')]['email'], 'a child with no login keeps her address, though it is a login’s');

case_('019 raises the revision of every child it changed, once, and of no other');
// student_save refuses a revision it did not load, so this is what stops a form
// left open during the update from writing the old login or address back.
$changed = ['paul', 'emma', 'mia', 'jonas', 'lisa', 'sara'];
foreach ($children as $key) {
    $bump = in_array($key, $changed, true) ? 1 : 0;
    is_same((int)$was[$id($key)]['revision'] + $bump, (int)$now[$id($key)]['revision'],
            $key . ($bump ? ' was changed, and its revision says so' : ' was not touched'));
    if (!$bump) is_same($was[$id($key)]['updated_at'], $now[$id($key)]['updated_at'], 'nor its updated_at');
}

case_('019 puts the rule in the database');
is_same(['exists' => true, 'unique' => true, 'columns' => ['account_id']], $n['index'],
        'a unique index on the login a child belongs to');

case_('An update of 019 that stopped partway and started again writes nothing twice');
// That is what the next page view does after an interrupted update: it starts
// the file again from the first statement. Times are left out of the comparison,
// because each run writes its own.
$without = fn(array $rows, string $column) => array_map(fn($row) => array_diff_key($row, [$column => 1]), $rows);
is_same($n['statements'] - 1, count($n['retried']), 'it was stopped after each statement but the last');
foreach ($n['retried'] as $stopped => $state) {
    $when = 'stopped after statement ' . $stopped . ' of ' . $n['statements'] . ' and run from the first: ';
    is_same($without($n['after']['students'], 'updated_at'), $without($state['students'], 'updated_at'),
            $when . 'every child ends as it does in one run, with its revision raised once');
    is_same($without($n['after']['versions'], 'created_at'), $without($state['versions'], 'created_at'),
            $when . 'the change log has each line once');
    is_same($n['after']['counts'], $state['counts'], $when . 'the same number of rows everywhere');
    is_same($n['index'], $state['index'], $when . 'the same index');
}

case_('019 run again after it finished changes nothing');
is_same($n['after'], $n['again'], 'the same children, revisions, times, lines and counts');

// ---------------------------------------------------------------------------
// 020 and 021: a status and thirty days of online history, and news by email on
// for a new login. Neither moves data; what is held to here is that neither
// changes any, on logins the previous version wrote.
$w = $after['twenty'];
$newsOf = fn(array $rows): array => array_combine(array_map('intval', array_column($rows, 'id')),
                                                  array_map('intval', array_column($rows, 'newsletter')));
$hadIt = $newsOf($w['before']['accounts']);
$hasIt = $newsOf($w['after']['accounts']);

case_('021 leaves every existing login’s news-by-email choice as it was');
ok(in_array(0, $hadIt, true) && in_array(1, $hadIt, true),
   'there were logins with it off and with it on before the update, for it to change');
is_same($hadIt, $hasIt, 'each keeps the choice it had');
is_same([], array_keys(array_filter($hasIt, fn($now, $id) => $now === 1 && $hadIt[$id] === 0, ARRAY_FILTER_USE_BOTH)),
        'and not one login that had it off is signed up by the update');

case_('A login written after 021 without naming it has news by email on');
is_same(1, (int)$w['after']['new_login']['newsletter'], 'news by email is on');

case_('020 gives every login a status that shows what it showed before');
is_same(array_fill(0, count($w['after']['accounts']), 'auto'), array_column($w['after']['accounts'], 'presence'),
        'every existing login is auto, and none is left NULL');
is_same('auto', $w['after']['new_login']['presence'], 'and so is a new one');

case_('020 adds the online history empty, and removes nothing');
is_same(['id', 'account_id', 'started_at', 'last_seen_at', 'hidden'], $w['after']['online_periods'],
        'online_periods has the columns ADR 0015 names');
is_same(0, $w['after']['online_period_rows'], 'with no history made up for anybody');
is_same($w['before']['counts'], $w['after']['counts'], 'every guarded table has as many rows as before');
ok(!in_array('online_periods', schema_guarded_tables(), true),
   'online_periods is left off the guard: the nightly prune empties it by design, and an update must not stay closed over that');
$indexes = $w['after']['indexes'];
ksort($indexes);
is_same(['PRIMARY' => ['id'], 'online_period_age' => ['last_seen_at'], 'online_period_of_account' => ['account_id', 'last_seen_at']],
        $indexes, 'with an index for one account’s periods and one for the prune, and no other');

case_('020 stopped partway and started again, and 021 run twice, end as one run does');
is_same($w['statements'] - 1, count($w['retried']), 'it was stopped after each statement but the last');
foreach ($w['retried'] as $stopped => $state) {
    $when = 'stopped after statement ' . $stopped . ' of ' . $w['statements'] . ' and run from the first: ';
    is_same($w['after']['accounts'], $state['accounts'], $when . 'every login has the choice and status of one run');
    is_same($w['after']['online_periods'], $state['online_periods'], $when . 'the same history table');
    is_same($w['after']['new_login'], $state['new_login'], $when . 'and a new login the same defaults');
}

// ---------------------------------------------------------------------------
// 022, 023 and 024: usernames came with 022 and 023 (ADR 0019, kept by 0020) and
// go with 024 (ADR 0021). A portal still on 021 runs all three in one update; one
// that ran 022 and 023 already runs 024 alone. Both must keep every login.
$u = $after['usernames'];
$f = $after['twentyfour'];
$byId = fn(array $rows): array => array_column($rows, null, 'id');

case_('022, 023 and 024 bring every login from before usernames through, value for value');
ok(count($u['before']['accounts']) >= 10, 'there were logins to keep: ' . count($u['before']['accounts']));
is_same($u['before']['accounts'], $u['after']['accounts'],
        'every login, with every value it had, its password and address included, and none more');
is_same($u['before']['columns'], $u['after']['columns'], 'accounts has the columns it had, in their order: what 022 added, 024 took away');
is_same($u['before']['indexes'], $u['after']['indexes'], 'and the indexes it had, the address’s included');
is_same($u['before']['counts'], $u['after']['counts'], 'every guarded table has as many rows as before');

case_('023 stopped partway and started again, then 024, ends as one run does');
is_same($u['statements'], count($u['retried']), 'stopped after each statement but the last, and once with its UPDATE run again after it finished');
// Each round builds its portal afresh, and the login from before 015 is dated
// the moment it was written, so times are left out as they are for 019.
$undated = fn(array $rows) => array_map(fn($row) => array_diff_key($row, ['created_at' => 1, 'verified_at' => 1]), $rows);
foreach ($u['retried'] as $stopped => $state) {
    $when = $stopped < $u['statements']
        ? 'stopped after statement ' . $stopped . ' of ' . $u['statements'] . ' and run from the first, then 024: '
        : 'its UPDATE run again after the file finished, then 024: ';
    is_same($undated($u['after']['accounts']), $undated($state['accounts']), $when . 'every login has the values of one run');
    is_same($u['after']['indexes'], $state['indexes'], $when . 'the same indexes');
}

case_('024 keeps every login the previous version wrote, with every value but its username [ADR 0021 §1]');
$was = $byId($f['before']['accounts']);
$is = $byId($f['after']['accounts']);
ok(count($was) >= 12, 'there were logins to keep: ' . count($was));
$held = array_column($f['before']['accounts'], 'username');
ok(count(array_filter($held, fn($name) => $name !== '' && $name[0] !== '#')) >= 8 && in_array('#' . $f['logins']['orphan'], $held, true),
   'they had usernames to lose, and one was still at its placeholder');
is_same(array_keys($was), array_keys($is), 'the same logins afterwards, none lost and none added');
foreach ($was as $loginId => $row)
    is_same(array_diff_key($row, ['username' => 1]), $is[$loginId] ?? null,
            'login ' . $loginId . ' (' . $row['email'] . ') has every value it had but the username');
$facts = fn(array $rows): array => array_map(fn($row) => [$row['email'], $row['password_hash'], $row['state'], $row['role']], $rows);
is_same($facts($was), $facts($is), 'every address, password hash, state and role, to the byte');
$invitation = (int)$f['logins']['invitation'];
is_same('invited', $is[$invitation]['state'] ?? null, 'an invitation never taken up is still one');
// array_key_exists rather than ??, which would read the NULL it is looking for as "missing".
ok(array_key_exists('password_hash', $is[$invitation] ?? []) && $is[$invitation]['password_hash'] === null, 'with no password');
is_same($f['before']['tokens'], $f['after']['tokens'], 'and its link still opens: every sign-in link is as it was');
is_same('suspended', $is[(int)$f['logins']['suspended']]['state'] ?? null, 'a suspended trainer stays suspended');
is_same($f['before']['versions'], $f['after']['versions'],
        'a change-log line about a username change is kept as written, for the history page’s „Benutzername“ label');

case_('024 removes no row, so the update’s guard stays as it is');
is_same($f['before']['counts'], $f['after']['counts'], 'every table in schema_guarded_tables() has as many rows as before');
ok(($f['before']['counts']['accounts'] ?? 0) > 0 && ($f['before']['counts']['students'] ?? 0) > 0, 'and there were logins and students to lose');
ok(in_array('accounts', schema_guarded_tables(), true), 'accounts is guarded, so a lost login would have kept the portal closed');

case_('024 drops the username column and its index, and nothing else');
ok(in_array('username', $f['before']['columns'], true), 'accounts had a username column before 024');
is_same(array_values(array_diff($f['before']['columns'], ['username'])), $f['after']['columns'],
        'afterwards it has every other column, in their order, and that one not');
is_same(['unique' => true, 'columns' => ['username']], $f['before']['indexes']['account_username'] ?? null,
        'before 024 account_username made the username unique');
$kept = array_diff_key($f['before']['indexes'], ['account_username' => 1]);
is_same($kept, $f['after']['indexes'], 'afterwards every other index is as it was, and that one is gone');
is_same([], array_keys(array_filter($f['after']['indexes'], fn($index) => in_array('username', $index['columns'], true))),
        'no index mentions a username');

case_('After 024 two logins can be made without a username, where before it the second was refused');
// The negative first: the same two writes on the same portal before 024 meet the
// username unique at '', which shows the check is looking at the right thing.
is_same([null, '23000'], $f['before']['two_new'], 'before 024 the first was taken and the second refused');
is_same([null, null], $f['after']['two_new'], 'after it both are taken');

case_('After 024 the address is still one login’s [0020 §1]');
is_same(['unique' => true, 'columns' => ['email']], $f['after']['indexes']['email'] ?? null,
        'the unique index 001 put on the address is still there, under its own name');
is_same('23000', $f['same_address'], 'a second login on the address of a login 024 carried through is refused');
is_same('23000', $f['address_other_case'], 'in other capitals too, under the tables’ collation');
is_same('23000', $f['legacy_address_taken'], 'and on the legacy quoted address, which is its login’s alone as well [R2]');
is_same(null, $f['own_address'], 'while the same write on an address of its own is taken: what refused the others was the address');
is_same('"familie..alt"@beispiel.test', $is[(int)$f['logins']['legacy']]['email'] ?? null,
        'and that legacy address is the same, byte for byte');

case_('024 run a second time is refused by the engine and changes nothing');
// An update that applied the file but stopped before the ledger recorded it
// starts the file again on the next page view. MySQL 8.0 has no DROP COLUMN IF
// EXISTS, so that run cannot pass; what matters is that it stops there, with the
// portal closed and the backup in place, and loses nothing.
ok($f['again']['refused'] !== null, 'the engine refuses it: SQLSTATE ' . var_export($f['again']['refused'], true));
is_same(array_diff_key($f['after'], ['two_new' => 1]), $f['again']['state'],
        'and every login, column, index, count, link and change-log line is as one run left them');

case_('After 024 the runner’s step runs without a username, changes no login it finds, and mails nobody');
// Up to 023 database/defaults.php gave out usernames. Still calling that after
// 024 would fail on every request and keep the portal closed (ADR 0021 §1).
// Since 025 it also gives every course its group chat (ADR 0022), and since 028
// every student without a login a placeholder (ADR 0023 §4), which the case on
// 028 to 031 below holds to in detail.
$r = $f['runner'];
is_same('', $r['first_error'], 'database/defaults.php runs on the portal 024 and the files after it were applied to');
$kept = array_slice($r['first']['accounts'], 0, count($r['before']['accounts']));
is_same($r['before']['accounts'], $kept, 'every login has every value it had');
ok($r['before']['without_login'] >= 5, 'there were students without a login on that portal: ' . $r['before']['without_login']);
is_same(0, $r['first']['without_login'], 'and afterwards there are none');
is_same(array_fill(0, $r['before']['without_login'], 'placeholder'), array_column(array_slice($r['first']['accounts'], count($r['before']['accounts'])), 'state'),
        'the only logins added are placeholders, one for each of them');
is_same(array_diff_key($r['before']['counts'], ['threads' => 0, 'accounts' => 0]), array_diff_key($r['first']['counts'], ['threads' => 0, 'accounts' => 0]),
        'every guarded table but the chats and the logins has as many rows as before');
is_same($r['before']['counts']['accounts'] + $r['before']['without_login'], $r['first']['counts']['accounts'],
        'and the logins grew by exactly those placeholders, and lost none');
is_same($r['before']['counts']['threads'] + $r['before']['courses'], $r['first']['counts']['threads'],
        'and the chats grew by one group per course, and lost none');
is_same($r['before']['mail'], $r['first']['mail'], 'no mail was queued');
is_same('', $r['before']['dummy_hash'], 'the portal had no sign-in comparison hash before the update');
ok(password_get_info($r['first']['dummy_hash'])['algo'] === PASSWORD_DEFAULT && !password_needs_rehash($r['first']['dummy_hash'], PASSWORD_DEFAULT),
   'and has one afterwards, made with PASSWORD_DEFAULT at today’s cost [R9]');
is_same('', $r['second_error'], 'the next update’s run goes through as well');
is_same([$r['first']['accounts'], $r['first']['dummy_hash'], $r['first']['counts']], [$r['second']['accounts'], $r['second']['dummy_hash'], $r['second']['counts']],
        'and changes nothing: no login, no hash, no count');

// ---------------------------------------------------------------------------
// 025: a group for every course, and the chats between a student and staff
// turned into ones the club's administrators can read (ADR 0022 §5, §10). The
// chats the previous version wrote go in before it, on the portal 024 left.
$g = $after['twentyfive'];
$chatIn = fn(array $state, string $key): ?array =>
    array_values(array_filter($state['threads'], fn($t) => (int)$t['id'] === (int)$g['chats'][$key]))[0] ?? null;
$kindAndOwner = fn(?array $t): array => [$t['kind'] ?? null, isset($t['account_id']) ? (int)$t['account_id'] : null];
$login = fn(string $key): int => (int)$g['logins'][$key];

case_('025 makes a chat between a trainer and a student one the administrators read, owned by the student');
is_same(['direct', $login('staff')], $kindAndOwner($chatIn($g['before'], 'trainer_and_student')),
        'before 025 it was a direct chat, owned by the trainer who started it');
is_same(['staff_direct', $login('mueller')], $kindAndOwner($chatIn($g['after'], 'trainer_and_student')),
        'afterwards it is staff_direct, owned by the student, so deleting the trainer’s login no longer takes it');

case_('025 leaves a chat between two students, and the old desk, as they were');
is_same(['direct', $login('mueller2')], $kindAndOwner($chatIn($g['after'], 'two_students')),
        'two students: still direct, still owned by the one who started it');
is_same($chatIn($g['before'], 'two_students'), $chatIn($g['after'], 'two_students'), 'not a value of it changed');
is_same(['staff', $login('gross')], $kindAndOwner($chatIn($g['after'], 'desk')),
        'the desk thread the trainer answered - a student and staff, two people, like the first - is still a desk thread, the student’s');
is_same($chatIn($g['before'], 'desk'), $chatIn($g['after'], 'desk'), 'not a value of it changed either');

case_('025 leaves a chat between two members of staff, and one of three people, as they were');
/* The two of 025's four conditions the chats above never decide on their own:
   that a student is in it, and that it has exactly two people. */
is_same(['direct', $login('suspended')], $kindAndOwner($chatIn($g['after'], 'two_staff')),
        'two trainers and no student: still direct, still owned by the one who started it');
is_same($chatIn($g['before'], 'two_staff'), $chatIn($g['after'], 'two_staff'), 'not a value of it changed');
is_same(['direct', $login('mueller')], $kindAndOwner($chatIn($g['after'], 'three_people')),
        'two students and a trainer, three people: still direct, still the student’s who started it');
is_same($chatIn($g['before'], 'three_people'), $chatIn($g['after'], 'three_people'), 'not a value of it changed either');

case_('025 removes no chat, no message and nobody from a chat');
is_same(['threads' => 5, 'messages' => 11, 'thread_participants' => 11], $g['before']['counts'],
        'there were five chats, four with two people and one with three, and a message from each of them, to lose');
is_same($g['before']['counts'], $g['after']['counts'], 'and every one of them is there afterwards');

case_('025 stopped after its UPDATE and started again, or its UPDATE run again after it finished, ends as one run does');
is_same($g['statements'], count($g['retried']), 'stopped after each statement but the last, and once with its UPDATE run again after it finished');
foreach ($g['retried'] as $stopped => $state)
    is_same($g['after'], $state, ($stopped < $g['statements']
        ? 'stopped after statement ' . $stopped . ' of ' . $g['statements'] . ' and run from the first: '
        : 'its UPDATE run again after the file finished: ') . 'every chat has the kind and owner of one run, and nothing is lost');

// ---------------------------------------------------------------------------
// 028 to 031 and the runner's step after them: a login may have a username and
// no address, a student's login cannot be deleted, every student has one, and a
// removed enrolment is kept (ADR 0023 §2-§4, 0024 §1). One portal as the
// version before 028 left it, one file at a time, each run a second time
// straight after.
$t = $after['twentyeight'];
$s = $t['state'];
$p = $t['people'];
$without = fn(array $rows, string ...$keys): array => array_map(fn($row) => array_diff_key($row, array_flip($keys)), $rows);
$studentsIn = fn(array $state): array => array_column($state['students'], null, 'id');
$keysOn = fn(array $keys, string $column): array => array_values(array_filter($keys, fn($k) => $k['col'] === $column));
$keysOff = fn(array $keys, string $column): array => array_values(array_filter($keys, fn($k) => $k['col'] !== $column));
$restrict = [['name' => 'student_login', 'col' => 'account_id', 'refers_to' => 'accounts', 'on_delete' => 'RESTRICT']];

case_('Before 028 the portal has students without a login to give one to, and enrolments to keep');
$had = $studentsIn($s['before']);
$noLogin = array_keys(array_filter($had, fn($row) => $row['account_id'] === null));
ok(count($noLogin) >= 9, 'there were students without a login: ' . count($noLogin));
foreach (['max', 'moritz', 'example'] as $key) ok(in_array((int)$p[$key], $noLogin, true), $key . ' is one of them');
foreach ([30, 40, 51, 80] as $id) ok(in_array($id, $noLogin, true), 'and so is student ' . $id . ', whom 019 or the version before it left without one');
is_same($had[$p['max']]['email'], $had[$p['moritz']]['email'], 'two brothers carry one parent’s address');
is_same('novak@beispiel.test', $had[80]['email'], 'Ida carries the address the Novak family signs in with');
ok(mb_strlen($had[30]['first_name'] . ' ' . $had[30]['last_name']) > TEXT_LINE_MAX, 'Emma has a name longer than a login’s name holds');
is_same(1, (int)$had[$p['example']]['is_demo'], 'one of them is an example student');
is_same((int)$p['clara_login'], (int)$had[$p['clara']]['account_id'], 'and Clara has an invitation by e-mail, not yet taken up');
ok(count($s['before']['enrolments']) >= 10, 'there were enrolments to keep: ' . count($s['before']['enrolments']));
ok(count(array_filter($s['before']['enrolments'], fn($e) => $e['left_on'] !== null)) >= 1, 'one of them a past membership');
$emptying = $keysOn($s['before']['keys'], 'account_id');
// The name is the engine's (001 gave none): students_ibfk_1 on MariaDB 10.11.14.
// 029 finds the key by what it is, so the name is shown here, not required.
is_same([['col' => 'account_id', 'refers_to' => 'accounts', 'on_delete' => 'SET NULL']], $without($emptying, 'name'),
        'one key from a student to their login, which empties the student when the login goes (named ' . ($emptying[0]['name'] ?? '?') . ' on this engine)');

case_('028 lets a login have no address and gives every login a username column, empty [ADR 0023 §2]');
$was = $s['before']['columns'];
$is = $s['028']['columns'];
is_same(['NO', null], [$was['email']['nullable'] ?? null, $was['username']], 'before 028 every login had to have an address, and none had a username column');
is_same(['type' => 'varchar(254)', 'nullable' => 'YES', 'default_value' => null, 'collation' => $was['email']['collation'] ?? null, 'position' => $was['email']['position'] ?? null],
        $is['email'], 'afterwards the address may be NULL, NULL by default, with the length, collation and place it had');
is_same('utf8mb4_unicode_ci', $is['email']['collation'] ?? null, 'which is the tables’ collation, the address’s since 001');
is_same(['type' => 'varchar(30)', 'nullable' => 'YES', 'default_value' => null, 'collation' => 'utf8mb4_unicode_ci', 'position' => ($is['email']['position'] ?? 0) + 1],
        $is['username'], 'and a username of 30 characters comes right after it, NULL by default');
is_same(array_fill(0, count($s['before']['accounts']), null), array_column($s['028']['accounts'], 'username'),
        'every login has username NULL, which is true of every one of them');
is_same($s['before']['accounts'], $without($s['028']['accounts'], 'username'), 'and every value it had, its address byte for byte, and none more');
is_same(['unique' => true, 'columns' => ['username']], $s['028']['indexes']['account_username'] ?? null, 'a unique index keeps a username to one login');
is_same($s['before']['indexes'], array_diff_key($s['028']['indexes'], ['account_username' => 1]), 'every other index is as it was');
is_same(['unique' => true, 'columns' => ['email']], $s['028']['indexes']['email'] ?? null, 'the one 001 put on the address among them, so an address is still one login’s');
is_same(array_diff_key($s['before'], ['accounts' => 1, 'columns' => 1, 'indexes' => 1]), array_diff_key($s['028'], ['accounts' => 1, 'columns' => 1, 'indexes' => 1]),
        'no student, enrolment, link, key or count changed');

case_('028 run a second time is refused by the engine and changes nothing');
ok($t['refused']['028'] !== null, 'the engine refuses it: SQLSTATE ' . var_export($t['refused']['028'], true));
is_same($s['028'], $s['028_again'], 'and every login, student, column, index, key and count is as one run left them');

case_('029 drops the key that emptied a student’s login, and nothing else [ADR 0023 §4]');
is_same(4, $t['statements']['029'], 'four statements, each of which can run again');
is_same([], $keysOn($s['029']['keys'], 'account_id'), 'afterwards no key empties students.account_id');
is_same($keysOff($s['028']['keys'], 'account_id'), $keysOff($s['029']['keys'], 'account_id'), 'the keys on level, age group and tariff are as they were');
is_same(['exists' => true, 'unique' => true, 'columns' => ['account_id']], $s['029']['one_account'], 'student_one_account stays, and with it one login for one child');
is_same(array_diff_key($s['028'], ['keys' => 1]), array_diff_key($s['029'], ['keys' => 1]), 'no login, student, enrolment, link, column, index or count changed');

case_('029 run again, after it finished and after 030, does nothing');
is_same(null, $t['refused']['029'], 'it runs a second time, on a connection of its own, as the next page view would');
is_same($s['029'], $s['029_again'], 'and changes nothing');
is_same(null, $t['refused']['029_after_030'], 'it runs once 030 has added its key, too');
is_same($s['030'], $s['029_after_030'], 'and leaves that key where it is: it only ever drops one that empties the student');

case_('029 stopped after each statement and started again on a new connection ends as one run does');
is_same($t['statements']['029'] - 1, count($t['retried']), 'it was stopped after each statement but the last');
foreach ($t['retried'] as $stopped => $round) {
    $when = 'stopped after statement ' . $stopped . ' of ' . $t['statements']['029'] . ' and run from the first: ';
    is_same($s['029']['keys'], $round['after']['keys'], $when . 'the keys of one run');
    is_same(array_diff_key($round['before'], ['keys' => 1]), array_diff_key($round['after'], ['keys' => 1]), $when . 'no row, column, index or count changed');
    is_same($s['030']['keys'], $round['keys_after_030'], $when . 'and 030 then adds its key as it does after one run');
}

case_('029 finds the key whatever the engine or a restore called it');
is_same([['name' => 'login_of_this_student', 'col' => 'account_id', 'refers_to' => 'accounts', 'on_delete' => 'SET NULL']],
        $keysOn($t['renamed']['before'], 'account_id'), 'a portal whose key has another name');
is_same($restrict, $keysOn($t['renamed']['after'], 'account_id'), 'comes out of 028 to 030 with student_login alone');
is_same($keysOff($t['renamed']['before'], 'account_id'), $keysOff($t['renamed']['after'], 'account_id'), 'and every other key as it was');

case_('030 makes a student’s login impossible to delete, and refuses no row that exists [ADR 0023 §4]');
is_same($restrict, $keysOn($s['030']['keys'], 'account_id'), 'students.account_id has one key, student_login, to accounts, ON DELETE RESTRICT');
is_same($keysOff($s['029']['keys'], 'account_id'), $keysOff($s['030']['keys'], 'account_id'), 'the other keys are as they were');
is_same($s['029']['one_account'], $s['030']['one_account'], 'it uses student_one_account, so no index is added');
is_same(array_diff_key($s['029'], ['keys' => 1]), array_diff_key($s['030'], ['keys' => 1]), 'no login, student, enrolment, link, column, index or count changed');

case_('030 run a second time is refused by the engine and changes nothing');
ok($t['refused']['030'] !== null, 'the engine refuses it: SQLSTATE ' . var_export($t['refused']['030'], true));
is_same($s['030'], $s['030_again'], 'and the key, every row and every count is as one run left them');

case_('031 gives every enrolment removed_on, NULL, and changes nothing else [ADR 0024 §1]');
is_same(null, $s['030']['columns']['removed_on'], 'before 031 there was no removed_on');
is_same(['type' => 'date', 'nullable' => 'YES', 'default_value' => null, 'collation' => null, 'position' => ($s['031']['columns']['left_on']['position'] ?? 0) + 1],
        $s['031']['columns']['removed_on'], 'afterwards a calendar date that may be NULL, NULL by default, right after left_on');
is_same(array_fill(0, count($s['030']['enrolments']), null), array_column($s['031']['enrolments'], 'removed_on'),
        'every enrolment is not removed, which is true of every one the previous version wrote');
is_same($s['030']['enrolments'], $without($s['031']['enrolments'], 'removed_on'),
        'and each keeps every term: tariff, price and note, interval, due day, discount, joined_on and left_on');
is_same(array_diff_key($s['030'], ['enrolments' => 1, 'columns' => 1]), array_diff_key($s['031'], ['enrolments' => 1, 'columns' => 1]),
        'no login, student, link, key, index or count changed, class_students’ count included');

case_('031 run a second time is refused by the engine and changes nothing');
ok($t['refused']['031'] !== null, 'the engine refuses it: SQLSTATE ' . var_export($t['refused']['031'], true));
is_same($s['031'], $s['031_again'], 'and every enrolment and count is as one run left them');

case_('The runner’s step after 031 gives every student without a login a placeholder of their own [ADR 0023 §4]');
$st = $t['step'];
is_same('', $st['first_error'], 'database/defaults.php runs on the portal 028 to 031 were applied to');
$was = $studentsIn($s['031']);
$now = $studentsIn($st['first']);
$loginsBefore = array_column($s['031']['accounts'], null, 'id');
$loginsAfter = array_column($st['first']['accounts'], null, 'id');
$needed = array_keys(array_filter($was, fn($row) => $row['account_id'] === null));
$made = array_diff_key($loginsAfter, $loginsBefore);
is_same($st['without_login'], count($needed), 'the students that needed one are the ones it was stopped on below');
is_same([], array_keys(array_filter($now, fn($row) => $row['account_id'] === null)), 'afterwards no student is without a login');
is_same(count($needed), count($made), 'one new login for each student who had none, and not one more');
$pointedAt = array_map(fn($id) => (int)$now[$id]['account_id'], $needed);
sort($pointedAt);
$madeIds = array_map('intval', array_keys($made));
sort($madeIds);
is_same($madeIds, $pointedAt, 'each of those students points at one of the new logins, each at a different one');
// array_key_exists rather than ??, which would read the NULLs looked for here as "missing".
$facts = fn(array $row, array $columns): array => array_map(fn($c) => array_key_exists($c, $row) ? $row[$c] : 'missing', $columns);
foreach ($needed as $id) {
    $placeholder = $loginsAfter[(int)$now[$id]['account_id']] ?? [];
    $name = login_name_for((string)$was[$id]['first_name'], (string)$was[$id]['last_name']);
    is_same([$name, null, null, null, null, 'student', 'placeholder', 'de', $was[$id]['is_demo']],
            $facts($placeholder, ['name', 'email', 'username', 'password_hash', 'verified_at', 'role', 'state', 'locale', 'is_demo']),
            'student ' . $id . ' has a placeholder named after them, with no address, username or password, never set up, and example data only if they are');
}
$nameOf = fn(int $studentId): string => (string)($loginsAfter[(int)$now[$studentId]['account_id']]['name'] ?? '');
ok(mb_strlen($nameOf(30)) <= TEXT_LINE_MAX && str_starts_with($had[30]['first_name'] . ' ' . $had[30]['last_name'], $nameOf(30)),
   'Emma’s placeholder carries as much of her name as a login’s name holds, cut the way the portal cuts it, where a strict server would refuse it whole');
ok($now[$p['max']]['account_id'] !== $now[$p['moritz']]['account_id'],
   'the two brothers on one parent’s address have a placeholder each, neither carrying the address, so the unique index has nothing to refuse');
is_same((int)$p['clara_login'], (int)$now[$p['clara']]['account_id'], 'a student invited by e-mail keeps the invitation, which no placeholder replaces');
is_same([1], array_values(array_unique(array_map(fn($l) => (int)$l['is_demo'], array_filter($made, fn($l) => $l['name'] === 'Beispiel Kind')))),
        'the example student’s placeholder is example data, for demo_clear() to take with them');
is_same(1, count(array_filter($made, fn($l) => (int)$l['is_demo'] === 1)), 'and no other placeholder is');
$adopted = array_map(fn($row) => (int)$row['account_id'], $now);
foreach (['orphan' => 'a login whose student is gone', 'invitation' => 'an invitation by address', 'invited' => 'an invitation never taken up'] as $key => $what)
    ok(!in_array((int)$p[$key], $adopted, true), $what . ' is not given to any student');
is_same($s['031']['accounts'], array_values(array_intersect_key($loginsAfter, $loginsBefore)),
        'every login that was there has every value it had: the address, password, state and role of every one');
$untouched = fn(array $rows): array => array_filter($rows, fn($row) => !in_array((int)$row['id'], $needed, true));
is_same($untouched($was), $untouched($now), 'a student who had a login has the same login, revision and time of change');
is_same(array_map(fn($id) => (int)$was[$id]['revision'] + 1, $needed), array_map(fn($id) => (int)$now[$id]['revision'], $needed),
        'each student given a login has their revision raised once, so a form opened before the update is refused rather than saved over it');
is_same(array_map(fn($id) => [$was[$id]['first_name'], $was[$id]['last_name'], $was[$id]['email'], $was[$id]['is_demo']], $needed),
        array_map(fn($id) => [$now[$id]['first_name'], $now[$id]['last_name'], $now[$id]['email'], $now[$id]['is_demo']], $needed),
        'and keeps their name, their address and everything else');
is_same([$s['031']['enrolments'], $s['031']['tokens'], $s['031']['mail']], [$st['first']['enrolments'], $st['first']['tokens'], $st['first']['mail']],
        'no enrolment, link or waiting mail changed, and nobody was mailed');
is_same(array_diff_key($s['031']['counts'], ['accounts' => 0, 'threads' => 0]), array_diff_key($st['first']['counts'], ['accounts' => 0, 'threads' => 0]),
        'every guarded table but the logins and the course chats has as many rows as before');
is_same($s['031']['counts']['accounts'] + count($needed), $st['first']['counts']['accounts'], 'the logins grew by exactly the placeholders, and lost none');
ok($st['first']['counts']['threads'] >= $s['031']['counts']['threads'], 'and the chats lost none');

case_('The runner’s step stopped part way leaves every student and every login as it was, and the next run finishes it');
is_same($st['without_login'] - 1, count($st['stopped']), 'it was stopped after each placeholder but the last');
foreach ($st['stopped'] as $stopped => $round) {
    $when = 'stopped after ' . $stopped . ' of ' . $st['without_login'] . ' placeholders: ';
    ok(str_contains($round['error'], 'stopped by the test'), $when . 'the update says it failed, so the portal stays closed and the next request runs the step again');
    is_same([$s['031']['accounts'], $s['031']['students'], $s['031']['enrolments'], $s['031']['tokens'], $s['031']['mail']],
            [$round['state']['accounts'], $round['state']['students'], $round['state']['enrolments'], $round['state']['tokens'], $round['state']['mail']],
            $when . 'no placeholder is left behind and no student is linked: every login, student, enrolment and link is as before');
}

case_('The runner’s step run again changes nothing');
is_same('', $st['second_error'], 'the next update’s run goes through');
is_same($st['first'], $st['second'], 'and every login, student, enrolment, link and count is as the first left them');

case_('Afterwards the database refuses to delete a student’s login of any kind, and still allows what it allowed [ADR 0023 §4]');
$a = $st['attempts'];
is_same(['23000', 1451], $a['placeholder'], 'a placeholder a student points to is refused');
is_same(['23000', 1451], $a['in_use'], 'so is a login in use');
is_same(['23000', 1451], $a['invitation_of_a_student'], 'and a student’s invitation not yet taken up');
is_same(null, $a['orphan'], 'a login whose student is gone can still be deleted');
is_same(null, $a['invitation_by_address'], 'and so can an invitation by address that no student points to');
is_same(null, $a['student_first'], 'deleting the student first still lets their login go after them');
is_same(['23000', 1452], $a['missing_login'], 'a student cannot be pointed at a login that does not exist');
is_same(null, $a['student_without_login'],
        'the database still takes a student written without a login: create_student() and the step hold that rule, because ADR 0023 rejects NOT NULL');
is_same($st['first'], $st['after_attempts'], 'and every attempt was undone, so none of them changed the portal');

case_('Afterwards a login may have neither an address nor a username, or a username alone, and each stays one login’s [ADR 0023 §2]');
is_same(null, $a['neither_twice'], 'two logins with neither are both taken, where an empty string would have refused the second');
is_same(null, $a['username_alone'], 'a login with a username and no address is taken');
is_same(['23000', 1062], $a['username_other_case'], 'a second login on a username taken in other capitals is refused');
is_same(null, $a['username_own'], 'while one on a username of its own is taken, so what refused the other was the username');
is_same(['23000', 1062], $a['address_taken'], 'and a second login on an address that is taken is still refused');

case_('028 to 031 and the step never lower a guarded count, so the update’s guard stays as it is');
foreach (['028', '029', '030', '031'] as $number)
    is_same($s['before']['counts'], $s[$number]['counts'], $number . ' leaves every table in schema_guarded_tables() with as many rows as before');
ok(($s['before']['counts']['accounts'] ?? 0) > 0 && ($s['before']['counts']['students'] ?? 0) > 0 && ($s['before']['counts']['class_students'] ?? 0) > 0,
   'and there were logins, students and enrolments to lose');
foreach (['accounts', 'students', 'class_students'] as $table)
    ok(in_array($table, schema_guarded_tables(), true), $table . ' is guarded, so a lost row would have kept the portal closed');
