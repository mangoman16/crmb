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
    'app/duplicate.php' => 80, 'app/portal_icon.php' => 60, 'app/start.php' => 100,
    'app/backup.php' => 100, 'public/setup.php' => 180, 'app/presence.php' => 120,
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
// Read either in setup.php itself or in install_submission(), where the rest of
// the form is read: the check follows the read wherever it has been moved.
$submission = defined_functions_in(APP_ROOT.'/app/install.php')['install_submission'] ?? '';
ok(str_contains($setup, "isset(\$_POST['demo_fill'])")
   || (str_contains($setup, 'install_submission($_POST') && preg_match("/\\\$post \\[ 'demo_fill' \\]/", $submission) === 1),
   'reading what that box posts');
// A fill that fails must not fail the install: the portal is up either way.
ok(preg_match('/try \{ \$demo = demo_fill\(\); \}\s*catch/', $setup) === 1,
   'and a failed fill is caught, because the portal is installed either way');

case_('Every page the router allows has a view file, or a handler that answers it');
$router = (string)file_get_contents(APP_ROOT.'/public/index.php');
preg_match("/\\\$allowed=\[([^\]]*)\]/", $router, $m);
ok(isset($m[1]), 'the allow-list is found');
/* A page that is not a page - a picture, a JSON file - is answered by one
   function that sends it and exits, never wrapped in the layout. Each is named
   with its handler, and the router has to call exactly that for exactly that
   page, so an exception cannot outlive the line that made it true. No stub
   views: an empty file would satisfy the old rule and serve nothing. */
$answeredWithoutView = ['icon' => 'serve_portal_icon', 'manifest' => 'serve_web_manifest',
                        'brand' => 'serve_brand_css', 'logo' => 'serve_portal_logo'];
foreach (array_map(fn($p) => trim($p, " '"), explode(',', $m[1] ?? '')) as $page) {
    if ($page === '') continue;
    if (!isset($answeredWithoutView[$page])) {
        ok(is_file(APP_ROOT.'/views/'.$page.'.php'), 'views/'.$page.'.php exists');
        continue;
    }
    $handler = $answeredWithoutView[$page];
    ok(function_exists($handler), $page.' is answered by '.$handler.'(), which exists');
    $call = strpos($router, "if(\$page==='".$page."')".$handler.'();');
    ok($call !== false, 'and the router calls it for '.$page);
    // After the classification is applied, so the lists read below are the
    // ones that decide who gets the answer, not a description of them.
    ok($call !== false && $call > (int)strpos($router, '$user=$public?'),
       'only once the router has decided who may have it');
    ok(!is_file(APP_ROOT.'/views/'.$page.'.php'), 'with no view beside it that the router would never reach');
}
foreach ($answeredWithoutView as $page => $handler)
    ok(str_contains($m[1] ?? '', "'".$page."'"), $page.' is still a page the router allows');

case_('No PHP is left outside its tags, where it prints as text instead of running');
/* fd0d179 shipped the tariff form with `submit_button();?>` one line below the
   `?>` that had already closed the block. PHP printed it as text, the form had
   no button, and the checklist's step 4 - „Preis für jeden Kurs“ - could not be
   done by anybody. Every suite stayed green, because they post to actions
   directly and never press a button; tests/e2e.sh found it by pressing one.
   Inline HTML is what the tokenizer says is outside PHP. A closing tag inside
   it has nothing to close, and a call to one of the application's own
   functions inside it was meant to run. */
