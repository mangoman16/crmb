<?php
declare(strict_types=1);

/*
 * Presence: the coloured dot on an avatar, the status a person chooses for
 * themselves, and when each account was online over the last thirty days.
 *
 * The reasoning, and who sees what, is ADR 0015. The short version:
 *
 *   - everybody signed in sees everybody's dot, the way a messenger shows who
 *     is here (ADR 0022); only staff see when somebody was last here and their
 *     history (presence_details_visible_to()), and only staff have a status to
 *     choose - a status stored on a family's row is read as 'auto'. A family's
 *     time online is recorded, always as visible, because staff see it;
 *   - "Als offline anzeigen" means trainers see the person as offline and their
 *     last-online time frozen at the moment they hid, while administrators still
 *     see the true time and the hidden periods, marked as such;
 *   - every visit is written on the counter connection, so an action that rolls
 *     back cannot take the fact that somebody was here with it. Only the nightly
 *     prune deletes on the main one.
 *
 * Views never work a state out themselves: they ask presence_state(),
 * presence_last_seen_for() and presence_history(), and each of those applies
 * presence_visible_to(), presence_details_visible_to() or its own narrower
 * rule before it answers.
 */

/** The statuses a person can choose, in the order the menu offers them. */
function presence_choices(): array {
    return [
        'auto'   => t('Automatisch', 'Automatic'),
        'away'   => t('Abwesend', 'Away'),
        'hidden' => t('Als offline anzeigen', 'Appear offline'),
    ];
}

/**
 * The status an account has chosen.
 *
 * Only staff have one. A family's row is 'auto' whatever the column holds - a
 * value left from before the owner decided, or put there by hand - so no
 * family can vanish from a trainer's view or have its visits recorded as hidden.
 * A row without its role is not taken for staff, as in
 * may_see_account_picture().
 *
 * Anything that is not one of the three choices - a row from before migration
 * 020, a value typed into the database by hand - is read as 'auto', which shows
 * exactly what the portal showed before there was a choice. Never a fourth state.
 */
function presence_choice(array $account): string {
    if (!isset($account['role']) || !is_staff($account)) return 'auto';
    $choice = (string)($account['presence'] ?? 'auto');
    return array_key_exists($choice, presence_choices()) ? $choice : 'auto';
}

/**
 * How long after the last activity each colour lasts, in seconds.
 *
 * Each is measured from the last activity, not from the end of the band
 * before it. presence_state() tests them in order, so an administrator who
 * sets „blau“ shorter than „grün“ gets no blue at all - never a dot that turns
 * blue and then green again as time passes - and nothing here has to reorder
 * them.
 *
 * @return array{online:int, recent:int, away:int}
 */
function presence_bands(): array {
    return ['online' => max(1, (int)setting('online_window_minutes')) * 60,
            'recent' => (int)setting('presence_recent_minutes') * 60,
            'away'   => (int)setting('presence_away_hours') * 3600];
}

/**
 * How many days of online periods are kept and shown.
 *
 * Clamped here as well as on the settings form, because a longer horizon would
 * be a new decision about families' data rather than a setting (ADR 0015): a
 * value written into the settings table by hand still cannot keep more.
 */
function presence_history_days(): int {
    return max(1, min(PRESENCE_HISTORY_MAX_DAYS, (int)setting('presence_history_days')));
}

/**
 * Note that somebody is in the portal, at most once a minute per session.
 *
 * The person recorded is the one really signed in. While staff look through a
 * family's eyes the session belongs to the family, and recording the family
 * would show a child as online at 23:00 because a trainer was checking what the
 * child sees. If the impersonator's account has been deleted mid-session,
 * nothing is recorded at all.
 *
 * One row per stretch of time online, not one per page view: the newest period
 * with the same hidden flag is extended while it is still within the online
 * window plus a minute (the throttle's own gap), and otherwise a new one starts.
 * Two devices touching in the same instant can both insert; two overlapping rows
 * are harmless, and a lock to prevent them would cost every page view.
 */
