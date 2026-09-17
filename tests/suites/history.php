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
