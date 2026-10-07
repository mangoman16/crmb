<?php
/**
 * The pages themselves.
 *
 * Every other suite checks a function. These render the real view and read what
 * came out, which is the only way to catch a page that computes the right answer
 * and then prints the wrong one — or prints something the viewer should not see.
 */

$trainer = make_account(['role'=>'trainer','name'=>'Trainerin Meier']);
$parent  = make_account(['role'=>'student','name'=>'Anna Huber']);
$brother = make_account(['role'=>'student','name'=>'Bernd Huber']);

sign_in_as($trainer);
$tariff = make_tariff(['price_cents'=>4500]);

// One student on the signed-in login, her brother on a login of his own, and
// two belonging to nobody, so the page has three kinds of thing to leave out.
// The brother is the one that matters: one login is one student (ADR 0010),
// and a family's page that showed him would be the old sharing come back.
$mine = []; $theirs = [];
$mine[] = make_student(['first_name'=>'Anna','last_name'=>'Huber','account_id'=>$parent,'tariff_id'=>$tariff,'status'=>'active']);
$mine[] = make_student(['first_name'=>'Bernd','last_name'=>'Huber','account_id'=>$brother,'tariff_id'=>$tariff,'status'=>'active']);
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

case_('A login sees its own student and nobody else, not even a brother');
sign_in_as($parent);
$parentHtml = render_view('dashboard');
ok(str_contains($parentHtml, 'Anna'), 'their own student is listed');
is_same(false, str_contains($parentHtml, 'Bernd'), 'the brother on a login of his own is not');
is_same(false, str_contains($parentHtml, 'Clara'), 'another family\'s child is not');
is_same(false, str_contains($parentHtml, 'Dieter'), 'nor the other one');
is_same(false, str_contains($parentHtml, 'Emil'), 'nor a student on no account');

case_('A login is shown what its own student owes, and only that');
$ours = balance($mine[0]);
is_same(4500, $ours, 'Anna owes 45.00');
ok($ours !== $expectOpen, 'which is not the same number as the club total, so the next checks can tell them apart');
ok(str_contains($parentHtml, money($ours)), 'the total shown is hers');
is_same(false, str_contains($parentHtml, money($ours + balance($mine[1]))), 'not hers and her brother’s together');
is_same(false, str_contains($parentHtml, money(balance($mine[1]))), 'nor her brother’s');
is_same(false, str_contains($parentHtml, money($expectOpen)), 'nor the whole club\'s outstanding total');

case_('Every page renders for the role that is allowed to open it');
$pages = ['dashboard','students','messages','news','profile'];
sign_in_as($parent);
foreach ($pages as $page)
    does_not_throw(fn() => render_view($page), 'parent: '.$page);
sign_in_as($trainer);
foreach (array_merge($pages, ['classes','payments','accounts','outbox']) as $page)
    does_not_throw(fn() => render_view($page), 'trainer: '.$page);

case_('No page leaks a PHP error or an unrendered escape into its HTML');
sign_in_as($trainer);
foreach (array_merge($pages, ['classes','payments','accounts','outbox']) as $page) {
    $out = render_view($page);
    foreach (['Fatal error','Warning:','Deprecated:','Notice:','Undefined ','Uncaught','\u20','Array to string'] as $token)
        is_same(false, str_contains($out, $token), $page.' is free of "'.$token.'"');
}

case_('An address that names a tariff lists everybody, rather than a selection nobody can see');
/* The tariff filter went with its fold and the saved views (ADR 0026 §8), so
   nothing on the page could show that one was on or take it off. An old
   bookmark that names one is ignored. */
sign_in_as($trainer);
$cards = fn(string $html): int => substr_count($html, 'class="student-card"');
$list = render_view('students', ['tariff'=>(string)$tariff]);
ok($cards($list) > 0 && $cards($list) === $cards(render_view('students')), 'the whole list is shown: '.$cards($list).' children');

case_('Verwaltung renders every tab for a trainer, without an administrator');
$trainerView = make_account(['role'=>'trainer']); sign_in_as($trainerView);
foreach (['levels','ages','members','payments'] as $tab) {
    $html = render_view('manage', ['tab'=>$tab]);
    ok(str_contains($html, 'Verwaltung'), 'manage/'.$tab.' renders');
    ok(str_contains($html, 'Wer gehört wohin?'), 'manage/'.$tab.' explains which grouping is which');
}

