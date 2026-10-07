<?php
/* The wizard „Schüler anlegen" (ADR 0023 §5): the one way staff make a student.
   Step 1 asks who is joining and posts student_draft, which keeps the details
   in the session under a key; step 2 asks how they sign in, as three forms that
   each post student_create; the done page says what happened. The address
   carries the draft's key and never the details. A GET of any step reads the
   draft and writes nothing.

   Built by backend-dev as the working minimum the actions need; frontend-dev
   gives it the designer's screens (docs/design/2026-10-05-accounts-and-chat-
   screens.md §2). */
$step=$_GET['step']??'';
$key=$_GET['draft']??'';
$draft=student_draft($key);
// A draft that became a child already: Back from the done page, or a step of
// it sent again. Unless the child has been deleted since.
$made=($madeId=student_made_from_draft($key)) ? one('SELECT id,first_name,last_name FROM students WHERE id=?',[$madeId]) : null;
$from=in_array($_GET['from']??'',['dashboard','students','start'],true)?$_GET['from']:'';
$back=$from==='start'?'start':($from==='dashboard'?'dashboard':'students');

if($step==='done'):
    /* Afterwards: what happened, and where to go next. A student added by
       mistake is deleted at the bottom of their page while there are no
       charges - there is no undo, and that is the way back. */
    $s=student((int)($_GET['id']??0));
    $login=$s['account_id']?one('SELECT * FROM accounts WHERE id=?',[(int)$s['account_id']]):null;
    $name=$s['first_name'];
    page_head(strtr(t('{name} ist angelegt','{name} has been added'),['{name}'=>$s['first_name'].' '.$s['last_name']]),
        match(true) {
            login_without_sign_in($login)                 => t('Ohne Anmeldung – du trägst alles selbst ein.','Without sign-in – you enter everything yourself.'),
            (string)($login['email']??'')!==''            => t('Die Einladung ist unterwegs.','The invitation is on its way.'),
            default                                       => strtr(t('Mit diesem Link meldet sich {name} zum ersten Mal an.','{name} signs in for the first time with this link.'),['{name}'=>$name]),
        }); ?>
<section class="card">
<?php if(login_without_sign_in($login)): ?>
    <p><?=e(strtr(t('{name} kann sich noch nicht anmelden. Soll {name} oder die Familie das Portal nutzen, erstell eine Einladung oder einen Anmeldelink.',
                    '{name} cannot sign in yet. If {name} or the family should use the portal, create an invitation or a sign-in link.'),['{name}'=>$name]))?></p>
    <div class="row-actions"><?=link_button(t('Anmeldung einrichten','Set up sign-in'),'student',['id'=>$s['id'],'#'=>'access'],'secondary')?></div>
<?php elseif((string)($login['email']??'')!==''):
    $sent=invitation_dates((int)$login['id']); ?>
    <p><?=e(strtr(t('Die Einladung geht an {email}. Der Link darin gilt bis {until}.','The invitation goes to {email}. Its link is valid until {until}.'),
                  ['{email}'=>(string)$login['email'],'{until}'=>$sent?fmt_datetime((string)$sent['expires_at']):'–']))?></p>
    <p class="muted"><?=e(strtr(t('Kommt keine E-Mail an? Auf der Seite von {name} prüfst du die Adresse und sendest die Einladung noch einmal – oder ziehst sie zurück und vergibst einen Benutzernamen.',
                                  'No email arriving? On {name}’s page you check the address and send the invitation again – or withdraw it and give a username.'),['{name}'=>$name]))?></p>
<?php else:
    $signinLogin=$login; $signinFirstName=$name; $signinStudentId=(int)$s['id'];
    require __DIR__.'/_signin_link.php';
endif ?>
</section>
<?php next_steps_card(student_next_steps((int)$s['id'])); ?>
<div class="row-actions">
    <?=link_button(strtr(t('Zur Seite von {name}','To {name}’s page'),['{name}'=>$name]),'student',['id'=>$s['id']])?>
    <?=link_button(t('Noch einen Schüler anlegen','Add another student'),'student_new',$from!==''?['from'=>$from]:[],'secondary')?>
</div>
<p class="muted"><?=e(strtr(t('Versehentlich angelegt? Ganz unten auf der Seite von {name} löschen – das geht, solange es keine Beiträge gibt.',
                              'Added by mistake? Delete it at the very bottom of {name}’s page – possible while there are no charges.'),['{name}'=>$name]))?></p>
