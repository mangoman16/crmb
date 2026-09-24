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
    'app/duplicate.php' => 80,
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
// app/ as well as views/, because a form can be written out by a shared helper:
// duplicate_button() offers the same action from eight different lists, and the
// point of this rule is "every handler is reachable", not "every handler is
// spelled out in a view".
foreach (array_merge(glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/app/*.php')) as $file)
    if (preg_match_all("/start_form\('([a-z_]+)'/", (string)file_get_contents($file), $m))
        $offered = array_merge($offered, $m[1]);
$offered = array_values(array_unique($offered));
ok(count($offered) > 20, 'the views offer a realistic number of actions ('.count($offered).')');
foreach ($offered as $action)
    ok(in_array($action, $dispatched, true), 'the form action "'.$action.'" has a handler');

case_('Every dispatched action is reachable from the interface');
foreach (array_unique($dispatched) as $action)
    ok(in_array($action, $offered, true), 'the handler "'.$action.'" is offered by some view');

case_('The installer offers the example data and actually fills it');
/* An empty portal is unrecognisable: no courses, no children, every page an
   empty state. The offer has to be on the form and the call has to be in the
   handler - a checkbox that posts a value nothing reads is worse than none. */
$setup = (string)file_get_contents(APP_ROOT.'/public/setup.php');
ok(str_contains($setup, 'name="demo_fill"'), 'the setup form has the box');
ok(str_contains($setup, 'demo_fill()'), 'and the handler calls the function behind it');
ok(str_contains($setup, "isset(\$_POST['demo_fill'])"), 'reading what that box posts');
// A fill that fails must not fail the install: the portal is up either way.
ok(preg_match('/try \{ \$demo = demo_fill\(\); \}\s*catch/', $setup) === 1,
   'and a failed fill is caught, because the portal is installed either way');

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
    'print' => 'staff',
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
             'sidebar_nav','time_cells','select_options',           // build their own markup and escape inside
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

/**
 * The named pieces of one PHP file: one entry per function and per action
 * handler, so a rule can name the handler that is wrong rather than the file it
 * sits in.
 */
function named_blocks_of(string $path): array {
    $blocks = []; $name = basename($path).' (file)'; $buffer = '';
    foreach (file($path) as $line) {
        if (preg_match('/^\s*function\s+([a-z_][a-z0-9_]*)\s*\(/i', $line, $m)
         || preg_match("/^\s*case\s+'([a-z0-9_]+)'\s*:/", $line, $m)) {
            $blocks[$name] = ($blocks[$name] ?? '').$buffer;
            $buffer = ''; $name = $m[1];
        }
        $buffer .= $line;
    }
    $blocks[$name] = ($blocks[$name] ?? '').$buffer;
    return $blocks;
}

/**
 * The SQL a stretch of PHP hands to the database.
 *
 * Tokenised rather than matched with a regular expression: a statement written
 * with double quotes can hold an apostrophe - WHERE state='active' - and a
 * regex that stops at the first quote reads half a statement and believes it.
 * Literals joined with '.' are put back together the way the database receives
 * them, whitespace is collapsed and the spaces around '=' are dropped, so a
 * rule reads the statement rather than the way somebody happened to type it.
 */
function sql_statements_in(string $php): array {
    $out = []; $current = null;
    foreach (token_get_all("<?php\n".$php) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $current = ($current ?? '').substr($token[1], 1, -1);
            continue;
        }
        if ($token === '.') continue;                       // the next literal continues this one
        if ($current !== null) { $out[] = $current; $current = null; }
    }
    if ($current !== null) $out[] = $current;
    return array_map(fn($sql) => (string)preg_replace('/\s*=\s*/', '=',
                                 (string)preg_replace('/\s+/', ' ', trim($sql))), $out);
}

case_('A handler that makes an account holds the address while it checks it');
/* Two people creating the same account at the same moment both looked, both
   found nothing and both wrote; the second one met the UNIQUE index instead of
   the sentence that explains the problem, and she was told to check her hosting
   because she had tapped twice. Whoever writes an account looks the address up
   through account_using_email() first, which is the one lookup that holds what
   it found.

   The rule asserts once per handler rather than once per lookup it happens to
   find: the version before this one only ever spoke about lookups that existed,
   so account_invite - which had none, and had the race - passed it in silence
   while the line underneath announced that every handler had been examined. */
$exempt = [
    // Each exemption names something that must still be in the handler, so it
    // cannot quietly outlive the reason it was granted.
    'app/auth.php create_admin_account' => [
        "FROM accounts WHERE role='admin' FOR UPDATE",
        'guards on the administrator count and holds every row that scan touched'],
    'app/demo.php demo_fill' => [
        'if (demo_present())',
        'writes three fixed addresses nobody typed, and refuses to run a second time'],
];
$examined = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/public/*.php')) as $path) {
    foreach (named_blocks_of($path) as $name => $block) {
        $sql = sql_statements_in($block);
        if (!array_filter($sql, fn($s) => str_contains($s, 'INSERT INTO accounts'))) continue;
        $handler = substr($path, strlen(APP_ROOT) + 1).' '.$name;
        $examined[] = $handler;
        $flat = (string)preg_replace('/\s*=\s*/', '=', (string)preg_replace('/[ \t]+/', ' ', $block));
        if (isset($exempt[$handler])) {
            [$stillThere, $why] = $exempt[$handler];
            ok(str_contains($flat, $stillThere), $handler.' is exempt because it '.$why);
            continue;
        }
        $lookups = array_values(array_filter($sql, fn($s) => (bool)preg_match('/FROM accounts\b[^|]* WHERE (?:\w+\.)?email=\?/', $s)));
        ok(str_contains($block, 'account_using_email(') || $lookups !== [],
           $handler.' looks the address up before it writes one');
        foreach ($lookups as $lookup)
            ok(str_contains($lookup, 'FOR UPDATE'), $handler.' holds the address it checked: '.$lookup);
    }
}
/* Named rather than counted: a count says "three of them" whether or not the
   three are the ones that matter, and a handler that stops inserting - or a
   file truncated to nothing - would just make the count smaller. */
