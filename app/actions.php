<?php
declare(strict_types=1);

/**
 * What she is told when an address is already somebody's login.
 *
 * One login belongs to one student (ADR 0010), so a brother or sister cannot
 * be given the address a sibling already signs in with. Said the same way from
 * every place that refuses it, and said before anything is written: the
 * database's own unique index would refuse too, but "Integrity constraint
 * violation" is not something she can act on.
 */
function own_address_needed(string $email): string {
    return t('Jede Schülerin und jeder Schüler braucht eine eigene E-Mail-Adresse. ',
             'Every student needs an email address of their own. ')
        .$email.t(' ist schon die Anmeldung eines anderen Kontos. Bitte eine andere Adresse eintragen.',
                  ' is already the login of another account. Please enter a different address.');
}

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
 * Change the address an account signs in with. The only place that does.
 *
 * Both copies change together - the login on the account and the address on
 * its student, which is where invoices and reminders go - so the two can never
 * disagree. Every session on the account ends (auth_version), every link
 * already sent stops working (a link to the old mailbox must not open the
 * account once the address has moved), and mail still waiting for the old
 * address is not sent.
 *
 * Refuses an address another account already holds, with a sentence rather
 * than the unique index's error: a family confirming a change can find that
 * somebody took the address between asking and clicking.
 *
 * Refuses, too, to move anybody else's staff or administrator login. Only a
 * student's login is ever re-addressed on somebody's behalf; a staff login
 * moves only when its holder confirms the link from the new mailbox.
 */
