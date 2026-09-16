<?php
// Settings tabs added in 0.2: skills, payment profiles, the defaults registry
// and system/maintenance. Admin only - index.php requires admin for this page.

if($tab==='skills'):
    $editScale=(int)($_GET['scale']??0); $editArea=(int)($_GET['area']??0); $editSkill=(int)($_GET['skill']??0);
    $scale=$editScale?one('SELECT * FROM rating_scales WHERE id=?',[$editScale]):null;
    $area=$editArea?one('SELECT * FROM skill_areas WHERE id=?',[$editArea]):null;
    $skill=$editSkill?one('SELECT * FROM skills WHERE id=?',[$editSkill]):null;
?>
<p class="muted"><?=e(t('Bereiche bündeln Fähigkeiten. Jede Fähigkeit wird auf einer Skala bewertet. Umbenennen und Archivieren behalten alle bisher erfassten Werte.','Areas group skills. Each skill is scored on a scale. Renaming and archiving keep every value already recorded.'))?></p>
<div class="settings-grid">
    <section class="card">
        <div class="section-heading"><h2><?=e(t('Bereiche','Areas'))?></h2><?=link_button(t('+ Neu','+ New'),'settings',['tab'=>'skills'],'secondary')?></div>
        <?php foreach(skill_areas(true) as $row):?>
        <a class="editor-list-item <?=$editArea===(int)$row['id']?'selected':''?>" href="<?=e(url('settings',['tab'=>'skills','area'=>$row['id']]))?>">
            <span><strong><?=e($row['name'])?></strong><small><?=(int)scalar('SELECT COUNT(*) FROM skills WHERE area_id=? AND archived=0',[$row['id']])?> <?=e(t('Fähigkeiten','skills'))?></small></span>
            <?php if($row['archived'])badge(t('Archiviert','Archived'));else echo icon('arrow');?>
        </a>
        <?php endforeach ?>
        <h3><?=e($area?t('Bereich bearbeiten','Edit area'):t('Bereich anlegen','Create area'))?></h3>
        <?php start_form('area_save',['id'=>$editArea]);
        input('name',t('Name','Name'),$area['name']??'','text',true);
        input('sort_order',t('Reihenfolge','Order'),$area['sort_order']??0,'number');
        input('description',t('Beschreibung','Description'),$area['description']??'');
        check_field('archived',t('Archivieren','Archive'),(bool)($area['archived']??false));
        submit_button();?></form>
    </section>
    <section class="card">
        <div class="section-heading"><h2><?=e(t('Skalen','Scales'))?></h2><?=link_button(t('+ Neu','+ New'),'settings',['tab'=>'skills'],'secondary')?></div>
        <?php foreach(rating_scales(true) as $row):?>
        <a class="editor-list-item <?=$editScale===(int)$row['id']?'selected':''?>" href="<?=e(url('settings',['tab'=>'skills','scale'=>$row['id']]))?>">
            <span><strong><?=e($row['name'])?></strong><small><?=e(rtrim(rtrim(number_format((float)$row['min_value'],2,'.',''),'0'),'.').' – '.rtrim(rtrim(number_format((float)$row['max_value'],2,'.',''),'0'),'.'))?></small></span>
            <?php if($row['archived'])badge(t('Archiviert','Archived'));else echo icon('arrow');?>
        </a>
        <?php endforeach ?>
        <h3><?=e($scale?t('Skala bearbeiten','Edit scale'):t('Skala anlegen','Create scale'))?></h3>
        <?php start_form('scale_save',['id'=>$editScale]);?>
        <div class="grid three"><?php
        input('min_value',t('Von','From'),$scale?rtrim(rtrim(number_format((float)$scale['min_value'],2,'.',''),'0'),'.'):'0','text',true);
        input('max_value',t('Bis','To'),$scale?rtrim(rtrim(number_format((float)$scale['max_value'],2,'.',''),'0'),'.'):'10','text',true);
        input('step',t('Schritt','Step'),$scale?rtrim(rtrim(number_format((float)$scale['step'],2,'.',''),'0'),'.'):'1','text',true);
        ?></div>
        <?php input('name',t('Name der Skala','Scale name'),$scale['name']??'','text',true);
        $labels=$scale?json_decode((string)$scale['labels_json'],true):[];
        $lines=[];foreach(is_array($labels)?$labels:[] as $k=>$v)$lines[]=$k.' = '.$v;
        input('labels',t('Bezeichnungen (optional)','Labels (optional)'),implode("\n",$lines),'textarea',false,t('Je Zeile „Wert = Text“, z. B. 1 = sehr gut. Leer lassen für reine Zahlen.','One per line as “value = text”, e.g. 1 = very good. Leave empty for plain numbers.'));
        check_field('archived',t('Archivieren','Archive'),(bool)($scale['archived']??false));
        submit_button();?></form>
    </section>
