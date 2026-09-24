<?php
declare(strict_types=1);

function current_user(bool $reload=false): ?array {
    static $resolved=false, $cached=null;
    if($reload) { $resolved=false; $cached=null; }
    if($resolved) return $cached;
    $resolved=true;
    if(empty($_SESSION['user_id'])) return $cached=null;
    $a=one('SELECT * FROM accounts WHERE id=?',[(int)$_SESSION['user_id']]);
    $expired=time()-(int)($_SESSION['last_seen']??0)>(int)config('session_idle_minutes')*60;
    if(!$a || $a['state']!=='active' || !$a['verified_at'] || (int)$a['auth_version']!==(int)($_SESSION['auth_version']??0) || $expired) {
        unset($_SESSION['user_id'],$_SESSION['auth_version']); return $cached=null;
    }
    $_SESSION['last_seen']=time(); return $cached=$a;
}
// Staff = admin or trainer. "manager" is the pre-0.2 name for trainer; it is
// accepted on read so a half-applied migration cannot lock anyone out.
function is_staff(?array $a=null): bool { $a??=current_user(); return $a && in_array($a['role'],['admin','trainer','manager'],true); }
function is_admin(?array $a=null): bool { $a??=current_user(); return $a && $a['role']==='admin'; }
function role_label(string $role): string {
    return match($role) {
        'admin'   => t('Administrator','Administrator'),
        'trainer','manager' => t('Trainerin','Trainer'),
        default   => t('Schülerkonto','Student account'),
    };
}
/** Roles that may be granted, and who may grant them. Admin only for the first two. */
function assignable_roles(array $actor): array {
    return is_admin($actor) ? ['student','trainer','admin'] : ['student'];
}
/** Record activity for the online indicator, at most once a minute per account. */
function touch_last_seen(array $user): void {
    if(time()-(int)($_SESSION['seen_written']??0) < 60) return;
    $_SESSION['seen_written']=time();
    // Written on the counter connection: an action that rolls back should not
    // also undo the fact that the person was here.
    run_counter('UPDATE accounts SET last_seen_at=? WHERE id=?',[now(),$user['id']]);
}
function is_online(?string $lastSeen): bool {
    if($lastSeen===null || $lastSeen==='') return false;
    return (strtotime($lastSeen.' UTC') ?: 0) > time()-((int)setting('online_window_minutes'))*60;
}
function require_user(): array { $a=current_user(); if(!$a) go('login'); return $a; }
function require_staff(): array { $a=require_user(); if(!is_staff($a)) throw new UserError(t('Kein Zugriff.','Access denied.')); return $a; }
function require_admin(): array { $a=require_user(); if($a['role']!=='admin') throw new UserError(t('Nur für Administratoren.','Administrators only.')); return $a; }
function sign_in(array $a): void { session_regenerate_id(true); $_SESSION['user_id']=(int)$a['id']; $_SESSION['auth_version']=(int)$a['auth_version']; $_SESSION['last_seen']=time(); $_SESSION['locale']=$a['locale']; $_SESSION['csrf']=bin2hex(random_bytes(32)); current_user(true); }
function strong_password(string $p): string {
    if(strlen($p)<12 || strlen($p)>72) throw new UserError(t('Das Passwort muss 12 bis 72 Byte lang sein. Umlaute zählen doppelt.','The password must be 12 to 72 bytes long. Accented characters count double.'));
    // A 12-character minimum alone still admits these; they are the passwords an
    // attacker tries first, so reject them by name.
    $weak=['passwordpassword','password1234','123456789012','1234567890123','qwertzuiopas','qwertyuiopas','administrator','badmintonbadminton','badminton123','letmeinletmein','iloveyouiloveyou','willkommen12','passwort1234','geheimgeheim'];
    $normalised=preg_replace('/[^a-z0-9]/','',mb_strtolower($p));
    if(in_array($normalised,$weak,true) || preg_match('/^(.{1,4})\\1+$/D',$normalised??'')) throw new UserError(t('Dieses Passwort ist zu leicht zu erraten. Bitte ein anderes wählen.','This password is too easy to guess. Please choose another one.'));
    return $p;
}
/**
 * The account already using this address, held for the rest of the transaction.
 *
 * Every handler that creates an account asks this first, and the answer is only
 * worth having if nothing can put a row in behind it: two people inviting the
 * same parent in the same moment both look, both find nothing and both write,
 * and the second one meets the UNIQUE index instead of the sentence that names
 * the address. FOR UPDATE holds the address - and, on an address that does not
 * exist yet, the gap the other insert would need - until this transaction ends.
 *
 * Outside a transaction it would hold nothing at all, so it refuses rather than
 * handing back a row the caller believes is safe. Same reasoning as lock_row().
 */
