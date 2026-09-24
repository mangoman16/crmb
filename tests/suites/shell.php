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
$withPhoto = avatar(['id'=>3, 'name'=>'Lena Hofer', 'avatar_name'=>'abc.jpg'], '', 'student');
ok(str_contains($withPhoto, '<img'), 'a picture when there is one');
ok(str_contains($withPhoto, 'what=avatar'), 'served through the download route, not by URL');
ok(!str_contains($withPhoto, 'abc.jpg'), 'and the stored name is never in the page');

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

case_('Stopping when nothing is being impersonated says so rather than failing');
throws(fn() => act('impersonate', ['mode'=>'stop']), 'there is nothing to stop', 'nicht als jemand anderer');

case_('Signing out ends it, whatever else happens');
sign_in_as($trainer);
act('impersonate', ['id'=>(string)$family, 'mode'=>'start']);
act('logout', []);
is_same(null, impersonator(), 'nothing of it survives');
is_same(null, current_user(), 'and nobody is signed in');

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
// The staff list said role IN ('admin','manager'). 'manager' is what trainers
// were called before 0.2, so a family writing in reached the administrator and
// nobody else — on a portal whose whole point is that she reads the messages.
sign_in_as($family);
$beforeTrainer = unread_notifications($trainer);
$beforeAdmin = unread_notifications($admin);
act('message_send', ['subject'=>'Frage zum Schläger', 'body'=>'Welchen sollen wir kaufen?']);
is_same($beforeTrainer + 1, unread_notifications($trainer), 'the trainer is told');
is_same($beforeAdmin + 1, unread_notifications($admin), 'and so is the administrator');

case_('And her reply reaches the family');
sign_in_as($trainer);
$thread = (int)scalar('SELECT MAX(id) FROM threads');
$beforeFamily = unread_notifications($family);
act('message_send', ['thread_id'=>(string)$thread, 'body'=>'Ich bringe zwei mit.']);
is_same($beforeFamily + 1, unread_notifications($family), 'the family is told');

// ---------------------------------------------------------------------------
case_('The main menu holds every destination, once, for the people who may open it');
/* Thirteen entries in one column made the panel 958px tall, which is taller than
   a 1920x1080 screen at 110% zoom: the last three were below the fold and the
   menu scrolled. Six of them now sit inside sections. What must stay true is
   that nothing was dropped on the way, and that nobody is offered a page the
   router will refuse them. */
function menu_routes(array $user): array {
    $routes = [];
    foreach (nav_entries($user) as $entry) {
        if (isset($entry['route'])) { $routes[] = $entry['route']; continue; }
        foreach ($entry['items'] as $item) $routes[] = $item['route'];
    }
    return $routes;
}
$adminUser = one('SELECT * FROM accounts WHERE id=?', [$admin]);
$trainerUser = one('SELECT * FROM accounts WHERE id=?', [$trainer]);
$familyUser = one('SELECT * FROM accounts WHERE id=?', [$family]);

$adminRoutes = menu_routes($adminUser);
is_same(array_values(array_unique($adminRoutes)), $adminRoutes, 'no destination is offered twice');
foreach (['dashboard','students','classes','attendance','payments','invoices','messages','news',
          'manage','accounts','outbox','history','settings'] as $route)
    ok(in_array($route, $adminRoutes, true), 'an administrator can still reach '.$route);

$trainerRoutes = menu_routes($trainerUser);
foreach (['history','settings'] as $route)
    ok(!in_array($route, $trainerRoutes, true), 'a trainer is not offered '.$route.', which the router refuses her');
ok(in_array('classes', $trainerRoutes, true), 'but she is offered the courses');

is_same(['dashboard','students','messages','news'], menu_routes($familyUser), 'a family sees four pages and no sections');
foreach (nav_entries($familyUser) as $entry)
    ok(isset($entry['route']), 'and nothing is folded away from them');

case_('Every menu entry names an icon that exists and a page the router allows');
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
preg_match("/\\\$allowed=\[([^\]]*)\]/", $router, $m);
$allowed = array_map(fn($p) => trim($p, " '"), explode(',', $m[1] ?? ''));
$fallback = icon('a name that is not an icon');
foreach ([$adminUser, $trainerUser, $familyUser] as $who)
    foreach (nav_entries($who) as $entry) {
        $parts = isset($entry['route']) ? [$entry] : array_merge([$entry], $entry['items']);
        foreach ($parts as $part) {
            ok(icon($part['icon']) !== $fallback, $part['label'].' has a real icon, not the arrow fallback');
            if (isset($part['route'])) ok(in_array($part['route'], $allowed, true), $part['route'].' is a page the router allows');
        }
    }

case_('The section you are working in is the one standing open');
sign_in_as($admin);
$menu = sidebar_nav($adminUser, 'attendance');
ok(preg_match('/<summary>[^<]*(<svg.*?<\/svg>)?<span>Training<\/span>.*?<\/details>/s', $menu) === 1, 'the menu has a Training section');
// Anwesenheit is inside Training, so Training is open and the other two are not.
is_same(1, substr_count($menu, '<details class="nav-section" name="nav-section" open>'), 'exactly one section stands open');
$open = substr($menu, (int)strpos($menu, 'name="nav-section" open'));
$open = substr($open, 0, (int)strpos($open, '</details>'));
ok(str_contains($open, 'page=attendance'), 'and it is the one holding the page being looked at');
ok(str_contains($open, '<span>Training</span>'), 'which is Training, not another one');
foreach (['dashboard'=>'', 'payments'=>'Geld', 'settings'=>'System'] as $page => $section) {
    $html = sidebar_nav($adminUser, $page);
    is_same($section === '' ? 0 : 1, substr_count($html, 'name="nav-section" open'),
            $page === 'dashboard' ? 'a page outside every section leaves them all shut' : $section.' opens for '.$page);
}
is_same(1, substr_count(sidebar_nav($adminUser, 'student'), 'aria-current="page"'), 'one student marks the students entry');
is_same(1, substr_count(sidebar_nav($adminUser, 'compose'), 'aria-current="page"'), 'and a new message marks Nachrichten');

case_('What is waiting is shown on the section while the section is shut');
$course = make_class(['name'=>'Kindertraining']);
$kid = make_student(['first_name'=>'Lena', 'last_name'=>'Hofer', 'account_id'=>$family]);
fixture('enrolment_requests', ['class_id'=>$course, 'student_id'=>$kid, 'kind'=>'join', 'state'=>'pending',
                               'message'=>'', 'requested_by'=>$family, 'created_at'=>now()]);
is_same(1, pending_request_count(), 'one request is waiting');
$shut = sidebar_nav($adminUser, 'dashboard');
ok(str_contains($shut, 'class="count section-count"'), 'the Training section carries the number while it is shut');
ok(substr_count($shut, 'class="count'), 'and the entry inside it carries it too, for when the section opens');
