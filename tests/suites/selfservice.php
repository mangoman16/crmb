<?php
/**
 * Families complete their own details, and she sees every change (ADR 0020, §7).
 *
 * A family signs in to their own student's page and fills in what she did not
 * ask for at creation: names, birth date, postal address, phone, emergency
 * contacts and the custom fields she marked „Ansehen und bearbeiten". Nothing
 * else, whatever is posted, and only on their own student. Every one of those
 * writes is tracked, so the change log names who changed what - which is the
 * undo: she reads the old value and types it back, and every field a family can
 * write, she can write too.
 */
$trainer = make_account(['role'=>'trainer', 'name'=>'Trainerin']);
$admin = make_account(['role'=>'admin', 'name'=>'Chefin']);
sign_in_as($trainer);

/** A custom field, as the settings page saves one. */
function make_field(string $label, string $visibility, bool $required, string $type = 'text', array $options = []): int {
    return fixture('field_definitions', ['label'=>$label, 'label_en'=>'', 'field_type'=>$type, 'section_name'=>'',
        'options_json'=>json_encode($options, JSON_UNESCAPED_UNICODE), 'default_json'=>json_encode($type === 'checkbox' ? false : ''),
        'required'=>$required ? 1 : 0, 'visibility'=>$visibility, 'sort_order'=>0, 'archived'=>0]);
}

// ---------------------------------------------------------------------------
case_('Creating a student is never refused by a required field, of any visibility');
/* save_custom_fields() was handed $new and never read it. The wizard carries
   no custom fields - "a form with twenty boxes is a form somebody abandons"
   (ADR 0023 §5) - so one field marked required must not refuse the creation of
   every student, whoever is to fill it in. */
$required = [];
foreach (['internal', 'view', 'edit'] as $visibility) {
    $required[$visibility] = make_field('Pflicht '.$visibility, $visibility, true);
    $before = (int)scalar('SELECT COUNT(*) FROM students');
    does_not_throw(fn() => create_through_wizard(['first_name'=>'Neu '.$visibility]), 'with a required field at '.$visibility.', a student is created');
    is_same($before + 1, (int)scalar('SELECT COUNT(*) FROM students'), 'and is really there');
}

// ---------------------------------------------------------------------------
$familyLogin = make_account(['role'=>'student', 'name'=>'Lena Hofer', 'email'=>'lena@beispiel.test']);
$lena = make_student(['first_name'=>'Lena', 'last_name'=>'Hofer', 'email'=>'lena@beispiel.test', 'account_id'=>$familyLogin,
                      'status'=>'active', 'internal_notes'=>'Nur für uns', 'price_cents'=>4500]);
$neighbour = make_student(['first_name'=>'Nachbar', 'last_name'=>'Kind', 'account_id'=>make_account(['role'=>'student'])]);
$shirt = make_field('T-Shirt-Größe', 'edit', false, 'select', ['S', 'M', 'L']);
$photos = make_field('Fotos erlaubt', 'edit', false, 'checkbox');
$seen = make_field('Gurtfarbe', 'view', false);
$secret = make_field('Interne Einschätzung', 'internal', false);
foreach ($required as $field) run('UPDATE field_definitions SET archived=1 WHERE id=?', [$field]);
fixture('field_values', ['student_id'=>$lena, 'field_id'=>$seen, 'value_json'=>'"blau"']);
fixture('field_values', ['student_id'=>$lena, 'field_id'=>$secret, 'value_json'=>'"talentiert"']);

/** Save Lena as her page posts it, from what is stored, with some fields changed. */
$familySave = function (array $changes = []) use ($lena): array {
    $s = one('SELECT * FROM students WHERE id=?', [$lena]);
    return act('student_save', $changes + ['id'=>(string)$lena, 'revision'=>(string)$s['revision'],
        'first_name'=>$s['first_name'], 'last_name'=>$s['last_name'], 'birth_date'=>(string)($s['birth_date'] ?? ''),
        'address'=>(string)$s['address'], 'phone'=>(string)$s['phone']]);
};

case_('A family saves names, birth date, address, phone and its own fields as one tracked change');
sign_in_as($familyLogin);
run('DELETE FROM record_versions');
$familySave(['first_name'=>'Lena-Marie', 'birth_date'=>'2014-05-06', 'address'=>'Hauptstraße 5, 4020 Linz', 'phone'=>'+43 660 1234567',
             'custom'=>[$shirt=>'M', $photos=>'1']]);
