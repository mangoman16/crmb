<?php
/**
 * The pages themselves.
 *
 * Every other suite checks a function. These render the real view and read what
 * came out, which is the only way to catch a page that computes the right answer
 * and then prints the wrong one — or prints something the viewer should not see.
 */

$trainer = make_account(['role'=>'trainer','name'=>'Trainerin Meier']);
$parent  = make_account(['role'=>'student','name'=>'Eltern Huber']);

sign_in_as($trainer);
$tariff = make_tariff(['price_cents'=>4500]);

// Two children of the signed-in parent and two belonging to somebody else, so
// the parent's page has something it must leave out.
$mine = []; $theirs = [];
foreach (['Anna','Bernd'] as $n) $mine[]   = make_student(['first_name'=>$n,'last_name'=>'Huber','account_id'=>$parent,'tariff_id'=>$tariff,'status'=>'active']);
foreach (['Clara','Dieter'] as $n) $theirs[] = make_student(['first_name'=>$n,'last_name'=>'Fremd','tariff_id'=>$tariff,'status'=>'active']);
$paused = make_student(['first_name'=>'Emil','last_name'=>'Pausiert','tariff_id'=>$tariff,'status'=>'paused']);

// One overdue charge, one not yet due, one settled, one cancelled.
fixture('charges', ['student_id'=>$mine[0],'label'=>'Überfällig','amount_cents'=>4500,'due_on'=>'2020-01-10','cancelled'=>0,'origin'=>'manual','created_at'=>now()]);
fixture('charges', ['student_id'=>$mine[1],'label'=>'Später','amount_cents'=>3000,'due_on'=>'2099-01-10','cancelled'=>0,'origin'=>'manual','created_at'=>now()]);
$settled = fixture('charges', ['student_id'=>$theirs[0],'label'=>'Bezahlt','amount_cents'=>2000,'due_on'=>'2020-01-10','cancelled'=>0,'origin'=>'manual','created_at'=>now()]);
fixture('payments', ['charge_id'=>$settled,'amount_cents'=>2000,'paid_on'=>'2020-01-11','method'=>'Bar','note'=>'','confirmed_at'=>now(),'voided'=>0]);
fixture('charges', ['student_id'=>$theirs[1],'label'=>'Storniert','amount_cents'=>9900,'due_on'=>'2020-01-10','cancelled'=>1,'origin'=>'manual','created_at'=>now()]);
// Another family owes something too, so the club's total and one parent's total
// are different numbers and a page showing the wrong one is visible.
fixture('charges', ['student_id'=>$theirs[1],'label'=>'Offen','amount_cents'=>1234,'due_on'=>'2099-02-10','cancelled'=>0,'origin'=>'manual','created_at'=>now()]);
// Two overlapping absences for one student: the tile counts people, not rows.
fixture('absences', ['student_id'=>$theirs[0],'starts_on'=>today(),'ends_on'=>today(),'reason'=>'sick','created_by'=>$trainer]);
fixture('absences', ['student_id'=>$theirs[0],'starts_on'=>today(),'ends_on'=>today(),'reason'=>'holiday','created_by'=>$trainer]);

case_('The dashboard tiles agree with the numbers worked out one student at a time');
$html = render_view('dashboard');
$all = filtered_students([]);
$expectOpen = 0; $expectOverdue = 0; $expectActive = 0;
foreach ($all as $s) {
    $expectOpen    += balance((int)$s['id']);
    $expectOverdue += balance((int)$s['id'], true);
    if ($s['status'] === 'active') $expectActive++;
}
is_same(4, $expectActive, 'four of the five students are active');
ok(str_contains($html, '<strong>'.$expectActive.'</strong>'), 'the active-students tile shows '.$expectActive);
ok(str_contains($html, money($expectOpen)), 'the outstanding tile shows '.money($expectOpen));
ok(str_contains($html, money($expectOverdue)), 'the overdue tile shows '.money($expectOverdue));
is_same(8734, $expectOpen, 'settled and cancelled charges are left out of the total');
is_same(4500, $expectOverdue, 'only the charge past its due date is overdue');

case_('One student with two overlapping absences counts as one person away');
is_same(1, (int)scalar('SELECT COUNT(DISTINCT student_id) FROM absences WHERE starts_on<=? AND ends_on>=?', [today(), today()]),
    'the data really does have one person with two absences');
ok(preg_match('/<strong>1<\/strong><small>[^<]*(nicht da|away)/u', $html) === 1
   || substr_count($html, '<strong>1</strong>') >= 1, 'the away tile counts the person once, not twice');

case_('A parent sees their own children and nobody else');
sign_in_as($parent);
$parentHtml = render_view('dashboard');
ok(str_contains($parentHtml, 'Anna'), 'their own child is listed');
ok(str_contains($parentHtml, 'Bernd'), 'their other child is listed');
is_same(false, str_contains($parentHtml, 'Clara'), 'another family\'s child is not');
is_same(false, str_contains($parentHtml, 'Dieter'), 'nor the other one');
is_same(false, str_contains($parentHtml, 'Emil'), 'nor a student on no account');

case_('A parent is shown what their own children owe, and only that');
$ours = balance($mine[0]) + balance($mine[1]);
is_same(7500, $ours, 'the two children owe 75.00');
ok($ours !== $expectOpen, 'which is not the same number as the club total, so the next two checks can tell them apart');
ok(str_contains($parentHtml, money($ours)), 'the total shown is their two children\'s');
is_same(false, str_contains($parentHtml, money($expectOpen)), 'not the whole club\'s outstanding total');

