<?php
declare(strict_types=1);

/**
 * Files people send: payment proofs, message attachments, profile pictures,
 * and the portal's own icon and logo.
 *
 * Three rules hold for all of them.
 *
 * Nothing is trusted from the browser. The name, the declared type and the size
 * in the form all come from whoever is uploading, so the type is read from the
 * bytes and the name is thrown away in favour of a random one. A file called
 * "beleg.php" cannot become a file called "beleg.php" on disk.
 *
 * Nothing is reachable by URL. Uploads live under storage/, which denies itself
 * over the web, and are served by a request that checks who is asking first.
 *
 * The limit is one the server can actually honour. PHP refuses an upload larger
 * than upload_max_filesize before a single line of this code runs, and a form
 * that promises 20 MB on a server configured for 8 fails in a way that looks
 * like the portal is broken. So the configured limit is clamped to what PHP
 * allows and the interface states the real number.
 */

/** Where uploads live, per kind, under storage/. */
function upload_dir(string $kind): string {
    $kind = preg_match('/^[a-z]+$/D', $kind) ? $kind : 'other';
    return dirname(maintenance_file()) . '/uploads/' . $kind;
}

/** Bytes, from a php.ini shorthand like "8M". */
function ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $n = (int)$value;
    return match ($unit) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
}

/**
 * The largest upload this server will really accept, in bytes.
 *
 * The smallest of what the operator asked for and what PHP is configured to
 * take. post_max_size counts as well, because a 7 MB file inside an 8 MB post
 * limit still arrives with form fields around it.
 */
function upload_limit(): int {
    $wanted = max(1, (int)setting('upload_max_kb')) * 1024;
    $php = array_filter([ini_bytes((string)ini_get('upload_max_filesize')),
                         // Leave room for the rest of the form.
                         ini_bytes((string)ini_get('post_max_size')) - 64 * 1024]);
    return $php ? min($wanted, (int)min($php)) : $wanted;
}

/**
 * A size in megabytes as a person reads it: „1,0 MB" or "1.0 MB", one decimal.
 *
 * The one formatter, so the limit a form states and the size a refusal names
 * read alike. $roundUp is for a measured size set against a limit: a file one
 * byte over 1 MB must read „1,1 MB", not the „1,0 MB" it would round to and
 * which is exactly what the form allows.
 */
function megabytes_label(int $bytes, bool $roundUp = false): string {
    $mb = $bytes / 1048576;
    if ($roundUp) $mb = ceil(round($mb * 10, 6)) / 10;
    return number_format($mb, 1, locale() === 'de' ? ',' : '.', '') . ' MB';
}

/**
 * That limit as a person would say it.
 *
 * $cap is a kind's own smaller limit, such as PORTAL_LOGO_MAX_BYTES for the
 * logo that every sign-in page loads: the label is then the smaller of the two,
 * so a form never promises more than its check will accept.
 */
function upload_limit_label(?int $cap = null): string {
    $bytes = $cap !== null ? min(upload_limit(), max(1, $cap)) : upload_limit();
    return $bytes >= 1024 * 1024
        ? megabytes_label($bytes)
        : (int)round($bytes / 1024) . ' kB';
}

/** Whether the operator's setting is larger than the server will honour. */
function upload_limit_capped(): bool {
    return max(1, (int)setting('upload_max_kb')) * 1024 > upload_limit();
}

/** What each kind of upload is allowed to be, as media type => extension. */
function upload_types(string $kind): array {
    $images = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    return match ($kind) {
        'avatar' => $images,
        'proof'  => $images + ['application/pdf' => 'pdf'],
        // PNG and nothing else: an iPhone takes its home-screen icon only as a
        // PNG, and one format for the tab, iOS and Android means no guessing
        // which browser takes what. check_portal_icon() then reads its size.
        'icon'   => ['image/png' => 'png'],
        // The logo may be wide and may be a photograph, so JPEG and WebP too;
        // not GIF, which animates, and never SVG, which can carry script (ADR 0014).
        'logo'   => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'],
        // A message may carry a picture, a document or a voice note. webm and
        // mp4 are what a browser's own recorder produces; the rest are what
        // somebody's phone hands over when they pick an existing file.
        default  => $images + ['application/pdf' => 'pdf', 'audio/webm' => 'webm', 'audio/mp4' => 'm4a',
                               'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav',
                               'video/webm' => 'webm', 'text/plain' => 'txt'],
    };
}

