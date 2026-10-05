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

case_('One way of saying megabytes, for limits and for measured sizes alike');
is_same('1,0 MB', megabytes_label(1048576), 'exactly one megabyte');
is_same('1,0 MB', megabytes_label(1048577), 'one byte over, said plainly, still reads as one');
is_same('1,1 MB', megabytes_label(1048577, true), 'but a measured size rounds up, so it never reads as the limit it broke');
is_same('1,0 MB', megabytes_label(1048576, true), 'and exactly the limit rounded up stays the limit');
is_same('2,5 MB', megabytes_label((int)(2.5 * 1048576)), 'one decimal');
$_SESSION['locale'] = 'en';
is_same('2.5 MB', megabytes_label((int)(2.5 * 1048576)), 'with a point in English');
$_SESSION['locale'] = 'de';
$uiSource = (string)file_get_contents(APP_ROOT.'/app/ui.php').(string)file_get_contents(APP_ROOT.'/app/brand.php');
is_same(0, preg_match_all('/number_format\(\$bytes \/ 1048576/', $uiSource), 'nothing else formats megabytes on its own');

case_('A kind with a smaller limit of its own is labelled with the smaller one');
/* The Logo card said „Höchstens 2,0 MB" while check_portal_logo() refused
   anything over 1 MB: the promise has to be the smaller of the two. */
set_setting('upload_max_kb', 2048);
$general = upload_limit();
is_same(upload_limit_label(), upload_limit_label(null), 'without a cap, the general limit');
ok($general > PORTAL_LOGO_MAX_BYTES, 'this fixture has a general limit above the logo’s ('.$general.' bytes), so the next line proves something');
is_same('1,0 MB', upload_limit_label(PORTAL_LOGO_MAX_BYTES), 'with the logo’s cap, the logo’s 1 MB');
is_same(upload_limit_label(), upload_limit_label($general * 4), 'a cap above the general limit does not raise it');
set_setting('upload_max_kb', 8);
is_same('8 kB', upload_limit_label(PORTAL_LOGO_MAX_BYTES), 'and a general limit below the cap still wins');

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
    // The file is what comes before the ?v= that carries a hash of its bytes (asset_path()).
    $file = APP_ROOT.'/public'.substr(explode('?', $entry['src'])[0], strlen(rtrim((string)config('app_url'), '/')));
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
set_setting('upload_max_kb', 4096);   // the general limit above the logo's, as on a real portal
$heavy = logo_file(png_header(600, 150).str_repeat("\0", PORTAL_LOGO_MAX_BYTES), 'png');
throws(fn() => check_portal_logo($heavy), 'a logo over 1 MB is refused, however well-formed: every sign-in page loads it',
       'Das Bild ist 1,1 MB groß. Für das Logo reichen 1,0 MB – größer wird es nicht schärfer');
ok(!is_file(upload_dir('logo').'/'.$heavy), 'and is deleted there and then');
/* The card says „Höchstens {upload_limit_label(PORTAL_LOGO_MAX_BYTES)}"; the
   refusal has to name that same limit in the same words. */
$heavy = logo_file(png_header(600, 150).str_repeat("\0", PORTAL_LOGO_MAX_BYTES), 'png');
$refusal = '';
try { check_portal_logo($heavy); } catch (UserError $e) { $refusal = $e->getMessage(); }
ok(str_contains($refusal, 'Für das Logo reichen '.upload_limit_label(PORTAL_LOGO_MAX_BYTES).' –'),
   'the refusal names exactly the limit the Logo card states ('.upload_limit_label(PORTAL_LOGO_MAX_BYTES).')');
$justUnder = logo_file(png_header(600, 150).str_repeat("\0", PORTAL_LOGO_MAX_BYTES - strlen(png_header(600, 150))), 'png');
does_not_throw(fn() => check_portal_logo($justUnder), 'exactly 1 MB is accepted');
foreach ([[webp_header(600, 150), 'webp', '600 × 150 WebP'], [png_header(440, 88), 'png', '5:1 PNG exactly 88 tall'],
          [jpeg_header(1024, 768), 'jpg', 'iPhone-shaped JPEG'], [png_header(100, 200), 'png', '1:2 PNG'],
          [png_header(2048, 2048), 'png', '2048 square PNG']] as [$bytes, $extension, $what]) {
    $name = logo_file($bytes, $extension);
    does_not_throw(fn() => check_portal_logo($name), 'a '.$what.' is accepted');
    ok(is_file(upload_dir('logo').'/'.$name), 'and kept');
}

