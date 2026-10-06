<?php
/**
 * Query counts.
 *
 * Not micro-optimisation: these guard against the pattern where a page issues
 * one query per row, so it looks fine with five students and crawls with fifty.
 * The thresholds are deliberately loose — they catch growth, not milliseconds.
 */
case_('The count is checked before it is trusted');
/* query_count() reads the server's own counter, and a reading that came back 0
   for everything would pass every ceiling below. So it is held to statements
   counted by hand first, the zero included. */
is_same(0, query_count(fn() => null), 'nothing sent counts nothing');
is_same(1, query_count(fn() => scalar('SELECT 1')), 'one statement counts one');
is_same(3, query_count(function () { rows('SELECT 1'); one('SELECT 2'); run('SELECT 3'); }), 'three count three');
is_same(0, query_count(fn() => run_counter('SELECT 1')), 'and the rate-limit counter’s own connection is not in it');

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

case_('A family’s dashboard does not grow a query per charge');
/* It was "per child" while one login could hold ten of them. A login is one
   student now (ADR 0010), so what grows on a family's page is their own
   charges, month after month, with nobody changing anything. */
$familyLogin = make_account(['role'=>'student','name'=>'Viele Monate']);
$own = make_student(['account_id'=>$familyLogin,'first_name'=>'Viele','last_name'=>'Monate','tariff_id'=>$tariff,'price_cents'=>4500]);
$bill = fn(int $from, int $to) => array_map(fn(int $n) => fixture('charges', ['student_id'=>$own,'label'=>'Monat '.$n,
    'amount_cents'=>4500,'due_on'=>sprintf('2025-%02d-10', ($n % 12) + 1),'cancelled'=>0,'origin'=>'manual','created_at'=>now()]),
    range($from, $to));
$bill(0, 2);
sign_in_as($familyLogin);
render_view('dashboard');                       // the settings cache fills once, as in the case below
payment_cache_clear();
$qFew = query_count(fn() => render_view('dashboard'));
$bill(3, 35);
payment_cache_clear();
$qMany = query_count(fn() => render_view('dashboard'));
ok($qMany <= $qFew, 'three charges and thirty-six cost the same ('.$qFew.' then '.$qMany.')');
ok($qMany < 20, 'a flat handful of queries (took '.$qMany.')');
ok(str_contains(render_view('dashboard'), 'Viele'), 'and the page really is theirs');

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
/* Six when this was written; eleven by October 2026, and twelve once a charge on
   a live invoice said which invoice holds it - live_invoices_of_charges(), one
   query for every charge on the tab. Raised by that one, not by a margin, so
   the next query added here is noticed too. */
ok($many < 13, 'and that is a flat handful, not one per charge (took '.$many.')');
ok(str_contains(render_view('student', ['id'=>$billed,'tab'=>'payments']), 'Beitrag 35'), 'the last charge really is on the page');

