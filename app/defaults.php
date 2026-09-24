<?php
declare(strict_types=1);

/**
 * Every operator-editable setting, declared once.
 *
 * setting() used to take its fallback from whichever call site asked first, so
 * the same key could have different defaults in different files and a key with
 * no row was whatever the caller guessed. Declaring them here means a missing
 * row is never undefined, an admin can see and change every one, and adding a
 * setting in a later version needs no migration - the default applies until it
 * is overridden.
 *
 * kind drives both validation and the admin form control:
 *   text      single line
 *   longtext  textarea
 *   int       whole number, clamped to min/max
 *   bool      checkbox
 *   list      one value per line
 *   map       code => label pairs
 *   choice    one of options
 */
function setting_schema(): array {
    static $schema;
    return $schema ??= [
        'club_name' => [
            'kind' => 'text', 'default' => 'Badminton', 'max' => 100, 'required' => true,
            'group' => 'portal',
            'label' => ['Name des Portals', 'Portal name'],
        ],
        'portal_tagline' => [
            'kind' => 'text', 'default' => '', 'max' => 120,
            'group' => 'portal',
            'label' => ['Untertitel in der Kopfzeile', 'Subtitle in the header'],
            'hint'  => ['Leer lassen, wenn keiner angezeigt werden soll.', 'Leave empty to show none.'],
        ],
        'statuses' => [
            'kind' => 'map', 'group' => 'students',
            'default' => ['trial' => 'Probetraining', 'active' => 'Aktiv', 'paused' => 'Pausiert', 'ended' => 'Beendet'],
            'label' => ['Mitgliedschaftsstatus', 'Membership statuses'],
        ],
        'absence_reasons' => [
            'kind' => 'map', 'group' => 'students',
            'default' => ['sick' => 'Krank', 'holiday' => 'Urlaub', 'other' => 'Abwesend'],
            'label' => ['Abwesenheitsgründe', 'Absence reasons'],
        ],
        'default_status' => [
            'kind' => 'choice', 'default' => 'active', 'options' => 'statuses', 'group' => 'students',
            'label' => ['Standardstatus für neue Schüler', 'Default status for new students'],
        ],
        'default_tariff' => [
            'kind' => 'int', 'default' => 0, 'min' => 0, 'group' => 'students',
            'label' => ['Standardtarif (0 = keiner)', 'Default tariff (0 = none)'],
        ],
        'payment_methods' => [
            'kind' => 'list', 'default' => ['Überweisung', 'Bar'], 'max_items' => 30, 'group' => 'payments',
            'label' => ['Zahlungsarten', 'Payment methods'],
        ],
        'default_payment_profile' => [
            'kind' => 'int', 'default' => 0, 'min' => 0, 'group' => 'payments',
            'label' => ['Standard-Zahlungsempfänger (0 = keiner)', 'Default payment profile (0 = none)'],
            'hint'  => ['Wird verwendet, wenn Beitrag und Kurs keinen eigenen haben.', 'Used when neither the charge nor the class names one.'],
        ],
        'payment_reference_template' => [
            'kind' => 'text', 'default' => '{label} - {student}', 'max' => 140, 'group' => 'payments',
            'label' => ['Zahlungsreferenz', 'Payment reference'],
            'hint'  => ['Platzhalter: {label} {student} {period} {club}', 'Placeholders: {label} {student} {period} {club}'],
        ],
        'show_payment_qr' => [
            'kind' => 'bool', 'default' => true, 'group' => 'payments',
            'label' => ['QR-Code für offene Beiträge anzeigen', 'Show a QR code for outstanding charges'],
        ],
        'billing_due_days' => [
            'kind' => 'int', 'default' => 14, 'min' => 0, 'max' => 90, 'group' => 'payments',
            'label' => ['Zahlungsziel für Monatsbeiträge (Tage ab dem 1.)', 'Payment term for monthly charges (days from the 1st)'],
        ],
        'billing_label' => [
            'kind' => 'text', 'default' => 'Beitrag {month}', 'max' => 120, 'group' => 'payments',
            'label' => ['Bezeichnung der Monatsbeiträge', 'Label for monthly charges'],
            'hint'  => ['Platzhalter: {month} {year}', 'Placeholders: {month} {year}'],
        ],
        'attendance_statuses' => [
            'kind' => 'map', 'group' => 'students',
            // Three by default so every label fits on one line on a phone at a
            // readable size. More can be added here; the buttons wrap to a
            // second row rather than truncating.
            'default' => ['present' => 'Anwesend', 'absent' => 'Fehlt', 'excused' => 'Entschuldigt'],
            'label' => ['Anwesenheit: mögliche Einträge', 'Attendance: possible entries'],
            'hint'  => ['Weitere Einträge sind möglich, z. B. „Verspätet“.', 'You can add more, for example “Late”.'],
        ],
        // --- who is running this ------------------------------------------
        // Entered once and used twice: in the privacy notice, where the law wants
        // a named controller, and on every invoice, where Austrian law wants a
        // name and address (§ 11 Abs 1 UStG).
        'org_name' => [
            'kind' => 'text', 'default' => '', 'max' => 160, 'group' => 'organisation',
            'label' => ['Name oder Firma', 'Name or company'],
            'hint'  => ['Genau so, wie es auf einer Rechnung stehen soll.', 'Exactly as it should appear on an invoice.'],
        ],
        'org_street' => [
            'kind' => 'text', 'default' => '', 'max' => 160, 'group' => 'organisation',
            'label' => ['Straße und Hausnummer', 'Street and number'],
        ],
        'org_zip' => [
            'kind' => 'text', 'default' => '', 'max' => 12, 'group' => 'organisation',
            'label' => ['PLZ', 'Postcode'],
        ],
        'org_city' => [
            'kind' => 'text', 'default' => '', 'max' => 120, 'group' => 'organisation',
            'label' => ['Ort', 'Town'],
        ],
        'org_country' => [
            'kind' => 'text', 'default' => 'Österreich', 'max' => 80, 'group' => 'organisation',
            'label' => ['Land', 'Country'],
        ],
        'org_email' => [
            'kind' => 'text', 'default' => '', 'max' => 254, 'group' => 'organisation',
            'label' => ['E-Mail für Rückfragen', 'Email for questions'],
        ],
        'org_phone' => [
            'kind' => 'text', 'default' => '', 'max' => 60, 'group' => 'organisation',
            'label' => ['Telefon', 'Telephone'],
        ],
        'org_web' => [
            'kind' => 'text', 'default' => '', 'max' => 160, 'group' => 'organisation',
            'label' => ['Website', 'Website'],
        ],
        'org_vat_id' => [
            'kind' => 'text', 'default' => '', 'max' => 20, 'group' => 'organisation',
            'label' => ['UID-Nummer', 'VAT identification number'],
            'hint'  => ['Leer lassen, wenn keine vorhanden ist. Kleinunternehmer haben meist keine.',
                        'Leave empty if you have none. Small businesses usually do not.'],
        ],
        'org_register_no' => [
            'kind' => 'text', 'default' => '', 'max' => 60, 'group' => 'organisation',
            // A registered club in Austria has a ZVR-Zahl and neither of the
            // other two, and it goes on everything the club sends out. Named
            // first because it is the commonest operator of this portal.
            'label' => ['ZVR-, Firmenbuch- oder GISA-Nummer', 'Register number (ZVR, company or trade register)'],
            'hint'  => ['Bei einem Verein die ZVR-Zahl, mit „ZVR“ davor, z. B. ZVR 1447346144. Sie erscheint so auf der Rechnung.',
                        'For a club, the ZVR number with “ZVR” in front, e.g. ZVR 1447346144. It appears on the invoice exactly as typed.'],
        ],
        'org_tax_mode' => [
            'kind' => 'choice', 'default' => 'small', 'options' => 'org_tax_modes', 'group' => 'organisation',
            'label' => ['Umsatzsteuer', 'VAT'],
            'hint'  => ['Kleinunternehmer stellen ohne Umsatzsteuer aus und müssen stattdessen auf die Befreiung hinweisen.',
                        'A small business invoices without VAT and must state the exemption instead.'],
        ],
        'org_tax_modes' => [
            'kind' => 'raw', 'group' => 'organisation', 'internal' => true,
            'default' => ['small' => 'Kleinunternehmer (keine Umsatzsteuer)', 'vat' => 'Mit Umsatzsteuer'],
            'label' => ['Umsatzsteuer-Varianten', 'VAT options'],
        ],
        'org_vat_rate' => [
            'kind' => 'int', 'default' => 20, 'min' => 0, 'max' => 100, 'group' => 'organisation',
            'label' => ['Steuersatz in Prozent', 'Tax rate, per cent'],
            'hint'  => ['Nur wenn mit Umsatzsteuer abgerechnet wird.', 'Only used when invoicing with VAT.'],
        ],
        'org_tax_note' => [
            'kind' => 'text', 'default' => 'Umsatzsteuerbefreit – Kleinunternehmer gemäß § 6 Abs 1 Z 27 UStG.',
            'max' => 255, 'group' => 'organisation',
            'label' => ['Hinweis zur Steuerbefreiung', 'Note about the tax exemption'],
            'hint'  => ['Muss auf jeder Rechnung stehen, wenn keine Umsatzsteuer ausgewiesen wird.',
                        'Must appear on every invoice when no VAT is shown.'],
        ],
        'invoice_number_format' => [
            'kind' => 'text', 'default' => '{year}-{number}', 'max' => 40, 'group' => 'organisation',
            'label' => ['Aufbau der Rechnungsnummer', 'Shape of the invoice number'],
            'hint'  => ['Platzhalter: {year} {number}. Die Nummer läuft je Jahr fortlaufend, wie es das Gesetz verlangt.',
                        'Placeholders: {year} {number}. The number runs consecutively within each year, as the law requires.'],
        ],
        'invoice_footer' => [
            'kind' => 'longtext', 'default' => '', 'max' => 1000, 'group' => 'organisation',
            'label' => ['Fußzeile der Rechnung', 'Invoice footer'],
            'hint'  => ['Zum Beispiel Bankverbindung in Worten oder ein Dank.', 'Bank details in words, or a thank you.'],
        ],
        'invoice_terms_days' => [
            'kind' => 'int', 'default' => 14, 'min' => 0, 'max' => 180, 'group' => 'organisation',
            'label' => ['Zahlungsziel neuer Rechnungen (Tage)', 'Payment term for new invoices (days)'],
        ],
        'invoice_sequence' => [
            'kind' => 'raw', 'default' => [], 'group' => 'organisation', 'internal' => true,
            'label' => ['Letzte Rechnungsnummer je Jahr', 'Last invoice number per year'],
        ],

        'default_accent' => [
            'kind' => 'choice', 'default' => 'teal', 'options' => 'accent_options', 'group' => 'portal',
            'label' => ['Standardfarbe des Portals', 'Default colour of the portal'],
            'hint'  => ['Jede und jeder kann im eigenen Konto eine andere wählen.', 'Everybody can pick a different one for their own account.'],
        ],
        'accent_options' => [
            'kind' => 'raw', 'group' => 'portal', 'internal' => true,
            'default' => ['teal'=>'Türkis','blue'=>'Blau','violet'=>'Violett','pink'=>'Pink',
                          'red'=>'Rot','orange'=>'Orange','green'=>'Grün','slate'=>'Grau'],
            'label' => ['Farbauswahl', 'Colour choices'],
        ],
        'history_months' => [
            'kind' => 'int', 'default' => 24, 'min' => 1, 'max' => 240, 'group' => 'system',
            'label' => ['Änderungen aufbewahren (Monate)', 'Keep changes for (months)'],
            'hint'  => ['Ältere Einträge im Änderungsprotokoll werden beim nächtlichen Aufräumen entfernt. Das Prüfprotokoll ist davon nicht betroffen.',
                        'Older entries in the change log are removed during the nightly cleanup. The audit log is not affected.'],
        ],
        'upload_max_kb' => [
            'kind' => 'int', 'default' => 4096, 'min' => 64, 'max' => 51200, 'group' => 'portal',
            'label' => ['Größte erlaubte Datei (kB)', 'Largest allowed file (kB)'],
            'hint'  => ['Gilt für Zahlungsbelege, Anhänge und Profilbilder. Der Server begrenzt zusätzlich; es gilt der kleinere Wert.',
                        'Applies to payment proofs, attachments and profile pictures. The server has its own limit; the smaller one wins.'],
        ],
        'online_window_minutes' => [
            'kind' => 'int', 'default' => 5, 'min' => 1, 'max' => 120, 'group' => 'portal',
            'label' => ['Als „online“ gilt eine Aktivität innerhalb von (Minuten)', 'Count as “online” when active within (minutes)'],
        ],
        'privacy_ready' => [
            'kind' => 'bool', 'default' => false, 'group' => 'privacy', 'internal' => true,
            'label' => ['Datenschutzerklärung freigegeben', 'Privacy notice approved'],
        ],
        'privacy_de' => [
            'kind' => 'longtext', 'default' => '', 'max' => 30000, 'group' => 'privacy', 'internal' => true,
            'label' => ['Datenschutzerklärung (Deutsch)', 'Privacy notice (German)'],
        ],
        'privacy_en' => [
            'kind' => 'longtext', 'default' => '', 'max' => 30000, 'group' => 'privacy', 'internal' => true,
            'label' => ['Datenschutzerklärung (Englisch)', 'Privacy notice (English)'],
        ],
        'smtp' => [
            'kind' => 'raw', 'default' => [], 'group' => 'smtp', 'internal' => true,
            'label' => ['SMTP', 'SMTP'],
        ],
        'smtp_last_test' => [
            'kind' => 'raw', 'default' => [], 'group' => 'smtp', 'internal' => true,
            'label' => ['Letzter Verbindungstest', 'Last connection test'],
        ],
        'mail_last_run' => [
            'kind' => 'raw', 'default' => '', 'group' => 'smtp', 'internal' => true,
            'label' => ['Letzter Versandlauf', 'Last mail run'],
        ],
        'defaults_initialized' => [
            'kind' => 'raw', 'default' => false, 'group' => 'smtp', 'internal' => true,
            'label' => ['Vorgaben angelegt', 'Defaults created'],
        ],
        'auto_background' => [
            'kind' => 'bool', 'default' => true, 'group' => 'system',
            'label' => ['Wartende Aufgaben beim Seitenaufruf erledigen', 'Do waiting work while pages are served'],
            'hint'  => ['E-Mails werden dann auch ohne Cronjob verschickt. Nur ausschalten, wenn ein Cronjob eingerichtet ist.',
                        'Email is then sent without a cron job. Switch this off only if a cron job is set up.'],
        ],
        'auto_billing' => [
            'kind' => 'bool', 'default' => false, 'group' => 'system',
            'label' => ['Monatsbeiträge automatisch anlegen', 'Create the monthly charges automatically'],
            // Said exactly: the portal has no clock of its own, it has page
            // views. "Am 1." was not true on a month where nobody opened the
            // portal until the 3rd, and it was not true of the moment the switch
            // itself is turned on.
            'hint'  => ['Einmal pro Monat, beim ersten Seitenaufruf in diesem Monat – nicht auf die Minute am 1. Wird sie mitten im Monat eingeschaltet, entstehen die Beiträge dieses Monats sofort. Aus: unter „Beiträge → Monatsbeiträge“ anlegen, mit Vorschau.',
                        'Once a month, on the first page view in that month – not on the stroke of the 1st. Switched on mid-month, this month’s charges are created straight away. Off: create them under “Beiträge → Monatsbeiträge”, with a preview first.'],
        ],
        'tick_last_run' => [
            'kind' => 'raw', 'default' => '', 'group' => 'system', 'internal' => true,
            'label' => ['Letzter Hintergrundlauf', 'Last background run'],
        ],
        'prune_last_run' => [
            'kind' => 'raw', 'default' => '', 'group' => 'system', 'internal' => true,
            'label' => ['Letztes Aufräumen', 'Last cleanup'],
        ],
        'billing_last_period' => [
            'kind' => 'raw', 'default' => '', 'group' => 'system', 'internal' => true,
            'label' => ['Zuletzt automatisch abgerechneter Monat', 'Last month billed automatically'],
        ],
        // Written by schema_apply(); storage/schema.stamp is only a cache of it,
        // so a hosting account that cannot write there still skips the check.
        'schema_written_by' => [
            'kind' => 'raw', 'default' => '', 'group' => 'system', 'internal' => true,
            'label' => ['Datenbank zuletzt geschrieben von Version', 'Database last written by version'],
        ],
        'version_history' => [
            'kind' => 'raw', 'default' => [], 'group' => 'system', 'internal' => true,
            'label' => ['Bisherige Versionen', 'Releases this database has seen'],
        ],
        'schema_last_update' => [
            'kind' => 'raw', 'default' => [], 'group' => 'system', 'internal' => true,
            'label' => ['Letzte Datenbankaktualisierung', 'Last database update'],
        ],
        'schema_fingerprint' => [
            'kind' => 'raw', 'default' => '', 'group' => 'system', 'internal' => true,
            'label' => ['Stand der Migrationen', 'Applied migration set'],
        ],
    ];
}

