<?php
/**
 * Query counts.
 *
 * Not micro-optimisation: these guard against the pattern where a page issues
 * one query per row, so it looks fine with five students and crawls with fifty.
 * The thresholds are deliberately loose — they catch growth, not milliseconds.
 */
$trainer = make_account(['role'=>'trainer']); sign_in_as($trainer);
$tariff = make_tariff();
$class = make_class(['tariff_id'=>$tariff]);
for ($i = 0; $i < 30; $i++) {
    $sid = make_student(['tariff_id'=>$tariff,'price_cents'=>4500,'joined_on'=>'2025-01-01']);
    fixture('class_students', ['class_id'=>$class,'student_id'=>$sid,'joined_on'=>'2025-01-01']);
}

case_('The monthly billing preview does not query per student');
$q = query_count(fn() => billing_plan('2026-10'));
ok($q < 30, 'planning 30 students takes fewer than 30 queries (took '.$q.')');

case_('Counting again with more students does not scale the query count');
for ($i = 0; $i < 30; $i++) make_student(['tariff_id'=>$tariff,'price_cents'=>4500,'joined_on'=>'2025-01-01']);
$q60 = query_count(fn() => billing_plan('2026-10'));
ok($q60 <= $q + 2, 'doubling the students adds at most two queries (30: '.$q.', 60: '.$q60.')');

case_('The student list does not query per row');
$q = query_count(fn() => filtered_students([]));
ok($q <= 2, 'listing students is one or two queries (took '.$q.')');

case_('current_user() is resolved once, not per call');
current_user(true);
$q = query_count(function () { for ($i = 0; $i < 20; $i++) { current_user(); is_staff(); } });
ok($q <= 1, 'twenty lookups cost at most one query (took '.$q.')');

case_('Settings are cached within a request');
setting_cache_clear();
$q = query_count(function () { for ($i = 0; $i < 20; $i++) setting('club_name'); });
ok($q <= 1, 'twenty reads of one setting cost at most one query (took '.$q.')');

case_('The batched balance agrees with the per-student one');
// A mix worth checking: fully paid, part paid, unpaid, cancelled, an unconfirmed
// payment that must not count, and a voided one that must not either.
$mix = make_student(['price_cents'=>4500]);
$paid   = fixture('charges', ['student_id'=>$mix,'label'=>'Bezahlt','amount_cents'=>4500,'due_on'=>'2026-01-10','cancelled'=>0,'origin'=>'manual','created_at'=>now()]);
$part   = fixture('charges', ['student_id'=>$mix,'label'=>'Teilweise','amount_cents'=>4500,'due_on'=>'2026-01-10','cancelled'=>0,'origin'=>'manual','created_at'=>now()]);
$unpaid = fixture('charges', ['student_id'=>$mix,'label'=>'Offen','amount_cents'=>3000,'due_on'=>'2099-01-10','cancelled'=>0,'origin'=>'manual','created_at'=>now()]);
$void   = fixture('charges', ['student_id'=>$mix,'label'=>'Storniert','amount_cents'=>9900,'due_on'=>'2026-01-10','cancelled'=>1,'origin'=>'manual','created_at'=>now()]);
fixture('payments', ['charge_id'=>$paid,'amount_cents'=>4500,'paid_on'=>'2026-01-05','method'=>'Bar','note'=>'','confirmed_at'=>now(),'voided'=>0]);
fixture('payments', ['charge_id'=>$part,'amount_cents'=>2000,'paid_on'=>'2026-01-05','method'=>'Bar','note'=>'','confirmed_at'=>now(),'voided'=>0]);
fixture('payments', ['charge_id'=>$part,'amount_cents'=>1000,'paid_on'=>'2026-01-06','method'=>'Bar','note'=>'','confirmed_at'=>null,'voided'=>0]);
fixture('payments', ['charge_id'=>$unpaid,'amount_cents'=>3000,'paid_on'=>'2026-01-06','method'=>'Bar','note'=>'','confirmed_at'=>now(),'voided'=>1]);

$batchOpen = balances(); $batchDue = balances(true);
is_same(balance($mix), $batchOpen[$mix] ?? 0, 'total outstanding matches');
is_same(balance($mix, true), $batchDue[$mix] ?? 0, 'overdue matches');
is_same(5500, $batchOpen[$mix] ?? 0, 'unconfirmed and voided payments do not reduce it, cancelled charges are excluded');
is_same(2500, $batchDue[$mix] ?? 0, 'only the past-due part counts as overdue');

case_('Every student the per-row version reports, the batch reports too');
foreach (rows('SELECT id FROM students') as $row) {
    $id = (int)$row['id'];
    is_same(balance($id), balances()[$id] ?? 0, 'student '.$id.' total');
    is_same(balance($id, true), balances(true)[$id] ?? 0, 'student '.$id.' overdue');
}

case_('Listing many students costs a fixed number of queries');
$q = query_count(function () { $due = balances(true); foreach (filtered_students([]) as $s) $x = $due[(int)$s['id']] ?? 0; });
ok($q <= 3, 'one list plus one balance query, whatever the row count (took '.$q.')');

case_('The dashboard costs the same whether it shows 6 students or 60');
/* Rendering the real view, not a copy of its logic, so the count cannot drift
   away from the page. Both roles matter: a parent sees the same cards. */
