<?php
/* A student who exists. A new one is made by the wizard (views/student_new.php,
   ADR 0023 §5); the router sends a student page without an id there. */
$id=(int)($_GET['id']??0);$staff=is_staff($user);
$s=student($id);
$tab=$_GET['tab']??'details';
$tabsAllowed=$staff?['details','contacts','payments','invoices','absence','classes','attendance']:['details','contacts','payments','invoices','absence','classes'];
if(!in_array($tab,$tabsAllowed,true))$tab='details';
page_head($s['first_name'].' '.$s['last_name'],status_label($s['status']),
    // A family has one student and this is their page (ADR 0010): a list of one
    // is not somewhere to go back to.
    // up-link: on a phone the bar's „‹ Schüler" is this way back.
    $staff?link_button(t('Alle Schüler','All students'),'students',[],'secondary up-link'):'');
$tabLabels=['details'=>t('Profil','Profile'),'contacts'=>t('Kontakte','Contacts'),'payments'=>t('Beiträge','Payments'),
            'invoices'=>t('Rechnungen','Invoices'),'classes'=>t('Kurse','Courses'),'absence'=>t('Abwesenheit','Absences')];
if($staff){$tabLabels['attendance']=t('Anwesenheit','Attendance');}
tabs($tabLabels,$tab,'student',['id'=>$id]);
/* Creating a child asks for a name and an address and nothing else - a form
   with twenty boxes on it is a form somebody abandons half-way. The rest is not
   optional, though: a child with no course is a child nobody bills. So the page
   says what is left, in the order she would do it, rather than leaving her to
   remember four days later. A family gets the list of what is theirs to fill
   in (ADR 0020, §7): the details and somebody to ring. On every tab, like
   hers. */
next_steps_card($staff?student_next_steps($id):family_next_steps($id),$staff?'':t('Noch zu ergänzen','Still to fill in'));
if($tab==='details'):
    $login=$staff && $s['account_id']?one('SELECT * FROM accounts WHERE id=?',[(int)$s['account_id']]):null;
    $firstName=$s['first_name']!==''?$s['first_name']:t('die Schülerin oder der Schüler','the student');
    $mailReady=$staff && account_mail_ready();
    start_form('student_save',['id'=>$id,'revision'=>$s['revision']??0],'form'); ?>
<section class="card" id="personal"><h2><?=e(t('Persönliche Daten','Personal details'))?></h2>
<?php if(!$staff): ?>
<p class="muted"><?=e(t('Hier ergänzt oder korrigierst du deine Angaben. Felder mit * bitte ausfüllen. Deine Trainerin sieht, was du änderst.',
                        'Fill in or correct your details here. Please fill in the fields marked *. Your coach can see what you change.'))?></p>
<?php endif ?>
<div class="grid two"><?php
input('first_name',t('Vorname','First name'),$s['first_name'],'text',true,'','',['maxlength'=>'100']);
input('last_name',t('Nachname','Last name'),$s['last_name'],'text',true,'','',['maxlength'=>'100']);
/* The student's own address (ADR 0020, §1): it signs in, and the invitation,
   the invoices and the „vergessen" link go to it. Directly under the name,
   because a name and an address are all it takes to invite somebody.

   Before there is a login it is hers to type; while the invitation is open she
   may still correct it, and saving sends the invitation again; once the login
   is in use it is its holder's, and only they change it - with a confirmation
   from the new mailbox, so nobody can move a family's way back in to a mailbox
   of their own. No box is posted then, which is what student_save expects. An
   address that is already somebody's login is refused when it is saved, and
   said on the access card below before anybody taps. */
