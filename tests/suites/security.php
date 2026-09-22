<?php
/**
 * Authorisation, credentials and the things that must not leak.
 *
 * These assert the boundary from the application's own helpers, which is what
 * every action calls, rather than from the HTTP layer.
 */

$adminId   = make_account(['role'=>'admin','name'=>'Admin']);
$trainerId = make_account(['role'=>'trainer','name'=>'Trainer']);
$parentA   = make_account(['role'=>'student','name'=>'Eltern A']);
$parentB   = make_account(['role'=>'student','name'=>'Eltern B']);
$kidA = make_student(['account_id'=>$parentA,'first_name'=>'KindA']);
$kidB = make_student(['account_id'=>$parentB,'first_name'=>'KindB']);
$orphan = make_student(['account_id'=>null,'first_name'=>'Ohne']);

case_('A parent reaches only their own children');
sign_in_as($parentA);
does_not_throw(fn() => student($kidA), 'their own child is visible');
throws(fn() => student($kidB), 'another parent\'s child is not');
throws(fn() => student($orphan), 'a student with no account is not');
is_same(1, count(filtered_students([], $parentA)), 'the list is scoped to their own');

case_('Staff reach every student');
sign_in_as($trainerId);
does_not_throw(fn() => student($kidA), 'trainer sees A');
does_not_throw(fn() => student($kidB), 'trainer sees B');
does_not_throw(fn() => student($orphan), 'trainer sees the unlinked one');
is_same(3, count(filtered_students([])), 'the unscoped list has all three');

case_('Role helpers agree with each other');
sign_in_as($adminId);
ok(is_staff() && is_admin(), 'an administrator is staff and admin');
sign_in_as($trainerId);
ok(is_staff() && !is_admin(), 'a trainer is staff but not admin');
sign_in_as($parentA);
ok(!is_staff() && !is_admin(), 'a parent is neither');
throws(fn() => require_staff(), 'require_staff refuses a parent');
throws(fn() => require_admin(), 'require_admin refuses a parent');
sign_in_as($trainerId);
throws(fn() => require_admin(), 'require_admin refuses a trainer');
does_not_throw(fn() => require_staff(), 'require_staff accepts a trainer');

case_('Only an administrator may grant a privileged role');
is_same(['student'], assignable_roles(['role'=>'trainer']), 'a trainer may only invite parents');
is_same(['student','trainer','admin'], assignable_roles(['role'=>'admin']), 'an admin may grant anything');

case_('A login made on the spot is an administrator\'s to make, and nobody else\'s');
/* Inviting needs working SMTP and a released privacy notice, so a portal on its
   first evening had no way to make a second account at all. Handing out a
   password is more than sending a link, so this one is admin-only. */
sign_in_as($trainerId);
throws(fn() => act('account_create', ['name'=>'Zweite Trainerin', 'email'=>'zweite@beispiel.test',
    'password'=>'Federball-2026-Halle!', 'role'=>'trainer', 'locale'=>'de']),
    'a trainer cannot make one', 'Administratoren');
sign_in_as($adminId);
does_not_throw(fn() => act('account_create', ['name'=>'Zweite Trainerin', 'email'=>'zweite@beispiel.test',
    'password'=>'Federball-2026-Halle!', 'role'=>'trainer', 'locale'=>'de']),
    'an administrator can');
$made = one('SELECT * FROM accounts WHERE email=?', ['zweite@beispiel.test']);
is_same('trainer', $made['role'], 'with the role that was asked for');
is_same('active', $made['state'], 'able to sign in at once, because there is no link to click');
ok($made['verified_at'] !== null, 'and not left waiting on a verification it will never get');
ok(password_verify('Federball-2026-Halle!', $made['password_hash']), 'the password is the one that was typed');
ok(!str_contains((string)$made['password_hash'], 'Federball'), 'and is stored as a hash, not as itself');
throws(fn() => act('account_create', ['name'=>'Noch eine', 'email'=>'zweite@beispiel.test',
    'password'=>'Federball-2026-Halle!', 'role'=>'student', 'locale'=>'de']),
    'and one address cannot have two', 'schon ein Konto');
throws(fn() => act('account_create', ['name'=>'Schwach', 'email'=>'schwach@beispiel.test',
    'password'=>'badminton123', 'role'=>'student', 'locale'=>'de']),
    'a guessable password is refused here too', 'erraten');
