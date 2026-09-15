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
    set_setting('defaults_initialized',true);db()->commit();
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
