<?php
/**
 * Any value from anyone (ADR 0026 §5).
 *
 * "we need this software to be robust, avoid errors and code as much as needed to
 * make sure stuff run as expected, expect all kinds of unexpected behaviours and
 * values by users and trainers" - the owner, 2026-10-07.
 *
 * Every action the dispatchers name is posted as every role with what nobody meant
 * to type, and every page is drawn with it in the address. What comes back has to
 * be a page the router knows, or one plain sentence on the way back to the form:
 * never an exception, a PHP warning or a database error. Nothing a family sends may
 * change another family's rows, and a form sent twice does its work once.
 *
 * Nothing here lists actions, fields or pages. They are read from the code - the
 * dispatchers' cases, the keys app/ reads from a request, the keys the views read
 * from the address, the router's lists - and the forms are the ones the pages draw,
 * filled in the way a person would. An action added tomorrow is tested tomorrow.
 * The one list kept by hand is the actions that change who is signed in.
 *
 * In process, through submit(), so the token, the throttles and the request-id
 * claim run as in the browser: each post inside a transaction rolled back
 * afterwards, the rate limits emptied first, and after a refusal what the router
 * does with one - remember_input() and form_return(). Signed out, and for the
 * pages that are not views, through the real router in a php -S of the run's own:
 * in process, require_user()'s redirect would end the run, and PHP on the command
 * line keeps no headers, so where a redirect leads could not be read.
 *
 * A failure names the cause once, the actions and roles that share it, and the
 * first input that showed it.
 */

/** What this suite read from the code and built, shared with its functions. */
function robust(): stdClass { static $r; return $r ??= new stdClass(); }

/** Thrown to undo whatever one probe wrote. */
final class RobustUndo extends Exception {}

$r = robust();
$r->found = []; $r->tried = []; $r->forms = []; $r->shapes = []; $r->drawn = []; $r->served = [];
$r->sentTwice = []; $r->notTwice = []; $r->shapes_drawn = [];
$r->reached = ['posts that did their work' => 0, 'pages drawn with unexpected values' => 0, 'requests to the real router' => 0];

// ---------------------------------------------------------------------------
// What there is to test, read from the code
// ---------------------------------------------------------------------------

case_('The actions, the fields, the address keys and the pages are read from the code');
$source = fn(string $pattern): string => implode("\n", array_map('file_get_contents', glob(APP_ROOT.$pattern)));
$actionFiles = glob(APP_ROOT.'/app/actions*.php');
$r->actions = [];
foreach ($actionFiles as $file)
    if (preg_match_all("/case '([a-z_]+)':/", (string)file_get_contents($file), $m))
        foreach ($m[1] as $action) $r->actions[$action] = basename($file);
ok(count($r->actions) >= 60, 'the dispatchers name at least 60 actions ('.count($r->actions).')');
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
preg_match('/function test_load_actions\(\).*?foreach \(\[([^\]]*)\]/s', (string)file_get_contents(TEST_ROOT.'/harness.php'), $m);
$loaded = array_map(fn($unit) => trim($unit, " '"), explode(',', $m[1] ?? ''));
foreach ($actionFiles as $file) {
    $unit = basename($file, '.php');
    ok(preg_match("~require\s+ROOT\s*\.\s*'/app/".preg_quote($unit, '~')."\.php'~", $router) === 1, 'public/index.php requires app/'.$unit.'.php');
    ok(in_array($unit, $loaded, true), 'test_load_actions() requires app/'.$unit.'.php');
}
preg_match_all("/start_form\('([a-z_]+)'/", $source('/views/*.php').$source('/app/*.php'), $m);
is_same([], array_values(array_diff(array_keys($r->actions), $m[1])), 'every action found is offered by a start_form()');
is_same([], array_values(array_diff(array_unique($m[1]), array_keys($r->actions))), 'and every start_form() offers an action found');

/* The keys the application reads from a request: the first argument of post(),
   required_text(), text_limit() and form_bookkeeping(), the field of reference_or_null(), every
   $_POST['…'] and $_FILES['…'] in app/ (ADR 0026 §5) - and, read the same way, the
   names the helpers that read a field under a name they are given build from it:
   posted_time() and posted_time_rows() add _h and _m, store_upload() reads
   $_FILES[name], and the settings form posts set_<key> and, for a list,
   <key>_keys and <key>_labels. */
$app = $source('/app/*.php');
$fields = [];
foreach (["/\b(?:post|required_text|text_limit|form_bookkeeping)\(\s*'([A-Za-z0-9_]+)'\s*[,)]/", "/\breference_or_null\(\s*'[a-z_]+'\s*,\s*'([A-Za-z0-9_]+)'/",
          "/\\\$_(?:POST|FILES)\[\s*'([A-Za-z0-9_]+)'\s*\]/", "/\bstore_upload\(\s*'([a-z_]+)'/"] as $pattern) {
    preg_match_all($pattern, $app, $m);
    $fields = array_merge($fields, $m[1]);
}
preg_match_all("/\bposted_time(?:_rows)?\(\s*'([a-z_]+)'/", $app, $m);
foreach ($m[1] as $name) array_push($fields, $name.'_h', $name.'_m');
foreach (setting_schema() as $key => $spec)
    array_push($fields, 'set_'.$key, ...(($spec['kind'] ?? '') === 'map' ? [$key.'_keys', $key.'_labels'] : []));
// The token, the request id and the action are what make a post a post; submit() sends them right.
$r->fields = array_values(array_diff(array_unique($fields), ['action', 'csrf', 'request_id']));
ok(count($r->fields) >= 100, 'the application reads at least 100 keys from a request ('.count($r->fields).')');

preg_match_all("/\\\$_GET\[\s*'([a-z_]+)'\s*\]/", $app.$source('/views/*.php').$router, $m);
$r->queryKeys = array_values(array_diff(array_unique($m[1]), ['page']));
ok(count($r->queryKeys) >= 20, 'the pages read at least 20 keys from the address ('.count($r->queryKeys).')');

$list = function (string $pattern) use ($router): array {
    preg_match($pattern, $router, $found);
    return array_values(array_filter(array_map(fn($p) => trim($p, " '"), explode(',', $found[1] ?? ''))));
};
$r->pages       = $list("/\\\$allowed=\[([^\]]*)\]/");
$r->publicPages = $list("/\\\$public=in_array\(\\\$page,\[([^\]]*)\]/");
$r->staffPages  = $list("/in_array\(\\\$page,\[([^\]]*)\],true\)\)require_staff/");
$r->adminPages  = $list("/in_array\(\\\$page,\[([^\]]*)\],true\)\)require_admin/");
ok($r->pages && $r->publicPages && $r->staffPages && $r->adminPages, 'the router’s four lists of pages were found');

/* The actions that count a throttle, in their own case or in handle_post()
   before it: the rate limits are emptied before each of their posts (ADR 0026
   §5), and only theirs, because nothing else writes them. */
$r->throttled = [];
foreach ($actionFiles as $file)
    foreach (preg_split("/(?=case '[a-z_]+':)/", (string)file_get_contents($file)) as $part)
        if (preg_match("/^case '([a-z_]+)':/", $part, $m) && str_contains($part, 'throttle')) $r->throttled[] = $m[1];
preg_match('/function handle_post\(\).*?\n}/s', (string)file_get_contents(APP_ROOT.'/app/actions.php'), $m);
preg_match_all("/'([a-z_]+)'/", $m[0] ?? '', $m);
$r->throttled = array_values(array_unique([...$r->throttled, ...array_intersect($m[1], array_keys($r->actions))]));
ok(count($r->throttled) >= 10, 'the actions that count a throttle are found ('.implode(', ', $r->throttled).')');

/* The one list kept by hand (ADR 0026 §5): the actions whose success changes who
   is signed in. After one of them the role is signed in again. Any other action
   that changes it fails below, so a sixth needs its reason written beside it. */
$r->signsInOrOut = ['login', 'logout', 'activate', 'impersonate', 'password_change'];
foreach ($r->signsInOrOut as $action) ok(isset($r->actions[$action]), $action.', listed as changing who is signed in, is still an action');

// ---------------------------------------------------------------------------
// The values (ADR 0026 §5, „With what")
// ---------------------------------------------------------------------------

$r->own   = ['child' => 1101, 'contact' => 1201, 'absence' => 1301, 'charge' => 1401, 'payment' => 1501,
             'invoice' => 1601, 'receipt' => 1701, 'chat' => 1801, 'message' => 1901, 'login' => 1001];
