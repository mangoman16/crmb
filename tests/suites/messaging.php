<?php
/**
 * Messages: who may read what, and who may write to whom.
 *
 * The rule that matters most is the one about direct conversations. A family
 * writing to another family is not the trainer's business, and "the trainer can
 * see everything" is the kind of default that gets added by accident and noticed
 * by nobody, so it is checked from both sides.
 */
$admin   = make_account(['role'=>'admin',   'name'=>'Admin']);
$trainer = make_account(['role'=>'trainer', 'name'=>'Trainerin']);
$hofer   = make_account(['role'=>'student', 'name'=>'Familie Hofer']);
$berger  = make_account(['role'=>'student', 'name'=>'Familie Berger']);
$gruber  = make_account(['role'=>'student', 'name'=>'Familie Gruber']);

case_('Writing to the trainer needs nobody’s permission');
sign_in_as($hofer);
is_same(true, may_message(current_user(), $trainer), 'the trainer');
is_same(true, may_message(current_user(), $admin), 'and the administrator');
is_same(false, may_message(current_user(), $berger), 'another family does not, not yet');
is_same(false, may_message(current_user(), (int)$hofer), 'and nobody writes to themselves');

case_('A family writing in reaches the staff conversation');
act('message_send', ['subject'=>'Frage zum Schläger', 'body'=>'Welchen sollen wir kaufen?']);
$thread = one('SELECT * FROM threads ORDER BY id DESC LIMIT 1');
is_same('staff', $thread['kind'], 'it is a staff conversation');
is_same($hofer, (int)$thread['account_id'], 'belonging to the family who started it');
is_same(1, count(threads_for(current_user())), 'they can see it');

case_('Every member of staff reads a staff conversation');
sign_in_as($trainer);
does_not_throw(fn() => thread_record((int)$thread['id']), 'the trainer');
sign_in_as($admin);
does_not_throw(fn() => thread_record((int)$thread['id']), 'and the administrator');
sign_in_as($berger);
throws(fn() => thread_record((int)$thread['id']), 'another family does not', 'nicht gefunden');

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
is_same(0, count(array_filter(threads_for(current_user()), fn($t) => (int)$t['id'] === $direct)), 'not in the list');
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
