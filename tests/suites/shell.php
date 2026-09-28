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

$signedOutOnly = array_intersect($publicOnly, ['login','forgot','activate','unsubscribe','icon','manifest','not_found']);
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
