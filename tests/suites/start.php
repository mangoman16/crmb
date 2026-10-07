<?php
/**
 * The start checklist (ADR 0011).
 *
 * Nothing on it is ticked by hand, so what is tested is that each step reads the
 * data the page it links to reads, that example data never counts, and that the
 * ways onto and off the checklist - landing after sign-in, the way back after a
 * save, hiding it - do what the ADR says and nothing else.
 */
$admin   = make_account(['role'=>'admin', 'email'=>'chefin@beispiel.test']);
$trainer = make_account(['role'=>'trainer']);
$family  = make_account(['role'=>'student']);
sign_in_as($admin);

/** The steps as the checklist sees them now, keyed, read fresh. */
$steps = function (): array { setup_cache_clear(); payment_cache_clear(); return array_column(setup_steps(), null, 'key'); };
$done  = fn(): array => array_map(fn($s) => $s['done'], $steps());
$dataSteps = ['first_course', 'course_prices', 'students', 'billing', 'invite'];

case_('A new portal has nine steps, none of them done, each leading to the screen that does it');
$fresh = $steps();
is_same(['organisation','bank','first_course','course_prices','students','billing','mail','privacy','invite'],
        array_keys($fresh), 'the nine steps, in the order they are shown');
is_same(array_fill_keys(array_keys($fresh), false), $done(), 'and nothing on a new portal is done');
foreach ($fresh as $key => $step) {
    ok($step['what'] !== '' && $step['why'] !== '', $key.' says what and why');
    is_same('start', $step['params']['from'] ?? null, $key.' carries from=start, so the page can offer the way back');
    ok(in_array($step['page'], ['settings','manage','classes','student','student_new','students','payments'], true), $key.' leads to a page that exists');
}
$progress = setup_progress();
is_same([0, 9], [$progress['done'], $progress['total']], 'none of nine');
is_same('organisation', $progress['next']['key'] ?? null, 'and the first thing to do is the first step');
is_same(true, setup_unfinished(), 'so setup is unfinished');

case_('The invitations step waits for mail and the privacy notice');
is_same(['mail', 'privacy'], $fresh['invite']['blocked_by'], 'it names the two steps it waits for');
is_same(true, $fresh['invite']['blocked'], 'and is held up while they are not done');
set_setting('smtp', ['host'=>'mail.beispiel.test', 'port'=>587, 'username'=>'', 'password'=>'',
                     'encryption'=>'tls', 'from_email'=>'portal@beispiel.test', 'from_name'=>'Badminton']);
set_setting('smtp_last_test', ['ok'=>true, 'summary'=>'', 'transcript'=>'', 'sent_to'=>'', 'at'=>now()]);
is_same(true, $steps()['invite']['blocked'], 'mail alone is not enough: the family still needs a notice to read');
set_setting('privacy_ready', true);
is_same(false, $steps()['invite']['blocked'], 'with both, it can be done');
is_same(false, $steps()['invite']['done'], 'though nobody has been invited yet');
is_same(['first_course'], $fresh['course_prices']['blocked_by'], 'a price for every course waits for a course');

case_('Example data never counts');
/* The other four are done first, so that what is left undone can only be left
   undone because the example rows were not counted - not because the portal
   is empty. */
foreach (['org_name'=>'Badmintonschule Hofer', 'org_street'=>'Turnweg 3', 'org_zip'=>'4020', 'org_city'=>'Linz'] as $k => $v)
    set_setting($k, $v);
run('UPDATE payment_profiles SET iban=? WHERE id=?', ['AT055100080513176900', (int)setting('default_payment_profile')]);
demo_fill();
// What the example data holds would tick every data step if it counted.
ok((int)scalar('SELECT COUNT(*) FROM classes WHERE is_demo=1 AND archived=0') > 0, 'there are example courses');
ok((int)scalar('SELECT COUNT(*) FROM charges') > 0, 'example charges');
ok((int)scalar("SELECT COUNT(*) FROM accounts WHERE is_demo=1 AND role='student' AND state='active'") > 0, 'and example families who have signed in');
$afterDemo = $done();
foreach (['organisation','bank','mail','privacy'] as $key) is_same(true, $afterDemo[$key], $key.' is done, from settings the example data does not touch');
foreach ($dataSteps as $key) is_same(false, $afterDemo[$key], $key.' is still undone with only example data in the portal');
demo_clear();

