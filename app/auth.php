<?php
declare(strict_types=1);

function current_user(bool $reload=false): ?array {
    static $resolved=false, $cached=null;
    if($reload) { $resolved=false; $cached=null; }
    if($resolved) return $cached;
    $resolved=true;
    // Nobody signed in is nobody viewing as anybody either: an id left over
    // from a session written before F1 is forgotten here, wherever it is first
    // asked (security review F1). Not forget_session_leftovers(): nothing ended
    // here, and a link being opened by whoever holds this browser lives in
    // exactly this session between opening it and sending its page - dropped
    // here, no invitation or reset link would ever work signed out.
    if(empty($_SESSION['user_id'])) { unset($_SESSION['impersonator_id']); return $cached=null; }
    $a=one('SELECT * FROM accounts WHERE id=?',[(int)$_SESSION['user_id']]);
    $expired=time()-(int)($_SESSION['last_seen']??0)>(int)config('session_idle_minutes')*60;
    if(!$a || $a['state']!=='active' || !$a['verified_at'] || (int)$a['auth_version']!==(int)($_SESSION['auth_version']??0) || $expired) {
        unset($_SESSION['user_id'],$_SESSION['auth_version']);
        forget_session_leftovers();
        return $cached=null;
    }
    $_SESSION['last_seen']=time(); $cached=$a;
    // A view through somebody's eyes is only as good as the viewer's own login,
    // and impersonator() ends this whole session when that no longer holds
    // (security re-review N1). Asked here, where every request first asks who
    // is signed in, so nothing is drawn or done for a view that has ended.
    // After $cached is set, because impersonator() asks who is looked at.
    if(!empty($_SESSION['impersonator_id']) && !impersonator()) return $cached=null;
    return $cached;
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
 * Roles that may be granted from the Zugänge page, and who may grant them.
 *
 * Staff only, and only by an administrator. A student's account is made on
 * that student's own page by student_invite, because it belongs to exactly one
 * student (ADR 0010): made anywhere else it would be a login attached to nobody.
 */
function assignable_roles(array $actor): array {
    return is_admin($actor) ? ['trainer','admin'] : [];
}
function require_user(): array { $a=current_user(); if(!$a) throw new SignInRequired(); return $a; }
function require_staff(): array { $a=require_user(); if(!is_staff($a)) throw new UserError(t('Kein Zugriff.','Access denied.')); return $a; }
function require_admin(): array { $a=require_user(); if($a['role']!=='admin') throw new UserError(t('Nur für Administratoren.','Administrators only.')); return $a; }
/**
 * Forget what a session holds for the person signed in to it, which nobody
 * after them on this browser may have: a view through somebody's eyes, which
 * handed the next person „Ansicht beenden" and the staff member's login
 * (security review F1); the wizard's drafts they made (ADR 0023 §5); a link
 * being opened (activation_hash), which is somebody's key; and where the forms
 * they sent landed (remember_answered_form()), which a copy sent by the next
 * person would otherwise be taken to.
 *
 * Asked wherever a sign-in starts or ends: sign_in(), a session that has run
 * out (current_user()), and a view whose viewer's own login has ended
 * (impersonator()). Signing out empties the whole session.
 */
function forget_session_leftovers(): void {
    unset($_SESSION['impersonator_id'],$_SESSION['impersonator_auth_version'],$_SESSION['student_drafts'],
          $_SESSION['activation_hash'],$_SESSION['answered_forms']);
}
/**
 * The one sentence a refused sign-in is given, whatever the reason: it says
 * nothing about whether the login exists, or why it was refused (ADR 0019,
 * 0021 §1). Thrown by the password path and by sign_in() itself.
 */
function sign_in_refusal(): UserError {
    return new UserError(t('Anmeldung nicht möglich. Bitte E-Mail-Adresse und Passwort prüfen. Noch nicht eingerichtet? Dann zuerst den Link aus der Einladung öffnen.',
                           'Could not sign in. Please check the email address and the password. Not set up yet? Then first open the link in your invitation.'));
}
/**
 * A new session for $a, and nothing of the one before it (forget_session_leftovers()).
 *
 * Every way in ends here - the password, an invitation, a reset link, a
 * confirmed new address, a changed password - so an example login past its days
 * (demo_login_expired()) is refused here, in the usual words, and a link made
 * for it signs nobody in either. Thrown before anything of the session changes,
 * and inside the action's transaction, so a link's password is not written.
 */
function sign_in(array $a): void {
    if(demo_login_expired($a)) throw sign_in_refusal();
    session_regenerate_id(true); forget_session_leftovers(); $_SESSION['user_id']=(int)$a['id']; $_SESSION['auth_version']=(int)$a['auth_version']; $_SESSION['last_seen']=time(); $_SESSION['locale']=$a['locale']; $_SESSION['csrf']=bin2hex(random_bytes(32)); current_user(true);
}
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
 * refusal of an address there is: every block that makes a login, every
 * re-addressing (change_account_email()) and every placeholder given an address
 * asks it, before it writes - the last through invitation_address(), which the
 * callers of invite_student() ask before anything is written (ADR 0023 §3).
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
 * A login's name from a student's two, cut to what accounts.name holds.
 *
 * Here rather than beside the actions that also call it, because the update's
 * step after the files (give_every_student_a_login()) runs before a request has
 * loaded app/actions.php - and the installer and the console never load it.
 */
function login_name_for(string $first, string $last): string {
    return rtrim(mb_substr($first.' '.$last,0,TEXT_LINE_MAX));
}

/**
 * A student's login that nobody can sign in with yet (ADR 0023 §3): state
 * placeholder, no address, no password, called what the student is called.
 * Returns its id. The caller points the student at it, in its own transaction,
 * so a placeholder never exists without its student.
 *
 * No address, even when the student's record carries one: two brothers or
 * sisters may carry a parent's address while neither has a login (0020 §1), and
 * the unique index would refuse the second. An example student's placeholder is
 * example data too, so demo_clear() takes it with the student.
 */
function placeholder_login(string $firstName, string $lastName, bool $isDemo = false): int {
    if(tx_depth()===0) throw new RuntimeException('placeholder_login() outside a transaction could leave a login nobody points to.');
    run("INSERT INTO accounts (name,email,role,state,locale,is_demo,created_at) VALUES (?,NULL,'student','placeholder','de',?,?)",
        [login_name_for($firstName,$lastName),$isDemo?1:0,now()]);
    return (int)db()->lastInsertId();
}

/**
 * Give every student without a login a placeholder (ADR 0023 §4): the students
 * the previous version wrote, for the update's step after the files
 * (database/defaults.php), which runs after every update. Returns how many it
 * gave one.
 *
 * One transaction, holding the students it reads, so a failure part way leaves
 * every student as they were and the next request starts again. Run again, it
 * finds nobody and changes nothing, in one query on student_one_account. Not
 * tracked(): no person made the change (0019 §4). The revision is raised
 * because the student page offers different things to a student with a login:
 * a form opened before the update is refused on saving rather than taken as
 * though nothing had changed.
 *
 * PHP rather than SQL, because an INSERT ... SELECT makes logins with nothing
 * that ties each one to its student, and would be a second copy of how a
 * placeholder is made that could never be corrected once shipped.
 */
function give_every_student_a_login(): int {
    return transactional(function(): int {
        $students=rows('SELECT id,first_name,last_name,is_demo FROM students WHERE account_id IS NULL ORDER BY id FOR UPDATE');
        foreach($students as $s)
            run('UPDATE students SET account_id=?,updated_at=?,revision=revision+1 WHERE id=?',
                [placeholder_login((string)$s['first_name'],(string)$s['last_name'],(bool)$s['is_demo']),now(),(int)$s['id']]);
        return count($students);
    });
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
 * runner and the daily prune - never by a sign-in, because hashing is slower
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
 * moved on since. Returns whether it wrote. For the runner and the daily prune.
 */
function refresh_sign_in_dummy_hash(): bool {
    $hash=(string)setting('sign_in_dummy_hash');
    if($hash!=='' && !password_needs_rehash($hash,PASSWORD_DEFAULT)) return false;
    set_setting('sign_in_dummy_hash',password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT));
    return true;
}

/**
 * The login a sign-in or „vergessen" input names, or null (ADR 0021 §1, 0030 §1).
 *
 * $email is what was typed, normalised (attempted_address()). In this order,
 * and each step is a reason this is not an oracle:
 *
 * 1. A value that fails the format - email_is_dot_atom() - is never looked up
 *    [M1]. Nothing outside atext reaches the collation.
 * 2. One literal statement; nothing is interpolated. A plain read for a
 *    sign-in: under REPEATABLE READ, FOR UPDATE takes a record lock on a login
 *    that exists and only a gap lock on one that does not, so concurrent
 *    sign-ins queued only for real logins, and the wait said which exist
 *    (security review F2). The sign-in's one write, the rehash, is conditional
 *    on the hash it verified instead. „Vergessen" passes $lock, and
 *    ' FOR UPDATE' is appended as a literal: two requests at once must not leave
 *    two live links.
 * 3. A row counts only if it is exactly what was typed, compared in PHP after
 *    normalising both sides. utf8mb4_unicode_ci folds accents, ß against ss and
 *    full-width letters (ADR 0007); a spelling that reaches a row only through
 *    that fold finds nothing here. A legacy address stored with capitals still
 *    matches, because email_normalised() lower-cases both sides.
 *
 * The caller has already counted the attempt under the typed value
 * (address_identity()); nothing here decides what is counted.
 */
function account_for_sign_in(string $email, bool $lock = false): ?array {
    if(!email_is_dot_atom($email)) return null;
    $a=one('SELECT * FROM accounts WHERE email=?'.($lock?' FOR UPDATE':''),[$email]);
    return $a && email_normalised((string)$a['email'])===$email ? $a : null;
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
            throw new UserError(t('Es gibt bereits einen Administrator. Weitere Konten werden im Portal unter „Zugänge“ eingeladen.','An administrator already exists. Invite further accounts under “Logins” in the portal.'));
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
/**
 * How long a link of each purpose works, in seconds. 48 hours for an invitation,
 * which leads to a first password (ADR 0023 §12): one lifetime a parent already
 * knows. An hour for a reset and a changed address.
 */
function token_lifetime(string $purpose): int {
    return $purpose==='invite' ? 172800 : 3600;
}

/**
 * How long a link of $purpose works, as a sentence says it - „eine Stunde",
 * „48 Stunden" - in English where $en: a mail speaks its recipient's language,
 * a page the reader's. Every text that names a lifetime asks this, so none can
 * promise a link longer than token_lifetime() gives it.
 */
function token_lifetime_words(string $purpose, bool $en): string {
    $hours=intdiv(token_lifetime($purpose),3600);
    if($hours===1) return $en?'one hour':'eine Stunde';
    return $en?$hours.' hours':$hours.' Stunden';
}

/**
 * Make a one-time link: 32 random bytes, of which only the sha256 is stored. It
 * replaces the login's earlier link of the same purpose, so there is one per
 * login and purpose - an invitation sent again kills the old one.
 */
function make_token(int $accountId,string $purpose,?string $email=null): string {
    run('DELETE FROM auth_tokens WHERE account_id=? AND purpose=?',[$accountId,$purpose]);
    $token=bin2hex(random_bytes(32));
    run('INSERT INTO auth_tokens (account_id,token_hash,purpose,target_email,expires_at,created_at) VALUES (?,?,?,?,?,?)',[$accountId,hash('sha256',$token),$purpose,$email,gmdate('Y-m-d H:i:s',time()+token_lifetime($purpose)),now()]);
    return $token;
}
/**
 * A live link and the login it belongs to. With the address, because the page
 * a link opens shows it beside the new password for the phone to save it
 * under: whoever holds the link signs in with it anyway. With the language,
 * because an invitation's page speaks the one it was sent in (public/index.php).
 */
function token_record(string $hash,bool $lock=false): ?array {
    return one('SELECT t.*,a.state,a.email,a.role,a.name,a.locale FROM auth_tokens t JOIN accounts a ON a.id=t.account_id WHERE t.token_hash=? AND t.expires_at>?'.($lock?' FOR UPDATE':''),[$hash,now()]);
}

/**
 * Whether a link token_record() found can still be used; null - no such link,
 * or one that has run out - cannot. One rule for the page a link opens and the
 * activate action that uses it, so the page never offers a form the action
 * refuses, nor shows the login's address for a link that signs nobody in
 * (security re-review N2).
 *
 * Each of the three purposes there are goes with the one state it is made for
 * (security review F3): an invitation with a login still invited - accepting
 * it sets the password, the consents and the privacy acknowledgement, which on
 * a login in use would be a reset that skips the reset's rule - and a reset or
 * a changed address with a login in use. Nothing opens a suspended login or a
 * placeholder. A row left in auth_tokens by a sign-in link from before ADR 0030
 * has no purpose here, so it opens nothing, and the daily prune removes it as
 * it lapses. The sender asks the same before a mail with a link goes out
 * (security_mail_links_live()).
 */
function link_usable(?array $r): bool {
    return $r!==null && match($r['purpose']) {
        'invite'         => $r['state']==='invited',
        'reset', 'email' => $r['state']==='active',
        default          => false,
    };
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
 * to one in use, active and verified (ADR 0020, §5), that has an address to mail
 * it to. An invitation is sent again instead; a suspended login is restored
 * first. One rule for the account_state action and for the card that offers the
 * button, so the card never offers what the action refuses.
 */
function reset_link_possible(array $account): bool {
    return ($account['state'] ?? '')==='active' && !empty($account['verified_at']) && (string)($account['email'] ?? '')!=='';
}

/**
 * When the newest invitation to a login was sent and when it stops working, as
 * created_at and expires_at - or null when there is none (ADR 0020, §10c).
 *
 * For the access card, so she can answer "the link doesn't work". Never the
 * token_hash: a view reads the dates through this and does not query
 * auth_tokens itself. Expired tokens are deleted once a day (prune_expired()),
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
 *
 * Where only an administrator can take it, as privacy_notice_missing() does:
 * an administrator is sent to the tab, anybody else is told who sets it up
 * rather than sent to a page they cannot open - in one sentence when both
 * steps are missing, not the same opening twice.
 */
function account_mail_missing(): string {
    $mail=!smtp_tested_ok();
    $privacy=privacy_notice_missing();
    if(!is_admin()) {
        if(!$mail) return $privacy;
        return $privacy!==''
            ? t('Eine Administratorin muss zuerst den E-Mail-Versand einrichten und testen und die Datenschutzerklärung freigeben.',
                'An administrator has to set up and test sending email and release the privacy notice first.')
            : t('Eine Administratorin muss zuerst den E-Mail-Versand einrichten und testen.',
                'An administrator has to set up and test sending email first.');
    }
    $missing=$mail?[t('E-Mail-Versand zuerst testen: unter „Einstellungen → SMTP“ die Verbindung prüfen.',
                      'Test sending email first: check the connection under “Settings → SMTP”.')]:[];
    if($privacy!=='') $missing[]=$privacy;
    return implode(' ',$missing);
}

/**
 * The step that releases the privacy notice, as the sentence that says where -
 * or '' once it is released. Everybody acknowledges the notice the first time
 * they set up their login, from the invitation, so none can go out before;
 * every refusal and notice that says so quotes this. Settings are an
 * administrator's, so a trainer is told who releases it rather than sent to a
 * page she cannot open - as the e-mail card tells her (mail_not_ready_notice()).
 */
function privacy_notice_missing(): string {
    if(setting('privacy_ready',false)) return '';
    return is_admin() ? t('Die Datenschutzerklärung unter „Einstellungen → Datenschutz“ freigeben.','Release the privacy notice under “Settings → Privacy”.')
                      : t('Eine Administratorin muss zuerst die Datenschutzerklärung freigeben.','An administrator has to release the privacy notice first.');
}

/**
 * Refuse an invitation that cannot go out yet, naming what is missing: a login
 * nobody can be invited to is somebody locked out of something they never saw.
 * Asked before anything is written by every way a login is invited.
 */
function refuse_until_invitations_can_go(): void {
    if(!account_mail_ready())
        throw new UserError(t('Eine Einladung lässt sich noch nicht verschicken. ','An invitation cannot be sent yet. ').account_mail_missing());
}

/**
 * Mail a one-time link: an invitation, a reset, or the confirmation of a new
 * address ($email, the new mailbox). A login without an address is refused
 * before any link is made: nothing is mailed to a login that has none. The
 * portal makes none that signs in (ADR 0030), and a placeholder is never asked
 * for a link, so what reaches this is a login edited by hand in the database -
 * an active one without an address.
 */
function send_account_token(array $account,string $purpose,?string $email=null): void {
    if((string)($email ?? $account['email'] ?? '')==='')
        throw new UserError(t('Dieser Zugang hat keine E-Mail-Adresse, an die ein Link gehen könnte.','This login has no email address for a link to go to.'));
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
    $valid=token_lifetime_words($purpose,$en);
    $expired=($en?"The link is valid for {$valid}. If it has expired, “Forgot your password” on the sign-in page sends a new one."
                 :"Der Link gilt {$valid}. Ist er abgelaufen, bekommst du auf der Anmeldeseite unter „Passwort vergessen“ einen neuen.")."\n\n";
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
            .($en?"Otherwise set a new one here (valid for {$valid}):":"Sonst leg hier ein neues fest ({$valid} gültig):")."\n".$link."\n\n"
            .($en?'If that was not you, you can ignore this email.':'Warst du das nicht, kannst du diese E-Mail ignorieren.'),
        default => $hello
            .($en?'Open this link to continue:':'Öffne diesen Link, um fortzufahren:')."\n".$link."\n\n"
            .($en?"The link is valid for {$valid}.":"Der Link gilt {$valid}.")."\n\n"
            .($en?'If you did not expect this email, you can ignore it.':'Falls du diese E-Mail nicht erwartet hast, kannst du sie ignorieren.'),
    };
    queue_mail((int)$account['id'],$email??$account['email'],$subjects[$purpose],$body,'security');
}
/**
 * How long the unsubscribe link in a mail works: 90 days from when the mail was
 * sent. Every mail that can be switched off carries a fresh one, so whoever
 * still gets such mail has a working link in the newest, and one read late - a
 * reminder kept in the inbox, news from a quarter ago - still works. A link that
 * travelled further, in a forwarded mail or a screenshot, stops switching
 * somebody's mail off after that; before, it did so for ever. The page then says
 * where the switches are (unsubscribe_refusal()).
 */
const UNSUBSCRIBE_LINK_DAYS = 90;
/**
 * What an unsubscribe link carries as its signature: the moment it stops
 * working, then a MAC over the account, the category and that moment, so none
 * of the three can be changed. $until exists for a test that needs a link from
 * the past; a mail never passes it.
 */
function unsubscribe_signature(int $id,string $category,?int $until=null): string {
    $until??=time()+UNSUBSCRIBE_LINK_DAYS*86400;
    return $until.'.'.hash_hmac('sha256',$id.'|'.$category.'|'.$until,base64_decode(config('app_key')));
}
function unsubscribe_categories(): array { return ['newsletter'=>'newsletter','notifications'=>'notifications','payments'=>'payment_notices']; }
/**
 * What switching one category off stops, as the unsubscribe page says it: the
 * mails queued under it, by what they are about. The page once had a sentence
 * for news and one for everything else, so a payment reminder's link offered to
 * stop „Hinweise für private Nachrichten". Every category needs its own here.
 */
function unsubscribe_stops(string $category): string {
    return match($category) {
        'newsletter' => t('Keine Neuigkeiten mehr per E-Mail erhalten. Im Portal bleiben sie lesbar.',
                          'Stop receiving news by email. News remains available in the portal.'),
        'notifications' => t('Keine E-Mail-Hinweise mehr zu neuen Nachrichten, geänderten Trainingsterminen und Antworten auf Anfragen. Im Portal steht alles weiter.',
                             'Stop receiving email notices about new messages, changed training dates and answers to requests. Everything stays in the portal.'),
        'payments' => t('Keine E-Mails mehr zu Beiträgen erhalten: keine Erinnerung an offene Beiträge und keine Rechnungen. Im Portal stehen sie weiter.',
                        'Stop receiving emails about payments: no reminders of outstanding payments and no invoices. They stay in the portal.'),
    };
}
/** A link this portal signed, for a category that can be switched off, and not past its day. */
function valid_unsubscribe(int $id,string $category,string $signature): bool {
    if(!isset(unsubscribe_categories()[$category]) || !preg_match('/^([0-9]{1,12})\.[0-9a-f]{64}$/D',$signature,$m)) return false;
    return (int)$m[1]>=time() && hash_equals(unsubscribe_signature($id,$category,(int)$m[1]),$signature);
}
/** What a link that no longer works is answered with, on the page and if it is sent anyway. */
function unsubscribe_refusal(): string {
    return t('Dieser Abmeldelink gilt nicht mehr. Melde dich an und schalte die E-Mails unter „Mein Konto“ ab.',
             'This unsubscribe link no longer works. Sign in and switch the emails off under “My account”.');
}
function record_consent(int $id,string $purpose,bool $enabled): void { run('INSERT INTO consent_log (account_id,purpose,enabled,notice_version,created_at) VALUES (?,?,?,?,?)',[$id,$purpose,$enabled?1:0,notice_version(),now()]); }
