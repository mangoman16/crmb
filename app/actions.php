<?php
declare(strict_types=1);

/**
 * The role asked for on the Konten page, which grants staff logins only.
 *
 * A student's login is refused there by name rather than as "invalid choice":
 * a page from before this rule still offers it, and she needs to know where
 * to go instead.
 */
function staff_role_posted(): string {
    $role=post('role');
    if($role==='student')
        throw new UserError(t('Ein Schülerkonto wird auf der Seite der Schülerin oder des Schülers angelegt – dort gehört es zu genau einer Person.',
                              'A student account is created on that student’s own page – there it belongs to exactly one person.'));
    return $role;
}

/**
 * Change the address a login's mail goes to, and that it signs in with. The only
 * place that does.
 *
 * Both copies change together - the address on the account and the one on its
 * student - so the two can never disagree. Every session on the account ends
 * (auth_version), every link already sent stops working (a link to the old
 * mailbox must not open the account once the address has moved), and mail still
 * waiting for the old address is not sent.
 *
 * Refuses an address that is another login's (refuse_address_in_use()). Asked
 * here, at the moment of writing, so a family confirming a change hears it even
 * if the address became somebody's login between asking and clicking - and so
 * email_change does not have to ask it earlier, where it would tell a signed-in
 * family whether an address has a login (ADR 0020, §1).
 *
 * Refuses, too, to move anybody else's staff or administrator login. Only a
 * student's login is ever re-addressed on somebody's behalf; a staff login
 * moves only when its holder confirms the link from the new mailbox.
 */
function change_account_email(int $accountId, string $email): void {
    $email=email_value($email);
    transactional(function() use ($accountId,$email): void {
        $account=lock_row('accounts',$accountId);
        if(!$account) throw new NotFound(t('Dieses Konto gibt es nicht mehr.','That account no longer exists.'));
        if((int)(current_user()['id']??0)!==$accountId) refuse_unless_student_login($account);
        refuse_address_in_use($email,$accountId);
        run('UPDATE accounts SET email=?,auth_version=auth_version+1 WHERE id=?',[$email,$accountId]);
        run('UPDATE students SET email=?,updated_at=?,revision=revision+1 WHERE account_id=?',[$email,now(),$accountId]);
        run('DELETE FROM auth_tokens WHERE account_id=?',[$accountId]);
        cancel_account_mail($accountId);
    });
}

/**
 * Change a login's username, for its holder only (ADR 0020, §2). The one way a
 * username is changed: from Mein Konto (username_change) and from the
 * invitation's activation page, so both follow the same rules.
 *
 * Returns 'unchanged', 'taken' or 'changed'. A taken name is answered rather
 * than thrown [R3]: a throw would roll back the audit row that makes each of
 * these attributable. Five a day per login, on a bucket of its own, so nobody
 * signed in can walk the list of names [S2]. The row is locked before tracked()
 * takes its snapshot, and history_never_recorded() keeps the hash out of it, so
 * the change log line says only what changed [S6]. Not auth_version: a username
 * is not a secret, and its holder changes it back the same way.
 *
 * The caller flashes, holds the form and chooses the page. It also decides who
 * may ask: this trusts that $account is the person asking.
 */
function change_own_username(array $account, string $typed): string {
    $username=username_value($typed);
    if($username===(string)$account['username']) return 'unchanged';
    lock_row('accounts',(int)$account['id']);
    if(one('SELECT id FROM accounts WHERE username=? FOR UPDATE',[$username])) {
        throttle('username-taken',account_identity((int)$account['id']),5,86400);
        audit('account.username_taken','account',(int)$account['id']);
        return 'taken';
    }
    tracked('accounts',(int)$account['id'],(string)$account['name'],
        fn()=>run('UPDATE accounts SET username=? WHERE id=?',[$username,(int)$account['id']]));
    audit('account.username_changed','account',(int)$account['id']);
    return 'changed';
}

/**
 * A student's own login, made by invitation - the only code that sets
 * students.account_id (ADR 0010, as moved by ADR 0020 §6). Returns the
 * account_id and the username it was given.
 *
 * For the access card's button (student_invite) and for creating a student with
 * „Gleich einladen" (student_save). The caller holds the student's row.
 *
 * Every refusal comes before the first write: a student who already has a
 * login, an address that cannot be written, an address that is another login's
 * - a brother or sister gets an address of their own, and a parent's belongs on
 * the contacts - and mail that cannot be sent yet, because a login nobody can be
 * invited to is a family locked out of something they never saw.
 *
 * The invitation goes to the address on the student rather than to one of the
 * people on their emergency list. Those are two different questions - who do I
 * ring when she falls over, who reads the invoices - and one row answering both
 * is how a grandmother with no email ended up being the reason a family could
 * not sign in. Everything else about the login comes from the student too: the
 * page offers a button, not a form.
 */
