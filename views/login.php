<?php if($user)go('dashboard');
/* One box, for the address (ADR 0021 §1, 0030 §1): type="email" brings „@"
   and „." to the keyboard and checks the shape before posting.
   sign_in_address_attributes() says why the rest of the box looks as it does.
   Posted as login; attempted_address() reads it. */ ?>
<div class="auth-card card"><h1><?=e(t('Anmelden','Sign in'))?></h1>
<?php start_form('login');
input('login',t('E-Mail-Adresse','Email address'),'','email',true,'','',sign_in_address_attributes());
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
