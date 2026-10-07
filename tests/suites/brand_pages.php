<?php
/**
 * The club's look as the pages draw it (ADR 0013, 0014). tests/suites/colour.php
 * checks the colour rules and brand_header(); this checks that the layout and
 * the Portal tab use them: the club's stylesheet only when a colour is set, the
 * logo, icon or „B" top left with the switches applied, the Logo and Aussehen
 * cards in their place, and the sign-in page's name readable in the dark.
 */
require_once TEST_ROOT.'/css.php';

$admin  = make_account(['role' => 'admin', 'name' => 'Admin Person']);
$family = make_account(['role' => 'student', 'name' => 'Familie Hofer']);
set_setting('club_name', 'TSV Beispiel');

/** The whole page, frame included, as views/layout.php draws it. */
function brand_page_html(string $page, array $query = [], bool $public = false): string {
    $content = $public ? '' : render_view($page, $query);
    $user = current_user(); $restore = $_GET; $_GET = $query; $GLOBALS['page'] = $page;
    ob_start(); require APP_ROOT.'/views/layout.php'; $html = (string)ob_get_clean(); $_GET = $restore;
    return $html;
}
function brand_xpath(string $html): DOMXPath {
    $dom = new DOMDocument();
    $quiet = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors(); libxml_use_internal_errors($quiet);
    return new DOMXPath($dom);
}
function brand_has_class(string $name): string { return "contains(concat(' ', normalize-space(@class), ' '), ' $name ')"; }
/** What one place top left shows: which kind, its picture, and the name and line as printed. */
function brand_place(string $html, string $where): array {
    $x = brand_xpath($html);
    $path = ['sidebar' => '//aside//a['.brand_has_class('brand').']', 'bar' => '//header['.brand_has_class('topbar').']/a['.brand_has_class('mobile-brand').']',
             'public' => '//header['.brand_has_class('public-header').']/a['.brand_has_class('brand').']'][$where];
    $a = $x->query($path)->item(0);
    if (!$a) return ['found' => false];
    preg_match('/brand-is-(\w+)/', $a->getAttribute('class'), $kind);
    $hidden = fn(string $q) => array_map(fn($n) => trim($n->textContent), iterator_to_array($x->query($q, $a)));
    $img = $x->query('.//img', $a)->item(0);
    return ['found' => true, 'kind' => $kind[1] ?? '', 'img' => $img ? $img->getAttribute('src') : '',
            'alt' => $img ? $img->getAttribute('alt') : null, 'size' => $img ? [$img->getAttribute('width'), $img->getAttribute('height')] : [],
            'text' => trim(preg_replace('/\s+/', ' ', $a->textContent)),
            'visuallyHidden' => $hidden('.//*['.brand_has_class('visually-hidden').']')];
}
$clearLook = function (): void {
    foreach (['portal_logo', 'portal_icon', 'brand_primary', 'brand_secondary', 'brand_highlight', 'brand_background',
              'brand_primary_dark', 'brand_secondary_dark', 'brand_highlight_dark', 'brand_background_dark'] as $key) set_setting($key, '');
    set_setting('header_hide_name', false); set_setting('header_hide_subtitle', false);
};
@mkdir(upload_dir('logo'), 0775, true); @mkdir(upload_dir('icon'), 0775, true);
$logo = str_repeat('3', 32).'.png';
file_put_contents(upload_dir('logo').'/'.$logo, "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 600, 150, 8, 6, 0, 0, 0).pack('N', 0));
$icon = str_repeat('4', 32).'.png';
file_put_contents(upload_dir('icon').'/'.$icon, 'x');

// ---------------------------------------------------------------------------
case_('The club\'s stylesheet is linked only when a colour is set, after app.css');
$clearLook();
sign_in_as($admin);
$html = brand_page_html('dashboard');
ok(!str_contains($html, 'page=brand'), 'a portal nobody customised asks for no stylesheet');
ok(str_contains($html, 'name="theme-color" content="#13243a"'), 'and its bar colour is the built-in menu colour');
set_setting('brand_secondary', '#e8eef4');
$html = brand_page_html('dashboard');
$app = strpos($html, 'assets/app.css'); $brand = strpos($html, 'href="'.e(brand_css_url()).'"');
ok($brand !== false, 'with a colour set, the stylesheet at its versioned address is linked');
ok($app !== false && $brand > $app, 'after app.css, so it wins where the two are equally specific');
ok(str_contains($html, 'name="theme-color" content="#e8eef4"'), 'and the bar colour is the club\'s menu colour');
sign_out();
ok(str_contains(brand_page_html('login', [], true), 'href="'.e(brand_css_url()).'"'), 'the sign-in page wears it too');
$clearLook();

// ---------------------------------------------------------------------------
case_('Top left: the „B" with the name, and the switches cannot leave nothing');
sign_in_as($admin);
$html = brand_page_html('dashboard');
$side = brand_place($html, 'sidebar');
ok($side['kind'] === 'mark' && str_contains($side['text'], 'TSV Beispiel') && str_contains($side['text'], 'Verwaltung') && $side['visuallyHidden'] === [],
   'the menu shows the „B", the name and „Verwaltung"');
is_same(['none', 'TSV Beispiel'], [brand_place($html, 'bar')['kind'], brand_place($html, 'bar')['text']], 'the phone bar shows the name');
set_setting('header_hide_name', true);
$side = brand_place(brand_page_html('dashboard'), 'sidebar');
is_same([], $side['visuallyHidden'], 'with only the „B", switching the name off still shows it');
set_setting('header_hide_subtitle', true);
is_same(['Verwaltung'], brand_place(brand_page_html('dashboard'), 'sidebar')['visuallyHidden'], 'the line under it can be switched off, and stays for a screen reader');
$clearLook();

case_('Top left with an icon: the icon beside the name, and alone in the phone bar when the name is off');
set_setting('portal_icon', $icon);
$html = brand_page_html('dashboard');
$side = brand_place($html, 'sidebar');
ok($side['kind'] === 'icon' && str_contains($side['img'], 'page=icon'), 'the menu shows the icon');
is_same('', $side['alt'], 'with an empty alt, because the name is beside it');
is_same('none', brand_place($html, 'bar')['kind'], 'the phone bar still shows the name while the name is on');
set_setting('header_hide_name', true);
$html = brand_page_html('dashboard');
is_same(['TSV Beispiel'], brand_place($html, 'sidebar')['visuallyHidden'], 'switched off, the name is there for a screen reader only');
$bar = brand_place($html, 'bar');
ok($bar['kind'] === 'icon' && $bar['visuallyHidden'] === ['TSV Beispiel'], 'and the phone bar shows the icon, with the name for a screen reader');
$clearLook();

case_('Top left with a logo: the logo on its plate, the name under it or read out');
set_setting('portal_logo', $logo);
$html = brand_page_html('dashboard');
foreach (['sidebar', 'bar'] as $where) {
    $place = brand_place($html, $where);
    ok($place['kind'] === 'logo' && str_contains($place['img'], 'page=logo'), 'the '.$where.' shows the logo');
    is_same(['600', '150'], $place['size'], 'with its width and height, so it keeps its shape without a style attribute');
    is_same('', $place['alt'], 'and an empty alt: the name is printed or read out beside it, once');
}
is_same(['TSV Beispiel'], brand_place($html, 'bar')['visuallyHidden'], 'in the phone bar the logo stands alone');
ok(str_contains(brand_place($html, 'sidebar')['text'], 'TSV Beispiel'), 'in the menu the name is under it');
sign_out();
$public = brand_place(brand_page_html('login', [], true), 'public');
ok($public['kind'] === 'logo', 'the sign-in page shows the logo too');
ok(!str_contains($public['text'], 'Verwaltung') && !str_contains($public['text'], 'Mein Portal'), 'without the line that says who is signed in');
$clearLook();

// ---------------------------------------------------------------------------
case_('The sign-in page\'s name is readable in the dark, with no colours set');
/* It was --navy on the page background: #13243a on #101922 in dark mode. It is
   --brand-ink now, the menu colour in light and the ink in both ways of being dark. */
$css = css_rules((string)file_get_contents(APP_ROOT.'/public/assets/app.css'));
$value = fn(string $selector, string $property) => array_column(array_filter(css_matching($css, '/^'.preg_quote($selector, '/').'$/'),
    fn($r) => $r['property'] === $property), 'value');
is_same(['var(--brand-ink)'], $value('.public-header .brand', 'color'), 'the name is drawn in --brand-ink');
is_same(['var(--navy)'], $value(':root', '--brand-ink'), 'which is the menu colour in light');
is_same(['var(--ink)'], $value('html:not([data-theme=light])', '--brand-ink'), 'the ink when the device is dark');
is_same(['var(--ink)'], $value('html[data-theme=dark]', '--brand-ink'), 'and when dark is chosen');

// ---------------------------------------------------------------------------
case_('The Portal tab: its settings, then Logo, Aussehen and the icon, each its own form');
sign_in_as($admin);
$page = render_view('settings', ['tab' => 'portal']);
$x = brand_xpath($page);
$cards = array_map(fn($n) => $n->getAttribute('id'), iterator_to_array($x->query('//section['.brand_has_class('card').']')));
is_same(['', 'logo', 'branding', 'icon'], $cards, 'in that order');
$logoForms = $x->query('//section[@id="logo"]//form');
is_same(1, $logoForms->length, 'with no logo, the logo card has one form, the upload');
is_same('multipart/form-data', $logoForms->item(0)->getAttribute('enctype'), 'which can carry a file');
is_same('portal_logo_save', $x->query('.//input[@name="action"]', $logoForms->item(0))->item(0)?->getAttribute('value'), 'and saves the logo');
ok(str_contains($page, 'Noch kein Logo'), 'the preview says there is none yet');
/* The card states the logo's own limit, never the general one: a card that
   promises more than check_portal_logo() accepts gets a refusal after the upload. */
preg_match('/Höchstens ([0-9]+),([0-9]) MB\./u', (string)$x->query('//section[@id="logo"]//small')->item(0)?->textContent, $stated);
is_same('1,0', isset($stated[1]) ? $stated[1].','.$stated[2] : null, 'the logo card says „Höchstens 1,0 MB"');
ok(isset($stated[1]) && (float)($stated[1].'.'.$stated[2]) * 1048576 <= PORTAL_LOGO_MAX_BYTES, 'which is no more than the logo check accepts');
$branding = $x->query('//section[@id="branding"]//form')->item(0);
ok($branding !== null, 'the Aussehen card has its form');
$field = fn(string $name) => $x->query('.//*[@name="'.$name.'"]', $branding)->length;
is_same('branding', $x->query('.//input[@name="group"]', $branding)->item(0)?->getAttribute('value'), 'saving the branding group');
foreach (array_keys(settings_in_group('branding')) as $key) is_same(1, $field('set_'.$key), 'with '.$key.' on it, so a save cannot blank it');
is_same(0, $x->query('//section[@id="logo"]//*[@name="set_header_hide_name"]')->length, 'the switches are not on the logo card');
$fieldsLink = '//a[contains(@href, "tab=fields")]';
is_same([0, 0], [$x->query($fieldsLink)->length, brand_xpath(render_view('settings', ['tab' => 'system']))->query($fieldsLink)->length],
        'nothing leads to custom fields any more, on the Portal tab or in the System tab\'s „Erweitert" (ADR 0026 §7)');
$pickerLabels = array_map(fn($n) => $n->getAttribute('data-picker-label'), iterator_to_array($x->query('//section[@id="branding"]//*[@data-picker-label]')));
is_same(count(array_filter(settings_in_group('branding'), fn($s) => $s['kind'] === 'colour')), count($pickerLabels), 'every colour field names its picker');
ok(in_array('Farbe auswählen: Hauptfarbe', $pickerLabels, true), 'in the page\'s language, with the field\'s name');
$ids = array_map(fn($n) => $n->getAttribute('id'), iterator_to_array($x->query('//*[@id]')));
is_same([], array_values(array_unique(array_diff_assoc($ids, array_unique($ids)))), 'no id is used twice on the page');

case_('A colour field says what empty means, and which colour is really used');
$primary = $x->query('//section[@id="branding"]//div['.brand_has_class('colour-field').'][.//input[@name="set_brand_primary"]]')->item(0);
ok($primary !== null, 'the main colour has its field');
is_same('#077e76', $x->query('.//input[@name="set_brand_primary"]', $primary)->item(0)?->getAttribute('placeholder'), 'empty shows the built-in colour');
is_same('#077e76', $x->query('.//*[@data-default]', $primary)->item(0)?->getAttribute('data-default'), 'which „Standard übernehmen" goes back to');
ok(str_contains($primary->textContent, 'Verwendet: #077e76'), 'and the line under it names the colour in use');
is_same(1, $x->query('.//span['.brand_has_class('swatch-brand-primary').']', $primary)->length, 'on a swatch coloured by class');
is_same(0, $x->query('//section[@id="branding"]//*[@style]')->length, 'nothing on the card has a style attribute');
set_setting('brand_primary', '#f5e663');
$x = brand_xpath(render_view('settings', ['tab' => 'portal']));
$primary = $x->query('//section[@id="branding"]//div['.brand_has_class('colour-field').'][.//input[@name="set_brand_primary"]]')->item(0);
$used = brand_palette()['used']['brand_primary'];
ok($used !== '#f5e663' && str_contains((string)$primary?->textContent, 'Für gute Lesbarkeit verwendet: '.$used.' – deine Wahl: #f5e663'),
   'a pale yellow is moved, and the card says to which colour, beside hers ('.$used.')');
is_same(1, $x->query('.//span['.brand_has_class('swatch-brand-primary-chosen').']', $primary)->length, 'with a swatch of her own choice too');
$clearLook();

case_('With a logo, the card shows it and offers to remove it, with the way back');
set_setting('portal_logo', $logo);
$x = brand_xpath(render_view('settings', ['tab' => 'portal']));
is_same(2, $x->query('//section[@id="logo"]//form')->length, 'two forms: replace, and remove');
is_same('1', $x->query('//section[@id="logo"]//form[.//input[@name="remove"]]//input[@name="remove"]')->item(0)?->getAttribute('value'), 'the second removes it');
ok(str_contains((string)$x->query('//section[@id="logo"]')->item(0)?->textContent, 'Zum Zurückholen einfach wieder hochladen.'), 'and says how to get it back');
ok(str_contains((string)$x->query('//section[@id="logo"]//img')->item(0)?->getAttribute('src'), 'page=logo'), 'the preview is the logo itself');
$clearLook();

unlink(upload_dir('logo').'/'.$logo); unlink(upload_dir('icon').'/'.$icon);
