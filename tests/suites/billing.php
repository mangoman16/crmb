<?php
/**
 * Charges.
 *
 * The rule: a child is billed for the courses they are enrolled in, at the
 * tariff that enrolment names, for periods anchored to the calendar year. What
 * the trainer can change - how often, which day, what happens to somebody who
 * joins mid-period, and the welcome discount - all live on the tariff.
 *
 * This is the suite that decides what real families are asked to pay, so it
 * checks the arithmetic and not only that something happened.
 */
$admin = make_account(['role'=>'admin']); sign_in_as($admin);

case_('A period is anchored to the calendar year, not to who joined when');
$bounds = fn(string $date, int $months) => billing_period_bounds($date, $months);
is_same(['from'=>'2026-09-01','to'=>'2026-09-30'], $bounds('2026-09-17', 1), 'one month is the month');
is_same(['from'=>'2026-09-01','to'=>'2026-10-31'], $bounds('2026-09-17', 2), 'two months start in an odd month');
is_same(['from'=>'2026-07-01','to'=>'2026-09-30'], $bounds('2026-09-17', 3), 'the third quarter');
is_same(['from'=>'2026-07-01','to'=>'2026-12-31'], $bounds('2026-09-17', 6), 'the second half');
is_same(['from'=>'2026-01-01','to'=>'2026-12-31'], $bounds('2026-09-17', 12), 'the year');
is_same(['from'=>'2026-01-01','to'=>'2026-03-31'], $bounds('2026-01-01', 3), 'the first quarter starts in January');
is_same(['from'=>'2028-01-01','to'=>'2028-02-29'], $bounds('2028-02-29', 2), 'a leap day lands in its own period');
foreach ([0, 4, 5, 7, 13, -1] as $bad)
    throws(fn() => billing_period_bounds('2026-01-01', $bad), 'rejects an interval of '.$bad);

case_('A period covers the whole calendar month');
is_same('2026-02-28', billing_period_end('2026-02'), 'February');
is_same('2028-02-29', billing_period_end('2028-02'), 'February in a leap year');
is_same('2026-11-30', billing_period_end('2026-11'), 'a 30-day month');
foreach (['2026-13','2026-00','26-11','2026-11-01','','nonsense'] as $bad)
    throws(fn() => billing_valid_period($bad), 'rejects the period '.test_show($bad));

case_('The due day is capped where every month has one');
is_same('2026-02-01', billing_due_date('2026-02-01', 1), 'the first');
is_same('2026-02-15', billing_due_date('2026-02-01', 15), 'the fifteenth');
is_same('2026-02-28', billing_due_date('2026-02-01', 28), 'the twenty-eighth');
is_same('2026-02-28', billing_due_date('2026-02-01', 31), 'the thirty-first becomes the twenty-eighth rather than March');
is_same('2026-04-28', billing_due_date('2026-04-01', 30), 'and so does the thirtieth, so one date means one rule');
is_same('2026-02-01', billing_due_date('2026-02-01', 0), 'zero means the first');

case_('Overdue is the due date plus the grace the tariff gives');
is_same('2026-02-08', billing_overdue_date('2026-02-01', 7), 'a week later');
is_same('2026-02-01', billing_overdue_date('2026-02-01', 0), 'no grace at all');
is_same('2026-03-03', billing_overdue_date('2026-02-01', 30), 'across a month boundary');

// ---------------------------------------------------------------------------
case_('An ordinary month for an ordinary child');
$course = make_class(['name'=>'Kindertraining']);
$monthly = make_tariff(['class_id'=>$course,'name'=>'Monatsbeitrag','price_cents'=>4500,
                        'interval_months'=>1,'due_day'=>1,'grace_days'=>7,'first_period'=>'prorate']);
$child = make_student(['first_name'=>'Alt','joined_on'=>'2025-01-01','price_cents'=>null]);
make_enrolment($course, $child, ['tariff_id'=>$monthly,'joined_on'=>'2025-01-01']);

