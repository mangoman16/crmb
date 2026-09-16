<?php page_head(t('Mein Konto','My account')); ?>
<section class="card">
    <h2><?=e(t('Bild','Picture'))?></h2>
    <div class="avatar-editor">
        <?=avatar($user,'large')?>
        <div>
            <p class="muted"><?=e(t('Ein Bild ist freiwillig. Ohne eines zeigt das Portal deine Anfangsbuchstaben.','A picture is optional. Without one the portal shows your initials.'))?></p>
            <?php start_form('avatar_save',['kind'=>'account','id'=>$user['id']],'form',true);
            file_field('avatar',t('Bild auswählen','Choose a picture'),'avatar');
            submit_button(t('Bild speichern','Save the picture'),'secondary');?></form>
            <?php if(($user['avatar_name']??'')!==''){start_form('avatar_save',['kind'=>'account','id'=>$user['id'],'remove'=>1],'inline-form');submit_button(t('Bild entfernen','Remove the picture'),'subtle danger-text');echo '</form>';} ?>
        </div>
    </div>
</section>
<section class="card"><h2><?=e(t('Profil und Darstellung','Profile and appearance'))?></h2><?php start_form('preferences_save');?><div class="grid two"><?php
input('name',t('Name','Name'),$user['name'],'text',true);
select_field('locale',t('Sprache','Language'),['de'=>'Deutsch','en'=>'English'],$user['locale'],true);
select_field('theme',t('Erscheinungsbild','Appearance'),['auto'=>t('Wie am Gerät eingestellt','Match my device'),'light'=>t('Immer hell','Always light'),'dark'=>t('Immer dunkel','Always dark')],$user['theme']??'auto',true);
select_field('text_scale',t('Schriftgröße','Text size'),['normal'=>t('Normal','Normal'),'large'=>t('Größer','Larger'),'larger'=>t('Noch größer','Even larger'),'largest'=>t('Am größten','Largest')],$user['text_scale']??'normal',true);
?></div>
<h3><?=e(t('Farbe','Colour'))?></h3>
<p class="muted"><?=e(t('Nur für dich. Andere sehen ihre eigene.','Only for you. Everybody else sees their own.'))?></p>
<div class="accent-picker">
    <label class="accent-swatch" data-accent="">
        <input type="radio" name="accent" value="" <?=($user['accent']??'')===''?'checked':''?>>
        <span class="accent-dot is-default"></span><small><?=e(t('Wie eingestellt','As set up'))?></small></label>
<?php foreach(accents() as $key=>[$colour,$label]): ?>
    <label class="accent-swatch" data-accent="<?=e($key)?>">
        <input type="radio" name="accent" value="<?=e($key)?>" <?=($user['accent']??'')===$key?'checked':''?>>
        <span class="accent-dot" style="background:<?=e($colour)?>"></span><small><?=e($label)?></small></label>
<?php endforeach ?>
</div><p class="muted"><?=e(t('„Wie am Gerät eingestellt“ übernimmt den Dunkelmodus von iPhone, iPad oder Mac automatisch.','“Match my device” follows the dark mode setting on your iPhone, iPad or Mac automatically.'))?></p><p class="muted"><?=e($user['email'])?> · <?=e(t('bestätigt','verified'))?></p><?php check_field('newsletter',t('Neuigkeiten per E-Mail erhalten','Receive news by email'),(bool)$user['newsletter']);check_field('notifications',t('E-Mail-Hinweise bei privaten Nachrichten erhalten','Receive email notifications for private messages'),(bool)$user['notifications']);
check_field('payment_notices',t('Erinnerung, wenn ein Beitrag offen ist','Remind me when a payment is outstanding'),(bool)($user['payment_notices']??true));
submit_button();?></form></section>
<section class="card"><details><summary><?=e(t('E-Mail-Adresse ändern','Change email address'))?></summary><?php start_form('email_change');input('email',t('Neue E-Mail-Adresse','New email address'),'','email',true);input('password',t('Aktuelles Passwort','Current password'),'','password',true);submit_button(t('Bestätigungslink senden','Send verification link'));?></form></details></section>
<section class="card"><details><summary><?=e(t('Passwort ändern','Change password'))?></summary><?php start_form('password_change');input('current_password',t('Aktuelles Passwort','Current password'),'','password',true);input('password',t('Neues Passwort','New password'),'','password',true);input('password_confirm',t('Passwort wiederholen','Repeat password'),'','password',true);submit_button();?></form></details></section>
<?php start_form('logout',[],'inline-form');submit_button(t('Abmelden','Sign out'),'secondary');?></form>
