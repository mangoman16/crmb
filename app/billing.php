<?php
declare(strict_types=1);

/**
 * Turning enrolments into charges.
 *
 * One rule, stated once: a child is billed for the courses they are enrolled in,
 * at the tariff that enrolment names, for periods anchored to the calendar year.
 * Anchored to the year rather than to the day each child joined, so "the second
 * quarter" means the same three months for everybody and she can answer "what is
 * due in April?" without looking anybody up.
 *
 * Nothing here is time-based magic. A period is only ever created when somebody
 * runs it, from the payments screen or from cron, and running it twice for the
 * same month creates nothing the second time: the plan skips a period a charge
 * already covers, and a unique key in the database catches the race the plan
 * cannot see.
 */

/** How often a tariff may recur, in months. */
function billing_intervals(): array {
    return [
        1  => t('monatlich',      'monthly'),
        2  => t('alle 2 Monate',  'every 2 months'),
        3  => t('alle 3 Monate',  'every 3 months'),
        6  => t('alle 6 Monate',  'every 6 months'),
        12 => t('jährlich',       'yearly'),
    ];
}

function billing_interval_label(int $months): string {
    return billing_intervals()[$months] ?? plural($months, 'Monat', 'Monate', 'month', 'months');
}

/** What happens to a child who joins part-way through a period. */
function billing_first_period_rules(): array {
    return [
        'prorate' => t('anteilig nach Tagen',           'pro rata by days'),
        'full'    => t('voller Zeitraum',               'the whole period'),
        'skip'    => t('erst ab dem nächsten Zeitraum', 'not until the next period'),
    ];
}

/**
 * The period of $months that contains $date, as first and last day.
 *
 * Anchored to January: with three-month periods the year is always
 * Jan-Mar, Apr-Jun, Jul-Sep, Oct-Dec, whoever joined when. Twelve months is the
 * calendar year; one month is the month.
 */
function billing_period_bounds(string $date, int $months): array {
    $months = billing_valid_interval($months);
    $day = new DateTimeImmutable($date);
    $index = intdiv((int)$day->format('n') - 1, $months);
    $from = $day->setDate((int)$day->format('Y'), $index * $months + 1, 1);
    return ['from' => $from->format('Y-m-d'),
            'to'   => $from->modify('+' . ($months - 1) . ' months')->modify('last day of this month')->format('Y-m-d')];
}

function billing_valid_interval(int $months): int {
    if (!isset(billing_intervals()[$months]))
        throw new UserError(t('Bitte einen gültigen Abrechnungszeitraum wählen.', 'Please choose a valid billing interval.'));
    return $months;
}

/** The month a period string refers to, as its first day. 2026-11 -> 2026-11-01. */
function billing_period_start(string $period): string { return $period . '-01'; }

/** Last day of that month. */
function billing_period_end(string $period): string {
    return (new DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d');
}

/** The current month as a period string. */
function billing_current_period(): string { return date('Y-m'); }

function billing_valid_period(string $period): string {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period))
        throw new UserError(t('Bitte einen Monat im Format JJJJ-MM angeben.', 'Please give a month as YYYY-MM.'));
    return $period;
}

/**
 * The day a charge for this period is expected.
 *
 * Capped at the 28th so that February is never a special case and "the 30th"
 * cannot quietly become "the 2nd of the next month".
 */
function billing_due_date(string $periodFrom, int $dueDay): string {
    $day = max(1, min(28, $dueDay ?: 1));
    return (new DateTimeImmutable($periodFrom))->setDate(
        (int)substr($periodFrom, 0, 4), (int)substr($periodFrom, 5, 2), $day)->format('Y-m-d');
}

/** The day it stops being merely open and starts being late. */
function billing_overdue_date(string $due, int $graceDays): string {
    return (new DateTimeImmutable($due))->modify('+' . max(0, min(365, $graceDays)) . ' days')->format('Y-m-d');
}

