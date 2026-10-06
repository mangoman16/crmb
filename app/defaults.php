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
 *   reference the id of one row in 'table', or 0 for none. The table must be
 *             one setting_reference_tables() names; the form offers its rows
 *             through setting_reference_options().
 *   colour    '' for the built-in colour ('builtin'), or a lower-case #rrggbb.
 *             With 'text_on', a colour that text could not be read on is
 *             refused (setting_colour_refusal()).
 *
 * 'advanced' => true marks a setting most portals never need to touch. The form
 * gathers those under one „Erweitert“ heading instead of leaving them among the
 * ones a new portal has to answer (ADR 0011).
 */
/**
 * The longest that when somebody was online is kept and shown, in days.
 *
 * A ceiling, not a setting: longer would be a new decision about families'
 * data, which the privacy notice would have to say (ADR 0015). The setting
 * presence_history_days may shorten it. Declared here, before the settings
 * that use it, and read by app/presence.php, which loads later.
 */
const PRESENCE_HISTORY_MAX_DAYS = 30;

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
        // The stored name of the portal's own icon under storage/uploads/icon/,
        // or '' for the one that ships. Changed only through its own card in
        // Settings, because it is a file, not something typed into a box.
        'portal_icon' => [
            'kind' => 'raw', 'default' => '', 'group' => 'portal', 'internal' => true,
            'label' => ['Eigenes Symbol des Portals', 'The portal’s own icon'],
        ],
        // The stored name of the club's logo under storage/uploads/logo/, or ''
        // for none (ADR 0014). A file like the icon, so it has its own card too.
        'portal_logo' => [
            'kind' => 'raw', 'default' => '', 'group' => 'portal', 'internal' => true,
            'label' => ['Logo des Portals', 'The portal’s logo'],
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
        'payment_methods' => [
            'kind' => 'list', 'default' => ['Überweisung', 'Bar'], 'max_items' => 30, 'group' => 'payments',
            'label' => ['Zahlungsarten', 'Payment methods'],
        ],
        'default_payment_profile' => [
            'kind' => 'reference', 'table' => 'payment_profiles', 'default' => 0, 'group' => 'payments',
            'label' => ['Standard-Zahlungsempfänger', 'Default payment recipient'],
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
        // When a charge is due is not a setting: it is the tariff's payment day
        // and its days before overdue, which a child or one enrolment may
        // override (billing_due_day()). A „Zahlungsziel für Monatsbeiträge“
        // stood here that nothing read; a stored row of it is ignored.
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
            'hint'  => ['Gilt, solange keine eigene Hauptfarbe gesetzt ist.', 'Applies while no main colour of your own is set.'],
        ],
        'accent_options' => [
            'kind' => 'raw', 'group' => 'portal', 'internal' => true,
            'default' => ['teal'=>'Türkis','blue'=>'Blau','violet'=>'Violett','pink'=>'Pink',
                          'red'=>'Rot','orange'=>'Orange','green'=>'Grün','slate'=>'Grau'],
            'label' => ['Farbauswahl', 'Colour choices'],
        ],
        // --- the club's look (ADR 0013, 0014) ---------------------------------
        // One group, one form: the „Aussehen" card. defaults_registry_save
        // writes every key of a group from one POST, so a key of this group on
        // any other card would be blanked by this one's save.
        'header_hide_name' => [
            'kind' => 'bool', 'default' => false, 'group' => 'branding',
            'label' => ['Portalnamen neben dem Logo ausblenden', 'Hide the portal name next to the logo'],
            'hint'  => ['Nur mit Logo oder Symbol – ohne beides wird der Name trotzdem gezeigt, sonst stünde oben links nichts.',
                        'Only with a logo or icon – without either the name is shown anyway, or nothing would be left top left.'],
        ],
        'header_hide_subtitle' => [
            'kind' => 'bool', 'default' => false, 'group' => 'branding',
            'label' => ['Zeile „Verwaltung“ / „Mein Portal“ unter dem Namen ausblenden', 'Hide the “Management” / “My portal” line under the name'],
            'hint'  => ['Die Zeile sagt, ob man als Trainerin oder als Familie angemeldet ist.',
                        'It says whether you are signed in as a trainer or as a family.'],
        ],
        // '' is the built-in colour, which 'builtin' names for the form and the
        // refusals. A background also names the grey text that has to stay
        // readable on it ('text_on'); the three brand colours are adjusted for
        // readability instead of refused, because a club's colours are not hers
        // to change (brand_palette()).
        'brand_primary' => [
            'kind' => 'colour', 'default' => '', 'builtin' => '#077e76', 'group' => 'branding',
            'label' => ['Hauptfarbe', 'Main colour'],
            'hint'  => ['Knöpfe, Links und Markierungen. Wer sich unter „Mein Konto“ eine eigene Farbe ausgesucht hat, behält sie.',
                        'Buttons, links and highlights. Anyone who picked their own colour under “My account” keeps it.'],
        ],
        'brand_secondary' => [
            'kind' => 'colour', 'default' => '', 'builtin' => '#13243a', 'group' => 'branding',
            'label' => ['Menüfarbe', 'Menu colour'],
            'hint'  => ['Das Menü am Computer, das Menü hinter „Mehr“ auf dem Handy und der Name auf der Anmeldeseite.',
                        'The menu on a computer, the menu behind “More” on a phone, and the name on the sign-in page.'],
        ],
        'brand_highlight' => [
            'kind' => 'colour', 'default' => '', 'builtin' => '#23cbbb', 'group' => 'branding',
            'label' => ['Hervorhebung', 'Highlight'],
            'hint'  => ['Der Punkt am „B“ und die Markierung beim Menüpunkt der Seite, auf der man gerade ist.',
                        'The dot on the “B” and the marker on the menu entry of the current page.'],
        ],
        'brand_background' => [
            'kind' => 'colour', 'default' => '', 'builtin' => '#f3f6f9', 'text_on' => '#5d6e7e', 'group' => 'branding',
            'label' => ['Hintergrund', 'Background'],
            'hint'  => ['Die Fläche hinter den Karten. Muss hell genug sein, dass graue Schrift darauf gut lesbar bleibt.',
                        'The area behind the cards. It has to be light enough for grey text on it to stay readable.'],
        ],
        'brand_primary_dark' => [
            'kind' => 'colour', 'default' => '', 'builtin' => '#3fd0bd', 'group' => 'branding', 'advanced' => true,
            'label' => ['Hauptfarbe im Dunkelmodus', 'Main colour in dark mode'],
            'hint'  => ['Leer = aus der hellen Farbe berechnet. Wirkt nur, solange oben die helle Farbe eingetragen ist.',
                        'Empty = worked out from the light colour. Only takes effect while the light colour is set above.'],
        ],
        'brand_secondary_dark' => [
            'kind' => 'colour', 'default' => '', 'builtin' => '#131d27', 'group' => 'branding', 'advanced' => true,
            'label' => ['Menüfarbe im Dunkelmodus', 'Menu colour in dark mode'],
            'hint'  => ['Leer = aus der hellen Farbe berechnet. Wirkt nur, solange oben die helle Farbe eingetragen ist.',
                        'Empty = worked out from the light colour. Only takes effect while the light colour is set above.'],
        ],
        'brand_highlight_dark' => [
            'kind' => 'colour', 'default' => '', 'builtin' => '#3fd0bd', 'group' => 'branding', 'advanced' => true,
            'label' => ['Hervorhebung im Dunkelmodus', 'Highlight in dark mode'],
            'hint'  => ['Leer = aus der hellen Farbe berechnet. Wirkt nur, solange oben die helle Farbe eingetragen ist.',
                        'Empty = worked out from the light colour. Only takes effect while the light colour is set above.'],
        ],
        'brand_background_dark' => [
            'kind' => 'colour', 'default' => '', 'builtin' => '#101922', 'text_on' => '#9aabba', 'group' => 'branding', 'advanced' => true,
            'label' => ['Hintergrund im Dunkelmodus', 'Background in dark mode'],
            'hint'  => ['Leer = aus der hellen Farbe berechnet. Wirkt nur, solange oben die helle Farbe eingetragen ist. Muss dunkel genug sein, dass graue Schrift darauf gut lesbar bleibt.',
                        'Empty = worked out from the light colour. Only takes effect while the light colour is set above. It has to be dark enough for grey text on it to stay readable.'],
        ],
        'history_months' => [
            'kind' => 'int', 'default' => 24, 'min' => 1, 'max' => 240, 'group' => 'system', 'advanced' => true,
            'label' => ['Änderungen aufbewahren (Monate)', 'Keep changes for (months)'],
            'hint'  => ['Ältere Einträge im Änderungsprotokoll werden beim nächtlichen Aufräumen entfernt. Das Prüfprotokoll ist davon nicht betroffen.',
                        'Older entries in the change log are removed during the nightly cleanup. The audit log is not affected.'],
        ],
        // The whole month by default, and never more: see
        // PRESENCE_HISTORY_MAX_DAYS. presence_history_days() clamps it again on read.
        'presence_history_days' => [
            'kind' => 'int', 'default' => PRESENCE_HISTORY_MAX_DAYS, 'min' => 1, 'max' => PRESENCE_HISTORY_MAX_DAYS, 'group' => 'system', 'advanced' => true,
            'label' => ['Wann jemand online war, aufbewahren (Tage)', 'Keep when somebody was online for (days)'],
            'hint'  => ['Höchstens 30. Sichtbar nur für Trainerinnen und Administratoren. Ältere Einträge löscht das nächtliche Aufräumen.',
                        'At most 30. Visible to trainers and administrators only. The nightly cleanup removes older entries.'],
        ],
        'upload_max_kb' => [
            'kind' => 'int', 'default' => 4096, 'min' => 64, 'max' => 51200, 'group' => 'portal',
            'label' => ['Größte erlaubte Datei (kB)', 'Largest allowed file (kB)'],
            'hint'  => ['Gilt für Zahlungsbelege, Anhänge und Profilbilder. Der Server begrenzt zusätzlich; es gilt der kleinere Wert.',
                        'Applies to payment proofs, attachments and profile pictures. The server has its own limit; the smaller one wins.'],
        ],
        // The four colours of the dot on an avatar (ADR 0015). Each band is
        // measured from the last activity, and presence_state() tests them in
        // order, so a band saved shorter than the one before it is skipped -
        // never shown out of order. Nothing needs to reorder them.
        'online_window_minutes' => [
            'kind' => 'int', 'default' => 5, 'min' => 1, 'max' => 120, 'group' => 'portal',
            'label' => ['Grün – „online“: aktiv innerhalb von (Minuten)', 'Green – “online”: active within (minutes)'],
            'hint'  => ['Danach blau – „vor Kurzem online“, dann gelb – „abwesend“, dann grau – „offline“. Einstellbar unter „Erweitert“.',
                        'After that blue – “recently online”, then yellow – “away”, then grey – “offline”. Adjustable under “Advanced”.'],
        ],
        'presence_recent_minutes' => [
            'kind' => 'int', 'default' => 60, 'min' => 5, 'max' => 1440, 'group' => 'portal', 'advanced' => true,
            'label' => ['Blau – „vor Kurzem online“: bis (Minuten nach der letzten Aktivität)', 'Blue – “recently online”: up to (minutes after the last activity)'],
        ],
        'presence_away_hours' => [
            'kind' => 'int', 'default' => 24, 'min' => 1, 'max' => 720, 'group' => 'portal', 'advanced' => true,
            'label' => ['Gelb – „abwesend“: bis (Stunden nach der letzten Aktivität)', 'Yellow – “away”: up to (hours after the last activity)'],
            'hint'  => ['Danach grau – „offline“.', 'After that grey – “offline”.'],
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
            'kind' => 'bool', 'default' => true, 'group' => 'system', 'advanced' => true,
            'label' => ['Wartende Aufgaben beim Seitenaufruf erledigen', 'Do waiting work while pages are served'],
            'hint'  => ['E-Mails werden dann auch ohne Cronjob verschickt. Nur ausschalten, wenn ein Cronjob eingerichtet ist.',
                        'Email is then sent without a cron job. Switch this off only if a cron job is set up.'],
        ],
        // Internal because it is switched on the Beiträge page, where the charges
        // it creates are listed, by auto_billing_save - not a second time here.
        'auto_billing' => [
            'kind' => 'bool', 'default' => false, 'group' => 'system', 'internal' => true,
            'label' => ['Monatsbeiträge automatisch anlegen', 'Create the monthly charges automatically'],
        ],
        // The start checklist (ADR 0011), put away by the administrator. It stops
        // the landing after sign-in, the menu entry, the overview card and the
        // way back; the checklist itself is never ticked by hand.
        'setup_hidden' => [
            'kind' => 'bool', 'default' => false, 'group' => 'system', 'internal' => true,
            'label' => ['Einrichtung ausgeblendet', 'Setup checklist hidden'],
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
        // What a refused sign-in checks the password against when there is no
        // real hash to check (ADR 0019, M2): a hash of random bytes, never a
        // password anybody has. '' until the migration runner makes it; kept at
        // PASSWORD_DEFAULT's cost by refresh_sign_in_dummy_hash().
        'sign_in_dummy_hash' => [
            'kind' => 'raw', 'default' => '', 'group' => 'system', 'internal' => true,
            'label' => ['Vergleichswert für abgelehnte Anmeldungen', 'Comparison value for refused sign-ins'],
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
        case 'reference':
            $v = trim((string)$raw);
            if ($v === '' || $v === '0') return 0;
            // Only a row the form could have offered: archived ones are left out
            // there, and a default pointing at one would quietly resolve to none.
            if (!preg_match('/^\d{1,18}$/D', $v) || !isset(setting_reference_options($spec)[(int)$v]))
                throw new UserError(setting_label($spec).': '.t('Die Auswahl ist nicht verfügbar.', 'That selection is not available.'));
            return (int)$v;
        case 'colour':
            $v = trim((string)$raw);
            if ($v === '') return '';
            $colour = colour_normalise($v);
            if ($colour === null) throw new UserError(setting_label($spec).': '.t('Bitte eine Farbe wie #1f5fa9 eingeben.', 'Please enter a colour like #1f5fa9.'));
            $refusal = setting_colour_refusal($spec, $colour);
            if ($refusal !== '') throw new UserError($refusal);
            return $colour;
    }
    throw new RuntimeException('Setting '.$key.' is not editable through the form.');
}

/**
 * Why a colour cannot be stored for this setting, or '' when it can.
 *
 * Only for a setting with 'text_on': a background, where the grey text on it
 * has to stay readable at 4.5:1 (WCAG AA). Refused rather than adjusted,
 * because a background is a free choice and a reason is clearer than a colour
 * she did not pick. brand_palette() asks again on read, so a value that never
 * came through this form cannot turn the grey text unreadable either.
 */
function setting_colour_refusal(array $spec, string $colour): string {
    if (!isset($spec['text_on']) || colour_contrast($colour, (string)$spec['text_on']) >= 4.5) return '';
    // Grey text that reads best on white is dark grey, so it needs a lighter
    // background; the dark scheme's light grey needs a darker one.
    $lighter = colour_text_on((string)$spec['text_on']) === '#ffffff';
    return setting_label($spec).': '
        .t('Auf diesem Hintergrund wäre die graue Schrift schwer zu lesen. Bitte eine ', 'Grey text would be hard to read on this background. Please choose a ')
        .($lighter ? t('hellere', 'lighter') : t('dunklere', 'darker'))
        .t(' Farbe wählen (eingebaut: ', ' colour (built-in: ').($spec['builtin'] ?? '').t('). Nichts wurde gespeichert.', '). Nothing was saved.');
}

/**
 * The sentence a save of the „Aussehen" card ends with: the colours it replaced.
 *
 * Settings are not tracked(), so this is the way back (ADR 0013): she can type
 * the old values in again. Only the colours that changed are named - a switch
 * is a tick she can see on the card, a hex code is not something anybody
 * remembers. The built-in colour is named as such, so a return to the built-in
 * look can be undone too. '' when no colour changed.
 */
function settings_replaced_colours(array $specs, array $before, array $after): string {
    // Read the way brand_chosen() reads them: a value that did not normalise,
    // or a background the form would refuse today, was showing as the built-in
    // colour, so that is what it is called.
    $colour = function (array $spec, mixed $v): string {
        $c = is_string($v) ? colour_normalise($v) : null;
        return $c === null || setting_colour_refusal($spec, $c) !== '' ? '' : $c;
    };
    $parts = [];
    foreach ($specs as $key => $spec) {
        if (($spec['kind'] ?? '') !== 'colour') continue;
        $old = $colour($spec, $before[$key] ?? '');
        if ($old === $colour($spec, $after[$key] ?? '')) continue;
        $label = setting_label($spec);
        // Mid-sentence in English, where a label is not a noun that keeps its capital.
        if (locale() === 'en') $label = lcfirst($label);
        $parts[] = $label.' '.($old === '' ? t('Standard', 'built-in') : $old);
    }
    return $parts ? t('Vorher: ', 'Before: ').implode(', ', $parts).'.' : '';
}

/**
 * The tables a 'reference' setting may point into, with the column that names a
 * row. An allowlist, as tracked_entities() is: the table name is interpolated,
 * and a declaration in this file is not a reason to trust whatever it says.
 */
function setting_reference_tables(): array {
    return ['payment_profiles' => 'name'];
}

/**
 * The rows a 'reference' setting may choose from, as id => name.
 *
 * The one list the form offers and setting_validate() accepts, so the two
 * cannot disagree about which rows count. Archived rows are not offered.
 */
function setting_reference_options(array $spec): array {
    $table = (string)($spec['table'] ?? '');
    $column = setting_reference_tables()[$table] ?? null;
    if ($column === null) throw new RuntimeException('Setting refers to a table that is not allowed: '.$table);
    $out = [];
    foreach (rows('SELECT id, '.sql_name($column, 'column').' AS label FROM '.sql_name($table, 'table')
        .' WHERE archived=0 ORDER BY '.sql_name($column, 'column').', id') as $row)
        $out[(int)$row['id']] = (string)$row['label'];
    return $out;
}
