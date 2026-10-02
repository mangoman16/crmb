#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Runs the test suites in tests/suites/.
 *
 *   tests/mariadb-local.sh                   every suite, on a throwaway MariaDB
 *   tests/mariadb-local.sh billing dates     only those suites
 *   CRM_CONFIG=<config> php tests/run.php    on a *_test database you already have
 *
 * Without CRM_CONFIG it refuses and says which of the two scripts to run; see
 * test_boot() in tests/harness.php for every database it refuses.
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
// Which database the run empties, on which server, and where everything it
// writes goes. Printed because these are the lines an operator can check to know
// the run is on a test database and stays out of the portal's own folder -
// TESTING.md tells her to look for the second - and because a run the host kills
// part-way leaves that folder behind for her to find.
printf("database: %s on %s\nfiles:  %s (removed when the run ends)\n\n",
    (string)config('db')['database'], (string)scalar('SELECT VERSION()'), test_run_dir());

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

// A suite that has to reach outside the run's own database, and could not, says
// so here; a green run that skipped it would otherwise read as one that checked.
if (test_unsupported()) {
    echo "\nNot covered by this run:\n";
    foreach (array_unique(test_unsupported()) as $u) echo "  - ".$u."\n";
}

exit($state->failed ? 1 : 0);
