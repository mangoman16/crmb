<?php
declare(strict_types=1);

/**
 * The two ways a trainer groups children: how far along they are, and how old.
 *
 * Both are ordinary lists she edits herself. Keeping them as rows rather than as
 * settings is what lets a student point at one, so renaming "Anfänger" to
 * "Einsteiger" renames it everywhere at once instead of leaving old records
 * holding a word that no longer exists anywhere else.
 *
 * The difference between them is worth stating, because it is the thing that
 * gets confused: a level is chosen, an age group is worked out. A level has a
 * default so a new child is never blank; an age group has none, because the
 * date of birth already answers it and answers it again next birthday.
 */

/** Levels, in the operator's order. */
function levels(bool $archived=false): array {
    return rows('SELECT * FROM levels'.($archived?'':' WHERE archived=0').' ORDER BY sort_order, name, id');
}

/** The level a new student starts at. Null only if every level has been archived. */
function level_default(): ?array {
    return one('SELECT * FROM levels WHERE is_default=1 AND archived=0 ORDER BY sort_order, id')
        ?: one('SELECT * FROM levels WHERE archived=0 ORDER BY sort_order, id');
}

function level_name(?int $id): string {
    if (!$id) return t('Keine Gruppe', 'No level');
    return (string)(scalar('SELECT name FROM levels WHERE id=?', [$id]) ?: t('Keine Gruppe', 'No level'));
}

/** Age groups, youngest band first. */
function age_groups(bool $archived=false): array {
    return rows('SELECT * FROM age_groups'.($archived?'':' WHERE archived=0').' ORDER BY sort_order, min_age, id');
}

/**
 * How a band reads to a person: "12 bis 17", "18 und älter", "bis 11".
 *
 * Built here rather than stored, so editing the numbers changes the wording with
 * them and the two can never disagree.
 */
function age_group_range(array $group): string {
    $min = (int)$group['min_age'];
    $max = $group['max_age'];
    if ($max === null)            return $min <= 0 ? t('jedes Alter', 'any age') : $min.' '.t('und älter', 'and older');
    if ($min <= 0)                return t('bis ', 'up to ').(int)$max;
    return $min.' '.t('bis', 'to').' '.(int)$max;
}

/** Completed years on a date of birth, or null when none is recorded. */
function student_age(?string $birthDate, ?string $on=null): ?int {
    if ($birthDate === null || $birthDate === '') return null;
    $born = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
    if (!$born || $born->format('Y-m-d') !== $birthDate) return null;
    $today = new DateTimeImmutable($on ?? today());
    if ($born > $today) return null;
    return (int)$born->diff($today)->y;
}

/**
 * The band an age falls into, or null when none covers it.
 *
 * First match in the operator's own order wins, so overlapping bands are decided
 * by the order she put them in rather than by whichever the database returned
 * first. Bands that do not cover everybody are her business to notice; inventing
 * a fallback would hide a gap she asked for.
 */
function age_group_for_age(?int $age, ?array $groups=null): ?array {
    if ($age === null) return null;
    foreach ($groups ?? age_groups() as $group)
        if ($age >= (int)$group['min_age'] && ($group['max_age'] === null || $age <= (int)$group['max_age']))
            return $group;
    return null;
}

/**
 * Which age group one student is in, and whether that was decided or worked out.
 *
 * Returns ['group'=>?array, 'pinned'=>bool]. A pinned group survives a birthday;
 * an unpinned one moves with it, which is what "auto detect, changeable" has to
 * mean for a list of children that is read for years.
 */
function student_age_group(array $student, ?array $groups=null): array {
    if (!empty($student['age_group_id'])) {
        $pinned = one('SELECT * FROM age_groups WHERE id=?', [(int)$student['age_group_id']]);
        if ($pinned) return ['group'=>$pinned, 'pinned'=>true];
    }
    return ['group'=>age_group_for_age(student_age($student['birth_date'] ?? null), $groups), 'pinned'=>false];
}

/** That group's name, with the reason it is blank when it is. */
function age_group_name(array $student, ?array $groups=null): string {
    $resolved = student_age_group($student, $groups);
    if ($resolved['group']) return (string)$resolved['group']['name'];
    return student_age($student['birth_date'] ?? null) === null
        ? t('Kein Geburtsdatum', 'No date of birth')
        : t('Keine passende Gruppe', 'No band covers this age');
}

/**
 * Bands that overlap or leave a gap, as sentences for the person editing them.
 *
 * Not an error: overlapping bands are allowed and the first match wins. Saying
 * so beats refusing an arrangement she meant, and beats silence when she did
 * not.
 */
function age_group_warnings(?array $groups=null): array {
    $groups = $groups ?? age_groups();
    $warnings = [];
    $previous = null;
    foreach ($groups as $group) {
        if ($group['max_age'] !== null && (int)$group['max_age'] < (int)$group['min_age'])
            $warnings[] = $group['name'].': '.t('das Höchstalter liegt unter dem Mindestalter.', 'the upper age is below the lower one.');
        if ($previous !== null) {
            if ($previous['max_age'] === null)
                $warnings[] = $previous['name'].' '.t('reicht nach oben offen, daher wird','is open-ended, so').' '.$group['name'].' '.t('nie erreicht.', 'is never reached.');
            elseif ((int)$group['min_age'] <= (int)$previous['max_age'])
                $warnings[] = $previous['name'].' '.t('und','and').' '.$group['name'].' '.t('überschneiden sich; die erste Gruppe gewinnt.', 'overlap; the first one wins.');
            elseif ((int)$group['min_age'] > (int)$previous['max_age'] + 1)
                $warnings[] = t('Zwischen ','Between ').$previous['name'].t(' und ',' and ').$group['name'].t(' fehlt ein Jahrgang.', ' a year is not covered.');
        }
        $previous = $group;
    }
    if ($groups && (int)$groups[0]['min_age'] > 0)
        $warnings[] = t('Kinder unter ','Children under ').(int)$groups[0]['min_age'].t(' Jahren fallen in keine Gruppe.', ' fall into no group.');
    if ($groups && end($groups)['max_age'] !== null)
        $warnings[] = t('Über ','Above ').(int)end($groups)['max_age'].t(' Jahren fällt niemand in eine Gruppe.', ' nobody falls into a group.');
    return $warnings;
}

/**
 * How many students are in each level and each age group.
 *
 * Shown beside the lists so that archiving or renaming one is done with its
 * weight visible. Age groups are counted in PHP because most students have no
 * pinned group and the band is worked out from a date.
 */
function group_usage(): array {
    $levels = [];
    foreach (rows('SELECT level_id, COUNT(*) AS n FROM students GROUP BY level_id') as $r)
        $levels[(int)$r['level_id']] = (int)$r['n'];
    $bands = age_groups(true);
    $ages = array_fill_keys(array_map(fn($g) => (int)$g['id'], $bands), 0);
    $unplaced = 0;
    foreach (rows('SELECT birth_date, age_group_id FROM students') as $s) {
        $group = student_age_group($s, $bands)['group'];
        if ($group) $ages[(int)$group['id']]++; else $unplaced++;
    }
    return ['levels'=>$levels, 'age_groups'=>$ages, 'unplaced'=>$unplaced];
}
