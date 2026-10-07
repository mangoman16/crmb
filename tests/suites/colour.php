<?php
/**
 * The club's colours (ADR 0013): the arithmetic, the stylesheet it becomes,
 * and the „Aussehen" card that sets them.
 *
 * A free colour choice can make text unreadable, and the components print
 * white - or the dark scheme's dark ink - on the main colour without asking.
 * So the promise worth testing is not "the colour she typed is used" but "text
 * on it and with it still reads", for colours nobody would pick on purpose.
 */

$admin = make_account(['role'=>'admin']); sign_in_as($admin);

/** Every branding key as the card posts it, with $over on top. */
function branding_post(array $over = []): array {
    $post = ['group' => 'branding', 'to_page' => 'settings', 'to_tab' => 'portal'];
    foreach (settings_in_group('branding') as $key => $spec)
        $post['set_'.$key] = $spec['kind'] === 'bool' ? (setting($key) ? '1' : '') : (string)setting($key);
    return array_merge($post, $over);
}

/** The eight colours back to built-in. */
function branding_clear(): void {
    foreach (settings_in_group('branding') as $key => $spec) set_setting($key, $spec['default']);
}

case_('A colour is read the way people write one, and nothing else is');
is_same('#1f5fa9', colour_normalise('#1F5FA9'), 'upper case is lowered');
is_same('#1f5fa9', colour_normalise('1f5fa9'), 'the # is optional');
is_same('#aabbcc', colour_normalise('#abc'), 'the short form is spelled out');
is_same('#aabbcc', colour_normalise(' ABC '), 'surrounding spaces from a copy and paste are dropped');
foreach (['', '#', 'red', '#12345', '#1234567', '#ggg000', 'rgb(1,2,3)', 'red;}body{display:none', "#fff\n}", '#fff;'] as $bad)
    is_same(null, colour_normalise($bad), test_show($bad).' is not a colour');

case_('The WCAG figures come out as the standard gives them');
is_same(1.0, round(colour_luminance('#ffffff'), 6), 'white has luminance 1');
is_same(0.0, round(colour_luminance('#000000'), 6), 'black has luminance 0');
is_same(0.2159, round(colour_luminance('#808080'), 4), 'mid grey #808080 is 0.2159');
is_same(21.0, round(colour_contrast('#000000', '#ffffff'), 2), 'black on white is 21:1');
is_same(21.0, round(colour_contrast('#ffffff', '#000000'), 2), 'in either order');
is_same(4.54, round(colour_contrast('#767676', '#ffffff'), 2), '#767676 on white is 4.54:1, the lightest grey that passes AA');
is_same(4.48, round(colour_contrast('#777777', '#ffffff'), 2), 'and #777777 is 4.48:1, which does not');
is_same(1.0, colour_contrast('#077e76', '#077e76'), 'a colour on itself is 1:1');

case_('Blending is by weight, and a weight out of range gives one of the two colours');
is_same('#808080', colour_mix('#ffffff', '#000000', .5020), 'half white, half black is mid grey');
is_same('#ffffff', colour_mix('#ffffff', '#000000', 1), 'all of the first is the first');
is_same('#000000', colour_mix('#ffffff', '#000000', 0), 'none of it is the second');
is_same('#ffffff', colour_mix('#ffffff', '#000000', 7), 'too much is the first, never a channel above ff');
is_same('#000000', colour_mix('#ffffff', '#000000', -3), 'too little is the second, never below 00');

case_('Text on a colour is white or the dark ink, whichever reads better');
is_same('#ffffff', colour_text_on('#13243a'), 'white on the built-in navy');
is_same('#0b1218', colour_text_on('#e8eef4'), 'dark ink on a pale menu colour');
is_same('#0b1218', colour_text_on('#ffff00'), 'and on yellow');