case_('Every page renders for the role that is allowed to open it');
$pages = ['dashboard','students','messages','news','profile'];
sign_in_as($parent);
foreach ($pages as $page)
    does_not_throw(fn() => render_view($page), 'parent: '.$page);
sign_in_as($trainer);
foreach (array_merge($pages, ['classes','payments','accounts','compose','outbox']) as $page)
    does_not_throw(fn() => render_view($page), 'trainer: '.$page);

case_('No page leaks a PHP error or an unrendered escape into its HTML');
sign_in_as($trainer);
foreach (array_merge($pages, ['classes','payments','accounts','compose','outbox']) as $page) {
    $out = render_view($page);
    foreach (['Fatal error','Warning:','Deprecated:','Notice:','Undefined ','Uncaught','\u20','Array to string'] as $token)
        is_same(false, str_contains($out, $token), $page.' is free of "'.$token.'"');
}

case_('Verwaltung renders every tab for a trainer, without an administrator');
$trainerView = make_account(['role'=>'trainer']); sign_in_as($trainerView);
foreach (['levels','ages','members','tariffs','templates','payments'] as $tab) {
    $html = render_view('manage', ['tab'=>$tab]);
    ok(str_contains($html, 'Verwaltung'), 'manage/'.$tab.' renders');
    ok(str_contains($html, 'Wer gehört wohin?'), 'manage/'.$tab.' explains which grouping is which');
}

case_('The placeholder list and the placeholders that actually work are the same list');
$html = render_view('manage', ['tab'=>'templates']);
foreach (array_keys(template_placeholders()) as $key)
    ok(str_contains($html, '{{'.$key.'}}'), 'the editor offers {{'.$key.'}}');
$student = one('SELECT s.*, NULL AS tariff_name FROM students s LIMIT 1') ?: ['id'=>make_student(), 'first_name'=>'Lena', 'last_name'=>'Hofer', 'tariff_name'=>'', 'level_id'=>null, 'birth_date'=>null, 'age_group_id'=>null];
$filled = template_text(implode(' ', array_map(fn($k) => '{{'.$k.'}}', array_keys(template_placeholders()))), $student);
ok(!str_contains($filled, '{{'), 'and every one of them is filled in when a message is sent');

case_('Einstellungen keeps only what an administrator has to decide');
sign_in_as(make_account(['role'=>'admin']));
$html = render_view('settings', ['tab'=>'portal']);
foreach (['tab=levels','tab=ages','tab=tariffs','tab=templates'] as $moved)
    ok(!str_contains($html, $moved), 'Einstellungen no longer offers '.$moved);

case_('The start page says when the next training is');
sign_in_as($trainer = make_account(['role'=>'trainer']));
$tlCourse = make_class(['name'=>'Timeline-Kurs', 'location'=>'Halle A',
                        'days'=>[['weekday'=>(int)date('N'), 'starts_at'=>'16:00:00', 'ends_at'=>'17:30:00']]]);
$html = render_view('dashboard');
ok(str_contains($html, 'Timeline-Kurs'), 'the course is on the timeline');
ok(str_contains($html, 'Heute'), 'and today is marked');
ok(str_contains($html, 'Halle A'), 'with where it is');

case_('A cancelled day says so on the start page rather than simply vanishing');
fixture('class_sessions', ['class_id'=>$tlCourse, 'session_on'=>today(), 'starts_at'=>null, 'ends_at'=>null,
    'location'=>'', 'status'=>'cancelled', 'note'=>'Halle gesperrt', 'created_by'=>$trainer, 'created_at'=>now()]);
$html = render_view('dashboard');
ok(str_contains($html, 'Entfällt'), 'it is shown as cancelled');
ok(str_contains($html, 'Halle gesperrt'), 'with the reason');

case_('Attendance is its own page and opens on a real course');
$html = render_view('attendance');
ok(str_contains($html, 'Timeline-Kurs'), 'the course picker is there');
ok(str_contains($html, 'Anwesenheit'), 'and so is the list');

case_('A saved view says what it selects, not only what it is called');
$level = (int)levels()[0]['id'];
fixture('saved_filters', ['name'=>'Montagsgruppe', 'criteria_json'=>json_encode(['course'=>$tlCourse, 'level'=>$level])]);
$html = render_view('students');
ok(str_contains($html, 'Montagsgruppe'), 'the view is offered');
ok(str_contains($html, 'Timeline-Kurs'), 'and says which course it selects');
ok(str_contains($html, (string)levels()[0]['name']), 'and which level');

case_('The proof upload is offered where a family will see it, and only when something is open');
$family = make_account(['role'=>'student', 'name'=>'Familie Berger']);
$kid = make_student(['first_name'=>'Nina', 'last_name'=>'Berger', 'account_id'=>$family]);
sign_in_as($family);
$html = render_view('dashboard');
ok(!str_contains($html, 'Schon überwiesen'), 'nothing owed, nothing to offer');
fixture('charges', ['student_id'=>$kid, 'label'=>'Monatsbeitrag', 'amount_cents'=>4500,
    'period_from'=>null, 'period_to'=>null, 'due_on'=>today(), 'cancelled'=>0, 'created_at'=>now()]);
$html = render_view('dashboard');
ok(str_contains($html, 'Schon überwiesen'), 'with an open charge the offer is on the page they land on');
ok(str_contains($html, 'Freiwillig'), 'and says it is voluntary, because it is');
sign_in_as($trainer);
ok(!str_contains(render_view('dashboard'), 'Schon überwiesen'), 'the trainer is not the one uploading it');
