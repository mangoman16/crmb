<!doctype html>
<?php
[$theme,$textScale]=appearance($user);
$accent=accent_for($user);
$realUser=$public?null:impersonator();
?>
<html lang="<?=e(locale())?>"<?=$theme!=='auto'?' data-theme="'.e($theme).'"':''?><?=$textScale!=='normal'?' data-text="'.e($textScale).'"':''?> data-accent="<?=e($accent)?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php /* Matching the bar to the surface keeps the notch area from banding in standalone mode. */ ?>
    <meta name="theme-color" content="<?=e(brand_theme_colour($theme==='dark'?'dark':'light'))?>"<?=$theme==='auto'?' media="(prefers-color-scheme: light)"':''?>>
    <?php if($theme==='auto'): ?><meta name="theme-color" content="<?=e(brand_theme_colour('dark'))?>" media="(prefers-color-scheme: dark)"><?php endif ?>
    <?php /* Home-screen install: the manifest covers modern iOS and Android, the
             apple-* tags cover older iOS versions that ignore display:standalone.
             The manifest is built per request (ADR 0008) and is fetched with the
             session cookie: without it every fetch would start a new session. */ ?>
    <link rel="manifest" href="<?=e(url('manifest'))?>" crossorigin="use-credentials">
    <?php /* Her own icon when she has uploaded one, in both places it is looked
             for; otherwise the files that ship, so a portal nobody customised
             makes no extra request through PHP for its icon. */
    if(($portalIcon=portal_icon_url())!==''): ?>
    <link rel="icon" type="image/png" href="<?=e($portalIcon)?>">
    <link rel="apple-touch-icon" href="<?=e($portalIcon)?>">
    <?php else: ?>
    <link rel="icon" href="<?=e(asset_url('favicon.svg'))?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?=e(asset_url('apple-touch-icon.png'))?>">
    <?php endif ?>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?=e(setting('club_name','Badminton'))?>">
    <meta name="format-detection" content="telephone=no">
    <meta name="description" content="<?=e(setting('club_name').' – '.t('Schüler, Beiträge und Nachrichten.','students, payments and messages.'))?>">
    <title><?=e(setting('club_name','Badminton'))?></title>
    <link rel="stylesheet" href="<?=e(asset_url('app.css'))?>?v=<?=e(app_version())?>">
    <?php /* The club's colours, after app.css so they win at equal specificity;
             no request at all while none is set (ADR 0013). */
    if(($brandCss=brand_css_url())!==''): ?><link rel="stylesheet" href="<?=e($brandCss)?>"><?php endif ?>
    <script defer src="<?=e(asset_url('app.js'))?>?v=<?=e(app_version())?>"></script>
</head>
<body class="<?=$public?'public-page':'app-page'?>">
<a class="skip-link" href="#main"><?=e(t('Zum Inhalt','Skip to content'))?></a>
<?php if(!$public):
$unreadNotes=unread_notifications((int)$user['id']);
?>
<aside class="sidebar" id="sidebar">
    <?php /* The way out of the side menu on a phone. A link, so it works without
             JavaScript: „Mehr" opens the menu as #sidebar, and this leaves it. */
    if(is_staff($user)): ?><a class="menu-close" id="menu-close" href="#main"><?=e(t('Menü schließen','Close menu'))?></a><?php endif ?>
    <?php brand_block($user,'sidebar',url('dashboard')); ?>
    <?=sidebar_nav($user,$page)?>
    <?php /* The account used to be here as well as in the top bar. One of the two
             was always redundant, and the top bar is the one on screen whatever
             you have scrolled to, so only what it has no room for stays here. */ ?>
    <div class="sidebar-bottom">
        <a class="feedback-link" href="#feedback"><?=icon('help')?><span><?=e(t('Etwas funktioniert nicht','Something is wrong'))?></span></a>
        <div class="sidebar-meta"><a href="<?=e(url('privacy'))?>"><?=e(t('Datenschutz','Privacy'))?></a><span>v<?=e(app_version())?></span></div>
    </div>
</aside>
<div class="app-shell">
<?php /* Pinned, because everything in it - the language switch, what is waiting,
         who you are, and the way back out of impersonation - is wanted from
         wherever you happen to have scrolled to. */ ?>
