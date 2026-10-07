<?php
/**
 * Presence: the dot, the status a person chooses, and when somebody was online.
 *
 * ADR 0015 decides who sees what, and the owner decided on top of it that a
 * family sees no presence at all, not even its own. What is checked here is
 * that each rule holds where it is applied - in app/presence.php, once - so a
 * view that asks cannot be handed more than its viewer may know.
 */

$admin   = make_account(['role' => 'admin',   'name' => 'Admin']);
$trainer = make_account(['role' => 'trainer', 'name' => 'Trainerin']);
$family  = make_account(['role' => 'student', 'name' => 'Familie']);
$other   = make_account(['role' => 'student', 'name' => 'Andere Familie']);
$second  = make_account(['role' => 'trainer', 'name' => 'Zweite Trainerin']);
$account = fn(int $id): array => one('SELECT * FROM accounts WHERE id=?', [$id]);
$ago     = fn(int $seconds): string => gmdate('Y-m-d H:i:s', time() - $seconds);
$periods = fn(int $id): array => rows('SELECT * FROM online_periods WHERE account_id=? ORDER BY id', [$id]);
$period  = fn(int $id, int $startedAgo, int $seenAgo, int $hidden = 0): int => fixture('online_periods',
    ['account_id' => $id, 'started_at' => $ago($startedAgo), 'last_seen_at' => $ago($seenAgo), 'hidden' => $hidden]);

case_('A stored status is one of three, and anything else reads as automatic');
is_same(['auto', 'away', 'hidden'], array_keys(presence_choices()), 'the three choices, in the menu\'s order');
is_same('Als offline anzeigen', presence_choices()['hidden'], 'named in her words');
is_same('auto', presence_choice(['role' => 'trainer', 'presence' => 'invisible']), 'an unknown value is automatic, never a fourth state');
is_same('auto', presence_choice(['role' => 'trainer']), 'and so is a row from before the column existed');
is_same('hidden', presence_choice(['role' => 'trainer', 'presence' => 'hidden']), 'a real choice is kept');
is_same('auto', presence_choice(['role' => 'student', 'presence' => 'hidden']), 'a family has no status, whatever is stored');
is_same('auto', presence_choice(['presence' => 'hidden']), 'and a row without its role is not taken for staff');

case_('The dot follows the time since the last activity');
$now = time();
$at = fn(int $seconds): array => ['role' => 'trainer', 'presence' => 'auto', 'last_seen_at' => gmdate('Y-m-d H:i:s', $now - $seconds)];
is_same('online',  presence_state($at(60), $now), 'a minute ago is online');
is_same('recent',  presence_state($at(300), $now), 'exactly the online window is already recently online');
is_same('recent',  presence_state($at(59 * 60), $now), 'within the hour is recently online');
is_same('away',    presence_state($at(2 * 3600), $now), 'two hours ago is away');
is_same('offline', presence_state($at(25 * 3600), $now), 'over a day ago is offline');
is_same('offline', presence_state(['role' => 'trainer', 'presence' => 'auto', 'last_seen_at' => null], $now), 'never seen is offline');
is_same('Vor Kurzem online', presence_state_label('recent'), 'each state has a name');
is_same('Offline', presence_state_label('something else'), 'and an unknown one reads as offline');

case_('A chosen status changes what the dot says');
$chosen = fn(string $choice, int $seconds): string => presence_state(['presence' => $choice] + $at($seconds), $now);
is_same('away',    $chosen('away', 60), 'away turns online into away');
is_same('away',    $chosen('away', 30 * 60), 'and recently online into away');
is_same('offline', $chosen('away', 26 * 3600), 'but somebody gone for a day is offline whatever they chose');
is_same('offline', $chosen('hidden', 0), 'appear offline is offline while active');

case_('A colour set shorter than the one before it is skipped, never shown out of order');
set_setting('online_window_minutes', 30);
set_setting('presence_recent_minutes', 5);
is_same('online', presence_state($at(20 * 60), $now), 'a shorter blue does not cut green short');
is_same('away', presence_state($at(40 * 60), $now), 'and there is simply no blue band');
set_setting('online_window_minutes', 5);
set_setting('presence_recent_minutes', 180);
set_setting('presence_away_hours', 1);
is_same('recent', presence_state($at(90 * 60), $now), 'a shorter yellow does not cut blue short');
is_same('offline', presence_state($at(4 * 3600), $now), 'and after blue it is grey');
set_setting('presence_recent_minutes', 60);
set_setting('presence_away_hours', 24);

