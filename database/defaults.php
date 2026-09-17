<?php
declare(strict_types=1);

/**
 * Seed data, in two parts that answer two different questions.
 *
 * The first part is the lists the application cannot work without. It is checked
 * on every schema update rather than only on a fresh install, because a release
 * that introduces one has to bring it to a portal that already exists - and the
 * migration file cannot, since the operator may have emptied the table since.
 *
 * The second part, below the guard, is the examples a new portal starts with.
 * Those run once: re-creating a template the operator deleted would be the
 * software arguing with her.
 */

// Levels: a child is always in one, so there is always one to be in.
if(!(int)scalar('SELECT COUNT(*) FROM levels')) {
    foreach([['Anfänger','Lernt die Grundschläge.',10,1],
             ['Fortgeschritten','Spielt sicher im Training.',20,0],
             ['Könner','Spielt Turniere oder Ligaspiele.',30,0]] as [$name,$about,$order,$isDefault])
        run('INSERT INTO levels (name,description,sort_order,is_default,created_at) VALUES (?,?,?,?,?)',
            [$name,$about,$order,$isDefault,now()]);
}
// Age groups: the bands she named, covering every age with no gap.
if(!(int)scalar('SELECT COUNT(*) FROM age_groups')) {
    foreach([['Unter 12',0,11,10],['Jugend',12,17,20],['Erwachsene',18,null,30]] as [$name,$min,$max,$order])
        run('INSERT INTO age_groups (name,min_age,max_age,sort_order,created_at) VALUES (?,?,?,?,?)',
            [$name,$min,$max,$order,now()]);
}
// Students written before levels existed point at nothing. They start where a
// new child starts; only rows that have never been given one are touched.
run('UPDATE students SET level_id=(SELECT id FROM levels WHERE is_default=1 ORDER BY id LIMIT 1) WHERE level_id IS NULL');

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
    // An empty profile with the SEPA payload already in place: the operator fills
    // in their own bank details and the QR code starts working.
    run('INSERT INTO payment_profiles (name,recipient,currency,qr_template,note,created_at) VALUES (?,?,?,?,?,?)',
        ['Vereinskonto',setting('club_name'),'EUR',"BCD\n002\n1\nSCT\n{bic}\n{recipient}\n{iban}\n{currency}{amount}\n\n{reference}",'',now()]);
    set_setting('default_payment_profile',(int)db()->lastInsertId());
    set_setting('defaults_initialized',true);db()->commit();
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