case_('Hue, saturation and lightness go there and back without losing a step');
foreach (['#077e76', '#1f5fa9', '#8a1538', '#23cbbb', '#13243a', '#fdf6b2', '#777777', '#ff0000', '#000000', '#ffffff'] as $c)
    is_same($c, colour_from_hsl(...colour_to_hsl($c)), $c.' survives the round trip');
is_same([0.0, 1.0, 0.5], colour_to_hsl('#ff0000'), 'pure red is hue 0, fully saturated, half light');
is_same('#000000', colour_from_hsl(54, .95, -1), 'a lightness below 0 is black, never a channel below 00');
is_same('#ffffff', colour_from_hsl(54, .95, 2), 'and above 1 is white');

case_('Only the lightness moves: the club’s colour keeps its hue and saturation');
/* Known answers, worked out once and checked by eye. Blending toward black
   turned the pale yellow into a grey olive (#7b7756); moving only the
   lightness gives a dark mustard she recognises as her yellow. */
foreach ([['#fdf6b2', 'darker', ['#ffffff'], '#837703', 'very light yellow, darkened for white text: a dark mustard'],
          ['#fbd3e0', 'darker', ['#ffffff'], '#e71559', 'pale pink, darkened: a raspberry'],
          ['#0a1030', 'lighter', ['#182430', '#0b1218'], '#7183e1', 'very dark navy, lightened for the dark scheme: a mid blue'],
          ['#777777', 'darker', ['#ffffff'], '#767676', 'mid grey, one step darker and still grey'],
          ['#ff0000', 'darker', ['#ffffff'], '#ee0000', 'pure red, still pure red']] as [$chosen, $direction, $against, $expected, $what]) {
    $used = colour_until_contrast($chosen, $against, 4.5, $direction);
    is_same($expected, $used, $what);
    [$h, $s] = colour_to_hsl($chosen);
    [$h2, $s2, $l2] = colour_to_hsl($used);
    ok(abs($h - $h2) < 1 && abs($s - $s2) < .02, $chosen.': hue and saturation kept ('.round($h2, 1).'°, '.round($s2, 3).')');
    ok(colour_contrasts_with_all($used, $against, 4.5), $chosen.': and it reads');
    $lessMoved = colour_from_hsl($h2, $s2, $l2 + ($direction === 'darker' ? .01 : -.01));
    ok(!colour_contrasts_with_all($lessMoved, $against, 4.5), $chosen.': moved no further than it had to be - one percent less would fail again');
}

case_('A colour that reads is not touched, and the walk stops at the end of the line');
is_same('#077e76', colour_until_contrast('#077e76', ['#ffffff'], 4.5, 'darker'), 'a colour that already reads is not touched');
is_same('#ffffff', colour_until_contrast('#eeeeee', ['#ffffff'], 4.5, 'lighter'),
        'when even the end of the line cannot reach it, the end of the line is the answer - not a loop, and not the colour that failed');
is_same('#000000', colour_until_contrast('#101010', ['#000000', '#ffffff'], 30, 'darker'),
        'an impossible ratio stops at black when walking darker');
throws(fn() => colour_until_contrast('#101010', ['#ffffff'], 4.5, '#000000'), 'a direction is darker or lighter, nothing else', 'darker or lighter');

// ---------------------------------------------------------------------------
// The palette: every guarantee, for colours nobody would pick on purpose
// ---------------------------------------------------------------------------

$pale = '#fdf6b2';   // very light yellow
$navy = '#0a1030';   // very dark navy
$grey = '#777777';   // mid grey, just under 4.5 on white
$red  = '#ff0000';   // pure red, 4.0 on white
$presets = ['#06736c', '#1f5fa9', '#6c4bb6', '#b03a72', '#b5432f', '#9a5a12', '#3a7a2e', '#41566b'];

