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
                       'interval_months'=>1, 'discount_months'=>1, 'discount_value'=>100]);
$lena = make_student(['first_name'=>'Lena', 'last_name'=>'Hofer', 'account_id'=>$family,
                      'birth_date'=>'2015-04-02', 'joined_on'=>'2026-01-01']);
$tobi = make_student(['first_name'=>'Tobias', 'last_name'=>'Hofer', 'account_id'=>$family,
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
// An invoice needs the operator's own details; the invoices suite checks those
// rules, this one only needs a document to exist so the pages have one to show.
foreach (['org_name'=>'Badminton Beispiel', 'org_street'=>'Hauptstraße 1', 'org_zip'=>'1010',
          'org_city'=>'Wien', 'org_country'=>'Österreich', 'org_email'=>'buero@beispiel.test'] as $k => $v)
    set_setting($k, $v);
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
    'outbox'     => [[], ['p'=>1]],
    'compose'    => [[], ['course'=>$course]],
    'manage'     => [[], ['tab'=>'levels'], ['tab'=>'ages'], ['tab'=>'members'], ['tab'=>'tariffs'],
                     ['tab'=>'templates'], ['tab'=>'payments']],
];
$adminPages = [
    'settings'   => [[], ['tab'=>'portal'], ['tab'=>'organisation'], ['tab'=>'fields'], ['tab'=>'smtp'],
                     ['tab'=>'privacy'], ['tab'=>'feedback'], ['tab'=>'system']],
    'history'    => [[], ['entity'=>'students','id'=>$lena]],
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
