<?php $r=token_record($_SESSION['activation_hash']??''); ?>
<div class="auth-card card">
<?php if(!$r || $r['state']==='suspended'):
    /* What she can do about it, not only that it failed: somebody who already
       set up just signs in, and „vergessen" sends a new link, an expired
       invitation again too (ADR 0020, §4). */ ?>
<h1><?=e(t('Link nicht mehr gültig','Link no longer valid'))?></h1>
<p><?=e(t('Schon eingerichtet? Dann melde dich einfach mit deiner E-Mail-Adresse an.','Already set up? Then just sign in with your email address.'))?></p>
<p><?=e(t('Eine Einladung gilt 48 Stunden, ein Link für ein neues Passwort eine Stunde. Unter „Passwort vergessen“ bekommst du einen neuen Link, auch für eine abgelaufene Einladung.',
          'An invitation is valid for 48 hours, a link for a new password for one hour. Under “Forgot your password” you get a new link, for an expired invitation too.'))?></p>
<p><?=link_button(t('Neuen Link anfordern','Request a new link'),'forgot')?></p>
<a class="text-link" href="<?=e(url('login'))?>"><?=e(t('Zur Anmeldung','Back to sign in'))?></a>
<?php elseif($r['purpose']==='email' && (!$user || (int)$user['id']!==(int)$r['account_id'])): ?><h1><?=e(t('Bitte zuerst anmelden','Please sign in first'))?></h1><p><?=e(t('Melde dich mit deiner bisherigen E-Mail-Adresse an und öffne diesen Link dann noch einmal.','Sign in with your previous email address, then open this link again.'))?></p><?=link_button(t('Anmelden','Sign in'),'login')?>
<?php else:
    $invite=$r['purpose']==='invite';
    /* Decided from the token, never from the post (ADR 0021, §3): an invitation
       to a student login that has no student yet makes that student here, from
       the three details asked below. */
    $createsStudent=$invite && setup_creates_student($r);
    // An invitation by address alone has no name yet, so the portal's own name
    // says where this is.
    $eyebrow=!$createsStudent && (string)$r['name']!==''?(string)$r['name']:(string)setting('club_name'); ?>
<?php if($eyebrow!==''): ?><span class="eyebrow"><?=e($eyebrow)?></span><?php endif ?>
<h1><?=e($invite?t('Konto einrichten','Set up your account'):($r['purpose']==='email'?t('E-Mail bestätigen','Verify email'):t('Neues Passwort','New password')))?></h1>
<?php if($createsStudent):
    /* Whose invitation this is, first: a link forwarded to the wrong person
       would otherwise make that person's student on somebody else's login. */ ?>
<p><?=e(strtr(t('Diese Einladung ist für {email}. Ist das nicht deine Adresse, dann trag hier bitte nichts ein und lösch die E-Mail einfach.',
                'This invitation is for {email}. If that is not your address, please enter nothing here and simply delete the email.'),['{email}'=>(string)$r['email']]))?></p>
<p class="muted"><?=e(t('Trag deine Angaben ein und leg ein Passwort fest. Danach wählst du deinen Kurs. Für ein Kind? Dann die Angaben des Kindes.',
                        'Enter your details and choose a password. After that you pick your course. For a child? Then the child’s details.'))?></p>
<?php endif;
start_form('activate');
if($r['purpose']!=='email'){
    if($createsStudent){
        echo '<h2>'.e(t('Deine Angaben','Your details')).'</h2>';
        input('first_name',t('Vorname','First name'),'','text',true,'','',['autocomplete'=>'given-name','autocapitalize'=>'words','maxlength'=>'100']);
        input('last_name',t('Nachname','Last name'),'','text',true,'','',['autocomplete'=>'family-name','autocapitalize'=>'words','maxlength'=>'100']);
        input('birth_date',t('Geburtsdatum','Date of birth'),'','date',true,t('Danach richtet sich die Altersgruppe.','It decides the age group.'),'',
              ['autocomplete'=>'bday','max'=>today()]);
        echo '<h2>'.e(t('Anmeldung','Signing in')).'</h2>';
    }
    /* The address, directly above the new password and in the same form: that
       pair is what lets an iPhone save the password under the address it signs
       in with. Read-only rather than disabled, which the password manager
       would skip, and never hidden; activate ignores what it posts. */
    input('email',t('E-Mail-Adresse','Email address'),(string)$r['email'],'email',false,t('Damit meldest du dich an.','You sign in with this.'),'',
          sign_in_address_attributes()+['readonly'=>'readonly']);
    input('password',$invite?t('Passwort','Password'):t('Neues Passwort','New password'),'','password',true,t('Mindestens 12 Zeichen.','At least 12 characters.'));
    input('password_confirm',t('Passwort wiederholen','Repeat password'),'','password',true);
}else echo '<p>'.e($r['target_email']).'</p>';
if($invite): ?>
<p><a class="text-link" href="<?=e(url('privacy'))?>" target="_blank" rel="noopener"><?=e(privacy_in_german_only()?t('Datenschutzerklärung lesen','Read the privacy notice (in German)'):t('Datenschutzerklärung lesen','Read the privacy notice'))?></a></p>
<?php check_field('privacy_seen',t('Ich habe die Datenschutzhinweise gelesen.','I have read the privacy notice.'));check_field('newsletter',t('Neuigkeiten des Vereins per E-Mail erhalten. Jederzeit abbestellbar.','Receive club news by email. You can stop them at any time.'),true);check_field('notifications',t('Bei neuen privaten Nachrichten per E-Mail benachrichtigen.','Email me about new private messages.'),true);endif;
submit_button($invite?t('Konto aktivieren','Activate account'):($r['purpose']==='email'?t('Bestätigen','Confirm'):t('Passwort speichern','Save the password')));?></form>
<?php endif ?></div>