$line = function (string $period, int $studentId, ?int $classId = null) {
    foreach (billing_plan($period) as $row)
        if ($row['student_id'] === $studentId && ($classId === null || $row['class_id'] === $classId)) return $row;
    return ['skip' => 'missing from the plan'];
};
$row = $line('2026-09', $child);
is_same(null, $row['skip'], 'nothing in the way');
is_same(4500, $row['amount'], 'the tariff price');
is_same('2026-09-01', $row['from'], 'the period starts on the first');
is_same('2026-09-30', $row['to'], 'and ends on the last day');
is_same('2026-09-01', $row['due'], 'due on the day the tariff says');
is_same('2026-09-08', $row['overdue'], 'and late a week after that');

case_('An agreed price on the enrolment wins over the tariff');
run('UPDATE class_students SET price_cents=4000 WHERE class_id=? AND student_id=?', [$course, $child]);
is_same(4000, $line('2026-09', $child)['amount'], 'the family pays what was agreed');
run('UPDATE class_students SET price_cents=NULL WHERE class_id=? AND student_id=?', [$course, $child]);
is_same(4500, $line('2026-09', $child)['amount'], 'and clearing it goes back to the tariff');

case_('Joining part-way through a month is charged for the days they are there');
$mid = make_student(['first_name'=>'Mitte','joined_on'=>'2026-09-16']);
make_enrolment($course, $mid, ['tariff_id'=>$monthly,'joined_on'=>'2026-09-16']);
$row = $line('2026-09', $mid);
// 15 of September's 30 days, so exactly half.
is_same(2250, $row['amount'], 'half a month is half the price');
is_same(true, $row['prorated'], 'and the plan says it was prorated');
is_same(4500, $line('2026-10', $mid)['amount'], 'the next whole month is the full price');

case_('Or not charged at all, when the tariff says so');
run("UPDATE tariffs SET first_period='skip' WHERE id=?", [$monthly]);
is_same('Erst ab dem nächsten vollen Zeitraum', $line('2026-09', $mid)['skip'], 'the part month is skipped');
is_same(4500, $line('2026-10', $mid)['amount'], 'and billing starts with the next whole one');
run("UPDATE tariffs SET first_period='full' WHERE id=?", [$monthly]);
is_same(4500, $line('2026-09', $mid)['amount'], 'or charged in full, if that is what she chose');
run("UPDATE tariffs SET first_period='prorate' WHERE id=?", [$monthly]);

case_('A discount this family was given, in the months she described it in');
/* It is on the enrolment, not on the tariff: giving one child a free first
   month must not mean inventing a tariff nobody else can be put on. */
$new = make_student(['first_name'=>'Neu','joined_on'=>'2026-09-01']);
make_enrolment($course, $new, ['tariff_id'=>$monthly,'joined_on'=>'2026-09-01']);
give_discount($course, $new, 1, 'percent', 100, 'Erster Monat gratis');
$row = $line('2026-09', $new);
is_same(4500, $row['gross'], 'the full price is still recorded');
is_same(4500, $row['discount'], 'and all of it comes off');
is_same(0, $row['amount'], 'so the first month is free');
ok(str_contains($row['note'], 'Erster Monat gratis'), 'and it says why, in the words she used');
is_same(4500, $line('2026-10', $new)['amount'], 'the second month is not');
// The child beside them on the same tariff is untouched, which is the whole
// point of the discount having moved off the price list.
is_same(4500, $line('2026-09', $child)['amount'], 'and nobody else on that tariff is affected');

case_('A percentage over several months');
give_discount($course, $new, 6, 'percent', 30);
is_same(3150, $line('2026-09', $new)['amount'], '30 per cent off');
is_same(3150, $line('2027-02', $new)['amount'], 'still, six months later');
is_same(4500, $line('2027-03', $new)['amount'], 'and full price the month after that');

case_('A fixed amount off, and a discount that never ends');
give_discount($course, $new, 2, 'fixed', 1000);
is_same(3500, $line('2026-09', $new)['amount'], 'ten euro off');
is_same(4500, $line('2026-11', $new)['amount'], 'for two months only');
give_discount($course, $new, -1, 'percent', 50);
is_same(2250, $line('2030-05', $new)['amount'], 'a standing discount still applies years later');
ok(str_contains($line('2030-05', $new)['note'], 'Dauerhafter Nachlass'), 'and calls itself what it is');
give_discount($course, $new, 0, 'percent', 0);

