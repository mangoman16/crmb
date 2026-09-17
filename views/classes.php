<?php
// Courses are the trainer's to change: when they meet, where, what they cost and
// who is in them. An administrator can too, because an administrator can do
// everything a trainer can.
$mayEdit=is_staff($user);
$id=(int)($_GET['id']??0);
$edit=$mayEdit && (!empty($_GET['edit']) || !empty($_GET['new']));
$c=$id?training_class($id):null;
$blank=['id'=>0,'name'=>'','description'=>'','location'=>'','trainer_id'=>null,
        'payment_profile_id'=>setting('default_payment_profile')?:null,
        'capacity'=>0,'sort_order'=>0,'archived'=>0,'profile_name'=>''];
$form=$c??$blank;
$days=$id?class_days($id):[];
$waiting=pending_request_count();

page_head(
    $id?$form['name']:t('Kurse','Courses'),
    $id?class_schedule($form,$days):t('Wann trainiert wird, was es kostet und wer dabei ist.','When training happens, what it costs and who takes part.'),
    $id?link_button(t('Alle Kurse','All courses'),'classes',[],'secondary')
       :($mayEdit?link_button(t('+ Kurs anlegen','+ Add a course'),'classes',['new'=>1]):'')
);

$tab=(string)($_GET['tab']??($id?'members':'list'));
if($id){
    if(!in_array($tab,['members','tariffs','dates','attendance'],true))$tab='members';
    if(!$edit) tabs(['members'=>t('Teilnehmer','Members'),'tariffs'=>t('Tarife','Tariffs'),
                     'dates'=>t('Termine','Dates'),'attendance'=>t('Anwesenheit','Attendance')],$tab,'classes',['id'=>$id]);
} else {
    if(!in_array($tab,['list','requests'],true))$tab='list';
    if(!$edit) tabs(['list'=>t('Kurse','Courses'),
                     'requests'=>t('Anfragen','Requests').($waiting?' ('.$waiting.')':'')],$tab,'classes');
}

// ---------------------------------------------------------------------------
if(!$id && !$edit && $tab==='list'):
    $list=training_classes(true);
    $pattern=class_days_for(array_column($list,'id'));
    if(!$list):
        empty_state(t('Noch keine Kurse.','No courses yet.'),
            t('Ein Kurs sagt, wann und wo trainiert wird, welche Tarife es dafür gibt und wer dabei ist.','A course says when and where training happens, which tariffs there are for it, and who takes part.'),
            $mayEdit?link_button(t('Ersten Kurs anlegen','Add the first course'),'classes',['new'=>1]):'');
    else: ?>
    <div class="card">
    <?php foreach($list as $row): ?>
        <a class="class-row" href="<?=e(url('classes',['id'=>$row['id']]))?>">
            <div>
                <h3><?=e($row['name'])?><?php if($row['archived'])badge(t('Archiviert','Archived'),'amber');?></h3>
                <p><?=e(class_schedule($row,$pattern[(int)$row['id']]??[]))?></p>
                <small><?=e(plural((int)$row['tariff_count'],'Tarif','Tarife','tariff','tariffs'))?><?php if($row['trainer_name'])echo ' · '.e($row['trainer_name']);?></small>
            </div>
            <span class="class-count"><strong><?=(int)$row['member_count']?></strong><small><?=e(t('Schüler','students'))?></small></span>
            <?=icon('arrow')?>
        </a>
    <?php endforeach ?>
    </div>
<?php endif;

// ---------------------------------------------------------------------------
elseif(!$id && !$edit && $tab==='requests'): $requests=open_requests(); ?>
<section class="card">
    <h2><?=e(t('Anfragen von Familien','Requests from families'))?></h2>
    <p class="muted"><?=e(t('Anmelden, abmelden und Tarifwechsel werden angefragt und erst wirksam, wenn du zustimmst.','Joining, leaving and changing tariff are asked for and only take effect once you agree.'))?></p>
    <?php if(!$requests): ?><p class="muted"><?=e(t('Im Moment wartet nichts auf eine Entscheidung.','Nothing is waiting for a decision right now.'))?></p><?php endif ?>
    <?php foreach($requests as $r): ?>
    <div class="record-row">
        <div>
            <strong><a href="<?=e(url('student',['id'=>$r['student_id']]))?>"><?=e($r['first_name'].' '.$r['last_name'])?></a></strong>
            <p><?=e(request_kind_label((string)$r['kind']).' · '.$r['class_name'].($r['tariff_name']?' · '.$r['tariff_name']:''))?></p>
            <?php if($r['message']):?><p class="prewrap"><?=e($r['message'])?></p><?php endif ?>
            <small><?=e(fmt_datetime((string)$r['created_at']))?></small>
        </div>
        <div class="row-actions">
            <?php start_form('enrolment_decide',['id'=>$r['id'],'decision'=>'approve'],'inline-form');submit_button(t('Annehmen','Approve'));?></form>
            <?php start_form('enrolment_decide',['id'=>$r['id'],'decision'=>'decline'],'inline-form');
                  input('note',t('Grund (optional)','Reason (optional)'),'','text',false,'',t('Warum nicht?','Why not?'));
                  submit_button(t('Ablehnen','Decline'),'subtle danger-text');?></form>
        </div>
    </div>
    <?php endforeach ?>