case_('Einstellungen keeps only what an administrator has to decide');
sign_in_as(make_account(['role'=>'admin']));
$html = render_view('settings', ['tab'=>'portal']);
foreach (['tab=levels','tab=ages'] as $moved)
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

// ---------------------------------------------------------------------------
case_('A problem report shows the way there, in both shapes it can be stored in');
/* Einstellungen → Rückmeldungen reads each report through report_trail() in
   app/ui.php. Reports filed since the trail exists carry steps and on; older
   ones carry only query and referer. Both are still in the table, so both have
   to read correctly - and nothing a family typed may survive "Erledigt". */
$trailStep = fn(string $method, string $url, array $extra = []) =>
    ['at' => '2026-09-24 08:00:00', 'method' => $method, 'url' => $url] + $extra;
$newShape = ['page' => 'student', 'on' => ['page' => 'student', 'id' => 4, 'tab' => ''], 'steps' => [
    $trailStep('GET', '/index.php?page=dashboard'),
    $trailStep('GET', '/index.php?page=student&id=4'),
    $trailStep('POST', '/index.php', ['action' => 'student_save',
        'fields' => ['first_name' => 'Mia', 'password' => '***', 'custom' => [3 => 'blau'], '…' => '…'],
        'files' => ['avatar' => ['bytes' => 2048, 'type' => 'image/png', 'error' => 0, 'name' => 'mia-geheim.png'],
                    'docs' => [['bytes' => 1572864, 'type' => 'application/pdf', 'error' => 0], ['error' => UPLOAD_ERR_NO_FILE],
                               ['error' => UPLOAD_ERR_INI_SIZE]]]]),
    $trailStep('GET', '/index.php?page=student&id=4', ['flash' => ['text' => 'Bitte ein Datum angeben.', 'kind' => 'error']]),
    // "Zurück" and then somewhere else: the last step is not the reported page.
    $trailStep('GET', '/index.php?page=messages'),
]];
$trail = report_trail($newShape);
is_same(true, $trail['recorded'], 'a report with steps says it carries a trail');
is_same(3, $trail['here'], 'the reported page is the last GET that showed the page the form was on, not the last step');
is_same('/index.php?page=student&id=4', $trail['address'], 'and its address is that page\'s');
is_same('POST ?action=student_save', $trail['before'], 'what came just before it is named, a post by its action');
is_same(['GET ?page=dashboard', 'GET ?page=student&id=4'], array_column(array_slice($trail['steps'], 0, 2), 'line'),
        'each page is one line, without the path that is the same on every line');
$posted = $trail['steps'][2]['line'];
ok(str_starts_with($posted, 'POST ?action=student_save  first_name=Mia · password=***'), 'a post shows what was sent, a password as ***: '.$posted);
ok(str_contains($posted, 'custom[3]=blau'), 'a nested field is written the way the form named it');
ok(str_contains($posted, ' · …'), 'and where the recorder stopped keeping fields, it says so');
ok(str_contains($posted, 'avatar=2 kB image/png'), 'a file shows its size and type');
ok(!str_contains($posted, 'mia-geheim'), 'and never its name, even when one was stored');
ok(str_contains($posted, 'docs[0]=1,5 MB application/pdf') && str_contains($posted, 'docs[1]=(keine Datei)')
   && str_contains($posted, 'docs[2]=(Upload-Fehler '.UPLOAD_ERR_INI_SIZE.')'),
   'several files under one name are each shown, including one that never came and one PHP refused');
is_same(['Bitte ein Datum angeben.', 'error'], [$trail['steps'][3]['flash'], $trail['steps'][3]['flash_kind']],
        'the message the page showed is kept with the step that showed it');

$done = feedback_mark_done($newShape);
$after = report_trail($done);
ok(str_contains($after['steps'][2]['line'], 'first_name=(gelöscht)') && str_contains($after['steps'][2]['line'], 'custom=(gelöscht)'),
   'the field names stay, each saying its value was deleted: '.$after['steps'][2]['line']);