/** Whole days from $from to $to, both counted. */
function billing_days(string $from, string $to): int {
    return (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
}

/**
 * The first day this enrolment is billed from.
 *
 * The day they joined this course, or - for an enrolment carried over from
 * before courses had their own dates - the day they joined at all.
 */
function billing_start(array $enrolment): ?string {
    return $enrolment['joined_on'] ?: ($enrolment['student_joined_on'] ?: null);
}

/**
 * What one charge comes to, and why.
 *
 * Returns the gross before the gift, what the gift takes off, a sentence saying
 * so, and the amount owed. Separated rather than collapsed into one number,
 * because a parent looking at "31,50 €" when the tariff says "45,00 €" should be
 * able to see which of the two reasons applies.
 */
function billing_amount_for(array $enrolment, array $tariff, array $period): array {
    $price = $enrolment['price_cents'] !== null ? (int)$enrolment['price_cents'] : (int)$tariff['price_cents'];
    $months = billing_valid_interval((int)$tariff['interval_months']);
    $start = billing_start($enrolment);
    $end = $enrolment['left_on'] ?: null;

    $coverFrom = $start !== null && $start > $period['from'] ? $start : $period['from'];
    $coverTo   = $end !== null && $end < $period['to'] ? $end : $period['to'];
    $joinedLate = $coverFrom > $period['from'];
    $leftEarly  = $coverTo < $period['to'];

    $rule = in_array($tariff['first_period'] ?? 'prorate', ['prorate', 'full', 'skip'], true) ? $tariff['first_period'] : 'prorate';
    // The rule answers one question - what happens to somebody who starts in the
    // middle of a period - and the form says so. Leaving is not a choice the
    // tariff gets to make: they were there for part of the period, so that part
    // is what is charged. Read as "any partial period", the same setting gave a
    // child who left on the 15th a free month under 'skip' and charged a whole
    // one under 'full', and nothing in the interface said it would.
    if ($joinedLate && $rule === 'skip')
        return ['gross' => 0, 'discount' => 0, 'amount' => 0, 'note' => '',
                'skip' => t('Erst ab dem nächsten vollen Zeitraum', 'Not until the next whole period')];

    $billFrom = $joinedLate && $rule === 'prorate' ? $coverFrom : $period['from'];
    $billTo   = $coverTo;
    $partial  = $billFrom > $period['from'] || $leftEarly;
    $gross = $partial
        ? (int)round($price * billing_days($billFrom, $billTo) / billing_days($period['from'], $period['to']))
        : $price;

    [$discount, $note] = billing_discount($tariff, $period, $start, $gross, $months);
    return ['gross' => $gross, 'discount' => $discount, 'amount' => max(0, $gross - $discount),
            'note' => $note, 'skip' => null,
            'prorated' => $partial,
            'covered_from' => $billFrom, 'covered_to' => $billTo];
}

/**
 * The welcome gift: how much comes off this period, and how to say so.
 *
 * The gift is expressed in months because that is how she describes it - "the
 * first month free", "30 % for half a year" - and a period may be longer than
 * the gift. So the months of this period that fall inside the gift window are
 * counted, and only their share of the price is discounted. With a monthly
 * tariff and one gifted month that is the whole charge; with a quarterly tariff
 * it is a third of it.
 */
function billing_discount(array $tariff, array $period, ?string $start, int $gross, int $months): array {
    $span = (int)$tariff['discount_months'];
    $value = (int)$tariff['discount_value'];
    if ($span === 0 || $value <= 0 || $gross <= 0 || $start === null) return [0, ''];

    $giftFrom = (new DateTimeImmutable($start))->modify('first day of this month');
    // -1 means "for as long as they stay", which is a discount rather than a
    // gift and is the only way to express a permanently reduced rate.
    $giftTo = $span < 0 ? null : $giftFrom->modify('+' . $span . ' months');

    $covered = 0;
    $month = new DateTimeImmutable($period['from']);
    for ($i = 0; $i < $months; $i++) {
        if ($month >= $giftFrom && ($giftTo === null || $month < $giftTo)) $covered++;
        $month = $month->modify('+1 month');
    }
    if ($covered === 0) return [0, ''];

    $share = (int)round($gross * $covered / $months);
    $discount = ($tariff['discount_kind'] ?? 'percent') === 'fixed'
        ? min($share, $value * $covered)
        : (int)round($share * min(100, $value) / 100);
    if ($discount <= 0) return [0, ''];

    $how = ($tariff['discount_kind'] ?? 'percent') === 'fixed'
        ? money($value) . t(' je Monat', ' per month')
        : min(100, $value) . ' %';
    $note = $span < 0
        ? t('Dauerhafter Nachlass: ', 'Standing discount: ') . $how
        : t('Willkommensrabatt: ', 'Welcome discount: ') . $how . ' · '
          . plural($covered, 'Monat in diesem Zeitraum', 'Monate in diesem Zeitraum', 'month in this period', 'months in this period');
    return [$discount, mb_substr($note, 0, 160)];
}

/** The database-unique key that makes a repeated run harmless. */
function billing_key(string $periodFrom, int $studentId, int $classId): string {
    return 'auto:' . $periodFrom . ':s' . $studentId . ':c' . $classId;
}

/** Enrolments that automatic billing looks at, with their tariff and student. */
function billing_enrolments(): array {
    return rows('SELECT cs.class_id, cs.student_id, cs.joined_on, cs.left_on, cs.tariff_id,'
        .' cs.price_cents, cs.price_note, cs.due_day AS enrolment_due_day,'
        .' s.first_name, s.last_name, s.status, s.billing_paused, s.billing_due_day, s.ended_on,'
        .' s.joined_on AS student_joined_on, c.name AS class_name, c.payment_profile_id,'
        .' t.id AS t_id, t.name AS tariff_name, t.price_cents AS tariff_price, t.period, t.interval_months,'
        .' t.due_day AS tariff_due_day, t.grace_days, t.first_period, t.discount_months, t.discount_kind, t.discount_value'
        .' FROM class_students cs'
        .' JOIN students s ON s.id=cs.student_id'
        .' JOIN classes c ON c.id=cs.class_id'
        .' LEFT JOIN tariffs t ON t.id=cs.tariff_id'
        .' ORDER BY s.last_name, s.first_name, s.id, c.sort_order, c.name');
}

/** The tariff columns, lifted back out of one joined enrolment row. */
function billing_tariff_of(array $row): ?array {
    if ($row['t_id'] === null) return null;
    return ['id' => (int)$row['t_id'], 'name' => $row['tariff_name'], 'price_cents' => (int)$row['tariff_price'],
            'period' => $row['period'], 'interval_months' => (int)$row['interval_months'],
            'due_day' => (int)$row['tariff_due_day'], 'grace_days' => (int)$row['grace_days'],
            'first_period' => $row['first_period'], 'discount_months' => (int)$row['discount_months'],
            'discount_kind' => $row['discount_kind'], 'discount_value' => (int)$row['discount_value']];
}

/**
 * Which day of the month this family pays on.
 *
 * The student's own day wins over the enrolment's, which wins over the tariff's.
 * All three exist because a family paid on the 15th should be able to say so
 * once, rather than on every course they are in.
 */
function billing_due_day(array $enrolment, array $tariff): int {
    return (int)($enrolment['billing_due_day'] ?: $enrolment['enrolment_due_day'] ?: $tariff['due_day'] ?: 1);
}

/**
 * Why each enrolment is or is not billed for a month.
 *
 * One row per enrolment, including the ones that are skipped and the reason.
 * Silence about a child who should have been charged is the failure mode this is
 * designed against: a preview that only lists what it will do cannot show you
 * what it has quietly stopped doing.
 */
function billing_plan(string $period): array {
    $period = billing_valid_period($period);
    $monthStart = billing_period_start($period);
    $monthEnd = billing_period_end($period);

    // Every charge that already covers a period, fetched once. One query per
    // enrolment here would grow with every child and every course they are in.
    $covered = [];
    foreach (rows('SELECT student_id, class_id, period_from FROM charges WHERE cancelled=0 AND period_from IS NOT NULL') as $r)
        $covered[(int)$r['student_id'] . ':' . (int)$r['class_id'] . ':' . $r['period_from']] = true;

    $rows = [];
    foreach (billing_enrolments() as $enrolment) {
        $name = $enrolment['first_name'] . ' ' . $enrolment['last_name'];
        $entry = ['student_id' => (int)$enrolment['student_id'], 'class_id' => (int)$enrolment['class_id'],
                  'name' => $name, 'class_name' => $enrolment['class_name'], 'period' => $period,
                  'enrolment' => $enrolment, 'amount' => null, 'gross' => null, 'discount' => 0,
                  'from' => null, 'to' => null, 'due' => null, 'overdue' => null, 'note' => '', 'skip' => null];

        $tariff = billing_tariff_of($enrolment);
        if (!$tariff)                              { $entry['skip'] = t('Kein Tarif gewählt', 'No tariff chosen'); $rows[] = $entry; continue; }
        if ($tariff['period'] !== 'recurring')     { $entry['skip'] = t('Einmaliger Tarif', 'One-off tariff'); $rows[] = $entry; continue; }

        $bounds = billing_period_bounds($monthStart, (int)$tariff['interval_months']);
        $entry['from'] = $bounds['from']; $entry['to'] = $bounds['to'];
        $entry['interval'] = (int)$tariff['interval_months'];
        $entry['tariff_id'] = $tariff['id'];
        $entry['tariff_name'] = $tariff['name'];

        $start = billing_start($enrolment);
        $end = $enrolment['left_on'] ?: $enrolment['ended_on'] ?: null;

        // A period is billed in its first month. A child who joins part-way
        // through one is billed in the month they join, because otherwise their
        // first charge would not appear until the period after next.
        $firstMonthOfPeriod = substr($bounds['from'], 0, 7) === $period;
        $joinsThisMonth = $start !== null && $start >= $monthStart && $start <= $monthEnd;

        if (isset($covered[(int)$enrolment['student_id'] . ':' . (int)$enrolment['class_id'] . ':' . $bounds['from']]))
                                                   $entry['skip'] = t('Bereits abgerechnet', 'Already charged');
        elseif ((int)$enrolment['billing_paused'] === 1) $entry['skip'] = t('Beiträge pausiert', 'Billing paused');
        elseif ($start === null)                   $entry['skip'] = t('Kein Beitrittsdatum', 'No joining date');
        elseif ($start > $bounds['to'])            $entry['skip'] = t('Noch nicht dabei', 'Not a member yet');
        elseif ($end !== null && $end < $bounds['from']) $entry['skip'] = t('Nicht mehr dabei', 'No longer a member');
        elseif (!$firstMonthOfPeriod && !$joinsThisMonth) $entry['skip'] = t('Zeitraum beginnt in einem anderen Monat', 'This period starts in another month');

        if ($entry['skip'] !== null) { $rows[] = $entry; continue; }

        $money = billing_amount_for($enrolment, $tariff, $bounds);
        if ($money['skip'] !== null)               { $entry['skip'] = $money['skip']; $rows[] = $entry; continue; }
        if ($money['amount'] <= 0 && $money['gross'] <= 0) { $entry['skip'] = t('Kein Preis hinterlegt', 'No price set'); $rows[] = $entry; continue; }

        $dueDay = billing_due_day($enrolment, $tariff);
        // A charge must never be created already late. The due day belongs to the
        // period, but a child who joins in the second month of a quarter has their
        // first charge written in that month - and anchoring it to the period
        // start meant it was due weeks before it existed, and the automatic
        // reminder went out the same evening.
        $dueFrom = max($bounds['from'], $monthStart);
        $entry['amount'] = $money['amount'];
        $entry['gross'] = $money['gross'];
        $entry['discount'] = $money['discount'];
        $entry['note'] = $money['note'];
        $entry['prorated'] = $money['prorated'] ?? false;
        $entry['due'] = billing_due_date($dueFrom, $dueDay);
        $entry['overdue'] = billing_overdue_date($entry['due'], (int)$tariff['grace_days']);
        $rows[] = $entry;
    }
    return $rows;
}

/**
 * Create the charges for a month.
 *
 * Returns what it did. Safe to call repeatedly: the plan skips a period a charge
 * already covers, and the unique billing_key refuses a duplicate that two
 * simultaneous runs slipped past the plan.
 */
function billing_run(string $period): array {
    $period = billing_valid_period($period);
    $label = (string)setting('billing_label');
    $created = 0; $skipped = 0; $total = 0;
    foreach (billing_plan($period) as $entry) {
        if ($entry['skip'] !== null) { $skipped++; continue; }
        try {
            run('INSERT INTO charges (student_id,class_id,tariff_id,payment_profile_id,label,origin,billing_key,'
                .'amount_cents,gross_cents,discount_cents,discount_note,period_from,period_to,due_on,overdue_on,created_at)'
                .' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$entry['student_id'], $entry['class_id'] ?: null, $entry['tariff_id'] ?? null,
                 $entry['enrolment']['payment_profile_id'] ?: null,
                 billing_charge_label($label, $entry), 'auto',
                 billing_key($entry['from'], $entry['student_id'], (int)$entry['class_id']),
                 $entry['amount'], $entry['gross'], $entry['discount'], $entry['note'],
                 $entry['from'], $entry['to'], $entry['due'], $entry['overdue'], now()]);
            $created++; $total += (int)$entry['amount'];
        } catch (PDOException $e) {
            // 23000 here means the unique billing_key already exists, i.e. a
            // concurrent run got there first. That is the guard working.
            if ($e->getCode() !== '23000') throw $e;
            $skipped++;
        }
    }
    if ($created) audit('billing.generated', 'charge');
    return ['created' => $created, 'skipped' => $skipped, 'period' => $period, 'total_cents' => $total];
}

