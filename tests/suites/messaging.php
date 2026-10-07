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
view_as($trainer, $hofer);
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
    throws(fn() => act($action, $fields), $action.' is refused, in the sentence the chat shows for it', viewing_refusal());
render_view('messages', ['id'=>(string)$group]);
ok(in_array($group, unread_thread_ids(current_user()), true), 'opening the group does not mark it read for the child');
view_as($admin, $hofer);
does_not_throw(fn() => thread_record($withOther), 'an administrator, who reads every chat between a child and staff, sees that one');
throws(fn() => thread_record($direct), 'but not the one between two families', 'nicht gefunden');
unset($_SESSION['impersonator_id']);
render_view('messages', ['id'=>(string)$group]);
ok(!in_array($group, unread_thread_ids(current_user()), true), 'the child opening it does');

case_('Looking through a child’s eyes, whom to write to is one answer, whoever is asked for');
/* The picker showed the child's requests with their words, their agreed contacts
   and every other family; and a chat opened by who it is with said „nicht
   gefunden" where the child had one the view hides, and opened empty where they
   had none - so typing addresses told the trainer whom the child writes to
   (security review S3, ADR 0022 §9). Nothing is written in that view, so every
   way in to writing gives the same page. */
$neumann = make_account(['role'=>'student', 'name'=>'Familie Neumann']);
$wagner  = make_account(['role'=>'student', 'name'=>'Familie Wagner']);
$stern   = make_account(['role'=>'student', 'name'=>'Familie Stern']);
fixture('contact_requests', ['from_account_id'=>$neumann, 'to_account_id'=>$hofer, 'state'=>'pending',
                             'message'=>'Wir sind neu im Dienstagskurs.', 'created_at'=>now()]);
fixture('contact_requests', ['from_account_id'=>$wagner, 'to_account_id'=>$hofer, 'state'=>'accepted',
                             'message'=>'', 'created_at'=>now(), 'decided_at'=>now()]);
// Berger is the agreed family with a chat ($direct), Wagner the one without, Stern
// a family the child has not asked; Neumann's request waits, with its words.
$theirs = ['Familie Neumann', 'Wir sind neu im Dienstagskurs.', 'Familie Berger', 'Familie Wagner', 'Familie Stern'];
// A refusal is an answer too, so it is compared as one rather than ending the suite.
$answer = function (array $query, string $draw = 'render_view'): string {
    try { return $draw('messages', $query); }
    catch (Throwable $e) { return get_class($e).': '.$e->getMessage(); }
};
$conversationOf = fn(string $html): string => (string)strstr($html, '<section class="card conversation">');
$writeLink = e(url('messages', ['new'=>1]));
sign_in_as($hofer);
$asChild = $answer(['new'=>'1']);
foreach ($theirs as $said)
    ok(str_contains($conversationOf($asChild), e($said)), 'the child’s own picker shows „'.$said.'“, so the lines below prove something');
$childList = $answer([]);
ok(str_contains($childList, e('1 neue Anfrage')) && substr_count($childList, $writeLink) === 3,
   'their list has the chip for the request, and „Neue Nachricht" above the list and in the empty conversation');
ok(str_contains($answer(['with'=>(string)$wagner]), 'value="message_send"'), 'a chat with the agreed family not started yet opens empty, to write in');
ok(str_contains($answer(['id'=>(string)$group, 'members'=>'1']), 'with='), 'and in the group’s member list the trainers lead to a chat');

view_as($trainer, $hofer);
$pickerViewed = $answer(['new'=>'1']);
ok(str_contains($conversationOf($pickerViewed), '<h2>'.e('Neue Nachricht').'</h2>')
   && str_contains($conversationOf($pickerViewed), e(viewing_refusal())),
   'viewed by the trainer, „Neue Nachricht" says that only the child can write');
foreach ($theirs as $said) ok(!str_contains($pickerViewed, e($said)), 'and nothing on the page shows „'.$said.'“');
ok(!str_contains($pickerViewed, 'contact_decide') && !str_contains($pickerViewed, 'contact_request') && !str_contains($pickerViewed, e('Jemand anderen fragen')),
   'nothing to agree to, and nobody to ask');
