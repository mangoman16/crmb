<?php
declare(strict_types=1);

ini_set('display_errors','0');
try {
    require __DIR__.'/../app/bootstrap.php';
    boot_http();
    // Registered rather than called at the end of the file: most requests leave
    // through go(), which redirects and exits, and the mail queue has to be
    // worked on those too.
    register_shutdown_function(run_background_tasks(...));
    require ROOT.'/app/actions.php';
    require ROOT.'/app/actions_settings.php';
    require ROOT.'/app/actions_messages.php';
    require ROOT.'/app/actions_config.php';
    require ROOT.'/app/ui.php';
    $page=is_scalar($_GET['page']??'')?(string)($_GET['page']??'dashboard'):'dashboard';
    $allowed=['dashboard','students','student','payments','classes','accounts','messages','compose','news','outbox','manage','invoices','attendance','download','settings','history','profile','print','login','forgot','activate','unsubscribe','privacy'];
    if(!in_array($page,$allowed,true)) {http_response_code(404);$page='not_found';}
    if($_SERVER['REQUEST_METHOD']==='POST') {
        try {
            [$target,$params]=handle_post();
            if($target==='outbox' && isset($params['process'])) {
                // Leave a margin below max_execution_time so the response still renders.
                $limit=(int)ini_get('max_execution_time');
                $count=process_mail(25,$limit>0?max(5.0,$limit-8.0):45.0);
                $note=$count['sent'].' '.t('gesendet, ','sent, ').$count['failed'].' '.t('fehlgeschlagen.','failed.');
                if($count['deferred'])$note.=' '.$count['deferred'].' '.t('warten noch und werden automatisch weiter versendet.','still waiting; they will be sent automatically.');
                flash($note);$params=[];
            }
            go($target,$params);
        } catch(UserError $ex) {flash($ex->getMessage(),'error');remember_input(post('action'));}
        catch(PDOException $ex) {
            remember_input(post('action'));
            error_log('CRM database action: '.$ex->getCode());
            if($ex->getCode()==='23000' && str_contains($ex->getMessage(),'form_requests'))
                flash(t('Diese Eingabe wurde bereits verarbeitet.','This submission has already been processed.'),'error');
            else flash($ex->getCode()==='23000'?t('Die Eingabe ist nicht möglich: Adresse bereits vergeben oder verknüpfte Daten vorhanden.','Cannot save: email already used or related records exist.'):t('Speichern fehlgeschlagen. Bitte erneut versuchen.','Could not save. Please try again.'),'error');
        }
        $fallback=post('return_page',current_user()?'dashboard':'login');
        if(!in_array($fallback,$allowed,true))$fallback='dashboard';
        $params=[];if(post('return_id')!=='')$params['id']=(int)post('return_id');if(post('return_tab')!=='')$params['tab']=post('return_tab');
        go($fallback,$params);
    }
    if($page==='activate' && isset($_GET['token'])) {
        throttle('token-view',$_SERVER['REMOTE_ADDR']??'local',60);
        $token=is_scalar($_GET['token'])?(string)$_GET['token']:'';
        $_SESSION['activation_hash']=preg_match('/^[a-f0-9]{64}$/D',$token)?hash('sha256',$token):'';
        go('activate');
    }
    $public=in_array($page,['login','forgot','activate','unsubscribe','privacy','not_found'],true);
    $user=$public?current_user():require_user();
    if($user)touch_last_seen($user);
    if(in_array($page,['accounts','payments','compose','outbox','classes','manage','invoices','attendance','print'],true))require_staff();
    // A download is not a page: it answers with a file and leaves. Handled here
    // rather than in a view because a view is wrapped in the layout, and the one
    // thing a PDF must not have around it is HTML.
    if($page==='download')serve_download();
    if(in_array($page,['settings','history'],true))require_admin();
    // Read once, like the flash message: a submission that was rejected is offered
    // back to the form that follows and then forgotten, so it cannot reappear on a
    // page she opens tomorrow.
    take_held_input();
    ob_start();
    if($page==='not_found')echo '<h1>404</h1><p>'.e(t('Seite nicht gefunden.','Page not found.')).'</p>';
    else require ROOT.'/views/'.$page.'.php';
    $content=ob_get_clean();
    require ROOT.'/views/layout.php';
} catch(UserError $ex) {
    // A record that is gone is not a refusal. "Kein Zugriff" over "Kurs nicht
    // gefunden" tells the trainer she is not allowed to see her own course,
    // when what happened is that she followed a link to something deleted.
    $missing=$ex instanceof NotFound;
    if(ob_get_level())ob_end_clean();http_response_code($missing?404:403);
    $content='<div class="card"><h1>'.e($missing?t('Nicht gefunden','Not found'):t('Kein Zugriff','Access denied')).'</h1><p>'.e($ex->getMessage()).'</p>'
        .($missing?'<p class="muted">'.e(t('Vielleicht wurde der Eintrag gelöscht, oder der Link ist alt.','It may have been deleted, or the link may be an old one.')).'</p>':'')
        .'<a class="button" href="'.e(url('dashboard')).'">'.e(t('Zur Übersicht','Back to overview')).'</a></div>';
    $page='error';$public=true;$user=null;require ROOT.'/views/layout.php';
} catch(Throwable $ex) {
    if(ob_get_level())ob_end_clean();http_response_code(503);error_log('CRM: '.$ex->getMessage());header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Badminton</title><p>Die Anwendung ist vorübergehend nicht verfügbar. Bitte Installation und Serverprotokoll prüfen.</p><p>The application is temporarily unavailable. Please check installation and server logs.</p></html>';
}
