<?php
declare(strict_types=1);

/**
 * The things around the edges of every page.
 *
 * Notifications, accent colours, profile pictures, looking through somebody
 * else's eyes, and the button that says this is broken. Grouped together
 * because they are all properties of the shell rather than of any one screen,
 * and separated from the pages so that adding a page does not mean remembering
 * to wire five things into it.
 */

// ---------------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------------

/**
 * Tell one account about something.
 *
 * $page and $params are where to go about it, kept as a page name and a few
 * values rather than a URL, so a notification written today still points
 * somewhere real after the address of the portal changes.
 */
function notify(int $accountId, string $kind, string $title, string $body = '', string $page = '', array $params = []): void {
    run('INSERT INTO notifications (account_id,kind,title,body,link_page,link_params,created_at) VALUES (?,?,?,?,?,?,?)',
        [$accountId, mb_substr($kind, 0, 40), mb_substr($title, 0, 180), mb_substr($body, 0, 500),
         mb_substr($page, 0, 40), mb_substr(http_build_query($params), 0, 255), now()]);
}

/** Tell every member of staff. Used for the things only they can act on. */
function notify_staff(string $kind, string $title, string $body = '', string $page = '', array $params = []): int {
    $sent = 0;
    foreach (rows("SELECT id FROM accounts WHERE role IN ('admin','trainer','manager') AND state='active'") as $a) {
        notify((int)$a['id'], $kind, $title, $body, $page, $params);
        $sent++;
    }
    return $sent;
}

/** The pane's contents: newest first, read and unread together. */
function notifications_for(int $accountId, int $limit = 30): array {
    return rows('SELECT * FROM notifications WHERE account_id=? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)), [$accountId]);
}

function unread_notifications(int $accountId): int {
    return (int)scalar('SELECT COUNT(*) FROM notifications WHERE account_id=? AND read_at IS NULL', [$accountId]);
}

/** Where a notification points, or the dashboard when it no longer points anywhere. */
function notification_link(array $notification): string {
    $page = (string)$notification['link_page'];
    if ($page === '') return url('dashboard');
    parse_str((string)$notification['link_params'], $params);
    return url($page, is_array($params) ? array_map('strval', $params) : []);
}

function notification_icon(string $kind): string {
    return match ($kind) {
        'payment' => 'wallet', 'message' => 'mail', 'request' => 'users',
        'schedule' => 'calendar', 'problem' => 'lock', default => 'news',
    };
}

// ---------------------------------------------------------------------------
// Colours
// ---------------------------------------------------------------------------

/**
 * The accents anybody may pick, as name => the one colour that changes.
 *
 * A fixed set rather than a colour picker. Every one of these has been chosen to
 * keep white text on it readable, which a free choice cannot promise - and the
 * people most likely to enjoy choosing are the children, who should not be able
 * to make their own portal unreadable by accident.
 */
function accents(): array {
    return [
        'teal'   => t('Türkis',  'Teal'),
        'blue'   => t('Blau',    'Blue'),
        'violet' => t('Violett', 'Violet'),
        'pink'   => t('Pink',    'Pink'),
        'red'    => t('Rot',     'Red'),
        'orange' => t('Orange',  'Orange'),
        'green'  => t('Grün',    'Green'),
        'slate'  => t('Grau',    'Slate'),
    ];
}

/**
 * Which accent applies: the person's own, or the one the administrator set.
 *
 * Both are validated against the list, so a value left behind by an accent that
 * has since been removed falls back rather than producing a page with no colour.
 */
function accent_for(?array $user): string {
    $own = (string)($user['accent'] ?? '');
    if (isset(accents()[$own])) return $own;
    $default = (string)setting('default_accent');
    return isset(accents()[$default]) ? $default : 'teal';
}

// ---------------------------------------------------------------------------
// Profile pictures
// ---------------------------------------------------------------------------

/** The initials shown when there is no picture. Two letters, never more. */
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
    if (!$parts) return '?';
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr((string)end($parts), 0, 1) : ''));
}

