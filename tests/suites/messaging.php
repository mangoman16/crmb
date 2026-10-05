<?php
/**
 * Messages: who may read what, and who may write to whom (ADR 0022).
 *
 * The rules that matter most are about who reads a chat they are not in. A
 * family writing to another family is not the trainer's business; a child's
 * chat with a trainer is readable by the administrators and by no other
 * trainer; a group is its course's, read from the enrolments. "Somebody can see
 * everything" is the kind of default that gets added by accident and noticed by
 * nobody, so each is checked from both sides.
 */
$admin    = make_account(['role'=>'admin',   'name'=>'Admin']);
$trainer  = make_account(['role'=>'trainer', 'name'=>'Trainerin']);
$trainer2 = make_account(['role'=>'trainer', 'name'=>'Zweite Trainerin']);
$hofer   = make_account(['role'=>'student', 'name'=>'Familie Hofer']);
$berger  = make_account(['role'=>'student', 'name'=>'Familie Berger']);
$gruber  = make_account(['role'=>'student', 'name'=>'Familie Gruber']);

case_('Writing to the trainer needs nobody’s permission');
sign_in_as($hofer);
is_same(true, may_message(current_user(), $trainer), 'the trainer');
is_same(true, may_message(current_user(), $admin), 'and the administrator');
is_same(false, may_message(current_user(), $berger), 'another family does not, not yet');
is_same(false, may_message(current_user(), (int)$hofer), 'and nobody writes to themselves');

case_('A family writing to the trainer gets a chat with her, which administrators can read too');
throws(fn() => act('message_send', ['body'=>'An wen?']), 'a message to nobody is refused: there is no shared desk any more', 'An wen geht die Nachricht');
act('message_send', ['to'=>(string)$trainer, 'body'=>'Welchen Schläger sollen wir kaufen?']);
$thread = one('SELECT * FROM threads ORDER BY id DESC LIMIT 1');
is_same('staff_direct', $thread['kind'], 'it is a chat between a student and staff');
is_same($hofer, (int)$thread['account_id'], 'owned by the student, so it outlives a trainer’s login');
is_same((int)$thread['id'], pair_thread($hofer, $trainer), 'the one chat of that pair');
$row = array_values(array_filter(chat_list(current_user()), fn($c) => (int)$c['id'] === (int)$thread['id']))[0] ?? [];
is_same([$trainer, 'Trainerin', 0], [(int)($row['other_id'] ?? 0), thread_title($row, current_user()), (int)($row['unread'] ?? -1)],
        'in their list with the trainer as the other person, and nothing unread of their own');
ok((int)scalar("SELECT COUNT(*) FROM notifications WHERE account_id=? AND kind='message'", [$trainer]) === 1, 'the trainer is told in her bell');
sign_in_as($trainer);
$seen = thread_record((int)$thread['id']);
ok(may_write_thread(current_user(), $seen), 'the trainer reads and writes in it');
ok(in_array((int)$thread['id'], unread_thread_ids(current_user()), true), 'and it is unread for her');
sign_in_as($admin);
$seen = thread_record((int)$thread['id']);
ok(!may_write_thread(current_user(), $seen), 'an administrator reads it too, and only reads');
ok(!in_array((int)$thread['id'], array_map('intval', array_column(chat_list(current_user()), 'id')), true)
   && !in_array((int)$thread['id'], unread_thread_ids(current_user()), true), 'it is not in her own list or her unread count');
ok(in_array((int)$thread['id'], array_map('intval', array_column(chat_list(current_user(), true), 'id')), true), 'but under „Alle Direktchats“');
sign_in_as($trainer2);
throws(fn() => thread_record((int)$thread['id']), 'another trainer cannot read it', 'nicht gefunden');
throws(fn() => chat_list(current_user(), true), 'nor list everybody’s', 'Nur für Administratoren');
sign_in_as($berger);
throws(fn() => thread_record((int)$thread['id']), 'nor can another family', 'nicht gefunden');

