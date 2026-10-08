<?php
/**
 * One login is one student (ADR 0010), and its address is its own and the only
 * name it signs in with (ADR 0020, 0021).
 *
 * A login used to be able to hold several children, and there were three ways
 * in: a "link an existing account" select, an invitation that joined a child
 * to an address that already had a login, and a directly created family login
 * that adopted every child at its address. All three are gone. A student gets a
 * login of their own from their own page, by invitation, or makes their own
 * student from an invitation by address; a brother or sister needs an address
 * of their own, and a parent's goes
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
make_student(['first_name'=>'Ohne', 'last_name'=>'Zugang', 'account_id'=>null]);
does_not_throw(fn() => make_student(['first_name'=>'Auch', 'last_name'=>'Ohne', 'account_id'=>null]),
               'while the database lets any number of students have no login at all - the code and the update hold that rule (ADR 0023 §4)');

// ---------------------------------------------------------------------------
case_('Inviting a student makes a login of their own, at their address');
mail_ready(true);
$mia = make_student(['first_name'=>'Mia', 'last_name'=>'Gruber', 'email'=>'Gruber.Familie@Beispiel.test']);
$miaPlaceholder = (int)scalar('SELECT account_id FROM students WHERE id=?', [$mia]);
act('student_invite', ['student_id'=>(string)$mia]);
$miaLogin = one('SELECT * FROM accounts WHERE id=?', [(int)scalar('SELECT account_id FROM students WHERE id=?', [$mia])]);
ok($miaLogin !== null, 'a login was made and the student points at it');
is_same($miaPlaceholder, (int)($miaLogin['id'] ?? 0), 'the placeholder she had from the start, now given her address (ADR 0023 §3)');
is_same('student', $miaLogin['role'] ?? null, 'a student’s login');
is_same('invited', $miaLogin['state'] ?? null, 'waiting for its invitation to be accepted');
is_same('gruber.familie@beispiel.test', $miaLogin['email'] ?? null, 'at the address on the student, written the one way');
is_same('Mia Gruber', $miaLogin['name'] ?? null, 'under the student’s own name');
is_same(1, links_queued_to('gruber.familie@beispiel.test'), 'with the invitation queued to it');
is_same(0, address_drift(), 'the student’s copy of the address is the login’s, byte for byte');
$logged = version_changes(history_for('accounts', (int)$miaLogin['id'])[0] ?? []);
is_same(['placeholder', 'invited'], [$logged['state']['from'] ?? null, $logged['state']['to'] ?? null],
        'the change log says when the placeholder became her login');
is_same('gruber.familie@beispiel.test', $logged['email']['to'] ?? null, 'and at which address');
$invitation = unseal((string)scalar("SELECT payload FROM mail_jobs WHERE account_id=? AND category='security'", [(int)$miaLogin['id']]));
ok(str_starts_with($invitation, "Hallo Mia,\n\n") && str_contains($invitation, 'Du meldest dich mit dieser E-Mail-Adresse an.')
   && !str_contains($invitation, 'Benutzername') && !str_contains($invitation, 'Geburtsdatum'),
   'the invitation greets her, says the address signs in, and asks for no details: her student exists');

case_('The card’s invitation takes the address and the language typed on it, and the login’s name from the student, cut to fit');
/* ADR 0030 §6: the address box sits on the access card, so the address is
   posted with the button and goes through invitation_address() as the wizard's
   does; a page from before the card had its box posts none, and then the
   record's is used. A posted name is never read: the login is called what the
   student is called - and a name built from two 100-character halves is 201
   characters, where a login's name holds 160, and MariaDB refused the insert. */
$longName = make_student(['first_name'=>str_repeat('Ä', 100), 'last_name'=>str_repeat('B', 100), 'email'=>'lang@beispiel.test']);
is_same(201, mb_strlen(student($longName)['first_name'].' '.student($longName)['last_name']), 'the name really is 201 characters');
act('student_invite', ['student_id'=>(string)$longName, 'email'=>' Anders@Beispiel.test ', 'name'=>'Jemand Anderes', 'locale'=>'en']);
$longLogin = one('SELECT * FROM accounts WHERE id=?', [(int)student($longName)['account_id']]);
is_same('anders@beispiel.test', $longLogin['email'] ?? null, 'the address typed on the card is the login’s, normalised');
is_same('anders@beispiel.test', (string)scalar('SELECT email FROM students WHERE id=?', [$longName]), 'and the record’s copy follows it');
$change = history_for('students', $longName)[0] ?? [];
is_same(['update', 'lang@beispiel.test', 'anders@beispiel.test'],
        [$change['operation'] ?? null, version_changes($change)['email']['from'] ?? null, version_changes($change)['email']['to'] ?? null],
        'and the student’s „Änderungen“ shows the address the card replaced, and by what');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['lang@beispiel.test']), 'nothing was made at the record’s old address');
is_same(TEXT_LINE_MAX, mb_strlen((string)($longLogin['name'] ?? '')), 'the name is cut to the 160 a login holds');
is_same(str_repeat('Ä', 100).' '.str_repeat('B', 59), $longLogin['name'] ?? null, 'from the end, by characters rather than bytes, and not the name posted');
is_same('en', $longLogin['locale'] ?? null, 'and the invitation speaks the language chosen on the card');
$oldPage = make_student(['first_name'=>'Alte', 'last_name'=>'Seite', 'email'=>'alte.seite@beispiel.test']);
is_same(['student', ['id'=>$oldPage, '#'=>'access']], act('student_invite', ['student_id'=>(string)$oldPage]),
        'the invitation lands at the access card, which now says „Eingeladen“, so she sees it without scrolling');
is_same([], history_for('students', $oldPage), 'an invitation to the address on the record changes nothing on the student, and adds no line to its „Änderungen“');
is_same(['alte.seite@beispiel.test', 'de'], array_values(one('SELECT email,locale FROM accounts WHERE id=?', [(int)student($oldPage)['account_id']]) ?? []),
        'a page that posts no address - opened before the card had its box - invites the record’s, in German');
