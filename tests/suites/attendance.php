<?php
/** Attendance: statuses, the suggested day, and the summary figures. */
$trainer = make_account(['role'=>'trainer']); sign_in_as($trainer);
$class = make_class(['weekday'=>1]);              // Mondays
$a = make_student(); $b = make_student(); $c = make_student();
foreach ([$a,$b,$c] as $s) fixture('class_students', ['class_id'=>$class,'student_id'=>$s,'joined_on'=>'2025-01-01']);

case_('The suggested day is the class day, not simply today');
$cls = fn(?int $weekday) => ['weekday'=>$weekday];
$monday = attendance_suggested_date($cls(1));
is_same('Monday', date('l', strtotime($monday)), 'a Monday class suggests a Monday');
ok($monday <= today(), 'and never a day in the future');
ok(strtotime(today()) - strtotime($monday) < 7*86400, 'within the last week');
is_same(today(), attendance_suggested_date($cls(null)), 'a class with no fixed day suggests today');

case_('Statuses come from settings and have a preselection');
$statuses = attendance_statuses();
ok($statuses !== [], 'there is at least one');
ok(array_key_exists(attendance_default_status(), $statuses), 'the default is one of them');
is_same(array_key_first($statuses), attendance_default_status(), 'the first configured entry is the default');
is_same('Anwesend', attendance_label('present'), 'a known code is labelled');
is_same('erfunden', attendance_label('erfunden'), 'an unknown code falls back to itself rather than blank');

case_('Recording, correcting and summarising');
$day = '2026-09-14';
foreach ([[$a,'present'],[$b,'absent'],[$c,'present']] as [$sid,$status])
    fixture('attendance', ['class_id'=>$class,'student_id'=>$sid,'session_on'=>$day,'status'=>$status,
                           'note'=>'','recorded_by'=>$trainer,'created_at'=>now()]);
$session = attendance_for_session($class, $day);
is_same(3, count($session), 'all three are recorded');
is_same('absent', $session[$b]['status'], 'the absence is stored');

// The unique key turns a second save for the same session into a correction.
run('INSERT INTO attendance (class_id,student_id,session_on,status,recorded_by,created_at) VALUES (?,?,?,?,?,?)'
   .' ON DUPLICATE KEY UPDATE status=VALUES(status),recorded_by=VALUES(recorded_by)',
    [$class,$b,$day,'excused',$trainer,now()]);
is_same(3, (int)scalar('SELECT COUNT(*) FROM attendance WHERE class_id=? AND session_on=?', [$class,$day]),
        'correcting does not add a second row');
is_same('excused', (string)scalar('SELECT status FROM attendance WHERE class_id=? AND student_id=? AND session_on=?', [$class,$b,$day]),
        'the correction took effect');

case_('The attendance rate counts turning up late as turning up');
is_same(null, attendance_rate([]), 'no sessions means no rate, not zero');
is_same(100.0, attendance_rate(['present'=>4]), 'all present');
is_same(0.0, attendance_rate(['absent'=>3]), 'none present');
is_same(75.0, attendance_rate(['present'=>2,'late'=>1,'absent'=>1]), 'late still counts as attended');
is_same(50.0, attendance_rate(['present'=>1,'excused'=>1]), 'excused counts as missed');

case_('Session dates are listed newest first');
foreach (['2026-09-07','2026-08-31'] as $d)
    fixture('attendance', ['class_id'=>$class,'student_id'=>$a,'session_on'=>$d,'status'=>'present',
                           'note'=>'','recorded_by'=>$trainer,'created_at'=>now()]);
is_same(['2026-09-14','2026-09-07','2026-08-31'], attendance_session_dates($class), 'newest first');
