<?php
declare(strict_types=1);

/**
 * Invoices that satisfy Austrian law, from details the operator enters once.
 *
 * The requirements are § 11 Abs 1 UStG: the issuer's name and address, the
 * recipient's, what was supplied and when, the amount, the tax rate and amount
 * or a note about why there is none, the issue date, a number that runs
 * consecutively, and the issuer's UID where they have one. A small business
 * under § 6 Abs 1 Z 27 UStG shows no tax and must say so instead, which is why
 * the exemption note is a setting rather than a constant.
 *
 * Two things follow from "invoice" being a document rather than a record:
 *
 * It is frozen. Everything it shows is copied into snapshot_json when it is
 * issued, and the PDF is built from that copy. Moving house next year changes
 * the address on new invoices and on no old one.
 *
 * It does not hold money. An invoice points at the charges it covers, and paid
 * means those charges have been paid - so what a family owes is still worked out
 * in one place and the invoice cannot disagree with the balance.
 */

/** Where invoices and proofs are written. Outside the web root by .htaccess. */
function invoice_dir(): string { return dirname(maintenance_file()) . '/proofs'; }

/**
 * What still has to be filled in before an invoice can be issued.
 *
 * Returned as sentences rather than a boolean, because "you cannot issue an
 * invoice" without saying what is missing is the kind of message that makes
 * somebody give up and use Word.
 *
 * Keyed by what each one is about - 'name', 'address', 'tax', 'iban' - so the
 * start checklist can tell „Name und Anschrift“ from „Bankkonto“ by asking this
 * function rather than spelling the rules a second time (ADR 0011). A page that
 * only prints them loops over the values and never sees the keys. Two tax
 * problems at once are one entry of two sentences: they are fixed in one place.
 */
function invoice_issuer_problems(): array {
    $missing = [];
    if (trim((string)setting('org_name')) === '')
        $missing['name'] = t('Name oder Firma des Betreibers fehlt.', 'The operator’s name or company is missing.');
    if (trim((string)setting('org_street')) === '' || trim((string)setting('org_city')) === '')
        $missing['address'] = t('Die Anschrift des Betreibers fehlt.', 'The operator’s address is missing.');
    $tax = [];
    if (setting('org_tax_mode') === 'small' && trim((string)setting('org_tax_note')) === '')
        $tax[] = t('Ohne Umsatzsteuer muss ein Hinweis auf die Steuerbefreiung auf der Rechnung stehen.',
                   'Without VAT, a note about the tax exemption has to appear on the invoice.');
    if (setting('org_tax_mode') === 'vat' && (int)setting('org_vat_rate') <= 0)
        $tax[] = t('Mit Umsatzsteuer muss ein Steuersatz angegeben sein.', 'With VAT, a tax rate has to be set.');
    if (setting('org_tax_mode') === 'vat' && trim((string)setting('org_vat_id')) === '')
        $tax[] = t('Mit Umsatzsteuer gehört die UID-Nummer auf die Rechnung.', 'With VAT, the VAT identification number belongs on the invoice.');
    if ($tax) $missing['tax'] = implode(' ', $tax);
    // The installer seeds a recipient called Vereinskonto with the SEPA payload
    // ready and no account number in it, waiting to be filled in - and being the
    // default, it is what every course and every charge picks up. Left as it
    // comes, the portal produces invoices with nowhere to send the money, and
    // the first anybody knows is the phone call. Said here so it is read on the
    // invoices page, before there is a family waiting for the document.
    //
    // No default at all is the same gap one step earlier: a charge from a course
    // without a recipient of its own falls back to the default, and with none it
    // falls back to nothing. An archived default counts as none, because that is
    // how charge_payment_profile() resolves it.
    $house = payment_profile((int)setting('default_payment_profile'));
    if (!$house)
        $missing['iban'] = t('Es ist kein Standard-Zahlungsempfänger gewählt – unter „Verwaltung → Geld & Zahlungen“ einen mit IBAN anlegen und als Standard wählen.',
                             'No default payment recipient is chosen – under “Manage → Money and payments”, add one with an IBAN and choose it as the default.');
    elseif (trim((string)$house['iban']) === '')
        $missing['iban'] = t('Beim Zahlungsempfänger „', 'The payment recipient “') . $house['name']
            . t('“ fehlt die IBAN – einzutragen unter „Verwaltung → Zahlungsempfänger“.',
                '” has no IBAN — add it under “Manage → Payment recipients”.');
    return $missing;
}

/** The issuer's details as they are right now, ready to be frozen onto a document. */
function invoice_issuer(): array {
    return [
        'name'       => (string)setting('org_name'),
        'street'     => (string)setting('org_street'),
        'zip'        => (string)setting('org_zip'),
        'city'       => (string)setting('org_city'),
        'country'    => (string)setting('org_country'),
        'email'      => (string)setting('org_email'),
        'phone'      => (string)setting('org_phone'),
        'web'        => (string)setting('org_web'),
        'vat_id'     => (string)setting('org_vat_id'),
        'register'   => (string)setting('org_register_no'),
        'footer'     => (string)setting('invoice_footer'),
    ];
}

