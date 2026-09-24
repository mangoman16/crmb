<?php
/**
 * The two things that get printed and handed to a family.
 *
 * A blank registration form, for a parent standing in the hall with a biro, and
 * a filled sheet for a child whose details the trainer has already typed in
 * herself - so the family can check them, sign, and keep a copy. Both are the
 * same layout on purpose: what is asked for on paper is exactly what the portal
 * stores, so nothing is collected that has nowhere to go and nothing is stored
 * that was never asked for.
 *
 * Printed from the browser rather than produced as a PDF. A PDF would need the
 * invoice generator, which exists for a document that has to look the same in
 * ten years; this one has to come out of whatever printer is in the club room.
 */
require_staff();
$id = (int)($_GET['id'] ?? 0);
$student = $id ? student($id) : null;
$fields = array_filter(field_definitions(), fn($f) => $f['visibility'] !== 'internal');
$contacts = $student ? student_contacts($id) : [];
// Two blank contact blocks on an empty form, because a child with one person to
// ring is a child nobody can reach when that person is at work.
$contactBlocks = $student ? max(count($contacts), 1) : 2;
$club = (string)setting('club_name', 'Badminton');
// A blank form is filled in by whoever is joining, and half a club's members are
// adults: "Kind" over the first block and "Unterschrift der erziehungsberechtigten
// Person" underneath told an adult the form was not meant for them. A filled
// sheet knows the age; a blank one cannot, so it says both and lets the minor's
// guardian sign where it applies - which is how the club's own form words it.
$minor = $student ? (($age = student_age($student['birth_date'] ?? null)) !== null && $age < 18) : null;
?>
<div class="page-heading no-print">
    <div>
        <h1><?=e($student ? t('Datenblatt', 'Data sheet') : t('Anmeldeformular', 'Registration form'))?></h1>
        <p class="muted"><?=e($student
            ? t('Zum Ausdrucken und Mitgeben: was im Portal steht, zum Prüfen und Unterschreiben.', 'To print and hand over: what the portal holds, to be checked and signed.')
            : t('Zum Ausdrucken und Ausfüllen. Ein Buchstabe je Kästchen, in Blockschrift.', 'To print and fill in. One letter per box, in block capitals.'))?></p>
    </div>
    <?=link_button(t('Drucken','Print'), 'print', $student ? ['id'=>$id] : [], 'secondary')?>
</div>
<p class="muted no-print"><?=e(t('Im Druckdialog des Browsers drucken (Strg+P bzw. auf dem iPhone „Teilen → Drucken“). Kopf- und Fußzeilen im Druckdialog abschalten, dann passt es auf eine Seite.','Print from the browser (Ctrl+P, or Share → Print on an iPhone). Turn off headers and footers in the print dialog and it fits on one page.'))?></p>

