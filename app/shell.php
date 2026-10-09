<?php
declare(strict_types=1);

/**
 * The things around the edges of every page.
 *
 * Notifications, accent colours, how a person is drawn - a picture or the
 * initials - looking through somebody else's eyes, and the button that says
 * this is broken. Grouped together
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
 *
 * Nothing is written for a placeholder (ADR 0023 §3): nobody reads its bell,
 * and the invitation that later turns it into a login would hand the family a
 * list of old news. Decided here, in the statement that writes, so no caller
 * has to remember it - the invoice, the decided request and the changed date
 * each had to, and two did not.
 */
function notify(int $accountId, string $kind, string $title, string $body = '', string $page = '', array $params = []): void {
    run("INSERT INTO notifications (account_id,kind,title,body,link_page,link_params,created_at) SELECT id,?,?,?,?,?,? FROM accounts WHERE id=? AND state<>'placeholder'",
        [mb_substr($kind, 0, 40), mb_substr($title, 0, 180), mb_substr($body, 0, 500),
         mb_substr($page, 0, 40), notice_params($params), now(), $accountId]);
}

/** A notice's link parameters as stored: the one form notify() writes and withdraw_notices() finds. */
function notice_params(array $params): string { return mb_substr(http_build_query($params), 0, 255); }

/**
 * Take back from every bell, read or not, the notices of $kind that point at
 * $page with $params: for something nobody can open any more, such as a news
 * item unpublished (design, Part 7).
 */
function withdraw_notices(string $kind, string $page, array $params): void {
    run('DELETE FROM notifications WHERE kind=? AND link_page=? AND link_params=?', [$kind, $page, notice_params($params)]);
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

/**
 * Tell every active administrator. Used for what only they can deal with: a
 * problem somebody reported, an error nobody had to (ADR 0012), and a change to
 * where the families' money goes (ADR 0025).
 */
function notify_admins(string $kind, string $title, string $body = '', string $page = '', array $params = []): int {
    $sent = 0;
    foreach (rows("SELECT id FROM accounts WHERE role='admin' AND state='active'") as $a) {
        notify((int)$a['id'], $kind, $title, $body, $page, $params);
        $sent++;
    }
    return $sent;
}

/**
 * Tell every administrator that where the families' money goes has changed -
 * a payment profile's account, a new profile, a course pointed at another one
 * (ADR 0025, amended 2026-10-08) - with who did it, $by, ahead of $what. The
 * trainer may make these changes, and so may whoever holds her login.
 */
function notify_admins_of_bank_change(array $by, string $title, string $what, string $page, array $params): int {
    return notify_admins('bank', $title, t('Von ', 'By ') . login_holder_name($by) . ' (' . role_label((string)$by['role']) . '): ' . $what, $page, $params);
}

/**
 * The kinds of notice the bell shows while $viewer looks through somebody else's
 * eyes: those that quote nothing $viewer could not read anyway. A chat notice is
 * not one - its text quotes the message, and a request to write is one too - so
 * the bell would hand a trainer the words of a child's private chat that
 * thread_seen_sql() keeps from her in the chat itself (security review, ADR 0022
 * §9). Listed by what may be shown rather than by what may not, so a kind added
 * later stays hidden until somebody has asked what it quotes.
 */
function notice_kinds_shown_while_viewing(array $viewer): array {
    return [
        'payment',      // an invoice's number, amount and date, which staff wrote
        'schedule',     // a course's date and whether it takes place, which staff wrote
        'request',      // somebody new, or a course asked for or decided: on pages staff read
        'news',         // a news item's title and opening, which staff wrote for every family
        // What somebody reported as broken, which administrators read under
        // Einstellungen › Rückmeldungen. Asked of who is looking rather than of
        // whose bell it is: a login that was an administrator once keeps these.
        ...(is_admin($viewer) ? ['problem'] : []),
    ];
}

/**
 * Which of an account's notifications the bell may show now, as one condition
 * over `notifications` and its parameters. The pane and its count both ask it,
 * so the bell never counts a notice its pane would not show.
 */
function notifications_seen_sql(int $accountId): array {
    $viewer = impersonator();
    if (!$viewer) return ['account_id=?', [$accountId]];
    $kinds = notice_kinds_shown_while_viewing($viewer);
    return ['account_id=? AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')', [$accountId, ...$kinds]];
}

/** The pane's contents: newest first, read and unread together. */
function notifications_for(int $accountId, int $limit = 30): array {
    [$seen, $params] = notifications_seen_sql($accountId);
    return rows('SELECT * FROM notifications WHERE ' . $seen . ' ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)), $params);
}

function unread_notifications(int $accountId): int {
    [$seen, $params] = notifications_seen_sql($accountId);
    return (int)scalar('SELECT COUNT(*) FROM notifications WHERE ' . $seen . ' AND read_at IS NULL', $params);
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
        'payment', 'bank' => 'wallet', 'message' => 'chat', 'request' => 'users',
        'schedule' => 'calendar', 'problem' => 'lock', 'picture' => 'camera', 'news' => 'news', default => 'news',
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
 * Which accent applies: the person's own, the club's main colour, or the preset
 * the administrator set.
 *
 * 'brand' is not one of accents(): it is what „Wie eingestellt" means once the
 * club has a main colour (ADR 0013), and the brand stylesheet colours it. If
 * that stylesheet fails to load, no rule matches and the built-in teal shows.
 * The rest are validated against the list, so a value left behind by an accent
 * that has since been removed falls back rather than producing a page with no
 * colour.
 */
function accent_for(?array $user): string {
    $own = (string)($user['accent'] ?? '');
    if (isset(accents()[$own])) return $own;
    if (brand_chosen()['brand_primary'] !== '') return 'brand';
    $default = (string)setting('default_accent');
    return isset(accents()[$default]) ? $default : 'teal';
}

// ---------------------------------------------------------------------------
// A person: a picture, a child's or a team member's, or the initials
// ---------------------------------------------------------------------------

/** The initials that stand for a person. Two letters, never more. */
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
    if (!$parts) return '?';
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr((string)end($parts), 0, 1) : ''));
}

