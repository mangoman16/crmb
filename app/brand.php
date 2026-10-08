<?php
declare(strict_types=1);

/**
 * The club's look: its colours and its logo (ADR 0013, ADR 0014).
 *
 * Colours reach the page as a stylesheet served by the router, because the
 * Content-Security-Policy refuses every inline style and public/ is what the
 * next update overwrites. The stylesheet only overrides app.css's own tokens,
 * in app.css's own selectors, so a portal nobody customised looks exactly as
 * it always did - and makes no request for it at all.
 *
 * Only colour_normalise() output reaches the stylesheet. A stored value is
 * normalised again on every read, and brand_css() checks every value it
 * prints once more, so a row written by hand or by an older version falls back
 * to the built-in colour instead of carrying text into the CSS.
 */

/**
 * The fixed colours every derivation works against: what app.css puts text on
 * in each appearance - a group and the grey ground around it in light, a
 * group and the field or track inside it in dark (Part 0.2 of the design
 * language) - its light ink, and the near-black the dark menu is shaded
 * toward. None of them is hers to choose (ADR 0013 rejects a custom surface).
 * The first surface of each pair is the one a soft tint is mixed into. The
 * built-in colours she can choose are not here: each is declared once, as
 * 'builtin' in app/defaults.php.
 */
const BRAND_SURFACES_LIGHT = ['#ffffff', '#f2f2f7'];
const BRAND_SURFACES_DARK = ['#1c1c1e', '#2c2c2e'];
const BRAND_NAV_SHADE = '#0d141b';
const BRAND_INK_LIGHT = '#000000';

/** The four colours she chooses, by family; each also has a _dark override. */
function brand_families(): array {
    return ['brand_primary', 'brand_secondary', 'brand_highlight', 'brand_background'];
}

/**
 * The eight colour settings as she chose them: '' or lower-case #rrggbb.
 *
 * Normalised again here rather than trusted from the database, and a
 * background that fails the readability rule it would be refused by today
 * counts as unset: either way the built-in colour applies, and nothing but a
 * colour ever reaches the stylesheet.
 */
function brand_chosen(): array {
    $schema = setting_schema();
    $out = [];
    foreach (brand_families() as $family) foreach ([$family, $family.'_dark'] as $key) {
        $raw = setting($key);
        $colour = is_string($raw) ? colour_normalise($raw) : null;
        if ($colour !== null && setting_colour_refusal($schema[$key], $colour) !== '') $colour = null;
        $out[$key] = $colour ?? '';
    }
    return $out;
}

/**
 * Every token the stylesheet sets, per scheme, and what each setting ends up as.
 *
 *   light, dark  token => colour, only for the families she customised, so a
 *                colour she left empty keeps app.css's own value
 *   used         setting => the colour that setting produces on the page
 *   placeholder  setting => what leaving it empty means: the built-in colour,
 *                or for a dark one, the colour worked out from the light choice
 *   adjusted     setting => [chosen, used], where readability moved her choice
 *
 * The derivation is the table in ADR 0013. A dark override applies only while
 * its light colour is set, as the form's hint says. Memoised per set of
 * choices, so a save within one request is seen by the next call.
 */
function brand_palette(): array {
    static $memo = [];
    $chosen = brand_chosen();
    $memoKey = implode(',', $chosen);
    if (isset($memo[$memoKey])) return $memo[$memoKey];
    $builtin = array_map(fn($spec) => (string)($spec['builtin'] ?? ''), setting_schema());

    $light = brand_scheme_light($chosen, $builtin);
    // Dark: the override where there is one, else worked out from the light
    // choice. Worked out a second time without the overrides, for what an
    // empty override means - the placeholder the form shows.
    $overrides = [];
    foreach (brand_families() as $family)
        $overrides[$family] = $chosen[$family] !== '' ? $chosen[$family.'_dark'] : '';
    $dark = brand_scheme_dark($chosen, $overrides, $builtin);
    $computed = brand_scheme_dark($chosen, array_fill_keys(brand_families(), ''), $builtin);

    $palette = ['light' => $light['tokens'], 'dark' => $dark['tokens'], 'used' => [], 'placeholder' => [], 'adjusted' => []];
    foreach (brand_families() as $family) {
        $palette['used'][$family] = $light['used'][$family];
        $palette['used'][$family.'_dark'] = $dark['used'][$family];
        $palette['placeholder'][$family] = $builtin[$family];
        $palette['placeholder'][$family.'_dark'] = $computed['used'][$family];
        // Adjusted means her own typed colour was moved. A dark colour worked
        // out from the light one was never hers to begin with.
        if ($chosen[$family] !== '' && $light['used'][$family] !== $chosen[$family])
            $palette['adjusted'][$family] = ['chosen' => $chosen[$family], 'used' => $light['used'][$family]];
        if ($overrides[$family] !== '' && $dark['used'][$family] !== $overrides[$family])
            $palette['adjusted'][$family.'_dark'] = ['chosen' => $overrides[$family], 'used' => $dark['used'][$family]];
    }
    return $memo[$memoKey] = $palette;
}

