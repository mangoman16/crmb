<?php
/* The people who run the portal. A student's login is not here: it belongs to
   that student and is given, suspended and deleted on their page (ADR 0010),
   where the address already is and there is nothing to connect afterwards. */
$team=rows("SELECT * FROM accounts WHERE role IN ('admin','trainer','manager') ORDER BY name,id");
$orphans=orphan_logins();
page_head(t('Team und Zugänge','Team and logins'),t('Trainerinnen und Administratoren. Den Zugang einer Schülerin oder eines Schülers verwaltest du auf deren Seite.','Trainers and administrators. A student’s login is managed on that student’s own page.'));
$roles=[];foreach(assignable_roles($user) as $r)$roles[$r]=role_label($r);
$mailReady=account_mail_ready();
if(is_admin($user) && $roles): ?>
<details class="card" <?=count($team)<=1?'open':''?>><summary><?=e(t('+ Teammitglied einladen','+ Invite a team member'))?></summary>
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
<?php endif ?>
<?php
/* One row of either list: who, what, whether and when they were last here, and
   where the login stands. An invitation nobody has taken up has no presence to
   show, so it gets none rather than a grey „Offline". The history for the whole
   page is one query (ADR 0015). */
$seenHere=array_values(array_filter(array_merge($team,$orphans),fn($a)=>presence_shown_for($user,$a)));
$history=presence_history($user,array_column($seenHere,'id'));
$recordedSince=presence_recorded_since();
$identity=function(array $a) use ($user,$history,$recordedSince): void {
    $present=presence_shown_for($user,$a); ?>
<div class="record-row"><div class="account-identity"><?=avatar($a)?><div><h3><?=e($a['name'])?></h3><p class="mono"><?=e($a['username'])?></p><p><?=e($a['email'])?></p><small><?=e(role_label($a['role']))?><?php if($present):?> · <?=presence_line($user,$a)?><?php endif ?></small></div></div><div><?php login_state_badge($a);?></div></div>
<?php if($present) presence_history_details($user,$history[(int)$a['id']]??[],$recordedSince);
};
$deleteText=t('Löscht die Anmeldung, die privaten Unterhaltungen und die E-Mails, die für sie noch warten. Schüler, Beiträge und Rechnungen bleiben.','Deletes the login, the private conversations and any emails still waiting for it. Students, charges and invoices stay.');
?>
<div class="card">
<?php foreach($team as $a): ?>
<div class="account-row"><?php $identity($a); ?>
<?php if((int)$a['id']!==(int)$user['id'] && is_admin($user)): ?>
<div class="row-actions">
<?php /* Looking through somebody's eyes rather than asking them to describe what
         they see. The bar at the top of every page says whose eyes, and one
         button gets back out. */
if(may_impersonate($user,$a)){start_form('impersonate',['id'=>$a['id'],'mode'=>'start'],'inline-form');submit_button(t('Portal als diese Person ansehen','View the portal as this person'),'secondary');echo '</form>';} ?>
<?php /* A link for a new password, to the login's own address (ADR 0020, §5).
         Only for a login in use: an invitation is sent again instead, and a
         suspended login is restored first. Nothing to undo if tapped by
         mistake: the old password works until the link is used. */
if($mailReady && reset_link_possible($a)){start_form('account_state',['id'=>$a['id'],'mode'=>'reset_link'],'inline-form');submit_button(t('Link zum Zurücksetzen senden','Send a reset link'),'secondary');echo '</form>';}
$mode=$a['state']==='suspended'?'restore':'suspend';start_form('account_state',['id'=>$a['id'],'mode'=>$mode],'inline-form');submit_button($mode==='restore'?t('Zugang entsperren','Restore the access'):t('Zugang sperren','Suspend the access'),'secondary');?></form>
<?php if($mailReady && $a['state']==='invited'){start_form('account_state',['id'=>$a['id'],'mode'=>'reinvite'],'inline-form');submit_button(t('Einladung erneut senden','Send the invitation again'),'secondary');echo '</form>';}?>
<?php login_delete_details($a,t('Zugang löschen','Delete the access'),$deleteText,t('Zugang endgültig löschen','Delete the access for good')); ?>
</div>
<?php endif ?></div>
<?php endforeach ?>
</div>
<?php if($orphans): ?>
<section class="card">
<h2><?=e(t('Zugänge ohne Schüler','Logins without a student'))?></h2>
<p class="muted"><?=e(t('Zu diesen Zugängen gehört kein Schüler mehr, etwa weil er gelöscht wurde. Sperren oder löschen.','No student belongs to these logins any more, for example because the student was deleted. Suspend or delete them.'))?></p>
<?php foreach($orphans as $a): ?>
<div class="account-row"><?php $identity($a); ?>
<div class="row-actions">
<?php $mode=$a['state']==='suspended'?'restore':'suspend';start_form('account_state',['id'=>$a['id'],'mode'=>$mode],'inline-form');submit_button($mode==='restore'?t('Zugang entsperren','Restore the access'):t('Zugang sperren','Suspend the access'),'secondary');?></form>
<?php login_delete_details($a,t('Zugang löschen','Delete the access'),
    t('Löscht die Anmeldung und die privaten Unterhaltungen. Zu diesem Zugang gehört kein Schüler, es geht also sonst nichts verloren.','Deletes the login and the private conversations. No student belongs to this login, so nothing else is lost.'),
    t('Zugang endgültig löschen','Delete the access for good')); ?>
</div></div>
<?php endforeach ?>
</section>
<?php endif ?>
