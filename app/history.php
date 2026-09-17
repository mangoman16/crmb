<?php
declare(strict_types=1);

/**
 * The change log: what changed, when, and who changed it.
 *
 * Wrap a change in tracked() and what actually differed is written down in
 * words a person can read - "Vorname: Lena → Lena-Marie" rather than two blobs
 * of JSON.
 *
 * It used to be an undo mechanism, and a version therefore held the whole row
 * twice so that the old values could be written back. Putting values straight
 * back into a record is a dangerous thing to offer next to a list of every
 * change ever made - a later edit to the same row is silently undone with it -
 * and it cost a full copy of every record on every save. So the undo is gone,
 * and a version now stores only the columns that differed. A deletion still
 * keeps the whole row, because that is the one case where the log is the only
 * remaining description of what was there.
 *
 * It is not the audit log. audit_log says that something happened, in one line,
 * and is never rewritten. This says what it looked like before and after.
 */

/**
 * What a stored column is called in the interface.
 *
 * Column names are what a developer calls a field. This is what she calls it,
 * which is the difference between a change log and a dump - and the reason the
 * log is worth keeping at all.
 */
function history_field_label(string $column): string {
    return match ($column) {
        'first_name' => t('Vorname', 'First name'),
        'last_name' => t('Nachname', 'Last name'),
        'birth_date' => t('Geburtsdatum', 'Date of birth'),
        'joined_on' => t('Dabei seit', 'Member since'),
        'left_on' => t('Ausgetreten am', 'Left on'),
        'ended_on' => t('Mitgliedschaft bis', 'Membership until'),
        'status' => t('Mitgliedschaft', 'Membership'),
        'level_id' => t('Leistungsgruppe', 'Level'),
        'age_group_id' => t('Altersgruppe', 'Age group'),
        'tariff_id' => t('Tarif', 'Tariff'),
        'class_id' => t('Kurs', 'Course'),
        'account_id' => t('Zugeordnetes Konto', 'Linked account'),
        'price_cents' => t('Vereinbarter Preis', 'Agreed price'),
        'price_note' => t('Preisvereinbarung', 'Price agreement'),
        'amount_cents' => t('Betrag', 'Amount'),
        'gross_cents' => t('Betrag vor Rabatt', 'Amount before discount'),
        'discount_cents' => t('Rabatt', 'Discount'),
        'due_on' => t('Fällig am', 'Due on'),
        'overdue_on' => t('Überfällig ab', 'Overdue from'),
        'period_from' => t('Zeitraum ab', 'Period from'),
        'period_to' => t('Zeitraum bis', 'Period to'),
        'billing_paused' => t('Beiträge pausiert', 'Billing paused'),
        'billing_note' => t('Hinweis zu den Beiträgen', 'Note about billing'),
        'billing_due_day' => t('Zahltag', 'Payment day'),
        'internal_notes' => t('Interne Notizen', 'Internal notes'),
        'name' => t('Name', 'Name'),
        'email' => t('E-Mail-Adresse', 'Email address'),
        'role' => t('Rolle', 'Role'),
        'state' => t('Zustand', 'State'),
        'description' => t('Beschreibung', 'Description'),
        'location' => t('Ort', 'Place'),
        'capacity' => t('Plätze', 'Places'),
        'archived' => t('Archiviert', 'Archived'),
        'sort_order' => t('Reihenfolge', 'Order'),
        'interval_months' => t('Abrechnung alle (Monate)', 'Billed every (months)'),
        'due_day' => t('Zahltag', 'Payment day'),
        'grace_days' => t('Tage bis überfällig', 'Days before overdue'),
        'first_period' => t('Erster Zeitraum', 'First period'),
        'discount_months' => t('Rabatt für (Monate)', 'Discount for (months)'),
        'discount_value' => t('Höhe des Rabatts', 'Size of the discount'),
        'discount_kind' => t('Art des Rabatts', 'Kind of discount'),
        'discount_note' => t('Name des Rabatts', 'Name of the discount'),
        'price_cents' => t('Preis', 'Price'),
        'price_note' => t('Preisvereinbarung', 'Price agreement'),
        'title' => t('Titel', 'Title'),
        'body' => t('Text', 'Text'),
        'published' => t('Veröffentlicht', 'Published'),
        'subject' => t('Betreff', 'Subject'),
        'label' => t('Bezeichnung', 'Description'),
        'cancelled' => t('Storniert', 'Cancelled'),
        'voided' => t('Storniert', 'Voided'),
        'method' => t('Zahlungsart', 'Payment method'),
        'is_default' => t('Standard', 'Default'),
        'min_age' => t('Ab Alter', 'From age'),
        'max_age' => t('Bis Alter', 'To age'),
        'iban' => 'IBAN', 'bic' => 'BIC', 'recipient' => t('Empfänger', 'Recipient'),
        default => $column,
    };
}

