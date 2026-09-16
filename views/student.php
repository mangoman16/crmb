<?php
$id=(int)($_GET['id']??0);$staff=is_staff($user);if(!$id)require_staff();
$defaultTariff=setting('default_tariff',null);
$s=$id?student($id):['id'=>0,'first_name'=>'','last_name'=>'','birth_date'=>'','joined_on'=>today(),'ended_on'=>'','status'=>setting('default_status','active'),'account_id'=>null,'tariff_id'=>$defaultTariff,'price_cents'=>null,'price_note'=>'','internal_notes'=>''];
$tab=(string)($_GET['tab']??'details');
$tabsAllowed=$staff?['details','contacts','payments','absence','skills','classes','attendance']:['details','contacts','payments','absence'];
if(!in_array($tab,$tabsAllowed,true))$tab='details';
page_head($id?$s['first_name'].' '.$s['last_name']:t('Neuen Schüler anlegen','Add a student'),$id?status_label($s['status']):'',link_button(t('Alle Schüler','All students'),'students',[],'secondary'));
if($id){
    $tabLabels=['details'=>t('Profil','Profile'),'contacts'=>t('Kontakte','Contacts'),'payments'=>t('Beiträge','Payments'),'absence'=>t('Abwesenheit','Absences')];
    if($staff){$tabLabels['skills']=t('Leistung','Performance');$tabLabels['attendance']=t('Anwesenheit','Attendance');$tabLabels['classes']=t('Kurse','Classes');}
    tabs($tabLabels,$tab,'student',['id'=>$id]);
}
if($tab==='details' || !$id): ?>
<?php start_form('student_save',['id'=>$id,'revision'=>$s['revision']??0],'form'); ?>
<section class="card"><h2><?=e(t('Persönliche Daten','Personal details'))?></h2><div class="grid two"><?php input('first_name',t('Vorname','First name'),$s['first_name'],'text',true);input('last_name',t('Nachname','Last name'),$s['last_name'],'text',true);input('birth_date',t('Geburtsdatum','Date of birth'),$s['birth_date'],'date');if($staff)select_field('account_id',t('Zugeordnetes Konto','Linked account'),array_column(rows("SELECT id,CONCAT(name,' · ',email) AS label FROM accounts WHERE role='student' ORDER BY name"),'label','id'),$s['account_id']);?></div></section>
<?php if($staff):?><section class="card"><h2><?=e(t('Mitgliedschaft und Tarif','Membership and tariff'))?></h2><div class="grid two">
<?php select_field('status',t('Status','Status'),array_combine(array_keys(statuses()),array_map('status_label',array_keys(statuses()))),$s['status'],true);select_field('tariff_id',t('Tarif','Tariff'),array_column(rows('SELECT id,name FROM tariffs WHERE archived=0 OR id=? ORDER BY name',[$s['tariff_id']??0]),'name','id'),$s['tariff_id']);$tariffPrice=$s['tariff_id']?(int)scalar('SELECT price_cents FROM tariffs WHERE id=?',[$s['tariff_id']]):null;
default_field('price',t('Vereinbarter Preis (€)','Agreed price (€)'),amount_input($s['price_cents']===null?null:(int)$s['price_cents']),
    $tariffPrice!==null?amount_input($tariffPrice):'',
    $tariffPrice!==null?money($tariffPrice).' · '.($s['tariff_name']??''):t('kein Tarif gewählt','no tariff chosen'),
    'text', t('Leer lassen, um den Preis des Tarifs zu übernehmen.','Leave blank to follow the tariff’s price.'));input('price_note',t('Preisvereinbarung / Rabatt','Price agreement / discount'),$s['price_note']);input('joined_on',t('Dabei seit','Member since'),$s['joined_on'],'date');input('ended_on',t('Mitgliedschaft bis','Membership until'),$s['ended_on'],'date'); ?>
