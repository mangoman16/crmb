<?php
declare(strict_types=1);

function statuses(): array { return setting('statuses',['trial'=>'Probetraining','active'=>'Aktiv','paused'=>'Pausiert','ended'=>'Beendet']); }
function reasons(): array { return setting('absence_reasons',['sick'=>'Krank','holiday'=>'Urlaub','other'=>'Abwesend']); }
function status_label(string $s): string { $en=['trial'=>'Trial','active'=>'Active','paused'=>'Paused','ended'=>'Ended']; return locale()==='en' && isset($en[$s])?$en[$s]:(statuses()[$s]??$s); }
function reason_label(string $s): string { $en=['sick'=>'Sick','holiday'=>'Holiday','other'=>'Absent']; return locale()==='en' && isset($en[$s])?$en[$s]:(reasons()[$s]??$s); }
function student(int $id): array {
    $u=require_user();
    $s=one('SELECT s.*,l.name AS level_name FROM students s LEFT JOIN levels l ON l.id=s.level_id WHERE s.id=?'.(is_staff($u)?'':' AND s.account_id=?'),is_staff($u)?[$id]:[$id,$u['id']]);
    if(!$s) throw new NotFound(t('Schüler nicht gefunden.','Student not found.')); return $s;
}
/**
 * The people to ring about one child, the first one to try first.
 *
 * Exactly one contact per child carries is_primary; the actions below keep it
 * that way. The ordering falls back to the oldest row so that a record written
 * before that rule existed still answers with somebody rather than nothing.
 *
 * This is a list of people to ring, and nothing else. It used to double as the
 * address the portal writes to, which made one row do two jobs that are not the
 * same job - the grandmother who should be rung has no email, the father who
 * reads the invoices is never in the hall - and the form could not say either
 * without lying about the other. Where the portal writes is on the child.
 */
function student_contacts(int $studentId): array {
    return rows('SELECT * FROM contacts WHERE student_id=? ORDER BY is_primary DESC, id',[$studentId]);
}

function primary_contact(int $studentId): ?array {
    return one('SELECT * FROM contacts WHERE student_id=? ORDER BY is_primary DESC, id LIMIT 1',[$studentId]);
}

/**
 * What one child's record is still missing, in the words she would use, or ''
 * when nothing is.
 *
 * Two different things, said separately because they are fixed in two different
 * places: somebody to ring in an emergency, and an address to write to.
 */
function contact_gap(int $studentId): string {
    $student=one('SELECT email FROM students WHERE id=?',[$studentId]);
    if(!primary_contact($studentId))
        return t('Für dieses Kind ist noch keine Notfall-Kontaktperson eingetragen.','No emergency contact has been entered for this child yet.');
    if((string)($student['email']??'')==='')
        return t('Für dieses Kind ist noch keine E-Mail-Adresse eingetragen – dorthin gehen Einladung, Rechnungen und Erinnerungen.','This child has no email address yet – that is where the invitation, the invoices and the reminders go.');
    return '';
}

/** What a contact form carries as an email: optional, but valid when given. */
function contact_email(): string { $email=post('email'); return $email===''?'':email_value($email); }

/**
 * How a contact's line in the change log is labelled: the student's name and
 * the contact's (ADR 0020, §7), because "Oma" alone does not say whose.
 */
function contact_history_label(array $student, string $owner): string {
    return $student['first_name'].' '.$student['last_name'].' · '.$owner;
}

/**
 * The address the portal writes to for one student.
 *
 * Their own login's where they have one, because that is the address their
 * reset links go to and changing it goes through a confirmation step;
 * otherwise what is written on the student, which is what an invitation would
 * be sent to. The two are kept equal (change_account_email()), so preferring
 * the login only matters for a row written before that rule.
 */
function student_email(array $student): string {
    $account=$student['account_id']?one('SELECT email FROM accounts WHERE id=?',[(int)$student['account_id']]):null;
    return (string)($account['email'] ?? $student['email'] ?? '');
}

/**
 * The login an address already belongs to, if any - for a page to say so
 * before she taps. A plain read: the refusal itself is refuse_address_in_use(),
 * which holds what it reads.
 *
 * Two callers: student_next_steps(), and the access card on the student page,
 * which say that the address on a student without a login is somebody else's
 * login, and that the student needs one of their own (ADR 0020, §1).
 */
function account_with_address(string $email): ?array {
    $email=email_normalised($email);
    return $email===''?null:one('SELECT * FROM accounts WHERE email=?',[$email]);
}

/*
 * A student login that no student points to is one of two things, and
 * verified_at says which (ADR 0021, §4): the first activation sets it and
 * nothing clears it. Never set up, it is an invitation by address still waiting
 * for its holder to make their student; set up, it was left behind by a deleted
 * student. That holds only because student_delete takes a never-set-up login
 * with its student. A suspended invitation is still an open one.
 */
function student_login_without_student_sql(string $account = 'a'): string {
    $a = sql_name($account, 'alias');
    return $a.".role='student' AND NOT EXISTS (SELECT 1 FROM students s WHERE s.account_id=".$a.'.id)';
}
function open_invitation_sql(string $account = 'a'): string {
    return student_login_without_student_sql($account).' AND '.sql_name($account, 'alias').'.verified_at IS NULL';
}

/** Invitations by address nobody has taken up yet, newest first, for the students page. */
function open_invitations(): array {
    return rows('SELECT a.* FROM accounts a WHERE '.open_invitation_sql().' ORDER BY a.created_at DESC, a.id DESC');
}