function presence_touch(array $user): void {
    if (time() - (int)($_SESSION['seen_written'] ?? 0) < 60) return;
    $_SESSION['seen_written'] = time();
    $who = $user;
    if (!empty($_SESSION['impersonator_id'])) {
        // Somebody is looking through $user's eyes. If their own account has
        // gone since they started, nobody real is here to record - and
        // recording $user instead is exactly the fault this function exists to
        // prevent. Ending the impersonation is shell.php's business, not this.
        $who = impersonator();
        if ($who === null) return;
    }
    $now = now();
    $hidden = presence_choice($who) === 'hidden' ? 1 : 0;
    $stillOpen = gmdate('Y-m-d H:i:s', time() - presence_bands()['online'] - 60);
    // The counter connection, for all three: an action that rolls back must not
    // also undo the fact that the person was here.
    run_counter('UPDATE accounts SET last_seen_at=? WHERE id=?', [$now, (int)$who['id']]);
    $open = run_counter('SELECT id FROM online_periods WHERE account_id=? AND hidden=? AND last_seen_at>=? ORDER BY last_seen_at DESC LIMIT 1',
        [(int)$who['id'], $hidden, $stillOpen])->fetchColumn();
    if ($open !== false) run_counter('UPDATE online_periods SET last_seen_at=? WHERE id=?', [$now, (int)$open]);
    else run_counter('INSERT INTO online_periods (account_id,started_at,last_seen_at,hidden) VALUES (?,?,?,?)',
        [(int)$who['id'], $now, $now, $hidden]);
}

/**
 * The dot as others see it: 'online', 'recent', 'away' or 'offline'.
 *
 * Worked out from the time since the last activity, then the chosen status is
 * applied: 'hidden' is always offline, and 'away' turns online and recently
 * online into away - someone who has been gone for two days is offline, whatever
 * they said when they left. Who may be shown this at all is
 * presence_visible_to()'s question, not this function's.
 */
function presence_state(array $subject, ?int $now = null): string {
    $choice = presence_choice($subject);
    if ($choice === 'hidden') return 'offline';
    $seen = presence_seconds_since($subject['last_seen_at'] ?? null, $now ?? time());
    if ($seen === null) return 'offline';
    $bands = presence_bands();
    $state = match (true) {
        $seen < $bands['online'] => 'online',
        $seen < $bands['recent'] => 'recent',
        $seen < $bands['away']   => 'away',
        default                  => 'offline',
    };
    return $choice === 'away' && in_array($state, ['online', 'recent'], true) ? 'away' : $state;
}

/** Seconds from a stored UTC timestamp to $now, or null for no timestamp. */
function presence_seconds_since(?string $utc, int $now): ?int {
    if ($utc === null || $utc === '') return null;
    $at = strtotime($utc . ' UTC');
    return $at === false ? null : $now - $at;
}

/** What a state is called, for the dot's hidden label and the line beside it. */
function presence_state_label(string $state): string {
    return match ($state) {
        'online' => t('Online', 'Online'),
        'recent' => t('Vor Kurzem online', 'Recently online'),
        'away'   => t('Abwesend', 'Away'),
        default  => t('Offline', 'Offline'),
    };
}

/**
 * Whether $viewer sees $subject's dot: everybody signed in, everybody's, their
 * own included (ADR 0022). When somebody was last here, and their history, are
 * presence_details_visible_to()'s question.
 */
function presence_visible_to(array $viewer, array $subject): bool {
    return isset($viewer['id']);
}

/**
 * Whether $viewer is told when people were last online, and over which days:
 * staff only (ADR 0015). A child sees who is here now, never when somebody else
 * was.
 */
function presence_details_visible_to(array $viewer): bool {
    return is_staff($viewer);
}