case_('Each data step is done by the real thing');
$course = make_class(['name'=>'Kindertraining', 'days'=>[]]);
is_same(true, $done()['first_course'], 'a course of her own is the first course');
$gap = $steps()['course_prices'];
is_same(false, $gap['done'], 'a course with no training day and no tariff is not priced');
is_same(['page'=>'classes', 'params'=>['id'=>$course, 'edit'=>1, 'from'=>'start']],
        ['page'=>$gap['page'], 'params'=>$gap['params']], 'and the step leads where the course’s own list of what is missing leads');
fixture('class_days', ['class_id'=>$course, 'weekday'=>2, 'starts_at'=>'16:00:00', 'ends_at'=>'17:30:00', 'location'=>'', 'sort_order'=>0]);
$tariff = make_tariff(['class_id'=>$course, 'price_cents'=>3700]);
is_same(true, $done()['course_prices'], 'with a day and a tariff it is');
make_class(['name'=>'Archiviert', 'days'=>[], 'archived'=>1]);
is_same(true, $done()['course_prices'], 'an archived course without either asks for nothing');

$kid = make_student(['first_name'=>'Lena', 'last_name'=>'Hofer']);
$waiting = $steps()['students'];
is_same(false, $waiting['done'], 'a child in no course yet holds the step up: „Jedes Kind in einem Kurs“');
is_same(['student', ['id'=>$kid, 'tab'=>'classes', 'from'=>'start'], 'add-course'],
        [$waiting['page'], $waiting['params'], $waiting['anchor'] ?? null], 'and the step leads to putting that child in one');
make_enrolment($course, $kid, ['tariff_id'=>null]);
$children = $steps()['students'];
is_same(false, $children['done'], 'a child in a course without a tariff does');
is_same(['student', ['id'=>$kid, 'tab'=>'classes', 'from'=>'start'], 'courses'],
        [$children['page'], $children['params'], $children['anchor'] ?? null], 'and the step leads to that child’s courses');
run('UPDATE class_students SET tariff_id=? WHERE student_id=?', [$tariff, $kid]);
is_same(true, $done()['students'], 'on a tariff with a price, it is done');
$ended = make_student(['first_name'=>'Ehemals', 'last_name'=>'Mitglied', 'status'=>'ended']);
make_enrolment($course, $ended, ['tariff_id'=>null]);
is_same(true, $done()['students'], 'a member who has ended does not hold it up');

is_same(false, $done()['billing'], 'no charges yet, and nothing automatic');
set_setting('auto_billing', true);
is_same(true, $done()['billing'], 'automatic charges switched on are enough');
set_setting('auto_billing', false);
fixture('charges', ['student_id'=>$kid, 'label'=>'Beitrag', 'amount_cents'=>3700, 'due_on'=>today(), 'cancelled'=>0,
                    'origin'=>'manual', 'created_at'=>now()]);
is_same(true, $done()['billing'], 'and so is one charge on a real child');

$login = make_account(['role'=>'student', 'state'=>'invited', 'verified_at'=>null]);
run('UPDATE students SET account_id=? WHERE id=?', [$login, $kid]);
is_same(true, $done()['invite'], 'one family invited is the invitations step done');
is_same(array_fill_keys(array_keys($steps()), true), $done(), 'and now every step is');
is_same(['done'=>9, 'total'=>9, 'next'=>null], setup_progress(), 'nine of nine, with nothing next');
is_same(false, setup_unfinished(), 'so setup is finished');

case_('A step that falls back is undone again');
set_setting('privacy_ready', false);
setup_cache_clear();
is_same(false, $steps()['privacy']['done'], 'taking the notice back undoes its step');
is_same(true, setup_unfinished(), 'and setup is unfinished again, without anybody unticking anything');
set_setting('privacy_ready', true);

case_('One rule says whether a child’s course has a price, and billing, the child’s page and the checklist agree');
/* enrolment_has_price() is the rule billing skips by. The child's page and the
   checklist ask it too, rather than each keeping a copy - a copy is how "no
   tariff" came to be checked in one place and "no price" in another. */
$free = make_tariff(['class_id'=>$course, 'name'=>'Ohne Preis', 'price_cents'=>0]);
run('UPDATE class_students SET tariff_id=?, joined_on=? WHERE student_id=?', [$free, '2026-01-01', $kid]);
$enrolment = fn() => array_values(array_filter(billing_enrolments(), fn($e) => (int)$e['student_id'] === $kid))[0];
$planned = fn() => array_values(array_filter(billing_plan('2026-03'), fn($e) => $e['student_id'] === $kid))[0]['skip'];
$asked = fn() => array_column(student_next_steps($kid), 'what');
is_same(false, enrolment_has_price($enrolment()), 'a tariff whose only rate is nothing is no price');
is_same(t('Kein Preis hinterlegt', 'No price set'), $planned(), 'billing skips it for want of one');
ok(in_array(t('Preis eintragen', 'Enter a price'), $asked(), true), 'the child’s page asks for one');
is_same(false, $done()['students'], 'and the checklist does not count the child');
run('UPDATE class_students SET price_cents=2500 WHERE student_id=?', [$kid]);
is_same(true, enrolment_has_price($enrolment()), 'her own agreed price is a price');
is_same(null, $planned(), 'billing charges it');
is_same([], array_intersect([t('Preis eintragen', 'Enter a price'), t('Tarif wählen', 'Choose a tariff')], $asked()),
        'the child’s page asks for nothing about money');