case_('Whatever the main colour, white reads on its buttons in light and the dark ink in dark');
foreach (array_merge($presets, [$pale, $navy, $grey, $red, '#ffffff', '#000000']) as $p) {
    branding_clear();
    set_setting('brand_primary', $p);
    $palette = brand_palette();
    $light = $palette['light'];
    $dark = $palette['dark'];
    ok(colour_contrast($light['--teal'], '#ffffff') >= 4.5, $p.': light --teal '.$light['--teal'].' reads against white, as text and under white text');
    ok(colour_contrast($light['--teal'], '#f2f2f7') >= 4.5, $p.': and as text on the grey ground around the groups');
    is_same('#ffffff', $light['--on-accent'], $p.': and the stylesheet says white is what is printed on it');
    ok(colour_contrast($light['--teal-ink'], $light['--teal-soft']) >= 4.5, $p.': --teal-ink reads on --teal-soft');
    ok(colour_contrast($dark['--teal'], '#0b1218') >= 4.5, $p.': dark --teal '.$dark['--teal'].' carries the dark ink');
    foreach (['#1c1c1e' => 'a dark group', '#2c2c2e' => 'a field inside one'] as $surface => $what)
        ok(colour_contrast($dark['--teal'], $surface) >= 4.5, $p.': and reads as text on '.$what);
    is_same('#0b1218', $dark['--on-accent'], $p.': and the stylesheet says the dark ink is what is printed on it');
    foreach (['#1c1c1e', '#2c2c2e'] as $surface)
        ok(colour_contrast($dark['--teal-ink'], $surface) >= 7, $p.': dark --teal-ink reaches 7:1 on '.$surface);
    is_same($light['--teal'], $light['--focus'], $p.': the focus ring is the main colour');
}

case_('A main colour that had to be moved is reported with both colours');
branding_clear();
set_setting('brand_primary', $pale);
$palette = brand_palette();
is_same(['chosen' => $pale, 'used' => $palette['light']['--teal']], $palette['adjusted']['brand_primary'] ?? null,
        'pale yellow: her choice and the colour used, for „Für gute Lesbarkeit verwendet"');
is_same('#7b7003', $palette['light']['--teal'], 'which is a dark mustard, not a grey - darkened until it reads on the grey ground as well as on white');
ok($palette['light']['--teal'] !== $pale, 'and they really differ');
set_setting('brand_primary', '#1f5fa9');
ok(!isset(brand_palette()['adjusted']['brand_primary']), 'a blue that already reads is used as chosen and not reported');
is_same('#1f5fa9', brand_palette()['used']['brand_primary'], 'and is what the card says is in use');

case_('The main colour reads on the club\'s own background too');
/* Text in the main colour - a link under a group, „Alle ansehen" beside a
   heading - stands on the background behind the groups as well as in them, and
   a background she chose may be darker than the built-in grey. */
branding_clear();
set_setting('brand_background', '#e5e5e5');
set_setting('brand_primary', $grey);
$palette = brand_palette();
is_same('#e5e5e5', $palette['used']['brand_background'], 'a grey background still light enough for grey text is used as chosen');
ok(colour_contrast($palette['light']['--teal'], '#e5e5e5') >= 4.5,
   'and a mid-grey main colour is darkened until it reads on it, not only on the built-in grey: '.$palette['light']['--teal']);

case_('The soft tint is mixed into the group it fills, in dark as in light');
/* --teal-soft fills a selected row or a pale button inside a group. Mixed into
   the black ground behind the groups instead, it came out darker than the
   group around it: a hole rather than a tint. */
branding_clear();
set_setting('brand_primary', '#06736c');
is_same('#182c2c', brand_palette()['dark']['--teal-soft'],
        'the built-in teal, chosen: 18 % of it in a dark group (#1c1c1e), not in the black ground (#011513)');

