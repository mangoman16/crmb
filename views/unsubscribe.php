<?php $id=(int)($_GET['account']??0);$category=$_GET['category']??'';$signature=$_GET['signature']??''; ?>
<div class="auth-card card"><h1><?=e(t('E-Mails abmelden','Unsubscribe from emails'))?></h1>
<?php if(valid_unsubscribe($id,$category,$signature)): ?><p><?=e(unsubscribe_stops($category))?></p>
<?php start_form('unsubscribe',['account'=>$id,'category'=>$category,'signature'=>$signature]);submit_button(t('Abmeldung bestätigen','Confirm unsubscribe'));?></form>
<?php else: ?><p><?=e(unsubscribe_refusal())?></p><?php endif ?></div>
