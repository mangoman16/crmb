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

/**
 * The same intervals, as short as they go, for a box in a narrow column.
 *
 * "alle 3 Monate" reads well in a sentence and is 97px wide; the price list on
 * a 320px screen leaves a box about 80px of text. Two names for the same thing
 * is a cost, but a name cut off mid-word is worse: "alle 3 Mona".
 */
function billing_interval_choices(): array {
    $out = [];
    foreach (array_keys(billing_intervals()) as $months)
        $out[$months] = plural($months, 'Monat', 'Monate', 'month', 'months');
    return $out;
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

    [$discount, $note] = billing_discount($enrolment, $period, $start, $gross, $months);
    return ['gross' => $gross, 'discount' => $discount, 'amount' => max(0, $gross - $discount),
            'note' => $note, 'skip' => null,
            'prorated' => $partial,
            'covered_from' => $billFrom, 'covered_to' => $billTo];
}

/**
 * The discount this family was given: how much comes off this period, and how
 * to say so.
 *
 * It lives on the enrolment, not on the tariff. A discount on the tariff was a
 * property of the price list rather than of an agreement with one family, so
 * giving one child three months at half price meant inventing a tariff nobody
 * else could be put on. The tariff still carries the shapes she gives - those
 * are templates, and tariff_discount_templates() has them.
 *
 * It is expressed in months because that is how she says it - "the first month
 * free", "30 % for half a year" - and a period may be longer than the discount.
 * So the months of this period that fall inside the window are counted, and only
 * their share of the price comes off. With a monthly tariff and one gifted month
 * that is the whole charge; with a quarterly tariff it is a third of it.
 */
function billing_discount(array $enrolment, array $period, ?string $start, int $gross, int $months): array {
    $span = (int)($enrolment['discount_months'] ?? 0);
    $value = (int)($enrolment['discount_value'] ?? 0);
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
    $discount = ($enrolment['discount_kind'] ?? 'percent') === 'fixed'
        ? min($share, $value * $covered)
        : (int)round($share * min(100, $value) / 100);
    if ($discount <= 0) return [0, ''];

    $how = ($enrolment['discount_kind'] ?? 'percent') === 'fixed'
        ? money($value) . t(' je Monat', ' per month')
        : min(100, $value) . ' %';
    // Her own name for it when she gave it one - "Geschwisterrabatt" on the
    // invoice says more to a parent than "Willkommensrabatt" does.
    $given = trim((string)($enrolment['discount_note'] ?? ''));
    $label = $given !== '' ? $given . ': ' : ($span < 0 ? t('Dauerhafter Nachlass: ', 'Standing discount: ') : t('Rabatt: ', 'Discount: '));
    $note = $span < 0
        ? $label . $how
        : $label . $how . ' · '
          . plural($covered, 'Monat in diesem Zeitraum', 'Monate in diesem Zeitraum', 'month in this period', 'months in this period');
    return [$discount, mb_substr($note, 0, 160)];
}

/** The database-unique key that makes a repeated run harmless. */
function billing_key(string $periodFrom, int $studentId, int $classId): string {
    return 'auto:' . $periodFrom . ':s' . $studentId . ':c' . $classId;
}

/**
 * Every rate every tariff offers, as tariff id => interval months => cents.
 *
 * One query for the whole run. The billing plan asks for the price of every
 * enrolment in the portal, and a query per enrolment grows with every child and
 * every course they are in.
 */
function tariff_rate_map(?array $tariffIds = null): array {
    $where = ''; $args = [];
    if ($tariffIds !== null) {
        $ids = array_values(array_unique(array_map('intval', $tariffIds)));
        if (!$ids) return [];
        $where = ' WHERE tariff_id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
        $args = $ids;
    }
    $out = [];
    foreach (rows('SELECT tariff_id, interval_months, price_cents FROM tariff_rates'.$where.' ORDER BY interval_months', $args) as $r)
        $out[(int)$r['tariff_id']][(int)$r['interval_months']] = (int)$r['price_cents'];
    return $out;
}

/** The ways one tariff may be paid, cheapest interval first. */
function tariff_rates(int $tariffId): array { return tariff_rate_map([$tariffId])[$tariffId] ?? []; }