function invite_student(array $student): array {
    if($student['account_id'])
        throw new UserError(t('Dieses Kind hat schon ein eigenes Konto. Zugang, Einladung und Adresse werden dort verwaltet.',
                              'This student already has an account of their own. Access, invitation and address are managed there.'));
    $email=email_value((string)$student['email']);
    refuse_address_in_use($email);
    if(!account_mail_ready())
        throw new UserError(t('Eine Einladung lässt sich noch nicht verschicken. ','An invitation cannot be sent yet. ').account_mail_missing());
    $name=rtrim(mb_substr($student['first_name'].' '.$student['last_name'],0,TEXT_LINE_MAX));
    $username=username_for_new_account((string)$student['first_name'],(string)$student['last_name']);
    // German until they choose, like every page before they sign in.
    run("INSERT INTO accounts (name,email,username,role,locale,created_at) VALUES (?,?,?,'student','de',?)",[$name,$email,$username,now()]);
    $accountId=(int)db()->lastInsertId();
    // Tracked, so the student's change log says when they got their login and
    // at which address - the one line that writes account_id is the one line
    // worth being able to read back.
    tracked('students',(int)$student['id'],$student['first_name'].' '.$student['last_name'],
        fn()=>run('UPDATE students SET account_id=?,email=?,updated_at=?,revision=revision+1 WHERE id=?',[$accountId,$email,now(),$student['id']]));
    send_account_token(one('SELECT * FROM accounts WHERE id=?',[$accountId]),'invite');
    audit('account.invited','account',$accountId);
    return ['account_id'=>$accountId,'username'=>$username];
}

/**
 * Refuse to change a login that is not a student's on somebody else's behalf.
 *
 * A student row linked to a trainer's or an administrator's login is left from
 * before ADR 0010, and while that login was still only invited, the student
 * page could move its address - and so send its invitation - somewhere the
 * person saving chose. That is how a trainer would take over an administrator.
 */
function refuse_unless_student_login(array $account): void {
    if(($account['role']??'')!=='student')
        throw new UserError(t('Diese Adresse ist die Anmeldung eines Mitarbeiterkontos. Ändern kann sie nur, wer sich damit anmeldet, unter „Mein Konto“. Nichts wurde gespeichert.',
                              'This address is the login of a staff account. Only whoever signs in with it can change it, under “My account”. Nothing was saved.'));
}

/**
 * What was typed into the one box of the sign-in and „vergessen" pages, as
 * [kind, value] (ADR 0020, §3).
 *
 * An '@' anywhere makes it an address, normalised the way addresses are stored;
 * anything else is a username, normalised the way usernames are. A username can
 * never hold an '@' - its alphabet is a-z, 0-9, dot and hyphen - so the two
 * kinds cannot overlap and the shape alone decides. One derivation for the
 * lookup and for the throttle: two spellings of it would mean a sign-in that
 * succeeds while its counter keeps climbing under a key nothing ever clears.
 */
function attempted_sign_in(): array {
    $typed=post('username');
    return str_contains($typed,'@') ? ['address',email_normalised($typed)] : ['username',username_normalised($typed)];
}

/**
 * The bucket attempts at one account are counted into.
 *
 * Spelled in one place, for the same reason rate_limit_bucket() is: counting
 * and clearing have to agree, and a clear that spelled the key differently
 * would empty nothing, silently, and leave the family locked out.
 */
function account_identity(int $accountId): string { return 'account:'.$accountId; }

/*
 * The buckets a sign-in and „vergessen" count into: the typed value, normalised
 * - and deliberately not the row it names. Do not "improve" this by looking the
 * account up first. That lookup is what made the form answer, through the
 * throttle, whether an address had an account (ADR 0007), and now that a login
 * has two names it would add a second oracle: fill the username's bucket, try
 * the address once, and the answer says whether the two are one login (ADR
 * 0020, §3). A known value and an unknown one are counted exactly alike.
 *
 * The cost is accepted: a login has two buckets, so 10 + 10 guesses a quarter
 * hour for whoever knows both names, inside the per-IP 60; and locking
 * lena.mueller leaves her address open, and the other way round (S3, softened).
 * Each spelled once, here, because counting and clearing have to agree.
 */
function username_identity(string $username): string { return 'username:'.$username; }
function address_identity(string $address): string { return 'address:'.$address; }
/** The bucket for one attempted_sign_in(). */
function sign_in_identity(array $attempt): string {
    [$kind,$value]=$attempt;
    return $kind==='address' ? address_identity($value) : username_identity($value);
}

function handle_post(): array {
    $action=post('action');
    if(!hash_equals(csrf(),post('csrf'))) throw new UserError(t('Die Sitzung ist abgelaufen. Seite neu laden.','Your session expired. Reload the page.'));
    $ip=$_SERVER['REMOTE_ADDR']??'local';
    if(in_array($action,['login','forgot','activate'],true)) throttle('auth-ip',$ip,60);
    if($action==='login') {
        throttle('login',sign_in_identity(attempted_sign_in()),10);
        // Made here, outside the action's transaction, on the one occasion it
        // is missing: inside, a refused sign-in would roll it back and the next
        // one would make it again (sign_in_dummy_hash()).
        sign_in_dummy_hash();
    }
    // Both counted on every request: three an hour per typed name, so nobody
    // can fill a family's inbox or keep replacing the link they are about to
    // use; ten an hour from one IP, so nobody can walk through many names (S7).
    // A login has two names, so up to six mails an hour can reach one mailbox.
    if($action==='forgot') throttle_all([['forgot',sign_in_identity(attempted_sign_in()),3,3600],['forgot-ip',$ip,10,3600]]);
    if(in_array($action,['password_change','email_change','username_change'],true)) {
        $actor=require_user();throttle('account-security',(string)$actor['id'],10);
    }
    $request=post('request_id');
    // One transaction around the whole action: it either happens or it does not.
    $result=transactional(function() use ($request,$action) {
        claim_request($request);
        return dispatch_action($action);
    });
    forget_attempts_after_success($action);
    return $result;
}

