<?php
/**
 * One login is one student (ADR 0010).
 *
 * A login used to be able to hold several children, and there were three ways
 * in: a "link an existing account" select, an invitation that joined a child
 * to an address that already had a login, and a directly created family login
 * that adopted every child at its address. All three are gone. A student gets a
 * login of their own from their own page, a brother or sister needs an address
 * of their own, and the address a student signs in with and the address their
 * invoices go to are one address that moves in one place.
 *
 * The database holds the first half with a unique index. Everything here is
 * about the application saying no first, in a sentence, before it writes - the
 * index's "Integrity constraint violation" is not something she can act on.
 */
$admin   = make_account(['role'=>'admin',   'name'=>'Chefin']);
$trainer = make_account(['role'=>'trainer', 'name'=>'Trainerin', 'email'=>'die-trainerin@beispiel.test']);
sign_in_as($trainer);

/** Mail as a portal on its first evening has it (false) or as a set-up one does (true). */
function mail_ready(bool $on): void {
    set_setting('smtp', $on ? ['host'=>'mail.example.test','port'=>587,'from_email'=>'portal@example.test','from_name'=>'B'] : []);
    set_setting('privacy_ready', $on);
}

/**
 * Linked students whose address is not their login's, byte for byte.
 *
 * Through HEX() rather than '=': MariaDB compares text under a collation that
 * folds case, so Gruber@ and gruber@ would count as the same address and stay
 * spelled two ways.
 */
function address_drift(): int {
    return (int)scalar('SELECT COUNT(*) FROM students s JOIN accounts a ON a.id=s.account_id WHERE HEX(s.email)<>HEX(a.email)');
}