$row = one('SELECT * FROM students WHERE id=?', [$lena]);
is_same(['Lena-Marie', '2014-05-06', 'Hauptstraße 5, 4020 Linz', '+43 660 1234567'], [$row['first_name'], $row['birth_date'], $row['address'], $row['phone']],
        'the five columns are written');
is_same(['M', true], [field_value($lena, $shirt), field_value($lena, $photos)], 'and so are the fields at „Ansehen und bearbeiten“');
$versions = history_for('students', $lena);
is_same(1, count($versions), 'one save is one line in the change log');
is_same($familyLogin, (int)$versions[0]['actor_id'], 'with the family as the actor');
is_same('Deine Angaben sind gespeichert.', $_SESSION['flash']['message'] ?? null, 'and the family is told their details are saved, in their words');
// Sorted: what is checked is which fields changed, not the order of the
// table's columns, which is the migrations' business.
$named = array_keys(version_changes($versions[0])); sort($named);
$expected = ['address', 'birth_date', 'field:'.$shirt, 'field:'.$photos, 'first_name', 'phone']; sort($expected);
is_same($expected, $named, 'naming each field that changed, the custom ones included');

case_('Nothing else a family posts is written');
run('DELETE FROM record_versions');
$before = one('SELECT * FROM students WHERE id=?', [$lena]);
$familySave(['status'=>'ended', 'joined_on'=>'2000-01-01', 'ended_on'=>'2000-02-02', 'level_id'=>'99', 'age_group_id'=>'1',
             'internal_notes'=>'überschrieben', 'email'=>'anders@beispiel.test', 'account_id'=>'1', 'tariff_id'=>'1', 'price_cents'=>'1',
             'custom'=>[$seen=>'rot', $secret=>'gar nichts', $shirt=>'M', $photos=>'1']]);
$after = one('SELECT * FROM students WHERE id=?', [$lena]);
foreach (['status', 'joined_on', 'ended_on', 'level_id', 'age_group_id', 'internal_notes', 'email', 'account_id', 'tariff_id', 'price_cents'] as $column)
    is_same($before[$column], $after[$column], 'a posted '.$column.' changes nothing');
is_same(['blau', 'talentiert'], [field_value($lena, $seen), field_value($lena, $secret)], 'nor does a field at „Ansehen“ or „Nur intern“');
is_same([], version_changes(history_for('students', $lena)[0] ?? ['before_json'=>null, 'after_json'=>null, 'entity'=>'students']),
        'so the log has nothing to say about it');

case_('A page that does not post the address and phone leaves them as they are');
/* A family's page opened before this update shows neither box, and the student
   page did not show them to families until ADR 0020. A save from such a page
   must not blank what the trainer typed: nothing posted is nothing to change. */
run("UPDATE students SET address='Getippt von ihr 1', phone='+43 1 234' WHERE id=?", [$lena]);
$s = one('SELECT * FROM students WHERE id=?', [$lena]);
act('student_save', ['id'=>(string)$lena, 'revision'=>(string)$s['revision'], 'first_name'=>$s['first_name'], 'last_name'=>$s['last_name'],
    'birth_date'=>(string)$s['birth_date'], 'custom'=>[$shirt=>'M', $photos=>'1']]);
is_same(['Getippt von ihr 1', '+43 1 234'], array_values(one('SELECT address,phone FROM students WHERE id=?', [$lena])), 'both are kept');
$familySave(['address'=>'', 'phone'=>'', 'custom'=>[$shirt=>'M', $photos=>'1']]);
is_same(['', ''], array_values(one('SELECT address,phone FROM students WHERE id=?', [$lena])), 'while empty boxes that were posted do empty them');
run("UPDATE students SET address='Hauptstraße 5, 4020 Linz', phone='+43 660 1234567' WHERE id=?", [$lena]);

case_('A family reaches its own student only');
throws(fn() => act('student_save', ['id'=>(string)$neighbour, 'revision'=>'1', 'first_name'=>'X', 'last_name'=>'Y', 'birth_date'=>'']),
       'another student’s id is not found', 'nicht gefunden');
