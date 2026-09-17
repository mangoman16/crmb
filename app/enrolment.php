<?php
declare(strict_types=1);

/**
 * Who is in which course, on which tariff, and who decided.
 *
 * The enrolment is the row that answers "what does this child pay for this
 * course": which tariff, any agreed price of their own, and which day of the
 * month the family pays on. A child in three courses has three of them, which is
 * the whole reason the price could not stay on the student.
 *
 * Joining, leaving and changing tariff are asked for and decided. A student
 * asks; nothing changes until the trainer approves it. The trainer herself does
 * not ask - she is the person the asking is addressed to, so her own changes
 * take effect at once and are recorded as hers.
 */

/** What can be asked for. */
function request_kinds(): array {
    return [
        'join'   => t('Anmeldung',      'Enrolment'),
        'leave'  => t('Abmeldung',      'Leaving'),
        'tariff' => t('Tarifwechsel',   'Change of tariff'),
    ];
}

function request_kind_label(string $kind): string { return request_kinds()[$kind] ?? $kind; }

function request_state_label(string $state): string {
    return match ($state) {
        'pending'   => t('Wartet auf Entscheidung', 'Waiting for a decision'),
        'approved'  => t('Angenommen',              'Approved'),
        'declined'  => t('Abgelehnt',               'Declined'),
        'withdrawn' => t('Zurückgezogen',           'Withdrawn'),
        default     => $state,
    };
}

/**
 * The columns every enrolment screen needs, with the tariff it names.
 *
 * cs.interval_months and t.interval_months are both called interval_months, and
 * a fetch keyed by column name keeps whichever came last. Both are aliased here
 * so neither can quietly stand in for the other, and with_tariff_rate() writes
 * the answer back as 'interval_months'.
 */
const ENROLMENT_COLUMNS = 'cs.*, cs.interval_months AS enrolment_interval,'
    .' c.name AS class_name, c.location, t.name AS tariff_name, t.period,'
    .' t.interval_months AS tariff_interval, t.due_day AS tariff_due_day';

/** One enrolment, with the tariff it names and the course it is in. */
function enrolment(int $classId, int $studentId): ?array {
    $row = one('SELECT '.ENROLMENT_COLUMNS
        .' FROM class_students cs JOIN classes c ON c.id=cs.class_id'
        .' LEFT JOIN tariffs t ON t.id=cs.tariff_id'
        .' WHERE cs.class_id=? AND cs.student_id=?', [$classId, $studentId]);
    return $row === null ? null : with_tariff_rate($row, tariff_rate_map([(int)$row['tariff_id']]));
}

/** Every course one student is in, current ones first. */
function student_enrolments(int $studentId): array {
    $rows = rows('SELECT '.ENROLMENT_COLUMNS.', c.archived'
        .' FROM class_students cs JOIN classes c ON c.id=cs.class_id'
        .' LEFT JOIN tariffs t ON t.id=cs.tariff_id'
        .' WHERE cs.student_id=? ORDER BY cs.left_on IS NOT NULL, c.sort_order, c.name', [$studentId]);
    $rates = tariff_rate_map(array_column($rows, 'tariff_id'));
    return array_map(fn($row) => with_tariff_rate($row, $rates), $rows);
}

/** What this enrolment costs per period, and where that number came from. */
function enrolment_price(array $enrolment): array {
    if ($enrolment['price_cents'] !== null)
        return ['cents' => (int)$enrolment['price_cents'], 'own' => true, 'note' => (string)$enrolment['price_note']];
    if ($enrolment['tariff_id'] === null || ($enrolment['tariff_price'] ?? null) === null)
        return ['cents' => null, 'own' => false, 'note' => ''];
    return ['cents' => (int)$enrolment['tariff_price'], 'own' => false, 'note' => ''];
}

/**
 * Courses a student could ask to join: running, not full, and not already theirs.
 *
 * Capacity 0 means no limit, which is what an empty field on the course form
 * leaves behind and what most of her courses actually are.
 */
function courses_open_to(int $studentId): array {
    return rows('SELECT c.*, (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id=c.id AND cs.left_on IS NULL) AS member_count'
        .' FROM classes c WHERE c.archived=0'
        .' AND NOT EXISTS (SELECT 1 FROM class_students cs WHERE cs.class_id=c.id AND cs.student_id=? AND cs.left_on IS NULL)'
        .' ORDER BY c.sort_order, c.name, c.id', [$studentId]);
}

function course_is_full(array $class): bool {
    return (int)$class['capacity'] > 0 && (int)($class['member_count'] ?? 0) >= (int)$class['capacity'];
}

/** Requests waiting on the trainer, or a student's own history. */
function open_requests(?int $studentId = null): array {
    return rows('SELECT r.*, s.first_name, s.last_name, c.name AS class_name, t.name AS tariff_name'
        .' FROM enrolment_requests r JOIN students s ON s.id=r.student_id JOIN classes c ON c.id=r.class_id'
        .' LEFT JOIN tariffs t ON t.id=r.tariff_id'
        ." WHERE r.state='pending'".($studentId ? ' AND r.student_id=?' : '')
        .' ORDER BY r.created_at, r.id', $studentId ? [$studentId] : []);
}

function student_requests(int $studentId, int $limit = 20): array {
    return rows('SELECT r.*, c.name AS class_name, t.name AS tariff_name, a.name AS decided_by_name'
        .' FROM enrolment_requests r JOIN classes c ON c.id=r.class_id'
        .' LEFT JOIN tariffs t ON t.id=r.tariff_id LEFT JOIN accounts a ON a.id=r.decided_by'
        .' WHERE r.student_id=? ORDER BY r.id DESC LIMIT '.max(1, min(100, $limit)), [$studentId]);
}

