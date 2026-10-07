<?php
/**
 * Every student has a login, a wizard adds one, and one-time sign-in links
 * (docs/decisions/0023-every-student-has-a-login-a-wizard-adds-one-and-one-time-sign-in-links.md).
 *
 * The rules the record's "Must stay true" and its list for qa-tester name, each
 * asserted where it can break: a student is never without a login after any
 * path; enrolment refuses one without; a student's login is replaced, never
 * deleted; usernames sign in in any capitals and are throttled like addresses;
 * a sign-in link is used up only by the POST that sets a new password, once,
 * for 48 hours, made only by whom the rule allows and shown only to its maker;
 * the wizard writes nothing before student_create. The structure suite holds
 * the same rules to the code's shape.
 */
$admin = make_account(['role'=>'admin', 'name'=>'Chefin', 'email'=>'chefin@beispiel.test']);
$trainer = make_account(['role'=>'trainer', 'name'=>'Trainerin', 'email'=>'trainerin@beispiel.test']);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'local';
$password = 'Federball-2026-Halle!';
sign_in_as($trainer);
mail_ready(true);

/** Rows the wizard could write, so "nothing was written" is one comparison. */
$written = fn(): array => [(int)scalar('SELECT COUNT(*) FROM students'), (int)scalar('SELECT COUNT(*) FROM accounts'),
    (int)scalar('SELECT COUNT(*) FROM class_students'), (int)scalar('SELECT COUNT(*) FROM auth_tokens'),
    (int)scalar('SELECT COUNT(*) FROM mail_jobs'), (int)scalar('SELECT COUNT(*) FROM record_versions'),
    (int)scalar('SELECT COUNT(*) FROM audit_log')];
/** The student's login row. */
$loginOf = fn(int $studentId): ?array => one('SELECT a.* FROM students s JOIN accounts a ON a.id=s.account_id WHERE s.id=?', [$studentId]);
/** The readable link a member of staff's session keeps for a login. */
$keptToken = fn(int $accountId): string => (string)($_SESSION['signin_links'][$accountId]['token'] ?? '');
/** Use a sign-in link as its holder does: open it, then post the page. */
$useLink = function (string $token, array $fields) use ($ip): array {
    sign_out();
    throttle_clear('auth-ip', $ip);
    $_SESSION['activation_hash'] = hash('sha256', $token);
    return submit('activate', $fields);
};

// ---------------------------------------------------------------------------
case_('The wizard writes nothing before student_create, and no step of it written by a GET');
$before = $written();
$step2 = act('student_draft', ['first_name'=>'Lena', 'last_name'=>'Hofer', 'birth_date'=>'2015-05-12', 'course'=>'none', 'status'=>'active']);
$key = (string)($step2[1]['draft'] ?? '');
ok(student_draft_key($key), 'step 1 answers with a draft key in the address: '.$key);
is_same('student_new', $step2[0], 'and leads to the wizard');
is_same($before, $written(), 'and writes nothing to the database: no student, no login, no audit line');
is_same(['Lena', 'Hofer', '2015-05-12', 'active', 0, null], array_values(array_intersect_key(student_draft($key) ?? [],
        array_flip(['first_name', 'last_name', 'birth_date', 'status', 'class_id', 'tariff_id']))),
        'the details are in the session, under the key, and nowhere else');
ok(!str_contains(http_build_query($step2[1]), 'Lena'), 'the address carries the key and never the details');
$drafts = $_SESSION['student_drafts'];
foreach ([[], ['draft'=>$key], ['draft'=>$key, 'step'=>'1'], ['draft'=>str_repeat('0', 32)]] as $query)
    render_view('student_new', $query);
is_same($before, $written(), 'opening step 1, step 2, „Ändern“ and a draft that is gone writes nothing either');
is_same($drafts, $_SESSION['student_drafts'], 'not even to the session');
ok(str_contains(render_view('student_new', ['draft'=>str_repeat('0', 32)]), e('Die Angaben waren nicht mehr da. Bitte noch einmal eintragen.')),
   'a draft that is gone sends step 2 back to step 1, with the designer’s sentence');
throws(fn() => act('student_draft', ['first_name'=>'', 'last_name'=>'Hofer', 'course'=>'none', 'status'=>'active']),
       'a first name is required', 'Vor- und Nachnamen');
throws(fn() => act('student_draft', ['first_name'=>'Lena', 'last_name'=>'Hofer', 'course'=>'', 'status'=>'active']),
       'and a course, or „Noch keinen Kurs“', 'Noch keinen Kurs');
throws(fn() => act('student_draft', ['first_name'=>'Lena', 'last_name'=>'Hofer', 'course'=>'none', 'status'=>'erfunden']),
       'and a status there is', 'Status');
$edited = act('student_draft', ['draft'=>$key, 'first_name'=>'Lena-Marie', 'last_name'=>'Hofer', 'birth_date'=>'', 'course'=>'none', 'status'=>'trial']);
is_same($key, $edited[1]['draft'], 'changing a draft keeps its key');
is_same('Lena-Marie', student_draft($key)['first_name'] ?? null, 'and its details are the new ones');
is_same($before, $written(), 'still nothing written');

case_('A draft lasts two hours, and a session keeps ten');
$_SESSION['student_drafts'][$key]['saved_at'] = time() - STUDENT_DRAFT_SECONDS - 1;
is_same(null, student_draft($key), 'a draft older than two hours is gone');
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'none']), 'and step 2 of it is refused, in the designer’s words',
       'Die Angaben waren nicht mehr da.');
$keys = [];
for ($i = 1; $i <= 11; $i++)
    $keys[] = (string)act('student_draft', ['first_name'=>'Kind'.$i, 'last_name'=>'Zehn', 'birth_date'=>'', 'course'=>'none', 'status'=>'active'])[1]['draft'];
is_same(10, count($_SESSION['student_drafts']), 'an eleventh draft leaves ten');
is_same(null, student_draft($keys[0]), 'by dropping the oldest');
ok(student_draft($keys[10]) !== null && student_draft($keys[1]) !== null, 'and keeping the rest');
ok(!isset($_SESSION['student_drafts'][$key]), 'a draft past its two hours is gone from the session at the next write');
$_SESSION['student_drafts'] = [];

case_('„Ohne Anmeldung anlegen“ makes the student with a placeholder, in one transaction with the course');
$course = make_class(['name'=>'Kinder Anfänger', 'capacity'=>2]);
$tariff = make_tariff(['class_id'=>$course, 'name'=>'Monatlich', 'price_cents'=>3500]);
$key = (string)act('student_draft', ['first_name'=>'Jonas', 'last_name'=>'Berger', 'birth_date'=>'', 'course'=>$course.':'.$tariff, 'status'=>'active'])[1]['draft'];
$done = act('student_create', ['draft'=>$key, 'method'=>'none']);
$jonas = (int)($done[1]['id'] ?? 0);
is_same(['student_new', ['step'=>'done', 'id'=>$jonas]], $done, 'it lands on the done page');
$login = $loginOf($jonas);
is_same(['student', 'placeholder', null, null, null, null, 'Jonas Berger'],
        [$login['role'] ?? null, $login['state'] ?? null, $login['email'] ?? null, $login['username'] ?? null, $login['password_hash'] ?? null,
         $login['verified_at'] ?? null, $login['name'] ?? null],
        'a student’s login with no address, no username, no password, called what the student is called');
is_same([$tariff, today()], [(int)scalar('SELECT tariff_id FROM class_students WHERE class_id=? AND student_id=?', [$course, $jonas]),
        (string)scalar('SELECT joined_on FROM class_students WHERE class_id=? AND student_id=?', [$course, $jonas])],
        'and is in the course, on the tariff, from today');
