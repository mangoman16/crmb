<?php
$id=(int)($_GET['id']??0);$staff=is_staff($user);if(!$id)require_staff();
$s=$id?student($id):['id'=>0,'first_name'=>'','last_name'=>'','email'=>'','birth_date'=>'','joined_on'=>today(),'ended_on'=>'','status'=>setting('default_status','active'),'account_id'=>null,'level_id'=>(int)(level_default()['id']??0),'age_group_id'=>null,'internal_notes'=>''];
$tab=(string)($_GET['tab']??'details');
$tabsAllowed=$staff?['details','contacts','payments','invoices','absence','classes','attendance']:['details','contacts','payments','invoices','absence','classes'];
if(!in_array($tab,$tabsAllowed,true))$tab='details';
page_head($id?$s['first_name'].' '.$s['last_name']:t('Neuen Schüler anlegen','Add a student'),$id?status_label($s['status']):'',
    ($id&&$staff?link_button(t('Datenblatt drucken','Print the data sheet'),'print',['id'=>$id],'secondary'):'')
    // A family has one student and this is their page (ADR 0010): a list of one
    // is not somewhere to go back to.
    .($staff?link_button(t('Alle Schüler','All students'),'students',[],'secondary'):''));
if($id){
    $tabLabels=['details'=>t('Profil','Profile'),'contacts'=>t('Kontakte','Contacts'),'payments'=>t('Beiträge','Payments'),
                'invoices'=>t('Rechnungen','Invoices'),'classes'=>t('Kurse','Courses'),'absence'=>t('Abwesenheit','Absences')];
    if($staff){$tabLabels['attendance']=t('Anwesenheit','Attendance');}
    tabs($tabLabels,$tab,'student',['id'=>$id]);
}
/* Creating a child asks for a name and an address and nothing else - a form
   with twenty boxes on it is a form somebody abandons half-way. The rest is not
   optional, though: a child with no course is a child nobody bills. So the page
   says what is left, in the order she would do it, rather than leaving her to
   remember four days later. */
next_steps_card($id&&$staff?student_next_steps($id):[]);
if($tab==='details' || !$id): ?>
<?php /* Outside the form below, and before it: a form inside a form is markup
         the browser throws away, so the picture would have been saved by
         whichever button was pressed last. */ ?>
<?php if($id): ?>
<section class="card">
    <h2><?=e(t('Bild','Picture'))?></h2>
    <div class="avatar-editor">
        <?=avatar($s,'large','student')?>
        <div>
            <p class="muted"><?=e(t('Freiwillig. Ohne Bild zeigt das Portal die Anfangsbuchstaben.','Optional. Without one the portal shows the initials.'))?></p>
            <?php start_form('avatar_save',['kind'=>'student','id'=>$id],'form',true);
            file_field('avatar',t('Bild auswählen','Choose a picture'),'avatar');
            submit_button(t('Bild speichern','Save the picture'),'secondary');?></form>
            <?php if(($s['avatar_name']??'')!==''){start_form('avatar_save',['kind'=>'student','id'=>$id,'remove'=>1],'inline-form');submit_button(t('Bild entfernen','Remove the picture'),'subtle danger-text');echo '</form>';} ?>
        </div>
    </div>
</section>
<?php endif ?>
<?php start_form('student_save',['id'=>$id,'revision'=>$s['revision']??0],'form'); ?>
<section class="card"><h2><?=e(t('Persönliche Daten','Personal details'))?></h2><div class="grid two"><?php
input('first_name',t('Vorname','First name'),$s['first_name'],'text',true);
input('last_name',t('Nachname','Last name'),$s['last_name'],'text',true);
$age=student_age($s['birth_date']??null);
input('birth_date',t('Geburtsdatum','Date of birth'),$s['birth_date'],'date',false,
    $age!==null?plural($age,'Jahr alt','Jahre alt','year old','years old').' · '.t('Altersgruppe: ','Age group: ').age_group_name($s)
               :t('Bestimmt die Altersgruppe.','Decides the age group.'));
/* The address this student signs in with, and where the invitation and the
   invoices go: one address, one student, one login (ADR 0010). Before there is
   a login it is hers to type; while the invitation is open she may still
   correct it, and saving sends the invitation again; once somebody signs in
   with it, it is theirs, and only they change it - with a confirmation from the
   new mailbox, so nobody can move a family's login to a mailbox of their own.
   No box is posted then, which is what student_save expects. */
if($staff):
    $login=$s['account_id']?one('SELECT * FROM accounts WHERE id=?',[(int)$s['account_id']]):null;
    $firstName=$s['first_name']!==''?$s['first_name']:t('die Schülerin oder der Schüler','The student');
    echo '<div id="email">';
    if($login && $login['state']!=='invited') {
        echo '<div class="field"><label>'.e(t('E-Mail-Adresse','Email address')).'</label><div class="readonly">'.e($login['email']).'</div><small>'
            .e($firstName.t(' meldet sich damit an und ändert sie selbst unter „Mein Konto“ – mit Bestätigung aus dem neuen Postfach.',' signs in with it and changes it themselves under “My account” – confirmed from the new mailbox.'))
            .'</small></div>';
    } elseif($login) {
        input('email',t('E-Mail-Adresse','Email address'),$login['email'],'email',true,
              t('Einladung noch nicht angenommen. Änderst du die Adresse, geht die Einladung beim Speichern an die neue; der alte Link gilt dann nicht mehr.',
                'The invitation has not been accepted yet. If you change the address, saving sends the invitation to the new one; the old link then stops working.'));
    } else {
        input('email',t('E-Mail-Adresse','Email address'),$s['email'],'email',false,
              t('Damit meldet sich ','').$firstName.t(' an. Dorthin geht die Einladung, und sobald es einen Zugang gibt, auch Rechnungen und Zahlungserinnerungen. Jede Schülerin und jeder Schüler braucht eine eigene Adresse.',
                ' signs in with this. The invitation goes here, and once there is a login, so do invoices and payment reminders. Every student needs an address of their own.'));
    }
    echo '</div>';
endif;
?></div>
<?php if($staff): ?>
<h3><?=e(t('Anschrift und Telefon','Address and telephone'))?></h3>
<?php /* One line for the address, the way an anmeldeformular asks it, because it
         is typed once and printed once and never sorted on. The telephone is the
         member's own: for an adult those are the same person as the emergency
         contact, and listing yourself as who to ring is not a record anybody
         should have to keep. */