</section>

<?php
// ---------------------------------------------------------------------------
elseif($id && !$edit && $tab==='attendance'): require ROOT.'/views/_class_attendance.php';

// ---------------------------------------------------------------------------
elseif($id && !$edit && $tab==='tariffs'):
    $editTariff=(int)($_GET['tariff']??0);
    $tariff=$editTariff?one('SELECT * FROM tariffs WHERE id=? AND class_id=?',[$editTariff,$id]):null;
    $blankTariff=['id'=>0,'name'=>'','description'=>'','price_cents'=>null,'period'=>'recurring','interval_months'=>1,
                  'due_day'=>1,'grace_days'=>7,'first_period'=>'prorate','discount_months'=>0,
                  'discount_kind'=>'percent','discount_value'=>0,'sort_order'=>0,'archived'=>0];
    $tf=$tariff??$blankTariff;
?>
<div class="settings-grid">
    <section class="card">
        <div class="section-heading"><h2><?=e(t('Tarife dieses Kurses','Tariffs for this course'))?></h2><?=link_button(t('+ Neu','+ New'),'classes',['id'=>$id,'tab'=>'tariffs'],'secondary')?></div>
        <p class="muted"><?=e(t('Ein Kurs kann mehrere Tarife haben – zum Beispiel monatlich oder günstiger im Halbjahr. Beim Anmelden wählt die Familie einen davon.','A course can have several tariffs – monthly, say, or cheaper for half a year. A family picks one when they enrol.'))?></p>
        <?php foreach(class_tariffs($id,true) as $row): ?>
        <a class="editor-list-item <?=$editTariff===(int)$row['id']?'selected':''?>" href="<?=e(url('classes',['id'=>$id,'tab'=>'tariffs','tariff'=>$row['id']]))?>">
            <span><strong><?=e($row['name'])?></strong><small><?=e(tariff_summary($row))?></small></span>
            <span><?=e(money((int)$row['price_cents']))?><?php if($row['archived'])badge(t('Archiviert','Archived'),'amber');?></span>
        </a>
        <?php endforeach ?>
        <?php $orphans=unattached_tariffs(); if($orphans): ?>
        <div class="notice warn"><?=e(t('Diese Tarife gehören noch zu keinem Kurs: ','These tariffs belong to no course yet: '))?><?=e(implode(', ',array_column($orphans,'name')))?>.
            <?=e(t('Sie werden nicht abgerechnet, bis du sie einem Kurs zuordnest.','They are not billed until you give them a course.'))?></div>
        <?php endif ?>
    </section>
    <section class="card">
        <h2><?=e($tariff?t('Tarif bearbeiten','Edit tariff'):t('Tarif anlegen','Create tariff'))?></h2>
        <?php start_form('tariff_save',['id'=>$editTariff,'class_id'=>$id]); ?>
        <div class="grid two"><?php
        input('name',t('Name','Name'),$tf['name'],'text',true,'',t('z. B. Monatsbeitrag','e.g. Monthly fee'));
        input('price',t('Preis je Zeitraum (€)','Price per period (€)'),$tf['price_cents']===null?'':amount_input((int)$tf['price_cents']),'text',true,
              t('Der Betrag für einen ganzen Abrechnungszeitraum, nicht pro Monat.','The amount for one whole billing period, not per month.'));
        select_field('period',t('Wiederkehrend?','Does it recur?'),['recurring'=>t('Ja, regelmäßig','Yes, regularly'),'once'=>t('Nein, einmalig','No, one-off')],$tf['period'],true);
        select_field('interval_months',t('Wie oft','How often'),billing_intervals(),(int)$tf['interval_months'],true);
        ?></div>
        <h3><?=e(t('Zahlung','Payment'))?></h3>
        <div class="grid two"><?php
        input('due_day',t('Zahltag im Monat','Day of the month it is due'),(int)$tf['due_day'],'number',true,
              t('1 bis 28. Am 29. bis 31. gibt es nicht in jedem Monat.','1 to 28. The 29th to 31st do not exist in every month.'));
        input('grace_days',t('Tage bis „überfällig“','Days before it counts as overdue'),(int)$tf['grace_days'],'number',true,
              t('Danach erscheint der Beitrag als überfällig.','After this the charge shows as overdue.'));
        select_field('first_period',t('Wer mittendrin einsteigt','Somebody joining part-way through'),billing_first_period_rules(),$tf['first_period'],true);
        input('sort_order',t('Reihenfolge','Order'),(int)$tf['sort_order'],'number');
        ?></div>
        <h3><?=e(t('Willkommensrabatt','Welcome discount'))?></h3>
        <p class="muted"><?=e(t('Zum Beispiel: 1 Monat zu 100 % ist ein Gratismonat. 6 Monate zu 30 % ist ein halbes Jahr günstiger.','For example: 1 month at 100 % is a free month. 6 months at 30 % is half a year cheaper.'))?></p>
        <div class="grid three"><?php
        input('discount_months',t('Für wie viele Monate','For how many months'),(int)$tf['discount_months']>0?(int)$tf['discount_months']:0,'number',false,
              t('0 = kein Rabatt.','0 = no discount.'));
        select_field('discount_kind',t('Art','Kind'),['percent'=>t('Prozent','Per cent'),'fixed'=>t('Fester Betrag je Monat','Fixed amount per month')],$tf['discount_kind'],true);
        if($tf['discount_kind']==='fixed') input('discount_amount',t('Betrag je Monat (€)','Amount per month (€)'),amount_input((int)$tf['discount_value']),'text');
        else input('discount_percent',t('Prozent','Per cent'),(int)$tf['discount_value'],'number');
        ?></div>
        <?php check_field('discount_forever',t('Dauerhaft, nicht nur am Anfang','Permanently, not only at the start'),(int)$tf['discount_months']<0);
        input('description',t('Erklärung für die Familie','Explanation for the family'),$tf['description'],'text');
        check_field('archived',t('Archivieren (bestehende Anmeldungen bleiben)','Archive (existing enrolments stay)'),(bool)$tf['archived']);
        submit_button();?></form>
    </section>
