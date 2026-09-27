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
is_same(['image/png' => 'png'], upload_types('icon'), 'the portal icon is a PNG and nothing else, not even an SVG');
foreach (['avatar','proof','message','icon'] as $kind)
    foreach (['text/html','application/x-php','application/octet-stream'] as $mime)
        ok(!isset(upload_types($kind)[$mime]), $mime.' is allowed nowhere');

case_('A file is stored under a name of the portal’s own choosing');
foreach (upload_types('message') as $extension)
    ok(preg_match('/^[a-z0-9]{2,5}$/D', $extension) === 1, 'the extension '.$extension.' cannot execute anywhere');

case_('Every kind of upload is swept, and the sweep knows where each is pointed at from');
foreach (['avatar','proof','message','icon'] as $kind)
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

// ---------------------------------------------------------------------------
// The portal's own icon (ADR 0008)
// ---------------------------------------------------------------------------

/**
 * The start of a PNG of the given size: the signature and the header chunk,
 * which is everything fileinfo and getimagesize() read. Nothing in the portal
 * decodes the picture itself, so no GD is needed to make one - which is the
 * point of the design.
 */
function png_header(int $width, int $height): string {
    $chunk = fn(string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IEND', '');
}

/** A file in the icon folder, as store_upload() would have left it. */
function icon_file(string $bytes, string $fill = ''): string {
    static $n = 0;
    $name = ($fill !== '' ? str_repeat($fill, 32) : str_pad(dechex(++$n), 32, 'f', STR_PAD_LEFT)).'.png';
    @mkdir(upload_dir('icon'), 0775, true);
    file_put_contents(upload_dir('icon').'/'.$name, $bytes);
    return $name;
}

case_('The icon in use survives the sweep; the ones it replaced do not');
/* The setting is stored as JSON, so the name sits inside quotes, and the sweep
   compares bare names. Without the REPLACE in its query nothing matches, and the
   icon she just uploaded is deleted an hour later - silently, until a page shows
   the built-in one again. */
$live = icon_file(png_header(512, 512), 'd');
$replaced = icon_file(png_header(512, 512), 'e');
touch(upload_dir('icon').'/'.$live, $old);
touch(upload_dir('icon').'/'.$replaced, $old);
set_setting('portal_icon', $live);
ok(str_starts_with((string)scalar("SELECT setting_value FROM settings WHERE setting_key='portal_icon'"), '"'),
   'the stored value really is quoted, so this fixture is the case the sweep has to handle');
is_same(1, prune_uploads(), 'one icon file is swept');
ok(is_file(upload_dir('icon').'/'.$live), 'and it is not the icon in use');
ok(!is_file(upload_dir('icon').'/'.$replaced), 'it is the one that was replaced');

case_('A picture that cannot be the icon is refused, says why, and is gone');
foreach ([[png_header(400, 300), 'quadratisch', 'a picture that is not square'],
          [png_header(120, 120), '180 × 180', 'a picture too small for the home screen'],
          [png_header(2049, 2049), '2048 × 2048', 'a picture larger than any home screen needs'],
          [png_header(30000, 30000), 'höchstens', 'a picture every phone would have to unpack into gigabytes'],
          ["GIF89a\x01\x00\x01\x00\x80\x00\x00", 'PNG', 'a GIF with a .png on the end'],
          ['<?php echo 1;', 'PNG', 'something that is not a picture at all']] as [$bytes, $says, $what]) {
    $name = icon_file($bytes);
    throws(fn() => check_portal_icon($name), $what.' is refused', $says);
    ok(!is_file(upload_dir('icon').'/'.$name), 'and '.$what.' is deleted there and then');
}
throws(fn() => check_portal_icon(str_repeat('0', 32).'.png'), 'a file that never arrived is refused rather than trusted');
foreach ([[180, 180], [1024, 1024], [2048, 2048]] as [$w, $h]) {
    $name = icon_file(png_header($w, $h));
    does_not_throw(fn() => check_portal_icon($name), $w.' × '.$h.' is accepted');
    ok(is_file(upload_dir('icon').'/'.$name), 'and kept');
}

case_('The icon in use, or the built-in one');
set_setting('portal_icon', '');
is_same('', portal_icon(), 'none chosen, so the built-in one');
is_same('', portal_icon_url(), 'and the layout keeps its own tags, with no extra request');
set_setting('portal_icon', $live);
is_same($live, portal_icon(), 'the one she uploaded');
$iconUrl = portal_icon_url();
ok(str_starts_with($iconUrl, rtrim((string)config('app_url'), '/').'/index.php?'), 'served through the front controller, as an absolute address');
ok(str_contains($iconUrl, 'page=icon'), 'at the icon page');
ok(str_contains($iconUrl, 'v='.substr($live, 0, 12)), 'with the start of its random name as the version');
ok(!str_contains($iconUrl, $live), 'but never the whole stored name');
$moved = icon_file(png_header(512, 512));
set_setting('portal_icon', $moved);
unlink(upload_dir('icon').'/'.$moved);
is_same('', portal_icon(), 'a database restored without storage/ falls back rather than linking to nothing');
is_same('', portal_icon_url(), 'so no page links to a 404');
set_setting('portal_icon', '../../config/config.php');
is_same('', portal_icon(), 'and a setting that does not look like a stored name is never served');

case_('The icon is kept for a year only at the address of the icon in use');
is_same('public, max-age=31536000, immutable', portal_icon_cache_control($live, substr($live, 0, 12)),
        'the current address may be kept for good: a new icon gets a new one');
is_same('no-cache', portal_icon_cache_control($live, 'aaaaaaaaaaaa'), 'an old address is checked again every time');
is_same('no-cache', portal_icon_cache_control($live, null), 'and so is one with no version');
is_same('no-cache', portal_icon_cache_control($live, ['x']), 'or with a version that is not text');
is_same('no-cache', portal_icon_cache_control('', ''), 'and so is the built-in icon served in place of one');
is_same('private, max-age=86400', shared_cache_control(86400, false, ['Set-Cookie: badminton_session=abc; path=/']),
        'a reply that starts a session is kept by the browser, never by a shared cache');
is_same('public, max-age=86400', shared_cache_control(86400, false, ['Content-Type: application/manifest+json']),
        'one that does not may be kept by any cache');

case_('The manifest names the club and points at the icon in use');
set_setting('club_name', 'TSV Beispiel');
set_setting('portal_icon', '');
$manifest = web_manifest();
is_same('TSV Beispiel', $manifest['name'], 'the club’s name, not "Badminton"');
is_same('TSV Beispiel', $manifest['short_name'], 'on the home screen too');
is_same(url('dashboard'), $manifest['start_url'], 'an install opens at the overview');
is_same(rtrim((string)config('app_url'), '/').'/', $manifest['scope'], 'and stays within the portal');
is_same(4, count($manifest['icons']), 'with no icon of her own, the built-in set');
foreach ($manifest['icons'] as $entry) {
    $file = APP_ROOT.'/public'.substr($entry['src'], strlen(rtrim((string)config('app_url'), '/')));
    ok(is_file($file), $entry['src'].' is absolute and is a file that ships');
}
set_setting('portal_icon', $live);
$manifest = web_manifest();
is_same(1, count($manifest['icons']), 'with her icon, that one alone');
is_same(portal_icon_url(), $manifest['icons'][0]['src'], 'at the same address the layout links');
is_same('512x512', $manifest['icons'][0]['sizes'], 'with its real size');
is_same('any', $manifest['icons'][0]['purpose'], 'and never "maskable": her logo was not drawn to be cut into a circle');
ok(!is_file(APP_ROOT.'/public/manifest.webmanifest'), 'the static manifest that said "Badminton" to every club is gone');

case_('Only an administrator changes the icon, and a refusal leaves the icon she had');
$trainer = make_account(['role'=>'trainer']);
sign_in_as($trainer);
throws(fn() => act('portal_icon_save', ['remove'=>'1']), 'a trainer may not', 'Administratoren');
is_same($live, portal_icon(), 'and nothing changed');
sign_in_as($admin);
throws(fn() => act('portal_icon_save', []), 'saving with no picture chosen says so', 'keine Datei');
$_FILES = ['icon' => ['name'=>'logo.png', 'type'=>'image/png', 'tmp_name'=>'/tmp/not-an-upload', 'error'=>UPLOAD_ERR_OK, 'size'=>2048]];
throws(fn() => act('portal_icon_save', []), 'a file that did not come through the form is refused', 'nicht über das Formular');
$_FILES = [];
is_same($live, portal_icon(), 'and the icon she had is still the icon');
ok(is_file(upload_dir('icon').'/'.$live), 'with its file untouched');

case_('Removing her icon puts the built-in one back and deletes the file');
act('portal_icon_save', ['remove'=>'1']);
is_same('', portal_icon(), 'the built-in icon applies again');
// In the words of the settings card, which calls it the „Standard-Symbol“.
is_same('Das Standard-Symbol wird wieder verwendet.', $_SESSION['flash']['message'] ?? null, 'and the message calls it what the card calls it');
ok(!is_file(upload_dir('icon').'/'.$live), 'and the file is gone, so the way back is to upload it again');
is_same('portal_icon.removed', (string)scalar('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'), 'the log says who did it');
does_not_throw(fn() => act('portal_icon_save', ['remove'=>'1']), 'removing when there is nothing to remove is not an error');
