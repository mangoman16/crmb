<?php
/** The shell: what is waiting, who you are, what it looks like, and what broke. */
$admin   = make_account(['role'=>'admin',   'name'=>'Admin Person']);
$trainer = make_account(['role'=>'trainer', 'name'=>'Trainerin Beispiel']);
$family  = make_account(['role'=>'student', 'name'=>'Familie Hofer']);
sign_in_as($admin);

case_('Notifications arrive, are counted, and point somewhere');
is_same(0, unread_notifications($family), 'nothing to begin with');
notify($family, 'payment', 'Ein Beitrag ist offen', '45,00 € bis 8. September', 'student', ['id'=>7, 'tab'=>'payments']);
is_same(1, unread_notifications($family), 'one waiting');
$note = notifications_for($family)[0];
is_same('Ein Beitrag ist offen', $note['title'], 'with the title it was given');
ok(str_contains(notification_link($note), 'page=student'), 'the link points at the page');
ok(str_contains(notification_link($note), 'id=7'), 'and carries what it needs to get there');
ok(str_contains(notification_link($note), 'tab=payments'), 'including the tab');

case_('A notification that has lost its page still goes somewhere');
notify($family, 'info', 'Ohne Ziel');
ok(str_contains(notification_link(notifications_for($family)[0]), 'page=dashboard'), 'the overview, rather than nowhere');

case_('Reading them is per account');
sign_in_as($family);
act('notifications_read', ['id'=>(string)$note['id']]);
is_same(1, unread_notifications($family), 'only the one just read is read');
act('notifications_read', []);
is_same(0, unread_notifications($family), 'and reading all clears the rest');
notify($admin, 'info', 'Für den Administrator');
sign_in_as($family);
act('notifications_read', []);
is_same(1, unread_notifications($admin), 'one account reading does not clear another’s');

case_('Telling every member of staff reaches staff and nobody else');
$before = unread_notifications($family);
is_same(2, notify_staff('request', 'Eine Anfrage wartet'), 'both the admin and the trainer');
is_same($before, unread_notifications($family), 'and no family');

// ---------------------------------------------------------------------------
case_('An accent is the person’s own, or the administrator’s, or the shipped one');
set_setting('default_accent', 'violet');
is_same('violet', accent_for(['accent'=>'']), 'nobody has chosen, so the administrator’s applies');
is_same('pink', accent_for(['accent'=>'pink']), 'their own wins');
is_same('violet', accent_for(['accent'=>'erfunden']), 'an accent that no longer exists falls back');
is_same('violet', accent_for(null), 'and a signed-out page still has a colour');
set_setting('default_accent', 'nicht-echt');
is_same('teal', accent_for(['accent'=>'']), 'a broken default falls back to the shipped one');
set_setting('default_accent', 'teal');
$css = (string)file_get_contents(APP_ROOT.'/public/assets/app.css');
foreach (accents() as $key => $label) {
    ok($label !== '', $key.' has a name a person can read');
    // The dot is coloured by the stylesheet, because the portal's own
    // Content-Security-Policy refuses a style attribute - which is how all
    // eight of them came to render grey.
    ok(str_contains($css, '.accent-dot.accent-'.$key.'{background:#'), $key.' has a colour in the stylesheet');
}
ok(!str_contains((string)file_get_contents(APP_ROOT.'/views/profile.php'), 'style="'),
   'and the picker sets no inline style, which the browser would refuse anyway');

case_('A picture replaces the initials, and initials are never more than two');
is_same('LH', initials('Lena Hofer'), 'two names');
is_same('L', initials('Lena'), 'one name');
is_same('LH', initials('Lena Maria Hofer'), 'the first and the last, not all three');
is_same('?', initials('   '), 'and a blank name still renders something');
ok(str_contains(avatar(['name'=>'Lena Hofer', 'avatar_name'=>'']), 'LH'), 'no picture, so the initials');
// A name of the shape store_upload() gives, so the version is what it would be.
$stored = str_repeat('c0ffee', 5).'ab.jpg';
$withPhoto = avatar(['id'=>3, 'name'=>'Lena Hofer', 'avatar_name'=>$stored], '', 'student');
ok(str_contains($withPhoto, '<img'), 'a picture when there is one');
ok(str_contains($withPhoto, 'what=avatar'), 'served through the download route, not by URL');
ok(!str_contains($withPhoto, $stored), 'and the stored name is never in the page');
ok(str_contains($withPhoto, 'v=c0ffeec0ffee'),
   'the address names the picture, so the browser may keep it until a new one is uploaded');
$replaced = avatar(['id'=>3, 'name'=>'Lena Hofer', 'avatar_name'=>str_repeat('d', 32).'.jpg'], '', 'student');
ok(str_contains($replaced, 'v=dddddddddddd'), 'a new picture of the same child gets a new address, so the old one is never shown');
ok(str_contains($withPhoto, 'loading="lazy"'), 'a picture in a list of people waits until it is scrolled to');
sign_in_as($family);
$topBar = avatar(['id'=>$family, 'role'=>'student', 'name'=>'Familie Hofer', 'avatar_name'=>$stored], 'tiny');
ok(str_contains($topBar, '<img'), 'her own picture in the top bar');
ok(!str_contains($topBar, 'loading="lazy"'), 'and, in view on every page, fetched at once');

case_('An account’s picture is drawn only for somebody who may see it');
/* The download route answers 404 to anybody else; drawing the <img> anyway
   would put a broken picture on the page instead of the initials. */
$otherFamily = ['id'=>$family + 1000, 'role'=>'student', 'name'=>'Familie Gruber', 'avatar_name'=>$stored];
$trainerRow = ['id'=>$trainer, 'role'=>'trainer', 'name'=>'Trainerin Beispiel', 'avatar_name'=>$stored];
sign_in_as($family);
is_same('<span class="avatar">FG</span>', avatar($otherFamily), 'a family sees another family’s initials, not their photograph');
ok(str_contains(avatar($trainerRow), '<img'), 'and the trainer’s picture');
ok(!str_contains(avatar(['role'=>null] + $trainerRow), '<img'), 'but not from a row that does not say it is the trainer’s');
sign_in_as($trainer);
ok(str_contains(avatar($otherFamily), '<img'), 'the trainer sees every family’s');
sign_out();
ok(!str_contains(avatar($trainerRow), '<img'), 'and nobody signed out sees any');
sign_in_as($family);

// ---------------------------------------------------------------------------
case_('Who may look through whose eyes');
$otherTrainer = one('SELECT * FROM accounts WHERE id=?', [make_account(['role'=>'trainer'])]);
$adminRow = one('SELECT * FROM accounts WHERE id=?', [$admin]);
$trainerRow = one('SELECT * FROM accounts WHERE id=?', [$trainer]);
$familyRow = one('SELECT * FROM accounts WHERE id=?', [$family]);
is_same(true,  may_impersonate($adminRow, $familyRow),   'an administrator may look at a family');
is_same(true,  may_impersonate($adminRow, $trainerRow),  'and at a trainer');
is_same(true,  may_impersonate($trainerRow, $familyRow), 'a trainer may look at a family');
is_same(false, may_impersonate($trainerRow, $adminRow),  'but never at an administrator');
is_same(false, may_impersonate($trainerRow, $otherTrainer), 'nor at another trainer');
is_same(false, may_impersonate($adminRow, $adminRow),    'and nobody at themselves');
run("UPDATE accounts SET state='suspended' WHERE id=?", [$family]);
is_same(false, may_impersonate($adminRow, one('SELECT * FROM accounts WHERE id=?', [$family])), 'nor at a suspended account');
run("UPDATE accounts SET state='active' WHERE id=?", [$family]);
// Asked of an open view on every request too (impersonator()), where the one
// looking may have been suspended since.
is_same(false, may_impersonate(['state'=>'suspended'] + $adminRow, $familyRow), 'and nobody whose own login is suspended looks at anybody');