/**
 * An avatar's side in CSS pixels, by its size: what app.css draws, and what the
 * picture in it says it is before it has loaded, so nothing moves when it does.
 */
const AVATAR_SIDES = ['tiny' => 32, 'small' => 36, '' => 40, 'large' => 72];

/**
 * How a person is drawn, everywhere: their picture, or the initials (ADR 0031
 * §6). The one helper, so who may see a face is asked in one place.
 *
 * The picture is drawn when the row has one (picture_of(): a child's, or a team
 * member's own), its file is there and the one rule lets whoever is signed in
 * see it (may_see_picture()); the initials otherwise, and for a course's letter.
 * No query per face: the rows a page draws carry what the rule needs, and the
 * rule asks its one question once a request. The address carries the picture's
 * version, so the browser keeps it while it is the picture in use
 * (picture_cache_control()) and asks again the moment it changes. alt is empty,
 * because the name stands beside every face.
 *
 * $size is a class rather than a pixel count, so every avatar in the portal is
 * one of four sizes (AVATAR_SIDES) and a new one cannot be almost-but-not-quite
 * the same as the others.
 */
function avatar(array $who, string $size = ''): string {
    $name = (string)($who['name'] ?? trim(($who['first_name'] ?? '') . ' ' . ($who['last_name'] ?? '')));
    $class = e('avatar' . ($size !== '' ? ' ' . $size : ''));
    $picture = picture_of($who);
    $viewer = current_user();
    if ($picture !== null && $viewer !== null && picture_stored($picture['picture_name']) && may_see_picture($viewer, $picture)) {
        $side = AVATAR_SIDES[$size] ?? AVATAR_SIDES[''];
        // Their own face is in the top bar of every page, in view the moment it
        // opens: lazy would only hold its request back. Every other face waits
        // until it is scrolled to.
        $own = array_key_exists('role', $who) && (int)($who['id'] ?? 0) === (int)$viewer['id'];
        return '<span class="' . $class . '"><img src="'
            . e(url('download', ['what' => 'picture', 'kind' => $picture['team'] ? 'account' : 'student',
                                 'id' => $picture['id'], 'v' => upload_version($picture['picture_name'])]))
            . '" alt="" width="' . $side . '" height="' . $side . '"' . ($own ? '' : ' loading="lazy"') . '></span>';
    }
    return '<span class="' . $class . '">' . e(initials($name)) . '</span>';
}

// ---------------------------------------------------------------------------
// Looking through somebody else's eyes
// ---------------------------------------------------------------------------

/**
 * Who is really signed in, when somebody is being impersonated.
 *
 * Kept in the session rather than in the database, so it cannot outlive the
 * browser session it was started in, and so signing out ends it whatever else
 * happens. Nor does it outlive the viewer's own login: her row is asked on every
 * request - current_user() asks this as it finds a session good - whether it is
 * still there, still may look at this account (may_impersonate()), and still has
 * the auth_version it had as the view began. Deleted, suspended, or with a
 * password changed since, she is looking at nothing any more.
 *
 * A view that has ended takes the whole session with it. Answering nobody and
 * carrying on is what turned one into the child's own session, with no bar and
 * nothing to limit it - every limit on a view asks this (security re-review N1).
 */