if($staff):
    echo '<div id="email">';
    // Only a login with an address signs in with it: a placeholder signs in
    // with nothing (ADR 0023 §3), so for it the address on the record is hers
    // to type, as before a login.
    $signsInWithAddress=(string)($login['email']??'')!=='';
    if($signsInWithAddress && $login['state']!=='invited') {
        echo '<div class="field"><label>'.e(t('E-Mail-Adresse','Email address')).'</label><div class="readonly">'.e($login['email']).'</div><small>'
            .e($firstName.t(' meldet sich damit an und ändert sie selbst unter „Mein Konto“ – mit Bestätigung aus dem neuen Postfach. Dorthin gehen auch Rechnungen und Erinnerungen.',
                            ' signs in with it and changes it themselves under “My account” – confirmed from the new mailbox. Invoices and reminders go there too.'))
            .'</small></div>';
    } elseif($signsInWithAddress) {
        input('email',t('E-Mail-Adresse','Email address'),$login['email'],'email',true,
              t('Einladung noch nicht angenommen. Änderst du die Adresse, geht die Einladung beim Speichern an die neue; der alte Link gilt dann nicht mehr.',
                'The invitation has not been accepted yet. If you change the address, saving sends the invitation to the new one; the old link then stops working.'));
    } else {
        input('email',t('E-Mail-Adresse','Email address'),$s['email'],'email',false,
              t('Die eigene Adresse der Schülerin oder des Schülers – die der Eltern gehört zu den Kontakten.',
                'The student’s own address – a parent’s belongs with the contacts.'));
    }
    echo '</div>';
endif;
/* How old, and the age group that follows (Part 1, revised 2026-10-08): the
   first of the trainer's bands that covers the age, from the date of birth
   alone - shown here once, under the date it comes from. A gap in the bands is
   hers to close under Verwaltung, so only staff read that none fits. */
$age=student_age($s['birth_date']??null);
$band=student_age_group($s);
echo '<div id="birth-date">';
input('birth_date',t('Geburtsdatum','Date of birth'),$s['birth_date'],'date',false,
    match(true) {
        $age===null  => t('Fehlt noch. Danach richtet sich die Altersgruppe.','Still missing. It decides the age group.'),
        $band!==null => plural($age,'Jahr alt','Jahre alt','year old','years old').' · '.t('Altersgruppe: ','Age group: ').$band['name'],
        $staff       => plural($age,'Jahr alt','Jahre alt','year old','years old').' · '.t('keine Altersgruppe passt','no age group fits'),
        default      => plural($age,'Jahr alt','Jahre alt','year old','years old'),
    });
echo '</div>';
?></div>
<h3><?=e(t('Anschrift und Telefon','Address and telephone'))?></h3>
<?php /* One line for the address, the way an anmeldeformular asks it, because it
         is typed once and never sorted on. The telephone is the
         member's own: for an adult those are the same person as the emergency
         contact, and listing yourself as who to ring is not a record anybody
         should have to keep. A family writes both too (ADR 0020, §7): the
         address is the one their next invoice is made out to. One block for
         both roles, inside the form so one save carries them; only the hints
         are said to each in their own words. */
echo '<div id="address">';
input('address',t('Anschrift','Postal address'),$s['address']??'','text',false,
      $staff?t('Straße, PLZ und Ort in einer Zeile. Gehört ab 400 € Rechnungsbetrag auf die Rechnung.',
               'Street, postcode and town on one line. Required on an invoice above 400 €.')
            :t('Straße, Hausnummer, PLZ und Ort in einer Zeile. Sie steht auf deinen Rechnungen; eine Rechnung über 400 € braucht sie.',
               'Street, number, postcode and town on one line. It goes on your invoices, and an invoice over 400 € needs it.'),
      '',['autocomplete'=>'street-address']);
echo '</div>';
input('phone',t('Telefonnummer','Telephone number'),$s['phone']??'','tel',false,
      $staff?t('Die eigene Nummer. Wen wir im Notfall anrufen, steht unter „Kontakte“.',
               'Their own number. Who to ring in an emergency is under “Contacts”.')
            :t('Deine eigene Nummer. Wer im Notfall angerufen wird, steht unter „Kontakte“.',
               'Your own number. Who is rung in an emergency is under “Contacts”.'),
      '',['autocomplete'=>'tel']);
?>
</section>

<?php if($staff):
/* What the child is in the club, in the order it is asked: whether and since
   when they are a member, then how far along. How old they are is under the
   date of birth it follows from, and nobody sets it (Part 1, revised
   2026-10-08). The prices went to the course (ADR 0011) - a tariff on the child
   billed nobody - and the two dates came here with the status they belong to.
   Inside the student form, so „Schüler speichern" keeps saving them. */ ?>
