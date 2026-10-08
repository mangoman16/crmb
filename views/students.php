<?php
/* The students list (Part 1, revised 2026-10-08): who is in the club, found
   by name, cut down by the quick selection and the filter fold, in A–Z or
   under the age groups she named under Verwaltung. Everything here is a GET
   link or a GET form, so nothing needs JavaScript, and a selection in the
   address is never refused (filters_from()). The rows carry what they show
   (filtered_students()), so the page costs the same however many children
   there are. It only reads. A family never sees it: the router sends them to
   their own child's page. */
$staff=is_staff($user);$f=filters_from($_GET);
$all=filtered_students($f,$staff?null:(int)$user['id']);$pageNum=page_number();$visible=array_slice($all,($pageNum-1)*50,50);
$overdueBy=balances(true);
$byAge=($f['sort']??'')==='age';
$bands=age_groups();
$row=fn(array $s,bool $underBand=false)=>student_card($s+['due_cents'=>$overdueBy[(int)$s['id']]??0],$underBand);
// One way to add a person: the wizard. Inviting by address alone is a link on
// its first step (spec §1, 2026-10-05).
page_head(t('Schüler','Students'),count($all).' '.t('in dieser Auswahl','in this selection'),
    $staff?link_button(t('+ Schüler anlegen','+ Add student'),'student_new',['from'=>'students']):'');
if($staff): ?>
<?php /* Option 1 (ADR 0021, §3): an address and a language, and the person sets
         themselves up and chooses a course. Only when asked for, or when a
         refused invitation comes back with what was typed. When mail cannot go
         out yet, the card says what is missing instead of a form that can only
         fail. */
$inviteHeld=held_for('email_invite');
$inviting=!empty($_GET['invite']) || $inviteHeld!==[];
if($inviting): ?>
<section class="card invite-card" id="invite">
    <h2><?=e(t('Per E-Mail einladen','Invite by email'))?></h2>
    <p class="muted"><?=e(t('Die Person richtet sich selbst ein und wählt ihren Kurs. Du bekommst Bescheid, sobald sie fertig ist.',
                            'They set themselves up and choose their course. You hear when they are done.'))?></p>
    <?php if(!account_mail_ready()): mail_not_ready_notice($user); else:
    /* The address is already on a student who has no login: inviting it here
       would make the same person twice, so the refusal leads to that student's
       own „Einladung senden" (ADR 0021, §3). Staff only, so the name is said. */
    $typed=$inviteHeld['email']??'';
    $carrier=is_string($typed) && $typed!==''?student_without_login_at(email_normalised($typed)):null;
    // The flash above says what to do; this is the way there.
    students_notice($carrier?[$carrier]:[],t('Diese Adresse steht schon bei einem Schüler','This address is already on a student'),'',[],'access');
    start_form('email_invite');?><div class="grid two"><?php
    // autocomplete="off" first, so it wins: her iPhone would offer her own address.
    input('email',t('E-Mail-Adresse','Email address'),'','email',true,t('Die eigene Adresse der Person, die trainiert.','The own address of the person who trains.'),'',
          ['autocomplete'=>'off']+sign_in_address_attributes());
    select_field('locale',t('Sprache der Einladung','Invitation language'),['de'=>'Deutsch','en'=>'English'],'de',true);
    ?></div><?php submit_button(t('Einladung senden','Send the invitation'));?></form>
    <?php endif ?>
</section>
<?php endif;
/* The invitations by address nobody has taken up (ADR 0021, §4), folded away
   unless she has just sent one or asked to invite. Each says until when its
   link works, because "the link doesn't work" is the call she gets; sending
   it again is the button that stands out once it has expired. An expired
   link's row is usually gone by the next morning (prune_expired()), and then
   no date is guessed. */