/**
 * Why an upload did not arrive, in words rather than a PHP constant.
 *
 * UPLOAD_ERR_INI_SIZE is the one worth naming exactly: it means the server said
 * no before the portal saw anything, and telling somebody "try a smaller
 * picture" is the only useful response.
 */
function upload_error_message(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            t('Die Datei ist zu groß. Erlaubt sind ', 'That file is too big. The limit is ') . upload_limit_label() . '.',
        UPLOAD_ERR_PARTIAL => t('Die Datei kam nur teilweise an. Bitte noch einmal versuchen.', 'The file only arrived partly. Please try again.'),
        UPLOAD_ERR_NO_FILE => t('Es wurde keine Datei ausgewählt.', 'No file was chosen.'),
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
            t('Der Server kann die Datei nicht speichern. Bitte den Administrator fragen.', 'The server cannot store the file. Please ask the administrator.'),
        default => t('Die Datei konnte nicht hochgeladen werden.', 'The file could not be uploaded.'),
    };
}

/**
 * Take one uploaded file and store it.
 *
 * Returns what belongs in a database row. Throws UserError with something a
 * person can act on for every way this can fail, because "upload failed" on a
 * phone at the side of a court is the end of the attempt.
 */
function store_upload(string $field, string $kind): array {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || !isset($file['error'])) throw new UserError(t('Es wurde keine Datei ausgewählt.', 'No file was chosen.'));
    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new UserError(upload_error_message((int)$file['error']));
    if (!is_uploaded_file((string)$file['tmp_name'])) throw new UserError(t('Diese Datei kam nicht über das Formular.', 'That file did not come through the form.'));
    if ((int)$file['size'] <= 0) throw new UserError(t('Die Datei ist leer.', 'That file is empty.'));
    if ((int)$file['size'] > upload_limit())
        throw new UserError(t('Die Datei ist zu groß. Erlaubt sind ', 'That file is too big. The limit is ') . upload_limit_label() . '.');

    // The type comes from the bytes, never from what the browser said it was.
    $mime = function_exists('mime_content_type') ? (string)@mime_content_type((string)$file['tmp_name']) : '';
    $allowed = upload_types($kind);
    if (!isset($allowed[$mime]))
        throw new UserError(t('Dieser Dateityp ist hier nicht erlaubt. Möglich sind: ', 'That kind of file is not allowed here. Allowed: ')
            . implode(', ', array_unique(array_values($allowed))) . '.');

    $dir = upload_dir($kind);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
        throw new UserError(t('Der Ordner für Uploads lässt sich nicht anlegen. Bitte Schreibrechte für storage/ prüfen.',
                              'The upload folder cannot be created. Check that storage/ is writable.'));
    $stored = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored))
        throw new UserError(t('Die Datei konnte nicht gespeichert werden.', 'The file could not be stored.'));

    return ['stored_name' => $stored, 'mime' => $mime, 'bytes' => (int)$file['size'],
            'original_name' => mb_substr((string)($file['name'] ?? ''), 0, 255)];
}

/** Remove a stored file. Missing is not an error; the row is going either way. */
function delete_upload(string $kind, string $storedName): void {
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/D', $storedName)) return;
    @unlink(upload_dir($kind) . '/' . $storedName);
}

/**
 * Where each kind of upload is pointed at from.
 *
 * One list, used by the sweep below. A new kind of upload that is not named
 * here keeps its files for ever; a new kind named here but queried wrongly
 * would delete files that are still in use, which is why each query is the
 * plain "every name this table still holds" and nothing cleverer.
 */