case_('Writing to another family has to be agreed to first');
sign_in_as($hofer);
throws(fn() => direct_thread(current_user(), $berger), 'not before they agree', 'noch nicht zugestimmt');
is_same('pending', request_contact(current_user(), $berger, 'Hallo, wir sind im Montagstraining.'), 'so ask');
is_same(1, pending_contact_count($berger), 'and they are asked');
throws(fn() => request_contact(current_user(), $berger, ''), 'asking twice is refused', 'läuft schon');
is_same(false, may_message(current_user(), $berger), 'and still nothing may be written');

case_('Once they agree, both directions open');
sign_in_as($berger);
decide_contact((int)contact_requests_for($berger)[0]['id'], true);
is_same(true, may_message(current_user(), $hofer), 'the one who agreed may write');
sign_in_as($hofer);
is_same(true, may_message(current_user(), $berger), 'and so may the one who asked');
is_same(0, pending_contact_count($berger), 'nothing is waiting any more');

case_('Declining keeps it shut, and may be asked again once');
sign_in_as($hofer);
request_contact(current_user(), $gruber, 'Hallo');
sign_in_as($gruber);
decide_contact((int)contact_requests_for($gruber)[0]['id'], false);
sign_in_as($hofer);
is_same(false, may_message(current_user(), $gruber), 'still shut');
is_same('pending', request_contact(current_user(), $gruber, 'Noch einmal'), 'and asking again is allowed');
is_same(1, (int)scalar('SELECT COUNT(*) FROM contact_requests WHERE from_account_id=? AND to_account_id=?', [$hofer, $gruber]),
        'on the same row rather than a second one');

case_('Only the person who was asked may answer');
sign_in_as($berger);
$open = (int)scalar("SELECT id FROM contact_requests WHERE state='pending' ORDER BY id DESC LIMIT 1");
throws(fn() => decide_contact($open, true), 'somebody else’s request is not theirs to accept', 'gibt es nicht');

case_('A direct conversation is private, from both sides');
sign_in_as($hofer);
$direct = direct_thread(current_user(), $berger);
act('message_send', ['thread_id'=>(string)$direct, 'body'=>'Fährst du am Samstag hin?']);
does_not_throw(fn() => thread_record($direct), 'the one who wrote it');
sign_in_as($berger);
does_not_throw(fn() => thread_record($direct), 'and the one it went to');
sign_in_as($trainer);
throws(fn() => thread_record($direct), 'the trainer cannot read it', 'nicht gefunden');
sign_in_as($admin);
throws(fn() => thread_record($direct), 'nor can the administrator', 'nicht gefunden');
sign_in_as($gruber);
throws(fn() => thread_record($direct), 'nor anybody else', 'nicht gefunden');

case_('And it does not turn up in anybody else’s list or unread count');
sign_in_as($trainer);
is_same(0, count(array_filter(chat_list(current_user()), fn($t) => (int)$t['id'] === $direct)), 'not in the list');
is_same(false, in_array($direct, unread_thread_ids(current_user()), true), 'and not in the unread count');
sign_in_as($berger);
is_same(true, in_array($direct, unread_thread_ids(current_user()), true), 'while the person it was sent to does see it');

case_('One conversation per pair, not one per message');
sign_in_as($hofer);
is_same($direct, direct_thread(current_user(), $berger), 'writing again reuses it');
act('message_send', ['thread_id'=>(string)$direct, 'body'=>'Noch eine']);
is_same(1, (int)scalar("SELECT COUNT(*) FROM threads WHERE kind='direct'"), 'still one thread');

case_('A message needs to be something');
throws(fn() => act('message_send', ['thread_id'=>(string)$direct, 'body'=>'']),
       'an empty bubble is refused', 'etwas schreiben');

