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

case_('A child’s or a family’s picture reaches only who may see them');
/* serve_download() is the route itself. A refusal throws before a byte is read,
   which the front controller turns into a 404; what is allowed is asked of
   avatar_for_download(), the lookup the route serves from, because serving
   would end the test run with exit. */
$picture = fn(string $fill) => str_repeat($fill, 32).'.jpg';
run('UPDATE students SET avatar_name=? WHERE id=?', [$picture('a'), $kidA]);
run('UPDATE students SET avatar_name=? WHERE id=?', [$picture('b'), $kidB]);
run('UPDATE accounts SET avatar_name=? WHERE id=?', [$picture('c'), $parentA]);
run('UPDATE accounts SET avatar_name=? WHERE id=?', [$picture('d'), $parentB]);
run('UPDATE accounts SET avatar_name=? WHERE id=?', [$picture('e'), $trainerId]);
/* Serving a file ends the request with exit, and exit here would end the whole
   run quietly with status 0. So while the route is being asked, an exit is
   caught on the way out and reported as the failure it is. */
$routeCall = new stdClass; $routeCall->asking = '';
register_shutdown_function(function () use ($routeCall) {
    if ($routeCall->asking === '') return;
    fwrite(STDERR, "\nFAIL security: the download route served ".$routeCall->asking." instead of refusing, and ended the run.\n");
    exit(1);
});
$download = function (string $kind, int $id) use ($routeCall): void {
    $_GET = ['page'=>'download', 'what'=>'avatar', 'kind'=>$kind, 'id'=>(string)$id];
    $routeCall->asking = $kind.' '.$id;
    try { serve_download(); } finally { $_GET = []; $routeCall->asking = ''; }
};
$refused = function (callable $fn, string $what) {
    try { $fn(); ok(false, $what); } catch (Throwable $e) { ok($e instanceof NotFound, $what.' (a 404, not '.get_class($e).')'); }
};
sign_in_as($parentA);
$refused(fn() => $download('student', $kidB), 'family A asking for family B’s child’s photo gets nothing');
$refused(fn() => $download('student', $orphan), 'nor a child linked to no account');
$refused(fn() => $download('account', $parentB), 'nor another family’s own photo');
$refused(fn() => $download('account', 999999), 'and an id that does not exist is the same answer');
is_same($picture('a'), avatar_for_download('student', $kidA), 'their own child’s photo is served');
is_same($picture('c'), avatar_for_download('account', $parentA), 'and their own');
is_same($picture('e'), avatar_for_download('account', $trainerId), 'and the trainer’s, whom they write to');
does_not_throw(fn() => $download('account', $adminId), 'the route lets them ask for an administrator’s, who has no picture');
sign_in_as($trainerId);
is_same($picture('b'), avatar_for_download('student', $kidB), 'the trainer sees every child’s');
is_same($picture('d'), avatar_for_download('account', $parentB), 'and every family’s');
sign_in_as($adminId);
is_same($picture('b'), avatar_for_download('student', $kidB), 'and so does an administrator');
ok(!may_see_account_picture(['id'=>$parentA, 'role'=>'student'], ['id'=>$trainerId, 'avatar_name'=>$picture('e')]),
   'an account row that does not say it is staff is not taken for staff');

case_('Signing out asks the browser to forget the pictures it kept');
/* Headers cannot be read back on the command line, so this pins the line in the
   logout action; TESTING.md has the check in a real browser. */
$actions = (string)file_get_contents(APP_ROOT.'/app/actions.php');
// The whole logout case, up to the next one, however long its comment grows.
$logout = (string)strstr((string)strstr($actions, "case 'logout':"), "case 'forgot':", true);
ok(str_contains($logout, "header('Clear-Site-Data: \"cache\"')"), 'Clear-Site-Data: "cache" goes out with the logout');

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
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$waiting]), 'and the student at that address was not touched');
act('account_invite', ['name'=>'Dritte Trainerin', 'email'=>'wartend@beispiel.test', 'role'=>'trainer', 'locale'=>'de']);
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$waiting]),
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
$familyUsername = 'familie.sieber';
$familyPassword = 'Federball-2026-Halle!';
$familyId = make_account(['email' => $familyEmail, 'username' => $familyUsername, 'name' => 'Familie Sieber',
                          'password_hash' => password_hash($familyPassword, PASSWORD_DEFAULT)]);