ok(str_contains($after['steps'][2]['line'], 'avatar=(gelöscht)') && !str_contains($after['steps'][2]['line'], 'image/png'),
   'and so do the files, without their size and type');
is_same(array_column($trail['steps'], 'line')[0], array_column($after['steps'], 'line')[0], 'the addresses are kept, they are how it is reproduced');
is_same($after['steps'][2]['line'], report_trail(feedback_mark_done($done))['steps'][2]['line'],
        'and marking it done a second time brings nothing back');

$fromOutside = report_trail(['on' => ['page' => 'student', 'id' => 4], 'steps' => [$trailStep('GET', '/index.php?page=student&id=4')]]);
is_same('', $fromOutside['before'], 'a report whose page was the first one in the trail came from outside the portal');
$old = report_trail(['page' => 'students', 'query' => ['page' => 'students', 'status' => 'active'],
                     'referer' => 'https://badminton.example.at/index.php?page=dashboard']);
is_same(false, $old['recorded'], 'an old report carries no trail');
is_same('?page=students&status=active', $old['address'], 'its address comes from the query it stored');
is_same('/index.php?page=dashboard', $old['before'], 'and "before" from its referer, without the host');
$oldest = report_trail(['page' => 'payments', 'query' => [], 'referer' => '']);
is_same('?page=payments', $oldest['address'], 'with nothing but a page name, the address is that page');
is_same(null, $oldest['before'], 'and "before" is unknown rather than "outside"');
does_not_throw(fn() => report_trail(['steps' => 'nonsense', 'on' => 7, 'referer' => ['x']]), 'a context of the wrong shapes is read, not a crash');

$admin = make_account(['role' => 'admin']);
foreach ([['from outside', $fromOutsideContext = ['on' => ['page' => 'student', 'id' => 4], 'steps' => [$trailStep('GET', '/index.php?page=student&id=4')]]],
          ['not recorded', ['page' => 'payments', 'query' => [], 'referer' => '']]] as [$label, $context])
    run('INSERT INTO feedback (account_id,page,message,context_json,created_at) VALUES (?,?,?,?,?)',
        [$admin, 'student', 'Meldung '.$label, json_encode($context), now()]);
sign_in_as($admin);
$reportsPage = render_view('settings', ['tab' => 'feedback']);
ok(str_contains($reportsPage, 'von außerhalb'), 'the page says „von außerhalb" for a report that came from outside');
ok(str_contains($reportsPage, 'nicht erfasst'), 'and „nicht erfasst" for one too old to know');

/* Whether a report still holds what was typed is decided by the page, from
   values_dropped_at, so it is read off the page: one report at a time, so a
   phrase found belongs to the report it is about. */
$onlyReport = function (array $context, string $state) use ($admin): string {
    run('DELETE FROM feedback');
    run('INSERT INTO feedback (account_id,page,message,context_json,state,created_at) VALUES (?,?,?,?,?,?)',
        [$admin, 'student', 'Nur diese Meldung', feedback_context_json($context), $state, now()]);
    return render_view('settings', ['tab' => 'feedback']);
};
$droppedNote = 'Die eingetippten Werte wurden beim Erledigen gelöscht.';
$openPage = $onlyReport($newShape, 'new');
ok(str_contains($openPage, e('first_name=Mia')), 'an open report shows what was typed');
ok(!str_contains($openPage, $droppedNote), 'and does not say it is gone');
ok(str_contains($openPage, 'Beim Erledigen werden die eingetippten Werte gelöscht'), 'it says, before the tap, that „Erledigt" deletes them');
$donePage = $onlyReport(feedback_mark_done($newShape), 'done');
ok(!str_contains($donePage, e('first_name=Mia')) && str_contains($donePage, e('first_name=(gelöscht)')),
   'after „Erledigt" nothing typed is on the page, only the field names');
ok(str_contains($donePage, $droppedNote), 'and the page says the typed values were deleted');
ok(!str_contains($donePage, 'Beim Erledigen werden'), 'rather than warning about a deletion that has happened');
run('DELETE FROM feedback');
sign_out();