<section class="card"><h2><?=e(t('Einteilung','Grouping'))?></h2>
<div class="grid three"><?php
select_field('status',t('Mitgliedschaft','Membership'),array_combine(array_keys(statuses()),array_map('status_label',array_keys(statuses()))),$s['status'],true,false,
    t('Probetraining, aktiv, pausiert oder beendet.','On trial, active, paused or ended.'));
input('joined_on',t('Dabei seit','Member since'),$s['joined_on'],'date',false,
    t('Im Verein. Wann das Kind in einen Kurs kam, steht beim Kurs.','In the club. When the child joined a course is shown with the course.'));
input('ended_on',t('Mitgliedschaft bis','Membership until'),$s['ended_on'],'date',false,
    t('Leer lassen, solange kein Ende feststeht.','Leave it empty while no end is fixed.'));
select_field('level_id',t('Leistungsgruppe','Level'),array_column(rows('SELECT id,name FROM levels WHERE archived=0 OR id=? ORDER BY sort_order,name',[$s['level_id']??0]),'name','id'),$s['level_id'],false,false,
    t('Du wählst sie. Neue Kinder starten in ','You choose it. New children start in ').(level_default()['name']??'–').'.');
?></div></section>
<?php else:?><section class="card"><h2><?=e(t('Mitgliedschaft','Membership'))?></h2><dl class="facts"><div><dt><?=e(t('Dabei seit','Member since'))?></dt><dd><?=e(fmt_date($s['joined_on']))?></dd></div><div><dt><?=e(t('Mitgliedschaft bis','Membership until'))?></dt><dd><?=e(fmt_date($s['ended_on']))?></dd></div></dl></section><?php endif ?>
<?php if($staff):?><section class="card"><details <?=$s['internal_notes']?'open':''?>><summary><?=e(t('Interne Notizen','Internal notes'))?></summary><?php input('internal_notes',t('Nur für die Verwaltung sichtbar','Visible to management only'),$s['internal_notes'],'textarea');?></details></section><?php endif ?>
<div class="form-footer"><?php submit_button($staff?t('Schüler speichern','Save student'):t('Angaben speichern','Save the details'));?></div></form>
<?php if(!$staff): /* Mein Konto, for a family: the login rather than the
         child, reached from here since their bar has four places and no
         „Konto" (owner, 2026-10-07). */ ?>
<section class="card"><a class="editor-list-item" href="<?=e(url('profile'))?>"><span><strong><?=e(t('Anmeldung und Darstellung','Sign-in and appearance'))?></strong>
    <small><?=e(my_account_hint())?></small></span><?=icon('chevron')?></a></section>
<?php endif ?>
<?php if($staff):
/* Everything about this student's login, in one card and in the order it
   happens (ADR 0030 §6, spec addendum §3): without sign-in, invited, in use,
   suspended. Outside the student form, because each of these is its own
   decision and its own POST - saving a birth date should not send anybody an
   email - and a form inside a form is thrown away by the browser. Nobody here
   sets or sees a password (ADR 0020, §5). Every form on the card names it as
   the place a refusal comes back to (return_anchor, FORM_RETURN_ANCHORS), so
   she is not left at the top of a long page with the card out of sight. */
$loginState=login_without_sign_in($login)?'placeholder':$login['state'];
$atCard=['return_anchor'=>'access'];
// The card's own forms, each of which carries $atCard: the one list the note below asks.
$cardActions=['student_invite','account_state','impersonate']; ?>
<section class="card access-card" id="access">
    <div class="badge-line access-title"><h2><?=e(t('Zugang zum Portal','Access to the portal'))?></h2><?php login_state_badge($login); ?></div>
<?php /* A refusal of one of the card's own forms comes back here, with what was
         typed; the banner that says why is at the top of the page, out of
         sight at the card, so the card says it too. Read, not taken: the
         layout still prints the banner. */
if(($_SESSION['flash']['kind']??'')==='error' && array_filter($cardActions,fn(string $action)=>held_for($action)!==[])): ?>
    <div class="notice warn" role="status"><p><?=e((string)$_SESSION['flash']['message'])?></p></div>