$familyBucket = username_identity($familyUsername);
$ourIp = $_SERVER['REMOTE_ADDR'] ?? 'local';
$hits = fn(string $name, string $identity) => (int)(run_counter('SELECT hits FROM rate_limits WHERE bucket=?',
    [rate_limit_bucket($name, $identity)])->fetchColumn() ?: 0);
$signIn = fn() => submit('login', ['username' => $familyUsername, 'password' => $familyPassword]);

case_('Signing in correctly never locks the family out');
$ipBefore = $hits('auth-ip', $ourIp);
does_not_throw(function () use ($signIn) { for ($i = 0; $i < 12; $i++) $signIn(); },
               'twelve correct sign-ins in a row are all let through, though the limit is ten');
is_same(0, $hits('login', $familyBucket), 'and leave nothing counted against the username');
is_same($ipBefore + 12, $hits('auth-ip', $ourIp),
        'while the per-IP counter keeps all twelve: one valid login must not refresh the limit that slows guessing at every other account');

case_('A sign-in clears its own counter and no other');
throttle('forgot', 'address:'.$familyEmail, 3, 3600);
is_same(1, $hits('forgot', 'address:'.$familyEmail), 'a reset request is counted');
does_not_throw($signIn, 'the family signs in');
is_same(1, $hits('forgot', 'address:'.$familyEmail),
        'and the reset-request counter stands: typing an address proves nothing about who typed it, so that bucket is never cleared');

case_('A sign-in that does not complete forgets nothing');
/* The counters sit on their own connection so that a rolled-back action cannot
   refund them, which is also why the clearing waits until the action has
   committed. A request that authenticated and then failed for some other reason
   must leave its attempt counted rather than forgetting one it never finished. */
$replayed = bin2hex(random_bytes(32));
$sent = ['username' => $familyUsername, 'password' => $familyPassword, 'request_id' => $replayed];
does_not_throw(fn() => submit('login', $sent), 'the first submission signs in');
is_same(0, $hits('login', $familyBucket), 'and its attempt is forgotten');
throws(fn() => submit('login', $sent), 'sending the very same submission again is refused as a replay', 'bereits verarbeitet');
is_same(1, $hits('login', $familyBucket), 'and that attempt stays counted, having proved nothing');

case_('Spelling a name differently does not buy a fresh ten guesses, and nothing is looked up to count');
/* ADR 0020, §3. What was typed is counted, after normalising, under its kind:
   a username after username_normalised(), an address after email_normalised().
   Capitals, spaces and the umlauts of the table land in one bucket in PHP, on
   every engine, and a name that exists is counted exactly like one that does
   not. Resolving the input to its row first would be ADR 0007's oracle again. */
throttle_clear('login', $familyBucket);
foreach (['familie.sieber', 'Familie.Sieber', ' FAMILIE.SIEBER '] as $spelling) {
    $_POST = ['username' => $spelling];
    is_same($familyBucket, sign_in_identity(attempted_sign_in()), 'counted in the one bucket: '.json_encode($spelling));
}
foreach (['familie@beispiel.test', ' Familie@Beispiel.TEST'] as $spelling) {
    $_POST = ['username' => $spelling];
    is_same('address:familie@beispiel.test', sign_in_identity(attempted_sign_in()), 'and an address in its own: '.json_encode($spelling));
}
$_POST = ['username' => 'lena@'];
is_same(['address', 'lena@'], attempted_sign_in(), 'an @ anywhere makes it an address, which a username never holds');
$actions = (string)file_get_contents(APP_ROOT.'/app/actions.php');
ok(!str_contains($actions, 'function attempted_identity'), 'and no step resolves the typed value to a row first');
$handle = (string)strstr((string)strstr($actions, 'function handle_post('), 'function forget_attempts_after_success(', true);
ok(str_contains($handle, "throttle('login',sign_in_identity(attempted_sign_in()),10);"),
   'the request counts the typed value, before anything is looked up');
