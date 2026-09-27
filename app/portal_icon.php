<?php
declare(strict_types=1);

/**
 * The portal's own icon: the browser tab, the iPhone home screen, an Android
 * install - and the web manifest that tells Android about it (ADR 0008).
 *
 * The operator uploads it in Settings. It is stored like every other upload,
 * under storage/, because public/ is what the next update overwrites and on
 * many hosts cannot be written at all; so it is served through the front
 * controller rather than by URL. Unlike every other upload it is served to
 * anybody, signed in or not: the login page wears it too, and it is the club's
 * logo, not a family's data.
 *
 * Only a square PNG of 180 x 180 to 2048 x 2048 pixels is accepted, and
 * nothing is resized. An iPhone takes its home-screen icon only as a PNG; an
 * SVG can carry script and would be served from the portal's own origin;
 * resizing would need GD, which shared hosting does not promise. getimagesize()
 * is part of PHP itself and reads the header, not the picture.
 */

/** The smallest side accepted, in pixels: the size an iPhone draws on its home screen. */
const PORTAL_ICON_MIN_SIZE = 180;
/**
 * The largest side accepted, in pixels. A PNG of a single colour compresses to
 * almost nothing at any size, so the upload limit does not stop a picture of
 * 30,000 x 30,000 pixels that every phone opening any page, the login included,
 * would have to unpack into gigabytes of memory. No home screen draws more than
 * a few hundred pixels, so a larger picture gains nothing.
 */
const PORTAL_ICON_MAX_SIZE = 2048;

/**
 * The stored name of the icon in use, or '' for the one that ships.
 *
 * '' as well when the file is missing: a database restored without storage/
 * must fall back to the built-in icon rather than link every page to a 404.
 */
function portal_icon(): string {
    $name = setting('portal_icon');
    // The shape store_upload() gives a name, so the route can only ever serve a
    // file from this one folder, whatever ends up in the setting.
    if (!is_string($name) || !preg_match('/^[a-f0-9]{32}\.png$/D', $name)) return '';
    return is_file(upload_dir('icon') . '/' . $name) ? $name : '';
}

/**
 * The part of the icon's address that changes when the icon does.
 *
 * The stored name is random per upload, so it already is a version and needs
 * no setting of its own. Twelve characters are plenty to tell uploads apart and
 * give nothing away that the address needs to hide.
 */
function portal_icon_version(string $storedName): string { return substr($storedName, 0, 12); }

/** Where the icon is fetched from, or '' when the built-in one applies. */
function portal_icon_url(): string {
    $name = portal_icon();
    return $name === '' ? '' : url('icon', ['v' => portal_icon_version($name)]);
}

/**
 * Refuse a stored upload that cannot be the portal's icon, and delete it.
 *
 * Deleted now rather than left for the hourly sweep: nothing points at it and
 * nothing ever will. The message says what the picture is and what it needs to
 * be, because "not accepted" sends her back to guess.
 */
function check_portal_icon(string $storedName): void {
    $path = upload_dir('icon') . '/' . $storedName;
    $size = is_file($path) ? @getimagesize($path) : false;
    [$width, $height] = $size ? [(int)$size[0], (int)$size[1]] : [0, 0];
    $dimensions = $width . ' × ' . $height;
    $minimum = PORTAL_ICON_MIN_SIZE . ' × ' . PORTAL_ICON_MIN_SIZE;
    $maximum = PORTAL_ICON_MAX_SIZE . ' × ' . PORTAL_ICON_MAX_SIZE;
    $problem = match (true) {
        !$size || $size[2] !== IMAGETYPE_PNG =>
            t('Diese Datei lässt sich nicht als PNG-Bild lesen.', 'This file cannot be read as a PNG picture.'),
        $width !== $height =>
            t('Das Bild ist ', 'The picture is ') . $dimensions
            . t(' Pixel groß. Es muss quadratisch sein, also genauso breit wie hoch.', ' pixels. It has to be square, as wide as it is tall.'),
        $width < PORTAL_ICON_MIN_SIZE =>
            t('Das Bild ist nur ', 'The picture is only ') . $dimensions
            . t(' Pixel groß. Es braucht mindestens ', ' pixels. It needs at least ') . $minimum
            . t(' Pixel, sonst wird es auf dem Home-Bildschirm unscharf.', ' pixels, or it looks blurred on the home screen.'),
        $width > PORTAL_ICON_MAX_SIZE =>
            t('Das Bild ist ', 'The picture is ') . $dimensions
            . t(' Pixel groß. Es darf höchstens ', ' pixels. It may be at most ') . $maximum
            . t(' Pixel groß sein – größer wird es nicht schärfer, lädt auf dem Telefon aber langsamer.', ' pixels – larger is no sharper, only slower to load on a phone.'),
        default => '',
    };
    if ($problem === '') return;
    delete_upload('icon', $storedName);
    throw new UserError($problem);
}

