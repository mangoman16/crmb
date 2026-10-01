<?php if($user)go('dashboard');
/* One box for the username or the address, whichever somebody remembers (ADR
   0020, §3). Posted as `username`; the action decides by the „@", which a
   username can never contain. inputmode="email" puts „@" and „." on the
   iPhone's first keyboard layer, and „." is in every username too. A refusal
   is one sentence for every failure and brings back what was typed, so this
   page says nothing about what was typed. */ ?>
<div class="auth-card card"><h1><?=e(t('Anmelden','Sign in'))?></h1>
<?php start_form('login');
input('username',t('Benutzername oder E-Mail-Adresse','Username or email address'),'','text',true,'','',username_attributes()+['inputmode'=>'email']);
input('password',t('Passwort','Password'),'','password',true,'','',current_password_attributes());
submit_button(t('Anmelden','Sign in')); ?></form>
<?php /* Said where it applies: under the button that signs you in. Setting up an
         account asks the same thing its own way, with a link and a box to tick
         (activate.php); the forgotten-password and unsubscribe pages sign nobody
         in, so they do not carry it. The noun alone is the link, so the sentence
         still reads as a sentence. No sign-up is offered anywhere: only people
         who were invited get in (ADR 0020, R-d). */ ?>
<p class="signin-consent"><?=e(t('Mit der Anmeldung akzeptierst du die ','By signing in you accept the '))?><a href="<?=e(url('privacy'))?>"><?=e(t('Datenschutzerklärung','privacy notice'))?></a>.</p>
<a class="text-link" href="<?=e(url('forgot'))?>"><?=e(t('Benutzername oder Passwort vergessen?','Forgot your username or password?'))?></a></div>
