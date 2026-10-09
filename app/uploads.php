<?php
declare(strict_types=1);

/**
 * Files people send: payment proofs, photos in a chat, problem reports'
 * screenshots, the children's and the team's pictures, and the portal's own
 * icon and logo.
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

/** What each kind of upload is allowed to be, as media type => extension. Every kind is named. */
function upload_types(string $kind): array {
    $images = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    return match ($kind) {
        // The problem reports' screenshots. The folder keeps the name it had while
        // it held profile pictures as well (ADR 0026 §8), so every screenshot
        // stored before is found where it is.
        'avatar' => $images,
        'proof'  => $images + ['application/pdf' => 'pdf'],
        // A photo in a chat, and which photos depend on who sends it (ADR 0022 §11.4).
        'message' => message_upload_types(current_user()),
        // PNG and nothing else: an iPhone takes its home-screen icon only as a
        // PNG, and one format for the tab, iOS and Android means no guessing
        // which browser takes what. check_portal_icon() then reads its size.
        'icon'   => ['image/png' => 'png'],
        // The logo may be wide and may be a photograph, so JPEG and WebP too;
        // not GIF, which animates, and never SVG, which can carry script (ADR 0014).
        'logo'   => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'],
        // The photos a picture is made from, a child's or a team member's
        // (ADR 0031 §3); what is stored is square_picture()'s JPEG.
        'picture' => picture_types(),
        // A kind nobody named takes nothing. A list that fell open here once
        // gave any new kind every type there was, PDFs and audio included.
        default  => throw new LogicException('No upload kind named „' . $kind . '“ in upload_types().'),
    };
}

/**
 * The type of an uploaded file, read from its bytes, never from what the
 * browser said it was - or a refusal when this PHP cannot read a type at all.
 * Without the fileinfo extension (extension_checks()) every upload would be
 * refused with a sentence about the sender's file that is not true, so they are
 * told it is the portal's: an administrator where to look, anybody else to tell
 * the club. $readable is whether a type can be read; the suite passes false,
 * to watch the refusal without taking anything away from the PHP it runs on.
 */
function uploaded_file_type(string $path, ?bool $readable = null): string {
    if (!($readable ?? function_exists('mime_content_type')))
        throw new UserError(is_admin()
            ? t('Das Portal kann gerade keine Dateien annehmen; unter Einstellungen → System steht, was dem Server fehlt.',
                'The portal cannot take files right now; Einstellungen → System says what the server is missing.')
            : t('Das Portal kann gerade keine Dateien annehmen. Bitte gib dem Verein Bescheid.',
                'The portal cannot take files right now. Please let the club know.'));
    return (string)@mime_content_type($path);
}

/**
 * The extension a file of $mime is stored under as $kind, or the refusal, in
 * words the person sending it can act on.
 *
 * A child's photo in the chat comes from the camera (chat_photo_from_camera(),
 * the one rule for it), so a list of file types tells them nothing; what to do
 * instead does. Staff, who may pick from the gallery, are told which kinds are
 * possible.
 */
function upload_extension(string $kind, string $mime): string {
    $allowed = upload_types($kind);
    if (isset($allowed[$mime])) return $allowed[$mime];
    if ($kind === 'message' && chat_photo_from_camera(current_user()))
        throw new UserError(t('Bitte nimm das Foto mit der Kamera auf.', 'Please take the photo with the camera.'));
    if ($kind === 'picture') throw new UserError(picture_unreadable());
    throw new UserError(t('Dieser Dateityp ist hier nicht erlaubt. Möglich sind: ', 'That kind of file is not allowed here. Allowed: ')
        . implode(', ', array_unique(array_values($allowed))) . '.');
}

/**
 * A file over the limit, refused with the limit in it: one sentence wherever it
 * is caught - by the portal (store_upload()), by the server for the one file
 * (upload_max_filesize), or by the server for the whole form (post_max_size,
 * public/index.php). That last throws the form away before the portal sees
 * which field the file was in, and a child's page takes a receipt as well as
 * the child's picture, so no path says „Foto": the same photo was „Das Foto ist
 * zu groß" at one size and „Die Datei ist zu groß" at another. „Höchstens" is
 * the word the file field's own hint uses (file_field()).
 */
function upload_too_large(): string {
    return t('Die Datei ist zu groß. Höchstens ', 'That file is too big. At most ') . upload_limit_label() . '.';
}

/**
 * Whether this request is a form PHP threw away for being larger than
 * post_max_size. PHP then empties $_POST and $_FILES - the token, the action
 * and the file all gone - and only CONTENT_LENGTH says what came, so the token
 * check would call a photo too large an expired session. Asked once, where a
 * post enters (public/index.php), before the token; a post_max_size of 0 is no
 * limit at all.
 */
