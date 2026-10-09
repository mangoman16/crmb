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
 *   direct        any other two: two members of staff, who write, or two
 *                 students from before their chats closed (ADR 0022 §11.3),
 *                 which the two still read and nobody writes in.
 *   staff         the shared desk from before ADR 0022: a family and every
 *                 member of staff. Kept to read, closed to new messages.
 *
 * Whether a chat between two still takes messages is asked of who is in it now
 * (two_person_chat_open()): with no member of staff left in it, it is closed,
 * whatever kind it was made as.
 *
 * An administrator reads every conversation of every kind, and writes only in
 * her own (ADR 0022 §11.1). Her reading leaves nothing behind (§11.2).
 *
 * Who sees what is one SQL condition (thread_listed_sql(), thread_readable_sql())
 * used by the list, the unread badge and the page alike: two spellings of one
 * rule is how a private conversation ends up in somebody's unread count.
 */

/**
 * Whether $user's photo in a chat comes from the camera (ADR 0022 §11.4): a
 * student's does, and so does that of nobody signed in, who gets no more than a
 * student; staff may pick one from the gallery too. The one answer for which
 * photos may be sent (message_upload_types()), for whether the photo button
 * asks the phone for its camera, and for how a photo that is not a camera's is
 * refused.
 */
function chat_photo_from_camera(?array $user): bool {
    return $user === null || !is_staff($user);
}

/**
 * The photos $user may send in a chat (ADR 0022 §11.4), as media type =>
 * extension: what store_upload() checks the bytes against and what the
 * composer offers, so the form never offers what the server refuses.
 *
 * From the camera, a JPEG, the one thing a phone's camera hands over, which
 * keeps out screenshots, animations and documents. Staff send JPEG, PNG or WebP,
 * from the camera or the gallery. No GIF for anybody: no camera writes one, and
 * it is the one picture kept uncleaned. Voice notes and files sent before stay,
 * and are shown as they are; nobody adds a new one.
 */
function message_upload_types(?array $user): array {
    return chat_photo_from_camera($user)
        ? ['image/jpeg' => 'jpg']
        : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
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
        .' WHERE cs.class_id=t.class_id AND s.account_id=? AND '.running_enrolment_sql('cs').'))'
        ." OR (t.kind IN ('direct','staff_direct','staff') AND $mine))", [$id, $id]];
}

/**
 * The conversations $user may open: their list, and for staff the group of an
 * archived course as well. An administrator may open every conversation there
 * is (ADR 0022 §11.1) - the owner: "chats should be readable by admin if needed
 * in case there are problems". Her list still leaves out the ones she is not
 * in, so her badge counts only messages meant for her.
 */
function thread_readable_sql(array $user): array {
    if (is_admin($user)) return ['TRUE', []];
    [$sql, $params] = thread_listed_sql($user);
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
    $row = one('SELECT t.*, a.name AS account_name, c.name AS class_name, c.archived AS class_archived, '
        .THREAD_HAS_STAFF_SQL.' AS has_staff'
        .' FROM threads t LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN classes c ON c.id=t.class_id'
        .' WHERE t.id=? AND '.$readable, [$id, ...$params]);
    if (!$row) throw new NotFound(t('Unterhaltung nicht gefunden.', 'Conversation not found.'));
    return $row;
}

/** Whether a member of staff is in conversation t, as the column two_person_chat_open() reads. */
const THREAD_HAS_STAFF_SQL = "EXISTS (SELECT 1 FROM thread_participants hs JOIN accounts ha ON ha.id=hs.account_id"
    ." WHERE hs.thread_id=t.id AND ha.role IN ('admin','trainer','manager'))";

/** Whether a conversation is a chat between two people, of either kind pair_kind() makes. */
function two_person_chat(array $thread): bool {
    return in_array($thread['kind'] ?? '', ['staff_direct', 'direct'], true);
}

/**
 * Whether a chat between two people takes messages: while a member of staff is
 * in it. A student writes to staff and staff to anybody (may_message()); two
 * students have no chat with each other any more, and the ones from before
 * stay to read, closed (ADR 0022 §11.3).
 *
 * Asked of who is in it now, never of what the two were when the chat was made
 * or of who is asking: roles change. A 'direct' chat between two trainers
 * stays open while one of them is staff, as a student's chat with staff is; a
 * 'staff_direct' chat whose trainer was made a student has two students in it
 * and closes, whatever kind it was made as (security review of round 2). A row
 * that does not say who is in it is closed.
 *
 * The one answer for may_write_thread(), the chat list's „Frühere
 * Unterhaltungen", „Alle Einzelchats", the line at the top of the chat and
 * what an administrator reading along is told.
 */
