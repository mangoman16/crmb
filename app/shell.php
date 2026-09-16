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
        'teal'   => ['#077e76', t('Türkis',  'Teal')],
        'blue'   => ['#1f5fa9', t('Blau',    'Blue')],
        'violet' => ['#6c4bb6', t('Violett', 'Violet')],
        'pink'   => ['#b03a72', t('Pink',    'Pink')],
        'red'    => ['#b5432f', t('Rot',     'Red')],
        'orange' => ['#9a5a12', t('Orange',  'Orange')],
        'green'  => ['#3a7a2e', t('Grün',    'Green')],
        'slate'  => ['#41566b', t('Grau',    'Slate')],
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

/**
 * Everything worth knowing about the request that was being made.
 *
 * Collected by the server rather than asked of the person: nobody reporting a
 * problem on a phone is going to find their browser version, and the answer
 * "what were you doing?" is already in the page they were on.
 */
function feedback_context(string $page): array {
    return [
        'page'        => $page,
        'query'       => array_map(fn($v) => is_scalar($v) ? (string)$v : '', array_slice($_GET, 0, 12)),
        'user_agent'  => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
        'ip'          => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'referer'     => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 400),
        'language'    => mb_substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 120),
        'locale'      => locale(),
        'version'     => app_version(),
        'php'         => PHP_VERSION,
        'at'          => now(),
    ];
}

/** Feedback waiting to be looked at. */
function open_feedback(int $limit = 50): array {
    return rows('SELECT f.*, a.name AS account_name, a.email FROM feedback f LEFT JOIN accounts a ON a.id=f.account_id'
        .' ORDER BY f.state<>\'new\', f.id DESC LIMIT ' . max(1, min(200, $limit)));
}

function unread_feedback(): int { return (int)scalar("SELECT COUNT(*) FROM feedback WHERE state='new'"); }
