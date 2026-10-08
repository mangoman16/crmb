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

case_('The pictures’ folder holds the problem reports’ screenshots, and the route hands one to an administrator only [ADR 0026 §8]');
/* serve_download() is the route itself. A refusal throws before a byte is read,
   which the front controller turns into a 403 or a 404. The profile pictures
   went; a screenshot can show a family's page, and is for the administrators
   who read the reports. */
$shot = fixture('feedback', ['account_id'=>$parentA, 'page'=>'dashboard', 'message'=>'Kaputt', 'context_json'=>'{}',
                             'screenshot_name'=>str_repeat('a', 32).'.png', 'created_at'=>now()]);
@mkdir(upload_dir('avatar'), 0775, true);
file_put_contents(upload_dir('avatar').'/'.str_repeat('a', 32).'.png', 'a screenshot');
/* Serving a file ends the request with exit, and exit here would end the whole
   run quietly with status 0. So while the route is being asked, an exit is
   caught on the way out and reported as the failure it is. */
$routeCall = new stdClass; $routeCall->asking = '';
register_shutdown_function(function () use ($routeCall) {
    if ($routeCall->asking === '') return;
    fwrite(STDERR, "\nFAIL security: the download route served ".$routeCall->asking." instead of refusing, and ended the run.\n");
    exit(1);
});
$download = function (string $what, int $id, string $kind = '') use ($routeCall): void {
    $_GET = ['page'=>'download', 'what'=>$what, 'kind'=>$kind, 'id'=>(string)$id];
    $routeCall->asking = trim($what.' '.$kind).' '.$id;
    try { serve_download(); } finally { $_GET = []; $routeCall->asking = ''; }
};
$refused = function (callable $fn, string $what) {
    try { $fn(); ok(false, $what); } catch (Throwable $e) { ok($e instanceof NotFound, $what.' (a 404, not '.get_class($e).')'); }
};
foreach ([$parentA => 'the family who sent it', $trainerId => 'a trainer'] as $who => $whom) {
    sign_in_as($who);
    throws(fn() => $download('shot', $shot), $whom.' asking for the screenshot gets nothing', 'Nur für Administratoren');
}
sign_in_as($parentA);
foreach (['student' => $kidA, 'account' => $parentA] as $kind => $id)
    does_not_throw(fn() => $download('avatar', $id, $kind), 'a picture asked for by its old address, of the '.$kind.', is served from nowhere');

case_('A file in a chat reaches only who may read the chat, and a removed one nobody');
/* The same route and the same catch as above: a refusal is a 404 before a byte
   is read, and a file served would end the run and be reported. */
sign_in_as($parentA);
$fileIn = function (int $threadId, string $fill): int {
    $message = fixture('messages', ['thread_id'=>$threadId, 'sender_id'=>(int)current_user()['id'], 'body'=>'Foto', 'created_at'=>now()]);
    @mkdir(upload_dir('message'), 0775, true);
    file_put_contents(upload_dir('message').'/'.str_repeat($fill, 32).'.jpg', 'a photo');
    return fixture('message_files', ['message_id'=>$message, 'kind'=>'image', 'stored_name'=>str_repeat($fill, 32).'.jpg',
        'original_name'=>'foto.jpg', 'mime'=>'image/jpeg', 'bytes'=>7, 'seconds'=>0, 'created_at'=>now()]);
};
$toTrainer = $fileIn(direct_thread(current_user(), $trainerId), 'f');
$course = make_class(['name'=>'Dienstagsgruppe']);
make_enrolment($course, $kidA);
$inGroup = $fileIn(course_group_thread($course), '9');
sign_in_as($parentB);
$refused(fn() => $download('attachment', $toTrainer), 'family B asking for a photo family A sent the trainer gets nothing');
$refused(fn() => $download('attachment', $inGroup), 'nor for one in a course group their child is not in');
sign_in_as($trainerId);
moderate_message((int)scalar('SELECT message_id FROM message_files WHERE id=?', [$inGroup]), true);
does_not_throw(fn() => $download('attachment', $inGroup), 'a photo taken down from the group is served to nobody, the trainer included');

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

case_('Only an administrator may grant a role, and the Konten page grants staff roles only');
/* A student's login is made on that student's page, because it belongs to
   exactly one student (ADR 0010). Made from the Konten page it would be a login
   attached to nobody - and "a trainer may invite parents" was how siblings came
   to share one. */
is_same([], assignable_roles(['role'=>'trainer']), 'a trainer grants nothing from the Konten page');
is_same(['trainer','admin'], assignable_roles(['role'=>'admin']), 'an administrator grants the staff roles');

case_('A staff login is made by invitation, by an administrator, and an address has one login');
/* ADR 0020, §5: nobody sets another person's password. account_create, which
   made a login with a password staff typed, is gone; a trainer or an
   administrator is invited and chooses their own. */
sign_in_as($trainerId);
mail_ready(true);
throws(fn() => act('account_invite', ['name'=>'Zweite Trainerin', 'email'=>'zweite@beispiel.test', 'role'=>'trainer', 'locale'=>'de']),
    'a trainer cannot invite one', 'Administratoren');
throws(fn() => act('account_create', ['name'=>'Zweite Trainerin', 'email'=>'zweite@beispiel.test',
    'password'=>'Federball-2026-Halle!', 'role'=>'trainer', 'locale'=>'de']),
    'and a page that still posts account_create makes nothing', 'Unbekannte Aktion');
sign_in_as($adminId);
throws(fn() => act('account_create', ['name'=>'Zweite Trainerin', 'email'=>'zweite@beispiel.test',
    'password'=>'Federball-2026-Halle!', 'role'=>'trainer', 'locale'=>'de']),
    'not even for an administrator', 'Unbekannte Aktion');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['zweite@beispiel.test']), 'no login was written');
does_not_throw(fn() => act('account_invite', ['name'=>'Zweite Trainerin', 'email'=>'zweite@beispiel.test',
    'role'=>'trainer', 'locale'=>'de']), 'an administrator invites one');
$made = one('SELECT * FROM accounts WHERE email=?', ['zweite@beispiel.test']);
is_same(['trainer', 'invited', null], [$made['role'] ?? null, $made['state'] ?? null, $made['password_hash'] ?? null],
        'with the role that was asked for, waiting, and with no password anybody but she will choose');
throws(fn() => act('account_invite', ['name'=>'Noch eine', 'email'=>'zweite@beispiel.test', 'role'=>'admin', 'locale'=>'de']),
    'an address that already has a login is not invited a second time', 'Jede Person braucht ihre eigene');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['zweite@beispiel.test']),
        'the address still has exactly one account');
is_same('trainer', (string)scalar('SELECT role FROM accounts WHERE email=?', ['zweite@beispiel.test']),
        'and the refused invitation did not change the one that was there');
