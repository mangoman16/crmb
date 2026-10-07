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
throws(fn() => history_record('schema_migrations', 1, 'update', 'x', null, null), 'a table that is not on it is refused, though it exists');
foreach (['message_templates', 'field_definitions'] as $entity)
    throws(fn() => history_record($entity, 1, 'update', 'x', null, null), 'and so is '.$entity.', whose feature has gone [ADR 0026 §7]');

case_('The log has a horizon, and the audit log does not');
$auditBefore = (int)scalar('SELECT COUNT(*) FROM audit_log');
run('UPDATE record_versions SET created_at=? WHERE id=?',
    [(new DateTimeImmutable(now()))->modify('-30 months')->format('Y-m-d H:i:s'), (int)$list[1]['id']]);
is_same(1, history_prune(24), 'an entry older than the horizon is removed');
is_same(1, count(history_for('students', $other)), 'and the recent one stays');
is_same(0, history_prune(24), 'running it again removes nothing');
is_same($auditBefore, (int)scalar('SELECT COUNT(*) FROM audit_log'), 'the audit log is untouched: it is evidence, not a convenience');

// ---------------------------------------------------------------------------
case_('A line written while custom fields existed still reads, without the tables it named');
/* ADR 0026 §7. A student's line kept each custom value as field:<id>. The
   definitions that named them went with migration 032, so every such key reads
   the same and nothing is looked up; the value is read from the line alone. */
$kid = make_student(['first_name'=>'Feld', 'last_name'=>'Test']);
$line = fixture('record_versions', ['entity'=>'students', 'entity_id'=>$kid, 'operation'=>'update', 'label'=>'Feld Test',
    'before_json'=>json_encode(['field:7'=>'["rot"]', 'field:8'=>'true']),
    'after_json'=>json_encode(['field:7'=>'["rot","blau"]', 'field:9'=>'"2019-01-27"']), 'actor_id'=>$admin, 'created_at'=>now()]);
$changes = version_changes(one('SELECT * FROM record_versions WHERE id=?', [$line]));
is_same(['field:7', 'field:8', 'field:9'], array_keys($changes), 'every value the line kept is still a change');
is_same(array_fill(0, 3, 'Früheres eigenes Feld'), array_map('history_field_label', array_keys($changes)), 'each named „Früheres eigenes Feld“, whichever it was');
is_same(0, query_count(fn() => history_field_label('field:7')), 'by a name that asks the database nothing');
is_same([['rot', 'rot, blau'], ['ja', '—'], ['—', '27.01.2019']],
        array_map(fn($c, $p) => [history_value($p['from'], $c), history_value($p['to'], $c)], array_keys($changes), $changes),
        'its values read as they did: a list joined with commas, a box as ja, a date as she writes one, nothing as a dash');
$_SESSION['locale'] = 'en';
is_same('Former custom field', history_field_label('field:7'), 'in English too');
unset($_SESSION['locale']);

case_('A line about a feature that has gone is named, not refused');
/* Message templates and custom fields are no longer tracked (ADR 0026 §7), but
   what was written about them stays until the horizon removes it: the log
   outlives any feature. */
foreach (['message_templates'=>'Zahlungserinnerung', 'field_definitions'=>'T-Shirt-Größe'] as $entity => $label)
    fixture('record_versions', ['entity'=>$entity, 'entity_id'=>1, 'operation'=>'update', 'label'=>$label,
        'before_json'=>json_encode(['name'=>'Alt']), 'after_json'=>json_encode(['name'=>'Neu']), 'actor_id'=>$admin, 'created_at'=>now()]);
is_same(['Frühere E-Mail-Vorlage', 'Früheres eigenes Feld'], [entity_label('message_templates'), entity_label('field_definitions')],
        'an entity no longer tracked is named as it was, marked as former, never by its table');
is_same('irgendetwas_altes', entity_label('irgendetwas_altes'), 'and one nobody named is shown as it was stored, rather than refusing the page');
$page = render_view('history');
ok(str_contains($page, e('Geändert: Frühere E-Mail-Vorlage · Zahlungserinnerung')) && str_contains($page, e('Geändert: Früheres eigenes Feld · T-Shirt-Größe')),
   'and the change log draws both lines, in words');