$r->other = ['child' => 2101, 'contact' => 2201, 'absence' => 2301, 'charge' => 2401, 'payment' => 2501,
             'invoice' => 2601, 'receipt' => 2701, 'chat' => 2801, 'message' => 2901, 'login' => 2001];
$r->missing = 987654321;
// Every row of the other family carries this name, so a page or a sentence that
// shows one of them to the family is found by reading it.
$r->marker = 'Wolkenstein';
$numbers = ['-1', '0', '2147483648', '99999999999999999999', '1.5', '1e9', ' 7 '];
$dates = ['2026-02-30', '0000-00-00', '9999-12-31', '31.12.2026'];
$r->classes = [
    1 => ['nothing but the token and the request id' => null],
    2 => ['an array' => ['x'], 'an array of arrays' => [['x']]],
    3 => ['100,000 characters' => str_repeat('a', 100000), '4,000 multibyte characters' => str_repeat('ü', 4000)],
    4 => array_combine(array_map(fn($n) => '„'.$n.'“', $numbers), $numbers),
    5 => array_combine(array_map(fn($d) => '„'.$d.'“', $dates), $dates),
    6 => ['an empty string' => '', 'spaces' => '   ', '„<script>“' => '<script>', 'a quote and --' => "' --",
          'a NUL byte' => "\0", 'invalid UTF-8' => "\xC3\x28", 'an emoji' => '🏸', 'a line break and Bcc:' => "\r\nBcc: robust@example.test"],
    7 => array_combine(array_map(fn($kind) => 'the other family’s '.$kind, array_keys($r->other)), array_map('strval', $r->other))
         + ['an id that does not exist' => (string)$r->missing],
];
// One field changed at a time on a form: left out, or a value of classes 2 to 6 -
// those that can reach a different line than their neighbours in the class.
$r->mutations = ['left out' => null] + $r->classes[2] + array_slice($r->classes[3], 0, 1)
    + array_intersect_key($r->classes[4], array_flip(['„-1“', '„2147483648“', '„99999999999999999999“', '„1.5“']))
    + array_intersect_key($r->classes[5], array_flip(['„2026-02-30“', '„9999-12-31“']))
    + array_intersect_key($r->classes[6], array_flip(['an empty string', 'a NUL byte', 'invalid UTF-8', 'an emoji', 'a line break and Bcc:']));
// In the address: classes 2, 3, 4 and 7 (ADR 0026 §5, „The pages too").
$r->inAddress = $r->classes[2] + $r->classes[3] + $r->classes[4];
$r->seed = (string)(getenv('CRM_ROBUSTNESS_SEED') ?: '2026');

// ---------------------------------------------------------------------------
// The portal: two families, a trainer and an administrator
// ---------------------------------------------------------------------------

/* Every id is fixed and every kind of row has its own hundred, so a value on a
   form says what it is: 1401 is the family's charge and 2401 the other family's.
   The family's child and course sort first in every list, so the pages a link
   reaches first - the ones the forms are taken from - are the fullest.
   The passwords are bcrypt at cost 4: at PHP 8.4's default of 12, every refused
   password_change and email_change costs a quarter of a second, and the suite
   posts them hundreds of times. What is checked is unchanged. */
$r->password = 'Robust-Probe-Passwort-2026';
$hash = password_hash($r->password, PASSWORD_BCRYPT, ['cost' => 4]);
set_setting('sign_in_dummy_hash', password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 4]));
$login = fn(int $id, string $role, string $name, ?string $email, string $state = 'active') => fixture('accounts', [
    'id' => $id, 'name' => $name, 'email' => $email, 'password_hash' => $state === 'placeholder' ? null : $hash, 'role' => $role,
    'state' => $state, 'verified_at' => $state === 'active' ? now() : null, 'locale' => 'de', 'theme' => 'auto',
    'text_scale' => 'normal', 'auth_version' => 1, 'notifications' => 1, 'payment_notices' => 1, 'created_at' => now()]);
$child = fn(int $id, int $account, string $first, string $last, string $email) => fixture('students', [
    'id' => $id, 'account_id' => $account, 'first_name' => $first, 'last_name' => $last, 'email' => $email,
    'status' => 'active', 'joined_on' => '2025-09-01', 'birth_date' => '2015-04-12', 'revision' => 1,
    'created_at' => now(), 'updated_at' => now()]);
$charge = fn(int $id, int $student, int $class, string $label, string $due) => fixture('charges', [
    'id' => $id, 'student_id' => $student, 'class_id' => $class, 'label' => $label, 'amount_cents' => 4500, 'gross_cents' => 4500,
    'discount_cents' => 0, 'discount_note' => '', 'due_on' => $due, 'overdue_on' => date('Y-m-d', strtotime($due.' +7 days')),
    'cancelled' => 0, 'created_at' => now()]);

$admin = 11; $trainer = 12; $o = $r->other; $w = $r->own;
$login($admin, 'admin', 'Anna Admin', 'admin@example.test');
$login($trainer, 'trainer', 'Tina Trainerin', 'trainerin@example.test');
$login($w['login'], 'student', 'Lena Hofer', 'lena@example.test');
$login($o['login'], 'student', 'Otto Wolkenstein', 'otto@example.test');
$login(1003, 'student', 'Mia Zauner', null, 'placeholder');
$login(1004, 'student', 'Jonas Ziegler', 'jonas@example.test', 'invited');
$child($w['child'], $w['login'], 'Lena', 'Hofer', 'lena@example.test');
$child($o['child'], $o['login'], 'Otto', 'Wolkenstein', 'otto@example.test');
$child(1103, 1003, 'Mia', 'Zauner', '');
$child(1104, 1004, 'Jonas', 'Ziegler', 'jonas@example.test');

set_setting('org_name', 'TSV Beispiel');
set_setting('org_street', 'Hallenweg 1');
set_setting('org_city', '1010 Wien');
run('UPDATE payment_profiles SET iban=?, recipient=? WHERE id=?', ['AT611904300234573201', 'TSV Beispiel', (int)setting('default_payment_profile')]);
mail_ready(true);
// Released, as mail_ready() says it is, so the notice's own form works as drawn.
set_setting('privacy_de', str_repeat('Der Verein verarbeitet die Angaben der Familien nur für das Training und die Beiträge. ', 5));
set_setting('privacy_en', str_repeat('The club processes the families’ details only for the training and the fees. ', 5));
// Mail goes nowhere: the SMTP test is refused on the spot instead of waiting ten seconds for a host.
set_setting('smtp', ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'tls', 'username' => '', 'password' => '',
                     'from_email' => 'portal@example.test', 'from_name' => 'TSV Beispiel']);

make_class(['id' => 501, 'name' => 'Kindertraining', 'trainer_id' => $trainer]);
make_class(['id' => 502, 'name' => 'Zirkeltraining', 'trainer_id' => $trainer]);
make_tariff(['id' => 601, 'class_id' => 501, 'name' => 'Monatlich']);
make_tariff(['id' => 602, 'class_id' => 502, 'name' => 'Zirkel monatlich']);
make_enrolment(501, $w['child'], ['tariff_id' => 601]);
make_enrolment(501, 1103, ['tariff_id' => 601]);
make_enrolment(502, $o['child'], ['tariff_id' => 602]);
fixture('contacts', ['id' => 1201, 'student_id' => $w['child'], 'owner_name' => 'Maria Hofer', 'relation_label' => 'Mutter', 'phone' => '0664 1234567', 'email' => '', 'is_primary' => 1]);
fixture('contacts', ['id' => 1202, 'student_id' => $w['child'], 'owner_name' => 'Peter Hofer', 'relation_label' => 'Vater', 'phone' => '0664 7654321', 'email' => '', 'is_primary' => 0]);
fixture('contacts', ['id' => $o['contact'], 'student_id' => $o['child'], 'owner_name' => 'Oma Wolkenstein', 'relation_label' => 'Oma', 'phone' => '0664 1111111', 'email' => '', 'is_primary' => 1]);
fixture('absences', ['id' => $w['absence'], 'student_id' => $w['child'], 'reason' => 'sick', 'starts_on' => today(), 'ends_on' => today(), 'created_by' => $w['login']]);
fixture('absences', ['id' => $o['absence'], 'student_id' => $o['child'], 'reason' => 'sick', 'starts_on' => today(), 'ends_on' => today(), 'created_by' => $o['login']]);
$charge($w['charge'], $w['child'], 501, 'Beitrag September', date('Y-m-01', strtotime('-1 month')));
$charge(1402, $w['child'], 501, 'Beitrag Oktober', date('Y-m-d', strtotime('-20 days')));
$charge($o['charge'], $o['child'], 502, 'Beitrag Wolkenstein', date('Y-m-01', strtotime('-1 month')));
fixture('payments', ['id' => $w['payment'], 'charge_id' => $w['charge'], 'amount_cents' => 4500, 'paid_on' => today(), 'method' => 'Überweisung', 'note' => '', 'confirmed_by' => null, 'confirmed_at' => null]);
fixture('payments', ['id' => $o['payment'], 'charge_id' => $o['charge'], 'amount_cents' => 4500, 'paid_on' => today(), 'method' => 'Überweisung', 'note' => 'Wolkenstein', 'confirmed_by' => null, 'confirmed_at' => null]);
sign_in_as($admin);
foreach ([$w, $o] as $family) {
    db()->exec('ALTER TABLE invoices AUTO_INCREMENT = '.(int)$family['invoice']);
    transactional(fn() => create_invoice($family['child'], [$family['charge']]));
}
$receipt = fn(int $id, int $charge, int $student, string $name, int $by) => fixture('payment_proofs', [
    'id' => $id, 'charge_id' => $charge, 'student_id' => $student, 'stored_name' => 'robust-'.$id.'.pdf', 'original_name' => $name,
    'mime' => 'application/pdf', 'bytes' => 10, 'note' => '', 'uploaded_by' => $by, 'created_at' => now()]);