/** How many decisions are waiting, for the badge in the navigation. */
function pending_request_count(): int {
    return (int)scalar("SELECT COUNT(*) FROM enrolment_requests WHERE state='pending'");
}

/**
 * Ask for something. Refuses a second open request for the same course.
 *
 * The check and the insert are in one transaction with the existing row locked,
 * because two taps on a slow phone connection are the ordinary way a duplicate
 * gets made and "I pressed it twice" should not produce two decisions.
 */
function request_enrolment(int $studentId, int $classId, string $kind, ?int $tariffId, string $message): int {
    $kind = choose($kind, array_keys(request_kinds()));
    return transactional(function () use ($studentId, $classId, $kind, $tariffId, $message): int {
        $open = one("SELECT * FROM enrolment_requests WHERE student_id=? AND class_id=? AND state='pending' FOR UPDATE",
                    [$studentId, $classId]);
        if ($open) throw new UserError(t('Für diesen Kurs wartet schon eine Anfrage auf eine Entscheidung.',
                                         'A request for this course is already waiting for a decision.'));
        $enrolled = (bool)one('SELECT 1 FROM class_students WHERE class_id=? AND student_id=? AND left_on IS NULL',
                              [$classId, $studentId]);
        if ($kind === 'join' && $enrolled)
            throw new UserError(t('Dieses Kind ist schon in diesem Kurs.', 'This child is already in this course.'));
        if ($kind !== 'join' && !$enrolled)
            throw new UserError(t('Dieses Kind ist nicht in diesem Kurs.', 'This child is not in this course.'));
        if ($kind === 'tariff' && $tariffId === null)
            throw new UserError(t('Bitte den gewünschten Tarif auswählen.', 'Please choose the tariff you want.'));
        if ($tariffId !== null && !one('SELECT 1 FROM tariffs WHERE id=? AND class_id=? AND archived=0', [$tariffId, $classId]))
            throw new UserError(t('Dieser Tarif gehört nicht zu diesem Kurs.', 'That tariff does not belong to this course.'));
        run('INSERT INTO enrolment_requests (student_id,class_id,kind,tariff_id,message,state,requested_by,created_at)'
            ." VALUES (?,?,?,?,?,'pending',?,?)",
            [$studentId, $classId, $kind, $tariffId, mb_substr($message, 0, 500), current_user()['id'] ?? null, now()]);
        $id = (int)db()->lastInsertId();
        audit('enrolment.requested', 'student', $studentId);
        return $id;
    });
}

/**
 * Decide one. Approving is what actually changes the enrolment.
 *
 * Everything happens in one transaction: a request that says "approved" while
 * the enrolment it approved was never written is worse than a failure, because
 * nobody would go looking.
 */
function decide_request(int $requestId, bool $approve, string $note): array {
    return transactional(function () use ($requestId, $approve, $note): array {
        $r = one('SELECT * FROM enrolment_requests WHERE id=? FOR UPDATE', [$requestId]);
        if (!$r) throw new UserError(t('Diese Anfrage gibt es nicht.', 'No such request.'));
        if ($r['state'] !== 'pending') throw new UserError(t('Über diese Anfrage wurde schon entschieden.', 'That request has already been decided.'));
        $studentId = (int)$r['student_id'];
        $classId = (int)$r['class_id'];

        if ($approve) {
            if ($r['kind'] === 'join') {
                // Checked again here, not only when the request was made: two
                // families can ask for the last place on the same evening, and
                // the second yes would otherwise put a ninth child into an
                // eight-place hall without anybody being told.
                $class = one('SELECT c.*, (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id=c.id AND cs.left_on IS NULL)'
                    .' AS member_count FROM classes c WHERE c.id=? FOR UPDATE', [$classId]);
                if ($class && course_is_full($class))
                    throw new UserError(t('Dieser Kurs ist inzwischen voll. Erst einen Platz frei machen oder die Plätze erhöhen.',
                                          'This course has filled up in the meantime. Free a place first, or raise the number of places.'));
                // A child who left this course before comes back on the same row
                // rather than a second one, so their history stays in one place.
                run('INSERT INTO class_students (class_id,student_id,joined_on,left_on,tariff_id)'
                    .' VALUES (?,?,?,NULL,?) ON DUPLICATE KEY UPDATE joined_on=VALUES(joined_on),left_on=NULL,tariff_id=VALUES(tariff_id)',
                    [$classId, $studentId, today(), $r['tariff_id']]);
            } elseif ($r['kind'] === 'leave') {
                run('UPDATE class_students SET left_on=? WHERE class_id=? AND student_id=? AND left_on IS NULL',
                    [today(), $classId, $studentId]);
            } else {
                run('UPDATE class_students SET tariff_id=? WHERE class_id=? AND student_id=?', [$r['tariff_id'], $classId, $studentId]);
            }
        }
        run('UPDATE enrolment_requests SET state=?, decision_note=?, decided_by=?, decided_at=? WHERE id=?',
            [$approve ? 'approved' : 'declined', mb_substr($note, 0, 500), current_user()['id'] ?? null, now(), $requestId]);
        audit('enrolment.' . ($approve ? 'approved' : 'declined'), 'student', $studentId);
        return $r;
    });
}
