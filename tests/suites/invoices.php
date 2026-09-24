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

case_('Once the operator has said who they are');
set_setting('org_name', 'Badmintonschule Hofer');
set_setting('org_street', 'Turnweg 3');
set_setting('org_zip', '4020');
set_setting('org_city', 'Linz');
set_setting('org_country', 'Österreich');
set_setting('org_email', 'kontakt@beispiel.test');
set_setting('org_tax_mode', 'small');
is_same([t('Beim Zahlungsempfänger „', 'The payment recipient “') . 'Vereinskonto'
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
throws(fn() => create_invoice($student, [$charge]), 'and an invoice nobody could pay is refused', 'fehlt die IBAN');
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
$discounted = fixture('charges', ['student_id'=>$other, 'label'=>'Beitrag Jänner', 'amount_cents'=>3150,
    'gross_cents'=>4500, 'discount_cents'=>1350, 'discount_note'=>'Willkommensrabatt: 30 %',
    'period_from'=>'2027-01-01', 'period_to'=>'2027-01-31', 'due_on'=>'2027-01-01', 'overdue_on'=>'2027-01-08',
    'cancelled'=>0, 'origin'=>'auto', 'created_at'=>now()]);
$withDiscount = invoice(create_invoice($other, [$discounted], '2027-01-02'));
$discountText = $pdfText(invoice_pdf($withDiscount));
ok(str_contains($discountText, 'Willkommensrabatt'), 'the reason is on the invoice');
ok(str_contains($discountText, '45,00'), 'with the price before it');
ok(str_contains($discountText, '31,50'), 'and the amount actually owed');
is_same(1, (int)$withDiscount['sequence'], 'and 2027 starts its own sequence at one');

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