/** The declared default for a key, used whenever no row exists. */
function setting_default(string $key): mixed {
    $schema = setting_schema();
    if (!isset($schema[$key])) throw new RuntimeException('Unknown setting: '.$key);
    return $schema[$key]['default'];
}

/** Keys an admin may edit in the settings form, grouped for display. */
function settings_in_group(string $group): array {
    return array_filter(setting_schema(), fn($s) => ($s['group'] ?? '') === $group && empty($s['internal']));
}

function setting_label(array $spec): string { return t($spec['label'][0], $spec['label'][1]); }
function setting_hint(array $spec): string { return isset($spec['hint']) ? t($spec['hint'][0], $spec['hint'][1]) : ''; }

/**
 * Validate one submitted value against its declaration.
 *
 * Returns the value to store. Throws UserError with a readable message rather
 * than storing something a later read would have to defend against.
 */
function setting_validate(string $key, array $spec, mixed $raw): mixed {
    switch ($spec['kind']) {
        case 'bool':
            return (bool)$raw;
        case 'int':
            $v = trim((string)$raw);
            if (!preg_match('/^-?\d{1,9}$/D', $v)) throw new UserError(setting_label($spec).': '.t('Bitte eine ganze Zahl eingeben.', 'Please enter a whole number.'));
            $n = (int)$v;
            if (isset($spec['min']) && $n < $spec['min']) throw new UserError(setting_label($spec).': '.t('Wert ist zu klein.', 'Value is too small.'));
            if (isset($spec['max']) && $n > $spec['max']) throw new UserError(setting_label($spec).': '.t('Wert ist zu groß.', 'Value is too large.'));
            return $n;
        case 'text':
        case 'longtext':
            $v = trim((string)$raw);
            if (mb_strlen($v) > ($spec['max'] ?? 255)) throw new UserError(setting_label($spec).': '.t('Die Eingabe ist zu lang.', 'Input is too long.'));
            if (!empty($spec['required']) && $v === '') throw new UserError(setting_label($spec).': '.t('Pflichtfeld.', 'Required field.'));
            return $v;
        case 'list':
            $items = array_values(array_unique(array_filter(array_map('trim', explode("\n", (string)$raw)), fn($x) => $x !== '')));
            if (!$items) throw new UserError(setting_label($spec).': '.t('Bitte mindestens einen Eintrag angeben.', 'Please enter at least one item.'));
            if (count($items) > ($spec['max_items'] ?? 50)) throw new UserError(setting_label($spec).': '.t('Zu viele Einträge.', 'Too many items.'));
            foreach ($items as $item) if (mb_strlen($item) > 100) throw new UserError(setting_label($spec).': '.t('Ein Eintrag ist zu lang.', 'An item is too long.'));
            return $items;
        case 'choice':
            $allowed = array_keys((array)setting($spec['options']));
            return choose(trim((string)$raw), $allowed);
    }
    throw new RuntimeException('Setting '.$key.' is not editable through the form.');
}
