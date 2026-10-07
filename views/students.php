<?php
$staff=is_staff($user);$f=filters_from($_GET);
$all=filtered_students($f,$staff?null:(int)$user['id']);$pageNum=page_number();$visible=array_slice($all,($pageNum-1)*50,50);
$overdueBy=balances(true);$coursePrices=course_prices_by_student();
// The two ways to add a person (ADR 0021, §3).
page_head(t('Schüler','Students'),count($all).' '.t('in dieser Auswahl','in this selection'),
    $staff?link_button(t('Per E-Mail einladen','Invite by email'),'students',['invite'=>1,'#'=>'invite'],'secondary')
           .link_button(t('+ Schüler anlegen','+ Add student'),'student_new',['from'=>'students']):'');
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
?>
<?php /* The two selections asked for most, a tap away (ADR 0026 §8). */ ?>
<div class="saved-filters">
    <a class="chip <?=!$f?'is-on':''?>" href="<?=e(url('students'))?>"><?=e(t('Alle Schüler','All students'))?></a>
    <a class="chip" href="<?=e(url('students',['overdue'=>1]))?>"><?=e(t('Überfällige Beiträge','Overdue charges'))?></a>
    <a class="chip" href="<?=e(url('students',['absence'=>'sick']))?>"><?=e(t('Aktuell krank','Currently sick'))?></a>
</div>
<div class="card filter-card"><?php render_filters($f); ?></div>
<div class="list-toolbar"><span><?=e(t('Nach Nachnamen sortiert','Sorted by last name'))?></span></div>
<?php endif ?>
<div class="card"><?php if(!$all)empty_state(t('Keine Schüler gefunden.','No students found.'),t('Passe die Filter an oder lege einen Schüler an.','Adjust the filters or add a student.'));else foreach($visible as $s)student_card($s+['due_cents'=>$overdueBy[(int)$s['id']]??0,'course_price'=>$coursePrices[(int)$s['id']]??course_price_label([])]);?></div>
<?php if(count($all)>50):?><nav class="pagination"><?php if($pageNum>1)echo link_button(t('Zurück','Previous'),'students',$f+['p'=>$pageNum-1],'secondary');?><span><?=$pageNum?> / <?=ceil(count($all)/50)?></span><?php if($pageNum*50<count($all))echo link_button(t('Weiter','Next'),'students',$f+['p'=>$pageNum+1],'secondary');?></nav><?php endif ?>
