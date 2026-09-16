<?php
$staff=is_staff($user);$students=filtered_students([],$staff?null:(int)$user['id']);
$open=0;$overdue=0;$active=0;$absent=0;
// One query each for the whole list, not one per student. The cards below show
// an overdue amount for every role, so both maps are worth loading either way.
$openBy=balances();$overdueBy=balances(true);
$absentToday=$staff?array_flip(array_map('intval',array_column(
    rows('SELECT DISTINCT student_id FROM absences WHERE starts_on<=? AND ends_on>=?',[today(),today()]),'student_id'))):[];
foreach($students as $s){
    $id=(int)$s['id'];
    $open+=$openBy[$id]??0;
    if($s['status']==='active')$active++;
    if($staff){
        $overdue+=$overdueBy[$id]??0;
        if(isset($absentToday[$id]))$absent++;
    }
}
page_head(t('Hallo ','Hello ').explode(' ',$user['name'])[0],$staff?t('Übersicht über Schüler, Beiträge und Abwesenheiten.','Students, charges and absences at a glance.'):t('Deine Schüler und was noch offen ist.','Your students and anything still outstanding.'),$staff?link_button(t('+ Schüler anlegen','+ Add student'),'student'):link_button(t('Nachricht schreiben','Write message'),'messages',['new'=>1]));
if($user['role']==='admin' && (!setting('smtp',[]) || !setting('privacy_ready',false))): ?>
<div class="setup-strip"><div><strong><?=e(t('Portal einrichten','Set up your portal'))?></strong><p><?=e(t('Für Einladungen fehlen noch SMTP oder die vollständige Datenschutzerklärung.','Invitations need SMTP settings and a completed privacy notice.'))?></p></div><?=link_button(t('Einrichten','Set up'),'settings',['tab'=>!setting('smtp',[])?'smtp':'privacy'],'secondary')?></div>
<?php endif ?>
<div class="stats-grid<?=$staff?'':' compact'?>">
<div class="stat accent"><span><?=e($staff?t('Aktive Schüler','Active students'):t('Meine Schüler','My students'))?></span><strong><?=$staff?$active:count($students)?></strong><small><?=e($staff?t('im Training','in training'):t('mit diesem Konto','on this account'))?></small><?=icon('users')?></div>
<div class="stat"><span><?=e($staff?t('Offene Beiträge','Outstanding charges'):t('Offen','Outstanding'))?></span><strong class="<?=!$staff&&$open?'due':''?>"><?=e(money($open))?></strong><small><?=e($staff?t('Noch nicht bestätigt','Not yet confirmed'):($open?t('Beim Schüler öffnen','Open the student'):t('Alles bezahlt','All paid')))?></small><?=icon('wallet')?></div>
<?php if($staff): ?>
<div class="stat"><span><?=e(t('Davon überfällig','Of which overdue'))?></span><strong class="<?=$overdue?'due':''?>"><?=e(money($overdue))?></strong><small><?=e(t('Fälligkeit überschritten','Past the due date'))?></small><?=icon('calendar')?></div>
<div class="stat"><span><?=e(t('Heute abwesend','Absent today'))?></span><strong><?=$absent?></strong><small><?=e(t('Krank, Urlaub oder abgemeldet','Sick, away or unavailable'))?></small><?=icon('calendar')?></div>
<?php endif ?>
</div>
<?php
/* The timeline: what happened, what is on today, and what is next.
   Built for the question she actually opens the portal with - "when is the next
   training and where" - which used to need three clicks and a memory of which
   day a course runs on. Students see the courses they are in; the trainer sees
   all of them. */
$myClasses=$staff?null:array_map('intval',array_column(rows(
    'SELECT DISTINCT cs.class_id FROM class_students cs JOIN students s ON s.id=cs.student_id'
    .' WHERE s.account_id=? AND cs.left_on IS NULL',[(int)$user['id']]),'class_id'));
