<?php
/**
 * Record versioning: what changed, and putting it back.
 *
 * Reverting is itself a change, recorded like any other, so the history stays a
 * complete account of the row rather than something that rewrites itself.
 */
$admin = make_account(['role'=>'admin','name'=>'Admin']); sign_in_as($admin);

case_('An update records the state on both sides');
$sid = make_student(['first_name'=>'Anna','last_name'=>'Vorher','price_cents'=>4500]);
tracked('students', $sid, 'Schüler geändert', fn() =>
    run('UPDATE students SET last_name=? WHERE id=?', ['Nachher', $sid]));
$v = history_for('students', $sid);
is_same(1, count($v), 'one version recorded');
is_same('update', $v[0]['operation'], 'recorded as an update');
is_same('Vorher', json_decode($v[0]['before_json'], true)['last_name'], 'the old value is kept');
is_same('Nachher', json_decode($v[0]['after_json'], true)['last_name'], 'and the new one');
is_same($admin, (int)$v[0]['actor_id'], 'with who did it');

case_('Reverting restores the previous values');
does_not_throw(fn() => revert_version((int)$v[0]['id']), 'the revert runs');
is_same('Vorher', (string)scalar('SELECT last_name FROM students WHERE id=?', [$sid]), 'the old value is back');
is_same(4500, (int)scalar('SELECT price_cents FROM students WHERE id=?', [$sid]), 'untouched columns are unchanged');

case_('A revert is itself recorded, and cannot be applied twice');
$after = history_for('students', $sid);
is_same(2, count($after), 'the revert added a version of its own');
ok($after[1]['reverted_at'] !== null, 'the original version is marked as reverted');
throws(fn() => revert_version((int)$v[0]['id']), 'reverting the same version again is refused');

case_('A creation is recorded and can be undone');
$tid = 0;
tracked_insert('tariffs', 'Tarif angelegt', function () use (&$tid) {
    $tid = fixture('tariffs', ['name'=>'Neu','price_cents'=>3000,'period'=>'monthly','due_days'=>14,'archived'=>0]);
    return $tid;
});
$created = history_for('tariffs', $tid);
is_same('insert', $created[0]['operation'], 'recorded as a creation');
is_same(null, $created[0]['before_json'], 'nothing existed before');
does_not_throw(fn() => revert_version((int)$created[0]['id']), 'undoing a creation runs');
is_same(null, one('SELECT id FROM tariffs WHERE id=?', [$tid]), 'the row is gone again');

case_('A deletion keeps enough to bring the row back');
$gone = make_student(['first_name'=>'Weg','last_name'=>'Damit','price_cents'=>2500]);
tracked('students', $gone, 'Schüler gelöscht', fn() => run('DELETE FROM students WHERE id=?', [$gone]), 'delete');
is_same(null, one('SELECT id FROM students WHERE id=?', [$gone]), 'the row is gone');
$deletion = history_for('students', $gone);
is_same('delete', $deletion[0]['operation'], 'recorded as a deletion');
does_not_throw(fn() => revert_version((int)$deletion[0]['id']), 'undeleting runs');
$back = one('SELECT * FROM students WHERE id=?', [$gone]);
ok($back !== null, 'the row is back');
is_same('Weg', $back['first_name'], 'with its values');
is_same($gone, (int)$back['id'], 'and its original id, so anything referring to it still matches');

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

case_('Only a tracked table can be reverted');
throws(fn() => history_record('sqlite_master', 1, 'update', 'x', null, null), 'an unknown entity is refused');