/**
 * When $subject was last in the portal, as $viewer may know it (UTC), or null.
 *
 * - Administrators, and the person themselves, get the true time.
 * - A trainer gets the same, unless the subject has chosen „Als offline
 *   anzeigen“: then the time stops where the subject hid, which is the newest
 *   period recorded without the hidden flag inside the history window - or
 *   null once that is older than the window.
 * - Anybody presence_details_visible_to() refuses gets null.
 *
 * One query only in the trainer-and-hidden case, which on a list is the rare row.
 */
function presence_last_seen_for(array $viewer, array $subject): ?string {
    if (!presence_details_visible_to($viewer)) return null;
    $trueTime = ($subject['last_seen_at'] ?? '') !== '' ? (string)$subject['last_seen_at'] : null;
    if (is_admin($viewer) || (int)$viewer['id'] === (int)($subject['id'] ?? 0)) return $trueTime;
    if (presence_choice($subject) !== 'hidden') return $trueTime;
    // Bounded by the window as well as by the nightly prune: a period the prune
    // has not reached yet - it did not run, or it failed - is still older than
    // anything a trainer is meant to be told about.
    $visible = scalar('SELECT MAX(last_seen_at) FROM online_periods WHERE account_id=? AND hidden=0 AND last_seen_at>=?',
        [(int)$subject['id'], presence_window_start()]);
    return $visible ? (string)$visible : null;
}

/**
 * The periods online within the history window, for a whole list of accounts.
 *
 * One query however long the list, so a page of fifty accounts is not fifty
 * round trips. Refuses anybody but staff. A trainer sees only the periods
 * recorded without the hidden flag, except their own; an administrator sees
 * every period, with 'hidden' marking the ones a trainer does not see.
 *
 * @param int[] $accountIds
 * @return array<int, list<array{started_at:string, last_seen_at:string, hidden:bool}>>
 *         Every id asked about is a key, with its periods newest first; an
 *         account with none has an empty list.
 */
function presence_history(array $viewer, array $accountIds, ?int $now = null): array {
    if (!presence_details_visible_to($viewer)) throw new UserError(t('Kein Zugriff.', 'Access denied.'));
    $ids = array_values(array_unique(array_map('intval', $accountIds)));
    $out = array_fill_keys($ids, []);
    if (!$ids) return $out;
    $since = presence_window_start($now);
    $sql = 'SELECT account_id,started_at,last_seen_at,hidden FROM online_periods WHERE account_id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') AND last_seen_at>=?';
    $params = [...$ids, $since];
    if (!is_admin($viewer)) { $sql .= ' AND (hidden=0 OR account_id=?)'; $params[] = (int)$viewer['id']; }
    foreach (rows($sql . ' ORDER BY last_seen_at DESC, id DESC', $params) as $row)
        $out[(int)$row['account_id']][] = ['started_at' => (string)$row['started_at'],
            'last_seen_at' => (string)$row['last_seen_at'], 'hidden' => (bool)(int)$row['hidden']];
    return $out;
}

/** The oldest moment the history window reaches back to, in UTC. */
function presence_window_start(?int $now = null): string {
    return gmdate('Y-m-d H:i:s', ($now ?? time()) - presence_history_days() * 86400);
}

/**
 * One account's periods laid out as calendar days, for the day strip and the list.
 *
 * Exactly presence_history_days() days, oldest first, ending today in the
 * portal's own time zone. A period belongs to the local day it started on, so a
 * session from 23:40 to 00:20 is listed once, under the evening it began.
 *
 * 'state' is 'on' when the day has a period a trainer would see, 'hidden' when
 * all of that day's periods were hidden ones (only an administrator's history
 * contains those), and 'none' otherwise. 'periods' holds that day's periods
 * oldest first, each with its local start and end as 'H:i' ready to print.
 *
 * @param list<array{started_at:string, last_seen_at:string, hidden:bool}> $periods
 *        one account's entry from presence_history()
 * @return list<array{date:string, state:string, periods:list<array{from:string, to:string, hidden:bool}>}>
 */