is_same(null, student_draft($key), 'the draft is dropped');
is_same(['insert'], array_column(history_for('students', $jonas), 'operation'), 'the change log has the student’s creation');
is_same(0, give_every_student_a_login(), 'and the update’s step finds nobody to give a login to');
$page = render_view('student_new', ['step'=>'done', 'id'=>$jonas]);
ok(str_contains($page, e('Ohne Anmeldung – du trägst alles selbst ein.')) && str_contains($page, e(url('student', ['id'=>$jonas, '#'=>'access']))),
   'the done page says so, and points to the access card');
ok(str_contains($page, e('Versehentlich angelegt?')), 'and says how a student added by mistake is deleted');

case_('„Anlegen“ tapped twice, or sent again after Back, makes the child once and lands where the first went');
/* The second copy of a form already answered was refused with „bereits
   verarbeitet" over the first one's „angelegt" and sent back to step 2, whose
   draft was gone - „Bitte noch einmal eintragen" - so she typed the child in
   again. Now it lands where the first went, with the first one's words. */
$key = (string)act('student_draft', ['first_name'=>'Pia', 'last_name'=>'Doppelt', 'birth_date'=>'', 'course'=>'none', 'status'=>'active'])[1]['draft'];
$sent = ['draft'=>$key, 'method'=>'none', 'request_id'=>bin2hex(random_bytes(32))];
$first = submit('student_create', $sent);
$said = $_SESSION['flash'] ?? null;
$second = null;
does_not_throw(function () use ($sent, &$second) { $second = submit('student_create', $sent); }, 'the very same form sent again is not refused');
is_same($first, $second, 'it lands where the first went: the done page of the child it made');
is_same(['message'=>'Pia Doppelt ist angelegt.', 'kind'=>'success'], $said, 'the first one said so');
is_same($said, $_SESSION['flash'] ?? null, 'and that is still what she reads, with no error over it');
$pia = (int)$first[1]['id'];
is_same(1, (int)scalar("SELECT COUNT(*) FROM students WHERE last_name='Doppelt'"), 'one child');
$back = render_view('student_new', ['draft'=>$key]);
ok(str_contains($back, e('Pia Doppelt ist schon angelegt.')) && str_contains($back, e(url('student_new', ['step'=>'done', 'id'=>$pia]))),
   'Back from the done page shows step 2 saying the child is made, with the way back to the done page');
ok(!str_contains($back, e('Die Angaben waren nicht mehr da.')) && !str_contains($back, 'value="student_create"'),
   'and neither asks for the details again nor offers to make the child');
is_same(['student_new', ['draft'=>$key]], act('student_create', ['draft'=>$key, 'method'=>'none']),
        'a step 2 reloaded and sent again - a new form, the same draft - lands there too');
is_same(['student_new', ['draft'=>$key]], act('student_draft', ['draft'=>$key, 'first_name'=>'Pia', 'last_name'=>'Doppelt', 'birth_date'=>'', 'course'=>'none', 'status'=>'active']),
        'and so does step 1 sent again from Back');
is_same(1, (int)scalar("SELECT COUNT(*) FROM students WHERE last_name='Doppelt'"), 'still one child');
$_SESSION['student_drafts'][$key]['saved_at'] = time() - STUDENT_DRAFT_SECONDS - 1;
ok(str_contains(render_view('student_new', ['draft'=>$key]), e('Die Angaben waren nicht mehr da.')), 'what it became is kept as long as a draft is, two hours');
$_SESSION['student_drafts'] = [];

case_('A course that filled or closed since step 1 is refused at step 2, and nothing is written');
$key = (string)act('student_draft', ['first_name'=>'Mia', 'last_name'=>'Voll', 'birth_date'=>'', 'course'=>$course.':'.$tariff, 'status'=>'active'])[1]['draft'];
make_enrolment($course, make_student());
$before = $written();
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'none']), 'a course full since step 1 is refused', 'inzwischen voll');
is_same($before, $written(), 'and nothing is written');
ok(student_draft($key) !== null, 'and the draft is whole for another choice');
$stillOpen = make_class(['name'=>'Noch frei', 'capacity'=>5]);
$stepOne = render_view('student_new');
ok(!str_contains($stepOne, 'value="'.$course.':'.$tariff.'"') && str_contains($stepOne, 'value="'.$stillOpen.':0"'),
   'step 1 no longer offers the full course, and still offers the one with room');
run('UPDATE classes SET archived=1 WHERE id=?', [$course]);
throws(fn() => act('student_draft', ['first_name'=>'Mia', 'last_name'=>'Voll', 'course'=>$course.':'.$tariff, 'status'=>'active']),
       'nor an archived one', 'gibt es nicht mehr');
run('UPDATE classes SET archived=0, capacity=0 WHERE id=?', [$course]);
throws(fn() => act('student_draft', ['first_name'=>'Mia', 'last_name'=>'Voll', 'course'=>$course.':'.make_tariff(), 'status'=>'active']),
       'nor a tariff of another course', 'Tarif');
throws(fn() => act('student_draft', ['first_name'=>'Mia', 'last_name'=>'Voll', 'course'=>'1;2', 'status'=>'active']),
       'and a course choice of another shape is refused rather than guessed at', 'Ungültige Auswahl');

case_('The last place, taken on another connection after step 2 began, is seen rather than a snapshot from before');
/* Two families on one evening, one place left. Step 2 counts the course's
   children with a locking read of its own: a FOR UPDATE on the course row does
   not reach a subquery, which reads the snapshot the transaction started with
   and so would not see a place another request took and committed since. */
$last = make_class(['name'=>'Letzter Platz', 'capacity'=>1]);
$key = (string)act('student_draft', ['first_name'=>'Nina', 'last_name'=>'Spät', 'birth_date'=>'', 'course'=>$last.':0', 'status'=>'active'])[1]['draft'];
$quicker = make_student(['first_name'=>'Schneller', 'last_name'=>'Woanders']);
$elsewhere = connect();
$_POST = ['draft'=>$key, 'method'=>'none'];
throws(fn() => transactional(function () use ($elsewhere, $last, $quicker) {
    scalar('SELECT COUNT(*) FROM class_students');   // the snapshot this transaction reads from starts here
    $elsewhere->prepare('INSERT INTO class_students (class_id,student_id,joined_on,left_on,tariff_id,price_cents,price_note,due_day) VALUES (?,?,?,NULL,NULL,NULL,?,0)')
        ->execute([$last, $quicker, today(), '']);
    // What a count from the snapshot would see, so this case can fail: a step
    // 2 that counted this way would find the place free.
    is_same(0, (int)scalar('SELECT COUNT(*) FROM class_students WHERE class_id=?', [$last]),
            'a plain count in this transaction does not see the other connection’s child');
    return dispatch_action('student_create');
}), 'the place the other request took is seen, and step 2 is refused', 'inzwischen voll');
$_POST = [];
$elsewhere = null;
is_same([0, 1], [(int)scalar("SELECT COUNT(*) FROM students WHERE first_name='Nina'"), (int)scalar('SELECT COUNT(*) FROM class_students WHERE class_id=?', [$last])],
        'nobody is written, and the course holds the one child who got there first');
ok(student_draft($key) !== null, 'and the draft is whole for another choice');