case_('An attachment is described by what it is, not by what it is called');
is_same('image', attachment_kind('image/jpeg'), 'a photo');
is_same('voice', attachment_kind('audio/webm'), 'a recording');
is_same('file',  attachment_kind('application/pdf'), 'and everything else');
is_same('0:42', duration_label(42), 'a short recording');
is_same('2:05', duration_label(125), 'and a longer one');
is_same('', duration_label(0), 'nothing to say about no length');

case_('The kinds a message may carry are the kinds a browser can record or pick');
$allowed = upload_types('message');
foreach (['image/jpeg','image/png','application/pdf','audio/webm','audio/mp4','audio/mpeg'] as $mime)
    ok(isset($allowed[$mime]), $mime.' is allowed');
foreach (['text/html','application/x-php','application/octet-stream'] as $mime)
    ok(!isset($allowed[$mime]), $mime.' is not');

case_('An attachment is reachable only by somebody who may read its conversation');
$messageId = (int)scalar('SELECT id FROM messages WHERE thread_id=? ORDER BY id DESC LIMIT 1', [$direct]);
fixture('message_files', ['message_id'=>$messageId, 'kind'=>'image', 'stored_name'=>str_repeat('a',32).'.jpg',
    'original_name'=>'foto.jpg', 'mime'=>'image/jpeg', 'bytes'=>1234, 'seconds'=>0, 'created_at'=>now()]);
$files = files_by_message([$messageId]);
is_same(1, count($files[$messageId]), 'the file belongs to the message');
sign_in_as($trainer);
// serve_download() looks the thread up through thread_record(), which is the
// same refusal as opening the conversation itself.
throws(fn() => thread_record($direct), 'and the conversation is still shut to the trainer', 'nicht gefunden');

case_('A staff member may write to a family without being asked');
sign_in_as($trainer);
is_same(true, may_message(current_user(), $hofer), 'she may');
throws(fn() => request_contact(current_user(), $hofer, ''), 'so she has nothing to ask for', 'ohnehin');

case_('Deleting an account takes their side of a private conversation with it');
// threads.account_id cascades, so the conversation goes when the family who
// started it does. That is the right answer for a family asking to be forgotten,
// and it is worth being explicit about: the other side loses the thread too.
$leaving = make_account(['role'=>'student', 'name'=>'Familie Zieht Weg']);
sign_in_as($leaving);
request_contact(current_user(), $gruber, 'Hallo');
sign_in_as($gruber);
decide_contact((int)contact_requests_for($gruber)[0]['id'], true);
sign_in_as($leaving);
$goodbye = direct_thread(current_user(), $gruber);
act('message_send', ['thread_id'=>(string)$goodbye, 'body'=>'Wir ziehen weg.']);
is_same(1, (int)scalar('SELECT COUNT(*) FROM threads WHERE id=?', [$goodbye]), 'the conversation exists');
run('DELETE FROM accounts WHERE id=?', [$leaving]);
is_same(0, (int)scalar('SELECT COUNT(*) FROM threads WHERE id=?', [$goodbye]), 'and goes when the account does');
is_same(0, (int)scalar('SELECT COUNT(*) FROM messages WHERE thread_id=?', [$goodbye]), 'messages with it');
is_same(0, (int)scalar('SELECT COUNT(*) FROM thread_participants WHERE thread_id=?', [$goodbye]),
        'and nobody is left listed as a participant in something that no longer exists');