/**
 * What one tariff costs at its usual interval, or null when it has no price.
 *
 * The one answer to "what does this tariff cost?" for every screen that shows a
 * single number - the student's agreed-price default, the payment form, a list.
 */
function tariff_price(?int $tariffId): ?int {
    if (!$tariffId) return null;
    $tariff = one('SELECT interval_months FROM tariffs WHERE id=?', [$tariffId]);
    if (!$tariff) return null;
    $rates = tariff_rates($tariffId);
    if (!$rates) return null;
    return $rates[(int)$tariff['interval_months']] ?? (int)reset($rates);
}

/**
 * The interval one enrolment is billed on, and what that costs.
 *
 * The enrolment names an interval; 0 means "whatever this tariff's normal one
 * is", which is what an enrolment made before the tariff offered a choice says.
 * An interval that has since been taken off the price list falls back to the
 * tariff's own, because tidying up a price list must never silently stop a child
 * being billed - it would show up as a missing charge months later.
 *
 * Reads $row['enrolment_interval'] and $row['tariff_interval'], and writes back
 * 'interval_months' and 'tariff_price'.
 */
function with_tariff_rate(array $row, array $rateMap): array {
    $offered = $rateMap[(int)($row['tariff_id'] ?? 0)] ?? [];
    $normal = (int)($row['tariff_interval'] ?? 0);
    $chosen = (int)($row['enrolment_interval'] ?? 0);
    $months = $chosen > 0 && isset($offered[$chosen]) ? $chosen : $normal;
    $row['interval_months'] = $months;
    $row['tariff_price'] = $offered[$months] ?? ($offered[$normal] ?? null);
    // Said out loud, because "billed quarterly at the yearly price" is the kind
    // of wrong that only shows up on somebody's bank statement.
    $row['interval_missing'] = $chosen > 0 && !isset($offered[$chosen]);
    return $row;
}

/** Enrolments that automatic billing looks at, with their tariff and student. */
function billing_enrolments(): array {
    $rows = rows('SELECT cs.class_id, cs.student_id, cs.joined_on, cs.left_on, cs.tariff_id,'
        .' cs.price_cents, cs.price_note, cs.due_day AS enrolment_due_day,'
        .' cs.interval_months AS enrolment_interval,'
        .' cs.discount_months, cs.discount_kind, cs.discount_value, cs.discount_note,'
        .' s.first_name, s.last_name, s.status, s.billing_paused, s.billing_due_day, s.ended_on,'
        .' s.joined_on AS student_joined_on, c.name AS class_name, c.payment_profile_id,'
        .' t.id AS t_id, t.name AS tariff_name, t.period, t.interval_months AS tariff_interval,'
        .' t.due_day AS tariff_due_day, t.grace_days, t.first_period'
        .' FROM class_students cs'
        .' JOIN students s ON s.id=cs.student_id'
        .' JOIN classes c ON c.id=cs.class_id'
        .' LEFT JOIN tariffs t ON t.id=cs.tariff_id'
        .' ORDER BY s.last_name, s.first_name, s.id, c.sort_order, c.name');
    $rates = tariff_rate_map();
    return array_map(fn($row) => with_tariff_rate($row, $rates), $rows);
}

