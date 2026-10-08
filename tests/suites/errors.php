<?php
/**
 * Errors that report themselves (ADR 0012).
 *
 * The router, the background work and the fatal-error handler each hand an
 * unexpected error to capture_error(). Where that happens is pinned by the
 * structure suite; this one throws real errors at capture_error() the way those
 * places do and reads back what was stored, what the administrators were told,
 * what went to the log, and what the text for support gives away.
 */
$admin   = make_account(['role'=>'admin', 'name'=>'Chefin']);
$second  = make_account(['role'=>'admin', 'name'=>'Stellvertretung']);
$trainer = make_account(['role'=>'trainer']);
$family  = make_account(['role'=>'student', 'name'=>'Familie Hofer']);
$admins  = 2;

$previousLog = ini_get('error_log');
$logFile = test_run_dir().'/errors-suite.log';
ini_set('error_log', $logFile);
/** What the log gained while $fn ran. */
$logged = function (callable $fn) use ($logFile): string {
    clearstatcache();
    $before = is_file($logFile) ? filesize($logFile) : 0;
    $fn();
    clearstatcache();
    return is_file($logFile) ? (string)substr((string)file_get_contents($logFile), $before) : '';
};
$automatic = fn(): array => rows('SELECT * FROM feedback WHERE account_id IS NULL ORDER BY id');
$told = fn(): int => (int)scalar("SELECT COUNT(*) FROM notifications WHERE kind='problem'");
/** A request, as far as capture_error() can tell: the router's globals and its recorded step. */
$request = function (string $page, array $query = [], string $method = 'GET', array $post = []) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $method === 'GET' ? '/index.php?'.http_build_query(['page'=>$page] + $query) : '/index.php';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
    $_GET = $method === 'GET' ? ['page'=>$page] + $query : [];
    $_POST = $post;
    $GLOBALS['page'] = $page;
    record_step($page);
    error_capture_reset();
};
/** Throw from one fixed place, so repeats are the same error. */
$boom = fn(string $message, int $code = 0) => throw new RuntimeException($message, $code);
$boomLine = __LINE__ - 1;
$capture = function (callable $fn, string $where = 'request') {
    try { $fn(); } catch (Throwable $e) { capture_error($e, $where); }
};

case_('An unexpected error in a page is written down once, with where it happened and nothing typed');
sign_in_as($family);
$request('dashboard');
$request('students', ['q'=>'Lena Hofer']);
$capture(fn() => $boom('Die Liste für familie.hofer@beispiel.test ist kaputt, Kundennummer 123456789'));
$rows = $automatic();
is_same(1, count($rows), 'one automatic entry');
$row = $rows[0];
$c = json_decode((string)$row['context_json'], true);
is_same(null, $row['account_id'], 'belonging to no account, because it gathers everybody who hits it');
is_same('new', $row['state'], 'new, so it is counted as unread');
is_same('students', $row['page'], 'on the page it happened on');
ok(str_contains((string)$row['message'], 'students') && str_contains((string)$row['message'], 'RuntimeException'),
   'with a short line naming the page and the kind of error');
ok(str_starts_with((string)$row['context_json'], ERROR_CONTEXT_PREFIX.$c['fingerprint'].'"'),
   'its context begins the one way that marks it as automatic');
is_same($c, automatic_error_context($row), 'and automatic_error_context() recognises it');
is_same(['RuntimeException', 'tests/suites/errors.php', $boomLine], [$c['class'], $c['file'], $c['line']],
        'the class, and the file and line relative to the portal');
ok(is_array($c['frames']) && $c['frames'] !== [] && count($c['frames']) <= 20, 'up to twenty frames');
is_same([], array_values(array_filter($c['frames'], fn($f) => array_keys($f) !== ['file','line','class','type','function'])),
        'each only file, line, class, type and function - never the arguments');