case_('„Ohne E-Mail, mit Benutzername“ gives a username and a sign-in link, kept only in the maker’s session');
$key = (string)act('student_draft', ['first_name'=>'Lena', 'last_name'=>'Hofer', 'birth_date'=>'', 'course'=>'none', 'status'=>'active'])[1]['draft'];
ok(str_contains(render_view('student_new', ['draft'=>$key]), 'value="lena.hofer"'), 'step 2 suggests first name, dot, last name');
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'username', 'username'=>'lena_hofer']), 'a username with „_“ is refused', '3 bis 30 Zeichen');
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'username', 'username'=>'lena@hofer']), 'and one with „@“', '3 bis 30 Zeichen');
set_setting('privacy_ready', false);
$before = $written();
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'username', 'username'=>'lena.hofer']),
       'without a released privacy notice it is refused, saying what to do', 'Datenschutzerklärung');
is_same($before, $written(), 'nothing is written');
set_setting('privacy_ready', true);
$done = act('student_create', ['draft'=>$key, 'method'=>'username', 'username'=>' Lena.Hofer ']);
$lena = (int)$done[1]['id'];
$lenaLogin = $loginOf($lena);
is_same(['lena.hofer', 'invited', null, null], [$lenaLogin['username'] ?? null, $lenaLogin['state'] ?? null, $lenaLogin['email'] ?? null, $lenaLogin['verified_at'] ?? null],
        'the username is stored in lower case, the login waits for its first sign-in, with no address');
$link = one("SELECT * FROM auth_tokens WHERE account_id=? AND purpose='signin'", [(int)$lenaLogin['id']]);
ok($link !== null, 'a sign-in link exists');
$token = $keptToken((int)$lenaLogin['id']);
ok(preg_match('/^[a-f0-9]{64}$/D', $token) === 1 && hash('sha256', $token) === $link['token_hash'], 'the readable link is in the maker’s session; the database has only its hash');
is_same(token_lifetime('signin'), strtotime((string)$link['expires_at'].' UTC') - strtotime((string)$link['created_at'].' UTC'), 'it lasts 48 hours');
is_same(172800, token_lifetime('signin'), 'which is 48 hours');
is_same(1, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.signin_link' AND entity_id=? AND actor_id=?", [(int)$lenaLogin['id'], $trainer]),
        'its making is written down, with the maker as actor');
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs WHERE account_id=?', [(int)$lenaLogin['id']]), 'and nothing is mailed');
$page = render_view('student_new', ['step'=>'done', 'id'=>$lena]);
ok(str_contains($page, '<svg') && str_contains($page, e(url('activate', ['token'=>$token]))), 'the done page shows the QR code and the link');
ok(str_contains($page, 'lena.hofer'), 'and the sign-in name');
throws(fn() => create_through_wizard(['first_name'=>'Lena', 'last_name'=>'Hofer'], 'username', ['username'=>'LENA.HOFER']),
       'a username somebody has is refused, ignoring case, with a free one named', 'Frei wäre zum Beispiel lena.hofer2.');
is_same('lena.hofer2', username_suggested('Lena', 'Hofer'), 'and the next suggestion is numbered');
sign_in_as($admin);
is_same(null, signin_link_shown((int)$lenaLogin['id']), 'another member of staff is never shown the link somebody else made');
sign_in_as($trainer);
ok(signin_link_shown((int)$lenaLogin['id']) !== null, 'its maker is, while it works');

case_('A sign-in link GET uses nothing up; only its POST does, which always sets a new password');
sign_out();
$_SESSION['activation_hash'] = hash('sha256', $token);
$page = render_view('activate');
ok(str_contains($page, 'value="lena.hofer"') && str_contains($page, 'readonly'), 'the page shows the username, read-only, for the phone to save the password under');
ok(str_contains($page, 'name="privacy_seen"') && !str_contains($page, 'name="newsletter"'), 'it asks for the privacy acknowledgement, and no mail ticks without an address');
ok(token_record(hash('sha256', $token)) !== null, 'opening it - as a WhatsApp preview does - uses nothing up');
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
ok(preg_match("~if\(\\\$page==='activate' && isset\(\\\$_GET\['token'\]\)\) \{\s*throttle\('token-view'~", $router) === 1,
   'the GET only counts, stores the hash in the session and redirects (public/index.php)');
foreach (['no password at all' => [[], 'Byte'], 'a password too short' => [['password'=>'kurz', 'password_confirm'=>'kurz'], 'Byte'],
          'two passwords that differ' => [['password'=>$password, 'password_confirm'=>$password.'x'], 'stimmen nicht'],
          'no privacy tick on the first sign-in' => [['password'=>$password, 'password_confirm'=>$password, 'privacy_seen'=>''], 'Datenschutz']] as $what => [$fields, $said])
    throws(fn() => $useLink($token, $fields + ['privacy_seen'=>'1']), 'a POST with '.$what.' is refused', $said);
ok(token_record(hash('sha256', $token)) !== null, 'and none of them used the link up');
sign_in_as($trainer);
$someoneElse = make_account(['role'=>'student', 'name'=>'Wer anderes']);
sign_in_as($someoneElse);
$authBefore = (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [(int)$lenaLogin['id']]);
throttle('login', username_identity('lena.hofer'), 10);
$_SESSION['activation_hash'] = hash('sha256', $token);
throttle_clear('auth-ip', $ip);
$landed = submit('activate', ['password'=>$password, 'password_confirm'=>$password, 'privacy_seen'=>'1', 'newsletter'=>'1']);
$after = one('SELECT * FROM accounts WHERE id=?', [(int)$lenaLogin['id']]);
is_same(['student', ['id'=>$lena]], $landed, 'the first sign-in lands on her own student page');
ok(str_contains((string)($_SESSION['flash']['message'] ?? ''), 'Du meldest dich ab jetzt mit lena.hofer an.') && str_contains((string)($_SESSION['flash']['message'] ?? ''), 'Willkommen, Lena!'),
   'told how she signs in, and welcomed to check her details: '.($_SESSION['flash']['message'] ?? ''));
is_same((int)$lenaLogin['id'], (int)(current_user()['id'] ?? 0), 'she is signed in, and whoever was signed in on this browser is not');
ok($after['state'] === 'active' && $after['verified_at'] !== null && password_verify($password, (string)$after['password_hash']),
   'active, set up, with the password she chose');
is_same($authBefore + 1, (int)$after['auth_version'], 'every other session of the login ends');
is_same([0, 0], [(int)$after['newsletter'], (int)$after['notifications']], 'a newsletter tick posted without an address to send to is nothing she said yes to');
is_same(0, (int)scalar("SELECT COUNT(*) FROM consent_log WHERE account_id=? AND purpose IN ('newsletter','notifications','payment_notices')", [(int)$after['id']]),
        'nor is any answer about mail written down for her: she was asked nothing about mail she cannot receive');
is_same(1, (int)scalar("SELECT COUNT(*) FROM consent_log WHERE account_id=? AND purpose='privacy_acknowledged'", [(int)$after['id']]), 'the privacy acknowledgement is recorded');
is_same(0, (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [(int)$after['id']]), 'every link of the login is gone');
is_same(1, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.signin_link_used' AND entity_id=? AND actor_id=?", [(int)$after['id'], (int)$after['id']]),
        'its use is written down, with the holder as actor');
is_same(0, (int)(run_counter('SELECT hits FROM rate_limits WHERE bucket=?', [rate_limit_bucket('login', username_identity('lena.hofer'))])->fetchColumn() ?: 0),
        'and the attempts counted against her username are forgotten');
throws(fn() => $useLink($token, ['password'=>$password, 'password_confirm'=>$password, 'privacy_seen'=>'1']), 'a used link fails a second time', 'ungültig oder abgelaufen');
ok(!str_contains(render_page('activate'), $token), 'and its page says only that it no longer works');
sign_in_as($trainer);
is_same(null, signin_link_shown((int)$after['id']), 'the maker is no longer shown a link that has been used');
ok(!isset($_SESSION['signin_links'][(int)$after['id']]), 'and it is gone from her session');

