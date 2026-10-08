<?php
/** Levels and age groups: the two ways children are grouped, and the difference. */
$trainer = make_account(['role'=>'trainer']); sign_in_as($trainer);

case_('A portal always has levels, and one of them is where a new child starts');
ok(count(levels()) >= 3, 'the shipped levels are there');
$default = level_default();
ok($default !== null, 'one of them is the default');
is_same('Anfänger', $default['name'], 'and it is the first one, as she asked');

case_('Levels are the trainer\'s own words, not the software\'s');
act('level_save', ['id'=>$default['id'], 'name'=>'Einsteiger', 'description'=>'Erste Schläge.',
                   'sort_order'=>'5', 'is_default'=>'1']);
is_same('Einsteiger', level_default()['name'], 'renaming takes effect at once');
$student = make_student(['level_id'=>$default['id']]);
is_same('Einsteiger', level_name((int)$default['id']), 'and everybody pointing at it reads the new name');

case_('The default moves rather than multiplying');
act('level_save', ['name'=>'Wettkampf', 'sort_order'=>'40', 'is_default'=>'1']);
is_same(1, (int)scalar('SELECT COUNT(*) FROM levels WHERE is_default=1'), 'exactly one level is the default');
is_same('Wettkampf', level_default()['name'], 'and it is the one just claimed');

case_('The last level cannot be archived away');
run('UPDATE levels SET archived=1 WHERE name<>?', ['Wettkampf']);
$last = one('SELECT * FROM levels WHERE archived=0');
throws(fn() => act('level_save', ['id'=>$last['id'], 'name'=>'Wettkampf', 'archived'=>'1']),
       'archiving the only living level is refused', 'mindestens eine');
throws(fn() => act('level_save', ['id'=>$last['id'], 'name'=>'Wettkampf', 'archived'=>'1', 'is_default'=>'1']),
       'and an archived level cannot be the default one', 'archivierte');
run('UPDATE levels SET archived=0');

case_('An age is whole completed years, and a date in the future is not an age');
is_same(11, student_age(date('Y-m-d', strtotime('-11 years -1 day'))), 'the day after the eleventh birthday');
is_same(12, student_age(date('Y-m-d', strtotime('-12 years'))), 'the twelfth birthday itself counts as twelve');
is_same(11, student_age(date('Y-m-d', strtotime('-12 years +1 day'))), 'the day before it does not');
is_same(null, student_age(null), 'no date of birth, no age');
is_same(null, student_age(''), 'and neither does an empty one');
is_same(null, student_age(date('Y-m-d', strtotime('+1 year'))), 'nor a date in the future');
is_same(null, student_age('2026-02-30'), 'nor a day that does not exist');

case_('The shipped bands cover every age with no gap and no overlap');
is_same([], age_group_warnings(), 'nothing to warn about');
is_same('Unter 12', age_group_for_age(0)['name'], 'a newborn');
is_same('Unter 12', age_group_for_age(11)['name'], 'and an eleven-year-old');
is_same('Jugend', age_group_for_age(12)['name'], 'twelve is where youth starts');
is_same('Jugend', age_group_for_age(17)['name'], 'and where it ends');
is_same('Erwachsene', age_group_for_age(18)['name'], 'eighteen is an adult');
is_same('Erwachsene', age_group_for_age(90)['name'], 'and so is ninety, because the last band is open-ended');
is_same(null, age_group_for_age(null), 'an unknown age is in no band');

case_('A group is worked out from the date of birth and moves with a birthday');
$child = one('SELECT * FROM students WHERE id=?', [make_student(['birth_date'=>date('Y-m-d', strtotime('-11 years -2 days'))])]);
is_same('Unter 12', age_group_name($child), 'eleven years old today');
$child['birth_date'] = date('Y-m-d', strtotime('-12 years'));
is_same('Jugend', age_group_name($child), 'and the day they turn twelve, without anybody editing anything');

case_('Nobody pins a band: the date of birth decides, whatever a row once held [ADR 0026]');
/* 038 took the pin away with its column. A row that still carries one - read
   before the update, or written by hand - is worked out like any other: Sophie
   Reiter, 14 and pinned to „Erwachsene", reads „Jugend". */
$adults = one('SELECT * FROM age_groups WHERE name=?', ['Erwachsene']);
$child['age_group_id'] = $adults['id'];
is_same('Jugend', age_group_name($child), 'a band the row names is no band of the child’s');
is_same('Jugend', student_age_group($child)['name'] ?? null, 'and the rule gives the band itself, nothing beside it');

