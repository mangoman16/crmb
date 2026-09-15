<?php
$staff=is_staff($user);$f=filters_from($_GET);
if($staff && !empty($_GET['saved'])){$saved=one('SELECT * FROM saved_filters WHERE id=?',[(int)$_GET['saved']]);if($saved)$f=json_decode($saved['criteria_json'],true);}
$all=filtered_students($f,$staff?null:(int)$user['id']);$pageNum=max(1,(int)($_GET['p']??1));$visible=array_slice($all,($pageNum-1)*50,50);
page_head(t('Schüler','Students'),count($all).' '.t('in dieser Auswahl','in this selection'),$staff?link_button(t('+ Schüler anlegen','+ Add student'),'student'):'');
if($staff): ?>
<div class="saved-filters"><a class="chip" href="<?=e(url('students'))?>"><?=e(t('Alle Schüler','All students'))?></a><a class="chip" href="<?=e(url('students',['overdue'=>1]))?>"><?=e(t('Überfällige Beiträge','Overdue charges'))?></a><a class="chip" href="<?=e(url('students',['absence'=>'sick']))?>"><?=e(t('Aktuell krank','Currently sick'))?></a><?php foreach(rows('SELECT * FROM saved_filters ORDER BY name') as $saved):?><a class="chip <?=(int)($_GET['saved']??0)===(int)$saved['id']?'selected':''?>" href="<?=e(url('students',['saved'=>$saved['id']]))?>"><?=e($saved['name'])?></a><?php endforeach ?></div>
<?php // A saved filter can now be removed; until this existed the only way was
      // to edit the database by hand.
$activeFilter=(int)($_GET['saved']??0);
if($activeFilter && one('SELECT id FROM saved_filters WHERE id=?',[$activeFilter])):
    start_form('filter_delete',['id'=>$activeFilter],'inline-form');
    submit_button(t('Diesen Filter löschen','Delete this filter'),'subtle danger-text');
    echo '</form>';
endif ?>
<div class="card filter-card"><?php render_filters($f); ?><details><summary><?=e(t('Filter speichern','Save filter'))?></summary><?php start_form('filter_save',$f);input('name',t('Name des Filters','Filter name'),'','text',true);submit_button();?></form></details></div>
<div class="list-toolbar"><span><?=e(t('Nach Nachnamen sortiert','Sorted by last name'))?></span><?=link_button(t('Auswahl anschreiben','Message this selection'),'compose',$f,'secondary')?></div>
<?php endif ?>
<div class="card"><?php if(!$all)empty_state(t('Keine Schüler gefunden.','No students found.'),t('Passe die Filter an oder lege einen Schüler an.','Adjust the filters or add a student.'));else foreach($visible as $s)student_card($s);?></div>
<?php if(count($all)>50):?><nav class="pagination"><?php if($pageNum>1)echo link_button(t('Zurück','Previous'),'students',$f+['p'=>$pageNum-1],'secondary');?><span><?=$pageNum?> / <?=ceil(count($all)/50)?></span><?php if($pageNum*50<count($all))echo link_button(t('Weiter','Next'),'students',$f+['p'=>$pageNum+1],'secondary');?></nav><?php endif ?>
