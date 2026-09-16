<?php
/** The defaults registry: nothing should ever read as undefined. */

case_('Every declared setting has a usable default');
foreach (setting_schema() as $key => $spec) {
    ok(array_key_exists('default', $spec), $key.' declares a default');
    ok(isset($spec['kind']), $key.' declares a kind');
    ok(isset($spec['label'][0], $spec['label'][1]), $key.' is labelled in both languages');
}

case_('A key with no stored row reads as its declared default');
run('DELETE FROM settings');
setting_cache_clear();
is_same('Badminton', setting('club_name'), 'falls back to the declared value');
is_same(14, setting('billing_due_days'), 'an integer default keeps its type');
is_same(false, setting('privacy_ready'), 'a boolean default keeps its type');
ok(is_array(setting('statuses')), 'a map default is an array');
throws(fn() => setting_default('no_such_setting'), 'an undeclared key is an error, not a silent empty string');

case_('A stored value wins, including a falsy one');
set_setting('club_name', 'Verein X');
is_same('Verein X', setting('club_name'), 'the stored value is returned');
set_setting('billing_due_days', 0);
is_same(0, setting('billing_due_days'), 'zero is a real value, not treated as absent');
set_setting('portal_tagline', '');
is_same('', setting('portal_tagline'), 'an empty string is a real value too');

case_('Validation matches what each kind promises');
$int = setting_schema()['billing_due_days'];
is_same(30, setting_validate('billing_due_days', $int, '30'), 'an integer parses');
throws(fn() => setting_validate('billing_due_days', $int, 'x'), 'rejects non-numeric');
throws(fn() => setting_validate('billing_due_days', $int, '-1'), 'rejects below the minimum');
throws(fn() => setting_validate('billing_due_days', $int, '999'), 'rejects above the maximum');
$text = setting_schema()['club_name'];
is_same('Verein', setting_validate('club_name', $text, '  Verein  '), 'text is trimmed');
throws(fn() => setting_validate('club_name', $text, ''), 'a required field rejects empty');
throws(fn() => setting_validate('club_name', $text, str_repeat('x', 200)), 'rejects over the length limit');
$list = setting_schema()['payment_methods'];
is_same(['Bar','Karte'], setting_validate('payment_methods', $list, "Bar\nKarte\n\nBar"), 'a list de-duplicates and drops blanks');
throws(fn() => setting_validate('payment_methods', $list, "\n \n"), 'a list may not be empty');

case_('Seeded defaults leave a usable installation');
test_reset();
ok(setting('club_name') !== '', 'the portal has a name');
ok(count((array)setting('statuses')) > 0, 'membership statuses exist');
ok(count((array)setting('attendance_statuses')) > 0, 'attendance statuses exist');
ok((int)scalar('SELECT COUNT(*) FROM rating_scales') > 0, 'at least one rating scale');
ok((int)scalar('SELECT COUNT(*) FROM skill_areas') > 0, 'at least one skill area');
ok((int)scalar('SELECT COUNT(*) FROM payment_profiles') > 0, 'a payment profile to fill in');
is_same((int)scalar('SELECT id FROM payment_profiles LIMIT 1'), (int)setting('default_payment_profile'),
        'and it is the configured default');

case_('The privacy notice says what is still in the way, rather than just "no"');
$long = str_repeat('Diese Erklärung beschreibt die Verarbeitung. ', 20);
sign_in_as(make_account(['role'=>'admin']));
$save = fn(string $de, string $en, bool $ready) =>
    act('privacy_save', ['privacy_de'=>$de, 'privacy_en'=>$en] + ($ready ? ['privacy_ready'=>'1'] : []));
throws(fn() => $save('kurz', $long, true), 'a version that is too short is named', 'Deutsch');
throws(fn() => $save($long, 'short', true), 'and so is the other one', 'English');
throws(fn() => $save($long.'[Name des Betreibers]', $long, true), 'a leftover placeholder is quoted back', '[Name des Betreibers]');
throws(fn() => $save($long, $long.'[operator name]', true), 'in either version', '[operator name]');
does_not_throw(fn() => $save($long, $long, true), 'two complete versions are accepted');
is_same(true, (bool)setting('privacy_ready'), 'and the notice is released');
// Saving a draft must work even while it is incomplete, or there is nowhere to
// keep half-finished text and the page looks as though it ignored the edit.
does_not_throw(fn() => $save('Entwurf', 'Draft', false), 'an incomplete draft still saves');
is_same('Entwurf', setting('privacy_de'), 'and the text is actually stored');
is_same(false, (bool)setting('privacy_ready'), 'with the release tick cleared');
