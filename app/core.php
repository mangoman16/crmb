<?php
declare(strict_types=1);

class UserError extends RuntimeException {}
/**
 * A record that is not there - or not theirs to see.
 *
 * Separate from UserError only so the page can use the right word and the right
 * status: a stale link to a deleted course was answering "Kein Zugriff", which
 * tells the trainer she is not allowed to see her own course. The two cases are
 * deliberately one class, because a family asking for another family's child
 * must be told the same thing as somebody asking for a child who has been
 * deleted; anything else confirms the record exists.
 */
class NotFound extends UserError {}
function config(string $key): mixed { global $config; return $config[$key] ?? null; }
function connect(): PDO {
    // Seam for the test harness, which supplies its own connection so the suite
    // can run without a database server. Nothing in the application sets this;
    // if it is unset, the normal MySQL connection below is used.
    $override = $GLOBALS['crm_connect_override'] ?? null;
    if ($override instanceof Closure) return $override();
    $c = config('db');
    $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4", $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
function db(): PDO { static $pdo; if(!$pdo){$pdo=connect();$GLOBALS['crm_db_opened']=true;} return $pdo; }
// A rate limit must survive the rollback of the action it is guarding, otherwise a
// failed attempt refunds its own counter and the limit never triggers. A second
// connection keeps the counters outside the action's transaction.
function counter_db(): PDO { static $pdo; return $pdo ??= connect(); }
function run(string $sql, array $params=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($params); return $s; }
function run_counter(string $sql, array $params=[]): PDOStatement { $s=counter_db()->prepare($sql); $s->execute($params); return $s; }
function rows(string $sql, array $params=[]): array { return run($sql,$params)->fetchAll(); }
function one(string $sql, array $params=[]): ?array { return run($sql,$params)->fetch() ?: null; }
function scalar(string $sql, array $params=[]): mixed { return run($sql,$params)->fetchColumn(); }
/**
 * A table, column or alias name that is about to be interpolated into SQL.
 *
 * Identifiers cannot be bound as parameters the way values can, so the few the
 * application builds itself are checked against the shape this schema uses.
 * Every caller passes a name from its own code or from an allowlist; this is
 * the backstop that keeps that assumption honest rather than assumed.
 */
function sql_name(string $name, string $kind='identifier'): string {
    if(!preg_match('/^[a-z_][a-z0-9_]*$/D',$name)) throw new RuntimeException('Refusing to use '.var_export($name,true).' as a SQL '.$kind);
    return $name;
}
function now(): string { return gmdate('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }
function locale(): string { return $_SESSION['locale'] ?? 'de'; }
function t(string $de, string $en): string { return locale()==='en' ? $en : $de; }
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page='', array $params=[]): string { return rtrim(config('app_url'),'/') . '/index.php' . ($page ? '?' . http_build_query(['page'=>$page]+$params) : ''); }
function go(string $page, array $params=[]): never {
    tx_abandon_open('redirect to '.$page);
    header('Location: '.url($page,$params),true,303); exit;
}
function flash(string $message, string $kind='success'): void { $_SESSION['flash']=['message'=>$message,'kind'=>$kind]; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }

/**
 * Which page is being served.
 *
 * public/index.php holds it in a global, and start_form(), holding_input() and
 * the test harness all have to agree on it, so they read it through here rather
 * than each reaching for the global with its own fallback.
 */
function current_page(): string { return (string)($GLOBALS['page'] ?? 'dashboard'); }

/**
 * Which form is being written out right now.
 *
 * Set by form_open() and read by held_input(), so a rejected submission is
 * offered back to the form it came from and to no other. Without it, a filter
 * box and a student's first name would both answer to the name "q" or "name".
 */
function form_context(string $action=''): string {
    static $current='';
    if (func_num_args()) $current=$action;
    return $current;
}

function form_open(string $action, array $hidden=[], string $class='', bool $multipart=false): void {
    form_context($action);
    // A form carrying a file has to say so, or PHP receives an empty $_FILES and
    // the upload looks to the person like nothing happened.
    echo '<form method="post" action="'.e(url()).'" class="'.e($class).'"'.($multipart?' enctype="multipart/form-data"':'').'>';
    foreach (['action'=>$action,'csrf'=>csrf(),'request_id'=>bin2hex(random_bytes(32))]+$hidden as $k=>$v) echo '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';
}

/**
 * Whether a submitted field holds something that must never be kept.
 *
 * One rule for both places that copy input into the session: remember_input(),
 * which offers a rejected form back, and record_step(), which keeps the trail a
 * problem report carries. Two copies of "never keep a password" is how the
 * second one ends up missing a word.
 *
 * Matched by name rather than listed per form, because the name is what every
 * form already agrees on - password, current_password, password_confirm and
 * smtp_password in forms, csrf in all of them, token and signature in links -
 * and a list per form would miss exactly the new one. A new field that holds a
 * secret must match this or be added to it.
 */
function is_secret_field(string $key): bool {
    $key=strtolower($key);
    if ($key==='csrf') return true;
    foreach (['password','passwort','token','secret','signature'] as $word)
        if (str_contains($key,$word)) return true;
    return false;
}

/**
 * The fields start_form() adds to every form for the portal's own use: which
 * submission this is, what it asks for, and the page to return to. Nobody typed
 * them, so neither a held form nor a report's steps keep them as input. Each
 * reader adds its own difference: remember_input() also drops a typed
 * confirmation, record_step() also drops csrf, which is_secret_field() would
 * otherwise record as ***.
 */
const FORM_BOOKKEEPING_FIELDS = ['request_id', 'action', 'return_page', 'return_id', 'return_tab'];

/**
 * Keep what was typed when one value is rejected.
 *
 * A form that comes back empty after a euro sign in a price field means typing
 * everything again, and the person retyping is on a phone. So the submission is
 * held for exactly one page: the redirect after the error renders the same form,
 * held_input() hands each field its value back, and the stash is gone.
 *
 * Nothing is_secret_field() names is ever held - passwords, the CSRF token,
 * signatures. Neither are FORM_BOOKKEEPING_FIELDS, because the form that renders
 * next writes its own and an old one would be wrong, nor a typed confirmation:
 * a name retyped to confirm a deletion has to be typed again, every time.
 *
 * One of the two places allowed to read $_POST outside an action (ADR 0009):
 * it copies input into the session for display and acts on none of it.
 */
function remember_input(string $action): void {
    $skip=[...FORM_BOOKKEEPING_FIELDS,'confirmation'];
    $fields=[];
    $bytes=0;
    foreach ($_POST as $key=>$value) {
        if (!is_string($key) || in_array($key,$skip,true) || is_secret_field($key)) continue;
        if (!is_scalar($value) && !is_array($value)) continue;
        // A session is a file on disk on most hosting. One long note is worth
        // keeping; a megabyte of paste is not worth carrying into every request
        // that follows.
        $size=strlen(json_encode($value,JSON_UNESCAPED_UNICODE) ?: '');
        if ($bytes+$size > 200000) break;
        $bytes+=$size;
        $fields[$key]=$value;
    }
    if (!$fields) return;
    $_SESSION['form_input']=['action'=>$action,'page'=>post('return_page'),'id'=>(string)(int)post('return_id'),
                             'tab'=>post('return_tab'),'fields'=>$fields];
}

/** Take the held submission out of the session. Called once, before a page renders. */
function take_held_input(): void {
    $GLOBALS['crm_held_input']=$_SESSION['form_input'] ?? null;
    unset($_SESSION['form_input']);
}

/**
 * Whether the held submission belongs to the form being written out right now.
 *
 * Same form, same page, same record, same tab. Anything less and a rejected edit
 * of one student could hand their details to the form for another.
 */
function holding_input(): bool {
    $held=$GLOBALS['crm_held_input'] ?? null;
    return $held!==null && form_context()!=='' && $held['action']===form_context()
        && (string)$held['page']===current_page()
        && (string)$held['id']===(string)(int)($_GET['id'] ?? 0)
        && (string)$held['tab']===(string)($_GET['tab'] ?? '');
}

/** The value this field should show: what was typed, or what the caller passed. */
function held_input(string $name, mixed $fallback): mixed {
    if (!holding_input()) return $fallback;
    $fields=$GLOBALS['crm_held_input']['fields'];
    return array_key_exists($name,$fields) ? $fields[$name] : $fallback;
}
// form_text() is in app/install.php, so the installer reads its form by the same rule.
function post(string $key, string $default=''): string { $v=form_text($_POST[$key]??$default); if($v===null) throw new UserError(t('Ungültige Eingabe.','Invalid input.')); return $v; }
/**
 * The longest a one-line name may be, unless a field says otherwise: the size of
 * accounts.name, which a name built from a student's two 100-character halves
 * can outgrow.
 */
const TEXT_LINE_MAX = 160;
function required_text(string $key, int $max=TEXT_LINE_MAX): string { $v=post($key); if($v==='' || mb_strlen($v)>$max) throw new UserError(t('Bitte alle Pflichtfelder korrekt ausfüllen.','Please complete all required fields correctly.')); return $v; }
function text_limit(string $key, int $max=255): string { $v=post($key); if(mb_strlen($v)>$max) throw new UserError(t('Die Eingabe ist zu lang.','Input is too long.')); return $v; }
function date_value(string $value, bool $required=false): ?string {
    if($value==='' && !$required) return null;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$d || $d->format('Y-m-d')!==$value) throw new UserError(t('Bitte ein gültiges Datum eingeben.','Please enter a valid date.'));
    return $value;
}
function date_range(?string $from, ?string $to): void { if($from && $to && $from>$to) throw new UserError(t('Das Enddatum liegt vor dem Startdatum.','The end date is before the start date.')); }
function cents(string $v, bool $zero=true): int {
    $v=str_replace(',','.',trim($v));
    if(!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$v)) throw new UserError(t('Betrag ohne Tausendertrennzeichen eingeben, z. B. 45,50.','Enter an amount without thousands separators, e.g. 45.50.'));
    [$a,$b]=array_pad(explode('.',$v),2,'0'); $n=(int)$a*100+(int)str_pad($b,2,'0');
    if(!$zero && $n===0) throw new UserError(t('Der Betrag muss größer als null sein.','The amount must be greater than zero.'));
    return $n;
}
/**
 * Count with the right singular or plural wording.
 *
 * "1 Beiträge werden angelegt" is the kind of thing that makes software read as
 * machine-written, which is precisely what this interface should not do.
 */
