<?php /* „Passwort vergessen" (ADR 0021, §1): the same box as the sign-in page. A
         login in use gets a link for a new password, an invited one its
         invitation again, so the explanation says "ein Passwort", true of both.
         The mail goes to the address stored on the login. The page reads the
         same afterwards whether anything was found or not, and the box is empty
         again, so nothing here says which it was. The last sentence is the
         owner's decision: the trainer can always say which address somebody is
         registered with. */ ?>
<div class="auth-card card"><h1><?=e(t('Passwort vergessen','Forgot your password'))?></h1>
<p class="muted"><?=e(t('Gib deine E-Mail-Adresse oder deinen Benutzernamen ein. Du bekommst dann einen Link, mit dem du ein Passwort festlegst.',
                        'Enter your email address or your username. You will then get a link to set a password.'))?></p>
<?php start_form('forgot');
input('login',t('E-Mail oder Benutzername','Email or username'),'','text',true,'','',sign_in_address_attributes()+['inputmode'=>'email']);
submit_button(t('E-Mail schicken','Send the email')); ?></form>
<p class="muted"><?=e(t('Ohne E-Mail-Adresse angemeldet? Dann bekommst du einen neuen Anmeldelink von deiner Trainerin.','Signed up without an email address? Then your coach gives you a new sign-in link.'))?></p>
<p class="muted"><?=e(t('Keine E-Mail bekommen? Schau auch im Spam-Ordner nach. Oder frag deine Trainerin – sie sieht, mit welcher Adresse du eingetragen bist.',
                        'No email? Check the spam folder too. Or ask your coach – she can see which address you are registered with.'))?></p>
<a class="text-link" href="<?=e(url('login'))?>"><?=e(t('Zur Anmeldung','Back to sign in'))?></a></div>
