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
    'app/portal_icon.php' => 60, 'app/start.php' => 100,
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
// app/ as well as views/, because a form can be written out by a shared helper
// (login_delete_details(), invitation_withdraw_details()), and the point of this rule is
// "every handler is reachable", not "every handler is spelled out in a view".
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
ok(str_contains(implode("\n", security_headers()), "style-src 'self'"),
   'and the policy that makes that true is still in place, on the list every page is sent with');

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
    'accounts' => 'staff', 'payments' => 'staff', 'outbox' => 'staff',
    'classes' => 'staff', 'manage' => 'staff', 'invoices' => 'staff', 'attendance' => 'staff',
    'student_new' => 'staff',   // the wizard „Schüler anlegen" (ADR 0023 §5)
    'more' => 'staff',          // „Mehr", the fifth place on staff's bar (ADR 0028)
    'welcome' => 'everyone',    // „Dein Foto", the step after a first password (ADR 0031)
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
             'sidebar_nav','nav_link','time_cells','select_options', // build their own markup and escape inside
             'chat_name',                                           // builds its own markup and escapes inside
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
/* The files at the root with no extension a pattern could match. MANIFEST was
   served on Apache without mod_rewrite until it was named here: the rule denied
   VERSION by name and *.txt by pattern, and nothing in between. */
preg_match_all('~<Files(Match)? "([^"]+)">\s*Require all denied~', $rootAccess, $deny, PREG_SET_ORDER);
$deniedByName = function (string $name) use ($deny): bool {
    foreach ($deny as $rule)
        if ($rule[1] === '' ? $rule[2] === $name : preg_match('~'.str_replace('~', '\~', $rule[2]).'~', $name) === 1) return true;
    return false;
};
foreach (['VERSION', 'MANIFEST', 'BUILD.txt'] as $name)
    ok($deniedByName($name), $name.' at the root is denied without mod_rewrite');
foreach (['app', 'bin', 'config', 'database', 'docs', 'storage', 'tests', 'views'] as $directory) {
    $guard = APP_ROOT.'/'.$directory.'/.htaccess';
    ok(is_file($guard), $directory.'/.htaccess exists');
    ok(str_contains((string)@file_get_contents($guard), 'Require all denied'), $directory.'/ denies itself');
}
ok(!is_file(APP_ROOT.'/public/.htaccess') || !str_contains((string)file_get_contents(APP_ROOT.'/public/.htaccess'), 'Require all denied'),
   'public/ is the one directory that does not, because it is the portal');

case_('The marker of an import in progress is spelled once, and only a copy makes or drops it [ADR 0029 §2]');
/* A copy makes import_unfinished as its first statement and drops it as its
   last, and that is the whole rule: the portal itself never makes, fills or
   drops it, and nothing else spells its name, so no second copy of the rule can
   disagree about which table says "a copy is being imported". Read from the
   source, because the suite's own tests make and drop it on purpose. */
$spelled = []; $touched = [];
foreach (['app', 'bin', 'public', 'views', 'database'] as $dir)
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!preg_match('/\.(php|sql)$/', (string)$file)) continue;
        $path = substr((string)$file, strlen(APP_ROOT) + 1);
        $block = '(file)';
        foreach (file((string)$file) as $line) {
            // Functions here open with "function name(" and close with "}" at
            // the margin; what lies between two of them belongs to the file.
            if (preg_match('/^\s*function\s+([a-z_][a-z0-9_]*)\s*\(/i', $line, $m)) $block = $m[1];
            if (preg_match('/[\'"`]'.IMPORT_UNFINISHED_TABLE.'[\'"`]/', $line)) $spelled[] = $path.' '.$block;
            // The constant beside a statement that makes, fills, empties or renames a table.
            if (str_contains($line, 'IMPORT_UNFINISHED_TABLE')
                && preg_match('/\b(CREATE|DROP|ALTER|RENAME|TRUNCATE) TABLE\b|\bINSERT INTO\b|\bDELETE FROM\b/', $line)) $touched[] = $path.' '.$block;
            if (preg_match('/^}\s*$/', $line)) $block = '(file)';
        }
    }
is_same(['app/backup.php (file)'], $spelled, 'the name is spelled once, as the constant in app/backup.php, and nowhere else in app/, bin/, public/, views/ or database/');
is_same(['app/backup.php backup_write', 'app/backup.php backup_write'], $touched,
        'backup_write() is the one function that makes or drops the table, once each; no migration, no runner, no sweep does');

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
   a folder of the run's own - checked here path by path, whatever configuration
   the run was started with, because a new kind of upload is a new folder. */
$runDir = test_run_dir();
ok(!test_path_inside($runDir, APP_ROOT), 'the run\'s own folder is outside the portal: '.$runDir);
$stored = ['the maintenance flag' => maintenance_file(), 'the backups' => backup_dir(),
           'the invoice proofs' => invoice_dir(), 'the schema marker' => schema_stamp_file(),
           'the backup override' => backup_override_file(), 'the sign-in sessions' => session_dir(),
           'the record of an unfinished update' => schema_unfinished_file()];
$kinds = array_keys(upload_references());
foreach (['avatar', 'proof', 'message', 'picture'] as $kind)
    ok(in_array($kind, $kinds, true), 'uploads of kind '.$kind.' are among the folders checked');
foreach ($kinds as $kind) $stored['uploads of kind '.$kind] = upload_dir($kind);
foreach ($stored as $what => $path) {
    ok(test_path_inside($path, $runDir), $what.' goes into the run\'s own folder, not '.$path);
    ok(!test_path_inside($path, APP_ROOT), $what.' is not inside the portal\'s folder');
}

case_('Every file the application stores is placed beside the maintenance flag');
/* That is what lets the harness move all of them with one setting. A folder the
   application named from its own root directly would not move with it, and would
   be the one a test run still writes into her storage/. Three places name that
   folder, for reasons that do not write through it at run time. */