</div>
<section class="card">
    <div class="section-heading"><h2><?=e(t('Fähigkeiten','Skills'))?></h2><?=link_button(t('+ Neu','+ New'),'settings',['tab'=>'skills'],'secondary')?></div>
    <?php $areas=skill_areas(); $scales=rating_scales();
    if(!$areas||!$scales):?><p class="muted"><?=e(t('Zuerst einen Bereich und eine Skala anlegen.','Create an area and a scale first.'))?></p><?php else:
    foreach(skills(true) as $row):?>
    <a class="editor-list-item <?=$editSkill===(int)$row['id']?'selected':''?>" href="<?=e(url('settings',['tab'=>'skills','skill'=>$row['id']]))?>">
        <span><strong><?=e($row['name'])?></strong><small><?=e($row['area_name'].' · '.$row['scale_name'])?></small></span>
        <?php if($row['archived'])badge(t('Archiviert','Archived'));else echo icon('arrow');?>
    </a>
    <?php endforeach ?>
    <h3><?=e($skill?t('Fähigkeit bearbeiten','Edit skill'):t('Fähigkeit anlegen','Create skill'))?></h3>
    <?php start_form('skill_save',['id'=>$editSkill]);?>
    <div class="grid two"><?php
    input('name',t('Name, z. B. Genauigkeit','Name, e.g. Accuracy'),$skill['name']??'','text',true);
    input('sort_order',t('Reihenfolge','Order'),$skill['sort_order']??0,'number');
    select_field('area_id',t('Bereich','Area'),array_column($areas,'name','id'),$skill['area_id']??($editArea?:''),true);
    select_field('scale_id',t('Skala','Scale'),array_column($scales,'name','id'),$skill['scale_id']??'',true);
    ?></div>
    <?php input('description',t('Hinweis für die Bewertung','Note for whoever scores it'),$skill['description']??'');
    check_field('archived',t('Archivieren','Archive'),(bool)($skill['archived']??false));
    submit_button();?></form>
    <?php endif ?>
</section>

<?php elseif($tab==='payments'):
    $editProfile=(int)($_GET['edit']??0);
    $profile=$editProfile?one('SELECT * FROM payment_profiles WHERE id=?',[$editProfile]):null;
    $sepa="BCD\n002\n1\nSCT\n{bic}\n{recipient}\n{iban}\n{currency}{amount}\n\n{reference}";
?>
<div class="settings-grid">
    <section class="card">
        <div class="section-heading"><h2><?=e(t('Zahlungsempfänger','Payment profiles'))?></h2><?=link_button(t('+ Neu','+ New'),'settings',['tab'=>'payments'],'secondary')?></div>
        <?php foreach(payment_profiles(true) as $row):?>
        <a class="editor-list-item <?=$editProfile===(int)$row['id']?'selected':''?>" href="<?=e(url('settings',['tab'=>'payments','edit'=>$row['id']]))?>">
            <span><strong><?=e($row['name'])?></strong><small class="mono"><?=e($row['iban']!==''?trim(chunk_split($row['iban'],4,' ')):t('Keine IBAN hinterlegt','No IBAN set'))?></small></span>
            <?php if($row['archived'])badge(t('Archiviert','Archived'));else echo icon('arrow');?>
        </a>
        <?php endforeach ?>
        <p class="muted"><?=e(t('Ein Kurs kann einen eigenen Empfänger haben. Ohne eigenen wird der Standard aus „Vorgaben“ verwendet.','A class can have its own recipient. Without one, the default from “Defaults” is used.'))?></p>
    </section>
    <section class="card">
        <h2><?=e($profile?t('Empfänger bearbeiten','Edit profile'):t('Empfänger anlegen','Create profile'))?></h2>
        <?php start_form('profile_save',['id'=>$editProfile]);?>
        <div class="grid two"><?php
        input('name',t('Bezeichnung','Label'),$profile['name']??'','text',true);
        input('recipient',t('Empfängername wie bei der Bank','Recipient name as held by the bank'),$profile['recipient']??setting('club_name'));
        input('iban','IBAN',$profile['iban']??'','text',false,t('Wird auf Prüfsumme kontrolliert.','Checked against its checksum.'));
        input('bic',t('BIC (optional)','BIC (optional)'),$profile['bic']??'');
        input('currency',t('Währung','Currency'),$profile['currency']??'EUR');
        ?></div>
        <?php input('note',t('Hinweis für Eltern','Note shown to parents'),$profile['note']??'');
        input('qr_template',t('Inhalt des QR-Codes','QR code contents'),$profile['qr_template']??$sepa,'textarea',false,
            t('Platzhalter: {recipient} {iban} {bic} {currency} {amount} {reference}. Die Vorgabe ist das SEPA-Format, das Bank-Apps lesen.','Placeholders: {recipient} {iban} {bic} {currency} {amount} {reference}. The default is the SEPA format banking apps read.'));
        check_field('archived',t('Archivieren','Archive'),(bool)($profile['archived']??false));
        submit_button();?></form>
        <?php if($profile && $profile['iban']!==''): $demo=qr_payload($profile,4500,t('Beispiel','Example'));?>
        <h3><?=e(t('Vorschau','Preview'))?></h3>
        <p class="muted"><?=e(t('So sieht der Code für 45,00 € aus.','This is the code for 45.00.'))?></p>
        <div class="pay-qr"><?=$demo!==''?qr_svg($demo,170):''?></div>
        <?php endif ?>
    </section>
