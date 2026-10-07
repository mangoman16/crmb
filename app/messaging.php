<?php
declare(strict_types=1);

/**
 * Conversations, and who is allowed in them (ADR 0022).
 *
 * Four kinds, and the difference is a rule rather than a preference:
 *
 *   course        a course's group. Its students are whoever is enrolled now,
 *                 read from the enrolments rather than stored, so nobody joins
 *                 or leaves it by hand. Every member of staff reads and writes
 *                 in every group, and can take a message down there.
 *   staff_direct  a student and one member of staff. The two write; the club's
 *                 administrators can read it too, because no adult has a
 *                 channel to a child that the club cannot see.
 *   direct        any other two - two families, after one agreed to the other.
 *                 Nobody else reads it.
 *   staff         the shared desk from before ADR 0022: a family and every
 *                 member of staff. Kept to read, closed to new messages.
 *
 * Who sees what is one SQL condition (thread_listed_sql(), thread_readable_sql())
 * used by the list, the unread badge and the page alike: two spellings of one
 * rule is how a private conversation ends up in somebody's unread count.
 */

/** What may be attached, and how the interface has to treat it. */
function attachment_kind(string $mime): string {
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'audio/')) return 'voice';
    return 'file';
}

/**
 * The conversations in $user's list, as one condition over `threads t` and its
 * parameters: the groups of the running courses they are in (staff: of every
 * running course), the chats they are in, and for staff the closed desk threads.
 */
function thread_listed_sql(array $user): array {
    $id = (int)$user['id'];
    $mine = 'EXISTS (SELECT 1 FROM thread_participants p WHERE p.thread_id=t.id AND p.account_id=?)';
    if (is_staff($user))
        return ["((t.kind='course' AND EXISTS (SELECT 1 FROM classes c WHERE c.id=t.class_id AND c.archived=0))"
            ." OR (t.kind IN ('direct','staff_direct') AND $mine) OR t.kind='staff')", [$id]];
    return ["((t.kind='course' AND EXISTS (SELECT 1 FROM class_students cs JOIN students s ON s.id=cs.student_id"
        .' JOIN classes c ON c.id=cs.class_id WHERE cs.class_id=t.class_id AND s.account_id=? AND '.current_enrolment_sql('cs')
        .' AND c.archived=0))'
        ." OR (t.kind IN ('direct','staff_direct','staff') AND $mine))", [$id, $id]];
}

/**
 * The conversations $user may open: their list, and for staff the group of an
 * archived course as well; an administrator also every chat between a student
 * and a member of staff, which her list leaves out so her badge counts only
 * messages meant for her.
 */
function thread_readable_sql(array $user): array {
    [$sql, $params] = thread_listed_sql($user);
    if (is_admin($user)) return ["($sql OR t.kind IN ('course','staff_direct'))", $params];
    if (is_staff($user)) return ["($sql OR t.kind='course')", $params];
    return [$sql, $params];
}

/**
 * What the chat shows $user: their list ($listedOnly) or all they may open.
 *
 * While staff look through somebody else's eyes, only what both of them may
 * read: a trainer viewing a child sees the child's groups and her own chat with
 * the child - never the child's chats with another trainer or another family,
 * which the view would otherwise have handed her (security review, ADR 0022).
 */
function thread_seen_sql(array $user, bool $listedOnly): array {
    [$sql, $params] = $listedOnly ? thread_listed_sql($user) : thread_readable_sql($user);
    $real = impersonator();
    if (!$real) return [$sql, $params];
    [$theirs, $theirParams] = thread_readable_sql($real);
    return ["($sql AND $theirs)", [...$params, ...$theirParams]];
}

/**
 * One conversation the signed-in account is allowed to see, with its course.
 *
 * The scoping is part of the lookup rather than something callers remember to
 * add, which is the same reason student() works the way it does.
 */
function thread_record(int $id): array {
    $u = require_user();
    [$readable, $params] = thread_seen_sql($u, false);
    $row = one('SELECT t.*, a.name AS account_name, c.name AS class_name, c.archived AS class_archived'
        .' FROM threads t LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN classes c ON c.id=t.class_id'
        .' WHERE t.id=? AND '.$readable, [$id, ...$params]);
    if (!$row) throw new NotFound(t('Unterhaltung nicht gefunden.', 'Conversation not found.'));
    return $row;
}