case_('Looking, and getting back out');
sign_in_as($trainer);
is_same(null, impersonator(), 'nobody is being impersonated');
act('impersonate', ['id'=>(string)$family, 'mode'=>'start']);
is_same($family, (int)current_user()['id'], 'the session is now theirs');
is_same($trainer, (int)impersonator()['id'], 'and the real person is remembered');
is_same(false, is_staff(current_user()), 'with the family’s rights, not the trainer’s');
act('impersonate', ['mode'=>'stop']);
is_same($trainer, (int)current_user()['id'], 'and back out again');
is_same(null, impersonator(), 'with nothing left behind');

case_('Impersonation cannot be used to gain rights');
sign_in_as($trainer);
throws(fn() => act('impersonate', ['id'=>(string)$admin, 'mode'=>'start']),
       'a trainer cannot become an administrator', 'nicht ansehen');
is_same($trainer, (int)current_user()['id'], 'and is still themselves');
sign_in_as($family);
throws(fn() => act('impersonate', ['id'=>(string)$trainer, 'mode'=>'start']),
       'a family cannot impersonate at all', 'Kein Zugriff');

case_('What is done while impersonating is recorded against the real person');
sign_in_as($trainer);
act('impersonate', ['id'=>(string)$family, 'mode'=>'start']);
audit('test.action', 'account', $family);
is_same($trainer, (int)scalar('SELECT actor_id FROM audit_log ORDER BY id DESC LIMIT 1'),
        'the trainer, not the account they are borrowing');
act('impersonate', ['mode'=>'stop']);

case_('Viewing as somebody is looking: nothing goes through but the view’s end');
/* Every action would speak as the person looked at. An administrator viewing a
   trainer's portal could save a child, report a problem or give a consent in
   the trainer's name - or start a view as the trainer, which „Ansicht beenden"
   then turned into the trainer's own session, without her password. */
$looked = make_student(['first_name'=>'Angesehen', 'last_name'=>'Kind']);
sign_in_as($admin);
act('impersonate', ['id'=>(string)$trainer, 'mode'=>'start']);
$untouched = fn(): array => [one('SELECT name,newsletter FROM accounts WHERE id=?', [$trainer]), (int)scalar('SELECT COUNT(*) FROM feedback'),
    (int)scalar('SELECT COUNT(*) FROM consent_log'), (int)scalar('SELECT revision FROM students WHERE id=?', [$looked])];
$before = $untouched();
foreach (['preferences_save' => ['name'=>'Anders', 'locale'=>'de', 'newsletter'=>'1'],
          'feedback_send'    => ['message'=>'Im Namen der Trainerin', 'page'=>'dashboard'],
          'student_save'     => ['id'=>(string)$looked, 'revision'=>'1', 'first_name'=>'Umbenannt', 'last_name'=>'Kind', 'status'=>'active'],
          'impersonate'      => ['id'=>(string)$family, 'mode'=>'start']] as $action => $fields)
    throws(fn() => act($action, $fields), $action.' is refused while viewing, in the one sentence', viewing_refusal());
is_same($before, $untouched(), 'and nothing was written: no consent, no report, no change to the child');
is_same([$trainer, $admin], [(int)current_user()['id'], (int)(impersonator()['id'] ?? 0)], 'the view is still the administrator’s, of the trainer');
act('impersonate', ['mode'=>'stop']);
is_same([$admin, null], [(int)current_user()['id'], impersonator()], 'stopping it goes through, back to the administrator herself');
act('impersonate', ['id'=>(string)$trainer, 'mode'=>'start']);
act('logout', []);
is_same([null, null], [current_user(), impersonator()], 'and so does signing out');

case_('A view that timed out is not handed to whoever signs in next on that browser');
/* Security review F1. The trainer's „Portal als … ansehen" session expired; the
   impersonator id was left in the session; the next person to sign in on that
   phone got „Ansicht beenden" and, with one tap, became the trainer. Ending a
   session - by time, by a changed password, by signing in, by an invitation
   accepted - ends the view with it, and stopping a view needs somebody signed
   in to stop it for. */
$visitor = make_account(['email'=>'naechste@beispiel.test',
                         'password_hash'=>password_hash('Federball-2026-Halle!', PASSWORD_DEFAULT)]);
sign_in_as($trainer);
act('impersonate', ['id'=>(string)$family, 'mode'=>'start']);
$_SESSION['last_seen'] = time() - (int)config('session_idle_minutes') * 60 - 5;
is_same(null, current_user(true), 'the view times out like any session');
ok(!isset($_SESSION['impersonator_id']), 'and the session forgets the real person with it');
is_same(null, impersonator(), 'so nobody is being viewed');
throws(fn() => act('impersonate', ['mode'=>'stop']), 'so nobody can stop it into the trainer’s account', 'nicht als jemand anderer');
is_same(null, current_user(), 'and nobody is signed in');
$_SESSION['impersonator_id'] = $trainer;   // as a session written before this fix may still hold it
audit('test.left_over', 'account', $family);
is_same(null, scalar("SELECT actor_id FROM audit_log WHERE action='test.left_over' ORDER BY id DESC LIMIT 1"),
        'what happens with nobody signed in is put down to nobody, never to a view left over');