/** Whether a login is one of open_invitations(). */
function is_open_invitation(int $accountId): bool {
    return (bool)scalar('SELECT 1 FROM accounts a WHERE a.id=? AND '.open_invitation_sql(), [$accountId]);
}

/**
 * Whether deleting a student also deletes their login (ADR 0021, §4): a
 * student's login nobody ever set up. A staff login on a student's record, left
 * from before ADR 0010, is never taken with it - a trainer may not touch one.
 */
function login_goes_with_student(?array $login): bool {
    return $login !== null && ($login['role'] ?? '') === 'student' && ($login['verified_at'] ?? null) === null;
}

/**
 * Student logins left behind by a deleted student, which was set up and so
 * stays (ADR 0010). Without a list of their own on the Konten page they could
 * neither be seen nor switched off.
 */
function orphan_logins(): array {
    return rows('SELECT a.* FROM accounts a WHERE '.student_login_without_student_sql()
        .' AND a.verified_at IS NOT NULL ORDER BY a.name,a.id');
}

/*
 * „Zugänge" in three categories (ADR 0023 §8): the trainers, the
 * administrators, and one row per student, each leading to that student's
 * access card. The page only reads; every action on a student's login is on
 * the access card.
 */

/** The team's logins by role, in the owner's order: trainers, then administrators. */
function team_logins(): array {
    $team = ['trainer' => [], 'admin' => []];
    foreach (rows("SELECT * FROM accounts WHERE role IN ('admin','trainer','manager') ORDER BY name,id") as $a)
        $team[$a['role'] === 'admin' ? 'admin' : 'trainer'][] = $a;
    return $team;
}

/** How many student rows the Schüler card shows at a time. */
const STUDENT_LOGINS_PER_PAGE = 50;

/**
 * The Schüler card's filter chips, as key => the condition on the login aliased
 * a. „Noch nicht angemeldet" is every login waiting for its first sign-in - an
 * invitation by e-mail and a username with a link alike: the badge tells the
 * two apart, the chip asks who has not arrived yet.
 */
function student_login_filters(): array {
    return [
        'all'         => '1=1',
        'waiting'     => "a.state='invited'",
        'placeholder' => "a.state='placeholder'",
        'suspended'   => "a.state='suspended'",
    ];
}

/**
 * How many students each chip holds, as key => n: one query for all four. The
 * column aliases are numbered, because a chip's key - „all" - can be a word the
 * database reserves.
 */
function student_login_counts(): array {
    $filters = student_login_filters();
    $sums = [];
    foreach (array_values($filters) as $i => $condition) $sums[] = 'COALESCE(SUM('.$condition.'),0) AS n'.$i;
    return array_combine(array_keys($filters), array_map('intval', array_values(one('SELECT '.implode(',', $sums).' FROM students s JOIN accounts a ON a.id=s.account_id') ?? [])));
}

/**
 * One page of the Schüler card: every student with their login, sorted by last
 * name, as the login's columns - for login_state_badge(), presence_line() and
 * the sign-in name - plus the student's id and names, and link_expires_at: when
 * the newest invitation or sign-in link still waiting runs out, or null when
 * there is none (lapsed links are pruned every night). An unknown filter is
 * 'all'. The link's date only, never its hash.
 *
 * The page is held to what an offset can be: a ?p= of twenty nines would
 * otherwise multiply out past the largest integer into a float, which the
 * database refuses as an OFFSET - a 503 for a typo. Past the last page, a page
 * is empty.
 */
function student_logins(string $filter, int $page): array {
    $condition = student_login_filters()[$filter] ?? student_login_filters()['all'];
    $offset = (min(max(1, $page), intdiv(PHP_INT_MAX, STUDENT_LOGINS_PER_PAGE)) - 1) * STUDENT_LOGINS_PER_PAGE;
    return rows('SELECT a.*, s.id AS student_id, s.first_name, s.last_name,'
        ." (SELECT MAX(t.expires_at) FROM auth_tokens t WHERE t.account_id=a.id AND t.purpose IN ('invite','signin')) AS link_expires_at"
        .' FROM students s JOIN accounts a ON a.id=s.account_id WHERE '.$condition
        .' ORDER BY s.last_name, s.first_name, s.id LIMIT '.STUDENT_LOGINS_PER_PAGE.' OFFSET '.$offset);
}

/*
 * The wizard's draft (ADR 0023 §5): step 1's details, kept in the session under
 * a random key between the two steps, so the address carries the key and never
 * the details; once student_create has made the student, the same slot keeps
 * which student it became. Only the wizard's two actions write one; a page
 * reads it and writes nothing (ADR 0003).
 */

/** How long a draft is kept, and how many one session holds. */
const STUDENT_DRAFT_SECONDS = 7200;
const STUDENT_DRAFTS_KEPT = 10;

/** Whether $key is shaped like a draft's key: 32 hex characters, nothing a person typed. */
function student_draft_key(string $key): bool { return preg_match('/^[a-f0-9]{32}$/D', $key) === 1; }

/**
 * What the session keeps under $key - a draft, or the student it became - or
 * null: never made, pushed out by ten newer ones, or older than two hours. The
 * age is decided here, as it is read, so a stale slot is gone for every page at
 * the same moment; student_draft removes it from the session the next time it
 * writes.
 */
