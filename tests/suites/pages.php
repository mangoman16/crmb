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
    'students'   => [[], ['q'=>'Hofer'], ['overdue'=>1], ['absence'=>'sick'], ['course'=>$course]],
    'student'    => [['id'=>$lena], ['id'=>$lena,'tab'=>'contacts'], ['id'=>$lena,'tab'=>'absence'],
                     ['id'=>$lena,'tab'=>'attendance'], ['id'=>$lena,'tab'=>'classes'],
                     ['id'=>$lena,'tab'=>'invoices'], ['id'=>$lena,'tab'=>'payments']],
    'messages'   => [[], ['id'=>$thread], ['new'=>1]],
    'news'       => [[]],
    'profile'    => [[]],
];
$staffPages = [
    'classes'    => [[], ['tab'=>'requests'], ['id'=>$course], ['id'=>$course,'tab'=>'tariffs'],
                     ['id'=>$course,'tab'=>'dates'], ['id'=>$course,'tab'=>'attendance'], ['new'=>1]],
    'attendance' => [[], ['id'=>$course], ['id'=>$course,'on'=>'2026-09-07']],
    'payments'   => [[], ['period'=>'2026-09'], ['overdue'=>1], ['overdue'=>1, 'period'=>'2026-09']],
    'invoices'   => [[], ['state'=>'open'], ['state'=>'overdue'], ['state'=>'paid'], ['state'=>'all']],
    'accounts'   => [[]],
    'outbox'     => [[], ['p'=>1]],
    'manage'     => [[], ['tab'=>'levels'], ['tab'=>'ages'], ['tab'=>'members'], ['tab'=>'payments']],
];
$adminPages = [
    'settings'   => [[], ['tab'=>'portal'], ['tab'=>'organisation'], ['tab'=>'smtp'],
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
    foreach ($variants as $query)
        does_not_throw(fn() => render_view($page, $query), $page.' '.json_encode($query));

case_('And the signed-out pages open with nobody signed in');
$_SESSION = [];
current_user(true);
foreach (['login'=>[], 'forgot'=>[], 'privacy'=>[]] as $page => $query)
    does_not_throw(fn() => render_view($page, $query), $page);

case_('A page renders the same when the record it is about has almost nothing on it');
// The awkward case is not the full record, it is the empty one: a child with no
// contacts, no course, no charge and no date of birth.
sign_in_as($trainer);
$bare = make_student(['first_name'=>'Neu', 'last_name'=>'Angelegt', 'birth_date'=>null, 'joined_on'=>null]);
foreach ([[], ['tab'=>'contacts'], ['tab'=>'absence'], ['tab'=>'attendance'], ['tab'=>'classes'],
          ['tab'=>'invoices'], ['tab'=>'payments']] as $tab)
    does_not_throw(fn() => render_view('student', ['id'=>$bare] + $tab), 'a bare record: '.json_encode($tab));
// The access card has a state for each step (ADR 0010). Without sign-in it is
// one form, the address and „Einladung senden“ (ADR 0030 §6): with no address on
// the record its box is empty, and the box says what is missing.
$cardOf = fn(string $html): string => preg_match('~<section class="card access-card" id="access">.*?</section>~s', $html, $m) ? $m[0] : '';
mail_ready(true);
$bareHtml = render_view('student', ['id'=>$bare]);
ok(str_contains($bareHtml, e('Zugang zum Portal')), 'a child without sign-in has the access card');
ok(str_contains($bareHtml, e('Ohne Anmeldung')), 'which says „Ohne Anmeldung“ for a placeholder (ADR 0023 §3)');
ok(str_contains($cardOf($bareHtml), 'value="student_invite"') && preg_match('~name="email" type="email" value=""~', $cardOf($bareHtml)) === 1,
   'which, with no address on the record, has an empty box for one beside „Einladung senden“');
ok($cardOf($bareHtml) !== '' && !str_contains($bareHtml, e('Trag oben zuerst eine E-Mail-Adresse ein und speichere.')), 'rather than sending her up the page first');
// With an address the invitation is offered, and that form is the one the
// nested-form rule has to see: it sits beside the record's own form. Only when
// mail can go out (ADR 0020, §6): otherwise the card says what is missing,
// because a button that can only be refused is worse than none.
$addressed = make_student(['first_name'=>'Neu', 'last_name'=>'Mitadresse', 'email'=>'neu.mitadresse@example.test']);
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
ok(preg_match('~name="email" type="email" value="neu\.mitadresse@example\.test"~', $cardOf($addressedHtml)) === 1, 'with the record’s address in the card’s box, which says where it goes');
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
/* deepest_form_nesting() (tests/harness.php) is held to known answers first. */
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
        $depth = deepest_form_nesting(render_view($page, $query));
        ok($depth <= 1, 'as a family, '.$page.' '.json_encode($query).' has no form inside a form (depth '.$depth.')');
    }

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
// Signing in by address (ADR 0021): the pages that ask for the address, show it
// beside a new password, or have it typed to confirm. A refusal is simulated by
// holding what it held, the way the router does after remember_input().
// ---------------------------------------------------------------------------
$tag = fn(string $html, string $name) => preg_match('/<input\b[^>]*\bname="'.$name.'"[^>]*>/', $html, $m) ? $m[0] : '';
$hold = function (string $action, string $page, int $id, array $fields): void {
    $GLOBALS['crm_held_input'] = ['action'=>$action, 'page'=>$page, 'id'=>(string)$id, 'tab'=>'', 'fields'=>$fields];
};
/** The markup of the first form that contains $marker, up to its closing tag. */
$formWith = function (string $html, string $marker): string {
    $at = strpos($html, $marker);
    if ($at === false) return '';
    $start = strrpos(substr($html, 0, $at), '<form');
    return substr($html, $start, strpos($html, '</form>', $at) - $start);
};
/** The markup of the first form that posts $action. */
$formOf = fn(string $html, string $action): string => $formWith($html, 'name="action" value="'.$action.'"');
$addressBox = ['type="email"', 'autocomplete="username"', 'autocapitalize="none"', 'autocorrect="off"', 'spellcheck="false"'];

/* The sign-in and „vergessen" box takes the address (ADR 0021 §1, 0030 §1):
   type="email", which brings „@" to the keyboard and checks the shape before
   posting, and the rest of what a sign-in box carries. */
$signInBox = ['type="email"', 'autocomplete="username"', 'autocapitalize="none"', 'autocorrect="off"', 'spellcheck="false"'];

case_('The sign-in page has one box, for the address, which the password is saved under');
sign_out();
$signIn = render_view('login');
$box = $tag($signIn, 'login');
ok($box !== '', 'there is one box, posted as login');
ok(str_contains($signIn, e('E-Mail-Adresse')) && !str_contains($signIn, e('Benutzername')), 'labelled as the address, and nothing about a username');
ok(!str_contains($box, 'inputmode'), 'with no inputmode, which type="email" makes pointless');
foreach ([...$signInBox, ' required'] as $attribute)
    ok(str_contains($box, $attribute), 'it carries '.$attribute);
is_same('', $tag($signIn, 'email'), 'there is no second box for the address');
is_same('', $tag($signIn, 'username'), 'nor one for the username, not even a hidden one');
ok(str_contains($tag($signIn, 'password'), 'autocomplete="current-password"'), 'the password box offers the saved password');
ok(!str_contains($tag($signIn, 'password'), 'minlength'), 'without asking an existing password to be twelve characters');
ok(str_contains($signIn, e('Passwort vergessen?')), 'the way to a forgotten password is on it');
ok(!str_contains($signIn, 'class="notice'), 'and nothing else is said before anybody typed anything');

case_('Signing in says where the privacy notice is, and asks nothing [the coordinator, 2026-10-09]');
/* The notice informs; signing in is not agreeing to it, so „… akzeptierst …"
   went. The sentence links it, and the footer leaves its own link out on this
   page: one on a page, where it was twice (the audit, 17). */
sign_out();
$signInPage = render_page('login');
ok(str_contains($signInPage, e('Deine Daten: ').'<a href="'.e(url('privacy')).'">'.e('Datenschutzerklärung').'</a>') && !str_contains($signInPage, 'akzeptierst'),
   'where to read about your data is said, not agreed to: „Deine Daten: Datenschutzerklärung"');
is_same(1, substr_count($signInPage, 'href="'.e(url('privacy')).'"'), 'and the page links the notice once');
is_same(1, substr_count(render_page('forgot'), 'href="'.e(url('privacy')).'"'), 'while a public page without the sentence keeps the footer\'s link');
/* Setting up a login from an invitation links the notice above its box to tick,
   so its footer leaves the second link out too. */
$invitedOnce = make_account(['role'=>'student', 'email'=>'einmal.verlinkt@beispiel.test', 'state'=>'invited', 'verified_at'=>null, 'password_hash'=>null]);
$_SESSION['activation_hash'] = hash('sha256', make_token($invitedOnce, 'invite'));
is_same(1, substr_count(render_page('activate'), 'href="'.e(url('privacy')).'"'), 'and so does setting up a login from an invitation');
unset($_SESSION['activation_hash']);

case_('A refused sign-in brings back what was typed, and says nothing about it');
/* One refusal for every failure (ADR 0020, §3), so the page cannot add a word
   of its own about what kind of thing was typed. */
foreach (['Maria@Beispiel.test', 'lena@beispiel'] as $typed) {
    $hold('login', 'login', 0, ['login'=>$typed]);
    $signIn = render_view('login');
    ok(str_contains($tag($signIn, 'login'), 'value="'.e($typed).'"'), 'what was typed is back in the box: '.$typed);
    ok(!str_contains($signIn, 'class="notice'), 'with no notice beside it: '.$typed);
}
unset($GLOBALS['crm_held_input']);

case_('„Passwort vergessen" has the same one box, and says who else can help');
$forgot = render_view('forgot');
$box = $tag($forgot, 'login');
foreach ([...$signInBox, ' required'] as $attribute)
    ok(str_contains($box, $attribute), 'the box carries '.$attribute);
is_same('', $tag($forgot, 'username'), 'and there is no second box beside it');
ok(str_contains($forgot, '<h1>'.e('Passwort vergessen').'</h1>'), 'headed for the password');
ok(!str_contains($forgot, e('Ohne E-Mail-Adresse angemeldet?')) && !str_contains($forgot, e('Benutzername')),
   'and nothing about a login without an address, which cannot sign in (ADR 0030 §1)');
ok(str_contains($forgot, e('Oder frag deine Trainerin – sie sieht, mit welcher Adresse du eingetragen bist.')), 'and the trainer is named as the other way');

case_('Every page that sets a password shows the address read-only, directly above it');
/* The three forms (ADR 0021, §1, §3): an invitation by address alone, an
   invitation to a person staff created, and a reset. Each pairs the address with
   the new password in one form, which is what an iPhone saves the password
   under - visible, read-only, and neither hidden nor disabled, which the
   password manager would skip. */
$byAddress = make_account(['role'=>'student', 'name'=>'', 'email'=>'neu.per.adresse@beispiel.test', 'state'=>'invited',
                           'verified_at'=>null, 'password_hash'=>null]);
$created = make_account(['role'=>'student', 'name'=>'Jonas Berger', 'email'=>'jonas@beispiel.test', 'state'=>'invited',
                         'verified_at'=>null, 'password_hash'=>null]);
make_student(['first_name'=>'Jonas', 'last_name'=>'Berger', 'email'=>'jonas@beispiel.test', 'account_id'=>$created]);
set_setting('club_name', 'Federball Nord');
foreach (['by address'=>[$byAddress, 'invite'], 'created by staff'=>[$created, 'invite'], 'reset'=>[$family, 'reset']] as $which => [$account, $purpose]) {
    $_SESSION['activation_hash'] = hash('sha256', make_token($account, $purpose));
    $page = render_view('activate');
    $form = $formOf($page, 'activate');
    $address = (string)scalar('SELECT email FROM accounts WHERE id=?', [$account]);
    $box = $tag($form, 'email');
    ok(str_contains($box, 'value="'.e($address).'"'), $which.': the login’s address is in the form');
    ok(str_contains($box, 'readonly="readonly"') && !str_contains($box, 'disabled') && !str_contains($box, 'type="hidden"'),
       $which.': read-only, neither disabled nor hidden');
    ok(!str_contains($box, ' required'), $which.': and not required, since nothing is typed into it');
    foreach ($addressBox as $attribute) ok(str_contains($box, $attribute), $which.': it carries '.$attribute);
    $password = $tag($form, 'password');
    $after = strpos($form, $box) + strlen($box);
    $between = $password === '' ? '<input' : substr($form, $after, strpos($form, $password) - $after);
    ok(!str_contains($between, '<input') && !str_contains($between, '<select'), $which.': directly above the new password, with no box between');
    is_same($which === 'by address' ? ['first_name', 'last_name', 'birth_date'] : [],
            array_values(array_filter(['first_name', 'last_name', 'birth_date'], fn($name) => $tag($form, $name) !== '')),
            $which === 'by address' ? $which.': asks for first name, last name and birth date' : $which.': asks for no details');
    if ($which === 'by address') {
        $birth = $tag($form, 'birth_date');
        ok(str_contains($birth, 'type="date"') && str_contains($birth, ' required') && str_contains($birth, 'autocomplete="bday"')
           && str_contains($birth, 'max="'.today().'"'), 'the birth date is a required date box, no later than today');
        ok(str_contains($tag($form, 'first_name'), 'autocomplete="given-name"') && str_contains($tag($form, 'last_name'), 'autocomplete="family-name"'),
           'the names are offered from the phone’s own card');
        ok(strpos($form, 'name="birth_date"') < strpos($form, 'name="email"'), 'the details come before the sign-in');
        ok(str_contains($page, '<span class="eyebrow">'.e('Federball Nord').'</span>'), 'with the portal’s name above, since the login has none yet');
        ok(str_contains($page, e('Danach wählst du deinen Kurs.')), 'and it says the course comes next');
    }
    if ($which === 'created by staff') ok(str_contains($page, '<span class="eyebrow">'.e('Jonas Berger').'</span>'), 'a person staff created is named above');
    if ($which === 'reset') ok(str_contains($page, e('Neues Passwort')) && str_contains($page, e('Passwort speichern')), 'a reset says what the button does');
    else ok(str_contains($form, 'name="privacy_seen"') && str_contains($form, e('Konto aktivieren')), $which.': an invitation asks for the privacy notice and activates');
    ok(!str_contains($page, e('Benutzername')), $which.': and no username anywhere');
}
unset($_SESSION['activation_hash']);
set_setting('club_name', 'Badminton');

case_('An expired link says signing in is still possible, and how to get a new one');
$_SESSION['activation_hash'] = hash('sha256', 'no such link');
$gone = render_view('activate');
ok(str_contains($gone, e('Schon eingerichtet? Dann melde dich einfach mit deiner E-Mail-Adresse an.')), 'somebody already set up is told to sign in');
ok(str_contains($gone, e('Unter „Passwort vergessen“ bekommst du einen neuen Link')), 'and the way to a new link is named as the sign-in page names it');
unset($_SESSION['activation_hash']);

case_('Mein Konto shows the address it signs in with, and nothing else to sign in with');
/* No address can be shared (ADR 0020) and no username exists (ADR 0021), so
   there is nobody to count and nothing to change but the address. */
sign_in_as($family);
$familyEmail = (string)scalar('SELECT email FROM accounts WHERE id=?', [$family]);
$mine = render_view('profile');
ok(str_contains($mine, '<dd>'.e($familyEmail).'<small'), 'the holder reads their own address');
ok(str_contains($mine, e('Mit dieser Adresse meldest du dich an.')), 'and that it is what they sign in with');
ok(!str_contains($mine, 'username') && !str_contains($mine, e('Benutzername')), 'with no username and no way to change one');
ok(!str_contains($mine, e('Diese E-Mail-Adresse nutz')), 'and nothing about sharing');
ok(str_contains($mine, e('bis du ihn öffnest, meldest du dich mit der bisherigen an.')), 'changing the address says the old one works until it is confirmed');
ok(str_contains($tag($formOf($mine, 'email_change'), 'password'), 'autocomplete="current-password"'), 'asking for the current password as the current one');
ok(!str_contains($tag($formOf($mine, 'password_change'), 'current_password'), 'new-password'), 'not as a new one an iPhone would offer to invent');
is_same(1, deepest_form_nesting($mine), 'and no form on Mein Konto is inside another');

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

case_('The student page shows the address first, and deleting the access asks for it');
sign_in_as($trainer);
$page = render_view('student', ['id'=>$lena]);
ok(preg_match('~<div id="email"><div class="field"><label>'.preg_quote(e('E-Mail-Adresse'), '~').'</label><div class="readonly">'.preg_quote(e($familyEmail), '~').'</div>~', $page) === 1,
   'the address is the first thing in the personal details, to read');
ok(!str_contains($page, e('Benutzername')) && !str_contains($page, e('Benutzernamen')), 'and no username anywhere on the page');
ok(!str_contains($page, e('Diese Adresse nutzen auch')), 'with no count of others on the address, which nobody shares');
$delete = $formWith($page, 'name="mode" value="delete"');
ok(str_contains($page, e(' Zur Bestätigung die E-Mail-Adresse eintippen: ').'<strong class="mono">'.e($familyEmail).'</strong></p>'),
   'deleting the access asks for the address, named outside the label');
$confirm = $tag($delete, 'confirmation');
ok(str_contains($confirm, 'type="email"') && str_contains($confirm, ' required'), 'in a required address box');
ok(str_contains($confirm, 'autocomplete="off"') && !str_contains($confirm, 'autocomplete="username"'), 'which the browser does not fill in, so the typing still means something');
ok(str_contains($confirm, 'autocapitalize="none"') && str_contains($confirm, 'spellcheck="false"'), 'and which takes an address the way the sign-in box does');
$paul = make_student(['first_name'=>'Paul', 'last_name'=>'Hofer', 'email'=>$familyEmail, 'account_id'=>null]);
sign_in_as($admin);
$page = render_view('student', ['id'=>$paul]);
ok(!str_contains($page, 'name="same_family"'), 'a child at somebody’s login address is offered no „Gleiche Familie“ (ADR 0020)');
ok(str_contains($page, e('Eigene E-Mail-Adresse eintragen')), 'and is asked for an address of their own before anybody taps');
is_same(1, deepest_form_nesting($page), 'every form on the card beside the record’s rather than inside it');

case_('An invited login’s card says until when the link works, and withdrawing it asks nothing typed');
$invitedLogin = make_account(['role'=>'student', 'name'=>'Mia Hofer', 'email'=>'mia@beispiel.test',
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
ok(str_contains($page, e('Eingeladen am '.fmt_datetime((string)$sent['created_at']).'; der Link gilt bis '.fmt_datetime((string)$sent['expires_at']).'.').'</p>'),
   'a live invitation says when it was sent and until when it works, and nothing about a username');
ok(preg_match('~<button class="button secondary" type="submit">'.preg_quote(e('Einladung erneut senden'), '~').'</button>~', $page) === 1,
   'with sending it again a quiet button while the link still works');
$withdraw = $formWith($page, 'name="mode" value="withdraw"');
ok($withdraw !== '' && str_contains($withdraw, 'name="id" value="'.$invitedLogin.'"'), 'withdrawing is offered, for this login');
ok(!str_contains($withdraw, 'name="confirmation"'), 'with nothing to type: nothing is lost, and inviting again is the way back');
ok(!str_contains($page, 'name="mode" value="delete"'), 'and no deletion asking for the address instead');
run("UPDATE auth_tokens SET expires_at=? WHERE account_id=? AND purpose='invite'", [gmdate('Y-m-d H:i:s', time() - 3600), $invitedLogin]);
ok(str_contains(render_view('student', ['id'=>$mia]), e('Die Einladung vom '.fmt_date((string)$sent['created_at']).' ist abgelaufen.')),
   'an expired invitation not yet pruned names the day it was sent');
$hold('student_save', 'student', $mia, ['email'=>$familyEmail, 'first_name'=>'Mia']);
ok(!str_contains(render_view('student', ['id'=>$mia]), 'name="same_family"'), 'and no „Gleiche Familie“ tick after a refusal either (ADR 0020)');
unset($GLOBALS['crm_held_input']);

case_('Zugänge names each login and its address, and no username');
/* The R10 flag for a staff login sharing an address cannot arise any more: the
   unique index refuses the pair (ADR 0020, §8). */
sign_in_as($admin);
$team = render_view('accounts');
$trainerEmail = (string)scalar('SELECT email FROM accounts WHERE id=?', [$trainer]);
ok(str_contains($team, '<strong>'.e('Trainerin Beispiel').'</strong><small>'.e($trainerEmail).'</small>'), 'each login shows its name and its address');
ok(!str_contains($team, '<p class="mono">'), 'and no username line');
ok(!str_contains($team, e('Ein Konto für Trainerin oder Administrator braucht eine eigene E-Mail-Adresse.')), 'with no sharing flag');
ok(str_contains($team, e('Zur Bestätigung die E-Mail-Adresse eintippen: ')), 'deleting a team login asks for the address too');
sign_out();

case_('Inviting by address is on the students list for staff, with the invitations still open');
/* Option 1 (ADR 0021, §3): the card only when asked for, the list whenever an
   invitation is open, and neither ever for a family. */
mail_ready(true);
sign_in_as($trainer);
$list = render_view('students');
ok(!str_contains($list, e(url('students', ['invite'=>1]).'#invite')) && str_contains(render_view('student_new'), e(url('students', ['invite'=>1, '#'=>'invite']))),
   'the heading offers one button, „+ Schüler anlegen"; the way to „Per E-Mail einladen" is step 1 of the wizard (Part 1)');
ok(!str_contains($list, 'id="invite"') && !str_contains($list, 'value="email_invite"'), 'the card is not there until asked for');
$card = render_view('students', ['invite'=>1]);
$inviteForm = $formOf($card, 'email_invite');
ok(str_contains($card, '<section class="card invite-card" id="invite">') && $inviteForm !== '', 'asked for, the card is there with its form');
$box = $tag($inviteForm, 'email');
ok(str_contains($box, 'type="email"') && str_contains($box, ' required') && str_contains($box, 'autocomplete="off"') && !str_contains($box, 'autocomplete="username"'),
   'an address box her iPhone does not fill with her own address');
ok(str_contains($inviteForm, 'name="locale"') && str_contains($inviteForm, e('Einladung senden')), 'with the invitation’s language and the button');
is_same(1, deepest_form_nesting($card), 'and no form inside another');
// The invitations by address: the one above, never set up and with no
// student, is open; one more, whose link has expired.
$expiredInvite = make_account(['role'=>'student', 'name'=>'', 'email'=>'abgelaufen@beispiel.test', 'state'=>'invited',
                               'verified_at'=>null, 'password_hash'=>null]);
make_token($expiredInvite, 'invite');
run("UPDATE auth_tokens SET expires_at=? WHERE account_id=?", [gmdate('Y-m-d H:i:s', time() - 3600), $expiredInvite]);
$open = open_invitations();
$list = render_view('students');
ok(str_contains($list, 'id="invitations"') && !str_contains($list, 'id="invitations" open'), 'the open invitations are listed, folded away');
ok(str_contains($list, e('Offene Einladungen').'</span><span class="badge amber">'.count($open).'</span>'), 'with how many there are');
foreach ($open as $a) ok(str_contains($list, '<strong>'.e($a['email']).'</strong>'), 'naming each address: '.$a['email']);
ok(str_contains($list, e('Der Link ist abgelaufen.')) && str_contains($list, e('Abgelaufen')), 'an expired link says so');
$expiredRow = $formWith($list, 'name="id" value="'.$expiredInvite.'"');
ok(str_contains($expiredRow, 'value="reinvite"') && str_contains($expiredRow, '<button class="button primary"'), 'and sending it again is the button that stands out');
$withdraw = $formWith($list, 'name="mode" value="withdraw"');
ok($withdraw !== '' && !str_contains($withdraw, 'name="confirmation"'), 'withdrawing asks nothing typed');
ok(str_contains(render_view('students', ['invitations'=>1]), 'id="invitations" open'), 'just after sending, the list is open');
is_same(1, deepest_form_nesting($list), 'and every row’s forms sit side by side');
mail_ready(false);
$card = render_view('students', ['invite'=>1]);
ok(!str_contains($card, 'value="email_invite"') && str_contains($card, e('Einladen geht noch nicht')), 'with mail not ready the card says why instead of a form');
ok(!str_contains(render_view('students'), 'value="reinvite"'), 'and nothing is sent again that could only be refused');
mail_ready(true);
// A refusal comes back with what was typed; an address on a student without
// a login is shown with the way to that student.
$hold('email_invite', 'students', 0, ['email'=>'neu.mitadresse@example.test', 'locale'=>'en']);
$card = render_view('students');
ok(str_contains($card, 'id="invite"') && str_contains($tag($formOf($card, 'email_invite'), 'email'), 'value="neu.mitadresse@example.test"'),
   'a refused invitation comes back open, with the address typed');
ok(str_contains($card, e('Diese Adresse steht schon bei einem Schüler')) && str_contains($card, e(url('student', ['id'=>$addressed])).'#access"'),
   'and an address a student without a login carries leads to that student’s access card');
unset($GLOBALS['crm_held_input']);
foreach ([$admin, $family] as $who) {
    sign_in_as($who);
    $seen = render_view('students', ['invite'=>1, 'invitations'=>1]);
    $staffSees = $who === $admin;
    is_same($staffSees, str_contains($seen, 'id="invite"'), ($staffSees ? 'an administrator' : 'a family').($staffSees ? ' sees' : ' never sees').' the invite card');
    is_same($staffSees, str_contains($seen, 'id="invitations"'), ($staffSees ? 'an administrator' : 'a family').($staffSees ? ' sees' : ' never sees').' the open invitations');
    is_same($staffSees, str_contains($seen, 'abgelaufen@beispiel.test'), 'nor any invited address'.($staffSees ? ', unlike staff' : ''));
}
run('DELETE FROM accounts WHERE id IN (?,?)', [$expiredInvite, $byAddress]);

case_('A family with no course is told when none has a free place, and how to reach the trainer');
$oleLogin = make_account(['role'=>'student', 'name'=>'Ole Neu']);
$ole = make_student(['first_name'=>'Ole', 'last_name'=>'Neu', 'account_id'=>$oleLogin]);
$full = make_class(['name'=>'Voller Kurs', 'capacity'=>1]);
make_enrolment($full, $lena, ['tariff_id'=>null]);
$running = array_map('intval', array_column(rows('SELECT id FROM classes WHERE archived=0 AND id<>?', [$full]), 'id'));
foreach ($running as $courseId) run('UPDATE classes SET archived=1 WHERE id=?', [$courseId]);
sign_in_as($oleLogin);
$courses = render_view('student', ['id'=>$ole, 'tab'=>'classes']);
$notice = '<strong>'.e('Gerade ist kein Kurs frei').'</strong>';
$card = strpos($courses, 'id="courses"');
ok(str_contains($courses, $notice), 'the Kurse tab says no course is free');
ok($card !== false && strpos($courses, $notice, $card) < strpos($courses, '</section>', $card), 'inside the Kurse card, where „Kurs wählen" leads');
ok(str_contains($courses, e(url('messages', ['new'=>1]))), 'with the way to write to the trainer');
run('UPDATE classes SET capacity=0 WHERE id=?', [$full]);
ok(!str_contains(render_view('student', ['id'=>$ole, 'tab'=>'classes']), $notice), 'and says nothing of the kind once a place is free');
run('UPDATE classes SET capacity=1 WHERE id=?', [$full]);
sign_in_as($trainer);
ok(!str_contains(render_view('student', ['id'=>$ole, 'tab'=>'classes']), $notice), 'staff are not told to write to themselves');
foreach ($running as $courseId) run('UPDATE classes SET archived=0 WHERE id=?', [$courseId]);
run('DELETE FROM class_students WHERE class_id=?', [$full]);
run('UPDATE classes SET archived=1 WHERE id=?', [$full]);
sign_out();

// ---------------------------------------------------------------------------
// The screens of ADR 0020: families completing their own details, inviting in
// one step, the access card's states, and „Von Familien".
// ---------------------------------------------------------------------------

case_('A family completes its own details on the Profil tab, in the one student form');
mail_ready(true);
run("UPDATE students SET address='', phone='' WHERE id=?", [$lena]);
sign_in_as($family);
$profil = render_view('student', ['id'=>$lena]);
ok(str_contains($profil, '<h2>'.e('Noch zu ergänzen').'</h2>'), 'the family is shown what is still to fill in');
ok(str_contains($profil, e(url('student', ['id'=>$lena])).'#address"'), 'with a step leading to the address box');
ok(str_contains($profil, 'id="address"') && str_contains($profil, 'id="personal"'), 'and the boxes it leads to carry those anchors');
$studentForm = $formOf($profil, 'student_save');
ok(str_contains($studentForm, 'name="address"') && str_contains($studentForm, 'name="phone"'), 'the address and phone are in the student form, so one save carries them');
ok(str_contains($studentForm, e('Steht auf deinen Rechnungen.')), 'with the family’s own hint about invoices');
ok(!str_contains($studentForm, 'name="email"') && !str_contains($profil, 'id="access"'), 'and no address box or access card of the login’s');
ok(str_contains($studentForm, e('Angaben speichern')), 'the button says what it saves');
ok(!str_contains($profil, 'avatar_save') && !str_contains($studentForm, 'type="file"'),
   'and no picture in it: the child’s picture has a card and forms of its own (ADR 0031)');
is_same(1, deepest_form_nesting($profil), 'and no form is inside another');
ok(str_contains(render_view('dashboard'), '<h2>'.e('Noch zu ergänzen').'</h2>'), 'the overview shows the same card');

case_('Staff see their own list on the child’s page, not the family’s');
sign_in_as($trainer);
ok(!str_contains(render_view('student', ['id'=>$lena]), '<h2>'.e('Noch zu ergänzen').'</h2>'), 'her page keeps her own list');

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

case_('The wizard invites by e-mail in one step when mail can go out, and says why not when it cannot');
sign_in_as($admin);
$wizardDraft = (string)act('student_draft', ['first_name'=>'Lea', 'last_name'=>'Neu', 'birth_date'=>'', 'course'=>'none', 'status'=>'active'])[1]['draft'];
$step2 = render_view('student_new', ['draft'=>$wizardDraft]);
ok(str_contains($step2, 'name="method" value="email"') && str_contains($step2, e('Empfohlen')), '„Per E-Mail einladen“ is offered, marked „Empfohlen“ (ADR 0023 §5)');
// An order holds only between two that are there: false < 1 is true in PHP.
$byEmail = strpos($step2, 'id="by-email"'); $later = strpos($step2, 'id="later"');
ok($byEmail !== false && $later !== false && $byEmail < $later && !str_contains($step2, 'id="by-username"') && !str_contains($step2, 'name="username"'),
   'first of the two, before „Ohne Anmeldung“, with no username card (ADR 0030 §6)');
mail_ready(false);
$step2 = render_view('student_new', ['draft'=>$wizardDraft]);
ok(!str_contains($step2, 'name="method" value="email"'), 'with mail not ready there is no e-mail form');
ok(str_contains($step2, e('Einladen geht noch nicht')) && str_contains($step2, e(url('start'))), 'and an administrator is shown what is missing, and the way to the setup');
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
$waiting = make_account(['role'=>'trainer', 'name'=>'Neue Trainerin', 'state'=>'invited', 'verified_at'=>null, 'password_hash'=>null]);
ok(!str_contains($team, 'value="account_invite"') && str_contains($team, e('Einladen geht noch nicht')), 'with mail not ready the invite card says why instead');
ok(!str_contains(render_view('accounts'), 'value="reinvite"'), 'and no invitation is offered again that could only be refused');
mail_ready(true);
ok(str_contains(render_view('accounts'), 'value="reinvite"'), 'which it is once mail can go out');
run('DELETE FROM accounts WHERE id=?', [$waiting]);
mail_ready(false);
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