case_('Everybody sees the dots; only staff are told when somebody was here');
/* ADR 0022: the chat shows who is here, like any messenger; when somebody was
   last online, and their history, stay with staff (ADR 0015). */
$A = $account($admin); $T = $account($trainer); $F = $account($family); $O = $account($other);
ok(presence_visible_to($A, $F) && presence_visible_to($A, $T), 'an administrator sees everybody’s dot');
ok(presence_visible_to($T, $F) && presence_visible_to($T, $A) && presence_visible_to($T, $T), 'a trainer too, her own included');
ok(presence_visible_to($F, $O) && presence_visible_to($F, $T) && presence_visible_to($F, $F), 'and so does a family: another family, the trainer, itself');
ok(!presence_visible_to([], $F), 'nobody signed in sees none');
ok(presence_details_visible_to($A) && presence_details_visible_to($T), 'staff are told when somebody was here');
ok(!presence_details_visible_to($F), 'a family is not');

case_('Last online: a hidden account is frozen for trainers and true for administrators');
$S = $account($second);
run('UPDATE accounts SET last_seen_at=? WHERE id=?', [$ago(120), $trainer]);
$period($trainer, 3 * 3600, 2 * 3600);         // seen while visible, until two hours ago
$period($trainer, 600, 120, 1);                 // then hid
$T = $account($trainer);
is_same($T['last_seen_at'], presence_last_seen_for($S, $T), 'while not hidden another trainer sees the true time');
run("UPDATE accounts SET presence='hidden' WHERE id=?", [$trainer]);
$T = $account($trainer);
is_same($ago(2 * 3600), presence_last_seen_for($S, $T), 'hidden, another trainer sees when she hid');
is_same($T['last_seen_at'], presence_last_seen_for($A, $T), 'an administrator still sees the true time');
is_same($T['last_seen_at'], presence_last_seen_for($T, $T), 'and so does she herself');
is_same(null, presence_last_seen_for($F, $T), 'a family is told nothing about her');
is_same(null, presence_last_seen_for($F, $F), 'nor about itself');
run('UPDATE online_periods SET started_at=?, last_seen_at=? WHERE account_id=? AND hidden=0', [$ago(41 * 86400), $ago(40 * 86400), $trainer]);
is_same(null, presence_last_seen_for($S, $T), 'a visible period older than the window is not shown, even before the prune removes it');
run('DELETE FROM online_periods WHERE account_id=? AND hidden=0', [$trainer]);
is_same(null, presence_last_seen_for($S, $T), 'once the visible periods are gone, a trainer sees nothing');
run("UPDATE accounts SET presence='auto' WHERE id=?", [$trainer]);
run('DELETE FROM online_periods');

case_('A member of staff who hid is frozen for trainers too, as the accounts page shows them');
/* The accounts page lists staff, not families, and it asks
   presence_last_seen_for() for every row: a hidden administrator is the case
   that page actually meets. */
run("UPDATE accounts SET presence='hidden', last_seen_at=? WHERE id=?", [$ago(30), $admin]);
$period($admin, 5 * 3600, 4 * 3600);
$period($admin, 1800, 30, 1);
$A = $account($admin);
is_same($ago(4 * 3600), presence_last_seen_for($S, $A), 'a trainer sees the administrator as last here when they hid');
is_same('offline', presence_state($A), 'and grey');
is_same($A['last_seen_at'], presence_last_seen_for($A, $A), 'the administrator sees their own true time');
run("UPDATE accounts SET presence='auto' WHERE id=?", [$admin]);
run('DELETE FROM online_periods');

case_('A family has no status: a stored "hidden" changes nothing a trainer sees or what is recorded');
/* Families cannot choose one (the owner's decision), but a value can still be
   in the column - left from before that decision, or put there by hand. It must
   not let a family disappear from the trainer's view. */
