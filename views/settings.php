<?php
/**
 * Einstellungen: the technical administration of the portal itself.
 *
 * What is not here on purpose: levels, age groups, membership statuses and bank
 * details. Those are the trainer's daily business and live under Verwaltung,
 * where she can reach them without an administrator.
 */
$tab=$_GET['tab']??'';
$items=['portal'=>t('Portal','Portal'),'organisation'=>t('Betrieb','Business'),
        'smtp'=>'SMTP','privacy'=>t('Datenschutz','Privacy'),
        'feedback'=>t('Rückmeldungen','Reports').(unread_feedback()?' ('.unread_feedback().')':''),
        'system'=>t('System','System')];
if($tab!=='' && !isset($items[$tab]))$tab='portal';
page_head(t('Einstellungen','Settings'));
/* Opened from the menu, with no tab asked for: the pages that have no menu entry
   of their own start here (nav_owner()), then the tabs. */
if($tab===''):
    $setupSteps=setup_progress();$setupHidden=(bool)setting('setup_hidden');
    $hub=[['page'=>'manage','params'=>[],'what'=>t('Verwaltung','Management'),'why'=>t('Gruppen, Mitgliedschaft, Bankkonto.','Groups, membership, bank account.')],
          ['page'=>'accounts','params'=>[],'what'=>t('Konten','Accounts'),'why'=>t('Wer außer dir das Portal verwaltet.','Who else runs the portal.')],
          ['page'=>'history','params'=>[],'what'=>t('Änderungen','Changes'),'why'=>t('Was zuletzt geändert wurde, und von wem.','What was changed lately, and by whom.')],
          ['page'=>'start','params'=>[],'what'=>t('Einrichtung ansehen','Look at the setup'),'why'=>strtr(t('Die {total} Schritte zum Start. {done} von {total} erledigt.','The {total} steps to get started. {done} of {total} done.'),['{total}'=>$setupSteps['total'],'{done}'=>$setupSteps['done']]),'setup'=>true],
          ['page'=>'settings','params'=>['tab'=>'system','open'=>'advanced'],'anchor'=>'advanced','what'=>t('Erweitert','Advanced'),'why'=>t('Selten gebraucht: Hintergrundaufgaben, wie lange Änderungen bleiben.','Rarely needed: background work, how long changes are kept.')]]; ?>
<section class="card settings-hub"><div class="grid two hub-grid">
<?php foreach($hub as $item): ?>
    <div class="hub-item">
        <a class="editor-list-item" href="<?=e(url($item['page'],$item['params']+['#'=>$item['anchor']??'']))?>"><span><strong><?=e($item['what'])?></strong><small><?=e($item['why'])?></small></span><?=icon('arrow')?></a>
        <?php if(!empty($item['setup']) && $setupHidden){start_form('setup_visibility',['hidden'=>'0'],'inline-form hub-action');submit_button(t('Wieder anzeigen','Show again'),'secondary');echo '</form>';} ?>
    </div>
<?php endforeach ?>
</div></section>
<?php endif;
tabs($items,$tab,'settings');
// Plain statements rather than extra branches, because PHP will not parse a
// braced if as the last statement of an alternative-syntax elseif branch.
if($tab==='system') require ROOT.'/views/_settings_system.php';
if($tab==='organisation') require ROOT.'/views/_organisation.php';
if($tab==='feedback') require ROOT.'/views/_feedback.php';
if(in_array($tab,['portal','organisation','system'],true)) require ROOT.'/views/_settings_registry.php';
// Portal: its settings, then the club's look - the logo, the colours with the
// switches for the name beside the logo - then the icon.
if($tab==='portal'){ require ROOT.'/views/_portal_logo.php'; require ROOT.'/views/_branding.php'; require ROOT.'/views/_portal_icon.php'; }
if($tab==='smtp'):$smtp=setting('smtp',[]); ?>
<section class="card"><h2><?=e(t('Ausgehende E-Mails','Outgoing email'))?></h2><?php start_form('smtp_save');?><div class="grid two"><?php input('host',t('SMTP-Server','SMTP server'),$smtp['host']??'','text',true);input('port',t('Port','Port'),$smtp['port']??587,'number',true);select_field('encryption',t('Verschlüsselung','Encryption'),['tls'=>'STARTTLS','ssl'=>'TLS / SSL'],$smtp['encryption']??'tls',true);input('username',t('SMTP-Benutzername','SMTP username'),$smtp['username']??'');?><div class="field"><label for="smtp_password"><?=e(t('SMTP-Passwort','SMTP password'))?></label><input id="smtp_password" name="smtp_password" type="password" autocomplete="new-password"><small><?=e(!empty($smtp['password'])?t('Gespeichert. Leer lassen, um es beizubehalten.','Saved. Leave blank to keep it.'):t('Noch kein Passwort gespeichert.','No password saved.'))?></small></div><?php input('from_email',t('Absenderadresse (No-Reply)','Sender address (no-reply)'),$smtp['from_email']??'','email',true);input('from_name',t('Absendername','Sender name'),$smtp['from_name']??setting('club_name','Badminton'),'text',true);?></div><?php check_field('clear_password',t('Gespeichertes SMTP-Passwort entfernen','Remove saved SMTP password'));submit_button();?></form></section>
<?php /* The test used to queue a message and send the operator to the outbox to
         look for it. A wrong port, a blocked outgoing connection and a rejected
         password all looked the same from there: nothing arrived, and nothing
         said why. Now the connection is opened while she waits and every step
         is written down, because "which step failed" is the whole answer. */