$typo = make_student(['first_name'=>'Falsch', 'last_name'=>'Getippt', 'email'=>'falsch@beispiel.test']);
throws(fn() => act('student_invite', ['student_id'=>(string)$typo, 'email'=>'kein-at']), 'an address that is none is refused', 'Ungültige E-Mail-Adresse');
throws(fn() => act('student_invite', ['student_id'=>(string)$typo, 'email'=>'anders@beispiel.test']), 'and one that is another login’s', 'Jede Person braucht ihre eigene');
throws(fn() => act('student_invite', ['student_id'=>(string)$typo, 'email'=>'neu.getippt@beispiel.test', 'locale'=>'fr']), 'and a language the portal does not speak', 'Ungültige Auswahl');
is_same('placeholder', (string)scalar('SELECT state FROM accounts WHERE id=?', [(int)student($typo)['account_id']]), 'and the placeholder is as it was');

case_('Two children whose records carry one address, neither signing in: neither is invited at it, and each is asked for an own one [ADR 0030 §5]');
/* One person, one address. A parent's address typed on a brother's and a
   sister's record before either had a login is a contact's, not the login of
   whichever child is invited first. The card refuses it as the wizard does,
   naming the other child; the student's own record carrying it is no reason
   to refuse. */
$twinOne = make_student(['first_name'=>'Zwilling', 'last_name'=>'Eins', 'email'=>'zwillinge@beispiel.test']);
$twinTwo = make_student(['first_name'=>'Zwilling', 'last_name'=>'Zwei', 'email'=>'zwillinge@beispiel.test']);
$written = fn(): array => [(int)scalar('SELECT COUNT(*) FROM auth_tokens'), (int)scalar('SELECT COUNT(*) FROM mail_jobs'), (int)scalar('SELECT COUNT(*) FROM record_versions'),
    scalar('SELECT GROUP_CONCAT(a.state ORDER BY s.id) FROM students s JOIN accounts a ON a.id=s.account_id WHERE s.id IN (?,?)', [$twinOne, $twinTwo])];
$before = $written();
throws(fn() => act('student_invite', ['student_id'=>(string)$twinTwo]), 'the record’s own address is refused while the sister’s record carries it too, naming her',
       'Diese Adresse steht schon bei Zwilling Eins. Ist es Zwilling Eins, lade dort ein; sonst braucht Zwilling eine eigene Adresse');
throws(fn() => act('student_invite', ['student_id'=>(string)$twinOne, 'email'=>' Zwillinge@Beispiel.test ']), 'whichever of the two is invited, however it is typed', 'steht schon bei Zwilling Zwei');
is_same($before, $written(), 'nothing is written: no link, no mail, no change, both still without sign-in');
$stepsOf = fn(int $id): array => array_column(student_next_steps($id), null, 'what');
foreach ([[$twinOne, 'Zwilling Zwei'], [$twinTwo, 'Zwilling Eins']] as [$twin, $other]) {
    $steps = $stepsOf($twin);
    ok(!isset($steps['Zugang einladen']), 'the next steps offer no invitation that could only be refused');
    is_same(['email', true], [$steps['Eigene E-Mail-Adresse eintragen']['anchor'] ?? null, str_contains($steps['Eigene E-Mail-Adresse eintragen']['why'] ?? '', $other)],
            'they ask for an address of their own, at the record’s address box, naming '.$other);
}
save_student($twinTwo, ['email'=>'zwilling.zwei@beispiel.test']);
ok(isset($stepsOf($twinOne)['Zugang einladen']) && isset($stepsOf($twinTwo)['Zugang einladen']), 'once one has an own address, each is offered the invitation');
act('student_invite', ['student_id'=>(string)$twinOne]);
is_same(['invited', 'zwillinge@beispiel.test'], array_values(one('SELECT state,email FROM accounts WHERE id=?', [(int)student($twinOne)['account_id']]) ?? []),
        'and the address on her own record alone is hers to be invited at');

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
$brotherLogin = (int)scalar('SELECT account_id FROM students WHERE id=?', [$brother]);
ok($brotherLogin !== (int)$miaLogin['id'] && scalar('SELECT state FROM accounts WHERE id=?', [$brotherLogin]) === 'placeholder',
   'he is not put on his sister’s login, and keeps his own placeholder');
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
is_same('placeholder', (string)scalar('SELECT a.state FROM students s JOIN accounts a ON a.id=s.account_id WHERE s.id=?', [$paul]),
        'and the student is left on his placeholder rather than half-attached');
run('UPDATE students SET email=? WHERE id=?', ['paul@beispiel.test', $paul]);   // he is given one of his own

case_('A student who already has a login is not given a second one');
throws(fn() => act('student_invite', ['student_id'=>(string)$mia]),
       'inviting her again is refused', 'schon eine eigene Anmeldung');
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE email=?', ['gruber.familie@beispiel.test']), 'and no second login was made');

case_('Without mail, an invitation says what to do instead, and writes nothing');
mail_ready(false);
throws(fn() => act('student_invite', ['student_id'=>(string)$paul]),
       'the invitation is refused, and says what is missing, and who sets it up', 'Eine Einladung lässt sich noch nicht verschicken. Eine Administratorin muss zuerst den E-Mail-Versand einrichten und testen und die Datenschutzerklärung freigeben.');
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
       'the invitation is refused', 'Eine Administratorin muss zuerst den E-Mail-Versand einrichten und testen.');
ok(!str_contains(account_mail_missing(), 'Einstellungen') && !str_contains(account_mail_missing(), 'Datenschutz'),
   'a trainer is told who sets mail up, not sent to a page she cannot open, and only what is actually missing');
sign_in_as($admin);
is_same('E-Mail-Versand zuerst testen: unter „Einstellungen → SMTP“ die Verbindung prüfen.', account_mail_missing(),
        'an administrator is sent to the SMTP tab');
sign_in_as($trainer);
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
is_same($brotherLogin, (int)scalar('SELECT account_id FROM students WHERE id=?', [$brother]), 'a student without sign-in is not given another login by a save');
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
mail_ready(true);
throws(fn() => create_through_wizard(['first_name'=>'Neu', 'last_name'=>'Anmeldung'], 'email', ['email'=>'paul@beispiel.test']),
    'a new student invited at somebody’s login is refused', 'Jede Person braucht ihre eigene');
