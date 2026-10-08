<?php
/**
 * Verwaltung: the lists the trainer uses about the children in front of her.
 *
 * Deliberately not under Einstellungen. Settings is where the portal itself is
 * configured - mail server, privacy notice, backups, maintenance - and that is
 * the administrator's job. Renaming a level or adding an age band is the
 * trainer's daily business and should not require asking anybody.
 */
$tab=$_GET['tab']??'levels';
$items=['levels'=>t('Leistungsgruppen','Levels'),'ages'=>t('Altersgruppen','Age groups'),
        'members'=>t('Mitgliedschaft','Membership'),'payments'=>t('Geld & Zahlungen','Money and payments')];
if(!isset($items[$tab]))$tab='levels';
$edit=(int)($_GET['edit']??0);
page_head(t('Verwaltung','Management'),t('Die Listen, mit denen du arbeitest. Technisches steht unter Einstellungen.','The lists you work with. The technical settings are under Einstellungen.'),
    // For a trainer, Verwaltung is the menu entry Zugänge belongs to (nav_owner()).
    link_button(t('Zugänge','Logins'),'accounts',[],'secondary'));
tabs($items,$tab,'manage');
?>
<?php /* The four groupings exist for different reasons and get mixed up exactly
         because nothing ever says what each is for. So it says so, once, above
         all of them, rather than in four separate hints nobody reads together. */ ?>
<section class="card grouping-guide">
    <h2><?=e(t('Wer gehört wohin?','Which grouping is which?'))?></h2>
    <div class="grid three">
        <div><h3><?=e(t('Kurs','Course'))?></h3><p class="muted"><?=e(t('Wann und wo trainiert wird. Ein Kind kann in mehreren Kursen sein. Kurse stehen unter „Kurse“.','When and where training happens. A child can be in several. Courses live under “Kurse”.'))?></p></div>
        <div><h3><?=e(t('Leistungsgruppe','Level'))?></h3><p class="muted"><?=e(t('Wie weit ein Kind ist. Du wählst sie aus; neue Kinder starten in der Standardgruppe.','How far along a child is. You choose it; a new child starts in the default one.'))?></p></div>
        <div><h3><?=e(t('Altersgruppe','Age group'))?></h3><p class="muted"><?=e(t('Ergibt sich aus dem Geburtsdatum und ändert sich mit jedem Geburtstag.','Worked out from the date of birth and moves with every birthday.'))?></p></div>
        <div><h3><?=e(t('Mitgliedschaftsstatus','Membership status'))?></h3><p class="muted"><?=e(t('Ob jemand aktiv ist, pausiert, auf Probe trainiert oder aufgehört hat.','Whether somebody is active, paused, on a trial or has stopped.'))?></p></div>
        <div><h3><?=e(t('Tarif','Tariff'))?></h3><p class="muted"><?=e(t('Was das Training kostet und wie oft abgerechnet wird. Tarife gehören zum Kurs.','What training costs and how often it is billed. Tariffs belong to a course.'))?></p></div>
        <div><h3><?=e(t('Anwesenheit','Attendance'))?></h3><p class="muted"><?=e(t('Wer an einem Trainingstag da war. Wird unter „Anwesenheit“ eingetragen, nicht hier.','Who turned up on a training day. Recorded under “Anwesenheit”, not here.'))?></p></div>
    </div>
</section>

<?php if($tab==='levels'): $usage=group_usage(); $level=$edit?one('SELECT * FROM levels WHERE id=?',[$edit]):null; ?>
<div class="settings-grid">
    <section class="card">
        <div class="section-heading"><h2><?=e(t('Leistungsgruppen','Levels'))?></h2><?=link_button(t('+ Neu','+ New'),'manage',['tab'=>'levels'],'secondary')?></div>
        <?php foreach(levels(true) as $row): $count=$usage['levels'][(int)$row['id']]??0; ?>
        <a class="editor-list-item <?=$edit===(int)$row['id']?'selected':''?>" href="<?=e(url('manage',['tab'=>'levels','edit'=>$row['id']]))?>">
            <span><strong><?=e($row['name'])?></strong><small><?=e($row['description']?:plural($count,'Kind','Kinder','child','children'))?></small></span>
            <span class="row-actions"><?php if($row['is_default'])badge(t('Standard','Default'),'green'); if($row['archived'])badge(t('Archiviert','Archived'),'amber'); echo icon('chevron');?></span>
        </a>
        <?php endforeach ?>
        <p class="muted"><?=e(t('Umbenennen wirkt überall sofort, weil die Schüler auf den Eintrag zeigen und nicht auf das Wort. Archivieren behält die Zuordnung bestehender Kinder.','Renaming takes effect everywhere at once, because a student points at the entry rather than at the word. Archiving keeps the children already assigned.'))?></p>
    </section>
    <section class="card">
        <div class="section-heading"><h2><?=e($level?t('Gruppe bearbeiten','Edit level'):t('Gruppe anlegen','Create level'))?></h2></div>
        <?php start_form('level_save',['id'=>$edit]);
        input('name',t('Name','Name'),$level['name']??'','text',true,'',t('z. B. Anfänger','e.g. Beginner'));
        input('description',t('Kurze Erklärung','Short explanation'),$level['description']??'','text',false,t('Wofür steht diese Gruppe? Wird in der Liste angezeigt.','What does this level mean? Shown in the list.'));
        input('sort_order',t('Reihenfolge (kleine Zahl zuerst)','Order (lowest first)'),$level['sort_order']??0,'number');
        check_field('is_default',t('Neue Kinder starten in dieser Gruppe','New children start in this level'),(bool)($level['is_default']??false),'',false,true);
        check_field('archived',t('Archivieren (bestehende Zuordnungen bleiben)','Archive (keeps existing assignments)'),(bool)($level['archived']??false),'',false,true);
        submit_button();?></form>
    </section>