ok(!str_contains($pickerViewed, 'neue Anfrage'), 'no chip counting the child’s requests');
foreach (['new'=>['new'=>'1'], 'contacts, as older notifications link'=>['contacts'=>'1'],
          'with the family they have a chat with'=>['with'=>(string)$berger],
          'with the family they have none with'=>['with'=>(string)$wagner],
          'with a family they have not asked'=>['with'=>(string)$stern],
          'with the trainer, whose chat with them she reads'=>['with'=>(string)$trainer],
          'with nobody at all'=>['with'=>'999999']] as $what => $query)
    ok($answer($query) === $pickerViewed, '?'.http_build_query($query).', '.$what.': the same page, byte for byte');
/* The whole page as the browser gets it, frame and all. Every form carries a
   random request_id and every field a random id, so those - and nothing else -
   are set aside before comparing. The flash an earlier action in this suite
   left is shown once, on whichever page comes first, so it goes first. */
unset($_SESSION['flash']);
$settled = fn(string $html): string => (string)preg_replace(['/name="request_id" value="[0-9a-f]{64}"/', '/\bf_(\w+?)_\d{4}\b/'],
                                                            ['name="request_id" value=""', 'f_$1_'], $html);
$withChat = $settled($answer(['with'=>(string)$berger], 'render_page'));
ok(str_contains($withChat, 'impersonation-bar') && $withChat === $settled($answer(['with'=>(string)$wagner], 'render_page')),
   'and so is the whole page around it, with a chat with that family or without one');
$viewedList = $answer([]);
ok(!str_contains($viewedList, $writeLink), 'no „Neue Nachricht" above the list or in the empty conversation');
ok(str_contains($viewedList, e('Wähle eine Unterhaltung aus.')) && !str_contains($viewedList, e('schreibe eine neue Nachricht')),
   'which asks only to choose a chat');
// Read in the conversation alone: the list beside it names the trainer and
// quotes the chat's last message too, and would answer for it.
$viewedSheet = $answer(['id'=>(string)$group, 'members'=>'1']);
ok(str_contains($conversationOf($viewedSheet), '<div class="member-row">') && !str_contains($viewedSheet, 'with='),
   'the group’s member list names its people, and leads to a chat with none of them');
$readOnly = $conversationOf($answer(['id'=>(string)$thread['id']]));
ok(str_contains($readOnly, '<div class="prewrap">'.e('Welchen Schläger sollen wir kaufen?').'</div>') && !str_contains($readOnly, 'value="message_send"'),
   'a chat in the list still opens by its id, to read');

/* An administrator viewing a trainer gets the trainer's picker - search, groups,
   every child - which is the other branch of the same page. */
unset($_SESSION['impersonator_id']);
sign_in_as($trainer);
ok(str_contains($answer(['id'=>(string)$group]), 'value="message_remove"'), 'the trainer’s own group offers „Nachricht entfernen"');
view_as($admin, $trainer);
$staffViewed = $conversationOf($answer(['new'=>'1']));
ok(str_contains($staffViewed, e(viewing_refusal())) && !str_contains($staffViewed, 'filter-search')
   && !str_contains($staffViewed, e('Familie Hofer')), 'viewed by an administrator, the trainer’s picker has the same sentence, no search and no children');
ok(!str_contains($answer(['id'=>(string)$group]), 'message_remove'), 'and her group offers nothing to take down, which could only be refused');
unset($_SESSION['impersonator_id']);

case_('The help button is not pinned over a chat’s writing box, new or not');
/* Above 760px the help button is fixed to the bottom right of the window, and so
   is a chat's writing box, so over a chat the button stays at the end of the
   page - or a click meant for Send opens it. A chat with somebody new opens by
   who it is with, where the picker and the member list lead, and was missed
   (code review C4). */
sign_in_as($trainer2);
$help = function (string $html): string {
    preg_match('~<details class="(feedback[^"]*)" id="feedback">~', $html, $m);
    return $m[1] ?? 'no help button';
};
is_same(0, pair_thread($trainer2, $berger), 'the second trainer has no chat with the Bergers yet');
$fresh = render_page('messages', ['with'=>(string)$berger]);
ok(str_contains($fresh, 'class="composer"'), 'so one opened by who it is with is new, with its writing box');
is_same('feedback', $help($fresh), 'and the help button is not pinned over it');
is_same('feedback', $help(render_page('messages', ['id'=>(string)$withOther])), 'nor over a chat opened by its id');
is_same('feedback is-pinned', $help(render_page('messages')), 'with no chat open it is pinned, as on every other page');
is_same('feedback is-pinned', $help(render_page('messages', ['new'=>'1'])), 'and while choosing whom to write to');
/* Whether the box is drawn is the chat page's to say. The layout once read it
   off the address instead, and took the button out of its corner over every
   chat - the ones that can only be read as well (code review). */
