<?php $id=(int)($_GET['account']??0);$category=(string)($_GET['category']??'');$signature=(string)($_GET['signature']??''); ?>
<div class="auth-card card"><h1><?=e(t('E-Mails abmelden','Unsubscribe from emails'))?></h1>
<?php if(valid_unsubscribe($id,$category,$signature)): ?><p><?=e($category==='newsletter'?t('Keine Neuigkeiten mehr per E-Mail erhalten. Im Portal bleiben sie lesbar.','Stop receiving news by email. News remains available in the portal.'):t('Keine E-Mail-Hinweise für private Nachrichten mehr erhalten. Nachrichten bleiben im Portal.','Stop receiving private message email notifications. Messages stay in the portal.'))?></p>
<?php start_form('unsubscribe',['account'=>$id,'category'=>$category,'signature'=>$signature]);submit_button(t('Abmeldung bestätigen','Confirm unsubscribe'));?></form>
<?php else: ?><p><?=e(t('Dieser Abmeldelink ist ungültig.','This unsubscribe link is invalid.'))?></p><?php endif ?></div>