<?php endif;
if($loginState==='placeholder'):
    /* The address and „Einladung senden" in one form: she gets the address
       weeks after adding the child, from a parent's message, with this card
       open - one form, one tap. The box shows the record's address, which the
       invitation writes back byte for byte (invite_student()); Persönliche
       Daten keeps its own box for an address kept while mail is not set up.

       Said before she taps rather than in the refusal after it (ADR 0020, §1;
       0030 §5): the address on the record is somebody else's login, or a
       brother's or sister's record without sign-in carries it too - typed in
       before every person needed their own. Staff only, so they may be named.
       No form then, because an invitation there can only be refused; the
       record's own box above is where the address is put right, and the next
       steps lead there. */
    $holder=$s['email']!==''?account_with_address((string)$s['email']):null;
    $sharer=$s['email']!=='' && !$holder?student_without_login_at((string)$s['email'],$id):null; ?>
    <p><?=e($firstName.t(' meldet sich noch nicht an. Du trägst alles selbst ein.',' does not sign in yet. You enter everything yourself.'))?></p>
    <?php if($holder || $sharer): ?>
    <div class="notice warn"><strong><?=e($holder
            ? strtr(t('Diese Adresse gehört schon zum Zugang von {name}','This address already belongs to {name}’s login'),['{name}'=>login_holder_name($holder)])
            : strtr(t('Diese Adresse steht auch bei {name}','This address is on {name}’s record too'),['{name}'=>$sharer['first_name'].' '.$sharer['last_name']]))?></strong>
        <p><?=e($firstName.t(' braucht eine eigene. Trag sie oben unter „E-Mail-Adresse“ ein und speichere – die Adresse der Eltern gehört zu den Kontakten.',
                             ' needs one of their own. Enter it above under “Email address” and save – a parent’s address belongs with the contacts.'))?></p></div>
    <?php elseif(!$mailReady): mail_not_ready_notice($user);
    else:
        start_form('student_invite',['student_id'=>$id]+$atCard);
        // autocomplete="off" first, so it wins: her iPhone would offer her own address.
        input('email',t('E-Mail-Adresse','Email address'),$s['email'],'email',true,
              strtr(t('Die eigene Adresse von {name} – die der Eltern gehört zu den Kontakten.','{name}’s own address – a parent’s belongs with the contacts.'),['{name}'=>$firstName]),
              '',['autocomplete'=>'off']+sign_in_address_attributes());
        select_field('locale',t('Sprache der Einladung','Invitation language'),['de'=>'Deutsch','en'=>'English'],(string)($login['locale']??'de'),true);
        submit_button(t('Einladung senden','Send the invitation')); ?></form>
    <?php endif ?>
