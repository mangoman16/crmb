<?php
declare(strict_types=1);

/**
 * Example data, so the portal can be tried before real families are in it.
 *
 * Everything written here carries is_demo=1 and everything removed by
 * demo_clear() is selected by that flag, so the two halves cannot drift: if a
 * new kind of row is added to the filling without a flag, the clean-up leaves it
 * behind and the count printed afterwards says so.
 *
 * Its job is to show every screen to the owner and to the phone sweep; the
 * end-to-end walk builds its own data, and the billing suites their own edge
 * cases. So it is as little as shows each screen, and fixed (ADR 0026 §9): one
 * course, four children, two family logins and the trainer, nothing drawn at
 * random but the password. Dates are counted back from the day it is written,
 * so it says the same on any day: who is overdue, what is not yet due, and each
 * child's age, far enough from a birthday that the band the rule gives does not
 * change for most of a year.
 */

/**
 * An example student's own address: lena.hofer@beispiel.test. The example names
 * are plain ASCII, so lower-casing them is all it takes, and every one gives a
 * different address.
 */
function demo_address(string $first, string $last): string {
    return strtolower($first.'.'.$last).'@beispiel.test';
}

/** Whether the portal currently holds any example data. */
function demo_present(): bool {
    return (int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=1') > 0
        || (int)scalar('SELECT COUNT(*) FROM accounts WHERE is_demo=1') > 0;
}

/** How much example data there is, for the panel that offers to remove it. */
function demo_counts(): array {
    return [
        'students' => (int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=1'),
        // The logins somebody signs in with: an example student's placeholder
        // is no more a login to her than it is to the student (ADR 0023 §3).
        'accounts' => (int)scalar("SELECT COUNT(*) FROM accounts WHERE is_demo=1 AND state<>'placeholder'"),
        'courses'  => (int)scalar('SELECT COUNT(*) FROM classes WHERE is_demo=1'),
    ];
}

/**
 * How many days the example logins sign in for. The trainer's example address
 * is published, and a staff login: one forgotten on a portal on the internet
 * should not stay a way in. Two weeks is time enough to try the portal; after
 * that, removing the example data and filling it again gives fresh logins.
 */
const DEMO_LOGIN_DAYS = 14;

/**
 * Whether $account is an example login past its days (DEMO_LOGIN_DAYS). Asked
 * by sign_in(), where every way in ends, rather than by the daily prune, so
 * it holds on a portal whose background work never runs; a session still open
 * ends at the idle limit.
 */
function demo_login_expired(array $account): bool {
    return (int)($account['is_demo'] ?? 0) === 1
        && (string)($account['created_at'] ?? '') < gmdate('Y-m-d H:i:s', time() - DEMO_LOGIN_DAYS * 86400);
}

/**
 * A password nobody has to remember but everybody can type on a phone: four
 * made-up words of two syllables, each starting with a capital, so it is typed
 * on the letters keyboard alone - „KemoTapiRunaSofe". 75 syllables, eight of
 * them: about 10^15 passwords. The trainer's example address is published and
 * a staff login; ten tries a quarter of an hour (throttle()) make about 13,000
 * in the days an example login works, one chance in some 10^11. The two words
 * from ten and a four-digit number it replaced were 900,000 passwords, and its
 * logins never expired.
 */
function demo_password(): string {
    $consonants = 'bdfghklmnprstvz';
    $vowels = 'aeiou';
    $password = '';
    for ($syllable = 0; $syllable < 8; $syllable++) {
        $sound = $consonants[random_int(0, strlen($consonants) - 1)] . $vowels[random_int(0, strlen($vowels) - 1)];
        $password .= $syllable % 2 === 0 ? ucfirst($sound) : $sound;
    }
    return $password;
}

/**
 * Fill the portal with example data.
 *
 * Refuses when there is real data to mix it into, unless the caller insists:
 * the flag keeps the two apart in the database, but a trainer looking at a list
 * of thirty children cannot tell at a glance which ones are pretend.
 */
function demo_fill(bool $force = false): array {
    // Refused even with $force: two sets of pretend children is nobody's idea
    // of a test, and a second set's logins could not have the first one's
    // addresses (refuse_address_in_use()).
    if (demo_present())
        throw new UserError(t(
            'Es gibt schon Beispieldaten. Bitte zuerst die vorhandenen entfernen.',
            'Example data is already there. Remove the existing set first.'));
    if (!$force && (int)scalar('SELECT COUNT(*) FROM students WHERE is_demo=0') > 0)
        throw new UserError(t(
            'Es gibt bereits echte Schüler. Beispieldaten würden sich darunter mischen.',
            'There are real students already. Example data would be mixed in among them.'));

    $password = demo_password();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $result = transactional(function () use ($hash): array {
        $today = new DateTimeImmutable(today());
        $counts = ['students' => 0, 'accounts' => 0, 'courses' => 0, 'charges' => 0];

        // --- logins ---------------------------------------------------------
        // Active and verified rather than invited, so trying the portal does not
        // first require working email and a released privacy notice. Each family
        // login is one student's own and carries that student's name (ADR 0010).
        // Every login has an address of its own (ADR 0020): one already
        // somebody's refuses the fill rather than being shared.
        $accounts = [];
        foreach ([['Trainerin', 'Beispiel', 'trainerin@beispiel.test', 'trainer'],
                  ['Lena', 'Hofer', demo_address('Lena', 'Hofer'), 'student'],
                  ['Jonas', 'Berger', demo_address('Jonas', 'Berger'), 'student']] as [$given, $family, $email, $role]) {
            refuse_address_in_use($email);
            run('INSERT INTO accounts (name,email,password_hash,role,state,verified_at,locale,created_at,is_demo)'
                .' VALUES (?,?,?,?,?,?,?,?,1)', [$given.' '.$family, $email, $hash, $role, 'active', now(), 'de', now()]);
            $accounts[$email] = (int)db()->lastInsertId();
            $counts['accounts']++;
        }
        $trainerId = $accounts['trainerin@beispiel.test'];

        // --- the course, its day, its price and its group --------------------
        run('INSERT INTO classes (name,description,location,trainer_id,capacity,sort_order,archived,created_at,is_demo)'
            ." VALUES ('Kindertraining','','Sporthalle Nord',?,16,10,0,?,1)", [$trainerId, now()]);
        $courseId = (int)db()->lastInsertId();
        $counts['courses']++;
        $weekday = 1;   // Monday, 16:00 to 17:30
        run("INSERT INTO class_days (class_id,weekday,starts_at,ends_at,location,sort_order) VALUES (?,?,'16:00:00','17:30:00','',0)",
            [$courseId, $weekday]);
        run('INSERT INTO tariffs (class_id,name,description,period,interval_months,due_day,grace_days,first_period,due_days,sort_order,archived,is_demo)'
            ." VALUES (?,'Monatsbeitrag','','recurring',1,10,7,'prorate',7,0,0,1)", [$courseId]);
        $tariffId = (int)db()->lastInsertId();
        $price = 3500;
        run('INSERT INTO tariff_rates (tariff_id,interval_months,price_cents) VALUES (?,1,?)', [$tariffId, $price]);
        // Its group, with the trainer's welcome, so the chat can be tried before
        // real families arrive; it goes with the course (ADR 0022).
        run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',
            [course_group_thread($courseId), $trainerId,
             'Willkommen in der Gruppe „Kindertraining“! Hier schreibe ich, wenn sich am Training etwas ändert.', now()]);

        // --- four children ----------------------------------------------------
        // Each with what they are there to show. Lena and Jonas, the two family
        // logins, are 9 and 10 and Elias is 13, so „Nach Alter" has two of the
        // seeded bands, Unter 12 and Jugend; Mia has no birth date, so it ends
        // with „Ohne Geburtsdatum". Each is weeks past a birthday, so the band
        // holds for most of a year after the fill.
        $joined = $today->modify('first day of this month')->modify('-3 months');
        $levels = array_column(levels(), 'id');
        $children = [];
        foreach ([['Lena', 'Hofer', [9, 40], 'active', true], ['Jonas', 'Berger', [10, 200], 'trial', false],
                  ['Mia', 'Gruber', null, 'active', true], ['Elias', 'Wagner', [13, 120], 'active', true]] as $i => [$first, $last, $age, $status, $inCourse]) {
            $address = demo_address($first, $last);
            $birth = $age === null ? null : $today->modify('-'.$age[0].' years')->modify('-'.$age[1].' days')->format('Y-m-d');
            run('INSERT INTO students (account_id,first_name,last_name,email,birth_date,joined_on,status,level_id,revision,created_at,updated_at,is_demo)'
                .' VALUES (?,?,?,?,?,?,?,?,1,?,?,1)',
                // A family login is that child's; the others have the placeholder
                // every student has (ADR 0023 §4), example data like them.
                [$accounts[$address] ?? placeholder_login($first, $last, true), $first, $last, $address, $birth,
                 ($inCourse ? $joined : $today)->format('Y-m-d'), $status,
                 // Spread over the levels, so the rows and the filter show more than one.
                 $levels ? (int)$levels[$i % count($levels)] : null, now(), now()]);
            $id = (int)db()->lastInsertId();
            $children[$first] = $id;
            $counts['students']++;
            run('INSERT INTO contacts (student_id,owner_name,relation_label,phone,email,is_primary) VALUES (?,?,?,?,?,1)',
                [$id, 'Elternteil '.$last, 'Erziehungsberechtigt', '+43 660 100000'.$i, strtolower($last).'@beispiel.test']);
            if ($inCourse)
                run('INSERT INTO class_students (class_id,student_id,joined_on,left_on,tariff_id,price_cents,price_note,due_day)'
                    ." VALUES (?,?,?,NULL,?,NULL,'',0)", [$courseId, $id, $joined->format('Y-m-d'), $tariffId]);
        }

        // --- money -------------------------------------------------------------
        // Last month and this one for each child in the course, keyed as the
        // monthly run keys them, so the run takes them as charged. Last month
        // was due on the 10th and is late by now whatever the day; this month is
        // due a week from the fill, so it is open and not yet due.
        //   Lena: last month paid and confirmed; this month open, with the QR
        //         code (once the club's bank details are in) and „Beleg hochladen".
        //   Mia: last month overdue.
        //   Elias: this month paid, and not yet confirmed.
        $months = ['last' => $today->modify('first day of this month')->modify('-1 month'),
                   'this' => $today->modify('first day of this month')];
        foreach (['Lena' => ['last' => 'confirmed', 'this' => null], 'Mia' => ['last' => null, 'this' => null],
                  'Elias' => ['last' => 'confirmed', 'this' => 'recorded']] as $first => $paid) {
            foreach ($months as $which => $month) {
                $due = $which === 'last' ? $month->modify('+9 days') : $today->modify('+7 days');
                run('INSERT INTO charges (student_id,class_id,tariff_id,label,origin,billing_key,amount_cents,gross_cents,'
                    .'period_from,period_to,covered_from,covered_to,due_on,overdue_on,cancelled,created_at)'
                    ." VALUES (?,?,?,?,'auto',?,?,?,?,?,?,?,?,?,0,?)",
                    [$children[$first], $courseId, $tariffId, 'Beitrag '.billing_month_name($month->format('Y-m')),
                     billing_key($month->format('Y-m-d'), $children[$first], $courseId), $price, $price,
                     $month->format('Y-m-d'), $month->modify('last day of this month')->format('Y-m-d'),
                     $month->format('Y-m-d'), $month->modify('last day of this month')->format('Y-m-d'),
                     $due->format('Y-m-d'), $due->modify('+7 days')->format('Y-m-d'), now()]);
                $chargeId = (int)db()->lastInsertId();
                $counts['charges']++;
                if ($paid[$which] === null) continue;
                $confirmed = $paid[$which] === 'confirmed';
                run('INSERT INTO payments (charge_id,amount_cents,paid_on,method,note,confirmed_by,confirmed_at,voided)'
                    ." VALUES (?,?,?,'Überweisung','',?,?,0)",
                    [$chargeId, $price, ($which === 'last' ? $due->modify('-2 days') : $today)->format('Y-m-d'),
                     $confirmed ? $trainerId : null, $confirmed ? now() : null]);
            }
        }

        // --- a request, a sick note, attendance ---------------------------------
        // Jonas is not in the course yet and has asked to join, so the
        // trainer's „Anfragen" holds one.
        run('INSERT INTO enrolment_requests (student_id,class_id,kind,tariff_id,message,state,requested_by,created_at)'
            ." VALUES (?,?,'join',?,?,'pending',?,?)",
            [$children['Jonas'], $courseId, $tariffId, 'Jonas möchte gern mittrainieren.', $accounts[demo_address('Jonas', 'Berger')], now()]);
        // Mia is sick from today, for three days.
        run("INSERT INTO absences (student_id,reason,starts_on,ends_on,created_by) VALUES (?,'sick',?,?,?)",
            [$children['Mia'], $today->format('Y-m-d'), $today->modify('+2 days')->format('Y-m-d'), $trainerId]);
        // The course's last two training days before today: everybody there,
        // but Elias away on the latest.
        $latest = $today->modify('-'.((((int)$today->format('N') - $weekday + 6) % 7) + 1).' days');
        foreach ([$latest->modify('-7 days'), $latest] as $day)
            foreach (['Lena', 'Mia', 'Elias'] as $first)
                run('INSERT INTO attendance (class_id,student_id,session_on,status,note,recorded_by,created_at) VALUES (?,?,?,?,?,?,?)',
                    [$courseId, $children[$first], $day->format('Y-m-d'),
                     $first === 'Elias' && $day == $latest ? 'absent' : 'present', '', $trainerId, now()]);

        // --- news and a chat ----------------------------------------------------
        run('INSERT INTO news (title,body,published,created_at,updated_at,is_demo) VALUES (?,?,1,?,?,1)',
            ['Hallenzeiten in den Ferien', "In den Ferien findet das Training nur am Montag statt.\n\nDie Halle ist ab 15:30 offen.", now(), now()]);
        // Lena's family's chat with the trainer, made by direct_thread() as every
        // chat is, so it is the kind, the owner and the two people a real one
        // would have: built by hand it once lacked the rows of who is in it, and
        // the example family opened Nachrichten to be told they had none.
        // Example data that lies about the app is worse than none.
        $family = $accounts[demo_address('Lena', 'Hofer')];
        $thread = direct_thread(one('SELECT * FROM accounts WHERE id=?', [$family]), $trainerId);
        run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',
            [$thread, $family, 'Hallo! Welchen Schläger sollen wir für Lena kaufen?', now()]);
        run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',
            [$thread, $trainerId, 'Hallo! Für den Anfang reicht ein leichter Schläger, ich bringe am Montag zwei zum Ausprobieren mit.', now()]);

        audit('demo.filled', 'settings');
        return $counts;
    });

    $result['password'] = $password;
    $result['logins'] = demo_logins();
    return $result;
}

