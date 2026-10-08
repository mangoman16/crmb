---
status: accepted
date: 2026-10-07
---

# 0026. Who the portal is for, the beta, and what it no longer carries

> **The owner's later answer on levels and age groups, 2026-10-07, 19:03 UTC.** Recorded on
> 2026-10-08. The owner: "Skill levels were good to have / age levels will also be needed, but it
> would be enough if the app can dynamically output in which age group one falls in". It reverses
> part of their first answer (the note of the owner's answers, below) and changes round 3. What it
> decides, as the project manager read it:
>
> - **Levels stay as they are:** the `levels` table, `students.level_id`, Verwaltung's
>   „Leistungsgruppen", `level_save`, the select on the child's page and in the list's filter,
>   and the seeding and the level backfill in `database/defaults.php`. A level is a group the
>   trainer chooses for a child, not the progress tracking that `ROADMAP.md` rules out: nothing
>   records more about it than the change log does.
> - **The age bands stay as lists the trainer edits:** `age_groups`, Verwaltung's „Altersgruppen",
>   `age_group_save` and the seeding.
> - **A child's band comes from the birth date only.** The pin goes: `students.age_group_id`
>   (008) and its key, the select „Altersgruppe festlegen" on the child's page, and the pinned
>   branch of `student_age_group()`. Round 3 has one migration,
>   `038_age_group_from_the_birth_date_only.sql`, one statement, as 024 is:
>   `ALTER TABLE students DROP FOREIGN KEY student_age_group, DROP COLUMN age_group_id;`. It lands
>   in the commit that stops the code reading and writing the column. 039 is not written.
> - **`app/groups.php` stays**, where it is in the load order, and nothing moves to `app/domain.php`.
> - **The students list** sorts „A–Z | Nach Alter", the youngest first under a header per band.
>   The filter keeps its Leistungsgruppe and Altersgruppe selects. „Alter von – bis" is not built:
>   the bands do its job, and the trainer edits them. `ui-ux-designer` revises Part 1 of
>   `docs/design/2026-10-07-ios-design-language-and-goal-screens.md`.
> - **The example data (§9)** stays as planned, except that its four children have ages in two
>   bands, so „Nach Alter" shows more than one header.
>
> **One rule decides a child's band:** the first band, in the order Verwaltung lists them, that
> covers the child's age today (`age_group_for_age()`, through `student_age_group()`). The child's
> page, the list's card and its headers, the filter and Verwaltung's counts all ask it, and no view
> works a band out for itself. Bands may still overlap or leave a gap, as today, and Verwaltung says
> so (`age_group_warnings()`). Two things follow:
>
> - **the filter keeps the children the rule puts in the band.** Today `filtered_students()` bounds
>   the birth date by the chosen band's own ages, in SQL: a second copy of the rule, which lists a
>   child under one band while the card names another wherever bands overlap. That copy goes with
>   the pin's half of the same clause;
> - **„Nach Alter" lists each child once, under the band the rule gives:** the bands in Verwaltung's
>   order, the youngest first within each, then the children no band covers, then those without a
>   birth date. For bands in age order that do not overlap, as the seeded ones are, that is simply
>   the youngest first.
>
> Rejected with it: removing the bands as "explicit groups", because the owner now asks for age
> groups; keeping the pin, which `student_age_group()`'s comment calls "auto detect, changeable",
> because the owner said working the band out is enough, and a child who trains with older ones is
> a matter of level or course, not of age; keeping the SQL bounds and refusing overlapping bands so
> the two agree, which takes away an arrangement the trainer may mean, to keep a copy nobody needs;
> and a header printed wherever the band changes down a list sorted by birth date alone, which
> shows a band twice when bands overlap.
>
> Lines marked *Later answer, 2026-10-07* say where this record said otherwise: in point 4 of the
> round-2 note, in the note of the owner's answers, and in §4, §8, §9, §10, §11, §12, Rejected,
> Consequences, "For the owner" and "In plain words". The notes on ADRs 0020 and 0021 gain a line
> each.
> Lines marked *Built 2026-10-08* say what building round 3 settled beyond the answer:
> `latest_birth_date_for_age()` went with the date bounds, and §9's dates, password and expiry.

> **Round 2 of the removals, 2026-10-07: the contact requests, the pictures' files, and the numbers
> of round 3's migrations.** Written with round 2's code (`ROADMAP.md`, "Now and next" 3), which
> removes presence (the dots, the chosen status and the online history), the status emoji, profile
> pictures, the contact requests, and new voice notes and files, with migrations 034 to 037.
>
> 1. **The old contact requests are deleted, with their table.** The project manager decided it on
>    2026-10-07, under §2: in the beta they are test data, and since 0022 §11.3's removal, which lands
>    in the same commit, nothing reads them. This answers "For the owner" 4, which had said nothing
>    would change until the owner said so. The answer is the project manager's, not the owner's.
>    `037_contact_requests_go.sql` is one `DROP TABLE IF EXISTS contact_requests;`, which can run
>    twice. The table was never in `schema_guarded_tables()`, so "No guarded table loses rows but
>    `field_values`" (Consequences) still holds. These parts no longer hold: in the note below, "and,
>    from 0022, the old contact requests (4)"; in §8's row for contact requests, "Nothing more to
>    remove" and "nothing more", since their table goes; in §11, "`contact_requests` stays, as 0022
>    left it to the owner"; in Rejected, "or `contact_requests`"; and "For the owner" 4. ADR 0022
>    carries a note of its own.
> 2. **Voice notes and files sent before stay**, their rows and their files, as 0022 §11.4 says. Only
>    new ones are refused. Deleting them would lower the rows of `message_files`, which is guarded,
>    and remove what somebody sent.
> 3. **The pictures' files go with the pictures.** §8's row names the columns, not the files. After a
>    run that has passed the guard, the runner's PHP step (`database/defaults.php`) deletes the
>    stored pictures, which no column names any more. The problem reports' screenshots, kept in the
>    same folder, stay. The copy the update takes first is of the database: it holds the pictures'
>    names, not the pictures, so once the step has run the portal cannot bring one back. That is the
>    intent: a photo of a child does not stay on the server once nothing shows it.
> 4. **Round 3's migrations are 038 and 039.** 037 took the number planned for levels. So
>    `037_students_without_level_or_age_group.sql` becomes
>    `038_students_without_level_or_age_group.sql`, and `038_levels_and_age_groups_go.sql` becomes
>    `039_levels_and_age_groups_go.sql`, each with the same statements. Wherever this record says 037
>    or 038 for levels and age groups (the note below, §8's row, and §11's table and bullets), read
>    038 and 039. §11 already says that the order is what matters.
>    *Later answer, 2026-10-07:* only 038 is written, and it drops one key and one column:
>    `038_age_group_from_the_birth_date_only.sql`. 039 is not written (the note above).
>
> Rejected with them:
>
> - asking the owner before deleting the requests, as "For the owner" 4 had promised: §2 gives schema
>   changes in the beta to the project manager, and the rows are test data;
> - keeping `contact_requests` unused, as 0022 did: a table nothing reads is one more thing to carry,
>   for a reversal nobody has asked for, and opening chats between students again needs code anyway;
> - deleting the voice notes and files sent before, for the reasons in 2;
> - leaving the pictures to the nightly prune, which runs only when the background work does;
> - naming the pictures before 035 drops their column, which needs a step between two migrations: a
>   second path the runner does not have.

> **The owner's answers, 2026-10-07.** Asked about the three removals marked † ("For the owner", 1),
> the owner answered:
>
> 1. Online dots, chosen status, status emoji and the 30 days of online history: "Remove all".
> 2. Levels and age groups: "trainer needs to sort them by age groups, if it is possible to do that
>    with only birth dates and without explicit groups then well do it".
>
> What they decide, the second as the project manager read it:
>
> - **The dots, the chosen status, the status emoji and the online history go**, as §8 proposed, with
>   the four settings of §10 and migrations 034 and 035. This record supersedes 0015.
> - **Levels go**, as §8 proposed. The owner did not ask to keep them.
>   *Later answer, 2026-10-07:* levels stay as they are (the note at the top).
> - **Configured age bands go:** the `age_groups` table, Verwaltung's „Altersgruppen" tab, and pinning
>   a child to a band with `students.age_group_id`. Migrations 037 and 038 stand as planned.
>   *Later answer, 2026-10-07:* the bands and their tab stay, as lists the trainer edits. Only the
>   pin goes, with 038, which drops `age_group_id` and its key; 039 is not written.
> - **Sorting by age stays, worked out from the birth date alone.** The students list can be sorted
>   by age, and filtered by an age range („Alter von – bis", in whole years). Both are new: today the
>   list sorts by last name only, and its age filter is a configured band. `student_age()` and
>   `latest_birth_date_for_age()` stay and move to `app/domain.php` (§12); the rest of
>   `app/groups.php` goes. No column is added: `students.birth_date` is all an age needs.
>   *Later answer, 2026-10-07:* the sort stays, as „A–Z | Nach Alter" under a header per band. The
>   range is not built, `app/groups.php` stays, and nothing moves to `app/domain.php`.
>   *Built 2026-10-08:* `latest_birth_date_for_age()` goes after all. The date bounds in
>   `filtered_students()` were its only caller, and they went with the pin; `student_age()` stays.
> - **How the age shows on the list** is `ui-ux-designer`'s to specify. It may group the list under
>   each age, if that reads better on a phone. Then `backend-dev` builds the sort and the range,
>   `frontend-dev` the filter and the list, `qa-tester` keeps the `groups` suite's cases for the two
>   functions and adds the sort and the range, and `mobile-tester` measures the list.
>   *Later answer, 2026-10-07:* a header per band. `backend-dev` builds the sort and the one rule,
>   and `qa-tester` keeps the `groups` suite, less its cases for the pin, and adds the sort and a case
>   where the filter and the card agree on overlapping bands.
>   *Built 2026-10-08:* the `groups` suite also loses `latest_birth_date_for_age()`'s cases.
>
> Rejected with the second answer: keeping the bands as lists the trainer edits, because the owner
> said "without explicit groups"; and a fixed set of bands in code, such as U11 and U13, which are
> explicit groups too, and club-specific code besides (§6).
> *Later answer, 2026-10-07:* the first of these no longer holds, because the bands stay as lists
> the trainer edits. The second still does.
>
> §8, §10, §11, §12, "For the owner" and "In plain words" carry lines dated 2026-10-07 that say so.
> Wherever else this record says "with the owner's word" or "once the owner agrees", the word has been
> given. ADR 0021 gains a note too, for its line about `level_default()`. Nothing here waits for the
> owner any more but the privacy notice ("For the owner", 2), and, from 0022, the old contact
> requests (4).
> *Later answer, 2026-10-07:* the note on ADR 0021 no longer holds, and now says so.

> **Accepted** on 2026-10-07. The owner set the direction in their own words (Context) and handed the
> planning to the project manager. This record amends ADRs 0011, 0016, 0018, 0020, 0022, 0023 and
> 0025, and supersedes 0017. Three of the removals take away things somebody asked for by name (†).
> By §2 they wait for the owner's word, and "For the owner" asks for it; with it, this record
> supersedes 0015 too. Each record named carries a note saying which of its parts no longer hold.

## Context

### What the owner said

The owner, on 2026-10-07:

> "remove the "custom field" in the settings, the whole app will be designed and configured by claude
> / a core design concept that may need to change / most if not all of the users will work on phone,
> so we need the website to work perfectly on phoney / most of these users are not technical, admin,
> who is me, will make the code with you / the trainer and students want an intuitive interface, they
> are non techcnical, and most of them wont be really liking reading too much, with wizards and
> everything that make following steps easy, with visual guidances / we need this software to be
> robust, avoid errors and code as much as needed to make sure stuff run as expected, expect all kinds
> of unexpected behaviours and values by users and trainers / whole goal for students is: easy roll in
> in courses, for beginning it probably will be only 1 course / manage payments and pay for the course
> and see reciepts and open payments and paid payments / report sick, get news from trainer for
> changes / for the trainer, she wants management burden not to be here anymore, she is not technical
> and wants smooth roll in, ways to add students to courses, student payments to be easier managed,
> tell them if changes happen to the courses or even if she is sick, communicate, help them and give
> feedback, have a list which students were in a course and which not"

Their answers to the project manager's questions:

1. **Custom-field data:** delete it. Both tables go.
2. **Settings:** "i like the settings to be comprehensive, remove nothing from settings, i want a good
   setting, and the setting should allow customization and also in best case this should work for
   other people too imagine this is a software you would sell, but custom fields being able to add
   them is not needed".
3. **Paying** stays bank transfer, the EPC QR code, and a photo of the transfer receipt that the
   trainer ticks off. No online payment.
4. **Feedback** from the trainer to a student is the chat. Nothing new is built for it.

And then:

> "The software is now in beta, dont worry about about db changes or bigger changes. as explained, we
> want it to be intuitive, secure, reliable, robust, easy to use for users and trainers. software is
> in testing, i am unsure about most of the features, and i want you to handle the thinking and
> planning, whenever you need my opinion you can ask me, if i have to test anything or deploy
> anything, tell me. also stripe down the demo data or stuff that are not needed anymore but are too
> much to maintain."

The working rule they gave: the best code is the code never written. Deletion over addition, reuse
what exists, no abstractions nobody asked for, no new dependency, the fewest files, and the shortest
working change once the problem is understood.

### What the code is today

Read from the code on 2026-10-07, not assumed:

- **Size.** `app/` is 15,400 lines in 38 files, `views/` 3,840 in 38, `app.js` 282 and `app.css`
  1,330. `tests/` is 19,122 lines in 52 files. ADR 0002's figures are from 2026-09-22.
- **Actions.** There are 78 `case`s: 23 in `app/actions.php`, 28 in `actions_config.php`, 20 in
  `actions_settings.php` and 7 in `actions_messages.php`. There are 76 once 0022 §11 removes
  `contact_request` and `contact_decide`.
- **No undo.** `revert_version()` is gone, and `tests/suites/history.php` asserts it. The change log
  (`views/history.php`) only reads, and only administrators open it.
- **Custom fields** are `field_definitions` and `field_values` (001). `field_values` is in
  `schema_guarded_tables()`. A student's values are part of their change-log lines as `field:<id>`
  pseudo-columns (0020 §7). A family edits the fields at `'edit'`, and `family_next_steps()` lists
  the required ones.
- **The update guard misses a dropped table.** `schema_counts()` skips a table it cannot count, and
  `schema_verify_counts()` compares only the tables it counted afterwards (`app/schema.php`). A
  migration that drops a guarded table, rows and all, therefore passes.
- **Billing reads no level and no age group** (`app/billing.php`). They label children in lists,
  filters and message templates.
- **A sick report, a receipt and a confirmed payment tell nobody.** `absence_add`, `proof_upload` and
  `confirm_payment()` put nothing in anybody's bell. A course request, a chat message and a family's
  first set-up do.
- **The receipt upload names no charge.** The form on the Beiträge tab posts only `student_id`
  (`views/student.php`), though `proof_upload` accepts a `charge_id`.
- **Unexpected input.** Values are read through `post()`, which refuses an array, and the helpers in
  `app/core.php` and `app/validate.php`. Gaps found while reading:
  - `sort_order` is cast with `(int)` and written unbounded to an `INT` column, in `class_save`,
    `tariff_save`, `level_save` and `age_group_save`. In MariaDB's default strict mode, `2147483648`
    is an out-of-range error, which the router records as an unexpected error and answers with
    „Speichern fehlgeschlagen";
  - views cast query values without checking them. `views/student.php` reads
    `(string)($_GET['tab']??'details')`, and `?tab[]=x` is a PHP warning;
  - `proof_upload` checks that a charge belongs to the child, but not an invoice.
- **Club-specific text.** What a family receives says "Badminton" where it should name the club: the
  invitation's subject and fallback text (`app/auth.php`), the new-message mail and the payment
  reminder (`app/mail.php`).

## Decision

### 1. Who the portal is for

- **The owner** is the administrator. They build the portal with Claude, test what they are told to
  test, and deploy by upload. They have no shell. Their pronouns are not stated, so this record says
  "they".
- **The trainer** runs the courses. She is not technical and works on her phone.
- **Students and their families** are not technical, use phones and read little.

Earlier records and `CLAUDE.md` call the owner "she" and mix the owner up with the trainer. The
records are history and are not rewritten for that. `CLAUDE.md` is: the list for `docs-writer` is under
Consequences.

### 2. The beta

The portal is in beta. No real family's data is in it: whatever an install holds is test data.

- **What changes.** Schema changes and larger changes no longer wait for the owner's yes. The project
  manager decides them, then tells the owner in plain steps what to test and what to deploy.
- **What still needs the owner:** a choice that is ambiguous, costs money, reverses something the
  owner asked for, or decides how families' data will be handled once real families arrive.
- **What does not change**, because each rule protects any install, a test one included:
  - migrations are append-only, and the ledger refuses an edited one (0004);
  - an update refuses rather than guesses: older files, an incomplete upload, a database it could not
    back up first, or fewer rows in a guarded table (0004);
  - a backup before every update;
  - security: the CSRF token, the throttles, authorisation in every action, the upload checks, and no
    secret in git;
  - parameterised SQL, and `sql_name()` for every identifier;
  - `e()` on every printed value;
  - money in integer cents, timestamps in UTC;
  - every text through `t()`, German first;
  - views read and actions write, inside `transactional()` (0003); every page works without
    JavaScript (0002); no dependency without a record.
- **When it ends.** When the owner says real families are coming, the project manager writes the
  record that ends the beta. Three things are decided again then: the stop-and-ask rule for schema
  changes; the problem reports' trail of typed form values (0009), which is test data now; and the
  privacy notice, which the owner releases.

### 3. The goals are the scope test

A feature stays if it serves one of the goals below, or if the portal cannot be installed, updated,
secured or run without it. Otherwise, or when it costs far more to keep than it gives, it goes (§8).

**Students and families**

| The owner's words | What exists | Gap |
| --- | --- | --- |
| "easy roll in in courses, for beginning it probably will be only 1 course" | The invitation by address (`email_invite`, 0021 §3) or the trainer's wizard (`student_new`, 0023 §5); the activation page; „Kurs wählen" on the family's „Noch zu ergänzen" card (`family_next_steps()`); „Anmeldung anfragen" (`enrolment_request`); the trainer's „Annehmen" (`enrolment_decide`). | **G1.** Four places, the link, the password, the own page and the Kurse tab, and nothing says how many steps are left. With one course, choosing is a yes. A first-sign-in wizard (§4). |
| "manage payments and pay for the course" | The Beiträge tab: every charge and its state; for an open one the EPC QR code, IBAN and reference (`qr_payload()`, `qr_svg()`); „Beleg hochladen" (`proof_upload`), offered on the overview under the amount. | **G2.** The upload names no charge (Context). One flow per charge: the amount and the code, then „Überwiesen" with the photo of the receipt. |
| "see reciepts and open payments and paid payments" | Bezahlt, offen, überfällig and Storniert on each charge; the Rechnungen tab with each invoice's PDF. | **G3.** Open and paid are one list in due-date order. A paid charge has a document only when staff issued an invoice. Nothing tells a family that a payment was confirmed. |
| "report sick" | The Abwesenheit tab: reason, from, to (`absence_add`). The trainer sees it beside the name when she takes attendance, and in „Heute abwesend". | **G4.** Three fields on the sixth tab, and the trainer gets no notice. „Heute krank" in one tap from the overview, with a notice to the trainer. |
| "get news from trainer for changes" | Neuigkeiten, and the latest three on the overview; the news mail when ticked (`news_save`); a moved or cancelled date tells the course's families in the bell, and by mail when ticked (`class_session_save`); the course group. | **G5.** A news item puts nothing in the bell. |

**The trainer**

| The owner's words | What exists | Gap |
| --- | --- | --- |
| "management burden not to be here anymore" | Monthly charges made automatically (`tick_billing()`); one reminder to every overdue family (`payment_remind`); the start checklist; one quick action. | none of its own |
| "smooth roll in, ways to add students to courses" | The wizard with course and tariff (0023); the invitation by address (0021); adding a member on the course page (`class_member_add`); answering requests; „Wieder aufnehmen" (0024). | none: 0023 and 0024 are being built |
| "student payments to be easier managed" | Geld: the month's charges previewed and made, automatically or by hand; the open list with „Nur überfällig"; „Alle überfälligen per E-Mail erinnern". On each child: record, confirm, void, cancel, invoice. | **G6.** Receipts are found only on each child's tab, and ticking one off there takes three steps: „Zahlung erfassen", tick „Zahlungseingang bestätigen", save. The owner's answer 3 is this step: one list of receipts waiting, each one tap to paid. |
| "tell them if changes happen to the courses or even if she is sick" | Each date of each course can be moved or cancelled, with the families told (`class_session_save`); news; the group chat. | **G7.** Ill for a day means a course's Termine tab, once per course. „Heute fällt aus" on today's row of the overview, for every course she has that day. |
| "communicate, help them and give feedback" | The chat with each family and each course group (0022, text and photos since §11); news. Feedback is the chat (answer 4). | none |
| "have a list which students were in a course and which not" | Attendance per training day (`views/_class_attendance.php`), with reported absences beside the names, and the earlier days; each child's rate on their page. | **G8.** No view of one course over a term. The designer decides whether the day list is enough. |

The gaps go to `ui-ux-designer`, who specifies each screen before anything is built. None needs a
dependency. Each is its own change.

### 4. Phones first, and a wizard for anything with steps

- **Measured, not looked at.** Every screen is measured at 320 and 390 px, as staff and as a family,
  light and dark, by `tests/mobile.mjs`: nothing wider than the screen, 44 pt targets, text of at
  least 12 px. Its page list follows the pages: `compose`, `manage&tab=ages`, `manage&tab=tariffs` and
  `settings&tab=fields` leave it, and `student_new` and every new wizard step join it.
  *Later answer, 2026-10-07:* `manage&tab=ages` stays in it, because the bands stay.
- **A task with several steps is a wizard**, built the way „Schüler anlegen" is (0023 §5), and no
  other way:
  - one question per step, with „Schritt 2 von 3" and a way back;
  - each step a page that only reads, with one form that posts one action;
  - what earlier steps chose is kept in the session under a random key, never in the address and
    never as half-made rows;
  - a refused step comes back to itself, with what was typed;
  - it works without JavaScript.
- **Which tasks.** Adding a student (built), a family's first sign-in through to asking for the
  course (G1), and paying a charge with its receipt (G2). Not a wizard: the start checklist, because
  setting up is not linear (0011); reporting sick and ticking off a receipt, which are one tap each.
- **Visual guidance.** A button or menu entry that starts a task has an icon from `icon()` beside a
  short word. An explanation is one sentence. What is left to do is a numbered card
  (`next_steps_card()`), never a paragraph.

### 5. Any value from anyone

**The rule.** Every value that comes with a request is checked where it enters the action, before
the first write, through the helpers that exist: `post()`, `required_text()`, `text_limit()`,
`date_value()`, `birth_date_value()`, `cents()`, `choose()`, `email_value()`, `username_value()`,
`reference_or_null()`, and for a record named by id the scoped readers `student()`, `invoice()`,
`training_class()` and `thread_record()`. That covers a missing field, an array where a string belongs,
a huge string, a negative or huge number, an impossible date, somebody else's id, a form sent twice
and a page brought back by the back button.

- **A refusal is one plain sentence** (`UserError`) on the page the form came from, with what was
  typed kept. Never a 500, a blank page or a PHP warning. A database error is never the answer a person
  reads.
- **Two gaps get one helper each**, beside the ones there are:
  - `whole_number_value(string $value, int $min, int $max): int` in `app/validate.php`, for every
    number that is not an id: `sort_order`, `capacity`, `due_day`, `grace_days`, `port`, `terms`. It
    replaces the bare `(int)` casts and the range checks each action spells for itself;
  - `query_text(string $key, string $default = ''): string` in `app/core.php`, beside `post()`, for
    every value a view reads from the address. A value that is not a string reads as absent. Views
    stop casting `$_GET` themselves.
- **Ids that belong together are checked together.** `proof_upload` checks its invoice against the
  child, as it checks its charge.

**The suite: `tests/suites/robustness.php`**, written by `qa-tester`.

*How it finds every action.* From the dispatch lists, never from a list of its own:

- every `case '…':` in every file matched by `app/actions*.php`, with the pattern the `structure`
  suite uses;
- it fails when it finds fewer than 60, when an `app/actions*.php` file is missing from the files
  `public/index.php` or `test_load_actions()` require, or when the actions it found are not exactly
  those `start_form()` offers;
- the fields it posts are every key the application reads from a request: the first argument of
  `post()`, `required_text()` and `text_limit()`, the field of `reference_or_null()`, and every
  `$_POST['…']` and `$_FILES['…']` in `app/`. It fails when it finds fewer than 100.

The one list kept by hand names the actions whose success changes who is signed in: `login`,
`logout`, `activate`, `impersonate` and `password_change`. After one of them the role is signed in
again. A sixth needs its reason written beside it.

*As whom.* Signed out; a family, as an active student login with their own child, a course, a charge,
an invoice, a receipt, an absence, a contact and a chat; a trainer; an administrator; and an
administrator viewing the portal as that family. A second family supplies other people's ids.

*With what.* Each class of value is posted to every field at once. Then a fixed number of seeded runs
draw each field from all the classes, and a failure prints its seed.

1. nothing but the token and the request id;
2. an array instead of a string, and an array of arrays;
3. 100,000 characters, and 4,000 multibyte ones;
4. `-1`, `0`, `2147483648`, `99999999999999999999`, `1.5`, `1e9`, ` 7 `;
5. `2026-02-30`, `0000-00-00`, `9999-12-31`, `31.12.2026`;
6. an empty string, spaces, `<script>`, a quote followed by `--`, a NUL byte, invalid UTF-8, an emoji,
   and a line break followed by `Bcc:`;
7. the ids of the other family's child, contact, absence, charge, payment, invoice, receipt, chat and
   message, and ids that do not exist;
8. a plausible form posted twice with the same request id.

*How it runs.* In process, through `submit()`, so the token, the throttles and the request-id claim
run as they do in the browser. Each call runs inside an outer transaction that is rolled back after
it. The rate limits, which live on their own connection, are emptied before each call. The session is
set to the role. Mail is only queued. Signed-out posts run out of process, through a helper like
`tests/setup-request.php` that requires `public/index.php`: in process, `require_user()`'s redirect
would end the run. Signed out, each action gets classes 1 and 2 only.

*It fails on:*

- any throwable but a `UserError` (a `NotFound` included): a `PDOException`, `TypeError`,
  `ValueError`, `ArgumentCountError`, `DivisionByZeroError`, `JsonException`, `ErrorException`,
  `LogicException` or `RuntimeException`;
- any PHP warning, notice or deprecation, turned into a failure as `render_view()` does;
- a refusal that is not one plain sentence: empty, longer than 300 characters, containing `SQLSTATE`,
  `PDO`, `.php`, `Exception` or `#0`, or repeating more than 60 characters of anything posted;
- a return that is not a page the router allows, with scalar parameters;
- a transaction left open after the call;
- with class 7, as the family or the family's view: any change to the other family's rows;
- with class 8: a second post answered with anything but „Diese Eingabe wurde bereits verarbeitet";
- signed out: a status of 500 or more, a body containing `Warning:`, `Notice:` or `Deprecated:`, or
  anything but a redirect to the sign-in page or back to a public page;
- the run ending inside an action. `go()` exits, so a shutdown function prints the action and the
  role and exits with 1. Without it, a stray exit would end the run looking green.

*The pages too.* Every page the router allows is drawn through `render_page()` as each role, with
classes 2, 3, 4 and 7 in its query string. It fails on a throwable that is not a `UserError`, and on
any warning.

*Proof that it can fail.* On today's code it should fail on `sort_order` at `2147483648` and on
`?tab[]=x` (Context). It lands together with the fixes it forces, so the suite stays green, and runs
in under a minute in `tests/mariadb-local.sh`.

### 6. A product another club could run

- **Settings stay comprehensive.** Every setting in `app/defaults.php` stays, except the four that
  exist only for a feature that goes (§10).
- **Nothing club-specific in code.** The club's name, address, bank details, colours, logo, icon,
  statuses, absence reasons, attendance entries, payment methods and invoice wording are settings
  already. What a family receives names the club (`club_name`), never the sport or the product: the
  invitation's subject and fallback text, the new-message mail and the payment reminder change.
  Product names that only an administrator sees stay: the set-up page, the SMTP test, the backups, the
  cookie and lock names.
- **A known limit, written down rather than built.** The invoice rules are Austria's: § 11 UStG's
  address above 400 € (`create_invoice()`), and the small-business note as a default. A club
  elsewhere needs its rules decided first, and nobody has asked.
- **No user-defined schema.** A field the trainer needs becomes a real column with a `DEFAULT`, written
  by the owner and Claude: a migration, its label through `t()`, its check in the action and its place
  on the page.

### 7. Custom fields go, with their data

The owner: "remove the "custom field" in the settings". Their data is deleted, and both tables go.

- **Migration `032_custom_fields_go.sql`**, two statements, each of which can run twice:

  ```sql
  DROP TABLE IF EXISTS field_values;
  DROP TABLE IF EXISTS field_definitions;
  ```

  `field_values` comes first, because its key to `field_definitions` is `RESTRICT`. A run that stops
  between the two starts again from the first, which then does nothing.
- **The guard, in the same commit.**
  - `field_values` leaves `schema_guarded_tables()`, with a comment saying why: 032 deletes its rows
    on purpose. `field_definitions` was never guarded.
  - `schema_verify_counts()` learns to see a dropped table. It compares every table counted before the
    update, and a table it can no longer count counts as 0. Without that, 032 would pass the guard
    whatever the list said, and so would a mistaken `DROP` of any guarded table. A case in the
    migrations suite drops a guarded table and expects the portal to stay closed.
- **The change log.** Lines written before keep their `field:<id>` keys.
  - `history_field_label()` names every `field:<id>` „Früheres eigenes Feld" / "Former custom field",
    with no query: the table it read is gone.
  - `history_value()` keeps reading such a value as text, which needs no query.
  - `entity_snapshot()` loses its `field_values` loop, and `tracked_entities()` loses
    `field_definitions`.
  - `entity_label()` names an entity that is no longer in `tracked_entities()` by its stored name
    instead of throwing, so the change log outlives any feature that goes.
  - There is no revert to teach about these keys: `revert_version()` is already gone.
- **Every use goes:**
  - in Einstellungen, the „fields" tab of `views/settings.php`, its mention in the hub's „Erweitert",
    and the link in `views/_settings_registry.php`;
  - the child's „Weitere Angaben" in `views/student.php`;
  - `views/print.php`, which goes whole (§8);
  - the filter's „Eigenes Feld" in `render_filters()` (`app/ui.php`), and the `field` and `value` keys
    of `filters_from()` and `filtered_students()`;
  - in `app/domain.php`: `field_definitions()`, `field_label()`, `field_value()`,
    `custom_value_empty()`, `custom_field_required_of()`, `validate_custom()` and
    `save_custom_fields()`, and the custom-field loop of `family_next_steps()` with its sentence in the
    docblock;
  - both calls to `save_custom_fields()` in `student_save`, and the comments that name custom fields;
  - `field_save`, and `field_definitions` in `record_duplicate`'s administrator check
    (`app/actions_settings.php`);
  - `app/duplicate.php`'s two entries, with the file (§8);
  - the two tables in `bin/console.php check`'s row counts;
  - the `data-field-*` block in `app.js`;
  - the custom-field comment in `database/defaults.php`;
  - in the tests: the custom-field cases in `selfservice.php`, `history.php`, `pages.php`, `forms.php`
    and `structure.php`, line 23 of `install.php`, the link check in `brand_pages.php`,
    `settings&tab=fields` in `tests/mobile.mjs`, and the field steps in `tests/integration.py`.
- **Records.** 0020 §7's custom-field parts, 0023 §5's "custom fields at `'edit'`" and 0011's
  „Eigene Felder" in „Erweitert" no longer hold.

### 8. What goes, and what stays

Line counts are rough: what the files hold today. Rows marked † wait for the owner's word (§2, and
"For the owner").

*Decided 2026-10-07:* the owner has given it (the note under the title). The rows for the dots and
the emoji stand as written; the row for levels and age groups keeps the age, as its cells now say.

*Later answer, 2026-10-07:* the row for levels and age groups becomes **keep**, but for the pin.
What goes is `students.age_group_id` and its key (038), the select „Altersgruppe festlegen", the
pinned branch of `student_age_group()` and the date bounds in `filtered_students()`; the list
gains „A–Z | Nach Alter" (the note at the top). Its tests stay, less the pin's cases, and so do
most of its lines.
*Built 2026-10-08:* `latest_birth_date_for_age()` goes too: the date bounds were its only caller.
`student_age()` stays, and the `groups` cases for the function that went go with it.

| Feature | Verdict | Why | What goes | Lines | Tests |
| --- | --- | --- | --- | --- | --- |
| Custom fields | **remove** | The owner's decision. | §7 | ~190 | `selfservice`, `history`, `pages`, `forms`, `structure`, `install`, `brand_pages`; `mobile.mjs`, `integration.py` |
| Example data (`app/demo.php`, `demo_data`, `demo:fill`) | **strip down** | Needed to show every screen. Most of it is billing edge cases that the billing suites cover with their own fixtures. | §9 | ~170 | `demo` |
| Copying records (`app/duplicate.php`, `record_duplicate`, „Kopieren" on eight lists) | **remove** | The lists hold one or two rows in the beta. `insert_row()` is the one insert in `app/` whose column names come from a row. | the file, the case, every `duplicate_button()`, the bootstrap comment | ~185 | `history`, `enrolment`, `messaging`, `structure` |
| Saved views (`filter_save`, `filter_delete`, `saved_filters`, `filter_summary()`) | **remove** | A handful of children. The chips „Überfällige Beiträge" and „Aktuell krank" stay. The filter's „Tarif und eigene Felder" fold goes with them. | the cases, the views' chips and form on the students page, `filter_summary()`; the table (033) | ~50 | `views`, `pages` |
| Writing to many (`bulk_preview`, `bulk_send`, `views/compose.php`) | **remove** | The course group, news, `payment_remind` and the direct chat cover every case. | the page, the cases, `template_placeholders()`, `template_values()`, `template_text()`, `current_tariff_names()`, „An mehrere schreiben", „Auswahl anschreiben" and „Zahlungserinnerung schreiben", the chip under Nachrichten, the select-all in `app.js` | ~140 | `accounts`, `messaging`, `pages`, `structure`; `mobile.mjs`, `integration.py` |
| Message templates (`template_save`, Verwaltung → „E-Mail-Vorlagen") | **remove**, with writing to many | Only the composer reads them. | the case, the tab, the two seeded templates, the placeholder buttons in `app.js`, the change-log entity; the table (033) | ~45 | `pages`, `structure` |
| Change log (`app/history.php`, `views/history.php`) | **keep** | It reads, it is for administrators, and it has no undo. It is the record of what an IBAN was (0025) and of what a family changed (0020 §7). | only §7's changes | none | `history` |
| Outbox and `mail_retry` | **keep** | Where an invitation is seen to have gone, and the way back from a failed send. | nothing | none | none |
| `mail_run`, „Warteschlange senden" | **remove** | The background work sends mail after page views, or a cron job does. It is the one action whose result the router finishes outside the dispatcher (`process`). | the case, the button, the router's branch | ~15 | none found |
| Online dots, chosen status and online history (`app/presence.php`, `presence_save`, migration 020) † | **remove**; decided 2026-10-07: "Remove all" | No goal needs it. It keeps 30 days of when each child was online, with four settings, a nightly prune and a sentence in the privacy notice. On 2026-10-05 the owner asked for a chat that is "the absolute basics". | the file, the case, the `presence_*()` functions in `app/ui.php`, the account menu's status, the lines on Konten and on the access card, the dots in the chat, `presence_touch()` in the router, the prune, four settings (§10), the CSS; `online_periods`, `accounts.presence` and `accounts.last_seen_at` (034, 035) | ~650 | `presence`, `presence_pages`, `messaging`, `shell`, `structure`, `migrations`, `history`, `errors` |
| Status emoji (`status_emoji_save`, migration 027) † | **remove**; decided 2026-10-07: "Remove all" | Decoration, in a chat cut to the basics. | the case, `status_emojis()`, `status_emoji()`, `status_emoji_mark()`, the menu's grid; `accounts.status_emoji` (035) | in the row above | `messaging`, `shell` |
| Profile pictures (`avatar_save`, ADR 0017) | **remove** | Photos of children that the club does not need. Initials already stand in everywhere. The upload kind the problem report's screenshot uses stays. | the case, the picture cards on Mein Konto and on the child's page, the photo branch of `avatar()`, `may_see_account_picture()`, `avatar_for_download()`, `avatar_cache_control()`, the download route's branch, the CSS; `avatar_name` on both tables (035, 036) | ~160 | `security`, `shell`, `uploads`, `views` |
| Maintenance switch | **keep** | Without a shell, it is how the owner holds an update's migrations back until the upload is complete: they wait while it is on. | nothing | none | none |
| Automatic charges | **keep** | The trainer's largest relief, and the checklist's step 6; the end-to-end walk switches it on. | nothing | none | none |
| Problem reporter, its trail and automatic errors (0009, 0012) | **keep** for the beta | How the testers report. Decided again when the beta ends, because the trail holds what was typed (§2). | nothing | none | none |
| Printed data sheet and blank form (`views/print.php`) | **remove** | Paper is what the wizard and the family's own set-up replace. | the view, `print_field()`, `print_tick()`, `print_signature()`, the print CSS, the router's entries, the button on the child's page, the link in the wizard | ~280 | `pages`, `structure`, `shell` |
| Levels and age groups (`app/groups.php`, `level_save`, `age_group_save`) † | **remove**, except the age; decided 2026-10-07 | Billing reads neither, and one course needs neither. A child's age still shows, from the birth date. *2026-10-07:* the trainer sorts children by age, so the students list sorts by age and filters by an age range, from the birth date alone. | the file except `student_age()`, the two cases, the two Verwaltung tabs and their part of „Wer gehört wohin?", the selects in „Einteilung", the line on the student card, the filters, the seeds and the level backfill in `database/defaults.php`; the columns and tables (037, 038). *2026-10-07:* not `latest_birth_date_for_age()`, which stays with `student_age()`; the level and age-group filters give way to „Alter von – bis" and a sort by age (§12) | ~300 | `groups`, `pages`, `views`, `demo`, `structure`; the `groups` cases for the two functions that stay are kept |
| "Reports" | **keep** | In this code they are the problem reports above. There is no statistics feature. | nothing | none | none |
| Verwaltung's „Tarife" tab | **remove** | It only points to the courses. Each course's own tab already lists the tariffs left without a course. | the tab | ~15 | none found |
| Contact requests, voice notes, chats between students | going under 0022 §11 | Nothing more to remove. Text, photos, groups, `message_remove` and „Alle Einzelchats" stay. | nothing more | none | none |
| Logins, the wizard and sign-in links (0023); removal and rejoining (0024); the record of payment-detail changes (0025) | **keep** | The way in, the way back, and the record of an IBAN change. Only 0023's lines about custom fields, the printed sheet, „An mehrere schreiben" and presence, and 0025's copying, go. | nothing more | none | none |
| Personal colour (Mein Konto → Farbe) | **keep** | Decided by 0013, and small. | nothing | none | none |

In all, roughly 2,200 lines of `app/`, `views/`, `app.js` and `app.css` go, about a tenth, and about
1,300 lines of tests. About 1,000 of those 2,200 are in the rows marked †.

### 9. Example data, stripped down

Today `demo_fill()` writes 15 children, 3 courses, 3 tariffs with 7 prices and 4 discount templates,
four weeks of random attendance in every course, 2 absences, 2 news items and a chat. Its job now is
to show every screen to the owner and to the phone sweep; the end-to-end walk builds its own data. So
it writes the following, all of it flagged `is_demo`, and all of it fixed, with no `random_int()`:

- **Logins:** the trainer, `trainerin@beispiel.test`, and two families, `lena.hofer@beispiel.test` and
  `jonas.berger@beispiel.test`, which `tests/mobile.mjs` and the demo suite use. One password, shown
  once, as today.
- **One course,** „Kindertraining", with one training day a week, one monthly tariff at one price, and
  its group chat with the trainer's welcome.
- **Four children**, each with one emergency contact:
  - Lena Hofer, the first family login: in the course; last month paid and confirmed; this month open
    and not yet due, so the QR code and „Beleg hochladen" show;
  - Jonas Berger, the second family login: not in the course, with a request to join waiting, so the
    trainer's „Anfragen" holds one;
  - Mia Gruber, without a login: in the course, with last month overdue, and reported sick from today
    for three days;
  - Elias Wagner, without a login: in the course, with this month's payment recorded and not yet
    confirmed.
- **Attendance** on the course's last two training days: everybody present, except Elias, absent on
  the latest.
- **One news item**, published.
- **One chat**, between Lena's family and the trainer, with two messages.

What goes: two courses and two tariffs, the extra prices and the discount templates, eleven children,
the second course membership, the random attendance, the second absence, the draft news item, and the
ages from 7 to 41.

*Later answer, 2026-10-07:* the four children have birth dates in two bands, so „Nach Alter" shows
more than one header.
*Built 2026-10-08:* fixed, except the password: `demo_password()` draws eight syllables at random,
four made-up words that a phone's letter keyboard types, shown once as before; `random_int()` is used
for nothing else. Every date, the birth dates included, is counted back from the day the data is
written, so it says the same on any day and no child's band changes for most of a year. The example
logins sign in for 14 days from the fill (`DEMO_LOGIN_DAYS`) and are refused at sign-in afterwards,
which is the expiry `ROADMAP.md`'s security batch asked for; removing the example data and filling
it again gives fresh ones.

`tests/suites/demo.php` keeps its checks on the password, the flags, clearing and the chats. Its
"cases that go wrong" become the ones above.

### 10. Settings that go with removed features

The owner said "remove nothing from settings". These go only because nothing they configure would be
left, and only with the dots, which wait for the owner's word (†). They are listed so the owner sees
each one:

| Where | Setting | What it configured |
| --- | --- | --- |
| Einstellungen → Portal | `online_window_minutes`, „Grün – „online“: aktiv innerhalb von (Minuten)" | the green dot |
| Einstellungen → Portal → Erweitert | `presence_recent_minutes`, „Blau – „vor Kurzem online“" | the blue dot |
| Einstellungen → Portal → Erweitert | `presence_away_hours`, „Gelb – „abwesend“" | the yellow dot |
| Einstellungen → System → Erweitert | `presence_history_days`, „Wann jemand online war, aufbewahren (Tage)" | the online history |

The constant `PRESENCE_HISTORY_MAX_DAYS` goes with them. `upload_max_kb` stays; its hint stops naming
profile pictures.

*Decided 2026-10-07:* the dots go ("Remove all"), so all four settings go, and the constant with them.

Not settings, but gone from the screens where things are configured:

- Einstellungen → „Eigene Felder für Schüler" (§7);
- Verwaltung → „E-Mail-Vorlagen", and the „Tarife" tab, which only pointed to the courses;
- Verwaltung → „Leistungsgruppen" and „Altersgruppen" (†, decided 2026-10-07; the students list sorts and filters by age instead);
  *Later answer, 2026-10-07:* both stay; what goes is „Altersgruppe festlegen" on the child's page.
- Mein Konto → „Bild", and the account menu's „Status" and „Status-Emoji" (the last two †, decided 2026-10-07).

### 11. Migrations, in order

Each lands in the commit that stops the code reading what it drops. The numbers are the next free ones
when each lands; the order is what matters.

| File | Statements | Lands with |
| --- | --- | --- |
| `032_custom_fields_go.sql` | `DROP TABLE IF EXISTS field_values;` `DROP TABLE IF EXISTS field_definitions;` | custom fields (§7) |
| `033_saved_views_and_message_templates_go.sql` | `DROP TABLE IF EXISTS saved_filters;` `DROP TABLE IF EXISTS message_templates;` | writing to many |
| `034_online_history_goes.sql` | `DROP TABLE IF EXISTS online_periods;` | the dots, status and emoji †, decided 2026-10-07 |
| `035_accounts_without_presence_or_picture.sql` | `ALTER TABLE accounts DROP INDEX account_seen, DROP COLUMN last_seen_at, DROP COLUMN presence, DROP COLUMN status_emoji, DROP COLUMN avatar_name;` | the dots, status and emoji †, decided 2026-10-07, with the pictures |
| `036_students_without_picture.sql` | `ALTER TABLE students DROP COLUMN avatar_name;` | the pictures |
| `037_students_without_level_or_age_group.sql` | `ALTER TABLE students DROP FOREIGN KEY student_level, DROP FOREIGN KEY student_age_group, DROP COLUMN level_id, DROP COLUMN age_group_id;` | levels and age groups †, decided 2026-10-07 |
| `038_levels_and_age_groups_go.sql` | `DROP TABLE IF EXISTS levels;` `DROP TABLE IF EXISTS age_groups;` | levels and age groups †, decided 2026-10-07 |

*Later answer, 2026-10-07:* the last two rows give way to one, which lands with the pin:
`038_age_group_from_the_birth_date_only.sql`, with
`ALTER TABLE students DROP FOREIGN KEY student_age_group, DROP COLUMN age_group_id;`. `level_id`,
its key `student_level` and both tables stay, and 039 is not written.

- **Restartable.** A `DROP TABLE IF EXISTS` can run twice. An `ALTER` that drops cannot, on MySQL 8.0,
  so each is one statement, alone in its file, as 024 is. 008 named both keys in 037, so no lookup is
  needed.
  *Later answer, 2026-10-07:* 038 drops the one key 008 named for the pin, `student_age_group`.
- **If the owner keeps the dots**, 034 is not written, and 035 drops only `avatar_name`. If they keep
  levels and age groups, 037 and 038 are not written.
  *Decided 2026-10-07:* they keep neither, so all seven files are written. 037 drops `age_group_id`
  with `level_id`: an age is worked out from `students.birth_date`, which stays.
  *Later answer, 2026-10-07:* they keep both but the pin, so 032 to 038 are written, and 038 drops
  `age_group_id` alone.
- **The guard.** None of these tables is guarded except `field_values` (§7). Dropping a column changes
  no row count.
- **`database/defaults.php`** stops seeding levels, age groups and message templates, and stops the
  level backfill, in the same commits. After 037 and 038 that code would fail on every request and
  keep the portal closed.
  *Later answer, 2026-10-07:* it keeps seeding levels and age groups, and keeps the level backfill.
  It never read `age_group_id`, so 038 asks nothing of it.
- **Engines.** `database-engineer` runs every file on MariaDB 10.11.14. MySQL 8.0 stays unverified, and
  the report says so, in particular for 037's two key drops and two column drops in one `ALTER`.
  *Later answer, 2026-10-07:* the same goes for 038's key and column dropped in one `ALTER`.
- **Not dropped:** `students.tariff_id`, `price_cents` and `price_note`, which nothing reads since 0011
  (4). `tariff_id` carries a key that 001 left unnamed, and dropping it needs 029's lookup; three unused
  columns are not worth that migration on their own. `contact_requests` stays, as 0022 left it to the
  owner.

### 12. Files and load order

- **Deleted:** `app/duplicate.php`, `views/print.php` and `views/compose.php`; and, with the owner's
  word (given 2026-10-07), `app/presence.php` and `app/groups.php`.
  *Later answer, 2026-10-07:* not `app/groups.php`, which stays where it is, with its `require` and
  its place in the `structure` suite's list.
- **`app/bootstrap.php`** loses their `require`s, and the comments that name `presence.php` and
  `duplicate_record()`. Nothing is added to the load order.
- **`student_age()`** moves from `app/groups.php` to `app/domain.php`, the first file of the domain
  block, so a child's age still shows. `latest_birth_date_for_age()` and the rest of `groups.php` go.
  *Changed 2026-10-07:* `latest_birth_date_for_age()` moves with it and stays. The age range turns
  „Alter von – bis" into two birth-date bounds with it, as `filtered_students()` does for a band
  today. Both need only `today()` from `app/core.php`, and nothing loaded before `app/domain.php`
  calls either: `filtered_students()`, beside them, and the views call them at request time. The sort
  by age is a literal `ORDER BY` clause chosen from a fixed list by a checked value, so no column name
  from the request reaches the SQL. The two ages are read and bounded as §5 says for every value from
  the address.
  *Later answer, 2026-10-07:* nothing moves, and there is no range. `filtered_students()`, in
  `app/domain.php`, keeps calling into `app/groups.php`, loaded after it, at request time only. The
  sort's rule stands: a literal `ORDER BY` from a fixed list, chosen by a checked value.
  *Built 2026-10-08:* `latest_birth_date_for_age()` goes after all. The date bounds in
  `filtered_students()` were its only caller, and they went with the pin: the filter is decided in
  PHP through `student_age_group()`, the one band rule. `student_age()` stays in `app/groups.php`.
- **The router** drops `compose` and `print` from `$allowed` and from its staff list, and, with the
  dots (which go, 2026-10-07), its call to `presence_touch()`.
- **The `structure` suite's** list of expected files loses each deleted file.

## Rejected

- **Hiding the custom-field tab and keeping the code.** The owner asked for removal, and for the data
  to go. A hidden feature is still a feature to maintain.
- **Fixed columns for the custom fields that were used.** Nobody has said which fields are wanted. A
  column is added when somebody asks for one (§6).
- **Removing settings to shrink the portal.** "remove nothing from settings". Only the four with
  nothing left to configure go.
- **Removing the example data.** The owner said strip it down. The phone sweep and the owner's first
  look need a filled portal.
- **Removing the problem reporter during the beta.** It is how the testers report, with the steps
  that led there.
- **Removing the change log.** It is the only record of what an IBAN was before a change (0025), and
  where the trainer finds the value to type back (0020 §7).
- **Removing the outbox.** It is where an invitation is seen to have gone, and the end-to-end walk
  checks it there.
- **Removing the maintenance switch.** It is how an owner without a shell holds an update back until
  the upload is complete.
- **Removing automatic charges.** They are the trainer's largest relief.
- **Removing the personal colour.** ADR 0013 decided it, and it is small.
- **Removing the invitation by address alone (0021) now that the wizard exists.** It is the lightest
  way the trainer has to bring a family in: she types one address.
- **Removing the three † features without asking.** The owner said "whenever you need my opinion you
  can ask me", and asking costs a minute. Each was somebody's request a week ago.
- **Keeping levels and age groups for display only.** Two lists, two tabs and two filters to maintain,
  for labels that one course does not need. A level a club needs can be a course.
  *Later answer, 2026-10-07:* no longer rejected: the owner keeps both (the note at the top).
- **Keeping „An mehrere schreiben" beside the group chat.** The group reaches everybody in a course,
  news reaches everybody, `payment_remind` reaches everybody who owes, and the direct chat reaches one
  family. A fifth way to write is the one nobody remembers how to use.
- **Dropping the unused price columns on `students`, or `contact_requests`, now.** §11 says why not.
- **Testing robustness with posts only.** A query value is input too, and a warning from it is the
  same defect.
- **Listing actions or fields in the suite by hand.** The next action added would be the one left out.
- **Fuzzing through a browser.** Slow, and the server is what has to hold. The end-to-end walk covers
  the browser.
- **Field definitions for other clubs in another shape.** That is custom fields again.
- **A second way to build a wizard.** 0023 §5's draft in the session works. A second way to keep steps
  would be the one that leaks a half-made record.

## Consequences

- **Records.**
  - Superseded: 0017 (profile pictures). 0015 (presence) carries a note that this record supersedes it
    once the owner agrees (†); until then it holds as 0022 left it.
  - Amended, each with a note headed "Superseded in part by ADR 0026": 0011, 0016, 0018, 0020, 0022,
    0023 and 0025. 0020's status line also gains 0023, which amended it on 2026-10-06 without the line
    saying so.
  - `docs/decisions/README.md` lists every record, with its status and what it decides today, so a
    reader can tell which records hold without opening them. It is kept in the same commit as every
    new record from now on.
  - If the owner keeps a † feature, a note on this record says so, and the notes on 0015, 0016, 0020,
    0022 and 0023 lose their † parts.
    *Later answer, 2026-10-07:* the owner kept levels and age groups; the notes on 0020 and 0021
    say so.
- **Actions.** 78 today, and 76 after 0022 §11. This record removes 13: `field_save`,
  `record_duplicate`, `filter_save`, `filter_delete`, `template_save`, `bulk_preview`, `bulk_send`,
  `mail_run`, `avatar_save`, and with the owner's word `presence_save`, `status_emoji_save`,
  `level_save` and `age_group_save`. 63 remain.
  *Later answer, 2026-10-07:* `level_save` and `age_group_save` stay, so it removes 11, and 65
  remain by the same count.
- **Pages.** `compose` and `print` leave the router.
- **Schema.** §11. No guarded table loses rows but `field_values`, whose guard goes with it.
- **Dependencies.** None added, none removed.
- **Order of work.**
  1. The project manager asks the owner about the three † features.
  2. `qa-tester` writes the robustness suite now: it is in `tests/` only, and it reads the dispatch
     lists, so a removed action drops out of it by itself. It lands with `backend-dev`'s fixes for
     what it finds.
  3. Everything else waits until `backend-dev` and `frontend-dev` have landed 0023. Almost every
     removal touches `app/actions.php`, `app/domain.php`, `app/auth.php`, `views/student.php`,
     `views/student_new.php` or `views/accounts.php`, which they are changing now.
  4. Then one commit per change, each with its migration, code, views and tests: custom fields;
     copying; writing to many, with templates and saved views; `mail_run`; the printed sheet;
     Verwaltung's „Tarife" tab; the pictures; the example data; the club's name in what families
     receive; and, with the owner's word, the dots, status and emoji, and levels and age groups.
  5. Then the gaps G1 to G8, the designer first, each its own change.
- **`database-engineer`:** §7's migration and guard, §11's files, `database/defaults.php`,
  `tests/migration-data.php` where it writes a dropped table, and the run on MariaDB 10.11.14.
- **`backend-dev`:** the code in §5, §6, §7, §8 and §12, the bootstrap and the router.
- **`frontend-dev`:** the views, `app.css` and `app.js` in §7 and §8; initials wherever a picture was;
  with the owner's word, the account menu without status or emoji.
- **`qa-tester`:** the robustness suite (§5); every suite §7 and §8 name; `tests/suites/demo.php` (§9);
  and the page list of `tests/mobile.mjs` (§4).
- **`mobile-tester`:** after each change, the screens it touched; after each gap, its new screens.
- **`ui-ux-designer`:** G1 to G8.
- **`security-reviewer`:** the robustness suite's roles and ids; the removal of `insert_row()` with
  `app/duplicate.php`.
- **`code-reviewer`:** nothing named here survives, and no comment still names it.
- **`docs-writer`:** `CLAUDE.md`, below; `README`, `INSTALL`, `UPDATING` (its list of guarded tables
  names `field_values`, and its checks name custom fields), `CHANGELOG`, `TESTING.md` and
  `VALIDATION.md`; the privacy drafts lose the profile pictures, and with the owner's word the online
  history.
- **`devops-engineer`:** nothing. No command is added, and none is needed after an update.

#### `CLAUDE.md`, for `docs-writer`

| Where | Today | Becomes |
| --- | --- | --- |
| The opening paragraph | "A self-hosted CRM for one badminton coach and her students. It is used by a trainer who is not technical, on a phone, with real families' data in it. That is the whole design constraint, and it decides most arguments." | "A self-hosted portal for a badminton trainer and her students' families, built so that another club could run its own copy. The trainer is not technical and works on a phone; so do the families, who read little. The owner, who is the administrator, builds it with Claude. It is in beta: what is in it is test data (ADR 0026). These people are the design constraint, and they decide most arguments." |
| „Stop and ask" | "Stop and ask the owner whenever a decision is **ambiguous**, **destructive**, or **changes the database schema**. Say what you would do and why, and wait. She has no staging copy, no shell and no way to undo a migration that has already run against her families' data — the cost of asking is a minute and the cost of guessing is hers to carry." | "During the beta (ADR 0026) the project manager decides schema changes and larger changes, then tells the owner what to test and what to deploy. Stop and ask the owner when a decision is **ambiguous**, costs money, reverses something the owner asked for, or decides how families' data will be handled once real families arrive. Say what you would do and why, and wait. The owner has no staging copy and no shell, and a migration that has run cannot be undone, so every rule under „An update refuses rather than guesses" holds for a test install too." |
| „What "better" is not" | "Every dependency is a thing she must keep patched." | "Every dependency is a thing the owner must keep patched." |
| „Every query is parameterised" | "see `tracked_entities()` and `revert_version()` for the pattern." | "see `tracked_entities()` and `setting_reference_tables()` for the pattern." (`revert_version()` was removed.) |
| „Every write goes through `transactional()`" | "Writes worth undoing go through `tracked()` (`app/history.php`) so the operator can reverse a mis-tap." | "Writes whose earlier value matters go through `tracked()` (`app/history.php`), so an administrator can read what it was and who changed it. There is no undo: the way back from a mis-tap is an action of its own, or the old value typed in again." |
| The bilingual convention | "**All operator-facing text is bilingual**" | "**Every text a person reads is bilingual**" |
| The shell convention | "**The operator has no shell.** She installs by opening `setup.php` and updates by uploading files" | "**The owner has no shell.** They install by opening `setup.php` and update by uploading files" |
| Conventions, a new bullet after „An update refuses rather than guesses" | none | "**Any value from anyone.** Every input is checked where it enters, through the helpers in `app/core.php` and `app/validate.php`. A refusal is one plain sentence on the page it came from: never a 500, a blank page or a PHP warning. The `robustness` suite posts unexpected values to every action as every role (ADR 0026 §5)." |
| Conventions, a second new bullet | none | "**Nothing club-specific is hard-coded.** What a family receives names the club (`club_name`). A field somebody needs is a real column with a `DEFAULT`, never one users define (ADR 0026 §6)." |
| „Before you say something works", the commands | none | add `tests/mariadb-local.sh robustness   # every action, every role, unexpected values` |
| „Honesty about what has been verified" | "the operator is making decisions about her family's data based on what you claim." | "the owner is making decisions based on what you claim." |
| The same section | "her server runs 10.11.19 and 8.4.24." | "the owner's server runs 10.11.19 and 8.4.24." |
| The heading „The person using this" | "## The person using this" | "## The people using this" |
| Its first line | "A middle-aged trainer, iOS user, not technical. Mostly on a phone." | three bullets: "**The owner** is the administrator and builds the portal with Claude. They test and deploy what they are told to, by upload." / "**The trainer** is middle-aged, uses an iPhone and is not technical. Mostly on her phone." / "**Students and families** are not technical, use phones and read little." |
| Its „Mobile first" bullet | "Check 320px, not just 390px." | "Check 320px, not just 390px, as staff and as a family, light and dark." |
| A new bullet in that section | none | "**A task with several steps is a wizard**: one question per step, the step count shown, an icon beside a short word, and it works without JavaScript (ADR 0026 §4)." |

- **Must stay true:**
  - nothing reads a table this record drops, and no view names one;
  - a guarded table that disappears in an update counts as emptied;
  - every request value is read through a helper that refuses or ignores the wrong shape, and every
    number that is not an id is bounded before it reaches a column;
  - the robustness suite finds the actions in the dispatch files and the fields in the code;
  - what a family receives names the club, never "Badminton";
  - a task with several steps is a wizard built as §4 says, and only so;
  - the change log reads what it wrote: a column or an entity it no longer knows is named, never
    looked up and never thrown on;
  - 020, 027 and every other shipped migration stay as they are.
  - *Later answer, 2026-10-07:* one rule decides a child's band, `student_age_group()`, and the
    list, the filter, the child's page and Verwaltung's counts all ask it; nothing reads or writes
    `students.age_group_id` once 038 has run.

### For the owner

1. **Three removals take away things somebody asked for by name (†).** They wait for your word.
   Recommended: remove all three.
   - the online dots, the status and when somebody was online (your request of 2026-09-29, ADR 0015).
     It keeps 30 days of when each child was online;
   - the status emoji (your request of 2026-10-02, ADR 0022 §7);
   - levels and age groups („the bands she named", `database/defaults.php`). Billing reads neither.
   - *Answered 2026-10-07:* "Remove all" for the first two. For levels and age groups: "trainer needs
     to sort them by age groups, if it is possible to do that with only birth dates and without
     explicit groups then well do it". All three go, and the students list sorts and filters by age,
     worked out from the birth date (the note under the title).
   - *Later answer, 2026-10-07, 19:03 UTC:* "Skill levels were good to have / age levels will
     also be needed, but it would be enough if the app can dynamically output in which age group
     one falls in". Levels stay; the age groups stay as lists you edit, and each child's is worked
     out from the birth date, so the setting that fixed a child in one goes (the note at the top).
2. **The privacy notice, afterwards.** Under Einstellungen → Datenschutz it should no longer say that
   profile pictures are stored, nor, if the dots go, that when somebody was online is kept for 30 days.
   `docs-writer` drafts the sentences, and you release them.
   *2026-10-07:* the dots go, so both sentences come out.
3. **What to test.** After each change lands, the project manager tells you what to open and what to
   look for. The first: Einstellungen has no „Eigene Felder für Schüler", and a child's page has no
   „Weitere Angaben".
4. **Still yours, from 0022:** whether the old contact requests may be deleted. Nothing changes until
   you say.

## In plain words, for the owner

- The portal is for three kinds of people: you, who build it with Claude; the trainer; and the
  families. Mostly on phones.
- It is a beta. Database changes and bigger changes are decided for you, and you are told afterwards
  what to test and what to upload. The safety rules for updates stay as they are.
- Custom fields are gone, with what was in them.
- Also going, because nobody needs them now and each costs work to keep: copying records, saved list
  views, writing to many at once and its templates, profile pictures (everybody shows as initials),
  and the printed form. Your settings stay.
- Three more wait for your word, because they were asked for: the online dots and status, the emoji,
  and levels and age groups. If they go, four settings about the dots go with them.
- *2026-10-07:* you answered. All three go, with the four settings. Instead, the students list can be
  sorted by age and filtered by age, worked out from the birth date alone.
- *Later answer, 2026-10-07:* you answered again. Levels and age groups stay, and you edit them as
  before. A child's age group is always worked out from the birth date, so it changes by itself on a
  birthday. The students list can be sorted by age, with a heading for each age group.
- The example data is smaller: one course, four children, and every kind of charge.
- *Built 2026-10-08:* the example logins work for two weeks after the data is filled, then sign-in
  refuses them; remove the example data and fill it again for fresh ones. Their password is four
  made-up words, shown once.
- Anything with several steps becomes a guide with one question per step.
- Whatever anybody types, the portal answers with one plain sentence, never an error page. A new test
  sends every form nonsense, as every kind of person.
- Next, the designer works on: an easy first sign-in for families, paying with the receipt in one go,
  a list of receipts the trainer ticks off, „Heute krank" and „Heute fällt aus" in one tap, and news in
  the bell.
