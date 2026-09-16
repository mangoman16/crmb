<?php
/**
 * Anwesenheit: pick a course, see every child, mark the list.
 *
 * Its own place rather than a tab inside one course, because this is the thing
 * she does courtside, in a hurry, on a phone - and getting to it used to mean
 * Kurse, then the right course, then a tab. Now it is one tap from anywhere, and
 * the course it opens on is the one that most recently met.
 */
$classes=training_classes();
$id=(int)($_GET['id']??0);
if(!$id && $classes) {
    // The course whose last meeting is closest to today, so walking off court
    // and opening this lands on the group she has just finished with.
    $best=null;
    foreach($classes as $c) {
        $when=attendance_suggested_date($c);
        if($best===null || $when>$best[1]) $best=[(int)$c['id'],$when];
    }
    $id=$best[0]??0;
}
$c=$id?training_class($id):null;

page_head(t('Anwesenheit','Attendance'),
    t('Kurs wählen, Tag prüfen, Liste abhaken.','Choose a course, check the day, tick the list.'),
    $c?link_button(t('Zum Kurs','Open the course'),'classes',['id'=>$id],'secondary'):'');

if(!$classes):
    empty_state(t('Noch keine Kurse.','No courses yet.'),
        t('Anwesenheit wird je Kurs erfasst. Lege zuerst einen Kurs an.','Attendance is recorded per course. Create a course first.'),
        link_button(t('Kurs anlegen','Create a course'),'classes',['new'=>1]));
else: ?>

<?php /* The course picker is chips rather than a dropdown: on a phone a chip is
         a 44-point target she can hit without looking, and a dropdown is two
         taps and a scroll. */ ?>
<div class="saved-filters">
<?php $pattern=class_days_for(array_column($classes,'id'));
foreach($classes as $row): ?>
    <a class="chip view-chip <?=$id===(int)$row['id']?'is-on':''?>" href="<?=e(url('attendance',['id'=>$row['id']]))?>">
        <strong><?=e($row['name'])?></strong><small><?=e(class_schedule($row,$pattern[(int)$row['id']]??[]))?></small></a>
<?php endforeach ?>
</div>

<?php if($c):
    // The per-course partial expects the course in $form and its id in $id,
    // which is exactly what the Kurse page gives it. One control, two places to
    // reach it from, rather than two controls that drift apart.
    $form=$c;
    require ROOT.'/views/_class_attendance.php';
endif ?>
<?php endif ?>
