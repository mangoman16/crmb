<?php if($user)go('dashboard'); ?>
<div class="auth-card card"><h1><?=e(t('Anmelden','Sign in'))?></h1><p class="muted"><?=e(t('Mit der E-Mail-Adresse, an die die Einladung geschickt wurde.','With the email address the invitation was sent to.'))?></p>
<?php start_form('login'); input('email',t('E-Mail-Adresse','Email address'),'','email',true); ?>
<div class="field"><label for="password"><?=e(t('Passwort','Password'))?></label><input type="password" name="password" id="password" required autocomplete="current-password"></div>
<?php submit_button(t('Anmelden','Sign in')); ?></form><a class="text-link" href="<?=e(url('forgot'))?>"><?=e(t('Passwort vergessen?','Forgot password?'))?></a></div>