// ---------------------------------------------------------------------------
case_('A longer billing period is charged once, in its first month');
$quarterly = make_tariff(['class_id'=>$course,'name'=>'Quartal','price_cents'=>12000,
                          'interval_months'=>3,'due_day'=>1,'grace_days'=>7,'first_period'=>'full']);
$q = make_student(['first_name'=>'Quartal','joined_on'=>'2025-01-01']);
make_enrolment($course, $q, ['tariff_id'=>$quarterly,'joined_on'=>'2025-01-01']);
is_same(12000, $line('2026-07', $q)['amount'], 'July starts the third quarter and is charged');
is_same('2026-09-30', $line('2026-07', $q)['to'], 'and the charge covers all three months');
is_same('Zeitraum beginnt in einem anderen Monat', $line('2026-08', $q)['skip'], 'August is inside it, so nothing happens');
is_same('Zeitraum beginnt in einem anderen Monat', $line('2026-09', $q)['skip'], 'nor September');
is_same(12000, $line('2026-10', $q)['amount'], 'October starts the next one');

case_('And a child joining inside one is billed in the month they join');
$late = make_student(['first_name'=>'Spät','joined_on'=>'2026-08-01']);
make_enrolment($course, $late, ['tariff_id'=>$quarterly,'joined_on'=>'2026-08-01']);
is_same(null, $line('2026-08', $late)['skip'], 'their first charge does not wait for October');
is_same('2026-07-01', $line('2026-08', $late)['from'], 'it is the quarter they joined during');

case_('A discount spanning fewer months than the period only discounts its share');
run("UPDATE tariffs SET first_period='prorate' WHERE id=?", [$quarterly]);
$qNew = make_student(['first_name'=>'Quartalsneu','joined_on'=>'2026-07-01']);
make_enrolment($course, $qNew, ['tariff_id'=>$quarterly,'joined_on'=>'2026-07-01']);
give_discount($course, $qNew, 1, 'percent', 100);
$row = $line('2026-07', $qNew);
is_same(12000, $row['gross'], 'the quarter costs what it costs');
is_same(4000, $row['discount'], 'one free month is a third of it');
is_same(8000, $row['amount'], 'so two of the three months are paid for');
give_discount($course, $qNew, 0, 'percent', 0);

// ---------------------------------------------------------------------------
case_('Why the others are not charged, said out loud rather than left out');
$paused  = make_student(['first_name'=>'Pause','joined_on'=>'2025-01-01','billing_paused'=>1]);
$noTar   = make_student(['first_name'=>'Ohne','joined_on'=>'2025-01-01']);
$gone    = make_student(['first_name'=>'Weg','joined_on'=>'2025-01-01']);
$notYet  = make_student(['first_name'=>'Bald','joined_on'=>'2027-01-01']);
$noDate  = make_student(['first_name'=>'Undatiert','joined_on'=>null,'created_at'=>now()]);
make_enrolment($course, $paused, ['tariff_id'=>$monthly,'joined_on'=>'2025-01-01']);
make_enrolment($course, $noTar,  ['tariff_id'=>null,'joined_on'=>'2025-01-01']);
make_enrolment($course, $gone,   ['tariff_id'=>$monthly,'joined_on'=>'2025-01-01','left_on'=>'2026-06-30']);
make_enrolment($course, $notYet, ['tariff_id'=>$monthly,'joined_on'=>'2027-01-01']);
make_enrolment($course, $noDate, ['tariff_id'=>$monthly,'joined_on'=>null]);
is_same('Beiträge pausiert', $line('2026-09', $paused)['skip'], 'billing paused');
is_same('Kein Tarif gewählt', $line('2026-09', $noTar)['skip'], 'no tariff on the enrolment');
is_same('Nicht mehr dabei', $line('2026-09', $gone)['skip'], 'left the course');
is_same('Noch nicht dabei', $line('2026-09', $notYet)['skip'], 'has not started');
is_same('Kein Beitrittsdatum', $line('2026-09', $noDate)['skip'], 'nothing to bill from');
// A child in no course at all does not appear in the plan, because the plan is
// a list of enrolments; the interface says so on their own page.
$orphan = make_student(['first_name'=>'Kurslos','joined_on'=>'2025-01-01']);
is_same('missing from the plan', $line('2026-09', $orphan)['skip'], 'a child in no course has nothing to bill');

