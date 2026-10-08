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
is_same(false, may_message(current_user(), $berger), 'another family not: chats between students have closed [ADR 0022 §11.3]');
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
ok(in_array((int)$thread['id'], array_map('intval', array_column(chat_list(current_user(), true), 'id')), true), 'but under „Alle Einzelchats“');
sign_in_as($trainer2);
throws(fn() => thread_record((int)$thread['id']), 'another trainer cannot read it', 'nicht gefunden');
throws(fn() => chat_list(current_user(), true), 'nor list everybody’s', 'Nur für Administratoren');
sign_in_as($berger);
throws(fn() => thread_record((int)$thread['id']), 'nor can another family', 'nicht gefunden');

case_('No new chat between two families, and one from before stays to read, closed [ADR 0022 §11.3]');
/* Chats between students have closed: nobody starts one, by asking or by
   writing, and the requests to write went with the rest of the chat's extras.
   One from before stays for the two to read, and nobody writes in it. */
sign_in_as($hofer);
throws(fn() => direct_thread(current_user(), $berger), 'a chat with another family is not started', 'kein Chat beginnen');
throws(fn() => act('message_send', ['to'=>(string)$berger, 'body'=>'Hallo']), 'nor by writing to them', 'kein Chat beginnen');
foreach ([['contact_request', ['to'=>(string)$berger, 'message'=>'Hallo']], ['contact_decide', ['id'=>'1', 'accept'=>'1']]] as [$action, $fields])
    throws(fn() => act($action, $fields), $action.', asking or answering, is no action any more', 'Unbekannte Aktion');
is_same(0, (int)scalar("SELECT COUNT(*) FROM threads WHERE kind='direct'"), 'and nothing is written');
$direct = make_thread([$hofer, $berger], ['kind'=>'direct', 'subject'=>'']);
fixture('messages', ['thread_id'=>$direct, 'sender_id'=>$hofer, 'body'=>'Fährst du am Samstag hin?', 'created_at'=>now()]);
foreach ([[$hofer, $berger], [$berger, $hofer]] as [$either, $other]) {
    sign_in_as($either);
    $name = current_user()['name'];
    ok(!may_write_thread(current_user(), thread_record($direct)), $name.' reads their chat from before, and may not write in it');
    throws(fn() => act('message_send', ['thread_id'=>(string)$direct, 'body'=>'Noch da?']), 'a message into it is refused', 'nicht mehr geschrieben');
    $byName = render_view('messages', ['with'=>(string)$other]);
    ok(str_contains($byName, e('Fährst du am Samstag hin?')) && !str_contains($byName, 'value="message_send"')
       && str_contains($byName, e('Chats zwischen Schülern sind geschlossen.')),
       'asked for by who it is with, it opens that chat, to read, and says why there is no writing box');
}
is_same(1, (int)scalar('SELECT COUNT(*) FROM messages WHERE thread_id=?', [$direct]), 'nothing was added to it');
sign_in_as($trainer);
$staffChat = direct_thread(current_user(), $trainer2);
ok(may_write_thread(current_user(), thread_record($staffChat)), 'between two members of staff a direct chat is open, to both');
sign_in_as($trainer2);
ok(may_write_thread(current_user(), thread_record($staffChat)), 'either of them');

case_('A chat between two families from before is read by the two and the administrators, nobody else [ADR 0022 §11.1]');
sign_in_as($hofer);
does_not_throw(fn() => thread_record($direct), 'the one who wrote it');
sign_in_as($berger);
does_not_throw(fn() => thread_record($direct), 'and the one it went to');
sign_in_as($trainer);
throws(fn() => thread_record($direct), 'the trainer cannot read it', 'nicht gefunden');
sign_in_as($admin);
does_not_throw(fn() => thread_record($direct), 'the administrator can, as she reads every chat');
sign_in_as($gruber);
throws(fn() => thread_record($direct), 'nor anybody else', 'nicht gefunden');