<header class="topbar">
    <span class="topbar-context"><?=e((string)setting('portal_tagline'))?></span>
    <?php brand_block($user,'bar',url('dashboard')); ?>
    <div class="topbar-actions">
        <a class="language" href="<?=e(url($page,['lang'=>locale()==='de'?'en':'de']+array_intersect_key($_GET,array_flip(['id','tab']))))?>"><?=locale()==='de'?'EN':'DE'?></a>
        <details class="topbar-menu notification-pane">
            <summary aria-label="<?=e($unreadNotes?$unreadNotes.' '.t('neue Hinweise','new notifications'):t('Hinweise','Notifications'))?>">
                <?=icon('bell')?><?php if($unreadNotes):?><span class="count"><?=e($unreadNotes)?></span><?php endif ?>
            </summary>
            <div class="topbar-menu-panel notification-list">
                <div class="notification-head">
                    <strong><?=e(t('Hinweise','Notifications'))?></strong>
                    <?php if($unreadNotes){start_form('notifications_read',[],'inline-form');submit_button(t('Alle gelesen','Mark all read'),'subtle');echo '</form>';} ?>
                </div>
                <?php $notes=notifications_for((int)$user['id'],20);
                if(!$notes):?><p class="muted"><?=e(t('Nichts Neues.','Nothing new.'))?></p><?php endif ?>
                <?php foreach($notes as $note): ?>
                <a class="notification <?=$note['read_at']===null?'is-unread':''?>" href="<?=e(notification_link($note))?>">
                    <?=icon(notification_icon((string)$note['kind']))?>
                    <span><strong><?=e($note['title'])?></strong><?php if($note['body']):?><small><?=e($note['body'])?></small><?php endif ?>
                        <time><?=e(fmt_datetime((string)$note['created_at']))?></time></span>
                </a>
                <?php endforeach ?>
            </div>
        </details>
        <?php require ROOT.'/views/_account_menu.php'; ?>
    </div>
</header>
<?php else: ?>
<header class="public-header"><?php brand_block($user,'public',url($user?'dashboard':'login')); ?><a class="language" href="<?=e(url($page,['lang'=>locale()==='de'?'en':'de']+array_intersect_key($_GET,array_flip(['account','category','signature']))))?>"><?=locale()==='de'?'EN':'DE'?></a></header>
<?php endif ?>
<main id="main" class="<?=$public?'public-main':'main-content'?>">
<?php if($realUser): ?>
<div class="impersonation-bar" role="status">
    <span><?=e(t('Du siehst das Portal als ','You are seeing the portal as '))?><strong><?=e($user['name'])?></strong><?=e(t('. Angemeldet bist du als ','. You are signed in as '))?><strong><?=e($realUser['name'])?></strong>.</span>
    <?php start_form('impersonate',['mode'=>'stop'],'inline-form');submit_button(t('Ansicht beenden','Stop viewing'),'secondary');?></form>
</div>
<?php endif ?>
<?php if(!$public && is_admin($user) && is_file(maintenance_file())): ?>
<div class="flash error" role="status"><?=e(t('Wartungsmodus ist aktiv – für alle anderen ist das Portal geschlossen.','Maintenance mode is on – the portal is closed for everyone else.'))?> <a href="<?=e(url('settings',['tab'=>'system']))?>"><?=e(t('Beenden','Switch off'))?></a></div>
<?php endif ?>
<?php /* The way back to the checklist (ADR 0011), after she left it to do one of
         its steps. The flag is in the session, so it outlives the redirect after
         a save; it goes once she is back, or once there is nothing left to do. */