</div></section><?php elseif($id):?><section class="card"><h2><?=e(t('Mitgliedschaft','Membership'))?></h2><dl class="facts"><div><dt><?=e(t('Tarif','Tariff'))?></dt><dd><?=e($s['tariff_name']?:'–')?></dd></div><div><dt><?=e(t('Vereinbarter Preis','Agreed price'))?></dt><dd><?=$s['price_cents']!==null?e(money((int)$s['price_cents'])):'–'?></dd></div><div><dt><?=e(t('Dabei seit','Member since'))?></dt><dd><?=e(fmt_date($s['joined_on']))?></dd></div><div><dt><?=e(t('Mitgliedschaft bis','Membership until'))?></dt><dd><?=e(fmt_date($s['ended_on']))?></dd></div></dl></section><?php endif ?>
<?php $fields=array_filter(field_definitions(),fn($f)=>$staff || $f['visibility']!=='internal');if($fields): ?><section class="card"><h2><?=e(t('Weitere Angaben','Additional details'))?></h2><div class="grid two">
<?php $lastSection='';foreach($fields as $f):$v=$id?field_value($id,(int)$f['id']):json_decode($f['default_json'],true);$label=field_label($f);$n='custom['.$f['id'].']';
if($f['section_name'] && $f['section_name']!==$lastSection){echo '<h3 class="full">'.e($f['section_name']).'</h3>';$lastSection=$f['section_name'];}
if(!$staff && $f['visibility']==='view'){echo '<div class="field"><label>'.e($label).'</label><div class="readonly">'.e(is_array($v)?implode(', ',$v):(is_bool($v)?($v?t('Ja','Yes'):t('Nein','No')):($v??'–'))).'</div></div>';continue;}
if(in_array($f['field_type'],['select','multiselect'],true)){$opts=json_decode($f['options_json'],true);foreach(is_array($v)?$v:[$v] as $x)if($x!==null&&$x!==''&&!in_array($x,$opts,true))$opts[]=$x;select_field($n,$label,array_combine($opts,$opts),$v,(bool)$f['required'],$f['field_type']==='multiselect');}
elseif($f['field_type']==='checkbox')check_field($n,$label.($f['required']?' *':''),(bool)$v);
else input($n,$label,$v??'',$f['field_type']==='number'?'text':$f['field_type'],(bool)$f['required']);
endforeach ?></div></section><?php endif ?>
<?php if($staff):?><section class="card"><details <?=$s['internal_notes']?'open':''?>><summary><?=e(t('Interne Notizen','Internal notes'))?></summary><?php input('internal_notes',t('Nur für die Verwaltung sichtbar','Visible to management only'),$s['internal_notes'],'textarea');?></details></section><?php endif ?>
<div class="form-footer"><?php submit_button(t('Schüler speichern','Save student'));?></div></form>
<?php if($id && $staff):?><details class="danger-zone"><summary><?=e(t('Schüler löschen','Delete student'))?></summary><p><?=e(t('Nur möglich, wenn keine Beiträge vorhanden sind. Sonst die Mitgliedschaft beenden.','Only possible when no charges exist. Otherwise, end the membership.'))?></p><?php start_form('student_delete',['id'=>$id]);input('confirmation',t('Vollständigen Namen zur Bestätigung eingeben','Enter the full name to confirm'),'','text',true);submit_button(t('Schüler endgültig löschen','Permanently delete student'),'danger');?></form></details><?php endif ?>
<?php elseif($tab==='contacts'): ?>
<section class="card"><h2><?=e(t('Kontaktpersonen','Contact people'))?></h2><?php $contacts=rows('SELECT * FROM contacts WHERE student_id=? ORDER BY id',[$id]);if(!$contacts)echo '<p class="muted">'.e(t('Noch keine Kontakte.','No contacts yet.')).'</p>';foreach($contacts as $c):?><div class="record-row"><div><strong><?=e($c['owner_name'])?></strong><p><?=e($c['relation_label'])?></p><?php if($c['phone']):?><a href="tel:<?=e(preg_replace('/[^0-9+]/','',$c['phone']))?>"><?=e($c['phone'])?></a><?php endif ?><p><?=e($c['email'])?></p></div><?php start_form('contact_delete',['student_id'=>$id,'id'=>$c['id']],'inline-form');submit_button(t('Entfernen','Remove'),'subtle danger-text');?></form></div><details><summary><?=e(t('Kontakt bearbeiten','Edit contact'))?></summary><?php start_form('contact_save',['student_id'=>$id,'id'=>$c['id']]);?><div class="grid two"><?php input('owner_name',t('Name','Name'),$c['owner_name'],'text',true);input('relation_label',t('Beziehung','Relationship'),$c['relation_label'],'text',true);input('phone',t('Telefonnummer','Phone number'),$c['phone'],'tel');input('email',t('E-Mail-Adresse','Email address'),$c['email'],'email');?></div><?php submit_button();?></form></details><?php endforeach ?></section>
<section class="card"><h2><?=e(t('Kontakt hinzufügen','Add contact'))?></h2><?php start_form('contact_add',['student_id'=>$id]);?><div class="grid two"><?php input('owner_name',t('Wem gehört der Kontakt?','Whose contact is this?'),'','text',true);input('relation_label',t('Beziehung, z. B. Mutter','Relationship, e.g. mother'),'','text',true);input('phone',t('Telefonnummer','Phone number'),'','tel');input('email',t('E-Mail-Adresse','Email address'),'','email');?></div><?php submit_button(t('Kontakt hinzufügen','Add contact'));?></form></section>
<?php elseif($tab==='absence'): ?>
<section class="card"><h2><?=e(t('Abwesenheiten','Absences'))?></h2><?php $list=rows('SELECT * FROM absences WHERE student_id=? ORDER BY starts_on DESC',[$id]);if(!$list)echo '<p class="muted">'.e(t('Keine Abwesenheiten eingetragen.','No absences recorded.')).'</p>';foreach($list as $a):?><div class="record-row"><div><strong><?=e(reason_label($a['reason']))?></strong><p><?=e(fmt_date($a['starts_on']).' – '.fmt_date($a['ends_on']))?></p></div><?php start_form('absence_delete',['student_id'=>$id,'id'=>$a['id']],'inline-form');submit_button(t('Entfernen','Remove'),'subtle danger-text');?></form></div><?php endforeach ?></section>
<section class="card"><h2><?=e(t('Abwesenheit melden','Report absence'))?></h2><?php start_form('absence_add',['student_id'=>$id]);?><div class="grid three"><?php select_field('reason',t('Grund','Reason'),array_combine(array_keys(reasons()),array_map('reason_label',array_keys(reasons()))),'',true);input('starts_on',t('Von','From'),today(),'date',true);input('ends_on',t('Bis einschließlich','Up to and including'),today(),'date',true);?></div><?php submit_button(t('Abwesenheit eintragen','Record absence'));?></form></section>
<?php elseif($tab==='skills'): require ROOT.'/views/_student_skills.php'; ?>
<?php elseif($tab==='attendance'): $sum=attendance_summary($id); $rate=attendance_rate($sum); ?>
<?php if($rate!==null): ?>
<div class="stats-grid compact">
    <div class="stat"><span><?=e(t('Anwesend','Attended'))?></span><strong><?=e(number_format($rate,0).'%')?></strong><small><?=e(t('der letzten 6 Monate','of the last 6 months'))?></small></div>
    <div class="stat"><span><?=e(t('Trainings erfasst','Sessions recorded'))?></span><strong><?=array_sum($sum)?></strong><small><?=e(implode(' · ',array_map(fn($k,$v)=>attendance_label($k).' '.$v,array_keys($sum),$sum)))?></small></div>
