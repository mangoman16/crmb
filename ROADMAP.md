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
   viewer, are fixed in the commit after it. Every student has a login, a placeholder until
   it gets an address and an invitation; „Schüler anlegen" is a two-step wizard; a student's
   login is replaced, never deleted (migrations 028–031). The usernames and one-time sign-in
   links built with it went again with ADR 0030, and its screens were built with that (item
   5). The whole suite and the browser walk pass at `2788c4f` on MariaDB 10.11.14 with PHP
   8.4.26; the sweep at phone width has not been run on it.
2. **ADR 0026.** Accepted (`4314eef`): who the portal is for, the beta rules, the goals as the
   scope test with eight gaps (G1–G8), the robustness rule, and what goes. The architect
   recorded the owner's answers of 2026-10-07 in it (`b5d92e1`), and their later answer on
   levels and age groups.
3. **Removals (ADR 0026 §7–§12).** In separate commits, each with its migrations:
   - **round 1, done:** custom fields with their data (032); copying records; saved views and
     message templates (033); writing to many; the queue button „Warteschlange senden"; the
     printed sheets; and Verwaltung's „Tarife" tab;
   - **round 2, done:** the online dots, the chosen status, the status emoji and the online
     history (034, 035), profile pictures and their files (035, 036), contact requests with
     their rows (037), and new voice notes and files — a message is text and photos, a
     family's from the camera; old voice notes and files stay, old chats between two
     students are readable and closed;
   - round 3, done: the age-group pin went (038) — levels and the age bands the trainer
     edits stay, and a child's band comes from the birth date by one rule, so the filter, the
     row, the header and the child's page agree even where bands overlap; the example data is
     one course, four children and two family logins (§9), with a password of eight syllables
     that stops working 14 days after the fill. The students list sorts „Nach Alter" under a
     header per band; its screen
     (docs/design/2026-10-07-ios-design-language-and-goal-screens.md, „Part 1, revised
     2026-10-08") is built: a search, „Alle | Überfällig | Krank", a „Filter" fold, „A–Z |
     Nach Alter", rows with age group and level instead of the price, and „Ohne Kurs".

   A document describing a removed feature changes in the commit that removes it, never
   before, so the documents never describe code that is not there.
4. **The iOS design language (owner, 2026-10-07: "Make sure it is as intuitive and easy to use
   as a modern ios app, design, design language").** ui-ux-designer writes the language —
   the system font, inset grouped lists, large titles, a tab bar at the bottom on phones,
   segmented controls, switches, sheets, the club's colour as the tint, safe areas, transitions
   — with an audit of every screen; frontend-dev applies it globally (app.css, layout.php, the
   helpers in app/ui.php) after the removals, and the owner sees screenshots before the
   screens below are built in it.
5. **Screens.**
   - **ADR 0030 (owner, 2026-10-08): done.** Everybody signs in by their own address;
     usernames and one-time sign-in links are gone. Migration 039 drops `accounts.username`,
     turns every login that signed in by a username into a placeholder, „Ohne Anmeldung",
     and takes it out of its chats, its read marks and its bell. The wizard has two cards;
     the card „Zugang zum Portal" invites in one tap; „Zugänge", Mein Konto and the sign-in
     box are built as the designer respecified them, from ADR 0023's parked screens trimmed
     per 0030 §8. The security review passed; the whole suite and the browser walk pass on
     a clean worktree (VALIDATION.md). Not walked by hand yet: TESTING.md L.2a, L.2b, L.9a,
     L.9b, Z.1–Z.6.
   - ADR 0022 §11, the chat cut to its basics. Built with round 2 (`8e5ce48`): a message is
     text and photos (a student's from the camera, JPEG; staff JPEG, PNG or WebP), and no new
     chat between two students — old ones are readable and closed. §11.1 and §11.2 built:
     administrators read every chat with nothing recorded about their reading, „Alle
     Einzelchats", and a chat between two is closed once no member of staff is in it; the
     chat paragraph of both privacy drafts changed with it, and an operator with a released
     notice re-releases it (UPDATING.md).
   - ADR 0024, taking a child out of a course and back in. Migration 031 is in; nothing
     writes `removed_on` yet.
   - ADR 0025, every change to a payment profile kept in „Änderungen": built, with the
     structure check that no write to `payment_profiles` happens outside the change log.
6. **Security batch.** Built: a throttle on `proof_upload` (20 an hour per login);
   unsubscribe links last 90 days from the mail, and links sent before stop at once; each
   kind of mail's unsubscribe page says what it stops; an invoice is made out to the
   student's name; a changed training date's notice and mail lead to the overview; a logo
   photo stored sideways is measured upright. Still open:
   - The example trainer login: done with round 3 (eight syllables, 14 days).
   - Targets below the minimum on a desktop screen: the help button's summary (36 px),
     „Alle ansehen" (21 px).
7. **A refused update stays refused (ADR 0027): done** (`bfeb592`, `1ad0488`). The counts from
   before an update stay in `storage/update-unfinished.json` until a run passes; until then
   no page is served and nothing writes, and the portal reopens by itself once the rows are
   back. The restore walk in TESTING.md (G.1–G.9) has not been walked in a real phpMyAdmin.
8. **A restore keeps the portal closed until its import is done (ADR 0029): done.** Every
   copy the portal writes says when its import is done; until then nothing changes the
   database, sweeps, copies or runs in the background, the closed page says what to do in
   phpMyAdmin's words and reloads itself, and the portal opens by itself once the whole copy
   is in. A copy from before this release has no marker and is restored with the files of its
   own version and maintenance mode on (INSTALL.md). The restore walk (TESTING.md G.1–G.9,
   H.1–H.5, 21.8) has not been walked in a real phpMyAdmin.
9. **Pictures and a calmer start (owner, 2026-10-08).**
   - **No white flash in dark mode: built.** Every page, the installer and the error page say
     light or dark before their stylesheet; not yet seen on an iPhone (TESTING.md I.12).
   - **Waiting for a page, and the shuttlecock mark: built.** A slow page keeps the tapped
     link pressed, and after 0.7 s a shuttlecock flies along the bar; the shuttlecock is the
     mark and the home-screen icon where a club has uploaded none (design document, „Part 0,
     continued"). Not yet seen on an iPhone or an Android phone (TESTING.md W.1–W.5).
   - **A full waiting page instead of the shuttle along the bar** (owner, later on
     2026-10-08): specified by ui-ux-designer (Part 0.4b), being built. A page slower than
     0.5 s gets the whole screen with a shuttlecock rallying over a net and the club's name,
     shown at least 0.5 s, carried on by the next page and faded out; after 6 s „Dauert
     länger als sonst." with „Abbrechen".
   - **Profile pictures come back** (ADR 0031, decided): faces for the trainer on
     Anwesenheit, the lists and the child's page, and for the children to make their profile
     their own; visible to the course with each family's consent; the family and the trainer
     add them, the family can always replace or remove them. This reverses round 2's removal
     of pictures. The owner's answers of the same evening (ADR 0031, amended): trainers and
     administrators have pictures too; a child agrees alone from 14 under Austrian law, a
     parent below that; an optional „Dein Foto" right after the first password. Specified;
     migrations 040 and 041 written; the server and the screens being built.
10. **Robustness suite: done** (`aa5b1b7`). `tests/suites/robustness.php` sends unexpected values
   to every action as every role and draws every page with them; 751 checks, about 45 s.
   What it cannot reach — uploads, actions no page draws a form for — it lists after a run.
11. **The owner's goals, walked at phone width** by ui-ux-designer, then the fixes. Started
   2026-10-08 as a design audit against the owner's words: "something schick, modern and
   minimalistic and well made and intuitive and easy to use without too much reading but
   visual signs".
12. **The documents, rechecked after each phase** by docs-writer. Last done 2026-10-07.

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
  ideally discouraged" (ADR 0021, amended by 0023; undone on 2026-10-08, ADR 0030); "there
  should be no student ever in a
  course without an account" (ADR 0010, amended by 0023); a chat that is "the absolute basics"
  (ADR 0022 §11).
- 2026-10-02: the chat "should basically be more like whatsapp … there should be groups for
  courses, and children in a course will be in it, automatically, cannot leave … trainer and
  admin see all groups" (ADR 0022).
- "I'm not technical enough for debugging and need something that just works." (ADR 0012)

## Decided

- **2026-10-08** — the owner: "it appears to me that my prompts are too vague or confused,
  make sure to always clarify whenever i give a prompt to reach the ideal prompt and clear
  misunderstandings and reach better results." CLAUDE.md now opens every request with a short
  restatement: what was understood, what is unclear with the answer chosen, then the work.
- **2026-10-08** — the owner, standing: "Work as a team of agents of different skills to make
  sure this software does everything i want, i need and i wanted or needed without knowing.
  whenever you are done with open tasks, you can preset ideas by looking at other open source
  projects on how to make it better, optimize it, or how to make it leaner by removing certain
  elements, constantly keeping me updated and guiding me through it, act as the project
  manager. full time". And: "for that work on perfection and improvement and be the one who
  works on new ideas, it shouldnt be constant, but useful and always improving"; "also from
  the security aspect very important". So after the open work, the project manager brings
  proposals, each with its reason, from comparable open-source club software: what to add,
  what to simplify, what to remove. The owner says yes or no.
- **2026-10-08** — the owner, on pictures: "we are based in austria, use austrian law";
  "trainer at least should always see them, and the admin"; "No legal basis? idk? use is just
  for my mom, the trainer to see who is in course and help her remember her name"; "yea
  definitely admin and trainer picture too makes it more personal"; "yea we can definitely do
  it that a child can also add a photo, but also so that trainer too". And on waiting: "I
  want a full waiting page with nice animations and transitions, it should not be too long to
  annoy and not too short to be even weird to be there / smooth". ADR 0031 records the
  pictures; the bar shuttle below gives way to the full waiting page.
- **2026-10-08** — the project manager, from the whole-portal security audit: the payment QR
  carries only a SEPA bank transfer (EPC, first line „BCD"), and every administrator is told
  when the IBAN, the recipient, the template or a course's payment profile changes (ADR 0025);
  pictures are opened only as JPEG or PNG, up to 24 megapixels (ADR 0031); the body of a sent
  mail that is not a security mail is cleared after 90 days.
- **2026-10-08** — the project manager, on the owner's "a short waiting page": the waiting
  is shown on the page being left — the tapped link stays pressed, and a shuttlecock flies
  along the bar after 0.7 s — not as a separate waiting page, which would itself flash
  between two pages. A full-screen variant is a few lines more if the owner wants it; the
  owner was asked, and wants the full waiting page (above). Files open in the same tab, not a new one: in the iPhone
  home-screen app a new tab likely opens without the app's sign-in (security's point).
- **2026-10-08** — the project manager, on ADR 0031: pictures are for children only. The
  architect proposed pictures for staff too; nobody asked for them, so they wait under Later.
  Whether the children of a course see each other's pictures is a club's choice, so it is a
  setting (`pictures_in_course`), on by default as the owner answered.
  Migration 039 also takes a username login it turns into a placeholder out of its chats,
  its read marks and its bell (ADR 0030, addendum): a placeholder holds nothing a fresh one
  could not, or whoever is invited into it next would read the child's old chats. In the
  beta these are test chats.
- **2026-10-08** — the owner, later: "please also implement a custom loading screen, should
  not be too slow, but now sometimes pages flash into eyes although dark mode is active and
  that is annoying. a short waiting page. for logo and waiting use badminton elements. also
  customization is very important for the brain of the young, so profile picture would still
  be nice, specially good for the trainer to see faces and not only names to know who people
  are, so for anwesenheit imagine how hard it is for someone to start teaching and remember
  who people are, otherwise i love everything that has changed so far". Asked, they answered:
  a child's picture is seen by the others in the same course too (with each family's
  consent); the family and the trainer may add or change it, and the family can always
  replace or remove it; a child may still be added without an address and invited later.
- **2026-10-08** — the owner: "Drop once again the username support, mainly email login
  support / 1 admin 1 email / 1 person 1 email / 1 trainer 1 email / 1 student 1 email"
  (ADR 0030). So: usernames go, every login has its own address, one address per person (a
  parent with two children has two logins, as before). The project manager, with it: the
  one-time sign-in links go too — they existed for logins that could get no mail, and the
  invitation and the reset mail cover every way in; a child may still be added „Ohne
  Anmeldung" and invited once the address is known. Asked whether no child should exist
  without an address, the owner answered: "Yes, invite later".
- **2026-10-08** — the project manager:
  - An invoice is made out to the student's name, never to the login's freely typed name. A
    billing name of the family's own (the paying parent, for an invoice over 400 €) needs a
    column the schema does not have; it is built only if a real family or the owner's tax
    adviser asks for it.
  - The students list's rows show the band and the level instead of the price, which wrapped
    every row at 320 px; a child in no current course gets an „Ohne Kurs" badge. Prices stay
    on the child's page and on Geld. It is cheap to put back if the owner misses it.
  - A package handed to the owner for the beta is numbered `0.6.0-beta.N`, counting from 2
    (the ZIP of `00dc020` was the first); the release is `0.6.0`.
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
      until then such a link would open a child's private chats with other families. (Gone
      with the links, ADR 0030.)
    - Viewing the portal as somebody else is look-only: every change is refused while
      viewing, by one rule, except ending the view and signing out.
  - The owner, on ADR 0026's questions: the online dots, the chosen status, the status emoji
    and the online history go ("Remove all"). Levels and age groups stay. First: "trainer
    needs to sort them by age groups, if it is possible to do that with only birth dates and
    without explicit groups then well do it"; later the same day: "Skill levels were good to
    have / age levels will also be needed, but it would be enough if the app can dynamically
    output in which age group one falls in". So the bands the trainer edits stay, a child's
    band comes from the birth date only, the pin goes, and the list sorts by age under a
    header per band (ADR 0026, its note on the later answer).
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
  - The student wizard, usernames and one-time sign-in links (ADR 0023); the usernames and
    the links went again with ADR 0030, 2026-10-08.
  - Sign-in links last 48 hours (gone with the links).
  - The minimum password length stays 12.
  - No sign-in links for staff (gone with the links).
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

For the owner to decide:

- How long the portal keeps what it holds: chats, sick notes, attendance, mail, logs. ADR 0032
  proposes a period for each, with accounting records kept seven years (BAO § 132).
- Whether a photo whose hidden data cannot be read through is refused instead of kept as it
  came, as decided on 2026-10-05 (ADR 0022 §11.5; the security audit recommends refusing).

For the owner to do:

- Write the privacy sentences only the operator can write — who runs the portal, the host and
  the mail provider, how long things are kept, and the legal bases in the bracketed notes —
  and release the German notice under **Einstellungen → Datenschutz**. Until it is released
  no invitation goes out.

## For the owner to test or deploy

- **Two minutes on your iPhone: the first start in dark mode** (design document, 0.5a). Turn
  dark mode on, close the app in the app switcher, open it from the home screen, and note the
  colour shown before the first page appears. Dark launch images are built only if it is
  white. Then walk TESTING.md W.1–W.3 on the iPhone, and W.4 on an Android phone if you have
  one.
- **No white flash in dark mode, on your iPhone** (TESTING.md I.12, test data only): in dark
  mode, and once with „Immer dunkel" under Mein Konto on a phone in light mode, open the
  home-screen app, tap through several pages, go Back, reload, open a link from Mail, and
  open the installer in dark mode. Write down any white frame. Chromium cannot show what
  Safari does, so only an iPhone can say.
- **Signing in by address only** (ADR 0030, test data only): after the update, a test login
  that signed in with a username is „Ohne Anmeldung"; enter its address on the child's card
  and send the invitation. Old sign-in links stop working. On your iPhone, walk TESTING.md
  L.2a, L.9a, L.9b and Z.1–Z.6. The update needs nothing from you.
- **The students list on your iPhone** (TESTING.md 17.1–17.8, test data only): search a
  child, tap „Überfällig" and „Krank", open „Filter", switch to „Nach Alter". Say what
  feels wrong; the price no longer shows in the list or on the overview, only on the
  child's page and on Geld.
- **The restore, in your hosting panel's phpMyAdmin** (TESTING.md H.1–H.5 and G.1–G.9, test
  data only): import a copy from `storage/backups`, open the portal in between and see it
  closed with the sentence, see it open by itself afterwards; stop an import halfway and
  import the file again. Nobody has walked this in a real phpMyAdmin yet.
- **Example logins stop working 14 days after the data was filled** (round 3). Remove the
  example data and fill it again for fresh ones; the password is four made-up words, shown
  once. A child who was pinned to an age group and has no birth date shows no age group after
  the update; enter the birth date.
- **Unsubscribe links in your own test mails stop working** with the next update: they now
  carry an expiry, and links made before have none. The next mail carries a working link,
  and the page says where the switches are.
- **The privacy notice's chat paragraph changed:** administrators can read every chat, and
  nothing records their reading. If your beta portal's notice is released, replace the
  paragraph under Einstellungen → Datenschutz and release it again (UPDATING.md says how).
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
- No independent security review or penetration test has been done. security-reviewer read
  the whole code on 2026-10-08: no High finding; the fixes are being built.
- The legal reading behind pictures checked (ADR 0031): legitimate interest (Art. 6 Abs. 1
  lit. f DSGVO) for staff seeing a child's picture; a parent's yes in words for a child under
  14 (§ 4 Abs. 4 DSG, Art. 8 DSGVO's "reasonable efforts"); whether consent records must
  outlive a deleted login.

## Later, not scheduled

- A club icon that is near-black on a see-through ground barely shows in light mode: the
  sidebar, the sign-in page and their previews put the dark menu colour behind it, about
  1.2:1 (mobile-tester, 2026-10-08; as before the shuttlecock mark). Backing it with the tint
  in light mode too is ui-ux-designer's call.
- A tap within about 0.2 s of a page appearing is lost on every page, to the cross-fade
  (`@view-transition`, app.css; mobile-tester, 2026-10-08, the same with JavaScript off).
  Shorter, or letting taps through during it, is frontend-dev's to measure; the staff
  overview's stat tiles overflowing at 200 % text go with the overview build.
- At 320 px with 200 % text, a child's „Beiträge" tab is 327 px wide: „Notfallkontakt
  eintragen" with its hint and „Überfällig" run past the screen (mobile-tester, 2026-10-08,
  older than the students list). It goes with the Bezahlen build, which redraws that tab.
- One rule for the whole portal, `overflow-wrap:anywhere` with tables left out, instead of
  fixing each screen at 200 % text as a measurement finds it (frontend-dev, 2026-10-08). To
  be measured on what mobile-tester found sideways at 200 % text on a 320 px screen, all of
  it older than ADR 0030: the signed-out footer's „v0.6.0" (357 px), the privacy page's
  „Fassung …" (364 px), the staff overview's tiles (338 px), and Geld, Rechnungen and a
  child's „Beiträge" tab (327 px).
- Export to CSV of students, charges and payments, so the club's data is never hostage to the
  portal and an accountant can be handed a file.
- „Who is at training on Thursday": a register view that the enrolments and absences can
  already answer.
- Search across messages and notes.
- Two-factor sign-in for administrators.
- A viewer for `audit_log`. „Änderungen" is the separate change log.
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