if(!$public && is_staff($user) && setup_return_active() && setup_unfinished()): $setup=setup_progress(); ?>
<nav class="setup-return" aria-label="<?=e(t('Einrichtung','Setup'))?>"><a href="<?=e(url('start'))?>"><span aria-hidden="true">←</span> <?=e(t('Zurück zur Einrichtung','Back to the setup').' ('.$setup['done'].' '.t('von','of').' '.$setup['total'].' '.t('erledigt','done').')')?></a></nav>
<?php endif ?>
<?php if(isset($_SESSION['flash'])):$f=$_SESSION['flash'];unset($_SESSION['flash']);?><div class="flash <?=e($f['kind'])?>" role="status"><?=e($f['message'])?></div><?php endif ?>
<?=$content?>
<?php if(!$public): ?>
<?php /* On every page, because the page something goes wrong on is the page you
         are looking at, and "email the administrator" needs a working email
         address and the presence of mind to describe where you were.

         Pinned to the bottom right on a desktop screen, where a help button has
         lived in every product she has ever used. On a phone it stays at the end
         of the page: the bottom of a phone screen already holds the menu bar and
         the sticky save button, and a third thing floating over them is how a
         Save button becomes unreachable. The menu carries a link down to it.

         A conversation keeps it at the end of the page on a desktop screen too:
         its writing box is pinned to the bottom of the window as well, and the
         help button sat on its Send button there until the thread was scrolled
         to its very end - so a click meant for Send opened this form instead. */
$pinnedHelp=!($page==='messages' && (int)($_GET['id']??0)>0); ?>
<details class="feedback<?=$pinnedHelp?' is-pinned':''?>" id="feedback">
    <summary><?=icon('help')?><span><?=e(t('Etwas funktioniert hier nicht','Something is wrong on this page'))?></span></summary>
    <div class="feedback-panel">
    <p class="muted"><?=e(t('Beschreibe kurz, was du erwartet hast und was passiert ist. Automatisch mitgeschickt werden: die Seite, die du gerade ansiehst, die Seiten davor samt dem, was du dort in Formulare eingetragen hast (ohne Passwörter), dein Gerät, deine IP-Adresse und die Version des Portals.','Say briefly what you expected and what happened. Sent along automatically: the page you are looking at, the pages before it together with what you entered in forms there (without passwords), your device, your IP address and the portal’s version.'))?></p>
    <?php start_form('feedback_send',['page'=>$page],'form',true);
    input('message',t('Was ist passiert?','What happened?'),'','textarea',true);
    file_field('screenshot',t('Bildschirmfoto (optional)','Screenshot (optional)'),'avatar',
               t('Falls du eines gemacht hast.','If you took one.'));
    submit_button(t('Absenden','Send'),'secondary');?></form>
    </div>
</details>
<?php endif ?>
</main>
<?php if(!$public): ?>
</div>
<nav class="mobile-nav" aria-label="<?=e(t('Mobilmenü','Mobile menu'))?>">
<?php foreach(mobile_nav_entries($user) as $item):?><a href="<?=e(url($item['route'],$item['params']))?>" <?=nav_is_current($item['route'],$page,$user)?'aria-current="page"':''?>><?=icon($item['icon'])?><?php
    // The bar prints the short word; a screen reader hears the entry's full name.
    if($item['short']!==$item['label']):?><span aria-hidden="true"><?=e($item['short'])?></span><span class="visually-hidden"><?=e($item['label'])?></span><?php else:?><span><?=e($item['label'])?></span><?php endif ?><?php if($item['count']):?><span class="count" aria-label="<?=e($item['count'].' '.t('ungelesen','unread'))?>"><?=e((string)$item['count'])?></span><?php endif ?></a><?php endforeach ?>
<?php /* A link to the side menu, so the entries that are not on the bar - Kurse,
         Geld, Einstellungen - are reachable without JavaScript (the stylesheet
         opens #sidebar when it is the target). app.js turns it into a button
         that slides the menu in and out without touching the address. */
if(is_staff($user)): ?><a href="#sidebar" id="menu-toggle" aria-controls="sidebar"><?=icon('more')?><span><?=e(t('Mehr','More'))?></span></a>
<?php endif ?>
</nav>
<a class="menu-backdrop" id="menu-backdrop" href="#main" aria-label="<?=e(t('Menü schließen','Close menu'))?>"></a>
<?php else: ?>
<?php /* Only the way to the privacy notice and the version. The paragraph that
         stood here was repeated at the foot of every public page, which is where
         nobody reads a paragraph; the one thing it had to say is now a sentence
         on the sign-in card. */ ?>
<footer class="public-footer"><a href="<?=e(url('privacy'))?>"><?=e(t('Datenschutzerklärung','Privacy notice'))?></a><span>v<?=e(app_version())?></span></footer>
<?php endif ?>
</body>
</html>
