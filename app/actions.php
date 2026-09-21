<?php
declare(strict_types=1);

function handle_post(): array {
    $action=post('action');
    if(!hash_equals(csrf(),post('csrf'))) throw new UserError(t('Die Sitzung ist abgelaufen. Seite neu laden.','Your session expired. Reload the page.'));
    $ip=$_SERVER['REMOTE_ADDR']??'local';
    if(in_array($action,['login','forgot','activate'],true)) {
        throttle('auth-ip',$ip,60);
        if($action!=='activate') throttle($action,mb_strtolower(post('email')),10);
    }
    if(in_array($action,['password_change','email_change'],true)) {
        $actor=require_user();throttle('account-security',(string)$actor['id'],10);
    }
    $request=post('request_id');
    // One transaction around the whole action: it either happens or it does not.
    return transactional(function() use ($request,$action) {
        claim_request($request);
        return dispatch_action($action);
    });
}

function dispatch_action(string $action): array {
    switch($action) {
    case 'login':
        $a=one('SELECT * FROM accounts WHERE email=? FOR UPDATE',[mb_strtolower(post('email'))]);
        $hash=$a['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if(!password_verify(post('password'),$hash) || !$a || $a['state']!=='active' || !$a['verified_at']) throw new UserError(t('Anmeldung nicht möglich. Zugangsdaten und Einladung prüfen.','Unable to sign in. Check your credentials and invitation.'));
        if(password_needs_rehash($hash,PASSWORD_DEFAULT)) run('UPDATE accounts SET password_hash=? WHERE id=?',[password_hash(post('password'),PASSWORD_DEFAULT),$a['id']]);
        sign_in($a); return ['dashboard',[]];
    case 'logout':
        $_SESSION=[]; session_regenerate_id(true); current_user(true); return ['login',[]];
    case 'forgot':
        $a=one("SELECT * FROM accounts WHERE email=? AND state='active' AND verified_at IS NOT NULL",[mb_strtolower(post('email'))]);
        if($a && setting('smtp',[]) && setting('privacy_ready',false)) send_account_token($a,'reset');
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
            run('UPDATE accounts SET email=?,verified_at=?,auth_version=auth_version+1 WHERE id=?',[$r['target_email'],now(),$r['account_id']]);
            cancel_account_mail((int)$r['account_id']);
        } else throw new UserError('Invalid token');
        run('DELETE FROM auth_tokens WHERE account_id=?',[$r['account_id']]);
        unset($_SESSION['activation_hash']);
        sign_in(one('SELECT * FROM accounts WHERE id=?',[$r['account_id']]));
        audit('account.verified','account',(int)$r['account_id']);
        flash(t('Dein Konto ist bereit.','Your account is ready.')); return ['dashboard',[]];
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
        $u=require_staff(); $role=choose(post('role','student'),assignable_roles($u));
        $email=email_value(required_text('email',254));
        run('INSERT INTO accounts (name,email,role,locale,created_at) VALUES (?,?,?,?,?)',[required_text('name'),$email,$role,choose(post('locale','de'),['de','en']),now()]);
        $id=(int)db()->lastInsertId();
        send_account_token(one('SELECT * FROM accounts WHERE id=?',[$id]),'invite');audit('account.invited','account',$id);
        flash(t('Konto angelegt. Die Einladung liegt im Postausgang.','Account created. The invitation is in the outbox.'));return ['accounts',[]];
    /* A login made here and now, with a password typed rather than emailed.
       Inviting needs working SMTP and a released privacy notice, which is right
       for a real family and wrong for every other reason somebody needs an
       account: trying the portal out before the mail is set up, a second
       administrator on the day the first one loses their phone, a trainer who
       stands next to her and can pick a password on the spot. Administrator
       only, because handing out a login is more than inviting one. */
    case 'account_create':
        $u=require_admin();
        $role=choose(post('role','student'),assignable_roles($u));
        $email=email_value(required_text('email',254));
        $password=(string)post('password'); strong_password($password);
        if(one('SELECT id FROM accounts WHERE email=?',[$email]))
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
        // A family account with nothing attached to it signs in and sees an
        // empty portal, which looks like a broken login rather than a missing
        // link. Inviting from the child's page already joins the two by address;
        // doing it here as well means the two ways in agree.
        $linked=0;
        if($role==='student')
            $linked=run('UPDATE students SET account_id=?,updated_at=?,revision=revision+1 WHERE email=? AND account_id IS NULL',
                        [$id,now(),$email])->rowCount();
        audit($linked?'account.created_directly_and_linked':'account.created_directly','account',$id);
        flash(t('Konto angelegt. Es kann sich sofort mit diesem Passwort anmelden – die Adresse wurde dabei nicht bestätigt.',
                'Account created. It can sign in with that password straight away – the address was not confirmed.')
              .($linked?' '.plural($linked,'Kind wurde damit verknüpft.','Kinder wurden damit verknüpft.',
                                   'child was linked to it.','children were linked to it.'):''));
        return ['accounts',[]];
    case 'student_invite':
        /* The invitation goes to the child's own record rather than to one of
           the people on their emergency list. Those are two different questions
           - who do I ring when she falls over, who reads the invoices - and one
           row answering both is how a grandmother with no email ended up being
           the reason a family could not sign in.

           An address that already has an account is linked rather than
           duplicated, which is what makes "both parents" and "three siblings on
           one login" work without a second concept. */
        $u=require_staff();$s=student((int)post('student_id'));
        if($s['account_id']) throw new UserError(t('Dieses Kind ist schon einem Konto zugeordnet.','This child already belongs to an account.'));
        $email=email_value(post('email')!==''?post('email'):(string)$s['email']);
        $name=trim(post('name'))!==''?required_text('name'):$s['first_name'].' '.$s['last_name'];
        $existing=one('SELECT * FROM accounts WHERE email=? FOR UPDATE',[$email]);
        if($existing) {
            if($existing['role']!=='student') throw new UserError(t('Diese Adresse gehört schon zu einem Konto der Verwaltung.','That address already belongs to a management account.'));
            $accountId=(int)$existing['id'];
        } else {
            run('INSERT INTO accounts (name,email,role,locale,created_at) VALUES (?,?,?,?,?)',
                [$name,$email,'student',choose(post('locale','de'),['de','en']),now()]);
            $accountId=(int)db()->lastInsertId();
        }
        run('UPDATE students SET account_id=?,email=?,updated_at=?,revision=revision+1 WHERE id=?',[$accountId,$email,now(),$s['id']]);
        // An account that has already set a password is being given another
        // child to look after, not invited again: a second invitation would
        // reset a password that works.
        $account=one('SELECT * FROM accounts WHERE id=?',[$accountId]);
        if($account['state']==='invited' && !$account['verified_at']) { send_account_token($account,'invite'); $sent=true; }
        else $sent=false;
        audit($existing?'account.linked':'account.invited','account',$accountId);
        flash($sent
            ? t('Einladung liegt im Postausgang.','The invitation is in the outbox.')
            : t('Das Kind wurde dem bestehenden Konto zugeordnet. Es kann sich wie bisher anmelden.','The child was added to the existing account. It signs in as before.'));
        return ['student',['id'=>$s['id']]];
    case 'account_state':
        $u=require_staff();$id=(int)post('id');$mode=choose(post('mode'),['suspend','restore','delete','reinvite']);
        $a=one('SELECT * FROM accounts WHERE id=? FOR UPDATE',[$id]);
        if(!$a || (int)$u['id']===$id || ($u['role']!=='admin' && $a['role']!=='student')) throw new UserError(t('Dieses Konto kann hier nicht geändert werden.','This account cannot be changed here.'));
        // Lock all administrators so concurrent requests cannot remove the last one.
        $admins=rows("SELECT id FROM accounts WHERE role='admin' AND state='active' FOR UPDATE");
        if($a['role']==='admin' && $a['state']==='active' && count($admins)<=1 && in_array($mode,['suspend','delete'],true)) throw new UserError(t('Der letzte Administrator muss erhalten bleiben.','The last administrator must remain active.'));
        if($mode==='reinvite') {
            if($a['state']!=='invited') throw new UserError(t('Nur offene Einladungen können erneut versendet werden.','Only pending invitations can be resent.'));
            cancel_account_mail($id);send_account_token($a,'invite');
        } else {
            run('DELETE FROM auth_tokens WHERE account_id=?',[$id]);cancel_account_mail($id);
            if($mode==='delete') {
                if(mb_strtolower(post('confirmation'))!==$a['email']) throw new UserError(t('Zum Löschen die E-Mail-Adresse eingeben.','Enter the email address to delete the account.'));
                run('DELETE FROM mail_jobs WHERE account_id=?',[$id]);
                run('DELETE FROM accounts WHERE id=?',[$id]);
            } else run('UPDATE accounts SET state=?,auth_version=auth_version+1 WHERE id=?',[$mode==='suspend'?'suspended':($a['verified_at']?'active':'invited'),$id]);
        }
        audit('account.'.$mode,'account',$id);flash(t('Konto aktualisiert.','Account updated.'));return ['accounts',[]];
    case 'student_save':
        $u=require_user();$id=(int)post('id');$existing=$id?student($id):null;
        if(!$existing) require_staff();
        $first=required_text('first_name',100);$last=required_text('last_name',100);$birth=date_value(post('birth_date'));
        if(is_staff($u)) {
            $accountId=(int)post('account_id')?:null;
            if($accountId && !one("SELECT id FROM accounts WHERE id=? AND role='student'",[$accountId])) throw new UserError(t('Schülerkonto nicht gefunden.','Student account not found.'));
            $tariffId=(int)post('tariff_id')?:null;$tariff=$tariffId?one('SELECT * FROM tariffs WHERE id=?',[$tariffId]):null;
            if($tariffId && (!$tariff || ($tariff['archived'] && $tariffId!==(int)($existing['tariff_id']??0)))) throw new UserError(t('Tarif ist nicht verfügbar.','Tariff is not available.'));
            // Through tariff_price(): a tariff has a rate per interval now, not a
            // price column, and reading a column that no longer exists would have
            // filed the student at no price at all rather than at the tariff's.
            $price=post('price')!==''?cents(post('price')):tariff_price($tariffId);
            $status=post('status');if(!isset(statuses()[$status]) && !($existing && $status===$existing['status'])) throw new UserError(t('Bitte einen Status auswählen.','Please choose a status.'));
            // A child is always in a level, so an unanswered field means the
            // default rather than nothing. An age group is the opposite: blank is
            // the normal answer and means "work it out from the date of birth",
            // which keeps being right as they have birthdays.
            $levelId=reference_or_null('levels','level_id') ?? (int)(level_default()['id'] ?? 0) ?: null;
            $ageGroupId=reference_or_null('age_groups','age_group_id');
            $join=date_value(post('joined_on'));$end=date_value(post('ended_on'));date_range($join,$end);
            // Where the portal writes to this family, which for a child is a
            // parent's address. Optional while the record is being set up, and
            // asked for by contact_gap() until it is there.
            $email=post('email')!==''?email_value(post('email')):'';
            // One line, the way the paper form asks it, because it is typed once
            // and printed once and never sorted on. Her own number rather than an
            // emergency contact's: for an adult member those are the same person,
            // and listing yourself as the person to ring is not a record anybody
            // should have to keep.
            $address=text_limit('address',200);$phone=text_limit('phone',60);
            $args=[$accountId,$first,$last,$email,$address,$phone,$birth,$join,$end,$status,$levelId,$ageGroupId,$tariffId,$price,text_limit('price_note'),text_limit('internal_notes',12000),now()];
            if($id) {
                tracked('students',$id,$first.' '.$last,function() use ($args,$id) {
                    $updated=run('UPDATE students SET account_id=?,first_name=?,last_name=?,email=?,address=?,phone=?,birth_date=?,joined_on=?,ended_on=?,status=?,level_id=?,age_group_id=?,tariff_id=?,price_cents=?,price_note=?,internal_notes=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?',[...$args,$id,(int)post('revision')]);
                    if(!$updated->rowCount())throw new UserError(t('Der Eintrag wurde inzwischen geändert. Bitte neu laden und die Änderungen vergleichen.','This record has changed. Reload it and compare the changes before saving.'));
                });
            }
            else {$id=tracked_insert('students',$first.' '.$last,function() use ($args) {
                run('INSERT INTO students (account_id,first_name,last_name,email,address,phone,birth_date,joined_on,ended_on,status,level_id,age_group_id,tariff_id,price_cents,price_note,internal_notes,updated_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$args,now()]);
                return (int)db()->lastInsertId();
            });}
        } else {
            $updated=run('UPDATE students SET first_name=?,last_name=?,birth_date=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?',[$first,$last,$birth,now(),$id,(int)post('revision')]);
            if(!$updated->rowCount())throw new UserError(t('Der Eintrag wurde inzwischen geändert. Bitte neu laden und die Änderungen vergleichen.','This record has changed. Reload it and compare the changes before saving.'));
        }
        save_custom_fields($id,!$existing);audit('student.saved','student',$id);flash(t('Schüler gespeichert.','Student saved.'));return ['student',['id'=>$id]];
    case 'student_delete':
        require_staff();$s=student((int)post('id'));
        if(post('confirmation')!==$s['first_name'].' '.$s['last_name']) throw new UserError(t('Bitte den vollständigen Namen eingeben.','Please enter the full name.'));
        if(scalar('SELECT COUNT(*) FROM charges WHERE student_id=?',[$s['id']])) throw new UserError(t('Es sind Beiträge vorhanden. Mitgliedschaft stattdessen beenden; Zahlungsdaten bleiben erhalten.','Charges exist. End the membership instead to retain payment records.'));
        tracked('students',(int)$s['id'],$s['first_name'].' '.$s['last_name'],fn()=>run('DELETE FROM students WHERE id=?',[$s['id']]),'delete');
        audit('student.deleted','student',(int)$s['id']);
        flash(t('Schüler gelöscht. Das lässt sich unter „Änderungen“ rückgängig machen.','Student deleted. This can be undone under “Changes”.'));return ['students',[]];
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