function post_too_large(): bool {
    $limit = ini_bytes((string)ini_get('post_max_size'));
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $_POST === [] && $_FILES === []
        && $limit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit;
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
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => upload_too_large(),
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
    if ((int)$file['size'] > upload_limit()) throw new UserError(upload_too_large());

    $mime = uploaded_file_type((string)$file['tmp_name']);
    $extension = upload_extension($kind, $mime);

    // A photo can carry where it was taken - a family's home - and a picture
    // posted to a course's group reaches every child in it (ADR 0022). So a
    // picture is stored as its cleaned copy, written once; anything else is
    // moved as it came. A picture of a child or of a team member is not the
    // photo at all but the small square square_picture() makes of it (ADR
    // 0031 §3). Made before the
    // folder and the name, so a photo refused leaves nothing behind. The
    // upload was not empty, so an empty copy means the file could not be read,
    // and storing it would keep nothing of the picture while saying it had
    // been kept.
    $clean = null;
    if ($kind === 'picture') {
        $clean = square_picture((string)$file['tmp_name'], $mime);
        [$mime, $extension] = ['image/jpeg', 'jpg'];
    } elseif (str_starts_with($mime, 'image/')) {
        $clean = image_without_metadata((string)file_get_contents((string)$file['tmp_name']), $mime);
    }

    $dir = upload_dir($kind);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
        throw new UserError(t('Der Ordner für Uploads lässt sich nicht anlegen. Bitte Schreibrechte für storage/ prüfen.',
                              'The upload folder cannot be created. Check that storage/ is writable.'));
    $stored = bin2hex(random_bytes(16)) . '.' . $extension;
    $path = $dir . '/' . $stored;
    $saved = $clean === null ? @move_uploaded_file((string)$file['tmp_name'], $path)
                             : $clean !== '' && @file_put_contents($path, $clean, LOCK_EX) === strlen($clean);
    if (!$saved) {
        @unlink($path);     // half a file is no file
        throw new UserError(t('Die Datei konnte nicht gespeichert werden.', 'The file could not be stored.'));
    }

    return ['stored_name' => $stored, 'mime' => $mime, 'bytes' => $clean === null ? (int)$file['size'] : strlen($clean),
            'original_name' => mb_substr((string)($file['name'] ?? ''), 0, 255)];
}

/**
 * An image as it may be stored: the picture, and nothing that says where, when
 * or with what it was taken (ADR 0022 §4).
 *
 * Each format names what it keeps - jpeg_segment_cleaned(), PNG_KEPT_CHUNKS,
 * WEBP_KEPT_CHUNKS - and never what it loses, because the next phone will write
 * a kind of metadata that no list of losses names yet. Of EXIF only which way is
 * up is kept, so an iPhone photo is not shown lying on its side. Nothing after
 * the end of the picture is kept: that is where a phone puts a second picture
 * (MPF) or a motion photo's video, each with EXIF of its own.
 *
 * A GIF is kept as it came. It can carry a comment and XMP, but it is not what a
 * camera or a phone writes a photo as. So is anything that is not a picture.
 *
 * A file that cannot be read the way its format says, before its picture data,
 * comes back as it came, metadata and all, and is kept so: the cleaner tries,
 * and a picture it cannot read through is not refused. The owner decided so on
 * 2026-10-05 (ADR 0022 §11.5) and confirmed it on 2026-10-08, after the
 * security review asked: "try to clear it, but otherwise ignore. dont refuse".
 * A JPEG cut off in the middle of its picture data keeps what was cleaned
 * before it, and the picture data as it is.
 */
function image_without_metadata(string $bytes, string $mime): string {
    return match ($mime) {
        'image/jpeg' => jpeg_without_metadata($bytes),
        'image/png'  => png_without_metadata($bytes),
        'image/webp' => webp_without_metadata($bytes),
        default      => $bytes,
    };
}

/**
 * Walked the way a decoder reads one: from marker to marker, past any stray byte
 * between two segments and any fill byte (0xFF) in front of a marker, and over
 * the markers that stand alone (TEM, a restart outside a scan). Each scan is
 * copied with its picture data, and the walk goes on after it, because a
 * progressive JPEG has several scans with tables between them. It ends at EOI.
 *
 * Something that cannot be read - a length running past the end, a second SOI,
 * no scan at all before EOI - returns the input unchanged before the first scan
 * (see image_without_metadata()); after one, the copy ends there, without it.
 */
function jpeg_without_metadata(string $b): string {
    $n = strlen($b);
    if ($n < 4 || substr($b, 0, 2) !== "\xFF\xD8") return $b;
    $out = "\xFF\xD8";
    $scanned = false;
    for ($i = 2; ($i = strpos($b, "\xFF", $i)) !== false;) {
        while ($i < $n && $b[$i] === "\xFF") $i++;
        if ($i >= $n) break;
        $marker = ord($b[$i++]);
        // The end of the picture. What follows it is not part of it.
        if ($marker === 0xD9) return $scanned ? $out . "\xFF\xD9" : $b;
        // A stuffed zero outside a scan is a stray byte; TEM and a restart
        // stand alone. None has a length, and none is anything to keep.
        if ($marker === 0x00 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) continue;
        if ($marker === 0xD8 || $i + 2 > $n) break;
        $length = unpack('n', substr($b, $i, 2))[1];
        if ($length < 2 || $i + $length > $n) break;
        $segment = "\xFF" . chr($marker) . substr($b, $i, $length);
        $i += $length;
        if ($marker !== 0xDA) { $out .= jpeg_segment_cleaned($marker, $segment); continue; }
        // A scan: its header, then its picture data up to the next marker.
        $end = jpeg_scan_end($b, $i);
        if ($end === null) return $out . $segment . substr($b, $i);
        $out .= $segment . substr($b, $i, $end - $i);
        $i = $end;
        $scanned = true;
    }
    return $scanned ? $out : $b;
}

/**
 * Where a scan's picture data ends: at the first marker that is not part of it -
 * neither a stuffed zero (FF00) nor a restart (RST0-7) - counted from the first
 * of any fill bytes in front of it. null when the file ends first.
 */
function jpeg_scan_end(string $b, int $from): ?int {
    $n = strlen($b);
    while (($at = strpos($b, "\xFF", $from)) !== false) {
        $code = $at + 1;
        while ($code < $n && $b[$code] === "\xFF") $code++;
        if ($code >= $n) return null;
        $marker = ord($b[$code]);
        if ($marker !== 0x00 && ($marker < 0xD0 || $marker > 0xD7)) return $at;
        $from = $code + 1;
    }
    return null;
}

/**
 * One segment as the cleaned JPEG has it: the list of what a JPEG keeps. A scan
 * is kept by jpeg_without_metadata() itself, with its picture data. A header is
 * matched by its name as well as its marker, because the same APPn markers carry
 * thumbnails (JFXX) and the index of a second picture (MPF).
 */
