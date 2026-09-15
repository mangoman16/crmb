<?php
declare(strict_types=1);

/**
 * Record versioning and undo.
 *
 * Wrap a change in tracked() and the row's state before and after is stored, so
 * the change can be shown to the operator and put back. This is what makes a
 * mis-tap survivable: deleting the wrong student is a mistake to undo rather
 * than a restore from backup.
 *
 * It is not the audit log. audit_log says that something happened and is never
 * rewritten; this says what the row looked like and exists to be acted on.
 *
 * Reverting is an ordinary change: it writes the old values back and records a
 * new version doing so. Nothing is ever removed from the history, so the record
 * of what happened stays complete even after an undo.
 */

/**
 * Tables that may be versioned, and how to describe one to a person.
 *
 * An allowlist rather than "any table": revert_version() writes columns straight
 * back, so the set of tables it can touch is a security boundary and belongs in
 * one visible place.
 */
function tracked_entities(): array {
    return [
        'students'         => ['label' => ['Schüler', 'Student'],            'title' => ['first_name', 'last_name']],
        'charges'          => ['label' => ['Beitrag', 'Charge'],             'title' => ['label']],
        'payments'         => ['label' => ['Zahlung', 'Payment'],            'title' => ['method']],
        'classes'          => ['label' => ['Kurs', 'Class'],                 'title' => ['name']],
        'tariffs'          => ['label' => ['Tarif', 'Tariff'],               'title' => ['name']],
        'payment_profiles' => ['label' => ['Zahlungsempfänger', 'Payment profile'], 'title' => ['name']],
        'skills'           => ['label' => ['Fähigkeit', 'Skill'],            'title' => ['name']],
        'skill_areas'      => ['label' => ['Bereich', 'Skill area'],         'title' => ['name']],
        'rating_scales'    => ['label' => ['Skala', 'Rating scale'],         'title' => ['name']],
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

/** Write one version row. Called by tracked(); rarely useful on its own. */
function history_record(string $entity, int $id, string $operation, string $label, ?array $before, ?array $after): int {
    tracked_entity($entity);
    run('INSERT INTO record_versions (entity,entity_id,operation,label,before_json,after_json,actor_id,created_at)'
        .' VALUES (?,?,?,?,?,?,?,?)',
        [$entity, $id, $operation, mb_substr($label, 0, 160),
         $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
         $after  === null ? null : json_encode($after,  JSON_UNESCAPED_UNICODE),
         current_user()['id'] ?? null, now()]);
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
 * Used to show a change rather than two opaque blobs, and to keep the revert
 * narrow: only the columns a change touched are written back, so reverting an
 * old edit does not also undo every later one.
 */
function version_changes(array $version): array {
    $before = $version['before_json'] ? json_decode($version['before_json'], true) : null;
    $after  = $version['after_json']  ? json_decode($version['after_json'],  true) : null;
    $columns = array_keys(($before ?? []) + ($after ?? []));
    $out = [];
    foreach ($columns as $column) {
        if (in_array($column, ['id', 'updated_at', 'revision'], true)) continue;
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
 * Put a change back.
 *
 * An update is reversed by writing the columns it changed back to their old
 * values — not the whole row, so a later edit to a different column survives.
 * A creation is reversed by deleting the row. A deletion is reversed by
 * re-inserting it under its original id, so anything that referenced it lines
 * up again.
 *
 * The reversal is recorded as a new version and the original is marked, which
 * is what stops it being applied twice.
 */
function revert_version(int $versionId): void {
    transactional(function () use ($versionId) {
        $v = one('SELECT * FROM record_versions WHERE id=? FOR UPDATE', [$versionId]);
        if (!$v) throw new UserError(t('Diese Änderung gibt es nicht.', 'No such change.'));
        if ($v['reverted_at'] !== null) throw new UserError(t('Diese Änderung wurde bereits zurückgenommen.', 'That change has already been undone.'));
        $entity = (string)$v['entity'];
        tracked_entity($entity);
        $id = (int)$v['entity_id'];
        $before = $v['before_json'] ? json_decode($v['before_json'], true) : null;

        if ($v['operation'] === 'insert') {
            if (!entity_snapshot($entity, $id)) throw new UserError(t('Der Eintrag ist bereits entfernt.', 'That record is already gone.'));
            run('DELETE FROM '.$entity.' WHERE id=?', [$id]);
            history_record($entity, $id, 'revert', t('Anlegen zurückgenommen', 'Creation undone'), $v['after_json'] ? json_decode($v['after_json'], true) : null, null);

        } elseif ($v['operation'] === 'delete') {
            if (!$before) throw new UserError(t('Für diese Löschung ist kein Stand gespeichert.', 'No stored state for that deletion.'));
            if (entity_snapshot($entity, $id)) throw new UserError(t('Es gibt bereits wieder einen Eintrag mit dieser Nummer.', 'A record with that number exists again.'));
            $columns = array_keys($before);
            foreach ($columns as $column) if (!preg_match('/^[a-z_][a-z0-9_]*$/D', (string)$column))
                throw new RuntimeException('Refusing to restore a column named '.var_export($column, true));
            run('INSERT INTO '.$entity.' ('.implode(',', array_map(fn($c) => '`'.$c.'`', $columns)).')'
                .' VALUES ('.implode(',', array_fill(0, count($columns), '?')).')', array_values($before));
            history_record($entity, $id, 'revert', t('Löschen zurückgenommen', 'Deletion undone'), null, entity_snapshot($entity, $id));

        } else {
            if (!$before) throw new UserError(t('Für diese Änderung ist kein Stand gespeichert.', 'No stored state for that change.'));
            $current = entity_snapshot($entity, $id);
            if (!$current) throw new UserError(t('Der Eintrag existiert nicht mehr.', 'That record no longer exists.'));
            $changes = version_changes($v);
            if (!$changes) throw new UserError(t('An dieser Änderung gibt es nichts zurückzunehmen.', 'There is nothing to undo in that change.'));
            $set = []; $args = [];
            foreach (array_keys($changes) as $column) {
                if (!preg_match('/^[a-z_][a-z0-9_]*$/D', (string)$column))
                    throw new RuntimeException('Refusing to restore a column named '.var_export($column, true));
                $set[] = '`'.$column.'`=?'; $args[] = $before[$column];
            }
            $args[] = $id;
            run('UPDATE '.$entity.' SET '.implode(',', $set).' WHERE id=?', $args);
            history_record($entity, $id, 'revert', t('Änderung zurückgenommen', 'Change undone'), $current, entity_snapshot($entity, $id));
        }

        run('UPDATE record_versions SET reverted_at=?,reverted_by=? WHERE id=?', [now(), current_user()['id'] ?? null, $versionId]);
        audit('record.reverted', $entity, $id);
    });
}
