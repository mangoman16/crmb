<?php
/**
 * How long the portal keeps what it holds (ADR 0032).
 *
 * Each kind of record has a period, a setting of its own, and the daily
 * cleanup (prune_expired()) deletes what is past it. For every period a row a
 * day short of it stays and a row a day past it goes, and nothing else in its
 * table changes; a deleted row's files go the same night; the answer of a
 * consent that holds stays however old; and accounting records are never
 * pruned, whatever the periods are set to.
 */
$admin  = make_account(['role'=>'admin', 'name'=>'Chefin']);
$family = make_account(['role'=>'student', 'name'=>'Lena Hofer', 'email'=>'lena@beispiel.test']);
$kid    = make_student(['account_id'=>$family, 'first_name'=>'Lena', 'last_name'=>'Hofer']);
$course = make_class();
sign_in_as($admin);
// As on a live portal, where every page view brings the schema current first:
// the cleanup deletes nothing on one that is not.
schema_made_current();

/** A moment counted from now, stored the way the cleanup counts it: UTC. */
$ago = fn(string $relative): string => (new DateTimeImmutable(now()))->modify($relative)->format('Y-m-d H:i:s');
/** A calendar date counted from today, for the columns that hold one. */
$daysBack = fn(string $relative): string => (new DateTimeImmutable(today()))->modify($relative)->format('Y-m-d');
$there = fn(string $table, int $id): bool => (bool)scalar('SELECT 1 FROM '.sql_name($table, 'table').' WHERE id=?', [$id]);
/** A stored file of $kind, as old as its row, so the sweep's hour of grace is long past. */
$stored = function (string $kind, string $extension): string {
    $name = bin2hex(random_bytes(16)).'.'.$extension;
    @mkdir(upload_dir($kind), 0775, true);
    file_put_contents(upload_dir($kind).'/'.$name, 'x');
    touch(upload_dir($kind).'/'.$name, time() - 3 * 365 * 86400);
    return $name;
};
$tick = (string)strstr((string)strstr((string)file_get_contents(APP_ROOT.'/app/tick.php'), 'function prune_expired('), 'function tick_due(', true);

// ---------------------------------------------------------------------------
case_('Every period is a setting of its own, with the period the owner accepted [ADR 0032]');
$periods = ['messages_months'=>12, 'removed_messages_days'=>30, 'absences_months'=>3, 'attendance_months'=>24, 'notices_days'=>90,
            'mail_months'=>12, 'proofs_months'=>24, 'audit_months'=>36, 'consent_months'=>36];
foreach ($periods as $key => $default) {
    $spec = setting_schema()[$key] ?? [];
    is_same([$default, 'int', 'system', true, 1], [$spec['default'] ?? null, $spec['kind'] ?? null, $spec['group'] ?? null, $spec['advanced'] ?? null, $spec['min'] ?? null],
            $key.' is declared once, '.$default.' by default, under Einstellungen → System, „Erweitert“, never less than 1');
    ok(str_contains($tick, "('".$key."')"), $key.' is what the cleanup counts its period by');
}
throws(fn() => setting_validate('messages_months', setting_schema()['messages_months'], '0'), 'a period of 0, which would keep nothing, is refused', 'zu klein');

// ---------------------------------------------------------------------------
case_('A row a day short of its period stays, and one a day past it goes, in every table [ADR 0032]');
$thread = make_thread([$family, $admin], ['kind'=>'staff_direct']);
$message = fn(string $sent, ?string $removed = null): int => fixture('messages',
    ['thread_id'=>$thread, 'sender_id'=>$family, 'body'=>'Bis Donnerstag!', 'created_at'=>$sent, 'removed_at'=>$removed]);
$photo = function (int $message) use ($stored): string {
    $name = $stored('message', 'jpg');
    fixture('message_files', ['message_id'=>$message, 'kind'=>'image', 'stored_name'=>$name, 'original_name'=>'', 'mime'=>'image/jpeg',
                              'bytes'=>1, 'seconds'=>0, 'created_at'=>now()]);
    return $name;
};
$proof = function (string $uploaded) use ($stored, $kid, $family): array {
    $name = $stored('proof', 'pdf');
    return [fixture('payment_proofs', ['charge_id'=>null, 'invoice_id'=>null, 'student_id'=>$kid, 'stored_name'=>$name,
        'original_name'=>'beleg.pdf', 'mime'=>'application/pdf', 'bytes'=>1, 'note'=>'', 'uploaded_by'=>$family, 'created_at'=>$uploaded]), $name];
};
$absence = fn(string $endsOn): int => fixture('absences', ['student_id'=>$kid, 'reason'=>'sick', 'starts_on'=>$endsOn, 'ends_on'=>$endsOn, 'created_by'=>$family]);
$session = fn(string $on): int => fixture('attendance', ['class_id'=>$course, 'student_id'=>$kid, 'session_on'=>$on, 'status'=>'present',
                                                        'note'=>'', 'recorded_by'=>$admin, 'created_at'=>now()]);
