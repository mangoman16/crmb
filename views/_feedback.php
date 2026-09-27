<?php
/**
 * What people said was broken.
 *
 * Kept in the portal rather than emailed, because the one time this matters is
 * when email is the thing that is broken. Everything technical was collected by
 * the server at the moment of the report, so nobody had to be asked what browser
 * they use or which page they were on.
 *
 * Where it was sent from and what came just before are in plain view; the rest
 * of the way there - every step, what was typed, the device - is one tap away
 * and shut until then, because the words of the report are what to read first.
 * report_trail() reads both shapes a report can be stored in (ADR 0009).
 */
$reports = open_feedback();
?>
<section class="card">
    <h2><?=e(t('Rückmeldungen','Reports'))?></h2>
    <p class="muted"><?=e(t('Jede Seite hat unten einen Knopf „Etwas funktioniert hier nicht“. Was hier ankommt, ist die Meldung, dazu die Seite, die Schritte davor, das Gerät, die Version und der Zeitpunkt.','Every page has a “Something is wrong on this page” button at the bottom. What arrives here is the report, plus the page, the steps before it, the device, the version and the time.'))?></p>
    <?php if(!$reports): ?><p class="muted"><?=e(t('Nichts gemeldet. Das ist die gute Nachricht.','Nothing reported. That is the good news.'))?></p><?php endif ?>
    <?php foreach($reports as $r):
        $context=json_decode((string)$r['context_json'],true); if(!is_array($context))$context=[];
        $trail=report_trail($context);
        $device=is_scalar($context['user_agent']??null)?(string)$context['user_agent']:'';
        $browserLanguage=is_scalar($context['language']??null)?(string)$context['language']:'';
        $technical=$trail['recorded'] || $device!=='' || $browserLanguage!==''; ?>
    <details class="mail-item" <?=$r['state']==='new'?'open':''?>>
        <summary>
            <span><strong><?=e($r['account_name'] ?: t('Nicht angemeldet','Not signed in'))?></strong>
                <small><?=e(($r['page']?:'?').' · '.fmt_datetime((string)$r['created_at']))?></small></span>
            <span><?php badge(['new'=>t('Neu','New'),'seen'=>t('Gesehen','Seen'),'done'=>t('Erledigt','Done')][$r['state']] ?? $r['state'],
                              $r['state']==='new'?'red':($r['state']==='done'?'green':'amber'));?></span>
        </summary>
        <div class="prewrap"><?=e($r['message'])?></div>
        <dl class="facts">
            <div class="fact-wide"><dt><?=e(t('Adresse','Address'))?></dt><dd class="mono"><?=e($trail['address'])?></dd></div>
            <?php /* An address is set in the characters it is made of; "von außerhalb"
                     is a sentence, and set the same way it would look like one. */ ?>
            <div class="fact-wide"><dt><?=e(t('Davor','Before that'))?></dt><?php if(($trail['before']??'')!==''): ?><dd class="mono"><?=e($trail['before'])?></dd><?php else: ?><dd><?=e($trail['before']===null?t('nicht erfasst','not recorded'):t('von außerhalb','from outside'))?></dd><?php endif ?></div>
            <?php foreach(['page'=>t('Seite','Page'),'version'=>t('Version','Version'),'php'=>'PHP',
                           'locale'=>t('Sprache','Language'),'ip'=>t('IP-Adresse','IP address')] as $key=>$label):
                if(!is_scalar($context[$key]??null) || (string)$context[$key]==='')continue; ?>
            <div><dt><?=e($label)?></dt><dd><?=e((string)$context[$key])?></dd></div>
            <?php endforeach ?>
        </dl>
        <?php if($r['screenshot_name']): ?>
        <p><a href="<?=e(url('download',['what'=>'shot','id'=>$r['id']]))?>"><?=e(t('Bildschirmfoto ansehen','See the screenshot'))?></a></p>
        <?php endif ?>
        <?php if($technical): ?>
        <?php /* Shut even on a new report: it is the part read second, and on a
                 phone eight steps of monospace would push the buttons below
                 out of reach of the words above them. */ ?>
        <details class="report-trail">
            <summary><?=e($trail['recorded']
                ?t('Technische Einzelheiten','Technical details').' ('.plural(count($trail['steps']),'Schritt','Schritte','step','steps').')'
                :t('Technische Einzelheiten','Technical details'))?></summary>
            <?php if($trail['steps']): ?>
            <ol class="trail">
                <?php foreach($trail['steps'] as $i=>$step): ?>
                <li>
                    <div class="badge-line"><span class="trail-time"><?=e($step['time'])?></span><?php if($i===$trail['here'])badge(t('hier gemeldet','reported here'),'amber');?></div>
                    <p class="trail-line"><?=e($step['line'])?></p>
                    <?php if($step['flash']!==''): ?><p class="trail-flash<?=$step['flash_kind']==='error'?' is-error':''?>"><?=e($step['flash'])?></p><?php endif ?>
                </li>
                <?php endforeach ?>
            </ol>
            <?php endif ?>
            <?php if($trail['recorded'] && $trail['here']===null): ?>
            <?php /* The back button shows a page without asking for it again, so
                     the page the report came from is not always among the steps. */ ?>
            <p class="muted trail-note"><?=e(t('Gemeldet von ','Reported from ').$trail['address'].t(' – diese Seite wurde ohne neuen Aufruf gezeigt, etwa mit „Zurück“.',' – shown without a new request, for example with “Back”.'))?></p>
            <?php endif ?>
            <?php if(isset($context['values_dropped_at'])): ?>
            <p class="muted trail-note"><?=e(t('Die eingetippten Werte wurden beim Erledigen gelöscht.','The typed values were deleted when this was marked done.'))?></p>
            <?php endif ?>
            <dl class="facts">
                <?php if($device!==''): ?><div class="fact-wide"><dt><?=e(t('Gerät','Device'))?></dt><dd class="mono trail-device"><?=e($device)?></dd></div><?php endif ?>
                <?php if($browserLanguage!==''): ?><div><dt><?=e(t('Browsersprache','Browser language'))?></dt><dd><?=e($browserLanguage)?></dd></div><?php endif ?>
            </dl>
        </details>
        <?php endif ?>
        <div class="row-actions">
        <?php foreach(['seen'=>t('Gesehen','Seen'),'done'=>t('Erledigt','Done'),'new'=>t('Wieder offen','Open again')] as $state=>$label):
            if($state===$r['state'])continue;
            start_form('feedback_state',['id'=>$r['id'],'state'=>$state],'inline-form');
            submit_button($label,'subtle'); echo '</form>';
        endforeach ?>
        </div>
        <?php /* Said before the tap rather than after it: what was typed is gone
                 for good once the report is done, and "Wieder offen" does not
                 bring it back - and the whole report goes some days later, which
                 is true of every report, so it is said on every open one. The
                 number is the one the clean-up uses, not a copy of it. */
        if($r['state']!=='done'): ?>
        <p class="muted report-done-note"><?=e(t('Beim Erledigen werden die eingetippten Werte gelöscht; nach ','Marking it done deletes the typed values; after ')
            .FEEDBACK_DONE_KEEP_DAYS.t(' Tagen wird die ganze Meldung samt Bildschirmfoto gelöscht.',' days the whole report, screenshot included, is deleted.'))?></p>
        <?php endif ?>
    </details>
    <?php endforeach ?>
</section>
