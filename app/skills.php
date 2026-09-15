<?php
declare(strict_types=1);

/**
 * Skill assessment.
 *
 * Skills live in areas (Technik, Kondition, ...) and are scored on a named
 * scale, so "Genauigkeit 0-10" is configuration rather than code. Assessments
 * are dated, which is what makes progress a line rather than a single number.
 *
 * Assessments are staff-only throughout. Nothing here is rendered for a student
 * account - see the visibility note in ROADMAP.md if that ever changes.
 */

function rating_scales(bool $archived=false): array {
    return rows('SELECT * FROM rating_scales'.($archived?'':' WHERE archived=0').' ORDER BY name, id');
}
function skill_areas(bool $archived=false): array {
    return rows('SELECT * FROM skill_areas'.($archived?'':' WHERE archived=0').' ORDER BY sort_order, name, id');
}
function skills(bool $archived=false, ?int $areaId=null): array {
    $where = $archived ? [] : ['s.archived=0'];
    $params = [];
    if ($areaId !== null) { $where[]='s.area_id=?'; $params[]=$areaId; }
    return rows('SELECT s.*, a.name AS area_name, a.sort_order AS area_order, r.name AS scale_name,'
        .' r.min_value, r.max_value, r.step, r.labels_json FROM skills s'
        .' JOIN skill_areas a ON a.id=s.area_id JOIN rating_scales r ON r.id=s.scale_id'
        .($where?' WHERE '.implode(' AND ',$where):'')
        .' ORDER BY a.sort_order, a.name, s.sort_order, s.name, s.id', $params);
}

/** Labels for a scale, keyed by value, for scales that name their steps. */
function scale_labels(array $skill): array {
    $labels = json_decode((string)($skill['labels_json'] ?? '[]'), true);
    return is_array($labels) ? $labels : [];
}

/** Every allowed value on a skill's scale, as strings for use in a select. */
function scale_values(array $skill): array {
    $min=(float)$skill['min_value']; $max=(float)$skill['max_value']; $step=(float)$skill['step'];
    if ($step <= 0 || $max <= $min) return [];
    $labels = scale_labels($skill);
    $out = [];
    // Cap the option count so a mis-entered scale cannot render a huge form.
    for ($v=$min, $i=0; $v <= $max + 1e-9 && $i < 200; $v += $step, $i++) {
        $key = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        $out[$key] = $labels[$key] ?? $key;
    }
    return $out;
}

/** Format a stored value the way its scale presents it. */
function scale_format(array $skill, ?float $value): string {
    if ($value === null) return '–';
    $key = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    $labels = scale_labels($skill);
    if (isset($labels[$key])) return (string)$labels[$key];
    $max = rtrim(rtrim(number_format((float)$skill['max_value'], 2, '.', ''), '0'), '.');
    return $key.' / '.$max;
}

/**
 * Where a value sits on its scale, 0..100.
 *
 * Banding and the progress chart both work in percent so that skills on
 * different scales stay comparable inside one area.
 */
function scale_percent(array $skill, float $value): float {
    $min=(float)$skill['min_value']; $max=(float)$skill['max_value'];
    if ($max <= $min) return 0.0;
    return max(0.0, min(100.0, ($value - $min) / ($max - $min) * 100));
}

/** The most recent assessment per skill for one student, keyed by skill id. */
function latest_assessments(int $studentId): array {
    $rows = rows('SELECT a.* FROM assessments a JOIN ('
        .'SELECT skill_id, MAX(assessed_on) AS on_date FROM assessments WHERE student_id=? GROUP BY skill_id'
        .') m ON m.skill_id=a.skill_id AND m.on_date=a.assessed_on WHERE a.student_id=?', [$studentId, $studentId]);
    $out=[];
    foreach ($rows as $r) $out[(int)$r['skill_id']]=$r;
    return $out;
}

/** Full dated history for one student and skill, oldest first, for charting. */
function assessment_history(int $studentId, int $skillId): array {
    return rows('SELECT value, assessed_on, note FROM assessments WHERE student_id=? AND skill_id=? ORDER BY assessed_on', [$studentId, $skillId]);
}

/**
 * Average percent per area for one student, from current assessments only.
 *
 * Assessments older than assessment_window_days are ignored, so a band reflects
 * where a student is now rather than where they were two seasons ago.
 */
function area_scores(int $studentId): array {
    $cutoff = gmdate('Y-m-d', time() - ((int)setting('assessment_window_days')) * 86400);
    $latest = latest_assessments($studentId);
    $byArea = [];
    foreach (skills() as $skill) {
        $a = $latest[(int)$skill['id']] ?? null;
        if (!$a || $a['assessed_on'] < $cutoff) continue;
        $byArea[(int)$skill['area_id']]['name'] = $skill['area_name'];
        $byArea[(int)$skill['area_id']]['values'][] = scale_percent($skill, (float)$a['value']);
    }
    $out = [];
    foreach ($byArea as $areaId => $data) {
        $avg = array_sum($data['values']) / count($data['values']);
        $out[$areaId] = ['name'=>$data['name'], 'percent'=>$avg, 'count'=>count($data['values']), 'band'=>skill_band($avg)];
    }
    return $out;
}

/**
 * Name the band a percentage falls into.
 *
 * Thresholds are an operator setting, so renaming or re-cutting the groups
 * re-bands everyone immediately with no migration and no code change.
 */
function skill_band(float $percent): string {
    $bands = (array)setting('skill_bands');
    $best = ''; $bestAt = -1.0;
    foreach ($bands as $from => $label) {
        $from = (float)$from;
        if ($percent + 1e-9 >= $from && $from >= $bestAt) { $bestAt = $from; $best = (string)$label; }
    }
    return $best;
}

/**
 * A progress chart as inline SVG.
 *
 * Inline rather than a charting library: the CSP allows no third-party script,
 * and currentColor keeps the line readable in both themes. Values are plotted as
 * percent of scale so the y-axis means the same thing for every skill.
 */
function progress_chart(array $skill, array $history, int $width=320, int $height=90): string {
    if (count($history) < 1) return '';
    $pad = 6;
    $inner = $width - $pad * 2;
    $points = [];
    $n = count($history);
    foreach (array_values($history) as $i => $h) {
        $x = $pad + ($n === 1 ? $inner / 2 : $inner * $i / ($n - 1));
        $pct = scale_percent($skill, (float)$h['value']);
        $y = $pad + (100 - $pct) / 100 * ($height - $pad * 2);
        $points[] = [round($x, 1), round($y, 1)];
    }
    $line = implode(' ', array_map(fn($p) => $p[0].','.$p[1], $points));
    $area = $line.' '.$points[count($points)-1][0].','.($height-$pad).' '.$points[0][0].','.($height-$pad);
    $dots = '';
    foreach ($points as $i => $p) {
        $h = array_values($history)[$i];
        $dots .= '<circle cx="'.$p[0].'" cy="'.$p[1].'" r="'.($i === count($points)-1 ? 3.5 : 2.2).'"'
            .' fill="currentColor"><title>'.e(fmt_date($h['assessed_on']).': '.scale_format($skill, (float)$h['value'])).'</title></circle>';
    }
    return '<svg class="spark" viewBox="0 0 '.$width.' '.$height.'" width="100%" height="'.$height.'" role="img"'
        .' aria-label="'.e(t('Verlauf','Progress')).'" preserveAspectRatio="none">'
        .'<polyline points="'.$area.'" fill="currentColor" opacity=".10" stroke="none"/>'
        .'<polyline points="'.$line.'" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>'
        .$dots.'</svg>';
}