$storageNamed = [
    'app/core.php'     => 'function maintenance_file()',  // the default every other path derives from
    'app/install.php'  => "dirname(\$flag) : ROOT . '/storage'", // the installer, before there is a maintenance flag to derive it from
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
/* The suite drops every table in the database it is given. Each refusal is
   watched from outside, in a run of its own, against a server address where
   nothing listens - so a guard that gave way would show as a connection
   attempt, never as a dropped table. Without CRM_CONFIG the application would
   read config/config.php, which on her host is the portal's own. */
$boot = defined_functions_in(TEST_ROOT.'/harness.php')['test_boot'] ?? '';
$firstStatement = strpos($boot, 'db ( )');
ok($firstStatement !== false, 'test_boot() was found and sends statements, so the order below is measured');
foreach (["getenv ( 'CRM_CONFIG' )" => 'the missing configuration', 'test_database_name_allowed' => 'the name',
          "'/config/config.php'" => 'the portal\'s own database'] as $guard => $what) {
    $at = strpos($boot, $guard);
    ok($at !== false && $firstStatement !== false && $at < $firstStatement, 'test_boot() checks '.$what.' before its first statement');
}
if (!function_exists('exec')) {
    test_unsupported(array_merge(test_unsupported(),
        ['a run refusing a missing configuration or a database name (this PHP disables exec)']));
} else {
    $attempt = function (?string $database, string $env = ''): array {
        $config = test_run_dir().'/refusal-'.bin2hex(random_bytes(4)).'.php';
        // Port 1 on this machine: nothing answers, so the only way to reach it
        // at all is to have got past every refusal.
        if ($database !== null)
            write_run_config($config,
                ['host' => '127.0.0.1', 'port' => 1, 'database' => $database, 'username' => 'nobody', 'password' => ''],
                test_run_dir());
        $setting = $env !== '' ? $env : 'CRM_CONFIG='.escapeshellarg($config);
        exec($setting.' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(TEST_ROOT.'/run.php').' no-such-suite 2>&1', $out, $code);
        @unlink($config);
        return ['code' => $code, 'out' => implode("\n", $out)];
    };
    // The control first: a run that is allowed through really does try to
    // connect, so a refusal below is a refusal and not a run that never started.
    $through = $attempt('crm_test');
    ok($through['code'] !== 2 && str_contains($through['out'], 'SQLSTATE'),
       'a correctly named test database gets past every refusal and tries to connect');
    foreach ([[null, 'env -u CRM_CONFIG', 'no CRM_CONFIG at all'], [null, 'CRM_CONFIG=', 'an empty CRM_CONFIG'],
              ['badminton', '', 'a database whose name does not end in _test'],
              ['x;dbname=badminton;y=z_test', '', 'a name ending in _test that carries a second dbname'],
              ['crm-test', '', 'a name with a character a connection string could use']] as [$database, $env, $what]) {
        $refused = $attempt($database, $env);
        is_same(2, $refused['code'], 'refused: '.$what);
        ok(str_starts_with($refused['out'], 'Refusing to run') && !str_contains($refused['out'], 'SQLSTATE'),
           'and it said so before any connection was attempted: '.$what);
    }
    $unset = $attempt(null, 'env -u CRM_CONFIG');
    is_same(1, substr_count(trim($unset['out']), "\n") + 1, 'without a configuration it says so in one line');
    ok(str_contains($unset['out'], 'tests/mariadb-local.sh') && str_contains($unset['out'], 'tests/existing-database.sh'),
       'naming the two scripts that set one up: '.$unset['out']);
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

case_('Every PHP extension the code or a dependency requires is one setup checks for');
/* A PHP without one of them installs cleanly and breaks somewhere else later:
   without iconv the family's payment page and every invoice, without ctype
   every paged list. composer.json names what the code calls and the lock file
   what each dependency calls; extension_checks() is what setup.php checks for -
   refusing to install without any but gd, without which only pictures are
   missing (ADR 0031 §4, as amended) - and what Einstellungen → System names
   when a PHP switched in the hosting panel has lost one. The two have to agree,
   both ways, apart from what PHP 8.2 cannot be built without: hash and json
   (since 7.4 and 8.0), random (since 8.2), and pcre, spl, date, standard and
   reflection (always). */
$alwaysThere = ['hash', 'json', 'random', 'pcre', 'spl', 'date', 'standard', 'reflection', 'core'];
// pdo is loaded whenever pdo_mysql is, which needs it, so it is checked through that one.
$checkedThrough = ['pdo' => 'pdo_mysql'];
$required = [];
foreach (array_keys(json_decode((string)file_get_contents(APP_ROOT.'/composer.json'), true)['require'] ?? []) as $name)
    if (str_starts_with($name, 'ext-')) $required[substr($name, 4)][] = 'composer.json';
foreach (json_decode((string)file_get_contents(APP_ROOT.'/composer.lock'), true)['packages'] ?? [] as $package)
    foreach (array_keys($package['require'] ?? []) as $name)
        if (str_starts_with($name, 'ext-')) $required[substr($name, 4)][] = $package['name'];
ok(count($required) >= 8, 'composer.json and the lock file name the extensions: '.implode(', ', array_keys($required)));
$checked = array_column(extension_checks(), 'extension');
foreach ($required as $extension => $by) {
    if (in_array($extension, $alwaysThere, true)) continue;
    ok(in_array($checkedThrough[$extension] ?? $extension, $checked, true),
       'setup checks for '.$extension.', which '.implode(' and ', array_unique($by)).' require');
}
foreach ($checked as $extension)
    ok(in_array('composer.json', $required[$extension] ?? [], true), 'composer.json requires '.$extension.', which setup checks for');
/* gd is called by the code, not by a dependency, so the two lists above could
   both forget it and still agree: asked of the code itself (ADR 0031 §4). */
$callsGd = [];
foreach (glob(APP_ROOT.'/app/*.php') as $path)
    if (preg_match('/\b(imagecreatefromstring|imagecreatetruecolor|imagecopyresampled|imagejpeg)\s*\(/', (string)file_get_contents($path))) $callsGd[] = basename($path);
ok($callsGd !== [], 'the code makes pictures with gd ('.implode(', ', $callsGd).')');
ok(in_array('gd', $checked, true) && in_array('composer.json', $required['gd'] ?? [], true),
   'so setup checks for gd, and composer.json requires ext-gd');

case_('bin/update.sh lets Composer do without exactly the extensions setup installs without [ADR 0031 §4, as amended]');
/* gd is the one extension setup installs without ('fatal' => false), and the one
   bin/update.sh waives when it runs composer install on a host with a shell: the
   rule is written in two places, so the two are held to each other here. A
   second optional extension update.sh did not waive would stop a Git host's
   update where setup installs; a waiver with no optional extension behind it
   would let an update through that setup refuses. Read from the commands, not
   the comments; a wildcard (ext-*) or php counts as a waiver too. A waiver is
   written one way, --ignore-platform-req=name, because that is the way read
   here: Composer also takes the name after a space, every requirement at once
   (--ignore-platform-reqs) and COMPOSER_IGNORE_PLATFORM_REQ(S) in the
   environment, and a waiver written any of those ways would pass unseen. */
$commands = implode('', array_filter(file(APP_ROOT.'/bin/update.sh') ?: [], fn(string $line): bool => !preg_match('/^\s*#/', $line)));
preg_match_all('/--ignore-platform-req=(\S+)/', $commands, $flags);
$waived = array_values(array_unique(array_map(fn(string $req): string => (string)preg_replace('/^ext-([a-z0-9_]+)$/D', '$1', $req), $flags[1])));
$optional = array_column(array_filter(extension_checks(), fn(array $c): bool => !$c['fatal']), 'extension');
sort($waived); sort($optional);
is_same($optional, $waived, 'update.sh waives exactly what setup installs without: '.(implode(', ', $optional) ?: 'nothing'));
ok(!preg_match('/--ignore-platform-req(s\b|\s)|COMPOSER_IGNORE_PLATFORM_REQ/', $commands), 'and in no other form Composer takes: not the name after a space, not all at once, not through the environment');

case_('bin/update.sh refuses a wrong argument before anything runs, and prints an address without its password');
/* Run as a shell runs it. A value that starts with '-' is the next option, not
   this one's: with the tag forgotten, "--ref --check" took --check as the tag,
   skipped the dry run and handed it to git checkout. --repo goes with --clone
   only, as an update fetches from the checkout's own origin. The address a first
   install clones from is printed without a user name or password, which would
   otherwise reach a cron job's mail - up to the last '@' before the path, as a
   password may hold one. --help is the comment at the top and none of the code
   after it. Each case ends before git, php or composer would do anything; should
   one stop doing so, the three found on PATH here are stand-ins that only say
   they were called, so a broken check cannot fetch, clone or migrate from a test. */
$bash = array_values(array_filter(array_map(fn(string $dir): string => $dir.'/bash', explode(':', (string)getenv('PATH'))), 'is_executable'))[0] ?? null;
$work = test_run_dir().'/update-sh-'.bin2hex(random_bytes(4));
$cannot = !function_exists('proc_open') ? 'this PHP disables proc_open' : ($bash === null ? 'no bash on PATH' : null);
if ($cannot === null) {
    mkdir($work.'/stand-ins', 0700, true);
    foreach (['git', 'php', 'composer'] as $tool) {
        file_put_contents($work.'/stand-ins/'.$tool, "#!/bin/sh\necho \"$tool was called: \$*\" >&2\nexit 97\n");
        chmod($work.'/stand-ins/'.$tool, 0700);
    }
    // Linux runs nothing from a folder mounted noexec: bash would pass over the
    // stand-ins to the real git on PATH, so then nothing here is run at all.
    if (!is_executable($work.'/stand-ins/git')) $cannot = 'the temporary folder does not let programs run';
}
if ($cannot !== null) {
    test_unsupported(array_merge(test_unsupported(), ['bin/update.sh refusing wrong arguments ('.$cannot.')']));
} else {
    $updateSh = function (string ...$arguments) use ($bash, $work): array {
        $process = proc_open(array_merge([$bash, APP_ROOT.'/bin/update.sh'], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                             $pipes, $work, ['PATH' => $work.'/stand-ins:'.getenv('PATH')] + getenv());
        fclose($pipes[0]);
        $said = (string)stream_get_contents($pipes[1]).(string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['code' => proc_close($process), 'said' => $said];
    };
    foreach ([[['--ref', '--check'], '--ref needs a tag or branch.'],
              [['--clone', '--check'], '--clone needs a directory.'],
              [['--clone', 'd', '--repo', '-x'], '--repo needs a URL.'],
              [['--repo', 'https://example.test/x'], '--repo goes with --clone']] as [$arguments, $sentence]) {
        $run = $updateSh(...$arguments);
        $right = $run['code'] === 1 && str_contains($run['said'], $sentence) && !str_contains($run['said'], ' was called');
        ok($right, 'update.sh '.implode(' ', $arguments).' stops with exit code 1: "'.$sentence.'"'.($right ? '' : ' - it ended '.$run['code'].': '.trim($run['said'])));
    }
    $folder = $work.'/new-portal';
    mkdir($folder, 0700);
    $clone = $updateSh('--clone', $folder, '--repo', 'https://user:p@ss@example.test/club/crmb.git', '--check');
    is_same(0, $clone['code'], 'a first install with --check stops before it clones, with exit code 0'.($clone['code'] !== 0 ? ': '.trim($clone['said']) : ''));
    $named = str_contains($clone['said'], 'Cloning https://example.test/club/crmb.git into '.$folder);
    ok($named, 'it names the address without its user name and password'.($named ? '' : ': '.trim($clone['said'])));
    ok(!str_contains($clone['said'], 'user:') && !str_contains($clone['said'], 'p@ss'), 'and prints no part of either');
    ok(!str_contains($clone['said'], ' was called') && array_diff(scandir($folder) ?: [], ['.', '..']) === [], 'git, php and composer did nothing, and the folder is still empty');
    $help = $updateSh('--help');
    ok($help['code'] === 0 && str_starts_with($help['said'], 'Install or update the portal from Git'), '--help starts with what the script does');
    ok(!str_contains($help['said'], 'set -euo pipefail'), 'and ends with the comment, before the first line of code');
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

case_('Whether a child is in a course now is asked of current_enrolment_sql(), never spelled by hand');
/* ADR 0024 will change what "in the course now" means. The courses a family may
   ask to join, the place a request counts and the mail about a changed date each
   spelled left_on IS NULL themselves (code review), and a rule spelled in eight
   places changes in seven. Read in the SQL a block hands over, comments aside. */
$spelledByHand = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'),
                     glob(APP_ROOT.'/bin/*.php')) as $path)
    foreach (named_blocks_of($path) as $block => $code)
        foreach (sql_statements_in($code) as $sql)
            if (preg_match('/\bleft_on IS (NOT )?NULL\b/i', $sql)) $spelledByHand[] = substr($path, strlen(APP_ROOT) + 1).' '.$block;
is_same(['app/enrolment.php current_enrolment_sql'], array_values(array_unique($spelledByHand)),
        'only current_enrolment_sql() says it; every other query, ordering and mail asks it');

case_('How long a link works is said from token_lifetime(), never written into a sentence');
/* „eine Stunde" was written into the reset mail, the address mail and the
   reset link's message beside the number that decides it (code review): a
   lifetime changed in token_lifetime() would have left them promising the old
   one. The server's texts are read here; the views' are frontend-dev's. */
$writtenLifetimes = [];
foreach (glob(APP_ROOT.'/app/*.php') as $path) {
    if (basename($path) === 'ui.php') continue;
    foreach (named_blocks_of($path) as $block => $code)
        foreach (token_get_all("<?php\n".$code) as $t)
            if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/\b(eine Stunde|one hour|\d+ Stunden|\d+ hours)\b/u', $t[1]))
                $writtenLifetimes[] = substr($path, strlen(APP_ROOT) + 1).' '.$block;
}
is_same(['app/auth.php token_lifetime_words'], array_values(array_unique($writtenLifetimes)),
        'only token_lifetime_words() words one, from the number token_lifetime() gives');
is_same(['eine Stunde', 'one hour', '48 Stunden', '48 hours'],
        [token_lifetime_words('reset', false), token_lifetime_words('email', true), token_lifetime_words('invite', false), token_lifetime_words('invite', true)],
        'and says one hour, or a number of hours, in either language');

case_('A child’s age group is never stored: the pin’s column is named only by the change log’s label [ADR 0026]');
/* 038 dropped the pin. Code still writing it fails the first request after the
   update; code still reading it reads nothing and says nothing. The change log
   keeps the label, so its older lines still read „Altersgruppe". */
$named = [];
foreach (['app', 'views', 'bin', 'public'] as $folder)
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT.'/'.$folder, FilesystemIterator::SKIP_DOTS)) as $file)
        foreach (file($file->getPathname()) ?: [] as $line)
            if (str_contains($line, 'age_group_id')) $named[] = substr($file->getPathname(), strlen(APP_ROOT) + 1).': '.trim($line);
is_same(["app/history.php: 'age_group_id' => t('Altersgruppe', 'Age group'),"], $named,
        'only app/history.php’s label names it, in app/, views/, bin/ and public/');

case_('Every page switches the camera, the microphone and the location off [ADR 0022 §11.4]');
/* Nothing asks a browser for any of them: a child's photo comes through the
   file input, which hands the camera to the phone. The microphone was on while
   voice notes were recorded in the page, and a policy left open outlives the
   reason for it. One header, on the list every page is sent with
   (security_headers()): boot_http() sends it for the portal, setup.php for itself. */
is_same(['Permissions-Policy: camera=(), microphone=(), geolocation=()'],
        array_values(array_filter(security_headers(), fn(string $line): bool => stripos($line, 'Permissions-Policy:') === 0)),
        'the list holds Permissions-Policy: camera=(), microphone=(), geolocation=(), once');
$boot = defined_functions_in(APP_ROOT.'/app/bootstrap.php')['boot_http'] ?? '';
ok(str_contains($boot, 'security_headers ( )'), 'boot_http() sends that list');
is_same(0, substr_count($boot, 'Permissions-Policy'), 'and sets no second policy of its own');

case_('The address reaches every page as text: public/index.php drops every list from it first');
/* ?tab[]=x is something anybody can type. Read as text it warned „Array to
   string conversion", and the pages were guarded one read at a time - and a
   child's page, one guard short, warned (code review). The router drops every
   value that is not text before anything reads the address, boot_http() and its
   ?lang= included. The suite draws pages from what it leaves
   (render_as_front_controller()), and the forms suite draws one with lists in
   its address. */
$routerText = (string)file_get_contents(APP_ROOT.'/public/index.php');
$dropped = strpos($routerText, "\$_GET=array_filter(\$_GET,'is_string');");
ok($dropped !== false && $dropped === strpos($routerText, '$_GET') && $dropped < (int)strpos($routerText, "require __DIR__.'/../app/bootstrap.php'"),
   'its first word on the address drops what is not text, before the application is loaded');

case_('Every block that makes a login asks whether its address is taken');
/* ADR 0021, §5. An address is one person's: refuse_address_in_use() says so in a
   sentence before the unique index says it as a 23000 nobody can act on. Setup,
   the console with --force and the demo fill make logins too - "a rule that
   three creators skip is not a rule" - so nothing is exempt.

   The rule asserts once per block rather than once per call it happens to find:
   an earlier version only ever spoke about lookups that existed, so a handler
   with none passed it in silence.

   A login written with no address has none to ask about: a placeholder (ADR
   0023 §3) names email as NULL, the one value the unique index lets any number
   of logins share. That is read from the statement itself, so a block that
   started writing an address would be held to the rule again, and the blocks it
   lets through are named below. */
$writesAddress = function (string $sql): bool {
    // A statement this does not read is held to the rule.
    if (!preg_match('/^INSERT INTO `?accounts`? ?\(([^)]*)\) ?VALUES ?\((.*)\)$/i', $sql, $m)) return true;
    $at = array_search('email', array_map(fn($c) => trim($c, ' `'), explode(',', $m[1])), true);
    return $at === false ? false : strtoupper(trim(explode(',', $m[2])[$at] ?? '?')) !== 'NULL';
};
$examined = []; $addressless = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/public/*.php')) as $path) {
    foreach (named_blocks_of($path) as $name => $block) {
        $sql = sql_statements_in($block);
        $insert = array_values(array_filter($sql, fn($s) => str_contains($s, 'INSERT INTO accounts')));
        if (!$insert) continue;
        $handler = substr($path, strlen(APP_ROOT) + 1).' '.$name;
        $examined[] = $handler;
        if (!array_filter($insert, $writesAddress)) { $addressless[] = $handler; continue; }
        $calls = array_column(array_filter(action_calls_in($block), fn($c) => !$c['method']), 'index', 'name');
        ok(isset($calls['refuse_address_in_use']), $handler.' calls refuse_address_in_use()');
        $firstInsert = min(array_map(fn($c) => $c['index'], array_filter(action_calls_in($block), fn($c) => $c['dml'] && $c['name'] === 'run')) ?: [PHP_INT_MAX]);
        ok(($calls['refuse_address_in_use'] ?? PHP_INT_MAX) < $firstInsert, $handler.' asks it before it writes');
    }
}
/* Named rather than counted: a count says "five of them" whether or not the
   five are the ones that matter, and a handler that stops inserting - or a
   file truncated to nothing - would just make the count smaller. */
/* These four and nothing else (ADR 0021, §5; 0023 §3): every invitation - a
   team member's, a student's own, one by address - goes through invite_login(),
   the two exceptions are the first administrator and the example data, and a
   student's placeholder is the one login made without an address. */
$creators = ['app/actions.php invite_login', 'app/auth.php create_admin_account', 'app/demo.php demo_fill',
             // Writes no address, so it has none to ask about (ADR 0023 §3).
             'app/auth.php placeholder_login'];
foreach ($creators as $known)
    ok(in_array($known, $examined, true), 'the rule reached '.$known);
foreach (array_diff($examined, $creators) as $new)
    ok(false, $new.' creates accounts too and nobody has said so here - add it to the list above');
is_same(['app/auth.php placeholder_login'], $addressless,
        'and the only block that makes a login without an address is the placeholder, which nobody can sign in with');
ok($writesAddress("INSERT INTO accounts (name,email,role,created_at) VALUES (?,?,?,?)")
   && !$writesAddress("INSERT INTO accounts (name,email,role) VALUES (?,NULL,'student')")
   && !$writesAddress("INSERT INTO accounts (name,role) VALUES (?,'student')"),
   'what lets a block through is an address written as NULL or not at all, and nothing else');

case_('The locking read every creator shares actually holds');
$auth = named_blocks_of(APP_ROOT.'/app/auth.php');
ok(in_array('SELECT id,role,name,email FROM accounts WHERE email=? AND id<>? FOR UPDATE',
             sql_statements_in($auth['refuse_address_in_use'] ?? ''), true),
   'refuse_address_in_use() reads any other login at the address FOR UPDATE');
$authSource = (string)file_get_contents(APP_ROOT.'/app/auth.php');
foreach (['refuse_address_in_use' => fn() => refuse_address_in_use('nobody@example.test')] as $name => $call) {
    // The comment right above the function, where whoever edits it reads it.
    ok(preg_match('~/\*\*(?:(?!\*/).)*REPEATABLE READ(?:(?!\*/).)*\*/\s*function '.$name.'\(~s', $authSource) === 1,
       $name.'() says in its own comment that it relies on REPEATABLE READ (R8)');
    throws($call, $name.'() refuses outside a transaction, where it would hold nothing', 'outside a transaction');
    does_not_throw(fn() => transactional($call), 'and inside one it answers');
}

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
            // The first argument when it is written out as a string, joined as
            // above; null when it is a variable or anything else worked out.
            'literal' => $literal,
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
          'send_account_token' => 'writes an auth token', 'direct_thread' => 'creates the conversation',
          'notify_payment' => 'queues mail',
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
    'app/actions_config.php payment_remind'   => 'the reminder run, which sends mail',
    'app/actions_config.php feedback_send'    => 'a problem report, which can carry a file',
    'app/actions_config.php proof_upload'     => 'a receipt, a file kept until staff remove it',
    'app/actions_config.php picture_save'     => 'a child’s picture, the most a request asks of the server (ADR 0031 §3)',
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
        /* Every throttle of a case, not only its first: message_send counts a
           photo as well. handle_post() makes the sign-in comparison hash
           between its own, before it opens the action's transaction - nothing
           holds a lock there for the counter connection to wait on - so there
           its first is the one that matters. */
        $last = $name === 'handle_post' ? $throttles[0] : $throttles[count($throttles) - 1];
        is_same(true, $last['index'] < $write['index'],
                $where.': every throttle() is counted before '.$write['name'].'()'
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
   are. */
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
    'app/actions.php invite_login' => [
        // The last of its refusals; refuse_address_in_use() is its first line.
        'call'    => 'refuse_until_invitations_can_go',
        'guards'  => 'a login at an address that is already another login’s, or one nobody can be invited to (ADR 0021, §5)',
        'suite'   => 'accounts.php "and nothing written: no login, no link, no mail, no audit line"',
    ],
    'app/actions.php email_invite' => [
        'call'    => 'refuse_address_on_student_without_sign_in',
        'guards'  => 'an address a student without sign-in already carries (ADR 0021, §3)',
        'suite'   => 'accounts.php "and nothing written: no login, no link, no mail, no audit line"',
    ],
    'app/actions.php activate' => [
        'call'    => 'own_student_details',
        'guards'  => 'a student made of missing names or a birth date that cannot be right (ADR 0021, §3)',
        'suite'   => 'accounts.php "and nothing was written: no student, the login as it was"',
    ],
    'app/actions.php student_save' => [
        // The last of its refusals - a new address for an invitation that
        // cannot be sent - so the address refusals above it come first too.
        'refusal' => 'lässt sich erst eintragen',
        'guards'  => 'a new address for an invited login when the invitation could not go there (ADR 0020, §5)',
        'suite'   => 'accounts.php "not a single write"',
    ],
    'app/actions.php student_create' => [
        // The last of its refusals - an address a student without sign-in
        // carries, after invitation_address() - so the draft and the address
        // come first too.
        'call'    => 'refuse_address_on_student_without_sign_in',
        'guards'  => 'a student, a login or an enrolment written for an invitation that cannot go (ADR 0023 §5, 0030 §6)',
        'suite'   => 'logins.php "nothing is written by any of them"',
    ],
    'app/actions.php student_invite' => [
        // The last of its refusals - an address a brother's or sister's record
        // carries too - so the address and its login come first too.
        'call'    => 'refuse_address_on_student_without_sign_in',
        'guards'  => 'an invitation to an address another child without sign-in carries (ADR 0030 §5)',
        'suite'   => 'accounts.php "nothing is written: no link, no mail, no change"',
    ],
    // The address is checked by its callers, once, before anything is written
    // (invitation_address()); what is left to refuse here is a login that is no
    // placeholder.
    'app/actions.php invite_student' => [
        'call'    => 'placeholder_of',
        'guards'  => 'an address given to a login that is not a placeholder (ADR 0023 §3)',
        'suite'   => 'accounts.php "and nothing was written"',
    ],
    'app/actions.php delete_login' => [
        'refusal' => 'wird nie allein gelöscht',
        'guards'  => 'a student’s login deleted from under the student (ADR 0023 §4)',
        'suite'   => 'logins.php "refused in words"',
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

case_('The checks the invitations and the wizard lean on are checks, not writes');
/* Five handlers above hand a refusal to one of these, and the wizard and the
   access card ask the address once, before their first write, through them
   (code review). "Called before the write" means "nothing had been written"
   only for as long as each of them writes nothing itself. */
$checkFunctions = defined_functions_in(APP_ROOT.'/app/actions.php') + defined_functions_in(APP_ROOT.'/app/auth.php');
foreach (['refuse_until_invitations_can_go', 'refuse_address_on_student_without_sign_in', 'invitation_address', 'placeholder_of'] as $check) {
    ok(($checkFunctions[$check] ?? '') !== '', $check.'() was found');
    is_same(null, first_main_write(action_calls_in($checkFunctions[$check] ?? ''), $writers),
            $check.'() writes nothing, so calling it first really does come before every write');
}

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

case_('Only the places named here give a student a login or change the address its mail goes to');
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
        'app/actions.php create_student'     => 'makes the student with the placeholder login they have from their first moment (ADR 0023 §4), or on the login of an invitation by address whose holder makes their own (ADR 0021, §3)',
        'app/actions.php replace_login_with_placeholder' => 'moves a student onto a fresh placeholder of their own before their old login is deleted (ADR 0023 §4)',
        'app/demo.php demo_fill'             => 'example data: two fixed logins, one student each, and the index would refuse more',
        'app/auth.php give_every_student_a_login' => 'is the update’s step that gives each student without a login a fresh placeholder of their own, never one a student already has (ADR 0023 §4)',
    ],
    'email' => [
        'app/actions.php change_account_email' => 'moves the login’s address and the student’s copy together',
        'app/actions.php invite_student'       => 'writes both copies when it gives the placeholder its address',
        'app/actions.php create_student'       => 'writes the address the wizard’s invitation is about to go to, which invite_student() then gives the login, or the login’s own for a student its holder makes',
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
        ok(false, $new.' writes students.'.$column.' and is not one of the places allowed to - route it through invite_student or change_account_email');
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

case_('A family’s save of its own student writes five columns, and nothing a family may not write');
/* ADR 0020, §7. The family branch of student_save is the one write a family
   makes to a student, and its column list is the whole of what they may change:
   names, birth date, postal address, phone - plus the bookkeeping every save
   writes. Status, membership dates, level, age group, prices, internal notes
   and the email stay staff's. Read from the SQL, so a column added to the
   family's statement fails here by name. */
$save = named_blocks_of(APP_ROOT.'/app/actions.php')['student_save'] ?? '';
$updates = [];
foreach (sql_statements_in($save) as $sql)
    if (preg_match('/^UPDATE students SET (.*?) WHERE /', $sql, $set))
        $updates[] = array_map(fn($pair) => trim(explode('=', $pair)[0]), explode(',', $set[1]));
is_same(2, count($updates), 'student_save has two UPDATEs of a student: staff’s and the family’s');
$family = array_values(array_filter($updates, fn($columns) => !in_array('status', $columns, true)));
is_same([['first_name', 'last_name', 'birth_date', 'address', 'phone', 'updated_at', 'revision']], $family,
        'the family’s writes first_name, last_name, birth_date, address and phone, and the bookkeeping, and nothing else');
ok(preg_match('/\} else \{.*?tracked \( \'students\'/s', implode(' ', array_map(fn($t) => is_array($t) ? $t[1] : $t, action_tokens(substr($save, (int)strrpos($save, '} else {')))))) === 1,
   'and it runs inside tracked(), like staff’s');

case_('One answer each to „may a reset link go?“ and „is the postal address missing?“');
/* Code review 5 and 6: the action and the card ask reset_link_possible(); the
   family's list and the invoice refusal ask postal_address_missing(), so the
   card never offers what the action refuses, and „Anschrift eintragen“ is on the
   list exactly when an invoice above 400 € would be refused for it. */
$state = named_blocks_of(APP_ROOT.'/app/actions.php')['account_state'] ?? '';
ok(str_contains($state, 'reset_link_possible($a)') && !str_contains($state, "\$a['verified_at'])\n                throw"), 'account_state asks reset_link_possible()');
ok(str_contains(defined_functions_in(APP_ROOT.'/app/domain.php')['family_next_steps'] ?? '', 'postal_address_missing ('), 'family_next_steps() asks postal_address_missing()');
ok(str_contains(defined_functions_in(APP_ROOT.'/app/invoices.php')['create_invoice'] ?? '', 'postal_address_missing ('), 'and so does create_invoice()');

case_('Only the places named here set a password');
/* ADR 0020, §5: nobody sets another person's password. A password is written by
   the person choosing it - accepting an invitation, a reset link, Mein Konto -
   by the rehash a correct sign-in makes, and by the two exceptions: the first
   administrator, and the example logins whose password is shown once. A new
   writer of password_hash fails here by name. */
$hashWriters = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/public/*.php'),
                     glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/database/*.php')) as $path)
    foreach (named_blocks_of($path) as $name => $block)
        foreach (sql_statements_in($block) as $sql)
            if (preg_match('/^(UPDATE accounts SET .*password_hash=|INSERT INTO accounts \([^)]*password_hash)/', $sql))
                $hashWriters[] = substr($path, strlen(APP_ROOT) + 1).' '.$name;
$hashWriters = array_values(array_unique($hashWriters));
sort($hashWriters);
is_same(['app/actions.php activate', 'app/actions.php login', 'app/actions_settings.php password_change',
         'app/auth.php create_admin_account', 'app/demo.php demo_fill'], $hashWriters,
        'password_hash is written by activate, the rehash in login, password_change, create_admin_account() and demo_fill(), and nowhere else');
$dispatched = [];
foreach (['actions', 'actions_settings', 'actions_messages', 'actions_config'] as $file)
    if (preg_match_all("/case '([a-z_]+)':/", (string)file_get_contents(APP_ROOT.'/app/'.$file.'.php'), $m))
        $dispatched = array_merge($dispatched, $m[1]);
ok(!in_array('account_create', $dispatched, true), 'account_create, which made a login with a password staff typed, is no action any more');
$invite = named_blocks_of(APP_ROOT.'/app/actions.php')['student_invite'] ?? '';
ok($invite !== '' && !str_contains($invite, "post('mode')") && !str_contains($invite, "post('password')"),
   'and student_invite reads neither a mode nor a password, so an old page’s mode=direct is an ordinary invitation');

case_('A sign-in looks up one literal statement, by address, and only through account_for_sign_in()');
/* ADR 0021 §1, 0030 §1: one statement, no column name interpolated, the
   dot-atom gate before it, and the login and „vergessen" cases send no SELECT
   of their own. A plain read, not FOR UPDATE (security review F2): a lock held
   through password_verify() made a second sign-in to an existing login wait
   while one to a missing login did not, and the wait said which logins exist.
   The one write a sign-in makes, the rehash, is conditional on the hash it
   verified instead. */
$lookup = defined_functions_in(APP_ROOT.'/app/auth.php')['account_for_sign_in'] ?? '';
// The statement is a literal, with nothing joined to it but the literal FOR
// UPDATE, and that only for „vergessen".
preg_match_all('~\bone \( \x27([^\x27]*)\x27 (?:\. \( \$lock \? \x27 FOR UPDATE\x27 : \x27\x27 \) )?,~', $lookup, $literal);
is_same(['SELECT * FROM accounts WHERE email=?'], $literal[1], 'account_for_sign_in() sends one literal statement, by address (ADR 0030 §1)');
ok(str_contains(named_blocks_of(APP_ROOT.'/app/actions.php')['login'] ?? '', 'account_for_sign_in(attempted_address());'),
   'login reads without a lock, so a missing login and an existing one take the same path (F2)');
ok(str_contains(named_blocks_of(APP_ROOT.'/app/actions.php')['forgot'] ?? '', 'account_for_sign_in(attempted_address(),lock:true);'),
   'while „vergessen“ locks, so two requests at once cannot leave two live links');
is_same(1, substr_count($lookup, 'one ('), 'and no other, so nothing is joined into a statement');
// Found first: strpos() answers false for a gate that is gone, and false is
// "less than" any position, so the order alone would pass without it.
$gate = strpos($lookup, 'email_is_dot_atom (');
ok($gate !== false && $gate < strpos($lookup, "WHERE email=?"), 'the address behind its format gate');
ok(str_contains($lookup, "email_normalised ( (string) \$a [ 'email' ] ) === \$email"), 'and a row used only when it is exactly what was typed, so the collation’s fold finds nothing');
$loginBlock = named_blocks_of(APP_ROOT.'/app/actions.php')['login'] ?? '';
is_same(['UPDATE accounts SET password_hash=? WHERE id=? AND password_hash=?'],
        array_values(array_filter(sql_statements_in($loginBlock), fn($sql) => str_starts_with($sql, 'UPDATE'))),
        'the rehash writes only over the very hash it verified, so it needs no lock taken before');
is_same(1, count(array_filter(action_calls_in($loginBlock), fn($call) => $call['name'] === 'password_verify' && !$call['method'])),
        'and the login case runs exactly one password_verify(), whatever it found, so the time a refusal takes says nothing');
foreach (['login', 'forgot'] as $case) {
    $block = named_blocks_of(APP_ROOT.'/app/actions.php')[$case] ?? '';
    ok(str_contains($block, 'account_for_sign_in(attempted_address()'), $case.' looks up through account_for_sign_in()');
    is_same([], array_values(array_filter(sql_statements_in($block), fn($sql) => str_starts_with($sql, 'SELECT'))), 'and sends no SELECT of its own');
}

case_('What ADRs 0020, 0021 and 0030 removed is gone, and nothing calls it');
/* The shared-address machinery of ADR 0019. */
$gone = ['staff_address_conflict', 'send_sign_in_details', 'confirm_same_family', 'recent_password_reset_notice',
         'own_address_missing_sql', 'students_needing_own_address', 'own_address_taken_by', 'attempted_email', 'attempted_username',
         'logins_on_address', 'accounts_sharing_address', 'own_address_notice',
         // And what ADR 0021 removed with usernames and ADR 0023 did not bring
         // back: a username for every login, and the holder changing their own.
         'full_name_parts', 'username_from_full_name', 'give_every_account_a_username',
         'change_own_username', 'username_taken_answer', 'account_identity', 'username_attributes',
         // What ADR 0030 removed, for good: a student's username and its sign-in
         // link, with the two-kind derivation ADR 0023 §7 had put in place of
         // attempted_address().
         'attempted_sign_in', 'sign_in_identity', 'username_identity', 'username_to_give', 'give_student_username',
         'make_signin_link', 'may_create_signin_link', 'signin_link_possible', 'username_login_waiting', 'signin_links_for',
         'signin_link_shown', 'remember_signin_link', 'forget_signin_link', 'refuse_signin_link_until_privacy_released',
         'usernames_taken_near', 'username_for_new_account', 'username_suggested', 'refuse_username_in_use',
         'username_transliterated', 'username_normalised', 'username_value', 'username_from_name', 'username_first_free',
         'sign_in_name', 'typed_sign_in_name_matches', 'signin_link_details', 'sign_in_name_label'];
$mentions = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php')) as $file)
    foreach (action_calls_in((string)file_get_contents($file)) as $call)
        if (in_array($call['name'], $gone, true)) $mentions[] = $call['name'].'() in '.basename($file);
is_same([], $mentions, 'none of them is defined or called anywhere');
foreach ($gone as $name) ok(!function_exists($name), $name.'() does not exist');
ok(!defined('USERNAME_PATTERN') && !defined('USERNAME_LETTERS'), 'and the username rule’s constants are gone with it');
ok(!in_array('signin_link', $dispatched, true), 'signin_link is no action any more (ADR 0030 §4)');
ok(!file_exists(APP_ROOT.'/views/_signin_link.php'), 'views/_signin_link.php does not exist');
$appJs = (string)file_get_contents(APP_ROOT.'/public/assets/app.js');
ok(!str_contains($appJs, 'navigator.share') && !str_contains($appJs, 'data-share'), 'and app.js has no „Teilen“');
$appText = implode('', array_map('file_get_contents', glob(APP_ROOT.'/app/*.php')));
ok(!str_contains($appText, "'signin_links'"), 'no session keeps a readable link');
ok(!str_contains($appText, "'same_family'") && !str_contains($appText, 'own_password_reset'), 'no action reads same_family, and no session keeps own_password_reset');
$viewText = implode('', array_map('file_get_contents', glob(APP_ROOT.'/views/*.php')));
ok(!str_contains($viewText, 'same_family'), 'and no page offers a same_family tick');
ok(!str_contains($viewText, "'account_create'") && !str_contains($viewText, "'mode'=>'direct'"), 'nor a form that makes a login with a password typed by staff (ADR 0020, §5)');
ok(!str_contains($appText.$viewText, 'Noch nicht angemeldet'), 'and „Noch nicht angemeldet“ is said nowhere: a login is invited at its address, or it is „Ohne Anmeldung“ (ADR 0030 §3)');
ok(!is_file(APP_ROOT.'/database/migrations/024_an_address_may_be_shared.sql'), 'and migration 024 does not exist');

case_('Only app/auth.php reads auth_tokens.token_hash, and no view reads auth_tokens');
/* ADR 0020, Must not. A link that sets a password is as good as the password,
   so the hash that finds one stays in the file that makes and checks them; the
   access card reads an invitation's dates through invitation_dates(). */
$readers = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php')) as $file) {
    $text = (string)file_get_contents($file);
    if (str_contains($text, 'token_hash') && basename($file) !== 'auth.php') $readers[] = substr($file, strlen(APP_ROOT) + 1);
    if (str_starts_with(substr($file, strlen(APP_ROOT) + 1), 'views/') && str_contains($text, 'auth_tokens')) $readers[] = substr($file, strlen(APP_ROOT) + 1);
}
is_same([], $readers, 'token_hash appears in app/auth.php only, and auth_tokens in no view');
is_same(["SELECT created_at,expires_at FROM auth_tokens WHERE account_id=? AND purpose='invite' ORDER BY id DESC LIMIT 1"],
        sql_statements_in((string)(named_blocks_of(APP_ROOT.'/app/auth.php')['invitation_dates'] ?? '')),
        'invitation_dates() reads the two dates and nothing else');

case_('Only create_own_student() names the actor of a change itself, and only activate calls it');
/* ADR 0021, §3: the actor of a change-log line or an audit entry is who is
   really signed in. The one exception is the student an invitation's holder
   makes while accepting it, before anybody is signed in: activate passes the
   locked link's own login. An actor passed anywhere else would let a caller
   write somebody else's name. tracked_insert() hands its $actor on to
   history_record(), which is the plumbing. */
$naming = [];
$limits = ['audit' => 3, 'tracked' => 5, 'tracked_insert' => 3, 'history_record' => 6];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php')) as $path)
    foreach (named_blocks_of($path) as $block => $code) {
        $tokens = action_tokens($code);
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !isset($limits[$token[1]]) || ($tokens[$i + 1] ?? null) !== '(') continue;
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) continue;
            $depth = 0; $arguments = 1;
            for ($j = $i + 1; $j < count($tokens); $j++) {
                $t = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if (in_array($t, ['(', '[', '{'], true)) $depth++;
                elseif (in_array($t, [')', ']', '}'], true)) { if (--$depth === 0) break; }
                elseif ($t === ',' && $depth === 1) $arguments++;
            }
            if ($arguments > $limits[$token[1]]) $naming[] = substr($path, strlen(APP_ROOT) + 1).' '.$block.' '.$token[1].'()';
        }
    }
