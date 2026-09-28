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
/* Creating a child asks for the basics only now, so this is the form that is
   actually in front of somebody: a name, a date, and the address the portal
   writes to - which is the one that gets this refused. */
$reject('student_save', ['return_page'=>'student','return_id'=>'0','return_tab'=>'',
    'first_name'=>'Lena','last_name'=>'Hofer','birth_date'=>'2015-04-02','status'=>'active',
    'joined_on'=>'2026-01-15',
    'email'=>'maria.hofer@',                  // half an address is what gets it refused
]);
$html = render_view('student', ['id'=>0]);
ok(str_contains($html, 'value="Lena"'), 'the first name is still there');
ok(str_contains($html, 'value="Hofer"'), 'and the surname');
ok(str_contains($html, 'value="2015-04-02"'), 'and the date of birth');
ok(str_contains($html, 'value="2026-01-15"'), 'and the day they joined');
ok(str_contains($html, 'maria.hofer@'), 'and the value that was refused, so it can be corrected rather than guessed at');

case_('And on the full form, which has the boxes the short one leaves out');
$existing = make_student(['first_name'=>'Tobias', 'last_name'=>'Hofer']);
// The refused value used to be a price with a euro sign. The price box is gone
// from this form (ADR 0011), so it is a date typed the way it is said instead -
// „Dabei seit“ stays on the form, wherever on it it ends up.
$reject('student_save', ['return_page'=>'student','return_id'=>(string)$existing,'return_tab'=>'',
    'id'=>(string)$existing, 'revision'=>'1',
    'first_name'=>'Tobias','last_name'=>'Hofer','birth_date'=>'','status'=>'active',
    'joined_on'=>'1.9.2025',                  // not a date the form sends, which is what gets it refused
    'phone'=>'+43 660 7654321','internal_notes'=>'Trainiert seit Herbst']);
$html = render_view('student', ['id'=>$existing]);
ok(str_contains($html, '1.9.2025'), 'the value that was refused');
ok(str_contains($html, '+43 660 7654321'), 'and the one typed beside it');
ok(str_contains($html, 'Trainiert seit Herbst'), 'and the long text nobody wants to type twice');

case_('A new child is asked the few things that cannot wait');
$blank = render_view('student', ['id'=>0]);
foreach (['first_name', 'last_name', 'birth_date', 'email', 'status', 'joined_on'] as $asked)
    ok(str_contains($blank, 'name="'.$asked.'"'), 'the create form asks for '.$asked);
foreach (['price_note', 'internal_notes', 'level_id', 'age_group_id'] as $later)
    ok(!str_contains($blank, 'name="'.$later.'"'), $later.' waits until the record exists');
// Her own fields wait too. They are the ones most likely to be many, and a
// create form that grows every time she adds one is a create form that is back
// where it started.
$own = fixture('field_definitions', ['label'=>'Bisheriger Verein', 'label_en'=>'', 'field_type'=>'text',
    'section_name'=>'', 'options_json'=>'[]', 'default_json'=>'null', 'required'=>0,
    'visibility'=>'view', 'sort_order'=>9, 'archived'=>0]);
$blank = render_view('student', ['id'=>0]);
ok(!str_contains($blank, 'custom['.$own.']'), 'and so does a custom field she added herself');
ok(str_contains($blank, 'Anlegen und weiter'), 'and the button says there is more to come');
$existingHtml = render_view('student', ['id'=>$existing]);
ok(str_contains($existingHtml, 'custom['.$own.']'), 'while the record’s own page has it');

case_('It is offered back once, and then forgotten');
// The next request takes it out of the session again and finds nothing, which is
// what stops a rejected edit reappearing on a page she opens tomorrow.
take_held_input();
is_same(null, $GLOBALS['crm_held_input'], 'the session no longer holds it');
$html = render_view('student', ['id'=>0]);
ok(!str_contains($html, 'value="Lena"'), 'so the same page is empty again');