/**
 * The light scheme: tokens for the customised families, and the colour each
 * family ends up as. $builtin is setting key => its declared built-in colour,
 * what an empty family is.
 */
function brand_scheme_light(array $chosen, array $builtin): array {
    [$p, $n, $h, $b] = array_map(fn($f) => $chosen[$f], brand_families());
    $tokens = [];
    $bg = $b !== '' ? $b : $builtin['brand_background'];
    if ($b !== '') $tokens += ['--bg' => $bg, '--scrim-bg' => $bg.'ee'];
    // White is printed on the main colour (--on-accent), so white has to read
    // on it - and the main colour has to read as text on a group, on the grey
    // ground and on the club's own background where she chose one, which may
    // be darker than both.
    $teal = $p !== '' ? colour_until_contrast($p, [...BRAND_SURFACES_LIGHT, $bg], 4.5, 'darker') : $builtin['brand_primary'];
    if ($p !== '') {
        $soft = colour_mix($p, BRAND_SURFACES_LIGHT[0], .12);
        $tokens += ['--teal' => $teal, '--focus' => $teal, '--on-accent' => '#ffffff', '--teal-soft' => $soft,
                    '--teal-ink' => colour_until_contrast($teal, [$soft], 4.5, 'darker')];
    }
    $nav = $n !== '' ? $n : $builtin['brand_secondary'];
    $onNav = colour_text_on($nav);
    if ($n !== '') $tokens += brand_nav_tokens($nav, $onNav, '0d');
    $bright = $h !== '' ? colour_until_contrast($h, [$nav], 3, brand_toward($onNav)) : $builtin['brand_highlight'];
    if ($h !== '') $tokens['--teal-bright'] = $bright;
    // The club's name on the sign-in page is printed in the menu colour on the
    // background, and a light menu colour on a light page would vanish.
    if ($n !== '' || $b !== '') $tokens['--brand-ink'] = colour_contrast($nav, $bg) >= 4.5 ? $nav : BRAND_INK_LIGHT;
    return ['tokens' => $tokens, 'used' => ['brand_primary' => $teal, 'brand_secondary' => $nav,
                                            'brand_highlight' => $bright, 'brand_background' => $bg]];
}

/**
 * The dark scheme. $overrides holds the _dark colours that apply, by family,
 * '' where none does and the colour is worked out from the light choice. An
 * empty family is its _dark built-in colour, and the dark background's
 * built-in colour is what a light background is shaded toward. The soft tint
 * is mixed into the group it is drawn on, as in light - into the black ground
 * it came out darker than app.css's own dark tints.
 */