function student_draft_slot(string $key): ?array {
    if (!student_draft_key($key)) return null;
    $slot = $_SESSION['student_drafts'][$key] ?? null;
    return is_array($slot) && (int)($slot['saved_at'] ?? 0) >= time() - STUDENT_DRAFT_SECONDS ? $slot : null;
}

/** The draft kept under $key while it is still one, or null - gone, or made into a student already. */
function student_draft(string $key): ?array {
    $slot = student_draft_slot($key);
    return $slot !== null && !isset($slot['made']) ? $slot : null;
}

/**
 * The student a draft became, or 0. student_create keeps the slot with the
 * student's id in it, under the same two hours and ten slots, so Back from the
 * done page - or a step of it sent again - finds the child made, rather than an
 * empty form that would make them twice (ADR 0023 §5).
 */
function student_made_from_draft(string $key): int {
    return (int)(student_draft_slot($key)['made'] ?? 0);
}

/*
 * "Has a login", in the trainer's sense (ADR 0023 §3): somebody can sign in
 * with it, or has been asked to. Every student has a login from the moment the
 * student exists, but a placeholder signs in with nothing - „Ohne Anmeldung" -
 * and to everything that asks "can we write to them, invite them, is that
 * done?" it is no login at all. Asked here and nowhere else, in the two forms it
 * is asked in, written side by side so they cannot drift apart. A student with
 * no account_id at all can only be one an update has not reached yet; it reads
 * the same.
 */
function login_without_sign_in(?array $account): bool {
    return $account === null || ($account['state'] ?? '') === 'placeholder';
}
function student_without_sign_in_sql(string $student = 's'): string {
    $s = sql_name($student, 'alias');
    return '('.$s.'.account_id IS NULL OR EXISTS (SELECT 1 FROM accounts pl WHERE pl.id='.$s.".account_id AND pl.state='placeholder'))";
}

/**
 * The student whose login is still a placeholder and whose record carries this
 * address, or null. An invitation by address to it would make the same person
 * twice, so email_invite and the wizard refuse it and point to that student's
 * own page (ADR 0021 §3, 0023 §3).
 */
function student_without_login_at(string $email): ?array {
    return one('SELECT s.id,s.first_name,s.last_name FROM students s WHERE s.email=? AND '.student_without_sign_in_sql().' ORDER BY s.id LIMIT 1', [$email]);
}

/**
 * What a new student starts with that nobody typed: the create form shows these,
 * and a student made through an invitation by address gets them
 * (create_own_student()), so the two ways in cannot start differently.
 *
 * level_default() is in app/groups.php, loaded after this file: safe, because
 * this runs only while a request runs, never while files load.
 */
function new_student_defaults(): array {
    return ['joined_on' => today(), 'status' => (string)setting('default_status', 'active'),
            'level_id' => level_default()['id'] ?? null, 'age_group_id' => null,
            'address' => '', 'phone' => '', 'internal_notes' => ''];
}

/**
 * A student's login, held for the rest of the transaction. Every student has
 * one (ADR 0023 §4): the update gives one to every student it finds without,
 * every way of making a student makes one, and the key is RESTRICT. A student
 * without is that promise broken, not something the trainer can put right, so
 * it stops the request and reports itself (ADR 0012) - it is not a refusal for
 * her to read. The column itself may be NULL: the update gives the logins only
 * after the migrations have run, so NOT NULL would stop it (ADR 0023, Rejected).
 */
function student_login_locked(array $student): array {
    $login = $student['account_id'] ? lock_row('accounts', (int)$student['account_id']) : null;
    if (!$login) throw new LogicException('A student without a login: give_every_student_a_login() gives every student one.');
    return $login;
}

/** The student a login belongs to (ADR 0010), or 0 when it belongs to none. */
function login_student_id(int $accountId): int {
    return (int)(scalar('SELECT id FROM students WHERE account_id=?', [$accountId]) ?: 0);
}

/**
 * Where a family is sent instead of the students list, or null for staff.
 *
 * The list is the trainer's; a family has one student, so an old bookmark to
 * it opens that student's own page, and a login nobody points to any more
 * opens the overview. The page stays open to everyone in the router - this
 * decides where a family lands, not who may look.
 */
function students_list_instead(array $account): ?array {
    if (is_staff($account)) return null;
    $own = login_student_id((int)$account['id']);
    return $own ? ['student', ['id' => $own]] : ['dashboard', []];
}

/**
 * The courses that count as hers: running (not archived) and not example data.
 *
 * One definition for every place that asks "is there a real course yet?" - the
 * start checklist and the overview - so the two cannot disagree about it.
 */
function real_course_ids(): array {
    return array_map('intval', array_column(rows('SELECT id FROM classes WHERE archived=0 AND is_demo=0'
        .' ORDER BY sort_order, name, id'), 'id'));
}
function real_course_exists(): bool { return real_course_ids() !== []; }

/** The children she still has to ask for something. Ended memberships are not chased. */
function students_missing_contact(): array {
    return rows('SELECT s.id,s.first_name,s.last_name FROM students s'
        ." WHERE s.status<>'ended' AND NOT EXISTS (SELECT 1 FROM contacts c WHERE c.student_id=s.id)"
        .' ORDER BY s.first_name,s.last_name');
}

/**
 * What is still missing on one child's record, as things to do with links.
 *
 * Creating a child asks for a name and an address and nothing else, because a
 * form with twenty boxes on it is a form somebody abandons. The rest is not
 * optional, though - a child with no course is a child nobody bills - so the
 * page says what is left rather than leaving her to remember.
 *
 * In the order she would do them: somebody to ring, a way to reach the family,
 * a course, and the price they are on.
 *
 * Each step names the element on that page it is about ('anchor'), because a
 * link to the page she is already on did nothing she could see.
 */
