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
 * tests/migration-data.php builds a portal as it stood before 015, 019, 020,
 * 022, 024, 025, 028, 032, 034, 038, 039, 040 and 041, applies the rest, and
 * prints what it finds; then it runs this release's update through the
 * application's own runner, with the files the pictures left on disk, the
 * mistakes ADR 0027 keeps the portal closed for, page view after page view,
 * with the imports that reopen it, the update stopped between two of its files
 * and started again, and the restores ADR 0029 keeps it closed for. This reads
 * that and holds it to the promise.
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

case_('On this run’s own engine, a new login has news by email on');
run("INSERT INTO accounts (name, email, role, created_at) VALUES ('Neu', 'neu@example.test', 'student', ?)", [now()]);
is_same(1, (int)scalar("SELECT newsletter FROM accounts WHERE email = 'neu@example.test'"), 'news by email is on');

case_('On this run’s own engine, the online history, the contact requests, a login’s status and emoji, and the columns a login’s and a child’s old pictures were in are gone [ADR 0026 §8, §11]');
foreach (['online_periods', 'contact_requests'] as $table)
    ok(!test_has_table($table), $table . ' is not in the database the migrations make');
$columnsOf = fn(string $table): array => array_column(rows('SELECT column_name AS name FROM information_schema.columns'
    . ' WHERE table_schema = DATABASE() AND table_name = ?', [$table]), 'name');
is_same([], array_values(array_intersect(['last_seen_at', 'presence', 'status_emoji', 'avatar_name'], $columnsOf('accounts'))),
        'accounts has none of last_seen_at, presence, status_emoji and avatar_name');
ok(!in_array('avatar_name', $columnsOf('students'), true), 'students has no avatar_name');
is_same(0, (int)scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE()"
    . " AND table_name = 'accounts' AND index_name = 'account_seen'"), 'and the index on last_seen_at went with its column');
is_same([], array_values(array_intersect(['online_periods', 'contact_requests'], schema_guarded_tables())),
        'neither dropped table is on the update’s guard, so dropping them needed no change to it');

case_('On this run’s own engine, a child has a picture’s name and the family’s yes for the course and a login a picture’s name, empty and no until they are set [ADR 0031]');
// On the database every other suite uses, so a run on MariaDB proves it there.
// migration-data.php holds 040 and 041 to the children and logins the previous
// version wrote.
$inOrder = array_column(rows('SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE()'
    . " AND table_name = 'students' ORDER BY ordinal_position"), 'name');
$lastName = array_search('last_name', $inOrder, true);
is_same(['last_name', 'picture_name', 'course_sees_picture'], $lastName === false ? [] : array_slice($inOrder, $lastName, 3),
        'students has picture_name and course_sees_picture, in that order, right after last_name');
test_load_actions();
$newChild = 0;
does_not_throw(function () use (&$newChild) { $newChild = transactional(fn(): int => create_student(['first_name' => 'Ohne', 'last_name' => 'Bild'])); },
               'create_student() makes a child, naming neither column');
// Read inside does_not_throw(): a column a migration failed to add must fail
// these checks, not stop the suite before the run with data in between.
$read = [];
does_not_throw(function () use (&$read, $newChild) {
    $read = [one('SELECT picture_name, course_sees_picture FROM students WHERE id = ?', [$newChild]),
             scalar('SELECT a.picture_name FROM accounts a JOIN students s ON s.account_id = a.id WHERE s.id = ?', [$newChild])];
}, 'the child and its login can be read back by those columns');
is_same(['picture_name' => '', 'course_sees_picture' => 0], $read[0] ?? null, 'and the child has no picture and no yes for the course');
is_same('', $read[1] ?? null, 'and the placeholder login create_student() makes for the child has no picture either');
is_same(['picture_name'], array_values(array_filter($columnsOf('accounts'), fn(string $column): bool => str_contains($column, 'picture') || str_contains($column, 'avatar'))),
        'accounts has one column for a picture, picture_name, a team member’s: the old avatar_name stays dropped');

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

case_('On this run’s own engine, a login has an address or none, there is no username column, and logins without an address do not collide [ADR 0030 §2, 0023 §2]');
// 028 let the address be NULL for a placeholder and gave every login a username
// column; 039 drops the column and its index again, and the address stays as 028
// left it: a unique index lets any number of rows share NULL and nothing else.
$column = fn(string $name): ?array => one('SELECT is_nullable AS nullable, column_type AS type FROM information_schema.columns'
    . " WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = ?", [$name]);
is_same(null, $column('username'), 'accounts has no username column');
is_same(['nullable' => 'YES', 'type' => 'varchar(254)'], $column('email'), 'and its address may be empty');
is_same(0, (int)scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'accounts' AND index_name = 'account_username'"),
        'the index account_username went with the column');
is_same([['non_unique' => 0, 'col' => 'email']], array_map(fn($r) => ['non_unique' => (int)$r['non_unique'], 'col' => $r['col']],
    rows("SELECT non_unique, column_name AS col FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'accounts' AND index_name = 'email'")),
        'and the one 001 put on the address keeps an address to one login');
$unnamed = [];
does_not_throw(function () use (&$unnamed) {
    foreach (['ohne.namen.eins@example.test', 'ohne.namen.zwei@example.test'] as $email) {
        run("INSERT INTO accounts (name, email, role, created_at) VALUES ('', ?, 'student', ?)", [$email, now()]);
        $unnamed[] = (int)db()->lastInsertId();
    }
}, 'two logins written the way an invitation by address writes them, naming only name, address, role and time, are both taken');
is_same(2, count(array_filter($unnamed)), 'and both are there');
$neither = [];
does_not_throw(function () use (&$neither) {
    foreach (['Platzhalter Eins', 'Platzhalter Zwei'] as $name) {
        run("INSERT INTO accounts (name, role, state, created_at) VALUES (?, 'student', 'placeholder', ?)", [$name, now()]);
        $neither[] = (int)db()->lastInsertId();
    }
}, 'two logins without an address, placeholders, are both taken');
is_same([null, null], array_column(rows('SELECT email FROM accounts WHERE id IN (?, ?) ORDER BY id', array_pad($neither, 2, 0)), 'email'),
        'and both have NULL for the address');

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
throws(fn() => submit('login', ['login' => 'niemand@hier.test', 'password' => 'falsch']), 'a sign-in with no such address is refused', 'Anmeldung nicht möglich');
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
is_same(['Jürgen Groß-Özdemir', null, null, null, 'student', 'placeholder', 0],
        [$of[$gets]['name'], $of[$gets]['email'], $of[$gets]['password_hash'], $of[$gets]['verified_at'],
         $of[$gets]['role'], $of[$gets]['state'], (int)$of[$gets]['is_demo']],
        'named after the child, with no address, no password, not set up, a student’s, a placeholder');
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

case_('On this run’s own engine, custom fields, saved views and message templates are gone, and a new portal’s seed writes none [ADR 0026 §7, §8]');
foreach (['field_definitions', 'field_values', 'saved_filters', 'message_templates'] as $table)
    ok(!test_has_table($table), $table . ' is not in the database the migrations make');
ok(!in_array('field_values', schema_guarded_tables(), true), 'field_values is off the update’s guard, since 032 empties it on purpose');
// The part of the seed that runs once, on a new portal: it wrote two templates.
run("DELETE FROM settings WHERE setting_key = 'defaults_initialized'");
setting_cache_clear();
does_not_throw($runnerStep, 'the seed a first install runs goes through on that schema, with no template left to write');

case_('On this run’s own engine, a child is no longer pinned to an age band, and keeps a level and the birth date the band is worked out from [ADR 0026 §8, §11]');
ok(!in_array('age_group_id', $columnsOf('students'), true), 'students has no age_group_id');
ok(in_array('level_id', $columnsOf('students'), true) && in_array('birth_date', $columnsOf('students'), true), 'and keeps level_id and birth_date');
foreach (['levels', 'age_groups'] as $table) ok(test_has_table($table), $table . ' is still there');
is_same([['name' => 'student_level', 'refers_to' => 'levels', 'on_delete' => 'SET NULL']],
        rows("SELECT constraint_name AS name, referenced_table_name AS refers_to, delete_rule AS on_delete FROM information_schema.referential_constraints"
           . " WHERE constraint_schema = DATABASE() AND table_name = 'students' AND constraint_name IN ('student_level', 'student_age_group')"),
        'the pin’s key, student_age_group, is gone, and the level’s, student_level, is there');
is_same(['student_level'], array_column(rows("SELECT DISTINCT index_name AS name FROM information_schema.statistics WHERE table_schema = DATABASE()"
    . " AND table_name = 'students' AND index_name IN ('student_level', 'student_age_group')"), 'name'),
        'and of the two indexes the engine made for them, the level’s is there and the pin’s went with its column');

case_('On this run’s own engine, the runner’s step gives a level only to a child without one, and adds nothing to lists that have rows');
// Its backfill is one UPDATE over every child, and what keeps it to the children
// without a level is its WHERE: held here to the child it must not touch as well
// as the one it must.
run("INSERT INTO levels (name, description, sort_order, is_default, archived, created_at) VALUES ('Fortgeschritten (Test)', '', 90, 0, 0, ?)", [now()]);
$otherLevel = (int)db()->lastInsertId();
$defaultLevel = (int)scalar('SELECT id FROM levels WHERE is_default = 1 ORDER BY id LIMIT 1');
$inALevel = make_student(['first_name' => 'Hat', 'last_name' => 'Gruppe', 'level_id' => $otherLevel]);
$withoutALevel = make_student(['first_name' => 'Ohne', 'last_name' => 'Gruppe', 'level_id' => null]);
$levelsOfAll = fn(): array => array_map(fn($level) => $level === null ? null : (int)$level,
                                        array_column(rows('SELECT id, level_id FROM students ORDER BY id'), 'level_id', 'id'));
$listsNow = fn(): array => [rows('SELECT * FROM levels ORDER BY id'), rows('SELECT * FROM age_groups ORDER BY id')];
[$levelsBefore, $listsBefore] = [$levelsOfAll(), $listsNow()];
ok($defaultLevel > 0 && $defaultLevel !== $otherLevel && $levelsBefore[$withoutALevel] === null, 'before it, one child is in a level other than the default, and one in none');
does_not_throw($runnerStep, 'the step goes through on that schema, and reads no pin');
$levelsExpected = $levelsBefore;
$levelsExpected[$withoutALevel] = $defaultLevel;
is_same($levelsExpected, $levelsOfAll(), 'afterwards the child without a level has the default one, and every other child, the one in another level included, has the level they had');
is_same($listsBefore, $listsNow(), 'and neither list gains or loses a row');

case_('On this run’s own engine, a change-log line about a child’s level and pinned band reads in words, the pin’s column gone [ADR 0026 §8]');
// Lines written before 038 stay until the horizon removes them, and the column
// one of them names is gone, so the line is drawn from what it holds: the column
// by its label, each value as history_value() shows what was written.
$staff = make_account(['role' => 'admin', 'name' => 'Trainerin Gruppen']);
$pinned = make_student(['first_name' => 'Lisa', 'last_name' => 'Angeheftet']);
$was = ['level_id' => 1, 'age_group_id' => null];
$became = ['level_id' => 4, 'age_group_id' => 2];
run('INSERT INTO record_versions (entity, entity_id, operation, label, before_json, after_json, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)',
    ['students', $pinned, 'update', 'Lisa Angeheftet', json_encode($was), json_encode($became), now()]);
sign_in_as($staff);
$pinLine = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(render_view('history', ['entity' => 'students', 'record' => (string)$pinned])),
                                                          ENT_QUOTES | ENT_HTML5, 'UTF-8'));
sign_out();
foreach (['level_id', 'age_group_id'] as $column) {
    ok(history_field_label($column) !== $column, $column . ' is named in words: ' . history_field_label($column));
    ok(str_contains($pinLine, history_field_label($column) . ' ' . history_value($was[$column], $column) . ' → ' . history_value($became[$column], $column)),
       'and the child’s line shows it so, from what it was to what it became');
}
ok(!str_contains($pinLine, 'level_id') && !str_contains($pinLine, 'age_group_id'), 'no column name shows');