case_('Every course has a group, and its members are whoever is enrolled now');
sign_in_as($trainer);
$course = make_class(['name'=>'Montagsgruppe']);
$group = course_group_thread($course);
is_same($group, course_group_thread($course), 'asking again gives the same group');
is_same(1, (int)scalar('SELECT COUNT(*) FROM threads WHERE class_id=?', [$course]), 'one group per course');
$lena = make_student(['first_name'=>'Lena', 'last_name'=>'Hofer', 'account_id'=>$hofer, 'email'=>'lena@beispiel.test']);
$tom  = make_student(['first_name'=>'Tom', 'last_name'=>'Berger', 'account_id'=>$berger, 'email'=>'tom@beispiel.test']);
make_enrolment($course, $lena);
sign_in_as($hofer);
does_not_throw(fn() => thread_record($group), 'an enrolled child reads it');
ok(may_write_thread(current_user(), thread_record($group)), 'and writes in it');
$mails = (int)scalar('SELECT COUNT(*) FROM mail_jobs');
act('message_send', ['thread_id'=>(string)$group, 'body'=>'Hallo Gruppe!']);
is_same(0, (int)scalar('SELECT COUNT(*) FROM thread_participants WHERE thread_id=?', [$group]), 'nobody is stored as a member: the enrolments are the list');
is_same($mails, (int)scalar('SELECT COUNT(*) FROM mail_jobs'), 'a group message mails nobody');
is_same(0, (int)scalar("SELECT COUNT(*) FROM notifications WHERE kind='message' AND body='Hallo Gruppe!'"), 'and rings no bell');
sign_in_as($berger);
throws(fn() => thread_record($group), 'a child in another course cannot read it', 'nicht gefunden');
make_enrolment($course, $tom);
does_not_throw(fn() => thread_record($group), 'until they join the course - and then read what came before');
ok(in_array($group, unread_thread_ids(current_user()), true), 'with it unread');
run('UPDATE class_students SET left_on=? WHERE class_id=? AND student_id=?', ['2026-01-01', $course, $tom]);
throws(fn() => thread_record($group), 'a child who left the course loses the group', 'nicht gefunden');
ok(!in_array($group, array_map('intval', array_column(chat_list(current_user()), 'id')), true), 'from their list too');
sign_in_as($trainer2);
ok(may_write_thread(current_user(), thread_record($group)), 'every member of staff reads and writes in every group');
run('UPDATE classes SET archived=1 WHERE id=?', [$course]);
does_not_throw(fn() => thread_record($group), 'an archived course’s group stays readable for staff');
throws(fn() => act('message_send', ['thread_id'=>(string)$group, 'body'=>'Noch da?']), 'but takes no more messages', 'archiviert');
sign_in_as($hofer);
throws(fn() => thread_record($group), 'and is gone for its children', 'nicht gefunden');
run('UPDATE classes SET archived=0 WHERE id=?', [$course]);

case_('A course gets its group as it is made, and keeps it while it has messages');
sign_in_as($trainer);
act('class_save', ['name'=>'Neuer Kurs', 'capacity'=>'0', 'sort_order'=>'0']);
$made = (int)scalar("SELECT id FROM classes WHERE name='Neuer Kurs'");
is_same(1, (int)scalar("SELECT COUNT(*) FROM threads WHERE class_id=? AND kind='course'", [$made]), 'saving a new course makes its group');
// „Kopieren" makes a course too, and the copy is a course of its own (ADR 0022 §3).
$copied = (int)(act('record_duplicate', ['table'=>'classes', 'id'=>(string)$course])[1]['id'] ?? 0);
ok($copied > 0 && $copied !== $course, 'copying a course makes a new one');
is_same(1, (int)scalar("SELECT COUNT(*) FROM threads WHERE class_id=? AND kind='course'", [$copied]),
        'and the copy has exactly one group of its own, straight away');
$old = make_class(['name'=>'Von früher']);
ok(course_groups_fill() >= 1 && (int)scalar('SELECT COUNT(*) FROM threads WHERE class_id=?', [$old]) === 1, 'a course from before gets its group with the next update');
is_same(0, course_groups_fill(), 'and the next update makes none');
throws(fn() => act('class_delete', ['id'=>(string)$course, 'confirmation'=>'Montagsgruppe']), 'a course whose group has messages is not deleted', 'Archiviere');
act('class_delete', ['id'=>(string)$made, 'confirmation'=>'Neuer Kurs']);
is_same(0, (int)scalar('SELECT COUNT(*) FROM threads WHERE class_id=?', [$made]), 'an empty group goes with its course');