/**
 * Tables that may be versioned, and how to describe one to a person.
 *
 * An allowlist rather than "any table": entity_snapshot() reads SELECT * from a
 * name that reaches it from a caller, so the set of tables it can touch belongs
 * in one visible place.
 */
function tracked_entities(): array {
    return [
        'students'         => ['label' => ['Schüler', 'Student'],            'title' => ['first_name', 'last_name']],
        'charges'          => ['label' => ['Beitrag', 'Charge'],             'title' => ['label']],
        'payments'         => ['label' => ['Zahlung', 'Payment'],            'title' => ['method']],
        'classes'          => ['label' => ['Kurs', 'Class'],                 'title' => ['name']],
        'tariffs'          => ['label' => ['Tarif', 'Tariff'],               'title' => ['name']],
        'payment_profiles' => ['label' => ['Zahlungsempfänger', 'Payment profile'], 'title' => ['name']],
        'levels'           => ['label' => ['Leistungsgruppe', 'Level'],      'title' => ['name']],
        'age_groups'       => ['label' => ['Altersgruppe', 'Age group'],     'title' => ['name']],
        'contacts'         => ['label' => ['Kontakt', 'Contact'],            'title' => ['owner_name']],
        'accounts'         => ['label' => ['Konto', 'Account'],              'title' => ['name']],
        'news'             => ['label' => ['Neuigkeit', 'News'],             'title' => ['title']],
        'message_templates'=> ['label' => ['Vorlage', 'Template'],           'title' => ['name']],
        'field_definitions'=> ['label' => ['Eigenes Feld', 'Custom field'],  'title' => ['label']],
    ];
}

function tracked_entity(string $entity): array {
    $all = tracked_entities();
    if (!isset($all[$entity])) throw new RuntimeException('Not a versioned table: '.$entity);
    return $all[$entity];
}

function entity_label(string $entity): string {
    $e = tracked_entity($entity);
    return t($e['label'][0], $e['label'][1]);
}

/** A short human name for a stored row, from whichever columns identify it. */
function entity_title(string $entity, ?array $row): string {
    if (!$row) return '';
    $parts = [];
    foreach (tracked_entity($entity)['title'] as $column)
        if (($row[$column] ?? '') !== '') $parts[] = (string)$row[$column];
    return implode(' ', $parts);
}

/** The current state of a row, or null when it does not exist. */
function entity_snapshot(string $entity, int $id): ?array {
    tracked_entity($entity);
    return one('SELECT * FROM '.$entity.' WHERE id=?', [$id]);
}

/**
 * Write one version row.
 *
 * An update stores only the columns that differ. A record with forty columns
 * changed in one of them used to cost two copies of all forty, on every save,
 * for as long as the portal is used; now it costs the one.
 *
 * A creation and a deletion keep the whole row on purpose: for a creation it is
 * what was entered, and for a deletion it is the only remaining description of
 * what used to be there.
 */
function history_record(string $entity, int $id, string $operation, string $label, ?array $before, ?array $after): int {
    tracked_entity($entity);
    if ($operation === 'update' && $before !== null && $after !== null) {
        $differing = [];
        foreach ($before as $column => $value)
            if ((string)$value !== (string)($after[$column] ?? null)) $differing[$column] = true;
        $before = array_intersect_key($before, $differing);
        $after  = array_intersect_key($after,  $differing);
    }
    run('INSERT INTO record_versions (entity,entity_id,operation,label,before_json,after_json,actor_id,created_at)'
        .' VALUES (?,?,?,?,?,?,?,?)',
        [$entity, $id, $operation, mb_substr($label, 0, 160),
         $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
         $after  === null ? null : json_encode($after,  JSON_UNESCAPED_UNICODE),
         $_SESSION['impersonator_id'] ?? (current_user()['id'] ?? null), now()]);
    return (int)db()->lastInsertId();
}