/**
 * Whether $user may write in a conversation thread_record() gave them: the group
 * of a running course, or a chat they are in. Nobody writes into a desk thread
 * any more, an administrator reading somebody else's chat only reads, and
 * nobody writes while viewing the portal as somebody else.
 */
function may_write_thread(array $user, array $thread): bool {
    // Nobody writes under somebody else's name while looking through their eyes.
    if (impersonator()) return false;
    return match ((string)($thread['kind'] ?? '')) {
        'course' => (int)($thread['class_archived'] ?? 1) === 0,
        'direct', 'staff_direct' => is_participant((int)$thread['id'], (int)$user['id']),
        default => false,
    };
}

function is_participant(int $threadId, int $accountId): bool {
    return (bool)one('SELECT 1 FROM thread_participants WHERE thread_id=? AND account_id=?', [$threadId, $accountId]);
}

/**
 * What the chat shows of a person: who, their picture, their role, their emoji,
 * and what their dot is drawn from. One list, so the header, the member sheet,
 * the contacts and the chat list cannot draw one person from different facts.
 */
const CHAT_PERSON_COLUMNS = ['id', 'name', 'avatar_name', 'role', 'status_emoji', 'last_seen_at', 'presence', 'state'];

/**
 * Those columns as a select list over the accounts table under $alias. With a
 * $prefix each is named with it, for a row that carries a person beside columns
 * of its own; chat_person_in() takes the person back out of such a row.
 */
function chat_person_columns(string $alias, string $prefix = ''): string {
    $alias = sql_name($alias, 'table alias');
    if ($prefix !== '') $prefix = sql_name($prefix, 'column prefix');
    return implode(', ', array_map(fn(string $column): string => $alias . '.' . $column . ($prefix !== '' ? ' AS ' . $prefix . $column : ''),
                                   CHAT_PERSON_COLUMNS));
}

/** The person a row carries under $prefix, as the helpers that draw people expect one. */
function chat_person_in(array $row, string $prefix): array {
    $person = [];
    foreach (CHAT_PERSON_COLUMNS as $column) $person[$column] = $row[$prefix . $column] ?? null;
    return $person;
}

/** Everyone in a chat, for its header: who, their dot and their emoji. */
function thread_people(int $threadId): array {
    return rows('SELECT ' . chat_person_columns('a')
        .' FROM thread_participants p JOIN accounts a ON a.id=p.account_id WHERE p.thread_id=? ORDER BY a.name', [$threadId]);
}

/**
 * How a conversation is titled for one reader. A row from chat_list() already
 * carries the other person and the names, so the list asks nothing per row; the
 * page's header asks thread_people() once.
 */
function thread_title(array $thread, array $user): string {
    $kind = (string)($thread['kind'] ?? 'staff');
    if ($kind === 'course') return (string)($thread['class_name'] ?? '');
    if ($kind === 'staff')
        return is_staff($user) ? (string)($thread['account_name'] ?? '') : t('Trainerteam', 'Coaching team');
    if (array_key_exists('people_names', $thread))
        return (int)$thread['me_in']
            ? (string)($thread['other_name'] ?? t('Gelöschtes Konto', 'Deleted account'))
            : (string)$thread['people_names'];
    $people = thread_people((int)$thread['id']);
    $others = array_values(array_filter($people, fn($p) => (int)$p['id'] !== (int)$user['id']));
    // An administrator reading a chat she is not in sees who it is between.
    if (count($others) === count($people)) return implode(' · ', array_column($people, 'name'));
    return $others ? implode(', ', array_column($others, 'name')) : t('Nur du', 'Only you');
}

/**
 * $user's chat list, in one query however long it is: the groups, then the
 * chats, then the closed desk threads, each newest first - every row with the
 * other person, the last message, its first file, the number in the course and
 * how many messages are unread. With $allDirect, an administrator's view of the
 * chats between a student and staff that she is not in.
 */