$helpUnlessWriting = function (array $query) use ($help): string {
    $html = render_page('messages', $query);
    return str_contains($html, 'class="composer"') ? 'a writing box is drawn' : $help($html);
};
sign_in_as($trainer);
is_same('feedback is-pinned', $helpUnlessWriting(['id'=>(string)$desk]), 'an earlier conversation, closed to new messages, keeps it pinned');
view_as($trainer, $hofer);
is_same('feedback is-pinned', $helpUnlessWriting(['id'=>(string)$group]), 'so does a chat read through a child’s eyes');
is_same('feedback is-pinned', $helpUnlessWriting(['with'=>(string)$trainer]),
        'and a chat asked for by who it is with there, which shows the page that says only the child writes');
unset($_SESSION['impersonator_id']);

case_('Looking through a child’s eyes, the bell quotes no chat, and nothing is marked read');
/* A chat notice quotes the message, and a request to write is one too: the bell
   handed the trainer the words of the child's chat with another family that
   thread_record() refuses her (security review S1, ADR 0022 §9) - and „Alle
   gelesen" marked every notice read before the child had seen it (S5). The bell
   shows the kinds on a list of its own then, so a kind nobody has asked about
   yet stays hidden too; and a problem report only to an administrator looking,
   whoever's bell it is in - a login that was an administrator once keeps them. */
sign_in_as($berger);
act('message_send', ['thread_id'=>(string)$direct, 'body'=>'Ja, ich fahre mit dem Zug.']);
notify($hofer, 'schedule', 'Training fällt aus', 'Am Montag ist die Halle zu.', 'dashboard');
notify($hofer, 'erinnerung', 'Bald Geburtstag', 'Lena aus dem Montagskurs wird morgen zehn.', 'dashboard');
notify($hofer, 'problem', 'Jemand meldet ein Problem', 'Beim Hochladen des Fotos kommt ein Fehler.', 'settings', ['tab'=>'feedback']);
sign_in_as($hofer);
$bell = fn(string $html): string => (string)strstr((string)strstr($html, 'notification-pane'), '</details>', true);
$quotes = fn(array $notes, string $said): bool => in_array($said, array_column($notes, 'body'), true);
$unread = (int)scalar('SELECT COUNT(*) FROM notifications WHERE account_id=? AND read_at IS NULL', [$hofer]);
$chatNotes = (int)scalar("SELECT COUNT(*) FROM notifications WHERE account_id=? AND read_at IS NULL AND kind='message'", [$hofer]);
$ownBell = $bell(render_page('dashboard'));
ok($quotes(notifications_for($hofer), 'Ja, ich fahre mit dem Zug.') && str_contains($ownBell, 'Ja, ich fahre mit dem Zug.'),
   'the child’s own bell quotes the other family’s message, so the lines below prove something');
ok(str_contains($ownBell, 'value="notifications_read"'), 'and offers „Alle gelesen"');
ok($chatNotes >= 2 && $unread === $chatNotes + 3, 'and holds chat notices and the three of other kinds, all unread ('.$chatNotes.' of '.$unread.')');
view_as($trainer, $hofer);
is_same([], array_values(array_filter(notifications_for($hofer), fn($n) => $n['kind'] === 'message')),
        'viewed by the trainer, the pane holds no chat notice');
ok($quotes(notifications_for($hofer), 'Am Montag ist die Halle zu.'), 'but the other notice still');
ok(!$quotes(notifications_for($hofer), 'Lena aus dem Montagskurs wird morgen zehn.'), 'and none of a kind that is on no list of what may be shown');
ok(!$quotes(notifications_for($hofer), 'Beim Hochladen des Fotos kommt ein Fehler.'), 'nor a problem report, to a trainer');
is_same($unread - $chatNotes - 2, unread_notifications($hofer), 'and the bell counts that one only');
$viewed = render_page('dashboard');
ok(str_contains($bell($viewed), 'Training fällt aus'), 'the page draws the bell, with the notice she may see');
ok(!str_contains($bell($viewed), 'notifications_read'), 'and, with a notice unread, no „Alle gelesen", which could only be refused');
foreach (['Ja, ich fahre mit dem Zug.', 'Bitte das Trikot mitbringen.', 'Lena aus dem Montagskurs', 'Hochladen des Fotos'] as $said)
    ok(!str_contains($viewed, $said), 'and nothing on the page quotes „'.$said.'“');