foreach (['app/actions.php account_invite', 'app/actions.php account_create',
          'app/actions.php student_invite', 'app/auth.php create_admin_account',
          'app/demo.php demo_fill'] as $known)
    ok(in_array($known, $examined, true), 'the rule reached '.$known);
foreach (array_diff($examined, ['app/actions.php account_invite', 'app/actions.php account_create',
                                'app/actions.php student_invite', 'app/auth.php create_admin_account',
                                'app/demo.php demo_fill']) as $new)
    ok(false, $new.' creates accounts too and nobody has said so here - add it to the list above');

case_('The lookup every account-creating handler shares actually holds');
$lock = sql_statements_in(named_blocks_of(APP_ROOT.'/app/auth.php')['account_using_email'] ?? '');
ok(in_array('SELECT * FROM accounts WHERE email=? FOR UPDATE', $lock, true),
   'account_using_email() reads the row FOR UPDATE');
throws(fn() => account_using_email('nobody@example.test'),
       'and refuses outside a transaction, where it would hold nothing');
does_not_throw(fn() => transactional(fn() => account_using_email('nobody@example.test')),
               'inside one it answers');

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
/* Inside a transaction, because lock_row() refuses at depth 0 before it ever
   looks at the name. Called bare it threw the refusal about the transaction and
   the assertion passed on that, so the allowlist it claimed to be checking was
   never reached: the message is read here rather than just the fact of a throw. */
$lockRefusal = '';
try { transactional(fn() => lock_row('students; DROP TABLE students', 1)); }
catch (Throwable $e) { $lockRefusal = $e->getMessage(); }
ok(str_contains($lockRefusal, 'as a SQL table'), 'lock_row checks its table: '.$lockRefusal);
does_not_throw(fn() => charge_paid_sql('ch2'), 'a digit in an alias is fine, which the old table check wrongly refused');

/**
 * The names of the calls whose brackets are still open at the point where
 * $callee is called.
 *
 * Tokenised, because the thing being asked about is nesting and a regular
 * expression cannot count brackets. A '(' that follows something other than a
 * function name - fn() =>, function () use (...) - is pushed as nothing, so it
 * still closes correctly without pretending to be a call.
 */
