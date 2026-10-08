<?php
/**
 * Every student has a login and a wizard adds one (ADR 0023), and everybody
 * signs in by their own address (ADR 0030):
 * docs/decisions/0023-every-student-has-a-login-a-wizard-adds-one-and-one-time-sign-in-links.md,
 * docs/decisions/0030-one-person-one-address-usernames-and-sign-in-links-go.md.
 *
 * The rules the records' "Must stay true" name, each asserted where it can
 * break: a student is never without a login after any path; enrolment refuses
 * one without; a student's login is replaced, never deleted; the wizard writes
 * nothing before student_create, and makes an invited login or a placeholder
 * and nothing else; only the invitation turns a placeholder into a login; a
 * sign-in link from before ADR 0030 signs nobody in. The structure suite holds
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
/** Open a link as its holder does - signed out, as from a mailbox - and post its page. */
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
is_same(['student', 'placeholder', null, null, null, 'Jonas Berger'],
        [$login['role'] ?? null, $login['state'] ?? null, $login['email'] ?? null, $login['password_hash'] ?? null,
         $login['verified_at'] ?? null, $login['name'] ?? null],
        'a student’s login with no address, no password, called what the student is called');
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
ok(str_contains($back, e('Pia Doppelt ist schon angelegt')) && str_contains($back, e(url('student', ['id'=>$pia, '#'=>'access']))),
   'Back from the done page shows step 2 saying the child is made, and what came of it - here the way to set up the sign-in');
ok(!str_contains($back, e('Die Angaben waren nicht mehr da.')) && !str_contains($back, 'value="student_create"'),
   'and neither asks for the details again nor offers to make the child');
is_same(['student_new', ['draft'=>$key]], act('student_create', ['draft'=>$key, 'method'=>'none']),
        'a step 2 reloaded and sent again - a new form, the same draft - lands there too');
is_same(['student_new', ['draft'=>$key]], act('student_draft', ['draft'=>$key, 'first_name'=>'Pia', 'last_name'=>'Doppelt', 'birth_date'=>'', 'course'=>'none', 'status'=>'active']),
        'and so does step 1 sent again from Back');
is_same(1, (int)scalar("SELECT COUNT(*) FROM students WHERE last_name='Doppelt'"), 'still one child');
$_SESSION['student_drafts'][$key]['saved_at'] = time() - STUDENT_DRAFT_SECONDS - 1;
ok(str_contains(render_view('student_new', ['draft'=>$key]), e('Die Angaben waren nicht mehr da.')), 'what it became is kept as long as a draft is, two hours');
/* A step 2 sent after its draft has gone is refused with that sentence in the
   banner, and comes back to step 1 - which said it a second time. */
$GLOBALS['crm_held_input'] = ['action'=>'student_create', 'page'=>'student_new', 'id'=>'0', 'tab'=>'', 'record'=>null, 'fields'=>['draft'=>$key, 'method'=>'none']];
$stepOne = render_view('student_new', ['draft'=>$key]);
ok(str_contains($stepOne, 'value="student_draft"') && !str_contains($stepOne, e('Die Angaben waren nicht mehr da.')),
   'after a refused step 2 the banner says it, and step 1 does not say it again');
$GLOBALS['crm_held_input'] = null;
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

case_('„Per E-Mail einladen“ refuses before the first write, and turns the placeholder into an invited login with the invitation queued');
/* ADR 0030 §6: every refusal before the first write, as ADR 0023 §5 had it -
   an address at all, nobody else's login, not one a student without sign-in
   carries, and mail that can go out - one transaction, the draft kept whole. */
$key = (string)act('student_draft', ['first_name'=>'Lena', 'last_name'=>'Hofer', 'birth_date'=>'', 'course'=>'none', 'status'=>'active'])[1]['draft'];
make_account(['role'=>'student', 'email'=>'vergeben@beispiel.test']);
make_student(['first_name'=>'Bruder', 'last_name'=>'Hofer', 'email'=>'eltern@beispiel.test']);
$before = $written();
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'email', 'email'=>'lena@beispiel']), 'an address that is none is refused', 'Ungültige E-Mail-Adresse');
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'email', 'email'=>'vergeben@beispiel.test']), 'and one that is another login’s', 'Jede Person braucht ihre eigene');
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'email', 'email'=>'eltern@beispiel.test']),
       'and one a student without sign-in carries, naming them', 'steht schon bei Bruder Hofer');
mail_ready(false);
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'email', 'email'=>'lena@beispiel.test']), 'and any address while mail cannot go out', 'lässt sich noch nicht verschicken');
mail_ready(true);
is_same($before, $written(), 'nothing is written by any of them');
ok(student_draft($key) !== null, 'and the draft is whole for another try');
$done = act('student_create', ['draft'=>$key, 'method'=>'email', 'email'=>' Lena@Beispiel.test ', 'locale'=>'en']);
$lena = (int)$done[1]['id'];
$lenaLogin = $loginOf($lena);
is_same(['invited', 'lena@beispiel.test', null, null, 'en', 'Lena Hofer'],
        [$lenaLogin['state'] ?? null, $lenaLogin['email'] ?? null, $lenaLogin['password_hash'] ?? null, $lenaLogin['verified_at'] ?? null, $lenaLogin['locale'] ?? null, $lenaLogin['name'] ?? null],
        'the student’s placeholder became the invited login, at the address normalised, in the language chosen, with no password yet');
