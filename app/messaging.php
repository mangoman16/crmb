<?php
declare(strict_types=1);

/**
 * Conversations, and who is allowed in them.
 *
 * Two kinds, and the difference is a rule rather than a preference:
 *
 *   staff   a family and whoever is on duty. Every member of staff can read it,
 *           because the trainer, a second trainer and the administrator are one
 *           desk as far as a family is concerned.
 *   direct  two families. Nobody else can read it - not the trainer, not the
 *           administrator - and writing to somebody has to be agreed to first.
 *
 * Writing to the trainer never needs agreeing to. She is the person the portal
 * exists to reach, and a child who has to ask permission to report that
 * something is wrong is a child who does not report it.
 */

/** What may be attached, and how the interface has to treat it. */
function attachment_kind(string $mime): string {
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'audio/')) return 'voice';
    return 'file';
}

/**
 * One conversation the signed-in account is allowed to see.
 *
 * The scoping is part of the lookup rather than something callers remember to
 * add, which is the same reason student() works the way it does.
 */
function thread_record(int $id): array {
    $u = require_user();
    $row = one('SELECT t.*, a.name AS account_name FROM threads t LEFT JOIN accounts a ON a.id=t.account_id WHERE t.id=?', [$id]);
    if (!$row) throw new UserError(t('Unterhaltung nicht gefunden.', 'Conversation not found.'));
    if (!may_read_thread($u, $row)) throw new UserError(t('Unterhaltung nicht gefunden.', 'Conversation not found.'));
    return $row;
}

/**
 * Whether this account may read this conversation.
 *
 * Staff read every staff thread and no direct one. A direct conversation
 * between two families is theirs; reading other people's private messages is
 * not a power the portal hands anybody, and the message screen says so.
 */
function may_read_thread(array $user, array $thread): bool {
    if (($thread['kind'] ?? 'staff') === 'staff') return is_staff($user) || is_participant((int)$thread['id'], (int)$user['id']);
    return is_participant((int)$thread['id'], (int)$user['id']);
}

function is_participant(int $threadId, int $accountId): bool {
    return (bool)one('SELECT 1 FROM thread_participants WHERE thread_id=? AND account_id=?', [$threadId, $accountId]);
}

/** Everyone in a conversation, for the header that says who is in it. */
function thread_people(int $threadId): array {
    return rows('SELECT a.id, a.name, a.avatar_name, a.role FROM thread_participants p JOIN accounts a ON a.id=p.account_id'
        .' WHERE p.thread_id=? ORDER BY a.name', [$threadId]);
}

/** How a conversation is titled for one reader: who else is in it. */
function thread_title(array $thread, array $user): string {
    if (($thread['kind'] ?? 'staff') === 'staff')
        return is_staff($user) ? (string)($thread['account_name'] ?? '') : (string)setting('club_name', 'Badminton');
    $others = array_filter(thread_people((int)$thread['id']), fn($p) => (int)$p['id'] !== (int)$user['id']);
    return $others ? implode(', ', array_column($others, 'name')) : t('Nur du', 'Only you');
}

/** The conversations one account can see, newest first. */
function threads_for(array $user): array {
    $staff = is_staff($user);
    return rows('SELECT t.*, a.name AS account_name,'
        .' (SELECT body FROM messages m WHERE m.thread_id=t.id ORDER BY m.id DESC LIMIT 1) AS last_message,'
        .' (SELECT COUNT(*) FROM message_files f JOIN messages m ON m.id=f.message_id WHERE m.thread_id=t.id) AS file_count'
        .' FROM threads t LEFT JOIN accounts a ON a.id=t.account_id'
        .' WHERE ' . ($staff
            ? "(t.kind='staff' OR EXISTS (SELECT 1 FROM thread_participants p WHERE p.thread_id=t.id AND p.account_id=?))"
            : 'EXISTS (SELECT 1 FROM thread_participants p WHERE p.thread_id=t.id AND p.account_id=?)')
        .' ORDER BY t.updated_at DESC, t.id DESC', [(int)$user['id']]);
}

/** Add somebody to a conversation. Safe to call twice. */
function join_thread(int $threadId, int $accountId): void {
    run('INSERT INTO thread_participants (thread_id,account_id,joined_at) VALUES (?,?,?)'
        .' ON DUPLICATE KEY UPDATE joined_at=joined_at', [$threadId, $accountId, now()]);
}

