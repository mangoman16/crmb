<?php
declare(strict_types=1);

function statuses(): array { return setting('statuses',['trial'=>'Probetraining','active'=>'Aktiv','paused'=>'Pausiert','ended'=>'Beendet']); }
function reasons(): array { return setting('absence_reasons',['sick'=>'Krank','holiday'=>'Urlaub','other'=>'Abwesend']); }
function status_label(string $s): string { $en=['trial'=>'Trial','active'=>'Active','paused'=>'Paused','ended'=>'Ended']; return locale()==='en' && isset($en[$s])?$en[$s]:(statuses()[$s]??$s); }
function reason_label(string $s): string { $en=['sick'=>'Sick','holiday'=>'Holiday','other'=>'Absent']; return locale()==='en' && isset($en[$s])?$en[$s]:(reasons()[$s]??$s); }
function student(int $id): array {
    $u=require_user();
    $s=one('SELECT s.*,t.name AS tariff_name,l.name AS level_name FROM students s LEFT JOIN tariffs t ON t.id=s.tariff_id LEFT JOIN levels l ON l.id=s.level_id WHERE s.id=?'.(is_staff($u)?'':' AND s.account_id=?'),is_staff($u)?[$id]:[$id,$u['id']]);
    if(!$s) throw new UserError(t('Schüler nicht gefunden.','Student not found.')); return $s;
}
/**
 * A conversation the signed-in account is allowed to see.
 *
 * Staff see every thread; anyone else only their own. Same shape as student():
 * the scoping is part of the lookup, so no caller can forget it.
 */
function thread_record(int $id): array {
    $u=require_user();
    $r=one('SELECT t.*,a.name AS account_name FROM threads t JOIN accounts a ON a.id=t.account_id WHERE t.id=?'.(is_staff($u)?'':' AND t.account_id=?'),is_staff($u)?[$id]:[$id,$u['id']]);
    if(!$r)throw new UserError(t('Unterhaltung nicht gefunden.','Conversation not found.'));return $r;
}
/**
 * What counts as money actually received.
 *
 * A payment counts once it has been confirmed and has not been voided. This one
 * rule decides every balance, the overdue filter and the payments screen, so it
 * is written here and nowhere else — a second copy is the one that gets
 * forgotten when the rule changes, and the two then disagree about what a family
 * owes. The `structure` suite fails if the condition reappears spelled out.
 */
function payment_counts_sql(string $payment='p'): string {
    return sql_name($payment,'alias').'.confirmed_at IS NOT NULL AND '.sql_name($payment,'alias').'.voided=0';
}

/**
 * SQL for the amount confirmed against one charge, as a correlated subquery.
 *
 * $charge is how the charges table is aliased in the surrounding query.
 */
function charge_paid_sql(string $charge='c'): string {
    return 'COALESCE((SELECT SUM(p.amount_cents) FROM payments p'
        .' WHERE p.charge_id='.sql_name($charge,'alias').'.id AND '.payment_counts_sql().'),0)';
}

function balance(int $studentId, bool $overdue=false): int {
    $charges=rows('SELECT c.amount_cents,'.charge_paid_sql().' AS paid FROM charges c WHERE c.student_id=? AND c.cancelled=0'.($overdue?' AND c.due_on<?':''),$overdue?[$studentId,today()]:[$studentId]);
    return array_sum(array_map(fn($c)=>max(0,(int)$c['amount_cents']-(int)$c['paid']),$charges));
}
/**
 * Outstanding amounts for many students at once, as id => cents.
 *
 * The list views show a balance on every card. Calling balance() per card is one
 * query per student, which is invisible with five children and slow with sixty,
 * so anything rendering a list resolves them together.
 */
function balances(bool $overdue=false): array {
    $out=[];
    foreach(rows('SELECT c.student_id, SUM(GREATEST(0, c.amount_cents - COALESCE(p.paid,0))) AS due'
        .' FROM charges c LEFT JOIN (SELECT charge_id, SUM(amount_cents) AS paid FROM payments'
        .'   WHERE '.payment_counts_sql('payments').' GROUP BY charge_id) p ON p.charge_id=c.id'
        .' WHERE c.cancelled=0'.($overdue?' AND c.due_on<?':'').' GROUP BY c.student_id',
        $overdue?[today()]:[]) as $r) $out[(int)$r['student_id']]=(int)$r['due'];
    return $out;
}

/**
 * The payments recorded against each of several charges, as charge_id => rows.
 *
 * The student payments tab lists every charge with its payments underneath.
 * Fetching them per charge is one query per month of membership, which grows for
 * as long as she uses the application, so they are fetched together.
 */
