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
is_same(['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'], upload_types('logo'),
        'the logo may be a PNG, a JPEG or a WebP - no GIF, which animates, and no SVG');
foreach (['avatar','proof','message','icon','logo'] as $kind)
    foreach (['text/html','application/x-php','application/octet-stream','image/svg+xml'] as $mime)
        ok(!isset(upload_types($kind)[$mime]), $mime.' is allowed nowhere');

case_('A file is stored under a name of the portal’s own choosing');
foreach (upload_types('message') as $extension)
    ok(preg_match('/^[a-z0-9]{2,5}$/D', $extension) === 1, 'the extension '.$extension.' cannot execute anywhere');

case_('Every kind of upload is swept, and the sweep knows where each is pointed at from');
foreach (['avatar','proof','message','icon','logo'] as $kind)
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

case_('A profile picture is kept by the browser, and only at the address of the picture in use');
/* Without this every page change fetched the top bar's picture again. A week,
   not a year: a copy left on a borrowed phone runs out on its own. */
$picture = str_repeat('e', 32).'.jpg';
is_same('private, max-age=604800', avatar_cache_control($picture, 'eeeeeeeeeeee'),
        'the current address is kept for a week, by this browser alone - never a shared cache');
is_same('private, no-store', avatar_cache_control($picture, 'aaaaaaaaaaaa'),
        'an address from before a new upload is never kept, so it cannot be remembered as the new picture');
is_same('private, no-store', avatar_cache_control($picture, null), 'nor is one with no version');
is_same('private, no-store', avatar_cache_control($picture, ['x']), 'or with a version that is not text');
is_same('private, no-store', avatar_cache_control('', ''), 'and a removed picture matches nothing');
/* The route ends with exit and its headers cannot be read on the command line,
   so which rule it hands to send_upload() is pinned here by its text. */
ok(str_contains((string)file_get_contents(APP_ROOT.'/app/uploads.php'), "avatar_cache_control(\$name, \$_GET['v'] ?? null)"),
   'the download route asks that question of the version it was given');

case_('Everything else that is downloaded is still never kept');
foreach (['send_download_headers' => 4, 'send_upload' => 4] as $function => $position)
    is_same('private, no-store', (new ReflectionFunction($function))->getParameters()[$position]->getDefaultValue(),
            $function.'() keeps invoices, proofs, attachments and screenshots out of every cache unless told otherwise');

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

// ---------------------------------------------------------------------------
// The club's logo (ADR 0014)
// ---------------------------------------------------------------------------

/** The start of a baseline JPEG: the markers getimagesize() reads for its size, and no picture. */
function jpeg_header(int $width, int $height): string {
    return "\xFF\xD8" . "\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
         . "\xFF\xC0" . pack('n', 17) . "\x08" . pack('nn', $height, $width) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
         . "\xFF\xD9";
}

/** The start of an extended WebP: the VP8X chunk, which carries the canvas size. */
function webp_header(int $width, int $height): string {
    $size = fn(int $n): string => substr(pack('V', $n - 1), 0, 3);
    $chunk = 'VP8X' . pack('V', 10) . "\x00\x00\x00\x00" . $size($width) . $size($height);
    return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
}

/** A file in the logo folder, as store_upload() would have left it. */
function logo_file(string $bytes, string $extension, string $fill = ''): string {
    static $n = 0;
    $name = ($fill !== '' ? str_repeat($fill, 32) : str_pad(dechex(++$n), 32, 'e', STR_PAD_LEFT)).'.'.$extension;
    @mkdir(upload_dir('logo'), 0775, true);
    file_put_contents(upload_dir('logo').'/'.$name, $bytes);
    return $name;
}

case_('The fixtures are pictures getimagesize() reads, of the size they claim');
foreach ([['png', png_header(600, 150), IMAGETYPE_PNG], ['jpg', jpeg_header(600, 150), IMAGETYPE_JPEG],
          ['webp', webp_header(600, 150), IMAGETYPE_WEBP]] as [$extension, $bytes, $type]) {
    $size = getimagesizefromstring($bytes);
    is_same([600, 150, $type], $size ? [$size[0], $size[1], $size[2]] : null, 'a '.$extension.' header of 600 × 150 reads as one');
}

case_('A picture that cannot be the logo is refused, says why, and is gone');
foreach ([[jpeg_header(600, 150), 'png', 'PNG-, JPEG- oder WebP', 'a JPEG renamed .png'],
          [png_header(600, 150), 'webp', 'PNG-, JPEG- oder WebP', 'a PNG renamed .webp'],
          ["GIF89a\x01\x00\x01\x00\x80\x00\x00", 'png', 'PNG-, JPEG- oder WebP', 'a GIF'],
          ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'png', 'PNG-, JPEG- oder WebP', 'an SVG'],
          [png_header(160, 40), 'png', 'nur 160 × 40 Pixel groß. Es muss mindestens 88 Pixel hoch sein', 'a picture 40 pixels tall'],
          [png_header(600, 100), 'png', '600 × 100 Pixel groß und damit mehr als fünfmal so breit wie hoch', 'a 6:1 banner'],
          [jpeg_header(100, 201), 'jpg', '100 × 201 Pixel groß und damit mehr als doppelt so hoch wie breit', 'a picture more than twice as tall as wide'],
          [webp_header(2049, 1000), 'webp', 'höchstens 2048 Pixel breit und hoch', 'a picture wider than 2048'],
          [png_header(30000, 30000), 'png', 'höchstens 2048', 'a picture every phone would have to unpack into gigabytes']] as [$bytes, $extension, $says, $what]) {
    $name = logo_file($bytes, $extension);
    throws(fn() => check_portal_logo($name), $what.' is refused', $says);
    ok(!is_file(upload_dir('logo').'/'.$name), 'and '.$what.' is deleted there and then');
}
throws(fn() => check_portal_logo(str_repeat('0', 32).'.png'), 'a file that never arrived is refused rather than trusted');
foreach ([[webp_header(600, 150), 'webp', '600 × 150 WebP'], [png_header(440, 88), 'png', '5:1 PNG exactly 88 tall'],
          [jpeg_header(1024, 768), 'jpg', 'iPhone-shaped JPEG'], [png_header(100, 200), 'png', '1:2 PNG'],
          [png_header(2048, 2048), 'png', '2048 square PNG']] as [$bytes, $extension, $what]) {
    $name = logo_file($bytes, $extension);
    does_not_throw(fn() => check_portal_logo($name), 'a '.$what.' is accepted');
    ok(is_file(upload_dir('logo').'/'.$name), 'and kept');
}

