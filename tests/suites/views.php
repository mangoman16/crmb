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

case_('The age line under the date of birth says the band, and a gap in the bands only to staff');
/* Part 1, revised 2026-10-08: a child's band follows from the date of birth
   alone, and shows once, under the date. When no band covers the age, staff
   read so - the gap is theirs to close under Verwaltung - and a family reads
   only the age. */
$coach = make_account(['role'=>'trainer', 'name'=>'Band Trainerin']);
$familyLogin = make_account(['role'=>'student', 'name'=>'Familie Lücke']);
$youth = make_student(['first_name'=>'Jana', 'last_name'=>'Jugend', 'birth_date'=>date('Y-m-d', strtotime('-14 years -10 days'))]);
$gapChild = make_student(['first_name'=>'Gero', 'last_name'=>'Lücke', 'birth_date'=>date('Y-m-d', strtotime('-19 years -10 days')), 'account_id'=>$familyLogin]);
$noDate = make_student(['first_name'=>'Ohne', 'last_name'=>'Datum', 'birth_date'=>null]);
sign_in_as($coach);
ok(str_contains(render_view('student', ['id'=>$youth]), e('14 Jahre alt · Altersgruppe: Jugend')), 'a covered age names its band');
ok(str_contains(render_view('student', ['id'=>$noDate]), e('Fehlt noch. Danach richtet sich die Altersgruppe.')), 'no date of birth says it is missing');
ok(!str_contains(render_view('student', ['id'=>$youth]), 'name="age_group_id"'), 'and nobody pins a band: the select is gone');
// A gap: adults start at 21, so a nineteen-year-old is in no band.
run("UPDATE age_groups SET min_age=21 WHERE name='Erwachsene'");
$staffSees = render_view('student', ['id'=>$gapChild]);
ok(str_contains($staffSees, e('19 Jahre alt · keine Altersgruppe passt')), 'staff read that no band covers the age');
sign_in_as($familyLogin);
$familySees = render_view('student', ['id'=>$gapChild]);
ok(str_contains($familySees, e('19 Jahre alt')) && !str_contains($familySees, e('keine Altersgruppe passt')) && !str_contains($familySees, e('Altersgruppe:')),
   'a family reads only the age: the gap is the trainer’s to close');
run("UPDATE age_groups SET min_age=18 WHERE name='Erwachsene'");
ok(str_contains(render_view('student', ['id'=>$gapChild]), e('19 Jahre alt · Altersgruppe: Erwachsene')), 'and the band once it covers the age again');
sign_out();

case_('The students list: the search, the quick selection, the filter fold and the order (Part 1, revised 2026-10-08)');
/* Staff find a child by name first; the quick selection starts afresh, the
   fold's row says what is chosen, the order keeps the filters, and a row says
   the age group and the level - under a group's own header the age. Every
   control is a GET link or a GET form. */
