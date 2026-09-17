<?php
/**
 * The change log.
 *
 * Informative, and only that. It used to offer an undo, which wrote old values
 * straight back into a record - dangerous next to a list of every change ever
 * made, because a later edit to the same row goes back with it, and expensive,
 * because it meant keeping a full copy of every record on every save. What is
 * left is the thing anybody actually opened it for: what changed, when, who did
 * it, and what it was before.
 */
$entity=(string)($_GET['entity']??'');
$recordId=(int)($_GET['record']??0);
$scoped=$entity!=='' && $recordId>0 && isset(tracked_entities()[$entity]);
$versions=$scoped?history_for($entity,$recordId):history_recent(80);

page_head(
    t('Änderungen','Changes'),
    $scoped?t('Alle Änderungen an diesem Eintrag.','Every change to this record.')
           :t('Was zuletzt geändert wurde, und von wem.','What changed recently, and who changed it.'),
    $scoped?link_button(t('Alle Änderungen','All changes'),'history',[],'secondary'):''
);

if(!$versions): empty_state(t('Noch keine Änderungen','No changes yet'),
    t('Sobald jemand etwas ändert, steht hier was es war und was daraus wurde.','Once somebody changes something, this says what it was and what it became.'));
else: ?>
<p class="muted"><?=e(t('Zum Nachlesen, nicht zum Zurücknehmen. Einträge, die älter sind als ','To read, not to undo. Entries older than ')
    .plural((int)setting('history_months'),'Monat','Monate','month','months')
    .t(', werden nachts entfernt; das Prüfprotokoll bleibt vollständig.',' are removed overnight; the audit log stays complete.'))?></p>
<div class="card">
<?php foreach($versions as $v):
    $changes=version_changes($v);
    $operation=['insert'=>t('Angelegt','Created'),'update'=>t('Geändert','Changed'),
                'delete'=>t('Gelöscht','Deleted'),'revert'=>t('Zurückgenommen','Undone')][$v['operation']]??$v['operation'];
?>
    <div class="history-row">
        <div class="history-what">
            <strong><?=e($operation)?>: <?=e(entity_label($v['entity']))?><?=$v['label']!==''?' · '.e($v['label']):''?></strong>
            <small><?=e(fmt_datetime($v['created_at']))?><?=$v['actor_name']?' · '.e($v['actor_name']):' · '.e(t('automatisch','automatically'))?></small>
            <?php if($changes): ?>
            <?php /* Open for a single record's history, folded on the list of
                     everything: on the list it is the headline that is being
                     scanned, and forty open panels is not a page. */ ?>
            <details <?=$scoped?'open':''?>>
                <summary><?=e(plural(count($changes),'Feld geändert','Felder geändert','field changed','fields changed'))?></summary>
                <dl class="facts">
                <?php foreach($changes as $column=>$pair): ?>
                    <div>
                        <dt><?=e(history_field_label((string)$column))?></dt>
                        <dd><span class="was"><?=e(history_value($pair['from']))?></span> → <?=e(history_value($pair['to']))?></dd>
                    </div>
                <?php endforeach ?>
                </dl>
            </details>
            <?php endif ?>
        </div>
        <?php if(!$scoped): ?>
        <div class="row-actions"><?=link_button(t('Verlauf dieses Eintrags','This record’s history'),'history',['entity'=>$v['entity'],'record'=>$v['entity_id']],'subtle')?></div>
        <?php endif ?>
    </div>
<?php endforeach ?>
</div>
<?php endif ?>