sort($naming);
is_same(['app/actions.php create_own_student audit()', 'app/actions.php create_own_student tracked_insert()', 'app/history.php tracked_insert history_record()'],
        $naming, 'an actor is named by create_own_student(), and only handed on below it');
$callers = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php')) as $path)
    foreach (named_blocks_of($path) as $block => $code)
        foreach (action_calls_in($code) as $call)
            if ($call['name'] === 'create_own_student' && !$call['method']) $callers[] = substr($path, strlen(APP_ROOT) + 1).' '.$block;
is_same(['app/actions.php activate'], $callers, 'create_own_student() is called from activate and nowhere else');
$activate = named_blocks_of(APP_ROOT.'/app/actions.php')['activate'] ?? '';
ok(str_contains($activate, "create_own_student((int)\$r['account_id'],(string)\$r['email'],\$details)"),
   'with the locked link’s own login and its stored address, never posted input');
ok(str_contains($activate, '$details=setup_creates_student($r) ? own_student_details() : null;'),
   'and only when the link, not the post, says the person makes their student');

// ---------------------------------------------------------------------------
case_('A file on a removed group message is served to nobody');
/* serve_download() ends the request, so no suite can call it; the messaging
   suite asks the database the same question. This holds the download to it. */
$uploadsSource = (string)file_get_contents(APP_ROOT.'/app/uploads.php');
ok(preg_match('~if \(\$what === \'attachment\'\) \{\s*\$file = one\(\'[^\']*\bWHERE f\.id=\? AND m\.removed_at IS NULL\'~', $uploadsSource) === 1,
   'the attachment is looked up only among messages that were not removed (ADR 0022)');