input('address',t('Anschrift','Postal address'),$s['address']??'','text',false,
      t('Straße, PLZ und Ort in einer Zeile. Gehört ab 400 € Rechnungsbetrag auf die Rechnung.',
        'Street, postcode and town on one line. Required on an invoice above 400 €.'));
input('phone',t('Telefonnummer','Telephone number'),$s['phone']??'','tel',false,
      t('Die eigene Nummer. Wen wir im Notfall anrufen, steht unter „Kontakte“.',
        'Their own number. Who to ring in an emergency is under “Contacts”.'));
?>
<?php endif ?>
</section>

<?php /* A new child gets the two questions that cannot be answered later - what
         is this child called, where do we write - and the one that decides
         whether they count as a member. Everything else has a default, a tab of
         its own, or a place on the list above, and a form of twenty boxes is a
         form somebody abandons in the middle of a training session. */
if($staff && !$id): ?>
<section class="card"><h2><?=e(t('Mitgliedschaft','Membership'))?></h2><div class="grid two"><?php
select_field('status',t('Mitgliedschaft','Membership'),array_combine(array_keys(statuses()),array_map('status_label',array_keys(statuses()))),$s['status'],true);
input('joined_on',t('Dabei seit','Member since'),$s['joined_on'],'date');
?></div>
<p class="muted"><?=e(t('Leistungsgruppe, Kurs, Tarif, Kontakte und alles Weitere trägst du gleich auf der Seite des Kindes ein – sie steht dann auch als Liste „Noch zu tun“ dort.','The level, the course, the tariff, the contacts and everything else are entered on the child’s own page next – it lists them there as “Still to do”.'))?></p>
</section>
<?php endif ?>
<?php if($staff && $id):
/* What the child is in the club, in the order it is asked: whether and since
   when they are a member, then how far along and how old. The prices went to
   the course (ADR 0011) - a tariff on the child billed nobody - and the two
   dates came here with the status they belong to. Inside the student form, so
   „Schüler speichern" keeps saving them. */ ?>
<section class="card"><h2><?=e(t('Einteilung','Grouping'))?></h2>
<p class="muted"><?=e(t('Drei verschiedene Dinge, die leicht durcheinandergehen: wie weit das Kind ist, wie alt es ist, und ob und seit wann es dabei ist.','Three different things that are easy to confuse: how far along the child is, how old they are, and whether and since when they are taking part.'))?></p>
<div class="grid three"><?php
select_field('status',t('Mitgliedschaft','Membership'),array_combine(array_keys(statuses()),array_map('status_label',array_keys(statuses()))),$s['status'],true,false,
    t('Probetraining, aktiv, pausiert oder beendet.','On trial, active, paused or ended.'));
input('joined_on',t('Dabei seit','Member since'),$s['joined_on'],'date',false,
    t('Im Verein. Wann das Kind in einen Kurs kam, steht beim Kurs.','In the club. When the child joined a course is shown with the course.'));
input('ended_on',t('Mitgliedschaft bis','Membership until'),$s['ended_on'],'date',false,
    t('Leer lassen, solange kein Ende feststeht.','Leave it empty while no end is fixed.'));
select_field('level_id',t('Leistungsgruppe','Level'),array_column(rows('SELECT id,name FROM levels WHERE archived=0 OR id=? ORDER BY sort_order,name',[$s['level_id']??0]),'name','id'),$s['level_id'],false,false,
    t('Du wählst sie. Neue Kinder starten in ','You choose it. New children start in ').(level_default()['name']??'–').'.');
select_field('age_group_id',t('Altersgruppe festlegen','Pin the age group'),array_column(age_groups(),'name','id'),$s['age_group_id'],false,false,
    $s['age_group_id']?t('Fest eingestellt. Leer lassen, damit sie sich wieder aus dem Geburtsdatum ergibt.','Pinned. Clear it to let the date of birth decide again.'):t('Leer = ergibt sich aus dem Geburtsdatum: ','Empty = worked out from the date of birth: ').age_group_name($s));
?></div></section>
<?php elseif($id):?><section class="card"><h2><?=e(t('Mitgliedschaft','Membership'))?></h2><dl class="facts"><div><dt><?=e(t('Dabei seit','Member since'))?></dt><dd><?=e(fmt_date($s['joined_on']))?></dd></div><div><dt><?=e(t('Mitgliedschaft bis','Membership until'))?></dt><dd><?=e(fmt_date($s['ended_on']))?></dd></div></dl></section><?php endif ?>
<?php $fields=$id?array_filter(field_definitions(),fn($f)=>$staff || $f['visibility']!=='internal'):[];if($fields): ?><section class="card"><h2><?=e(t('Weitere Angaben','Additional details'))?></h2><div class="grid two">
<?php $lastSection='';foreach($fields as $f):$v=$id?field_value($id,(int)$f['id']):json_decode($f['default_json'],true);$label=field_label($f);$n='custom['.$f['id'].']';
if($f['section_name'] && $f['section_name']!==$lastSection){echo '<h3 class="full">'.e($f['section_name']).'</h3>';$lastSection=$f['section_name'];}
if(!$staff && $f['visibility']==='view'){echo '<div class="field"><label>'.e($label).'</label><div class="readonly">'.e(is_array($v)?implode(', ',$v):(is_bool($v)?($v?t('Ja','Yes'):t('Nein','No')):($v??'–'))).'</div></div>';continue;}
if(in_array($f['field_type'],['select','multiselect'],true)){$opts=json_decode($f['options_json'],true);foreach(is_array($v)?$v:[$v] as $x)if($x!==null&&$x!==''&&!in_array($x,$opts,true))$opts[]=$x;select_field($n,$label,array_combine($opts,$opts),$v,(bool)$f['required'],$f['field_type']==='multiselect');}
elseif($f['field_type']==='checkbox')check_field($n,$label.($f['required']?' *':''),(bool)$v);
else input($n,$label,$v??'',$f['field_type']==='number'?'text':$f['field_type'],(bool)$f['required']);
endforeach ?></div></section><?php endif ?>
<?php if($staff && $id):?><section class="card"><details <?=$s['internal_notes']?'open':''?>><summary><?=e(t('Interne Notizen','Internal notes'))?></summary><?php input('internal_notes',t('Nur für die Verwaltung sichtbar','Visible to management only'),$s['internal_notes'],'textarea');?></details></section><?php endif ?>
<div class="form-footer"><?php submit_button($id?t('Schüler speichern','Save student'):t('Anlegen und weiter','Create and continue'));?></div></form>
<?php if($staff && $id):
/* Everything about this student's login, in one card and in the order it
   happens: invited, active, suspended, gone. Outside the student form, because
   each of these is its own decision and its own POST - saving a birth date
   should not send anybody an email - and a form inside a form is thrown away
   by the browser. */
