<?php
/** Forms: a rejected submission comes back filled in, and defaults are visible. */
$admin = make_account(['role'=>'admin']); sign_in_as($admin);
$tariff = make_tariff(['name'=>'Monatsbeitrag Kinder','price_cents'=>4500]);

/** Run an action the way a POST does, failing on purpose, and keep what was typed. */
$reject = function (string $action, array $fields) {
    try { act($action, $fields); ok(false, 'expected '.$action.' to be refused'); }
    catch (UserError) { remember_input($action); }
    take_held_input();
};

case_('What was typed survives a rejected save');
$reject('student_save', ['return_page'=>'student','return_id'=>'0','return_tab'=>'',
    'first_name'=>'Lena','last_name'=>'Hofer','birth_date'=>'2015-04-02','status'=>'active',
    'price'=>'45,50 €',                       // the euro sign is what gets it refused
    'price_note'=>'Geschwisterermäßigung','internal_notes'=>'Trainiert seit Herbst']);
$html = render_view('student', ['id'=>0]);
ok(str_contains($html, 'value="Lena"'), 'the first name is still there');
ok(str_contains($html, 'value="Hofer"'), 'and the surname');
ok(str_contains($html, 'value="2015-04-02"'), 'and the date of birth');
ok(str_contains($html, '45,50 €'), 'and the value that was refused, so it can be corrected rather than guessed at');
ok(str_contains($html, 'Geschwisterermäßigung'), 'and the note beside it');
ok(str_contains($html, 'Trainiert seit Herbst'), 'and the long text nobody wants to type twice');

case_('It is offered back once, and then forgotten');
// The next request takes it out of the session again and finds nothing, which is
// what stops a rejected edit reappearing on a page she opens tomorrow.
take_held_input();
is_same(null, $GLOBALS['crm_held_input'], 'the session no longer holds it');
$html = render_view('student', ['id'=>0]);
ok(!str_contains($html, 'value="Lena"'), 'so the same page is empty again');

case_('It is never offered to a different form, page or record');
$reject('student_save', ['return_page'=>'student','return_id'=>'0','return_tab'=>'',
    'first_name'=>'Lena','last_name'=>'Hofer','status'=>'active','price'=>'45,50 €']);
$other = make_student(['first_name'=>'Jonas','last_name'=>'Berger']);
$html = render_view('student', ['id'=>$other]);
ok(!str_contains($html, 'value="Lena"'), 'another student keeps their own name');
ok(str_contains($html, 'value="Jonas"'), 'which is still shown');

case_('A password is never held');
$reject('password_change', ['return_page'=>'profile','return_id'=>'0','return_tab'=>'',
    'current_password'=>'wrong-one-entirely','password'=>'geheimes-neues-passwort','password_confirm'=>'x']);
$held = $GLOBALS['crm_held_input'] ?? ['fields'=>[]];
foreach (array_keys($held['fields']) as $key)
    ok(!str_contains($key, 'password'), 'not held: '.$key);
ok(!in_array('geheimes-neues-passwort', array_values($held['fields']), true), 'and no password value got through');

case_('Neither is the token or the duplicate-submission guard');
$_POST = ['csrf'=>'abc','request_id'=>'def','action'=>'x','return_page'=>'students','return_id'=>'0','return_tab'=>'','q'=>'Hofer'];
remember_input('student_save');
is_same(['q'=>'Hofer'], $_SESSION['form_input']['fields'], 'only the field she filled in is kept');

case_('An unticked box stays unticked when the form comes back');
$_POST = ['return_page'=>'student','return_id'=>'0','return_tab'=>'','first_name'=>'Lena'];
remember_input('student_save');
take_held_input();
form_context('student_save');
$GLOBALS['page'] = 'student'; $_GET = ['id'=>0];
ob_start(); check_field('billing_paused', 'Pausiert', true); $box = (string)ob_get_clean();
ok(!str_contains($box, 'checked'), 'a box she cleared is not re-ticked by the record it came from');
ob_start(); input('first_name', 'Vorname', 'aus der Datenbank'); $field = (string)ob_get_clean();
ok(str_contains($field, 'value="Lena"'), 'while a field she filled in keeps her value');
unset($GLOBALS['page'], $GLOBALS['crm_held_input']); $_GET = []; form_context('');

case_('A default is shown rather than only referred to');
// "Leave blank to use the tariff's default price" never said what that price
// was, so the only way to find out was to save and look.
$student = make_student(['tariff_id'=>$tariff, 'price_cents'=>null]);
$html = render_view('student', ['id'=>$student]);
ok(str_contains($html, '45,00'), 'the tariff price is printed next to the field');
ok(str_contains($html, 'is-default'), 'and the field is marked as following the default');

case_('A price of her own is marked as hers, with the default still visible');
run('UPDATE students SET price_cents=4000 WHERE id=?', [$student]);
$html = render_view('student', ['id'=>$student]);
ok(str_contains($html, 'value="40.00"'), 'her own price is in the box');
ok(str_contains($html, '45,00'), 'the tariff price is still named, so the difference is visible');
ok(!str_contains($html, 'with-default is-default'), 'and the field is no longer marked as following the default');