/** Invitations and other sign-in links waiting to go to one address. */
function links_queued_to(string $address): int {
    return (int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE recipient=? AND category='security' AND status='queued'", [$address]);
}

/** Every column history_field_label() names, in order, read from its source. */
function defined_labels_of_history(): array {
    $source = (string)file_get_contents(APP_ROOT.'/app/history.php');
    $start = strpos($source, 'function history_field_label');
    $body = substr($source, $start, strpos($source, 'default =>', $start) - $start);
    preg_match_all("/^\s*'([a-z_]+)'\s*=>/m", $body, $m);
    return $m[1];
}

/** Save a student the way the student page does, from what is stored, with some fields changed. */
function save_student(int $id, array $changes = []): array {
    $s = one('SELECT * FROM students WHERE id=?', [$id]);
    return act('student_save', $changes + ['id'=>(string)$id, 'revision'=>(string)$s['revision'],
        'first_name'=>$s['first_name'], 'last_name'=>$s['last_name'], 'email'=>(string)$s['email'],
        'birth_date'=>(string)($s['birth_date'] ?? ''), 'joined_on'=>(string)($s['joined_on'] ?? ''), 'ended_on'=>'',
        'status'=>$s['status'], 'tariff_id'=>'', 'price'=>'', 'price_note'=>'', 'internal_notes'=>'',
        'address'=>(string)($s['address'] ?? ''), 'phone'=>(string)($s['phone'] ?? '')]);
}

// ---------------------------------------------------------------------------
case_('The database refuses a second student on one login, whatever the application does');
$login = make_account(['role'=>'student', 'name'=>'Lena Hofer', 'email'=>'lena@beispiel.test']);
make_student(['first_name'=>'Lena', 'last_name'=>'Hofer', 'account_id'=>$login, 'email'=>'lena@beispiel.test']);
$code = '';
try { make_student(['first_name'=>'Tobias', 'last_name'=>'Hofer', 'account_id'=>$login]); }
catch (PDOException $e) { $code = (string)$e->getCode(); }
is_same('23000', $code, 'the unique index answers a second student with 23000');
is_same(1, (int)scalar('SELECT COUNT(*) FROM students WHERE account_id=?', [$login]), 'and the login still holds one');
make_student(['first_name'=>'Ohne', 'last_name'=>'Zugang']);
does_not_throw(fn() => make_student(['first_name'=>'Auch', 'last_name'=>'Ohne']), 'while any number of students have no login at all');

// ---------------------------------------------------------------------------
case_('Inviting a student makes a login of their own, at their address');
mail_ready(true);
$mia = make_student(['first_name'=>'Mia', 'last_name'=>'Gruber', 'email'=>'Gruber.Familie@Beispiel.test']);
act('student_invite', ['student_id'=>(string)$mia]);
$miaLogin = one('SELECT * FROM accounts WHERE id=?', [(int)scalar('SELECT account_id FROM students WHERE id=?', [$mia])]);
ok($miaLogin !== null, 'a login was made and the student points at it');
is_same('student', $miaLogin['role'] ?? null, 'a student’s login');
is_same('invited', $miaLogin['state'] ?? null, 'waiting for its invitation to be accepted');
is_same('gruber.familie@beispiel.test', $miaLogin['email'] ?? null, 'at the address on the student, written the one way');
is_same('Mia Gruber', $miaLogin['name'] ?? null, 'under the student’s own name');
is_same(1, links_queued_to('gruber.familie@beispiel.test'), 'with the invitation queued to it');
is_same(0, address_drift(), 'the student’s copy of the address is the login’s, byte for byte');
$logged = version_changes(history_for('students', $mia)[0]);
ok(isset($logged['account_id']), 'the student’s change log says they got a login');
is_same('gruber.familie@beispiel.test', history_value($logged['account_id']['to'], 'account_id'),
        'and names it by its address, not by a number');

case_('Everything about the new login comes from the student, and a long name is cut to fit');
/* The page offers a button, not a form. The action used to read an address, a
   name and a language that nothing sends - a way round the student's own
   address for anybody writing their own POST. And a name built from two
   100-character halves is 201 characters, where a login's name holds 160:
   MariaDB refused the insert, SQLite stored it whole. */
$longName = make_student(['first_name'=>str_repeat('Ä', 100), 'last_name'=>str_repeat('B', 100), 'email'=>'lang@beispiel.test']);
is_same(201, mb_strlen(student($longName)['first_name'].' '.student($longName)['last_name']), 'the name really is 201 characters');
act('student_invite', ['student_id'=>(string)$longName, 'email'=>'anders@beispiel.test', 'name'=>'Jemand Anderes', 'locale'=>'en']);
$longLogin = one('SELECT * FROM accounts WHERE id=?', [(int)student($longName)['account_id']]);
is_same('lang@beispiel.test', $longLogin['email'] ?? null, 'an address posted with it is not used; the student’s is');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['anders@beispiel.test']), 'and nothing was made at it');
is_same(TEXT_LINE_MAX, mb_strlen((string)($longLogin['name'] ?? '')), 'the name is cut to the 160 a login holds');
is_same(str_repeat('Ä', 100).' '.str_repeat('B', 59), $longLogin['name'] ?? null, 'from the end, by characters rather than bytes');
is_same('de', $longLogin['locale'] ?? null, 'and a posted language is not used either');

case_('A brother at the same address is refused, in words, before anything is written');
$brother = make_student(['first_name'=>'Jonas', 'last_name'=>'Gruber', 'email'=>'gruber.familie@beispiel.test']);
$revisionBefore = (int)scalar('SELECT revision FROM students WHERE id=?', [$brother]);
$accountsBefore = (int)scalar('SELECT COUNT(*) FROM accounts');
throws(fn() => act('student_invite', ['student_id'=>(string)$brother]),
       'the second student at a login’s address is refused', 'eigene E-Mail-Adresse');
throws(fn() => act('student_invite', ['student_id'=>(string)$brother]),
       'and the refusal names the address, so she knows which one', 'gruber.familie@beispiel.test');
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$brother]), 'he is not put on his sister’s login');
is_same($accountsBefore, (int)scalar('SELECT COUNT(*) FROM accounts'), 'and no second login was made for the address');
is_same($revisionBefore, (int)scalar('SELECT revision FROM students WHERE id=?', [$brother]),
        'and the brother was not touched at all');