function upload_references(): array {
    return [
        // Screenshots from a problem report are stored beside the pictures,
        // because they are pictures and the same types are allowed.
        'avatar'  => ["SELECT avatar_name AS name FROM accounts WHERE avatar_name<>''",
                      "SELECT avatar_name AS name FROM students WHERE avatar_name<>''",
                      "SELECT screenshot_name AS name FROM feedback WHERE screenshot_name<>''"],
        'proof'   => ['SELECT stored_name AS name FROM payment_proofs'],
        'message' => ['SELECT stored_name AS name FROM message_files'],
        // A setting is stored as JSON, so the name sits inside quotes. Compared
        // with the quotes still on, nothing would match and the live icon would
        // be swept an hour after it was uploaded. REPLACE(x,y,z) is spelled the
        // same in MariaDB, MySQL and SQLite.
        'icon'    => ["SELECT REPLACE(setting_value,'\"','') AS name FROM settings WHERE setting_key='portal_icon'"],
        'logo'    => ["SELECT REPLACE(setting_value,'\"','') AS name FROM settings WHERE setting_key='portal_logo'"],
    ];
}

/**
 * Remove uploaded files that no record points at any more.
 *
 * A deleted account takes its conversations with it, a deleted child takes
 * their photo, and a database row can go without anything touching the disk -
 * so without this, a family who asked to be forgotten leaves their voice notes
 * and photographs behind in storage, and the folder only ever grows.
 *
 * Deliberately conservative: a file younger than the grace period is left
 * alone, because it may belong to a row being written in another request right
 * now, and deleting somebody's photograph a second after they uploaded it is a
 * worse failure than keeping one too long.
 */
function prune_uploads(int $graceSeconds = 3600): int {
    $removed = 0;
    $cutoff = time() - max(60, $graceSeconds);
    foreach (upload_references() as $kind => $queries) {
        $files = glob(upload_dir($kind) . '/*') ?: [];
        if (!$files) continue;
        $kept = [];
        foreach ($queries as $sql)
            foreach (rows($sql) as $row) $kept[(string)$row['name']] = true;
        foreach ($files as $path) {
            if (!is_file($path) || isset($kept[basename($path)])) continue;
            if ((int)@filemtime($path) > $cutoff) continue;
            if (@unlink($path)) $removed++;
        }
    }
    return $removed;
}

/**
 * How a download is cached unless its caller knows better: not at all.
 *
 * An invoice, a payment proof or a message attachment is one family's
 * business, and a phone shared in the family keeps what its browser keeps.
 */
const DOWNLOAD_CACHE_CONTROL = 'private, no-store';

/**
 * Send a stored file to the browser, having decided the caller may have it.
 *
 * Content-Disposition is attachment for everything except images, and the type
 * is the one recorded at upload rather than guessed again, so a file cannot be
 * served as something it is not.
 */
function send_upload(string $kind, string $storedName, string $mime, string $downloadName = '',
                     string $cacheControl = DOWNLOAD_CACHE_CONTROL): never {
    $path = upload_dir($kind) . '/' . $storedName;
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/D', $storedName) || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit(t('Diese Datei gibt es nicht mehr.', 'That file is no longer here.') . "\n");
    }
    // Streamed rather than read into a string first: shared hosting sets
    // memory_limit as low as 64 MB, and a voice note plus whatever else the
    // request is holding should not be what decides whether a file can be
    // downloaded at all.
    send_download_headers($mime, $downloadName !== '' ? $downloadName : $storedName,
                          !str_starts_with($mime, 'image/'), (int)filesize($path), $cacheControl);
    readfile($path);
    exit;
}

/** Send bytes we hold in memory, such as a freshly built PDF. */
function send_bytes(string $body, string $mime, string $name, bool $asAttachment = true): never {
    send_download_headers($mime, $name, $asAttachment, strlen($body));
    echo $body;
    exit;
}