case_('The menu keeps readable text on any menu colour');
foreach ([$navy, '#e8eef4', $grey, $red, $pale, '#13243a'] as $n) {
    branding_clear();
    set_setting('brand_secondary', $n);
    foreach (['light', 'dark'] as $scheme) {
        $t = brand_palette()[$scheme];
        $best = max(colour_contrast($t['--nav-bg'], '#ffffff'), colour_contrast($t['--nav-bg'], '#0b1218'));
        is_same(colour_text_on($t['--nav-bg']), $t['--on-nav'], $n.' '.$scheme.': --on-nav is whichever text reads better');
        ok(colour_contrast($t['--nav-ink'], $t['--nav-bg']) >= min(7, $best), $n.' '.$scheme.': the entries reach 7:1, or the best there is');
        ok(colour_contrast($t['--nav-ink-2'], $t['--nav-bg']) >= min(4.5, $best), $n.' '.$scheme.': the quieter line reaches 4.5:1, or the best there is');
    }
    is_same($n, brand_palette()['light']['--nav-bg'], $n.': the menu colour itself is used as chosen - it is the club’s');
    ok(colour_contrast(brand_palette()['light']['--brand-ink'], '#f2f2f7') >= 4.5, $n.': the club’s name on the sign-in page reads on the background');
    ok(!isset(brand_palette()['dark']['--brand-ink']),
       $n.': in dark mode the name is app.css’s own ink, which reads on every dark background - nothing to override');
}

case_('The highlight is moved until it shows against the menu');
branding_clear();
set_setting('brand_highlight', '#13243a');   // the built-in navy itself: invisible on the menu
$palette = brand_palette();
ok(colour_contrast($palette['light']['--teal-bright'], '#13243a') >= 3, 'the marker reaches 3:1 on the built-in menu');
ok(colour_contrast($palette['dark']['--teal-bright'], '#131d27') >= 3, 'and on the built-in dark menu');
ok(isset($palette['adjusted']['brand_highlight']), 'and is reported as moved');

case_('A dark override applies only while its light colour is set');
branding_clear();
set_setting('brand_primary_dark', '#ff9900');
is_same([], brand_palette()['dark'], 'a dark colour alone changes nothing');
is_same('', brand_css_url(), 'so no stylesheet is requested for it');
set_setting('brand_primary', '#1f5fa9');
is_same('#ff9900', brand_palette()['dark']['--teal'], 'with a light colour set, the dark override is used');
is_same('#3fd0bd', setting_schema()['brand_primary_dark']['builtin'], 'the built-in dark main colour is app.css’s');
set_setting('brand_primary_dark', '');
ok(brand_palette()['placeholder']['brand_primary_dark'] === brand_palette()['dark']['--teal'],
   'an empty override shows, as its placeholder, the colour worked out from the light one');

case_('Background and theme colour');
branding_clear();
/* The phone's own bar runs into the portal's bars, which sit on the ground:
   grey in light and black in dark (design language, Part 0.5) - not the menu
   colour, which is the menu's on a computer. */
is_same('#f2f2f7', brand_theme_colour('light'), 'uncustomised: the bar is the grey ground');
is_same('#000000', brand_theme_colour('dark'), 'and the black ground in dark');
is_same('#f2f2f7', brand_background_colour(), 'an install opens on the built-in background');
set_setting('brand_secondary', '#8a1538');
set_setting('brand_background', '#fffaf0');
is_same('#fffaf0', brand_theme_colour('light'), 'the bar follows her background, not the menu colour');
is_same(colour_mix('#fffaf0', '#000000', .06), brand_theme_colour('dark'), 'and the dark background worked out from hers');
is_same('#fffaf0ee', brand_palette()['light']['--scrim-bg'], 'the scrim is her background, nearly opaque');
is_same('#fffaf0', web_manifest()['theme_color'], 'the manifest’s bar colour too');
is_same('#fffaf0', web_manifest()['background_color'], 'and the colour an installed portal opens on');

// ---------------------------------------------------------------------------
// The stylesheet
// ---------------------------------------------------------------------------

