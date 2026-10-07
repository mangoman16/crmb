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
/* Making a child is the wizard now (ADR 0023 §5), so this is the form that is
   actually in front of somebody: step 1, a name, a date and a course - and a
   date of birth that does not exist is what gets it refused. */
$reject('student_draft', ['return_page'=>'student_new','return_id'=>'0','return_tab'=>'',
    'first_name'=>'Lena','last_name'=>'Hofer','birth_date'=>'2015-02-30','status'=>'active','course'=>'none']);
$html = render_view('student_new');
ok(str_contains($html, 'value="Lena"'), 'the first name is still there');
ok(str_contains($html, 'value="Hofer"'), 'and the surname');
ok(str_contains($html, '2015-02-30'), 'and the value that was refused, so it can be corrected rather than guessed at');
ok(preg_match('~<option value="none"[^>]*selected~', $html) === 1, 'and the course chosen');

case_('And on step 2, which comes back to step 2 with its draft whole');
$draftKey = (string)act('student_draft', ['first_name'=>'Lena','last_name'=>'Hofer','birth_date'=>'2015-04-02','status'=>'active','course'=>'none'])[1]['draft'];
mail_ready(true);
$_POST = ['return_draft'=>$draftKey, 'return_page'=>'student_new'];
is_same(['student_new', ['draft'=>$draftKey]], form_return(), 'a refusal returns to the page with the draft’s key, so a reload keeps step 2');
$reject('student_create', ['return_page'=>'student_new','return_id'=>'0','return_tab'=>'','return_draft'=>$draftKey,
    'draft'=>$draftKey, 'method'=>'email', 'email'=>'maria.hofer@', 'locale'=>'de']);
$html = render_view('student_new', ['draft'=>$draftKey]);
ok(str_contains($html, e('Schritt 2 von 2')) && str_contains($html, 'Lena Hofer'), 'step 2, with the draft’s details');
ok(str_contains($html, 'maria.hofer@'), 'and the address that was refused, back in its box');
mail_ready(false);

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
$blank = render_view('student_new');
foreach (['first_name', 'last_name', 'birth_date', 'course', 'status'] as $asked)
    ok(str_contains($blank, 'name="'.$asked.'"'), 'step 1 asks for '.$asked);
foreach (['email', 'price_note', 'internal_notes', 'level_id', 'age_group_id', 'joined_on'] as $later)
    ok(!str_contains($blank, 'name="'.$later.'"'), $later.' waits: the sign-in on step 2, the rest until the record exists');
// Her own fields wait too. They are the ones most likely to be many, and a
// create form that grows every time she adds one is a create form that is back
// where it started.
$own = fixture('field_definitions', ['label'=>'Bisheriger Verein', 'label_en'=>'', 'field_type'=>'text',
    'section_name'=>'', 'options_json'=>'[]', 'default_json'=>'null', 'required'=>0,
    'visibility'=>'view', 'sort_order'=>9, 'archived'=>0]);
$blank = render_view('student_new');
ok(!str_contains($blank, 'custom['.$own.']'), 'and so does a custom field she added herself');
ok(str_contains($blank, e('Weiter')), 'and the button says there is more to come');
$existingHtml = render_view('student', ['id'=>$existing]);
ok(str_contains($existingHtml, 'custom['.$own.']'), 'while the record’s own page has it');

case_('It is offered back once, and then forgotten');
// The next request takes it out of the session again and finds nothing, which is
// what stops a rejected edit reappearing on a page she opens tomorrow.
$reject('student_draft', ['return_page'=>'student_new','return_id'=>'0','return_tab'=>'',
    'first_name'=>'Lena','last_name'=>'Hofer','birth_date'=>'2015-02-30','status'=>'active','course'=>'none']);
take_held_input();
is_same(null, $GLOBALS['crm_held_input'], 'the session no longer holds it');
$html = render_view('student_new');
ok(!str_contains($html, 'value="Lena"'), 'so the same page is empty again');

