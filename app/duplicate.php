<?php
declare(strict_types=1);

/**
 * Copying a record she has already set up.
 *
 * Two tariffs that differ in one number, a second course on another evening, a
 * template that is last year's with three words changed: each of them is ten
 * minutes of retyping, and retyping is where a wrong price comes from. Every
 * list in the portal that she builds by hand offers a copy now.
 *
 * What may be copied is declared here rather than worked out from the schema,
 * because "what belongs to this record" is a judgement and not a foreign key. A
 * course's training days are part of the course; the children enrolled in it are
 * not, and a generic copy that followed the keys would have taken them too.
 */

/**
 * The records that may be copied, and what comes with each.
 *
 *   'title'    the column holding the name, which the copy gets a variation of
 *   'scope'    the column a name has to be unique within, if any
 *   'reset'    columns the copy does not inherit: an archived record's copy is
 *              not archived, an example record's copy is not an example
 *   'children' tables whose rows belong to this record, keyed by their pointer
 *   'deep'     children that are themselves copyable records, copied by this
 *              same function so that their own children come too
 */
function duplicable_records(): array {
    return [
        'tariffs' => [
            'title' => 'name', 'scope' => 'class_id', 'reset' => ['archived' => 0, 'is_demo' => 0],
            'children' => ['tariff_rates' => 'tariff_id', 'tariff_discounts' => 'tariff_id'],
        ],
        'classes' => [
            'title' => 'name', 'reset' => ['archived' => 0, 'is_demo' => 0, 'created_at' => null],
            'children' => ['class_days' => 'class_id'],
            'deep' => ['tariffs' => 'class_id'],
        ],
        'message_templates' => ['title' => 'name'],
        'levels'            => ['title' => 'name', 'reset' => ['archived' => 0, 'is_default' => 0, 'created_at' => null]],
        'age_groups'        => ['title' => 'name', 'reset' => ['archived' => 0, 'created_at' => null]],
        'payment_profiles'  => ['title' => 'name', 'reset' => ['archived' => 0, 'created_at' => null]],
        'field_definitions' => ['title' => 'label', 'reset' => ['archived' => 0]],
        // A news item is copied as a draft on purpose: the commonest reason to
        // copy one is last year's notice, and the commonest mistake would be
        // publishing it before the dates in it have been changed.
        'news'              => ['title' => 'title', 'reset' => ['published' => 0, 'is_demo' => 0, 'created_at' => null]],
    ];
}

function duplicable_record(string $table): array {
    $all = duplicable_records();
    if (!isset($all[$table])) throw new UserError(t('Das lässt sich nicht kopieren.', 'That cannot be copied.'));
    return $all[$table] + ['title' => 'name', 'scope' => null, 'reset' => [], 'children' => [], 'deep' => []];
}

/**
 * Copy one record, with everything that belongs to it, and return its id.
 *
 * The copy is never archived and never published, so it cannot be mistaken for
 * the original by anybody but the person who just pressed the button - and it
 * gets a name saying what it is, because two rows reading exactly the same on a
 * list is a choice nobody can make.
 *
 * $table is checked against the list above before it reaches any SQL; the
 * column names come from the row the database returned, and go through
 * sql_name() as well, because "it came from the database" is how an allowlist
 * stops being one.
 */
