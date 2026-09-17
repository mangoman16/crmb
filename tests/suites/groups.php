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

case_('Pinning one keeps it where it is put');
$pinned = one('SELECT id FROM age_groups WHERE name=?', ['Erwachsene']);
$child['age_group_id'] = $pinned['id'];
is_same('Erwachsene', age_group_name($child), 'the pinned band wins over the date of birth');
is_same(true, student_age_group($child)['pinned'], 'and it says that it was pinned rather than worked out');

case_('It says why a group is blank, rather than being blank');
is_same('Kein Geburtsdatum', age_group_name(['birth_date'=>null, 'age_group_id'=>null]), 'no date of birth');
run('DELETE FROM age_groups');
is_same('Keine passende Gruppe', age_group_name(['birth_date'=>'2015-01-01', 'age_group_id'=>null]), 'no band covers the age');
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
