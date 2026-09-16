<?php
declare(strict_types=1);

/**
 * Monthly charges.
 *
 * The rule, as the operator stated it: a charge appears on the first of each
 * month, and the first time the calendar reaches a first after a student joins,
 * that month is free. Being away does not change anything - absence and billing
 * are deliberately unlinked. The trainer can pause billing for one student.
 *
 * Nothing here is time-based magic: a period is generated only when somebody
 * runs it, either from the payments screen or from cron. Running it twice for
 * the same month is safe, because every generated charge carries a billing_key
 * that is unique in the database.
 */

/** The month a period string refers to, as its first day. 2026-11 -> 2026-11-01. */
function billing_period_start(string $period): string { return $period.'-01'; }

/** Last day of that month, which is the coverage end. */
function billing_period_end(string $period): string {
    return (new DateTimeImmutable($period.'-01'))->modify('last day of this month')->format('Y-m-d');
}

/** The current month as a period string. */
function billing_current_period(): string { return date('Y-m'); }

function billing_valid_period(string $period): string {
    if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$period)) throw new UserError(t('Bitte einen Monat im Format JJJJ-MM angeben.','Please give a month as YYYY-MM.'));
    return $period;
}

/**
 * The month a student is not charged for.
 *
 * "The first time the calendar hits the first" after joining. A student who
 * joined on the 1st gets that same month free; anyone who joined mid-month gets
 * the following month free, because that is the first first they experience.
 *
 * Derived rather than stored, so correcting a join date corrects the free month
 * with it instead of leaving a stale flag behind.
 */
function billing_free_period(array $student): ?string {
    $joined=$student['joined_on'] ?: substr((string)$student['created_at'],0,10);
    if(!$joined) return null;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$joined);
    if(!$d) return null;
    return $d->format('d')==='01' ? $d->format('Y-m') : $d->modify('first day of next month')->format('Y-m');
}

/** The first month that is actually charged. */
function billing_first_charged_period(array $student): ?string {
    $free=billing_free_period($student);
    if($free===null) return null;
    return (new DateTimeImmutable($free.'-01'))->modify('first day of next month')->format('Y-m');
}

/**
 * The monthly amount for one student, in cents, or null when they have none.
 *
 * The student's agreed price wins over the tariff's, which is what the two
 * fields already mean everywhere else in the application.
 */
function billing_amount(array $student, ?array $tariffs=null): ?int {
    if($student['price_cents']!==null) return (int)$student['price_cents'];
    if($student['tariff_id']) {
        $id=(int)$student['tariff_id'];
        // $tariffs lets a caller working through many students pass a map it has
        // already loaded, instead of one lookup per student.
        $t=$tariffs!==null ? ($tariffs[$id]??null) : one('SELECT price_cents, period FROM tariffs WHERE id=?',[$id]);
        // Only a recurring tariff should produce a monthly charge; "once" and
        // fixed-period tariffs are billed by hand on purpose.
        if($t && $t['period']==='monthly') return (int)$t['price_cents'];
    }
    return null;
}

/**
 * Why a student is or is not billed for a period.
 *
 * Returns a row for every active student so the preview can show the skipped
 * ones with their reason. Silence about a student who should have been charged
 * is the failure mode worth designing against here.
 */