function jpeg_segment_cleaned(int $marker, string $segment): string {
    $payload = substr($segment, 4);
    return match (true) {
        // SOFn, DHT and DAC: the frame and how its data is coded.
        $marker >= 0xC0 && $marker <= 0xCF => $segment,
        // DQT, DNL, DRI, DHP and EXP: the rest of the tables a decoder reads.
        $marker >= 0xDB && $marker <= 0xDF => $segment,
        // EXIF as which way is up and nothing else, in a block of its own.
        $marker === 0xE1 && str_starts_with($payload, "Exif\0\0") => jpeg_orientation_segment(exif_orientation(substr($payload, 6))),
        // JFIF as its 14 bytes, the thumbnail's size set to none: what follows
        // them is a thumbnail, which can show the photo from before it was cropped.
        $marker === 0xE0 && str_starts_with($payload, "JFIF\0") && strlen($payload) >= 14
            => jpeg_segment_of(0xE0, substr($payload, 0, 12) . "\0\0"),
        // The colour profile, which the colours are drawn by.
        $marker === 0xE2 && str_starts_with($payload, "ICC_PROFILE\0") => $segment,
        // Adobe's colour transform as its 12 bytes, which are all a decoder reads.
        $marker === 0xEE && str_starts_with($payload, 'Adobe') && strlen($payload) >= 12
            => jpeg_segment_of(0xEE, substr($payload, 0, 12)),
        default => '',
    };
}

/** A JPEG segment: its marker, its length (which counts itself) and what it holds. */
function jpeg_segment_of(int $marker, string $payload): string {
    return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
}

/** Which way is up (1-8) in an EXIF block, or 0 when it does not say. */
function exif_orientation(string $tiff): int {
    $order = substr($tiff, 0, 2);
    if (strlen($tiff) < 8 || ($order !== 'II' && $order !== 'MM')) return 0;
    $u16 = fn(int $at): int => $at >= 0 && $at + 2 <= strlen($tiff) ? unpack($order === 'II' ? 'v' : 'n', substr($tiff, $at, 2))[1] : 0;
    $ifd = unpack($order === 'II' ? 'V' : 'N', substr($tiff, 4, 4))[1];
    for ($k = 0, $count = min($u16($ifd), 512); $k < $count; $k++) {
        $entry = $ifd + 2 + 12 * $k;
        if ($u16($entry) === 0x0112) { $value = $u16($entry + 8); return $value >= 1 && $value <= 8 ? $value : 0; }
    }
    return 0;
}

/**
 * An EXIF segment holding nothing but which way is up - or nothing at all when
 * the picture is upright already (1) or the EXIF did not say (0), because then
 * there is nothing a viewer needs to be told.
 */
function jpeg_orientation_segment(int $orientation): string {
    if ($orientation <= 1) return '';
    $tiff = "MM\x00\x2A" . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\0\0" . pack('N', 0);
    return jpeg_segment_of(0xE1, "Exif\0\0" . $tiff);
}

/**
 * Whether $length bytes from $at end by $end. A negative length never does: it
 * is what unpack('N') and unpack('V') give on 32-bit PHP for 2^31 and more, and
 * taken as it came it would walk a file backwards, for ever.
 */
function chunk_fits(int $at, int $length, int $end): bool {
    return $length >= 0 && $length <= $end - $at;
}

/** The chunks a cleaned PNG keeps: the picture, and how it is meant to be shown. */
const PNG_KEPT_CHUNKS = [
    'IHDR', 'PLTE', 'IDAT', 'IEND',
    'tRNS',                             // which colour, or how much of each, is see-through
    'gAMA', 'cHRM', 'sRGB', 'iCCP',     // how its colours are meant; iCCP is a colour profile
    'sBIT', 'bKGD', 'pHYs',             // how precise its colours were, a background to show it on, the shape of a pixel
    'acTL', 'fcTL', 'fdAT',             // an animated PNG's frames
    'cICP', 'mDCV', 'cLLI',             // the colour of an HDR picture, and the brightness it was made for
];

function png_without_metadata(string $b): string {
    if (substr($b, 0, 8) !== "\x89PNG\r\n\x1A\n") return $b;
    $out = substr($b, 0, 8);
    for ($i = 8, $n = strlen($b); $i + 12 <= $n;) {
        $length = unpack('N', substr($b, $i, 4))[1];
        // Its data, and the checksum after the data, have to be in the file.
        if (!chunk_fits($i + 8, $length, $n - 4)) return $b;
        $type = substr($b, $i + 4, 4);
        if (in_array($type, PNG_KEPT_CHUNKS, true)) $out .= substr($b, $i, 12 + $length);
        if ($type === 'IEND') return $out;
        $i += 12 + $length;
    }
    // No IEND: the file stops before its end.
    return $b;
}

/** The chunks a cleaned WebP keeps: the picture, and how it is meant to be shown. */
const WEBP_KEPT_CHUNKS = [
    'VP8X',                             // the extended header, no longer saying that EXIF or XMP follow
    'VP8 ', 'VP8L', 'ALPH',             // the picture, lossy or lossless, and a lossy one's see-through parts
    'ANIM', 'ANMF',                     // an animation and its frames
    'ICCP',                             // the colour profile
];

