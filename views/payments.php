<?php
/* Geld › Beiträge (the audit, N5): who owes, first. The title is the word on the
   bar and in the menu, with no line under it; then Beiträge and Rechnungen, two
   chips, and the list. Under „Überfällig" the reminder, as a sheet that says
   before the tap what goes out; after the list the monthly charges, folded: a
   page she opens weekly to see who owes, and a run she does once a month. */
$overdue=!empty($_GET['overdue']);
$period=preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$_GET['period']??'')?$_GET['period']:billing_current_period();
money_head('payments');
/* „Überfällig" is the one rule the reminder sends by (charge_is_overdue_sql()),
   so the list and the count on the reminder cannot disagree about who is late. */
$charges=rows('SELECT c.*,s.first_name,s.last_name,'.charge_paid_sql().' AS paid FROM charges c JOIN students s ON s.id=c.student_id WHERE '.($overdue?charge_is_overdue_sql():'c.cancelled=0').' ORDER BY c.due_on,c.id',$overdue?[today()]:[]);
/* Which list is on says itself, as Zugänge's chips do: a chip each, keyed by
   the list it shows, the one on filled and current. Links, so nothing needs
   JavaScript. */
$list=$overdue?'overdue':'all'; ?>
<nav class="saved-filters" aria-label="<?=e(t('Auswahl','Selection'))?>">
<?php foreach(['all'=>t('Alle','All'),'overdue'=>t('Überfällig','Overdue')] as $key=>$label): ?>
    <a class="chip <?=$key===$list?'is-on':''?>" href="<?=e(url('payments',$key==='overdue'?['overdue'=>1]:[]))?>" <?=$key===$list?'aria-current="true"':''?>><?=e($label)?></a>
<?php endforeach ?>
</nav>
<div class="card"><?php $count=0;foreach($charges as $c):$due=(int)$c['amount_cents']-(int)$c['paid'];if($due<=0)continue;$count++;?><a class="payment-row" href="<?=e(url('student',['id'=>$c['student_id'],'tab'=>'payments']))?>"><div><h3><?=e($c['first_name'].' '.$c['last_name'])?></h3><p><?=e($c['label'])?></p><small><?=e(t('Fällig am ','Due ').fmt_date($c['due_on']))?></small></div><strong class="<?=charge_is_overdue($c)?'due':''?>"><?=e(money($due))?></strong><?=icon('chevron')?></a><?php endforeach;if(!$count)empty_state(t('Alles ausgeglichen.','All settled.'),t('Für diese Auswahl sind keine offenen Beiträge vorhanden.','No outstanding charges in this selection.'),'','check');?></div>
<?php
/* The reminder (spec-a3-reminder, „On Geld"): only under „Überfällig", and only
   when that list has someone. A sheet, because what it sends cannot be called
   back: the count and who gets none are said before the tap, from
   payment_reminders(), the one rule payment_remind sends by, so the number on
   the button is the number that goes out. Where none can go, one sentence in
   its place says why, and there is no button: mail does not work yet - by
   mail_sending_missing(), the rule payment_remind refuses by, which waits for
   working mail and not for the privacy notice an invitation needs, since a
   reminder goes to logins already set up - or there is nobody to send to. */
if($overdue && $count):
    $why=mail_sending_missing();
    if($why===''):
        $reminders=payment_reminders();
        $sending=count($reminders['send']);
        $leftOut=payment_reminders_left_out($reminders);
        /* The reason is the banner's after „Jetzt schicken"
           (payment_reminders_unreachable()), and like the banner it is given
           only when nobody was reminded today, or it would be the wrong one. */
        if(!$sending) $why=$reminders['today']?$leftOut:t('Keine Erinnerung möglich: ','No reminder possible: ').payment_reminders_unreachable();
    endif;
    if($why!==''): ?>
<p class="card reminder-none"><?=e($why)?></p>
<?php else: ?>
<details class="card reminder-sheet" data-sheet>
    <summary><?=e(plural($sending,'Erinnerung schicken','Erinnerungen schicken','reminder – send','reminders – send'))?></summary>
    <p><?=e(t('Pro Kind eine E-Mail mit allen überfälligen Beiträgen und der Summe. Verschickt lässt sie sich nicht zurückholen.','One email per child, listing every overdue charge and the total. Once sent, it cannot be called back.'))?></p>
    <?php if($leftOut!==''): ?><p><?=e($leftOut)?></p><?php endif;
    start_form('payment_remind');submit_button(t('Jetzt schicken','Send now'),'secondary'); ?></form>