</div>
<?php endif ?>
<section class="card">
    <h2><?=e(t('Letzte Trainings','Recent sessions'))?></h2>
    <?php $recent=attendance_recent($id);
    if(!$recent)echo '<p class="muted">'.e(t('Noch nichts erfasst. Anwesenheit wird beim Kurs eingetragen.','Nothing recorded yet. Attendance is entered on the class page.')).'</p>';
    foreach($recent as $r): ?>
    <div class="record-row">
        <div><strong><?=e(fmt_date($r['session_on']))?></strong><p><?=e($r['class_name'])?></p></div>
        <?php badge(attendance_label($r['status']),attendance_tone($r['status'])); ?>
    </div>
    <?php endforeach ?>
</section>
<?php elseif($tab==='classes'): $mine=student_classes($id); ?>
<section class="card"><h2><?=e(t('Kurse dieses Schülers','This student’s classes'))?></h2>
<?php if(!$mine)echo '<p class="muted">'.e(t('Noch keinem Kurs zugeordnet.','Not in any class yet.')).'</p>';
foreach($mine as $row):?><div class="record-row<?=$row['left_on']!==null?' is-past':''?>"><div><strong><a href="<?=e(url('classes',['id'=>$row['id']]))?>"><?=e($row['name'])?></a></strong><p><?=e(class_schedule($row))?></p><small><?=e($row['left_on']!==null?t('Ausgetreten am ','Left on ').fmt_date($row['left_on']):t('Dabei seit ','Member since ').fmt_date($row['joined_on']))?></small></div>
<?php if($row['left_on']===null){start_form('class_member_remove',['class_id'=>$row['id'],'student_id'=>$id,'mode'=>'leave'],'inline-form');submit_button(t('Austritt eintragen','Record leaving'),'subtle');echo '</form>';}?></div><?php endforeach ?></section>
<?php $joined=array_column(array_filter($mine,fn($r)=>$r['left_on']===null),'id');$open=array_filter(training_classes(),fn($c)=>!in_array($c['id'],$joined));
if($open):?><section class="card"><h2><?=e(t('Zu einem Kurs hinzufügen','Add to a class'))?></h2><?php start_form('class_member_add',['student_id'=>$id]);?><div class="grid two"><?php
select_field('class_id',t('Kurs','Class'),array_column($open,'name','id'),'',true);
input('joined_on',t('Dabei seit','Member since'),today(),'date');?></div><?php submit_button(t('Hinzufügen','Add'));?></form></section><?php endif ?>
<?php elseif($tab==='payments'): ?>
<?php if($staff): $free=billing_free_period($s); $firstBill=billing_first_charged_period($s); ?>
<section class="card">
    <div class="section-heading">
        <div>
            <h2><?=e(t('Monatsbeiträge','Monthly charges'))?></h2>
            <p class="muted"><?php
                if((int)($s['billing_paused']??0)===1) echo e(t('Pausiert – für diesen Schüler werden keine Monatsbeiträge angelegt.','Paused – no monthly charges are created for this student.'));
                elseif(billing_amount($s)===null) echo e(t('Kein monatlicher Preis hinterlegt, daher entstehen keine automatischen Beiträge.','No monthly price is set, so no automatic charges are created.'));
                elseif($firstBill) echo e(t('Erster Monat frei: ','First month free: ').billing_month_name((string)$free).'. '.t('Beiträge ab ','Charged from ').billing_month_name($firstBill).' '.substr($firstBill,0,4).'.');
                else echo e(t('Kein Beitrittsdatum hinterlegt.','No join date set.'));
            ?></p>
        </div>
    </div>
    <?php if($s['billing_note']??'')echo '<p class="muted">'.e($s['billing_note']).'</p>'; ?>
    <?php start_form('billing_pause',['id'=>$id,'mode'=>((int)($s['billing_paused']??0)===1?'resume':'pause')]);
    if((int)($s['billing_paused']??0)!==1) input('billing_note',t('Grund (optional, nur intern)','Reason (optional, internal only)'),'','text');
    submit_button((int)($s['billing_paused']??0)===1?t('Beiträge wieder starten','Resume billing'):t('Beiträge pausieren','Pause billing'),'secondary'); ?></form>