throws(fn() => act('notifications_read', []), '„Alle gelesen" is refused', 'Ansicht');
$oneNote = (int)scalar('SELECT id FROM notifications WHERE account_id=? AND read_at IS NULL ORDER BY id LIMIT 1', [$hofer]);
throws(fn() => act('notifications_read', ['id'=>(string)$oneNote]), 'and so is marking one notice read', 'Ansicht');
view_as($admin, $hofer);
ok($quotes(notifications_for($hofer), 'Beim Hochladen des Fotos kommt ein Fehler.'),
   'an administrator looking sees the problem report, which she reads under Rückmeldungen anyway');
ok(!$quotes(notifications_for($hofer), 'Lena aus dem Montagskurs wird morgen zehn.'), 'but not the kind on no list');
is_same($unread - $chatNotes - 1, unread_notifications($hofer), 'and her bell counts the two she sees');
unset($_SESSION['impersonator_id']);
is_same($unread, unread_notifications($hofer), 'signed in as the child, every notice is there and still unread');

case_('An administrator looking through a trainer’s eyes writes nothing in her name');
/* may_impersonate() lets an administrator view the portal as a trainer. Every
   action of the chat would then speak as the trainer - the circular into each
   chosen child's chat with her above all (security review S4, ADR 0022 §9). */
view_as($admin, $trainer);
$_SESSION['bulk_preview'] = ['student_ids'=>[$lena], 'subject'=>'Training', 'body'=>'Bitte pünktlich sein.', 'email'=>false,
                             'accounts'=>[$hofer=>'Familie Hofer'], 'created'=>time()];
$groupMessage = (int)scalar('SELECT id FROM messages WHERE thread_id=? ORDER BY id DESC LIMIT 1', [$group]);
$written = fn(): array => [(int)scalar('SELECT COUNT(*) FROM messages'), (int)scalar('SELECT COUNT(*) FROM news'),
                           (int)scalar('SELECT COUNT(*) FROM messages WHERE removed_at IS NOT NULL')];
$writtenBefore = $written();
foreach ([['bulk_send', []], ['message_remove', ['id'=>(string)$groupMessage]],
          ['news_save', ['title'=>'Hallenzeiten', 'body'=>'Ab Montag neu.', 'published'=>'1']]] as [$action, $fields])
    throws(fn() => act($action, $fields), $action.' is refused, in the sentence the chat shows for it', viewing_refusal());
is_same($writtenBefore, $written(), 'and nothing was written: no message, no news, nothing taken down');
unset($_SESSION['impersonator_id'], $_SESSION['bulk_preview']);

case_('„Alle Direktchats" holds what the person looking may read, and nothing once she may no longer look');
/* An administrator may view the portal as another administrator, and sees her
   „Alle Direktchats" as far as she may read them herself (ADR 0022 §2, §9).
   Made a trainer while that view is open, she may not look at an administrator
   at all: the view is asked of her row on every request, and ends with the
   session it borrowed - the list is handed to nobody (security re-review N1). */
$admin2 = make_account(['role'=>'admin', 'name'=>'Zweite Admin']);
view_as($admin, $admin2);
$allDirect = fn(): array => array_map('intval', array_column(chat_list(current_user(), true), 'id'));
ok(in_array($withOther, $allDirect(), true) && in_array((int)$thread['id'], $allDirect(), true),
   'viewed by an administrator, it holds the child’s chats with each trainer');
run("UPDATE accounts SET role='trainer' WHERE id=?", [$admin]);
is_same(null, current_user(true), 'made a trainer, her next request finds nobody signed in');
ok(!isset($_SESSION['user_id']) && !isset($_SESSION['impersonator_id']), 'with neither the administrator looked at nor the view left in the session');
run("UPDATE accounts SET role='admin' WHERE id=?", [$admin]);

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
view_as($admin, $hofer);
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
/* A bubble drew its author from a column list of its own (code review). */
$writer = array_values(array_filter(thread_people((int)$thread['id']), fn($p) => (int)$p['id'] === $hofer))[0] ?? [];
$bubble = array_values(array_filter(thread_messages((int)$thread['id'])['messages'], fn($m) => (int)$m['sender_id'] === $hofer))[0] ?? [];
ok($writer !== [] && $bubble !== [], 'the child wrote in that chat, so the next line proves something');
is_same($writer, chat_person_in($bubble, 'author_'), 'and a message carries who wrote it the same way, value for value');
/* The group's page drew the name over a run from a copy of those columns made
   for it alone; it reads the author the message carries. The emoji is what a
   copy would leave out, so the trainer has one while this is looked at. */
