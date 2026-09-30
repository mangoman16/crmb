<?php
/**
 * What a problem report carries: the last steps somebody took, and nothing in
 * them that must never be kept (ADR 0009).
 *
 * The steps are written by record_step() at its one call site in the router.
 * visit() and send() below do what public/index.php does around that call - the
 * request's globals, the recorder, the real action, and the flash a refusal
 * leaves - so the trail read back is the one a real run of requests would leave
 * behind, not a description of it.
 *
 * send() records exactly what a browser posts, token and request id included,
 * and then runs the action through act() rather than handle_post(). On SQLite a
 * write locks the whole file: handle_post() claims the request id on the main
 * connection, and a throttle inside feedback_send or email_change then waits on
 * the counter connection for a lock that is never released. MariaDB locks rows,
 * so the portal is fine; the harness is not, and the trail is the same either way.
 */
$admin  = make_account(['role'=>'admin', 'name'=>'Chefin']);
$family = make_account(['role'=>'student', 'name'=>'Familie Hofer']);

/** A GET, as far as the trail can tell: the router records it, the layout shows the flash and forgets it. */
function visit(string $page, array $query = []): void {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['page' => $page] + $query;
    $_SERVER['REQUEST_URI'] = '/index.php?'.http_build_query($_GET);
    $_POST = []; $_FILES = [];
    record_step($page);
    unset($_SESSION['flash']);
    $_GET = [];
}