is_same('lena@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$lena]), 'and the record carries the same address');
is_same(['insert'], array_column(history_for('students', $lena), 'operation'), 'whose „Änderungen“ has its creation and no empty line after it');
is_same(['invite'], array_column(rows('SELECT purpose FROM auth_tokens WHERE account_id=?', [(int)$lenaLogin['id']]), 'purpose'), 'one link, the invitation');
is_same(['lena@beispiel.test'], array_column(rows('SELECT recipient FROM mail_jobs WHERE account_id=?', [(int)$lenaLogin['id']]), 'recipient'), 'queued to that address');
is_same([172800, 3600], [token_lifetime('invite'), token_lifetime('reset')], 'an invitation works 48 hours, a reset link one');
$page = render_view('student_new', ['step'=>'done', 'id'=>$lena]);
ok(str_contains($page, e('Die Einladung geht an lena@beispiel.test')), 'the done page says where the invitation went');
ok(str_contains($page, e('Kommt keine E-Mail an? Auf der Seite von Lena prüfst du die Adresse und sendest die Einladung noch einmal.')),
   'and where to look when nothing arrives');
ok(!str_contains($page, e('Benutzername')) && !str_contains($page, e('Anmeldelink')), 'and nothing of a username or a link');

case_('The wizard knows two ways to sign in: method=username is refused by choose() and writes nothing');
$key = (string)act('student_draft', ['first_name'=>'Kim', 'last_name'=>'Weg', 'birth_date'=>'', 'course'=>'none', 'status'=>'active'])[1]['draft'];
$before = $written();
throws(fn() => act('student_create', ['draft'=>$key, 'method'=>'username', 'username'=>'kim.weg']), 'the method from before ADR 0030 is refused', 'Ungültige Auswahl');
is_same($before, $written(), 'and nothing is written');
ok(student_draft($key) !== null, 'the draft is whole for one of the two');
$step2 = render_view('student_new', ['draft'=>$key]);
ok(str_contains($step2, 'name="method" value="email"') && str_contains($step2, 'name="method" value="none"'), 'step 2 offers the two');
ok(!str_contains($step2, 'name="username"') && !str_contains($step2, 'value="username"') && !str_contains($step2, e('Benutzername')), 'and no username anywhere');
ok(preg_match('~id="by-email">.*?'.preg_quote(e('Empfohlen'), '~').'.*?'.preg_quote(e('Anlegen und einladen'), '~').'.*?id="later">.*?<h2>'.preg_quote(e('Ohne Anmeldung'), '~').'</h2>.*?'
              .preg_quote(e('Ohne Anmeldung anlegen'), '~').'~s', $step2) === 1,
   'first „Per E-Mail einladen“, recommended, with „Anlegen und einladen“; then „Ohne Anmeldung“ with its own button (spec addendum §2)');

case_('The invitation sets the login up, and from then on the address alone signs in, in any capitals');
$token = make_token((int)$lenaLogin['id'], 'invite');
$landed = $useLink($token, ['password'=>$password, 'password_confirm'=>$password, 'privacy_seen'=>'1', 'newsletter'=>'1']);
is_same(['student', ['id'=>$lena]], $landed, 'the first sign-in lands on her own student page');
ok(str_contains((string)($_SESSION['flash']['message'] ?? ''), 'Du meldest dich ab jetzt mit lena@beispiel.test an.'), 'told what she signs in with: '.($_SESSION['flash']['message'] ?? ''));
$after = one('SELECT * FROM accounts WHERE id=?', [(int)$lenaLogin['id']]);
ok($after['state'] === 'active' && $after['verified_at'] !== null && password_verify($password, (string)$after['password_hash']), 'active, set up, with the password she chose');
is_same([1, 0], [(int)$after['newsletter'], (int)$after['notifications']], 'the two mail switches are what she ticked');
is_same(['newsletter'=>1, 'notifications'=>0, 'payment_notices'=>1, 'privacy_acknowledged'=>1],
        array_map('intval', array_column(rows('SELECT purpose,enabled FROM consent_log WHERE account_id=? ORDER BY purpose', [(int)$after['id']]), 'enabled', 'purpose')),
        'and written down as her answers, every invitation having an address');
is_same(0, (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [(int)$after['id']]), 'every link of the login is gone');
sign_out();
throttle_clear('auth-ip', $ip);
does_not_throw(fn() => submit('login', ['login'=>' LENA@Beispiel.TEST ', 'password'=>$password]), '„LENA@Beispiel.TEST“ signs in as lena@beispiel.test');
is_same((int)$after['id'], (int)(current_user()['id'] ?? 0), 'as the right login');
sign_out();
throttle_clear('auth-ip', $ip);
is_same(null, account_for_sign_in('lena.hofer'), 'a value that is no address finds nobody');
is_same(0, query_count(fn() => account_for_sign_in('lena.hofer')), 'and reaches no SELECT: it is never looked up (ADR 0030, Tests 1)');
is_same(1, query_count(fn() => account_for_sign_in('niemand@beispiel.test')), 'while an address is one statement, found or not');
throws(fn() => submit('login', ['login'=>'lena.hofer', 'password'=>$password]), 'and the name a username would have had is refused, with the right password, in the one sentence',
       'Anmeldung nicht möglich. Bitte E-Mail-Adresse und Passwort prüfen. Noch nicht eingerichtet? Dann zuerst den Link aus der Einladung öffnen.');
throttle_clear('login', address_identity('lena.hofer'));

case_('A sign-in link from before the update signs nobody in: its row opens nothing, and the prune removes it once lapsed');
/* ADR 0030 §2: no statement deletes the sign-in links' rows. link_usable()
   names the three purposes there are, so such a row opens nothing, and the
   nightly prune takes it within 48 hours. */
$leftover = bin2hex(random_bytes(32));
fixture('auth_tokens', ['account_id'=>(int)$after['id'], 'token_hash'=>hash('sha256', $leftover), 'purpose'=>'signin', 'target_email'=>null,
                        'expires_at'=>gmdate('Y-m-d H:i:s', time() + 3600), 'created_at'=>now()]);
sign_out();
$_SESSION['activation_hash'] = hash('sha256', $leftover);
$page = render_view('activate');
ok(str_contains($page, e('Link nicht mehr gültig')) && !str_contains($page, 'name="password"') && !str_contains($page, 'lena@beispiel.test'),
   'the page says the link no longer works, offers no form and shows nothing of the login');
$rowBefore = one('SELECT * FROM accounts WHERE id=?', [(int)$after['id']]);
$before = $written();
throws(fn() => $useLink($leftover, ['password'=>'Neues-Passwort-2026!', 'password_confirm'=>'Neues-Passwort-2026!', 'privacy_seen'=>'1']), 'the POST is refused', 'ungültig oder abgelaufen');
is_same([$rowBefore, $before, null], [one('SELECT * FROM accounts WHERE id=?', [(int)$after['id']]), $written(), current_user()],
        'the login is as it was, nothing is written, and nobody is signed in');
run('UPDATE auth_tokens SET expires_at=? WHERE token_hash=?', [gmdate('Y-m-d H:i:s', time() - 1), hash('sha256', $leftover)]);
prune_expired();
is_same(0, (int)scalar("SELECT COUNT(*) FROM auth_tokens WHERE purpose='signin'"), 'lapsed, the prune removes it');
sign_in_as($trainer);
throws(fn() => act('signin_link', ['student_id'=>(string)$lena, 'mode'=>'create']), 'and signin_link is no action any more', 'Unbekannte Aktion');

case_('A link opens only the state it was made for, and a mail carrying one that no longer does is not sent [security review F3]');
/* An invitation goes with a login still invited: accepted on a login in use it
   would set a new password, consents and the privacy acknowledgement - a reset
   that skips the reset's rule. A reset and a changed address go with a login in
   use. The sender asks the same rule, so a mail waiting in the outbox for a
   login that has changed since is dropped rather than delivered with a link
   that can only say „Link nicht mehr gültig“ - a confirmation of a new address
   for a username login that 039 has made a placeholder, say. */
$asIs = fn(int $id): array => [one('SELECT * FROM accounts WHERE id=?', [$id]), (int)scalar('SELECT COUNT(*) FROM consent_log WHERE account_id=?', [$id])];
$lenaId = (int)$lenaLogin['id'];
$lateInvite = make_token($lenaId, 'invite');
is_same(false, link_usable(token_record(hash('sha256', $lateInvite))), 'an invitation to a login in use opens nothing');
$before = $asIs($lenaId);
throws(fn() => $useLink($lateInvite, ['password'=>'Neues-Passwort-2026!', 'password_confirm'=>'Neues-Passwort-2026!', 'privacy_seen'=>'1']),
       'its POST is refused', 'ungültig oder abgelaufen');
is_same($before, $asIs($lenaId), 'and writes nothing: not the password, not a consent');
$invitedOnly = make_account(['role'=>'student', 'email'=>'nur.eingeladen@beispiel.test', 'state'=>'invited', 'verified_at'=>null, 'password_hash'=>null]);
$placeholderOnly = make_account(['role'=>'student', 'email'=>null, 'state'=>'placeholder', 'verified_at'=>null, 'password_hash'=>null]);
$suspendedOnly = make_account(['role'=>'student', 'email'=>'gesperrt.nur@beispiel.test', 'state'=>'suspended']);
$opens = fn(int $id, string $purpose): bool => link_usable(token_record(hash('sha256', make_token($id, $purpose, $purpose === 'email' ? 'neu.'.$id.'@beispiel.test' : null))));
is_same(['invite'=>[true, false, false, false], 'reset'=>[false, true, false, false], 'email'=>[false, true, false, false]],
        array_map(fn($purpose) => array_map(fn($id) => $opens($id, $purpose), [$invitedOnly, $lenaId, $placeholderOnly, $suspendedOnly]), ['invite'=>'invite', 'reset'=>'reset', 'email'=>'email']),
        'each purpose opens the one state it is for - invited, in use, in use - and never a placeholder or a suspended login');
run('DELETE FROM auth_tokens WHERE account_id IN (?,?,?,?)', [$invitedOnly, $lenaId, $placeholderOnly, $suspendedOnly]);
// The outbox: a confirmation of a new address, queued while the login was in
// use, for a login that is a placeholder by the time the sender comes.
run('DELETE FROM mail_jobs');
$moved = make_account(['role'=>'student', 'email'=>null, 'state'=>'active']);
$confirm = make_token($moved, 'email', 'umzug@beispiel.test');
queue_mail($moved, 'umzug@beispiel.test', 'E-Mail-Adresse bestätigen', "Öffne diesen Link:\n".url('activate', ['token'=>$confirm]), 'security');
ok(security_mail_links_live("Öffne diesen Link:\n".url('activate', ['token'=>$confirm]), 'umzug@beispiel.test'), 'while the login is in use, the mail may go');
run("UPDATE accounts SET state='placeholder', verified_at=NULL, password_hash=NULL WHERE id=?", [$moved]);
ok(!security_mail_links_live("Öffne diesen Link:\n".url('activate', ['token'=>$confirm]), 'umzug@beispiel.test'), 'once it is a placeholder, its link opens nothing and the mail may not');
process_mail();
is_same('cancelled', (string)scalar('SELECT status FROM mail_jobs WHERE account_id=?', [$moved]), 'so the sender drops it rather than send a dead link');

case_('A student’s login is replaced, never removed, and the database refuses it too');
sign_in_as($admin);
$inUse = one('SELECT * FROM accounts WHERE id=?', [(int)$lenaLogin['id']]);
$chat = make_thread([(int)$inUse['id'], $admin], ['kind'=>'staff_direct']);
throws(fn() => transactional(fn() => delete_login((int)$inUse['id'])), 'delete_login() refuses a login a student points to, in words', 'wird nie allein gelöscht');
$refusal = null;
try { run('DELETE FROM accounts WHERE id=?', [(int)$inUse['id']]); } catch (PDOException $e) { $refusal = $e; }
is_same(1451, (int)($refusal?->errorInfo[1] ?? 0), 'and the database refuses it with 1451, the backstop');
throws(fn() => act('account_state', ['id'=>(string)$inUse['id'], 'mode'=>'delete', 'confirmation'=>'lena.hofer']),
       '„Anmeldung löschen“ asks for the address typed', 'die E-Mail-Adresse eingeben');
is_same(['student', ['id'=>$lena]], act('account_state', ['id'=>(string)$inUse['id'], 'mode'=>'delete', 'confirmation'=>' Lena@Beispiel.test ']),
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
// A login in use with no address is what only a database edited by hand can
// hold now (039): typing nothing deletes nothing.
$bare = make_account(['role'=>'student', 'email'=>null]);
make_student(['first_name'=>'Ohne', 'last_name'=>'Adresse', 'account_id'=>$bare]);
throws(fn() => act('account_state', ['id'=>(string)$bare, 'mode'=>'delete', 'confirmation'=>'']), 'nor is a login without an address deleted by typing nothing', 'die E-Mail-Adresse eingeben');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [$bare]), 'it is still there');
/* Such a login is also what reaches the guards the mail keeps for a login
   without an address, now that no username login is one. */
