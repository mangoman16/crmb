<?php
/**
 * One login is one student (ADR 0010), with a username (ADR 0019) and an
 * address of its own (ADR 0020).
 *
 * A login used to be able to hold several children, and there were three ways
 * in: a "link an existing account" select, an invitation that joined a child
 * to an address that already had a login, and a directly created family login
 * that adopted every child at its address. All three are gone. A student gets a
 * login of their own from their own page, by invitation, with a username of its
 * own; a brother or sister needs an address of their own, and a parent's goes
 * on the contacts; and the address a student signs in with and the address
 * their invoices go to are one address that moves in one place.
 *
 * The database holds the first half with a unique index. Everything here is
 * about the application saying no first, in a sentence, before it writes - the
 * index's "Integrity constraint violation" is not something she can act on.
 */
$admin   = make_account(['role'=>'admin',   'name'=>'Chefin']);
$trainer = make_account(['role'=>'trainer', 'name'=>'Trainerin', 'email'=>'die-trainerin@beispiel.test']);
sign_in_as($trainer);

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
is_same('mia.gruber', $miaLogin['username'] ?? null, 'with a username made from the student’s name');
is_same(0, address_drift(), 'the student’s copy of the address is the login’s, byte for byte');
$logged = version_changes(history_for('students', $mia)[0]);
ok(isset($logged['account_id']), 'the student’s change log says they got a login');
is_same('mia.gruber', history_value($logged['account_id']['to'], 'account_id'),
        'and names it by its username, not by a number - nor by an address a brother may share');
$invitation = unseal((string)scalar("SELECT payload FROM mail_jobs WHERE account_id=? AND category='security'", [(int)$miaLogin['id']]));
ok(str_contains($invitation, 'Dein Benutzername: mia.gruber') && str_contains($invitation, 'beim Einrichten ändern'),
   'the invitation says what the login is called and that it can be changed while setting up');

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
/* ADR 0020, §1: one person, one login, one address of their own. The sentence
   is refuse_address_in_use()'s; staff are told whose login it is. */
$brother = make_student(['first_name'=>'Jonas', 'last_name'=>'Gruber', 'email'=>'gruber.familie@beispiel.test']);
$revisionBefore = (int)scalar('SELECT revision FROM students WHERE id=?', [$brother]);
$accountsBefore = (int)scalar('SELECT COUNT(*) FROM accounts');
throws(fn() => act('student_invite', ['student_id'=>(string)$brother]),
       'the second student at a login’s address is refused', 'Jede Person braucht ihre eigene');
throws(fn() => act('student_invite', ['student_id'=>(string)$brother, 'same_family'=>'1']),
       'and the refusal names whose login it is, so she knows; a „same family“ tick from an old page changes nothing', 'Es ist der Zugang von Mia Gruber.');
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$brother]), 'he is not put on his sister’s login');
is_same($accountsBefore, (int)scalar('SELECT COUNT(*) FROM accounts'), 'and no second login was made for the address');
is_same($revisionBefore, (int)scalar('SELECT revision FROM students WHERE id=?', [$brother]),
        'and the brother was not touched at all');
is_same(1, links_queued_to('gruber.familie@beispiel.test'), 'nor was a second invitation sent');
ok(in_array('Eigene E-Mail-Adresse eintragen', array_column(student_next_steps($brother), 'what'), true)
   && !in_array('Zugang einladen', array_column(student_next_steps($brother), 'what'), true),
   'and his page asks for an address of his own instead of offering an invitation that would be refused');

case_('A staff address is refused the same way, and stays staff');
$paul = make_student(['first_name'=>'Paul', 'last_name'=>'Mayr', 'email'=>'die-trainerin@beispiel.test']);
throws(fn() => act('student_invite', ['student_id'=>(string)$paul]),
       'a trainer’s address cannot become a student’s login', 'Es ist der Zugang von Trainerin.');
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
       'the invitation is refused, and says what is missing', 'Eine Einladung lässt sich noch nicht verschicken. E-Mail-Versand zuerst testen');
