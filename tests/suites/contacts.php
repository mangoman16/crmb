<?php
/**
 * Two different questions about one child, which used to be one row.
 *
 * "Who do I ring when she falls over" and "who does the portal write to" are not
 * the same question, and the standard contact was being asked both. They came
 * apart in practice - the grandmother who should be rung has no email, the
 * father who reads the invoices is never in the hall - and the form could not
 * answer either without lying about the other.
 *
 * So: the emergency contacts are people to ring, and the address the portal
 * writes to is on the child. This suite holds that line, because the first
 * thing that will happen to it is somebody quietly making a contact's email
 * mean something again.
 */
$trainer = make_account(['role'=>'trainer']); sign_in_as($trainer);
$sid = make_student(['first_name'=>'Lena','last_name'=>'Hofer']);

case_('A child with nobody to ring says so, in words');
is_same(0, count(student_contacts($sid)), 'nothing there to begin with');
ok(str_contains(contact_gap($sid), 'Notfall-Kontaktperson'), 'the page has a sentence to show');
is_same([$sid], array_map(fn($s)=>(int)$s['id'], students_missing_contact()), 'and the list names the child');

case_('And a child with nowhere to write says that separately');
// Separately because they are filled in in two different places: one is a
// person with a phone number, the other is a box on the child's own record.
ok(in_array($sid, array_map(fn($s)=>(int)$s['id'], students_missing_email()), true), 'the child is on that list too');
act('contact_add', ['student_id'=>(string)$sid, 'owner_name'=>'Oma Hofer',
    'relation_label'=>'Großmutter', 'phone'=>'+43 660 1234567', 'email'=>'']);
is_same([], students_missing_contact(), 'a contact takes them off the first list');
ok(str_contains(contact_gap($sid), 'E-Mail-Adresse'), 'and the sentence moves on to the address');
run('UPDATE students SET email=? WHERE id=?', ['familie.hofer@beispiel.test', $sid]);
is_same('', contact_gap($sid), 'which fills the last gap');
is_same([], students_missing_email(), 'and clears the second list');

case_('An emergency contact needs a phone number, not an email address');
// The grandmother who is first on the list has never had an email address and
// does not need one: she needs to answer the telephone.
$other = make_student(['first_name'=>'Tobias']);
does_not_throw(fn() => act('contact_add', ['student_id'=>(string)$other, 'owner_name'=>'Opa Berger',
    'relation_label'=>'Großvater', 'phone'=>'+43 660 7654321', 'email'=>'']),
    'a contact with no email address is accepted');