/**
 * What one automatic charge is called.
 *
 * A month for a monthly tariff, a range for anything longer, because "Beitrag
 * April" on a charge covering April to June is the kind of thing a parent
 * queries and she then has to explain.
 */
function billing_charge_label(string $template, array $entry): string {
    $from = $entry['from']; $to = $entry['to'];
    $name = substr($from, 0, 7) === substr($to, 0, 7)
        ? billing_month_name(substr($from, 0, 7))
        : billing_month_name(substr($from, 0, 7)) . '–' . billing_month_name(substr($to, 0, 7));
    return mb_substr(strtr($template, [
        '{month}'  => $name,
        '{year}'   => substr($from, 0, 4),
        '{course}' => (string)($entry['class_name'] ?? ''),
        '{tariff}' => (string)($entry['tariff_name'] ?? ''),
    ]), 0, 160);
}

/**
 * One tariff in a sentence, the way she would say it out loud.
 *
 * "45,00 € monatlich · fällig am 1. · erster Monat gratis". Built here rather
 * than in the view because the course page, the student's page and the enrolment
 * form all show it, and three descriptions of the same rules is how two of them
 * end up wrong.
 */
function tariff_summary(array $tariff): string {
    if (($tariff['period'] ?? 'recurring') !== 'recurring')
        return money((int)$tariff['price_cents']) . ' · ' . t('einmalig', 'one-off');
    $parts = [money((int)$tariff['price_cents']) . ' ' . billing_interval_label((int)$tariff['interval_months'])];
    $parts[] = t('fällig am ', 'due on the ') . (int)$tariff['due_day'] . t('.', '');
    $span = (int)($tariff['discount_months'] ?? 0);
    $value = (int)($tariff['discount_value'] ?? 0);
    if ($span !== 0 && $value > 0) {
        $how = ($tariff['discount_kind'] ?? 'percent') === 'fixed'
            ? money($value) . t(' weniger', ' off')
            : ($value >= 100 ? t('gratis', 'free') : $value . ' % ' . t('Rabatt', 'off'));
        $parts[] = $span < 0
            ? $how . ' ' . t('dauerhaft', 'permanently')
            : plural($span, 'Monat', 'Monate', 'month', 'months') . ' ' . $how;
    }
    return implode(' · ', $parts);
}

/** Month name in the current interface language, for the charge label. */
function billing_month_name(string $period): string {
    $months = locale() === 'en'
        ? ['January','February','March','April','May','June','July','August','September','October','November','December']
        : ['Jänner','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    return $months[(int)substr($period, 5, 2) - 1] ?? $period;
}