throws(fn() => act('student_invite', ['student_id'=>(string)$paul]), 'without offering a password instead (ADR 0020, §5)', 'freigeben.');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['paul@beispiel.test']), 'and no login was left waiting without a link');

case_('Mail that is saved but never tested is not mail that works');
/* The invite button and the checklist's invitations step ask one rule. An
   invitation queued behind a server that never answered would sit in the
   outbox while the family waits, so the refusal says to test first, and where. */
mail_ready(true);
set_setting('smtp_last_test', []);
is_same(false, account_mail_ready(), 'a saved server with no passing test is not ready');
throws(fn() => act('student_invite', ['student_id'=>(string)$paul]),
       'the invitation is refused', 'E-Mail-Versand zuerst testen');
ok(str_contains(account_mail_missing(), '„Einstellungen → SMTP“') && !str_contains(account_mail_missing(), 'Datenschutz'),
   'the sentence points at the SMTP tab, and only at what is actually missing');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['paul@beispiel.test']), 'and nothing was written');
set_setting('smtp_last_test', ['ok'=>false, 'summary'=>'', 'transcript'=>'', 'sent_to'=>'', 'at'=>now()]);
is_same(false, account_mail_ready(), 'nor is one whose last test failed');
setup_cache_clear();
is_same(true, array_column(setup_steps(), 'blocked', 'key')['invite'], 'and the checklist holds invitations up for the same reason');
mail_ready(false);

case_('A page that still posts mode=direct and a password gets an ordinary invitation, never an active login');
/* ADR 0020, §5: nobody sets another person's password. The access card used to
   offer administrators a password box; a page opened before the update may
   still post it. */
sign_in_as($admin);
mail_ready(true);
act('student_invite', ['student_id'=>(string)$paul, 'mode'=>'direct', 'password'=>'Federball-2026-Halle!']);
$paulLogin = one('SELECT * FROM accounts WHERE email=?', ['paul@beispiel.test']);
is_same((int)($paulLogin['id'] ?? 0), (int)scalar('SELECT account_id FROM students WHERE id=?', [$paul]), 'the login is his');
is_same(['invited', null, null], [$paulLogin['state'] ?? null, $paulLogin['verified_at'] ?? null, $paulLogin['password_hash'] ?? null],
        'invited, with no password and nothing verified');