function two_person_chat_open(array $thread): bool {
    return two_person_chat($thread) && (int)($thread['has_staff'] ?? 0) !== 0;
}

/** The other half: a chat between two people with no member of staff left in it. */
function two_person_chat_closed(array $thread): bool {
    return two_person_chat($thread) && !two_person_chat_open($thread);
}

/**
 * Whether $user may write in a conversation thread_record() gave them: the group
 * of a running course, or a two-person chat they are in and that is not closed.
 * Nobody writes into a desk thread any more, an administrator reading somebody
 * else's chat only reads (ADR 0022 §11.1), and nobody writes while viewing the
 * portal as somebody else.
 */
function may_write_thread(array $user, array $thread): bool {
    // Nobody writes under somebody else's name while looking through their eyes.
    if (impersonator()) return false;
    return match ((string)($thread['kind'] ?? '')) {
        'course' => (int)($thread['class_archived'] ?? 1) === 0,
        'staff_direct', 'direct' => two_person_chat_open($thread) && is_participant((int)$thread['id'], (int)$user['id']),
        default => false,
    };
}

/**
 * What an administrator reading a chat between two others is told, where the
 * writing box would be and if she sends something anyway (ADR 0022 §11.1).
 */
function only_the_two_write(): string {
    return t('Du liest hier mit. Schreiben können nur die beiden.', 'You are reading along. Only the two of them can write.');
}

function is_participant(int $threadId, int $accountId): bool {
    return (bool)one('SELECT 1 FROM thread_participants WHERE thread_id=? AND account_id=?', [$threadId, $accountId]);
}

/**
 * What the chat shows of a person: who, their role, whether their login is in
 * use and a team member's picture - and a family's login carries its child's
 * picture beside it (child_picture_columns()). avatar() draws by them (ADR 0031
 * §6). One list, so the header, the member sheet, the contacts and the chat
 * list cannot draw one person from different facts.
 */
const CHAT_PERSON_COLUMNS = ['id', 'name', 'role', 'state', 'picture_name'];

/**
 * Those columns as a select list over the accounts table under $alias, with the
 * child's picture columns after them. With a $prefix each is named with it, for
 * a row that carries a person beside columns of its own; chat_person_in() takes
 * the person back out of such a row. $student is the alias of the login's
 * student where the statement has joined it already - a course's members, who
 * are students first and may have no login in use - and the picture is read
 * from it rather than looked up beside the login.
 */
function chat_person_columns(string $alias, string $prefix = '', string $student = ''): string {
    $alias = sql_name($alias, 'table alias');
    if ($prefix !== '') $prefix = sql_name($prefix, 'column prefix');
    return implode(', ', array_map(fn(string $column): string => $alias . '.' . $column . ($prefix !== '' ? ' AS ' . $prefix . $column : ''),
                                   CHAT_PERSON_COLUMNS))
        . ', ' . child_picture_columns($alias, $prefix, $student);
}

/** The person a row carries under $prefix, as the helpers that draw people expect one. */
function chat_person_in(array $row, string $prefix): array {
    $person = [];
    foreach ([...CHAT_PERSON_COLUMNS, ...array_keys(CHILD_PICTURE_COLUMNS)] as $column) $person[$column] = $row[$prefix . $column] ?? null;
    return $person;
}

/** Everyone in a chat, for its header. */
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
 * other person, the last message and its writer (both people as
 * CHAT_PERSON_COLUMNS has them), its first file, the number in the course,
 * whether a member of staff is in it (two_person_chat_open()) and how many
 * messages are unread.
 *
 * With $allDirect, an administrator's „Alle Einzelchats" (ADR 0022 §11.1): every
 * chat between two people that she is not in, with nothing unread. She has no
 * read mark in those (mark_thread_read()), and none of them waits for her.
 *
 * ponytail: the writer carries a child's picture columns too, as every person
 * the chat shows does (ADR 0031), though the list draws no face for the
 * writer: three lookups by a unique key a row. The way up, should the list
 * ever grow slow, is the writer's columns without them.
 */
