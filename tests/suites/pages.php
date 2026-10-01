<?php
/**
 * Every page, for everybody who may open it, with real data behind it.
 *
 * The other suites check what a function returns. This one opens the pages the
 * way a browser does and insists that nothing goes wrong on the way: since
 * render_view() turns a PHP warning into a failure, an undefined index or a
 * division by zero in a rarely-opened tab fails here instead of printing itself
 * quietly between two table cells on the trainer's phone.
 *
 * A page is checked with data present, not on an empty portal, because "works
 * until somebody uses it" is the failure this is for.
 */

// ---------------------------------------------------------------------------
// One small portal with something in every table the pages read.
// ---------------------------------------------------------------------------
$admin   = make_account(['role'=>'admin',   'name'=>'Admin Person']);
$trainer = make_account(['role'=>'trainer', 'name'=>'Trainerin Beispiel']);
$family  = make_account(['role'=>'student', 'name'=>'Familie Hofer']);
$other   = make_account(['role'=>'student', 'name'=>'Familie Berger']);

sign_in_as($trainer);
$course = make_class(['name'=>'Kindertraining', 'location'=>'Halle Nord', 'capacity'=>10,
    'days'=>[['weekday'=>1,'starts_at'=>'16:00:00','ends_at'=>'17:30:00','location'=>'Halle Nord'],
             ['weekday'=>4,'starts_at'=>'17:00:00','ends_at'=>'18:30:00','location'=>'Halle Süd']]]);
$tariff = make_tariff(['class_id'=>$course, 'name'=>'Monatsbeitrag', 'price_cents'=>4500,
                       'interval_months'=>1, 'rates'=>[1=>4500, 3=>12000, 12=>45000]]);
$lena = make_student(['first_name'=>'Lena', 'last_name'=>'Hofer', 'account_id'=>$family,
                      'birth_date'=>'2015-04-02', 'joined_on'=>'2026-01-01']);
// Lena's brother, on a login of his own: one login is one student (ADR 0010).
$tobiLogin = make_account(['role'=>'student', 'name'=>'Tobias Hofer']);
$tobi = make_student(['first_name'=>'Tobias', 'last_name'=>'Hofer', 'account_id'=>$tobiLogin,
                      'birth_date'=>'2009-08-11', 'joined_on'=>'2026-02-01']);
foreach ([$lena, $tobi] as $kid) {
    make_enrolment($course, $kid, ['joined_on'=>'2026-02-01', 'tariff_id'=>$tariff]);
    fixture('contacts', ['student_id'=>$kid, 'owner_name'=>'Maria Hofer', 'relation_label'=>'Mutter',
                         'phone'=>'+43 660 1234567', 'email'=>'maria@beispiel.test', 'is_primary'=>1]);
    fixture('absences', ['student_id'=>$kid, 'reason'=>'sick', 'starts_on'=>today(), 'ends_on'=>today(),
                         'created_by'=>$trainer]);
    fixture('attendance', ['class_id'=>$course, 'student_id'=>$kid, 'session_on'=>'2026-09-07',
                           'status'=>'present', 'note'=>'', 'recorded_by'=>$trainer, 'created_at'=>now()]);
}
$charge = fixture('charges', ['student_id'=>$lena, 'class_id'=>$course, 'tariff_id'=>$tariff,
    'label'=>'Beitrag September', 'origin'=>'auto', 'billing_key'=>'auto:2026-09-01:s'.$lena.':c'.$course,
    'amount_cents'=>4500, 'gross_cents'=>4500, 'discount_cents'=>0, 'discount_note'=>'',
    'period_from'=>'2026-09-01', 'period_to'=>'2026-09-30', 'due_on'=>'2026-09-01',
    'overdue_on'=>'2026-09-08', 'cancelled'=>0, 'created_at'=>now()]);
fixture('payments', ['charge_id'=>$charge, 'amount_cents'=>2000, 'paid_on'=>today(), 'method'=>'Überweisung',
                     'note'=>'Teilzahlung', 'confirmed_by'=>$trainer, 'confirmed_at'=>now(), 'voided'=>0]);
fixture('news', ['title'=>'Hallenzeiten', 'body'=>'Ab Oktober trainieren wir länger.', 'published'=>1,
                 'created_at'=>now(), 'updated_at'=>now()]);
$thread = make_thread([$family], ['subject'=>'Frage zum Schläger']);
fixture('messages', ['thread_id'=>$thread, 'sender_id'=>$family, 'body'=>'Welchen sollen wir kaufen?',
                     'created_at'=>now()]);
fixture('saved_filters', ['name'=>'Montagsgruppe', 'criteria_json'=>json_encode(['course'=>$course])]);
fixture('mail_jobs', ['account_id'=>$family, 'recipient'=>'familie@beispiel.test', 'subject'=>'Test',
                      'payload'=>seal('Hallo'), 'category'=>'notifications', 'status'=>'queued',
                      'attempts'=>0, 'created_at'=>now()]);
fixture('feedback', ['account_id'=>$family, 'page'=>'payments', 'message'=>'Der Knopf tut nichts.',
                     'context_json'=>'{}', 'screenshot_name'=>'', 'state'=>'new', 'created_at'=>now()]);
notify($trainer, 'message', 'Neue Nachricht', 'Familie Hofer', 'messages', ['id'=>$thread]);
request_enrolment($lena, make_class(['name'=>'Zweiter Kurs', 'days'=>[]]), 'join', null, 'Dürfen wir?');
sign_in_as($admin);
// An invoice needs the operator's own details and somewhere to send the money;
// the invoices suite checks those rules, this one only needs a document to exist
// so the pages have one to show.
foreach (['org_name'=>'Badminton Beispiel', 'org_street'=>'Hauptstraße 1', 'org_zip'=>'1010',
          'org_city'=>'Wien', 'org_country'=>'Österreich', 'org_email'=>'buero@beispiel.test'] as $k => $v)
    set_setting($k, $v);