function account_using_email(string $email): ?array {
    if(tx_depth()===0) throw new RuntimeException('account_using_email() outside a transaction holds nothing.');
    return one('SELECT * FROM accounts WHERE email=? FOR UPDATE',[$email]);
}
/**
 * Create the first administrator.
 *
 * There is no web signup: this account is provisioned by whoever set the server
 * up and is trusted from the start, while every invitation it later sends is
 * confirmed by email.
 *
 * The guard is a locking read inside the transaction that writes the row, so a
 * second tab on the setup page waits for the first one to commit and then finds
 * the administrator it made. COUNT(*) was there before and promised the same
 * thing without doing it: a plain read takes no locks, so both tabs read zero
 * and both wrote. Holding every row the scan touches is free here - it runs
 * once, against a table with nothing in it yet.
 *
 * $force exists for the console, where deliberately adding another
 * administrator is sometimes the only way back into a portal.
 */
function create_admin_account(string $name,string $email,string $password,bool $force=false): int {
    $name=trim($name);
    if($name===''||mb_strlen($name)>160) throw new UserError(t('Bitte einen Namen eingeben (höchstens 160 Zeichen).','Please enter a name of at most 160 characters.'));
    $email=email_value($email); strong_password($password);
    return transactional(function() use ($name,$email,$password,$force): int {
        if(!$force && rows("SELECT id FROM accounts WHERE role='admin' FOR UPDATE"))
            throw new UserError(t('Es gibt bereits einen Administrator. Weitere Konten werden im Portal unter „Konten“ eingeladen.','An administrator already exists. Invite further accounts under “Konten” in the portal.'));
        run("INSERT INTO accounts (name,email,password_hash,role,state,verified_at,created_at) VALUES (?,?,?,'admin','active',?,?)",
            [$name,$email,password_hash($password,PASSWORD_DEFAULT),now(),now()]);
        return (int)db()->lastInsertId();
    });
}
/**
 * The counter a throttle counts into.
 *
 * Derived in one place because throttle() and throttle_clear() have to agree:
 * a reset that spelled the key even slightly differently would clear nothing,
 * silently, and the family it was meant to let back in would stay locked out.
 */
function rate_limit_bucket(string $name,string $identity): string { return hash('sha256',$name.'|'.$identity); }
function throttle(string $name,string $identity,int $limit,int $seconds=900): void {
    $key=rate_limit_bucket($name,$identity); $time=time();
    run_counter('INSERT INTO rate_limits (bucket,hits,window_start) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=IF(window_start < ?,1,hits+1),window_start=IF(window_start < ?,?,window_start)',[$key,$time,$time-$seconds,$time-$seconds,$time]);
    if((int)run_counter('SELECT hits FROM rate_limits WHERE bucket=?',[$key])->fetchColumn()>$limit) throw new UserError(t('Zu viele Versuche. Bitte später erneut versuchen.','Too many attempts. Please try again later.'));
}
/**
 * Forget the attempts counted against one bucket.
 *
 * Written on the counter connection, like the count itself, so no rollback can
 * put the attempts back. That connection cannot write while an action's
 * transaction is open, which is why a caller has to clear once its action has
 * committed rather than from inside it.
 *
 * Which buckets are worth clearing, and which must never be, is a question
 * about what a request has proved rather than about counters: it is answered
 * once, in forget_attempts_after_success() in app/actions.php.
 */
function throttle_clear(string $name,string $identity): void {
    run_counter('DELETE FROM rate_limits WHERE bucket=?',[rate_limit_bucket($name,$identity)]);
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
function unsubscribe_categories(): array { return ['newsletter'=>'newsletter','notifications'=>'notifications','payments'=>'payment_notices']; }
function valid_unsubscribe(int $id,string $category,string $signature): bool { return isset(unsubscribe_categories()[$category]) && hash_equals(unsubscribe_signature($id,$category),$signature); }
function record_consent(int $id,string $purpose,bool $enabled): void { run('INSERT INTO consent_log (account_id,purpose,enabled,notice_version,created_at) VALUES (?,?,?,?,?)',[$id,$purpose,$enabled?1:0,notice_version(),now()]); }