$_SESSION['impersonator_id'] = $trainer;
$line = history_record('accounts', $family, 'update', 'x', ['name'=>'a'], ['name'=>'b']);
is_same(null, scalar('SELECT actor_id FROM record_versions WHERE id=?', [$line]), 'and so is a change-log line');
$_SESSION['impersonator_id'] = $trainer;
current_user(true);
ok(!isset($_SESSION['impersonator_id']), 'asking who is signed in, with nobody, drops the id left over');
$_SESSION['impersonator_id'] = $trainer;
stop_impersonation();
is_same(null, current_user(), 'stop_impersonation() itself signs nobody in from a view that has ended');
$_SESSION['impersonator_id'] = $trainer;
throws(fn() => act('impersonate', ['mode'=>'stop']), 'a stop with nobody signed in is refused even with an id left over', 'nicht als jemand anderer');
is_same(null, current_user(), 'and signs nobody in');
is_same(null, impersonator(), 'and asking who is signed in forgets the id left over');
throttle_clear('auth-ip', $_SERVER['REMOTE_ADDR'] ?? 'local');
// Left over in the raw session, where only sign_in() itself can drop it:
// nothing asks who is signed in before the sign-in does.
$_SESSION['impersonator_id'] = $trainer;
submit('login', ['login'=>'naechste@beispiel.test', 'password'=>'Federball-2026-Halle!']);
is_same($visitor, (int)(current_user()['id'] ?? 0), 'the next person signs in as themselves');
ok(!isset($_SESSION['impersonator_id']), 'into a session that carries no view of anybody');
is_same(null, impersonator(), 'with no view of anybody else’s left over');
throws(fn() => act('impersonate', ['mode'=>'stop']), 'and „Ansicht beenden“ does not make them the trainer', 'nicht als jemand anderer');
is_same($visitor, (int)current_user()['id'], 'they are still themselves');
sign_out();
$invited = make_account(['email'=>'neu.eingeladen@beispiel.test', 'state'=>'invited', 'verified_at'=>null, 'password_hash'=>null]);
make_student(['first_name'=>'Neu', 'last_name'=>'Eingeladen', 'email'=>'neu.eingeladen@beispiel.test', 'account_id'=>$invited]);
set_setting('privacy_ready', true);
$_SESSION['impersonator_id'] = $trainer;
$_SESSION['activation_hash'] = hash('sha256', make_token($invited, 'invite'));
submit('activate', ['password'=>'Federball-2026-Halle!', 'password_confirm'=>'Federball-2026-Halle!', 'privacy_seen'=>'1']);
is_same([$invited, null], [(int)(current_user()['id'] ?? 0), impersonator()], 'accepting an invitation on that browser leaves no view behind either');
sign_out();

case_('Stopping when nothing is being impersonated says so rather than failing');
throws(fn() => act('impersonate', ['mode'=>'stop']), 'there is nothing to stop', 'nicht als jemand anderer');

case_('Signing out ends it, whatever else happens');
sign_in_as($trainer);
act('impersonate', ['id'=>(string)$family, 'mode'=>'start']);
act('logout', []);
is_same(null, impersonator(), 'nothing of it survives');
is_same(null, current_user(), 'and nobody is signed in');

case_('A view lasts as long as the viewer’s own login, and takes its whole session with it');
/* Security re-review N1. impersonator() answered nobody once the viewer's row
   was gone, and everything that limits a view asks it - the read-only guard,
   the chat's narrowing, the bar at the top - so a trainer whose login was
   deleted while she looked through a child's eyes kept the child's session,
   with no bar and no limits: the child's private chats to read, and the child's
   name to act in. Her row is asked on every request now: still there, still
   allowed to look, and signed in with the auth_version the view began with.
   Each way her login ends breaks one of those, and ends the session. */
$looked = make_account(['role'=>'student', 'name'=>'Familie Angesehen']);
// What another phone does meanwhile, in a session of its own; this phone's is
// put back afterwards, as the next request finds it.
$onAnotherPhone = function (int $who, string $action, array $fields): void {
    $thisPhone = $_SESSION;
    sign_in_as($who);
    act($action, $fields);
    $_SESSION = $thisPhone;
};
foreach (['an administrator deletes her login'        => fn(array $viewer) => $onAnotherPhone($admin, 'account_state',
                                                             ['id'=>(string)$viewer['id'], 'mode'=>'delete', 'confirmation'=>$viewer['email']]),
          'an administrator suspends it'              => fn(array $viewer) => $onAnotherPhone($admin, 'account_state',
                                                             ['id'=>(string)$viewer['id'], 'mode'=>'suspend']),
          'she changes her password on her own phone' => fn(array $viewer) => $onAnotherPhone((int)$viewer['id'], 'password_change',
                                                             ['current_password'=>'Test-Only-Password-2026', 'password'=>'Neues-Passwort-2026!', 'password_confirm'=>'Neues-Passwort-2026!'])]
         as $what => $ending) {
    $viewer = one('SELECT * FROM accounts WHERE id=?', [make_account(['role'=>'trainer', 'name'=>'Trainerin auf Zeit'])]);
    view_as((int)$viewer['id'], $looked);
    is_same([$looked, (int)$viewer['id']], [(int)(current_user()['id'] ?? 0), (int)(impersonator()['id'] ?? 0)], $what.': first she looks through the child’s eyes');
    $ending($viewer);
    is_same(null, current_user(true), $what.': her phone’s next request finds nobody signed in, so nothing is drawn for the child');
    ok(!isset($_SESSION['user_id']) && !isset($_SESSION['auth_version']) && !isset($_SESSION['impersonator_id']),
       $what.': the session holds neither the child nor the view');
    is_same(null, impersonator(), $what.': and nobody is looking');
}

// ---------------------------------------------------------------------------
case_('A report carries what nobody would think to write down');
sign_in_as($family);
$GLOBALS['page'] = 'payments';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X)';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
act('feedback_send', ['message'=>'Der Knopf tut nichts.', 'page'=>'payments', 'return_page'=>'payments']);
$report = open_feedback()[0];
is_same('Der Knopf tut nichts.', $report['message'], 'what they said');
is_same('payments', $report['page'], 'where they were');
is_same('new', $report['state'], 'and it is waiting');
$context = json_decode((string)$report['context_json'], true);
ok(str_contains((string)$context['user_agent'], 'iPhone'), 'the device, without asking them');
is_same('203.0.113.9', $context['ip'], 'the address it came from');
is_same(app_version(), $context['version'], 'and which version of the portal');
unset($GLOBALS['page']);

case_('And the administrator is told, because nobody watches a table');
ok(unread_notifications($admin) > 0, 'a notification went to the administrator');

case_('After a report they are back on the page they reported from');
/* Found by tests/e2e.sh at fd0d179: a family reporting from their child's
   „Beiträge“ tab was sent to ?page=student with no id, and read „Danke! Die
   Meldung ist angekommen.“ above „Kein Zugriff“. Every page that needs an id -
   a child, a course, a thread - did the same. The form carries return_id and
   return_tab like every start_form(); the redirect has to use them. */
sign_in_as($family);
[$target, $params] = act('feedback_send', ['message'=>'Der Beleg ist weg.', 'page'=>'student',
    'return_page'=>'student', 'return_id'=>'7', 'return_tab'=>'payments']);
is_same('student', $target, 'the same page');
is_same(7, (int)($params['id'] ?? 0), 'the same child, not a page without one');
is_same('payments', $params['tab'] ?? '', 'and the same tab');
[$target, $params] = act('feedback_send', ['message'=>'Die Liste ist leer.', 'page'=>'news',
    'return_page'=>'news', 'return_id'=>'0', 'return_tab'=>'']);
is_same(['news', []], [$target, $params], 'a page without an id gets none made up for it');
sign_in_as($admin);
run("UPDATE feedback SET state='done' WHERE message IN ('Der Beleg ist weg.','Die Liste ist leer.')");

case_('Reports can be worked through');
is_same(1, unread_feedback(), 'one is new');
sign_in_as($admin);
act('feedback_state', ['id'=>(string)$report['id'], 'state'=>'done']);
is_same(0, unread_feedback(), 'and marking it done clears it');
throws(fn() => act('feedback_state', ['id'=>(string)$report['id'], 'state'=>'erfunden']),
       'an invented state is refused', 'Ungültige Auswahl');

case_('Only an administrator sorts through them');
sign_in_as($trainer);
throws(fn() => act('feedback_state', ['id'=>(string)$report['id'], 'state'=>'seen']),
       'a trainer does not', 'Administratoren');

