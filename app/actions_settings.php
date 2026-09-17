<?php
declare(strict_types=1);

function dispatch_settings_or_messages(string $action): array {
    switch($action) {
    case 'tariff_save':
        require_staff();$id=(int)post('id');
        if($id && !one('SELECT id FROM tariffs WHERE id=?',[$id]))throw new UserError(t('Diesen Tarif gibt es nicht.','No such tariff.'));
        $classId=reference_or_null('classes','class_id');
        if(!$classId)throw new UserError(t('Bitte den Kurs wählen, zu dem dieser Tarif gehört.','Please choose the course this tariff belongs to.'));
        $recurring=post('period','recurring')!=='once';
        $rates=posted_tariff_rates($recurring);
        $interval=$recurring?billing_valid_interval((int)post('interval_months','1')):1;
        // The normal interval is one of the prices on the list, or the list says
        // one thing and the tariff says another - and the enrolments that follow
        // the tariff would be billed at a price that is not written down.
        if(!isset($rates[$interval]))throw new UserError(t('Für den üblichen Zeitraum ist kein Preis eingetragen.','There is no price for the usual interval.'));
        $dueDay=(int)post('due_day','1');
        if($dueDay<1||$dueDay>28)throw new UserError(t('Zahltag: 1 bis 28. Der 29. bis 31. existiert nicht in jedem Monat.','Payment day: 1 to 28. The 29th to 31st do not exist in every month.'));
        $grace=(int)post('grace_days','7');
        if($grace<0||$grace>365)throw new UserError(t('Frist bis „überfällig“: 0 bis 365 Tage.','Days before overdue: 0 to 365.'));
        $firstPeriod=choose(post('first_period','prorate'),array_keys(billing_first_period_rules()));
        $templates=posted_discount_templates();
        $args=[$classId,required_text('name',120),text_limit('description',300),
               $recurring?'recurring':'once',$interval,$dueDay,$grace,$firstPeriod,
               (int)post('sort_order','0'),post('archived')?1:0];
        $id=transactional(function() use ($id,$args,$rates,$templates) {
            if($id)run('UPDATE tariffs SET class_id=?,name=?,description=?,period=?,interval_months=?,due_day=?,grace_days=?,first_period=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
            else {run('INSERT INTO tariffs (class_id,name,description,period,interval_months,due_day,grace_days,first_period,sort_order,archived) VALUES (?,?,?,?,?,?,?,?,?,?)',$args);$id=(int)db()->lastInsertId();}
            // Rewritten rather than merged: a row removed from the form is a
            // price she has taken off the list, and merging would leave it there.
            // Enrolments name an interval, not a rate row, so nothing dangles.
            run('DELETE FROM tariff_rates WHERE tariff_id=?',[$id]);
            foreach($rates as $months=>$cents)
                run('INSERT INTO tariff_rates (tariff_id,interval_months,price_cents) VALUES (?,?,?)',[$id,$months,$cents]);
            run('DELETE FROM tariff_discounts WHERE tariff_id=?',[$id]);
            foreach($templates as $i=>$template)
                run('INSERT INTO tariff_discounts (tariff_id,name,months,kind,value,sort_order) VALUES (?,?,?,?,?,?)',
                    [$id,$template['name'],$template['months'],$template['kind'],$template['value'],$i]);
            return $id;
        });
        audit('tariff.saved','tariff',$id);
        flash(t('Tarif gespeichert. Schon erstellte Beiträge ändern sich nicht.','Tariff saved. Charges already created are unchanged.'));
        return ['classes',['id'=>$classId,'tab'=>'tariffs']];
    case 'tariff_duplicate':
        require_staff();$source=one('SELECT * FROM tariffs WHERE id=?',[(int)post('id')]);
        if(!$source)throw new UserError(t('Diesen Tarif gibt es nicht.','No such tariff.'));
        $copy=duplicate_tariff((int)$source['id']);
        audit('tariff.duplicated','tariff',$copy);
        flash(t('Kopie angelegt. Name und Preise anpassen und speichern.','Copy created. Change the name and the prices, then save.'));
        return ['classes',['id'=>(int)$source['class_id'],'tab'=>'tariffs','tariff'=>$copy]];
    case 'field_save':
        require_admin();$id=(int)post('id');$old=$id?one('SELECT * FROM field_definitions WHERE id=?',[$id]):null;
        if($id && !$old)throw new UserError('Not found');
        $type=choose(post('field_type'),['text','textarea','number','date','select','multiselect','checkbox']);
        if($old && $old['field_type']!==$type && scalar('SELECT COUNT(*) FROM field_values WHERE field_id=?',[$id])) throw new UserError(t('Dieses Feld enthält Daten. Für einen anderen Typ bitte ein neues Feld anlegen und das alte archivieren.','This field contains data. Create a new field for a different type and archive the old field.'));
        $options=array_values(array_unique(array_filter(array_map('trim',explode("\n",post('options'))),fn($x)=>$x!=='')));
        if(count($options)>100 || mb_strlen(post('options'))>8000)throw new UserError(t('Zu viele Optionen.','Too many options.'));
        if(in_array($type,['select','multiselect'],true) && !$options)throw new UserError(t('Bitte mindestens eine Option eingeben.','Please enter at least one option.'));
        $def=post('default_value');
        if($type==='checkbox')$def=post('default_checked')==='1';
        if($type==='multiselect')$def=array_values(array_filter(array_map('trim',explode("\n",$def)),fn($x)=>$x!==''));
        $label=required_text('label',120);$optionsJson=json_encode($options,JSON_UNESCAPED_UNICODE);
        $def=validate_custom(['options_json'=>$optionsJson,'field_type'=>$type,'required'=>0,'label'=>$label],$def);
        $args=[$label,text_limit('label_en',120),$type,text_limit('section_name',120),$optionsJson,json_encode($def,JSON_UNESCAPED_UNICODE),post('required')?1:0,choose(post('visibility'),['internal','view','edit']),(int)post('sort_order'),post('archived')?1:0];
        if($id)run('UPDATE field_definitions SET label=?,label_en=?,field_type=?,section_name=?,options_json=?,default_json=?,required=?,visibility=?,sort_order=?,archived=? WHERE id=?',[...$args,$id]);
        else {run('INSERT INTO field_definitions (label,label_en,field_type,section_name,options_json,default_json,required,visibility,sort_order,archived) VALUES (?,?,?,?,?,?,?,?,?,?)',$args);$id=(int)db()->lastInsertId();}
        audit('field.saved','field',$id);flash(t('Feld gespeichert.','Field saved.'));return ['settings',['tab'=>'fields']];
    case 'smtp_save':
        require_admin();$old=setting('smtp',[]);$host=required_text('host',253);
        if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D',$host))throw new UserError(t('SMTP-Hostname ohne Protokoll oder Pfad eingeben.','Enter an SMTP hostname without a protocol or path.'));
        $port=(int)post('port');if($port<1 || $port>65535)throw new UserError('Invalid SMTP port');
        $s=['host'=>$host,'port'=>$port,'username'=>text_limit('username',254),'password'=>post('smtp_password')!==''?seal(post('smtp_password')):($old['password']??''),'encryption'=>choose(post('encryption'),['tls','ssl']),'from_email'=>email_value(post('from_email')),'from_name'=>required_text('from_name',120)];
        if(post('clear_password'))$s['password']='';
        set_setting('smtp',$s);audit('smtp.saved','settings');flash(t('SMTP-Einstellungen gespeichert.','SMTP settings saved.'));return ['settings',['tab'=>'smtp']];
    case 'smtp_test':
        // Run while she waits, rather than queued: she pressed the button to find
        // out whether the server answers, and an answer that turns up in the
        // outbox five minutes later - or never - is what sent her here.
        $u=require_admin();throttle('smtp-test',(string)$u['id'],10,300);
        $to=post('test_email')!==''?email_value(post('test_email')):'';
        $result=smtp_check(post('mode')==='connect'?null:($to?:$u['email']));
        set_setting('smtp_last_test',$result);
        audit($result['ok']?'smtp.tested':'smtp.test_failed','settings');
        flash($result['summary'],$result['ok']?'success':'error');
        return ['settings',['tab'=>'smtp']];
    case 'mail_retry':
        require_staff();$j=one('SELECT * FROM mail_jobs WHERE id=? AND status=?',[(int)post('id'),'failed']);
        if(!$j)throw new UserError('Not found');
        if($j['category']==='security')throw new UserError(t('Bitte einen neuen Einladungs- oder Passwortlink anfordern.','Please request a fresh invitation or password link.'));
        run("UPDATE mail_jobs SET status='queued',error=NULL,retry_after=NULL,attempts=0 WHERE id=?",[$j['id']]);return ['outbox',[]];
    case 'mail_run':
        require_admin();return ['outbox',['process'=>1]];
    case 'privacy_save':
        require_admin();$de=text_limit('privacy_de',30000);$en=text_limit('privacy_en',30000);
        // The text is always saved. Only the release tick is refused, and it says
        // which version and which placeholder is in the way: "complete both and
        // replace the placeholders" sent an operator hunting through two walls of
        // text, and looked from the outside as though saving had done nothing.
        if(post('privacy_ready')) foreach(['privacy_de'=>[$de,'Deutsch'],'privacy_en'=>[$en,'English']] as [$text,$which]) {
            if(mb_strlen($text)<300) throw new UserError(t('Die Fassung „','The “').$which.t('“ ist noch zu kurz, um freigegeben zu werden.','” version is still too short to be released.'));
            if(preg_match('/\[[^\]]{1,80}\]/u',$text,$m)) throw new UserError(t('In der Fassung „','In the “').$which.t('“ steht noch ein Platzhalter: ','” version there is still a placeholder: ').$m[0]);
        }
        set_setting('privacy_de',$de);set_setting('privacy_en',$en);set_setting('privacy_ready',(bool)post('privacy_ready'));
        audit('privacy.saved','settings');flash(t('Datenschutzerklärung gespeichert.','Privacy notice saved.'));return ['settings',['tab'=>'privacy']];
    case 'template_save':
        require_staff();$id=(int)post('id');$subject=required_text('subject',180);$body=required_text('body',20000);
        preg_match_all('/\{\{[^}]+\}\}/',$subject.$body,$matches);
        $known=array_map(fn($k)=>'{{'.$k.'}}',array_keys(template_placeholders()));
        foreach($matches[0] as $match)if(!in_array($match,$known,true))
            throw new UserError(t('Unbekannter Platzhalter: ','Unknown placeholder: ').$match.'. '
                .t('Möglich sind: ','Available: ').implode(' ',$known));
        $args=[required_text('name',120),$subject,$body];
        if($id)run('UPDATE message_templates SET name=?,subject=?,body=? WHERE id=?',[...$args,$id]);else run('INSERT INTO message_templates (name,subject,body) VALUES (?,?,?)',$args);
        audit('template.saved','template',$id?:null);flash(t('Vorlage gespeichert.','Template saved.'));return ['manage',['tab'=>'templates']];
    case 'filter_save':
        require_staff();$criteria=filters_from($_POST);
        run('INSERT INTO saved_filters (name,criteria_json) VALUES (?,?)',[required_text('name',120),json_encode($criteria,JSON_UNESCAPED_UNICODE)]);flash(t('Filter gespeichert.','Filter saved.'));return ['students',$criteria];
    case 'filter_delete':
        require_staff();run('DELETE FROM saved_filters WHERE id=?',[(int)post('id')]);return ['students',[]];
    case 'preferences_save':
        $u=require_user();$newsletter=(bool)post('newsletter');$notifications=(bool)post('notifications');$payments=(bool)post('payment_notices');
        // An empty accent means "whatever the administrator chose", which is a
        // real answer and has to stay distinguishable from a colour.
        $accent=post('accent')===''?'':choose(post('accent'),array_keys(accents()));
        run('UPDATE accounts SET name=?,locale=?,theme=?,accent=?,text_scale=?,newsletter=?,notifications=?,payment_notices=? WHERE id=?',[required_text('name'),choose(post('locale'),['de','en']),choose(post('theme','auto'),['auto','light','dark']),$accent,choose(post('text_scale','normal'),['normal','large','larger','largest']),$newsletter?1:0,$notifications?1:0,$payments?1:0,$u['id']]);
        if((bool)($u['payment_notices']??1)!==$payments)record_consent((int)$u['id'],'payment_notices',$payments);
        if((bool)$u['newsletter']!==$newsletter)record_consent((int)$u['id'],'newsletter',$newsletter);
        if((bool)$u['notifications']!==$notifications)record_consent((int)$u['id'],'notifications',$notifications);
        $_SESSION['locale']=post('locale');flash(t('Einstellungen gespeichert.','Preferences saved.'));return ['profile',[]];
    case 'email_change':
        $u=require_user();throttle('email-change',(string)$u['id'],5,3600);
        if(!password_verify(post('password'),$u['password_hash']))throw new UserError(t('Passwort nicht korrekt.','Incorrect password.'));
        $email=email_value(post('email'));if($email===$u['email'] || scalar('SELECT id FROM accounts WHERE email=?',[$email]))throw new UserError(t('Diese Adresse kann nicht verwendet werden.','This address cannot be used.'));
        send_account_token($u,'email',$email);flash(t('Bitte die neue E-Mail-Adresse über den zugesendeten Link bestätigen.','Please verify the new email address using the link sent to it.'));return ['profile',[]];
    case 'password_change':
        $u=require_user();if(!password_verify(post('current_password'),$u['password_hash']))throw new UserError(t('Passwort nicht korrekt.','Incorrect password.'));
        $p=strong_password(post('password'));if($p!==post('password_confirm'))throw new UserError(t('Die Passwörter stimmen nicht überein.','Passwords do not match.'));
        run('UPDATE accounts SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($p,PASSWORD_DEFAULT),$u['id']]);run('DELETE FROM auth_tokens WHERE account_id=?',[$u['id']]);sign_in(one('SELECT * FROM accounts WHERE id=?',[$u['id']]));
        audit('password.changed','account',(int)$u['id']);flash(t('Passwort geändert. Andere Sitzungen wurden abgemeldet.','Password changed. Other sessions were signed out.'));return ['profile',[]];
    }
    return dispatch_messages($action);
}
