<?php
declare(strict_types=1);

/**
 * Courses: when they meet, what they cost, and who is in them.
 *
 * A course is a timetable, not a weekday. It has any number of meeting days,
 * each with its own time and place; a day with no place of its own uses the
 * course's, so moving the whole course to another hall is one change rather than
 * five. One dated meeting can differ from the pattern - cancelled, moved,
 * somewhere else - and only those are stored, so a term that runs as planned
 * writes nothing.
 *
 * A course also owns its tariffs. That is the whole of the answer to "it is very
 * confusing to have both tarifs and courses": there is no longer a separate
 * list of prices to reconcile with a separate list of courses.
 *
 * A student may be in several courses. A charge resolves its payment recipient
 * in order: the charge's own profile, then the course's, then the configured
 * default. Each step falls back rather than erroring, so a charge created before
 * any of this existed still shows correct bank details.
 */

function weekdays(): array {
    return [
        1 => t('Montag','Monday'), 2 => t('Dienstag','Tuesday'), 3 => t('Mittwoch','Wednesday'),
        4 => t('Donnerstag','Thursday'), 5 => t('Freitag','Friday'), 6 => t('Samstag','Saturday'),
        7 => t('Sonntag','Sunday'),
    ];
}

function training_classes(bool $archived=false): array {
    return rows('SELECT c.*, p.name AS profile_name, a.name AS trainer_name,'
        .' (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id=c.id AND cs.left_on IS NULL) AS member_count,'
        .' (SELECT COUNT(*) FROM tariffs t WHERE t.class_id=c.id AND t.archived=0) AS tariff_count'
        .' FROM classes c'
        .' LEFT JOIN payment_profiles p ON p.id=c.payment_profile_id'
        .' LEFT JOIN accounts a ON a.id=c.trainer_id'
        .($archived?'':' WHERE c.archived=0')
        .' ORDER BY c.archived, c.sort_order, c.name, c.id');
}

function training_class(int $id): array {
    $c = one('SELECT c.*, p.name AS profile_name, a.name AS trainer_name'
        .' FROM classes c LEFT JOIN payment_profiles p ON p.id=c.payment_profile_id'
        .' LEFT JOIN accounts a ON a.id=c.trainer_id WHERE c.id=?', [$id]);
    if (!$c) throw new NotFound(t('Kurs nicht gefunden.','Course not found.'));
    return $c;
}

/** The weekly pattern of one course, in the order she put it in. */
function class_days(int $classId): array {
    return rows('SELECT * FROM class_days WHERE class_id=? ORDER BY sort_order, weekday, starts_at, id', [$classId]);
}

/** The weekly pattern of several courses at once, as class_id => rows. */
function class_days_for(array $classIds): array {
    $ids = array_values(array_unique(array_map('intval', $classIds)));
    if (!$ids) return [];
    $out = array_fill_keys($ids, []);
    foreach (rows('SELECT * FROM class_days WHERE class_id IN ('.implode(',', array_fill(0, count($ids), '?')).')'
        .' ORDER BY sort_order, weekday, starts_at, id', $ids) as $day)
        $out[(int)$day['class_id']][] = $day;
    return $out;
}

/**
 * What is still missing on one course, as things to do with links.
 *
 * The same idea as a child's list: the form that creates a course asks what a
 * course is, and the two things that make it usable - when it meets and what it
 * costs - are said here rather than discovered on the first of the month when
 * nobody was billed.
 */
function class_next_steps(int $classId): array {
    $steps = [];
    if (!class_days($classId))
        $steps[] = ['what' => t('Trainingstag eintragen', 'Add a training day'),
                    'why'  => t('Ohne Termin gibt es nichts, wozu man anwesend sein kann.', 'Without a date there is nothing to be present at.'),
                    'page' => 'classes', 'params' => ['id' => $classId, 'edit' => 1]];
    if (!class_tariffs($classId))
        $steps[] = ['what' => t('Tarif anlegen', 'Add a tariff'),
                    'why'  => t('Ohne Tarif entstehen für diesen Kurs keine Beiträge.', 'Without a tariff this course bills nobody.'),
                    'page' => 'classes', 'params' => ['id' => $classId, 'tab' => 'tariffs']];
    return $steps;
}

/** The tariffs a course offers, cheapest arrangement first. */
function class_tariffs(int $classId, bool $archived=false): array {
    return rows('SELECT * FROM tariffs WHERE class_id=?'.($archived?'':' AND archived=0')
        // Was "cheapest first" when a tariff had one price. It has several now,
        // so the order she puts them in is the only order that means anything.
        .' ORDER BY sort_order, name, id', [$classId]);
}

/** Tariffs that belong to no course yet, so they can be given one rather than lost. */
function unattached_tariffs(): array {
    return rows('SELECT * FROM tariffs WHERE class_id IS NULL AND archived=0 ORDER BY name, id');
}