$lockout = function (string $typed) use ($hits, $ourIp): array {
    run_counter('UPDATE rate_limits SET window_start = window_start - 901 WHERE bucket = ?', [rate_limit_bucket('auth-ip', $ourIp)]);
    $answers = [];
    for ($i = 0; $i < 11; $i++) {
        try { submit('login', ['username' => $typed, 'password' => 'falsch-geraten']); $answers[] = 'in'; }
        catch (UserError $e) { $answers[] = str_contains($e->getMessage(), 'Zu viele') ? 'throttled' : $e->getMessage(); }
    }
    $_POST = ['username' => $typed];
    $bucket = sign_in_identity(attempted_sign_in());
    $counted = $hits('login', $bucket);
    throttle_clear('login', $bucket);
    return [$answers, $counted];
};
$known = $lockout($familyUsername);
is_same(11, count($known[0]), 'eleven tries');
is_same(['throttled'], array_slice($known[0], 10), 'a username that exists is refused ten times, then throttled at the eleventh');
is_same($known, $lockout('niemand.hier'), 'and one that does not exist gets exactly the same answers, counted exactly the same way');
is_same($known, $lockout($familyEmail), 'an address that exists the same');
is_same($known, $lockout('niemand@beispiel.test'), 'and an address that does not');

case_('The address signs in too, each name with its own counter, and a sign-in clears both');
sign_out();
run_counter('UPDATE rate_limits SET window_start = window_start - 901 WHERE bucket = ?', [rate_limit_bucket('auth-ip', $ourIp)]);
for ($i = 0; $i < 11; $i++) { try { submit('login', ['username' => $familyUsername, 'password' => 'falsch']); } catch (UserError $e) {} }
throws($signIn, 'with the username’s bucket full, the username is throttled', 'Zu viele');
throttle('login', address_identity($familyEmail), 10);
does_not_throw(fn() => submit('login', ['username' => ' Familie@Beispiel.test ', 'password' => $familyPassword]), 'the address still signs in, in any capitals');
is_same($familyId, (int)(current_user()['id'] ?? 0), 'as the right login');
is_same([0, 0], [$hits('login', $familyBucket), $hits('login', address_identity($familyEmail))], 'and both of the login’s buckets are cleared');
sign_out();
$_POST = [];

case_('Lena.Müller, capitalised by an iPhone, signs in as lena.mueller, and LENA@Beispiel.AT as her address');
$lena = make_account(['username' => 'lena.mueller', 'email' => 'lena@beispiel.at', 'password_hash' => password_hash($familyPassword, PASSWORD_DEFAULT)]);
does_not_throw(fn() => submit('login', ['username' => ' Lena.Müller', 'password' => $familyPassword]), 'the typed username signs in');
is_same($lena, (int)(current_user()['id'] ?? 0), 'as the right login');
sign_out();
does_not_throw(fn() => submit('login', ['username' => 'LENA@Beispiel.AT', 'password' => $familyPassword]), 'and so does the address in capitals');
is_same($lena, (int)(current_user()['id'] ?? 0), 'as the same login');
sign_out();

case_('An address that reaches a login only through the collation signs nobody in');
/* ADR 0020, §3: a row is used only if it is exactly what was typed. On the real
   engine utf8mb4_unicode_ci reads ß as ss, so 'strasse@…' finds a legacy row
   stored as 'straße@…'; the exact-match check refuses it. SQLite compares bytes
   and would never find the row, which would prove nothing. */
if (test_driver() === 'sqlite') {
    test_unsupported(array_merge(test_unsupported(), ['a sign-in that reaches a row only through the collation (ß read as ss) refused by the exact match (needs the MySQL collation)']));
} else {
    $folded = make_account(['username' => 'strasse.kind', 'email' => 'straße@beispiel.test', 'password_hash' => password_hash($familyPassword, PASSWORD_DEFAULT)]);
    ok(one('SELECT id FROM accounts WHERE email=?', ['strasse@beispiel.test']) !== null, 'the collation does find the row, so this is a real test');
    throws(fn() => submit('login', ['username' => 'strasse@beispiel.test', 'password' => $familyPassword]), 'but it does not sign in', 'Anmeldung nicht möglich');
    is_same(null, current_user(), 'nobody is signed in');
    throttle_clear('login', address_identity('strasse@beispiel.test'));
    run('DELETE FROM accounts WHERE id=?', [$folded]);
}