$loginState=$login['state']??'none'; ?>
<section class="card access-card" id="access">
    <div class="badge-line access-title"><h2><?=e(t('Zugang zum Portal','Access to the portal'))?></h2><?php login_state_badge($login); ?></div>
<?php if(!$login):
    $takenBy=$s['email']!==''?own_address_taken_by($id):''; ?>
    <p><?=e($firstName.t(' hat noch keinen Zugang.',' has no access yet.'))?></p>
    <?php if($s['email']===''): ?>
    <p class="muted"><?=e(t('Trag oben zuerst eine E-Mail-Adresse ein und speichere.','Enter an email address above first, and save.'))?></p>
    <?php elseif($takenBy!==''): ?>
    <div class="notice"><?=e(t('Diese Adresse nutzt schon ','This address is already used by ').$takenBy.t('. Trag oben eine eigene ein.','. Enter one of their own above.'))?></div>
    <?php else: ?>
    <div class="row-actions access-actions"><?php start_form('student_invite',['student_id'=>$id],'inline-form');submit_button(t('Einladung senden','Send the invitation'));?></form></div>
    <?php if(is_admin($user)): ?>
    <details class="access-direct"><summary><?=e(t('Ohne E-Mail anlegen (mit Passwort)','Create without email (with a password)'))?></summary>
        <p class="muted"><?=e(t('Wenn keine Einladung möglich oder gewünscht ist: zum Ausprobieren, oder für jemanden, der neben dir steht und sein Passwort selbst eintippt. Die Adresse wird dabei nicht bestätigt.','When an invitation is not possible or not wanted: to try the portal out, or for somebody standing next to you who types their own password. The address is not confirmed this way.'))?></p>
        <?php start_form('student_invite',['student_id'=>$id,'mode'=>'direct']);
        input('password',t('Passwort','Password'),'','password',true,t('Mindestens 12 Zeichen. ','At least 12 characters. ').$firstName.t(' kann es später ändern.',' can change it later.'));
        submit_button(t('Zugang anlegen','Create the access'),'secondary');?></form>
    </details>
    <?php endif ?>
    <?php endif ?>
<?php else:
    $deleteText=t('Löscht die Anmeldung und die privaten Unterhaltungen. ','Deletes the login and the private conversations. ')
        .$firstName.t(', Kurse, Beiträge und Rechnungen bleiben; du kannst später neu einladen. Nur vorübergehend? Dann lieber sperren.',
                      ', the courses, charges and invoices stay; you can invite again later. Only for a while? Then suspend instead.'); ?>
    <p><?php if($loginState==='invited') echo e(t('Eingeladen an ','Invited at ').$login['email'].t(', noch nicht angenommen.',', not accepted yet.'));
        elseif($loginState==='active') echo e(t('Meldet sich an mit ','Signs in with ').$login['email'].'.');
        else echo e(t('Gesperrt – ','Suspended – ').$firstName.t(' kann sich nicht anmelden. Daten und Nachrichten bleiben.',' cannot sign in. Details and messages stay.')); ?></p>
    <?php /* When the login was last used, as this viewer may know it: through
             presence_line(), never last_seen_at itself, which for somebody who
             appears offline is a time a trainer is not to see (ADR 0015). An
             invitation has not been used, so there is nothing to show. */
    if(presence_shown_for($user,$login)): ?>
    <p class="access-presence"><?=presence_line($user,$login)?></p>
    <?php presence_history_details($user,presence_history($user,[(int)$login['id']])[(int)$login['id']]??[],presence_recorded_since());
    endif ?>
    <div class="row-actions access-actions">
    <?php if($loginState==='invited'):
        start_form('account_state',['id'=>$login['id'],'mode'=>'reinvite'],'inline-form');submit_button(t('Einladung erneut senden','Send the invitation again'),'secondary');echo '</form>';
        login_delete_details($login,t('Einladung zurückziehen','Withdraw the invitation'),
            t('Der Link in der Einladung gilt dann nicht mehr. ','The link in the invitation then stops working. ')
            .$firstName.t(', Kurse, Beiträge und Rechnungen bleiben; du kannst später neu einladen.',', the courses, charges and invoices stay; you can invite again later.'),
            t('Einladung endgültig zurückziehen','Withdraw the invitation for good'));
    elseif($loginState==='active'):
        if(may_impersonate($user,$login)){start_form('impersonate',['id'=>$login['id'],'mode'=>'start'],'inline-form');submit_button(t('Portal als ','View the portal as ').$firstName.t(' ansehen',''),'secondary');echo '</form>';}
        start_form('account_state',['id'=>$login['id'],'mode'=>'suspend'],'inline-form');submit_button(t('Zugang sperren','Suspend the access'),'secondary');echo '</form>';
        login_delete_details($login,t('Zugang löschen','Delete the access'),$deleteText,t('Zugang endgültig löschen','Delete the access for good'));
    else:
        start_form('account_state',['id'=>$login['id'],'mode'=>'restore'],'inline-form');submit_button(t('Zugang entsperren','Restore the access'),'secondary');echo '</form>';
        login_delete_details($login,t('Zugang löschen','Delete the access'),$deleteText,t('Zugang endgültig löschen','Delete the access for good'));
    endif ?>
    </div>
<?php endif ?>
</section>
<details class="danger-zone"><summary><?=e(t('Schüler löschen','Delete student'))?></summary>
    <?php /* Said before the tap: the change log records what was deleted, but
             nothing brings it back. */ ?>
    <p><?=e(t('Das lässt sich nicht rückgängig machen. Die Änderungen verzeichnen zwar, was gelöscht wurde, stellen es aber nicht wieder her. Nur möglich, wenn keine Beiträge vorhanden sind – sonst die Mitgliedschaft beenden.','This cannot be undone. The change log records what was deleted, but it cannot bring it back. Only possible when no charges exist – otherwise, end the membership.'))?></p>
    <?php /* Deleting the student leaves the login standing - the foreign key only
             unhooks it - so it would turn up under Konten with nobody behind it. */
    if($login): ?><p><?=e($firstName.t(' hat einen Zugang. Lösche ihn vorher, sonst bleibt er unter „Konten“ ohne Schüler übrig.',' has an access. Delete it first, or it is left under “Accounts” with no student.'))?></p><?php endif ?>
    <?php start_form('student_delete',['id'=>$id]);input('confirmation',t('Vollständigen Namen zur Bestätigung eingeben','Enter the full name to confirm'),'','text',true);submit_button(t('Schüler endgültig löschen','Permanently delete student'),'danger');?></form>
