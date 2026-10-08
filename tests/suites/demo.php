<?php
/** Example data: what ADR 0026 §9 lists, recognisable, and all of it coming out again. */
$admin = make_account(['role'=>'admin']); sign_in_as($admin);

case_('An empty portal reports no example data');
is_same(false, demo_present(), 'nothing there to begin with');
is_same(0, demo_counts()['students'], 'and the count agrees');

case_('Filling writes the example portal, and hands back its one password');
$result = demo_fill();
// The console said "the password printed above" and printed no password, so the
// three accounts it had just made could not be signed in to at all. It is
// generated once and never stored in the clear, so the fill is the only moment
// anybody can be told it.
ok(($result['password'] ?? '') !== '', 'the fill hands back the password it set');
ok(str_contains((string)file_get_contents(APP_ROOT.'/bin/console.php'), "\$result['password']"),
   'and the console prints it rather than pointing at nothing');
foreach (['trainerin@beispiel.test', 'lena.hofer@beispiel.test', 'jonas.berger@beispiel.test'] as $who)
    ok(password_verify((string)$result['password'], (string)scalar('SELECT password_hash FROM accounts WHERE email=?', [$who])),
       $who.' signs in with exactly that password');
is_same(['students'=>4, 'accounts'=>3, 'courses'=>1, 'charges'=>6],
        array_intersect_key($result, ['students'=>0, 'accounts'=>0, 'courses'=>0, 'charges'=>0]),
        'four children, the trainer and two families, one course, and two months of charges for the three in it');
is_same(true, demo_present(), 'the portal now says it holds example data');

case_('The password is hard to guess, and typed on a phone’s letters alone');
/* The trainer's example address is published and a staff login. Its password
   was two words from ten and four digits: 900,000 passwords, for a login that
   never expired (security review). Four made-up words of two syllables: 75^8,
   about 10^15. */
$password = (string)$result['password'];
ok(preg_match('/^(?:[BDFGHKLMNPRSTVZ][aeiou][bdfghklmnprstvz][aeiou]){4}$/D', $password) === 1,
   'four words of two syllables, each starting with a capital: '.$password);
does_not_throw(fn() => strong_password($password), 'it passes the real password rule');
$drawn = array_map(fn() => demo_password(), range(1, 20));
is_same(20, count(array_unique($drawn)), 'twenty drawn are twenty different ones');