is_same([app_version(), PHP_VERSION, 'request'], [$c['version'], $c['php'], $c['where']], 'the version, PHP and where');
is_same([1, $c['first_seen']], [$c['count'], $c['last_seen']], 'seen once, first and last at the same moment');
is_same(false, str_contains((string)$row['context_json'], 'familie.hofer@beispiel.test'), 'the email address in the message is not kept');
is_same(false, str_contains((string)$row['context_json'], '123456789'), 'nor the long number');
ok(str_contains($c['message'], 'Die Liste für'), 'while the rest of the message is');
is_same('student', $c['role'], 'the role that was signed in');
is_same($family, $c['account'], 'and which account, for the page inside the portal');
is_same(2, count($c['steps']), 'the steps that led there');
is_same($admins, $told(), 'and every administrator is told, once');
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
ok(preg_match('/\} catch\(Throwable \$ex\) \{.*?http_response_code\(503\);.*?if\(function_exists\(\'capture_error\'\)\)capture_error\(\$ex\);.*?echo \'<!doctype html>.*?vorübergehend nicht verfügbar/s', $router) === 1,
   'the router captures in its last catch and still sends the friendly page after it');
/* The page itself is reached by the browser walk, which takes a table away from
   under a signed-in family (tests/e2e.sh, „an unexpected error"); here it is
   read where it is written. */
ok(preg_match('/http_response_code\(503\);.*?echo \'<!doctype html>[^\']*<meta name="color-scheme" content="light dark">[^\']*<title>/s', $router) === 1,
   'and that page is drawn for light and dark alike, before its title, so it is not white on a phone in dark mode');

case_('A second error in the same request is not written down');
// Nearly always the first one's consequence, and the first is the one to read.
$capture(fn() => $boom('Folgefehler'));
is_same(1, (int)json_decode((string)$automatic()[0]['context_json'], true)['count'], 'the entry still counts one');
is_same(1, count($automatic()), 'and there is no second entry');

case_('The same error again counts up the same entry, quietly');
$id = (int)$row['id'];
// An hour earlier, so "later" can be read off rather than hoped for.
$c['first_seen'] = $c['last_seen'] = gmdate('Y-m-d H:i:s', time() - 3600);
run('UPDATE feedback SET context_json=? WHERE id=?', [feedback_context_json($c), $id]);
$request('students', ['q'=>'Hofer']);
$capture(fn() => $boom('Die Liste ist wieder kaputt'));
$rows = $automatic();
is_same(1, count($rows), 'still one entry');
$again = json_decode((string)$rows[0]['context_json'], true);
is_same(2, $again['count'], 'counted twice');
ok($again['last_seen'] > $c['last_seen'], 'seen last just now');
is_same($c['first_seen'], $again['first_seen'], 'and first when it first happened');
ok(str_contains($again['message'], 'wieder'), 'the last occurrence replaces the one before');
is_same($admins, $told(), 'and nobody is told a second time: a count going up is not news');

case_('An error that comes back after it was marked done opens its entry again, and says so');
sign_in_as($admin);
act('feedback_state', ['id'=>(string)$id, 'state'=>'done']);
$done = one('SELECT * FROM feedback WHERE id=?', [$id]);
ok(str_starts_with((string)$done['context_json'], ERROR_CONTEXT_PREFIX), 'marking it done keeps how it is recognised');
$doneContext = json_decode((string)$done['context_json'], true);
ok(isset($doneContext['done_at'], $doneContext['values_dropped_at']), 'and drops what was typed, as for any report');
sign_in_as($family);
$request('students');
$capture(fn() => $boom('Und noch einmal'));
$rows = $automatic();
is_same(1, count($rows), 'the same entry, not a second one');
$back = json_decode((string)$rows[0]['context_json'], true);
is_same(['new', 3], [$rows[0]['state'], $back['count']], 'new again, counted three times');
ok(!isset($back['done_at']) && !isset($back['values_dropped_at']), 'with the new steps, whose values are there again');
is_same($admins * 2, $told(), 'and every administrator is told once more');