function presence_days(array $periods, ?int $now = null): array {
    $zone = new DateTimeZone(date_default_timezone_get());
    $today = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($zone)->setTime(0, 0);
    $days = [];
    for ($i = presence_history_days() - 1; $i >= 0; $i--)
        $days[$today->modify('-' . $i . ' days')->format('Y-m-d')] = ['state' => 'none', 'periods' => []];
    foreach (array_reverse($periods) as $period) {
        $from = local_time($period['started_at']); $to = local_time($period['last_seen_at']);
        if (!$from || !$to || !isset($days[$from->format('Y-m-d')])) continue;
        $key = $from->format('Y-m-d');
        $days[$key]['periods'][] = ['from' => $from->format('H:i'), 'to' => $to->format('H:i'), 'hidden' => $period['hidden']];
        if (!$period['hidden']) $days[$key]['state'] = 'on';
        elseif ($days[$key]['state'] === 'none') $days[$key]['state'] = 'hidden';
    }
    $out = [];
    foreach ($days as $date => $day) $out[] = ['date' => $date] + $day;
    return $out;
}

/**
 * When the portal started recording periods online (UTC), if that is inside the
 * history window; otherwise null.
 *
 * For the first month after the update the history is shorter than it claims to
 * be, and „In den letzten 30 Tagen nicht online“ would be untrue about somebody
 * who simply had not been recorded yet. Once the whole window is covered there
 * is nothing to explain, so null tells the page to say nothing.
 */
function presence_recorded_since(?int $now = null): ?string {
    $appliedAt = (string)(scalar('SELECT applied_at FROM schema_migrations WHERE version=?', ['020_when_somebody_was_online.sql']) ?: '');
    return $appliedAt !== '' && $appliedAt > presence_window_start($now) ? $appliedAt : null;
}

/**
 * Forget the periods that ended more than $days days ago. Returns how many.
 *
 * Called by the nightly prune_expired(). The horizon is clamped to
 * PRESENCE_HISTORY_MAX_DAYS here too, so no caller can keep presence data longer than the privacy notice
 * says.
 */
function presence_prune(int $days): int {
    $days = max(1, min(PRESENCE_HISTORY_MAX_DAYS, $days));
    return run('DELETE FROM online_periods WHERE last_seen_at<?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
}

/**
 * The emoji anybody may show beside their name (ADR 0022), key => [emoji, what
 * it is called].
 *
 * A fixed list rather than whatever is typed, so nothing anybody writes ends up
 * beside their name. Each is one code point from Unicode 9 or older, so every
 * phone in a family draws it in colour. Left out on purpose: hearts (romantic
 * between an adult and a child), the thumbs-up (rude in some places), slang and
 * anything a family might read as a statement.
 */
function status_emojis(): array {
    return [
        'badminton' => ['🏸', t('Badminton', 'Badminton')],
        'strong'    => ['💪', t('Stark', 'Strong')],
        'trophy'    => ['🏆', t('Pokal', 'Trophy')],
        'star'      => ['⭐', t('Stern', 'Star')],
        'happy'     => ['😀', t('Fröhlich', 'Happy')],
        'cool'      => ['😎', t('Cool', 'Cool')],
        'thinking'  => ['🤔', t('Nachdenklich', 'Thinking')],
        'sleepy'    => ['😴', t('Müde', 'Sleepy')],
        'party'     => ['🎉', t('Feiern', 'Party')],
        'lucky'     => ['🍀', t('Glück', 'Lucky')],
        'rocket'    => ['🚀', t('Rakete', 'Rocket')],
        'books'     => ['📚', t('Lernen', 'Studying')],
        'cat'       => ['🐱', t('Katze', 'Cat')],
        'dog'       => ['🐶', t('Hund', 'Dog')],
        'fox'       => ['🦊', t('Fuchs', 'Fox')],
        'unicorn'   => ['🦄', t('Einhorn', 'Unicorn')],
    ];
}

/** The emoji $account shows, as [emoji, name], or null - for none, and for any stored key not on the list. */
function status_emoji(array $account): ?array {
    return status_emojis()[(string)($account['status_emoji'] ?? '')] ?? null;
}