is_same(1, links_queued_to('gruber.familie@beispiel.test'), 'nor was a second invitation sent');

case_('A staff address is refused the same way, and stays staff');
$paul = make_student(['first_name'=>'Paul', 'last_name'=>'Mayr', 'email'=>'die-trainerin@beispiel.test']);
throws(fn() => act('student_invite', ['student_id'=>(string)$paul]),
       'a trainer’s address cannot become a student’s login', 'eigene E-Mail-Adresse');
is_same('trainer', (string)scalar('SELECT role FROM accounts WHERE email=?', ['die-trainerin@beispiel.test']), 'and it is still hers');
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$paul]), 'and the student is left without one rather than half-attached');
run('UPDATE students SET email=? WHERE id=?', ['paul@beispiel.test', $paul]);   // he is given one of his own

case_('A student who already has a login is not given a second one');
throws(fn() => act('student_invite', ['student_id'=>(string)$mia]),
       'inviting her again is refused', 'schon ein eigenes Konto');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['gruber.familie@beispiel.test']), 'and no second login was made');

case_('Without mail, an invitation says what to do instead, and writes nothing');
mail_ready(false);
throws(fn() => act('student_invite', ['student_id'=>(string)$paul]),
       'the invitation is refused', 'direkt mit Passwort');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['paul@beispiel.test']), 'and no login was left waiting without a link');

case_('An administrator can make a student’s login on the spot, with a password');
throws(fn() => act('student_invite', ['student_id'=>(string)$paul, 'mode'=>'direct', 'password'=>'Federball-2026-Halle!']),
       'a trainer may not', 'Administratoren');
sign_in_as($admin);
throws(fn() => act('student_invite', ['student_id'=>(string)$paul, 'mode'=>'direct', 'password'=>'badminton123']),
       'a guessable password is refused', 'erraten');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['paul@beispiel.test']), 'and neither refusal wrote a login');
act('student_invite', ['student_id'=>(string)$paul, 'mode'=>'direct', 'password'=>'Federball-2026-Halle!']);
$paulLogin = one('SELECT * FROM accounts WHERE email=?', ['paul@beispiel.test']);
is_same((int)($paulLogin['id'] ?? 0), (int)scalar('SELECT account_id FROM students WHERE id=?', [$paul]), 'the login is his');
is_same('active', $paulLogin['state'] ?? null, 'and works at once, without mail');
ok(($paulLogin['verified_at'] ?? null) !== null, 'with nothing left to verify');
ok(password_verify('Federball-2026-Halle!', (string)($paulLogin['password_hash'] ?? '')), 'and the password that was typed');
is_same(0, (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [(int)($paulLogin['id'] ?? 0)]), 'no link was made');
is_same(0, address_drift(), 'and the student’s address is the login’s');
sign_in_as($trainer);

// ---------------------------------------------------------------------------
case_('Saving a student never changes whose login they are');
/* The old page offered "Bestehendes Konto verknüpfen" and posted account_id,
   and a page opened before the update still does. */
does_not_throw(fn() => save_student($brother, ['account_id'=>(string)$miaLogin['id']]), 'a page that still posts account_id saves');
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$brother]), 'a student without a login is not given one by a save');
does_not_throw(fn() => save_student($paul, ['account_id'=>(string)$miaLogin['id']]), 'for a student with a login too');
is_same((int)$paulLogin['id'], (int)scalar('SELECT account_id FROM students WHERE id=?', [$paul]), 'nor moved to another by one');
save_student($paul, ['account_id'=>'']);
is_same((int)$paulLogin['id'], (int)scalar('SELECT account_id FROM students WHERE id=?', [$paul]), 'nor taken off his own');
is_same(0, address_drift(), 'and every student with a login still has its address');