/* An invitation is a row plus a link that can set a password. The two lines
   above would both hold if the row were left alone and a second working link
   sent out anyway, which is the half of the write that hands the address away.
   One link stands: the first invitation's, which shows the count is a
   measurement rather than a habit. */
is_same(1, (int)scalar('SELECT COUNT(*) FROM auth_tokens t JOIN accounts a ON a.id=t.account_id WHERE a.email=?',
                       ['zweite@beispiel.test']),
        'and left no second invitation link behind that could set a password on it');
mail_ready(false);
throws(fn() => act('account_invite', ['name'=>'Noch jemand', 'email'=>'nochjemand@beispiel.test',
    'role'=>'trainer', 'locale'=>'de']),
    'on a portal whose mail is not ready, inviting is refused and says what is missing', 'SMTP');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['nochjemand@beispiel.test']), 'and leaves no login waiting without a link');

case_('A student’s login is not made from the Konten page, and nothing is linked from there');
/* A student's login is made on the student's page, by invite_student(). */
$waiting = make_student(['first_name'=>'Wartend', 'last_name'=>'Hofer', 'email'=>'wartend@beispiel.test']);
mail_ready(true);
throws(fn() => act('account_invite', ['name'=>'Familie Wartend', 'email'=>'wartend@beispiel.test',
    'role'=>'student', 'locale'=>'de']),
    'inviting one is refused, and says where to go instead', 'Seite der Schülerin oder des Schülers');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['wartend@beispiel.test']), 'no account was written');
// Every student has a login from the start - a placeholder until somebody gives
// it an address (ADR 0023 §3, 0030 §3) - so "not touched" is: the same one.
$waitingLogin = (int)scalar('SELECT account_id FROM students WHERE id=?', [$waiting]);
is_same([$waitingLogin, 'placeholder'], [(int)scalar('SELECT account_id FROM students WHERE id=?', [$waiting]), (string)scalar('SELECT state FROM accounts WHERE id=?', [$waitingLogin])],
        'and the student at that address was not touched: still on their own placeholder');
act('account_invite', ['name'=>'Dritte Trainerin', 'email'=>'wartend@beispiel.test', 'role'=>'trainer', 'locale'=>'de']);
is_same($waitingLogin, (int)scalar('SELECT account_id FROM students WHERE id=?', [$waiting]),
        'a staff login at a student’s address adopts nobody either');
mail_ready(false);
sign_in_as($trainerId);
throws(fn() => act('account_invite', ['name'=>'Neue Trainerin', 'email'=>'neu-trainerin@beispiel.test',
    'role'=>'trainer', 'locale'=>'de']),
    'inviting from the Konten page is an administrator’s to do', 'Administratoren');
sign_in_as($adminId);

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

case_('An unsubscribe link works for 90 days after its mail, then says where the switches are instead');
/* It never expired: a link in a forwarded mail or a screenshot switched a
   family's mail off for ever (security batch). The day it stops travels inside
   its signature, which covers it, so nobody can move it on. */
$fresh = unsubscribe_signature($parentA, 'payments');
ok(abs((int)strtok($fresh, '.') - (time() + 90 * 86400)) <= 5, 'a link made now works for 90 days');
ok(valid_unsubscribe($parentA, 'payments', $fresh), 'and works today');
$lapsed = unsubscribe_signature($parentA, 'payments', time() - 1);
ok(!valid_unsubscribe($parentA, 'payments', $lapsed), 'one whose day has passed does not');
ok(!valid_unsubscribe($parentA, 'payments', (string)preg_replace('/^[0-9]+/', (string)(time() + 999 * 86400), $lapsed)),
   'nor one whose day was moved on by hand');
ok(!valid_unsubscribe($parentA, 'payments', hash_hmac('sha256', $parentA.'|payments', base64_decode(config('app_key')))),
   'nor one from before links had a day, which would have worked for ever');
sign_out();
$expired = render_view('unsubscribe', ['account'=>(string)$parentA, 'category'=>'payments', 'signature'=>$lapsed]);
ok(str_contains($expired, e(unsubscribe_refusal())) && !str_contains($expired, 'value="unsubscribe"'),
   'an old link’s page says where the switches are, and offers nothing to confirm');
throws(fn() => act('unsubscribe', ['account'=>(string)$parentA, 'category'=>'payments', 'signature'=>$lapsed]),
       'sent anyway, it is refused in the same words', unsubscribe_refusal());
is_same(1, (int)scalar('SELECT payment_notices FROM accounts WHERE id=?', [$parentA]), 'and the reminders stay on');
act('unsubscribe', ['account'=>(string)$parentA, 'category'=>'payments', 'signature'=>$fresh]);
is_same(0, (int)scalar('SELECT payment_notices FROM accounts WHERE id=?', [$parentA]), 'while the link from a recent mail switches them off');
run('UPDATE accounts SET payment_notices=1 WHERE id=?', [$parentA]);

case_('The unsubscribe page says what each kind of mail is about, and a payment reminder’s says payments');
/* For everything but news it said „Keine E-Mail-Hinweise für private
   Nachrichten mehr erhalten", so a payment reminder's link offered to stop the
   notices of new messages. */
foreach (array_keys(unsubscribe_categories()) as $category)
    does_not_throw(fn() => unsubscribe_stops($category), '„'.$category.'“ has words of its own');
is_same(count(unsubscribe_categories()), count(array_unique(array_map('unsubscribe_stops', array_keys(unsubscribe_categories())))),
        'and no two have the same');
$reminderPage = render_view('unsubscribe', ['account'=>(string)$parentA, 'category'=>'payments', 'signature'=>$fresh]);
ok(str_contains($reminderPage, e(unsubscribe_stops('payments'))) && str_contains($reminderPage, 'Beiträgen') && !str_contains($reminderPage, 'Nachrichten'),
   'a payment reminder’s link says it stops the mails about payments, and nothing about messages');
sign_in_as($trainerId);

case_('A family sends twenty receipts an hour, and the twenty-first is refused before anything is looked at');
/* proof_upload stored a file for every request and counted none of them: a
   thousand receipts are a full disk. */
sign_in_as($parentA);
$_FILES = [];
$answers = [];
for ($i = 0; $i < 21; $i++) {
    try { act('proof_upload', ['student_id'=>(string)$kidA]); $answers[] = 'stored'; }
    catch (UserError $e) { $answers[] = $e->getMessage(); }
}
is_same(array_fill(0, 20, 'Es wurde keine Datei ausgewählt.'), array_slice($answers, 0, 20), 'twenty are each answered for what they carry');
ok(str_starts_with((string)($answers[20] ?? ''), 'Zu viele Versuche'), 'the twenty-first is told to wait');
throttle_clear('proof', (string)$parentA);
sign_in_as($trainerId);

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
$familyBucket = address_identity($familyEmail);
$ourIp = $_SERVER['REMOTE_ADDR'] ?? 'local';
$hits = fn(string $name, string $identity) => (int)(run_counter('SELECT hits FROM rate_limits WHERE bucket=?',
    [rate_limit_bucket($name, $identity)])->fetchColumn() ?: 0);
