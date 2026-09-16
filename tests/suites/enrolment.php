<?php
/** Courses, their timetable, and who asks to be in them. */
$trainer = make_account(['role'=>'trainer']); sign_in_as($trainer);
$course = make_class(['name'=>'Kindertraining','location'=>'Sporthalle Nord','days'=>[]]);

case_('A course can meet more than once a week, each day in its own place');
act('class_save', ['id'=>$course, 'name'=>'Kindertraining', 'location'=>'Sporthalle Nord',
    'day_weekday'=>['1','4',''], 'day_starts_at'=>['16:00','18:00',''], 'day_ends_at'=>['17:30','19:30',''],
    'day_location'=>['','Turnsaal Ost','']]);
$days = class_days($course);
is_same(2, count($days), 'both days were kept and the blank row dropped');
is_same(1, (int)$days[0]['weekday'], 'Monday first');
is_same(4, (int)$days[1]['weekday'], 'then Thursday');
$class = training_class($course);
ok(str_contains(class_day_label($days[0], $class), 'Sporthalle Nord'), 'a day with no place of its own uses the course’s');
ok(str_contains(class_day_label($days[1], $class), 'Turnsaal Ost'), 'and a day with one uses that');
ok(str_contains(class_schedule($class, $days), '|'), 'the whole pattern reads as one line');

case_('An impossible day is refused by name');
throws(fn() => act('class_save', ['id'=>$course, 'name'=>'Kindertraining',
    'day_weekday'=>['1'], 'day_starts_at'=>['18:00'], 'day_ends_at'=>['16:00'], 'day_location'=>['']]),
    'an end before the start', 'Montag');
is_same(2, count(class_days($course)), 'and nothing was saved');

case_('A double-tapped day is one day');
act('class_save', ['id'=>$course, 'name'=>'Kindertraining',
    'day_weekday'=>['1','1'], 'day_starts_at'=>['16:00','16:00'], 'day_ends_at'=>['17:30','17:30'], 'day_location'=>['','']]);
is_same(1, count(class_days($course)), 'the duplicate was dropped');

case_('The calendar is the pattern, until one day differs');
act('class_save', ['id'=>$course, 'name'=>'Kindertraining', 'location'=>'Sporthalle Nord',
    'day_weekday'=>['1'], 'day_starts_at'=>['16:00'], 'day_ends_at'=>['17:30'], 'day_location'=>['']]);
$from = '2026-09-01'; $to = '2026-09-30';
$calendar = class_calendar($from, $to, $course);
is_same(4, count($calendar), 'four Mondays in September 2026');
foreach ($calendar as $entry) is_same('Monday', date('l', strtotime($entry['date'])), 'every one is a Monday');
is_same('planned', $calendar[0]['status'], 'all going ahead');
is_same('Sporthalle Nord', $calendar[0]['location'], 'in the course’s usual place');

case_('One date can be cancelled without touching the pattern');
act('class_session_save', ['class_id'=>$course, 'session_on'=>'2026-09-14', 'status'=>'cancelled',
    'note'=>'Halle belegt', 'starts_at'=>'', 'ends_at'=>'', 'location'=>'']);
$calendar = class_calendar($from, $to, $course);
$cancelled = array_values(array_filter($calendar, fn($e) => $e['date'] === '2026-09-14'))[0];
is_same('cancelled', $cancelled['status'], 'that Monday is off');
is_same('Halle belegt', $cancelled['note'], 'with the reason attached');
is_same(4, count($calendar), 'the other Mondays are unaffected');
is_same(1, count(class_days($course)), 'and the weekly pattern is unchanged');

case_('Or moved somewhere else, at another time');
act('class_session_save', ['class_id'=>$course, 'session_on'=>'2026-09-21', 'status'=>'changed',
    'starts_at'=>'18:00', 'ends_at'=>'19:30', 'location'=>'Turnsaal Ost', 'note'=>'']);