/**
 * An avatar: the picture if there is one, the initials if not.
 *
 * $size is a class rather than a pixel count, so every avatar in the portal is
 * one of three sizes and a new one cannot be almost-but-not-quite the same as
 * the others.
 */
function avatar(array $who, string $size = '', string $kind = 'account'): string {
    $name = (string)($who['name'] ?? trim(($who['first_name'] ?? '') . ' ' . ($who['last_name'] ?? '')));
    $class = 'avatar' . ($size !== '' ? ' ' . $size : '');
    if (($who['avatar_name'] ?? '') !== '')
        return '<span class="' . e($class) . ' has-photo"><img src="'
            . e(url('download', ['what' => 'avatar', 'kind' => $kind, 'id' => (int)($who['id'] ?? 0)]))
            . '" alt="" loading="lazy"></span>';
    return '<span class="' . e($class) . '">' . e(initials($name)) . '</span>';
}

// ---------------------------------------------------------------------------
// Looking through somebody else's eyes
// ---------------------------------------------------------------------------

/**
 * Who is really signed in, when somebody is being impersonated.
 *
 * Kept in the session rather than in the database, so it cannot outlive the
 * browser session it was started in, and so signing out ends it whatever else
 * happens.
 */
function impersonator(): ?array {
    if (empty($_SESSION['impersonator_id'])) return null;
    return one('SELECT * FROM accounts WHERE id=?', [(int)$_SESSION['impersonator_id']]);
}

/**
 * Whether $actor may look through $target's eyes.
 *
 * An administrator may look at anybody but themselves. A trainer may look at a
 * family, because seeing what a parent sees is how she checks that the portal
 * makes sense - but not at another member of staff, and never at an
 * administrator, or impersonation would be a way to acquire rights rather than
 * to lose them.
 */
function may_impersonate(array $actor, array $target): bool {
    if ((int)$actor['id'] === (int)$target['id']) return false;
    if ($target['state'] !== 'active') return false;
    if (is_admin($actor)) return true;
    return is_staff($actor) && !is_staff($target);
}

/** Start looking. The real account is remembered; the session becomes theirs. */
function start_impersonation(int $targetId): array {
    $actor = require_staff();
    $target = one('SELECT * FROM accounts WHERE id=?', [$targetId]);
    if (!$target || !may_impersonate($actor, $target))
        throw new UserError(t('Dieses Konto kannst du nicht ansehen.', 'You cannot view this account.'));
    audit('impersonation.started', 'account', $targetId);
    // Deliberately not session_regenerate_id(): the impersonator's own identity
    // stays in this session and has to survive into the next request.
    $_SESSION['impersonator_id'] = (int)$actor['id'];
    $_SESSION['user_id'] = (int)$target['id'];
    $_SESSION['auth_version'] = (int)$target['auth_version'];
    $_SESSION['last_seen'] = time();
    current_user(true);
    return $target;
}

/** Stop looking, and go back to being yourself. */
function stop_impersonation(): void {
    $real = impersonator();
    if (!$real) return;
    audit('impersonation.ended', 'account', (int)($_SESSION['user_id'] ?? 0));
    unset($_SESSION['impersonator_id']);
    $_SESSION['user_id'] = (int)$real['id'];
    $_SESSION['auth_version'] = (int)$real['auth_version'];
    $_SESSION['last_seen'] = time();
    current_user(true);
}

// ---------------------------------------------------------------------------
// "This is broken"
// ---------------------------------------------------------------------------

/*
 * The last steps somebody took, so a report says how they got where it broke
 * (ADR 0009). Kept in the session, never in the database: a GET must not write,
 * and the trail is only ever read at the moment somebody files a report.
 *
 * Posted values are kept, because "what did you type?" is the question a report
 * otherwise cannot answer. Secrets never are - see is_secret_field() - and
 * everything is cut to a size a session file can carry into every request.
 */