test_reset(); sign_in_as($coach = make_account(['role'=>'trainer', 'name'=>'Listen Trainerin']));
$aged = fn(int $years): string => (new DateTimeImmutable(today()))->modify('-'.$years.' years')->modify('-100 days')->format('Y-m-d');
$course = make_class(['name'=>'Kindertraining']);
$level = level_default();
$unter12 = (int)scalar("SELECT id FROM age_groups WHERE name='Unter 12'");
$jugend = (int)scalar("SELECT id FROM age_groups WHERE name='Jugend'");
run("UPDATE age_groups SET min_age=21 WHERE name='Erwachsene'");   // a gap: a nineteen-year-old is in no group
$anna = make_student(['first_name'=>'Anna', 'last_name'=>'Alt', 'birth_date'=>$aged(9), 'level_id'=>$level['id']]);
$ben = make_student(['first_name'=>'Ben', 'last_name'=>'Berg', 'birth_date'=>$aged(14), 'level_id'=>$level['id']]);
$cora = make_student(['first_name'=>'Cora', 'last_name'=>'Clever', 'birth_date'=>null, 'level_id'=>$level['id']]);
$dan = make_student(['first_name'=>'Dan', 'last_name'=>'Dazwischen', 'birth_date'=>$aged(19), 'level_id'=>$level['id']]);
make_enrolment($course, $anna);
$decoded = fn(string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$subtitleOf = fn(string $html, string $name): string => preg_match('~<h3>'.preg_quote(e($name), '~').'</h3><p>([^<]*)</p>~', $html, $m) ? $decoded($m[1]) : '(no row)';
$rowOf = fn(string $html, string $name): string => preg_match('~<a class="student-card"[^>]*>(?:(?!</a>).)*<h3>'.preg_quote(e($name), '~').'</h3>.*?</a>~s', $html, $m) ? $m[0] : '';
$control = fn(string $html, string $label): string => preg_match('~<nav class="tabs is-segmented" aria-label="'.preg_quote(e($label), '~').'">(.*?)</nav>~s', $html, $m) ? $m[1] : '';
$fold = fn(string $html): string => preg_match('~<details class="card filter-fold">(.*?)</details>~s', $html, $m) ? $m[1] : '';
$sections = fn(string $html): array => preg_match_all('~<section class="card age-section" id="([^"]+)"~', $html, $m) ? $m[1] : [];
$list = render_view('students');
ok(str_contains($list, '<form method="get" class="search" role="search">') && str_contains($list, 'type="search" name="q"') && str_contains($list, 'placeholder="'.e('Suchen').'"'),
   'the search is a form of its own, a box that says „Suchen"');
$quick = $control($list, 'Auswahl');
ok(str_contains($quick, 'aria-current="page" href="'.e(url('students')).'"') && str_contains($quick, 'href="'.e(url('students', ['overdue'=>1])).'"') && str_contains($quick, 'href="'.e(url('students', ['absence'=>'sick'])).'"'),
   '„Alle | Überfällig | Krank" is a segmented control of three plain links, „Alle" lit');
ok(str_contains($fold($list), '<summary><span>'.e('Filter').'</span><svg'), 'the fold’s row says „Filter", and nothing chosen');
foreach (['course', 'status', 'level', 'age_group'] as $field) ok(str_contains($fold($list), 'name="'.$field.'"'), 'it asks for '.$field);
ok(preg_match('~<option value="none"[^>]*>'.preg_quote(e('Ohne Altersgruppe'), '~').'</option>~', $fold($list)) === 1, 'with „Ohne Altersgruppe" among the groups');
ok(!str_contains($fold($list), 'name="absence"') && !str_contains($fold($list), 'type="checkbox"') && !str_contains($fold($list), 'name="q"'),
   'and no longer for an absence, the overdue or the search, which stand on their own');
ok(str_contains($fold($list), e('Anwenden')), 'with „Anwenden"');
$sorted = render_view('students', ['course'=>(string)$course]);
$sort = $control($sorted, 'Sortieren');
ok(str_contains($sort, 'aria-current="page" href="'.e(url('students', ['course'=>(string)$course])).'"') && str_contains($sort, 'href="'.e(url('students', ['course'=>(string)$course, 'sort'=>'age'])).'"'),
   '„A–Z | Nach Alter" keeps the filters, A–Z lit');
ok(str_contains($control($sorted, 'Auswahl'), 'href="'.e(url('students', ['overdue'=>1])).'"'), 'while „Überfällig" starts afresh');
ok(str_contains($sorted, '<input type="hidden" name="course" value="'.$course.'">'), 'and the search keeps the course');
$chosen = render_view('students', ['course'=>(string)$course, 'level'=>(string)$level['id'], 'age_group'=>(string)$unter12, 'sort'=>'age', 'q'=>'A']);
ok(str_contains($fold($chosen), '<span class="filter-value">'.e('Kindertraining · '.$level['name'].' · Unter 12').'</span>'), 'chosen, the row names the choices in the fields’ order');
ok(str_contains($fold($chosen), '<input type="hidden" name="q" value="A">') && str_contains($fold($chosen), '<input type="hidden" name="sort" value="age">'),
   'and „Anwenden" keeps the search and the order');
is_same(['Anna Alt', 'Ben Berg', 'Cora Clever', 'Dan Dazwischen'], array_map($decoded, preg_match_all('~<h3>([^<]*)</h3>~', $list, $m) ? $m[1] : []), 'A–Z is by last name');
is_same('Unter 12 · '.$level['name'], $subtitleOf($list, 'Anna Alt'), 'in A–Z a row says the age group and the level');
is_same('19 Jahre · '.$level['name'], $subtitleOf($list, 'Dan Dazwischen'), 'an age no group covers says the age');
is_same('Geburtsdatum fehlt · '.$level['name'], $subtitleOf($list, 'Cora Clever'), 'and no birth date says so');
ok(!str_contains($list, '€') && !str_contains($list, e('in keinem Kurs')), 'the price left the row');
$overview = render_view('dashboard');   // the same rows on the overview's Schüler group; nobody here is overdue, so „€" can only be a price
ok(preg_match_all('~<a class="student-card".*?</a>~s', $overview, $m) >= 1 && !str_contains(implode('', $m[0]), '€'), 'and from the overview’s Schüler rows');
ok(!str_contains($rowOf($list, 'Anna Alt'), e('Ohne Kurs')) && str_contains($rowOf($list, 'Ben Berg'), '<span class="badge amber">'.e('Ohne Kurs').'</span>'),
   'a child in no running course wears „Ohne Kurs" instead');
$byAge = render_view('students', ['sort'=>'age']);
foreach (['age-group-'.$unter12 => ['Unter 12', 'bis 11'], 'age-group-'.$jugend => ['Jugend', '12 bis 17'], 'no-age-group' => ['Ohne Altersgruppe', ''], 'no-birth-date' => ['Ohne Geburtsdatum', '']] as $key => [$title, $span])
    ok(preg_match('~<section class="card age-section" id="'.$key.'">\s*<div class="section-heading"><div><h2>'.preg_quote(e($title), '~').'</h2>\s*'
                  .($span !== '' ? '<small>'.preg_quote(e($span), '~').'</small>' : '').'\s*</div><span class="badge ">1</span></div>~', $byAge) === 1,
       '„Nach Alter" has the group '.$title.($span !== '' ? ' ('.$span.')' : '').', its count beside it');
is_same(['age-group-'.$unter12, 'age-group-'.$jugend, 'no-age-group', 'no-birth-date'], $sections($byAge), 'in Verwaltung’s order, then no group, then no birth date');
ok(!str_contains($list, e('Nach Nachnamen sortiert')) && !str_contains($byAge, e('Nach Nachnamen sortiert')), 'and the line „Nach Nachnamen sortiert" is gone: the control says the order');
is_same('9 Jahre · '.$level['name'], $subtitleOf($byAge, 'Anna Alt'), 'under a group’s header a row says the age and the level');
ok(str_contains($byAge, '<p class="section-footer">'.e('Keine deiner Altersgruppen passt.').' <a href="'.e(url('manage', ['tab'=>'ages'])).'">'.e('Altersgruppen ansehen').'</a></p>'),
   '„Ohne Altersgruppe" says the groups leave a gap, with the way to them');
$withBand = render_view('students', ['age_group'=>(string)$unter12]);
ok(str_contains($withBand, e('1 Kind ohne Geburtsdatum ist nicht dabei.')) && str_contains($withBand, 'href="'.e(url('students', ['age_group'=>'none', 'sort'=>'age', '#'=>'no-birth-date'])).'">'.e('Zeigen').'</a>'),
   'a chosen group says whom it leaves out, with „Zeigen" to them');
// Rows, not the page: the notices above the list name children too.
ok($rowOf($withBand, 'Anna Alt') !== '' && $rowOf($withBand, 'Ben Berg') === '', 'and shows only its children');
$none = render_view('students', ['age_group'=>'none']);
ok($rowOf($none, 'Cora Clever') !== '' && $rowOf($none, 'Dan Dazwischen') !== '' && $rowOf($none, 'Anna Alt') === '' && !str_contains($none, e('nicht dabei')) && !str_contains($list, e('nicht dabei')),
   '„Ohne Altersgruppe" is the children no group places - no birth date, or the gap - and neither it nor „Alle" has the line');
$nobody = render_view('students', ['q'=>'Niemand']);
ok(str_contains($nobody, '<h2>'.e('Niemand in dieser Auswahl').'</h2>') && str_contains($nobody, e(url('students')).'">'.e('Alle Schüler').'</a>'), 'a selection with nobody in it offers „Alle Schüler"');
run('DELETE FROM age_groups');
$noBands = render_view('students', ['sort'=>'age']);
ok(str_contains($noBands, e('Es gibt noch keine Altersgruppen.')) && str_contains($noBands, e(url('manage', ['tab'=>'ages'])).'">'.e('Altersgruppen anlegen').'</a>'),
   'without any group, the list says so and leads to Verwaltung');
is_same(['no-age-group', 'no-birth-date'], $sections($noBands), 'and every child with a birth date is under „Ohne Altersgruppe"');
ok(!str_contains($noBands, 'class="section-footer"'), 'without a footer saying none of them fits');
for ($i = 0; $i < 50; $i++) make_student(['first_name'=>'Viele', 'last_name'=>'Kind'.str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'level_id'=>$level['id']]);
$first = render_view('students', ['sort'=>'age', 'q'=>'e']);
ok(str_contains($first, 'href="'.e(url('students', ['q'=>'e', 'sort'=>'age', 'p'=>2])).'"'), 'the pager keeps the search and the order');
ok(substr_count($first, 'class="student-card"') === 50 && preg_match('~id="no-birth-date">.*?<span class="badge ">51</span>~s', $first) === 1,
   'a group that runs on to the next page keeps the count of the whole group');
$second = render_view('students', ['q'=>'e', 'sort'=>'age', 'p'=>'2']);
ok(substr_count($second, 'class="student-card"') === 3 && str_contains($second, '<span class="badge ">51</span>') && $sections($second) === ['no-birth-date'],
   'and the next page continues it under the same header');
run('DELETE FROM class_students'); run('DELETE FROM students');
$empty = render_view('students');
ok(str_contains($empty, '<h2>'.e('Noch keine Schüler').'</h2>') && str_contains($empty, e(url('student_new', ['from'=>'students']))), 'with no student at all, the list offers the wizard');
sign_out();

// ---------------------------------------------------------------------------
// Children's pictures on the pages (ADR 0031; the screens' spec)
// ---------------------------------------------------------------------------
$pictureTrainer = make_account(['role'=>'trainer', 'name'=>'Trainerin Foto']);
$pictureAdmin = make_account(['role'=>'admin', 'name'=>'Admin Foto']);
$miaLogin = make_account(['role'=>'student', 'name'=>'Mia Berger']);
$mia = make_student(['first_name'=>'Mia', 'last_name'=>'Berger', 'account_id'=>$miaLogin]);
$leo = make_student(['first_name'=>'Leo', 'last_name'=>'Platz']);        // a placeholder login: nobody signed in yet
$pictureCourse = make_class(['name'=>'Foto-Kurs']);
make_enrolment($pictureCourse, $mia); make_enrolment($pictureCourse, $leo);
if (!is_dir(upload_dir('picture'))) mkdir(upload_dir('picture'), 0775, true);
/** A picture on student $id, as has_picture() asks for one: a stored name whose file is there. */
$givePicture = function (int $id, int $shared = 0): void {
    $name = bin2hex(random_bytes(16)).'.jpg';
    file_put_contents(upload_dir('picture').'/'.$name, 'picture');
    run('UPDATE students SET picture_name=?, course_sees_picture=? WHERE id=?', [$name, $shared, $id]);
};
$noPicture = fn(int $id) => run("UPDATE students SET picture_name='', course_sees_picture=0 WHERE id=?", [$id]);
$xpath = function (string $html): DOMXPath {
    $dom = new DOMDocument();
    $quiet = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors(); libxml_use_internal_errors($quiet);
    return new DOMXPath($dom);
};
$hasClass = fn(string $name): string => "contains(concat(' ', normalize-space(@class), ' '), ' $name ')";
$formFor = fn(string $action): string => './/form[.//input[@name="action" and @value="'.$action.'"]]';
/** What the picture card on the Profil tab shows: the row, its sheet, the switch, staff's button and line. */
$card = function (string $html) use ($xpath, $hasClass, $formFor): array {
    $x = $xpath($html);
    $c = $x->query('//section[@id="picture"]')->item(0);
    if (!$c) return ['no card'];
    $text = function (string $q) use ($x, $c): ?string { $n = $x->query($q, $c)->item(0); return $n ? trim((string)preg_replace('/\s+/', ' ', $n->textContent)) : null; };
    $switch = $x->query('.//input[@type="checkbox" and @name="on"]', $c)->item(0);
    $sheet = $x->query('.//details[@data-sheet]', $c)->item(0);
    return ['row' => $text('.//*['.$hasClass('member-row').']//strong').' / '.$text('.//*['.$hasClass('member-row').']//small'),
            'sheet' => $sheet ? $sheet->getAttribute('data-sheet-title') : null,
            'switch' => $switch ? ($switch->hasAttribute('checked') ? 'on' : 'off') : null,
            'hide' => $x->query($formFor('picture_consent').'[.//input[@name="on" and @value="0"]]', $c)->length > 0,
            'line' => $text('.//*['.$hasClass('picture-state').']/p') ?? $text('.//p['.$hasClass('picture-note').']')];
};
$state = fn(string $row, ?string $sheet, ?string $switch, bool $hide, ?string $line) => compact('row', 'sheet', 'switch', 'hide', 'line');

case_('The picture card says who sees the photo, and only the family can show it to the course [ADR 0031]');
/* The spec's table of states, one check each. A family gets the switch; staff
   only ever take the course view away, so they get a line and, while the
   family's yes stands, a button - never a switch. Nothing about the course
   while there is no picture or the club shows none in a course. The note
   shows other families exactly what they see: the child's own initials. */
set_setting('pictures_in_course', true);
sign_in_as($miaLogin);
$profile = fn(int $id) => $card(render_view('student', ['id'=>(string)$id]));
is_same($state('Foto hinzufügen / Freiwillig. Ohne Foto stehen hier deine Anfangsbuchstaben.', null, null, false, null), $profile($mia),
        'the family, no photo: the row adds one, and says it is voluntary');
$givePicture($mia, 0);
is_same($state('Foto ändern / Dein Trainerteam sieht es.', 'Foto von Mia', 'off', false, 'Die anderen im Kurs sehen nur „MB“.'), $profile($mia),
        'the family, a photo the course does not see: the switch off, and what the others see instead');
run('UPDATE students SET course_sees_picture=1 WHERE id=?', [$mia]);
is_same($state('Foto ändern / Dein Trainerteam und dein Kurs sehen es.', 'Foto von Mia', 'on', false,
               'Die Kinder in deinem Kurs und ihre Familien sehen es im Kurs-Chat. Ausschalten geht jederzeit.'), $profile($mia),
        'the family, a photo the course sees: the switch on, and who sees it');
set_setting('pictures_in_course', false);
is_same($state('Foto ändern / Dein Trainerteam sieht es.', 'Foto von Mia', null, false, null), $profile($mia),
        'with the club showing no photos in a course, the family is not asked');
set_setting('pictures_in_course', true);
sign_in_as($pictureTrainer);
is_same($state('Foto ändern / Die Familie sieht es und kann es ändern.', 'Foto von Mia', null, true,
               'Im Kurs-Chat sichtbar, weil die Familie zugestimmt hat. Wieder einschalten kann dann nur sie.'), $profile($mia),
        'staff, the family said yes: no switch, a button that only hides it, and why only the family can undo that');
run('UPDATE students SET course_sees_picture=0 WHERE id=?', [$mia]);
is_same($state('Foto ändern / Die Familie sieht es und kann es ändern.', 'Foto von Mia', null, false, 'Im Kurs-Chat nicht sichtbar. Das entscheidet die Familie.'),
        $profile($mia), 'staff, no yes: nothing to press, and who decides');
$givePicture($leo, 0);
is_same($state('Foto ändern / Die Familie sieht es und kann es ändern.', 'Foto von Leo', null, false,
               'Im Kurs-Chat nicht sichtbar. Zustimmen kann die Familie, sobald sie sich angemeldet hat.'), $profile($leo),
        'staff, a child nobody has signed in for yet: the family can agree once they have');
$noPicture($leo);
is_same($state('Foto hinzufügen / Die Familie sieht es, sobald sie sich angemeldet hat.', null, null, false, null), $profile($leo),
        'staff adding the first photo of such a child: the family sees it once they sign in');
$noPicture($mia);
is_same($state('Foto hinzufügen / Die Familie bekommt einen Hinweis und kann es ändern.', null, null, false, null), $profile($mia),
        'staff adding the first photo of a child whose family has signed in: they are told');
$page = render_view('student', ['id'=>(string)$mia]);
$x = $xpath($page);
ok($x->query('//section[@id="picture"]//label['.$hasClass('member-row').']//input[@type="file" and @name="picture" and not(@capture)]')->length === 1,
   'the row is the label of the photo field, so the phone offers its library and its camera');
is_same(1, deepest_form_nesting($page), 'and the card\'s forms stand beside the child\'s form, never inside it');

case_('At attendance a child with no photo is a button that takes one, after saving the marks [ADR 0031]');
/* The face of a child without a photo submits the attendance form with
   photo={id}, so what she ticked is saved before the photo; the list then
   opens the photo sheet for that child, with the rear camera at once. */
sign_in_as($pictureTrainer);
$givePicture($mia, 0); $noPicture($leo);
$list = fn(array $query = []) => $xpath(render_view('attendance', ['id'=>(string)$pictureCourse, 'on'=>today()] + $query));
$x = $list();
$takes = $x->query(substr($formFor('attendance_save'), 1).'//button['.$hasClass('avatar-add').' and @type="submit" and @name="photo"]');
is_same([(string)$leo], array_map(fn($b) => $b->getAttribute('value'), iterator_to_array($takes)),
        'the child without a photo is a button of the attendance form, the one with a photo is not');
is_same('Foto von Leo aufnehmen', $takes->item(0)?->getAttribute('aria-label'), 'which says whose photo it takes');
is_same(1, $x->query('//div['.$hasClass('attendance-name').']//img')->length, 'and the child with a photo shows it');
is_same(0, $x->query('//details['.$hasClass('picture-sheet').']')->length, 'no sheet until a face is tapped');
$x = $list(['photo'=>(string)$leo]);
$sheet = $x->query('//details['.$hasClass('picture-sheet').' and @open]')->item(0);
ok($sheet && $sheet->getAttribute('data-sheet-title') === 'Foto von Leo'
   && $x->query($formFor('picture_save').'[.//input[@name="student_id" and @value="'.$leo.'"]][.//input[@name="class_id" and @value="'.$pictureCourse.'"]]'
                .'//input[@type="file" and @name="picture" and @capture="environment"]', $sheet)->length === 1,
   'photo={id} opens the sheet for that child: the rear camera, sent back to the same course');
is_same(0, $x->query('//form//details['.$hasClass('picture-sheet').']')->length, 'the sheet stands outside the attendance form');
is_same(0, $list(['photo'=>(string)$mia])->query('//details['.$hasClass('picture-sheet').']')->length, 'not for a child who has a photo');
is_same(0, $list(['photo'=>'999999'])->query('//details['.$hasClass('picture-sheet').']')->length, 'nor for a child not in the list');

case_('Faces in the course\'s list, on Zugänge, and the club\'s setting [ADR 0031]');
$x = $xpath(render_view('classes', ['id'=>(string)$pictureCourse]));
is_same([1, 1], [$x->query('//div['.$hasClass('record-who').'][.//a[contains(@href,"id='.$mia.'")]]//img')->length,
                 $x->query('//div['.$hasClass('record-who').'][.//a[contains(@href,"id='.$leo.'")]]//span['.$hasClass('avatar').']')->length],
        'the course\'s members each have a face beside the name: the photo, or the initials');
sign_in_as($pictureAdmin);
$x = $xpath(render_view('accounts'));
ok($x->query('//a['.$hasClass('member-row').' and contains(@href,"id='.$mia.'")]//span['.$hasClass('avatar').']/img')->length === 1,
   'Zugänge draws the child\'s photo on the child\'s row');
/* A team member's row drew its face from the name alone, so a trainer's photo
   never showed there (mobile-tester, 2026-10-08). */
$teamFace = bin2hex(random_bytes(16)).'.jpg';
file_put_contents(upload_dir('picture').'/'.$teamFace, 'picture');
run('UPDATE accounts SET picture_name=? WHERE id=?', [$teamFace, $pictureTrainer]);
$x = $xpath(render_view('accounts'));
ok($x->query('//*['.$hasClass('member-row').'][.//strong[normalize-space()="Trainerin Foto"]]//span['.$hasClass('avatar').']'
             .'/img[contains(@src,"kind=account&id='.$pictureTrainer.'&")]')->length === 1,
   'and a team member\'s own photo on theirs');
run("UPDATE accounts SET picture_name='' WHERE id=?", [$pictureTrainer]);
$x = $xpath(render_view('settings', ['tab'=>'privacy']));
ok($x->query('//section[@id="pictures"]//input[@type="checkbox" and @name="set_pictures_in_course"]')->length === 1,
   'Einstellungen › Datenschutz has the club\'s switch for photos in a course, above the notice');
ok($x->query('//section[@id="pictures"]//details['.$hasClass('advanced-settings').']//input[@name="set_consent_age"]')->length === 1,
   'and, under „Erweitert", the age from which a child agrees alone');
sign_out();

case_('In a course\'s group, a face beside each run of bubbles, and staff named with the pill [ADR 0031; the security review, 2026-10-08]');
/* The faces came with the pictures and the pill with the security review, on
   the same line of a group: a writer's face by the rule every face follows
   (avatar()), their name by chat_sender(), and the role never as words after
   the name again. */
$miaBefore = one('SELECT picture_name, course_sees_picture FROM students WHERE id=?', [$mia]);
$teamFace = bin2hex(random_bytes(16)).'.jpg';
file_put_contents(upload_dir('picture').'/'.$teamFace, 'picture');
run('UPDATE accounts SET picture_name=? WHERE id=?', [$teamFace, $pictureTrainer]);
$givePicture($mia, 1);
$pictureGroup = course_group_thread($pictureCourse);
fixture('messages', ['thread_id'=>$pictureGroup, 'sender_id'=>$pictureTrainer, 'body'=>'Morgen fällt das Training aus.', 'created_at'=>now()]);
fixture('messages', ['thread_id'=>$pictureGroup, 'sender_id'=>$miaLogin, 'body'=>'Schade!', 'created_at'=>now()]);
sign_in_as($pictureAdmin);
$page = render_view('messages', ['id'=>(string)$pictureGroup]);
$x = $xpath($page);
// Each run of someone else's bubbles as [its face, the name over it, the pill beside the name].
$runs = array_map(fn(DOMElement $row) => [
    ($img = $x->query('./span['.$hasClass('avatar').']/img', $row)->item(0)) ? (str_contains($img->getAttribute('src'), 'kind=account') ? 'team photo' : 'child photo') : 'initials',
    trim((string)$x->query('.//span['.$hasClass('bubble-sender').']/span['.$hasClass('sender-name').']', $row)->item(0)?->textContent),
    ($pill = $x->query('.//span['.$hasClass('bubble-sender').']/span['.$hasClass('badge').']', $row)->item(0)) ? trim($pill->textContent) : null,
], iterator_to_array($x->query('//div['.$hasClass('message-row').' and '.$hasClass('run-start').' and not('.$hasClass('mine').')]')));
is_same([['team photo', 'Trainerin Foto', 'Trainerin'], ['child photo', 'Mia Berger', null]], $runs,
        'the trainer\'s run has her photo and her name with the pill, the child\'s the child\'s photo and name');
ok(!str_contains($page, '· '.role_label('trainer')), 'and no role written after a name');
run('UPDATE accounts SET picture_name=\'\' WHERE id=?', [$pictureTrainer]);
run('UPDATE students SET picture_name=?, course_sees_picture=? WHERE id=?', [$miaBefore['picture_name'], $miaBefore['course_sees_picture'], $mia]);
sign_out();

case_('The course switch says whose yes it is, by the child\'s age [ADR 0031, as amended: § 4 Abs. 4 DSG]');
/* Said inside the switch's own label, read before the tap: under the club's
   consent_age, or with no birth date, a parent switches it on through the
   family's login; from that age the child decides. */
sign_in_as($miaLogin);
set_setting('pictures_in_course', true);
$givePicture($mia, 0);
$hint = function () use ($xpath, $mia): ?string {
    $x = $xpath(render_view('student', ['id'=>(string)$mia]));
    $small = $x->query('//section[@id="picture"]//label[contains(concat(" ", normalize-space(@class), " "), " switch ")][.//input[@name="on"]]//small')->item(0);
    return $small ? trim($small->textContent) : null;
};
$born = fn(?int $years) => run('UPDATE students SET birth_date=? WHERE id=?', [$years === null ? null : date('Y-m-d', strtotime('-'.$years.' years -1 day')), $mia]);
$born(10);
is_same('Unter 14 schaltet das ein Elternteil ein.', $hint(), 'a child of ten: a parent switches it on, and the switch says so');
$born(15);
is_same('Das entscheidest du selbst.', $hint(), 'from fourteen: the child decides');
set_setting('consent_age', 16);
is_same('Unter 16 schaltet das ein Elternteil ein.', $hint(), 'the age is the club\'s setting');
set_setting('consent_age', 14);
$born(null);
is_same('Ohne Geburtsdatum schaltet das ein Elternteil ein.', $hint(), 'and without a birth date, a parent');
$born(10);

case_('Mein Konto: a team member\'s own photo, and none for a family [ADR 0031; the owner, 2026-10-08]');
$account = function (string $html) use ($xpath, $hasClass, $formFor): array {
    $x = $xpath($html);
    $c = $x->query('//section[@id="picture"]')->item(0);
    if (!$c) return ['no card'];
    $sheet = $x->query('.//details[@data-sheet]', $c)->item(0);
    return ['row' => trim((string)$x->query('.//*['.$hasClass('member-row').']//strong', $c)->item(0)?->textContent).' / '
                    .trim((string)$x->query('.//*['.$hasClass('member-row').']//small', $c)->item(0)?->textContent),
            'adds' => $x->query($formFor('picture_save').'[.//input[@name="kind" and @value="account"]][not(.//input[@name="remove"])]//input[@type="file" and @name="picture"]', $c)->length,
            'sheet' => $sheet ? $sheet->getAttribute('data-sheet-title') : null,
            'removes' => $x->query($formFor('picture_save').'[.//input[@name="kind" and @value="account"]][.//input[@name="remove" and @value="1"]]', $c)->length,
            'first' => $x->query('//section[@id="picture"]/following-sibling::section[@id="sign-in"]')->length === 1];
};
sign_in_as($pictureTrainer);
is_same(['row'=>'Foto hinzufügen / Das Team und die Familien sehen es.', 'adds'=>1, 'sheet'=>null, 'removes'=>0, 'first'=>true], $account(render_view('profile')),
        'a trainer without a photo: one row that adds it, before „Anmeldung"');
$teamName = bin2hex(random_bytes(16)).'.jpg';
file_put_contents(upload_dir('picture').'/'.$teamName, 'picture');
run('UPDATE accounts SET picture_name=? WHERE id=?', [$teamName, $pictureTrainer]);
sign_in_as($pictureTrainer);
$page = render_view('profile');
is_same(['row'=>'Foto ändern / Das Team und die Familien sehen es.', 'adds'=>1, 'sheet'=>'Dein Foto', 'removes'=>1, 'first'=>true], $account($page),
        'with one: the row opens the sheet „Dein Foto", with a new photo and a removal');
is_same(1, deepest_form_nesting($page), 'every form of it stands on its own');
sign_in_as($miaLogin);
is_same(['no card'], $account(render_view('profile')), 'a family\'s Mein Konto has no photo: theirs is the child\'s, on the child\'s page');

case_('„Dein Foto": one question after the first password, three ways [ADR 0031, as amended]');
/* For a family the child's picture, for a team member their own; never the
   course switch. Without a photo yet: „Foto hinzufügen" filled, „Überspringen"
   on. With one the trainer added: „Passt so" is the way on, „Anderes Foto" the
   way to their own. Both go where the first password landed before. */
$welcome = function () use ($xpath, $hasClass, $formFor): array {
    $x = $xpath(render_view('welcome'));
    $step = $x->query('//section['.$hasClass('photo-step').']')->item(0);
    if (!$step) return ['no step'];
    $form = $x->query($formFor('picture_save'), $step)->item(0);
    $hidden = [];
    foreach ($form ? $x->query('.//input[@type="hidden"]', $form) : [] as $input)
        if (in_array($input->getAttribute('name'), ['student_id', 'kind', 'to'], true)) $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
    ksort($hidden);
    $pick = $form ? $x->query('.//label['.$hasClass('picture-pick').']', $form)->item(0) : null;
    $link = fn(string $class) => ($a = $x->query('.//a['.$hasClass($class).']', $step)->item(0)) ? trim($a->textContent).' → '.$a->getAttribute('href') : null;
    return ['title' => trim((string)$x->query('//h1')->item(0)?->textContent),
            'line' => trim((string)$x->query('//div['.$hasClass('page-heading').']//p')->item(0)?->textContent),
            'pick' => $pick ? trim($pick->textContent).($x->query('.//input[@type="file" and @name="picture" and not(@capture)]', $pick)->length ? '' : ' (camera only)')
                             .(str_contains(' '.$pick->getAttribute('class').' ', ' secondary ') ? ', tinted' : ', filled') : null,
            'sends' => $hidden, 'done' => $link('photo-done'), 'skip' => $link('photo-skip'),
            'note' => trim((string)$x->query('.//p['.$hasClass('photo-note').']', $step)->item(0)?->textContent),
            'switch' => $x->query($formFor('picture_consent'))->length];
};
$childPage = e(url('student', ['id'=>$mia]));
sign_in_as($miaLogin);
$noPicture($mia);
is_same(['title'=>'Willkommen, Mia!', 'line'=>'Möchtest du ein Foto? Es ist freiwillig.', 'pick'=>'Foto hinzufügen, filled',
         'sends'=>['student_id'=>(string)$mia, 'to'=>'landing'], 'done'=>null, 'skip'=>'Überspringen → '.html_entity_decode($childPage),
         'note'=>'Dein Trainerteam sieht es. Ob auch dein Kurs es sieht, entscheidest du später auf deiner Seite.', 'switch'=>0], $welcome(),
        'a family: the child\'s photo, voluntary, „Überspringen" to the child\'s page, and no course switch');
$givePicture($mia, 0);
is_same(['title'=>'Willkommen, Mia!', 'line'=>'Dein Trainerteam hat schon ein Foto von dir hinzugefügt.', 'pick'=>'Anderes Foto, tinted',
         'sends'=>['student_id'=>(string)$mia, 'to'=>'landing'], 'done'=>'Passt so → '.html_entity_decode($childPage), 'skip'=>null,
         'note'=>'Dein Trainerteam sieht es. Ob auch dein Kurs es sieht, entscheidest du später auf deiner Seite.', 'switch'=>0], $welcome(),
        'a family whose trainer already took one: „Passt so" on, or „Anderes Foto"');
sign_in_as($pictureTrainer);
run("UPDATE accounts SET picture_name='' WHERE id=?", [$pictureTrainer]);
sign_in_as($pictureTrainer);
[$landPage, $landParams] = landing_after_sign_in(current_user());
is_same(['title'=>'Willkommen, Trainerin!', 'line'=>'Möchtest du ein Foto? Die Familien sehen es in den Chats.', 'pick'=>'Foto hinzufügen, filled',
         'sends'=>['kind'=>'account', 'to'=>'landing'], 'done'=>null, 'skip'=>'Überspringen → '.url($landPage, $landParams),
         'note'=>'Alle im Portal sehen es. Ändern kannst du es unter „Mein Konto“.', 'switch'=>0], $welcome(),
        'a team member: their own photo, „Überspringen" to where a sign-in lands');
/* A team login still left on a child's record from before ADR 0010 is where the
   two part: the server sends its first password on to the child's page, and the
   step worked the place out a third time and sent it to the overview (code
   review). Its way on is landing_after_first_password()'s, like the server's. */
$oldTeam = make_account(['role'=>'trainer', 'name'=>'Trainer Früher']);
make_student(['first_name'=>'Alt', 'last_name'=>'Eingetragen', 'account_id'=>$oldTeam]);
sign_in_as($oldTeam);
[$rulePage, $ruleParams] = landing_after_first_password(current_user());
ok([$rulePage, $ruleParams] !== landing_after_sign_in(current_user()), 'for such a login the rule and a sign-in land apart, so the next line can tell which one the step asked');
is_same('Überspringen → '.url($rulePage, $ruleParams), $welcome()['skip'], 'and its „Überspringen" goes on where the rule says, as the server does');
$childless = make_account(['role'=>'student', 'name'=>'Niemand Hier']);
sign_in_as($childless);
$x = $xpath(render_view('welcome'));
ok($x->query($formFor('picture_save'))->length === 0 && $x->query('//section['.$hasClass('photo-step').']//a['.$hasClass('button').']')->length === 1,
   'a login with nothing to put a photo on is only shown the way on');

case_('Attendance: Enter saves, „Alle" offers the usual status, „Mehr" the rarer ones [the audit, N3]');
/* The faces are submit buttons of the attendance form, and Enter in a form
   presses its first submit button: so the first is a copy of „Anwesenheit
   speichern". Then one bulk button, for the first of the club's statuses, and
   the others in a sheet - a whole class away is rare, and deliberate. */
sign_in_as($pictureTrainer);
$noPicture($leo);
set_setting('attendance_statuses', ['present'=>'Anwesend', 'absent'=>'Fehlt', 'excused'=>'Entschuldigt']);
$x = $list();
$first = $x->query(substr($formFor('attendance_save'), 1).'//button[@type="submit"]')->item(0);
is_same(['Anwesenheit speichern', '', true], [trim((string)$first?->textContent), (string)$first?->getAttribute('name'), str_contains(' '.$first?->getAttribute('class').' ', ' default-submit ')],
        'the attendance form\'s first submit button saves, before any face');
$tools = $x->query('//div['.$hasClass('attendance-tools').']')->item(0);
$bulk = fn(string $q) => array_map(fn($b) => $b->getAttribute('data-mark-all').' '.trim($b->textContent), iterator_to_array($x->query($q, $tools)));
is_same(['present Alle: Anwesend'], $bulk('./button[@data-mark-all]'), 'beside the list: „Alle: Anwesend", the first status');
is_same(['absent Alle: Fehlt', 'excused Alle: Entschuldigt'], $bulk('./details[@data-sheet]['.$hasClass('attendance-more').'][summary="Mehr"]/button[@data-mark-all]'),
        'and the others in the sheet behind „Mehr"');
set_setting('attendance_statuses', ['present'=>'Anwesend']);
$x = $list();
is_same([0, 1], [$x->query('//details['.$hasClass('attendance-more').']')->length, $x->query('//div['.$hasClass('attendance-tools').']/button[@data-mark-all]')->length],
        'a club with one status gets no „Mehr"');
set_setting('attendance_statuses', ['present'=>'Anwesend', 'absent'=>'Fehlt', 'excused'=>'Entschuldigt']);

case_('The family\'s overview greets the child by their face, which leads to their photo [the audit, N7]');
$face = function () use ($xpath, $hasClass): ?array {
    $x = $xpath(render_view('dashboard'));
    $a = $x->query('//div['.$hasClass('page-heading').' and '.$hasClass('has-face').']/a['.$hasClass('page-face').']')->item(0);
    return $a ? [$a->getAttribute('href'), $a->getAttribute('aria-label'), $x->query('./span['.$hasClass('avatar').' and '.$hasClass('large').']', $a)->length,
                 $x->query('.//img', $a)->length] : null;
};
sign_in_as($miaLogin);
$givePicture($mia, 0);
is_same([html_entity_decode(e(url('student', ['id'=>$mia, '#'=>'picture']))), 'Dein Profil', 1, 1], $face(),
        'beside „Hallo Mia": the child\'s own photo, large, a link to it at the top of the child\'s page');
$noPicture($mia);
is_same(0, $face()[3] ?? null, 'without a photo, the initials, leading to where one is added');
sign_in_as($childless);
is_same(null, $face(), 'a login with no child has no face to greet');
sign_in_as($pictureTrainer);
is_same(null, $face(), 'and staff\'s overview none');
sign_out();

case_('A page greets whoever is signed in by one first name, worked out in one place [the code review]');
/* The overview's „Hallo …" and „Dein Foto"'s „Willkommen, …!" both ask
   greeting_first_name(): a family by its child's first name, as a mail greets
   it, a team member by the first word of their own. No view works one out. */
is_same('Mia', greeting_first_name(one('SELECT * FROM accounts WHERE id=?', [$miaLogin])), 'a family by its child\'s first name');
is_same('Trainerin', greeting_first_name(['role'=>'trainer', 'name'=>'  Trainerin Foto ']), 'a team member by the first word of their own');
sign_in_as($pictureTrainer);
is_same('Hallo Trainerin', trim((string)$xpath(render_view('dashboard'))->query('//h1')->item(0)?->textContent), 'and the overview greets her so');
$splitting = array_map('basename', array_values(array_filter(glob(APP_ROOT.'/views/*.php'),
    fn($f) => preg_match("/explode\(\s*'\s'\s*,[^;]*\['name'\]/", (string)file_get_contents($f)) === 1)));
is_same([], $splitting, 'and no view splits a name into words itself');
sign_out();

case_('Without gd, a photo\'s row says so in the words a refused photo is given [ADR 0031 §4]');
/* pictures_possible() asks the PHP this suite runs on, so the row without gd
   cannot be drawn here; what it says is read from picture_row() instead: the
   one home of those words, and none of its own beside it. */
$row = named_blocks_of(APP_ROOT.'/app/ui.php')['picture_row'] ?? '';
/* Every sentence pictures_unavailable() can give - for an administrator and for
   anybody else, in German and in English - read from its own source. Two
   fragments listed by hand let a copy of the third, the administrator's
   „… steht, was fehlt", pass (code review). */
$unavailable = array_values(array_filter(array_map(
    fn($token) => is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING ? stripslashes(substr($token[1], 1, -1)) : '',
    token_get_all('<?php '.(named_blocks_of(APP_ROOT.'/app/uploads.php')['pictures_unavailable'] ?? ''))), fn(string $s) => mb_strlen($s) >= 8));
$said = [];
foreach ([$pictureAdmin, $pictureTrainer] as $reader) { sign_in_as($reader); array_push($said, ...pictures_unavailable()); }
sign_out();
is_same([], array_values(array_diff($said, $unavailable)),
        'what it says to an administrator and to anybody else is read from its source whole, so the next check has something to look for');
is_same([], array_values(array_filter($unavailable, fn(string $s) => str_contains($row, $s))),
        'picture_row() writes none of them out itself');
ok(str_contains($row, '=pictures_unavailable();'), 'it takes what it says, and who sets it up, from pictures_unavailable() alone');