function plural(int $n, string $de1, string $deN, string $en1, string $enN): string {
    return $n.' '.($n===1 ? t($de1,$en1) : t($deN,$enN));
}
function money(?int $v): string { return number_format(($v??0)/100,2,locale()==='de'?',':'.',locale()==='de'?'.':',').' €'; }
/**
 * An amount as it goes back into a box she types in.
 *
 * With the decimal point of the machine rather than of the country, the same
 * 19,80 € she had just typed came back as 19.80 on a German form beside a list
 * that said 19,80 € - and a point is where a reader looks for a thousands
 * separator. cents() takes either, so nothing is lost by writing it her way.
 * No thousands separator at all: that is the one thing cents() refuses.
 */
/**
 * An IBAN in groups of four, the way it is printed on everything else.
 *
 * Stored without spaces, because that is the value, and read in fours, because
 * that is how somebody copies twenty characters into a banking app without
 * losing their place. The rule lived in two views and was missing from the one
 * document where it matters most - the invoice a family types the number from.
 */
function iban_groups(string $iban): string { return trim(chunk_split(str_replace(' ', '', $iban), 4, ' ')); }
function amount_input(?int $v): string { return $v===null ? '' : number_format($v/100,2,locale()==='de'?',':'.',''); }
// Stored timestamps are UTC (now()). DATE columns are calendar dates and must not be
// shifted; DATETIME values are converted to the configured timezone before display,
// otherwise a record written after 22:00 UTC shows the previous day in Vienna.
function local_time(string $v): ?DateTimeImmutable {
    foreach (['!Y-m-d H:i:s'=>true, '!Y-m-d'=>false] as $format=>$hasTime) {
        $d = DateTimeImmutable::createFromFormat($format, $v, new DateTimeZone('UTC'));
        // createFromFormat rolls 2026-02-30 over into March and accepts MySQL zero
        // dates, so require the value to round-trip before trusting it.
        if (!$d instanceof DateTimeImmutable || $d->format($format === '!Y-m-d' ? 'Y-m-d' : 'Y-m-d H:i:s') !== $v) continue;
        return $hasTime ? $d->setTimezone(new DateTimeZone(date_default_timezone_get())) : $d;
    }
    return null;
}
function fmt_date(?string $v): string { $d=$v!==null&&$v!==''?local_time($v):null; return $d ? $d->format(locale()==='de'?'d.m.Y':'d M Y') : '–'; }
function fmt_datetime(?string $v): string { $d=$v!==null&&$v!==''?local_time($v):null; return $d ? $d->format(locale()==='de'?'d.m.Y, H:i':'d M Y, H:i') : '–'; }
/** Per-request settings cache, shared by reference so it can be invalidated. */
function &setting_cache(): array { static $cache=[]; return $cache; }
/**
 * Read an operator setting.
 *
 * With no stored row the declared default in app/defaults.php applies, so a key
 * is never undefined and a new setting needs no migration. $default is still
 * honoured for the few callers that pass one explicitly.
 */
