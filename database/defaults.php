<?php
declare(strict_types=1);

if(setting('defaults_initialized',false))return;
db()->beginTransaction();
try{
    set_setting('club_name','Badminton');set_setting('default_status','active');set_setting('default_tariff',null);
    set_setting('statuses',['trial'=>'Probetraining','active'=>'Aktiv','paused'=>'Pausiert','ended'=>'Beendet']);
    set_setting('absence_reasons',['sick'=>'Krank','holiday'=>'Urlaub','other'=>'Abwesend']);
    set_setting('payment_methods',['Überweisung','Bar']);set_setting('privacy_ready',false);
    set_setting('privacy_de',file_get_contents(ROOT.'/docs/privacy-draft-de.txt'));
    set_setting('privacy_en',file_get_contents(ROOT.'/docs/privacy-draft-en.txt'));
    run('INSERT INTO field_definitions (label,label_en,field_type,section_name,options_json,default_json,visibility,sort_order) VALUES (?,?,?,?,?,?,?,?)',['Trainingsgruppe','Training group','select','','["Gruppe 1","Gruppe 2"]','""','view',10]);
    run('INSERT INTO message_templates (name,subject,body) VALUES (?,?,?)',['Zahlungserinnerung','Dein Badminton-Beitrag',"Hallo {{first_name}},\n\nbei deinen Badminton-Beiträgen sind derzeit {{outstanding}} offen. Bitte prüfe die Beiträge im Portal. Falls du bereits bezahlt hast, gib mir dort kurz Bescheid.\n\n{{portal_url}}\n\nVielen Dank!"]);
    run('INSERT INTO message_templates (name,subject,body) VALUES (?,?,?)',['Training – Information','Information zum Training',"Hallo {{first_name}},\n\n\n\nDu kannst mir direkt im Portal antworten:\n{{portal_url}}"]);
    // Scales, areas and skills are seeded as editable examples, not fixtures:
    // renaming or deleting them is expected and breaks nothing.
    run('INSERT INTO rating_scales (name,min_value,max_value,step,labels_json,created_at) VALUES (?,?,?,?,?,?)',['0 bis 10',0,10,1,'[]',now()]);
    $tenPoint=(int)db()->lastInsertId();
    run('INSERT INTO rating_scales (name,min_value,max_value,step,labels_json,created_at) VALUES (?,?,?,?,?,?)',
        ['Schulnote 1 bis 5',1,5,1,json_encode(['1'=>'1 – sehr gut','2'=>'2 – gut','3'=>'3 – befriedigend','4'=>'4 – genügend','5'=>'5 – nicht genügend'],JSON_UNESCAPED_UNICODE),now()]);
    foreach([['Technik',10,['Genauigkeit','Aufschlag','Clear','Netzspiel']],['Kondition',20,['Ausdauer','Beinarbeit','Schnelligkeit']],['Taktik',30,['Spielübersicht','Aufstellung']]] as [$area,$order,$list]) {
        run('INSERT INTO skill_areas (name,sort_order,created_at) VALUES (?,?,?)',[$area,$order,now()]);
        $areaId=(int)db()->lastInsertId();
        foreach($list as $i=>$skill)
            run('INSERT INTO skills (area_id,scale_id,name,sort_order,created_at) VALUES (?,?,?,?,?)',[$areaId,$tenPoint,$skill,($i+1)*10,now()]);
    }
    // An empty profile with the SEPA payload already in place: the operator fills
    // in their own bank details and the QR code starts working.
    run('INSERT INTO payment_profiles (name,recipient,currency,qr_template,note,created_at) VALUES (?,?,?,?,?,?)',
        ['Vereinskonto',setting('club_name'),'EUR',"BCD\n002\n1\nSCT\n{bic}\n{recipient}\n{iban}\n{currency}{amount}\n\n{reference}",'',now()]);
    set_setting('default_payment_profile',(int)db()->lastInsertId());
    set_setting('defaults_initialized',true);db()->commit();
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