$notice = fn(string $at, string $kind = 'message'): int => fixture('notifications', ['account_id'=>$family, 'kind'=>$kind, 'title'=>'Neue Nachricht', 'body'=>'Bis Donnerstag!',
                                                          'link_page'=>'', 'link_params'=>'', 'read_at'=>null, 'created_at'=>$at]);
$mail = fn(string $queued): int => fixture('mail_jobs', ['account_id'=>$family, 'recipient'=>'lena@beispiel.test', 'subject'=>'Offener Beitrag',
    'payload'=>'', 'category'=>'payments', 'status'=>'sent', 'attempts'=>1, 'created_at'=>$queued, 'sent_at'=>$queued]);
$audit = fn(string $at): int => fixture('audit_log', ['actor_id'=>$admin, 'action'=>'student.saved', 'entity_type'=>'student', 'entity_id'=>$kid, 'created_at'=>$at]);

$rows = [
    // what => [table, the row a day short of its period, the row a day past it]
    'a message, counted from when it was sent' => ['messages', $message($ago('-12 months +1 day')), $message($ago('-12 months -1 day'))],
    'a message taken down, counted from when it was' => ['messages', $message($ago('-2 days'), $ago('-29 days')), $message($ago('-2 days'), $ago('-31 days'))],
    'an absence, counted from the day it ended' => ['absences', $absence($daysBack('-3 months +1 day')), $absence($daysBack('-3 months -1 day'))],
    'attendance, counted from the training' => ['attendance', $session($daysBack('-24 months +1 day')), $session($daysBack('-24 months -1 day'))],
    'a notification' => ['notifications', $notice($ago('-89 days')), $notice($ago('-91 days'))],
    'a news item’s notice, like any other in the bell' => ['notifications', $notice($ago('-89 days'), 'news'), $notice($ago('-91 days'), 'news')],
    'a mail in the outbox, counted from when it was queued' => ['mail_jobs', $mail($ago('-12 months +1 day')), $mail($ago('-12 months -1 day'))],
    'a payment proof, counted from the upload' => ['payment_proofs', ($keptProof = $proof($ago('-24 months +1 day')))[0], ($goneProof = $proof($ago('-24 months -1 day')))[0]],
    'a line in the audit log' => ['audit_log', $audit($ago('-36 months +1 day')), $audit($ago('-36 months -1 day'))],
];
[, $keptMessage, $goneMessage] = $rows['a message, counted from when it was sent'];
$keptPhoto = $photo($keptMessage); $gonePhoto = $photo($goneMessage);
$ongoing = fixture('absences', ['student_id'=>$kid, 'reason'=>'sick', 'starts_on'=>$daysBack('-1 year'), 'ends_on'=>$daysBack('+1 day'), 'created_by'=>$family]);
$keptRows = [];
foreach ($rows as $what => [$table, $kept, $gone]) {
    $keptRows[$table.$kept] = one('SELECT * FROM '.sql_name($table, 'table').' WHERE id=?', [$kept]);
    ok($keptRows[$table.$kept] !== null && $there($table, $gone), $what.': both rows are there before the cleanup, so what follows proves something');
}

prune_expired();
clearstatcache();
foreach ($rows as $what => [$table, $kept, $gone]) {
    is_same($keptRows[$table.$kept], one('SELECT * FROM '.sql_name($table, 'table').' WHERE id=?', [$kept]), $what.': a day short of its period, it stays as it was');
    ok(!$there($table, $gone), $what.': a day past it, it goes');
}
ok($there('absences', $ongoing), 'an absence that began a year ago and has not ended stays: its period runs from its end');
ok(is_file(upload_dir('message').'/'.$keptPhoto) && is_file(upload_dir('proof').'/'.$keptProof[1]), 'the photo and the proof of the rows that stay are kept');
is_same(0, (int)scalar('SELECT COUNT(*) FROM message_files WHERE message_id=?', [$goneMessage]), 'a message’s photo goes with it');
ok(!is_file(upload_dir('message').'/'.$gonePhoto) && !is_file(upload_dir('proof').'/'.$goneProof[1]),
   'and its file, and the file of the proof, the same night');