case_('The upload limit never promises more than the server will take');
ok(upload_limit() > 0, 'there is a limit');
ok(upload_limit() <= max(1, (int)setting('upload_max_kb')) * 1024, 'never above what the operator asked for');
$phpLimit = min(array_filter([ini_bytes((string)ini_get('upload_max_filesize')),
                              ini_bytes((string)ini_get('post_max_size')) - 64 * 1024]) ?: [PHP_INT_MAX]);
ok(upload_limit() <= $phpLimit, 'and never above what PHP will accept');
ok(upload_limit_label() !== '', 'and it can be said out loud');
is_same(8 * 1024 * 1024, ini_bytes('8M'), 'a php.ini shorthand in megabytes');
is_same(512 * 1024, ini_bytes('512K'), 'in kilobytes');
is_same(2 * 1024 ** 3, ini_bytes('2G'), 'in gigabytes');
is_same(4096, ini_bytes('4096'), 'and plain bytes');

case_('A message to the trainer reaches the trainer');
// The staff list once said role IN ('admin','manager'). 'manager' is what
// trainers were called before 0.2, so a family writing in reached the
// administrator and nobody else. Now a family writes to a person (ADR 0022):
// the one written to is told, and nobody else's bell rings for it.
sign_in_as($family);
$beforeTrainer = unread_notifications($trainer);
$beforeAdmin = unread_notifications($admin);
act('message_send', ['to'=>(string)$trainer, 'body'=>'Welchen Schläger sollen wir kaufen?']);
is_same($beforeTrainer + 1, unread_notifications($trainer), 'the trainer is told');
is_same($beforeAdmin, unread_notifications($admin), 'and the administrator is not: it was written to the trainer');

case_('And her reply reaches the family');
sign_in_as($trainer);
$thread = pair_thread($family, $trainer);
$beforeFamily = unread_notifications($family);
act('message_send', ['thread_id'=>(string)$thread, 'body'=>'Ich bringe zwei mit.']);
is_same($beforeFamily + 1, unread_notifications($family), 'the family is told');

// ---------------------------------------------------------------------------
case_('The main menu is one flat list for each kind of person');
/* ADR 0011: seven entries and no sections. A section hid what it held, and a list
   of seven fits on a phone. Everything that lost its entry is reached from the
   entry it belongs to - the case after next holds that for every page. */
function menu_routes(array $user): array { return array_column(nav_entries($user), 'route'); }
$adminUser = one('SELECT * FROM accounts WHERE id=?', [$admin]);
$trainerUser = one('SELECT * FROM accounts WHERE id=?', [$trainer]);
$familyUser = one('SELECT * FROM accounts WHERE id=?', [$family]);
$setupShown = function (bool $shown): void { set_setting('setup_hidden', !$shown); setup_cache_clear(); };

$setupShown(true);
ok(setup_unfinished(), 'a portal with nothing set up has its checklist unfinished');
is_same(['start','dashboard','students','classes','attendance','payments','messages','settings'], menu_routes($adminUser),
        'an administrator sees „Einrichtung" first while it is unfinished, then the seven');
$setupShown(false);
is_same(['dashboard','students','classes','attendance','payments','messages','settings'], menu_routes($adminUser),
        'and the seven alone once it is hidden');
is_same([t('Übersicht','Overview'), t('Schüler','Students'), t('Kurse','Courses'), t('Anwesenheit','Attendance'),
         t('Geld','Money'), t('Nachrichten','Messages'), t('Einstellungen','Settings')],
        array_column(nav_entries($adminUser), 'label'), 'named the way the portal names them');
is_same(['dashboard','students','classes','attendance','payments','messages','manage'], menu_routes($trainerUser),
        'a trainer\'s seventh is Verwaltung, the page she can open, not Einstellungen');
foreach (['history','settings','start'] as $route)
    ok(!in_array($route, menu_routes($trainerUser), true), 'a trainer is not offered '.$route.', which the router refuses her');
foreach ([$adminUser, $trainerUser, $familyUser] as $who)
    foreach (nav_entries($who) as $entry)
        ok(isset($entry['route']) && !isset($entry['items']), 'nothing is folded into a section: '.$entry['label']);
ok(!str_contains(sidebar_nav($adminUser, 'attendance'), 'nav-section'), 'and the side menu draws no section at all');
is_same(array_values(array_unique(menu_routes($adminUser))), menu_routes($adminUser), 'no destination is offered twice');

// One login is one student (ADR 0010): the family's second entry is their own
// student's page, called „Profil", and a login no student points to has no
// record to show, so it gets no entry rather than one that leads nowhere.
is_same(0, (int)scalar('SELECT COUNT(*) FROM students WHERE account_id=?', [$family]), 'this family login has no student');
is_same(['dashboard','messages','news'], menu_routes($familyUser), 'so it sees three pages');
is_same(null, people_nav_entry($familyUser), 'and no „Profil" entry that would lead nowhere');
$ownLogin = make_account(['role'=>'student', 'name'=>'Familie Profil']);
$ownStudent = make_student(['first_name'=>'Pia', 'last_name'=>'Profil', 'account_id'=>$ownLogin, 'email'=>'profil@example.test']);
$otherStudent = make_student(['first_name'=>'Nicht', 'last_name'=>'Ihres']);
$ownUser = one('SELECT * FROM accounts WHERE id=?', [$ownLogin]);
is_same(['dashboard','student','messages','news'], menu_routes($ownUser), 'a family with a student sees four pages');
$profile = people_nav_entry($ownUser);
is_same(['student', ['id'=>$ownStudent], 'Profil'], [$profile['route'] ?? null, $profile['params'] ?? null, $profile['label'] ?? null],
        'the second is „Profil", their own student\'s page');
ok($ownStudent !== $otherStudent && ($profile['params']['id'] ?? null) !== $otherStudent, 'and never another student\'s');
ok(str_contains(sidebar_nav($ownUser, 'dashboard'), 'href="'.e(url('student', ['id'=>$ownStudent])).'"'), 'the menu links there');

case_('The bar along the bottom of a phone');
/* Four places for staff and „Mehr" for the rest; five for a family and no „Mehr",
   because everything on their side menu is on the bar already. The short word
   is what fits five to a 320px screen; the full one is what a screen reader says. */
$bar = fn(array $who) => [array_column(mobile_nav_entries($who), 'route'), array_column(mobile_nav_entries($who), 'short')];
foreach (['administrator' => $adminUser, 'trainer' => $trainerUser] as $role => $who)
    is_same([['dashboard','students','messages','attendance'], ['Übersicht','Schüler','Post','Anwesend']], $bar($who),
            'staff ('.$role.'): Übersicht, Schüler, Post, Anwesend');
is_same([['dashboard','student','messages','news','profile'], ['Übersicht','Profil','Post','Neues','Konto']], $bar($ownUser),
        'a family: Übersicht, Profil, Post, Neues, Konto');
is_same(['dashboard','messages','news','profile'], array_column(mobile_nav_entries($familyUser), 'route'),
        'and a login with no student simply has no Profil');
is_same('Mein Konto', array_column(mobile_nav_entries($ownUser), 'label', 'route')['profile'] ?? null,
        '„Konto" is read out as „Mein Konto"');
