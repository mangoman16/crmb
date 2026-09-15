<?php
declare(strict_types=1);

function dispatch_settings_or_messages(string $action): array {
    switch($action) {
    case 'tariff_save':
        require_admin();$id=(int)post('id');$days=(int)post('due_days','14');
        if($days<0 || $days>365)throw new UserError(t('Zahlungsziel: 0 bis 365 Tage.','Payment term: 0 to 365 days.'));
        $args=[required_text('name',120),cents(post('price')),choose(post('period'),['monthly','fixed','once']),$days,post('archived')?1:0];
        if($id)run('UPDATE tariffs SET name=?,price_cents=?,period=?,due_days=?,archived=? WHERE id=?',[...$args,$id]);
        else {run('INSERT INTO tariffs (name,price_cents,period,due_days,archived) VALUES (?,?,?,?,?)',$args);$id=(int)db()->lastInsertId();}
        audit('tariff.saved','tariff',$id);flash(t('Tarif gespeichert. Bestehende Vereinbarungen bleiben erhalten.','Tariff saved. Existing agreements are preserved.'));return ['settings',['tab'=>'tariffs']];
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
    case 'defaults_save':
        require_admin();set_setting('club_name',required_text('club_name',100));
        foreach(['statuses','absence_reasons'] as $key) {
            $values=[];
            $keys=$_POST[$key.'_keys']??[];$labels=$_POST[$key.'_labels']??[];
            if(!is_array($keys)||!is_array($labels))throw new UserError('Invalid list');
            foreach($labels as $i=>$label) {
                if(!is_scalar($label)||!is_scalar($keys[$i]??''))throw new UserError('Invalid list');
                $label=trim((string)$label);if($label==='')continue;
                $code=trim((string)($keys[$i]??''));if($code==='')$code='custom_'.bin2hex(random_bytes(4));
                $parts=[$code,$label];
                if(!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$parts[0]) || strlen($parts[1])>100) throw new UserError(t('Bitte gültige Bezeichnungen eingeben.','Please enter valid labels.'));
                if(isset($values[$parts[0]]))throw new UserError(t('Kürzel doppelt vorhanden.','Duplicate key.'));
                $values[$parts[0]]=$parts[1];
            }
            if(!$values || count($values)>80)throw new UserError(t('Bitte eine gültige Liste eingeben.','Please enter a valid list.'));
            $used=$key==='statuses'?rows('SELECT DISTINCT status AS code FROM students'):rows('SELECT DISTINCT reason AS code FROM absences');
            foreach($used as $r)if(!isset($values[$r['code']]))throw new UserError(t('Verwendetes Kürzel muss erhalten bleiben: ','Keep this key because records use it: ').$r['code']);
            set_setting($key,$values);
        }
        set_setting('default_status',choose(post('default_status'),array_keys(statuses())));
        $tariff=(int)post('default_tariff')?:null;
        if($tariff && !one('SELECT id FROM tariffs WHERE id=? AND archived=0',[$tariff]))throw new UserError('Invalid tariff');
        set_setting('default_tariff',$tariff);
        $methods=array_values(array_unique(array_filter(array_map('trim',explode("\n",post('payment_methods'))),fn($x)=>$x!=='')));
        if(!$methods || count($methods)>30 || mb_strlen(post('payment_methods'))>2000)throw new UserError('Invalid payment methods');
        foreach($methods as $method)if(mb_strlen($method)>100)throw new UserError('Payment method too long');
        set_setting('payment_methods',$methods);audit('defaults.saved','settings');flash(t('Vorgaben gespeichert.','Defaults saved.'));return ['settings',['tab'=>'defaults']];
    case 'smtp_save':
        require_admin();$old=setting('smtp',[]);$host=required_text('host',253);
        if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D',$host))throw new UserError(t('SMTP-Hostname ohne Protokoll oder Pfad eingeben.','Enter an SMTP hostname without a protocol or path.'));
        $port=(int)post('port');if($port<1 || $port>65535)throw new UserError('Invalid SMTP port');
        $s=['host'=>$host,'port'=>$port,'username'=>text_limit('username',254),'password'=>post('smtp_password')!==''?seal(post('smtp_password')):($old['password']??''),'encryption'=>choose(post('encryption'),['tls','ssl']),'from_email'=>email_value(post('from_email')),'from_name'=>required_text('from_name',120)];
        if(post('clear_password'))$s['password']='';
        set_setting('smtp',$s);audit('smtp.saved','settings');flash(t('SMTP-Einstellungen gespeichert.','SMTP settings saved.'));return ['settings',['tab'=>'smtp']];
    case 'smtp_test':
        $u=require_admin();throttle('smtp-test',(string)$u['id'],5,300);
        queue_mail((int)$u['id'],$u['email'],t('SMTP-Test: Badminton','SMTP test: Badminton'),t('Deine SMTP-Verbindung funktioniert.','Your SMTP connection works.'),'test');
        flash(t('Testmail liegt im Postausgang.','Test email is in the outbox.'));return ['outbox',[]];
    case 'mail_retry':
        require_staff();$j=one('SELECT * FROM mail_jobs WHERE id=? AND status=?',[(int)post('id'),'failed']);
        if(!$j)throw new UserError('Not found');
        if($j['category']==='security')throw new UserError(t('Bitte einen neuen Einladungs- oder Passwortlink anfordern.','Please request a fresh invitation or password link.'));
        run("UPDATE mail_jobs SET status='queued',error=NULL,retry_after=NULL,attempts=0 WHERE id=?",[$j['id']]);return ['outbox',[]];
    case 'mail_run':
        require_admin();return ['outbox',['process'=>1]];
    case 'privacy_save':
        require_admin();$de=text_limit('privacy_de',30000);$en=text_limit('privacy_en',30000);
        if(post('privacy_ready') && (mb_strlen($de)<300 || mb_strlen($en)<300 || preg_match('/\[[^\]]+\]/u',$de.$en)))throw new UserError(t('Bitte beide Datenschutzerklärungen vervollständigen und Platzhalter ersetzen.','Complete both privacy notices and replace the placeholders.'));
        set_setting('privacy_de',$de);set_setting('privacy_en',$en);set_setting('privacy_ready',(bool)post('privacy_ready'));
        audit('privacy.saved','settings');flash(t('Datenschutzerklärung gespeichert.','Privacy notice saved.'));return ['settings',['tab'=>'privacy']];
    case 'template_save':
        require_admin();$id=(int)post('id');$subject=required_text('subject',180);$body=required_text('body',20000);
        preg_match_all('/\{\{[^}]+\}\}/',$subject.$body,$matches);
        foreach($matches[0] as $match)if(!in_array($match,['{{student_name}}','{{first_name}}','{{tariff}}','{{outstanding}}','{{paid_through}}','{{portal_url}}'],true))throw new UserError(t('Unbekannter Platzhalter: ','Unknown placeholder: ').$match);
        $args=[required_text('name',120),$subject,$body];
        if($id)run('UPDATE message_templates SET name=?,subject=?,body=? WHERE id=?',[...$args,$id]);else run('INSERT INTO message_templates (name,subject,body) VALUES (?,?,?)',$args);
        audit('template.saved','template',$id?:null);flash(t('Vorlage gespeichert.','Template saved.'));return ['settings',['tab'=>'templates']];
    case 'filter_save':
        require_staff();$criteria=filters_from($_POST);
        run('INSERT INTO saved_filters (name,criteria_json) VALUES (?,?)',[required_text('name',120),json_encode($criteria,JSON_UNESCAPED_UNICODE)]);flash(t('Filter gespeichert.','Filter saved.'));return ['students',$criteria];
    case 'filter_delete':
        require_staff();run('DELETE FROM saved_filters WHERE id=?',[(int)post('id')]);return ['students',[]];
    case 'preferences_save':
        $u=require_user();$newsletter=(bool)post('newsletter');$notifications=(bool)post('notifications');
        run('UPDATE accounts SET name=?,locale=?,theme=?,text_scale=?,newsletter=?,notifications=? WHERE id=?',[required_text('name'),choose(post('locale'),['de','en']),choose(post('theme','auto'),['auto','light','dark']),choose(post('text_scale','normal'),['normal','large','larger','largest']),$newsletter?1:0,$notifications?1:0,$u['id']]);
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