// ---------------------------------------------------------------------------
case_('No view overwrites what the layout reads after it');
/* public/index.php requires the page's view and then views/layout.php in one
   scope, so a view that names a variable $page hands the layout an array for the
   page's name - a chat opened as a fatal error, while render_view(), which runs a
   view inside a function, stayed green. Held here for every view. */
foreach (glob(APP_ROOT.'/views/*.php') as $view) {
    if (basename($view) === 'layout.php') continue;
    preg_match_all('~\$(page|user|public|content)\s*(?:=(?!=)|\.=|\+=)~', (string)file_get_contents($view), $assigned);
    ok(!$assigned[0], basename($view).' leaves $page, $user, $public and $content as the front controller set them'
       .($assigned[0] ? ': '.implode(', ', array_unique($assigned[0])) : ''));
}

// ---------------------------------------------------------------------------
case_('Only direct_thread() puts somebody into a chat');
/* ADR 0022: who is in a chat is written in one place, which decides the kind of
   chat and its owner with it, and who is in a group is never stored at all. The
   example data once made its chat by hand and left out the rows of who was in
   it, so the example family was told they had no messages. */
$joining = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'),
                     glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/database/*.php')) as $path)
    foreach (named_blocks_of($path) as $block => $code) {
        $where = substr($path, strlen(APP_ROOT) + 1).' '.$block;
        foreach (action_calls_in($code) as $call)
            if ($call['name'] === 'join_thread' && !$call['method']) $joining[] = $where.' calls join_thread()';
        foreach (sql_statements_in($code) as $sql)
            if (preg_match('/^(INSERT|REPLACE) INTO `?thread_participants`?[\s(]/i', $sql)) $joining[] = $where.' writes thread_participants';
    }
$joining = array_values(array_unique($joining));
sort($joining);
is_same(['app/messaging.php direct_thread calls join_thread()', 'app/messaging.php join_thread writes thread_participants'], $joining,
        'join_thread() writes who is in a chat, and direct_thread() is the only caller');

// ---------------------------------------------------------------------------
case_('Every way a course is made gives it its group chat');
/* ADR 0022 §3: a course has its group from the moment it exists, however it came
   to exist. The places that make one each had to remember course_group_thread()
   on their own (code review). So every block that writes a row into `classes`
   is found and named here, and has to ask for the group. A third way fails until
   it is added to this list, which is the moment to give it the call. Copying a
   course was the third, and went with copying (ADR 0026 §8). */
