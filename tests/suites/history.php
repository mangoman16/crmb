<?php
/**
 * The change log: what changed, in words, and nothing more.
 *
 * It used to offer an undo, and therefore kept a whole copy of every record on
 * every save. What is checked here is that it still says exactly what happened
 * while storing only what actually differed.
 */
$admin = make_account(['role'=>'admin','name'=>'Admin']); sign_in_as($admin);

case_('An update records what differed, and only that');
$sid = make_student(['first_name'=>'Anna','last_name'=>'Vorher','price_cents'=>4500]);
tracked('students', $sid, 'Anna Vorher', fn() =>
    run('UPDATE students SET last_name=? WHERE id=?', ['Nachher', $sid]));
$v = history_for('students', $sid);
is_same(1, count($v), 'one version recorded');
is_same('update', $v[0]['operation'], 'recorded as an update');
is_same('Vorher', json_decode((string)$v[0]['before_json'], true)['last_name'], 'the old value is kept');
is_same('Nachher', json_decode((string)$v[0]['after_json'], true)['last_name'], 'and the new one');
is_same($admin, (int)$v[0]['actor_id'], 'with who did it');
is_same(['last_name'], array_keys(json_decode((string)$v[0]['before_json'], true)),
        'and nothing else: a record with forty columns costs one, not forty');
is_same(['last_name'], array_keys(version_changes($v[0])), 'which is exactly what the page shows');

case_('A change with several fields keeps all of them');
tracked('students', $sid, 'Anna Nachher', fn() =>
    run('UPDATE students SET first_name=?, internal_notes=? WHERE id=?', ['Anna-Marie', 'Trainiert dienstags', $sid]));
$changes = version_changes(history_for('students', $sid)[0]);
is_same(['first_name','internal_notes'], array_keys($changes), 'both fields');
is_same('Anna', $changes['first_name']['from'], 'the old first name');
is_same('Anna-Marie', $changes['first_name']['to'], 'and the new one');

case_('Bookkeeping columns are not a change anybody made');
tracked('students', $sid, 'Anna-Marie', fn() =>
    run('UPDATE students SET updated_at=?, revision=revision+1 WHERE id=?', [now(), $sid]));
is_same([], version_changes(history_for('students', $sid)[0]), 'a touched timestamp is not a change');

case_('A column reads as the word she uses for it');
is_same('Vorname', history_field_label('first_name'), 'a field with a name');
is_same('Leistungsgruppe', history_field_label('level_id'), 'and a foreign key');
is_same('was_auch_immer', history_field_label('was_auch_immer'), 'one nobody has named falls back to itself');
foreach (['first_name','last_name','status','amount_cents','due_on','tariff_id','archived'] as $column)
    ok(history_field_label($column) !== $column, $column.' has been given a word');

case_('A creation keeps what was entered');
$tid = 0;
tracked_insert('tariffs', 'Tarif angelegt', function () use (&$tid) {
    $tid = make_tariff(['name'=>'Neu','price_cents'=>3000]);
    return $tid;
});
$created = history_for('tariffs', $tid);
is_same('insert', $created[0]['operation'], 'recorded as a creation');
is_same(null, $created[0]['before_json'], 'nothing existed before');
is_same('Neu', json_decode((string)$created[0]['after_json'], true)['name'], 'and the whole row is there');

case_('A deletion keeps the whole row, because nothing else describes it any more');
$gone = make_student(['first_name'=>'Weg','last_name'=>'Damit','price_cents'=>2500]);
tracked('students', $gone, 'Weg Damit', fn() => run('DELETE FROM students WHERE id=?', [$gone]), 'delete');
is_same(null, one('SELECT id FROM students WHERE id=?', [$gone]), 'the row is gone');
$deletion = history_for('students', $gone)[0];
is_same('delete', $deletion['operation'], 'recorded as a deletion');
is_same(null, $deletion['after_json'], 'nothing exists afterwards');
$kept = json_decode((string)$deletion['before_json'], true);
is_same('Weg', $kept['first_name'], 'their name');
is_same(2500, (int)$kept['price_cents'], 'and everything else that was on the record');

