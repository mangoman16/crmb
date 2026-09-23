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
/* Inviting wrote the row without looking first, so two people inviting the same
   parent in the same moment both wrote, and the second one met the UNIQUE index
   instead of a sentence naming the address. It now asks the same question
   account_create asks, and holds the answer while it writes. */
throws(fn() => act('account_invite', ['name'=>'Noch eine Trainerin', 'email'=>'zweite@beispiel.test',
    'role'=>'student', 'locale'=>'de']),
    'and an address that already has an account is not invited a second time', 'schon ein Konto');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['zweite@beispiel.test']),
        'the address still has exactly one account');
is_same('trainer', (string)scalar('SELECT role FROM accounts WHERE email=?', ['zweite@beispiel.test']),
        'and the refused invitation did not change the one that was there');
/* An invitation is a row plus a link that can set a password. The two lines
   above would both hold if the account row were left alone and a working link
   sent out anyway, which is the half of the write that actually hands the
   address away. */
is_same(0, (int)scalar('SELECT COUNT(*) FROM auth_tokens t JOIN accounts a ON a.id=t.account_id WHERE a.email=?',
                       ['zweite@beispiel.test']),
        'and left no invitation link behind that could set a password on it');
/* The same measurement on an invitation that was allowed through. Without it a
   count of nought reads exactly like a portal that issues no links at all, and
   the assertion above would hold for ever without meaning anything.
   Inviting needs SMTP and a released notice, which this case deliberately does
   not have - it is the portal on its first evening. They are switched on for
   this one pair of assertions and switched off again, so the premise the rest
   of the case rests on is the one it started with. */
set_setting('smtp', ['host'=>'mail.example.test','port'=>587,'from_email'=>'portal@example.test','from_name'=>'B']);
set_setting('privacy_ready', true);
does_not_throw(fn() => act('account_invite', ['name'=>'Eingeladene Familie', 'email'=>'eingeladen@beispiel.test',
    'role'=>'student', 'locale'=>'de']), 'an address with no account is invited');
is_same(1, (int)scalar('SELECT COUNT(*) FROM auth_tokens t JOIN accounts a ON a.id=t.account_id WHERE a.email=?',
                       ['eingeladen@beispiel.test']),
        'and that one does leave a link, so the nought above is a measurement rather than a habit');
set_setting('smtp', []);
set_setting('privacy_ready', false);
throws(fn() => act('account_invite', ['name'=>'Noch jemand', 'email'=>'nochjemand@beispiel.test',
    'role'=>'student', 'locale'=>'de']),
    'and the first-evening portal is back, so what follows reads what it expects', 'SMTP');
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
$familyId = make_account(['email' => $familyEmail, 'name' => 'Familie Sieber',
                          'password_hash' => password_hash($familyPassword, PASSWORD_DEFAULT)]);
$ourIp = $_SERVER['REMOTE_ADDR'] ?? 'local';
$hits = fn(string $name, string $identity) => (int)(run_counter('SELECT hits FROM rate_limits WHERE bucket=?',
    [rate_limit_bucket($name, $identity)])->fetchColumn() ?: 0);
$signIn = fn() => submit('login', ['email' => $familyEmail, 'password' => $familyPassword]);

case_('Signing in correctly never locks the family out');
$ipBefore = $hits('auth-ip', $ourIp);
does_not_throw(function () use ($signIn) { for ($i = 0; $i < 12; $i++) $signIn(); },
               'twelve correct sign-ins in a row are all let through, though the limit is ten');
is_same(0, $hits('login', account_identity($familyId)), 'and leave nothing counted against the address');
is_same($ipBefore + 12, $hits('auth-ip', $ourIp),
        'while the per-IP counter keeps all twelve: one valid login must not refresh the limit that slows guessing at every other account');

case_('A sign-in clears its own counter and no other');
throttle('forgot', account_identity($familyId), 10);
is_same(1, $hits('forgot', account_identity($familyId)), 'a reset request is counted');
does_not_throw($signIn, 'the family signs in');
is_same(1, $hits('forgot', account_identity($familyId)),
        'and the reset-request counter stands: typing an address proves nothing about who typed it, so that bucket is never cleared');

case_('A sign-in that does not complete forgets nothing');
/* The counters sit on their own connection so that a rolled-back action cannot
   refund them, which is also why the clearing waits until the action has
   committed. A request that authenticated and then failed for some other reason
   must leave its attempt counted rather than forgetting one it never finished. */
$replayed = bin2hex(random_bytes(32));
$sent = ['email' => $familyEmail, 'password' => $familyPassword, 'request_id' => $replayed];
does_not_throw(fn() => submit('login', $sent), 'the first submission signs in');
is_same(0, $hits('login', account_identity($familyId)), 'and its attempt is forgotten');
throws(fn() => submit('login', $sent), 'sending the very same submission again is refused as a replay', 'bereits verarbeitet');
is_same(1, $hits('login', account_identity($familyId)), 'and that attempt stays counted, having proved nothing');

