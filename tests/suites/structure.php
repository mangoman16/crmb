<?php
/**
 * Structural checks.
 *
 * These exist because an automated edit once truncated an entire dispatcher and
 * the behavioural suites did not notice: nothing they covered went through that
 * file. Counting what must be present is cheap and catches a whole class of
 * damage that reading a diff can miss.
 */

case_('Every entry point and module parses and is substantial');
$expected = [
    'app/actions.php' => 120, 'app/actions_config.php' => 200, 'app/actions_messages.php' => 40,
    'app/actions_settings.php' => 80, 'app/attendance.php' => 40, 'app/auth.php' => 30,
    'app/billing.php' => 60, 'app/bootstrap.php' => 20, 'app/classes.php' => 40,
    'app/core.php' => 60, 'app/defaults.php' => 80, 'app/domain.php' => 50,
    'app/history.php' => 80, 'app/mail.php' => 50, 'app/qr.php' => 20,
    'app/groups.php' => 60, 'app/tx.php' => 40, 'app/ui.php' => 30,
    'app/validate.php' => 40, 'public/index.php' => 30, 'bin/console.php' => 60,
    'app/install.php' => 150, 'app/schema.php' => 150, 'app/tick.php' => 80,
    'app/backup.php' => 100, 'public/setup.php' => 180,
];
foreach ($expected as $file => $minLines) {
    $path = APP_ROOT.'/'.$file;
    ok(is_file($path), $file.' exists');
    $lines = is_file($path) ? substr_count((string)file_get_contents($path), "\n") : 0;
    ok($lines >= $minLines, $file.' has at least '.$minLines.' lines (has '.$lines.')');
}

case_('Every POST action the interface offers is dispatched somewhere');
$dispatched = [];
foreach (['actions','actions_settings','actions_messages','actions_config'] as $file)
    if (preg_match_all("/case '([a-z_]+)':/", (string)file_get_contents(APP_ROOT.'/app/'.$file.'.php'), $m))
        $dispatched = array_merge($dispatched, $m[1]);
$offered = [];
foreach (glob(APP_ROOT.'/views/*.php') as $view)
    if (preg_match_all("/start_form\('([a-z_]+)'/", (string)file_get_contents($view), $m))
        $offered = array_merge($offered, $m[1]);
$offered = array_values(array_unique($offered));
ok(count($offered) > 20, 'the views offer a realistic number of actions ('.count($offered).')');
foreach ($offered as $action)
    ok(in_array($action, $dispatched, true), 'the form action "'.$action.'" has a handler');

case_('Every dispatched action is reachable from the interface');
foreach (array_unique($dispatched) as $action)
    ok(in_array($action, $offered, true), 'the handler "'.$action.'" is offered by some view');

case_('Every page the router allows has a view file');
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
preg_match("/\\\$allowed=\[([^\]]*)\]/", $router, $m);
ok(isset($m[1]), 'the allow-list is found');
foreach (array_map(fn($p) => trim($p, " '"), explode(',', $m[1] ?? '')) as $page) {
    if ($page === '') continue;
    ok(is_file(APP_ROOT.'/views/'.$page.'.php'), 'views/'.$page.'.php exists');
}