</details>
<?php endif ?>
<?php elseif($tab==='contacts'): $contacts=student_contacts($id); ?>
<section class="card">
    <div class="section-heading"><h2><?=e(t('Notfallkontakte','Emergency contacts'))?></h2></div>
    <p class="muted"><?=e(t('Wen du anrufst, wenn etwas ist. Jedes Kind braucht mindestens eine Person; der Standardkontakt ist der, den du zuerst probierst. Rechnungen und Einladungen gehen nicht hierher, sondern an die E-Mail-Adresse des Kindes.','Who you ring when something happens. Every child needs at least one person; the standard one is the one you try first. Invoices and invitations do not go here – they go to the child’s own email address.'))?></p>
    <?php if($gap=contact_gap($id)): ?><div class="notice warn"><?=e($gap)?></div><?php endif ?>
    <?php if(!$contacts)echo '<p class="muted">'.e(t('Noch keine Kontakte.','No contacts yet.')).'</p>';
    foreach($contacts as $c): ?>
    <div class="record-row">
        <div><div class="badge-line"><strong><?=e($c['owner_name'])?></strong><?php if((int)$c['is_primary'])badge(t('Standardkontakt','Standard contact'),'green');?></div>
            <p><?=e($c['relation_label'])?></p>
            <?php if($c['phone']):?><a href="tel:<?=e(preg_replace('/[^0-9+]/','',$c['phone']))?>"><?=e($c['phone'])?></a><?php endif ?>
            <p><?=e($c['email']?:t('Keine E-Mail-Adresse','No email address'))?></p></div>
        <?php if(count($contacts)>1): start_form('contact_delete',['student_id'=>$id,'id'=>$c['id']],'inline-form');submit_button(t('Entfernen','Remove'),'subtle danger-text');?></form><?php endif ?>
    </div>
    <details><summary><?=e(t('Kontakt bearbeiten','Edit contact'))?></summary>
        <?php start_form('contact_save',['student_id'=>$id,'id'=>$c['id']]);
        contact_fields($c,(bool)$c['is_primary']);
        if((int)$c['is_primary'])echo '<p class="muted">'.e(t('Das ist der Standardkontakt. Um das zu ändern, setze bei einem anderen Kontakt das Häkchen.','This is the standard contact. To change that, tick the box on another contact.')).'</p>';
        else check_field('is_primary',t('Als Standardkontakt verwenden','Use as the standard contact'),false);
        submit_button();?></form>
    </details>
    <?php endforeach ?>
</section>
<section class="card" id="add-contact"><h2><?=e(t('Notfallkontakt hinzufügen','Add an emergency contact'))?></h2>
    <?php start_form('contact_add',['student_id'=>$id]);
    // The first contact a child has is the standard one by definition, so it is
    // the one that needs an address to send an invoice to.
    contact_fields([],!$contacts);
    if($contacts)check_field('is_primary',t('Als Standardkontakt verwenden','Use as the standard contact'),false);
    submit_button(t('Kontakt hinzufügen','Add contact'));?></form>
</section>
<?php elseif($tab==='absence'): ?>
<section class="card"><h2><?=e(t('Abwesenheiten','Absences'))?></h2><?php $list=rows('SELECT * FROM absences WHERE student_id=? ORDER BY starts_on DESC',[$id]);if(!$list)echo '<p class="muted">'.e(t('Keine Abwesenheiten eingetragen.','No absences recorded.')).'</p>';foreach($list as $a):?><div class="record-row"><div><strong><?=e(reason_label($a['reason']))?></strong><p><?=e(fmt_date($a['starts_on']).' – '.fmt_date($a['ends_on']))?></p></div><?php start_form('absence_delete',['student_id'=>$id,'id'=>$a['id']],'inline-form');submit_button(t('Entfernen','Remove'),'subtle danger-text');?></form></div><?php endforeach ?></section>
<section class="card"><h2><?=e(t('Abwesenheit melden','Report absence'))?></h2><?php start_form('absence_add',['student_id'=>$id]);?><div class="grid three"><?php select_field('reason',t('Grund','Reason'),array_combine(array_keys(reasons()),array_map('reason_label',array_keys(reasons()))),'',true);input('starts_on',t('Von','From'),today(),'date',true);input('ends_on',t('Bis einschließlich','Up to and including'),today(),'date',true);?></div><?php submit_button(t('Abwesenheit eintragen','Record absence'));?></form></section>
<?php elseif($tab==='attendance'): $sum=attendance_summary($id); $rate=attendance_rate($sum); ?>
<?php if($rate!==null): ?>
<div class="stats-grid compact">
    <div class="stat"><span><?=e(t('Anwesend','Attended'))?></span><strong><?=e(number_format($rate,0).'%')?></strong><small><?=e(t('der letzten 6 Monate','of the last 6 months'))?></small></div>
    <div class="stat"><span><?=e(t('Trainings erfasst','Sessions recorded'))?></span><strong><?=array_sum($sum)?></strong><small><?=e(implode(' · ',array_map(fn($k,$v)=>attendance_label($k).' '.$v,array_keys($sum),$sum)))?></small></div>
</div>
<?php endif ?>
<section class="card">
    <h2><?=e(t('Letzte Trainings','Recent sessions'))?></h2>
    <?php $recent=attendance_recent($id);
    if(!$recent)echo '<p class="muted">'.e(t('Noch nichts erfasst. Anwesenheit wird beim Kurs eingetragen.','Nothing recorded yet. Attendance is entered on the class page.')).'</p>';
    foreach($recent as $r): ?>
    <div class="record-row">
        <div><strong><?=e(fmt_date($r['session_on']))?></strong><p><?=e($r['class_name'])?></p></div>
        <?php badge(attendance_label($r['status']),attendance_tone($r['status'])); ?>
    </div>
    <?php endforeach ?>
