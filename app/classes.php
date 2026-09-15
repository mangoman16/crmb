<?php
declare(strict_types=1);

/**
 * Training classes and the payment details attached to them.
 *
 * A student may be in several classes. A charge resolves its payment recipient
 * in order: the charge's own profile, then the class's, then the configured
 * default. Each step falls back rather than erroring, so a charge created
 * before any of this existed still shows correct bank details.
 */

function weekdays(): array {
    return [
        1 => t('Montag','Monday'), 2 => t('Dienstag','Tuesday'), 3 => t('Mittwoch','Wednesday'),
        4 => t('Donnerstag','Thursday'), 5 => t('Freitag','Friday'), 6 => t('Samstag','Saturday'),
        7 => t('Sonntag','Sunday'),
    ];
}

function training_classes(bool $archived=false): array {
    return rows('SELECT c.*, t.name AS tariff_name, p.name AS profile_name, a.name AS trainer_name,'
        .' (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id=c.id AND cs.left_on IS NULL) AS member_count'
        .' FROM classes c'
        .' LEFT JOIN tariffs t ON t.id=c.tariff_id'
        .' LEFT JOIN payment_profiles p ON p.id=c.payment_profile_id'
        .' LEFT JOIN accounts a ON a.id=c.trainer_id'
        .($archived?'':' WHERE c.archived=0')
        .' ORDER BY c.archived, c.sort_order, c.name, c.id');
}

function training_class(int $id): array {
    $c = one('SELECT c.*, t.name AS tariff_name, p.name AS profile_name, a.name AS trainer_name'
        .' FROM classes c LEFT JOIN tariffs t ON t.id=c.tariff_id'
        .' LEFT JOIN payment_profiles p ON p.id=c.payment_profile_id'
        .' LEFT JOIN accounts a ON a.id=c.trainer_id WHERE c.id=?', [$id]);
    if (!$c) throw new UserError(t('Kurs nicht gefunden.','Class not found.'));
    return $c;
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

function class_schedule(array $c): string {
    $parts = [];
    if ($c['weekday'] !== null && isset(weekdays()[(int)$c['weekday']])) $parts[] = weekdays()[(int)$c['weekday']];
    if ($c['starts_at']) {
        $time = substr((string)$c['starts_at'], 0, 5);
        if ($c['ends_at']) $time .= '–'.substr((string)$c['ends_at'], 0, 5);
        $parts[] = $time;
    }
    if (($c['location'] ?? '') !== '') $parts[] = (string)$c['location'];
    return $parts ? implode(' · ', $parts) : t('Kein Termin hinterlegt','No schedule set');
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
