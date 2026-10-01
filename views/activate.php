<?php $r=token_record($_SESSION['activation_hash']??''); ?>
<div class="auth-card card">
<?php if(!$r || $r['state']==='suspended'):
    /* What she can do about it, not only that it failed: „vergessen" sends a
       new link, and an expired invitation again too (ADR 0020, §4). */ ?>
<h1><?=e(t('Link nicht mehr gültig','Link no longer valid'))?></h1>
<p><?=e(t('Eine Einladung gilt 48 Stunden, ein Link für ein neues Passwort eine Stunde. Unter „Benutzername oder Passwort vergessen“ bekommst du einen neuen Link, auch für eine abgelaufene Einladung.',
          'An invitation is valid for 48 hours, a link for a new password for one hour. Under “Forgot your username or password” you get a new link, for an expired invitation too.'))?></p>
<p><?=link_button(t('Neuen Link anfordern','Request a new link'),'forgot')?></p>
<a class="text-link" href="<?=e(url('login'))?>"><?=e(t('Zur Anmeldung','Back to sign in'))?></a>
<?php elseif($r['purpose']==='email' && (!$user || (int)$user['id']!==(int)$r['account_id'])): ?><h1><?=e(t('Bitte zuerst anmelden','Please sign in first'))?></h1><p><?=e(t('Melde dich an – mit deinem Benutzernamen oder deiner E-Mail-Adresse – und öffne diesen Link dann noch einmal.','Sign in – with your username or your email address – then open this link again.'))?></p><?=link_button(t('Anmelden','Sign in'),'login')?>
<?php else: $invite=$r['purpose']==='invite'; ?><span class="eyebrow"><?=e($r['name'])?></span><h1><?=e($invite?t('Konto einrichten','Set up your account'):($r['purpose']==='email'?t('E-Mail bestätigen','Verify email'):t('Neues Passwort','New password')))?></h1>
<?php start_form('activate');
if($r['purpose']!=='email'){
    /* The username, beside the new password: that pair is what lets an iPhone
       save the password under the right name. On an invitation it is the
       person's to keep or change (ADR 0020, §2), so it is a box to type in,
       filled in with the one made for them - with no pattern, because the
       server turns Lena.Müller into lena.mueller and a pattern would refuse it
       first. A name that is taken comes back here with what was typed. On a
       reset it is to read (N10): read-only rather than disabled, which the
       password manager would skip; activate ignores what it posts. */
    if($invite)
        input('username',t('Benutzername','Username'),(string)$r['username'],'text',true,
              t('So meldest du dich an – oder mit deiner E-Mail-Adresse. Du kannst ihn so lassen oder ändern.','You sign in with this – or with your email address. You can keep it or change it.'),
              '',username_attributes()+['maxlength'=>'40','class'=>'mono']);
    else
        input('username',t('Benutzername','Username'),(string)$r['username'],'text',false,
              t('Damit meldest du dich an – oder mit deiner E-Mail-Adresse.','You sign in with this – or with your email address.'),
              '',username_attributes()+['readonly'=>'readonly','class'=>'mono']);
    input('password',$invite?t('Passwort','Password'):t('Neues Passwort','New password'),'','password',true,t('Mindestens 12 Zeichen.','At least 12 characters.'));
    input('password_confirm',t('Passwort wiederholen','Repeat password'),'','password',true);
}else echo '<p>'.e($r['target_email']).'</p>';
if($invite): ?>
<p><a class="text-link" href="<?=e(url('privacy'))?>" target="_blank" rel="noopener"><?=e(privacy_in_german_only()?t('Datenschutzerklärung lesen','Read the privacy notice (in German)'):t('Datenschutzerklärung lesen','Read the privacy notice'))?></a></p>
<?php check_field('privacy_seen',t('Ich habe die Datenschutzhinweise gelesen.','I have read the privacy notice.'));check_field('newsletter',t('Neuigkeiten des Vereins per E-Mail erhalten. Jederzeit abbestellbar.','Receive club news by email. You can stop them at any time.'),true);check_field('notifications',t('Bei neuen privaten Nachrichten per E-Mail benachrichtigen.','Email me about new private messages.'),true);endif;
submit_button($invite?t('Konto aktivieren','Activate account'):($r['purpose']==='email'?t('Bestätigen','Confirm'):t('Passwort speichern','Save the password')));?></form>
<?php endif ?></div>