case_('Staff take a group message down and put it back; a family’s chat is theirs');
$said = (int)scalar('SELECT id FROM messages WHERE thread_id=? ORDER BY id LIMIT 1', [$group]);
fixture('message_files', ['message_id'=>$said, 'kind'=>'image', 'stored_name'=>str_repeat('b',32).'.jpg',
    'original_name'=>'foto.jpg', 'mime'=>'image/jpeg', 'bytes'=>1234, 'seconds'=>0, 'created_at'=>now()]);
sign_in_as($hofer);
throws(fn() => act('message_remove', ['id'=>(string)$said]), 'a child cannot remove a message', 'Kein Zugriff');
sign_in_as($trainer);
is_same(['messages', ['id'=>$group, '#'=>'m'.$said]], act('message_remove', ['id'=>(string)$said]), 'a trainer can, and lands on the message');
$shown = array_values(array_filter(thread_messages($group)['messages'], fn($m) => (int)$m['id'] === $said))[0];
is_same([true, '', []], [$shown['removed'], $shown['body'], $shown['files']], 'it comes back without its words or its photo');
is_same('Hallo Gruppe!', scalar('SELECT body FROM messages WHERE id=?', [$said]), 'which are kept, so it can be put back');
is_same(null, one('SELECT f.id FROM message_files f JOIN messages m ON m.id=f.message_id WHERE m.id=? AND m.removed_at IS NULL', [$said]),
        'and its photo is served to nobody (the download asks exactly this)');
is_same(1, (int)scalar("SELECT COUNT(*) FROM audit_log WHERE action='message.removed' AND entity_id=?", [$said]), 'who did it is in the audit log');
$listed = array_values(array_filter(chat_list(current_user()), fn($c) => (int)$c['id'] === $group))[0];
is_same($said, (int)$listed['last_id'], 'it is the last message in the group, so the next line proves something');
is_same([null, null], [$listed['last_body'], $listed['last_file']], 'and the chat list quotes neither its words nor its photo');
act('message_remove', ['id'=>(string)$said, 'restore'=>'1']);
is_same(false, array_values(array_filter(thread_messages($group)['messages'], fn($m) => (int)$m['id'] === $said))[0]['removed'], 'restoring brings it back');
$private = (int)scalar('SELECT id FROM messages WHERE thread_id=? LIMIT 1', [$direct]);
throws(fn() => act('message_remove', ['id'=>(string)$private]), 'a message in a family’s private chat is not staff’s to remove', 'Kursgruppen');

case_('The old shared desk is kept to read and closed to new messages');
$desk = make_thread([$gruber], ['subject'=>'Frage von früher']);
sign_in_as($trainer);
$seen = thread_record($desk);
ok(!may_write_thread(current_user(), $seen), 'staff read it and cannot write in it');
throws(fn() => act('message_send', ['thread_id'=>(string)$desk, 'body'=>'Antwort']), 'a message into it is refused', 'nicht mehr geschrieben');
sign_in_as($gruber);
does_not_throw(fn() => thread_record($desk), 'the family reads it too');
is_same('Trainerteam', thread_title(thread_record($desk), current_user()), 'titled with who they talked to');

case_('Looking through a child’s eyes shows only what both may read, and sends nothing');
/* A trainer viewing the portal as a child sees it does not get to read, that
   way, the child's chat with another trainer or with another family - nor to
   write to a whole course as the child (security review, ADR 0022). */