$signIn = fn() => submit('login', ['login' => $familyEmail, 'password' => $familyPassword]);

case_('Signing in correctly never locks the family out');
$ipBefore = $hits('auth-ip', $ourIp);
does_not_throw(function () use ($signIn) { for ($i = 0; $i < 12; $i++) $signIn(); },
               'twelve correct sign-ins in a row are all let through, though the limit is ten');
is_same(0, $hits('login', $familyBucket), 'and leave nothing counted against the address');
is_same($ipBefore + 12, $hits('auth-ip', $ourIp),
        'while the per-IP counter keeps all twelve: one valid login must not refresh the limit that slows guessing at every other account');

case_('A sign-in clears its own counter and no other');
throttle('forgot', $familyBucket, 3, 3600);
is_same(1, $hits('forgot', $familyBucket), 'a reset request is counted');
does_not_throw($signIn, 'the family signs in');
is_same(1, $hits('forgot', $familyBucket),
        'and the reset-request counter stands: typing an address proves nothing about who typed it, so that bucket is never cleared');

case_('A sign-in that does not complete forgets nothing');
/* The counters sit on their own connection so that a rolled-back action cannot
   refund them, which is also why the clearing waits until the action has
   committed. A request that authenticated and then failed for some other reason
   must leave its attempt counted rather than forgetting one it never finished. */
$replayed = bin2hex(random_bytes(32));
$sent = ['login' => $familyEmail, 'password' => $familyPassword, 'request_id' => $replayed];
does_not_throw(fn() => submit('login', $sent), 'the first submission signs in');
is_same(0, $hits('login', $familyBucket), 'and its attempt is forgotten');
// A copy this session remembers answering lands where the first went and
// counts nothing (answered_form_landing()). One arriving where its answer is
// not remembered reaches the claim, which refuses it.
unset($_SESSION['answered_forms']);
throws(fn() => submit('login', $sent), 'sending the very same submission again, where its answer is not remembered, is refused as a replay', 'bereits verarbeitet');
is_same(1, $hits('login', $familyBucket), 'and that attempt stays counted, having proved nothing');

case_('Spelling an address differently does not buy a fresh ten guesses, and nothing is looked up to count');
/* What was typed is counted after email_normalised(): capitals and spaces land
   in one bucket in PHP, on every engine, and an address that has a login is
   counted exactly like one that does not. Resolving the input to its row first
   would be ADR 0007's oracle again. */
throttle_clear('login', $familyBucket);
foreach (['familie@beispiel.test', ' Familie@Beispiel.TEST', "FAMILIE@beispiel.test\n"] as $spelling) {
    $_POST = ['login' => $spelling];
    is_same($familyBucket, address_identity(attempted_address()), 'counted in the one bucket: '.json_encode($spelling));
}
$actions = (string)file_get_contents(APP_ROOT.'/app/actions.php');
ok(!str_contains($actions, 'function attempted_identity'), 'and no step resolves the typed value to a row first');
$handle = (string)strstr((string)strstr($actions, 'function handle_post('), 'function forget_attempts_after_success(', true);
ok(str_contains($handle, "throttle('login',address_identity(attempted_address()),10);"),
   'the request counts the typed address, before anything is looked up');
$lockout = function (string $typed) use ($hits, $ourIp): array {
    run_counter('UPDATE rate_limits SET window_start = window_start - 901 WHERE bucket = ?', [rate_limit_bucket('auth-ip', $ourIp)]);
    $answers = [];
    for ($i = 0; $i < 11; $i++) {
        try { submit('login', ['login' => $typed, 'password' => 'falsch-geraten']); $answers[] = 'in'; }
        catch (UserError $e) { $answers[] = str_contains($e->getMessage(), 'Zu viele') ? 'throttled' : $e->getMessage(); }
    }
    $_POST = ['login' => $typed];
    $bucket = address_identity(attempted_address());
    $counted = $hits('login', $bucket);
    throttle_clear('login', $bucket);
    return [$answers, $counted];
};
$known = $lockout($familyEmail);
is_same(11, count($known[0]), 'eleven tries');
is_same(['throttled'], array_slice($known[0], 10), 'an address that has a login is refused ten times, then throttled at the eleventh');
is_same($known, $lockout('niemand@beispiel.test'), 'and one that has none gets exactly the same answers, counted exactly the same way');
is_same($known, $lockout('familie.sieber'), 'and so does something that is no address at all');

case_('The address signs in, in any capitals, and nothing else does');
/* ADR 0030 §1: the address is the only name a login has. The name this family's
   login carried as a username under ADR 0019 and 0023 is nothing now, refused
   in the same words as a wrong password and counted like any typed value. */
sign_out();
does_not_throw(fn() => submit('login', ['login' => ' Familie@Beispiel.test ', 'password' => $familyPassword]), 'the address signs in, in any capitals');
is_same($familyId, (int)(current_user()['id'] ?? 0), 'as the right login');
sign_out();
throws(fn() => submit('login', ['login' => 'familie.sieber', 'password' => $familyPassword]),
       'the name that was once its username is refused, with the right password', 'Anmeldung nicht möglich');
is_same(null, current_user(), 'and nobody is signed in');
foreach (['username', 'email'] as $otherBox)
    throws(fn() => submit('login', [$otherBox => $familyEmail, 'password' => $familyPassword]),
           'and the address posted under any name but the one box, login, signs nobody in: '.$otherBox, 'Anmeldung nicht möglich');
throttle_clear('login', address_identity('familie.sieber')); throttle_clear('login', address_identity(''));
$_POST = [];

case_('An address that reaches a login only through the collation signs nobody in');
/* ADR 0021, §1: a row is used only if it is exactly what was typed.
   utf8mb4_unicode_ci reads ß as ss, so 'strasse@…' finds a legacy row stored as
   'straße@…'; the exact-match check refuses it. */
$folded = make_account(['email' => 'straße@beispiel.test', 'password_hash' => password_hash($familyPassword, PASSWORD_DEFAULT)]);
ok(one('SELECT id FROM accounts WHERE email=?', ['strasse@beispiel.test']) !== null, 'the collation does find the row, so this is a real test');
throws(fn() => submit('login', ['login' => 'strasse@beispiel.test', 'password' => $familyPassword]), 'but it does not sign in', 'Anmeldung nicht möglich');
is_same(null, current_user(), 'nobody is signed in');
throttle_clear('login', address_identity('strasse@beispiel.test'));
run('DELETE FROM accounts WHERE id=?', [$folded]);