function impersonator(): ?array {
    if (empty($_SESSION['impersonator_id'])) return null;
    // A view needs somebody signed in to be a view of: an id left over from a
    // session that ended is no view, and is dropped (security review F1).
    $looked = current_user();
    if (!$looked) { unset($_SESSION['impersonator_id']); return null; }
    $real = one('SELECT * FROM accounts WHERE id=?', [(int)$_SESSION['impersonator_id']]);
    if ($real && (int)$real['auth_version'] === (int)($_SESSION['impersonator_auth_version'] ?? 0)
        && may_impersonate($real, $looked)) return $real;
    unset($_SESSION['user_id'], $_SESSION['auth_version']);
    forget_session_leftovers();
    current_user(true);
    return null;
}

/**
 * What the portal says while somebody looks through another's eyes: the refusal
 * of every action but stopping the view and signing out (dispatch_action()),
 * and the line the chat shows where its writing box would be. One sentence, so
 * the two cannot come to say different things.
 */
function viewing_refusal(): string {
    return t('Beim Ansehen als jemand anderer lässt sich nichts schreiben oder ändern. Beende zuerst die Ansicht.',
             'While viewing as somebody else, nothing can be written or changed. Stop viewing first.');
}

/**
 * Whether $actor may look through $target's eyes.
 *
 * An administrator may look at anybody but themselves. A trainer may look at a
 * family, because seeing what a parent sees is how she checks that the portal
 * makes sense - but not at another member of staff, and never at an
 * administrator, or impersonation would be a way to acquire rights rather than
 * to lose them.
 *
 * Only a login in use - active and set up by its holder. A view is a session as
 * that login, and current_user() keeps a session for nothing else; an exception
 * for a placeholder or a login not yet signed in would widen the one check made
 * on every request, for a login with nothing of its own to show that the
 * student page does not already show staff (ADR 0023 §12, A6 overruled). The
 * same of the one looking: impersonator() asks this again of an open view on
 * every request, and a viewer suspended since may look at nobody.
 */
function may_impersonate(array $actor, array $target): bool {
    if ((int)$actor['id'] === (int)$target['id']) return false;
    foreach ([$actor, $target] as $login)
        if (($login['state'] ?? '') !== 'active' || empty($login['verified_at'])) return false;
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
    // stays in this session and has to survive into the next request - with
    // her auth_version, which impersonator() asks her row for on every one.
    $_SESSION['impersonator_id'] = (int)$actor['id'];
    $_SESSION['impersonator_auth_version'] = (int)$actor['auth_version'];
    $_SESSION['user_id'] = (int)$target['id'];
    $_SESSION['auth_version'] = (int)$target['auth_version'];
    $_SESSION['last_seen'] = time();
    current_user(true);
    return $target;
}

/**
 * Stop looking, and go back to being yourself.
 *
 * Only from a view that is still open: impersonator() answers nobody unless
 * somebody is signed in as the account being looked at. An impersonator id
 * with nobody signed in is a view whose session has ended, and stopping it
 * would sign the browser in as the staff member without a password (security
 * review F1).
 */
function stop_impersonation(): void {
    $real = impersonator();
    if (!$real) return;
    audit('impersonation.ended', 'account', (int)($_SESSION['user_id'] ?? 0));
    unset($_SESSION['impersonator_id'], $_SESSION['impersonator_auth_version']);
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
/**
 * Pages that are not somewhere a person went: downloads - a chat fetches every
 * photo in it through one - the icon, the manifest, the club's stylesheet and
 * logo, which every page fetches. Recorded, they would push the steps she
 * actually took out of the trail.
 */
const REPORT_UNRECORDED_PAGES = ['download', 'icon', 'manifest', 'brand', 'logo'];

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
        // The bookkeeping says nothing about this form, and the action and
        // where it came from have their own fields above. Nor does the token,
        // which is on every form and would only ever read ***; is_secret_field()
        // still names csrf, for remember_input() and for any other use.
        $step['fields'] = report_input(array_diff_key($_POST, array_flip([...FORM_BOOKKEEPING_FIELDS, 'csrf'])));
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
    return ['page' => report_text(form_bookkeeping('return_page'), 60), 'id' => (int)form_bookkeeping('return_id'),
            'tab' => report_text(form_bookkeeping('return_tab'), 60)];
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
 * depth, so a nested one, x[api_token], is as safe as password. Otherwise: 40 fields,
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
    // Written in place rather than put first: an automatic entry is recognised
    // by how its context begins (ADR 0012), and marking it done must not change
    // that, or the next repeat of the error would open a second entry.
    $context = feedback_forget_typed_values($context);
    $context['done_at'] = now();
    return $context;
}