</section>
<?php elseif($tab==='classes'): $mine=student_enrolments($id); $requests=student_requests($id); ?>
<section class="card" id="courses">
    <h2><?=e(t('Kurse','Courses'))?></h2>
    <p class="muted"><?=e(t('Jede Kursteilnahme hat ihren eigenen Tarif. Ein Kind kann in mehreren Kursen sein und in jedem etwas anderes zahlen.','Each enrolment has its own tariff. A child can be in several courses and pay something different for each.'))?></p>
    <?php if(!$mine)echo '<p class="muted">'.e(t('Noch in keinem Kurs.','Not in any course yet.')).'</p>';
    foreach($mine as $row): $price=enrolment_price($row); $past=$row['left_on']!==null; ?>
    <div class="record-row<?=$past?' is-past':''?>">
        <div>
            <strong><a href="<?=e(url('classes',['id'=>$row['class_id']]))?>"><?=e($row['class_name'])?></a></strong>
            <p class="badge-line"><span><?=e($row['tariff_name']?:t('Kein Tarif gewählt','No tariff chosen'))?><?php
                if($price['cents']!==null) echo ' · '.e(money($price['cents']));?></span><?php
                if($price['own']) badge(t('Eigener Preis','Own price'),'amber');
            ?></p>
            <small><?=e($past?t('Ausgetreten am ','Left on ').fmt_date($row['left_on']):t('Dabei seit ','Member since ').fmt_date($row['joined_on']))?><?php
                if($price['note'])echo ' · '.e($price['note']);?></small>
        </div>
        <?php if($staff && !$past): ?>
        <div class="row-actions">
            <?php start_form('class_member_remove',['class_id'=>$row['class_id'],'student_id'=>$id,'mode'=>'leave'],'inline-form');
                  submit_button(t('Austritt eintragen','Record leaving'),'subtle');?></form>
        </div>
        <?php elseif(!$past): ?>
        <div class="row-actions">
            <?php start_form('enrolment_request',['student_id'=>$id,'class_id'=>$row['class_id'],'kind'=>'leave'],'inline-form');
                  submit_button(t('Abmeldung anfragen','Ask to leave'),'subtle');?></form>
        </div>
        <?php endif ?>
    </div>
    <?php if($staff && !$past): ?>
    <details><summary><?=e(t('Tarif, Zahlungsweise und Rabatt','Tariff, how it is paid, and any discount'))?></summary>
        <?php start_form('enrolment_save',['class_id'=>$row['class_id'],'student_id'=>$id]);
        $offered=class_tariffs((int)$row['class_id']);
        $chosenRates=$row['tariff_id']?tariff_rates((int)$row['tariff_id']):[];
        ?>
        <div class="grid two"><?php
        select_field('tariff_id',t('Tarif','Tariff'),array_column($offered,'name','id'),$row['tariff_id']);
        // Every way the chosen tariff may be paid, with what each one costs, so
        // "alle 3 Monate" does not have to be looked up somewhere else.
        $intervalOptions=[0=>t('wie im Tarif üblich','as the tariff usually is')
            .($chosenRates?' – '.billing_interval_label((int)$row['tariff_interval']).' '.money((int)($chosenRates[(int)$row['tariff_interval']]??0)):'')];
        foreach($chosenRates as $months=>$cents) $intervalOptions[$months]=billing_interval_label((int)$months).' – '.money((int)$cents);
        select_field('interval_months',t('Zahlungsweise','How it is paid'),$intervalOptions,(int)$row['enrolment_interval'],true);
        ?></div>
        <?php /* The tariff and how it is paid are what almost every child needs;
                 an own price, another payment day, the dates and a discount are
                 the exceptions, and wait here. A closed <details> still posts
                 its fields, so saving is the same either way (ADR 0011). Open
                 when this child already has one of them, so nothing set is
                 hidden. */
        $special=$row['price_cents']!==null||(int)$row['due_day']>0||(int)$row['discount_value']>0; ?>
        <details class="more-options" <?=$special?'open':''?>><summary><?=e(t('Mehr Möglichkeiten','More options'))?></summary>
        <div class="grid two"><?php
        $tariffPrice=$row['tariff_price']!==null?(int)$row['tariff_price']:null;
        default_field('price',t('Vereinbarter Preis (€)','Agreed price (€)'),amount_input($row['price_cents']===null?null:(int)$row['price_cents']),
            $tariffPrice!==null?amount_input($tariffPrice):'',
            $tariffPrice!==null?money($tariffPrice):t('kein Tarif gewählt','no tariff chosen'),'text',
            t('Leer lassen, um den Preis des Tarifs zu übernehmen.','Leave blank to follow the tariff’s price.'));
        input('price_note',t('Grund für den eigenen Preis','Why the different price'),$row['price_note']);
        input('due_day',t('Zahltag (0 = wie im Tarif)','Payment day (0 = as the tariff says)'),(int)$row['due_day'],'number',false,
              t('Der Tarif sagt: ','The tariff says: ').(int)($row['tariff_due_day']??1).'.');
        input('joined_on',t('Dabei seit','Member since'),$row['joined_on'],'date');
        input('left_on',t('Ausgetreten am','Left on'),$row['left_on'],'date');
        ?></div>
        <?php /* The discount belongs to the agreement with this family, not to
                 the price list. The tariff's templates are offered as a starting
                 point - picking one fills these three boxes in - and every one of
                 them stays editable, because the exception is the reason a
                 discount gets given in the first place. */
        $templates=$row['tariff_id']?tariff_discount_templates((int)$row['tariff_id']):[];
        ?>
        <h3><?=e(t('Rabatt für dieses Kind','Discount for this child'))?></h3>
        <?php if($templates): ?>
        <div class="placeholder-list"><?php foreach($templates as $template): ?>
            <span class="chip"><?=e($template['name'])?>: <?=e(discount_summary((int)$template['months'],(string)$template['kind'],(int)$template['value']))?></span>
        <?php endforeach ?></div>
        <p class="muted"><?=e(t('Vorlagen dieses Tarifs. Trage die Werte unten ein – der Name darf auf der Rechnung stehen.','Templates on this tariff. Type the values below – the name is what appears on the invoice.'))?></p>
        <?php endif ?>
        <div class="grid three"><?php
        select_field('discount_months',t('Wie lange','How long'),discount_spans(),(int)$row['discount_months'],true);
        select_field('discount_kind',t('Art','Kind'),['percent'=>t('Prozent','Per cent'),'fixed'=>t('Fester Betrag je Monat','Fixed amount per month')],$row['discount_kind'],true);
        input('discount_value',t('Wert','Value'),(int)$row['discount_value']===0?'':($row['discount_kind']==='fixed'?amount_input((int)$row['discount_value']):(string)(int)$row['discount_value']),'text',false,
              t('Prozent, oder Betrag in € je Monat.','Per cent, or an amount in € per month.'));
        ?></div>
        <?php input('discount_note',t('Name des Rabatts','What to call it'),$row['discount_note'],'text',false,
              t('Steht so auf der Rechnung, z. B. „Geschwisterrabatt“.','This is what appears on the invoice, e.g. “sibling discount”.')); ?>
        </details>
        <?php submit_button();?></form>
    </details>
    <?php endif ?>
    <?php endforeach ?>