case_('Everything it wrote is marked as example data');
is_same(0, (int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=0'), 'no student was left unflagged');
is_same(0, (int)scalar('SELECT COUNT(*) FROM classes WHERE is_demo=0'), 'no course was left unflagged');
// The demo accounts are flagged; the administrator running this is not.
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE is_demo=0'), 'only the real account is unflagged');

case_('Nothing in it is drawn at random but the password [ADR 0026 §9]');
$fill = new ReflectionFunction('demo_fill');
$source = implode('', array_slice(file($fill->getFileName()), $fill->getStartLine() - 1, $fill->getEndLine() - $fill->getStartLine() + 1));
is_same(0, preg_match('/\b(random_int|mt_rand|rand|shuffle|array_rand|random_bytes)\s*\(/', $source), 'demo_fill() draws nothing; demo_password() is the one that does');

case_('One course, as §9 has it: one day a week, one price, and its group with the trainer’s welcome');
$course = one('SELECT * FROM classes WHERE is_demo=1');
is_same('Kindertraining', $course['name'] ?? null, 'the course is „Kindertraining"');
is_same(1, (int)scalar('SELECT COUNT(*) FROM class_days WHERE class_id=?', [(int)$course['id']]), 'it meets one day a week');
is_same([[1, 3500]], array_map(fn($r) => [(int)$r['interval_months'], (int)$r['price_cents']],
        rows('SELECT r.* FROM tariff_rates r JOIN tariffs t ON t.id=r.tariff_id WHERE t.class_id=?', [(int)$course['id']])),
        'one monthly tariff at one price');
ok(str_starts_with((string)scalar('SELECT m.body FROM messages m JOIN threads t ON t.id=m.thread_id WHERE t.class_id=?', [(int)$course['id']]), 'Willkommen in der Gruppe'),
   'and its group has the trainer’s welcome');

case_('Four children, each showing what §9 has them for');
$child = fn(string $first): array => one('SELECT * FROM students WHERE first_name=? AND is_demo=1', [$first]);
[$lena, $jonas, $mia, $elias] = [$child('Lena'), $child('Jonas'), $child('Mia'), $child('Elias')];
$inCourse = fn(array $s): bool => (bool)one('SELECT 1 FROM class_students cs WHERE cs.student_id=? AND cs.class_id=? AND '.current_enrolment_sql(), [(int)$s['id'], (int)$course['id']]);
is_same([true, false, true, true], [$inCourse($lena), $inCourse($jonas), $inCourse($mia), $inCourse($elias)], 'Lena, Mia and Elias are in the course, Jonas is not');
is_same(['lena.hofer@beispiel.test', 'jonas.berger@beispiel.test', 'placeholder', 'placeholder'],
        array_map(fn($s) => (string)(scalar("SELECT IF(state='placeholder','placeholder',email) FROM accounts WHERE id=?", [(int)$s['account_id']]) ?? ''), [$lena, $jonas, $mia, $elias]),
        'Lena and Jonas are the two family logins; Mia and Elias have none yet');
is_same([1, 1, 1, 1], array_map(fn($s) => (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=?', [(int)$s['id']]), [$lena, $jonas, $mia, $elias]),
        'each has one emergency contact');
$charges = fn(array $s): array => rows('SELECT c.*,'.charge_paid_sql().' AS paid FROM charges c WHERE c.student_id=? ORDER BY c.period_from', [(int)$s['id']]);
$payments = fn(array $charge): array => rows('SELECT * FROM payments WHERE charge_id=?', [(int)$charge['id']]);
[$lenaLast, $lenaThis] = $charges($lena);
ok((int)$lenaLast['paid'] === (int)$lenaLast['amount_cents'], 'Lena’s last month is paid and confirmed');
ok((int)$lenaThis['paid'] === 0 && $payments($lenaThis) === [] && $lenaThis['due_on'] > today(), 'and this month is open and not yet due, for the QR code and „Beleg hochladen"');
is_same(1, (int)scalar("SELECT COUNT(*) FROM enrolment_requests WHERE student_id=? AND class_id=? AND kind='join' AND state='pending'", [(int)$jonas['id'], (int)$course['id']]),
        'Jonas has asked to join, so the trainer’s „Anfragen" holds one');
is_same([], $charges($jonas), 'and owes nothing');
[$miaLast, $miaThis] = $charges($mia);
ok(charge_is_overdue($miaLast), 'Mia’s last month is overdue');
ok(!charge_is_overdue($miaThis), 'her month now is not, yet');
is_same([[today(), date('Y-m-d', strtotime(today().' +2 days'))]],
        array_map(fn($a) => [$a['starts_on'], $a['ends_on']], rows("SELECT * FROM absences WHERE student_id=? AND reason='sick'", [(int)$mia['id']])),
        'and she is sick from today, for three days');
[$eliasLast, $eliasThis] = $charges($elias);
ok((int)$eliasLast['paid'] === (int)$eliasLast['amount_cents'], 'Elias’s last month is paid and confirmed');
$waiting = $payments($eliasThis);
ok(count($waiting) === 1 && $waiting[0]['confirmed_at'] === null && (int)$eliasThis['paid'] === 0,
   'and this month’s payment is recorded and waits for the trainer to confirm it');

case_('Attendance on the course’s last two training days: everybody there, but Elias on the latest');
$days = array_column(rows('SELECT DISTINCT session_on FROM attendance WHERE class_id=? ORDER BY session_on', [(int)$course['id']]), 'session_on');
is_same(2, count($days), 'two training days are recorded');
ok($days[1] < today() && $days[1] >= date('Y-m-d', strtotime(today().' -7 days')) && (int)date('N', strtotime($days[1])) === 1
   && date('Y-m-d', strtotime($days[0].' +7 days')) === $days[1],
   'the last two Mondays before today, a week apart: '.implode(', ', $days));
$marked = [];
foreach (rows('SELECT a.session_on, s.first_name, a.status FROM attendance a JOIN students s ON s.id=a.student_id ORDER BY a.session_on, s.first_name') as $row)
    $marked[$row['session_on']][$row['first_name']] = $row['status'];
is_same([$days[0] => ['Elias'=>'present', 'Lena'=>'present', 'Mia'=>'present'], $days[1] => ['Elias'=>'absent', 'Lena'=>'present', 'Mia'=>'present']], $marked,
        'Lena and Mia were there both times, and Elias was away on the latest');

case_('One news item, and one chat between Lena’s family and the trainer');
is_same([1], array_map('intval', array_column(rows('SELECT published FROM news WHERE is_demo=1'), 'published')), 'one news item, published');
$lenaLogin = (int)$lena['account_id'];
$chat = one("SELECT * FROM threads WHERE kind='staff_direct'");
is_same($lenaLogin, (int)($chat['account_id'] ?? 0), 'one chat, Lena’s family’s, with the trainer');
is_same(2, (int)scalar('SELECT COUNT(*) FROM messages WHERE thread_id=?', [(int)$chat['id']]), 'with two messages');

case_('Its children are in two of the seeded bands, and one has no birth date, so „Nach Alter" has something to show');
/* The birth dates are counted back from the day of the fill, weeks past a
   birthday, so this holds on whichever day the fill is run - and the band each
   child is in holds for a year after (ADR 0026, the later answer). */
$sections = student_sections(filtered_students(['sort'=>'age']));
is_same(['Unter 12', 'Jugend', null], array_map(fn($s) => $s['band']['name'] ?? null, $sections), 'two band headers, then „Ohne Geburtsdatum"');
is_same([['Lena', 'Jonas'], ['Elias'], ['Mia']], array_map(fn($s) => array_column($s['rows'], 'first_name'), $sections),
        'Lena and Jonas in „Unter 12", Elias in „Jugend", Mia without a birth date');
is_same([9, 10, null, 13], [student_age($lena['birth_date']), student_age($jonas['birth_date']), student_age($mia['birth_date']), student_age($elias['birth_date'])],
        'aged 9, 10 and 13 on the day of the fill');
foreach ([0, 100, 200, 364] as $later) {
    $on = date('Y-m-d', strtotime(today().' +'.$later.' days'));
    is_same(['Unter 12', 'Unter 12', 'Jugend'],
            array_map(fn($s) => age_group_for_age(student_age($s['birth_date'], $on))['name'] ?? null, [$lena, $jonas, $elias]),
            'and still in the same bands '.$later.' days later');
}

case_('There is something outstanding, so the overdue views have work to do');
ok(array_sum(balances()) > 0, 'money is owed somewhere');

case_('Filling twice is refused rather than doubled');
throws(fn() => demo_fill(), 'a second fill is refused', 'Beispieldaten');

case_('Clearing removes the example data and nothing else');
$real = make_student(['is_demo'=>0]);
$before = (int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=0');
$removed = demo_clear();
ok($removed['students'] > 0, 'it reports what it removed');
is_same(false, demo_present(), 'no example data is left');
is_same(0, (int)scalar('SELECT COUNT(*) FROM classes WHERE is_demo=1'), 'courses gone');
is_same(0, (int)scalar("SELECT COUNT(*) FROM threads WHERE kind IN ('course','staff_direct')"), 'their groups and the example chat with them');
is_same(0, (int)scalar('SELECT COUNT(*) FROM charges'), 'their charges gone with them');
is_same(0, (int)scalar('SELECT COUNT(*) FROM payments'), 'and the payments against those charges');
is_same($before, (int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=0'), 'the real student is untouched');
is_same(2, (int)scalar('SELECT COUNT(*) FROM accounts WHERE is_demo=0'), 'and so are the real accounts: the administrator’s, and the real student’s login');

case_('Clearing twice is not an error');
does_not_throw(fn() => demo_clear(), 'a second clear finds nothing and says so');

case_('With real students there, filling needs to be insisted on');
throws(fn() => demo_fill(), 'refused while a real student exists', 'echte');
does_not_throw(fn() => demo_fill(true), 'unless the caller insists');

case_('Nothing example-shaped is left in any table');
// The clear leans on the database's own cascades for everything that hangs off
// a demo student or a demo account. A table added later that cascades from
// neither would keep its rows and nobody would notice until a real portal had
// example attendance in its statistics.
// First prove there is something to lose: an assertion that a table is empty is
// worth nothing if it was empty to begin with.
foreach (['charges','payments','class_students','attendance','absences','contacts','enrolment_requests','threads'] as $table)
    ok((int)scalar('SELECT COUNT(*) FROM '.$table) > 0, $table.' has example rows before the clear');
demo_clear();
foreach (['students','classes','tariffs','accounts','news'] as $table)
    is_same(0, (int)scalar('SELECT COUNT(*) FROM '.$table.' WHERE is_demo=1'), $table.' has nothing left');
foreach (['charges','payments','class_students','attendance','absences','contacts',
          'enrolment_requests','notifications','thread_participants','messages','threads'] as $table)
    is_same(0, (int)scalar('SELECT COUNT(*) FROM '.$table), $table.' was taken with it');
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs'), 'and nothing is left waiting in the outbox');

case_('The example family can see their own example conversation');
// They could not: the thread was written without the row that says who is in
// it, so the family opened Nachrichten and was told there was nothing there.
// Nobody noticed, because the trainer sees every staff conversation anyway.
demo_fill(true);
$familyId = (int)scalar('SELECT id FROM accounts WHERE email=?', ['lena.hofer@beispiel.test']);
ok($familyId > 0, 'the example family has an account');
sign_in_as($familyId);
$theirs = chat_list(current_user());
$chats = array_values(array_filter($theirs, fn($c) => $c['kind'] === 'staff_direct'));
is_same(1, count($chats), 'and one chat of their own, with the trainer');
ok(str_contains((string)$chats[0]['last_body'], 'Schläger'), 'with the trainer’s answer in it');
$groups = array_values(array_filter($theirs, fn($c) => $c['kind'] === 'course'));
ok(count($groups) === 1 && str_starts_with((string)$groups[0]['last_body'], 'Willkommen in der Gruppe'),
   'and the group of their course, with the trainer’s welcome in it');
sign_in_as($admin);
demo_clear();

case_('The example logins sign in for two weeks, and then not at all - by password or by link [security review]');
/* The trainer's address is published and a staff login, and an example set
   forgotten on a portal on the internet stayed a way in for ever. Refused in
   sign_in(), where the password, a sign-in link and a reset link all end, in the
   words every refusal uses, so it holds on a portal whose background work never
   runs. */
$filled = demo_fill(true);
$lenaLogin = (int)scalar('SELECT id FROM accounts WHERE email=?', ['lena.hofer@beispiel.test']);
$openLink = function () use ($lenaLogin): array {
    $_SESSION['activation_hash'] = hash('sha256', make_token($lenaLogin, 'signin'));
    return submit('activate', ['password'=>'Neues-Passwort-Lena-2026', 'password_confirm'=>'Neues-Passwort-Lena-2026']);
};
sign_out();
does_not_throw(fn() => submit('login', ['login'=>'trainerin@beispiel.test', 'password'=>$filled['password']]), 'a fresh example login signs in');
sign_out();
run('UPDATE accounts SET created_at=? WHERE is_demo=1', [gmdate('Y-m-d H:i:s', time() - DEMO_LOGIN_DAYS * 86400 + 3600)]);
does_not_throw(fn() => submit('login', ['login'=>'lena.hofer@beispiel.test', 'password'=>$filled['password']]), 'and still does an hour before its days are up');
sign_out();
run('UPDATE accounts SET created_at=? WHERE is_demo=1', [gmdate('Y-m-d H:i:s', time() - DEMO_LOGIN_DAYS * 86400 - 60)]);
throws(fn() => submit('login', ['login'=>'trainerin@beispiel.test', 'password'=>$filled['password']]),
       'past them, the right password is refused', 'Anmeldung nicht möglich');
is_same(null, current_user(true), 'and nobody is signed in');
$hashBefore = (string)scalar('SELECT password_hash FROM accounts WHERE id=?', [$lenaLogin]);
throws($openLink, 'a sign-in link made for an expired example login is refused in the same words', 'Anmeldung nicht möglich');
is_same([null, $hashBefore], [current_user(true), (string)scalar('SELECT password_hash FROM accounts WHERE id=?', [$lenaLogin])],
        'nobody is signed in, and the password the link would have set is not written');
run('UPDATE accounts SET created_at=? WHERE is_demo=1', [now()]);
does_not_throw($openLink, 'the same link for a fresh example login signs in');
is_same($lenaLogin, (int)(current_user(true)['id'] ?? 0), 'as that login');
sign_out();
run('UPDATE accounts SET created_at=? WHERE is_demo=1', [gmdate('Y-m-d H:i:s', time() - DEMO_LOGIN_DAYS * 86400 - 60)]);
run('UPDATE accounts SET created_at=? WHERE id=?', [gmdate('Y-m-d H:i:s', time() - 400 * 86400), $admin]);
is_same([false, true], [demo_login_expired(one('SELECT * FROM accounts WHERE id=?', [$admin])),
                        demo_login_expired(one("SELECT * FROM accounts WHERE email='jonas.berger@beispiel.test'"))],
        'a real login of any age is not an example one');
sign_in_as($admin);
demo_clear();

case_('The example logins are made the way everybody else’s are: at an address nobody else has');
/* Moved from the usernames suite (ADR 0021). Every creator asks
   refuse_address_in_use(), the fill included. */
$shared = make_account(['email' => 'trainerin@beispiel.test']);
throws(fn() => demo_fill(true), 'a fill whose trainer would take an address another login has is refused', 'Jede Person braucht ihre eigene');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE is_demo=1'), 'and wrote nothing');
run('DELETE FROM accounts WHERE id=?', [$shared]);
$filled = demo_fill(true);
is_same(['trainerin@beispiel.test', 'lena.hofer@beispiel.test', 'jonas.berger@beispiel.test'], array_column($filled['logins'], 'email'),
        'with it gone, the fill makes its three, staff first, and hands their addresses back');
foreach ($filled['logins'] as $login) is_same($login['email'], email_value($login['email']), 'each a plain address: '.$login['email']);
demo_clear();