/**
 * The example logins, as address and role, staff first. For the setup page,
 * the console and the notice that shows the password. Only those somebody signs
 * in with: the example students' placeholders have neither an address nor a
 * password (ADR 0023 §3).
 */
function demo_logins(): array {
    return rows("SELECT email,role FROM accounts WHERE is_demo=1 AND state<>'placeholder' ORDER BY role='student', id");
}

/**
 * Remove everything demo_fill() created.
 *
 * Deleted parents first would be a foreign-key error on charges and payments,
 * which are RESTRICT on purpose: money is not something a cascade should take
 * with it. So they are removed explicitly, by way of the students they belong
 * to, and everything else follows its own cascade.
 */
function demo_clear(): array {
    return transactional(function (): array {
        $counts = demo_counts();
        run('DELETE FROM payments WHERE charge_id IN (SELECT id FROM charges WHERE student_id IN (SELECT id FROM students WHERE is_demo=1))');
        run('DELETE FROM charges WHERE student_id IN (SELECT id FROM students WHERE is_demo=1)');
        run('DELETE FROM students WHERE is_demo=1');
        run('DELETE FROM classes WHERE is_demo=1');
        // Spelled out rather than left to ON DELETE CASCADE: the rates and the
        // discount templates of a demo tariff are demo data too, and a cascade
        // is a promise of the engine rather than of this file.
        run('DELETE FROM tariff_rates WHERE tariff_id IN (SELECT id FROM tariffs WHERE is_demo=1)');
        run('DELETE FROM tariff_discounts WHERE tariff_id IN (SELECT id FROM tariffs WHERE is_demo=1)');
        run('DELETE FROM tariffs WHERE is_demo=1');
        run('DELETE FROM news WHERE is_demo=1');
        run('DELETE FROM mail_jobs WHERE account_id IN (SELECT id FROM accounts WHERE is_demo=1)');
        run('DELETE FROM accounts WHERE is_demo=1');
        audit('demo.cleared', 'settings');
        return $counts;
    });
}
