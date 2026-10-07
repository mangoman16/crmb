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
 * Make an invited login and send its invitation - every login with an address
 * made in the portal comes from here (ADR 0021, §5): a team member's
 * (account_invite) and one by address alone (email_invite). A student's own
 * login is not made but given: invite_student() turns their placeholder into it
 * (ADR 0023 §3). Returns its id.
 *
 * Every refusal comes before the write: an address that is another login's,
 * and mail that cannot go out yet, because a login nobody can be invited to is
 * somebody locked out of something they never saw. Nobody sets another
 * person's password; they choose their own on the page the link opens.
 *
 * $attach links the login to what it belongs to before the invitation is
 * written, because the mail's words depend on it: a login no student points to
 * is asked for the person's details (send_account_token()).
 */
function invite_login(string $name, string $email, string $role, string $locale, ?Closure $attach = null): int {
    refuse_address_in_use($email);
    refuse_until_invitations_can_go();
    run('INSERT INTO accounts (name,email,role,locale,created_at) VALUES (?,?,?,?,?)',[$name,$email,$role,$locale,now()]);
    $accountId=(int)db()->lastInsertId();
    if($attach) $attach($accountId);
    send_account_token(one('SELECT * FROM accounts WHERE id=?',[$accountId]),'invite');
    audit('account.invited','account',$accountId);
    return $accountId;
}

/**
 * Delete a login, its links and the mail still waiting for it - the one way a
 * login is deleted: on Konten, by withdrawing an invitation by address, with a
 * student whose login was never set up, and as the second half of replacing a
 * student's login with a placeholder (replace_login_with_placeholder()).
 * Conversations, notifications and presence go by their foreign keys. The
 * caller refuses whatever else it refuses first.
 *
 * A login a student still points to is refused here, in words she can read:
 * every student has a login (ADR 0023 §4), and the database's own refusal - the
 * key student_login is RESTRICT, error 1451 - is the backstop, not the answer.
 * Locked, so the student cannot be pointed at it between the question and the
 * delete.
 */
function delete_login(int $accountId): void {
    if($student=one('SELECT first_name,last_name FROM students WHERE account_id=? FOR UPDATE',[$accountId]))
        throw new UserError(strtr(t('Diese Anmeldung gehört zu {name} und wird nie allein gelöscht – jedes Kind hat eine. Auf der Seite von {name} unter „Zugang zum Portal“ bekommt es stattdessen eine neue, leere.',
                                    'This login belongs to {name} and is never deleted on its own – every child has one. On {name}’s page under “Access to the portal” they get a new, empty one instead.'),
                                  ['{name}'=>$student['first_name'].' '.$student['last_name']]));
    run('DELETE FROM auth_tokens WHERE account_id=?',[$accountId]);
    run('DELETE FROM mail_jobs WHERE account_id=?',[$accountId]);
    run('DELETE FROM accounts WHERE id=?',[$accountId]);
}

/**
 * Make a student, on $accountId's login or, without one, on the placeholder
 * every student has from the moment they exist (ADR 0023 §4), in the caller's
 * transaction and inside its tracked_insert(). Returns the student's id.
 *
 * The one insert of a student made in the portal - by the wizard, and by the
 * holder of an invitation by address (create_own_student()); demo_fill() writes
 * its example ones. $details holds first_name, last_name and birth_date (or
 * null), and the status and the address where the caller has them; the rest is
 * what every new student starts with (new_student_defaults()), so no way in
 * starts differently.
 */
function create_student(array $details, ?int $accountId = null): int {
    if(tx_depth()===0) throw new RuntimeException('create_student() outside a transaction could leave a login nobody points to.');
    $d=new_student_defaults();
    $accountId??=placeholder_login((string)$details['first_name'],(string)$details['last_name']);
    run('INSERT INTO students (account_id,first_name,last_name,email,address,phone,birth_date,joined_on,status,level_id,age_group_id,internal_notes,updated_at,created_at)'
        .' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$accountId,$details['first_name'],$details['last_name'],(string)($details['email']??''),$d['address'],$d['phone'],$details['birth_date']??null,
         $d['joined_on'],$details['status']??$d['status'],$d['level_id'],$d['age_group_id'],$d['internal_notes'],now(),now()]);
    return (int)db()->lastInsertId();
}

/**
 * Step 1 of the wizard, from the post: first and last name (required, as for
 * every placeholder and username - 0021 rejected names nobody typed), birth date
 * (optional), membership status, and the course with its tariff or „Noch keinen
 * Kurs" - checked, as a draft (student_draft_checked()), or a refusal.
 *
 * The course arrives as 'none', or as "{course}:{tariff}" with 0 for a course
 * that has no tariff yet; anything else is refused rather than guessed at.
 */
function student_draft_posted(): array {
    [$first,$last]=posted_names();
    $course=post('course');
    if($course==='') throw new UserError(t('Bitte einen Kurs wählen – oder „Noch keinen Kurs“.','Please choose a course – or “No course yet”.'));
    if($course==='none') { $classId=0; $tariffId=null; }
    elseif(preg_match('/^([1-9][0-9]{0,9}):(0|[1-9][0-9]{0,9})$/D',$course,$m)) { $classId=(int)$m[1]; $tariffId=(int)$m[2] ?: null; }
    else throw new UserError(t('Ungültige Auswahl.','Invalid choice.'));
    return student_draft_checked(['first_name'=>$first,'last_name'=>$last,'birth_date'=>birth_date_value(post('birth_date')),
        'status'=>post('status'),'course'=>$course,'class_id'=>$classId,'tariff_id'=>$tariffId]);
}

/**
 * A draft as it may still be written, or a refusal: its status is one there is,
 * and its course is still running, still has a place, and still offers the
 * tariff. student_draft asks when step 1 is sent, and student_create asks
 * again, because a course can fill or be archived between the two steps - two
 * families on one evening for the last place. The course is held while it is
 * counted (course_held()), so the place is still there when it is written.
 */
function student_draft_checked(array $draft): array {
    if(!isset(statuses()[(string)($draft['status']??'')])) throw new UserError(t('Bitte einen Status auswählen.','Please choose a status.'));
    if(!(int)($draft['class_id']??0)) return $draft;
    $class=course_held((int)$draft['class_id']);
    if(!$class || (int)$class['archived'])
        throw new UserError(t('Diesen Kurs gibt es nicht mehr. Bitte einen anderen wählen.','That course no longer runs. Please choose another one.'));
    if(course_is_full($class))
        throw new UserError(t('Dieser Kurs ist inzwischen voll. Bitte einen anderen wählen – oder „Noch keinen Kurs“.','This course has filled up in the meantime. Please choose another one – or “No course yet”.'));
    if($draft['tariff_id']!==null && !one('SELECT 1 FROM tariffs WHERE id=? AND class_id=? AND archived=0',[(int)$draft['tariff_id'],(int)$class['id']]))
        throw new UserError(t('Diesen Tarif bietet der Kurs nicht mehr an. Bitte noch einmal wählen.','The course no longer offers that tariff. Please choose again.'));
    return $draft;
}

/**
 * Keep a slot in the session under $key - the one writer of
 * $_SESSION['student_drafts']: step 1's draft (student_draft), or once
 * student_create has written it, ['made' => the student's id], which is what
 * Back from the done page finds (student_made_from_draft()). Slots older than
 * two hours are dropped as it writes, and of the rest a session keeps the ten
 * newest: an eleventh pushes the oldest out (ADR 0023 §5).
 */