is_same(1, links_queued_to('paul@beispiel.test'), 'and the invitation is on its way, for him to choose his own');
is_same(0, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.created_directly'"), 'nothing is written down as made directly');
is_same(0, address_drift(), 'and the student’s address is the login’s');
// He accepts it, as the rest of this suite needs a login in use.
run("UPDATE accounts SET state='active', verified_at=?, password_hash=? WHERE id=?",
    [now(), password_hash('Federball-2026-Halle!', PASSWORD_DEFAULT), (int)$paulLogin['id']]);
$paulLogin = one('SELECT * FROM accounts WHERE id=?', [(int)$paulLogin['id']]);
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
throws(fn() => save_student($brother, ['email'=>'paul@beispiel.test']), 'an address that signs somebody in is refused', 'Jede Person braucht ihre eigene');
is_same('gruber.familie@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$brother]), 'and his address is as it was');
/* The address he has now is his sister's login, from before the rule. His page
   asks for one of his own; refusing every other edit until then would help
   nobody. */
save_student($brother, ['phone'=>'+43 660 1111111']);
is_same('+43 660 1111111', (string)scalar('SELECT phone FROM students WHERE id=?', [$brother]), 'an address that already clashed still lets the rest save');
save_student($brother, ['email'=>'jonas@beispiel.test']);
is_same('jonas@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$brother]), 'and an address of his own is simply saved');
$twin = make_student(['first_name'=>'Zwilling', 'last_name'=>'Gruber', 'email'=>'jonas@beispiel.test']);
does_not_throw(fn() => save_student($twin, ['phone'=>'1']), 'two students without a login may carry one address: nothing signs in with it yet');
throws(fn() => act('student_save', ['first_name'=>'Neu', 'last_name'=>'Anmeldung', 'email'=>'paul@beispiel.test', 'status'=>'active',
    'joined_on'=>today(), 'birth_date'=>'', 'ended_on'=>'', 'tariff_id'=>'', 'price'=>'', 'price_note'=>'', 'internal_notes'=>'']),
    'a new student at somebody’s login is refused', 'Jede Person braucht ihre eigene');
is_same(0, (int)scalar("SELECT COUNT(*) FROM students WHERE first_name='Neu'"), 'nor is a new student created at somebody’s login');
throws(fn() => save_student($brother, ['email'=>'"jonas gruber"@beispiel.test']),
       'and an address that is deliverable but not plain is refused for writing (M1)', 'Ungültige E-Mail-Adresse');

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
$miaRevision = (int)scalar('SELECT revision FROM students WHERE id=?', [$mia]);
throws(fn() => save_student($mia, ['email'=>'paul@beispiel.test', 'phone'=>'+43 660 5555555']),
       'nor can it move onto somebody else’s login, which is named to staff', 'Es ist der Zugang von Paul Mayr.');
is_same('mia.gruber@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$mia]), 'and stays where it was');
is_same($miaRevision, (int)scalar('SELECT revision FROM students WHERE id=?', [$mia]), 'with nothing else of the save written');
throws(fn() => save_student($mia, ['email'=>'die-trainerin@beispiel.test']),
       'onto a staff login’s address the same', 'Jede Person braucht ihre eigene');
is_same('mia.gruber@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [(int)$miaLogin['id']]), 'and the login did not move either');
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
throws(fn() => transactional(fn() => change_account_email($bossLogin, 'trainerin-privat@beispiel.test')),
       'the helper itself refuses it too, for whoever calls it next', 'Mitarbeiterkonto');
mail_ready(false);
throws(fn() => save_student($bossChild, ['email'=>'trainerin-privat@beispiel.test']),
       'refused before anything else is checked: without mail it does not say „set up mail first“, as if that would let it through',
       'Mitarbeiterkonto');
mail_ready(true);
run("UPDATE accounts SET state='active', verified_at=? WHERE id=?", [now(), $bossLogin]);   // she accepted, and signs in
sign_in_as($bossLogin);
does_not_throw(fn() => transactional(fn() => change_account_email($bossLogin, 'chefin-neu@beispiel.test')),
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
/* email_change no longer asks when the change is requested (ADR 0020, §1), so
   this is where the clash is found: by whoever opens the link, who reads that
   mailbox. The sentence, not the unique index's 23000. */
make_account(['role'=>'student', 'email'=>'vergeben@beispiel.test']);
$_SESSION['activation_hash'] = hash('sha256', make_token($login, 'email', 'vergeben@beispiel.test'));
throws(fn() => act('activate', []), 'the confirmation is refused rather than failing on the index', 'Jede Person braucht ihre eigene');
is_same('lena.neu@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$login]), 'and the login is where it was');
sign_in_as($trainer);

case_('Changing a login’s address ends its sessions, kills its links and stops its mail');
$waiting = fixture('mail_jobs', ['account_id'=>$login, 'recipient'=>'lena.neu@beispiel.test', 'subject'=>'Offener Beitrag',
    'payload'=>seal('Hallo'), 'category'=>'payments', 'status'=>'queued', 'attempts'=>0, 'created_at'=>now()]);
make_token($login, 'reset');
sign_in_as($login);
transactional(fn() => change_account_email($login, 'Lena.Hofer@Beispiel.test'));
is_same('lena.hofer@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$login]), 'written the one way');
is_same(null, current_user(true), 'the session that was open is over');
is_same(0, (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [$login]), 'a reset link sent to the old address no longer works');
is_same('cancelled', (string)scalar('SELECT status FROM mail_jobs WHERE id=?', [$waiting]), 'and mail waiting for the old address is not sent');
throws(fn() => transactional(fn() => change_account_email($login, 'paul@beispiel.test')),
       'another login’s address is refused by the helper itself', 'Jede Person braucht ihre eigene');
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
throws(fn() => act('account_state', ['id'=>(string)$paulLogin['id'], 'mode'=>'delete', 'confirmation'=>'paul@beispiel.test']),
       'deleting is confirmed by the username, not the address (ADR 0019, §9, kept by 0020)', 'Benutzernamen');
is_same(['student', ['id'=>$paul]], act('account_state', ['id'=>(string)$paulLogin['id'], 'mode'=>'delete', 'confirmation'=>'Paul.Mayr']),
        'but by the username, however it was capitalised - and it still finds the student, because it looked before the link was cut');
is_same(null, scalar('SELECT account_id FROM students WHERE id=?', [$paul]), 'he is kept, without a login');
is_same(['accounts', []], act('account_state', ['id'=>(string)$trainer, 'mode'=>'suspend']), 'a staff login returns to the Konten page');
act('account_state', ['id'=>(string)$trainer, 'mode'=>'restore']);
sign_in_as($trainer);

// ---------------------------------------------------------------------------
case_('A page can ask, before she taps, whether an address is somebody’s login');
$sibling = make_student(['first_name'=>'Clara', 'last_name'=>'Hofer', 'email'=>'lena.hofer@beispiel.test']);
$steps = array_column(student_next_steps($sibling), 'what');
ok(in_array('Eigene E-Mail-Adresse eintragen', $steps, true), 'a sister at her brother’s login address is asked for one of her own');
ok(!in_array('Zugang einladen', $steps, true), 'instead of being offered an invitation that could only be refused');
save_student($sibling, ['email'=>'clara@beispiel.test']);
ok(in_array('Zugang einladen', array_column(student_next_steps($sibling), 'what'), true), 'and once she has one, she is offered it');
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
case_('The change log names a login by its username, or says it is gone');
is_same('Konto (Zugang)', history_field_label('account_id'), 'the field is called what it is');
is_same('Benutzername', history_field_label('username'), 'and so is a changed username');
is_same('mia.gruber', history_value((int)$miaLogin['id'], 'account_id'),
        'a login that exists reads as its username: an address may be a whole family’s');
is_same('gelöschter Zugang', history_value((int)$paulLogin['id'], 'account_id'), 'one that was deleted says so');
is_same('—', history_value(null, 'account_id'), 'and none at all is a dash, like every other empty value');
is_same((string)$paulLogin['id'], history_value((int)$paulLogin['id']), 'without the column, a number stays a number');
$labels = defined_labels_of_history();
is_same(array_values(array_unique($labels)), $labels, 'no field is labelled twice, where only the first label could ever be used');


// ---------------------------------------------------------------------------
case_('The example data is one student per login, each with a username');
test_reset();
sign_in_as(make_account(['role'=>'admin']));
$filled = demo_fill();
foreach (['lena.hofer@beispiel.test' => ['Lena Hofer', 'lena.hofer'], 'jonas.berger@beispiel.test' => ['Jonas Berger', 'jonas.berger']] as $email => [$name, $username]) {
    $row = one('SELECT * FROM accounts WHERE email=?', [$email]);
    is_same($name, $row['name'] ?? null, $email.' is named after its student');
    is_same($username, $row['username'] ?? null, 'and signs in as '.$username);
    is_same(1, (int)scalar('SELECT COUNT(*) FROM students WHERE account_id=?', [(int)($row['id'] ?? 0)]), 'and holds exactly one');
}
is_same([['trainerin.beispiel', 'trainer'], ['lena.hofer', 'student'], ['jonas.berger', 'student']],
        array_map(fn($l) => [$l['username'], $l['role']], $filled['logins']),
        'the fill hands back the usernames it made, staff first, for setup and the console to show');
is_same(0, address_drift(), 'each of those students has their login’s address');
is_same([], array_values(array_filter(array_column(rows('SELECT email FROM students WHERE is_demo=1'), 'email'), fn($e) => str_starts_with((string)$e, 'eltern.'))),
        'and every example student has an address of their own, none a parent’s (ADR 0020)');
is_same((int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=1'), count(array_unique(array_column(rows('SELECT email FROM students WHERE is_demo=1'), 'email'))),
        'no two alike');

case_('A family opening the students list is sent to their own student instead');
/* An old bookmark to ?page=students. The list is the trainer's; a family has
   one student (ADR 0010). The page's classification does not change - the
   structure suite pins that - only where a family lands. */
$ownLogin = make_account(['role'=>'student']);
$own = make_student(['first_name'=>'Eigene', 'last_name'=>'Seite', 'account_id'=>$ownLogin]);
is_same(['student', ['id'=>$own]], students_list_instead(one('SELECT * FROM accounts WHERE id=?', [$ownLogin])),
        'a family goes to their own student’s page');
$lonely = make_account(['role'=>'student']);
is_same(['dashboard', []], students_list_instead(one('SELECT * FROM accounts WHERE id=?', [$lonely])),
        'a family login no student points to goes to the overview');
// Accounts of this case's own: the suite's trainer and administrator may be gone by now.
is_same(null, students_list_instead(one('SELECT * FROM accounts WHERE id=?', [make_account(['role'=>'trainer'])])), 'a trainer stays on the list');
is_same(null, students_list_instead(one('SELECT * FROM accounts WHERE id=?', [make_account(['role'=>'admin'])])), 'and so does an administrator');
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
$redirect = strpos($router, "if(\$page==='students' && (\$instead=students_list_instead(\$user)))go(\$instead[0],\$instead[1]);");
ok($redirect !== false && $redirect > (int)strpos($router, '$user=$public?') && $redirect < (int)strpos($router, "require ROOT.'/views/'"),
   'the router redirects there once it knows who is asking, before any page is drawn');

case_('Accepting an invitation with club news unticked stores the no, and a record of it (ADR 0018)');
/* The box arrives ticked and the schema starts a login with news on, so the
   only thing standing between an untick and a news mail is the activation
   writing what was posted. Two logins, one unticked and one left ticked, both
   starting at 1 - so a write of a fixed value fails one of them - and a third
   that is not activated, to show the write touched only the one it was for. */
set_setting('privacy_ready', true);
is_same(1, (int)scalar('SELECT newsletter FROM accounts WHERE id=?', [make_account(['email' => 'standard@beispiel.test'])]),
        'a login written without naming it has news by email on: the schema\'s default since 021');
$activate = function (string $email, array $fields): int {
    $id = make_account(['email' => $email, 'state' => 'invited', 'verified_at' => null, 'password_hash' => null, 'newsletter' => 1]);
    sign_out();
    $_SESSION['activation_hash'] = hash('sha256', make_token($id, 'invite'));
    submit('activate', ['password' => 'Federball-2026-Halle!', 'password_confirm' => 'Federball-2026-Halle!',
                        'privacy_seen' => '1', 'notifications' => '1'] + $fields);
    return $id;
};
$bystander = make_account(['email' => 'unbeteiligt@beispiel.test', 'newsletter' => 1]);
$consents = fn(int $id) => array_map(fn($r) => [(string)$r['purpose'], (int)$r['enabled']],
    rows("SELECT purpose, enabled FROM consent_log WHERE account_id=? AND purpose='newsletter' ORDER BY id", [$id]));
$declined = $activate('ohne-neuigkeiten@beispiel.test', []);
is_same('active', (string)scalar('SELECT state FROM accounts WHERE id=?', [$declined]), 'the invitation was accepted');
is_same(0, (int)scalar('SELECT newsletter FROM accounts WHERE id=?', [$declined]), 'news by email is off for the login that unticked it');
is_same([['newsletter', 0]], $consents($declined), 'and one line records that they said no');
// Asked straight away: the ticked activation below writes 1, which would hide
// an untick that had been written to every login.
is_same(1, (int)scalar('SELECT newsletter FROM accounts WHERE id=?', [$bystander]), 'a login that was not activated keeps its own');
$accepted = $activate('mit-neuigkeiten@beispiel.test', ['newsletter' => '1']);
is_same(1, (int)scalar('SELECT newsletter FROM accounts WHERE id=?', [$accepted]), 'left ticked, it stays on');
is_same([['newsletter', 1]], $consents($accepted), 'and that is recorded too');
is_same(0, (int)scalar('SELECT newsletter FROM accounts WHERE id=?', [$declined]), 'and the one that said no is still off');
is_same([], $consents($bystander), 'the login that was not activated gets no record');
sign_out();

// ---------------------------------------------------------------------------
case_('Creating a student with „Gleich einladen“ makes the student, the login and the invitation in one step');
/* ADR 0020, §6. Every refusal comes before the first write, so a refused
   invitation leaves no student behind either. */
test_reset();
$boss = make_account(['role'=>'admin', 'name'=>'Chefin']);
$coach = make_account(['role'=>'trainer', 'name'=>'Trainerin', 'email'=>'coach@beispiel.test']);
sign_in_as($coach);
$create = fn(array $over = []) => act('student_save', $over + ['first_name'=>'Lea', 'last_name'=>'Neumann', 'email'=>'lea.neumann@beispiel.test',
    'status'=>'active', 'joined_on'=>today(), 'birth_date'=>'', 'ended_on'=>'', 'internal_notes'=>'', 'invite'=>'1']);
$counts = fn() => [(int)scalar('SELECT COUNT(*) FROM students'), (int)scalar('SELECT COUNT(*) FROM accounts'),
                   (int)scalar('SELECT COUNT(*) FROM auth_tokens'), (int)scalar('SELECT COUNT(*) FROM mail_jobs')];
$nothing = $counts();
mail_ready(false);
throws(fn() => $create(), 'without mail it is refused, saying what is missing', 'E-Mail-Versand zuerst testen');
throws(fn() => $create(), 'and that without the tick the student is saved now and invited later', 'Ohne „Gleich einladen“ wird jetzt gespeichert und später eingeladen.');
is_same($nothing, $counts(), 'and nothing at all was created');
mail_ready(true);
throws(fn() => $create(['email'=>'']), 'an empty address is refused', 'eigene E-Mail-Adresse');
throws(fn() => $create(['email'=>'lea@']), 'an invalid one is refused', 'Ungültige E-Mail-Adresse');
throws(fn() => $create(['email'=>'Coach@Beispiel.test']), 'and one that is somebody’s login is refused', 'Jede Person braucht ihre eigene');
is_same($nothing, $counts(), 'none of the three created anything');
$landed = $create();
$lea = (int)scalar("SELECT id FROM students WHERE first_name='Lea'");
is_same(['student', ['id'=>$lea]], $landed, 'with mail and an address of her own, the student is created, and her page opens');
$leaLogin = one('SELECT * FROM accounts WHERE id=?', [(int)scalar('SELECT account_id FROM students WHERE id=?', [$lea])]);
is_same(['lea.neumann', 'invited', 'lea.neumann@beispiel.test'], [$leaLogin['username'] ?? null, $leaLogin['state'] ?? null, $leaLogin['email'] ?? null],
        'with an invited login of her own');
is_same(1, (int)scalar("SELECT COUNT(*) FROM auth_tokens WHERE account_id=? AND purpose='invite'", [(int)$leaLogin['id']]), 'a token');
is_same(1, links_queued_to('lea.neumann@beispiel.test'), 'and the invitation queued');
ok(str_contains((string)($_SESSION['flash']['message'] ?? ''), 'Benutzername: lea.neumann'), 'the flash names the username');
is_same(['insert', 'update'], array_reverse(array_column(history_for('students', $lea), 'operation')), 'the change log has her creation and her login');
act('student_save', ['first_name'=>'Ohne', 'last_name'=>'Haken', 'email'=>'ohne.haken@beispiel.test', 'status'=>'active',
    'joined_on'=>today(), 'birth_date'=>'', 'ended_on'=>'', 'internal_notes'=>'']);
is_same(null, scalar("SELECT account_id FROM students WHERE first_name='Ohne'"), 'without the tick, only the student is made');
is_same(0, links_queued_to('ohne.haken@beispiel.test'), 'and nobody is mailed');

case_('Staff can have a link for a new password mailed, and never see it');
/* ADR 0020, §5: an active login only, never one's own, a trainer for students'
   logins only. The link goes to the login's own address; the outbox never shows
   a security mail's body. */
$active = make_account(['role'=>'student', 'name'=>'Aktiv', 'email'=>'aktiv@beispiel.test', 'username'=>'aktiv.kind']);
$activeKid = make_student(['first_name'=>'Aktiv', 'last_name'=>'Kind', 'email'=>'aktiv@beispiel.test', 'account_id'=>$active]);
$otherCoach = make_account(['role'=>'trainer', 'name'=>'Zweite', 'email'=>'zweite@beispiel.test']);
run('DELETE FROM mail_jobs'); run('DELETE FROM auth_tokens');
$hashBefore = (string)scalar('SELECT password_hash FROM accounts WHERE id=?', [$active]);
is_same(['student', ['id'=>$activeKid]], act('account_state', ['id'=>(string)$active, 'mode'=>'reset_link']), 'a trainer asks for a student’s link, and lands on the student');
is_same(['aktiv@beispiel.test'], array_column(rows("SELECT recipient FROM mail_jobs WHERE category='security'"), 'recipient'), 'mailed to the login’s own address');
is_same('reset', (string)scalar('SELECT purpose FROM auth_tokens WHERE account_id=?', [$active]), 'as a reset link');
is_same(1, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.reset_link' AND entity_id=? AND actor_id=?", [$active, $coach]),
        'and written down as hers');
is_same($hashBefore, (string)scalar('SELECT password_hash FROM accounts WHERE id=?', [$active]), 'the password itself is not touched');
ok(!str_contains((string)($_SESSION['flash']['message'] ?? ''), 'token='), 'and the flash shows no link');
throws(fn() => act('account_state', ['id'=>(string)$otherCoach, 'mode'=>'reset_link']), 'a trainer may not for a staff login', 'kann hier nicht geändert werden');
throws(fn() => act('account_state', ['id'=>(string)$coach, 'mode'=>'reset_link']), 'nobody for their own', 'kann hier nicht geändert werden');
sign_in_as($boss);
does_not_throw(fn() => act('account_state', ['id'=>(string)$otherCoach, 'mode'=>'reset_link']), 'an administrator may for a trainer');
throws(fn() => act('account_state', ['id'=>(string)$boss, 'mode'=>'reset_link']), 'but not for herself', 'kann hier nicht geändert werden');
throws(fn() => act('account_state', ['id'=>(string)(int)$leaLogin['id'], 'mode'=>'reset_link']), 'nor for an invitation, which is sent again instead', 'Eine offene Einladung erneut senden');
run("UPDATE accounts SET state='suspended' WHERE id=?", [$active]);
throws(fn() => act('account_state', ['id'=>(string)$active, 'mode'=>'reset_link']), 'nor for a suspended login, which is restored first', 'zuerst entsperren');
$outbox = render_view('outbox');
ok(!preg_match('/token=[a-f0-9]{64}/', $outbox), 'and the outbox, which staff read, shows none of the links');
sign_out();