function webp_without_metadata(string $b): string {
    if (strlen($b) < 12 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') return $b;
    // The picture is as long as its RIFF header says. Bytes after that are no
    // chunk of it, whatever they look like, and are not walked at all.
    $riff = unpack('V', substr($b, 4, 4))[1];
    if ($riff < 4 || !chunk_fits(8, $riff, strlen($b))) return $b;
    $end = 8 + $riff;
    $chunks = '';
    for ($i = 12; $i < $end;) {
        if ($i + 8 > $end) return $b;
        $type = substr($b, $i, 4);
        $size = unpack('V', substr($b, $i + 4, 4))[1];
        if (!chunk_fits($i + 8, $size, $end)) return $b;
        // A chunk of odd size is followed by a byte of padding, which the last
        // one in a file is sometimes written without.
        $whole = 8 + $size + $size % 2;
        $chunk = substr($b, $i, min($whole, $end - $i));
        // The extended header says whether EXIF (8) and XMP (4) follow; they no longer do.
        if ($type === 'VP8X' && $size >= 1) $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
        if (in_array($type, WEBP_KEPT_CHUNKS, true)) $chunks .= $chunk;
        $i += $whole;
    }
    return 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;
}

// ---------------------------------------------------------------------------
// A picture: a child's, or a team member's (ADR 0031 §3, §4)
// ---------------------------------------------------------------------------

/**
 * The side of the one square a picture is stored as, in pixels. The
 * largest face drawn is 72 pt, on the child's page, which a 3× phone draws with
 * 216 pixels; 320 leaves room. About 20 to 30 KB each.
 */
const PICTURE_SIDE = 320;

/**
 * The most pixels a photo may have to be made into a picture: 24 million, about
 * 120 MB to decode (ADR 0031 §3, from the security review). Not left to the
 * upload limit: a PNG of one colour claiming 25 million pixels is a few
 * kilobytes, and a few of them at once could take a shared host's memory. Asked
 * of the header, before anything is decoded.
 *
 * A phone's own „24 MP" photo is 5712 × 4284, 24.5 million: the browser makes a
 * photo smaller before it sends it, and without JavaScript that one is refused
 * in words, as a photo over the upload limit is.
 */
const PICTURE_MAX_PIXELS = 24_000_000;

/** What decoding holds of one pixel, give or take: about five bytes. */
const PICTURE_BYTES_PER_PIXEL = 5;

/** As far as a request raises its own memory_limit to decode one photo: 256 MB, as WordPress does for images. */
const PICTURE_MEMORY_CEILING = 256 * 1024 * 1024;

/** How long a photo waits for the one being made before it is refused: a 24-megapixel photo takes a second or two. */
const PICTURE_LOCK_SECONDS = 10;

/** Whether this PHP can make a picture at all: whether it has gd, which extension_checks() names. */
function pictures_possible(): bool { return function_exists('imagecreatetruecolor'); }

/**
 * What a person is told where this server cannot make a picture (ADR 0031 §4),
 * as [what, who sets it up]: an administrator where to look, anybody else that
 * an administrator does it. One wording, for the refusal (square_picture()) and
 * for the picture card, which shows the two on two lines.
 */
function pictures_unavailable(): array {
    return [t('Fotos gehen auf diesem Server noch nicht.', 'Photos do not work on this server yet.'),
            is_admin() ? t('Unter „Einstellungen“ → „System“ steht, was fehlt.', '“Settings” → “System” says what is missing.')
                       : t('Das richtet eine Administratorin ein.', 'An administrator sets this up.')];
}

/**
 * The photos a picture is made from, as media type => extension: JPEG and PNG
 * (the security review, 2026-10-08) - what a phone's camera writes, and what the
 * browser sends once it has made a chosen photo smaller - as far as this
 * server's gd reads them, so the form's accept never offers what
 * square_picture() refuses. Nothing else gd might read: each format is a native
 * decoder of the host's that a family can reach, and libwebp's is where
 * CVE-2023-4863 was. So no WebP, which is refused in words when it comes
 * without JavaScript; no GIF, whose animation would be lost on the way to a
 * still; no HEIC, which gd cannot read. Without gd both are named, and
 * square_picture() says what the server is missing instead.
 */
function picture_types(): array {
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!pictures_possible()) return $types;
    $read = ['image/jpeg' => IMG_JPG, 'image/png' => IMG_PNG];
    return array_filter($types, fn(string $mime): bool => (imagetypes() & $read[$mime]) !== 0, ARRAY_FILTER_USE_KEY);
}

/** What a photo is refused with that cannot be made into a picture, whatever was wrong with it. */
function picture_unreadable(): string {
    return t('Dieses Bild lässt sich nicht lesen. Bitte ein Foto (JPEG oder PNG) wählen.',
             'This picture cannot be read. Please choose a photo (JPEG or PNG).');
}

/**
 * Room to decode a photo of $pixels pixels, or a refusal in words rather than
 * PHP's fatal error and a blank page.
 *
 * When what decoding takes does not fit in what memory_limit leaves, the
 * request raises its own limit as far as PICTURE_MEMORY_CEILING, where the host
 * lets a script set it, and never lowers it. A 12-megapixel photo takes about
 * 60 MB. Where gd is PHP's own copy, its pictures count against the limit; a gd
 * of the system's own allocates beside it, and then this only asks for more
 * than was needed.
 */
function picture_memory_for(int $pixels): void {
    $needed = memory_get_usage() + $pixels * PICTURE_BYTES_PER_PIXEL;
    $limit = ini_bytes((string)ini_get('memory_limit'));
    if ($limit < 0 || $needed <= $limit) return;    // -1 is no limit at all
    if ($needed <= PICTURE_MEMORY_CEILING && @ini_set('memory_limit', (string)PICTURE_MEMORY_CEILING) !== false) return;
    throw new UserError(t('Dieses Foto ist zu groß, um es hier zu verkleinern. Bitte ein kleineres wählen.',
                          'This photo is too large to be made smaller here. Please choose a smaller one.'));
}