mail_ready(false);
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
is_same('Zugang gesperrt.', $_SESSION['flash']['message'] ?? null, 'and says what happened, in the button’s words');
is_same(['student', ['id'=>$paul]], act('account_state', ['id'=>(string)$paulLogin['id'], 'mode'=>'restore']),
        'and so does restoring');
is_same('Zugang entsperrt.', $_SESSION['flash']['message'] ?? null, 'and says so too');
is_same(['student', ['id'=>$mia]], act('account_state', ['id'=>(string)$miaLogin['id'], 'mode'=>'reinvite']),
        'and resending the invitation');
throws(fn() => act('account_state', ['id'=>(string)$paulLogin['id'], 'mode'=>'delete', 'confirmation'=>'Paul Mayr']),
       'deleting is confirmed by the address, not the name (ADR 0021, §1)', 'Zum Löschen die E-Mail-Adresse eingeben.');
throws(fn() => act('account_state', ['id'=>(string)$paulLogin['id'], 'mode'=>'withdraw']),
       'and a login that was set up cannot be withdrawn without typing it', 'noch nicht angenommen');
is_same(['student', ['id'=>$paul]], act('account_state', ['id'=>(string)$paulLogin['id'], 'mode'=>'delete', 'confirmation'=>' PAUL@Beispiel.test ']),
        'but by the address, however it was typed - and it still finds the student, because it looked before the link was cut');
$paulNow = one('SELECT a.* FROM students s JOIN accounts a ON a.id=s.account_id WHERE s.id=?', [$paul]);
ok($paulNow !== null && (int)$paulNow['id'] !== (int)$paulLogin['id'] && $paulNow['state'] === 'placeholder',
   'he is kept, on a fresh placeholder of his own: never without a login (ADR 0023 §4)');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [(int)$paulLogin['id']]), 'and the login that was in use is gone');
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
case_('The change log names a login by whose it is, or says it is gone');
is_same('Konto (Zugang)', history_field_label('account_id'), 'the field is called what it is');
is_same('Benutzername', history_field_label('username'), 'and a username in a line written before ADR 0021 still reads as one');
is_same('Mia Gruber', history_value((int)$miaLogin['id'], 'account_id'), 'a login that exists reads as its student');
$unnamed = make_account(['role'=>'student', 'name'=>'', 'email'=>'noch.ohne@beispiel.test', 'state'=>'invited', 'verified_at'=>null]);
is_same('noch.ohne@beispiel.test', history_value($unnamed, 'account_id'), 'and one nobody has named yet as its address');
is_same('gelöschter Zugang', history_value((int)$paulLogin['id'], 'account_id'), 'one that was deleted says so');
is_same('—', history_value(null, 'account_id'), 'and none at all is a dash, like every other empty value');
is_same((string)$paulLogin['id'], history_value((int)$paulLogin['id']), 'without the column, a number stays a number');
$labels = defined_labels_of_history();
is_same(array_values(array_unique($labels)), $labels, 'no field is labelled twice, where only the first label could ever be used');


// ---------------------------------------------------------------------------
case_('The example data is one student per login, each at an address of its own');
test_reset();
sign_in_as(make_account(['role'=>'admin']));
$filled = demo_fill();
foreach (['lena.hofer@beispiel.test' => 'Lena Hofer', 'jonas.berger@beispiel.test' => 'Jonas Berger'] as $email => $name) {
    $row = one('SELECT * FROM accounts WHERE email=?', [$email]);
    is_same($name, $row['name'] ?? null, $email.' is named after its student');
    is_same(1, (int)scalar('SELECT COUNT(*) FROM students WHERE account_id=?', [(int)($row['id'] ?? 0)]), 'and holds exactly one');
}
is_same([['trainerin@beispiel.test', 'trainer'], ['lena.hofer@beispiel.test', 'student'], ['jonas.berger@beispiel.test', 'student']],
        array_map(fn($l) => [$l['email'], $l['role']], $filled['logins']),
        'the fill hands back the addresses that sign in, staff first, for setup and the console to show');
is_same($filled['logins'], demo_logins(), 'read back from the database for the pages that show them');
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
    make_student(['email' => $email, 'account_id' => $id]);
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
case_('The wizard’s „Per E-Mail einladen“ makes the student, the login and the invitation in one step');
/* ADR 0023 §5, card (a): every refusal comes before the first write, so a
   refused invitation leaves no student behind either - and comes back to step 2
   with the draft whole. */
test_reset();
$boss = make_account(['role'=>'admin', 'name'=>'Chefin']);
$coach = make_account(['role'=>'trainer', 'name'=>'Trainerin', 'email'=>'coach@beispiel.test']);
sign_in_as($coach);
$draft = (string)act('student_draft', ['first_name'=>'Lea', 'last_name'=>'Neumann', 'birth_date'=>'', 'course'=>'none', 'status'=>'active'])[1]['draft'];
$create = fn(array $over = []) => act('student_create', $over + ['draft'=>$draft, 'method'=>'email', 'email'=>'lea.neumann@beispiel.test', 'locale'=>'de']);
$counts = fn() => [(int)scalar('SELECT COUNT(*) FROM students'), (int)scalar('SELECT COUNT(*) FROM accounts'),
                   (int)scalar('SELECT COUNT(*) FROM auth_tokens'), (int)scalar('SELECT COUNT(*) FROM mail_jobs')];
$nothing = $counts();
mail_ready(false);
throws(fn() => $create(), 'without mail it is refused, saying what is missing', 'Eine Einladung lässt sich noch nicht verschicken. Eine Administratorin muss zuerst den E-Mail-Versand');
is_same($nothing, $counts(), 'and nothing at all was created');
mail_ready(true);
throws(fn() => $create(['email'=>'']), 'an empty address is refused', 'Ungültige E-Mail-Adresse');
throws(fn() => $create(['email'=>'lea@']), 'an invalid one is refused', 'Ungültige E-Mail-Adresse');
throws(fn() => $create(['email'=>'Coach@Beispiel.test']), 'and one that is somebody’s login is refused, naming whose',
       'Jede Person braucht ihre eigene. Es ist der Zugang von Trainerin.');