case_('It is never offered to a different form, page or record');
$reject('student_draft', ['return_page'=>'student_new','return_id'=>'0','return_tab'=>'',
    'first_name'=>'Lena','last_name'=>'Hofer','status'=>'active','course'=>'']);
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
// With a wizard draft's key in the address too, which a form carries back after
// a refusal (ADR 0023 §5); without one, return_draft is simply not written.
$GLOBALS['page'] = 'student'; $_GET = ['id'=>7, 'tab'=>'contacts', 'draft'=>str_repeat('a', 32)];
ob_start(); start_form('student_save', ['id'=>7]); echo '</form>'; $rendered = (string)ob_get_clean();
preg_match_all('/<input type="hidden" name="([^"]+)"/', $rendered, $hidden);
$added = array_values(array_diff($hidden[1], ['id', 'csrf']));
sort($added); $declared = FORM_BOOKKEEPING_FIELDS; sort($declared);
is_same($declared, $added, 'the bookkeeping list is exactly what every form adds besides its token');
$_GET = [];

case_('An address with ?tab[]= draws its page without a warning');
/* A list where a word is expected is something anybody can type into an
   address. Turned into text it warned „Array to string conversion" - on a
   child's page from views/student.php, and on every form of it from
   start_form() - and was guarded against one place at a time. public/index.php
   drops every list from the address before anything reads it now, and the
   suite draws pages from what it leaves (render_as_front_controller()); the
   structure suite holds the router to it. */
$listed = make_student(['first_name'=>'Liste', 'last_name'=>'Imadresse']);
$drawn = null;
does_not_throw(function () use ($listed, &$drawn) {
    $drawn = render_page('student', ['id'=>(string)$listed, 'tab'=>['contacts'], 'draft'=>['x'], 'lang'=>['en']]);
}, 'a child’s page with ?tab[]=, ?draft[]= and ?lang[]= in its address is drawn without a warning');
ok(str_contains((string)$drawn, e('Persönliche Daten')) && str_contains((string)$drawn, 'name="return_tab" value=""'),
   'as the page with no tab: the details, whose forms carry no tab back');

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

case_('A view can ask what was held for one form by name, and gets the same answer the fields get');
/* ADR 0019, I4: a page reacts to a refusal before it opens the form - a
   refused invitation's card left open, a refused contact's details opened. held_for() is
   where the form, page, record and tab are matched, and holding_input() is
   built on it, so the two cannot disagree about what was refused where. */
$_POST = ['return_page'=>'student','return_id'=>'5','return_tab'=>'','invite'=>'','email'=>'eltern@beispiel.test'];
remember_input('student_invite');
take_held_input();
$GLOBALS['page'] = 'student'; $_GET = ['id'=>5];
is_same(['invite'=>'', 'email'=>'eltern@beispiel.test'], held_for('student_invite'), 'the refused form, asked for by name, on its page and record');
is_same([], held_for('student_save'), 'nothing for another form on the same page');
$_GET = ['id'=>6];
is_same([], held_for('student_invite'), 'nor for the same form on another record');
$_GET = ['id'=>5, 'tab'=>'contacts'];
is_same([], held_for('student_invite'), 'nor on another tab');
$_GET = ['id'=>5];
foreach (['student_invite', 'student_save', ''] as $context) {
    form_context($context);
    is_same(held_for($context) !== [], holding_input(), 'holding_input() agrees with held_for() for '.json_encode($context));
}
unset($GLOBALS['page'], $GLOBALS['crm_held_input']); $_GET = []; form_context('');

case_('A refused edit of one contact comes back in that contact’s form, and in no other');
/* ADR 0020, §10a, reproduced by the designer: a refused contact_save was held
   for the action, the page and the tab - never for the record - so every
   contact's edit form on the page showed what was typed for one of them, and
   saving the wrong one overwrote a person. held_for() now also matches the
   record a form edits; a form with no id of its own still matches as before. */