$receipt($w['receipt'], 1402, $w['child'], 'beleg.pdf', $w['login']);
$receipt($o['receipt'], $o['charge'], $o['child'], 'beleg-wolkenstein.pdf', $o['login']);
make_thread([$w['login'], $trainer], ['id' => $w['chat'], 'kind' => 'staff_direct', 'account_id' => $w['login'], 'subject' => '']);
make_thread([$o['login'], $trainer], ['id' => $o['chat'], 'kind' => 'staff_direct', 'account_id' => $o['login'], 'subject' => '']);
fixture('messages', ['id' => $w['message'], 'thread_id' => $w['chat'], 'sender_id' => $w['login'], 'body' => 'Lena ist heute krank.', 'created_at' => now()]);
fixture('messages', ['id' => $o['message'], 'thread_id' => $o['chat'], 'sender_id' => $o['login'], 'body' => 'Grüße von Familie Wolkenstein', 'created_at' => now()]);
transactional(function () use ($trainer, $w) {
    foreach ([501, 502] as $class) course_group_thread($class);
    run('INSERT INTO messages (id,thread_id,sender_id,body,created_at) VALUES (1911,?,?,?,?)', [course_group_thread(501), $trainer, 'Willkommen in der Gruppe!', now()]);
    notify($w['login'], 'message', 'Neue Nachricht', 'Hallo', 'messages', ['id' => $w['chat']]);
    queue_mail($w['login'], 'lena@example.test', 'Offener Beitrag', 'Bitte überweisen.', 'payment');
    run("UPDATE mail_jobs SET status='failed', attempts=3, error='refused' ORDER BY id DESC LIMIT 1");
});
sign_in_as($w['login']);
transactional(fn() => request_enrolment($w['child'], 502, 'join', 602, ''));
fixture('news', ['title' => 'Hallenzeiten im Oktober', 'body' => 'Ab Montag in der kleinen Halle.', 'published' => 1, 'created_at' => now(), 'updated_at' => now()]);
fixture('feedback', ['account_id' => $w['login'], 'page' => 'dashboard', 'message' => 'Der Knopf reagiert nicht.', 'context_json' => '{}', 'state' => 'new', 'created_at' => now()]);
// Saved views and custom fields leave with ADR 0026 §7 and §8, their tables with them.
if (test_has_table('saved_filters')) fixture('saved_filters', ['name' => 'Aktive', 'criteria_json' => '{"status":"active"}']);
if (test_has_table('field_definitions'))
    fixture('field_definitions', ['label' => 'T-Shirt-Größe', 'field_type' => 'select', 'options_json' => '["S","M","L"]', 'default_json' => 'null', 'visibility' => 'edit']);

$r->families = ['family', 'administrator viewing as the family'];
$r->ids = ['family' => $w['login'], 'trainer' => $trainer, 'administrator' => $admin, 'administrator viewing as the family' => $w['login']];
$r->roles = [];
foreach ($r->ids as $role => $id) $r->roles[$role] = robust_session_now($role);
$r->otherRows = robust_other_rows();
// Every setting read once, as it is now, so a probe finds them in the memo it is
// handed rather than asking the database for each.
setting_cache_clear();
foreach ([...array_keys(setting_schema()), ...array_column(rows('SELECT setting_key FROM settings'), 'setting_key')] as $key) setting($key);
ok(substr_count($r->otherRows, $r->marker) >= 5, 'the other family’s rows carry their name, so a page that shows one is found');

// A stray exit - go() is one - ends the run looking green, with every check after it
// never made. The run says where it was and fails instead.
$GLOBALS['robust_inside'] = null;
register_shutdown_function(static function (): void {
    robust_server_stop();
    if (($inside = $GLOBALS['robust_inside'] ?? null) === null) return;
    while (ob_get_level()) ob_end_clean();
    echo "\n\nrobustness: the run ended inside ".$inside.". Something called exit - a redirect, most likely - in the middle of it, and nothing after it was checked.\n";
    exit(1);
});

// ---------------------------------------------------------------------------
// Every page as each role, once as linked: the forms are collected on the way
// ---------------------------------------------------------------------------

foreach (array_keys($r->roles) as $role) robust_crawl($role);
foreach (array_keys($r->roles) as $role)
    ok(count($r->forms[$role] ?? []) >= ($role === 'administrator' ? 40 : 8), $role.': the pages draw the forms the role uses ('.count($r->forms[$role] ?? []).')');

// ---------------------------------------------------------------------------
// The forms the pages draw: twice, with another family's ids, one value changed
// ---------------------------------------------------------------------------

foreach (array_keys($r->roles) as $role) {
    // The list grows while it is walked: a form sent once can lead to a page with
    // a form no link reaches, as step 1 of „Schüler anlegen" leads to step 2.
    for ($i = 0; $i < count($r->forms[$role]); $i++) $r->forms[$role][$i]['worked'] = robust_twice($role, $r->forms[$role][$i]);
    if (in_array($role, $r->families, true)) foreach ($r->forms[$role] as $form) robust_swap_ids($role, $form);
    /* One value changed at a time, as the family and as the administrator, who sees
       every form a trainer does; a form the family sees with the same fields as
       staff, once, as the family. Only on a form that works as drawn: behind one
       that is refused as drawn, every change meets the same refusal. */
    if (in_array($role, ['family', 'administrator'], true)) {
        $familySees = array_map(fn($form) => array_keys($form['post']), robust_first_forms('family'));
        foreach (robust_first_forms($role) as $action => $form)
            if (!empty($form['worked']) && ($role === 'family' || ($familySees[$action] ?? null) != array_keys($form['post'])))
                robust_mutate($role, $form);
    }
}

// ---------------------------------------------------------------------------
// Every action, as every role, with every class in every field at once
// ---------------------------------------------------------------------------

/* Every value where the role can get past the question of who it is - an action
   its own pages offer it, or one no page offers anybody - and one of each class
   where only another role's pages offer the action: enough to show it refuses
   this role in one plain sentence, whatever is posted. Where two roles run the
   same code, one of them is posted every value: the trainer, not the
   administrator, for what both are offered, and the family, not the
   administrator viewing as the family. */
$offered = [];
foreach ($r->forms as $role => $forms) foreach ($forms as $form) $offered[$role][$form['post']['action']] = true;
$offeredAnywhere = array_merge(...array_values($offered));
foreach (array_keys($r->roles) as $role) {
    $bases = array_map(fn($form) => $form['post'], robust_first_forms($role));
    foreach (array_keys($r->actions) as $action) {
        $every = match ($role) {
            'administrator viewing as the family' => false,
            'administrator' => !isset($offered['trainer'][$action]) && (isset($offered[$role][$action]) || !isset($offeredAnywhere[$action])),
            default => isset($offered[$role][$action]) || !isset($offeredAnywhere[$action]),
        };
        foreach ($r->classes as $class => $values)
            foreach ($every ? $values : array_slice($values, 0, 1) as $label => $value)
                robust_probe('values', $role, $action, $value === null ? [] : array_fill_keys($r->fields, $value), 'class '.$class.', '.$label.($value === null ? '' : ' in every field'));
        // Then each field drawn from every class, and from the form where the page draws one.
        for ($run = 1; $run <= 3; $run++) {
            $seed = crc32($r->seed.'|'.$role.'|'.$action.'|'.$run);
            robust_probe('values', $role, $action, robust_drawn_fields($seed, $bases[$action] ?? []),
                         'seeded run '.$run.' (seed '.$seed.', CRM_ROBUSTNESS_SEED='.$r->seed.')');
        }
    }
}