$students = (int)scalar('SELECT COUNT(*) FROM students');
throws(fn() => act('student_save', ['first_name'=>'Neu', 'last_name'=>'Kind']), 'and a family creates no student: without one there is none to find', 'nicht gefunden');
is_same($students, (int)scalar('SELECT COUNT(*) FROM students'), 'none is written');
throws(fn() => act('contact_add', ['student_id'=>(string)$neighbour, 'owner_name'=>'X', 'relation_label'=>'Y', 'phone'=>'', 'email'=>'']),
       'nor adds a contact to somebody else’s', 'nicht gefunden');

case_('A stale form is refused as a whole, its fields included');
$stale = (int)scalar('SELECT revision FROM students WHERE id=?', [$lena]) - 1;
throws(fn() => $familySave(['revision'=>(string)$stale, 'phone'=>'0', 'custom'=>[$shirt=>'L']]), 'an old revision is refused, without asking a family to compare changes it cannot see',
       'Inzwischen hat jemand anderer etwas an diesem Profil gespeichert. Deine Eingaben sind noch da – bitte prüfen und noch einmal speichern.');
is_same('M', field_value($lena, $shirt), 'and its custom field was not written either');

case_('A required field is required of whoever fills it in');
$mustFamily = make_field('Allergien', 'edit', true);
$mustStaff = make_field('Mitgliedsnummer', 'internal', true);
throws(fn() => $familySave(), 'a required field at „Ansehen und bearbeiten“ refuses the family’s save while empty', 'Pflichtfeld: Allergien');
does_not_throw(fn() => $familySave(['custom'=>[$mustFamily=>'keine', $shirt=>'M', $photos=>'1']]), 'and lets it through once filled in');
throws(fn() => $familySave(['custom'=>[$mustFamily=>'keine', $shirt=>'XXL', $photos=>'1']]), 'a value not offered is refused for a family too', 'Ungültige Option');
sign_in_as($trainer);
$staffSave = function (array $changes = []) use ($lena): array {
    $s = one('SELECT * FROM students WHERE id=?', [$lena]);
    return act('student_save', $changes + ['id'=>(string)$lena, 'revision'=>(string)$s['revision'], 'first_name'=>$s['first_name'],
        'last_name'=>$s['last_name'], 'email'=>(string)$s['email'], 'birth_date'=>(string)($s['birth_date'] ?? ''), 'joined_on'=>(string)$s['joined_on'],
        'ended_on'=>'', 'status'=>$s['status'], 'internal_notes'=>(string)$s['internal_notes'], 'address'=>(string)$s['address'], 'phone'=>(string)$s['phone']]);
};
throws(fn() => $staffSave(['custom'=>[$seen=>'blau', $secret=>'x']]), 'a required internal field refuses staff’s save of an existing student', 'Pflichtfeld: Mitgliedsnummer');
does_not_throw(fn() => $staffSave(['custom'=>[$seen=>'blau', $secret=>'x', $mustStaff=>'A-17']]),
               'while an empty required field of the family’s never refuses staff’s');
is_same('', field_value($lena, $mustFamily), 'she left it empty, and may');
throws(fn() => $staffSave(['custom'=>[$mustStaff=>'A-17', 'abc'=>'x', $shirt=>'XXL']]), 'the type checks hold for staff too', 'Ungültige Option');

case_('Whom a required field is required of is answered in one place');
/* Code review 2: the save, the family's list and the student page each spelled
   it out; three copies of one rule are two chances to disagree. */
$of = fn(string $visibility, bool $required, bool $staff) => custom_field_required_of(['visibility'=>$visibility, 'required'=>$required ? 1 : 0], $staff);
is_same([true, false], [$of('edit', true, false), $of('edit', true, true)], 'a required field at „Ansehen und bearbeiten“ is the family’s, not staff’s');
is_same([true, true, false, false], [$of('view', true, true), $of('internal', true, true), $of('view', true, false), $of('internal', true, false)],
        'one at „Ansehen“ or „Nur intern“ is staff’s, never the family’s');
is_same([false, false, false], [$of('edit', false, false), $of('view', false, true), $of('internal', false, true)], 'and a field not marked required is nobody’s');

case_('Staff can write every column and field a family can: typing the old value back is the undo');
$staffSave(['first_name'=>'Lena', 'birth_date'=>'2014-05-07', 'address'=>'Alte Gasse 1, 4020 Linz', 'phone'=>'',
            'custom'=>[$mustStaff=>'A-17', $shirt=>'S', $photos=>'', $mustFamily=>'Nüsse', $seen=>'grün']]);