/**
 * How long a browser may keep the icon it is being sent.
 *
 * For a year, when the address names the icon in use: a new upload has a new
 * name and so a new address, so nothing stale can come out of a cache. Anything
 * else - an old address, or the built-in icon served in its place - is checked
 * again every time.
 */
function portal_icon_cache_control(string $storedName, mixed $requestedVersion): string {
    $current = $storedName !== '' && is_string($requestedVersion)
        && $requestedVersion === portal_icon_version($storedName);
    return $current ? shared_cache_control(31536000, true) : 'no-cache';
}

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
 * The session sends Expires and Pragma alongside it, and a reply that may be
 * kept should not carry two headers saying the opposite.
 */
function send_cache_control(string $value): void {
    header('Cache-Control: ' . $value);
    if ($value === 'no-cache') return;
    header_remove('Expires');
    header_remove('Pragma');
}

/** Answer ?page=icon with the icon in use, or the built-in one in its place. */
function serve_portal_icon(): never {
    $name = portal_icon();
    $path = $name !== '' ? upload_dir('icon') . '/' . $name : ROOT . '/public/assets/icon-512.png';
    send_download_headers('image/png', 'icon.png', false, (int)filesize($path));
    // After send_download_headers(), whose no-store this replaces.
    send_cache_control(portal_icon_cache_control($name, $_GET['v'] ?? null));
    readfile($path);
    exit;
}

/**
 * The web manifest: what Android installs, under which name, with which icon.
 *
 * Built per request because the name and the icon are hers to change, and a
 * static file said "Badminton" to every club. The addresses are absolute: the
 * manifest is no longer at the web root, and relative ones would resolve
 * against index.php.
 */
function web_manifest(): array {
    $base = rtrim((string)config('app_url'), '/');
    $name = (string)setting('club_name');
    $icon = portal_icon();
    if ($icon !== '') {
        $size = @getimagesize(upload_dir('icon') . '/' . $icon) ?: [0, 0];
        // Never "maskable": that promises a logo drawn to survive being cut
        // into a circle or a squircle, and hers was not drawn for it.
        $icons = [['src' => portal_icon_url(), 'sizes' => (int)$size[0] . 'x' . (int)$size[1],
                   'type' => 'image/png', 'purpose' => 'any']];
    } else {
        $icons = [
            ['src' => $base . '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $base . '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $base . '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => $base . '/assets/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'],
        ];
    }
    return [
        'name'             => $name,
        'short_name'       => $name,
        // Both languages at once: the reply is cached for a day and shared,
        // so it cannot follow one person's choice.
        'description'      => 'Schüler, Beiträge und Nachrichten. / Students, payments and messages.',
        'start_url'        => url('dashboard'),
        'scope'            => $base . '/',
        'display'          => 'standalone',
        'orientation'      => 'portrait-primary',
        'background_color' => '#f3f6f9',
        'theme_color'      => '#13243a',
        'icons'            => $icons,
    ];
}

/** Answer ?page=manifest. */
function serve_web_manifest(): never {
    $body = (string)json_encode(web_manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    header('Content-Type: application/manifest+json');
    header('Content-Length: ' . strlen($body));
    send_cache_control(shared_cache_control(86400));
    echo $body;
    exit;
}
