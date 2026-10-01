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
    // A custom field's value, recorded with the student it belongs to
    // (entity_snapshot()), is named as the settings page names the field. That
    // reads field_definitions through app/domain.php, which is loaded after
    // this file - safe, because this runs only while a page is drawn, never
    // while files load. The same arrangement as avatar() calling
    // upload_version().
    if (preg_match('/^field:([0-9]+)$/D', $column, $m)) {
        $field = one('SELECT label,label_en FROM field_definitions WHERE id=?', [(int)$m[1]]);
        return $field ? field_label($field) : t('Gelöschtes eigenes Feld', 'Deleted custom field');
    }
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
        // The student's own login (ADR 0010), not a family account they are filed under.
        'account_id' => t('Konto (Zugang)', 'Account (login)'),
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
        'username' => t('Benutzername', 'Username'),
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
        // What a family writes on its own page (ADR 0020, §7). 'phone' is the
        // student's and a contact's alike.
        'address' => t('Anschrift', 'Postal address'),
        'phone' => t('Telefonnummer', 'Telephone number'),
        'owner_name' => t('Name', 'Name'),
        'relation_label' => t('Beziehung', 'Relationship'),
        'is_primary' => t('Standardkontakt', 'Standard contact'),
        default => $column,
    };
}

/**
 * Tables that may be versioned, and how to describe one to a person.
 *
 * An allowlist rather than "any table": entity_snapshot() reads SELECT * from a
 * name that reaches it from a caller, so the set of tables it can touch belongs
 * in one visible place.
 *
 * 'hidden' names columns a line about that entity does not show. A contact's
 * student_id only repeats the label, which already names the child; on a
 * charge it is the one thing saying whose charge it was, so it is hidden for
 * contacts only (ADR 0020, §10b).
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
        'contacts'         => ['label' => ['Kontakt', 'Contact'],            'title' => ['owner_name'], 'hidden' => ['student_id']],
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

/**
 * The current state of a row, or null when it does not exist.
 *
 * A student's custom fields are part of the student (ADR 0020, §7):
 * field_values has no id of its own to be tracked by, so each of its rows for
 * this student is added as a pseudo-column field:<field_id> holding its
 * value_json. A save of the student and its fields is then one line, and a
 * deleted student's line keeps their custom values. A value that is not filled
 * in, by custom_value_empty() - the rule a required field is refused by, in
 * app/domain.php, called only while a request runs, as history_field_label()
 * calls field_label() - is
 * left out, so a save that merely wrote empty rows for fields nobody filled in
 * is no change. An unticked box is one of those: unticking reads „ja → —“, not
 * „ja → nein“. These keys never reach SQL: there is no undo to write them back.
 */
function entity_snapshot(string $entity, int $id): ?array {
    tracked_entity($entity);
    $row = one('SELECT * FROM '.$entity.' WHERE id=?', [$id]);
    if ($row === null || $entity !== 'students') return $row;
    foreach (rows('SELECT field_id,value_json FROM field_values WHERE student_id=? ORDER BY field_id', [$id]) as $value)
        if (!custom_value_empty(json_decode((string)$value['value_json'], true)))
            $row['field:'.(int)$value['field_id']] = (string)$value['value_json'];
    return $row;
}

/**
 * Columns of a table that the change log never holds, whatever the operation.
 *
 * A password hash is a secret, and a copy in record_versions would outlive every
 * change of password; auth_version and last_seen_at change without anybody
 * editing anything and say nothing she could act on. Stripped from both sides
 * of an insert, an update and a delete alike (ADR 0019, S6 and R5): stripping
 * them on update only would still write the hash with every new or deleted
 * login, and a future tracked('accounts', …) around a password write would
 * put it in the log.
 */
