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
 * tests/migration-data.php builds a portal as it stood before 015 and again as
 * it stood before 019, applies the rest, and prints what it finds. This reads
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
 * to stop half way. That is a SQLite file unless CRM_MIGRATION_CONFIG names the
 * config of an empty MySQL or MariaDB database whose name ends in _test, which
 * is how the dialect of these particular statements is proven. Without it, a run
 * on MySQL says so in the footer rather than glossing over it.
 */

if (!function_exists('exec')) {
    // Shared hosting often lists exec in disable_functions. The run says what it
    // could not do rather than stopping on an undefined function.
    test_unsupported(array_merge(test_unsupported(),
        ['the data moved or kept by migrations 015, 016, 019, 020 and 021 (this PHP disables exec, which the run with data in between needs)']));
    return;
}
$out = [];
$target = (string)getenv('CRM_MIGRATION_CONFIG');
// Its SQLite file goes into this run's own folder, which is removed with it.
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tests/migration-data.php') . ' '
    . escapeshellarg(test_run_dir() . '/migration-data.sqlite')
    . ($target !== '' ? ' ' . escapeshellarg('--mysql=' . $target) : '') . ' 2>&1', $out, $code);
$raw = implode("\n", $out);
is_same(0, $code, 'the migrations apply with data written in between');
$after = json_decode($raw, true);
if (!is_array($after)) {
    ok(false, 'the run with data in between printed a result: ' . mb_substr($raw, 0, 300));
    return;
}
if ($after['engine'] === 'sqlite' && test_driver() !== 'sqlite')
    test_unsupported(array_merge(test_unsupported(),
        ['the data moved or kept by migrations 015, 016, 019, 020 and 021 (checked on sqlite; set CRM_MIGRATION_CONFIG'
         . ' to the config of an empty *_test database to check it on this engine)']));

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
if ($w['after']['indexes'] !== null) {
    $indexes = $w['after']['indexes'];
    ksort($indexes);
    is_same(['PRIMARY' => ['id'], 'online_period_age' => ['last_seen_at'], 'online_period_of_account' => ['account_id', 'last_seen_at']],
            $indexes, 'with an index for one account’s periods and one for the prune, and no other');
}

case_('020 stopped partway and started again, and 021 run twice, end as one run does');
is_same($w['statements'] - 1, count($w['retried']), 'it was stopped after each statement but the last');
foreach ($w['retried'] as $stopped => $state) {
    $when = 'stopped after statement ' . $stopped . ' of ' . $w['statements'] . ' and run from the first: ';
    is_same($w['after']['accounts'], $state['accounts'], $when . 'every login has the choice and status of one run');
    is_same($w['after']['online_periods'], $state['online_periods'], $when . 'the same history table');
    is_same($w['after']['new_login'], $state['new_login'], $when . 'and a new login the same defaults');
}

case_('On this run’s own engine, a new login has news on and status auto, and its history goes with it');
run("INSERT INTO accounts (name, email, role, created_at) VALUES ('Neu', 'neu@example.test', 'student', ?)", [now()]);
$fresh = one("SELECT id, newsletter, presence FROM accounts WHERE email = 'neu@example.test'");
is_same([1, 'auto'], [(int)$fresh['newsletter'], $fresh['presence']], 'news by email on, status auto');
run('INSERT INTO online_periods (account_id, started_at, last_seen_at, hidden) VALUES (?, ?, ?, 0)', [$fresh['id'], now(), now()]);
run('DELETE FROM accounts WHERE id = ?', [$fresh['id']]);
is_same(0, (int)scalar('SELECT COUNT(*) FROM online_periods WHERE account_id = ?', [$fresh['id']]),
        'deleting a login deletes when it was online, rather than leaving periods nobody can be named for');

if (test_driver() === 'sqlite') {
    case_('The SQLite translation of SET DEFAULT changes the default and no stored value');
    // SQLite has no such statement; TestSqlitePdo edits the table's definition.
    // A row older than its column stores nothing for it and is read through the
    // default, so this is the case where "changes nothing stored" can go wrong.
    $file = test_run_dir() . '/set-default.sqlite';
    $open = fn() => new TestSqlitePdo('sqlite:' . $file, null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $main = $open();
    $main->exec('PRAGMA journal_mode=WAL');
    $main->exec("CREATE TABLE t (id INTEGER PRIMARY KEY, kept INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT 'a,b')");
    $main->exec('INSERT INTO t (id) VALUES (1)');
    $main->exec('ALTER TABLE t ADD COLUMN late INTEGER NOT NULL DEFAULT 0');
    $other = $open();
    $other->query('SELECT * FROM t')->fetchAll();   // holds the old definition, as the counter connection would
    is_same(0, $main->exec('ALTER TABLE t ALTER COLUMN late SET DEFAULT 1'), 'exec() takes it');
    $main->exec('ALTER TABLE `t` ALTER COLUMN `kept` SET DEFAULT 1;');
    is_same([], $main->query("ALTER TABLE t ALTER COLUMN note SET DEFAULT 'x, y'")->fetchAll(),
            'and so does query(), which is how the runner sends a migration statement');
    is_same(['id' => 1, 'kept' => 0, 'note' => 'a,b', 'late' => 0], $main->query('SELECT * FROM t WHERE id = 1')->fetch(),
            'a row written before keeps every value it had, including one it never stored');
    $other->exec('INSERT INTO t (id) VALUES (2)');
    is_same(['id' => 2, 'kept' => 1, 'note' => 'x, y', 'late' => 1], $main->query('SELECT * FROM t WHERE id = 2')->fetch(),
            'a row written afterwards, on another connection, gets the new defaults');
    is_same('ok', $main->query('PRAGMA integrity_check')->fetchColumn(), 'and the file is sound');
    throws(fn() => $main->exec('ALTER TABLE t ALTER COLUMN kept SET DEFAULT (1 + 1)'),
           'an expression is refused rather than guessed at', 'literal');
    throws(fn() => $main->exec('ALTER TABLE t ALTER COLUMN id SET DEFAULT 5'),
           'as is a column with no DEFAULT to change', 'no DEFAULT');
    $main = $other = null;
}

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

case_('The functions 019 calls behave on this engine as they do on MySQL');
// On sqlite they are TestSqlitePdo's stand-ins, and a stand-in that is kinder
// than MySQL - '' where MySQL gives NULL - lets a statement pass here that loses
// a value on the real engine.
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
