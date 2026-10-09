<?php
declare(strict_types=1);

/**
 * One photo made into a picture (square_picture()), in a process of its own,
 * for the uploads suite:
 *
 *   CRM_CONFIG=<config> php -d memory_limit=64M tests/picture-request.php <file> <type>
 *
 * In a process of its own because the case it is for must never run out of
 * memory: a header claiming more pixels than a host could hold. Were the check
 * of the header gone, the suite's own process would end there, and every check
 * after it with it; this one ends instead, and the suite reads that it did. The
 * last line printed is JSON: what was said, how many bytes were made, and how
 * far the memory rose while it was asked.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../app/bootstrap.php';

memory_reset_peak_usage();
$before = memory_get_peak_usage();
try {
    $made = strlen(square_picture((string)($argv[1] ?? ''), (string)($argv[2] ?? '')));
    $said = '';
} catch (UserError $e) {
    $made = 0;
    $said = $e->getMessage();
}
echo "\n", json_encode(['said' => $said, 'made' => $made, 'rose' => memory_get_peak_usage() - $before], JSON_UNESCAPED_UNICODE), "\n";
