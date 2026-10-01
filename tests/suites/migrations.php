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
 * tests/migration-data.php builds a portal as it stood before 015, 019, 020 and
 * 022, applies the rest, and prints what it finds. This reads that and holds it
 * to the promise.
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
// The username is named because 023 makes it unique: '' is taken once at most.
run("INSERT INTO accounts (name, email, username, role, created_at) VALUES ('Neu', 'neu@example.test', 'neu', 'student', ?)", [now()]);
$fresh = one("SELECT id, newsletter, presence FROM accounts WHERE email = 'neu@example.test'");
is_same([1, 'auto'], [(int)$fresh['newsletter'], $fresh['presence']], 'news by email on, status auto');
run('INSERT INTO online_periods (account_id, started_at, last_seen_at, hidden) VALUES (?, ?, ?, 0)', [$fresh['id'], now(), now()]);
run('DELETE FROM accounts WHERE id = ?', [$fresh['id']]);
is_same(0, (int)scalar('SELECT COUNT(*) FROM online_periods WHERE account_id = ?', [$fresh['id']]),
        'deleting a login deletes when it was online, rather than leaving periods nobody can be named for');

case_('On this run’s own engine, no two logins share an address, nor a username');
// On the run's own database, so a run on MariaDB proves this on MariaDB.
// refuse_address_in_use() gives the sentence a person reads; this is the
// database behind it, which refuses under any isolation level (ADR 0020 §1).
make_account(['email' => 'eigene.adresse@example.test', 'username' => 'eigene.eins']);
$refusal = null;
try { make_account(['email' => 'eigene.adresse@example.test', 'username' => 'eigene.zwei']); } catch (PDOException $e) { $refusal = $e; }
is_same('23000', (string)$refusal?->getCode(), 'a second login on an address that is taken is refused, with a username of its own');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['eigene.adresse@example.test']), 'and only the first is there');
does_not_throw(fn() => make_account(['email' => 'eigene.zwei@example.test', 'username' => 'eigene.zwei']),
               'the same login on an address of its own is taken, so what refused it was the address');
$refusal = null;
try { make_account(['username' => 'eigene.eins']); } catch (PDOException $e) { $refusal = $e; }
is_same('23000', (string)$refusal?->getCode(), 'a username that is taken is refused by the database');
$refusal = null;
try { make_account(['email' => 'Eigene.Adresse@Example.TEST']); } catch (PDOException $e) { $refusal = $e; }
is_same('23000', (string)$refusal?->getCode(), 'the taken address in other capitals is refused too, under the tables’ collation');
$refusal = null;
try { make_account(['username' => 'Eigene.Eins']); } catch (PDOException $e) { $refusal = $e; }
is_same('23000', (string)$refusal?->getCode(), 'and so is the taken username in other capitals');
$refusal = null;
try { make_account(['email' => 'eigene-adresse@example.test']); make_account(['email' => 'eigeneadresse@example.test']); }
catch (PDOException $e) { $refusal = $e; }
is_same(null, $refusal, 'while a dot, a hyphen and nothing at all are three different addresses to it');
$refusal = null;
try { make_account(['username' => 'eigene-eins']); make_account(['username' => 'eigeneeins']); } catch (PDOException $e) { $refusal = $e; }
is_same(null, $refusal, 'and three different usernames');

case_('On this run’s own engine, a locking read also locks the gap where a row would go [R8]');
// refuse_address_in_use() and username_for_new_account() read FOR UPDATE and
// rely on REPEATABLE READ locking the gap a missing row would fill; under
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

case_('On this run’s own engine, the runner’s step names a login at a placeholder and leaves a named one alone');
// What runs after every update's migrations is database/defaults.php, required
// here the way schema_apply() requires it, and in a scope of its own so its loop
// variables do not land in this file's. On this run's database, so a run on
// MariaDB proves the backfill's statements there; migration-data.php proves it
// on a portal 022 and 023 were applied to.
$runnerStep = static function (): void { require ROOT . '/database/defaults.php'; setting_cache_clear(); };
$namedTrainer = make_account(['role' => 'trainer', 'name' => 'Trainerin Benannt', 'email' => 'trainerin.benannt@example.test', 'username' => 'trainerin.benannt']);
$placeholderFamily = make_account(['role' => 'student', 'name' => 'Familie Platzhalter', 'email' => 'platzhalter@example.test', 'username' => 'wird.ersetzt']);
run('UPDATE accounts SET username=? WHERE id=?', ['#' . $placeholderFamily, $placeholderFamily]);
$pairOf = fn(string $columns) => rows('SELECT ' . $columns . ' FROM accounts WHERE id IN (?, ?) ORDER BY id', [$namedTrainer, $placeholderFamily]);
$pairBefore = $pairOf('id, email, role');
$runnerStep();
is_same($pairBefore, $pairOf('id, email, role'), 'both logins are still there, each on its own address, with the same role');
is_same(['trainerin.benannt', 'familie.platzhalter'], array_column($pairOf('username'), 'username'),
        'the trainer keeps her username and the family’s login is given one');

