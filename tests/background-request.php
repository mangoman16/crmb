<?php
declare(strict_types=1);

/**
 * The end of one request, for the install suite: a session held the way a page
 * holds it, and the background work that follows the page (run_background_tasks()).
 *
 *   CRM_CONFIG=<config> php tests/background-request.php
 *
 * In a process of its own, because PHP starts a session only before anything is
 * printed, and the suite has printed by then. The session is kept by a handler
 * of this file's, which notes what the run's stamp, tick_last_run, says at the
 * moment the session is let go: the work stamps it as it begins, so a stamp
 * still empty means the session was let go before any of the work. The last
 * line printed is JSON.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../app/bootstrap.php';

$session = new class implements SessionHandlerInterface {
    /** tick_last_run as the session was let go, or null while it is held. */
    public ?string $stampWhenLetGo = null;
    public string $written = '';
    public function open(string $path, string $name): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { $this->written = $data; return true; }
    public function close(): bool {
        setting_cache_clear();
        $this->stampWhenLetGo ??= (string)setting('tick_last_run');
        return true;
    }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max): int|false { return 0; }
};
session_set_save_handler($session, false);
session_start();
$_SESSION['page'] = 'written by the page';

set_setting('auto_background', true);
set_setting('tick_last_run', '');
run_background_tasks();

setting_cache_clear();
echo "\n", json_encode(['let_go' => $session->stampWhenLetGo, 'written' => $session->written,
                        'stamped' => (string)setting('tick_last_run')], JSON_UNESCAPED_SLASHES), "\n";
