<?php
declare(strict_types=1);

/**
 * Transaction handling.
 *
 * One rule: a write either happens completely or not at all. Everything that
 * changes data runs inside transactional(), which nests safely, so a helper
 * does not need to know whether its caller already opened a transaction.
 *
 * Nesting uses savepoints rather than a counter that only pretends to nest.
 * An inner scope that fails is rolled back to its savepoint and the outer scope
 * can carry on or fail in turn - which is the behaviour a caller that catches
 * the inner error is entitled to expect.
 */

/** Current nesting depth. 0 means no transaction is open. */
function tx_depth(?int $set = null): int {
    static $depth = 0;
    if ($set !== null) $depth = $set;
    return $depth;
}

function tx_savepoint_name(int $depth): string { return 'crm_sp_'.$depth; }

/**
 * Run $fn inside a transaction, committing on success and rolling back on any
 * throwable. Returns whatever $fn returns.
 *
 * Re-entrant: an inner call creates a savepoint instead of a second
 * transaction, which MySQL would reject outright.
 */
function transactional(callable $fn): mixed {
    $pdo = db();
    $depth = tx_depth();

    if ($depth === 0) {
        // A stray open transaction here means an earlier scope leaked. Finish it
        // rather than silently joining it, and say so in the log.
        if ($pdo->inTransaction()) {
            error_log('CRM: a transaction was already open when a new one started; rolling the old one back.');
            $pdo->rollBack();
        }
        $pdo->beginTransaction();
    } else {
        $pdo->exec('SAVEPOINT '.tx_savepoint_name($depth));
    }
    tx_depth($depth + 1);

    try {
        $result = $fn();
    } catch (Throwable $e) {
        tx_depth($depth);
        try {
            if ($depth === 0) { if ($pdo->inTransaction()) $pdo->rollBack(); }
            else { $pdo->exec('ROLLBACK TO SAVEPOINT '.tx_savepoint_name($depth)); }
        } catch (Throwable $rollbackFailed) {
            // The original error is the useful one; note that unwinding failed
            // too, because that usually means the connection is gone.
            error_log('CRM: rollback failed: '.$rollbackFailed->getMessage());
        }
        throw $e;
    }

    tx_depth($depth);
    if ($depth === 0) $pdo->commit();
    else $pdo->exec('RELEASE SAVEPOINT '.tx_savepoint_name($depth));
    return $result;
}

/**
 * Read a row and hold it for the rest of the transaction.
 *
 * FOR UPDATE only means anything inside a transaction, so this refuses to be
 * called outside one rather than quietly returning an unlocked row and leaving
 * the caller believing it is safe from a concurrent writer.
 */
function lock_row(string $table, int $id): ?array {
    if (tx_depth() === 0) throw new RuntimeException('lock_row('.$table.') outside a transaction locks nothing.');
    if (!preg_match('/^[a-z_]+$/D', $table)) throw new RuntimeException('Invalid table name');
    return one('SELECT * FROM '.$table.' WHERE id=? FOR UPDATE', [$id]);
}

/**
 * Claim a one-time request token, refusing a replay.
 *
 * The insert, not the select, is what makes this safe: two identical
 * submissions arriving together both find nothing, and the primary key refuses
 * the second one.
 */
function claim_request(string $token): void {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new UserError(t('Ungültige Anfrage.', 'Invalid request.'));
    try {
        run('INSERT INTO form_requests (request_id,created_at) VALUES (?,?)', [$token, now()]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000')
            throw new UserError(t('Diese Eingabe wurde bereits verarbeitet.', 'This submission has already been processed.'));
        throw $e;
    }
}

/**
 * Abandon any open transaction before leaving the request.
 *
 * PHP would roll back on connection teardown anyway, but silently. Calling this
 * from the redirect path makes the discard explicit and logged, so an action
 * that redirects mid-write is a visible bug rather than a mystery.
 */
function tx_abandon_open(string $because): void {
    if (tx_depth() === 0 && !db_connected()) return;
    if (tx_depth() === 0) return;
    error_log('CRM: leaving with an open transaction ('.$because.'); discarding it.');
    try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable) { /* going away regardless */ }
    tx_depth(0);
}

/** Whether a database connection was ever established, to avoid opening one late. */
function db_connected(): bool { return $GLOBALS['crm_db_opened'] ?? false; }
