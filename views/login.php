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
<?php /* Where to read about your data, under the button that signs you in. The
         notice informs; signing in is not agreeing to it (the coordinator,
         2026-10-09), so the line asks nothing and says where it is - one line
         at 320 px, so the link is not left alone on a line of its own: „Wie das
         Portal mit deinen Daten umgeht, steht in der Datenschutzerklärung." took
         three there. Setting up an account has its own sentence and box
         (activate.php). The noun alone is the link, and it is the page's one link
         to the notice: the footer leaves its own out ($privacyLinked, read by
         views/layout.php). No sign-up is offered anywhere: only people who were
         invited get in (ADR 0021, §3). */
$privacyLinked=true; ?>
<p class="signin-privacy"><?=e(t('Deine Daten: ','About your data: '))?><a href="<?=e(url('privacy'))?>"><?=e(t('Datenschutzerklärung','privacy notice'))?></a></p>
<a class="text-link" href="<?=e(url('forgot'))?>"><?=e(t('Passwort vergessen?','Forgot your password?'))?></a></div>