$moved = array_values(array_filter(class_calendar($from, $to, $course), fn($e) => $e['date'] === '2026-09-21'))[0];
is_same('18:00:00', $moved['starts_at'], 'the new time');
is_same('Turnsaal Ost', $moved['location'], 'and the new place');
ok(str_contains(session_label($moved), '18:00–19:30'), 'which reads as one line');

case_('An extra session exists without pretending the course meets that day');
act('class_session_save', ['class_id'=>$course, 'session_on'=>'2026-09-26', 'status'=>'extra',
    'starts_at'=>'10:00', 'ends_at'=>'12:00', 'location'=>'', 'note'=>'Nachholtermin']);
$calendar = class_calendar($from, $to, $course);
is_same(5, count($calendar), 'the Saturday is in the calendar');
is_same(1, count(class_days($course)), 'but Saturday is not part of the weekly pattern');

case_('Putting a date back to normal removes the row rather than storing "as usual"');
act('class_session_save', ['class_id'=>$course, 'session_on'=>'2026-09-14', 'status'=>'planned',
    'starts_at'=>'', 'ends_at'=>'', 'location'=>'', 'note'=>'']);
is_same(0, (int)scalar('SELECT COUNT(*) FROM class_sessions WHERE class_id=? AND session_on=?', [$course, '2026-09-14']),
        'nothing is stored for a day that follows the pattern');
is_same('planned', class_session($course, '2026-09-14')['status'], 'and it is going ahead again');

// ---------------------------------------------------------------------------
case_('A course carries its own tariffs');
act('tariff_save', ['class_id'=>$course, 'name'=>'Monatsbeitrag', 'price'=>'45,00', 'period'=>'recurring',
    'interval_months'=>'1', 'due_day'=>'1', 'grace_days'=>'7', 'first_period'=>'prorate',
    'discount_months'=>'1', 'discount_kind'=>'percent', 'discount_percent'=>'100']);
act('tariff_save', ['class_id'=>$course, 'name'=>'Halbjahr', 'price'=>'240,00', 'period'=>'recurring',
    'interval_months'=>'6', 'due_day'=>'1', 'grace_days'=>'7', 'first_period'=>'prorate',
    'discount_months'=>'0', 'discount_kind'=>'percent', 'discount_percent'=>'0']);
is_same(2, count(class_tariffs($course)), 'both belong to this course');
throws(fn() => act('tariff_save', ['name'=>'Heimatlos', 'price'=>'10,00', 'period'=>'recurring',
    'interval_months'=>'1', 'due_day'=>'1', 'grace_days'=>'7', 'first_period'=>'prorate']),
    'a tariff with no course is refused', 'Kurs');
throws(fn() => act('tariff_save', ['class_id'=>$course, 'name'=>'Falsch', 'price'=>'10,00', 'period'=>'recurring',
    'interval_months'=>'1', 'due_day'=>'31', 'grace_days'=>'7', 'first_period'=>'prorate']),
    'a due day that not every month has', 'Zahltag');

case_('And says what it is in one sentence');
$tariffs = class_tariffs($course);
ok(str_contains(tariff_summary($tariffs[0]), 'monatlich') || str_contains(tariff_summary($tariffs[1]), 'monatlich'),
   'the monthly one says monthly');

// ---------------------------------------------------------------------------
case_('A student asks to join, and nothing happens until the trainer agrees');
$account = make_account(['role'=>'student']);
$student = make_student(['account_id'=>$account, 'joined_on'=>'2026-01-01']);
$monthly = (int)class_tariffs($course)[0]['id'];
sign_in_as($account);
act('enrolment_request', ['student_id'=>$student, 'class_id'=>$course, 'kind'=>'join',
                          'tariff_id'=>(string)$monthly, 'message'=>'Wir würden gern mitmachen.']);
is_same(1, pending_request_count(), 'the request is waiting');
is_same(null, enrolment($course, $student), 'and the child is not in the course yet');

case_('Asking twice for the same course is refused rather than doubled');
throws(fn() => act('enrolment_request', ['student_id'=>$student, 'class_id'=>$course, 'kind'=>'join', 'tariff_id'=>(string)$monthly]),
       'a second request', 'wartet schon');
