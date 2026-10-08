<?php
declare(strict_types=1);

/**
 * The runner's step after the migration files, in two parts that answer two
 * different questions.
 *
 * The first part runs on every schema update rather than only on a fresh
 * install, and brings to a portal that already exists what a release needs and
 * a migration file cannot give it. First the lists the application cannot work
 * without: a file would seed one once, and the operator may have emptied the
 * table since. Then what needs the application's own code - a hash, a login, a
 * course's group, a file deleted. Each step does only what it finds still to
 * do, so it can run on every update.
 *
 * The second part, below the guard, is the examples a new portal starts with.
 * Those run once: re-creating a payment profile the operator deleted would be
 * the software arguing with them.
 */

// Levels: a child is always in one, so there is always one to be in.
if(!(int)scalar('SELECT COUNT(*) FROM levels')) {
    foreach([['Anfänger','Lernt die Grundschläge.',10,1],
             ['Fortgeschritten','Spielt sicher im Training.',20,0],
             ['Könner','Spielt Turniere oder Ligaspiele.',30,0]] as [$name,$about,$order,$isDefault])
        run('INSERT INTO levels (name,description,sort_order,is_default,created_at) VALUES (?,?,?,?,?)',
            [$name,$about,$order,$isDefault,now()]);
}
// Age groups: the bands a new portal starts with, covering every age with no gap.
if(!(int)scalar('SELECT COUNT(*) FROM age_groups')) {
    foreach([['Unter 12',0,11,10],['Jugend',12,17,20],['Erwachsene',18,null,30]] as [$name,$min,$max,$order])
        run('INSERT INTO age_groups (name,min_age,max_age,sort_order,created_at) VALUES (?,?,?,?,?)',
            [$name,$min,$max,$order,now()]);
}
// Students written before levels existed point at nothing. They start where a
// new child starts; only rows that have never been given one are touched.
run('UPDATE students SET level_id=(SELECT id FROM levels WHERE is_default=1 ORDER BY id LIMIT 1) WHERE level_id IS NULL');

// The hash a refused sign-in is checked against, made once and brought to
// today's PASSWORD_DEFAULT cost after a PHP upgrade [M2, R9]. Here and in the
// nightly prune, because a sign-in that hashed would give away by how long it
// took which addresses have a login.
refresh_sign_in_dummy_hash();

// Courses made before migration 025 get their group chat here; a new course
// gets one as it is saved (ADR 0022).
course_groups_fill();

// Students made before migration 028 get a login here: a placeholder nobody can
// sign in with until staff give it an address or a username. A new student gets
// one as it is made, and the key 030 adds keeps it (ADR 0023 §4). After the
// files, never in one of them: nothing in SQL ties a new login to its student.
give_every_student_a_login();

// Profile pictures went with 035 and 036 (ADR 0026 §8), and a child's photo must
// not stay on the server once nothing shows it. Since then no column names
// them, so they are uploads left behind, which prune_uploads() deletes by its
// one rule for every kind. Called here so that they go with the update rather
// than at the nightly prune, which needs the background work to run. Ten
// minutes of grace rather than the prune's hour: the pictures were stored
// beside the problem reports' screenshots, and a screenshot that young may
// belong to a report another request is still saving.
// ponytail: a picture saved in the ten minutes before the update stays until
// the next nightly prune. Naming the pictures before 035 drops their column
// would need a step between two migrations, which the runner does not have.
prune_uploads(600);

if(setting('defaults_initialized',false))return;
db()->beginTransaction();
try{
    set_setting('club_name','Badminton');set_setting('default_status','active');
    set_setting('statuses',['trial'=>'Probetraining','active'=>'Aktiv','paused'=>'Pausiert','ended'=>'Beendet']);
    set_setting('absence_reasons',['sick'=>'Krank','holiday'=>'Urlaub','other'=>'Abwesend']);
    set_setting('payment_methods',['Überweisung','Bar']);set_setting('privacy_ready',false);
    set_setting('privacy_de',file_get_contents(ROOT.'/docs/privacy-draft-de.txt'));
    set_setting('privacy_en',file_get_contents(ROOT.'/docs/privacy-draft-en.txt'));
    // An empty profile with the SEPA payload already in place: the operator fills
    // in their own bank details and the QR code starts working.
    run('INSERT INTO payment_profiles (name,recipient,currency,qr_template,note,created_at) VALUES (?,?,?,?,?,?)',
        ['Vereinskonto',setting('club_name'),'EUR',"BCD\n002\n1\nSCT\n{bic}\n{recipient}\n{iban}\n{currency}{amount}\n\n{reference}",'',now()]);
    set_setting('default_payment_profile',(int)db()->lastInsertId());
    set_setting('defaults_initialized',true);db()->commit();
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