function student_next_steps(int $studentId): array {
    // The waiting join request comes with the row, so the page pays nothing
    // extra for it on every tab.
    $student = one('SELECT s.*, '.student_without_sign_in_sql().' AS without_sign_in, '.pending_join_sql().' AS pending_join FROM students s WHERE s.id=?', [$studentId]);
    if (!$student) return [];
    $steps = [];
    $withoutSignIn = (bool)$student['without_sign_in'];
    if (!primary_contact($studentId))
        $steps[] = ['what' => t('Notfallkontakt eintragen', 'Add an emergency contact'),
                    'why'  => t('Wen du anrufst, wenn etwas ist.', 'Who you ring if something happens.'),
                    'page' => 'student', 'params' => ['id' => $studentId, 'tab' => 'contacts'], 'anchor' => 'add-contact'];
    if ((string)$student['email'] === '' && $withoutSignIn)
        $steps[] = ['what' => t('E-Mail-Adresse eintragen', 'Add an email address'),
                    'why'  => t('Dorthin gehen Einladung, Rechnungen und Erinnerungen.', 'The invitation, the invoices and the reminders go there.'),
                    'page' => 'student', 'params' => ['id' => $studentId], 'anchor' => 'email'];
    // Asked before "invite": an invitation to this address would be refused
    // (refuse_address_in_use()), and a button that can only fail is not a next
    // step. Typically a brother's or a parent's address, typed in before the
    // rule came back; a parent's belongs on the contacts.
    elseif ($withoutSignIn && account_with_address((string)$student['email']))
        $steps[] = ['what' => t('Eigene E-Mail-Adresse eintragen', 'Enter an email address of their own'),
                    'why'  => t('Die eingetragene Adresse ist schon der Zugang einer anderen Person. Jede Person braucht ihre eigene; die Adresse der Eltern gehört zu den Kontakten.',
                                'The address on the record is already somebody else’s login. Everybody needs their own; a parent’s address belongs on the contacts.'),
                    'page' => 'student', 'params' => ['id' => $studentId], 'anchor' => 'email'];
    elseif ($withoutSignIn)
        $steps[] = ['what' => t('Zugang einladen', 'Invite them in'),
                    'why'  => t('Damit die Familie Termine und Beiträge selbst sieht.', 'So the family can see dates and charges themselves.'),
                    'page' => 'student', 'params' => ['id' => $studentId], 'anchor' => 'access'];
    // Only the courses they are still in: a child who has left every one of them
    // needs a course again, and saying otherwise would tick the box for ever on
    // the strength of a membership that ended in March.
    $enrolments = array_filter(student_enrolments($studentId), 'enrolment_is_current');
    if (!$enrolments && $student['pending_join'] !== null)
        $steps[] = ['what' => t('Kursanfrage beantworten', 'Answer the course request'),
                    'why'  => strtr(t('{name} möchte in „{course}“.', '{name} would like to join “{course}”.'),
                                    ['{name}' => $student['first_name'], '{course}' => $student['pending_join']]),
                    'page' => 'student', 'params' => ['id' => $studentId, 'tab' => 'classes'], 'anchor' => 'requests'];
    elseif (!$enrolments)
        $steps[] = ['what' => t('In einen Kurs eintragen', 'Put them in a course'),
                    'why'  => t('Ohne Kurs entstehen keine Beiträge.', 'Without a course there are no charges.'),
                    'page' => 'student', 'params' => ['id' => $studentId, 'tab' => 'classes'], 'anchor' => 'add-course'];
    // enrolment_has_price() is the rule billing skips by, so this step appears
    // exactly when a charge would not. Which words it uses is the only thing
    // decided here: no tariff at all, or a tariff that comes to nothing.
    elseif ($unpriced = array_filter($enrolments, fn($e) => !enrolment_has_price($e)))
        $steps[] = array_filter($unpriced, fn($e) => $e['tariff_id'] === null)
            ? ['what' => t('Tarif wählen', 'Choose a tariff'),
               'why'  => t('Eine Kursteilnahme hat noch keinen Tarif.', 'One of their courses has no tariff yet.'),
               'page' => 'student', 'params' => ['id' => $studentId, 'tab' => 'classes'], 'anchor' => 'courses']
            : ['what' => t('Preis eintragen', 'Enter a price'),
               'why'  => t('Eine Kursteilnahme hat einen Tarif ohne Preis, dafür entstehen keine Beiträge.', 'One of their courses is on a tariff with no price, so it bills nothing.'),
               'page' => 'student', 'params' => ['id' => $studentId, 'tab' => 'classes'], 'anchor' => 'courses'];
    return $steps;
}

/**
 * What a family still has to fill in on their own student, as next steps in
 * the shape student_next_steps() has, so next_steps_card() draws it (ADR 0020,
 * §7). Shown to the family on their dashboard and their student page; the
 * staff page keeps student_next_steps().
 *
 * A course first, then somebody to ring, a birth date, a postal address -
 * every family has one, and an invoice above 400 € needs it (create_invoice())
 * - and every custom field the family fills in ('edit') that is required and
 * still empty. Not the phone: it is the member's own number, a child may have
 * none, and an item some families can never tick off teaches every family to
 * ignore the card.
 *
 * Each step names the element on the page it is about ('anchor').
 */