/** A whole page as the browser gets it: the view inside the layout. */
function shell_page(string $page, array $query = []): string {
    $content = render_view($page, $query);
    $public = false; $user = current_user(); $restore = $_GET; $_GET = $query; $GLOBALS['page'] = $page;
    ob_start(); require APP_ROOT.'/views/layout.php'; $html = (string)ob_get_clean(); $_GET = $restore;
    return $html;
}
sign_in_as($trainer);
ok(str_contains(shell_page('dashboard'), 'id="menu-toggle"'), 'staff have „Mehr", which opens the side menu');
sign_in_as($ownLogin);
$familyFrame = shell_page('dashboard');
ok(!str_contains($familyFrame, 'id="menu-toggle"'), 'a family has no „Mehr"');
ok(str_contains($familyFrame, 'href="'.e(url('profile')).'"'), 'their Konto is on the bar instead');

// ---------------------------------------------------------------------------
case_('Every page a person may open is on their menu, or reached from the entry it belongs to');
/* ADR 0011. Taking pages off the menu is only a simplification if every one of
   them can still be found. For each page the router lets a person open, one of:
   - it is an entry of their side menu or phone bar, and that entry is the one
     highlighted while it is open;
   - it belongs to no entry (nav_owner() is ''), and the frame of every page links
     to it: the account in the top bar, the privacy notice at the foot;
   - it belongs to one of their entries, and that entry's page links to it,
     directly or through a page that belongs to the same entry (Geld → Rechnungen
     → a PDF), with exactly that entry highlighted.
   Who may open what is read from the router, not listed again here. */
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
$routerList = function (string $pattern) use ($router): array {
    preg_match($pattern, $router, $found);
    return array_values(array_filter(array_map(fn($p) => trim($p, " '"), explode(',', $found[1] ?? ''))));
};
$allowed    = $routerList("/\\\$allowed=\[([^\]]*)\]/");
$publicOnly = $routerList("/\\\$public=in_array\(\\\$page,\[([^\]]*)\]/");
$staffOnly  = $routerList("/in_array\(\\\$page,\[([^\]]*)\],true\)\)require_staff/");
$adminOnly  = $routerList("/in_array\(\\\$page,\[([^\]]*)\],true\)\)require_admin/");
ok($allowed && $publicOnly && $staffOnly && $adminOnly, 'the router\'s four lists were found');
// A page the router sends somewhere else for some people - a family's old
// bookmark to the students list opens their own student - is reachable for them
// when it lands on one of their own entries. Read from the router, like the lists.
preg_match_all('/if\(\$page===\x27(\w+)\x27 && \(\$instead=(\w+)\(\$user\)\)\)go\(\$instead\[0\],\$instead\[1\]\)/', $router, $found, PREG_SET_ORDER);
$redirects = [];
foreach ($found as [, $page, $function]) $redirects[$page] = $function;
is_same(['students' => 'students_list_instead'], $redirects, 'the router sends a family\'s students list elsewhere, and nothing else');

// Data enough for every link to be drawn: a course, a family's child with a
// charge and an issued invoice, which is what puts a PDF link on the page.
sign_in_as($admin);
foreach (['org_name'=>'Badmintonschule Profil', 'org_street'=>'Turnweg 3', 'org_zip'=>'4020', 'org_city'=>'Linz',
          'org_country'=>'Österreich', 'org_email'=>'kontakt@example.test', 'org_tax_mode'=>'small'] as $key => $value)
    set_setting($key, $value);