/**
 * Who the invoice is addressed to.
 *
 * The account that holds the child's login, because that is the person paying;
 * a child with no account is addressed by their own name, which is what a family
 * who joined before accounts existed has.
 */
function invoice_recipient(array $student): array {
    $account = $student['account_id'] ? one('SELECT * FROM accounts WHERE id=?', [(int)$student['account_id']]) : null;
    $name = $student['first_name'] . ' ' . $student['last_name'];
    // Not the emergency contact any more: that list is people to ring, and the
    // grandmother at the top of it is not who the invoice is for. The address
    // is the one the family keeps on its own „Profil" tab (ADR 0020, §7),
    // frozen into each invoice when it is issued: create_invoice() copies this
    // into snapshot_json, so a later move changes the next invoice and never
    // an earlier one.
    return [
        'name'    => $account ? (string)$account['name'] : $name,
        'address' => (string)($student['address'] ?? ''),
        'email'   => student_email($student),
        'student' => $name,
        'account_id' => $account ? (int)$account['id'] : null,
        // The language the family reads the portal in, frozen with the rest:
        // the document is printed in it, whoever downloads it (invoice_locale()).
        'locale'  => ($account['locale'] ?? '') === 'en' ? 'en' : PORTAL_LOCALE,
    ];
}

/**
 * Above this, an invoice has to name the recipient's address.
 *
 * § 11 Abs 1 Z 3 lit b UStG wants the name and the address of the recipient.
 * Abs 6 lets a Kleinbetragsrechnung leave both out, and a Kleinbetragsrechnung
 * is one whose gross total does not exceed 400 €, so most of a club's invoices
 * never need it - which is why the address is a field she fills in when it
 * matters rather than one that blocks the form for a monthly fee.
 */
const INVOICE_ADDRESS_FROM_CENTS = 40000;

/**
 * Whether a student's postal address is missing: trimmed, it is empty. One rule
 * for create_invoice(), which refuses an invoice above the threshold without
 * one, and for family_next_steps(), which puts „Anschrift eintragen" on the
 * family's list for exactly that reason (ADR 0020, §7 and §11).
 */
function postal_address_missing(array $student): bool { return trim((string)($student['address'] ?? '')) === ''; }

/**
 * The span one charge actually paid for, as [from, to].
 *
 * Two dates on a charge answer two different questions. period_from and
 * period_to are the billing period, which is how the run knows a period has been
 * charged already. covered_from and covered_to are what the money was for, which
 * is shorter for anybody who joined or left part-way through - and is what § 11
 * Abs 1 Z 3 lit d UStG calls the Zeitraum of the supply.
 *
 * Charges written before migration 017 have no covered span. Their period is
 * what they were claiming, so it is what they go on claiming rather than
 * becoming blank on an old invoice that is reprinted.
 */
function charge_supplied(array $charge): array {
    $from = $charge['covered_from'] ?? null ?: ($charge['period_from'] ?? null);
    $to   = $charge['covered_to']   ?? null ?: ($charge['period_to']   ?? null);
    return [$from ?: null, $to ?: null];
}

/**
 * The span a charge paid for, as it is written for a person: "28.09.2026 –
 * 30.09.2026", or '' for a charge with none. Every place that shows a family
 * the period - the charge on their page, the bank reference - says what the
 * invoice says, because all of them read charge_supplied().
 */
function charge_period_text(array $charge, string $between = ' – '): string {
    [$from, $to] = charge_supplied($charge);
    return $from && $to ? fmt_date($from) . $between . fmt_date($to) : '';
}

/**
 * The live invoice each of these charges is on, as charge id => ['id', 'number'];
 * a charge on none is not in the answer. One query for any number of charges,
 * because a child's payments tab asks it of every charge it lists.
 */
