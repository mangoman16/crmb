# Roadmap

The plan, and the only place it is kept. Read it at the start of a session, and change it in
the commit that finishes or adds a task. What was built is in [CHANGELOG.md](CHANGELOG.md) and
git, what was verified and on what in [VALIDATION.md](VALIDATION.md), and the structural
decisions in [docs/decisions/](docs/decisions/README.md).

**Where it stands.** Beta. Version 0.6.0, not released. No portal holds real families' data.
The suite runs on MariaDB only; MySQL 8.0 has never been run.

## Now and next

In this order.

1. **Accounts, server side (ADR 0023).** Built: work-in-progress commit `e9aff6e`, finished
   by `d095ca4`; the reviews' findings fixed in `2788c4f` (a form sent twice lands where the
   first went; viewing is look-only; the last place in a course is held). The security
   re-review passed; the code re-review's remaining items, and a view that outlived its
   viewer, are fixed in the commit after it. A student's login may sign in with a username instead of an address; every
   student has a login, a placeholder until it gets an address or a username; „Schüler
   anlegen" is a two-step wizard; staff make sign-in links that work once, within 48 hours;
   a student's login is replaced, never deleted (migrations 028–031). The screens are still
   the minimum the server side needed: their design pass is item 5. The whole suite and the
   browser walk pass at `2788c4f` on MariaDB 10.11.14 with PHP 8.4.26; the sweep at phone
   width has not been run on it.
2. **ADR 0026.** Accepted (`4314eef`): who the portal is for, the beta rules, the goals as the
   scope test with eight gaps (G1–G8), the robustness rule, and what goes. The architect is
   recorded the owner's answers of 2026-10-07 in it (`b5d92e1`).
3. **Removals (ADR 0026 §7–§12).** In separate commits, each with its migrations:
   - **round 1, done:** custom fields with their data (032); copying records; saved views and
     message templates (033); writing to many; the queue button „Warteschlange senden"; the
     printed sheets; and Verwaltung's „Tarife" tab;
   - **round 2, done:** the online dots, the chosen status, the status emoji and the online
     history (034, 035), profile pictures and their files (035, 036), contact requests with
     their rows (037), and new voice notes and files — a message is text and photos, a
     family's from the camera; old voice notes and files stay, old chats between two
     students are readable and closed;
   - round 3: levels and configured age groups — children are sorted and filtered by age
     from the birth date (docs/design/2026-10-07-ios-design-language-and-goal-screens.md,
     Part 1) — and the example data, cut to one course, four children and two family logins
     (§9).

   A document describing a removed feature changes in the commit that removes it, never
   before, so the documents never describe code that is not there.
