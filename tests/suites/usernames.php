<?php
/**
 * Usernames, and one address per login (ADR 0019, as amended by ADR 0020).
 *
 * Everybody has a username such as lena.mueller, made from their name, and an
 * address of their own; either signs in, and either asks „Passwort vergessen"
 * for a way back in. No two logins share an address. The review items the ADRs
 * name are in brackets where they are tested.
 */
$admin = make_account(['role' => 'admin', 'name' => 'Chefin', 'username' => 'chefin', 'email' => 'chefin@beispiel.test']);
$trainer = make_account(['role' => 'trainer', 'name' => 'Trainerin', 'username' => 'trainerin', 'email' => 'trainerin@beispiel.test']);
$password = 'Test-Only-Password-2026';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'local';
/** Start the per-IP limits afresh, so how much this suite has sent cannot decide a case. */
$freshIp = function () use ($ip): void {
    foreach (['auth-ip', 'forgot-ip'] as $name) throttle_clear($name, $ip);
};

// ---------------------------------------------------------------------------
case_('A username is made from the name, the same way on every host');
foreach ([[['Anna Lena', 'von der Heide'], 'anna-lena.von-der-heide'],
          [['Hans-Peter', "O'Neill"], 'hans-peter.oneill'],
          [['Jürgen', 'Groß'], 'juergen.gross'],
          [['Łukasz', 'Wałęsa'], 'lukasz.walesa'],
          [['王', '芳'], 'konto'],
          [['ÄNNE', ''], 'aenne'],
          [['Lena', 'ẞeidl'], 'lena.sseidl'],
          [['Renée', 'Fjørtoft'], 'renee.fjortoft'],
          [['', 'Müller'], 'mueller'],
          [['1Anna', ''], 'konto'],
          [['Jo', ''], 'konto']] as [[$first, $last], $expected])
    is_same($expected, username_from_name($first, $last), json_encode([$first, $last], JSON_UNESCAPED_UNICODE).' becomes '.$expected);
$long = username_from_name('Anna-Maria-Theresia-Josefine', 'von-und-zu-Hohenlohe-Schillingsfürst');
ok(strlen($long) <= 36, 'a long name is cut to 36, leaving room for a number up to 9999: '.$long);
is_same('anna-maria-theresia-josefine.von-und', $long, 'at a separator rather than in the middle of a word');
is_same(36, strlen(username_from_name(str_repeat('a', 50), '')), 'and a name with no separator is cut where it must be');
is_same('trainerin.beispiel', username_from_full_name('Trainerin Beispiel'), 'a one-field name uses its first and last word');
is_same('maria.ruiz', username_from_full_name('  Maria del Carmen  Ruiz '), 'middle words are dropped');
is_same('admin', username_from_full_name('Admin'), 'one word is the first name alone, and "admin" is nobody’s to reserve');
is_same('lena.mueller', username_normalised(' Lena.Müller '), 'a typed username is trimmed, lower-cased and transliterated');
is_same('lena.mueller', username_value('Lena.Mueller'), 'and written in that form');
foreach (['ab', 'lena_mueller', 'lena..mueller', '.lena', 'lena-', '1lena', str_repeat('a', 41), '#12', 'lena mueller', '王芳'] as $bad)
    throws(fn() => username_value($bad), 'refused as a username: '.json_encode($bad, JSON_UNESCAPED_UNICODE), '3 bis 40 Zeichen');

case_('A number is added only when the name is taken, and the lowest free one first');
is_same('lena', username_first_free('lena', ['anna']), 'a free name is kept plain');
is_same('lena2', username_first_free('lena', ['lena']), 'the second gets a 2');
is_same('lena3', username_first_free('lena', ['lena', 'lena2']), 'the third a 3');
is_same('lena2', username_first_free('lena', ['lena', 'lena3']), 'and a 2 freed by a deleted login is used again');
$made = transactional(function (): array {
    $out = [];
    foreach (['Lena', 'Lena', 'Lena'] as $i => $first) {
        $out[] = $username = username_for_new_account($first, 'Müller');
        make_account(['username' => $username, 'email' => 'lena'.$i.'@beispiel.test']);
    }
    return $out;
});
is_same(['lena.mueller', 'lena.mueller2', 'lena.mueller3'], $made, 'a second and a third Lena Müller get …2 and …3');
make_account(['username' => 'lena.mueller-ost']);
run("DELETE FROM accounts WHERE username='lena.mueller2'");
is_same('lena.mueller2', transactional(fn() => username_for_new_account('Lena', 'Müller')),
        'the freed …2 is handed out again, and lena.mueller-ost is no number of lena.mueller’s');

// ---------------------------------------------------------------------------
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
sign_in_as($trainer);
$families = [];
foreach (['news.eins@beispiel.test', $legacy, 'news.zwei@beispiel.test'] as $i => $address)
    $families[] = make_account(['email' => $address, 'username' => 'news.'.$i, 'newsletter' => 1]);
