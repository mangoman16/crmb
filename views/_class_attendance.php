<?php
// Attendance for one class. Built for a phone held in one hand during training:
// the whole class on one screen, one tap per student, one save at the end.
$on=date_value($_GET['on']??'')??attendance_suggested_date($form);
$members=array_filter(class_members($id),fn($m)=>$m['left_on']===null||$m['left_on']>=$on);
$recorded=attendance_for_session($id,$on);
$reported=absences_on(array_map(fn($m)=>(int)$m['id'],$members),$on);
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
    <?php /* Reached both from Kurse and from the Anwesenheit page, so every link
             here goes back to whichever one it was opened from rather than
             always landing on the course. */
    $back=current_page()==='attendance'?['page'=>'attendance','id'=>$id]:['page'=>'classes','id'=>$id,'tab'=>'attendance']; ?>
    <form method="get" class="row-form">
    <?php foreach($back as $key=>$value): ?>
        <input type="hidden" name="<?=e($key)?>" value="<?=e($value)?>">
    <?php endforeach ?>
        <?php input('on',t('Trainingstag','Training day'),$on,'date',true); ?>
        <?php submit_button(t('Tag wechseln','Change day'),'secondary'); ?>
    </form>

<?php if(!$members): ?>
    <p class="muted"><?=e(t('Für diesen Tag sind keine Teilnehmer im Kurs.','Nobody is in this class on that day.'))?></p>
<?php else:
    /* A photo taken right here (ADR 0031): the camera face below saves the
       marks made so far and comes back with photo={id}, which opens this sheet
       over the list - the rear camera at once, because she is photographing the
       child in front of her. Outside the attendance form, as its own form. Only
       for a child in today's list who has no photo yet. */
    $photoFor=(int)($_GET['photo']??0);
    $child=null;
    if(pictures_possible()) foreach($members as $m) if((int)$m['id']===$photoFor && !has_picture($m)) $child=$m;
    if($child):
        $childLogin=$child['account_id']?one('SELECT * FROM accounts WHERE id=?',[(int)$child['account_id']]):null;
        $photoOf=strtr(t('Foto von {name}','Photo of {name}'),['{name}'=>$child['first_name']]); ?>
    <details class="picture-sheet" data-sheet data-sheet-title="<?=e($photoOf)?>" open>
        <summary><?=e($photoOf)?></summary>
        <div class="picture-sheet-face"><?=avatar($child,'large')?></div>
        <?php picture_form(['student_id'=>(int)$child['id'],'class_id'=>$id,'on'=>$on],'button picture-pick',
                           function(): void { echo icon('camera').e(t('Foto aufnehmen','Take a photo')); },true); ?>
        <p class="picture-note"><?=e(login_without_sign_in($childLogin?:null)
            ?t('Die Familie sieht es, sobald sie sich angemeldet hat.','The family sees it once they have signed in.')
            :strtr(t('Die Familie von {name} bekommt einen Hinweis und kann es ändern oder entfernen.','{name}’s family is told and can change or remove it.'),['{name}'=>$child['first_name']]))?></p>
    </details>
    <?php endif ?>
    <?php start_form('attendance_save',['class_id'=>$id,'session_on'=>$on]);
    /* Enter in a form presses its first submit button, and the faces below are
       submit buttons too: so the first one is this copy of „Anwesenheit
       speichern", and Enter saves, as it always did. Hidden, not removed: a
       browser skips a default button that is not drawn at all and takes the
       next, which would be a face. */
    submit_button(t('Anwesenheit speichern','Save attendance'),'default-submit');
    /* Most of a class is present, so the first status is offered for all at
       once, and she corrects the handful who are not (the audit, N3). The
       others - a whole class away is rare, and deliberate - are in a sheet
       behind „Mehr"; the statuses are the club's own, so a fourth lands there by
       itself. Hidden without JavaScript, where they would do nothing. */
    $firstStatus=attendance_default_status(); ?>
    <div class="attendance-tools" role="group" aria-label="<?=e(t('Alle auf einmal','Everyone at once'))?>" hidden data-needs-js>
        <button type="button" class="button secondary" data-mark-all="<?=e($firstStatus)?>"><?=e(t('Alle: ','All: ').$statuses[$firstStatus])?></button>
        <?php if(count($statuses)>1): ?>
        <details class="attendance-more" data-sheet>
            <summary class="button subtle"><?=e(t('Mehr','More'))?></summary>
            <p><?=e(t('Für die ganze Liste. Gespeichert wird erst mit „Anwesenheit speichern“.','For the whole list. Nothing is saved until “Save attendance”.'))?></p>
            <?php foreach(array_slice($statuses,1,null,true) as $code=>$label): ?>
            <button type="button" class="button secondary" data-mark-all="<?=e($code)?>"><?=e(t('Alle: ','All: ').$label)?></button>
            <?php endforeach ?>
        </details>
        <?php endif ?>
    </div>
    <div class="attendance-list">
    <?php foreach($members as $m): $sid=(int)$m['id'];
        $current=$recorded[$sid]['status'] ?? ''; ?>
        <div class="attendance-row">
            <div class="attendance-name">
                <?php /* A child with no photo yet: the initials with a camera are a
                         button of this form, so the marks made so far are saved
                         before the photo is taken. A face with a photo does
                         nothing here; it is changed on the child's page. Without
                         gd no photo can be made, and there is no camera. */ ?>
                <?=has_picture($m) || !pictures_possible()?avatar($m):'<button class="avatar-add" type="submit" name="photo" value="'.$sid.'" aria-label="'.e(strtr(t('Foto von {name} aufnehmen','Take a photo of {name}'),['{name}'=>$m['first_name']])).'">'.avatar($m).icon('camera').'</button>'?>
                <span><strong><?=e($m['first_name'])?></strong><small><?=e(isset($reported[$sid])?$m['last_name'].' · '.reason_label($reported[$sid]).t(' gemeldet',' reported'):$m['last_name'])?></small></span>
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
        <a class="chip <?=$d===$on?'is-on':''?>" href="<?=e(url(current_page(),array_diff_key($back,['page'=>1])+['on'=>$d]))?>"><?=e(fmt_date($d))?></a>
    <?php endforeach ?>
    </div>
    <?php if(isset($recorded) && $recorded): ?>
    <details class="danger-zone" data-sheet>
        <summary><?=e(t('Eintrag für diesen Tag löschen','Delete the entry for this day'))?></summary>
        <?php start_form('attendance_clear',['class_id'=>$id,'session_on'=>$on],'inline-form');
              submit_button(t('Ja, Tag löschen','Yes, delete this day'),'danger'); ?></form>
    </details>
    <?php endif ?>
</section>
<?php endif ?>