$invitations=open_invitations();
if($invitations): $mailReady=account_mail_ready(); ?>
<details class="card invitation-list" id="invitations" <?=!empty($_GET['invitations'])||$inviting?'open':''?>>
    <summary><span class="badge-line"><span><?=e(t('Offene Einladungen','Open invitations'))?></span><?php badge((string)count($invitations),'amber');?></span></summary>
    <p class="muted"><?=e(t('Noch nicht angenommen. Ein Link gilt 48 Stunden.','Not accepted yet. A link is valid for 48 hours.'))?></p>
    <?php foreach($invitations as $a): $sent=invitation_dates((int)$a['id']); $live=invitation_link_live($sent); ?>
    <div class="record-row">
        <div><div class="badge-line"><strong><?=e($a['email'])?></strong><?php if(!$live)badge(t('Abgelaufen','Expired'),'amber');?></div>
            <?php if($sent!==null): ?><p><?=e(t('Eingeladen am ','Invited on ').fmt_datetime((string)$sent['created_at']))?></p><?php endif ?>
            <small><?=e($live?strtr(t('Der Link gilt bis {until}.','The link is valid until {until}.'),['{until}'=>fmt_datetime((string)$sent['expires_at'])])
                             :t('Der Link ist abgelaufen.','The link has expired.'))?></small></div>
        <div class="row-actions">
            <?php if($mailReady){start_form('account_state',['id'=>$a['id'],'mode'=>'reinvite'],'inline-form');submit_button(t('Erneut senden','Send again'),$live?'secondary':'primary');echo '</form>';}
            invitation_withdraw_details($a,t('Zurückziehen','Withdraw'),t('Der Link gilt dann nicht mehr. Versehentlich? Einfach neu einladen.','The link then stops working. By mistake? Just invite again.')); ?>
        </div>
    </div>
    <?php endforeach ?>
</details>
<?php endif ?>
<?php /* Two different gaps, said separately because they are filled in
         different places and neither is found on the day it matters: somebody
         to ring, and somewhere to write. Both are warnings: without an address
         there is no login, and without a login no invoice or reminder reaches
         the family by email. An address that is already somebody else's login
         is said on that child's own page, before anybody taps „Einladung
         senden" (ADR 0020, §1). */
$noContact=students_missing_contact();$noEmail=students_missing_email();
students_notice($noContact,plural(count($noContact),'Kind ohne Notfallkontakt','Kinder ohne Notfallkontakt','child with nobody to ring','children with nobody to ring'),
    '',['tab'=>'contacts'],'add-contact');
students_notice($noEmail,plural(count($noEmail),'Kind ohne E-Mail-Adresse','Kinder ohne E-Mail-Adresse','child with no email address','children with no email address'),
    '',[],'email');
/* The controls (Part 1): finding a child is the commonest reason to open the
   list, so the search stands on its own and keeps the rest of the selection;
   then the quick selection, which starts afresh - „Alle" is the way back to
   everybody; the fold with the other filters; and the order, which keeps the
   filters. All of it GET, so the address says what is shown and can be shared. */
$quick=!empty($f['overdue'])?'overdue':(($f['absence']??'')==='sick'?'sick':'all');
$ordered=array_diff_key($f,['sort'=>1]); ?>
<div class="list-controls">
<form method="get" class="search" role="search">
    <input type="hidden" name="page" value="students">
    <?php foreach(array_diff_key($f,['q'=>1]) as $key=>$value): ?><input type="hidden" name="<?=e($key)?>" value="<?=e($value)?>"><?php endforeach ?>
    <?=icon('search')?>
    <label class="visually-hidden" for="students-search"><?=e(t('Suchen','Search'))?></label>
    <input id="students-search" type="search" name="q" value="<?=e($f['q']??'')?>" placeholder="<?=e(t('Suchen','Search'))?>" autocomplete="off" enterkeyhint="search">
</form>
<?php tabs(['all'=>['label'=>t('Alle','All'),'page'=>'students','params'=>[]],
            'overdue'=>['label'=>t('Überfällig','Overdue'),'page'=>'students','params'=>['overdue'=>1]],
            'sick'=>['label'=>t('Krank','Sick'),'page'=>'students','params'=>['absence'=>'sick']]],$quick,'students',[],t('Auswahl','Selection'));
