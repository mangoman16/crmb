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
 * The data is deliberately awkward rather than tidy - a child who left, a
 * family who is behind, an overdue month, somebody with no tariff - because a
 * portal that has only ever been seen with three neat rows in it is a portal
 * whose edge cases are found by the trainer.
 */

/** Given names and surnames that read as real without being anyone's. */
function demo_names(): array {
    return [
        ['Lena','Hofer'], ['Jonas','Berger'], ['Mia','Gruber'], ['Elias','Wagner'],
        ['Emma','Steiner'], ['Paul','Mayr'], ['Sophie','Reiter'], ['Felix','Moser'],
        ['Anna','Lechner','f'], ['David','Fuchs'], ['Marie','Winkler'], ['Tobias','Egger'],
        ['Valentina','Aigner'], ['Noah','Brandner'], ['Johanna','Pichler'],
    ];
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
        'accounts' => (int)scalar('SELECT COUNT(*) FROM accounts WHERE is_demo=1'),
        'courses'  => (int)scalar('SELECT COUNT(*) FROM classes WHERE is_demo=1'),
    ];
}

/**
 * A password nobody has to remember but everybody can type.
 *
 * Words rather than characters: it is read off a screen and typed into a phone,
 * often by somebody checking whether a parent's view looks right. Long enough
 * that strong_password() accepts it without being weak for the two days it
 * exists.
 */
function demo_password(): string {
    $words = ['Feder','Birke','Anker','Wolke','Kiesel','Ufer','Nebel','Halm','Funke','Dohle'];
    return $words[random_int(0, count($words) - 1)] . '-' . $words[random_int(0, count($words) - 1)]
        . '-' . random_int(1000, 9999);
}

/**
 * Fill the portal with example data.
 *
 * Refuses when there is real data to mix it into, unless the caller insists:
 * the flag keeps the two apart in the database, but a trainer looking at a list
 * of thirty children cannot tell at a glance which ones are pretend.
 */