case_('It is never offered to a different form, page or record');
$reject('student_save', ['return_page'=>'student','return_id'=>'0','return_tab'=>'',
    'first_name'=>'Lena','last_name'=>'Hofer','status'=>'active','joined_on'=>'1.9.2025']);
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

/* One list, FORM_BOOKKEEPING_FIELDS, is what remember_input() and record_step()
   both leave out. It is only right while it is exactly what a form adds on its
   own, so read that off a rendered form: a field start_form() gains tomorrow
   fails here until the list names it too. */
$GLOBALS['page'] = 'student'; $_GET = ['id'=>7, 'tab'=>'contacts'];
ob_start(); start_form('student_save', ['id'=>7]); echo '</form>'; $rendered = (string)ob_get_clean();
preg_match_all('/<input type="hidden" name="([^"]+)"/', $rendered, $hidden);
$added = array_values(array_diff($hidden[1], ['id', 'csrf']));
sort($added); $declared = FORM_BOOKKEEPING_FIELDS; sort($declared);
is_same($declared, $added, 'the bookkeeping list is exactly what every form adds besides its token');
$_GET = [];

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
// was, so the only way to find out was to save and look. The child's own
// „Tarif und Beitrag" box is gone (ADR 0011); the agreed price lives on the
// enrolment now, on the child's Kurse tab, under „Mehr Möglichkeiten".
$course = make_class(['name'=>'Kindertraining']);
run('UPDATE tariffs SET class_id=? WHERE id=?', [$course, $tariff]);
$student = make_student(['first_name'=>'Mia', 'last_name'=>'Standard']);
make_enrolment($course, $student, ['tariff_id'=>$tariff]);
/** The enrolment's „Mehr Möglichkeiten", as the child's Kurse tab draws it. */
$moreOptions = function (int $student): string {
    $html = render_view('student', ['id'=>$student, 'tab'=>'classes']);
    return preg_match('~<details class="more-options"[^>]*>.*?</details>~s', $html, $m) ? $m[0] : '';
};
$more = $moreOptions($student);
ok(str_contains($more, 'name="price"'), 'the agreed price is one of the „Mehr Möglichkeiten"');
ok(str_contains($more, '45,00'), 'the tariff price is printed next to the field');
ok(str_contains($more, 'is-default'), 'and the field is marked as following the default');
ok(preg_match('~^<details class="more-options"\s*>~', $more) === 1, 'and with nothing of her own set, the section stays shut');

case_('A price of her own is marked as hers, with the default still visible');
run('UPDATE class_students SET price_cents=4000 WHERE class_id=? AND student_id=?', [$course, $student]);
$more = $moreOptions($student);
// With a comma, because the form is in German and the list beside it says
// 45,00 €. A point in the box is where a reader looks for a thousands separator.
ok(str_contains($more, 'value="40,00"'), 'her own price is in the box, written the way she types it');
ok(!str_contains($more, 'value="40.00"'), 'and not with the decimal point of the machine');
ok(str_contains($more, '45,00'), 'the tariff price is still named, so the difference is visible');
ok(!str_contains($more, 'with-default is-default'), 'and the field is no longer marked as following the default');
ok(preg_match('~^<details class="more-options"\s+open\s*>~', $more) === 1, 'and the section opens by itself, so nothing she set is hidden');

// ---------------------------------------------------------------------------
case_('A time of day is 24 hours on every device, because the page decides it');
/* <input type="time"> renders in the language of the phone or the computer, not
   of the page: a device set to English shows "05:30 PM" however the portal is
   set. So a time is an hour box and a minute box, posted separately. */
is_same(['17','30'], time_parts('17:30:00'), 'a stored time splits into two boxes');
is_same(['17','30'], time_parts('17:30'), 'with or without the seconds');
is_same(['',''], time_parts(''), 'and nothing at all is two empty boxes');
is_same(['',''], time_parts('5:30 PM'), 'a twelve-hour time is not a time this portal wrote');
is_same(['00','00'], time_parts('00:00:00'), 'midnight is a time, not an absence');