set_setting('default_payment_profile', fixture('payment_profiles', ['name'=>'Vereinskonto',
    'recipient'=>'Badminton Beispiel', 'iban'=>'AT05 5100 0805 1317 6900', 'bic'=>'', 'currency'=>'EUR',
    'note'=>'', 'qr_template'=>'', 'archived'=>0, 'created_at'=>now()]));
$invoice = create_invoice($lena, [$charge]);

// ---------------------------------------------------------------------------
// The sweep itself.
// ---------------------------------------------------------------------------
/** Every page each role may open, with the query strings the interface produces. */
$pages = [
    'dashboard'  => [[]],
    'students'   => [[], ['q'=>'Hofer'], ['overdue'=>1], ['absence'=>'sick'], ['course'=>$course], ['saved'=>1]],
    'student'    => [['id'=>$lena], ['id'=>$lena,'tab'=>'contacts'], ['id'=>$lena,'tab'=>'absence'],
                     ['id'=>$lena,'tab'=>'attendance'], ['id'=>$lena,'tab'=>'classes'],
                     ['id'=>$lena,'tab'=>'invoices'], ['id'=>$lena,'tab'=>'payments']],
    'messages'   => [[], ['id'=>$thread], ['contacts'=>1], ['new'=>1]],
    'news'       => [[]],
    'profile'    => [[]],
];
$staffPages = [
    'classes'    => [[], ['tab'=>'requests'], ['id'=>$course], ['id'=>$course,'tab'=>'tariffs'],
                     ['id'=>$course,'tab'=>'dates'], ['id'=>$course,'tab'=>'attendance'], ['new'=>1]],
    'attendance' => [[], ['id'=>$course], ['id'=>$course,'on'=>'2026-09-07']],
    'payments'   => [[], ['period'=>'2026-09']],
    'invoices'   => [[], ['state'=>'open'], ['state'=>'overdue'], ['state'=>'paid'], ['state'=>'all']],
    'accounts'   => [[]],
    'print'      => [[], ['id'=>$lena]],
    'outbox'     => [[], ['p'=>1]],
    'compose'    => [[], ['course'=>$course]],
    'manage'     => [[], ['tab'=>'levels'], ['tab'=>'ages'], ['tab'=>'members'], ['tab'=>'tariffs'],
                     ['tab'=>'templates'], ['tab'=>'payments']],
];
$adminPages = [
    'settings'   => [[], ['tab'=>'portal'], ['tab'=>'organisation'], ['tab'=>'fields'], ['tab'=>'smtp'],
                     ['tab'=>'privacy'], ['tab'=>'feedback'], ['tab'=>'system']],
    'history'    => [[], ['entity'=>'students','id'=>$lena]],
    'start'      => [[]],
];

case_('Every page an administrator can open, opens');
sign_in_as($admin);
foreach ($pages + $staffPages + $adminPages as $page => $variants)
    foreach ($variants as $query)
        does_not_throw(fn() => render_view($page, $query), $page.' '.json_encode($query));

case_('Every page the trainer can open, opens');
sign_in_as($trainer);
foreach ($pages + $staffPages as $page => $variants)
    foreach ($variants as $query)
        does_not_throw(fn() => render_view($page, $query), $page.' '.json_encode($query));

case_('Every page a family can open, opens');
sign_in_as($family);
foreach ($pages as $page => $variants)
    foreach ($variants as $query) {
        // A family opens their own children, not the staff variants of a page.
        if ($page === 'students' && isset($query['saved'])) continue;
        does_not_throw(fn() => render_view($page, $query), $page.' '.json_encode($query));
    }

case_('And the signed-out pages open with nobody signed in');
$_SESSION = [];
current_user(true);
foreach (['login'=>[], 'forgot'=>[], 'privacy'=>[]] as $page => $query)
    does_not_throw(fn() => render_view($page, $query), $page);

case_('A page renders the same when the record it is about has almost nothing on it');
// The awkward case is not the full record, it is the empty one: a child with no
// contacts, no course, no charge and no date of birth.
sign_in_as($trainer);
$bare = make_student(['first_name'=>'Neu', 'last_name'=>'Angelegt', 'birth_date'=>null,
                      'joined_on'=>null, 'account_id'=>null]);
foreach ([[], ['tab'=>'contacts'], ['tab'=>'absence'], ['tab'=>'attendance'], ['tab'=>'classes'],
          ['tab'=>'invoices'], ['tab'=>'payments']] as $tab)
    does_not_throw(fn() => render_view('student', ['id'=>$bare] + $tab), 'a bare record: '.json_encode($tab));
// The access card has a state for each step (ADR 0010). With no address there
// is nobody to invite yet, so it says what to do first and offers no button; a
// button that could only be refused is worse than none.
$bareHtml = render_view('student', ['id'=>$bare]);
ok(str_contains($bareHtml, e('Zugang zum Portal')), 'a child with no account has the access card');
ok(str_contains($bareHtml, e('Trag oben zuerst eine E-Mail-Adresse ein und speichere.')), 'which, with no address, says to enter one first');
ok(!str_contains($bareHtml, 'value="student_invite"') && !str_contains($bareHtml, e('Einladung senden')),
   'and offers no invitation to send');