function brand_scheme_dark(array $chosen, array $overrides, array $builtin): array {
    $ground = $builtin['brand_background_dark'];
    $pick = fn(string $family) => $overrides[$family] !== '' ? $overrides[$family] : $chosen[$family];
    $tokens = [];
    // The dark scheme prints the dark ink on the main colour, and uses the main
    // colour as text on a group and on what sits inside one: it has to read
    // against all three. The black ground is darker than both surfaces.
    $p = $pick('brand_primary');
    $teal = $p !== '' ? colour_until_contrast($p, [...BRAND_SURFACES_DARK, COLOUR_DARK_INK], 4.5, 'lighter') : $builtin['brand_primary_dark'];
    if ($p !== '') {
        $soft = colour_mix($p, BRAND_SURFACES_DARK[0], .18);
        $tokens += ['--teal' => $teal, '--focus' => $teal, '--on-accent' => COLOUR_DARK_INK, '--teal-soft' => $soft,
                    '--teal-ink' => colour_until_contrast($teal, [...BRAND_SURFACES_DARK, $soft], 7, 'lighter')];
    }
    $n = $chosen['brand_secondary'];
    $nav = match (true) {
        $overrides['brand_secondary'] !== '' => $overrides['brand_secondary'],
        $n !== '' => colour_mix($n, BRAND_NAV_SHADE, .25),
        default => $builtin['brand_secondary_dark'],
    };
    $onNav = colour_text_on($nav);
    if ($n !== '') $tokens += brand_nav_tokens($nav, $onNav, '12');
    $h = $pick('brand_highlight');
    $bright = $h !== '' ? colour_until_contrast($h, [$nav], 3, brand_toward($onNav)) : $builtin['brand_highlight_dark'];
    if ($h !== '') $tokens['--teal-bright'] = $bright;
    $b = $chosen['brand_background'];
    $bg = match (true) {
        $overrides['brand_background'] !== '' => $overrides['brand_background'],
        $b !== '' => colour_mix($b, $ground, .06),
        default => $ground,
    };
    if ($b !== '') $tokens += ['--bg' => $bg, '--scrim-bg' => $bg.'ee'];
    return ['tokens' => $tokens, 'used' => ['brand_primary' => $teal, 'brand_secondary' => $nav,
                                            'brand_highlight' => $bright, 'brand_background' => $bg]];
}

/**
 * The menu family, from the menu colour and the text that reads on it.
 *
 * The menu's text is a blend of its colour into that text, then darkened or
 * lightened until it reads: 7:1 for the entries, 4.5:1 for the quieter line.
 * The hover is the text colour at a low alpha, as in app.css.
 */
function brand_nav_tokens(string $nav, string $onNav, string $hoverAlpha): array {
    $ink = colour_until_contrast(colour_mix($onNav, $nav, .82), [$nav], 7, brand_toward($onNav));
    return ['--navy' => $nav, '--nav-bg' => $nav, '--on-nav' => $onNav,
            '--nav-active' => colour_mix($onNav, $nav, .14), '--nav-ink' => $ink,
            '--nav-ink-2' => colour_until_contrast(colour_mix($onNav, $nav, .68), [$nav], 4.5, brand_toward($onNav)),
            '--nav-hover' => $onNav.$hoverAlpha];
}

/** Text of the colour $onNav reads better the further a colour moves toward it: white is lighter, the dark ink darker. */
function brand_toward(string $onNav): string {
    return $onNav === '#ffffff' ? 'lighter' : 'darker';
}

/** The class that colours the swatch of one setting on the „Aussehen" card: its colour in use, or her choice. */
function brand_swatch_class(string $key, bool $chosen = false): string {
    return 'swatch-' . str_replace('_', '-', $key) . ($chosen ? '-chosen' : '');
}