case_('A portal nobody customised requests no stylesheet at all');
branding_clear();
is_same('', brand_css_url(), 'no address, so the layout prints no link');
is_same('', brand_css(), 'and the stylesheet is empty');
is_same([], brand_palette()['light'], 'no token is overridden');

case_('The stylesheet repeats app.css’s selectors, and holds colours and nothing else');
set_setting('brand_primary', '#8a1538');
set_setting('brand_secondary', '#0a1030');
set_setting('brand_highlight', '#ffcc00');
set_setting('brand_background', '#fffaf0');
$css = brand_css();
foreach (['html[data-accent=brand]{', ':root{', '@media(prefers-color-scheme:dark){',
          'html:not([data-theme=light])[data-accent=brand]{', 'html:not([data-theme=light]){',
          'html[data-theme=dark][data-accent=brand]{', 'html[data-theme=dark]{',
          '.accent-dot.is-default{background:#8a1538}', '.brand-swatch.swatch-brand-primary{'] as $selector)
    ok(str_contains($css, $selector), 'it has '.$selector);
ok(strpos($css, 'html[data-accent=brand]{') < strpos($css, '@media'), 'light comes before dark, so dark wins where it applies');
ok(strpos($css, '@media') < strpos($css, 'html[data-theme=dark][data-accent=brand]{'),
   'and a chosen dark theme comes last, as in app.css');
$body = preg_replace('#/\*.*?\*/#s', '', $css);
preg_match_all('/\{([^{}]*)\}/', $body, $blocks);
$declarations = 0;
foreach ($blocks[1] as $block) foreach (explode(';', $block) as $declaration) {
    $declarations++;
    ok(preg_match('/^(--[a-z][a-z0-9-]*|background):#[0-9a-f]{6}([0-9a-f]{2})?$/D', $declaration) === 1,
       test_show($declaration).' is a token or a background, set to a colour');
}
ok($declarations > 40, 'and there are '.$declarations.' of them, so the check read the stylesheet');
ok(!preg_match('/url\(|@import|expression|<|\\\\/i', $css), 'no url(), no @import, nothing that is not a colour');

case_('A value stored without the form never reaches the stylesheet');
branding_clear();
set_setting('brand_primary', 'red;}body{display:none');   // set_setting() stores what it is given; only the form validates
is_same('', brand_chosen()['brand_primary'], 'a tampered main colour reads as built-in');
is_same('', brand_css(), 'and produces no CSS text');
is_same('teal', accent_for(['accent' => '']), 'nor does it switch the page to the brand accent');
set_setting('brand_primary', ['#fff']);
is_same('', brand_chosen()['brand_primary'], 'a value that is not even text reads as built-in');
set_setting('brand_background', '#333333');
is_same('', brand_chosen()['brand_background'], 'a background the form would refuse reads as built-in too');
ok(brand_css_rule('x', ['--teal' => 'red;}', 'color' => '#ffffff', '--ok' => '#123456']) === "x{--ok:#123456}\n",
   'and the rule builder drops anything but a token set to a colour, as the last line of defence');

case_('The address changes when the stylesheet does, and the old one is not kept');
branding_clear();
set_setting('brand_primary', '#1f5fa9');
$first = brand_css_version();
ok(preg_match('/^[a-f0-9]{12}$/D', $first) === 1, 'the version is twelve hex characters');
ok(str_contains(brand_css_url(), 'page=brand') && str_contains(brand_css_url(), 'v='.$first), 'served by the router at that version');
set_setting('brand_primary', '#1f5fa8');
$second = brand_css_version();
ok($first !== $second, 'one step of one channel is a new address');
set_setting('brand_primary_dark', '#ff9900');
$third = brand_css_version();
ok($second !== $third, 'and so is a dark override that applies');
set_setting('brand_background_dark', '#0a0a0a');
is_same($third, brand_css_version(),
        'one whose light colour is not set changes nothing on the page, so the address and every cached copy stay');