/**
 * How one meeting day reads: "Montag 16:00-17:30, Sporthalle Nord".
 *
 * The place is only named when the day has one of its own; otherwise the course
 * already said where it is and repeating it on every line is noise.
 */
function class_day_label(array $day, array $class=[]): string {
    $parts = [weekdays()[(int)$day['weekday']] ?? '?'];
    if ($day['starts_at']) {
        $time = substr((string)$day['starts_at'], 0, 5);
        if ($day['ends_at']) $time .= '–'.substr((string)$day['ends_at'], 0, 5);
        $parts[] = $time;
    }
    $where = (string)($day['location'] !== '' ? $day['location'] : ($class['location'] ?? ''));
    if ($where !== '') $parts[] = $where;
    return implode(' · ', $parts);
}

/** Members of a class, current first. */
function class_members(int $classId): array {
    return rows('SELECT s.*, cs.joined_on, cs.left_on FROM class_students cs'
        .' JOIN students s ON s.id=cs.student_id WHERE cs.class_id=?'
        .' ORDER BY cs.left_on IS NOT NULL, s.last_name, s.first_name, s.id', [$classId]);
}

/** Classes one student belongs to. */
function student_classes(int $studentId): array {
    return rows('SELECT c.*, cs.joined_on, cs.left_on FROM class_students cs'
        .' JOIN classes c ON c.id=cs.class_id WHERE cs.student_id=?'
        .' ORDER BY cs.left_on IS NOT NULL, c.sort_order, c.name', [$studentId]);
}

/**
 * The whole weekly pattern of a course on one line.
 *
 * $days lets a caller listing many courses pass the pattern it has already
 * loaded, instead of one query per course on a page whose job is to list them.
 */
function class_schedule(array $c, ?array $days=null): string {
    $days ??= class_days((int)$c['id']);
    if (!$days) return ($c['location'] ?? '') !== '' ? (string)$c['location'] : t('Kein Termin hinterlegt','No schedule set');
    return implode(' | ', array_map(fn($day) => class_day_label($day, $c), $days));
}

function payment_profiles(bool $archived=false): array {
    return rows('SELECT * FROM payment_profiles'.($archived?'':' WHERE archived=0').' ORDER BY name, id');
}

/**
 * The payment profile that applies to one charge.
 *
 * Returns null when nothing is configured yet, which the views treat as "no QR
 * code to show" rather than an error.
 */
function charge_payment_profile(array $charge): ?array {
    // Each step is taken only if the one before it came up empty. Written as a
    // list of candidates this read better but evaluated all of them first, so a
    // charge naming its own profile still paid for a lookup of its class's, and
    // a charge with no class looked up class 0 — once per charge, on a page that
    // lists every charge a student has ever had.
    if ($p = payment_profile((int)($charge['payment_profile_id'] ?? 0))) return $p;
    if ($p = payment_profile(class_payment_profile_id((int)($charge['class_id'] ?? 0)))) return $p;
    return payment_profile((int)setting('default_payment_profile'));
}

/**
 * The memo behind the two lookups below, by reference so it can be emptied.
 *
 * Follows the shape setting_cache() already uses. Holding it for the length of a
 * request is safe because an action writes and then redirects, so nothing
 * re-reads a profile it has just changed within the same request.
 */
function &payment_cache(): array { static $cache = ['profile'=>[], 'class'=>[]]; return $cache; }
function payment_cache_clear(): void { $cache =& payment_cache(); $cache = ['profile'=>[], 'class'=>[]]; }

/**
 * One payment profile by id, remembered for the rest of the request.
 *
 * A student's charges nearly all resolve to the same profile, so without this
 * the same row is fetched once per charge listed.
 */
function payment_profile(int $id): ?array {
    if ($id <= 0) return null;
    $cache =& payment_cache();
    if (!array_key_exists($id, $cache['profile']))
        $cache['profile'][$id] = one('SELECT * FROM payment_profiles WHERE id=? AND archived=0', [$id]);
    return $cache['profile'][$id];
}

/** Which profile a class collects into, remembered for the rest of the request. */
function class_payment_profile_id(int $classId): int {
    if ($classId <= 0) return 0;
    $cache =& payment_cache();
    if (!array_key_exists($classId, $cache['class']))
        $cache['class'][$classId] = (int)(scalar('SELECT payment_profile_id FROM classes WHERE id=?', [$classId]) ?: 0);
    return $cache['class'][$classId];
}

/** The remittance reference for a charge, from the operator's template. */
function charge_reference(array $charge, array $student): string {
    $period = $charge['period_from'] && $charge['period_to']
        ? fmt_date($charge['period_from']).'-'.fmt_date($charge['period_to'])
        : '';
    $text = strtr((string)setting('payment_reference_template'), [
        '{label}'   => (string)$charge['label'],
        '{student}' => $student['first_name'].' '.$student['last_name'],
        '{period}'  => $period,
        '{club}'    => (string)setting('club_name'),
    ]);
    // EPC allows 140 characters of unstructured remittance information.
    return mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? $text), 0, 140);
}

