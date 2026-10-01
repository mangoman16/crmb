#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Runs the test suites in tests/suites/.
 *
 *   php tests/run.php                 every suite, sqlite driver
 *   php tests/run.php billing dates   only those suites
 *   CRM_TEST_DRIVER=mysql php tests/run.php     against a real *_test database
 *
 * Exit code 0 when everything passed, 1 on any failure, 2 on a setup problem.
 */

require __DIR__.'/harness.php';
test_boot();

$only = array_slice($argv, 1);
$files = glob(TEST_ROOT.'/suites/*.php');
sort($files);

$state = test_state();
$start = microtime(true);
// Where everything this run writes goes. Printed because it is the one line an
// operator can check to know these are tests that stay out of the portal's own
// folder - TESTING.md tells her to look for it - and because a run the host
// kills part-way leaves that folder behind for her to find.
printf("driver: %s\nfiles:  %s (removed when the run ends)\n\n", test_driver(), test_run_dir());

foreach ($files as $file) {
    $name = basename($file, '.php');
    if ($only && !in_array($name, $only, true)) continue;
    $state->suite = $name;
    $state->case = '';
    $before = $state->failed;
    $passedBefore = $state->passed;
    test_reset();
    try {
        (static function (string $f) { require $f; })($file);
    } catch (Throwable $e) {
        $state->failed++;
        $state->failures[] = $name.': suite aborted — '.get_class($e).': '.$e->getMessage()
            .' @ '.basename($e->getFile()).':'.$e->getLine();
    }
    $failedHere = $state->failed - $before;
    printf("  %-22s %3d passed%s\n", $name, $state->passed - $passedBefore,
        $failedHere ? sprintf(', %d FAILED', $failedHere) : '');
}

printf("\n%d passed, %d failed in %.2fs\n", $state->passed, $state->failed, microtime(true) - $start);

if ($state->failures) {
    echo "\nFailures:\n";
    foreach ($state->failures as $f) echo "  - ".$f."\n";
}

// Printed on every driver, not just sqlite: a suite that has to reach outside
// the run's own database says so here, and on mysql that note is the only place
// it would appear.
if (test_unsupported()) {
    echo test_driver() === 'sqlite'
        ? "\nNot covered by the sqlite driver (run with CRM_TEST_DRIVER=mysql to cover these):\n"
        : "\nNot covered by this run:\n";
    foreach (array_unique(test_unsupported()) as $u) echo "  - ".$u."\n";
}

exit($state->failed ? 1 : 0);