case_('The logo in use survives the sweep; the ones it replaced do not');
$liveLogo = logo_file(webp_header(600, 150), 'webp', 'a');
$replacedLogo = logo_file(png_header(600, 150), 'png', 'b');
foreach (glob(upload_dir('logo').'/*') as $path) touch($path, $old);
set_setting('portal_logo', $liveLogo);
prune_uploads();
ok(is_file(upload_dir('logo').'/'.$liveLogo), 'the logo in use is kept, although its setting is stored in quotes');
ok(!is_file(upload_dir('logo').'/'.$replacedLogo), 'the one it replaced is swept');

case_('The logo in use, its address and its size');
is_same($liveLogo, portal_logo(), 'the one she uploaded');
$logoUrl = portal_logo_url();
ok(str_contains($logoUrl, 'page=logo') && str_contains($logoUrl, 'v='.substr($liveLogo, 0, 12)), 'served by the router, versioned by its name');
ok(!str_contains($logoUrl, $liveLogo), 'but never the whole stored name');
is_same([600, 150], portal_logo_size(), 'its real size, for the width and height attributes');
set_setting('portal_logo', '../../config/config.php');
is_same('', portal_logo(), 'a setting that does not look like a stored name is never served');
set_setting('portal_logo', str_repeat('c', 32).'.gif');
is_same('', portal_logo(), 'nor one of a type the logo cannot be');
set_setting('portal_logo', str_repeat('c', 32).'.png');
is_same('', portal_logo(), 'and a file that is not there falls back rather than linking to a 404');
is_same('', portal_logo_url(), 'so no page links to one');
is_same([0, 0], portal_logo_size(), 'and there is no size to print');
set_setting('portal_logo', $liveLogo);

case_('The logo is kept for a year only at the address of the logo in use');
is_same('public, max-age=31536000, immutable', portal_logo_cache_control($liveLogo, substr($liveLogo, 0, 12)),
        'the current address may be kept for good: a new logo gets a new one');
is_same('no-cache', portal_logo_cache_control($liveLogo, 'bbbbbbbbbbbb'), 'an old address is checked again every time');
is_same('no-cache', portal_logo_cache_control($liveLogo, null), 'and one with no version');
is_same('no-cache', portal_logo_cache_control('', ''), 'and there is no current address without a logo');
is_same('image/webp', array_search('webp', upload_types('logo'), true), 'the logo is served as the type its extension stands for');

case_('Only an administrator changes the logo, and a refusal leaves the logo she had');
sign_in_as($trainer);
throws(fn() => act('portal_logo_save', ['remove'=>'1']), 'a trainer may not', 'Administratoren');
is_same($liveLogo, portal_logo(), 'and nothing changed');
sign_in_as($admin);
throws(fn() => act('portal_logo_save', []), 'saving with no picture chosen says so', 'keine Datei');
$_FILES = ['logo' => ['name'=>'logo.png', 'type'=>'image/png', 'tmp_name'=>'/tmp/not-an-upload', 'error'=>UPLOAD_ERR_OK, 'size'=>2048]];
throws(fn() => act('portal_logo_save', []), 'a file that did not come through the form is refused', 'nicht über das Formular');
$_FILES = [];
is_same($liveLogo, portal_logo(), 'and the logo she had is still the logo');
ok(is_file(upload_dir('logo').'/'.$liveLogo), 'with its file untouched');

case_('Removing the logo deletes the file and says what shows instead');
set_setting('portal_icon', '');
act('portal_logo_save', ['remove'=>'1']);
is_same('', portal_logo(), 'no logo');
is_same('Logo entfernt. Oben links steht wieder das „B“.', $_SESSION['flash']['message'] ?? null, 'with no icon either, the „B" is back');
ok(!is_file(upload_dir('logo').'/'.$liveLogo), 'and the file is gone, so the way back is to upload it again');
is_same('portal_logo.removed', (string)scalar('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'), 'the log says who did it');
$iconBack = icon_file(png_header(512, 512));
set_setting('portal_icon', $iconBack);
act('portal_logo_save', ['remove'=>'1']);
is_same('Logo entfernt. Oben links steht wieder das Symbol des Portals.', $_SESSION['flash']['message'] ?? null,
        'with an icon, the message says the icon is back');
set_setting('portal_icon', '');
