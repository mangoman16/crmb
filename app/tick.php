<?php
declare(strict_types=1);

/**
 * The work a cron job would do, done by the portal itself.
 *
 * Shared hosting gives the operator a panel, not a shell, and a cron line that
 * never got set up means no invitation, no password reset and no payment
 * reminder ever leaves the server. So the same three jobs the console exposes
 * also run after a page has been delivered: the mail queue, the daily prune,
 * and optionally the monthly charges.
 *
 * Three things keep this from being a burden on a page view:
 *   - it runs after the response has been sent and the visitor's session has
 *     been let go, so nobody waits for it - not even the same phone's next
 *     request, which waits for the session the last one holds;
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
 * The daily cleanup: whatever has passed its time goes. Expired links, old
 * rate-limit counters, spent form identifiers, problem reports long since dealt
 * with, errors nothing has repeated for a month and what mails sent long ago
 * said - and each kind of record kept for a period of its own, set under
 * Einstellungen → System (ADR 0032): messages, and those taken down, absences,
 * attendance, notifications, the outbox, payment proofs, the audit log and
 * replaced consents, each by its own setting and nothing else. It runs once a
 * day after a page view (tick_prune()), or from the console's cron job.
 *
 * Never a student, a login or an accounting record: charges, payments and
 * invoices stay seven years by law (BAO § 132), longer while a proceeding
 * needs them, and nothing in the portal deletes them but the removal of the
 * example data (demo_clear()) - a charge is cancelled and an invoice voided,
 * and a student with charges cannot be deleted (student_delete). Files go last
 * (prune_uploads()), so the photo or the proof of a row deleted today goes
 * today as well, as does one of a conversation deleted with its account.
 *
 * Never while an update could be counting (ADR 0032 §2, from the security
 * review): an update counts the guarded tables (schema_guarded_tables()) before
 * its first migration and again after, and a row deleted in between reads as a
 * loss and keeps the portal closed. So nothing at all is deleted unless this
 * holds the update's own lock, asked for without waiting and let go the moment
 * the prune ends - schema_apply() waits for it up to 30 seconds - and then finds
 * no update unfinished (schema_is_unfinished()), the database as current as
 * these files (schema_is_current(), what the page path asks before it runs the
 * update) and no copy being imported (schema_restore_refusal()). An update in
 * another request, a newer release's files landed and not yet run, this very
 * code finishing its work as they land, an import: each leaves the prune for
 * later. Returns whether it ran, so tick_prune() stamps only a prune that did.
 */
function prune_expired(): bool {
    if ((int)scalar("SELECT GET_LOCK('badminton_crm_migrate',0)") !== 1) return false;
    try {
        if (schema_is_unfinished() || !schema_is_current() || schema_restore_refusal()) return false;
        run('DELETE FROM auth_tokens WHERE expires_at<?', [now()]);
        run('DELETE FROM rate_limits WHERE window_start<?', [time() - 86400]);
        run('DELETE FROM form_requests WHERE created_at<?', [gmdate('Y-m-d H:i:s', time() - 604800)]);
        // The change log informs, so it has a horizon of its own; the audit log
        // beside it is evidence, and has the longer one below.
        history_prune((int)setting('history_months'));
        // After a PHP upgrade raises PASSWORD_DEFAULT's cost, the hash a refused
        // sign-in is checked against must cost the same as a real one again, or
        // the time a refusal takes says whether the address has a login (M2).
        // Here and in the migration runner, never in a sign-in.
        refresh_sign_in_dummy_hash();
        prune_done_feedback();
        prune_quiet_errors();
        // The row stays for the outbox; what the mail said goes (MAIL_BODY_KEEP_DAYS).
        run("UPDATE mail_jobs SET payload='' WHERE status='sent' AND payload<>'' AND COALESCE(sent_at,created_at)<?", [gmdate('Y-m-d H:i:s', time() - MAIL_BODY_KEEP_DAYS * 86400)]);
        // Each period counted back from now for a moment stored in UTC, and from
        // today for a calendar date - an absence's end, a training's day.
        $monthsAgo = fn(string $key): string => months_ago((int)setting($key));
        $daysAgo = fn(string $key): string => gmdate('Y-m-d H:i:s', time() - max(1, (int)setting($key)) * 86400);
        $dateMonthsAgo = fn(string $key): string => (new DateTimeImmutable(today()))->modify('-' . max(1, (int)setting($key)) . ' months')->format('Y-m-d');
        // A message's photos go with it: message_files cascades.
        run('DELETE FROM messages WHERE created_at<?', [$monthsAgo('messages_months')]);
        run('DELETE FROM messages WHERE removed_at<?', [$daysAgo('removed_messages_days')]);
        run('DELETE FROM absences WHERE ends_on<?', [$dateMonthsAgo('absences_months')]);
        run('DELETE FROM attendance WHERE session_on<?', [$dateMonthsAgo('attendance_months')]);
        run('DELETE FROM notifications WHERE created_at<?', [$daysAgo('notices_days')]);
        run('DELETE FROM mail_jobs WHERE created_at<?', [$monthsAgo('mail_months')]);
        run('DELETE FROM payment_proofs WHERE created_at<?', [$monthsAgo('proofs_months')]);
        run('DELETE FROM audit_log WHERE created_at<?', [$monthsAgo('audit_months')]);
        // A consent a later answer of its login to the same question replaced,
        // counted from that later answer; the answer that holds stays however old.
        // The picture switch's purposes, one per who said yes (ADR 0031), are one
        // question.
        run('DELETE replaced FROM consent_log replaced JOIN consent_log later ON later.account_id=replaced.account_id AND later.id>replaced.id'
            . " AND IF(LEFT(later.purpose,19)='course_sees_picture','course_sees_picture',later.purpose)"
            . " = IF(LEFT(replaced.purpose,19)='course_sees_picture','course_sees_picture',replaced.purpose)"
            . ' WHERE later.created_at<?', [$monthsAgo('consent_months')]);
        prune_uploads();
        return true;
    } finally {
        run("SELECT RELEASE_LOCK('badminton_crm_migrate')");
    }
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
        // The session first: the same phone's next request - the club's
        // stylesheet, the next page - waits until this one lets go of it, and
        // the work below can take as long as a mail server that does not
        // answer. Held to the end, it kept the stylesheet waiting 8.1 seconds
        // behind an 8-second stall. What the page wrote is saved here; nothing
        // in the work writes to $_SESSION, so nothing is lost after it.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
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
    // Once a day, and not within a day of a period set shorter: the save's own
    // request ends here, and would delete before its message - that it can be
    // set back - was read. Only such a save moves that time; tick_due() only
    // reads, so the wait cannot extend itself. The console's cron job does not
    // wait: it is the daily cleanup the message means.
    if (!tick_due('prune_last_run', PRUNE_INTERVAL) || !tick_due('period_last_shortened', PRUNE_INTERVAL)) return;
    // Stamped only when it ran: one left for an update is tried again at the
    // next tick, not a day later.
    if (prune_expired()) set_setting('prune_last_run', now());
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
