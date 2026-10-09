<?php
/**
 * The account menu in the top bar (ADR 0016): „Mein Konto" and „Abmelden".
 *
 * Required by views/layout.php, whose $user it reads. It only reads: the sign-out
 * is a POST of its own, in its own form inside the panel - never around it,
 * because a form inside a form is thrown away by the browser.
 */
?>
<details class="topbar-menu account-menu">
    <summary aria-label="<?=e(t('Konto-Menü: ','Account menu: ').$user['name'])?>">
        <?=avatar($user,'tiny')?>
        <span class="account-menu-who"><strong><?=e($user['name'])?></strong><small><?=e(role_label($user['role']))?></small></span>
        <span class="account-menu-chevron" aria-hidden="true"></span>
    </summary>
    <div class="topbar-menu-panel account-menu-panel">
        <?php /* Who this is, for a phone, where the button is the initials alone. */ ?>
        <div class="account-menu-head"><strong><?=e($user['name'])?></strong><small><?=e(role_label($user['role']))?></small></div>
        <a class="account-menu-row" href="<?=e(url('profile'))?>"><span class="account-menu-text"><?=e(t('Mein Konto','My account'))?><small><?=e(my_account_hint())?></small></span></a>
        <div class="account-menu-signout"><?php start_form('logout',[],'inline-form'); ?>
            <button class="account-menu-row" type="submit"><?=icon('logout')?><span class="account-menu-text"><?=e(t('Abmelden','Sign out'))?></span></button></form></div>
    </div>
</details>