case_('No view sets an inline style, because the browser refuses to apply it');
// style-src is 'self', so a style attribute is not a shortcut - it is markup
// that silently does nothing. The colour picker spent a release with eight grey
// dots for exactly this reason, and nothing in the test suite could see it.
foreach (array_merge(glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php')) as $file) {
    $body = (string)file_get_contents($file);
    ok(!preg_match('/\sstyle\s*=\s*["\']/', $body), basename($file).' sets no style attribute');
}
ok(str_contains((string)file_get_contents(APP_ROOT.'/app/bootstrap.php'), "style-src 'self'"),
   'and the policy that makes that true is still in place');

case_('Every page the router allows is classified: public, everyone, staff or admin');
// The guard lives in the router rather than in the view, so adding a page to
// the allow-list and forgetting the other three lists is all it takes to serve
// the trainer's payments screen to a family. This is the list, written down
// once: a new page fails here until somebody says who may open it.
$expected = [
    'login' => 'public', 'forgot' => 'public', 'activate' => 'public',
    'unsubscribe' => 'public', 'privacy' => 'public',
    'dashboard' => 'everyone', 'students' => 'everyone', 'student' => 'everyone',
    'messages' => 'everyone', 'news' => 'everyone', 'profile' => 'everyone',
    'download' => 'everyone',   // decides per file, inside serve_download()
    'accounts' => 'staff', 'payments' => 'staff', 'compose' => 'staff', 'outbox' => 'staff',
    'classes' => 'staff', 'manage' => 'staff', 'invoices' => 'staff', 'attendance' => 'staff',
    'settings' => 'admin', 'history' => 'admin',
];
$list = function (string $pattern) use ($router): array {
    preg_match($pattern, $router, $found);
    return array_values(array_filter(array_map(fn($p) => trim($p, " '"), explode(',', $found[1] ?? ''))));
};
$allowed = $list("/\\\$allowed=\[([^\]]*)\]/");
$publicPages = $list("/\\\$public=in_array\(\\\$page,\[([^\]]*)\]/");
$staffPages  = $list("/in_array\(\\\$page,\[([^\]]*)\],true\)\)require_staff/");
$adminPages  = $list("/in_array\(\\\$page,\[([^\]]*)\],true\)\)require_admin/");
ok($allowed && $publicPages && $staffPages && $adminPages, 'all four lists were found in the router');
foreach ($allowed as $page) {
    $actual = in_array($page, $adminPages, true) ? 'admin'
        : (in_array($page, $staffPages, true) ? 'staff'
        : (in_array($page, $publicPages, true) ? 'public' : 'everyone'));
    is_same($expected[$page] ?? 'UNCLASSIFIED', $actual, $page.' is open to: '.$actual);
}
foreach (array_keys($expected) as $page)
    ok(in_array($page, $allowed, true) || $page === 'not_found', $page.' is still a page the router knows');

case_('Every migration parses into statements');
foreach (glob(APP_ROOT.'/database/migrations/*.sql') as $file)
    ok(count(split_sql((string)file_get_contents($file))) > 0, basename($file).' contains statements');

case_('Every function called in the application is defined');
$defined = []; $called = []; $guarded = [];
$files = array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'),
                     glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/database/*.php'));
foreach ($files as $file) {
    // Some functions exist only in one PHP SAPI. Calling one behind its own
    // function_exists() check is correct, and this process is not necessarily
    // running the SAPI that has it, so the guard is what makes it defined here.
    if (preg_match_all("/function_exists\(\s*'([a-z_][a-z0-9_]*)'/i", (string)file_get_contents($file), $m))
        foreach ($m[1] as $name) $guarded[strtolower($name)] = true;
    $tokens = array_values(array_filter(token_get_all((string)file_get_contents($file)),
        fn($x) => !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_FUNCTION) {
            $j = $i + 1;
            $amp = $tokens[$j] ?? null;
            if ($amp === '&' || (is_array($amp) && str_contains(token_name($amp[0]), 'AMPERSAND'))) $j++;
            if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $defined[strtolower($tokens[$j][1])] = true; continue; }
        }
        if (!is_array($token) || $token[0] !== T_STRING || ($tokens[$i+1] ?? null) !== '(') continue;
        $prev = $tokens[$i-1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION,
            T_NULLSAFE_OBJECT_OPERATOR, T_ATTRIBUTE, T_STRING], true)) continue;
        $called[strtolower($token[1])] = $file;
    }
}
$keywords = ['array','isset','unset','list','echo','print','exit','die','include','require','include_once',
             'require_once','eval','match','fn','static','int','float','string','bool','void','catch','if',
             'for','foreach','while','switch','elseif','and','or','xor','empty'];
foreach ($called as $name => $where) {
    if (isset($defined[$name]) || isset($guarded[$name]) || function_exists($name) || in_array($name, $keywords, true)) continue;
    ok(false, 'undefined function '.$name.'() called in '.basename($where));
}
ok(true, count($defined).' functions defined, '.count($called).' distinct call targets, all resolved');

case_('Nothing reaches the page unescaped');
/* An expression is safe when every part of it that can actually be printed is
   safe. A ternary prints one of its branches, never its condition, and a
   concatenation prints all of its operands, so the check walks the expression
   down to the parts that reach the page and inspects only those.
   Written as a block comment on purpose: PHP ends a // comment at a closing
   tag, so naming the tag in a line comment would end PHP mode mid-file. */

/** Split on a token at parenthesis depth zero, ignoring string literals. */
function split_top_level(string $expr, string $token): array {
    $parts = []; $buf = ''; $depth = 0; $quote = null;
    for ($i = 0; $i < strlen($expr); $i++) {
        $ch = $expr[$i];
        if ($quote !== null) { $buf .= $ch; if ($ch === $quote && $expr[$i-1] !== '\\') $quote = null; continue; }
        if ($ch === "'" || $ch === '"') { $quote = $ch; $buf .= $ch; continue; }
        if ($ch === '(' || $ch === '[') $depth++;
        if ($ch === ')' || $ch === ']') $depth--;
        if ($depth === 0 && $ch === $token[0] && substr($expr, $i, strlen($token)) === $token
            && !($token === '?' && substr($expr, $i, 2) === '??')
            && !($token === ':' && substr($expr, $i, 2) === '::')) {
            $parts[] = $buf; $buf = ''; $i += strlen($token) - 1; continue;
        }
        $buf .= $ch;
    }
    $parts[] = $buf;
    return $parts;
}

/** The sub-expressions of $expr whose value can end up on the page. */
function printable_parts(string $expr): array {
    $expr = trim($expr);
    $ternary = split_top_level($expr, '?');
    if (count($ternary) === 2) {                       // condition ? then : else
        $branches = split_top_level($ternary[1], ':');
        if (count($branches) === 2)
            return array_merge(printable_parts($branches[0]), printable_parts($branches[1]));
    }
    $concat = split_top_level($expr, '.');
    if (count($concat) > 1) {
        $out = [];
        foreach ($concat as $piece) $out = array_merge($out, printable_parts($piece));
        return $out;
    }
    return [$expr];
}

/* Only helpers that either escape their own output or can only return text the
   operator cannot influence. Anything returning a stored column or a setting
   belongs in a view wrapped in e(), not on this list: truncating a string with
   mb_substr() or looking a code up in a settings array does not make it safe. */
$escaping = ['e',                                                   // escapes
             'icon','link_button','qr_svg','progress_chart','avatar', // build their own markup and escape inside
             'money','number_format','count','ceil','floor','round','array_sum','plural',  // numbers
             'fmt_date','fmt_datetime',                             // formatted dates
             'role_label','entity_label'];                          // fixed sets in code
$numericVars = ['id','sid','absent','unreadTotal','pageNum','active','open','overdue','content','rate'];

/**
 * Every expression a file prints, whether written as a short-echo tag or an
 * echo statement.
 *
 * Tokenised rather than matched with a regular expression, because a regex
 * cannot tell a semicolon inside a string from the one ending the statement,
 * and the whole point of this rule is that it does not miss anything.
 */
function printed_expressions(string $src): array {
    $out = [];
    $tokens = token_get_all($src);
    for ($i = 0; $i < count($tokens); $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || ($t[0] !== T_ECHO && $t[0] !== T_OPEN_TAG_WITH_ECHO)) continue;
        $buf = ''; $depth = 0;
        for ($j = $i + 1; $j < count($tokens); $j++) {
            $u = $tokens[$j];
            if (is_array($u)) {
                if ($u[0] === T_CLOSE_TAG) break;
                $buf .= $u[1];
                continue;
            }
            if ($u === '(' || $u === '[') $depth++;
            if ($u === ')' || $u === ']') $depth--;
            if ($depth === 0 && ($u === ';' || $u === ',')) {
                if (trim($buf) !== '') $out[] = trim($buf);
                $buf = '';
                if ($u === ';') break;
                continue;
            }
            $buf .= $u;
        }
        if (trim($buf) !== '') $out[] = trim($buf);
    }
    return $out;
}