does_not_throw(fn() => queue_mail($families[1], $legacy, 'Test', 'Hallo', 'newsletter'), 'queue_mail() takes the legacy address');
run('DELETE FROM mail_jobs');
act('news_save', ['title' => 'Turnier', 'body' => 'Am Samstag', 'published' => '1', 'send_email' => '1']);
$queued = array_column(rows("SELECT recipient FROM mail_jobs WHERE category='newsletter' ORDER BY account_id"), 'recipient');
is_same(['news.eins@beispiel.test', $legacy, 'news.zwei@beispiel.test'], array_values(array_intersect($queued, ['news.eins@beispiel.test', $legacy, 'news.zwei@beispiel.test'])),
        'one newsletter for each of them, the legacy address not rolling the others back');
throws(fn() => queue_mail($families[0], "news\x01@beispiel.test", 'Test', 'Hallo', 'newsletter'), 'while an undeliverable address is still refused');
run('DELETE FROM accounts WHERE id IN ('.implode(',', $families).')');

// ---------------------------------------------------------------------------
case_('A second login at an address is refused, in a sentence, before anything is written [R-a]');
$student = make_account(['role' => 'student', 'email' => 'familie.berg@beispiel.test', 'username' => 'kind.berg', 'name' => 'Familie Berg']);
make_student(['first_name' => 'Kim', 'last_name' => 'Berg', 'account_id' => $student]);
sign_in_as($admin);
throws(fn() => transactional(fn() => refuse_address_in_use('familie.berg@beispiel.test')),
       'any login at an address another login has is refused', 'Jede Person braucht ihre eigene');
throws(fn() => transactional(fn() => refuse_address_in_use('familie.berg@beispiel.test')),
       'and staff are told whose it is, by the student’s name', 'Es ist der Zugang von Kim Berg.');
sign_in_as($student);
try { transactional(fn() => refuse_address_in_use('trainerin@beispiel.test')); $told = ''; } catch (UserError $e) { $told = $e->getMessage(); }
ok($told !== '' && !str_contains($told, 'Trainerin') && !str_contains($told, 'Es ist der Zugang'), 'a family is never told a name: '.$told);
does_not_throw(fn() => transactional(fn() => refuse_address_in_use('trainerin@beispiel.test', $trainer)),
               'and a login is never in its own way');
does_not_throw(fn() => transactional(fn() => refuse_address_in_use('frei@beispiel.test')), 'an address nobody has is fine');
sign_in_as($admin);
mail_ready(true);
throws(fn() => act('account_invite', ['name' => 'Neue Trainerin', 'email' => 'Familie.Berg@beispiel.test', 'role' => 'trainer', 'locale' => 'de']),
       'account_invite refuses a family’s address for a staff login, however it is capitalised', 'Jede Person braucht ihre eigene');
is_same(1, (int)scalar("SELECT COUNT(*) FROM accounts WHERE email='familie.berg@beispiel.test'"), 'and wrote nothing');
/* Bypassing the sentence: the unique index from 001 is the backstop, under any
   isolation level, on SQLite and on the real engine alike. */
$code = '';
try { make_account(['email' => 'familie.berg@beispiel.test', 'username' => 'zweites.konto']); } catch (PDOException $e) { $code = (string)$e->getCode(); }
is_same('23000', $code, 'a second row written past the guard is refused by the database with 23000');

case_('An invitation names the username, says it can be changed while setting up, and that the address signs in too');
act('account_invite', ['name' => 'Eva Maria Hofer', 'email' => 'eva@beispiel.test', 'role' => 'trainer', 'locale' => 'de']);
is_same('eva.hofer', (string)scalar("SELECT username FROM accounts WHERE email='eva@beispiel.test'"), 'eva.hofer, from the first and last word');
act('account_invite', ['name' => 'Eva Hofer', 'email' => 'eva2@beispiel.test', 'role' => 'trainer', 'locale' => 'de']);
$invited = one("SELECT * FROM accounts WHERE email='eva2@beispiel.test'");
is_same('eva.hofer2', $invited['username'] ?? null, 'a second Eva Hofer is eva.hofer2');
$mail = unseal((string)scalar("SELECT payload FROM mail_jobs WHERE account_id=?", [(int)$invited['id']]));
/* The whole mail, as the designer wrote it (spec §3.1): nothing in it reads as
   machine-made, and the link is the only machine part. */
$masked = fn(string $body) => (string)preg_replace('~https?://\S+token=[a-f0-9]{64}~', '{link}', $body);
is_same("Hallo Eva Hofer,\n\ndu bist ins Badminton-Portal eingeladen.\n\nDein Benutzername: eva.hofer2\n"
    ."Beim Einrichten kannst du ihn so lassen oder ändern. Anmelden kannst du dich damit oder mit dieser E-Mail-Adresse.\n\n"
    ."Öffne diesen Link, leg dein Passwort fest und ergänze danach deine Angaben:\n{link}\n\n"
    ."Der Link gilt 48 Stunden. Ist er abgelaufen, bekommst du unter „Benutzername oder Passwort vergessen“ einen neuen.\n\n"
    ."Falls du diese E-Mail nicht erwartet hast, kannst du sie ignorieren.", $masked($mail),
    'the invitation names the username, says it may stay or change, that the address signs in too, and what to do when the link has expired');