case_('Spelling the address differently does not buy a fresh ten guesses');
/* accounts.email is compared by the database, under a collation that folds
   case, accents, ss against ß, ligatures and full-width letters. The throttle
   was keyed on the PHP string that was typed, so 'familie@beispiel.test' and
   'famílie@beispiel.test' found the same family and counted into two separate
   buckets: ten guesses per spelling, and nobody is short of spellings. What was
   left was the per-IP limit, which an attacker with more than one address walks
   past. The same trick minted fresh 'forgot' buckets, so the cap that stops
   somebody mailing a family over and over - each link invalidating the one they
   are in the middle of using - went with it. */
throttle_clear('login', account_identity($familyId));
$_POST = ['email' => $familyEmail];
is_same(account_identity($familyId), attempted_identity(),
        'an attempt at an address that has an account is counted against the account');
$_POST = ['email' => 'FAMILIE@beispiel.test'];
is_same(account_identity($familyId), attempted_identity(), 'however it is capitalised');
$_POST = ['email' => 'niemand@beispiel.test'];
is_same('niemand@beispiel.test', attempted_identity(),
        'and an address with no account against what was typed, which is all there is to count');

/* The half only a real engine can show. Whether two spellings are one row is
   the database's ruling, and the sqlite translation compares bytes, so it is
   asked rather than assumed - a suite that pretended otherwise would report
   this as covered on the driver that cannot cover it. */
$respelled = 'famílie@beispiel.test';
if ((bool)scalar('SELECT 1 FROM accounts WHERE email=? LIMIT 1', [$respelled])) {
    $_POST = ['email' => $respelled];
    is_same(account_identity($familyId), attempted_identity(),
            'a spelling this engine reads as the same row lands in the same bucket');
    // So that however much traffic the rest of the suite sent from this address
    // cannot decide the case.
    run_counter('UPDATE rate_limits SET window_start = window_start - 901 WHERE bucket = ?',
                [rate_limit_bucket('auth-ip', $ourIp)]);
    for ($i = 0; $i < 10; $i++) {
        try { submit('login', ['email' => $familyEmail, 'password' => 'falsch-geraten']); }
        catch (UserError $e) { /* wrong password, counted */ }
    }
    is_same(10, $hits('login', account_identity($familyId)), 'ten wrong guesses at one spelling are counted');
    throws(fn() => submit('login', ['email' => $respelled, 'password' => 'auch-falsch']),
           'and the eleventh, typed with an accent, is refused instead of starting a fresh ten', 'Zu viele');
    throttle_clear('login', account_identity($familyId));
    does_not_throw(fn() => submit('login', ['email' => $respelled, 'password' => $familyPassword]),
                   'while the family itself gets in with the right password, spelled either way');
    sign_out();
} else {
    test_unsupported(array_merge(test_unsupported(),
        ['two spellings of one address sharing a throttle bucket (needs the MySQL collation)']));
}

case_('An emailed link ends the lockout, whatever the link was sent for');
/* Opening a one-time link proves the same thing a typed password proves:
   whoever did it reads the mailbox that address belongs to. All three purposes
   a link can carry - an invitation accepted, a password reset, a changed
   address confirmed - end in sign_in(), so all three clear the bucket. Only the
   reset was ever written down, and an invitation is the one that would look
   like an oversight: a family locked out by a week of wrong guesses at an
   address they had not finished setting up would accept the invitation and find
   themselves still locked out of the sign-in that follows it. */