$offenders = []; $checked = 0;
// The installer prints before core.php exists, so it carries its own escape
// function. It is scanned by exactly the same rule, because "the page that runs
// before the application" is not a reason to be the one page that forgets.
$escaping[] = 'install_e';
$printing = array_merge(glob(APP_ROOT.'/views/*.php'), [APP_ROOT.'/public/setup.php']);
foreach ($printing as $view) {
    foreach (printed_expressions((string)file_get_contents($view)) as $expr) {
        $checked++;
        foreach (printable_parts($expr) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (preg_match('/^([\x27"]).*\1$/s', $part)) continue;                       // a literal
            if (preg_match('/^\$content$/', $part)) continue;                            // assembled, already escaped
            if (preg_match('/^\(int\)/', $part)) continue;                               // cast to a number
            if (preg_match('/^\$('.implode('|', $numericVars).')$/', $part)) continue;    // a counter
            if (preg_match('/^([a-z_]+)\s*\(/i', $part, $fn) && in_array(strtolower($fn[1]), $escaping, true)) continue;
            $offenders[] = basename($view).': '.preg_replace('/\s+/', ' ', mb_substr($part, 0, 70));
        }
    }
}
foreach (array_unique($offenders) as $o) ok(false, 'unescaped output — '.$o);
ok(true, 'checked '.$checked.' printed expressions across '.count($printing).' pages');