// ---------------------------------------------------------------------------
// Permission to write to another family
// ---------------------------------------------------------------------------

/** Whether these two may write to one another. */
function may_message(array $from, int $toId): bool {
    if ((int)$from['id'] === $toId) return false;
    $to = one("SELECT * FROM accounts WHERE id=? AND state='active'", [$toId]);
    if (!$to) return false;
    // Anybody may write to staff, and staff may write to anybody.
    if (is_staff($to) || is_staff($from)) return true;
    return (bool)one("SELECT 1 FROM contact_requests WHERE state='accepted'"
        .' AND ((from_account_id=? AND to_account_id=?) OR (from_account_id=? AND to_account_id=?))',
        [(int)$from['id'], $toId, $toId, (int)$from['id']]);
}

/** Requests waiting for this account to answer. */
function contact_requests_for(int $accountId): array {
    return rows("SELECT r.*, a.name AS from_name, a.avatar_name FROM contact_requests r"
        .' JOIN accounts a ON a.id=r.from_account_id'
        ." WHERE r.to_account_id=? AND r.state='pending' ORDER BY r.id DESC", [$accountId]);
}

function pending_contact_count(int $accountId): int {
    return (int)scalar("SELECT COUNT(*) FROM contact_requests WHERE to_account_id=? AND state='pending'", [$accountId]);
}

/**
 * Accounts this family already has permission to write to.
 *
 * Staff are always in the list, first, because they are the answer to "who can
 * I ask about this" and should never be something to go looking for.
 */
function contacts_for(array $user): array {
    $staff = rows("SELECT id, name, avatar_name, role FROM accounts WHERE role IN ('admin','trainer','manager')"
        ." AND state='active' AND id<>? ORDER BY name", [(int)$user['id']]);
    if (is_staff($user))
        return array_merge($staff, rows("SELECT id, name, avatar_name, role FROM accounts WHERE role='student'"
            ." AND state='active' ORDER BY name"));
    $agreed = rows('SELECT a.id, a.name, a.avatar_name, a.role FROM contact_requests r'
        .' JOIN accounts a ON a.id = IF(r.from_account_id=?, r.to_account_id, r.from_account_id)'
        ." WHERE r.state='accepted' AND (r.from_account_id=? OR r.to_account_id=?) AND a.state='active'"
        .' ORDER BY a.name', [(int)$user['id'], (int)$user['id'], (int)$user['id']]);
    return array_merge($staff, $agreed);
}

/**
 * Ask somebody if you may write to them.
 *
 * One row per pair per direction, so pressing the button twice on a slow
 * connection asks once. A request to somebody who already asked you is taken as
 * an answer: both of you want this, so there is nothing left to decide.
 */
function request_contact(array $from, int $toId, string $message): string {
    if (is_staff($from)) throw new UserError(t('Du darfst ohnehin schreiben.', 'You may write to them anyway.'));
    $to = one("SELECT * FROM accounts WHERE id=? AND state='active'", [$toId]);
    if (!$to || (int)$to['id'] === (int)$from['id']) throw new UserError(t('Dieses Konto gibt es nicht.', 'No such account.'));
    if (is_staff($to)) throw new UserError(t('Der Trainerin kannst du immer schreiben.', 'You can always write to the trainer.'));
    return transactional(function () use ($from, $toId, $message): string {
        $theirs = one("SELECT * FROM contact_requests WHERE from_account_id=? AND to_account_id=? FOR UPDATE", [$toId, (int)$from['id']]);
        if ($theirs && $theirs['state'] === 'pending') {
            run("UPDATE contact_requests SET state='accepted', decided_at=? WHERE id=?", [now(), (int)$theirs['id']]);
            audit('contact.accepted', 'account', $toId);
            return 'accepted';
        }
        $mine = one('SELECT * FROM contact_requests WHERE from_account_id=? AND to_account_id=? FOR UPDATE', [(int)$from['id'], $toId]);
        if ($mine) {
            if ($mine['state'] === 'accepted') return 'accepted';
            if ($mine['state'] === 'pending') throw new UserError(t('Die Anfrage läuft schon.', 'That request is already waiting.'));
            // A declined request may be asked again, once, by clearing the old
            // answer rather than piling up a second row.
            run("UPDATE contact_requests SET state='pending', message=?, decided_at=NULL, created_at=? WHERE id=?",
                [mb_substr($message, 0, 300), now(), (int)$mine['id']]);
        } else {
            run("INSERT INTO contact_requests (from_account_id,to_account_id,state,message,created_at) VALUES (?,?,'pending',?,?)",
                [(int)$from['id'], $toId, mb_substr($message, 0, 300), now()]);
        }
        audit('contact.requested', 'account', $toId);
        return 'pending';
    });
}