/**
 * Run a change to one existing row and record what it did.
 *
 * The snapshots and the change happen in one transaction, so a failure leaves
 * neither a half-applied row nor a version claiming something that did not
 * happen.
 *
 * $operation is 'update' normally, or 'delete' when $mutate removes the row.
 */
function tracked(string $entity, int $id, string $label, callable $mutate, string $operation = 'update'): mixed {
    tracked_entity($entity);
    return transactional(function () use ($entity, $id, $label, $mutate, $operation) {
        $before = entity_snapshot($entity, $id);
        $result = $mutate();
        $after = $operation === 'delete' ? null : entity_snapshot($entity, $id);
        history_record($entity, $id, $operation, $label, $before, $after);
        return $result;
    });
}

/**
 * Run a change that creates a row and record it.
 *
 * $create must return the new id, since there is nothing to snapshot first.
 */
function tracked_insert(string $entity, string $label, callable $create): int {
    tracked_entity($entity);
    return transactional(function () use ($entity, $label, $create) {
        $id = (int)$create();
        history_record($entity, $id, 'insert', $label, null, entity_snapshot($entity, $id));
        return $id;
    });
}

/** Versions for one record, newest first. */
function history_for(string $entity, int $id, int $limit = 50): array {
    tracked_entity($entity);
    return rows('SELECT v.*, a.name AS actor_name FROM record_versions v LEFT JOIN accounts a ON a.id=v.actor_id'
        .' WHERE v.entity=? AND v.entity_id=? ORDER BY v.id DESC LIMIT '.max(1, min(200, $limit)), [$entity, $id]);
}

/** The most recent changes across every tracked record, for the admin view. */
function history_recent(int $limit = 60): array {
    return rows('SELECT v.*, a.name AS actor_name FROM record_versions v LEFT JOIN accounts a ON a.id=v.actor_id'
        .' ORDER BY v.id DESC LIMIT '.max(1, min(200, $limit)));
}

/**
 * Which columns actually differ between the two sides of a version.
 *
 * Used to show a change rather than two opaque blobs. An update already stores
 * only the differing columns; this still compares, because a creation and a
 * deletion store the whole row and only some of it is worth reading.
 */
function version_changes(array $version): array {
    $before = $version['before_json'] ? json_decode($version['before_json'], true) : null;
    $after  = $version['after_json']  ? json_decode($version['after_json'],  true) : null;
    $columns = array_keys(($before ?? []) + ($after ?? []));
    $out = [];
    foreach ($columns as $column) {
        // Bookkeeping columns change on every save and say nothing about what
        // was actually edited.
        if (in_array($column, ['id', 'updated_at', 'created_at', 'revision'], true)) continue;
        $from = $before[$column] ?? null;
        $to   = $after[$column]  ?? null;
        if ((string)$from === (string)$to) continue;
        $out[$column] = ['from' => $from, 'to' => $to];
    }
    return $out;
}

/** A stored column value as a short readable string. */
function history_value(mixed $v): string {
    if ($v === null || $v === '') return '—';
    if (is_bool($v)) return $v ? t('ja', 'yes') : t('nein', 'no');
    $s = (string)$v;
    return mb_strlen($s) > 60 ? mb_substr($s, 0, 57).'…' : $s;
}

/**
 * Trim the log so it cannot grow without end.
 *
 * Kept by age rather than by count: "what changed in the last year" is the
 * question anybody asks of it, and a count would silently drop the history of a
 * quiet record because a busy one filled the table.
 */
function history_prune(int $months = 24): int {
    $before = (new DateTimeImmutable(now()))->modify('-' . max(1, $months) . ' months')->format('Y-m-d H:i:s');
    return run('DELETE FROM record_versions WHERE created_at < ?', [$before])->rowCount();
}