function enclosing_calls_of(string $php, string $callee): array {
    $tokens = array_values(array_filter(token_get_all("<?php\n".$php),
        fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $open = [];
    foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === $callee && ($tokens[$i + 1] ?? null) === '(')
            return array_values(array_filter($open, fn($n) => $n !== null));
        if ($token === '(') {
            $before = $tokens[$i - 1] ?? null;
            $open[] = (is_array($before) && $before[0] === T_STRING) ? $before[1] : null;
        } elseif ($token === ')') array_pop($open);
    }
    return [];
}

case_('The suite reaches an action the same way a request does');
/* act() dispatched actions with no transaction open. Every FOR UPDATE in every
   handler the suites exercise was therefore locking nothing, and twenty-two
   green suites were describing a weaker portal than the one that ships. It was
   a refactor that noticed, not a test.

   The behaviour is checked in the transactions suite. This is the general
   version of it: whatever handle_post() wraps around dispatch_action() in the
   running portal, act() wraps the same thing in the same order, so the next
   wrapper somebody adds to one of them fails here by name instead of quietly
   putting the suites back in a situation the portal is never in. */
$handlePost = named_blocks_of(APP_ROOT.'/app/actions.php')['handle_post'] ?? '';
$harness    = named_blocks_of(TEST_ROOT.'/harness.php');
ok($handlePost !== '', 'handle_post() was found in app/actions.php');
ok(($harness['act'] ?? '') !== '' && ($harness['submit'] ?? '') !== '',
   'act() and submit() were found in tests/harness.php');

$requestChain = enclosing_calls_of($handlePost, 'dispatch_action');
/* Read before it is compared to anything. Two empty lists match each other
   perfectly, and a rule that compared them would report agreement about a call
   it had failed to find - which is the shape of the defect it exists to catch. */
is_same(['transactional'], $requestChain,
        'a real request calls dispatch_action() inside transactional() and nothing else');

$harnessOnly = [
    // Each one names why it is allowed to sit in the chain, so an exemption
    // cannot outlive its reason: these open nothing and hold nothing.
    'without_session_id_warning' => 'swallows the session_regenerate_id warning a command-line run cannot avoid',
];
$actChain = enclosing_calls_of($harness['act'], 'dispatch_action');
foreach (array_keys($harnessOnly) as $allowed)
    ok(in_array($allowed, $actChain, true), 'act() still goes through '.$allowed.', which '.$harnessOnly[$allowed]);
is_same($requestChain, array_values(array_diff($actChain, array_keys($harnessOnly))),
        'and act() puts the handler inside the same calls, in the same order');

ok(str_contains($harness['submit'], 'handle_post()'),
   'submit() calls handle_post() itself rather than a second description of it');
is_same([], enclosing_calls_of($harness['submit'], 'dispatch_action'),
        'and never reaches dispatch_action() around it, which would be that second description');

/**
 * Every call in a stretch of PHP, in the order the tokens run, with the two
 * things a rule about ordering needs to know about each one: whether it is a
 * method call, and whether its first argument is a statement that writes.
 *
 * Tokenised rather than matched, because the question is "which of these comes
 * first" and a regular expression cannot answer that across a nested call.
 */
function action_tokens(string $php): array {
    return array_values(array_filter(token_get_all("<?php\n".$php),
        fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
}

function action_calls_in(string $php): array {
    $tokens = action_tokens($php);
    $calls = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || ($tokens[$i + 1] ?? null) !== '(') continue;
        // Literals joined with '.' are put back together, the way the database
        // receives them, so SQL split over several lines still reads as SQL.
        $literal = null;
        for ($j = $i + 2; $j < count($tokens); $j++) {
            $u = $tokens[$j];
            if (is_array($u) && $u[0] === T_CONSTANT_ENCAPSED_STRING) { $literal = ($literal ?? '').substr($u[1], 1, -1); continue; }
            if ($u === '.') continue;
            break;
        }
        $previous = $tokens[$i - 1] ?? null;
        $calls[] = [
            'name'  => $token[1],
            'index' => $i,
            // Upper case and a table name, both required: a case-insensitive
            // match on the keyword alone reads t('Update: die neuen Dateien …')
            // as a write and puts an imaginary one ahead of the real thing.
            'dml'   => $literal !== null
                       && preg_match('/^(INSERT INTO|REPLACE INTO|DELETE FROM|UPDATE)\s+`?[a-z_][a-z0-9_]*`?[\s(]/', $literal) === 1,
            'method'=> is_array($previous) && in_array($previous[0],
                       [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION], true),
        ];
    }
    return $calls;
}