$row = one('SELECT * FROM students WHERE id=?', [$lena]);
is_same(['Lena', '2014-05-07', 'Alte Gasse 1, 4020 Linz', ''], [$row['first_name'], $row['birth_date'], $row['address'], $row['phone']], 'names, birth date, address, phone');
is_same(['S', false, 'Nüsse', 'grün'], [field_value($lena, $shirt), field_value($lena, $photos), field_value($lena, $mustFamily), field_value($lena, $seen)],
        'and every custom field, the family’s included');
is_same($trainer, (int)history_for('students', $lena)[0]['actor_id'], 'tracked under her name');

case_('Contacts are tracked, labelled with the child and the contact, and scoped');
sign_in_as($familyLogin);
run('DELETE FROM record_versions');
act('contact_add', ['student_id'=>(string)$lena, 'owner_name'=>'Oma Hofer', 'relation_label'=>'Großmutter', 'phone'=>'+43 660 1', 'email'=>'']);
act('contact_add', ['student_id'=>(string)$lena, 'owner_name'=>'Papa Hofer', 'relation_label'=>'Vater', 'phone'=>'+43 660 2', 'email'=>'']);
$oma = (int)scalar("SELECT id FROM contacts WHERE owner_name='Oma Hofer'");
$papa = (int)scalar("SELECT id FROM contacts WHERE owner_name='Papa Hofer'");
act('contact_save', ['student_id'=>(string)$lena, 'id'=>(string)$papa, 'owner_name'=>'Papa Hofer', 'relation_label'=>'Vater', 'phone'=>'+43 660 3', 'email'=>'', 'is_primary'=>'1']);
act('contact_delete', ['student_id'=>(string)$lena, 'id'=>(string)$oma]);
is_same('Entfernt: Oma Hofer (Großmutter), +43 660 1. Aus Versehen? Trag die Person unten unter „Notfallkontakt hinzufügen“ wieder ein.',
        $_SESSION['flash']['message'] ?? null, 'removing a contact says exactly who went, which is the family’s way back');
$lines = rows("SELECT * FROM record_versions WHERE entity='contacts' ORDER BY id");
act('contact_save', ['student_id'=>(string)$lena, 'id'=>(string)$papa, 'owner_name'=>'Papa Hofer', 'relation_label'=>'Vater', 'phone'=>'+43 660 4', 'email'=>'']);
is_same(['phone'], array_keys(version_changes(rows("SELECT * FROM record_versions WHERE entity='contacts' ORDER BY id DESC LIMIT 1")[0])),
        'saving the standard contact’s phone logs the phone alone, not a standard taken away and given back');
is_same(['insert', 'insert', 'update', 'delete'], array_column($lines, 'operation'), 'adding, changing and removing are each one line');
is_same(['Lena Hofer · Oma Hofer', 'Lena Hofer · Papa Hofer'], array_values(array_unique(array_column($lines, 'label'))), 'named for the child and the contact');
is_same([$familyLogin], array_values(array_unique(array_map('intval', array_column($lines, 'actor_id')))), 'with the family as the actor');
is_same(['phone', 'is_primary'], array_keys(version_changes($lines[2])), 'the change says what changed, the standard included');
is_same('Oma Hofer', json_decode((string)$lines[3]['before_json'], true)['owner_name'] ?? null, 'and the removal keeps the whole row, to add it back from');
is_same(0, (int)scalar("SELECT COUNT(*) FROM record_versions WHERE entity='contacts' AND entity_id=? AND operation='update'", [$oma]),
        'moving the standard off another contact is bookkeeping, not a line of its own');
throws(fn() => act('contact_save', ['student_id'=>(string)$neighbour, 'id'=>(string)$papa, 'owner_name'=>'X', 'relation_label'=>'Y', 'phone'=>'', 'email'=>'']),
       'another family’s student is not found', 'nicht gefunden');
throws(fn() => act('contact_delete', ['student_id'=>(string)$lena, 'id'=>(string)fixture('contacts', ['student_id'=>$neighbour, 'owner_name'=>'Fremd',
       'relation_label'=>'X', 'phone'=>'', 'email'=>'', 'is_primary'=>1])]), 'and another child’s contact is not found through one’s own', 'Kontakt nicht gefunden');