case_('After the runner the sign-in comparison hash exists at today’s cost, and a request refreshes nothing [R9]');
// Hashing is slower than verifying, so a sign-in that hashed would say by its
// time whether a username or an address has a login. The runner and the nightly
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
throws(fn() => submit('login', ['username' => 'niemand.hier', 'password' => 'falsch']), 'a sign-in with no such username is refused', 'Anmeldung nicht möglich');
setting_cache_clear();
is_same($outdated, (string)setting('sign_in_dummy_hash'), 'and leaves even an outdated hash alone: a request refreshes nothing');
throttle_clear('login', username_identity('niemand.hier'));
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
        ['the data moved or kept by migrations 015, 016 and 019 to 023 (this PHP disables exec, which the run with data in between needs)']));
    return;
}
$target = (string)getenv('CRM_MIGRATION_CONFIG');
if ($target === '') {
    test_unsupported(array_merge(test_unsupported(),
        ['the data moved or kept by migrations 015, 016 and 019 to 023 (set CRM_MIGRATION_CONFIG to the config of a'
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
// 022 and 023: a username for every login, beside an address that stays its own
// (ADR 0019, and 0020, which withdrew 0019's shared addresses before they
// shipped). The usernames made from names are the runner's PHP step after these
// files, not these files, and are tested where that step is.
$u = $after['twentytwo'];
$named = (int)$u['logins']['named'];
$loginsBefore = array_column($u['before']['accounts'], null, 'id');
$loginsAfter = array_column($u['after']['accounts'], null, 'id');
$usernamePattern = '/^[a-z](?:[a-z0-9]|[.-](?=[a-z0-9])){2,39}$/D';

case_('022 and 023 keep every login the previous version wrote, value for value');
ok(count($loginsBefore) >= 10, 'there were logins to keep: ' . count($loginsBefore));
is_same(array_keys($loginsBefore), array_keys(array_diff_key($loginsAfter, [$named => 1])),
        'the same logins afterwards, and no other but the one written in between');
foreach ($loginsBefore as $loginId => $row)
    is_same($row, array_diff_key($loginsAfter[$loginId] ?? [], ['username' => 1]),
            'login ' . $loginId . ' (' . $row['name'] . ') has every other value it had, its password and address included');
$expectedCounts = $u['before']['counts'];
$expectedCounts['accounts']++;
is_same($expectedCounts, $u['after']['counts'],
        'every guarded table has as many rows as before but for that one login, so the guard stays as it is');

case_('022 gives every login from before it the empty username, never NULL');
is_same(array_fill_keys(array_keys($loginsBefore), ''),
        array_diff_key(array_column($u['after_022']['accounts'], 'username', 'id'), [$named => 1]),
        'each is at "" - not given one yet - which can never sign in, because it fails the pattern');
ok(!preg_match($usernamePattern, ''), 'and "" does fail it');
$columns = $u['after_022']['columns'];
is_same(array_search('email', $columns, true) + 1, array_search('username', $columns, true),
        'the column sits after email, where a person reading the table looks for it');

case_('023 gives each of them a placeholder of its own, and touches no other login');
foreach ($loginsBefore as $loginId => $row)
    is_same('#' . $loginId, $loginsAfter[$loginId]['username'], $row['name'] . ' is #' . $loginId);
is_same('schon.benannt', $loginsAfter[$named]['username'],
        'a login that already had a username keeps it: the UPDATE reached only the rows still at ""');
$usernames = array_column($u['after']['accounts'], 'username');
is_same(count($usernames), count(array_unique($usernames)), 'no two logins have the same one');
is_same([], array_values(array_filter(array_diff($usernames, ['schon.benannt']), fn($p) => preg_match($usernamePattern, $p))),
        'and no placeholder is a username anybody could sign in with or choose');
is_same('23000', $u['placeholder_taken'], 'the database refuses a second login at a placeholder that is taken');

case_('After 023 the address is still one login’s, and so is the username [0020 §1]');
// 001's inline UNIQUE on accounts.email is the backstop behind
// refuse_address_in_use(): with that bypassed, the database still says no.
$onlyEmail = fn(array $indexes): array => array_keys(array_filter($indexes, fn($columns) => $columns === ['email']));
is_same(1, count($onlyEmail($u['before']['unique'])), 'before 022 one unique index covered the address alone');
is_same(['email'], $onlyEmail($u['before']['unique']), 'and it was named email, after its column, as 001 left it');
$expectedIndexes = $u['before']['unique'] + ['account_username' => ['username']];
ksort($expectedIndexes);
is_same($expectedIndexes, $u['after']['unique'],
        'afterwards every unique index from before is still there, the address’s included, and account_username covers the username');
is_same('23000', $u['same_address'],
        'a second login on the address of a login the update carried through is refused, with a username nobody has');
is_same('23000', $u['legacy_address_taken'], 'and so is one on the legacy quoted address, which is its login’s alone as well [R2]');
is_same(null, $u['own_address'], 'while the same write on an address of its own is taken: what refused the others was the address');
is_same('23000', $u['same_username'], 'a second login with a username that is taken is refused');
is_same('23000', $u['address_other_case'], 'the address in other capitals is refused too, under the tables’ collation');
is_same('23000', $u['other_case'], 'and so is a username differing only in capitals');

case_('A legacy address with a quoted local part comes through the update untouched [R2]');
$legacyId = (int)$u['logins']['legacy'];
$legacy = $loginsBefore[$legacyId]['email'] ?? null;
is_same('"familie..alt"@beispiel.test', $legacy, 'the portal held one before the update');
ok(filter_var($legacy, FILTER_VALIDATE_EMAIL) !== false,
   'which is realistic: FILTER_VALIDATE_EMAIL, the only check an address ever went through, lets it pass');
is_same($legacy, $loginsAfter[$legacyId]['email'] ?? null, 'and it is the same address, byte for byte, afterwards');
is_same('#' . $legacyId, $loginsAfter[$legacyId]['username'] ?? null, 'with a placeholder username like every other login');

case_('023 stopped partway and started again, and its UPDATE run twice, end as one run does');
is_same($u['statements'] - 1, count($u['retried']), 'it was stopped after each statement but the last');
// Each retry builds its portal afresh, and the login from before 015 is dated
// the moment it was written, so times are left out as they are for 019.
$undated = fn(array $rows) => array_map(fn($row) => array_diff_key($row, ['created_at' => 1, 'verified_at' => 1]), $rows);
foreach ($u['retried'] as $stopped => $state) {
    $when = 'stopped after statement ' . $stopped . ' of ' . $u['statements'] . ' and run from the first: ';
    is_same($undated($u['after']['accounts']), $undated($state['accounts']), $when . 'every login has the values and placeholder of one run');
    is_same($u['after']['unique'], $state['unique'], $when . 'the same unique indexes, the address’s included');
}
is_same($u['after']['accounts'], $u['again'], 'the UPDATE run again after the file finished changes nothing, "#" and all');

case_('After 023 the runner’s step names every login carried through, oldest first, once, and mails nobody [§4]');
// database/defaults.php run on the portal 022 and 023 were applied to, rather
// than on logins a fixture wrote after the fact: the placeholders here are the
// ones 023 made. How a name becomes a username is tests/suites/usernames.php's.
$r = $u['runner'];
$runBefore = array_column($r['before']['accounts'], null, 'id');
$runAfter = array_column($r['first']['accounts'], null, 'id');
$withoutUsername = fn(array $logins) => array_map(fn($row) => array_diff_key($row, ['username' => 1]), $logins);
is_same(array_keys($runBefore), array_keys($runAfter), 'the same logins afterwards, none added and none lost');
is_same($r['before']['counts'], $r['first']['counts'], 'and every guarded table has as many rows as before');
is_same($withoutUsername($runBefore), $withoutUsername($runAfter),
        'every other value of every login is as it was, its password and its address included');
is_same(['lena.mueller', 'lena.mueller2', 'hans-juergen.gross-oezdemir', 'trainerin', 'kurt.novak', 'eva.hofer', 'familie.alt'],
        array_map(fn($case) => $runAfter[$u['logins'][$case]]['username'] ?? null, ['mueller', 'mueller2', 'gross', 'staff', 'orphan', 'invited', 'legacy']),
        'a student’s login after the student, the older Lena keeping the plain name; a one-word trainer, a login whose student is gone, an invitation and a legacy address after their own');
is_same(['', 'familie.leer'], [$runBefore[$r['never_named']]['username'] ?? null, $runAfter[$r['never_named']]['username'] ?? null],
        'a login at "" is named like one at a placeholder');
$runUsernames = array_column($r['first']['accounts'], 'username');
is_same([], array_values(array_filter($runUsernames, fn($name) => !preg_match($usernamePattern, (string)$name))),
        'every login now has a username that can sign in');
is_same(count($runUsernames), count(array_unique($runUsernames)), 'and no two have the same one');
// The negative: a step that renamed everybody would pass every line above.
$wasUnnamed = array_keys(array_filter($runBefore, fn($row) => $row['username'] === '' || $row['username'][0] === '#'));
$renamed = array_keys(array_filter($runAfter, fn($row) => $row['username'] !== $runBefore[$row['id']]['username']));
is_same($wasUnnamed, $renamed, 'it changed exactly the logins at a placeholder or at "", ' . count($wasUnnamed) . ' of ' . count($runBefore));
is_same('schon.benannt', $runAfter[$u['logins']['named']]['username'] ?? null, 'a login that already had a username keeps it');
is_same($r['before']['mail'], $r['first']['mail'], 'no mail was queued: nobody is told their username by the update');
is_same('', $r['before']['dummy_hash'], 'the portal had no sign-in comparison hash before the update');
ok(password_get_info($r['first']['dummy_hash'])['algo'] === PASSWORD_DEFAULT && !password_needs_rehash($r['first']['dummy_hash'], PASSWORD_DEFAULT),
   'and has one afterwards, made with PASSWORD_DEFAULT at today’s cost [R9]');
is_same([$r['first']['accounts'], $r['first']['dummy_hash'], $r['first']['counts']], [$r['second']['accounts'], $r['second']['dummy_hash'], $r['second']['counts']],
        'the next update’s run changes nothing: no login, no hash, no count');