// A family account with nothing attached signs in to an empty portal, which
// looks like a broken login rather than a missing link. Inviting from the
// child's page joins the two by address; this way in has to agree.
$waiting = make_student(['first_name'=>'Wartend', 'last_name'=>'Hofer', 'email'=>'wartend@beispiel.test']);
$elsewhere = make_student(['first_name'=>'Woanders', 'last_name'=>'Berger', 'email'=>'woanders@beispiel.test']);
act('account_create', ['name'=>'Familie Wartend', 'email'=>'wartend@beispiel.test',
    'password'=>'Federball-2026-Halle!', 'role'=>'student', 'locale'=>'de']);
$account = one('SELECT id FROM accounts WHERE email=?', ['wartend@beispiel.test']);
is_same((int)$account['id'], (int)scalar('SELECT account_id FROM students WHERE id=?', [$waiting]),
        'the child at that address is linked to it');
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$elsewhere]),
        'and a child at another address is not');
act('account_create', ['name'=>'Dritte Trainerin', 'email'=>'wartend2@beispiel.test',
    'password'=>'Federball-2026-Halle!', 'role'=>'trainer', 'locale'=>'de']);
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$elsewhere]),
        'a management account adopts nobody');

case_('A conversation is scoped to its account');
$threadA = make_thread([$parentA], ['subject'=>'A']);
$threadB = make_thread([$parentB], ['subject'=>'B']);
sign_in_as($parentA);
does_not_throw(fn() => thread_record($threadA), 'their own thread');
throws(fn() => thread_record($threadB), 'not another account\'s thread');
sign_in_as($trainerId);
does_not_throw(fn() => thread_record($threadB), 'staff see any thread');

case_('Password rules reject what a length check alone would allow');
foreach (['Correct-Horse-Battery-9','Sommer2026!Wien','Tr0mmelwirbel!x'] as $good)
    does_not_throw(fn() => strong_password($good), 'accepts '.test_show($good));
foreach (['short','passwordpassword','abababababab','aaaaaaaaaaaa','badminton123','123412341234'] as $bad)
    throws(fn() => strong_password($bad), 'rejects '.test_show($bad));
throws(fn() => strong_password(str_repeat('a', 73)), 'rejects over 72 bytes');

case_('IBANs are checked by their own checksum');
foreach (['AT611904300234573201','DE89370400440532013000','GB82WEST12345698765432','CH9300762011623852957'] as $valid)
    ok(valid_iban($valid), 'accepts '.$valid);
foreach (['AT611904300234573202','AT621904300234573201','DE89370400440532013001','XX00','notaniban',''] as $invalid)
    ok(!valid_iban($invalid), 'rejects '.test_show($invalid));

case_('An unsubscribe link cannot be forged or repointed');
$sig = unsubscribe_signature($parentA, 'newsletter');
ok(valid_unsubscribe($parentA, 'newsletter', $sig), 'the genuine link works');
ok(!valid_unsubscribe($parentB, 'newsletter', $sig), 'the same signature does not work for another account');
ok(!valid_unsubscribe($parentA, 'notifications', $sig), 'nor for another category');
ok(!valid_unsubscribe($parentA, 'newsletter', 'deadbeef'), 'a made-up signature is refused');
ok(!valid_unsubscribe($parentA, 'password_hash', unsubscribe_signature($parentA, 'password_hash')),
   'a category that is not a subscription is refused even with a matching signature');

case_('Rate limiting actually stops');
throws(function () { for ($i = 0; $i < 6; $i++) throttle('suite-limit', 'someone', 5); },
       'the sixth attempt over a limit of five is refused');

/* A sign-in has to be counted before the password is checked, because until it
   is checked nobody knows whose attempt this was. Nothing cleared that count
   afterwards, so a family whose three children share one phone reached ten
   correct sign-ins inside a quarter of an hour and was told "Zu viele
   Versuche", with no way out but waiting. These go through handle_post(), not
   act(), because the defect lived between the two. */
$familyEmail = 'familie@beispiel.test';
$familyPassword = 'Federball-2026-Halle!';
make_account(['email' => $familyEmail, 'name' => 'Familie Sieber',
              'password_hash' => password_hash($familyPassword, PASSWORD_DEFAULT)]);