// With an address the invitation is offered, and that form is the one the
// nested-form rule has to see: it sits beside the record's own form. Only when
// mail can go out (ADR 0020, §6): otherwise the card says what is missing,
// because a button that can only be refused is worse than none.
$addressed = make_student(['first_name'=>'Neu', 'last_name'=>'Mitadresse', 'email'=>'neu.mitadresse@example.test', 'account_id'=>null]);
mail_ready(false);
$addressedHtml = render_view('student', ['id'=>$addressed]);
ok(!str_contains($addressedHtml, 'value="student_invite"') && str_contains($addressedHtml, e('Einladen geht noch nicht')),
   'with mail not ready, a child with an address is told why there is no invitation yet');
ok(str_contains($addressedHtml, e('Das richtet eine Administratorin unter „Einstellungen“ ein.')) && !str_contains($addressedHtml, e('Zur Einrichtung')),
   'and a trainer is told who sets it up, with no link to a page she cannot open');
mail_ready(true);
$addressedHtml = render_view('student', ['id'=>$addressed]);
ok(str_contains($addressedHtml, e('Einladung senden')) && str_contains($addressedHtml, 'value="student_invite"'),
   'a child with an address and no account is offered the invitation');
ok(str_contains($addressedHtml, e(' Die Einladung geht an neu.mitadresse@example.test.')), 'and the card says where it goes');
ok(!str_contains($addressedHtml, e('Trag oben zuerst eine E-Mail-Adresse ein und speichere.')), 'without being told to enter an address');
is_same(1, deepest_form_nesting($addressedHtml), 'and that form is not inside the record’s own form');

case_('An id that does not exist is refused rather than half-rendered');
foreach (['student'=>['id'=>999999], 'classes'=>['id'=>999999], 'messages'=>['id'=>999999]] as $page => $query)
    throws(fn() => render_view($page, $query), $page.' says it cannot find it');

case_('A family cannot open another family’s child by editing the address');
sign_in_as($other);
throws(fn() => render_view('student', ['id'=>$lena]), 'the page refuses');
throws(fn() => render_view('messages', ['id'=>$thread]), 'and so does the conversation');

case_('A record that is gone says so, rather than accusing anybody');
// "Kein Zugriff" over "Kurs nicht gefunden" reads as "you are not allowed to
// see your own course". The two cases are told apart by type, so the page can
// use the right word - and a family asking for somebody else's child still
// gets exactly the same answer as somebody asking for a child who was deleted.
sign_in_as($trainer);
throws(fn() => training_class(999999), 'a course that is gone', 'nicht gefunden');
try { training_class(999999); } catch (Throwable $e) { ok($e instanceof NotFound, 'is a NotFound, so the page answers 404'); }
try { student(999999); } catch (Throwable $e) { ok($e instanceof NotFound, 'and so is a child that is gone'); }
sign_in_as($other);
try { student($lena); } catch (Throwable $e) {
    ok($e instanceof NotFound, 'somebody else’s child is the same answer, not a different one');
}
ok(str_contains((string)file_get_contents(APP_ROOT.'/public/index.php'), 'NotFound'),
   'and the router is what turns that into the right page');

// ---------------------------------------------------------------------------
case_('No page puts one form inside another');
/* A form inside a form is markup the browser throws away: it keeps the outer
   one and drops the inner, so the button that says "Bild speichern" quietly
   submits the whole student record instead. Nothing on the page looks wrong,
   which is why it survived on the student page until somebody counted the tags.

   Counted rather than parsed, because the rule is about the tags themselves:
   a form opened and not closed is the same bug seen from the other side. */