is_same(true, $done()['students'], 'and the checklist counts the child');
run('UPDATE class_students SET tariff_id=NULL WHERE student_id=?', [$kid]);
is_same(false, enrolment_has_price($enrolment()), 'an agreed price with no tariff is not billed, so it is no price either');
is_same(t('Kein Tarif gewählt', 'No tariff chosen'), $planned(), 'billing says why');
ok(in_array(t('Tarif wählen', 'Choose a tariff'), $asked(), true), 'the child’s page asks for a tariff');
is_same(false, $done()['students'], 'and the checklist agrees');
run('UPDATE class_students SET tariff_id=?, price_cents=NULL WHERE student_id=?', [$tariff, $kid]);
is_same(true, $done()['students'], 'back on the priced tariff, all three are satisfied again');

case_('Sign-in lands an administrator on the checklist while it is unfinished, and nobody else');
set_setting('org_name', '');
setup_cache_clear();
sign_out();
$signIn = function (int $id) { setup_cache_clear();
    return submit('login', ['login'=>(string)scalar('SELECT email FROM accounts WHERE id=?', [$id]), 'password'=>'Test-Only-Password-2026']); };
is_same(['start', []], $signIn($admin), 'an administrator signing in is taken to the checklist');
is_same(['start', []], $signIn($admin), 'at every sign-in, not only the first');
is_same(['dashboard', []], $signIn($trainer), 'a trainer is taken to the overview');
is_same(['dashboard', []], $signIn($family), 'and so is a family');
is_same(['dashboard', []], landing_after_sign_in(one('SELECT * FROM accounts WHERE id=?', [$trainer])),
        'landing_after_sign_in() is the one rule');

case_('Following an emailed link lands the same way');
$_SESSION['activation_hash'] = hash('sha256', make_token($admin, 'reset'));
setup_cache_clear();
is_same(['start', []], submit('activate', ['password'=>'Federball-2026-Halle!', 'password_confirm'=>'Federball-2026-Halle!']),
        'an administrator resetting a password lands on the checklist');
$newcomer = make_account(['role'=>'trainer', 'email'=>'neu@beispiel.test', 'state'=>'invited', 'verified_at'=>null, 'password_hash'=>null]);
$_SESSION['activation_hash'] = hash('sha256', make_token($newcomer, 'invite'));
setup_cache_clear();
is_same(['dashboard', []], submit('activate', ['password'=>'Federball-2026-Halle!', 'password_confirm'=>'Federball-2026-Halle!', 'privacy_seen'=>'1']),
        'a trainer accepting an invitation lands on the overview');
sign_in_as($admin);

case_('The way back survives a save and ends on the checklist');
/* In the session, not the URL: the redirect after a save carries nothing
   forward, and no action should have to. */
$get = function (string $page, array $query = []) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = $query; note_setup_return($page); };
$get('settings', ['tab'=>'organisation', 'from'=>'start']);
is_same(true, setup_return_active(), 'leaving the checklist for one of its steps offers the way back');
$_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = [];
note_setup_return('settings');
$target = act('defaults_registry_save', ['group'=>'organisation', 'to_page'=>'settings', 'to_tab'=>'organisation']
    + array_combine(array_map(fn($k) => 'set_'.$k, array_keys(settings_in_group('organisation'))),
                    array_map(fn($k) => is_array(setting($k)) ? '' : (string)setting($k), array_keys(settings_in_group('organisation'))))
    + ['set_org_name'=>'Badmintonschule Hofer']);