function live_invoices_of_charges(array $chargeIds): array {
    $ids = array_values(array_unique(array_map('intval', $chargeIds)));
    if (!$ids) return [];
    $out = [];
    foreach (rows('SELECT ic.charge_id, i.id, i.number FROM invoice_charges ic JOIN invoices i ON i.id=ic.invoice_id'
        .' WHERE i.cancelled_at IS NULL AND ic.charge_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $r)
        $out[(int)$r['charge_id']] = ['id' => (int)$r['id'], 'number' => (string)$r['number']];
    return $out;
}

/**
 * Cancel one charge, or say in a sentence why it cannot be. Returns the charge
 * as it was.
 *
 * Refused while payments are recorded against it, and while a live invoice
 * asks for it: cancelled from under its invoice, the charge was still on a
 * document a family pays from, and „Als bezahlt eintragen“ then paid it. The
 * invoice is cancelled first - its number stays used - and the charge after.
 *
 * The charge gives up its billing key as it goes. The key says „this period is
 * charged“, and kept by a cancelled charge it refused the corrected one for
 * ever. Nothing un-cancels a charge (the change log informs, it does not undo),
 * so a cancelled charge can never need its key back.
 */
function cancel_charge(int $chargeId): array {
    return transactional(function () use ($chargeId): array {
        $charge = lock_row('charges', $chargeId);
        if (!$charge) throw new NotFound(t('Diesen Beitrag gibt es nicht.', 'No such charge.'));
        if ((int)$charge['cancelled']) throw new UserError(t('Dieser Beitrag ist schon storniert.', 'That charge has already been cancelled.'));
        if ((int)scalar('SELECT ' . charge_recorded_sql() . ' FROM charges c WHERE c.id=?', [$chargeId]) > 0)
            throw new UserError(t('Zugehörige Zahlungen zuerst stornieren.', 'Void associated payments first.'));
        if ($invoice = live_invoices_of_charges([$chargeId])[$chargeId] ?? null)
            throw new UserError(strtr(t('Dieser Beitrag steht auf der Rechnung {number}. Storniere zuerst die Rechnung, dann den Beitrag.',
                                        'This charge is on invoice {number}. Cancel the invoice first, then the charge.'),
                                      ['{number}' => $invoice['number']]));
        tracked('charges', $chargeId, (string)$charge['label'],
            fn() => run('UPDATE charges SET cancelled=1, billing_key=NULL WHERE id=?', [$chargeId]));
        audit('charge.cancelled', 'charge', $chargeId);
        return $charge;
    });
}

/** Charges of one student that no live invoice covers yet. */
function uninvoiced_charges(int $studentId): array {
    return rows('SELECT c.*, ' . charge_paid_sql() . ' AS paid FROM charges c'
        .' WHERE c.student_id=? AND c.cancelled=0'
        .' AND NOT EXISTS (SELECT 1 FROM invoice_charges ic JOIN invoices i ON i.id=ic.invoice_id'
        .'                 WHERE ic.charge_id=c.id AND i.cancelled_at IS NULL)'
        .' ORDER BY c.due_on, c.id', [$studentId]);
}

/**
 * The next number, allocated inside the caller's transaction.
 *
 * Consecutive within the year is a legal requirement, and a gap that appears
 * because two browser tabs allocated at once is not something anybody would
 * notice until an audit. So the counter row is locked before it is read, and the
 * unique key on (year, sequence) catches anything that still gets past.
 */
function invoice_next_number(int $year): array {
    // The row has to exist before it can be locked.
    run('INSERT INTO settings (setting_key,setting_value,updated_at) VALUES (?,?,?)'
        .' ON DUPLICATE KEY UPDATE setting_key=setting_key', ['invoice_sequence', '{}', now()]);
    $raw = scalar('SELECT setting_value FROM settings WHERE setting_key=? FOR UPDATE', ['invoice_sequence']);
    $counters = json_decode((string)$raw, true);
    if (!is_array($counters)) $counters = [];
    $next = (int)($counters[(string)$year] ?? 0) + 1;
    $counters[(string)$year] = $next;
    run('UPDATE settings SET setting_value=?, updated_at=? WHERE setting_key=?',
        [json_encode($counters, JSON_UNESCAPED_UNICODE), now(), 'invoice_sequence']);
    setting_cache_clear();
    $number = strtr((string)setting('invoice_number_format'), [
        '{year}' => (string)$year,
        '{number}' => str_pad((string)$next, 4, '0', STR_PAD_LEFT),
    ]);
    return ['number' => mb_substr($number, 0, 40), 'sequence' => $next];
}

/**
 * Issue an invoice for some of a student's charges.
 *
 * Everything the document will ever show is worked out here and stored, so the
 * PDF built in two years' time is the document that was sent rather than a fresh
 * one made from today's settings.
 */
function create_invoice(int $studentId, array $chargeIds, string $issuedOn = '', int $termDays = -1): int {
    // Who is issuing it has to be on every invoice. Where the money goes is not
    // asked of the default here: it is checked below against the recipient these
    // charges actually resolve to - their own, their course's, then the default
    // - so a portal whose courses each name one can invoice with no default, and
    // an invoice with nowhere to pay is still refused.
    if ($problems = array_diff_key(invoice_issuer_problems(), ['iban' => true]))
        throw new UserError(t('Bitte zuerst die Angaben zum Betreiber vervollständigen: ',
                              'Please complete the operator’s details first: ') . implode(' ', $problems));
    $ids = array_values(array_unique(array_map('intval', $chargeIds)));
    if (!$ids) throw new UserError(t('Bitte mindestens einen Beitrag auswählen.', 'Please choose at least one charge.'));

    return transactional(function () use ($studentId, $ids, $issuedOn, $termDays): int {
        $student = one('SELECT * FROM students WHERE id=?', [$studentId]);
        if (!$student) throw new NotFound(t('Schüler nicht gefunden.', 'Student not found.'));

        $in = implode(',', array_fill(0, count($ids), '?'));
        $charges = rows('SELECT * FROM charges WHERE id IN (' . $in . ') AND student_id=? AND cancelled=0 ORDER BY due_on, id',
                        [...$ids, $studentId]);
        if (count($charges) !== count($ids))
            throw new UserError(t('Mindestens ein Beitrag gehört nicht zu diesem Kind oder ist storniert.',
                                  'At least one charge does not belong to this child or has been cancelled.'));
        $taken = (int)scalar('SELECT COUNT(*) FROM invoice_charges ic JOIN invoices i ON i.id=ic.invoice_id'
            .' WHERE ic.charge_id IN (' . $in . ') AND i.cancelled_at IS NULL', $ids);
        if ($taken) throw new UserError(t('Für mindestens einen dieser Beiträge gibt es schon eine Rechnung.',
                                          'At least one of these charges is already on an invoice.'));

        $issuedOn = $issuedOn !== '' ? date_value($issuedOn, true) : today();
        $term = $termDays >= 0 ? $termDays : (int)setting('invoice_terms_days');
        $due = (new DateTimeImmutable((string)$issuedOn))->modify('+' . max(0, min(180, $term)) . ' days')->format('Y-m-d');

        $vat = setting('org_tax_mode') === 'vat';
        $rate = $vat ? (int)setting('org_vat_rate') : 0;
        $gross = array_sum(array_map(fn($c) => (int)$c['amount_cents'], $charges));
        // The prices the trainer types are what a family pays, so the tax is
        // inside them rather than added on top.
        $net = $vat && $rate > 0 ? (int)round($gross * 100 / (100 + $rate)) : $gross;
        $tax = $gross - $net;

        $lines = [];
        $from = null; $to = null;
        foreach ($charges as $c) {
            // What was covered, not what the billing period was called: somebody
            // who joined on 12 November paid for seven weeks of the year, and
            // the period of supply this document states has to be those seven
            // weeks. A charge written before 017 has no covered span, and its
            // period is what it was claiming, so that is what it keeps saying.
            [$lineFrom, $lineTo] = charge_supplied($c);
            $lines[] = [
                'label'  => (string)$c['label'],
                'period' => $lineFrom && $lineTo ? [$lineFrom, $lineTo] : null,
                'gross'  => (int)$c['amount_cents'],
                'before_discount' => (int)$c['gross_cents'] ?: (int)$c['amount_cents'],
                'discount' => (int)$c['discount_cents'],
                'discount_note' => (string)$c['discount_note'],
            ];
            if ($lineFrom && ($from === null || $lineFrom < $from)) $from = $lineFrom;
            if ($lineTo && ($to === null || $lineTo > $to)) $to = $lineTo;
        }

        // One invoice carries one set of bank details, so the charges on it have
        // to agree about where the money goes. They did not have to before: the
        // first charge's account was printed and the rest were quietly billed to
        // it, which on a document a family pays from is the wrong IBAN.
        $accounts = [];
        foreach ($charges as $c) {
            $profile = charge_payment_profile($c);
            $accounts[$profile ? (string)$profile['iban'] : ''] = true;
        }
        if (count($accounts) > 1)
            throw new UserError(t('Diese Beiträge gehören zu Kursen mit verschiedenen Bankverbindungen. Bitte getrennte Rechnungen ausstellen.',
                                  'These charges belong to courses with different bank accounts. Please issue separate invoices.'));
        // An invoice with no account on it is a document a family cannot pay.
        // It came out looking finished, which is worse than being refused: the
        // first anybody knew was the phone call asking where to send the money.
        //
        // Which of the two things is wrong decides what she has to do, and the
        // first version of this message got it wrong for the commoner one: a
        // charge remembers the recipient it was written for, so changing the
        // course afterwards changes nothing and she is sent round in a circle.
        // The recipient it is actually pointing at is the thing to name.
        if (trim((string)array_key_first($accounts)) === '') {
            $named = charge_payment_profile($charges[0]);
            throw new UserError($named
                ? t('Beim Zahlungsempfänger „', 'The payment recipient “') . $named['name']
                  . t('“ steht keine IBAN, also stünde auf der Rechnung nicht, wohin das Geld soll. Unter „Verwaltung → Zahlungsempfänger“ die IBAN dort eintragen.',
                      '” has no IBAN, so the invoice would not say where to send the money. Add it under “Manage → Payment recipients”.')
                : t('Für diese Beiträge ist kein Zahlungsempfänger hinterlegt – auf der Rechnung stünde nicht, wohin das Geld soll. Unter „Verwaltung → Zahlungsempfänger“ einen anlegen und beim Kurs oder als Standard auswählen.',
                    'These charges have no payment recipient, so the invoice would not say where to send the money. Add one under “Manage → Payment recipients” and choose it on the course or as the default.'));
        }

        $recipient = invoice_recipient($student);
        // The threshold is on the gross of this document, so it cannot be known
        // from the settings the way the issuer's own details can: it is checked
        // here, where the charges are finally added up.
        if ($gross > INVOICE_ADDRESS_FROM_CENTS && postal_address_missing($student))
            throw new UserError(t('Über 400 € gehört die Anschrift der Rechnungsempfängerin oder des Rechnungsempfängers auf die Rechnung (§ 11 Abs 1 UStG). Sie steht beim Kind unter „Anschrift“.',
                                  'Above 400 € the recipient’s postal address belongs on the invoice (§ 11 Abs 1 UStG). It goes on the child’s record under “Anschrift”.'));

        $year = (int)substr((string)$issuedOn, 0, 4);
        $allocated = invoice_next_number($year);
        $profile = charge_payment_profile($charges[0]);
        $snapshot = [
            'issuer'    => invoice_issuer(),
            'recipient' => $recipient,
            'lines'     => $lines,
            'bank'      => $profile ? ['name' => $profile['recipient'] ?: $profile['name'],
                                       'iban' => $profile['iban'], 'bic' => $profile['bic']] : null,
            'reference' => $profile ? charge_reference($charges[0], $student) : '',
        ];
        $taxNote = $vat ? '' : (string)setting('org_tax_note');

        run('INSERT INTO invoices (number,year,sequence,student_id,account_id,issued_on,supplied_from,supplied_to,'
            .'due_on,overdue_on,net_cents,tax_cents,gross_cents,tax_rate,tax_note,snapshot_json,created_by,created_at)'
            .' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$allocated['number'], $year, $allocated['sequence'], $studentId, $recipient['account_id'],
             $issuedOn, $from, $to, $due, $due, $net, $tax, $gross, $rate, $taxNote,
             json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
             current_user()['id'] ?? null, now()]);
        $id = (int)db()->lastInsertId();
        foreach ($charges as $c)
            run('INSERT INTO invoice_charges (invoice_id,charge_id) VALUES (?,?)', [$id, (int)$c['id']]);
        audit('invoice.issued', 'invoice', $id);
        return $id;
    });
}

function invoice(int $id): array {
    $u = require_user();
    $sql = 'SELECT i.*, s.first_name, s.last_name FROM invoices i JOIN students s ON s.id=i.student_id WHERE i.id=?';
    $row = is_staff($u) ? one($sql, [$id]) : one($sql . ' AND s.account_id=?', [$id, (int)$u['id']]);
    if (!$row) throw new NotFound(t('Rechnung nicht gefunden.', 'Invoice not found.'));
    return $row;
}

/** What has actually been paid against the charges one invoice covers. */
function invoice_paid_cents(int $invoiceId): int {
    return (int)scalar('SELECT COALESCE(SUM(p.amount_cents),0) FROM payments p'
        .' JOIN invoice_charges ic ON ic.charge_id=p.charge_id'
        .' WHERE ic.invoice_id=? AND ' . payment_counts_sql(), [$invoiceId]);
}

/**
 * Open, overdue, paid or cancelled.
 *
 * Open is the state an invoice starts in, and the trainer is the one who says it
 * is paid - that is the whole rule she gave. Overdue is not a state anybody sets:
 * it is what open becomes once the due date has passed, so nobody has to
 * remember to go and mark anything.
 */
function invoice_status(array $invoice, ?int $paid = null): string {
    if ($invoice['cancelled_at'] !== null) return 'cancelled';
    $paid ??= invoice_paid_cents((int)$invoice['id']);
    // An invoice for nothing - a charge that a discount took to zero - is paid
    // the moment it is issued. Without this it stayed open for ever and then
    // turned overdue, and the family was asked to transfer 0,00 €.
    if ($paid >= (int)$invoice['gross_cents']) return 'paid';
    return $invoice['overdue_on'] < today() ? 'overdue' : 'open';
}

function invoice_status_label(string $status): string {
    return match ($status) {
        'paid'      => t('Bezahlt',    'Paid'),
        'overdue'   => t('Überfällig', 'Overdue'),
        'cancelled' => t('Storniert',  'Cancelled'),
        default     => t('Offen',      'Open'),
    };
}

function invoice_status_tone(string $status): string {
    return match ($status) { 'paid' => 'green', 'overdue' => 'red', 'cancelled' => '', default => 'amber' };
}

/** One student's invoices, newest first, each with what has been paid on it. */
function invoices_for(int $studentId): array {
    $out = rows('SELECT * FROM invoices WHERE student_id=? ORDER BY issued_on DESC, id DESC', [$studentId]);
    // One query for every invoice's payments rather than one per invoice: the
    // history grows for as long as she uses the portal.
    $paid = [];
    foreach (rows('SELECT ic.invoice_id, COALESCE(SUM(p.amount_cents),0) AS paid FROM invoice_charges ic'
        .' JOIN invoices i ON i.id=ic.invoice_id'
        .' LEFT JOIN payments p ON p.charge_id=ic.charge_id AND ' . payment_counts_sql()
        .' WHERE i.student_id=? GROUP BY ic.invoice_id', [$studentId]) as $r)
        $paid[(int)$r['invoice_id']] = (int)$r['paid'];
    foreach ($out as &$invoice) {
        $invoice['paid_cents'] = $paid[(int)$invoice['id']] ?? 0;
        $invoice['status'] = invoice_status($invoice, $invoice['paid_cents']);
    }
    return $out;
}

/**
 * Every invoice with what has been paid on it and its state, as a table to
 * select from, aliased 'listed'. Takes today() as its one parameter.
 *
 * invoice_status() in SQL, beside it so the two are read together: the
 * overview has to count and add up every invoice, and loading them into PHP to
 * ask invoice_status() of each was a query per invoice - capped at the newest
 * 200, so an unpaid invoice from two years ago fell out of „Überfällig“.
 */
function invoices_listed_sql(): string {
    return '(SELECT invoice.*, CASE WHEN invoice.cancelled_at IS NOT NULL THEN \'cancelled\''
        .' WHEN invoice.paid_cents>=invoice.gross_cents THEN \'paid\''
        .' WHEN invoice.overdue_on<? THEN \'overdue\' ELSE \'open\' END AS status'
        .' FROM (SELECT i.*, s.first_name, s.last_name, COALESCE((SELECT SUM(p.amount_cents) FROM invoice_charges ic'
        .'   JOIN payments p ON p.charge_id=ic.charge_id AND ' . payment_counts_sql() . ' WHERE ic.invoice_id=i.id),0) AS paid_cents'
        .'   FROM invoices i JOIN students s ON s.id=i.student_id) invoice) listed';
}

/** The states the overview filters by; 'all' is every one of them. */
function invoice_states(): array { return ['open', 'overdue', 'paid', 'cancelled']; }

/**
 * One page of the trainer's overview: the invoices in one state, or 'all',
 * newest first, each with 'paid_cents' and 'status'. $perPage at most 200.
 */
function invoice_list(string $state, int $page = 1, int $perPage = 50): array {
    $state = $state === 'all' ? 'all' : choose($state, invoice_states());
    $perPage = max(1, min(200, $perPage));
    return rows('SELECT * FROM ' . invoices_listed_sql() . ($state === 'all' ? '' : ' WHERE listed.status=?')
        . ' ORDER BY listed.issued_on DESC, listed.id DESC LIMIT ' . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage,
        $state === 'all' ? [today()] : [today(), $state]);
}

/**
 * How many invoices are in each state, and what the open and overdue ones still
 * owe: their gross less what has been paid on them, so a part-paid invoice adds
 * what is left rather than the whole of it. Over every invoice, however many.
 */
function invoice_totals(): array {
    $counts = array_fill_keys(invoice_states(), 0);
    $outstanding = 0;
    foreach (rows('SELECT listed.status, COUNT(*) AS invoices, SUM(listed.gross_cents-listed.paid_cents) AS owed'
        . ' FROM ' . invoices_listed_sql() . ' GROUP BY listed.status', [today()]) as $r) {
        $counts[(string)$r['status']] = (int)$r['invoices'];
        if (in_array($r['status'], ['open', 'overdue'], true)) $outstanding += (int)$r['owed'];
    }
    return ['counts' => $counts, 'outstanding_cents' => $outstanding];
}

/**
 * Record that an invoice has been paid.
 *
 * Writes the payment against the charges rather than setting a flag, so the
 * family's balance, the overdue list and the invoice all change together and
 * cannot end up saying different things.
 */
function invoice_mark_paid(int $invoiceId, string $paidOn, string $method, string $note): int {
    return transactional(function () use ($invoiceId, $paidOn, $method, $note): int {
        $invoice = one('SELECT * FROM invoices WHERE id=? FOR UPDATE', [$invoiceId]);
        if (!$invoice) throw new NotFound(t('Rechnung nicht gefunden.', 'Invoice not found.'));
        if ($invoice['cancelled_at'] !== null) throw new UserError(t('Diese Rechnung ist storniert.', 'That invoice has been cancelled.'));
        $written = 0;
        // Held, as „Zahlung erfassen“ holds the charge it writes to, and only the
        // charges still owed: one cancelled while it sat on this invoice was paid
        // here too.
        foreach (rows('SELECT c.*, ' . charge_recorded_sql() . ' AS recorded FROM charges c'
            .' JOIN invoice_charges ic ON ic.charge_id=c.id WHERE ic.invoice_id=? AND c.cancelled=0 ORDER BY c.id FOR UPDATE', [$invoiceId]) as $c) {
            // A transfer already recorded is the payment: it is confirmed, not
            // paid again beside it. Only what nobody has recorded yet is written.
            $pending = rows('SELECT * FROM payments p WHERE p.charge_id=? AND ' . payment_recorded_sql()
                .' AND p.confirmed_at IS NULL ORDER BY p.id', [(int)$c['id']]);
            foreach ($pending as $payment) confirm_payment($payment);
            $missing = (int)$c['amount_cents'] - (int)$c['recorded'];
            if ($missing > 0)
                run('INSERT INTO payments (charge_id,amount_cents,paid_on,method,note,confirmed_by,confirmed_at,voided)'
                    .' VALUES (?,?,?,?,?,?,?,0)',
                    [(int)$c['id'], $missing, $paidOn, $method, mb_substr($note, 0, 255),
                     current_user()['id'] ?? null, now()]);
            if ($pending || $missing > 0) $written++;
        }
        audit('invoice.paid', 'invoice', $invoiceId);
        return $written;
    });
}

/**
 * Cancel an invoice.
 *
 * Marked rather than deleted: a number that has been issued stays issued, or the
 * sequence has a hole in it that nobody can explain. The charges become free to
 * put on a corrected invoice.
 */
function invoice_cancel(int $invoiceId, string $reason): void {
    transactional(function () use ($invoiceId, $reason): void {
        $invoice = one('SELECT * FROM invoices WHERE id=? FOR UPDATE', [$invoiceId]);
        if (!$invoice) throw new NotFound(t('Rechnung nicht gefunden.', 'Invoice not found.'));
        if ($invoice['cancelled_at'] !== null) throw new UserError(t('Diese Rechnung ist bereits storniert.', 'That invoice is already cancelled.'));
        if (invoice_paid_cents($invoiceId) > 0)
            throw new UserError(t('Auf diese Rechnung wurde schon gezahlt. Bitte zuerst die Zahlung stornieren.',
                                  'A payment has already been made against this invoice. Void the payment first.'));
        run('UPDATE invoices SET cancelled_at=?, cancel_reason=? WHERE id=?', [now(), mb_substr($reason, 0, 255), $invoiceId]);
        audit('invoice.cancelled', 'invoice', $invoiceId);
    });
}

/**
 * The language an invoice is written in: its family's, as it was frozen when
 * the invoice was issued, or for one issued before that was kept, the family's
 * now. Never the language of whoever opens it - the mail queue builds the
 * attachment after whoever's page view came last.
 */
function invoice_locale(array $invoice): string {
    $snapshot = json_decode((string)($invoice['snapshot_json'] ?? ''), true);
    $kept = is_array($snapshot) ? ($snapshot['recipient']['locale'] ?? null) : null;
    if (in_array($kept, ['de', 'en'], true)) return $kept;
    $account = $invoice['account_id'] ? scalar('SELECT locale FROM accounts WHERE id=?', [(int)$invoice['account_id']]) : null;
    return $account === 'en' ? 'en' : PORTAL_LOCALE;
}

/**
 * The document, built from the frozen copy, in the family's language.
 *
 * Built on download rather than stored, because a PDF is a few kilobytes of
 * layout around numbers that are already in the database, and hosting space is
 * one of the things the operator pays for.
 */
function invoice_pdf(array $invoice): string {
    return in_locale(invoice_locale($invoice), fn(): string => invoice_document($invoice));
}

/** invoice_pdf(), in the language it has chosen. */
function invoice_document(array $invoice): string {
    $snapshot = json_decode((string)$invoice['snapshot_json'], true);
    if (!is_array($snapshot)) throw new RuntimeException('Invoice ' . $invoice['id'] . ' has no usable snapshot.');
    $issuer = $snapshot['issuer']; $recipient = $snapshot['recipient'];
    $right = PDF_PAGE_WIDTH - PDF_MARGIN;

    $doc = pdf_new();
    pdf_text($doc, $issuer['name'], 16, true);
    foreach ([$issuer['street'], trim($issuer['zip'] . ' ' . $issuer['city']), $issuer['country']] as $line)
        if (trim((string)$line) !== '') pdf_text($doc, (string)$line, 9, false, PDF_MARGIN, 0.35);
    foreach ([$issuer['email'], $issuer['phone'], $issuer['web']] as $line)
        if (trim((string)$line) !== '') pdf_text($doc, (string)$line, 9, false, PDF_MARGIN, 0.35);
    pdf_down($doc, 16);

    pdf_text($doc, t('Rechnung', 'Invoice'), 20, true);
    pdf_down($doc, 4);
    pdf_text($doc, t('Rechnungsnummer: ', 'Invoice number: ') . $invoice['number'], 10);
    pdf_text($doc, t('Rechnungsdatum: ', 'Invoice date: ') . fmt_date((string)$invoice['issued_on']), 10);
    if ($invoice['supplied_from'] && $invoice['supplied_to'])
        pdf_text($doc, t('Leistungszeitraum: ', 'Period of supply: ')
            . fmt_date((string)$invoice['supplied_from']) . ' – ' . fmt_date((string)$invoice['supplied_to']), 10);
    if (trim((string)$issuer['vat_id']) !== '') pdf_text($doc, 'UID: ' . $issuer['vat_id'], 10);
    if (trim((string)$issuer['register']) !== '') pdf_text($doc, $issuer['register'], 10);
    pdf_down($doc, 14);

    pdf_text($doc, t('Rechnungsempfänger', 'Billed to'), 9, false, PDF_MARGIN, 0.45);
    pdf_text($doc, (string)$recipient['name'], 12, true);
    // On an invoice above 400 € this is what § 11 Abs 1 Z 3 lit b UStG asks for,
    // and create_invoice() refuses without it. Printed whenever it is there: an
    // older document reprinted from its snapshot simply has nothing here.
    if (trim((string)($recipient['address'] ?? '')) !== '')
        pdf_text($doc, (string)$recipient['address'], 10);
    if (($recipient['student'] ?? '') !== '' && $recipient['student'] !== $recipient['name'])
        pdf_text($doc, t('für ', 'for ') . $recipient['student'], 10, false, PDF_MARGIN, 0.35);
    pdf_down($doc, 16);

    pdf_at($doc, PDF_MARGIN, pdf_y($doc), t('Leistung', 'Description'), 9, true, 0.35);
    pdf_right($doc, $right, pdf_y($doc), t('Betrag', 'Amount'), 9, true, 0.35);
    pdf_down($doc, 14);
    pdf_rule($doc);

    foreach ($snapshot['lines'] as $line) {
        $y = pdf_y($doc);
        pdf_at($doc, PDF_MARGIN, $y, (string)$line['label'], 11);
        pdf_right($doc, $right, $y, money((int)$line['gross']), 11);
        pdf_down($doc, 15);
        $detail = [];
        if (!empty($line['period']))
            $detail[] = fmt_date($line['period'][0]) . ' – ' . fmt_date($line['period'][1]);
        if ((int)($line['discount'] ?? 0) > 0)
            $detail[] = ($line['discount_note'] ?: t('Rabatt', 'Discount'))
                . ': ' . money((int)$line['before_discount']) . ' − ' . money((int)$line['discount']);
        if ($detail) { pdf_text($doc, implode(' · ', $detail), 9, false, PDF_MARGIN, 0.4); }
        pdf_down($doc, 4);
    }

    pdf_rule($doc);
    if ((int)$invoice['tax_cents'] > 0) {
        $y = pdf_y($doc);
        pdf_at($doc, PDF_MARGIN, $y, t('Nettobetrag', 'Net amount'), 10, false, 0.35);
        pdf_right($doc, $right, $y, money((int)$invoice['net_cents']), 10, false, 0.35);
        pdf_down($doc, 15);
        $y = pdf_y($doc);
        pdf_at($doc, PDF_MARGIN, $y, t('Umsatzsteuer ', 'VAT ') . (int)$invoice['tax_rate'] . ' %', 10, false, 0.35);
        pdf_right($doc, $right, $y, money((int)$invoice['tax_cents']), 10, false, 0.35);
        pdf_down($doc, 15);
    }
    $y = pdf_y($doc);
    pdf_at($doc, PDF_MARGIN, $y, t('Gesamtbetrag', 'Total'), 13, true);
    pdf_right($doc, $right, $y, money((int)$invoice['gross_cents']), 13, true);
    pdf_down($doc, 24);

    if (trim((string)$invoice['tax_note']) !== '') { pdf_paragraph($doc, (string)$invoice['tax_note'], 9, false, 0.3); pdf_down($doc, 6); }
    pdf_paragraph($doc, t('Zahlbar bis ', 'Payable by ') . fmt_date((string)$invoice['due_on']) . '.', 10);

    if (!empty($snapshot['bank']) && trim((string)$snapshot['bank']['iban']) !== '') {
        pdf_down($doc, 8);
        pdf_text($doc, t('Bankverbindung', 'Bank details'), 9, true, PDF_MARGIN, 0.35);
        pdf_text($doc, (string)$snapshot['bank']['name'], 10);
        pdf_text($doc, 'IBAN ' . iban_groups((string)$snapshot['bank']['iban'])
            . (trim((string)$snapshot['bank']['bic']) !== '' ? '   BIC ' . $snapshot['bank']['bic'] : ''), 10);
        if (trim((string)($snapshot['reference'] ?? '')) !== '')
            pdf_text($doc, t('Verwendungszweck: ', 'Reference: ') . $snapshot['reference'], 10);
    }
    if (trim((string)$issuer['footer']) !== '') { pdf_down($doc, 14); pdf_paragraph($doc, (string)$issuer['footer'], 9, false, 0.4); }

    return pdf_render($doc, [
        'title'   => t('Rechnung ', 'Invoice ') . $invoice['number'],
        'author'  => (string)$issuer['name'],
        'subject' => (string)$recipient['name'],
    ]);
}

/** A file name a parent can find again in their downloads folder. */
function invoice_filename(array $invoice): string {
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$invoice['number']) ?? 'rechnung';
    return 'Rechnung-' . trim($slug, '-') . '.pdf';
}