case_('On this run’s own engine, the runner’s step deletes the pictures left behind, and the screenshots stay [ADR 0026 §8]');
// No column names a picture since 035 and 036, so a stored file in the pictures'
// folder that no problem report names is one left behind. migration-data.php
// holds the step to the pictures the columns named, beside every other kind of
// file; this runs wherever the suite does, a host without exec included.
$pictures = upload_dir('avatar');
if (!is_dir($pictures)) mkdir($pictures, 0700, true);
[$leftBehind, $screenshot, $justSaved] = [str_repeat('a', 32) . '.jpg', str_repeat('d', 32) . '.png', str_repeat('9', 32) . '.jpg'];
// Either side of the step's ten minutes, which the nightly prune's hour and
// prune_uploads()' floor of one minute would each get wrong.
[$nineMinutes, $elevenMinutes] = [str_repeat('b', 32) . '.jpg', str_repeat('c', 32) . '.jpg'];
foreach ([$leftBehind => time() - 86400, $screenshot => time() - 86400, $justSaved => time(),
          $nineMinutes => time() - 540, $elevenMinutes => time() - 660] as $name => $when) {
    file_put_contents($pictures . '/' . $name, 'x');
    touch($pictures . '/' . $name, $when);
}
// The report comes from a login of its own making: prune_uploads() deletes
// nothing while accounts is empty, as in a database half way through a restore,
// so without one this case would hold the step to nothing.
$reporter = make_account(['name' => 'Meldet einen Fehler']);
run("INSERT INTO feedback (account_id, page, message, context_json, screenshot_name, created_at) VALUES (?, 'dashboard', 'Kaputt', '{}', ?, ?)",
    [$reporter, $screenshot, now()]);
$runnerStep();
clearstatcache();
ok(!is_file($pictures . '/' . $leftBehind), 'a picture a day old that no report names is deleted');
ok(is_file($pictures . '/' . $screenshot), 'a screenshot a problem report names stays, though it is as old');
ok(is_file($pictures . '/' . $justSaved), 'and so does a picture saved a moment ago, which may be a screenshot whose report is being saved');
ok(is_file($pictures . '/' . $nineMinutes) && !is_file($pictures . '/' . $elevenMinutes),
   'the step leaves ten minutes: a picture nine minutes old stays, and one eleven minutes old is deleted');