/**
 * Delete reports that were marked done more than FEEDBACK_DONE_KEEP_DAYS ago.
 *
 * The done time lives in context_json, so no column was needed for it. A done
 * report that carries no done_at - one marked done before this rule existed -
 * is given one now, which keeps it for the full period from the first prune
 * rather than guessing. values_dropped_at is not a done time: it is the first
 * time only, and a report opened again and done since would be counted from it.
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
            $doneAt = (string)($context['done_at'] ?? '');
            if ($doneAt === '') {
                // Appended, for the reason feedback_mark_done() gives.
                run("UPDATE feedback SET context_json=? WHERE id=? AND state='done' AND context_json=?",
                    [feedback_context_json($context + ['done_at' => now()]), $report['id'], $raw]);
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

// ---------------------------------------------------------------------------
// Errors nobody had to report (ADR 0012)
// ---------------------------------------------------------------------------

/*
 * When the portal breaks, it writes the error down itself, with the steps the
 * person took before it, and the administrators find it under Einstellungen →
 * Rückmeldungen with a text to copy for whoever helps her. The owner is not
 * technical enough to read a server log, and until this the log was the only
 * place an error went.
 *
 * Stored in the feedback table rather than a table of its own, so the list, the
 * unread count, the notification and the deletion are the ones reports already
 * have. An automatic entry has no account - it gathers everybody who hit the
 * error - and a context that begins with ERROR_CONTEXT_PREFIX. A person's
 * report begins with {"page": and can never match, whatever was typed into it.
 */

/** How an automatic entry's stored context begins, and nothing else's does. */
const ERROR_CONTEXT_PREFIX = '{"kind":"error","fingerprint":"';

/** Days an automatic entry is kept after the error was last seen, whatever its state. */
const ERROR_KEEP_DAYS = 30;

/**
 * Automatic entries not marked done, at most. An error that fires on every page
 * with a different line each time must not fill the table; past this, entries
 * already there still count up and anything new goes to the log only.
 */
const ERROR_OPEN_MAX = 50;

/** Bytes of context one automatic entry may hold. Step values go first, then steps. */
const ERROR_CONTEXT_MAX = 65536;

/**
 * Write an unexpected error down for the administrators. Never throws.
 *
 * $where is 'request' for a page or a form, 'background' for the work done
 * after a page was sent. Called from exactly the places ADR 0012 names - the
 * router's two catches, the two in app/tick.php, and capture_fatal_error() -
 * and the structure suite holds it to that.
 *
 * A refusal is not an error: a UserError, NotFound and every "Kein Zugriff" are
 * answers the portal meant to give. One capture per request, because the second
 * error in a request is nearly always the first one's consequence.
 *
 * The log line comes first, so the error is in the log even when writing it
 * down fails. Nothing is written to the database during maintenance, when no
 * connection was ever opened, or when the connection is the thing that broke:
 * there is nothing to write with, and opening a connection now would be the
 * worst moment to find that out.
 */
function capture_error(Throwable $e, string $where = 'request'): void {
    if ($e instanceof UserError) return;
    $state =& error_capture_state();
    if ($state['captured']) return;
    $state['captured'] = true;
    try {
        $facts = error_facts($e);
        error_log('CRM error (' . $where . '): ' . error_log_text($e) . ' at ' . $facts['file'] . ':' . $facts['line']);
        if (is_file(maintenance_file()) || !db_connected() || error_connection_lost($e)) return;
        tx_abandon_open('error');
        transactional(fn() => error_record($facts, $where === 'background' ? 'background' : 'request'));
    } catch (Throwable $failed) {
        try { error_log('CRM: the error above could not be written down: ' . get_class($failed)); } catch (Throwable) {}
    }
}

/**
 * Whether this request has captured its error yet, by reference.
 *
 * A request is one process here and captures once; the test harness is one
 * process for many requests, and empties it with error_capture_reset().
 */
function &error_capture_state(): array { static $state = ['captured' => false]; return $state; }
function error_capture_reset(): void { $state =& error_capture_state(); $state = ['captured' => false]; }

/**
 * A fatal error - a timeout, the memory running out - which no catch can see.
 *
 * Registered as a shutdown function by public/index.php. $error is what
 * error_get_last() says, and is a parameter so a test can hand it a fatal error
 * without having one. A warning is not captured: at this volume they are noise.
 */