</details>
<?php endif;
endif;

/* The monthly charges, after the list: one row that says how they stand -
   made by themselves, or how many the month shown would make - and opens on
   what was the page's first card, without its title (the audit, N5). Open by
   itself when they are not automatic and the current month has some to make,
   when a month was chosen in it, and when the setup checklist's „Beiträge" step
   sent an administrator here for them. Always a preview first: nothing is made
   until its button is pressed, and pressing it twice is harmless. */
$plan=billing_plan($period);
$willCreate=array_values(array_filter($plan,fn($r)=>$r['skip']===null));
$willSkip=array_values(array_filter($plan,fn($r)=>$r['skip']!==null));
/* Whether the monthly charges make themselves, beside the charges they
   would make (ADR 0011). What switching it on does is said before the
   tap, with the number, because it acts at once. */
$auto=(bool)setting('auto_billing');$current=billing_current_period();
$monthName=fn(string $p)=>billing_month_name($p).' '.substr($p,0,4);
$lastRun=(string)setting('billing_last_period','');
/* What the current month would make, and of that what switching it on would
   make at once: nothing for a month it has already run for. */
$thisMonth=count(array_filter($period===$current?$plan:billing_plan($current),fn($r)=>$r['skip']===null));
$pending=$lastRun===$current?0:$thisMonth;
if($auto) $status=$lastRun!==''?t('Automatisch – zuletzt ','Automatic – last done for ').$monthName($lastRun).'.'
                               :t('Automatisch – beim nächsten Aufruf des Portals zum ersten Mal.','Automatic – for the first time on the portal’s next page view.');
elseif(is_admin($user)) $status=$pending
    ?($pending===1?t('Schaltest du es jetzt ein, entsteht sofort ','If you switch it on now, '):t('Schaltest du es jetzt ein, entstehen sofort ','If you switch it on now, '))
        .plural($pending,'Beitrag','Beiträge','charge','charges')
        .($pending===1?t(' für ',' is created straight away for '):t(' für ',' are created straight away for ')).$monthName($current)
        .($period===$current?t(' – die Vorschau steht darunter.',' – the preview is below.'):'.')
    :t('Schaltest du es jetzt ein, entstehen die Beiträge ab dem nächsten Monat von selbst.','If you switch it on now, the charges make themselves from next month on.');
/* The switch is not the trainer's, and „Erst die Vorschau" below already says
   how the charges are made while it is off: no second line saying it again. */
else $status='';
$month=billing_month_name($period);
fold_row(t('Monatsbeiträge','Monthly charges'),
    $auto?t('Automatisch','Automatic')
         :($willCreate?strtr(t('{month}: {n} anzulegen','{month}: {n} to create'),['{month}'=>$month,'{n}'=>(string)count($willCreate)])
                      :strtr(t('{month}: nichts anzulegen','{month}: nothing to create'),['{month}'=>$month])),
    (!$auto && $thisMonth>0) || isset($_GET['period']) || ($_GET['from']??null)==='start','billing-card'); ?>
    <p class="muted"><?=e(t('Erst die Vorschau – angelegt wird erst mit dem Knopf unten.','A preview first – nothing is created until the button below.'))?></p>
<?php if($status!==''): /* always, for an administrator: the switch is theirs */ ?>
    <div class="auto-charges" id="auto-charges">
        <p class="auto-charges-status"><?=e($status)?></p>
        <?php if(is_admin($user)): start_form('auto_billing_save',[],'form auto-charges-form');
            check_field('auto_billing',t('Jeden Monat automatisch anlegen','Create them every month automatically'),$auto,'',false,true); ?>
            <small class="auto-charges-hint"><?=e(t('Beim ersten Aufruf des Portals im Monat, nicht auf die Minute am 1.','On the first page view of the month, not on the stroke of the 1st.'))?></small>
            <?php submit_button(t('Speichern','Save'),'secondary'); ?></form>
        <?php endif ?>
    </div>
<?php endif ?>
    <form method="get" class="row-form">
        <input type="hidden" name="page" value="payments">
        <?php if($overdue): ?><input type="hidden" name="overdue" value="1"><?php endif;
        input('period',t('Monat','Month'),$period,'month',true);
        submit_button(t('Monat wechseln','Change month'),'secondary'); ?>
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
            <p class="badge-line"><span><?=e($r['class_name'].' · '.($r['tariff_name']??'').' · '.money((int)$r['amount']))?></span><?php
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
</details>