throws(fn() => transactional(fn() => send_account_token(one('SELECT * FROM accounts WHERE id=?', [$bare]), 'reset')),
       'send_account_token() refuses it before any link is made', 'keine E-Mail-Adresse');
is_same(0, (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [$bare]), 'and no link is made');
does_not_throw(fn() => queue_mail($bare, null, 'Neuigkeit', 'Hallo', 'newsletter'), 'queue_mail() with no recipient throws nothing');
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs WHERE account_id=?', [$bare]), 'and queues nothing');
$reader = make_account(['role'=>'student', 'email'=>'leser@beispiel.test', 'newsletter'=>1]);
run('UPDATE accounts SET newsletter=1 WHERE id=?', [$bare]);
does_not_throw(fn() => act('news_save', ['title'=>'Turnier', 'body'=>'Am Samstag', 'published'=>'1', 'send_email'=>'1']),
               'a newsletter to everybody, that login among them, goes out');
is_same([1, 0], [(int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE account_id=? AND category='newsletter'", [$reader]),
                 (int)scalar('SELECT COUNT(*) FROM mail_jobs WHERE account_id=?', [$bare])],
        'to those with an address, and to nobody without one');
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
is_same(['accounts', []], $deleteOnTheCard(), 'no circle: deleted again - on Zugänge - it is a team login like any other');
is_same('Zugang gelöscht.', $_SESSION['flash']['message'] ?? null, 'and the banner says it is deleted, in the button’s words');
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
ok(str_contains($card, e('Ohne Anmeldung')) && !str_contains($card, 'value="signin_link"') && !str_contains($card, 'name="username"') && !str_contains($card, e('Benutzername')),
   'the access card of a placeholder says „Ohne Anmeldung“, and offers neither a link nor a username (ADR 0030 §6)');
ok(!str_contains($card, 'value="impersonate"'), 'and no view as the child (A6 overruled)');
$adminRow = one('SELECT * FROM accounts WHERE id=?', [$admin]);
$placeholder = $loginOf($jonas);
$mailed = create_through_wizard(['first_name'=>'Per', 'last_name'=>'Post'], 'email', ['email'=>'per.post@beispiel.test']);
is_same([false, false, false], [may_impersonate($adminRow, $placeholder), may_impersonate($adminRow, $loginOf($mailed)),
        may_impersonate($adminRow, ['state'=>'active', 'verified_at'=>null] + $placeholder)],
        'nobody views the portal as a placeholder, an invited login, or an active one never set up');

case_('Saving a student whose login has no address leaves the address on the record hers to type');
$emailStep = fn() => array_column(student_next_steps($jonas), null, 'what')['E-Mail-Adresse eintragen'] ?? null;
is_same('access', $emailStep()['anchor'] ?? null, 'with mail ready, the next step for a child without an address leads to the access card, where its box is (ADR 0030 §6)');
mail_ready(false);
is_same('email', $emailStep()['anchor'] ?? null, 'and to the record’s own box while the card can offer no form');
mail_ready(true);
$jonasRow = one('SELECT * FROM students WHERE id=?', [$jonas]);
act('student_save', ['id'=>(string)$jonas, 'revision'=>(string)$jonasRow['revision'], 'first_name'=>'Jonas', 'last_name'=>'Berger',
    'email'=>'jonas@beispiel.test', 'birth_date'=>'', 'joined_on'=>'', 'ended_on'=>'', 'status'=>'active', 'internal_notes'=>'', 'address'=>'', 'phone'=>'']);
is_same(['jonas@beispiel.test', null, 'placeholder'], [(string)scalar('SELECT email FROM students WHERE id=?', [$jonas]),
        $loginOf($jonas)['email'], $loginOf($jonas)['state']], 'the record carries it; the placeholder does not, until an invitation');
ok(in_array('Zugang einladen', array_column(student_next_steps($jonas), 'what'), true), 'and the page offers the invitation as the next step');
act('student_invite', ['student_id'=>(string)$jonas]);
is_same(['invited', 'jonas@beispiel.test', (int)$placeholder['id']], [$loginOf($jonas)['state'], $loginOf($jonas)['email'], (int)$loginOf($jonas)['id']],
        'and the invitation turns that placeholder into the login, without a new one');
// A card opened before the child was invited still offers the invitation.
// Sent, it is told what happened, not that the address is missing.
throws(fn() => act('student_invite', ['student_id'=>(string)$mailed]), 'an invitation from a card older than the child’s login says the child has one', 'schon eine eigene Anmeldung');
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
$d = create_through_wizard(['first_name'=>'Dora', 'last_name'=>'Huber'], 'email', ['email'=>'dora@beispiel.test']);
run("UPDATE accounts SET state='suspended' WHERE id=?", [(int)$loginOf($d)['id']]);
$team = team_logins();
is_same([[$coach], [$boss]], [array_map('intval', array_column($team['trainer'], 'id')), array_map('intval', array_column($team['admin'], 'id'))],
        'the team in two: trainers, then administrators');
is_same(['all', 'invited', 'placeholder', 'suspended'], array_keys(student_login_filters()),
        'the chips: „Alle“, „Eingeladen“, „Ohne Anmeldung“, „Gesperrt“ - one name per state (the design addendum of 2026-10-08, §6)');
is_same(['all'=>3, 'invited'=>1, 'placeholder'=>1, 'suspended'=>1], student_login_counts(), 'each chip counts its students');
is_same([$b, $d, $a], array_map('intval', array_column(student_logins('all', 1), 'student_id')), 'one row per student, sorted by last name');
is_same([$b], array_map('intval', array_column(student_logins('invited', 1), 'student_id')), 'and filtered by a chip');
is_same([$b, $d, $a], array_map('intval', array_column(student_logins('erfunden', 1), 'student_id')), 'an unknown chip is „Alle“');
ok(student_logins('invited', 1)[0]['link_expires_at'] !== null && student_logins('placeholder', 1)[0]['link_expires_at'] === null,
   'an invited login carries until when its invitation works; a placeholder has none');
is_same([], student_logins('all', 2), 'fifty to a page, so three fill the first');
for ($i = 0; $i < STUDENT_LOGINS_PER_PAGE; $i++) make_student(['first_name'=>'Viele', 'last_name'=>'Kind'.str_pad((string)$i, 2, '0', STR_PAD_LEFT)]);
is_same([STUDENT_LOGINS_PER_PAGE, 3], [count(student_logins('all', 1)), count(student_logins('all', 2))], 'and the rest on the next');
$hugePage = null;
does_not_throw(function () use (&$hugePage) { $hugePage = student_logins('all', (int)'99999999999999999999'); },
               'a page number far past any there is - ?p= typed with twenty nines - does not stop the page');
is_same([], $hugePage, 'it is simply empty');
/* The page (ADR 0023 §8, 0030 §8, spec addendum §6): three groups, the
   students' four chips with their counts and none for a chip nobody is in, and
   a row per student that leads to the access card and acts on nothing. */
$page = render_view('accounts');
foreach (['trainers', 'admins', 'students'] as $group) ok(str_contains($page, 'id="'.$group.'"'), 'Zugänge has the group '.$group);
ok(str_contains($page, e('Alle (53)')) && str_contains($page, e('Eingeladen (1)')) && str_contains($page, e('Ohne Anmeldung (51)')) && str_contains($page, e('Gesperrt (1)')),
   'each chip says how many are in it');
ok(str_contains($page, 'id="students"') && !str_contains($page, e('Noch nicht angemeldet')) && !str_contains($page, '<small class="mono">'),
   'none is „Noch nicht angemeldet“, and no row reads like a username');
ok(str_contains($page, 'id="students"') && !str_contains($page, 'name="student_id"'), 'no form on the page acts on a student');
$invitedOnly = render_view('accounts', ['logins'=>'invited']);
ok(str_contains($invitedOnly, e(url('student', ['id'=>$b, '#'=>'access']))) && !str_contains($invitedOnly, e(url('student', ['id'=>$d, '#'=>'access']))),
   'a chip shows only its students');
ok(str_contains($invitedOnly, '<small>ben@beispiel.test</small>') && str_contains($invitedOnly, e('Link gilt bis ')), 'an invited row has its address, and until when the invitation works');
$rowOf = fn(string $html, int $student): string => preg_match('~<a class="member-row" href="'.preg_quote(e(url('student', ['id'=>$student, '#'=>'access'])), '~').'">.*?</a>~s', $html, $m) ? $m[0] : '';
$first = (int)scalar("SELECT id FROM students WHERE last_name='Kind00'");
ok($rowOf($page, $first) !== '' && !str_contains($rowOf($page, $first), '<small>'), 'a placeholder’s row has no address line');
ok(str_contains($page, e('Einladen, sperren und löschen machst du auf der Seite der Schülerin oder des Schülers.')), 'and the footnote says where all of it is done');
ok($rowOf($page, $b) !== '' && $rowOf($page, $a) === '', 'fifty to a page, by last name: Adler on the first, Zander on the next');
ok($rowOf(render_view('accounts', ['p'=>'2']), $a) !== '', 'where the next page has her');
ok($rowOf(render_view('accounts', ['logins'=>'erfunden']), $d) !== '', 'an unknown chip in the address is „Alle“');
run("UPDATE accounts SET state='active', verified_at=? WHERE id=?", [now(), (int)$loginOf($d)['id']]);
$page = render_view('accounts');
ok(str_contains($page, e('Alle (53)')) && !str_contains($page, e('Gesperrt (')), 'a chip nobody is in is left out');
sign_in_as($coach);
$asTrainer = render_view('accounts');
ok(str_contains($asTrainer, 'id="trainers"') && !str_contains($asTrainer, '<details class="account-row">') && !str_contains($asTrainer, 'value="suspend"'),
   'a trainer reads the team and acts on nobody');
sign_in_as($boss);

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

case_('Signing in drops the drafts, the link being opened and the answered forms the person before made');
$kid = make_student(['first_name'=>'Kim', 'last_name'=>'Link', 'email'=>'kim.link@beispiel.test']);
mail_ready(true);
act('student_invite', ['student_id'=>(string)$kid]);
act('student_draft', ['first_name'=>'Noch', 'last_name'=>'Einer', 'birth_date'=>'', 'course'=>'none', 'status'=>'active']);
$_SESSION['activation_hash'] = hash('sha256', make_token((int)$loginOf($kid)['id'], 'invite'));   // she opened the invitation on her own phone
// Where a form she sent landed: a copy sent again by whoever comes next would
// be taken there, to her page, as though they had sent it.
$herForm = str_repeat('d', 64);
remember_answered_form($herForm, ['student', ['id'=>$kid]]);
ok($_SESSION['student_drafts'] !== [] && isset($_SESSION['activation_hash']) && answered_form_landing($herForm) !== null,
   'her session holds a draft, a link being opened and a form she sent');
$other = make_account(['role'=>'trainer', 'email'=>'andere@beispiel.test', 'password_hash'=>password_hash($password, PASSWORD_DEFAULT)]);
throttle_clear('auth-ip', $ip);
submit('login', ['login'=>'andere@beispiel.test', 'password'=>$password]);
ok(!isset($_SESSION['student_drafts']) && !isset($_SESSION['activation_hash']), 'whoever signs in next on the browser finds none of them');
is_same(null, answered_form_landing($herForm), 'nor where her form landed');
$_SESSION['user_id'] = $other; $_SESSION['auth_version'] = 999;
$_SESSION['activation_hash'] = str_repeat('b', 64);
remember_answered_form($herForm, ['student', ['id'=>$kid]]);
current_user(true);
ok(!isset($_SESSION['activation_hash']) && answered_form_landing($herForm) === null, 'and a session that ended keeps none either');
sign_out();
$_SESSION['activation_hash'] = str_repeat('c', 64);
current_user(true);
is_same(str_repeat('c', 64), $_SESSION['activation_hash'] ?? null,
        'while a link opened with nobody signed in stays until its page is sent: that is where it lives in between');
unset($_SESSION['activation_hash']);

case_('The access card: the address and „Einladung senden“ in one form, said before the tap where it cannot go, and every form coming back to the card');
/* Spec addendum §3 (ADR 0030 §6): a placeholder's card holds the address box,
   the record's address in it, the invitation's language and the button. An
   address that is another login's, or one a brother's or sister's record
   without sign-in carries too, is said instead of a form that can only be
   refused. Every form on the card names it as the place a refusal comes back
   to (return_anchor). */
test_reset();
$boss = make_account(['role'=>'admin', 'name'=>'Karten Chefin']);
sign_in_as($boss);
mail_ready(true);
$cardOf = fn(int $student): string => preg_match('~<section class="card access-card" id="access">.*?</section>~s', render_view('student', ['id'=>$student]), $m) ? $m[0] : '';
/** The markup of the first form in $html that contains $marker, up to its closing tag. */
$formWith = function (string $html, string $marker): string {
    $at = strpos($html, $marker);
    if ($at === false) return '';
    $start = strrpos(substr($html, 0, $at), '<form');
    return substr($html, $start, strpos($html, '</form>', $at) - $start);
};
$atCard = '<input type="hidden" name="return_anchor" value="access">';
$karla = make_student(['first_name'=>'Karla', 'last_name'=>'Karte', 'email'=>'karla@beispiel.test']);
$card = $cardOf($karla);
$invite = $formWith($card, 'name="action" value="student_invite"');
ok(preg_match('~<input id="[^"]+" name="email" type="email" value="karla@beispiel\.test"[^>]*autocomplete="off"~', $invite) === 1,
   'a placeholder’s card has the address box, the record’s address in it, which the phone does not fill with her own');
ok(str_contains($invite, 'name="locale"') && str_contains($invite, $atCard), 'with the invitation’s language, and the card as where a refusal comes back to');
ok(str_contains($card, e('Karla meldet sich noch nicht an. Du trägst alles selbst ein.')), 'under the sentence that says what „Ohne Anmeldung“ means');
ok($card !== '' && !str_contains($card, e('Trag oben zuerst eine E-Mail-Adresse ein und speichere.')) && !str_contains($card, e('Die Einladung geht an ')), 'the lines the box replaces are gone');
$emil = make_student(['first_name'=>'Emil', 'last_name'=>'Leer']);
ok(preg_match('~<input id="[^"]+" name="email" type="email" value=""~', $formWith($cardOf($emil), 'name="action" value="student_invite"')) === 1,
   'with no address on the record, the box is there to type one');
make_account(['role'=>'student', 'email'=>'vergeben@beispiel.test', 'name'=>'Vera Vergeben']);
$tom = make_student(['first_name'=>'Tom', 'last_name'=>'Taken', 'email'=>'vergeben@beispiel.test']);
$card = $cardOf($tom);
ok(str_contains($card, e('Diese Adresse gehört schon zum Zugang von Vera Vergeben')) && !str_contains($card, 'value="student_invite"'),
   'an address that is another login’s is said before the tap, with no form');
$sina = make_student(['first_name'=>'Sina', 'last_name'=>'Schwester', 'email'=>'familie@beispiel.test']);
$bruno = make_student(['first_name'=>'Bruno', 'last_name'=>'Bruder', 'email'=>'familie@beispiel.test']);
$card = $cardOf($bruno);
ok(str_contains($card, e('Diese Adresse steht auch bei Sina Schwester')) && str_contains($card, e('Bruno braucht eine eigene.')) && !str_contains($card, 'value="student_invite"'),
   'and so is one a sister’s record carries too, naming her (one person, one address)');
ok(str_contains($cardOf($sina), e('Diese Adresse steht auch bei Bruno Bruder')), 'on her card the same, naming him');
throws(fn() => act('student_invite', ['student_id'=>(string)$bruno, 'email'=>'familie@beispiel.test', 'locale'=>'de', 'return_anchor'=>'access']),
       'which the action refuses too, should a form be sent all the same', 'steht schon bei Sina Schwester');
/* A refused post comes back to the card (#access) with what was typed; the
   banner at the top of the page is out of sight there, so the card says why. */
$_SESSION['flash'] = ['message'=>'Diese E-Mail-Adresse gehört schon zu einem anderen Zugang.', 'kind'=>'error'];
$GLOBALS['crm_held_input'] = ['action'=>'student_invite', 'page'=>'student', 'id'=>(string)$karla, 'tab'=>'', 'record'=>null, 'fields'=>['student_id'=>(string)$karla, 'email'=>'vergeben@beispiel.test', 'locale'=>'de']];
$card = $cardOf($karla);
ok(str_contains($card, '<div class="notice warn" role="status"><p>'.e('Diese E-Mail-Adresse gehört schon zu einem anderen Zugang.').'</p></div>')
   && preg_match('~name="email" type="email" value="vergeben@beispiel\.test"~', $card) === 1,
   'a refused invitation is said on the card it comes back to, with the address that was typed');
$GLOBALS['crm_held_input'] = null;
$card = $cardOf($karla);
ok($card !== '' && !str_contains($card, 'role="status"'), 'and only after a refusal of the card’s own form');
$_SESSION['flash'] = ['message'=>'Zugang gesperrt.', 'kind'=>'success'];
$GLOBALS['crm_held_input'] = ['action'=>'student_invite', 'page'=>'student', 'id'=>(string)$karla, 'tab'=>'', 'record'=>null, 'fields'=>['student_id'=>(string)$karla]];
$card = $cardOf($karla);
ok($card !== '' && !str_contains($card, 'Zugang gesperrt.'), 'never a success, which the banner says on its own');
$GLOBALS['crm_held_input'] = null;
unset($_SESSION['flash']);
mail_ready(false);
$card = $cardOf($karla);
ok(str_contains($card, e('Einladen geht noch nicht')) && !str_contains($card, 'value="student_invite"'), 'while mail cannot go out, the card says what is missing instead of the form');
mail_ready(true);
act('student_invite', ['student_id'=>(string)$emil, 'email'=>' Emil@Beispiel.test ', 'locale'=>'en', 'return_anchor'=>'access']);
is_same(['invited', 'emil@beispiel.test', 'en', 'emil@beispiel.test'],
        [$loginOf($emil)['state'] ?? null, $loginOf($emil)['email'] ?? null, $loginOf($emil)['locale'] ?? null, (string)scalar('SELECT email FROM students WHERE id=?', [$emil])],
        'the address typed on the card and the language chosen are the invitation’s, and the record carries the address');
$card = $cardOf($emil);
foreach (['reinvite', 'withdraw'] as $mode)
    ok(str_contains($formWith($card, 'name="mode" value="'.$mode.'"'), $atCard), 'invited: „'.$mode.'“ comes back to the card too');
$alma = make_student(['first_name'=>'Alma', 'last_name'=>'Aktiv', 'account_id'=>make_account(['role'=>'student', 'name'=>'Alma Aktiv', 'email'=>'alma@beispiel.test',
                      'state'=>'active', 'verified_at'=>now(), 'password_hash'=>password_hash($password, PASSWORD_DEFAULT)])]);
$card = $cardOf($alma);
foreach (['reset_link', 'suspend', 'delete'] as $mode)
    ok(str_contains($formWith($card, 'name="mode" value="'.$mode.'"'), $atCard), 'in use: „'.$mode.'“ comes back to the card too');
ok(str_contains($card, '<dd>alma@beispiel.test</dd>') && !str_contains($card, 'name="email"'), 'and the card reads the address rather than offering a box for it');
/* The card's other two forms come back to it too: „Anmeldung löschen" with
   the wrong address typed, and „Portal als … ansehen" refused - the login
   suspended in another tab, say. Each is said on the card. */
foreach (['account_state'=>['Zum Löschen die E-Mail-Adresse eingeben.', ['id'=>(string)$loginOf($alma)['id'], 'mode'=>'delete']],
          'impersonate'=>['Dieses Konto kannst du nicht ansehen.', ['id'=>(string)$loginOf($alma)['id'], 'mode'=>'start']]] as $action => [$message, $fields]) {
    $_SESSION['flash'] = ['message'=>$message, 'kind'=>'error'];
    $GLOBALS['crm_held_input'] = ['action'=>$action, 'page'=>'student', 'id'=>(string)$alma, 'tab'=>'', 'record'=>null, 'fields'=>$fields];
    $card = $cardOf($alma);
    ok($card !== '' && str_contains($card, '<div class="notice warn" role="status"><p>'.e($message).'</p></div>'), 'a refused '.$action.' is said on the card it comes back to');
}
$GLOBALS['crm_held_input'] = null;
unset($_SESSION['flash']);
act('account_state', ['id'=>(string)$loginOf($alma)['id'], 'mode'=>'suspend']);
ok(str_contains($formWith($cardOf($alma), 'name="mode" value="restore"'), $atCard), 'suspended: „restore“ too');
sign_out();

case_('Mein Konto: the address, one sentence, the resets of two weeks and the three switches, for everybody');
/* Spec addendum §4 (ADR 0030 §6): every login that reaches the page has an
   address, so the switches are always there and nothing travels hidden. */
test_reset();
$mona = make_account(['role'=>'student', 'name'=>'Mona Konto', 'email'=>'mona@beispiel.test']);
make_student(['first_name'=>'Mona', 'last_name'=>'Konto', 'account_id'=>$mona]);
sign_in_as($mona);
$mine = render_view('profile');
ok(substr_count($mine, '<div class="fact-wide">') === 1 && str_contains($mine, '<dd>mona@beispiel.test<small') && str_contains($mine, e('Mit dieser Adresse meldest du dich an.')),
   'one row, the address, and the one sentence');
foreach (['newsletter', 'notifications', 'payment_notices'] as $switch)
    ok(str_contains($mine, '<input type="checkbox" role="switch" name="'.$switch.'"'), 'the switch '.$switch);
ok(str_contains($mine, 'id="sign-in"') && !str_contains($mine, '<input type="hidden" name="newsletter"') && !str_contains($mine, e('sobald du oben eine E-Mail-Adresse hinzufügst')),
   'and nothing carried hidden, nor a promise for later');
ok(str_contains($mine, e('E-Mail bei neuen Nachrichten und Änderungen im Training')), 'the message switch named for what it sends');
ok(str_contains($mine, e('E-Mail-Adresse ändern')) && !str_contains($mine, e('E-Mail-Adresse hinzufügen')), 'the fold says „E-Mail-Adresse ändern“, always');
ok(str_contains($mine, 'id="sign-in"') && !str_contains($mine, 'class="notice warn"'), 'with no reset, no notice');
fixture('audit_log', ['actor_id'=>$mona, 'action'=>'account.password_reset', 'entity_type'=>'account', 'entity_id'=>$mona, 'created_at'=>$recent = gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
fixture('audit_log', ['actor_id'=>$mona, 'action'=>'account.password_reset', 'entity_type'=>'account', 'entity_id'=>$mona, 'created_at'=>$old = gmdate('Y-m-d H:i:s', time() - 20 * 86400)]);
$mine = render_view('profile');
ok(str_contains($mine, '<strong>'.e('Dein Passwort wurde per E-Mail-Link neu festgelegt').'</strong>') && str_contains($mine, e(fmt_datetime($recent)))
   && !str_contains($mine, e(fmt_datetime($old))), 'a reset of the last two weeks is listed under its heading, an older one not');
sign_out();

case_('Whose portal is being looked at is said on the public pages too, with the way out');
/* While a view lasts, the page a link opens, the sign-in and „abbestellen"
   refuse to go on until it ends (security review, finding 5). The bar with
   „Ansicht beenden" was drawn on the signed-in pages only, so on those pages
   there was no way to end it but going back to one of them. */
test_reset();
$looker = make_account(['role'=>'admin', 'name'=>'Ansicht Chefin']);
$looked = make_account(['role'=>'student', 'name'=>'Angesehen Familie']);
make_student(['first_name'=>'Angesehen', 'last_name'=>'Familie', 'account_id'=>$looked]);
view_as($looker, $looked);
$privacy = render_page('privacy');
ok(str_contains($privacy, 'class="impersonation-bar"') && str_contains($privacy, '<strong>'.e('Angesehen Familie').'</strong>') && str_contains($privacy, 'value="stop"'),
   'the privacy notice, a public page, says whose portal it is and offers „Ansicht beenden“');
unset($_SESSION['impersonator_id'], $_SESSION['impersonator_auth_version']);
$privacy = render_page('privacy');
ok(str_contains($privacy, '<main') && !str_contains($privacy, 'class="impersonation-bar"'), 'and once the view has ended, it says nothing');
sign_out();