</div>

<?php elseif($tab==='ages'): $usage=group_usage(); $warnings=age_group_warnings(); $group=$edit?one('SELECT * FROM age_groups WHERE id=?',[$edit]):null; ?>
<div class="settings-grid">
    <section class="card">
        <div class="section-heading"><h2><?=e(t('Altersgruppen','Age groups'))?></h2><?=link_button(t('+ Neu','+ New'),'manage',['tab'=>'ages'],'secondary')?></div>
        <?php foreach(age_groups(true) as $row): $count=$usage['age_groups'][(int)$row['id']]??0; ?>
        <a class="editor-list-item <?=$edit===(int)$row['id']?'selected':''?>" href="<?=e(url('manage',['tab'=>'ages','edit'=>$row['id']]))?>">
            <span><strong><?=e($row['name'])?></strong><small><?=e(age_group_range($row).' · '.plural($count,'Kind','Kinder','child','children'))?></small></span>
            <span class="row-actions"><?php if($row['archived'])badge(t('Archiviert','Archived'),'amber'); echo icon('chevron');?></span>
        </a>
        <?php endforeach ?>
        <?php if($usage['unplaced']): ?>
        <div class="notice warn"><?=e(plural($usage['unplaced'],'Kind fällt in keine Altersgruppe','Kinder fallen in keine Altersgruppe','child falls into no age group','children fall into no age group'))?>.
            <?=e(t('Meist fehlt das Geburtsdatum.','Usually the date of birth is missing.'))?></div>
        <?php endif ?>
        <?php foreach($warnings as $warning): ?><div class="notice warn"><?=e($warning)?></div><?php endforeach ?>
    </section>
    <section class="card">
        <div class="section-heading"><h2><?=e($group?t('Altersgruppe bearbeiten','Edit age group'):t('Altersgruppe anlegen','Create age group'))?></h2></div>
        <p class="muted"><?=e(t('Beide Grenzen zählen mit: „12 bis 17“ heißt vom zwölften Geburtstag bis zum Tag vor dem achtzehnten.','Both ends count: “12 to 17” runs from the twelfth birthday to the day before the eighteenth.'))?></p>
        <?php start_form('age_group_save',['id'=>$edit]);
        input('name',t('Name','Name'),$group['name']??'','text',true,'',t('z. B. Jugend','e.g. Youth'));?>
        <div class="grid two"><?php
        input('min_age',t('Ab diesem Alter','From this age'),$group['min_age']??0,'number');
        input('max_age',t('Bis zu diesem Alter','Up to this age'),$group['max_age']??'','number',false,t('Leer lassen für „und älter“.','Leave empty for “and older”.'));?></div><?php
        input('sort_order',t('Reihenfolge (kleine Zahl zuerst)','Order (lowest first)'),$group['sort_order']??0,'number',false,t('Bei Überschneidungen gewinnt die erste passende Gruppe.','Where bands overlap, the first matching one wins.'));
        check_field('archived',t('Archivieren','Archive'),(bool)($group['archived']??false),'',false,true);
        submit_button();?></form>
    </section>
</div>

<?php elseif($tab==='members'): $registryGroup='students'; require ROOT.'/views/_settings_registry.php';

elseif($tab==='payments'): require ROOT.'/views/_payment_profiles.php'; $registryGroup='payments'; require ROOT.'/views/_settings_registry.php';
endif ?>
