<?php
/* „Zugänge" (ADR 0023 §8, 0030 §8): who can sign in, in the owner's three
   categories - the trainers, the administrators, and one row per student - as
   inset lists (Part 0, C5). The team's own actions are here, folded under each person; a
   student's login is changed on that student's access card, where the address
   and the student are (ADR 0010), so a student's row only leads there and
   nothing here acts on it. The rows and the counts are read by team_logins(),
   student_logins() and student_login_counts() (app/domain.php). */
$team=team_logins();
$orphans=orphan_logins();
$invitations=open_invitations();
$mailReady=account_mail_ready();
// The chip the address asks for; an unknown word is „Alle", as a filter in the
// address is everywhere (ADR 0026 §5). The router leaves no list in $_GET.
$loginFilters=student_login_filters();
$loginFilter=isset($loginFilters[$_GET['logins']??'']) ? $_GET['logins'] : 'all';
$loginCounts=student_login_counts();
$pageNum=page_number();
$studentRows=student_logins($loginFilter,$pageNum);
page_head(t('Zugänge','Logins'),t('Wer sich im Portal anmelden kann: Trainer, Administratoren und Schüler.','Who can sign in to the portal: trainers, administrators and students.'));
$roles=[];foreach(assignable_roles($user) as $r)$roles[$r]=role_label($r);
if(is_admin($user) && $roles): ?>
<details class="card" <?=count($team['trainer'])+count($team['admin'])<=1?'open':''?>><summary><?=e(t('+ Teammitglied einladen','+ Invite a team member'))?></summary>
<?php /* The only way a team login is made (ADR 0020, §5): each person sets their
         own password from the invitation. When mail cannot go out yet, the card
         says what is missing instead of offering a form that can only fail. */
if(!$mailReady): mail_not_ready_notice($user); else:
start_form('account_invite');?><div class="grid two"><?php
input('name',t('Name','Name'),'','text',true);
input('email',t('E-Mail-Adresse','Email address'),'','email',true,
      t('Eine eigene Adresse, die kein anderes Konto nutzt.','An address of their own that no other account uses.'));
select_field('locale',t('Sprache der Einladung','Invitation language'),['de'=>'Deutsch','en'=>'English'],'de',true);
select_field('role',t('Rolle','Role'),$roles,'trainer',true);
?></div><?php submit_button(t('Einladung senden','Send the invitation'));?></form>
<?php endif ?></details>
<?php endif;

/* One login of the team or one left without a student, as a row: who, the
   address, where the login stands. An administrator taps it open for what she
   can do with it, as a contact opens on a phone; her own login, and every row
   for a trainer, is a row to read. Folded, the list stays a list. Each action
   is a form of its own, and „Zugang löschen" a fold of its own, so nothing is
   a form inside a form. */
