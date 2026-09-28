<?php
declare(strict_types=1);

ini_set('display_errors','0');
try {
    require __DIR__.'/../app/bootstrap.php';
    boot_http();
    // A timeout or running out of memory ends the script where no catch can
    // see it. Registered before the background work below, so it looks at the
    // page's own fatal error rather than being queued behind that work (ADR 0012).
    register_shutdown_function(capture_fatal_error(...));
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
    $allowed=['dashboard','start','students','student','payments','classes','accounts','messages','compose','news','outbox','manage','invoices','attendance','download','settings','history','profile','print','login','forgot','activate','unsubscribe','privacy','icon','manifest'];
    if(!in_array($page,$allowed,true)) {http_response_code(404);$page='not_found';}
    // The trail a problem report carries. Here, before the POST branch, because
    // that branch redirects and never comes back: recorded any later, the trail
    // would have a hole exactly where a form was sent.
    record_step($page);
    // The way back to the start checklist, kept in the session for the same
    // reason: a save's redirect has to find it there (ADR 0011).
    note_setup_return($page);
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
            // A form sent twice is handled, not broken: the second copy is told
            // so. Anything else the database refused is written down for the
            // administrators (ADR 0012), and the person is told in a sentence.
            if($ex->getCode()==='23000' && str_contains($ex->getMessage(),'form_requests'))
                flash(t('Diese Eingabe wurde bereits verarbeitet.','This submission has already been processed.'),'error');
            else {
                capture_error($ex);
                flash($ex->getCode()==='23000'?t('Die Eingabe ist nicht möglich: Adresse bereits vergeben oder verknüpfte Daten vorhanden.','Cannot save: email already used or related records exist.'):t('Speichern fehlgeschlagen. Bitte erneut versuchen.','Could not save. Please try again.'),'error');
            }
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
    $public=in_array($page,['login','forgot','activate','unsubscribe','privacy','not_found','icon','manifest'],true);
    $user=$public?current_user():require_user();
    if($user)touch_last_seen($user);
    if(in_array($page,['accounts','payments','compose','outbox','classes','manage','invoices','attendance','print'],true))require_staff();
    // A family's list is their own student; an old bookmark to the students list
    // opens that page instead. Not a change of who may open it.
    if($page==='students' && ($instead=students_list_instead($user)))go($instead[0],$instead[1]);
    // A download is not a page: it answers with a file and leaves. Handled here
    // rather than in a view because a view is wrapped in the layout, and the one
    // thing a PDF must not have around it is HTML.
    if($page==='download')serve_download();
    // The icon and the manifest likewise, and public: the login page wears the
    // icon, and an install reads the manifest before anybody signs in.
    if($page==='icon')serve_portal_icon();
    if($page==='manifest')serve_web_manifest();
    if(in_array($page,['settings','history','start'],true))require_admin();
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
    // capture_error() logs the error, writes it down for the administrators and
    // cannot throw, so the friendly page below is sent whatever happens there.
    // This catch also sees a configuration that stopped app/bootstrap.php before
    // app/shell.php was loaded; then there is nothing to write with, only the log.
    if(ob_get_level())ob_end_clean();http_response_code(503);
    if(function_exists('capture_error'))capture_error($ex);else error_log('CRM: '.$ex->getMessage());
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Badminton</title><p>Die Anwendung ist vorübergehend nicht verfügbar. Bitte Installation und Serverprotokoll prüfen.</p><p>The application is temporarily unavailable. Please check installation and server logs.</p></html>';
}
