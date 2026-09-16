<?php
// Recent changes, with the option to take one back. Admin only: reverting writes
// values straight back into a record, which is not a trainer's decision.
$entity=(string)($_GET['entity']??'');
$recordId=(int)($_GET['record']??0);
$scoped=$entity!=='' && $recordId>0 && isset(tracked_entities()[$entity]);
$versions=$scoped?history_for($entity,$recordId):history_recent();

page_head(
    t('Änderungen','Changes'),
    $scoped?t('Alle Änderungen an diesem Eintrag.','Every change to this record.')
           :t('Was zuletzt geändert wurde – und wie man es zurücknimmt.','What changed recently, and how to take it back.'),
    $scoped?link_button(t('Alle Änderungen','All changes'),'history',[],'secondary'):''
);

if(!$versions): empty_state(t('Noch keine Änderungen','No changes yet'),
    t('Sobald etwas geändert wird, steht es hier und kann zurückgenommen werden.','Once something changes it appears here and can be undone.'));
else: ?>
<div class="card">
<?php foreach($versions as $v):
    $changes=version_changes($v);
    $undone=$v['reverted_at']!==null;
    $operation=['insert'=>t('Angelegt','Created'),'update'=>t('Geändert','Changed'),
                'delete'=>t('Gelöscht','Deleted'),'revert'=>t('Zurückgenommen','Undone')][$v['operation']]??$v['operation'];
?>
    <div class="history-row<?=$undone?' is-past':''?>">
        <div class="history-what">
            <strong><?=e($operation)?>: <?=e(entity_label($v['entity']))?><?=$v['label']!==''?' · '.e($v['label']):''?></strong>
            <small><?=e(fmt_datetime($v['created_at']))?><?=$v['actor_name']?' · '.e($v['actor_name']):''?></small>
            <?php if($changes): ?>
            <details>
                <summary><?=e(plural(count($changes),'Feld geändert','Felder geändert','field changed','fields changed'))?></summary>
                <dl class="facts">
                <?php foreach($changes as $column=>$pair): ?>
                    <div>
                        <dt><?=e($column)?></dt>
                        <dd><span class="was"><?=e(history_value($pair['from']))?></span> → <?=e(history_value($pair['to']))?></dd>
                    </div>
                <?php endforeach ?>
                </dl>
            </details>
            <?php endif ?>
            <?php if($undone):?><small><?=e(t('Bereits zurückgenommen am ','Already undone on ').fmt_datetime($v['reverted_at']))?></small><?php endif ?>
        </div>
        <div class="row-actions">
        <?php if(!$undone && $v['operation']!=='revert'):
            start_form('version_revert',['id'=>$v['id']],'inline-form');
            submit_button(t('Rückgängig','Undo'),'secondary');
            echo '</form>';
        endif ?>
        <?php if(!$scoped)echo link_button(t('Verlauf','History'),'history',['entity'=>$v['entity'],'record'=>$v['entity_id']],'subtle'); ?>
        </div>
    </div>
<?php endforeach ?>
</div>
<?php endif ?>