is_same(1, (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=?', [$other]), 'and written down');
is_same(1, (int)primary_contact($other)['is_primary'], 'as the one to try first, without being asked');

case_('A second contact is an ordinary one until somebody says otherwise');
act('contact_add', ['student_id'=>(string)$sid, 'owner_name'=>'Maria Hofer',
    'relation_label'=>'Mutter', 'phone'=>'+43 660 1111111', 'email'=>'maria@beispiel.test']);
$contacts = student_contacts($sid);
is_same(2, count($contacts), 'two contacts');
is_same('Oma Hofer', $contacts[0]['owner_name'], 'the standard one is listed first');
is_same(1, (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=? AND is_primary=1', [$sid]),
        'and there is exactly one of it');

case_('Making another one the standard moves it rather than adding a second');
$maria = (int)scalar('SELECT id FROM contacts WHERE student_id=? AND owner_name=?', [$sid, 'Maria Hofer']);
act('contact_save', ['student_id'=>(string)$sid, 'id'=>(string)$maria, 'owner_name'=>'Maria Hofer',
    'relation_label'=>'Mutter', 'phone'=>'+43 660 1111111', 'email'=>'', 'is_primary'=>'1']);
is_same(1, (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=? AND is_primary=1', [$sid]),
        'still exactly one standard contact');
is_same('Maria Hofer', (string)primary_contact($sid)['owner_name'], 'and it is the new one');
is_same('', (string)primary_contact($sid)['email'], 'which needed no address to become it');

case_('Unticking the box does not leave the child without a standard contact');
act('contact_save', ['student_id'=>(string)$sid, 'id'=>(string)$maria, 'owner_name'=>'Maria Hofer',
    'relation_label'=>'Mutter', 'phone'=>'+43 660 1111111', 'email'=>'']);
is_same('Maria Hofer', (string)primary_contact($sid)['owner_name'], 'it stays the standard one');

case_('The last contact cannot be removed');
$oma = (int)scalar('SELECT id FROM contacts WHERE student_id=? AND owner_name=?', [$sid, 'Oma Hofer']);
act('contact_delete', ['student_id'=>(string)$sid, 'id'=>(string)$oma]);
is_same(1, count(student_contacts($sid)), 'the ordinary one goes');
throws(fn() => act('contact_delete', ['student_id'=>(string)$sid, 'id'=>(string)$maria]),
       'the last one does not', 'mindestens eine Kontaktperson');
is_same(1, count(student_contacts($sid)), 'and is still there');
// Which one, not how many. contact_delete promotes the next contact after it
// deletes, so a count of one would also describe a child left with the wrong
// person, or with the right person no longer marked as the standard.
is_same('Maria Hofer', (string)student_contacts($sid)[0]['owner_name'], 'and it is Maria, the one the refusal protected');
is_same(1, (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=? AND is_primary=1', [$sid]),
        'still marked as the standard, so the promotion that follows a delete did not half-run');

case_('Removing the standard contact promotes the next one');
act('contact_add', ['student_id'=>(string)$sid, 'owner_name'=>'Josef Hofer',
    'relation_label'=>'Vater', 'phone'=>'+43 660 2222222', 'email'=>'']);
act('contact_delete', ['student_id'=>(string)$sid, 'id'=>(string)$maria]);
is_same('Josef Hofer', (string)primary_contact($sid)['owner_name'], 'the one left over takes it on');
is_same(1, (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=? AND is_primary=1', [$sid]),
        'and is flagged as such, so the list agrees with the page');

case_('A record written before the rule existed still answers with somebody');
run('UPDATE contacts SET is_primary=0 WHERE student_id=?', [$sid]);
is_same('Josef Hofer', (string)primary_contact($sid)['owner_name'], 'the oldest row stands in');
run('UPDATE contacts SET is_primary=1 WHERE id=(SELECT MIN(id) FROM contacts WHERE student_id=?)', [$sid]);

case_('An ended membership is not chased for something it will never need');
$gone = make_student(['first_name'=>'Weg', 'status'=>'ended']);
is_same(false, in_array($gone, array_map(fn($s)=>(int)$s['id'], students_missing_contact()), true),
        'nobody is asked to fill in a record that is closed');
is_same(false, in_array($gone, array_map(fn($s)=>(int)$s['id'], students_missing_email()), true),
        'for either of the two things');

// ---------------------------------------------------------------------------
case_('An invoice goes to the child’s own address, not to the emergency list');
$recipient = invoice_recipient(student($sid));
is_same('familie.hofer@beispiel.test', $recipient['email'], 'the address is the one on the child');
is_same('Lena Hofer', $recipient['name'], 'addressed by the child’s name, there being no account yet');
is_same('Lena Hofer', $recipient['student'], 'and the child is named as the one trained');
// The grandfather on the emergency list has an address of his own on the other
// child; it must not be what the portal writes to.
run('UPDATE contacts SET email=? WHERE student_id=?', ['opa@beispiel.test', $sid]);
is_same('familie.hofer@beispiel.test', invoice_recipient(student($sid))['email'],
        'an address on a contact changes nothing, which is the whole point');

case_('An account of their own wins, because that is who signs in');
$family = make_account(['role'=>'student', 'name'=>'Familie Hofer', 'email'=>'familie@beispiel.test']);
run('UPDATE students SET account_id=? WHERE id=?', [$family, $sid]);
$recipient = invoice_recipient(student($sid));
is_same('familie@beispiel.test', $recipient['email'], 'the account’s address');
is_same('Familie Hofer', $recipient['name'], 'and the name on the account');
is_same($family, (int)$recipient['account_id'], 'and the account it belongs to');

// ---------------------------------------------------------------------------
case_('An invitation goes to the address on the student, never to a contact’s');
/* The rules about whose login is whose are in the accounts suite. What belongs
   here is the line this suite holds: the address the portal writes to is on the
   student, and an address on an emergency contact is not where anything goes. */
set_setting('smtp', ['host'=>'mail.example.test','port'=>587,'from_email'=>'portal@example.test','from_name'=>'B']);
set_setting('privacy_ready', true);
$first = make_student(['first_name'=>'Erstes','last_name'=>'Kind', 'email'=>'erstes-kind@beispiel.test']);
fixture('contacts', ['student_id'=>$first, 'owner_name'=>'Opa Kind', 'relation_label'=>'Großvater',
                     'phone'=>'+43 660 7654321', 'email'=>'opa-kind@beispiel.test', 'is_primary'=>1]);
act('student_invite', ['student_id'=>(string)$first, 'email'=>'', 'name'=>'']);
$account = one('SELECT * FROM accounts WHERE id=?', [(int)student($first)['account_id']]);
is_same('erstes-kind@beispiel.test', $account['email'] ?? null, 'with no address typed, the one on the student is used');
is_same('Erstes Kind', $account['name'] ?? null, 'and the student’s own name, because the login is theirs');
is_same(1, (int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE recipient=? AND category='security'", ['erstes-kind@beispiel.test']),
        'the invitation is queued to that address');
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs WHERE recipient=?', ['opa-kind@beispiel.test']),
        'and nothing to the grandfather on the emergency list');
set_setting('smtp', []);
set_setting('privacy_ready', false);

case_('Example data leaves no child without somebody to ring or somewhere to write');
$before = array_map(fn($s)=>(int)$s['id'], rows('SELECT id FROM students'));
demo_fill(true);   // there are already students here: these are the ones being looked at
$new = fn(array $rows) => array_values(array_diff(array_map(fn($s)=>(int)$s['id'], $rows), $before));
is_same([], $new(students_missing_contact()), 'every example child has an emergency contact');
is_same([], $new(students_missing_email()), 'and an address to write to');
demo_clear();

case_('And the pages say it, rather than leaving her to work it out');
$html = render_view('student', ['id'=>(string)$sid, 'tab'=>'contacts']);
ok(str_contains($html, 'Notfallkontakte'), 'the contacts tab says what the list is for');
ok(str_contains($html, 'Standardkontakt'), 'the one to try first is marked as such');
ok(str_contains($html, 'Kontakt hinzufügen'), 'and another can be added from there');
$profile = render_view('student', ['id'=>(string)$sid]);
ok(str_contains($profile, 'Zugang zum Portal'), 'the child’s own page is where the address lives');
$list = render_view('students');
ok(str_contains($list, 'ohne Notfallkontakt') || str_contains($list, 'ohne E-Mail-Adresse'),
   'the student list names what is still missing');
ok(str_contains($list, 'Tobias'), 'and who it is missing for');