/**
 * A picture as it is stored, a child's or a team member's (ADR 0031 §3), made
 * from the photo in the file at $path: one square JPEG of PICTURE_SIDE pixels,
 * cut from the middle of the photo, turned upright by its EXIF orientation and
 * on white where the photo was see-through - then cleaned like every stored
 * picture (image_without_metadata()), which also takes the comment gd writes
 * into it. Made from the pixels, it keeps nothing else of the photo: no place,
 * no time, no camera, no thumbnail, no second picture. The photo itself is
 * never stored.
 *
 * Never a 500 or a warning, whatever arrives. The size is read from the header
 * (getimagesize()), which decodes nothing, and a photo with no size, or with
 * more than PICTURE_MAX_PIXELS, is refused before a byte of it is decoded or
 * even read in; the memory to
 * decode it is made room for or refused (picture_memory_for()); a JPEG cut off
 * in its picture data - which gd draws without a word, grey below the cut - and
 * anything else gd cannot read are refused in words. The photo is let go as
 * soon as the square is cut, and the square once it is encoded.
 *
 * Decoded by the function of the one type read from its bytes, JPEG or PNG -
 * never imagecreatefromstring(), which takes any format the host's gd was built
 * with (the security review, 2026-10-08). From the file, because those take a
 * file, and a data: address is shut where allow_url_fopen is off, as on many a
 * shared host.
 *
 * ponytail: the square is cut from the middle, with no step to choose it. Its
 * ceiling: a face at the edge of a photo is cut off. The way up is a crop step
 * in app.js, with the middle as what a page without JavaScript gets.
 *
 * One photo is decoded at a time across the portal, under a lock of the
 * database's as the mail queue and the background work hold theirs: twenty
 * sessions of one login at once could otherwise each take what 24 megapixels
 * take. A trainer photographing one child after another never waits; a photo
 * sent while another is being made waits for it, up to $wait seconds, and is
 * then refused in words.
 *
 * gd drops the colour profile, so a wide-colour photo shows a little paler; at
 * 320 pixels that is accepted. $gd is whether gd is there: the suite passes
 * false to watch the refusal without taking gd from the PHP it runs on, and 0
 * as $wait to watch the lock refuse without waiting for it.
 */
function square_picture(string $path, string $mime, ?bool $gd = null, int $wait = PICTURE_LOCK_SECONDS): string {
    if (!($gd ?? pictures_possible())) throw new UserError(implode(' ', pictures_unavailable()));
    // is_file() first: getimagesize() throws on an empty path or a NUL byte.
    $size = isset(picture_types()[$mime]) && is_file($path) ? @getimagesize($path) : false;
    if (!$size || ($size['mime'] ?? '') !== $mime || $size[0] < 1 || $size[1] < 1) throw new UserError(picture_unreadable());
    if ($size[0] * $size[1] > PICTURE_MAX_PIXELS)
        throw new UserError(t('Dieses Foto hat zu viele Bildpunkte: höchstens 24 Megapixel.',
                              'This photo has too many pixels: at most 24 megapixels.'));
    picture_memory_for($size[0] * $size[1]);
    if ((int)scalar('SELECT GET_LOCK(?, ?)', ['badminton_crm_picture', $wait]) !== 1)
        throw new UserError(t('Gerade wird ein anderes Foto verarbeitet. Bitte gleich noch einmal.',
                              'Another photo is being processed right now. Please try again in a moment.'));
    try {
        // Cleaned, a JPEG that is whole ends where its picture ends; gd would draw
        // one cut off without a word. Which way is up is read from the cleaned one.
        $clean = image_without_metadata((string)@file_get_contents($path), $mime);
        if ($mime === 'image/jpeg' && !str_ends_with($clean, "\xFF\xD9")) throw new UserError(picture_unreadable());
        $photo = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if ($photo === false) throw new UserError(picture_unreadable());
        [$width, $height] = [imagesx($photo), imagesy($photo)];
        $side = min($width, $height);
        $square = imagecreatetruecolor(PICTURE_SIDE, PICTURE_SIDE);
        imagefilledrectangle($square, 0, 0, PICTURE_SIDE - 1, PICTURE_SIDE - 1, imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled($square, $photo, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), PICTURE_SIDE, PICTURE_SIDE, $side, $side);
        unset($photo);
        $square = picture_upright($square, $mime === 'image/jpeg' ? jpeg_orientation($clean) : 0);
        ob_start();
        imagejpeg($square, null, 85);
        $jpeg = (string)ob_get_clean();
        unset($square);
        return image_without_metadata($jpeg, 'image/jpeg');
    } finally {
        run("SELECT RELEASE_LOCK('badminton_crm_picture')");
    }
}

/**
 * The square turned the way its EXIF says is up (2-8; 0 and 1 are upright
 * already). Turned once it is cut, so 320 pixels are turned rather than the
 * whole photo: the middle of a turned photo is the turned middle of the photo.
 */
function picture_upright(GdImage $square, int $orientation): GdImage {
    if (in_array($orientation, [2, 5, 7], true)) imageflip($square, IMG_FLIP_HORIZONTAL);
    if ($orientation === 4) imageflip($square, IMG_FLIP_VERTICAL);
    $angle = match ($orientation) { 3 => 180, 5, 8 => 90, 6, 7 => 270, default => 0 };
    return $angle === 0 ? $square : imagerotate($square, $angle, 0);
}

/** The shape of every name store_upload() gives a file: nothing a person typed. */
const STORED_UPLOAD_NAME = '/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/D';

/**
 * Whether $name is one store_upload() could have given a file of $kind: its
 * shape, and an extension that kind takes. What a setting names is served only
 * if it is, so a route serves from its own folder whatever ends up in it.
 *
 * For a name a setting holds, the icon's and the logo's. Not for the download
 * route's files, whose names come from their rows: it judges by the types a
 * kind takes today, and would refuse the voice notes and files sent before a
 * message became text and photos (ADR 0022 §11.4).
 */
function is_stored_upload(mixed $name, string $kind): bool {
    return is_string($name) && preg_match(STORED_UPLOAD_NAME, $name) === 1
        && in_array(pathinfo($name, PATHINFO_EXTENSION), upload_types($kind), true);
}

