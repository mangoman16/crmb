<?php
declare(strict_types=1);

/**
 * Input validation shared across the application.
 *
 * These live here rather than beside the actions that use them because they are
 * reusable rules, not behaviour: the console needs valid_iban() as much as the
 * web interface does, and a rule reachable through only one entry point cannot
 * be tested on its own.
 *
 * Each one either returns a value that is safe to store, or throws UserError
 * with a message meant for the person who typed it.
 */

/** HH:MM from a form, or null when left empty. */
function time_value(string $value): ?string {
    if(trim($value)==='') return null;
    if(!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D',trim($value),$m)) throw new UserError(t('Bitte eine Uhrzeit als HH:MM eingeben.','Please enter a time as HH:MM.'));
    return $m[0].':00';
}

/**
 * The time posted by one time_field(), or null when it was left blank.
 *
 * The hour and the minute arrive as two boxes, because <input type="time">
 * renders in the language of the device rather than of the page. Half a time is
 * a mistake worth naming: an hour with no minute is somebody who stopped
 * half-way, not somebody who meant "on the hour".
 */
function posted_time(string $name): ?string {
    $hour=post($name.'_h'); $minute=post($name.'_m');
    if($hour==='' && $minute==='') return null;
    if($hour==='' || $minute==='') throw new UserError(t('Bitte Stunde und Minute angeben.','Please give both the hour and the minute.'));
    return time_value($hour.':'.$minute);
}

/** A decimal from a form, accepting a comma as the separator. */
function decimal_value(string $value): float {
    $v=str_replace(',','.',trim($value));
    if(!preg_match('/^-?\d{1,4}(?:\.\d{1,2})?$/D',$v)) throw new UserError(t('Bitte eine Zahl eingeben, z. B. 0,5.','Please enter a number, e.g. 0.5.'));
    return (float)$v;
}

/**
 * Read an optional foreign key from a form and confirm the row exists.
 *
 * Returns null for "none selected" so the column keeps its meaning, and rejects
 * a dangling id rather than letting the database raise a constraint error the
 * operator cannot interpret.
 */
function reference_or_null(string $table, string $field, string $extra=''): ?int {
    $id=(int)post($field);
    if($id<=0) return null;
    if(!one('SELECT id FROM '.sql_name($table,'table').' WHERE id=?'.($extra?' AND '.$extra:''),[$id]))
        throw new UserError(t('Die Auswahl ist nicht verfügbar.','That selection is not available.'));
    return $id;
}

/** code => label pairs from the paired inputs the settings form renders. */
function map_from_post(string $key, array $spec): array {
    $keys=$_POST[$key.'_keys']??[]; $labels=$_POST[$key.'_labels']??[];
    if(!is_array($keys)||!is_array($labels)) throw new UserError(t('Ungültige Liste.','Invalid list.'));
    $out=[];
    foreach($labels as $i=>$label) {
        if(!is_scalar($label)||!is_scalar($keys[$i]??'')) throw new UserError(t('Ungültige Liste.','Invalid list.'));
        $label=trim((string)$label); if($label==='') continue;
        $code=trim((string)($keys[$i]??''));
        if($code==='') $code='custom_'.bin2hex(random_bytes(4));
        if(!preg_match('/^[a-z0-9][a-z0-9_]{0,39}$/D',$code) || mb_strlen($label)>100)
            throw new UserError(setting_label($spec).': '.t('Bitte gültige Bezeichnungen eingeben.','Please enter valid labels.'));
        if(isset($out[$code])) throw new UserError(setting_label($spec).': '.t('Kürzel doppelt vorhanden.','Duplicate key.'));
        $out[$code]=$label;
    }
    if(!$out || count($out)>80) throw new UserError(setting_label($spec).': '.t('Bitte eine gültige Liste eingeben.','Please enter a valid list.'));
    // A code still referenced by a record must survive, or those rows would
    // display a raw key with no label.
    foreach(['statuses'=>'SELECT DISTINCT status AS code FROM students','absence_reasons'=>'SELECT DISTINCT reason AS code FROM absences'] as $k=>$sql)
        if($key===$k) foreach(rows($sql) as $r)
            if(!isset($out[$r['code']])) throw new UserError(t('Verwendetes Kürzel muss erhalten bleiben: ','Keep this key because records use it: ').$r['code']);
    return $out;
}

/**
 * IBAN check: layout, then the mod-97 checksum.
 *
 * A typo in an IBAN means money goes nowhere or somewhere else, so it is worth
 * catching at entry rather than when a parent's transfer bounces.
 */
function valid_iban(string $iban): bool {
    if(!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/D',$iban)) return false;
    $rearranged=substr($iban,4).substr($iban,0,4);
    $digits='';
    foreach(str_split($rearranged) as $ch)
        $digits .= ctype_digit($ch) ? $ch : (string)(ord($ch)-55);
    // bcmod-free mod 97 for a number far wider than an integer.
    $remainder=0;
    foreach(str_split($digits) as $d) $remainder=($remainder*10+(int)$d)%97;
    return $remainder===1;
}

/**
 * The times of one column of repeating rows, as row index to "HH:MM:00".
 *
 * Each row posts its hour and its minute separately, so a row that is there at
 * all has both or neither; a row with only one of them is refused rather than
 * stored as midnight.
 */
function posted_time_rows(string $name): array {
    $hours=$_POST[$name.'_h']??[]; $minutes=$_POST[$name.'_m']??[];
    if(!is_array($hours) || !is_array($minutes)) throw new UserError(t('Ungültige Termine.','Invalid schedule.'));
    $out=[];
    foreach($hours as $i=>$hour) {
        $hour=is_scalar($hour)?trim((string)$hour):'';
        $minute=is_scalar($minutes[$i]??'')?trim((string)($minutes[$i]??'')):'';
        if($hour==='' && $minute==='') { $out[$i]=null; continue; }
        if($hour==='' || $minute==='') throw new UserError(t('Bitte Stunde und Minute angeben.','Please give both the hour and the minute.'));
        $out[$i]=time_value($hour.':'.$minute);
    }
    return $out;
}

/**
 * The weekly pattern a course form posts: one row per meeting day.
 *
 * Rows arrive as parallel arrays, the way the settings map editor already does
 * it, so a row can be added in the browser without the server knowing how many
 * there will be. A row with no weekday chosen is a blank line at the bottom of
 * the form and is dropped rather than refused.
 */
function class_days_from_post(): array {
    $weekdays=$_POST['day_weekday']??[]; $places=$_POST['day_location']??[];
    $starts=posted_time_rows('day_starts_at'); $ends=posted_time_rows('day_ends_at');
    foreach([$weekdays,$places] as $list)
        if(!is_array($list)) throw new UserError(t('Ungültige Termine.','Invalid schedule.'));
    $out=[]; $seen=[];
    foreach($weekdays as $i=>$weekday) {
        if(!is_scalar($weekday) || trim((string)$weekday)==='') continue;
        $day=(int)choose(trim((string)$weekday),array_map('strval',array_keys(weekdays())));
        $from=$starts[$i]??null; $to=$ends[$i]??null;
        if($from && $to && $from>=$to)
            throw new UserError(weekdays()[$day].': '.t('Das Ende muss nach dem Beginn liegen.','The end time must be after the start time.'));
        $where=is_scalar($places[$i]??'')?trim((string)($places[$i]??'')):'';
        if(mb_strlen($where)>160) throw new UserError(t('Der Ort ist zu lang.','That place name is too long.'));
        // Two entries for the same weekday at the same time is a double-tap on a
        // phone, not a course that meets twice at once.
        $key=$day.'|'.(string)$from;
        if(isset($seen[$key])) continue;
        $seen[$key]=true;
        $out[]=['weekday'=>$day,'starts_at'=>$from,'ends_at'=>$to,'location'=>$where];
    }
    if(count($out)>14) throw new UserError(t('Höchstens 14 Termine pro Kurs.','At most 14 meeting days per course.'));
    return $out;
}