<?php else:
    $deleteText=t('Löscht die Anmeldung und die privaten Unterhaltungen. ','Deletes the login and the private conversations. ')
        .$firstName.t(' bekommt eine neue, leere; Kurse, Beiträge und Rechnungen bleiben, und du kannst neu einladen. Nur vorübergehend? Dann lieber sperren.',
                      ' gets a new, empty one; the courses, charges and invoices stay, and you can invite again. Only for a while? Then suspend instead.');
    if($loginState==='suspended'): ?>
    <p><?=e(t('Gesperrt – ','Suspended – ').$firstName.t(' kann sich nicht anmelden. Daten und Nachrichten bleiben.',' cannot sign in. Details and messages stay.'))?></p>
    <?php endif;
    login_facts($login);
    $invitationLive=false;
    if($loginState==='invited'):
        /* What she needs when a parent says "the link doesn't work" (ADR 0020,
           §10c): when it was sent and until when it works, and how long a new
           one lasts, as token_lifetime() makes it. An expired invitation's row
           is usually gone by the next morning (prune_expired()), and then the
           line says so without a date rather than guessing one. */
        $sent=invitation_dates((int)$login['id']);
        $invitationLive=invitation_link_live($sent);
        $valid=token_lifetime_words('invite',locale()==='en'); ?>
    <p><?=e(match(true) {
        $invitationLive => strtr(t('Eingeladen am {sent}; der Link gilt bis {until}.',
                                   'Invited on {sent}; the link is valid until {until}.'),
                                 ['{sent}'=>fmt_datetime((string)$sent['created_at']),'{until}'=>fmt_datetime((string)$sent['expires_at'])]),
        $sent!==null    => strtr(t('Die Einladung vom {sent} ist abgelaufen. Schick sie noch einmal – der neue Link gilt wieder {valid}.',
                                   'The invitation of {sent} has expired. Send it again – the new link is valid for another {valid}.'),
                                 ['{sent}'=>fmt_date((string)$sent['created_at']),'{valid}'=>$valid]),
        default         => strtr(t('Die Einladung ist abgelaufen. Schick sie noch einmal – der neue Link gilt wieder {valid}.',
                                   'The invitation has expired. Send it again – the new link is valid for another {valid}.'),
                                 ['{valid}'=>$valid]),
    })?></p>
    <?php if(!$mailReady) mail_not_ready_notice($user);
    endif;
    /* Whether a mailed link set the password lately - „vergessen", or the
       button below (ADR 0019, I1; 0020, §8): the same thing the holder sees on
       Mein Konto. */
    if($loginState!=='invited' && ($lastReset=password_resets_for((int)$login['id'],PASSWORD_RESET_SHOWN_DAYS)[0]??null)): ?>
    <p class="muted"><?=e(t('Passwort zuletzt per E-Mail-Link neu gesetzt: ','Password last set anew through an email link: ').fmt_datetime((string)$lastReset['created_at']))?></p>
    <?php endif ?>
    <div class="row-actions access-actions">
    <?php if($loginState==='invited'):
        // The way forward is the button when the link no longer works.
        if($mailReady){start_form('account_state',['id'=>$login['id'],'mode'=>'reinvite']+$atCard,'inline-form');submit_button(t('Einladung erneut senden','Send the invitation again'),$invitationLive?'secondary':'primary');echo '</form>';}
        /* Nothing to type for a login never set up (ADR 0021, §3): nothing is
           lost, and inviting again is the way back. One that was set up and is
           somehow invited again keeps the typed address. */
        if(empty($login['verified_at']))
            invitation_withdraw_details($login,t('Einladung zurückziehen','Withdraw the invitation'),
                t('Der Link in der Einladung gilt dann nicht mehr. ','The link in the invitation then stops working. ')
                .$firstName.t(', Kurse, Beiträge und Rechnungen bleiben. Versehentlich? Einfach neu einladen.',', the courses, charges and invoices stay. By mistake? Just invite again.'),$atCard);
        else
            login_delete_details($login,t('Einladung zurückziehen','Withdraw the invitation'),$deleteText,t('Einladung endgültig zurückziehen','Withdraw the invitation for good'),$atCard);
    elseif($loginState==='active'):
        if(may_impersonate($user,$login)){start_form('impersonate',['id'=>$login['id'],'mode'=>'start']+$atCard,'inline-form');submit_button(t('Portal als ','View the portal as ').$firstName.t(' ansehen',''),'secondary');echo '</form>';}
        /* A link for a new password, to the login's own address (ADR 0020,
           §5). Not destructive: the old password works until the link is used,
           and the link lapses in an hour. Only for a login in use, and only
           when mail can go out - a button that can only fail is not offered. */
        if($mailReady && reset_link_possible($login)){start_form('account_state',['id'=>$login['id'],'mode'=>'reset_link']+$atCard,'inline-form');submit_button(t('Link zum Zurücksetzen senden','Send a reset link'),'secondary');echo '</form>';}
        start_form('account_state',['id'=>$login['id'],'mode'=>'suspend']+$atCard,'inline-form');submit_button(t('Zugang sperren','Suspend the access'),'secondary');echo '</form>';
        login_delete_details($login,t('Anmeldung löschen','Delete the sign-in'),$deleteText,t('Anmeldung endgültig löschen','Delete the sign-in for good'),$atCard);
    else:
        start_form('account_state',['id'=>$login['id'],'mode'=>'restore']+$atCard,'inline-form');submit_button(t('Zugang entsperren','Restore the access'),'secondary');echo '</form>';
        login_delete_details($login,t('Anmeldung löschen','Delete the sign-in'),$deleteText,t('Anmeldung endgültig löschen','Delete the sign-in for good'),$atCard);
    endif ?>
    </div>