$timeline=[];
if($staff || $myClasses) {
    $from=(new DateTimeImmutable(today()))->modify('-21 days')->format('Y-m-d');
    $to=(new DateTimeImmutable(today()))->modify('+35 days')->format('Y-m-d');
    foreach(class_calendar($from,$to) as $entry)
        if($staff || in_array($entry['class_id'],$myClasses,true)) $timeline[]=$entry;
}
$past=array_values(array_filter($timeline,fn($e)=>$e['date']<today()));
$todayEntries=array_values(array_filter($timeline,fn($e)=>$e['date']===today()));
$upcoming=array_values(array_filter($timeline,fn($e)=>$e['date']>today()));
if($timeline): ?>
<section class="card timeline-card">
    <div class="section-heading">
        <div><h2><?=e(t('Termine','What is on'))?></h2>
        <p class="muted"><?=e(t('Aus den Wochenplänen der Kurse. Änderungen für einzelne Tage stehen mit dabei.','From the courses’ weekly patterns. Changes to single days are shown with them.'))?></p></div>
        <?php if($staff)echo link_button(t('Termin ändern','Change a date'),'classes',[],'secondary');?>
    </div>
    <ol class="timeline">
    <?php /* Only the last three that have been, because the useful part of the
             past is "did Monday happen", not a term's worth of history. */
    foreach(array_slice($past,-3) as $entry): ?>
        <li class="timeline-item is-past">
            <span class="timeline-when"><?=e(fmt_date($entry['date']))?></span>
            <span class="timeline-what"><strong><?=e($entry['class_name'])?></strong><small><?=e(session_label($entry))?></small></span>
            <?php if($entry['status']!=='planned')badge(session_statuses()[$entry['status']]??$entry['status'],$entry['status']==='cancelled'?'red':'amber');?>
        </li>
    <?php endforeach ?>
    <?php if(!$todayEntries): ?>
        <li class="timeline-item is-today is-empty"><span class="timeline-when"><?=e(t('Heute','Today'))?></span>
            <span class="timeline-what"><small><?=e(t('Heute ist kein Training.','No training today.'))?></small></span></li>
    <?php endif ?>
    <?php foreach($todayEntries as $entry): ?>
        <li class="timeline-item is-today">
            <span class="timeline-when"><?=e(t('Heute','Today'))?></span>
            <span class="timeline-what"><strong><?=e($entry['class_name'])?></strong><small><?=e(session_label($entry))?></small>
                <?php if($entry['note']):?><small><?=e($entry['note'])?></small><?php endif ?></span>
            <?php if($entry['status']!=='planned')badge(session_statuses()[$entry['status']]??$entry['status'],$entry['status']==='cancelled'?'red':'amber');
                  elseif($staff)echo link_button(t('Anwesenheit','Attendance'),'attendance',['id'=>$entry['class_id'],'on'=>$entry['date']],'secondary');?>
        </li>
    <?php endforeach ?>
    <?php foreach(array_slice($upcoming,0,6) as $entry): ?>
        <li class="timeline-item">
            <span class="timeline-when"><?=e(fmt_date($entry['date']))?></span>
            <span class="timeline-what"><strong><?=e($entry['class_name'])?></strong><small><?=e(session_label($entry))?></small>
                <?php if($entry['note']):?><small><?=e($entry['note'])?></small><?php endif ?></span>
            <?php if($entry['status']!=='planned')badge(session_statuses()[$entry['status']]??$entry['status'],$entry['status']==='cancelled'?'red':'amber');?>
        </li>
    <?php endforeach ?>
    </ol>
</section>
<?php endif ?>
<div class="dashboard-grid"><section class="card"><div class="section-heading"><h2><?=e($staff?t('Schüler','Students'):t('Meine Schüler','My students'))?></h2><a href="<?=e(url('students'))?>"><?=e(t('Alle ansehen','View all'))?> <?=icon('arrow')?></a></div>
<?php if(!$students)empty_state(t('Noch keine Schüler','No students yet'),$staff?t('Lege zuerst einen Schüler an. Ein Konto kannst du auch später zuordnen.','Add your first student. You can link an account later.'):t('Deine Trainerin ordnet diesem Konto Schüler zu.','Your coach will link students to this account.'),$staff?link_button(t('Ersten Schüler anlegen','Add first student'),'student'):'');else foreach(array_slice($students,0,6) as $s)student_card($s+['due_cents'=>$overdueBy[(int)$s['id']]??0]); ?>
</section><section class="card news-panel"><div class="section-heading"><h2><?=e(t('Neuigkeiten','News'))?></h2><?=icon('news')?></div>
<?php $items=rows('SELECT * FROM news WHERE published=1 ORDER BY updated_at DESC LIMIT 3');if(!$items):?><p class="muted"><?=e(t('Noch keine Neuigkeiten.','No news yet.'))?></p><?php else:foreach($items as $n):?><a class="news-summary" href="<?=e(url('news',['id'=>$n['id']]))?>"><small><?=e(fmt_date($n['updated_at']))?></small><h3><?=e($n['title'])?></h3><p><?=e(mb_substr($n['body'],0,140))?></p></a><?php endforeach;endif ?>
<?php if($staff):?><div class="quick-actions"><?=link_button(t('Neuigkeit schreiben','Write news'),'news',['new'=>1],'secondary')?><?=link_button(t('Gruppe anschreiben','Message a group'),'compose',[],'secondary')?></div><?php endif ?></section></div>