$deleteText=t('Löscht die Anmeldung, die privaten Unterhaltungen und die E-Mails, die für sie noch warten. Schüler, Beiträge und Rechnungen bleiben.','Deletes the login, the private conversations and any emails still waiting for it. Students, charges and invoices stay.');
$loginRow=function(array $a,bool $orphan=false) use ($user,$mailReady,$deleteText): void {
    // A login nobody has set up yet may have no name, and then the address is
    // its name (login_holder_name()); said once, not twice. The face is the
    // login's own: a team member's photo where there is one, by the rule every
    // face follows (avatar()), the initials of that name otherwise.
    $holder=login_holder_name($a);
    $address=(string)($a['email']??'');
    $identity=function() use ($a,$holder,$address): void {
        echo avatar(['name'=>$holder]+$a).'<span class="member-row-text"><strong>'.e($holder).'</strong>';
        if($address!=='' && $address!==$holder) echo '<small>'.e($address).'</small>';
        login_state_badge($a);
        echo '</span>';
    };
    if((int)$a['id']===(int)$user['id'] || !is_admin($user)) {
        echo '<div class="member-row account-row">'; $identity(); echo '</div>';
        return;
    }
    echo '<details class="account-row"><summary class="member-row">'; $identity(); echo icon('chevron').'</summary><div class="row-actions account-actions">';
    if($orphan) {
        $mode=$a['state']==='suspended'?'restore':'suspend';
        start_form('account_state',['id'=>$a['id'],'mode'=>$mode],'inline-form');submit_button($mode==='restore'?t('Zugang entsperren','Restore the access'):t('Zugang sperren','Suspend the access'),'secondary');echo '</form>';
        login_delete_details($a,t('Zugang löschen','Delete the access'),
            t('Löscht die Anmeldung und die privaten Unterhaltungen. Zu diesem Zugang gehört kein Schüler, es geht also sonst nichts verloren.','Deletes the login and the private conversations. No student belongs to this login, so nothing else is lost.'),
            t('Zugang endgültig löschen','Delete the access for good'));
        echo '</div></details>';
        return;
    }
    // An invitation not taken up: sending it again is the way forward.
    if($mailReady && $a['state']==='invited'){start_form('account_state',['id'=>$a['id'],'mode'=>'reinvite'],'inline-form');submit_button(t('Einladung erneut senden','Send the invitation again'),'secondary');echo '</form>';}
    /* Looking through somebody's eyes rather than asking them to describe what
       they see. The bar at the top of every page says whose eyes, and one
       button gets back out. */
    if(may_impersonate($user,$a)){start_form('impersonate',['id'=>$a['id'],'mode'=>'start'],'inline-form');submit_button(t('Portal als diese Person ansehen','View the portal as this person'),'secondary');echo '</form>';}
    /* A link for a new password, to the login's own address (ADR 0020, §5).
       Only for a login in use: an invitation is sent again instead, and a
       suspended login is restored first. Nothing to undo if tapped by
       mistake: the old password works until the link is used. */
    if($mailReady && reset_link_possible($a)){start_form('account_state',['id'=>$a['id'],'mode'=>'reset_link'],'inline-form');submit_button(t('Link zum Zurücksetzen senden','Send a reset link'),'secondary');echo '</form>';}
    $mode=$a['state']==='suspended'?'restore':'suspend';start_form('account_state',['id'=>$a['id'],'mode'=>$mode],'inline-form');submit_button($mode==='restore'?t('Zugang entsperren','Restore the access'):t('Zugang sperren','Suspend the access'),'secondary');echo '</form>';
    login_delete_details($a,t('Zugang löschen','Delete the access'),$deleteText,t('Zugang endgültig löschen','Delete the access for good'));
    echo '</div></details>';
};
/* A group's name and how many are in it, above the group, as an inset list
   has its header (Part 0, C5). */
$groupHead=function(string $title,int $count): void {
    echo '<div class="group-title"><h2>'.e($title).'</h2>'; badge((string)$count); echo '</div>';
};
?>
<section class="account-group" id="trainers">
<?php $groupHead(t('Trainer','Trainers'),count($team['trainer'])); ?>
<div class="card">
<?php foreach($team['trainer'] as $a) $loginRow($a);
if(!$team['trainer']): ?><p class="muted"><?=e(is_admin($user)?t('Noch niemand. Lade oben eine Trainerin oder einen Trainer ein.','Nobody yet. Invite a trainer above.'):t('Noch niemand.','Nobody yet.'))?></p><?php endif ?>
</div>
</section>
<section class="account-group" id="admins">
<?php $groupHead(t('Administratoren','Administrators'),count($team['admin'])); ?>
<div class="card">
<?php foreach($team['admin'] as $a) $loginRow($a); ?>
</div>
</section>
<?php /* One row per student, each leading to that student's access card, with
         the address, where the login stands, and until when an invitation
         works; a placeholder has no address yet, and its row none either. The
         chips filter by the address, without JavaScript; a chip nobody is in is
         left out. „Eingeladen" answers the question she opens the page for -
         who has not taken the invitation up (spec addendum §6). */ ?>
<section class="account-group" id="students">
<?php $groupHead(t('Schüler','Students'),$loginCounts['all']);
if($loginCounts['all']>0): ?>
<nav class="saved-filters" aria-label="<?=e(t('Auswahl','Selection'))?>">
<?php foreach(['all'=>t('Alle','All'),'invited'=>t('Eingeladen','Invited'),'placeholder'=>t('Ohne Anmeldung','No sign-in'),'suspended'=>t('Gesperrt','Suspended')] as $key=>$label):
    if($key!=='all' && $loginCounts[$key]===0 && $key!==$loginFilter) continue; ?>
    <a class="chip <?=$key===$loginFilter?'is-on':''?>" href="<?=e(url('accounts',($key!=='all'?['logins'=>$key]:[])+['#'=>'students']))?>" <?=$key===$loginFilter?'aria-current="true"':''?>><?=e($label.' ('.$loginCounts[$key].')')?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<div class="card">