case_('A username signs in in any capitals, and a known and an unknown username are throttled alike');
sign_out();
throttle_clear('auth-ip', $ip);
does_not_throw(fn() => submit('login', ['login'=>' Lena.Hofer ', 'password'=>$password]), '„Lena.Hofer“ signs in as lena.hofer');
is_same((int)$after['id'], (int)(current_user()['id'] ?? 0), 'as the right login');
sign_out();
throttle_clear('auth-ip', $ip);
$lockout = function (string $typed) use ($ip): array {
    $answers = [];
    for ($i = 0; $i < 11; $i++) {
        throttle_clear('auth-ip', $ip);
        try { submit('login', ['login'=>$typed, 'password'=>'falsch-geraten']); $answers[] = 'in'; }
        catch (UserError $e) { $answers[] = str_contains($e->getMessage(), 'Zu viele') ? 'throttled' : $e->getMessage(); }
    }
    $_POST = ['login'=>$typed];
    $bucket = sign_in_identity(attempted_sign_in());
    $counted = (int)(run_counter('SELECT hits FROM rate_limits WHERE bucket=?', [rate_limit_bucket('login', $bucket)])->fetchColumn() ?: 0);
    throttle_clear('login', $bucket);
    return [$answers, $counted, str_starts_with($bucket, 'username:')];
};
$known = $lockout('lena.hofer');
is_same(['throttled'], array_slice($known[0], 10), 'a username that has a login is refused ten times, then throttled');
is_same(true, $known[2], 'counted under the typed username, not the login it names');
is_same($known, $lockout('niemand.hier'), 'and one that has none gets exactly the same answers, counted the same way');
is_same('Anmeldung nicht möglich. Bitte E-Mail bzw. Benutzernamen und Passwort prüfen. Noch nicht eingerichtet? Dann zuerst den Link öffnen, den du bekommen hast.',
        $known[0][0], 'in the one sentence every refusal gets');
$_POST = [];
is_same(['username', 'lena.hofer'], (function () { $_POST = ['login'=>'Lena.Hofer']; return attempted_sign_in(); })(), 'a typed value without „@“ is a username, lower-cased');
is_same(['address', 'lena@beispiel.test'], (function () { $_POST = ['login'=>' Lena@Beispiel.test']; return attempted_sign_in(); })(), 'and one with it an address');
$_POST = [];
is_same(null, account_for_sign_in('username', 'lena_hofer'), 'a value that fails the username rule is never looked up');

case_('A username follows ADR 0023 §1, one rule to a line');
/* Each rule of the record once, each worked out on its own line, so that any
   one of them breaking fails that line rather than hiding behind another or
   stopping the suite. A refusal reads as 'refused', no free name as null. */
foreach ([
    'ä, ö, ü and ß are written ae, oe, ue and ss'     => [fn() => username_from_name('Jörg', 'Weiß'), 'joerg.weiss'],
    'in capitals too'                                  => [fn() => username_from_name('Ännchen', 'Bürger'), 'aennchen.buerger'],
    'and in what is typed'                             => [fn() => username_value('ÖLMÜLLER'), 'oelmueller'],
    'two characters are refused'                       => [fn() => username_value('ab'), 'refused'],
    'three are accepted'                               => [fn() => username_value('abc'), 'abc'],
    'thirty are accepted'                              => [fn() => username_value(str_repeat('a', 30)), str_repeat('a', 30)],
    'thirty-one are refused'                           => [fn() => username_value(str_repeat('a', 31)), 'refused'],
    'a digit first is refused'                         => [fn() => username_value('1lena'), 'refused'],
    'a dot last is refused'                            => [fn() => username_value('lena.'), 'refused'],
    'a hyphen last is refused'                         => [fn() => username_value('lena-'), 'refused'],
    'two separators in a row are refused'              => [fn() => username_value('lena..hofer'), 'refused'],
    "an apostrophe is dropped: O'Neill"                => [fn() => username_from_name('Seán', "O'Neill"), 'sean.oneill'],
    'and so is a typographic one: O’Neill'             => [fn() => username_from_name('Seán', 'O’Neill'), 'sean.oneill'],
    'a name with nothing left is „konto“'              => [fn() => username_from_name('Иван', 'Петров'), 'konto'],
    'numbered like any other when taken'               => [fn() => username_first_free('konto', ['konto']), 'konto2'],
    'a long name is cut back to a separator in 26'     => [fn() => username_from_name('Alexandra', 'Zimmermann-Oberhuber'), 'alexandra.zimmermann'],
    'one without a separator is cut at 26'             => [fn() => username_from_name('Donaudampfschifffahrtsgesellschaft', ''), 'donaudampfschifffahrtsgese'],
    'a base of 30 has no room for a number: none free' => [fn() => username_first_free(str_repeat('a', 30), [str_repeat('a', 30)]), null],
] as $rule => [$work, $expected]) {
    try { $got = $work(); } catch (UserError) { $got = 'refused'; }
    is_same($expected, $got, $rule);
}

case_('„Vergessen“ with a username sends nothing to a login without an address, and says the same');
sign_out();
run('DELETE FROM mail_jobs');
throttle_clear('auth-ip', $ip); throttle_clear('forgot-ip', $ip);
submit('forgot', ['login'=>'lena.hofer']);
$said = (string)($_SESSION['flash']['message'] ?? '');
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs'), 'nothing is queued for a login without an address');
is_same(0, (int)scalar("SELECT COUNT(*) FROM auth_tokens WHERE account_id=?", [(int)$after['id']]), 'and no link is made');
throttle_clear('forgot-ip', $ip);
submit('forgot', ['login'=>'niemand.hier']);
is_same($said, (string)($_SESSION['flash']['message'] ?? ''), 'the answer is the one an unknown name gets');
$both = make_account(['role'=>'student', 'email'=>'mit.adresse@beispiel.test', 'username'=>'mit.adresse']);
throttle_clear('forgot-ip', $ip);
submit('forgot', ['login'=>'Mit.Adresse']);
is_same(['mit.adresse@beispiel.test'], array_column(rows("SELECT recipient FROM mail_jobs WHERE account_id=?", [$both]), 'recipient'),
        'a username login that added an address gets its reset link at that address');
throws(fn() => transactional(fn() => send_account_token($after, 'reset')), 'send_account_token() refuses a login without an address', 'keine E-Mail-Adresse');
throws(fn() => transactional(fn() => send_account_token($after, 'signin')), 'and a sign-in link is never mailed', 'never mailed');
does_not_throw(fn() => queue_mail((int)$after['id'], null, 'Neuigkeit', 'Hallo', 'newsletter'), 'queue_mail() with no recipient throws nothing');
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs WHERE account_id=?', [(int)$after['id']]), 'and queues nothing');
sign_in_as($admin);
run('UPDATE accounts SET newsletter=1 WHERE id=?', [(int)$after['id']]);
$reader = make_account(['role'=>'student', 'email'=>'leser@beispiel.test', 'newsletter'=>1]);
does_not_throw(fn() => act('news_save', ['title'=>'Turnier', 'body'=>'Am Samstag', 'published'=>'1', 'send_email'=>'1']),
               'a newsletter to everybody, a username login without an address among them, goes out');
