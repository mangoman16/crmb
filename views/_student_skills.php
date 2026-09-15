<?php
// Skills tab on the student page. Staff only - never reached by a student
// account, which has no tab link and is rejected by the tab whitelist.
$all=skills();
$scores=area_scores($id);
$latest=latest_assessments($id);
$entryDate=date_value((string)($_GET['on']??''))??today();
if(!$all): empty_state(t('Noch keine Fähigkeiten definiert.','No skills defined yet.'),
    t('Bereiche und Fähigkeiten werden in den Einstellungen angelegt.','Areas and skills are created in the settings.'),
    is_admin($user)?link_button(t('Fähigkeiten einrichten','Set up skills'),'settings',['tab'=>'skills'],'secondary'):'');
else: ?>
<?php if($scores): ?>
<div class="stats-grid compact">
<?php foreach($scores as $area): ?>
    <div class="stat">
        <span><?=e($area['name'])?></span>
        <strong><?=e(number_format($area['percent'],0).'%')?></strong>
        <small><?=e($area['band'])?> · <?=(int)$area['count']?> <?=e(t('Fähigkeiten','skills'))?></small>
    </div>
<?php endforeach ?>
</div>
<?php endif ?>

<?php // Group the entry form by area so it reads like a report card.
$byArea=[]; foreach($all as $skill) $byArea[$skill['area_name']][]=$skill; ?>
<?php start_form('assessment_save',['student_id'=>$id]); ?>
<section class="card">
    <div class="section-heading">
        <div>
            <h2><?=e(t('Bewertung erfassen','Record an assessment'))?></h2>
            <p class="muted"><?=e(t('Leer lassen heißt „nicht bewertet“. Ein zweiter Eintrag am selben Tag ersetzt den ersten.','Leave a field empty for “not assessed”. A second entry on the same day replaces the first.'))?></p>
        </div>
    </div>
    <div class="grid two"><?php input('assessed_on',t('Datum','Date'),$entryDate,'date',true); ?></div>
<?php foreach($byArea as $areaName=>$list): ?>
    <h3 class="area-heading"><?=e($areaName)?></h3>
    <?php foreach($list as $skill): $sid=(int)$skill['id']; $a=$latest[$sid]??null;
        $history=assessment_history($id,$sid);
        // Prefill only when editing the same date, so opening the form does not
        // silently re-save an older value under today's date.
        $current=$a && $a['assessed_on']===$entryDate ? rtrim(rtrim(number_format((float)$a['value'],2,'.',''),'0'),'.') : '';
        $note=$a && $a['assessed_on']===$entryDate ? (string)$a['note'] : ''; ?>
    <div class="skill-row">
        <div class="skill-meta">
            <strong><?=e($skill['name'])?></strong>
            <small><?=e($skill['scale_name'])?><?php if($a)echo ' · '.e(t('zuletzt ','last ').scale_format($skill,(float)$a['value']).' ('.fmt_date($a['assessed_on']).')');?></small>
            <?php if($skill['description'])echo '<small>'.e($skill['description']).'</small>';?>
        </div>
        <div class="skill-input">
            <?php select_field('skill['.$sid.']',t('Wert für ','Value for ').$skill['name'],scale_values($skill),$current);
                  input('skill_note['.$sid.']','',$note,'text',false,'',t('Notiz (optional)','Note (optional)')); ?>
        </div>
        <div class="skill-chart"><?=count($history)>1?progress_chart($skill,$history):''?></div>
    </div>
    <?php endforeach ?>
<?php endforeach ?>
    <div class="form-footer"><?php submit_button(t('Bewertungen speichern','Save assessments')); ?></div>
</section>
</form>

<?php // History, newest first, so a wrong entry can be found and removed.
$dates=rows('SELECT DISTINCT assessed_on FROM assessments WHERE student_id=? ORDER BY assessed_on DESC LIMIT 20',[$id]);
if($dates): ?>
<section class="card">
    <h2><?=e(t('Frühere Bewertungen','Earlier assessments'))?></h2>
    <?php foreach($dates as $d): $day=$d['assessed_on'];
        $entries=rows('SELECT a.*, s.name, s.area_id, r.min_value, r.max_value, r.step, r.labels_json, ar.name AS area_name
                       FROM assessments a JOIN skills s ON s.id=a.skill_id
                       JOIN rating_scales r ON r.id=s.scale_id JOIN skill_areas ar ON ar.id=s.area_id
                       WHERE a.student_id=? AND a.assessed_on=? ORDER BY ar.sort_order, s.sort_order',[$id,$day]); ?>
    <details>
        <summary><?=e(fmt_date($day))?> · <?=count($entries)?> <?=e(t('Werte','values'))?></summary>
        <?php foreach($entries as $x): ?>
        <div class="record-row">
            <div>
                <strong><?=e($x['name'])?></strong>
                <p><?=e($x['area_name'].' · '.scale_format($x,(float)$x['value']))?></p>
                <?php if($x['note'])echo '<small>'.e($x['note']).'</small>';?>
            </div>
            <?php start_form('assessment_delete',['student_id'=>$id,'skill_id'=>$x['skill_id'],'assessed_on'=>$day],'inline-form');
                  submit_button(t('Entfernen','Remove'),'subtle danger-text'); ?></form>
        </div>
        <?php endforeach ?>
        <?=link_button(t('Diesen Tag bearbeiten','Edit this day'),'student',['id'=>$id,'tab'=>'skills','on'=>$day],'secondary')?>
    </details>
    <?php endforeach ?>
</section>
<?php endif; endif ?>