// ---------------------------------------------------------------------------
// Every page with unexpected values in its address
// ---------------------------------------------------------------------------

/* Each page as linked, with each value in every key it was not linked with, and
   then in each key it was linked with, one at a time. As the family and as the
   administrator, every value on every address a link leads to - a tab, a record
   being edited, a day - because some keys are read only there: the first address
   of each page whole, through render_page(), so the frame around it reads every
   value too, and the others through render_view(), the frame being the same on
   every page. As the trainer and as the administrator viewing the family, who
   see those same views, one value of each class on the first address of each.
   Of class 7 staff get the other family's child, which they may open, and an id
   that does not exist; a family gets every one, none of which it may open. */
foreach (array_keys($r->roles) as $role) {
    $family = in_array($role, $r->families, true);
    $deep = in_array($role, ['family', 'administrator'], true);
    $ids = $family ? $r->classes[7] : array_intersect_key($r->classes[7], array_flip(['the other family’s child', 'an id that does not exist']));
    $values = $deep ? $r->inAddress + $ids
                    : array_slice($r->classes[2], 0, 1) + array_slice($r->classes[3], 0, 1) + array_slice($r->classes[4], 2, 1) + array_slice($ids, 0, 1);
    $seenPage = [];
    foreach ($r->drawn[$role] as [$page, $query]) {
        $whole = !isset($seenPage[$page]);
        $seenPage[$page] = true;
        if (!$whole && !$deep) continue;
        foreach ($values as $label => $value) {
            robust_look($role, $page, $query + array_fill_keys(array_diff($r->queryKeys, array_keys($query)), $value), 'as linked, '.$label.' in every other key', $whole);
            if ($whole) foreach (array_keys($query) as $key) robust_look($role, $page, [$key => $value] + $query, 'as linked, '.$label.' in '.$key);
        }
    }
}

// ---------------------------------------------------------------------------
// Signed out, and the pages that are not views, through the real router
// ---------------------------------------------------------------------------

$why = robust_server_start();
if ($why !== '') {
    test_unsupported(array_merge(test_unsupported(), ['robustness: signed-out posts and the pages that are not views ('.$why.')']));
} else {
    $background = setting('auto_background');
    set_setting('auto_background', false);   // the work after a page view would send the queued mail
    try {
        // Signed out, each action gets classes 1 and 2 only (ADR 0026 §5).
        foreach (array_keys($r->actions) as $action)
            foreach ($r->classes[1] + $r->classes[2] as $label => $value)
                robust_outside('signed out', $action, [], $value === null ? [] : array_fill_keys($r->fields, $value), $label.' in every field');
        /* Every page signed out: the public ones with every value of classes 2, 4
           and 7 in every key, the others - which send a stranger to the sign-in
           page before they read anything - with class 2. A hundred thousand
           characters are left out of an address here: a web server refuses one
           that long before PHP sees it. The download, which is no view, as every
           role: as linked, and with each value in each key. */
        $short = $r->classes[2] + $r->classes[4] + $r->classes[7];
        foreach ($r->pages as $page)
            foreach (in_array($page, $r->publicPages, true) ? $short : $r->classes[2] as $label => $value)
                robust_outside('signed out', $page, ['page' => $page] + array_fill_keys($r->queryKeys, $value), null, $label.' in every key');
        foreach (array_keys($r->roles) as $role)
            foreach ([['download', []], ...$r->served[$role]] as [$page, $query])
                foreach ($role === 'family' ? $short : array_slice($short, 0, 3) + array_slice($r->classes[7], 0, 1) as $label => $value) {
                    robust_outside($role, $page, ['page' => $page] + $query + array_fill_keys(array_diff($r->queryKeys, array_keys($query)), $value), null, 'as linked, '.$label.' in every other key');
                    foreach (array_keys($query) as $key) robust_outside($role, $page, ['page' => $page, $key => $value] + $query, null, 'as linked, '.$label.' in '.$key);
                }
    } finally {
        robust_server_stop();
        set_setting('auto_background', $background);
    }
}

// ---------------------------------------------------------------------------
// The installer, an entry point of its own
// ---------------------------------------------------------------------------

/* public/setup.php does not go through public/index.php, whose first line drops
   every list from the address, so its own reads are its only guard. The keys it
   reads from the address are read from it, as above, and each is sent every value
   of classes 2 and 4, in a process of its own - the way tests/setup-request.php
   asks the page - with no configuration: the first page a new host shows. */
case_('The installer reads any value in its address without a warning');
preg_match_all("/\\\$_GET\[\s*'([a-z_]+)'\s*\]/", (string)file_get_contents(APP_ROOT.'/public/setup.php'), $m);
$setupKeys = array_values(array_unique($m[1]));
ok($setupKeys !== [], 'the keys public/setup.php reads from the address were found ('.implode(', ', $setupKeys).')');
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(), ['robustness: the installer’s address (this PHP disables exec)']));
} else {
    foreach ($r->classes[2] + $r->classes[4] as $label => $value) {
        $file = test_run_dir().'/robust-setup-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($file, json_encode(['method' => 'GET', 'host' => '127.0.0.1', 'https' => false, 'headers' => [], 'post' => [],
                                              'cookie' => [], 'query' => array_fill_keys($setupKeys, $value)]));
        $out = [];
        exec('CRM_CONFIG='.escapeshellarg(test_run_dir().'/robust-no-config.php').' '.escapeshellarg(PHP_BINARY)
             .' '.escapeshellarg(TEST_ROOT.'/setup-request.php').' '.escapeshellarg($file).' 2>&1', $out, $code);
        @unlink($file);
        $answer = json_decode((string)end($out), true);
        $log = implode("\n", array_slice($out, 0, -1));
        ok(is_array($answer) && $answer['status'] < 500 && $log === '' && !preg_match('/\b(?:Warning|Notice|Deprecated)\b/', (string)$answer['body']),
           'public/setup.php with '.$label.' in '.implode(', ', $setupKeys).($log !== '' ? ' - '.robust_show($log, 160) : ''));
    }
}

// ---------------------------------------------------------------------------
// What was found
// ---------------------------------------------------------------------------

robust_report('values', 'Every action, as every role, with every class of value in every field at once, and seeded');
robust_report('forms', 'The forms the pages draw, filled in as a person would, then with one value changed at a time');
robust_report('twice', 'A form sent twice does its work once, and the second copy hears only that');
robust_report('ids', 'A family’s forms with the other family’s ids change nothing of theirs');
robust_report('pages', 'Every page, as every role, with unexpected values in its address');
robust_report('outside', 'Signed out, and the pages that are not views, through the real router');

case_('What a run covered');
$twice = count($r->sentTwice);
ok($twice >= 60, 'a form was sent twice for '.$twice.' actions and roles');
// A run that reached nothing would report nothing, and look the same as one that found nothing.
foreach (['posts that did their work' => 500, 'pages drawn with unexpected values' => 500, 'requests to the real router' => 300] as $what => $least)
    if ($what !== 'requests to the real router' || $why === '')   // without the server, that is said under „Not covered"
        ok($r->reached[$what] >= $least, $what.': '.$r->reached[$what].' (at least '.$least.')');
$noForm = array_keys(array_diff_key($r->actions, ...array_map(fn($forms) => array_flip(array_map(fn($f) => $f['post']['action'], $forms)), array_values($r->forms))));
if ($noForm)
    test_unsupported(array_merge(test_unsupported(), ['robustness: no page draws a form for '.robust_list($noForm, 40)
        .'; these get every class in every field and the seeded runs, but no form sent twice']));
if ($r->notTwice)
    test_unsupported(array_merge(test_unsupported(), ['robustness: a form sent twice was not reached where the form as drawn is refused - '
        .robust_list(array_map(fn($who, $why) => $who.' („'.mb_strimwidth($why, 0, 50, '…').'“)', array_keys($r->notTwice), $r->notTwice), 12)]));
test_unsupported(array_merge(test_unsupported(), ['robustness: what an action does with an uploaded file. is_uploaded_file() is true only for '
    .'a real upload, so in process store_upload() refuses every file before it reads it']));

// ---------------------------------------------------------------------------
// How one probe is made and judged
// ---------------------------------------------------------------------------

