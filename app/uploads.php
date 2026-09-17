<?php
declare(strict_types=1);

/**
 * Files people send: payment proofs, message attachments, profile pictures.
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

/** That limit as a person would say it. */
function upload_limit_label(): string {
    $bytes = upload_limit();
    return $bytes >= 1024 * 1024
        ? number_format($bytes / 1048576, 1, locale() === 'de' ? ',' : '.', '') . ' MB'
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
 * Send a stored file to the browser, having decided the caller may have it.
 *
 * Content-Disposition is attachment for everything except images, and the type
 * is the one recorded at upload rather than guessed again, so a file cannot be
 * served as something it is not.
 */
function send_upload(string $kind, string $storedName, string $mime, string $downloadName = ''): never {
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
                          !str_starts_with($mime, 'image/'), (int)filesize($path));
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
function send_download_headers(string $mime, string $name, bool $asAttachment, int $length): void {
    // The name goes into a header, so anything that could end the header or
    // start a second one is removed rather than escaped.
    $safe = preg_replace('/[^\w .()\-]+/u', '_', $name) ?: 'download';
    header('Content-Type: ' . (preg_match('#^[\w.+-]+/[\w.+-]+$#D', $mime) ? $mime : 'application/octet-stream'));
    header('Content-Disposition: ' . ($asAttachment ? 'attachment' : 'inline') . '; filename="' . $safe . '"');
    header('Content-Length: ' . $length);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
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
        // A picture is shown to everybody who can already see the person's name,
        // which on this portal is everybody signed in: a family sees the
        // trainer, the trainer sees the children, and a child sees their own.
        require_user();
        $kind = ($_GET['kind'] ?? '') === 'student' ? 'students' : 'accounts';
        $name = (string)(scalar('SELECT avatar_name FROM ' . $kind . ' WHERE id=?', [$id]) ?: '');
        // The type comes back from the same table the extension was chosen
        // from, so a file is never announced as something it is not.
        $mime = array_search(pathinfo($name, PATHINFO_EXTENSION), upload_types('avatar'), true);
        if ($name !== '' && $mime !== false) send_upload('avatar', $name, (string)$mime);
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
