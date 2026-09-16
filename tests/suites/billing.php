<?php
/**
 * Monthly charges.
 *
 * The rule: a charge appears on the 1st, and the first time the calendar reaches
 * a 1st after a student joins, that month is free. Absence is irrelevant.
 */
$admin = make_account(['role'=>'admin']); sign_in_as($admin);

case_('The free month is derived from the join date');
$free = fn(string $joined) => billing_free_period(['joined_on'=>$joined,'created_at'=>$joined.' 00:00:00']);
is_same('2026-09', $free('2026-09-01'), 'joining on the 1st makes that month free');
is_same('2026-10', $free('2026-09-02'), 'joining on the 2nd makes the next month free');
is_same('2026-10', $free('2026-09-30'), 'joining on the last day makes the next month free');
is_same('2027-01', $free('2026-12-15'), 'across a year boundary');
is_same('2027-03', $free('2027-02-28'), 'from the end of February');
is_same('2026-01', $free('2026-01-01'), 'January the 1st');

case_('The first charged month follows the free one');
$firstBill = fn(string $j) => billing_first_charged_period(['joined_on'=>$j,'created_at'=>$j.' 00:00:00']);
is_same('2026-10', $firstBill('2026-09-01'), 'joined on the 1st');
is_same('2026-11', $firstBill('2026-09-15'), 'joined mid-month');
is_same('2027-02', $firstBill('2026-12-15'), 'across a year boundary');

case_('A period covers the whole calendar month');
is_same('2026-02-28', billing_period_end('2026-02'), 'February');
is_same('2028-02-29', billing_period_end('2028-02'), 'February in a leap year');
is_same('2026-11-30', billing_period_end('2026-11'), 'a 30-day month');
is_same('2026-12-31', billing_period_end('2026-12'), 'December');
foreach (['2026-13','2026-00','26-11','2026-11-01','','nonsense'] as $bad)
    throws(fn() => billing_valid_period($bad), 'rejects the period '.test_show($bad));

case_('Who gets charged, and why the others do not');
$old   = make_student(['first_name'=>'Alt','joined_on'=>'2025-01-01','price_cents'=>4500]);
$mid   = make_student(['first_name'=>'Mitte','joined_on'=>'2026-08-15','price_cents'=>4500]);
$first = make_student(['first_name'=>'Erster','joined_on'=>'2026-09-01','price_cents'=>4500]);
$pause = make_student(['first_name'=>'Pause','joined_on'=>'2025-01-01','price_cents'=>4500,'billing_paused'=>1]);
$free_ = make_student(['first_name'=>'Ohne','joined_on'=>'2025-01-01','price_cents'=>null]);
$ended = make_student(['first_name'=>'Ende','joined_on'=>'2025-01-01','price_cents'=>4500,'ended_on'=>'2026-08-31']);
$reason = function (string $period, int $id) {
    foreach (billing_plan($period) as $row) if ($row['student_id'] === $id) return $row['skip'];
    return 'missing from the plan';
};
is_same(null, $reason('2026-09', $old), 'a long-standing student is charged');
is_same('Erster Monat frei', $reason('2026-09', $mid), 'the free month for a mid-month joiner');
is_same(null, $reason('2026-10', $mid), 'and charged the month after');
is_same('Erster Monat frei', $reason('2026-09', $first), 'joined on the 1st: that month is free');
is_same(null, $reason('2026-10', $first), 'charged from October');
is_same('Beiträge pausiert', $reason('2026-09', $pause), 'paused billing is skipped');
is_same('Kein monatlicher Preis hinterlegt', $reason('2026-09', $free_), 'no price means no charge');
is_same('Mitgliedschaft beendet', $reason('2026-09', $ended), 'an ended membership is skipped');
is_same('Noch nicht dabei', $reason('2025-01', $mid), 'before joining');

case_('Being away does not change the bill');
fixture('absences', ['student_id'=>$old,'reason'=>'sick','starts_on'=>'2026-09-01','ends_on'=>'2026-09-30','created_by'=>$admin]);
is_same(null, $reason('2026-09', $old), 'a month-long absence still bills');

case_('Running the same month twice creates nothing the second time');
$one = billing_run('2026-10');
is_same(3, $one['created'], 'first run creates the three eligible students');
$two = billing_run('2026-10');
is_same(0, $two['created'], 'second run creates nothing');
$three = billing_run('2026-10');
is_same(0, $three['created'], 'third run too');
is_same(3, (int)scalar("SELECT COUNT(*) FROM charges WHERE origin='auto' AND period_from='2026-10-01'"), 'three charges exist in total');

case_('A generated charge carries the right numbers');
$c = one("SELECT * FROM charges WHERE billing_key=?", [billing_key('2026-10', $old)]);
ok($c !== null, 'the charge exists');
is_same(4500, (int)$c['amount_cents'], 'the amount is the agreed price');
is_same('2026-10-01', $c['period_from'], 'coverage starts on the 1st');
is_same('2026-10-31', $c['period_to'], 'and ends on the last day');
is_same('2026-10-15', $c['due_on'], 'due after the configured term');
is_same('auto', $c['origin'], 'marked as generated');

case_('The tariff supplies the price only when it recurs');
$monthly = make_tariff(['price_cents'=>3000,'period'=>'monthly']);
$once    = make_tariff(['price_cents'=>3000,'period'=>'once']);
is_same(3000, billing_amount(['price_cents'=>null,'tariff_id'=>$monthly]), 'a monthly tariff is used');
is_same(null, billing_amount(['price_cents'=>null,'tariff_id'=>$once]), 'a one-time tariff is not');
is_same(9900, billing_amount(['price_cents'=>9900,'tariff_id'=>$monthly]), 'an agreed price wins over the tariff');

case_('A failed run leaves no partial month behind');
$before = (int)scalar('SELECT COUNT(*) FROM charges');
throws(function () {
    transactional(function () { billing_run('2026-11'); throw new UserError('interrupted after generating'); });
}, 'the interruption propagates');
is_same($before, (int)scalar('SELECT COUNT(*) FROM charges'), 'November was rolled back entirely');
