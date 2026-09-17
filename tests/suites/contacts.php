<?php
/**
 * Contact people: somebody to ring, and one of them is the one you ring first.
 *
 * The rule is not decoration. The standard contact is where an invoice and a
 * payment reminder go, and in an emergency it is the number the trainer reaches
 * for, so a child must always have one and it must always have an address.
 */
$trainer = make_account(['role'=>'trainer']); sign_in_as($trainer);
$sid = make_student(['first_name'=>'Lena','last_name'=>'Hofer']);

case_('A child with nobody to ring says so, in words');
is_same(0, count(student_contacts($sid)), 'nothing there to begin with');
ok(str_contains(contact_gap($sid), 'keine Kontaktperson'), 'the page has a sentence to show');
is_same([$sid], array_map(fn($s)=>(int)$s['id'], students_missing_contact()), 'and the list names the child');

case_('The first contact is the standard one without being asked');
act('contact_add', ['student_id'=>(string)$sid, 'owner_name'=>'Maria Hofer',
    'relation_label'=>'Mutter', 'phone'=>'+43 660 1234567', 'email'=>'maria@beispiel.test']);
$contacts = student_contacts($sid);
is_same(1, count($contacts), 'one contact');
is_same(1, (int)$contacts[0]['is_primary'], 'and it is the standard one');
is_same('', contact_gap($sid), 'nothing is missing any more');
is_same([], students_missing_contact(), 'and the child is off the list');

case_('The standard contact needs an address, because that is where invoices go');
$other = make_student(['first_name'=>'Tobias']);
throws(fn() => act('contact_add', ['student_id'=>(string)$other, 'owner_name'=>'Ohne Mail',
    'relation_label'=>'Vater', 'phone'=>'', 'email'=>'']),
    'the first contact is refused without one', 'E-Mail-Adresse');