$ourIp = $_SERVER['REMOTE_ADDR'] ?? 'local';
$hits = fn(string $name, string $identity) => (int)(run_counter('SELECT hits FROM rate_limits WHERE bucket=?',
    [rate_limit_bucket($name, $identity)])->fetchColumn() ?: 0);
$signIn = fn() => submit('login', ['email' => $familyEmail, 'password' => $familyPassword]);

case_('Signing in correctly never locks the family out');
$ipBefore = $hits('auth-ip', $ourIp);
does_not_throw(function () use ($signIn) { for ($i = 0; $i < 12; $i++) $signIn(); },
               'twelve correct sign-ins in a row are all let through, though the limit is ten');
is_same(0, $hits('login', $familyEmail), 'and leave nothing counted against the address');
is_same($ipBefore + 12, $hits('auth-ip', $ourIp),
        'while the per-IP counter keeps all twelve: one valid login must not refresh the limit that slows guessing at every other account');

case_('A sign-in clears its own counter and no other');
throttle('forgot', $familyEmail, 10);
is_same(1, $hits('forgot', $familyEmail), 'a reset request is counted');
does_not_throw($signIn, 'the family signs in');
is_same(1, $hits('forgot', $familyEmail),
        'and the reset-request counter stands: typing an address proves nothing about who typed it, so that bucket is never cleared');

case_('A sign-in that does not complete forgets nothing');
/* The counters sit on their own connection so that a rolled-back action cannot
   refund them, which is also why the clearing waits until the action has
   committed. A request that authenticated and then failed for some other reason
   must leave its attempt counted rather than forgetting one it never finished. */
$replayed = bin2hex(random_bytes(32));
$sent = ['email' => $familyEmail, 'password' => $familyPassword, 'request_id' => $replayed];
does_not_throw(fn() => submit('login', $sent), 'the first submission signs in');
is_same(0, $hits('login', $familyEmail), 'and its attempt is forgotten');
throws(fn() => submit('login', $sent), 'sending the very same submission again is refused as a replay', 'bereits verarbeitet');
is_same(1, $hits('login', $familyEmail), 'and that attempt stays counted, having proved nothing');

case_('Wrong passwords are still counted, and still stop');
throttle_clear('login', $familyEmail); // the suite's own clean slate, not the behaviour under test
$outcome = ['in' => 0, 'refused' => 0, 'throttled' => 0];
for ($i = 0; $i < 12; $i++) {
    try { submit('login', ['email' => $familyEmail, 'password' => 'das-ist-nicht-es']); $outcome['in']++; }
    catch (UserError $e) { str_contains($e->getMessage(), 'Zu viele') ? $outcome['throttled']++ : $outcome['refused']++; }
}
is_same(0, $outcome['in'], 'none of the twelve gets in');
is_same(10, $outcome['refused'], 'the first ten are refused on the password');
is_same(2, $outcome['throttled'], 'from the eleventh on the address is throttled before the password is looked at');
is_same(12, $hits('login', $familyEmail), 'every one of them was counted');
throws($signIn, 'and the right password does not reopen a throttled address until the window runs down', 'Zu viele');
sign_out();

case_('Choice validation rejects anything not offered');
does_not_throw(fn() => choose('de', ['de','en']), 'an offered value passes');
foreach (['fr','', 'DE','de '] as $bad) throws(fn() => choose($bad, ['de','en']), 'rejects '.test_show($bad));

case_('Escaping covers the characters that matter');
is_same('&lt;script&gt;', e('<script>'), 'angle brackets');
is_same('&quot;', e('"'), 'double quote');
is_same('&#039;', e("'"), 'single quote');
is_same('&amp;', e('&'), 'ampersand');
is_same('', e(null), 'null becomes empty, not the word null');

case_('Secrets round-trip and refuse tampering');
$sealed = seal('smtp-password-example');
is_same('smtp-password-example', unseal($sealed), 'a sealed value comes back');
ok($sealed !== 'smtp-password-example', 'and is not stored in the clear');
throws(fn() => unseal(substr($sealed, 0, -4).'AAAA'), 'a modified ciphertext is refused, not silently wrong');
throws(fn() => unseal('short'), 'a truncated value is refused');