$child = make_student(['first_name'=>'Mia', 'last_name'=>'Kontakt']);
$oma = fixture('contacts', ['student_id'=>$child, 'owner_name'=>'Oma Erika', 'relation_label'=>'Großmutter',
                            'phone'=>'+43 660 1000001', 'email'=>'', 'is_primary'=>1]);
$papa = fixture('contacts', ['student_id'=>$child, 'owner_name'=>'Papa Jonas', 'relation_label'=>'Vater',
                             'phone'=>'+43 660 1000002', 'email'=>'', 'is_primary'=>0]);
$reject('contact_save', ['return_page'=>'student', 'return_id'=>(string)$child, 'return_tab'=>'contacts',
    'student_id'=>(string)$child, 'id'=>(string)$papa, 'owner_name'=>'Papa Getippt', 'relation_label'=>'Vater',
    'phone'=>'+43 660 1000002', 'email'=>'kein-at-zeichen']);
$page = render_view('student', ['id'=>$child, 'tab'=>'contacts']);
$formOf = function (string $html, int $contact): string {
    // The edit form, not the delete form beside it, which carries the id too.
    return preg_match('~<form[^>]*><input type="hidden" name="action" value="contact_save">(?:(?!</form>).)*name="id" value="'.$contact.'"(?:(?!</form>).)*</form>~s', $html, $m) ? $m[0] : '';
};
ok($formOf($page, $papa) !== '' && $formOf($page, $oma) !== '', 'both contacts have their edit form on the page');
ok(str_contains($formOf($page, $papa), 'value="Papa Getippt"'), 'the refused contact’s form has what was typed');
ok(str_contains($formOf($page, $oma), 'value="Oma Erika"'), 'the other contact’s form shows her own stored name');
ok(!str_contains($formOf($page, $oma), 'Getippt') && !str_contains($formOf($page, $oma), 'kein-at-zeichen'),
   'and nothing typed for somebody else');
unset($GLOBALS['crm_held_input']);
$reject('contact_add', ['return_page'=>'student', 'return_id'=>(string)$child, 'return_tab'=>'contacts',
    'student_id'=>(string)$child, 'owner_name'=>'Neue Tante', 'relation_label'=>'Tante', 'phone'=>'', 'email'=>'kein-at-zeichen']);
$page = render_view('student', ['id'=>$child, 'tab'=>'contacts']);
ok(str_contains($page, 'value="Neue Tante"'), 'a refused new contact, which has no id of its own, is still held in the add form');
ok(!str_contains($formOf($page, $oma), 'Neue Tante') && !str_contains($formOf($page, $papa), 'Neue Tante'), 'and in no edit form');
unset($GLOBALS['crm_held_input']);
is_same(['owner_name'=>'x'], (function () {
    $GLOBALS['crm_held_input'] = ['action'=>'contact_save', 'page'=>'student', 'id'=>'0', 'tab'=>'', 'fields'=>['owner_name'=>'x']];
    $GLOBALS['page'] = 'student'; $_GET = [];
    try { return held_for('contact_save', 4); } finally { unset($GLOBALS['crm_held_input'], $GLOBALS['page']); }
})(), 'a submission held before records were stored has none, and still matches');

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
create_through_wizard(['first_name'=>'Preis', 'last_name'=>'Folger', 'tariff_id'=>(string)$priced, 'price'=>'12,00',
                       'price_cents'=>'1200', 'price_note'=>'Sonderpreis']);
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

case_('A form sent from any page goes back to that page, record and tab included');
/* The notification pane is on every page, and „Alle gelesen“ used to return
   the page name alone: pressed on a child's page it went to "page=student"
   with no child, which is "Nicht gefunden". form_return() is the one reading of
   the return_* fields, for it, the account menu and the router's way back. */