/** The headers both of those need, written once so they cannot drift apart. */
function send_download_headers(string $mime, string $name, bool $asAttachment, int $length,
                               string $cacheControl = DOWNLOAD_CACHE_CONTROL): void {
    // The name goes into a header, so anything that could end the header or
    // start a second one is removed rather than escaped.
    $safe = preg_replace('/[^\w .()\-]+/u', '_', $name) ?: 'download';
    header('Content-Type: ' . (preg_match('#^[\w.+-]+/[\w.+-]+$#D', $mime) ? $mime : 'application/octet-stream'));
    header('Content-Disposition: ' . ($asAttachment ? 'attachment' : 'inline') . '; filename="' . $safe . '"');
    header('Content-Length: ' . $length);
    header('X-Content-Type-Options: nosniff');
    send_cache_control($cacheControl);
}

/**
 * How long the club's own assets may be kept: a year.
 *
 * For the portal's icon, its logo and its colours - the club's, not a
 * family's - each served at an address that changes when it does, so a year
 * risks nothing stale.
 */
const CLUB_ASSET_MAX_AGE = 31536000;

/**
 * Cache-Control for a reply any cache may keep - unless it starts a session.
 *
 * A request that arrives without a cookie gets a new session and a Set-Cookie
 * with its reply, and a shared cache may store that header with the body
 * (RFC 9111) and hand one session to everybody behind it. Such a reply is kept
 * by the browser alone.
 */
function shared_cache_control(int $seconds, bool $immutable = false, ?array $sentHeaders = null): string {
    $setsCookie = false;
    foreach ($sentHeaders ?? headers_list() as $header)
        if (stripos((string)$header, 'set-cookie:') === 0) $setsCookie = true;
    return ($setsCookie ? 'private' : 'public') . ', max-age=' . $seconds . ($immutable ? ', immutable' : '');
}

/**
 * Replace the no-store every response starts with.
 *
 * boot_http() sends no-store, and the session sends its own Cache-Control with
 * Expires and Pragma beside it; header() replaces the first two but not the
 * others. A reply that may be kept should not carry headers saying the
 * opposite, so they go - and only then: for no-store or no-cache they agree.
 */
function send_cache_control(string $value): void {
    header('Cache-Control: ' . $value);
    if (!str_contains($value, 'max-age=')) return;
    header_remove('Expires');
    header_remove('Pragma');
}

/**
 * The part of a stored file's address that changes when the file does.
 *
 * store_upload() gives every file a new random name, so the name already is a
 * version and needs no setting of its own. Twelve characters are plenty to
 * tell uploads apart and give nothing away that the address needs to hide.
 */
function upload_version(string $storedName): string { return substr($storedName, 0, 12); }

/** Whether a requested address names the file stored now, not one it replaced. */
function upload_version_current(string $storedName, mixed $requestedVersion): bool {
    return $storedName !== '' && is_string($requestedVersion) && $requestedVersion === upload_version($storedName);
}

/**
 * How long a browser may keep the profile picture it is being sent.
 *
 * Profile pictures sit in the top bar of every page, and without this each
 * page change fetched the same picture again. At the address of the picture in
 * use it is kept: a new picture gets a new address and a removed one falls back
 * to initials, so nothing stale is shown from the cache. An old address is
 * never kept, so it cannot be remembered as the new picture.
 *
 * Always private - a child's photograph is never for a shared cache. Seven
 * days rather than a year, and not immutable: after signing out, the picture
 * stays in that browser's own cache (logout asks the browser to clear it, but
 * not every browser does), and on a borrowed phone that copy should run out on
 * its own within a week. A week is still far longer than a visit, which is all
 * it takes to stop the reload on every page. The route still asks who is
 * signed in, and whether they may see this picture, before it reads a byte.
 */
function avatar_cache_control(string $storedName, mixed $requestedVersion): string {
    return upload_version_current($storedName, $requestedVersion)
        ? 'private, max-age=604800'
        : DOWNLOAD_CACHE_CONTROL;
}

