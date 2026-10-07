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
    // here, no invitation, reset or sign-in link would ever work signed out.
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
 * Roles that may be granted from the Konten page, and who may grant them.
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
 * (security review F1); the readable sign-in links and the wizard's drafts they
 * made (ADR 0023 §5, §6), a link being a key to a child's login; a link being
 * opened (activation_hash), which is somebody's key too; and where the forms
 * they sent landed (remember_answered_form()), which a copy sent by the next
 * person would otherwise be taken to.
 *
 * Asked wherever a sign-in starts or ends: sign_in(), a session that has run
 * out (current_user()), and a view whose viewer's own login has ended
 * (impersonator()). Signing out empties the whole session.
 */
function forget_session_leftovers(): void {
    unset($_SESSION['impersonator_id'],$_SESSION['impersonator_auth_version'],$_SESSION['signin_links'],$_SESSION['student_drafts'],
          $_SESSION['activation_hash'],$_SESSION['answered_forms']);
}
/** A new session for $a, and nothing of the one before it (forget_session_leftovers()). */
function sign_in(array $a): void { session_regenerate_id(true); forget_session_leftovers(); $_SESSION['user_id']=(int)$a['id']; $_SESSION['auth_version']=(int)$a['auth_version']; $_SESSION['last_seen']=time(); $_SESSION['locale']=$a['locale']; $_SESSION['csrf']=bin2hex(random_bytes(32)); current_user(true); }
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
 * "Lena Hofer" does - and the sign-in name while there is no name at all: an
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
    return $name!=='' ? $name : sign_in_name($account);
}

/**
 * What a login signs in with, as its holder types it: the address, or the
 * username of a login without one (ADR 0023 §1), or '' for a placeholder, which
 * signs in with nothing. The address first: a username login whose holder added
 * an address signs in with both, and the address is the one mail goes to.
 */
function sign_in_name(array $account): string {
    $email=(string)($account['email']??'');
    return $email!=='' ? $email : (string)($account['username']??'');
}

/**
 * Whether what somebody typed to confirm is this login's sign-in name: the
 * address, compared the way addresses are, or the username, the way usernames
 * are. For „Anmeldung löschen", which asks for it typed (ADR 0021 §1, 0023 §4).
 * A placeholder has no sign-in name, so nothing matches it.
 */