function chat_list(array $user, bool $allDirect = false): array {
    $me = (int)$user['id'];
    if ($allDirect) {
        if (!is_admin($user)) throw new UserError(t('Nur für Administratoren.', 'Administrators only.'));
        // What she may open, narrowed to the two-person chats she is not in.
        // Built on the one rule rather than beside it: if who reads those chats
        // changes, or a view through somebody's eyes narrows it, this list
        // follows (ADR 0022 §2, §9).
        [$readable, $readableParams] = thread_seen_sql($user, false);
        $where = "($readable) AND t.kind IN ('staff_direct','direct')"
            .' AND NOT EXISTS (SELECT 1 FROM thread_participants p WHERE p.thread_id=t.id AND p.account_id=?)';
        $whereParams = [...$readableParams, $me];
        [$unread, $unreadParams] = ['0', []];
    } else {
        [$where, $whereParams] = thread_seen_sql($user, true);
        [$unread, $unreadParams] = ['(SELECT COUNT(*) FROM messages mu WHERE mu.thread_id=t.id AND NOT (mu.sender_id <=> ?) AND mu.id>COALESCE('
            .'(SELECT r.last_read_message_id FROM thread_reads r WHERE r.thread_id=t.id AND r.account_id=?),0))', [$me, $me]];
    }
    return rows('SELECT t.*, c.name AS class_name, c.archived AS class_archived, ow.name AS account_name, '
        .chat_person_columns('o', 'other_').','
        .' EXISTS (SELECT 1 FROM thread_participants pm WHERE pm.thread_id=t.id AND pm.account_id=?) AS me_in,'
        ." (SELECT GROUP_CONCAT(pa.name ORDER BY pa.name SEPARATOR ' · ') FROM thread_participants pp"
        .'   JOIN accounts pa ON pa.id=pp.account_id WHERE pp.thread_id=t.id) AS people_names,'
        .' '.THREAD_HAS_STAFF_SQL.' AS has_staff,'
        .' m.id AS last_id, CASE WHEN m.removed_at IS NULL THEN m.body END AS last_body,'
        .' m.created_at AS last_at, m.removed_at AS last_removed_at, '.chat_person_columns('ms', 'last_sender_').','
        ." (SELECT CONCAT(f.kind,'|',f.seconds,'|',f.original_name) FROM message_files f WHERE f.message_id=m.id"
        .'   AND m.removed_at IS NULL ORDER BY f.id LIMIT 1) AS last_file,'
        .' (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id=t.class_id AND '.current_enrolment_sql('cs').') AS members,'
        .' '.$unread.' AS unread'
        .' FROM threads t LEFT JOIN classes c ON c.id=t.class_id LEFT JOIN accounts ow ON ow.id=t.account_id'
        ." LEFT JOIN accounts o ON t.kind<>'course' AND o.id=(SELECT p2.account_id FROM thread_participants p2"
        .'   WHERE p2.thread_id=t.id AND p2.account_id<>? ORDER BY p2.account_id LIMIT 1)'
        .' LEFT JOIN messages m ON m.id=(SELECT MAX(id) FROM messages WHERE thread_id=t.id)'
        .' LEFT JOIN accounts ms ON ms.id=m.sender_id'
        .' WHERE '.$where
        ." ORDER BY t.kind='course' DESC, t.kind='staff' ASC, m.id IS NULL ASC, m.id DESC, c.name, t.id DESC",
        [$me, ...$unreadParams, $me, ...$whereParams]);
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
 * Remember how far $reader has read a conversation in their own list. Per
 * account, because staff share the groups: one trainer's reading must not hide
 * a message from another.
 *
 * Only a chat in their list - thread_seen_sql($reader, true), the rule the list
 * and the badge use - and never while staff look through somebody's eyes, who
 * has not read it (ADR 0022 §9). So an administrator opening a chat she is not
 * in leaves nothing behind: no row, whose updated_at would say when she read
 * it, and nothing the two in the chat could see. The owner: "it should not be
 * recorded if an admin does something" (§11.2). One statement, which writes
 * nothing when the chat is not in the list and only ever moves a mark forward.
 */
function mark_thread_read(int $threadId, array $reader): void {
    if (impersonator()) return;
    [$listed, $params] = thread_seen_sql($reader, true);
    // Qualified: an INSERT … SELECT reads threads too, which has an updated_at of its own.
    run('INSERT INTO thread_reads (thread_id,account_id,last_read_message_id,updated_at)'
        .' SELECT t.id,?,COALESCE((SELECT MAX(m.id) FROM messages m WHERE m.thread_id=t.id),0),? FROM threads t WHERE t.id=? AND '.$listed
        .' ON DUPLICATE KEY UPDATE thread_reads.last_read_message_id=GREATEST(thread_reads.last_read_message_id,VALUES(last_read_message_id)),'
        .'thread_reads.updated_at=VALUES(updated_at)',
        [(int)$reader['id'], now(), $threadId, ...$params]);
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
 * Whether $accountId has read every message of a conversation from before
 * message $messageId, except their own - how message_send decides whether a
 * new message is worth a mail to them: one unread from before was mailed about
 * already. `<=>` for a message whose sender's login was deleted, as above.
 */
function has_read_before(int $threadId, int $accountId, int $messageId): bool {
    return !scalar('SELECT 1 FROM messages m WHERE m.thread_id=? AND m.id<? AND NOT (m.sender_id <=> ?)'
        .' AND m.id>COALESCE((SELECT r.last_read_message_id FROM thread_reads r WHERE r.thread_id=m.thread_id AND r.account_id=?),0) LIMIT 1',
        [$threadId, $messageId, $accountId, $accountId]);
}

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
 * can say who cannot read the group yet. A student without one still carries
 * their picture (chat_person_columns()'s $student), for staff, who see it.
 */
function course_group_people(int $classId): array {
    return [
        'staff' => rows('SELECT '.chat_person_columns('a')." FROM accounts a WHERE a.role IN ('admin','trainer','manager') AND a.state='active'"
            .' ORDER BY a.name, a.id'),
        'members' => rows('SELECT s.id AS student_id, s.first_name, s.last_name, '.chat_person_columns('a', '', 's').' FROM class_students cs'
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
 * and groups only: a family's chat is theirs. The words stay in the database for
 * removed_messages_days, after which the daily cleanup deletes the message
 * (ADR 0032), so restoring from the same place is the way back until then; who
 * did it is in the audit log. Returns the conversation's id.
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
// Who may start a chat with whom
// ---------------------------------------------------------------------------

/**
 * Whether these two may start a chat: anybody with staff, and staff with
 * anybody. Never two students: chats between students have closed (ADR 0022
 * §11.3), and the ones from before stay to read, closed (may_write_thread()).
 */
function may_message(array $from, int $toId): bool {
    if ((int)$from['id'] === $toId) return false;
    $to = one("SELECT * FROM accounts WHERE id=? AND state='active'", [$toId]);
    return $to !== null && (is_staff($to) || is_staff($from));
}

/**
 * What $from is told when may_message() says no: a family where to write
 * instead, staff only that it cannot be done - the coaching team and a course
 * group are theirs already (code review of round 2).
 */
function chat_refusal(array $from): string {
    return is_staff($from)
        ? t('Diese Person kannst du hier nicht anschreiben.', 'You cannot write to this person here.')
        : t('Mit dieser Person lässt sich hier kein Chat beginnen. Schreib dem Trainerteam oder in deine Kursgruppe.',
            'You cannot start a chat with this person here. Write to the coaching team or in your course group.');
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
 * staff a 'staff_direct' one; any other two a 'direct' one, which since §11.3
 * only two members of staff can start. Fixed when the chat is made - a later
 * change of role never changes who reads it, while whether it still takes
 * messages is asked of who is in it now (two_person_chat_open()) - and asked
 * beforehand only by the page that shows the empty chat before its first
 * message, so it says what the chat will be.
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
    if (!may_message($from, $toId)) throw new UserError(chat_refusal($from));
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
    // Only a photo arrives here: store_upload() refuses anything
    // message_upload_types() does not name.
    $stored = store_upload($field, 'message');
    // Without the name the phone gave it: „IMG_2041.jpg", or a family's own
    // words, would be the whole group's to read, as the picture's text and the
    // name it downloads under. It downloads under its stored name instead
    // (upload_download_name()) (security review, 2026-10-08).
    run('INSERT INTO message_files (message_id,kind,stored_name,original_name,mime,bytes,seconds,created_at)'
        .' VALUES (?,?,?,?,?,?,?,?)',
        [$messageId, 'image', $stored['stored_name'], '', $stored['mime'], $stored['bytes'], 0, now()]);
    return true;
}

/** A length like "0:42", for a voice note. */
function duration_label(int $seconds): string {
    if ($seconds <= 0) return '';
    return intdiv($seconds, 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}