is_same('public, max-age=31536000, immutable', brand_css_cache_control(brand_css_version()),
        'the current address is kept for a year, by any cache');
is_same('no-cache', brand_css_cache_control($first), 'an old address is asked again every time');
is_same('no-cache', brand_css_cache_control(null), 'and one with no version');
is_same('no-cache', brand_css_cache_control(['x']), 'or with a version that is not text');

case_('The address is a hash of the stylesheet itself, not of her colours and the release number');
/* Commit 667367a changed how the colours are worked out, and VERSION stayed
   0.6.0. The address was a hash of the release number and her choices, so it
   stayed the same, and the route had told every browser to keep that address
   for a year, unchanged. A run of the suite cannot change the derivation
   half way, so the rule is held as what it is: whatever the page links is the
   hash of the bytes the route sends. Then other bytes are another address,
   whatever made them other - her colours, or a release. */
$configurations = [
    'the main colour alone' => ['brand_primary' => '#8a1538'],
    'the menu colour alone' => ['brand_secondary' => '#0a1030'],
    'all four, with a dark override' => ['brand_primary' => '#8a1538', 'brand_secondary' => '#0a1030',
        'brand_highlight' => '#ffcc00', 'brand_background' => '#fffaf0', 'brand_primary_dark' => '#ff9900'],
];
$versions = [];
foreach ($configurations as $what => $colours) {
    branding_clear();
    foreach ($colours as $key => $colour) set_setting($key, $colour);
    $body = brand_css();
    parse_str((string)parse_url(brand_css_url(), PHP_URL_QUERY), $query);
    ok($body !== '', $what.': there is a stylesheet to send');
    is_same(substr(hash('sha256', $body), 0, 12), $query['v'] ?? null, $what.': the page links the hash of the bytes the route sends');
    is_same('public, max-age=31536000, immutable', brand_css_cache_control($query['v'] ?? null),
            $what.': and the route lets that address be kept');
    $versions[$what] = $query['v'] ?? '';
}
is_same(count($configurations), count(array_unique($versions)), 'each stylesheet has an address of its own');

case_('Everybody without a colour of their own gets the club’s main colour');
branding_clear();
set_setting('default_accent', 'violet');
is_same('violet', accent_for(['accent' => '']), 'without a main colour, the preset the administrator chose');
set_setting('brand_primary', '#8a1538');
is_same('brand', accent_for(['accent' => '']), 'with one, the club’s');
is_same('brand', accent_for(null), 'on the sign-in page too');
is_same('pink', accent_for(['accent' => 'pink']), 'and a colour somebody picked for themselves still wins');
ok(!isset(accents()['brand']), '„brand“ is not a preset anybody can pick');
set_setting('default_accent', 'teal');

// ---------------------------------------------------------------------------
// The „Aussehen" card
// ---------------------------------------------------------------------------

case_('The card saves colours as colours, and names what they were');
branding_clear();
set_setting('brand_primary', '#077e76');
act('defaults_registry_save', branding_post(['set_brand_primary' => '1F5FA9', 'set_brand_secondary' => '#8a1538']));
is_same('#1f5fa9', setting('brand_primary'), 'stored normalised');
is_same('#8a1538', setting('brand_secondary'), 'the menu colour too');
is_same('Vorgaben gespeichert. Vorher: Hauptfarbe #077e76, Menüfarbe Standard.', $_SESSION['flash']['message'] ?? null,
        'the message is the way back: each changed colour with what it was, „Standard" for built-in');
act('defaults_registry_save', branding_post());
is_same('Vorgaben gespeichert.', $_SESSION['flash']['message'] ?? null, 'saving with nothing changed names nothing');
$_SESSION['locale'] = 'en';
act('defaults_registry_save', branding_post(['set_brand_primary' => '', 'set_brand_highlight_dark' => '#abc']));
is_same('Defaults saved. Before: main colour #1f5fa9, highlight in dark mode built-in.', $_SESSION['flash']['message'] ?? null,
        'in English too, mid-sentence in lower case');
