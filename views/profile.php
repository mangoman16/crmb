<?php /* The login, not the person: how you sign in and how the portal looks to
         you. A family's record - name, courses, payments - is their "Profil" in
         the menu, and the two must not read as the same page. */
page_head(t('Mein Konto','My account'),t('Wie du dich anmeldest und wie das Portal für dich aussieht.','How you sign in and how the portal looks for you.'));
/* Everything about signing in, in one card and first, as the heading promises:
   the address - the only name a login has (ADR 0021, §1) - whether a mailed
   link set the password lately, and the two changes. */
$resets=password_resets_for((int)$user['id'],PASSWORD_RESET_SHOWN_DAYS); ?>
<section class="card" id="sign-in">
    <h2><?=e(t('Anmeldung','Signing in'))?></h2>
    <?php login_facts($user,t('bestätigt','verified')); ?>
    <p class="muted"><?=e(t('Mit dieser Adresse meldest du dich an.','You sign in with this address.'))?></p>
    <?php if($resets): ?>
    <div class="notice warn"><strong><?=e(t('Dein Passwort wurde per E-Mail-Link neu gesetzt','Your password was set anew through an email link'))?></strong>
        <?php foreach($resets as $reset): ?><p><?=e(fmt_datetime((string)$reset['created_at']))?></p><?php endforeach ?>
        <?php /* A family asks the trainer; the trainer, or an administrator, would
                 be sent to herself (ADR 0019, I2). */ ?>
        <p><?=e(is_staff($user)
            ? t('Warst du das nicht? Ändere dein Passwort gleich hier unten.','Wasn’t that you? Change your password just below.')
            : t('Warst du das nicht? Ändere dein Passwort gleich hier unten und sag deiner Trainerin Bescheid.','Wasn’t that you? Change your password just below and let your coach know.'))?></p></div>
    <?php endif ?>
    <details><summary><?=e(t('E-Mail-Adresse ändern','Change email address'))?></summary><?php start_form('email_change');
    /* An address that is another login's is refused when the link is opened,
       not here (ADR 0020, §1): asked now, this box would tell a signed-in
       family whether an address has a login. */
    input('email',t('Neue E-Mail-Adresse','New email address'),'','email',true,
          t('Deine eigene, die kein anderer Zugang nutzt. Der Bestätigungslink geht an sie; bis du ihn öffnest, meldest du dich mit der bisherigen an.',
            'Your own, which no other login uses. The confirmation link goes to it; until you open it, you keep signing in with the current one.'),
          '',['autocomplete'=>'email']);
    input('password',t('Aktuelles Passwort','Current password'),'','password',true,'','',current_password_attributes());
    submit_button(t('Bestätigungslink senden','Send verification link'));?></form></details>
    <details><summary><?=e(t('Passwort ändern','Change password'))?></summary><?php start_form('password_change');
    input('current_password',t('Aktuelles Passwort','Current password'),'','password',true,'','',current_password_attributes());
    input('password',t('Neues Passwort','New password'),'','password',true);
    input('password_confirm',t('Passwort wiederholen','Repeat password'),'','password',true);
    submit_button();?></form></details>
</section>
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
<section class="card"><h2><?=e(t('Name und Darstellung','Name and appearance'))?></h2><?php start_form('preferences_save');?><div class="grid two"><?php
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
<?php /* The colour of each dot comes from the stylesheet, not from a style
         attribute: the portal's own Content-Security-Policy refuses inline
         styles, so a style attribute here left every dot grey and the choice
         impossible to make. */
foreach(accents() as $key=>$label): ?>
    <label class="accent-swatch" data-accent="<?=e($key)?>">
        <input type="radio" name="accent" value="<?=e($key)?>" <?=($user['accent']??'')===$key?'checked':''?>>
        <span class="accent-dot accent-<?=e($key)?>"></span><small><?=e($label)?></small></label>
<?php endforeach ?>
</div><p class="muted"><?=e(t('„Wie am Gerät eingestellt“ übernimmt den Dunkelmodus von iPhone, iPad oder Mac automatisch.','“Match my device” follows the dark mode setting on your iPhone, iPad or Mac automatically.'))?></p><?php check_field('newsletter',t('Neuigkeiten per E-Mail erhalten','Receive news by email'),(bool)$user['newsletter'],'',false,true);check_field('notifications',t('E-Mail-Hinweise bei privaten Nachrichten erhalten','Receive email notifications for private messages'),(bool)$user['notifications'],'',false,true);
check_field('payment_notices',t('Erinnerung, wenn ein Beitrag offen ist','Remind me when a payment is outstanding'),(bool)($user['payment_notices']??true),'',false,true);
submit_button();?></form></section>
<?php /* The sidebar's foot holds these on a computer; on a phone a family has
         no „Mehr", so they are here - and on staff's „Mehr" too. */
privacy_and_help_group();
sign_out_row(); ?>