sign_in_as($trainer2);
$withOther = direct_thread(current_user(), $hofer);
act('message_send', ['thread_id'=>(string)$withOther, 'body'=>'Bitte das Trikot mitbringen.']);
sign_in_as($trainer);
act('message_send', ['thread_id'=>(string)$group, 'body'=>'Morgen in der großen Halle.']);
sign_in_as($hofer);
$_SESSION['impersonator_id'] = $trainer;
does_not_throw(fn() => thread_record($group), 'the course group is shown');
does_not_throw(fn() => thread_record((int)$thread['id']), 'and the child’s chat with her');
throws(fn() => thread_record($withOther), 'the child’s chat with another trainer is not', 'nicht gefunden');
throws(fn() => thread_record($direct), 'nor the chat with another family', 'nicht gefunden');
$listed = array_map('intval', array_column(chat_list(current_user()), 'id'));
ok(in_array($group, $listed, true) && !array_intersect([$withOther, $direct], $listed), 'the list leaves out what she may not read');
$unread = unread_thread_ids(current_user());
ok(in_array($group, $unread, true) && !array_intersect([$withOther, $direct], $unread), 'and so does the unread count');
ok(!may_write_thread(current_user(), thread_record($group)), 'nowhere is offered to write');
foreach ([['message_send', ['thread_id'=>(string)$group, 'body'=>'Hallo']], ['message_send', ['to'=>(string)$admin, 'body'=>'Hallo']],
          ['contact_request', ['to'=>(string)$gruber]], ['contact_decide', ['id'=>'1', 'accept'=>'1']]] as [$action, $fields])
    throws(fn() => act($action, $fields), $action.' is refused', 'Ansicht');
render_view('messages', ['id'=>(string)$group]);
ok(in_array($group, unread_thread_ids(current_user()), true), 'opening the group does not mark it read for the child');
$_SESSION['impersonator_id'] = $admin;
does_not_throw(fn() => thread_record($withOther), 'an administrator, who reads every chat between a child and staff, sees that one');
throws(fn() => thread_record($direct), 'but not the one between two families', 'nicht gefunden');
unset($_SESSION['impersonator_id']);
render_view('messages', ['id'=>(string)$group]);
ok(!in_array($group, unread_thread_ids(current_user()), true), 'the child opening it does');

case_('Looking through a child’s eyes, the bell quotes no chat, and nothing is marked read');
/* A chat notice quotes the message, and a request to write is one too: the bell
   handed the trainer the words of the child's chat with another family that
   thread_record() refuses her (security review S1, ADR 0022 §9) - and „Alle
   gelesen" marked every notice read before the child had seen it (S5). */
sign_in_as($berger);
act('message_send', ['thread_id'=>(string)$direct, 'body'=>'Ja, ich fahre mit dem Zug.']);
notify($hofer, 'schedule', 'Training fällt aus', 'Am Montag ist die Halle zu.', 'dashboard');
sign_in_as($hofer);
$bell = fn(string $html): string => (string)strstr((string)strstr($html, 'notification-pane'), '</details>', true);
$quotes = fn(array $notes, string $said): bool => in_array($said, array_column($notes, 'body'), true);
$unread = (int)scalar('SELECT COUNT(*) FROM notifications WHERE account_id=? AND read_at IS NULL', [$hofer]);
$chatNotes = (int)scalar("SELECT COUNT(*) FROM notifications WHERE account_id=? AND read_at IS NULL AND kind='message'", [$hofer]);
ok($quotes(notifications_for($hofer), 'Ja, ich fahre mit dem Zug.') && str_contains($bell(render_page('dashboard')), 'Ja, ich fahre mit dem Zug.'),
   'the child’s own bell quotes the other family’s message, so the lines below prove something');
ok($chatNotes >= 2 && $unread > $chatNotes, 'and holds chat notices and one of another kind, all unread ('.$chatNotes.' of '.$unread.')');
$_SESSION['impersonator_id'] = $trainer;
is_same([], array_values(array_filter(notifications_for($hofer), fn($n) => $n['kind'] === 'message')),
        'viewed by the trainer, the pane holds no chat notice');
ok($quotes(notifications_for($hofer), 'Am Montag ist die Halle zu.'), 'but the other notice still');
is_same($unread - $chatNotes, unread_notifications($hofer), 'and the bell counts that one only');
$viewed = render_page('dashboard');
ok(str_contains($bell($viewed), 'Training fällt aus'), 'the page draws the bell, with the notice she may see');
foreach (['Ja, ich fahre mit dem Zug.', 'Bitte das Trikot mitbringen.'] as $said)
    ok(!str_contains($viewed, $said), 'and nothing on the page quotes „'.$said.'“');