case_('The family is shown what is still missing, and not the phone');
$fresh = make_student(['first_name'=>'Frisch', 'last_name'=>'Da', 'account_id'=>make_account(['role'=>'student'])]);
make_enrolment(make_class(), $fresh);   // in a course, so the course step is not what this case is about
$mustPhoto = make_field('Hausordnung gelesen', 'edit', true, 'checkbox');
$what = fn() => array_column(family_next_steps($fresh), 'what');
is_same(['Notfallkontakt eintragen', 'Geburtsdatum eintragen', 'Anschrift eintragen', 'Allergien', 'Hausordnung gelesen'], $what(),
        'somebody to ring first, then birth date and address, then every required field of theirs that is empty, by its label alone');
is_same([['Wen die Trainerin anruft, wenn im Training etwas passiert.', 'contacts', 'add-contact'], ['Danach richtet sich die Altersgruppe.', null, 'birth-date'],
         ['Sie steht auf deinen Rechnungen.', null, 'address'], ['Bitte ausfüllen – deine Trainerin bittet darum.', null, 'field-'.$mustFamily]],
        array_map(fn($s) => [$s['why'], $s['params']['tab'] ?? null, $s['anchor']], array_slice(family_next_steps($fresh), 0, 4)),
        'each with the line the designer wrote, and the anchor the page has');
ok(!in_array('Telefonnummer eintragen', $what(), true), 'never the phone, which a child may not have');
ok(!str_contains(implode(' ', $what()), 'Mitgliedsnummer'), 'nor a required field that is staff’s to fill in');
run("UPDATE students SET birth_date='2015-01-01', address='Weg 1' WHERE id=?", [$fresh]);
fixture('contacts', ['student_id'=>$fresh, 'owner_name'=>'Mama', 'relation_label'=>'Mutter', 'phone'=>'1', 'email'=>'', 'is_primary'=>1]);
fixture('field_values', ['student_id'=>$fresh, 'field_id'=>$mustFamily, 'value_json'=>'"keine"']);
fixture('field_values', ['student_id'=>$fresh, 'field_id'=>$mustPhoto, 'value_json'=>'false']);
is_same(['Hausordnung gelesen'], $what(), 'an unticked box counts as not filled in, the same rule the save refuses by');
run('UPDATE field_values SET value_json=? WHERE student_id=? AND field_id=?', ['true', $fresh, $mustPhoto]);
is_same([], family_next_steps($fresh), 'and once everything is there, the list is empty');
run('UPDATE field_definitions SET archived=1 WHERE id IN (?,?)', [$mustPhoto, $mustFamily]);

case_('An address of spaces is no address, for the list and for the invoice alike');
is_same([true, true, false, true], [postal_address_missing(['address'=>'']), postal_address_missing(['address'=>"  \t "]),
                                     postal_address_missing(['address'=>'Weg 1']), postal_address_missing([])],
        'empty, blank or absent is missing; anything else is an address');

// ---------------------------------------------------------------------------
case_('An issued invoice keeps the address it was issued with; the next one has the new one');
/* ADR 0020, §7: the family writes the address its invoices are made out to.
   create_invoice() freezes it into snapshot_json, and every download and every
   mailed copy is built from that. */
sign_in_as($trainer);
foreach (['org_name'=>'Badmintonschule Hofer', 'org_street'=>'Turnweg 3', 'org_zip'=>'4020', 'org_city'=>'Linz',
          'org_country'=>'Österreich', 'org_email'=>'kontakt@beispiel.test', 'org_tax_mode'=>'small'] as $key => $value) set_setting($key, $value);