case_('The shape rule is said in words made from the numbers it checks');
is_same('Höchstens fünfmal so breit wie hoch und höchstens doppelt so hoch wie breit.', portal_logo_shape_hint(),
        'the Logo card’s hint, from PORTAL_LOGO_MAX_RATIO and PORTAL_LOGO_MIN_RATIO');
is_same(['fünfmal', 'five times'], portal_logo_times(PORTAL_LOGO_MAX_RATIO), 'the widest, in both languages');
is_same(['doppelt', 'twice'], portal_logo_times(1 / PORTAL_LOGO_MIN_RATIO), 'the tallest');
throws(fn() => portal_logo_times(2.5), 'a ratio nobody would write as a word is a mistake in the constants, and says so', 'No words');

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

// ---------------------------------------------------------------------------
// Where a photo was taken (ADR 0022: a picture in a group reaches every child)
// ---------------------------------------------------------------------------

/** One JPEG segment: its marker and its payload, with the length between. */
function jpeg_segment(int $marker, string $payload): string {
    return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
}

/**
 * A JPEG as a phone sends it: the address in EXIF (with GPS), in XMP and in
 * IPTC, a colour profile, and a picture. EXIF in Intel order, where the
 * portal's own orientation block is Motorola, so both are read.
 */
function jpeg_from_a_phone(int $orientation, string $where): string {
    $gps = pack('v', 2) . pack('vvVa4', 0x0001, 2, 2, "N\0") . pack('vvVV', 0x0002, 5, 3, 0) . pack('V', 0);
    $ifd0Length = 2 + 3 * 12 + 4;
    $text = $where . "\0";
    $tiff = "II*\0" . pack('V', 8) . pack('v', 3)
          . pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
          . pack('vvVV', 0x010E, 2, strlen($text), 8 + $ifd0Length)
          . pack('vvVV', 0x8825, 4, 1, 8 + $ifd0Length + strlen($text))
          . pack('V', 0) . $text . $gps;
    return "\xFF\xD8" . jpeg_segment(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
         . jpeg_segment(0xE1, "Exif\0\0" . $tiff)
         . jpeg_segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><photoshop:City>" . $where . '</photoshop:City></x:xmpmeta>')
         . jpeg_segment(0xED, "Photoshop 3.0\08BIM\x04\x04\0\0" . pack('N', strlen($where) + 5) . "\x1C\x02\x5A" . pack('n', strlen($where)) . $where)
         . jpeg_segment(0xE2, "ICC_PROFILE\0\x01\x01" . str_repeat("\x2A", 16))
         . jpeg_segment(0xC0, "\x08" . pack('nn', 150, 600) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01")
         . jpeg_segment(0xDA, "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00") . "\x12\x34\x56\xFF\x00\x78\xFF\xD9";
}

/** Which way is up, as PHP's own EXIF reader finds it - not as the portal's does. */
function exif_as_php_reads_it(string $jpeg): array {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $jpeg); rewind($stream);
    $read = @exif_read_data($stream);
    fclose($stream);
    return $read ?: [];
}

/** A PNG chunk: length, type, data, checksum. */
function png_chunk(string $type, string $data): string {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

$where = 'Gartenweg 12, 4020 Linz';

case_('A photo is stored without where it was taken, and still the right way up');
$phone = jpeg_from_a_phone(6, $where);
$clean = image_without_metadata($phone, 'image/jpeg');
ok(substr_count($phone, $where) === 3, 'the fixture carries the address three times, as EXIF, XMP and IPTC');
ok(!str_contains($clean, 'Gartenweg'), 'none of them is left');
ok(!str_contains($clean, pack('v', 0x8825)), 'nor the pointer to its GPS block');
ok(str_contains($clean, 'ICC_PROFILE'), 'the colour profile stays, so the colours do not change');
ok(str_ends_with($clean, substr($phone, (int)strpos($phone, "\xFF\xDA"))), 'the picture itself is copied byte for byte');
$size = getimagesizefromstring($clean);
is_same([600, 150, IMAGETYPE_JPEG], $size ? [$size[0], $size[1], $size[2]] : null, 'and still reads as the same JPEG');
if (function_exists('exif_read_data')) {
    is_same(6, exif_as_php_reads_it($phone)['Orientation'] ?? null, 'PHP reads the phone’s orientation from the fixture');
    ok(isset(exif_as_php_reads_it($phone)['GPSLatitudeRef']), 'and its GPS block, so the next lines prove something');
    $after = exif_as_php_reads_it($clean);
    is_same(6, $after['Orientation'] ?? null, 'a photo taken upright on an iPhone is still shown upright');
    ok(!isset($after['GPSLatitudeRef']) && !isset($after['ImageDescription']), 'and PHP finds no place and no description in it');
} else {
    test_unsupported(array_merge(test_unsupported(), ['uploads: PHP has no exif extension here, so the orientation was read only by the portal’s own reader']));
}
is_same(6, exif_orientation(substr($clean, (int)strpos($clean, "Exif\0\0") + 6)), 'the portal’s own reader agrees');
$upright = image_without_metadata(jpeg_from_a_phone(1, $where), 'image/jpeg');
ok(!str_contains($upright, "Exif\0\0"), 'a photo that is the right way up already keeps no EXIF at all');

case_('A PNG and a WebP lose their text and EXIF too');
$pngIn = substr(png_header(600, 150), 0, -12)
       . png_chunk('tEXt', "Location\0" . $where) . png_chunk('iTXt', "Location\0\0\0\0\0" . $where)
       . png_chunk('zTXt', "Location\0\0" . gzcompress($where)) . png_chunk('eXIf', "MM\0*" . $where)
       . png_chunk('IDAT', "\x78\x9C\x63\x00\x00\x00\x01\x00\x01") . png_chunk('IEND', '');
$pngOut = image_without_metadata($pngIn, 'image/png');
ok(!str_contains($pngOut, 'Gartenweg') && !preg_match('/tEXt|iTXt|zTXt|eXIf/', $pngOut), 'the PNG keeps no text and no EXIF');
is_same(png_header(600, 150), substr($pngOut, 0, 33) . png_chunk('IEND', ''), 'its header is untouched');
ok(str_contains($pngOut, png_chunk('IDAT', "\x78\x9C\x63\x00\x00\x00\x01\x00\x01")) && str_ends_with($pngOut, png_chunk('IEND', '')), 'and so is the picture');
$riffChunk = fn(string $type, string $data): string => $type . pack('V', strlen($data)) . $data . (strlen($data) % 2 ? "\0" : '');
$webpIn = substr(webp_header(600, 150), 0, 20) . "\x0C" . substr(webp_header(600, 150), 21)
        . $riffChunk('ICCP', str_repeat("\x2A", 16)) . $riffChunk('VP8 ', "\x10\x02\x00\x9D\x01\x2A\x58\x02\x96\x00\x01")
        . $riffChunk('EXIF', "MM\0*" . $where) . $riffChunk('XMP ', '<photoshop:City>' . $where . '</photoshop:City>');
$webpIn = 'RIFF' . pack('V', strlen($webpIn) - 8) . substr($webpIn, 8);
$webpOut = image_without_metadata($webpIn, 'image/webp');
ok(!str_contains($webpOut, 'Gartenweg') && !str_contains($webpOut, 'EXIF') && !str_contains($webpOut, 'XMP '), 'the WebP keeps neither');
is_same(0, ord($webpOut[20]) & 0x0C, 'and its header no longer says they follow');
is_same(strlen($webpOut) - 8, unpack('V', substr($webpOut, 4, 4))[1], 'its length is its new length');
ok(str_contains($webpOut, 'ICCP') && str_contains($webpOut, $riffChunk('VP8 ', "\x10\x02\x00\x9D\x01\x2A\x58\x02\x96\x00\x01")), 'the colour profile and the picture stay');
$size = getimagesizefromstring($webpOut);
is_same([600, 150, IMAGETYPE_WEBP], $size ? [$size[0], $size[1], $size[2]] : null, 'and it still reads as the same WebP');

case_('A file that does not read the way its format says is kept as it came');
/* Better a photo with its EXIF than a photo nobody can open. */
foreach ([['image/jpeg', substr($phone, 0, 60), 'a JPEG cut off inside a segment'],
          ['image/jpeg', "\xFF\xD8" . jpeg_segment(0xE1, "Exif\0\0") . "\xFF\xD9", 'a JPEG with no picture in it'],
          ['image/png', substr($pngIn, 0, -2), 'a PNG cut off inside its last chunk'],
          ['image/webp', substr($webpIn, 0, -7), 'a WebP cut off inside a chunk'],
          ['image/gif', "GIF89a" . $where, 'a GIF, which can carry a comment and XMP but is not what a camera writes a photo as'],
          ['application/pdf', "%PDF-1.4\n" . $where, 'and anything that is not a picture']] as [$mime, $bytes, $what])
    is_same($bytes, image_without_metadata($bytes, $mime), $what);

case_('What is stored is the cleaned picture, not the one that arrived');
/* store_upload() takes only what came through a form, which a test cannot send,
   so this pins the lines that do it; TESTING.md has the check with a real phone. */
$store = (string)strstr((string)strstr((string)file_get_contents(APP_ROOT.'/app/uploads.php'), 'function store_upload('), 'function image_without_metadata(', true);
ok(str_contains($store, 'image_without_metadata((string)file_get_contents((string)$file[\'tmp_name\']), $mime)'), 'every picture goes through image_without_metadata()');
ok(str_contains($store, '@file_put_contents($path, $clean, LOCK_EX) === strlen($clean)'), 'and the cleaned copy is what is written, all of it or nothing');
ok(str_contains($store, "\$clean !== '' && @file_put_contents("),
   'and an empty copy - a picture that could not be read - is never stored as though it were the picture');
ok(str_contains($store, "'bytes' => \$clean === null ? (int)\$file['size'] : strlen(\$clean)"), 'and its own size is recorded');

case_('A JPEG keeps what is on its list, and nothing after the end of its picture');
/* What a phone writes besides EXIF, XMP and IPTC: a comment, other APPn
   segments, the index of the pictures that follow (MPF) - and after the end of
   the first picture a second one, or a motion photo's video, with EXIF of its
   own. A stray byte between two segments, TEM and a restart outside a scan and
   fill bytes in front of a marker are passed over, the way a decoder passes over
   them, rather than being a reason to keep the file as it came. */
$sof = (int)strpos($phone, "\xFF\xC0");
$messy = substr($phone, 0, $sof)
       . jpeg_segment(0xFE, 'Aufgenommen in ' . $where)
       . jpeg_segment(0xEB, "JP\0\x01" . $where)
       . jpeg_segment(0xE2, "MPF\0MM\0*" . $where)
       . "\x00" . "\xFF\x01" . "\xFF\xD3" . "\xFF\xFF"
       . substr($phone, $sof)
       . jpeg_from_a_phone(1, $where);
$tidied = image_without_metadata($messy, 'image/jpeg');
is_same(9, substr_count($messy, $where), 'the fixture carries the address nine times: three in the photo, in its comment, APP11 and MPF, and three in the picture after it');
ok(!str_contains($tidied, 'Gartenweg'), 'none of them is left');
is_same(strlen($tidied) - 2, strpos($tidied, "\xFF\xD9"), 'and nothing follows the end of the first picture');
is_same($clean, $tidied, 'what is left is exactly what is left of the photo without them');
$size = getimagesizefromstring($tidied);
is_same([600, 150, IMAGETYPE_JPEG], $size ? [$size[0], $size[1], $size[2]] : null, 'and it still reads as the same JPEG');
is_same(6, exif_orientation(substr($tidied, (int)strpos($tidied, "Exif\0\0") + 6)), 'the right way up');

case_('A progressive JPEG keeps every scan and the tables between them, and nothing else there');
$dht = fn(int $table): string => jpeg_segment(0xC4, chr($table) . "\x01" . str_repeat("\x00", 15) . "\x05");
$frame = "\xFF\xD8" . jpeg_segment(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
       . jpeg_segment(0xDB, "\x00" . str_repeat("\x01", 64))
       . jpeg_segment(0xC2, "\x08" . pack('nn', 150, 600) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01")
       . jpeg_segment(0xDD, pack('n', 4));
// Each scan with a stuffed zero and a restart in its data: both are part of it.
$firstScan  = jpeg_segment(0xDA, "\x03\x01\x00\x02\x11\x03\x11\x00\x00\x00") . "\x11\xFF\x00\x22\xFF\xD0\x33";
$secondScan = jpeg_segment(0xDA, "\x01\x01\x10\x01\x3F\x00") . "\x44\xFF\x00\x55\xFF\xD1\x66";
$progressive = $frame . $dht(0x00) . $firstScan . "\xFF\xFF" . jpeg_segment(0xFE, $where) . $dht(0x10) . $secondScan . "\xFF\xD9";
is_same($frame . $dht(0x00) . $firstScan . $dht(0x10) . $secondScan . "\xFF\xD9", image_without_metadata($progressive, 'image/jpeg'),
        'both scans with all their data and the table between them, byte for byte; the comment between them goes');
$size = getimagesizefromstring(image_without_metadata($progressive, 'image/jpeg'));
is_same([600, 150, IMAGETYPE_JPEG], $size ? [$size[0], $size[1], $size[2]] : null, 'and it reads as the same JPEG');

case_('A JPEG cut off in its picture data keeps that data, cleaned of what came before');
$cut = substr($phone, 0, -2);
ok(!str_contains($cut, "\xFF\xD9"), 'the fixture has lost its end, so its scan never reaches a marker');
is_same(substr($clean, 0, -2), image_without_metadata($cut, 'image/jpeg'), 'the header is cleaned and the picture data kept as it came');

case_('A PNG keeps only the chunks on its list');
$listed = png_chunk('gAMA', pack('N', 45455)) . png_chunk('pHYs', pack('NNC', 2835, 2835, 1))
        . png_chunk('iCCP', "Display P3\0\0" . gzcompress('profil'));
$picture = png_chunk('IDAT', "\x78\x9C\x63\x00\x00\x00\x01\x00\x01") . png_chunk('IEND', '');
$pngMessy = substr(png_header(600, 150), 0, -12) . png_chunk('tIME', pack('nCCCCC', 2026, 10, 5, 14, 30, 0)) . $listed
          . png_chunk('caBX', 'jumb' . $where) . png_chunk('prVt', $where) . $picture;
$pngTidied = image_without_metadata($pngMessy, 'image/png');
ok(!str_contains($pngTidied, 'Gartenweg') && !preg_match('/tIME|caBX|prVt/', $pngTidied),
   'its time, its content credentials and a chunk private to some program are gone');
is_same(substr(png_header(600, 150), 0, -12) . $listed . $picture, $pngTidied, 'and every chunk on the list is there as it was, in its order');

case_('A WebP keeps only the chunks on its list, and nothing past the length its RIFF header gives');
// Behind the RIFF length: a chunk of a kind the list keeps, so only that length
// can be what leaves it out.
$inside = substr($webpIn, 12) . $riffChunk('Xtra', $where);
$webpMessy = 'RIFF' . pack('V', 4 + strlen($inside)) . 'WEBP' . $inside . $riffChunk('ICCP', $where);
$webpTidied = image_without_metadata($webpMessy, 'image/webp');
ok(!str_contains($webpTidied, 'Gartenweg') && !str_contains($webpTidied, 'Xtra'), 'a chunk not on the list goes, and so does what follows the RIFF length');
is_same($webpOut, $webpTidied, 'what is left is exactly what is left of the WebP without them');

case_('A length of 2^31 or more is never walked');
/* unpack('N') and unpack('V') give such a length as a negative number on 32-bit
   PHP, and walked as one it would go backwards through the file for ever. */
ok(!chunk_fits(8, -1, 100) && !chunk_fits(8, PHP_INT_MIN, 100), 'a negative length fits nowhere');
ok(chunk_fits(8, 92, 100) && !chunk_fits(8, 93, 100), 'a length fits exactly as far as the end, and no further');
$huge = substr(png_header(600, 150), 0, -12) . pack('N', 0xFFFFFFFF) . 'tEXt' . $where . png_chunk('IEND', '');
is_same($huge, image_without_metadata($huge, 'image/png'), 'a PNG whose chunk says 2^32 - 1 bytes is kept as it came');
$hugeWebp = 'RIFF' . pack('V', 0xFFFFFFF0) . 'WEBP' . substr($webpIn, 12);
is_same($hugeWebp, image_without_metadata($hugeWebp, 'image/webp'), 'and so is a WebP whose RIFF header says as much');
