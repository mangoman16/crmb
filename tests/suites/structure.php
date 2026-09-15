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