case_('And it does not turn up in anybody else’s list or unread count');
foreach ([$trainer => 'the trainer’s', $admin => 'the administrator’s, who reads it'] as $who => $whose) {
    sign_in_as($who);
    is_same(0, count(array_filter(chat_list(current_user()), fn($t) => (int)$t['id'] === $direct)), 'not in '.$whose.' list');
    is_same(false, in_array($direct, unread_thread_ids(current_user()), true), 'and not in '.$whose.' unread count');
}
sign_in_as($berger);
run('DELETE FROM thread_reads WHERE thread_id=?', [$direct]);   // as before the two opened it above
is_same(true, in_array($direct, unread_thread_ids(current_user()), true), 'while the person it was sent to does see it');

case_('One conversation per pair, not one per message');
sign_in_as($hofer);
is_same((int)$thread['id'], direct_thread(current_user(), $trainer), 'writing to the trainer again reuses their chat');
act('message_send', ['to'=>(string)$trainer, 'body'=>'Noch eine Frage']);
is_same(1, (int)scalar("SELECT COUNT(*) FROM threads WHERE kind='staff_direct' AND account_id=?", [$hofer]), 'still one chat');

case_('A message needs to be something');
throws(fn() => act('message_send', ['thread_id'=>(string)$thread['id'], 'body'=>'']),
       'an empty bubble is refused', 'etwas schreiben');

case_('A voice note from before says how long it is');
// Nobody sends one any more (ADR 0022 §11.4); the ones sent before are shown as they are.
is_same('0:42', duration_label(42), 'a short recording');
is_same('2:05', duration_label(125), 'and a longer one');
is_same('', duration_label(0), 'nothing to say about no length');

case_('A message carries a photo: a child’s a JPEG from the camera, staff’s from the gallery too [ADR 0022 §11.4]');
$as = fn(int $id): array => one('SELECT * FROM accounts WHERE id=?', [$id]);
is_same(['image/jpeg'=>'jpg'], message_upload_types($as($hofer)), 'a child sends a JPEG, what a phone’s camera hands over');
is_same(['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'], message_upload_types($as($trainer)),
        'a trainer a JPEG, a PNG or a WebP');
is_same(message_upload_types($as($trainer)), message_upload_types($as($admin)), 'and so does an administrator');
is_same(['image/jpeg'=>'jpg'], message_upload_types(null), 'nobody signed in is allowed no more than a child');
is_same([true, false, false, true], [chat_photo_from_camera($as($hofer)), chat_photo_from_camera($as($trainer)),
        chat_photo_from_camera($as($admin)), chat_photo_from_camera(null)],
        'one rule says whose photo comes from the camera: a child’s and nobody signed in’s, never staff’s');
foreach ([$hofer => 'the child', $trainer => 'the trainer'] as $who => $whom) {
    sign_in_as($who);
    is_same(message_upload_types(current_user()), upload_types('message'), 'store_upload() checks '.$whom.'’s upload against that list');
    foreach (['audio/webm','audio/mp4','audio/mpeg','application/pdf','image/gif','image/svg+xml','text/html','application/x-php','application/octet-stream'] as $mime)
        ok(!isset(upload_types('message')[$mime]), $mime.' is not sent by '.$whom);
}
sign_in_as($hofer);
$composer = (string)strstr(render_view('messages', ['id'=>(string)$thread['id']]), 'class="composer"');
ok(str_contains($composer, 'accept="image/jpeg" capture="environment"'), 'the child’s writing box asks the camera for a JPEG');
ok(!str_contains($composer, 'audio') && !str_contains($composer, 'attachment_seconds'), 'and offers no voice note');
sign_in_as($trainer);
$composer = (string)strstr(render_view('messages', ['id'=>(string)$thread['id']]), 'class="composer"');
ok(str_contains($composer, 'accept="image/jpeg,image/png,image/webp">'), 'the trainer’s offers her pictures, without asking for the camera');

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