case_('Every refusal checks one password, says the same words, and never uses a hash written into the code');
/* M2. A missing address, a value that cannot be one, an invitation without a
   password, a suspended login and a wrong password all cost one
   password_verify(), and all get the one sentence, so neither the time nor the
   words say which it was. The hash for "nothing to check" is the stored
   sign_in_dummy_hash(), at today's PASSWORD_DEFAULT cost - not a literal from
   the year it was written, which PHP 8.4 had already made cheaper. */
$login = (string)strstr((string)strstr($actions, "case 'login':"), "case 'logout':", true);
ok($login !== '', 'the login case was found');
// Read as code, so a comment that names the function is not counted as a call.
$loginCode = implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                                   token_get_all("<?php\n".$login)));
is_same(1, substr_count($loginCode, 'password_verify('), 'the case has exactly one password_verify(), which every refusal passes through');
ok(!str_contains($loginCode, '$2y$'), 'and no literal hash');
ok(str_contains($loginCode, 'account_for_sign_in(attempted_address())'), 'it looks the login up through account_for_sign_in()');
$dummy = sign_in_dummy_hash();
ok($dummy !== '' && !password_needs_rehash($dummy, PASSWORD_DEFAULT), 'the comparison hash exists at today’s cost');
ok(!password_verify('', $dummy) && !password_verify('Test-Only-Password-2026', $dummy), 'and matches no password anybody has');
is_same($dummy, sign_in_dummy_hash(), 'a request asking again gets the same one: nothing is hashed per sign-in');
make_account(['email' => 'eingeladen-noch@beispiel.test', 'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
make_account(['email' => 'gesperrt@beispiel.test', 'state' => 'suspended', 'password_hash' => password_hash($familyPassword, PASSWORD_DEFAULT)]);
$said = [];
foreach (['an invitation not yet accepted' => ['eingeladen-noch@beispiel.test', ''], 'a suspended login with its right password' => ['gesperrt@beispiel.test', $familyPassword],
          'a value that cannot be an address' => ['a..b@beispiel.test', $familyPassword],
          'an address nobody has' => ['niemand@beispiel.test', $familyPassword], 'a wrong password' => [$familyEmail, 'falsch']] as $what => [$typed, $typedPassword]) {
    try { submit('login', ['login' => $typed, 'password' => $typedPassword]); $said[$what] = 'in'; }
    catch (UserError $e) { $said[$what] = $e->getMessage(); }
    $_POST = ['login' => $typed]; throttle_clear('login', address_identity(attempted_address()));
}
is_same(['Anmeldung nicht möglich. Bitte E-Mail-Adresse und Passwort prüfen. Noch nicht eingerichtet? Dann zuerst den Link aus der Einladung öffnen.'],
        array_values(array_unique($said)), 'every one of them gets the one sentence, word for word: '.implode(', ', array_keys($said)));
$_POST = [];

case_('An outdated password hash is brought to today’s cost by the next correct sign-in');
$cheap = make_account(['email' => 'alt.hash@beispiel.test', 'password_hash' => password_hash($familyPassword, PASSWORD_BCRYPT, ['cost' => 4])]);
does_not_throw(fn() => submit('login', ['login' => 'alt.hash@beispiel.test', 'password' => $familyPassword]), 'the old hash still signs in');
$renewed = (string)scalar('SELECT password_hash FROM accounts WHERE id=?', [$cheap]);
ok(!password_needs_rehash($renewed, PASSWORD_DEFAULT) && password_verify($familyPassword, $renewed), 'and is replaced by one at today’s cost, of the same password');
sign_out();

case_('A restored database without the comparison hash gets one from its first sign-in, once');
/* R9's one repair. Made before the action's transaction opens, so the refusal
   that follows cannot roll it back and make every refusal hash again. */
run("DELETE FROM settings WHERE setting_key='sign_in_dummy_hash'"); setting_cache_clear();
run_counter('UPDATE rate_limits SET window_start = window_start - 901 WHERE bucket = ?', [rate_limit_bucket('auth-ip', $ourIp)]);
is_same('', (string)setting('sign_in_dummy_hash'), 'there is none');
$logged = ini_get('error_log'); $logFile = test_run_dir().'/dummy-hash.log'; ini_set('error_log', $logFile);
try { throws(fn() => submit('login', ['login' => 'niemand@beispiel.test', 'password' => 'falsch']), 'a refused sign-in', 'Anmeldung nicht möglich'); }
finally { ini_set('error_log', (string)$logged); }
setting_cache_clear();
$repaired = (string)setting('sign_in_dummy_hash');
ok($repaired !== '', 'leaves one stored, although the sign-in itself was rolled back');
ok(str_contains((string)@file_get_contents($logFile), 'sign_in_dummy_hash'), 'and says so in the log');
throws(fn() => submit('login', ['login' => 'niemand@beispiel.test', 'password' => 'falsch']), 'the next refusal', 'Anmeldung nicht möglich');
setting_cache_clear();
is_same($repaired, (string)setting('sign_in_dummy_hash'), 'uses that one rather than making another');
throttle_clear('login', address_identity('niemand@beispiel.test'));

case_('The nightly prune keeps the comparison hash at today’s cost, and leaves a current one alone');
set_setting('sign_in_dummy_hash', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]));
prune_expired(); setting_cache_clear();
$refreshed = (string)setting('sign_in_dummy_hash');
ok(!password_needs_rehash($refreshed, PASSWORD_DEFAULT), 'a hash from a cheaper default is made again');
prune_expired(); setting_cache_clear();
is_same($refreshed, (string)setting('sign_in_dummy_hash'), 'and a current one is kept');
$declared = setting_schema()['sign_in_dummy_hash'] ?? [];
is_same(['raw', '', true], [$declared['kind'] ?? null, $declared['default'] ?? null, $declared['internal'] ?? null],
        'declared once, as an internal raw setting that starts empty');

case_('An emailed link ends the lockout, whatever the link was sent for');
/* Opening a one-time link proves the same thing a typed password proves:
   whoever did it reads the mailbox that address belongs to. All three purposes
   a link can carry - an invitation accepted, a password reset, a changed
   address confirmed - end in sign_in(), so all three clear the bucket. An
   invitation is the one that would look like an oversight: a family locked out
   by a week of wrong guesses at an address they had not finished setting up
   would accept the invitation and find themselves still locked out of the
   sign-in that follows it. */