// ---------------------------------------------------------------------------
// The calendar: what actually happens on which day
// ---------------------------------------------------------------------------

/** What a dated meeting can be, beyond simply happening. */
function session_statuses(): array {
    return [
        'planned'   => t('Findet statt',  'Going ahead'),
        'cancelled' => t('Entfällt',      'Cancelled'),
        'changed'   => t('Geändert',      'Changed'),
        'extra'     => t('Zusatztermin',  'Extra session'),
    ];
}

/**
 * Every meeting between two dates, from the weekly pattern plus what differs.
 *
 * The pattern produces the dates; class_sessions overrides them. An override may
 * move the time, move the hall, cancel the day outright, or add a date the
 * pattern never produced - which is how a make-up session on a Saturday exists
 * without pretending the course meets on Saturdays.
 *
 * Three queries however wide the window is, because this feeds the start page
 * and the start page is the one everybody opens.
 */
function class_calendar(string $from, string $to, ?int $classId = null): array {
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    if ($start > $end) return [];
    // A window nobody asked for is a page that never finishes rendering.
    if ((int)$start->diff($end)->days > 400) $end = $start->modify('+400 days');

    $classes = [];
    foreach (rows('SELECT id, name, location, archived FROM classes WHERE archived=0'
        . ($classId ? ' AND id=?' : '') . ' ORDER BY sort_order, name, id', $classId ? [$classId] : []) as $c)
        $classes[(int)$c['id']] = $c;
    if (!$classes) return [];

    $pattern = class_days_for(array_keys($classes));
    $overrides = [];
    foreach (rows('SELECT * FROM class_sessions WHERE session_on BETWEEN ? AND ?'
        . ($classId ? ' AND class_id=?' : ''), $classId ? [$from, $to, $classId] : [$from, $to]) as $row)
        $overrides[(int)$row['class_id'] . ':' . $row['session_on']] = $row;

    $entries = [];
    foreach ($classes as $id => $class) {
        foreach ($pattern[$id] ?? [] as $day) {
            // Walk forward from the first matching weekday rather than over every
            // date in the window: a year is 52 steps, not 365.
            $offset = ((int)$day['weekday'] - (int)$start->format('N') + 7) % 7;
            for ($date = $start->modify('+' . $offset . ' days'); $date <= $end; $date = $date->modify('+7 days'))
                $entries[$id . ':' . $date->format('Y-m-d')] = [
                    'class_id' => $id, 'class_name' => $class['name'], 'date' => $date->format('Y-m-d'),
                    'starts_at' => $day['starts_at'], 'ends_at' => $day['ends_at'],
                    'location' => $day['location'] !== '' ? $day['location'] : $class['location'],
                    'status' => 'planned', 'note' => '', 'session_id' => null];
        }
    }
    foreach ($overrides as $key => $row) {
        $id = (int)$row['class_id'];
        if (!isset($classes[$id])) continue;
        $base = $entries[$key] ?? ['class_id' => $id, 'class_name' => $classes[$id]['name'],
                                   'date' => $row['session_on'], 'starts_at' => null, 'ends_at' => null,
                                   'location' => $classes[$id]['location'], 'status' => 'extra', 'note' => ''];
        $entries[$key] = [
            'class_id' => $id, 'class_name' => $classes[$id]['name'], 'date' => $row['session_on'],
            'starts_at' => $row['starts_at'] ?: $base['starts_at'],
            'ends_at' => $row['ends_at'] ?: $base['ends_at'],
            'location' => $row['location'] !== '' ? $row['location'] : $base['location'],
            'status' => $row['status'], 'note' => $row['note'], 'session_id' => (int)$row['id']];
    }
    usort($entries, fn($a, $b) => [$a['date'], (string)$a['starts_at'], $a['class_name']]
                              <=> [$b['date'], (string)$b['starts_at'], $b['class_name']]);
    return array_values($entries);
}

/** One meeting on one date, whether it is stored or only implied by the pattern. */
function class_session(int $classId, string $date): ?array {
    $found = class_calendar($date, $date, $classId);
    return $found[0] ?? null;
}

/** How a meeting reads on one line: "16:00–17:30 · Sporthalle Nord". */
function session_label(array $entry): string {
    $parts = [];
    if ($entry['starts_at']) {
        $time = substr((string)$entry['starts_at'], 0, 5);
        if ($entry['ends_at']) $time .= '–' . substr((string)$entry['ends_at'], 0, 5);
        $parts[] = $time;
    }
    if (($entry['location'] ?? '') !== '') $parts[] = (string)$entry['location'];
    return $parts ? implode(' · ', $parts) : t('Zeit noch offen', 'Time not set');
}