function payments_by_charge(array $chargeIds): array {
    $ids = array_values(array_unique(array_map('intval', $chargeIds)));
    if (!$ids) return [];
    $out = array_fill_keys($ids, []);
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (rows('SELECT p.*,a.name AS confirmer FROM payments p LEFT JOIN accounts a ON a.id=p.confirmed_by'
        .' WHERE p.charge_id IN ('.$in.') ORDER BY p.paid_on DESC, p.id DESC', $ids) as $row)
        $out[(int)$row['charge_id']][] = $row;
    return $out;
}

function student_charges(int $id): array { return rows('SELECT c.*,'.charge_paid_sql().' AS paid FROM charges c WHERE c.student_id=? ORDER BY c.due_on DESC,c.id DESC',[$id]); }
function field_definitions(bool $archived=false): array { return rows('SELECT * FROM field_definitions'.($archived?'':' WHERE archived=0').' ORDER BY sort_order,id'); }
function field_label(array $f): string { return locale()==='en' && $f['label_en']?$f['label_en']:$f['label']; }
function field_value(int $studentId,int $fieldId): mixed { $v=scalar('SELECT value_json FROM field_values WHERE student_id=? AND field_id=?',[$studentId,$fieldId]); return $v===false?null:json_decode($v,true); }
function validate_custom(array $f,mixed $v,mixed $old=null): mixed {
    $opts=json_decode($f['options_json'],true);
    if($f['field_type']==='multiselect') {
        if(!is_array($v)) $v=[];
        if(count($v)>100) throw new UserError(t('Zu viele Optionen.','Too many options.'));
        foreach($v as $x) if(!is_string($x) || (!in_array($x,$opts,true) && !in_array($x,is_array($old)?$old:[],true))) throw new UserError(t('Ungültige Option.','Invalid option.'));
        $v=array_values(array_unique($v));
    } elseif($f['field_type']==='checkbox') { $v=(bool)$v;
    } else {
        if(!is_scalar($v) && $v!==null) throw new UserError(t('Ungültiger Feldwert.','Invalid field value.'));
        $v=trim((string)$v);
        if(mb_strlen($v)>4000) throw new UserError(t('Feldwert zu lang.','Field value is too long.'));
        if($v!=='') {
            if($f['field_type']==='date') date_value($v,true);
            if($f['field_type']==='number' && !preg_match('/^-?\d+(?:[.,]\d+)?$/D',$v)) throw new UserError(t('Zahl erwartet: ','Number expected: ').$f['label']);
            if($f['field_type']==='select' && !in_array($v,$opts,true) && $v!==$old) throw new UserError(t('Ungültige Option: ','Invalid option: ').$f['label']);
        }
    }
    if($f['required'] && ($v==='' || $v===false || $v===[] || $v===null)) throw new UserError(t('Pflichtfeld: ','Required field: ').$f['label']);
    return $v;
}
function save_custom_fields(int $id,bool $new): void {
    $input=$_POST['custom']??[]; if(!is_array($input)) throw new UserError('Invalid fields');
    foreach(field_definitions() as $f) {
        if(!is_staff() && $f['visibility']!=='edit') continue;
        $old=field_value($id,(int)$f['id']);
        $value=$input[$f['id']]??($f['field_type']==='checkbox'?false:($f['field_type']==='multiselect'?[]:''));
        $value=validate_custom($f,$value,$old);
        run('INSERT INTO field_values (student_id,field_id,value_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)',[$id,$f['id'],json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
}
function filters_from(array $data): array {
    $keys=['q','status','absence','overdue','tariff','level','age_group','field','value']; $out=[];
    foreach($keys as $key) if(isset($data[$key]) && is_scalar($data[$key])) $out[$key]=mb_substr(trim((string)$data[$key]),0,200);
    return $out;
}
function filtered_students(array $f,?int $accountId=null): array {
    $where=['1=1']; $p=[];
    if($accountId!==null){$where[]='s.account_id=?';$p[]=$accountId;}
    if(!empty($f['q'])){$where[]="CONCAT(s.first_name,' ',s.last_name) LIKE ?";$p[]='%'.$f['q'].'%';}
    if(!empty($f['status'])){$where[]='s.status=?';$p[]=$f['status'];}
    if(!empty($f['tariff'])){$where[]='s.tariff_id=?';$p[]=(int)$f['tariff'];}
    if(!empty($f['level'])){$where[]='s.level_id=?';$p[]=(int)$f['level'];}
    // An age group is usually not stored on the student, so filtering by one has
    // to cover both the pinned case and the dates that fall into the band. The
    // bounds become dates once here rather than a function call per row.
    if(!empty($f['age_group'])){
        $band=one('SELECT * FROM age_groups WHERE id=?',[(int)$f['age_group']]);
        if($band){
            $youngest=(new DateTimeImmutable(today()))->modify('-'.((int)$band['min_age']+1).' years')->modify('+1 day')->format('Y-m-d');
            $oldest=$band['max_age']===null?null:(new DateTimeImmutable(today()))->modify('-'.((int)$band['max_age']+1).' years')->modify('+1 day')->format('Y-m-d');
            $clause='s.age_group_id=? OR (s.age_group_id IS NULL AND s.birth_date IS NOT NULL AND s.birth_date<=?';
            array_push($p,(int)$band['id'],$youngest);
            if($oldest!==null){$clause.=' AND s.birth_date>?';$p[]=$oldest;}
            $where[]='('.$clause.'))';
        }
    }
    if(!empty($f['absence'])){$where[]='EXISTS (SELECT 1 FROM absences a WHERE a.student_id=s.id AND a.reason=? AND a.starts_on<=? AND a.ends_on>=?)';array_push($p,$f['absence'],today(),today());}
    if(!empty($f['overdue'])){$where[]='EXISTS (SELECT 1 FROM charges c WHERE c.student_id=s.id AND c.cancelled=0 AND c.due_on<? AND c.amount_cents>'.charge_paid_sql().')';$p[]=today();}
    if(!empty($f['field']) && isset($f['value'])){
        $def=one('SELECT * FROM field_definitions WHERE id=? AND archived=0',[(int)$f['field']]);
        if($def && (is_staff() || $def['visibility']!=='internal')) {
            $where[]='EXISTS (SELECT 1 FROM field_values v WHERE v.student_id=s.id AND v.field_id=? AND JSON_CONTAINS(v.value_json,?))';
            array_push($p,$def['id'],json_encode($def['field_type']==='checkbox'?in_array($f['value'],['1','true','yes'],true):$f['value'],JSON_UNESCAPED_UNICODE));
        }
    }
    return rows('SELECT s.*,t.name AS tariff_name,a.name AS account_name,l.name AS level_name FROM students s'
        .' LEFT JOIN tariffs t ON t.id=s.tariff_id LEFT JOIN accounts a ON a.id=s.account_id LEFT JOIN levels l ON l.id=s.level_id'
        .' WHERE '.implode(' AND ',$where).' ORDER BY s.last_name,s.first_name,s.id',$p);
}
/**
 * Every placeholder a message template may use, what it means, and an example.
 *
 * One list. template_values() fills them in, template_save() validates against
 * them, and the editor shows them beside the box they go into. The same set used
 * to be written out in three places, so adding one meant remembering all three
 * and a template could be accepted that the sender then could not fill in.
 */
function template_placeholders(): array {
    return [
        'student_name' => [t('Vollständiger Name','Full name'),                          'Lena Hofer'],
        'first_name'   => [t('Vorname','First name'),                                    'Lena'],
        'level'        => [t('Leistungsgruppe','Level'),                                 t('Anfänger','Beginner')],
        'age_group'    => [t('Altersgruppe','Age group'),                                t('Unter 12','Under 12')],
        'tariff'       => [t('Tarif','Tariff'),                                          t('Monatsbeitrag','Monthly fee')],
        'outstanding'  => [t('Offener Gesamtbetrag','Total outstanding'),                money(4500)],
        'paid_through' => [t('Ende des letzten bezahlten Zeitraums','End of the latest paid period'), fmt_date(today())],
        'portal_url'   => [t('Link zum Portal','Link to the portal'),                    url('messages')],
    ];
}

/** What each placeholder becomes for one student. Keys match template_placeholders(). */
function template_values(array $s): array {
    $paidThrough=null;
    foreach(student_charges((int)$s['id']) as $c) if(!$c['cancelled'] && $c['paid']>=$c['amount_cents'] && $c['period_to'] && (!$paidThrough || $c['period_to']>$paidThrough)) $paidThrough=$c['period_to'];
    return [
        'student_name' => $s['first_name'].' '.$s['last_name'],
        'first_name'   => $s['first_name'],
        'level'        => level_name(isset($s['level_id'])?(int)$s['level_id']:null),
        'age_group'    => age_group_name($s),
        'tariff'       => $s['tariff_name']??'',
        'outstanding'  => money(balance((int)$s['id'])),
        'paid_through' => fmt_date($paidThrough),
        'portal_url'   => url('messages'),
    ];
}

function template_text(string $text,array $s): string {
    $map=[];
    foreach(template_values($s) as $key=>$value) $map['{{'.$key.'}}']=$value;
    return strtr($text,$map);
}
