<?php
/** Date and money handling: the timezone bug fixed in the 0.1.0 review. */

case_('DATETIME values are converted from UTC to local, not relabelled');
// 22:30 UTC on 15 Sep is 00:30 on 16 Sep in Vienna (CEST, UTC+2).
is_same('16.09.2026', fmt_date('2026-09-15 22:30:00'), 'summer, crossing midnight');
is_same('16.09.2026, 00:30', fmt_datetime('2026-09-15 22:30:00'), 'with the time of day');
// 23:30 UTC on 15 Jan is 00:30 on 16 Jan in Vienna (CET, UTC+1).
is_same('16.01.2026', fmt_date('2026-01-15 23:30:00'), 'winter, crossing midnight');
is_same('15.09.2026, 12:00', fmt_datetime('2026-09-15 10:00:00'), 'midday, no boundary crossed');

case_('DATE columns are calendar dates and must not shift');
foreach (['2026-09-15'=>'15.09.2026','2026-01-01'=>'01.01.2026','2026-12-31'=>'31.12.2026'] as $in=>$want)
    is_same($want, fmt_date($in), 'plain date '.$in);

case_('Unparseable values degrade instead of raising');
foreach (['not-a-date','','0000-00-00','2026-02-30','2026-13-01','2026-09-15 25:00:00'] as $bad)
    is_same('–', fmt_date($bad), 'rejects '.test_show($bad));
is_same('–', fmt_date(null), 'rejects null');

case_('Amounts parse and format');
is_same(4550, cents('45,50'), 'comma decimal separator');
is_same(4550, cents('45.50'), 'point decimal separator');
is_same(50, cents('0.5'), 'single decimal digit');
is_same(1200, cents(' 12 '), 'surrounding space');
foreach (['45.999','','45.','.5','1e3','-5','1 000'] as $bad)
    throws(fn() => cents($bad), 'rejects '.test_show($bad));
throws(fn() => cents('0', false), 'rejects zero when zero is not allowed');
is_same('45,50 €', money(4550), 'German formatting');

case_('A date typed into a form is a real day from 1900 to 2100, and the refusal names the years');
/* ADR 0026 §5: 9999-12-31 passed and then overflowed an invoice's due date in
   the database, where the year 10000 does not fit. */
is_same(['2026-02-28', null], [date_value('2026-02-28'), date_value('')], 'a real day, and nothing where nothing may be');
is_same(['1900-01-01', '2100-12-31'], [date_value('1900-01-01'), date_value('2100-12-31')], 'the first and the last day of the range are dates');
foreach (['9999-12-31', '1899-12-31', '2101-01-01'] as $far)
    throws(fn() => date_value($far), $far.' is refused', 'Bitte ein Datum zwischen 1900 und 2100 eingeben.');
throws(fn() => date_value('2026-02-30'), 'and a day that does not exist, as before', 'Bitte ein gültiges Datum eingeben.');
is_same('2026-10', billing_valid_period('2026-10'), 'a month to charge for is held to the same years');
throws(fn() => billing_valid_period('9999-12'), 'so 9999-12 is refused before its charges fall due in the year 10000', 'zwischen 1900 und 2100');

case_('A date in the address is a date or none, never a refusal');
$kept = $_GET;
foreach (['2026-03-01' => '2026-03-01', '0' => null, '9999-12-31' => null, '31.12.2026' => null, '' => null] as $in => $want) {
    $_GET = ['on' => (string)$in];
    is_same($want, query_date('on'), test_show((string)$in).' reads as '.test_show($want));
}
$_GET = ['on' => ['2026-03-01']];
is_same(null, query_date('on'), 'and a list as none');
$_GET = $kept;

case_('A number that is not an id is digits within its range, or one sentence');
is_same([0, 28, -1, 7], [whole_number_value('0', 0, 28), whole_number_value('28', 0, 28), whole_number_value('-1', -1, 120), whole_number_value(' 7 ', 0, 28)],
        'digits, a minus where the range allows one, and the spaces around them');
foreach (['1,5', '1.5', 'abc', '', '1e9', '29', '-1', '2147483648', '99999999999999999999', '+3', '0x1A'] as $bad)
    throws(fn() => whole_number_value($bad, 0, 28), test_show($bad).' is refused, never cast', 'Bitte eine ganze Zahl von 0 bis 28 eingeben.');
throws(fn() => whole_number_value('600', 0, 500, 'Plätze: 0 bis 500 (0 = unbegrenzt).'), 'in the field’s own sentence where it has one', 'Plätze: 0 bis 500');
is_same([0, -5, 99999], [list_position_value(''), list_position_value('-5'), list_position_value('99999')], 'a position in a list: empty is first, as 0 is');
throws(fn() => list_position_value('2147483648'), 'and one past any list is refused before the column refuses it', 'Reihenfolge');

case_('Counts read as German, not as a template');
is_same('1 Beitrag', plural(1, 'Beitrag', 'Beiträge', 'charge', 'charges'), 'singular');
is_same('2 Beiträge', plural(2, 'Beitrag', 'Beiträge', 'charge', 'charges'), 'plural');
is_same('0 Beiträge', plural(0, 'Beitrag', 'Beiträge', 'charge', 'charges'), 'zero takes the plural');

case_('now() is UTC and today() is local, deliberately');
ok(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', now()) === 1, 'now() shape');
ok(preg_match('/^\d{4}-\d{2}-\d{2}$/D', today()) === 1, 'today() shape');
is_same(gmdate('Y-m-d H:i'), substr(now(), 0, 16), 'now() is UTC');
is_same(date('Y-m-d'), today(), 'today() is local');