case_('It says why a group is blank, rather than being blank');
is_same('Kein Geburtsdatum', age_group_name(['birth_date'=>null]), 'no date of birth');
is_same(null, student_age_group(['birth_date'=>null]), 'and the rule gives no band');
run('DELETE FROM age_groups');
is_same('Keine passende Gruppe', age_group_name(['birth_date'=>'2015-01-01']), 'no band covers the age');
test_reset(); sign_in_as($trainer = make_account(['role'=>'trainer']));

case_('Bands that overlap or leave a gap are pointed out rather than refused');
run('DELETE FROM age_groups');
foreach ([['Klein',0,12,10], ['Mittel',10,17,20], ['Groß',20,null,30]] as [$n,$lo,$hi,$o])
    fixture('age_groups', ['name'=>$n,'min_age'=>$lo,'max_age'=>$hi,'sort_order'=>$o,'archived'=>0,'created_at'=>now()]);
$warnings = age_group_warnings();
ok(count($warnings) >= 2, 'both problems are reported');
ok(str_contains(implode(' ', $warnings), 'überschneiden'), 'the overlap is named');
ok(str_contains(implode(' ', $warnings), 'fehlt ein Jahrgang'), 'and so is the year nobody covers');
is_same('Klein', age_group_for_age(11)['name'], 'where two bands overlap, the first in her order wins');

case_('An impossible band is refused at the point of entry');
throws(fn() => act('age_group_save', ['name'=>'Falsch', 'min_age'=>'20', 'max_age'=>'10']),
       'an upper age below the lower one', 'Höchstalter');
throws(fn() => act('age_group_save', ['name'=>'Falsch', 'min_age'=>'500']),
       'an age nobody reaches', 'zwischen 0 und 120');
does_not_throw(fn() => act('age_group_save', ['name'=>'Senioren', 'min_age'=>'60', 'max_age'=>'']),
       'an empty upper age means "and older"');
is_same(null, one('SELECT max_age FROM age_groups WHERE name=?', ['Senioren'])['max_age'], 'and is stored as no limit');

case_('The trainer can edit both lists without an administrator');
sign_in_as(make_account(['role'=>'trainer']));
does_not_throw(fn() => act('level_save', ['name'=>'Neu', 'sort_order'=>'99']), 'a trainer may add a level');
does_not_throw(fn() => act('age_group_save', ['name'=>'Neu', 'min_age'=>'0', 'max_age'=>'3']), 'and an age group');
sign_in_as(make_account(['role'=>'student']));
throws(fn() => act('level_save', ['name'=>'Nein', 'sort_order'=>'1']), 'a student may not', 'Kein Zugriff');

case_('Filtering by an age group finds every child the band covers, on their birthday too');
/* Found by the whole-app review of October 2026, when the filter bounded the
   birth date in SQL: the lower bound was a year too high and the upper one a
   day too low, so an 11–12 band found only twelve-year-olds, and not the one
   whose thirteenth birthday is tomorrow. The filter now asks the one rule; the
   band comes first in Verwaltung's order, so the rule puts every age it covers
   in it. */
sign_in_as($trainer = make_account(['role'=>'trainer']));
$band = fixture('age_groups', ['name'=>'Elf bis Zwölf', 'min_age'=>11, 'max_age'=>12, 'sort_order'=>1,
                               'archived'=>0, 'created_at'=>now()]);