case_('A value that fails its format is never looked up');
if (test_driver() !== 'sqlite') {
    test_unsupported(array_merge(test_unsupported(), ['a sign-in sending no statement for a value that fails its format (counted by the sqlite driver)']));
} else {
    $ownBuckets = [];
    $statements = function (string $typed) use (&$ownBuckets) {
        $_POST = ['username' => $typed, 'password' => 'x'];
        $ownBuckets[] = sign_in_identity(attempted_sign_in());
        return query_count(function () { try { act('login', $_POST); } catch (UserError $e) {} });
    };
    ok($statements('familie.sieber') > 0 && $statements('familie@beispiel.test') > 0, 'a username and a plain address are looked up');
    foreach (['"familie"@beispiel.test', "familie\x01@beispiel.test", 'famílie@beispiel.test', 'a..b', 'fa', 'fa_mi'] as $bad)
        is_same(0, $statements($bad), 'and not a single statement is sent for '.json_encode($bad));
    $_POST = [];
}

case_('Every refusal checks one password, says the same words, and never uses a hash written into the code');
/* M2. A missing name, a value that cannot be one, an invitation without a
   password, a suspended login and a wrong password all cost one
   password_verify(), and all get the one sentence (ADR 0020, §3), so neither the
   time nor the words say which it was. The hash for "nothing to check" is the
   stored sign_in_dummy_hash(), at today's PASSWORD_DEFAULT cost - not a literal
   from the year it was written, which PHP 8.4 had already made cheaper. */