</section>
<?php endif ?>
<div class="stats-grid compact"><div class="stat"><span><?=e(t('Offen','Outstanding'))?></span><strong><?=e(money(balance($id)))?></strong></div><div class="stat"><span><?=e(t('Überfällig','Overdue'))?></span><strong class="due"><?=e(money(balance($id,true)))?></strong></div></div>
<?php $charges=student_charges($id);$paymentsOf=payments_by_charge(array_column($charges,'id'));if(!$charges)echo '<div class="card"><p class="muted">'.e(t('Noch keine Beiträge erfasst.','No charges recorded yet.')).'</p></div>';foreach($charges as $c):$remaining=max(0,(int)$c['amount_cents']-(int)$c['paid']); ?>
<section class="card charge-card"><div class="section-heading"><div><h2><?=e($c['label'])?></h2><p class="muted"><?=e(t('Fällig am ','Due ').fmt_date($c['due_on']))?></p></div><?php badge($c['cancelled']?t('Storniert','Cancelled'):($remaining===0?t('Bezahlt','Paid'):money($remaining).' '.t('offen','outstanding')),$c['cancelled']?'':($remaining===0?'green':($c['due_on']<today()?'red':'')));?></div>
<dl class="facts"><div><dt><?=e(t('Beitrag','Charge'))?></dt><dd><?=e(money((int)$c['amount_cents']))?></dd></div><div><dt><?=e(t('Zeitraum','Coverage period'))?></dt><dd><?=e($c['period_from']?fmt_date($c['period_from']).' – '.fmt_date($c['period_to']):t('Einmalig / ohne Zeitraum','One-time / no period'))?></dd></div></dl>
<?php
// Transfer details for whatever is still open on this charge.
if($remaining>0 && !$c['cancelled'] && setting('show_payment_qr')):
    $profile=charge_payment_profile($c);
    if($profile && $profile['iban']!==''):
        $reference=charge_reference($c,$s);
        $payload=qr_payload($profile,$remaining,$reference);