function chat_list(array $user, bool $allDirect = false): array {
    $me = (int)$user['id'];
    if ($allDirect) {
        if (!is_admin($user)) throw new UserError(t('Nur für Administratoren.', 'Administrators only.'));
        // What she may open, narrowed to the chats between a student and staff
        // that she is not in. Built on the one rule rather than beside it: if
        // who reads those chats changes, or a view through somebody's eyes
        // narrows it, this list follows (ADR 0022 §2, §9).
        [$readable, $readableParams] = thread_seen_sql($user, false);
        $where = "($readable) AND t.kind='staff_direct'"
            .' AND NOT EXISTS (SELECT 1 FROM thread_participants p WHERE p.thread_id=t.id AND p.account_id=?)';
        $whereParams = [...$readableParams, $me];
    } else {
        [$where, $whereParams] = thread_seen_sql($user, true);
    }
    return rows('SELECT t.*, c.name AS class_name, c.archived AS class_archived, ow.name AS account_name, '
        .chat_person_columns('o', 'other_').','
        .' EXISTS (SELECT 1 FROM thread_participants pm WHERE pm.thread_id=t.id AND pm.account_id=?) AS me_in,'
        ." (SELECT GROUP_CONCAT(pa.name ORDER BY pa.name SEPARATOR ' · ') FROM thread_participants pp"
        .'   JOIN accounts pa ON pa.id=pp.account_id WHERE pp.thread_id=t.id) AS people_names,'
        .' m.id AS last_id, CASE WHEN m.removed_at IS NULL THEN m.body END AS last_body, m.sender_id AS last_sender_id,'
        .' m.created_at AS last_at,'
        .' m.removed_at AS last_removed_at, ms.name AS last_sender_name,'
        ." (SELECT CONCAT(f.kind,'|',f.seconds,'|',f.original_name) FROM message_files f WHERE f.message_id=m.id"
        .'   AND m.removed_at IS NULL ORDER BY f.id LIMIT 1) AS last_file,'
        .' (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id=t.class_id AND '.current_enrolment_sql('cs').') AS members,'
        .' (SELECT COUNT(*) FROM messages mu WHERE mu.thread_id=t.id AND NOT (mu.sender_id <=> ?) AND mu.id>COALESCE('
        .'   (SELECT r.last_read_message_id FROM thread_reads r WHERE r.thread_id=t.id AND r.account_id=?),0)) AS unread'
        .' FROM threads t LEFT JOIN classes c ON c.id=t.class_id LEFT JOIN accounts ow ON ow.id=t.account_id'
        ." LEFT JOIN accounts o ON t.kind<>'course' AND o.id=(SELECT p2.account_id FROM thread_participants p2"
        .'   WHERE p2.thread_id=t.id AND p2.account_id<>? ORDER BY p2.account_id LIMIT 1)'
        .' LEFT JOIN messages m ON m.id=(SELECT MAX(id) FROM messages WHERE thread_id=t.id)'
        .' LEFT JOIN accounts ms ON ms.id=m.sender_id'
        .' WHERE '.$where
        ." ORDER BY t.kind='course' DESC, t.kind='staff' ASC, m.id IS NULL ASC, m.id DESC, c.name, t.id DESC",
        [$me, $me, $me, $me, ...$whereParams]);
}

/**
 * The courses each login's student is in now, as "Kinder Anfänger, Jugend" -
 * so staff picking somebody to write to can tell two Lenas apart. One query
 * for the whole list.
 */
function student_courses_by_account(array $accountIds): array {
    $ids = array_values(array_unique(array_map('intval', $accountIds)));
    if (!$ids) return [];
    $out = [];
    foreach (rows("SELECT s.account_id, GROUP_CONCAT(c.name ORDER BY c.sort_order, c.name SEPARATOR ', ') AS courses"
        .' FROM students s JOIN class_students cs ON cs.student_id=s.id AND '.current_enrolment_sql('cs')
        .' JOIN classes c ON c.id=cs.class_id AND c.archived=0'
        .' WHERE s.account_id IN ('.implode(',', array_fill(0, count($ids), '?')).') GROUP BY s.account_id', $ids) as $r)
        $out[(int)$r['account_id']] = (string)$r['courses'];
    return $out;
}

/**
 * Remember how far an account has read a conversation. Per account, because
 * staff share the groups: one trainer's reading must not hide a message from
 * another.
 */
function mark_thread_read(int $threadId, int $accountId): void {
    run('INSERT INTO thread_reads (thread_id,account_id,last_read_message_id,updated_at)'
        .' VALUES (?,?,COALESCE((SELECT MAX(id) FROM messages WHERE thread_id=?),0),?)'
        .' ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id)),updated_at=VALUES(updated_at)',
        [$threadId, $accountId, $threadId, now()]);
}

/**
 * The conversations in $user's list whose newest message is somebody else's
 * and newer than what they have read. `<=>`, not `<>`: a message from a deleted
 * account has no sender, and with `<>` it was never unread.
 */
