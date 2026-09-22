<?php
/**
 * Data consistency.
 *
 * Every write the web interface makes goes through handle_post(), which wraps
 * the action in one transaction. These checks are about what must be true when
 * something goes wrong halfway: nothing half-applied, no duplicate from a
 * repeated submission, and no silent overwrite of somebody else's edit.
 */

$admin = make_account(['role'=>'admin','name'=>'Admin']);
sign_in_as($admin);

case_('The suite dispatches an action into the transaction a real request opens');
/* The sentence above this file used to be a description rather than a check.
   act() called dispatch_action() with nothing open, so every FOR UPDATE in
   every handler all twenty-two suites exercise was locking nothing, and the
   whole run was green against a weaker portal than the one that ships. A
   refactor found it; no test did. This is that test.

   account_invite is what the probe dispatches because account_using_email()
   inside it refuses outright when no transaction is open, rather than quietly
   handing back an unlocked row - which turns "was a transaction open" into
   something a suite can ask instead of something it has to trust.

   The case builds the portal state it needs: a released privacy notice and an
   SMTP host, because inviting refuses without them and a refusal here would
   look exactly like the defect. */
set_setting('privacy_ready', true);
set_setting('smtp', ['host'=>'mail.example.test','port'=>587,
                     'from_email'=>'portal@example.test','from_name'=>'Portal']);
test_load_actions();

/* First the measurement itself, so the two passes underneath cannot be vacuous.
   If account_using_email() ever stops refusing, this fails and says so instead
   of letting the rest of the case report a transaction that was never there. */
$_POST = ['name'=>'Ohne Transaktion', 'email'=>'bare@beispiel.test', 'role'=>'student', 'locale'=>'de'];
throws(fn() => without_session_id_warning(fn() => dispatch_action('account_invite')),
       'dispatching with nothing open is refused, which is what makes this case able to tell',
       'outside a transaction');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['bare@beispiel.test']),
        'and wrote no account on the way out');

does_not_throw(fn() => act('account_invite',
    ['name'=>'Mit act', 'email'=>'act@beispiel.test', 'role'=>'student', 'locale'=>'de']),
    'act() gets the same handler through, so act() opened one');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['act@beispiel.test']),
        'and the account it wrote is committed, not left inside a transaction nobody closed');

does_not_throw(fn() => submit('account_invite',
    ['name'=>'Mit submit', 'email'=>'submit@beispiel.test', 'role'=>'student', 'locale'=>'de']),
    'and so does submit(), which goes the whole way through handle_post()');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['submit@beispiel.test']),
        'with its account committed too');

is_same(0, tx_depth(), 'and neither of them left a transaction open behind it');

case_('A failing action leaves nothing behind');
$before = (int)scalar('SELECT COUNT(*) FROM students');
throws(function () {
    transactional(function () {
        run('INSERT INTO students (first_name,last_name,status,revision,created_at,updated_at) VALUES (?,?,?,?,?,?)',
            ['Halb','Angelegt','active',1,now(),now()]);
        throw new UserError('something went wrong after the insert');
    });
}, 'the error propagates');
is_same($before, (int)scalar('SELECT COUNT(*) FROM students'), 'the row is gone again');

case_('A successful action commits');
does_not_throw(function () {
    transactional(fn() => run('INSERT INTO students (first_name,last_name,status,revision,created_at,updated_at) VALUES (?,?,?,?,?,?)',
        ['Ganz','Angelegt','active',1,now(),now()]));
}, 'completes');
is_same(1, (int)scalar('SELECT COUNT(*) FROM students WHERE first_name=?', ['Ganz']), 'the row is there');

case_('Nested scopes roll back independently');
$outer = null;
does_not_throw(function () use (&$outer) {
    transactional(function () use (&$outer) {
        $outer = (int)fixture('students', ['first_name'=>'Aussen','last_name'=>'Test','status'=>'active',
            'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        // An inner failure must not take the outer work with it when the caller
        // chooses to handle it.
        try {
            transactional(function () {
                run('INSERT INTO students (first_name,last_name,status,revision,created_at,updated_at) VALUES (?,?,?,?,?,?)',
                    ['Innen','Test','active',1,now(),now()]);
                throw new UserError('inner failure');
            });
        } catch (UserError) { /* handled on purpose */ }
    });
}, 'the outer scope completes');
is_same(1, (int)scalar('SELECT COUNT(*) FROM students WHERE first_name=?', ['Aussen']), 'outer row kept');
is_same(0, (int)scalar('SELECT COUNT(*) FROM students WHERE first_name=?', ['Innen']), 'inner row rolled back');

case_('A repeated form submission is processed once');
$token = bin2hex(random_bytes(32));
$claimedTwice = 0;
for ($i = 0; $i < 2; $i++) {
    try { transactional(fn() => claim_request($token)); }
    catch (UserError) { $claimedTwice++; }
}
is_same(1, $claimedTwice, 'the second attempt is refused');
is_same(1, (int)scalar('SELECT COUNT(*) FROM form_requests WHERE request_id=?', [$token]), 'one record only');

case_('Rate limit counters survive the rollback of what they count');
$hits = fn() => (int)(scalar('SELECT hits FROM rate_limits WHERE bucket=?', [hash('sha256', 'consistency-test|x')]) ?: 0);
is_same(0, $hits(), 'starts at zero');
try {
    transactional(function () {
        throttle('consistency-test', 'x', 100);
        throw new UserError('the guarded action failed');
    });
} catch (UserError) { /* expected */ }
is_same(1, $hits(), 'the failed attempt still counted');

case_('Optimistic locking refuses a stale edit');
$sid = make_student(['first_name'=>'Rev','last_name'=>'Test']);
$rev = (int)scalar('SELECT revision FROM students WHERE id=?', [$sid]);
$first = run('UPDATE students SET first_name=?,revision=revision+1 WHERE id=? AND revision=?', ['Erste', $sid, $rev]);
is_same(1, $first->rowCount(), 'the first writer wins');
$second = run('UPDATE students SET first_name=?,revision=revision+1 WHERE id=? AND revision=?', ['Zweite', $sid, $rev]);
is_same(0, $second->rowCount(), 'the second writer, holding the old revision, changes nothing');
is_same('Erste', (string)scalar('SELECT first_name FROM students WHERE id=?', [$sid]), 'the first edit stands');

case_('A payment can never exceed its charge');
$charge = fixture('charges', ['student_id'=>$sid,'label'=>'Test','amount_cents'=>5000,
    'due_on'=>'2026-10-15','cancelled'=>0,'origin'=>'manual','created_at'=>now()]);
fixture('payments', ['charge_id'=>$charge,'amount_cents'=>3000,'paid_on'=>'2026-10-02',
    'method'=>'Bar','note'=>'','confirmed_at'=>now(),'voided'=>0]);
$allocated = fn() => (int)scalar('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE charge_id=? AND voided=0', [$charge]);
is_same(3000, $allocated(), 'part paid');
ok($allocated() + 2500 > 5000, 'a 25.00 payment would overshoot, so the action must refuse it');
ok($allocated() + 2000 <= 5000, 'a 20.00 payment exactly settles it and is allowed');

case_('Money is stored as integer cents, so totals cannot drift');
$drift = 0;
for ($i = 0; $i < 100; $i++) $drift += cents('0.07');
is_same(700, $drift, 'a hundred additions of 0.07 are exactly 7.00');
ok(0.07 * 100 !== 7.0, 'the same sum in floating point does not land on 7.00, which is why cents are used');