set_setting('org_name', 'Badmintonschule Hofer');
act('account_state', ['id'=>(string)$invited['id'], 'mode'=>'reinvite']);
$mail = unseal((string)scalar("SELECT payload FROM mail_jobs WHERE account_id=? AND status='queued' ORDER BY id DESC LIMIT 1", [(int)$invited['id']]));
ok(str_contains($mail, "du bist ins Portal von Badmintonschule Hofer eingeladen."), 'and names the club once it has a name');
run("UPDATE accounts SET locale='en' WHERE id=?", [(int)$invited['id']]);
act('account_state', ['id'=>(string)$invited['id'], 'mode'=>'reinvite']);
$mail = $masked(unseal((string)scalar("SELECT payload FROM mail_jobs WHERE account_id=? AND status='queued' ORDER BY id DESC LIMIT 1", [(int)$invited['id']])));
is_same("Hello Eva Hofer,\n\nyou have been invited to Badmintonschule Hofer's portal.\n\nYour username: eva.hofer2\n"
    ."While setting up you can keep it or change it. You can sign in with it or with this email address.\n\n"
    ."Open this link, set your password and then complete your details:\n{link}\n\n"
    ."The link is valid for 48 hours. If it has expired, “Forgot your username or password” sends a new one.\n\n"
    ."If you did not expect this email, you can ignore it.", $mail, 'in English for an English login');
set_setting('org_name', '');

// ---------------------------------------------------------------------------
case_('„Vergessen“ takes a username or an address, and mails the address as stored [§4, S1]');
run('DELETE FROM mail_jobs'); run('DELETE FROM auth_tokens');
$mia = make_account(['email' => 'mia.stein@beispiel.test', 'username' => 'mia.stein', 'name' => 'Mia Stein']);
sign_out(); $freshIp();
$answer = submit('forgot', ['username' => ' Mia.Stein ']);
$jobs = rows("SELECT * FROM mail_jobs WHERE category='security'");
is_same([['mia.stein@beispiel.test', $mia]], array_map(fn($j) => [$j['recipient'], (int)$j['account_id']], $jobs),
        'by username: one mail, to the login’s own address');
$body = unseal((string)($jobs[0]['payload'] ?? ''));
ok(preg_match_all('/token=[a-f0-9]{64}/', $body) === 1, 'with one link');
is_same("Hallo Mia Stein,\n\nfür deinen Zugang wurde ein Link für ein neues Passwort angefordert.\n\nDein Benutzername: mia.stein\n"
    ."Anmelden kannst du dich damit oder mit dieser E-Mail-Adresse.\n\n"
    ."Weißt du dein Passwort noch, ist nichts zu tun – es gilt weiter.\nSonst leg hier ein neues fest (eine Stunde gültig):\n{link}\n\n"
    ."Warst du das nicht, kannst du diese E-Mail ignorieren.", (string)preg_replace('~https?://\S+token=[a-f0-9]{64}~', '{link}', $body),
    'the reset mail, one text whoever asked for it, saying the old password keeps working');
is_same('reset', (string)scalar('SELECT purpose FROM auth_tokens WHERE account_id=?', [$mia]), 'a reset link');
$flash = (string)($_SESSION['flash']['message'] ?? '');
run('DELETE FROM mail_jobs'); $freshIp();
submit('forgot', ['username' => 'MIA.STEIN@Beispiel.test']);
is_same(['mia.stein@beispiel.test'], array_column(rows("SELECT recipient FROM mail_jobs WHERE category='security'"), 'recipient'),
        'by address, in any capitals: the same mail to the same address');
foreach (['niemand@beispiel.test', 'niemand.hier'] as $unknown) {
    run('DELETE FROM mail_jobs'); $freshIp();
    is_same(['forgot', []], submit('forgot', ['username' => $unknown]), 'an unknown '.$unknown.' lands on the same page');
    is_same($flash, (string)($_SESSION['flash']['message'] ?? ''), 'with the same answer, word for word');
    is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs'), 'and nothing is sent');
}
ok(!str_contains($flash, 'mia.stein@') && str_contains($flash, 'Wenn es dazu einen Zugang gibt'), 'the answer names no address');