is_same(1, (int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE account_id=? AND category='newsletter'", [$reader]), 'to those with an address');
is_same(0, (int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE account_id=?", [(int)$after['id']]), 'and to nobody without one');

case_('Who may make a sign-in link for whom');
$inUse = one('SELECT * FROM accounts WHERE id=?', [(int)$after['id']]);
$invitedByMail = $loginOf($mailed = create_through_wizard(['first_name'=>'Per', 'last_name'=>'Post'], 'email', ['email'=>'per.post@beispiel.test']));
$placeholder = $loginOf($jonas);
$staffTarget = one('SELECT * FROM accounts WHERE id=?', [$trainer]);
$adminRow = one('SELECT * FROM accounts WHERE id=?', [$admin]);
$trainerRow = one('SELECT * FROM accounts WHERE id=?', [$trainer]);
is_same([true, true, false, false, false],
        [signin_link_possible($placeholder), signin_link_possible($inUse), signin_link_possible($invitedByMail),
         signin_link_possible($staffTarget), signin_link_possible(['state'=>'suspended'] + $inUse)],
        'possible for a placeholder and a student’s login in use; never an invitation by e-mail, a staff login or a suspended one');
is_same([true, false, false, false], [may_create_signin_link($trainerRow, $placeholder), may_create_signin_link($trainerRow, $inUse),
        may_create_signin_link($trainerRow, $invitedByMail), may_create_signin_link($trainerRow, $adminRow)],
        'a trainer for a login not yet signed in, and not for one in use, an invitation by e-mail or any staff login');
is_same([true, true, false, false], [may_create_signin_link($adminRow, $placeholder), may_create_signin_link($adminRow, $inUse),
        may_create_signin_link($adminRow, $invitedByMail), may_create_signin_link($adminRow, $adminRow)],
        'an administrator also for one in use, and never for her own');
sign_in_as($trainer);
throws(fn() => act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'create']), 'the action refuses a trainer for a login in use', 'nur eine Administratorin');
throws(fn() => act('signin_link', ['student_id'=>(string)$mailed, 'mode'=>'create']), 'and anybody for an invitation by e-mail', 'Einladung per E-Mail');
sign_in_as($admin);
throws(fn() => act('signin_link', ['student_id'=>(string)$mailed, 'mode'=>'create']), 'an administrator too', 'Einladung per E-Mail');
is_same(0, (int)scalar("SELECT COUNT(*) FROM auth_tokens WHERE purpose='signin'"), 'no link was made by any of them');
$firstLink = act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'create']);
is_same(['student', ['id'=>$lena, '#'=>'access']], $firstLink, 'an administrator makes one for a login in use, the way back from a forgotten password');
$old = $keptToken((int)$inUse['id']);
act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'create']);
is_same(null, token_record(hash('sha256', $old)), 'a new link kills the old one');
is_same(1, (int)scalar("SELECT COUNT(*) FROM auth_tokens WHERE account_id=? AND purpose='signin'", [(int)$inUse['id']]), 'one link per login');
$newer = $keptToken((int)$inUse['id']);
act('account_state', ['id'=>(string)$inUse['id'], 'mode'=>'suspend']);
is_same(null, token_record(hash('sha256', $newer)), 'and so does suspending the login');
is_same(null, signin_link_shown((int)$inUse['id']), 'and the card no longer shows it');
act('account_state', ['id'=>(string)$inUse['id'], 'mode'=>'restore']);
is_same('active', (string)scalar('SELECT state FROM accounts WHERE id=?', [(int)$inUse['id']]), 'restored, it is in use again');
throttle_clear('signin-link', (string)$admin);
for ($i = 0; $i < 20; $i++) act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'create']);
throws(fn() => act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'create']), 'a member of staff makes at most twenty an hour: the twenty-first is refused', 'Zu viele');
throttle_clear('signin-link', (string)$admin);

case_('A first sign-in link waits for the privacy notice, whichever way it is made');
/* Security review, finding 6. Its holder acknowledges the notice on the page the
   link opens, and a link that page would refuse cannot work. The gate is in
   make_signin_link(), where every link is made - and, for a placeholder, in
   username_to_give(), before its username is written (code review). */
sign_in_as($admin);
$noNotice = make_student(['first_name'=>'Pauline', 'last_name'=>'Wartet']);
$waitingOne = create_through_wizard(['first_name'=>'Wim', 'last_name'=>'Wartet'], 'username', ['username'=>'wim.wartet']);
set_setting('privacy_ready', false);
$linksBefore = (int)scalar("SELECT COUNT(*) FROM auth_tokens WHERE purpose='signin'");
throws(fn() => act('signin_link', ['student_id'=>(string)$noNotice, 'mode'=>'create', 'username'=>'pauline.wartet']),
       'a placeholder gets no username and no link while the notice is not released, and is told what to do', 'Einstellungen → Datenschutz');
is_same(['placeholder', null], array_values(one('SELECT state,username FROM accounts WHERE id=?', [(int)$loginOf($noNotice)['id']]) ?? []),
        'its login is as it was');
throws(fn() => act('signin_link', ['student_id'=>(string)$waitingOne, 'mode'=>'create']),
       'nor does a username login waiting for its first sign-in get a new one', 'Einstellungen → Datenschutz');
is_same($linksBefore, (int)scalar("SELECT COUNT(*) FROM auth_tokens WHERE purpose='signin'"), 'no link was made');
/* Settings are an administrator's: a trainer was sent to „Einstellungen →
   Datenschutz", a page she cannot open (code review). She is told who
   releases it, as the e-mail card tells her. */
sign_in_as($trainer);
$toTrainer = '';
try { act('signin_link', ['student_id'=>(string)$noNotice, 'mode'=>'create', 'username'=>'pauline.wartet']); }
catch (UserError $e) { $toTrainer = $e->getMessage(); }
ok(str_contains($toTrainer, 'Eine Administratorin muss zuerst die Datenschutzerklärung freigeben.') && !str_contains($toTrainer, 'Einstellungen'),
   'a trainer is told an administrator releases it, and sent to no page she cannot open: '.$toTrainer);
set_setting('privacy_ready', true);
throttle_clear('signin-link', (string)$admin); throttle_clear('signin-link', (string)$trainer);
sign_in_as($admin);

case_('A link for a login in use changes only the password, ends the old one, and the holder can see who made it');
$keep = one('SELECT email,username,locale,newsletter,notifications,privacy_version,verified_at FROM accounts WHERE id=?', [(int)$inUse['id']]);
act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'create']);
$token = $keptToken((int)$inUse['id']);
$landed = $useLink($token, ['password'=>'Neues-Passwort-2026!', 'password_confirm'=>'Neues-Passwort-2026!']);
is_same($keep, one('SELECT email,username,locale,newsletter,notifications,privacy_version,verified_at FROM accounts WHERE id=?', [(int)$inUse['id']]),
        'everything but the password is as it was - no privacy tick asked again');
ok(!password_verify($password, (string)scalar('SELECT password_hash FROM accounts WHERE id=?', [(int)$inUse['id']])), 'and the old password no longer works');
is_same(['dashboard', []], $landed, 'it lands where every later sign-in lands, not on the welcome');
is_same('Dein neues Passwort gilt ab sofort.', $_SESSION['flash']['message'] ?? null, 'and says the new password works');
$links = signin_links_for((int)$inUse['id'], PASSWORD_RESET_SHOWN_DAYS);
is_same(['Chefin', true], [$links[0]['made_by'] ?? null, ($links[0]['used_at'] ?? null) !== null],
        'the newest link says who made it and that it was used - what Mein Konto and the access card read');
is_same('Trainerin', end($links)['made_by'] ?? null, 'and the first, the trainer’s');
sign_in_as($admin);
ok(str_contains(render_view('student', ['id'=>$lena]), e('(Chefin), benutzt am')), 'the access card names who made the last link, and that it was used');