$q6 = query_count(fn() => render_view('dashboard'));
for ($i = 0; $i < 30; $i++) make_student(['tariff_id'=>$tariff,'price_cents'=>4500,'joined_on'=>'2025-01-01']);
$q60 = query_count(fn() => render_view('dashboard'));
ok($q60 <= $q6, 'trebling the students adds no queries ('.$q6.' then '.$q60.')');
ok($q60 < 20, 'the whole page is a flat handful of queries (took '.$q60.')');

case_('A parent dashboard is batched too, not one balance per child');
$parentAccount = make_account(['role'=>'student','name'=>'Elternteil']);
for ($i = 0; $i < 10; $i++) make_student(['account_id'=>$parentAccount,'tariff_id'=>$tariff,'price_cents'=>4500]);
sign_in_as($parentAccount);
$qp = query_count(fn() => render_view('dashboard'));
ok($qp < 20, 'ten children cost a flat handful of queries (took '.$qp.')');

case_('The student list page stays flat as the roll grows');
sign_in_as($trainer);
$ql = query_count(fn() => render_view('students'));
ok($ql < 20, 'listing every student is a flat handful of queries (took '.$ql.')');
$html = render_view('dashboard');
ok(str_contains($html, 'student-card'), 'the page really rendered its student cards');

case_('The student payments tab does not grow a query per charge');
/* Charges accumulate every month for as long as she uses this, so a per-charge
   query here gets slower on its own with nobody changing anything. */
sign_in_as($trainer);
$billed = make_student(['first_name'=>'Viele','last_name'=>'Beitraege','tariff_id'=>$tariff]);
$addCharge = function (int $n) use ($billed) {
    $c = fixture('charges', ['student_id'=>$billed,'label'=>'Beitrag '.$n,'amount_cents'=>4500,
        'due_on'=>sprintf('2026-%02d-10', ($n % 12) + 1),'cancelled'=>0,'origin'=>'manual','created_at'=>now()]);
    fixture('payments', ['charge_id'=>$c,'amount_cents'=>2000,'paid_on'=>sprintf('2026-%02d-11', ($n % 12) + 1),
        'method'=>'Bar','note'=>'','confirmed_at'=>now(),'voided'=>0]);
};
/* Both readings are taken the same way, which needs care here. The settings
   cache fills on the first render a process ever does — a one-time cost, not a
   per-charge one — so the page is rendered once and thrown away before
   measuring. The payment-profile memo is emptied before each reading, since it
   is request-scoped in production but would persist across both here. */
for ($n = 0; $n < 3; $n++) $addCharge($n);
render_view('student', ['id'=>$billed,'tab'=>'payments']);
payment_cache_clear();
$few = query_count(fn() => render_view('student', ['id'=>$billed,'tab'=>'payments']));
for ($n = 3; $n < 36; $n++) $addCharge($n);          // three years of membership
payment_cache_clear();
$many = query_count(fn() => render_view('student', ['id'=>$billed,'tab'=>'payments']));
is_same($few, $many, 'three charges and thirty-six cost the same ('.$few.')');
ok($many < 12, 'and that is a flat handful, not one per charge (took '.$many.')');
ok(str_contains(render_view('student', ['id'=>$billed,'tab'=>'payments']), 'Beitrag 35'), 'the last charge really is on the page');

case_('The student skills tab does not grow a query per skill or per assessment day');
$area  = fixture('skill_areas', ['name'=>'Technik','sort_order'=>1,'archived'=>0,'created_at'=>now()]);
$scale = fixture('rating_scales', ['name'=>'0-10','min_value'=>0,'max_value'=>10,'step'=>1,
    'labels_json'=>'{}','archived'=>0,'created_at'=>now()]);
$rated = make_student(['first_name'=>'Viele','last_name'=>'Faehigkeiten']);
$addSkill = function (int $n) use ($area, $scale, $rated, $trainer) {
    $skill = fixture('skills', ['area_id'=>$area,'scale_id'=>$scale,'name'=>'Skill '.$n,
        'sort_order'=>$n,'archived'=>0,'created_at'=>now()]);
    // A different day each time, so the history list grows as well.
    fixture('assessments', ['student_id'=>$rated,'skill_id'=>$skill,'value'=>7,'note'=>'',
        'assessed_on'=>sprintf('2026-%02d-%02d', ($n % 12) + 1, ($n % 28) + 1),
        'assessed_by'=>$trainer,'created_at'=>now()]);
};
for ($n = 0; $n < 2; $n++) $addSkill($n);
render_view('student', ['id'=>$rated,'tab'=>'skills']);      // warm-up, as above
payment_cache_clear();
$fewSkills = query_count(fn() => render_view('student', ['id'=>$rated,'tab'=>'skills']));
for ($n = 2; $n < 20; $n++) $addSkill($n);
payment_cache_clear();
$manySkills = query_count(fn() => render_view('student', ['id'=>$rated,'tab'=>'skills']));
/* Not exact equality: whether the area-score tiles render depends on how many
   assessments fall inside the configured window, and that costs a settings read.
   The property worth guarding is that ten times the rows does not mean ten times
   the queries — an N+1 here would be eighteen more, not one. */
ok($manySkills <= $fewSkills + 2, 'twenty skills cost no more than two queries above two skills ('.$fewSkills.' then '.$manySkills.')');
ok($manySkills < 12, 'and that is a flat handful (took '.$manySkills.')');
ok(str_contains(render_view('student', ['id'=>$rated,'tab'=>'skills']), 'Skill 19'), 'the last skill really is on the page');