function billing_plan(string $period): array {
    $period=billing_valid_period($period);
    $start=billing_period_start($period);
    $end=billing_period_end($period);
    $dueDays=(int)setting('billing_due_days');
    $due=(new DateTimeImmutable($start))->modify('+'.$dueDays.' days')->format('Y-m-d');

    // Everything this needs is fetched up front. The preview is rendered on
    // every visit to the payments screen, so a query per student would make the
    // page slower with every child added.
    $already=[];
    foreach(rows("SELECT billing_key FROM charges WHERE billing_key LIKE ?",['auto:'.$period.':%']) as $r)
        $already[$r['billing_key']]=true;
    $tariffs=[];
    foreach(rows('SELECT id, price_cents, period FROM tariffs') as $r) $tariffs[(int)$r['id']]=$r;

    $rows=[];
    foreach(rows('SELECT s.*, t.name AS tariff_name FROM students s LEFT JOIN tariffs t ON t.id=s.tariff_id ORDER BY s.last_name, s.first_name, s.id') as $s) {
        $name=$s['first_name'].' '.$s['last_name'];
        $existing=isset($already[billing_key($period,(int)$s['id'])]);
        $amount=billing_amount($s,$tariffs);
        $free=billing_free_period($s);
        $joined=$s['joined_on'] ?: substr((string)$s['created_at'],0,10);
        $entry=['student_id'=>(int)$s['id'],'name'=>$name,'amount'=>$amount,'period'=>$period,
                'from'=>$start,'to'=>$end,'due'=>$due,'charge'=>$s,'skip'=>null];

        if($existing)                          $entry['skip']=t('Bereits erzeugt','Already created');
        elseif((int)$s['billing_paused']===1)  $entry['skip']=t('Beiträge pausiert','Billing paused');
        elseif(!$joined || $joined>$end)       $entry['skip']=t('Noch nicht dabei','Not a member yet');
        elseif($s['ended_on'] && $s['ended_on']<$start) $entry['skip']=t('Mitgliedschaft beendet','Membership ended');
        // The month somebody joins mid-way through is not charged either, but for a
        // different reason than the free month, and saying so avoids the operator
        // thinking the free month was used up early.
        elseif($free!==null && $period<$free)  $entry['skip']=t('Beitrittsmonat','Month they joined');
        elseif($free!==null && $period===$free) $entry['skip']=t('Erster Monat frei','First month free');
        elseif($amount===null || $amount<=0)   $entry['skip']=t('Kein monatlicher Preis hinterlegt','No monthly price set');
        $rows[]=$entry;
    }
    return $rows;
}

/** The database-unique key that makes a repeated run harmless. */
function billing_key(string $period,int $studentId): string { return 'auto:'.$period.':s'.$studentId; }

/**
 * Create the charges for a period.
 *
 * Returns [created, skipped]. Safe to call repeatedly: the unique billing_key
 * means a row that already exists is skipped by the plan, and a race that
 * slipped past the plan is refused by the database rather than duplicated.
 */
function billing_run(string $period): array {
    $period=billing_valid_period($period);
    $label=setting('billing_label');
    $created=0; $skipped=0;
    // The first class a student is in decides which account the money goes to;
    // without one the charge falls back to the default profile. Loaded once.
    $classOf=[];
    foreach(rows('SELECT student_id, MIN(class_id) AS class_id FROM class_students WHERE left_on IS NULL GROUP BY student_id') as $r)
        $classOf[(int)$r['student_id']]=(int)$r['class_id'];
    foreach(billing_plan($period) as $entry) {
        if($entry['skip']!==null) { $skipped++; continue; }
        $s=$entry['charge'];
        $classId=$classOf[$entry['student_id']]??null;
        try {
            run('INSERT INTO charges (student_id,class_id,payment_profile_id,label,origin,billing_key,amount_cents,period_from,period_to,due_on,created_at)'
                .' VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$entry['student_id'],$classId?:null,null,
                 strtr($label,['{month}'=>billing_month_name($period),'{year}'=>substr($period,0,4)]),
                 'auto',billing_key($period,$entry['student_id']),$entry['amount'],
                 $entry['from'],$entry['to'],$entry['due'],now()]);
            $created++;
        } catch(PDOException $e) {
            // 23000 here means the unique billing_key already exists, i.e. a
            // concurrent run got there first. That is the guard working.
            if($e->getCode()!=='23000') throw $e;
            $skipped++;
        }
    }
    if($created) audit('billing.generated','charge');
    return ['created'=>$created,'skipped'=>$skipped,'period'=>$period];
}

/** Month name in the current interface language, for the charge label. */
function billing_month_name(string $period): string {
    $months=locale()==='en'
        ? ['January','February','March','April','May','June','July','August','September','October','November','December']
        : ['Jänner','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    return $months[(int)substr($period,5,2)-1] ?? $period;
}