/**
 * The stored picture the signed-in person asked for, having checked they may
 * see it: '' when there is none, NotFound when there is nobody they may see.
 *
 * A child's picture goes through student(), which is how every page finds a
 * child, so a family reaches their own children's and staff reach all. An
 * account's goes through may_see_account_picture(), the rule avatar() draws by.
 * Somebody who is not there and somebody who may not be seen are the same 404,
 * so the address cannot be used to find out which ids exist.
 */
function avatar_for_download(string $kind, int $id): string {
    $viewer = require_user();
    if ($kind === 'student') return (string)student($id)['avatar_name'];
    $account = one('SELECT id, role, avatar_name FROM accounts WHERE id=?', [$id]);
    if (!$account || !may_see_account_picture($viewer, $account))
        throw new NotFound(t('Dieses Bild gibt es nicht.', 'There is no such picture.'));
    return (string)$account['avatar_name'];
}

/**
 * Serve whatever ?page=download was asked for.
 *
 * Every branch decides who may have the file before it reads a byte, using the
 * same lookups the pages use - invoice() and student() both scope themselves to
 * the signed-in account - so a download cannot be the one place the rule was
 * forgotten. Anything it cannot serve falls through to views/download.php, which
 * says so in words.
 */
function serve_download(): void {
    $what = is_scalar($_GET['what'] ?? '') ? (string)($_GET['what'] ?? '') : '';
    $id = (int)($_GET['id'] ?? 0);
    if ($what === 'invoice') {
        $invoice = invoice($id);
        send_bytes(invoice_pdf($invoice), 'application/pdf', invoice_filename($invoice));
    }
    if ($what === 'avatar') {
        // Who may have which picture is avatar_for_download()'s to decide;
        // anybody else gets its 404.
        $name = avatar_for_download(($_GET['kind'] ?? '') === 'student' ? 'student' : 'account', $id);
        // The type comes back from the same table the extension was chosen
        // from, so a file is never announced as something it is not.
        $mime = array_search(pathinfo($name, PATHINFO_EXTENSION), upload_types('avatar'), true);
        // An address with an older version is still answered, uncached, rather
        // than refused: a page drawn a moment before the picture was replaced -
        // or a lazy picture fetched when scrolled to much later - would
        // otherwise show a broken image instead of the new picture.
        if ($name !== '' && $mime !== false)
            send_upload('avatar', $name, (string)$mime, '', avatar_cache_control($name, $_GET['v'] ?? null));
    }
    if ($what === 'shot') {
        require_admin();
        $report = one('SELECT * FROM feedback WHERE id=?', [$id]);
        if ($report && $report['screenshot_name'] !== '') {
            $mime = array_search(pathinfo((string)$report['screenshot_name'], PATHINFO_EXTENSION), upload_types('avatar'), true);
            if ($mime !== false) send_upload('avatar', (string)$report['screenshot_name'], (string)$mime);
        }
    }
    if ($what === 'attachment') {
        $file = one('SELECT f.*, m.thread_id FROM message_files f JOIN messages m ON m.id=f.message_id WHERE f.id=?', [$id]);
        // thread_record() refuses a conversation this account may not read, so
        // an attachment cannot be the way round the rule about who reads what.
        if ($file) {
            thread_record((int)$file['thread_id']);
            send_upload('message', (string)$file['stored_name'], (string)$file['mime'],
                        (string)($file['original_name'] ?: $file['stored_name']));
        }
    }
    if ($what === 'proof') {
        $proof = one('SELECT * FROM payment_proofs WHERE id=?', [$id]);
        if ($proof) {
            // student() refuses a child that does not belong to the caller.
            student((int)$proof['student_id']);
            send_upload('proof', (string)$proof['stored_name'], (string)$proof['mime'],
                        (string)($proof['original_name'] ?: $proof['stored_name']));
        }
    }
}