function keep_student_draft(string $key, array $draft): void {
    $drafts=array_filter(is_array($_SESSION['student_drafts']??null) ? $_SESSION['student_drafts'] : [],
        fn($d)=>is_array($d) && (int)($d['saved_at']??0)>=time()-STUDENT_DRAFT_SECONDS);
    unset($drafts[$key]);
    $drafts[$key]=$draft+['saved_at'=>time()];
    $_SESSION['student_drafts']=array_slice($drafts,-STUDENT_DRAFTS_KEPT,null,true);
}

/**
 * The student's login, locked, when it is still a placeholder - the only login
 * that can be given an address or a username (ADR 0023 §3). Refused otherwise,
 * in words: a login in use or waiting is managed on the access card.
 */
function placeholder_of(array $student): array {
    $login=student_login_locked($student);
    if($login['state']!=='placeholder')
        throw new UserError(t('Dieses Kind hat schon eine eigene Anmeldung. Zugang, Einladung und Adresse werden dort verwaltet.',
                              'This student already has a login of their own. Access, invitation and address are managed there.'));
    return $login;
}

/**
 * The address an invitation may go to, from what was typed - or a refusal: an
 * address at all (email_value()), nobody else's login (refuse_address_in_use(),
 * leaving out $loginId, the login it is for), and mail that can go out. Asked
 * before anything is written, once; invite_student() writes what this returns.
 */
function invitation_address(string $typed, int $loginId = 0): string {
    $email=email_value($typed);
    refuse_address_in_use($email,$loginId);
    refuse_until_invitations_can_go();
    return $email;
}

/**
 * Refuse an address a student without sign-in already carries
 * (student_without_login_at()), naming them. Either the person is that student,
 * invited from their own page, or it is a parent's address on a brother's or
 * sister's record and $newcomer needs one of their own - not "the person exists
 * twice", which it usually is not. Asked by the wizard and by an invitation by
 * address alone, before either writes.
 */
function refuse_address_on_student_without_sign_in(string $email, string $newcomer): void {
    $carrier=student_without_login_at($email);
    if(!$carrier) return;
    throw new UserError(strtr(t('Diese Adresse steht schon bei {name}. Ist es {name}, lade dort ein; sonst braucht {new} eine eigene Adresse – die der Eltern gehört zu den Kontakten.',
                                'This address is already on {name}. If it is {name}, invite from there; otherwise {new} needs an address of their own – a parent’s belongs with the contacts.'),
                              ['{name}'=>$carrier['first_name'].' '.$carrier['last_name'],'{new}'=>$newcomer]));
}

/**
 * Give a student's placeholder the address invitation_address() returned, and
 * send the invitation (ADR 0023 §3) - the access card's „Einladung senden"
 * (student_invite) and the wizard's „Per E-Mail einladen". Returns the login's
 * id. The caller holds the student's row.
 *
 * The login is not made but turned from the placeholder the student already
 * points to, so students.account_id never changes; a login that is not a
 * placeholder is refused before anything is written.
 *
 * The invitation goes to the address on the student rather than to one of the
 * people on their emergency list. Those are two different questions - who do I
 * ring when she falls over, who reads the invoices - and one row answering both
 * is how a grandmother with no email ended up being the reason a family could
 * not sign in. Tracked on the login, so the change log says when they got it and
 * at which address.
 */
function invite_student(array $student, string $email, string $locale = 'de'): int {
    $login=placeholder_of($student);
    $name=login_name_for((string)$student['first_name'],(string)$student['last_name']);
    tracked('accounts',(int)$login['id'],$name,function() use ($login,$student,$email,$name,$locale): void {
        run("UPDATE accounts SET name=?,email=?,state='invited',locale=? WHERE id=? AND state='placeholder'",[$name,$email,$locale,(int)$login['id']]);
        // The student's copy is the login's address from now on, byte for byte.
        run('UPDATE students SET email=?,updated_at=?,revision=revision+1 WHERE id=?',[$email,now(),(int)$student['id']]);
    });
    send_account_token(one('SELECT * FROM accounts WHERE id=?',[(int)$login['id']]),'invite');
    audit('account.invited','account',(int)$login['id']);
    return (int)$login['id'];
}

/**
 * The username a placeholder may be given, from what was typed - or a refusal:
 * the rule (username_value()), nobody has it, read with a lock and refused with
 * a free one named (refuse_username_in_use()), and a released privacy notice,
 * because a username comes with its first sign-in link, whose holder
 * acknowledges the notice. Asked before anything is written, once - by the
 * wizard and by the access card alike; give_student_username() writes what this
 * returns.
 */
function username_to_give(string $typed): string {
    $username=username_value($typed);
    refuse_username_in_use($username);
    refuse_signin_link_until_privacy_released();
    return $username;
}

/**
 * Give a student's placeholder the username username_to_give() returned, and
 * make its first sign-in link (ADR 0023 §3, §6) - the wizard's „Ohne E-Mail,
 * mit Benutzername" and the access card's fold for a login without sign-in.
 * Returns the readable link's token, which is kept in the maker's session only
 * (remember_signin_link()). A login that is not a placeholder is refused before
 * anything is written. Tracked on the login, like an invitation.
 */
function give_student_username(array $student, string $username): string {
    $login=placeholder_of($student);
    $name=login_name_for((string)$student['first_name'],(string)$student['last_name']);
    tracked('accounts',(int)$login['id'],$name,
        fn()=>run("UPDATE accounts SET name=?,username=?,state='invited' WHERE id=? AND state='placeholder'",[$name,$username,(int)$login['id']]));
    return make_signin_link(one('SELECT * FROM accounts WHERE id=?',[(int)$login['id']]));
}

/**
 * Make a sign-in link for a login the caller has locked and asked about
 * (may_create_signin_link()), write down who made it, and keep the readable
 * link in the maker's session (ADR 0023 §6). It replaces the login's earlier
 * one: make_token() keeps one per purpose. Returns the token.
 *
 * Every link is made here, so here is where a first one waits for the privacy
 * notice its holder will be asked to acknowledge (security review, finding 6):
 * for a username login waiting. A placeholder has been refused already, before
 * its username was written (username_to_give()); asked again here, it can only
 * pass.
 */
function make_signin_link(array $login): string {
    if(($login['verified_at'] ?? null)===null) refuse_signin_link_until_privacy_released();
    $token=make_token((int)$login['id'],'signin');
    audit('account.signin_link','account',(int)$login['id']);
    remember_signin_link((int)$login['id'],$token);
    return $token;
}

/**
 * Give a student a fresh placeholder in place of their login, and delete the old
 * one (ADR 0023 §4): „Einladung zurückziehen" and „Benutzernamen zurückziehen"
 * (account_state's withdraw) for a login never used, and „Anmeldung löschen"
 * for one in use. Not „Link zurückziehen": that deletes only the sign-in link
 * (signin_link's withdraw), and the login stays. Returns the new login's id.
 * The caller holds the student's row and the old login.
 *
 * The student is moved first, inside tracked('students', …), and the old login
 * deleted after, so the student is never without a login, not even for one
 * statement. delete_login() then takes its links and waiting mail, and the
 * foreign keys its private conversations - which a next holder must never see,
 * which is why the login is replaced rather than emptied. The record, its
 * address, courses, charges and invoices stay.
 *
 * Only a student's own login is deleted. A staff login a student's record still
 * points to, from before ADR 0010, only lets go of the child: it is a team
 * member's, with her chats and her role, and a stale link on a child's record
 * must not take it. Deleting it is Konten's, where it is hers.
 */
