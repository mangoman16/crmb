<?php
declare(strict_types=1);

/**
 * The work a cron job would do, done by the portal itself.
 *
 * Shared hosting gives the operator a panel, not a shell, and a cron line that
 * never got set up means no invitation, no password reset and no payment
 * reminder ever leaves the server. So the same three jobs the console exposes
 * also run after a page has been delivered: the mail queue, the nightly prune,
 * and optionally the monthly charges.
 *
 * Three things keep this from being a burden on a page view:
 *   - it runs after the response has been sent, so nobody waits for it;
 *   - an advisory lock means one worker at a time across every visitor and any
 *     real cron job that is also configured;
 *   - a stored timestamp means at most one run per interval, so a busy minute
 *     costs one background run rather than one per request.
 *
 * With a real cron job configured the operator can switch the whole thing off
 * under Einstellungen → System.
 */

/** Seconds between background runs. Roughly what a per-minute cron line gives. */
const TICK_INTERVAL = 60;

/** Seconds between prune runs. The console's nightly cron does the same work. */
const PRUNE_INTERVAL = 86400;

/**
 * Remove expired tokens, old rate-limit counters, spent form identifiers,
 * problem reports long since dealt with, errors nothing has repeated for a
 * month, and uploaded files nothing points at any more.
 *
 * Deliberately narrow: nothing here removes a student, a payment or a message.
 * The files it does remove are the ones whose record has already gone - a photo
 * from a conversation deleted with an account, the screenshot of a report -
 * which would otherwise stay in storage for ever. That is why prune_uploads()
 * comes last.
 */
function prune_expired(): void {
    run('DELETE FROM auth_tokens WHERE expires_at<?', [now()]);
    run('DELETE FROM rate_limits WHERE window_start<?', [time() - 86400]);
    run('DELETE FROM form_requests WHERE created_at<?', [gmdate('Y-m-d H:i:s', time() - 604800)]);
    // The change log is informative, not evidence, so it has a horizon. The
    // audit log next to it does not, because that one is evidence.
    history_prune((int)setting('history_months'));
    // After a PHP upgrade raises PASSWORD_DEFAULT's cost, the hash a refused
    // sign-in is checked against must cost the same as a real one again, or
    // the time a refusal takes says whether the address has a login (M2).
    // Here and in the migration runner, never in a sign-in.
    refresh_sign_in_dummy_hash();
    prune_done_feedback();
    prune_quiet_errors();
    prune_uploads();
}

/** Whether enough time has passed since the last background run. */
function tick_due(string $key, int $interval): bool {
    $last = (string)setting($key, '');
    return $last === '' || (strtotime($last . ' UTC') ?: 0) <= time() - $interval;
}

/**
 * Send the response, then do the waiting work.
 *
 * Called at the very end of a request. Everything it does is optional by
 * definition, so a failure is logged and the request still ends normally.
 */
function run_background_tasks(): void {
    try {
        if (!setting('auto_background') || is_file(maintenance_file())) return;
        if (!tick_due('tick_last_run', TICK_INTERVAL)) return;
        finish_response();
        // Whoever gets the lock does the work; everyone else has already been
        // served and simply stops here.
        if ((int)scalar("SELECT GET_LOCK('badminton_crm_tick',0)") !== 1) return;
        try {
            setting_cache_clear();
            if (!tick_due('tick_last_run', TICK_INTERVAL)) return;
            // Stamped before the work, not after: a job that fails must not leave
            // every following request trying it again immediately.
            set_setting('tick_last_run', now());
            tick_work();
        } finally {
            run("SELECT RELEASE_LOCK('badminton_crm_tick')");
        }
    } catch (Throwable $e) {
        error_log('CRM background: ' . error_log_text($e));
        capture_error($e, 'background');
    }
}

/**
 * The jobs themselves, each independent of whether the others worked.
 *
 * As the portal rather than as the visitor whose page view they follow
 * (as_the_portal()): in the portal's language, and with nobody as the actor. A
 * month billed after a family's page view was recorded as billed by that family,
 * in their language.
 */
function tick_work(): void {
    // Nothing while a copy is being restored. A restore that keeps the files is
    // not refused by the page path (ADR 0029 §6), so the tick after a page view
    // would mail, sweep and bill on a database being emptied or imported, and
    // only the owner's maintenance flag kept it out. One information_schema
    // query and a COUNT(*), at most once a minute.
    if (schema_restore_refusal()) return;
    foreach (['mail' => tick_mail(...), 'prune' => tick_prune(...), 'billing' => tick_billing(...)] as $name => $job) {
        try { as_the_portal($job); }
        catch (Throwable $e) {
            // Logged here with the job's name, which capture_error() does not
            // know, and for the refusals and second errors it does not write down.
            error_log('CRM background (' . $name . '): ' . error_log_text($e));
            capture_error($e, 'background');
        }
    }
}

function tick_mail(): void {
    if (!(int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE status='queued' OR (status='failed' AND retry_after IS NOT NULL AND retry_after<=?)", [now()])) return;
    process_mail(10, tick_budget());
}

function tick_prune(): void {
    if (!tick_due('prune_last_run', PRUNE_INTERVAL)) return;
    prune_expired();
    set_setting('prune_last_run', now());
}

/**
 * The monthly charges, for an operator who would otherwise have to remember to
 * open the payments screen on the 1st. Off by default: creating charges without
 * anyone looking is a decision, not a default.
 */
function tick_billing(): void {
    if (!setting('auto_billing')) return;
    $period = billing_current_period();
    if ((string)setting('billing_last_period', '') === $period) return;
    // billing_run() is one transaction: a run that fails writes no charge at
    // all. Marked done only after it, so a failed month is tried again; one
    // whose mark failed is run again and creates nothing it already has.
    billing_run($period);
    set_setting('billing_last_period', $period);
}

/**
 * How long the background work may take.
 *
 * One SMTP conversation can use its full 15-second timeout, so the budget keeps
 * a margin below max_execution_time; process_mail() stops at the deadline and
 * leaves the rest for the next run.
 */
function tick_budget(): float {
    $limit = (int)ini_get('max_execution_time');
    if ($limit <= 0) return 30.0;
    return max(5.0, min(30.0, $limit - (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) - 5.0));
}

/**
 * Hand the finished page to the visitor and keep running.
 *
 * Both function names below exist only in the FPM and LiteSpeed SAPIs; under
 * anything else the buffers are simply flushed and the visitor's browser has
 * the whole page even though the connection stays open a moment longer.
 */
function finish_response(): void {
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
    if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); return; }
    while (ob_get_level()) ob_end_flush();
    flush();
}