/**
 * Run $fn inside a transaction that is always rolled back, and put the request's
 * memos back as they were: a setting saved and rolled back would otherwise still
 * be read from the memo by the next probe.
 */
function robust_undone(callable $fn): mixed {
    $result = null;
    $memos = [setting_cache(), payment_cache(), setup_cache()];
    try { transactional(function () use ($fn, &$result) { $result = $fn(); throw new RobustUndo(); }); }
    catch (RobustUndo) {}
    finally {
        $memo = &setting_cache(); $memo = $memos[0];
        $memo = &payment_cache(); $memo = $memos[1];
        $memo = &setup_cache(); $memo = $memos[2];
    }
    return $result;
}

/** A session for the role as it is in the database now: signed in, with its token. */
function robust_session_now(string $role): array {
    $r = robust(); $kept = $_SESSION;
    $_SESSION = ['locale' => 'de'];
    sign_in_as($r->ids[$role]);
    // The view as impersonate starts it: since c77348b it holds the viewer's auth_version too.
    if ($role === 'administrator viewing as the family') {
        $_SESSION['impersonator_id'] = $r->ids['administrator'];
        $_SESSION['impersonator_auth_version'] = (int)scalar('SELECT auth_version FROM accounts WHERE id=?', [$r->ids['administrator']]);
    }
    csrf();
    $session = $_SESSION; $_SESSION = $kept; current_user(true);
    return $session;
}

function robust_signed_in(array $session): array { return [$session['user_id'] ?? null, $session['impersonator_id'] ?? null]; }

/**
 * One post, as a browser sends it: $fields to $action in $session, through
 * submit(), and after a refusal what public/index.php does with one. Returns
 * where it goes ('to'), the sentence it refused with, what broke, and the session
 * it left behind.
 */
function robust_call(string $role, array $session, string $action, array $fields): array {
    $r = robust();
    if (in_array($action, $r->throttled, true)) run_counter('DELETE FROM rate_limits');
    $_SESSION = $session; current_user(true);
    $_GET = []; $_FILES = []; $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($fields['csrf'], $fields['action']);
    $paused = is_file(maintenance_file());
    $depth = tx_depth();
    $out = ['to' => null, 'refusal' => null, 'broke' => null, 'back' => null];
    set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $no)) return false;   // silenced with @ on purpose
        throw new ErrorException($message, 0, $no, $file, $line);
    });
    $GLOBALS['robust_inside'] = $action.', posted as '.$role;
    try {
        $out['to'] = submit($action, $fields);
    } catch (UserError $e) {
        $out['refusal'] = $e->getMessage();
        try { remember_input(post('action')); $out['to'] = form_return(current_user() ? 'dashboard' : 'login', $r->pages); }
        catch (Throwable $e) { $out['back'] = robust_describe($e); }
    } catch (Throwable $e) {
        $out['broke'] = robust_describe($e);
    } finally {
        restore_error_handler();
        $GLOBALS['robust_inside'] = null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        // A file is not rolled back with the transaction.
        if (!$paused && is_file(maintenance_file())) unlink(maintenance_file());
    }
    if (!db()->inTransaction()) {
        // A redirect in the middle of a request discards its transaction (tx_abandon_open()). Opened
        // again, so that nothing else this probe does is kept.
        $out['broke'] ??= 'ends the request’s transaction';
        db()->beginTransaction(); tx_depth($depth);
    } elseif ($out['broke'] === null && tx_depth() !== $depth) $out['broke'] = 'leaves a transaction open';
    $out['session'] = $_SESSION;
    return $out;
}

/** What is wrong with what one post came back with: a list of short sentences, empty when nothing is. */
function robust_problems(string $role, string $action, array $session, array $posted, array $out): array {
    $r = robust();
    if ($out['broke'] !== null) return [[$out['broke'], '']];
    $problems = [];
    if (($said = $out['refusal']) !== null) {
        $quoted = 'it said '.robust_show($said, 120);
        // Refused before the action - at the token, the request id or a throttle
        // the suite should have emptied - the probe measured nothing, and would pass.
        foreach (['Die Sitzung ist abgelaufen', 'Diese Eingabe wurde bereits verarbeitet', 'Zu viele Versuche'] as $before)
            if (str_contains($said, $before)) return [['the post never reached the action: the suite’s own token, request id or rate limit stopped it', $quoted]];
        if (trim($said) === '') $problems[] = ['refuses with an empty sentence', ''];
        elseif (mb_strlen($said) > 300) $problems[] = ['refuses with a sentence of more than 300 characters', $quoted];
        elseif (preg_match('/SQLSTATE|PDO|\.php|Exception|#0/', $said, $m)) $problems[] = ['refuses with a sentence containing „'.$m[0].'“', $quoted];
        elseif (robust_echoes($said, $posted)) $problems[] = ['refuses repeating more than 60 characters of what was posted', $quoted];
        if (in_array($role, $r->families, true) && str_contains($said, $r->marker)) $problems[] = ['refuses with a sentence naming the other family', $quoted];
        if ($out['back'] !== null) return [...$problems, ['after the refusal, the router’s way back breaks: '.$out['back'], '']];
    }
    [$page, $params] = $out['to'];
    if (!in_array($page, $r->pages, true)) $problems[] = ['returns a page the router does not allow', 'it went to '.robust_show($page)];
    foreach ((array)$params as $key => $value)
        if (!is_scalar($value)) { $problems[] = ['returns a parameter that is not a scalar', 'it went to '.$page.' with '.$key]; break; }
    if (!in_array($action, $r->signsInOrOut, true) && robust_signed_in($out['session']) !== robust_signed_in($session))
        $problems[] = ['changes who is signed in, and is not on the list of actions that do', ''];
    if ($said === null && in_array($role, $r->families, true) && robust_other_rows() !== $r->otherRows)
        $problems[] = ['changes the other family’s rows', ''];
    if ($said === null) $r->reached['posts that did their work']++;
    return $problems;
}

/** Post once inside a transaction that is rolled back, judge it there, and write down what was wrong. */
function robust_probe(string $section, string $role, string $action, array $fields, string $input, ?array $session = null): void {
    $r = robust();
    $session ??= $r->roles[$role];
    robust_undone(function () use ($r, $section, $role, $action, $fields, $input, $session) {
        $out = robust_call($role, $session, $action, $fields);
        robust_record($section, $action.' as '.$role, $input, robust_problems($role, $action, $session, $fields, $out));
    });
}

/**
 * A form as its page draws it, sent twice with its one request id (class 8). The
 * first copy has to work. The second, sent in the session the first left - a
 * double tap - and again from another session of the same person, has to write
 * nothing and hear only that it was a second copy: the session remembers where
 * the first went, the database remembers that it was sent. After an action that
 * changes who is signed in, the role signs in again and sends it once. A page the
 * first leads to is read for forms no link reaches - step 2 of a wizard.
 */
function robust_twice(string $role, array $form): bool {
    $r = robust();
    $action = $form['post']['action']; $who = $action.' as '.$role;
    return (bool)robust_undone(function () use ($r, $role, $form, $action, $who) {
        $first = robust_call($role, $form['session'], $action, $form['post']);
        robust_record('forms', $who, 'the form from '.$form['from'].', as drawn', robust_problems($role, $action, $form['session'], $form['post'], $first));
        if ($first['refusal'] !== null || $first['broke'] !== null) {
            if (!isset($r->sentTwice[$who])) $r->notTwice[$who] ??= (string)($first['refusal'] ?? $first['broke']);
            return false;
        }
        $r->sentTwice[$who] = true;
        unset($r->notTwice[$who]);
        $copies = in_array($action, $r->signsInOrOut, true) ? ['signed in again' => robust_session_now($role)]
                : ['in the same session' => $first['session'], 'from another session' => robust_session_now($role)];
        foreach ($copies as $where => $session) {
            $before = robust_checksum();
            $second = robust_call($role, $session, $action, $form['post']);
            $problems = $second['broke'] !== null ? [[$second['broke'], '']] : [];
            if ($second['broke'] === null && !robust_answered_as_duplicate($first, $second, $where))
                $problems[] = ['a second copy of a form is answered with something other than the duplicate handling',
                               $second['refusal'] !== null ? 'it said '.robust_show($second['refusal'], 100) : 'it went to '.$second['to'][0]];
            if (robust_checksum() !== $before) $problems[] = ['a second copy of a form writes', ''];
            robust_record('twice', $who, 'the form from '.$form['from'].', sent again '.$where, $problems);
        }
        // Not after a change of who is signed in: the session it left is not one a later post can use.
        if (!in_array($action, $r->signsInOrOut, true)) robust_follow($role, $action, $first);
        return true;
    });
}

