<?php
/**
 * The account menu in the top bar (ADR 0016): „Mein Konto", for staff the
 * status they choose (ADR 0015), the status emoji anybody may pick (ADR 0022),
 * and „Abmelden".
 *
 * Required by views/layout.php, whose $user and $realUser it reads. It only
 * reads: the status, the emoji and the sign-out are POSTs of their own, each in
 * its own form inside the panel - never around it, because a form inside a form
 * is thrown away by the browser.
 *
 * Everybody's avatar has its dot, a child's always automatic (ADR 0022). While
 * staff look through somebody else's eyes the dot, the status and the emoji are
 * left out: they are the reader's own, and choosing one would change the other
 * person's - the actions refuse it anyway.
 */
$own=$realUser===null;
$statusShown=$own && is_staff($user);
// The person reading this page is here now, whatever the row loaded before this
// request's touch says: their own dot would otherwise show the gap since their
// previous visit.
$self=['last_seen_at'=>now()]+$user;
$choice=presence_choice($user);
$statusText=$choice==='hidden'?t('Als offline angezeigt','Appearing offline'):presence_state_label(presence_state($self));
$choiceDots=['auto'=>'online','away'=>'away','hidden'=>'offline'];
$emoji=status_emoji($user);
?>
<details class="topbar-menu account-menu">
    <summary aria-label="<?=e(t('Konto-Menü: ','Account menu: ').$user['name'].($own?', '.$statusText:''))?>">
        <span class="avatar-presence"><?=avatar($user,'tiny')?><?=$own?presence_dot($user,$self):''?></span>
        <span class="account-menu-who"><strong><?=chat_name($user)?></strong><small><?=e(role_label($user['role']))?></small></span>
        <span class="account-menu-chevron" aria-hidden="true"></span>
    </summary>
    <div class="topbar-menu-panel account-menu-panel">
        <?php /* Who this is, for a phone, where the button is the picture alone. */ ?>
        <div class="account-menu-head"><strong><?=chat_name($user)?></strong><small><?=e(role_label($user['role']).($own?' · '.$statusText:''))?></small></div>
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
        </div>
        <?php endif ?>
        <?php if($own): ?>
        <details class="emoji-choice">
            <summary class="account-menu-row"><span class="account-menu-text"><?=e(t('Status-Emoji','Status emoji'))?></span><span class="emoji-choice-current" aria-hidden="true"><?=e($emoji[0]??'')?></span></summary>
            <?php /* One tap saves. The current one is marked, not a button, as above. */
            start_form('status_emoji_save',[],'emoji-grid');
            foreach(status_emojis()+['' => ['',t('Keins','None')]] as $key=>[$glyph,$label]):
                $current=(string)($user['status_emoji']??'')===(string)$key || ($key==='' && !$emoji);
                if($current): ?>
            <span class="emoji-cell is-chosen<?=$key===''?' emoji-none':''?>" title="<?=e($label)?>"><?=e($key===''?$label:$glyph)?><span class="visually-hidden"><?=e(($key===''?'':' '.$label).t(' (gewählt)',' (selected)'))?></span></span>
                <?php else: ?>
            <button class="emoji-cell<?=$key===''?' emoji-none':''?>" type="submit" name="status_emoji" value="<?=e($key)?>" title="<?=e($label)?>" aria-label="<?=e($label)?>"><?=e($key===''?$label:$glyph)?></button>
            <?php endif; endforeach ?></form>
        </details>
        <?php endif ?>
        <div class="account-menu-signout"><?php start_form('logout',[],'inline-form'); ?>
            <button class="account-menu-row" type="submit"><?=icon('logout')?><span class="account-menu-text"><?=e(t('Abmelden','Sign out'))?></span></button></form></div>
    </div>
</details>