<?php endif ?>
</section>
<details class="danger-zone" data-sheet><summary><?=e(t('Schüler löschen','Delete student'))?></summary>
    <?php /* Said before the tap: the change log records what was deleted, but
             nothing brings it back. */ ?>
    <p><?=e(t('Das lässt sich nicht rückgängig machen. Die Änderungen verzeichnen zwar, was gelöscht wurde, stellen es aber nicht wieder her. Nur möglich, wenn keine Beiträge vorhanden sind – sonst die Mitgliedschaft beenden.','This cannot be undone. The change log records what was deleted, but it cannot bring it back. Only possible when no charges exist – otherwise, end the membership.'))?></p>
    <?php /* A student's login never set up is deleted with the student, so its
             link cannot rebuild the record (ADR 0021, §4); any other login stays
             - the foreign key only unhooks it - and turns up under Zugänge. */
    if($loginState==='placeholder'): ?><p><?=e(t('Die leere Anmeldung geht mit.','The empty login goes with it.'))?></p>
    <?php elseif(login_goes_with_student($login)): ?><p><?=e(t('Die offene Einladung wird dabei zurückgezogen.','The open invitation is withdrawn with it.'))?></p>
    <?php elseif($login): ?><p><?=e($firstName.t(' hat einen Zugang. Lösche ihn vorher, sonst bleibt er unter „Zugänge“ ohne Schüler übrig.',' has an access. Delete it first, or it is left under “Logins” with no student.'))?></p><?php endif ?>
    <?php start_form('student_delete',['id'=>$id]);input('confirmation',t('Vollständigen Namen zur Bestätigung eingeben','Enter the full name to confirm'),'','text',true);submit_button(t('Schüler endgültig löschen','Permanently delete student'),'danger');?></form>
</details>
<?php endif ?>
<?php elseif($tab==='contacts'): $contacts=student_contacts($id); ?>
<section class="card">
    <div class="section-heading"><h2><?=e(t('Notfallkontakte','Emergency contacts'))?></h2></div>
    <?php /* „Standardkontakt" for both, because the change log labels is_primary
             with it (ADR 0020, §7); the family's sentence says what it means. */ ?>
    <p class="muted"><?=e($staff
        ? t('Wen du anrufst, wenn etwas ist. Jedes Kind braucht mindestens eine Person; der Standardkontakt ist der, den du zuerst probierst. Rechnungen und Einladungen gehen nicht hierher, sondern an die E-Mail-Adresse des Kindes.','Who you ring when something happens. Every child needs at least one person; the standard one is the one you try first. Invoices and invitations do not go here – they go to the child’s own email address.')
        : t('Wen die Trainerin anruft, wenn im Training etwas passiert. Mindestens eine Person, am besten mit Telefonnummer; der Standardkontakt wird zuerst angerufen.','Who your coach rings if something happens at training. At least one person, ideally with a phone number; the standard contact is rung first.'))?></p>
    <?php if(!$staff && !$contacts): ?>
    <div class="notice warn"><?=e(t('Noch niemand eingetragen. Bitte trag mindestens eine Person ein, die im Notfall angerufen werden kann.','Nobody entered yet. Please add at least one person who can be rung in an emergency.'))?></div>
    <?php elseif($staff && ($gap=contact_gap($id))): ?><div class="notice warn"><?=e($gap)?></div><?php endif ?>
    <?php foreach($contacts as $c): ?>
    <div class="record-row">
        <div><div class="badge-line"><strong><?=e($c['owner_name'])?></strong><?php if((int)$c['is_primary'])badge(t('Standardkontakt','Standard contact'),'green');?></div>
            <p><?=e($c['relation_label'])?></p>
            <?php if($c['phone']):?><a href="tel:<?=e(preg_replace('/[^0-9+]/','',$c['phone']))?>"><?=e($c['phone'])?></a><?php endif ?>
            <p><?=e($c['email']?:t('Keine E-Mail-Adresse','No email address'))?></p></div>
        <?php if(count($contacts)>1): start_form('contact_delete',['student_id'=>$id,'id'=>$c['id']],'inline-form');submit_button(t('Entfernen','Remove'),'subtle danger-text');?></form><?php endif ?>
    </div>
    <?php /* Open after its own refused edit, and only its own (ADR 0020, §10a):
             held_for() matches the contact the form edits, so the typing goes
             back into this form and no other. */ ?>
    <details <?=held_for('contact_save',(int)$c['id'])!==[]?'open':''?>><summary><?=e(t('Kontakt bearbeiten','Edit contact'))?></summary>
        <?php start_form('contact_save',['student_id'=>$id,'id'=>$c['id']]);
        contact_fields($c);
        if((int)$c['is_primary'])echo '<p class="muted">'.e(t('Das ist der Standardkontakt. Um das zu ändern, setze bei einem anderen Kontakt das Häkchen.','This is the standard contact. To change that, tick the box on another contact.')).'</p>';
        else check_field('is_primary',t('Als Standardkontakt verwenden','Use as the standard contact'),false);
        submit_button();?></form>
    </details>
    <?php endforeach ?>
