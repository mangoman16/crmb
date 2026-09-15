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
    'app/skills.php' => 60, 'app/tx.php' => 40, 'app/ui.php' => 30,
    'app/validate.php' => 40, 'public/index.php' => 30, 'bin/console.php' => 60,
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

case_('Every migration parses into statements');
foreach (glob(APP_ROOT.'/database/migrations/*.sql') as $file)
    ok(count(split_sql((string)file_get_contents($file))) > 0, basename($file).' contains statements');

case_('Every function called in the application is defined');
$defined = []; $called = [];
$files = array_merge(glob(APP_ROOT.'/app/*.php'), glob(APP_ROOT.'/views/*.php'),
                     glob(APP_ROOT.'/public/*.php'), glob(APP_ROOT.'/bin/*.php'), glob(APP_ROOT.'/database/*.php'));
foreach ($files as $file) {
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
    if (isset($defined[$name]) || function_exists($name) || in_array($name, $keywords, true)) continue;
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
             'icon','link_button','qr_svg','progress_chart',        // build their own markup and escape inside
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
foreach (glob(APP_ROOT.'/views/*.php') as $view) {
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
ok(true, 'checked '.$checked.' printed expressions across '.count(glob(APP_ROOT.'/views/*.php')).' views');