$courseMakers = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'),
                     glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/database/*.php')) as $path)
    foreach (named_blocks_of($path) as $block => $code) {
        $writes = (bool)array_filter(sql_statements_in($code),
            fn($sql) => preg_match('/^(INSERT( IGNORE)?|REPLACE) INTO `?classes`?[\s(]/i', $sql) === 1);
        if (!$writes) continue;
        $where = substr($path, strlen(APP_ROOT) + 1).' '.$block;
        $courseMakers[] = $where;
        $called = array_column(array_filter(action_calls_in($code), fn($c) => !$c['method']), 'name');
        ok(in_array('course_group_thread', $called, true), $where.' makes a course, and asks course_group_thread() for its group');
    }
sort($courseMakers);
is_same(['app/actions_config.php class_save', 'app/demo.php demo_fill'], $courseMakers,
        'a course is made by the course form and the example data, and nowhere else');

// ---------------------------------------------------------------------------
case_('While viewing as somebody, every action is refused in one place, in one sentence');
/* Viewing the portal as somebody is read-only. The refusal was spelled per
   action - the chat's dispatcher, „Alle gelesen", the status, the emoji - and
   the actions nobody had listed went through: a consent saved, a problem
   reported in the child's name. One guard at the top of dispatch_action(),
   where every action passes, before the first case; viewing_refusal() is its
   sentence, and the chat's line where the writing box would be. Read in
   German and in English, either of which a second copy would repeat. */
$refusalCopies = []; $guards = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'),
                     glob(APP_ROOT.'/bin/*.php')) as $path)
    foreach (named_blocks_of($path) as $block => $code) {
        if (str_contains($code, 'Beim Ansehen als jemand anderer') || str_contains($code, 'While viewing as somebody else')
            || str_contains($code, 'Beende zuerst die Ansicht') || str_contains($code, 'Stop viewing first'))
            $refusalCopies[] = substr($path, strlen(APP_ROOT) + 1).' '.$block;
        foreach (action_calls_in($code) as $call)
            if ($call['name'] === 'viewing_refusal' && !$call['method'] && !str_starts_with($path, APP_ROOT.'/views/'))
                $guards[] = substr($path, strlen(APP_ROOT) + 1).' '.$block;
    }
sort($refusalCopies);
is_same(['app/shell.php viewing_refusal'], $refusalCopies, 'viewing_refusal() is where the sentence is written');
// One spelling, the trainer's Austrian one: „jemand anderer" (code review).
$otherSpelling = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php')) as $path)
    if (str_contains((string)file_get_contents($path), 'jemand anderes')) $otherSpelling[] = substr($path, strlen(APP_ROOT) + 1);
is_same([], $otherSpelling, 'and every text says „jemand anderer“, never „jemand anderes“');
is_same(['app/actions.php dispatch_action'], $guards, 'and dispatch_action() is the one action that refuses with it');
$dispatch = named_blocks_of(APP_ROOT.'/app/actions.php')['dispatch_action'] ?? '';
/* Decided by the session's mark of a view rather than by impersonator()'s
   answer: that answered nobody once the viewer's login was gone, and the guard
   then let everything through as the child (security re-review N1). */
ok(preg_match("/if\(current_user\(\) && !empty\(\\\$_SESSION\['impersonator_id'\]\) && \\\$action!=='logout' && !\(\\\$action==='impersonate' && post\('mode'\)==='stop'\)\)\s*throw new UserError\(viewing_refusal\(\)\);\s*switch\(\\\$action\)/", $dispatch) === 1,
   'before its first case, decided by the session’s mark of a view, letting through only signing out and stopping the view');

// ---------------------------------------------------------------------------
case_('A link reaches its place on a page through url(), never by a „#" joined on by hand');
/* url() takes the place as '#' and encodes it once. link_button() joined its own
   on, and five other places did too: two ways of building one address, which
   encode it differently (code review C7). A literal that starts with „#" and is
   joined to something with „." is the second way. Two places may: url(), which
   puts the place on an address, and colour_normalise(), which puts the hash on
   a colour. */
