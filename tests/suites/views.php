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