<?php elseif($made):
    /* Not the form again: it would make the child a second time. Where the
       first went instead, and a fresh start for somebody else. */
    page_head(t('Neuer Schüler','New student')); ?>
<div class="notice"><p><?=e(strtr(t('{name} ist schon angelegt.','{name} has already been added.'),['{name}'=>$made['first_name'].' '.$made['last_name']]))?></p>
    <div class="row-actions"><?=link_button(t('Weiter','Continue'),'student_new',['step'=>'done','id'=>$made['id']]+($from!==''?['from'=>$from]:[]))?>
    <?=link_button(t('Noch einen Schüler anlegen','Add another student'),'student_new',$from!==''?['from'=>$from]:[],'secondary')?></div></div>
<?php /* A refused step 1 comes back with its draft key when it was a change to
         one, and has to show step 1 again with what was typed, not step 2. */
elseif($draft && $step!=='1' && !held_for('student_draft')):
    /* Step 2: how they sign in. Three cards, each its own form with one
       button, so nothing needs JavaScript. */
    $name=(string)$draft['first_name'];
    $course=$draft['class_id']?one('SELECT name FROM classes WHERE id=?',[(int)$draft['class_id']]):null;
    page_head(t('Neuer Schüler','New student'),strtr(t('Schritt 2 von 2: Wie meldet sich {name} an?','Step 2 of 2: How does {name} sign in?'),['{name}'=>$name])); ?>
<p class="muted"><?=e(implode(' · ',array_filter([$draft['first_name'].' '.$draft['last_name'],$draft['birth_date']?fmt_date((string)$draft['birth_date']):'',(string)($course['name']??'')])))?>
    <a class="text-link" href="<?=e(url('student_new',['draft'=>$key,'step'=>'1']+($from!==''?['from'=>$from]:[])))?>"><?=e(t('Ändern','Change'))?></a></p>
<section class="card" id="by-email">
    <div class="badge-line"><h2><?=e(t('Per E-Mail einladen','Invite by email'))?></h2><?php badge(t('Empfohlen','Recommended'),'green'); ?></div>
    <p><?=e(strtr(t('{name} bekommt einen Link per E-Mail, legt ein Passwort fest und kann alle Angaben selbst ergänzen oder ändern.',
                    '{name} gets a link by email, chooses a password and can add or change all the details themselves.'),['{name}'=>$name]))?></p>
    <?php if(!account_mail_ready()): mail_not_ready_notice($user); else:
    start_form('student_create',['draft'=>$key,'method'=>'email']);
    input('email',t('E-Mail-Adresse','Email address'),'','email',true,
          t('Die eigene Adresse der Schülerin oder des Schülers – die der Eltern gehört zu den Kontakten.','The student’s own address – a parent’s belongs with the contacts.'),
          '',['autocomplete'=>'off']+sign_in_address_attributes());
    select_field('locale',t('Sprache der Einladung','Invitation language'),['de'=>'Deutsch','en'=>'English'],'de',true);
    submit_button(t('Anlegen und Einladung senden','Create and send the invitation')); ?></form>
    <?php endif ?>
</section>
<section class="card" id="by-username">
    <div class="badge-line"><h2><?=e(t('Ohne E-Mail, mit Benutzername','Without email, with a username'))?></h2><?php badge(t('Nur wenn nötig','Only if needed'),'amber'); ?></div>
    <p><?=e(t('Für Kinder ohne eigene E-Mail-Adresse. Du bekommst einen QR-Code und einen Link für die erste Anmeldung.',
              'For children without an email address of their own. You get a QR code and a link for the first sign-in.'))?></p>
    <div class="notice warn"><strong><?=e(t('Ohne E-Mail-Adresse fehlt einiges','Without an email address, some things are missing'))?></strong>
        <p><?=e(t('Kein „Passwort vergessen“ – ein neues Passwort gibt es nur über einen neuen Link von dir. Keine Rechnungen, Erinnerungen und Hinweise per E-Mail.',
                  'No “forgot your password” – a new password only comes through a new link from you. No invoices, reminders or notices by email.'))?></p></div>
    <?php if(($privacyMissing=privacy_notice_missing())!==''): ?>
    <div class="notice"><p><?=e($privacyMissing)?></p></div>
    <?php else:
    start_form('student_create',['draft'=>$key,'method'=>'username']);
    input('username',t('Benutzername','Username'),username_suggested((string)$draft['first_name'],(string)$draft['last_name']),'text',true,
          strtr(t('Kleinbuchstaben, Ziffern, Punkt und Bindestrich. Damit meldet sich {name} an.','Lower-case letters, digits, dot and hyphen. {name} signs in with it.'),['{name}'=>$name]),
          '',sign_in_address_attributes()+['maxlength'=>'30']);
    submit_button(t('Anlegen und Anmeldelink zeigen','Create and show the sign-in link'),'secondary'); ?></form>
    <?php endif ?>