function family_next_steps(int $studentId): array {
    $student = one('SELECT birth_date,address FROM students WHERE id=?', [$studentId]);
    if (!$student) return [];
    $steps = [];
    // First, because it is why they came (ADR 0021, §3).
    if (wants_a_course($studentId)) {
        $steps[] = ['what' => t('Kurs wählen', 'Choose a course'),
                    'why'  => t('Such dir einen Kurs aus. Deine Trainerin bestätigt die Anmeldung.', 'Pick a course. Your coach confirms your place.'),
                    'page' => 'student', 'params' => ['id' => $studentId, 'tab' => 'classes'], 'anchor' => free_courses_for($studentId) ? 'add-course' : 'courses'];
    }
    // Safety first, then what the age group and the invoices need, then her
    // own questions; the words are the designer's (spec §6.1). The anchors are
    // the ids the student page gives those boxes.
    if (!primary_contact($studentId))
        $steps[] = ['what' => t('Notfallkontakt eintragen', 'Add an emergency contact'),
                    'why'  => t('Wen die Trainerin anruft, wenn im Training etwas passiert.', 'Who your coach rings if something happens at training.'),
                    'page' => 'student', 'params' => ['id' => $studentId, 'tab' => 'contacts'], 'anchor' => 'add-contact'];
    if ((string)($student['birth_date'] ?? '') === '')
        $steps[] = ['what' => t('Geburtsdatum eintragen', 'Add the date of birth'),
                    'why'  => t('Danach richtet sich die Altersgruppe.', 'It decides the age group.'),
                    'page' => 'student', 'params' => ['id' => $studentId], 'anchor' => 'birth-date'];
    // postal_address_missing() is in app/invoices.php, which is loaded after
    // this file: safe, because this runs only while a request runs, never while
    // files load - the same arrangement as avatar() calling upload_version().
    if (postal_address_missing($student))
        $steps[] = ['what' => t('Anschrift eintragen', 'Add the postal address'),
                    'why'  => t('Sie steht auf deinen Rechnungen.', 'It goes on your invoices.'),
                    'page' => 'student', 'params' => ['id' => $studentId], 'anchor' => 'address'];
    // The label alone is the link text, so a yes/no field reads as well as a size.
    foreach (field_definitions() as $f)
        if (custom_field_required_of($f, false) && custom_value_empty(field_value($studentId, (int)$f['id'])))
            $steps[] = ['what' => field_label($f),
                        'why'  => t('Bitte ausfüllen – deine Trainerin bittet darum.', 'Please fill this in – your coach has asked for it.'),
                        'page' => 'student', 'params' => ['id' => $studentId], 'anchor' => 'field-'.$f['id']];
    return $steps;
}

/**
 * Whether a family still has a course to choose: a membership that has not
 * ended, no course they are in now, and no request to join one waiting. The
 * trainer confirms a place, because joining bills (ADR 0021, §3). One rule for
 * the „Kurs wählen" step and the Kurse tab's word when no course is free.
 */
function wants_a_course(int $studentId): bool {
    $student = one('SELECT s.status, '.pending_join_sql().' AS pending_join FROM students s WHERE s.id=?', [$studentId]);
    return $student && $student['status'] !== 'ended' && $student['pending_join'] === null
        && !array_filter(student_enrolments($studentId), 'enrolment_is_current');
}

/**
 * SQL for the course of the oldest join request still waiting for the student
 * aliased $student, by name, or NULL. Both lists of next steps ask it, so a
 * family who asked is not asked again and the trainer is told to answer.
 */
function pending_join_sql(string $student = 's'): string {
    return "(SELECT c.name FROM enrolment_requests r JOIN classes c ON c.id=r.class_id WHERE r.student_id=".sql_name($student, 'alias')
        .".id AND r.kind='join' AND r.state='pending' ORDER BY r.created_at, r.id LIMIT 1)";
}

/** The children with nowhere to send an invitation or an invoice. */
function students_missing_email(): array {
    return rows('SELECT s.id,s.first_name,s.last_name FROM students s'
        ." WHERE s.status<>'ended' AND s.email='' AND ".student_without_sign_in_sql()
        .' ORDER BY s.first_name,s.last_name');
}

/**
 * What counts as money actually received.
 *
 * A payment counts once it has been confirmed and has not been voided. This one
 * rule decides every balance, the overdue filter and the payments screen, so it
 * is written here and nowhere else — a second copy is the one that gets
 * forgotten when the rule changes, and the two then disagree about what a family
 * owes. The `structure` suite fails if the condition reappears spelled out.
 */
function payment_counts_sql(string $payment='p'): string {
    return sql_name($payment,'alias').'.confirmed_at IS NOT NULL AND '.sql_name($payment,'alias').'.voided=0';
}

/**
 * SQL for the amount confirmed against one charge, as a correlated subquery.
 *
 * $charge is how the charges table is aliased in the surrounding query.
 */
function charge_paid_sql(string $charge='c'): string {
    return 'COALESCE((SELECT SUM(p.amount_cents) FROM payments p'
        .' WHERE p.charge_id='.sql_name($charge,'alias').'.id AND '.payment_counts_sql().'),0)';
}

/**
 * SQL for the day a charge stops being merely open and starts being late.
 *
 * A charge written before grace days existed has no overdue_on, and for it the
 * due date is the answer - which is exactly how it behaved before. Written once
 * here for the same reason payment_counts_sql() is: a second copy is the one
 * that gets forgotten, and the two then disagree about who owes money.
 */
