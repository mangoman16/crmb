<?php
$overdue=!empty($_GET['overdue']);
$period=preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',(string)($_GET['period']??''))?(string)$_GET['period']:billing_current_period();
page_head(t('Beiträge','Payments'),t('Offene Beträge nach bestätigten Zahlungseingängen.','Outstanding amounts after confirmed payments.'));
// Monthly charges: always shown as a preview first. Nothing is created until the
// button below is pressed, and pressing it twice is harmless.
$plan=billing_plan($period);
$willCreate=array_values(array_filter($plan,fn($r)=>$r['skip']===null));
$willSkip=array_values(array_filter($plan,fn($r)=>$r['skip']!==null));
?>
<section class="card billing-card">
    <div class="section-heading">
        <div>
            <h2><?=e(t('Beiträge anlegen','Create charges'))?></h2>
            <p class="muted"><?=e(t('Beiträge entstehen aus den Kursen: jede Kursteilnahme, zu dem Tarif, den sie nennt. Hier ist erst einmal nur die Vorschau – angelegt wird nichts, bis du unten drückst.','Charges come from courses: one per enrolment, at the tariff it names. This is only the preview – nothing is created until you press the button below.'))?></p>
        </div>
    </div>
    <form method="get" class="attendance-date">
        <input type="hidden" name="page" value="payments">
        <?php input('period',t('Monat','Month'),$period,'month',true); ?>
        <?php submit_button(t('Monat wechseln','Change month'),'secondary'); ?>
    </form>
<?php if($willCreate): ?>
    <div class="billing-summary">
        <strong><?=count($willCreate)?></strong>
        <span><?=e(count($willCreate)===1?t('Beitrag wird angelegt','charge will be created'):t('Beiträge werden angelegt','charges will be created'))?> · <?=e(money(array_sum(array_column($willCreate,'amount'))))?></span>
    </div>
    <details>
        <summary><?=e(t('Wen betrifft das?','Who does this affect?'))?></summary>
        <?php foreach($willCreate as $r): ?>
        <div class="record-row"><div><strong><?=e($r['name'])?></strong>
            <p><?=e($r['class_name'].' · '.($r['tariff_name']??'').' · '.money((int)$r['amount']))?><?php
               if((int)$r['discount']>0)badge(t('Rabatt','Discount'),'green');
               if(!empty($r['prorated']))badge(t('Anteilig','Pro rata'),'amber');?></p>
            <small><?=e(t('Zeitraum ','Covers ').fmt_date($r['from']).'–'.fmt_date($r['to']).' · '.t('fällig am ','due ').fmt_date($r['due']))?><?php
               if($r['note'])echo ' · '.e($r['note']);?></small></div></div>
        <?php endforeach ?>
    </details>
    <?php start_form('billing_generate',['period'=>$period]);
          submit_button(plural(count($willCreate),'Beitrag jetzt anlegen','Beiträge jetzt anlegen','charge — create now','charges — create now')); ?></form>
<?php else: ?>
    <p class="muted"><?=e(t('Für diesen Monat gibt es nichts anzulegen.','There is nothing to create for this month.'))?></p>
<?php endif ?>
<?php if($willSkip): ?>
    <details>
        <summary><?=e(plural(count($willSkip),'wird übersprungen','werden übersprungen','is skipped','are skipped'))?></summary>
        <?php foreach($willSkip as $r): ?>
        <div class="record-row"><div><strong><?=e($r['name'])?></strong><p><?=e($r['class_name'].' · '.$r['skip'])?></p></div></div>
        <?php endforeach ?>
    </details>
<?php endif ?>
</section>
<?php
$charges=rows('SELECT c.*,s.first_name,s.last_name,'.charge_paid_sql().' AS paid FROM charges c JOIN students s ON s.id=c.student_id WHERE c.cancelled=0'.($overdue?' AND '.charge_overdue_sql().'<?':'').' ORDER BY c.due_on,c.id',$overdue?[today()]:[]);
?>
<div class="saved-filters"><a class="chip" href="<?=e(url('payments'))?>"><?=e(t('Alle offenen Beiträge','All outstanding charges'))?></a><a class="chip" href="<?=e(url('payments',['overdue'=>1]))?>"><?=e(t('Nur überfällig','Overdue only'))?></a><?=link_button(t('Zahlungserinnerung schreiben','Write payment reminder'),'compose',['overdue'=>1],'secondary')?>
<?php start_form('payment_remind',[],'inline-form');submit_button(t('Alle überfälligen per E-Mail erinnern','Email everyone overdue'),'secondary');?></form></div>
<div class="card"><?php $count=0;foreach($charges as $c):$due=(int)$c['amount_cents']-(int)$c['paid'];if($due<=0)continue;$count++;?><a class="payment-row" href="<?=e(url('student',['id'=>$c['student_id'],'tab'=>'payments']))?>"><div><h3><?=e($c['first_name'].' '.$c['last_name'])?></h3><p><?=e($c['label'])?></p><small><?=e(t('Fällig am ','Due ').fmt_date($c['due_on']))?></small></div><strong class="<?=($c['overdue_on']??$c['due_on'])<today()?'due':''?>"><?=e(money($due))?></strong><?=icon('arrow')?></a><?php endforeach;if(!$count)empty_state(t('Alles ausgeglichen.','All settled.'),t('Für diese Auswahl sind keine offenen Beiträge vorhanden.','No outstanding charges in this selection.'));?></div>