function unread_thread_ids(array $user): array {
    [$listed, $params] = thread_seen_sql($user, true);
    return array_map('intval', array_column(rows(
        'SELECT t.id FROM threads t JOIN messages m ON m.id=(SELECT MAX(id) FROM messages WHERE thread_id=t.id)'
        .' LEFT JOIN thread_reads r ON r.thread_id=t.id AND r.account_id=?'
        .' WHERE NOT (m.sender_id <=> ?) AND m.id>COALESCE(r.last_read_message_id,0) AND '.$listed,
        [(int)$user['id'], (int)$user['id'], ...$params]), 'id'));
}

function unread_count(array $user): int { return count(unread_thread_ids($user)); }

/**
 * One conversation's messages, oldest first: the newest $limit, or the $limit
 * before message $before. A removed message comes back without its words and
 * files, so no page can print them by mistake. Each carries who wrote it under
 * 'author_', drawn as every other person in the chat is; chat_person_in($m,
 * 'author_') takes them out. A deleted login leaves an author of nulls, as its
 * sender_id is.
 *
 * @return array{messages: list<array>, more: bool}
 */
function thread_messages(int $threadId, int $before = 0, int $limit = 50): array {
    $rows = rows('SELECT m.*, '.chat_person_columns('a', 'author_').' FROM messages m LEFT JOIN accounts a ON a.id=m.sender_id'
        .' WHERE m.thread_id=?'.($before > 0 ? ' AND m.id<?' : '').' ORDER BY m.id DESC LIMIT '.($limit + 1),
        $before > 0 ? [$threadId, $before] : [$threadId]);
    $more = count($rows) > $limit;
    $rows = array_reverse(array_slice($rows, 0, $limit));
    $files = files_by_message(array_column(array_filter($rows, fn($m) => $m['removed_at'] === null), 'id'));
    foreach ($rows as &$m) {
        $m['removed'] = $m['removed_at'] !== null;
        if ($m['removed']) $m['body'] = '';
        $m['files'] = $m['removed'] ? [] : ($files[(int)$m['id']] ?? []);
    }
    unset($m);
    return ['messages' => $rows, 'more' => $more];
}

/**
 * Who is in a course's group: every active member of staff, and every student
 * enrolled now - with their login where they have an active one, so the page
 * can say who cannot read the group yet.
 */
function course_group_people(int $classId): array {
    $cols = chat_person_columns('a');
    return [
        'staff' => rows("SELECT $cols FROM accounts a WHERE a.role IN ('admin','trainer','manager') AND a.state='active'"
            .' ORDER BY a.name, a.id'),
        'members' => rows("SELECT s.id AS student_id, s.first_name, s.last_name, $cols FROM class_students cs"
            ." JOIN students s ON s.id=cs.student_id LEFT JOIN accounts a ON a.id=s.account_id AND a.state='active'"
            .' WHERE cs.class_id=? AND '.current_enrolment_sql('cs').' ORDER BY s.last_name, s.first_name, s.id', [$classId]),
    ];
}

/**
 * A course's group, made if it has none yet - the one place a 'course'
 * conversation is written. Asked for first, so a repeat does not use up an id;
 * the unique class_id makes two requests at once end with one group.
 */