function charge_overdue_sql(string $charge='c'): string {
    $a=sql_name($charge,'alias');
    return 'COALESCE('.$a.'.overdue_on, '.$a.'.due_on)';
}

/**
 * Whether a charge is overdue: not cancelled, past the day it turns late, and
 * not paid in full. The one rule for „überfällig“ on a charge, in the two forms
 * it is asked in - a row already read (it needs 'paid', which student_charges()
 * gives it) and a condition in a query, which takes today() as its one
 * parameter - written side by side so they cannot drift apart. A reminder run
 * that read „past its day“ alone counted every paid charge as one it skipped.
 */
function charge_is_overdue(array $charge): bool {
    return !(int)$charge['cancelled'] && ($charge['overdue_on'] ?: $charge['due_on']) < today()
        && (int)($charge['paid'] ?? 0) < (int)$charge['amount_cents'];
}
function charge_is_overdue_sql(string $charge='c'): string {
    $a=sql_name($charge,'alias');
    return $a.'.cancelled=0 AND '.charge_overdue_sql($charge).'<? AND '.$a.'.amount_cents>'.charge_paid_sql($charge);
}

/**
 * What has been recorded against a charge: every payment not voided, whether
 * it has been confirmed yet or not.
 *
 * Not what has been received - that is payment_counts_sql(). This is the other
 * question, „how much of this charge is already accounted for?“, which decides
 * how much more may be recorded against it. „Zahlung erfassen“ asked it one way
 * and „Als bezahlt eintragen“ the other, and a transfer recorded but not yet
 * confirmed was paid a second time.
 */
function payment_recorded_sql(string $payment='p'): string { return sql_name($payment,'alias').'.voided=0'; }
function charge_recorded_sql(string $charge='c'): string {
    return 'COALESCE((SELECT SUM(r.amount_cents) FROM payments r WHERE r.charge_id='.sql_name($charge,'alias').'.id AND '
        .payment_recorded_sql('r').'),0)';
}

/**
 * Confirm a payment: the money has arrived. Tracked, so the change log says who
 * confirmed it; a payment already confirmed, or voided, is left as it is.
 */
function confirm_payment(array $payment): void {
    $by=current_user()['id'] ?? null;
    tracked('payments',(int)$payment['id'],money((int)$payment['amount_cents']),
        fn()=>run('UPDATE payments SET confirmed_at=?,confirmed_by=? WHERE id=? AND confirmed_at IS NULL AND voided=0',[now(),$by,(int)$payment['id']]));
}

function balance(int $studentId, bool $overdue=false): int {
    $charges=rows('SELECT c.amount_cents,'.charge_paid_sql().' AS paid FROM charges c WHERE c.student_id=? AND c.cancelled=0'.($overdue?' AND '.charge_overdue_sql().'<?':''),$overdue?[$studentId,today()]:[$studentId]);
    return array_sum(array_map(fn($c)=>max(0,(int)$c['amount_cents']-(int)$c['paid']),$charges));
}
/**
 * Outstanding amounts for many students at once, as id => cents.
 *
 * The list views show a balance on every card. Calling balance() per card is one
 * query per student, which is invisible with five children and slow with sixty,
 * so anything rendering a list resolves them together.
 */
function balances(bool $overdue=false): array {
    $out=[];
    foreach(rows('SELECT c.student_id, SUM(GREATEST(0, c.amount_cents - COALESCE(p.paid,0))) AS due'
        .' FROM charges c LEFT JOIN (SELECT charge_id, SUM(amount_cents) AS paid FROM payments'
        .'   WHERE '.payment_counts_sql('payments').' GROUP BY charge_id) p ON p.charge_id=c.id'
        .' WHERE c.cancelled=0'.($overdue?' AND '.charge_overdue_sql().'<?':'').' GROUP BY c.student_id',
        $overdue?[today()]:[]) as $r) $out[(int)$r['student_id']]=(int)$r['due'];
    return $out;
}

/**
 * The payments recorded against each of several charges, as charge_id => rows.
 *
 * The student payments tab lists every charge with its payments underneath.
 * Fetching them per charge is one query per month of membership, which grows for
 * as long as she uses the application, so they are fetched together.
 */
function payments_by_charge(array $chargeIds): array {
    $ids = array_values(array_unique(array_map('intval', $chargeIds)));
    if (!$ids) return [];
    $out = array_fill_keys($ids, []);
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (rows('SELECT p.*,a.name AS confirmer FROM payments p LEFT JOIN accounts a ON a.id=p.confirmed_by'
        .' WHERE p.charge_id IN ('.$in.') ORDER BY p.paid_on DESC, p.id DESC', $ids) as $row)
        $out[(int)$row['charge_id']][] = $row;
    return $out;
}

function student_charges(int $id): array { return rows('SELECT c.*,'.charge_paid_sql().' AS paid FROM charges c WHERE c.student_id=? ORDER BY c.due_on DESC,c.id DESC',[$id]); }
function field_definitions(bool $archived=false): array { return rows('SELECT * FROM field_definitions'.($archived?'':' WHERE archived=0').' ORDER BY sort_order,id'); }
function field_label(array $f): string { return locale()==='en' && $f['label_en']?$f['label_en']:$f['label']; }
function field_value(int $studentId,int $fieldId): mixed { $v=scalar('SELECT value_json FROM field_values WHERE student_id=? AND field_id=?',[$studentId,$fieldId]); return $v===false?null:json_decode($v,true); }
/**
 * Whether a custom field's value counts as not filled in: nothing, an empty
 * text, no option chosen, or a box not ticked. One rule for the refusal of a
 * required field and for the family's list of what is still missing, so the two
 * cannot disagree about whether something was done.
 */