/** How many steps a report carries, oldest first. */
const REPORT_STEPS = 8;
/** Pages that are not somewhere a person went: pictures, the icon, the manifest. */
const REPORT_UNRECORDED_PAGES = ['download', 'icon', 'manifest'];

/**
 * Write down the request being served, for a report filed later.
 *
 * Called once, from public/index.php, before the POST branch: that branch
 * redirects and never comes back, so recorded anywhere later the trail would
 * have a hole exactly where a form was sent.
 *
 * Only while somebody is signed in. That leaves out the login form, with the
 * address typed into it, and every token link opened by nobody in particular;
 * an anonymous visitor cannot file a report anyway. The trail belongs to one
 * account: when a different one is signed in - after an idle expiry on a shared
 * phone, or at the start of looking through somebody else's eyes - it starts
 * again rather than handing one person's typing to another's report.
 *
 * One of the two places allowed to read $_POST outside an action (ADR 0009): it
 * copies input into the session for display and acts on none of it.
 */
function record_step(string $page): void {
    if (in_array($page, REPORT_UNRECORDED_PAGES, true)) return;
    $account = current_user();
    if (!$account) return;
    $method = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
    $action = $method === 'POST' && is_scalar($_POST['action'] ?? null) ? (string)$_POST['action'] : '';
    // The report itself is not a step on the way to the problem, and the
    // steps before it are the ones it is meant to carry.
    if ($action === 'feedback_send') return;

    if ((int)($_SESSION['steps_account'] ?? 0) !== (int)$account['id']) {
        $_SESSION['steps'] = [];
        $_SESSION['steps_account'] = (int)$account['id'];
    }
    $step = ['at' => now(), 'method' => $method, 'url' => report_url()];
    if ($method === 'POST') {
        $step['action'] = report_text($action, 60);
        $step['from']   = form_origin();
        // The form token and the request id are on every form and say nothing
        // about this one - the token would only ever read *** - and the action
        // and where it came from have their own fields above. is_secret_field()
        // still names csrf, for remember_input() and for any other use.
        $step['fields'] = report_input(array_diff_key($_POST,
            array_flip(['csrf', 'request_id', 'action', 'return_page', 'return_id', 'return_tab'])));
        $step['files']  = report_files();
    } elseif (is_array($_SESSION['flash'] ?? null)) {
        // The outcome of the POST before this page, which redirected here. The
        // layout shows it and forgets it later in this same request.
        $step['flash'] = ['kind' => report_text((string)($_SESSION['flash']['kind'] ?? ''), 20),
                          'text' => report_text((string)($_SESSION['flash']['message'] ?? ''))];
    }
    $steps = is_array($_SESSION['steps'] ?? null) ? $_SESSION['steps'] : [];
    $steps[] = $step;
    $_SESSION['steps'] = array_slice($steps, -REPORT_STEPS);
}

/** The trail of the account signed in now, oldest step first. */
function recent_steps(): array {
    $account = current_user();
    if (!$account || (int)($_SESSION['steps_account'] ?? 0) !== (int)$account['id']) return [];
    return array_values(is_array($_SESSION['steps'] ?? null) ? $_SESSION['steps'] : []);
}

/**
 * Where the form being sent was: its page, record and tab.
 *
 * Read from the return_* fields every start_form() writes, because that is the
 * page the form was really on - the back button can show a page without
 * requesting it, so the last step in the trail is not always it.
 */
function form_origin(): array {
    $read = fn(string $key): string => is_scalar($_POST[$key] ?? null) ? trim((string)$_POST[$key]) : '';
    return ['page' => report_text($read('return_page'), 60), 'id' => (int)$read('return_id'),
            'tab' => report_text($read('return_tab'), 60)];
}