case_('A refusal is not an error');
$before = count($automatic());
foreach (['a UserError' => new UserError('Bitte alle Pflichtfelder korrekt ausfüllen.'),
          'NotFound' => new NotFound('Kurs nicht gefunden.')] as $what => $refusal) {
    error_capture_reset();
    $log = $logged(fn() => capture_error($refusal));
    is_same($before, count($automatic()), $what.' writes nothing down');
    is_same('', $log, 'and logs nothing');
}
sign_in_as($trainer);
error_capture_reset();
$capture(fn() => require_admin());
is_same($before, count($automatic()), 'nor does the „Nur für Administratoren“ the router answers with 403');
error_capture_reset();
$token = bin2hex(random_bytes(32));
claim_request($token);
$capture(fn() => claim_request($token));
is_same($before, count($automatic()), 'nor a form sent twice, which is handled');
ok(!str_contains($router, 'form_requests'),
   'and the router’s database catch keeps no second copy of it: claim_request() answers a form sent twice before the database error could reach it');

case_('A database error keeps its codes and never its message');
sign_in_as($family);
run('DELETE FROM feedback WHERE account_id IS NULL');
run('INSERT INTO accounts (name,email,role,created_at) VALUES (?,?,?,?)', ['Doppelt', 'doppelt.familie@beispiel.test', 'student', now()]);
$request('profile');
$raised = null;
try { run('INSERT INTO accounts (name,email,role,created_at) VALUES (?,?,?,?)', ['Doppelt', 'doppelt.familie@beispiel.test', 'student', now()]); }
catch (PDOException $e) { $raised = $e; capture_error($e); }
ok($raised instanceof PDOException, 'the database refused the second address');
$stored = (string)(one('SELECT context_json FROM feedback WHERE account_id IS NULL')['context_json'] ?? '');
$c = json_decode($stored, true);
is_same(false, str_contains($stored, 'doppelt.familie@beispiel.test'), 'the address the database quoted is not kept');
is_same(false, str_contains($stored, json_encode(mb_substr($raised?->getMessage() ?? 'x', 0, 40), JSON_UNESCAPED_UNICODE) ?: 'x')
               || str_contains($stored, 'constraint') || str_contains($stored, 'Duplicate'), 'nor any of its message');
is_same(['23000', ''], [$c['sqlstate'] ?? null, $c['message'] ?? null], 'the SQLSTATE is, and the message is empty');
is_same(1062, $c['code'] ?? null, 'and the driver’s own code, 1062 for a duplicate');
// The control: the message really did carry the address, so leaving it out
// above is something the capture did and not something the engine spared it.
ok(str_contains($raised?->getMessage() ?? '', 'doppelt.familie@beispiel.test'), 'the database’s message named the address');

case_('A password posted at the moment of the error is kept nowhere');
run('DELETE FROM feedback WHERE account_id IS NULL');
$request('profile', [], 'POST', ['action'=>'password_change', 'csrf'=>csrf(), 'request_id'=>bin2hex(random_bytes(32)),
    'return_page'=>'profile', 'return_id'=>'0', 'return_tab'=>'',
    'current_password'=>'Alt-Geheim-2026!', 'password'=>'Neu-Geheim-2026!!', 'password_confirm'=>'Neu-Geheim-2026!!']);
$capture(fn() => $boom('Beim Speichern ging etwas schief'));
$entry = one('SELECT * FROM feedback WHERE account_id IS NULL');
foreach (['Alt-Geheim-2026!', 'Neu-Geheim-2026!!'] as $secret) {
    is_same(false, str_contains((string)$entry['context_json'], $secret), 'not in the stored context: '.$secret);
    is_same(false, str_contains(support_text($entry), $secret), 'not in the text for support: '.$secret);
}
ok(str_contains(support_text($entry), 'current_password'), 'while the text names the fields that were sent');
$_POST = [];

case_('The text for support names fields and never their values, nor anybody');
run('DELETE FROM feedback WHERE account_id IS NULL');
$request('students', ['q'=>'Lena Hofer', 'course'=>'3']);
$request('student', [], 'POST', ['action'=>'student_save', 'csrf'=>csrf(), 'request_id'=>bin2hex(random_bytes(32)),
    'return_page'=>'student', 'return_id'=>'5', 'return_tab'=>'',
    'id'=>'5', 'first_name'=>'Lena', 'last_name'=>'Hofer', 'email'=>'lena.hofer@beispiel.test']);