/**
 * Whether the second copy of a form got the duplicate handling and nothing else.
 * Since 2788c4f a copy the session remembers - a double tap - lands on the page
 * the first went to with nothing said, and any other copy, from another session,
 * hears „Diese Eingabe wurde bereits verarbeitet". Exactly that: a copy in the
 * same session refused with the sentence is the old answer, which replaced the
 * first one's „gespeichert" with a red error.
 */
function robust_answered_as_duplicate(array $first, array $second, string $where): bool {
    $refused = $second['refusal'] !== null && str_contains($second['refusal'], t('Diese Eingabe wurde bereits verarbeitet', 'This submission has already been processed'));
    $landed = $second['refusal'] === null && $second['to'] == $first['to'];
    return $landed || ($refused && $where !== 'in the same session');
}

/** Every table but the rate limits, which the next post empties: what one post wrote changes it. */
function robust_checksum(): array {
    static $tables;
    $tables ??= implode(', ', array_map(fn($t) => '`'.sql_name($t, 'table').'`', array_diff(test_tables(), ['rate_limits'])));
    return array_column(db()->query('CHECKSUM TABLE '.$tables)->fetchAll(PDO::FETCH_NUM), 1, 0);
}

/**
 * The page a form that worked goes to, when no link leads to a page of its shape -
 * step 2 of a wizard - read for forms of actions the role has none for yet.
 */
function robust_follow(string $role, string $action, array $first): void {
    $r = robust();
    [$page, $params] = $first['to'];
    if (isset($r->shapes_drawn[$role][robust_shape($page, array_diff_key($params, ['#' => 0]))])) return;
    $_SESSION = $first['session'];
    $user = current_user(true);
    if (!$user || !robust_drawable($user, $page, $params)) return;
    $drawn = robust_render($role.', after '.$action, $page, $params);
    if ($drawn['broke'] !== null) { robust_record('pages', $page.' as '.$role, 'after '.$action, [[$drawn['broke'], '']]); return; }
    if ($drawn['html'] === null) return;
    $have = array_flip(array_map(fn($f) => $f['post']['action'], $r->forms[$role]));
    robust_keep_forms($role, array_filter(robust_read($drawn['html'])[1], fn($f) => !isset($have[$f['post']['action']])),
                      $first['session'], $page.' after '.$action);
}

/** Per action, the first form that worked as drawn, or the first form there is. */
function robust_first_forms(string $role): array {
    $first = [];
    foreach (robust()->forms[$role] as $form)
        if (!isset($first[$form['post']['action']]) || (!empty($form['worked']) && empty($first[$form['post']['action']]['worked'])))
            $first[$form['post']['action']] = $form;
    return $first;
}

/** The family's form with every id of one kind swapped for the other family's, or for one that does not exist (class 7). */
function robust_swap_ids(string $role, array $form): void {
    $r = robust();
    foreach ($r->own as $kind => $id)
        foreach (['the other family’s '.$kind => (string)$r->other[$kind], 'a '.$kind.' id that does not exist' => (string)$r->missing] as $label => $swap) {
            $post = $form['post']; $hit = false;
            array_walk_recursive($post, function (&$value, $key) use ($id, $swap, &$hit) {
                if (!in_array($key, FORM_BOOKKEEPING_FIELDS, true) && (string)$value === (string)$id) { $value = $swap; $hit = true; }
            });
            if ($hit) robust_probe('ids', $role, $form['post']['action'], $post, 'the form from '.$form['from'].' with '.$label, $form['session']);
        }
}

/** The form with one field changed at a time: left out, or any value of classes 2 to 6; inside a list, its first item too. */
function robust_mutate(string $role, array $form): void {
    $r = robust();
    $action = $form['post']['action'];
    foreach ($form['post'] as $field => $drawn) {
        if (in_array($field, [...FORM_BOOKKEEPING_FIELDS, 'csrf'], true)) continue;
        $paths = [[$field]];
        if (is_array($drawn) && $drawn) $paths[] = [$field, array_key_first($drawn)];
        foreach ($paths as $path)
            foreach ($r->mutations as $label => $value) {
                $post = $form['post'];
                $at = &$post;
                foreach (array_slice($path, 0, -1) as $step) $at = &$at[$step];
                if ($value === null) unset($at[end($path)]); else $at[end($path)] = $value;
                unset($at);
                robust_probe('forms', $role, $action, $post, 'the form from '.$form['from'].' with '.implode('[', $path).(count($path) > 1 ? ']' : '').' '.$label, $form['session']);
            }
    }
}

/** One seeded draw of every field: as the form has it, mostly, and otherwise any value of any class. */
function robust_drawn_fields(int $seed, array $base): array {
    $r = robust();
    $random = new Random\Randomizer(new Random\Engine\Mt19937($seed));
    $values = [];
    foreach ($r->classes as $class) foreach ($class as $value) $values[] = $value;
    $fields = [];
    foreach (array_unique([...$r->fields, ...array_keys($base)]) as $field) {
        if (in_array($field, ['action', 'csrf', 'request_id'], true)) continue;
        $value = $random->getInt(1, 100) <= 60 ? ($base[$field] ?? null) : $values[$random->getInt(0, count($values) - 1)];
        if ($value !== null) $fields[$field] = $value;
    }
    return $fields;
}

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

/** Whether the router lets $user open $page at all, by its own lists. */
function robust_may_open(?array $user, string $page): bool {
    $r = robust();
    if (in_array($page, $r->adminPages, true)) return ($user['role'] ?? '') === 'admin';
    if (in_array($page, $r->staffPages, true)) return $user !== null && is_staff($user);
    return $user !== null || in_array($page, $r->publicPages, true);
}

/**
 * Whether render_page() can draw $page as public/index.php would: a view, opened by
 * somebody it lets in, and not one the router sends on before the view - a signed-in
 * visitor away from the sign-in page, a family from the list of students to its own
 * child, and a student page without a student to the wizard.
 */
function robust_drawable(array $user, string $page, array $query): bool {
    if (!robust_may_open($user, $page) || $page === 'download' || $page === 'login' || !is_file(APP_ROOT.'/views/'.$page.'.php')) return false;
    if ($page === 'students' && students_list_instead($user)) return false;
    if ($page === 'student' && (int)($query['id'] ?? 0) <= 0) return false;
    return true;
}

/** One page drawn inside a transaction that is rolled back - whole, or the view alone: its HTML, the sentence it refused with, or what broke. */
function robust_render(string $who, string $page, array $query, bool $whole = true): array {
    return robust_undone(function () use ($who, $page, $query, $whole) {
        $GLOBALS['robust_inside'] = $page.', drawn as '.$who;
        try { return ['html' => $whole ? render_page($page, $query) : render_view($page, $query), 'refusal' => null, 'broke' => null]; }
        catch (UserError $e) { return ['html' => null, 'refusal' => $e->getMessage(), 'broke' => null]; }
        catch (Throwable $e) { return ['html' => null, 'refusal' => null, 'broke' => robust_describe($e)]; }
        finally { $GLOBALS['robust_inside'] = null; }
    });
}

/** Draw $page as $role with $query and write down what was wrong. */
function robust_look(string $role, string $page, array $query, string $input, bool $whole = true): void {
    $r = robust();
    $_SESSION = $r->roles[$role];
    $user = current_user(true);
    if (!robust_drawable($user, $page, $query)) return;
    $drawn = robust_render($role, $page, $query, $whole);
    robust_record('pages', $page.' as '.$role, $input, robust_page_problems($role, $drawn));
}

/** What is wrong with a drawn page: what broke, or a family shown something of the other family's. */
function robust_page_problems(string $role, array $drawn): array {
    $r = robust();
    if ($drawn['broke'] !== null) return [[$drawn['broke'], '']];
    if ($drawn['html'] !== null) $r->reached['pages drawn with unexpected values']++;
    if ($drawn['html'] !== null && in_array($role, $r->families, true) && str_contains($drawn['html'], $r->marker))
        return [['shows the family something of the other family’s', 'the page names „'.$r->marker.'“']];
    return [];
}

/**
 * Every page the role reaches from the router's pages by following links, each
 * record's once - every date of a calendar counts as one - with the forms kept on
 * the way. One address of each shape (the page, its tab, which keys) is kept for
 * drawing again with unexpected values.
 */
