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

case_('Counts read as German, not as a template');
is_same('1 Beitrag', plural(1, 'Beitrag', 'Beiträge', 'charge', 'charges'), 'singular');
is_same('2 Beiträge', plural(2, 'Beitrag', 'Beiträge', 'charge', 'charges'), 'plural');
is_same('0 Beiträge', plural(0, 'Beitrag', 'Beiträge', 'charge', 'charges'), 'zero takes the plural');

case_('now() is UTC and today() is local, deliberately');
ok(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', now()) === 1, 'now() shape');
ok(preg_match('/^\d{4}-\d{2}-\d{2}$/D', today()) === 1, 'today() shape');
is_same(gmdate('Y-m-d H:i'), substr(now(), 0, 16), 'now() is UTC');
is_same(date('Y-m-d'), today(), 'today() is local');
