<?php
declare(strict_types=1);

/**
 * Attendance per training session.
 *
 * Recorded courtside on a phone, so the interface that uses this is built for
 * one thumb: the whole class on one screen, one tap per student, one save.
 *
 * A session is just a class plus a date. There is no separate sessions table
 * because nothing else needs one yet, and inventing one would mean generating
 * rows nobody asked for.
 */

function attendance_statuses(): array {
    $list=(array)setting('attendance_statuses');
    return $list ?: ['present'=>'Anwesend'];
}

/** The status preselected for a student with nothing recorded yet. */
function attendance_default_status(): string { return (string)array_key_first(attendance_statuses()); }

function attendance_label(string $code): string { return attendance_statuses()[$code] ?? $code; }

/**
 * Which visual treatment a status gets.
 *
 * Keyed off the shipped codes only; a status the operator invents renders
 * neutral rather than guessing a colour from its name.
 */
function attendance_tone(string $code): string {
    return match($code) { 'present'=>'green', 'absent'=>'red', 'late'=>'amber', default=>'' };
}

/** What was recorded for one class on one date, keyed by student id. */
function attendance_for_session(int $classId, string $date): array {
    $out=[];
    foreach(rows('SELECT * FROM attendance WHERE class_id=? AND session_on=?',[$classId,$date]) as $r)
        $out[(int)$r['student_id']]=$r;
    return $out;
}

/** Dates that already have attendance for a class, newest first. */
function attendance_session_dates(int $classId, int $limit=30): array {
    return array_column(rows('SELECT session_on, COUNT(*) AS n FROM attendance WHERE class_id=?'
        .' GROUP BY session_on ORDER BY session_on DESC LIMIT '.max(1,min(200,$limit)),[$classId]),'session_on');
}

/**
 * The next sensible date to record for a class.
 *
 * A class that meets on Mondays should open on the most recent Monday, not on
 * today, because attendance is usually entered during or just after training.
 */
function attendance_suggested_date(array $class): string {
    $today=new DateTimeImmutable(today());
    if($class['weekday']===null) return $today->format('Y-m-d');
    $weekday=(int)$class['weekday'];
    // ISO-8601: 1 = Monday .. 7 = Sunday, matching the stored value.
    $diff=((int)$today->format('N')-$weekday+7)%7;
    return $today->modify('-'.$diff.' days')->format('Y-m-d');
}

/** Counts per status for one student, for the summary on their page. */
function attendance_summary(int $studentId, int $days=180): array {
    $since=(new DateTimeImmutable(today()))->modify('-'.$days.' days')->format('Y-m-d');
    $out=[];
    foreach(rows('SELECT status, COUNT(*) AS n FROM attendance WHERE student_id=? AND session_on>=? GROUP BY status',[$studentId,$since]) as $r)
        $out[$r['status']]=(int)$r['n'];
    return $out;
}

/** Recent sessions for one student, across all their classes. */
function attendance_recent(int $studentId, int $limit=12): array {
    return rows('SELECT a.*, c.name AS class_name FROM attendance a JOIN classes c ON c.id=a.class_id'
        .' WHERE a.student_id=? ORDER BY a.session_on DESC, a.id DESC LIMIT '.max(1,min(100,$limit)),[$studentId]);
}

/** Share of sessions attended, as a percentage, or null when nothing recorded. */
function attendance_rate(array $summary): ?float {
    $total=array_sum($summary);
    if($total===0) return null;
    // "Late" still counts as having turned up.
    return (($summary['present']??0)+($summary['late']??0))/$total*100;
}
