<?php
declare(strict_types=1);

/**
 * Actions for the things an administrator configures and a trainer uses:
 * classes, payment profiles, rating scales, skill areas, skills, assessments,
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
        require_admin(); $id=(int)post('id');
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
        require_admin(); $c=training_class((int)post('id'));
        if(post('confirmation')!==$c['name']) throw new UserError(t('Bitte den Kursnamen zur Bestätigung eingeben.','Please enter the class name to confirm.'));
        // Charges reference the class with ON DELETE SET NULL, so payment history
        // survives; only the grouping goes away.
        run('DELETE FROM classes WHERE id=?',[$c['id']]);
        audit('class.deleted','class',(int)$c['id']); flash(t('Kurs gelöscht. Beiträge und Zahlungen bleiben erhalten.','Class deleted. Charges and payments are kept.'));
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
        require_admin(); $id=(int)post('id');
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
        return ['settings',['tab'=>'payments','edit'=>$id]];

    // ---- rating scales, areas and skills -------------------------------

    case 'scale_save':
        require_admin(); $id=(int)post('id');
        if($id && !one('SELECT id FROM rating_scales WHERE id=?',[$id])) throw new UserError(t('Skala nicht gefunden.','Scale not found.'));
        $min=decimal_value(post('min_value','0')); $max=decimal_value(post('max_value','10')); $step=decimal_value(post('step','1'));
        if($max<=$min) throw new UserError(t('Der Höchstwert muss über dem Mindestwert liegen.','The maximum must be above the minimum.'));
        if($step<=0 || ($max-$min)/$step>200) throw new UserError(t('Die Schrittweite ergibt zu viele Stufen (höchstens 200).','That step size produces too many steps (200 at most).'));
        // Labels are optional; one "value = label" per line names individual steps.
        $labels=[];
        foreach(explode("\n",post('labels')) as $line) {
            $line=trim($line); if($line==='') continue;
            if(!str_contains($line,'=')) throw new UserError(t('Bezeichnungen je Zeile als „Wert = Text“ angeben.','Write one label per line as “value = text”.'));
            [$k,$v]=array_map('trim',explode('=',$line,2));
            if($k===''||$v==='') continue;
            $labels[$k]=mb_substr($v,0,80);
        }
        if(count($labels)>200) throw new UserError(t('Zu viele Bezeichnungen.','Too many labels.'));
        $args=[required_text('name',120),$min,$max,$step,json_encode($labels,JSON_UNESCAPED_UNICODE),post('archived')?1:0];
        if($id) run('UPDATE rating_scales SET name=?,min_value=?,max_value=?,step=?,labels_json=?,archived=? WHERE id=?',[...$args,$id]);
        else { run('INSERT INTO rating_scales (name,min_value,max_value,step,labels_json,archived,created_at) VALUES (?,?,?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId(); }
        audit('scale.saved','rating_scale',$id); flash(t('Skala gespeichert. Bereits erfasste Werte bleiben unverändert.','Scale saved. Values already recorded are unchanged.'));
        return ['settings',['tab'=>'skills']];

    case 'area_save':
        require_admin(); $id=(int)post('id');
        if($id && !one('SELECT id FROM skill_areas WHERE id=?',[$id])) throw new UserError(t('Bereich nicht gefunden.','Area not found.'));
        $args=[required_text('name',120),text_limit('description',500),(int)post('sort_order','0'),post('archived')?1:0];
        if($id) run('UPDATE skill_areas SET name=?,description=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
        else { run('INSERT INTO skill_areas (name,description,sort_order,archived,created_at) VALUES (?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId(); }
        audit('area.saved','skill_area',$id); flash(t('Bereich gespeichert.','Area saved.'));
        return ['settings',['tab'=>'skills']];

    case 'skill_save':
        require_admin(); $id=(int)post('id');
        if($id && !one('SELECT id FROM skills WHERE id=?',[$id])) throw new UserError(t('Fähigkeit nicht gefunden.','Skill not found.'));
        $areaId=(int)post('area_id'); if(!one('SELECT id FROM skill_areas WHERE id=?',[$areaId])) throw new UserError(t('Bitte einen Bereich auswählen.','Please choose an area.'));
        $scaleId=(int)post('scale_id'); if(!one('SELECT id FROM rating_scales WHERE id=?',[$scaleId])) throw new UserError(t('Bitte eine Skala auswählen.','Please choose a scale.'));
        // Changing the scale under existing values would silently reinterpret
        // them, so it is refused the same way a custom field type change is.
        if($id) {
            $current=(int)scalar('SELECT scale_id FROM skills WHERE id=?',[$id]);
            if($current!==$scaleId && scalar('SELECT COUNT(*) FROM assessments WHERE skill_id=?',[$id]))
                throw new UserError(t('Für diese Fähigkeit sind Bewertungen erfasst. Für eine andere Skala bitte eine neue Fähigkeit anlegen und diese archivieren.','Assessments exist for this skill. Create a new skill for a different scale and archive this one.'));
        }
        $args=[$areaId,$scaleId,required_text('name',120),text_limit('description',500),(int)post('sort_order','0'),post('archived')?1:0];
        if($id) run('UPDATE skills SET area_id=?,scale_id=?,name=?,description=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
        else { run('INSERT INTO skills (area_id,scale_id,name,description,sort_order,archived,created_at) VALUES (?,?,?,?,?,?,?)',[...$args,now()]); $id=(int)db()->lastInsertId(); }
        audit('skill.saved','skill',$id); flash(t('Fähigkeit gespeichert.','Skill saved.'));
        return ['settings',['tab'=>'skills']];

    // ---- assessments (trainer's day-to-day work) -----------------------

    case 'assessment_save':
        $u=require_staff(); $s=student((int)post('student_id'));
        $on=date_value(post('assessed_on'),true);
        if($on>today()) throw new UserError(t('Das Datum liegt in der Zukunft.','That date is in the future.'));
        $input=$_POST['skill']??[]; if(!is_array($input)) throw new UserError(t('Ungültige Eingabe.','Invalid input.'));
        $notes=$_POST['skill_note']??[]; if(!is_array($notes)) throw new UserError(t('Ungültige Eingabe.','Invalid input.'));
        $saved=0; $cleared=0;
        foreach(skills() as $skill) {
            $sid=(int)$skill['id'];
            if(!array_key_exists($sid,$input)) continue;
            $raw=is_scalar($input[$sid])?trim((string)$input[$sid]):'';
            // A blank value removes that day's entry rather than storing a zero,
            // so "not assessed" and "assessed as zero" stay distinguishable.
            if($raw==='') {
                $cleared += run('DELETE FROM assessments WHERE student_id=? AND skill_id=? AND assessed_on=?',[$s['id'],$sid,$on])->rowCount();
                continue;
            }
            if(!isset(scale_values($skill)[$raw])) throw new UserError(t('Ungültiger Wert für ','Invalid value for ').$skill['name'].'.');
            $note=is_scalar($notes[$sid]??'')?mb_substr(trim((string)($notes[$sid]??'')),0,500):'';
            run('INSERT INTO assessments (student_id,skill_id,value,note,assessed_on,assessed_by,created_at) VALUES (?,?,?,?,?,?,?)'
                .' ON DUPLICATE KEY UPDATE value=VALUES(value),note=VALUES(note),assessed_by=VALUES(assessed_by)',
                [$s['id'],$sid,$raw,$note,$on,$u['id'],now()]);
            $saved++;
        }
        audit('assessment.saved','student',(int)$s['id']);
        flash($saved.' '.t('Bewertungen gespeichert.','assessments saved.').($cleared?' '.$cleared.' '.t('entfernt.','removed.'):''));
        return ['student',['id'=>$s['id'],'tab'=>'skills']];

    case 'assessment_delete':
        require_staff(); $s=student((int)post('student_id'));
        run('DELETE FROM assessments WHERE student_id=? AND skill_id=? AND assessed_on=?',[$s['id'],(int)post('skill_id'),date_value(post('assessed_on'),true)]);
        audit('assessment.deleted','student',(int)$s['id']);
        return ['student',['id'=>$s['id'],'tab'=>'skills']];

    case 'payment_remind':
        $u=require_staff(); throttle('payment-remind',(string)$u['id'],6,3600);
        if(!setting('smtp',[])) throw new UserError(t('Bitte zuerst SMTP einrichten.','Set up SMTP first.'));
        $only=(int)post('student_id');
        $sent=0; $skipped=0;
        // Group by account: one parent with three children gets three lines of
        // detail, not three separate emails.
        foreach(rows('SELECT c.*, s.first_name, s.last_name, s.account_id,'
            .' COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.charge_id=c.id AND p.confirmed_at IS NOT NULL AND p.voided=0),0) AS paid'
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
        require_admin(); $group=choose(post('group'),['portal','students','payments','skills']);
        foreach(settings_in_group($group) as $key=>$spec) {
            $raw = $spec['kind']==='bool' ? (post('set_'.$key)!=='') : ($_POST['set_'.$key] ?? '');
            if($spec['kind']==='map') { set_setting($key,map_from_post($key,$spec)); continue; }
            if(is_array($raw)) throw new UserError(t('Ungültige Eingabe.','Invalid input.'));
            set_setting($key,setting_validate($key,$spec,$raw));
        }
        audit('settings.saved','settings'); flash(t('Vorgaben gespeichert.','Defaults saved.'));
        return ['settings',['tab'=>$group]];

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

/** HH:MM from a form, or null when left empty. */
function time_value(string $value): ?string {
    if(trim($value)==='') return null;
    if(!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D',trim($value),$m)) throw new UserError(t('Bitte eine Uhrzeit als HH:MM eingeben.','Please enter a time as HH:MM.'));
    return $m[0].':00';
}

/** A decimal from a form, accepting a comma as the separator. */
function decimal_value(string $value): float {
    $v=str_replace(',','.',trim($value));
    if(!preg_match('/^-?\d{1,4}(?:\.\d{1,2})?$/D',$v)) throw new UserError(t('Bitte eine Zahl eingeben, z. B. 0,5.','Please enter a number, e.g. 0.5.'));
    return (float)$v;
}

/**
 * Read an optional foreign key from a form and confirm the row exists.
 *
 * Returns null for "none selected" so the column keeps its meaning, and rejects
 * a dangling id rather than letting the database raise a constraint error the
 * operator cannot interpret.
 */
function reference_or_null(string $table, string $field, string $extra=''): ?int {
    $id=(int)post($field);
    if($id<=0) return null;
    if(!one('SELECT id FROM '.$table.' WHERE id=?'.($extra?' AND '.$extra:''),[$id]))
        throw new UserError(t('Die Auswahl ist nicht verfügbar.','That selection is not available.'));
    return $id;
}

/** code => label pairs from the paired inputs the settings form renders. */
function map_from_post(string $key, array $spec): array {
    $keys=$_POST[$key.'_keys']??[]; $labels=$_POST[$key.'_labels']??[];
    if(!is_array($keys)||!is_array($labels)) throw new UserError(t('Ungültige Liste.','Invalid list.'));
    $out=[];
    foreach($labels as $i=>$label) {
        if(!is_scalar($label)||!is_scalar($keys[$i]??'')) throw new UserError(t('Ungültige Liste.','Invalid list.'));
        $label=trim((string)$label); if($label==='') continue;
        $code=trim((string)($keys[$i]??''));
        if($code==='') $code='custom_'.bin2hex(random_bytes(4));
        if(!preg_match('/^[a-z0-9][a-z0-9_]{0,39}$/D',$code) || mb_strlen($label)>100)
            throw new UserError(setting_label($spec).': '.t('Bitte gültige Bezeichnungen eingeben.','Please enter valid labels.'));
        if(isset($out[$code])) throw new UserError(setting_label($spec).': '.t('Kürzel doppelt vorhanden.','Duplicate key.'));
        $out[$code]=$label;
    }
    if(!$out || count($out)>80) throw new UserError(setting_label($spec).': '.t('Bitte eine gültige Liste eingeben.','Please enter a valid list.'));
    // A code still referenced by a record must survive, or those rows would
    // display a raw key with no label.
    foreach(['statuses'=>'SELECT DISTINCT status AS code FROM students','absence_reasons'=>'SELECT DISTINCT reason AS code FROM absences'] as $k=>$sql)
        if($key===$k) foreach(rows($sql) as $r)
            if(!isset($out[$r['code']])) throw new UserError(t('Verwendetes Kürzel muss erhalten bleiben: ','Keep this key because records use it: ').$r['code']);
    return $out;
}

/**
 * IBAN check: layout, then the mod-97 checksum.
 *
 * A typo in an IBAN means money goes nowhere or somewhere else, so it is worth
 * catching at entry rather than when a parent's transfer bounces.
 */
function valid_iban(string $iban): bool {
    if(!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/D',$iban)) return false;
    $rearranged=substr($iban,4).substr($iban,0,4);
    $digits='';
    foreach(str_split($rearranged) as $ch)
        $digits .= ctype_digit($ch) ? $ch : (string)(ord($ch)-55);
    // bcmod-free mod 97 for a number far wider than an integer.
    $remainder=0;
    foreach(str_split($digits) as $d) $remainder=($remainder*10+(int)$d)%97;
    return $remainder===1;
}