/** A form sent the way the browser sends it: to index.php with no query, recorded, then handled. */
function send(string $action, array $fields = [], array $files = []): void {
    test_load_actions();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/index.php';
    $_GET = [];
    $_POST = $fields + ['action' => $action, 'csrf' => csrf(), 'request_id' => bin2hex(random_bytes(32))];
    $_FILES = $files;
    record_step('dashboard');
    try { act($action, $_POST); }
    catch (UserError $e) { flash($e->getMessage(), 'error'); }
    $_POST = []; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

/** Where every start_form() says the form was. */
function on_page(string $page, int $id = 0, string $tab = ''): array {
    return ['return_page' => $page, 'return_id' => (string)$id, 'return_tab' => $tab];
}

function last_step(): array { $steps = recent_steps(); return (array)end($steps); }

// ---------------------------------------------------------------------------
case_('Every page somebody signed in opens is a step');
sign_in_as($family);
visit('dashboard');
visit('student', ['id' => 5, 'tab' => 'payments']);
$steps = recent_steps();
is_same(2, count($steps), 'two pages, two steps');
is_same('GET', $steps[1]['method'], 'with the method');
is_same('/index.php?page=student&id=5&tab=payments', $steps[1]['url'], 'and the exact address');
ok((bool)preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', (string)$steps[1]['at']), 'and when, as a UTC timestamp');

case_('A form is a step too, with what was sent, before it redirects');
send('preferences_save', ['name'=>'Familie Hofer', 'locale'=>'de', 'theme'=>'dark', 'accent'=>'',
                          'text_scale'=>'normal'] + on_page('profile'));
$step = last_step();
is_same('POST', $step['method'], 'the method');
is_same('/index.php', $step['url'], 'the address it went to');
is_same('preferences_save', $step['action'], 'what it asked for');
is_same(['page'=>'profile', 'id'=>0, 'tab'=>''], $step['from'], 'and the page the form was on');
is_same('dark', $step['fields']['theme'] ?? null, 'what was chosen');
is_same('Familie Hofer', $step['fields']['name'] ?? null, 'what was typed');
foreach (['request_id', 'action', 'return_page', 'return_id', 'return_tab'] as $noise)
    ok(!array_key_exists($noise, $step['fields']), $noise.' is not repeated among the fields');
/* It used to be kept as csrf=***: on every form, the same three stars, saying
   nothing. The rule that masks it stays - see the case on is_secret_field(). */
ok(!array_key_exists('csrf', $step['fields']), 'the form token is left out altogether, not even as ***');
is_same(['name', 'locale', 'theme', 'accent', 'text_scale'], array_keys($step['fields']), 'so the fields are exactly the ones the form asked for');
is_same([], $step['files'], 'and nothing was attached');

case_('The page after a form says how it went');
visit('profile');
is_same(['kind'=>'success', 'text'=>'Einstellungen gespeichert.'], last_step()['flash'] ?? null, 'the message it showed');
send('password_change', ['current_password'=>'falsch-falsch-falsch', 'password'=>'x', 'password_confirm'=>'x'] + on_page('profile'));
visit('profile');
is_same(['kind'=>'error', 'text'=>'Passwort nicht korrekt.'], last_step()['flash'] ?? null, 'a refusal too, in the words she saw');
visit('dashboard');
ok(!isset(last_step()['flash']), 'and only on the page that showed it');

case_('Eight steps, oldest first');
for ($i = 1; $i <= 11; $i++) visit('student', ['id' => $i]);
$steps = recent_steps();
is_same(8, count($steps), 'the last eight are kept');
is_same('/index.php?page=student&id=4', $steps[0]['url'], 'the oldest first');
is_same('/index.php?page=student&id=11', $steps[7]['url'], 'the newest last');

case_('Not recorded: pictures, the icon, the manifest, the club’s colours and logo');
/* An avatar loads through download on every page, and so do the club's
   stylesheet and logo once she has set them; recorded, they would push the
   steps that matter out of the trail. */
$before = recent_steps();
visit('download', ['what'=>'avatar', 'kind'=>'account', 'id'=>1]);
visit('icon', ['v'=>'abcdef123456']);
visit('manifest');
visit('brand', ['v'=>'abcdef123456']);
visit('logo', ['v'=>'abcdef123456']);
is_same($before, recent_steps(), 'none of them is a step');

case_('Not recorded: the report itself');
send('feedback_send', ['message'=>'Der Knopf tut nichts.', 'page'=>'student'] + on_page('student', 11));
is_same($before, recent_steps(), 'filing a report adds nothing to the trail');
$context = json_decode((string)scalar('SELECT context_json FROM feedback ORDER BY id DESC LIMIT 1'), true);
is_same($before, $context['steps'], 'and the report carries the steps before it');
is_same(['page'=>'student', 'id'=>11, 'tab'=>''], $context['on'], 'with the page the form was really on');
ok(!array_key_exists('query', $context), 'no query string, which was empty in every report ever filed');
ok(!array_key_exists('referer', $context), 'and no Referer, which the portal never lets a browser send');

case_('Not recorded: anybody not signed in');
/* Which leaves out the login form and the address typed into it, and every
   token link opened by nobody in particular. */
act('logout', []);
is_same(null, current_user(), 'nobody is signed in');
$familyRow = one('SELECT * FROM accounts WHERE id=?', [$family]);
visit('login');
send('login', ['email'=>$familyRow['email'], 'password'=>'Test-Only-Password-2026'] + on_page('login'));
is_same($family, (int)(current_user()['id'] ?? 0), 'the sign-in worked');
/* Read from the session itself, not through recent_steps(): a step recorded
   for nobody would be hidden by the change of account at sign-in, and the
   password would be masked anyway - the address is what would give it away. */
is_same([], $_SESSION['steps'] ?? [], 'neither the page nor the form was written down');
ok(!str_contains(serialize($_SESSION), $familyRow['email']), 'so the address typed into it is nowhere in the session');
ok(!str_contains(serialize($_SESSION), 'Test-Only-Password-2026'), 'and nor is the password');

case_('The trail belongs to one account');
visit('dashboard');
visit('students');
is_same(2, count(recent_steps()), 'the family has two steps');
sign_in_as($admin);
is_same([], recent_steps(), 'somebody else signed in on the same phone does not see them');
visit('settings');
is_same(1, count(recent_steps()), 'and starts a trail of their own');
send('impersonate', ['id'=>(string)$family, 'mode'=>'start'] + on_page('accounts'));
is_same($family, (int)current_user()['id'], 'looking through the family’s eyes');
is_same([], recent_steps(), 'starts again too, so nothing of the administrator’s lands in the family’s report');
visit('dashboard');
is_same(1, count(recent_steps()), 'with the family’s own steps from here');
send('impersonate', ['mode'=>'stop'] + on_page('dashboard'));

case_('What is kept is cut to a size a session can carry');
sign_in_as($family);
$kept = report_input([
    'long'   => str_repeat('a', 500),
    'broken' => "Gr\xFC\xDFe",
    'nested' => ['one' => ['two' => ['three' => 'x']]],
    'many'   => range(1, 30),
    'custom' => ['api_token' => 'darf-nicht-bleiben', 'note' => 'darf bleiben'],
] + array_fill_keys(array_map(fn($i) => 'f'.$i, range(1, 60)), 'v'));
is_same(200, mb_strlen($kept['long']), 'a value is cut to 200 characters');
ok(str_ends_with($kept['long'], '…'), 'and says it was cut');
ok(mb_check_encoding($kept['broken'], 'UTF-8'), 'a malformed byte is replaced, so the value can still be stored');
is_same(['one' => ['two' => '…']], $kept['nested'], 'two levels of arrays are kept, anything deeper is …');
is_same(20, count($kept['many']), 'twenty items per array');
is_same('***', $kept['custom']['api_token'], 'a secret is masked at any depth');
is_same('darf bleiben', $kept['custom']['note'], 'and its neighbour is not');
is_same(40, count($kept), 'forty fields per step');
$huge = report_input(array_fill_keys(array_map(fn($i) => 'g'.$i, range(1, 40)),
                                     array_fill(0, 20, array_fill(0, 20, str_repeat('ü', 300)))));
ok(strlen((string)json_encode($huge, JSON_UNESCAPED_UNICODE)) < 20000,
   'and a form built to be enormous stops at 16 kB, rather than multiplying out to megabytes');
is_same('…', $huge['…'] ?? null, 'saying that it stopped');
is_same('***', report_input(['password' => ''])['password'], 'a secret nobody typed is still ***, so an empty one cannot be told from a full one');
is_same('***', report_input([str_repeat('x', 80).'password' => 'geheim'])[mb_substr(str_repeat('x', 80).'password', 0, 59).'…'] ?? null,
        'and a long name is judged before it is shortened, not after the word that marks it is cut off');

case_('An attached file is its size, its type and how it arrived - never its name');
send('feedback_send', ['message'=>'Mit Bild', 'page'=>'dashboard'] + on_page('dashboard'));   // not recorded, as above
send('avatar_save', ['kind'=>'account', 'id'=>(string)$family] + on_page('profile'),
     ['avatar' => ['name'=>'Urlaub-Lena-Hofer.png', 'type'=>'image/png', 'tmp_name'=>'/tmp/phpA1B2C3', 'error'=>UPLOAD_ERR_OK, 'size'=>48213],
      'extra'  => ['name'=>['a.pdf', 'b.pdf'], 'type'=>['application/pdf', 'application/pdf'], 'tmp_name'=>['/tmp/x', '/tmp/y'],
                   'error'=>[UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE], 'size'=>[100, 0]]]);
$step = last_step();
is_same('avatar_save', $step['action'], 'the step is the upload');
is_same(['bytes'=>48213, 'type'=>'image/png', 'error'=>UPLOAD_ERR_OK], $step['files']['avatar'] ?? null, 'size, declared type and error');
is_same(UPLOAD_ERR_INI_SIZE, $step['files']['extra'][1]['error'] ?? null, 'a list of files is kept per file, with the one the server refused');
$trail = serialize($_SESSION['steps']);
ok(!str_contains($trail, 'Urlaub-Lena-Hofer'), 'the name somebody gave the file is not kept');
ok(!str_contains($trail, 'phpA1B2C3'), 'nor where the server put it');

// ---------------------------------------------------------------------------
case_('No password, token or signature reaches a report');
/* The ADR's test, on real actions: every kind of secret field the portal has
   is sent, the report is filed, and the stored JSON is searched as text - not
   the decoded keys, which would miss a value that ended up somewhere else.
   Each keyword in is_secret_field() has a value here that only it protects:
   take one out and one of these assertions fails. */
test_reset();
$chef = make_account(['role'=>'admin', 'name'=>'Chefin']);
$chefRow = sign_in_as($chef);
$secrets = [
    'the current password'      => 'Test-Only-Password-2026',
    'the new password'          => 'Neues-Geheimwort-2026-q7',
    'the SMTP password'         => 'Smtp-Kennwort-9f3k2m',
    'a password field in German'=> 'Passwort-Feld-Wert-77x',
    'a client secret'           => 'Client-Secret-Wert-4j8',
    'a token at the second level'=> 'Bank-Token-Wert-2p5',
    'the email-change token'    => str_repeat('ab12', 16),
    'the unsubscribe signature' => unsubscribe_signature($chef, 'newsletter'),
];
$csrfs = [];
visit('profile');
$csrfs[] = csrf();
send('password_change', ['current_password'=>$secrets['the current password'], 'password'=>$secrets['the new password'],
                         'password_confirm'=>$secrets['the new password']] + on_page('profile'));
ok(password_verify($secrets['the new password'], (string)scalar('SELECT password_hash FROM accounts WHERE id=?', [$chef])),
   'the password really was changed, so this is the form that succeeds');
$csrfs[] = csrf();   // signing in again issued a new one
send('email_change', ['email'=>'neu@beispiel.test', 'password'=>$secrets['the new password']] + on_page('profile'));
visit('activate', ['token'=>$secrets['the email-change token']]);
visit('unsubscribe', ['account'=>$chef, 'category'=>'newsletter', 'signature'=>$secrets['the unsubscribe signature']]);
send('smtp_save', ['host'=>'smtp.beispiel.test', 'port'=>'587', 'encryption'=>'tls', 'username'=>'chefin',
                   'smtp_password'=>$secrets['the SMTP password'], 'from_email'=>'noreply@beispiel.test',
                   'from_name'=>'Verein'] + on_page('settings', 0, 'smtp'));
send('preferences_save', ['name'=>'Chefin', 'locale'=>'de', 'theme'=>'auto', 'accent'=>'', 'text_scale'=>'normal',
                          'neues_passwort'=>$secrets['a password field in German'], 'client_secret'=>$secrets['a client secret'],
                          'custom'=>['bank_token'=>$secrets['a token at the second level']]] + on_page('profile'));
visit('settings', ['tab'=>'smtp']);
send('feedback_send', ['message'=>'Die E-Mail kommt nicht an.', 'page'=>'settings'] + on_page('settings', 0, 'smtp'));
$raw = (string)scalar('SELECT context_json FROM feedback ORDER BY id DESC LIMIT 1');
$context = json_decode($raw, true);

/* Read before it is trusted. A trail that lost a step would pass every search
   below for the wrong reason: the value is not there because the step is not. */
$steps = $context['steps'] ?? [];
is_same(8, count($steps), 'the report carries all eight steps');
is_same(['password_change', 'email_change', 'smtp_save', 'preferences_save'],
        array_values(array_filter(array_map(fn($s) => $s['action'] ?? null, $steps))), 'including every form that carried a secret');
$urls = implode(' ', array_column($steps, 'url'));
ok(str_contains($urls, 'page=activate') && str_contains($urls, 'page=unsubscribe'), 'and both links that carried one');

foreach ($secrets as $what => $value)
    ok(!str_contains($raw, $value), $what.' is nowhere in the stored report');
foreach (array_unique($csrfs) as $i => $token)
    ok(!str_contains($raw, $token), 'form token '.($i + 1).' is nowhere in the stored report');

$byAction = [];
foreach ($steps as $s) if (isset($s['action'])) $byAction[$s['action']] = $s['fields'];
foreach (['current_password', 'password', 'password_confirm'] as $key)
    is_same('***', $byAction['password_change'][$key] ?? null, 'password_change: '.$key.' is ***');
foreach ($byAction as $action => $fields)
    ok(!array_key_exists('csrf', $fields), $action.': the form token is not recorded at all');
is_same('***', $byAction['email_change']['password'] ?? null, 'email_change: password is ***');
is_same('***', $byAction['smtp_save']['smtp_password'] ?? null, 'smtp_save: smtp_password is ***');
is_same('smtp.beispiel.test', $byAction['smtp_save']['host'] ?? null, 'while the server name beside it is kept');
is_same('***', $byAction['preferences_save']['neues_passwort'] ?? null, 'a field named in German is caught too');
is_same('***', $byAction['preferences_save']['client_secret'] ?? null, 'and a secret');
is_same('***', $byAction['preferences_save']['custom']['bank_token'] ?? null, 'and a token inside an array');
ok(str_contains($urls, 'token=***'), 'the link’s token is written as ***');
ok(str_contains($urls, 'signature=***') && str_contains($urls, 'category=newsletter'),
   'and the signature, with the rest of the address kept');

case_('The rule that keeps them out is the one that keeps a rejected form');
/* One rule, two users. remember_input() used to say str_contains($key,
   'password') on its own, so a new keyword added to one would have been missing
   from the other. */
foreach (['csrf', 'password', 'current_password', 'smtp_password', 'Passwort', 'reset_token', 'signature', 'client_secret'] as $key)
    is_same(true, is_secret_field($key), $key.' is secret');
foreach (['email', 'iban', 'bic', 'message', 'note', 'tokens_left_alone_in_a_word_like_this'] as $key)
    is_same($key === 'tokens_left_alone_in_a_word_like_this', is_secret_field($key),
            $key.($key === 'tokens_left_alone_in_a_word_like_this' ? ' contains "token", so it is masked rather than guessed about' : ' is not'));
$_POST = ['client_secret'=>'x', 'signature'=>'y', 'name'=>'Lena'] + on_page('profile');
remember_input('preferences_save');
is_same(['name'=>'Lena'], $_SESSION['form_input']['fields'], 'a rejected form keeps nothing is_secret_field() names');
unset($_SESSION['form_input']); $_POST = [];

case_('A malformed byte does not cost a report its context');
/* json_encode() returns false on one bad byte, and false went into the column
   as nothing: the report arrived with no device, no version and no steps. */
$encoded = feedback_context_json(['ip' => "203.0.113.\xFF", 'steps' => [['url' => '/index.php']]]);
$decoded = json_decode($encoded, true);
is_same('/index.php', $decoded['steps'][0]['url'] ?? null, 'the rest of the context survives');
ok(str_starts_with((string)($decoded['ip'] ?? ''), '203.0.113.'), 'and so does most of the damaged value');

// ---------------------------------------------------------------------------
case_('A report marked done forgets what was typed and keeps the way there');
$report = (int)scalar('SELECT MAX(id) FROM feedback');
$typed = ['smtp.beispiel.test', 'neu@beispiel.test', 'Verein'];
foreach ($typed as $value) ok(str_contains($raw, $value), 'before: the report holds "'.$value.'"');
act('feedback_state', ['id'=>(string)$report, 'state'=>'seen']);
is_same($raw, (string)scalar('SELECT context_json FROM feedback WHERE id=?', [$report]), '"Gesehen" changes nothing in it');
act('feedback_state', ['id'=>(string)$report, 'state'=>'done']);
$doneRaw = (string)scalar('SELECT context_json FROM feedback WHERE id=?', [$report]);
$done = json_decode($doneRaw, true);
foreach ($typed as $value) ok(!str_contains($doneRaw, $value), '"Erledigt": "'.$value.'" is gone');
is_same('done', (string)scalar('SELECT state FROM feedback WHERE id=?', [$report]), 'the report is done');
is_same(array_column($steps, 'url'), array_column($done['steps'], 'url'), 'every address is kept');
is_same(array_column($steps, 'method'), array_column($done['steps'], 'method'), 'and every method');
is_same(array_map(fn($s) => $s['action'] ?? null, $steps), array_map(fn($s) => $s['action'] ?? null, $done['steps']), 'and every action');
foreach ($steps as $i => $s) if (isset($s['fields'])) {
    is_same(array_keys($s['fields']), array_keys($done['steps'][$i]['fields']), ($s['action'] ?? '?').': the names of the fields are kept');
    is_same([], array_filter($done['steps'][$i]['fields'], fn($v) => $v !== null), ($s['action'] ?? '?').': and every value is null');
}
is_same($context['on'], $done['on'], 'the page it was filed from is kept');
is_same($context['user_agent'], $done['user_agent'], 'and the device');
ok(isset($done['values_dropped_at']), 'and it says when the values were dropped, so an empty field is not mistaken for one sent empty');
act('feedback_state', ['id'=>(string)$report, 'state'=>'new']);
is_same($doneRaw, (string)scalar('SELECT context_json FROM feedback WHERE id=?', [$report]), 'opening it again does not bring them back');
throws(fn() => act('feedback_state', ['id'=>'999999', 'state'=>'done']), 'a report that is not there says so', 'gibt es nicht');

// ---------------------------------------------------------------------------
case_('A report is deleted 30 days after it was marked done, with its screenshot');
/* The owner's rule. Nothing used to delete a report, so what somebody typed and
   a picture of their screen stayed for ever. Driven through prune_expired(),
   which the hourly background run and the console's nightly job both call. */
$daysAgo = fn(int $days) => gmdate('Y-m-d H:i:s', time() - $days * 86400);
$shot = function (): string {
    $name = bin2hex(random_bytes(16)).'.png';
    @mkdir(upload_dir('avatar'), 0775, true);
    file_put_contents(upload_dir('avatar').'/'.$name, 'png');
    touch(upload_dir('avatar').'/'.$name, time() - 40 * 86400);   // as old as the report
    return $name;
};
$filed = fn(string $state, array $context, string $screenshot = '') => fixture('feedback', [
    'account_id'=>null, 'page'=>'dashboard', 'message'=>'Alter Bericht', 'context_json'=>feedback_context_json($context),
    'screenshot_name'=>$screenshot, 'state'=>$state, 'created_at'=>$daysAgo(45)]);
$old       = $filed('done', ['done_at'=>$daysAgo(31), 'values_dropped_at'=>$daysAgo(31)], $oldShot = $shot());
$oldOnlyDropped = $filed('done', ['values_dropped_at'=>$daysAgo(31)]);   // done before done_at existed, perhaps more than once
$recent    = $filed('done', ['done_at'=>$daysAgo(29)], $recentShot = $shot());
$open      = $filed('new', [], $openShot = $shot());
$reopened  = $filed('seen', ['done_at'=>$daysAgo(40), 'values_dropped_at'=>$daysAgo(40)]);
$doneTwice = $filed('done', ['done_at'=>$daysAgo(40), 'values_dropped_at'=>$daysAgo(40)]);
$undated   = $filed('done', ['steps'=>[]]);   // marked done before any done time was kept
act('feedback_state', ['id'=>(string)$doneTwice, 'state'=>'new']);
act('feedback_state', ['id'=>(string)$doneTwice, 'state'=>'done']);
$still = fn(int $id) => (int)scalar('SELECT COUNT(*) FROM feedback WHERE id=?', [$id]) === 1;
ok($still($old) && is_file(upload_dir('avatar').'/'.$oldShot), 'before: the 31-day-old report and its screenshot are there');

prune_expired();
ok(!$still($old), 'a report done 31 days ago is gone');
ok(!is_file(upload_dir('avatar').'/'.$oldShot), 'and so is its screenshot');
ok($still($oldOnlyDropped), 'one with only the time its values were dropped is not counted from that, which is only the first time');
ok((json_decode((string)scalar('SELECT context_json FROM feedback WHERE id=?', [$oldOnlyDropped]), true)['done_at'] ?? '') >= $daysAgo(0),
   'it is given a done time now, like any report without one');
ok($still($recent), 'one done 29 days ago stays');
ok(is_file(upload_dir('avatar').'/'.$recentShot), 'with its screenshot');
ok($still($open) && is_file(upload_dir('avatar').'/'.$openShot), 'an open report stays, however old, and its screenshot');
ok($still($reopened), 'a report opened again stays, although it was once done 40 days ago');
ok($still($doneTwice), 'one done again today counts from today, not from the first time');
ok($still($undated), 'a done report with no done time is not guessed about');
$stamped = json_decode((string)scalar('SELECT context_json FROM feedback WHERE id=?', [$undated]), true);
ok(($stamped['done_at'] ?? '') >= $daysAgo(0), 'it is given one now, so it goes a full 30 days from here');
is_same([], $stamped['steps'] ?? null, 'and the rest of it is kept as it was');
is_same(false, isset($stamped['values_dropped_at']), 'without claiming its values were dropped');
// The upload folder is shared by every suite in a run; a picture left here
// would be counted by the next suite's sweep as one it did not expect.
foreach ([$recentShot, $openShot] as $name) @unlink(upload_dir('avatar').'/'.$name);
run('DELETE FROM feedback WHERE id IN (?,?,?,?,?,?)', [$oldOnlyDropped, $recent, $open, $reopened, $doneTwice, $undated]);
