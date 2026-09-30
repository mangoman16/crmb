<?php
/**
 * The account menu in the top bar (ADR 0016): „Mein Konto", for staff the
 * status they choose (ADR 0015), and „Abmelden".
 *
 * Required by views/layout.php, whose $user and $realUser it reads. It only
 * reads: the status and the sign-out are POSTs of their own, each in its own
 * form inside the panel - never around it, because a form inside a form is
 * thrown away by the browser.
 *
 * A family's menu is „Mein Konto" and „Abmelden" and nothing else, and their
 * avatar has no dot: the owner decided presence is for staff only. While staff
 * look through somebody else's eyes the status is left out too, because it
 * would change the other person's and the action refuses it anyway.
 */
// The dot on the avatar and the status are the reader's own, so both are left
// out while staff look through somebody else's eyes: drawn from $self below,
// the dot would show the person looked at as online this minute.
$statusShown=is_staff($user) && $realUser===null;
// The person reading this page is here now, whatever the row loaded before this
// request's touch says: their own dot would otherwise show the gap since their
// previous visit.
$self=['last_seen_at'=>now()]+$user;
$choice=presence_choice($user);
$statusText=$choice==='hidden'?t('Als offline angezeigt','Appearing offline'):presence_state_label(presence_state($self));
$choiceDots=['auto'=>'online','away'=>'away','hidden'=>'offline'];
?>
<details class="topbar-menu account-menu">
    <summary aria-label="<?=e(t('Konto-Menü: ','Account menu: ').$user['name'].($statusShown?', '.$statusText:''))?>">
        <span class="avatar-presence"><?=avatar($user,'tiny')?><?=$statusShown?presence_dot($user,$self):''?></span>
        <span class="account-menu-who"><strong><?=e($user['name'])?></strong><small><?=e(role_label($user['role']))?></small></span>
        <span class="account-menu-chevron" aria-hidden="true"></span>
    </summary>
    <div class="topbar-menu-panel account-menu-panel">
        <?php /* Who this is, for a phone, where the button is the picture alone. */ ?>
        <div class="account-menu-head"><strong><?=e($user['name'])?></strong><small><?=e(role_label($user['role']).($statusShown?' · '.$statusText:''))?></small></div>
        <a class="account-menu-row" href="<?=e(url('profile'))?>"><span class="account-menu-text"><?=e(t('Mein Konto','My account'))?><small><?=e(t('Bild, Name, Passwort, Farbe','Picture, name, password, colour'))?></small></span></a>
        <?php if($statusShown): ?>
        <div class="account-menu-status" role="group" aria-labelledby="account-menu-status">
            <span class="account-menu-label" id="account-menu-status"><?=e(t('Status','Status'))?></span>
            <?php foreach(presence_choices() as $key=>$label):
                /* The current one is a marked row, not a button: pressing it would
                   change nothing, so it is not offered (ADR 0016). */
                if($key===$choice): ?>
            <div class="account-menu-row is-current"><?=presence_dot_for($choiceDots[$key],false)?><span class="account-menu-text"><?=e($label)?><span class="visually-hidden"><?=e(t(' (gewählt)',' (selected)'))?></span></span><?=icon('check')?></div>
            <?php else: start_form('presence_save',['presence'=>$key],'inline-form'); ?>
            <button class="account-menu-row" type="submit"><?=presence_dot_for($choiceDots[$key],false)?><span class="account-menu-text"><?=e($label)?></span></button></form>
            <?php endif; endforeach ?>
            <span class="account-menu-note"><?=e(t('„Als offline anzeigen“: Trainerinnen sehen dich als offline. Administratoren sehen weiterhin, wann du online warst.','“Appear offline”: trainers see you as offline. Administrators still see when you were online.'))?></span>
        </div>
        <?php endif ?>
        <div class="account-menu-signout"><?php start_form('logout',[],'inline-form'); ?>
            <button class="account-menu-row" type="submit"><?=icon('logout')?><span class="account-menu-text"><?=e(t('Abmelden','Sign out'))?></span></button></form></div>
    </div>
</details>