post_data(time_post('starts_at', '17:30'));
is_same('17:30:00', posted_time('starts_at'), 'the two boxes come back as one time');
post_data(time_post('starts_at', ''));
is_same(null, posted_time('starts_at'), 'both left alone means no time was given');
// Half a time is somebody who stopped in the middle, not somebody who meant
// "on the hour" - and stored as 17:00 it would be a training session that
// silently starts at the wrong time.
post_data(['starts_at_h'=>'17', 'starts_at_m'=>'']);
throws(fn() => posted_time('starts_at'), 'an hour with no minute is refused', 'Stunde und Minute');
post_data(['starts_at_h'=>'', 'starts_at_m'=>'30']);
throws(fn() => posted_time('starts_at'), 'and a minute with no hour', 'Stunde und Minute');
post_data(['starts_at_h'=>'25', 'starts_at_m'=>'00']);
throws(fn() => posted_time('starts_at'), 'a 25th hour is refused', 'HH:MM');

case_('The minute box offers every five minutes, and never loses a stored time');
$every5 = minute_options();
is_same(12, count($every5), 'twelve choices, not sixty');
ok(isset($every5['00']) && isset($every5['55']), 'from on the hour to five to');
ok(!isset($every5['37']), 'and nothing in between');
// A time already in the database that is not on the grid has to stay in the
// list, or saving an unrelated change on that form would quietly move it.
$withOdd = minute_options('37');
ok(isset($withOdd['37']), 'a stored 17:37 keeps its minute');
// Compared as values: PHP turns "10" into the integer key 10 but leaves "00"
// and "05" alone, so the keys of this map are deliberately not all one type.
// Everything that reads it casts to string, which is what select_options does.
is_same(['00','05','10','15','20','25','30','35','37','40','45','50','55'], array_values($withOdd), 'in its place in the order');
ok(str_contains(select_options($withOdd,'37'),'value="37" selected'), 'and it is the one shown as chosen');
is_same($every5, minute_options('30'), 'and a time already on the grid adds nothing');

// ---------------------------------------------------------------------------
case_('Saving a child writes no tariff and no price, whatever is posted');
/* What a child pays is decided per course, on the enrolment, and that is what
   billing reads (ADR 0011). The box on the child's own page is gone, and a
   form without it would have blanked the three columns on every save - so the
   save neither reads nor writes them, and a page from before that still posts
   the box changes nothing. */
sign_in_as(make_account(['role'=>'admin']));
$course = make_class(['name'=>'Kindertraining']);
$priced = make_tariff(['class_id'=>$course, 'name'=>'Beitrag', 'interval_months'=>3,
                       'rates'=>[1=>3700, 3=>9900]]);
act('student_save', ['first_name'=>'Preis', 'last_name'=>'Folger', 'birth_date'=>'', 'joined_on'=>today(),
                     'ended_on'=>'', 'status'=>'active', 'tariff_id'=>(string)$priced, 'price'=>'12,00',
                     'price_cents'=>'1200', 'price_note'=>'Sonderpreis', 'internal_notes'=>'', 'revision'=>'1']);
$saved = one("SELECT * FROM students WHERE first_name='Preis'");
is_same(null, $saved['tariff_id'], 'a new child gets no tariff from the posted one');
is_same(null, $saved['price_cents'], 'nor a price from the posted one');
is_same('', (string)$saved['price_note'], 'nor the note beside it');
$kept = make_student(['first_name'=>'Alt', 'last_name'=>'Preis', 'tariff_id'=>$priced, 'price_cents'=>4200,
                      'price_note'=>'Vereinbart 2024', 'joined_on'=>'2024-09-01']);
act('student_save', ['id'=>(string)$kept, 'revision'=>'1', 'first_name'=>'Alt', 'last_name'=>'Preis',
                     'birth_date'=>'', 'joined_on'=>'2024-10-01', 'ended_on'=>'2027-06-30', 'status'=>'active',
                     'tariff_id'=>'', 'price'=>'', 'price_cents'=>'', 'price_note'=>'', 'internal_notes'=>'']);