// ---------------------------------------------------------------------------
case_('A consent that holds stays however old; one a later answer replaced goes three years after [ADR 0032, ADR 0031]');
$other = make_account(['role'=>'student', 'name'=>'Otto Nachbar']);
$consent = fn(int $account, string $purpose, int $on, string $at): int => fixture('consent_log',
    ['account_id'=>$account, 'purpose'=>$purpose, 'enabled'=>$on, 'notice_version'=>'v1', 'created_at'=>$at]);
$holdsForYears   = $consent($family, 'newsletter', 1, $ago('-10 years'));
$othersLater     = $consent($other, 'newsletter', 0, $ago('-5 years'));
$replacedLong    = $consent($family, 'notifications', 1, $ago('-10 years'));
$replacedThen    = $consent($family, 'notifications', 0, $ago('-36 months -1 day'));
$holdsNow        = $consent($family, 'notifications', 1, $ago('-1 month'));
$replacedLately  = $consent($family, 'payment_notices', 1, $ago('-5 years'));
$replacing       = $consent($family, 'payment_notices', 0, $ago('-36 months +1 day'));
$parentsYes      = $consent($family, 'course_sees_picture_by_parent', 1, $ago('-6 years'));
$ownNo           = $consent($family, 'course_sees_picture', 0, $ago('-37 months'));
is_same(9, (int)scalar('SELECT COUNT(*) FROM consent_log WHERE account_id IN (?,?)', [$family, $other]), 'nine answers are there before the cleanup');
prune_expired();
ok($there('consent_log', $holdsForYears), 'an answer given ten years ago and never changed stays: it is the one that holds');
ok($there('consent_log', $othersLater), 'and another login’s later answer to the same question replaces nothing of this one’s');
ok(!$there('consent_log', $replacedLong), 'an answer replaced three years and a day ago goes');
ok($there('consent_log', $replacedThen) && $there('consent_log', $holdsNow), 'the answer that replaced it, itself replaced a month ago, stays, and so does the newest');
ok($there('consent_log', $replacedLately), 'an answer replaced a day short of three years ago stays');
ok(!$there('consent_log', $parentsYes) && $there('consent_log', $ownNo),
   'a parent’s yes to the course seeing the picture and the child’s own later no are one question: the yes goes, the no stays');

// ---------------------------------------------------------------------------
case_('A period is its setting');
set_setting('absences_months', 1);
$lastMonth = $absence($daysBack('-40 days'));
prune_expired();
ok(!$there('absences', $lastMonth), 'with one month set, an absence that ended forty days ago goes, which three months would have kept');
set_setting('absences_months', 3);
act('message_remove', ['id'=>(string)fixture('messages', ['thread_id'=>course_group_thread($course), 'sender_id'=>$family, 'body'=>'Hallo', 'created_at'=>now()])]);
is_same('Nachricht entfernt. 30 Tage lang kannst du sie an derselben Stelle wiederherstellen.', $_SESSION['flash']['message'] ?? null,
        'taking a group message down says for how long it can be put back');
history_record('students', $kid, 'update', 'Lena Hofer', ['first_name'=>'Lena'], ['first_name'=>'Lena-Marie']);
$changes = render_view('history');
ok(str_contains($changes, e('werden beim täglichen Aufräumen entfernt; das Prüfprotokoll nach 36 Monaten.')) && !str_contains($changes, 'bleibt vollständig'),
   '„Änderungen“ says the audit log goes after its 36 months, no longer that it stays complete');

// ---------------------------------------------------------------------------
case_('Accounting records are never pruned, whatever the periods say [ADR 0032, BAO § 132]');
/* Seven years from the end of the year they concern, and longer while a
   proceeding needs them: a person decides, with the student, never the
   cleanup. With every period at its shortest, ten-year-old records stay. */
foreach (array_keys($periods) as $key) set_setting($key, 1);
$decade = $ago('-10 years');
$charge = fixture('charges', ['student_id'=>$kid, 'label'=>'Beitrag Oktober 2016', 'amount_cents'=>4500, 'gross_cents'=>4500, 'discount_cents'=>0,
    'discount_note'=>'', 'due_on'=>substr($decade, 0, 10), 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>$decade]);