$appFunctions = [];
foreach (glob(APP_ROOT.'/app/*.php') as $file) {
    preg_match_all('/^function\s+&?(\w+)\s*\(/m', (string)file_get_contents($file), $found);
    $appFunctions = array_merge($appFunctions, $found[1]);
}
ok(count($appFunctions) > 300, 'the application\'s functions were found to look for ('.count($appFunctions).')');
$callPattern = '/\b(?:'.implode('|', array_map('preg_quote', $appFunctions)).')\s*\([^\n]*\)\s*;/';
$templates = array_merge(glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'));
ok(count($templates) > 30, 'the views were found to read ('.count($templates).')');
foreach ($templates as $file) {
    $stray = [];
    foreach (token_get_all((string)file_get_contents($file)) as $token) {
        if (!is_array($token) || $token[0] !== T_INLINE_HTML) continue;
        if (preg_match('/\?>/', $token[1])) $stray[] = 'line '.$token[2].': a ?> with nothing to close';
        if (preg_match($callPattern, $token[1], $call)) $stray[] = 'line '.$token[2].': '.$call[0].' printed, not called';
    }
    is_same([], $stray, basename($file).' has no PHP printed as text');
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
    'icon' => 'public', 'manifest' => 'public',   // the login page wears the icon; an install reads the manifest
    'brand' => 'public', 'logo' => 'public',      // the sign-in page wears the portal's colours and logo too
    'dashboard' => 'everyone', 'students' => 'everyone', 'student' => 'everyone',
    'messages' => 'everyone', 'news' => 'everyone', 'profile' => 'everyone',
    'download' => 'everyone',   // decides per file, inside serve_download()
    'accounts' => 'staff', 'payments' => 'staff', 'compose' => 'staff', 'outbox' => 'staff',
    'classes' => 'staff', 'manage' => 'staff', 'invoices' => 'staff', 'attendance' => 'staff',
    'print' => 'staff',
    'settings' => 'admin', 'history' => 'admin',
    'start' => 'admin',   // the setup checklist: administrator decisions only (ADR 0011)
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

case_('A form holding a field is a row form, not an inline one');
/* .inline-form is for a single button. Given a field as well, the button
   stretches to the height of the field's label and help text and floats beside
   it - the "stretched button" that had to be rebuilt on the enrolment rows.
   .row-form is the layout that lines a field and its button up. The scan runs
   from each start_form(...'inline-form') to the first </form> after it, across
   lines, because a form is usually opened in one PHP block and closed in
   another. */
$fieldHelpers = ['input', 'select_field', 'file_field', 'time_field', 'check_field', 'default_field', 'contact_fields'];
$stretchedForms = function (string $source) use ($fieldHelpers): array {
    $found = [];
    $offset = 0;
    while (($at = strpos($source, 'start_form(', $offset)) !== false) {
        $offset = $at + 11;
        // The call's own arguments, up to its matching parenthesis.
        $depth = 1; $end = $offset;
        for ($k = $offset, $n = strlen($source); $k < $n && $depth > 0; $k++) {
            if ($source[$k] === '(') $depth++;
            if ($source[$k] === ')') $depth--;
            $end = $k;
        }
        if (!preg_match('/[\x27"]inline-form[\x27"]/', substr($source, $offset, $end - $offset))) continue;
        $close = strpos($source, '</form>', $end);
        $body = substr($source, $end, ($close === false ? strlen($source) : $close) - $end);
        if (preg_match('/(?<![\w>$:])('.implode('|', $fieldHelpers).')\s*\(/', $body, $hit))
            $found[] = $hit[1].'() in the form opened on line '.(substr_count(substr($source, 0, $at), "\n") + 1);
    }
    return $found;
};
// Read on examples first, including a form that closes several lines later.
foreach (["<?php start_form('x',[],'inline-form');submit_button('Go');?></form>"                        => 0,
          "<?php start_form('x',['a'=>f(1)],'inline-form');\ninput('n','N');\n?>\n<p></p>\n</form>"   => 1,
          "<?php start_form('x',[],'row-form');input('n','N');submit_button('Go');?></form>"            => 0,
          "<?php start_form('x',[],'inline-form');submit_button('Go');echo '</form>';input('y','Y');"  => 0,
          "<?php start_form('x',[],'inline-form');contact_fields(\$c);echo '</form>';"                 => 1,
          "<?php start_form('x',[],'inline-form');\$f->input('n');default_input('n');?></form>"        => 0] as $sample => $expected)
    is_same($expected, count($stretchedForms($sample)), 'the form scan reads '.test_show($sample).' correctly');
$formFiles = array_merge(glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/app/*.php'));
foreach ($formFiles as $file)
    foreach ($stretchedForms((string)file_get_contents($file)) as $where)
        ok(false, substr($file, strlen(APP_ROOT) + 1).': an inline-form holds '.$where
            .'; a form with a field needs class row-form, or its button stretches beside the field');
ok(count($formFiles) > 40, count($formFiles).' view and application files scanned for inline forms');

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
        // \shell_exec( is one token, not two, and is the same call as shell_exec(.
        $global = is_array($token) && ($token[0] === T_STRING
            || ($token[0] === T_NAME_FULLY_QUALIFIED && substr_count($token[1], '\\') === 1));
        if (!$global || ($tokens[$i+1] ?? null) !== '(') continue;
        $prev = $tokens[$i-1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION,
            T_NULLSAFE_OBJECT_OPERATOR, T_ATTRIBUTE, T_STRING], true)) continue;
        $called[strtolower(ltrim($token[1], '\\'))][basename($file)] = true;
    }
}
$keywords = ['array','isset','unset','list','echo','print','exit','die','include','require','include_once',
             'require_once','eval','match','fn','static','int','float','string','bool','void','catch','if',
             'for','foreach','while','switch','elseif','and','or','xor','empty'];
// PHP 8 removes a disabled function entirely, so on a host that disables one
// this rule is the first place the difference shows - and "undefined" alone
// sends the reader looking for a typo that is not there.
$disabled = array_map('strtolower', array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions')))));
foreach ($called as $name => $where) {
    if (isset($defined[$name]) || isset($guarded[$name]) || function_exists($name) || in_array($name, $keywords, true)) continue;
    ok(false, 'undefined function '.$name.'() called in '.implode(', ', array_keys($where))
        .(in_array($name, $disabled, true)
            ? ' — but this PHP disables it (disable_functions); guard it with function_exists(\''.$name.'\')' : ''));
}
ok(true, count($defined).' functions defined, '.count($called).' distinct call targets, all resolved');

case_('Every call that starts a shell is behind a check that it exists');
/* Shared hosting commonly lists these in disable_functions, and PHP 8 then
   removes them: an unguarded call is a fatal error on her host and nowhere else,
   so the rule above only catches it on a machine that happens to disable the same
   ones. This one does not depend on the host - each call needs a
   function_exists() for that name in its own file, so that one file's guard
   cannot excuse another's call. The backtick operator is shell_exec() without
   the name, so it counts as one. */
$shellFunctions = ['shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen'];
$unguardedShell = function (string $source) use ($shellFunctions): array {
    preg_match_all('/function_exists\(\s*[\x27"]\\\\?([a-z_]+)[\x27"]/i', $source, $m);
    $guards = array_map('strtolower', $m[1]);
    $tokens = array_values(array_filter(token_get_all($source),
        fn($x) => !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $found = []; $line = 1; $inBackticks = false;
    foreach ($tokens as $i => $t) {
        if (is_array($t)) $line = $t[2];
        if ($t === '`') {
            $inBackticks = !$inBackticks;
            if ($inBackticks && !in_array('shell_exec', $guards, true)) $found[] = 'the backtick operator (shell_exec) on line '.$line;
            continue;
        }
        if (!is_array($t) || ($tokens[$i + 1] ?? null) !== '(') continue;
        if (!($t[0] === T_STRING || ($t[0] === T_NAME_FULLY_QUALIFIED && substr_count($t[1], '\\') === 1))) continue;
        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) continue;
        $name = strtolower(ltrim($t[1], '\\'));
        if (in_array($name, $shellFunctions, true) && !in_array($name, $guards, true)) $found[] = $name.'() on line '.$line;
    }
    return $found;
};
// The rule proves it can see what it is looking for before it is trusted with
// the real files; a pass on the real files alone would look the same if it
// could not.
foreach (["<?php echo shell_exec('ls');"                                   => 1,
          "<?php \\exec('ls');"                                            => 1,
          "<?php \$listing = `ls`;"                                        => 1,
          "<?php if (function_exists('exec')) shell_exec('ls');"           => 1,
          "<?php if (function_exists('shell_exec')) echo shell_exec('ls');" => 0,
          "<?php \$pdo->exec('SELECT 1'); db()->exec('SELECT 1'); Foo::system();" => 0] as $sample => $expected)
    is_same($expected, count($unguardedShell($sample)), 'the rule reads '.test_show($sample).' correctly');
$shellFiles = array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'),
                          glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/database/*.php'));
foreach ($shellFiles as $file)
    foreach ($unguardedShell((string)file_get_contents($file)) as $call)
        ok(false, substr($file, strlen(APP_ROOT) + 1).' calls '.$call.' without function_exists() for it in the same file: '
            .'on a host that disables it, which shared hosting often does, PHP 8 stops there with an undefined function');
ok(count($shellFiles) > 40, count($shellFiles).' application files read for it');

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
             'presence_dot','presence_dot_for','presence_line',     // build their own markup and escape inside
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
/* Read from the folder itself, not with glob('*'), which leaves out every name
   starting with a dot - and the one that matters most is among those. Her
   public_html is a git checkout, and .git/ holds every version of every file
   ever committed. A dot-folder may be refused by a deny file of its own or by
   the root .htaccess, but only by a rule that holds without mod_rewrite, for the
   same reason each folder here denies itself: with mod_rewrite on, the rewrite
   into public/ already hides it. .well-known/ is left reachable on purpose,
   because the hosting panel renews the certificate through it. */
/** Whether .htaccess rules refuse a URL path without relying on mod_rewrite. */
$deniesWithoutRewrite = function (string $rules, string $path): bool {
    // Comments are not rules, and whatever sits inside the mod_rewrite block is
    // exactly what a server without mod_rewrite ignores.
    $rules = preg_replace('~<IfModule\s+mod_rewrite\.c>.*?</IfModule>~is', '', preg_replace('/^\s*#.*$/m', '', $rules));
    $matches = fn(string $pattern) => @preg_match('#'.str_replace('#', '\#', trim($pattern, '"')).'#', $path) === 1;
    // mod_alias: RedirectMatch [status] regex, and Redirect [status] /prefix
    preg_match_all('/^\s*RedirectMatch\s+(?:(?:\d{3}|gone|permanent|temp|seeother)\s+)?("[^"]+"|\S+)/mi', $rules, $m);
    foreach ($m[1] as $pattern) if ($matches($pattern)) return true;
    preg_match_all('/^\s*Redirect\s+(?:(?:\d{3}|gone|permanent|temp|seeother)\s+)?"?(\/[^\s"]*)/mi', $rules, $m);
    foreach ($m[1] as $prefix)
        if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/').'/')) return true;
    // core: <If "%{REQUEST_URI} =~ m#…#"> Require all denied </If>
    preg_match_all('~<If\s+"([^"]*)"\s*>(.*?)</If>~is', $rules, $blocks, PREG_SET_ORDER);
    foreach ($blocks as [, $expression, $inside])
        if (str_contains($inside, 'Require all denied') && str_contains($expression, '%{REQUEST_URI}')
            && preg_match('@=~\s*m?([#/|!])(.+?)\1@', $expression, $re) && $matches($re[2])) return true;
    return false;
};
// The reader is checked on rules written the ways Apache accepts, and on the
// ones that would not hold, before it is trusted with the real file.
foreach (["RedirectMatch 404 /\\.git(/|$)" => true, 'RedirectMatch 404 "/\\.(?!well-known/)"' => true,
          'Redirect 404 /.git' => true, "<If \"%{REQUEST_URI} =~ m#/\\.git#\">\n Require all denied\n</If>" => true,
          "<IfModule mod_rewrite.c>\nRewriteRule ^\\.git - [F]\n</IfModule>" => false, 'Redirect 404 /.gitx' => false,
          "# RedirectMatch 404 /\\.git" => false, '' => false] as $rule => $denies)
    is_same($denies, $deniesWithoutRewrite($rule, '/.git/config'), 'the rule reader on '.test_show($rule));
is_same(false, $deniesWithoutRewrite('RedirectMatch 404 "/\\.(?!well-known/)"', '/.well-known/acme-challenge/x'),
        'and a rule for every dot-folder can still leave .well-known/ reachable');
$rootRules = (string)file_get_contents(APP_ROOT.'/.htaccess');
$rootDenies = fn(string $path): bool => $deniesWithoutRewrite($rootRules, $path);
$folders = 0;
foreach (scandir(APP_ROOT) ?: [] as $name) {
    if ($name === '.' || $name === '..' || !is_dir(APP_ROOT.'/'.$name)) continue;
    if (in_array($name, ['public', 'vendor', '.well-known'], true)) continue;   // the portal, a build artefact, the certificate
    $folders++;
    if ($name[0] !== '.') { ok(is_file(APP_ROOT.'/'.$name.'/.htaccess'), $name.'/ has a deny file'); continue; }
    $own = str_contains((string)@file_get_contents(APP_ROOT.'/'.$name.'/.htaccess'), 'Require all denied');
    ok($own || $rootDenies('/'.$name.'/config'),
       $name.'/ is refused over the web, by a deny file of its own or by the root .htaccess without needing mod_rewrite');
}
ok($folders >= 8, $folders.' folders beside public/ checked, dot-folders included');

case_('A test run stores nothing in the portal\'s own folder');
/* The owner runs this suite on her hosting, and the folder it runs in may be the
   one her portal is served from. The suites sweep uploads that no test record
   points at and prune backups; pointed at her storage/, that is every photograph,
   voice note, payment proof and backup she has. The harness moves all of it into
   a folder of the run's own - checked here path by path, whatever driver and
   whatever configuration the run was started with, because a new kind of upload
   is a new folder. */
$runDir = test_run_dir();
ok(!test_path_inside($runDir, APP_ROOT), 'the run\'s own folder is outside the portal: '.$runDir);
$stored = ['the maintenance flag' => maintenance_file(), 'the backups' => backup_dir(),
           'the invoice proofs' => invoice_dir(), 'the schema marker' => schema_stamp_file(),
           'the backup override' => backup_override_file()];
$kinds = array_keys(upload_references());
foreach (['avatar', 'proof', 'message'] as $kind)
    ok(in_array($kind, $kinds, true), 'uploads of kind '.$kind.' are among the folders checked');
foreach ($kinds as $kind) $stored['uploads of kind '.$kind] = upload_dir($kind);
foreach ($stored as $what => $path) {
    ok(test_path_inside($path, $runDir), $what.' goes into the run\'s own folder, not '.$path);
    ok(!test_path_inside($path, APP_ROOT), $what.' is not inside the portal\'s folder');
}
if (test_driver() === 'sqlite')
    ok(test_path_inside(config_path(), $runDir), 'the configuration this run wrote is in its own folder, not in tests/');

case_('Every file the application stores is placed beside the maintenance flag');
/* That is what lets the harness move all of them with one setting. A folder the
   application named from its own root directly would not move with it, and would
   be the one a test run still writes into her storage/. Three places name that
   folder, for reasons that do not write through it at run time. */
$storageNamed = [
    'app/core.php'     => 'function maintenance_file()',  // the default every other path derives from
    'app/install.php'  => 'is_writable(ROOT',             // the installer asking whether it could write
    'public/setup.php' => "'maintenance_file' =>",        // the installer writing that setting itself
];
$seen = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/public/*.php'),
                     glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/database/*.php')) as $file) {
    $relative = substr($file, strlen(APP_ROOT) + 1);
    foreach (file($file) ?: [] as $number => $line) {
        if (!preg_match('/(?:\bROOT\b|__DIR__)[^;]*?[\x27"][^\x27"]*\bstorage\b/', $line)) continue;
        $reason = $storageNamed[$relative] ?? null;
        if ($reason !== null && str_contains($line, $reason)) { $seen[$relative] = true; continue; }
        ok(false, $relative.':'.($number + 1).' names storage/ itself instead of deriving it from maintenance_file(), '
            .'so a test run would write there: '.trim($line));
    }
}
foreach (array_keys($storageNamed) as $relative)
    ok(isset($seen[$relative]), $relative.' still names storage/ where it is expected to, so this search is still finding them');

case_('No test writes to a path inside the portal\'s own folder');
/* A page view of the live portal sees whatever a test puts there, for as long as
   it is there: a migration file in database/migrations is applied to her
   database on the next request. Read from the tests' own source, because the
   write that matters lasts a moment and is gone again before any check made
   afterwards could see it.
   A path counts as inside the portal when it is built from APP_ROOT, TEST_ROOT,
   ROOT, __DIR__ or __FILE__, from migration_files(), from a path written
   relative to the portal's folder (database/…, storage/…), which is where a run
   started inside it resolves one, or from a variable last assigned any of those.
   A command handed to exec() and its kind is not parsed; it is refused if it
   names one of the folders a live request reads from - database/, storage/,
   config/ or public/ - because a shell command is a write this rule cannot see. */
$writers = ['file_put_contents', 'touch', 'mkdir', 'rename', 'copy', 'unlink', 'rmdir', 'tempnam',
            'symlink', 'link', 'chmod', 'fopen'];
$roots = ['APP_ROOT', 'TEST_ROOT', 'ROOT', 'migration_files'];
$writesChecked = 0;
foreach (array_merge(glob(TEST_ROOT.'/*.php'), glob(TEST_ROOT.'/suites/*.php')) as $file) {
    $tokens = array_values(array_filter(token_get_all((string)file_get_contents($file)),
        fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $inside = [];   // variable => whether it currently holds a path in the portal
    // What these return is a name, a size or an answer, never a place to write.
    $notAPath = ['basename', 'hash_file', 'filesize', 'filemtime', 'is_file', 'is_dir', 'file_exists', 'strlen', 'count'];
    $holdsRoot = function (array $slice) use (&$inside, $roots, $notAPath): bool {
        $skip = 0;
        foreach ($slice as $k => $t) {
            if ($skip > 0) { if ($t === '(') $skip++; if ($t === ')') $skip--; continue; }
            if (is_array($t) && $t[0] === T_STRING && in_array(strtolower($t[1]), $notAPath, true) && ($slice[$k + 1] ?? null) === '(') {
                $skip = -1; continue;
            }
            if ($skip === -1) { $skip = 1; continue; }   // the opening parenthesis of that call
            if (!is_array($t)) continue;
            if (in_array($t[0], [T_DIR, T_FILE], true)) return true;
            if ($t[0] === T_STRING && in_array($t[1], $roots, true)) return true;
            if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('#^(\./)?(app|bin|config|database|docs|public|storage|tests|views|vendor)(/|$)#', trim($t[1], '\'"'))) return true;
            if ($t[0] === T_VARIABLE && ($inside[$t[1]] ?? false)) return true;
        }
        return false;
    };
    // The tokens up to the end of the statement or argument list, at depth zero.
    $until = function (int $from, array $stops) use ($tokens): array {
        $depth = 0; $out = [];
        for ($k = $from; $k < count($tokens); $k++) {
            $t = $tokens[$k];
            if (in_array($t, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
            if (in_array($t, [')', ']', '}'], true)) { if ($depth === 0) break; $depth--; }
            if ($depth === 0 && in_array($t, $stops, true)) break;
            $out[] = $t;
        }
        return $out;
    };
    foreach ($tokens as $i => $t) {
        if (!is_array($t)) continue;
        $next = $tokens[$i + 1] ?? null;
        // $x = …;  and  $x .= …;  - in the order the file runs them.
        if ($t[0] === T_VARIABLE && ($next === '=' || (is_array($next) && $next[0] === T_CONCAT_EQUAL))) {
            $holds = $holdsRoot($until($i + 2, [';']));
            $inside[$t[1]] = $next === '=' ? $holds : (($inside[$t[1]] ?? false) || $holds);
            continue;
        }
        // foreach (… as $x)  and  foreach (… as $k => $x)
        if ($t[0] === T_FOREACH) {
            $head = $until($i + 2, []);
            $as = array_search(true, array_map(fn($h) => is_array($h) && $h[0] === T_AS, $head), true);
            if ($as === false) continue;
            $holds = $holdsRoot(array_slice($head, 0, $as));
            foreach (array_slice($head, $as + 1) as $h) if (is_array($h) && $h[0] === T_VARIABLE) $inside[$h[1]] = $holds;
            continue;
        }
        if ($t[0] === T_STRING && $next === '(' && in_array(strtolower($t[1]), ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'], true)) {
            $shellCommand = $until($i + 2, []);
            foreach ($shellCommand as $a)
                if (is_array($a) && in_array($a[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match('#(^|[/\s\'"])(database|storage|config|public)(/|$)#', trim($a[1], '\'"'))) {
                    ok(false, 'tests/'.substr($file, strlen(TEST_ROOT) + 1).':'.$t[2].' hands '.$t[1].'() a command naming '
                        .trim($a[1], '\'"').', a folder a page view of the live portal reads from');
                    break;
                }
            continue;
        }
        if ($t[0] !== T_STRING || !in_array(strtolower($t[1]), $writers, true) || $next !== '(') continue;
        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) continue;
        $args = []; $current = []; $depth = 0;
        foreach (array_slice($tokens, $i + 2) as $a) {
            if (in_array($a, ['(', '['], true)) $depth++;
            if (in_array($a, [')', ']'], true)) { if ($depth === 0) break; $depth--; }
            if ($a === ',' && $depth === 0) { $args[] = $current; $current = []; continue; }
            $current[] = $a;
        }
        $args[] = $current;
        $name = strtolower($t[1]);
        if ($name === 'fopen') {
            $mode = $args[1][0] ?? null;
            if (is_array($mode) && $mode[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\x27"]r[bt]?[\x27"]$/', $mode[1])) continue;
        }
        // A move takes its source away as well; a copy or a link only reads its
        // source and writes the second argument.
        $checked = match ($name) {
            'rename' => $args,
            'copy', 'symlink', 'link' => [$args[1] ?? []],
            default => [$args[0]],
        };
        $writesChecked++;
        foreach ($checked as $arg)
            if ($holdsRoot($arg)) {
                ok(false, 'tests/'.substr($file, strlen(TEST_ROOT) + 1).':'.$t[2].' '.$name.'() writes to a path inside the portal\'s own folder, '
                    .'where a page view of the live portal would see it');
                break;
            }
    }
}
ok($writesChecked >= 20, 'the tests\' own writes were found and followed ('.$writesChecked.'), so a pass here is not an empty search');

case_('A run refuses a database it could destroy, before it connects');
/* The suite drops every table in the database it is given. The guards once
   asked for the driver "mysql" while the dropping ran for anything that was not
   "sqlite", so CRM_TEST_DRIVER=MySQL skipped every check and emptied whatever
   the configuration named. Each refusal is watched from outside, in a run of its
   own, against a server address where nothing listens - so a guard that gave way
   would show as a connection attempt, never as a dropped table. */
$boot = defined_functions_in(TEST_ROOT.'/harness.php')['test_boot'] ?? '';
ok(str_contains($boot, "if ( \$driver === 'sqlite' )"), 'test_boot() still decides between building and dropping on "sqlite"');
ok(preg_match("/\\\$driver\s*={2,3}\s*'mysql'/", $boot) === 0,
   'so no guard in it asks for "mysql": each asks for "not sqlite", the exact complement of the branch that drops');
ok(substr_count($boot, "\$driver !== 'sqlite'") >= 2, 'both guards, the name and the portal\'s own database, are written that way');
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(),
        ['a run refusing a misspelled driver or a database name (this PHP disables exec)']));
} else {
    $attempt = function (string $driver, string $database): array {
        $config = test_run_dir().'/refusal-'.bin2hex(random_bytes(4)).'.php';
        // Port 1 on this machine: nothing answers, so the only way to reach it
        // at all is to have got past every refusal.
        write_run_config($config,
            ['host' => '127.0.0.1', 'port' => 1, 'database' => $database, 'username' => 'nobody', 'password' => ''],
            test_run_dir());
        exec('CRM_TEST_DRIVER='.escapeshellarg($driver).' CRM_CONFIG='.escapeshellarg($config).' '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg(TEST_ROOT.'/run.php').' no-such-suite 2>&1', $out, $code);
        @unlink($config);
        return ['code' => $code, 'out' => implode("\n", $out)];
    };
    // The control first: a run that is allowed through really does try to
    // connect, so a refusal below is a refusal and not a run that never started.
    $through = $attempt('mysql', 'crm_test');
    ok($through['code'] !== 2 && str_contains($through['out'], 'SQLSTATE'),
       'a correctly named test database gets past every refusal and tries to connect');
    foreach ([['MySQL', 'crm_test', 'the driver spelled MySQL'], ['mariadb', 'crm_test', 'the driver called mariadb'],
              ['mysql ', 'crm_test', 'the driver with a trailing space'],
              ['mysql', 'badminton', 'a database whose name does not end in _test'],
              ['mysql', 'x;dbname=badminton;y=z_test', 'a name ending in _test that carries a second dbname'],
              ['mysql', 'crm-test', 'a name with a character a connection string could use']] as [$driver, $database, $what]) {
        $refused = $attempt($driver, $database);
        is_same(2, $refused['code'], 'refused: '.$what);
        ok(str_starts_with($refused['out'], 'Refusing to run') && !str_contains($refused['out'], 'SQLSTATE'),
           'and it said so before any connection was attempted: '.$what);
    }
}
foreach (['crm_test' => true, 'konto_crm_test' => true, 'CRM_TEST' => false, 'crm_test ' => false,
          "crm_test\n" => false, 'x;dbname=live;y=z_test' => false, '_test' => false, 'crm_testing' => false] as $name => $allowed)
    is_same($allowed, test_database_name_allowed($name), 'the name rule on '.test_show($name));

case_('The commands that identify a release work before it is configured');
/* During an update you unpack a release and want to know which one it is
   before linking a configuration into it. These three must therefore answer
   without a database or a config file; everything else may reasonably refuse. */
$console = escapeshellarg(APP_ROOT.'/bin/console.php');
/* A configuration file that does not exist is the point: the release has not
   been configured yet. It is named rather than left empty, because an empty
   CRM_CONFIG falls back to config/config.php - and run from the folder the live
   portal is served from, that is hers, so the check would neither prove what it
   says nor be one step away from her database. */
$unconfigured = test_run_dir().'/no-such-config.php';
ok(!is_file($unconfigured), 'the configuration the console is pointed at really does not exist');
if (!function_exists('exec')) {
    // Shared hosting often lists exec in disable_functions. Said in the footer
    // rather than aborting the rest of this suite on an undefined function.
    test_unsupported(array_merge(test_unsupported(),
        ['the console answering before it is configured, and its help text (this PHP disables exec)']));
} else {
    $bare = function (string $command) use ($console, $unconfigured): array {
        exec('CRM_CONFIG='.escapeshellarg($unconfigured).' '.escapeshellarg(PHP_BINARY).' '.$console.' '.escapeshellarg($command).' 2>&1', $out, $code);
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

case_('Presence is written on the counter connection only, and never from the asset routes');
/* ADR 0015. The online helpers moved to app/presence.php; a copy left in
   auth.php would be the one somebody fixes next. A period written on the main
   connection vanishes with any action that rolls back, and a touch from the
   icon or the manifest - which a browser fetches on its own, from a tab left
   open - would show somebody online who is not there. */
$authFunctions = defined_functions_in(APP_ROOT.'/app/auth.php');
ok(!isset($authFunctions['touch_last_seen']) && !isset($authFunctions['is_online']), 'touch_last_seen() and is_online() are gone from auth.php');
$oldCalls = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php')) as $file)
    if (preg_match('/(?<![a-z_>])(touch_last_seen|is_online)\s*\(/', (string)file_get_contents($file))) $oldCalls[] = basename($file);
is_same([], $oldCalls, 'and nothing calls them any more');
$touch = defined_functions_in(APP_ROOT.'/app/presence.php')['presence_touch'] ?? '';
is_same(4, substr_count($touch, 'run_counter ('), 'presence_touch() reads and writes the account and the period on the counter connection');
ok($touch !== '' && preg_match('/(?<![a-z_])(run|one|rows|scalar) \(/', $touch) === 0, 'and writes nothing on the main connection');
$routerText = (string)file_get_contents(APP_ROOT.'/public/index.php');
is_same(1, substr_count($routerText, 'presence_touch('), 'the router touches in one place');
preg_match('/if\(\$user && !in_array\(\$page,\[([^\]]*)\],true\)\)presence_touch\(\$user\);/', $routerText, $skip);
$skipped = isset($skip[1]) ? array_map(fn($p) => trim($p, " '"), explode(',', $skip[1])) : [];
foreach (['icon', 'manifest', 'brand', 'logo'] as $asset)
    ok(in_array($asset, $skipped, true), 'and not for the '.$asset.' route');
$prune = defined_functions_in(APP_ROOT.'/app/tick.php')['prune_expired'] ?? '';
ok(preg_match('/^\{ presence_prune \(/', $prune) === 1,
   'the nightly prune forgets presence first, so nothing failing after it can keep it past the month');

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
        // The sentence lives in own_address_needed(), so every refusal of an
        // address that is already a login says the same thing.
        'call'    => 'own_address_needed',
        'guards'  => 'a second student on an address that is already a login (ADR 0010)',
        'suite'   => 'accounts.php "and the brother was not touched at all"',
    ],
    'app/actions.php student_save' => [
        // The last of the address refusals, so the ones above it come first too.
        'refusal' => 'lässt sich erst eintragen',
        'guards'  => 'a new address for an invited login that no invitation could reach',
        'suite'   => 'accounts.php "nothing of the save happened"',
    ],
    'app/actions.php account_invite' => [
        'refusal' => 'schon ein Konto',
        'guards'  => 'inviting an address that already has an account',
        'suite'   => 'security.php "the address still has exactly one account"',
    ],
    'app/actions_settings.php portal_icon_save' => [
        // The refusals are in check_portal_icon(), checked separately below.
        'call'    => 'check_portal_icon',
        'guards'  => 'a picture that is not a square PNG of at least 180 pixels',
        'suite'   => 'uploads.php "and the icon she had is still the icon"',
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

case_('The icon check portal_icon_save leans on is a check, not a write');
/* The same reasoning as the day check: portal_icon_save is in the list above
   because check_portal_icon() refuses for it, which only means "nothing was
   written" while that function writes nothing to the database. It does delete
   the refused file - that is the disk, not the transaction, and it is the one
   thing it is meant to do. */
$iconCheck = defined_functions_in(APP_ROOT.'/app/portal_icon.php')['check_portal_icon'] ?? '';
ok($iconCheck !== '', 'check_portal_icon() was found in app/portal_icon.php');
ok(str_contains($iconCheck, 'IMAGETYPE_PNG') && str_contains($iconCheck, 'PORTAL_ICON_MIN_SIZE'),
   'and it is the one that refuses a picture that is not a PNG, not square, or too small');
is_same(null, first_main_write(action_calls_in($iconCheck), $writers),
        'and it writes nothing to the database, so calling it first really does come before every write');

case_('One place writes the trail a problem report carries, before the POST branch');
/* ADR 0009: the recorder is called once, from the router, after the page is
   known and before a POST can redirect away. A second call site records a step
   twice; a call below the POST branch never sees a POST at all, and the trail
   has a hole exactly where a form was sent - the step a report most needs. */
$callSites = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'),
                     glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php')) as $file)
    foreach (action_calls_in((string)file_get_contents($file)) as $call)
        if ($call['name'] === 'record_step' && !$call['method']) $callSites[] = substr($file, strlen(APP_ROOT) + 1);
is_same(['public/index.php'], $callSites, 'record_step() is called exactly once, from public/index.php');
$recorded = strpos($router, 'record_step($page);');
ok($recorded !== false, 'with the page the router settled on');
ok($recorded > (int)strpos($router, "\$page='not_found';"), 'after the allow-list has decided what page this is');
ok($recorded < (int)strpos($router, "if(\$_SERVER['REQUEST_METHOD']==='POST')"), 'and before the POST branch, which redirects and never comes back');
$writesTrail = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php')) as $file)
    if (preg_match("/\\\$_SESSION\['steps(_account)?'\]\s*=/", (string)file_get_contents($file)))
        $writesTrail[] = basename($file);
is_same(['shell.php'], $writesTrail, 'and nothing but app/shell.php writes the trail into the session');

case_('Only the places named here give a student a login or change the address it signs in with');
/* One login is one student (ADR 0010). The database holds half of that - the
   unique index refuses a second student on a login - and nothing holds the
   other half, that a student's address and their login's are the same, except
   that only these places write either one. A new writer fails here by name and
   has to say why it keeps both true. Read from the SQL itself, so a statement
   split over lines or spelled with spaces is still found. */
$writersOf = ['account_id' => [], 'email' => []];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/public/*.php'),
                     glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/database/*.php')) as $path) {
    foreach (named_blocks_of($path) as $name => $block) {
        foreach (sql_statements_in($block) as $sql) {
            $columns = [];
            if (preg_match('/^UPDATE `?students`? SET (.*?)(?: WHERE |$)/i', $sql, $set))
                $columns = array_map(fn($pair) => trim(explode('=', $pair)[0], ' `'), explode(',', $set[1]));
            elseif (preg_match('/^INSERT INTO `?students`? ?\(([^)]*)\)/i', $sql, $into))
                $columns = array_map(fn($c) => trim($c, ' `'), explode(',', $into[1]));
            foreach (array_keys($writersOf) as $column)
                if (in_array($column, $columns, true)) $writersOf[$column][] = substr($path, strlen(APP_ROOT) + 1).' '.$name;
        }
    }
}
$allowedWriters = [
    'account_id' => [
        'app/actions.php student_invite' => 'the one way a student gets a login: a new one, never a sibling’s',
        'app/demo.php demo_fill'         => 'example data: two fixed logins, one student each, and the index would refuse more',
    ],
    'email' => [
        'app/actions.php change_login_address' => 'moves the login and the student’s copy together',
        'app/actions.php student_invite'       => 'writes both copies when it makes the login',
        'app/actions.php student_save'         => 'writes the login’s own address back for a student who has one',
        'app/demo.php demo_fill'               => 'example data, each login’s student given that login’s address',
    ],
];
foreach ($allowedWriters as $column => $expected) {
    $found = array_values(array_unique($writersOf[$column]));
    sort($found);
    $names = array_keys($expected);
    sort($names);
    /* Read before it is compared: an extraction that found nothing would
       "agree" with a list of nothing, and the rule would prove nothing. */
    ok(count($found) >= 2, 'students.'.$column.' writers were found in the code ('.count($found).')');
    foreach (array_diff($found, $names) as $new)
        ok(false, $new.' writes students.'.$column.' and is not one of the places allowed to - route it through student_invite or change_login_address');
    foreach ($expected as $where => $why)
        ok(in_array($where, $found, true), $where.' still writes students.'.$column.', because it '.$why);
}

case_('Unexpected errors are written down from exactly the places ADR 0012 names');
/* capture_error() is a writer outside an action, like the background work, and
   it is only safe where the ADR put it: after the router has given up on a
   request, after a background job has failed, and from the fatal-error
   handler. Called from anywhere else it would record refusals, run twice in a
   request or write in the middle of somebody else's transaction. A new caller
   fails here by name. */
$captures = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'),
                     glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/database/*.php')) as $path)
    foreach (named_blocks_of($path) as $block => $php)
        foreach (action_calls_in($php) as $call)
            if (!$call['method'] && in_array($call['name'], ['capture_error', 'capture_fatal_error'], true))
                $captures[$call['name']][] = substr($path, strlen(APP_ROOT) + 1).' '.$block;
$expected = ['app/shell.php capture_fatal_error', 'app/tick.php run_background_tasks', 'app/tick.php tick_work',
             'public/index.php index.php (file)', 'public/index.php index.php (file)'];
$found = $captures['capture_error'] ?? [];
sort($found);
is_same($expected, $found,
        'capture_error() is called in the router’s last catch and its database catch, the two catches of the background work, and the fatal-error handler - nowhere else');
is_same(['public/index.php index.php (file)'], $captures['capture_fatal_error'] ?? [],
        'and the fatal-error handler is registered once, by the router');
$final = substr($router, (int)strrpos($router, '} catch(Throwable $ex) {'));
ok(str_contains($final, 'capture_error($ex);') && strpos($final, 'capture_error($ex);') < strpos($final, "echo '<!doctype html>"),
   'the last catch captures before it sends the friendly page, which capture_error() cannot stop');

case_('The router’s last catch works before the application has loaded');
/* That catch also sees a configuration that stops app/bootstrap.php at its first
   check - a wrong app key, a malformed app_url - before a single file of the
   application is loaded. Anything it calls from app/ has to be behind a
   function_exists() for that name, or the friendly page becomes a fatal error:
   capture_error() was, until this rule. Built-in functions are always there. */
$lastCatch = substr($router, (int)strrpos($router, '} catch(Throwable $ex) {'));
preg_match_all("/function_exists\\('([a-z_][a-z0-9_]*)'\\)/", $lastCatch, $guardedHere);
$fromTheApp = [];
foreach (action_calls_in($lastCatch) as $call) {
    if ($call['method'] || !function_exists($call['name'])) continue;
    if ((new ReflectionFunction($call['name']))->isInternal()) continue;
    if (!in_array($call['name'], $guardedHere[1], true)) $fromTheApp[] = $call['name'].'()';
}
ok(str_contains($lastCatch, 'capture_error('), 'the last catch was found, and it captures');
is_same([], array_values(array_unique($fromTheApp)),
        'and everything it calls from the application is behind function_exists(), so a portal stopped before loading still gets its page');