is_same($nothing, $counts(), 'none of the three created anything');
ok(student_draft($draft) !== null, 'and the draft is still there for the next try');
$landed = $create();
$lea = (int)scalar("SELECT id FROM students WHERE first_name='Lea'");
is_same(['student_new', ['step'=>'done', 'id'=>$lea]], $landed, 'with mail and an address of her own, the student is created, and the done page opens');
$leaLogin = one('SELECT * FROM accounts WHERE id=?', [(int)scalar('SELECT account_id FROM students WHERE id=?', [$lea])]);
is_same(['invited', 'lea.neumann@beispiel.test'], [$leaLogin['state'] ?? null, $leaLogin['email'] ?? null],
        'with an invited login of her own');
is_same(1, (int)scalar("SELECT COUNT(*) FROM auth_tokens WHERE account_id=? AND purpose='invite'", [(int)$leaLogin['id']]), 'a token');
is_same(1, links_queued_to('lea.neumann@beispiel.test'), 'and the invitation queued');
is_same(0, address_drift(), 'her record carries the login’s address');
is_same(['insert'], array_column(history_for('students', $lea), 'operation'), 'the change log has her creation');
is_same(null, student_draft($draft), 'and the draft is gone');
create_through_wizard(['first_name'=>'Ohne', 'last_name'=>'Haken'], 'none');
is_same('placeholder', (string)scalar("SELECT a.state FROM students s JOIN accounts a ON a.id=s.account_id WHERE s.first_name='Ohne'"),
        '„Ohne Anmeldung anlegen“ makes the student with a placeholder');