4. **The iOS design language (owner, 2026-10-07: "Make sure it is as intuitive and easy to use
   as a modern ios app, design, design language").** ui-ux-designer writes the language —
   the system font, inset grouped lists, large titles, a tab bar at the bottom on phones,
   segmented controls, switches, sheets, the club's colour as the tint, safe areas, transitions
   — with an audit of every screen; frontend-dev applies it globally (app.css, layout.php, the
   helpers in app/ui.php) after the removals, and the owner sees screenshots before the
   screens below are built in it.
5. **Screens.** Not started.
   - ADR 0023, as specified in
     [docs/design/2026-10-05-accounts-and-chat-screens.md](docs/design/2026-10-05-accounts-and-chat-screens.md).
   - ADR 0022 §11, the chat cut to its basics. Built with round 2 (`8e5ce48`): a message is
     text and photos (a student's from the camera, JPEG; staff JPEG, PNG or WebP), and no new
     chat between two students — old ones are readable and closed. Being built now: §11.1 and
     §11.2, administrators read every chat with nothing recorded about their reading, and
     „Alle Einzelchats"; the chat paragraph of both privacy drafts changes in the same commit.
   - ADR 0024, taking a child out of a course and back in. Migration 031 is in; nothing
     writes `removed_on` yet.
   - ADR 0025, every change to a payment profile kept in „Änderungen": being built now.
6. **Security batch.** Not started.
   - The example trainer login: a high-entropy password, and an expiry.
   - A throttle on `proof_upload`.
   - Unsubscribe links never expire (`valid_unsubscribe()`, `app/auth.php`), and their
     „payments" wording.
   - An invoice's recipient: the student, or the billing name.
   - A family's notice and e-mail about a changed training date link to `?page=classes`, which
     only staff may open; they should lead to the dashboard (`class_session_save` in
     `app/actions_config.php`, near line 77; `notify_class_change()` in `app/mail.php`, near
     line 192).
   - A logo photo stored sideways (EXIF orientation) is measured unrotated and can be refused.
   - Targets below the minimum on a desktop screen: the help button's summary (36 px),
     „Alle ansehen" (21 px).
7. **A refused update stays refused (ADR 0027): done** (`bfeb592`, `1ad0488`). The counts from
   before an update stay in `storage/update-unfinished.json` until a run passes; until then
   no page is served and nothing writes, and the portal reopens by itself once the rows are
   back. The restore walk in TESTING.md (G.1–G.9) has not been walked in a real phpMyAdmin.
8. **Robustness suite: done** (`aa5b1b7`). `tests/suites/robustness.php` sends unexpected values
   to every action as every role and draws every page with them; 751 checks, about 45 s.
   What it cannot reach — uploads, actions no page draws a form for — it lists after a run.
9. **The owner's goals, walked at phone width** by ui-ux-designer, then the fixes. Not
   started.
10. **The documents, rechecked after each phase** by docs-writer. Last done 2026-10-07.

## The owner's goals

In their words.

- 2026-10-07, what the portal is for:
  - "most if not all of the users will work on phone, so we need the website to work
    perfectly on phoney"
  - "the trainer and students want an intuitive interface, they are non techcnical, and most
    of them wont be really liking reading too much, with wizards and everything that make
    following steps easy, with visual guidances"
  - "we need this software to be robust, avoid errors and code as much as needed to make sure
    stuff run as expected, expect all kinds of unexpected behaviours and values by users and
    trainers"
  - "whole goal for students is: easy roll in in courses, for beginning it probably will be
    only 1 course / manage payments and pay for the course and see reciepts and open payments
    and paid payments / report sick, get news from trainer for changes"
  - "for the trainer, she wants management burden not to be here anymore, she is not
    technical and wants smooth roll in, ways to add students to courses, student payments to
    be easier managed, tell them if changes happen to the courses or even if she is sick,
    communicate, help them and give feedback, have a list which students were in a course and
    which not"
  - "i like the settings to be comprehensive, remove nothing from settings, i want a good
    setting, and the setting should allow customization and also in best case this should
    work for other people too imagine this is a software you would sell, but custom fields
    being able to add them is not needed"
- 2026-10-07: "Clean up all files, specially all markdowns as a plan for yourself, and which to
  check when, make sure they are accurate and correct."
- 2026-10-07: "admin, who is me, will make the code with you"
- 2026-10-07: "The software is now in beta, dont worry about about db changes or bigger
  changes … software is in testing, i am unsure about most of the features, and i want you to
  handle the thinking and planning, whenever you need my opinion you can ask me, if i have to
  test anything or deploy anything, tell me. also stripe down the demo data or stuff that are
  not needed anymore but are too much to maintain."
- 2026-10-06: "trainer should be able to change iban" (ADR 0025); about taking a child out of
  a course: "child can undo but needs to be accepted by trainer" (ADR 0024).
- 2026-10-05: "now that usernames are allowed, no email login should also be possible, but
  ideally discouraged" (ADR 0021, amended by 0023); "there should be no student ever in a
  course without an account" (ADR 0010, amended by 0023); a chat that is "the absolute basics"
  (ADR 0022 §11).
- 2026-10-02: the chat "should basically be more like whatsapp … there should be groups for
  courses, and children in a course will be in it, automatically, cannot leave … trainer and
  admin see all groups" (ADR 0022).
- "I'm not technical enough for debugging and need something that just works." (ADR 0012)

## Decided

- **2026-10-07**
  - The owner is the administrator, and builds the portal together with Claude. The trainer is
    not technical and works on a phone. Students and families are not technical, work on
    phones and read little.
  - Beta: there are no real families' data. Schema changes and larger changes no longer wait
    for the owner's approval; the project manager decides and tells the owner what to test or
    deploy. What protects any install still holds: append-only migrations with the checksum
    ledger, the update refusing rather than guessing, a backup before an update, security,
    parameterised SQL, `e()`, cents, UTC, bilingual text.
  - Custom fields are removed, with their data (migration 032).
  - The demo data, and whatever is no longer needed but costs upkeep, is stripped down.
  - Settings stay comprehensive, and the portal should work for other clubs too.
  - Paying stays bank transfer, the QR code and an uploaded receipt.
  - Trainer feedback to students is the chat.
  - The project manager, on the reviews of ADR 0023:
    - Only an administrator makes a sign-in link for a login already in use; a trainer makes
      one only for a login not yet signed in. A child without an address who forgets the
      password needs an administrator. The admin's half waits for ADR 0022 §11, because
      until then such a link would open a child's private chats with other families.
    - Viewing the portal as somebody else is look-only: every change is refused while
      viewing, by one rule, except ending the view and signing out.
  - The owner, on ADR 0026's questions: the online dots, the chosen status, the status emoji
    and the online history go ("Remove all"). Levels go. Age groups: "trainer needs to sort
    them by age groups, if it is possible to do that with only birth dates and without
    explicit groups then well do it" — so no configured bands; the list sorts and filters by
    age from the birth date.
  - The owner, on the design language's questions: age counts as how old a child is today,
    not by birth year; a family's tab bar has four entries (Übersicht · Beiträge · Chats ·
    Profil), news reaching them through the bell and the overview; both overviews get
    simpler (the trainer's stat tiles go, in favour of „Heute" and „Zu tun"); and the DE/EN
    switch leaves the signed-in bar, staying on the sign-in pages.
  - The project manager, on 0026's last open point: the old contact requests are deleted
    with ADR 0022 §11, which removes asking to write to another student. In the beta they
    are test data.
- **2026-10-05 and 2026-10-06** — the owner's answers, and the project manager's decisions on
  the designer's questions (ADRs 0022 to 0025, and the design specification):
  - Administrators can read every chat, and their reading is not recorded.
  - Students send photos from the camera only (JPEG); staff send JPEG, PNG or WebP.
  - The trainer can change the IBAN.
  - A child removed from a course can ask to come back, and staff accept.
  - The student wizard, usernames and one-time sign-in links (ADR 0023).
  - Sign-in links last 48 hours.
  - The minimum password length stays 12.
  - No sign-in links for staff.
  - One quick action on the start page: „Schüler anlegen".
  - Chats between two students are closed; the existing ones stay readable.
  - Everybody is signed out once after the update.
- **Earlier, still standing**
  - Absence does not change what is charged: fees are for the place, not the session.
  - The payment QR code carries what is still owed, not the whole charge.
  - „Änderungen" is a change log that informs; there is no undo (removed 2026-09-16).
  - Automatic monthly charges are switched on by hand on the Beiträge page; nothing is charged
    without anybody having looked first.
  - Only the German privacy notice must be released (ADR 0011).

## Open

For the project manager to decide:

- Should billing warn about a „Beendet" student with no end date?
- Rejoining a course starts new terms, while restoring a removed child keeps the old ones: is
  that right?
- Reminders per family instead of per charge?

For the owner to do:

- Write the privacy sentences only the operator can write — who runs the portal, the host and
  the mail provider, how long things are kept, and the legal bases in the bracketed notes —
  and release the German notice under **Einstellungen → Datenschutz**. Until it is released
  no invitation goes out, and nobody can sign in for the first time with a sign-in link.

## For the owner to test or deploy

- **The new iOS look on your iPhone** (from `f5d3c28`). On the beta install, with test data
  only: walk TESTING.md I.1–I.11 as the trainer and as a family — the font and your text
  size, the bars around the notch, the home-screen app's status bar, pressed states, the
  sheet, the switches, the back button. It has only been seen in Chromium. Say what feels
  wrong; it is cheap to change before the screens are built on it.
- **Round 2 on your iPhone and an Android phone, if you have one** (TESTING.md R.9–R.17): as
  a family, the „+" in a chat opens the camera — this has never been measured, and the portal
  sends `Permissions-Policy: camera=()`, which may stop it; as the trainer, a PNG from the
  gallery; an old voice note still plays. Test data only.

## Before real families use it

- One real e-mail end to end through the club's own provider: the SMTP test, an invitation,
  the sign-up, a reset link. Only a mail server on the test machine has been used so far.
- The German privacy notice released (see Open).
- The background work watched on the real host: **Einstellungen → System** shows a recent
  „Letzter Hintergrundlauf", and an invitation leaves the queue without anybody pressing
  anything.
- `config/config.php` kept safe, apart from the database copies: its `app_key` decrypts the
  stored SMTP password and the queued mail, and a dump without it restores neither.
- The portal on a real iPhone in Safari, at 320 px and in dark mode. Every walk so far has
  been Chromium.
- A real hosting account: the `.htaccess` rules, the https redirect behind a host's proxy,
  nginx, and LiteSpeed's path for finishing a response have never run on one.
- `composer audit`, where there is network access. It last reported no advisories during the
  0.2.0 install.
- MySQL 8.0, if a club's host runs it. INSTALL.md names it as intended; it has never been
  run.
- No independent security review or penetration test has been done.

## Later, not scheduled

- Export to CSV of students, charges and payments, so the club's data is never hostage to the
  portal and an accountant can be handed a file.
- „Who is at training on Thursday": a register view that the enrolments and absences can
  already answer.
- Search across messages and notes.
- Two-factor sign-in for administrators.
- A viewer for `audit_log`. „Änderungen" is the separate change log.
- How long `audit_log` and `consent_log` are kept. Both grow without end; decide it with the
  privacy notice's retention periods.
- Terms instead of months, one invoice per family, waiting lists and trial lessons: only once
  real use asks for them.
- Push notifications for a new message. A mail notice exists; ask before building.
- A mail job with no account is always cancelled (`process_mail()`). No caller queues one
  today.
- PHP 8.2 gets security fixes until 31 December 2026, and INSTALL.md still names it as the
  minimum.

## Not planned

- Online card payments: paying stays bank transfer, the QR code and an uploaded receipt.
- A JavaScript framework or a build step (ADR 0002). Every page works without JavaScript.
- Several clubs in one install. Another club installs its own portal.
- Replacing the portal with an off-the-shelf CRM such as EspoCRM, SuiteCRM, Krayin or Monica.
  They are built around leads, deals and pipelines; a club needs students, tariffs, what each
  family has paid for which period, and a chat. They also bring a larger attack surface and an
  upgrade treadmill.
- Progress tracking or skill assessment. Built in 0.2.0 and removed by migration 008; trainer
  feedback to students is the chat.