$emojiBefore = scalar('SELECT status_emoji FROM accounts WHERE id=?', [$trainer]);
run("UPDATE accounts SET status_emoji='rocket' WHERE id=?", [$trainer]);
$author = one('SELECT '.chat_person_columns('a').' FROM accounts a WHERE a.id=?', [$trainer]);
ok(str_contains(chat_name($author), '🚀'), 'the trainer has an emoji, so the next line proves something');
ok(str_contains(render_view('messages', ['id'=>(string)$group]), '<span class="bubble-sender hue-'.chat_hue($trainer).'">'.chat_name($author).'</span>'),
   'and the group names her over her message as the chat draws her everywhere else, emoji and all, in her colour');
run('UPDATE accounts SET status_emoji=? WHERE id=?', [$emojiBefore, $trainer]);
throws(fn() => chat_person_columns('a; DROP TABLE accounts'), 'an alias is a name, never a piece of SQL', 'Refusing');

case_('A chat not started yet looks as it will once the first message makes it');
/* The empty chat drew its person from a column list of its own and decided its
   kind with a rule of its own (code review C7). Both now come from where the
   chat is made, so its header and what it says about who reads it are what the
   chat keeps once it exists - and the chat list draws the person from the same
   facts as the header. */
sign_in_as($hofer);
run("UPDATE accounts SET status_emoji='rocket', last_seen_at=? WHERE id=?", [now(), $admin]);
run("UPDATE accounts SET status_emoji='cat', last_seen_at=? WHERE id=?", [now(), $wagner]);
$headOf = fn(string $html): string => (string)strstr((string)strstr($html, '<header class="chat-head">'), '</header>', true);
$notesOf = function (string $html): array { preg_match_all('~<p class="chat-note">(.*?)</p>~', $html, $m); return $m[1]; };
foreach ([[$admin, '🚀', 'Die Administratoren des Vereins können diesen Chat lesen.'],
          [$wagner, '🐱', 'Diese Unterhaltung ist privat. Auch die Trainerin und der Administrator lesen sie nicht mit.']] as [$person, $emoji, $note]) {
    $name = (string)scalar('SELECT name FROM accounts WHERE id=?', [$person]);
    is_same(0, pair_thread($hofer, $person), 'the child has no chat with '.$name.' yet');
    $unstarted = render_view('messages', ['with'=>(string)$person]);
    ok(str_contains($headOf($unstarted), $emoji) && str_contains($headOf($unstarted), 'is-online'),
       'the empty chat’s header shows '.$name.' with their emoji and their dot, so the comparison below has something to compare');
    ok(in_array(e($note), $notesOf($unstarted), true), 'and says: „'.$note.'“');
    $opened = render_view('messages', ['id'=>(string)direct_thread(current_user(), $person)]);
    is_same($headOf($unstarted), $headOf($opened), 'once the chat exists, its header is that one');
    is_same($notesOf($unstarted), $notesOf($opened), 'and so is what it says about who reads it');
}
$rowOf = fn(string $html, int $chat): string
    => (string)strstr((string)strstr($html, 'href="'.e(url('messages', ['id'=>$chat, '#'=>'chat-end'])).'"'), '</a>', true);
$adminRow = one('SELECT * FROM accounts WHERE id=?', [$admin]);
$listed = $rowOf(render_view('messages'), pair_thread($hofer, $admin));
ok(str_contains($listed, presence_dot(current_user(), $adminRow)) && str_contains($listed, status_emoji_mark($adminRow)),
   'the chat list draws the administrator with the dot and the emoji the header shows');
// link_button() takes the place on the page as url() does, so there is one way to say it.
ok(str_contains(render_view('messages', ['id'=>(string)$group, 'members'=>'1']),
                '<a class="button secondary" href="'.e(url('messages', ['id'=>$group, '#'=>'chat-end'])).'">'.e('Zurück zur Gruppe').'</a>'),
   '„Zurück zur Gruppe" lands on the end of the chat, through url()');

case_('The chat list is one query, however many chats there are');
sign_in_as($trainer);
for ($i = 0; $i < 5; $i++) make_class();
course_groups_fill();
is_same(1, query_count(fn() => chat_list(current_user())), 'with a handful');
for ($i = 0; $i < 45; $i++) make_class();
course_groups_fill();
is_same(1, query_count(fn() => chat_list(current_user())), 'and with fifty');
is_same(1, query_count(fn() => unread_count(current_user())), 'the badge on every page is one query too');