/** One rule, with every value checked to be a colour and every name to be a token. */
function brand_css_rule(string $selector, array $declarations): string {
    $out = [];
    foreach ($declarations as $property => $value)
        if (preg_match('/^--[a-z][a-z0-9-]*$|^background$/D', (string)$property) && preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/D', (string)$value))
            $out[] = $property . ':' . $value;
    return $out ? $selector . '{' . implode(';', $out) . "}\n" : '';
}

/**
 * The stylesheet: app.css's own selectors again, so specificity and order
 * decide exactly as they do between app.css's built-in blocks. '' when nothing
 * is customised.
 *
 * The main colour goes under data-accent=brand, which accent_for() gives
 * everybody who has not picked a colour of their own: a personal accent keeps
 * winning, and if this file fails to load, no rule matches and the built-in
 * teal applies.
 *
 * Memoised per set of choices, like brand_palette(): every page asks for it
 * through brand_css_url(), and the route asks twice, for the body and for the
 * address the body is cached at.
 */
function brand_css(): string {
    static $memo = [];
    $chosen = brand_chosen();
    $memoKey = implode(',', $chosen);
    if (isset($memo[$memoKey])) return $memo[$memoKey];
    $palette = brand_palette();
    $primary = ['--teal', '--teal-ink', '--teal-soft', '--focus', '--on-accent'];
    $split = function (array $tokens) use ($primary): array {
        return [array_intersect_key($tokens, array_flip($primary)), array_diff_key($tokens, array_flip($primary))];
    };
    [$lightAccent, $lightRoot] = $split($palette['light']);
    [$darkAccent, $darkRoot] = $split($palette['dark']);

    $css = brand_css_rule('html[data-accent=brand]', $lightAccent)
         . brand_css_rule(':root', $lightRoot);
    if ($chosen['brand_primary'] !== '')
        $css .= brand_css_rule('.accent-dot.is-default', ['background' => $chosen['brand_primary']]);
    // The swatches on the „Aussehen" card: app.css colours them with the
    // built-in colours, and here the ones she changed. Fixed colours, not the
    // tokens, so the dark ones show their dark colour on a light page too.
    foreach (brand_families() as $family) {
        if ($chosen[$family] === '') continue;
        foreach ([$family, $family.'_dark'] as $key) {
            $css .= brand_css_rule('.brand-swatch.' . brand_swatch_class($key), ['background' => $palette['used'][$key]]);
            $own = $key === $family ? $chosen[$family] : $chosen[$key];
            if ($own !== '') $css .= brand_css_rule('.brand-swatch.' . brand_swatch_class($key, true), ['background' => $own]);
        }
    }
    $autoDark = brand_css_rule('html:not([data-theme=light])[data-accent=brand]', $darkAccent)
              . brand_css_rule('html:not([data-theme=light])', $darkRoot);
    if ($autoDark !== '') $css .= "@media(prefers-color-scheme:dark){\n" . $autoDark . "}\n";
    $css .= brand_css_rule('html[data-theme=dark][data-accent=brand]', $darkAccent)
          . brand_css_rule('html[data-theme=dark]', $darkRoot);
    return $memo[$memoKey] = $css === '' ? '' : "/* The club's colours, from Einstellungen → Portal → Aussehen. Generated; see app/brand.php. */\n" . $css;
}

/**
 * The part of the stylesheet's address that changes when the stylesheet does:
 * a hash of its bytes, as asset_path() gives app.css.
 *
 * Its bytes, not her choices and the release number. The address is kept for a
 * year, immutable, so whatever it is made of has to change whenever the text
 * does. It used to be made of those two, and a release changed how the colours
 * are worked out while VERSION stayed 0.6.0: the address stayed the same, and
 * a browser that had the old colours could keep them for a year. Made of the
 * bytes, it also stays put when nothing on the page changes - a dark colour
 * whose light one is not set, or an update that leaves the colours alone,
 * costs nobody a download.
 */
function brand_css_version(): string {
    return substr(hash('sha256', brand_css()), 0, 12);
}

/**
 * Where the stylesheet is fetched from, or '' when it would be empty and no
 * request is made: nothing is customised, or only a dark colour whose light
 * one is not set, which does not apply yet.
 */
function brand_css_url(): string {
    $chosen = brand_chosen();
    foreach (brand_families() as $family) if ($chosen[$family] !== '') return url('brand', ['v' => brand_css_version()]);
    return '';
}

/** Kept for a year at the address of the colours in use; any other address is asked again every time. */
function brand_css_cache_control(mixed $requestedVersion): string {
    return is_string($requestedVersion) && $requestedVersion === brand_css_version()
        ? shared_cache_control(CLUB_ASSET_MAX_AGE, true) : 'no-cache';
}

/** Answer ?page=brand. An empty stylesheet, not an error, when nothing is customised. */
function serve_brand_css(): never {
    $body = brand_css();
    header('Content-Type: text/css; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    send_cache_control(brand_css_cache_control($_GET['v'] ?? null));
    echo $body;
    exit;
}

/**
 * The browser's own bar colour, for the layout's theme-color tags and the
 * manifest: the grey ground in light and the black one in dark, which the
 * portal's own bars sit on, so the phone's status bar runs into them without
 * a band (Part 0.5). A club's own background, where she set one.
 */
function brand_theme_colour(string $scheme): string {
    $used = brand_palette()['used'];
    return $scheme === 'dark' ? $used['brand_background_dark'] : $used['brand_background'];
}

/** The page colour an installed portal opens on, before its first page is drawn. */
function brand_background_colour(): string {
    return brand_palette()['used']['brand_background'];
}

// ---------------------------------------------------------------------------
// The logo (ADR 0014)
// ---------------------------------------------------------------------------

/** The smallest height accepted: 44 px, the height it is drawn at, on a 2x screen. */
const PORTAL_LOGO_MIN_HEIGHT = 88;
/**
 * The largest side accepted. As with the icon: a picture of one colour
 * compresses to nothing, and every phone opening the sign-in page would have
 * to unpack it.
 */
const PORTAL_LOGO_MAX_SIDE = 2048;
/**
 * The largest file accepted: 1 MB. Every sign-in page loads the logo, often on
 * a phone on mobile data, so it gets a cap of its own below the general
 * upload limit, which is sized for payment proofs and voice notes. A logo
 * drawn for the web is a few kilobytes; a megabyte is a photograph.
 */
const PORTAL_LOGO_MAX_BYTES = 1048576;
/**
 * Widest and tallest accepted, as width / height: 5:1 and 1:2. The words for
 * them come from portal_logo_times(), so the refusals and the Logo card's hint
 * (portal_logo_shape_hint()) change with the numbers.
 */
const PORTAL_LOGO_MAX_RATIO = 5.0;
const PORTAL_LOGO_MIN_RATIO = 0.5;

/**
 * A whole ratio in words, [German, English]: „doppelt"/"twice",
 * „fünfmal"/"five times". Only the ratios somebody would write as words; any
 * other number is a mistake in the constants above, and says so.
 */
function portal_logo_times(float $ratio): array {
    $words = [2 => ['doppelt', 'twice'], 3 => ['dreimal', 'three times'], 4 => ['viermal', 'four times'],
              5 => ['fünfmal', 'five times'], 6 => ['sechsmal', 'six times'], 8 => ['achtmal', 'eight times'],
              10 => ['zehnmal', 'ten times']];
    $whole = (int)round($ratio);
    if (abs($ratio - $whole) > 1e-9 || !isset($words[$whole])) throw new LogicException('No words for a logo ratio of '.$ratio);
    return $words[$whole];
}

/** The shape a logo may have, as the Logo card says it. */
function portal_logo_shape_hint(): string {
    [$wideDe, $wideEn] = portal_logo_times(PORTAL_LOGO_MAX_RATIO);
    [$tallDe, $tallEn] = portal_logo_times(1 / PORTAL_LOGO_MIN_RATIO);
    return t('Höchstens '.$wideDe.' so breit wie hoch und höchstens '.$tallDe.' so hoch wie breit.',
             'At most '.$wideEn.' as wide as it is tall, and at most '.$tallEn.' as tall as it is wide.');
}

/** The image type getimagesize() must report for each extension store_upload() gives. */
function portal_logo_types(): array {
    return ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'webp' => IMAGETYPE_WEBP];
}

/**
 * The stored name of the logo in use, or '' for none.
 *
 * '' as well when the file is missing, so a database restored without
 * storage/ falls back to the icon, or the „B", rather than a broken picture.
 * The shape store_upload() gives a name is required, so the route serves from
 * this one folder whatever ends up in the setting.
 */
function portal_logo(): string {
    $name = setting('portal_logo');
    if (!is_string($name) || !preg_match('/^[a-f0-9]{32}\.(png|jpg|webp)$/D', $name)) return '';
    return is_file(upload_dir('logo') . '/' . $name) ? $name : '';
}

/** Where the logo is fetched from, or '' when there is none. */
function portal_logo_url(): string {
    $name = portal_logo();
    return $name === '' ? '' : url('logo', ['v' => upload_version($name)]);
}

/**
 * [width, height] of the logo in use, [0, 0] when there is none.
 *
 * For the width and height attributes: the CSP refuses a style attribute, and
 * without both the page jumps when the picture arrives.
 */
function portal_logo_size(): array {
    static $sizes = [];
    $name = portal_logo();
    if ($name === '') return [0, 0];
    if (!isset($sizes[$name])) {
        $size = portal_logo_measured(upload_dir('logo') . '/' . $name);
        $sizes[$name] = $size ? [(int)$size[0], (int)$size[1]] : [0, 0];
    }
    return $sizes[$name];
}

/**
 * getimagesize() of a stored logo, with its width and height as a browser draws
 * it, or false when it cannot be read.
 *
 * A JPEG keeps which way is up (jpeg_segment_cleaned()), and every browser turns
 * the picture by it. Turned a quarter - EXIF orientations 5 to 8, how a phone
 * held upright stores its photo - it is drawn as wide as the file is tall.
 * Measured as stored, a logo photographed that way was refused for a shape it is
 * never shown in, and drawn into a box of the wrong shape.
 */
function portal_logo_measured(string $path): array|false {
    $size = is_file($path) ? @getimagesize($path) : false;
    // ponytail: only the first 64 KB are read for which way is up. The cleaned
    // copy keeps it in its first few hundred bytes; a JPEG stored uncleaned, with
    // more than 64 KB of other headers before its EXIF, is measured unturned. The
    // way up is to read segment by segment with fseek().
    if ($size && ($size[2] ?? null) === IMAGETYPE_JPEG
        && jpeg_orientation((string)@file_get_contents($path, false, null, 0, 65536)) >= 5)
        [$size[0], $size[1]] = [$size[1], $size[0]];
    return $size;
}

/**
 * Which way is up in a JPEG (1-8), or 0 when it does not say: the first EXIF
 * block before the picture data, read by exif_orientation() as the cleaner reads
 * it. Walked from marker to marker as jpeg_without_metadata() walks; anything
 * that cannot be read says nothing, so the picture is measured as stored.
 */
function jpeg_orientation(string $b): int {
    $n = strlen($b);
    if ($n < 4 || substr($b, 0, 2) !== "\xFF\xD8") return 0;
    for ($i = 2; ($i = strpos($b, "\xFF", $i)) !== false;) {
        while ($i < $n && $b[$i] === "\xFF") $i++;
        if ($i >= $n) return 0;
        $marker = ord($b[$i++]);
        // A stuffed zero, TEM and a restart stand alone, without a length.
        if ($marker === 0x00 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) continue;
        // The picture data, its end or a second start: no header said it.
        if ($marker === 0xDA || $marker === 0xD9 || $marker === 0xD8 || $i + 2 > $n) return 0;
        $length = unpack('n', substr($b, $i, 2))[1];
        if ($length < 2 || $i + $length > $n) return 0;
        // A segment too short to hold the six-byte header announces no EXIF,
        // whatever the bytes after it say: nothing past a segment is read.
        if ($marker === 0xE1 && $length >= 8 && substr($b, $i + 2, 6) === "Exif\0\0") return exif_orientation(substr($b, $i + 8, $length - 8));
        $i += $length;
    }
    return 0;
}

/**
 * Refuse a stored upload that cannot be the logo, and delete it.
 *
 * getimagesize() reads the header only, never the picture. The type is
 * checked against the extension as well, although store_upload() chose the
 * extension from the bytes: a file that reads as one type and is named as
 * another would be served as the wrong one. Each refusal names the size it
 * found, as it is drawn (portal_logo_measured()), and what is needed, because
 * "not accepted" sends her back to guess.
 */
function check_portal_logo(string $storedName): void {
    $path = upload_dir('logo') . '/' . $storedName;
    $size = portal_logo_measured($path);
    $extension = pathinfo($storedName, PATHINFO_EXTENSION);
    [$width, $height] = $size ? [(int)$size[0], (int)$size[1]] : [0, 0];
    $dimensions = $width . ' × ' . $height;
    $bytes = is_file($path) ? (int)filesize($path) : 0;
    $problem = match (true) {
        // First: however well-formed, a file this size is not what every
        // sign-in page should be made to load.
        $bytes > PORTAL_LOGO_MAX_BYTES =>
            // The limit in the words the Logo card uses for it, so the refusal
            // repeats what she was told rather than a second way of saying it.
            t('Das Bild ist ', 'The picture is ') . megabytes_label($bytes, true) . t(' groß. Für das Logo reichen ', '. ')
            . upload_limit_label(PORTAL_LOGO_MAX_BYTES)
            . t(' – größer wird es nicht schärfer, lädt auf dem Telefon aber langsamer. Speichere es kleiner oder als PNG.',
                ' is enough for the logo – larger is no sharper, only slower to load on a phone. Save it smaller or as a PNG.'),
        !$size || $width < 1 || $height < 1 || ($size[2] ?? null) !== (portal_logo_types()[$extension] ?? false) =>
            t('Diese Datei lässt sich nicht als PNG-, JPEG- oder WebP-Bild lesen.', 'This file cannot be read as a PNG, JPEG or WebP picture.'),
        $height < PORTAL_LOGO_MIN_HEIGHT =>
            t('Das Bild ist nur ', 'The picture is only ') . $dimensions
            . t(' Pixel groß. Es muss mindestens ', ' pixels. It has to be at least ') . PORTAL_LOGO_MIN_HEIGHT
            . t(' Pixel hoch sein, sonst wird es unscharf.', ' pixels tall, or it looks blurred.'),
        max($width, $height) > PORTAL_LOGO_MAX_SIDE =>
            t('Das Bild ist ', 'The picture is ') . $dimensions
            . t(' Pixel groß. Es darf höchstens ', ' pixels. It may be at most ') . PORTAL_LOGO_MAX_SIDE
            . t(' Pixel breit und hoch sein – größer wird es nicht schärfer, lädt auf dem Telefon aber langsamer.',
                ' pixels wide and tall – larger is no sharper, only slower to load on a phone.'),
        $width / $height > PORTAL_LOGO_MAX_RATIO =>
            t('Das Bild ist ', 'The picture is ') . $dimensions
            . t(' Pixel groß und damit mehr als '.portal_logo_times(PORTAL_LOGO_MAX_RATIO)[0].' so breit wie hoch. Schneide den Rand ab oder nimm eine kompaktere Fassung.',
                ' pixels, more than '.portal_logo_times(PORTAL_LOGO_MAX_RATIO)[1].' as wide as it is tall. Crop the edges or use a more compact version.'),
        $width / $height < PORTAL_LOGO_MIN_RATIO =>
            t('Das Bild ist ', 'The picture is ') . $dimensions
            . t(' Pixel groß und damit mehr als '.portal_logo_times(1 / PORTAL_LOGO_MIN_RATIO)[0].' so hoch wie breit. Schneide den Rand ab oder nimm eine breitere Fassung.',
                ' pixels, more than '.portal_logo_times(1 / PORTAL_LOGO_MIN_RATIO)[1].' as tall as it is wide. Crop the edges or use a wider version.'),
        default => '',
    };
    if ($problem === '') return;
    delete_upload('logo', $storedName);
    throw new UserError($problem);
}

/** A year at the address of the logo in use, as for the icon; anything else is asked again. */
function portal_logo_cache_control(string $storedName, mixed $requestedVersion): string {
    return upload_version_current($storedName, $requestedVersion)
        ? shared_cache_control(CLUB_ASSET_MAX_AGE, true) : 'no-cache';
}

/** Answer ?page=logo: the logo in use, or a 404 nobody caches when there is none. */
function serve_portal_logo(): never {
    $name = portal_logo();
    if ($name === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        send_cache_control('no-cache');
        exit(t('Es ist kein Logo hinterlegt.', 'No logo has been set.') . "\n");
    }
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $path = upload_dir('logo') . '/' . $name;
    send_download_headers((string)array_search($extension, upload_types('logo'), true), 'logo.' . $extension, false,
                          (int)filesize($path), portal_logo_cache_control($name, $_GET['v'] ?? null));
    readfile($path);
    exit;
}

/**
 * What the top left shows: the one place the fallback and the two switches are
 * decided, for the sidebar, the sign-in header and the phone's top bar alike.
 *
 *   logo, logo_width, logo_height  the logo's address and size, or '' and 0
 *   icon           the portal icon's address when there is no logo, else ''
 *   name           the club's name, always - hidden or not, it stays in the
 *                  markup for screen readers. The logo itself has alt="", so
 *                  the name is read once, not twice
 *   subtitle       „Verwaltung" or „Mein Portal", for the person looking
 *   show_name      false only when she hid it and a logo or icon is there to
 *                  take its place: with only the „B", the name always shows
 *   show_subtitle  false when she hid the line
 */
function brand_header(?array $user): array {
    $logo = portal_logo_url();
    $icon = $logo === '' ? portal_icon_url() : '';
    [$width, $height] = $logo !== '' ? portal_logo_size() : [0, 0];
    return [
        'logo' => $logo, 'logo_width' => $width, 'logo_height' => $height,
        'icon' => $icon,
        'name' => (string)setting('club_name'),
        'subtitle' => $user && is_staff($user) ? t('Verwaltung', 'Management') : t('Mein Portal', 'My portal'),
        'show_name' => !(setting('header_hide_name') === true && ($logo !== '' || $icon !== '')),
        'show_subtitle' => setting('header_hide_subtitle') !== true,
    ];
}
