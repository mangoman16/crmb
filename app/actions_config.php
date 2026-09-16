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
        if($id && !one('SELECT id FROM classes WHERE id=?',[$id])) throw new UserError(t('Kurs nicht gefunden.','Class not found.'));
        $weekday=post('weekday')===''?null:(int)choose(post('weekday'),array_map('strval',array_keys(weekdays())));
        $from=time_value(post('starts_at')); $to=time_value(post('ends_at'));
        if($from && $to && $from>=$to) throw new UserError(t('Das Ende muss nach dem Beginn liegen.','The end time must be after the start time.'));
        $capacity=(int)post('capacity','0');
        if($capacity<0 || $capacity>500) throw new UserError(t('Plätze: 0 bis 500 (0 = unbegrenzt).','Places: 0 to 500 (0 = unlimited).'));
        $args=[required_text('name',120),text_limit('description',500),$weekday,$from,$to,text_limit('location',160),
               reference_or_null('accounts','trainer_id',"role IN ('admin','trainer','manager')"),
               reference_or_null('tariffs','tariff_id'),reference_or_null('payment_profiles','payment_profile_id'),
               $capacity,(int)post('sort_order','0'),post('archived')?1:0];
        if($id) run('UPDATE classes SET name=?,description=?,weekday=?,starts_at=?,ends_at=?,location=?,trainer_id=?,tariff_id=?,payment_profile_id=?,capacity=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
        else { run('INSERT INTO classes (name,description,weekday,starts_at,ends_at,location,trainer_id,tariff_id,payment_profile_id,capacity,sort_order,archived,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId(); }
        audit('class.saved','class',$id); flash(t('Kurs gespeichert.','Class saved.'));
        return ['classes',['id'=>$id]];

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
        run('INSERT INTO class_students (class_id,student_id,joined_on) VALUES (?,?,?) ON DUPLICATE KEY UPDATE joined_on=VALUES(joined_on),left_on=NULL',
            [$c['id'],$s['id'],date_value(post('joined_on'))??today()]);
        audit('class.member_added','class',(int)$c['id']);
        return ['classes',['id'=>$c['id']]];

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
            .' WHERE c.cancelled=0 AND c.due_on<?'.($only?' AND s.id=?':'')
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
        $group=choose(post('group'),['portal','students','payments','system']);
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

    case 'version_revert':
        require_admin();
        revert_version((int)post('id'));
        flash(t('Änderung zurückgenommen.','Change undone.'));
        return ['history',[]];

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

