<?php
/* The wizard „Schüler anlegen" (ADR 0023 §5, 0030 §6): the one way staff make a
   student. Step 1 asks who is joining and posts student_draft, which keeps the
   details in the session under a key; step 2 asks how they sign in, as two
   cards that each post student_create; the done page says what happened. The
   address carries the draft's key and never the details. A GET of any step
   reads the draft and writes nothing (docs/design/2026-10-05-accounts-and-
   chat-screens.md, addendum §2). */
// Which step is showing, read once with the bar's back button (student_new_state()).
$wizard=student_new_state($_GET);
['key'=>$key,'draft'=>$draft,'made'=>$made,'from'=>$from,'back'=>$back]=$wizard;

if(in_array($wizard['step'],['done','made'],true)):
    /* Afterwards: what happened, and where to go next. The same when she comes
       back to step 2 with Back, or sends a step again, after the child was made
       („made", student_new_state()): the page says the child is made already
       and shows what came of it, rather than a form that would make the child
       a second time. A student added by mistake is deleted at the bottom of
       their page while there are no charges - there is no undo, and that is
       the way back. */
    $s=student($wizard['step']==='made'?(int)$made['id']:(int)($_GET['id']??0));
    $login=$s['account_id']?one('SELECT * FROM accounts WHERE id=?',[(int)$s['account_id']]):null;
    $name=$s['first_name'];
    /* How they sign in, read from the login as it is now rather than from how
       it was made: a child made long ago, whose id somebody typed into the
       address, signs in already and is told nothing about an invitation. */
    $path=match(true) {
        login_without_sign_in($login) => 'none',
        $login['state']==='invited'   => 'email',
        default                       => '',
    };
    page_head(strtr($wizard['step']==='made'?t('{name} ist schon angelegt','{name} has already been added'):t('{name} ist angelegt','{name} has been added'),
                    ['{name}'=>$s['first_name'].' '.$s['last_name']]),
        match($path) {
            'none'  => t('Ohne Anmeldung – du trägst alles selbst ein.','Without sign-in – you enter everything yourself.'),
            'email' => t('Die Einladung ist unterwegs.','The invitation is on its way.'),
            default => '',
        });
    if($path==='none'): ?>
<section class="card">
    <p><?=e(strtr(t('{name} kann sich noch nicht anmelden. Sobald du die E-Mail-Adresse hast, trägst du sie auf der Seite von {name} ein und schickst die Einladung.',
                    '{name} cannot sign in yet. Once you have the email address, enter it on {name}’s page and send the invitation.'),['{name}'=>$name]))?></p>
    <div class="row-actions"><?=link_button(t('Anmeldung einrichten','Set up sign-in'),'student',['id'=>$s['id'],'#'=>'access'],'secondary')?></div>
</section>
<?php elseif($path==='email'):
    $sent=invitation_dates((int)$login['id']); ?>
<section class="card">
    <p><?=e(strtr(t('Die Einladung geht an {email}. Der Link darin gilt bis {until}.','The invitation goes to {email}. Its link is valid until {until}.'),
                  ['{email}'=>(string)$login['email'],'{until}'=>$sent?fmt_datetime((string)$sent['expires_at']):'–']))?></p>
    <p class="muted"><?=e(strtr(t('Kommt keine E-Mail an? Auf der Seite von {name} prüfst du die Adresse und sendest die Einladung noch einmal.',
                                  'No email arriving? On {name}’s page you check the address and send the invitation again.'),['{name}'=>$name]))?></p>
</section>
<?php endif;
next_steps_card(student_next_steps((int)$s['id'])); ?>
<div class="row-actions">
    <?=link_button(strtr(t('Zur Seite von {name}','To {name}’s page'),['{name}'=>$name]),'student',['id'=>$s['id']])?>
    <?=link_button(t('Noch einen Schüler anlegen','Add another student'),'student_new',$from!==''?['from'=>$from]:[],'secondary')?>
</div>
<p class="muted"><?=e(strtr(t('Versehentlich angelegt? Ganz unten auf der Seite von {name} löschen – das geht, solange es keine Beiträge gibt.',
                              'Added by mistake? Delete it at the very bottom of {name}’s page – possible while there are no charges.'),['{name}'=>$name]))?></p>
