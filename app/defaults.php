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
        'skill_bands' => [
            'kind' => 'map', 'group' => 'skills',
            // Keys are the lower bound as a percentage of the scale; the label is
            // what a trainer sees. Editing this re-bands everyone immediately.
            'default' => ['0' => 'Einsteiger', '40' => 'Mittelstufe', '65' => 'Fortgeschritten', '85' => 'Wettkampf'],
            'label' => ['Leistungsgruppen (Untergrenze in % der Skala)', 'Skill bands (lower bound as % of scale)'],
        ],
        'assessment_window_days' => [
            'kind' => 'int', 'default' => 120, 'min' => 7, 'max' => 3650, 'group' => 'skills',
            'label' => ['Bewertung gilt als aktuell für (Tage)', 'An assessment counts as current for (days)'],
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
        'mail_last_run' => [
            'kind' => 'raw', 'default' => '', 'group' => 'smtp', 'internal' => true,
            'label' => ['Letzter Versandlauf', 'Last mail run'],
        ],
        'defaults_initialized' => [
            'kind' => 'raw', 'default' => false, 'group' => 'smtp', 'internal' => true,
            'label' => ['Vorgaben angelegt', 'Defaults created'],
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
