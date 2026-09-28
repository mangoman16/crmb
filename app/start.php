<?php
declare(strict_types=1);

/**
 * The start checklist: what a new portal still needs before families can use it
 * (ADR 0011).
 *
 * A checklist rather than a stepper, because setup is not done in order: the
 * bank details get filled in on the evening the letter turns up. Nothing here
 * is ticked by hand and nothing is stored about it. Every step is read from the
 * data on every visit, by asking the same function the page it links to asks,
 * so the checklist cannot call something done that the page itself still
 * complains about. A step whose data later falls back becomes undone again.
 *
 * Example data never counts. demo_fill() flags every account, student, course,
 * tariff and news item it writes, and everything else it writes hangs off one
 * of those; a portal holding nothing but example data is a portal with nothing
 * set up.
 */

/**
 * The request's memo of the steps, by reference so it can be emptied.
 *
 * Held for the request because the layout, the menu and the page may all ask,
 * and an action writes and then redirects, so nothing re-reads a step it has
 * just changed within the same request.
 */
function &setup_cache(): array { static $cache = []; return $cache; }
function setup_cache_clear(): void { $cache =& setup_cache(); $cache = []; }

/**
 * The nine steps, in the order they are shown.
 *
 * Each is ['key', 'what', 'why', 'page', 'params', 'anchor'?, 'done',
 * 'blocked_by', 'blocked']: 'page', 'params' and 'anchor' lead to the screen
 * that already does the job, carrying from=start so that screen can offer the
 * way back. 'blocked_by' lists the keys of steps that have to be done first,
 * and 'blocked' says whether one of them is not.
 */
function setup_steps(): array {
    $cache =& setup_cache();
    if (isset($cache['steps'])) return $cache['steps'];

    // --- who is running this, and where the money goes -------------------
    $issuer = invoice_issuer_problems();
    $house = payment_profile((int)setting('default_payment_profile'));

    // --- courses ------------------------------------------------------------
    $courses = real_course_ids();
    // The first thing class_next_steps() still asks of any course, which is
    // also where the step leads: one call per course, and courses are few.
    $courseGap = null;
    foreach ($courses as $courseId)
        if ($courseGap = (class_next_steps($courseId)[0] ?? null)) break;

    // --- children -----------------------------------------------------------
    // One query for every enrolment rather than student_next_steps() for every
    // child; the rule applied to each is the same enrolment_has_price().
    $children = (int)scalar("SELECT COUNT(*) FROM students WHERE is_demo=0 AND status<>'ended'");
    $unpriced = null;
    foreach (billing_enrolments() as $enrolment)
        if ((int)$enrolment['is_demo'] === 0 && $enrolment['status'] !== 'ended' && $enrolment['left_on'] === null
            && !enrolment_has_price($enrolment)) { $unpriced = (int)$enrolment['student_id']; break; }

    // --- families -------------------------------------------------------------
    $invited = (bool)scalar("SELECT COUNT(*) FROM students s JOIN accounts a ON a.id=s.account_id"
        ." WHERE s.is_demo=0 AND a.is_demo=0 AND a.state IN ('invited','active')");
    $uninvited = (int)(scalar("SELECT id FROM students WHERE is_demo=0 AND status<>'ended' AND account_id IS NULL"
        .' ORDER BY first_name, last_name, id LIMIT 1') ?: 0);

    $steps = [
        ['key' => 'organisation',
         'what' => t('Name und Anschrift', 'Name and address'),
         'why'  => t('Stehen auf jeder Rechnung und in der Datenschutzerklärung.', 'They appear on every invoice and in the privacy notice.'),
         'page' => 'settings', 'params' => ['tab' => 'organisation'],
         'done' => !array_intersect_key($issuer, ['name' => 1, 'address' => 1, 'tax' => 1]),
         'blocked_by' => []],
        ['key' => 'bank',
         'what' => t('Bankkonto', 'Bank account'),
         'why'  => t('Damit Rechnungen und der QR-Code sagen, wohin das Geld geht.', 'So invoices and the QR code say where the money goes.'),
         'page' => 'manage', 'params' => ['tab' => 'payments'] + ($house ? ['edit' => (int)$house['id']] : []),
         'done' => !isset($issuer['iban']),
         'blocked_by' => []],
        ['key' => 'first_course',
         'what' => t('Ersten Kurs anlegen', 'Create the first course'),
         'why'  => t('Kinder werden in Kurse eingetragen, und Kurse haben Termine und Preise.', 'Children are put into courses, and courses have dates and prices.'),
         'page' => 'classes', 'params' => ['new' => 1],
         'done' => $courses !== [],
         'blocked_by' => []],
        ['key' => 'course_prices',
         'what' => t('Preis für jeden Kurs', 'A price for every course'),
         'why'  => t('Jeder Kurs braucht einen Trainingstag und einen Tarif, sonst hat er keine Termine und keine Beiträge.', 'Every course needs a training day and a tariff, or it has no dates and bills nobody.'),
         'page' => $courseGap['page'] ?? 'classes', 'params' => $courseGap['params'] ?? [],
         'done' => $courses !== [] && $courseGap === null,
         'blocked_by' => ['first_course']],
        ['key' => 'students',
         'what' => t('Kinder eintragen', 'Enter the children'),
         'why'  => t('Jedes Kind in einem Kurs mit Preis, damit seine Beiträge entstehen.', 'Each child in a course with a price, so their charges are created.'),
         // Straight to the child whose course has no price; with nobody yet,
         // to the form for the first one.
         'page' => $unpriced ? 'student' : ($children ? 'students' : 'student'),
         'params' => $unpriced ? ['id' => $unpriced, 'tab' => 'classes'] : [],
         'anchor' => $unpriced ? 'courses' : null,
         'done' => $children > 0 && $unpriced === null,
         'blocked_by' => []],
        ['key' => 'billing',
         'what' => t('Beiträge', 'Charges'),
         'why'  => t('Monatsbeiträge automatisch anlegen lassen oder einmal selbst anlegen.', 'Have the monthly charges created automatically, or create them once yourself.'),
         'page' => 'payments', 'params' => [],
         'done' => (bool)setting('auto_billing')
             || (bool)scalar('SELECT COUNT(*) FROM charges c JOIN students s ON s.id=c.student_id WHERE s.is_demo=0 AND c.cancelled=0'),
         'blocked_by' => []],
        ['key' => 'mail',
         'what' => t('E-Mails verschicken', 'Sending email'),
         'why'  => t('SMTP eintragen und einmal testen. Einladungen, Rechnungen und Erinnerungen gehen per E-Mail.', 'Enter the SMTP details and test them once. Invitations, invoices and reminders go by email.'),
         'page' => 'settings', 'params' => ['tab' => 'smtp'],
         'done' => smtp_tested_ok(),
         'blocked_by' => []],
        ['key' => 'privacy',
         'what' => t('Datenschutzerklärung', 'Privacy notice'),
         'why'  => t('Prüfen und freigeben. Familien bestätigen sie, bevor sie sich anmelden.', 'Check it and release it. Families acknowledge it before they sign in.'),
         'page' => 'settings', 'params' => ['tab' => 'privacy'],
         'done' => (bool)setting('privacy_ready'),
         'blocked_by' => []],
        ['key' => 'invite',
         'what' => t('Familien einladen', 'Invite the families'),
         'why'  => t('Dann sehen sie Termine und Beiträge selbst.', 'Then they see dates and charges themselves.'),
         'page' => $uninvited ? 'student' : 'students', 'params' => $uninvited ? ['id' => $uninvited] : [],
         'anchor' => $uninvited ? 'access' : null,
         'done' => $invited,
         // The invitation is an email with a link to a notice they must read.
         'blocked_by' => ['mail', 'privacy']],
    ];

    $done = array_column($steps, 'done', 'key');
    foreach ($steps as &$step) {
        $step['params'] += ['from' => 'start'];
        if (empty($step['anchor'])) unset($step['anchor']);
        $step['blocked'] = (bool)array_filter($step['blocked_by'], fn($key) => !$done[$key]);
    }
    unset($step);
    return $cache['steps'] = $steps;
}