function typed_sign_in_name_matches(string $typed, array $account): bool {
    $email=(string)($account['email']??'');
    if($email!=='') return email_normalised($typed)===email_normalised($email);
    $username=(string)($account['username']??'');
    return $username!=='' && username_normalised($typed)===$username;
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
 * placeholder, no address, no username, no password, called what the student is
 * called. Returns its id. The caller points the student at it, in its own
 * transaction, so a placeholder never exists without its student.
 *
 * No address, even when the student's record carries one: two brothers or
 * sisters may carry a parent's address while neither has a login (0020 §1), and
 * the unique index would refuse the second. An example student's placeholder is
 * example data too, so demo_clear() takes it with the student.
 */
function placeholder_login(string $firstName, string $lastName, bool $isDemo = false): int {
    if(tx_depth()===0) throw new RuntimeException('placeholder_login() outside a transaction could leave a login nobody points to.');
    run("INSERT INTO accounts (name,email,username,role,state,locale,is_demo,created_at) VALUES (?,NULL,NULL,'student','placeholder','de',?,?)",
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
 * tracked(): no person made the change (0019 §4). The revision is raised, as
 * invite_student() raises it when it links a login, because the student page
 * offers different things to a student with a login: a form opened before the
 * update is refused on saving rather than taken as though nothing had changed.
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

/*
 * Giving a username (ADR 0023 §1). A username is unique ignoring case; nothing
 * but lower case is ever written (username_value()), and the unique index on
 * accounts.username compares under utf8mb4_unicode_ci as a second guard, not as
 * the rule. The pattern has no '_' and no '%', so the LIKE below holds no
 * wildcard but its own.
 */

/** The usernames $base and $base followed by digits that logins already have. $base% takes in $base itself. */
function usernames_taken_near(string $base, bool $lock): array {
    $near=rows('SELECT username FROM accounts WHERE username LIKE ?'.($lock?' FOR UPDATE':''),[$base.'%']);
    return array_values(array_filter(array_map('strval',array_column($near,'username')),
        fn(string $u)=>$u===$base || preg_match('/^'.preg_quote($base,'/').'[0-9]+$/D',$u)===1));
}

/**
 * $username if no login has it, or the first free one numbered after it
 * (username_first_free()), or null when there is none - read with a lock on the
 * name and on every numbered one, so two requests giving lena.hofer at once
 * cannot both have it: the second waits for the first to commit and then sees
 * its row.
 *
 * Relies on REPEATABLE READ for the lock to cover a name nobody has yet, like
 * refuse_address_in_use(), and refuses outside a transaction, where it would
 * hold nothing. The caller compares the answer with what it asked for: the same
 * name is free, any other is the suggestion its refusal offers.
 */
function username_for_new_account(string $username): ?string {
    if(tx_depth()===0) throw new RuntimeException('username_for_new_account() outside a transaction holds nothing.');
    return username_first_free($username,usernames_taken_near($username,true));
}

/**
 * The username a form offers for a person: their name's (username_from_name()),
 * numbered when it is taken. A plain read, for a page: the form only suggests,
 * and the action that writes asks username_for_new_account() again.
 */
function username_suggested(string $firstName, string $lastName): string {
    $base=username_from_name($firstName,$lastName);
    return username_first_free($base,usernames_taken_near($base,false)) ?? $base;
}

/**
 * Refuse a username another login has, saying which one is free instead - staff
 * see every username anyway, so the answer tells them nothing new (ADR 0023 §1).
 * Asked inside the transaction that writes it, before it writes.
 */
function refuse_username_in_use(string $username): void {
    $free=username_for_new_account($username);
    if($free===$username) return;
    throw new UserError(t('Diesen Benutzernamen hat schon jemand. ','Somebody already has this username. ')
        .($free!==null ? strtr(t('Frei wäre zum Beispiel {free}.','{free} would be free, for example.'),['{free}'=>$free])
                       : t('Bitte einen anderen wählen.','Please choose another one.')));
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
 * The login a sign-in or „vergessen" input names, or null (ADR 0021 §1, 0023 §7).
 *
 * $kind and $value come from attempted_sign_in(): 'address' with a normalised
 * address, or 'username' with a normalised username. In this order, and each
 * step is a reason this is not an oracle:
 *
 * 1. A value that fails its format - email_is_dot_atom() or USERNAME_PATTERN -
 *    is never looked up [M1]. Nothing outside atext reaches the collation.
 * 2. Two literal statements, one per kind; the column is never interpolated. A
 *    plain read for a sign-in: under REPEATABLE READ, FOR UPDATE takes a record
 *    lock on a login that exists and only a gap lock on one that does not, so
 *    concurrent sign-ins queued only for real logins, and the wait said which
 *    exist (security review F2). The sign-in's one write, the rehash, is
 *    conditional on the hash it verified instead. „Vergessen" passes $lock, and
 *    ' FOR UPDATE' is appended as a literal: two requests at once must not leave
 *    two live links.
 * 3. A row counts only if it is exactly what was typed, compared in PHP after
 *    normalising both sides. utf8mb4_unicode_ci folds accents, ß against ss and
 *    full-width letters (ADR 0007); a spelling that reaches a row only through
 *    that fold finds nothing here. A legacy address stored with capitals still
 *    matches, because email_normalised() lower-cases both sides; a username is
 *    only ever stored in lower case.
 *
 * The caller has already counted the attempt under the typed value
 * (sign_in_identity()); nothing here decides what is counted.
 */
function account_for_sign_in(string $kind, string $value, bool $lock = false): ?array {
    if($kind==='address') {
        if(!email_is_dot_atom($value)) return null;
        $a=one('SELECT * FROM accounts WHERE email=?'.($lock?' FOR UPDATE':''),[$value]);
        return $a && email_normalised((string)$a['email'])===$value ? $a : null;
    }
    if($kind==='username') {
        if(!preg_match(USERNAME_PATTERN,$value)) return null;
        $a=one('SELECT * FROM accounts WHERE username=?'.($lock?' FOR UPDATE':''),[$value]);
        return $a && (string)$a['username']===$value ? $a : null;
    }
    throw new LogicException('Not a kind of sign-in: '.$kind);
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
/**
 * How long a link of each purpose works, in seconds. 48 hours for the two that
 * lead to a first password - an invitation and a sign-in link (ADR 0023 §12):
 * one lifetime a parent already knows. An hour for a reset and a changed address.
 */
function token_lifetime(string $purpose): int {
    return in_array($purpose,['invite','signin'],true) ? 172800 : 3600;
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
 * login and purpose - a new sign-in link kills the old one (ADR 0023 §6).
 */
function make_token(int $accountId,string $purpose,?string $email=null): string {
    run('DELETE FROM auth_tokens WHERE account_id=? AND purpose=?',[$accountId,$purpose]);
    $token=bin2hex(random_bytes(32));
    run('INSERT INTO auth_tokens (account_id,token_hash,purpose,target_email,expires_at,created_at) VALUES (?,?,?,?,?,?)',[$accountId,hash('sha256',$token),$purpose,$email,gmdate('Y-m-d H:i:s',time()+token_lifetime($purpose)),now()]);
    return $token;
}
/**
 * A live link and the login it belongs to. With the address or the username,
 * because the page a link opens shows it beside the new password for the phone
 * to save it under: whoever holds the link signs in with it anyway. With the
 * language, because an invitation's page speaks the one it was sent in
 * (public/index.php), and with verified_at, because a sign-in link's page asks
 * for the privacy acknowledgement only on the first sign-in (ADR 0023 §6).
 */
function token_record(string $hash,bool $lock=false): ?array {
    return one('SELECT t.*,a.state,a.email,a.username,a.verified_at,a.role,a.name,a.locale FROM auth_tokens t JOIN accounts a ON a.id=t.account_id WHERE t.token_hash=? AND t.expires_at>?'.($lock?' FOR UPDATE':''),[$hash,now()]);
}

/**
 * Whether a link token_record() found can still be used; null - no such link,
 * or one that has run out - cannot. One rule for the page a link opens and the
 * activate action that uses it, so the page never offers a form the action
 * refuses, nor shows the login's address or username for a link that signs
 * nobody in (security re-review N2).
 *
 * Not to a suspended login, and only for the four purposes there are. A sign-in
 * link is asked, as it is used, what was asked when it was made
 * (signin_link_possible()): one whose login has since become a staff login,
 * lost its student or been given an address it never confirmed signs nobody in.
 * The last is also why a first link never sets up an address: an invitation by
 * e-mail is accepted from its mailbox, or not at all.
 */
function link_usable(?array $r): bool {
    if(!$r || $r['state']==='suspended' || !in_array($r['purpose'],['invite','reset','email','signin'],true)) return false;
    return $r['purpose']!=='signin' || signin_link_possible(['id'=>(int)$r['account_id']]+$r);
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
 * it to - a username login without one gets a sign-in link instead (ADR 0023
 * §6). An invitation is sent again instead; a suspended login is restored
 * first. One rule for the account_state action and for the card that offers the
 * button, so the card never offers what the action refuses.
 */
function reset_link_possible(array $account): bool {
    return ($account['state'] ?? '')==='active' && !empty($account['verified_at']) && (string)($account['email'] ?? '')!=='';
}

/*
 * Sign-in links („Anmeldelink", ADR 0023 §6): purpose 'signin' in auth_tokens,
 * made by staff for a student's login and shared by them - shown as a QR code
 * or sent through a messenger, never mailed. The GET of ?page=activate&token=
 * uses nothing up; only the activate POST does, and it always sets a new
 * password. Whether one may be made is decided here and by
 * may_create_signin_link() (app/shell.php), and nowhere else.
 */

/**
 * Whether a sign-in link can be made for this login at all, by anybody: the one
 * rule the action and every button ask.
 *
 * Only a student's login that a student points to - staff recover by mail, and
 * a link to a login with staff rights is the most valuable key the portal could
 * hand out. Not a suspended one. And one of: a placeholder, which gets its
 * username in the same request; a username login not yet signed in; or a login
 * in use, with an address or a username - for which the link is the way back
 * from a forgotten password without a mailbox.
 *
 * Never an invitation by e-mail: a link would set up a login whose address
 * nobody confirmed, and its invoices and reminders would go there - a mistyped
 * address would send a child's invoices to a stranger, unnoticed.
 */
function signin_link_possible(array $account): bool {
    if(($account['role'] ?? '')!=='student' || !isset($account['id']) || !login_student_id((int)$account['id'])) return false;
    return ($account['state'] ?? '')==='placeholder' || username_login_waiting($account) || account_in_use($account);
}

/**
 * A username login waiting for its first sign-in (ADR 0023 §3): given a
 * username and a link, and no address - „Noch nicht angemeldet". An invitation
 * by e-mail waits too, but at an address, and is „Eingeladen". One rule for the
 * badge, the access card and signin_link_possible().
 */
function username_login_waiting(array $account): bool {
    return ($account['state'] ?? '')==='invited' && (string)($account['email'] ?? '')==='' && (string)($account['username'] ?? '')!=='';
}

/**
 * The sign-in links made for one login in the last $days days, newest first:
 * when each was made and by whom, until when it works while it still does, and
 * when it was used or withdrawn. Read from the audit, which records who made a
 * link and who used one (ADR 0023 §6, "Every use is visible"), and for the live
 * one its end from auth_tokens - never its hash.
 *
 * For the access card, which shows the newest, and for Mein Konto, which lists
 * those used to set a password - so a link used by anybody but its holder
 * cannot go unnoticed. Every caller passes PASSWORD_RESET_SHOWN_DAYS. A use or
 * a withdrawal belongs to the newest link made before it, because a new link
 * replaces the old one.
 */
function signin_links_for(int $accountId, int $days): array {
    $entries=rows("SELECT l.action,l.created_at,a.name AS actor_name FROM audit_log l LEFT JOIN accounts a ON a.id=l.actor_id"
        ." WHERE l.entity_type='account' AND l.entity_id=? AND l.action IN ('account.signin_link','account.signin_link_used','account.signin_link_withdrawn')"
        .' AND l.created_at>=? ORDER BY l.id',[$accountId,gmdate('Y-m-d H:i:s',time()-$days*86400)]);
    $links=[];
    foreach($entries as $entry) {
        if($entry['action']==='account.signin_link') {
            $links[]=['created_at'=>(string)$entry['created_at'],'made_by'=>(string)($entry['actor_name']??''),
                      'expires_at'=>null,'used_at'=>null,'withdrawn_at'=>null];
            continue;
        }
        $last=count($links)-1;
        if($last<0) continue;
        $links[$last][$entry['action']==='account.signin_link_used'?'used_at':'withdrawn_at']??=(string)$entry['created_at'];
    }
    $live=one("SELECT created_at,expires_at FROM auth_tokens WHERE account_id=? AND purpose='signin' AND expires_at>? ORDER BY id DESC LIMIT 1",[$accountId,now()]);
    $last=count($links)-1;
    if($live && $last>=0 && $links[$last]['used_at']===null && $links[$last]['withdrawn_at']===null) $links[$last]['expires_at']=(string)$live['expires_at'];
    return array_reverse($links);
}

/**
 * Keep the readable sign-in link in the session of the member of staff who made
 * it, and nowhere else - not the database, a log, a mail or an address (ADR
 * 0023 §6, A5): an iPhone reloads the tab after a switch to WhatsApp, and a link
 * shown once would be gone the moment she went to share it. Only the actions
 * that make one call this.
 */
function remember_signin_link(int $accountId, string $token): void {
    $_SESSION['signin_links'][$accountId]=['token'=>$token,'by'=>(int)acting_account_id()];
}

/** Forget the readable link for one login: withdrawn, replaced, used up or lapsed. */
function forget_signin_link(int $accountId): void {
    unset($_SESSION['signin_links'][$accountId]);
}

/**
 * The readable sign-in link for a login, as ['link','token','expires_at'], while
 * the person looking made it and it still works - or null. A link this session
 * holds that has been used, replaced, withdrawn or has lapsed is dropped here,
 * the first time a page finds it so; and one somebody else made never shows,
 * whoever this browser is signed in as now.
 */
function signin_link_shown(int $accountId): ?array {
    $kept=$_SESSION['signin_links'][$accountId] ?? null;
    if(!is_array($kept) || !is_string($kept['token'] ?? null)) return null;
    $record=preg_match('/^[a-f0-9]{64}$/D',$kept['token']) ? token_record(hash('sha256',$kept['token'])) : null;
    $live=$record && $record['purpose']==='signin' && (int)$record['account_id']===$accountId && $record['state']!=='suspended';
    if(!$live) { forget_signin_link($accountId); return null; }
    if((int)($kept['by'] ?? 0)!==(int)acting_account_id()) return null;
    return ['link'=>url('activate',['token'=>$kept['token']]),'token'=>$kept['token'],'expires_at'=>(string)$record['expires_at']];
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
 * they set up their login, by invitation or by sign-in link, so neither can
 * go out before; every refusal and notice that says so quotes this. Settings
 * are an administrator's, so a trainer is told who releases it rather than sent
 * to a page she cannot open - as the e-mail card tells her (mail_not_ready_notice()).
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
 * Refuse a first sign-in link while the privacy notice is not released: its
 * holder acknowledges the notice on the page the link opens, and a link that
 * page would refuse is a link that cannot work. Asked by make_signin_link() for
 * every login not yet set up (security review, finding 6), and by the wizard
 * before it writes anything (ADR 0023 §5).
 */
function refuse_signin_link_until_privacy_released(): void {
    if(($missing=privacy_notice_missing())!=='')
        throw new UserError(t('Ein Anmeldelink geht erst, wenn die Datenschutzerklärung freigegeben ist – sie wird bei der ersten Anmeldung bestätigt. ',
                              'A sign-in link only works once the privacy notice is released – it is acknowledged at the first sign-in. ').$missing);
}
/**
 * Mail a one-time link: an invitation, a reset, or the confirmation of a new
 * address ($email, the new mailbox). Never a sign-in link, which is shared by
 * staff rather than mailed (ADR 0023 §6) - a mailed one would be a reset link
 * under another name. A login without an address is refused before any link is
 * made (§7): nothing is mailed to a login that has none.
 */
function send_account_token(array $account,string $purpose,?string $email=null): void {
    if($purpose==='signin') throw new LogicException('A sign-in link is never mailed (ADR 0023 §6).');
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
function unsubscribe_signature(int $id,string $category): string { return hash_hmac('sha256',$id.'|'.$category,base64_decode(config('app_key'))); }
function unsubscribe_categories(): array { return ['newsletter'=>'newsletter','notifications'=>'notifications','payments'=>'payment_notices']; }
function valid_unsubscribe(int $id,string $category,string $signature): bool { return isset(unsubscribe_categories()[$category]) && hash_equals(unsubscribe_signature($id,$category),$signature); }
function record_consent(int $id,string $purpose,bool $enabled): void { run('INSERT INTO consent_log (account_id,purpose,enabled,notice_version,created_at) VALUES (?,?,?,?,?)',[$id,$purpose,$enabled?1:0,notice_version(),now()]); }
