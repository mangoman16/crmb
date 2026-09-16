<!doctype html>
<?php [$theme,$textScale]=appearance($user); ?>
<html lang="<?=e(locale())?>"<?=$theme!=='auto'?' data-theme="'.e($theme).'"':''?><?=$textScale!=='normal'?' data-text="'.e($textScale).'"':''?>>
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
$nav=['dashboard'=>['home',t('Übersicht','Overview')],'students'=>['users',t('Schüler','Students')]];
$waitingRequests=is_staff($user)?pending_request_count():0;
if(is_staff($user)){$nav['classes']=['calendar',t('Kurse','Courses')];$nav['attendance']=['check',t('Anwesenheit','Attendance')];$nav['payments']=['wallet',t('Beiträge','Payments')];$nav['invoices']=['news',t('Rechnungen','Invoices')];}
$nav['messages']=['mail',t('Nachrichten','Messages')];$nav['news']=['news',t('Neuigkeiten','News')];
$unreadTotal=unread_count($user);
if(is_staff($user)){$nav['manage']=['settings',t('Verwaltung','Management')];$nav['accounts']=['lock',t('Konten','Accounts')];$nav['outbox']=['mail',t('Postausgang','Outbox')];}
if(is_admin($user)){$nav['history']=['calendar',t('Änderungen','Changes')];$nav['settings']=['settings',t('Einstellungen','Settings')];}
?>
<aside class="sidebar" id="sidebar">
    <a class="brand" href="<?=e(url('dashboard'))?>"><span class="brand-mark">B<span></span></span><span><?=e(setting('club_name','Badminton'))?><small><?=e(is_staff($user)?t('Verwaltung','Management'):t('Mein Portal','My portal'))?></small></span></a>
    <nav aria-label="<?=e(t('Hauptmenü','Main menu'))?>">
    <?php foreach($nav as $route=>[$symbol,$label]):$active=$route===$page || ($route==='students'&&$page==='student') || ($route==='messages'&&$page==='compose'); ?>
        <a href="<?=e(url($route))?>" <?=$active?'aria-current="page"':''?>><?=icon($symbol)?><span><?=e($label)?></span><?php if($route==='messages'&&$unreadTotal):?><span class="count" aria-label="<?=e($unreadTotal.' '.t('ungelesen','unread'))?>"><?=$unreadTotal?></span><?php endif ?><?php if($route==='classes'&&$waitingRequests):?><span class="count" aria-label="<?=e($waitingRequests.' '.t('Anfragen','requests'))?>"><?=e($waitingRequests)?></span><?php endif ?></a>
    <?php endforeach ?>
    </nav>
    <div class="sidebar-bottom"><a class="account-link" href="<?=e(url('profile'))?>"><span class="avatar small"><?=e(mb_substr($user['name'],0,1))?></span><span><?=e($user['name'])?><small><?=e(role_label($user['role']))?></small></span></a><div class="sidebar-meta"><a href="<?=e(url('privacy'))?>"><?=e(t('Datenschutz','Privacy'))?></a><span>v<?=e(app_version())?></span></div></div>
</aside>
<div class="app-shell">
<header class="topbar"><span class="topbar-context"><?=e((string)setting('portal_tagline'))?></span><a class="mobile-brand" href="<?=e(url('dashboard'))?>"><?=e(setting('club_name','Badminton'))?></a><div class="topbar-actions"><a class="language" href="<?=e(url($page,['lang'=>locale()==='de'?'en':'de']+array_intersect_key($_GET,array_flip(['id','tab']))))?>"><?=locale()==='de'?'EN':'DE'?></a><a href="<?=e(url('profile'))?>" aria-label="<?=e(t('Mein Konto','My account'))?>"><span class="avatar tiny"><?=e(mb_substr($user['name'],0,1))?></span></a></div></header>
<?php else: ?>
<header class="public-header"><a class="brand" href="<?=e(url($user?'dashboard':'login'))?>"><span class="brand-mark">B<span></span></span><?=e(setting('club_name','Badminton'))?></a><a class="language" href="<?=e(url($page,['lang'=>locale()==='de'?'en':'de']+array_intersect_key($_GET,array_flip(['account','category','signature']))))?>"><?=locale()==='de'?'EN':'DE'?></a></header>
<?php endif ?>
<main id="main" class="<?=$public?'public-main':'main-content'?>">
<?php if(!$public && is_admin($user) && is_file(maintenance_file())): ?>
<div class="flash error" role="status"><?=e(t('Wartungsmodus ist aktiv – für alle anderen ist das Portal geschlossen.','Maintenance mode is on – the portal is closed for everyone else.'))?> <a href="<?=e(url('settings',['tab'=>'system']))?>"><?=e(t('Beenden','Switch off'))?></a></div>
<?php endif ?>
<?php if(isset($_SESSION['flash'])):$f=$_SESSION['flash'];unset($_SESSION['flash']);?><div class="flash <?=e($f['kind'])?>" role="status"><?=e($f['message'])?></div><?php endif ?>
<?=$content?>
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