run('UPDATE payment_profiles SET iban=? WHERE id=?', ['AT055100080513176900', (int)setting('default_payment_profile')]);
payment_cache_clear();
make_class(['name'=>'Profilkurs']);
$profileCharge = fixture('charges', ['student_id'=>$ownStudent, 'label'=>'Beitrag September', 'amount_cents'=>4500,
    'gross_cents'=>4500, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>'2026-09-01', 'period_to'=>'2026-09-30',
    'due_on'=>'2026-09-01', 'overdue_on'=>'2026-09-08', 'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()]);
create_invoice($ownStudent, [$profileCharge]);

// Also the files a page loads rather than a page anybody opens: the icon, the
// manifest, the portal's colours and its logo.
$signedOutOnly = array_intersect($publicOnly, ['login','forgot','activate','unsubscribe','icon','manifest','brand','logo','not_found']);
/** The links on a page: [page, query] for each, the page the router would open. */
$linksOn = function (string $html): array {
    preg_match_all('/href="([^"]*)"/', $html, $m);
    $out = [];
    foreach ($m[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!str_contains($href, 'index.php') && !str_starts_with($href, '?')) continue;
        parse_str((string)parse_url($href, PHP_URL_QUERY), $query);
        $out[] = [(string)($query['page'] ?? 'dashboard'), $query];
    }
    return $out;
};
$reaches = function (array $who, string $owner, array $ownerParams, string $target) use ($linksOn): bool {
    $queue = [[$owner, $ownerParams, 0]]; $seen = [];
    while ($queue) {
        [$page, $query, $depth] = array_shift($queue);
        $key = $page.'?'.http_build_query($query);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        try { $html = render_view($page, $query); } catch (Throwable) { continue; }
        foreach ($linksOn($html) as [$linked, $linkedQuery]) {
            if ($linked === $target) return true;
            // Only pages that belong to the same entry: the way there has to
            // stay under the entry that is highlighted.
            if ($depth < 2 && !in_array($linked, ['download', 'print'], true) && nav_owner($linked, $who) === $owner)
                $queue[] = [$linked, $linkedQuery, $depth + 1];
        }
    }
    return false;
};
$people = ['an administrator, setup unfinished' => [$admin, true], 'an administrator, setup hidden' => [$admin, false],
           'a trainer' => [$trainer, false], 'a family' => [$ownLogin, false]];
$checkedPairs = 0;
foreach ($people as $who => [$accountId, $setupOpen]) {
    $setupShown($setupOpen);
    sign_in_as($accountId);
    $user = current_user();
    $entries = [];
    foreach (array_merge(nav_entries($user), mobile_nav_entries($user)) as $entry) $entries[$entry['route']] ??= $entry['params'] ?? [];
    $frameLinks = array_column($linksOn(shell_page('dashboard')), 0);
    foreach ($allowed as $page) {
        if (in_array($page, $signedOutOnly, true)) continue;
        if (in_array($page, $staffOnly, true) && !is_staff($user)) continue;
        if (in_array($page, $adminOnly, true) && !is_admin($user)) continue;
        $checkedPairs++;
        $owner = nav_owner($page, $user);
        $instead = isset($redirects[$page]) && function_exists($redirects[$page]) ? $redirects[$page]($user) : null;
        if ($instead !== null) {
            [$landing, $landingParams] = $instead;
            ok(isset($entries[$landing]) && $entries[$landing] == $landingParams,
               $page.' sends '.$who.' to '.$landing.' '.json_encode($landingParams).', one of their own entries');
        } elseif (isset($entries[$page])) {
            is_same($page, $owner, $page.' is on the menu of '.$who.', and its own entry is the one highlighted');
        } elseif ($owner === '') {
            ok(in_array($page, $frameLinks, true), $page.' belongs to no entry for '.$who.', and the frame of every page links to it');
        } else {
            ok(isset($entries[$owner]), $page.' belongs to '.$owner.', which is on the menu of '.$who);
            ok(isset($entries[$owner]) && $reaches($user, $owner, $entries[$owner], $page),
               $page.' is linked from '.$owner.' for '.$who.', or from a page that belongs to it');
        }
        if ($owner !== '')
            is_same([$owner], array_values(array_filter(array_keys($entries), fn($route) => nav_is_current($route, $page, $user))),
                    'while '.$page.' is open, only '.$owner.' is highlighted for '.$who);
    }
}
ok($checkedPairs >= 50, $checkedPairs.' pages checked across four kinds of person');
is_same(['student', ['id'=>$ownStudent]], students_list_instead($ownUser), 'a family with a student is sent to that student\'s page');
is_same(['dashboard', []], students_list_instead($familyUser), 'one with no student to the overview');
foreach ([$adminUser, $trainerUser] as $staffUser)
    is_same(null, students_list_instead($staffUser), 'and staff are not sent anywhere: the list is theirs');
// A family's phone has no side menu, so its way to the notice is Konto.
sign_in_as($ownLogin);
ok(in_array('privacy', array_column($linksOn(render_view('profile')), 0), true) && str_contains(render_view('profile'), e('Datenschutz und Hilfe')),
   'on a family\'s phone the privacy notice is under Konto, in „Datenschutz und Hilfe"');
$setupShown(true);

case_('Every menu entry names an icon that exists and a page the router allows');
$fallback = icon('a name that is not an icon');
foreach ([$adminUser, $trainerUser, $ownUser] as $who)
    foreach (array_merge(nav_entries($who), mobile_nav_entries($who)) as $entry) {
        ok(icon($entry['icon']) !== $fallback, $entry['label'].' has a real icon, not the arrow fallback');
        ok(in_array($entry['route'], $allowed, true), $entry['route'].' is a page the router allows');
    }

case_('What is waiting is shown on the entry it belongs to');
$course = make_class(['name'=>'Kindertraining']);
$kid = make_student(['first_name'=>'Lena', 'last_name'=>'Hofer', 'account_id'=>$family]);
fixture('enrolment_requests', ['class_id'=>$course, 'student_id'=>$kid, 'kind'=>'join', 'state'=>'pending',
                               'message'=>'', 'requested_by'=>$family, 'created_at'=>now()]);
is_same(1, pending_request_count(), 'one request is waiting');
is_same(1, array_column(nav_entries($adminUser), 'count', 'route')['classes'] ?? null, 'the number is on Kurse itself');
// Each entry's own number on its own link, and a link with nothing waiting
// carries none - read from the drawn menu, so a count put on the wrong entry
// shows even when another entry legitimately has one (unread messages here).
preg_match_all('~<a href="[^"]*page=(\w+)[^"]*"[^>]*>(.*?)</a>~s', sidebar_nav($adminUser, 'dashboard'), $links, PREG_SET_ORDER);
$drawn = [];
foreach ($links as [, $route, $inside])
    $drawn[$route] = preg_match('~<span class="count"[^>]*>(\d+)</span>~', $inside, $n) ? (int)$n[1] : 0;
is_same(array_column(nav_entries($adminUser), 'count', 'route'), $drawn, 'the side menu draws each number on its own entry and nowhere else');

// ---------------------------------------------------------------------------
case_('A menu in the top bar is a <details> of its own kind, with a panel');
/* The bell once took the rules meant for a section that folds open in the page:
   a margin under the open button, and on a phone a drawn triangle. So it jumped
   when opened, and its panel ran off the left edge of a phone. Every menu in the
   top bar says it is one - the class the stylesheet and app.js look for - and
   hangs its list in a panel of its own. Read from the source, so a menu drawn
   only in some states is held to it too, and from the drawn page. */
$layoutSource = (string)file_get_contents(APP_ROOT.'/views/layout.php');
ok(preg_match('~<header class="topbar">(.*?)</header>~s', $layoutSource, $topbarSource) === 1, 'the top bar was found in views/layout.php');
preg_match_all('~<details\b[^>]*>~', $topbarSource[1] ?? '', $topbarDetails);
ok(count($topbarDetails[0]) >= 1, 'it holds at least one menu, the bell');
foreach ($topbarDetails[0] as $tag)
    ok(preg_match('~\bclass="[^"]*\btopbar-menu\b(?!-)~', $tag) === 1, $tag.' carries the class topbar-menu');
$drawnMenus = function (string $html): array {
    $dom = new DOMDocument();
    $quiet = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors(); libxml_use_internal_errors($quiet);
    $class = fn(string $name) => "contains(concat(' ', normalize-space(@class), ' '), ' $name ')";
    $xpath = new DOMXPath($dom);
    $menus = [];
    // The bar's own menus; a <details> inside a menu's panel (the emoji choice) is part of that menu.
    foreach ($xpath->query('//header['.$class('topbar').']//details[not(ancestor::details)]') as $details) {
        $first = $xpath->query('*[1]', $details)->item(0);
        $menus[] = [
            'menu'    => preg_match('~(^|\s)topbar-menu(\s|$)~', $details->getAttribute('class')) === 1,
            'summary' => $first !== null && $first->nodeName === 'summary',
            'panel'   => $xpath->query('*['.$class('topbar-menu-panel').']', $details)->length === 1,
            'count'   => $xpath->query('summary/*['.$class('count').']', $details)->length,
        ];
    }
    return $menus;
};
foreach (['the administrator' => $admin, 'a family' => $family] as $who => $accountId) {
    notify($accountId, 'info', 'Etwas wartet');
    sign_in_as($accountId);
    $menus = $drawnMenus(shell_page('dashboard'));
    ok(count($menus) >= 1, 'the top bar drawn for '.$who.' has its menus');
    foreach ($menus as $n => $menu) {
        ok($menu['menu'], 'menu '.($n + 1).' drawn for '.$who.' carries the class topbar-menu');
        ok($menu['summary'], 'its button, a <summary>, comes first');
        ok($menu['panel'], 'and what it opens is one .topbar-menu-panel directly inside it');
    }
    is_same(1, $menus[0]['count'] ?? null, 'the bell drawn for '.$who.' carries its number');
}

case_('The stylesheet reader counts as a browser does');
/* tests/css.php decides which rule wins below, so it is held to known answers
   first: a reader that miscounted would pass an override that loses. */
foreach (['details[open]>summary' => [0, 1, 2], '.topbar-menu[open]>summary' => [0, 2, 1],
          'html:not([data-theme=light]) :is(.sidebar nav a,.mobile-nav a) .count' => [0, 3, 3],
          'summary:before' => [0, 0, 2], 'summary::-webkit-details-marker' => [0, 0, 2],
          '#main :where(.card p)' => [1, 0, 0], 'li:nth-child(odd)' => [0, 1, 1], 'p:lang(de) a' => [0, 1, 2]] as $selector => $expected)
    is_same($expected, css_specificity($selector), $selector);
$sample = css_rules("/* a { b } */ a  >  b , .c::before { margin : 0 !important ; content:\"x;y\" }\n@media (max-width:760px){.d{left:1px}}.e{top:0}");
is_same([['', 'a>b', 'margin', '0', true, 1], ['', '.c:before', 'margin', '0', true, 1],
         ['', 'a>b', 'content', '"x;y"', false, 1], ['', '.c:before', 'content', '"x;y"', false, 1],
         ['@media (max-width:760px)', '.d', 'left', '1px', false, 2], ['', '.e', 'top', '0', false, 3]],
        array_map(fn($r) => [$r['media'], $r['selector'], $r['property'], $r['value'], $r['important'], $r['order']], $sample),
        'rules are read past comments, spacing, a quoted semicolon and an @media');
$row = fn(string $selector, int $order, string $media = '', bool $important = false) =>
    ['media' => $media, 'selector' => $selector, 'property' => 'margin', 'value' => '0', 'important' => $important, 'order' => $order];
ok(css_wins($row('.a>summary', 2), $row('.b>summary', 1)), 'as specific and later wins');
ok(!css_wins($row('.a>summary', 1), $row('.b>summary', 2)), 'as specific and earlier loses');
ok(css_wins($row('.a[open]>summary', 1), $row('details[open]>summary', 2)), 'more specific wins even when earlier');
ok(!css_wins($row('.a>summary', 2), $row('details[open]>summary', 1)), 'less specific loses even when later');
ok(!css_wins($row('.a[open]>summary', 2), $row('details summary', 1, '', true)), 'nothing without !important beats !important');
ok(!css_wins($row('.a[open]>summary', 2, '@media(min-width:900px)'), $row('details summary', 1)), 'and an override in a narrower @media is not one everywhere');
is_same(760, css_max_width('@media(max-width:760px)'), 'a phone @media applies up to its max-width');
is_same(0, css_max_width('@media (min-width:400px) and (max-width:760px)'), 'and one with a min-width is not taken for every phone');

case_('The stylesheet keeps a top-bar menu still when it opens, and its panel on the screen');
/* A general rule for <summary> - one naming no class - that moves the button or
   draws beside it has to be undone for .topbar-menu by a rule that wins, at
   every width the general one applies at. css_wins() does not check that the
   override applies in the same state: .topbar-menu[open]>summary is accepted
   against details summary, which is fine here because a shut menu's button is
   what the always-on .topbar-menu>summary rule already sets. */
$css = css_rules((string)file_get_contents(APP_ROOT.'/public/assets/app.css'));
ok(count($css) > 1000, count($css).' declarations read from app.css');
$notPrint = fn(array $row) => !str_contains($row['media'], 'print');
$topbarButton = css_matching($css, '/^\.topbar-menu(\[open\])?>summary$/');
$generalMoves = array_filter(css_matching($css, '/^[^.#]*summary$/'),
    fn($r) => $notPrint($r) && preg_match('/^(margin|padding|display|gap)(-|$)/', $r['property']));
ok(count($generalMoves) >= 2, 'the general rules for a <summary> that move it were found ('.count($generalMoves).')');
foreach ($generalMoves as $general) {
    $margin = str_starts_with($general['property'], 'margin');
    ok(array_filter($topbarButton, fn($over) => css_wins($over, $general) && (!$margin || css_is_zero($over['value']))) !== [],
       $general['selector'].' { '.$general['property'].': '.$general['value'].' }'.($general['media'] !== '' ? ' in '.$general['media'] : '')
       .' is undone for a top-bar menu\'s button by a rule that wins'.($margin ? ', with a margin of 0' : ''));
}
$generalDrawn = array_filter(css_matching($css, '/^[^.#]*summary:(before|after)$/'),
    fn($r) => $notPrint($r) && $r['property'] === 'content' && !in_array($r['value'], ['none', 'normal'], true));
ok(count($generalDrawn) >= 1, 'the general rule drawing a triangle beside a <summary> was found');
foreach ($generalDrawn as $general) {
    $pseudo = substr($general['selector'], (int)strrpos($general['selector'], ':'));
    ok(array_filter(css_matching($css, '/^\.topbar-menu(\[open\])?>summary'.preg_quote($pseudo, '/').'$/'),
                    fn($over) => css_wins($over, $general) && $over['value'] === 'none') !== [],
       $general['selector'].' { content: '.$general['value'].' } is undone for a top-bar menu with content: none, by a rule that wins');
}
$everywhere = fn(string $pattern, string $property, callable $accepts) => array_filter(css_matching($css, $pattern),
    fn($r) => $r['media'] === '' && $r['property'] === $property && $accepts($r['value'])) !== [];
ok($everywhere('/^\.topbar-menu>summary$/', 'list-style', fn($v) => str_contains($v, 'none'))
   || $everywhere('/^\.topbar-menu>summary$/', 'list-style-type', fn($v) => $v === 'none'),
   'a top-bar menu\'s button has no list marker, at every width');
ok($everywhere('/^\.topbar-menu>summary::-webkit-details-marker$/', 'display', fn($v) => $v === 'none'),
   'nor Safari\'s own triangle, at every width');
ok($everywhere('/^\.topbar-menu$/', 'position', fn($v) => $v === 'relative'),
   'on a wide screen the panel hangs from its own button');

/* On a phone the account button sits right of the bell, so a panel hung from
   the bell runs off the left edge. It hangs from the bar, at every width the
   phone layout is used - read from where the stylesheet shows the phone's
   portal name - and the bar has to be positioned for that to work. */
$phone = max(array_map(fn($r) => css_max_width($r['media']),
    array_filter(css_matching($css, '/(^|[\s>])(a)?\.mobile-brand$/'), fn($r) => $r['property'] === 'display' && $r['value'] !== 'none')) ?: [0]);
ok($phone > 0, 'the phone layout starts below '.$phone.'px, where the portal\'s name moves into the top bar');
$onPhone = fn(string $pattern, string $property) => array_filter(css_matching($css, $pattern),
    fn($r) => css_max_width($r['media']) >= $phone && $r['property'] === $property);
ok(array_filter($onPhone('/^\.topbar-menu$/', 'position'), fn($r) => $r['value'] === 'static') !== [],
   'on a phone a top-bar menu lets go of its panel (position: static)');
foreach (['left', 'right'] as $side)
    ok($onPhone('/\.topbar-menu-panel$/', $side) || $onPhone('/\.topbar-menu-panel$/', 'inset'),
       'and the panel is pinned to the bar\'s '.$side.' edge, up to '.$phone.'px');
$barPosition = array_filter(css_matching($css, '/^\.topbar$/'),
    fn($r) => $r['property'] === 'position' && ($r['media'] === '' || css_max_width($r['media']) >= $phone));
usort($barPosition, fn($x, $y) => $x['order'] <=> $y['order']);
$last = end($barPosition) ?: ['value' => 'nothing'];
ok(in_array($last['value'], ['sticky', 'fixed', 'relative', 'absolute'], true),
   'the bar is positioned, so the panel is measured from it ('.$last['value'].')');

/* The bell's number is a badge like the menu's: the same background, and its
   figure in --on-accent, the colour made for text on the accent, which the
   stylesheet sets once for light and once for each way of being dark. A
   hard-coded colour on a number is how the bell's came to differ before. */
$bellCount = css_matching($css, '/^\.notification-pane>summary \.count$/');
$menuCount = css_matching($css, '/^\.mobile-nav a \.count$/');
$value = fn(array $rows, string $property) => array_column(array_filter($rows, fn($r) => $r['media'] === '' && $r['property'] === $property), 'value');
ok($value($menuCount, 'background') !== [] && $value($bellCount, 'background') === $value($menuCount, 'background'),
   'the bell\'s number has the background of the menu\'s ('.implode(', ', $value($bellCount, 'background')).')');
is_same(['var(--on-accent)'], $value($bellCount, 'color'), 'and its figure is in var(--on-accent)');
$hardCoded = array_filter($css, fn($r) => $notPrint($r) && preg_match('/(\.notification-pane>summary|\.mobile-nav a|\.sidebar nav a)\)? \.count$/', $r['selector'])
    && $r['property'] === 'color' && $r['value'] !== 'var(--on-accent)');
is_same([], array_map(fn($r) => $r['selector'].' { color: '.$r['value'].' }', array_values($hardCoded)), 'no rule gives a number any other colour');
foreach ([':root' => 'light', 'html:not([data-theme=light])' => 'dark by the device', 'html[data-theme=dark]' => 'dark by choice'] as $selector => $mode)
    ok(array_filter(css_matching($css, '/^'.preg_quote($selector, '/').'$/'), fn($r) => $r['property'] === '--on-accent') !== [],
       '--on-accent is set for '.$mode);

case_('A tap elsewhere, a swipe and Escape do what they should to an open menu');
/* The behaviour itself, run against the real app.js in tests/topbar-menus.mjs:
   the stylesheet can keep the bell still, but only the script closes it. */
$appJs = (string)file_get_contents(APP_ROOT.'/public/assets/app.js');
ok(preg_match('/querySelectorAll\(\s*([\'"])\.topbar-menu\1\s*\)/', $appJs) === 1,
   'app.js finds the top bar\'s menus by the class the layout gives them');
$node = function_exists('exec') ? trim((string)exec('command -v node 2>/dev/null')) : '';
if ($node === '') {
    test_unsupported(array_merge(test_unsupported(),
        ['what a tap, a swipe and Escape do to a top-bar menu (tests/topbar-menus.mjs needs node)']));
} else {
    $output = [];
    exec(escapeshellarg($node).' '.escapeshellarg(TEST_ROOT.'/topbar-menus.mjs').' 2>&1', $output, $status);
    $results = json_decode(implode("\n", $output), true);
    ok($status === 0 && is_array($results), 'tests/topbar-menus.mjs ran'.($status === 0 && is_array($results) ? '' : ': '.implode("\n", $output)));
    ok(is_array($results) && count($results) >= 16, 'and made all its checks ('.(is_array($results) ? count($results) : 0).')');
    foreach (is_array($results) ? $results : [] as $result)
        ok($result['pass'] === true, $result['what'].($result['pass'] ? '' : ' — '.$result['detail']));
}

case_('A file from public/assets/ is linked at an address that changes when its bytes do');
/* The release that brought the steady bell and the account menu changed app.css
   and app.js, and VERSION stayed 0.6.0 - which was all their address carried. So
   after the upload browsers kept the stylesheet they had: the bell jumped as it
   used to, and the account menu opened unstyled across the page, and both were
   reported as "still" broken. The address now carries a hash of the file's
   bytes. Asked of a PHP of its own, because it is worked out once per request:
   a file rewritten with the same length and the same date - which an FTP client
   that keeps dates leaves behind - still gets a new address. */
$tree = test_run_dir().'/asset-tree';
if (!is_dir($tree.'/public/assets')) mkdir($tree.'/public/assets', 0777, true);
$probe = $tree.'/public/assets/probe.css';
$addressFor = function (string $bytes) use ($tree, $probe): string {
    file_put_contents($probe, $bytes);
    touch($probe, 1700000000);
    clearstatcache();
    $output = [];
    exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('define("ROOT", $argv[1]); require $argv[2]; echo asset_path("probe.css");')
        .' '.escapeshellarg($tree).' '.escapeshellarg(APP_ROOT.'/app/install.php').' 2>&1', $output, $status);
    return $status === 0 ? implode("\n", $output) : 'failed: '.implode("\n", $output);
};
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['whether an asset\'s address follows its bytes (needs exec)']));
} else {
    $first = $addressFor('a{color:red}');
    $second = $addressFor('b{color:red}');
    ok(preg_match('~^assets/probe\.css\?v=[0-9a-f]{8,}$~', $first) === 1, 'the address names the file and a hash ('.$first.')');
    ok($second !== $first, 'the same length and the same date with other bytes is another address ('.$second.')');
    is_same($first, $addressFor('a{color:red}'), 'and the same bytes again are the same address, so an unchanged file stays cached');
}

