<?php
/**
 * Invoices.
 *
 * What the law wants is checked against the document that is actually produced,
 * not against the fields that feed it: § 11 Abs 1 UStG asks for particular
 * things to appear on the invoice, so the test reads the PDF.
 *
 * The reader below is our own, which proves the strings are in the file but not
 * that the file is a PDF anybody else can open. That was checked separately, by
 * opening a generated invoice with pypdf: one page, the metadata readable, and
 * the text extracted with the umlauts and the euro sign intact. Worth repeating
 * by hand if the writer is ever changed, because a test that parses its own
 * output cannot tell you the output is valid.
 */
$trainer = make_account(['role'=>'trainer']); sign_in_as($trainer);

/** The text of a PDF, near enough to assert against. */
$pdfText = function (string $pdf): string {
    // Everything this writer produces is an uncompressed literal string in a
    // Tj operator, which is what makes it readable here at all.
    preg_match_all('/\((.*?)\) Tj/s', $pdf, $m);
    $text = implode("\n", array_map(fn($x) => strtr($x, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']), $m[1]));
    return (string)@iconv('CP1252', 'UTF-8//IGNORE', $text);
};

case_('An invoice is refused until the operator has said who they are');
$problems = invoice_issuer_problems();
ok($problems !== [], 'a fresh portal is not ready to invoice');
$student = make_student(['first_name'=>'Lena', 'last_name'=>'Hofer']);
$charge = fixture('charges', ['student_id'=>$student, 'label'=>'Beitrag September', 'amount_cents'=>4500,
    'gross_cents'=>4500, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>'2026-09-01',
    'period_to'=>'2026-09-30', 'due_on'=>'2026-09-01', 'overdue_on'=>'2026-09-08',
    'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()]);
throws(fn() => create_invoice($student, [$charge]), 'with no details, no invoice', 'Betreiber');

case_('Each problem says what it is about, so the start checklist asks rather than re-spells');
/* ADR 0011: „Name und Anschrift“ and „Bankkonto“ are ticked by this function's
   keys. A page that prints the problems only loops over the values. */
is_same(['name', 'address', 'iban'], array_keys($problems), 'a fresh portal lacks a name, an address and an IBAN');
set_setting('org_tax_mode', 'vat');
set_setting('org_vat_rate', 0);
$vatProblem = invoice_issuer_problems()['tax'] ?? '';
ok(str_contains($vatProblem, t('Steuersatz', 'tax rate')) && str_contains($vatProblem, 'UID'),
   'two tax problems at once are one entry naming both, because they are fixed in one place');
set_setting('org_tax_mode', 'small');
set_setting('org_vat_rate', 20);
ok(!isset(invoice_issuer_problems()['tax']), 'a small business with its exemption note has none');
$seeded = (int)setting('default_payment_profile');
set_setting('default_payment_profile', 0);
payment_cache_clear();
ok(str_contains(invoice_issuer_problems()['iban'] ?? '', 'kein Standard-Zahlungsempfänger')
   || str_contains(invoice_issuer_problems()['iban'] ?? '', 'No default payment recipient'),
   'no default recipient at all is the bank problem too: a course without its own would bill into nothing');
run('UPDATE payment_profiles SET archived=1 WHERE id=?', [$seeded]);
set_setting('default_payment_profile', $seeded);
payment_cache_clear();
ok(str_contains(invoice_issuer_problems()['iban'] ?? '', 'kein Standard-Zahlungsempfänger')
   || str_contains(invoice_issuer_problems()['iban'] ?? '', 'No default payment recipient'),
   'and so is an archived one, which resolves to none everywhere else');
run('UPDATE payment_profiles SET archived=0 WHERE id=?', [$seeded]);
payment_cache_clear();

case_('Once the operator has said who they are');
set_setting('org_name', 'Badmintonschule Hofer');
set_setting('org_street', 'Turnweg 3');
set_setting('org_zip', '4020');
set_setting('org_city', 'Linz');
set_setting('org_country', 'Österreich');
set_setting('org_email', 'kontakt@beispiel.test');
set_setting('org_tax_mode', 'small');
is_same(['iban' => t('Beim Zahlungsempfänger „', 'The payment recipient “') . 'Vereinskonto'
         . t('“ fehlt die IBAN – einzutragen unter „Verwaltung → Zahlungsempfänger“.', '” has no IBAN — add it under “Manage → Payment recipients”.')], invoice_issuer_problems(),
        'only one thing is still missing, and it is not about the operator');

case_('And until there is somewhere to send the money');
/* Not a corner case: the installer seeds a recipient called "Vereinskonto" with
   the SEPA payload ready and the account number blank, and makes it the default,
   so this is the state every portal starts in. Left alone it produced a
   finished-looking invoice with nowhere to pay it. */
$house = payment_profile((int)setting('default_payment_profile'));
ok($house !== null, 'a fresh portal has a payment recipient');
is_same('', trim((string)$house['iban']), 'and it is waiting for her account number');
ok(in_array(t('Beim Zahlungsempfänger „', 'The payment recipient “') . $house['name']
            . t('“ fehlt die IBAN – einzutragen unter „Verwaltung → Zahlungsempfänger“.', '” has no IBAN — add it under “Manage → Payment recipients”.'), invoice_issuer_problems(), true),
   'which the invoices page says before there is a family waiting for the document');
// Refused by the check of the recipient these charges resolve to, which names it.
throws(fn() => create_invoice($student, [$charge]), 'and an invoice nobody could pay is refused', 'Vereinskonto');
// She fills it in on the recipient that is already the default, which is what
// the message tells her to do: a charge remembers the recipient it was written
// for, so changing the course afterwards would change nothing.
run('UPDATE payment_profiles SET iban=? WHERE id=?', ['AT055100080513176900', (int)$house['id']]);
payment_cache_clear();
is_same([], invoice_issuer_problems(), 'and then nothing is missing');

case_('A second recipient without one is caught too, where the settings cannot see it');
/* The check above reads the default. A course may collect into another account,
   and the charge remembers the one it was written for - so this is the case the
   invoices page cannot warn about in advance. */
$cash = fixture('payment_profiles', ['name'=>'Turnierkasse', 'recipient'=>'Bar', 'iban'=>'', 'bic'=>'',
    'currency'=>'EUR', 'note'=>'', 'qr_template'=>'', 'archived'=>0, 'created_at'=>now()]);
$cashCharge = fixture('charges', ['student_id'=>$student, 'payment_profile_id'=>$cash,
    'label'=>'Turniergebühr', 'amount_cents'=>1500, 'gross_cents'=>1500, 'discount_cents'=>0,
    'discount_note'=>'', 'period_from'=>null, 'period_to'=>null, 'covered_from'=>null, 'covered_to'=>null,
    'due_on'=>'2026-09-01', 'overdue_on'=>null, 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()]);
payment_cache_clear();
throws(fn() => create_invoice($student, [$cashCharge]), 'named, so she knows which one to fix', 'Turnierkasse');
run('UPDATE charges SET cancelled=1 WHERE id=?', [$cashCharge]);

case_('With the details in place, it can be issued');
// Issued today rather than on a fixed day in 2026: an invoice dated in the past
// turns overdue the moment the calendar passes its due date, and the assertion
// further down that it "starts open" would then fail on an ordinary Tuesday for
// a reason nobody reading it could see.
$issuedOn = today();
$id = create_invoice($student, [$charge], $issuedOn, 14);
$invoice = invoice($id);
is_same(4500, (int)$invoice['gross_cents'], 'the total is the charge');
is_same(0, (int)$invoice['tax_cents'], 'a small business shows no tax');
is_same(date('Y-m-d', strtotime($issuedOn . ' +14 days')), $invoice['due_on'], 'payable fourteen days after the invoice date');
is_same('2026-09-01', $invoice['supplied_from'], 'the period of supply starts where the charge does');
is_same('2026-09-30', $invoice['supplied_to'], 'and ends where it ends');

case_('The number runs consecutively within the year');
ok(str_contains($invoice['number'], date('Y', strtotime($issuedOn))), 'it carries the year');
is_same(1, (int)$invoice['sequence'], 'and starts at one');
$second = invoice(create_invoice($student, [fixture('charges', ['student_id'=>$student, 'label'=>'Beitrag Oktober',
    'amount_cents'=>4500, 'gross_cents'=>4500, 'discount_cents'=>0, 'discount_note'=>'',
    'period_from'=>'2026-10-01', 'period_to'=>'2026-10-31', 'due_on'=>'2026-10-01', 'overdue_on'=>'2026-10-08',
    'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()])], $issuedOn));
is_same(2, (int)$second['sequence'], 'the next one follows it');
ok($second['number'] !== $invoice['number'], 'with a different number');

case_('A part period is stated as the part, not as the whole one');
/* The bill for somebody who joined on the 16th was right to the cent and said
   "01.09. – 30.09." underneath it. § 11 Abs 1 Z 3 lit d UStG asks for the
   Zeitraum of the supply, and a family keeps this document. */
$late = fixture('charges', ['student_id'=>$student, 'label'=>'Beitrag September', 'amount_cents'=>2250,
    'gross_cents'=>2250, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>'2026-09-01',
    'period_to'=>'2026-09-30', 'covered_from'=>'2026-09-16', 'covered_to'=>'2026-09-30',
    'due_on'=>'2026-09-16', 'overdue_on'=>'2026-09-23', 'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()]);
$partial = invoice(create_invoice($student, [$late], $issuedOn, 14));
is_same('2026-09-16', $partial['supplied_from'], 'the supply starts the day they joined');
is_same('2026-09-30', $partial['supplied_to'], 'and ends with the month');
$partialPdf = $pdfText(invoice_pdf($partial));
ok(str_contains($partialPdf, fmt_date('2026-09-16')), 'and the document says so where the family reads it');
ok(!str_contains($partialPdf, fmt_date('2026-09-01')),
   'and nowhere claims a fortnight they were not a member for');

case_('And the account number is printed the way it is read');
/* Grouped in fours on the two pages that show it and run together on the one
   document somebody copies it from, which is twenty characters with no place to
   keep your finger. */
is_same('AT05 5100 0805 1317 6900', iban_groups('AT055100080513176900'), 'four at a time');
is_same('AT05 5100 0805 1317 6900', iban_groups('AT05 5100 0805 1317 6900'), 'and an already-spaced one is not doubled up');
$banked = invoice(create_invoice($student, [fixture('charges', ['student_id'=>$student, 'label'=>'Beitrag November',
    'amount_cents'=>4500, 'gross_cents'=>4500, 'discount_cents'=>0, 'discount_note'=>'',
    'period_from'=>'2026-11-01', 'period_to'=>'2026-11-30', 'due_on'=>'2026-11-01', 'overdue_on'=>'2026-11-08',
    'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()])], $issuedOn));
ok(str_contains($pdfText(invoice_pdf($banked)), 'AT05 5100 0805 1317 6900'),
   'and the invoice carries it in groups, not as one twenty-character run');

case_('Above 400 € the recipient’s address has to be on it');
/* § 11 Abs 1 Z 3 lit b UStG wants the recipient's name and address; Abs 6 lets
   a Kleinbetragsrechnung up to 400 € gross leave both out, which is most of a
   club's invoices. So the address is asked for where it matters rather than
   made compulsory on a monthly fee. */
$big = fixture('charges', ['student_id'=>$student, 'label'=>'Jahresbeitrag und Anmeldung',
    'amount_cents'=>40100, 'gross_cents'=>40100, 'discount_cents'=>0, 'discount_note'=>'',
    'period_from'=>'2026-01-01', 'period_to'=>'2026-12-31', 'covered_from'=>'2026-01-01',
    'covered_to'=>'2026-12-31', 'due_on'=>'2026-01-01', 'overdue_on'=>'2026-01-08',
    'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()]);
throws(fn() => create_invoice($student, [$big], $issuedOn), 'over the threshold it is refused', 'Anschrift');
$under = fixture('charges', ['student_id'=>$student, 'label'=>'Beitrag Dezember',
    'amount_cents'=>40000, 'gross_cents'=>40000, 'discount_cents'=>0, 'discount_note'=>'',
    'period_from'=>'2026-12-01', 'period_to'=>'2026-12-31', 'covered_from'=>'2026-12-01',
    'covered_to'=>'2026-12-31', 'due_on'=>'2026-12-01', 'overdue_on'=>'2026-12-08',
    'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()]);
does_not_throw(fn() => create_invoice($student, [$under], $issuedOn), 'at exactly 400 € it is not');
run('UPDATE students SET address=? WHERE id=?', ['Hauptstraße 5, 7000 Eisenstadt', $student]);
$addressed = invoice(create_invoice($student, [$big], $issuedOn));
ok(str_contains($pdfText(invoice_pdf($addressed)), 'Hauptstraße 5, 7000 Eisenstadt'),
   'and with it filled in the document carries it, under the name');

case_('The same charge cannot be invoiced twice');
throws(fn() => create_invoice($student, [$charge]), 'a charge already on an invoice', 'schon eine Rechnung');
is_same(0, count(array_filter(uninvoiced_charges($student), fn($c) => (int)$c['id'] === $charge)),
        'and it is no longer offered for invoicing');

case_('Somebody else’s charge is refused');
$other = make_student(['first_name'=>'Jonas']);
throws(fn() => create_invoice($other, [$charge]), 'a charge belonging to another child', 'gehört nicht');

case_('The document carries what Austrian law asks for');
$text = $pdfText(invoice_pdf($invoice));
ok(str_starts_with(invoice_pdf($invoice), '%PDF-'), 'it really is a PDF');
ok(str_contains($text, 'Badmintonschule Hofer'), 'the issuer’s name');
ok(str_contains($text, 'Turnweg 3'), 'the issuer’s street');
ok(str_contains($text, '4020 Linz'), 'the issuer’s town');
ok(str_contains($text, 'Lena Hofer'), 'who it is for');
ok(str_contains($text, 'Beitrag September'), 'what was supplied');
ok(str_contains($text, '01.09.2026'), 'when it was supplied');
ok(str_contains($text, $invoice['number']), 'the consecutive number');
ok(str_contains($text, fmt_date($issuedOn)), 'the date it was issued');
ok(str_contains($text, '45,00'), 'the amount');
ok(str_contains($text, '6 Abs 1 Z 27'), 'and, with no VAT shown, why there is none');

case_('With VAT, the tax is split out of the price rather than added to it');
set_setting('org_tax_mode', 'vat');
set_setting('org_vat_rate', 20);
set_setting('org_vat_id', 'ATU12345678');
is_same([], invoice_issuer_problems(), 'a VAT-registered business needs a UID and now has one');
$vatCharge = fixture('charges', ['student_id'=>$student, 'label'=>'Turniergebühr', 'amount_cents'=>12000,
    'gross_cents'=>12000, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>null, 'period_to'=>null,
    'due_on'=>'2026-11-01', 'overdue_on'=>'2026-11-08', 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()]);
$withVat = invoice(create_invoice($student, [$vatCharge], '2026-11-01'));
is_same(12000, (int)$withVat['gross_cents'], 'the family pays what the charge said');
is_same(10000, (int)$withVat['net_cents'], 'the net is the price without the tax inside it');
is_same(2000, (int)$withVat['tax_cents'], 'and the tax is the difference');
$vatText = $pdfText(invoice_pdf($withVat));
ok(str_contains($vatText, 'ATU12345678'), 'the UID is on the invoice');
ok(str_contains($vatText, '20 %'), 'and the rate');
ok(str_contains($vatText, '100,00'), 'with the net amount shown');
set_setting('org_tax_mode', 'small');

case_('Open by default, overdue on its own, paid when the trainer says');
$owedBefore = balance($student);
is_same('open', invoice_status($invoice), 'it starts open');
run('UPDATE invoices SET overdue_on=? WHERE id=?', [date('Y-m-d', strtotime('-1 day')), $invoice['id']]);
is_same('overdue', invoice_status(invoice((int)$invoice['id'])), 'and goes overdue with nobody touching it');
invoice_mark_paid((int)$invoice['id'], '2026-09-10', 'Überweisung', 'Danke');
is_same('paid', invoice_status(invoice((int)$invoice['id'])), 'the trainer marking it paid settles it');

case_('Marking it paid is a real payment, not a flag');
is_same(4500, invoice_paid_cents((int)$invoice['id']), 'the money is recorded against the charge');
is_same($owedBefore - 4500, balance($student), 'and the family owes exactly that much less');
is_same(0, invoice_mark_paid((int)$invoice['id'], today(), 'Bar', ''), 'marking it paid twice writes nothing more');

case_('Cancelling keeps the number but frees the charge');
$third = create_invoice($other, [fixture('charges', ['student_id'=>$other, 'label'=>'Beitrag', 'amount_cents'=>3000,
    'gross_cents'=>3000, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>null, 'period_to'=>null,
    'due_on'=>'2026-09-01', 'overdue_on'=>'2026-09-08', 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()])]);
$freed = (int)scalar('SELECT charge_id FROM invoice_charges WHERE invoice_id=?', [$third]);
invoice_cancel($third, 'Falscher Empfänger');
is_same('cancelled', invoice_status(invoice($third)), 'it is cancelled');
ok(in_array($freed, array_map(fn($c) => (int)$c['id'], uninvoiced_charges($other)), true),
   'and the charge can go on a corrected invoice');
does_not_throw(fn() => create_invoice($other, [$freed]), 'which it now can');
throws(fn() => invoice_cancel($third, ''), 'cancelling twice is refused', 'bereits storniert');

case_('An invoice that has been paid cannot simply be cancelled');
throws(fn() => invoice_cancel((int)$invoice['id'], 'Versehen'), 'the payment has to go first', 'schon gezahlt');

case_('The document is frozen at the moment it was issued');
set_setting('org_name', 'Ganz Anderer Name');
set_setting('org_street', 'Andere Gasse 9');
$again = $pdfText(invoice_pdf(invoice((int)$invoice['id'])));
ok(str_contains($again, 'Badmintonschule Hofer'), 'the old invoice still names the old business');
ok(!str_contains($again, 'Ganz Anderer Name'), 'and not the new one');
$new = invoice(create_invoice($other, [fixture('charges', ['student_id'=>$other, 'label'=>'Neu', 'amount_cents'=>1000,
    'gross_cents'=>1000, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>null, 'period_to'=>null,
    'due_on'=>'2026-12-01', 'overdue_on'=>'2026-12-08', 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()])]));
ok(str_contains($pdfText(invoice_pdf($new)), 'Ganz Anderer Name'), 'while a new one names the new business');

case_('A family sees their own invoices and nobody else’s');
$account = make_account(['role'=>'student']);
run('UPDATE students SET account_id=? WHERE id=?', [$account, $student]);
sign_in_as($account);
does_not_throw(fn() => invoice((int)$invoice['id']), 'their own');
throws(fn() => invoice($third), 'somebody else’s', 'nicht gefunden');

case_('A discount is explained on the document rather than only subtracted');
sign_in_as($trainer);
// The year after today's, so no invoice issued today has already started its
// sequence: written as 2027, this broke on 1 January 2027.
$nextYear = (string)((int)substr(today(), 0, 4) + 1);
$discounted = fixture('charges', ['student_id'=>$other, 'label'=>'Beitrag Jänner', 'amount_cents'=>3150,
    'gross_cents'=>4500, 'discount_cents'=>1350, 'discount_note'=>'Willkommensrabatt: 30 %',
    'period_from'=>$nextYear.'-01-01', 'period_to'=>$nextYear.'-01-31', 'due_on'=>$nextYear.'-01-01', 'overdue_on'=>$nextYear.'-01-08',
    'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()]);
$withDiscount = invoice(create_invoice($other, [$discounted], $nextYear.'-01-02'));
$discountText = $pdfText(invoice_pdf($withDiscount));
ok(str_contains($discountText, 'Willkommensrabatt'), 'the reason is on the invoice');
ok(str_contains($discountText, '45,00'), 'with the price before it');
ok(str_contains($discountText, '31,50'), 'and the amount actually owed');
is_same(1, (int)$withDiscount['sequence'], 'and a new year starts its own sequence at one');

case_('The privacy notice fills itself in from the same details');
set_setting('privacy_de', 'Verantwortlich ist {{org_name}}, {{org_address}}. Kontakt: {{org_email}}.');
ok(str_contains(privacy_text('de'), 'Ganz Anderer Name'), 'the controller is named');
ok(str_contains(privacy_text('de'), 'Andere Gasse 9'), 'and the address filled in');
$before = notice_version();
set_setting('org_name', 'Wieder Anders');
ok(notice_version() !== $before, 'changing who the controller is changes the version people agreed to');

case_('An invoice for nothing is not something to chase');
// A welcome discount can take a charge to zero. The document still exists -
// it is what says the month was free - but it is settled the day it is issued.
$freeStudent = make_student(['first_name'=>'Gratis', 'last_name'=>'Monat']);
$freeCharge = fixture('charges', ['student_id'=>$freeStudent, 'label'=>'Willkommensmonat', 'amount_cents'=>0,
    'gross_cents'=>4500, 'discount_cents'=>4500, 'discount_note'=>'Willkommensrabatt: 100 %',
    'period_from'=>'2026-09-01', 'period_to'=>'2026-09-30', 'due_on'=>'2026-09-01',
    'overdue_on'=>'2026-09-08', 'cancelled'=>0, 'created_at'=>now()]);
$freeInvoice = invoice(create_invoice($freeStudent, [$freeCharge], '2026-09-01'));
is_same(0, (int)$freeInvoice['gross_cents'], 'nothing is owed');
is_same('paid', invoice_status($freeInvoice), 'so it is settled, not open and later overdue');

case_('One invoice, one bank account');
// Two courses can collect into different accounts. An invoice prints one set of
// bank details, so charges that disagree about where the money goes cannot share
// one document: the family would transfer to whichever account happened to be
// first.
$profileA = fixture('payment_profiles', ['name'=>'Verein', 'recipient'=>'Badminton Verein',
    'iban'=>'AT611904300234573201', 'bic'=>'BKAUATWW', 'currency'=>'EUR', 'qr_template'=>'', 'note'=>'',
    'archived'=>0, 'created_at'=>now()]);
$profileB = fixture('payment_profiles', ['name'=>'Privat', 'recipient'=>'Hofer Privat',
    'iban'=>'AT022050302101023600', 'bic'=>'SPIHAT22', 'currency'=>'EUR', 'qr_template'=>'', 'note'=>'',
    'archived'=>0, 'created_at'=>now()]);
$splitStudent = make_student(['first_name'=>'Zwei', 'last_name'=>'Konten']);
$chargeA = fixture('charges', ['student_id'=>$splitStudent, 'payment_profile_id'=>$profileA, 'label'=>'Kurs A',
    'amount_cents'=>3000, 'gross_cents'=>3000, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>null,
    'period_to'=>null, 'due_on'=>today(), 'overdue_on'=>today(), 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()]);
$chargeB = fixture('charges', ['student_id'=>$splitStudent, 'payment_profile_id'=>$profileB, 'label'=>'Kurs B',
    'amount_cents'=>4000, 'gross_cents'=>4000, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>null,
    'period_to'=>null, 'due_on'=>today(), 'overdue_on'=>today(), 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()]);
throws(fn() => create_invoice($splitStudent, [$chargeA, $chargeB]),
       'the two together are refused, with what to do instead', 'getrennte Rechnungen');
does_not_throw(fn() => create_invoice($splitStudent, [$chargeA]), 'separately, each one is fine');
does_not_throw(fn() => create_invoice($splitStudent, [$chargeB]), 'and so is the other');

case_('No default recipient stops an invoice only when the charges have no recipient of their own');
/* The start checklist still asks for a default („Bankkonto“). An invoice is a
   different question: it needs somewhere to pay these charges, and a course
   with its own recipient is that. */
set_setting('default_payment_profile', 0);
payment_cache_clear();
ok(isset(invoice_issuer_problems()['iban']), 'the checklist is told no default is chosen');
$ownCourse = make_class(['name'=>'Eigenes Konto', 'payment_profile_id'=>$profileA]);
$ownCharge = fixture('charges', ['student_id'=>$splitStudent, 'class_id'=>$ownCourse, 'label'=>'Kurs mit eigenem Konto',
    'amount_cents'=>2500, 'gross_cents'=>2500, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>null,
    'period_to'=>null, 'due_on'=>today(), 'overdue_on'=>today(), 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()]);
does_not_throw(fn() => create_invoice($splitStudent, [$ownCharge]), 'a charge from a course with its own recipient is invoiced');
$nowhere = fixture('charges', ['student_id'=>$splitStudent, 'label'=>'Ohne Kurs',
    'amount_cents'=>1000, 'gross_cents'=>1000, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>null,
    'period_to'=>null, 'due_on'=>today(), 'overdue_on'=>today(), 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()]);
throws(fn() => create_invoice($splitStudent, [$nowhere]), 'one with neither is refused, in a sentence',
       t('kein Zahlungsempfänger hinterlegt', 'no payment recipient'));

case_('A child who joins mid-month is due no earlier than the charge, and every page shows the same period');
/* Seen in a real browser: joined on the 28th, charged 3,50 € for three days,
   due on the 1st and „Überfällig“ on its first day; the family's page said
   01.09–30.09 while the invoice said 28.09–30.09. */
$midTariff = make_tariff(['class_id'=>$ownCourse, 'price_cents'=>3100, 'interval_months'=>1, 'due_day'=>1,
                          'grace_days'=>7, 'first_period'=>'prorate']);
$joiner = make_student(['first_name'=>'Neu', 'last_name'=>'Dabei', 'joined_on'=>today()]);
make_enrolment($ownCourse, $joiner, ['joined_on'=>today(), 'tariff_id'=>$midTariff]);
billing_run(billing_current_period());
$fresh = one('SELECT * FROM charges WHERE student_id=?', [$joiner]);
ok($fresh !== null, 'the charge is written');
ok($fresh['due_on'] >= today(), 'due no earlier than the day it is written');
ok(charge_overdue_sql() !== '' && $fresh['overdue_on'] > today(), 'so not overdue on its first day');
is_same([today(), billing_month_end(today())], charge_supplied($fresh), 'it covers from the day they joined');
$joinerInvoice = invoice(create_invoice($joiner, [(int)$fresh['id']]));
$line = json_decode((string)$joinerInvoice['snapshot_json'], true)['lines'][0]['period'];
is_same(charge_period_text($fresh), fmt_date($line[0]).' – '.fmt_date($line[1]), 'the family’s page and the invoice give the same period');
ok(str_contains(charge_reference($fresh, ['first_name'=>'Neu', 'last_name'=>'Dabei']), fmt_date(today())) || !str_contains((string)setting('payment_reference_template'), '{period}'),
   'and so does the bank reference, where it names one');


// ---------------------------------------------------------------------------
// Found by the whole-app review of October 2026. Each case failed before its fix.
// ---------------------------------------------------------------------------
sign_in_as($trainer);
set_setting('default_payment_profile', $seeded);
payment_cache_clear();
$owedCharge = fn(int $studentId, int $cents, string $label = 'Beitrag') => fixture('charges', ['student_id'=>$studentId,
    'label'=>$label, 'amount_cents'=>$cents, 'gross_cents'=>$cents, 'discount_cents'=>0, 'discount_note'=>'',
    'period_from'=>null, 'period_to'=>null, 'due_on'=>today(), 'overdue_on'=>today(), 'cancelled'=>0,
    'origin'=>'manual', 'created_at'=>now()]);
$recorded = fn(int $chargeId) => rows('SELECT * FROM payments WHERE charge_id=? AND voided=0 ORDER BY id', [$chargeId]);
$markPaid = fn(int $invoiceId) => act('invoice_state', ['id'=>(string)$invoiceId, 'mode'=>'paid', 'paid_on'=>today(),
                                                        'method'=>'Überweisung', 'note'=>'']);

case_('Marking an invoice paid confirms a payment already recorded, rather than adding a second');
/* „Als bezahlt eintragen“ counted only confirmed payments and paid the rest,
   while „Zahlung erfassen“ counts unconfirmed ones too. A transfer recorded but
   not yet confirmed was paid a second time, and confirming it made 90 € of 45. */
$payer = make_student(['first_name'=>'Zahlt', 'last_name'=>'Einmal']);
$whole = $owedCharge($payer, 4500);
$wholeInvoice = create_invoice($payer, [$whole]);
act('payment_add', ['charge_id'=>(string)$whole, 'amount'=>'45,00', 'paid_on'=>today(), 'method'=>'Überweisung', 'note'=>'']);
throws(fn() => act('payment_add', ['charge_id'=>(string)$whole, 'amount'=>'1,00', 'paid_on'=>today(), 'method'=>'Bar', 'note'=>'']),
       'with 45 € recorded and not yet confirmed, not one euro more can be recorded', 'unbestätigte');
$markPaid($wholeInvoice);
$payments = $recorded($whole);
is_same([4500], array_map(fn($p) => (int)$p['amount_cents'], $payments), 'the charge holds the one payment of 45 €, not two');
ok($payments !== [] && $payments[0]['confirmed_at'] !== null, 'and marking the invoice paid confirmed it');
is_same('paid', invoice_status(invoice($wholeInvoice)), 'so the invoice is paid');
$part = $owedCharge($payer, 4500);
$partInvoice = create_invoice($payer, [$part]);
act('payment_add', ['charge_id'=>(string)$part, 'amount'=>'20,00', 'paid_on'=>today(), 'method'=>'Bar', 'note'=>'']);
$markPaid($partInvoice);
$payments = $recorded($part);
is_same(4500, array_sum(array_map(fn($p) => (int)$p['amount_cents'], $payments)), 'with part of it recorded, only the rest is added');
is_same(0, count(array_filter($payments, fn($p) => $p['confirmed_at'] === null)), 'and every payment on it is confirmed');

case_('A charge on a live invoice cannot be cancelled from under it');
/* Cancelling it left the invoice asking for money for something that was no
   longer owed, and „Als bezahlt eintragen“ then paid the cancelled charge. */
$onInvoice = $owedCharge($payer, 2500);
$holding = invoice(create_invoice($payer, [$onInvoice]));
throws(fn() => act('charge_cancel', ['id'=>(string)$onInvoice]), 'refused, naming the invoice', $holding['number']);
is_same(0, (int)scalar('SELECT cancelled FROM charges WHERE id=?', [$onInvoice]), 'and the charge is not cancelled');
invoice_cancel((int)$holding['id'], 'Korrektur');
does_not_throw(fn() => act('charge_cancel', ['id'=>(string)$onInvoice]), 'once the invoice is cancelled, the charge can be');
$free = $owedCharge($payer, 500);
$heldBy = invoice(create_invoice($payer, [$owedCharge($payer, 700)]));
$heldCharge = (int)scalar('SELECT charge_id FROM invoice_charges WHERE invoice_id=?', [(int)$heldBy['id']]);
is_same([$heldCharge => ['id'=>(int)$heldBy['id'], 'number'=>$heldBy['number']]],
        live_invoices_of_charges([$free, $heldCharge, $onInvoice]),
        'the payments tab can ask which charges a live invoice holds - not the free one, not one whose invoice is cancelled');
// A charge cancelled before this rule existed may still sit on a live invoice.
$kept = $owedCharge($payer, 1000);
$dropped = $owedCharge($payer, 2000);
$mixed = create_invoice($payer, [$kept, $dropped]);
run('UPDATE charges SET cancelled=1 WHERE id=?', [$dropped]);
$markPaid($mixed);
is_same([], $recorded($dropped), 'marking that invoice paid pays nothing on the cancelled charge');
is_same(1000, (int)($recorded($kept)[0]['amount_cents'] ?? 0), 'and the live one in full');

case_('An invoice is not e-mailed to a family who turned payment e-mails off, and nothing says it was');
/* notify_invoice() asked whether the login was set up, never whether it takes
   payment e-mails. The queue then dropped the mail, while the invoice said
   „per E-Mail geschickt am …“. */
mail_ready(true);
$quiet = make_account(['role'=>'student', 'payment_notices'=>0]);
$quietKid = make_student(['first_name'=>'Still', 'last_name'=>'Familie', 'account_id'=>$quiet]);
$quietInvoice = create_invoice($quietKid, [$owedCharge($quietKid, 3000)]);
throws(fn() => act('invoice_state', ['id'=>(string)$quietInvoice, 'mode'=>'send']), 'refused up front, in words', 'abbestellt');
is_same(null, invoice($quietInvoice)['sent_at'], 'the invoice does not claim it was e-mailed');
is_same(0, (int)scalar('SELECT COUNT(*) FROM mail_jobs WHERE account_id=?', [$quiet]), 'and nothing was queued');
run('UPDATE accounts SET payment_notices=1 WHERE id=?', [$quiet]);
act('invoice_state', ['id'=>(string)$quietInvoice, 'mode'=>'send']);
ok(invoice($quietInvoice)['sent_at'] !== null, 'with them switched back on it goes, and says so');
is_same(1, (int)scalar("SELECT COUNT(*) FROM mail_jobs WHERE account_id=? AND status='queued'", [$quiet]), 'one mail in the outbox');
mail_ready(false);

case_('An invoice is printed in its family’s language, whoever opens it');
/* invoice_pdf() followed the session. The queue builds the attachment after
   whoever's page view came last, so an English family's invoice went out in
   German, or a German family's in English. */
$english = make_account(['role'=>'student', 'locale'=>'en']);
$englishKid = make_student(['first_name'=>'Emma', 'last_name'=>'Smith', 'account_id'=>$english]);
$englishInvoice = invoice(create_invoice($englishKid, [$owedCharge($englishKid, 3000)]));
$_SESSION['locale'] = 'de';
$englishPdf = $pdfText(invoice_pdf($englishInvoice));
ok(str_contains($englishPdf, 'Invoice number'), 'an English family’s invoice is in English for a German-speaking trainer');
ok(!str_contains($englishPdf, 'Rechnungsnummer'), 'with no German in it');
$attached = mail_attachment(['kind'=>'invoice', 'id'=>(int)$englishInvoice['id']]);
ok(str_contains($pdfText((string)($attached['body'] ?? '')), 'Invoice number'), 'and the one attached to the mail is the same');
$_SESSION['locale'] = 'en';
try { $germanPdf = $pdfText(invoice_pdf(invoice($wholeInvoice))); } finally { $_SESSION['locale'] = 'de'; }
ok(str_contains($germanPdf, 'Rechnungsnummer'), 'and a German family’s invoice stays German for an English-speaking one');

case_('The overview counts every invoice, and a part-paid one owes only what is left');
/* Only the newest 200 invoices were loaded, so one unpaid since 2020 fell out of
   „Überfällig“, its count and the total, and the total added the whole gross of
   an invoice half paid. invoice_totals() and invoice_list() ask the database
   about all of them, and views/invoices.php reads those: the next case reads
   the page. */
test_reset();
sign_in_as(make_account(['role'=>'trainer']));
$kid = make_student(['first_name'=>'Alt', 'last_name'=>'Offen']);
$listed = fn(array $over) => fixture('invoices', $over + ['student_id'=>$kid, 'account_id'=>null, 'year'=>2020,
    'supplied_from'=>null, 'supplied_to'=>null, 'net_cents'=>3000, 'tax_cents'=>0, 'gross_cents'=>3000, 'tax_rate'=>0,
    'tax_note'=>'', 'snapshot_json'=>'{}', 'created_by'=>null, 'created_at'=>now()]);
$forgotten = $listed(['number'=>'ALT-0001', 'sequence'=>1, 'issued_on'=>'2020-01-01', 'due_on'=>'2020-01-15', 'overdue_on'=>'2020-01-15']);
for ($i = 2; $i <= 201; $i++)
    $listed(['number'=>'ST-'.$i, 'sequence'=>$i, 'issued_on'=>'2021-01-01', 'due_on'=>'2021-01-15', 'overdue_on'=>'2021-01-15',
             'cancelled_at'=>now(), 'cancel_reason'=>'Versehen']);
$halfCharge = fixture('charges', ['student_id'=>$kid, 'label'=>'Teil', 'amount_cents'=>4500, 'due_on'=>today(), 'cancelled'=>0,
                                  'origin'=>'manual', 'created_at'=>now()]);
$soon = date('Y-m-d', strtotime(today().' +14 days'));
$half = $listed(['number'=>'TEIL-1', 'sequence'=>500, 'year'=>(int)substr(today(), 0, 4), 'issued_on'=>today(), 'due_on'=>$soon,
                 'overdue_on'=>$soon, 'gross_cents'=>4500, 'net_cents'=>4500]);
fixture('invoice_charges', ['invoice_id'=>$half, 'charge_id'=>$halfCharge]);
fixture('payments', ['charge_id'=>$halfCharge, 'amount_cents'=>2000, 'paid_on'=>today(), 'method'=>'Bar', 'note'=>'',
                     'confirmed_at'=>now(), 'voided'=>0]);
$totals = invoice_totals();
is_same(['open'=>1, 'overdue'=>1, 'paid'=>0, 'cancelled'=>200], $totals['counts'], 'every invoice is counted, the oldest included');
is_same(2500 + 3000, $totals['outstanding_cents'], 'and they owe 25 € on the part-paid one and 30 € on the old one');
is_same(['ALT-0001'], array_column(invoice_list('overdue'), 'number'), '„Überfällig“ lists the one unpaid since 2020');
is_same(['TEIL-1'], array_column(invoice_list('open'), 'number'), '„Offen“ the part-paid one');
is_same(50, count(invoice_list('cancelled')), 'a page holds fifty');
is_same(['ST-151'], array_column(array_slice(invoice_list('cancelled', 2), 0, 1), 'number'), 'and the next page carries on from there');
is_same(202, count(invoice_list('all', 1, 200)) + count(invoice_list('all', 2, 200)), 'all of them, two hundred to a page');
throws(fn() => invoice_list('vielleicht'), 'a state that is not one is refused');
$disagree = [];
foreach (array_merge(invoice_list('all', 1, 200), invoice_list('all', 2, 200)) as $row)
    if ($row['status'] !== invoice_status(invoice((int)$row['id']))) $disagree[] = $row['number'];
is_same([], $disagree, 'and the state the list gives each one is the state its own page gives it');

case_('The invoices page shows the oldest overdue invoice, counts it, and pages through the rest');
/* The page counted and filtered all_invoices(), the newest 200, in PHP: ALT-0001,
   unpaid since 2020, was not under „Überfällig", not in its count and not in the
   total, and the total added all of TEIL-1 when half of it was paid. */
$overdueList = render_view('invoices', ['state'=>'overdue']);
ok(str_contains($overdueList, '>ALT-0001</a>'), '„Überfällig" lists the invoice unpaid since 2020, older than the newest 200');
ok(str_contains($overdueList, e(t('Überfällig', 'Overdue')).' (1)</a>'), 'the count beside „Überfällig" includes it');
ok(str_contains($overdueList, '<strong>'.e(money(2500 + 3000)).'</strong>'),
   '„Offen und überfällig" adds what is still owed: 30 € on the old one, 25 € of the part-paid one');
ok(!str_contains($overdueList, 'class="pagination"'), 'one page of them has no pager');
$cancelledList = render_view('invoices', ['state'=>'cancelled']);
is_same(50, substr_count($cancelledList, '<div class="record-row">'), 'a page lists fifty');
ok(str_contains($cancelledList, 'href="'.e(url('invoices', ['state'=>'cancelled', 'p'=>2])).'"'), '„Weitere" leads on, keeping the filter');
ok(str_contains($cancelledList, e(t('Seite 1 von 4', 'Page 1 of 4'))), 'and the page says where in the list it is');
ok(!str_contains($cancelledList, e(t('Zurück', 'Previous'))), 'with no way back from the first page');
$lastCancelled = render_view('invoices', ['state'=>'cancelled', 'p'=>'4']);
ok(str_contains($lastCancelled, 'href="'.e(url('invoices', ['state'=>'cancelled', 'p'=>3])).'"'), 'the last page leads back');
ok(!str_contains($lastCancelled, e(url('invoices', ['state'=>'cancelled', 'p'=>5]))), 'and not on, because there is nothing more');
ok(str_contains(render_view('invoices', ['state'=>'cancelled', 'p'=>'99']), e(t('Seite 4 von 4', 'Page 4 of 4'))),
   'a page past the end shows the last one');
ok(!str_contains(render_view('invoices', ['state'=>'all']), '>ALT-0001</a>'), '„Alle" starts with the newest');
ok(str_contains(render_view('invoices', ['state'=>'all', 'p'=>'5']), '>ALT-0001</a>'), 'and its last page reaches the oldest');

case_('On the child’s page a charge is red only once it is late, and one an invoice holds says so instead of offering to cancel');
/* The badge compared the due date with today, so a charge inside its grace days
   was red while the overdue total, the reminders and „Überfällig" said it was
   not late yet. „Beitrag stornieren" was offered on a charge a live invoice
   holds, which charge_cancel then refused. And „Per E-Mail schicken" was offered
   for an invoice nobody could e-mail, refused only after the tap. */
$quietLogin = make_account(['role'=>'student', 'payment_notices'=>0]);
$family = make_student(['first_name'=>'Rot', 'last_name'=>'Odernicht', 'account_id'=>$quietLogin]);
$day = fn(int $days) => date('Y-m-d', strtotime(today().' '.sprintf('%+d', $days).' days'));
$chargeOn = fn(string $label, string $due, string $overdue) => fixture('charges', ['student_id'=>$family, 'label'=>$label,
    'amount_cents'=>3000, 'gross_cents'=>3000, 'discount_cents'=>0, 'discount_note'=>'', 'period_from'=>null, 'period_to'=>null,
    'due_on'=>$due, 'overdue_on'=>$overdue, 'cancelled'=>0, 'origin'=>'manual', 'created_at'=>now()]);
$chargeOn('In der Frist', $day(-2), $day(5));
$chargeOn('Zu spät', $day(-20), $day(-13));
$settled = $chargeOn('Beglichen', $day(-40), $day(-33));
fixture('payments', ['charge_id'=>$settled, 'amount_cents'=>3000, 'paid_on'=>$day(-35), 'method'=>'Bar', 'note'=>'',
                     'confirmed_at'=>now(), 'voided'=>0]);
$held = $chargeOn('Auf Rechnung', today(), $day(7));
$chargeOn('Ohne Rechnung', today(), $day(7));
$freed = $chargeOn('Rechnung storniert', today(), $day(7));
$thisYear = (int)substr(today(), 0, 4);
$holding = $listed(['student_id'=>$family, 'account_id'=>$quietLogin, 'number'=>'HALT-1', 'sequence'=>600, 'year'=>$thisYear,
                    'issued_on'=>today(), 'due_on'=>$day(14), 'overdue_on'=>$day(14)]);
fixture('invoice_charges', ['invoice_id'=>$holding, 'charge_id'=>$held]);
$withdrawn = $listed(['student_id'=>$family, 'number'=>'WEG-1', 'sequence'=>601, 'year'=>$thisYear, 'issued_on'=>today(),
                      'due_on'=>$day(14), 'overdue_on'=>$day(14), 'cancelled_at'=>now(), 'cancel_reason'=>'Versehen']);
fixture('invoice_charges', ['invoice_id'=>$withdrawn, 'charge_id'=>$freed]);

$paymentsTab = render_view('student', ['id'=>$family, 'tab'=>'payments']);
$card = function (string $label) use ($paymentsTab): string {
    foreach (array_slice(explode('<section class="card charge-card">', $paymentsTab), 1) as $piece)
        if (str_contains($piece, '<h2>'.e($label).'</h2>')) return (string)strstr($piece, '</section>', true);
    return '';
};
ok($card('In der Frist') !== '' && str_contains($card('In der Frist'), 'class="badge'), 'the charge inside its grace days is on the page, with its badge');
ok(!str_contains($card('In der Frist'), 'badge red'), 'past its due date but inside its grace days, it is not red');
ok(str_contains($card('Zu spät'), 'class="badge red"'), 'past its grace days and unpaid, it is');
ok(str_contains($card('Beglichen'), 'class="badge green"') && !str_contains($card('Beglichen'), 'badge red'),
   'paid, however long ago it was due, it is green and not red');
$heldCard = $card('Auf Rechnung');
ok(!str_contains($heldCard, 'value="charge_cancel"'), 'a charge a live invoice holds is not offered „Beitrag stornieren"');
ok(str_contains($heldCard, e(t('Steht auf Rechnung ', 'On invoice '))) && str_contains($heldCard, e(t(' – zuerst die Rechnung stornieren.', ' – cancel the invoice first.'))),
   'it says which invoice holds it and what to do first');
ok(str_contains($heldCard, '<a href="'.e(url('student', ['id'=>$family, 'tab'=>'invoices', '#'=>'invoice-'.$holding])).'">HALT-1</a>'),
   'with the invoice’s number linked to that invoice');
ok(str_contains($card('Ohne Rechnung'), 'value="charge_cancel"'), 'a charge on no invoice can be cancelled');
ok(str_contains($card('Rechnung storniert'), 'value="charge_cancel"') && !str_contains($card('Rechnung storniert'), 'WEG-1'),
   'and so can one whose invoice was cancelled, which names no invoice');

$invoicesTab = render_view('student', ['id'=>$family, 'tab'=>'invoices']);
ok(str_contains($invoicesTab, 'id="invoice-'.$holding.'"'), 'the link lands on the invoice, on the child’s invoices tab');
/** One invoice's row on the invoices tab: from its anchor to the next row, or the end of the list. */
$invoiceRow = function (string $html, int $invoiceId): string {
    $from = (string)strstr($html, 'id="invoice-'.$invoiceId.'"');
    $ends = array_filter([strpos($from, 'id="invoice-', 1), strpos($from, '</section>')], fn($at) => $at !== false);
    return $ends ? substr($from, 0, min($ends)) : $from;
};
$heldRow = $invoiceRow($invoicesTab, $holding);
ok(str_contains($heldRow, e(invoice_mail_refusal(invoice($holding)) ?? 'keine Ablehnung')),
   'an invoice for a family who turned payment e-mails off says so where „Per E-Mail schicken" would be');
ok(!str_contains($heldRow, 'name="mode" value="send"'), 'and offers no button that can only be refused');
ok(str_contains($heldRow, 'name="mode" value="paid"'), 'while „Als bezahlt eintragen" is still offered on that row');
run('UPDATE accounts SET payment_notices=1 WHERE id=?', [$quietLogin]);
// Issued before the child had a login, an invoice is addressed to nobody: the
// page asks once per login, and must not give one invoice another's answer.
$beforeLogin = $listed(['student_id'=>$family, 'account_id'=>null, 'number'=>'VORHER-1', 'sequence'=>602, 'year'=>$thisYear,
                        'issued_on'=>$day(-1), 'due_on'=>$day(13), 'overdue_on'=>$day(13)]);
$invoicesTab = render_view('student', ['id'=>$family, 'tab'=>'invoices']);
$heldRow = $invoiceRow($invoicesTab, $holding);
ok(str_contains($heldRow, 'name="mode" value="send"'), 'with them switched back on, the button is there');
ok(!str_contains($heldRow, e(t('abbestellt', 'switched off'))), 'and the sentence is gone');
$unaddressedRow = $invoiceRow($invoicesTab, $beforeLogin);
ok(!str_contains($unaddressedRow, 'name="mode" value="send"')
   && str_contains($unaddressedRow, e(invoice_mail_refusal(invoice($beforeLogin)) ?? 'keine Ablehnung')),
   'while the child’s invoice addressed to no login says why it cannot go, on the same page');

sign_in_as($quietLogin);
ok(!str_contains(render_view('student', ['id'=>$family, 'tab'=>'payments']), e(t('Steht auf Rechnung ', 'On invoice '))),
   'the family is not told to cancel an invoice, which is not theirs to do');
