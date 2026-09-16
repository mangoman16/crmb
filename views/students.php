<?php
$staff=is_staff($user);$f=filters_from($_GET);
if($staff && !empty($_GET['saved'])){$saved=one('SELECT * FROM saved_filters WHERE id=?',[(int)$_GET['saved']]);if($saved)$f=json_decode($saved['criteria_json'],true);}
$all=filtered_students($f,$staff?null:(int)$user['id']);$pageNum=max(1,(int)($_GET['p']??1));$visible=array_slice($all,($pageNum-1)*50,50);
$overdueBy=balances(true);
page_head(t('Schüler','Students'),count($all).' '.t('in dieser Auswahl','in this selection'),$staff?link_button(t('+ Schüler anlegen','+ Add student'),'student'):'');
if($staff): ?>
<?php /* Saved filters are the "views" she asked for: a named selection she opens
         instead of rebuilding. Each chip carries what it selects underneath its
         name, because a chip called "Montag" says nothing a month later. */
$activeFilter=(int)($_GET['saved']??0);
$views=rows('SELECT * FROM saved_filters ORDER BY name'); ?>
<div class="saved-filters">
    <a class="chip <?=!$activeFilter&&!$f?'is-on':''?>" href="<?=e(url('students'))?>"><?=e(t('Alle Schüler','All students'))?></a>
    <a class="chip" href="<?=e(url('students',['overdue'=>1]))?>"><?=e(t('Überfällige Beiträge','Overdue charges'))?></a>
    <a class="chip" href="<?=e(url('students',['absence'=>'sick']))?>"><?=e(t('Aktuell krank','Currently sick'))?></a>
<?php foreach($views as $saved): $criteria=json_decode((string)$saved['criteria_json'],true); ?>
    <a class="chip view-chip <?=$activeFilter===(int)$saved['id']?'is-on':''?>" href="<?=e(url('students',['saved'=>$saved['id']]))?>">
        <strong><?=e($saved['name'])?></strong><small><?=e(filter_summary(is_array($criteria)?$criteria:[]))?></small></a>
<?php endforeach ?>
</div>
<?php if($activeFilter && one('SELECT id FROM saved_filters WHERE id=?',[$activeFilter])):
    start_form('filter_delete',['id'=>$activeFilter],'inline-form');
    submit_button(t('Diese Ansicht löschen','Delete this view'),'subtle danger-text');
    echo '</form>';
endif ?>
<div class="card filter-card"><?php render_filters($f); ?>
<details <?=$f&&!$activeFilter?'open':''?>><summary><?=e(t('Diese Auswahl als Ansicht speichern','Save this selection as a view'))?></summary>
    <p class="muted"><?=e(t('Gespeichert wird: ','This saves: '))?><strong><?=e(filter_summary($f))?></strong></p>
    <?php start_form('filter_save',$f);input('name',t('Name der Ansicht','Name of the view'),'','text',true,'',t('z. B. Montagsgruppe','e.g. Monday group'));submit_button(t('Ansicht speichern','Save the view'));?></form>
</details></div>
<div class="list-toolbar"><span><?=e(t('Nach Nachnamen sortiert','Sorted by last name'))?></span><?=link_button(t('Auswahl anschreiben','Message this selection'),'compose',$f,'secondary')?></div>
<?php endif ?>
<div class="card"><?php if(!$all)empty_state(t('Keine Schüler gefunden.','No students found.'),t('Passe die Filter an oder lege einen Schüler an.','Adjust the filters or add a student.'));else foreach($visible as $s)student_card($s+['due_cents'=>$overdueBy[(int)$s['id']]??0]);?></div>
<?php if(count($all)>50):?><nav class="pagination"><?php if($pageNum>1)echo link_button(t('Zurück','Previous'),'students',$f+['p'=>$pageNum-1],'secondary');?><span><?=$pageNum?> / <?=ceil(count($all)/50)?></span><?php if($pageNum*50<count($all))echo link_button(t('Weiter','Next'),'students',$f+['p'=>$pageNum+1],'secondary');?></nav><?php endif ?>