?>
<div class="pay-box">
    <div class="pay-qr"><?=$payload!==''?qr_svg($payload,200):''?></div>
    <div class="pay-details">
        <h3><?=e(t('Offenen Betrag überweisen','Transfer the outstanding amount'))?></h3>
        <p class="muted"><?=e(t('Mit der Bank-App den Code scannen – Betrag und Verwendungszweck werden übernommen.','Scan the code with your banking app and the amount and reference are filled in for you.'))?></p>
        <dl class="facts">
            <div><dt><?=e(t('Betrag','Amount'))?></dt><dd><?=e(money($remaining))?></dd></div>
            <div><dt><?=e(t('Empfänger','Recipient'))?></dt><dd><?=e($profile['recipient']!==''?$profile['recipient']:$profile['name'])?></dd></div>
            <div><dt>IBAN</dt><dd class="mono"><?=e(trim(chunk_split($profile['iban'],4,' ')))?></dd></div>
            <?php if($profile['bic']):?><div><dt>BIC</dt><dd class="mono"><?=e($profile['bic'])?></dd></div><?php endif ?>
            <div><dt><?=e(t('Verwendungszweck','Reference'))?></dt><dd><?=e($reference)?></dd></div>
        </dl>
        <?php if($profile['note'])echo '<p class="muted">'.e($profile['note']).'</p>';?>
    </div>
</div>
<?php endif; endif ?>
<?php $payments=$paymentsOf[(int)$c['id']]??[];$allocated=0;foreach($payments as $p):if(!$p['voided'])$allocated+=(int)$p['amount_cents'];?><div class="record-row"><div><strong><?=e(money((int)$p['amount_cents']))?></strong><p><?=e(fmt_date($p['paid_on']).' · '.$p['method'])?></p><?php badge($p['voided']?t('Storniert','Voided'):($p['confirmed_at']?t('Bestätigt','Confirmed'):t('Unbestätigt','Unconfirmed')),$p['confirmed_at']&&!$p['voided']?'green':'');if($p['note'])echo '<p>'.e($p['note']).'</p>';if($staff&&$p['confirmed_at'])echo '<small>'.e(t('Bestätigt von ','Confirmed by ').($p['confirmer']??t('gelöschtem Konto','deleted account')).' · '.fmt_datetime($p['confirmed_at'])).'</small>';?></div>
<?php if($staff&&!$p['voided']):?><div class="row-actions"><?php if(!$p['confirmed_at']){start_form('payment_state',['id'=>$p['id'],'mode'=>'confirm'],'inline-form');submit_button(t('Bestätigen','Confirm'),'secondary');echo '</form>';}start_form('payment_state',['id'=>$p['id'],'mode'=>'void'],'inline-form');submit_button(t('Stornieren','Void'),'subtle danger-text');?></form></div><?php endif ?></div><?php endforeach ?>
<?php if($staff&&!$c['cancelled']):?>
<?php if((int)$c['amount_cents']>$allocated):?><details><summary><?=e(t('+ Zahlung erfassen','+ Record payment'))?></summary><?php start_form('payment_add',['charge_id'=>$c['id']]);?><div class="grid three"><?php input('amount',t('Betrag (€)','Amount (€)'),amount_input((int)$c['amount_cents']-$allocated),'text',true);input('paid_on',t('Zahlungsdatum','Payment date'),today(),'date',true);$methods=setting('payment_methods',['Überweisung','Bar']);select_field('method',t('Zahlungsart','Payment method'),array_combine($methods,$methods),$methods[0],true);?></div><?php input('note',t('Notiz / Buchungsreferenz','Note / payment reference'));check_field('confirmed',t('Zahlungseingang bestätigen','Confirm receipt of payment'));submit_button(t('Zahlung erfassen','Record payment'));?></form></details><?php endif ?>
<?php if(!$allocated){start_form('charge_cancel',['id'=>$c['id']],'inline-form');submit_button(t('Beitrag stornieren','Cancel charge'),'subtle danger-text');echo '</form>';}endif ?></section>
<?php endforeach ?>
<?php if($staff):$tariff=$s['tariff_id']?one('SELECT * FROM tariffs WHERE id=?',[$s['tariff_id']]):null;?><section class="card"><h2><?=e(t('Beitrag anlegen','Create charge'))?></h2><p class="muted"><?=e(t('Betrag und Zeitraum werden hier festgelegt. Beiträge entstehen nicht automatisch.','Set the amount and coverage here. Charges are not created automatically.'))?></p><?php start_form('charge_add',['student_id'=>$id]);?><div class="grid two"><?php input('label',t('Bezeichnung','Description'),$tariff['name']??'','text',true);input('amount',t('Betrag (€)','Amount (€)'),amount_input($s['price_cents']===null?null:(int)$s['price_cents']),'text',true);input('period_from',t('Bezahlt für Zeitraum ab','Covers from'),'','date');input('period_to',t('Bis einschließlich','Covers through'),'','date');input('due_on',t('Fällig am','Due on'),date('Y-m-d',strtotime('+'.($tariff['due_days']??14).' days')),'date',true);?></div><?php submit_button(t('Beitrag anlegen','Create charge'));?></form></section><?php endif ?>
<?php endif ?>