function history_never_recorded(string $entity): array {
    return match ($entity) {
        'accounts' => ['password_hash', 'auth_version', 'last_seen_at'],
        default => [],
    };
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
 *
 * The actor is who is really signed in (audit()'s rule), unless the caller
 * names one: $actor exists for the one change made before anybody is signed in
 * - the username chosen while accepting an invitation, whose actor is the
 * holder of the link (change_own_username()). Nothing else passes it.
 */
function history_record(string $entity, int $id, string $operation, string $label, ?array $before, ?array $after, ?int $actor = null): int {
    tracked_entity($entity);
    $never = array_flip(history_never_recorded($entity));
    if ($before !== null) $before = array_diff_key($before, $never);
    if ($after !== null) $after = array_diff_key($after, $never);
    if ($operation === 'update' && $before !== null && $after !== null) {
        $differing = [];
        // Both sides' columns: a custom field filled in for the first time is
        // on the after side only (entity_snapshot()).
        foreach (array_keys($before + $after) as $column)
            if ((string)($before[$column] ?? null) !== (string)($after[$column] ?? null)) $differing[$column] = true;
        $before = array_intersect_key($before, $differing);
        $after  = array_intersect_key($after,  $differing);
    }
    run('INSERT INTO record_versions (entity,entity_id,operation,label,before_json,after_json,actor_id,created_at)'
        .' VALUES (?,?,?,?,?,?,?,?)',
        [$entity, $id, $operation, mb_substr($label, 0, 160),
         $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
         $after  === null ? null : json_encode($after,  JSON_UNESCAPED_UNICODE),
         $actor ?? acting_account_id(), now()]);
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
 * $actor is history_record()'s, for the one caller that has to name it.
 */
function tracked(string $entity, int $id, string $label, callable $mutate, string $operation = 'update', ?int $actor = null): mixed {
    tracked_entity($entity);
    return transactional(function () use ($entity, $id, $label, $mutate, $operation, $actor) {
        $before = entity_snapshot($entity, $id);
        $result = $mutate();
        $after = $operation === 'delete' ? null : entity_snapshot($entity, $id);
        history_record($entity, $id, $operation, $label, $before, $after, $actor);
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

/**
 * Versions for one record, newest first, each with its actor's name and role
 * as they are now - read when the page is read, not stored with the change.
 */
function history_for(string $entity, int $id, int $limit = 50): array {
    tracked_entity($entity);
    return rows('SELECT v.*, a.name AS actor_name, a.role AS actor_role FROM record_versions v LEFT JOIN accounts a ON a.id=v.actor_id'
        .' WHERE v.entity=? AND v.entity_id=? ORDER BY v.id DESC LIMIT '.max(1, min(200, $limit)), [$entity, $id]);
}

/**
 * The most recent changes across every tracked record, for the admin view.
 *
 * $familiesOnly is the „Von Familien" tab (ADR 0020, §10b): changes whose actor
 * is a family's login. A change made while staff viewed the portal as a family
 * is recorded under the staff member (history_record()), so it is not one of
 * them; one whose login has since been deleted has no actor, and shows under
 * „Alle" only.
 */
function history_recent(int $limit = 60, bool $familiesOnly = false): array {
    return rows('SELECT v.*, a.name AS actor_name, a.role AS actor_role FROM record_versions v LEFT JOIN accounts a ON a.id=v.actor_id'
        .($familiesOnly ? " WHERE a.role='student'" : '')
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
    $hidden = isset(tracked_entities()[$version['entity'] ?? '']) ? (tracked_entity($version['entity'])['hidden'] ?? []) : [];
    $out = [];
    foreach ($columns as $column) {
        // Bookkeeping columns change on every save and say nothing about what
        // was actually edited.
        if (in_array($column, ['id', 'updated_at', 'created_at', 'revision'], true)) continue;
        if (in_array($column, $hidden, true)) continue;
        $from = $before[$column] ?? null;
        $to   = $after[$column]  ?? null;
        if ((string)$from === (string)$to) continue;
        $out[$column] = ['from' => $from, 'to' => $to];
    }
    return $out;
}

/**
 * A stored column value as a short readable string.
 *
 * $column is the field it came from, for the values whose raw form means
 * nothing to her: a login is stored as a number and read as its username, and
 * a custom field (field:<id>) as JSON, read as its text, its options joined with
 * commas, or ja / nein for a box. A calendar date - a value shaped exactly
 * YYYY-MM-DD, a custom date field's included - reads as she writes one;
 * fmt_date() does not shift a DATE, so it stays the day it was. A DATETIME has
 * a time part and is left alone (ADR 0020, §10b).
 */
function history_value(mixed $v, string $column = ''): string {
    if (str_starts_with($column, 'field:') && is_string($v)) {
        $v = json_decode($v, true);
        if (is_array($v)) $v = implode(', ', array_map('strval', $v));
    }
    if ($v === null || $v === '') return '—';
    if ($column === 'account_id') return history_login((int)$v);
    if (is_bool($v)) return $v ? t('ja', 'yes') : t('nein', 'no');
    $s = (string)$v;
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $s) && local_time($s)) return fmt_date($s);
    return mb_strlen($s) > 60 ? mb_substr($s, 0, 57).'…' : $s;
}

/**
 * A login as the change log names it: its username, or that it no longer exists.
 *
 * The username, because that is what the login is called wherever staff see it
 * (ADR 0019, §8); the address would say as much now that none is shared (ADR
 * 0020), but two names for one thing in one log is churn. Looked up when the page is
 * read rather than stored with the change, because its holder can change it
 * and the line should name the login as it is now. Not memoised: a login in the
 * log is rare, and a memo would outlive a language switch.
 */
function history_login(int $accountId): string {
    $username = scalar('SELECT username FROM accounts WHERE id=?', [$accountId]);
    return $username !== false && $username !== null ? (string)$username : t('gelöschter Zugang', 'deleted login');
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
