<?php
$staff=is_staff($user);$students=filtered_students([],$staff?null:(int)$user['id']);
$open=0;$overdue=0;$active=0;$absent=0;
// One query each for the whole list, not one per student. The cards below show
// an overdue amount for every role, so both maps are worth loading either way.
$openBy=balances();$overdueBy=balances(true);$coursePrices=$staff?course_prices_by_student():[];
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
// A family's login is one student's (ADR 0010), so the page is about that
// student and greets them by their own first name, as the mails do.
$mine=$staff?null:($students[0]??null);
// A child is put into a course, so a portal with no real course yet has
// nothing to put a child into; the first step there is the course. The same
// question the checklist's „Ersten Kurs anlegen" asks, through the same function.
$hasCourse=$staff && real_course_exists();
page_head(t('Hallo ','Hello ').($staff?explode(' ',$user['name'])[0]:greeting_name($user)),
    $staff?t('Übersicht über Schüler, Beiträge und Abwesenheiten.','Students, charges and absences at a glance.'):t('Deine Termine, Beiträge und Nachrichten.','Your dates, payments and messages.'),
    $staff?($hasCourse?link_button(t('+ Schüler anlegen','+ Add student'),'student_new',['from'=>'dashboard']):''):($mine?link_button(t('Nachricht schreiben','Write message'),'messages',['new'=>1]):''));
if(!$staff):
    /* The family's page, in the order they read it: who, what is owed, when is
       training, what is new. Every part the full width - there is one of
       each, and nothing to put beside it. */
    if(!$mine) {
        empty_state(t('Hier ist noch nichts','Nothing here yet'),
            t('Zu diesem Zugang gehört gerade keine Mitgliedschaft. Schreib deiner Trainerin, wenn das nicht stimmt.','No membership belongs to this login at the moment. Write to your coach if that is not right.'),
            link_button(t('Nachricht schreiben','Write message'),'messages',['new'=>1]),'person');
    } else {
        /* What the family still has to fill in, first: the same list as on
           their Profil tab (ADR 0020, §7), each step a way to where it is
           done. It disappears once nothing is left. */
        next_steps_card(family_next_steps((int)$mine['id']),t('Noch zu ergänzen','Still to fill in')); ?>
<div class="card own-student"><?php student_card($mine+['due_cents'=>$overdueBy[(int)$mine['id']]??0]); ?></div>
<div class="stats-grid single"><div class="stat"><span><?=e(t('Offen','Outstanding'))?></span><strong class="<?=$open?'due':''?>"><?=e(money($open))?></strong><small><?=e($open?t('Einzeln unter „Beiträge“ in deinem Profil','Itemised under “Payments” in your profile'):t('Alles bezahlt','All paid'))?></small><?=icon('wallet')?></div></div>
<?php /* Uploading the proof is voluntary and nobody will chase it, so it has to
         be offered at the moment it is easy - under the amount, on the page
         the family lands on - rather than waiting on a tab they never open. */
    if($open): ?>
<div class="setup-strip">
    <div><strong><?=e(t('Schon überwiesen?','Already transferred?'))?></strong>
        <p><?=e(t('Du kannst den Beleg hochladen, damit die Zahlung schneller zugeordnet wird. Freiwillig – die Trainerin sieht den Eingang auch so.','You can upload the proof so the payment is matched faster. Voluntary – your coach sees the money arrive either way.'))?></p></div>
    <?=link_button(t('Beleg hochladen','Upload the proof'),'student',['id'=>$mine['id'],'tab'=>'payments'],'secondary')?>
</div>
<?php endif;
    }
endif;
/* While the start checklist has something left, the overview says how much and
   what comes next - one card, not a redirect: she is not trapped on the
   checklist, and the numbers are the checklist's own (ADR 0011). */
if(is_admin($user) && setup_unfinished()): $setup=setup_progress();$left=$setup['total']-$setup['done'];$step=$setup['next']; ?>
<section class="card setup-card">
    <h2><?=e(t('Dein Portal einrichten','Set up your portal'))?></h2>
    <p><?=e(t('Noch ','').plural($left,'Schritt','Schritte','step left','steps left').'. '.t('Als Nächstes: ','Next: ').$step['what'].'.')?></p>
    <div class="row-actions">
        <a class="button" href="<?=e(url($step['page'],$step['params']+['#'=>(string)($step['anchor']??'')]))?>"><?=e(t('Weiter','Continue'))?></a>
        <?=link_button(t('Alle Schritte','All steps'),'start',[],'secondary')?>
    </div>