$login = (string)strstr((string)strstr($actions, "case 'login':"), "case 'logout':", true);
ok($login !== '', 'the login case was found');
// Read as code, so a comment that names the function is not counted as a call.
$loginCode = implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                                   token_get_all("<?php\n".$login)));
is_same(1, substr_count($loginCode, 'password_verify('), 'the case has exactly one password_verify(), which every refusal passes through');
ok(!str_contains($loginCode, '$2y$'), 'and no literal hash');
ok(str_contains($loginCode, 'account_for_sign_in(...attempted_sign_in())'), 'it looks the login up through account_for_sign_in()');
$dummy = sign_in_dummy_hash();
ok($dummy !== '' && !password_needs_rehash($dummy, PASSWORD_DEFAULT), 'the comparison hash exists at today’s cost');
ok(!password_verify('', $dummy) && !password_verify('Test-Only-Password-2026', $dummy), 'and matches no password anybody has');
is_same($dummy, sign_in_dummy_hash(), 'a request asking again gets the same one: nothing is hashed per sign-in');
make_account(['username' => 'noch.eingeladen', 'email' => 'eingeladen-noch@beispiel.test', 'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
make_account(['username' => 'gesperrt.konto', 'email' => 'gesperrt@beispiel.test', 'state' => 'suspended', 'password_hash' => password_hash($familyPassword, PASSWORD_DEFAULT)]);
$said = [];
foreach (['an invitation not yet accepted' => ['noch.eingeladen', ''], 'a suspended login with its right password' => ['gesperrt.konto', $familyPassword],
          'the same by its address' => ['gesperrt@beispiel.test', $familyPassword], 'a value that cannot be a username' => ['a..b', $familyPassword],
          'a name nobody has' => ['niemand.hier', $familyPassword], 'a wrong password' => [$familyUsername, 'falsch']] as $what => [$typed, $typedPassword]) {
    try { submit('login', ['username' => $typed, 'password' => $typedPassword]); $said[$what] = 'in'; }
    catch (UserError $e) { $said[$what] = $e->getMessage(); }
    $_POST = ['username' => $typed]; throttle_clear('login', sign_in_identity(attempted_sign_in()));
}
is_same(['Anmeldung nicht möglich. Bitte Benutzername oder E-Mail-Adresse und Passwort prüfen. Noch nicht eingerichtet? Dann zuerst den Link in der Einladung öffnen.'],
        array_values(array_unique($said)), 'every one of them gets the one sentence, word for word: '.implode(', ', array_keys($said)));
ok(!str_contains((string)reset($said), 'Zugangsdaten'), 'which no longer says „Zugangsdaten“');
$_POST = [];

case_('A restored database without the comparison hash gets one from its first sign-in, once');
/* R9's one repair. Made before the action's transaction opens, so the refusal
   that follows cannot roll it back and make every refusal hash again. */
run("DELETE FROM settings WHERE setting_key='sign_in_dummy_hash'"); setting_cache_clear();
run_counter('UPDATE rate_limits SET window_start = window_start - 901 WHERE bucket = ?', [rate_limit_bucket('auth-ip', $ourIp)]);
is_same('', (string)setting('sign_in_dummy_hash'), 'there is none');
$logged = ini_get('error_log'); $logFile = test_run_dir().'/dummy-hash.log'; ini_set('error_log', $logFile);
try { throws(fn() => submit('login', ['username' => 'niemand.hier', 'password' => 'falsch']), 'a refused sign-in', 'Anmeldung nicht möglich'); }
finally { ini_set('error_log', (string)$logged); }
setting_cache_clear();
$repaired = (string)setting('sign_in_dummy_hash');
ok($repaired !== '', 'leaves one stored, although the sign-in itself was rolled back');
ok(str_contains((string)@file_get_contents($logFile), 'sign_in_dummy_hash'), 'and says so in the log');
throws(fn() => submit('login', ['username' => 'niemand.hier', 'password' => 'falsch']), 'the next refusal', 'Anmeldung nicht möglich');
setting_cache_clear();
is_same($repaired, (string)setting('sign_in_dummy_hash'), 'uses that one rather than making another');
throttle_clear('login', username_identity('niemand.hier'));

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
   address confirmed - end in sign_in(), so all three clear the bucket. Only the
   reset was ever written down, and an invitation is the one that would look
   like an oversight: a family locked out by a week of wrong guesses at an
   address they had not finished setting up would accept the invitation and find
   themselves still locked out of the sign-in that follows it. */
set_setting('privacy_ready', true);
$invitedEmail = 'neuzugang@beispiel.test';
$invitedId = make_account(['email' => $invitedEmail, 'name' => 'Familie Neuzugang',
                           'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
$invitedUsername = (string)scalar('SELECT username FROM accounts WHERE id=?', [$invitedId]);
for ($i = 0; $i < 3; $i++) throttle('login', username_identity($invitedUsername), 10);
is_same(3, $hits('login', username_identity($invitedUsername)), 'three guesses stand against the username');
$_SESSION['activation_hash'] = hash('sha256', make_token($invitedId, 'invite'));
does_not_throw(fn() => submit('activate', ['password' => 'Federball-2026-Halle!',
    'password_confirm' => 'Federball-2026-Halle!', 'privacy_seen' => '1', 'notifications' => '1']),
    'the invitation is accepted');
is_same(0, $hits('login', username_identity($invitedUsername)), 'and the attempts counted against that username are forgotten');
sign_out();

case_('A request that signed nobody in forgets nothing');
/* Both branches ask the session who is there rather than taking "nothing threw"
   for an answer. The throw happens in handle_post(), which is a fact about
   another file; this one should still be right if that ever changes. */
sign_out();
throttle_clear('login', $familyBucket); // a known slate, not the behaviour under test
throttle('login', $familyBucket, 10);
is_same(1, $hits('login', $familyBucket), 'one attempt is counted');
$_POST = ['username' => $familyUsername];   // the username was typed; nobody got in with it
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
    try { submit('login', ['username' => $familyUsername, 'password' => 'das-ist-nicht-es']); $outcome['in']++; }
    catch (UserError $e) { str_contains($e->getMessage(), 'Zu viele') ? $outcome['throttled']++ : $outcome['refused']++; }
}
is_same(0, $outcome['in'], 'none of the twelve gets in');
is_same(10, $outcome['refused'], 'the first ten are refused on the password');
is_same(2, $outcome['throttled'], 'from the eleventh on the address is throttled before the password is looked at');
is_same(12, $hits('login', $familyBucket), 'every one of them was counted');
throws($signIn, 'and the right password does not reopen a throttled username until the window runs down', 'Zu viele');
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
$waitingUsername = 'familie.wartinger';
$waitingPassword = 'Schlaeger-Tasche-2026!';
$waitingId = make_account(['username' => $waitingUsername, 'name' => 'Familie Wartinger',
                           'password_hash' => password_hash($waitingPassword, PASSWORD_DEFAULT)]);
$waitingBucket = username_identity($waitingUsername);
$rightPassword = fn() => submit('login', ['username' => $waitingUsername, 'password' => $waitingPassword]);
$wrongPassword = fn() => submit('login', ['username' => $waitingUsername, 'password' => 'falsch-getippt']);
// The per-IP bucket is aged too, so that how much traffic the rest of this
// suite sent from the same address cannot decide whether this case passes.
$ageWindow('auth-ip', $ourIp, 901);
for ($i = 0; $i < 11; $i++) { try { $wrongPassword(); } catch (UserError $e) {} }
is_same(11, $hits('login', $waitingBucket), 'eleven attempts stand against the username');
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
