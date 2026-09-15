<?php if($user)go('dashboard'); ?>
<div class="auth-card card"><span class="eyebrow"><?=e(t('Dein Badminton-Portal','Your badminton portal'))?></span><h1><?=e(t('Willkommen zurück.','Welcome back.'))?></h1><p class="muted"><?=e(t('Melde dich mit deinem eingeladenen Konto an.','Sign in with your invited account.'))?></p>
<?php start_form('login'); input('email',t('E-Mail-Adresse','Email address'),'','email',true); ?>
<div class="field"><label for="password"><?=e(t('Passwort','Password'))?></label><input type="password" name="password" id="password" required autocomplete="current-password"></div>
<?php submit_button(t('Anmelden','Sign in')); ?></form><a class="text-link" href="<?=e(url('forgot'))?>"><?=e(t('Passwort vergessen?','Forgot password?'))?></a></div>
