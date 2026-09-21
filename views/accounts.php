<?php page_head(t('Konten und Einladungen','Accounts and invitations'),t('Ein Konto kann mehrere Schüler verwalten.','One account can manage several students.')); ?>
<details class="card" <?=!scalar('SELECT COUNT(*) FROM accounts WHERE role=?',['student'])?'open':''?>><summary><?=e(t('+ Konto einladen','+ Invite account'))?></summary>
<?php /* For a trainer or a second administrator. A family is invited from the
         child's own page, where the address already is - inviting them here
         means typing it a second time and then remembering to connect the two. */ ?>
<p class="muted"><?=e(t('Für Trainerinnen und Administratoren. Eine Familie lädst du beim Kind ein – dort steht die Adresse schon, und das Konto wird gleich richtig zugeordnet.','For trainers and administrators. A family is invited from the child’s own page, where the address already is and the account is linked to the right child straight away.'))?></p>
<?php start_form('account_invite');?><div class="grid two"><?php input('name',t('Name der Kontaktperson','Account holder name'),'','text',true);input('email',t('E-Mail-Adresse','Email address'),'','email',true);select_field('locale',t('Sprache der Einladung','Invitation language'),['de'=>'Deutsch','en'=>'English'],'de',true);$roles=[];foreach(assignable_roles($user) as $r)$roles[$r]=role_label($r);
if(count($roles)>1)select_field('role',t('Rolle','Role'),$roles,'student',true);?></div><?php submit_button(t('Einladung senden','Send invitation'));?></form></details>

<?php /* The same account, made on the spot with a password instead of a link.
         Inviting needs working SMTP and a released privacy notice; this needs
         neither, which is what makes the portal usable on the first evening and
         testable at all. Administrators only. */
if(is_admin($user)): ?>
<details class="card"><summary><?=e(t('+ Konto direkt anlegen (ohne E-Mail)','+ Create an account directly (no email)'))?></summary>
<p class="muted"><?=e(t('Wenn keine Einladung möglich oder gewünscht ist: zum Ausprobieren, für eine zweite Administratorin, oder für jemanden, der neben dir steht und sein Passwort selbst eintippt. Die Adresse wird dabei nicht bestätigt.','When an invitation is not possible or not wanted: to try the portal out, for a second administrator, or for somebody standing next to you who types their own password. The address is not confirmed this way.'))?></p>
<?php start_form('account_create');?><div class="grid two"><?php
input('name',t('Name','Name'),'','text',true);
input('email',t('E-Mail-Adresse','Email address'),'','email',true);
input('password',t('Passwort','Password'),'','password',true,t('Mindestens 12 Zeichen. Diese Person kann es später selbst ändern.','At least 12 characters. They can change it themselves later.'));
select_field('locale',t('Sprache','Language'),['de'=>'Deutsch','en'=>'English'],'de',true);
$direct=[];foreach(assignable_roles($user) as $r)$direct[$r]=role_label($r);
select_field('role',t('Rolle','Role'),$direct,'student',true);
?></div><?php submit_button(t('Konto anlegen','Create account'));?></form></details>
<?php endif ?>
<div class="card">
<?php foreach(rows('SELECT a.*,(SELECT COUNT(*) FROM students s WHERE s.account_id=a.id) AS student_count FROM accounts a ORDER BY a.name') as $a): ?>
<div class="account-row"><div class="record-row"><div class="account-identity"><?=avatar($a)?><div><h3><?=e($a['name'])?></h3><p><?=e($a['email'])?></p><small><?=e(role_label($a['role']))?> · <?=(int)$a['student_count']?> <?=e(t('Schüler','students'))?><?php
    if(is_online($a['last_seen_at']??null)) echo ' · <span class="online"><span class="dot"></span>'.e(t('online','online')).'</span>';
    elseif(!empty($a['last_seen_at'])) echo ' · '.e(t('zuletzt ','last seen ').fmt_datetime($a['last_seen_at']));
?></small></div></div><div><?php badge(['active'=>t('Aktiv','Active'),'invited'=>t('Eingeladen','Invited'),'suspended'=>t('Gesperrt','Suspended')][$a['state']],$a['state']==='active'?'green':($a['state']==='suspended'?'red':''));?></div></div>
<?php if((int)$a['id']!==(int)$user['id'] && ($user['role']==='admin' || $a['role']==='student')): ?>
<div class="row-actions">
<?php /* Looking through somebody's eyes rather than asking them to describe what
         they see. The bar at the top of every page says whose eyes, and one
         button gets back out. */
if(may_impersonate($user,$a)){start_form('impersonate',['id'=>$a['id'],'mode'=>'start'],'inline-form');submit_button(t('Portal als diese Person ansehen','View the portal as this person'),'secondary');echo '</form>';} ?>
<?php $mode=$a['state']==='suspended'?'restore':'suspend';start_form('account_state',['id'=>$a['id'],'mode'=>$mode],'inline-form');submit_button($mode==='restore'?t('Entsperren','Restore access'):t('Zugang sperren','Suspend access'),'secondary');?></form>
<?php if($a['state']==='invited'){start_form('account_state',['id'=>$a['id'],'mode'=>'reinvite'],'inline-form');submit_button(t('Einladung erneut senden','Resend invitation'),'secondary');echo '</form>';}?>
<details class="account-delete"><summary><?=e(t('Konto löschen','Delete account'))?></summary><p><?=e(t('Login, private Unterhaltungen und E-Mails werden gelöscht. Schüler und Zahlungsdaten bleiben erhalten und können einem anderen Konto zugeordnet werden.','The login, private conversations and emails will be deleted. Students and payment records remain and can be linked to another account.'))?></p><?php start_form('account_state',['id'=>$a['id'],'mode'=>'delete']);input('confirmation',t('Zur Bestätigung die E-Mail-Adresse eingeben','Enter the email address to confirm'),'','email',true);submit_button(t('Konto endgültig löschen','Permanently delete account'),'danger');?></form></details></div>
<?php endif ?></div><?php endforeach ?></div>
