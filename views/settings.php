<?php
/**
 * Einstellungen: the technical administration of the portal itself.
 *
 * What is not here on purpose: levels, age groups, membership statuses, tariffs,
 * email templates and bank details. Those are the trainer's daily business and
 * live under Verwaltung, where she can reach them without an administrator.
 */
$tab=(string)($_GET['tab']??'portal');
$items=['portal'=>t('Portal','Portal'),'organisation'=>t('Betrieb','Business'),'fields'=>t('Eigene Felder','Custom fields'),
        'smtp'=>'SMTP','privacy'=>t('Datenschutz','Privacy'),
        'feedback'=>t('Rückmeldungen','Reports').(unread_feedback()?' ('.unread_feedback().')':''),
        'system'=>t('System','System')];
if(!isset($items[$tab]))$tab='portal';$edit=(int)($_GET['edit']??0);
page_head(t('Einstellungen','Settings'),t('Technische Verwaltung des Portals. Die Listen für den Trainingsalltag stehen unter „Verwaltung“.','Technical administration of the portal. The lists for day-to-day training are under “Verwaltung”.'));
tabs($items,$tab,'settings');
// Plain statements rather than extra branches, because PHP will not parse a
// braced if as the last statement of an alternative-syntax elseif branch.
if($tab==='system') require ROOT.'/views/_settings_system.php';
if($tab==='organisation') require ROOT.'/views/_organisation.php';
if($tab==='feedback') require ROOT.'/views/_feedback.php';
if(in_array($tab,['portal','organisation','system'],true)) require ROOT.'/views/_settings_registry.php';
if($tab==='fields'):
$f=$edit?one('SELECT * FROM field_definitions WHERE id=?',[$edit]):null;
?>
<div class="settings-grid"><section class="card"><div class="section-heading"><h2><?=e(t('Schülerfelder','Student fields'))?></h2><?=link_button(t('+ Neues Feld','+ New field'),'settings',['tab'=>'fields'],'secondary')?></div>
<?php foreach(field_definitions(true) as $field):?><a class="editor-list-item <?=$edit===(int)$field['id']?'selected':''?>" href="<?=e(url('settings',['tab'=>'fields','edit'=>$field['id']]))?>"><span><strong><?=e($field['label'])?></strong><small><?=e(['internal'=>t('Intern','Internal'),'view'=>t('Schüler können es sehen','Students can view'),'edit'=>t('Schüler können es bearbeiten','Students can edit')][$field['visibility']])?></small></span><?php if($field['archived'])badge(t('Archiviert','Archived'));else echo icon('arrow');?></a><?php endforeach ?>
<p class="muted"><?=e(t('Eigene Felder gibt es bewusst nur beim Schüler. Alles andere – Kurse, Tarife, Rechnungen, Beiträge – hat feste Felder, weil daran gerechnet und Recht gehängt wird: ein selbst angelegtes Feld in einer Rechnung wäre eine Rechnung, die niemand prüfen kann. Leistungsgruppen, Altersgruppen, Mitgliedschaftsstatus und Zahlungsarten sind stattdessen unter „Verwaltung“ frei änderbar.','Custom fields exist for students only, on purpose. Everything else – courses, tariffs, invoices, charges – has fixed fields, because they are calculated with and carry legal weight: a self-made field on an invoice would be an invoice nobody can check. Levels, age groups, membership statuses and payment methods are freely editable instead, under “Verwaltung”.'))?></p>
<p class="muted"><?=e(t('Umbenennen und Archivieren erhalten die bisherigen Werte. Vorgaben gelten für neue Schüler.','Renaming and archiving preserve existing values. Defaults apply to new students.'))?></p></section>
<section class="card"><h2><?=e($f?t('Feld bearbeiten','Edit field'):t('Feld hinzufügen','Add field'))?></h2><?php start_form('field_save',['id'=>$edit]);?><div class="grid two">
<?php input('label',t('Bezeichnung (Deutsch)','Label (German)'),$f['label']??'','text',true);input('label_en',t('Bezeichnung (Englisch)','Label (English)'),$f['label_en']??'');
select_field('field_type',t('Feldtyp','Field type'),['text'=>t('Kurzer Text','Short text'),'textarea'=>t('Langer Text','Long text'),'number'=>t('Zahl','Number'),'date'=>t('Datum','Date'),'select'=>t('Auswahlliste','Dropdown'),'multiselect'=>t('Mehrfachauswahl','Multiple choices'),'checkbox'=>t('Ja / Nein','Yes / No')],$f['field_type']??'text',true);
input('section_name',t('Abschnitt im Profil','Profile section'),$f['section_name']??'');input('sort_order',t('Reihenfolge (kleine Zahl zuerst)','Order (lowest number first)'),$f['sort_order']??0,'number');
select_field('visibility',t('Berechtigung für Schüler','Student permission'),['internal'=>t('Nur intern','Internal only'),'view'=>t('Ansehen','View'),'edit'=>t('Ansehen und bearbeiten','View and edit')],$f['visibility']??'internal',true);?></div>
<div data-field-options><?php input('options',t('Optionen, eine pro Zeile','Options, one per line'),implode("\n",json_decode($f['options_json']??'[]',true)),'textarea');?></div>
<div data-field-default><?php $def=json_decode($f['default_json']??'""',true);input('default_value',t('Vorgabewert (optional)','Default value (optional)'),is_array($def)?implode("\n",$def):(is_bool($def)?'':$def),($f['field_type']??'')==='multiselect'?'textarea':'text',false,t('Mehrfachauswahl: eine Option je Zeile.','Multiple choices: one option per line.'));?></div>
<div data-field-checkbox><?php check_field('default_checked',t('Standardmäßig angekreuzt','Checked by default'),$def===true);?></div>
<?php check_field('required',t('Pflichtfeld','Required field'),(bool)($f['required']??false));check_field('archived',t('Archivieren (Werte behalten)','Archive (keep values)'),(bool)($f['archived']??false));submit_button();?></form></section></div>
<?php elseif($tab==='smtp'):$smtp=setting('smtp',[]); ?>
<section class="card"><h2><?=e(t('Ausgehende E-Mails','Outgoing email'))?></h2><?php start_form('smtp_save');?><div class="grid two"><?php input('host',t('SMTP-Server','SMTP server'),$smtp['host']??'','text',true);input('port',t('Port','Port'),$smtp['port']??587,'number',true);select_field('encryption',t('Verschlüsselung','Encryption'),['tls'=>'STARTTLS','ssl'=>'TLS / SSL'],$smtp['encryption']??'tls',true);input('username',t('SMTP-Benutzername','SMTP username'),$smtp['username']??'');?><div class="field"><label for="smtp_password"><?=e(t('SMTP-Passwort','SMTP password'))?></label><input id="smtp_password" name="smtp_password" type="password" autocomplete="new-password"><small><?=e(!empty($smtp['password'])?t('Gespeichert. Leer lassen, um es beizubehalten.','Saved. Leave blank to keep it.'):t('Noch kein Passwort gespeichert.','No password saved.'))?></small></div><?php input('from_email',t('Absenderadresse (No-Reply)','Sender address (no-reply)'),$smtp['from_email']??'','email',true);input('from_name',t('Absendername','Sender name'),$smtp['from_name']??setting('club_name','Badminton'),'text',true);?></div><?php check_field('clear_password',t('Gespeichertes SMTP-Passwort entfernen','Remove saved SMTP password'));submit_button();?></form></section>
<section class="card"><h2><?=e(t('Verbindung testen','Test connection'))?></h2><p class="muted"><?=e(t('Die Testmail geht an deine bestätigte Konto-Adresse. Versandstatus im Postausgang prüfen.','The test email goes to your verified account address. Check its status in the outbox.'))?></p><?php start_form('smtp_test');submit_button(t('Testmail vormerken','Queue test email'),'secondary');?></form></section>
<?php elseif($tab==='privacy'): ?>
<section class="card"><h2><?=e(t('Datenschutzerklärung','Privacy notice'))?></h2><p class="muted"><?=e(t('Den Entwurf an das tatsächliche Hosting und den E-Mail-Dienst anpassen. Beide Sprachfassungen sind öffentlich vor der Anmeldung erreichbar.','Adapt the draft to the actual hosting and email service. Both languages are accessible before sign-in.'))?></p>
<div class="notice"><?=e(t('Name und Anschrift kommen aus „Betrieb“ und müssen hier nicht getippt werden. Diese Platzhalter werden beim Anzeigen ersetzt: ','The name and address come from “Betrieb” and do not have to be typed here. These placeholders are filled in when the notice is shown: '))?><code><?=e(implode(' ',array_keys(privacy_placeholders())))?></code></div><?php start_form('privacy_save');input('privacy_de','Deutsch',setting('privacy_de',''),'textarea',true);input('privacy_en','English',setting('privacy_en',''),'textarea',true);check_field('privacy_ready',t('Beide Fassungen sind vervollständigt und zur Verwendung freigegeben.','Both versions are complete and approved for use.'),(bool)setting('privacy_ready',false));submit_button();?></form></section>
<?php endif ?>