render_filters($f,$bands);
tabs(['name'=>['label'=>t('A–Z','A–Z'),'page'=>'students','params'=>$ordered],
      'age'=>['label'=>t('Nach Alter','By age'),'page'=>'students','params'=>$ordered+['sort'=>'age']]],$byAge?'age':'name','students',[],t('Sortieren','Sort'));
/* A chosen age group leaves out the children whose age nobody knows: said,
   with the way to them - the same selection without the group, by age, at
   the group they are listed under. */
if($leftOut=left_out_without_birth_date($f)): ?>
<p class="left-out"><span><?=e(plural($leftOut,'Kind ohne Geburtsdatum ist nicht dabei.','Kinder ohne Geburtsdatum sind nicht dabei.','child without a date of birth is not included.','children without a date of birth are not included.'))?></span>
    <a class="button subtle" href="<?=e(url('students',array_diff_key($f,['age_group'=>1,'sort'=>1])+['age_group'=>'none','sort'=>'age','#'=>'no-birth-date']))?>"><?=e(t('Zeigen','Show'))?></a></p>
<?php endif ?>
</div>
<?php endif;
if(!$all): ?>
<div class="card"><?php if(!$f) empty_state(t('Noch keine Schüler','No students yet'),t('Leg den ersten an – Schritt für Schritt.','Add the first one – step by step.'),
        $staff?link_button(t('Schüler anlegen','Add student'),'student_new',['from'=>'students']):'');
    else empty_state(t('Niemand in dieser Auswahl','Nobody in this selection'),'',link_button(t('Alle Schüler','All students'),'students',[],'secondary')); ?></div>
<?php elseif(!$byAge): ?>
<div class="card"><?php foreach($visible as $s) $row($s); ?></div>
<?php else:
    /* „Nach Alter": a group per age group in Verwaltung's order, each child
       under the first that covers their age, then the children no group
       covers, then those without a birth date (student_sections()). The
       headers are this page's, the counts the whole selection's, so a group
       that runs on to the next page keeps its number. */
    if(!$bands): ?>
<div class="notice"><strong><?=e(t('Es gibt noch keine Altersgruppen.','There are no age groups yet.'))?></strong>
    <div class="row-actions"><?=link_button(t('Altersgruppen anlegen','Create age groups'),'manage',['tab'=>'ages'],'secondary')?></div></div>
<?php endif;
    $counts=array_map('count',array_column(student_sections($all,$bands),'rows','key'));
    foreach(student_sections($visible,$bands) as $section):
        $band=$section['band']; ?>
<section class="card age-section" id="<?=e($section['key'])?>">
    <div class="section-heading"><div><h2><?=e($band?$band['name']:($section['key']==='no-age-group'?t('Ohne Altersgruppe','No age group'):t('Ohne Geburtsdatum','No date of birth')))?></h2>
        <?php if($band): ?><small><?=e(age_group_range($band))?></small><?php endif ?></div><?php badge((string)($counts[$section['key']]??count($section['rows']))); ?></div>
    <?php foreach($section['rows'] as $s) $row($s,true); ?>
    <?php // With no group at all the notice above says so; „none of them fits" would be about nothing.
    if($section['key']==='no-age-group' && $bands): ?>
    <p class="section-footer"><?=e(t('Keine deiner Altersgruppen passt.','None of your age groups fits.'))?> <a href="<?=e(url('manage',['tab'=>'ages']))?>"><?=e(t('Altersgruppen ansehen','View the age groups'))?></a></p>
    <?php endif ?>
</section>
<?php endforeach;
endif;
if(count($all)>50):?><nav class="pagination"><?php if($pageNum>1)echo link_button(t('Zurück','Previous'),'students',$f+['p'=>$pageNum-1],'secondary');?><span><?=$pageNum?> / <?=ceil(count($all)/50)?></span><?php if($pageNum*50<count($all))echo link_button(t('Weiter','Next'),'students',$f+['p'=>$pageNum+1],'secondary');?></nav><?php endif ?>