run("UPDATE accounts SET presence='hidden', last_seen_at=? WHERE id=?", [$ago(60), $family]);
$F = $account($family);
is_same('online', presence_state($F), 'the trainer sees the family online from its activity');
is_same($F['last_seen_at'], presence_last_seen_for($T, $F), 'and the true time it was last here');
sign_in_as($family);
$_SESSION['seen_written'] = 0;
presence_touch($F);
is_same([0], array_map('intval', array_column($periods($family), 'hidden')), 'and its time online is recorded as visible');
run("UPDATE accounts SET presence='auto' WHERE id=?", [$family]);
run('DELETE FROM online_periods');

case_('The history: one query for a list, hidden periods for administrators only');
$period($second, 5 * 86400, 5 * 86400 - 1800);
$period($second, 2 * 86400, 2 * 86400 - 600, 1);
$period($second, 40 * 86400, 40 * 86400 - 600);     // outside the thirty days
$period($other, 3600, 60);
$period($trainer, 7200, 7000, 1);
$A = $account($admin); $T = $account($trainer); $F = $account($family);
$seen = [];
presence_history_days();   // the setting is read once per request, not per list
$queries = query_count(function () use ($T, $second, $other, $admin, &$seen) { $seen = presence_history($T, [$second, $other, $admin]); });
is_same(1, $queries, 'three accounts, one query');
is_same([$second, $other, $admin], array_keys($seen), 'every account asked about is answered');
is_same([], $seen[$admin], 'an account with no periods has an empty list');
is_same(1, count($seen[$second]), 'a trainer sees the visible period and not the hidden one or the old one');
ok($seen[$second][0]['hidden'] === false, 'and it is marked as visible');
is_same(1, count($seen[$other]), 'and a family\'s time online');
$full = presence_history($A, [$second, $other]);
is_same(2, count($full[$second]), 'an administrator sees the hidden period too');
ok($full[$second][0]['hidden'] === true && $full[$second][1]['hidden'] === false, 'newest first, the hidden one marked');
is_same(1, count(presence_history($T, [$trainer])[$trainer]), 'a trainer sees her own hidden periods');
throws(fn() => presence_history($F, [$other]), 'a family asking for another account\'s history is refused', 'Kein Zugriff');
throws(fn() => presence_history($F, [$family]), 'and for its own', 'Kein Zugriff');
set_setting('presence_history_days', 3);
is_same(1, count(presence_history($A, [$second])[$second]), 'a shorter window leaves out what is older than it');
$keep = setting_schema()['presence_history_days'];
throws(fn() => setting_validate('presence_history_days', $keep, '31'), 'the settings form refuses more than thirty days', 'zu groß');
is_same(30, setting_validate('presence_history_days', $keep, '30'), 'and accepts thirty');
set_setting('presence_history_days', 90);
is_same(30, presence_history_days(), 'a longer window than thirty days is never used, whatever is stored');
set_setting('presence_history_days', 30);
run('DELETE FROM online_periods');