/**
 * Forget the sign-in attempts counted against a login, now that the request
 * has proved who was making them.
 *
 * The counting above has to happen before the password is checked, because at
 * that moment the outcome is not known. Nothing undid it, so a family whose
 * children share one phone reached ten correct sign-ins in a quarter of an hour
 * and was told "Zu viele Versuche", with no way out but waiting.
 *
 * Two kinds of request prove who is asking, and they are exactly the two that
 * end in sign_in(): a password typed correctly, and a one-time link opened out
 * of the mailbox the address belongs to. Every purpose such a link carries
 * proves it - an invitation accepted, a password reset, a changed address
 * confirmed - so all three clear the buckets rather than 'reset' alone. Without
 * the link half, the reset sent to a locked-out family lets them in once and
 * leaves them locked out of the next sign-in until the window runs down.
 *
 * Both of the proven login's buckets are cleared - its username's and its
 * address's - because either may be what was being typed (ADR 0020, §3). A
 * wrong password stays counted; the per-IP limit is never cleared, because one
 * valid account must not be able to refresh the limit that slows down guessing
 * at all the others; and neither 'forgot' limit is ever cleared, since typing a
 * name proves nothing about who typed it and clearing it would hand anybody an
 * unlimited mailer pointed at one family's inbox.
 *
 * It runs after the transaction rather than inside the action because this is
 * the first moment at which "the attempt succeeded" is a fact - throttle_clear()
 * has its own reason for needing to be out here.
 */
function forget_attempts_after_success(string $action): void {
    if(!in_array($action,['login','activate'],true)) return;
    // Both branches ask the session the same question instead of reading
    // success out of the fact that nothing threw: an action that failed threw
    // out of handle_post() long before this line, but that is a fact about
    // another file and this one should not depend on knowing it.
    $who=current_user();
    if(!$who) return;
    throttle_clear('login',username_identity((string)$who['username']));
    throttle_clear('login',address_identity(email_normalised((string)$who['email'])));
}

