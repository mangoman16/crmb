<?php
// Attendance for one class. Built for a phone held in one hand during training:
// the whole class on one screen, one tap per student, one save at the end.
$on=date_value((string)($_GET['on']??''))??attendance_suggested_date($form);
$members=array_filter(class_members($id),fn($m)=>$m['left_on']===null||$m['left_on']>=$on);
$recorded=attendance_for_session($id,$on);
$statuses=attendance_statuses();
$dates=attendance_session_dates($id);
?>
<section class="card">
    <div class="section-heading">
        <div>
            <h2><?=e(t('Anwesenheit','Attendance'))?></h2>
            <p class="muted"><?=e(t('Antippen. Am Ende einmal speichern.','Tap each one. Save once at the end.'))?></p>
        </div>
    </div>

    <?php // Changing the date is a GET, so an accidental tap cannot lose entries. ?>
    <form method="get" class="attendance-date">
        <input type="hidden" name="page" value="classes">
        <input type="hidden" name="id" value="<?=$id?>">
        <input type="hidden" name="tab" value="attendance">
        <?php input('on',t('Trainingstag','Training day'),$on,'date',true); ?>
        <?php submit_button(t('Tag wechseln','Change day'),'secondary'); ?>
    </form>

<?php if(!$members): ?>
    <p class="muted"><?=e(t('Für diesen Tag sind keine Teilnehmer im Kurs.','Nobody is in this class on that day.'))?></p>
<?php else: ?>
    <?php start_form('attendance_save',['class_id'=>$id,'session_on'=>$on]); ?>
    <?php // Most of a class is present, so offer the bulk choice first and let her
          // correct the handful who are not. Hidden without JavaScript, where it
          // would do nothing. ?>
    <div class="attendance-tools" hidden data-needs-js>
    <?php foreach($statuses as $code=>$label): ?>
        <button type="button" class="button secondary" data-mark-all="<?=e($code)?>"><?=e(t('Alle: ','All: ').$label)?></button>
    <?php endforeach ?>
    </div>
    <div class="attendance-list">
    <?php foreach($members as $m): $sid=(int)$m['id'];
        $current=$recorded[$sid]['status'] ?? ''; ?>
        <div class="attendance-row">
            <div class="attendance-name">
                <span class="avatar small"><?=e(mb_substr($m['first_name'],0,1).mb_substr($m['last_name'],0,1))?></span>
                <span><strong><?=e($m['first_name'])?></strong><small><?=e($m['last_name'])?></small></span>
                <?php $rid='att_'.$sid.'_none'; ?>
                <input class="clear-radio" type="radio" id="<?=e($rid)?>" name="present[<?=$sid?>]" value="" <?=$current===''?'checked':''?>>
                <label class="clear-mark" for="<?=e($rid)?>" title="<?=e(t('Nicht erfasst','Not recorded'))?>">
                    <span aria-hidden="true">–</span><span class="visually-hidden"><?=e($m['first_name'].': '.t('nicht erfasst','not recorded'))?></span>
                </label>
            </div>
            <?php // Radios styled as a segmented control: native behaviour, no
                  // JavaScript, and each label is a full-size tap target. ?>
            <div class="segmented" role="group" aria-label="<?=e($m['first_name'].' '.$m['last_name'])?>">
            <?php foreach($statuses as $code=>$label): $rid='att_'.$sid.'_'.preg_replace('/[^a-z0-9]/i','',$code); ?>
                <input type="radio" id="<?=e($rid)?>" name="present[<?=$sid?>]" value="<?=e($code)?>" <?=$current===(string)$code?'checked':''?>>
                <label for="<?=e($rid)?>" class="tone-<?=e(attendance_tone((string)$code))?>"><?=e($label)?></label>
            <?php endforeach ?>
            </div>
        </div>
    <?php endforeach ?>
    </div>
    <div class="form-footer sticky-save"><?php submit_button(t('Anwesenheit speichern','Save attendance')); ?></div>
    </form>
<?php endif ?>
</section>

<?php if($dates): ?>
<section class="card">
    <h2><?=e(t('Frühere Trainings','Earlier sessions'))?></h2>
    <div class="saved-filters">
    <?php foreach($dates as $d): ?>
        <a class="chip <?=$d===$on?'selected':''?>" href="<?=e(url('classes',['id'=>$id,'tab'=>'attendance','on'=>$d]))?>"><?=e(fmt_date($d))?></a>
    <?php endforeach ?>
    </div>
    <?php if(isset($recorded) && $recorded): ?>
    <details class="danger-zone">
        <summary><?=e(t('Eintrag für diesen Tag löschen','Delete the entry for this day'))?></summary>
        <?php start_form('attendance_clear',['class_id'=>$id,'session_on'=>$on],'inline-form');
              submit_button(t('Ja, Tag löschen','Yes, delete this day'),'danger'); ?></form>
    </details>
    <?php endif ?>
</section>
<?php endif ?>