<article class="sheet">
    <header class="sheet-head">
        <div>
            <strong><?=e($club)?></strong>
            <p><?=e($student ? t('Datenblatt', 'Data sheet') : t('Anmeldung zum Training', 'Registration for training'))?></p>
        </div>
        <div class="sheet-date">
            <?php if($student): ?><small><?=e(t('Stand: ', 'As of ').fmt_date(today()))?></small>
            <?php else: ?><?php print_field(t('Datum','Date'), 10); ?><?php endif ?>
        </div>
    </header>

    <?php if(!$student): ?>
    <p class="sheet-intro"><?=e(t('Bitte in Blockschrift ausfüllen, ein Buchstabe je Kästchen. Die Angaben werden nur intern verwendet, nicht verkauft und nicht weitergegeben.','Please fill in in block capitals, one letter per box. The details are used inside the club only, never sold and never passed on.'))?></p>
    <?php endif ?>

    <section class="sheet-block">
        <h2><?=e($minor === false ? t('Mitglied','Member') : t('Mitglied / Kind','Member / child'))?></h2>
        <?php
        print_field(t('Vorname','First name'), 17, (string)($student['first_name'] ?? ''));
        print_field(t('Nachname','Last name'), 17, (string)($student['last_name'] ?? ''));
        print_field(t('Geburtsdatum','Date of birth'), 10,
                    $student ? ($student['birth_date'] ? fmt_date($student['birth_date']) : '') : '',
                    t('TT.MM.JJJJ','DD.MM.YYYY'));
        // Its own row: an address is longer than a name, and one broken over
        // two rows of boxes is one nobody can read back.
        print_field(t('E-Mail-Adresse für das Portal','Email address for the portal'), 34,
                    $student ? student_email($student) : '',
                    t('Bei einem Kind die Adresse eines Elternteils','For a child, a parent’s address'), true);
        // Asked for on paper by every club form and, above 400 €, by § 11 UStG.
        print_field(t('Anschrift','Postal address'), 34, (string)($student['address'] ?? ''),
                    t('Straße, PLZ und Ort','Street, postcode and town'), true);
        print_field(t('Telefonnummer','Telephone number'), 17, (string)($student['phone'] ?? ''));
        ?>
    </section>

    <section class="sheet-block">
        <h2><?=e(t('Notfallkontakte','Emergency contacts'))?></h2>
        <p class="sheet-note"><?=e(t('Wen rufen wir an, wenn etwas ist?','Who do we ring if something happens?'))?></p>
        <?php for($n = 0; $n < $contactBlocks; $n++): $c = $contacts[$n] ?? null; ?>
        <div class="sheet-contact">
            <?php
            print_field(t('Name','Name'), 17, (string)($c['owner_name'] ?? ''));
            print_field(t('Beziehung','Relationship'), 14, (string)($c['relation_label'] ?? ''));
            print_field(t('Telefonnummer','Phone number'), 17, (string)($c['phone'] ?? ''), '', true);
            ?>
        </div>
        <?php endfor ?>
    </section>

    <?php /* What it costs, as a choice to tick. A club's anmeldeformular has this
             block - "Jährliche Zahlung: 252 €, Halbjährliche Zahlung: 162 €" -
             and without it a parent standing in the hall has filled in a form
             that never says what they are agreeing to pay. Taken from the price
             list so the paper cannot drift from the portal. */
    $priced = [];
    foreach ($student ? [] : training_classes() as $course) {
        $tariffs = class_tariffs((int)$course['id']);
        if ($tariffs) $priced[(string)$course['name']] = $tariffs;
    }
    $enrolled = $student ? array_filter(student_enrolments($id), fn($r) => $r['left_on'] === null) : [];
    if($priced || $enrolled): ?>
    <section class="sheet-block">
        <h2><?=e(t('Beitrag','Fee'))?></h2>
        <?php if($student): foreach($enrolled as $row):
            print_field($row['class_name'], 24, enrolment_summary($row));
        endforeach; else: ?>
        <p class="sheet-note"><?=e(t('Bitte ankreuzen, was gewählt wird.','Please tick what is being chosen.'))?></p>
        <?php foreach($priced as $courseName => $tariffs): ?>
            <?php if(count($priced) > 1): ?><h3 class="sheet-sub"><?=e($courseName)?></h3><?php endif ?>
            <div class="sheet-ticks"><?php
            // The course name once above the list rather than on every line: with
            // four tariffs it was four repetitions of the same words, and the
            // blank form ran onto a second sheet because of them.
            foreach($tariffs as $tariff) print_tick($tariff['name'].': '.tariff_summary($tariff, null, false));
            ?></div>
        <?php endforeach; endif ?>
    </section>
    <?php endif ?>

    <?php if($fields): ?>
    <section class="sheet-block">
        <h2><?=e(t('Weitere Angaben','Additional details'))?></h2>
        <?php foreach($fields as $f):
            $label = field_label($f);
            if($f['field_type'] === 'checkbox') { print_tick($label, $student ? (bool)field_value($id, (int)$f['id']) : null); continue; }
            $value = $student ? field_value($id, (int)$f['id']) : null;
            print_field($label, 34, is_array($value) ? implode(', ', $value) : (string)($value ?? ''), '', true);
        endforeach ?>
    </section>
    <?php endif ?>

    <section class="sheet-block">
        <h2><?=e(t('Einverständnis','Consent'))?></h2>
        <?php
        print_tick(t('Ich habe die Datenschutzerklärung gelesen.','I have read the privacy notice.'), $student ? null : null);
        print_tick(t('Ja, ich möchte die Neuigkeiten per E-Mail bekommen.','Yes, send me the news by email.'), null);
        print_tick(t('Ja, bitte per E-Mail an neue Nachrichten erinnern.','Yes, email me when there is a new message.'), null);
        ?>
        <?php /* The long version of this is in the intro at the top of a blank
                 form, and two paragraphs saying the same thing cost the sheet a
                 second page. A data sheet has no intro, so it gets the sentence
                 here; a blank form gets the pointer. */ ?>
        <p class="sheet-note"><?=e($student
            ? t('Die Angaben werden nur intern für den Trainingsbetrieb verarbeitet, nicht verkauft und nicht für Werbung weitergegeben. Die vollständige Datenschutzerklärung steht im Portal.','The details are used inside the club only, never sold and never passed on for advertising. The full privacy notice is in the portal.')
            : t('Die vollständige Datenschutzerklärung steht im Portal.','The full privacy notice is in the portal.'))?></p>
    </section>

    <section class="sheet-block sheet-signatures">
        <?php
        print_signature(t('Ort und Datum','Place and date'));
        print_signature($student
            ? t('Unterschrift – Angaben geprüft','Signature – details checked')
            : t('Unterschrift (bei Minderjährigen der erziehungsberechtigten Person)',
                'Signature (for a minor, the parent or guardian)'));
        ?>
    </section>

    <footer class="sheet-foot">
        <?=e($club)?><?php if(setting('org_email'))echo ' · '.e((string)setting('org_email'));?><?php if(setting('org_phone'))echo ' · '.e((string)setting('org_phone'));?>
    </footer>
</article>
