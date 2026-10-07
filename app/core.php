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
/** The portal's own language: every page before somebody chooses, and everything the portal writes for everybody. */
const PORTAL_LOCALE = 'de';

/**
 * Who is speaking, when it is not the person whose session this is.
 *
 * Most text follows the signed-in person. Some does not: a charge is written for
 * every family in the portal's language, an invoice is printed in its family's
 * whoever downloads it, and the background work after a page view is the
 * portal's own and nobody's - not the visitor's whose request it happened to
 * follow. Held here rather than in $_SESSION, because the session is written
 * back when the request ends and must come out of this unchanged.
 */
function &speaking_as(): array { static $as = ['locale' => null, 'nobody' => false]; return $as; }

function locale(): string { return speaking_as()['locale'] ?? $_SESSION['locale'] ?? PORTAL_LOCALE; }

/** Run $fn with every t(), money() and date in one language, and put the session's back afterwards. */
function in_locale(string $locale, callable $fn): mixed {
    $as = &speaking_as();
    $was = $as['locale'];
    $as['locale'] = $locale === 'en' ? 'en' : PORTAL_LOCALE;
    try { return $fn(); } finally { $as['locale'] = $was; }
}

/**
 * Run $fn as the portal's own work: in its language, and with nobody as the one
 * who did it. The background work after a page view goes through this
 * (tick_work()), so the audit and the change log do not name whoever's page view
 * it followed - a family, as often as not.
 */
function as_the_portal(callable $fn): mixed {
    $as = &speaking_as();
    $was = $as;
    $as = ['locale' => PORTAL_LOCALE, 'nobody' => true];
    try { return $fn(); } finally { $as = $was; }
}
function t(string $de, string $en): string { return locale()==='en' ? $en : $de; }
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** A page's address; a '#' entry in $params is the place on the page, so a redirect can land on it without JavaScript. */
function url(string $page='', array $params=[]): string {
    $fragment=(string)($params['#']??''); unset($params['#']);
    return rtrim(config('app_url'),'/') . '/index.php' . ($page ? '?' . http_build_query(['page'=>$page]+$params) : '')
        . ($fragment!=='' ? '#'.rawurlencode($fragment) : '');
}
/** The address of a file that ships in public/assets/, which changes when its bytes do (asset_path()). */
function asset_url(string $file): string { return rtrim((string)config('app_url'),'/') . '/' . asset_path($file); }
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
 * Which form is being written out right now: its action, and the record it
 * edits when it has one (form_record()).
 *
 * Set by form_open() and read by held_input(), so a rejected submission is
 * offered back to the form it came from and to no other. Without it, a filter
 * box and a student's first name would both answer to the name "q" or "name" -
 * and without the record, a refused edit of one contact was written into every
 * contact's form on the page (ADR 0020, §10a).
 */
function &form_context_state(): array { static $state=['action'=>'','record'=>null]; return $state; }
function form_context(string $action='', ?int $record=null): string {
    $state=&form_context_state();
    if (func_num_args()) $state=['action'=>$action,'record'=>$record];
    return $state['action'];
}
/** The record the form being written out edits, or null for a form with none. */
function form_record(): ?int { return form_context_state()['record']; }

/**
 * The record a form edits, from the id it carries: a positive integer, or null
 * for a form that has none - an add form, a create form. One reading for the
 * form written out and the form posted back, so the two can be compared.
 */
function form_record_id(mixed $id): ?int {
    return (is_int($id) && $id>0) || (is_string($id) && preg_match('/^[1-9][0-9]{0,18}$/D',$id)) ? (int)$id : null;
}

function form_open(string $action, array $hidden=[], string $class='', bool $multipart=false): void {
    form_context($action, form_record_id($hidden['id'] ?? null));
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
const FORM_BOOKKEEPING_FIELDS = ['request_id', 'action', 'return_page', 'return_id', 'return_tab', 'return_draft'];

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
    // 'id' is the page's record, from return_id; 'record' is the form's own -
    // one contact of several on a student's page, say.
    $_SESSION['form_input']=['action'=>$action,'page'=>post('return_page'),'id'=>(string)(int)post('return_id'),
                             'tab'=>post('return_tab'),'record'=>form_record_id($_POST['id'] ?? null),'fields'=>$fields];
}