/**
 * How far setup has come: ['done' => n, 'total' => 9, 'next' => step|null].
 *
 * 'next' is the first step not done that nothing else holds up, or null when
 * everything is done.
 */
function setup_progress(): array {
    $steps = setup_steps();
    $open = array_values(array_filter($steps, fn($s) => !$s['done']));
    $ready = array_values(array_filter($open, fn($s) => !$s['blocked']));
    return ['done' => count($steps) - count($open), 'total' => count($steps), 'next' => $ready[0] ?? $open[0] ?? null];
}

/**
 * Whether the checklist is still asking for something.
 *
 * False once she has hidden it, and then without looking at a single step: a
 * portal set up years ago should not pay for the checklist on every page.
 */
function setup_unfinished(): bool {
    if (setting('setup_hidden')) return false;
    return setup_progress()['next'] !== null;
}

/**
 * Where somebody lands after signing in or following an emailed link.
 *
 * An administrator lands on the checklist at every sign-in while it is
 * unfinished - that is the moment the owner asked for - and on the overview
 * otherwise. The overview itself never redirects there: she would be trapped
 * on the checklist until everything was done.
 */
function landing_after_sign_in(array $account): array {
    return is_admin($account) && setup_unfinished() ? ['start', []] : ['dashboard', []];
}

/**
 * Remember that an administrator left the checklist to do one of its steps.
 *
 * Called once, from public/index.php, beside record_step(). A link from the
 * checklist carries from=start, which sets the flag; opening the checklist
 * clears it. It is kept in the session rather than the URL so that it survives
 * the redirect after a save without every action having to pass it on, and it
 * names the account, so a different administrator signing in on the same
 * phone does not inherit it. Logging out empties the session and it with it.
 */
function note_setup_return(string $page): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $account = current_user();
    if (!$account || !is_admin($account)) return;
    if ($page === 'start') { unset($_SESSION['setup_return']); return; }
    if (($_GET['from'] ?? null) === 'start') $_SESSION['setup_return'] = (int)$account['id'];
}

/**
 * Whether the page should offer the way back to the checklist.
 *
 * The layout asks this together with setup_unfinished(): once everything is
 * done, or the checklist is hidden, there is nothing to go back to.
 */
function setup_return_active(): bool {
    $account = current_user();
    return $account !== null && is_admin($account) && (int)($_SESSION['setup_return'] ?? 0) === (int)$account['id'];
}