is_same('#aabbcc', setting('brand_highlight_dark'), 'a dark override is stored without its light colour, for when one is set');
set_setting('brand_background', '#444444');   // stored without the form; brand_chosen() shows it as built-in
act('defaults_registry_save', branding_post(['set_brand_background' => '#fffaf0']));
is_same('Defaults saved. Before: background built-in.', $_SESSION['flash']['message'] ?? null,
        'a stored background the form would refuse was showing as built-in, and is named so - not as a colour to type back in');
$_SESSION['locale'] = 'de';

case_('A refusal leaves the whole card as it was');
branding_clear();
set_setting('brand_primary', '#077e76');
set_setting('header_hide_name', false);
$target = null;
throws(function () use (&$target) { $target = act('defaults_registry_save', branding_post(
            ['set_brand_primary' => '#123456', 'set_header_hide_name' => '1', 'set_brand_background' => '#444444'])); },
       'a background grey text cannot be read on is refused', 'Hintergrund: Auf diesem Hintergrund wäre die graue Schrift schwer zu lesen. Bitte eine hellere Farbe wählen (eingebaut: #f2f2f7). Nichts wurde gespeichert.');
is_same('#077e76', setting('brand_primary'), 'the main colour typed beside it was not saved');
is_same(false, setting('header_hide_name'), 'nor the switch');
throws(fn() => act('defaults_registry_save', branding_post(['set_brand_background_dark' => '#eeeeee'])),
       'a dark background too light for the dark grey text is refused, asking for a darker one', 'dunklere Farbe wählen (eingebaut: #000000)');
throws(fn() => act('defaults_registry_save', branding_post(['set_brand_highlight' => 'gelb'])),
       'something that is not a colour is refused with an example', 'Hervorhebung: Bitte eine Farbe wie #1f5fa9 eingeben.');
throws(fn() => act('defaults_registry_save', branding_post(['set_brand_primary' => ['#fff']])),
       'and so is a list where one value was expected', 'Ungültige Eingabe');
is_same('#077e76', setting('brand_primary'), 'after all of which the card is as it was');
does_not_throw(fn() => act('defaults_registry_save', branding_post(['set_brand_primary' => '#fdf6b2'])),
       'a main colour that is too pale is not refused - it is adjusted, because a club’s colours are not hers to change');

case_('The card and its switches belong to the administrator, and it returns to the Portal tab');
branding_clear();
$target = act('defaults_registry_save', branding_post(['set_header_hide_name' => '1', 'to_tab' => '']));
is_same(['settings', ['tab' => 'portal']], $target, 'the „Aussehen" card has no tab of its own');
is_same(true, setting('header_hide_name'), 'the switch is saved as posted');
act('defaults_registry_save', branding_post(['set_header_hide_name' => '']));
is_same(false, setting('header_hide_name'), 'and unticked, as posted');
$trainer = make_account(['role' => 'trainer']);
sign_in_as($trainer);
throws(fn() => act('defaults_registry_save', branding_post(['set_brand_primary' => '#8a1538'])), 'a trainer may not', 'Administratoren');
is_same('', setting('brand_primary'), 'and nothing changed');
sign_in_as($admin);

case_('Each built-in colour is declared once, and app.css and the palette agree with it');
/* defaults.php's 'builtin' is the one place: the palette falls back to it and
   the form shows it. app.css cannot read PHP, so its swatches - the built-in
   colours the „Aussehen" card shows before anything is set - are held to it
   here. */
require_once TEST_ROOT.'/css.php';
branding_clear();
$palette = brand_palette();
$swatches = [];
foreach (css_rules((string)file_get_contents(APP_ROOT.'/public/assets/app.css')) as $row)
    if ($row['media'] === '' && $row['property'] === 'background'
        && preg_match('/^\.brand-swatch\.swatch-([a-z-]+)$/D', $row['selector'], $m))
        $swatches[str_replace('-', '_', $m[1])] = $row['value'];