/** Remove a stored file. Missing is not an error; the row is going either way. */
function delete_upload(string $kind, string $storedName): void {
    if (!preg_match(STORED_UPLOAD_NAME, $storedName)) return;
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
        // The problem reports' screenshots, alone in the folder since the
        // profile pictures went (ADR 0026 §8): a stored file there that no
        // report names is a picture left behind, and goes.
        'avatar'  => ["SELECT screenshot_name AS name FROM feedback WHERE screenshot_name<>''"],
        'proof'   => ['SELECT stored_name AS name FROM payment_proofs'],
        'message' => ['SELECT stored_name AS name FROM message_files'],
        // A setting is stored as JSON, so the name sits inside quotes. Compared
        // with the quotes still on, nothing would match and the live icon would
        // be swept an hour after it was uploaded.
        'icon'    => ["SELECT REPLACE(setting_value,'\"','') AS name FROM settings WHERE setting_key='portal_icon'"],
        'logo'    => ["SELECT REPLACE(setting_value,'\"','') AS name FROM settings WHERE setting_key='portal_logo'"],
        // Children's pictures, back since 040 in a folder of their own, and the
        // team's since 041 beside them (ADR 0031 §9).
        'picture' => ["SELECT picture_name AS name FROM students WHERE picture_name<>''",
                      "SELECT picture_name AS name FROM accounts WHERE picture_name<>''"],
    ];
}

/**
 * Remove uploaded files that no record points at any more.
 *
 * A deleted account takes its conversations with it, a deleted child their
 * receipts, and a database row can go without anything touching the disk - so
 * without this, a family who asked to be forgotten leaves their photographs
 * behind in storage, and the folder only ever grows. A message taken down keeps
 * its photo while it can be put back (moderate_message()). The daily cleanup
 * runs this last (prune_expired()), so the file of a row it deleted that day -
 * a message or a proof past its period, a message taken down longer ago than
 * removed_messages_days (ADR 0032) - goes the same day; and every update's
 * step after the files runs it too (database/defaults.php), which is how the
 * profile pictures left the disk with their columns.
 *
 * Nothing at all while the database has nobody in it. Then it is not the one
 * these files belong to: restoring a backup the way INSTALL.md says - every
 * table deleted, then the copy imported - leaves a moment in which the next
 * request makes the tables afresh and runs the update, and its sweep found no
 * row naming any file and deleted every receipt, chat photo, screenshot, icon
 * and logo. The backup holds the rows, never the files. A portal anybody uses
 * has an administrator, and setup.php makes the first one only after the
 * migrations.
 *
 * Deliberately conservative: a file younger than the grace period is left
 * alone, because it may belong to a row being written in another request right
 * now, and deleting somebody's photograph a second after they uploaded it is a
 * worse failure than keeping one too long. Only in each kind's own folder, only
 * an ordinary file, never a link or a folder, and only a name store_upload()
 * gives (STORED_UPLOAD_NAME): whatever else somebody put there is theirs.
 */
function prune_uploads(int $graceSeconds = 3600): int {
    // Nothing is swept while a copy is being restored, whoever calls - the
    // daily cleanup, the console, the update's step (ADR 0029 §3): the rows that
    // name the files may not have arrived yet, and every file would look like
    // an orphan.
    if (schema_restore_refusal()) return 0;
    // ponytail: a copy without the marker - written before this release, or
    // exported in the panel - is judged by its ledger alone, and an import of it
    // that stops after schema_migrations is not told apart from the portal's own
    // database (ADR 0029 §6). backup_tables() writes the tables in name order, so
    // accounts is among the first such an import brings; until the first
    // administrator is in, nothing is swept. The way up is the marker in every
    // copy, which the release that ships this writes.
    if (!(int)scalar('SELECT COUNT(*) FROM accounts')) return 0;
    $removed = 0;
    $cutoff = time() - max(60, $graceSeconds);
    foreach (upload_references() as $kind => $queries) {
        $dir = upload_dir($kind);
        $names = dir_entries($dir);
        if (!$names) continue;
        $kept = [];
        foreach ($queries as $sql)
            foreach (rows($sql) as $row) $kept[(string)$row['name']] = true;
        foreach ($names as $name) {
            $path = $dir . '/' . $name;
            if (isset($kept[$name]) || is_link($path) || !is_file($path) || !preg_match(STORED_UPLOAD_NAME, $name)) continue;
            if ((int)@filemtime($path) > $cutoff) continue;
            if (@unlink($path)) $removed++;
        }
    }
    return $removed;
}

/**
 * How a download is cached unless its caller knows better: not at all.
 *
 * An invoice, a payment proof, a message attachment or a problem report's
 * screenshot is one family's business, and a phone shared in the family keeps
 * what its browser keeps. Only the club's own icon and logo say otherwise, and a
 * picture of a child or a team member, for a week (picture_cache_control()).
 */
const DOWNLOAD_CACHE_CONTROL = 'private, no-store';

/**
 * The name a stored file is handed over under: the one its sender gave it, with
 * the extension it was stored under. store_upload() read the bytes and chose
 * that extension, so a photo a family called „spiel.apk" leaves as spiel.jpg -
 * as spiel.apk, a phone offers to install it. No usable name given, the stored
 * one.
 */
function upload_download_name(string $storedName, string $givenName): string {
    $base = pathinfo($givenName, PATHINFO_FILENAME);
    if (trim($base, '. ') === '') $base = pathinfo($storedName, PATHINFO_FILENAME);
    return $base . '.' . pathinfo($storedName, PATHINFO_EXTENSION);
}

/**
 * Send a stored file to the browser, having decided the caller may have it.
 *
 * Content-Disposition is attachment for everything except images, and the type
 * is the one recorded at upload rather than guessed again, so a file cannot be
 * served as something it is not - nor named as something it is not
 * (upload_download_name()). $cacheControl is said only for a picture
 * (serve_download()); everything else is kept by no cache.
 */