function deepest_form_nesting(string $html): int {
    $depth = 0; $deepest = 0;
    foreach (preg_split('/(<form\b[^>]*>|<\/form\s*>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
        if (preg_match('/^<form\b/i', $piece)) { $depth++; $deepest = max($deepest, $depth); }
        elseif (preg_match('/^<\/form/i', $piece)) $depth--;
    }
    return $depth === 0 ? $deepest : 99;   // 99: unbalanced, which is worse
}
is_same(1, deepest_form_nesting('<form></form><form></form>'), 'two forms in a row are one deep');
is_same(2, deepest_form_nesting('<form><form></form></form>'), 'one inside another is two');
is_same(99, deepest_form_nesting('<form>'), 'and a form never closed is reported, not counted as fine');

sign_in_as($admin);
foreach ($pages + $staffPages + $adminPages as $page => $variants)
    foreach ($variants as $query) {
        $depth = deepest_form_nesting(render_view($page, $query));
        ok($depth <= 1, $page.' '.json_encode($query).' has no form inside a form (depth '.$depth.')');
    }
sign_in_as($family);
foreach ($pages as $page => $variants)
    foreach ($variants as $query) {
        if ($page === 'students' && isset($query['saved'])) continue;
        $depth = deepest_form_nesting(render_view($page, $query));
        ok($depth <= 1, 'as a family, '.$page.' '.json_encode($query).' has no form inside a form (depth '.$depth.')');
    }

// ---------------------------------------------------------------------------
case_('What goes on paper is exactly what the portal can hold');
/* A blank form that asks for something with nowhere to go produces a family who
   wrote it down and a trainer with nowhere to type it - and a filled sheet that
   leaves something out is a sheet nobody can check. So both are the same
   layout, and this is the rule that keeps them so. */
sign_in_as($trainer);
fixture('field_definitions', ['label'=>'Verein bisher', 'label_en'=>'Previous club', 'field_type'=>'text',
                              'section_name'=>'', 'options_json'=>'[]', 'default_json'=>'null', 'required'=>0,
                              'visibility'=>'view', 'sort_order'=>5, 'archived'=>0]);
fixture('field_definitions', ['label'=>'Nur intern', 'label_en'=>'', 'field_type'=>'text',
                              'section_name'=>'', 'options_json'=>'[]', 'default_json'=>'null', 'required'=>0,
                              'visibility'=>'internal', 'sort_order'=>6, 'archived'=>0]);
$blank = render_view('print');
// Every one of these is on a real club's own anmeldeformular. The address and
// the telephone were the two it asked for that the portal had nowhere to put.
foreach (['Vorname', 'Nachname', 'Geburtsdatum', 'E-Mail-Adresse', 'Anschrift', 'Telefonnummer',
          'Notfallkontakte', 'Verein bisher'] as $asked)
    ok(str_contains($blank, $asked), 'the blank form asks for '.$asked);
// A blank form does not know who is filling it in, and half a club's members are
// adults: it must not tell them it is meant for a child.
ok(!str_contains($blank, '>Kind<'), 'and it is not headed "Kind", because an adult joins too');
ok(str_contains($blank, 'bei Minderjährigen'), 'the guardian signs where that applies, not always');
ok(!str_contains($blank, 'Nur intern'), 'and not for a field marked internal, which is hers and not theirs');
ok(substr_count($blank, 'sheet-contact') >= 2, 'with room for two people to ring, not one');
ok(str_contains($blank, 'print-boxes'), 'in boxes, one letter each');
ok(!str_contains($blank, 'print-value'), 'and nothing filled in');
ok(str_contains($blank, 'nicht verkauft'), 'saying what happens to what they write down');
// Club news by email is on unless somebody declines it (ADR 0018), so the paper
// offers a box for "no" - a form still asking "yes, please" would make a parent
// who ticked nothing look like somebody who declined.
/* The paper's two email boxes, read the way print_tick() draws them: true for
   a tick, false for an empty box, null when the line is not there at all. */
$noNews     = 'Bitte keine Neuigkeiten des Vereins per E-Mail schicken.';
$noReminder = 'Bitte keine E-Mail bei neuen Nachrichten schicken.';
$tick = fn(string $html, string $label) =>
    preg_match('/<span class="print-box">(&#10003;)?<\/span><span>'.preg_quote(e($label), '/').'<\/span>/', $html, $m)
        ? ($m[1] ?? '') !== '' : null;
is_same(false, $tick($blank, $noNews), 'the news box on paper is an empty one for saying no');
is_same(false, $tick($blank, $noReminder), 'and so is the one for message reminders, both being on unless declined');
ok(!str_contains($blank, 'ich möchte die Neuigkeiten') && !str_contains($blank, 'Ja, bitte per E-Mail'),
   'and neither asks them to opt in any more');
// The fee block a club's own form has. Without it a parent has filled in a page
// that never says what they are agreeing to pay.
ok(str_contains($blank, 'Beitrag'), 'and what it costs is on it');
ok(str_contains($blank, 'Monatsbeitrag'), 'with the tariff named');
ok(str_contains($blank, '45,00'), 'and its price, taken from the price list so the paper cannot drift');
ok(!str_contains($blank, 'fällig am'), 'but not the day of the month, which is not what a tick decides');
// The course name goes above the list, not on every line: four tariffs meant
// four repetitions of the same words, and the sheet ran onto a second page.
// One course here, so its name is not repeated above the list at all; with two
// it appears once each. Either way, never once per tariff.
ok(substr_count($blank, 'Monatsbeitrag') <= 1, 'and the tariff itself appears once');
is_same(0, substr_count($blank, 'Kindertraining · Monatsbeitrag'), 'the course is not glued to every tariff line');

run('UPDATE students SET address=?, phone=? WHERE id=?',
    ['Hauptstraße 5, 7000 Eisenstadt', '+43 660 1234567', $lena]);
$sheet = render_view('print', ['id'=>$lena]);
ok(str_contains($sheet, 'print-value'), 'the data sheet has values on it');
ok(str_contains($sheet, 'Hauptstraße 5, 7000 Eisenstadt'), 'with the address on it to be checked');
ok(str_contains($sheet, '+43 660 1234567'), 'and the number to ring them on');
ok(str_contains($sheet, 'Lena'), 'the child’s name among them');
ok(str_contains($sheet, 'Maria Hofer'), 'and the person to ring');
ok(str_contains($sheet, 'Unterschrift'), 'with somewhere to sign that it was checked');
is_same(0, deepest_form_nesting($blank), 'and no form at all on it: it is printed, not submitted');
// A data sheet is what the portal holds, to be checked and signed - so a "no"
// the family already gave is on it, ticked, and a "yes" leaves the box empty.
$familyChoices = one('SELECT newsletter, notifications FROM accounts WHERE id=?', [$family]);
run('UPDATE accounts SET newsletter=0, notifications=1 WHERE id=?', [$family]);
$sheet = render_view('print', ['id'=>$lena]);
is_same(true, $tick($sheet, $noNews), 'a family who switched news off has the "no" ticked on the data sheet');
is_same(false, $tick($sheet, $noReminder), 'while the reminders they kept stay unticked');
run('UPDATE accounts SET newsletter=1, notifications=0 WHERE id=?', [$family]);
$sheet = render_view('print', ['id'=>$lena]);
is_same(false, $tick($sheet, $noNews), 'the other way round, news they receive is left unticked');
is_same(true, $tick($sheet, $noReminder), 'and the reminders they declined are ticked');
run('UPDATE accounts SET newsletter=?, notifications=? WHERE id=?',
    [(int)$familyChoices['newsletter'], (int)$familyChoices['notifications'], $family]);
$bareSheet = render_view('print', ['id'=>$bare]);
is_same([false, false], [$tick($bareSheet, $noNews), $tick($bareSheet, $noReminder)],
        'a child with no login yet has nothing to show, so both boxes are empty');

case_('And it is staff-only, because it carries a family’s details');
sign_in_as($family);
throws(fn() => render_view('print', ['id'=>$lena]), 'a family does not open the print view', 'Zugriff');

case_('Sending news by email is offered to the people who get it, not to "subscribers"');
/* With news on by default nobody subscribed to anything (ADR 0018), and the
   word was jargon to her anyway. */
sign_in_as($trainer);
$newsForm = render_view('news', ['new'=>1]);
ok(str_contains($newsForm, e('an alle senden, die Neuigkeiten per E-Mail erhalten')), 'the box says who the email goes to');
ok(!str_contains($newsForm, 'Newsletter'), 'without calling them newsletter subscribers');

case_('Setting up an account starts with club news by email switched on');
/* ADR 0018: club news is club information, on unless the person switches it off.
   The activation page decides for every invited account - the action stores
   whatever the box posts - so the column default alone would change nothing a
   family sees. The box has to arrive ticked, and still be a box they can untick. */
sign_out();
$invited = make_account(['role'=>'student', 'email'=>'eingeladen@beispiel.test', 'state'=>'invited',
                         'verified_at'=>null, 'password_hash'=>null]);
$_SESSION['activation_hash'] = hash('sha256', make_token($invited, 'invite'));
$activation = render_view('activate');
$box = fn(string $name) => preg_match('/<input\b[^>]*\bname="'.$name.'"[^>]*>/', $activation, $m) ? $m[0] : '';
// The attribute as a word: a class or a value that merely contains "checked"
// must not pass for a ticked box.
$ticked = fn(string $name) => (bool)preg_match('/\schecked(\s|>|=)/', $box($name));
ok($box('newsletter') !== '', 'the page has the club news box');
ok($ticked('newsletter'), 'and it arrives ticked');
ok(str_contains($box('newsletter'), 'type="checkbox"'), 'as a box that can still be unticked');
ok($ticked('notifications'), 'like the message reminders beside it');
ok($box('privacy_seen') !== '' && !$ticked('privacy_seen'), 'while having read the privacy notice is still theirs to tick');
ok(str_contains($activation, 'Neuigkeiten des Vereins per E-Mail erhalten'), 'it says what they will get');
ok(!str_contains($activation, 'Freiwillig') && !str_contains($activation, 'zusätzlich'),
   'without calling club information optional or extra');
unset($_SESSION['activation_hash']);

// ---------------------------------------------------------------------------
// Signing in with a username (ADR 0019): the pages that show one, ask for one,
// or say who else is on an address. A refusal is simulated by holding what it
// held, the way the router does after remember_input().
// ---------------------------------------------------------------------------
$tag = fn(string $html, string $name) => preg_match('/<input\b[^>]*\bname="'.$name.'"[^>]*>/', $html, $m) ? $m[0] : '';
$hold = function (string $action, string $page, int $id, array $fields): void {
    $GLOBALS['crm_held_input'] = ['action'=>$action, 'page'=>$page, 'id'=>(string)$id, 'tab'=>'', 'fields'=>$fields];
};
$usernameOf = fn(int $account) => (string)scalar('SELECT username FROM accounts WHERE id=?', [$account]);

case_('The sign-in page has one box, for the username or the address');
sign_out();
$signIn = render_view('login');
$box = $tag($signIn, 'username');
ok($box !== '', 'there is one box, posted as username');
ok(str_contains($signIn, e('Benutzername oder E-Mail-Adresse')), 'labelled for either');
foreach (['autocomplete="username"', 'autocapitalize="none"', 'autocorrect="off"', 'spellcheck="false"', 'inputmode="email"', ' required'] as $attribute)
    ok(str_contains($box, $attribute), 'it carries '.$attribute);
ok(str_contains($box, 'type="text"'), 'as a text box, so a username is not refused as a malformed address');
is_same('', $tag($signIn, 'email'), 'and no second box, not even a hidden one');
ok(str_contains($tag($signIn, 'password'), 'autocomplete="current-password"'), 'the password box offers the saved password');
ok(!str_contains($tag($signIn, 'password'), 'minlength'), 'without asking an existing password to be twelve characters');
ok(str_contains($signIn, e('Benutzername oder Passwort vergessen?')), 'the way to a forgotten username is named on it');
ok(!str_contains($signIn, 'class="notice'), 'and nothing else is said before anybody typed anything');

case_('A refused sign-in brings back what was typed, and says nothing about it');
/* One refusal for every failure (ADR 0020, §3), so the page cannot add a word
   of its own about what kind of thing was typed. */
foreach (['Maria@Beispiel.test', 'lena.hofr'] as $typed) {
    $hold('login', 'login', 0, ['username'=>$typed]);
    $signIn = render_view('login');
    ok(str_contains($tag($signIn, 'username'), 'value="'.e($typed).'"'), 'what was typed is back in the box: '.$typed);
    ok(!str_contains($signIn, 'class="notice') && !str_contains($signIn, e('Benutzernamen anfordern')), 'with no notice beside it: '.$typed);
}
unset($GLOBALS['crm_held_input']);

case_('„Vergessen" has the same one box, and says who else can help');
$forgot = render_view('forgot');
$box = $tag($forgot, 'username');
foreach (['autocomplete="username"', 'autocapitalize="none"', 'inputmode="email"', 'type="text"', ' required'] as $attribute)
    ok(str_contains($box, $attribute), 'the box carries '.$attribute);
is_same('', $tag($forgot, 'email'), 'and there is no address box beside it');
ok(str_contains($forgot, e('Benutzername oder E-Mail-Adresse')), 'labelled for either');
ok(!str_contains($forgot, e('Geschwister')), 'with nothing about brothers and sisters sharing an address');
ok(str_contains($forgot, e('Oder frag deine Trainerin – sie kann dir deinen Benutzernamen sagen.')), 'and the trainer is named as the other way');

case_('A reset link shows the username it resets, to read and to save the password under (N10)');
run('UPDATE accounts SET username=? WHERE id=?', ['lena.hofer', $family]);
$_SESSION['activation_hash'] = hash('sha256', make_token($family, 'reset'));
$reset = render_view('activate');
$box = $tag($reset, 'username');
ok(str_contains($box, 'value="lena.hofer"'), 'the page shows the username');
ok(str_contains($box, 'readonly="readonly"') && !str_contains($box, 'disabled'), 'read-only, not disabled, which autofill would skip');
ok(str_contains($box, 'autocomplete="username"'), 'marked as the username, so the new password is saved under it');
ok(str_contains($reset, e('Neues Passwort')) && str_contains($reset, e('Passwort speichern')), 'and the button says what it does');
unset($_SESSION['activation_hash']);

case_('Mein Konto shows the username, and nothing about sharing an address');
/* No address can be shared any more (ADR 0020), so there is nobody to count. */
sign_in_as($family);
$familyEmail = (string)scalar('SELECT email FROM accounts WHERE id=?', [$family]);
$mine = render_view('profile');
ok(str_contains($mine, '<dd class="mono">lena.hofer</dd>'), 'the holder reads their own username');
ok(!str_contains($mine, e('Diese E-Mail-Adresse nutz')), 'with nothing about sharing');

case_('Mein Konto lists a recent reset, and tells a family and staff different things to do (I2)');
$resetAt = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
fixture('audit_log', ['actor_id'=>$family, 'action'=>'account.password_reset', 'entity_type'=>'account', 'entity_id'=>$family, 'created_at'=>$resetAt]);
$mine = render_view('profile');
ok(str_contains($mine, e(fmt_datetime($resetAt))), 'the reset is listed with its date and time');
ok(str_contains($mine, e('sag deiner Trainerin Bescheid')), 'a family is told to let the trainer know');
fixture('audit_log', ['actor_id'=>$trainer, 'action'=>'account.password_reset', 'entity_type'=>'account', 'entity_id'=>$trainer, 'created_at'=>$resetAt]);
sign_in_as($trainer);
$staffOwn = render_view('profile');
ok(str_contains($staffOwn, e('Warst du das nicht? Ändere dein Passwort gleich hier unten.')), 'staff are told to change it');
ok(!str_contains($staffOwn, e('Trainerin Bescheid')), 'and are not sent to themselves');
run("DELETE FROM audit_log WHERE action='account.password_reset'");

case_('Mein Konto offers the username change, and opens it again after a refusal');
sign_in_as($family);
$mine = render_view('profile');
ok(str_contains($mine, 'value="username_change"'), 'the form is there');
ok(str_contains($tag($mine, 'current_password'), 'autocomplete="current-password"'), 'asking for the current password as the current one');
ok(!str_contains($tag($mine, 'current_password'), 'new-password'), 'not as a new one an iPhone would offer to invent');
ok(!str_contains($tag($mine, 'username'), 'pattern='), 'with no pattern, so Lena.Müller reaches the server that fixes it');
ok(!str_contains($mine, '<details id="username" open'), 'folded away until wanted');
$hold('username_change', 'profile', 0, ['username'=>'lena.neu']);
$mine = render_view('profile');
ok(str_contains($mine, '<details id="username" open'), 'open after a refusal');
ok(str_contains($tag($mine, 'username'), 'value="lena.neu"'), 'with what was typed still in the box');
unset($GLOBALS['crm_held_input']);
$_SESSION['impersonator_id'] = $trainer;
$viewed = render_view('profile');
ok(!str_contains($viewed, 'value="username_change"'), 'somebody looking through the family’s eyes is not offered it');
ok(str_contains($viewed, e('Den Benutzernamen ändert nur Lena selbst.')), 'and is told who changes it');
unset($_SESSION['impersonator_id']);
is_same(1, deepest_form_nesting($mine), 'and no form on Mein Konto is inside another');

case_('The student page shows the username first, and the access card says who else uses the address');
sign_in_as($trainer);
$page = render_view('student', ['id'=>$lena]);
ok(preg_match('~<div id="email"><div class="field"><label>'.preg_quote(e('Benutzername'), '~').'</label><div class="readonly mono">lena\.hofer</div>~', $page) === 1,
   'the username is the first thing in the personal details');
ok(str_contains($page, '<dd class="mono">lena.hofer</dd>'), 'and it is in the access card');
ok(!str_contains($page, e('Diese Adresse nutzen auch')), 'with no count of others on the address, which nobody shares');
ok(str_contains($page, e('Zur Bestätigung den Benutzernamen eintippen: ').'</p>') === false
   && str_contains($page, e(' Zur Bestätigung den Benutzernamen eintippen: ').'<strong class="mono">lena.hofer</strong></p>'),
   'deleting the access asks for the username, named outside the label');
ok(!preg_match('/<input\b[^>]*name="confirmation"[^>]*type="email"/', $page), 'in a text box, not an address box');
$paul = make_student(['first_name'=>'Paul', 'last_name'=>'Hofer', 'email'=>$familyEmail, 'account_id'=>null]);
sign_in_as($admin);
$page = render_view('student', ['id'=>$paul]);
ok(!str_contains($page, 'name="same_family"'), 'a child at somebody’s login address is offered no „Gleiche Familie“ (ADR 0020)');
ok(str_contains($page, e('Eigene E-Mail-Adresse eintragen')), 'and is asked for an address of their own before anybody taps');
is_same(1, deepest_form_nesting($page), 'every form on the card beside the record’s rather than inside it');

case_('An invited login’s card names the username, and a refused re-address asks nothing more');
$invitedLogin = make_account(['role'=>'student', 'name'=>'Mia Hofer', 'email'=>'mia@beispiel.test', 'username'=>'mia.hofer',
                              'state'=>'invited', 'verified_at'=>null, 'password_hash'=>null]);
$mia = make_student(['first_name'=>'Mia', 'last_name'=>'Hofer', 'email'=>'mia@beispiel.test', 'account_id'=>$invitedLogin]);
/* The invitation's dates (ADR 0020, §10c): none for a token pruned overnight,
   and then no date is guessed. */
$page = render_view('student', ['id'=>$mia]);
ok(str_contains($page, e('Die Einladung ist abgelaufen. Schick sie noch einmal – der neue Link gilt wieder 48 Stunden.')),
   'an invitation with no token left says it has expired, and names no date');
ok(preg_match('~<button class="button primary" type="submit">'.preg_quote(e('Einladung erneut senden'), '~').'</button>~', $page) === 1,
   'and sending it again is the button that stands out');
make_token($invitedLogin, 'invite');
$sent = invitation_dates($invitedLogin);
$page = render_view('student', ['id'=>$mia]);
ok(str_contains($page, e('Eingeladen am '.fmt_datetime((string)$sent['created_at']).'; der Link gilt bis '.fmt_datetime((string)$sent['expires_at']).'.')),
   'a live invitation says when it was sent and until when it works');
ok(str_contains($page, e('Beim Einrichten kann Mia den Benutzernamen noch ändern.')), 'and that the username can still be changed');
ok(preg_match('~<button class="button secondary" type="submit">'.preg_quote(e('Einladung erneut senden'), '~').'</button>~', $page) === 1,
   'with sending it again a quiet button while the link still works');
run("UPDATE auth_tokens SET expires_at=? WHERE account_id=? AND purpose='invite'", [gmdate('Y-m-d H:i:s', time() - 3600), $invitedLogin]);
ok(str_contains(render_view('student', ['id'=>$mia]), e('Die Einladung vom '.fmt_date((string)$sent['created_at']).' ist abgelaufen.')),
   'an expired invitation not yet pruned names the day it was sent');
$hold('student_save', 'student', $mia, ['email'=>$familyEmail, 'first_name'=>'Mia']);
ok(!str_contains(render_view('student', ['id'=>$mia]), 'name="same_family"'), 'and no „Gleiche Familie“ tick after a refusal either (ADR 0020)');
unset($GLOBALS['crm_held_input']);

case_('Konten shows usernames');
/* The R10 flag for a staff login sharing an address cannot arise any more: the
   unique index refuses the pair (ADR 0020, §8). */
sign_in_as($admin);
run('UPDATE accounts SET username=? WHERE id=?', ['trainerin.beispiel', $trainer]);
$team = render_view('accounts');
ok(str_contains($team, '<p class="mono">trainerin.beispiel</p>'), 'each login shows its username');
ok(!str_contains($team, e('Ein Konto für Trainerin oder Administrator braucht eine eigene E-Mail-Adresse.')), 'with no sharing flag');
sign_out();

// ---------------------------------------------------------------------------
// The screens of ADR 0020: families completing their own details, inviting in
// one step, the access card's states, and „Von Familien".
// ---------------------------------------------------------------------------
/** The markup of the first form that posts $action, up to its closing tag. */
$formOf = function (string $html, string $action): string {
    $at = strpos($html, 'name="action" value="'.$action.'"');
    if ($at === false) return '';
    $start = strrpos(substr($html, 0, $at), '<form');
    return substr($html, $start, strpos($html, '</form>', $at) - $start);
};

case_('A family completes its own details on the Profil tab, in the one student form');
mail_ready(true);
run("UPDATE students SET address='', phone='' WHERE id=?", [$lena]);
$shirt = fixture('field_definitions', ['label'=>'T-Shirt-Größe', 'label_en'=>'', 'field_type'=>'select', 'section_name'=>'',
    'options_json'=>json_encode(['S','M','L']), 'default_json'=>'null', 'required'=>1, 'visibility'=>'edit', 'sort_order'=>0, 'archived'=>0]);
sign_in_as($family);
$profil = render_view('student', ['id'=>$lena]);
ok(str_contains($profil, '<h2>'.e('Noch zu ergänzen').'</h2>'), 'the family is shown what is still to fill in');
ok(str_contains($profil, e(url('student', ['id'=>$lena])).'#address"'), 'with a step leading to the address box');
ok(str_contains($profil, 'id="address"') && str_contains($profil, 'id="field-'.$shirt.'"') && str_contains($profil, 'id="personal"'),
   'and the boxes it leads to carry those anchors');
$studentForm = $formOf($profil, 'student_save');
ok(str_contains($studentForm, 'name="address"') && str_contains($studentForm, 'name="phone"'), 'the address and phone are in the student form, so one save carries them');
ok(str_contains($studentForm, e('eine Rechnung über 400 € braucht sie')), 'with the family’s own hint about invoices');
ok(!str_contains($studentForm, 'name="email"') && !str_contains($profil, 'id="access"'), 'and no address box or access card of the login’s');
ok(str_contains($studentForm, e('Angaben speichern')), 'the button says what it saves');
ok(strpos($profil, 'value="avatar_save"') > strpos($profil, $studentForm) + strlen($studentForm),
   'the picture comes after the details, outside their form');
is_same(1, deepest_form_nesting($profil), 'and no form is inside another');
ok(preg_match('~<select[^>]*name="custom\['.$shirt.'\]"[^>]*required~', $profil) === 1 && str_contains($profil, e('Bitte ausfüllen.')),
   'a required field the family fills in is required of them, and asked for');
ok(str_contains($profil, e('T-Shirt-Größe').' <span aria-hidden="true">*</span>'), 'with the same mark every required box has');
ok(str_contains(render_view('dashboard'), '<h2>'.e('Noch zu ergänzen').'</h2>'), 'the overview shows the same card');

case_('Staff are never held up by a field the family fills in');
sign_in_as($trainer);
$staffPage = render_view('student', ['id'=>$lena]);
ok(preg_match('~<select[^>]*name="custom\['.$shirt.'\]"[^>]*required~', $staffPage) === 0, 'the family’s required field is not required of her');
ok(str_contains($staffPage, e('Fehlt noch. Das füllt die Familie aus.')), 'and says who fills it in');
ok(!str_contains($staffPage, '<h2>'.e('Noch zu ergänzen').'</h2>'), 'her page keeps her own list, not the family’s');
run('DELETE FROM field_definitions WHERE id=?', [$shirt]);

case_('A refused edit of one contact opens that contact’s form, and no other');
$granny = fixture('contacts', ['student_id'=>$lena, 'owner_name'=>'Gertrude Hofer', 'relation_label'=>'Großmutter',
                               'phone'=>'', 'email'=>'', 'is_primary'=>0]);
$GLOBALS['crm_held_input'] = ['action'=>'contact_save', 'page'=>'student', 'id'=>(string)$lena, 'tab'=>'contacts',
                              'record'=>$granny, 'fields'=>['owner_name'=>'Gertrude Hofer', 'relation_label'=>'Tippfehler Person']];
$contactsTab = render_view('student', ['id'=>$lena, 'tab'=>'contacts']);
preg_match_all('~<details ?(open)?><summary>'.preg_quote(e('Kontakt bearbeiten'), '~').'</summary>~', $contactsTab, $m);
is_same(['', 'open'], $m[1], 'only the refused contact’s form is open');
is_same(1, substr_count($contactsTab, 'value="Tippfehler Person"'), 'and only it holds what was typed');
unset($GLOBALS['crm_held_input']);
run('DELETE FROM contacts WHERE id=?', [$granny]);

case_('A family with nobody to ring is asked for somebody, in its own words');
$alone = make_account(['role'=>'student', 'name'=>'Ida Neu']);
$ida = make_student(['first_name'=>'Ida', 'last_name'=>'Neu', 'account_id'=>$alone]);
sign_in_as($alone);
$idaContacts = render_view('student', ['id'=>$ida, 'tab'=>'contacts']);
ok(str_contains($idaContacts, e('Noch niemand eingetragen. Bitte trag mindestens eine Person ein, die im Notfall angerufen werden kann.')), 'the family is asked for one person');
ok(!str_contains($idaContacts, e('Für dieses Kind')), 'not told about „this child" as the trainer is');

case_('The create form invites in one step when mail can go out, and says why not when it cannot');
sign_in_as($admin);
$create = render_view('student');
ok(preg_match('~<input type="checkbox" name="invite" value="1" checked>~', $create) === 1, '„Gleich einladen" is offered, ticked');
ok(strpos($create, 'name="invite"') < strpos($create, 'name="birth_date"'), 'directly under the address, before the boxes the family fills in');
mail_ready(false);
$create = render_view('student');
ok(!str_contains($create, 'name="invite"'), 'with mail not ready there is no tick');
ok(str_contains($create, e('Einladen geht noch nicht')) && str_contains($create, e(url('start'))), 'and an administrator is shown what is missing, and the way to the setup');
mail_ready(true);

case_('An active login’s card offers a reset link; a suspended one does not');
$lenaCard = render_view('student', ['id'=>$lena]);
ok(str_contains($lenaCard, 'name="mode" value="reset_link"') && str_contains($lenaCard, e('Link zum Zurücksetzen senden')), 'an active login can be sent a reset link');
run("UPDATE accounts SET state='suspended' WHERE id=?", [$family]);
ok(!str_contains(render_view('student', ['id'=>$lena]), 'value="reset_link"'), 'a suspended one cannot');
run("UPDATE accounts SET state='active' WHERE id=?", [$family]);
mail_ready(false);
ok(!str_contains(render_view('student', ['id'=>$lena]), 'value="reset_link"'), 'nor can anybody while mail cannot go out');
mail_ready(true);

case_('Konten invites only, and offers a reset link for a team login in use');
$team = render_view('accounts');
ok(!str_contains($team, 'account_create') && !str_contains($team, 'name="password"'), 'no login is made with a password typed by staff');
ok(str_contains($team, 'name="mode" value="reset_link"'), 'a team login in use can be sent a reset link');
mail_ready(false);
$team = render_view('accounts');
ok(!str_contains($team, 'value="account_invite"') && str_contains($team, e('Einladen geht noch nicht')), 'with mail not ready the invite card says why instead');
mail_ready(true);

case_('Änderungen separates what families changed');
sign_in_as($family);
tracked('students', $lena, 'Lena Hofer', fn() => run("UPDATE students SET phone='+43 1' WHERE id=?", [$lena]));
sign_in_as($admin);
$log = render_view('history');
ok(str_contains($log, e('Von Familien')) && str_contains($log, e(url('history', ['tab'=>'family']))), 'the list has a tab for families');
ok(str_contains($log, e('Familie Hofer (Familie)')), 'and a family’s change says so after the name');
tracked('students', $lena, 'Lena Hofer', fn() => run("UPDATE students SET phone='+43 2' WHERE id=?", [$lena]));
$familyLog = render_view('history', ['tab'=>'family']);
is_same(1, substr_count($familyLog, 'class="history-row"'), '„Von Familien" lists the family’s change and not hers');
ok(!str_contains(render_view('history', ['entity'=>'students', 'record'=>$lena]), e('Von Familien')), 'one record’s history has no tabs');
sign_out();