foreach (brand_families() as $family) foreach ([$family, $family.'_dark'] as $key) {
    $builtin = setting_schema()[$key]['builtin'];
    is_same($builtin, $palette['used'][$key], $key.': with nothing set, the palette uses the declared built-in colour');
    is_same($builtin, $swatches[$key] ?? null, $key.': and app.css’s swatch shows that same colour');
}
is_same(8, count($swatches), 'app.css has a swatch for each of the eight, and no other');

case_('Every branding key is a colour or a switch, and belongs on the one card');
$branding = settings_in_group('branding');
is_same(10, count($branding), 'eight colours and two switches');
foreach ($branding as $key => $spec) {
    ok(in_array($spec['kind'], ['colour', 'bool'], true), $key.' is a '.$spec['kind']);
    if ($spec['kind'] === 'colour') {
        is_same('', $spec['default'], $key.' defaults to the built-in colour');
        ok(colour_normalise((string)($spec['builtin'] ?? '')) === ($spec['builtin'] ?? null), $key.' names its built-in colour');
    }
}
foreach (['brand_background', 'brand_background_dark'] as $key)
    ok(colour_contrast(setting_schema()[$key]['builtin'], setting_schema()[$key]['text_on']) >= 4.5,
       $key.': the built-in background passes its own rule');

// ---------------------------------------------------------------------------
// The top left: logo, icon or „B"
// ---------------------------------------------------------------------------

case_('The top left falls back from logo to icon to „B", and the name never leaves nothing');
branding_clear();
set_setting('portal_logo', ''); set_setting('portal_icon', ''); set_setting('club_name', 'TSV Beispiel');
set_setting('header_hide_name', true); set_setting('header_hide_subtitle', true);
$staff = brand_header(one('SELECT * FROM accounts WHERE id=?', [$admin]));
is_same(['', '', 'TSV Beispiel', true, false], [$staff['logo'], $staff['icon'], $staff['name'], $staff['show_name'], $staff['show_subtitle']],
        'with only the „B", the name shows even when hidden - otherwise nothing is left top left');
is_same('Verwaltung', $staff['subtitle'], 'staff see „Verwaltung"');
is_same('Mein Portal', brand_header(null)['subtitle'], 'everybody else „Mein Portal"');
@mkdir(upload_dir('icon'), 0775, true);
$icon = str_repeat('1', 32).'.png';
file_put_contents(upload_dir('icon').'/'.$icon, 'x');
set_setting('portal_icon', $icon);
$header = brand_header(null);
ok($header['icon'] !== '' && $header['logo'] === '', 'with an icon and no logo, the icon');
is_same(false, $header['show_name'], 'and the name may be hidden now');
@mkdir(upload_dir('logo'), 0775, true);
$logo = str_repeat('2', 32).'.png';
file_put_contents(upload_dir('logo').'/'.$logo, "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 600, 150, 8, 6, 0, 0, 0).pack('N', 0));
set_setting('portal_logo', $logo);
$header = brand_header(null);
ok(str_contains($header['logo'], 'page=logo'), 'with a logo, the logo');
is_same('', $header['icon'], 'and not the icon beside it');
is_same([600, 150], [$header['logo_width'], $header['logo_height']], 'with its size, for the width and height attributes');
set_setting('header_hide_name', false); set_setting('header_hide_subtitle', false);
is_same([true, true], [brand_header(null)['show_name'], brand_header(null)['show_subtitle']], 'both switches off: both lines show');
unlink(upload_dir('logo').'/'.$logo); unlink(upload_dir('icon').'/'.$icon);
set_setting('portal_logo', ''); set_setting('portal_icon', '');

branding_clear();