set_setting('privacy_ready', true);
$invitedEmail = 'neuzugang@beispiel.test';
$invitedId = make_account(['email' => $invitedEmail, 'name' => 'Familie Neuzugang',
                           'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
make_student(['first_name' => 'Neu', 'last_name' => 'Zugang', 'email' => $invitedEmail, 'account_id' => $invitedId]);
for ($i = 0; $i < 3; $i++) throttle('login', address_identity($invitedEmail), 10);
is_same(3, $hits('login', address_identity($invitedEmail)), 'three guesses stand against the address');
$_SESSION['activation_hash'] = hash('sha256', make_token($invitedId, 'invite'));
does_not_throw(fn() => submit('activate', ['password' => 'Federball-2026-Halle!',
    'password_confirm' => 'Federball-2026-Halle!', 'privacy_seen' => '1', 'notifications' => '1']),
    'the invitation is accepted');
is_same(0, $hits('login', address_identity($invitedEmail)), 'and the attempts counted against that address are forgotten');
sign_out();

case_('A request that signed nobody in forgets nothing');
/* Both branches ask the session who is there rather than taking "nothing threw"
   for an answer. The throw happens in handle_post(), which is a fact about
   another file; this one should still be right if that ever changes. */
sign_out();
throttle_clear('login', $familyBucket); // a known slate, not the behaviour under test
throttle('login', $familyBucket, 10);
is_same(1, $hits('login', $familyBucket), 'one attempt is counted');
$_POST = ['login' => $familyEmail];   // the address was typed; nobody got in with it
forget_attempts_after_success('login');
is_same(1, $hits('login', $familyBucket), 'a sign-in that signed nobody in clears nothing');
forget_attempts_after_success('activate');
is_same(1, $hits('login', $familyBucket), 'and neither does an activation that activated nobody');
throttle_clear('login', $familyBucket); // the suite's own clean slate, not the behaviour under test

case_('Wrong passwords are still counted, and still stop');
throttle_clear('login', $familyBucket); // the suite's own clean slate, not the behaviour under test
run_counter('UPDATE rate_limits SET window_start = window_start - 901 WHERE bucket = ?', [rate_limit_bucket('auth-ip', $ourIp)]);
$outcome = ['in' => 0, 'refused' => 0, 'throttled' => 0];
for ($i = 0; $i < 12; $i++) {
    try { submit('login', ['login' => $familyEmail, 'password' => 'das-ist-nicht-es']); $outcome['in']++; }
    catch (UserError $e) { str_contains($e->getMessage(), 'Zu viele') ? $outcome['throttled']++ : $outcome['refused']++; }
}
is_same(0, $outcome['in'], 'none of the twelve gets in');
is_same(10, $outcome['refused'], 'the first ten are refused on the password');
is_same(2, $outcome['throttled'], 'from the eleventh on the address is throttled before the password is looked at');
is_same(12, $hits('login', $familyBucket), 'every one of them was counted');
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
$waitingEmail = 'wartinger@beispiel.test';
$waitingPassword = 'Schlaeger-Tasche-2026!';
$waitingId = make_account(['email' => $waitingEmail, 'name' => 'Familie Wartinger',
                           'password_hash' => password_hash($waitingPassword, PASSWORD_DEFAULT)]);
$waitingBucket = address_identity($waitingEmail);
$rightPassword = fn() => submit('login', ['login' => $waitingEmail, 'password' => $waitingPassword]);
$wrongPassword = fn() => submit('login', ['login' => $waitingEmail, 'password' => 'falsch-getippt']);
// The per-IP bucket is aged too, so that how much traffic the rest of this
// suite sent from the same address cannot decide whether this case passes.
$ageWindow('auth-ip', $ourIp, 901);
for ($i = 0; $i < 11; $i++) { try { $wrongPassword(); } catch (UserError $e) {} }
is_same(11, $hits('login', $waitingBucket), 'eleven attempts stand against the address');
throws($rightPassword, 'the right password is refused while the window is still open', 'Zu viele');

// The negative first. Without it a throttle() that reset on every single call
// would pass the rest of this case, and the limit would stop nobody.
$ageWindow('login', $waitingBucket, 600);
throws($rightPassword, 'ten minutes in is not yet later, and it is still refused', 'Zu viele');
is_same(13, $hits('login', $waitingBucket), 'and those refusals are counted too, rather than sitting still');

$ageWindow('login', $waitingBucket, 400); // 1000 seconds in total, past the quarter of an hour
throws($wrongPassword, 'once the window has run down the password is looked at again',
       'Anmeldung nicht möglich');
is_same(1, $hits('login', $waitingBucket),
        'and the counter starts the new window at one, rather than carrying the old thirteen over');
does_not_throw($rightPassword, 'so the family signs in with the same password that was refused a moment ago');
is_same(0, $hits('login', $waitingBucket), 'and that sign-in clears the counter behind it');
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

// ---------------------------------------------------------------------------
// Moved from the usernames suite when usernames went (ADR 0021).
$ip = $_SERVER['REMOTE_ADDR'] ?? 'local';
/** Start the per-IP limits afresh, so how much this suite has sent cannot decide a case. */
$freshIp = function () use ($ip): void {
    foreach (['auth-ip', 'forgot-ip'] as $name) throttle_clear($name, $ip);
};
$password = 'Test-Only-Password-2026';

case_('An address is checked twice: whether mail can go there, and whether it may be written or looked up');
/* M1 and R2. FILTER_VALIDATE_EMAIL accepts a quoted local part, which may hold
   characters the collation ignores. A stored one keeps receiving mail; nothing
   new of the kind is written, and none is ever looked up. */
$legacy = '"familie..alt"@beispiel.test';
ok(email_deliverable($legacy), 'a quoted local part is deliverable, and portals may hold one');
ok(!email_is_dot_atom($legacy), 'but is no dot-atom');
throws(fn() => email_value($legacy), 'so it is refused for writing', 'Ungültige E-Mail-Adresse');
foreach (['"a b"@x.at', "le\x01na@x.at", 'lena@exämple.at', 'léna@x.at', 'a@[127.0.0.1]', 'a..b@x.at'] as $bad)
    ok(!email_is_dot_atom(email_normalised($bad)), 'never looked up: '.json_encode($bad));
foreach (['lena@beispiel.at', "o'neill+badminton@ex-ample.co.uk", 'LENA@Beispiel.AT'] as $good)
    is_same(email_normalised($good), email_value($good), 'written and looked up: '.json_encode($good));

case_('News goes out to every family, a legacy address among them [R2]');
sign_in_as($trainerId);
$families = [];
foreach (['news.eins@beispiel.test', $legacy, 'news.zwei@beispiel.test'] as $address)
    $families[] = make_account(['email' => $address, 'newsletter' => 1]);
does_not_throw(fn() => queue_mail($families[1], $legacy, 'Test', 'Hallo', 'newsletter'), 'queue_mail() takes the legacy address');
run('DELETE FROM mail_jobs');
act('news_save', ['title' => 'Turnier', 'body' => 'Am Samstag', 'published' => '1', 'send_email' => '1']);
$queued = array_column(rows("SELECT recipient FROM mail_jobs WHERE category='newsletter' ORDER BY account_id"), 'recipient');
is_same(['news.eins@beispiel.test', $legacy, 'news.zwei@beispiel.test'], array_values(array_intersect($queued, ['news.eins@beispiel.test', $legacy, 'news.zwei@beispiel.test'])),
        'one newsletter for each of them, the legacy address not rolling the others back');
throws(fn() => queue_mail($families[0], "news\x01@beispiel.test", 'Test', 'Hallo', 'newsletter'), 'while an undeliverable address is still refused');
run('DELETE FROM accounts WHERE id IN ('.implode(',', $families).')');

case_('„Vergessen“ takes an address, and mails the address as stored [S1]');
mail_ready(true);
run('DELETE FROM mail_jobs'); run('DELETE FROM auth_tokens');
$mia = make_account(['email' => 'mia.stein@beispiel.test', 'name' => 'Mia Stein']);
sign_out(); $freshIp();
is_same(['forgot', []], submit('forgot', ['login' => ' MIA.Stein@Beispiel.test ']), 'it lands on the same page');
$jobs = rows("SELECT * FROM mail_jobs WHERE category='security'");
is_same([['mia.stein@beispiel.test', $mia]], array_map(fn($j) => [$j['recipient'], (int)$j['account_id']], $jobs),
        'one mail, to the login’s own address, however it was typed');
$body = unseal((string)($jobs[0]['payload'] ?? ''));
ok(preg_match_all('/token=[a-f0-9]{64}/', $body) === 1, 'with one link');
is_same("Hallo Mia Stein,\n\nfür deinen Zugang wurde ein Link für ein neues Passwort angefordert.\n\n"
    ."Weißt du dein Passwort noch, ist nichts zu tun – es gilt weiter.\nSonst leg hier ein neues fest (eine Stunde gültig):\n{link}\n\n"
    ."Warst du das nicht, kannst du diese E-Mail ignorieren.", (string)preg_replace('~https?://\S+token=[a-f0-9]{64}~', '{link}', $body),
    'the reset mail, one text whoever asked for it, saying the old password keeps working');
is_same('reset', (string)scalar('SELECT purpose FROM auth_tokens WHERE account_id=?', [$mia]), 'a reset link');
$flash = (string)($_SESSION['flash']['message'] ?? '');
is_same('Wenn dazu ein Zugang mit E-Mail-Adresse gehört, ist eine E-Mail dorthin unterwegs.', $flash, 'the answer names no address (spec S3), and is the same whatever was typed');
foreach (['niemand@beispiel.test', 'mia.stein'] as $unknown) {
    run('DELETE FROM mail_jobs'); $freshIp();
    is_same(['forgot', []], submit('forgot', ['login' => $unknown]), 'an unknown '.$unknown.' lands on the same page');
    is_same($flash, (string)($_SESSION['flash']['message'] ?? ''), 'with the same answer, word for word');
    is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs'), 'and nothing is sent');
}

case_('„Vergessen“ sends an invited login its invitation again, a suspended one nothing, and nothing without mail');
$ida = make_account(['email' => 'ida.stein@beispiel.test', 'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
$ole = make_account(['email' => 'ole.stein@beispiel.test', 'state' => 'suspended']);
$stale = fixture('mail_jobs', ['account_id'=>$ida, 'recipient'=>'ida.stein@beispiel.test', 'subject'=>'Einladung', 'payload'=>seal('alt'),
                               'category'=>'security', 'status'=>'queued', 'attempts'=>0, 'created_at'=>now()]);
run('DELETE FROM mail_jobs WHERE id<>?', [$stale]); $freshIp();
submit('forgot', ['login' => 'ida.stein@beispiel.test']);
is_same('invite', (string)scalar('SELECT purpose FROM auth_tokens WHERE account_id=?', [$ida]), 'the invited login gets an invitation, not a reset');
is_same(1, (int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE account_id=? AND status='queued'", [$ida]), 'one invitation waiting');
is_same('cancelled', (string)scalar('SELECT status FROM mail_jobs WHERE id=?', [$stale]), 'and the one before it is not sent as well');
run('DELETE FROM mail_jobs'); $freshIp();
submit('forgot', ['login' => 'ole.stein@beispiel.test']);
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs') + (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [$ole]),
        'a suspended login gets nothing, and no link');
mail_ready(false); $freshIp();
submit('forgot', ['login' => 'mia.stein@beispiel.test']);
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs'), 'without mail nothing is queued');
is_same($flash, (string)($_SESSION['flash']['message'] ?? ''), 'and the answer is the same');
mail_ready(true);
/* "To the stored address, never the typed one" can only be told apart where the
   two differ and still find each other: an address saved with capitals before
   everything was lower-cased, which the tables' collation matches. */
$capitalised = make_account(['email' => 'Gross.Familie@Beispiel.test']);
$freshIp();
submit('forgot', ['login' => 'gross.familie@beispiel.test']);
is_same('Gross.Familie@Beispiel.test', (string)scalar("SELECT recipient FROM mail_jobs WHERE account_id=? AND category='security'", [$capitalised]),
        'a stored address with capitals gets its mail at the address as stored');

case_('The sender checks every link in the mail, and compares addresses as they are stored [S1, R7]');
$younger = make_account(['email' => 'tim.stein@beispiel.test']);
$twoLinks = url('activate', ['token' => make_token($mia, 'reset')])."\n".url('activate', ['token' => make_token($younger, 'reset')]);
ok(!security_mail_links_live($twoLinks, 'mia.stein@beispiel.test'), 'a link belonging to another address stops the whole mail');
$oneLink = url('activate', ['token' => make_token($mia, 'reset')]);
ok(security_mail_links_live($oneLink, 'mia.stein@beispiel.test'), 'its own live link goes out');
make_token($mia, 'reset');
ok(!security_mail_links_live($oneLink, 'mia.stein@beispiel.test'), 'a link replaced since is stale and stops it');
ok(!security_mail_links_live('Hallo, kein Link', 'mia.stein@beispiel.test'), 'a security mail with no link is not sent');
$capitals = make_account(['email' => 'Lena@Example.at']);
$link = url('activate', ['token' => make_token($capitals, 'reset')]);
ok(security_mail_links_live($link, 'lena@example.at'), 'an address stored with capitals still receives its reset link');
ok(!security_mail_links_live($link, 'jemand@example.at'), 'while a link to somebody else’s address is not sent');
run("UPDATE accounts SET state='suspended' WHERE id=?", [$capitals]);
ok(!security_mail_links_live($link, 'lena@example.at'), 'nor one for a suspended login');
$moving = make_account(['email' => 'alt@beispiel.test']);
ok(security_mail_links_live(url('activate', ['token' => make_token($moving, 'email', 'neu@beispiel.test')]), 'neu@beispiel.test'),
   'a changed address is confirmed at the address it is changing to');

case_('„Vergessen“ counts per typed address and per requester, and both on every request [S7]');
$freshIp();
throttle_clear('forgot', address_identity('dreimal@beispiel.test'));
for ($i = 0; $i < 3; $i++) submit('forgot', ['login' => 'dreimal@beispiel.test']);
throws(fn() => submit('forgot', ['login' => 'Dreimal@Beispiel.test']), 'the fourth request for one address in an hour is refused, in any capitals', 'Zu viele');
is_same(4, $hits('forgot-ip', $ip), 'and it still counted against the requester');
is_same(4, $hits('forgot', address_identity('dreimal@beispiel.test')), 'counted under the address as typed, not under a login');
$freshIp();
for ($i = 0; $i < 10; $i++) submit('forgot', ['login' => 'anders'.$i.'@beispiel.test']);
throws(fn() => submit('forgot', ['login' => 'elftens@beispiel.test']), 'the eleventh from one requester is refused, whatever it names', 'Zu viele');
is_same(1, $hits('forgot', address_identity('elftens@beispiel.test')), 'and still counted against that address');
$freshIp();

case_('A reset raises auth_version, is written down, and the page it opens shows the address');
/* ADR 0020, §8: with an address of one's own, the reader of the mailbox is the
   holder. The audit entry is what Mein Konto and the access card list for
   PASSWORD_RESET_SHOWN_DAYS [I1, S4]. */
$holder = make_account(['email' => 'kern@beispiel.test', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
sign_out(); set_setting('privacy_ready', true);
$before = (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [$holder]);
$_SESSION['activation_hash'] = hash('sha256', make_token($holder, 'reset'));
is_same('kern@beispiel.test', token_record($_SESSION['activation_hash'])['email'] ?? null, 'the page the link opens can read the address from the link');
$freshIp();
submit('activate', ['password' => 'Federball-2026-Halle!', 'password_confirm' => 'Federball-2026-Halle!', 'email' => 'anders@beispiel.test']);
is_same($before + 1, (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [$holder]), 'every other session on it has ended');
is_same('kern@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$holder]), 'and an address posted to the reset page changes nothing');
$audited = one("SELECT * FROM audit_log WHERE action='account.password_reset' ORDER BY id DESC LIMIT 1");
is_same([$holder, $holder], [(int)($audited['entity_id'] ?? 0), (int)($audited['actor_id'] ?? 0)], 'the reset is in the audit log, done by the holder of the link');
is_same('Dein neues Passwort gilt ab sofort.', $_SESSION['flash']['message'] ?? null, 'and the sign-in it performs says so');
sign_out(); $freshIp(); unset($_SESSION['flash']);
submit('login', ['login' => 'kern@beispiel.test', 'password' => 'Federball-2026-Halle!']);
is_same('', (string)($_SESSION['flash']['message'] ?? ''), 'the next sign-in is not warned about it');
is_same(14, PASSWORD_RESET_SHOWN_DAYS, 'Mein Konto and the access card list resets for two weeks [I1]');
fixture('audit_log', ['actor_id' => $holder, 'action' => 'account.password_reset', 'entity_type' => 'account',
    'entity_id' => $holder, 'created_at' => gmdate('Y-m-d H:i:s', time() - 15 * 86400)]);
is_same(1, count(password_resets_for($holder, PASSWORD_RESET_SHOWN_DAYS)), 'the one from today is listed, not the one from fifteen days ago');
sign_out();

// ---------------------------------------------------------------------------
case_('A child is called in the chat what the club calls them: Mein Konto renames no student’s login, renaming the child does [security review 2026-10-08]');
/* preferences_save wrote accounts.name for every role, and the course group
   prints it over every bubble: a child named themselves „Trainerin Anna" and
   wrote to the group under the trainer's name. Renaming the child never renamed
   the login, so the chat went on showing the old name. */
$chatLogin = make_account(['role'=>'student', 'name'=>'Lena Hofer', 'email'=>'lena.chat@beispiel.test']);
$chatKid = make_student(['account_id'=>$chatLogin, 'first_name'=>'Lena', 'last_name'=>'Hofer']);
$preferences = ['locale'=>'de', 'theme'=>'dark', 'accent'=>'', 'text_scale'=>'normal', 'notifications'=>'1'];
sign_in_as($chatLogin);
act('preferences_save', ['name'=>'Trainerin Anna'] + $preferences);
is_same(['Lena Hofer', 'dark'], array_values(one('SELECT name,theme FROM accounts WHERE id=?', [$chatLogin])),
        'a child calling themselves „Trainerin Anna“ under Mein Konto keeps their name, and the rest of the card is saved');
does_not_throw(fn() => act('preferences_save', $preferences), 'and the card saves without a name, as Mein Konto now sends it');
$childsCard = render_view('profile');
ok(!str_contains($childsCard, 'name="name"'), 'Mein Konto offers a child no box for a name');
ok(str_contains($childsCard, '<h2>Darstellung</h2>') && !str_contains($childsCard, 'Name und Darstellung'), 'and its card is called „Darstellung“, promising no name');
sign_in_as($trainerId);
act('preferences_save', ['name'=>'Tina Trainerin'] + $preferences);
is_same('Tina Trainerin', (string)scalar('SELECT name FROM accounts WHERE id=?', [$trainerId]), 'staff still give themselves a name');
ok(str_contains(render_view('profile'), 'name="name"') && str_contains(render_view('profile'), '<h2>Name und Darstellung</h2>'), 'with the box on their Mein Konto, under „Name und Darstellung“');
throws(fn() => act('preferences_save', ['name'=>''] + $preferences), 'and must give one', 'Pflichtfelder');

$saveChild = function (int $id, array $changes) {
    $s = one('SELECT * FROM students WHERE id=?', [$id]);
    $fields = $changes + ['id'=>(string)$id, 'revision'=>(string)$s['revision'], 'first_name'=>$s['first_name'], 'last_name'=>$s['last_name'],
        'birth_date'=>(string)($s['birth_date'] ?? ''), 'address'=>(string)$s['address'], 'phone'=>(string)$s['phone']];
    return act('student_save', $fields + (is_staff() ? ['email'=>'', 'joined_on'=>(string)$s['joined_on'], 'ended_on'=>'',
        'status'=>$s['status'], 'internal_notes'=>''] : []));
};
$loginName = fn(int $id): string => (string)scalar('SELECT name FROM accounts WHERE id=?', [$id]);
run('DELETE FROM record_versions');
$saveChild($chatKid, ['first_name'=>'Lena-Marie']);
is_same('Lena-Marie Hofer', $loginName($chatLogin), 'the trainer renaming the child renames their login, the name the chat shows');
$lines = history_for('accounts', $chatLogin);
is_same([1, $trainerId, ['name'=>['from'=>'Lena Hofer', 'to'=>'Lena-Marie Hofer']]],
        [count($lines), (int)($lines[0]['actor_id'] ?? 0), version_changes($lines[0] ?? ['before_json'=>null, 'after_json'=>null])],
        'one line in the change log on the login, by the trainer, with the old name and the new');
$saveChild($chatKid, ['address'=>'Hauptstraße 5, 4020 Linz']);
is_same(1, count(history_for('accounts', $chatLogin)), 'a save that renames nobody adds no line about the login');
sign_in_as($chatLogin);
$saveChild($chatKid, ['first_name'=>'Lena']);
is_same('Lena Hofer', $loginName($chatLogin), 'the family renaming their own child renames the login too');
sign_in_as($trainerId);
$teamLogin = make_account(['role'=>'trainer', 'name'=>'Trainerin von früher', 'email'=>'frueher@beispiel.test']);
$filedUnder = make_student(['account_id'=>$teamLogin, 'first_name'=>'Kind', 'last_name'=>'Von Früher']);
$saveChild($filedUnder, ['first_name'=>'Umbenannt']);
is_same('Trainerin von früher', $loginName($teamLogin), 'a team member’s login a child is still filed under keeps her own name');

// ---------------------------------------------------------------------------
case_('A new address is told to the old one, a new password to the address it signs in with, by a notice without a link [security review 2026-10-08]');
/* change_account_email() signed out every session and killed every link, and
   told nobody: whoever moved a login they had taken over did it unseen. Neither
   did a changed password. The notice is a security mail - no switch stops it,
   nothing tries it again by itself, its words go once it is sent - and it has
   no link, so the sender lets it go as a notice, while any other security mail
   without a link is still never sent [S1]. */
$moving = make_account(['role'=>'student', 'name'=>'Mila Umzug', 'email'=>'mila.alt@beispiel.test',
                        'password_hash'=>password_hash($password, PASSWORD_DEFAULT)]);
make_student(['account_id'=>$moving, 'first_name'=>'Mila', 'last_name'=>'Umzug', 'email'=>'mila.alt@beispiel.test']);
run('DELETE FROM mail_jobs');
sign_in_as($moving);
$_SESSION['activation_hash'] = hash('sha256', make_token($moving, 'email', 'mila.neu@beispiel.test'));
act('activate', []);
is_same('mila.neu@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$moving]), 'the confirmed address is the login’s');
$noticeJob = one("SELECT * FROM mail_jobs WHERE account_id=? AND status='queued'", [$moving]) ?? [];
is_same(['mila.alt@beispiel.test', 'security', 'Deine Anmeldeadresse wurde geändert'],
        [$noticeJob['recipient'] ?? null, $noticeJob['category'] ?? null, $noticeJob['subject'] ?? null],
        'the old address is told, by a security mail');
$said = $noticeJob ? mail_payload(unseal((string)$noticeJob['payload'])) : ['body'=>'', 'attach'=>[], 'notice'=>false];
is_same("Hallo Mila,\n\ndie Adresse, mit der du dich anmeldest, wurde auf m***@b***.test geändert.\n\nWarst du das nicht? Melde dich beim Verein.",
        $said['body'], 'greeting Mila, with the new address masked, and what to do if it was not her');
is_same([true, false], [$said['notice'], (bool)preg_match('/token=|https?:/', $said['body'])], 'a notice, with no link in it');

queue_mail($moving, 'mila.neu@beispiel.test', 'Kein Hinweis', 'Hallo, ganz ohne Link', 'security');
$plain = (int)scalar("SELECT MAX(id) FROM mail_jobs WHERE subject='Kein Hinweis'");
set_setting('smtp', ['host'=>'127.0.0.1', 'port'=>1, 'encryption'=>'tls', 'from_email'=>'portal@example.test', 'from_name'=>'B']);   // nothing listens there
process_mail();
is_same(['failed', 1], [(string)scalar('SELECT status FROM mail_jobs WHERE id=?', [(int)($noticeJob['id'] ?? 0)]), (int)scalar('SELECT attempts FROM mail_jobs WHERE id=?', [(int)($noticeJob['id'] ?? 0)])],
        'the sender tries to send the notice, link or none - here to a server that is not there');
is_same('cancelled', (string)scalar('SELECT status FROM mail_jobs WHERE id=?', [$plain]), 'while a security mail without a link that is no notice is still never sent');
set_setting('smtp', []);

run('DELETE FROM mail_jobs');
sign_in_as($moving);
act('password_change', ['current_password'=>$password, 'password'=>'Federball-Halle-2026!', 'password_confirm'=>'Federball-Halle-2026!']);
$noticeJob = one('SELECT * FROM mail_jobs WHERE account_id=?', [$moving]) ?? [];
is_same(['mila.neu@beispiel.test', 'security', 'Dein Passwort wurde geändert'],
        [$noticeJob['recipient'] ?? null, $noticeJob['category'] ?? null, $noticeJob['subject'] ?? null],
        'a new password is told to the address the login signs in with');
$said = $noticeJob ? mail_payload(unseal((string)$noticeJob['payload'])) : ['body'=>'', 'attach'=>[], 'notice'=>false];
ok($said['notice'] && str_contains($said['body'], 'das Passwort, mit dem du dich anmeldest, wurde geändert.'), 'as a notice, in the holder’s words');
run("UPDATE accounts SET locale='en' WHERE id=?", [$moving]);
sign_in_as($moving);
act('password_change', ['current_password'=>'Federball-Halle-2026!', 'password'=>'Federball-Halle-2027!', 'password_confirm'=>'Federball-Halle-2027!']);
is_same('Your password was changed', (string)scalar('SELECT subject FROM mail_jobs WHERE account_id=? ORDER BY id DESC LIMIT 1', [$moving]),
        'in English for a login that reads English');

run('DELETE FROM mail_jobs');
sign_in_as($trainerId);
$typo = make_account(['role'=>'student', 'email'=>'tippfehler@beispiel.test', 'state'=>'invited', 'verified_at'=>null, 'password_hash'=>null]);
make_student(['account_id'=>$typo, 'first_name'=>'Tim', 'last_name'=>'Tipp', 'email'=>'tippfehler@beispiel.test']);
transactional(fn() => change_account_email($typo, 'tim.richtig@beispiel.test'));
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs WHERE recipient=?', ['tippfehler@beispiel.test']),
        'an invitation’s address that staff correct is told nothing: nobody signs in with it, and it may be a stranger’s');
is_same('t***@b***.test', masked_address('tim.richtig@beispiel.test'), 'an address is masked to its first letters and its ending');
sign_out();