case_('„Vergessen“ sends an invited login its invitation again, a suspended one nothing, and nothing without mail');
$ida = make_account(['email' => 'ida.stein@beispiel.test', 'username' => 'ida.stein', 'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
$ole = make_account(['email' => 'ole.stein@beispiel.test', 'username' => 'ole.stein', 'state' => 'suspended']);
$stale = fixture('mail_jobs', ['account_id'=>$ida, 'recipient'=>'ida.stein@beispiel.test', 'subject'=>'Einladung', 'payload'=>seal('alt'),
                               'category'=>'security', 'status'=>'queued', 'attempts'=>0, 'created_at'=>now()]);
run('DELETE FROM mail_jobs WHERE id<>?', [$stale]); $freshIp();
submit('forgot', ['username' => 'ida.stein']);
is_same('invite', (string)scalar('SELECT purpose FROM auth_tokens WHERE account_id=?', [$ida]), 'the invited login gets an invitation, not a reset');
is_same(1, (int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE account_id=? AND status='queued'", [$ida]), 'one invitation waiting');
is_same('cancelled', (string)scalar('SELECT status FROM mail_jobs WHERE id=?', [$stale]), 'and the one before it is not sent as well');
run('DELETE FROM mail_jobs'); $freshIp();
submit('forgot', ['username' => 'ole.stein@beispiel.test']);
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs') + (int)scalar('SELECT COUNT(*) FROM auth_tokens WHERE account_id=?', [$ole]),
        'a suspended login gets nothing, and no link');
mail_ready(false); $freshIp();
submit('forgot', ['username' => 'mia.stein']);
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs'), 'without mail nothing is queued');
is_same($flash, (string)($_SESSION['flash']['message'] ?? ''), 'and the answer is the same');
mail_ready(true);
/* "To the stored address, never the typed one" can only be told apart where the
   two differ and still find each other: an address saved with capitals before
   everything was lower-cased, which the real collation matches and SQLite's
   byte comparison does not. */
if (test_driver() === 'sqlite') {
    test_unsupported(array_merge(test_unsupported(),
        ['„vergessen“ and sign-in finding an address stored with capitals, and mailing it as stored (needs the MySQL collation)']));
} else {
    $capitalised = make_account(['email' => 'Gross.Familie@Beispiel.test', 'username' => 'gross.kind']);
    $freshIp();
    submit('forgot', ['username' => 'gross.familie@beispiel.test']);
    is_same('Gross.Familie@Beispiel.test', (string)scalar("SELECT recipient FROM mail_jobs WHERE account_id=? AND category='security'", [$capitalised]),
            'a stored address with capitals gets its mail at the address as stored');
}

case_('The sender checks every link in the mail, and compares addresses as they are stored [S1, R7]');
$younger = make_account(['email' => 'tim.stein@beispiel.test', 'username' => 'tim.stein']);
$twoLinks = url('activate', ['token' => make_token($mia, 'reset')])."\n".url('activate', ['token' => make_token($younger, 'reset')]);
ok(!security_mail_links_live($twoLinks, 'mia.stein@beispiel.test'), 'a link belonging to another address stops the whole mail');
$oneLink = url('activate', ['token' => $miaToken = make_token($mia, 'reset')]);
ok(security_mail_links_live($oneLink, 'mia.stein@beispiel.test'), 'its own live link goes out');
make_token($mia, 'reset');
ok(!security_mail_links_live($oneLink, 'mia.stein@beispiel.test'), 'a link replaced since is stale and stops it');
ok(!security_mail_links_live('Hallo, kein Link', 'mia.stein@beispiel.test'), 'a security mail with no link is not sent');
$capitals = make_account(['email' => 'Lena@Example.at', 'username' => 'lena.gross']);
$link = url('activate', ['token' => make_token($capitals, 'reset')]);
ok(security_mail_links_live($link, 'lena@example.at'), 'an address stored with capitals still receives its reset link');
ok(!security_mail_links_live($link, 'jemand@example.at'), 'while a link to somebody else’s address is not sent');
run("UPDATE accounts SET state='suspended' WHERE id=?", [$capitals]);
ok(!security_mail_links_live($link, 'lena@example.at'), 'nor one for a suspended login');
$moving = make_account(['email' => 'alt@beispiel.test', 'username' => 'umzug']);
ok(security_mail_links_live(url('activate', ['token' => make_token($moving, 'email', 'neu@beispiel.test')]), 'neu@beispiel.test'),
   'a changed address is confirmed at the address it is changing to');

case_('„Vergessen“ looks up nothing that is not a plain address [M1]');
mail_ready(true);
/* Counted by the SQLite driver, which is the one that can count: elsewhere
   query_count() answers 0 for everything, and a nought would prove nothing.
   The plain address comes first, so the noughts after it are a measurement. */
if (test_driver() !== 'sqlite') {
    test_unsupported(array_merge(test_unsupported(), ['„vergessen“ sending no statement for a spelling that is not plain (counted by the sqlite driver)']));
} else {
    ok(query_count(fn() => act('forgot', ['username' => 'mia.stein@beispiel.test'])) > 0, 'a plain address is looked up');
    ok(query_count(fn() => act('forgot', ['username' => 'mia.stein'])) > 0, 'and so is a username');
    foreach (['"mia.stein"@beispiel.test', "mia.stein\x01@beispiel.test", 'mía.stein@beispiel.test', 'a..b', '1mia', 'mia_stein'] as $spelling)
        is_same(0, query_count(fn() => act('forgot', ['username' => $spelling])), 'and not a single statement is sent for '.json_encode($spelling));
}

case_('„Vergessen“ counts per typed name and per requester, and both on every request [S7]');
$freshIp();
$bucket = fn(string $name, string $identity) => (int)(run_counter('SELECT hits FROM rate_limits WHERE bucket=?', [rate_limit_bucket($name, $identity)])->fetchColumn() ?: 0);
throttle_clear('forgot', 'address:dreimal@beispiel.test');
for ($i = 0; $i < 3; $i++) submit('forgot', ['username' => 'dreimal@beispiel.test']);
throws(fn() => submit('forgot', ['username' => 'Dreimal@Beispiel.test']), 'the fourth request for one address in an hour is refused', 'Zu viele');
is_same(4, $bucket('forgot-ip', $ip), 'and it still counted against the requester');
throttle_clear('forgot', 'username:dreimal');
for ($i = 0; $i < 3; $i++) submit('forgot', ['username' => 'dreimal']);
throws(fn() => submit('forgot', ['username' => 'Dreimal']), 'and so is the fourth for one username', 'Zu viele');
is_same(4, $bucket('forgot', username_identity('dreimal')), 'counted under the username as typed, not under a login');
$freshIp();
for ($i = 0; $i < 10; $i++) submit('forgot', ['username' => 'anders'.$i.'@beispiel.test']);
throws(fn() => submit('forgot', ['username' => 'elftens@beispiel.test']), 'the eleventh from one requester is refused, whatever it names', 'Zu viele');
is_same(1, $bucket('forgot', address_identity('elftens@beispiel.test')), 'and still counted against that address');
$freshIp();

// ---------------------------------------------------------------------------
case_('A reset raises auth_version, is written down, and leaves no warning behind for the sign-in [R4 removed]');
/* ADR 0020, §8: with an address of one's own, the reader of the mailbox is the
   holder, so the red flash of 0019 R4 is gone, and with it the id it kept in the
   session. The audit entry stays: Mein Konto and the access card list it for
   PASSWORD_RESET_SHOWN_DAYS [I1, S4]. */
$holder = make_account(['username' => 'jana.kern', 'email' => 'kern@beispiel.test', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
sign_out(); set_setting('privacy_ready', true);
$before = (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [$holder]);
$_SESSION['activation_hash'] = hash('sha256', make_token($holder, 'reset'));
is_same('jana.kern', token_record($_SESSION['activation_hash'])['username'] ?? null, 'the page the link opens can read the username from the link [N10, I4]');
$freshIp();
submit('activate', ['password' => 'Federball-2026-Halle!', 'password_confirm' => 'Federball-2026-Halle!', 'username' => 'versuch.umzubenennen']);
is_same($before + 1, (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [$holder]), 'every other session on it has ended');
is_same('jana.kern', (string)scalar('SELECT username FROM accounts WHERE id=?', [$holder]), 'and a username posted to the reset page changes nothing [N10]');
$audited = one("SELECT * FROM audit_log WHERE action='account.password_reset' ORDER BY id DESC LIMIT 1");
is_same([$holder, $holder], [(int)($audited['entity_id'] ?? 0), (int)($audited['actor_id'] ?? 0)], 'the reset is in the audit log, done by the holder of the link');
is_same('Dein neues Passwort gilt ab sofort. Anmelden kannst du dich mit jana.kern oder mit kern@beispiel.test.', $_SESSION['flash']['message'] ?? null,
        'the sign-in it performs names both names that sign in');
ok(!array_key_exists('own_password_reset', $_SESSION), 'and keeps no id in the session');
sign_out(); $freshIp(); unset($_SESSION['flash']);
submit('login', ['username' => 'jana.kern', 'password' => 'Federball-2026-Halle!']);
is_same('', (string)($_SESSION['flash']['message'] ?? ''), 'the next sign-in is not warned about it');
is_same(14, PASSWORD_RESET_SHOWN_DAYS, 'Mein Konto and the access card list resets for two weeks [I1]');
fixture('audit_log', ['actor_id' => $holder, 'action' => 'account.password_reset', 'entity_type' => 'account',
    'entity_id' => $holder, 'created_at' => gmdate('Y-m-d H:i:s', time() - 15 * 86400)]);
is_same(1, count(password_resets_for($holder, PASSWORD_RESET_SHOWN_DAYS)), 'the one from today is listed, not the one from fifteen days ago');
sign_out();

// ---------------------------------------------------------------------------
case_('The change log never holds a password hash, a session counter or a last visit [R5, S6]');
sign_in_as($admin);
run("DELETE FROM record_versions");
$recorded = fn() => array_merge(...array_map(fn($v) => array_keys((array)json_decode((string)$v['before_json'], true) + (array)json_decode((string)$v['after_json'], true)),
    rows("SELECT before_json, after_json FROM record_versions WHERE entity='accounts'")));
$logged = tracked_insert('accounts', 'Neu', fn() => make_account(['username' => 'protokoll', 'last_seen_at' => now()]));
tracked('accounts', $logged, 'Neu', fn() => run("UPDATE accounts SET password_hash='x', auth_version=auth_version+1, last_seen_at=? WHERE id=?", [now(), $logged]));
tracked('accounts', $logged, 'Neu', fn() => run('DELETE FROM accounts WHERE id=?', [$logged]), 'delete');
is_same(3, (int)scalar("SELECT COUNT(*) FROM record_versions WHERE entity='accounts'"), 'an insert, an update and a delete were recorded');
is_same([], array_values(array_intersect($recorded(), ['password_hash', 'auth_version', 'last_seen_at'])), 'and none of them holds any of the three');
ok(in_array('username', $recorded(), true), 'while the rest of the row is there');

// ---------------------------------------------------------------------------
case_('Only the holder changes a username, with their password [N5, §8]');
$lisa = make_account(['username' => 'lisa.bauer', 'name' => 'Lisa Bauer', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
make_account(['username' => 'vergeben']);
sign_in_as($lisa);
run('DELETE FROM record_versions');
throws(fn() => act('username_change', ['current_password' => 'falsch', 'username' => 'lisa.b']), 'a wrong password is refused', 'Passwort nicht korrekt');
throws(fn() => act('username_change', ['password' => $password, 'username' => 'lisa.b']), 'the password is read from current_password, nothing else', 'Passwort nicht korrekt');
throws(fn() => act('username_change', ['current_password' => $password, 'username' => 'Lisa B']), 'a name outside the rule is refused, saying the rule', '3 bis 40');
act('username_change', ['current_password' => $password, 'username' => 'Lisa.Bauer']);
is_same(0, (int)scalar("SELECT COUNT(*) FROM record_versions"), 'the same name in capitals is no change, and writes nothing');
$_SESSION['impersonator_id'] = $admin;
throws(fn() => act('username_change', ['current_password' => $password, 'username' => 'lisa.b']), 'staff looking through her eyes cannot', 'Beende zuerst');
unset($_SESSION['impersonator_id']);
act('username_change', ['current_password' => $password, 'username' => 'Lisa.B']);
is_same('lisa.b', (string)scalar('SELECT username FROM accounts WHERE id=?', [$lisa]), 'she can, and it is stored the one way');
$line = one("SELECT * FROM record_versions WHERE entity='accounts' AND entity_id=?", [$lisa]);
is_same([['username' => 'lisa.bauer'], ['username' => 'lisa.b']], [json_decode((string)$line['before_json'], true), json_decode((string)$line['after_json'], true)],
        'the change log line holds the username and nothing else');
is_same(1, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.username_changed' AND entity_id=?", [$lisa]), 'and it is audited');
ok(str_contains((string)($_SESSION['flash']['message'] ?? ''), 'lisa.b'), 'the flash names the new one');
// The rules live in change_own_username(), which Mein Konto and the
// activation page both call (ADR 0020, §2).
$rules = (string)strstr((string)strstr((string)file_get_contents(APP_ROOT.'/app/actions.php'), 'function change_own_username('), 'function invite_student(', true);
ok(strpos($rules, 'lock_row(') !== false && strpos($rules, 'lock_row(') < (int)strpos($rules, 'tracked('),
   'the row is locked before tracked() takes its snapshot [S6]');
$settings = (string)strstr((string)strstr((string)file_get_contents(APP_ROOT.'/app/actions_settings.php'), "case 'username_change':"), "case 'password_change':", true);
ok(str_contains($settings, 'change_own_username($u,post(\'username\'))') && !str_contains($settings, 'UPDATE accounts SET username'),
   'and username_change writes the username only through it');

case_('A taken username is answered in one sentence, with a way out, wherever it is chosen');
is_same('Diesen Benutzernamen hat schon jemand. Such dir einen anderen aus, zum Beispiel mit einer Zahl am Ende.', username_taken_answer(),
        'the designer’s words (spec §4.5)');
foreach (['app/actions.php' => "case 'activate':", 'app/actions_settings.php' => "case 'username_change':"] as $file => $case)
    ok(str_contains((string)strstr((string)file_get_contents(APP_ROOT.'/'.$file), $case), "flash(username_taken_answer(),'error');"),
       $case.' gives that answer');

case_('A username that is taken is answered, not thrown, and at most five times a day [R3, S2]');
/* Only a "taken" answer counts, so the count comes after the lookup - inside a
   transaction that has already read. InnoDB lets the counter connection write
   then; SQLite in WAL mode does not let the first connection write afterwards
   (SQLITE_BUSY_SNAPSHOT), so this runs on MariaDB and MySQL only. */
if (test_driver() === 'sqlite') {
    test_unsupported(array_merge(test_unsupported(),
        ['a taken username answered, audited and throttled inside its transaction (SQLite cannot write after a second connection has; InnoDB can)']));
} else {
    throttle_clear('username-taken', account_identity($lisa));
    $answers = [];
    for ($i = 0; $i < 5; $i++) {
        unset($_SESSION['flash'], $_SESSION['form_input']);
        $answers[] = act('username_change', ['current_password' => $password, 'username' => 'Vergeben', 'return_page' => 'profile']);
    }
    is_same(array_fill(0, 5, ['profile', []]), $answers, 'five times the answer is a return to Mein Konto, not a refusal thrown');
    is_same('error', $_SESSION['flash']['kind'] ?? null, 'with the answer as an error flash');
    is_same('Diesen Benutzernamen hat schon jemand. Such dir einen anderen aus, zum Beispiel mit einer Zahl am Ende.', $_SESSION['flash']['message'] ?? null, 'saying the name is taken');
    is_same('Vergeben', $_SESSION['form_input']['fields']['username'] ?? null, 'the typed name is offered back');
    ok(!isset($_SESSION['form_input']['fields']['current_password']), 'and the password is not');
    is_same(5, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.username_taken' AND entity_id=?", [$lisa]),
            'each of the five stayed in the audit log, which a throw would have rolled back');
    throws(fn() => act('username_change', ['current_password' => $password, 'username' => 'vergeben']), 'the sixth in a day is throttled', 'Zu viele');
    is_same('lisa.b', (string)scalar('SELECT username FROM accounts WHERE id=?', [$lisa]), 'and she is still lisa.b');
}
sign_out();

case_('The invitation page lets the person choose their username, through the same rules [§2]');
sign_out(); set_setting('privacy_ready', true); mail_ready(true);
$newcomer = make_account(['username' => 'neu.ankunft', 'name' => 'Neu Ankunft', 'email' => 'ankunft@beispiel.test',
                          'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
$newKid = make_student(['first_name' => 'Neu', 'last_name' => 'Ankunft', 'email' => 'ankunft@beispiel.test', 'account_id' => $newcomer]);
$_SESSION['activation_hash'] = hash('sha256', make_token($newcomer, 'invite'));
// Somebody else is still signed in on this browser: they did not choose the name.
sign_in_as($trainer);
run('DELETE FROM record_versions'); $freshIp();
$setUp = ['password' => 'Federball-2026-Halle!', 'password_confirm' => 'Federball-2026-Halle!', 'privacy_seen' => '1', 'notifications' => '1'];
$landed = submit('activate', $setUp + ['username' => 'Neu.Ankunft-Lena']);
is_same('neu.ankunft-lena', (string)scalar('SELECT username FROM accounts WHERE id=?', [$newcomer]), 'the name typed on the page is the username, normalised');
$line = one("SELECT * FROM record_versions WHERE entity='accounts' AND entity_id=?", [$newcomer]);
is_same([['username' => 'neu.ankunft'], ['username' => 'neu.ankunft-lena']],
        [json_decode((string)($line['before_json'] ?? ''), true), json_decode((string)($line['after_json'] ?? ''), true)], 'the change is tracked');
is_same($newcomer, (int)($line['actor_id'] ?? 0), 'put down to the holder of the link, who chose it - not to whoever this browser was signed in as, nor to nobody');
is_same($newcomer, (int)scalar("SELECT actor_id FROM audit_log WHERE action='account.username_changed' AND entity_id=? ORDER BY id DESC LIMIT 1", [$newcomer]),
        'and so is the audit entry');
is_same('active', (string)scalar('SELECT state FROM accounts WHERE id=?', [$newcomer]), 'the login is set up');
is_same('Dein Konto ist bereit. Anmelden kannst du dich mit neu.ankunft-lena oder mit ankunft@beispiel.test.', $_SESSION['flash']['message'] ?? null,
   'and told both names that sign in');
is_same(['student', ['id' => $newKid]], $landed, 'a family with details still to fill in lands on their own page [§7]');
sign_out();
$complete = make_account(['username' => 'fertig.kind', 'email' => 'fertig@beispiel.test', 'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
$completeKid = make_student(['first_name' => 'Fertig', 'last_name' => 'Kind', 'email' => 'fertig@beispiel.test', 'account_id' => $complete,
                             'birth_date' => '2015-03-04', 'address' => 'Hauptstraße 1, 1010 Wien']);
fixture('contacts', ['student_id' => $completeKid, 'owner_name' => 'Mutter', 'relation_label' => 'Mutter', 'phone' => '1', 'email' => '', 'is_primary' => 1]);
$_SESSION['activation_hash'] = hash('sha256', make_token($complete, 'invite')); $freshIp();
is_same(['dashboard', []], submit('activate', $setUp + ['username' => 'fertig.kind']), 'one with nothing missing lands where every sign-in does');
sign_out();
if (test_driver() === 'sqlite') {
    test_unsupported(array_merge(test_unsupported(),
        ['a taken username on the invitation page, answered and audited inside its transaction (SQLite cannot write after a second connection has; InnoDB can)']));
} else {
    $late = make_account(['username' => 'spaet.dran', 'email' => 'spaet@beispiel.test', 'state' => 'invited', 'verified_at' => null, 'password_hash' => null]);
    throttle_clear('username-taken', account_identity($late));
    $_SESSION['activation_hash'] = hash('sha256', make_token($late, 'invite')); $freshIp();
    unset($_SESSION['form_input']);
    $_SESSION['impersonator_id'] = $trainer;   // a view left over on this browser
    is_same(['activate', []], submit('activate', $setUp + ['username' => 'neu.ankunft-lena']), 'a taken name comes back to the page');
    ok(!isset($_SESSION['impersonator_id']), 'with no view left over, though nobody is signed in afterwards (F1)');
    is_same($late, (int)scalar("SELECT actor_id FROM audit_log WHERE action='account.username_taken' AND entity_id=? ORDER BY id DESC LIMIT 1", [$late]),
            'and the attempt put down to the holder of the link');
    is_same(['invited', null], [scalar('SELECT state FROM accounts WHERE id=?', [$late]), scalar('SELECT password_hash FROM accounts WHERE id=?', [$late])],
            'having activated nothing');
    is_same(1, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='account.username_taken' AND entity_id=?", [$late]), 'with the attempt in the audit log [R3]');
    is_same('Diesen Benutzernamen hat schon jemand. Such dir einen anderen aus, zum Beispiel mit einer Zahl am Ende.', $_SESSION['flash']['message'] ?? null, 'and the answer said');
    is_same('neu.ankunft-lena', $_SESSION['form_input']['fields']['username'] ?? null, 'the typed name is offered back');
    ok(!isset($_SESSION['form_input']['fields']['password']) && !isset($_SESSION['form_input']['fields']['password_confirm']), 'and the passwords are not');
    ok(token_record((string)$_SESSION['activation_hash']) !== null, 'the link still works for the next try');
    sign_out();
}

case_('A changed address is asked for without saying whether it is taken, and refused when the link is opened [§1]');
/* Asking would tell a signed-in family whether an address has a login - half a
   credential, now that the address signs in. Refusing at confirmation tells
   only the reader of that mailbox, who knows already. */
sign_in_as($lisa);
mail_ready(true);
throttle_clear('email-change', (string)$lisa);
$lisaAddress = (string)scalar('SELECT email FROM accounts WHERE id=?', [$lisa]);
does_not_throw(fn() => act('email_change', ['password' => $password, 'email' => 'Trainerin@Beispiel.test']),
               'a trainer’s address is asked for like any other');
is_same('trainerin@beispiel.test', (string)scalar("SELECT target_email FROM auth_tokens WHERE account_id=? AND purpose='email'", [$lisa]),
        'with the confirmation link on its way to it, where only the trainer reads it');
ok(!str_contains((string)($_SESSION['flash']['message'] ?? ''), 'gehört'), 'and the answer says nothing about whose it is');
$_SESSION['activation_hash'] = hash('sha256', make_token($lisa, 'email', 'trainerin@beispiel.test'));
throws(fn() => act('activate', []), 'opening the link is refused', 'Jede Person braucht ihre eigene');
is_same($lisaAddress, (string)scalar('SELECT email FROM accounts WHERE id=?', [$lisa]), 'and her address is unchanged');
sign_out();

// ---------------------------------------------------------------------------
case_('Existing logins are given a username from their names, oldest first, once [§4]');
$named = make_account(['username' => 'anna.berg', 'name' => 'Anna Berg']);
$placeholder = function (array $row): int {
    $id = make_account(['username' => 'zwischen.'.bin2hex(random_bytes(3))] + $row);
    run('UPDATE accounts SET username=? WHERE id=?', ['#'.$id, $id]);
    return $id;
};
$annaStudent = $placeholder(['name' => 'Familie Berg']);
make_student(['first_name' => 'Anna', 'last_name' => 'Berg', 'account_id' => $annaStudent]);
$annaStaff = $placeholder(['name' => 'Anna Berg', 'role' => 'trainer']);
$kurt = make_account(['username' => '', 'name' => 'Kurt Novák']);
$countBefore = (int)scalar('SELECT COUNT(*) FROM accounts');
is_same(3, give_every_account_a_username(), 'three logins without one are named');
is_same(['anna.berg', 'anna.berg2', 'anna.berg3', 'kurt.novak'],
        [scalar('SELECT username FROM accounts WHERE id=?', [$named]), scalar('SELECT username FROM accounts WHERE id=?', [$annaStudent]),
         scalar('SELECT username FROM accounts WHERE id=?', [$annaStaff]), scalar('SELECT username FROM accounts WHERE id=?', [$kurt])],
        'a student’s login after the student, anyone else after their own name, the older one keeping the lower number');
is_same([], array_values(array_filter(array_column(rows('SELECT username FROM accounts'), 'username'), fn($u) => !preg_match(USERNAME_PATTERN, (string)$u))),
        'every login now has a username that can sign in');
is_same(0, give_every_account_a_username(), 'a second run finds nothing to do');
is_same($countBefore, (int)scalar('SELECT COUNT(*) FROM accounts'), 'and no login was added or lost');

// ---------------------------------------------------------------------------
case_('The example data makes its logins the way everybody else’s are made [R1, §11]');
test_reset();
$realAdmin = make_account(['role' => 'admin', 'username' => 'chefin']);
sign_in_as($realAdmin);
make_student(['first_name' => 'Echt', 'last_name' => 'Kind']);
make_account(['username' => 'lena.hofer', 'email' => 'echte.lena@beispiel.test']);
$shared = make_account(['username' => 'fremd', 'email' => 'trainerin@beispiel.test']);
throws(fn() => demo_fill(true), 'a fill whose trainer would take an address another login has is refused', 'Jede Person braucht ihre eigene');
is_same(0, (int)scalar('SELECT COUNT(*) FROM accounts WHERE is_demo=1'), 'and wrote nothing');
run('DELETE FROM accounts WHERE id=?', [$shared]);
$filled = demo_fill(true);
is_same(['trainerin.beispiel', 'lena.hofer2', 'jonas.berger'], array_column($filled['logins'], 'username'),
        'beside a real lena.hofer, the example Lena is lena.hofer2 - which is why the usernames are handed back');
is_same($filled['logins'], demo_logins(), 'and read back from the database for the pages that show them');
$console = (string)file_get_contents(APP_ROOT.'/bin/console.php');
$setup = (string)file_get_contents(APP_ROOT.'/public/setup.php');
ok(str_contains($console, "\$login['username']") && !str_contains($console, 'familie.hofer@beispiel.test'), 'the console prints those usernames, not addresses');
ok(str_contains($setup, "\$login['username']") && !str_contains($setup, 'familie.hofer@beispiel.test'), 'and so does the setup page');
ok(str_contains($setup, 'install_e($adminUsername)') && str_contains($console, "\$made['username']"),
   'both show the first administrator the username nobody typed');