case_('A link is asked again as it is used what was asked when it was made');
/* Security review, finding 3. Between making a link and its use, the login it
   opens can change: given an address nobody confirmed, or left without its
   student. Each is refused by the same rule that decided the link could be
   made (signin_link_possible()). */
sign_in_as($admin);
$ole = create_through_wizard(['first_name'=>'Ole', 'last_name'=>'Adresse'], 'username', ['username'=>'ole.adresse']);
$oleLogin = $loginOf($ole);
$oleToken = $keptToken((int)$oleLogin['id']);
run('UPDATE accounts SET email=? WHERE id=?', ['ole@beispiel.test', (int)$oleLogin['id']]);   // an address nobody confirmed, written since
throws(fn() => $useLink($oleToken, ['password'=>$password, 'password_confirm'=>$password, 'privacy_seen'=>'1']),
       'a first link on a login that has an address now is refused: it would set up an address nobody confirmed', 'ungültig oder abgelaufen');
is_same(['invited', null], array_values(one('SELECT state,verified_at FROM accounts WHERE id=?', [(int)$oleLogin['id']]) ?? []), 'and nothing is set up');
/* The page the link opens asked less than the action: it offered the form for
   it, and printed the login's address beside the new password for whoever
   held the link (security re-review N2). Both ask link_usable() now. */
$linkPage = render_view('activate');   // the link is still the one this browser opened
ok(str_contains($linkPage, e('Link nicht mehr gültig')) && !str_contains($linkPage, 'name="password"'),
   'its page says the link no longer works, rather than offering a form that is refused');
ok(!str_contains($linkPage, 'ole@beispiel.test') && !str_contains($linkPage, 'ole.adresse'), 'and says nothing of the login: neither its address nor its username');
sign_in_as($admin);
$gina = create_through_wizard(['first_name'=>'Gina', 'last_name'=>'Weg'], 'username', ['username'=>'gina.weg']);
$ginaLogin = $loginOf($gina);
$useLink($keptToken((int)$ginaLogin['id']), ['password'=>$password, 'password_confirm'=>$password, 'privacy_seen'=>'1']);
sign_in_as($admin);
act('signin_link', ['student_id'=>(string)$gina, 'mode'=>'create']);
act('student_delete', ['id'=>(string)$gina, 'confirmation'=>'Gina Weg']);
is_same([1, 0], [(int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [(int)$ginaLogin['id']]),
                 (int)scalar("SELECT COUNT(*) FROM auth_tokens WHERE account_id=? AND purpose='signin'", [(int)$ginaLogin['id']])],
        'deleting the student leaves the login that was set up, and takes its sign-in link');
$leftBehind = make_token((int)$ginaLogin['id'], 'signin');   // as a link made before this rule would still be there
throws(fn() => $useLink($leftBehind, ['password'=>'Neues-Passwort-2026!', 'password_confirm'=>'Neues-Passwort-2026!']),
       'and a sign-in link to a login without its student signs nobody in', 'ungültig oder abgelaufen');
ok(password_verify($password, (string)scalar('SELECT password_hash FROM accounts WHERE id=?', [(int)$ginaLogin['id']])), 'its password is as it was');
$linkPage = render_view('activate');
ok(str_contains($linkPage, e('Link nicht mehr gültig')) && !str_contains($linkPage, 'name="password"') && !str_contains($linkPage, 'gina.weg'),
   'and its page says so too, without the username of a login that is in use');
sign_in_as($admin);

case_('Withdrawing a link takes it away and says so; withdrawing a username frees it');
act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'create']);
$token = $keptToken((int)$inUse['id']);
act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'withdraw']);
is_same(null, token_record(hash('sha256', $token)), 'the withdrawn link no longer works');
ok(!isset($_SESSION['signin_links'][(int)$inUse['id']]), 'and is gone from the session');
is_same(1, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.signin_link_withdrawn' AND entity_id=?", [(int)$inUse['id']]), 'written down once');
act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'withdraw']);
is_same(1, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.signin_link_withdrawn' AND entity_id=?", [(int)$inUse['id']]),
        'withdrawing nothing writes nothing down');
$waiting = create_through_wizard(['first_name'=>'Tom', 'last_name'=>'Weber'], 'username', ['username'=>'tom.weber']);
$tomLogin = $loginOf($waiting);
throws(fn() => act('account_state', ['id'=>(string)$tomLogin['id'], 'mode'=>'reinvite']), 'a username login is not „sent again“: it gets a new link', 'Nur offene Einladungen');
act('account_state', ['id'=>(string)$tomLogin['id'], 'mode'=>'withdraw']);
$tomNow = $loginOf($waiting);
ok((int)$tomNow['id'] !== (int)$tomLogin['id'] && $tomNow['state'] === 'placeholder', 'withdrawn, the student is on a fresh placeholder');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [(int)$tomLogin['id']]), 'and the username login is gone');
does_not_throw(fn() => act('signin_link', ['student_id'=>(string)$waiting, 'mode'=>'create', 'username'=>'tom.weber']),
               'so the username can be given again - the way to change one before it is used (ADR 0023 §1)');

case_('A student’s login is replaced, never removed, and the database refuses it too');
sign_in_as($admin);
$chat = make_thread([(int)$inUse['id'], $admin], ['kind'=>'staff_direct']);
throws(fn() => transactional(fn() => delete_login((int)$inUse['id'])), 'delete_login() refuses a login a student points to, in words', 'wird nie allein gelöscht');
$refusal = null;
try { run('DELETE FROM accounts WHERE id=?', [(int)$inUse['id']]); } catch (PDOException $e) { $refusal = $e; }
is_same(1451, (int)($refusal?->errorInfo[1] ?? 0), 'and the database refuses it with 1451, the backstop');
throws(fn() => act('account_state', ['id'=>(string)$inUse['id'], 'mode'=>'delete', 'confirmation'=>'lena.hofer@beispiel.test']),
       '„Anmeldung löschen“ of a username login asks for the username typed', 'den Benutzernamen eingeben');
is_same(['student', ['id'=>$lena]], act('account_state', ['id'=>(string)$inUse['id'], 'mode'=>'delete', 'confirmation'=>' Lena.Hofer ']),
        'typed, in any capitals, it deletes and returns to the student');
$fresh = $loginOf($lena);
ok($fresh !== null && (int)$fresh['id'] !== (int)$inUse['id'] && $fresh['state'] === 'placeholder', 'the student has a fresh placeholder');
is_same([0, 0], [(int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [(int)$inUse['id']]), (int)scalar('SELECT COUNT(*) FROM threads WHERE id=?', [$chat])],
        'the old login is gone, and its private conversations with it, so the next holder never sees them');
ok(str_contains((string)($_SESSION['flash']['message'] ?? ''), 'ohne Anmeldung'), 'and she is told the student is without sign-in now');
$change = history_for('students', $lena)[0] ?? [];
is_same([(int)$inUse['id'], (int)$fresh['id']], [(int)(version_changes($change)['account_id']['from'] ?? 0), (int)(version_changes($change)['account_id']['to'] ?? 0)],
        'the student’s change log has the move');
throws(fn() => act('account_state', ['id'=>(string)$fresh['id'], 'mode'=>'delete', 'confirmation'=>'']), 'a placeholder has nothing to delete', 'noch leer');
throws(fn() => act('account_state', ['id'=>(string)$fresh['id'], 'mode'=>'suspend']), 'nor to suspend', 'noch leer');
$orphan = make_account(['role'=>'student', 'email'=>'verwaist@beispiel.test']);
does_not_throw(fn() => transactional(fn() => delete_login($orphan)), 'a login no student points to is still deleted');