function duplicate_record(string $table, int $id, bool $rename = true): int {
    $spec = duplicable_record($table);
    $source = one('SELECT * FROM ' . sql_name($table, 'table') . ' WHERE id=?', [$id]);
    if (!$source) throw new NotFound(t('Diesen Eintrag gibt es nicht mehr.', 'That record no longer exists.'));

    return transactional(function () use ($table, $id, $spec, $source, $rename) {
        $row = $source;
        unset($row['id']);
        foreach ($spec['reset'] as $column => $value)
            if (array_key_exists($column, $row)) $row[$column] = $value === null ? now() : $value;
        if ($rename && isset($row[$spec['title']]))
            $row[$spec['title']] = copy_name((string)$row[$spec['title']], duplicate_taken($table, $spec, $source));

        $copy = insert_row($table, $row);
        foreach ($spec['children'] as $child => $pointer)
            foreach (rows('SELECT * FROM ' . sql_name($child, 'table')
                     . ' WHERE ' . sql_name($pointer, 'column') . '=?', [$id]) as $childRow) {
                unset($childRow['id']);
                $childRow[$pointer] = $copy;
                insert_row($child, $childRow);
            }
        // A deep child is a record in its own right, so it is copied by this
        // same function and brings its own children with it. Not renamed: a
        // course's tariffs are already distinguished by the course they are in,
        // and "Beitrag (Kopie)" inside "Kindertraining (Kopie)" says the same
        // thing twice.
        foreach ($spec['deep'] as $child => $pointer)
            foreach (rows('SELECT id FROM ' . sql_name($child, 'table')
                     . ' WHERE ' . sql_name($pointer, 'column') . '=?', [$id]) as $childRow) {
                $childCopy = duplicate_record($child, (int)$childRow['id'], false);
                run('UPDATE ' . sql_name($child, 'table') . ' SET ' . sql_name($pointer, 'column') . '=? WHERE id=?',
                    [$copy, $childCopy]);
            }
        audit($table . '.duplicated', $table, $copy);
        return $copy;
    });
}

/** The names already in use where this copy will sit, so it can avoid them. */
function duplicate_taken(string $table, array $spec, array $source): array {
    $where = ''; $args = [];
    if ($spec['scope'] !== null && array_key_exists($spec['scope'], $source)) {
        $column = sql_name($spec['scope'], 'column');
        if ($source[$spec['scope']] === null) $where = ' WHERE ' . $column . ' IS NULL';
        else { $where = ' WHERE ' . $column . '=?'; $args = [$source[$spec['scope']]]; }
    }
    return array_column(rows('SELECT ' . sql_name($spec['title'], 'column') . ' AS n FROM '
        . sql_name($table, 'table') . $where, $args), 'n');
}

/**
 * Insert one row given as column => value, and return its id.
 *
 * The columns are whatever the row being copied had, so they are named by the
 * database rather than by a person - but they are still validated, because the
 * moment an allowlist has an exception is the moment it stops being one.
 */
function insert_row(string $table, array $row): int {
    $columns = array_map(fn($c) => sql_name((string)$c, 'column'), array_keys($row));
    run('INSERT INTO ' . sql_name($table, 'table') . ' (' . implode(',', $columns) . ')'
        . ' VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')', array_values($row));
    return (int)db()->lastInsertId();
}

/**
 * Where the copy is opened, so she lands on the thing she has to change.
 *
 * A copy that is made and then left on a list somewhere is a copy she has to go
 * and find; the point of pressing the button is to edit it.
 */
function duplicate_destination(string $table, int $copy): array {
    return match ($table) {
        'tariffs' => ['classes', ['id' => (int)(scalar('SELECT class_id FROM tariffs WHERE id=?', [$copy]) ?? 0),
                                  'tab' => 'tariffs', 'tariff' => $copy]],
        'classes' => ['classes', ['id' => $copy, 'edit' => 1]],
        'message_templates' => ['manage', ['tab' => 'templates', 'edit' => $copy]],
        'levels'            => ['manage', ['tab' => 'levels', 'edit' => $copy]],
        'age_groups'        => ['manage', ['tab' => 'ages', 'edit' => $copy]],
        'payment_profiles'  => ['manage', ['tab' => 'payments', 'edit' => $copy]],
        'field_definitions' => ['settings', ['tab' => 'fields', 'edit' => $copy]],
        'news'              => ['news', ['id' => $copy, 'edit' => 1]],
        default             => ['dashboard', []],
    };
}

/** The button that copies one record, for any list that offers one. */
function duplicate_button(string $table, int $id, string $label = ''): void {
    start_form('record_duplicate', ['table' => $table, 'id' => $id], 'inline-form');
    submit_button($label !== '' ? $label : t('Kopieren', 'Duplicate'), 'secondary');
    echo '</form>';
}