case_('Deleting a family’s account takes their chats with it');
// threads.account_id cascades, and a chat with staff is the student's
// (direct_thread()), so it goes when the family does. That is the right answer
// for a family asking to be forgotten, and it is worth being explicit about:
// the trainer loses the thread too.
$leaving = make_account(['role'=>'student', 'name'=>'Familie Zieht Weg']);
sign_in_as($leaving);
$goodbye = direct_thread(current_user(), $trainer2);
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
throws(fn() => act('message_remove', ['id'=>(string)$private]), 'a message in a chat between two families is not staff’s to remove', 'Kursgruppen');

case_('The old shared desk is kept to read and closed to new messages');
$desk = make_thread([$gruber], ['subject'=>'Frage von früher']);
sign_in_as($trainer);
$seen = thread_record($desk);
ok(!may_write_thread(current_user(), $seen), 'staff read it and cannot write in it');
throws(fn() => act('message_send', ['thread_id'=>(string)$desk, 'body'=>'Antwort']), 'a message into it is refused', 'nicht mehr geschrieben');
$deskPage = render_view('messages', ['id'=>(string)$desk]);
ok(str_contains($deskPage, e('Diese frühere Unterhaltung ist geschlossen.')) && !str_contains($deskPage, e('Hier schreibt ihr zu zweit.'))
   && !str_contains($deskPage, e(only_the_two_write())), 'it says it is closed, and nothing about two who write in it');
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
foreach ([['message_send', ['thread_id'=>(string)$group, 'body'=>'Hallo']], ['message_send', ['to'=>(string)$admin, 'body'=>'Hallo']]] as [$action, $fields])
    throws(fn() => act($action, $fields), $action.' is refused, in the sentence the chat shows for it', viewing_refusal());
render_view('messages', ['id'=>(string)$group]);
ok(in_array($group, unread_thread_ids(current_user()), true), 'opening the group does not mark it read for the child');
view_as($admin, $hofer);
does_not_throw(fn() => thread_record($withOther), 'an administrator, who reads every chat, sees that one');
does_not_throw(fn() => thread_record($direct), 'and the one between two families: the view narrows by both rules, and both read it [ADR 0022 §11.1]');
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
$wagner  = make_account(['role'=>'student', 'name'=>'Familie Wagner']);
$stern   = make_account(['role'=>'student', 'name'=>'Familie Stern']);
// Berger is the family with a chat from before ($direct), Wagner and Stern
// families without one: none of the three is offered (ADR 0022 §11.3).
$families = ['Familie Berger', 'Familie Wagner', 'Familie Stern'];
// A refusal is an answer too, so it is compared as one rather than ending the suite.
$answer = function (array $query, string $draw = 'render_view'): string {
    try { return $draw('messages', $query); }
    catch (Throwable $e) { return get_class($e).': '.$e->getMessage(); }
};
$conversationOf = fn(string $html): string => (string)strstr($html, '<section class="card conversation">');
$writeLink = e(url('messages', ['new'=>1]));
sign_in_as($hofer);
$asChild = $answer(['new'=>'1']);
ok(str_contains($conversationOf($asChild), e('Zweite Trainerin')), 'the child’s own picker offers the trainers, so the lines below prove something');
foreach ($families as $family)
    ok(!str_contains($conversationOf($asChild), e($family)), 'while no other family is offered to write to: not „'.$family.'“');
$childList = $answer([]);
is_same(2, substr_count($childList, $writeLink), 'their list has „Neue Nachricht" above the list and in the empty conversation');
ok(str_contains($answer(['with'=>(string)$trainer2]), 'value="message_send"'), 'a chat with a trainer not started yet opens empty, to write in');
ok(str_contains($answer(['with'=>(string)$wagner]), 'NotFound: '), 'one with a family they have none with is not there to start');
ok(str_contains($answer(['id'=>(string)$group, 'members'=>'1']), 'with='), 'and in the group’s member list the trainers lead to a chat');