</section>
<section class="card" id="later">
    <h2><?=e(t('Ich schließe es selbst ab','I will finish it myself'))?></h2>
    <p><?=e(strtr(t('{name} kann sich vorerst nicht anmelden; du trägst alles selbst ein. Eine Einladung oder einen Anmeldelink erstellst du später auf der Seite von {name}.',
                    '{name} cannot sign in for now; you enter everything yourself. You create an invitation or a sign-in link later on {name}’s page.'),['{name}'=>$name]))?></p>
    <?php start_form('student_create',['draft'=>$key,'method'=>'none']); submit_button(t('Ohne Anmeldung anlegen','Create without sign-in'),'secondary'); ?></form>
</section>
<a class="text-link" href="<?=e(url($back))?>"><?=e(t('Abbrechen – es wird nichts gespeichert','Cancel – nothing is saved'))?></a>
<?php else:
    /* Step 1: who is joining. A draft being changed fills the form in again. */
    page_head(t('Neuer Schüler','New student'),t('Schritt 1 von 2: Wer kommt dazu?','Step 1 of 2: Who is joining?'));
    if($key!=='' && !$draft): ?>
<div class="notice warn"><p><?=e(t('Die Angaben waren nicht mehr da. Bitte noch einmal eintragen.','The details were no longer there. Please enter them again.'))?></p></div>
<?php endif;
    // Running courses with a place, each with the tariffs it offers; a course
    // with no tariff yet is offered as it is. Full courses are left out.
    $courses=[];
    foreach(training_classes() as $c) {
        if(course_is_full($c)) continue;
        $tariffs=class_tariffs((int)$c['id']);
        if(!$tariffs) $courses[$c['id'].':0']=$c['name'].' · '.t('noch ohne Tarif','no tariff yet');
        foreach($tariffs as $tariff) $courses[$c['id'].':'.$tariff['id']]=$c['name'].' · '.$tariff['name'];
    }
    start_form('student_draft',['draft'=>$draft?$key:'','from'=>$from],'form'); ?>
<section class="card"><div class="grid two"><?php
input('first_name',t('Vorname','First name'),(string)($draft['first_name']??''),'text',true,'','',['autocomplete'=>'off','autocapitalize'=>'words','maxlength'=>'100']);
input('last_name',t('Nachname','Last name'),(string)($draft['last_name']??''),'text',true,'','',['autocomplete'=>'off','autocapitalize'=>'words','maxlength'=>'100']);
input('birth_date',t('Geburtsdatum','Date of birth'),(string)($draft['birth_date']??''),'date',false,
      t('Bestimmt die Altersgruppe. Kann auch später ergänzt werden.','Decides the age group. Can be added later too.'),'',['max'=>today()]);
select_field('course',t('Kurs','Course'),['none'=>t('Noch keinen Kurs','No course yet')]+$courses,(string)($draft['course']??''),true,false,
      t('Volle Kurse stehen nicht in der Liste. Ohne Kurs entstehen keine Beiträge.','Full courses are not listed. Without a course there are no charges.'));
select_field('status',t('Mitgliedschaft','Membership'),array_combine(array_keys(statuses()),array_map('status_label',array_keys(statuses()))),
      (string)($draft['status']??setting('default_status','active')),true);
?></div></section>
<div class="form-footer"><?php submit_button(t('Weiter','Next')); ?></div></form>
<a class="text-link" href="<?=e(url($back))?>"><?=e(t('Abbrechen','Cancel'))?></a>
<a class="text-link" href="<?=e(url('students',['invite'=>1,'#'=>'invite']))?>"><?=e(t('Nur die E-Mail-Adresse bekannt? Ohne Namen einladen','Only know the email address? Invite without a name'))?></a>
<?php endif ?>