$test=setting('smtp_last_test',[]); ?>
<section class="card mail-test"><h2><?=e(t('Verbindung testen','Test the connection'))?></h2>
<p class="muted"><?=e(t('Öffnet die Verbindung zum Mailserver, meldet sich an und schreibt jeden Schritt mit. Auf Wunsch geht danach eine echte Testmail an eine Adresse deiner Wahl.','Opens the connection to the mail server, signs in, and writes down every step. It can then send a real test email to any address you like.'))?></p>
<?php start_form('smtp_test');
input('test_email',t('Testmail an diese Adresse','Send the test email to'),$test['sent_to']??$user['email'],'email',false,
      t('Beliebige Adresse – am besten ein Postfach, das du gerade offen hast.','Any address – best one you have open right now.'));
?><div class="row-actions">
<?php submit_button(t('Verbindung prüfen und Testmail senden','Test the connection and send'),'primary','mode','send');
submit_button(t('Nur Verbindung prüfen','Only test the connection'),'secondary','mode','connect'); ?>
</div></form>
<?php if($test): ?>
<div class="test-result">
    <div class="section-heading"><h3><?=e(t('Letzter Test','Last test'))?></h3><?php $tested=smtp_tested_ok();badge($tested?t('Erfolgreich','Worked'):t('Fehlgeschlagen','Failed'),$tested?'green':'red');?></div>
    <p><?=e((string)($test['summary']??''))?></p>
    <p class="muted"><?=e(fmt_datetime((string)($test['at']??now())))?></p>
    <?php if(($test['transcript']??'')!==''): ?>
    <details><summary><?=e(t('Gespräch mit dem Server anzeigen','Show the conversation with the server'))?></summary>
        <pre class="transcript"><?=e((string)$test['transcript'])?></pre>
        <p class="muted"><?=e(t('Benutzername und Passwort sind hier entfernt. Den Rest kannst du deinem Hoster schicken.','The user name and the password are removed here. The rest can go to your host.'))?></p>
    </details>
    <?php endif ?>
    <?php /* A failed send is noticed here, so the list of what went out and
             what did not is one tap away. */ ?>
    <p class="test-outbox"><a href="<?=e(url('outbox'))?>"><?=e(t('Postausgang ansehen','Open the outbox'))?></a></p>
</div>
<?php endif ?>
</section>
<?php elseif($tab==='privacy'): ?>
<section class="card"><h2><?=e(t('Datenschutzerklärung','Privacy notice'))?></h2><p class="muted"><?=e(t('Den Entwurf an das tatsächliche Hosting und den E-Mail-Dienst anpassen. Beide Sprachfassungen sind öffentlich vor der Anmeldung erreichbar.','Adapt the draft to the actual hosting and email service. Both languages are accessible before sign-in.'))?></p>
<div class="notice"><?=e(t('Name und Anschrift kommen aus „Betrieb“ und müssen hier nicht getippt werden. Diese Platzhalter werden beim Anzeigen ersetzt: ','The name and address come from “Betrieb” and do not have to be typed here. These placeholders are filled in when the notice is shown: '))?><code><?=e(implode(' ',array_keys(privacy_placeholders())))?></code></div><?php start_form('privacy_save');input('privacy_de','Deutsch',setting('privacy_de',''),'textarea',true);input('privacy_en',t('English (freiwillig)','English (optional)'),setting('privacy_en',''),'textarea',false,t('Leer lassen, wenn es keine englische Fassung gibt. Wer das Portal auf Englisch nutzt, sieht dann die deutsche, mit einem Hinweis darauf.','Leave it empty if there is no English version. Whoever uses the portal in English then sees the German one, with a note saying so.'));check_field('privacy_ready',t('Die Datenschutzerklärung ist vollständig und zur Verwendung freigegeben.','The privacy notice is complete and approved for use.'),(bool)setting('privacy_ready',false));submit_button();?></form></section>
<?php endif ?>