</div>

<?php
// ---------------------------------------------------------------------------
elseif($id && !$edit && $tab==='dates'):
    $from=(new DateTimeImmutable(today()))->modify('-14 days')->format('Y-m-d');
    $to=(new DateTimeImmutable(today()))->modify('+70 days')->format('Y-m-d');
    $calendar=class_calendar($from,$to,$id);
    $chosen=is_scalar($_GET['on']??'')?(string)($_GET['on']??''):'';
    $current=$chosen!==''?class_session($id,$chosen):null;
?>
<div class="settings-grid">
    <section class="card">
        <h2><?=e(t('Nächste Termine','Upcoming dates'))?></h2>
        <p class="muted"><?=e(t('Aus dem Wochenplan des Kurses. Ein einzelner Tag lässt sich verschieben, absagen oder woanders abhalten, ohne den Plan zu ändern.','Worked out from the course’s weekly pattern. A single day can be moved, cancelled or held somewhere else without changing the pattern.'))?></p>
        <?php if(!$calendar): ?><p class="muted"><?=e(t('Für diesen Kurs ist noch kein Wochentag hinterlegt.','No weekday has been set for this course yet.'))?></p><?php endif ?>
        <?php foreach($calendar as $entry): ?>
        <a class="editor-list-item <?=$chosen===$entry['date']?'selected':''?> <?=$entry['status']==='cancelled'?'is-off':''?>" href="<?=e(url('classes',['id'=>$id,'tab'=>'dates','on'=>$entry['date']]))?>">
            <span><strong><?=e(fmt_date($entry['date']))?><?php if($entry['date']===today())badge(t('Heute','Today'),'green');?></strong><small><?=e(session_label($entry))?></small></span>
            <span><?php if($entry['status']!=='planned')badge(session_statuses()[$entry['status']]??$entry['status'],$entry['status']==='cancelled'?'red':'amber'); echo icon('arrow');?></span>
        </a>
        <?php endforeach ?>
    </section>
    <section class="card">
        <h2><?=e(t('Einen Tag ändern','Change one day'))?></h2>
        <?php start_form('class_session_save',['class_id'=>$id]); ?>
        <div class="grid two"><?php
        input('session_on',t('Datum','Date'),$chosen?:today(),'date',true);
        select_field('status',t('Was ist damit','What about it'),session_statuses(),$current['status']??'planned',true);
        time_field('starts_at',t('Beginn (nur wenn anders)','Starts (only if different)'),(string)($current['starts_at']??''));
        time_field('ends_at',t('Ende (nur wenn anders)','Ends (only if different)'),(string)($current['ends_at']??''));
        ?></div>
        <?php
        input('location',t('Ort (nur wenn anders)','Place (only if different)'),$current['session_id']??0?($current['location']??''):'','text',false,
              t('Leer lassen, wenn der übliche Ort gilt: ','Leave empty for the usual place: ').($form['location']?:t('nicht hinterlegt','not set')));
        input('note',t('Hinweis für die Familien','Note for the families'),$current['note']??'','textarea',false,
              t('Steht in der E-Mail, falls du sie verschickst.','Goes into the email, if you send one.'));
        check_field('notify',t('Alle Kursteilnehmer per E-Mail informieren','Email everybody in this course'));
        submit_button(); ?></form>
    </section>