set_setting('privacy_ready', true);
$invitedEmail = 'neuzugang@beispiel.test';
$invitedId = make_account(['email' => $invitedEmail, 'name' => 'Familie Neuzugang',
                           'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
for ($i = 0; $i < 3; $i++) throttle('login', account_identity($invitedId), 10);
is_same(3, $hits('login', account_identity($invitedId)), 'three guesses stand against the address');
$_SESSION['activation_hash'] = hash('sha256', make_token($invitedId, 'invite'));
does_not_throw(fn() => submit('activate', ['password' => 'Federball-2026-Halle!',
    'password_confirm' => 'Federball-2026-Halle!', 'privacy_seen' => '1', 'notifications' => '1']),
    'the invitation is accepted');
is_same(0, $hits('login', account_identity($invitedId)), 'and the attempts counted against that address are forgotten');
sign_out();

case_('A request that signed nobody in forgets nothing');
/* Both branches ask the session who is there rather than taking "nothing threw"
   for an answer. The throw happens in handle_post(), which is a fact about
   another file; this one should still be right if that ever changes. */
sign_out();
throttle_clear('login', account_identity($familyId)); // a known slate, not the behaviour under test
throttle('login', account_identity($familyId), 10);
is_same(1, $hits('login', account_identity($familyId)), 'one attempt is counted');
$_POST = ['email' => $familyEmail];   // the address was typed; nobody got in with it
forget_attempts_after_success('login');
is_same(1, $hits('login', account_identity($familyId)), 'a sign-in that signed nobody in clears nothing');
forget_attempts_after_success('activate');
is_same(1, $hits('login', account_identity($familyId)), 'and neither does an activation that activated nobody');
throttle_clear('login', account_identity($familyId)); // the suite's own clean slate, not the behaviour under test

case_('Wrong passwords are still counted, and still stop');
throttle_clear('login', account_identity($familyId)); // the suite's own clean slate, not the behaviour under test
$outcome = ['in' => 0, 'refused' => 0, 'throttled' => 0];
for ($i = 0; $i < 12; $i++) {
    try { submit('login', ['email' => $familyEmail, 'password' => 'das-ist-nicht-es']); $outcome['in']++; }
    catch (UserError $e) { str_contains($e->getMessage(), 'Zu viele') ? $outcome['throttled']++ : $outcome['refused']++; }
}
is_same(0, $outcome['in'], 'none of the twelve gets in');
is_same(10, $outcome['refused'], 'the first ten are refused on the password');
is_same(2, $outcome['throttled'], 'from the eleventh on the address is throttled before the password is looked at');
is_same(12, $hits('login', account_identity($familyId)), 'every one of them was counted');
throws($signIn, 'and the right password does not reopen a throttled address until the window runs down', 'Zu viele');
sign_out();

case_('And a quarter of an hour later she gets in again');
/* "Zu viele Versuche. Bitte später erneut versuchen." is a promise that later
   arrives. throttle() keeps it by resetting hits to 1 once window_start falls
   behind time() - $seconds, and nothing asserted that a locked-out address ever
   comes back: a reset that never fired would have looked exactly like a working
   throttle to every case above, and she would have been locked out for good.

   Nothing here can move the clock - throttle() reads time() directly. What it
   compares against is one stored number, so the window is aged by moving its
   start backwards, which leaves the row in the state it is in after that many
   seconds really have passed. That proves throttle()'s reset arithmetic. It
   does not prove the clock source, and TESTING.md 4.6c walks the real wait. */
$ageWindow = fn(string $name, string $identity, int $seconds) => run_counter(
    'UPDATE rate_limits SET window_start = window_start - ? WHERE bucket = ?',
    [$seconds, rate_limit_bucket($name, $identity)]);

// Built here rather than inherited from the case above: this one has to start
// from a known number of attempts whatever else has run first.
$waitingEmail = 'wartende@beispiel.test';
$waitingPassword = 'Schlaeger-Tasche-2026!';
$waitingId = make_account(['email' => $waitingEmail, 'name' => 'Familie Wartinger',
                           'password_hash' => password_hash($waitingPassword, PASSWORD_DEFAULT)]);
$rightPassword = fn() => submit('login', ['email' => $waitingEmail, 'password' => $waitingPassword]);
$wrongPassword = fn() => submit('login', ['email' => $waitingEmail, 'password' => 'falsch-getippt']);
// The per-IP bucket is aged too, so that how much traffic the rest of this
// suite sent from the same address cannot decide whether this case passes.
$ageWindow('auth-ip', $ourIp, 901);
for ($i = 0; $i < 11; $i++) { try { $wrongPassword(); } catch (UserError $e) {} }
is_same(11, $hits('login', account_identity($waitingId)), 'eleven attempts stand against the address');
throws($rightPassword, 'the right password is refused while the window is still open', 'Zu viele');

// The negative first. Without it a throttle() that reset on every single call
// would pass the rest of this case, and the limit would stop nobody.
$ageWindow('login', account_identity($waitingId), 600);
throws($rightPassword, 'ten minutes in is not yet later, and it is still refused', 'Zu viele');
is_same(13, $hits('login', account_identity($waitingId)), 'and those refusals are counted too, rather than sitting still');

$ageWindow('login', account_identity($waitingId), 400); // 1000 seconds in total, past the quarter of an hour
throws($wrongPassword, 'once the window has run down the password is looked at again',
       'Anmeldung nicht möglich');
is_same(1, $hits('login', account_identity($waitingId)),
        'and the counter starts the new window at one, rather than carrying the old thirteen over');
does_not_throw($rightPassword, 'so the family signs in with the same password that was refused a moment ago');
is_same(0, $hits('login', account_identity($waitingId)), 'and that sign-in clears the counter behind it');
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