</section>

<?php $open=courses_open_to($id); if($open): ?>
<section class="card" id="add-course">
    <h2><?=e($staff?t('In einen Kurs eintragen','Add to a course'):t('Freie Kurse','Courses you could join'))?></h2>
    <?php if(!$staff): ?><p class="muted"><?=e(t('Deine Anfrage geht an die Trainerin. Erst wenn sie zustimmt, bist du angemeldet.','Your request goes to the trainer. You are enrolled once she agrees.'))?></p><?php endif ?>
    <?php $pattern=class_days_for(array_column($open,'id'));
    foreach($open as $row): $full=course_is_full($row); $offered=class_tariffs((int)$row['id']); ?>
    <div class="record-row with-form">
        <div>
            <?php /* "Voll" is said of the course, so it stands by the course's
                     name - which is also where the eye looks for why this row,
                     alone, has nothing to press. */ ?>
            <div class="badge-line"><strong><?=e($row['name'])?></strong><?php if($full)badge(t('Voll','Full'),'red');?></div>
            <p><?=e(class_schedule($row,$pattern[(int)$row['id']]??[]))?></p>
            <small><?=e($offered?implode(' | ',array_map(fn($x)=>$x['name'].': '.tariff_summary($x),$offered)):t('Noch kein Tarif hinterlegt','No tariff set yet'))?></small>
        </div>
        <?php if(!$full){
            start_form('enrolment_request',['student_id'=>$id,'class_id'=>$row['id'],'kind'=>'join'],'row-form');
            if($offered) select_field('tariff_id',t('Tarif','Tariff'),array_column($offered,'name','id'),(int)$offered[0]['id']);
            submit_button($staff?t('Eintragen','Enrol'):t('Anmeldung anfragen','Ask to join'),'secondary');
            echo '</form>';
        } ?>
    </div>
    <?php endforeach ?>
</section>
<?php endif ?>

<?php if($requests): ?>
<section class="card">
    <h2><?=e(t('Anfragen','Requests'))?></h2>
    <?php foreach($requests as $r): ?>
    <div class="record-row">
        <div>
            <strong><?=e(request_kind_label((string)$r['kind']).' · '.$r['class_name'])?></strong>
            <p><?=e(request_state_label((string)$r['state']))?><?php if($r['tariff_name'])echo ' · '.e($r['tariff_name']);?></p>
            <?php if($r['decision_note']):?><p class="prewrap"><?=e($r['decision_note'])?></p><?php endif ?>
            <small><?=e(fmt_datetime((string)$r['created_at']))?></small>
        </div>
        <?php if($r['state']==='pending' && $staff): ?>
        <div class="row-actions">
            <?php start_form('enrolment_decide',['id'=>$r['id'],'decision'=>'approve'],'inline-form');submit_button(t('Annehmen','Approve'),'secondary');?></form>
            <?php start_form('enrolment_decide',['id'=>$r['id'],'decision'=>'decline'],'inline-form');submit_button(t('Ablehnen','Decline'),'subtle danger-text');?></form>
        </div>
        <?php endif ?>
    </div>
    <?php endforeach ?>
</section>
<?php endif ?>
<?php elseif($tab==='invoices'): $invoices=invoices_for($id); $open=uninvoiced_charges($id); ?>
<section class="card">
    <h2><?=e(t('Rechnungen','Invoices'))?></h2>
    <p class="muted"><?=e(t('Jede Rechnung wird beim Ausstellen festgeschrieben: das PDF zeigt immer den Stand von damals, auch wenn sich später etwas ändert. Es wird erst beim Herunterladen erzeugt und belegt keinen Speicherplatz.','Each invoice is frozen when it is issued: the PDF always shows how things stood then, even if something changes later. It is built when you download it and takes up no space.'))?></p>
    <?php if(!$invoices)echo '<p class="muted">'.e(t('Noch keine Rechnung.','No invoices yet.')).'</p>';
    foreach($invoices as $inv): ?>
    <div class="record-row">
        <div>
            <div class="badge-line"><strong><?=e($inv['number'])?></strong><?php badge(invoice_status_label($inv['status']),invoice_status_tone($inv['status'])); ?></div>
            <p><?=e(money((int)$inv['gross_cents']))?><?php if((int)$inv['tax_cents']>0)echo ' '.e(t('inkl. ','incl. ').money((int)$inv['tax_cents']).' '.t('USt','VAT'));?>
               · <?=e(t('zahlbar bis ','payable by ').fmt_date((string)$inv['due_on']))?></p>
            <small><?=e(t('Ausgestellt am ','Issued ').fmt_date((string)$inv['issued_on']))?><?php
                if($inv['sent_at'])echo ' · '.e(t('per E-Mail geschickt am ','emailed ').fmt_datetime((string)$inv['sent_at']));
                if($inv['cancelled_at'])echo ' · '.e(t('storniert: ','cancelled: ').($inv['cancel_reason']?:t('ohne Grund','no reason given')));?></small>
        </div>
        <div class="row-actions">
            <a class="button secondary" href="<?=e(url('download',['what'=>'invoice','id'=>$inv['id']]))?>"><?=e(t('PDF herunterladen','Download PDF'))?></a>
            <?php if($staff && $inv['status']!=='cancelled'): ?>
                <?php if($inv['status']!=='paid'): ?>
                <details><summary><?=e(t('Als bezahlt eintragen','Mark as paid'))?></summary>
                    <?php start_form('invoice_state',['id'=>$inv['id'],'mode'=>'paid']); ?>
                    <div class="grid two"><?php
                    input('paid_on',t('Bezahlt am','Paid on'),today(),'date',true);
                    $methods=(array)setting('payment_methods');
                    select_field('method',t('Zahlungsart','How'),array_combine($methods,$methods),$methods[0]??'',true);
                    ?></div>
                    <?php input('note',t('Notiz','Note'));submit_button(t('Als bezahlt eintragen','Mark as paid'));?></form>
                </details>
                <?php endif ?>
                <?php start_form('invoice_state',['id'=>$inv['id'],'mode'=>'send'],'inline-form');submit_button(t('Per E-Mail schicken','Email it'),'subtle');?></form>
                <?php if((int)$inv['paid_cents']===0): ?>
                <details class="account-delete"><summary><?=e(t('Stornieren','Cancel'))?></summary>
                    <p><?=e(t('Die Nummer bleibt vergeben – eine Lücke in der Nummernfolge wäre bei einer Prüfung nicht erklärbar.','The number stays used – a hole in the sequence would be impossible to explain at an audit.'))?></p>
                    <?php start_form('invoice_state',['id'=>$inv['id'],'mode'=>'cancel']);
                    input('note',t('Grund','Reason'),'','text',true);
                    submit_button(t('Rechnung stornieren','Cancel invoice'),'danger');?></form>
                </details>
                <?php endif ?>
            <?php endif ?>
        </div>
    </div>
    <?php endforeach ?>
