<?php
$admin=is_admin($user);
$id=(int)($_GET['id']??0);
$edit=$admin && (!empty($_GET['edit']) || !empty($_GET['new']));
$c=$id?training_class($id):null;
$blank=['id'=>0,'name'=>'','description'=>'','weekday'=>null,'starts_at'=>'','ends_at'=>'','location'=>'',
        'trainer_id'=>null,'tariff_id'=>setting('default_tariff')?:null,'payment_profile_id'=>setting('default_payment_profile')?:null,
        'capacity'=>0,'sort_order'=>0,'archived'=>0];
$form=$c??$blank;

page_head(
    $id?$form['name']:t('Kurse','Classes'),
    $id?class_schedule($form):t('Trainingsgruppen und ihre Teilnehmer.','Training groups and who is in them.'),
    $id?link_button(t('Alle Kurse','All classes'),'classes',[],'secondary')
       :($admin?link_button(t('+ Kurs anlegen','+ Add class'),'classes',['new'=>1]):'')
);

if(!$id && !$edit):
    $list=training_classes(true);
    if(!$list):
        empty_state(t('Noch keine Kurse.','No classes yet.'),
            t('Ein Kurs bündelt Schüler, einen Tarif und die Bankverbindung für die Beiträge.','A class groups students with a tariff and the bank details used for their charges.'),
            $admin?link_button(t('Ersten Kurs anlegen','Add the first class'),'classes',['new'=>1]):'');
    else: ?>
    <div class="card">
    <?php foreach($list as $row): ?>
        <a class="class-row" href="<?=e(url('classes',['id'=>$row['id']]))?>">
            <div>
                <h3><?=e($row['name'])?><?php if($row['archived'])badge(t('Archiviert','Archived'));?></h3>
                <p><?=e(class_schedule($row))?></p>
                <small><?=e($row['tariff_name']?:t('Kein Tarif','No tariff'))?><?php if($row['trainer_name'])echo ' · '.e($row['trainer_name']);?></small>
            </div>
            <span class="class-count"><strong><?=(int)$row['member_count']?></strong><small><?=e(t('Schüler','students'))?></small></span>
            <?=icon('arrow')?>
        </a>
    <?php endforeach ?>
    </div>
<?php endif; endif ?>

<?php
$tab=(string)($_GET['tab']??'members');
if(!in_array($tab,['members','attendance'],true))$tab='members';
if($id && !$edit) tabs(['members'=>t('Teilnehmer','Members'),'attendance'=>t('Anwesenheit','Attendance')],$tab,'classes',['id'=>$id]);
?>
<?php if($id && !$edit && $tab==='attendance'): require ROOT.'/views/_class_attendance.php'; ?>
<?php elseif($id && !$edit): $members=class_members($id); ?>
<div class="card">
    <div class="section-heading">
        <h2><?=e(t('Teilnehmer','Members'))?></h2>
        <?php if($admin)echo link_button(t('Kurs bearbeiten','Edit class'),'classes',['id'=>$id,'edit'=>1],'secondary');?>
    </div>
    <dl class="facts">
        <div><dt><?=e(t('Termin','Schedule'))?></dt><dd><?=e(class_schedule($form))?></dd></div>
        <div><dt><?=e(t('Tarif','Tariff'))?></dt><dd><?=e($form['tariff_name']?:'–')?></dd></div>
        <div><dt><?=e(t('Zahlungsempfänger','Paid into'))?></dt><dd><?=e($form['profile_name']?:t('Standard','Default'))?></dd></div>
        <div><dt><?=e(t('Plätze','Places'))?></dt><dd><?=(int)$form['capacity']?e((string)(int)$form['capacity']):e(t('Unbegrenzt','Unlimited'))?></dd></div>
    </dl>
    <?php if($form['description']):?><p class="prewrap"><?=e($form['description'])?></p><?php endif ?>
</div>

