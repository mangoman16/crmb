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
        require_staff(); $id=(int)post('id');
        if($id && !one('SELECT id FROM classes WHERE id=?',[$id])) throw new UserError(t('Kurs nicht gefunden.','Course not found.'));
        $capacity=(int)post('capacity','0');
        if($capacity<0 || $capacity>500) throw new UserError(t('Plätze: 0 bis 500 (0 = unbegrenzt).','Places: 0 to 500 (0 = unlimited).'));
        $days=class_days_from_post();
        $args=[required_text('name',120),text_limit('description',500),text_limit('location',160),
               reference_or_null('accounts','trainer_id',"role IN ('admin','trainer','manager')"),
               reference_or_null('payment_profiles','payment_profile_id'),
               $capacity,(int)post('sort_order','0'),post('archived')?1:0];
        $id=transactional(function() use ($id,$args,$days): int {
            if($id) run('UPDATE classes SET name=?,description=?,location=?,trainer_id=?,payment_profile_id=?,capacity=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
            else { run('INSERT INTO classes (name,description,location,trainer_id,payment_profile_id,capacity,sort_order,archived,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId(); }
            // Replaced rather than reconciled: the form shows the whole pattern,
            // so what it posts is the whole pattern. Nothing points at a
            // class_days row, so there is no identity worth preserving.
            run('DELETE FROM class_days WHERE class_id=?',[$id]);
            foreach($days as $order=>$day)
                run('INSERT INTO class_days (class_id,weekday,starts_at,ends_at,location,sort_order) VALUES (?,?,?,?,?,?)',
                    [$id,$day['weekday'],$day['starts_at'],$day['ends_at'],$day['location'],$order*10]);
            return $id;
        });
        audit('class.saved','class',$id); flash(t('Kurs gespeichert.','Course saved.'));
        return ['classes',['id'=>$id]];

    case 'class_session_save':
        require_staff(); $c=training_class((int)post('class_id'));
        $on=date_value(post('session_on'),true);
        $status=choose(post('status','planned'),array_keys(session_statuses()));
        $from=time_value(post('starts_at')); $to=time_value(post('ends_at'));
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
                .' WHERE cs.class_id=? AND cs.left_on IS NULL AND s.account_id IS NOT NULL',[(int)$c['id']]) as $who)
                notify((int)$who['account_id'],'schedule',$c['name'].' – '.fmt_date($on),
                    session_statuses()[$entry['status']??'planned']??'','classes',['id'=>(int)$c['id'],'tab'=>'dates']);
            $sent=notify_class_change($c,$on,$entry,text_limit('note',500));
            flash(plural($sent,'Familie informiert','Familien informiert','family notified','families notified').'.');
        }
        return ['classes',['id'=>$c['id'],'tab'=>'dates']];

    case 'class_delete':
        require_staff(); $c=training_class((int)post('id'));
        if(post('confirmation')!==$c['name']) throw new UserError(t('Bitte den Kursnamen zur Bestätigung eingeben.','Please enter the class name to confirm.'));
        // Charges reference the class with ON DELETE SET NULL, so payment history
        // survives; only the grouping goes away.
        tracked('classes',(int)$c['id'],(string)$c['name'],fn()=>run('DELETE FROM classes WHERE id=?',[$c['id']]),'delete');
        audit('class.deleted','class',(int)$c['id']); flash(t('Kurs gelöscht. Beiträge und Zahlungen bleiben erhalten. Rückgängig unter „Änderungen“.','Class deleted. Charges and payments are kept. Undo under “Changes”.'));
        return ['classes',[]];

    case 'class_member_add':
        require_staff(); $c=training_class((int)post('class_id')); $s=student((int)post('student_id'));
        if((int)$c['capacity']>0) {
            $current=(int)scalar('SELECT COUNT(*) FROM class_students WHERE class_id=? AND left_on IS NULL',[$c['id']]);
            if($current>=(int)$c['capacity']) throw new UserError(t('Dieser Kurs ist voll. Plätze in den Kurseinstellungen erhöhen.','This class is full. Raise the number of places in the class settings.'));
        }
        $tariffId=reference_or_null('tariffs','tariff_id','class_id='.(int)$c['id']);
        run('INSERT INTO class_students (class_id,student_id,joined_on,tariff_id) VALUES (?,?,?,?)'
            .' ON DUPLICATE KEY UPDATE joined_on=VALUES(joined_on),left_on=NULL,tariff_id=VALUES(tariff_id)',
            [$c['id'],$s['id'],date_value(post('joined_on'))??today(),$tariffId]);
        audit('class.member_added','class',(int)$c['id']);
        return ['classes',['id'=>$c['id']]];

    case 'enrolment_save':
        require_staff(); $c=training_class((int)post('class_id')); $s=student((int)post('student_id'));
        if(!enrolment((int)$c['id'],(int)$s['id'])) throw new UserError(t('Dieses Kind ist nicht in diesem Kurs.','This child is not in this course.'));
        $dueDay=(int)post('due_day','0');
        if($dueDay<0 || $dueDay>28) throw new UserError(t('Zahltag: 1 bis 28, oder 0 für „wie im Tarif“.','Payment day: 1 to 28, or 0 for “as the tariff says”.'));
        run('UPDATE class_students SET tariff_id=?,price_cents=?,price_note=?,due_day=?,joined_on=?,left_on=? WHERE class_id=? AND student_id=?',
            [reference_or_null('tariffs','tariff_id','class_id='.(int)$c['id']),
             post('price')!==''?cents(post('price')):null, text_limit('price_note'), $dueDay,
             date_value(post('joined_on')), date_value(post('left_on')), $c['id'], $s['id']]);
        audit('enrolment.saved','student',(int)$s['id']);
        flash(t('Kursteilnahme gespeichert.','Enrolment saved.'));
        return ['student',['id'=>$s['id'],'tab'=>'classes']];

    // ---- asking to join, leave or change tariff -------------------------

    case 'enrolment_request':
        $u=require_user(); $s=student((int)post('student_id'));
        $classId=(int)post('class_id');
        if(!one('SELECT id FROM classes WHERE id=? AND archived=0',[$classId])) throw new UserError(t('Diesen Kurs gibt es nicht.','No such course.'));
        $kind=choose(post('kind'),array_keys(request_kinds()));
        $tariffId=post('tariff_id')!==''?(int)post('tariff_id'):null;
        if($kind==='join') {
            $class=one('SELECT c.*, (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id=c.id AND cs.left_on IS NULL) AS member_count FROM classes c WHERE c.id=?',[$classId]);
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
        require_staff(); $id=(int)post('id');
        if($id && !one('SELECT id FROM payment_profiles WHERE id=?',[$id])) throw new UserError(t('Zahlungsempfänger nicht gefunden.','Payment profile not found.'));
        $iban=strtoupper(preg_replace('/\s+/','',post('iban')) ?? '');
        if($iban!=='' && !valid_iban($iban)) throw new UserError(t('Diese IBAN ist nicht gültig. Bitte Ziffern und Prüfsumme kontrollieren.','This IBAN is not valid. Please check the digits and the checksum.'));
        $bic=strtoupper(preg_replace('/\s+/','',post('bic')) ?? '');
        if($bic!=='' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/D',$bic)) throw new UserError(t('Diese BIC ist nicht gültig.','This BIC is not valid.'));
        $currency=strtoupper(post('currency','EUR'));
        if(!preg_match('/^[A-Z]{3}$/D',$currency)) throw new UserError(t('Währung als dreistelliger Code, z. B. EUR.','Currency as a three-letter code, e.g. EUR.'));
        $args=[required_text('name',120),text_limit('recipient',140),$iban,$bic,$currency,text_limit('qr_template',2000),text_limit('note',255),post('archived')?1:0];
        if($id) run('UPDATE payment_profiles SET name=?,recipient=?,iban=?,bic=?,currency=?,qr_template=?,note=?,archived=? WHERE id=?',[...$args,$id]);
        else { run('INSERT INTO payment_profiles (name,recipient,iban,bic,currency,qr_template,note,archived,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId(); }
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
        $args=[required_text('name',80),text_limit('description',300),(int)post('sort_order','0'),$archived];
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
        $min=(int)post('min_age','0');
        // Empty means "and upwards", which is what the oldest band always is.
        $max=post('max_age')===''?null:(int)post('max_age');
        if($min<0 || $min>120 || ($max!==null && ($max<0 || $max>120))) throw new UserError(t('Bitte ein Alter zwischen 0 und 120 angeben.','Please give an age between 0 and 120.'));
        if($max!==null && $max<$min) throw new UserError(t('Das Höchstalter liegt unter dem Mindestalter.','The upper age is below the lower one.'));
        $args=[required_text('name',80),$min,$max,(int)post('sort_order','0'),post('archived')?1:0];
        if($id) run('UPDATE age_groups SET name=?,min_age=?,max_age=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
        else { run('INSERT INTO age_groups (name,min_age,max_age,sort_order,archived,created_at) VALUES (?,?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId(); }
        audit('age_group.saved','age_group',$id); flash(t('Altersgruppe gespeichert.','Age group saved.'));
        return ['manage',['tab'=>'ages','edit'=>$id]];

    case 'payment_remind':
        $u=require_staff(); throttle('payment-remind',(string)$u['id'],6,3600);
        if(!setting('smtp',[])) throw new UserError(t('Bitte zuerst SMTP einrichten.','Set up SMTP first.'));
        $only=(int)post('student_id');
        $sent=0; $skipped=0;
        // Group by account: one parent with three children gets three lines of
        // detail, not three separate emails.
        foreach(rows('SELECT c.*, s.first_name, s.last_name, s.account_id,'
            .' '.charge_paid_sql().' AS paid'
            .' FROM charges c JOIN students s ON s.id=c.student_id'
            .' WHERE c.cancelled=0 AND '.charge_overdue_sql().'<?'.($only?' AND s.id=?':'')
            .' ORDER BY s.account_id, c.due_on', $only?[today(),$only]:[today()]) as $c) {
            $due=(int)$c['amount_cents']-(int)$c['paid'];
            if($due<=0 || !$c['account_id']) { $skipped++; continue; }
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
        if($on>today()) throw new UserError(t('Das Datum liegt in der Zukunft.','That date is in the future.'));
        $marks=$_POST['present']??[]; if(!is_array($marks)) throw new UserError(t('Ungültige Eingabe.','Invalid input.'));
        $allowed=array_keys(attendance_statuses());
        $saved=0; $cleared=0;
        foreach(class_members((int)$c['id']) as $m) {
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
        audit('attendance.saved','class',(int)$c['id']);
        flash(plural($saved,'Eintrag gespeichert.','Einträge gespeichert.','entry saved.','entries saved.')
              .($cleared?' '.plural($cleared,'entfernt.','entfernt.','removed.','removed.'):''));
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
        $group=choose(post('group'),['portal','students','payments','organisation','system']);
        // Who may change what, rather than one rule for the whole registry:
        // membership statuses and payment methods are the trainer's words for
        // her own work; the portal's name and the background jobs are not.
        if(in_array($group,['students','payments'],true)) require_staff(); else require_admin();
        foreach(settings_in_group($group) as $key=>$spec) {
            $raw = $spec['kind']==='bool' ? (post('set_'.$key)!=='') : ($_POST['set_'.$key] ?? '');
            if($spec['kind']==='map') { set_setting($key,map_from_post($key,$spec)); continue; }
            if(is_array($raw)) throw new UserError(t('Ungültige Eingabe.','Invalid input.'));
            set_setting($key,setting_validate($key,$spec,$raw));
        }
        audit('settings.saved','settings'); flash(t('Vorgaben gespeichert.','Defaults saved.'));
        return [choose(post('to_page','settings'),['settings','manage']),['tab'=>post('to_tab')?:$group]];

    // ---- invoices --------------------------------------------------------

    case 'invoice_create':
        require_staff(); $s=student((int)post('student_id'));
        $ids=$_POST['charge_ids']??[];
        if(!is_array($ids)) throw new UserError(t('Ungültige Auswahl.','Invalid selection.'));
        $id=create_invoice((int)$s['id'],array_map('intval',$ids),post('issued_on'),post('terms')!==''?(int)post('terms'):-1);
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
            flash(notify_invoice($inv)?t('Rechnung liegt im Postausgang.','The invoice is in the outbox.')
                                      :t('Für dieses Kind ist kein Konto hinterlegt, an das die Rechnung gehen könnte.','This child has no account for the invoice to go to.'));
        }
        return ['student',['id'=>$inv['student_id'],'tab'=>'invoices']];

    case 'proof_upload':
        $u=require_user(); $s=student((int)post('student_id'));
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
        if(!$p) throw new UserError(t('Diesen Beleg gibt es nicht.','No such proof.'));
        run('DELETE FROM payment_proofs WHERE id=?',[$p['id']]);
        delete_upload('proof',(string)$p['stored_name']);
        audit('proof.deleted','student',(int)$p['student_id']);
        return ['student',['id'=>$p['student_id'],'tab'=>'payments']];

    // ---- the shell: notifications, pictures, colours, impersonation ------

    case 'notifications_read':
        $u=require_user();
        if(post('id')!=='') run('UPDATE notifications SET read_at=? WHERE id=? AND account_id=? AND read_at IS NULL',[now(),(int)post('id'),$u['id']]);
        else run('UPDATE notifications SET read_at=? WHERE account_id=? AND read_at IS NULL',[now(),$u['id']]);
        return [post('return_page','dashboard'),[]];

    case 'avatar_save':
        $u=require_user();
        $kind=choose(post('kind','account'),['account','student']);
        $table=$kind==='student'?'students':'accounts';
        // A family may change their own picture and their own children's; staff
        // may change anybody's, which is how a wrong photo gets fixed.
        if($kind==='student') { $s=student((int)post('id')); $id=(int)$s['id']; }
        else { $id=(int)post('id'); if($id!==(int)$u['id'] && !is_staff($u)) throw new UserError(t('Kein Zugriff.','Access denied.')); }
        $old=(string)(scalar('SELECT avatar_name FROM '.$table.' WHERE id=?',[$id])?:'');
        if(post('remove')) {
            run('UPDATE '.$table.' SET avatar_name=? WHERE id=?',['',$id]);
            if($old!=='') delete_upload('avatar',$old);
            flash(t('Bild entfernt.','Picture removed.'));
        } else {
            $stored=store_upload('avatar','avatar');
            run('UPDATE '.$table.' SET avatar_name=? WHERE id=?',[$stored['stored_name'],$id]);
            if($old!=='') delete_upload('avatar',$old);
            flash(t('Bild gespeichert.','Picture saved.'));
        }
        audit('avatar.saved',$kind,$id);
        return $kind==='student'?['student',['id'=>$id]]:['profile',[]];

    case 'impersonate':
        // Stopping is checked against who is really signed in, not against the
        // session's rights: while a trainer is looking through a family's eyes
        // the session has no staff rights at all, so requiring them here left
        // the only way back out refusing to work.
        if(post('mode')==='stop') {
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
            [(int)$u['id'],$page,$message,json_encode(feedback_context($page),JSON_UNESCAPED_UNICODE),$screenshot,now()]);
        $id=(int)db()->lastInsertId();
        foreach(rows("SELECT id FROM accounts WHERE role='admin' AND state='active'") as $admin)
            notify((int)$admin['id'],'problem',t('Jemand meldet ein Problem','Somebody reported a problem'),
                mb_substr($message,0,200),'settings',['tab'=>'feedback']);
        audit('feedback.sent','feedback',$id);
        flash(t('Danke! Die Meldung ist angekommen.','Thank you. Your report has arrived.'));
        return [post('return_page','dashboard'),[]];

    case 'feedback_state':
        require_admin();
        run('UPDATE feedback SET state=? WHERE id=?',[choose(post('state'),['new','seen','done']),(int)post('id')]);
        return ['settings',['tab'=>'feedback']];

    case 'demo_data':
        require_admin(); $mode=choose(post('mode'),['fill','clear']);
        if($mode==='clear') {
            $removed=demo_clear(); unset($_SESSION['demo_password']);
            flash(t('Beispieldaten entfernt: ','Example data removed: ').plural($removed['students'],'Schüler','Schüler','student','students').'.');
            return ['settings',['tab'=>'system']];
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
        return ['settings',['tab'=>'system']];

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