/** name => body, for every named function in one file, by matching its braces. */
function defined_functions_in(string $path): array {
    $tokens = array_values(array_filter(token_get_all((string)file_get_contents($path)),
        fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $out = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
        $name = $tokens[$i + 1] ?? null;
        if (!is_array($name) || $name[0] !== T_STRING) continue;       // a closure has no name to record
        $depth = 0; $body = ''; $open = false;
        for ($j = $i; $j < count($tokens); $j++) {
            $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            if ($text === '{') { $depth++; $open = true; }
            if ($open) $body .= $text.' ';
            if ($text === '}' && --$depth === 0) break;
        }
        $out[$name[1]] = $body;
    }
    return $out;
}

/**
 * The first call in $calls that writes through the main connection.
 *
 * "Writes" is derived rather than listed: a call is one if its own first
 * argument is an INSERT/UPDATE/DELETE, or if it reaches a function that has
 * one. A hand-kept list of write helpers would stop matching the code the first
 * time somebody adds a helper, and it would do it silently - which is exactly
 * the shape of failure this rule exists to prevent.
 */
function first_main_write(array $calls, array $writers): ?array {
    foreach ($calls as $call) {
        // The counter is a second connection on purpose, so its write is not
        // the main connection's and does not belong in this ordering at all.
        if ($call['name'] === 'run_counter') continue;
        if ($call['dml']) return $call;
        if (!$call['method'] && isset($writers[$call['name']])) return $call;
    }
    return null;
}

case_('A throttle is counted before its action writes anything');
/* throttle() counts on a second connection, deliberately, so a refused attempt
   is not refunded by the rollback of the action it guarded. That second
   connection is also why the order matters: once the action's transaction has
   written a row on the main connection, a statement sent down the counter
   connection is waiting on locks that only the main connection can release, and
   the main connection is waiting on that statement to return. Nothing times out
   and nothing rolls back - the request stops.

   Every one of these is the first statement of its case today, and they are
   correct today. This rule is the lock on that, because the suites cannot see
   it: act() reaches five of the six with a transaction already open and the run
   is green either way. */

$counterOnly = [
    // Named with what must still be true of them, so the exemption cannot
    // outlive its reason: these write, but never on the main connection.
    'throttle'       => 'counts the attempt',
    'throttle_clear' => 'forgets the attempts once the action has committed',
];
foreach ($counterOnly as $name => $why) {
    $body = defined_functions_in(APP_ROOT.'/app/auth.php')[$name] ?? '';
    ok($body !== '', $name.'() was found in app/auth.php, where it '.$why);
    $calls = action_calls_in($body);
    ok((bool)array_filter($calls, fn($c) => $c['name'] === 'run_counter'),
       $name.'() goes through run_counter(), which is what keeps it off the main connection');
    ok(!array_filter($calls, fn($c) => $c['dml'] && $c['name'] !== 'run_counter'),
       'and writes nothing on the main connection, so excluding it here is still honest');
}

/* Which functions write, worked out by following the calls rather than by
   listing the answers. run(), one(), rows() and scalar() all hand a statement
   to the main connection, but the statement is their caller's, so none of them
   is a writer in itself - the caller that supplies the INSERT is. */
$functionBodies = [];
foreach (glob(APP_ROOT.'/app/*.php') as $file) $functionBodies += defined_functions_in($file);
$functionCalls = array_map('action_calls_in', $functionBodies);
$writers = [];
do {
    $grew = false;
    foreach ($functionCalls as $name => $calls) {
        if (isset($writers[$name]) || isset($counterOnly[$name])) continue;
        if (first_main_write($calls, $writers) === null) continue;
        $writers[$name] = true; $grew = true;
    }
} while ($grew);

/* The derivation is read before it is used. A rule that asked "does a write
   come after the throttle" would pass perfectly on a set of writers that turned
   out to be empty, and would go on passing for ever. These are named rather
   than counted for the same reason the account rule is: a count stays large
   while the entries that matter drop out of it. */
foreach (['audit' => 'writes the change log', 'set_setting' => 'writes the settings table',
          'notify' => 'writes a notification row', 'record_consent' => 'writes the consent log',
          'send_account_token' => 'writes an auth token', 'request_contact' => 'writes a contact request',
          'direct_thread' => 'creates the conversation', 'notify_payment' => 'queues mail',
          'queue_mail' => 'writes the outbox'] as $name => $what)
    ok(isset($writers[$name]), $name.'() is recognised as writing on the main connection, because it '.$what);
foreach (['run' => 'hands over whatever statement its caller gave it',
          'one' => 'reads', 'rows' => 'reads', 'scalar' => 'reads',
          'throttle' => 'writes on the counter connection, not this one'] as $name => $why)
    ok(!isset($writers[$name]), $name.'() is not counted as a main-connection write, because it '.$why);

/* Named rather than counted. A seventh throttle added to a handler fails here
   under its own name and has to be looked at; a total would simply become
   seven and nobody would know which one was new. */
$throttled = [
    'app/actions_messages.php message_send'   => 'a family writing a message',
    'app/actions_messages.php contact_request'=> 'a family asking to write to somebody',
    'app/actions_config.php payment_remind'   => 'the reminder run, which sends mail',
    'app/actions_config.php feedback_send'    => 'a problem report, which can carry a file',
    'app/actions_settings.php smtp_test'      => 'the SMTP test, which talks to the mail server',
    'app/actions_settings.php email_change'   => 'a change of address, which checks a password',
    // Not a dispatcher case: the request's own throttles, before the
    // transaction is opened at all. Same ordering, same reason, so it is held
    // to the same rule rather than left as the one place nobody checks.
    'app/actions.php handle_post'             => 'the login and account-security limits',
];
$found = [];
foreach (['actions', 'actions_settings', 'actions_messages', 'actions_config'] as $unit) {
    $path = APP_ROOT.'/app/'.$unit.'.php';
    foreach (named_blocks_of($path) as $name => $block) {
        $calls = action_calls_in($block);
        $throttles = array_values(array_filter($calls, fn($c) => $c['name'] === 'throttle' && !$c['method']));
        if (!$throttles) continue;
        $where = 'app/'.$unit.'.php '.$name;
        $found[] = $where;
        ok(isset($throttled[$where]), $where.' throttles, and this rule knows about it');

        $write = first_main_write($calls, $writers);
        /* Read before it is compared. Without this, a handler whose writes the
           derivation failed to recognise - or a handler emptied by a bad edit -
           reports that its throttle comes first, having found nothing to come
           first of. */
        ok($write !== null,
           $where.' writes something on the main connection for the throttle to come before'
           .($write ? ': '.$write['name'].'()' : ''));
        if ($write === null) continue;
        ok($write['name'] !== 'throttle', $where.': the write found is not the throttle itself');
        is_same(true, $throttles[0]['index'] < $write['index'],
                $where.': throttle() is counted before '.$write['name'].'()'
                .' — '.($throttled[$where] ?? 'not a handler this rule knows'));
    }
}
foreach (array_keys($throttled) as $where)
    ok(in_array($where, $found, true), 'the rule reached '.$where);

/** Where the first string literal containing $fragment sits in the token run. */
function refusal_index_in(string $php, string $fragment): ?int {
    foreach (action_tokens($php) as $i => $token)
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($token[1], $fragment))
            return $i;
    return null;
}