</section>

<?php if($staff): ?>
<section class="card">
    <h2><?=e(t('Rechnung ausstellen','Issue an invoice'))?></h2>
    <?php $problems=invoice_issuer_problems(); if($problems): ?>
    <div class="notice warn"><strong><?=e(t('Es fehlen noch Angaben zum Betrieb:','Some details about the business are still missing:'))?></strong>
        <?php foreach($problems as $problem): ?><br><?=e($problem)?><?php endforeach ?>
        <p><?=link_button(t('Jetzt eintragen','Fill them in now'),'settings',['tab'=>'organisation'],'secondary')?></p></div>
    <?php elseif(!$open): ?>
    <p class="muted"><?=e(t('Alle Beiträge dieses Kindes stehen schon auf einer Rechnung.','Every charge for this child is already on an invoice.'))?></p>
    <?php else: ?>
    <p class="muted"><?=e(t('Wähle die Beiträge aus, die auf die Rechnung sollen. Mehrere ergeben eine Rechnung mit mehreren Zeilen.','Choose the charges that belong on the invoice. Several make one invoice with several lines.'))?></p>
    <?php start_form('invoice_create',['student_id'=>$id]); ?>
    <div class="recipient-list">
    <?php foreach($open as $c): ?>
        <label class="recipient"><input type="checkbox" name="charge_ids[]" value="<?=(int)$c['id']?>" checked>
            <span><strong><?=e($c['label'])?></strong><small><?=e(money((int)$c['amount_cents']).' · '.t('fällig am ','due ').fmt_date((string)$c['due_on']))?></small></span></label>
    <?php endforeach ?>
    </div>
    <div class="grid two"><?php
    input('issued_on',t('Rechnungsdatum','Invoice date'),today(),'date',true);
    input('terms',t('Zahlungsziel in Tagen','Payment term in days'),(int)setting('invoice_terms_days'),'number');
    ?></div>
    <?php submit_button(t('Rechnung ausstellen','Issue the invoice'));?></form>
    <?php endif ?>
</section>
<?php endif ?>

<?php elseif($tab==='payments'): ?>
<?php if($staff): $enrolments=student_enrolments($id); $paying=array_filter($enrolments,fn($r)=>$r['left_on']===null && $r['tariff_id']!==null); ?>
<section class="card">
    <div class="section-heading">
        <div>
            <h2><?=e(t('Automatische Beiträge','Automatic charges'))?></h2>
            <p class="muted"><?php
                if((int)($s['billing_paused']??0)===1) echo e(t('Pausiert – für dieses Kind werden keine Beiträge angelegt.','Paused – no charges are created for this child.'));
                elseif(!$paying) echo e(t('Beiträge entstehen aus den Kursen. Dieses Kind ist in keinem Kurs mit Tarif.','Charges come from courses. This child is in no course with a tariff.'));
                else echo e(t('Aus ','From ').plural(count($paying),'Kurs','Kursen','course','courses').': '
                    .implode(' | ',array_map(fn($r)=>$r['class_name'].' – '.enrolment_summary($r),$paying)));
            ?></p>
        </div>
    </div>
    <?php if($s['billing_note']??'')echo '<p class="muted">'.e($s['billing_note']).'</p>'; ?>
    <?php start_form('billing_pause',['id'=>$id,'mode'=>((int)($s['billing_paused']??0)===1?'resume':'pause')]);
    if((int)($s['billing_paused']??0)!==1) input('billing_note',t('Grund (optional, nur intern)','Reason (optional, internal only)'),'','text');
    submit_button((int)($s['billing_paused']??0)===1?t('Beiträge wieder starten','Resume billing'):t('Beiträge pausieren','Pause billing'),'secondary'); ?></form>
</section>
<?php endif ?>
<div class="stats-grid compact"><div class="stat"><span><?=e(t('Offen','Outstanding'))?></span><strong><?=e(money(balance($id)))?></strong></div><div class="stat"><span><?=e(t('Überfällig','Overdue'))?></span><strong class="due"><?=e(money(balance($id,true)))?></strong></div></div>
<?php $charges=student_charges($id);$paymentsOf=payments_by_charge(array_column($charges,'id'));if(!$charges)echo '<div class="card"><p class="muted">'.e(t('Noch keine Beiträge erfasst.','No charges recorded yet.')).'</p></div>';foreach($charges as $c):$remaining=max(0,(int)$c['amount_cents']-(int)$c['paid']); ?>
<section class="card charge-card"><div class="section-heading"><div><h2><?=e($c['label'])?></h2><p class="muted"><?=e(t('Fällig am ','Due ').fmt_date($c['due_on']))?></p></div><?php badge($c['cancelled']?t('Storniert','Cancelled'):($remaining===0?t('Bezahlt','Paid'):money($remaining).' '.t('offen','outstanding')),$c['cancelled']?'':($remaining===0?'green':($c['due_on']<today()?'red':'')));?></div>
<dl class="facts"><div><dt><?=e(t('Beitrag','Charge'))?></dt><dd><?=e(money((int)$c['amount_cents']))?></dd></div><div><dt><?=e(t('Zeitraum','Coverage period'))?></dt><dd><?php /* The period the invoice names, not the stored one: a child who joined mid-month is billed from the day they joined (charge_period_text()). */ $period=charge_period_text($c); ?><?=e($period!==''?$period:t('Einmalig / ohne Zeitraum','One-time / no period'))?></dd></div></dl>
<?php
// Transfer details for whatever is still open on this charge.
if($remaining>0 && !$c['cancelled'] && setting('show_payment_qr')):
    $profile=charge_payment_profile($c);
    if($profile && $profile['iban']!==''):
        $reference=charge_reference($c,$s);
        $payload=qr_payload($profile,$remaining,$reference);