function capture_fatal_error(?array $error = null): void {
    $error ??= error_get_last();
    if (!$error || !in_array((int)($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    capture_error(new ErrorException((string)($error['message'] ?? ''), 0, (int)$error['type'],
                                     (string)($error['file'] ?? ''), (int)($error['line'] ?? 0)));
}

/**
 * What an error is, without anything a family typed or the database echoed.
 *
 * A PDOException's message is not kept at all: MariaDB puts values into it -
 * "Duplicate entry 'familie@…'" - and a mask that misses one engine's wording
 * would leak an address into a support email. Its SQLSTATE, its code and where
 * it was thrown say what it was. Stack frames keep where and what was called,
 * never the arguments.
 */
function error_facts(Throwable $e): array {
    $pdo = $e instanceof PDOException;
    [$sqlstate, $driverCode] = $pdo ? pdo_error_codes($e) : ['', null];
    $file = error_path($e->getFile());
    $frames = [];
    foreach ($e->getTrace() as $frame) {
        // An error made by the handler itself - a fatal error, which has no
        // trace of its own - has only the handler and whatever called it
        // outward from here, and none of that is about the error.
        if (in_array($frame['function'] ?? '', ['capture_error', 'capture_fatal_error'], true)) break;
        $frames[] = ['file' => isset($frame['file']) ? error_path((string)$frame['file']) : '', 'line' => (int)($frame['line'] ?? 0),
                     'class' => (string)($frame['class'] ?? ''), 'type' => (string)($frame['type'] ?? ''),
                     // A closure is named after where it was written, path and all.
                     'function' => error_without_folder((string)($frame['function'] ?? ''))];
        if (count($frames) === 20) break;
    }
    $code = $pdo ? ($sqlstate !== '' ? $sqlstate : (string)$driverCode) : (string)$e->getCode();
    return [
        'fingerprint' => sha1(get_class($e) . '|' . $code . '|' . $file . ':' . $e->getLine()),
        'class'    => get_class($e),
        'sqlstate' => $sqlstate,
        'code'     => $pdo ? $driverCode : $e->getCode(),
        'message'  => error_from_database($e) ? '' : error_message_scrub($e->getMessage()),
        'file'     => $file,
        'line'     => $e->getLine(),
        'frames'   => $frames,
        'previous' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
    ];
}

/**
 * Whether anything in the chain came from the database. Its message is then
 * not kept, whoever wrapped it: a RuntimeException that quotes the PDOException
 * it caught carries "Duplicate entry 'familie@…'" just the same.
 */
function error_from_database(Throwable $e): bool {
    for ($x = $e; $x !== null; $x = $x->getPrevious())
        if ($x instanceof PDOException || str_contains($x->getMessage(), 'SQLSTATE[')) return true;
    return false;
}

/**
 * One error as a log line: its class, and for a database error anywhere in the
 * chain the SQLSTATE and code - never its message - otherwise the scrubbed
 * message. The line every log call about an unexpected error writes.
 */
function error_log_text(Throwable $e): string {
    for ($x = $e; $x !== null; $x = $x->getPrevious())
        if ($x instanceof PDOException) {
            [$sqlstate, $code] = pdo_error_codes($x);
            return get_class($e) . ' SQLSTATE ' . ($sqlstate ?: '?') . ' code ' . ($code ?? '?');
        }
    return get_class($e) . (error_from_database($e) ? '' : ': ' . error_message_scrub($e->getMessage()));
}

/**
 * The SQLSTATE and the driver's own error number of a database error.
 *
 * errorInfo when the driver filled it in; otherwise read off the code and the
 * message's "SQLSTATE[HY000] [2002]" prefix, which is how a failed connect
 * reports itself. Only the codes are read from the message, never kept.
 */
function pdo_error_codes(PDOException $e): array {
    $info = is_array($e->errorInfo) ? $e->errorInfo : [];
    $sqlstate = is_string($info[0] ?? null) ? $info[0] : '';
    $driver = isset($info[1]) && is_numeric($info[1]) ? (int)$info[1] : null;
    $code = $e->getCode();
    if ($sqlstate === '' && is_string($code) && preg_match('/^[0-9A-Z]{5}$/D', $code)) $sqlstate = $code;
    if (preg_match('/^SQLSTATE\[([0-9A-Z]{5})\](?:[^\[]{0,40}\[(\d+)\]|: [^:]{0,60}: (\d+))?/', $e->getMessage(), $m)) {
        if ($sqlstate === '') $sqlstate = $m[1];
        if ($driver === null && (($m[2] ?? '') !== '' || ($m[3] ?? '') !== '')) $driver = (int)(($m[2] ?? '') !== '' ? $m[2] : $m[3]);
    }
    if ($driver === null && is_int($code) && $code > 0) $driver = $code;
    return [$sqlstate, $driver];
}

/**
 * Whether the error is the database connection itself going away: SQLSTATE
 * class 08, or MySQL's "can't connect" (2002, 2003) and "gone away" (2006,
 * 2013). Asked of the whole chain, because a wrapper can carry one inside.
 */
function error_connection_lost(Throwable $e): bool {
    for ($x = $e; $x !== null; $x = $x->getPrevious()) {
        if (!$x instanceof PDOException) continue;
        [$sqlstate, $driver] = pdo_error_codes($x);
        if (str_starts_with($sqlstate, '08') || in_array($driver, [2002, 2003, 2006, 2013], true)) return true;
    }
    return false;
}

/** A file as the portal names it: relative to its own folder, or only its name. */
function error_path(string $file): string {
    // ROOT is spelled with a "/.." in it, and a thrown error's file never is.
    static $root;
    $root ??= rtrim((string)(realpath(ROOT) ?: ROOT), '/') . '/';
    if ($file === '') return '';
    return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
}

/** Text with the portal's own folder taken off every path in it, resolved or as ROOT spells it. */
function error_without_folder(string $text): string {
    foreach (array_unique([rtrim((string)(realpath(ROOT) ?: ROOT), '/') . '/', rtrim((string)ROOT, '/') . '/', (string)ROOT]) as $folder)
        $text = str_replace($folder, '', $text);
    return $text;
}

/**
 * An error message as it may be kept and shown to somebody outside the club.
 *
 * Email addresses, numbers of six digits or more and runs of twenty or more
 * letters and digits - a token, a hash, an IBAN - become …, and so does any of
 * the portal's own secrets that turns up word for word, and the portal's own
 * folder is taken off every path. Cut to 300 characters. A name in a message
 * is not caught, which is why support_text() only passes on the messages PHP
 * writes itself (support_text_message()).
 */
function error_message_scrub(string $message): string {
    $message = error_without_folder(mb_scrub($message, 'UTF-8'));
    foreach (error_secrets() as $secret) $message = str_replace($secret, '…', $message);
    $message = (string)preg_replace(['/[^\s@<>()\[\]{}"\'`,;:]+@[^\s@<>()\[\]{}"\'`,;:]+/u', '/[\p{L}\p{N}]{20,}/u', '/\d{6,}/u'],
                                    '…', $message);
    return mb_substr($message, 0, 300);
}

/**
 * The secrets a message could quote back: the database password, the app key
 * and the SMTP password. The last is read only over a connection that is
 * already open, never by opening one. Anything shorter than four characters is
 * left alone, because replacing every "a" would ruin the message and hide
 * nothing.
 */
function error_secrets(): array {
    $secrets = [(string)(config('db')['password'] ?? ''), (string)config('app_key')];
    if (db_connected()) {
        try { $secrets[] = smtp_password((array)setting('smtp', [])); } catch (Throwable) {}
    }
    return array_values(array_filter($secrets, fn(string $s) => mb_strlen($s) >= 4));
}

/**
 * Write one occurrence: count up the entry for the same error, open it again if
 * it was done, or start a new one. Runs inside capture_error()'s transaction.
 *
 * The same error is the same class, SQLSTATE or code, and place. Every write
 * repeats the context it read, so two requests failing at once cost at worst a
 * count, or a second entry - accepted, as the ADR says.
 */
function error_record(array $facts, string $where): void {
    $found = one('SELECT id, state, context_json FROM feedback WHERE account_id IS NULL AND context_json LIKE ? ORDER BY id LIMIT 1',
                 [ERROR_CONTEXT_PREFIX . $facts['fingerprint'] . '"%']);
    $page = $where === 'background' ? '' : mb_substr(current_page(), 0, 60);
    $short = substr((string)strrchr('\\' . $facts['class'], '\\'), 1);
    $message = $where === 'background'
        ? t('Fehler bei der Arbeit im Hintergrund: ', 'Error in the background work: ') . $short
        : t('Fehler auf Seite ', 'Error on page ') . $page . ': ' . $short;
    $previous = $found ? json_decode((string)$found['context_json'], true) : null;
    $context = error_context($facts, $where, $page,
        (int)(is_array($previous) ? ($previous['count'] ?? 0) : 0) + 1,
        is_array($previous) && is_string($previous['first_seen'] ?? null) ? $previous['first_seen'] : now());
    $title = null;
    if ($found) {
        // Done, and back: that is news. Otherwise a count going up is not.
        $reopened = $found['state'] === 'done';
        $written = run('UPDATE feedback SET state=?, page=?, message=?, context_json=? WHERE id=? AND context_json=?',
            [$reopened ? 'new' : $found['state'], $page, $message, error_context_json($context), $found['id'], $found['context_json']])->rowCount();
        if ($reopened && $written) $title = t('Ein Fehler ist wieder aufgetreten', 'An error has happened again');
    } else {
        $open = (int)scalar("SELECT COUNT(*) FROM feedback WHERE account_id IS NULL AND state<>'done' AND context_json LIKE ?",
                            [ERROR_CONTEXT_PREFIX . '%']);
        if ($open >= ERROR_OPEN_MAX) { error_log('CRM: ' . ERROR_OPEN_MAX . ' errors are already waiting; this one is only in the log.'); return; }
        run("INSERT INTO feedback (account_id,page,message,context_json,screenshot_name,state,created_at) VALUES (NULL,?,?,?,'','new',?)",
            [$page, $message, error_context_json($context), now()]);
        $title = t('Ein Fehler ist aufgetreten', 'An error has happened');
    }
    if ($title !== null) notify_admins('problem', $title, $message, 'settings', ['tab' => 'feedback']);
}

/**
 * One occurrence as it is stored. The order of the first two keys is what makes
 * an automatic entry recognisable, so they are always written first.
 *
 * The request and the trail are this occurrence's; an earlier one's are
 * replaced, and with them any done_at or dropped values, because the steps
 * written now carry values again. A background error belongs to no request,
 * so it has neither. Never the IP address.
 */
function error_context(array $facts, string $where, string $page, int $count, string $firstSeen): array {
    $account = $where === 'background' ? null : current_user();
    return [
        'kind' => 'error', 'fingerprint' => $facts['fingerprint'],
        'count' => $count, 'first_seen' => $firstSeen, 'last_seen' => now(), 'where' => $where,
        'class' => $facts['class'], 'sqlstate' => $facts['sqlstate'], 'code' => $facts['code'],
        'message' => $facts['message'], 'file' => $facts['file'], 'line' => $facts['line'],
        'frames' => $facts['frames'], 'previous' => $facts['previous'],
        'page' => $page,
        'method' => $where === 'background' ? '' : report_text((string)($_SERVER['REQUEST_METHOD'] ?? ''), 10),
        'url' => $where === 'background' ? '' : report_url(),
        'role' => $account['role'] ?? null, 'account' => isset($account['id']) ? (int)$account['id'] : null,
        'steps' => $where === 'background' ? [] : recent_steps(),
        'user_agent' => $where === 'background' ? '' : report_text((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 400),
        'version' => app_version(), 'php' => PHP_VERSION,
    ];
}

/**
 * An automatic entry's context as it is stored, at most ERROR_CONTEXT_MAX bytes:
 * what was typed goes first, then the steps, then the frames. The start of it
 * never changes.
 */
function error_context_json(array $context): string {
    $json = feedback_context_json($context);
    if (strlen($json) <= ERROR_CONTEXT_MAX) return $json;
    $context = feedback_forget_typed_values($context);
    unset($context['values_dropped_at']);
    $context['trimmed'] = 'values';
    if (strlen($json = feedback_context_json($context)) <= ERROR_CONTEXT_MAX) return $json;
    $context['steps'] = []; $context['trimmed'] = 'steps';
    if (strlen($json = feedback_context_json($context)) <= ERROR_CONTEXT_MAX) return $json;
    $context['frames'] = []; $context['user_agent'] = ''; $context['trimmed'] = 'frames';
    return feedback_context_json($context);
}

/**
 * The stored context of a Rückmeldungen row if the portal wrote it itself, or
 * null for a report a person sent. The one test for "automatic": no account,
 * and a context that begins the way only error_context() begins one.
 */
function automatic_error_context(array $row): ?array {
    if (($row['account_id'] ?? null) !== null) return null;
    $raw = (string)($row['context_json'] ?? '');
    if (!str_starts_with($raw, ERROR_CONTEXT_PREFIX)) return null;
    $context = json_decode($raw, true);
    return is_array($context) ? $context : null;
}

/**
 * Delete automatic entries nothing has repeated for ERROR_KEEP_DAYS, whatever
 * their state. A done one may go sooner, by the rule every report has; this is
 * the one for errors nobody looked at. A person's report is never touched here.
 * Each delete repeats the context it read, so one that counted up meanwhile stays.
 */
function prune_quiet_errors(): int {
    $cutoff = gmdate('Y-m-d H:i:s', time() - ERROR_KEEP_DAYS * 86400);
    return transactional(function () use ($cutoff): int {
        $removed = 0;
        foreach (rows('SELECT id, account_id, context_json FROM feedback WHERE account_id IS NULL AND context_json LIKE ?',
                      [ERROR_CONTEXT_PREFIX . '%']) as $row) {
            $lastSeen = (string)(automatic_error_context($row)['last_seen'] ?? '');
            if ($lastSeen !== '' && $lastSeen < $cutoff)
                $removed += run('DELETE FROM feedback WHERE id=? AND account_id IS NULL AND context_json=?',
                                [$row['id'], $row['context_json']])->rowCount();
        }
        return $removed;
    });
}

/**
 * The text an administrator copies for whoever helps her, outside the club.
 *
 * What the error was and where, and the way there as methods, actions and the
 * names of the fields - never what was typed into them, never a name, an email
 * address or an IP address. The message only when PHP wrote it itself
 * (support_text_message()); any other can quote what somebody typed. In the
 * address, every value except page, id, tab and what is …, because a search
 * term can be a child's name. The page inside the portal may go on showing
 * more; this text is leaving it.
 *
 * '' for a report a person wrote: that one is in their own words already.
 */
function support_text(array $entry): string {
    $c = automatic_error_context($entry);
    if ($c === null) return '';
    $scalar = static fn(mixed $v): string => is_scalar($v) ? (string)$v : '';
    $lines = [
        t('Automatisch erfasster Fehler im Badminton-Portal', 'Error recorded automatically by the badminton portal'),
        t('Version: ', 'Version: ') . $scalar($c['version'] ?? '') . ' · PHP ' . $scalar($c['php'] ?? ''),
        t('Zuerst: ', 'First: ') . fmt_datetime($scalar($c['first_seen'] ?? '')) . ' · '
            . t('zuletzt: ', 'last: ') . fmt_datetime($scalar($c['last_seen'] ?? '')) . ' · '
            . t('Anzahl: ', 'count: ') . (int)($c['count'] ?? 0),
        t('Fehler: ', 'Error: ') . $scalar($c['class'] ?? '')
            . ($scalar($c['sqlstate'] ?? '') !== '' ? ' SQLSTATE ' . $scalar($c['sqlstate']) : '')
            . ($scalar($c['code'] ?? '') !== '' && $scalar($c['code']) !== '0' ? ' ' . t('Code ', 'code ') . $scalar($c['code']) : ''),
    ];
    if (support_text_message($c)) $lines[] = t('Meldung: ', 'Message: ') . error_message_scrub($scalar($c['message']));
    $lines[] = t('Ort: ', 'Where: ') . $scalar($c['file'] ?? '') . ':' . (int)($c['line'] ?? 0)
        . ' (' . ($scalar($c['where'] ?? '') === 'background' ? t('Hintergrund', 'background') : t('Seitenaufruf', 'request')) . ')';
    foreach (array_values(array_filter((array)($c['frames'] ?? []), 'is_array')) as $i => $f)
        $lines[] = '  #' . $i . ' ' . $scalar($f['file'] ?? '') . ':' . (int)($f['line'] ?? 0) . ' '
            . $scalar($f['class'] ?? '') . $scalar($f['type'] ?? '') . $scalar($f['function'] ?? '') . '()';
    if ($scalar($c['url'] ?? '') !== '')
        $lines[] = t('Zuletzt aufgerufen: ', 'Last request: ') . $scalar($c['method'] ?? '') . ' ' . support_url($scalar($c['url']));
    $steps = array_values(array_filter((array)($c['steps'] ?? []), 'is_array'));
    if ($steps) {
        $lines[] = t('Schritte davor:', 'Steps before it:');
        foreach ($steps as $step) {
            if (($step['method'] ?? '') === 'POST') {
                $names = array_keys(array_diff_key(is_array($step['fields'] ?? null) ? $step['fields'] : [], ['…' => 1]));
                $files = array_keys(is_array($step['files'] ?? null) ? $step['files'] : []);
                $lines[] = '  POST ?action=' . $scalar($step['action'] ?? '')
                    . ($names ? '  ' . t('Felder: ', 'fields: ') . implode(', ', array_map('strval', $names)) : '')
                    . ($files ? '  ' . t('Dateien: ', 'files: ') . implode(', ', array_map('strval', $files)) : '');
            } else {
                $lines[] = '  GET ' . support_url($scalar($step['url'] ?? ''));
            }
        }
    }
    if ($scalar($c['user_agent'] ?? '') !== '') $lines[] = t('Gerät: ', 'Device: ') . $scalar($c['user_agent']);
    return implode("\n", $lines);
}

/**
 * Whether the stored message may leave the club: only for the errors whose
 * wording PHP writes itself, from the code rather than from anybody's input -
 * a wrong type, a wrong number of arguments, a plain Error, a fatal error the
 * handler turned into an ErrorException. Any other class can carry what an
 * application or library put into it, and a scrub does not catch a name.
 */
function support_text_message(array $context): bool {
    return in_array($context['class'] ?? '', ['TypeError', 'ArgumentCountError', 'Error', 'ErrorException'], true)
        && is_scalar($context['message'] ?? null) && (string)$context['message'] !== '';
}

/** An address with every query value but page, id, tab and what replaced by …. */
function support_url(string $url): string {
    [$path, $query] = array_pad(explode('?', $url, 2), 2, '');
    parse_str($query, $values);
    $kept = [];
    foreach ($values as $key => $value)
        $kept[] = rawurlencode((string)$key) . '=' . (in_array($key, ['page', 'id', 'tab', 'what'], true) && is_scalar($value)
            ? rawurlencode((string)$value) : '…');
    return $path . ($kept ? '?' . implode('&', $kept) : '');
}
