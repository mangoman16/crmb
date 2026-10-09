<?php
declare(strict_types=1);

/**
 * Actions for the things an administrator configures and a trainer uses:
 * classes, payment profiles, levels, age groups,
 * the defaults registry and maintenance mode.
 *
 * Who may do what:
 *   admin   - everything here
 *   trainer - class membership and assessments (day-to-day work), not the
 *             definitions those depend on
 */
function dispatch_config(string $action): array {
    switch ($action) {

    // ---- classes -------------------------------------------------------

    case 'class_save':
        $u=require_staff(); $id=(int)post('id');
        $was=$id?one('SELECT id,payment_profile_id FROM classes WHERE id=?',[$id]):null;
        if($id && !$was) throw new NotFound(t('Kurs nicht gefunden.','Course not found.'));
        // An empty box is no limit, as a 0 is.
        $capacity=whole_number_value(post('capacity')?:'0',0,500,t('Plätze: 0 bis 500 (0 = unbegrenzt).','Places: 0 to 500 (0 = unlimited).'));
        $days=class_days_from_post();
        $name=required_text('name',120); $description=text_limit('description',500); $location=text_limit('location',160);
        $trainerId=reference_or_null('accounts','trainer_id',"role IN ('admin','trainer','manager')");
        $profileId=reference_or_null('payment_profiles','payment_profile_id');
        $args=[$name,$description,$location,$trainerId,$profileId,$capacity,list_position_value(post('sort_order')),post('archived')?1:0];
        $id=transactional(function() use ($id,$args,$days): int {
            if($id) run('UPDATE classes SET name=?,description=?,location=?,trainer_id=?,payment_profile_id=?,capacity=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
            else {
                run('INSERT INTO classes (name,description,location,trainer_id,payment_profile_id,capacity,sort_order,archived,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId();
                // Every course has its group chat from the start (ADR 0022).
                course_group_thread($id);
            }
            // Replaced rather than reconciled: the form shows the whole pattern,
            // so what it posts is the whole pattern. Nothing points at a
            // class_days row, so there is no identity worth preserving.
            run('DELETE FROM class_days WHERE class_id=?',[$id]);
            foreach($days as $order=>$day)
                run('INSERT INTO class_days (class_id,weekday,starts_at,ends_at,location,sort_order) VALUES (?,?,?,?,?,?)',
                    [$id,$day['weekday'],$day['starts_at'],$day['ends_at'],$day['location'],$order*10]);
            return $id;
        });
        // A course pointed at another payment profile sends its families'
        // money to another account, so every administrator hears of it, like a
        // changed IBAN (ADR 0025, amended 2026-10-08). Compared as charges find
        // their account (charge_payment_profile()): no profile of its own is the
        // default one, and a new course would have had that.
        $house=(int)setting('default_payment_profile');
        $from=(int)($was['payment_profile_id']??0)?:$house; $to=(int)($profileId??0)?:$house;
        if($from!==$to)
            notify_admins_of_bank_change($u,t('Kurs zahlt auf ein anderes Konto: ','Course pays into another account: ').$name,
                strtr(t('Die Beiträge gehen jetzt auf {to} statt auf {from}.','Charges are now paid into {to} instead of {from}.'),
                      ['{to}'=>payment_profile_name($to),'{from}'=>payment_profile_name($from)]),
                'classes',['id'=>$id,'edit'=>1]);
        audit('class.saved','class',$id); flash(t('Kurs gespeichert.','Course saved.'));
        return ['classes',['id'=>$id]];

    case 'class_session_save':
        require_staff(); $c=training_class((int)post('class_id'));
        $on=date_value(post('session_on'),true);
        $status=choose(post('status','planned'),array_keys(session_statuses()));
        $from=posted_time('starts_at'); $to=posted_time('ends_at');
        if($from && $to && $from>=$to) throw new UserError(t('Das Ende muss nach dem Beginn liegen.','The end time must be after the start time.'));
        // "Findet statt" with nothing else changed is the weekly pattern, so the
        // row is removed rather than stored: a table of rows that say "as usual"
        // is a table that grows for no reason.
        if($status==='planned' && !$from && !$to && post('location')==='' && post('note')==='') {
            run('DELETE FROM class_sessions WHERE class_id=? AND session_on=?',[$c['id'],$on]);
            flash(t('Termin folgt wieder dem normalen Plan.','That date follows the usual pattern again.'));
        } else {
            run('INSERT INTO class_sessions (class_id,session_on,starts_at,ends_at,location,status,note,created_by,created_at)'
                .' VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE starts_at=VALUES(starts_at),ends_at=VALUES(ends_at),'
                .'location=VALUES(location),status=VALUES(status),note=VALUES(note),created_by=VALUES(created_by)',
                [$c['id'],$on,$from,$to,text_limit('location',160),$status,text_limit('note',500),current_user()['id']??null,now()]);
            flash(t('Termin gespeichert.','Date saved.'));
        }
        audit('class.session_saved','class',(int)$c['id']);
        // Telling the families is a separate, deliberate step, because a change
        // made to correct a typo should not send fifteen emails.
        if(post('notify')) {
            $entry=class_session((int)$c['id'],$on);
            foreach(rows('SELECT DISTINCT s.account_id FROM class_students cs JOIN students s ON s.id=cs.student_id'
                .' WHERE cs.class_id=? AND '.current_enrolment_sql(),[(int)$c['id']]) as $who)
                // To the overview, where a family's „Termine" are: the course's
                // own page is staff's, and a family is refused it.
                notify((int)$who['account_id'],'schedule',$c['name'].' – '.fmt_date($on),
                    session_statuses()[$entry['status']??'planned']??'','dashboard');
            $sent=notify_class_change($c,$on,$entry,text_limit('note',500));
            flash(plural($sent,'Familie informiert','Familien informiert','family notified','families notified').'.');
        }
        return ['classes',['id'=>$c['id'],'tab'=>'dates']];

    case 'class_delete':
        require_staff(); $c=training_class((int)post('id'));
        if(post('confirmation')!==$c['name']) throw new UserError(t('Bitte den Kursnamen zur Bestätigung eingeben.','Please enter the class name to confirm.'));
        // The group chat goes with its course, so one that holds messages is
        // kept by archiving the course instead (ADR 0022). An empty one goes.
        if(scalar('SELECT 1 FROM messages m JOIN threads t ON t.id=m.thread_id WHERE t.class_id=? LIMIT 1',[$c['id']]))
            throw new UserError(t('Im Gruppenchat dieses Kurses gibt es Nachrichten. Archiviere den Kurs stattdessen – dann bleibt der Chat lesbar.',
                                  'This course’s group chat has messages. Archive the course instead – the chat then stays readable.'));
        // Charges reference the class with ON DELETE SET NULL, so payment history
        // survives; only the grouping goes away.
        tracked('classes',(int)$c['id'],(string)$c['name'],fn()=>run('DELETE FROM classes WHERE id=?',[$c['id']]),'delete');
        // The change log keeps the deleted row to read, not to restore (app/history.php).
        audit('class.deleted','class',(int)$c['id']); flash(t('Kurs gelöscht. Beiträge und Zahlungen bleiben erhalten. Unter „Änderungen“ steht, was gelöscht wurde.','Course deleted. Charges and payments are kept. “Changes” shows what was deleted.'));
        return ['classes',[]];

    case 'class_member_add':
        require_staff(); $c=training_class((int)post('class_id')); $s=student((int)post('student_id'));
        if(($held=course_held((int)$c['id'])) && course_is_full($held))
            throw new UserError(t('Dieser Kurs ist voll. Plätze in den Kurseinstellungen erhöhen.','This class is full. Raise the number of places in the class settings.'));
        $tariffId=reference_or_null('tariffs','tariff_id','class_id='.(int)$c['id']);
        if(enrol_student((int)$c['id'],(int)$s['id'],$tariffId,date_value(post('joined_on'))??today()))
            flash(strtr(t('{name} ist wieder im Kurs. Ein früher vereinbarter Preis oder Rabatt gilt nicht mehr – beim Kind unter „Tarif, Zahlungsweise und Rabatt“ neu eintragen, wenn er weiter gelten soll.',
                          '{name} is back in the course. A price or discount agreed before no longer applies – enter it again on the child under “Tariff, how it is paid, and any discount” if it should go on.'),
                        ['{name}'=>$s['first_name'].' '.$s['last_name']]));
        audit('class.member_added','class',(int)$c['id']);
        return ['classes',['id'=>$c['id']]];

    case 'enrolment_save':
        require_staff(); $c=training_class((int)post('class_id')); $s=student((int)post('student_id'));
        $current=enrolment((int)$c['id'],(int)$s['id']);
        if(!$current) throw new UserError(t('Dieses Kind ist nicht in diesem Kurs.','This child is not in this course.'));
        $dueDay=whole_number_value(post('due_day')?:'0',0,28,t('Zahltag: 1 bis 28, oder 0 für „wie im Tarif“.','Payment day: 1 to 28, or 0 for “as the tariff says”.'));
        $joined=date_value(post('joined_on')); $left=date_value(post('left_on')); date_range($joined,$left);
        $tariffId=posted_enrolment_tariff($current);
        // 0 is "whatever this tariff's usual interval is", which is what most
        // enrolments say. Anything else has to be a price that is written down,
        // or the child would be billed at an amount nobody could point at.
        $interval=whole_number_value(post('interval_months')?:'0',0,12,t('Bitte einen gültigen Abrechnungszeitraum wählen.','Please choose a valid billing interval.'));
        if($interval!==0) {
            billing_valid_interval($interval);
            if(!isset(tariff_rates((int)$tariffId)[$interval]))
                throw new UserError(t('Für diese Zahlungsweise hat der Tarif keinen Preis.','The tariff has no price for that way of paying.'));
        }
        $discount=discount_from_post(post('discount_kind','percent'),post('discount_months','0'),post('discount_value'));
        run('UPDATE class_students SET tariff_id=?,interval_months=?,price_cents=?,price_note=?,due_day=?,joined_on=?,left_on=?,'
            .'discount_months=?,discount_kind=?,discount_value=?,discount_note=? WHERE class_id=? AND student_id=?',
            [$tariffId, $interval,
             post('price')!==''?cents(post('price')):null, text_limit('price_note'), $dueDay,
             $joined, $left,
             $discount['months'], $discount['kind'], $discount['value'],
             $discount['value']>0?text_limit('discount_note',120):'',
             $c['id'], $s['id']]);
        audit('enrolment.saved','student',(int)$s['id']);
        flash(t('Kursteilnahme gespeichert.','Enrolment saved.'));
        return ['student',['id'=>$s['id'],'tab'=>'classes']];

    // ---- asking to join, leave or change tariff -------------------------

    case 'enrolment_request':
        $u=require_user(); $s=student((int)post('student_id'));
        $classId=(int)post('class_id');
        if(!one('SELECT id FROM classes WHERE id=? AND archived=0',[$classId])) throw new NotFound(t('Diesen Kurs gibt es nicht.','No such course.'));
        $kind=choose(post('kind'),array_keys(request_kinds()));
        $tariffId=post('tariff_id')!==''?(int)post('tariff_id'):null;
        if($kind==='join') {
            $class=one('SELECT c.*, (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id=c.id AND '.current_enrolment_sql().') AS member_count FROM classes c WHERE c.id=?',[$classId]);
            if(course_is_full($class)) throw new UserError(t('Dieser Kurs ist voll.','This course is full.'));
        }
        // The trainer is the person being asked, so she does not ask: her own
        // change happens now and the record says she made it.
        if(is_staff($u)) {
            $id=request_enrolment((int)$s['id'],$classId,$kind,$tariffId,text_limit('message',500));
            decide_request($id,true,t('Von der Trainerin selbst eingetragen.','Entered by the trainer.'));
            flash(t('Erledigt.','Done.'));
        } else {
            request_enrolment((int)$s['id'],$classId,$kind,$tariffId,text_limit('message',500));
            notify_staff('request',request_kind_label($kind).': '.$s['first_name'].' '.$s['last_name'],
                (string)scalar('SELECT name FROM classes WHERE id=?',[$classId]),'classes',['tab'=>'requests']);
            flash(t('Deine Anfrage ist unterwegs. Die Trainerin entscheidet darüber.','Your request has been sent. The trainer will decide.'));
        }
        return ['student',['id'=>$s['id'],'tab'=>'classes']];

    case 'enrolment_decide':
        require_staff();
        $approve=post('decision')==='approve';
        $r=decide_request((int)post('id'),$approve,text_limit('note',500));
        notify_enrolment_decision($r,$approve,text_limit('note',500));
        if($account=scalar('SELECT account_id FROM students WHERE id=?',[(int)$r['student_id']]))
            notify((int)$account,'request',
                request_kind_label((string)$r['kind']).': '.($approve?t('angenommen','approved'):t('abgelehnt','declined')),
                (string)scalar('SELECT name FROM classes WHERE id=?',[(int)$r['class_id']]),
                'student',['id'=>(int)$r['student_id'],'tab'=>'classes']);
        flash($approve?t('Angenommen. Das Kind ist eingetragen.','Approved. The child is enrolled.')
                      :t('Abgelehnt. Die Familie wird benachrichtigt.','Declined. The family is told.'));
        return ['classes',['tab'=>'requests']];

    case 'class_member_remove':
        require_staff(); $c=training_class((int)post('class_id')); $s=student((int)post('student_id'));
        // Leaving is recorded rather than erased, so past membership stays visible.
        if(post('mode')==='forget') run('DELETE FROM class_students WHERE class_id=? AND student_id=?',[$c['id'],$s['id']]);
        else run('UPDATE class_students SET left_on=? WHERE class_id=? AND student_id=?',[today(),$c['id'],$s['id']]);
        audit('class.member_removed','class',(int)$c['id']);
        return ['classes',['id'=>$c['id']]];

    // ---- payment profiles ----------------------------------------------

    case 'profile_save':
        $u=require_staff(); $id=(int)post('id');
        $before=$id?one('SELECT * FROM payment_profiles WHERE id=?',[$id]):null;
        if($id && !$before) throw new UserError(t('Zahlungsempfänger nicht gefunden.','Payment profile not found.'));
        $iban=strtoupper(preg_replace('/\s+/','',post('iban')) ?? '');
        if($iban!=='' && !valid_iban($iban)) throw new UserError(t('Diese IBAN ist nicht gültig. Bitte Ziffern und Prüfsumme kontrollieren.','This IBAN is not valid. Please check the digits and the checksum.'));
        $bic=strtoupper(preg_replace('/\s+/','',post('bic')) ?? '');
        if($bic!=='' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/D',$bic)) throw new UserError(t('Diese BIC ist nicht gültig.','This BIC is not valid.'));
        $currency=strtoupper(post('currency','EUR'));
        if(!preg_match('/^[A-Z]{3}$/D',$currency)) throw new UserError(t('Währung als dreistelliger Code, z. B. EUR.','Currency as a three-letter code, e.g. EUR.'));
        // Stored with LF, as qr_payload() reads it: a browser posts a textarea
        // with CRLF, and the same text saved again was a change every time.
        $posted=['name'=>required_text('name',120),'recipient'=>text_limit('recipient',140),'iban'=>$iban,'bic'=>$bic,'currency'=>$currency,
                 'qr_template'=>qr_template_lines(text_limit('qr_template',2000)),'note'=>text_limit('note',255),'archived'=>post('archived')?1:0];
        // A transfer into this profile's account or no code at all
        // (qr_template_pays_profile()): anything else is refused here, where it
        // can still be corrected.
        if(trim($posted['qr_template'])!=='' && !qr_template_pays_profile($posted['qr_template']))
            throw new UserError(t('Der Inhalt des QR-Codes muss eine SEPA-Überweisung bleiben: „BCD“ in der ersten Zeile, {recipient} in der sechsten und {iban} in der siebten.',
                                  'The QR code contents must stay a SEPA transfer: “BCD” on the first line, {recipient} on the sixth and {iban} on the seventh.'));
        $args=array_values($posted);
        // Every change is kept in „Änderungen", with what it was before and who
        // changed it (ADR 0025): an IBAN decides where the families' money goes,
        // and a trainer may change it, so its old value is the one most worth
        // being able to read back.
        if($id) tracked('payment_profiles',$id,$args[0],fn()=>run('UPDATE payment_profiles SET name=?,recipient=?,iban=?,bic=?,currency=?,qr_template=?,note=?,archived=? WHERE id=?',[...$args,$id]));
        else $id=tracked_insert('payment_profiles',$args[0],function() use ($args): int {
            run('INSERT INTO payment_profiles (name,recipient,iban,bic,currency,qr_template,note,archived,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[...$args,now()]);
            return (int)db()->lastInsertId();
        });
        // Where the money goes and what the banking apps are told: every
        // administrator hears of a change to it in the bell, who and which, and
        // finds what it was before under „Änderungen" (ADR 0025, amended
        // 2026-10-08). A new profile counts: it brings an account of its own. A
        // template saved before with CRLF is the same text as the one posted now.
        if($before) $before['qr_template']=qr_template_lines((string)$before['qr_template']);
        $changed=array_values(array_filter(['iban','recipient','qr_template'],
            fn(string $column): bool => (string)($before[$column]??'')!==(string)$posted[$column]));
        if($changed)
            notify_admins_of_bank_change($u,($before?t('Kontoverbindung geändert: ','Bank details changed: '):t('Neuer Zahlungsempfänger: ','New payment profile: ')).$posted['name'],
                implode(', ',array_map('history_field_label',$changed)).t('. Die Einzelheiten stehen unter „Änderungen“.','. The details are under “Changes”.'),
                'history',['entity'=>'payment_profiles','record'=>$id]);
        audit('profile.saved','payment_profile',$id); flash(t('Zahlungsempfänger gespeichert.','Payment profile saved.'));
        return ['manage',['tab'=>'payments','edit'=>$id]];

    // ---- levels and age groups -----------------------------------------
    //
    // Both are the trainer's own lists, so both are hers to edit: these are the
    // words she uses about the children in front of her, not configuration of
    // the software.

    case 'level_save':
        require_staff(); $id=(int)post('id');
        if($id && !one('SELECT id FROM levels WHERE id=?',[$id])) throw new UserError(t('Diese Gruppe gibt es nicht.','No such level.'));
        $archived=post('archived')?1:0;
        $isDefault=post('is_default')!=='';
        if($archived && $isDefault) throw new UserError(t('Eine archivierte Gruppe kann nicht die Standardgruppe sein.','An archived level cannot be the default one.'));
        // Archiving the last living level would leave a new student with nowhere
        // to start, and the form that offers the choice with nothing to offer.
        if($archived && $id && (int)scalar('SELECT COUNT(*) FROM levels WHERE archived=0 AND id<>?',[$id])===0)
            throw new UserError(t('Es muss mindestens eine Gruppe übrig bleiben.','At least one level has to remain.'));
        $args=[required_text('name',80),text_limit('description',300),list_position_value(post('sort_order')),$archived];
        $id=transactional(function() use ($id,$args,$isDefault): int {
            if($id) run('UPDATE levels SET name=?,description=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
            else { run('INSERT INTO levels (name,description,sort_order,archived,created_at) VALUES (?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId(); }
            // Exactly one default, decided in the same transaction as the change
            // that claims it, so two tabs cannot end up with none or two.
            if($isDefault) { run('UPDATE levels SET is_default=0'); run('UPDATE levels SET is_default=1 WHERE id=?',[$id]); }
            elseif(!scalar('SELECT COUNT(*) FROM levels WHERE is_default=1 AND archived=0'))
                run('UPDATE levels SET is_default=1 WHERE archived=0 ORDER BY sort_order, id LIMIT 1');
            return $id;
        });
        audit('level.saved','level',$id); flash(t('Gruppe gespeichert.','Level saved.'));
        return ['manage',['tab'=>'levels','edit'=>$id]];

    case 'age_group_save':
        require_staff(); $id=(int)post('id');
        if($id && !one('SELECT id FROM age_groups WHERE id=?',[$id])) throw new UserError(t('Diese Altersgruppe gibt es nicht.','No such age group.'));
        $age=fn(string $value): int => whole_number_value($value,0,120,t('Bitte ein Alter zwischen 0 und 120 angeben.','Please give an age between 0 and 120.'));
        $min=$age(post('min_age')?:'0');
        // Empty means "and upwards", which is what the oldest band always is.
        $max=post('max_age')===''?null:$age(post('max_age'));
        if($max!==null && $max<$min) throw new UserError(t('Das Höchstalter liegt unter dem Mindestalter.','The upper age is below the lower one.'));
        $args=[required_text('name',80),$min,$max,list_position_value(post('sort_order')),post('archived')?1:0];
        if($id) run('UPDATE age_groups SET name=?,min_age=?,max_age=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
        else { run('INSERT INTO age_groups (name,min_age,max_age,sort_order,archived,created_at) VALUES (?,?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId(); }
        audit('age_group.saved','age_group',$id); flash(t('Altersgruppe gespeichert.','Age group saved.'));
        return ['manage',['tab'=>'ages','edit'=>$id]];

    case 'payment_remind':
        $u=require_staff(); throttle('payment-remind',(string)$u['id'],6,3600);
        if(!setting('smtp',[])) throw new UserError(t('Bitte zuerst SMTP einrichten.','Set up SMTP first.'));
        $only=(int)post('student_id');
        $sent=0; $skipped=0;
        // One reminder per overdue charge, to the student's own login. A student
        // with no login - including a brother or sister taken off a shared one
        // by the update to one login per member - is skipped and counted, until
        // they are invited with an address of their own.
        foreach(rows('SELECT c.*, s.first_name, s.last_name, s.account_id,'
            .' '.charge_paid_sql().' AS paid'
            .' FROM charges c JOIN students s ON s.id=c.student_id'
            .' WHERE '.charge_is_overdue_sql().($only?' AND s.id=?':'')
            .' ORDER BY s.account_id, c.due_on', $only?[today(),$only]:[today()]) as $c) {
            $due=(int)$c['amount_cents']-(int)$c['paid'];
            if(!$c['account_id']) { $skipped++; continue; }
            $account=one('SELECT * FROM accounts WHERE id=?',[(int)$c['account_id']]);
            if(!$account) { $skipped++; continue; }
            if(notify_payment($account,['id'=>$c['student_id'],'first_name'=>$c['first_name'],'last_name'=>$c['last_name']],$due,(string)$c['due_on'])) $sent++;
            else $skipped++;
        }
        audit('payment.reminded','charge');
        flash($sent
            ? $sent.' '.t('Erinnerungen liegen im Postausgang.','reminders are in the outbox.').($skipped?' '.$skipped.' '.t('übersprungen (kein Konto oder abgemeldet).','skipped (no account, or unsubscribed).'):'')
            : t('Keine Erinnerung nötig oder alle Empfänger haben diese E-Mails abbestellt.','Nothing to remind about, or every recipient has unsubscribed from these emails.'),
            $sent?'success':'error');
        return ['outbox',[]];

    // ---- attendance ----------------------------------------------------

    case 'attendance_save':
        $u=require_staff(); $c=training_class((int)post('class_id'));
        $on=date_value(post('session_on'),true);
        /* The face of a child without a photo is a button of this form (ADR
           0031; the screens' spec, §2): a photo is a page load of its own, so
           the marks made so far are saved first and nothing ticked is lost,
           and the list comes back with the sheet that takes the photo. A day
           still to come is then neither saved nor refused, and with nothing
           saved nothing is said. */
        $photo=post('photo')!==''?max(0,(int)post('photo')):null;
        if($on>today() && $photo===null) throw new UserError(t('Das Datum liegt in der Zukunft.','That date is in the future.'));
        $marks=$_POST['present']??[]; if(!is_array($marks)) throw new UserError(t('Ungültige Eingabe.','Invalid input.'));
        $allowed=array_keys(attendance_statuses());
        $saved=0; $cleared=0;
        foreach($on>today()?[]:class_members((int)$c['id']) as $m) {
            $sid=(int)$m['id'];
            if(!array_key_exists($sid,$marks)) continue;
            $value=is_scalar($marks[$sid])?trim((string)$marks[$sid]):'';
            // An empty value means "not recorded", which is different from being
            // marked absent, so it removes the row rather than storing a status.
            if($value==='') {
                $cleared += run('DELETE FROM attendance WHERE class_id=? AND student_id=? AND session_on=?',[$c['id'],$sid,$on])->rowCount();
                continue;
            }
            if(!in_array($value,$allowed,true)) throw new UserError(t('Ungültiger Eintrag.','Invalid entry.'));
            run('INSERT INTO attendance (class_id,student_id,session_on,status,recorded_by,created_at) VALUES (?,?,?,?,?,?)'
                .' ON DUPLICATE KEY UPDATE status=VALUES(status),recorded_by=VALUES(recorded_by)',
                [$c['id'],$sid,$on,$value,$u['id'],now()]);
            $saved++;
        }
        if($photo===null || $saved || $cleared) {
            audit('attendance.saved','class',(int)$c['id']);
            flash(plural($saved,'Eintrag gespeichert.','Einträge gespeichert.','entry saved.','entries saved.')
                  .($cleared?' '.plural($cleared,'entfernt.','entfernt.','removed.','removed.'):''));
        }
        if($photo!==null) return ['attendance',['id'=>$c['id'],'on'=>$on]+($photo?['photo'=>$photo]:[])];
        return ['classes',['id'=>$c['id'],'tab'=>'attendance','on'=>$on]];

    case 'attendance_clear':
        require_staff(); $c=training_class((int)post('class_id'));
        $on=date_value(post('session_on'),true);
        run('DELETE FROM attendance WHERE class_id=? AND session_on=?',[$c['id'],$on]);
        audit('attendance.cleared','class',(int)$c['id']);
        flash(t('Eintrag für diesen Tag entfernt.','The entry for that day was removed.'));
        return ['classes',['id'=>$c['id'],'tab'=>'attendance']];

    // ---- monthly billing -----------------------------------------------

    case 'billing_generate':
        require_staff();
        $period=billing_valid_period(post('period',billing_current_period()));
        $result=billing_run($period);
        flash($result['created']
            ? plural($result['created'],'Beitrag für','Beiträge für','charge created for','charges created for')
              .' '.billing_month_name($period).' '.substr($period,0,4).t(' angelegt.','.')
            : t('Nichts zu tun: für diesen Monat gibt es bereits alle Beiträge.','Nothing to do: every charge for that month already exists.'),
            $result['created']?'success':'error');
        return ['payments',['period'=>$period]];

    case 'billing_pause':
        require_staff(); $s=student((int)post('id'));
        $paused=post('mode')==='pause';
        run('UPDATE students SET billing_paused=?, billing_note=? WHERE id=?',[$paused?1:0,text_limit('billing_note',255),$s['id']]);
        audit('billing.'.($paused?'paused':'resumed'),'student',(int)$s['id']);
        flash($paused?t('Beiträge für diesen Schüler pausiert. Bereits erzeugte Beiträge bleiben bestehen.','Billing paused for this student. Charges already created are unaffected.')
                     :t('Beiträge laufen wieder.','Billing resumed.'));
        return ['student',['id'=>$s['id'],'tab'=>'payments']];

    // ---- defaults registry and maintenance -----------------------------

    case 'defaults_registry_save':
        $group=choose(post('group'),['portal','branding','students','payments','organisation','privacy','system']);
        // Who may change what, rather than one rule for the whole registry:
        // membership statuses and payment methods are the trainer's words for
        // her own work; the portal's name, its look and the background jobs are not.
        $u=in_array($group,['students','payments'],true)?require_staff():require_admin();
        // Every value is checked before any is written, so a refusal - an
        // unreadable background, a colour that is not one - leaves the whole
        // card as it was, and says so, rather than half of it saved.
        $specs=settings_in_group($group); $values=[]; $before=[];
        foreach($specs as $key=>$spec) {
            $before[$key]=setting($key);
            $raw = $spec['kind']==='bool' ? (post('set_'.$key)!=='') : ($_POST['set_'.$key] ?? '');
            if($spec['kind']==='map') { $values[$key]=map_from_post($key,$spec); continue; }
            if(is_array($raw)) throw new UserError(t('Ungültige Eingabe.','Invalid input.'));
            $values[$key]=setting_validate($key,$spec,$raw);
        }
        foreach($values as $key=>$value) set_setting($key,$value);
        // Every course without a profile of its own pays into the default one:
        // changing it moves their families' money as moving a course does
        // (class_save), so every administrator hears of it (ADR 0025, amended
        // 2026-10-08).
        if(array_key_exists('default_payment_profile',$values) && (int)$before['default_payment_profile']!==(int)$values['default_payment_profile'])
            notify_admins_of_bank_change($u,t('Standard-Zahlungsempfänger geändert: ','Default payment recipient changed: ').payment_profile_name((int)$values['default_payment_profile']),
                strtr(t('Kurse ohne eigenen Zahlungsempfänger zahlen jetzt auf {to} statt auf {from}.','Courses without a payment recipient of their own now pay into {to} instead of {from}.'),
                      ['{to}'=>payment_profile_name((int)$values['default_payment_profile']),'{from}'=>payment_profile_name((int)$before['default_payment_profile'])]),
                'manage',['tab'=>'payments']);
        audit('settings.saved','settings');
        // The colours are not tracked(), so the message is the way back: it
        // names what they were, to be typed in again (ADR 0013).
        $replaced=$group==='branding'?settings_replaced_colours($specs,$before,$values):'';
        // A period set shorter deletes at the next daily cleanup, and is the
        // way back until then (ADR 0032): what it was is said, to be typed in
        // again. The cleanup this request ends with would come before that is
        // read, so the page views' cleanup waits a day from now (tick_prune()).
        $shortened=settings_shortened_periods($specs,$before,$values);
        if($shortened!=='') set_setting('period_last_shortened',now());
        flash(t('Vorgaben gespeichert.','Defaults saved.').($replaced!==''?' '.$replaced:'').($shortened!==''?' '.$shortened:''));
        // The „Aussehen" card is on the Portal tab; it has no tab of its own.
        return [choose(post('to_page','settings'),['settings','manage']),['tab'=>post('to_tab')?:($group==='branding'?'portal':$group)]];

    // ---- invoices --------------------------------------------------------

    case 'invoice_create':
        require_staff(); $s=student((int)post('student_id'));
        $ids=$_POST['charge_ids']??[];
        if(!is_array($ids)) throw new UserError(t('Ungültige Auswahl.','Invalid selection.'));
        $terms=post('terms')!==''?whole_number_value(post('terms'),0,180,t('Zahlungsziel: 0 bis 180 Tage.','Payment term: 0 to 180 days.')):-1;
        $id=create_invoice((int)$s['id'],array_map('intval',$ids),post('issued_on'),$terms);
        $created=invoice($id);
        if($created['account_id'])
            notify((int)$created['account_id'],'payment',t('Neue Rechnung: ','New invoice: ').$created['number'],
                money((int)$created['gross_cents']).t(', zahlbar bis ',', payable by ').fmt_date((string)$created['due_on']),
                'student',['id'=>(int)$s['id'],'tab'=>'invoices']);
        flash(t('Rechnung angelegt.','Invoice created.'));
        return ['student',['id'=>$s['id'],'tab'=>'invoices','invoice'=>$id]];

    case 'invoice_state':
        require_staff(); $inv=invoice((int)post('id'));
        $mode=choose(post('mode'),['paid','cancel','send']);
        if($mode==='paid') {
            $methods=(array)setting('payment_methods');
            $written=invoice_mark_paid((int)$inv['id'],date_value(post('paid_on'))??today(),
                choose(post('method',(string)($methods[0]??'Überweisung')),$methods),text_limit('note'));
            flash($written?t('Als bezahlt eingetragen.','Marked as paid.')
                          :t('Diese Rechnung war schon vollständig bezahlt.','That invoice was already paid in full.'));
        } elseif($mode==='cancel') {
            invoice_cancel((int)$inv['id'],text_limit('note'));
            flash(t('Rechnung storniert. Die Nummer bleibt vergeben, damit die Nummernfolge lückenlos bleibt.','Invoice cancelled. The number stays used, so the sequence has no hole in it.'));
        } else {
            if(!setting('smtp',[])) throw new UserError(t('Bitte zuerst SMTP einrichten.','Set up SMTP first.'));
            if($refusal=invoice_mail_refusal($inv)) throw new UserError($refusal);
            notify_invoice($inv);
            flash(t('Rechnung liegt im Postausgang.','The invoice is in the outbox.'));
        }
        return ['student',['id'=>$inv['student_id'],'tab'=>'invoices']];

    case 'proof_upload':
        // A receipt is a file, kept until staff remove it, so the limit is the
        // one a problem report's screenshot has: a family sending several for
        // several months is well inside it, a thousand is a full disk.
        $u=require_user(); throttle('proof',(string)$u['id'],20,3600);
        $s=student((int)post('student_id'));
        $chargeId=post('charge_id')!==''?(int)post('charge_id'):null;
        if($chargeId && !one('SELECT id FROM charges WHERE id=? AND student_id=?',[$chargeId,$s['id']]))
            throw new UserError(t('Dieser Beitrag gehört nicht zu diesem Kind.','That charge does not belong to this child.'));
        $invoiceId=post('invoice_id')!==''?(int)post('invoice_id'):null;
        if($invoiceId) invoice($invoiceId);
        $stored=store_upload('proof','proof');
        run('INSERT INTO payment_proofs (charge_id,invoice_id,student_id,stored_name,original_name,mime,bytes,note,uploaded_by,created_at)'
            .' VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$chargeId,$invoiceId,$s['id'],$stored['stored_name'],$stored['original_name'],$stored['mime'],$stored['bytes'],
             text_limit('note'),$u['id'],now()]);
        audit('proof.uploaded','student',(int)$s['id']);
        flash(t('Danke! Der Beleg ist angekommen.','Thank you. The proof has arrived.'));
        return ['student',['id'=>$s['id'],'tab'=>'payments']];

    case 'proof_delete':
        require_staff(); $p=one('SELECT * FROM payment_proofs WHERE id=?',[(int)post('id')]);
        if(!$p) throw new NotFound(t('Diesen Beleg gibt es nicht.','No such proof.'));
        run('DELETE FROM payment_proofs WHERE id=?',[$p['id']]);
        delete_upload('proof',(string)$p['stored_name']);
        audit('proof.deleted','student',(int)$p['student_id']);
        return ['student',['id'=>$p['student_id'],'tab'=>'payments']];

    // ---- the shell: notifications, pictures, impersonation ---

    case 'notifications_read':
        $u=require_user();
        if(post('id')!=='') run('UPDATE notifications SET read_at=? WHERE id=? AND account_id=? AND read_at IS NULL',[now(),(int)post('id'),$u['id']]);
        else run('UPDATE notifications SET read_at=? WHERE account_id=? AND read_at IS NULL',[now(),$u['id']]);
        // The pane is on every page, so back to that page with its record and tab.
        return form_return();

    case 'picture_save':
        /* A picture added, replaced or removed (ADR 0031 §7, as amended): a
           child's (student_id) by the child's own login on the child's page,
           and by staff for every child, at training too, from the attendance
           list; or a team member's own (kind=account), on Mein Konto, by that
           team member and nobody else - a student's login never has a picture
           of its own. student() finds the one child a family may change, and
           any for staff. Twenty photos an hour per login, as for a receipt:
           making one is the most a request here asks of the server. Removing
           makes nothing, and is not counted. */
        $u=require_user();
        $team=post('kind')==='account';
        // Asked before a photo is made of anything: the writer asks again, of the locked row.
        if($team) team_picture_holder($u);
        $s=$team?null:student((int)post('student_id'));
        // Sent from the attendance list, back to the same course and day: the
        // face in its row, or - refused - the sheet that takes it, open again
        // under the sentence that says why (the screens' spec, §2).
        $list=!$team && (int)post('class_id')>0?['id'=>(int)post('class_id'),'on'=>date_value(post('on'),true)]:null;
        $stored='';
        if(post('remove')==='') {
            try {
                throttle('picture',(string)$u['id'],20,3600,t('Zu viele Fotos in kurzer Zeit. In einer Stunde geht es wieder.','Too many photos in a short time. It works again in an hour.'));
                $stored=store_upload('picture','picture')['stored_name'];
            } catch(UserError $refused) {
                if($list===null) throw $refused;
                flash($refused->getMessage(),'error');
                return ['attendance',$list+['photo'=>(int)$s['id']]];
            }
        }
        if($team) write_team_picture((int)$u['id'],$stored); else write_child_picture($s,$stored);
        if($stored!=='' && $list!==null) {
            flash(strtr(t('Foto von {name} gespeichert.','Photo of {name} saved.'),['{name}'=>$s['first_name']]));
            return ['attendance',$list];
        }
        flash($stored!==''?t('Foto gespeichert.','Photo saved.'):t('Foto gelöscht.','Photo deleted.'));
        // From „Dein Foto" (to=landing), on to where a first password lands
        // without the step.
        if(post('to')==='landing') return landing_after_first_password($u);
        return $team?['profile',['#'=>'picture']]:['student',['id'=>$s['id'],'#'=>'picture']];

    case 'picture_consent':
        /* Whether the children in the child's courses and their families see
           the picture in the course chat (ADR 0031 §8). Only the child's own
           login says yes. Staff take it back - for a family that asks on the
           phone, or a picture that should not be shown - and never give it:
           nobody says yes in a family's place. Each change is kept three ways,
           each for its reader: the column the rule reads, the consent log with
           the notice's version as the proof, and the change log with who. */
        $u=require_user(); $s=student((int)post('student_id'));
        $on=post('on')==='1';
        if($on && (is_staff($u) || (int)$s['account_id']!==(int)$u['id']))
            throw new UserError(t('Einschalten kann nur die Familie, auf der Seite ihres Kindes.','Only the family can switch this on, on their child’s page.'));
        if($on && !setting('pictures_in_course')) throw new UserError(t('Der Verein zeigt im Kurs-Chat keine Fotos.','The club shows no photos in the course chat.'));
        if($on!==((int)(lock_row('students',(int)$s['id'])['course_sees_picture']??0)===1)) {
            tracked('students',(int)$s['id'],$s['first_name'].' '.$s['last_name'],
                fn()=>run('UPDATE students SET course_sees_picture=? WHERE id=?',[$on?1:0,(int)$s['id']]));
            /* Whose say it was, in the purpose (ADR 0031, as amended): a
               parent's, through the family's login, under consent_age or with
               no birth date (§ 4 Abs. 4 DSG), is course_sees_picture_by_parent;
               from that age on the child's own is course_sees_picture. Staff
               only ever take it back, which is no parent's say: the purpose
               itself, and who in the change log. A parent's yes stays when the
               child turns 14. */
            record_consent((int)$s['account_id'],!is_staff($u) && needs_a_parents_yes($s)?'course_sees_picture_by_parent':'course_sees_picture',$on);
            audit($on?'picture.shown_to_course':'picture.hidden_from_course','student',(int)$s['id']);
        }
        flash($on?t('Im Kurs-Chat sichtbar.','Shown in the course chat.'):t('Im Kurs-Chat ausgeblendet.','Hidden from the course chat.'));
        return ['student',['id'=>$s['id'],'#'=>'picture']];

    case 'impersonate':
        // Stopping is checked against who is really signed in, not against the
        // session's rights: while a trainer is looking through a family's eyes
        // the session has no staff rights at all, so requiring them here left
        // the only way back out refusing to work.
        if(post('mode')==='stop') {
            // impersonator() answers nobody unless somebody is signed in, so a
            // view whose session ended cannot be stopped into staff (F1).
            if(!impersonator()) throw new UserError(t('Du siehst das Portal gerade nicht als jemand anderer.','You are not viewing the portal as somebody else.'));
            stop_impersonation(); flash(t('Du bist wieder du selbst.','You are yourself again.')); return ['dashboard',[]];
        }
        require_staff();
        $target=start_impersonation((int)post('id'));
        flash(t('Du siehst das Portal jetzt als ','You are now seeing the portal as ').$target['name'].t('. Oben kannst du das beenden.','. You can stop that at the top.'));
        return ['dashboard',[]];

    case 'feedback_send':
        // The form is only ever drawn for somebody signed in, so a report from
        // nobody is not a feature - it is an unauthenticated file upload. The
        // limit is per account and generous: a page that really is broken is
        // worth reporting twice, and a thousand times is a full disk.
        $u=require_user();
        throttle('feedback',(string)$u['id'],20,3600);
        $message=required_text('message',4000);
        $page=mb_substr(post('page','dashboard'),0,60);
        $screenshot='';
        // A screenshot is a file the person took themselves. The portal cannot
        // take one for them without loading a rendering library into every page,
        // and that is a lot of code on every request for a rare moment.
        if(isset($_FILES['screenshot']) && (int)($_FILES['screenshot']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)
            $screenshot=store_upload('screenshot','avatar')['stored_name'];
        run('INSERT INTO feedback (account_id,page,message,context_json,screenshot_name,created_at) VALUES (?,?,?,?,?,?)',
            [(int)$u['id'],$page,$message,feedback_context_json(feedback_context($page)),$screenshot,now()]);
        $id=(int)db()->lastInsertId();
        notify_admins('problem',t('Jemand meldet ein Problem','Somebody reported a problem'),
            mb_substr($message,0,200),'settings',['tab'=>'feedback']);
        audit('feedback.sent','feedback',$id);
        flash(t('Danke! Die Meldung ist angekommen.','Thank you. Your report has arrived.'));
        // Back to the page the report was sent from, record and tab included:
        // the page name alone opened ?page=student with no id, which is „Kein
        // Zugriff“. Read by form_return(), as the way back after a refusal is,
        // so it is held to the pages there are: a forged page name leads to the
        // overview, not to a page the router does not know.
        return form_return('dashboard');

    case 'feedback_state':
        require_admin(); $state=choose(post('state'),['new','seen','done']);
        transactional(function() use ($state): void {
            // Held while it is rewritten, so two taps on "Erledigt" from two
            // tabs cannot each read the old context and write it back.
            $report=lock_row('feedback',(int)post('id'));
            if(!$report) throw new NotFound(t('Diese Meldung gibt es nicht mehr.','That report no longer exists.'));
            $context=(string)$report['context_json'];
            // Done means dealt with, and a report that is dealt with no longer
            // needs copies of what somebody typed. Re-opening it does not bring
            // them back; the way there is kept. Done also starts the clock after
            // which prune_done_feedback() deletes it.
            if($state==='done' && is_array($decoded=json_decode($context,true)))
                $context=feedback_context_json(feedback_mark_done($decoded));
            run('UPDATE feedback SET state=?,context_json=? WHERE id=?',[$state,$context,$report['id']]);
        });
        return ['settings',['tab'=>'feedback']];

    case 'demo_data':
        require_admin(); $mode=choose(post('mode'),['fill','clear']);
        // Back to the checklist when that is where she came from: filling or
        // clearing example data is something the checklist offers on the way
        // to a real portal, and the System tab would lose her place (ADR 0011).
        $back=post('from')==='start' || setup_return_active() ? ['start',[]] : ['settings',['tab'=>'system']];
        if($mode==='clear') {
            $removed=demo_clear(); unset($_SESSION['demo_password']);
            flash(t('Beispieldaten entfernt: ','Example data removed: ').plural($removed['students'],'Schüler','Schüler','student','students').'.');
            return $back;
        }
        $result=demo_fill(post('confirm')!=='');
        // Held in the session rather than the database: it is only ever needed by
        // the person who just pressed the button, and a password sitting in a
        // settings row outlives every reason anyone had for it.
        $_SESSION['demo_password']=$result['password'];
        flash(t('Beispieldaten angelegt: ','Example data created: ')
            .plural($result['students'],'Schüler','Schüler','student','students').', '
            .plural($result['courses'],'Kurs','Kurse','course','courses').'. '
            .t('Das Passwort für die Beispielkonten steht unten.','The password for the example accounts is below.'));
        return $back;

    case 'maintenance_toggle':
        require_admin(); $on=post('mode')==='on';
        if($on) {
            if(file_put_contents(maintenance_file(),now().PHP_EOL)===false)
                throw new UserError(t('Die Wartungsdatei kann nicht geschrieben werden. Bitte Schreibrechte für den Ordner storage/ prüfen.','Cannot write the maintenance file. Check that storage/ is writable.'));
        } elseif(is_file(maintenance_file()) && !unlink(maintenance_file())) {
            throw new UserError(t('Die Wartungsdatei kann nicht entfernt werden.','Cannot remove the maintenance file.'));
        }
        audit('maintenance.'.($on?'on':'off'),'settings');
        flash($on?t('Wartungsmodus aktiv. Nur Administratoren können das Portal noch öffnen.','Maintenance mode is on. Only administrators can still open the portal.')
                 :t('Wartungsmodus beendet.','Maintenance mode is off.'));
        return ['settings',['tab'=>'system']];
    }
    return dispatch_settings_or_messages($action);
}

/**
 * $login, if it may have a picture of its own - a team member's - or the
 * refusal: a student's login never has one, the child's is on the child (ADR
 * 0031, as amended).
 */
function team_picture_holder(?array $login): array {
    if(!$login || !is_staff($login)) throw new UserError(t('Nur das Team hat ein eigenes Foto; das eines Kindes steht beim Kind.','Only the team has a photo of their own; a child’s is on the child.'));
    return $login;
}

/**
 * The one writer of a team member's own picture (ADR 0031; the owner,
 * 2026-10-08): $stored is the picture store_upload() has just made, or '' to
 * remove theirs. Only a team member's login has one - a student's never does -
 * so the login is read under a lock and must be the team's. Tracked, so
 * „Änderungen" says who changed it, as „Profilbild"; the file it replaces is
 * deleted at once, and last, as a child's is.
 */
function write_team_picture(int $accountId, string $stored): void {
    $locked=team_picture_holder(lock_row('accounts',$accountId));
    $old=(string)$locked['picture_name'];
    if($stored===$old) return;
    tracked('accounts',$accountId,(string)$locked['name'],fn()=>run('UPDATE accounts SET picture_name=? WHERE id=?',[$stored,$accountId]));
    audit($stored!==''?'picture.saved':'picture.removed','account',$accountId);
    // Last, because a file cannot be rolled back.
    if($old!=='') delete_upload('picture',$old);
}

/**
 * The one writer of a child's picture (ADR 0031 §7): $stored is the picture
 * store_upload() has just made, or '' to remove the one there is.
 *
 * Tracked, so „Änderungen" says who changed it - as „Profilbild", never the
 * file's name. A picture staff put on a child ends the family's yes in the same
 * statement, the consent log keeps that as the family's latest answer, and the
 * family's bell says so: the course sees a picture only once the family has seen
 * that picture and said yes ("The family always sees it").
 * A placeholder's bell takes nothing (notify()). The family's own picture leaves
 * its answer as it was, and so does a removal.
 *
 * The file it replaces is deleted at once, and last, as the icon's and the
 * logo's are: a commit that fails after that leaves the row naming a file that
 * is gone, which draws the initials (has_picture()), and the nightly prune takes
 * whatever no row names. Read under a lock, so two at once each delete the file
 * they replaced.
 */
function write_child_picture(array $student, string $stored): void {
    $actor=require_user();
    $id=(int)$student['id'];
    $locked=lock_row('students',$id);
    if(!$locked) throw new NotFound(t('Schüler nicht gefunden.','Student not found.'));
    $old=(string)$locked['picture_name'];
    if($stored===$old) return;
    $byStaff=$stored!=='' && is_staff($actor);
    tracked('students',$id,$locked['first_name'].' '.$locked['last_name'],fn()=>$byStaff
        ? run('UPDATE students SET picture_name=?,course_sees_picture=0 WHERE id=?',[$stored,$id])
        : run('UPDATE students SET picture_name=? WHERE id=?',[$stored,$id]));
    // The answer that holds now, which is what the log keeps (ADR 0031 §8, ADR
    // 0032): staff are no parent, so it is the purpose itself, as when staff
    // switch it off on the child's page.
    if($byStaff && (int)$locked['course_sees_picture']===1) record_consent((int)$locked['account_id'],'course_sees_picture',false);
    if($byStaff)
        notify((int)$locked['account_id'],'picture',
            strtr(t('Neues Foto von {name}','New photo of {name}'),['{name}'=>$locked['first_name']]),
            strtr(t('{name} hat es hinzugefügt. Du kannst es jederzeit ändern oder entfernen.','{name} added it. You can change or remove it at any time.'),['{name}'=>$actor['name']])
                // The yes it ended, and whose it is to give again, by the age.
                .((int)$locked['course_sees_picture']!==1?'':(needs_a_parents_yes($locked)
                    ?t(' Im Kurs-Chat erscheint es erst, wenn ein Elternteil wieder zustimmt.',' It shows in the course chat only once a parent agrees again.')
                    :t(' Im Kurs-Chat zeigst du es erst, wenn du wieder zustimmst.',' It shows in the course chat only once you agree again.'))),
            'student',['id'=>$id,'#'=>'picture']);
    audit($stored!==''?'picture.saved':'picture.removed','student',$id);
    // Last, because a file cannot be rolled back.
    if($old!=='') delete_upload('picture',$old);
}