function custom_value_empty(mixed $v): bool { return $v===null || $v==='' || $v===false || $v===[]; }

/**
 * Whether a required custom field is required of this person: a field the
 * family fills in ('edit') is required of the family, and a 'view' or
 * 'internal' field of staff (ADR 0020, §7). The one answer the save that
 * refuses, the family's list of what is missing and the page that marks the
 * box all ask, so they cannot disagree.
 */
function custom_field_required_of(array $field, bool $staff): bool {
    return (bool)$field['required'] && ($field['visibility']==='edit')!==$staff;
}
/**
 * A custom field's value, checked and in the shape it is stored, or a refusal.
 *
 * The type checks - a date, a number, an offered option - apply to every value
 * that is not empty, for everybody. Whether an empty one is refused is the
 * caller's to say ($emptyRefused): a required field is required of whoever
 * fills it in, which save_custom_fields() works out (ADR 0020, §7).
 */
function validate_custom(array $f,mixed $v,mixed $old,bool $emptyRefused): mixed {
    $opts=json_decode($f['options_json'],true);
    if($f['field_type']==='multiselect') {
        if(!is_array($v)) $v=[];
        if(count($v)>100) throw new UserError(t('Zu viele Optionen.','Too many options.'));
        foreach($v as $x) if(!is_string($x) || (!in_array($x,$opts,true) && !in_array($x,is_array($old)?$old:[],true))) throw new UserError(t('Ungültige Option.','Invalid option.'));
        $v=array_values(array_unique($v));
    } elseif($f['field_type']==='checkbox') { $v=(bool)$v;
    } else {
        if(!is_scalar($v) && $v!==null) throw new UserError(t('Ungültiger Feldwert.','Invalid field value.'));
        $v=trim((string)$v);
        if(mb_strlen($v)>4000) throw new UserError(t('Feldwert zu lang.','Field value is too long.'));
        if($v!=='') {
            if($f['field_type']==='date') date_value($v,true);
            if($f['field_type']==='number' && !preg_match('/^-?\d+(?:[.,]\d+)?$/D',$v)) throw new UserError(t('Zahl erwartet: ','Number expected: ').$f['label']);
            if($f['field_type']==='select' && !in_array($v,$opts,true) && $v!==$old) throw new UserError(t('Ungültige Option: ','Invalid option: ').$f['label']);
        }
    }
    if($emptyRefused && custom_value_empty($v)) throw new UserError(t('Pflichtfeld: ','Required field: ').$f['label']);
    return $v;
}
/**
 * Write the custom fields posted with a student's form. Called inside the
 * student's own tracked() or tracked_insert(), so the values are part of that
 * one change-log line (entity_snapshot()).
 *
 * A family writes only the fields at 'edit'; the rest are skipped, whatever is
 * posted. A required field is required of whoever fills it in (ADR 0020, §7):
 * an 'edit' field refuses the family's save while empty and never staff's - she
 * may fill it in and need not - and a 'view' or 'internal' field refuses
 * staff's save of an existing student. Creating a student ($new) refuses none,
 * because the create form carries no custom fields at all.
 */