function setting(string $key, mixed $default=null): mixed {
    $cache=&setting_cache();
    if(!array_key_exists($key,$cache)) {
        $v=scalar('SELECT setting_value FROM settings WHERE setting_key=?',[$key]);
        $cache[$key]=$v===false?null:json_decode((string)$v,true);
    }
    if($cache[$key]!==null) return $cache[$key];
    if($default!==null) return $default;
    return setting_default($key);
}
/** Drop the whole settings cache. Used by the test harness between cases. */
function setting_cache_clear(): void { $cache=&setting_cache(); $cache=[]; }
function set_setting(string $key, mixed $value): void {
    run('INSERT INTO settings (setting_key,setting_value,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)',[$key,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),now()]);
    $cache=&setting_cache(); unset($cache[$key]);
}
/**
 * Write down that something happened, and who did it.
 *
 * "Who" is the person really signed in. While somebody is looking through
 * another account's eyes, the session belongs to that account but the decision
 * was made by the person impersonating, and an audit log that named the borrowed
 * account would be recording the wrong person for the one kind of action where
 * it matters most.
 */
function audit(string $action,string $type,?int $id=null): void {
    $actor=$_SESSION['impersonator_id'] ?? (current_user()['id']??null);
    run('INSERT INTO audit_log (actor_id,action,entity_type,entity_id,created_at) VALUES (?,?,?,?,?)',[$actor?(int)$actor:null,$action,$type,$id,now()]);
}
function seal(string $plain): string { $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',base64_decode(config('app_key')),OPENSSL_RAW_DATA,$iv,$tag); if($cipher===false) throw new RuntimeException('Encryption failed'); return base64_encode($iv.$tag.$cipher); }
function unseal(string $value): string { $b=base64_decode($value,true); if($b===false || strlen($b)<28) throw new RuntimeException('Invalid encrypted data'); $plain=openssl_decrypt(substr($b,28),'aes-256-gcm',base64_decode(config('app_key')),OPENSSL_RAW_DATA,substr($b,0,12),substr($b,12,16)); if($plain===false) throw new RuntimeException('Cannot decrypt with this app key'); return $plain; }
/**
 * The name a copy gets: "Monatsbeitrag (Kopie)", then "(Kopie 2)".
 *
 * Numbered against the names already in use, because two rows reading exactly
 * the same on a list is a choice nobody can make. The name is shortened rather
 * than the suffix, so the part that says it is a copy never falls off the end.
 */
function copy_name(string $name, array $taken=[], int $max=120): string {
    $taken = array_map('mb_strtolower', $taken);
    for ($n = 1; $n <= 99; $n++) {
        $tail = t(' (Kopie', ' (copy') . ($n > 1 ? ' ' . $n : '') . ')';
        $candidate = mb_substr($name, 0, max(1, $max - mb_strlen($tail))) . $tail;
        if (!in_array(mb_strtolower($candidate), $taken, true)) return $candidate;
    }
    return mb_substr($name, 0, $max);
}

/**
 * An address the one way it is stored and compared: trimmed and in lower case.
 * Every lookup and comparison goes through this, so two spellings of one
 * address can never be two accounts, or one account that cannot be found.
 */
function email_normalised(string $value): string { return mb_strtolower(trim($value)); }
function email_value(string $value): string { $v=email_normalised($value); if(!filter_var($v,FILTER_VALIDATE_EMAIL) || strlen($v)>254) throw new UserError(t('Ungültige E-Mail-Adresse.','Invalid email address.')); return $v; }
function choose(string $value,array $allowed): string { if(!in_array($value,$allowed,true)) throw new UserError(t('Ungültige Auswahl.','Invalid choice.')); return $value; }
/**
 * The privacy notice, with the operator's own details filled in.
 *
 * The draft ships with placeholders for the controller, because a notice naming
 * nobody is not a notice. Rather than asking her to type her address into two
 * long texts and keep them in step, the texts carry {{org_*}} markers and the
 * details come from the one place they are already entered for invoices.
 */
function privacy_placeholders(): array {
    $address=trim(trim((string)setting('org_street')).', '.trim((string)setting('org_zip').' '.(string)setting('org_city')), ' ,');
    return [
        '{{org_name}}'    => (string)setting('org_name'),
        '{{org_address}}' => $address,
        '{{org_email}}'   => (string)setting('org_email'),
        '{{org_phone}}'   => (string)setting('org_phone'),
        '{{org_country}}' => (string)setting('org_country'),
    ];
}

/**
 * The notes in square brackets the shipped draft asks her to replace, still in
 * the text: „[Vollständiger Name / Organisation, …]“, „[Datum]“.
 *
 * Any length, because most of them are instructions of a hundred words or more;
 * a length cap once let six of the eight through. On one line, because every
 * note in the drafts is one and a bracket left open by a typo should not swallow
 * the rest of the notice. It must contain a letter, so a footnote „[1]“ or an
 * elision „[…]“ in a quotation is not one, and a bracket followed by „(“ is a
 * link written as [text](address), not a note.
 *
 * Linear, because this runs on 30,000 characters anybody with the settings page
 * can paste: nothing inside a match may be a „[“, and both runs are possessive,
 * so no position is scanned twice. The earlier pattern looked ahead across
 * further „[“ for a letter and took seconds on one long line of them. The
 * look-ahead skips non-letters only - skipping letters possessively as well
 * would leave none for \p{L} to find, and the check would pass everything.
 */
function privacy_draft_placeholders(string $text): array {
    if (preg_match_all('/\[(?=[^\[\]\r\n\p{L}]*+\p{L})[^\[\]\r\n]++\](?!\()/u', $text, $matches) === false)
        throw new UserError(t('Der Text lässt sich nicht auf Platzhalter prüfen. Bitte ungewöhnliche Zeichen entfernen, etwa aus einem anderen Programm eingefügte, und noch einmal speichern.',
                              'The text cannot be checked for placeholders. Please remove unusual characters, for example ones pasted from another program, and save again.'));
    return $matches[0];
}

/**
 * The notice as a reader in $locale sees it, with the operator's details filled in.
 *
 * Only the German text is required (ADR 0011). A reader of the English page
 * while there is no English text is shown the German one rather than nothing,
 * and the page says so - privacy_in_german_only() is how it knows.
 */
function privacy_text(?string $locale=null): string {
    $english = ($locale ?? locale())==='en' && !privacy_in_german_only($locale);
    return privacy_filled((string)setting($english ? 'privacy_en' : 'privacy_de'));
}

/** Whether an English reader is being shown the German notice, for want of an English one. */
function privacy_in_german_only(?string $locale=null): bool {
    return ($locale ?? locale())==='en' && trim((string)setting('privacy_en'))==='';
}

/** A stored notice with the placeholders replaced by who is responsible. */
function privacy_filled(string $stored): string { return strtr($stored, privacy_placeholders()); }

/**
 * Which version of the notice somebody agreed to.
 *
 * Hashed after substitution, so moving house changes the notice and the people
 * who agreed to the old wording are recorded as having agreed to the old
 * wording - which is the point of storing a version at all.
 *
 * The English text as stored, never the German copy privacy_text() falls back
 * to: adding a translation later is a new version, because it is one, and an
 * English reader of a German-only notice agreed to the version with no English.
 */
function notice_version(): string {
    // As a pair rather than run together, so no German text and English text
    // can add up to the same string as a different pair.
    return substr(hash('sha256', (string)json_encode([privacy_filled((string)setting('privacy_de')),
                                                      privacy_filled((string)setting('privacy_en'))], JSON_UNESCAPED_UNICODE)), 0, 16);
}
// Appearance follows the signed-in account; signed-out pages follow the device.
function appearance(?array $user): array {
    return [
        in_array($user['theme']??'auto',['auto','light','dark'],true)?($user['theme']??'auto'):'auto',
        in_array($user['text_scale']??'normal',['normal','large','larger','largest'],true)?($user['text_scale']??'normal'):'normal',
    ];
}
function maintenance_file(): string { return config('maintenance_file') ?: ROOT.'/storage/maintenance.flag'; }
// Split a migration file into statements on semicolons that are not inside a string
// literal, a quoted identifier or a comment. Splitting on every semicolon breaks any
// migration that carries one in a default value, an enum or a trigger body.
function split_sql(string $sql): array {
    $statements=[]; $buffer=''; $quote=null; $length=strlen($sql);
    for($i=0;$i<$length;$i++) {
        $c=$sql[$i];
        if($quote!==null) {
            $buffer.=$c;
            if($c==='\\' && $i+1<$length) { $buffer.=$sql[++$i]; continue; }
            if($c===$quote) { if(($sql[$i+1]??'')===$quote) $buffer.=$sql[++$i]; else $quote=null; }
            continue;
        }
        if($c==="'" || $c==='"' || $c==='`') { $quote=$c; $buffer.=$c; continue; }
        if($c==='-' && ($sql[$i+1]??'')==='-' || $c==='#') { while($i<$length && $sql[$i]!=="\n") $i++; $buffer.="\n"; continue; }
        if($c==='/' && ($sql[$i+1]??'')==='*') { $end=strpos($sql,'*/',$i+2); $i=$end===false?$length:$end+1; $buffer.=' '; continue; }
        if($c===';') { if(trim($buffer)!=='') $statements[]=trim($buffer); $buffer=''; continue; }
        $buffer.=$c;
    }
    if(trim($buffer)!=='') $statements[]=trim($buffer);
    return $statements;
}