case_('The history as days: oldest first, a period under the day it began');
$zone = new DateTimeZone(date_default_timezone_get());
$localToUtc = fn(string $local): string => (new DateTimeImmutable($local, $zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$today = new DateTimeImmutable('today', $zone);
$yesterday = $today->modify('-1 day')->format('Y-m-d');
$history = [
    ['started_at' => $localToUtc($yesterday.' 23:40:00'), 'last_seen_at' => $localToUtc($today->format('Y-m-d').' 00:20:00'), 'hidden' => false],
    ['started_at' => $localToUtc($yesterday.' 18:02:00'), 'last_seen_at' => $localToUtc($yesterday.' 18:40:00'), 'hidden' => false],
    ['started_at' => $localToUtc($today->modify('-3 days')->format('Y-m-d').' 09:00:00'), 'last_seen_at' => $localToUtc($today->modify('-3 days')->format('Y-m-d').' 09:05:00'), 'hidden' => true],
];
$days = presence_days($history);
is_same(30, count($days), 'exactly the window, one entry per day');
is_same($today->format('Y-m-d'), $days[29]['date'], 'ending today');
is_same($today->modify('-29 days')->format('Y-m-d'), $days[0]['date'], 'starting twenty-nine days before');
is_same('on', $days[28]['state'], 'yesterday was online');
is_same([['from' => '18:02', 'to' => '18:40', 'hidden' => false], ['from' => '23:40', 'to' => '00:20', 'hidden' => false]],
    $days[28]['periods'], 'its periods in the order they happened, past midnight listed under the evening it began');
is_same('none', $days[29]['state'], 'so today stays empty');
is_same('hidden', $days[26]['state'], 'a day with only hidden periods says so');

case_('A touch extends the open period, or starts a new one');
sign_in_as($trainer);
$_SESSION['seen_written'] = 0;
presence_touch($account($trainer));
$rows = $periods($trainer);
is_same(1, count($rows), 'the first page view opens a period');
is_same(0, (int)$rows[0]['hidden'], 'an ordinary one');
ok($account($trainer)['last_seen_at'] !== null, 'and the account is seen');
run('UPDATE online_periods SET started_at=?, last_seen_at=? WHERE id=?', [$ago(300), $ago(120), $rows[0]['id']]);
presence_touch($account($trainer));
is_same($ago(120), $periods($trainer)[0]['last_seen_at'], 'within the minute nothing is written at all');
$_SESSION['seen_written'] = 0;
presence_touch($account($trainer));
$rows = $periods($trainer);
is_same(1, count($rows), 'two minutes later the same period is extended');
ok($rows[0]['last_seen_at'] > $ago(30) && $rows[0]['started_at'] === $ago(300), 'to now, keeping when it started');
run('UPDATE online_periods SET started_at=?, last_seen_at=? WHERE id=?', [$ago(1800), $ago(1200), $rows[0]['id']]);
$_SESSION['seen_written'] = 0;
presence_touch($account($trainer));
is_same(2, count($periods($trainer)), 'twenty minutes later a new period starts');
run("UPDATE accounts SET presence='hidden' WHERE id=?", [$trainer]);
$_SESSION['seen_written'] = 0;
presence_touch($account($trainer));
$rows = $periods($trainer);
is_same(3, count($rows), 'choosing to appear offline starts a new period at once');
is_same(1, (int)$rows[2]['hidden'], 'marked as hidden');
run("UPDATE accounts SET presence='auto' WHERE id=?", [$trainer]);

case_('An action that rolls back leaves the period in place');
run('DELETE FROM online_periods');
$_SESSION['seen_written'] = 0;
try {
    transactional(function () use ($account, $family) { presence_touch($account($family)); throw new UserError('abgelehnt'); });
} catch (UserError) {}
is_same(1, count($periods($family)), 'written on the counter connection, so it survived');

case_('While staff look through a family\'s eyes, the trainer is the one online');
run('DELETE FROM online_periods');
run('UPDATE accounts SET last_seen_at=NULL WHERE id IN (?,?)', [$family, $trainer]);
view_as($trainer, $family);
$_SESSION['seen_written'] = 0;
presence_touch($account($family));
is_same(0, count($periods($family)), 'no period for the family');
is_same(null, $account($family)['last_seen_at'], 'and the family is not seen');
is_same(1, count($periods($trainer)), 'the trainer\'s period instead');
ok($account($trainer)['last_seen_at'] !== null, 'and the trainer is seen');
run('DELETE FROM online_periods');
run('UPDATE accounts SET last_seen_at=NULL WHERE id=?', [$family]);
$_SESSION['impersonator_id'] = 999999;          // deleted while they were looking
$_SESSION['seen_written'] = 0;
presence_touch($account($family));
is_same(0, count($periods($family)), 'with the impersonator\'s account gone, the family is still not recorded');
is_same(null, $account($family)['last_seen_at'], 'nor seen');
is_same([], rows('SELECT id FROM online_periods'), 'and nobody else is recorded instead');
unset($_SESSION['impersonator_id']);

case_('The nightly cleanup forgets periods older than the window');
run('DELETE FROM online_periods');
$old    = $period($other, 32 * 86400, 31 * 86400);
$edge   = $period($other, 31 * 86400, 29 * 86400);   // began outside, still seen inside
$recent = $period($other, 11 * 86400, 10 * 86400);
is_same(1, presence_prune(30), 'one period ended more than thirty days ago');
is_same([$edge, $recent], array_map('intval', array_column($periods($other), 'id')), 'exactly that one is gone');
is_same(1, presence_prune(20), 'a shorter horizon removes more');
$period($other, 45 * 86400, 44 * 86400);
is_same(1, presence_prune(90), 'a longer one than thirty days is not honoured');
$period($other, 45 * 86400, 44 * 86400);
set_setting('presence_history_days', 30);
prune_expired();
is_same([$recent], array_map('intval', array_column($periods($other), 'id')), 'and the nightly prune calls it');

case_('The history says when recording began, while that is inside the window');
$version = '020_when_somebody_was_online.sql';
$kept = one('SELECT * FROM schema_migrations WHERE version=?', [$version]);
run('DELETE FROM schema_migrations WHERE version=?', [$version]);
is_same(null, presence_recorded_since(), 'without the ledger row there is nothing to say');
run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$version, str_repeat('0', 64), $ago(5 * 86400)]);
is_same($ago(5 * 86400), presence_recorded_since(), 'five days after the update, since then');
run('UPDATE schema_migrations SET applied_at=? WHERE version=?', [$ago(40 * 86400), $version]);
is_same(null, presence_recorded_since(), 'once the whole window is covered, nothing');
run('DELETE FROM schema_migrations WHERE version=?', [$version]);
if ($kept) run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$kept['version'], $kept['checksum'], $kept['applied_at']]);