case_('Nothing puts a change back any more');
ok(!function_exists('revert_version'), 'the undo is gone rather than hidden');
$actions = (string)file_get_contents(APP_ROOT.'/app/actions_config.php');
ok(!str_contains($actions, 'version_revert'), 'and so is the action behind its button');
ok(!str_contains((string)file_get_contents(APP_ROOT.'/views/history.php'), 'version_revert'), 'and the button');
$deleted = make_student(['first_name'=>'Tim','last_name'=>'Weg']);
act('student_delete', ['id'=>(string)$deleted, 'confirmation'=>'Tim Weg']);
$said = (string)($_SESSION['flash']['message'] ?? '');
ok(str_contains($said, 'wiederherstellen lässt es sich nicht'), 'deleting a student says it cannot be restored');
ok(!str_contains($said, 'rückgängig'), 'rather than promising an undo that no longer exists');
is_same('delete', history_for('students', $deleted)[0]['operation'] ?? null, 'and what it points at is really there');

case_('A failed change records no version');
$sid2 = make_student(['first_name'=>'Heil']);
$before = count(history_for('students', $sid2));
throws(function () use ($sid2) {
    transactional(fn() => tracked('students', $sid2, 'geht schief', function () use ($sid2) {
        run('UPDATE students SET first_name=? WHERE id=?', ['Kaputt', $sid2]);
        throw new UserError('failed after the write');
    }));
}, 'the failure propagates');
is_same($before, count(history_for('students', $sid2)), 'no version was left behind');
is_same('Heil', (string)scalar('SELECT first_name FROM students WHERE id=?', [$sid2]), 'and no change either');

case_('History is scoped and ordered newest first');
$other = make_student(['first_name'=>'Andere']);
tracked('students', $other, 'x', fn() => run('UPDATE students SET first_name=? WHERE id=?', ['Geändert', $other]));
is_same(1, count(history_for('students', $other)), 'only this record\'s own versions');
tracked('students', $other, 'y', fn() => run('UPDATE students SET first_name=? WHERE id=?', ['Nochmal', $other]));
$list = history_for('students', $other);
is_same(2, count($list), 'both versions');
ok((int)$list[0]['id'] > (int)$list[1]['id'], 'newest first');

case_('Only a table on the allowlist can be written about');
throws(fn() => history_record('sqlite_master', 1, 'update', 'x', null, null), 'an unknown entity is refused');

case_('The log has a horizon, and the audit log does not');
$auditBefore = (int)scalar('SELECT COUNT(*) FROM audit_log');
run('UPDATE record_versions SET created_at=? WHERE id=?',
    [(new DateTimeImmutable(now()))->modify('-30 months')->format('Y-m-d H:i:s'), (int)$list[1]['id']]);
is_same(1, history_prune(24), 'an entry older than the horizon is removed');
is_same(1, count(history_for('students', $other)), 'and the recent one stays');
is_same(0, history_prune(24), 'running it again removes nothing');
is_same($auditBefore, (int)scalar('SELECT COUNT(*) FROM audit_log'), 'the audit log is untouched: it is evidence, not a convenience');

// ---------------------------------------------------------------------------
case_('A record she set up by hand can be copied, with what belongs to it');
/* Two tariffs that differ in one number, a second course on another evening:
   each of them is ten minutes of retyping, and retyping is where a wrong price
   comes from. What comes with a copy is a judgement, not a foreign key. */
sign_in_as(make_account(['role'=>'admin']));
$course = make_class(['name'=>'Kindertraining', 'location'=>'Halle Nord', 'archived'=>1,
    'days'=>[['weekday'=>1,'starts_at'=>'16:00:00','ends_at'=>'17:30:00','location'=>''],
             ['weekday'=>4,'starts_at'=>'17:00:00','ends_at'=>'18:30:00','location'=>'Halle Süd']]]);
$tariff = make_tariff(['class_id'=>$course, 'name'=>'Beitrag', 'interval_months'=>1,
                       'rates'=>[1=>3700, 12=>25200]]);
fixture('tariff_discounts', ['tariff_id'=>$tariff, 'name'=>'Erster Monat gratis',
                             'months'=>1, 'kind'=>'percent', 'value'=>100, 'sort_order'=>0]);
$kid = make_student(['first_name'=>'Lena']);
make_enrolment($course, $kid, ['tariff_id'=>$tariff]);

$copy = duplicate_record('classes', $course);
$copied = training_class($copy);
ok(str_contains((string)$copied['name'], 'Kopie'), 'the copy says it is one');
is_same(0, (int)$copied['archived'], 'and is not archived, whatever the original was');
is_same(2, count(class_days($copy)), 'both training days came with it');
is_same('17:00:00', class_days($copy)[1]['starts_at'], 'at the times they were at');
is_same(1, count(class_tariffs($copy, true)), 'and its price list');
$copiedTariff = class_tariffs($copy, true)[0];
is_same([1=>3700, 12=>25200], tariff_rates((int)$copiedTariff['id']), 'with both prices');
is_same(1, count(tariff_discount_templates((int)$copiedTariff['id'])), 'and its discount template');
// The children in the original course are emphatically not in the copy: that is
// the difference between "what belongs to this record" and "what points at it".
is_same(0, (int)scalar('SELECT COUNT(*) FROM class_students WHERE class_id=?', [$copy]),
        'and nobody was enrolled in a course that did not exist a moment ago');