/** Answer one. Only the person who was asked may. */
function decide_contact(int $requestId, bool $accept): array {
    $u = require_user();
    return transactional(function () use ($requestId, $accept, $u): array {
        $r = one('SELECT * FROM contact_requests WHERE id=? FOR UPDATE', [$requestId]);
        if (!$r || (int)$r['to_account_id'] !== (int)$u['id'])
            throw new UserError(t('Diese Anfrage gibt es nicht.', 'No such request.'));
        if ($r['state'] !== 'pending') throw new UserError(t('Darüber wurde schon entschieden.', 'That has already been decided.'));
        run('UPDATE contact_requests SET state=?, decided_at=? WHERE id=?',
            [$accept ? 'accepted' : 'declined', now(), $requestId]);
        audit('contact.' . ($accept ? 'accepted' : 'declined'), 'account', (int)$r['from_account_id']);
        return $r;
    });
}

/**
 * The conversation between two accounts, made if it does not exist yet.
 *
 * One direct conversation per pair: a messenger that starts a new thread every
 * time somebody writes is a messenger nobody can find anything in.
 */
function direct_thread(array $from, int $toId): int {
    if (!may_message($from, $toId))
        throw new UserError(t('Diese Person hat dem Schreiben noch nicht zugestimmt.', 'That person has not agreed to being written to yet.'));
    return transactional(function () use ($from, $toId): int {
        $existing = scalar("SELECT t.id FROM threads t WHERE t.kind='direct'"
            .' AND EXISTS (SELECT 1 FROM thread_participants p WHERE p.thread_id=t.id AND p.account_id=?)'
            .' AND EXISTS (SELECT 1 FROM thread_participants q WHERE q.thread_id=t.id AND q.account_id=?)'
            .' AND (SELECT COUNT(*) FROM thread_participants r WHERE r.thread_id=t.id)=2 LIMIT 1',
            [(int)$from['id'], $toId]);
        if ($existing) return (int)$existing;
        $other = one('SELECT name FROM accounts WHERE id=?', [$toId]);
        run("INSERT INTO threads (account_id,kind,subject,updated_at) VALUES (?,'direct',?,?)",
            [(int)$from['id'], mb_substr((string)($other['name'] ?? ''), 0, 180), now()]);
        $id = (int)db()->lastInsertId();
        join_thread($id, (int)$from['id']);
        join_thread($id, $toId);
        return $id;
    });
}

// ---------------------------------------------------------------------------
// Attachments
// ---------------------------------------------------------------------------

/** Files on several messages at once, as message_id => rows. */
function files_by_message(array $messageIds): array {
    $ids = array_values(array_unique(array_map('intval', $messageIds)));
    if (!$ids) return [];
    $out = array_fill_keys($ids, []);
    foreach (rows('SELECT * FROM message_files WHERE message_id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id', $ids) as $file)
        $out[(int)$file['message_id']][] = $file;
    return $out;
}

/**
 * Store the file attached to a message, if there is one.
 *
 * Returns whether anything was attached, so the caller can refuse a message that
 * is neither text nor a file rather than writing an empty bubble.
 */
function attach_to_message(int $messageId, string $field = 'attachment'): bool {
    if (!isset($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return false;
    $stored = store_upload($field, 'message');
    $seconds = (int)(is_scalar($_POST['attachment_seconds'] ?? null) ? $_POST['attachment_seconds'] : 0);
    run('INSERT INTO message_files (message_id,kind,stored_name,original_name,mime,bytes,seconds,created_at)'
        .' VALUES (?,?,?,?,?,?,?,?)',
        [$messageId, attachment_kind($stored['mime']), $stored['stored_name'], $stored['original_name'],
         $stored['mime'], $stored['bytes'], max(0, min(3600, $seconds)), now()]);
    return true;
}

/** A length like "0:42", for a voice note. */
function duration_label(int $seconds): string {
    if ($seconds <= 0) return '';
    return intdiv($seconds, 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}