is_same(1, pending_request_count(), 'still just the one');

case_('Approving it is what enrols them, on the tariff they asked for');
sign_in_as($trainer);
$pending = open_requests()[0];
act('enrolment_decide', ['id'=>$pending['id'], 'decision'=>'approve', 'note'=>'Gern!']);
$enrolled = enrolment($course, $student);
ok($enrolled !== null, 'the child is in the course');
is_same($monthly, (int)$enrolled['tariff_id'], 'on the tariff they asked for');
is_same(0, pending_request_count(), 'and nothing is waiting any more');
is_same('approved', student_requests($student)[0]['state'], 'the request records the decision');

case_('Declining changes nothing but says so');
sign_in_as($account);
act('enrolment_request', ['student_id'=>$student, 'class_id'=>$course, 'kind'=>'leave', 'message'=>'Umzug']);
sign_in_as($trainer);
act('enrolment_decide', ['id'=>open_requests()[0]['id'], 'decision'=>'decline', 'note'=>'Bitte erst im Juli.']);
is_same(null, enrolment($course, $student)['left_on'], 'the child is still in the course');
is_same('declined', student_requests($student)[0]['state'], 'and the answer is recorded');
is_same('Bitte erst im Juli.', student_requests($student)[0]['decision_note'], 'with the reason');

case_('A decision cannot be made twice');
sign_in_as($account);
act('enrolment_request', ['student_id'=>$student, 'class_id'=>$course, 'kind'=>'tariff',
                          'tariff_id'=>(string)class_tariffs($course)[1]['id']]);
sign_in_as($trainer);
$id = (int)open_requests()[0]['id'];
act('enrolment_decide', ['id'=>$id, 'decision'=>'approve', 'note'=>'']);
throws(fn() => act('enrolment_decide', ['id'=>$id, 'decision'=>'decline', 'note'=>'']),
       'the second decision is refused', 'schon entschieden');
is_same((int)class_tariffs($course)[1]['id'], (int)enrolment($course, $student)['tariff_id'], 'the tariff did change, once');

case_('The trainer does not have to ask herself');
$other = make_student(['first_name'=>'Direkt', 'joined_on'=>'2026-01-01']);
act('enrolment_request', ['student_id'=>$other, 'class_id'=>$course, 'kind'=>'join', 'tariff_id'=>(string)$monthly]);
ok(enrolment($course, $other) !== null, 'her own change takes effect at once');
is_same(0, pending_request_count(), 'with nothing left waiting');
is_same('approved', student_requests($other)[0]['state'], 'and it is written down as a decision she made');

case_('A student may only ask about their own child');
$stranger = make_student(['first_name'=>'Fremd']);
sign_in_as($account);
throws(fn() => act('enrolment_request', ['student_id'=>$stranger, 'class_id'=>$course, 'kind'=>'join']),
       'somebody else’s child is not theirs to enrol', 'nicht gefunden');
throws(fn() => act('enrolment_decide', ['id'=>'1', 'decision'=>'approve']),
       'and a family cannot decide their own request', 'Kein Zugriff');

case_('A full course cannot be joined');
sign_in_as($trainer);
run('UPDATE classes SET capacity=1 WHERE id=?', [$course]);
run('UPDATE class_students SET left_on=NULL WHERE class_id=?', [$course]);
$third = make_student(['first_name'=>'Zuspät']);
throws(fn() => act('enrolment_request', ['student_id'=>$third, 'class_id'=>$course, 'kind'=>'join']),
       'the course is full', 'voll');
run('UPDATE classes SET capacity=0 WHERE id=?', [$course]);

case_('A course only offers its own tariffs');
$otherCourse = make_class(['name'=>'Anderer Kurs']);
$foreign = make_tariff(['class_id'=>$otherCourse, 'name'=>'Fremd']);
sign_in_as($account);
throws(fn() => act('enrolment_request', ['student_id'=>$student, 'class_id'=>$course, 'kind'=>'tariff', 'tariff_id'=>(string)$foreign]),
       'a tariff from another course is refused', 'gehört nicht');