</div>

<?php
// ---------------------------------------------------------------------------
elseif($id && !$edit): $members=class_members($id); ?>
<div class="card">
    <div class="section-heading">
        <h2><?=e(t('Teilnehmer','Members'))?></h2>
        <?php if($mayEdit)echo link_button(t('Kurs bearbeiten','Edit course'),'classes',['id'=>$id,'edit'=>1],'secondary');?>
    </div>
    <dl class="facts">
        <div><dt><?=e(t('Termine','Dates'))?></dt><dd><?=e(class_schedule($form,$days))?></dd></div>
        <div><dt><?=e(t('Üblicher Ort','Usual place'))?></dt><dd><?=e($form['location']?:'–')?></dd></div>
        <div><dt><?=e(t('Tarife','Tariffs'))?></dt><dd><?=e(count(class_tariffs($id))?implode(', ',array_column(class_tariffs($id),'name')):t('noch keiner','none yet'))?></dd></div>
        <div><dt><?=e(t('Zahlungsempfänger','Paid into'))?></dt><dd><?=e($form['profile_name']?:t('Standard','Default'))?></dd></div>
        <div><dt><?=e(t('Plätze','Places'))?></dt><dd><?=(int)$form['capacity']?e((string)(int)$form['capacity']):e(t('Unbegrenzt','Unlimited'))?></dd></div>
    </dl>
    <?php if($form['description']):?><p class="prewrap"><?=e($form['description'])?></p><?php endif ?>
</div>

<div class="card">
    <?php if(!$members)echo '<p class="muted">'.e(t('Noch keine Schüler in diesem Kurs.','No students in this course yet.')).'</p>';
    foreach($members as $m): $left=$m['left_on']!==null; $en=enrolment($id,(int)$m['id']); ?>
    <div class="record-row<?=$left?' is-past':''?>">
        <div>
            <strong><a href="<?=e(url('student',['id'=>$m['id']]))?>"><?=e($m['first_name'].' '.$m['last_name'])?></a></strong>
            <p><?=e($left
                ? t('Ausgetreten am ','Left on ').fmt_date($m['left_on'])
                : t('Dabei seit ','Member since ').fmt_date($m['joined_on']))?>
               · <?=e($en && $en['tariff_name']?$en['tariff_name']:t('Kein Tarif','No tariff'))?></p>
        </div>
        <div class="row-actions">
        <?php if(!$left){ start_form('class_member_remove',['class_id'=>$id,'student_id'=>$m['id'],'mode'=>'leave'],'inline-form');
                          submit_button(t('Austritt eintragen','Record leaving'),'subtle'); echo '</form>'; }
              start_form('class_member_remove',['class_id'=>$id,'student_id'=>$m['id'],'mode'=>'forget'],'inline-form');
              submit_button(t('Aus Kurs entfernen','Remove from course'),'subtle danger-text'); ?></form>
        </div>
    </div>
    <?php endforeach ?>
</div>