case_('Nothing outside public/ is reachable if the web root points at the project');
/* Shared hosting usually fixes the web root at the account's public_html with no
   way to move it, so the root .htaccess rewrites everything into public/. That
   is one layer; each directory denying itself is the layer that still holds
   when mod_rewrite is off, which is why both are checked. */
$rootAccess = (string)file_get_contents(APP_ROOT.'/.htaccess');
ok(str_contains($rootAccess, 'RewriteRule ^public/ - [L]'), 'a request already inside public/ is left alone, so this cannot loop');
ok(preg_match('/RewriteRule \^\(\.\*\)\$ public\/\$1/', $rootAccess) === 1, 'everything else is rewritten into public/');
ok(str_contains($rootAccess, 'Options -Indexes'), 'and the project root is not browsable');
foreach (['app', 'bin', 'config', 'database', 'docs', 'storage', 'tests', 'views'] as $directory) {
    $guard = APP_ROOT.'/'.$directory.'/.htaccess';
    ok(is_file($guard), $directory.'/.htaccess exists');
    ok(str_contains((string)@file_get_contents($guard), 'Require all denied'), $directory.'/ denies itself');
}
ok(!is_file(APP_ROOT.'/public/.htaccess') || !str_contains((string)file_get_contents(APP_ROOT.'/public/.htaccess'), 'Require all denied'),
   'public/ is the one directory that does not, because it is the portal');

case_('A database backup can never be served over the web');
/* Each file holds every family's data in the clear, plus the encrypted SMTP
   password. Three things have to be true at once, because this is the one folder
   where a single mistake is a full disclosure. */
ok(str_starts_with(backup_dir(), dirname(maintenance_file())),
   'backups live beside the maintenance flag, under storage/, not in public/');
ok(!str_contains(backup_dir(), APP_ROOT.'/public'), 'and never inside the web directory');
ok(str_contains((string)@file_get_contents(APP_ROOT.'/storage/.htaccess'), 'Require all denied'),
   'storage/ denies itself');
$source = (string)file_get_contents(APP_ROOT.'/app/backup.php');
ok(str_contains($source, "'/.htaccess'") && str_contains($source, 'Require all denied'),
   'and backup_database() writes a second deny file into the folder it creates');
ok(preg_match('/random_bytes\(\d+\)/', $source) === 1,
   'the file name carries random bytes, so a server that ever fails to deny the folder still cannot be walked');

case_('Every directory beside public/ is covered by that rule');
/* A directory added later with no .htaccess would be served in full on hosting
   without mod_rewrite. The list above is checked against what is actually there
   rather than trusted to have been kept up to date. */
foreach (glob(APP_ROOT.'/*', GLOB_ONLYDIR) as $directory) {
    $name = basename($directory);
    if (in_array($name, ['public', 'vendor'], true)) continue;   // the portal, and a build artefact
    ok(is_file($directory.'/.htaccess'), $name.'/ has a deny file');
}

case_('The commands that identify a release work before it is configured');
/* During an update you unpack a release and want to know which one it is
   before linking a configuration into it. These three must therefore answer
   without a database or a config file; everything else may reasonably refuse. */
