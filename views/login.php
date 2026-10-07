<?php if($user)go('dashboard');
/* One box for the address or the username (ADR 0023 §7): type="text", because
   type="email" refuses a username, and inputmode="email" keeps „@" and „." on
   the first keyboard layer. sign_in_address_attributes() says why the rest of
   the box looks as it does. Posted as login; attempted_sign_in() reads it. */ ?>
<div class="auth-card card"><h1><?=e(t('Anmelden','Sign in'))?></h1>
<?php start_form('login');
input('login',t('E-Mail oder Benutzername','Email or username'),'','text',true,'','',sign_in_address_attributes()+['inputmode'=>'email']);
input('password',t('Passwort','Password'),'','password',true,'','',current_password_attributes());
submit_button(t('Anmelden','Sign in')); ?></form>
<?php /* Said where it applies: under the button that signs you in. Setting up an
         account asks the same thing its own way, with a link and a box to tick
         (activate.php); the forgotten-password and unsubscribe pages sign nobody
         in, so they do not carry it. The noun alone is the link, so the sentence
         still reads as a sentence. No sign-up is offered anywhere: only people
         who were invited get in (ADR 0021, §3). */ ?>
<p class="signin-consent"><?=e(t('Mit der Anmeldung akzeptierst du die ','By signing in you accept the '))?><a href="<?=e(url('privacy'))?>"><?=e(t('Datenschutzerklärung','privacy notice'))?></a>.</p>
<a class="text-link" href="<?=e(url('forgot'))?>"><?=e(t('Passwort vergessen?','Forgot your password?'))?></a></div>