$joinedHashes = function (string $source): array {
    $tokens = array_values(array_filter(token_get_all($source),
        fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $lines = [];
    foreach ($tokens as $i => $t)
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\x27"]#/', $t[1])
            && (($tokens[$i - 1] ?? null) === '.' || ($tokens[$i + 1] ?? null) === '.'))
            $lines[] = $t[2];
    return $lines;
};
foreach (["<?php echo url('a').'#'.\$x;"                                => 1,
          "<?php echo url('a').'#top';"                                 => 1,
          "<?php echo e(url('a').(\$f!==''?'#'.\$f:''));"               => 1,
          "<?php echo url('a', ['id'=>1, '#'=>\$f]);"                   => 0,
          "<?php \$c = colour_contrast(\$b, '#ffffff');"                 => 0,
          "<?php echo '<a href=\"#main\">'.\$x;"                        => 0] as $sample => $expected)
    is_same($expected, count($joinedHashes($sample)), 'the rule reads '.test_show($sample).' correctly');
$hashJoiners = [];
$hashFiles = array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'),
                         glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/database/*.php'));
foreach ($hashFiles as $path) {
    $source = (string)file_get_contents($path);
    $fileLines = explode("\n", $source);
    foreach ($joinedHashes($source) as $line) {
        // The function it is in, or the file for a view, which has none.
        $in = basename($path).' (file)';
        for ($n = $line - 1; $n >= 0; $n--)
            if (preg_match('/^\s*function\s+([a-z_][a-z0-9_]*)\s*\(/i', $fileLines[$n], $m)) { $in = $m[1]; break; }
        $hashJoiners[] = substr($path, strlen(APP_ROOT) + 1).' '.$in;
    }
}
$hashJoiners = array_values(array_unique($hashJoiners));
sort($hashJoiners);
is_same(['app/colour.php colour_normalise', 'app/core.php url'], $hashJoiners,
        'only url() joins a „#" onto an address, and colour_normalise() onto a colour ('.count($hashFiles).' files read)');

case_('Every refusal she can read is in German and English');
/* Found by the whole-app review of October 2026: „Not found“, „Invalid SMTP
   port“ and „Invalid fields“ reached her in English only, and the mail queue
   told somebody with no shell to run „composer install“. A message written
   straight into the exception, rather than through t(), is that mistake. */
$untranslated = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php')) as $path)
    foreach (file($path) as $n => $text)
        if (preg_match('/new\s+(UserError|NotFound)\(\s*[\'"]/', $text))
            $untranslated[] = substr($path, strlen(APP_ROOT) + 1).':'.($n + 1);
is_same([], $untranslated, 'no UserError or NotFound is thrown with a bare string');
ok(!str_contains((string)file_get_contents(APP_ROOT.'/app/mail.php'), 'composer install'),
   'and nothing tells her to run a command she has nowhere to type');

// ---------------------------------------------------------------------------
// ADR 0023: every student has a login, a wizard adds one, one-time sign-in links.
// Each rule below is the "Must stay true" list of the record, read from the code.

/** Every named block, in app/, bin/, public/ and database/*.php, that sends a statement $match finds, as "file block". */
$blocksSending = function (callable $match): array {
    $found = [];
    foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/public/*.php'),
                         glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/database/*.php')) as $path)
        foreach (named_blocks_of($path) as $name => $block)
            if (array_filter(sql_statements_in($block), $match)) $found[] = substr($path, strlen(APP_ROOT) + 1).' '.$name;
    $found = array_values(array_unique($found));
    sort($found);
    return $found;
};

case_('A student is inserted in two places, and both name the login [ADR 0023 §4]');
/* Every student has a login from the moment the student exists: create_student()
   with a fresh placeholder, or on the invitation's own login for
   create_own_student(), and demo_fill() with the example logins and
   placeholders. A third INSERT is how a student without a login comes back; the
   key is RESTRICT, not NOT NULL, so nothing in the database would stop it. */
$inserts = $blocksSending(fn($sql) => preg_match('/^INSERT INTO `?students`? ?\(/i', $sql) === 1);
is_same(['app/actions.php create_student', 'app/demo.php demo_fill'], $inserts,
        'INSERT INTO students is in create_student() and demo_fill(), and nowhere else');
foreach ($inserts as $where) {
    [$file, $name] = explode(' ', $where);
    foreach (sql_statements_in(named_blocks_of(APP_ROOT.'/'.$file)[$name]) as $sql)
        if (preg_match('/^INSERT INTO `?students`? ?\(([^)]*)\)/i', $sql, $columns))
            ok(in_array('account_id', array_map(fn($c) => trim($c, ' `'), explode(',', $columns[1])), true), $where.' names account_id in its insert');
}
$create = defined_functions_in(APP_ROOT.'/app/actions.php')['create_student'] ?? '';
ok(strpos($create, 'placeholder_login (') !== false && strpos($create, 'placeholder_login (') < strpos($create, 'INSERT INTO students'),
   'and create_student() makes the placeholder before it inserts the student that points to it');

case_('A child is put into a course in one place, which refuses a child without a login [ADR 0023 §4]');
/* enrol_student() is the one copy of the insert; demo_fill() writes its example
   enrolments with every login already in place. Read from the text as well as
   from the statements, because enrol_student() builds its column list. */
$enrolments = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/views/*.php')) as $path)
    foreach (named_blocks_of($path) as $name => $block)
        if (preg_match('/INSERT\s+INTO\s+`?class_students`?/i', $block)) $enrolments[] = substr($path, strlen(APP_ROOT) + 1).' '.$name;
sort($enrolments);
is_same(['app/demo.php demo_fill', 'app/enrolment.php enrol_student'], array_values(array_unique($enrolments)),
        'INSERT INTO class_students is in enrol_student() and demo_fill(), and nowhere else');
$enrol = defined_functions_in(APP_ROOT.'/app/enrolment.php')['enrol_student'] ?? '';
ok(strpos($enrol, "SELECT id, account_id FROM students WHERE id=? FOR UPDATE") !== false
   && strpos($enrol, "SELECT id, account_id FROM students WHERE id=? FOR UPDATE") < strpos($enrol, 'INSERT INTO class_students'),
   'enrol_student() locks the student and reads its login before it inserts');
ok(strpos($enrol, 'student_login_locked (') !== false && strpos($enrol, 'student_login_locked (') < strpos($enrol, 'INSERT INTO class_students'),
   'and stops at one without, through student_login_locked(), before it inserts');
ok(str_contains(defined_functions_in(APP_ROOT.'/app/domain.php')['student_login_locked'] ?? '', 'throw new LogicException'),
   'which treats a student without a login as the broken promise it is, wherever it is asked (ADR 0012)');

case_('A student’s login is replaced, never removed, and delete_login() says so first [ADR 0023 §4]');
$delete = defined_functions_in(APP_ROOT.'/app/actions.php')['delete_login'] ?? '';
ok(strpos($delete, 'SELECT first_name,last_name FROM students WHERE account_id=? FOR UPDATE') !== false
   && strpos($delete, 'SELECT first_name,last_name FROM students WHERE account_id=? FOR UPDATE') < strpos($delete, 'DELETE FROM accounts'),
   'delete_login() asks, with a lock, whether a student points to the login before it deletes it');
$replace = defined_functions_in(APP_ROOT.'/app/actions.php')['replace_login_with_placeholder'] ?? '';
ok(strpos($replace, 'UPDATE students SET account_id') !== false && strpos($replace, 'UPDATE students SET account_id') < strpos($replace, 'delete_login ('),
   'replace_login_with_placeholder() moves the student before it deletes the old login, so the student is never without one');
$state = named_blocks_of(APP_ROOT.'/app/actions.php')['account_state'] ?? '';
is_same(2, substr_count($state, 'replace_login_with_placeholder($student=lock_row(\'students\',$studentId),$a);'),
        'account_state’s delete and withdraw both replace a student’s login rather than delete it');
ok(str_contains($state, '$studentId=login_student_id($id);'), 'whatever its role, so a staff login left on a student’s record is not sent round in a circle');

case_('Every write of a login’s address asks refuse_address_in_use() first [ADR 0023, must stay true]');
/* The INSERTs are held to it above; this is the UPDATEs. A placeholder given its
   address (invite_student()) is the write ADR 0023 added. It writes the address
   invitation_address() checked, which its every caller asks first - so the
   wizard asks it once, before its first write, rather than twice. */
$addressWriters = $blocksSending(fn($sql) => preg_match('/^UPDATE `?accounts`? SET (.*?)(?: WHERE |$)/i', $sql, $set) === 1
    && in_array('email', array_map(fn($pair) => trim(explode('=', $pair)[0], ' `'), explode(',', $set[1])), true));
is_same(['app/actions.php change_account_email', 'app/actions.php invite_student'], $addressWriters,
        'accounts.email is updated by change_account_email() and invite_student(), and nowhere else');
$change = named_blocks_of(APP_ROOT.'/app/actions.php')['change_account_email'] ?? '';
$write = min(array_map(fn($c) => $c['index'], array_filter(action_calls_in($change), fn($c) => $c['dml'] && str_contains((string)$c['literal'], 'UPDATE accounts'))) ?: [PHP_INT_MAX]);
ok((call_index_in($change, 'refuse_address_in_use') ?? PHP_INT_MAX) < $write, 'change_account_email() asks refuse_address_in_use() before it writes the address');
ok(call_index_in(defined_functions_in(APP_ROOT.'/app/actions.php')['invitation_address'] ?? '', 'refuse_address_in_use') !== null,
   'invitation_address() asks refuse_address_in_use()');
/** Every named block in app/ that calls $callee, as "file block" => its text. */
$callersOf = function (string $callee): array {
    $found = [];
    foreach (glob(APP_ROOT.'/app/*.php') as $path)
        foreach (named_blocks_of($path) as $name => $block)
            if (call_index_in($block, $callee) !== null && $name !== $callee) $found[substr($path, strlen(APP_ROOT) + 1).' '.$name] = $block;
    ksort($found);
    return $found;
};
$inviters = $callersOf('invite_student');
is_same(['app/actions.php student_create', 'app/actions.php student_invite'], array_keys($inviters), 'invite_student() is called by the wizard and the access card');
foreach ($inviters as $where => $block)
    ok((call_index_in($block, 'invitation_address') ?? PHP_INT_MAX) < call_index_in($block, 'invite_student'),
       $where.' asks invitation_address() before it hands invite_student() the address');

case_('The word username appears only where it names something other than a login’s name [ADR 0030 §7]');
/* Not "nowhere": the HTML token a password manager pairs a password with, the
   SMTP user, the database user and the change log's label for lines written
   while logins had one are not a login's name. The rule names each place by
   file and form, so the next one has to be argued; a bare search would have
   failed on all four. Everything else that spells the word - a column in a
   statement, a box, a sentence - fails here. */
$allowed = [
    // 1. autocomplete="username": the HTML token, in the helper every sign-in box uses and its docblock.
    'app/ui.php'               => ['/\'autocomplete\'=>\'username\'/', '/autocomplete="username"/'],
    // 2. the SMTP user: the smtp setting's key, read by the mailer, written by the settings page.
    'app/mail.php'             => ['/\$s\[\'username\'\]/', '/->Username/'],
    'app/actions_settings.php' => ['/\'username\'=>text_limit\(\'username\',254\)/'],
    'views/settings.php'       => ['/input\(\'username\',t\(\'SMTP-Benutzername\',\'SMTP username\'\),\$smtp\[\'username\'\]/'],
    // 3. the database user: config/config.php's key, read by connect(), written by the installer and setup.php.
    'app/core.php'             => ['/\$c\[\'username\'\]/'],
    'app/install.php'          => ['/\$db\[\'username\'\]/', '/\'username\' => /', '/\[\'db\'\]\[\'username\'\]/'],
    'public/setup.php'         => ['/\'username\' => \$form\[\'db_user\'\]/', '/\$db\[\'username\'\]/'],
    // 4. the change log's label for lines written while logins had a username (022 to 024, 028 to 039).
    'app/history.php'          => ['/\'username\' => t\(\'Benutzername\', \'Username\'\),/'],
];
$stray = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php')) as $path) {
    $file = substr($path, strlen(APP_ROOT) + 1);
    foreach (explode("\n", (string)file_get_contents($path)) as $n => $line) {
        if (stripos($line, 'username') === false) continue;
        $rest = $line;
        foreach ($allowed[$file] ?? [] as $form) $rest = (string)preg_replace($form, '', $rest);
        if (stripos($rest, 'username') !== false) $stray[] = $file.':'.($n + 1).' '.trim($line);
    }
}
is_same([], $stray, 'every mention of username is one of the four forms, in its file');
foreach ($allowed as $file => $forms)
    ok(array_filter($forms, fn($form) => preg_match($form, (string)file_get_contents(APP_ROOT.'/'.$file)) === 1) !== [],
       $file.' still holds a form the list allows, so the list is not stale');
// No purpose, action, session key, audit kind or function spells signin (ADR 0030 §4) - "signing" is a word.
$signin = [];
foreach (glob(APP_ROOT.'/app/*.php') as $path)
    foreach (explode("\n", (string)file_get_contents($path)) as $n => $line)
        if (preg_match('/signin(?!g)/', $line)) $signin[] = substr($path, strlen(APP_ROOT) + 1).':'.($n + 1).' '.trim($line);
is_same([], $signin, 'and nothing in app/ spells signin');

/**
 * Every statement in a stretch of PHP that writes $table, each with the calls
 * that enclose it, outermost first: the walk of enclosing_calls_of(), stopped at
 * a literal rather than at a name. Literals joined with '.' are read as one
 * statement, the way the database receives them.
 */
function enclosed_writes_of(string $php, string $table): array {
    $tokens = array_values(array_filter(token_get_all("<?php\n".$php),
        fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $open = []; $found = []; $literal = null; $inside = null;
    foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $literal = ($literal ?? '').substr($token[1], 1, -1);
            $inside ??= array_values(array_filter($open, fn($n) => $n !== null));
            continue;
        }
        if ($token === '.') continue;
        if ($literal !== null) {
            if (preg_match('/^(?:INSERT INTO|REPLACE INTO|UPDATE|DELETE FROM)\s+`?'.preg_quote($table, '/').'`?\b/i', trim($literal)))
                $found[] = ['sql' => (string)preg_replace('/\s+/', ' ', $literal), 'inside' => $inside];
            $literal = null; $inside = null;
        }
        if ($token === '(') {
            $before = $tokens[$i - 1] ?? null;
            $open[] = (is_array($before) && $before[0] === T_STRING) ? $before[1] : null;
        } elseif ($token === ')') array_pop($open);
    }
    return $found;
}

case_('Every write of a payment recipient is inside tracked() or tracked_insert() [ADR 0025]');
/* The owner: "trainer should be able to change iban". An IBAN decides where the
   families' money goes, and profile_save wrote it with a plain UPDATE, so the
   change log never saw what it had been: who changed it was in the audit log,
   what it was before was nowhere. Read in the SQL each block hands over and the
   calls around it, so a second place that writes the table - a save from another
   page, a delete - fails here by name until it is tracked too. The seed in
   database/defaults.php is the install's, made by nobody, and stays as it is.
   ADR 0025 excepted duplicate_record() as well; it went with app/duplicate.php
   (ADR 0026 §8), so nothing is excepted now. */
is_same(['app/actions_config.php profile_save', 'database/defaults.php defaults.php (file)'],
        $blocksSending(fn($sql) => preg_match('/^(?:INSERT INTO|REPLACE INTO|UPDATE|DELETE FROM) `?payment_profiles`?\b/i', $sql) === 1),
        'payment_profiles is written by profile_save and by the install’s seed, and nowhere else');
$recipientWrites = 0;
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/views/*.php')) as $path)
    foreach (named_blocks_of($path) as $name => $block)
        foreach (enclosed_writes_of($block, 'payment_profiles') as $write) {
            $recipientWrites++;
            ok(array_intersect($write['inside'], ['tracked', 'tracked_insert']) !== [],
               substr($path, strlen(APP_ROOT) + 1).' '.$name.' runs its '.strtok($write['sql'], ' ').' of payment_profiles inside tracked() or tracked_insert()'
               .' (found inside '.(implode(' > ', $write['inside']) ?: 'no call').')');
        }
ok($recipientWrites >= 2, 'profile_save’s UPDATE and INSERT were found and followed ('.$recipientWrites.'), so a pass here is not an empty search');

case_('A link is asked link_usable() by the page it opens and by the action that uses it alike');
/* The page once asked less than the action: it offered the form for a link
   activate refuses, and printed that login's address to whoever held the link
   (security re-review N2). And link_usable() names the three purposes there
   are, so a row left by a sign-in link from before ADR 0030 opens nothing. */
ok(call_index_in(named_blocks_of(APP_ROOT.'/app/actions.php')['activate'] ?? '', 'link_usable') !== null, 'activate asks link_usable() before it reads the link');
ok(str_contains((string)file_get_contents(APP_ROOT.'/views/activate.php'), '<?php if(!link_usable($r)):'), 'and so does the page, before it shows anything of the login');
// Each purpose with the one state it is for, and no purpose besides the three
// (ADR 0030 §2, security review F3); the behaviour is in logins.php.
$usable = defined_functions_in(APP_ROOT.'/app/auth.php')['link_usable'] ?? '';
ok(str_contains($usable, "match ( \$r [ 'purpose' ] ) { 'invite' => \$r [ 'state' ] === 'invited' , 'reset' , 'email' => \$r [ 'state' ] === 'active' , default => false , }"),
   'link_usable() pairs an invitation with a login invited, a reset and a changed address with one in use, and opens nothing else');
ok(call_index_in(defined_functions_in(APP_ROOT.'/app/mail.php')['security_mail_links_live'] ?? '', 'link_usable') !== null,
   'and the sender asks it before a mail with a link goes out, rather than a rule of its own');

case_('The wizard’s drafts live in the session, written by their own function only [ADR 0023 §5]');
$sessionWriters = ['student_drafts' => []];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php')) as $path)
    foreach (named_blocks_of($path) as $name => $block)
        foreach (array_keys($sessionWriters) as $key)
            if (preg_match('/\$_SESSION\[\''.$key.'\'\](\[[^\]]*\])*\s*(?:=(?!=)|\?\?=)/', $block)) $sessionWriters[$key][] = substr($path, strlen(APP_ROOT) + 1).' '.$name;
is_same(['app/actions.php keep_student_draft'], $sessionWriters['student_drafts'], 'only keep_student_draft() writes a draft');
$keepCallers = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php')) as $path)
    foreach (named_blocks_of($path) as $name => $block)
        foreach (action_calls_in($block) as $call)
            if ($call['name'] === 'keep_student_draft' && !$call['method']) $keepCallers[] = substr($path, strlen(APP_ROOT) + 1).' '.$name;
is_same(['app/actions.php student_draft', 'app/actions.php student_create'], $keepCallers,
        'and only the wizard’s two actions call it - step 1 for the draft, step 2 for the child it became - never a page');
$wizardView = (string)file_get_contents(APP_ROOT.'/views/student_new.php');
ok(!preg_match('/\b(run|tracked|tracked_insert|create_student|keep_student_draft)\s*\(/', $wizardView),
   'the wizard’s page writes nothing: no statement, no draft');
/* What a session holds for its person is dropped in one place, and that place
   is asked wherever a sign-in starts or ends (security review, finding 4). */
$authFunctions = defined_functions_in(APP_ROOT.'/app/auth.php');
foreach (['impersonator_id', 'impersonator_auth_version', 'student_drafts', 'activation_hash', 'answered_forms'] as $key)
    ok(str_contains($authFunctions['forget_session_leftovers'] ?? '', "\$_SESSION [ '".$key."' ]"), 'forget_session_leftovers() drops '.$key);
foreach (['sign_in' => 'signing in', 'current_user' => 'a session that ended'] as $fn => $when)
    ok(call_index_in($authFunctions[$fn] ?? '', 'forget_session_leftovers') !== null,
       $fn.'() asks it at '.$when.', so the next person on the browser has none of it');
ok(call_index_in(defined_functions_in(APP_ROOT.'/app/shell.php')['impersonator'] ?? '', 'forget_session_leftovers') !== null,
   'and so does impersonator(), as a view whose viewer’s login has ended takes its session with it (security re-review N1)');
/* Not activate, as it signs out whoever was here before it sets up the link's
   own login: a write failing after that took the link being opened with it,
   and a passing „Speichern fehlgeschlagen" became „Link nicht mehr gültig"
   (code review). sign_in() at its end forgets the rest. */
ok(call_index_in(named_blocks_of(APP_ROOT.'/app/actions.php')['activate'] ?? '', 'forget_session_leftovers') === null,
   'activate leaves what the session holds to sign_in(), so a failed write keeps the link being opened');

case_('A file opens in the tab it was tapped in, and the waiting code leaves its link alone [Part 0.4b]');
/* serve_download() answers with the file and no page. In the iPhone's
   home-screen app a link that opens a new tab opens a browser view with
   Safari's cookies, not the app's: a family tapping a PDF, a receipt or a
   chat photo would land on the sign-in page there, and might sign in a second
   time on a shared phone (security review, 2026-10-08). So a file opens where
   it is tapped, as it always did, and app.js knows the router's download page
   by its name: for a page that never comes it neither holds the link pressed
   nor starts the waiting page. Read from where links are drawn: an <a> whose address
   is that page, written in place or through a variable that holds it. */
$download = preg_match('~if\(\$page===\'(\w+)\'\)serve_download\(\);~', (string)file_get_contents(APP_ROOT.'/public/index.php'), $served) ? $served[1] : '';
ok($download !== '', 'the router answers one page with a file ('.$download.')');
$fileLinks = 0; $newTab = [];
$route = 'url\(\s*[\'"]'.preg_quote($download, '~').'[\'"]';
foreach (array_merge(glob(APP_ROOT.'/views/*.php'), [APP_ROOT.'/app/ui.php']) as $file) {
    $source = (string)file_get_contents($file);
    preg_match_all('~\$(\w+)\s*=\s*'.$route.'~', $source, $holders);
    $address = $route.($holders[1] ? '|\$(?:'.implode('|', array_unique($holders[1])).')\)' : '');
    // An <a ...> up to the end of its attributes, the PHP printed into it included.
    preg_match_all('~<a\s(?:[^<>]|<\?=.*?\?>)*>~s', $source, $tags, PREG_OFFSET_CAPTURE);
    foreach ($tags[0] as [$tag, $offset]) {
        if (!preg_match('~href="<\?=e\((?:'.$address.')~', $tag)) continue;
        $fileLinks++;
        if (preg_match('~\starget="(?!_self")~', $tag))
            $newTab[] = substr($file, strlen(APP_ROOT) + 1).':'.(substr_count($source, "\n", 0, $offset) + 1);
    }
}
ok($fileLinks >= 6, $fileLinks.' links to a file found');
is_same([], $newTab, 'each opens in the tab it was tapped in');
ok(preg_match('~get\(\s*([\'"])page\1\s*\)\s*===\s*([\'"])'.preg_quote($download, '~').'\2~', (string)file_get_contents(APP_ROOT.'/public/assets/app.js')) === 1,
   'and app.js leaves a link to that page alone, by the name the router gives it');

case_('The shuttlecock is one drawing: the mark on the page and the icon in the browser tab [C16a]');
test_load_actions();
$drawing = fn(string $svg): array => [preg_match('~<path d="([^"]+)"~', $svg, $skirt) ? $skirt[1] : '',
                                     preg_match('~<circle cx="([^"]+)" cy="([^"]+)" r="([^"]+)"~', $svg, $cork) ? array_slice($cork, 1) : []];
$onPage = $drawing(icon('shuttle'));
ok($onPage[0] !== '' && $onPage[1] !== [], 'icon(\'shuttle\') draws a skirt and a cork');
is_same($onPage, $drawing((string)file_get_contents(APP_ROOT.'/public/assets/favicon.svg')), 'and favicon.svg draws the same ones');

case_('The home-screen icons have the sizes they are offered at, and only the square ones are opaque [C16a]');
/* An iPhone rounds the icon itself and shows see-through corners black, and
   Android cuts a maskable icon to its own shape, so those two are squares
   without an alpha channel; the 192 and the 512 keep their rounded corners,
   which only an alpha channel can make see-through. Read from each file's own
   header, so no image library is needed. */
$png = function (string $name): array {
    $head = (string)@file_get_contents(APP_ROOT.'/public/assets/'.$name, false, null, 0, 26);
    if (strlen($head) < 26 || substr($head, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($head, 12, 4) !== 'IHDR') return ['not a PNG'];
    $ihdr = unpack('Nwidth/Nheight/Cdepth/Ctype', substr($head, 16, 10));
    return [$ihdr['width'], $ihdr['height'], [2 => 'opaque', 6 => 'see-through where drawn so'][$ihdr['type']] ?? 'colour type '.$ihdr['type']];
};
is_same([180, 180, 'opaque'], $png('apple-touch-icon.png'), 'apple-touch-icon.png is 180 square and opaque');
is_same([512, 512, 'opaque'], $png('icon-maskable.png'), 'icon-maskable.png is 512 square and opaque');
is_same([192, 192, 'see-through where drawn so'], $png('icon-192.png'), 'icon-192.png is 192, with its corners see-through');
is_same([512, 512, 'see-through where drawn so'], $png('icon-512.png'), 'icon-512.png is 512, with its corners see-through');

// ---------------------------------------------------------------------------
// ADR 0031: pictures. Each rule below is the record's "Must stay true", read
// from the code; the behaviour is in the pictures, uploads and robustness suites.

case_('One helper draws a person: no view draws an avatar or initials by hand [ADR 0031 §6, test 4]');
/* The attendance row drew its own initials, so it would never have shown a
   face, and a rule about who sees one could never have reached it. Only
   avatar() writes an avatar's markup, and nothing slices a name into initials
   but initials(). */
$handMade = [];
/* The class anywhere in a tag's list, however the list is written - "avatar
   small", "face avatar", with more attributes after it, or put together in PHP
   - and not a class of its own that only starts with the word, such as
   avatar-add. An avatar's circle around an icon - an invitation not yet
   anybody's - is no person. */
$drawsAPerson = function (string $code): bool {
    preg_match_all('/class=\\\\?(["\'])((?:(?!\\\\?\1).)*)\\\\?\1[^>]*>(?!<\?=icon\()/s', $code, $tags);
    return preg_grep('/(?<![\w-])avatar(?![\w-])/', $tags[2]) !== [];
};
foreach (['<span class="avatar">MA</span>' => true, '<span class="face avatar">MA</span>' => true,
          '<span class="avatar small" title="Mia">MA</span>' => true, '<span class=\"tiny avatar\">MA</span>' => true,
          "'<span class=\"'.e('large avatar').'\">'.e(\$initials)" => true,
          '<button class="avatar-add" type="submit">' => false, '<div class="avatar-editor">' => false, '<span class="my-avatar">MA</span>' => false,
          '<span class="avatar"><?=icon(\'mail\')?></span>' => false] as $sample => $person)
    is_same($person, $drawsAPerson($sample), ($person ? 'a person drawn by hand is found: ' : 'and this is no person: ').$sample);
foreach (array_merge(glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/app/*.php')) as $path) {
    $file = substr($path, strlen(APP_ROOT) + 1);
    foreach (named_blocks_of($path) as $block => $code) {
        if ($file === 'app/shell.php' && in_array($block, ['avatar', 'initials'], true)) continue;
        if ($drawsAPerson($code)) $handMade[] = $file.' '.$block.' writes an avatar’s markup';
        if (preg_match('/mb_substr\(\s*\$[a-z]+\[\'(?:first|last)_name\'\]\s*,\s*0\s*,\s*1\s*\)/', $code)) $handMade[] = $file.' '.$block.' slices a name into initials';
    }
}
is_same([], $handMade, 'no view and no other function draws a person: only avatar() does, the attendance row included');
ok(str_contains(defined_functions_in(APP_ROOT.'/app/shell.php')['avatar'] ?? '', 'may_see_picture ('), 'and avatar() asks the rule before it draws a face');

case_('A photo is decoded only as a JPEG or a PNG, by the function of its own type [ADR 0031 §3, the security review]');
/* gd brings the host's native decoders, the first a family's file reaches:
   imagecreatefromstring() takes whatever gd was built with - WebP among them,
   where CVE-2023-4863 was - so it is called nowhere, and a photo is decoded by
   the function of the one type read from its bytes. */
$decoders = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php')) as $path)
    foreach (named_blocks_of($path) as $block => $code)
        foreach (action_calls_in($code) as $call)
            if (!$call['method'] && str_starts_with(strtolower($call['name']), 'imagecreatefrom')) $decoders[] = substr($path, strlen(APP_ROOT) + 1).' '.$block.' '.$call['name'];
sort($decoders);
is_same(['app/uploads.php square_picture imagecreatefromjpeg', 'app/uploads.php square_picture imagecreatefrompng'], $decoders,
        'square_picture() decodes with imagecreatefromjpeg() and imagecreatefrompng(), and nothing anywhere decodes otherwise');
is_same(['image/jpeg', 'image/png'], array_keys(picture_types()), 'and the types a picture is made from are those two');

case_('No picture’s address leaves the pages that draw it: no mail, notice or PDF holds one [ADR 0031 §5, test 11]');
/* A picture is drawn by avatar() and served by the route, and named nowhere
   else - not in a mail, a notice in the bell, an invoice, an export or a page
   for somebody signed out. */
$addressedAt = [];
foreach (array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/public/*.php')) as $path)
    foreach (named_blocks_of($path) as $block => $code)
        if (preg_match('/what\'?\s*(?:=>|===|==|=)\s*\'?picture\b/', $code)) $addressedAt[] = substr($path, strlen(APP_ROOT) + 1).' '.$block;
is_same(['app/shell.php avatar', 'app/uploads.php serve_download'], $addressedAt, 'what=picture is in avatar() and the route, and nowhere else');
$logout = named_blocks_of(APP_ROOT.'/app/actions.php')['logout'] ?? '';
ok(str_contains($logout, "header('Clear-Site-Data: \"cache\"')"), 'signing out asks the browser to forget the pictures it keeps for a week (the robustness suite reads the header)');

case_('A picture is written by its one writer, and the yes for the course said by the family alone [ADR 0031 §7, §8, test 12]');
$writesColumn = fn(string $table, string $column) => fn(string $sql): bool
    => preg_match('/^(?:UPDATE `?'.$table.'`? SET .*\b'.$column.'\s*=|INSERT INTO `?'.$table.'`? ?\([^)]*\b'.$column.'\b)/i', $sql) === 1;
is_same(['app/actions_config.php write_child_picture'], $blocksSending($writesColumn('students', 'picture_name')),
        'students.picture_name is written by write_child_picture() and nowhere else');
is_same(['app/actions_config.php write_team_picture'], $blocksSending($writesColumn('accounts', 'picture_name')),
        'accounts.picture_name by write_team_picture() and nowhere else');
$teamWriter = defined_functions_in(APP_ROOT.'/app/actions_config.php')['write_team_picture'] ?? '';
$teamCheck = strpos($teamWriter, 'team_picture_holder ( lock_row (');
ok($teamCheck !== false && $teamCheck < (int)strpos($teamWriter, 'UPDATE accounts SET picture_name')
   && str_contains(defined_functions_in(APP_ROOT.'/app/actions_config.php')['team_picture_holder'] ?? '', '! is_staff ( $login )'),
   'which refuses a login that is not the team’s before it writes: a student’s login never has a picture of its own');
$childWriter = defined_functions_in(APP_ROOT.'/app/actions_config.php')['write_child_picture'] ?? '';
ok(str_contains($childWriter, "tracked ( 'students'") && str_contains($teamWriter, "tracked ( 'accounts'"), 'each inside tracked(), so „Änderungen" says who');
ok(preg_match("/delete_upload \( 'picture' , \\\$old \)\s*;\s*}\s*$/", $childWriter) === 1 && preg_match("/delete_upload \( 'picture' , \\\$old \)\s*;\s*}\s*$/", $teamWriter) === 1,
   'and each deletes the file it replaced, last');
$sayingYes = [];
foreach ($blocksSending($writesColumn('students', 'course_sees_picture')) as $where) {
    [$file, $block] = explode(' ', $where, 2);
    $code = named_blocks_of(APP_ROOT.'/'.$file)[$block] ?? '';
    foreach (sql_statements_in($code) as $sql)
        if ($writesColumn('students', 'course_sees_picture')($sql) && !preg_match('/\bcourse_sees_picture=0\b/', $sql)) $sayingYes[] = $where;
}
is_same(['app/actions_config.php picture_consent'], array_values(array_unique($sayingYes)),
        'course_sees_picture is set to anything but 0 in one place, picture_consent');
$consent = named_blocks_of(APP_ROOT.'/app/actions_config.php')['picture_consent'] ?? '';
$refusal = refusal_index_in($consent, 'Einschalten kann nur die Familie');
$staffAsked = call_index_in($consent, 'is_staff');
$write = min(array_map(fn($c) => $c['index'], array_filter(action_calls_in($consent), fn($c) => $c['dml'])) ?: [PHP_INT_MAX]);
ok($refusal !== null && $staffAsked !== null && $staffAsked < $refusal && $refusal < $write,
   'which refuses staff, and anybody but the child’s own login, before it writes');
is_same(['app/actions.php replace_login_with_placeholder', 'app/actions_config.php picture_consent', 'app/actions_config.php write_child_picture'],
        $blocksSending($writesColumn('students', 'course_sees_picture')),
        'and it is ended - set to 0 - by a picture staff put on and by a replaced login, and by nothing else');
ok(isset(upload_references()['picture']), 'upload_references() names the picture folder, so the prune knows what is in use');
