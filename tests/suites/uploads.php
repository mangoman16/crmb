<?php
/**
 * Files people send, and what happens to them afterwards.
 *
 * The limit has to be one the server can honour, the type has to come from the
 * bytes rather than the name, and - the part that is easy to forget - a file
 * whose record is gone has to go too. A portal that keeps a deleted family's
 * photographs in a folder nobody looks at is not storing them on purpose.
 */
$admin = make_account(['role'=>'admin']); sign_in_as($admin);

case_('The limit offered is never larger than the server will take');
set_setting('upload_max_kb', 1024 * 1024);   // 1 GB, more than any shared host allows
ok(upload_limit() <= max(1, ini_bytes((string)ini_get('upload_max_filesize'))), 'PHP has the final word');
ok(upload_limit_capped(), 'and the interface can say that it was capped');
set_setting('upload_max_kb', 8);
is_same(8 * 1024, upload_limit(), 'a small setting is honoured as it stands');
is_same(false, upload_limit_capped(), 'and is not reported as capped');

case_('Each kind allows what it is for, and nothing else');
ok(!isset(upload_types('avatar')['application/pdf']), 'a profile picture is a picture');
ok(isset(upload_types('proof')['application/pdf']), 'a proof may be a PDF');
ok(isset(upload_types('message')['audio/webm']), 'a message may be a voice note');
foreach (['avatar','proof','message'] as $kind)
    foreach (['text/html','application/x-php','application/octet-stream'] as $mime)
        ok(!isset(upload_types($kind)[$mime]), $mime.' is allowed nowhere');

case_('A file is stored under a name of the portal’s own choosing');
foreach (upload_types('message') as $extension)
    ok(preg_match('/^[a-z0-9]{2,5}$/D', $extension) === 1, 'the extension '.$extension.' cannot execute anywhere');

case_('Every kind of upload is swept, and the sweep knows where each is pointed at from');
foreach (['avatar','proof','message'] as $kind)
    ok(isset(upload_references()[$kind]), $kind.' is covered by the sweep');

case_('A file nothing points at any more is removed');
$old = time() - 7200;
$made = [];
foreach (['avatar'=>'jpg', 'proof'=>'pdf', 'message'=>'webm'] as $kind => $extension) {
    @mkdir(upload_dir($kind), 0775, true);
    foreach (['kept', 'orphan'] as $fate) {
        $name = str_repeat($fate === 'kept' ? 'a' : 'b', 32) . '.' . $extension;
        file_put_contents(upload_dir($kind) . '/' . $name, 'x');
        touch(upload_dir($kind) . '/' . $name, $old);
        $made[$kind][$fate] = $name;
    }
}
// One row pointing at each "kept" file, one per kind, the way the real tables do.
run('UPDATE accounts SET avatar_name=? WHERE id=?', [$made['avatar']['kept'], $admin]);
$student = make_student();
fixture('payment_proofs', ['charge_id'=>null, 'invoice_id'=>null, 'student_id'=>$student,
    'stored_name'=>$made['proof']['kept'], 'original_name'=>'beleg.pdf', 'mime'=>'application/pdf',
    'bytes'=>1, 'note'=>'', 'uploaded_by'=>$admin, 'created_at'=>now()]);
$thread = make_thread([$admin]);
run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)', [$thread, $admin, 'Hallo', now()]);
$messageId = (int)db()->lastInsertId();
fixture('message_files', ['message_id'=>$messageId, 'kind'=>'voice', 'stored_name'=>$made['message']['kept'],
    'original_name'=>'note.webm', 'mime'=>'audio/webm', 'bytes'=>1, 'seconds'=>3, 'created_at'=>now()]);

is_same(3, prune_uploads(), 'the three files nothing points at are removed');
foreach ($made as $kind => $names) {
    ok(is_file(upload_dir($kind) . '/' . $names['kept']), $kind.': the file a record points at stays');
    ok(!is_file(upload_dir($kind) . '/' . $names['orphan']), $kind.': the one nothing points at goes');
}
is_same(0, prune_uploads(), 'running it again removes nothing');

case_('A file uploaded moments ago is left alone');
// Its row may be being written in another request right now, and deleting
// somebody's photograph a second after they chose it is the worse failure.
$fresh = str_repeat('c', 32) . '.jpg';
file_put_contents(upload_dir('avatar') . '/' . $fresh, 'x');
is_same(0, prune_uploads(), 'nothing is swept');
ok(is_file(upload_dir('avatar') . '/' . $fresh), 'and it is still there');
touch(upload_dir('avatar') . '/' . $fresh, time() - 7200);
is_same(1, prune_uploads(), 'an hour later it is gone');

case_('Deleting the record is what makes the file an orphan');
run('DELETE FROM message_files WHERE stored_name=?', [$made['message']['kept']]);
touch(upload_dir('message') . '/' . $made['message']['kept'], $old);
is_same(1, prune_uploads(), 'the attachment of a deleted message is swept');
ok(!is_file(upload_dir('message') . '/' . $made['message']['kept']), 'and the voice note is really gone');

case_('The nightly maintenance is what runs it');
ok(str_contains((string)file_get_contents(APP_ROOT.'/app/tick.php'), 'prune_uploads()'),
   'so nobody has to remember to sweep by hand');