/* What the pages actually link: every file from public/assets/ in the drawn
   layout is at exactly the address asset_url() gives it, with nothing appended,
   for staff and for a family. */
foreach (['the administrator' => $admin, 'a family' => $family] as $who => $accountId) {
    sign_in_as($accountId);
    preg_match_all('~\b(?:href|src)="([^"]*/assets/([^"?]+)[^"]*)"~', shell_page('dashboard'), $linked, PREG_SET_ORDER);
    $names = array_column($linked, 2);
    foreach (['app.css', 'app.js'] as $needed) ok(in_array($needed, $names, true), 'the page drawn for '.$who.' links '.$needed);
    foreach ($linked as [, $address, $name])
        is_same(e(asset_url($name)), $address, $name.' is linked for '.$who.' at the address of its bytes');
}
foreach (glob(APP_ROOT.'/public/assets/*') as $shipped)
    ok(str_ends_with(asset_url(basename($shipped)), '?v='.substr((string)hash_file('sha256', $shipped), 0, 12)),
       basename($shipped).' is offered at the hash of its own bytes');
$manifestIcons = array_column(web_manifest()['icons'], 'src');
ok(count($manifestIcons) >= 1, 'the manifest offers the built-in icons ('.count($manifestIcons).')');
foreach ($manifestIcons as $src)
    is_same(asset_url(basename((string)parse_url($src, PHP_URL_PATH))), $src, 'the manifest offers '.basename((string)parse_url($src, PHP_URL_PATH)).' at the address of its bytes');

/* And nothing builds such an address by hand: an "assets/" or a "?v=" written
   into the PHP anywhere else - the release number appended in the layout, the
   setup page's own link - is a second way, and the second way is the one that
   goes stale. A path on disk (…/public/assets/…) is not an address. */
$byHand = [];
$sources = array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php'));
foreach ($sources as $source) {
    $relative = substr($source, strlen(APP_ROOT) + 1);
    foreach (token_get_all((string)file_get_contents($source)) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) continue;
        $text = str_replace('public/assets/', '', $token[1]);
        if ($relative === 'app/install.php' && in_array($token[1], ["'assets/'", "'?v='"], true)) continue;   // asset_path() itself
        if (str_contains($text, 'assets/') || str_contains($text, '?v='))
            $byHand[] = $relative.':'.$token[2].' '.trim(substr($token[1], 0, 80));
    }
}
ok(count($sources) > 60, count($sources).' PHP files read');
is_same([], $byHand, 'no address of a shipped file is built anywhere but asset_path()');
