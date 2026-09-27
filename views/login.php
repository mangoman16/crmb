<?php if($user)go('dashboard'); ?>
<div class="auth-card card"><h1><?=e(t('Anmelden','Sign in'))?></h1>
<?php start_form('login'); input('email',t('E-Mail-Adresse','Email address'),'','email',true); ?>
<?php /* Written out rather than through input(), because the browser needs
         autocomplete="current-password" to offer the saved one - but it carries
         the same asterisk as every other required field, or the form appears to
         be asking for one thing and requiring two. */ ?>
<div class="field"><label for="password"><?=e(t('Passwort','Password'))?> <span aria-hidden="true">*</span></label><input type="password" name="password" id="password" required autocomplete="current-password"></div>
<?php submit_button(t('Anmelden','Sign in')); ?></form>
<?php /* Said where it applies: under the button that signs you in. Setting up an
         account asks the same thing its own way, with a link and a box to tick
         (activate.php); the forgotten-password and unsubscribe pages sign nobody
         in, so they do not carry it. The noun alone is the link, so the sentence
         still reads as a sentence. */ ?>
<p class="signin-consent"><?=e(t('Mit der Anmeldung akzeptierst du die ','By signing in you accept the '))?><a href="<?=e(url('privacy'))?>"><?=e(t('Datenschutzerklärung','privacy notice'))?></a>.</p>
<a class="text-link" href="<?=e(url('forgot'))?>"><?=e(t('Passwort vergessen?','Forgot password?'))?></a></div>