</section>
<?php endif ?>
<?php if($staff): ?>
<div class="stats-grid">
<div class="stat accent"><span><?=e(t('Aktive Schüler','Active students'))?></span><strong><?=$active?></strong><small><?=e(t('im Training','in training'))?></small><?=icon('users')?></div>
<div class="stat"><span><?=e(t('Offene Beiträge','Outstanding charges'))?></span><strong><?=e(money($open))?></strong><small><?=e(t('Noch nicht bestätigt','Not yet confirmed'))?></small><?=icon('wallet')?></div>
<div class="stat"><span><?=e(t('Davon überfällig','Of which overdue'))?></span><strong class="<?=$overdue?'due':''?>"><?=e(money($overdue))?></strong><small><?=e(t('Fälligkeit überschritten','Past the due date'))?></small><?=icon('calendar')?></div>
<div class="stat"><span><?=e(t('Heute abwesend','Absent today'))?></span><strong><?=$absent?></strong><small><?=e(t('Krank, Urlaub oder abgemeldet','Sick, away or unavailable'))?></small><?=icon('calendar')?></div>
</div>
<?php endif ?>
<?php
/* The timeline: what happened, what is on today, and what is next.
   Built for the question she actually opens the portal with - "when is the next
   training and where" - which used to need three clicks and a memory of which
   day a course runs on. Students see the courses they are in; the trainer sees
   all of them. */
$myClasses=$staff?null:array_map('intval',array_column(rows(
    'SELECT DISTINCT cs.class_id FROM class_students cs JOIN students s ON s.id=cs.student_id'
    .' WHERE s.account_id=? AND '.current_enrolment_sql(),[(int)$user['id']]),'class_id'));
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
<?php if($staff): ?><div class="dashboard-grid"><section class="card"><div class="section-heading"><h2><?=e(t('Schüler','Students'))?></h2><a href="<?=e(url('students'))?>"><?=e(t('Alle ansehen','View all'))?><?=icon('chevron')?></a></div>
<?php if(!$students&&!$hasCourse)empty_state(t('Noch keine Schüler','No students yet'),t('Leg zuerst einen Kurs an – Kinder werden in Kurse eingetragen.','Create a course first – children are put into courses.'),link_button(t('Ersten Kurs anlegen','Create the first course'),'classes',['new'=>1]),'calendar');
elseif(!$students)empty_state(t('Noch keine Schüler','No students yet'),t('Lege zuerst einen Schüler an. Einen Zugang lädst du danach auf seiner Seite ein.','Add your first student. You invite them in from their own page afterwards.'),link_button(t('Ersten Schüler anlegen','Add first student'),'student_new',['from'=>'dashboard']));else foreach(array_slice($students,0,6) as $s)student_card($s+['due_cents'=>$overdueBy[(int)$s['id']]??0,'course_price'=>$coursePrices[(int)$s['id']]??course_price_label([])]); ?>
</section><?php endif ?><?php /* All the news, from here: a family's bar no longer has „Neues" - news
         reaches them through the bell and this group. */ ?>
<section class="card news-panel"><div class="section-heading"><h2><?=e(t('Neuigkeiten','News'))?></h2><a href="<?=e(url('news'))?>"><?=e(t('Alle ansehen','View all'))?><?=icon('chevron')?></a></div>
<?php $items=rows('SELECT * FROM news WHERE published=1 ORDER BY updated_at DESC LIMIT 3');if(!$items):?><p class="muted"><?=e(t('Noch keine Neuigkeiten.','No news yet.'))?></p><?php else:foreach($items as $n):?><a class="news-summary" href="<?=e(url('news',['id'=>$n['id']]))?>"><small><?=e(fmt_date($n['updated_at']))?></small><h3><?=e($n['title'])?></h3><p><?=e(mb_substr($n['body'],0,140))?></p></a><?php endforeach;endif ?>
<?php if($staff):?><div class="quick-actions"><?=link_button(t('Neuigkeit schreiben','Write news'),'news',['new'=>1],'secondary')?></div><?php endif ?></section><?php if($staff): ?></div><?php endif ?>
