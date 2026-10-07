<?php
/**
 * What the pages show about presence, and the account menu in the top bar
 * (ADR 0015, 0016, 0022). tests/suites/presence.php checks the rules in
 * app/presence.php; this checks that the pages ask them, and draw what the owner
 * decided: everybody has their dot and a status emoji to pick, only staff choose
 * a status, from a menu whose current choice is not a button, and only staff see
 * when somebody was online - with the hidden periods for administrators alone.
 */

$admin    = make_account(['role' => 'admin',   'name' => 'Admin Person']);
$trainer  = make_account(['role' => 'trainer', 'name' => 'Trainerin Beispiel']);
$trainer2 = make_account(['role' => 'trainer', 'name' => 'Zweite Trainerin']);
$family   = make_account(['role' => 'student', 'name' => 'Familie Hofer']);
$child    = make_student(['first_name' => 'Lena', 'last_name' => 'Hofer', 'account_id' => $family, 'email' => 'a4@example.test']);
$ago      = fn(int $seconds): string => gmdate('Y-m-d H:i:s', time() - $seconds);

/** An XPath over $html, and a way to name a class in it. */
function presence_xpath(string $html): DOMXPath {
    $dom = new DOMDocument();
    $quiet = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors(); libxml_use_internal_errors($quiet);
    return new DOMXPath($dom);
}
function presence_class(string $name): string { return "contains(concat(' ', normalize-space(@class), ' '), ' $name ')"; }

/** The account menu of a page: its dot, its rows, and what each form posts. */
function account_menu_of(string $html): array {
    $x = presence_xpath($html);
    $menu = $x->query('//header//details['.presence_class('account-menu').']')->item(0);
    if (!$menu) return ['found' => false];
    $posted = [];
    foreach ($x->query('.//form', $menu) as $form) {
        $fields = [];
        foreach ($x->query('.//input[@type="hidden"]', $form) as $input) $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        $posted[] = ['action' => $fields['action'] ?? '', 'presence' => $fields['presence'] ?? null,
                     'submits' => $x->query('.//button[@type="submit"]', $form)->length];
    }
    return [
        'found'    => true,
        'topbar'   => $menu->parentNode->nodeName === 'div' && str_contains((string)$menu->getAttribute('class'), 'topbar-menu'),
        'dots'     => $x->query('.//*['.presence_class('presence-dot').']', $menu->getElementsByTagName('summary')->item(0))->length,
        'status'   => $x->query('.//*['.presence_class('account-menu-status').']', $menu)->length,
        'current'  => array_map(fn($n) => trim($n->textContent), iterator_to_array($x->query('.//*['.presence_class('is-current').']', $menu))),
        'currentIsButton' => $x->query('.//button['.presence_class('is-current').'] | .//*['.presence_class('is-current').']//button', $menu)->length,
        'emoji'    => array_map(fn($n) => trim($n->textContent), iterator_to_array($x->query('.//*['.presence_class('is-chosen').']', $menu))),
        'emojiIsButton' => $x->query('.//button['.presence_class('is-chosen').']', $menu)->length,
        'posted'   => $posted,
        'profile'  => $x->query('.//a[@href="'.url('profile').'"]', $menu)->length,
        'label'    => (string)$menu->getElementsByTagName('summary')->item(0)?->getAttribute('aria-label'),
    ];
}

// ---------------------------------------------------------------------------
case_('A family\'s account menu: „Mein Konto", the status emoji and „Abmelden", with their dot and no status');
sign_in_as($family);
$html = render_page('dashboard');
$menu = account_menu_of($html);
ok($menu['found'], 'the top bar has an account menu');
ok($menu['topbar'], 'and it is a top-bar menu, held to the same rules as the bell');
is_same(1, $menu['profile'], 'it links to „Mein Konto"');
is_same([['action' => 'status_emoji_save', 'presence' => null, 'submits' => count(status_emojis())],
         ['action' => 'logout', 'presence' => null, 'submits' => 1]], $menu['posted'],
        'one form picks the emoji - every one a button but the chosen „Keins" - and one signs out');
is_same(0, $menu['status'], 'no status block: a child\'s status is always automatic');
is_same(1, $menu['dots'], 'their avatar carries their dot (ADR 0022)');
is_same('Konto-Menü: Familie Hofer, Online', $menu['label'], 'and the label says they are online');
is_same(['Keins (gewählt)'], $menu['emoji'], 'with no emoji chosen, „Keins" is marked');
is_same(0, $menu['emojiIsButton'], 'and the marked one is not a button');
ok(!str_contains($html, 'account-menu-note'), 'nobody\'s menu explains the status any more');
run("UPDATE accounts SET status_emoji='fox' WHERE id=?", [$family]);
sign_in_as($family);
$menu = account_menu_of(render_page('dashboard'));
is_same(['🦊 Fuchs (gewählt)'], $menu['emoji'], 'a chosen emoji is the marked one');
ok(str_contains(render_page('dashboard'), '<span class="status-emoji" aria-hidden="true">🦊</span>'), 'and stands beside their name');
run("UPDATE accounts SET status_emoji='' WHERE id=?", [$family]);

