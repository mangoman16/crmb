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
    <meta name="theme-color" content="<?=$theme==='dark'?'#101922':'#13243a'?>"<?=$theme==='auto'?' media="(prefers-color-scheme: light)"':''?>>
    <?php if($theme==='auto'): ?><meta name="theme-color" content="#101922" media="(prefers-color-scheme: dark)"><?php endif ?>
    <?php /* Home-screen install: the manifest covers modern iOS and Android, the
             apple-* tags cover older iOS versions that ignore display:standalone. */ ?>
    <link rel="manifest" href="<?=e(rtrim(config('app_url'),'/'))?>/manifest.webmanifest">
    <link rel="apple-touch-icon" href="<?=e(rtrim(config('app_url'),'/'))?>/assets/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?=e(setting('club_name','Badminton'))?>">
    <meta name="format-detection" content="telephone=no">
    <meta name="description" content="<?=e(setting('club_name').' – '.t('Schüler, Beiträge und Nachrichten.','students, payments and messages.'))?>">
    <title><?=e(setting('club_name','Badminton'))?></title>
    <link rel="icon" href="<?=e(rtrim(config('app_url'),'/'))?>/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?=e(rtrim(config('app_url'),'/'))?>/assets/app.css?v=<?=e(app_version())?>">
    <script defer src="<?=e(rtrim(config('app_url'),'/'))?>/assets/app.js?v=<?=e(app_version())?>"></script>
</head>
<body class="<?=$public?'public-page':'app-page'?>">
<a class="skip-link" href="#main"><?=e(t('Zum Inhalt','Skip to content'))?></a>
<?php if(!$public):
$unreadTotal=unread_count($user);
$unreadNotes=unread_notifications((int)$user['id']);
?>
<aside class="sidebar" id="sidebar">
    <a class="brand" href="<?=e(url('dashboard'))?>"><span class="brand-mark">B<span></span></span><span><?=e(setting('club_name','Badminton'))?><small><?=e(is_staff($user)?t('Verwaltung','Management'):t('Mein Portal','My portal'))?></small></span></a>
    <?=sidebar_nav($user,$page)?>
    <?php /* The account used to be here as well as in the top bar. One of the two
             was always redundant, and the top bar is the one on screen whatever
             you have scrolled to, so only what it has no room for stays here. */ ?>
    <div class="sidebar-bottom"><div class="sidebar-meta"><a href="<?=e(url('privacy'))?>"><?=e(t('Datenschutz','Privacy'))?></a><span>v<?=e(app_version())?></span></div></div>
</aside>
<div class="app-shell">
<?php /* Pinned, because everything in it - the language switch, what is waiting,
         who you are, and the way back out of impersonation - is wanted from
         wherever you happen to have scrolled to. */ ?>
<header class="topbar">
    <span class="topbar-context"><?=e((string)setting('portal_tagline'))?></span>
    <a class="mobile-brand" href="<?=e(url('dashboard'))?>"><?=e(setting('club_name','Badminton'))?></a>
    <div class="topbar-actions">
        <a class="language" href="<?=e(url($page,['lang'=>locale()==='de'?'en':'de']+array_intersect_key($_GET,array_flip(['id','tab']))))?>"><?=locale()==='de'?'EN':'DE'?></a>
        <details class="notification-pane">
            <summary aria-label="<?=e($unreadNotes?$unreadNotes.' '.t('neue Hinweise','new notifications'):t('Hinweise','Notifications'))?>">
                <?=icon('bell')?><?php if($unreadNotes):?><span class="count"><?=e($unreadNotes)?></span><?php endif ?>
            </summary>
            <div class="notification-list">
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
        <a class="account-link compact" href="<?=e(url('profile'))?>"><?=avatar($user,'tiny')?><span><?=e($user['name'])?><small><?=e(role_label($user['role']))?></small></span></a>
    </div>
</header>
<?php else: ?>
<header class="public-header"><a class="brand" href="<?=e(url($user?'dashboard':'login'))?>"><span class="brand-mark">B<span></span></span><?=e(setting('club_name','Badminton'))?></a><a class="language" href="<?=e(url($page,['lang'=>locale()==='de'?'en':'de']+array_intersect_key($_GET,array_flip(['account','category','signature']))))?>"><?=locale()==='de'?'EN':'DE'?></a></header>
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
<?php if(isset($_SESSION['flash'])):$f=$_SESSION['flash'];unset($_SESSION['flash']);?><div class="flash <?=e($f['kind'])?>" role="status"><?=e($f['message'])?></div><?php endif ?>
<?=$content?>
<?php if(!$public): ?>
<?php /* On every page, because the page something goes wrong on is the page you
         are looking at, and "email the administrator" needs a working email
         address and the presence of mind to describe where you were. */ ?>
<details class="feedback">
    <summary><?=icon('lock')?><span><?=e(t('Etwas funktioniert hier nicht','Something is wrong on this page'))?></span></summary>
    <p class="muted"><?=e(t('Beschreibe kurz, was du erwartet hast und was passiert ist. Welche Seite du gerade ansiehst, welches Gerät du benutzt und welche Version das Portal hat, wird automatisch mitgeschickt – das musst du nicht wissen.','Say briefly what you expected and what happened. Which page you are on, what device you are using and which version the portal is are sent automatically – you do not have to know any of that.'))?></p>
    <?php start_form('feedback_send',['page'=>$page],'form',true);
    input('message',t('Was ist passiert?','What happened?'),'','textarea',true);
    file_field('screenshot',t('Bildschirmfoto (optional)','Screenshot (optional)'),'avatar',
               t('Falls du eines gemacht hast.','If you took one.'));
    submit_button(t('Absenden','Send'),'secondary');?></form>
</details>
<?php endif ?>
</main>
<?php if(!$public): ?>
</div>
<nav class="mobile-nav" aria-label="<?=e(t('Mobilmenü','Mobile menu'))?>">
<?php foreach(['dashboard'=>['home',t('Übersicht','Overview')],'students'=>['users',t('Schüler','Students')],'messages'=>['mail',t('Post','Messages')],'news'=>['news',t('Neues','News')]] as $r=>[$i,$l]):?><a href="<?=e(url($r))?>" <?=$page===$r||($r==='students'&&$page==='student')?'aria-current="page"':''?>><?=icon($i)?><span><?=e($l)?></span><?php if($r==='messages'&&$unreadTotal):?><span class="count" aria-label="<?=e($unreadTotal.' '.t('ungelesen','unread'))?>"><?=$unreadTotal?></span><?php endif ?></a><?php endforeach ?>
<button type="button" id="menu-toggle" aria-controls="sidebar" aria-expanded="false"><?=icon('more')?><span><?=e(t('Mehr','More'))?></span></button>
</nav>
<button type="button" class="menu-backdrop" id="menu-backdrop" aria-label="<?=e(t('Menü schließen','Close menu'))?>" hidden></button>
<?php else: ?><footer class="public-footer"><a href="<?=e(url('privacy'))?>"><?=e(t('Datenschutzerklärung','Privacy notice'))?></a><span>v<?=e(app_version())?></span></footer><?php endif ?>
</body>
</html>
