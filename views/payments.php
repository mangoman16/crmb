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
            <h2><?=e(t('Monatsbeiträge','Monthly charges'))?></h2>
            <p class="muted"><?=e(t('Am 1. jedes Monats. Der erste Monat nach dem Beitritt ist frei.','On the 1st of each month. The first month after joining is free.'))?></p>
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
        <span><?=e(t('Beiträge werden angelegt','charges will be created'))?> · <?=e(money(array_sum(array_column($willCreate,'amount'))))?></span>
    </div>
    <details>
        <summary><?=e(t('Wen betrifft das?','Who does this affect?'))?></summary>
        <?php foreach($willCreate as $r): ?>
        <div class="record-row"><div><strong><?=e($r['name'])?></strong><p><?=e(money((int)$r['amount']).' · '.t('fällig am ','due ').fmt_date($r['due']))?></p></div></div>
        <?php endforeach ?>
    </details>
    <?php start_form('billing_generate',['period'=>$period]);
          submit_button(count($willCreate).' '.t('Beiträge jetzt anlegen','charges — create now')); ?></form>
<?php else: ?>
    <p class="muted"><?=e(t('Für diesen Monat gibt es nichts anzulegen.','There is nothing to create for this month.'))?></p>
<?php endif ?>
<?php if($willSkip): ?>
    <details>
        <summary><?=count($willSkip)?> <?=e(t('werden übersprungen','are skipped'))?></summary>
        <?php foreach($willSkip as $r): ?>
        <div class="record-row"><div><strong><?=e($r['name'])?></strong><p><?=e($r['skip'])?></p></div></div>
        <?php endforeach ?>
    </details>
<?php endif ?>
</section>
<?php
$charges=rows('SELECT c.*,s.first_name,s.last_name,COALESCE((SELECT SUM(amount_cents) FROM payments p WHERE p.charge_id=c.id AND p.confirmed_at IS NOT NULL AND p.voided=0),0) AS paid FROM charges c JOIN students s ON s.id=c.student_id WHERE c.cancelled=0'.($overdue?' AND c.due_on<?':'').' ORDER BY c.due_on,c.id',$overdue?[today()]:[]);
?>
<div class="saved-filters"><a class="chip" href="<?=e(url('payments'))?>"><?=e(t('Alle offenen Beiträge','All outstanding charges'))?></a><a class="chip" href="<?=e(url('payments',['overdue'=>1]))?>"><?=e(t('Nur überfällig','Overdue only'))?></a><?=link_button(t('Zahlungserinnerung schreiben','Write payment reminder'),'compose',['overdue'=>1],'secondary')?>
<?php start_form('payment_remind',[],'inline-form');submit_button(t('Alle überfälligen per E-Mail erinnern','Email everyone overdue'),'secondary');?></form></div>
<div class="card"><?php $count=0;foreach($charges as $c):$due=(int)$c['amount_cents']-(int)$c['paid'];if($due<=0)continue;$count++;?><a class="payment-row" href="<?=e(url('student',['id'=>$c['student_id'],'tab'=>'payments']))?>"><div><h3><?=e($c['first_name'].' '.$c['last_name'])?></h3><p><?=e($c['label'])?></p><small><?=e(t('Fällig am ','Due ').fmt_date($c['due_on']))?></small></div><strong class="<?=$c['due_on']<today()?'due':''?>"><?=e(money($due))?></strong><?=icon('arrow')?></a><?php endforeach;if(!$count)empty_state(t('Alles ausgeglichen.','All settled.'),t('Für diese Auswahl sind keine offenen Beiträge vorhanden.','No outstanding charges in this selection.'));?></div>