$runnerStep();
clearstatcache();
ok(is_file($pictures . '/' . $screenshot) && is_file($pictures . '/' . $justSaved), 'run again, as every later update runs it, it deletes nothing more');
run('DELETE FROM feedback WHERE screenshot_name = ?', [$screenshot]);
foreach ([$screenshot, $justSaved, $nineMinutes] as $name) @unlink($pictures . '/' . $name);

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
        ['the data moved, kept, added or dropped by migrations 015, 016 and 019 to 041, and the update refusing a migration that drops a guarded table'
         . ' (this PHP disables exec, which the run with data in between needs)']));
    return;
}
$target = (string)getenv('CRM_MIGRATION_CONFIG');
if ($target === '') {
    test_unsupported(array_merge(test_unsupported(),
        ['the data moved, kept, added or dropped by migrations 015, 016 and 019 to 041, and the update refusing a migration that drops a'
         . ' guarded table (set CRM_MIGRATION_CONFIG to the config of a'
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
// What the engine said to a file run a second time: [SQLSTATE, its own error number].
$refusedWith = fn(?array $said): string => $said === null ? 'nothing' : 'SQLSTATE ' . $said[0] . ', error ' . $said[1];
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
ok($t['refused']['028'] !== null, 'the engine refuses it: ' . $refusedWith($t['refused']['028']));
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
ok($t['refused']['030'] !== null, 'the engine refuses it: ' . $refusedWith($t['refused']['030']));
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
ok($t['refused']['031'] !== null, 'the engine refuses it: ' . $refusedWith($t['refused']['031']));
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

// ---------------------------------------------------------------------------
// 032 and 033: custom fields go with their values, and saved views and message
// templates go (ADR 0026 §7, §8, §11). Both only drop, so they are held to
// taking their own tables away and nothing else: every other table, its rows to
// the engine's checksum of every row, and every key, as it was.
$d = $after['dropping'];
$custom = ['field_definitions', 'field_values'];
$views = ['message_templates', 'saved_filters'];
$except = fn(array $map, array $keys): array => array_diff_key($map, array_flip($keys));
$touching = fn(array $keys, array $tables): array => array_values(array_filter($keys,
    fn($k) => in_array($k['on_table'], $tables, true) || in_array($k['refers_to'], $tables, true)));

case_('Before 032 the portal has custom fields, saved views and templates to lose, beside rows that must stay');
is_same(['field_definitions' => 2, 'field_values' => 4, 'message_templates' => 3, 'saved_filters' => 2],
        array_intersect_key($d['before']['rows'], array_flip(array_merge($custom, $views))),
        'two custom fields with four values, three templates and two saved views');
ok(($d['before']['rows']['students'] ?? 0) >= 10, 'beside the students the values are on: ' . ($d['before']['rows']['students'] ?? 0));
is_same(['students', 'field_definitions', 'message_templates'], array_column($d['before']['lines'], 'entity'),
        'and the change log has a line about a student’s custom-field value, one about a field and one about a template');
ok(str_contains((string)($d['before']['lines'][0]['before_json'] ?? ''), '"field:' . ($d['written']['fields'][0] ?? '?') . '"'),
   'the value’s line under the field:<id> key the change log gives one');
// The engine names the keys 001 left unnamed, so they are compared without names.
is_same([['field_values', 'students', 'CASCADE'], ['field_values', 'field_definitions', 'RESTRICT']],
        array_map(fn($k) => [$k['on_table'], $k['refers_to'], $k['on_delete']], $touching($d['before']['keys'], array_merge($custom, $views))),
        'the only keys that touch the four tables are field_values’ two: no table points at a saved view or a template');

case_('032 drops the custom fields with every value, and nothing else');
is_same(array_values(array_diff($d['before']['tables'], $custom)), $d['032']['tables'], 'field_definitions and field_values are gone, and every other table is there');
is_same($except($d['before']['sums'], $custom), $d['032']['sums'],
        'every other table has every row it had, to the engine’s checksum: the students the values were on, the change log and its field:<id> line, the saved views and the templates');
is_same($except($d['before']['rows'], $custom), $d['032']['rows'], 'and as many rows');
is_same(array_values(array_filter($d['before']['keys'], fn($k) => $k['on_table'] !== 'field_values')), $d['032']['keys'],
        'the keys gone are field_values’ own two, and every other key is as it was');

case_('033 drops the saved views and the templates, and nothing else');
is_same(array_values(array_diff($d['032']['tables'], $views)), $d['033']['tables'], 'saved_filters and message_templates are gone, and every other table is there');
is_same($except($d['032']['sums'], $views), $d['033']['sums'], 'every other table has every row it had, to the engine’s checksum, the change log’s line about a template included');
is_same($except($d['032']['rows'], $views), $d['033']['rows'], 'and as many rows');
is_same($d['032']['keys'], $d['033']['keys'], 'and every key is as it was');

case_('The change log keeps its lines about custom fields and templates as they were written [ADR 0026 §7]');
is_same($d['before']['lines'], $d['032']['lines'], '032 leaves every one, the field:<id> key included');
is_same($d['before']['lines'], $d['033']['lines'], 'and so does 033, so the history page has them to name without the tables');

case_('032 and 033 leave every guarded table, and drop none, so the guard has nothing to refuse');
is_same([], array_values(array_diff(schema_guarded_tables(), $d['033']['tables'])), 'every table the update guards is still there after both');
is_same([], array_values(array_intersect(array_merge($custom, $views), schema_guarded_tables())),
        'and none of the four they drop is guarded: field_values, which 032 empties on purpose, left the list with it');

case_('032 and 033 run a second time do nothing');
is_same(['032' => null, '033' => null], $d['refused'] ?? [],
        'each runs again, on a connection of its own as the next page view would, and the engine refuses neither');
is_same($d['032'], $d['032_again'], '032 run again changes nothing');
is_same($d['033'], $d['033_again'], 'and neither does 033');

case_('032 and 033 stopped after a statement and started again from the first end as one run does');
// Each round builds its portal afresh, dated as it was built, so one round is
// compared with the run above by tables, rows and keys, and with itself by
// checksum.
foreach (['032' => $custom, '033' => $views] as $number => $gone) {
    is_same(($d['statements'][$number] ?? 0) - 1, count($d['retried'][$number] ?? []), $number . ' was stopped after each statement but the last');
    foreach ($d['retried'][$number] ?? [] as $stopped => $round) {
        $when = $number . ' stopped after statement ' . $stopped . ' of ' . $d['statements'][$number] . ': ';
        $half = array_values(array_diff($round['before']['tables'], $round['stopped']['tables']));
        ok(count($half) === (int)$stopped && array_intersect($half, $gone) === $half, $when . 'it had dropped ' . implode(', ', $half) . ' and no more');
        is_same($except($round['before']['sums'], $half), $round['stopped']['sums'], $when . 'the rest, the second table included, kept every row');
        is_same([$d[$number]['tables'], $d[$number]['rows'], $d[$number]['keys']], [$round['after']['tables'], $round['after']['rows'], $round['after']['keys']],
                $when . 'run from the first, the same tables, rows and keys as one run');
        is_same($except($round['before']['sums'], $gone), $round['after']['sums'], $when . 'and every other table every row it had');
    }
}

// ---------------------------------------------------------------------------
// 034 to 037: when each login was online, a login's time last seen, status, emoji
// and picture, a child's picture, and the requests families sent one another go
// (ADR 0026 §8, §11; 0022 §11.3). Each only drops, so each is held to taking its
// own and nothing else: every other table to the engine's checksum of every row,
// every key, and of accounts and students every other value of every row.
$go = $after['going'];
$presence = ['last_seen_at', 'presence', 'status_emoji', 'avatar_name'];
$less = fn(array $rows, array $columns): array => array_map(fn(array $row): array => array_diff_key($row, array_flip($columns)), $rows);
$listed = fn(array $list, array $gone): array => array_values(array_diff($list, $gone));
$keysOf = fn(array $keys, string $table): array => array_values(array_filter($keys, fn($k) => $k['on_table'] === $table));
$filled = fn(array $rows, string $column, $empty): int => count(array_filter($rows, fn(array $row): bool => $row[$column] !== $empty));

case_('Before 034 the portal has an online history, statuses, emoji, pictures and contact requests to lose, beside rows that must stay');
is_same(['contact_requests' => 3, 'online_periods' => 3], array_intersect_key($go['before']['rows'], array_flip(['online_periods', 'contact_requests'])),
        'three stretches of time online, and three requests between families');
is_same([4, 2, 3, 2, 2], [$filled($go['before']['accounts'], 'last_seen_at', null), $filled($go['before']['accounts'], 'presence', 'auto'),
                          $filled($go['before']['accounts'], 'status_emoji', ''), $filled($go['before']['accounts'], 'avatar_name', ''),
                          $filled($go['before']['students'], 'avatar_name', '')],
        'logins with a time last seen, a chosen status, an emoji and a picture, and children with a picture');
ok(count($go['before']['accounts']) > 4 && count($go['before']['students']) > 2, 'beside logins and children with none of them');
ok(($go['before']['rows']['message_files'] ?? 0) === 3 && ($go['before']['rows']['feedback'] ?? 0) === 1,
   'and a chat’s photo, voice note and file, and a problem report with its screenshot, which have to stay');
is_same([['contact_requests', 'accounts', 'CASCADE'], ['contact_requests', 'accounts', 'CASCADE'], ['online_periods', 'accounts', 'CASCADE']],
        array_map(fn($k) => [$k['on_table'], $k['refers_to'], $k['on_delete']], array_values(array_filter($go['before']['keys'],
            fn($k) => in_array($k['on_table'], ['online_periods', 'contact_requests'], true) || in_array($k['refers_to'], ['online_periods', 'contact_requests'], true)))),
        'the only keys that touch the two tables are their own, to accounts: nothing points at either');

case_('034 drops the online history, and nothing else');
is_same($listed($go['before']['tables'], ['online_periods']), $go['034']['tables'], 'online_periods is gone, and every other table is there');
is_same($except($go['before']['sums'], ['online_periods']), $go['034']['sums'], 'every other table has every row it had, to the engine’s checksum');
is_same($except($go['before']['rows'], ['online_periods']), $go['034']['rows'], 'and as many rows');
is_same(array_values(array_filter($go['before']['keys'], fn($k) => $k['on_table'] !== 'online_periods')), $go['034']['keys'],
        'the key gone is online_periods’ own, and every other key is as it was');
is_same([$go['before']['columns'], $go['before']['indexes']], [$go['034']['columns'], $go['034']['indexes']], 'accounts and students have their columns and indexes');

case_('035 takes the time last seen, the status, the emoji and the picture from every login, and nothing else');
is_same($listed($go['034']['columns']['accounts'], $presence), $go['035']['columns']['accounts'], 'accounts loses the four columns, and keeps every other in its order');
is_same($except($go['034']['indexes']['accounts'], ['account_seen']), $go['035']['indexes']['accounts'], 'and the index on last_seen_at, and keeps every other index');
is_same($less($go['034']['accounts'], $presence), $go['035']['accounts'], 'every login has every other value it had, and none is lost');
is_same($except($go['034']['sums'], ['accounts']), $except($go['035']['sums'], ['accounts']), 'every other table has every row it had, to the engine’s checksum');
is_same([$go['034']['tables'], $go['034']['rows'], $go['034']['keys']], [$go['035']['tables'], $go['035']['rows'], $go['035']['keys']],
        'the same tables, as many rows in each, and every key, those to accounts included');

case_('036 takes the picture from every child, and nothing else');
is_same($listed($go['035']['columns']['students'], ['avatar_name']), $go['036']['columns']['students'], 'students loses avatar_name, and keeps every other column in its order');
is_same($go['035']['indexes']['students'], $go['036']['indexes']['students'], 'and every index');
is_same($less($go['035']['students'], ['avatar_name']), $go['036']['students'], 'every child has every other value they had, and none is lost');
is_same($except($go['035']['sums'], ['students']), $except($go['036']['sums'], ['students']), 'every other table has every row it had, to the engine’s checksum');
is_same([$go['035']['tables'], $go['035']['rows'], $go['035']['keys']], [$go['036']['tables'], $go['036']['rows'], $go['036']['keys']],
        'the same tables, as many rows in each, and every key');

case_('037 drops the contact requests, and nothing else');
is_same($listed($go['036']['tables'], ['contact_requests']), $go['037']['tables'], 'contact_requests is gone, and every other table is there');
is_same($except($go['036']['sums'], ['contact_requests']), $go['037']['sums'], 'every other table has every row it had, to the engine’s checksum');
is_same($except($go['036']['rows'], ['contact_requests']), $go['037']['rows'], 'and as many rows');
is_same(array_values(array_filter($go['036']['keys'], fn($k) => $k['on_table'] !== 'contact_requests')), $go['037']['keys'],
        'the keys gone are contact_requests’ own two, and every other key is as it was');
is_same([], $keysOf($go['037']['keys'], 'contact_requests'), 'none of them is left');

case_('034 to 037 keep the chat’s photo, voice note and file, the screenshot’s report, and the change log’s lines about pictures and emoji [ADR 0022 §11.4]');
foreach (['threads', 'messages', 'message_files', 'feedback', 'record_versions', 'audit_log'] as $table)
    is_same($go['before']['sums'][$table] ?? 'missing', $go['037']['sums'][$table] ?? 'gone', $table . ' is as it was, to the engine’s checksum');

case_('034 to 037 lower no guarded count, so the update’s guard stays as it is');
foreach (['034', '035', '036', '037'] as $number)
    is_same($go['before']['counts'], $go[$number]['counts'], $number . ' leaves every table in schema_guarded_tables() with as many rows as before');
foreach (['accounts', 'students', 'message_files'] as $table)
    ok(in_array($table, schema_guarded_tables(), true) && ($go['before']['counts'][$table] ?? 0) > 0, $table . ' is guarded and had rows to lose');

case_('034 to 037 are one statement each, so none can stop inside itself');
is_same(['034' => 1, '035' => 1, '036' => 1, '037' => 1], $go['statements'], 'one statement in each file');

case_('Run a second time, 034 and 037 do nothing, and 035 and 036 are refused by the engine and change nothing');
// An update that applied a file but stopped before the ledger recorded it starts
// the file again on the next page view. A DROP TABLE IF EXISTS runs twice; MySQL
// 8.0 has no DROP COLUMN IF EXISTS, so 035 and 036 cannot, and what matters is
// that the second run stops there, with the portal closed and the copy in place,
// and loses nothing - as 024's does.
is_same(['034' => null, '035' => ['42000', 1091], '036' => ['42000', 1091], '037' => null], $go['refused'] ?? [],
        'each runs again on a connection of its own; the engine refuses only the two that drop columns, because what they drop is gone (1091, SQLSTATE 42000)');
foreach (['034', '035', '036', '037'] as $number)
    is_same($go[$number], $go[$number . '_again'], $number . ' run again changes nothing: the same tables, rows, checksums, keys, columns and indexes');

// ---------------------------------------------------------------------------
// 038: a child is no longer pinned to an age band, and falls in the band their
// birth date gives (ADR 0026 §8, §11; the owner's answer of 2026-10-07). It drops
// one column and its key, so it is held to taking those and nothing else: every
// other value of every child - their level and their birth date above all -
// every other key and index, both lists with every row and every column, every
// other table to the engine's checksum of every row, and the change log's and
// the audit log's lines about levels and bands.
$pins = $after['pins'];
[$was, $is] = [$pins['before'], $pins['after']];
$lists = ['levels', 'age_groups'];
$childGone = ['avatar_name', 'age_group_id'];
$studentOf = fn(array $state, int $id): array => array_column($state['students'], null, 'id')[$id] ?? [];
$keyRows = fn(array $keys): array => array_map(fn($k) => [$k['on_table'], $k['name'], $k['refers_to'], $k['on_delete']], $keys);

case_('Before 038 children are pinned to an age band, beside levels, bands and children in every level, which must stay');
is_same(['age_groups' => 4, 'levels' => 4], array_intersect_key($was['rows'], array_flip($lists)),
        'four levels and four bands: three of each as a new portal starts, and one of each the trainer added and archived');
is_same(count($was['students']), $filled($was['students'], 'level_id', null), 'every child is in a level, as the step after the files leaves them');
is_same(4, count(array_unique(array_column($was['students'], 'level_id'))), 'and there are children in all four, the archived one included');
is_same(2, $filled($was['students'], 'age_group_id', null), 'two children are pinned to a band, and every other child is not');
is_same([(int)$pins['written']['levels']['archived'], (int)$pins['written']['age_groups']['youth']],
        [(int)($studentOf($was, 51)['level_id'] ?? 0), (int)($studentOf($was, 51)['age_group_id'] ?? 0)],
        'Lisa is in the archived level and pinned to „Jugend“');
is_same((int)$pins['written']['age_groups']['archived'], (int)($studentOf($was, 60)['age_group_id'] ?? 0), 'Jakob is pinned to the archived band');
ok($filled($was['students'], 'birth_date', null) >= 4, 'children have birth dates to keep: ' . $filled($was['students'], 'birth_date', null));
is_same([['students', 'student_age_group', 'age_groups', 'SET NULL'], ['students', 'student_level', 'levels', 'SET NULL']], $keyRows($touching($was['keys'], $lists)),
        'the only keys that touch either list are students’ two, under the names 008 gave them');
is_same([['unique' => false, 'columns' => ['age_group_id']], ['unique' => false, 'columns' => ['level_id']]],
        [$was['indexes']['students']['student_age_group'] ?? null, $was['indexes']['students']['student_level'] ?? null],
        'the engine made an index for each key, under its name and on its column alone');
is_same(['students', 'levels', 'age_groups'], array_column($was['lines']['versions'], 'entity'),
        'the change log has a line about a child’s level and band, one about a level and one about a band');
is_same(['level.saved', 'age_group.saved'], array_column($was['lines']['audit'], 'action'), 'and the audit log an entry about saving a level and a band');

case_('038 takes the pinned band from every child, with its key, and nothing else');
is_same($listed($was['columns']['students'], ['age_group_id']), $is['columns']['students'], 'students loses age_group_id, and keeps every other column in its order');
ok(in_array('level_id', $is['columns']['students'], true) && in_array('birth_date', $is['columns']['students'], true),
   'level_id and birth_date, from which the band is worked out, among them');
is_same($except($was['indexes']['students'], ['student_age_group']), $is['indexes']['students'],
        'the index the engine made for the key goes with its column, and every other index stays, the level’s among them');
is_same(array_values(array_filter($was['keys'], fn($k) => $k['name'] !== 'student_age_group')), $is['keys'],
        'the key gone is student_age_group, and every other key is as it was');
is_same([['students', 'student_level', 'levels', 'SET NULL']], $keyRows($touching($is['keys'], $lists)), 'so the one key left that touches either list is the level’s');
is_same($less($was['students'], ['age_group_id']), $is['students'], 'every child has every other value they had, their level and their birth date included, and none is lost');
is_same($except($was['sums'], ['students']), $except($is['sums'], ['students']), 'every other table has every row it had, to the engine’s checksum');
is_same([$was['tables'], $was['rows']], [$is['tables'], $is['rows']], 'the same tables, and as many rows in each');
is_same([$was['accounts'], $was['columns']['accounts'], $was['indexes']['accounts']], [$is['accounts'], $is['columns']['accounts'], $is['indexes']['accounts']],
        'and every login, with its columns and indexes, as it was');

case_('038 leaves the levels and the age groups with every row and every column');
is_same($was['lists'], $is['lists'], 'levels and age_groups have every column and every row they had, each value included');
is_same([$was['sums']['levels'] ?? 'missing', $was['sums']['age_groups'] ?? 'missing'], [$is['sums']['levels'] ?? 'gone', $is['sums']['age_groups'] ?? 'gone'],
        'and the engine’s checksum of each is the same');

case_('038 leaves the change log’s and the audit log’s lines about levels, bands and pins as they were written [ADR 0026 §8]');
is_same($was['lines'], $is['lines'], 'every one, those that name age_group_id included, so the history page has them to read');

case_('038 lowers no guarded count, so the update’s guard stays as it is');
is_same($was['counts'], $is['counts'], 'every table in schema_guarded_tables() has as many rows as before');
ok(in_array('students', schema_guarded_tables(), true) && ($was['counts']['students'] ?? 0) > 0, 'students is guarded and had rows to lose');

case_('038 is one statement, so it cannot stop inside itself');
is_same(1, $pins['statements'], 'one statement');

case_('Run a second time, 038 is refused by the engine and changes nothing');
// As 035 and 036: MySQL 8.0 has no DROP FOREIGN KEY IF EXISTS and no DROP COLUMN
// IF EXISTS, so what matters is that the second run stops there, with the portal
// closed and the copy in place.
is_same(['42000', 1091], $pins['refused'] ?? null,
        'it runs again on a connection of its own, as the next page view would, and the engine refuses it because what it drops is gone (1091, SQLSTATE 42000)');
is_same($is, $pins['again'] ?? null, 'and the tables, rows, checksums, keys, columns, indexes, lists and lines are as one run left them');

// ---------------------------------------------------------------------------
// 039: everybody signs in by their own address again (ADR 0030 §2). The username
// column and its index go, and a login that signed in by a username - the only
// kind without an address that is not a placeholder, invited, active or
// suspended - becomes one, with no password and not set up, and holding nothing
// a placeholder made any other way could not: no place in a chat, no notice and
// no read mark (security finding F1). It is held to exactly that: every other
// value of every login byte for byte, every other login's places, notices and
// read marks, the chats and their messages, the count of every guarded table,
// every other table to the engine's checksum, and levels, age groups and every
// child as they were.
$ad = $after['addresses'];
[$was, $is] = [$ad['before'], $ad['after']];
$loginOf = fn(array $state, int $id): array => array_column($state['accounts'], null, 'id')[$id] ?? [];
$byUsername = array_map(fn(string $key): int => (int)$ad['written'][$key], ['username_invited', 'username_active', 'username_suspended']);
[$lea, $trainer, $leasChat] = [(int)$ad['written']['username_active'], (int)$ad['written']['trainer'], (int)$ad['written']['chat']];
[$family, $familysChat, $leasGroup] = [(int)$ad['written']['family'], (int)$ad['written']['family_chat'], (int)$ad['written']['course_group']];
// What 039 takes from a login, besides its username, and leaves of every other.
$held = ['participants', 'notifications', 'reads'];
$heldTables = ['thread_participants', 'notifications', 'thread_reads'];
// A login's value by its column, or a word when the column is missing: a NULL the
// harness read must not pass for a column it did not find.
$valueOf = fn(array $login, string $column): mixed => array_key_exists($column, $login) ? $login[$column] : '«missing»';
$facts = fn(array $login): array => [$valueOf($login, 'email'), $valueOf($login, 'state'), $valueOf($login, 'password_hash'), $valueOf($login, 'verified_at')];
// What 039 makes of a login without an address: a placeholder, with no password,
// not set up - its columns in their order, which is_same() compares too.
$asPlaceholders = fn(array $rows): array => array_map(fn(array $row): array =>
    array_key_exists('email', $row) && $row['email'] === null && ($row['state'] ?? '') !== 'placeholder'
        ? array_replace($row, ['state' => 'placeholder', 'password_hash' => null, 'verified_at' => null]) : $row, $rows);
// The logins 039 converts, read from a state before it: no address, not a placeholder.
$convertedIn = fn(array $state): array => array_map('intval', array_column(array_filter($state['accounts'],
    fn(array $a): bool => array_key_exists('email', $a) && $a['email'] === null && ($a['state'] ?? '') !== 'placeholder'), 'id'));
// Places in chats, or notices, less those of the given logins: what 039 leaves.
$keptFrom = fn(array $rows, array $logins): array => array_values(array_filter($rows, fn(array $row): bool => !in_array((int)$row['account_id'], $logins, true)));
$whose = fn(array $rows): array => array_map(fn(array $row): int => (int)$row['account_id'], $rows);
// Who is in a chat, in order of their ids.
$inChat = fn(array $state, int $chat): array => (function (array $ids): array { sort($ids); return $ids; })(
    array_map(fn(array $p): int => (int)$p['account_id'], array_filter($state['participants'], fn(array $p): bool => (int)$p['thread_id'] === $chat)));

case_('Before 039 the portal has three logins that sign in by a username, one of them in a chat with a notice, beside address logins, a placeholder and the staff');
ok(in_array('username', $was['columns']['accounts'], true), 'accounts has the username column 028 added');
is_same(['unique' => true, 'columns' => ['username']], $was['indexes']['accounts']['account_username'] ?? null, 'under the unique index account_username');
is_same([null, 'invited', null, null, 'noah.steiner'], [...$facts($loginOf($was, $byUsername[0])), $valueOf($loginOf($was, $byUsername[0]), 'username')],
        'one login invited by username, not yet signed in: no address, no password, not set up');
$active = $loginOf($was, $lea);
is_same([null, 'active', 'lea.brunner'], [$valueOf($active, 'email'), $valueOf($active, 'state'), $valueOf($active, 'username')], 'one set up by username and active');
ok(is_string($active['password_hash'] ?? null) && is_string($active['verified_at'] ?? null), 'with the password its holder set and the time it was set up');
$suspended = $loginOf($was, $byUsername[2]);
is_same([null, 'suspended', 'tom.berger'], [$valueOf($suspended, 'email'), $valueOf($suspended, 'state'), $valueOf($suspended, 'username')],
        'and one set up by username, then suspended by staff');
is_same($byUsername, array_map('intval', array_column(array_filter($was['accounts'], fn(array $a): bool => $a['username'] !== null), 'id')), 'they are the only logins with a username');
is_same($byUsername, $convertedIn($was), 'and the only logins without an address that are not placeholders');
is_same([null, 'placeholder', null, null], $facts($loginOf($was, (int)$ad['written']['placeholder'])), 'a placeholder, without an address, which is what it is already');
ok(count(array_filter($was['accounts'], fn(array $a): bool => $a['email'] !== null && $a['state'] === 'invited')) >= 1, 'an invited address login');
ok(count(array_filter($was['accounts'], fn(array $a): bool => $a['email'] !== null && $a['state'] === 'active' && $a['role'] !== 'student')) >= 1,
   'and the staff, who sign in by address');
is_same(array_values(array_unique([min($trainer, $lea), max($trainer, $lea)])), $inChat($was, $leasChat), 'Lea and the trainer are in a chat together');
ok(in_array($lea, $whose($was['notifications']), true) && in_array($trainer, $whose($was['notifications']), true), 'each with a notice about it in the bell');
is_same(null, $valueOf($loginOf($was, $family), 'username'), 'and a family that signs in by address');
ok($valueOf($loginOf($was, $family), 'email') !== null, 'with its address');
is_same(array_values(array_unique([min($trainer, $family), max($trainer, $family)])), $inChat($was, $familysChat), 'is in a chat of its own with the trainer');
ok(in_array($family, $whose($was['notifications']), true), 'with a notice about it too');
$marks = fn(array $state, int $login): array => array_map(fn(array $r): int => (int)$r['thread_id'],
    array_values(array_filter($state['reads'], fn(array $r): bool => (int)$r['account_id'] === $login)));
is_same([$leasChat, $leasGroup], $marks($was, $lea), 'Lea has read her chat, and her course group up to the trainer\'s latest message');
is_same([$leasChat, $familysChat], $marks($was, $trainer), 'the trainer has read both chats');
is_same([$familysChat], $marks($was, $family), 'and the family its own');
is_same([], array_values(array_intersect($heldTables, schema_guarded_tables())),
        'none of places in chats, notices and read marks is on the update\'s guard, so taking some does not refuse the update');
ok(in_array('threads', schema_guarded_tables(), true) && in_array('messages', schema_guarded_tables(), true), 'while the chats and their messages are');

case_('039 turns the three username logins into placeholders holding nothing a placeholder could not, drops the column and its index, and changes nothing else [ADR 0030 §2, F1]');
is_same($listed($was['columns']['accounts'], ['username']), $is['columns']['accounts'], 'accounts loses username, and keeps every other column in its order');
is_same($except($was['indexes']['accounts'], ['account_username']), $is['indexes']['accounts'], 'and account_username, and keeps every other index, the address’s among them');
foreach ($byUsername as $id)
    is_same([null, 'placeholder', null, null], $facts($loginOf($is, $id)), 'login ' . $id . ' is a placeholder: no address, no password, not set up');
is_same($asPlaceholders($less($was['accounts'], ['username'])), $is['accounts'],
        'every login has every value it had but the username; the three that signed in by one are placeholders, and no other login changed by a byte');
is_same(count($was['accounts']), count($is['accounts']), 'and not one login is lost');
is_same([], array_values(array_intersect($byUsername, $whose($is['participants']))), 'none of the three is in any chat any more');
is_same($keptFrom($was['participants'], $byUsername), $is['participants'], 'and every other place in every chat is as it was, the trainer\'s in Lea\'s chat among them');
is_same([], array_values(array_intersect($byUsername, $whose($is['notifications']))), 'none of the three has a notice');
is_same($keptFrom($was['notifications'], $byUsername), $is['notifications'], 'and every other notice is as it was, the trainer\'s about Lea\'s chat among them');
is_same([[$trainer], $inChat($was, $familysChat)], [$inChat($is, $leasChat), $inChat($is, $familysChat)],
        'Lea\'s chat keeps the trainer; the family that signs in by address keeps its chat whole');
ok(in_array($family, $whose($is['notifications']), true), 'and its notice');
is_same([], array_values(array_intersect($byUsername, $whose($is['reads']))), 'none of the three has a read mark: Lea\'s course group is unread for whoever holds her login next');
is_same($keptFrom($was['reads'], $byUsername), $is['reads'], 'and every other read mark is as it was, the trainer\'s and the family\'s among them');
is_same([$was['sums']['threads'] ?? 'missing', $was['sums']['messages'] ?? 'missing'], [$is['sums']['threads'] ?? 'gone', $is['sums']['messages'] ?? 'gone'],
        'Lea\'s chat and both its messages are still there, to the engine\'s checksum');
is_same($was['counts'], $is['counts'], 'every guarded table has as many rows as before, accounts, threads and messages among them');
is_same($except($was['sums'], ['accounts', ...$heldTables]), $except($is['sums'], ['accounts', ...$heldTables]),
        'every other table has every row it had, to the engine’s checksum, the course group and its enrolment among them');
is_same([$was['lists'], $was['students'], $was['tables'], $was['keys'], $was['columns']['students'], $was['indexes']['students']],
        [$is['lists'], $is['students'], $is['tables'], $is['keys'], $is['columns']['students'], $is['indexes']['students']],
        'levels, age groups, every child, every table and every key are as they were');
is_same(5, $ad['statements'], 'five statements: the UPDATE and the three DELETEs, which can run twice, first; the ALTER, which cannot, last');

case_('Run a second time, 039 is refused by the engine at its ALTER and changes nothing; stopped after each statement and started again, it ends as one run does');
is_same(['42000', 1091], $ad['refused'],
        'run again on a connection of its own, the UPDATE and the DELETEs find nothing to change and the engine refuses the ALTER: what it drops is gone (1091, SQLSTATE 42000)');
is_same($is, $ad['again'], 'and nothing changed: the same logins, places, notices, read marks, columns, indexes, checksums, keys, lists and counts');
is_same([1, 2, 3, 4], array_keys($ad['retried'] ?? []), 'stopped after each statement but the last');
foreach ($ad['retried'] ?? [] as $stopped => $round) {
    $when = 'stopped after statement ' . $stopped . ' of 5: ';
    $byThen = $round['stopped'];
    ok(in_array('username', $byThen['columns']['accounts'], true), $when . 'the column is still there');
    foreach ($byUsername as $id)
        is_same([null, 'placeholder', null, null], $facts($loginOf($byThen, $id)), $when . 'login ' . $id . ' is a placeholder already');
    is_same($stopped >= 2, !in_array($lea, $whose($byThen['participants']), true), $when . ($stopped >= 2 ? 'Lea\'s place in the chat is gone' : 'Lea\'s place in the chat is still there'));
    is_same($stopped >= 4, $marks($byThen, $lea) === [], $when . ($stopped >= 4 ? 'Lea\'s read marks are gone' : 'Lea\'s read marks are still there'));
    $again = $round['restarted'];
    is_same($less($byThen['accounts'], ['username']), $again['accounts'], $when . 'started again from the first statement, every login as after the stop, less the username');
    is_same([$is['participants'], $is['notifications'], $is['reads']], [$again['participants'], $again['notifications'], $again['reads']],
            $when . 'the places, notices and read marks of one run');
    is_same([$is['columns']['accounts'], $is['indexes']['accounts']], [$again['columns']['accounts'], $again['indexes']['accounts']], $when . 'with the columns and indexes of one run');
    is_same($except($byThen['sums'], ['accounts', ...$heldTables]), $except($again['sums'], ['accounts', ...$heldTables]), $when . 'and every other table as it was');
}

case_('After 039 a login written the way the portal writes one, naming no username, is taken, twice');
is_same([null, null], $ad['two_new'], 'two logins on addresses of their own, naming only name, address, role and time: the engine takes both');

// ---------------------------------------------------------------------------
// 040: a child has a picture again, and the family decides whether the course
// sees it (ADR 0031 §2). It adds two columns to students and writes nothing, so
// it is held to exactly that: the two columns where the record puts them, with
// their types and defaults; '' and 0 on every child; every other value of every
// child and every login byte for byte; every other table to the engine's
// checksum; every count. And a child written the way the previous version
// writes one is still taken: the children already there would read '' and 0
// without the defaults too, because the engine fills a new column with its
// type's empty value, so that insert is what a missing default breaks.
$fc = $after['faces'];
[$was, $is] = [$fc['before'], $fc['after']];
$faceColumns = ['picture_name', 'course_sees_picture'];
$distinct = function (array $values): array { $values = array_values(array_unique($values)); sort($values); return $values; };
// What kind of login a child has: its state, and whether it signs in by address.
$loginKind = function (array $state, array $child) use ($loginOf): string {
    if (!array_key_exists('account_id', $child)) return 'no such child';
    if ($child['account_id'] === null) return 'none';
    $login = $loginOf($state, (int)$child['account_id']);
    return $login === [] ? 'missing' : $login['state'] . ($login['email'] === null ? '' : ' by address');
};

case_('Before 040 the portal has children of every kind, with their logins, and no column for a picture');
is_same(['active', 'ended', 'paused', 'trial'], $distinct(array_column($was['students'], 'status')), 'children active, on trial, paused and ended');
is_same(['active by address', 'invited by address', 'none', 'placeholder', 'suspended by address'],
        $distinct(array_map(fn(array $child): string => $loginKind($was, $child), $was['students'])),
        'whose families are set up by address, invited or suspended, on placeholders, and without a login');
is_same(array_fill(0, 3, 'placeholder'), array_map(fn(string $key): string => $loginKind($was, $studentOf($was, (int)$fc['written'][$key . '_student'])),
                                                   ['username_invited', 'username_active', 'username_suspended']),
        'the three whose logins 039 made placeholders among them');
$example = $studentOf($was, (int)$fc['written']['example']);
is_same([1, 1], [$example['is_demo'] ?? 'missing', $loginOf($was, (int)($example['account_id'] ?? 0))['is_demo'] ?? 'missing'],
        'and an example child on an example login, as demo_fill() makes them');
$full = $studentOf($was, (int)$fc['written']['every_value']);
ok($full !== [] && !in_array(null, $full, true), 'one child has a value in every column, so no column is compared only as NULL');
ok(str_contains((string)($full['internal_notes'] ?? ''), "\u{1F95C}") && str_contains((string)($full['internal_notes'] ?? ''), '\\'),
   'its notes with a character of four bytes, quotes and a backslash');
is_same([null, null], [$was['facts']['picture_name'], $was['facts']['course_sees_picture']], 'students has neither picture_name nor course_sees_picture yet');

case_('040 adds a picture’s name and the course’s yes right after the last name, with their types and defaults, and gives every child \'\' and 0 [ADR 0031 §2]');
$lastNameAt = (int)($is['facts']['last_name']['position'] ?? 0);
is_same(['type' => 'varchar(40)', 'nullable' => 'NO', 'default_value' => '', 'collation' => $is['facts']['last_name']['collation'] ?? 'missing', 'position' => $lastNameAt + 1],
        $is['facts']['picture_name'], 'picture_name: up to 40 characters, never NULL, \'\' by default, in the collation of the names beside it, right after last_name');
$flag = $is['facts']['course_sees_picture'] ?? [];
// Plain TINYINT, as every flag is: MariaDB reports it as tinyint(4), MySQL 8.0.19 and later as tinyint, and both TINYINT(1) as tinyint(1).
ok(in_array($flag['type'] ?? null, ['tinyint(4)', 'tinyint'], true), 'course_sees_picture is a plain TINYINT like every other flag, not TINYINT(1): ' . ($flag['type'] ?? 'missing'));
is_same(['nullable' => 'NO', 'default_value' => '0', 'collation' => null, 'position' => $lastNameAt + 2], array_diff_key($flag, ['type' => 1]),
        'course_sees_picture: never NULL, 0 by default, right after picture_name');
is_same(array_merge(array_slice($was['columns']['students'], 0, $lastNameAt), $faceColumns, array_slice($was['columns']['students'], $lastNameAt)),
        $is['columns']['students'], 'and every other column of students is there, in its order');
is_same(array_fill(0, count($was['students']), ''), array_column($is['students'], 'picture_name'), 'every child has no picture');
is_same(array_fill(0, count($was['students']), 0), array_column($is['students'], 'course_sees_picture'), 'and no yes for the course, which nobody has given');

case_('040 changes nothing else: every child and every login byte for byte, every other table, key, index and count');
is_same($was['students'], $less($is['students'], $faceColumns), 'every child has every value they had, the one with every column filled among them, and none is lost or added');
is_same([$was['accounts'], $was['columns']['accounts'], $was['indexes']['accounts']], [$is['accounts'], $is['columns']['accounts'], $is['indexes']['accounts']],
        'every login, with its columns and indexes, is as it was');
is_same($except($was['sums'], ['students']), $except($is['sums'], ['students']), 'every other table has every row it had, to the engine’s checksum');
is_same([$was['tables'], $was['rows'], $was['keys'], $was['indexes']['students']], [$is['tables'], $is['rows'], $is['keys'], $is['indexes']['students']],
        'the same tables, as many rows in each, the same keys, and on students the same indexes and no new one');

case_('040 removes no row, so the update’s guard has nothing to compare, and students stays on it');
is_same($was['counts'], $is['counts'], 'every table in schema_guarded_tables() has as many rows as before');
ok(in_array('students', schema_guarded_tables(), true) && ($was['counts']['students'] ?? 0) > 0, 'students is guarded and had rows to lose: ' . ($was['counts']['students'] ?? 0));

case_('After 040 a child written the way the previous version writes one is still taken, with \'\' and 0');
is_same(null, $fc['previous_version_before']['refused'], 'before 040 the engine takes placeholder_login()’s login and create_student()’s insert as they stood at 039');
is_same(null, $fc['previous_version']['refused'], 'after 040 it takes them too, though the insert names neither column: refused with ' . $refusedWith($fc['previous_version']['refused']));
is_same(['', 0], [$fc['previous_version']['child']['picture_name'] ?? 'missing', $fc['previous_version']['child']['course_sees_picture'] ?? 'missing'],
        'and the child reads \'\' and 0');

case_('040 is one statement, so it cannot stop inside itself');
is_same(1, $fc['statements'], 'one statement');

case_('Run a second time, 040 is refused by the engine and changes nothing');
// As 031: MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so what matters is that the
// second run stops there, with the portal closed and the copy in place.
is_same(['42S21', 1060], $fc['refused'],
        'run again on a connection of its own, as the next page view would, the engine refuses it: the column is there already (1060, SQLSTATE 42S21)');
is_same($is, $fc['again'], 'and the tables, rows, checksums, keys, columns, indexes, children and logins are as one run left them');

// ---------------------------------------------------------------------------
// 041: a team member has a picture, on the login (ADR 0031). It adds one column
// to accounts and writes nothing, so it is held to exactly that: the column right
// after the name, with its type and default; '' on every login; every other
// value of every login and every child byte for byte; every other table to the
// engine's checksum; every count. And a login written by each of the previous
// version's four inserts is still taken: as with 040, the logins already there
// would read '' without the default too.
$tm = $after['team'];
[$was, $is] = [$tm['before'], $tm['after']];
$kindOfLogin = fn(array $login): string => $login['role'] . ' ' . $login['state'] . ($login['email'] === null ? '' : ' by address');

case_('Before 041 the portal has logins of every kind, the team’s and the families’, and no column for a login’s picture');
is_same(['admin active by address', 'manager active by address', 'student active by address', 'student invited by address', 'student placeholder',
         'student suspended by address', 'trainer active by address', 'trainer invited by address', 'trainer suspended by address'],
        $distinct(array_map($kindOfLogin, $was['accounts'])),
        'an administrator, a manager, trainers set up, invited and suspended, and families set up, invited and suspended by address or on placeholders');
$administrator = $loginOf($was, (int)$tm['written']['administrator']);
ok($administrator !== [] && !in_array(null, $administrator, true), 'one login has a value in every column, so no column is compared only as NULL');
is_same([1, 1], [(int)($loginOf($was, (int)$tm['written']['example_trainer'])['is_demo'] ?? 0),
                 (int)($loginOf($was, (int)($studentOf($was, (int)$tm['written']['example'])['account_id'] ?? 0))['is_demo'] ?? 0)],
        'and an example login on each side, a trainer’s and a child’s');
is_same(null, $was['facts']['picture_name'], 'accounts has no picture_name yet');
ok(in_array('picture_name', $was['columns']['students'], true), 'while students has the one 040 gave it');

case_('041 adds a picture’s name right after the name of every login, with its type and default, and gives every login \'\' [ADR 0031]');
$nameAt = (int)($is['facts']['name']['position'] ?? 0);
is_same(['type' => 'varchar(40)', 'nullable' => 'NO', 'default_value' => '', 'collation' => $is['facts']['name']['collation'] ?? 'missing', 'position' => $nameAt + 1],
        $is['facts']['picture_name'], 'picture_name: up to 40 characters, never NULL, \'\' by default, in the collation of the name, right after it');
is_same(array_merge(array_slice($was['columns']['accounts'], 0, $nameAt), ['picture_name'], array_slice($was['columns']['accounts'], $nameAt)),
        $is['columns']['accounts'], 'and every other column of accounts is there, in its order');
is_same(array_fill(0, count($was['accounts']), ''), array_column($is['accounts'], 'picture_name'), 'every login has no picture: the team’s, the families’ and the placeholders’');

case_('041 changes nothing else: every login and every child byte for byte, every other table, key, index and count');
is_same($was['accounts'], $less($is['accounts'], ['picture_name']), 'every login has every value it had, the one with every column filled among them, and none is lost or added');
is_same([$was['students'], $was['columns']['students'], $was['indexes']['students']], [$is['students'], $is['columns']['students'], $is['indexes']['students']],
        'every child is as it was, with the picture’s name and the course’s yes 040 gave them');
is_same($except($was['sums'], ['accounts']), $except($is['sums'], ['accounts']), 'every other table has every row it had, to the engine’s checksum');
is_same([$was['tables'], $was['rows'], $was['keys'], $was['indexes']['accounts']], [$is['tables'], $is['rows'], $is['keys'], $is['indexes']['accounts']],
        'the same tables, as many rows in each, the same keys, and on accounts the same indexes and no new one');

case_('041 removes no row, so the update’s guard has nothing to compare, and accounts stays on it');
is_same($was['counts'], $is['counts'], 'every table in schema_guarded_tables() has as many rows as before');
ok(in_array('accounts', schema_guarded_tables(), true) && ($was['counts']['accounts'] ?? 0) > 0, 'accounts is guarded and had rows to lose: ' . ($was['counts']['accounts'] ?? 0));

case_('After 041 a login written by each of the previous version’s inserts is still taken, with \'\'');
is_same(null, $tm['previous_version_before']['refused'],
        'before 041 the engine takes invite_login()’s, placeholder_login()’s, create_admin_account()’s and demo_fill()’s inserts as they stood at 040');
is_same(null, $tm['previous_version']['refused'], 'after 041 it takes them too, though none names the column: refused with ' . $refusedWith($tm['previous_version']['refused']));
is_same(['invitation' => '', 'placeholder' => '', 'first_administrator' => '', 'example' => ''],
        array_map(fn(array $login): string => (string)($login['picture_name'] ?? 'missing'), $tm['previous_version']['logins'] ?? []), 'and each login reads \'\'');

case_('041 is one statement, so it cannot stop inside itself');
is_same(1, $tm['statements'], 'one statement');

case_('Run a second time, 041 is refused by the engine and changes nothing');
is_same(['42S21', 1060], $tm['refused'],
        'run again on a connection of its own, as the next page view would, the engine refuses it: the column is there already (1060, SQLSTATE 42S21)');
is_same($is, $tm['again'], 'and the tables, rows, checksums, keys, columns, indexes, logins and children are as one run left them');

// ---------------------------------------------------------------------------
// The update this release brings, then one with a mistake in it, through the
// application's own runner, schema_apply(), on a portal the previous version
// left: 001 to 031 in its ledger, rows in everything 032 to 039 drop or change,
// children for 040 to give a picture's name and the course's yes, logins for
// 041 to give a picture's name, and the files the pictures were stored in on
// disk.
$u = $after['runner'];
// What 035 and 039 take from every login.
$accountGone = array_merge($presence, ['username']);
$ledgerOf = fn(array $state): array => array_column($state['ledger'], 'checksum', 'version');
// What this release drops, and the tables whose rows it changes: the settings and
// the ledger record the update, accounts loses five columns and gains one, and
// students loses two and gains two.
$droppedTables = array_merge($custom, $views, ['online_periods', 'contact_requests']);
$changedTables = ['settings', 'schema_migrations', 'accounts', 'students', 'thread_participants', 'notifications', 'thread_reads'];
// Children as 040 leaves them: no picture and no yes for the course, right after
// the last name - in that place, because is_same() compares the order too.
$withFaces = fn(array $rows): array => array_map(function (array $row): array {
    $at = array_search('last_name', array_keys($row), true);
    return $at === false ? $row
        : array_slice($row, 0, $at + 1) + ['picture_name' => '', 'course_sees_picture' => 0] + array_slice($row, $at + 1);
}, $rows);
// Logins as 041 leaves them: no picture, right after the name - in that place,
// because is_same() compares the order too.
$withPictureName = fn(array $rows): array => array_map(function (array $row): array {
    $at = array_search('name', array_keys($row), true);
    return $at === false ? $row : array_slice($row, 0, $at + 1) + ['picture_name' => ''] + array_slice($row, $at + 1);
}, $rows);

case_('This release’s update passes the guard on a portal with custom-field values, drops exactly what 032 to 039 drop, and adds what 040 and 041 add');
is_same('', $u['previous_step_error'], 'the portal was where the previous version’s update leaves it, its step after the files run');
ok(($u['before']['rows']['field_values'] ?? 0) > 0, 'with custom-field values for the guard to see: ' . ($u['before']['rows']['field_values'] ?? 0));
ok(($u['before']['rows']['online_periods'] ?? 0) > 0 && ($u['before']['rows']['contact_requests'] ?? 0) > 0,
   'and an online history and contact requests to drop');
ok($filled($u['before']['students'], 'age_group_id', null) > 0 && ($u['before']['rows']['levels'] ?? 0) > 0 && ($u['before']['rows']['age_groups'] ?? 0) > 0,
   'and children pinned to an age band, beside levels and bands that stay');
is_same(3, count($convertedIn($u['before'])), 'and three logins that sign in by a username, one of them in chats, with a notice and read marks');
is_same(null, $u['release']['refused'], 'schema_apply() goes through: field_values is off the guard, so 032 emptying it does not refuse the update');
is_same(true, $u['release']['current'], 'and files and database agree afterwards, so the portal opens');
is_same(1, $u['release']['backups'], 'with the copy taken before the files ran');
is_same(['032', '033', '034', '035', '036', '037', '038', '039', '040', '041'], array_map(fn(string $version): string => substr($version, 0, 3), array_keys($u['shipped'])),
        'this release’s files are 032 to 041');
is_same($u['shipped'], array_diff_key($ledgerOf($u['release']['state']), $ledgerOf($u['before'])),
        'the ledger gained each of them, with the checksum of the file shipped, and nothing else');
is_same(array_values(array_diff($u['before']['tables'], $droppedTables)), $u['release']['state']['tables'],
        'the six tables are gone, and every other table is there');
is_same($except($u['before']['sums'], array_merge($droppedTables, $changedTables)), $except($u['release']['state']['sums'], $changedTables),
        'every table but the settings, the ledger, accounts, students, places in chats, notices and read marks has every row it had, levels, age_groups, threads and messages among them: the backup, the files and the step after them changed nothing else');
is_same($withPictureName($asPlaceholders($less($u['before']['accounts'], $accountGone))), $u['release']['state']['accounts'],
        'every login has every value it had but the four 035 takes and the username 039 takes, the three that signed in by one now placeholders, and none a picture yet');
is_same(array_map(fn(string $kind): array => $keptFrom($u['before'][$kind], $convertedIn($u['before'])), $held),
        array_map(fn(string $kind): array => $u['release']['state'][$kind], $held),
        'and those three hold no place in a chat, no notice and no read mark, while every other login keeps every one it had');
is_same($withFaces($less($u['before']['students'], $childGone)), $u['release']['state']['students'],
        'and every child every value but the old picture and the pinned band, their level included, with no picture and no yes for the course in their place');
is_same($u['before']['counts'], $u['release']['state']['counts'], 'every guarded table has as many rows as before');
is_same(null, $u['release']['record'], 'and it leaves no record of an unfinished update behind: the run that passes deletes it [ADR 0027 §1]');

case_('This release’s update deletes the pictures’ files from disk, and nothing beside them [ADR 0026 §8]');
// The step after the files (database/defaults.php): in the pictures' folder, a
// stored file no problem report names is a picture left behind.
$files = $u['files'];
$onDiskBefore = $u['before']['files']['uploads'];
$onDiskAfter = $u['release']['state']['files']['uploads'];
$deleted = array_map(fn(string $name): string => 'avatar/' . $name, [...$files['account_pictures'], $files['child_picture']]);
is_same(array_fill(0, 3, 'file'), array_map(fn(string $path): ?string => $onDiskBefore[$path] ?? null, $deleted),
        'two logins’ pictures and a child’s, which the columns named, were on disk before the update');
is_same($except($onDiskBefore, $deleted), $onDiskAfter, 'afterwards those three are gone, and every other file, folder and link under the uploads is there');
is_same('file', $onDiskAfter['avatar/' . $files['screenshot']] ?? null, 'the screenshot a problem report names stays, in the same folder and as old');
is_same('file', $onDiskAfter['avatar/' . $files['child_picture_just_saved']] ?? null,
        'a child’s picture saved a moment before the update stays, for the nightly prune: the step leaves the last ten minutes alone');
is_same(array_fill(0, 3, 'file'), array_map(fn(string $name): ?string => $onDiskAfter['message/' . $name] ?? null, $files['chat']),
        'the chat’s photo, voice note and file stay, as ADR 0022 §11.4 keeps what was sent');
is_same(['file', 'folder', 'link'], [$onDiskAfter['avatar/kein-upload.txt'] ?? null, $onDiskAfter['avatar/' . str_repeat('e', 32) . '.jpg'] ?? null,
                                     $onDiskAfter['avatar/' . str_repeat('f', 32) . '.jpg'] ?? null],
        'in the pictures’ folder a file not named the way an upload is, a folder and a link stay');
is_same(true, $u['release']['state']['files']['outside'], 'and the file the link points to, outside the uploads, is still there');
is_same('', $u['release']['again']['error'], 'the step runs again, as every later update runs it, without an error');
is_same($u['release']['state']['files'], $u['release']['again']['files'], 'and deletes nothing more');

case_('This release’s update stopped between two of its files and started again by the next page view ends as the update that ran through');
// 034 to 038, 040 and 041 are one statement each, and 039 stopped inside itself
// is run above, so what is left is an update stopping between two files: a file
// that stops is put after each in turn, and the next page view runs without it.
$restarts = $u['restarted'] ?? [];
is_same(['033', '034', '035', '036', '037', '038', '039', '040'], array_map('strval', array_keys($restarts)),
        'stopped after each of 033 to 040 in turn, the last two leaving 040 and 041 to the next page view');
foreach ($restarts as $stop => $round) {
    $when = 'stopped after ' . $stop . ': ';
    $stopped = $round['stopped'];
    is_same('SchemaError', $stopped['said']['class'] ?? null, $when . 'the update stops at the file put there');
    is_same(array_values(array_filter(array_keys($u['shipped']), fn(string $version): bool => strcmp($version, $stop . '_zz') < 0)),
            array_values(array_diff(array_column($stopped['ledger'] ?? [], 'version'), array_column($u['before']['ledger'], 'version'))),
            $when . 'the ledger has this release’s files up to it, and none after');
    ok(is_array($stopped['record']['data']['counts'] ?? null), $when . 'the record of the unfinished update is kept, with the counts from before');
    is_same($round['before']['files'], $stopped['files'], $when . 'no file is deleted from disk: the step after the files runs only in a run that passes');
    $again = $round['restarted'];
    is_same(null, $again['said'], $when . 'the next page view, without that file, passes');
    is_same(null, $again['record'], $when . 'and deletes the record');
    is_same($u['release']['state']['ledger'], $again['ledger'], $when . 'the ledger ends as after the update that ran through: each file once, with its checksum');
    is_same([$u['release']['state']['tables'], $u['release']['state']['keys'], $u['release']['state']['columns'], $u['release']['state']['indexes']],
            [$again['portal']['tables'], $again['portal']['keys'], $again['portal']['columns'], $again['portal']['indexes']],
            $when . 'the same tables, keys, columns and indexes');
    is_same($except($u['release']['state']['rows'], ['settings']), $except($again['portal']['rows'], ['settings']), $when . 'as many rows in every table');
    is_same($round['before']['counts'], $again['counts'], $when . 'every guarded table has as many rows as before');
    is_same($except($round['before']['sums'], array_merge($droppedTables, $changedTables)), $except($again['portal']['sums'], $changedTables),
            $when . 'every other table has every row it had, to the engine’s checksum');
    is_same($withPictureName($asPlaceholders($less($round['before']['accounts'], $accountGone))), $again['portal']['accounts'],
            $when . 'every login every value but the four 035 takes and the username, the three username logins now placeholders, and none a picture yet');
    is_same(array_map(fn(string $kind): array => $keptFrom($round['before'][$kind], $convertedIn($round['before'])), $held),
            array_map(fn(string $kind): array => $again['portal'][$kind], $held),
            $when . 'and they hold no place in a chat, no notice and no read mark, every other login all it had');
    is_same($withFaces($less($round['before']['students'], $childGone)), $again['portal']['students'],
            $when . 'and every child every value but the old picture and the pinned band, with no picture and no yes for the course');
    is_same($u['release']['state']['files'], $again['files'], $when . 'and the files on disk end as after the update that ran through');
}

// ---------------------------------------------------------------------------
// A refused update stays refused (ADR 0027). Each mistake is one more file in
// that release, and each schema_apply() one page view. The numbers from before
// an update are kept in storage/update-unfinished.json from just before its
// first migration until a run passes; while they are, every run compares first
// and refuses on a shortfall, writes no copy, and only a run that passes
// deletes them.
$m = $u['mistake'];
$guardedBefore = $m['before']['counts'];
$contacts = $guardedBefore['contacts'] ?? 0;
$app = $u['app_version'];
$saidBy = fn(array $requests): array => array_map(fn(array $r): array => [$r['said']['de'] ?? null, $r['said']['en'] ?? null], $requests);
// The sentences of §4 for this loss, with the record's own versions and copy.
$lossDe = fn(string $lost, array $data): string => 'Nach der Aktualisierung auf Version ' . $data['to'] . ' fehlen Datensätze: ' . $lost
    . '. Deshalb bleibt das Portal geschlossen. So kommen sie zurück: zuerst die Dateien von Version ' . $data['from']
    . ' wieder hochladen, dann die Sicherung „' . $data['backup'] . '-…“ aus dem Ordner storage/backups einspielen, wie INSTALL.md unter „Wiederherstellen“ beschreibt.'
    . ' Beim nächsten Aufruf zählt das Portal nach und öffnet sich, wenn nichts mehr fehlt.';
$lossEn = fn(string $lost, array $data): string => 'Records are missing after the update to version ' . $data['to'] . ': ' . $lost
    . '. That is why the portal stays closed. To bring them back: first upload the files of version ' . $data['from']
    . ' again, then import the copy “' . $data['backup'] . '-…” from the storage/backups folder, as INSTALL.md describes under „Wiederherstellen“.'
    . ' On the next page view the portal counts again and opens once nothing is missing.';
$recordData = fn(array $state): array => (array)($state['record']['data'] ?? []) + ['from' => '?', 'to' => '?', 'backup' => '?', 'counts' => null];

case_('A migration that drops a guarded table is refused on the first page view, and on every one after it [ADR 0026 §7, 0027 §2]');
// Before 0027 the next page view found nothing pending, counted after the loss,
// compared that with itself and opened the portal with every contact gone.
ok($contacts > 0, 'contacts, which the update guards, had rows to lose: ' . $contacts);
$first = $m['requests'][1] ?? [];
ok(!in_array('contacts', $first['tables'] ?? ['contacts'], true), 'the file ran on the first page view: contacts is gone');
foreach ($m['requests'] as $n => $r)
    is_same('UpdateBlocked', $r['said']['class'] ?? null, 'page view ' . $n . ' is refused, so it gets the closed page');
$data = $recordData($first);
is_same([$lossDe('contacts (vorher ' . $contacts . ', jetzt 0)', $data), $lossEn('contacts (' . $contacts . ' before, 0 now)', $data)],
        [$first['said']['de'] ?? null, $first['said']['en'] ?? null],
        'the first says in German and English which table lost how many records, counting the dropped one as emptied, and the way back');
is_same(array_fill(1, 3, $saidBy([$first])[0]), $saidBy($m['requests']), 'and the second and third say exactly the same');
foreach ($m['requests'] as $n => $r)
    is_same($guardedBefore, $recordData($r)['counts'], 'after page view ' . $n . ' the record is there, holding the counts from before the update');
is_same([$m['before']['version'], $app], [$data['from'], $data['to']], 'it names the version the database was on and the version of the files');

case_('The record names the copy taken before the update, without the part that keeps it from being fetched [ADR 0027 §1]');
$copy = $m['copy'][0] ?? '';
is_same(1, count($m['copy']), 'the first page view took one copy: ' . $copy);
ok(preg_match('/^' . preg_quote((string)$data['backup'], '/') . '-([0-9a-f]{8})\.sql$/D', $copy, $random) === 1,
   'the record’s „backup“ is that copy’s name without its random part and extension: ' . test_show($data['backup']));
ok(isset($random[1]) && !str_contains((string)($first['record']['text'] ?? ''), $random[1]), 'and the random part appears nowhere in the file');
is_same($contacts, $m['copy_contacts'], 'the copy holds every contact the file dropped');

case_('Page views after a refusal change nothing: not the ledger, not the copies, not the stamp [ADR 0027 §2]');
foreach ([2, 3] as $n)
    is_same([$first['ledger'] ?? null, $first['copies'] ?? null, $first['stamp'] ?? null],
            [$m['requests'][$n]['ledger'] ?? null, $m['requests'][$n]['copies'] ?? null, $m['requests'][$n]['stamp'] ?? null],
            'page view ' . $n . ' leaves the ledger, the copies and the stamp as the first left them');
is_same($m['before']['stamp'], $first['stamp'] ?? null, 'and the first did not write the stamp either');

case_('A stamp and settings that match the files cannot reopen an unfinished update [ADR 0027 §2]');
// After an import with the previous files, either can match the files again
// without a single row being back.
$f = $m['forged'];
is_same(false, $f['current'], 'with schema_state() in the stamp and the fingerprint and the version in the settings, schema_is_current() is false');
is_same($f['stamp'], $f['next']['stamp'] ?? null, 'the stamp really did say the files are current');
is_same('UpdateBlocked', $f['next']['said']['class'] ?? null, 'and the next page view is refused, naming the same loss');
is_same($saidBy([$first])[0], $saidBy([$f['next']])[0], 'with the same sentences');

case_('Nothing runs on top of a loss: a newer upload’s migration waits [ADR 0027 §2]');
$newer = $m['newer'];
is_same('UpdateBlocked', $newer['said']['class'] ?? null, 'a further file that makes a table is refused');
is_same($saidBy([$first])[0], $saidBy([$newer])[0], 'for the missing contacts, before it is even looked at');
ok(!in_array('999_b_a_newer_release_makes_a_table.sql', array_column($newer['ledger'] ?? [], 'version'), true), 'it is not in the ledger');
ok(!in_array('made_by_a_newer_release', $newer['tables'] ?? [], true), 'and its table does not exist');

case_('Imported with the new files still in place, the copy is taken again, and still no second copy is written [ADR 0027 §5 a]');
$i = $m['imported'];
is_same($contacts, $i['contacts'], 'the import put every contact back');
is_same('UpdateBlocked', $i['request']['said']['class'] ?? null, 'the next page view applied the new files again and is refused again');
ok(!in_array('contacts', $i['request']['tables'] ?? ['contacts'], true), 'contacts is gone again');
is_same($first['copies'] ?? null, $i['request']['copies'] ?? [], 'no copy was written: the one from before the update is still among them');
is_same($contacts, $i['copy_contacts'], 'and it still holds every contact');

case_('A partial import keeps the portal closed, a complete one reopens it [ADR 0026 §7, 0027 §5 a]');
$p = $m['partial'];
is_same('UpdateBlocked', $p['said']['class'] ?? null, 'the previous files and the copy broken off at contacts: refused');
ok(str_contains($p['said']['de'] ?? '', 'contacts (vorher ' . $contacts . ', jetzt 0)'), 'naming contacts, which the import never reached');
ok(str_contains($p['said']['en'] ?? '', 'contacts (' . $contacts . ' before, 0 now)'), 'in both languages');
ok(!in_array('students', $p['tables'] ?? ['students'], true) && str_contains($p['said']['de'] ?? '', 'students (vorher'),
   'and every guarded table after it, which it never reached either');
$c = $m['complete'];
is_same(null, $c['said'], 'imported in full: the run passes');
is_same($guardedBefore, $c['counts'], 'every guarded table, contacts with them, has as many rows as before the update');
is_same($m['before']['ledger'], $c['ledger'], 'the ledger is the one from before the update');
is_same($m['before']['version'], $c['version'], 'the version is the one from before');
is_same($m['before']['history'], $c['history'], 'and the release history has no new entry');

case_('A run that passes deletes the record, and the next page view asks the database nothing [ADR 0027 §1]');
is_same(null, $c['record'], 'after the complete import none is left');
is_same(true, $m['afterwards']['current'], 'files and database agree');
is_same(0, $m['afterwards']['queries'], 'and the next page view takes the fast path, without a query');

case_('A release that takes a table off the guard reopens the portal its predecessor closed, without an import [ADR 0027 §5 b]');
$o = $u['off_list'];
is_same('UpdateBlocked', $o['refused']['said']['class'] ?? null, 'the file that drops contacts is refused');
is_same(null, $o['released']['said'], 'with contacts counted under a name no longer on the list - what that release looks like to the record - the run passes');
is_same(null, $o['released']['record'], 'and deletes the record');
is_same(null, $o['restored']['said'], 'the previous files and the copy then bring the contacts back, and the run passes');
is_same($guardedBefore, $o['restored']['counts'], 'with every row');

case_('A migration that deletes the rows of a guarded table, dropping nothing, is refused on every page view too [ADR 0027 §2]');
$e = $u['emptied'];
$data = $recordData($e['requests'][1] ?? []);
ok(in_array('contacts', $e['requests'][1]['tables'] ?? [], true), 'the table is still there, empty');
foreach ($e['requests'] as $n => $r)
    is_same('UpdateBlocked', $r['said']['class'] ?? null, 'page view ' . $n . ' is refused');
is_same(array_fill(1, 3, [$lossDe('contacts (vorher ' . $contacts . ', jetzt 0)', $data), $lossEn('contacts (' . $contacts . ' before, 0 now)', $data)]),
        $saidBy($e['requests']), 'each with the same German and English, naming contacts');
foreach ($e['requests'] as $n => $r)
    is_same($guardedBefore, $recordData($r)['counts'], 'after page view ' . $n . ' the record holds the counts from before');
is_same([$e['before']['version'], $app], [$data['from'], $data['to']], 'and the versions');
is_same(1, count($e['copy']), 'one copy, written by the first page view');
foreach ([2, 3] as $n)
    is_same([$e['requests'][1]['ledger'] ?? null, $e['requests'][1]['copies'] ?? null, $e['requests'][1]['stamp'] ?? null],
            [$e['requests'][$n]['ledger'] ?? null, $e['requests'][$n]['copies'] ?? null, $e['requests'][$n]['stamp'] ?? null],
            'page view ' . $n . ' changes neither the ledger, nor the copies, nor the stamp');
is_same(null, $e['restored']['said'], 'and the previous files and the copy reopen it');

case_('A migration that loses a row and then stops is not tried again on top of the loss [ADR 0027 §2]');
$h = $u['halfway'];
is_same('SchemaError', $h['requests'][1]['said']['class'] ?? null, 'the first page view stops in the file, at its second statement');
is_same($guardedBefore, $recordData($h['requests'][1] ?? [])['counts'], 'and the record of the update is written, with the counts from before');
is_same('UpdateBlocked', $h['requests'][2]['said']['class'] ?? null, 'the second is refused for the missing contact, not stopped a second time');
ok(str_contains($h['requests'][2]['said']['de'] ?? '', 'contacts (vorher ' . $contacts . ', jetzt ' . ($contacts - 1) . ')'), 'naming the one contact it lost');
is_same($contacts - 1, $h['requests'][2]['counts']['contacts'] ?? null, 'and the file did not run again: one contact is missing, not two');
is_same($h['requests'][1]['copies'] ?? null, $h['requests'][2]['copies'] ?? [], 'and it wrote no copy');
is_same(null, $h['restored']['said'], 'the previous files and the copy reopen it');

case_('A migration that stops every time writes one copy, and the copy from before stays among those kept [ADR 0027 §2]');
// Before 0027 each page view took a copy before trying again, and five later
// (BACKUP_KEEP) the copy from before the update had been pruned.
$s = $u['stopping'];
$name = '999_e_mistake_makes_a_table_and_stops.sql';
is_same(array_fill(1, count($s['requests']), 'SchemaError'), array_map(fn(array $r): ?string => $r['said']['class'] ?? null, $s['requests']),
        count($s['requests']) . ' page views, each stopped in the file');
is_same(1, count($s['copy']), 'the first wrote one copy');
foreach ($s['requests'] as $n => $r)
    if ($n > 1) is_same($s['requests'][1]['copies'] ?? null, $r['copies'] ?? [], 'page view ' . $n . ' wrote none');
ok(in_array($s['copy'][0] ?? '?', end($s['requests'])['copies'] ?? [], true), 'the copy from before the update is still there after the last');
is_same($contacts, $s['copy_contacts'], 'holding every contact');
is_same(null, $s['restored']['said'], 'and the previous files with it reopen the portal');
ok(str_contains($s['requests'][1]['said']['de'] ?? '', 'Die Sicherung von vorher liegt im Ordner storage/backups.')
   && str_contains($s['requests'][1]['said']['en'] ?? '', 'The copy taken beforehand is in storage/backups.'), 'its refusal says the copy from before is in storage/backups, which it is');

case_('A migration that stops in an update run with skip-backup sends nobody looking for a copy that was never taken');
$c = $u['uncopied'];
is_same('SchemaError', $c['request']['said']['class'] ?? null, 'the file stops');
is_same(false, $c['skip_backup_left'], 'skip-backup was used up');
is_same($c['before']['copies'], $c['request']['copies'] ?? null, 'and no copy was written');
$data = (array)($c['request']['record']['data'] ?? []);
ok(array_key_exists('backup', $data) && $data['backup'] === null, 'the record names none');
is_same(['Eine Datenbankänderung ist fehlgeschlagen: 999_g_mistake_stops_without_a_copy.sql (1/1).', 'A database change failed: 999_g_mistake_stops_without_a_copy.sql (1/1).'],
        $saidBy([$c['request']])[0], 'and the refusal names the file and the statement, and no copy');
is_same(null, $c['after']['said'], 'without the file the next page view passes');

case_('A retry reports how far the update got, not where the retry stopped');
// Every retry starts at statement 1 and stops there, on the table the first
// attempt made. Reporting that said "Statements 1 to 0 were applied".
is_same(2, $s['requests'][1]['said']['statement'] ?? null, 'the first attempt stopped at statement 2');
is_same(1, $s['requests'][2]['said']['statement'] ?? null, 'a retry at statement 1, on the table the first attempt made');
foreach ($s['requests'] as $n => $r)
    is_same($name . ': 2/2', $r['said']['summary'] ?? null, 'page view ' . $n . ' reports the update stopped at 2 of 2');
ok(str_contains($s['requests'][2]['said']['de'] ?? '', $name . ' (2/2)'), 'and the closed page says so');
ok(str_contains($s['requests'][2]['said']['log'] ?? '', 'Statements 1 to 1 were applied'), 'as does the log, which says statement 1 is applied');

case_('Without the record, nothing is migrated, and no copy is written either [ADR 0027 §1]');
// Refused after the copy, each page view wrote one more, and BACKUP_KEEP page
// views later the copies from before earlier updates had been pruned.
$w = $u['unwritable'];
$unwritableSaid = ['Vor der Aktualisierung konnte das Portal im Ordner storage nicht schreiben. Ohne die Zahlen von vorher fängt es nicht an, und es hat nichts geändert. Bitte im Dateimanager dem Ordner storage Schreibrechte geben (755) und die Seite neu laden.',
                   'Before updating, the portal could not write into the storage folder. Without the numbers from before it does not start, and it has changed nothing. Please make the storage folder writable in the file manager (755), then reload the page.'];
foreach ($w['requests'] as $n => $r) {
    is_same($unwritableSaid, $saidBy([$r])[0], 'with its place taken by a folder, page view ' . $n . ' is refused with the sentences for a record that cannot be written');
    is_same([$w['before']['ledger'], $w['before']['tables'], $w['before']['counts']],
            [$r['ledger'] ?? null, $r['tables'] ?? null, $r['counts'] ?? null], 'page view ' . $n . ' leaves the ledger, the tables and the rows as they were');
    is_same($w['before']['copies'], $r['copies'] ?? null, 'and writes no copy, so none is pruned');
}
is_same(false, $w['part_left'], 'and nothing half-written is left beside it');

case_('A record that cannot be read refuses, and nothing runs [ADR 0027 §1]');
$r = $u['unreadable'];
$unreadableSaid = ['Die Datei storage/update-unfinished.json mit den Zahlen von vor der Aktualisierung lässt sich nicht lesen. Deshalb bleibt das Portal geschlossen. Bitte die Sicherung von vorher aus dem Ordner storage/backups einspielen, wie INSTALL.md unter „Wiederherstellen“ beschreibt, und danach diese Datei löschen.',
                   'The file storage/update-unfinished.json, which holds the numbers from before the update, cannot be read. That is why the portal stays closed. Please import the copy from before the update from the storage/backups folder, as INSTALL.md describes under „Wiederherstellen“, then delete that file.'];
foreach (['broken' => 'half a JSON object', 'counts_text' => 'counts that are a sentence'] as $key => $what) {
    is_same($unreadableSaid, $saidBy([$r[$key]])[0], $what . ': refused, with the sentences for a record that cannot be read');
    is_same([$r['before']['ledger'], $r['before']['tables'], $r['before']['copies']],
            [$r[$key]['ledger'] ?? null, $r[$key]['tables'] ?? null, $r[$key]['copies'] ?? null], $what . ': nothing ran, and no copy was written');
}
is_same(null, $r['after']['said'], 'deleted, the next page view runs as before');

// ---------------------------------------------------------------------------
// A restore keeps the portal closed until its import is done (ADR 0029). Three
// states in which nothing touches the database, met the way a restore meets them
// on the portal the last round left, with its data, its ledger and its stamp.
$re = $u['restore'] ?? [];
$restoreSaid = [
    'a' => ['Gerade wird eine Sicherung eingespielt, oder das Einspielen ist abgebrochen. Solange bleibt das Portal geschlossen. Meldet phpMyAdmin, dass das Einspielen fertig ist: diese Seite neu laden. Ist es abgebrochen: dieselbe Datei in phpMyAdmin noch einmal einspielen – dabei wird nichts doppelt.',
            'A copy is being imported, or the import stopped. The portal stays closed meanwhile. Once phpMyAdmin says the import has finished: reload this page. If it stopped: import the same file again in phpMyAdmin; nothing is doubled.'],
    'b' => ['In der Datenbank fehlt die Tabelle schema_migrations, die jedes Portal hat. Wird gerade eine Sicherung eingespielt: warten, bis phpMyAdmin fertig meldet, dann diese Seite neu laden. Ist das Einspielen abgebrochen: dieselbe Datei noch einmal einspielen. Wird nichts eingespielt, nennt config/config.php eine fremde Datenbank. Das Portal hat nichts verändert.',
            "The database is missing the table schema_migrations, which every portal has. If a copy is being imported: wait until phpMyAdmin says it has finished, then reload this page. If the import stopped: import the same file again. If nothing is being imported, config/config.php names a database that is not the portal's. The portal has changed nothing."],
    'c' => ['Die Datenbank ist leer, aber in diesem Ordner lief schon ein Portal. Zum Wiederherstellen: die Sicherung in phpMyAdmin einspielen, dann diese Seite neu laden. Soll hier ein neues, leeres Portal entstehen: im Dateimanager die Datei storage/schema.stamp löschen und diese Seite neu laden. Vorsicht: Belege und Fotos des alten Portals werden dann gelöscht; seine Sicherungen in storage/backups bleiben.',
            "The database is empty, but a portal has run in this folder before. To restore: import the copy in phpMyAdmin, then reload this page. If a new, empty portal is meant to start here: delete the file storage/schema.stamp in the file manager and reload this page. Careful: the old portal's receipts and photos are deleted then; its copies in storage/backups stay."],
];
// A run refused with one of the three, and nothing changed by it: the same
// tables, ledger, copies, stamp, record, counts and files as just before it.
$refusedAs = function (string $state, array $before, array $request, string $when) use ($restoreSaid): void {
    is_same('UpdateBlocked', $request['said']['class'] ?? null, $when . 'is refused');
    is_same($restoreSaid[$state], [$request['said']['de'] ?? null, $request['said']['en'] ?? null], $when . 'with the sentences of ADR 0029 §5 (' . $state . ')');
    is_same(array_diff_key($before, ['said' => 1]), array_diff_key($request, ['said' => 1]),
            $when . 'and changes nothing: the same tables, ledger, copies, stamp, record, counts and files');
};
$fullLedger = array_column($u['release']['state']['ledger'] ?? [], 'version');
// A value by its path, or a word when a key is missing: a null the harness wrote
// must not pass for one it did not write.
$at = function (mixed $data, string ...$keys): mixed {
    foreach ($keys as $key) {
        if (!is_array($data) || !array_key_exists($key, $data)) return '«missing»';
        $data = $data[$key];
    }
    return $data;
};

case_('The portal the restore scenarios start from has data, a full ledger and a stamp, and its own copy carries the marker [ADR 0029 §2]');
$start = $re['before'] ?? [];
ok(($start['counts']['students'] ?? 0) > 0 && ($start['counts']['accounts'] ?? 0) > 0, 'children and logins to lose: ' . ($start['counts']['students'] ?? 0) . ' and ' . ($start['counts']['accounts'] ?? 0));
is_same($u['release']['state']['ledger'], $start['ledger'] ?? null, 'the ledger as the update that ran through left it');
ok(is_string($start['stamp'] ?? null) && $start['stamp'] !== '', 'and the stamp a run that passed wrote');
is_same(null, $at($start, 'record'), 'with no update unfinished');
ok(in_array($re['copy'] ?? '?', $re['copied']['copies'] ?? [], true), 'the portal wrote a copy of itself: ' . ($re['copy'] ?? '?'));
is_same(3, $re['importing']['marker_at'] ?? null, 'whose first statement after the three SET lines makes the marker');
$stops = $re['importing']['stops'] ?? [];
is_same(['marker', 'accounts', 'payment_proofs', 'ledger', 'before_last'], array_keys($stops), 'and which is stopped at five points');
is_same(array_values($stops), array_values(array_unique($stops)), 'each further on than the one before');
is_same(array_values($stops), (function (array $s): array { sort($s); return $s; })(array_values($stops)), 'in that order');
is_same(($re['copy_statements'] ?? 0) - 1, $stops['before_last'] ?? null, 'the last of them with only the final statement to go');
ok(in_array('payment_proofs', $re['importing']['order'] ?? [], true) && in_array('schema_migrations', $re['importing']['order'] ?? [], true),
   'the copy holds payment_proofs and the ledger among its tables');

case_('A database with data but no ledger is refused, and nothing is made [ADR 0029 §1 (b)]');
$nl = $re['no_ledger'] ?? [];
is_same(null, $at($nl, 'before', 'ledger'), 'the ledger was dropped from under the data');
ok(($nl['before']['counts']['students'] ?? 0) > 0, 'which is still there');
$refusedAs('b', $nl['before'] ?? [], $nl['request'] ?? [], 'a page view\'s run ');
ok(!in_array('schema_migrations', $nl['request']['tables'] ?? ['schema_migrations'], true), 'it did not make the ledger\'s table');
ok(str_contains($nl['request']['said']['log'] ?? '', 'students ' . ($nl['before']['counts']['students'] ?? -1)), 'the log line names the tables with rows, students among them');
is_same([], $nl['empty_ledger']['ledger'] ?? null, 'with the ledger\'s table there but empty');
$refusedAs('b', $nl['empty_ledger_before'] ?? [], $nl['empty_ledger'] ?? [], 'the run ');

case_('An empty database over a folder a portal has used is refused, by a page view\'s run and by setup\'s, and nothing is written [ADR 0029 §1 (c)]');
$em = $re['emptied'] ?? [];
is_same([], $em['before']['tables'] ?? null, 'every table was deleted, as INSTALL.md\'s restore begins');
is_same($start['stamp'] ?? null, $em['before']['stamp'] ?? null, 'with the stamp still there');
$refusedAs('c', $em['before'] ?? [], $em['request'] ?? [], 'a page view\'s run ');
$refusedAs('c', $em['before'] ?? [], $em['install'] ?? [], 'setup\'s run, with the safeguards waived, ');
ok(str_contains($em['request']['said']['log'] ?? '', 'schema.stamp'), 'the log line names the stamp');
is_same([], $em['install']['tables'] ?? null, 'afterwards no table exists, schema_migrations included');
is_same($em['before']['copies'] ?? null, $em['install']['copies'] ?? 'changed', 'storage/backups holds the same files');
is_same($em['before']['files'] ?? null, $em['install']['files'] ?? 'changed', 'and every upload is still there: the step after the files never ran');

case_('A copy keeps the portal closed from its first statement to its last, and its last statement opens it [ADR 0029 §1 (a), §4]');
$im = $re['importing'] ?? [];
foreach (['marker' => 'after the marker is made', 'accounts' => 'after accounts', 'payment_proofs' => 'after payment_proofs',
          'ledger' => 'after the ledger\'s last row', 'before_last' => 'with only the final DROP to go'] as $stop => $what) {
    $round = $im['rounds'][$stop] ?? [];
    is_same(null, $at($round, 'import_error'), 'the copy ran ' . $what);
    ok(in_array(IMPORT_UNFINISHED_TABLE, $round['before']['tables'] ?? [], true), $what . ', the marker is there');
    $refusedAs('a', $round['before'] ?? [], $round['request'] ?? [], $what . ', a run ');
    ok(str_contains($round['request']['said']['log'] ?? '', IMPORT_UNFINISHED_TABLE), $what . ', the log line names the table');
}
is_same([IMPORT_UNFINISHED_TABLE], $im['rounds']['marker']['before']['tables'] ?? null, 'after the marker nothing else exists');
$refusedAs('a', $im['rounds']['marker']['before'] ?? [], $im['rounds']['marker']['install'] ?? [], 'and setup\'s run, with the safeguards waived, ');
is_same($fullLedger, array_values(array_intersect(array_column($im['rounds']['ledger']['before']['ledger'] ?? [], 'version'), $fullLedger)),
        'after the ledger\'s last row the ledger is full, which alone would let the run through');
ok(!in_array('settings', $im['rounds']['ledger']['before']['tables'] ?? ['settings'], true), 'while settings has not arrived yet');
is_same(null, $at($im, 'last_error'), 'the last statement runs');
$done = $im['done'] ?? [];
ok(array_key_exists('said', $done) && $done['said'] === null, 'and the next run passes: the portal opens by itself');
ok(!in_array(IMPORT_UNFINISHED_TABLE, $done['tables'] ?? [IMPORT_UNFINISHED_TABLE], true), 'the marker is gone');
ok(is_string($done['stamp'] ?? null) && $done['stamp'] !== '', 'the stamp is written');
is_same(null, $at($done, 'record'), 'no update is unfinished');
is_same($start['copies'] ?? null, array_values(array_diff($done['copies'] ?? [], [$re['copy'] ?? ''])), 'and no copy was written during any of it');
is_same($start['counts'] ?? null, $done['counts'] ?? null, 'every guarded table has as many rows as the portal had');
is_same($except($re['portal']['sums'] ?? [], ['settings']), $except($done['portal']['sums'] ?? [], ['settings']),
        'and every table but the settings, which the run marks, has every row it had, to the engine\'s checksum');

case_('An import that stopped keeps the portal closed, and the same copy imported again opens it with every row once [ADR 0029 §4]');
$ag = $re['again'] ?? [];
is_same(null, $at($ag, 'first_error'), 'the copy ran up to payment_proofs and stopped');
$refusedAs('a', $ag['stopped_before'] ?? [], $ag['stopped'] ?? [], 'the run ');
is_same(null, $at($ag, 'second_error'), 'the same copy, imported again from its first statement, runs through: the marker is made IF NOT EXISTS, and each table dropped before it is made');
ok(array_key_exists('said', $ag['done'] ?? []) && $ag['done']['said'] === null, 'then the run passes');
is_same($start['counts'] ?? null, $ag['done']['counts'] ?? null, 'with every guarded table at the rows the portal had: nothing twice, nothing missing');
is_same($except($re['portal']['sums'] ?? [], ['settings']), $except($ag['done']['portal']['sums'] ?? [], ['settings']), 'and every row as it was, to the engine\'s checksum');

case_('A new install still installs, and copies nothing [ADR 0029 §3, §4]');
$fr = $re['fresh'] ?? [];
is_same([null, []], [$at($fr, 'before', 'stamp'), $at($fr, 'before', 'tables')], 'no stamp, no table');
ok(array_key_exists('said', $fr['request'] ?? []) && $fr['request']['said'] === null, 'the run passes');
is_same($fullLedger, array_column($fr['request']['ledger'] ?? [], 'version'), 'the ledger is complete');
ok(is_string($fr['request']['stamp'] ?? null) && $fr['request']['stamp'] !== '', 'the stamp is written');
is_same($fr['before']['copies'] ?? null, $fr['request']['copies'] ?? 'changed', 'and storage/backups holds no new copy: a database that holds nothing is not copied');

case_('A new portal on a used folder: the stamp deleted by hand, the run passes and the old portal\'s copies stay [ADR 0029 §4]');
$uf = $re['used_folder'] ?? [];
is_same(null, $at($uf, 'before', 'stamp'), 'the stamp was deleted');
ok(count($uf['before']['copies'] ?? []) > BACKUP_KEEP, 'beside more copies than are kept: ' . count($uf['before']['copies'] ?? []));
ok(array_key_exists('said', $uf['request'] ?? []) && $uf['request']['said'] === null, 'the run passes');
is_same($uf['before']['copies'] ?? null, $uf['request']['copies'] ?? 'changed', 'and every copy is still there: none was written, so none was pruned');
