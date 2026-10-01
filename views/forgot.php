<?php /* „Benutzername oder Passwort vergessen" (ADR 0020, §4): the same box as the
         sign-in page, for a username or an address. A login in use gets a link
         for a new password, an invited one its invitation again, so the
         explanation says "ein Passwort", true of both. The mail goes to the
         address stored on the login, never to what was typed. The page reads the
         same afterwards whether anything was found or not, and the box is empty
         again, so nothing here says which it was. The last sentence is the
         owner's decision: the trainer can always say what the username is. */ ?>
<div class="auth-card card"><h1><?=e(t('Benutzername oder Passwort vergessen','Forgot your username or password'))?></h1>
<p class="muted"><?=e(t('Gib deinen Benutzernamen oder deine E-Mail-Adresse ein. Du bekommst dann eine E-Mail mit deinem Benutzernamen und einem Link, mit dem du ein Passwort festlegst. Sie geht an die Adresse, die im Portal für dich eingetragen ist.',
                        'Enter your username or your email address. You will then get an email with your username and a link to set a password. It goes to the address the portal has for you.'))?></p>
<?php start_form('forgot');
input('username',t('Benutzername oder E-Mail-Adresse','Username or email address'),'','text',true,'','',username_attributes()+['inputmode'=>'email']);
submit_button(t('E-Mail schicken','Send the email')); ?></form>
<p class="muted"><?=e(t('Keine E-Mail bekommen? Schau auch im Spam-Ordner nach. Oder frag deine Trainerin – sie kann dir deinen Benutzernamen sagen.',
                        'No email? Check your spam folder too. Or ask your coach – she can tell you your username.'))?></p>
<a class="text-link" href="<?=e(url('login'))?>"><?=e(t('Zur Anmeldung','Back to sign in'))?></a></div>