function robust_crawl(string $role): void {
    $r = robust();
    $r->forms[$role] = []; $r->drawn[$role] = []; $r->served[$role] = [];
    $queue = array_map(fn($page) => [$page, []], $r->pages);
    $seen = []; $shapes = [];
    while ($queue && count($seen) < 250) {
        [$page, $query] = array_shift($queue);
        ksort($query);
        // Each record once: a date or anything else that is not a word or an id is any value.
        $address = $page;
        foreach ($query as $key => $value)
            $address .= '&'.$key.'='.(is_string($value) && preg_match('/^(?:[a-z_]+|[0-9]+)$/', $value) ? $value : '*');
        $shape = robust_shape($page, $query);
        if (isset($seen[$address])) continue;
        $seen[$address] = true;
        $r->shapes_drawn[$role][$shape] = true;
        $_SESSION = $r->roles[$role];
        $user = current_user(true);
        if (!robust_may_open($user, $page)) continue;
        if (!robust_drawable($user, $page, $query)) {
            if ($page === 'download' && $query) $r->served[$role][] = [$page, $query];
            continue;
        }
        $drawn = robust_render($role, $page, $query);
        robust_record('pages', $page.' as '.$role, 'as linked'.($query ? ', '.http_build_query($query) : ''), robust_page_problems($role, $drawn));
        if ($drawn['html'] === null) continue;
        if (!isset($shapes[$shape])) $r->drawn[$role][] = [$page, $query];
        $shapes[$shape] = true;
        [$links, $forms] = robust_read($drawn['html']);
        array_push($queue, ...$links);
        robust_keep_forms($role, $forms, $r->roles[$role], $page.($query ? '?'.http_build_query($query) : ''));
    }
}

/** An address by its shape: the page, its tab and what it asks for, and which keys - not which record or day. */
function robust_shape(string $page, array $query): string {
    ksort($query);
    foreach ($query as $key => $value) $page .= '&'.$key.(is_string($value) && preg_match('/^[a-z_]+$/', $value) ? '='.$value : '');
    return $page;
}

/** Keep each form once by its shape: its action, its fields, and its hidden choices such as mode=suspend. */
function robust_keep_forms(string $role, array $forms, array $session, string $from): void {
    $r = robust();
    foreach ($forms as $form) {
        if (isset($r->shapes[$role][$form['shape']])) continue;
        $r->shapes[$role][$form['shape']] = true;
        $r->forms[$role][] = $form + ['session' => $session, 'from' => $from];
    }
}

/**
 * The links and the forms of a drawn page. A form is sent the way a browser
 * sends it: what it holds, with what a person types into a box that must be
 * filled, a password box and an empty text area, a ticked box ticked, and the
 * first button that has a name pressed.
 */
function robust_read(string $html): array {
    $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $links = [];
    foreach ($doc->querySelectorAll('a[href]') as $a) {
        $href = (string)$a->getAttribute('href');
        if (!str_contains($href, 'index.php?')) continue;
        parse_str((string)parse_url($href, PHP_URL_QUERY), $query);
        $page = $query['page'] ?? '';
        unset($query['page'], $query['lang']);
        if (is_string($page) && $page !== '') $links[] = [$page, $query];
    }
    $forms = [];
    foreach ($doc->querySelectorAll('form[method=post]') as $form) {
        $pairs = []; $radios = []; $shape = [];
        foreach ($form->querySelectorAll('input[name], select[name], textarea[name]') as $el) {
            if ($el->hasAttribute('disabled')) continue;
            $name = (string)$el->getAttribute('name');
            $tag = strtolower($el->tagName);
            $type = $tag === 'input' ? strtolower((string)($el->getAttribute('type') ?: 'text')) : $tag;
            if (in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) continue;
            if ($type === 'radio') {
                if (!isset($radios[$name]) || $el->hasAttribute('checked')) $radios[$name] = (string)($el->getAttribute('value') ?? 'on');
                continue;
            }
            if ($type === 'checkbox' && !$el->hasAttribute('checked')) continue;
            $value = match ($type) {
                'select' => robust_selected($el),
                'textarea' => $el->textContent,
                'checkbox' => (string)($el->getAttribute('value') ?? 'on'),
                default => (string)($el->getAttribute('value') ?? ''),
            };
            // A box that must be filled, a password, a message: what a person types.
            if ($value === '' && ($el->hasAttribute('required') || $type === 'password' || $type === 'textarea')) $value = robust_typed($el, $type);
            $pairs[] = [$name, $value];
            $shape[] = $name.($type === 'hidden' && !ctype_digit($value) && !in_array($name, [...FORM_BOOKKEEPING_FIELDS, 'csrf'], true) ? '='.$value : '');
        }
        foreach ($radios as $name => $value) $pairs[] = [$name, $value];
        if ($button = $form->querySelector('button[name]:not([type=button]), input[type=submit][name]')) {
            $pairs[] = [(string)$button->getAttribute('name'), (string)($button->getAttribute('value') ?? '')];
            $shape[] = implode('=', end($pairs));
        }
        parse_str(implode('&', array_map(fn($pair) => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $pairs)), $post);
        if (is_string($post['action'] ?? null)) $forms[] = ['post' => $post, 'shape' => $post['action'].'?'.implode('&', $shape)];
    }
    return [$links, $forms];
}

function robust_selected(Dom\Element $select): string {
    $option = $select->querySelector('option[selected]') ?? $select->querySelector('option');
    return $option === null ? '' : ($option->hasAttribute('value') ? (string)$option->getAttribute('value') : trim($option->textContent));
}

/** What a person types into a box that must not stay empty. */
function robust_typed(Dom\Element $el, string $type): string {
    static $n = 0;
    if ($type === 'select') {
        foreach ($el->querySelectorAll('option') as $option) {
            $value = $option->hasAttribute('value') ? (string)$option->getAttribute('value') : trim($option->textContent);
            if ($value !== '') return $value;
        }
        return '';
    }
    $numeric = in_array((string)$el->getAttribute('inputmode'), ['decimal', 'numeric'], true);
    return match (true) {
        $type === 'email' => 'robust'.(++$n).'@example.test',
        $type === 'date' => today(),
        $type === 'password' => robust()->password,
        $type === 'number', $numeric => (string)($el->getAttribute('min') ?: '1'),
        default => 'Robust',
    };
}

// ---------------------------------------------------------------------------
// Out of process: the real router, in a php -S of the run's own
// ---------------------------------------------------------------------------

/** Start the server; '' when it answers, otherwise why not. */
function robust_server_start(): string {
    $r = robust();
    if (!function_exists('proc_open')) return 'this PHP disables proc_open';
    $r->work = test_run_dir().'/robustness';
    @mkdir($r->work.'/sessions', 0700, true);
    write_run_config($r->work.'/config.php', config('db'), $r->work);
    // The schema is the run's, made from the migration files: the router is told it is current.
    file_put_contents($r->work.'/schema.stamp', schema_state());
    // The server's folder is the run's own; every request goes to the portal's
    // public/index.php through this one line, which is all it serves.
    file_put_contents($r->work.'/router.php', '<?php require '.var_export(realpath(APP_ROOT.'/public/index.php'), true).";\n");
    $r->log = $r->work.'/server.log';
    touch($r->log);
    for ($try = 0; $try < 5; $try++) {
        $port = random_int(20000, 60999);
        $server = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=',
                             '-S', '127.0.0.1:'.$port, $r->work.'/router.php'],
                            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $r->log, 'a'], 2 => ['file', $r->log, 'a']],
                            $pipes, $r->work, ['CRM_CONFIG' => $r->work.'/config.php'] + getenv());
        if (!is_resource($server)) continue;
        for ($wait = 0; $wait < 60; $wait++) {
            if ($socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
                fclose($socket);
                $GLOBALS['robust_server'] = $server;
                $r->port = $port;
                return '';
            }
            if (!proc_get_status($server)['running']) break;
            usleep(50000);
        }
        proc_terminate($server);
    }
    return 'php -S did not start: '.trim(substr((string)file_get_contents($r->log), -300));
}

function robust_server_stop(): void {
    if (!isset($GLOBALS['robust_server'])) return;
    proc_terminate($GLOBALS['robust_server']);
    proc_close($GLOBALS['robust_server']);
    unset($GLOBALS['robust_server']);
}

/**
 * One request to the real router: a GET of $query, or with $post a POST of it as
 * signed out. $role is 'signed out' or one of the roles, whose session is written
 * where the server keeps its sessions.
 */