view_as($trainer, $hofer);
$pickerViewed = $answer(['new'=>'1']);
ok(str_contains($conversationOf($pickerViewed), '<h2>'.e('Neue Nachricht').'</h2>')
   && str_contains($conversationOf($pickerViewed), e(viewing_refusal())),
   'viewed by the trainer, „Neue Nachricht" says that only the child can write');
foreach ([...$families, 'Zweite Trainerin'] as $said) ok(!str_contains($pickerViewed, e($said)), 'and nothing on the page shows „'.$said.'“');
foreach (['new'=>['new'=>'1'],
          'with the family they have a chat with'=>['with'=>(string)$berger],
          'with the family they have none with'=>['with'=>(string)$wagner],
          'with another family they have none with'=>['with'=>(string)$stern],
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
/* A chat notice quotes the message: the bell handed the trainer the words of a
   chat of the child's that thread_record() refuses her - with another family
   then, with another trainer now (security review S1, ADR 0022 §9) - and „Alle
   gelesen" marked every notice read before the child had seen it (S5). The bell
   shows the kinds on a list of its own then, so a kind nobody has asked about
   yet stays hidden too; and a problem report only to an administrator looking,
   whoever's bell it is in - a login that was an administrator once keeps them. */
sign_in_as($trainer2);
act('message_send', ['thread_id'=>(string)$withOther, 'body'=>'Ja, ich fahre mit dem Zug.']);
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
   'the child’s own bell quotes the other trainer’s message, so the lines below prove something');
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
   action of the chat would then speak as the trainer (security review S4,
   ADR 0022 §9). */
view_as($admin, $trainer);
$groupMessage = (int)scalar('SELECT id FROM messages WHERE thread_id=? ORDER BY id DESC LIMIT 1', [$group]);
$written = fn(): array => [(int)scalar('SELECT COUNT(*) FROM messages'), (int)scalar('SELECT COUNT(*) FROM news'),
                           (int)scalar('SELECT COUNT(*) FROM messages WHERE removed_at IS NOT NULL')];
$writtenBefore = $written();
foreach ([['message_send', ['thread_id'=>(string)$thread['id'], 'body'=>'Bitte pünktlich sein.']], ['message_remove', ['id'=>(string)$groupMessage]],
          ['news_save', ['title'=>'Hallenzeiten', 'body'=>'Ab Montag neu.', 'published'=>'1']]] as [$action, $fields])
    throws(fn() => act($action, $fields), $action.' is refused, in the sentence the chat shows for it', viewing_refusal());
is_same($writtenBefore, $written(), 'and nothing was written: no message, no news, nothing taken down');
unset($_SESSION['impersonator_id']);

case_('„Alle Einzelchats" holds what the person looking may read, and nothing once she may no longer look');
/* An administrator may view the portal as another administrator, and sees her
   „Alle Einzelchats" as far as she may read them herself (ADR 0022 §2, §9).
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

case_('Which kind of chat a pair makes is decided in one place');
$as = fn(int $id): array => one('SELECT * FROM accounts WHERE id=?', [$id]);
is_same('staff_direct', pair_kind($as($hofer), $as($trainer)), 'a student and a trainer: the administrators can read it');
is_same('staff_direct', pair_kind($as($admin), $as($berger)), 'a student and an administrator, either way round');
is_same('direct', pair_kind($as($hofer), $as($berger)), 'two students, as their chats from before are: nobody else reads it');
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
   for it alone; it reads the author the message carries. */
$author = one('SELECT '.chat_person_columns('a').' FROM accounts a WHERE a.id=?', [$trainer]);
ok(str_contains(render_view('messages', ['id'=>(string)$group]), '<span class="bubble-sender hue-'.chat_hue($trainer).'">'.chat_name($author).'</span>'),
   'and the group names her over her message as the chat draws her everywhere else, in her colour');
throws(fn() => chat_person_columns('a; DROP TABLE accounts'), 'an alias is a name, never a piece of SQL', 'Refusing');

case_('A chat not started yet looks as it will once the first message makes it');
/* The empty chat drew its person from a column list of its own and decided its
   kind with a rule of its own (code review C7). Both now come from where the
   chat is made, so its header and what it says about who reads it are what the
   chat keeps once it exists - and the chat list draws the person from the same
   facts as the header. */
$headOf = fn(string $html): string => (string)strstr((string)strstr($html, '<header class="chat-head">'), '</header>', true);
$notesOf = function (string $html): array { preg_match_all('~<p class="chat-note">(.*?)</p>~', $html, $m); return $m[1]; };
$admin3 = make_account(['role'=>'admin', 'name'=>'Dritte Admin']);
foreach ([[$hofer, $admin, 'Hier schreibt ihr zu zweit. Die Administratoren des Vereins können mitlesen.'],
          [$trainer, $admin3, 'Hier schreibt ihr zu zweit. Die Administratoren des Vereins können mitlesen.']] as [$writer, $person, $note]) {
    sign_in_as($writer);
    $name = (string)scalar('SELECT name FROM accounts WHERE id=?', [$person]);
    is_same(0, pair_thread($writer, $person), current_user()['name'].' has no chat with '.$name.' yet');
    $unstarted = render_view('messages', ['with'=>(string)$person]);
    ok(str_contains($headOf($unstarted), '<h2>'.e($name).'</h2>') && str_contains($headOf($unstarted), e('Administrator')),
       'the empty chat’s header shows '.$name.' with their role, so the comparison below has something to compare');
    ok(in_array(e($note), $notesOf($unstarted), true), 'and says: „'.$note.'“');
    $opened = render_view('messages', ['id'=>(string)direct_thread(current_user(), $person)]);
    is_same($headOf($unstarted), $headOf($opened), 'once the chat exists, its header is that one');
    is_same($notesOf($unstarted), $notesOf($opened), 'and so is what it says about who reads it');
}
sign_in_as($hofer);
$rowOf = fn(string $html, int $chat): string
    => (string)strstr((string)strstr($html, 'href="'.e(url('messages', ['id'=>$chat, '#'=>'chat-end'])).'"'), '</a>', true);
$adminRow = one('SELECT '.chat_person_columns('a').' FROM accounts a WHERE a.id=?', [$admin]);
$listed = $rowOf(render_view('messages'), pair_thread($hofer, $admin));
ok(str_contains($listed, avatar($adminRow)) && str_contains($headOf(render_view('messages', ['with'=>(string)$admin])), avatar($adminRow, 'small')),
   'the chat list draws the administrator with the initials the header shows');
// link_button() takes the place on the page as url() does, so there is one way to say it.
ok(str_contains(render_view('messages', ['id'=>(string)$group, 'members'=>'1']),
                '<a class="button secondary" href="'.e(url('messages', ['id'=>$group, '#'=>'chat-end'])).'">'.e('Zurück zur Gruppe').'</a>'),
   '„Zurück zur Gruppe" lands on the end of the chat, through url()');

case_('An administrator reads every chat, of every kind; a trainer and a family no more than before [ADR 0022 §11.1]');
/* The owner: "administrators can read everything" - "if needed in case there
   are problems". Her list stays her own; the trainer's and the families' rule
   does not move. */
$trainer3 = make_account(['role'=>'trainer', 'name'=>'Dritte Trainerin']);
sign_in_as($trainer2);
$teamChat = direct_thread(current_user(), $trainer3);
act('message_send', ['thread_id'=>(string)$teamChat, 'body'=>'Übernimmst du am Freitag?']);
run('UPDATE classes SET archived=1 WHERE id=?', [$course]);
sign_in_as($admin);
foreach (['the group of an archived course'=>$group, 'a child’s chat with a trainer'=>(int)$thread['id'],
          'a chat between two trainers'=>$teamChat, 'a chat between two children from before'=>$direct,
          'a desk thread from before'=>$desk] as $what => $chat)
    does_not_throw(fn() => thread_record($chat), 'she opens '.$what);
run('UPDATE classes SET archived=0 WHERE id=?', [$course]);
sign_in_as($trainer);
foreach (['a chat between two other trainers'=>$teamChat, 'a chat between two children'=>$direct,
          'a child’s chat with another trainer'=>$withOther] as $what => $chat)
    throws(fn() => thread_record($chat), 'the trainer still does not open '.$what, 'nicht gefunden');
sign_in_as($berger);
foreach (['a chat between two trainers'=>$teamChat, 'another family’s chat with a trainer'=>(int)$thread['id'],
          'another family’s desk thread'=>$desk] as $what => $chat)
    throws(fn() => thread_record($chat), 'a family still does not open '.$what, 'nicht gefunden');

case_('Her reading leaves nothing behind: no read mark, no audit entry, no notice [ADR 0022 §11.2]');
/* A read mark is a dated row saying that she read it, which is the record the
   owner declined ("it should not be recorded if an admin does something"). A
   mark from before that rule stays exactly as it was. */
$notHers = ['a child’s chat with a trainer'=>(int)$thread['id'], 'a chat between two trainers'=>$teamChat,
            'a chat between two children'=>$direct];
fixture('thread_reads', ['thread_id'=>(int)$thread['id'], 'account_id'=>$admin, 'last_read_message_id'=>0, 'updated_at'=>'2026-01-01 00:00:00']);
$traces = fn(): array => [rows('SELECT thread_id, last_read_message_id, updated_at FROM thread_reads WHERE account_id=? ORDER BY thread_id', [$admin]),
                          (int)scalar('SELECT COUNT(*) FROM audit_log'), (int)scalar('SELECT COUNT(*) FROM notifications')];
sign_in_as($admin);
$before = $traces();
foreach ($notHers as $what => $chat) {
    $page = render_view('messages', ['id'=>(string)$chat]);
    ok(str_contains($page, '<div class="prewrap">'), 'she reads '.$what.', its messages and all');
    ok(!str_contains($page, 'value="message_send"'), 'with no writing box');
    ok(!in_array($chat, array_map('intval', array_column(chat_list(current_user()), 'id')), true)
       && !in_array($chat, unread_thread_ids(current_user()), true), 'and it is neither in her list nor in her badge');
}
is_same($before, $traces(), 'opening the three wrote no read mark, changed none from before, and left no audit entry and no notice');
render_view('messages', ['id'=>(string)$group]);
ok(in_array($group, array_map('intval', array_column(rows('SELECT thread_id FROM thread_reads WHERE account_id=?', [$admin]), 'thread_id')), true),
   'while a chat in her own list is marked read as she opens it, so the line above proves something');
$messages = (int)scalar('SELECT COUNT(*) FROM messages');
throws(fn() => act('message_send', ['thread_id'=>(string)$thread['id'], 'body'=>'Ich lese mit.']),
       'a message she sends into a chat she reads along is refused, in the words the chat shows her', only_the_two_write());
is_same($messages, (int)scalar('SELECT COUNT(*) FROM messages'), 'and nothing is written');
foreach (['a child’s chat with a trainer'=>(int)$thread['id'], 'a chat between two trainers'=>$teamChat] as $what => $chat)
    ok(str_contains(render_view('messages', ['id'=>(string)$chat]), e(only_the_two_write())),
       'where the writing box would be, '.$what.' says „'.only_the_two_write().'"');
ok(!str_contains(render_view('messages', ['id'=>(string)$direct]), e(only_the_two_write())),
   'one between two children does not: nobody writes there');

/** The heading of the section of the chat list that $chat is listed under, or 'not listed'. */
$sectionOf = function (string $html, int $chat): string {
    $list = (string)strstr((string)strstr($html, '<aside class="card thread-list">'), '</aside>', true);
    $at = strpos($list, 'href="'.e(url('messages', ['id'=>$chat, '#'=>'chat-end'])).'"');
    if ($at === false) return 'not listed';
    preg_match_all('~<h2 class="chat-section">(.*?)</h2>~', substr($list, 0, $at), $m);
    return html_entity_decode((string)(end($m[1]) ?: 'no heading'));
};

case_('„Alle Einzelchats": every chat between two others, by who it is between, both named, nothing unread [ADR 0022 §11.1]');
sign_in_as($admin);
ok(str_contains(render_view('messages'), '>'.e('Alle Einzelchats').'</a>'), 'her chat list leads there, under the new name');
$all = render_view('messages', ['all'=>'1']);
foreach (['a child’s chat with a trainer'=>[(int)$thread['id'], 'Schüler und Team'], 'one with the second trainer'=>[$withOther, 'Schüler und Team'],
          'a chat between two trainers'=>[$teamChat, 'Im Team'], 'the first two trainers’ chat'=>[$staffChat, 'Im Team'],
          'a chat between two children from before'=>[$direct, 'Zwischen Schülern (geschlossen)']] as $what => [$chat, $section])
    is_same($section, $sectionOf($all, $chat), $what.' is under „'.$section.'"');
is_same('not listed', $sectionOf($all, $group), 'a course group is not a chat between two');
is_same('not listed', $sectionOf($all, pair_thread($hofer, $admin)), 'and a chat she is in is in her own list, not here');
ok(str_contains($rowOf($all, $direct), e('Familie Berger · Familie Hofer')), 'a row names both people');
$listOf = fn(string $html): string => (string)strstr((string)strstr($html, '<aside class="card thread-list">'), '</aside>', true);
ok(!str_contains($listOf($all), 'class="count"'), 'and none counts anything unread, though she has marked none of them read');

case_('What a chat says at the top follows who it is between, never who is looking [ADR 0022 §11.1]');
/* A chat between two trainers told them it was private, which an
   administrator reading it made untrue, and the line asked whether the person
   looking was staff (code review of round 2). */
$both = 'Hier schreibt ihr zu zweit. Die Administratoren des Vereins können mitlesen.';
$closedNote = 'Chats zwischen Schülern sind geschlossen. Was hier steht, bleibt lesbar. Schreib dem Trainerteam oder in deine Kursgruppe.';
foreach ([[$trainer, $staffChat, $both, 'two trainers, to one of them'],
          [$admin, $teamChat, $both, 'two trainers, to an administrator reading along'],
          [$admin, (int)$thread['id'], $both, 'a child and a trainer, to an administrator reading along'],
          [$admin, $direct, $closedNote, 'two children, to an administrator reading along'],
          [$hofer, $direct, $closedNote, 'two children, to one of them']] as [$who, $chat, $note, $what]) {
    sign_in_as($who);
    $notes = $notesOf(render_view('messages', ['id'=>(string)$chat]));
    is_same(e($note), $notes[0] ?? '', $what.': „'.$note.'"');
    ok(!preg_grep('/privat/', $notes), 'and nothing on it says it is private');
}

case_('A family’s chat with another family is an earlier one; a trainer’s with a trainer is one of her chats');
sign_in_as($hofer);
$list = render_view('messages');
is_same('Frühere Unterhaltungen', $sectionOf($list, $direct), 'the child’s closed chat with another child is under „Frühere Unterhaltungen"');
is_same('Einzelchats', $sectionOf($list, (int)$thread['id']), 'and their chat with the trainer under „Einzelchats"');
sign_in_as($trainer);
is_same('Einzelchats', $sectionOf(render_view('messages'), $staffChat), 'a trainer’s chat with another trainer is under „Einzelchats"');

case_('A chat between two takes messages while a member of staff is in it, whatever it was made as [security review of round 2]');
/* Who writes was asked of the kind the chat was made as and of the writer's
   role: a 'staff_direct' chat whose trainer was later made a student had two
   students in it and still took messages from both. One rule, asked of who is
   in the chat now: no member of staff left in it, closed. */
sign_in_as($trainer);
ok(may_write_thread(current_user(), thread_record($staffChat)), 'the trainer writes to the second trainer, so the lines below prove something');
run("UPDATE accounts SET role='student' WHERE id=?", [$trainer2]);
sign_in_as($trainer);
ok(may_write_thread(current_user(), thread_record($staffChat)), 'with the second trainer made a student, their chat stays open: a member of staff is still in it, as in a child’s chat with staff');
sign_in_as($trainer2);
ok(may_write_thread(current_user(), thread_record($staffChat)), 'and the second trainer, now a student, still writes to her, as any student writes to staff');
run("UPDATE accounts SET role='trainer' WHERE id=?", [$trainer2]);
sign_in_as($hofer);
ok(may_write_thread(current_user(), thread_record((int)$thread['id'])), 'the child writes to the trainer, so the lines below prove something');
run("UPDATE accounts SET role='student' WHERE id=?", [$trainer]);
foreach ([$hofer => 'the child', $trainer => 'the trainer, now a student'] as $who => $whom) {
    sign_in_as($who);
    ok(!may_write_thread(current_user(), thread_record((int)$thread['id'])), 'with the trainer made a student, '.$whom.' may not write in their chat any more');
    throws(fn() => act('message_send', ['thread_id'=>(string)$thread['id'], 'body'=>'Noch da?']), 'a message from '.$whom.' is refused', 'nicht mehr geschrieben');
}
sign_in_as($hofer);
$closedNow = render_view('messages', ['id'=>(string)$thread['id']]);
ok(!str_contains($closedNow, 'value="message_send"') && str_contains($closedNow, e($closedNote)), 'it has no writing box, and says why');
is_same('Frühere Unterhaltungen', $sectionOf(render_view('messages'), (int)$thread['id']), 'and the child’s list has it among the earlier conversations');
sign_in_as($admin);
is_same('Zwischen Schülern (geschlossen)', $sectionOf(render_view('messages', ['all'=>'1']), (int)$thread['id']),
        'while „Alle Einzelchats" has it between students, closed');
run("UPDATE accounts SET role='trainer' WHERE id=?", [$trainer]);

case_('Who cannot start a chat is told what fits them; an old notice’s ?contacts=1 opens the chat list');
sign_in_as($trainer);
$refusal = '';
try { direct_thread(current_user(), 999999); } catch (UserError $e) { $refusal = $e->getMessage(); }
is_same('Diese Person kannst du hier nicht anschreiben.', $refusal, 'staff are not sent to the coaching team or to a course group');
sign_in_as($hofer);
throws(fn() => direct_thread(current_user(), $berger), 'a family is', 'Schreib dem Trainerteam oder in deine Kursgruppe.');
ok(str_contains(render_view('messages', ['new'=>'1']), e('An wen?')), 'a family’s „Neue Nachricht" asks whom to write to');
ok(!str_contains(render_view('messages', ['contacts'=>'1']), e('An wen?')), 'while ?contacts=1, which older notices link to, is the list');

case_('The chat list is one query, however many chats there are');
sign_in_as($trainer);
for ($i = 0; $i < 5; $i++) make_class();
course_groups_fill();
is_same(1, query_count(fn() => chat_list(current_user())), 'with a handful');
for ($i = 0; $i < 45; $i++) make_class();
course_groups_fill();
is_same(1, query_count(fn() => chat_list(current_user())), 'and with fifty');
is_same(1, query_count(fn() => unread_count(current_user())), 'the badge on every page is one query too');