$capture(fn() => $boom('Speichern von lena.hofer@beispiel.test fehlgeschlagen'));
$entry = one('SELECT * FROM feedback WHERE account_id IS NULL');
$text = support_text($entry);
ok($text !== '' && str_contains($text, 'RuntimeException') && str_contains($text, 'tests/suites/errors.php:'.$boomLine),
   'it says what broke and where');
ok(str_contains($text, 'first_name') && str_contains($text, 'last_name') && str_contains($text, 'email'), 'it names the fields');
foreach (['Lena', 'Hofer', 'lena.hofer@beispiel.test', '203.0.113.77'] as $never)
    is_same(false, str_contains($text, $never), 'and never contains '.$never);
ok(str_contains($text, 'q=…') && str_contains($text, 'course=…') && str_contains($text, 'page=students'),
   'a search term is …, while the page is still named');
ok(str_contains($text, 'Mozilla/5.0'), 'the device is there');
is_same('', support_text(['account_id'=>$family, 'context_json'=>'{"page":"students"}']), 'a report a person wrote has none');
is_same(null, automatic_error_context(['account_id'=>null, 'context_json'=>'{"page":"x","kind":"error","fingerprint":"abc"}']),
        'and a person’s report is never taken for an automatic one, whatever it says');
$_POST = [];

case_('Nobody signed in: no steps, no account, and never the IP address');
run('DELETE FROM feedback WHERE account_id IS NULL');
sign_out();
$request('privacy');
$capture(fn() => $boom('Auf einer öffentlichen Seite'));
$entry = one('SELECT * FROM feedback WHERE account_id IS NULL');
$c = json_decode((string)$entry['context_json'], true);
is_same([[], null, null], [$c['steps'], $c['account'], $c['role']], 'no steps, no account and no role');
is_same(null, $entry['account_id'], 'and no account on the entry');
is_same(false, str_contains((string)$entry['context_json'], '203.0.113.77'), 'the IP address is nowhere in it');

case_('When the database cannot be written to, the error goes to the log and nothing throws');
sign_in_as($family);
run('DELETE FROM feedback WHERE account_id IS NULL');
$request('students');
$GLOBALS['crm_db_opened'] = false;
$queries = 0; $log = '';
does_not_throw(function () use (&$queries, &$log, $logged, $capture, $boom) {
    $log = $logged(function () use (&$queries, $capture, $boom) { $queries = query_count(fn() => $capture(fn() => $boom('Vor jeder Verbindung'))); });
}, 'with no connection ever opened, capturing does not throw');
$GLOBALS['crm_db_opened'] = true;
is_same(0, $queries, 'and does not open one to write with');
ok(str_contains($log, 'RuntimeException'), 'the log has it');
is_same([], $automatic(), 'and nothing was written');

touch(maintenance_file());
$request('students');
$log = $logged(fn() => $capture(fn() => $boom('Während der Wartung')));
@unlink(maintenance_file());
ok(str_contains($log, 'RuntimeException'), 'during maintenance the log has it');
is_same([], $automatic(), 'and the database is left alone, as it may be mid-update');