throws(fn() => act('notifications_read', []), '„Alle gelesen" is refused', 'Ansicht');
$oneNote = (int)scalar('SELECT id FROM notifications WHERE account_id=? AND read_at IS NULL ORDER BY id LIMIT 1', [$hofer]);
throws(fn() => act('notifications_read', ['id'=>(string)$oneNote]), 'and so is marking one notice read', 'Ansicht');
unset($_SESSION['impersonator_id']);
is_same($unread, unread_notifications($hofer), 'signed in as the child, every notice is there and still unread');

case_('An administrator looking through a trainer’s eyes writes nothing in her name');
/* may_impersonate() lets an administrator view the portal as a trainer. Every
   action of the chat would then speak as the trainer - the circular into each
   chosen child's chat with her above all (security review S4, ADR 0022 §9). */
sign_in_as($trainer);
$_SESSION['impersonator_id'] = $admin;
$_SESSION['bulk_preview'] = ['student_ids'=>[$lena], 'subject'=>'Training', 'body'=>'Bitte pünktlich sein.', 'email'=>false,
                             'accounts'=>[$hofer=>'Familie Hofer'], 'created'=>time()];
$groupMessage = (int)scalar('SELECT id FROM messages WHERE thread_id=? ORDER BY id DESC LIMIT 1', [$group]);
$written = fn(): array => [(int)scalar('SELECT COUNT(*) FROM messages'), (int)scalar('SELECT COUNT(*) FROM news'),
                           (int)scalar('SELECT COUNT(*) FROM messages WHERE removed_at IS NOT NULL')];
$writtenBefore = $written();
foreach ([['bulk_send', []], ['message_remove', ['id'=>(string)$groupMessage]],
          ['news_save', ['title'=>'Hallenzeiten', 'body'=>'Ab Montag neu.', 'published'=>'1']]] as [$action, $fields])
    throws(fn() => act($action, $fields), $action.' is refused', 'Ansicht');
is_same($writtenBefore, $written(), 'and nothing was written: no message, no news, nothing taken down');
unset($_SESSION['impersonator_id'], $_SESSION['bulk_preview']);

case_('„Alle Direktchats" holds what the person looking may read, like every other list');
/* An administrator may view the portal as another administrator, and sees her
   „Alle Direktchats" as far as she may read them herself. Made a trainer while
   that view is open, she may no longer read a child's chat with a trainer, and
   the list must not hand it to her (ADR 0022 §2, §9). */
$admin2 = make_account(['role'=>'admin', 'name'=>'Zweite Admin']);
sign_in_as($admin2);
$_SESSION['impersonator_id'] = $admin;
$allDirect = fn(): array => array_map('intval', array_column(chat_list(current_user(), true), 'id'));
ok(in_array($withOther, $allDirect(), true) && in_array((int)$thread['id'], $allDirect(), true),
   'viewed by an administrator, it holds the child’s chats with each trainer');
run("UPDATE accounts SET role='trainer' WHERE id=?", [$admin]);
is_same([], array_values(array_intersect([$withOther, (int)$thread['id']], $allDirect())),
        'viewed by her once she is a trainer, neither of them');
run("UPDATE accounts SET role='admin' WHERE id=?", [$admin]);
unset($_SESSION['impersonator_id']);