case_('Choosing a status: staff only, never while looking through somebody else\'s eyes');
$presence = fn(int $id): string => (string)$account($id)['presence'];
sign_in_as($family);
throws(fn() => act('presence_save', ['presence' => 'away']), 'a family is refused, in a sentence', 'Einen Status wählen nur Trainerinnen');
is_same('auto', $presence($family), 'and nothing changed');
view_as($admin, $family);
throws(fn() => act('presence_save', ['presence' => 'hidden']), 'staff viewing as a family are told how to get back',
    'Beende zuerst die Ansicht');
is_same('auto', $presence($family), 'and the family\'s status is untouched');
is_same('auto', $presence($admin), 'and so is the administrator\'s');
unset($_SESSION['impersonator_id']);
sign_in_as($trainer);
throws(fn() => act('presence_save', ['presence' => 'invisible']), 'a value that is not a choice is refused', 'Ungültige Auswahl');
is_same('auto', $presence($trainer), 'and nothing changed');

case_('Choosing a status takes her back to where she was, and is not logged');
sign_in_as($trainer);
$_SESSION['seen_written'] = time();
$audits = (int)scalar('SELECT COUNT(*) FROM audit_log');
$versions = (int)scalar('SELECT COUNT(*) FROM record_versions');
$back = act('presence_save', ['presence' => 'hidden', 'return_page' => 'student', 'return_id' => '5', 'return_tab' => 'payments']);
is_same(['student', ['id' => 5, 'tab' => 'payments']], $back, 'the same page, record and tab');
is_same('hidden', $presence($trainer), 'the status is saved');
is_same('Du wirst jetzt als offline angezeigt.', $_SESSION['flash']['message'] ?? null, 'and said in a sentence');
is_same(0, (int)$_SESSION['seen_written'], 'the next page view writes, so the new kind of period starts at once');
is_same($audits, (int)scalar('SELECT COUNT(*) FROM audit_log'), 'not audited');
is_same($versions, (int)scalar('SELECT COUNT(*) FROM record_versions'), 'nor kept as a change to undo');
act('presence_save', ['presence' => 'away', 'return_page' => 'dashboard']);
is_same('Dein Status ist jetzt „Abwesend“.', $_SESSION['flash']['message'] ?? null, 'away has its own sentence');
is_same(['dashboard', []], act('presence_save', ['presence' => 'auto', 'return_page' => 'https://example.com/']),
    'an address that is not a page of the portal goes to the overview instead');
is_same('Dein Status richtet sich wieder nach deiner Aktivität.', $_SESSION['flash']['message'] ?? null, 'and automatic has its own');
