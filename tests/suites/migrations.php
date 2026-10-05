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
 * 024 and 025, applies the rest, and prints what it finds. This reads that and
 * holds it to the promise.
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

case_('On this run’s own engine, the address is a login’s only name, and logins made without anything else do not collide [ADR 0021 §1]');
// 022 and 023 left a username column unique at a default of ''. Kept, it would
// refuse the second login made without one; 024 drops it with its index.
is_same(0, (int)scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'username'"),
        'accounts has no username column');
is_same(0, (int)scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'accounts' AND index_name = 'account_username'"),
        'nor the index that made one unique');
$unnamed = [];
does_not_throw(function () use (&$unnamed) {
    foreach (['ohne.namen.eins@example.test', 'ohne.namen.zwei@example.test'] as $email) {
        run("INSERT INTO accounts (name, email, role, created_at) VALUES ('', ?, 'student', ?)", [$email, now()]);
        $unnamed[] = (int)db()->lastInsertId();
    }
}, 'two logins written the way an invitation by address writes them, naming only name, address, role and time, are both taken');
is_same(2, count(array_filter($unnamed)), 'and both are there');

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
does_not_throw(function () { foreach (range(1, 3) as $_) make_student(['account_id' => null]); },
               'any number of children can have no login');
does_not_throw(fn() => run('DELETE FROM accounts WHERE id=?', [$login]),
               'and deleting a login leaves its child without one, beside the others');
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$first]), 'the child is still there, with no login');

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
        ['the data moved or kept by migrations 015, 016 and 019 to 025 (this PHP disables exec, which the run with data in between needs)']));
    return;
}
$target = (string)getenv('CRM_MIGRATION_CONFIG');
if ($target === '') {
    test_unsupported(array_merge(test_unsupported(),
        ['the data moved or kept by migrations 015, 016 and 019 to 025 (set CRM_MIGRATION_CONFIG to the config of a'
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

case_('After 024 the runner’s step runs without a username, changes no login, and mails nobody');
// Up to 023 database/defaults.php gave out usernames. Still calling that after
// 024 would fail on every request and keep the portal closed (ADR 0021 §1).
// Since 025 it also gives every course its group chat (ADR 0022).
$r = $f['runner'];
is_same('', $r['first_error'], 'database/defaults.php runs on the portal 024 and the files after it were applied to');
is_same($r['before']['accounts'], $r['first']['accounts'], 'every login has every value it had');
is_same(array_diff_key($r['before']['counts'], ['threads' => 0]), array_diff_key($r['first']['counts'], ['threads' => 0]),
        'every guarded table but the chats has as many rows as before');
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

case_('025 removes no chat, no message and nobody from a chat');
is_same(['threads' => 3, 'messages' => 6, 'thread_participants' => 6], $g['before']['counts'],
        'there were three chats with two people and two messages each, to lose');
is_same($g['before']['counts'], $g['after']['counts'], 'and every one of them is there afterwards');

case_('025 stopped after its UPDATE and started again, or its UPDATE run again after it finished, ends as one run does');
is_same($g['statements'], count($g['retried']), 'stopped after each statement but the last, and once with its UPDATE run again after it finished');
foreach ($g['retried'] as $stopped => $state)
    is_same($g['after'], $state, ($stopped < $g['statements']
        ? 'stopped after statement ' . $stopped . ' of ' . $g['statements'] . ' and run from the first: '
        : 'its UPDATE run again after the file finished: ') . 'every chat has the kind and owner of one run, and nothing is lost');