?>
<div class="pay-box">
    <div class="pay-qr"><?=$payload!==''?qr_svg($payload,200):''?></div>
    <div class="pay-details">
        <h3><?=e(t('Offenen Betrag überweisen','Transfer the outstanding amount'))?></h3>
        <p class="muted"><?=e(t('Mit der Bank-App den Code scannen – Betrag und Verwendungszweck werden übernommen.','Scan the code with your banking app and the amount and reference are filled in for you.'))?></p>
        <dl class="facts">
            <div><dt><?=e(t('Betrag','Amount'))?></dt><dd><?=e(money($remaining))?></dd></div>
            <div><dt><?=e(t('Empfänger','Recipient'))?></dt><dd><?=e($profile['recipient']!==''?$profile['recipient']:$profile['name'])?></dd></div>
            <div><dt>IBAN</dt><dd class="mono"><?=e(iban_groups($profile['iban']))?></dd></div>
            <?php if($profile['bic']):?><div><dt>BIC</dt><dd class="mono"><?=e($profile['bic'])?></dd></div><?php endif ?>
            <div><dt><?=e(t('Verwendungszweck','Reference'))?></dt><dd><?=e($reference)?></dd></div>
        </dl>
        <?php if($profile['note'])echo '<p class="muted">'.e($profile['note']).'</p>';?>
    </div>
</div>
<?php endif; endif ?>
<?php $payments=$paymentsOf[(int)$c['id']]??[];$allocated=0;foreach($payments as $p):if(!$p['voided'])$allocated+=(int)$p['amount_cents'];?><div class="record-row"><div><strong><?=e(money((int)$p['amount_cents']))?></strong><p><?=e(fmt_date($p['paid_on']).' · '.$p['method'])?></p><?php badge($p['voided']?t('Storniert','Voided'):($p['confirmed_at']?t('Bestätigt','Confirmed'):t('Unbestätigt','Unconfirmed')),$p['confirmed_at']&&!$p['voided']?'green':'');if($p['note'])echo '<p>'.e($p['note']).'</p>';if($staff&&$p['confirmed_at'])echo '<small>'.e(t('Bestätigt von ','Confirmed by ').($p['confirmer']??t('gelöschtem Konto','deleted account')).' · '.fmt_datetime($p['confirmed_at'])).'</small>';?></div>
<?php if($staff&&!$p['voided']):?><div class="row-actions"><?php if(!$p['confirmed_at']){start_form('payment_state',['id'=>$p['id'],'mode'=>'confirm'],'inline-form');submit_button(t('Bestätigen','Confirm'),'secondary');echo '</form>';}start_form('payment_state',['id'=>$p['id'],'mode'=>'void'],'inline-form');submit_button(t('Stornieren','Void'),'subtle danger-text');?></form></div><?php endif ?></div><?php endforeach ?>
<?php if($staff&&!$c['cancelled']):?>
<?php if((int)$c['amount_cents']>$allocated):?><details><summary><?=e(t('+ Zahlung erfassen','+ Record payment'))?></summary><?php start_form('payment_add',['charge_id'=>$c['id']]);?><div class="grid three"><?php input('amount',t('Betrag (€)','Amount (€)'),amount_input((int)$c['amount_cents']-$allocated),'text',true);input('paid_on',t('Zahlungsdatum','Payment date'),today(),'date',true);$methods=setting('payment_methods',['Überweisung','Bar']);select_field('method',t('Zahlungsart','Payment method'),array_combine($methods,$methods),$methods[0],true);?></div><?php input('note',t('Notiz / Buchungsreferenz','Note / payment reference'));check_field('confirmed',t('Zahlungseingang bestätigen','Confirm receipt of payment'));submit_button(t('Zahlung erfassen','Record payment'));?></form></details><?php endif ?>
<?php if(!$allocated){start_form('charge_cancel',['id'=>$c['id']],'inline-form');submit_button(t('Beitrag stornieren','Cancel charge'),'subtle danger-text');echo '</form>';}endif ?></section>
<?php endforeach ?>
<?php $proofs=rows('SELECT * FROM payment_proofs WHERE student_id=? ORDER BY created_at DESC, id DESC',[$id]); ?>
<section class="card">
    <h2><?=e(t('Zahlungsbeleg','Proof of payment'))?></h2>
    <p class="muted"><?=e($staff
        ? t('Familien können hier einen Beleg hochladen. Das ist freiwillig und ersetzt keine Bestätigung.','Families can upload a proof here. It is optional and does not replace confirming the payment.')
        : t('Wenn du überwiesen hast, kannst du hier den Beleg hochladen. Das musst du nicht – es geht dann nur schneller.','If you have made the transfer you can upload the confirmation here. You do not have to – it just makes it quicker.'))?></p>
    <?php foreach($proofs as $proof): ?>
    <div class="record-row">
        <div><strong><a href="<?=e(url('download',['what'=>'proof','id'=>$proof['id']]))?>"><?=e($proof['original_name']?:t('Beleg','Proof'))?></a></strong>
            <p><?=e(fmt_datetime((string)$proof['created_at']).' · '.round(((int)$proof['bytes'])/1024).' kB')?></p>
            <?php if($proof['note']):?><p><?=e($proof['note'])?></p><?php endif ?></div>
        <?php if($staff): ?><div class="row-actions"><?php start_form('proof_delete',['id'=>$proof['id']],'inline-form');submit_button(t('Entfernen','Remove'),'subtle danger-text');?></form></div><?php endif ?>
    </div>
    <?php endforeach ?>
    <?php start_form('proof_upload',['student_id'=>$id],'form',true);
    file_field('proof',t('Beleg als Foto oder PDF','Proof as a photo or PDF'),'proof');
    input('note',t('Notiz (optional)','Note (optional)'),'','text',false,'',t('z. B. „am 3. überwiesen“','e.g. “transferred on the 3rd”'));
    submit_button(t('Beleg hochladen','Upload the proof'),'secondary');?></form>
</section>

<?php if($staff): $first=array_values($paying)[0]??null; ?>
<section class="card"><h2><?=e(t('Beitrag von Hand anlegen','Create a charge by hand'))?></h2>
<p class="muted"><?=e(t('Für alles, was kein regelmäßiger Kursbeitrag ist – Turniergebühr, Schläger, Hallenmiete.','For anything that is not a recurring course fee – a tournament entry, a racket, hall hire.'))?></p>
<?php start_form('charge_add',['student_id'=>$id]);?><div class="grid two"><?php
input('label',t('Bezeichnung','Description'),'','text',true);
input('amount',t('Betrag (€)','Amount (€)'),$first?amount_input(enrolment_price($first)['cents']):'','text',true);
input('period_from',t('Bezahlt für Zeitraum ab','Covers from'),'','date');
input('period_to',t('Bis einschließlich','Covers through'),'','date');
input('due_on',t('Fällig am','Due on'),date('Y-m-d',strtotime('+14 days')),'date',true);
?></div><?php submit_button(t('Beitrag anlegen','Create charge'));?></form></section><?php endif ?>
<?php endif ?>