function send_upload(string $kind, string $storedName, string $mime, string $givenName = '',
                     string $cacheControl = DOWNLOAD_CACHE_CONTROL): never {
    $path = upload_dir($kind) . '/' . $storedName;
    if (!preg_match(STORED_UPLOAD_NAME, $storedName) || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit(t('Diese Datei gibt es nicht mehr.', 'That file is no longer here.') . "\n");
    }
    // Streamed rather than read into a string first: shared hosting sets
    // memory_limit as low as 64 MB, and a voice note plus whatever else the
    // request is holding should not be what decides whether a file can be
    // downloaded at all.
    send_download_headers($mime, upload_download_name($storedName, $givenName),
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

// ---------------------------------------------------------------------------
// Who sees a picture (ADR 0031 §5, §6)
// ---------------------------------------------------------------------------

/**
 * The columns a family's login carries beside its own so that avatar() can draw
 * its child's picture with no query of its own - which child, the picture, and
 * the family's yes for the course - as name => the student's column. A
 * student's own row has the three under its own names: id, picture_name and
 * course_sees_picture.
 */
const CHILD_PICTURE_COLUMNS = ['child_id' => 'id', 'child_picture_name' => 'picture_name', 'child_course_sees_picture' => 'course_sees_picture'];

/**
 * Those columns for the login under $account, as a select list: read from its
 * student, of whom a login has one at most (students.account_id is unique), or
 * from $student where the statement has joined that student already. With a
 * $prefix each is named with it, as chat_person_columns() names a person's.
 */
function child_picture_columns(string $account, string $prefix = '', string $student = ''): string {
    $account = sql_name($account, 'table alias');
    if ($prefix !== '') $prefix = sql_name($prefix, 'column prefix');
    if ($student !== '') $student = sql_name($student, 'table alias');
    $read = fn(string $column): string => $student !== ''
        ? $student . '.' . $column
        : '(SELECT pictured.' . $column . ' FROM students pictured WHERE pictured.account_id=' . $account . '.id)';
    return implode(', ', array_map(fn(string $as, string $column): string => $read($column) . ' AS ' . $prefix . $as,
                                   array_keys(CHILD_PICTURE_COLUMNS), CHILD_PICTURE_COLUMNS));
}

/**
 * The picture a row draws, or null when it draws none - as may_see_picture()
 * and the route read one: whether it is the team's, whose it is (the student's
 * id for a child, the login's for the team), the login it belongs to, its
 * file's name and the family's yes.
 *
 * A student's row draws the child. A login's row - one that says its role -
 * draws a team member's own picture (accounts.picture_name), or a family's
 * child's by the CHILD_PICTURE_COLUMNS it carries: a student's login never has
 * a picture of its own, the child's is on the child. A row without a picture's
 * column - a course's letter, a name on its own - draws none.
 */
function picture_of(array $who): ?array {
    if (!array_key_exists('role', $who))
        return ($who['picture_name'] ?? null) === null ? null
            : ['team' => false, 'id' => (int)($who['id'] ?? 0), 'account_id' => (int)($who['account_id'] ?? 0),
               'picture_name' => (string)$who['picture_name'], 'course_sees_picture' => (int)($who['course_sees_picture'] ?? 0)];
    if (is_staff($who))
        return ($who['picture_name'] ?? null) === null ? null
            : ['team' => true, 'id' => (int)($who['id'] ?? 0), 'account_id' => (int)($who['id'] ?? 0),
               'picture_name' => (string)$who['picture_name'], 'course_sees_picture' => 0];
    return ($who['child_picture_name'] ?? null) === null ? null
        : ['team' => false, 'id' => (int)($who['child_id'] ?? 0), 'account_id' => (int)($who['id'] ?? 0),
           'picture_name' => (string)$who['child_picture_name'], 'course_sees_picture' => (int)($who['child_course_sees_picture'] ?? 0)];
}

/** Whether $name is a picture as store_upload() names one (STORED_UPLOAD_NAME), whose file is there. */
function picture_stored(string $name): bool {
    return $name !== '' && preg_match(STORED_UPLOAD_NAME, $name) === 1 && is_file(upload_dir('picture') . '/' . $name);
}

/**
 * Whether a row has a picture to draw (picture_of()), whose file is there. A
 * commit that failed after the old file was deleted leaves a name whose file is
 * gone, and the person is drawn by the initials until the next picture (ADR 0031
 * §7). Any row avatar() draws: a student's, a team member's, a family's login.
 */
function has_picture(array $who): bool { return picture_stored((string)(picture_of($who)['picture_name'] ?? '')); }

/**
 * Whether $viewer may see a picture: the one rule (ADR 0031 §5), which avatar()
 * draws by and the route serves by, so a page never shows a face the route
 * would refuse. $picture is as picture_of() gives one. True for
 *
 *   - a team member's picture: everybody signed in sees the team's faces (the
 *     owner, 2026-10-08) - the people a family writes to and trains with;
 *   - staff, every child's - the owner's reason, "for anwesenheit";
 *   - the child's own login;
 *   - anybody whose child is in a running course with the child now
 *     (children_in_course_with()), once the child's family has said yes
 *     (picture_consent, needs_a_parents_yes()) and while the club shows
 *     pictures in a course (pictures_in_course).
 *
 * Not in return: a family that keeps its own child's to itself still sees those
 * of families who said yes, because a yes with a price is not freely given (GDPR
 * Art. 7(4)). Nobody signed out sees any: the callers ask with who is signed in.
 */
function may_see_picture(array $viewer, array $picture): bool {
    if (!empty($picture['team']) || is_staff($viewer)) return true;
    if ((int)($picture['account_id'] ?? 0) === (int)$viewer['id']) return true;
    return (int)($picture['course_sees_picture'] ?? 0) === 1 && (bool)setting('pictures_in_course')
        && isset(children_in_course_with((int)$viewer['id'])[(int)($picture['id'] ?? 0)]);
}

/**
 * The children in a running course with the child of login $accountId now
 * (running_enrolment_sql(), for both), as student id => true: whom a family's
 * yes reaches. Asked once per request and kept for it, so a page of twenty
 * faces asks no query per face. A test run is one process, and empties it with
 * picture_audience_clear() as a request would start without it.
 */
function children_in_course_with(int $accountId): array {
    $memo =& picture_audience();
    return $memo[$accountId] ??= array_fill_keys(array_map('intval', array_column(rows(
        'SELECT DISTINCT theirs.student_id FROM students s'
        .' JOIN class_students mine ON mine.student_id=s.id AND '.running_enrolment_sql('mine')
        .' JOIN class_students theirs ON theirs.class_id=mine.class_id AND '.running_enrolment_sql('theirs')
        .' WHERE s.account_id=?', [$accountId]), 'student_id')), true);
}
function &picture_audience(): array { static $memo = []; return $memo; }
function picture_audience_clear(): void { $memo =& picture_audience(); $memo = []; }

/**
 * Whether the yes for $student's picture in the course is a parent's to give
 * (ADR 0031, as amended: § 4 Abs. 4 DSG): under the age from which a child
 * agrees alone, consent_age (14 unless the club is in another country), or with
 * no birth date to tell. A parent agrees then, through the family's login.
 * Asked today: picture_consent writes down whose the yes was, and a parent's
 * yes stays when the child turns 14 - nobody is asked again. The switch's words
 * and the bell's notice ask it too.
 */
function needs_a_parents_yes(array $student): bool {
    $age = student_age($student['birth_date'] ?? null);
    return $age === null || $age < (int)setting('consent_age');
}

/**
 * The stored name of a picture, for whoever is signed in - or the route's one
 * 404 (ADR 0031 §6, as amended): of $kind 'student', a child's by the student's
 * id; of 'account', a team member's by the login's. Any other kind is the 404.
 *
 * It reads only the columns the rule needs and asks may_see_picture(); not
 * student(), which hands over the whole record, when a family that may see a
 * classmate's face may see nothing else of the child. No such child or login,
 * no picture and not allowed are the same 404 in the same words, so the address
 * cannot be used to learn which ids exist; a family's login asked for as the
 * team's has none (picture_of()).
 */
function picture_for_download(string $kind, int $id): string {
    $viewer = require_user();
    $row = match ($kind) {
        'student' => one('SELECT id, account_id, picture_name, course_sees_picture FROM students WHERE id=?', [$id]),
        'account' => one('SELECT id, role, picture_name FROM accounts WHERE id=?', [$id]),
        default   => null,
    };
    $picture = $row ? picture_of($row) : null;
    if ($picture === null || !picture_stored($picture['picture_name']) || !may_see_picture($viewer, $picture))
        throw new NotFound(t('Dieses Bild gibt es nicht.', 'There is no such picture.'));
    return $picture['picture_name'];
}

/**
 * How long a browser may keep the picture it is sent (ADR 0031 §6, as ADR 0017
 * had it): a week at the address of the picture stored now, and not at all at
 * any other. Twenty faces fetched again on every page is what the week saves; a
 * new picture has a new address, and a removed one is drawn as the initials, so
 * nothing stale is shown from the cache. An older address is still answered,
 * uncached, rather than refused: a page drawn a moment before the picture
 * changed would otherwise show a broken image.
 *
 * Always private - a child's photograph is never for a shared cache - and never
 * immutable: a copy on a borrowed phone runs out on its own within the week.
 * Sign-out asks the browser to forget it sooner (logout), which not every
 * browser does.
 */
function picture_cache_control(string $storedName, mixed $requestedVersion): string {
    return upload_version_current($storedName, $requestedVersion) ? 'private, max-age=604800' : DOWNLOAD_CACHE_CONTROL;
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
    $what = $_GET['what'] ?? '';
    $id = (int)($_GET['id'] ?? 0);
    if ($what === 'invoice') {
        $invoice = invoice($id);
        send_bytes(invoice_pdf($invoice), 'application/pdf', invoice_filename($invoice));
    }
    if ($what === 'picture') {
        // Whose face may be seen is picture_for_download()'s to decide;
        // anybody else gets its 404, whatever kind the address holds.
        $name = picture_for_download(is_string($_GET['kind'] ?? null) ? $_GET['kind'] : '', $id);
        // Nothing here writes to the session, so it is let go now that it has
        // said who is asking: twenty faces on a page then load side by side,
        // rather than each waiting for the one before it to let go.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        send_upload('picture', $name, 'image/jpeg', '', picture_cache_control($name, $_GET['v'] ?? null));
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
        $file = one('SELECT f.*, m.thread_id FROM message_files f JOIN messages m ON m.id=f.message_id WHERE f.id=? AND m.removed_at IS NULL', [$id]);
        // thread_record() refuses a conversation this account may not read, so
        // an attachment cannot be the way round the rule about who reads what;
        // and a removed message's file is served to nobody, staff included.
        if ($file) {
            thread_record((int)$file['thread_id']);
            send_upload('message', (string)$file['stored_name'], (string)$file['mime'], (string)$file['original_name']);
        }
    }
    if ($what === 'proof') {
        $proof = one('SELECT * FROM payment_proofs WHERE id=?', [$id]);
        if ($proof) {
            // student() refuses a child that does not belong to the caller.
            student((int)$proof['student_id']);
            send_upload('proof', (string)$proof['stored_name'], (string)$proof['mime'], (string)$proof['original_name']);
        }
    }
}