/** Text as a report may keep it: valid UTF-8, and at most $max characters. */
function report_text(string $text, int $max = 200): string {
    $text = mb_scrub($text, 'UTF-8');
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

/**
 * Submitted values as a report may keep them.
 *
 * A secret is replaced by *** whatever was typed, even nothing, and at every
 * depth, so custom[api_token] is as safe as password. Otherwise: 40 fields,
 * 20 items per array, two levels of arrays, 200 characters per value. On top of
 * that the fields of one step stop at 16 kB: the limits multiply out to
 * megabytes for a form built to do so, and this sits in the session for the
 * next eight requests. No form in the portal comes near it.
 */
function report_input(array $input, int $depth = 0): array {
    $out = []; $bytes = 0;
    foreach ($input as $key => $value) {
        if (count($out) >= ($depth === 0 ? 40 : 20)) break;
        // Judged on the whole name, before it is shortened: cut first, and a
        // long name ending in "password" would lose the word that marks it.
        $secret = is_secret_field((string)$key);
        $key = report_text((string)$key, 60);
        if ($secret) $kept = '***';
        elseif (is_array($value)) $kept = $depth < 2 ? report_input($value, $depth + 1) : '…';
        else $kept = report_text(is_scalar($value) ? (string)$value : '');
        if ($depth === 0) {
            $bytes += strlen((string)json_encode([$key => $kept], JSON_UNESCAPED_UNICODE));
            if ($bytes > 16384) { $out['…'] = '…'; break; }
        }
        $out[$key] = $kept;
    }
    return $out;
}

/**
 * What was attached to the form: size, the type the browser claimed, and PHP's
 * error code for each file. Never the name, which is the person's own words,
 * and never the content.
 */
function report_files(): array {
    $describe = fn(mixed $size, mixed $type, mixed $error): array =>
        ['bytes' => (int)$size, 'type' => report_text(is_scalar($type) ? (string)$type : '', 100), 'error' => (int)$error];
    $out = [];
    foreach (array_slice($_FILES, 0, 20, true) as $field => $file) {
        if (!is_array($file) || !isset($file['error'])) continue;
        $key = report_text((string)$field, 60);
        if (!is_array($file['error'])) { $out[$key] = $describe($file['size'] ?? 0, $file['type'] ?? '', $file['error']); continue; }
        // name="files[]": PHP turns the list inside out, one array per property.
        foreach (array_slice(array_keys($file['error']), 0, 20) as $i)
            $out[$key][] = $describe($file['size'][$i] ?? 0, $file['type'][$i] ?? '', is_scalar($file['error'][$i]) ? $file['error'][$i] : 0);
    }
    return $out;
}

/**
 * The address of this request: the path, then the query with secrets masked.
 *
 * Rebuilt from $_GET rather than copied from REQUEST_URI, so an email-change
 * token or an unsubscribe signature is never written down, not even once.
 */
function report_url(): string {
    $path = explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''), 2)[0];
    $query = http_build_query(report_input($_GET), '', '&', PHP_QUERY_RFC3986);
    // '*' is legal in a query string, so *** is written as itself rather than
    // as %2A%2A%2A, which nobody reading a report would recognise.
    return report_text($path . ($query !== '' ? '?' . str_replace('%2A', '*', $query) : ''), 300);
}

/**
 * Everything worth knowing about the request that was being made.
 *
 * Collected by the server rather than asked of the person: nobody reporting a
 * problem on a phone is going to find their browser version, and the answer
 * "what were you doing?" is already in the steps that led here.
 *
 * There is no query string and no Referer here any more. The report is a POST
 * to index.php, which has no query, and the portal sends no-referrer, so both
 * were empty in every report ever filed; `on` and `steps` are what replaced them.
 */
function feedback_context(string $page): array {
    return [
        'page'        => $page,
        'on'          => form_origin(),
        'steps'       => recent_steps(),
        'user_agent'  => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
        'ip'          => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'language'    => mb_substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 120),
        'locale'      => locale(),
        'version'     => app_version(),
        'php'         => PHP_VERSION,
        'at'          => now(),
    ];
}