// ---------------------------------------------------------------------------
case_('Staff see their status: the current choice is a marked row, the other two are buttons');
foreach (['auto' => ['away', 'hidden'], 'away' => ['auto', 'hidden'], 'hidden' => ['auto', 'away']] as $chosen => $offered) {
    run('UPDATE accounts SET presence=? WHERE id=?', [$chosen, $trainer]);
    sign_in_as($trainer);
    $menu = account_menu_of(render_page('dashboard'));
    is_same(1, $menu['status'], 'with „'.$chosen.'" chosen there is a status block');
    is_same(1, count($menu['current']), 'one row is marked as chosen');
    ok(str_starts_with($menu['current'][0] ?? '', presence_choices()[$chosen]), 'and it is „'.presence_choices()[$chosen].'"');
    is_same(0, $menu['currentIsButton'], 'which is not a button');
    $choices = array_values(array_filter(array_column($menu['posted'], 'presence')));
    is_same($offered, $choices, 'the other two are offered, in the menu\'s order');
    is_same(2, count(array_filter($menu['posted'], fn($f) => $f['action'] === 'presence_save' && $f['submits'] === 1)), 'each in a form of its own with one button');
    is_same('logout', end($menu['posted'])['action'], 'and signing out comes last');
    is_same(1, $menu['dots'], 'her avatar carries her dot');
}
run('UPDATE accounts SET presence=? WHERE id=?', ['hidden', $trainer]);
sign_in_as($trainer);
ok(str_contains(account_menu_of(render_page('dashboard'))['label'], 'Als offline angezeigt'), 'the label says when she appears offline');
run('UPDATE accounts SET presence=? WHERE id=?', ['auto', $trainer]);
sign_in_as($trainer);
is_same('Konto-Menü: Trainerin Beispiel, Online', account_menu_of(render_page('dashboard'))['label'], 'and that she is online, however long ago her last visit was stored');

// ---------------------------------------------------------------------------
case_('While looking through somebody else\'s eyes there is no status to change');
view_as($admin, $trainer);
$menu = account_menu_of(render_page('dashboard'));
is_same(0, $menu['status'], 'no status block, even when the person looked at is staff');
is_same(0, $menu['dots'], 'and no dot: it would show the person looked at as online this minute');
is_same(['logout'], array_column($menu['posted'], 'action'), 'only signing out');
is_same('Konto-Menü: Trainerin Beispiel', $menu['label'], 'and the label is the name alone');
unset($_SESSION['impersonator_id']);

// ---------------------------------------------------------------------------
case_('Only staff see when somebody was online, on the student page and the team page');
run('UPDATE accounts SET last_seen_at=? WHERE id=?', [$ago(3 * 3600), $family]);
fixture('online_periods', ['account_id' => $family, 'started_at' => $ago(3 * 3600 + 600), 'last_seen_at' => $ago(3 * 3600), 'hidden' => 0]);
sign_in_as($trainer);
$page = render_page('student', ['id' => $child]);
$x = presence_xpath($page);
is_same(1, $x->query('//*['.presence_class('access-card').']//*['.presence_class('presence-line').']')->length, 'a trainer sees the login\'s presence line on the student page');
is_same(1, $x->query('//*['.presence_class('access-card').']//details['.presence_class('presence-history').']')->length, 'and its history');
ok(str_contains($page, 'Abwesend · zuletzt '.fmt_datetime($ago(3 * 3600))), 'the line says what the dot means and when');
ok(str_contains($page, 'An 1 von 30 Tagen online.'), 'the history counts the day');
ok(!str_contains($page, 'last_seen_at'), 'nothing leaks the column name');
sign_in_as($family);
$page = render_page('student', ['id' => $child]);
ok(!str_contains($page, 'presence-line') && !str_contains($page, 'presence-history') && !str_contains($page, 'zuletzt'),
   'the family sees none of it on their own page');
sign_in_as($admin);
$x = presence_xpath(render_page('accounts'));
ok($x->query('//*['.presence_class('presence-line').']')->length >= 3, 'an administrator sees a presence line for each member of staff');
ok($x->query('//details['.presence_class('presence-history').']')->length >= 3, 'and a history under each');

case_('The drawing helpers give a family dots, never times or history');
/* The pages above never ask for a family's line or history, so these are what
   stands behind them if one ever does. */
$F = one('SELECT * FROM accounts WHERE id=?', [$family]);
$T = one('SELECT * FROM accounts WHERE id=?', [$trainer]);
is_same('', presence_line($F, $T), 'no presence line about the trainer');
is_same('', presence_line($F, $F), 'nor about themselves');
ok(str_contains(presence_dot($F, $T), 'presence-dot') && str_contains(presence_dot($F, $F), 'presence-dot'), 'the dots, the trainer\'s and their own');
ob_start(); presence_history_details($F, [['started_at' => $ago(600), 'last_seen_at' => $ago(60), 'hidden' => false]], null);
is_same('', (string)ob_get_clean(), 'and no history, even when handed periods');
ok(str_contains(presence_line($T, $F), 'presence-dot'), 'while a trainer asking about the family gets the line');