case_('A child sees who reads the group, and only a number for the rest');
sign_in_as($hofer);
$mia = make_student(['first_name'=>'Mia', 'last_name'=>'Ohnezugang', 'account_id'=>null]);
make_enrolment($course, $mia);
$sheet = render_view('messages', ['id'=>(string)$group, 'members'=>'1']);
ok(str_contains($sheet, 'Familie Hofer') && !str_contains($sheet, 'Ohnezugang'), 'a classmate whose family is not in the portal is not named to a child');
ok(str_contains($sheet, 'Dazu 1 Person ohne Zugang zum Portal.') && str_contains($sheet, 'Im Kurs (2)'), 'only counted');
sign_in_as($trainer);
$sheet = render_view('messages', ['id'=>(string)$group, 'members'=>'1']);
ok(str_contains($sheet, 'Mia Ohnezugang') && str_contains($sheet, 'Noch kein Zugang'), 'staff see every child enrolled, and who has no login yet');
ok(!str_contains($sheet, 'ohne Zugang zum Portal'), 'with nobody left over to count');

case_('Everybody may show an emoji from the list, and only from the list');
sign_in_as($hofer);
act('status_emoji_save', ['status_emoji'=>'fox', 'return_page'=>'dashboard']);
is_same('fox', scalar('SELECT status_emoji FROM accounts WHERE id=?', [$hofer]), 'a key from the list is saved');
is_same(['🦊', 'Fuchs'], status_emoji(one('SELECT * FROM accounts WHERE id=?', [$hofer])), 'and read back as the emoji');
throws(fn() => act('status_emoji_save', ['status_emoji'=>'🍆']), 'anything not on the list is refused', 'Ungültige Auswahl');
act('status_emoji_save', ['status_emoji'=>'']);
is_same('', scalar('SELECT status_emoji FROM accounts WHERE id=?', [$hofer]), '„Keins" clears it');
is_same(null, status_emoji(['status_emoji'=>'gibts-nicht']), 'a stored key that is not on the list shows nothing');
$_SESSION['impersonator_id'] = $admin;
throws(fn() => act('status_emoji_save', ['status_emoji'=>'cat']), 'nobody changes it while looking through somebody else’s eyes', 'Ansicht');
unset($_SESSION['impersonator_id']);

case_('Which kind of chat a pair makes is decided in one place');
$as = fn(int $id): array => one('SELECT * FROM accounts WHERE id=?', [$id]);
is_same('staff_direct', pair_kind($as($hofer), $as($trainer)), 'a student and a trainer: the administrators can read it');
is_same('staff_direct', pair_kind($as($admin), $as($berger)), 'a student and an administrator, either way round');
is_same('direct', pair_kind($as($hofer), $as($berger)), 'two students: nobody else reads it');
is_same('direct', pair_kind($as($trainer), $as($admin)), 'two members of staff: nor that');
is_same(pair_kind($as($hofer), $as($trainer)), (string)scalar('SELECT kind FROM threads WHERE id=?', [(int)$thread['id']]),
        'and it is the kind direct_thread() gave their chat');

case_('The chat list and a chat’s header draw a person from the same facts');
sign_in_as($hofer);
$row = array_values(array_filter(chat_list(current_user()), fn($c) => (int)$c['id'] === (int)$thread['id']))[0] ?? [];
$header = array_values(array_filter(thread_people((int)$thread['id']), fn($p) => (int)$p['id'] === $trainer))[0] ?? [];
is_same(CHAT_PERSON_COLUMNS, array_keys($header), 'the header reads the one list of what the chat shows of a person');
is_same($header, chat_person_in($row, 'other_'), 'and the list’s row carries the same person, value for value');
throws(fn() => chat_person_columns('a; DROP TABLE accounts'), 'an alias is a name, never a piece of SQL', 'Refusing');

case_('The chat list is one query, however many chats there are');
sign_in_as($trainer);
for ($i = 0; $i < 5; $i++) make_class();
course_groups_fill();
is_same(1, query_count(fn() => chat_list(current_user())), 'with a handful');
for ($i = 0; $i < 45; $i++) make_class();
course_groups_fill();
is_same(1, query_count(fn() => chat_list(current_user())), 'and with fifty');
is_same(1, query_count(fn() => unread_count(current_user())), 'the badge on every page is one query too');