foreach (['gone away (2006)' => ['HY000', 2006, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
          'lost (2013)'      => ['HY000', 2013, 'SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query'],
          'refused (2002)'   => [null, null, 'SQLSTATE[HY000] [2002] Connection refused'],
          'class 08'         => ['08S01', 1047, 'SQLSTATE[08S01]: Communication link failure: 1047 Unknown command']] as $what => [$state, $code, $message]) {
    $request('students');
    $gone = new PDOException($message);
    if ($state !== null) $gone->errorInfo = [$state, $code, $message];
    $log = $logged(fn() => capture_error($gone));
    ok(str_contains($log, 'PDOException'), 'a connection '.$what.' is logged');
    is_same([], $automatic(), 'and not written with the connection that failed');
}

$request('students');
run('ALTER TABLE feedback RENAME TO feedback_away');
try {
    $log = '';
    does_not_throw(function () use (&$log, $logged, $capture, $boom) { $log = $logged(fn() => $capture(fn() => $boom('Ohne Tabelle'))); },
                   'a capture whose own write fails does not throw');
} finally { run('ALTER TABLE feedback_away RENAME TO feedback'); }
ok(str_contains($log, 'RuntimeException') && str_contains($log, 'could not be written down'), 'and the log has both the error and that');
is_same(0, tx_depth(), 'and it leaves no transaction behind');

case_('Fifty errors waiting is enough; after that only the ones already there count up');
run('DELETE FROM feedback WHERE account_id IS NULL');
$numbered = fn(int $i) => throw new LogicException('Fehler Nummer '.$i, $i);
for ($i = 1; $i <= ERROR_OPEN_MAX; $i++) { $request('students'); $capture(fn() => $numbered($i)); }
is_same(ERROR_OPEN_MAX, count($automatic()), 'fifty distinct errors are fifty entries');
$request('students');
$log = $logged(fn() => $capture(fn() => $numbered(ERROR_OPEN_MAX + 1)));
is_same(ERROR_OPEN_MAX, count($automatic()), 'the fifty-first is not stored');
ok(str_contains($log, 'LogicException') && str_contains($log, 'only in the log'), 'it is in the log, which says why');
$request('students');
$capture(fn() => $numbered(7));
is_same(2, max(array_map(fn($r) => (int)json_decode((string)$r['context_json'], true)['count'], $automatic())),
        'while one of the fifty still counts up');

case_('An error nothing has repeated for thirty days is deleted, whatever its state, and no report with it');
run('DELETE FROM feedback');
$ago = fn(int $days) => gmdate('Y-m-d H:i:s', time() - $days * 86400);
$filed = fn(string $state, int $days, ?int $account = null, ?array $context = null) => fixture('feedback', [
    'account_id'=>$account, 'page'=>'students', 'message'=>'x', 'screenshot_name'=>'', 'state'=>$state, 'created_at'=>$ago($days),
    'context_json'=>feedback_context_json($context ?? ['kind'=>'error', 'fingerprint'=>sha1(uniqid('', true)), 'count'=>1,
                                                        'first_seen'=>$ago($days), 'last_seen'=>$ago($days)])]);
$quietNew  = $filed('new', 31);
$quietSeen = $filed('seen', 31);
$quietDone = $filed('done', 31);
$recent    = $filed('new', 29);
$person    = $filed('new', 40, $family, ['page'=>'students', 'steps'=>[]]);
$personGone = $filed('new', 40, null, ['page'=>'students', 'steps'=>[]]);   // a report whose account was deleted since
is_same(3, prune_quiet_errors(), 'three quiet errors are deleted');
$left = array_map('intval', array_column(rows('SELECT id FROM feedback ORDER BY id'), 'id'));
is_same([$recent, $person, $personGone], $left, 'new, seen and done alike, while one seen 29 days ago stays, and so does every report a person wrote');
ok(preg_match('/function prune_expired\(\): void \{[^}]*prune_quiet_errors\(\);/s', (string)file_get_contents(APP_ROOT.'/app/tick.php')) === 1,
   'and the nightly clean-up runs it');

case_('The background work is captured as such, with no steps');
run('DELETE FROM feedback');
sign_in_as($admin);
$request('dashboard');
set_setting('prune_last_run', '');
run('ALTER TABLE form_requests RENAME TO form_requests_away');
try { tick_work(); } finally { run('ALTER TABLE form_requests_away RENAME TO form_requests'); }
$entry = one('SELECT * FROM feedback WHERE account_id IS NULL');
$c = json_decode((string)($entry['context_json'] ?? '{}'), true);
is_same('background', $c['where'] ?? null, 'a job that failed after the page was sent is marked as background work');
is_same(['', [], '', null], [$entry['page'] ?? null, $c['steps'] ?? null, $c['url'] ?? null, $c['role'] ?? null],
        'with no page, no steps, no address and no role: it belonged to no request');
is_same('PDOException', $c['class'] ?? null, 'it is the error the job actually had');

case_('A fatal error is captured; a warning is not');
run('DELETE FROM feedback');
$request('students');
capture_fatal_error(['type'=>E_WARNING, 'message'=>'Undefined variable $x', 'file'=>APP_ROOT.'/views/students.php', 'line'=>3]);
is_same([], $automatic(), 'a warning is noise at this volume');
capture_fatal_error(['type'=>E_ERROR, 'message'=>'Maximum execution time of 30 seconds exceeded',
                     'file'=>realpath(APP_ROOT.'/app/mail.php'), 'line'=>397]);
$entry = one('SELECT * FROM feedback WHERE account_id IS NULL');
$c = json_decode((string)($entry['context_json'] ?? '{}'), true);
is_same(['ErrorException', 'app/mail.php', 397, []], [$c['class'] ?? null, $c['file'] ?? null, $c['line'] ?? null, $c['frames'] ?? null],
        'a timeout is written down where it happened, without the handler’s own frames');
ok(str_contains((string)($c['message'] ?? ''), 'Maximum execution time'), 'saying what it was');
ok(preg_match('/boot_http\(\);\s*(?:\/\/[^\n]*\n\s*)*register_shutdown_function\(capture_fatal_error\(\.\.\.\)\);/', $router) === 1,
   'and the router registers the handler straight after boot_http()');

case_('A database error wrapped in another keeps nothing of either message');
/* The class names what broke; a wrapper that quotes the database's refusal - or
   says whose record it was - would carry both into a text that leaves the club. */
run('DELETE FROM feedback');
sign_in_as($admin);
$request('student');
$inner = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'lena.hofer@beispiel.test' for key 'email'");
$inner->errorInfo = ['23000', 1062, "Duplicate entry 'lena.hofer@beispiel.test' for key 'email'"];
$logLine = $logged(fn() => capture_error(new RuntimeException('Lena Hofer konnte nicht gespeichert werden: '.$inner->getMessage(), 0, $inner)));
$entry = one('SELECT * FROM feedback WHERE account_id IS NULL');
foreach (['lena.hofer@beispiel.test', 'Lena Hofer', 'Duplicate entry'] as $never) {
    is_same(false, str_contains((string)$entry['context_json'], $never), 'not in the stored context: '.$never);
    is_same(false, str_contains(support_text($entry), $never), 'not in the text for support: '.$never);
    is_same(false, str_contains($logLine, $never), 'not in the log: '.$never);
}
ok(str_contains($logLine, 'SQLSTATE 23000') && str_contains($logLine, '1062'), 'the log says what it was by its codes');
run('DELETE FROM feedback');
$request('student');
$request('student', [], 'GET');
$capture(function () { $typed = fn(int $n): int => $n; return $typed('zwölf'); });
$entry = one('SELECT * FROM feedback WHERE account_id IS NULL');
$text = support_text($entry);
ok(str_contains($text, t('Meldung: ', 'Message: ')) && str_contains($text, 'must be of type int'), 'a TypeError’s own wording is passed on');
is_same(false, str_contains($text, (string)realpath(APP_ROOT)) || str_contains((string)$entry['context_json'], (string)realpath(APP_ROOT)),
        'without the portal’s folder in any path');
run('DELETE FROM feedback');
$request('students');
$capture(fn() => $boom('Kaputt bei Familie Hofer'));
is_same(false, str_contains(support_text(one('SELECT * FROM feedback WHERE account_id IS NULL')), 'Hofer'),
        'while any other message is kept in the portal and left out of the text for support');

case_('A message is scrubbed of addresses, numbers, tokens and the portal’s own secrets');
$scrubbed = error_message_scrub('Mail an eltern@beispiel.test mit IBAN AT611904300234573201 und Token '
    .str_repeat('a1', 15).' für Rechnung 2026-000123 mit Schlüssel '.config('app_key').' und 12345 Kinder');
foreach (['eltern@beispiel.test', 'AT611904300234573201', str_repeat('a1', 15), '000123', config('app_key')] as $gone)
    is_same(false, str_contains($scrubbed, $gone), 'gone: '.$gone);
ok(str_contains($scrubbed, 'Mail an') && str_contains($scrubbed, '12345 Kinder'), 'the words and short numbers stay');
is_same(300, mb_strlen(error_message_scrub(str_repeat('Wort ', 200))), 'and it is cut to 300 characters');

ini_set('error_log', (string)$previousLog);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = []; $_POST = [];
unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