case_('An invitation not yet taken up shows no presence');
$invited = make_account(['role' => 'trainer', 'name' => 'Noch Eingeladen', 'state' => 'invited', 'verified_at' => null]);
sign_in_as($admin);
$x = presence_xpath(render_page('accounts'));
$row = $x->query('//div['.presence_class('account-row').'][.//h3[text()="Noch Eingeladen"]]')->item(0);
ok($row !== null, 'the invited trainer is listed');
is_same(0, $row ? $x->query('.//*['.presence_class('presence-line').'] | .//details['.presence_class('presence-history').']', $row)->length : -1,
        'with no presence line and no history');

// ---------------------------------------------------------------------------
case_('Periods spent appearing offline are shown to administrators only, and marked');
run('DELETE FROM online_periods');
run('UPDATE accounts SET presence=?, last_seen_at=? WHERE id=?', ['hidden', $ago(7200), $trainer2]);
fixture('online_periods', ['account_id' => $trainer2, 'started_at' => $ago(9000), 'last_seen_at' => $ago(7200), 'hidden' => 1]);
fixture('online_periods', ['account_id' => $trainer2, 'started_at' => $ago(3 * 86400), 'last_seen_at' => $ago(3 * 86400 - 600), 'hidden' => 0]);
$rowOf = function (string $html, string $name): string {
    $x = presence_xpath($html);
    $row = $x->query('//div['.presence_class('account-row').'][.//h3[text()="'.$name.'"]]')->item(0);
    return $row ? (string)$row->ownerDocument->saveHTML($row) : '';
};
sign_in_as($admin);
$asAdmin = $rowOf(render_page('accounts'), 'Zweite Trainerin');
ok(str_contains($asAdmin, '(als offline angezeigt)'), 'the administrator is told the person appears offline');
ok(str_contains($asAdmin, 'class="is-hidden"'), 'the hidden day is drawn in the strip');
ok(str_contains($asAdmin, 'zuletzt '.fmt_datetime($ago(7200))), 'with the true time');
is_same(2, substr_count($asAdmin, 'class="is-hidden"') + substr_count($asAdmin, 'class="is-on"'), 'both days show');
sign_in_as($trainer);
$asTrainer = $rowOf(render_page('accounts'), 'Zweite Trainerin');
ok($asTrainer !== '', 'the trainer sees the row');
ok(!str_contains($asTrainer, 'als offline angezeigt'), 'but is not told about hiding');
ok(!str_contains($asTrainer, 'is-hidden'), 'nor shown the hidden period');
ok(str_contains($asTrainer, 'zuletzt '.fmt_datetime($ago(3 * 86400 - 600))), 'and the time stops where she hid');
is_same(1, substr_count($asTrainer, 'class="is-on"'), 'one day online, the visible one');
run('UPDATE accounts SET presence=? WHERE id=?', ['auto', $trainer2]);

// ---------------------------------------------------------------------------
case_('The team page asks when recording began once, not once per person');
/* In the first month after the update every history says since when it has been
   recorded; asked per row, that was one query more for every person listed. */
$version = '020_when_somebody_was_online.sql';
$kept = one('SELECT * FROM schema_migrations WHERE version=?', [$version]);
run('DELETE FROM schema_migrations WHERE version=?', [$version]);
run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$version, str_repeat('0', 64), $ago(5 * 86400)]);
sign_in_as($admin);
$page = render_page('accounts');
is_same(substr_count($page, 'class="presence-history"'), substr_count($page, 'Aufgezeichnet wird seit dem '.fmt_date($ago(5 * 86400)).'.'),
        'every history on the page says since when');
$queries = fn() => query_count(fn() => render_view('accounts'));
$before = $queries();
for ($i = 0; $i < 5; $i++) make_account(['role' => 'trainer', 'name' => 'Weitere '.$i, 'last_seen_at' => $ago(60)]);
$added = $queries() - $before;
ok($added < 5, 'five more people cost fewer than five more queries ('.$added.')');
ok(substr_count($page, 'class="presence-history"') > 0, 'and there was a history on the team page to ask about');
// A child's page draws one history, for the child's login, and says the same.
$studentPage = render_view('student', ['id' => $child]);
is_same(1, substr_count($studentPage, 'class="presence-history"'), 'the child\'s page shows the history of the child\'s login');
ok(str_contains($studentPage, 'Aufgezeichnet wird seit dem '.fmt_date($ago(5 * 86400)).'.'), 'and says since when, too');
run('DELETE FROM schema_migrations WHERE version=?', [$version]);
if ($kept) run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$kept['version'], $kept['checksum'], $kept['applied_at']]);