is_same(0, (int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE subject LIKE '%Einladung%' AND account_id=(SELECT account_id FROM students WHERE first_name='Ohne')"),
        'and nobody is mailed');

case_('A reset link can go only to a login in use, at its address');
is_same([true, false, false, false, false], array_map(fn($a) => reset_link_possible($a), [
    ['state'=>'active', 'verified_at'=>now(), 'email'=>'a@b.test'], ['state'=>'invited', 'verified_at'=>null, 'email'=>'a@b.test'],
    ['state'=>'suspended', 'verified_at'=>now(), 'email'=>'a@b.test'], ['state'=>'active', 'verified_at'=>null, 'email'=>'a@b.test'],
    ['state'=>'active', 'verified_at'=>now(), 'email'=>null]]),
    'active, verified and with an address, and nothing else');

case_('Staff can have a link for a new password mailed, and never see it');
/* ADR 0020, §5: an active login only, never one's own, a trainer for students'
   logins only. The link goes to the login's own address; the outbox never shows
   a security mail's body. */
$active = make_account(['role'=>'student', 'name'=>'Aktiv', 'email'=>'aktiv@beispiel.test']);
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
is_same('Ein Link für ein neues Passwort ist an aktiv@beispiel.test unterwegs. Er gilt eine Stunde; bis dahin gilt das alte Passwort weiter.',
        $_SESSION['flash']['message'] ?? null, 'it names the address the link went to');
$cardKid = make_student(['first_name'=>'Karte', 'last_name'=>'Kind', 'email'=>'karte.kind@beispiel.test']);
act('student_invite', ['student_id'=>(string)$cardKid]);
is_same('Die Einladung an karte.kind@beispiel.test ist unterwegs.', $_SESSION['flash']['message'] ?? null,
        'an invitation from the card names the address');
act('account_state', ['id'=>(string)scalar('SELECT account_id FROM students WHERE id=?', [$cardKid]), 'mode'=>'reinvite']);
is_same('Die Einladung ist noch einmal an karte.kind@beispiel.test unterwegs. Der alte Link gilt nicht mehr.', $_SESSION['flash']['message'] ?? null,
        'and sending it again names it too');
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

// ---------------------------------------------------------------------------
// Moved from the usernames suite when usernames went (ADR 0021): what is true
// of an address whatever a login is called.
test_reset();
$boss = make_account(['role'=>'admin', 'name'=>'Chefin', 'email'=>'chefin@beispiel.test']);
$coach = make_account(['role'=>'trainer', 'name'=>'Trainerin', 'email'=>'trainerin@beispiel.test']);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'local';
$masked = fn(string $body) => (string)preg_replace('~https?://\S+token=[a-f0-9]{64}~', '{link}', $body);
$lastMail = fn(int $id) => $masked(unseal((string)scalar("SELECT payload FROM mail_jobs WHERE account_id=? AND status='queued' ORDER BY id DESC LIMIT 1", [$id])));

case_('A second login at an address is refused, in a sentence, before anything is written');
$family = make_account(['role' => 'student', 'email' => 'familie.berg@beispiel.test', 'name' => 'Familie Berg']);
make_student(['first_name' => 'Kim', 'last_name' => 'Berg', 'account_id' => $family]);
sign_in_as($boss);
throws(fn() => transactional(fn() => refuse_address_in_use('familie.berg@beispiel.test')),
       'staff are told whose it is, by the student’s name', 'Jede Person braucht ihre eigene. Es ist der Zugang von Kim Berg.');
sign_in_as($family);
try { transactional(fn() => refuse_address_in_use('trainerin@beispiel.test')); $told = ''; } catch (UserError $e) { $told = $e->getMessage(); }
ok($told !== '' && !str_contains($told, 'Trainerin') && !str_contains($told, 'Es ist der Zugang'), 'a family is never told a name: '.$told);
does_not_throw(fn() => transactional(fn() => refuse_address_in_use('trainerin@beispiel.test', $coach)), 'and a login is never in its own way');
does_not_throw(fn() => transactional(fn() => refuse_address_in_use('frei@beispiel.test')), 'an address nobody has is fine');
sign_in_as($boss);
mail_ready(true);
throws(fn() => act('account_invite', ['name' => 'Neue Trainerin', 'email' => 'Familie.Berg@beispiel.test', 'role' => 'trainer', 'locale' => 'de']),
       'account_invite refuses a family’s address for a staff login, however it is capitalised', 'Jede Person braucht ihre eigene');
is_same(1, (int)scalar("SELECT COUNT(*) FROM accounts WHERE email='familie.berg@beispiel.test'"), 'and wrote nothing');
$code = '';
try { make_account(['email' => 'familie.berg@beispiel.test']); } catch (PDOException $e) { $code = (string)$e->getCode(); }
is_same('23000', $code, 'a second row written past the guard is refused by the database with 23000');

case_('An invitation says the address signs in, and asks for the person’s details only when nobody made their student');
/* Spec S6: the whole mail, as the designer wrote it; the link is the only
   machine-made part. */
run('DELETE FROM mail_jobs');
act('account_invite', ['name' => 'Eva Hofer', 'email' => 'eva@beispiel.test', 'role' => 'trainer', 'locale' => 'de']);
$eva = (int)scalar("SELECT id FROM accounts WHERE email='eva@beispiel.test'");
is_same("Hallo Eva Hofer,\n\ndu bist ins Badminton-Portal eingeladen.\n\n"
    ."Öffne diesen Link, leg dein Passwort fest und ergänze danach deine Angaben:\n{link}\n\n"
    ."Du meldest dich mit dieser E-Mail-Adresse an.\n\n"
    ."Der Link gilt 48 Stunden. Ist er abgelaufen, bekommst du auf der Anmeldeseite unter „Passwort vergessen“ einen neuen.\n\n"
    ."Falls du diese E-Mail nicht erwartet hast, kannst du sie ignorieren.", $lastMail($eva), 'a team member’s invitation');
set_setting('org_name', 'Badmintonschule Hofer');
run("UPDATE accounts SET locale='en' WHERE id=?", [$eva]);
act('account_state', ['id' => (string)$eva, 'mode' => 'reinvite']);
is_same("Hello Eva Hofer,\n\nyou have been invited to Badmintonschule Hofer's portal.\n\n"
    ."Open this link, set your password and then complete your details:\n{link}\n\n"
    ."You sign in with this email address.\n\n"
    ."The link is valid for 48 hours. If it has expired, “Forgot your password” on the sign-in page sends a new one.\n\n"
    ."If you did not expect this email, you can ignore it.", $lastMail($eva), 'in English for an English login, naming the club once it has a name');
act('email_invite', ['email' => 'selbst@beispiel.test', 'locale' => 'de']);
$self = (int)scalar("SELECT id FROM accounts WHERE email='selbst@beispiel.test'");
is_same("Hallo,\n\ndu bist ins Portal von Badmintonschule Hofer eingeladen.\n\n"
    ."Öffne diesen Link und richte dein Konto ein: Vorname, Nachname, Geburtsdatum und ein Passwort. Danach wählst du deinen Kurs.\n{link}\n\n"
    ."Du meldest dich danach mit dieser E-Mail-Adresse an.\n\n"
    ."Der Link gilt 48 Stunden. Ist er abgelaufen, bekommst du auf der Anmeldeseite unter „Passwort vergessen“ einen neuen.\n\n"
    ."Falls du diese E-Mail nicht erwartet hast, kannst du sie ignorieren.", $lastMail($self),
    'an invitation by address greets nobody by name - never „Hallo ,“ - and says what the page will ask');
run("UPDATE accounts SET locale='en' WHERE id=?", [$self]);
act('account_state', ['id' => (string)$self, 'mode' => 'reinvite']);
is_same("Hello,\n\nyou have been invited to Badmintonschule Hofer's portal.\n\n"
    ."Open this link and set up your account: first name, last name, date of birth and a password. After that you pick your course.\n{link}\n\n"
    ."You then sign in with this email address.\n\n"
    ."The link is valid for 48 hours. If it has expired, “Forgot your password” on the sign-in page sends a new one.\n\n"
    ."If you did not expect this email, you can ignore it.", $lastMail($self), 'and in English');
set_setting('org_name', '');
is_same('selbst@beispiel.test', login_holder_name(one('SELECT * FROM accounts WHERE id=?', [$self])),
        'staff see the address where a name would be, until the person types one');

case_('A changed address is asked for without saying whether it is taken, and refused when the link is opened');
/* Asking would tell a signed-in family whether an address has a login - half a
   credential, since the address signs in. Refusing at confirmation tells only
   the reader of that mailbox, who knows already (ADR 0020, §1). */
$lisa = make_account(['name' => 'Lisa Bauer', 'email' => 'lisa@beispiel.test', 'password_hash' => password_hash('Test-Only-Password-2026', PASSWORD_DEFAULT)]);
sign_in_as($lisa);
throttle_clear('email-change', (string)$lisa);
does_not_throw(fn() => act('email_change', ['password' => 'Test-Only-Password-2026', 'email' => 'Trainerin@Beispiel.test']),
               'a trainer’s address is asked for like any other');
is_same('trainerin@beispiel.test', (string)scalar("SELECT target_email FROM auth_tokens WHERE account_id=? AND purpose='email'", [$lisa]),
        'with the confirmation link on its way to it, where only the trainer reads it');
ok(!str_contains((string)($_SESSION['flash']['message'] ?? ''), 'gehört'), 'and the answer says nothing about whose it is');
$_SESSION['activation_hash'] = hash('sha256', make_token($lisa, 'email', 'trainerin@beispiel.test'));
throws(fn() => act('activate', []), 'opening the link is refused', 'Jede Person braucht ihre eigene');
is_same('lisa@beispiel.test', (string)scalar('SELECT email FROM accounts WHERE id=?', [$lisa]), 'and her address is unchanged');

// ---------------------------------------------------------------------------
// Option 1 of ADR 0021, §3: staff type an address, the person makes their own student.
case_('Inviting by address makes an invited student login with no student, and lists it as an open invitation');
sign_in_as($coach);
mail_ready(true);
run('DELETE FROM mail_jobs');
is_same(['students', ['invitations' => 1]], act('email_invite', ['email' => ' Ida.Neumann@Beispiel.test ', 'locale' => 'en']),
        'a trainer sends one, and lands on the list of open invitations');
$ida = one("SELECT * FROM accounts WHERE email='ida.neumann@beispiel.test'");
is_same(['student', '', 'invited', null, null, 'en'],
        [$ida['role'] ?? null, $ida['name'] ?? null, $ida['state'] ?? null, $ida['verified_at'] ?? null, $ida['password_hash'] ?? null, $ida['locale'] ?? null],
        'a student login with no name, waiting, with no password, in the language chosen');
is_same(0, (int)scalar('SELECT COUNT(*) FROM students WHERE account_id=?', [(int)$ida['id']]), 'and no student: nobody has typed a name yet');
is_same('invite', (string)scalar('SELECT purpose FROM auth_tokens WHERE account_id=?', [(int)$ida['id']]), 'with an invitation link');
is_same(1, links_queued_to('ida.neumann@beispiel.test'), 'mailed to the address');
is_same('Die Einladung an ida.neumann@beispiel.test ist unterwegs.', $_SESSION['flash']['message'] ?? null, 'and she is told so');
ok(in_array((int)$ida['id'], array_map('intval', array_column(open_invitations(), 'id')), true), 'it is an open invitation');
ok(!in_array((int)$ida['id'], array_map('intval', array_column(orphan_logins(), 'id')), true), 'not a login left behind by a deleted student');
run("UPDATE accounts SET state='suspended' WHERE id=?", [(int)$ida['id']]);
ok(in_array((int)$ida['id'], array_map('intval', array_column(open_invitations(), 'id')), true), 'suspended, it is still an open invitation');
run("UPDATE accounts SET state='invited' WHERE id=?", [(int)$ida['id']]);
sign_in_as($family);
throws(fn() => act('email_invite', ['email' => 'fremd@beispiel.test', 'locale' => 'de']), 'a family cannot invite anybody', 'Kein Zugriff');
sign_in_as($coach);

case_('Inviting by address refuses before anything is written');
/* Refused like every other action: the front controller says why, keeps what
   was typed and returns to the students page, which opens the card again from
   that (pages suite). Here: the right sentence, and nothing written. */
$tom = make_student(['first_name' => 'Tom', 'last_name' => 'Weber', 'email' => 'tom@beispiel.test']);
$counts = fn() => [(int)scalar('SELECT COUNT(*) FROM accounts'), (int)scalar('SELECT COUNT(*) FROM auth_tokens'),
                   (int)scalar('SELECT COUNT(*) FROM mail_jobs'), (int)scalar('SELECT COUNT(*) FROM audit_log')];
$refusals = [
    'an address that is not one'                   => [['email' => 'nicht-gueltig'], 'Ungültige E-Mail-Adresse.'],
    'an address that is a login'                    => [['email' => 'Familie.Berg@beispiel.test'], 'Diese E-Mail-Adresse gehört schon zu einem anderen Zugang. Jede Person braucht ihre eigene. Es ist der Zugang von Kim Berg.'],
    'an address with an invitation on its way'      => [['email' => 'ida.neumann@beispiel.test'], 'An diese Adresse ist schon eine Einladung unterwegs. Du findest sie unter „Offene Einladungen“.'],
    'an address on a student without a login'       => [['email' => 'TOM@beispiel.test'], 'Diese Adresse steht schon bei Tom Weber. Ist es Tom Weber, lade dort ein; sonst braucht die eingeladene Person eine eigene Adresse – die der Eltern gehört zu den Kontakten.'],
];
foreach ($refusals as $what => [$posted, $said]) {
    $before = $counts();
    throws(fn() => act('email_invite', $posted + ['locale' => 'en']), $what.' is refused, saying why', $said);
    is_same($before, $counts(), 'and nothing written: no login, no link, no mail, no audit line');
}
mail_ready(false);
$before = $counts();
throws(fn() => act('email_invite', ['email' => 'spaeter@beispiel.test', 'locale' => 'de']), 'without mail it says what is missing',
       'Eine Einladung lässt sich noch nicht verschicken. Eine Administratorin muss zuerst den E-Mail-Versand');
is_same($before, $counts(), 'and leaves no login waiting without a link');
mail_ready(true);

case_('Opening the invitation asks for the person’s details; the link decides, never the post');
$token = fn(int $id, string $purpose = 'invite') => token_record(hash('sha256', make_token($id, $purpose)));
$withStudent = make_account(['email' => 'mit.kind@beispiel.test', 'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
$hisStudent = make_student(['first_name' => 'Max', 'last_name' => 'Kind', 'email' => 'mit.kind@beispiel.test', 'account_id' => $withStudent]);
$staffInvite = make_account(['role' => 'trainer', 'email' => 'neue.trainerin@beispiel.test', 'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
is_same([true, false, false, false],
        [setup_creates_student($token((int)$ida['id'])), setup_creates_student($token($withStudent)), setup_creates_student($token($staffInvite)),
         setup_creates_student($token((int)$ida['id'], 'reset'))],
        'only an invitation to a student login no student points to: not one with a student, not a team member’s, not a reset');
is_same(['ida.neumann@beispiel.test', 'en'], [$token((int)$ida['id'])['email'] ?? null, $token((int)$ida['id'])['locale'] ?? null],
        'the link carries the address the page shows, and the invitation’s language');
unset($_SESSION['locale']);
adopt_link_language($token((int)$ida['id']));
is_same('en', $_SESSION['locale'] ?? null, 'opening it on a phone that chose no language speaks the invitation’s');
$_SESSION['locale'] = 'de';
adopt_link_language($token((int)$ida['id']));
is_same('de', $_SESSION['locale'], 'while a language chosen on this browser is kept');
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
ok(str_contains($router, "adopt_link_language(token_record(\$_SESSION['activation_hash']));\n        go('activate');"), 'the router asks it as the link is opened');

case_('Setting up refuses missing names and a birth date that cannot be right, before writing anything');
sign_out();
throttle_clear('auth-ip', $ip);
$_SESSION['activation_hash'] = hash('sha256', make_token((int)$ida['id'], 'invite'));
$setUp = ['password' => 'Federball-2026-Halle!', 'password_confirm' => 'Federball-2026-Halle!', 'privacy_seen' => '1', 'notifications' => '1'];
$details = ['first_name' => 'Ida', 'last_name' => 'Neumann', 'birth_date' => '2014-05-06'];
$untouched = fn() => [(int)scalar('SELECT COUNT(*) FROM students'), one('SELECT state,name,password_hash FROM accounts WHERE id=?', [(int)$ida['id']])];
$before = $untouched();
foreach (['no first name' => [['first_name' => ''], 'Bitte Vor- und Nachnamen eintragen.'],
          'no last name' => [['last_name' => '   '], 'Bitte Vor- und Nachnamen eintragen.'],
          'no birth date' => [['birth_date' => ''], 'Bitte das Geburtsdatum prüfen.'],
          'a birth date in the future' => [['birth_date' => (new DateTimeImmutable(today()))->modify('+1 day')->format('Y-m-d')], 'Bitte das Geburtsdatum prüfen.'],
          'a birth date over a hundred years ago' => [['birth_date' => '1900-01-01'], 'Bitte das Geburtsdatum prüfen.'],
          'a date that does not exist' => [['birth_date' => '2014-02-30'], 'Bitte das Geburtsdatum prüfen.'],
          'everything right but the privacy tick' => [['privacy_seen' => ''], 'Datenschutzhinweise'],
          'everything right but the password' => [['password_confirm' => 'anders'], 'stimmen nicht überein']] as $what => [$change, $said]) {
    throws(fn() => submit('activate', $change + $details + $setUp), $what.' is refused', $said);
    is_same($before, $untouched(), 'and nothing was written: no student, the login as it was');
}
ok(token_record((string)$_SESSION['activation_hash']) !== null, 'the link still works for the next try');

case_('Setting up makes exactly one student, of what was typed, and signs the person in');
sign_in_as($coach);   // somebody else is still signed in on this browser: they are not who sets this up
run('DELETE FROM record_versions'); run('DELETE FROM notifications');
throttle_clear('auth-ip', $ip);
$landed = submit('activate', $details + $setUp + ['email' => 'jemand.anders@beispiel.test', 'status' => 'ended', 'internal_notes' => 'erfunden',
                                                  'account_id' => (string)$coach, 'address' => 'Erfunden 1']);
$own = (int)scalar('SELECT id FROM students WHERE account_id=?', [(int)$ida['id']]);
$row = one('SELECT * FROM students WHERE id=?', [$own]);
is_same(1, (int)scalar('SELECT COUNT(*) FROM students WHERE account_id=?', [(int)$ida['id']]), 'one student, on her login');
is_same(['Ida', 'Neumann', '2014-05-06', 'ida.neumann@beispiel.test'], [$row['first_name'] ?? null, $row['last_name'] ?? null, $row['birth_date'] ?? null, $row['email'] ?? null],
        'with the names and birth date typed, and the login’s own address, not the one posted');
$defaults = new_student_defaults();
is_same([$defaults['status'], today(), $defaults['level_id'] === null ? null : (int)$defaults['level_id'], '', ''],
        [$row['status'] ?? null, $row['joined_on'] ?? null, $row['level_id'] === null ? null : (int)$row['level_id'], (string)($row['internal_notes'] ?? ''), (string)($row['address'] ?? '')],
        'and what every new student starts with: nothing else posted is read');
is_same(['Ida Neumann', 'active'], [scalar('SELECT name FROM accounts WHERE id=?', [(int)$ida['id']]), scalar('SELECT state FROM accounts WHERE id=?', [(int)$ida['id']])],
        'the login is named after her and set up');
is_same((int)$ida['id'], (int)(current_user()['id'] ?? 0), 'she is signed in, and nobody else is');
is_same(['student', ['id' => $own]], $landed, 'on her own page');
is_same('Kurs wählen', family_next_steps($own)[0]['what'] ?? null, 'where choosing a course is the first thing to do');
$line = one("SELECT * FROM record_versions WHERE entity='students' AND entity_id=?", [$own]);
is_same(['insert', (int)$ida['id']], [$line['operation'] ?? null, (int)($line['actor_id'] ?? 0)],
        'the change log has her student, made by her - not by whoever this browser was signed in as');
is_same((int)$ida['id'], (int)scalar("SELECT actor_id FROM audit_log WHERE action='student.saved' AND entity_id=? ORDER BY id DESC LIMIT 1", [$own]),
        'and so does the audit log');
$told = rows("SELECT account_id,title,body,link_page,link_params FROM notifications ORDER BY account_id");
is_same([$boss, $coach], array_map('intval', array_column($told, 'account_id')), 'every member of staff hears about it');
is_same(['Neu im Portal: Ida Neumann', 'Hat sich über die Einladung an ida.neumann@beispiel.test eingerichtet. Noch in keinem Kurs.', 'student', 'id='.$own],
        [$told[0]['title'] ?? null, $told[0]['body'] ?? null, $told[0]['link_page'] ?? null, $told[0]['link_params'] ?? null],
        'naming her, with a link to her page');
is_same('Dein Konto ist bereit. Du meldest dich ab jetzt mit ida.neumann@beispiel.test an. Willkommen, Ida! Schau kurz, ob alles stimmt, und ergänze, was fehlt. Frag deine Eltern, wenn du etwas nicht weißt.',
        $_SESSION['flash']['message'] ?? null, 'and told how she signs in from now on, and welcomed to check her details (ADR 0023 §5)');
ok(!in_array((int)$ida['id'], array_map('intval', array_column(open_invitations(), 'id')), true)
   && !in_array((int)$ida['id'], array_map('intval', array_column(orphan_logins(), 'id')), true), 'no longer an open invitation, nor a login left behind');

case_('A form sent twice cannot make two students');
throws(fn() => submit('activate', $details + $setUp), 'the same page sent again is refused: its link is used up', 'ungültig oder abgelaufen');
is_same(1, (int)scalar('SELECT COUNT(*) FROM students WHERE account_id=?', [(int)$ida['id']]), 'and she still has one student');
$code = '';
try { transactional(fn() => create_own_student((int)$ida['id'], 'ida.neumann@beispiel.test', $details)); } catch (PDOException $e) { $code = (string)$e->getCode(); }
is_same('23000', $code, 'and two arriving together are stopped by the unique index on students.account_id');
sign_out();

case_('Names posted to any other invitation make no student');
foreach (['a student’s own invitation' => $withStudent, 'a team member’s' => $staffInvite] as $what => $login) {
    $_SESSION['activation_hash'] = hash('sha256', make_token($login, 'invite'));
    $students = (int)scalar('SELECT COUNT(*) FROM students');
    throttle_clear('auth-ip', $ip);
    does_not_throw(fn() => submit('activate', ['first_name' => 'Erfunden', 'last_name' => 'Person', 'birth_date' => '2010-01-01'] + $setUp), $what.' is set up');
    is_same($students, (int)scalar('SELECT COUNT(*) FROM students'), 'and no student is made');
    sign_out();
}
is_same(['Max', 'Kind'], array_values(one('SELECT first_name,last_name FROM students WHERE id=?', [$hisStudent]) ?? []), 'nor are the existing student’s names changed');

case_('An invitation not taken up is sent again or withdrawn from the students page, without typing anything');
sign_in_as($coach);
act('email_invite', ['email' => 'zurueck@beispiel.test', 'locale' => 'de']);
$back = (int)scalar("SELECT id FROM accounts WHERE email='zurueck@beispiel.test'");
is_same(['students', ['invitations' => 1]], act('account_state', ['id' => (string)$back, 'mode' => 'reinvite']), 'sending it again returns to the list');
is_same(['students', ['invitations' => 1]], act('account_state', ['id' => (string)$back, 'mode' => 'withdraw']), 'and so does withdrawing it');
is_same([0, 0, 0], [(int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [$back]), (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [$back]),
                    (int)scalar('SELECT COUNT(*) FROM mail_jobs WHERE account_id=?', [$back])], 'the login, its link and its mail are gone');
is_same('Die Einladung an zurueck@beispiel.test ist zurückgezogen. Versehentlich? Lade die Adresse einfach neu ein.', $_SESSION['flash']['message'] ?? null,
        'and the way back is said');
does_not_throw(fn() => act('email_invite', ['email' => 'zurueck@beispiel.test', 'locale' => 'de']), 'inviting again is that way back');
throws(fn() => act('account_state', ['id' => (string)$staffInvite, 'mode' => 'withdraw']), 'a trainer withdraws no team member’s login', 'kann hier nicht geändert werden');
$cardKid = make_student(['first_name' => 'Karte', 'last_name' => 'Zwei', 'email' => 'karte.zwei@beispiel.test']);
act('student_invite', ['student_id' => (string)$cardKid]);
$cardLogin = (int)scalar('SELECT account_id FROM students WHERE id=?', [$cardKid]);
is_same(['student', ['id' => $cardKid]], act('account_state', ['id' => (string)$cardLogin, 'mode' => 'withdraw']),
        'a student’s own invitation is withdrawn from their page, and returns there');
$cardNow = one('SELECT a.* FROM students s JOIN accounts a ON a.id=s.account_id WHERE s.id=?', [$cardKid]);
ok($cardNow !== null && (int)$cardNow['id'] !== $cardLogin && $cardNow['state'] === 'placeholder' && $cardNow['email'] === null,
   'the student stays, on a fresh placeholder, ready to be invited again');
is_same([0, 0], [(int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [$cardLogin]), (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [$cardLogin])],
        'and the invitation, its login and its link are gone');

case_('Deleting a student takes a login never set up with it, and keeps one that was');
/* ADR 0021, §4: left standing, the live link would let the person make the
   record again, and the login would read as an open invitation. */
$gone = make_student(['first_name' => 'Weg', 'last_name' => 'Damit', 'email' => 'weg@beispiel.test']);
act('student_invite', ['student_id' => (string)$gone]);
$goneLogin = (int)scalar('SELECT account_id FROM students WHERE id=?', [$gone]);
act('student_delete', ['id' => (string)$gone, 'confirmation' => 'Weg Damit']);
is_same([0, 0, 0], [(int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [$goneLogin]), (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [$goneLogin]),
                    (int)scalar('SELECT COUNT(*) FROM mail_jobs WHERE account_id=?', [$goneLogin])], 'the never-used login, its link and its mail go with the student');
ok(str_ends_with((string)($_SESSION['flash']['message'] ?? ''), 'Die Einladung an weg@beispiel.test gilt nicht mehr.'), 'and she is told the invitation no longer works');
$kept = make_student(['first_name' => 'Bleibt', 'last_name' => 'Da', 'email' => 'bleibt@beispiel.test',
                      'account_id' => $keptLogin = make_account(['email' => 'bleibt@beispiel.test', 'name' => 'Bleibt Da'])]);
act('student_delete', ['id' => (string)$kept, 'confirmation' => 'Bleibt Da']);
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [$keptLogin]), 'a login that was set up stays');
ok(in_array($keptLogin, array_map('intval', array_column(orphan_logins(), 'id')), true)
   && !in_array($keptLogin, array_map('intval', array_column(open_invitations(), 'id')), true), 'as a login left behind on Konten, not an open invitation');
// A student linked to a team member's login is left from before ADR 0010: a
// trainer deleting that student must not take a team invitation with it.
$teamLogin = make_account(['role' => 'trainer', 'email' => 'alt.team@beispiel.test', 'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
$linked = make_student(['first_name' => 'Alt', 'last_name' => 'Verknuepft', 'email' => 'alt.team@beispiel.test', 'account_id' => $teamLogin]);
act('student_delete', ['id' => (string)$linked, 'confirmation' => 'Alt Verknuepft']);
is_same(1, (int)scalar('SELECT COUNT(*) FROM accounts WHERE id=?', [$teamLogin]), 'a team member’s invitation stays, whatever student it was linked to');
sign_out();