// Written out here rather than with modify('-11 years'), which turns 29 February
// into 1 March and would make the test disagree with itself one day in four years.
$born = function (int $years, int $days = 0): string {
    $today = new DateTimeImmutable(today());
    $year = (int)$today->format('Y') - $years; $month = (int)$today->format('n');
    $day = min((int)$today->format('j'), (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'));
    return $today->setDate($year, $month, $day)->modify(($days < 0 ? '' : '+').$days.' days')->format('Y-m-d');
};
$kids = [
    'eleven today'               => [$born(11), true],
    'eleven tomorrow'            => [$born(11, 1), false],
    'twelve today'               => [$born(12), true],
    'thirteen tomorrow'          => [$born(13, 1), true],
    'thirteen today'             => [$born(13), false],
    'eleven and a half'          => [$born(11, -180), true],
];
foreach ($kids as $who => [$birth, $inBand]) {
    $id = make_student(['first_name'=>$who, 'birth_date'=>$birth]);
    $found = array_map('intval', array_column(filtered_students(['age_group'=>(string)$band]), 'id'));
    is_same($inBand, in_array($id, $found, true), $who.' ('.$birth.')'.($inBand ? ' is in the band' : ' is not'));
    $age = student_age($birth);
    is_same($age >= 11 && $age <= 12, in_array($id, $found, true), $who.': the filter agrees with the age the child’s page shows');
}

// ---------------------------------------------------------------------------
// The students list by age group (docs/design/2026-10-07-…, Part 1 revised)
// ---------------------------------------------------------------------------

case_('Where two bands overlap, the filter, the row and „Nach Alter" name the same band [ADR 0026]');
/* The filter bounded the birth date by the chosen band's own ages, in SQL: a
   second copy of the rule, which listed a child under one band while the card
   named another wherever two bands overlap. One rule now - the first band in
   Verwaltung's order that covers the age - and every place asks it. */
test_reset(); sign_in_as($trainer = make_account(['role'=>'trainer']));
run('DELETE FROM age_groups');
$band = fn(string $name, int $from, ?int $to, int $order): int => fixture('age_groups',
    ['name'=>$name, 'min_age'=>$from, 'max_age'=>$to, 'sort_order'=>$order, 'archived'=>0, 'created_at'=>now()]);
[$early, $late] = [$band('Früh', 8, 12, 10), $band('Spät', 10, 15, 20)];
// A hundred days past a birthday, so no case here turns on today's date.
$aged = fn(int $years): string => (new DateTimeImmutable(today()))->modify('-'.$years.' years')->modify('-100 days')->format('Y-m-d');
$byId = fn(array $rows): array => array_column($rows, null, 'id');
$ids = fn(array $rows): array => array_map('intval', array_column($rows, 'id'));
$ida = make_student(['first_name'=>'Ida', 'last_name'=>'Überlapp', 'birth_date'=>$aged(11), 'level_id'=>level_default()['id']]);
$ben = make_student(['first_name'=>'Ben', 'last_name'=>'Später', 'birth_date'=>$aged(14)]);
$rows = $byId(filtered_students([]));
is_same('Früh', $rows[$ida]['band']['name'] ?? null, 'Ida, 11, is in „Früh", the first band that covers her, though „Spät" covers her too');
is_same($rows[$ida]['band']['name'] ?? null, age_group_name(one('SELECT * FROM students WHERE id=?', [$ida])), 'and her page names the same band');
is_same([$ida], $ids(filtered_students(['age_group'=>(string)$early])), 'the filter for „Früh" finds her');
is_same([$ben], $ids(filtered_students(['age_group'=>(string)$late])), 'and the filter for „Spät" finds Ben, 14, and not her');
is_same([['age-group-'.$early, [$ida]], ['age-group-'.$late, [$ben]]],
        array_map(fn(array $s): array => [$s['key'], $ids($s['rows'])], student_sections(filtered_students(['sort'=>'age']))),
        '„Nach Alter" lists her once, under „Früh", and Ben under „Spät"');

case_('Each row of the list carries what it shows, so no row asks for it');
$running = make_class(['name'=>'Läuft']);
$over = make_class(['name'=>'Vorbei', 'archived'=>1]);
$lea = make_student(['first_name'=>'Lea', 'last_name'=>'Ausgetreten', 'birth_date'=>$aged(9)]);
make_enrolment($running, $ida);
make_enrolment($over, $ben);
make_enrolment($running, $lea, ['left_on'=>'2026-01-01']);
$rows = $byId(filtered_students([]));
is_same([11, 'Früh', level_default()['name'], true], [$rows[$ida]['age'], $rows[$ida]['band']['name'] ?? null, $rows[$ida]['level_name'], $rows[$ida]['in_course']],
        'Ida’s row: her age, her band, her level, and that she is in a course');
is_same(false, $rows[$ben]['in_course'], 'a course that was archived is no course to be in, for „Ohne Kurs"');
is_same(false, $rows[$lea]['in_course'], 'nor one the child has left');
is_same(2, query_count(fn() => filtered_students(['sort'=>'age', 'age_group'=>(string)$early])),
        'the rows and the bands: two queries, however many children and whatever is chosen');

case_('„Nach Alter": each band in Verwaltung’s order, the youngest first, then no band, then no birth date');
$kai = make_student(['first_name'=>'Kai', 'last_name'=>'Jünger', 'birth_date'=>$aged(8)]);
$eva = make_student(['first_name'=>'Eva', 'last_name'=>'Erwachsen', 'birth_date'=>$aged(30)]);
$max = make_student(['first_name'=>'Max', 'last_name'=>'Ohnedatum', 'birth_date'=>null]);
$byAge = filtered_students(['sort'=>'age']);
is_same([$kai, $lea, $ida, $ben, $eva, $max], $ids($byAge),
        '„Früh" youngest first, then „Spät", then Eva, whom no band covers, then Max, without a birth date');
$sections = student_sections($byAge);
is_same(['age-group-'.$early, 'age-group-'.$late, 'no-age-group', 'no-birth-date'], array_column($sections, 'key'),
        'four sections, each keyed by the anchor it is shown under');
is_same([[$kai, $lea, $ida], [$ben], [$eva], [$max]], array_map(fn(array $s): array => $ids($s['rows']), $sections), 'each keeping the list’s order');
is_same([$early, $late, null, null], array_map(fn(array $s): ?int => $s['band'] === null ? null : (int)$s['band']['id'], $sections),
        'the band each belongs to, and none for the last two');
is_same(['age-group-'.$early, 'age-group-'.$late, 'no-age-group'], array_column(student_sections(array_slice($byAge, 2, 3)), 'key'),
        'a page cut from the list has the sections of its own rows, in order');
is_same([$lea, $eva, $kai, $max, $ben, $ida], $ids(filtered_students([])), 'A–Z is by last name, whatever the bands');
run('UPDATE age_groups SET archived=1 WHERE id=?', [$early]);
$rows = $byId(filtered_students([]));
is_same(['Spät', null], [$rows[$ida]['band']['name'] ?? null, $rows[$kai]['band']], 'an archived band places nobody: Ida, 11, falls to „Spät", and Kai, 8, to none');
$usage = group_usage();
is_same([0, 2, 4], [$usage['age_groups'][$early], $usage['age_groups'][$late], $usage['unplaced']],
        'and Verwaltung counts it so: nobody in the archived band, Ida and Ben in „Spät", four in none');
run('UPDATE age_groups SET archived=0 WHERE id=?', [$early]);
$usage = group_usage();
is_same([3, 1, 2], [$usage['age_groups'][$early], $usage['age_groups'][$late], $usage['unplaced']], 'brought back, it counts its three again');

case_('The address chooses a band the list can show, nobody’s band, or age order, and nothing else [ADR 0026 §5]');
is_same(['age_group'=>(string)$early, 'sort'=>'age'], filters_from(['age_group'=>(string)$early, 'sort'=>'age']), 'a band and age order are taken');
is_same(['age_group'=>'none'], filters_from(['age_group'=>'none']), 'and so is nobody’s band');
foreach (['999999', $early.'abc', '-1', '0'.$early, ' '] as $bad) is_same([], filters_from(['age_group'=>$bad]), 'the band „'.$bad.'" is ignored');
is_same([], filters_from(['age_group'=>['x'], 'sort'=>['age']]), 'and so is a list where one value belongs');
is_same([], filters_from(['sort'=>'name']), 'an order that is not age is A–Z');
run('UPDATE age_groups SET archived=1 WHERE id=?', [$late]);
is_same([], filters_from(['age_group'=>(string)$late]), 'an archived band is no band the list shows');
run('UPDATE age_groups SET archived=0 WHERE id=?', [$late]);
is_same(['q'=>'Ida'], filters_from(['q'=>' Ida ', 'status'=>'', 'course'=>'']), 'a value is trimmed, and a field left empty is no filter');

case_('With a band chosen, the list says how many children without a birth date it leaves out');
is_same(1, left_out_without_birth_date(['age_group'=>(string)$early]), 'Max, without a birth date, would be in the list but for the band');
is_same(0, left_out_without_birth_date(['age_group'=>(string)$early, 'q'=>'Ida']), 'only those the rest of the selection would show');
is_same(0, left_out_without_birth_date(['age_group'=>'none']), 'nobody’s band shows them, so none are left out');
is_same(0, left_out_without_birth_date([]), 'and no band chosen leaves out nobody');
is_same([$eva, $max], $ids(filtered_students(['age_group'=>'none', 'sort'=>'age'])),
        'nobody’s band is the child no band covers, then the one without a birth date');
