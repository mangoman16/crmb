<?php
declare(strict_types=1);

function current_user(): ?array {
    if(empty($_SESSION['user_id'])) return null;
    $a=one('SELECT * FROM accounts WHERE id=?',[(int)$_SESSION['user_id']]);
    $expired=time()-(int)($_SESSION['last_seen']??0)>(int)config('session_idle_minutes')*60;
    if(!$a || $a['state']!=='active' || !$a['verified_at'] || (int)$a['auth_version']!==(int)($_SESSION['auth_version']??0) || $expired) {
        unset($_SESSION['user_id'],$_SESSION['auth_version']); return null;
    }
    $_SESSION['last_seen']=time(); return $a;
}
function is_staff(?array $a=null): bool { $a??=current_user(); return $a && in_array($a['role'],['admin','manager'],true); }
function require_user(): array { $a=current_user(); if(!$a) go('login'); return $a; }
function require_staff(): array { $a=require_user(); if(!is_staff($a)) throw new UserError(t('Kein Zugriff.','Access denied.')); return $a; }
function require_admin(): array { $a=require_user(); if($a['role']!=='admin') throw new UserError(t('Nur für Administratoren.','Administrators only.')); return $a; }
function sign_in(array $a): void { session_regenerate_id(true); $_SESSION['user_id']=(int)$a['id']; $_SESSION['auth_version']=(int)$a['auth_version']; $_SESSION['last_seen']=time(); $_SESSION['locale']=$a['locale']; $_SESSION['csrf']=bin2hex(random_bytes(32)); }
function strong_password(string $p): string {
    if(strlen($p)<12 || strlen($p)>72) throw new UserError(t('Das Passwort muss 12 bis 72 Byte lang sein. Umlaute zählen doppelt.','The password must be 12 to 72 bytes long. Accented characters count double.'));
    // A 12-character minimum alone still admits these; they are the passwords an
    // attacker tries first, so reject them by name.
    $weak=['passwordpassword','password1234','123456789012','1234567890123','qwertzuiopas','qwertyuiopas','administrator','badmintonbadminton','badminton123','letmeinletmein','iloveyouiloveyou','willkommen12','passwort1234','geheimgeheim'];
    $normalised=preg_replace('/[^a-z0-9]/','',mb_strtolower($p));
    if(in_array($normalised,$weak,true) || preg_match('/^(.{1,4})\\1+$/D',$normalised??'')) throw new UserError(t('Dieses Passwort ist zu leicht zu erraten. Bitte ein anderes wählen.','This password is too easy to guess. Please choose another one.'));
    return $p;
}
function throttle(string $name,string $identity,int $limit,int $seconds=900): void {
    $key=hash('sha256',$name.'|'.$identity); $time=time();
    run_counter('INSERT INTO rate_limits (bucket,hits,window_start) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=IF(window_start < ?,1,hits+1),window_start=IF(window_start < ?,?,window_start)',[$key,$time,$time-$seconds,$time-$seconds,$time]);
    if((int)run_counter('SELECT hits FROM rate_limits WHERE bucket=?',[$key])->fetchColumn()>$limit) throw new UserError(t('Zu viele Versuche. Bitte später erneut versuchen.','Too many attempts. Please try again later.'));
}
function make_token(int $accountId,string $purpose,?string $email=null): string {
    run('DELETE FROM auth_tokens WHERE account_id=? AND purpose=?',[$accountId,$purpose]);
    $token=bin2hex(random_bytes(32));
    run('INSERT INTO auth_tokens (account_id,token_hash,purpose,target_email,expires_at,created_at) VALUES (?,?,?,?,?,?)',[$accountId,hash('sha256',$token),$purpose,$email,gmdate('Y-m-d H:i:s',time()+($purpose==='invite'?172800:3600)),now()]);
    return $token;
}
function token_record(string $hash,bool $lock=false): ?array {
    return one('SELECT t.*,a.state,a.email,a.role,a.name FROM auth_tokens t JOIN accounts a ON a.id=t.account_id WHERE t.token_hash=? AND t.expires_at>?'.($lock?' FOR UPDATE':''),[$hash,now()]);
}
function send_account_token(array $account,string $purpose,?string $email=null): void {
    if(!setting('smtp',[]) || !setting('privacy_ready',false)) throw new UserError(t('Bitte zuerst SMTP und Datenschutzerklärung einrichten.','Set up SMTP and the privacy notice first.'));
    $token=make_token((int)$account['id'],$purpose,$email);
    $en=$account['locale']==='en';
    $subjects=['invite'=>$en?'Your badminton invitation':'Deine Badminton-Einladung','reset'=>$en?'Reset your password':'Passwort zurücksetzen','email'=>$en?'Verify your email address':'E-Mail-Adresse bestätigen'];
    $body=($en?'Hello ':'Hallo ').$account['name'].",\n\n".($en?'Open this link to continue:':'Öffne diesen Link, um fortzufahren:')."\n".url('activate',['token'=>$token])."\n\n".($purpose==='invite'?($en?'Valid for 48 hours.':'48 Stunden gültig.'):($en?'Valid for one hour.':'Eine Stunde gültig.'))."\n\n".($en?'If you did not expect this email, you can ignore it.':'Falls du diese E-Mail nicht erwartet hast, kannst du sie ignorieren.');
    queue_mail((int)$account['id'],$email??$account['email'],$subjects[$purpose],$body,'security');
}
function unsubscribe_signature(int $id,string $category): string { return hash_hmac('sha256',$id.'|'.$category,base64_decode(config('app_key'))); }
function valid_unsubscribe(int $id,string $category,string $signature): bool { return in_array($category,['newsletter','notifications'],true) && hash_equals(unsubscribe_signature($id,$category),$signature); }
function record_consent(int $id,string $purpose,bool $enabled): void { run('INSERT INTO consent_log (account_id,purpose,enabled,notice_version,created_at) VALUES (?,?,?,?,?)',[$id,$purpose,$enabled?1:0,notice_version(),now()]); }
