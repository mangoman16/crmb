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
 * "17:30:00" or "17:30" as ['17','30']; anything else as two empty strings.
 *
 * Beside time_value() rather than with the form helpers, because splitting a
 * time and validating one are the same subject, and this way it is loaded
 * wherever a time is handled rather than only where a form is drawn.
 */
function time_parts(string $value): array {
    return preg_match('/^([01]\d|2[0-3]):([0-5]\d)/',trim($value),$m)?[$m[1],$m[2]]:['',''];
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

/**
 * A whole number typed into a form, from $min to $max, or a refusal in one
 * sentence.
 *
 * For every number that is not an id - a course's places, a day of the month,
 * the days until „überfällig", a port, an invoice's terms, a position in a list
 * (ADR 0026 §5). A cast turned „1,5" into 1, „abc" into 0 - which for places
 * means unlimited - and twenty nines into the largest integer there is, which
 * the database then refused with an error of its own. Digits only, with a minus
 * where $min allows one.
 *
 * $refusal is the sentence for a field whose range needs saying in its own words
 * („Zahltag: 1 bis 28 …"); without it the sentence names the range.
 */
function whole_number_value(string $value, int $min, int $max, ?string $refusal = null): int {
    $value=trim($value);
    if(preg_match('/^-?[0-9]{1,18}$/D',$value) && (int)$value>=$min && (int)$value<=$max) return (int)$value;
    throw new UserError($refusal ?? strtr(t('Bitte eine ganze Zahl von {min} bis {max} eingeben.','Please enter a whole number from {min} to {max}.'),
                                          ['{min}'=>$min,'{max}'=>$max]));
}

/**
 * Where a course, a tariff, a level or an age group stands in its list: a
 * whole number, smallest first. Bounded where nobody's list reaches, so the
 * column always takes it.
 */
function list_position_value(string $value): int {
    return whole_number_value($value?:'0',-99999,99999,
        t('Reihenfolge: bitte eine ganze Zahl von -99999 bis 99999 eingeben, die kleinste steht zuerst.',
          'Order: please enter a whole number from -99999 to 99999; the smallest comes first.'));
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
        $label=form_text($label); $code=form_text($keys[$i]??'');
        if($label===null||$code===null) throw new UserError(t('Ungültige Liste.','Invalid list.'));
        if($label==='') continue;
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
        $hour=form_text($hour)??'';
        $minute=form_text($minutes[$i]??'')??'';
        if($hour==='' && $minute==='') { $out[$i]=null; continue; }
        if($hour==='' || $minute==='') throw new UserError(t('Bitte Stunde und Minute angeben.','Please give both the hour and the minute.'));
        $out[$i]=time_value($hour.':'.$minute);
    }
    return $out;
}

/**
 * The price list a tariff form posts: one row per interval.
 *
 * Returns interval months => cents, cheapest interval first. A row with no
 * price is the blank line at the bottom of the form and is dropped; the same
 * interval twice is a double-tap, and the second one loses rather than the form
 * being refused - she would have to work out which of two identical rows the
 * complaint is about.
 */
function posted_tariff_rates(bool $recurring): array {
    $intervals=$_POST['rate_interval']??[]; $prices=$_POST['rate_price']??[];
    if(!is_array($intervals) || !is_array($prices)) throw new UserError(t('Ungültige Preise.','Invalid prices.'));
    $out=[];
    foreach($intervals as $i=>$months) {
        $price=form_text($prices[$i]??'')??'';
        if($price==='') continue;
        $months=$recurring?billing_valid_interval((int)(form_text($months)??'0')):1;
        if(isset($out[$months])) continue;
        $out[$months]=cents($price);
    }
    if(!$out) throw new UserError(t('Bitte mindestens einen Preis eintragen.','Please enter at least one price.'));
    if(count($out)>count(billing_intervals())) throw new UserError(t('Zu viele Preise.','Too many prices.'));
    ksort($out);
    return $out;
}

/**
 * The discount templates a tariff form posts.
 *
 * A template is a shape she gives, not an agreement: "dauerhaft -20 %", "erster
 * Monat frei". A row with no name is the blank line at the bottom.
 */
function posted_discount_templates(): array {
    $names=$_POST['discount_name']??[]; $months=$_POST['discount_months']??[];
    $kinds=$_POST['discount_kind']??[]; $values=$_POST['discount_value']??[];
    foreach([$names,$months,$kinds,$values] as $list)
        if(!is_array($list)) throw new UserError(t('Ungültige Rabatte.','Invalid discounts.'));
    $out=[];
    foreach($names as $i=>$name) {
        $name=form_text($name)??'';
        if($name==='') continue;
        if(mb_strlen($name)>120) throw new UserError(t('Der Name des Rabatts ist zu lang.','That discount name is too long.'));
        $out[]=['name'=>$name]+discount_from_post($kinds[$i]??'percent',$months[$i]??'0',$values[$i]??'0');
    }
    if(count($out)>20) throw new UserError(t('Höchstens 20 Rabattvorlagen je Tarif.','At most 20 discount templates per tariff.'));
    return $out;
}

/**
 * One discount, wherever it was typed: how long it runs, in what shape, and how
 * much.
 *
 * -1 month is "for as long as they stay", which is the only way to say a
 * permanently reduced rate; the forms offer it as its own choice rather than
 * asking anybody to type a negative number.
 */
function discount_from_post(mixed $kind,mixed $months,mixed $value): array {
    $kind=choose(form_text($kind)??'percent',['percent','fixed']);
    $months=whole_number_value((form_text($months)??'')?:'0',-1,120,
        t('Rabattdauer: 0 bis 120 Monate, oder dauerhaft.','Discount length: 0 to 120 months, or permanent.'));
    $raw=form_text($value)??'';
    $amount=$months===0||$raw===''?0:($kind==='fixed'?cents($raw):whole_number_value($raw,0,100,t('Rabatt: eine ganze Zahl von 0 bis 100 Prozent.','Discount: a whole number from 0 to 100 per cent.')));
    return ['months'=>$amount===0?0:$months,'kind'=>$kind,'value'=>$amount];
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
        $weekday=form_text($weekday)??'';
        if($weekday==='') continue;
        $day=(int)choose($weekday,array_map('strval',array_keys(weekdays())));
        $from=$starts[$i]??null; $to=$ends[$i]??null;
        if($from && $to && $from>=$to)
            throw new UserError(weekdays()[$day].': '.t('Das Ende muss nach dem Beginn liegen.','The end time must be after the start time.'));
        $where=form_text($places[$i]??'')??'';
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