function robust_outside(string $role, string $what, array $query, ?array $post, string $input): void {
    $r = robust();
    run_counter('DELETE FROM rate_limits');
    $session = $role === 'signed out' ? ['locale' => 'de', 'csrf' => bin2hex(random_bytes(32))] : ['last_seen' => time()] + $r->roles[$role];
    $id = bin2hex(random_bytes(16));
    $stored = '';
    foreach ($session as $key => $value) $stored .= $key.'|'.serialize($value);
    file_put_contents($r->work.'/sessions/sess_'.$id, $stored);
    if ($post !== null) $post += ['action' => $what, 'csrf' => $session['csrf'], 'request_id' => bin2hex(random_bytes(32))];
    clearstatcache();
    $from = filesize($r->log);
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
        'header' => 'Cookie: badminton_session='.$id."\r\n".($post === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n"),
        'content' => $post === null ? '' : http_build_query($post)]]);
    $body = (string)@file_get_contents('http://127.0.0.1:'.$r->port.'/index.php'.($query ? '?'.http_build_query($query) : ''), false, $context);
    $headers = $http_response_header ?? [];
    clearstatcache();
    $log = (string)file_get_contents($r->log, false, null, $from);
    preg_match('~^HTTP/\S+ (\d{3})~', $headers[0] ?? '', $m);
    $status = (int)($m[1] ?? 0);
    $problems = [];
    if ($status === 0 || $status >= 500) $problems[] = ['answers with a status of 500 or more, or none', 'status '.$status];
    if (preg_match('/<b>(?:Warning|Notice|Deprecated)<\/b>:|\b(?:Warning|Notice|Deprecated): /', $body)) $problems[] = ['prints a PHP warning into the page', ''];
    if (preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error):\s+(.*?) in (\S+) on line (\d+)/', $log, $m))
        $problems[] = ['PHP '.$m[1].': '.$m[2].' @ '.robust_path($m[3]).':'.$m[4], ''];
    // What the portal writes to the host's error log is for something that went
    // wrong. A bot posting to it signed out is expected, and must not fill it.
    elseif (preg_match('/^.*\bCRM\b.*$/m', $log, $m))
        $problems[] = ['writes to the host’s error log', robust_show(trim($m[0]), 120)];
    if (in_array($role, $r->families, true) && str_contains($body, $r->marker)) $problems[] = ['shows the family something of the other family’s', ''];
    if ($post !== null && $status < 500) {
        $location = '';
        foreach ($headers as $header) if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
        parse_str((string)parse_url($location, PHP_URL_QUERY), $to);
        if ($status !== 303 || !in_array($to['page'] ?? '', $r->publicPages, true))
            $problems[] = ['answers with something other than a way back to the sign-in page or another public page',
                           'status '.$status.($location !== '' ? ', to '.($to['page'] ?? '?') : '')];
    }
    // The session the request left says whether it was refused at the token.
    if ($post !== null && str_contains((string)@file_get_contents($r->work.'/sessions/sess_'.$id), 'Die Sitzung ist abgelaufen'))
        $problems[] = ['the post never reached the action: the suite’s own token was refused', ''];
    if ($status > 0) $r->reached['requests to the real router']++;
    robust_record('outside', $what.' as '.$role, $input, $problems);
    @unlink($r->work.'/sessions/sess_'.$id);
}

// ---------------------------------------------------------------------------
// What the other family has, and how a finding is written down
// ---------------------------------------------------------------------------

/**
 * Everything of the other family's, as one string: their own rows, and every row
 * of any table that points at one of them. One statement, built once from the
 * schema, because it is asked after every post of the family's that is not refused.
 */
function robust_other_rows(): string {
    static $sql, $params;
    if ($sql === null) {
        $o = robust()->other;
        $points = ['student_id' => 'child', 'account_id' => 'login', 'from_account_id' => 'login', 'to_account_id' => 'login', 'sender_id' => 'login',
                   'thread_id' => 'chat', 'charge_id' => 'charge', 'invoice_id' => 'invoice', 'message_id' => 'message'];
        $own = ['students' => 'child', 'accounts' => 'login', 'contacts' => 'contact', 'absences' => 'absence', 'charges' => 'charge',
                'payments' => 'payment', 'invoices' => 'invoice', 'payment_proofs' => 'receipt', 'threads' => 'chat', 'messages' => 'message'];
        $columns = [];
        foreach (rows('SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position') as $c)
            $columns[$c['t']][] = $c['c'];
        $parts = []; $params = [];
        foreach ($columns as $table => $names) {
            $row = "CONCAT_WS('|', ".implode(', ', array_map(fn($n) => "IFNULL(`".sql_name($n)."`, '-')", $names)).')';
            $where = array_merge(isset($own[$table]) ? [['id', $own[$table]]] : [], array_map(fn($n) => [$n, $points[$n]], array_values(array_intersect($names, array_keys($points)))));
            foreach ($where as [$column, $kind]) {
                $parts[] = "SELECT '".$table.'.'.$column."' AS k, ".$row.' AS v FROM `'.sql_name($table, 'table').'` WHERE `'.sql_name($column).'` = ?';
                $params[] = $o[$kind];
            }
        }
        $sql = implode(' UNION ALL ', $parts).' ORDER BY k, v';
    }
    return (string)json_encode(rows($sql, $params), JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Whether a refusal repeats more than 60 characters of anything posted. */
function robust_echoes(string $said, array $posted): bool {
    static $memo = [];
    if (mb_strlen($said) <= 60) return false;
    $long = [];
    array_walk_recursive($posted, static function ($value) use (&$long) { if (is_string($value) && strlen($value) > 60) $long[$value] = true; });
    foreach (array_keys($long) as $value) {
        $value = (string)$value;
        if (!isset($memo[$said][$value])) {
            $memo[$said][$value] = false;
            for ($i = 0, $n = mb_strlen($said) - 60; $i < $n; $i++)
                if (str_contains($value, mb_substr($said, $i, 61))) { $memo[$said][$value] = true; break; }
        }
        if ($memo[$said][$value]) return true;
    }
    return false;
}

/** What broke, and the line in the portal it broke on: for a database error, the line that sent the statement. */
function robust_describe(Throwable $e): string {
    $where = '';
    foreach ([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()] as $frame) {
        if (!isset($frame['file']) || str_contains($frame['file'], '/tests/')) continue;
        if ($e instanceof PDOException && preg_match('~/app/(?:core|tx)\.php$~', $frame['file'])) continue;
        $where = ' @ '.robust_path($frame['file']).':'.($frame['line'] ?? 0);
        break;
    }
    return get_class($e).': '.robust_show($e->getMessage(), 200).$where;
}

/** A file as the portal names it: app/core.php, not the whole path to it. */
function robust_path(string $file): string { return preg_replace('~^.*/((?:app|views|public|bin)/[^/]+)$~', '$1', $file); }

function robust_show(mixed $value, int $max = 40): string {
    if ($value === null) return '(nothing)';
    if (is_array($value)) return (string)json_encode($value);
    $text = (string)$value;
    if (!mb_check_encoding($text, 'UTF-8')) return 'the bytes '.bin2hex(substr($text, 0, 16));
    $text = addcslashes($text, "\0..\37");
    return mb_strlen($text) > $max ? '„'.mb_substr($text, 0, (int)($max / 2)).'…“ ('.mb_strlen($text).' characters)' : '„'.$text.'“';
}

function robust_list(array $items, int $max = 8): string {
    return count($items) <= $max ? implode(', ', $items) : implode(', ', array_slice($items, 0, $max)).' and '.(count($items) - $max).' more';
}

/** Write down that $who was tried in $section, and each problem it showed, once per cause. */
function robust_record(string $section, string $who, string $input, array $problems): void {
    $r = robust();
    $r->tried[$section][$who] = true;
    foreach ($problems as [$problem, $detail]) {
        $entry = &$r->found[$section][$problem];
        $entry ??= ['who' => [], 'first' => $who.', '.$input.($detail !== '' ? ' - '.$detail : ''), 'times' => 0];
        $entry['who'][$who] = true;
        $entry['times']++;
        unset($entry);
    }
}

/** One failure per cause, naming who shares it; one pass for everybody tried in $section who showed nothing. */
function robust_report(string $section, string $title): void {
    $r = robust();
    case_($title);
    $failed = [];
    foreach ($r->found[$section] ?? [] as $problem => $entry) {
        ok(false, $problem.' — '.robust_list(array_keys($entry['who'])).'. First: '.$entry['first'].($entry['times'] > 1 ? ' ('.$entry['times'].' times)' : ''));
        $failed += $entry['who'];
    }
    foreach (array_keys($r->tried[$section] ?? []) as $who) if (!isset($failed[$who])) ok(true, $who);
}