$payment = fixture('payments', ['charge_id'=>$charge, 'amount_cents'=>4500, 'paid_on'=>substr($decade, 0, 10), 'method'=>'Bar', 'note'=>'',
                                'confirmed_at'=>$decade, 'voided'=>0]);
$invoice = fixture('invoices', ['number'=>'2016-0001', 'year'=>2016, 'sequence'=>1, 'student_id'=>$kid, 'account_id'=>null,
    'issued_on'=>substr($decade, 0, 10), 'supplied_from'=>null, 'supplied_to'=>null, 'due_on'=>substr($decade, 0, 10), 'overdue_on'=>substr($decade, 0, 10),
    'net_cents'=>4500, 'tax_cents'=>0, 'gross_cents'=>4500, 'tax_rate'=>0, 'tax_note'=>'', 'snapshot_json'=>'{}', 'created_by'=>null, 'created_at'=>$decade]);
fixture('invoice_charges', ['invoice_id'=>$invoice, 'charge_id'=>$charge]);
prune_expired();
is_same([1, 1, 1, 1], [(int)$there('charges', $charge), (int)$there('payments', $payment), (int)$there('invoices', $invoice),
        (int)scalar('SELECT COUNT(*) FROM invoice_charges WHERE invoice_id=? AND charge_id=?', [$invoice, $charge])],
        'a charge, its payment, its invoice and the line between them, ten years old, are all still there');
ok($tick !== '' && !preg_match('/\b(DELETE\s+(\w+\s+)?FROM|TRUNCATE(\s+TABLE)?)\s+`?(charges|payments|invoices|invoice_charges)\b/i', $tick),
   'and the cleanup names no accounting table in anything that deletes');
foreach ($periods as $key => $default) set_setting($key, $default);

// ---------------------------------------------------------------------------
case_('Nothing is deleted while an update could be counting, and a prune left for later is not stamped as done [ADR 0032 §2]');
/* An update counts the guarded tables before its first migration and again
   after, and a row the cleanup deleted in between read as a loss and kept the
   portal closed: an update in another request, a newer release's files landed
   and not yet run, this release's own code finishing its work as they land, an
   import. The cleanup runs only holding the update's lock, with no update
   unfinished, the database as current as the files and no copy being imported. */
$waiting = fixture('notifications', ['account_id'=>$family, 'kind'=>'message', 'title'=>'Von vor einem Jahr', 'body'=>'',
    'link_page'=>'', 'link_params'=>'', 'read_at'=>null, 'created_at'=>$ago('-1 year')]);
set_setting('prune_last_run', '');
$elsewhere = connect();
is_same(1, (int)$elsewhere->query("SELECT GET_LOCK('badminton_crm_migrate',0)")->fetchColumn(),
        'another connection - an update in another request - holds the update’s lock');
tick_prune(); setting_cache_clear();
ok($there('notifications', $waiting), 'meanwhile the daily cleanup deletes nothing');
is_same('', (string)setting('prune_last_run'), 'and is not stamped as done, so the next tick tries again');
$elsewhere->query("SELECT RELEASE_LOCK('badminton_crm_migrate')");
$elsewhere = null;
file_put_contents(schema_unfinished_file(), '{}');
try { is_same(false, prune_expired(), 'nor while an update is unfinished'); } finally { @unlink(schema_unfinished_file()); }
@unlink(schema_stamp_file());
set_setting('schema_written_by', '0.0.1');
is_same(false, prune_expired(), 'nor while the files are newer than the database, their update not yet run');
schema_made_current();
db()->exec('CREATE TABLE IF NOT EXISTS `'.IMPORT_UNFINISHED_TABLE.'` (importing TINYINT NULL)');
try { is_same(false, prune_expired(), 'nor while a copy is being imported'); } finally { db()->exec('DROP TABLE IF EXISTS `'.IMPORT_UNFINISHED_TABLE.'`'); }
ok($there('notifications', $waiting), 'and the row past its period is still there after all four');
tick_prune(); setting_cache_clear();
ok(!$there('notifications', $waiting), 'once nobody holds the lock and nothing is under way, the next tick deletes it');
ok((string)setting('prune_last_run') !== '', 'and stamps the cleanup as done');
is_same(1, (int)scalar("SELECT IS_FREE_LOCK('badminton_crm_migrate')"), 'and it lets go of the lock again');