function change_login_address(int $accountId, string $email): void {
    $email=email_value($email);
    transactional(function() use ($accountId,$email): void {
        $account=lock_row('accounts',$accountId);
        if(!$account) throw new NotFound(t('Dieses Konto gibt es nicht mehr.','That account no longer exists.'));
        if((int)(current_user()['id']??0)!==$accountId) refuse_unless_student_login($account);
        $holder=account_using_email($email);
        if($holder && (int)$holder['id']!==$accountId) throw new UserError(own_address_needed($email));
        run('UPDATE accounts SET email=?,auth_version=auth_version+1 WHERE id=?',[$email,$accountId]);
        run('UPDATE students SET email=?,updated_at=?,revision=revision+1 WHERE account_id=?',[$email,now(),$accountId]);
        run('DELETE FROM auth_tokens WHERE account_id=?',[$accountId]);
        cancel_account_mail($accountId);
    });
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
 * The address typed into the sign-in or password-reset form.
 *
 * One derivation, because it is both the identity an attempt is counted
 * against and the address the account is looked up by. Two spellings of it
 * would mean a sign-in that succeeds while its counter keeps climbing under a
 * key nothing ever clears.
 */
function attempted_email(): string { return email_normalised(post('email')); }

/**
 * The bucket attempts at one account are counted into.
 *
 * Spelled in one place, for the same reason rate_limit_bucket() is: counting
 * and clearing have to agree, and a clear that spelled the key differently
 * would empty nothing, silently, and leave the family locked out.
 */
function account_identity(int $accountId): string { return 'account:'.$accountId; }

/**
 * What an attempt at an address is counted against.
 *
 * Not the typed string. accounts.email is compared by the database under
 * utf8mb4_unicode_ci, which folds case, accents, ss against ß, ligatures and
 * full-width letters alike: 'familie@beispiel.at', 'familie@beispiel.át' and
 * the ligature spelling are one row and three different PHP strings - measured
 * on MariaDB 10.11.14, not assumed. Counting the string therefore gave ten
 * guesses per spelling and as many spellings as anyone cared to invent, which
 * is a limit of ten an attacker walks straight past. The same trick minted
 * fresh reset-request buckets, and each new link invalidates the one a family
 * may be in the middle of using.
 *
 * So the address is resolved first and the attempt counted against the row the
 * database says it is, by its own rules rather than a PHP imitation of them -
 * no imitation of a collation reaches ligatures and full-width letters. An
 * address with no account is counted as what was typed, which is all there is.
 *
 * Read here, before the action's transaction opens, because the counters are
 * written on their own connection and that connection cannot write while a
 * transaction that has already written is open.
 */
function attempted_identity(): string {
    $id=scalar('SELECT id FROM accounts WHERE email=?',[attempted_email()]);
    return $id ? account_identity((int)$id) : attempted_email();
}

function handle_post(): array {
    $action=post('action');
    if(!hash_equals(csrf(),post('csrf'))) throw new UserError(t('Die Sitzung ist abgelaufen. Seite neu laden.','Your session expired. Reload the page.'));
    $ip=$_SERVER['REMOTE_ADDR']??'local';
    if(in_array($action,['login','forgot','activate'],true)) {
        throttle('auth-ip',$ip,60);
        if($action!=='activate') throttle($action,attempted_identity(),10);
    }
    if(in_array($action,['password_change','email_change'],true)) {
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
 * Forget the sign-in attempts counted against an address, now that the request
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
 * confirmed - so all three clear the bucket rather than 'reset' alone. Without
 * the link half, the reset sent to a locked-out family lets them in once and
 * leaves them locked out of the next sign-in until the window runs down.
 *
 * Only the bucket belonging to that proven identity is cleared. A wrong
 * password stays counted; the per-IP limit is never cleared, because one valid
 * account must not be able to refresh the limit that slows down guessing at all
 * the others; and 'forgot' is never cleared either, since typing an address
 * proves nothing about who typed it and clearing it would hand anybody an
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
    // The account, not the address: that is the key the attempts were counted
    // under, whichever of its spellings was typed at the time - an activation
    // empties the bucket the failed sign-ins before it filled.
    throttle_clear('login',account_identity((int)$who['id']));
}

function dispatch_action(string $action): array {
    switch($action) {
    case 'login':
        $a=one('SELECT * FROM accounts WHERE email=? FOR UPDATE',[attempted_email()]);
        $hash=$a['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if(!password_verify(post('password'),$hash) || !$a || $a['state']!=='active' || !$a['verified_at']) throw new UserError(t('Anmeldung nicht möglich. Zugangsdaten und Einladung prüfen.','Unable to sign in. Check your credentials and invitation.'));
        if(password_needs_rehash($hash,PASSWORD_DEFAULT)) run('UPDATE accounts SET password_hash=? WHERE id=?',[password_hash(post('password'),PASSWORD_DEFAULT),$a['id']]);
        sign_in($a); return landing_after_sign_in($a);
    case 'logout':
        $_SESSION=[]; session_regenerate_id(true); current_user(true); return ['login',[]];
    case 'forgot':
        $a=one("SELECT * FROM accounts WHERE email=? AND state='active' AND verified_at IS NOT NULL",[attempted_email()]);
        if($a && account_mail_ready()) send_account_token($a,'reset');
        flash(t('Wenn ein aktives Konto existiert, erhältst du einen Link per E-Mail.','If an active account exists, you will receive an email link.'));
        return ['forgot',[]];
    case 'activate':
        $r=token_record($_SESSION['activation_hash']??'',true);
        if(!$r || $r['state']==='suspended') throw new UserError(t('Dieser Link ist ungültig oder abgelaufen. Bitte eine neue Einladung bzw. einen neuen Link anfordern.','This link is invalid or expired. Please request a new invitation or reset link.'));
        if($r['purpose']==='invite') {
            if(!post('privacy_seen') || !setting('privacy_ready',false)) throw new UserError(t('Bitte die Datenschutzhinweise lesen und bestätigen.','Please read and acknowledge the privacy notice.'));
            $pass=strong_password(post('password'));
            if($pass!==post('password_confirm')) throw new UserError(t('Die Passwörter stimmen nicht überein.','Passwords do not match.'));
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
            change_login_address((int)$r['account_id'],(string)$r['target_email']);
            // Confirmed from the new mailbox, which is what proves it is theirs.
            run('UPDATE accounts SET verified_at=? WHERE id=?',[now(),$r['account_id']]);
        } else throw new UserError('Invalid token');
        run('DELETE FROM auth_tokens WHERE account_id=?',[$r['account_id']]);
        unset($_SESSION['activation_hash']);
        $signed=one('SELECT * FROM accounts WHERE id=?',[$r['account_id']]);
        sign_in($signed);
        audit('account.verified','account',(int)$r['account_id']);
        flash(t('Dein Konto ist bereit.','Your account is ready.')); return landing_after_sign_in($signed);
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
        $u=require_admin(); $role=choose(staff_role_posted(),assignable_roles($u));
        $email=email_value(required_text('email',254));
        if(account_using_email($email))
            throw new UserError(t('Diese Adresse hat schon ein Konto.','That address already has an account.'));
        run('INSERT INTO accounts (name,email,role,locale,created_at) VALUES (?,?,?,?,?)',[required_text('name'),$email,$role,choose(post('locale','de'),['de','en']),now()]);
        $id=(int)db()->lastInsertId();
        send_account_token(one('SELECT * FROM accounts WHERE id=?',[$id]),'invite');audit('account.invited','account',$id);
        flash(t('Konto angelegt. Die Einladung liegt im Postausgang.','Account created. The invitation is in the outbox.'));return ['accounts',[]];
    /* A login made here and now, with a password typed rather than emailed.
       Inviting needs tested SMTP and a released privacy notice, which is right
       for a real family and wrong for every other reason somebody needs an
       account: trying the portal out before the mail is set up, a second
       administrator on the day the first one loses their phone, a trainer who
       stands next to her and can pick a password on the spot. Administrator
       only, because handing out a login is more than inviting one. A student's
       login is made the same way from the student's page (student_invite with
       mode=direct), because there it belongs to somebody. */
    case 'account_create':
        $u=require_admin();
        $role=choose(staff_role_posted(),assignable_roles($u));
        $email=email_value(required_text('email',254));
        $password=(string)post('password'); strong_password($password);
        if(account_using_email($email))
            throw new UserError(t('Diese Adresse hat schon ein Konto.','That address already has an account.'));
        // Active and verified: there is no link to click, and an account that
        // cannot sign in is not what she asked for. The address is not proven
        // to belong to anybody, which is what the invitation does, so this says
        // so in the change log rather than pretending otherwise.
        run("INSERT INTO accounts (name,email,password_hash,role,state,verified_at,locale,created_at)"
            ." VALUES (?,?,?,?,'active',?,?,?)",
            [required_text('name'),$email,password_hash($password,PASSWORD_DEFAULT),$role,now(),
             choose(post('locale','de'),['de','en']),now()]);
        $id=(int)db()->lastInsertId();
        audit('account.created_directly','account',$id);
        flash(t('Konto angelegt. Es kann sich sofort mit diesem Passwort anmelden – die Adresse wurde dabei nicht bestätigt.',
                'Account created. It can sign in with that password straight away – the address was not confirmed.'));
        return ['accounts',[]];
    case 'student_invite':
        /* A student's own login, made on their own page - the only place
           students.account_id is ever set (ADR 0010). One login is one student:
           a brother or sister gets an address of their own, never a place on
           somebody else's login.

           The invitation goes to the address on the student rather than to one
           of the people on their emergency list. Those are two different
           questions - who do I ring when she falls over, who reads the invoices
           - and one row answering both is how a grandmother with no email ended
           up being the reason a family could not sign in.

           mode=direct makes the login active on the spot with a password typed
           here, for an administrator standing next to the family or trying the
           portal out before mail works. It replaces account_create for
           students. Every refusal comes before the first write. */
        $u=require_staff();$direct=post('mode')==='direct';
        if($direct) require_admin();
        $s=lock_row('students',(int)student((int)post('student_id'))['id']);
        if($s['account_id']) throw new UserError(t('Dieses Kind hat schon ein eigenes Konto. Zugang, Einladung und Adresse werden dort verwaltet.','This student already has an account of their own. Access, invitation and address are managed there.'));
        // Everything about the login comes from the student: the page offers a
        // button, not a form, and the address is edited on the student itself.
        $email=email_value((string)$s['email']);
        $name=rtrim(mb_substr($s['first_name'].' '.$s['last_name'],0,TEXT_LINE_MAX));
        // German until they choose, like every page before they sign in.
        $locale='de';
        $password=$direct?strong_password((string)post('password')):'';
        if(account_using_email($email)) throw new UserError(own_address_needed($email));
        if(!$direct && !account_mail_ready())
            throw new UserError(t('Eine Einladung lässt sich noch nicht verschicken. ','An invitation cannot be sent yet. ').account_mail_missing()
                .t(' Bis dahin kann eine Administratorin das Konto direkt mit Passwort anlegen.',' Until then, an administrator can create the account directly with a password.'));
        if($direct) run("INSERT INTO accounts (name,email,password_hash,role,state,verified_at,locale,created_at) VALUES (?,?,?,'student','active',?,?,?)",
                        [$name,$email,password_hash($password,PASSWORD_DEFAULT),now(),$locale,now()]);
        else run("INSERT INTO accounts (name,email,role,locale,created_at) VALUES (?,?,'student',?,?)",[$name,$email,$locale,now()]);
        $accountId=(int)db()->lastInsertId();
        // Tracked, so the student's change log says when they got their login
        // and at which address - the one line that writes account_id is the
        // one line worth being able to read back.
        tracked('students',(int)$s['id'],$s['first_name'].' '.$s['last_name'],
            fn()=>run('UPDATE students SET account_id=?,email=?,updated_at=?,revision=revision+1 WHERE id=?',[$accountId,$email,now(),$s['id']]));
        if(!$direct) send_account_token(one('SELECT * FROM accounts WHERE id=?',[$accountId]),'invite');
        audit($direct?'account.created_directly':'account.invited','account',$accountId);
        flash($direct
            ? t('Konto angelegt. ','Account created. ').$name.t(' kann sich sofort mit diesem Passwort anmelden – die Adresse wurde dabei nicht bestätigt.',' can sign in with that password straight away – the address was not confirmed.')
            : t('Einladung liegt im Postausgang.','The invitation is in the outbox.'));
        return ['student',['id'=>$s['id']]];
    case 'account_state':
        $u=require_staff();$id=(int)post('id');$mode=choose(post('mode'),['suspend','restore','delete','reinvite']);
        $a=one('SELECT * FROM accounts WHERE id=? FOR UPDATE',[$id]);
        if(!$a || (int)$u['id']===$id || ($u['role']!=='admin' && $a['role']!=='student')) throw new UserError(t('Dieses Konto kann hier nicht geändert werden.','This account cannot be changed here.'));
        // Lock all administrators so concurrent requests cannot remove the last one.
        $admins=rows("SELECT id FROM accounts WHERE role='admin' AND state='active' FOR UPDATE");
        if($a['role']==='admin' && $a['state']==='active' && count($admins)<=1 && in_array($mode,['suspend','delete'],true)) throw new UserError(t('Der letzte Administrator muss erhalten bleiben.','The last administrator must remain active.'));
        // A student's login is managed from that student's page, so that is where
        // she lands again. Looked up now, because after a delete the foreign key
        // has already cut the link and there is nobody left to find.
        $studentId=$a['role']==='student'?login_student_id($id):0;
        if($mode==='reinvite') {
            if($a['state']!=='invited') throw new UserError(t('Nur offene Einladungen können erneut versendet werden.','Only pending invitations can be resent.'));
            cancel_account_mail($id);send_account_token($a,'invite');
        } else {
            run('DELETE FROM auth_tokens WHERE account_id=?',[$id]);cancel_account_mail($id);
            if($mode==='delete') {
                if(email_normalised(post('confirmation'))!==$a['email']) throw new UserError(t('Zum Löschen die E-Mail-Adresse eingeben.','Enter the email address to delete the account.'));
                run('DELETE FROM mail_jobs WHERE account_id=?',[$id]);
                run('DELETE FROM accounts WHERE id=?',[$id]);
            } else run('UPDATE accounts SET state=?,auth_version=auth_version+1 WHERE id=?',[$mode==='suspend'?'suspended':($a['verified_at']?'active':'invited'),$id]);
        }
        audit('account.'.$mode,'account',$id);flash(t('Konto aktualisiert.','Account updated.'));
        return $studentId?['student',['id'=>$studentId]]:['accounts',[]];
    case 'student_save':
        $u=require_user();$id=(int)post('id');$existing=$id?student($id):null;
        if(!$existing) require_staff();
        $first=required_text('first_name',100);$last=required_text('last_name',100);$birth=date_value(post('birth_date'));
        if(is_staff($u)) {
            // account_id is neither read nor written here: a login is given by
            // student_invite and taken away by deleting it, nothing else
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
            // One line, the way the paper form asks it, because it is typed once
            // and printed once and never sorted on. Her own number rather than an
            // emergency contact's: for an adult member those are the same person,
            // and listing yourself as the person to ring is not a record anybody
            // should have to keep.
            $address=text_limit('address',200);$phone=text_limit('phone',60);
            // Where the portal writes to this student: the invitation, invoices,
            // reminders. Optional while the record is being set up, and asked for
            // by contact_gap() until it is there. Once the student has a login it
            // is that login's address, and this copy is kept equal to it.
            $posted=post('email')!==''?email_value(post('email')):'';
            $account=$existing && $existing['account_id']?lock_row('accounts',(int)$existing['account_id']):null;
            $readdress=false;
            if(!$account) {
                $email=$posted;
                // Only a change is checked. Two members who already share an
                // address from before the rule are listed for her to sort out
                // (students_needing_own_address()); refusing every other edit on
                // the page until then would help nobody.
                if($email!=='' && $email!==email_normalised((string)($existing['email']??'')) && account_using_email($email))
                    throw new UserError(own_address_needed($email));
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
                    if(account_using_email($posted)) throw new UserError(own_address_needed($posted));
                    if(!account_mail_ready())
                        throw new UserError(t('Die neue Adresse lässt sich erst eintragen, wenn die Einladung dorthin verschickt werden kann. ',
                                              'The new address can only be entered once the invitation can be sent there. ')
                            .account_mail_missing().t(' Nichts wurde gespeichert.',' Nothing was saved.'));
                    $email=$posted; $readdress=true;
                }
            }
            $args=[$first,$last,$email,$address,$phone,$birth,$join,$end,$status,$levelId,$ageGroupId,text_limit('internal_notes',12000),now()];
            if($id) {
                // The login moves inside the same tracked change, so the change
                // log shows the new address on the student it belongs to.
                tracked('students',$id,$first.' '.$last,function() use ($args,$id,$account,$readdress,$email) {
                    $updated=run('UPDATE students SET first_name=?,last_name=?,email=?,address=?,phone=?,birth_date=?,joined_on=?,ended_on=?,status=?,level_id=?,age_group_id=?,internal_notes=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?',[...$args,$id,(int)post('revision')]);
                    if(!$updated->rowCount())throw new UserError(t('Der Eintrag wurde inzwischen geändert. Bitte neu laden und die Änderungen vergleichen.','This record has changed. Reload it and compare the changes before saving.'));
                    if($readdress) change_login_address((int)$account['id'],$email);
                });
                // The first invitation went to the old address and has just been
                // made useless; this is the one that works.
                if($readdress) {
                    send_account_token(one('SELECT * FROM accounts WHERE id=?',[(int)$account['id']]),'invite');
                    audit('account.readdressed','account',(int)$account['id']);
                }
            }
            else {$id=tracked_insert('students',$first.' '.$last,function() use ($args) {
                run('INSERT INTO students (first_name,last_name,email,address,phone,birth_date,joined_on,ended_on,status,level_id,age_group_id,internal_notes,updated_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$args,now()]);
                return (int)db()->lastInsertId();
            });}
        } else {
            $updated=run('UPDATE students SET first_name=?,last_name=?,birth_date=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?',[$first,$last,$birth,now(),$id,(int)post('revision')]);
            if(!$updated->rowCount())throw new UserError(t('Der Eintrag wurde inzwischen geändert. Bitte neu laden und die Änderungen vergleichen.','This record has changed. Reload it and compare the changes before saving.'));
        }
        save_custom_fields($id,!$existing);audit('student.saved','student',$id);
        flash(t('Schüler gespeichert.','Student saved.').(($readdress??false)
            ?' '.t('Die Einladung ist an die neue Adresse unterwegs; der Link an die alte gilt nicht mehr.','The invitation is on its way to the new address; the link sent to the old one no longer works.'):''));
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
        if($standard) run('UPDATE contacts SET is_primary=0 WHERE student_id=?',[$s['id']]);
        run('INSERT INTO contacts (student_id,owner_name,relation_label,phone,email,is_primary) VALUES (?,?,?,?,?,?)',[$s['id'],required_text('owner_name'),required_text('relation_label',100),text_limit('phone',80),$email,$standard?1:0]);
        audit('contact.added','student',(int)$s['id']);return ['student',['id'=>$s['id'],'tab'=>'contacts']];
    case 'contact_delete':
        $s=student((int)post('student_id'));
        $contact=one('SELECT * FROM contacts WHERE id=? AND student_id=?',[(int)post('id'),$s['id']]);
        if(!$contact) throw new NotFound(t('Kontakt nicht gefunden.','Contact not found.'));
        if(count(student_contacts((int)$s['id']))<2) throw new UserError(t('Jedes Kind braucht mindestens eine Kontaktperson. Trage zuerst eine andere ein.','Every child needs at least one contact person. Enter another one first.'));
        run('DELETE FROM contacts WHERE id=? AND student_id=?',[(int)$contact['id'],$s['id']]);
        // Removing the standard contact must not leave the child without one.
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
        if($standard) run('UPDATE contacts SET is_primary=0 WHERE student_id=?',[$s['id']]);
        run('UPDATE contacts SET owner_name=?,relation_label=?,phone=?,email=?,is_primary=? WHERE id=? AND student_id=?',[required_text('owner_name'),required_text('relation_label',100),text_limit('phone',80),$email,$standard?1:0,(int)$contact['id'],$s['id']]);
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