/** Where the first call to $callee sits in the same token run. */
function call_index_in(string $php, string $callee): ?int {
    foreach (action_calls_in($php) as $call)
        if ($call['name'] === $callee && !$call['method']) return $call['index'];
    return null;
}

case_('A handler that refuses a change does so before it writes, not after');
/* Six assertions in the enrolment, contacts and security suites were written to
   prove this, and they did prove it: act() ran in autocommit, so a handler that
   wrote first and checked afterwards left the half-written row behind for them
   to find. act() now opens a transaction, the way a real request does, and the
   rollback puts that row back whether the handler checked first or not. The
   assertions are still true and still worth having - they describe what the
   trainer sees after a refusal - but they no longer measure the ordering, so
   the ordering is asserted here instead, once, rather than three times over in
   three suites that would drift apart.

   Textual order is execution order for straight-line code, which all of these
   are; student_invite is the one exception, and there the refusal sits in the
   branch taken when the address is already known while the account INSERT sits
   in the other, so the two cannot both run and the write that is common to both
   paths still comes after. */
$orderings = [
    'app/actions_config.php class_save' => [
        // The refusal is in class_days_from_post(), checked separately below,
        // so what matters here is that class_save calls it before it writes.
        'call'    => 'class_days_from_post',
        'guards'  => 'a meeting day whose end is before its start',
        'suite'   => 'enrolment.php "and nothing was saved"',
    ],
    'app/enrolment.php request_enrolment' => [
        'refusal' => 'wartet schon',
        'guards'  => 'a second request for a course that already has one waiting',
        'suite'   => 'enrolment.php "still just the one"',
    ],
    'app/enrolment.php decide_request' => [
        'refusal' => 'wurde schon entschieden',
        'guards'  => 'a decision taken twice',
        'suite'   => 'enrolment.php "the tariff did change, once"',
    ],
    'app/actions.php contact_delete' => [
        'refusal' => 'mindestens eine Kontaktperson',
        'guards'  => 'removing the only person left to ring',
        'suite'   => 'contacts.php "and is still there"',
    ],
    'app/actions.php student_invite' => [
        'refusal' => 'Konto der Verwaltung',
        'guards'  => 'handing a family a login that belongs to the management',
        'suite'   => 'contacts.php "leaving the child unattached rather than half-attached"',
    ],
    'app/actions.php account_invite' => [
        'refusal' => 'schon ein Konto',
        'guards'  => 'inviting an address that already has an account',
        'suite'   => 'security.php "the address still has exactly one account"',
    ],
];
foreach ($orderings as $where => $rule) {
    [$file, $name] = explode(' ', $where);
    $block = named_blocks_of(APP_ROOT.'/'.$file)[$name] ?? '';
    ok($block !== '', $where.' was found, so this rule has something to read');
    if ($block === '') continue;

    $anchor = isset($rule['call'])
        ? call_index_in($block, $rule['call'])
        : refusal_index_in($block, $rule['refusal']);
    $anchorName = $rule['call'] ?? $rule['refusal'];
    /* Read before it is compared, the same way the account rule reads its
       lookups. A refusal whose wording changed would otherwise be "not found",
       and "not found" compares happily against a write it also did not find. */
    ok($anchor !== null, $where.' still refuses '.$rule['guards'].', by '.$anchorName);

    $write = first_main_write(action_calls_in($block), $writers);
    ok($write !== null, $where.' writes something for that refusal to come before'
       .($write ? ': '.$write['name'].'()' : ' — nothing recognised as a write, so this rule proved nothing here'));

    if ($anchor === null || $write === null) continue;
    is_same(true, $anchor < $write['index'],
            $where.': '.$rule['guards'].' is refused before '.$write['name'].'() runs'
            .' — the behaviour is in '.$rule['suite']);
}

case_('The day check class_save leans on is a check, not a write');
/* class_save is only in the list above because it hands the question to
   class_days_from_post(). That is worth something only for as long as that
   function stays a pure check: the moment it writes, "called before the write"
   stops meaning "nothing had been written". */
$dayCheck = defined_functions_in(APP_ROOT.'/app/validate.php')['class_days_from_post'] ?? '';
ok($dayCheck !== '', 'class_days_from_post() was found in app/validate.php');
ok(refusal_index_in($dayCheck, 'Das Ende muss nach dem Beginn liegen') !== null,
   'and it is the one that refuses an end before the start');
is_same(null, first_main_write(action_calls_in($dayCheck), $writers),
        'and it writes nothing itself, so calling it first really does come before every write');