</div>

<?php elseif($tab==='system'): $inMaintenance=is_file(maintenance_file()); ?>
<section class="card">
    <h2><?=e(t('Wartungsmodus','Maintenance mode'))?></h2>
    <p class="muted"><?=e(t('Im Wartungsmodus sehen Schüler und Trainerinnen eine kurze Hinweisseite. Administratoren können weiterarbeiten und den Modus hier wieder beenden.','In maintenance mode students and trainers see a short notice page. Administrators can keep working and switch it off again here.'))?></p>
    <?php if($inMaintenance): ?>
        <div class="notice"><?=e(t('Der Wartungsmodus ist aktiv.','Maintenance mode is on.'))?></div>
        <?php start_form('maintenance_toggle',['mode'=>'off']);submit_button(t('Wartungsmodus beenden','Switch maintenance off'));?></form>
    <?php else: ?>
        <?php start_form('maintenance_toggle',['mode'=>'on']);submit_button(t('Wartungsmodus starten','Switch maintenance on'),'secondary');?></form>
    <?php endif ?>
</section>
<section class="card">
    <h2><?=e(t('Installation','Installation'))?></h2>
    <dl class="facts">
        <div><dt><?=e(t('Version','Version'))?></dt><dd><?=e(trim(file_get_contents(ROOT.'/VERSION')))?></dd></div>
        <div><dt>PHP</dt><dd><?=e(PHP_VERSION)?></dd></div>
        <?php // schema_migrations does not exist before the first migrate, which is
              // exactly when an operator is most likely to open this page.
              try { $schemaVersion=(string)(scalar('SELECT MAX(version) FROM schema_migrations')?:''); $pending=schema_pending(); }
              catch (PDOException) { $schemaVersion=''; $pending=[]; } ?>
        <div><dt><?=e(t('Datenbankstand','Database version'))?></dt><dd><?=e($schemaVersion!==''?$schemaVersion:t('noch nicht migriert','not migrated yet'))?></dd></div>
        <div><dt><?=e(t('Letzter Versandlauf','Last mail run'))?></dt><dd><?=e(setting('mail_last_run')?fmt_datetime((string)setting('mail_last_run')):t('noch keiner','none yet'))?></dd></div>
        <div><dt><?=e(t('Wartende E-Mails','Queued email'))?></dt><dd><?=(int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE status='queued'")?></dd></div>
        <div><dt><?=e(t('Fehlgeschlagene E-Mails','Failed email'))?></dt><dd><?=(int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE status='failed'")?></dd></div>
    </dl>
    <?php if($pending): ?>
    <div class="notice"><?=e(plural(count($pending),'Datenbankänderung wartet noch','Datenbankänderungen warten noch','database change is still waiting','database changes are still waiting'))?>: <?=e(implode(', ',$pending))?>.
    <?=e(t('Sie werden beim nächsten Seitenaufruf angewendet, sobald der Wartungsmodus aus ist.','They are applied on the next page view once maintenance mode is off.'))?></div>
    <?php endif ?>
    <p class="muted"><?=e(t('Update: die neuen Dateien hochladen. Die Datenbank wird beim nächsten Aufruf des Portals selbst angepasst – es ist kein weiterer Schritt nötig.','Update: upload the new files. The database updates itself on the next page view; there is no further step.'))?></p>
</section>
<section class="card">
    <h2><?=e(t('Wartende Aufgaben','Waiting work'))?></h2>
    <p class="muted"><?=e(t('E-Mails verschicken, abgelaufene Links entfernen und – falls eingeschaltet – die Monatsbeiträge anlegen. Ohne Cronjob erledigt das Portal das selbst, kurz nachdem eine Seite geladen wurde.','Sending email, removing expired links and – if switched on – creating the monthly charges. Without a cron job the portal does this itself, just after a page has been served.'))?></p>
    <dl class="facts">
        <div><dt><?=e(t('Letzter Hintergrundlauf','Last background run'))?></dt><dd><?=e(setting('tick_last_run')?fmt_datetime((string)setting('tick_last_run')):t('noch keiner','none yet'))?></dd></div>
        <div><dt><?=e(t('Letztes Aufräumen','Last cleanup'))?></dt><dd><?=e(setting('prune_last_run')?fmt_datetime((string)setting('prune_last_run')):t('noch keins','none yet'))?></dd></div>
        <div><dt><?=e(t('Automatische Beiträge','Automatic charges'))?></dt><dd><?=e(setting('auto_billing')?(setting('billing_last_period')?:t('noch keine','none yet')):t('aus','off'))?></dd></div>
    </dl>
</section>
<?php endif ?>