function replace_login_with_placeholder(array $student, array $old): int {
    $fresh=tracked('students',(int)$student['id'],$student['first_name'].' '.$student['last_name'],function() use ($student): int {
        $fresh=placeholder_login((string)$student['first_name'],(string)$student['last_name'],(bool)($student['is_demo']??false));
        run('UPDATE students SET account_id=?,updated_at=?,revision=revision+1 WHERE id=?',[$fresh,now(),(int)$student['id']]);
        return $fresh;
    });
    if(($old['role']??'')==='student') delete_login((int)$old['id']);
    return $fresh;
}

/**
 * A student's first and last name as posted, or a refusal: both, at most 100
 * characters each, which is what the student's two columns hold. One rule for
 * every form that names a student - the wizard, the page an invitation by
 * address opens, and the student page.
 */
function posted_names(): array {
    $first=post('first_name'); $last=post('last_name');
    if($first==='' || $last==='' || mb_strlen($first)>100 || mb_strlen($last)>100)
        throw new UserError(t('Bitte Vor- und Nachnamen eintragen.','Please enter the first and last name.'));
    return [$first,$last];
}

/**
 * The first name, last name and birth date typed on the page an invitation by
 * address opens (ADR 0021, §3) - the only things read from that post for the
 * student - checked, or a refusal. Asked before anything is written.
 */
function own_student_details(): array {
    [$first,$last]=posted_names();
    return ['first_name'=>$first,'last_name'=>$last,'birth_date'=>(string)birth_date_value(post('birth_date'),true)];
}

/**
 * The student an invitation by address makes for its holder, in the transaction
 * that activates the login (ADR 0021, §3). Returns the student's id.
 *
 * Only activate calls it, and only when setup_creates_student() says so for the
 * locked link. The address is the login's own, the rest is what any new student
 * starts with (create_student()). Nobody is signed in yet, so the change
 * log and the audit name the link's own login - the one place an actor is
 * passed. The unique index on students.account_id refuses a second student, so
 * a form sent twice cannot make two.
 */
