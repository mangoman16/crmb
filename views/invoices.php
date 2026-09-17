<?php
/**
 * Every invoice, for the trainer.
 *
 * Open is what an invoice starts as, overdue is what open becomes on its own,
 * and paid is the one the trainer says. Those three words are the whole of the
 * state she has to think about, so they are the whole of the filter.
 */
$filter=(string)($_GET['state']??'open');
if(!in_array($filter,['open','overdue','paid','cancelled','all'],true))$filter='open';
$all=all_invoices();
$shown=$filter==='all'?$all:array_values(array_filter($all,fn($i)=>$i['status']===$filter));
$counts=['open'=>0,'overdue'=>0,'paid'=>0,'cancelled'=>0];
$outstanding=0;
foreach($all as $i){ $counts[$i['status']]=($counts[$i['status']]??0)+1;
                     if(in_array($i['status'],['open','overdue'],true))$outstanding+=(int)$i['gross_cents']; }

page_head(t('Rechnungen','Invoices'),
    t('Rechnungen entstehen aus Beiträgen und werden beim jeweiligen Kind angelegt.','Invoices are made from charges, on each child’s page.'));

$problems=invoice_issuer_problems();
if($problems): ?>
<div class="notice warn">
    <strong><?=e(t('Bevor Rechnungen ausgestellt werden können, fehlen noch Angaben zum Betrieb.','Some details about the business are still missing before invoices can be issued.'))?></strong>
    <?php foreach($problems as $problem): ?><br><?=e($problem)?><?php endforeach ?>
    <p><?=link_button(t('Jetzt eintragen','Fill them in now'),'settings',['tab'=>'organisation'],'secondary')?></p>
</div>
<?php endif ?>

<div class="stats-grid compact">
    <div class="stat accent"><span><?=e(t('Offen und überfällig','Open and overdue'))?></span><strong><?=e(money($outstanding))?></strong><small><?=e(plural($counts['open']+$counts['overdue'],'Rechnung','Rechnungen','invoice','invoices'))?></small><?=icon('wallet')?></div>
    <div class="stat"><span><?=e(t('Überfällig','Overdue'))?></span><strong><?=(int)$counts['overdue']?></strong><small><?=e(t('über das Zahlungsziel hinaus','past the payment date'))?></small><?=icon('calendar')?></div>
</div>

<div class="saved-filters">
<?php foreach(['open'=>t('Offen','Open'),'overdue'=>t('Überfällig','Overdue'),'paid'=>t('Bezahlt','Paid'),
               'cancelled'=>t('Storniert','Cancelled'),'all'=>t('Alle','All')] as $key=>$label): ?>
    <a class="chip <?=$filter===$key?'is-on':''?>" href="<?=e(url('invoices',['state'=>$key]))?>"><?=e($label)?><?php
        if($key!=='all')echo ' ('.(int)($counts[$key]??0).')';?></a>
<?php endforeach ?>
</div>

<div class="card">
<?php if(!$shown): ?>
    <p class="muted"><?=e(t('In dieser Auswahl gibt es nichts.','Nothing in this selection.'))?></p>
<?php endif ?>
<?php foreach($shown as $inv): ?>
    <div class="record-row">
        <div>
            <strong><a href="<?=e(url('student',['id'=>$inv['student_id'],'tab'=>'invoices']))?>"><?=e($inv['number'])?></a></strong>
            <p><?=e($inv['first_name'].' '.$inv['last_name'])?> · <?=e(money((int)$inv['gross_cents']))?></p>
            <small><?=e(t('Ausgestellt am ','Issued ').fmt_date((string)$inv['issued_on']).' · '.t('zahlbar bis ','payable by ').fmt_date((string)$inv['due_on']))?></small>
        </div>
        <div class="row-actions">
            <?php badge(invoice_status_label($inv['status']),invoice_status_tone($inv['status'])); ?>
            <a class="button secondary" href="<?=e(url('download',['what'=>'invoice','id'=>$inv['id']]))?>"><?=e(t('PDF','PDF'))?></a>
        </div>
    </div>
<?php endforeach ?>
</div>