function dispatch_action(string $action): array {
    switch($action) {
    case 'login':
        /* By username or by address, in one box (ADR 0020, §3). The lookup is
           account_for_sign_in(), which looks nothing up that fails its format
           and uses no row that does not match exactly what was typed.

           Every refusal runs exactly one password_verify(): against the login's
           own hash where there is one - a suspended login, an invitation
           somebody already set a password on - and against sign_in_dummy_hash()
           where there is none - nothing found, a value that cannot be a name, an
           invitation not yet accepted. So how long the answer takes does not
           say whether the login exists, and the words are the same for every
           failure. Nothing is hashed on the way to a refusal. */
        $a=account_for_sign_in(...attempted_sign_in());
        $hash=(string)($a['password_hash']??'');
        $real=$hash!=='';
        if(!password_verify(post('password'),$real?$hash:sign_in_dummy_hash()) || !$real || $a['state']!=='active' || !$a['verified_at'])
            throw new UserError(t('Anmeldung nicht möglich. Bitte Benutzername oder E-Mail-Adresse und Passwort prüfen. Noch nicht eingerichtet? Dann zuerst den Link in der Einladung öffnen.',
                                  'Could not sign you in. Please check your username or email address and your password. Not set up yet? Open the link in your invitation first.'));
        if(password_needs_rehash($hash,PASSWORD_DEFAULT)) run('UPDATE accounts SET password_hash=? WHERE id=?',[password_hash(post('password'),PASSWORD_DEFAULT),$a['id']]);
        sign_in($a);
        return landing_after_sign_in($a);
    case 'logout':
        $_SESSION=[]; session_regenerate_id(true); current_user(true);
        // Profile pictures are kept by the browser for up to a week
        // (avatar_cache_control()), so ask it to forget them along with
        // everything else it cached here. Best effort only: Safari has not
        // always honoured Clear-Site-Data, and no browser has to. What
        // actually bounds a copy left on a borrowed phone is the week.
        // headers_sent() is only ever true where output began before the
        // action ran - the test runner, which prints as it goes; a real
        // request prints nothing before its redirect.
        if(!headers_sent()) header('Clear-Site-Data: "cache"');
        return ['login',[]];
    case 'forgot':
        /* „Passwort vergessen", by username or by address (ADR 0020, §4). The
           answer is the same whatever happened, and names no address, so the
           page does not say whether a login exists (ADR 0019 closed ADR 0007's
           channel; this keeps it closed). The mail goes to the address as
           stored, never to what was typed [S1].

           An active login gets a reset link. An invited one gets its invitation
           again: that is its way in, and accepting it records the privacy
           acknowledgement a reset would skip - sending nothing meant a call to
           the trainer. A suspended login gets nothing. */
        $a=account_for_sign_in(...attempted_sign_in());
        if($a && account_mail_ready()) {
            if($a['state']==='active' && $a['verified_at']) send_account_token($a,'reset');
            elseif($a['state']==='invited') { cancel_account_mail((int)$a['id']); send_account_token($a,'invite'); }
        }
        flash(t('Wenn es dazu einen Zugang gibt, ist eine E-Mail an dessen Adresse unterwegs.',
                'If there is a login for it, an email is on its way to its address.'));
        return ['forgot',[]];
    case 'activate':
        $r=token_record($_SESSION['activation_hash']??'',true);
        if(!$r || $r['state']==='suspended') throw new UserError(t('Dieser Link ist ungültig oder abgelaufen. Bitte eine neue Einladung bzw. einen neuen Link anfordern.','This link is invalid or expired. Please request a new invitation or reset link.'));
        if($r['purpose']==='invite') {
            if(!post('privacy_seen') || !setting('privacy_ready',false)) throw new UserError(t('Bitte die Datenschutzhinweise lesen und bestätigen.','Please read and acknowledge the privacy notice.'));
            $pass=strong_password(post('password'));
            if($pass!==post('password_confirm')) throw new UserError(t('Die Passwörter stimmen nicht überein.','Passwords do not match.'));
            // Whoever this browser was signed in as is not who is setting up
            // this login, and must not be named in the change log as having
            // chosen its username. The sign_in() below replaces the session in
            // any case.
            if(current_user()) { unset($_SESSION['user_id'],$_SESSION['auth_version'],$_SESSION['impersonator_id']); current_user(true); }
            // The username, as the person chose it on this page (ADR 0020, §2).
            // The link proves the holder, so no current password is asked. A
            // taken name comes back to this page with what was typed, having
            // activated nothing; the password boxes come back empty, because
            // is_secret_field() never holds them.
            if(post('username')!=='' && change_own_username(one('SELECT * FROM accounts WHERE id=?',[$r['account_id']]),post('username'))==='taken') {
                flash(t('Dieser Benutzername ist schon vergeben. Bitte einen anderen wählen.','That username is already taken. Please choose another one.'),'error');
                remember_input('activate');
                return ['activate',[]];
            }
            run("UPDATE accounts SET password_hash=?,state='active',verified_at=?,auth_version=auth_version+1,privacy_version=?,newsletter=?,notifications=?,locale=? WHERE id=?",[password_hash($pass,PASSWORD_DEFAULT),now(),notice_version(),post('newsletter')?1:0,post('notifications')?1:0,locale(),$r['account_id']]);
            record_consent((int)$r['account_id'],'privacy_acknowledged',true);
            record_consent((int)$r['account_id'],'newsletter',(bool)post('newsletter'));
            record_consent((int)$r['account_id'],'notifications',(bool)post('notifications'));
            record_consent((int)$r['account_id'],'payment_notices',true);
        } elseif($r['purpose']==='reset') {
            if($r['state']!=='active') throw new UserError('Invalid account');
            $pass=strong_password(post('password'));
            if($pass!==post('password_confirm')) throw new UserError(t('Die Passwörter stimmen nicht überein.','Passwords do not match.'));
            run('UPDATE accounts SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$r['account_id']]);
        } elseif($r['purpose']==='email') {
            $u=require_user();
            if((int)$u['id']!==(int)$r['account_id']) throw new UserError(t('Bitte mit dem zugehörigen Konto anmelden.','Please sign in to the matching account.'));
            change_account_email((int)$r['account_id'],(string)$r['target_email']);
            // Confirmed from the new mailbox, which is what proves it is theirs.
            run('UPDATE accounts SET verified_at=? WHERE id=?',[now(),$r['account_id']]);
        } else throw new UserError('Invalid token');
        run('DELETE FROM auth_tokens WHERE account_id=?',[$r['account_id']]);
        unset($_SESSION['activation_hash']);
        $signed=one('SELECT * FROM accounts WHERE id=?',[$r['account_id']]);
        sign_in($signed);
        audit('account.verified','account',(int)$r['account_id']);
        // Written down after sign_in(), so the actor is the holder of the link
        // rather than whoever this browser was signed in as. Mein Konto and the
        // access card list it for a fortnight (password_resets_for()).
        if($r['purpose']==='reset') audit('account.password_reset','account',(int)$r['account_id']);
        if($r['purpose']==='invite') {
            // Both names that sign in, said once more now that they are final.
            flash(t('Dein Konto ist bereit. Du meldest dich mit dem Benutzernamen ','Your account is ready. You sign in with the username ').$signed['username']
                .t(' oder mit der E-Mail-Adresse ',' or with the email address ').$signed['email'].t(' an.','.'));
            // A family that has only just arrived is shown what is still missing
            // on their own page (family_next_steps()), once; every later
            // sign-in lands where landing_after_sign_in() says.
            $own=login_student_id((int)$signed['id']);
            if($own && family_next_steps($own)) return ['student',['id'=>$own]];
        } elseif($r['purpose']==='reset') {
            flash(t('Dein neues Passwort gilt ab sofort. Dein Benutzername: ','Your new password works from now on. Your username: ').$signed['username']);
        } else {
            flash(t('Deine neue E-Mail-Adresse ist bestätigt. Du meldest dich mit ihr oder mit deinem Benutzernamen an.','Your new email address is confirmed. You sign in with it or with your username.'));
        }
        return landing_after_sign_in($signed);
    case 'unsubscribe':
        $id=(int)post('account');$category=post('category');
        if(!valid_unsubscribe($id,$category,post('signature'))) throw new UserError(t('Ungültiger Abmeldelink.','Invalid unsubscribe link.'));
        if(one('SELECT id FROM accounts WHERE id=?',[$id])) {
            $column=unsubscribe_categories()[$category] ?? throw new UserError(t('Ungültiger Abmeldelink.','Invalid unsubscribe link.'));
            run('UPDATE accounts SET '.$column.'=0 WHERE id=?',[$id]); record_consent($id,$category,false);
            run("UPDATE mail_jobs SET status='cancelled',payload='' WHERE account_id=? AND category=? AND status IN ('queued','failed')",[$id,$category]);
        }
        flash(t('Du wurdest für diese E-Mails abgemeldet.','You have unsubscribed from these emails.')); return ['login',[]];
    case 'account_invite':
        /* A trainer's or an administrator's login, by invitation - the only way
           one is made in the portal (ADR 0020, §5). Nobody sets another person's
           password: they choose their own on the page the link opens. */
        $u=require_admin(); $role=choose(staff_role_posted(),assignable_roles($u));
        $email=email_value(required_text('email',254)); $name=required_text('name');
        refuse_address_in_use($email);
        $username=username_for_new_account(...full_name_parts($name));
        run('INSERT INTO accounts (name,email,username,role,locale,created_at) VALUES (?,?,?,?,?,?)',[$name,$email,$username,$role,choose(post('locale','de'),['de','en']),now()]);
        $id=(int)db()->lastInsertId();
        send_account_token(one('SELECT * FROM accounts WHERE id=?',[$id]),'invite');audit('account.invited','account',$id);
        flash(t('Konto angelegt. Die Einladung liegt im Postausgang.','Account created. The invitation is in the outbox.'));return ['accounts',[]];
    case 'student_invite':
        /* The access card's button. A post from a page that still offers
           mode=direct and a password box gets an ordinary invitation: nothing
           here reads either (ADR 0020, §5). */
        require_staff();
        $s=lock_row('students',(int)student((int)post('student_id'))['id']);
        $made=invite_student($s);
        flash(t('Einladung liegt im Postausgang. Benutzername: ','The invitation is in the outbox. Username: ').$made['username']);
        return ['student',['id'=>$s['id']]];
    case 'account_state':
        $u=require_staff();$id=(int)post('id');$mode=choose(post('mode'),['suspend','restore','delete','reinvite','reset_link']);
        $a=one('SELECT * FROM accounts WHERE id=? FOR UPDATE',[$id]);
        // Nobody acts on their own login here - that is Mein Konto or
        // „vergessen" - and a trainer only on a student's.
        if(!$a || (int)$u['id']===$id || ($u['role']!=='admin' && $a['role']!=='student')) throw new UserError(t('Dieses Konto kann hier nicht geändert werden.','This account cannot be changed here.'));
        // Lock all administrators so concurrent requests cannot remove the last one.
        $admins=rows("SELECT id FROM accounts WHERE role='admin' AND state='active' FOR UPDATE");
        if($a['role']==='admin' && $a['state']==='active' && count($admins)<=1 && in_array($mode,['suspend','delete'],true)) throw new UserError(t('Der letzte Administrator muss erhalten bleiben.','The last administrator must remain active.'));
        // A student's login is managed from that student's page, so that is where
        // she lands again. Looked up now, because after a delete the foreign key
        // has already cut the link and there is nobody left to find.
        $studentId=$a['role']==='student'?login_student_id($id):0;
        $said=t('Konto aktualisiert.','Account updated.');
        if($mode==='reinvite') {
            if($a['state']!=='invited') throw new UserError(t('Nur offene Einladungen können erneut versendet werden.','Only pending invitations can be resent.'));
            cancel_account_mail($id);send_account_token($a,'invite');
        } elseif($mode==='reset_link') {
            /* A link to set a new password, mailed to the login's own address
               (ADR 0020, §5). Staff see neither the password nor the link: the
               outbox never shows a security mail's body. Only for a login in
               use - an invitation is sent again instead, a suspended login is
               restored first. */
            if($a['state']!=='active' || !$a['verified_at'])
                throw new UserError(t('Ein Link für ein neues Passwort geht nur an einen Zugang, der in Gebrauch ist. Eine offene Einladung erneut senden; einen gesperrten Zugang zuerst entsperren.',
                                      'A link for a new password only goes to a login in use. Send an open invitation again; restore a suspended login first.'));
            send_account_token($a,'reset');
            $said=t('Ein Link für ein neues Passwort ist an die Adresse des Zugangs unterwegs. Er gilt eine Stunde.','A link for a new password is on its way to the login’s address. It is valid for one hour.');
        } else {
            run('DELETE FROM auth_tokens WHERE account_id=?',[$id]);cancel_account_mail($id);
            if($mode==='delete') {
                // The username, which says which login this is (ADR 0019, §9;
                // kept by 0020, §8).
                if(username_normalised(post('confirmation'))!==$a['username']) throw new UserError(t('Zum Löschen den Benutzernamen eingeben.','Enter the username to delete the account.'));
                run('DELETE FROM mail_jobs WHERE account_id=?',[$id]);
                run('DELETE FROM accounts WHERE id=?',[$id]);
            } else run('UPDATE accounts SET state=?,auth_version=auth_version+1 WHERE id=?',[$mode==='suspend'?'suspended':($a['verified_at']?'active':'invited'),$id]);
        }
        audit('account.'.$mode,'account',$id);flash($said);
        return $studentId?['student',['id'=>$studentId]]:['accounts',[]];
    case 'student_save':
        $u=require_user();$id=(int)post('id');$existing=$id?student($id):null;
        if(!$existing) require_staff();
        $first=required_text('first_name',100);$last=required_text('last_name',100);$birth=date_value(post('birth_date'));
        // One line, the way the paper form asks it, because it is typed once and
        // printed once and never sorted on. Her own number rather than an
        // emergency contact's: for an adult member those are the same person,
        // and listing yourself as the person to ring is not a record anybody
        // should have to keep. The family writes both as well (ADR 0020, §7):
        // the address is the one their next invoice is made out to.
        $address=text_limit('address',200);$phone=text_limit('phone',60);
        $stale=fn()=>new UserError(t('Der Eintrag wurde inzwischen geändert. Bitte neu laden und die Änderungen vergleichen.','This record has changed. Reload it and compare the changes before saving.'));
        $made=null;$readdress=false;
        if(is_staff($u)) {
            // account_id is neither read nor written here: a login is given by
            // invite_student() and taken away by deleting it, nothing else
            // (ADR 0010). A page from before that rule may still post it.
            //
            // Nor are tariff_id, price_cents and price_note (ADR 0011). What a
            // child pays is decided per course, on the enrolment, and that is
            // what billing reads; the student's own columns bill nobody. They
            // keep whatever they hold, because a page from before still posts
            // the old box, and a form without it would blank them on every save.
            $status=post('status');if(!isset(statuses()[$status]) && !($existing && $status===$existing['status'])) throw new UserError(t('Bitte einen Status auswählen.','Please choose a status.'));
            // A child is always in a level, so an unanswered field means the
            // default rather than nothing. An age group is the opposite: blank is
            // the normal answer and means "work it out from the date of birth",
            // which keeps being right as they have birthdays.
            $levelId=reference_or_null('levels','level_id') ?? (int)(level_default()['id'] ?? 0) ?: null;
            $ageGroupId=reference_or_null('age_groups','age_group_id');
            $join=date_value(post('joined_on'));$end=date_value(post('ended_on'));date_range($join,$end);
            // Where the portal writes to this student: the invitation, invoices,
            // reminders - and, once they have a login, what they sign in with.
            // Optional while the record is being set up. Once the student has a
            // login it is that login's address, and this copy is kept equal to it.
            $posted=post('email')!==''?email_value(post('email')):'';
            $account=$existing && $existing['account_id']?lock_row('accounts',(int)$existing['account_id']):null;
            if(!$account) {
                // Nothing signs in with it until invite_student() makes a login,
                // so a brother and a sister without one may share it. A new or
                // changed address that is already somebody's login is refused
                // (ADR 0010, restored by 0020 §1); one already stored is
                // tolerated, so her other edits still save.
                $email=$posted;
                if($email!=='' && $email!==email_normalised((string)($existing['email']??''))) refuse_address_in_use($email);
            } else {
                $email=(string)$account['email'];
                // Nothing posted means nothing to change: a login always has an
                // address, so an empty or missing field cannot be a request to
                // remove it.
                if($posted!=='' && $posted!==email_normalised($email)) {
                    refuse_unless_student_login($account);
                    // Refused, not ignored: a page opened before the family signed
                    // up still shows an editable field, and a save that quietly
                    // dropped the new address would say "saved" and mean less.
                    if($account['state']!=='invited' || $account['verified_at'])
                        throw new UserError(t('Die Adresse ist die Anmeldung dieses Kontos und kann hier nicht geändert werden. Wer sich damit anmeldet, ändert sie selbst unter „Mein Konto“, mit einem Bestätigungslink an die neue Adresse. Nichts wurde gespeichert.',
                                              'The address is this account’s login and cannot be changed here. Whoever signs in with it changes it under “My account”, with a confirmation link sent to the new address. Nothing was saved.'));
                    // Asked again by change_account_email() as it writes; asked
                    // here so the refusal comes before anything is written.
                    refuse_address_in_use($posted,(int)$account['id']);
                    if(!account_mail_ready())
                        throw new UserError(t('Die neue Adresse lässt sich erst eintragen, wenn die Einladung dorthin verschickt werden kann. ',
                                              'The new address can only be entered once the invitation can be sent there. ')
                            .account_mail_missing().t(' Nichts wurde gespeichert.',' Nothing was saved.'));
                    $email=$posted; $readdress=true;
                }
            }
            // „Gleich einladen" on the create form (ADR 0020, §6): every refusal
            // before the first write, so a refused invitation leaves no student
            // behind either. An address that is somebody's login was refused
            // just above.
            $invite=!$existing && post('invite')==='1';
            if($invite) {
                if($email==='')
                    throw new UserError(t('Zum Einladen braucht es die eigene E-Mail-Adresse der Schülerin oder des Schülers.',
                                          'To invite them, enter the student’s own email address.'));
                if(!account_mail_ready())
                    throw new UserError(t('Eine Einladung lässt sich noch nicht verschicken. ','An invitation cannot be sent yet. ').account_mail_missing()
                        .t(' Ohne „Gleich einladen“ wird jetzt gespeichert und später eingeladen.',' Without “Invite now” the student is saved now and invited later.'));
            }
            $args=[$first,$last,$email,$address,$phone,$birth,$join,$end,$status,$levelId,$ageGroupId,text_limit('internal_notes',12000),now()];
            if($id) {
                // One tracked change for the whole save, custom fields included,
                // so it is one line in the change log and a stale form is refused
                // as a whole. The login moves inside it too, so the log shows the
                // new address on the student it belongs to.
                tracked('students',$id,$first.' '.$last,function() use ($args,$id,$account,$readdress,$email,$stale) {
                    $updated=run('UPDATE students SET first_name=?,last_name=?,email=?,address=?,phone=?,birth_date=?,joined_on=?,ended_on=?,status=?,level_id=?,age_group_id=?,internal_notes=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?',[...$args,$id,(int)post('revision')]);
                    if(!$updated->rowCount()) throw $stale();
                    save_custom_fields($id,false);
                    if($readdress) change_account_email((int)$account['id'],$email);
                });
                // The first invitation went to the old address and has just been
                // made useless; this is the one that works.
                if($readdress) {
                    send_account_token(one('SELECT * FROM accounts WHERE id=?',[(int)$account['id']]),'invite');
                    audit('account.readdressed','account',(int)$account['id']);
                }
            } else {
                $id=tracked_insert('students',$first.' '.$last,function() use ($args) {
                    run('INSERT INTO students (first_name,last_name,email,address,phone,birth_date,joined_on,ended_on,status,level_id,age_group_id,internal_notes,updated_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$args,now()]);
                    $new=(int)db()->lastInsertId();
                    save_custom_fields($new,true);
                    return $new;
                });
                if($invite) $made=invite_student(one('SELECT * FROM students WHERE id=?',[$id]));
            }
        } else {
            /* A family completing its own details (ADR 0020, §7): names, birth
               date, postal address and phone, and the custom fields at 'edit'
               (save_custom_fields() skips the rest). Nothing else, whatever is
               posted. Authorised by student()'s scoping above and nothing else,
               and tracked like every other change to a student, so she sees it
               in the change log with the family as the actor. */
            // A page that does not carry the two boxes - one opened before
            // families were shown them - leaves both as they are: nothing
            // posted is nothing to change, and an empty box that was posted
            // still empties it.
            if(!array_key_exists('address',$_POST)) $address=(string)$existing['address'];
            if(!array_key_exists('phone',$_POST)) $phone=(string)$existing['phone'];
            tracked('students',$id,$first.' '.$last,function() use ($first,$last,$birth,$address,$phone,$id,$stale) {
                $updated=run('UPDATE students SET first_name=?,last_name=?,birth_date=?,address=?,phone=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?',[$first,$last,$birth,$address,$phone,now(),$id,(int)post('revision')]);
                if(!$updated->rowCount()) throw $stale();
                save_custom_fields($id,false);
            });
        }
        audit('student.saved','student',$id);
        flash(t('Schüler gespeichert.','Student saved.')
            .($readdress?' '.t('Die Einladung ist an die neue Adresse unterwegs; der Link an die alte gilt nicht mehr.','The invitation is on its way to the new address; the link sent to the old one no longer works.'):'')
            .($made?' '.t('Die Einladung liegt im Postausgang. Benutzername: ','The invitation is in the outbox. Username: ').$made['username']:''));
        return ['student',['id'=>$id]];
    case 'student_delete':
        require_staff();$s=student((int)post('id'));
        if(post('confirmation')!==$s['first_name'].' '.$s['last_name']) throw new UserError(t('Bitte den vollständigen Namen eingeben.','Please enter the full name.'));
        if(scalar('SELECT COUNT(*) FROM charges WHERE student_id=?',[$s['id']])) throw new UserError(t('Es sind Beiträge vorhanden. Mitgliedschaft stattdessen beenden; Zahlungsdaten bleiben erhalten.','Charges exist. End the membership instead to retain payment records.'));
        tracked('students',(int)$s['id'],$s['first_name'].' '.$s['last_name'],fn()=>run('DELETE FROM students WHERE id=?',[$s['id']]),'delete');
        audit('student.deleted','student',(int)$s['id']);
        // The change log keeps the deleted row to read, not to restore (app/history.php).
        flash(t('Schüler gelöscht. Unter „Änderungen“ steht, was gelöscht wurde; wiederherstellen lässt es sich nicht.',
                'Student deleted. “Changes” shows what was deleted; it cannot be restored.'));return ['students',[]];
    /* Contacts: a child always has one, and one of them is the one to try first.
       They are people to ring and nothing else now - a phone number is what
       makes one useful, and an email address on one is a convenience, not the
       address the portal writes to. That one is on the child. The rules live
       here, in the actions that can break them, rather than in the page that
       happens to show them. */
    case 'contact_add':
        $s=student((int)post('student_id'));$email=contact_email();
        $standard=!student_contacts((int)$s['id']) || post('is_primary');   // the first one is it, without being asked
        $owner=required_text('owner_name');$relation=required_text('relation_label',100);$phone=text_limit('phone',80);
        // Moving the standard off the others is bookkeeping, not a change
        // anybody made to them, so it is not tracked (ADR 0020, §7).
        if($standard) run('UPDATE contacts SET is_primary=0 WHERE student_id=?',[$s['id']]);
        tracked_insert('contacts',contact_history_label($s,$owner),function() use ($s,$owner,$relation,$phone,$email,$standard) {
            run('INSERT INTO contacts (student_id,owner_name,relation_label,phone,email,is_primary) VALUES (?,?,?,?,?,?)',[$s['id'],$owner,$relation,$phone,$email,$standard?1:0]);
            return (int)db()->lastInsertId();
        });
        audit('contact.added','student',(int)$s['id']);return ['student',['id'=>$s['id'],'tab'=>'contacts']];
    case 'contact_delete':
        $s=student((int)post('student_id'));
        $contact=one('SELECT * FROM contacts WHERE id=? AND student_id=?',[(int)post('id'),$s['id']]);
        if(!$contact) throw new NotFound(t('Kontakt nicht gefunden.','Contact not found.'));
        if(count(student_contacts((int)$s['id']))<2) throw new UserError(t('Jedes Kind braucht mindestens eine Kontaktperson. Trage zuerst eine andere ein.','Every child needs at least one contact person. Enter another one first.'));
        // The deletion line keeps the whole row: that is how she adds it back.
        tracked('contacts',(int)$contact['id'],contact_history_label($s,(string)$contact['owner_name']),
            fn()=>run('DELETE FROM contacts WHERE id=? AND student_id=?',[(int)$contact['id'],$s['id']]),'delete');
        // Removing the standard contact must not leave the child without one.
        // Bookkeeping, like moving it in contact_save: not tracked.
        if((int)$contact['is_primary'] && ($next=one('SELECT id FROM contacts WHERE student_id=? ORDER BY id LIMIT 1',[$s['id']])))
            run('UPDATE contacts SET is_primary=1 WHERE id=?',[(int)$next['id']]);
        audit('contact.deleted','student',(int)$s['id']);return ['student',['id'=>$s['id'],'tab'=>'contacts']];
    case 'contact_save':
        $s=student((int)post('student_id'));$email=contact_email();
        $contact=one('SELECT * FROM contacts WHERE id=? AND student_id=?',[(int)post('id'),$s['id']]);
        if(!$contact) throw new NotFound(t('Kontakt nicht gefunden.','Contact not found.'));
        // Unticking the box does not take the standard away: another contact is
        // made the standard one instead, so the child is never left without.
        $standard=(int)$contact['is_primary']===1 || (bool)post('is_primary');
        $owner=required_text('owner_name');$relation=required_text('relation_label',100);$phone=text_limit('phone',80);
        // The others lose the standard as bookkeeping, untracked; this one is
        // left out of that, so its own snapshot shows only what she changed.
        if($standard) run('UPDATE contacts SET is_primary=0 WHERE student_id=? AND id<>?',[$s['id'],(int)$contact['id']]);
        tracked('contacts',(int)$contact['id'],contact_history_label($s,$owner),
            fn()=>run('UPDATE contacts SET owner_name=?,relation_label=?,phone=?,email=?,is_primary=? WHERE id=? AND student_id=?',[$owner,$relation,$phone,$email,$standard?1:0,(int)$contact['id'],$s['id']]));
        audit('contact.updated','student',(int)$s['id']);return ['student',['id'=>$s['id'],'tab'=>'contacts']];
    case 'absence_add':
        $u=require_user();$s=student((int)post('student_id'));$from=date_value(post('starts_on'),true);$to=date_value(post('ends_on'),true);date_range($from,$to);
        $reason=choose(post('reason'),array_keys(reasons()));
        run('INSERT INTO absences (student_id,reason,starts_on,ends_on,created_by) VALUES (?,?,?,?,?)',[$s['id'],$reason,$from,$to,$u['id']]);audit('absence.added','student',(int)$s['id']);return ['student',['id'=>$s['id'],'tab'=>'absence']];
    case 'absence_delete':
        $s=student((int)post('student_id'));run('DELETE FROM absences WHERE id=? AND student_id=?',[(int)post('id'),$s['id']]);return ['student',['id'=>$s['id'],'tab'=>'absence']];
    case 'charge_add':
        require_staff();$s=student((int)post('student_id'));$from=date_value(post('period_from'));$to=date_value(post('period_to'));date_range($from,$to);
        if((bool)$from!==(bool)$to) throw new UserError(t('Für einen Zeitraum bitte Anfang und Ende angeben.','Enter both dates for a coverage period.'));
        run('INSERT INTO charges (student_id,label,amount_cents,period_from,period_to,due_on,created_at) VALUES (?,?,?,?,?,?,?)',[$s['id'],required_text('label'),cents(post('amount')),$from,$to,date_value(post('due_on'),true),now()]);
        audit('charge.created','charge',(int)db()->lastInsertId());flash(t('Beitrag angelegt.','Charge created.'));return ['student',['id'=>$s['id'],'tab'=>'payments']];
    case 'charge_cancel':
        require_staff();$c=one('SELECT * FROM charges WHERE id=? FOR UPDATE',[(int)post('id')]);if(!$c)throw new UserError('Not found');
        if(scalar('SELECT COUNT(*) FROM payments WHERE charge_id=? AND voided=0',[$c['id']])) throw new UserError(t('Zugehörige Zahlungen zuerst stornieren.','Void associated payments first.'));
        tracked('charges',(int)$c['id'],(string)$c['label'],fn()=>run('UPDATE charges SET cancelled=1 WHERE id=?',[$c['id']]));
        audit('charge.cancelled','charge',(int)$c['id']);return ['student',['id'=>$c['student_id'],'tab'=>'payments']];
    case 'payment_add':
        $u=require_staff();$c=one('SELECT * FROM charges WHERE id=? AND cancelled=0 FOR UPDATE',[(int)post('charge_id')]);if(!$c)throw new UserError('Not found');
        $amount=cents(post('amount'),false);
        $allocated=(int)scalar('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE charge_id=? AND voided=0',[$c['id']]);
        if($amount+$allocated>(int)$c['amount_cents']) throw new UserError(t('Der Betrag übersteigt den noch nicht erfassten Beitrag. Auch unbestätigte Zahlungen zählen hier.','The amount exceeds the charge not yet recorded, including unconfirmed payments.'));
        $confirmed=(bool)post('confirmed');
        run('INSERT INTO payments (charge_id,amount_cents,paid_on,method,note,confirmed_by,confirmed_at) VALUES (?,?,?,?,?,?,?)',[$c['id'],$amount,date_value(post('paid_on'),true),choose(post('method'),setting('payment_methods',['Überweisung','Bar'])),text_limit('note'),$confirmed?$u['id']:null,$confirmed?now():null]);
        audit('payment.recorded','payment',(int)db()->lastInsertId());flash(t('Zahlung erfasst.','Payment recorded.'));return ['student',['id'=>$c['student_id'],'tab'=>'payments']];
    case 'payment_state':
        $u=require_staff();$p=one('SELECT p.*,c.student_id FROM payments p JOIN charges c ON c.id=p.charge_id WHERE p.id=? FOR UPDATE',[(int)post('id')]);if(!$p || $p['voided'])throw new UserError('Not found');
        $mode=choose(post('mode'),['confirm','void']);
        tracked('payments',(int)$p['id'],money((int)$p['amount_cents']),function() use ($mode,$u,$p) {
            if($mode==='confirm')run('UPDATE payments SET confirmed_at=?,confirmed_by=? WHERE id=?',[now(),$u['id'],$p['id']]);
            else run('UPDATE payments SET voided=1 WHERE id=?',[$p['id']]);
        });
        audit('payment.'.$mode,'payment',(int)$p['id']);return ['student',['id'=>$p['student_id'],'tab'=>'payments']];
    }
    return dispatch_config($action);
}