function create_own_student(int $accountId, string $email, array $details): int {
    $id=tracked_insert('students',$details['first_name'].' '.$details['last_name'],
        fn(): int => create_student(['email'=>$email]+$details,$accountId),$accountId);
    // The login is called what its holder is called, so staff see a name
    // where they saw the address until now.
    run('UPDATE accounts SET name=? WHERE id=?',[login_name_for($details['first_name'],$details['last_name']),$accountId]);
    audit('student.saved','student',$id,$accountId);
    return $id;
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
 * What was typed into the one box of the sign-in or „vergessen" page, „E-Mail
 * oder Benutzername" (ADR 0023 §7), as [kind, value]: a typed '@' means an
 * address, anything else a username, each normalised the way it is stored. One
 * derivation for the lookup and for the throttle: two spellings of it would
 * mean a sign-in that succeeds while its counter keeps climbing under a key
 * nothing ever clears. The box is posted as login.
 */
function attempted_sign_in(): array {
    $typed=post('login');
    return str_contains($typed,'@') ? ['address',email_normalised($typed)] : ['username',username_normalised($typed)];
}

/*
 * The bucket a sign-in and „vergessen" count into: the typed value, normalised
 * - and deliberately not the row it names. Spelled in one place, because
 * counting and clearing have to agree: a clear that spelled the key differently
 * would empty nothing, silently, and leave the family locked out.
 *
 * Do not "improve" this by looking the account up first: that lookup is what
 * made the form answer, through the throttle, whether an address had an
 * account (ADR 0007). A known value and an unknown one are counted alike.
 */
function address_identity(string $address): string { return 'address:'.$address; }
function username_identity(string $username): string { return 'username:'.$username; }
/** The bucket for one attempted_sign_in(). */
function sign_in_identity(array $attempt): string {
    [$kind,$value]=$attempt;
    return $kind==='address' ? address_identity($value) : username_identity($value);
}

function handle_post(): array {
    $action=post('action');
    if(!hash_equals(csrf(),post('csrf'))) throw new UserError(t('Die Sitzung ist abgelaufen. Seite neu laden.','Your session expired. Reload the page.'));
    // A form this session has answered already is not done again: it lands
    // where the first went (answered_form_landing()). Before the throttles,
    // because it is no new attempt at anything.
    $request=post('request_id');
    if(($landing=answered_form_landing($request))!==null) return $landing;
    $ip=$_SERVER['REMOTE_ADDR']??'local';
    if(in_array($action,['login','forgot','activate'],true)) throttle('auth-ip',$ip,60);
    if($action==='login') {
        throttle('login',sign_in_identity(attempted_sign_in()),10);
        // Made here, outside the action's transaction, on the one occasion it
        // is missing: inside, a refused sign-in would roll it back and the next
        // one would make it again (sign_in_dummy_hash()).
        sign_in_dummy_hash();
    }
    // Both counted on every request: three an hour per typed address, so
    // nobody can fill a family's inbox or keep replacing the link they are
    // about to use; ten an hour from one IP, so nobody can walk through many
    // addresses (S7).
    if($action==='forgot') throttle_all([['forgot',sign_in_identity(attempted_sign_in()),3,3600],['forgot-ip',$ip,10,3600]]);
    if(in_array($action,['password_change','email_change'],true)) {
        $actor=require_user();throttle('account-security',(string)$actor['id'],10);
    }
    // One transaction around the whole action: it either happens or it does not.
    $result=transactional(function() use ($request,$action) {
        claim_request($request);
        return dispatch_action($action);
    });
    forget_attempts_after_success($action);
    remember_answered_form($request,$result);
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
 * confirmed - so all three clear the bucket rather than 'reset' alone. Without
 * the link half, the reset sent to a locked-out family lets them in once and
 * leaves them locked out of the next sign-in until the window runs down.
 *
 * The proven login's buckets, its address's and its username's, are cleared:
 * either may be what was typed (ADR 0023 §7). A sign-in link opened by the
 * child it was made for proves it too - it ends in the same sign_in(). A wrong
 * password stays
 * counted; the per-IP limit is never cleared, because one valid account must not
 * be able to refresh the limit that slows down guessing at all the others; and
 * neither 'forgot' limit is ever cleared, since typing an address proves nothing
 * about who typed it and clearing it would hand anybody an unlimited mailer
 * pointed at one family's inbox.
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
    if((string)($who['email']??'')!=='') throttle_clear('login',address_identity(email_normalised((string)$who['email'])));
    if((string)($who['username']??'')!=='') throttle_clear('login',username_identity((string)$who['username']));
}

function dispatch_action(string $action): array {
    // Viewing the portal as somebody is looking, never acting: every action
    // would speak as the person looked at - a message in their name, their
    // consent, a problem report under their name, a trainer's work done as her.
    // So while a view is open nothing goes through but its end: stopping it, or
    // signing out. Asked here, where every action passes, rather than by each
    // action that writes, which the next one added would not be. Decided by the
    // session's own mark of a view, not by impersonator()'s answer, so a view it
    // could not vouch for is refused rather than let through as the person
    // looked at (security re-review N1). current_user() first: it ends such a
    // view with its session, and drops a mark left with nobody signed in.
    if(current_user() && !empty($_SESSION['impersonator_id']) && $action!=='logout' && !($action==='impersonate' && post('mode')==='stop'))
        throw new UserError(viewing_refusal());
    switch($action) {
    case 'login':
        /* By address or by username, in one box (ADR 0023 §7). The lookup is
           account_for_sign_in(), which looks nothing up that fails its format
           and uses no row that does not match exactly what was typed.

           Every refusal runs exactly one password_verify(): against the login's
           own hash where there is one - a suspended login, an invitation
           somebody already set a password on - and against sign_in_dummy_hash()
           where there is none - nothing found, a value that cannot be an address
           or a username, an invitation or a placeholder not yet set up. So how
           long the answer takes does not say whether the login exists, and the
           words are the same for every failure. Nothing is hashed on the way to
           a refusal. */
        $a=account_for_sign_in(...attempted_sign_in());
        $hash=(string)($a['password_hash']??'');
        $real=$hash!=='';
        if(!password_verify(post('password'),$real?$hash:sign_in_dummy_hash()) || !$real || $a['state']!=='active' || !$a['verified_at'])
            throw new UserError(t('Anmeldung nicht möglich. Bitte E-Mail bzw. Benutzernamen und Passwort prüfen. Noch nicht eingerichtet? Dann zuerst den Link öffnen, den du bekommen hast.',
                                  'Could not sign in. Please check the email or username and the password. Not set up yet? Then first open the link you were given.'));
        // Over the very hash just verified, and nothing else: a password changed
        // in the meantime is not overwritten with this one, and so the read
        // above needs no lock (security review F2).
        if(password_needs_rehash($hash,PASSWORD_DEFAULT)) run('UPDATE accounts SET password_hash=? WHERE id=? AND password_hash=?',[password_hash(post('password'),PASSWORD_DEFAULT),$a['id'],$hash]);
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
        /* „Passwort vergessen", by address or username (ADR 0023 §7). The
           answer is the same whatever happened, and names nothing, so the page
           does not say whether a login exists (ADR 0019 closed ADR 0007's
           channel; this keeps it closed). The mail goes to the address as
           stored, never to what was typed [S1] - and a username login without
           an address gets nothing, with the same answer: the page says that its
           new link comes from the trainer.

           An active login gets a reset link. An invited one gets its invitation
           again: that is its way in, and accepting it records the privacy
           acknowledgement a reset would skip - sending nothing meant a call to
           the trainer. A suspended login gets nothing. */
        $a=account_for_sign_in(...attempted_sign_in(),lock:true);
        if($a && (string)($a['email']??'')!=='' && account_mail_ready()) {
            if($a['state']==='active' && $a['verified_at']) send_account_token($a,'reset');
            elseif($a['state']==='invited') { cancel_account_mail((int)$a['id']); send_account_token($a,'invite'); }
        }
        flash(t('Wenn dazu ein Zugang mit E-Mail-Adresse gehört, ist eine E-Mail dorthin unterwegs.',
                'If this belongs to a login with an email address, an email is on its way to it.'));
        return ['forgot',[]];
    case 'activate':
        $r=token_record($_SESSION['activation_hash']??'',true);
        // The rule the page it opens asks too (link_usable()), so the page
        // never offers a form this refuses.
        if(!link_usable($r))
            throw new UserError(t('Dieser Link ist ungültig oder abgelaufen. Bitte eine neue Einladung bzw. einen neuen Link anfordern.','This link is invalid or expired. Please request a new invitation or reset link.'));
        /* A sign-in link (ADR 0023 §6) sets its login up the first time, the
           way an invitation does - privacy notice, password, set up - and after
           that only sets a new password, the way a reset link does: a login in
           use keeps its address, preferences and consents. Either way it asks
           for a new password in this POST, because one that signed in without
           would let whoever holds it in as the child unnoticed (A8 overruled);
           and either way auth_version goes up, so the holder's old password and
           sessions stop working and a link used by anybody else is noticed. */
        $purpose=$r['purpose']==='signin' ? ($r['verified_at']===null ? 'invite' : 'reset') : $r['purpose'];
        $ownStudent=0;
        if($purpose==='invite') {
            // Everything read and checked before anything is written. Whether
            // the person's student is made here is decided by the locked link,
            // never by what was posted (ADR 0021, §3).
            $details=setup_creates_student($r) ? own_student_details() : null;
            if(!post('privacy_seen') || !setting('privacy_ready',false)) throw new UserError(t('Bitte die Datenschutzhinweise lesen und bestätigen.','Please read and acknowledge the privacy notice.'));
            $pass=strong_password(post('password'));
            if($pass!==post('password_confirm')) throw new UserError(t('Die Passwörter stimmen nicht überein.','Passwords do not match.'));
            // Whoever this browser was signed in as is not who is setting up
            // this login: their sign-in ends here (security review F1). What
            // the session holds for them goes as sign_in() below starts the
            // new one - not here, where a write that fails after this line
            // would take the link being opened with it, and a passing
            // „Speichern fehlgeschlagen" become „Link nicht mehr gültig".
            unset($_SESSION['user_id'],$_SESSION['auth_version']); current_user(true);
            if($details) $ownStudent=create_own_student((int)$r['account_id'],(string)$r['email'],$details);
            // Mail is asked about only of a login with an address to send it to.
            // One without - a username's first sign-in by link - has said yes or
            // no to nothing, whatever a page posts, so no consent is written for
            // it, and an address added later under Mein Konto starts with both off.
            $mailable=(string)($r['email']??'')!=='';
            $newsletter=$mailable && post('newsletter'); $notifications=$mailable && post('notifications');
            run("UPDATE accounts SET password_hash=?,state='active',verified_at=?,auth_version=auth_version+1,privacy_version=?,newsletter=?,notifications=?,locale=? WHERE id=?",[password_hash($pass,PASSWORD_DEFAULT),now(),notice_version(),$newsletter?1:0,$notifications?1:0,locale(),$r['account_id']]);
            record_consent((int)$r['account_id'],'privacy_acknowledged',true);
            if($mailable) {
                record_consent((int)$r['account_id'],'newsletter',$newsletter);
                record_consent((int)$r['account_id'],'notifications',$notifications);
                record_consent((int)$r['account_id'],'payment_notices',true);
            }
        } elseif($purpose==='reset') {
            if($r['state']!=='active') throw new UserError(t('Dieser Zugang kann sein Passwort gerade nicht neu setzen. Bitte bei der Trainerin melden.','This login cannot set a new password right now. Please contact your coach.'));
            $pass=strong_password(post('password'));
            if($pass!==post('password_confirm')) throw new UserError(t('Die Passwörter stimmen nicht überein.','Passwords do not match.'));
            run('UPDATE accounts SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$r['account_id']]);
        } else {
            $u=require_user();
            if((int)$u['id']!==(int)$r['account_id']) throw new UserError(t('Bitte mit dem zugehörigen Konto anmelden.','Please sign in to the matching account.'));
            change_account_email((int)$r['account_id'],(string)$r['target_email']);
            // Confirmed from the new mailbox, which is what proves it is theirs.
            run('UPDATE accounts SET verified_at=? WHERE id=?',[now(),$r['account_id']]);
        }
        // Every link of the login, so none - this one included - works twice.
        run('DELETE FROM auth_tokens WHERE account_id=?',[$r['account_id']]);
        $signed=one('SELECT * FROM accounts WHERE id=?',[$r['account_id']]);
        sign_in($signed);
        audit('account.verified','account',(int)$r['account_id']);
        // Written down after sign_in(), so the actor is the holder of the link
        // rather than whoever this browser was signed in as. Mein Konto and the
        // access card list it for a fortnight (password_resets_for(),
        // signin_links_for()).
        if($r['purpose']==='reset') audit('account.password_reset','account',(int)$r['account_id']);
        if($r['purpose']==='signin') audit('account.signin_link_used','account',(int)$r['account_id']);
        if($ownStudent) {
            // Staff hear about somebody new, with a link to their page (spec S1).
            notify_staff('request',t('Neu im Portal: ','New in the portal: ').login_holder_name($signed),
                strtr(t('Hat sich über die Einladung an {email} eingerichtet. Noch in keinem Kurs.','Set up through the invitation to {email}. Not in a course yet.'),['{email}'=>(string)$signed['email']]),
                'student',['id'=>$ownStudent]);
        }
        if($purpose==='invite') {
            /* Set up for the first time, by mail or by link: the person lands
               on their own student page, where they correct or complete what
               staff entered or left out - every time, not only while
               family_next_steps() has something to say (ADR 0023 §5). Every
               later sign-in lands where landing_after_sign_in() says. */
            $own=login_student_id((int)$signed['id']);
            flash(strtr(t('Dein Konto ist bereit. Du meldest dich ab jetzt mit {login} an.','Your account is ready. From now on you sign in with {login}.'),['{login}'=>sign_in_name($signed)])
                .($own ? ' '.strtr(t('Willkommen, {name}! Schau kurz, ob alles stimmt, und ergänze, was fehlt. Frag deine Eltern, wenn du etwas nicht weißt.',
                                     'Welcome, {name}! Check that everything is right and fill in what is missing. Ask your parents if you are not sure.'),
                                   ['{name}'=>(string)scalar('SELECT first_name FROM students WHERE id=?',[$own])]) : ''));
            if($own) return ['student',['id'=>$own]];
        } elseif($purpose==='reset') {
            flash(t('Dein neues Passwort gilt ab sofort.','Your new password works from now on.'));
        } else {
            flash(t('Deine neue E-Mail-Adresse ist bestätigt. Du meldest dich ab jetzt mit ihr an.','Your new email address is confirmed. From now on you sign in with it.'));
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
           one is made in the portal (ADR 0021, §5). */
        $u=require_admin(); $role=choose(staff_role_posted(),assignable_roles($u));
        $email=email_value(required_text('email',254)); $name=required_text('name');
        invite_login($name,$email,$role,choose(post('locale','de'),['de','en']));
        flash(t('Konto angelegt. Die Einladung liegt im Postausgang.','Account created. The invitation is in the outbox.'));return ['accounts',[]];
    case 'student_invite':
        /* The access card's button. A post from a page that still offers
           mode=direct and a password box gets an ordinary invitation: nothing
           here reads either (ADR 0020, §5). */
        require_staff();
        $s=lock_row('students',(int)student((int)post('student_id'))['id']);
        // First: a card from before the child had a login says that, rather
        // than refusing the address it no longer offers to use.
        placeholder_of($s);
        $email=invitation_address((string)$s['email'],(int)$s['account_id']);
        invite_student($s,$email);
        flash(strtr(t('Die Einladung an {email} ist unterwegs.','The invitation to {email} is on its way.'),['{email}'=>$email]));
        return ['student',['id'=>$s['id']]];
    case 'email_invite':
        /* Option 1 of ADR 0021, §3: staff type an address and a language, and
           the person makes their own student on the page the link opens. A
           refusal comes back to the students page with what was typed, which
           opens the form again (held_for()). */
        require_staff();
        $email=email_value(post('email'));
        $locale=choose(post('locale','de'),['de','en']);
        // A login at the address is the first thing to sort out; invite_login()
        // asks it again as it writes.
        refuse_address_in_use($email);
        refuse_address_on_student_without_sign_in($email,t('die eingeladene Person','the person invited'));
        invite_login('',$email,'student',$locale);
        flash(strtr(t('Die Einladung an {email} ist unterwegs.','The invitation to {email} is on its way.'),['{email}'=>$email]));
        return ['students',['invitations'=>1]];
    case 'account_state':
        $u=require_staff();$id=(int)post('id');$mode=choose(post('mode'),['suspend','restore','delete','withdraw','reinvite','reset_link']);
        $a=one('SELECT * FROM accounts WHERE id=? FOR UPDATE',[$id]);
        // Nobody acts on their own login here - that is Mein Konto or
        // „vergessen" - and a trainer only on a student's.
        if(!$a || (int)$u['id']===$id || ($u['role']!=='admin' && $a['role']!=='student')) throw new UserError(t('Dieses Konto kann hier nicht geändert werden.','This account cannot be changed here.'));
        // Lock all administrators so concurrent requests cannot remove the last one.
        $admins=rows("SELECT id FROM accounts WHERE role='admin' AND state='active' FOR UPDATE");
        if($a['role']==='admin' && $a['state']==='active' && count($admins)<=1 && in_array($mode,['suspend','delete','withdraw'],true)) throw new UserError(t('Der letzte Administrator muss erhalten bleiben.','The last administrator must remain active.'));
        // A student's login is managed from that student's page, so that is where
        // she lands again, and an invitation by address from the students page's
        // list. Looked up now, because after a delete the foreign key has
        // already cut the link and there is nobody left to find. Whatever the
        // login's role: delete_login() refuses any login a student points to,
        // and sent her to the student's page, which sent her back here.
        $studentId=login_student_id($id);
        $back=$studentId?['student',['id'=>$studentId]]:(is_open_invitation($id)?['students',['invitations'=>1]]:['accounts',[]]);
        $said=t('Konto aktualisiert.','Account updated.');
        // A staff login on a student's record, left from before ADR 0010, only
        // lets go of the child when its sign-in is deleted or withdrawn there:
        // the child gets a fresh placeholder, and the team member keeps her
        // login, her chats and her role (replace_login_with_placeholder()).
        $letGo=$studentId && $a['role']!=='student' && in_array($mode,['delete','withdraw'],true);
        // A placeholder signs in with nothing (ADR 0023 §3): there is nothing to
        // suspend, send, withdraw or delete. Its way forward is an invitation or
        // a username, on the access card.
        if($a['state']==='placeholder')
            throw new UserError(t('Diese Anmeldung ist noch leer – damit meldet sich niemand an. Lade auf der Seite des Kindes ein oder vergib einen Benutzernamen.',
                                  'This login is still empty – nobody signs in with it. Invite from the child’s page, or give a username there.'));
        if($mode==='reinvite') {
            // An invitation goes to an address; a username login waiting for its
            // first sign-in gets a new sign-in link instead (ADR 0023 §6).
            if($a['state']!=='invited' || (string)($a['email']??'')==='') throw new UserError(t('Nur offene Einladungen können erneut versendet werden.','Only pending invitations can be resent.'));
            cancel_account_mail($id);send_account_token($a,'invite');
            $said=t('Die Einladung ist noch einmal an ','The invitation is on its way to ').$a['email']
                .t(' unterwegs. Der alte Link gilt nicht mehr.',' again. The old link no longer works.');
        } elseif($mode==='reset_link') {
            /* A link to set a new password, mailed to the login's own address
               (ADR 0020, §5). Staff see neither the password nor the link: the
               outbox never shows a security mail's body. Only for a login in
               use - an invitation is sent again instead, a suspended login is
               restored first. */
            if(!reset_link_possible($a))
                throw new UserError(t('Ein Link für ein neues Passwort geht nur an einen Zugang, der in Gebrauch ist. Eine offene Einladung erneut senden; einen gesperrten Zugang zuerst entsperren.',
                                      'A link for a new password only goes to a login in use. Send an open invitation again; restore a suspended login first.'));
            send_account_token($a,'reset');
            // The address, so she can tell the family where to look; never the link.
            $said=t('Ein Link für ein neues Passwort ist an ','A link for a new password is on its way to ').$a['email']
                .strtr(t(' unterwegs. Er gilt {valid}; bis dahin gilt das alte Passwort weiter.','. It is valid for {valid}; until then the old password keeps working.'),
                       ['{valid}'=>token_lifetime_words('reset',locale()==='en')]);
        } elseif($mode==='delete') {
            // The sign-in name, typed: the address, or the username of a login
            // without one - it says which login this is (ADR 0021 §1, 0023 §4).
            if(!typed_sign_in_name_matches(post('confirmation'),$a))
                throw new UserError((string)($a['email']??'')!==''
                    ? t('Zum Löschen die E-Mail-Adresse eingeben.','Enter the email address to delete the login.')
                    : t('Zum Löschen den Benutzernamen eingeben.','Enter the username to delete the login.'));
            if($studentId) {
                // Never without a login: the student is given a fresh, empty one,
                // and this one goes (ADR 0023 §4) - a team member's only lets go
                // ($letGo). Named from the student's own row: by now no student
                // points to the old login to ask.
                replace_login_with_placeholder($student=lock_row('students',$studentId),$a);
                $said=strtr(t('Die Anmeldung {login} ist gelöscht, mit ihren privaten Unterhaltungen. {name} ist jetzt ohne Anmeldung; Kurse, Beiträge und Rechnungen bleiben. Eine neue Einladung oder einen Benutzernamen gibst du hier.',
                              'The login {login} has been deleted, with its private conversations. {name} is now without sign-in; courses, charges and invoices stay. You give a new invitation or a username here.'),
                            ['{login}'=>sign_in_name($a),'{name}'=>$student['first_name'].' '.$student['last_name']]);
            } else delete_login($id);
        } elseif($mode==='withdraw') {
            // Nothing is lost that inviting again would not bring back, so
            // nothing is typed to confirm it - but only for a login never set up.
            if($a['verified_at']!==null) throw new UserError(t('Zurückziehen lässt sich nur eine Einladung, die noch nicht angenommen ist.','Only an invitation not yet accepted can be withdrawn.'));
            if($studentId) {
                // An invitation, or a username waiting for its first sign-in:
                // the student is given a fresh placeholder, which frees the
                // address or the username for whatever comes next (ADR 0023 §4).
                replace_login_with_placeholder($student=lock_row('students',$studentId),$a);
                $name=$student['first_name'].' '.$student['last_name'];
                $said=(string)($a['email']??'')!==''
                    ? strtr(t('Die Einladung an {email} ist zurückgezogen. {name} ist wieder ohne Anmeldung – du kannst neu einladen oder einen Benutzernamen vergeben.',
                              'The invitation to {email} has been withdrawn. {name} is without sign-in again – you can invite again or give a username.'),['{email}'=>(string)$a['email'],'{name}'=>$name])
                    : strtr(t('Die Anmeldung {username} ist zurückgezogen; ihr Link gilt nicht mehr. {name} ist wieder ohne Anmeldung.',
                              'The sign-in {username} has been withdrawn; its link no longer works. {name} is without sign-in again.'),['{username}'=>(string)$a['username'],'{name}'=>$name]);
            } else {
                delete_login($id);
                $said=strtr(t('Die Einladung an {email} ist zurückgezogen. Versehentlich? Lade die Adresse einfach neu ein.',
                              'The invitation to {email} has been withdrawn. By mistake? Just invite the address again.'),['{email}'=>(string)$a['email']]);
            }
        } else {
            // Suspending ends every session and every link, sign-in links
            // included; restoring puts back the state it had (ADR 0023 §3).
            if($mode==='restore' && $a['state']!=='suspended') throw new UserError(t('Dieser Zugang ist nicht gesperrt.','This login is not suspended.'));
            run('DELETE FROM auth_tokens WHERE account_id=?',[$id]);cancel_account_mail($id);
            run('UPDATE accounts SET state=?,auth_version=auth_version+1 WHERE id=?',[$mode==='suspend'?'suspended':($a['verified_at']?'active':'invited'),$id]);
        }
        // Said, and written down, as what happened: the team member's login let
        // go of the child, and was neither deleted nor withdrawn - it is still
        // there, on Konten.
        if($letGo) $said=strtr(t('{name} hat jetzt eine neue, leere Anmeldung. Die Anmeldung von {staff} gehört zum Team und bleibt, wie sie ist.',
                                 '{name} now has a new, empty login. The login of {staff} belongs to the team and stays as it is.'),
                               ['{name}'=>$student['first_name'].' '.$student['last_name'],'{staff}'=>login_holder_name($a)]);
        audit('account.'.($letGo?'let_go':$mode),'account',$id);flash($said);
        return $back;
    case 'student_save':
        /* Saving a student who exists. Making one is the wizard's - student_draft
           and student_create (ADR 0023 §5) - because two ways to make a student
           would be two places to remember the login, and the second is the one
           that forgets. */
        $u=require_user();
        $existing=student((int)post('id'));$id=(int)$existing['id'];
        [$first,$last]=posted_names();$birth=birth_date_value(post('birth_date'));
        // One line, the way a registration form asks it, because it is typed
        // once and never sorted on. Her own number rather than an emergency
        // contact's: for an adult member those are the same person, and listing
        // yourself as the person to ring is not a record anybody should have to
        // keep. The family writes both as well (ADR 0020, §7): the address is
        // the one their next invoice is made out to.
        $address=text_limit('address',200);$phone=text_limit('phone',60);
        // One text for both roles: a family cannot "compare the changes", and
        // the held form does bring back what they typed (spec §6.2).
        $stale=fn()=>new UserError(t('Inzwischen hat jemand anderer etwas an diesem Profil gespeichert. Deine Eingaben sind noch da – bitte prüfen und noch einmal speichern.',
                                     'Somebody else saved something on this profile in the meantime. What you typed is still here – please check it and save again.'));
        $readdress=false;
        if(is_staff($u)) {
            // account_id is neither read nor written here: a login is given by
            // invite_student() or give_student_username() and replaced by a
            // placeholder on the access card, nothing else (ADR 0010, 0023 §4).
            // A page from before that rule may still post it.
            //
            // Nor are tariff_id, price_cents and price_note (ADR 0011). What a
            // child pays is decided per course, on the enrolment, and that is
            // what billing reads; the student's own columns bill nobody. They
            // keep whatever they hold, because a page from before still posts
            // the old box, and a form without it would blank them on every save.
            $status=post('status');if(!isset(statuses()[$status]) && $status!==$existing['status']) throw new UserError(t('Bitte einen Status auswählen.','Please choose a status.'));
            // A child is always in a level, so an unanswered field means the
            // default rather than nothing. An age group is the opposite: blank is
            // the normal answer and means "work it out from the date of birth",
            // which keeps being right as they have birthdays.
            $levelId=reference_or_null('levels','level_id') ?? (int)(level_default()['id'] ?? 0) ?: null;
            $ageGroupId=reference_or_null('age_groups','age_group_id');
            $join=date_value(post('joined_on'));$end=date_value(post('ended_on'));date_range($join,$end);
            // Where the portal writes to this student: the invitation, invoices,
            // reminders - and, once they sign in with it, what they sign in with.
            // Optional while nothing signs in with it. Once the student's login
            // has an address it is that login's, and this copy is kept equal to it.
            $posted=post('email')!==''?email_value(post('email')):'';
            $account=$existing['account_id']?lock_row('accounts',(int)$existing['account_id']):null;
            if((string)($account['email']??'')==='') {
                // Nothing signs in with the address on the record: a placeholder
                // signs in with nothing, a username login with its username (ADR
                // 0023 §3). So a brother and a sister without a login may share
                // it. A new or changed address that is already somebody's login
                // is refused (ADR 0010, restored by 0020 §1); one already stored
                // is tolerated, so her other edits still save.
                $email=$posted;
                if($email!=='' && !same_address($email,(string)$existing['email'])) refuse_address_in_use($email);
            } else {
                $email=(string)$account['email'];
                // Nothing posted means nothing to change: an empty or missing
                // field cannot be a request to take the address its login signs
                // in with away.
                if($posted!=='' && !same_address($posted,$email)) {
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
            $args=[$first,$last,$email,$address,$phone,$birth,$join,$end,$status,$levelId,$ageGroupId,text_limit('internal_notes',12000),now()];
            // One tracked change for the whole save, so it is one line in the
            // change log and a stale form is refused as a whole. The login moves
            // inside it too, so the log shows the new address on the student it
            // belongs to.
            tracked('students',$id,$first.' '.$last,function() use ($args,$id,$account,$readdress,$email,$stale) {
                $updated=run('UPDATE students SET first_name=?,last_name=?,email=?,address=?,phone=?,birth_date=?,joined_on=?,ended_on=?,status=?,level_id=?,age_group_id=?,internal_notes=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?',[...$args,$id,(int)post('revision')]);
                if(!$updated->rowCount()) throw $stale();
                if($readdress) change_account_email((int)$account['id'],$email);
            });
            // The first invitation went to the old address and has just been
            // made useless; this is the one that works.
            if($readdress) {
                send_account_token(one('SELECT * FROM accounts WHERE id=?',[(int)$account['id']]),'invite');
                audit('account.readdressed','account',(int)$account['id']);
            }
        } else {
            /* A family completing its own details (ADR 0020, §7): names, birth
               date, postal address and phone. Nothing else, whatever is posted.
               Authorised by student()'s scoping above and nothing else, and
               tracked like every other change to a student, so she sees it in
               the change log with the family as the actor. */
            // A page that does not carry the two boxes - one opened before
            // families were shown them - leaves both as they are: nothing
            // posted is nothing to change, and an empty box that was posted
            // still empties it.
            if(!array_key_exists('address',$_POST)) $address=(string)$existing['address'];
            if(!array_key_exists('phone',$_POST)) $phone=(string)$existing['phone'];
            tracked('students',$id,$first.' '.$last,function() use ($first,$last,$birth,$address,$phone,$id,$stale) {
                $updated=run('UPDATE students SET first_name=?,last_name=?,birth_date=?,address=?,phone=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?',[$first,$last,$birth,$address,$phone,now(),$id,(int)post('revision')]);
                if(!$updated->rowCount()) throw $stale();
            });
        }
        audit('student.saved','student',$id);
        flash((is_staff($u)?t('Schüler gespeichert.','Student saved.'):t('Deine Angaben sind gespeichert.','Your details are saved.'))
            .($readdress?' '.t('Die Einladung ist an die neue Adresse unterwegs; der Link an die alte gilt nicht mehr.','The invitation is on its way to the new address; the link sent to the old one no longer works.'):''));
        return ['student',['id'=>$id]];
    case 'student_delete':
        require_staff();$s=student((int)post('id'));
        if(post('confirmation')!==$s['first_name'].' '.$s['last_name']) throw new UserError(t('Bitte den vollständigen Namen eingeben.','Please enter the full name.'));
        if(scalar('SELECT COUNT(*) FROM charges WHERE student_id=?',[$s['id']])) throw new UserError(t('Es sind Beiträge vorhanden. Mitgliedschaft stattdessen beenden; Zahlungsdaten bleiben erhalten.','Charges exist. End the membership instead to retain payment records.'));
        $login=$s['account_id']?lock_row('accounts',(int)$s['account_id']):null;
        tracked('students',(int)$s['id'],$s['first_name'].' '.$s['last_name'],fn()=>run('DELETE FROM students WHERE id=?',[$s['id']]),'delete');
        audit('student.deleted','student',(int)$s['id']);
        // A student's login never set up goes with them (ADR 0021, §4): its
        // live link would otherwise let the person make the record again, and a
        // placeholder is nobody's without its student (ADR 0023 §4). One that was
        // set up stays, as a login left behind on Konten (ADR 0010) - without
        // its sign-in links: staff made those as a key to a child's login, and
        // there is no child behind it any more.
        $withdrawn=login_goes_with_student($login);
        if($withdrawn) {
            delete_login((int)$login['id']);
            audit('account.withdraw','account',(int)$login['id']);
        } elseif($login) run("DELETE FROM auth_tokens WHERE account_id=? AND purpose='signin'",[(int)$login['id']]);
        // The change log keeps the deleted row to read, not to restore (app/history.php).
        flash(t('Schüler gelöscht. Unter „Änderungen“ steht, was gelöscht wurde; wiederherstellen lässt es sich nicht.',
                'Student deleted. “Changes” shows what was deleted; it cannot be restored.')
            .match(true) {
                !$withdrawn || $login['state']==='placeholder' => '',
                (string)($login['email']??'')!=='' => ' '.strtr(t('Die Einladung an {email} gilt nicht mehr.','The invitation to {email} no longer works.'),['{email}'=>(string)$login['email']]),
                default => ' '.strtr(t('Der Anmeldelink für {username} gilt nicht mehr.','The sign-in link for {username} no longer works.'),['{username}'=>(string)$login['username']]),
            });
        return ['students',[]];
    case 'student_draft':
        /* Step 1 of the wizard „Schüler anlegen" (ADR 0023 §5): who is joining,
           checked, and kept in the session under a key of its own - the only
           place a draft is written. The address carries the key and never the
           details, so names and birth dates stay out of the web server's log
           and the browser's history. Nothing reaches the database until
           student_create. A draft being changed keeps its key; a new one gets a
           fresh one, so two tabs cannot mix two children. */
        require_staff();
        $from=in_array(post('from'),['dashboard','students','start'],true) ? ['from'=>post('from')] : [];
        // Back twice to step 1 and „Weiter": that draft is a child already, and
        // starting it again is how the child would be made twice. Its page
        // says so, with the way to the done page.
        if(student_made_from_draft(post('draft'))) return ['student_new',['draft'=>post('draft')]+$from];
        $draft=student_draft_posted();
        $key=student_draft(post('draft')) ? post('draft') : bin2hex(random_bytes(16));
        keep_student_draft($key,$draft);
        return ['student_new',['draft'=>$key]+$from];
    case 'student_create':
        /* Step 2: the student, their login and their course, in one transaction
           (ADR 0023 §5). Every refusal comes before the first write, and comes
           back to step 2 with the draft whole: the slot records which student
           it became only once everything is written. method is how they sign in
           - by e-mail, with a username, or not yet. */
        require_staff();
        $key=post('draft');
        // Step 2 sent again - reloaded after the done page, or Back and „Anlegen"
        // on a page with a new form - lands where the draft says it went.
        if(student_made_from_draft($key)) return ['student_new',['draft'=>$key]];
        $draft=student_draft($key);
        if(!$draft) throw new UserError(t('Die Angaben waren nicht mehr da. Bitte noch einmal eintragen.','The details were no longer there. Please enter them again.'));
        $method=choose(post('method'),['email','username','none']);
        // Checked again: a status removed since, a course archived or filled
        // since step 1, are refused now rather than written.
        $draft=student_draft_checked($draft);
        $locale='de';$email='';$username='';
        if($method==='email') {
            $email=invitation_address(post('email'));
            refuse_address_on_student_without_sign_in($email,(string)$draft['first_name']);
            $locale=choose(post('locale','de'),['de','en']);
        } elseif($method==='username') $username=username_to_give(post('username'));
        $name=$draft['first_name'].' '.$draft['last_name'];
        $id=tracked_insert('students',$name,fn()=>create_student($draft+['email'=>$email]));
        if($draft['class_id']) enrol_student($draft['class_id'],$id,$draft['tariff_id'],today());
        $student=lock_row('students',$id);
        if($method==='email') invite_student($student,$email,$locale);
        elseif($method==='username') give_student_username($student,$username);
        audit('student.saved','student',$id);
        keep_student_draft($key,['made'=>$id]);
        flash(strtr(t('{name} ist angelegt.','{name} has been added.'),['{name}'=>$name]));
        return ['student_new',['step'=>'done','id'=>$id]];
    case 'signin_link':
        /* „Anmeldelink" on the access card (ADR 0023 §6): make one, or withdraw
           the one there is. Counted per member of staff before anything is
           written, twenty an hour. Who may make one for whom is
           may_create_signin_link()'s answer alone, asked of the locked login; a
           placeholder is given its username in the same request. The readable
           link goes into the maker's session and nowhere else. */
        $u=require_staff();
        throttle('signin-link',(string)$u['id'],20,3600);
        $mode=choose(post('mode','create'),['create','withdraw']);
        $student=lock_row('students',(int)student((int)post('student_id'))['id']);
        $login=student_login_locked($student);
        if($mode==='withdraw') {
            // Withdrawing only takes a key away, so any member of staff may. A
            // link already used or lapsed has nothing left to withdraw, and the
            // audit does not say otherwise.
            $withdrawn=run("DELETE FROM auth_tokens WHERE account_id=? AND purpose='signin'",[(int)$login['id']])->rowCount();
            forget_signin_link((int)$login['id']);
            if($withdrawn) audit('account.signin_link_withdrawn','account',(int)$login['id']);
            flash($withdrawn ? t('Der Anmeldelink ist zurückgezogen und gilt nicht mehr.','The sign-in link has been withdrawn and no longer works.')
                             : t('Es gab keinen Anmeldelink mehr, der gilt.','There was no working sign-in link left.'));
            return ['student',['id'=>$student['id'],'#'=>'access']];
        }
        if(!may_create_signin_link($u,$login))
            throw new UserError(match(true) {
                $login['state']==='suspended' => t('Für einen gesperrten Zugang gibt es keinen Anmeldelink. Entsperre ihn zuerst.','A suspended login gets no sign-in link. Restore it first.'),
                $login['state']==='invited' && (string)($login['email']??'')!=='' => t('Für eine Einladung per E-Mail gibt es keinen Anmeldelink: die Adresse wäre sonst nie bestätigt, und Rechnungen gingen dorthin. Prüf die Adresse und sende die Einladung noch einmal – oder zieh sie zurück und vergib einen Benutzernamen.',
                                                                                        'An invitation by email gets no sign-in link: the address would never be confirmed, and invoices would go there. Check the address and send the invitation again – or withdraw it and give a username.'),
                signin_link_possible($login) => t('Einen Anmeldelink für einen Zugang, der schon benutzt wird, erstellt nur eine Administratorin.','Only an administrator creates a sign-in link for a login that is already in use.'),
                default => t('Für diesen Zugang gibt es keinen Anmeldelink.','This login gets no sign-in link.'),
            });
        if($login['state']==='placeholder') {
            $username=username_to_give(post('username'));
            give_student_username($student,$username);
        } else make_signin_link($login);
        flash(strtr(t('Der Anmeldelink ist erstellt. Er gilt einmal, {valid} lang; ein früherer gilt nicht mehr.','The sign-in link has been created. It works once, for {valid}; any earlier one no longer works.'),
                    ['{valid}'=>token_lifetime_words('signin',locale()==='en')]));
        return ['student',['id'=>$student['id'],'#'=>'access']];
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
        audit('contact.deleted','student',(int)$s['id']);
        // There is no undo: this sentence is the family's way back, so it says
        // exactly who went (spec §6.3). The trainer also has the whole row in
        // the change log.
        flash(t('Entfernt: ','Removed: ').$contact['owner_name'].' ('.$contact['relation_label'].')'
            .((string)$contact['phone']!==''?', '.$contact['phone']:'').((string)$contact['email']!==''?', '.$contact['email']:'')
            .t('. Aus Versehen? Trag die Person unten unter „Notfallkontakt hinzufügen“ wieder ein.','. By mistake? Just add them again below under “Add an emergency contact”.'));
        return ['student',['id'=>$s['id'],'tab'=>'contacts']];
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
        require_staff();$c=cancel_charge((int)post('id'));
        return ['student',['id'=>$c['student_id'],'tab'=>'payments']];
    case 'payment_add':
        $u=require_staff();$c=one('SELECT c.*,'.charge_recorded_sql().' AS recorded FROM charges c WHERE c.id=? FOR UPDATE',[(int)post('charge_id')]);
        if(!$c)throw new NotFound(t('Diesen Beitrag gibt es nicht.','No such charge.'));
        if($c['cancelled'])throw new UserError(t('Dieser Beitrag ist storniert. Darauf lässt sich keine Zahlung erfassen.','That charge has been cancelled. No payment can be recorded against it.'));
        $amount=cents(post('amount'),false);
        if($amount+(int)$c['recorded']>(int)$c['amount_cents']) throw new UserError(t('Der Betrag übersteigt den noch nicht erfassten Beitrag. Auch unbestätigte Zahlungen zählen hier.','The amount exceeds the charge not yet recorded, including unconfirmed payments.'));
        $confirmed=(bool)post('confirmed');
        run('INSERT INTO payments (charge_id,amount_cents,paid_on,method,note,confirmed_by,confirmed_at) VALUES (?,?,?,?,?,?,?)',[$c['id'],$amount,date_value(post('paid_on'),true),choose(post('method'),setting('payment_methods',['Überweisung','Bar'])),text_limit('note'),$confirmed?$u['id']:null,$confirmed?now():null]);
        audit('payment.recorded','payment',(int)db()->lastInsertId());flash(t('Zahlung erfasst.','Payment recorded.'));return ['student',['id'=>$c['student_id'],'tab'=>'payments']];
    case 'payment_state':
        require_staff();$p=one('SELECT p.*,c.student_id FROM payments p JOIN charges c ON c.id=p.charge_id WHERE p.id=? FOR UPDATE',[(int)post('id')]);
        if(!$p)throw new NotFound(t('Diese Zahlung gibt es nicht.','No such payment.'));
        if($p['voided'])throw new UserError(t('Diese Zahlung ist bereits storniert.','That payment has already been voided.'));
        $mode=choose(post('mode'),['confirm','void']);
        if($mode==='confirm') confirm_payment($p);
        else tracked('payments',(int)$p['id'],money((int)$p['amount_cents']),fn()=>run('UPDATE payments SET voided=1 WHERE id=?',[$p['id']]));
        audit('payment.'.$mode,'payment',(int)$p['id']);return ['student',['id'=>$p['student_id'],'tab'=>'payments']];
    }
    return dispatch_config($action);
}