/** The tariff columns, lifted back out of one joined enrolment row. */
function billing_tariff_of(array $row): ?array {
    if ($row['t_id'] === null) return null;
    return ['id' => (int)$row['t_id'], 'name' => $row['tariff_name'],
            'price_cents' => (int)($row['tariff_price'] ?? 0),
            'period' => $row['period'], 'interval_months' => (int)$row['interval_months'],
            'due_day' => (int)$row['tariff_due_day'], 'grace_days' => (int)$row['grace_days'],
            'first_period' => $row['first_period']];
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
function tariff_summary(array $tariff, ?array $rates = null): string {
    $rates ??= tariff_rates((int)$tariff['id']);
    $normal = (int)($tariff['interval_months'] ?? 1);
    if (($tariff['period'] ?? 'recurring') !== 'recurring')
        return money((int)($rates[$normal] ?? reset($rates) ?: 0)) . ' · ' . t('einmalig', 'one-off');
    if (!$rates) return t('Noch kein Preis hinterlegt', 'No price set yet');
    // The normal interval first and then the rest, because "37,00 € monatlich"
    // is the answer to "what does it cost?" and the other three are the answer
    // to "and if we pay for the year?".
    $order = array_keys($rates);
    usort($order, fn($a, $b) => [$a !== $normal, $a] <=> [$b !== $normal, $b]);
    $parts = [];
    foreach ($order as $months) $parts[] = money($rates[$months]) . ' ' . billing_interval_label($months);
    $parts[] = t('fällig am ', 'due on the ') . (int)$tariff['due_day'] . t('.', '');
    return implode(' · ', $parts);
}

/**
 * The shapes of discount this tariff offers, as something to start from.
 *
 * "Dauerhaft -20 %", "erster Monat frei", "die ersten 3 Monate -50 %": the ones
 * she actually gives, written down once so that giving one to a family is a
 * choice from a list rather than three numbers typed from memory. What a family
 * was actually given is on their enrolment; this is only the starting point, and
 * changing a template never changes an agreement already made.
 */
function tariff_discount_templates(int $tariffId): array {
    return rows('SELECT * FROM tariff_discounts WHERE tariff_id=? ORDER BY sort_order, id', [$tariffId]);
}

/**
 * What one child pays for one course, in a sentence.
 *
 * The tariff's summary answers "what does this cost?"; this answers "what does
 * this family pay?", which is a different question once the interval and the
 * discount are theirs rather than the price list's.
 */
function enrolment_summary(array $enrolment): string {
    $price = enrolment_price($enrolment);
    if ($price['cents'] === null) return t('Kein Preis hinterlegt', 'No price set');
    $parts = [money((int)$price['cents']) . ' ' . billing_interval_label((int)$enrolment['interval_months'])];
    if ($price['own']) $parts[] = $price['note'] !== '' ? $price['note'] : t('vereinbarter Preis', 'agreed price');
    $months = (int)($enrolment['discount_months'] ?? 0);
    $value = (int)($enrolment['discount_value'] ?? 0);
    if ($months !== 0 && $value > 0) {
        $given = trim((string)($enrolment['discount_note'] ?? ''));
        $parts[] = ($given !== '' ? $given . ': ' : '')
            . discount_summary($months, (string)($enrolment['discount_kind'] ?? 'percent'), $value);
    }
    $due = (int)($enrolment['due_day'] ?: $enrolment['tariff_due_day'] ?: 0);
    if ($due) $parts[] = t('fällig am ', 'due on the ') . $due . t('.', '');
    return implode(' · ', $parts);
}

/**
 * How long a discount runs, as something to choose rather than type.
 *
 * -1 is "for as long as they stay", which is the only way to say a permanently
 * reduced rate. It is offered as its own choice because nobody should have to
 * work out that minus one month means forever.
 */
function discount_spans(): array {
    $out = [0 => t('kein Rabatt', 'no discount'), -1 => t('dauerhaft', 'permanently')];
    foreach ([1, 2, 3, 6, 12, 24] as $months)
        $out[$months] = plural($months, 'Monat', 'Monate', 'month', 'months');
    return $out;
}

/** "Dauerhaft 20 % Rabatt", "3 Monate 50 % Rabatt", "1 Monat gratis". */
function discount_summary(int $months, string $kind, int $value): string {
    if ($months === 0 || $value <= 0) return t('Kein Rabatt', 'No discount');
    $how = $kind === 'fixed'
        ? money($value) . t(' weniger je Monat', ' off per month')
        : ($value >= 100 ? t('gratis', 'free') : $value . ' % ' . t('Rabatt', 'off'));
    return $months < 0
        ? $how . ', ' . t('dauerhaft', 'permanently')
        : plural($months, 'Monat', 'Monate', 'month', 'months') . ' ' . $how;
}

/** Month name in the current interface language, for the charge label. */
function billing_month_name(string $period): string {
    $months = locale() === 'en'
        ? ['January','February','March','April','May','June','July','August','September','October','November','December']
        : ['Jänner','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    return $months[(int)substr($period, 5, 2) - 1] ?? $period;
}
