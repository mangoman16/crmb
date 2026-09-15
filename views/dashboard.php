<?php
$staff=is_staff($user);$students=filtered_students([],$staff?null:(int)$user['id']);
$open=0;$overdue=0;$active=0;$absent=0;
foreach($students as $s){
    $open+=balance((int)$s['id']);
    if($s['status']==='active')$active++;
    // Only staff see the overdue and absent tiles, so a parent's page does not
    // pay for those two queries per student.
    if($staff){
        $overdue+=balance((int)$s['id'],true);
        if(scalar('SELECT COUNT(*) FROM absences WHERE student_id=? AND starts_on<=? AND ends_on>=?',[$s['id'],today(),today()]))$absent++;
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
<div class="dashboard-grid"><section class="card"><div class="section-heading"><h2><?=e($staff?t('Schüler','Students'):t('Meine Schüler','My students'))?></h2><a href="<?=e(url('students'))?>"><?=e(t('Alle ansehen','View all'))?> <?=icon('arrow')?></a></div>
<?php if(!$students)empty_state(t('Noch keine Schüler','No students yet'),$staff?t('Lege zuerst einen Schüler an. Ein Konto kannst du auch später zuordnen.','Add your first student. You can link an account later.'):t('Deine Trainerin ordnet diesem Konto Schüler zu.','Your coach will link students to this account.'),$staff?link_button(t('Ersten Schüler anlegen','Add first student'),'student'):'');else foreach(array_slice($students,0,6) as $s)student_card($s); ?>
</section><section class="card news-panel"><div class="section-heading"><h2><?=e(t('Neuigkeiten','News'))?></h2><?=icon('news')?></div>
<?php $items=rows('SELECT * FROM news WHERE published=1 ORDER BY updated_at DESC LIMIT 3');if(!$items):?><p class="muted"><?=e(t('Noch keine Neuigkeiten.','No news yet.'))?></p><?php else:foreach($items as $n):?><a class="news-summary" href="<?=e(url('news',['id'=>$n['id']]))?>"><small><?=e(fmt_date($n['updated_at']))?></small><h3><?=e($n['title'])?></h3><p><?=e(mb_substr($n['body'],0,140))?></p></a><?php endforeach;endif ?>
<?php if($staff):?><div class="quick-actions"><?=link_button(t('Neuigkeit schreiben','Write news'),'news',['new'=>1],'secondary')?><?=link_button(t('Gruppe anschreiben','Message a group'),'compose',[],'secondary')?></div><?php endif ?></section></div>