case_('A student without a login may change their address to anything that is not somebody’s login');
throws(fn() => save_student($brother, ['email'=>'paul@beispiel.test']), 'an address that signs somebody in is refused', 'eigene E-Mail-Adresse');
is_same('gruber.familie@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$brother]), 'and his address is as it was');
/* The address he has now is his sister's login, from before the rule. That is
   listed for her to sort out; refusing every other edit until then would help
   nobody. */
save_student($brother, ['phone'=>'+43 660 1111111']);
is_same('+43 660 1111111', (string)scalar('SELECT phone FROM students WHERE id=?', [$brother]), 'an address that already clashed still lets the rest save');
save_student($brother, ['email'=>'jonas@beispiel.test']);
is_same('jonas@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$brother]), 'and an address of his own is simply saved');
throws(fn() => act('student_save', ['first_name'=>'Neu', 'last_name'=>'Anmeldung', 'email'=>'paul@beispiel.test', 'status'=>'active',
    'joined_on'=>today(), 'birth_date'=>'', 'ended_on'=>'', 'tariff_id'=>'', 'price'=>'', 'price_note'=>'', 'internal_notes'=>'']),
    'a new student at somebody’s login is refused', 'eigene E-Mail-Adresse');
is_same(0, (int)scalar("SELECT COUNT(*) FROM students WHERE first_name='Neu'"), 'nor is a new student created at somebody’s login');

case_('An active login’s address is not changed from the student page');
$paulBefore = one('SELECT * FROM students WHERE id=?', [$paul]);
throws(fn() => save_student($paul, ['email'=>'paul-neu@beispiel.test', 'phone'=>'+43 660 2222222']),
       'a new address is refused, and says where it is changed instead', 'Mein Konto');
is_same('paul@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [(int)$paulLogin['id']]), 'the login is untouched');
is_same((string)$paulBefore['phone'], (string)scalar('SELECT phone FROM students WHERE id=?', [$paul]), 'and nothing else in the save happened either');
does_not_throw(fn() => save_student($paul, ['email'=>'PAUL@Beispiel.test', 'phone'=>'+43 660 3333333']), 'the same address in other capitals is no change');
is_same('paul@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$paul]), 'and is written the login’s way');
does_not_throw(fn() => save_student($paul, ['email'=>'']), 'nor is an empty field, which cannot mean "no address" for a login');
is_same('paul@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$paul]), 'so the address stays');
run("UPDATE accounts SET state='suspended' WHERE id=?", [(int)$paulLogin['id']]);
throws(fn() => save_student($paul, ['email'=>'paul-neu@beispiel.test']), 'a suspended login’s address is refused the same way', 'Mein Konto');
run("UPDATE accounts SET state='active' WHERE id=?", [(int)$paulLogin['id']]);
is_same(0, address_drift(), 'and the two copies still agree');

case_('An invited login is re-addressed from the student page, and the old link stops working');
mail_ready(true);
$oldHash = (string)scalar('SELECT token_hash FROM auth_tokens WHERE account_id=?', [(int)$miaLogin['id']]);
ok($oldHash !== '' && token_record($oldHash) !== null, 'the first invitation’s link works before the change');
sign_in_as($admin);
$authBefore = (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [(int)$miaLogin['id']]);
save_student($mia, ['email'=>'mia.gruber@beispiel.test']);
is_same('mia.gruber@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [(int)$miaLogin['id']]), 'the login moved');
is_same('mia.gruber@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$mia]), 'and the student’s copy with it');
is_same(null, token_record($oldHash), 'the link sent to the old address is dead');
is_same(1, links_queued_to('mia.gruber@beispiel.test'), 'a new invitation is queued to the new address');
is_same(0, links_queued_to('gruber.familie@beispiel.test'), 'and the one still waiting for the old address will not be sent');
is_same($authBefore + 1, (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [(int)$miaLogin['id']]), 'every session on it has ended');
$logged = version_changes(history_for('students', $mia)[0]);
is_same('mia.gruber@beispiel.test', $logged['email']['to'] ?? null, 'and the student’s change log shows the new address');
is_same(0, address_drift(), 'the two copies agree');

case_('A re-address that could not be delivered is refused, and nothing of the save happened');
mail_ready(false);
$miaBefore = one('SELECT * FROM students WHERE id=?', [$mia]);
throws(fn() => save_student($mia, ['email'=>'mia.anders@beispiel.test', 'phone'=>'+43 660 4444444']),
       'without mail, a new address for an invited login is refused, saying why', 'lässt sich erst eintragen');
is_same('mia.gruber@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [(int)$miaLogin['id']]), 'the login is where it was');
is_same((string)$miaBefore['phone'], (string)scalar('SELECT phone FROM students WHERE id=?', [$mia]), 'and nothing else in the save happened');
is_same((int)$miaBefore['revision'], (int)scalar('SELECT revision FROM students WHERE id=?', [$mia]), 'not a single write');
mail_ready(true);
throws(fn() => save_student($mia, ['email'=>'paul@beispiel.test']), 'nor can it move onto somebody else’s login', 'eigene E-Mail-Adresse');
is_same('mia.gruber@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$mia]), 'and stays where it was');
is_same(0, address_drift(), 'the two copies agree');
sign_in_as($trainer);

case_('A staff login on a student’s record is not re-addressed from the student page');
/* Left over from before one login per student: a student whose login is an
   administrator's, still only invited. Re-addressing it from the student page
   would send the administrator's invitation wherever the trainer typed - and
   whoever opens it signs in as the administrator. */
mail_ready(true);
$bossLogin = make_account(['role'=>'admin', 'name'=>'Zweite Chefin', 'email'=>'zweite-chefin@beispiel.test',
                           'state'=>'invited', 'verified_at'=>null]);
$bossChild = make_student(['first_name'=>'Kind', 'last_name'=>'Der Chefin', 'email'=>'zweite-chefin@beispiel.test']);
run('UPDATE students SET account_id=? WHERE id=?', [$bossLogin, $bossChild]);
$bossAuth = (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [$bossLogin]);
sign_in_as($trainer);
throws(fn() => save_student($bossChild, ['email'=>'trainerin-privat@beispiel.test']),
       'the student page refuses, and says who can change it', 'Mitarbeiterkonto');
is_same('zweite-chefin@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$bossLogin]), 'the login is where it was');
is_same(0, links_queued_to('trainerin-privat@beispiel.test'), 'and no invitation went to the address typed');
is_same($bossAuth, (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [$bossLogin]), 'nor did anything else happen to it');
throws(fn() => transactional(fn() => change_login_address($bossLogin, 'trainerin-privat@beispiel.test')),
       'the helper itself refuses it too, for whoever calls it next', 'Mitarbeiterkonto');
mail_ready(false);
throws(fn() => save_student($bossChild, ['email'=>'trainerin-privat@beispiel.test']),
       'refused before anything else is checked: without mail it does not say „set up mail first“, as if that would let it through',
       'Mitarbeiterkonto');
mail_ready(true);
run("UPDATE accounts SET state='active', verified_at=? WHERE id=?", [now(), $bossLogin]);   // she accepted, and signs in
sign_in_as($bossLogin);
does_not_throw(fn() => transactional(fn() => change_login_address($bossLogin, 'chefin-neu@beispiel.test')),
               'while the administrator moving her own login, from the link she confirmed, still can');
is_same('chefin-neu@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$bossLogin]), 'and it moved');
run('UPDATE students SET account_id=NULL WHERE id=?', [$bossChild]);
is_same(0, address_drift(), 'the two copies agree');
sign_in_as($trainer);

// ---------------------------------------------------------------------------
case_('The family confirms a new address themselves, and both copies move');
/* The email_change flow asks for the password and mails a link to the new
   address; opening it is what lands here. The link is made directly so the
   test can open it. */
$lenaStudent = (int)scalar('SELECT id FROM students WHERE account_id=?', [$login]);
run("UPDATE accounts SET state='active', verified_at=? WHERE id=?", [now(), $login]);
sign_in_as($login);
$_SESSION['activation_hash'] = hash('sha256', make_token($login, 'email', 'lena.neu@beispiel.test'));
act('activate', []);
is_same('lena.neu@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$login]), 'the login is the new address');
is_same('lena.neu@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$lenaStudent]), 'and so is the student’s');
is_same(0, (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [$login]), 'no link to anything is left');
is_same($login, (int)(current_user()['id'] ?? 0), 'and they are signed in again, as the change ends every session');
is_same(0, address_drift(), 'the two copies agree');

case_('An address taken between asking and confirming is refused in words');
make_account(['role'=>'student', 'email'=>'vergeben@beispiel.test']);
$_SESSION['activation_hash'] = hash('sha256', make_token($login, 'email', 'vergeben@beispiel.test'));
throws(fn() => act('activate', []), 'the confirmation is refused rather than failing on the index', 'eigene E-Mail-Adresse');
is_same('lena.neu@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$login]), 'and the login is where it was');
sign_in_as($trainer);

case_('Changing a login’s address ends its sessions, kills its links and stops its mail');
$waiting = fixture('mail_jobs', ['account_id'=>$login, 'recipient'=>'lena.neu@beispiel.test', 'subject'=>'Offener Beitrag',
    'payload'=>seal('Hallo'), 'category'=>'payments', 'status'=>'queued', 'attempts'=>0, 'created_at'=>now()]);
make_token($login, 'reset');
sign_in_as($login);
transactional(fn() => change_login_address($login, 'Lena.Hofer@Beispiel.test'));
is_same('lena.hofer@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$login]), 'written the one way');
is_same(null, current_user(true), 'the session that was open is over');
is_same(0, (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [$login]), 'a reset link sent to the old address no longer works');
is_same('cancelled', (string)scalar('SELECT status FROM mail_jobs WHERE id=?', [$waiting]), 'and mail waiting for the old address is not sent');
throws(fn() => transactional(fn() => change_login_address($login, 'paul@beispiel.test')),
       'another account’s address is refused by the helper itself', 'eigene E-Mail-Adresse');
is_same(0, address_drift(), 'the two copies agree');
sign_in_as($trainer);

// ---------------------------------------------------------------------------
case_('A student’s login is managed from the student’s page, and returns there');
sign_in_as($admin);
is_same(['student', ['id'=>$paul]], act('account_state', ['id'=>(string)$paulLogin['id'], 'mode'=>'suspend']),
        'suspending lands on the student it belongs to');
is_same(['student', ['id'=>$paul]], act('account_state', ['id'=>(string)$paulLogin['id'], 'mode'=>'restore']),
        'and so does restoring');
is_same(['student', ['id'=>$mia]], act('account_state', ['id'=>(string)$miaLogin['id'], 'mode'=>'reinvite']),
        'and resending the invitation');
is_same(['student', ['id'=>$paul]], act('account_state', ['id'=>(string)$paulLogin['id'], 'mode'=>'delete', 'confirmation'=>'paul@beispiel.test']),
        'deleting it still finds the student, because it looked before the link was cut');
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$paul]), 'he is kept, without a login');
is_same(['accounts', []], act('account_state', ['id'=>(string)$trainer, 'mode'=>'suspend']), 'a staff login returns to the Konten page');
act('account_state', ['id'=>(string)$trainer, 'mode'=>'restore']);
sign_in_as($trainer);

// ---------------------------------------------------------------------------
case_('The students who need an address of their own before they can be invited');
$shareA = make_student(['first_name'=>'Anna', 'last_name'=>'Teilt', 'email'=>'geteilt@beispiel.test']);
$shareB = make_student(['first_name'=>'Bernd', 'last_name'=>'Teilt', 'email'=>'geteilt@beispiel.test']);
$sibling = make_student(['first_name'=>'Clara', 'last_name'=>'Hofer', 'email'=>'lena.hofer@beispiel.test']);
$ended = make_student(['first_name'=>'Dora', 'last_name'=>'Hofer', 'email'=>'lena.hofer@beispiel.test', 'status'=>'ended']);
$coachChild = make_student(['first_name'=>'Gustav', 'last_name'=>'Trainerkind', 'email'=>'die-trainerin@beispiel.test']);
$needing = array_column(students_needing_own_address(), null, 'id');
/* The login branch on its own: a trainer's address is nobody else's record, so
   only "is it somebody's login" can find it - a student's login is always also
   on that student, and would be found as shared either way. */
is_same('login', $needing[$coachChild]['reason'] ?? null, 'a student at a staff member’s login address');
is_same('login', $needing[$sibling]['reason'] ?? null, 'a sister holding her brother’s login address, as the update leaves her');
is_same('shared', $needing[$shareA]['reason'] ?? null, 'two students with one address and no login');
ok(isset($needing[$shareB]), 'both of them');
ok(!isset($needing[$ended]), 'not a membership that has ended');
$alone = make_student(['first_name'=>'Emil', 'last_name'=>'Allein', 'email'=>'frueher-geteilt@beispiel.test']);
make_student(['first_name'=>'Frieda', 'last_name'=>'Allein', 'email'=>'frueher-geteilt@beispiel.test', 'status'=>'ended']);
ok(!isset(array_column(students_needing_own_address(), null, 'id')[$alone]),
   'nor a current member whose address is shared only with one who has left: inviting them would work');
ok(!isset($needing[$mia]) && !isset($needing[$lenaStudent]), 'not a student who has a login');
ok(!isset($needing[$brother]), 'not a student with an address of their own');
$steps = array_column(student_next_steps($sibling), 'what');
ok(in_array('Eigene E-Mail-Adresse eintragen', $steps, true), 'her page asks for an address of her own');
ok(!in_array('Zugang einladen', $steps, true), 'instead of offering an invitation that could only be refused');
ok(in_array('Zugang einladen', array_column(student_next_steps($brother), 'what'), true), 'while a student with their own address is offered one');
save_student($sibling, ['email'=>'clara@beispiel.test']);
ok(!isset(array_column(students_needing_own_address(), null, 'id')[$sibling]), 'and once she has one, she is off the list');
is_same($login, (int)(account_with_address(' Lena.Hofer@beispiel.test ')['id'] ?? 0), 'a page can ask who signs in with an address');
is_same(null, account_with_address('niemand@beispiel.test'), 'and hear that nobody does');
foreach (["\tLena.HOFER@Beispiel.test\n", ' LENA.hofer@beispiel.TEST '] as $spelling) {
    is_same(email_value($spelling), email_normalised($spelling), 'an address is checked and looked up by one rule: '.json_encode($spelling));
    is_same($login, (int)(account_with_address($spelling)['id'] ?? 0), 'and found however it was typed: '.json_encode($spelling));
}

// ---------------------------------------------------------------------------
case_('A mail greets the student it belongs to, and staff by their own name');
run('UPDATE accounts SET name=? WHERE id=?', ['Familie Hofer', $login]);
is_same('Lena', greeting_name(one('SELECT * FROM accounts WHERE id=?', [$login])),
        'a student’s login is greeted with the student’s name, whatever the login is called');
run("UPDATE students SET first_name='Magdalena' WHERE id=?", [$lenaStudent]);
is_same('Magdalena', greeting_name(one('SELECT * FROM accounts WHERE id=?', [$login])), 'and follows the student record when it changes');
is_same('Trainerin', greeting_name(one('SELECT * FROM accounts WHERE id=?', [$trainer])), 'staff are greeted by the account’s name');
$orphanLogin = make_account(['role'=>'student', 'name'=>'Niemandes Zugang']);
is_same('Niemandes Zugang', greeting_name(one('SELECT * FROM accounts WHERE id=?', [$orphanLogin])), 'as is a login no student points to');
mail_ready(true);
send_account_token(one('SELECT * FROM accounts WHERE id=?', [$login]), 'reset');
$mail = (string)scalar("SELECT payload FROM mail_jobs WHERE account_id=? AND category='security' ORDER BY id DESC LIMIT 1", [$login]);
ok(str_contains(unseal($mail), 'Hallo Magdalena,'), 'and the mail that goes out says so');
is_same([], array_values(array_filter(glob(APP_ROOT.'/app/*.php'),
    fn($f) => preg_match("/'Hallo '\\)\\.\\\$\\w+\\['name'\\]/", (string)file_get_contents($f)) === 1)),
    'no mail greets with an account’s name directly any more');

// ---------------------------------------------------------------------------
case_('A message to several students is one conversation each, with their own details');
sign_in_as($admin);
$first = make_student(['first_name'=>'Erste', 'last_name'=>'Schülerin', 'account_id'=>make_account(['role'=>'student', 'name'=>'Erste'])]);
$second = make_student(['first_name'=>'Zweiter', 'last_name'=>'Schüler', 'account_id'=>make_account(['role'=>'student', 'name'=>'Zweiter'])]);
$threadsBefore = (int)scalar('SELECT COUNT(*) FROM threads');
act('bulk_preview', ['student_ids'=>[(string)$first, (string)$second], 'subject'=>'Hallo {{first_name}}', 'body'=>'Liebe/r {{student_name}}']);
act('bulk_send', []);
is_same($threadsBefore + 2, (int)scalar('SELECT COUNT(*) FROM threads'), 'two students, two conversations');
$bodies = array_column(rows('SELECT m.body FROM messages m JOIN threads t ON t.id=m.thread_id ORDER BY m.id DESC LIMIT 2'), 'body');
ok(in_array('Liebe/r Erste Schülerin', $bodies, true) && in_array('Liebe/r Zweiter Schüler', $bodies, true),
   'each with their own name, and nobody else’s details appended');
sign_in_as($trainer);

// ---------------------------------------------------------------------------
case_('The change log names a login by its address, or says it is gone');
is_same('Konto (Zugang)', history_field_label('account_id'), 'the field is called what it is');
is_same('mia.gruber@beispiel.test', history_value((int)$miaLogin['id'], 'account_id'),
        'a login that exists reads as its address');
is_same('gelöschter Zugang', history_value((int)$paulLogin['id'], 'account_id'), 'one that was deleted says so');
is_same('—', history_value(null, 'account_id'), 'and none at all is a dash, like every other empty value');
is_same((string)$paulLogin['id'], history_value((int)$paulLogin['id']), 'without the column, a number stays a number');
$labels = defined_labels_of_history();
is_same(array_values(array_unique($labels)), $labels, 'no field is labelled twice, where only the first label could ever be used');


// ---------------------------------------------------------------------------
case_('The example data is one student per login, and nobody needs an address of their own');
test_reset();
sign_in_as(make_account(['role'=>'admin']));
demo_fill();
foreach (['familie.hofer@beispiel.test' => 'Lena Hofer', 'familie.berger@beispiel.test' => 'Jonas Berger'] as $email => $name) {
    $row = one('SELECT * FROM accounts WHERE email=?', [$email]);
    is_same($name, $row['name'] ?? null, $email.' is named after its student');
    is_same(1, (int)scalar('SELECT COUNT(*) FROM students WHERE account_id=?', [(int)($row['id'] ?? 0)]), 'and holds exactly one');
}
is_same(0, address_drift(), 'each of those students has their login’s address');
is_same([], students_needing_own_address(), 'and no example student would be refused an invitation');