$after = one('SELECT * FROM students WHERE id=?', [$kept]);
is_same($priced, (int)$after['tariff_id'], 'a blank tariff posted by a form without the box leaves the stored one');
is_same(4200, (int)$after['price_cents'], 'and the stored price');
is_same('Vereinbart 2024', (string)$after['price_note'], 'and the stored note');
act('student_save', ['id'=>(string)$kept, 'revision'=>'2', 'first_name'=>'Alt', 'last_name'=>'Preis',
                     'birth_date'=>'', 'joined_on'=>'2024-10-01', 'ended_on'=>'2027-06-30', 'status'=>'active',
                     'tariff_id'=>'999', 'price'=>'1,00', 'price_cents'=>'100', 'price_note'=>'Neu', 'internal_notes'=>'']);
$after = one('SELECT * FROM students WHERE id=?', [$kept]);
is_same([$priced, 4200, 'Vereinbart 2024'], [(int)$after['tariff_id'], (int)$after['price_cents'], (string)$after['price_note']],
        'and a page from before that posts other values changes none of the three');
is_same(['2024-10-01', '2027-06-30'], [$after['joined_on'], $after['ended_on']],
        'while „Dabei seit“ and „Mitgliedschaft bis“ are still saved');
is_same(9900, tariff_price($priced), 'what a tariff costs is still read from its rate at the usual interval');
is_same(null, tariff_price(null), 'and no tariff is no price');
is_same(null, tariff_price(999999), 'as is a tariff that is not there');

// ---------------------------------------------------------------------------
case_('And then the page says what is left, in the order she would do it');
/* The short form is only kind if the things it left out are asked for
   somewhere. A child with no course is a child nobody bills, and four days
   later nobody remembers which of fifteen children that was. */
$fresh = make_student(['first_name'=>'Frisch', 'last_name'=>'Angelegt', 'account_id'=>null]);
run("UPDATE students SET email='' WHERE id=?", [$fresh]);
$steps = fn() => array_column(student_next_steps($fresh), 'what');
ok(in_array(t('Notfallkontakt eintragen','Add an emergency contact'), $steps(), true), 'somebody to ring');
ok(in_array(t('E-Mail-Adresse eintragen','Add an email address'), $steps(), true), 'somewhere to write');
ok(in_array(t('In einen Kurs eintragen','Put them in a course'), $steps(), true), 'and a course');
ok(str_contains(render_view('student', ['id'=>$fresh]), 'Noch zu tun'), 'and the page says so');

fixture('contacts', ['student_id'=>$fresh, 'owner_name'=>'Oma', 'relation_label'=>'Großmutter',
                     'phone'=>'+43 660 1', 'email'=>'', 'is_primary'=>1]);
ok(!in_array(t('Notfallkontakt eintragen','Add an emergency contact'), $steps(), true), 'a contact ticks the first one off');
run("UPDATE students SET email='eltern@beispiel.test' WHERE id=?", [$fresh]);
// With an address but no account, the next thing to do is to hand it out.
ok(in_array(t('Zugang einladen','Invite them in'), $steps(), true), 'and the address turns into an invitation to send');

$courseForFresh = make_class(['name'=>'Kurs für Frisch']);
$tariffForFresh = make_tariff(['class_id'=>$courseForFresh, 'name'=>'Beitrag']);
make_enrolment($courseForFresh, $fresh, ['tariff_id'=>null]);
ok(in_array(t('Tarif wählen','Choose a tariff'), $steps(), true), 'a course with no tariff is the next thing');
run('UPDATE class_students SET tariff_id=? WHERE student_id=?', [$tariffForFresh, $fresh]);
ok(!in_array(t('Tarif wählen','Choose a tariff'), $steps(), true), 'and choosing one clears it');

// Somebody who has left is not chased through a checklist they will never need.
run('UPDATE class_students SET left_on=? WHERE student_id=?', [today(), $fresh]);
ok(in_array(t('In einen Kurs eintragen','Put them in a course'), $steps(), true),
   'and a child who has left every course is asked for one again');