// ---------------------------------------------------------------------------
case_('A period set shorter says what it was, so it can be set back before the next cleanup [ADR 0032]');
$systemCard = [];
foreach (settings_in_group('system') as $key => $spec)
    $systemCard['set_'.$key] = $spec['kind'] === 'list' ? implode("\n", (array)setting($key)) : (is_bool(setting($key)) ? (setting($key) ? '1' : '') : (string)setting($key));
act('defaults_registry_save', ['group'=>'system', 'set_messages_months'=>'6', 'set_notices_days'=>'30', 'set_audit_months'=>'48'] + $systemCard);
is_same('Vorgaben gespeichert. Kürzer gestellt, vorher: Nachrichten aufbewahren (Monate) 12, Hinweise aufbewahren (Tage) 90.'
        .' Bis zum nächsten täglichen Aufräumen lässt es sich so zurückstellen.', $_SESSION['flash']['message'] ?? null,
        'the two shortened periods are named with what they were, the lengthened one is not');
is_same([6, 30, 48], [(int)setting('messages_months'), (int)setting('notices_days'), (int)setting('audit_months')], 'all three are saved');
act('defaults_registry_save', ['group'=>'system'] + $systemCard);
is_same('Vorgaben gespeichert. Kürzer gestellt, vorher: Prüfprotokoll aufbewahren (Monate) 48. Bis zum nächsten täglichen Aufräumen lässt es sich so zurückstellen.',
        $_SESSION['flash']['message'] ?? null, 'setting all three back names only the one that got shorter this time');
act('defaults_registry_save', ['group'=>'system'] + $systemCard);
is_same('Vorgaben gespeichert.', $_SESSION['flash']['message'] ?? null, 'and a save that shortens nothing says nothing more');
is_same('Hinweise aufbewahren (Tage)', setting_schema()['notices_days']['label'][0] ?? null, 'the bell’s entries are called what the bell calls them');
ok(str_contains(setting_schema()['mail_months']['hint'][0] ?? '', 'schon nach '.MAIL_BODY_KEEP_DAYS.' Tagen'), 'and the outbox’s hint names the days a mail’s words are kept by the one rule');

// ---------------------------------------------------------------------------
case_('A save that sets a period shorter deletes nothing in its own request: the cleanup after a page view waits a day [ADR 0032]');
/* The background work runs as the save's own request ends, and with the
   daily cleanup due it deleted there and then, before the message saying the
   period could still be set back had been read. */
$fortyDays = $notice($ago('-40 days'));
set_setting('notices_days', 90);
set_setting('period_last_shortened', '');
set_setting('prune_last_run', '');
set_setting('tick_last_run', '');
act('defaults_registry_save', ['group'=>'system', 'set_notices_days'=>'30'] + $systemCard);
is_same(30, (int)setting('notices_days'), 'the save sets notifications to 30 days, and one 40 days old is past them');
run_background_tasks(); setting_cache_clear();
ok((string)setting('tick_last_run') !== '', 'the background work runs as the same request ends');
ok($there('notifications', $fortyDays), 'and the cleanup, due, deletes nothing: what the message says can be set back still can be');
is_same('', (string)setting('prune_last_run'), 'nor is it stamped as done');
$almostADay = gmdate('Y-m-d H:i:s', time() - PRUNE_INTERVAL + 60);
set_setting('period_last_shortened', $almostADay);
set_setting('tick_last_run', '');
run_background_tasks(); setting_cache_clear();
ok($there('notifications', $fortyDays), 'a minute short of a day after the save, a page view’s cleanup still waits');
is_same($almostADay, (string)setting('period_last_shortened'), 'and waiting does not move the time it waits from');
set_setting('period_last_shortened', gmdate('Y-m-d H:i:s', time() - PRUNE_INTERVAL));
set_setting('tick_last_run', '');
run_background_tasks(); setting_cache_clear();
ok(!$there('notifications', $fortyDays), 'a day after it, the next page view’s cleanup deletes it');
set_setting('period_last_shortened', '');
act('defaults_registry_save', ['group'=>'system', 'set_history_months'=>'12'] + $systemCard);
is_same('Vorgaben gespeichert. Kürzer gestellt, vorher: Änderungen aufbewahren (Monate) 24. Bis zum nächsten täglichen Aufräumen lässt es sich so zurückstellen.',
        $_SESSION['flash']['message'] ?? null, 'the change log’s period, shortened alone, is named with what it was');
ok((string)setting('period_last_shortened') !== '', 'and holds the cleanup like the others: „Änderungen“ has no undo, so this is its way back');