$console = escapeshellarg(APP_ROOT.'/bin/console.php');
$bare = function (string $command) use ($console): array {
    // An empty CRM_CONFIG is the point: the release has not been configured yet.
    exec('CRM_CONFIG= '.escapeshellarg(PHP_BINARY).' '.$console.' '.escapeshellarg($command).' 2>&1', $out, $code);
    return ['out' => implode("\n", $out), 'code' => $code];
};
$version = $bare('version');
is_same(0, $version['code'], 'version exits cleanly');
is_same(trim((string)file_get_contents(APP_ROOT.'/VERSION')), trim($version['out']), 'version prints what VERSION says');

$help = $bare('help');
is_same(0, $help['code'], 'help exits cleanly');
ok(str_contains($help['out'], 'console.php'), 'help lists the commands');

$key = $bare('key');
is_same(0, $key['code'], 'key exits cleanly');
ok(strlen(base64_decode(trim($key['out']), true) ?: '') === 32, 'key prints 32 bytes of base64, the length seal() needs');

case_('Every command the help text lists is one the console handles');
$source = (string)file_get_contents(APP_ROOT.'/bin/console.php');
preg_match_all('/console\.php ([a-z][a-z:-]*)/', $help['out'], $listed);
preg_match_all('/\$command===\x27([a-z][a-z:-]*)\x27/', $source, $handled);
foreach (array_unique($listed[1]) as $name)
    ok(in_array($name, $handled[1], true), 'help lists "'.$name.'", and the console handles it');
foreach (array_unique($handled[1]) as $name) {
    // A help text that lists itself tells the reader nothing they have not
    // just demonstrated they know.
    if ($name === 'help') continue;
    ok(in_array($name, $listed[1], true), 'the console handles "'.$name.'", and help lists it');
}

case_('The rule for what counts as paid is written once');
/* Every balance, the overdue filter and the payments screen depend on it. A
   second copy is the one that gets forgotten when the rule changes, and the
   two then disagree about what a family owes. */
$spelled = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php')) as $file) {
    foreach (file($file) as $n => $line) {
        if (!preg_match('/confirmed_at\s+IS\s+NOT\s+NULL/i', $line)) continue;
        if (basename($file) === 'domain.php' && str_contains($line, 'sql_name')) continue;   // the definition
        $spelled[] = basename($file).':'.($n + 1);
    }
}
foreach ($spelled as $where) ok(false, 'the paid-payment rule is spelled out again at '.$where.' — use charge_paid_sql() or payment_counts_sql()');
ok(true, 'payment_counts_sql() is the only place the condition appears');

case_('charge_paid_sql() still produces the query it replaced');
is_same('COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.charge_id=c.id'
       .' AND p.confirmed_at IS NOT NULL AND p.voided=0),0)',
        charge_paid_sql(), 'unchanged from the hand-written version it replaced');

case_('A name interpolated into SQL cannot smuggle anything in');
/* Identifiers cannot be bound as parameters, so sql_name() is the one backstop
   for every table, column and alias the application builds itself. */
foreach (['c WHERE 1=1 --', 'p; DROP TABLE payments', 'a b', "a'b", '`a`', 'a)', '', '1abc', 'A', 'ä'] as $bad)
    throws(fn() => sql_name($bad), 'refused: '.var_export($bad, true));
foreach (['c', 'ch2', 'class_students', '_internal', 'a1_b2'] as $good)
    does_not_throw(fn() => sql_name($good), 'accepted: '.$good);
is_same('charges', sql_name('charges', 'table'), 'a valid name comes back unchanged');
$refusal = '';
try { sql_name('x y', 'column'); } catch (Throwable $e) { $refusal = $e->getMessage(); }
ok(str_contains($refusal, 'column'), 'the error says which kind of name was refused');
ok(str_contains($refusal, 'x y'), 'and which name it was');

case_('Callers route their identifiers through it');
throws(fn() => charge_paid_sql('c WHERE 1=1 --'), 'charge_paid_sql checks its alias');
throws(fn() => payment_counts_sql('p; DROP TABLE payments'), 'payment_counts_sql checks its alias');
throws(fn() => lock_row('students; DROP TABLE students', 1), 'lock_row checks its table');
does_not_throw(fn() => charge_paid_sql('ch2'), 'a digit in an alias is fine, which the old table check wrongly refused');
