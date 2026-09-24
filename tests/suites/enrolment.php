<?php
/** Courses, their timetable, and who asks to be in them. */
$trainer = make_account(['role'=>'trainer']); sign_in_as($trainer);
$course = make_class(['name'=>'Kindertraining','location'=>'Sporthalle Nord','days'=>[]]);

case_('A course can meet more than once a week, each day in its own place');
act('class_save', ['id'=>$course, 'name'=>'Kindertraining', 'location'=>'Sporthalle Nord',
    'day_weekday'=>['1','4',''],] + time_post('day_starts_at', ['16:00','18:00','']) + time_post('day_ends_at', ['17:30','19:30','']) + [
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
$patternBefore = class_days($course);
throws(fn() => act('class_save', ['id'=>$course, 'name'=>'Kindertraining',
    'day_weekday'=>['1'], 'day_location'=>['']] + time_post('day_starts_at', ['18:00']) + time_post('day_ends_at', ['16:00'])),
    'an end before the start', 'Montag');
is_same(2, count(class_days($course)), 'and nothing was saved');
/* Counting them is not enough. class_save replaces the whole pattern rather
   than reconciling it, so a refusal arriving after the delete-and-reinsert had
   started would leave two rows that are not these two - same count, different
   days, different ids. Compared row for row so that cannot pass.
   That the refusal comes before the write at all is asserted in the structure
   suite: inside a transaction this assertion holds either way. */
is_same($patternBefore, class_days($course), 'and it is the pattern that was there, row for row');

case_('A double-tapped day is one day');
act('class_save', ['id'=>$course, 'name'=>'Kindertraining',
    'day_weekday'=>['1','1'], 'day_location'=>['','']] + time_post('day_starts_at', ['16:00','16:00']) + time_post('day_ends_at', ['17:30','17:30']));
is_same(1, count(class_days($course)), 'the duplicate was dropped');

case_('The calendar is the pattern, until one day differs');
act('class_save', ['id'=>$course, 'name'=>'Kindertraining', 'location'=>'Sporthalle Nord',
    'day_weekday'=>['1'], 'day_location'=>['']] + time_post('day_starts_at', ['16:00']) + time_post('day_ends_at', ['17:30']));
$from = '2026-09-01'; $to = '2026-09-30';
$calendar = class_calendar($from, $to, $course);
is_same(4, count($calendar), 'four Mondays in September 2026');
foreach ($calendar as $entry) is_same('Monday', date('l', strtotime($entry['date'])), 'every one is a Monday');
is_same('planned', $calendar[0]['status'], 'all going ahead');
is_same('Sporthalle Nord', $calendar[0]['location'], 'in the course’s usual place');

case_('One date can be cancelled without touching the pattern');
act('class_session_save', ['class_id'=>$course, 'session_on'=>'2026-09-14', 'status'=>'cancelled',
    'note'=>'Halle belegt', 'location'=>''] + time_post('starts_at', '') + time_post('ends_at', ''));
$calendar = class_calendar($from, $to, $course);
$cancelled = array_values(array_filter($calendar, fn($e) => $e['date'] === '2026-09-14'))[0];
is_same('cancelled', $cancelled['status'], 'that Monday is off');
is_same('Halle belegt', $cancelled['note'], 'with the reason attached');
is_same(4, count($calendar), 'the other Mondays are unaffected');
is_same(1, count(class_days($course)), 'and the weekly pattern is unchanged');

case_('Or moved somewhere else, at another time');
act('class_session_save', ['class_id'=>$course, 'session_on'=>'2026-09-21', 'status'=>'changed',
    'location'=>'Turnsaal Ost', 'note'=>''] + time_post('starts_at', '18:00') + time_post('ends_at', '19:30'));
$moved = array_values(array_filter(class_calendar($from, $to, $course), fn($e) => $e['date'] === '2026-09-21'))[0];
is_same('18:00:00', $moved['starts_at'], 'the new time');
is_same('Turnsaal Ost', $moved['location'], 'and the new place');
ok(str_contains(session_label($moved), '18:00–19:30'), 'which reads as one line');

case_('An extra session exists without pretending the course meets that day');
act('class_session_save', ['class_id'=>$course, 'session_on'=>'2026-09-26', 'status'=>'extra',
    'location'=>'', 'note'=>'Nachholtermin'] + time_post('starts_at', '10:00') + time_post('ends_at', '12:00'));
$calendar = class_calendar($from, $to, $course);
is_same(5, count($calendar), 'the Saturday is in the calendar');
is_same(1, count(class_days($course)), 'but Saturday is not part of the weekly pattern');

case_('Putting a date back to normal removes the row rather than storing "as usual"');
act('class_session_save', ['class_id'=>$course, 'session_on'=>'2026-09-14', 'status'=>'planned',
    'location'=>'', 'note'=>''] + time_post('starts_at', '') + time_post('ends_at', ''));
is_same(0, (int)scalar('SELECT COUNT(*) FROM class_sessions WHERE class_id=? AND session_on=?', [$course, '2026-09-14']),
        'nothing is stored for a day that follows the pattern');
is_same('planned', class_session($course, '2026-09-14')['status'], 'and it is going ahead again');

// ---------------------------------------------------------------------------
case_('A course carries its own tariffs, and one tariff carries its own prices');
$rate = fn(array $prices) => ['rate_interval'=>array_map('strval', array_keys($prices)),
                              'rate_price'=>array_values($prices)];
$basics = ['class_id'=>$course, 'period'=>'recurring', 'due_day'=>'1', 'grace_days'=>'7', 'first_period'=>'prorate'];
act('tariff_save', $basics + ['name'=>'Beitrag', 'interval_months'=>'1']
    + $rate([1=>'37,00', 3=>'99,00', 6=>'162,00', 12=>'252,00'])
    + ['discount_name'=>['Erster Monat gratis',''], 'discount_months'=>['1','0'],
       'discount_kind'=>['percent','percent'], 'discount_value'=>['100','']]);
act('tariff_save', $basics + ['name'=>'Halbjahr', 'interval_months'=>'6'] + $rate([6=>'240,00']));
is_same(2, count(class_tariffs($course)), 'both belong to this course');
$beitrag = one("SELECT id FROM tariffs WHERE name='Beitrag'");
is_same([1=>3700, 3=>9900, 6=>16200, 12=>25200], tariff_rates((int)$beitrag['id']),
        'four ways to pay one tariff, cheapest period first');
is_same(1, count(tariff_discount_templates((int)$beitrag['id'])), 'and one discount ready to give');
is_same('Erster Monat gratis', tariff_discount_templates((int)$beitrag['id'])[0]['name'], 'by the name she gave it');

throws(fn() => act('tariff_save', ['name'=>'Heimatlos', 'period'=>'recurring',
    'interval_months'=>'1', 'due_day'=>'1', 'grace_days'=>'7', 'first_period'=>'prorate'] + $rate([1=>'10,00'])),
    'a tariff with no course is refused', 'Kurs');
// The override goes on the left: array + array keeps the left-hand keys, so
// putting due_day into $basics' place would have left it at 1 and the check
// would have passed by never being made.
throws(fn() => act('tariff_save', ['name'=>'Falsch', 'interval_months'=>'1', 'due_day'=>'31'] + $basics + $rate([1=>'10,00'])),
    'a due day that not every month has', 'Zahltag');
throws(fn() => act('tariff_save', $basics + ['name'=>'Preislos', 'interval_months'=>'1'] + $rate([])),
    'a tariff with no price at all is refused', 'mindestens einen Preis');
// The usual interval decides what an enrolment that says nothing is billed at,
// so a tariff whose usual interval has no price is a tariff that cannot bill.
throws(fn() => act('tariff_save', $basics + ['name'=>'Lücke', 'interval_months'=>'1'] + $rate([6=>'100,00'])),
    'and so is one whose usual interval has no price', 'üblichen Zeitraum');

case_('And says what it is in one sentence');
$tariffs = class_tariffs($course);
$sentences = array_map('tariff_summary', $tariffs);
ok(str_contains(implode(' ', $sentences), 'monatlich'), 'the monthly one says monthly');
ok(str_contains(implode(' ', $sentences), '252,00'), 'and names the yearly price as well');

case_('A tariff can be copied, prices and discounts and all');
$copy = duplicate_record('tariffs', (int)$beitrag['id']);
is_same(tariff_rates((int)$beitrag['id']), tariff_rates($copy), 'the copy has the same four prices');
is_same(1, count(tariff_discount_templates($copy)), 'and the same discount template');
ok(str_contains((string)scalar('SELECT name FROM tariffs WHERE id=?', [$copy]), 'Kopie'),
   'under a name that says it is a copy');
$second = duplicate_record('tariffs', (int)$beitrag['id']);
ok(scalar('SELECT name FROM tariffs WHERE id=?', [$second]) !== scalar('SELECT name FROM tariffs WHERE id=?', [$copy]),
   'and a second copy is not called the same thing as the first');

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
$firstRequest = (int)scalar("SELECT id FROM enrolment_requests WHERE student_id=? AND class_id=? AND state='pending'",
                            [$student, $course]);
throws(fn() => act('enrolment_request', ['student_id'=>$student, 'class_id'=>$course, 'kind'=>'join', 'tariff_id'=>(string)$monthly]),
       'a second request', 'wartet schon');
is_same(1, pending_request_count(), 'still just the one');
// Named rather than counted: the count stays at one whether the row waiting is
// the one she sent or a second one written over the top of it.
is_same($firstRequest, (int)scalar("SELECT id FROM enrolment_requests WHERE student_id=? AND class_id=? AND state='pending'",
                                   [$student, $course]),
        'and it is the request she actually sent, not a replacement');
is_same(1, (int)scalar('SELECT COUNT(*) FROM enrolment_requests WHERE student_id=? AND class_id=?', [$student, $course]),
        'with no second row written for this course at all, pending or otherwise');

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
/* "Once" is the part the line above cannot see: applying the same tariff a
   second time looks identical. These two can tell the difference - a second
   decision would either write a second membership row or move the request off
   'approved'. */
is_same(1, (int)scalar('SELECT COUNT(*) FROM class_students WHERE class_id=? AND student_id=?', [$course, $student]),
        'on the one membership row, not a second one beside it');
is_same('approved', (string)scalar('SELECT state FROM enrolment_requests WHERE id=?', [$id]),
        'and the refused second decision did not overwrite the first one');

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

case_('The last place cannot be given away twice');
$small = make_class(['name'=>'Kleine Gruppe', 'capacity'=>1, 'days'=>[]]);
$first = make_student(['first_name'=>'Erste']);
$second = make_student(['first_name'=>'Zweite']);
$askFirst = request_enrolment($first, $small, 'join', null, '');
$askSecond = request_enrolment($second, $small, 'join', null, '');   // asked while there was still room
decide_request($askFirst, true, '');
throws(fn() => decide_request($askSecond, true, ''),
       'the second yes is refused rather than overfilling the hall', 'voll');
is_same(1, (int)scalar('SELECT COUNT(*) FROM class_students WHERE class_id=? AND left_on IS NULL', [$small]),
        'and the course still holds one child');
does_not_throw(fn() => decide_request($askSecond, false, 'Leider voll.'),
               'declining it is still possible, which is how the trainer answers');

case_('A tariff outlives the course it belonged to, as something unattached');
// Not as a row pointing at a course that is gone: invisible on every course
// page because nothing joins, and absent from the unattached list because
// class_id was not NULL. What a charge was priced by has to stay readable.
$migration = (string)file_get_contents(APP_ROOT.'/database/migrations/014_tariffs_belong_to_a_course.sql');
ok(str_contains($migration, 'REFERENCES classes(id) ON DELETE SET NULL'),
   'the constraint says what happens: the tariff stays, its course does not');
if (test_driver() === 'mysql') {
    // The sqlite driver cannot add a constraint to an existing table and says so
    // in the run's footer, so the behaviour itself is checked on the engine that
    // has it rather than asserted twice in two different ways.
    $doomed = make_class(['name'=>'Wird gelöscht', 'days'=>[]]);
    $price = make_tariff(['class_id'=>$doomed, 'name'=>'Preis des gelöschten Kurses']);
    run('DELETE FROM classes WHERE id=?', [$doomed]);
    $kept = one('SELECT * FROM tariffs WHERE id=?', [$price]);
    ok($kept !== null, 'the tariff is still there');
    is_same(null, $kept['class_id'], 'and belongs to no course any more');
    ok(in_array($price, array_map(fn($t) => (int)$t['id'], unattached_tariffs()), true),
       'so the trainer can see it and attach it to another course');
}

// ---------------------------------------------------------------------------
case_('The trainer chooses how a family pays, and what they were given');
sign_in_as($trainer);
$flexCourse = make_class(['name'=>'Flexkurs']);
$flexTariff = make_tariff(['class_id'=>$flexCourse, 'name'=>'Beitrag', 'interval_months'=>1,
                           'rates'=>[1=>3700, 3=>9900, 12=>25200]]);
$kid = make_student(['first_name'=>'Wahl','joined_on'=>'2026-01-01']);
make_enrolment($flexCourse, $kid, ['tariff_id'=>$flexTariff, 'joined_on'=>'2026-01-01']);
$save = fn(array $fields) => act('enrolment_save', ['class_id'=>(string)$flexCourse, 'student_id'=>(string)$kid,
    'tariff_id'=>(string)$flexTariff, 'price'=>'', 'price_note'=>'', 'due_day'=>'0',
    'joined_on'=>'2026-01-01', 'left_on'=>''] + $fields);

$save(['interval_months'=>'3', 'discount_months'=>'0', 'discount_kind'=>'percent', 'discount_value'=>'']);
$row = enrolment($flexCourse, $kid);
is_same(3, (int)$row['interval_months'], 'the interval she chose');
is_same(9900, (int)$row['tariff_price'], 'priced at that interval');

// An interval the tariff has no price for would bill this child at an amount
// nobody could point at, so it is refused rather than guessed.
throws(fn() => $save(['interval_months'=>'6', 'discount_months'=>'0', 'discount_kind'=>'percent', 'discount_value'=>'']),
       'an interval with no price is refused', 'keinen Preis');
throws(fn() => $save(['interval_months'=>'5', 'discount_months'=>'0', 'discount_kind'=>'percent', 'discount_value'=>'']),
       'and so is one that is not a billing interval at all', 'Abrechnungszeitraum');
is_same(3, (int)enrolment($flexCourse, $kid)['interval_months'], 'and the refusal changed nothing');

$save(['interval_months'=>'0', 'discount_months'=>'-1', 'discount_kind'=>'percent', 'discount_value'=>'20',
       'discount_note'=>'Geschwisterrabatt']);
$row = enrolment($flexCourse, $kid);
is_same(1, (int)$row['interval_months'], 'back to the tariff’s usual interval');
is_same(-1, (int)$row['discount_months'], 'the discount runs for as long as they stay');
is_same(20, (int)$row['discount_value'], 'at twenty per cent');
is_same('Geschwisterrabatt', $row['discount_note'], 'under the name that goes on the invoice');

// A value of nothing is no discount, and the name goes with it: a name left
// behind on an enrolment with no discount reads as a discount on the invoice.
$save(['interval_months'=>'0', 'discount_months'=>'-1', 'discount_kind'=>'percent', 'discount_value'=>'',
       'discount_note'=>'Geschwisterrabatt']);
$row = enrolment($flexCourse, $kid);
is_same(0, (int)$row['discount_months'], 'no value means no discount');
is_same('', $row['discount_note'], 'and no name either');
throws(fn() => $save(['interval_months'=>'0', 'discount_months'=>'3', 'discount_kind'=>'percent', 'discount_value'=>'120']),
       'a discount over 100 per cent is refused', '0 bis 100');

// ---------------------------------------------------------------------------
case_('A new course says what it still needs, rather than billing nobody quietly');
sign_in_as($trainer);
$empty = make_class(['name'=>'Ganz neu', 'days'=>[]]);
$what = fn(int $c) => array_column(class_next_steps($c), 'what');
ok(in_array(t('Trainingstag eintragen','Add a training day'), $what($empty), true), 'a day to meet on');
ok(in_array(t('Tarif anlegen','Add a tariff'), $what($empty), true), 'and a price');
ok(str_contains(render_view('classes', ['id'=>$empty]), 'Noch zu tun'), 'and the course page says so');

fixture('class_days', ['class_id'=>$empty, 'weekday'=>2, 'starts_at'=>'17:00:00', 'ends_at'=>'18:00:00',
                       'location'=>'', 'sort_order'=>0]);
ok(!in_array(t('Trainingstag eintragen','Add a training day'), $what($empty), true), 'a day ticks the first one off');
make_tariff(['class_id'=>$empty, 'name'=>'Beitrag']);
is_same([], $what($empty), 'and a tariff clears the list');
ok(!str_contains(render_view('classes', ['id'=>$empty]), 'Noch zu tun'), 'so the card goes away');
