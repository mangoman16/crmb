<?php
/* The people who run the portal. A student's login is not here: it belongs to
   that student and is given, suspended and deleted on their page (ADR 0010),
   where the address already is and there is nothing to connect afterwards. */
$team=rows("SELECT * FROM accounts WHERE role IN ('admin','trainer','manager') ORDER BY name,id");
$orphans=orphan_logins();
page_head(t('Team und Zugänge','Team and logins'),t('Trainerinnen und Administratoren. Den Zugang einer Schülerin oder eines Schülers verwaltest du auf deren Seite.','Trainers and administrators. A student’s login is managed on that student’s own page.'));
$roles=[];foreach(assignable_roles($user) as $r)$roles[$r]=role_label($r);
if(is_admin($user) && $roles): ?>
<details class="card" <?=count($team)<=1?'open':''?>><summary><?=e(t('+ Teammitglied einladen','+ Invite a team member'))?></summary>
<?php start_form('account_invite');?><div class="grid two"><?php
input('name',t('Name','Name'),'','text',true);
input('email',t('E-Mail-Adresse','Email address'),'','email',true);
select_field('locale',t('Sprache der Einladung','Invitation language'),['de'=>'Deutsch','en'=>'English'],'de',true);
select_field('role',t('Rolle','Role'),$roles,'trainer',true);
?></div><?php submit_button(t('Einladung senden','Send the invitation'));?></form></details>

<?php /* The same login, made on the spot with a password instead of a link.
         Inviting needs working SMTP and a released privacy notice; this needs
         neither, which is what makes the portal usable on the first evening. */ ?>
<details class="card"><summary><?=e(t('+ Teammitglied direkt anlegen (ohne E-Mail)','+ Add a team member directly (no email)'))?></summary>
<p class="muted"><?=e(t('Wenn keine Einladung möglich oder gewünscht ist: für eine zweite Administratorin, oder für jemanden, der neben dir steht und sein Passwort selbst eintippt. Die Adresse wird dabei nicht bestätigt.','When an invitation is not possible or not wanted: for a second administrator, or for somebody standing next to you who types their own password. The address is not confirmed this way.'))?></p>
<?php start_form('account_create');?><div class="grid two"><?php
input('name',t('Name','Name'),'','text',true);
input('email',t('E-Mail-Adresse','Email address'),'','email',true);
input('password',t('Passwort','Password'),'','password',true,t('Mindestens 12 Zeichen. Diese Person kann es später selbst ändern.','At least 12 characters. They can change it themselves later.'));
select_field('locale',t('Sprache','Language'),['de'=>'Deutsch','en'=>'English'],'de',true);
select_field('role',t('Rolle','Role'),$roles,'trainer',true);
?></div><?php submit_button(t('Anlegen','Add'));?></form></details>
<?php endif ?>
<?php
/* One row of either list: who, what, when last seen, and where the login stands. */
$identity=function(array $a): void { ?>
<div class="record-row"><div class="account-identity"><?=avatar($a)?><div><h3><?=e($a['name'])?></h3><p><?=e($a['email'])?></p><small><?=e(role_label($a['role']))?><?php
    if(is_online($a['last_seen_at']??null)) echo ' · <span class="online"><span class="dot"></span>'.e(t('online','online')).'</span>';
    elseif(!empty($a['last_seen_at'])) echo ' · '.e(t('zuletzt ','last seen ').fmt_datetime($a['last_seen_at']));
?></small></div></div><div><?php login_state_badge($a);?></div></div>
<?php };
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
<?php $mode=$a['state']==='suspended'?'restore':'suspend';start_form('account_state',['id'=>$a['id'],'mode'=>$mode],'inline-form');submit_button($mode==='restore'?t('Zugang entsperren','Restore the access'):t('Zugang sperren','Suspend the access'),'secondary');?></form>
<?php if($a['state']==='invited'){start_form('account_state',['id'=>$a['id'],'mode'=>'reinvite'],'inline-form');submit_button(t('Einladung erneut senden','Send the invitation again'),'secondary');echo '</form>';}?>
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