case_('A staff login left on a student’s record lets go of the child, and stays the team member’s');
/* From before ADR 0010 a student's record can point to a trainer's login.
   delete_login() refuses any login a student points to and sent her to the
   student's page, whose „Anmeldung löschen" came back here and was refused
   again - a circle. Then it went through, and took the trainer's login with it,
   her chats and her role, because of a stale link on a child's record
   (docs-writer). Now the child gets a fresh placeholder and the team member's
   login stays as it is; deleting it is Konten's, where it is hers. */
sign_in_as($admin);
$oldTrainer = make_account(['role'=>'trainer', 'name'=>'Frühere Trainerin', 'email'=>'frueher@beispiel.test']);
$rita = make_student(['first_name'=>'Rita', 'last_name'=>'Kreis', 'account_id'=>$oldTrainer]);
$herChat = make_thread([$oldTrainer, $admin]);
$deleteOnTheCard = fn() => act('account_state', ['id'=>(string)$oldTrainer, 'mode'=>'delete', 'confirmation'=>'frueher@beispiel.test']);
is_same(['student', ['id'=>$rita]], $deleteOnTheCard(), '„Anmeldung löschen“ on the child goes through, and lands on the child');
is_same(['trainer', 'active', 1], [(string)scalar('SELECT role FROM accounts WHERE id=?', [$oldTrainer]),
        (string)scalar('SELECT state FROM accounts WHERE id=?', [$oldTrainer]), (int)scalar('SELECT COUNT(*) FROM threads WHERE id=?', [$herChat])],
        'the team member’s login stays, with its role and its chats');
ok(($loginOf($rita)['state'] ?? null) === 'placeholder' && (int)$loginOf($rita)['id'] !== $oldTrainer, 'and the child has a fresh placeholder of her own');
$said = (string)($_SESSION['flash']['message'] ?? '');
ok(str_contains($said, 'Rita Kreis hat jetzt eine neue, leere Anmeldung') && str_contains($said, 'Frühere Trainerin gehört zum Team und bleibt'),
   'the sentence names the child and says the team member’s login stays: '.$said);
is_same(1, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.let_go' AND entity_id=?", [$oldTrainer]),
        'and the audit says it was let go of, not deleted');
is_same(['accounts', []], $deleteOnTheCard(), 'no circle: deleted again - on Konten - it is a team login like any other');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [$oldTrainer]), 'and is gone');

case_('No student is in a course without a login, and the access card speaks of a placeholder as one');
$legacy = make_student(['first_name'=>'Alt', 'last_name'=>'Daten', 'account_id'=>null]);
$open = make_class(['name'=>'Offen']);
throws(fn() => act('class_member_add', ['class_id'=>(string)$open, 'student_id'=>(string)$legacy]),
       'enrolling a student without a login stops, as the broken promise it is, and reports itself', 'A student without a login');
is_same(0, (int)scalar('SELECT COUNT(*) FROM class_students WHERE student_id=?', [$legacy]), 'and nothing is written');
does_not_throw(fn() => act('class_member_add', ['class_id'=>(string)$open, 'student_id'=>(string)$jonas]), 'a placeholder is a login, and enrols');
give_every_student_a_login();
does_not_throw(fn() => act('class_member_add', ['class_id'=>(string)$open, 'student_id'=>(string)$legacy]), 'once the update has given the student one, so does that student');
make_enrolment($open, make_student(['account_id'=>$signsIn = make_account()]));
act('class_session_save', ['class_id'=>(string)$open, 'session_on'=>'2026-11-02', 'status'=>'cancelled', 'location'=>'', 'note'=>'', 'notify'=>'1']
    + time_post('starts_at', '') + time_post('ends_at', ''));
is_same([1, 0, 0], array_map(fn(int $a) => (int)scalar("SELECT COUNT(*) FROM notifications WHERE kind='schedule' AND account_id=?", [$a]),
        [$signsIn, (int)$loginOf($jonas)['id'], (int)$loginOf($legacy)['id']]),
        'a changed date is told to a login that signs in, never to a placeholder, whose later holder would find old news');
$card = render_view('student', ['id'=>$jonas]);
ok(str_contains($card, e('Ohne Anmeldung')) && str_contains($card, 'value="signin_link"') && str_contains($card, 'name="username"'),
   'the access card of a placeholder says „Ohne Anmeldung“ and offers a link with a username');
ok(!str_contains($card, 'value="impersonate"'), 'and no view as the child (A6 overruled)');
is_same([false, false, false], [may_impersonate($adminRow, $placeholder), may_impersonate($adminRow, $loginOf($waiting)),
        may_impersonate($adminRow, ['state'=>'active', 'verified_at'=>null] + $placeholder)],
        'nobody views the portal as a placeholder, a login not yet signed in, or an active one never set up');

case_('Saving a student whose login has no address leaves the address on the record hers to type');
$jonasRow = one('SELECT * FROM students WHERE id=?', [$jonas]);
act('student_save', ['id'=>(string)$jonas, 'revision'=>(string)$jonasRow['revision'], 'first_name'=>'Jonas', 'last_name'=>'Berger',
    'email'=>'jonas@beispiel.test', 'birth_date'=>'', 'joined_on'=>'', 'ended_on'=>'', 'status'=>'active', 'internal_notes'=>'', 'address'=>'', 'phone'=>'']);
is_same(['jonas@beispiel.test', null, 'placeholder'], [(string)scalar('SELECT email FROM students WHERE id=?', [$jonas]),
        $loginOf($jonas)['email'], $loginOf($jonas)['state']], 'the record carries it; the placeholder does not, until an invitation');
ok(in_array('Zugang einladen', array_column(student_next_steps($jonas), 'what'), true), 'and the page offers the invitation as the next step');
act('student_invite', ['student_id'=>(string)$jonas]);
is_same(['invited', 'jonas@beispiel.test', (int)$placeholder['id']], [$loginOf($jonas)['state'], $loginOf($jonas)['email'], (int)$loginOf($jonas)['id']],
        'and the invitation turns that placeholder into the login, without a new one');
// A card opened before the child was given a username still offers the
// invitation. Sent, it is told what happened, not that the address is missing.
$given = create_through_wizard(['first_name'=>'Kai', 'last_name'=>'Karte'], 'username', ['username'=>'kai.karte']);
throws(fn() => act('student_invite', ['student_id'=>(string)$given]), 'an invitation from a card older than the child’s login says the child has one', 'schon eine eigene Anmeldung');
$students = (int)scalar('SELECT COUNT(*) FROM students');
throws(fn() => act('student_save', ['id'=>'0', 'first_name'=>'Neu', 'last_name'=>'Alt', 'status'=>'active']),
       'student_save makes nobody: without a student it finds none', 'nicht gefunden');
is_same($students, (int)scalar('SELECT COUNT(*) FROM students'), 'the wizard is the one way a student is made');

case_('„Zugänge“ in three categories, with the students’ filters counted');
test_reset();
$boss = make_account(['role'=>'admin', 'name'=>'Zeta Chefin']);
$coach = make_account(['role'=>'trainer', 'name'=>'Alpha Trainerin']);
sign_in_as($boss);
mail_ready(true);
$a = create_through_wizard(['first_name'=>'Anna', 'last_name'=>'Zander'], 'none');
$b = create_through_wizard(['first_name'=>'Ben', 'last_name'=>'Adler'], 'email', ['email'=>'ben@beispiel.test']);
$c = create_through_wizard(['first_name'=>'Cem', 'last_name'=>'Moser'], 'username', ['username'=>'cem.moser']);
$d = create_through_wizard(['first_name'=>'Dora', 'last_name'=>'Huber'], 'email', ['email'=>'dora@beispiel.test']);
run("UPDATE accounts SET state='suspended' WHERE id=?", [(int)$loginOf($d)['id']]);
$team = team_logins();
is_same([[$coach], [$boss]], [array_map('intval', array_column($team['trainer'], 'id')), array_map('intval', array_column($team['admin'], 'id'))],
        'the team in two: trainers, then administrators');