<?php /* A refused step 1 comes back with its draft key when it was a change to
         one, and has to show step 1 again with what was typed, not step 2. */
elseif($wizard['step']==='2'):
    /* Step 2: how they sign in (ADR 0030 §6). Two cards, each its own form with
       one button, so nothing needs JavaScript; each says its consequence before
       the tap, and „Empfohlen" ranks them. Short enough that the second card
       starts on the first screen at 320 (spec addendum §2, measured). */
    $name=(string)$draft['first_name'];
    $course=$draft['class_id']?one('SELECT name FROM classes WHERE id=?',[(int)$draft['class_id']]):null;
    page_head(t('Neuer Schüler','New student'),strtr(t('Schritt 2 von 2: Wie meldet sich {name} an?','Step 2 of 2: How does {name} sign in?'),['{name}'=>$name]));
    wizard_progress(2,2); ?>
<p class="muted draft-summary"><?=e(implode(' · ',array_filter([$draft['first_name'].' '.$draft['last_name'],$draft['birth_date']?fmt_date((string)$draft['birth_date']):'',(string)($course['name']??'')])))?>
    <a class="text-link" href="<?=e(url('student_new',$wizard['again']))?>"><?=e(t('Ändern','Change'))?></a></p>
<section class="card" id="by-email">
    <div class="badge-line"><h2><?=e(t('Per E-Mail einladen','Invite by email'))?></h2><?php badge(t('Empfohlen','Recommended'),'green'); ?></div>
    <p><?=e(strtr(t('{name} bekommt einen Link per E-Mail und richtet sich selbst ein.','{name} gets a link by email and sets themselves up.'),['{name}'=>$name]))?></p>
    <?php if(!account_mail_ready()): mail_not_ready_notice($user); else:
    start_form('student_create',['draft'=>$key,'method'=>'email']);
    // autocomplete="off" first, so it wins: her iPhone would offer her own address.
    input('email',t('E-Mail-Adresse','Email address'),'','email',true,
          strtr(t('Die eigene Adresse von {name} – die der Eltern gehört zu den Kontakten.','{name}’s own address – a parent’s belongs with the contacts.'),['{name}'=>$name]),
          '',['autocomplete'=>'off']+sign_in_address_attributes());
    select_field('locale',t('Sprache der Einladung','Invitation language'),['de'=>'Deutsch','en'=>'English'],'de',true);
    submit_button(t('Anlegen und einladen','Create and invite')); ?></form>
    <?php endif ?>
</section>
<section class="card" id="later">
    <h2><?=e(t('Ohne Anmeldung','Without sign-in'))?></h2>
    <p><?=e(strtr(t('Noch keine Adresse? Dann kann sich {name} vorerst nicht anmelden, und du trägst alles selbst ein. Die Einladung schickst du später von der Seite von {name}.',
                    'No address yet? Then {name} cannot sign in for now, and you enter everything yourself. You send the invitation later from {name}’s page.'),['{name}'=>$name]))?></p>
    <?php start_form('student_create',['draft'=>$key,'method'=>'none']); submit_button(t('Ohne Anmeldung anlegen','Create without sign-in'),'secondary'); ?></form>
</section>
<div class="text-links"><a class="text-link" href="<?=e(url($back))?>"><?=e(t('Abbrechen – es wird nichts gespeichert','Cancel – nothing is saved'))?></a></div>
<?php else:
    /* Step 1: who is joining. A draft being changed fills the form in again. */
    page_head(t('Neuer Schüler','New student'),t('Schritt 1 von 2: Wer kommt dazu?','Step 1 of 2: Who is joining?'));
    wizard_progress(1,2);
    /* A key whose draft has gone - two hours passed, or ten newer ones pushed
       it out - says so, unless a refused step 2 just said it in the banner
       above: once is enough. */
    if($key!=='' && !$draft && held_for('student_create')===[]): ?>
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
<div class="text-links">
    <a class="text-link" href="<?=e(url($back))?>"><?=e(t('Abbrechen','Cancel'))?></a>
    <a class="text-link" href="<?=e(url('students',['invite'=>1,'#'=>'invite']))?>"><?=e(t('Nur die E-Mail-Adresse bekannt? Ohne Namen einladen','Only know the email address? Invite without a name'))?></a>
</div>
<?php endif ?>