is_same(1, (int)scalar('SELECT COUNT(*) FROM class_students WHERE class_id=?', [$course]),
        'while the original keeps its members');

case_('A copy is numbered rather than left identical');
$second = duplicate_record('classes', $course);
ok(training_class($second)['name'] !== $copied['name'], 'the second copy is not called the same as the first');
ok(str_contains((string)training_class($second)['name'], '2'), 'it counts');

case_('Only the records that are hers to build may be copied');
foreach (['students', 'charges', 'payments', 'accounts', 'invoices'] as $table)
    throws(fn() => duplicate_record($table, 1), 'a '.$table.' row cannot be copied', 'kopieren');
// A copied news item is a draft, because the commonest reason to copy one is
// last year's notice and the commonest mistake would be publishing it unchanged.
$item = fixture('news', ['title'=>'Hallenzeiten', 'body'=>'Ab Oktober.', 'published'=>1,
                         'created_at'=>now(), 'updated_at'=>now()]);
is_same(0, (int)scalar('SELECT published FROM news WHERE id=?', [duplicate_record('news', $item)]),
        'a copied news item is not published');

// ---------------------------------------------------------------------------
case_('A student’s custom fields are part of the student’s line, labelled and readable');
/* ADR 0020, §7. field_values has no id to be tracked by, so a student's
   snapshot carries each value as field:<id>. */
sign_in_as($admin);
$colour = fixture('field_definitions', ['label'=>'Lieblingsfarbe', 'label_en'=>'Favourite colour', 'field_type'=>'multiselect', 'section_name'=>'',
    'options_json'=>'["rot","blau"]', 'default_json'=>'[]', 'required'=>0, 'visibility'=>'edit', 'sort_order'=>0, 'archived'=>0]);
$photos = fixture('field_definitions', ['label'=>'Fotos erlaubt', 'label_en'=>'', 'field_type'=>'checkbox', 'section_name'=>'',
    'options_json'=>'[]', 'default_json'=>'false', 'required'=>0, 'visibility'=>'edit', 'sort_order'=>0, 'archived'=>0]);
$since = fixture('field_definitions', ['label'=>'Im Verein seit', 'label_en'=>'', 'field_type'=>'date', 'section_name'=>'',
    'options_json'=>'[]', 'default_json'=>'""', 'required'=>0, 'visibility'=>'internal', 'sort_order'=>0, 'archived'=>0]);
$kid = make_student(['first_name'=>'Feld', 'last_name'=>'Test']);
fixture('field_values', ['student_id'=>$kid, 'field_id'=>$since, 'value_json'=>'""']);
tracked('students', $kid, 'Feld Test', function () use ($kid, $colour, $photos, $since) {
    run('INSERT INTO field_values (student_id,field_id,value_json) VALUES (?,?,?)', [$kid, $colour, '["rot","blau"]']);
    run('INSERT INTO field_values (student_id,field_id,value_json) VALUES (?,?,?)', [$kid, $photos, 'true']);
    run('UPDATE field_values SET value_json=? WHERE student_id=? AND field_id=?', ['"2019-01-27"', $kid, $since]);
});
$changes = version_changes(history_for('students', $kid)[0]);
is_same(['field:'.$colour, 'field:'.$photos, 'field:'.$since], array_keys($changes),
        'a field filled in for the first time is a change, though the row did not exist before');
is_same(['Lieblingsfarbe', 'Fotos erlaubt', 'Im Verein seit'], array_map('history_field_label', array_keys($changes)), 'each named as the settings page names it');
is_same(['rot, blau', 'ja', '27.01.2019'], array_map(fn($c, $p) => history_value($p['to'], $c), array_keys($changes), $changes),
        'a list joined with commas, a box as ja, a date as she writes one');