/**
 * A report's context as it is stored.
 *
 * One malformed byte in a user agent used to be enough for json_encode() to
 * return false and the whole context to be stored as nothing; it is replaced
 * instead, and the rest of the report survives.
 */
function feedback_context_json(array $context): string {
    return json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
}

/**
 * The context of a report once it is done: what was typed is gone, the way
 * there stays.
 *
 * A report holds copies of what people typed, and a report that is dealt with
 * no longer needs them. URLs, methods, actions and the names of the fields are
 * kept, because "Erledigt" is sometimes wrong and the steps are what makes an
 * old report readable; each value becomes null, which a page can tell apart
 * from something that was sent empty. Not undoable - that is the point.
 */
function feedback_forget_typed_values(array $context): array {
    foreach ((array)($context['steps'] ?? []) as $i => $step) {
        if (!is_array($step)) continue;
        foreach (['fields', 'files'] as $part)
            if (isset($step[$part]) && is_array($step[$part]))
                $context['steps'][$i][$part] = array_fill_keys(array_keys($step[$part]), null);
    }
    $context['values_dropped_at'] ??= now();
    return $context;
}

/**
 * How long a report stays once it is marked done. The owner's decision, and the
 * privacy notice can name it, so it is a fixed rule rather than a setting: a
 * value she could change without a screen to change it on is not a choice, it
 * is a second place for the notice and the code to disagree.
 */
const FEEDBACK_DONE_KEEP_DAYS = 30;

/**
 * The context of a report being marked done: what was typed is dropped, and
 * the clock for deleting it starts. `done_at` is written every time, so a
 * report opened again and done again is kept for the full period from then;
 * `values_dropped_at` keeps the first time, because that is when they went.
 */
function feedback_mark_done(array $context): array {
    return ['done_at' => now()] + feedback_forget_typed_values($context);
}

/**
 * Delete reports that were marked done more than FEEDBACK_DONE_KEEP_DAYS ago.
 *
 * The done time lives in context_json, so no column was needed for it. A done
 * report that carries no done time - one marked done before this rule existed -
 * is given one now, which keeps it for the full period from the first prune
 * rather than guessing it was done on the day it was filed.
 *
 * The screenshot is not deleted here: prune_uploads() removes a picture once no
 * row points at it, and it runs straight after this in prune_expired().
 *
 * Each write repeats the state and the context it read, so a report she opens
 * again while this runs is left alone rather than deleted from under her.
 */
function prune_done_feedback(): int {
    $cutoff = gmdate('Y-m-d H:i:s', time() - FEEDBACK_DONE_KEEP_DAYS * 86400);
    return transactional(function () use ($cutoff): int {
        $removed = 0;
        foreach (rows("SELECT id, context_json FROM feedback WHERE state='done'") as $report) {
            $raw = (string)$report['context_json'];
            $context = json_decode($raw, true);
            if (!is_array($context)) $context = [];
            $doneAt = (string)($context['done_at'] ?? $context['values_dropped_at'] ?? '');
            if ($doneAt === '') {
                run("UPDATE feedback SET context_json=? WHERE id=? AND state='done' AND context_json=?",
                    [feedback_context_json(['done_at' => now()] + $context), $report['id'], $raw]);
            } elseif ($doneAt < $cutoff) {
                $removed += run("DELETE FROM feedback WHERE id=? AND state='done' AND context_json=?",
                                [$report['id'], $raw])->rowCount();
            }
        }
        return $removed;
    });
}

/** Feedback waiting to be looked at. */
function open_feedback(int $limit = 50): array {
    return rows('SELECT f.*, a.name AS account_name, a.email FROM feedback f LEFT JOIN accounts a ON a.id=f.account_id'
        .' ORDER BY f.state<>\'new\', f.id DESC LIMIT ' . max(1, min(200, $limit)));
}

function unread_feedback(): int { return (int)scalar("SELECT COUNT(*) FROM feedback WHERE state='new'"); }