case_('Being away does not change the bill');
fixture('absences', ['student_id'=>$child,'reason'=>'sick','starts_on'=>'2026-09-01','ends_on'=>'2026-09-30','created_by'=>$admin]);
is_same(null, $line('2026-09', $child)['skip'], 'a month-long absence still bills');

case_('A child in two courses is charged for both');
$second = make_class(['name'=>'Zusatztraining']);
$secondTariff = make_tariff(['class_id'=>$second,'price_cents'=>2000,'interval_months'=>1,'due_day'=>1]);
make_enrolment($second, $child, ['tariff_id'=>$secondTariff,'joined_on'=>'2025-01-01']);
is_same(4500, $line('2026-09', $child, $course)['amount'], 'the first course');
is_same(2000, $line('2026-09', $child, $second)['amount'], 'and the second, separately');

case_('The day the family pays on can be overridden per enrolment and per child');
run('UPDATE class_students SET due_day=15 WHERE class_id=? AND student_id=?', [$second, $child]);
is_same('2026-09-15', $line('2026-09', $child, $second)['due'], 'the enrolment overrides the tariff');
is_same('2026-09-01', $line('2026-09', $child, $course)['due'], 'and only that enrolment');
run('UPDATE students SET billing_due_day=20 WHERE id=?', [$child]);
is_same('2026-09-20', $line('2026-09', $child, $course)['due'], 'a day on the child overrides every tariff they are on');
run('UPDATE students SET billing_due_day=0 WHERE id=?', [$child]);
run('UPDATE class_students SET due_day=0 WHERE class_id=? AND student_id=?', [$second, $child]);

// ---------------------------------------------------------------------------
case_('Running the same month twice creates nothing the second time');
$first = billing_run('2026-10');
ok($first['created'] > 0, 'the first run creates charges');
is_same(0, billing_run('2026-10')['created'], 'the second creates nothing');
is_same(0, billing_run('2026-10')['created'], 'nor the third');

case_('A generated charge carries the right numbers');
$c = one('SELECT * FROM charges WHERE student_id=? AND class_id=? AND period_from=?', [$child, $course, '2026-10-01']);
ok($c !== null, 'the charge exists');
is_same(4500, (int)$c['amount_cents'], 'the amount');
is_same(4500, (int)$c['gross_cents'], 'the price before any discount');
is_same(0, (int)$c['discount_cents'], 'no discount on this one');
is_same('2026-10-01', $c['period_from'], 'coverage starts on the first');
is_same('2026-10-31', $c['period_to'], 'and ends on the last day');
is_same('2026-10-01', $c['due_on'], 'due on the tariff day');
is_same('2026-10-08', $c['overdue_on'], 'and late a week after');
is_same('auto', $c['origin'], 'marked as generated');
is_same((int)$monthly, (int)$c['tariff_id'], 'and it says which tariff produced it');