</section>
<section class="card" id="add-contact"><h2><?=e(t('Notfallkontakt hinzufügen','Add an emergency contact'))?></h2>
    <?php start_form('contact_add',['student_id'=>$id]);
    contact_fields();
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
<?php elseif($tab==='classes'): $mine=student_enrolments($id); $requests=student_requests($id); $open=courses_open_to($id); ?>
<section class="card" id="courses">
    <h2><?=e(t('Kurse','Courses'))?></h2>
    <p class="muted"><?=e(t('Jede Kursteilnahme hat ihren eigenen Tarif. Ein Kind kann in mehreren Kursen sein und in jedem etwas anderes zahlen.','Each enrolment has its own tariff. A child can be in several courses and pay something different for each.'))?></p>
    <?php if(!$mine)echo '<p class="muted">'.e(t('Noch in keinem Kurs.','Not in any course yet.')).'</p>';
    /* Where „Kurs wählen" leads a family when there is nothing to choose (ADR
       0021, §3): said, with the way to the trainer, rather than an empty list.
       Only while they have a course to choose: wants_a_course(), the rule the
       „Kurs wählen" step asks too. */
    if(!$staff && wants_a_course($id) && !free_courses_for($id)): ?>
    <div class="notice"><strong><?=e(t('Gerade ist kein Kurs frei','No course has a free place right now'))?></strong>
        <p><?=e(t('Schreib deiner Trainerin – sie meldet sich bei dir.','Write to your coach – she will get back to you.'))?></p>
        <div class="row-actions"><?=link_button(t('Nachricht schreiben','Write a message'),'messages',['new'=>1],'secondary')?></div></div>
    <?php endif;
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
        $chosenRates=$row['tariff_id']?tariff_rates((int)$row['tariff_id']):[];
        ?>
        <div class="grid two"><?php
        // The child's own tariff stays in the list after it is archived, marked
        // as such: left out, the box showed „Auswählen" and saving dropped it.
        // Said under the box as well, because at 320px a closed box cuts the
        // name off before „(archiviert)".
        $tariffArchived=$row['tariff_id']!==null && (int)scalar('SELECT archived FROM tariffs WHERE id=?',[(int)$row['tariff_id']])===1;
        select_field('tariff_id',t('Tarif','Tariff'),enrolment_tariff_choices($row),$row['tariff_id'],false,false,
            $tariffArchived?t('Archiviert – neue Kinder bekommen ihn nicht mehr. Dieses Kind bleibt darauf, bis du einen anderen wählst.',
                              'Archived – no new child is put on it. This child stays on it until you choose another.'):'');
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