sign_in_as($admin);
is_same(['student', ['id' => 7, 'tab' => 'payments']],
    act('notifications_read', ['return_page' => 'student', 'return_id' => '7', 'return_tab' => 'payments']),
    'marking all read keeps the child and the tab');
is_same(['students', []], act('notifications_read', ['return_page' => 'students', 'return_id' => '0', 'return_tab' => '']),
    'a page with no record gets no id=0 either');
$_POST = ['return_page' => '//evil.example/x'];
is_same(['dashboard', []], form_return(), 'something that is not a page name is never where it goes');
$_POST = [];
is_same(['login', []], form_return('login'), 'and with nothing posted the fallback is used');
/* After a refusal the router passes its own list of pages, so the page she
   lands on with the error message is always one that exists. */
$_POST = ['return_page' => 'no_such_page', 'return_id' => '4'];
is_same(['dashboard', ['id' => 4]], form_return('dashboard', ['dashboard', 'student']), 'a page the router does not know is replaced by the fallback');
$_POST = ['return_page' => 'student', 'return_id' => '4'];
is_same(['student', ['id' => 4]], form_return('dashboard', ['dashboard', 'student']), 'one it knows is kept');
$_POST = [];
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
ok(str_contains($router, "form_return(current_user()?'dashboard':'login',\$allowed)"), 'and the router hands its list of pages to the way back');

case_('A box takes extra attributes, each escaped, and can drop the ones it sets itself');
/* One helper draws every box (what the sign-in address box and a
   current-password box need included), so an attribute is either printed
   through e() or refused by name - there is no third way for a value to reach
   the page. */
test_load_actions();
form_context('');
$draw = function (string $type, array $attributes): string { ob_start(); input('x', 'X', '', $type, true, '', '', $attributes); return (string)ob_get_clean(); };
$box = $draw('text', ['data-note'=>'"><script>alert(1)</script>']);
ok(str_contains($box, 'data-note="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"'), 'a value is escaped');
ok(!str_contains($box, '<script>'), 'and nothing of it reaches the page as markup');
$password = $draw('password', []);
ok(str_contains($password, 'autocomplete="new-password"') && str_contains($password, 'minlength="12"'), 'a password box asks for a new password by default');
$current = $draw('password', current_password_attributes());
ok(str_contains($current, 'autocomplete="current-password"') && !str_contains($current, 'new-password'), 'a passed value replaces the default');
ok(!str_contains($current, 'minlength'), 'and null drops it');
ok(str_contains($current, 'maxlength="72"') && str_contains($current, ' required'), 'while the rest stays');
/* The address box (ADR 0021, §1): what the password manager saves the password
   under, unless the caller says off first - the invitation and the delete
   confirmation, where the browser must not fill in her own address. */
$address = $draw('email', sign_in_address_attributes());
foreach (['autocomplete="username"', 'autocapitalize="none"', 'autocorrect="off"', 'spellcheck="false"'] as $attribute)
    ok(str_contains($address, $attribute), 'the sign-in address box carries '.$attribute);
ok(!str_contains($address, 'inputmode'), 'and no inputmode, which type="email" already brings');
$off = $draw('email', ['autocomplete'=>'off'] + sign_in_address_attributes());
ok(str_contains($off, 'autocomplete="off"') && !str_contains($off, 'autocomplete="username"'), 'an off put first wins over the helper’s username');
ok(str_contains($off, 'autocapitalize="none"'), 'and the rest of the helper still applies');
ok(!function_exists('username_attributes'), 'username_attributes() is gone, so no box asks for a username');
foreach (['onclick x', 'Onfocus', 'value', 'name', 'type', 'required', 7] as $bad) {
    ob_start();
    try { input('x', 'X', '', 'text', false, '', '', [$bad => 'y']); $refused = false; }
    catch (LogicException) { $refused = true; }
    $printed = (string)ob_get_clean();
    ok($refused && $printed === '', 'a name that is not a plain attribute, or one input() owns, is refused before anything is printed: '.json_encode($bad));
}