case_('Overdue means past the grace, not past the due date');
run('UPDATE charges SET due_on=?, overdue_on=? WHERE id=?', [date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+4 days')), $c['id']]);
is_same(0, balance((int)$child, true), 'a charge inside its grace is not overdue yet');
run('UPDATE charges SET overdue_on=? WHERE id=?', [date('Y-m-d', strtotime('-1 day')), $c['id']]);
is_same(4500, balance((int)$child, true), 'and is, once the grace has run out');

case_('A charge from before grace days existed behaves as it did');
run('UPDATE charges SET overdue_on=NULL, due_on=? WHERE id=?', [date('Y-m-d', strtotime('-1 day')), $c['id']]);
is_same(4500, balance((int)$child, true), 'its due date is the answer');

case_('A failed run leaves no partial month behind');
$before = (int)scalar('SELECT COUNT(*) FROM charges');
throws(function () {
    transactional(function () { billing_run('2026-11'); throw new UserError('interrupted after generating'); });
}, 'the interruption propagates');
is_same($before, (int)scalar('SELECT COUNT(*) FROM charges'), 'November was rolled back entirely');

case_('A tariff describes itself the way she would say it');
/* Every way it may be paid, the usual one first: "37,00 € monatlich" is the
   answer to "what does it cost?", and the rest answer "and for the year?". */
$summary = tariff_summary(['id'=>0,'period'=>'recurring','interval_months'=>1,'due_day'=>1],
                          [1=>3700, 3=>9900, 6=>16200, 12=>25200]);
ok(str_contains($summary, '37,00'), 'the price');
ok(str_contains($summary, 'monatlich'), 'how often');
ok(str_contains($summary, '252,00'), 'and what the year costs');
ok(str_starts_with($summary, '37,00'), 'the usual interval is the one it leads with');
ok(strpos($summary, '99,00') < strpos($summary, '162,00'), 'and the rest run cheapest period first');
ok(str_contains(tariff_summary(['id'=>0,'period'=>'once','interval_months'=>1,'due_day'=>1], [1=>2000]), 'einmalig'),
   'a one-off tariff says so');
is_same(t('Noch kein Preis hinterlegt','No price set yet'),
        tariff_summary(['id'=>0,'period'=>'recurring','interval_months'=>1,'due_day'=>1], []),
        'and a tariff with no price at all says that, rather than 0,00 €');

case_('A discount describes itself too, wherever it is written down');
is_same(t('Kein Rabatt','No discount'), discount_summary(0, 'percent', 0), 'nothing is nothing');
ok(str_contains(discount_summary(1, 'percent', 100), 'gratis'), '100 per cent is free');
ok(str_contains(discount_summary(3, 'percent', 50), '3 Monate'), 'three months says three months');
ok(str_contains(discount_summary(-1, 'percent', 20), 'dauerhaft'), 'and minus one month is for as long as they stay');
ok(str_contains(discount_summary(2, 'fixed', 1000), '10,00'), 'a fixed amount is money');

// ---------------------------------------------------------------------------
// Two things this got wrong until they were looked for.
// ---------------------------------------------------------------------------

case_('A charge is never created already overdue');
$quarterCourse = make_class(['name'=>'Quartalskurs']);
$quarterTariff = make_tariff(['class_id'=>$quarterCourse, 'price_cents'=>9000, 'interval_months'=>3,
                              'due_day'=>1, 'grace_days'=>7]);
$mayJoiner = make_student(['first_name'=>'Mai', 'joined_on'=>'2026-05-10']);
make_enrolment($quarterCourse, $mayJoiner, ['joined_on'=>'2026-05-10', 'tariff_id'=>$quarterTariff]);
$row = null;
foreach (billing_plan('2026-05') as $r) if ((int)$r['student_id'] === $mayJoiner) $row = $r;
is_same(null, $row['skip'], 'the child who joined in the second month of the quarter is billed');
is_same('2026-04-01', $row['from'], 'for the quarter the join falls into');
is_same('2026-05-01', $row['due'], 'but the money is due in the month the charge is written, not before it existed');
ok($row['overdue'] >= '2026-05-01', 'so it cannot be overdue on the day it is created');

case_('Joining in the first month of a period still uses the period’s own day');
$aprilJoiner = make_student(['first_name'=>'April', 'joined_on'=>'2026-04-20']);
make_enrolment($quarterCourse, $aprilJoiner, ['joined_on'=>'2026-04-20', 'tariff_id'=>$quarterTariff]);
foreach (billing_plan('2026-04') as $r) if ((int)$r['student_id'] === $aprilJoiner)
    is_same('2026-04-01', $r['due'], 'the normal case is unchanged');

case_('Leaving part-way through is charged by the days, whatever the joining rule says');
// The rule on a tariff answers "what happens to somebody who joins mid-period".
// It used to decide the leaving case as well, silently: a child who left on the
// 15th cost nothing under 'skip' and a whole period under 'full'.
foreach (['prorate' => 1500, 'full' => 1500, 'skip' => 1500] as $rule => $expected) {
    $course = make_class(['name'=>'Kurs '.$rule]);
    $tariff = make_tariff(['class_id'=>$course, 'price_cents'=>3000, 'interval_months'=>1, 'first_period'=>$rule]);
    $leaver = make_student(['first_name'=>'Geht'.$rule, 'joined_on'=>'2025-01-01']);
    make_enrolment($course, $leaver, ['joined_on'=>'2025-01-01', 'left_on'=>'2026-06-15', 'tariff_id'=>$tariff]);
    foreach (billing_plan('2026-06') as $r) if ((int)$r['student_id'] === $leaver) {
        is_same(null, $r['skip'], $rule.': the half month they were there is still billed');
        is_same($expected, $r['amount'], $rule.': fifteen of thirty days of 30,00 €');
        is_same(true, $r['prorated'], $rule.': and it says it was prorated');
    }
}

case_('The joining rule itself is unchanged');
foreach (['prorate' => 1100, 'full' => 3000, 'skip' => null] as $rule => $expected) {
    $course = make_class(['name'=>'Beitritt '.$rule]);
    $tariff = make_tariff(['class_id'=>$course, 'price_cents'=>3000, 'interval_months'=>1, 'first_period'=>$rule]);
    $joiner = make_student(['first_name'=>'Kommt'.$rule, 'joined_on'=>'2026-06-20']);
    make_enrolment($course, $joiner, ['joined_on'=>'2026-06-20', 'tariff_id'=>$tariff]);
    foreach (billing_plan('2026-06') as $r) if ((int)$r['student_id'] === $joiner)
        is_same($expected, $r['amount'], $rule.': joining on the 20th');
}

// ---------------------------------------------------------------------------
case_('One tariff, several ways to pay it, and the enrolment says which');
/* "252 € im Jahr, 162 € im Halbjahr, 99 € im Quartal, 37 € im Monat" was four
   tariffs with the same name and four places to change the price when it rises.
   It is one tariff with four rates now. */
$flex = make_tariff(['class_id'=>$course, 'name'=>'Beitrag', 'interval_months'=>1, 'due_day'=>1,
                     'grace_days'=>7, 'first_period'=>'full',
                     'rates'=>[1=>3700, 3=>9900, 6=>16200, 12=>25200]]);
$monthly2 = make_student(['first_name'=>'Monatlich','joined_on'=>'2025-01-01']);
$yearly  = make_student(['first_name'=>'Jährlich','joined_on'=>'2025-01-01']);
make_enrolment($course, $monthly2, ['tariff_id'=>$flex,'joined_on'=>'2025-01-01']);
make_enrolment($course, $yearly, ['tariff_id'=>$flex,'joined_on'=>'2025-01-01']);
bill_every($course, $yearly, 12);

is_same(3700, $line('2026-09', $monthly2)['amount'], 'saying nothing means the tariff’s usual interval');
is_same(1, $line('2026-09', $monthly2)['interval'], 'which is a month');
is_same(25200, $line('2026-01', $yearly)['amount'], 'and the one paying yearly pays the yearly price');
is_same(12, $line('2026-01', $yearly)['interval'], 'over twelve months');
is_same('2026-12-31', $line('2026-01', $yearly)['to'], 'covering the whole year');
is_same('Zeitraum beginnt in einem anderen Monat', $line('2026-09', $yearly)['skip'],
        'and September does not start a year, so nothing happens then');

case_('An interval taken off the price list falls back rather than billing nothing');
// Tidying up a price list must never quietly stop a child being billed: that
// shows up as a charge nobody notices is missing, months later.
bill_every($course, $yearly, 6);
is_same(16200, $line('2026-07', $yearly)['amount'], 'half-yearly while the half-yearly price is there');
run('DELETE FROM tariff_rates WHERE tariff_id=? AND interval_months=6', [$flex]);
$fallen = $line('2026-09', $yearly);
is_same(3700, $fallen['amount'], 'and the tariff’s usual price once it is gone');
is_same(1, $fallen['interval'], 'on the tariff’s usual interval');
ok($fallen['enrolment']['interval_missing'], 'with the row saying it had to fall back');

case_('The price a family pays says which of the two numbers it is');
$e = enrolment($course, $monthly2);
ok(str_contains(enrolment_summary($e), '37,00'), 'the price');
ok(str_contains(enrolment_summary($e), 'monatlich'), 'and how often');
give_discount($course, $monthly2, -1, 'percent', 20, 'Geschwisterrabatt');
$e = enrolment($course, $monthly2);
ok(str_contains(enrolment_summary($e), 'Geschwisterrabatt'), 'the discount by the name she gave it');
ok(str_contains(enrolment_summary($e), 'dauerhaft'), 'and for how long');
is_same(2960, $line('2026-09', $monthly2)['amount'], 'and it comes off what they are actually charged');
give_discount($course, $monthly2, 0, 'percent', 0);