function demo_fill(bool $force = false): array {
    // Refused even with $force: a second fill would collide on the demo accounts'
    // addresses, and two sets of pretend children is nobody's idea of a test.
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

        // --- accounts -------------------------------------------------------
        // Active and verified rather than invited, so trying the portal does not
        // first require working email and a released privacy notice.
        $accounts = [];
        foreach ([['Trainerin Beispiel','trainerin@beispiel.test','trainer'],
                  ['Familie Hofer','familie.hofer@beispiel.test','student'],
                  ['Familie Berger','familie.berger@beispiel.test','student']] as [$name,$email,$role]) {
            run('INSERT INTO accounts (name,email,password_hash,role,state,verified_at,locale,created_at,is_demo)'
                .' VALUES (?,?,?,?,?,?,?,?,1)', [$name, $email, $hash, $role, 'active', now(), 'de', now()]);
            $accounts[$email] = (int)db()->lastInsertId();
            $counts['accounts']++;
        }
        $trainerId = $accounts['trainerin@beispiel.test'];

        // --- tariffs --------------------------------------------------------
        $tariffs = [];
        foreach ([['Monatsbeitrag Kinder', 4500], ['Monatsbeitrag Jugend', 5500], ['Erwachsene', 6500]] as [$name,$price]) {
            run('INSERT INTO tariffs (name,price_cents,period,due_days,archived,is_demo) VALUES (?,?,?,?,0,1)',
                [$name, $price, 'monthly', 7]);
            $tariffs[] = (int)db()->lastInsertId();
        }

        // --- courses --------------------------------------------------------
        $courses = [];
        foreach ([['Kindertraining Montag', 1, '16:00:00', '17:30:00', 'Sporthalle Nord', $tariffs[0]],
                  ['Jugendtraining Mittwoch', 3, '17:00:00', '18:30:00', 'Sporthalle Nord', $tariffs[1]],
                  ['Erwachsene Freitag', 5, '19:00:00', '21:00:00', 'Sporthalle Süd', $tariffs[2]]] as $i => $c) {
            [$name,$weekday,$from,$to,$where,$tariff] = $c;
            run('INSERT INTO classes (name,description,weekday,starts_at,ends_at,location,trainer_id,tariff_id,capacity,sort_order,archived,created_at,is_demo)'
                .' VALUES (?,?,?,?,?,?,?,?,?,?,0,?,1)',
                [$name, '', $weekday, $from, $to, $where, $trainerId, $tariff, 16, ($i + 1) * 10, now()]);
            $courses[] = (int)db()->lastInsertId();
            $counts['courses']++;
        }

        // --- students -------------------------------------------------------
        $names = demo_names();
        $students = [];
        foreach ($names as $i => $n) {
            // Ages 7 to 41, so every age group has somebody in it and the
            // boundaries of the group definitions are actually exercised.
            $age = [7,9,10,11,12,13,14,15,16,17,18,22,29,35,41][$i] ?? 12;
            $birth = $today->modify('-' . $age . ' years')->modify('-' . random_int(0, 300) . ' days');
            $joined = $today->modify('-' . ($i * 47 + 20) . ' days');
            $status = match (true) { $i === 13 => 'ended', $i === 12 => 'paused', $i < 2 => 'trial', default => 'active' };
            $course = $age < 12 ? 0 : ($age < 18 ? 1 : 2);
            // Most follow their tariff; two have an agreed price of their own,
            // and one has none at all, which is what the billing preview has to
            // be able to explain rather than skip silently.
            $price = $i === 4 ? 4000 : ($i === 9 ? 5000 : null);
            run('INSERT INTO students (account_id,first_name,last_name,birth_date,joined_on,ended_on,status,tariff_id,'
                .'price_cents,price_note,billing_paused,billing_note,internal_notes,revision,created_at,updated_at,is_demo)'
                .' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,1)',
                [$i === 0 ? $accounts['familie.hofer@beispiel.test'] : ($i === 1 ? $accounts['familie.berger@beispiel.test'] : null),
                 $n[0], $n[1], $birth->format('Y-m-d'), $joined->format('Y-m-d'),
                 $status === 'ended' ? $today->modify('-30 days')->format('Y-m-d') : null,
                 $status, $i === 7 ? null : ($courses ? (int)scalar('SELECT tariff_id FROM classes WHERE id=?', [$courses[$course]]) : null),
                 $price, $price !== null ? 'Geschwisterermäßigung' : '',
                 $status === 'paused' ? 1 : 0, $status === 'paused' ? 'Verletzungspause' : '',
                 '', now(), now()]);
            $id = (int)db()->lastInsertId();
            $students[] = ['id' => $id, 'course' => $courses[$course], 'age' => $age, 'index' => $i];
            $counts['students']++;

            // Every child has somebody to ring. That is the point of the list.
            run('INSERT INTO contacts (student_id,owner_name,relation_label,phone,email) VALUES (?,?,?,?,?)',
                [$id, ($age < 18 ? 'Elternteil ' : '') . $n[1],
                 $age < 18 ? 'Erziehungsberechtigt' : 'Selbst',
                 '+43 660 ' . (1000000 + $i * 13), mb_strtolower($n[1]) . '@beispiel.test']);

            run('INSERT INTO class_students (class_id,student_id,joined_on,left_on) VALUES (?,?,?,?)',
                [$courses[$course], $id, $joined->format('Y-m-d'), $status === 'ended' ? $today->modify('-30 days')->format('Y-m-d') : null]);
            // Two of them train twice a week, because a student in more than one
            // course is the case the attendance and billing screens get wrong.
            if ($i === 2 || $i === 6)
                run('INSERT INTO class_students (class_id,student_id,joined_on,left_on) VALUES (?,?,?,NULL)',
                    [$courses[2], $id, $joined->format('Y-m-d')]);
        }

        // --- charges and payments -------------------------------------------
        foreach ($students as $s) {
            if ($s['index'] === 7) continue;                       // no tariff, no charges
            $amount = (int)(scalar('SELECT COALESCE(price_cents,(SELECT price_cents FROM tariffs WHERE id=students.tariff_id))'
                .' FROM students WHERE id=?', [$s['id']]) ?: 0);
            if ($amount <= 0) continue;
            for ($back = 2; $back >= 0; $back--) {
                $month = $today->modify('first day of this month')->modify('-' . $back . ' months');
                $due = $month->modify('+6 days');
                run('INSERT INTO charges (student_id,class_id,label,origin,billing_key,amount_cents,period_from,period_to,due_on,cancelled,created_at)'
                    .' VALUES (?,?,?,?,?,?,?,?,?,0,?)',
                    [$s['id'], $s['course'], 'Beitrag ' . billing_month_name($month->format('Y-m')), 'auto',
                     'demo:' . $month->format('Y-m') . ':s' . $s['id'], $amount,
                     $month->format('Y-m-d'), $month->modify('last day of this month')->format('Y-m-d'),
                     $due->format('Y-m-d'), now()]);
                $chargeId = (int)db()->lastInsertId();
                $counts['charges']++;
                // Two months back everyone has paid; the month before last one
                // family has not, so there is something overdue to look at.
                $paid = $back === 2 || ($back === 1 && $s['index'] % 4 !== 0);
                if (!$paid) continue;
                run('INSERT INTO payments (charge_id,amount_cents,paid_on,method,note,confirmed_by,confirmed_at,voided)'
                    .' VALUES (?,?,?,?,?,?,?,0)',
                    [$chargeId, $amount, $due->modify('-2 days')->format('Y-m-d'),
                     $s['index'] % 3 === 0 ? 'Bar' : 'Überweisung', '', $trainerId, now()]);
            }
        }

        // --- attendance and absences ----------------------------------------
        foreach ($courses as $courseId) {
            $class = one('SELECT * FROM classes WHERE id=?', [$courseId]);
            $date = new DateTimeImmutable(attendance_suggested_date($class));
            for ($week = 0; $week < 4; $week++) {
                $session = $date->modify('-' . ($week * 7) . ' days')->format('Y-m-d');
                foreach (rows('SELECT student_id FROM class_students WHERE class_id=? AND left_on IS NULL', [$courseId]) as $m) {
                    $status = random_int(1, 10) > 8 ? (random_int(0, 1) ? 'absent' : 'excused') : 'present';
                    run('INSERT INTO attendance (class_id,student_id,session_on,status,note,recorded_by,created_at)'
                        .' VALUES (?,?,?,?,?,?,?)', [$courseId, (int)$m['student_id'], $session, $status, '', $trainerId, now()]);
                }
            }
        }
        run('INSERT INTO absences (student_id,reason,starts_on,ends_on,created_by) VALUES (?,?,?,?,?)',
            [$students[3]['id'], 'holiday', $today->format('Y-m-d'), $today->modify('+10 days')->format('Y-m-d'), $trainerId]);
        run('INSERT INTO absences (student_id,reason,starts_on,ends_on,created_by) VALUES (?,?,?,?,?)',
            [$students[5]['id'], 'sick', $today->modify('-2 days')->format('Y-m-d'), $today->modify('+3 days')->format('Y-m-d'), $trainerId]);

        // --- news and a conversation ----------------------------------------
        run('INSERT INTO news (title,body,published,created_at,updated_at,is_demo) VALUES (?,?,1,?,?,1)',
            ['Hallenzeiten in den Ferien', "In den Ferien findet das Training nur am Mittwoch statt.\n\nDie Halle ist ab 17:00 offen.", now(), now()]);
        run('INSERT INTO news (title,body,published,created_at,updated_at,is_demo) VALUES (?,?,0,?,?,1)',
            ['Entwurf: Vereinsmeisterschaft', 'Termin steht noch nicht fest.', now(), now()]);

        run('INSERT INTO threads (account_id,subject,updated_at) VALUES (?,?,?)',
            [$accounts['familie.hofer@beispiel.test'], 'Frage zum Schläger', now()]);
        $thread = (int)db()->lastInsertId();
        run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',
            [$thread, $accounts['familie.hofer@beispiel.test'], 'Hallo! Welchen Schläger sollen wir für Lena kaufen?', now()]);
        run('INSERT INTO messages (thread_id,sender_id,body,created_at) VALUES (?,?,?,?)',
            [$thread, $trainerId, 'Hallo! Für den Anfang reicht ein leichter Schläger, ich bringe am Montag zwei zum Ausprobieren mit.', now()]);

        audit('demo.filled', 'settings');
        return $counts;
    });

    $result['password'] = $password;
    return $result;
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
        run('DELETE FROM tariffs WHERE is_demo=1');
        run('DELETE FROM news WHERE is_demo=1');
        run('DELETE FROM mail_jobs WHERE account_id IN (SELECT id FROM accounts WHERE is_demo=1)');
        run('DELETE FROM accounts WHERE is_demo=1');
        audit('demo.cleared', 'settings');
        return $counts;
    });
}