run('UPDATE payment_profiles SET iban=? WHERE id=?', ['AT055100080513176900', (int)setting('default_payment_profile')]);
payment_cache_clear();
$charge = fn(int $cents) => fixture('charges', ['student_id'=>$lena, 'label'=>'Beitrag', 'amount_cents'=>$cents, 'gross_cents'=>$cents,
    'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>'2026-09-01', 'period_to'=>'2026-09-30', 'due_on'=>'2026-09-01',
    'overdue_on'=>'2026-09-08', 'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()]);
$first = create_invoice($lena, [$charge(4500)]);
sign_in_as($familyLogin);
$familySave(['address'=>'Neue Straße 9, 1010 Wien', 'custom'=>[$shirt=>'S']]);
sign_in_as($trainer);
$issued = invoice($first);
ok(str_contains(invoice_pdf($issued), 'Alte Gasse 1, 4020 Linz') && !str_contains(invoice_pdf($issued), 'Neue Stra'),
   'the invoice issued before the move still downloads with the old address');
ok(str_contains((string)(mail_attachment(['kind'=>'invoice', 'id'=>$first])['body'] ?? ''), 'Alte Gasse 1, 4020 Linz'), 'and is mailed with it');
$next = invoice(create_invoice($lena, [$charge(4500)]));
ok(str_contains(invoice_pdf($next), 'Neue Stra'), 'the next one has the new address');
sign_in_as($familyLogin);
$familySave(['address'=>'', 'custom'=>[$shirt=>'S']]);
sign_in_as($trainer);
throws(fn() => create_invoice($lena, [$charge(45000)]), 'with the address emptied, an invoice above 400 € is refused', 'Anschrift');
does_not_throw(fn() => create_invoice($lena, [$charge(4500)]), 'and one below is issued');

// ---------------------------------------------------------------------------
case_('A family in no course is asked to choose one first, until they are in one or have asked (ADR 0021, §3)');
/* Spec S4. Joining bills, so choosing is a request the trainer approves; the
   step goes as soon as the request is sent, and the trainer's list says to
   answer it instead of to put them in a course. */
sign_in_as($trainer);
run('UPDATE classes SET archived=1');
$newcomer = make_student(['first_name'=>'Neu', 'last_name'=>'Hier', 'account_id'=>make_account(['role'=>'student'])]);
$first = fn(int $id) => family_next_steps($id)[0] ?? [];
$course = make_class(['name'=>'Montagstraining', 'capacity'=>1]);
is_same(['Kurs wählen', 'Such dir einen Kurs aus. Deine Trainerin bestätigt die Anmeldung.', ['id'=>$newcomer, 'tab'=>'classes'], 'add-course'],
        [$first($newcomer)['what'] ?? null, $first($newcomer)['why'] ?? null, $first($newcomer)['params'] ?? null, $first($newcomer)['anchor'] ?? null],
        'the first step, pointing at the courses with a free place');
make_enrolment($course, make_student(['first_name'=>'Schon', 'last_name'=>'Drin']));
is_same('courses', $first($newcomer)['anchor'] ?? null, 'with every course full, it points at the Kurse card, which says so');
run("UPDATE students SET status='ended' WHERE id=?", [$newcomer]);
ok(!in_array('Kurs wählen', array_column(family_next_steps($newcomer), 'what'), true), 'never for a membership that has ended');
run("UPDATE students SET status='active' WHERE id=?", [$newcomer]);
$open = make_class(['name'=>'Mittwochstraining']);
ok(in_array('In einen Kurs eintragen', array_column(student_next_steps($newcomer), 'what'), true), 'staff are told to put them in a course');
request_enrolment($newcomer, $open, 'join', null, '');
ok(!in_array('Kurs wählen', array_column(family_next_steps($newcomer), 'what'), true), 'once they have asked, the family is not asked again');
$staffSteps = array_column(student_next_steps($newcomer), null, 'what');
ok(!isset($staffSteps['In einen Kurs eintragen']), 'and staff are not told to put them in a course');
is_same(['Neu möchte in „Mittwochstraining“.', 'requests'],
        [$staffSteps['Kursanfrage beantworten']['why'] ?? null, $staffSteps['Kursanfrage beantworten']['anchor'] ?? null],
        'but to answer the request, naming the course, at the requests card');
run("UPDATE enrolment_requests SET state='declined' WHERE student_id=?", [$newcomer]);
is_same('Kurs wählen', $first($newcomer)['what'] ?? null, 'a request declined brings the step back');
make_enrolment($open, $newcomer);
ok(!in_array('Kurs wählen', array_column(family_next_steps($newcomer), 'what'), true), 'and being in a course ends it');
make_enrolment($course, $newcomer, ['left_on'=>'2025-06-30']);
run('DELETE FROM class_students WHERE class_id=? AND student_id=?', [$open, $newcomer]);
is_same('Kurs wählen', $first($newcomer)['what'] ?? null, 'a course they have left does not count');