function save_custom_fields(int $id,bool $new): void {
    $input=$_POST['custom']??[]; if(!is_array($input)) throw new UserError(t('Ungültige Eingabe.','Invalid input.'));
    $staff=is_staff();
    foreach(field_definitions() as $f) {
        $families=$f['visibility']==='edit';
        if(!$staff && !$families) continue;
        $old=field_value($id,(int)$f['id']);
        $value=$input[$f['id']]??($f['field_type']==='checkbox'?false:($f['field_type']==='multiselect'?[]:''));
        $value=validate_custom($f,$value,$old,!$new && custom_field_required_of($f,$staff));
        run('INSERT INTO field_values (student_id,field_id,value_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)',[$id,$f['id'],json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
}
function filters_from(array $data): array {
    $keys=['q','status','absence','overdue','tariff','level','age_group','course','field','value']; $out=[];
    foreach($keys as $key) if(isset($data[$key]) && is_scalar($data[$key])) $out[$key]=mb_substr(trim((string)$data[$key]),0,200);
    return $out;
}
function filtered_students(array $f,?int $accountId=null): array {
    $where=['1=1']; $p=[];
    if($accountId!==null){$where[]='s.account_id=?';$p[]=$accountId;}
    if(!empty($f['q'])){$where[]="CONCAT(s.first_name,' ',s.last_name) LIKE ?";$p[]='%'.$f['q'].'%';}
    if(!empty($f['status'])){$where[]='s.status=?';$p[]=$f['status'];}
    // What a child pays is decided per course (ADR 0011), so "on this tariff"
    // means a course they are still in names it; students.tariff_id bills nobody.
    if(!empty($f['tariff'])){$where[]='EXISTS (SELECT 1 FROM class_students cs WHERE cs.student_id=s.id AND cs.tariff_id=? AND '.current_enrolment_sql().')';$p[]=(int)$f['tariff'];}
    if(!empty($f['level'])){$where[]='s.level_id=?';$p[]=(int)$f['level'];}
    // "Who is in Monday's group" is the view she builds most often, so a course
    // is a filter in its own right rather than something to be read off a card.
    if(!empty($f['course'])){$where[]='EXISTS (SELECT 1 FROM class_students cs WHERE cs.student_id=s.id AND cs.class_id=? AND '.current_enrolment_sql().')';$p[]=(int)$f['course'];}
    // An age group is usually not stored on the student, so filtering by one has
    // to cover both the pinned case and the dates that fall into the band. The
    // bounds become dates once here rather than a function call per row: at
    // least min_age is born on or before the last day for that age, and at most
    // max_age is born after the last day for max_age+1. Both were a year or a
    // day out, and an 11–12 band found only twelve-year-olds.
    if(!empty($f['age_group'])){
        $band=one('SELECT * FROM age_groups WHERE id=?',[(int)$f['age_group']]);
        if($band){
            $youngest=latest_birth_date_for_age((int)$band['min_age']);
            $tooOld=$band['max_age']===null?null:latest_birth_date_for_age((int)$band['max_age']+1);
            $clause='s.age_group_id=? OR (s.age_group_id IS NULL AND s.birth_date IS NOT NULL AND s.birth_date<=?';
            array_push($p,(int)$band['id'],$youngest);
            if($tooOld!==null){$clause.=' AND s.birth_date>?';$p[]=$tooOld;}
            $where[]='('.$clause.'))';
        }
    }
    if(!empty($f['absence'])){$where[]='EXISTS (SELECT 1 FROM absences a WHERE a.student_id=s.id AND a.reason=? AND a.starts_on<=? AND a.ends_on>=?)';array_push($p,$f['absence'],today(),today());}
    if(!empty($f['overdue'])){$where[]='EXISTS (SELECT 1 FROM charges c WHERE c.student_id=s.id AND '.charge_is_overdue_sql().')';$p[]=today();}
    if(!empty($f['field']) && isset($f['value'])){
        $def=one('SELECT * FROM field_definitions WHERE id=? AND archived=0',[(int)$f['field']]);
        if($def && (is_staff() || $def['visibility']!=='internal')) {
            $where[]='EXISTS (SELECT 1 FROM field_values v WHERE v.student_id=s.id AND v.field_id=? AND JSON_CONTAINS(v.value_json,?))';
            array_push($p,$def['id'],json_encode($def['field_type']==='checkbox'?in_array($f['value'],['1','true','yes'],true):$f['value'],JSON_UNESCAPED_UNICODE));
        }
    }
    return rows('SELECT s.*,a.name AS account_name,l.name AS level_name FROM students s'
        .' LEFT JOIN accounts a ON a.id=s.account_id LEFT JOIN levels l ON l.id=s.level_id'
        .' WHERE '.implode(' AND ',$where).' ORDER BY s.last_name,s.first_name,s.id',$p);
}
/**
 * Every placeholder a message template may use, what it means, and an example.
 *
 * One list. template_values() fills them in, template_save() validates against
 * them, and the editor shows them beside the box they go into. The same set used
 * to be written out in three places, so adding one meant remembering all three
 * and a template could be accepted that the sender then could not fill in.
 */
function template_placeholders(): array {
    return [
        'student_name' => [t('Vollständiger Name','Full name'),                          'Lena Hofer'],
        'first_name'   => [t('Vorname','First name'),                                    'Lena'],
        'level'        => [t('Leistungsgruppe','Level'),                                 t('Anfänger','Beginner')],
        'age_group'    => [t('Altersgruppe','Age group'),                                t('Unter 12','Under 12')],
        'tariff'       => [t('Tarif','Tariff'),                                          t('Monatsbeitrag','Monthly fee')],
        'outstanding'  => [t('Offener Gesamtbetrag','Total outstanding'),                money(4500)],
        'paid_through' => [t('Ende des letzten bezahlten Zeitraums','End of the latest paid period'), fmt_date(today())],
        'portal_url'   => [t('Link zum Portal','Link to the portal'),                    url('messages')],
    ];
}

/** What each placeholder becomes for one student. Keys match template_placeholders(). */
function template_values(array $s): array {
    $paidThrough=null;
    foreach(student_charges((int)$s['id']) as $c) if(!$c['cancelled'] && $c['paid']>=$c['amount_cents'] && $c['period_to'] && (!$paidThrough || $c['period_to']>$paidThrough)) $paidThrough=$c['period_to'];
    return [
        'student_name' => $s['first_name'].' '.$s['last_name'],
        'first_name'   => $s['first_name'],
        'level'        => level_name(isset($s['level_id'])?(int)$s['level_id']:null),
        'age_group'    => age_group_name($s),
        'tariff'       => current_tariff_names((int)$s['id']),
        'outstanding'  => money(balance((int)$s['id'])),
        'paid_through' => fmt_date($paidThrough),
        'portal_url'   => url('messages'),
    ];
}

/**
 * The tariffs of the courses a child is in now, joined with „ + “, in the
 * order the courses are listed; '' for a child on none. The student's own
 * tariff column bills nobody any more, so it is not what a message may say.
 */
function current_tariff_names(int $studentId): string {
    return implode(' + ', array_column(rows('SELECT t.name FROM class_students cs JOIN tariffs t ON t.id=cs.tariff_id'
        .' JOIN classes c ON c.id=cs.class_id WHERE cs.student_id=? AND '.current_enrolment_sql()
        .' ORDER BY c.sort_order, c.name, c.id', [$studentId]), 'name'));
}

function template_text(string $text,array $s): string {
    $map=[];
    foreach(template_values($s) as $key=>$value) $map['{{'.$key.'}}']=$value;
    return strtr($text,$map);
}
