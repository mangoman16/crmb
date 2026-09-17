<?php
/** Example data: it fills, it is recognisable, and it all comes out again. */
$admin = make_account(['role'=>'admin']); sign_in_as($admin);

case_('An empty portal reports no example data');
is_same(false, demo_present(), 'nothing there to begin with');
is_same(0, demo_counts()['students'], 'and the count agrees');

case_('Filling writes a portal worth looking at');
$result = demo_fill();
ok($result['students'] >= 10, 'enough children that a list is a list');
ok($result['courses'] >= 3, 'more than one course');
ok($result['charges'] > 0, 'and some money to look at');
ok(strlen($result['password']) >= 12, 'the password is long enough to be accepted at sign-in');
does_not_throw(fn() => strong_password($result['password']), 'and passes the real password rule');
is_same(true, demo_present(), 'the portal now says it holds example data');

case_('Everything it wrote is marked as example data');
is_same(0, (int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=0'), 'no student was left unflagged');
is_same(0, (int)scalar('SELECT COUNT(*) FROM classes WHERE is_demo=0'), 'no course was left unflagged');
// The demo accounts are flagged; the administrator running this is not.
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE is_demo=0'), 'only the real account is unflagged');

case_('The data exercises the cases that go wrong');
ok((int)scalar('SELECT COUNT(DISTINCT status) FROM students') >= 3, 'several membership statuses');
ok((int)scalar('SELECT COUNT(*) FROM class_students WHERE tariff_id IS NULL') >= 1,
   'somebody enrolled with no tariff, which billing has to explain rather than skip silently');
ok((int)scalar('SELECT COUNT(*) FROM class_students WHERE price_cents IS NOT NULL') >= 1,
   'and somebody on a price of their own');
ok((int)scalar('SELECT COUNT(*) FROM (SELECT student_id FROM class_students GROUP BY student_id HAVING COUNT(*)>1) x') >= 1,
   'somebody in more than one course');
ok((int)scalar('SELECT COUNT(*) FROM (SELECT class_id FROM class_days GROUP BY class_id HAVING COUNT(*)>1) x') >= 1,
   'a course that meets more than once a week');
ok((int)scalar('SELECT COUNT(*) FROM (SELECT class_id FROM tariffs GROUP BY class_id HAVING COUNT(*)>1) x') >= 1,
   'a course offering more than one tariff');
ok((int)scalar('SELECT COUNT(*) FROM tariffs WHERE interval_months>1') >= 1, 'a tariff that is not monthly');
ok((int)scalar('SELECT COUNT(*) FROM tariffs WHERE discount_months<>0') >= 1, 'and one with a welcome discount');
ok((int)scalar('SELECT COUNT(*) FROM students WHERE birth_date < ?', [date('Y-m-d', strtotime('-18 years'))]) >= 1, 'an adult');
ok((int)scalar('SELECT COUNT(*) FROM students WHERE birth_date > ?', [date('Y-m-d', strtotime('-12 years'))]) >= 1, 'a child under twelve');
ok((int)scalar('SELECT COUNT(*) FROM contacts') >= $result['students'], 'every child has somebody to ring');
ok((int)scalar('SELECT COUNT(*) FROM attendance') > 0, 'attendance has been recorded');
ok((int)scalar('SELECT COUNT(*) FROM payments') > 0, 'and some of it has been paid');

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
is_same(0, (int)scalar('SELECT COUNT(*) FROM charges'), 'their charges gone with them');
is_same(0, (int)scalar('SELECT COUNT(*) FROM payments'), 'and the payments against those charges');
is_same($before, (int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=0'), 'the real student is untouched');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE is_demo=0'), 'and so is the real account');

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
foreach (['charges','class_students','attendance','contacts','threads'] as $table)
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
$familyId = (int)scalar('SELECT id FROM accounts WHERE email=?', ['familie.hofer@beispiel.test']);
ok($familyId > 0, 'the example family has an account');
sign_in_as($familyId);
$theirs = threads_for(current_user());
is_same(1, count($theirs), 'and one conversation of their own');
ok(str_contains((string)$theirs[0]['subject'], 'Schläger'), 'the one the example data wrote');
ok(str_contains((string)$theirs[0]['last_message'], 'Schläger'), 'with the trainer’s answer in it');
sign_in_as($admin);
demo_clear();
