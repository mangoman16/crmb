<?php
declare(strict_types=1);

/**
 * One request to public/setup.php, made from the command line, for the install
 * suite.
 *
 *   CRM_CONFIG=<config> php tests/setup-request.php <request.json>
 *
 * The page decides what to offer from the configuration it finds, the database
 * that configuration names and the request itself, so it is asked in a process
 * of its own: CRM_CONFIG points it at a configuration the suite wrote into the
 * run's own folder, and nothing it does can reach the portal's.
 *
 * The request file names the method, the host, whether it arrived over HTTPS,
 * any forwarded headers, the address's query, the fields posted and the cookies
 * sent. What comes back on the last line is JSON: the status the page set and
 * the page itself.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$request = json_decode((string)file_get_contents($argv[1] ?? ''), true);
if (!is_array($request)) { fwrite(STDERR, "Usage: php tests/setup-request.php <request.json>\n"); exit(2); }

$_SERVER['REQUEST_METHOD'] = (string)$request['method'];
$_SERVER['HTTP_HOST'] = (string)$request['host'];
$_SERVER['SERVER_NAME'] = (string)$request['host'];
$_SERVER['REQUEST_URI'] = (string)($request['path'] ?? '/setup.php');
$_SERVER['SERVER_PORT'] = $request['https'] ? 443 : 80;
if ($request['https']) $_SERVER['HTTPS'] = 'on';
foreach ((array)($request['headers'] ?? []) as $name => $value)
    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string)$name))] = (string)$value;
$_GET = (array)($request['query'] ?? []);
$_POST = (array)$request['post'];
$_COOKIE = (array)$request['cookie'];

// Read at shutdown, because the page may end the request itself.
ob_start();
register_shutdown_function(static function (): void {
    $body = (string)ob_get_clean();
    echo "\n", json_encode(['status' => http_response_code() ?: 200, 'body' => $body],
                           JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
});
require __DIR__ . '/../public/setup.php';