/** Take the held submission out of the session. Called once, before a page renders. */
function take_held_input(): void {
    $GLOBALS['crm_held_input']=$_SESSION['form_input'] ?? null;
    unset($_SESSION['form_input']);
}

/**
 * What was typed into the form for $action, when that is the form refused last
 * and it was refused on this page, on this tab, for this page's record and -
 * where both have one - for this form's own $record; or [].
 *
 * Same form, same page, same records, same tab. Anything less and a rejected
 * edit of one student could hand their details to the form for another, or a
 * refused edit of one contact be written into every contact's form and saved
 * over the wrong person (ADR 0020, §10a). A form with no record of its own - an
 * add form - matches as it always did. The one place that match is made:
 * holding_input() and held_input() ask this for the form being written out, and
 * a view asks it by name to react to a refusal before it opens the form - a
 * refused invitation's form left open, a refused contact's details opened.
 * It reads the held copy only, so a view that asks still writes nothing.
 * remember_input() never holds an empty set, so [] means nothing is held.
 */
function held_for(string $action, ?int $record = null): array {
    $held=$GLOBALS['crm_held_input'] ?? null;
    if ($held===null || $action==='' || $held['action']!==$action
        || (string)$held['page']!==current_page()
        || (string)$held['id']!==(string)(int)($_GET['id'] ?? 0)
        || (string)$held['tab']!==($_GET['tab'] ?? '')) return [];
    // A submission held before records were stored has none, and matches.
    $heldRecord=$held['record'] ?? null;
    if ($record!==null && $heldRecord!==null && (int)$heldRecord!==$record) return [];
    return (array)$held['fields'];
}

/** Whether the held submission belongs to the form being written out right now. */
function holding_input(): bool { return held_for(form_context(),form_record())!==[]; }

/** The value this field should show: what was typed, or what the caller passed. */
function held_input(string $name, mixed $fallback): mixed {
    $fields=held_for(form_context(),form_record());
    return array_key_exists($name,$fields) ? $fields[$name] : $fallback;
}
/**
 * The page a form was sent from, as [page, params] for go(): the record and tab
 * start_form() posted along with it.
 *
 * For an action that can be sent from any page - the notification pane, the
 * account menu - and for the front controller's way back after a refusal.
 * Returning only the page name sent a form sent from one student's page back to
 * "page=student" with no student, which is a "Nicht gefunden".
 *
 * Never an open redirect: go() builds the address from url(), so the most a
 * forged value can do is name a page of this portal that does not exist, and a
 * name that is not even shaped like one falls back to $fallback. The router
 * passes its list of pages as $pages, so after a refusal - when the page a
 * person lands on is the one thing they see - it is always one that exists.
 */