is_same('—', history_value($changes['field:'.$since]['from'], 'field:'.$since), 'and an empty value as a dash');
tracked('students', $kid, 'Feld Test', fn() => run('UPDATE field_values SET value_json=? WHERE student_id=? AND field_id=?', ['false', $kid, $photos]));
is_same('nein', history_value(version_changes(history_for('students', $kid)[0])['field:'.$photos]['to'], 'field:'.$photos), 'an unticked box reads nein');
$_SESSION['locale'] = 'en';
is_same('Favourite colour', history_field_label('field:'.$colour), 'in English where the field has an English name');
unset($_SESSION['locale']);
is_same('Gelöschtes eigenes Feld', history_field_label('field:999999'), 'and a field that no longer exists says so rather than showing a number');
run('DELETE FROM record_versions');
tracked('students', $kid, 'Feld Test', fn() => run('INSERT INTO field_values (student_id,field_id,value_json) VALUES (?,?,?)',
    [$kid, fixture('field_definitions', ['label'=>'Leer', 'label_en'=>'', 'field_type'=>'text', 'section_name'=>'', 'options_json'=>'[]',
     'default_json'=>'""', 'required'=>0, 'visibility'=>'edit', 'sort_order'=>0, 'archived'=>0]), '""']));
is_same([], version_changes(history_for('students', $kid)[0]), 'a field written empty where nothing was is no change worth a line');
tracked('students', $kid, 'Feld Test', fn() => run('DELETE FROM students WHERE id=?', [$kid]), 'delete');
$kept = json_decode((string)history_for('students', $kid)[0]['before_json'], true);
is_same(['["rot","blau"]', 'false', '"2019-01-27"'], [$kept['field:'.$colour] ?? null, $kept['field:'.$photos] ?? null, $kept['field:'.$since] ?? null],
        'a deleted student’s line keeps their custom values');

case_('What a family writes is labelled, and a contact’s line does not repeat whose it is');
foreach (['address'=>'Anschrift', 'phone'=>'Telefonnummer', 'owner_name'=>'Name', 'relation_label'=>'Beziehung', 'is_primary'=>'Standardkontakt'] as $column => $word)
    is_same($word, history_field_label($column), $column.' reads as „'.$word.'“');
is_same('27.01.2019', history_value('2019-01-27', 'birth_date'), 'a birth date reads as she writes one');
is_same('2026-09-30 14:00:00', history_value('2026-09-30 14:00:00', 'created_at'), 'while a DATETIME is left alone');
is_same('2026-02-30', history_value('2026-02-30'), 'and something shaped like a date that is none is shown as it is');
$child = make_student(['first_name'=>'Kontakt', 'last_name'=>'Kind']);
$contact = tracked_insert('contacts', 'Kontakt Kind · Oma', fn() => fixture('contacts', ['student_id'=>$child, 'owner_name'=>'Oma',
    'relation_label'=>'Großmutter', 'phone'=>'1', 'email'=>'', 'is_primary'=>1]));
ok(!array_key_exists('student_id', version_changes(history_for('contacts', $contact)[0])), 'a contact’s line hides student_id: the label names the child');
$charged = tracked_insert('charges', 'Beitrag', fn() => fixture('charges', ['student_id'=>$child, 'label'=>'Beitrag', 'amount_cents'=>100,
    'gross_cents'=>100, 'discount_cents'=>0, 'discount_note'=>'', 'due_on'=>'2026-09-01', 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()]));
ok(array_key_exists('student_id', version_changes(history_for('charges', $charged)[0])), 'while a charge’s still shows it, being the only thing that says whose');

case_('„Von Familien“ lists what families changed, and nothing staff did in their place');
run('DELETE FROM record_versions');
$familyLogin = make_account(['role'=>'student', 'name'=>'Familie Test']);
$theirs = make_student(['first_name'=>'Eigen', 'last_name'=>'Kind', 'account_id'=>$familyLogin]);
sign_in_as($familyLogin);
tracked('students', $theirs, 'Eigen Kind', fn() => run('UPDATE students SET phone=? WHERE id=?', ['1', $theirs]));
$_SESSION['impersonator_id'] = $admin;
tracked('students', $theirs, 'Eigen Kind', fn() => run('UPDATE students SET phone=? WHERE id=?', ['2', $theirs]));
unset($_SESSION['impersonator_id']);
sign_in_as($admin);
tracked('students', $theirs, 'Eigen Kind', fn() => run('UPDATE students SET phone=? WHERE id=?', ['3', $theirs]));
$byFamilies = history_recent(60, true);
is_same([$familyLogin], array_map('intval', array_column($byFamilies, 'actor_id')), 'only the family’s own change');
is_same('student', $byFamilies[0]['actor_role'] ?? null, 'with the actor’s role, read when the page is read');
is_same(3, count(history_recent()), 'while „Alle“ has all three, the change made while viewing as the family under the administrator');
is_same(['admin', 'admin', 'student'], array_column(history_for('students', $theirs), 'actor_role'), 'and a record’s own history carries the role too');