is_same(0, (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=?', [$other]), 'and nothing was written');

case_('A second contact is an ordinary one until somebody says otherwise');
act('contact_add', ['student_id'=>(string)$sid, 'owner_name'=>'Josef Hofer',
    'relation_label'=>'Vater', 'phone'=>'+43 660 7654321', 'email'=>'']);
$contacts = student_contacts($sid);
is_same(2, count($contacts), 'two contacts');
is_same('Maria Hofer', $contacts[0]['owner_name'], 'the standard one is listed first');
is_same(1, (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=? AND is_primary=1', [$sid]),
        'and there is exactly one of it');

case_('Making another one the standard moves it rather than adding a second');
$josef = (int)scalar('SELECT id FROM contacts WHERE student_id=? AND owner_name=?', [$sid, 'Josef Hofer']);
throws(fn() => act('contact_save', ['student_id'=>(string)$sid, 'id'=>(string)$josef, 'owner_name'=>'Josef Hofer',
    'relation_label'=>'Vater', 'phone'=>'', 'email'=>'', 'is_primary'=>'1']),
    'not without an address of its own', 'E-Mail-Adresse');
act('contact_save', ['student_id'=>(string)$sid, 'id'=>(string)$josef, 'owner_name'=>'Josef Hofer',
    'relation_label'=>'Vater', 'phone'=>'', 'email'=>'josef@beispiel.test', 'is_primary'=>'1']);
is_same(1, (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=? AND is_primary=1', [$sid]),
        'still exactly one standard contact');
is_same('Josef Hofer', (string)primary_contact($sid)['owner_name'], 'and it is the new one');

case_('Unticking the box does not leave the child without a standard contact');
act('contact_save', ['student_id'=>(string)$sid, 'id'=>(string)$josef, 'owner_name'=>'Josef Hofer',
    'relation_label'=>'Vater', 'phone'=>'', 'email'=>'josef@beispiel.test']);
is_same('Josef Hofer', (string)primary_contact($sid)['owner_name'], 'it stays the standard one');

case_('The last contact cannot be removed');
$maria = (int)scalar('SELECT id FROM contacts WHERE student_id=? AND owner_name=?', [$sid, 'Maria Hofer']);
act('contact_delete', ['student_id'=>(string)$sid, 'id'=>(string)$maria]);
is_same(1, count(student_contacts($sid)), 'the ordinary one goes');
throws(fn() => act('contact_delete', ['student_id'=>(string)$sid, 'id'=>(string)$josef]),
       'the last one does not', 'mindestens eine Kontaktperson');
is_same(1, count(student_contacts($sid)), 'and is still there');

case_('Removing the standard contact promotes the next one');
act('contact_add', ['student_id'=>(string)$sid, 'owner_name'=>'Oma Hofer',
    'relation_label'=>'Großmutter', 'phone'=>'', 'email'=>'oma@beispiel.test']);
act('contact_delete', ['student_id'=>(string)$sid, 'id'=>(string)$josef]);
is_same('Oma Hofer', (string)primary_contact($sid)['owner_name'], 'the one left over takes it on');
is_same(1, (int)scalar('SELECT COUNT(*) FROM contacts WHERE student_id=? AND is_primary=1', [$sid]),
        'and is flagged as such, so the list agrees with the page');

case_('A standard contact without an address is a gap, not a pass');
run('UPDATE contacts SET email=? WHERE student_id=?', ['', $sid]);
ok(str_contains(contact_gap($sid), 'E-Mail'), 'the child is named as incomplete');
ok(in_array($sid, array_map(fn($s)=>(int)$s['id'], students_missing_contact()), true), 'and is back on the list');
run('UPDATE contacts SET email=? WHERE student_id=?', ['oma@beispiel.test', $sid]);

case_('A record written before the rule existed still answers with somebody');
run('UPDATE contacts SET is_primary=0 WHERE student_id=?', [$sid]);
is_same('Oma Hofer', (string)primary_contact($sid)['owner_name'], 'the oldest row stands in');
run('UPDATE contacts SET is_primary=1 WHERE id=(SELECT MIN(id) FROM contacts WHERE student_id=?)', [$sid]);

case_('An ended membership is not chased for a contact it will never need');
$gone = make_student(['first_name'=>'Weg', 'status'=>'ended']);
is_same(false, in_array($gone, array_map(fn($s)=>(int)$s['id'], students_missing_contact()), true),
        'nobody is asked to fill in a record that is closed');

case_('An invoice to a family with no portal account goes to the standard contact');
$recipient = invoice_recipient(student($sid));
is_same('oma@beispiel.test', $recipient['email'], 'the address is the standard contact’s');
is_same('Oma Hofer', $recipient['name'], 'and so is the name on the invoice');
is_same('Lena Hofer', $recipient['student'], 'the child is still named as the one trained');

case_('An account of their own still wins, because that is who signs in');
$family = make_account(['role'=>'student', 'name'=>'Familie Hofer', 'email'=>'familie@beispiel.test']);
run('UPDATE students SET account_id=? WHERE id=?', [$family, $sid]);
$recipient = invoice_recipient(student($sid));
is_same('familie@beispiel.test', $recipient['email'], 'the account’s address');
is_same($family, (int)$recipient['account_id'], 'and the account it belongs to');

case_('Example data leaves no child without somebody to ring');
$before = array_map(fn($s)=>(int)$s['id'], rows('SELECT id FROM students'));
demo_fill(true);   // there are already students here: these are the ones being looked at
$missing = array_map(fn($s)=>(int)$s['id'], students_missing_contact());
is_same([], array_values(array_diff($missing, $before)),
        'every example child has a standard contact with an address');
demo_clear();

case_('And the pages say it, rather than leaving her to work it out');
$html = render_view('student', ['id'=>(string)$sid, 'tab'=>'contacts']);
ok(str_contains($html, 'Standardkontakt'), 'the standard contact is marked as such on the child’s page');
ok(str_contains($html, 'Kontakt hinzufügen'), 'and another can be added from there');
$list = render_view('students');
ok(str_contains($list, 'ohne Standardkontakt'), 'the student list names how many are still missing one');
ok(str_contains($list, 'Tobias'), 'and who they are');