function form_return(string $fallback='dashboard', ?array $pages=null): array {
    $page=post('return_page',$fallback);
    if(!preg_match('/^[a-z_]{1,40}$/D',$page) || ($pages!==null && !in_array($page,$pages,true))) $page=$fallback;
    $params=[];
    if((int)post('return_id')>0) $params['id']=(int)post('return_id');
    if(post('return_tab')!=='') $params['tab']=post('return_tab');
    // The wizard's draft key (ADR 0023 §5), so a refused step 2 comes back to
    // step 2 with the draft whole - and stays there when the phone reloads the
    // tab. Only something shaped like a key; never the details.
    // student_draft_key() is in app/domain.php, loaded after this file: safe,
    // because a form is only ever returned from while a request runs.
    if(student_draft_key(post('return_draft'))) $params['draft']=post('return_draft');
    return [$page,$params];
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
/**
 * A date of birth, or a refusal in one sentence: a real date, not in the
 * future, and not more than a hundred years ago - either is a slip of the
 * thumb on a phone's date wheel, not a member. One rule wherever a birth date
 * is typed: by staff, by a family, and while accepting an invitation.
 */
function birth_date_value(string $value, bool $required=false): ?string {
    try { $date=date_value($value,$required); } catch(UserError) { $date=false; }
    if($date===null) return null;
    if($date===false || $date>today() || $date<(new DateTimeImmutable(today()))->modify('-100 years')->format('Y-m-d'))
        throw new UserError(t('Bitte das Geburtsdatum prüfen.','Please check the date of birth.'));
    return $date;
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
// Invalid UTF-8 is substituted rather than refused: a broken mail server's
// reply is stored in smtp_last_test as it came, and refusing it turned a failed
// connection test into a 503.
function set_setting(string $key, mixed $value): void {
    run('INSERT INTO settings (setting_key,setting_value,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)',[$key,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),now()]);
    $cache=&setting_cache(); unset($cache[$key]);
}
/**
 * Who is acting: the person impersonating, while somebody is being looked at,
 * otherwise the signed-in login, otherwise nobody - and nobody, too, while the
 * portal does its own work (as_the_portal()). The one answer audit() and
 * history_record() give. Asked through current_user() first, so a view whose
 * session has ended is never named: with nobody signed in, nobody acted
 * (security review F1). current_user() is in app/auth.php, loaded later, and
 * called only while a request runs.
 */
function acting_account_id(): ?int {
    if(speaking_as()['nobody']) return null;
    $user=current_user();
    if(!$user) return null;
    return (int)($_SESSION['impersonator_id'] ?? $user['id']);
}

/**
 * Write down that something happened, and who did it.
 *
 * "Who" is the person really signed in. While somebody is looking through
 * another account's eyes, the session belongs to that account but the decision
 * was made by the person impersonating, and an audit log that named the borrowed
 * account would be recording the wrong person for the one kind of action where
 * it matters most.
 *
 * $actor names somebody else only where nobody is signed in yet and the person
 * acting is known all the same: the holder of an invitation setting up their
 * own student (create_own_student()). Nothing else passes it.
 */
function audit(string $action,string $type,?int $id=null,?int $actor=null): void {
    $actor??=acting_account_id();
    run('INSERT INTO audit_log (actor_id,action,entity_type,entity_id,created_at) VALUES (?,?,?,?,?)',[$actor?(int)$actor:null,$action,$type,$id,now()]);
}
function seal(string $plain): string { $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',base64_decode(config('app_key')),OPENSSL_RAW_DATA,$iv,$tag); if($cipher===false) throw new RuntimeException('Encryption failed'); return base64_encode($iv.$tag.$cipher); }
function unseal(string $value): string { $b=base64_decode($value,true); if($b===false || strlen($b)<28) throw new RuntimeException('Invalid encrypted data'); $plain=openssl_decrypt(substr($b,28),'aes-256-gcm',base64_decode(config('app_key')),OPENSSL_RAW_DATA,substr($b,0,12),substr($b,12,16)); if($plain===false) throw new RuntimeException('Cannot decrypt with this app key'); return $plain; }
/**
 * An address the one way it is stored and compared: trimmed and in lower case.
 * Every lookup and comparison goes through this, so two spellings of one
 * address can never be two accounts, or one account that cannot be found.
 */
function email_normalised(string $value): string { return mb_strtolower(trim($value)); }

/*
 * Two questions are asked of an address, and they have different answers
 * (ADR 0019, M1 and R2).
 *
 * "Can a mail be sent there?" is email_deliverable(): FILTER_VALIDATE_EMAIL and
 * at most 254 bytes, the check every address the portal has ever stored went
 * through. The mail queue asks only this, so no stored address - a legacy one
 * with a quoted local part included - can make queue_mail() throw and roll back
 * a newsletter to every other family with it.
 *
 * "May it be written, or looked up?" is email_is_dot_atom(): plain ASCII in
 * lower case, a dot-atom local part and LDH labels, nothing else. That is
 * stricter on purpose. FILTER_VALIDATE_EMAIL accepts a quoted local part, which
 * may hold control characters, and the database's collation ignores those when
 * it compares - so one mailbox had any number of spellings, each a fresh
 * throttle bucket for „vergessen". With only atext on both sides there is
 * nothing for the collation to ignore or fold.
 */

/** Whether a mail can be sent to this address. $normalised has been through email_normalised(). */
function email_deliverable(string $normalised): bool {
    return strlen($normalised)<=254 && filter_var($normalised,FILTER_VALIDATE_EMAIL)!==false;
}

/**
 * An address that may be written or looked up: at most 64 characters of RFC 5322
 * atext in dot-separated runs, '@', then dot-separated LDH labels of at most 63.
 * Lower case only, because it is only ever asked of a normalised address - so
 * anything that lower-casing left in capitals, and anything not ASCII, fails.
 */
const EMAIL_DOT_ATOM = '/^(?=[^@]{1,64}@)[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*'
    .'@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D';

function email_is_dot_atom(string $normalised): bool {
    return strlen($normalised)<=254 && preg_match(EMAIL_DOT_ATOM,$normalised)===1;
}

/**
 * The address to write, from what was typed - or a refusal.
 *
 * Both checks, so what is stored can be sent to and looked up alike. An address
 * written before the stricter one keeps receiving mail; saving its record asks
 * for it to be replaced.
 */
function email_value(string $value): string {
    $v=email_normalised($value);
    if(!email_deliverable($v) || !email_is_dot_atom($v)) throw new UserError(t('Ungültige E-Mail-Adresse.','Invalid email address.'));
    return $v;
}

/*
 * Usernames (ADR 0023 §1): the sign-in name of a student's login that has no
 * address, such as lena.hofer. Staff logins never have one.
 *
 * Lower-case a-z, digits, dot and hyphen; starting with a letter, ending with a
 * letter or digit, never two separators in a row, 3 to 30 characters. No '@', so
 * a username and an address can never be the same text. No underscore, because
 * username_for_new_account() builds a LIKE pattern from a username and '_' is a
 * LIKE wildcard: without it the pattern is literal (A1).
 *
 * Pure functions, here beside the address rules, so the sign-in, the access card
 * and the wizard read one rule and the suite can test it without a database.
 */
const USERNAME_PATTERN = '/^[a-z](?:[a-z0-9]|[.-](?=[a-z0-9])){2,29}$/D';

/**
 * Letters written the German way or stripped to their base letter, keyed by
 * what they become. Lower case only: everything is lower-cased first.
 *
 * One table in this file rather than ext-intl or iconv('…//TRANSLIT'). The first
 * is not a requirement of the portal and a shared host may lack it; the second
 * depends on the locale and answers differently on glibc and musl. This gives
 * the same username on every host.
 */
const USERNAME_LETTERS = [
    'ae' => 'äæ', 'oe' => 'öœ', 'ue' => 'ü', 'ss' => 'ßẞ', 'th' => 'þ', 'ij' => 'ĳ',
    'a' => 'àáâãåāăą', 'c' => 'çćĉċč', 'd' => 'ðďđ', 'e' => 'èéêëēĕėęě', 'g' => 'ĝğġģ', 'h' => 'ĥħ',
    'i' => 'ìíîïĩīĭįı', 'j' => 'ĵ', 'k' => 'ķĸ', 'l' => 'ĺļľŀł', 'n' => 'ñńņňŉŋ', 'o' => 'òóôõøōŏő',
    'r' => 'ŕŗř', 's' => 'śŝşšſ', 't' => 'ţťŧ', 'u' => 'ùúûũūŭůűų', 'w' => 'ŵ', 'y' => 'ýÿŷ', 'z' => 'źżž',
];

/** A text in lower case with USERNAME_LETTERS applied, and nothing else changed. */
function username_transliterated(string $text): string {
    static $map=null;
    if($map===null) {
        // mb_strtolower() turns the Turkish capital İ into i and a combining dot
        // above, which is not a letter of its own; it goes with the capital.
        $map=["i\u{307}"=>'i'];
        foreach(USERNAME_LETTERS as $to=>$letters) foreach(mb_str_split($letters) as $letter) $map[$letter]=$to;
    }
    return strtr(mb_strtolower($text),$map);
}

/**
 * A typed username in the one form it is stored and looked up in.
 *
 * Everything outside the table is kept, so a value that still does not match the
 * pattern is refused rather than quietly turned into somebody else's name. That
 * way `Lena.Hofer`, capitalised by an iPhone, signs in as lena.hofer.
 */
function username_normalised(string $typed): string { return username_transliterated(trim($typed)); }

/** The username to write, from what was typed - or a refusal that says the rule. The only way a typed username reaches a write. */
function username_value(string $typed): string {
    $username=username_normalised($typed);
    if(!preg_match(USERNAME_PATTERN,$username))
        throw new UserError(t('Ein Benutzername hat 3 bis 30 Zeichen: Kleinbuchstaben a–z, Ziffern, Punkt und Bindestrich. Er beginnt mit einem Buchstaben, endet mit einem Buchstaben oder einer Ziffer, und Punkt oder Bindestrich stehen nie zweimal hintereinander.',
                              'A username has 3 to 30 characters: lower-case letters a–z, digits, dot and hyphen. It starts with a letter, ends with a letter or a digit, and never has two dots or hyphens in a row.'));
    return $username;
}

/**
 * The username a person's name suggests, with no number: lena.mueller.
 *
 * Inside each name a run of spaces, hyphens or dots becomes one hyphen,
 * apostrophes are dropped (O'Neill is oneill) and so is anything else outside
 * the alphabet. The two parts are joined with a dot. The result is cut to 26
 * characters, leaving room for a number up to 9999, at a separator where that
 * still leaves a name. A name that leaves nothing usable - written only in
 * Cyrillic, Greek or Chinese, say - gives 'konto', which is numbered like any
 * other.
 */
function username_from_name(string $first, string $last): string {
    $parts=[];
    foreach([$first,$last] as $name) {
        // An apostrophe is outside the alphabet like anything else, and goes
        // with it: O'Neill is oneill, not o-neill.
        $part=preg_replace('/[^a-z0-9\s.-]/u','',username_transliterated($name));
        $part=trim((string)preg_replace('/[\s.-]+/u','-',(string)$part),'-');
        if($part!=='') $parts[]=$part;
    }
    $base=implode('.',$parts);
    if(strlen($base)>26) {
        $cut=substr($base,0,26);
        $boundary=max((int)strrpos($cut,'.'),(int)strrpos($cut,'-'));
        // At the last separator, unless the next character is one anyway or
        // cutting there would leave too little to be a name.
        $base=rtrim(($base[26]==='.' || $base[26]==='-' || $boundary<3) ? $cut : substr($cut,0,$boundary),'.-');
    }
    // A name of digits first, or of one letter, is no username; the rule, not a guess, says so.
    return preg_match(USERNAME_PATTERN,$base) ? $base : 'konto';
}

/**
 * $base if nobody has it, otherwise $base2, $base3 … - the lowest that is free,
 * or null when none of them is a username any more (a base of 30 characters has
 * no room for a number).
 *
 * So a number appears only when the name is taken, and a number freed by a
 * deleted login is handed out again.
 */
function username_first_free(string $base, array $taken): ?string {
    $taken=array_flip($taken);
    if(!isset($taken[$base])) return $base;
    for($n=2;$n<=9999;$n++) {
        $candidate=$base.$n;
        if(!preg_match(USERNAME_PATTERN,$candidate)) return null;
        if(!isset($taken[$candidate])) return $candidate;
    }
    return null;
}

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
