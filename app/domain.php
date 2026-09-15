<?php
declare(strict_types=1);

function statuses(): array { return setting('statuses',['trial'=>'Probetraining','active'=>'Aktiv','paused'=>'Pausiert','ended'=>'Beendet']); }
function reasons(): array { return setting('absence_reasons',['sick'=>'Krank','holiday'=>'Urlaub','other'=>'Abwesend']); }
function status_label(string $s): string { $en=['trial'=>'Trial','active'=>'Active','paused'=>'Paused','ended'=>'Ended']; return locale()==='en' && isset($en[$s])?$en[$s]:(statuses()[$s]??$s); }
function reason_label(string $s): string { $en=['sick'=>'Sick','holiday'=>'Holiday','other'=>'Absent']; return locale()==='en' && isset($en[$s])?$en[$s]:(reasons()[$s]??$s); }
function student(int $id): array {
    $u=require_user();
    $s=one('SELECT s.*,t.name AS tariff_name FROM students s LEFT JOIN tariffs t ON t.id=s.tariff_id WHERE s.id=?'.(is_staff($u)?'':' AND s.account_id=?'),is_staff($u)?[$id]:[$id,$u['id']]);
    if(!$s) throw new UserError(t('Schüler nicht gefunden.','Student not found.')); return $s;
}
function balance(int $studentId, bool $overdue=false): int {
    $charges=rows('SELECT c.amount_cents,COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.charge_id=c.id AND p.confirmed_at IS NOT NULL AND p.voided=0),0) AS paid FROM charges c WHERE c.student_id=? AND c.cancelled=0'.($overdue?' AND c.due_on<?':''),$overdue?[$studentId,today()]:[$studentId]);
    return array_sum(array_map(fn($c)=>max(0,(int)$c['amount_cents']-(int)$c['paid']),$charges));
}
function student_charges(int $id): array { return rows('SELECT c.*,COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.charge_id=c.id AND p.confirmed_at IS NOT NULL AND p.voided=0),0) AS paid FROM charges c WHERE c.student_id=? ORDER BY c.due_on DESC,c.id DESC',[$id]); }
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
    $keys=['q','status','absence','overdue','tariff','field','value']; $out=[];
    foreach($keys as $key) if(isset($data[$key]) && is_scalar($data[$key])) $out[$key]=mb_substr(trim((string)$data[$key]),0,200);
    return $out;
}
function filtered_students(array $f,?int $accountId=null): array {
    $where=['1=1']; $p=[];
    if($accountId!==null){$where[]='s.account_id=?';$p[]=$accountId;}
    if(!empty($f['q'])){$where[]="CONCAT(s.first_name,' ',s.last_name) LIKE ?";$p[]='%'.$f['q'].'%';}
    if(!empty($f['status'])){$where[]='s.status=?';$p[]=$f['status'];}
    if(!empty($f['tariff'])){$where[]='s.tariff_id=?';$p[]=(int)$f['tariff'];}
    if(!empty($f['absence'])){$where[]='EXISTS (SELECT 1 FROM absences a WHERE a.student_id=s.id AND a.reason=? AND a.starts_on<=? AND a.ends_on>=?)';array_push($p,$f['absence'],today(),today());}
    if(!empty($f['overdue'])){$where[]='EXISTS (SELECT 1 FROM charges c WHERE c.student_id=s.id AND c.cancelled=0 AND c.due_on<? AND c.amount_cents>COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.charge_id=c.id AND p.confirmed_at IS NOT NULL AND p.voided=0),0))';$p[]=today();}
    if(!empty($f['field']) && isset($f['value'])){
        $def=one('SELECT * FROM field_definitions WHERE id=? AND archived=0',[(int)$f['field']]);
        if($def && (is_staff() || $def['visibility']!=='internal')) {
            $where[]='EXISTS (SELECT 1 FROM field_values v WHERE v.student_id=s.id AND v.field_id=? AND JSON_CONTAINS(v.value_json,?))';
            array_push($p,$def['id'],json_encode($def['field_type']==='checkbox'?in_array($f['value'],['1','true','yes'],true):$f['value'],JSON_UNESCAPED_UNICODE));
        }
    }
    return rows('SELECT s.*,t.name AS tariff_name,a.name AS account_name FROM students s LEFT JOIN tariffs t ON t.id=s.tariff_id LEFT JOIN accounts a ON a.id=s.account_id WHERE '.implode(' AND ',$where).' ORDER BY s.last_name,s.first_name,s.id',$p);
}
function template_text(string $text,array $s): string {
    $paidThrough=null;
    foreach(student_charges((int)$s['id']) as $c) if(!$c['cancelled'] && $c['paid']>=$c['amount_cents'] && $c['period_to'] && (!$paidThrough || $c['period_to']>$paidThrough)) $paidThrough=$c['period_to'];
    return strtr($text,['{{student_name}}'=>$s['first_name'].' '.$s['last_name'],'{{first_name}}'=>$s['first_name'],'{{tariff}}'=>$s['tariff_name']??'','{{outstanding}}'=>money(balance((int)$s['id'])),'{{paid_through}}'=>fmt_date($paidThrough),'{{portal_url}}'=>url('messages')]);
}
