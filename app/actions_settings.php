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
    case 'record_duplicate':
        // One handler for every list she builds by hand. Which tables may be
        // copied, and where the copy is then opened, are declared in
        // duplicate.php rather than spelled out again per list.
        require_staff();$table=post('table');
        if(!isset(duplicable_records()[$table]))throw new UserError(t('Das lässt sich nicht kopieren.','That cannot be copied.'));
        if(in_array($table,['field_definitions','payment_profiles','message_templates'],true))require_admin();
        $copy=duplicate_record($table,(int)post('id'));
        flash(t('Kopie angelegt. Sie ist noch nicht veröffentlicht – ändern und speichern.','Copy created. It is not published yet – change it and save.'));
        return duplicate_destination($table,$copy);
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
        $def=validate_custom(['options_json'=>$optionsJson,'field_type'=>$type,'label'=>$label],$def,null,false);
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
        // The last test was of the old settings, and smtp_tested_ok() would go
        // on calling mail working on the strength of it.
        if(smtp_settings_changed($old,$s))set_setting('smtp_last_test',[]);
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
    case 'portal_icon_save':
        // The file is checked before the setting points at it, and the old one
        // is deleted only after, so a refused picture leaves the icon she had.
        // Not tracked(): removing deletes the file, and the way back is to
        // upload it again, the same as for any other setting saved here.
        require_admin(); $old=portal_icon(); $new='';
        if(!post('remove')) { $new=store_upload('icon','icon')['stored_name']; check_portal_icon($new); }
        set_setting('portal_icon',$new);
        audit($new!==''?'portal_icon.saved':'portal_icon.removed','settings');
        // Last, because a file cannot be rolled back. The request's transaction
        // commits after this; should that commit fail, the setting still names
        // the old file, which is gone - and portal_icon() checks is_file(), so
        // the built-in icon shows rather than a broken picture.
        if($old!=='' && $old!==$new) delete_upload('icon',$old);
        flash($new!==''
            ?t('Symbol gespeichert. Im Browser erscheint es beim nächsten Seitenaufruf. Wer das Portal schon auf dem Home-Bildschirm hat, sieht es dort erst, wenn es neu hinzugefügt wird.',
               'Icon saved. The browser shows it on the next page. Anybody who already has the portal on their home screen sees it there once they add it again.')
            :t('Das Standard-Symbol wird wieder verwendet.','The standard icon is used again.'));
        return ['settings',['tab'=>'portal']];
    case 'portal_logo_save':
        // The same steps in the same order as the icon (ADR 0014): checked
        // before the setting points at it, the old file deleted only after, so
        // a refused picture leaves the logo she had. Not tracked(): the way
        // back from a removal is to upload the file again.
        require_admin(); $old=portal_logo(); $new='';
        if(!post('remove')) { $new=store_upload('logo','logo')['stored_name']; check_portal_logo($new); }
        set_setting('portal_logo',$new);
        audit($new!==''?'portal_logo.saved':'portal_logo.removed','settings');
        // Last, as for the icon: should the request's commit fail after this,
        // portal_logo() finds no file and the icon, or the „B", shows instead.
        if($old!=='' && $old!==$new) delete_upload('logo',$old);
        flash($new!==''?t('Logo gespeichert.','Logo saved.')
            :(portal_icon()!==''
                ?t('Logo entfernt. Oben links steht wieder das Symbol des Portals.','Logo removed. The portal icon is shown top left again.')
                :t('Logo entfernt. Oben links steht wieder das „B“.','Logo removed. The “B” is shown top left again.')));
        return ['settings',['tab'=>'portal']];
    case 'privacy_save':
        require_admin();$de=text_limit('privacy_de',30000);$en=text_limit('privacy_en',30000);
        // A draft saves whenever the release tick is not set. With the tick, a
        // text that is not ready is refused, and like every refusal that rolls
        // the whole save back, text included - so the refusal says which version
        // and which placeholder is in the way, and the router offers the typed
        // text back (remember_input()): "complete both and replace the
        // placeholders" sent an operator hunting through two walls of text, and
        // looked from the outside as though saving had done nothing.
        // Only the German text is required (ADR 0011); an English reader is shown
        // it, with a line saying so. An English text that is there is checked the
        // same way, because a half-translated notice is not one to release.
        if(post('privacy_ready')) foreach(['Deutsch'=>$de]+($en!==''?['English'=>$en]:[]) as $which=>$text) {
            if(mb_strlen($text)<300) throw new UserError(t('Die Fassung „','The “').$which.t('“ ist noch zu kurz, um freigegeben zu werden.','” version is still too short to be released.'));
            // Quoted by its start: enough to find it, and some run to 300 characters.
            if($left=privacy_draft_placeholders($text)) throw new UserError(t('In der Fassung „','In the “').$which.t('“ steht noch ein Platzhalter: ','” version there is still a placeholder: ').mb_strimwidth($left[0],0,100,'…'));
        }
        set_setting('privacy_de',$de);set_setting('privacy_en',$en);set_setting('privacy_ready',(bool)post('privacy_ready'));
        audit('privacy.saved','settings');flash(t('Datenschutzerklärung gespeichert.','Privacy notice saved.'));return ['settings',['tab'=>'privacy']];
    case 'setup_visibility':
        // The start checklist is put away or brought back, never ticked: what is
        // done is read from the data on every visit (ADR 0011). Hiding it is
        // offered once everything is done, showing it again under Einstellungen.
        require_admin();$hidden=choose(post('hidden'),['0','1'])==='1';
        set_setting('setup_hidden',$hidden);audit($hidden?'setup.hidden':'setup.shown','settings');
        flash($hidden?t('Die Einrichtung ist ausgeblendet. Unter „Einstellungen“ lässt sie sich wieder ansehen.','The setup checklist is hidden. You can look at it again under “Settings”.')
                     :t('Die Einrichtung wird wieder angezeigt.','The setup checklist is shown again.'));
        return $hidden?['dashboard',[]]:['start',[]];
    case 'auto_billing_save':
        // On the Beiträge page, beside the charges it would create, rather than
        // among the settings. Administrator only, as it was there: charges
        // written without anybody looking are a decision about the whole portal.
        // A box left unticked posts nothing, and nothing means off.
        require_admin();$on=post('auto_billing')==='1';
        set_setting('auto_billing',$on);audit($on?'billing.auto_on':'billing.auto_off','settings');
        flash($on?t('Monatsbeiträge werden ab jetzt automatisch angelegt: einmal im Monat, beim ersten Seitenaufruf.','Monthly charges are now created automatically: once a month, on the first page view.')
                 :t('Monatsbeiträge werden nicht mehr automatisch angelegt. Du legst sie unten unter „Beiträge anlegen“ an, mit Vorschau.','Monthly charges are no longer created automatically. Create them below under “Create charges”, with a preview.'));
        return ['payments',[]];
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
    case 'presence_save':
        // Asked before who is asking: while staff look through a family's eyes
        // the session is the family's, and the answer she needs is how to get
        // back to her own, not that families have no status (ADR 0016).
        if(impersonator())throw new UserError(t('Den Status kann nur die Person selbst ändern. Beende zuerst die Ansicht.','Only the person themselves can change their status. Stop viewing first.'));
        // Staff only: the owner decided a family has no status to choose (ADR 0015).
        $u=require_user();
        if(!is_staff($u))throw new UserError(t('Einen Status wählen nur Trainerinnen und Administratoren.','Only trainers and administrators choose a status.'));
        $choice=choose(post('presence'),array_keys(presence_choices()));
        run('UPDATE accounts SET presence=? WHERE id=?',[$choice,$u['id']]);
        // So the very next page view writes, and opens a period with the new
        // hidden flag, instead of extending the old one for up to a minute.
        $_SESSION['seen_written']=0;
        // Neither tracked() nor audited: one tap puts it back, and it is the
        // person's own business (ADR 0016).
        flash(match($choice){
            'away'   => t('Dein Status ist jetzt „Abwesend“.','Your status is now “Away”.'),
            'hidden' => t('Du wirst jetzt als offline angezeigt.','You now appear offline.'),
            default  => t('Dein Status richtet sich wieder nach deiner Aktivität.','Your status follows your activity again.'),
        });
        return form_return();
    case 'email_change':
        /* The holder moves their own address, confirmed by a link to the new
           one. Whether the address is already another login's is asked only
           when the link is opened (change_account_email()), never here: the
           address signs in, so "does this address have a login?" is half a
           credential, and a page that answered it would undo what the sign-in
           and „vergessen" pages are built not to say. Whoever reads the refusal
           then is whoever reads that mailbox, who knows already (ADR 0020, §1). */
        $u=require_user();throttle('email-change',(string)$u['id'],5,3600);
        if(!password_verify(post('password'),$u['password_hash']))throw new UserError(t('Passwort nicht korrekt.','Incorrect password.'));
        $email=email_value(post('email'));
        if(same_address($email,(string)$u['email']))throw new UserError(t('Das ist schon die Adresse dieses Kontos.','That is already this account’s address.'));
        send_account_token($u,'email',$email);flash(t('Bitte die neue E-Mail-Adresse über den zugesendeten Link bestätigen.','Please verify the new email address using the link sent to it.'));return ['profile',[]];
    case 'password_change':
        $u=require_user();if(!password_verify(post('current_password'),$u['password_hash']))throw new UserError(t('Passwort nicht korrekt.','Incorrect password.'));
        $p=strong_password(post('password'));if($p!==post('password_confirm'))throw new UserError(t('Die Passwörter stimmen nicht überein.','Passwords do not match.'));
        run('UPDATE accounts SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($p,PASSWORD_DEFAULT),$u['id']]);run('DELETE FROM auth_tokens WHERE account_id=?',[$u['id']]);sign_in(one('SELECT * FROM accounts WHERE id=?',[$u['id']]));
        audit('password.changed','account',(int)$u['id']);flash(t('Passwort geändert. Andere Sitzungen wurden abgemeldet.','Password changed. Other sessions were signed out.'));return ['profile',[]];
    }
    return dispatch_messages($action);
}