$get($target[0], $target[1]);
is_same(true, setup_return_active(), 'the page the save redirects to still offers it');
$get('students');
is_same(true, setup_return_active(), 'and any other page she opens on the way');
$get('start');
is_same(false, setup_return_active(), 'opening the checklist ends it');
$get('students', ['from'=>'start']);
$get('students');
is_same(true, setup_return_active(), 'a second trip starts it again');
$_SERVER['REQUEST_METHOD'] = 'POST';
without_session_id_warning(fn() => act('logout'));
is_same(false, isset($_SESSION['setup_return']), 'signing out takes it with the rest of the session');
sign_in_as($trainer);
$get('students', ['from'=>'start']);
is_same(false, setup_return_active(), 'a trainer following such a link is offered nothing');
is_same(false, isset($_SESSION['setup_return']), 'and nothing is written for her');
sign_in_as($admin);
$_SESSION['setup_return'] = (int)$trainer;
is_same(false, setup_return_active(), 'a way back left in the session by somebody else is not hers');
unset($_SESSION['setup_return']);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = [];

$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
ok(str_contains($router, "record_step(\$page);\n    // The way back to the start checklist")
   && substr_count($router, 'note_setup_return(') === 1,
   'the router notes it exactly once, right after record_step(), before the POST branch can redirect');

case_('Hiding the checklist stops the landing, and asks no questions of the data');
setup_cache_clear();
is_same(true, setup_unfinished(), 'setup is unfinished before');
throws(fn() => act('setup_visibility', ['hidden'=>'maybe']), 'only 0 or 1 is a visibility');
is_same(['dashboard', []], act('setup_visibility', ['hidden'=>'1']), 'hiding it goes to the overview');
setup_cache_clear();
is_same(false, setup_unfinished(), 'hidden, setup no longer counts as unfinished');
is_same(['dashboard', []], landing_after_sign_in(one('SELECT * FROM accounts WHERE id=?', [$admin])),
        'so an administrator signing in lands on the overview');
setting('setup_hidden');   // the one read it needs, answered from memory from now on
setup_cache_clear();       // and nothing remembered of the steps, as on a fresh request
is_same(0, query_count(fn() => setup_unfinished()), 'and deciding that runs no query at all');
is_same(['start', []], act('setup_visibility', ['hidden'=>'0']), 'showing it again opens it');
setup_cache_clear();
is_same(true, setup_unfinished(), 'and it is back');

case_('Only an administrator may hide the checklist or switch automatic charges');
sign_in_as($trainer);
throws(fn() => act('setup_visibility', ['hidden'=>'1']), 'a trainer cannot hide the checklist', 'Administratoren');
is_same(false, (bool)setting('setup_hidden'), 'and it stays shown');
throws(fn() => act('auto_billing_save', ['auto_billing'=>'1']), 'a trainer cannot switch on automatic charges', 'Administratoren');
is_same(false, (bool)setting('auto_billing'), 'and they stay off');
sign_in_as($admin);
is_same(['payments', []], act('auto_billing_save', ['auto_billing'=>'1']), 'an administrator can, from the Beiträge page');
is_same(true, (bool)setting('auto_billing'), 'and they are on');
act('auto_billing_save', []);
is_same(false, (bool)setting('auto_billing'), 'a box left unticked posts nothing, and nothing is off');
ok(!array_key_exists('auto_billing', settings_in_group('system')), 'the settings form no longer lists it a second time');

case_('Example data filled or cleared from the checklist goes back to the checklist');
/* Anywhere else it returns to the System tab, where the buttons have always been. */
sign_in_as($admin);
unset($_SESSION['setup_return']);
is_same(['settings', ['tab'=>'system']], act('demo_data', ['mode'=>'fill', 'confirm'=>'1']), 'from the System tab, back to it');
is_same(['start', []], act('demo_data', ['mode'=>'clear', 'from'=>'start']), 'a form on the checklist that says so goes back there');
$_SESSION['setup_return'] = (int)$admin;
is_same(['start', []], act('demo_data', ['mode'=>'fill', 'confirm'=>'1']), 'and so does one sent while the way back is open');
is_same(['start', []], act('demo_data', ['mode'=>'clear']), 'clearing too');
unset($_SESSION['setup_return']);
is_same(['settings', ['tab'=>'system']], act('demo_data', ['mode'=>'clear']), 'once it is closed, the System tab again');
is_same(0, (int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=1'), 'and the example data is gone');

case_('A real course is one that runs and is not example data, and there is one definition of it');
run('UPDATE classes SET archived=1');
is_same([false, []], [real_course_exists(), real_course_ids()], 'with every course archived there is none');
demo_fill(true);
is_same(false, real_course_exists(), 'example courses do not count');
demo_clear();
run('UPDATE classes SET archived=0 WHERE id=?', [$course]);
is_same([true, [$course]], [real_course_exists(), real_course_ids()], 'a running course of her own does');
ok(str_contains((string)file_get_contents(APP_ROOT.'/app/start.php'), '$courses = real_course_ids();'),
   'and the checklist asks it rather than keeping its own copy');