function course_group_thread(int $classId): int {
    $id = scalar('SELECT id FROM threads WHERE class_id=?', [$classId]);
    if ($id) return (int)$id;
    run("INSERT INTO threads (account_id,kind,class_id,subject,updated_at) VALUES (NULL,'course',?,'',?)"
        .' ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$classId, now()]);
    return (int)db()->lastInsertId();
}

/**
 * Give every course that has no group its group - the courses made before
 * migration 025, run after every update. Example courses too, so the chat can
 * be tried before real families arrive. Returns how many it made.
 */
function course_groups_fill(): int {
    $made = 0;
    foreach (rows('SELECT c.id FROM classes c WHERE NOT EXISTS (SELECT 1 FROM threads t WHERE t.class_id=c.id)') as $c) {
        course_group_thread((int)$c['id']);
        $made++;
    }
    return $made;
}

/**
 * Take a message in a course group down, or put it back (ADR 0022). Staff only,
 * and groups only: a family's chat is theirs. The words stay in the database, so
 * restoring from the same place is the way back; who did it is in the audit log.
 * Returns the conversation's id.
 */
function moderate_message(int $messageId, bool $remove): int {
    require_staff();
    $m = one("SELECT m.id, m.thread_id FROM messages m JOIN threads t ON t.id=m.thread_id WHERE m.id=? AND t.kind='course' FOR UPDATE",
        [$messageId]);
    if (!$m) throw new UserError(t('Nur Nachrichten in Kursgruppen können entfernt werden.', 'Only messages in course groups can be removed.'));
    run('UPDATE messages SET removed_at=? WHERE id=?', [$remove ? now() : null, $messageId]);
    audit($remove ? 'message.removed' : 'message.restored', 'message', $messageId);
    return (int)$m['thread_id'];
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
    // The sender's role travels with the request because whether their picture
    // may be shown depends on it (may_see_account_picture()).
    return rows("SELECT r.*, a.name AS from_name, a.role AS from_role, a.avatar_name FROM contact_requests r"
        .' JOIN accounts a ON a.id=r.from_account_id'
        ." WHERE r.to_account_id=? AND r.state='pending' ORDER BY r.id DESC", [$accountId]);
}

function pending_contact_count(int $accountId): int {
    return (int)scalar("SELECT COUNT(*) FROM contact_requests WHERE to_account_id=? AND state='pending'", [$accountId]);
}

/**
 * Whom „Neue Nachricht" offers: the coaching team, first, because they are the
 * answer to "who can I ask about this" and should never be something to go
 * looking for - and for staff, every family too.
 *
 * A family is offered the team and nobody else. Chats between students have
 * closed (ADR 0022 §11.3), so no other family is listed, agreed to before or
 * not, and none can be asked any more.
 */
function contacts_for(array $user): array {
    $cols = chat_person_columns('a');
    $staff = rows("SELECT $cols FROM accounts a WHERE a.role IN ('admin','trainer','manager')"
        ." AND a.state='active' AND a.id<>? ORDER BY a.name", [(int)$user['id']]);
    if (!is_staff($user)) return $staff;
    return array_merge($staff, rows("SELECT $cols FROM accounts a WHERE a.role='student'"
        ." AND a.state='active' ORDER BY a.name"));
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
 * The chat two accounts already have, or 0. A pair has one at most: whether it
 * is 'direct' or 'staff_direct' follows from who the two are.
 */
function pair_thread(int $a, int $b): int {
    return (int)(scalar("SELECT t.id FROM threads t WHERE t.kind IN ('direct','staff_direct')"
        .' AND EXISTS (SELECT 1 FROM thread_participants p WHERE p.thread_id=t.id AND p.account_id=?)'
        .' AND EXISTS (SELECT 1 FROM thread_participants q WHERE q.thread_id=t.id AND q.account_id=?)'
        .' AND (SELECT COUNT(*) FROM thread_participants r WHERE r.thread_id=t.id)=2 ORDER BY t.id LIMIT 1', [$a, $b]) ?: 0);
}

/**
 * Which kind of chat two accounts make (ADR 0022 §1): a student and a member of
 * staff a 'staff_direct' one, which the club's administrators can read; any
 * other two a 'direct' one. Fixed when the chat is made - a later change of role
 * never changes who reads it - and asked beforehand only by the page that shows
 * the empty chat before its first message, so it says what the chat will be.
 */
function pair_kind(array $one, array $other): string {
    return is_staff($one) !== is_staff($other) ? 'staff_direct' : 'direct';
}

/**
 * The conversation between two accounts, made if it does not exist yet.
 *
 * One per pair: a messenger that starts a new thread every time somebody writes
 * is a messenger nobody can find anything in. Its kind is pair_kind()'s, from
 * the roles as they are when it is made. A 'staff_direct' one is owned by the
 * student so it outlives a trainer's login; any other by whoever started it.
 * Both accounts are held first, so two taps at once cannot make two.
 */
function direct_thread(array $from, int $toId): int {
    if (!may_message($from, $toId))
        throw new UserError(t('Diese Person hat dem Schreiben noch nicht zugestimmt.', 'That person has not agreed to being written to yet.'));
    return transactional(function () use ($from, $toId): int {
        $pair = array_column(rows('SELECT id, role FROM accounts WHERE id IN (?,?) ORDER BY id FOR UPDATE',
            [(int)$from['id'], $toId]), null, 'id');
        if ($existing = pair_thread((int)$from['id'], $toId)) return $existing;
        $starter = $pair[(int)$from['id']] ?? $from;
        $kind = pair_kind($starter, $pair[$toId] ?? []);
        run("INSERT INTO threads (account_id,kind,subject,updated_at) VALUES (?,?,'',?)",
            [$kind === 'staff_direct' && is_staff($starter) ? $toId : (int)$from['id'], $kind, now()]);
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