<?php foreach($studentRows as $row):
    $invited=$row['state']==='invited';
    $linkLive=$invited && $row['link_expires_at']!==null && (string)$row['link_expires_at']>now();
    $address=(string)($row['email']??''); ?>
<a class="member-row" href="<?=e(url('student',['id'=>$row['student_id'],'#'=>'access']))?>">
    <?php /* The row is the login, carrying its child's picture (student_logins()); the
             initials are the child's name, which the row shows, not the login's. */ ?>
    <?=avatar(['name'=>$row['first_name'].' '.$row['last_name']]+$row)?>
    <span class="member-row-text"><strong><?=e($row['first_name'].' '.$row['last_name'])?></strong>
        <?php if($address!==''): ?><small><?=e($address)?></small><?php endif ?>
        <?php if($invited): ?><small><?=e($linkLive?t('Link gilt bis ','Link valid until ').fmt_datetime((string)$row['link_expires_at']):t('Link abgelaufen','Link expired'))?></small><?php endif ?>
        <?php login_state_badge($row); ?></span><?=icon('chevron')?>
</a>
<?php endforeach;
if(!$studentRows): ?>
<p class="muted"><?=e($loginCounts['all']===0?t('Noch keine Schüler. Leg den ersten über „Schüler anlegen“ an.','No students yet. Add the first with “Add student”.'):t('Niemand in dieser Auswahl.','Nobody in this selection.'))?></p>
<?php endif ?>
</div>
<p class="group-footer"><?=e(t('Einladen, sperren und löschen machst du auf der Seite der Schülerin oder des Schülers.','Inviting, suspending and deleting are done on the student’s own page.'))?></p>
<?php $pages=max(1,(int)ceil($loginCounts[$loginFilter]/STUDENT_LOGINS_PER_PAGE));
if($pages>1): $here=$loginFilter!=='all'?['logins'=>$loginFilter]:[]; ?>
<nav class="pagination" aria-label="<?=e(t('Seiten','Pages'))?>">
    <?php if($pageNum>1)echo link_button(t('Zurück','Previous'),'accounts',$here+['p'=>$pageNum-1,'#'=>'students'],'secondary'); ?>
    <span><?=e(strtr(t('Seite {n} von {total}','Page {n} of {total}'),['{n}'=>(string)min($pageNum,$pages),'{total}'=>(string)$pages]))?></span>
    <?php if($pageNum<$pages)echo link_button(t('Weitere','More'),'accounts',$here+['p'=>$pageNum+1,'#'=>'students'],'secondary'); ?>
</nav>
<?php endif ?>
</section>
<?php /* Invitations by address alone (ADR 0021, §3): nobody's student yet - the
         student is made when the link is opened - and handled on the students
         list, where they were sent from. */
if($invitations): ?>
<section class="account-group" id="invitations">
<?php $groupHead(t('Einladungen ohne Namen','Invitations without a name'),count($invitations)); ?>
<div class="card">
<?php foreach($invitations as $a): ?>
<a class="member-row" href="<?=e(url('students',['invitations'=>1,'#'=>'invitations']))?>">
    <span class="avatar"><?=icon('mail')?></span>
    <span class="member-row-text"><strong class="is-address"><?=e((string)$a['email'])?></strong><?php login_state_badge($a); ?></span><?=icon('chevron')?>
</a>
<?php endforeach ?>
</div>
<p class="group-footer"><?=e(t('Wer sie annimmt, trägt sich selbst ein und wird dabei zum Schüler.','Whoever accepts one enters their own details and becomes a student.'))?></p>
</section>
<?php endif ?>
<?php if($orphans): ?>
<section class="account-group" id="orphans">
<?php $groupHead(t('Zugänge ohne Schüler','Logins without a student'),count($orphans)); ?>
<div class="card">
<?php foreach($orphans as $a) $loginRow($a,true); ?>
</div>
<p class="group-footer"><?=e(t('Zu diesen Zugängen gehört kein Schüler mehr, etwa weil er gelöscht wurde. Sperren oder löschen.','No student belongs to these logins any more, for example because the student was deleted. Suspend or delete them.'))?></p>
</section>
<?php endif ?>