is_same(['all'=>4, 'waiting'=>2, 'placeholder'=>1, 'suspended'=>1], student_login_counts(), 'each chip counts its students; waiting is an invitation or a username alike');
is_same([$b, $d, $c, $a], array_map('intval', array_column(student_logins('all', 1), 'student_id')), 'one row per student, sorted by last name');
is_same([$b, $c], array_map('intval', array_column(student_logins('waiting', 1), 'student_id')), 'and filtered by a chip');
is_same([$b, $d, $c, $a], array_map('intval', array_column(student_logins('erfunden', 1), 'student_id')), 'an unknown chip is „Alle“');
ok(student_logins('waiting', 1)[1]['link_expires_at'] !== null && student_logins('placeholder', 1)[0]['link_expires_at'] === null,
   'a login waiting carries until when its link works; a placeholder has none');
is_same([], student_logins('all', 2), 'fifty to a page, so four fill the first');
for ($i = 0; $i < STUDENT_LOGINS_PER_PAGE; $i++) make_student(['first_name'=>'Viele', 'last_name'=>'Kind'.str_pad((string)$i, 2, '0', STR_PAD_LEFT)]);
is_same([STUDENT_LOGINS_PER_PAGE, 4], [count(student_logins('all', 1)), count(student_logins('all', 2))], 'and the rest on the next');
$hugePage = null;
does_not_throw(function () use (&$hugePage) { $hugePage = student_logins('all', (int)'99999999999999999999'); },
               'a page number far past any there is - ?p= typed with twenty nines - does not stop the page');
is_same([], $hugePage, 'it is simply empty');

case_('The start checklist leads to the wizard, and its invitations step to a child without sign-in');
test_reset();
$boss = make_account(['role'=>'admin']);
sign_in_as($boss);
setup_cache_clear();
is_same(['student_new', ['from'=>'start']], [setup_steps()[4]['page'], setup_steps()[4]['params']], 'with no child yet, „Kinder eintragen“ opens the wizard');
$first = make_student(['first_name'=>'Erste', 'last_name'=>'Ohne']);
setup_cache_clear();
$invite = array_column(setup_steps(), null, 'key')['invite'];
is_same(['student', ['id'=>$first, 'from'=>'start'], 'access'], [$invite['page'], $invite['params'], $invite['anchor'] ?? null],
        '„Familien einladen“ leads to the first child whose login is still a placeholder');
is_same('students', nav_owner('student_new', current_user()), 'the wizard belongs to „Schüler“ in the menu');
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
$redirect = strpos($router, "if(\$page==='student' && (int)(\$_GET['id']??0)<=0)go('student_new',");
ok($redirect !== false && $redirect > (int)strpos($router, '$user=$public?') && $redirect < (int)strpos($router, "require ROOT.'/views/'"),
   'a student page without an id goes to the wizard, once the router knows who is asking');

case_('Signing in drops the readable links, drafts and answered forms the person before made');
$kid = make_student(['first_name'=>'Kim', 'last_name'=>'Link']);
mail_ready(true);
act('signin_link', ['student_id'=>(string)$kid, 'mode'=>'create', 'username'=>'kim.link']);
act('student_draft', ['first_name'=>'Noch', 'last_name'=>'Einer', 'birth_date'=>'', 'course'=>'none', 'status'=>'active']);
$_SESSION['activation_hash'] = hash('sha256', $keptToken((int)$loginOf($kid)['id']));   // she opened the link on her own phone
// Where a form she sent landed: a copy sent again by whoever comes next would
// be taken there, to her page, as though they had sent it.
$herForm = str_repeat('d', 64);
remember_answered_form($herForm, ['student', ['id'=>$kid]]);
ok($_SESSION['signin_links'] !== [] && $_SESSION['student_drafts'] !== [] && answered_form_landing($herForm) !== null,
   'her session holds a link, a draft, a link being opened and a form she sent');
$other = make_account(['role'=>'trainer', 'email'=>'andere@beispiel.test', 'password_hash'=>password_hash($password, PASSWORD_DEFAULT)]);
throttle_clear('auth-ip', $ip);
submit('login', ['login'=>'andere@beispiel.test', 'password'=>$password]);
ok(!isset($_SESSION['signin_links']) && !isset($_SESSION['student_drafts']) && !isset($_SESSION['activation_hash']),
   'whoever signs in next on the browser finds none of them');
is_same(null, answered_form_landing($herForm), 'nor where her form landed');
$_SESSION['user_id'] = $other; $_SESSION['auth_version'] = 999;
$_SESSION['signin_links'] = [1=>['token'=>str_repeat('a', 64), 'by'=>$other]]; $_SESSION['activation_hash'] = str_repeat('b', 64);
remember_answered_form($herForm, ['student', ['id'=>$kid]]);
current_user(true);
ok(!isset($_SESSION['signin_links']) && !isset($_SESSION['activation_hash']) && answered_form_landing($herForm) === null,
   'and a session that ended keeps none either');
sign_out();
$_SESSION['activation_hash'] = str_repeat('c', 64);
current_user(true);
is_same(str_repeat('c', 64), $_SESSION['activation_hash'] ?? null,
        'while a link opened with nobody signed in stays until its page is sent: that is where it lives in between');
unset($_SESSION['activation_hash']);

case_('A username login adds an address under Mein Konto, confirmed from the new mailbox, and then signs in with both');
test_reset();
mail_ready(true);
$kid = make_student(['first_name'=>'Ida', 'last_name'=>'Adresse',
    'account_id'=>$holder = make_account(['role'=>'student', 'email'=>null, 'username'=>'ida.adresse', 'password_hash'=>password_hash($password, PASSWORD_DEFAULT)])]);
sign_in_as($holder);
throttle_clear('email-change', (string)$holder);
does_not_throw(fn() => act('email_change', ['password'=>$password, 'email'=>'ida@beispiel.test']), '„E-Mail-Adresse hinzufügen“ is the change of address there is (ADR 0023 §1)');
is_same(['ida@beispiel.test'], array_column(rows("SELECT recipient FROM mail_jobs WHERE account_id=? AND category='security'", [$holder]), 'recipient'),
        'the confirmation goes to the new mailbox, though the login had none');
$_SESSION['activation_hash'] = hash('sha256', make_token($holder, 'email', 'ida@beispiel.test'));
act('activate', []);
is_same(['ida@beispiel.test', 'ida.adresse', 'ida@beispiel.test'], [scalar('SELECT email FROM accounts WHERE id=?', [$holder]),
        scalar('SELECT username FROM accounts WHERE id=?', [$holder]), scalar('SELECT email FROM students WHERE id=?', [$kid])],
        'confirmed, the login has the address and keeps its username, and the student’s copy follows');
is_same([$holder, $holder], [(int)(account_for_sign_in('address', 'ida@beispiel.test')['id'] ?? 0), (int)(account_for_sign_in('username', 'ida.adresse')['id'] ?? 0)],
        'and both sign in to it');
ok(account_takes_mail(one('SELECT * FROM accounts WHERE id=?', [$holder]), 'payments'), 'and mail reaches it from now on');
sign_out();