<?php if($open): ?>
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
<section class="card" id="requests">
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
<?php elseif($tab==='invoices'): $invoices=invoices_for($id); $open=uninvoiced_charges($id); $mailRefusals=[]; ?>
<section class="card">
    <h2><?=e(t('Rechnungen','Invoices'))?></h2>
    <p class="muted"><?=e(t('Jede Rechnung wird beim Ausstellen festgeschrieben: das PDF zeigt immer den Stand von damals, auch wenn sich später etwas ändert. Es wird erst beim Herunterladen erzeugt und belegt keinen Speicherplatz.','Each invoice is frozen when it is issued: the PDF always shows how things stood then, even if something changes later. It is built when you download it and takes up no space.'))?></p>
    <?php if(!$invoices)echo '<p class="muted">'.e(t('Noch keine Rechnung.','No invoices yet.')).'</p>';
    foreach($invoices as $inv): ?>
    <?php /* The id is where a charge on „Beiträge" leads when this invoice holds it. */ ?>
    <div class="record-row" id="invoice-<?=(int)$inv['id']?>">
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
                <?php /* Said here rather than refused after the tap. Every reason is
                         about the login the invoice goes to, so it is asked once per
                         login, not once per invoice of a history that keeps growing. */
                $to=(int)$inv['account_id'];
                if(!array_key_exists($to,$mailRefusals)) $mailRefusals[$to]=invoice_mail_refusal($inv);
                if($mailRefusals[$to]!==null): ?>
                <p class="muted mail-refusal"><?=e($mailRefusals[$to])?></p>
                <?php else: start_form('invoice_state',['id'=>$inv['id'],'mode'=>'send'],'inline-form');submit_button(t('Per E-Mail schicken','Email it'),'subtle');?></form>
                <?php endif ?>
                <?php if((int)$inv['paid_cents']===0): ?>
                <details class="account-delete" data-sheet><summary><?=e(t('Stornieren','Cancel'))?></summary>
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
<?php $charges=student_charges($id);$paymentsOf=payments_by_charge(array_column($charges,'id'));
/* Which charges a live invoice holds, asked once for all of them: such a charge
   is not offered „Beitrag stornieren", because the invoice would go on asking
   for it. The refusal in charge_cancel stays; this says it before the tap. */
$onInvoice=$staff?live_invoices_of_charges(array_column($charges,'id')):[];
if(!$charges)echo '<div class="card"><p class="muted">'.e(t('Noch keine Beiträge erfasst.','No charges recorded yet.')).'</p></div>';foreach($charges as $c):$remaining=max(0,(int)$c['amount_cents']-(int)$c['paid']); ?>
<?php /* Red is charge_is_overdue(), the rule the overdue total and the reminders
         use: past its due date but inside its grace days is not late yet. */ ?>
<section class="card charge-card"><div class="section-heading"><div><h2><?=e($c['label'])?></h2><p class="muted"><?=e(t('Fällig am ','Due ').fmt_date($c['due_on']))?></p></div><?php badge($c['cancelled']?t('Storniert','Cancelled'):($remaining===0?t('Bezahlt','Paid'):money($remaining).' '.t('offen','outstanding')),$c['cancelled']?'':($remaining===0?'green':(charge_is_overdue($c)?'red':'')));?></div>
<dl class="facts"><div><dt><?=e(t('Beitrag','Charge'))?></dt><dd><?=e(money((int)$c['amount_cents']))?></dd></div><div><dt><?=e(t('Zeitraum','Coverage period'))?></dt><dd><?php /* The period the invoice names, not the stored one: a child who joined mid-month is billed from the day they joined (charge_period_text()). */ $period=charge_period_text($c); ?><?=e($period!==''?$period:t('Einmalig / ohne Zeitraum','One-time / no period'))?></dd></div></dl>
<?php
// Transfer details for whatever is still open on this charge.
if($remaining>0 && !$c['cancelled'] && setting('show_payment_qr')):
    $profile=charge_payment_profile($c);
    if($profile && $profile['iban']!==''):
        $reference=charge_reference($c,$s);
        $payload=qr_payload($profile,$remaining,$reference);
?>
<div class="qr-box">
    <div class="qr-plate"><?=$payload!==''?qr_svg($payload,200):''?></div>
    <div class="qr-details">
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
<?php if(!$allocated):
    if($heldBy=$onInvoice[(int)$c['id']]??null):
        // The number is the way to the invoice and its „Stornieren", so it is a
        // link, and a 44pt one (.held-by-invoice a) inside the sentence.
        [$before,$after]=explode('{number}',t('Steht auf Rechnung {number} – zuerst die Rechnung stornieren.','On invoice {number} – cancel the invoice first.'),2)+['',''];
        echo '<p class="muted held-by-invoice">'.e($before)
            .'<a href="'.e(url('student',['id'=>$id,'tab'=>'invoices','#'=>'invoice-'.$heldBy['id']])).'">'.e($heldBy['number']).'</a>'
            .e($after).'</p>';
    else:
        start_form('charge_cancel',['id'=>$c['id']],'inline-form');submit_button(t('Beitrag stornieren','Cancel charge'),'subtle danger-text');echo '</form>';
    endif;
endif;endif ?></section>
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
