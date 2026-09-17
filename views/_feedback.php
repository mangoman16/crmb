<?php
/**
 * What people said was broken.
 *
 * Kept in the portal rather than emailed, because the one time this matters is
 * when email is the thing that is broken. Everything technical was collected by
 * the server at the moment of the report, so nobody had to be asked what browser
 * they use or which page they were on.
 */
$reports = open_feedback();
?>
<section class="card">
    <h2><?=e(t('Rückmeldungen','Reports'))?></h2>
    <p class="muted"><?=e(t('Jede Seite hat unten einen Knopf „Etwas funktioniert hier nicht“. Was hier ankommt, ist die Meldung, dazu Seite, Gerät, Version und Zeitpunkt.','Every page has a “Something is wrong on this page” button at the bottom. What arrives here is the report, plus the page, the device, the version and the time.'))?></p>
    <?php if(!$reports): ?><p class="muted"><?=e(t('Nichts gemeldet. Das ist die gute Nachricht.','Nothing reported. That is the good news.'))?></p><?php endif ?>
    <?php foreach($reports as $r): $context=json_decode((string)$r['context_json'],true) ?: []; ?>
    <details class="mail-item" <?=$r['state']==='new'?'open':''?>>
        <summary>
            <span><strong><?=e($r['account_name'] ?: t('Nicht angemeldet','Not signed in'))?></strong>
                <small><?=e(($r['page']?:'?').' · '.fmt_datetime((string)$r['created_at']))?></small></span>
            <span><?php badge(['new'=>t('Neu','New'),'seen'=>t('Gesehen','Seen'),'done'=>t('Erledigt','Done')][$r['state']] ?? $r['state'],
                              $r['state']==='new'?'red':($r['state']==='done'?'green':'amber'));?></span>
        </summary>
        <div class="prewrap"><?=e($r['message'])?></div>
        <dl class="facts">
            <?php foreach(['page'=>t('Seite','Page'),'version'=>t('Version','Version'),'php'=>'PHP',
                           'locale'=>t('Sprache','Language'),'ip'=>t('IP-Adresse','IP address')] as $key=>$label):
                if(($context[$key]??'')==='')continue; ?>
            <div><dt><?=e($label)?></dt><dd><?=e((string)$context[$key])?></dd></div>
            <?php endforeach ?>
        </dl>
        <?php if(!empty($context['user_agent'])): ?>
        <p class="muted mono"><?=e((string)$context['user_agent'])?></p>
        <?php endif ?>
        <?php if(!empty($context['query'])): ?>
        <p class="muted"><?=e(t('Adresszeile: ','Query: ').http_build_query((array)$context['query']))?></p>
        <?php endif ?>
        <?php if($r['screenshot_name']): ?>
        <p><a href="<?=e(url('download',['what'=>'shot','id'=>$r['id']]))?>"><?=e(t('Bildschirmfoto ansehen','See the screenshot'))?></a></p>
        <?php endif ?>
        <div class="row-actions">
        <?php foreach(['seen'=>t('Gesehen','Seen'),'done'=>t('Erledigt','Done'),'new'=>t('Wieder offen','Open again')] as $state=>$label):
            if($state===$r['state'])continue;
            start_form('feedback_state',['id'=>$r['id'],'state'=>$state],'inline-form');
            submit_button($label,'subtle'); echo '</form>';
        endforeach ?>
        </div>
    </details>
    <?php endforeach ?>
</section>