<div class="card">
    <?php if(!$members)echo '<p class="muted">'.e(t('Noch keine Schüler in diesem Kurs.','No students in this class yet.')).'</p>';
    foreach($members as $m): $left=$m['left_on']!==null; ?>
    <div class="record-row<?=$left?' is-past':''?>">
        <div>
            <strong><a href="<?=e(url('student',['id'=>$m['id']]))?>"><?=e($m['first_name'].' '.$m['last_name'])?></a></strong>
            <p><?=e($left
                ? t('Ausgetreten am ','Left on ').fmt_date($m['left_on'])
                : t('Dabei seit ','Member since ').fmt_date($m['joined_on']))?></p>
        </div>
        <div class="row-actions">
        <?php if(!$left){ start_form('class_member_remove',['class_id'=>$id,'student_id'=>$m['id'],'mode'=>'leave'],'inline-form');
                          submit_button(t('Austritt eintragen','Record leaving'),'subtle'); echo '</form>'; }
              start_form('class_member_remove',['class_id'=>$id,'student_id'=>$m['id'],'mode'=>'forget'],'inline-form');
              submit_button(t('Aus Kurs entfernen','Remove from class'),'subtle danger-text'); ?></form>
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
    <div class="grid two">
    <?php
    select_field('student_id',t('Schüler','Student'),
        array_column(array_map(fn($s)=>['id'=>$s['id'],'label'=>$s['first_name'].' '.$s['last_name']],array_values($available)),'label','id'),
        '',true);
    input('joined_on',t('Dabei seit','Member since'),today(),'date');
    ?>
    </div>
    <?php submit_button(t('Zum Kurs hinzufügen','Add to class')); ?></form>
</div>
<?php endif ?>
<?php endif ?>

<?php if($edit): ?>
<section class="card">
    <h2><?=e($id?t('Kurs bearbeiten','Edit class'):t('Kurs anlegen','Create class'))?></h2>
    <?php start_form('class_save',['id'=>$id]); ?>
    <div class="grid two">
    <?php
    input('name',t('Name des Kurses','Class name'),$form['name'],'text',true);
    input('location',t('Ort / Halle','Location'),$form['location']);
    select_field('weekday',t('Wochentag','Weekday'),weekdays(),$form['weekday']);
    input('capacity',t('Plätze (0 = unbegrenzt)','Places (0 = unlimited)'),(int)$form['capacity'],'number');
    input('starts_at',t('Beginn','Starts'),substr((string)$form['starts_at'],0,5),'time');
    input('ends_at',t('Ende','Ends'),substr((string)$form['ends_at'],0,5),'time');
    select_field('trainer_id',t('Trainerin','Trainer'),
        array_column(rows("SELECT id,name FROM accounts WHERE role IN ('admin','trainer','manager') AND state='active' ORDER BY name"),'name','id'),
        $form['trainer_id']);
    select_field('tariff_id',t('Tarif','Tariff'),
        array_column(rows('SELECT id,name FROM tariffs WHERE archived=0 OR id=? ORDER BY name',[(int)($form['tariff_id']??0)]),'name','id'),
        $form['tariff_id']);
    select_field('payment_profile_id',t('Beiträge gehen auf','Charges are paid into'),
        array_column(payment_profiles(),'name','id'),$form['payment_profile_id']);
    input('sort_order',t('Reihenfolge','Order'),(int)$form['sort_order'],'number');
    ?>
    </div>
    <?php
    input('description',t('Beschreibung','Description'),$form['description'],'textarea');
    check_field('archived',t('Kurs archivieren','Archive class'),(bool)$form['archived']);
    submit_button(); ?></form>
</section>
<?php if($id): ?>
<details class="danger-zone">
    <summary><?=e(t('Kurs löschen','Delete class'))?></summary>
    <p><?=e(t('Der Kurs wird entfernt. Schüler, Beiträge und Zahlungen bleiben erhalten.','The class is removed. Students, charges and payments are kept.'))?></p>
    <?php start_form('class_delete',['id'=>$id]);
    input('confirmation',t('Kursnamen zur Bestätigung eingeben','Enter the class name to confirm'),'','text',true);
    submit_button(t('Kurs löschen','Delete class'),'danger'); ?></form>
</details>
<?php endif; endif ?>
