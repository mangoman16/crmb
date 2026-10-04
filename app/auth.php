<?php
declare(strict_types=1);

function current_user(bool $reload=false): ?array {
    static $resolved=false, $cached=null;
    if($reload) { $resolved=false; $cached=null; }
    if($resolved) return $cached;
    $resolved=true;
    // Nobody signed in is nobody viewing as anybody either: an id left over
    // from a session that ended is forgotten here, wherever it is first asked
    // (security review F1).
    if(empty($_SESSION['user_id'])) { unset($_SESSION['impersonator_id']); return $cached=null; }
    $a=one('SELECT * FROM accounts WHERE id=?',[(int)$_SESSION['user_id']]);
    $expired=time()-(int)($_SESSION['last_seen']??0)>(int)config('session_idle_minutes')*60;
    if(!$a || $a['state']!=='active' || !$a['verified_at'] || (int)$a['auth_version']!==(int)($_SESSION['auth_version']??0) || $expired) {
        // A view through somebody's eyes ends with the session it was opened
        // in. Left behind, it handed whoever signed in next on that browser
        // „Ansicht beenden" - and the staff member's account (security review F1).
        unset($_SESSION['user_id'],$_SESSION['auth_version'],$_SESSION['impersonator_id']); return $cached=null;
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
/**
 * Roles that may be granted from the Konten page, and who may grant them.
 *
 * Staff only, and only by an administrator. A student's account is made on
 * that student's own page by student_invite, because it belongs to exactly one
 * student (ADR 0010): made anywhere else it would be a login attached to nobody.
 */
function assignable_roles(array $actor): array {
    return is_admin($actor) ? ['trainer','admin'] : [];
}
/**
 * Whether one person may see another account's profile picture.
 *
 * Staff see everybody's, everybody sees their own, and everybody sees the
 * trainers' and administrators' - the people a family writes to. A family
 * never sees another family's, not even one they have agreed to write with:
 * the name is shown, the photograph stays theirs. The download route refuses
 * by this rule and avatar() draws initials by it, so a page never links to a
 * picture the route would refuse. An account row without its role is not
 * taken for staff.
 */
function may_see_account_picture(array $viewer, array $account): bool {
    return is_staff($viewer)
        || (int)$viewer['id'] === (int)($account['id'] ?? 0)
        || (isset($account['role']) && is_staff($account));
}
function require_user(): array { $a=current_user(); if(!$a) go('login'); return $a; }
function require_staff(): array { $a=require_user(); if(!is_staff($a)) throw new UserError(t('Kein Zugriff.','Access denied.')); return $a; }
function require_admin(): array { $a=require_user(); if($a['role']!=='admin') throw new UserError(t('Nur für Administratoren.','Administrators only.')); return $a; }
/** A new session for $a, and nothing of the one before it: no view through somebody else's eyes (F1). */
function sign_in(array $a): void { session_regenerate_id(true); unset($_SESSION['impersonator_id']); $_SESSION['user_id']=(int)$a['id']; $_SESSION['auth_version']=(int)$a['auth_version']; $_SESSION['last_seen']=time(); $_SESSION['locale']=$a['locale']; $_SESSION['csrf']=bin2hex(random_bytes(32)); current_user(true); }
function strong_password(string $p): string {
    if(strlen($p)<12 || strlen($p)>72) throw new UserError(t('Das Passwort muss 12 bis 72 Byte lang sein. Umlaute zählen doppelt.','The password must be 12 to 72 bytes long. Accented characters count double.'));
    // A 12-character minimum alone still admits these; they are the passwords an
    // attacker tries first, so reject them by name.
    $weak=['passwordpassword','password1234','123456789012','1234567890123','qwertzuiopas','qwertyuiopas','administrator','badmintonbadminton','badminton123','letmeinletmein','iloveyouiloveyou','willkommen12','passwort1234','geheimgeheim'];
    $normalised=preg_replace('/[^a-z0-9]/','',mb_strtolower($p));
    if(in_array($normalised,$weak,true) || preg_match('/^(.{1,4})\\1+$/D',$normalised??'')) throw new UserError(t('Dieses Passwort ist zu leicht zu erraten. Bitte ein anderes wählen.','This password is too easy to guess. Please choose another one.'));
    return $p;
}
/*
 * Making a login (ADR 0021, §5). Every block that inserts into accounts asks
 * refuse_address_in_use() inside its transaction and before its INSERT - one
 * person, one login, one address of their own.
 *
 * It holds what it reads with FOR UPDATE, and relies on InnoDB's default
 * isolation, REPEATABLE READ, for it: there a locking read also locks the gap
 * where a missing row would go, so a second request making a login at this
 * address in the same moment waits for this one to commit and then sees its
 * row. Under READ COMMITTED no gap is locked and both would write. The portal
 * never changes the isolation level; nothing may start to.
 *
 * Outside a transaction a locking read holds nothing at all, so it refuses
 * rather than answers. Same reasoning as lock_row().
 */

/**
 * Refuse an address that is already another login's (ADR 0020, §1). The only
 * refusal of an address there is: every block that makes a login and every
 * re-addressing (change_account_email()) asks it, before it writes.
 *
 * The unique index on accounts.email would refuse too, under any isolation
 * level, but with a 23000 nobody can act on; this is the sentence a person
 * reads (ADR 0010). Staff are told whose login it is, because they can do
 * something about it; a family is never told a name. $exceptId is the login
 * being re-addressed, which is never in its own way.
 *
 * Relies on REPEATABLE READ for the lock to cover an address nobody has yet
 * (above).
 */
function refuse_address_in_use(string $email, ?int $exceptId = null): void {
    if(tx_depth()===0) throw new RuntimeException('refuse_address_in_use() outside a transaction holds nothing.');
    $holder=one('SELECT id,role,name,email FROM accounts WHERE email=? AND id<>? FOR UPDATE',[$email,$exceptId??0]);
    if(!$holder) return;
    // An invitation nobody has taken up yet is the same person invited twice,
    // not somebody else's login, so staff are told where to find it (spec S1).
    if(is_staff() && is_open_invitation((int)$holder['id']))
        throw new UserError(t('An diese Adresse ist schon eine Einladung unterwegs. Du findest sie unter „Offene Einladungen“.',
                              'An invitation to this address is already on its way. It is under “Open invitations”.'));
    throw new UserError(t('Diese E-Mail-Adresse gehört schon zu einem anderen Zugang. Jede Person braucht ihre eigene.',
                          'This email address already belongs to another login. Everybody needs their own.')
        .(is_staff() ? t(' Es ist der Zugang von ',' It is the login of ').login_holder_name($holder).'.' : ''));
}

/**
 * Who a login belongs to, by name: the student's own name for a student's login,
 * the account's name otherwise - "Familie Hofer" on an old login says less than
 * "Lena Hofer" does - and the address while there is no name at all: an
 * invitation by address has none until its holder types one (ADR 0021, §3).
 * $student is the login's student row when the caller has already read it;
 * otherwise it is looked up.
 */
function login_holder_name(array $account, ?array $student = null): string {
    $name=(string)($account['name']??'');
    if(($account['role']??'')==='student') {
        $student??=isset($account['id']) ? one('SELECT first_name,last_name FROM students WHERE account_id=?',[(int)$account['id']]) : null;
        if($student) $name=trim($student['first_name'].' '.$student['last_name']);
    }
    return $name!=='' ? $name : (string)($account['email']??'');
}

/**
 * How long a password set anew through a mailed link - „vergessen", or the link
 * staff asked for (reset_link) - is listed for its holder on Mein Konto and on
 * the access card (ADR 0019, I1; 0020 §8). One number, so the two cannot tell a
 * family two different things.
 */
const PASSWORD_RESET_SHOWN_DAYS = 14;

/**
 * The passwords set on this login through a mailed link in the last $days days,
 * newest first, as their audit entries [S4]. Every caller passes
 * PASSWORD_RESET_SHOWN_DAYS. Whoever reads the mailbox can set a password; the
 * holder cannot be protected from that, but can find out.
 */
function password_resets_for(int $accountId, int $days): array {
    return rows("SELECT id,created_at FROM audit_log WHERE action='account.password_reset' AND entity_type='account' AND entity_id=? AND created_at>=? ORDER BY id DESC",
        [$accountId,gmdate('Y-m-d H:i:s',time()-$days*86400)]);
}

/**
 * The hash a sign-in checks the password against when there is no real one to
 * check [M2]: no such address, a value that cannot be one, a row
 * that matched only through the collation, an invitation not yet accepted.
 * Every refusal then costs one password_verify() at the cost PASSWORD_DEFAULT
 * has today, so the time an answer takes does not say whether the login exists.
 *
 * Made and kept current by refresh_sign_in_dummy_hash(), from the migration
 * runner and the nightly prune - never by a sign-in, because hashing is slower
 * than verifying and a request that hashed would be the oracle this closes. The
 * one exception: a database restored without its settings has none, and then
 * the first request to need it makes it once, stores it and says so in the log.
 * handle_post() asks for it before the action's transaction opens, so a refused
 * sign-in cannot roll that repair back and make the next request repeat it.
 */
function sign_in_dummy_hash(): string {
    $hash=(string)setting('sign_in_dummy_hash');
    if($hash!=='') return $hash;
    $hash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
    set_setting('sign_in_dummy_hash',$hash);
    error_log('CRM: the sign-in comparison hash (setting sign_in_dummy_hash) was missing and has been made by a request.');
    return $hash;
}

/**
 * Make the sign-in comparison hash, or make it again when PHP's default cost has
 * moved on since. Returns whether it wrote. For the runner and the nightly prune.
 */
function refresh_sign_in_dummy_hash(): bool {
    $hash=(string)setting('sign_in_dummy_hash');
    if($hash!=='' && !password_needs_rehash($hash,PASSWORD_DEFAULT)) return false;
    set_setting('sign_in_dummy_hash',password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT));
    return true;
}

/**
 * The login a sign-in or „vergessen" address names, or null (ADR 0021, §1).
 *
 * $address is what was typed, already normalised (attempted_address()). In this
 * order, and each step is a reason this is not an oracle:
 *
 * 1. An address that is no dot-atom is never looked up [M1]. Nothing outside
 *    atext reaches the collation.
 * 2. One literal statement; the column is never interpolated. A plain read for
 *    a sign-in: under REPEATABLE READ, FOR UPDATE takes a record lock on a login
 *    that exists and only a gap lock on one that does not, so concurrent
 *    sign-ins queued only for real logins, and the wait said which exist
 *    (security review F2). The sign-in's one write, the rehash, is conditional
 *    on the hash it verified instead. „Vergessen" passes $lock, and ' FOR
 *    UPDATE' is appended as a literal: two requests at once must not leave two
 *    live links.
 * 3. A row counts only if it is exactly what was typed, compared in PHP after
 *    normalising both sides. utf8mb4_unicode_ci folds accents, ß against ss and
 *    full-width letters (ADR 0007); a spelling that reaches a row only through
 *    that fold finds nothing here. A legacy address stored with capitals still
 *    matches, because email_normalised() lower-cases both sides.
 *
 * The caller has already counted the attempt under the typed address
 * (address_identity()); nothing here decides what is counted.
 */
function account_for_sign_in(string $address, bool $lock = false): ?array {
    if(!email_is_dot_atom($address)) return null;
    $a=one('SELECT * FROM accounts WHERE email=?'.($lock?' FOR UPDATE':''),[$address]);
    return $a && email_normalised((string)$a['email'])===$address ? $a : null;
}

/**
 * Create the first administrator, and return its id.
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
 * administrator is sometimes the only way back into a portal. It skips that
 * guard and no other: a forced administrator still needs an address no other
 * login uses (refuse_address_in_use(), ADR 0020 §1).
 */
function create_admin_account(string $name,string $email,string $password,bool $force=false): array {
    $name=trim($name);
    if($name===''||mb_strlen($name)>160) throw new UserError(t('Bitte einen Namen eingeben (höchstens 160 Zeichen).','Please enter a name of at most 160 characters.'));
    $email=email_value($email); strong_password($password);
    return transactional(function() use ($name,$email,$password,$force): array {
        if(!$force && rows("SELECT id FROM accounts WHERE role='admin' FOR UPDATE"))
            throw new UserError(t('Es gibt bereits einen Administrator. Weitere Konten werden im Portal unter „Konten“ eingeladen.','An administrator already exists. Invite further accounts under “Konten” in the portal.'));
        refuse_address_in_use($email);
        run("INSERT INTO accounts (name,email,password_hash,role,state,verified_at,created_at) VALUES (?,?,?,'admin','active',?,?)",
            [$name,$email,password_hash($password,PASSWORD_DEFAULT),now(),now()]);
        return ['id'=>(int)db()->lastInsertId()];
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
 * Count one request against several throttles, then refuse if any is over.
 *
 * Every one is counted rather than stopping at the first that is full, so a
 * request refused by one still counts against the others (ADR 0019, S7).
 */
function throttle_all(array $limits): void {
    $refused=null;
    foreach($limits as [$name,$identity,$limit,$seconds]) {
        try { throttle($name,$identity,$limit,$seconds); }
        catch(UserError $e) { $refused??=$e; }
    }
    if($refused) throw $refused;
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
/**
 * A live link and the login it belongs to. With the address, because the page a
 * link opens shows it beside the new password for the phone to save it under:
 * whoever holds the link reads that mailbox anyway. With the language, because
 * an invitation's page speaks the one it was sent in (public/index.php).
 */
function token_record(string $hash,bool $lock=false): ?array {
    return one('SELECT t.*,a.state,a.email,a.role,a.name,a.locale FROM auth_tokens t JOIN accounts a ON a.id=t.account_id WHERE t.token_hash=? AND t.expires_at>?'.($lock?' FOR UPDATE':''),[$hash,now()]);
}

/**
 * Let the page a link opens speak the language of the login it belongs to - an
 * invitation's is the one staff chose for it - unless this browser has already
 * chosen one, by ?lang= or by signing in (spec S1). German otherwise, as before.
 */
function adopt_link_language(?array $tokenRecord): void {
    if($tokenRecord && !isset($_SESSION['locale']) && in_array($tokenRecord['locale']??'',['de','en'],true))
        $_SESSION['locale']=$tokenRecord['locale'];
}

/**
 * Whether accepting this link also makes the person's student (ADR 0021, §3): an
 * invitation to a login nobody has set up and no student points to - one sent by
 * address alone. Decided from the stored link and login, never from what a page
 * posts, so the activation page and the activate action cannot disagree, and
 * neither can be talked into a second student.
 */
function setup_creates_student(array $tokenRecord): bool {
    return ($tokenRecord['purpose']??'')==='invite' && is_open_invitation((int)($tokenRecord['account_id']??0));
}
/**
 * Whether staff may have a link for a new password mailed to this login: only
 * to one in use, active and verified (ADR 0020, §5). An invitation is sent
 * again instead; a suspended login is restored first. One rule for the
 * account_state action and for the card that offers the button, so the card
 * never offers what the action refuses.
 */
function reset_link_possible(array $account): bool {
    return ($account['state'] ?? '')==='active' && !empty($account['verified_at']);
}
/**
 * When the newest invitation to a login was sent and when it stops working, as
 * created_at and expires_at - or null when there is none (ADR 0020, §10c).
 *
 * For the access card, so she can answer "the link doesn't work". Never the
 * token_hash: a view reads the dates through this and does not query
 * auth_tokens itself. Expired tokens are deleted every night (prune_expired()),
 * so an expired invitation usually has no row at all, and the card says so
 * without a date rather than guessing one from accounts.created_at, which a
 * later re-invitation would make wrong.
 */
function invitation_dates(int $accountId): ?array {
    return one("SELECT created_at,expires_at FROM auth_tokens WHERE account_id=? AND purpose='invite' ORDER BY id DESC LIMIT 1",[$accountId]);
}

/** Whether the link invitation_dates() describes still works: there is one, and it has not run out. */
function invitation_link_live(?array $dates): bool {
    return $dates!==null && (string)$dates['expires_at']>now();
}
/**
 * Whether a link to sign in can be sent at all: mail has been tested and works
 * (smtp_tested_ok()), and there is a released privacy notice for the person to
 * read before they agree.
 *
 * Tested, not merely saved: an invitation queued behind a server that never
 * answered sits in the outbox unsent while the family waits for it. The same
 * rule decides the invitations step of the start checklist, so the button and
 * the checklist cannot disagree (ADR 0011).
 *
 * Asked by send_account_token() and, earlier, by the handlers that must refuse
 * before they write anything - a changed address that cannot be told about is
 * a family locked out of a login they never saw.
 */
function account_mail_ready(): bool { return account_mail_missing()===''; }

/**
 * What is in the way of sending a link, as the sentences that say what to do,
 * or '' when nothing is. Every refusal quotes this, so each one names the step
 * that is actually missing and where to take it.
 */
function account_mail_missing(): string {
    $missing=[];
    if(!smtp_tested_ok()) $missing[]=t('E-Mail-Versand zuerst testen: unter „Einstellungen → SMTP“ die Verbindung prüfen.',
                                       'Test sending email first: check the connection under “Settings → SMTP”.');
    if(!setting('privacy_ready',false)) $missing[]=t('Die Datenschutzerklärung unter „Einstellungen → Datenschutz“ freigeben.',
                                                     'Release the privacy notice under “Settings → Privacy”.');
    return implode(' ',$missing);
}
function send_account_token(array $account,string $purpose,?string $email=null): void {
    if(!account_mail_ready()) throw new UserError(account_mail_missing());
    $token=make_token((int)$account['id'],$purpose,$email);
    $en=$account['locale']==='en';
    $subjects=['invite'=>$en?'Your badminton invitation':'Deine Badminton-Einladung','reset'=>$en?'Reset your password':'Passwort zurücksetzen','email'=>$en?'Verify your email address':'E-Mail-Adresse bestätigen'];
    // The words are the designer's (spec S6), one text per purpose whoever
    // asked for it, so the builder needs no sender. The link is the only
    // machine-made part. An invitation by address alone asks for the person's
    // details on the page it opens, so it says which (ADR 0021, §3).
    $link=url('activate',['token'=>$token]);
    $club=trim((string)setting('org_name'));
    $hello=mail_greeting($account);
    $expired=($en?'The link is valid for 48 hours. If it has expired, “Forgot your password” on the sign-in page sends a new one.'
                 :'Der Link gilt 48 Stunden. Ist er abgelaufen, bekommst du auf der Anmeldeseite unter „Passwort vergessen“ einen neuen.')."\n\n";
    $ownDetails=$purpose==='invite' && is_open_invitation((int)$account['id']);
    $body=match($purpose) {
        'invite' => $hello
            .($en ? ($club!==''?"you have been invited to {$club}'s portal.":'you have been invited to the badminton portal.')
                  : ($club!==''?"du bist ins Portal von {$club} eingeladen.":'du bist ins Badminton-Portal eingeladen.'))."\n\n"
            .($ownDetails
                ? ($en?'Open this link and set up your account: first name, last name, date of birth and a password. After that you pick your course.'
                      :'Öffne diesen Link und richte dein Konto ein: Vorname, Nachname, Geburtsdatum und ein Passwort. Danach wählst du deinen Kurs.')
                : ($en?'Open this link, set your password and then complete your details:':'Öffne diesen Link, leg dein Passwort fest und ergänze danach deine Angaben:'))
            ."\n".$link."\n\n"
            .($ownDetails
                ? ($en?'You then sign in with this email address.':'Du meldest dich danach mit dieser E-Mail-Adresse an.')
                : ($en?'You sign in with this email address.':'Du meldest dich mit dieser E-Mail-Adresse an.'))."\n\n"
            .$expired
            .($en?'If you did not expect this email, you can ignore it.':'Falls du diese E-Mail nicht erwartet hast, kannst du sie ignorieren.'),
        'reset' => $hello
            .($en?'a link to set a new password was requested for your login.':'für deinen Zugang wurde ein Link für ein neues Passwort angefordert.')."\n\n"
            .($en?'If you still know your password, there is nothing to do – it keeps working.':'Weißt du dein Passwort noch, ist nichts zu tun – es gilt weiter.')."\n"
            .($en?'Otherwise set a new one here (valid for one hour):':'Sonst leg hier ein neues fest (eine Stunde gültig):')."\n".$link."\n\n"
            .($en?'If that was not you, you can ignore this email.':'Warst du das nicht, kannst du diese E-Mail ignorieren.'),
        default => $hello
            .($en?'Open this link to continue:':'Öffne diesen Link, um fortzufahren:')."\n".$link."\n\n"
            .($en?'Valid for one hour.':'Eine Stunde gültig.')."\n\n"
            .($en?'If you did not expect this email, you can ignore it.':'Falls du diese E-Mail nicht erwartet hast, kannst du sie ignorieren.'),
    };
    queue_mail((int)$account['id'],$email??$account['email'],$subjects[$purpose],$body,'security');
}
function unsubscribe_signature(int $id,string $category): string { return hash_hmac('sha256',$id.'|'.$category,base64_decode(config('app_key'))); }
function unsubscribe_categories(): array { return ['newsletter'=>'newsletter','notifications'=>'notifications','payments'=>'payment_notices']; }
function valid_unsubscribe(int $id,string $category,string $signature): bool { return isset(unsubscribe_categories()[$category]) && hash_equals(unsubscribe_signature($id,$category),$signature); }
function record_consent(int $id,string $purpose,bool $enabled): void { run('INSERT INTO consent_log (account_id,purpose,enabled,notice_version,created_at) VALUES (?,?,?,?,?)',[$id,$purpose,$enabled?1:0,notice_version(),now()]); }