ok(str_contains($page, 'Früheres eigenes Feld') && str_contains($page, 'rot, blau'), 'and the student’s line with its former custom values');

case_('A staff save that changes nothing has nothing to say');
$quiet = make_student(['first_name'=>'Still', 'last_name'=>'Kind', 'level_id'=>(int)(level_default()['id'] ?? 0) ?: null]);
run('DELETE FROM record_versions');
act('student_save', ['id'=>(string)$quiet, 'revision'=>'1', 'first_name'=>'Still', 'last_name'=>'Kind', 'email'=>'', 'birth_date'=>'',
    'joined_on'=>'2025-01-01', 'ended_on'=>'', 'status'=>'active', 'internal_notes'=>'', 'address'=>'', 'phone'=>'']);
is_same([], version_changes(history_for('students', $quiet)[0] ?? ['before_json'=>null, 'after_json'=>null, 'entity'=>'students']),
        'an empty box posted where nothing was stored is no change');

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
view_as($admin, $familyLogin);
tracked('students', $theirs, 'Eigen Kind', fn() => run('UPDATE students SET phone=? WHERE id=?', ['2', $theirs]));
unset($_SESSION['impersonator_id']);
sign_in_as($admin);
tracked('students', $theirs, 'Eigen Kind', fn() => run('UPDATE students SET phone=? WHERE id=?', ['3', $theirs]));
$byFamilies = history_recent(60, true);
is_same([$familyLogin], array_map('intval', array_column($byFamilies, 'actor_id')), 'only the family’s own change');
is_same('student', $byFamilies[0]['actor_role'] ?? null, 'with the actor’s role, read when the page is read');
is_same(3, count(history_recent()), 'while „Alle“ has all three, the change made while viewing as the family under the administrator');
is_same(['admin', 'admin', 'student'], array_column(history_for('students', $theirs), 'actor_role'), 'and a record’s own history carries the role too');

// ---------------------------------------------------------------------------
case_('The change log never holds a password hash, a session counter or a last visit [R5, S6]');
/* Moved from the usernames suite when usernames went (ADR 0021). */
sign_in_as($admin);
run("DELETE FROM record_versions");
$recorded = fn() => array_merge(...array_map(fn($v) => array_keys((array)json_decode((string)$v['before_json'], true) + (array)json_decode((string)$v['after_json'], true)),
    rows("SELECT before_json, after_json FROM record_versions WHERE entity='accounts'")));
$logged = tracked_insert('accounts', 'Neu', fn() => make_account(['last_seen_at' => now()]));
tracked('accounts', $logged, 'Neu', fn() => run("UPDATE accounts SET password_hash='x', auth_version=auth_version+1, last_seen_at=? WHERE id=?", [now(), $logged]));
tracked('accounts', $logged, 'Neu', fn() => run('DELETE FROM accounts WHERE id=?', [$logged]), 'delete');
is_same(3, (int)scalar("SELECT COUNT(*) FROM record_versions WHERE entity='accounts'"), 'an insert, an update and a delete were recorded');
is_same([], array_values(array_intersect($recorded(), ['password_hash', 'auth_version', 'last_seen_at'])), 'and none of them holds any of the three');
ok(in_array('email', $recorded(), true), 'while the rest of the row is there');

case_('A creation is put down to who is signed in, unless its caller names the one person who is not yet');
/* ADR 0021, §3: the student an invitation by address makes is recorded before
   anybody is signed in, so create_own_student() names the link's own login. */
$holderOfLink = make_account(['role' => 'student']);
$byAdmin = tracked_insert('students', 'Vom Admin', fn() => make_student());
$named = tracked_insert('students', 'Selbst angelegt', fn() => make_student(), $holderOfLink);
is_same([$admin, $holderOfLink], [(int)history_for('students', $byAdmin)[0]['actor_id'], (int)history_for('students', $named)[0]['actor_id']],
        'the signed-in administrator for one, the named login for the other');