<?php
// Only offer students who are not already current members.
$current=array_column(array_filter($members,fn($m)=>$m['left_on']===null),'id');
$available=array_filter(filtered_students([]),fn($s)=>!in_array($s['id'],$current));
if($available): ?>
<div class="card">
    <h2><?=e(t('Schüler hinzufügen','Add a student'))?></h2>
    <?php start_form('class_member_add',['class_id'=>$id]); ?>
    <div class="grid three">
    <?php
    select_field('student_id',t('Schüler','Student'),
        array_column(array_map(fn($s)=>['id'=>$s['id'],'label'=>$s['first_name'].' '.$s['last_name']],array_values($available)),'label','id'),
        '',true);
    select_field('tariff_id',t('Tarif','Tariff'),array_column(class_tariffs($id),'name','id'),(int)(class_tariffs($id)[0]['id']??0));
    input('joined_on',t('Dabei seit','Member since'),today(),'date');
    ?>
    </div>
    <?php submit_button(t('Zum Kurs hinzufügen','Add to the course')); ?></form>
</div>
<?php endif ?>

<?php
// ---------------------------------------------------------------------------
elseif($edit): ?>
<section class="card">
    <h2><?=e($id?t('Kurs bearbeiten','Edit course'):t('Kurs anlegen','Create course'))?></h2>
    <?php start_form('class_save',['id'=>$id]); ?>
    <div class="grid two">
    <?php
    input('name',t('Name des Kurses','Course name'),$form['name'],'text',true);
    input('location',t('Üblicher Ort / Halle','Usual place'),$form['location'],'text',false,
          t('Gilt für alle Termine, die keinen eigenen Ort haben.','Applies to every date that has no place of its own.'));
    input('capacity',t('Plätze (0 = unbegrenzt)','Places (0 = unlimited)'),(int)$form['capacity'],'number');
    select_field('trainer_id',t('Trainerin','Trainer'),
        array_column(rows("SELECT id,name FROM accounts WHERE role IN ('admin','trainer','manager') AND state='active' ORDER BY name"),'name','id'),
        $form['trainer_id']);
    select_field('payment_profile_id',t('Beiträge gehen auf','Charges are paid into'),
        array_column(payment_profiles(),'name','id'),$form['payment_profile_id']);
    input('sort_order',t('Reihenfolge','Order'),(int)$form['sort_order'],'number');
    ?>
    </div>

    <h3><?=e(t('Wann trainiert wird','When it meets'))?></h3>
    <p class="muted"><?=e(t('Ein Kurs kann mehrmals pro Woche stattfinden. Ort nur ausfüllen, wenn dieser Tag woanders ist als der Kurs sonst.','A course can meet more than once a week. Only fill in a place if that day is somewhere other than the course usually is.'))?></p>
    <div class="option-editor" data-option-editor="day">
    <?php foreach(array_merge($days,[['weekday'=>'','starts_at'=>'','ends_at'=>'','location'=>'']]) as $day): ?>
        <div class="option-row day-row">
            <select name="day_weekday[]" aria-label="<?=e(t('Wochentag','Weekday'))?>">
                <option value=""><?=e(t('– kein Termin –','– no date –'))?></option>
                <?php foreach(weekdays() as $n=>$label): ?>
                <option value="<?=(int)$n?>" <?=(string)$day['weekday']===(string)$n?'selected':''?>><?=e($label)?></option>
                <?php endforeach ?>
            </select>
            <?=time_cells('day_starts_at',t('Beginn','Starts'),(string)$day['starts_at'])?>
            <?=time_cells('day_ends_at',t('Ende','Ends'),(string)$day['ends_at'])?>
            <input name="day_location[]" value="<?=e($day['location'])?>" placeholder="<?=e(t('Ort, falls abweichend','Place, if different'))?>" aria-label="<?=e(t('Ort','Place'))?>">
        </div>
    <?php endforeach ?>
    </div>
    <button type="button" class="button secondary" data-add-option="day"><?=e(t('+ Weiterer Trainingstag','+ Another training day'))?></button>

    <?php
    input('description',t('Beschreibung','Description'),$form['description'],'textarea');
    check_field('archived',t('Kurs archivieren','Archive course'),(bool)$form['archived']);
    submit_button(); ?></form>
</section>
<?php if($id): ?>
<details class="danger-zone">
    <summary><?=e(t('Kurs löschen','Delete course'))?></summary>
    <p><?=e(t('Der Kurs wird entfernt, mit seinen Terminen und Tarifen. Schüler, Beiträge und Zahlungen bleiben erhalten.','The course is removed, along with its dates and tariffs. Students, charges and payments are kept.'))?></p>
    <?php start_form('class_delete',['id'=>$id]);
    input('confirmation',t('Kursnamen zur Bestätigung eingeben','Enter the course name to confirm'),'','text',true);
    submit_button(t('Kurs löschen','Delete course'),'danger'); ?></form>
</details>
<?php endif; endif ?>
