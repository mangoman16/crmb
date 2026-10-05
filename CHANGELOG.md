# Changelog

## 0.6.0 — unreleased

### Before you upload this over a portal that has families in it

A portal installed fresh from this version can skip this. An existing one needs
one thing done before the upload, and changes in several ways the first time it
is opened; [UPDATING.md](UPDATING.md#updating-an-existing-portal-to-060) has
them in full.

**First, while the old version is still running, add three paragraphs to the
privacy notice** under **Einstellungen → Datenschutz**: one saying that the
portal now records when each account was online, and who can see it, one
replacing the old newsletter paragraph, because club news by email now starts
switched on and is no longer described as voluntary consent, and one about the
course groups, the online dot everybody sees, and the administrators reading
chats between a child and a trainer. UPDATING.md has
the first two to copy and says where in the drafts the third one is. The drafts
shipped with this version only fill in the notice of a new portal; an existing
one keeps the text it had. Each paragraph
ends in a note in square brackets for the legal basis, which is hers to decide
and have checked, and a released notice is not saved until the notes are
replaced.

Migrations 020 to 027 then run by themselves. 020 gives every account a status
that starts on „Automatisch" and an empty list of times online; nothing is
filled in from before. 021 switches news by email on for accounts created from
now on; every existing account keeps its choice. 022 to 024 give usernames and
take them away again: every login signs in with its address, as before. 025 to
027 give every course its group chat, let staff take a group message down, and
add the status emoji; the old shared conversations stay readable and closed.

Migration 019 takes every child but the first off a login they shared; nothing
is deleted, each change is written under **Änderungen**, and those children
need an address of their own and an invitation before they can sign in on
their own. Invitations and „Passwort vergessen?“ links wait until **„Nur
Verbindung prüfen“** under **Einstellungen → SMTP** has passed once. And the
version number printed under the privacy notice changes once although the text
does not; nobody is asked to acknowledge it again.

### Signing in, and two ways to add a person

- **Everybody signs in with their own e-mail address.** Usernames are gone;
  „Passwort vergessen" asks for the address too.
- **„Per E-Mail einladen"** on the **Schüler** page: type an address and a
  language. The person fills in their name and birth date, sets a password and
  lands on their own page with „Kurs wählen" first; choosing a course is a
  request the trainer answers. Staff are told in the bell when somebody new has
  set themselves up. Open invitations are listed there, to send again or
  withdraw without typing anything.
- **„Schüler anlegen"** works as before: the trainer enters the details, can put
  the child into a course straight away, and the invitation only has the person
  set their own password. Nobody sets another person's password.
- A birth date in the future or more than a hundred years ago is refused,
  wherever it is typed.

### The chat works like a messenger

- **A group for every course.** Its children are whoever is enrolled now — a
  child who joins can read what came before, one who leaves loses it — and the
  trainers and administrators are in every group. A course has its group from
  the moment it exists, a copy made with „Kurs kopieren" too. Group messages
  send no e-mail. Staff can take a message down from „⋯" and put it back from
  the same place.
- **Chats with one person.** A child writes to a trainer or an administrator by
  name, and staff to any child. The administrators can read chats between a
  child and a trainer; a second trainer cannot. Children's chats with each
  other, once one has agreed, stay private to the two of them.
- **Everybody has an online dot**, a child's always automatic, and may pick one
  of sixteen emojis to show beside their name. When somebody was last here
  stays with the trainers and administrators.
- **A photo keeps only the picture**, because a picture in a group reaches
  every child in the course. Where it was taken, its camera and its time are
  removed before it is stored, and so is whatever a phone puts after the
  picture: a second photo, or a motion photo's short video, which can carry a
  location of its own. It stays the right way up. An HDR photo shows at normal
  brightness, because what makes it brighter is one of those second pictures. A
  GIF is stored as it came. So is a picture whose file the portal cannot make
  sense of, and whether to refuse that instead is still hers to decide.
- In a group's „Wer ist in der Gruppe?", a child sees the classmates who read
  it; one whose family has no login yet is only counted. Staff see everybody.
- While a trainer or an administrator views the portal as somebody else, she
  sees only the chats she may read herself, and the bell leaves out that
  person's chat notices, because each one quotes the message. Nothing can be
  written, asked for, taken down or marked read in that view, „Alle gelesen"
  included, so their unread messages and notices stay unread; an administrator
  viewing the portal as a trainer cannot write to the children in her name.
  „Neue Nachricht" says that only the person can write, and lists nobody.
- **„An mehrere schreiben"** (formerly „Gruppe anschreiben") puts the message in
  each child's chat with you.
- On a phone the writing box sits above the menu bar instead of under it, and a
  chat opens at its newest message.

### After an update, the browser fetches what changed

- The stylesheet and script were linked with the version number, which did not
  change between uploads, so a browser kept the old ones: the account menu
  opened as plain text and buttons over the page, and the bell still jumped.
  Every file is now linked with a fingerprint of its own contents, the club's
  colours too.

### Tests run on MariaDB, the engine her server runs

- The SQLite translation the tests used to run on is gone. `tests/mariadb-local.sh`
  starts a throwaway MariaDB; `tests/e2e.sh` walks the first evening in a
  browser and now also an invitation by address. Run on MariaDB 10.11.14 with
  PHP 8.4.26; MySQL 8.0 is not verified.
- Two tests that broke when the calendar moved on — one on 2 October, one due
  on 1 January — now take their dates from the clock.

### The top bar: a steady bell, and a menu behind your picture

- **Opening the bell no longer moves anything.** On a phone the whole bar used
  to jump and the panel ran off the left edge of the screen. The panel now
  stays on the screen, the number of unread notices is a badge in the portal's
  colour, like the one on **Post**, and Escape, a tap anywhere else or opening
  the other menu closes it.
- **Tapping your picture opens „Mein Konto" and „Abmelden".** Trainers and
  administrators also get a status there: „Automatisch", „Abwesend" or „Als
  offline anzeigen". The menu works without JavaScript. Families have the menu,
  but no status (ADR 0016).
- On the attendance list, the text on the marks for present and absent is
  readable in dark mode again; it now comes from the same colour as the text on
  every button.

### Who was online, for trainers and administrators

- **A coloured dot on each picture**: green online, blue recently, yellow
  away, grey offline. „Abwesend" shows as yellow while you are in the portal;
  „Als offline anzeigen" shows as offline to the other trainers, while
  administrators still see the true times, marked „(als offline angezeigt)".
- **„Wann online? Letzte 30 Tage"**, under **Konten** and on each child's page,
  lists the days and the times from when to when each account was in the
  portal. Only the date and time are kept: no IP address and no pages viewed.
  Periods older than 30 days are the first thing the nightly cleanup removes;
  under **Einstellungen → System → Erweitert** the 30 days can be shortened,
  never lengthened (ADR 0015, migration 020).
- **Families see none of it**, neither their own times nor anybody else's: the
  owner's decision. When a trainer views the portal as a family, the trainer's
  visit is recorded, not the family's, so a child does not show as online at
  23:00 because somebody checked what the child sees.

### Profile pictures load once, and a family sees only its own

- The picture in the top bar was fetched again on every page. It is now kept
  by the browser, privately, for up to seven days, at an address that changes
  when the picture does, so a new picture shows at once. Invoices, attachments
  and payment proofs are still never kept. Signing out asks the browser to
  clear what it kept; Safari may ignore that, which is why the seven days are
  the real limit (ADR 0017).
- **This closed a real hole.** Any signed-in family could fetch every other
  child's photo, and every account's, by counting through the numbers in the
  address. Now staff see every picture, and everybody else sees their own and
  those of the trainers and administrators. Anything else is refused, and the
  page draws initials instead, in **Nachrichten** too.
- The list of contact requests could show a stranger's face beside a request;
  it now shows the sender's own picture.

### Club news by email starts switched on

- The owner decided that club news is information every member needs, not
  advertising (ADR 0018). New accounts therefore start with „Neuigkeiten per
  E-Mail" switched on (migration 021). Existing accounts keep what they had.
- The invitation page shows „Neuigkeiten des Vereins per E-Mail erhalten.
  Jederzeit abbestellbar." already ticked. A family can untick it there, later
  under **Mein Konto**, or through the link in every such email; a no is kept,
  with a record of when it was given.
- **The printed sign-up form asks for the no**: „Bitte keine Neuigkeiten des
  Vereins per E-Mail schicken." and the same for message reminders, so a parent
  who ticked nothing is not mistaken for one who declined. A data sheet printed
  for an existing child ticks each no from what the login actually holds.
- The news form no longer says „Newsletter-Abonnenten"; it sends to everyone
  who receives news by email.
- The privacy drafts say so; see the first section above for an existing
  portal.

### The club's own colours and logo

- **Einstellungen → Portal** has two new cards. **„Aussehen"** takes a main
  colour, a menu colour, a highlight and a background; the dark-mode shades are
  worked out from them and can be set by hand under „Erweitert". A colour too
  pale or too dark for readable text is saved, not refused, and used darker or
  lighter in the same hue, with the card showing „Für gute Lesbarkeit
  verwendet: …" beside her choice. A save that changes a colour names the
  colours it replaced, so the change can be typed back. A colour picker sits beside each box; without
  JavaScript she types the colour. (ADR 0013.)
- **„Logo"** replaces the „B" top left, in the menu, on the sign-in page and in
  the phone's top bar: a PNG, JPEG or WebP of at most 1 MB, at least 88 pixels
  tall, at most 2048 on either side, and no more than five times as wide as it
  is tall or twice as tall as it is wide. Two switches on
  „Aussehen" hide the portal's name and the „Verwaltung" line beside it.
  Removing the logo brings back the portal icon, or else the „B". (ADR 0014.)
- A portal with no colours set looks exactly as before and loads nothing extra.
- **Known limit**: a photo taken on a phone is often stored sideways with a
  note telling the viewer to turn it. The portal measures the picture as stored,
  so such a logo can be refused as too tall when it looks wide. Saving it again
  from an image editor, or as a screenshot, fixes that.

### One login is one student

- **A student's email address is their login.** Brothers and sisters each need
  an address of their own. Access is invited, suspended, sent again or deleted
  on the student's own page, in a card „Zugang zum Portal“ whose badge says
  **Kein Zugang**, **Eingeladen**, **Aktiv** or **Gesperrt**. An address that is
  already somebody's login is refused with „Jede Schülerin und jeder Schüler
  braucht eine eigene E-Mail-Adresse.“ before anything is written, and a unique
  index in the database refuses it too, so no later mistake in the code can put
  two children on one login again (ADR 0010).
- **Migration 019 separates the logins that were shared.** The child whose
  record was created first keeps the login; the others keep their record,
  courses, charges, invoices, payments and address, and get a line under
  **Änderungen** saying which login they were on. The overview and the
  **Schüler** list name the children who now need an address of their own.
- **Konten is the team's page now**, „Team und Zugänge“: trainers and
  administrators. A student login that no student points to any more is listed
  there under „Zugänge ohne Schüler“, to lock or delete. A family's menu has
  **Profil**, which goes straight to their child's page; **Mein Konto** keeps
  the sign-in settings. Every mail to a family opens with the child's first
  name.
- **An address can be changed by the trainer only while the invitation is
  still open**: the old link stops working and a new one goes to the new
  address. Once the family has signed up, the address is theirs to change under
  **Mein Konto**, confirmed from the new mailbox. Only a student's login can be
  re-addressed this way; a trainer's or administrator's pending invitation
  cannot.
- **„Ohne E-Mail anlegen (mit Passwort)“** on a child's page lets an
  administrator create that child's login directly, for a portal whose mail is
  not working yet.
- **Deleting a student no longer promises an undo** that was removed earlier in
  this release. The message now says that **Änderungen** shows what was deleted
  and that it cannot be restored.

### A new portal walks her through its setup

- **„Dein Portal einrichten“** lists the nine things a portal needs before
  families come: name and address, bank account, a first course, a price for
  every course, the children, charges, email, the privacy notice and the
  invitations. Each step links to the screen that already does the job, and
  each is ticked from the data itself on every visit, never by hand — so a
  course that loses its price un-ticks its step. Example data never ticks
  anything (ADR 0011).
- An administrator lands there at every sign-in until it is finished, the
  overview shows how many steps are left, and after saving a step the page
  offers „← Zurück zur Einrichtung“. Once everything is done the list can be
  hidden, and **Einstellungen → Einrichtung ansehen** brings it back. Trainers
  and families never see it.
- **„Kinder eintragen“ means every child is in a course with a price.** The
  first version ticked it for a child in no course at all, because a child
  with no enrolment has no unpriced enrolment either; the browser walk below
  caught it.

### A menu of seven, and fewer boxes at first

- **The menu is one list of seven**: Übersicht, Schüler, Kurse, Anwesenheit,
  Geld, Nachrichten, Einstellungen — for a trainer, Verwaltung in place of
  Einstellungen — with „Einrichtung“ above them while the checklist is
  unfinished. Nothing folds open or shut. The rest is reached from where it
  belongs: **Rechnungen** by a „Beiträge · Rechnungen“ switch under Geld;
  „Gruppe anschreiben“, „Neuigkeiten“ and „Postausgang“ from the top of
  Nachrichten; Verwaltung, Konten, Änderungen, Einrichtung ansehen and Erweitert
  as cards at the top of Einstellungen. The entry a page belongs to stays
  marked while it is open.
- **The phone bar** reads Übersicht, Schüler, Post, Anwesend, Mehr for the
  trainer, and Übersicht, Profil, Post, Neues, Konto for a family. „Mehr“ opens
  the menu without JavaScript. A family's way to the privacy notice and the
  version is at the end of **Mein Konto**, under „Datenschutz und Hilfe“.
- **The price box on a child's page is gone.** Its tariff and agreed price
  billed nobody: what bills is the price of the course the child is in. The
  stored values are kept and a save no longer changes them. The **Schüler**
  list's „Tarif“ filter and the `{{tariff}}` placeholder in email templates now
  read the child's current courses, so neither can name a price that bills
  nobody. „Dabei seit“ and „Mitgliedschaft bis“ moved to „Einteilung“.
  Automatic monthly charges are switched on and off on the **Beiträge** page,
  by an administrator.
- **Forms ask for less before they are saved.** A course's price form shows the
  name and the amounts; everything else waits under „Mehr Möglichkeiten“, as
  do the rarer course fields on a child's page. The default payment recipient
  is chosen from a list rather than typed as a number. The VAT rate and UID
  number only appear once „Mit Umsatzsteuer“ is picked — with JavaScript off,
  all of them show. Rarely needed settings, custom fields among them, sit under
  „Erweitert“.
- **A new portal no longer starts with an empty „Trainingsgruppe“ field**, which
  every family saw as „Weitere Angaben: Trainingsgruppe –“. A portal that
  already has it keeps it.

### Only the German privacy notice has to be released

- **The English text is optional.** When there is one it is checked the same
  way as the German, because a half-translated notice is not one to release.
  Somebody using the portal in English is shown the German text under „This
  privacy notice is only available in German. If anything in it is unclear,
  please ask your coach.“
- **The sign-in page carries one small line** about the notice instead of the
  long paragraph that sat on every public page.
- **The drafts describe what the portal actually does now**: the host's server
  logs and their 30 days, what a problem report and an automatically recorded
  error hold and when each is deleted, and one login per student. The release
  check finds a placeholder of any length — six of the eight in each draft had
  been too long for it — and reads a line in linear time, so a long line of
  brackets cannot make the settings page hang.

### Invitations wait for a mail test that passed

- **Invitations, password-reset links and address confirmations are only sent
  once the last test under Einstellungen → SMTP passed.** Before, a saved SMTP
  setting was enough, and an invitation behind a server that never answered sat
  unsent in the outbox while the family waited. Changing the SMTP settings
  clears the result; saving them unchanged keeps it. The checklist's email step
  and the invite button read the same rule, so they cannot disagree.
- A mail server that answered with broken characters could turn the test into
  an error page. It now shows the answer.
- **Issuing an invoice is refused only when a charge really has nowhere to be
  paid**, and the refusal names the recipient that lacks an IBAN, or says that
  none is chosen.

### A problem report says how she got there

- **„Etwas funktioniert hier nicht“ now records the exact address**, the one
  before it and the last eight steps, with the form fields that were sent —
  never a password, a token or a file name; of an upload only its size and
  type (ADR 0009). Before, two of those fields had been empty in every report
  ever filed.
- **A report sent from a child's page brings the family back to that page and
  tab.** It used to land them on „Kein Zugriff“.
- **Marking a report „Erledigt“ removes what was typed**, and a done report is
  deleted, screenshot and all, 30 days after it was last marked done. A report
  that is still open is never deleted. Nobody has waited the 30 days: the
  suite ages the stored date, and TESTING.md U.19 is where the clock is proven.

### Errors report themselves

- **An unexpected error is written down for the administrators** under
  **Einstellungen → Rückmeldungen**, marked „Automatisch erfasst“, with the
  steps that led to it. The person who hit it sees the friendly page, as
  before. The same error again counts up on the same entry instead of adding
  one, and a notification goes out only for a new error or one that came back
  after being marked done (ADR 0012).
- **„Für den Support kopieren“** gives a block of plain text for whoever helps
  her: what broke and where, the version, the device, and the pages visited
  with the names of the fields, never what was typed into them. No names, no
  email addresses, no IP address, search terms shown as „…“, and the portal's
  own folder on the server taken off every path. A database's own error
  message is never kept, because it can quote a family's address.
- An entry is deleted 30 days after the error last happened. At most fifty
  are kept open. Nothing is written while the portal is in maintenance or when
  the database connection is the thing that broke; the server's error log gets
  the class and code either way, never a database message.

### The portal's own icon

- **Einstellungen → Portal, „Symbol des Portals“** takes a square PNG from 180
  to 2048 pixels and shows it in the browser tab, on an iPhone home screen and
  in an Android install, which now also carries the club's name. Anything else
  is refused with a sentence saying what is wrong with it, and „Standard-Symbol
  verwenden“ goes back to the built-in one. The icon lives in `storage/`, so an
  update cannot overwrite it (ADR 0008). An iPhone keeps an icon already on the
  home screen until it is removed and added again.

### What the first walk in a real browser found

A new robot, `tests/e2e.sh`, does the first evening the way she would: install,
all nine setup steps through their own buttons at phone width, a family invited
through a mail server running on the same machine, signed in, paying and reporting
a problem, an invoice issued and an error provoked. The whole PHP suite had
passed on every one of these:

- **The price form on a course had no save button** — a function call was
  printed as text — so step 4 of the checklist could not be done at all.
- **The privacy link on the invitation page was too small to tap.**
- **A child joining on the 28th got a charge due on the 1st**, overdue on the
  day it was made. A charge is now never due before the first day it covers or
  before the day it is written, and the bank reference uses the period the
  invoice shows.
- **The family's charge card said „01.09.2026 – 30.09.2026“** while the invoice
  for the same charge said „28.09.2026 – 30.09.2026“. Both now read the same
  span.
- The report that landed on „Kein Zugriff“, step 5 ticking for a child in no
  course, and the mail test that broke on an odd character, all described above.

### Smaller things

- The course rows under „In einen Kurs eintragen“, the lines with a name and a
  badge, and the pinned help button no longer stretch, crowd or cover controls
  — measured at 320, 390 and 1280 pixels, light and dark.
- The console no longer crashes on a host that switches off `shell_exec`.
- `setup.php` trims the administrator's password the way the sign-in form
  does, so a space typed at the end cannot lock her out of the first account.

### Tests that cannot touch the live portal, and hidden folders

- **The tests write only into a temporary folder of their own**, print it on
  their second line and remove it afterwards. Before, the install suite briefly
  wrote a migration into the portal's own folder, and a run against a real
  database with a copied configuration could have deleted the portal's uploads
  and backups.
- **`tests/existing-database.sh`** runs the whole suite on shared hosting,
  against an empty database made in the hosting panel whose name ends in
  `_test`. It refuses the portal's own database, a database that is not empty
  the first time, and one holding a real email address.
- **The `.htaccess` at the top answers 404 for hidden folders** such as `.git`,
  `.claude` or a stray `.env`, even where `mod_rewrite` is off; `.well-known/`
  stays reachable for certificate renewal. The rule needs `mod_alias`, and is
  wrapped so a server without it still serves the portal. The structure suite
  reads the rule; no real Apache has been asked.

### What this was checked on

The whole suite: 3813 assertions, 0 failed, on the SQLite translation under PHP
8.4.19 and 8.5.11; 3832, 0 failed, against MariaDB 10.11.14, with all nineteen
migrations applying there. The data that migrations 015, 016 and 019 carry
across is checked on SQLite only, as that run says at its end. The browser walk
passed, 279 checks, against MariaDB 10.11.14 on PHP 8.4.19 and 8.5.11, with no
PHP warning, JavaScript error or layout failure. **MySQL 8.0 was not tried**,
nor Safari on a real iPhone, a real mail provider, a PDF reader rather than a
parser, or a real hosting account. VALIDATION.md has the details.

The top bar, online status, news by email and the colours and logo were
checked later, at commit `dda5db0`. The whole suite gave 4840 assertions, 0
failed, on the SQLite translation, and 4851, 0 failed, against MariaDB 10.11.14,
with all twenty-one migrations applying there. The data that 020 and 021 find
already in place was checked on MariaDB as well. The browser walk was **not**
repeated for these, and nothing was tried on a real iPhone. **MySQL 8.0 was not
tried.**

Signing in by address and the chat, with the fixes from its second round of
review, were checked on the code of commit `6a5cfc6`. The suite no longer has a
SQLite translation; it gave 5882 assertions, 0 failed, on MariaDB 10.11.14 with
PHP 8.4.26, with all twenty-seven migrations applying and the data that 015,
016 and 019 to 025 carry across checked on MariaDB too. The browser walk
passed, 361 checks, on the same versions, but it does not open the chat. The
chat's checks by hand, C.1 to C.18 in TESTING.md, are part of neither run, and
nothing was tried on a real iPhone. **MySQL 8.0 was not tried.**

### Signing in correctly no longer counts against her

- **A sign-in that works no longer spends one of the ten attempts.** Ten are
  allowed per account per quarter of an hour, and each one is counted before the password is
  checked, because at that moment nobody knows yet whether it is the right one —
  but nothing ever undid the count afterwards. A family whose children share one
  phone reached ten correct sign-ins inside fifteen minutes by using the portal
  exactly as intended, and was answered with „Zu viele Versuche. Bitte später
  erneut versuchen." — a sentence about attacks, shown to a parent who had done
  nothing but sign in, with no way back but waiting. A sign-in that succeeds now
  empties that account's count. A wrong password still counts, which is the
  attempt the limit exists for.
- **A one-time link opened out of the mailbox proves the same thing**, so an
  accepted invitation, an opened reset link and a confirmed change of address
  clear the count too. Without that, the reset sent to a locked-out family let
  them in once and left them locked out of the next sign-in until the quarter of
  an hour ran down.
- **The attempts are counted against the account, not against the spelling that
  was typed.** The database compares addresses under a collation that treats
  upper and lower case, accents, ß and ss, ligatures and full-width letters as
  the same, so `familie@beispiel.at` and `familie@beispiel.át` are one account
  row and two different words to the portal. Every spelling used to get its own ten guesses, which
  means somebody guessing passwords spelled the address differently and carried
  on, with nothing but the limit per internet connection left in the way — and
  behind that sign-in are children's birth dates, health notes and parents' bank
  details. The address is now looked up first and the attempt counted against
  the account it finds; an address with no account is counted as what was typed,
  which is all there is. Measured on MariaDB 10.11.14 rather than assumed.
- **What that deliberately does not close:** ten failed attempts on one spelling
  and then one on another are refused straight away, which tells whoever is
  trying that both reach the same account — eleven requests to learn that an
  address is registered here. Closing it means keying the count on the
  database's own sort key, which differs between engines, is missing from the
  translation the test suite runs on, and is being withdrawn upstream. It is
  accepted and written down rather than quietly left out.
- **That the lockout ends was never actually checked.** „Bitte später erneut
  versuchen" is a promise that later arrives, and a count that never reset would
  have looked exactly like a working limit to every test there was: it would
  refuse people, which is the visible half, and never let them back in, which is
  the half nobody was watching. The quarter of an hour is now tested from both
  sides — ten minutes in is not yet later, sixteen minutes in is. The clock
  itself is still walked by hand, because the suite ages the stored counter
  rather than waiting.
- **Two limits are never cleared, on purpose.** The sixty attempts a quarter of
  an hour from one internet connection stand, because one valid account must not
  be able to refresh the limit that slows guessing at every other one; and
  „Passwort vergessen" stands, because typing an address proves nothing about
  who typed it and clearing it would hand anybody an unlimited mailer pointed at
  one family's inbox. Families sharing one connection therefore share that
  budget; TESTING.md 4.6d describes what that looks like from her side.

### An account created twice, from two directions

- **Inviting somebody whose address already has one now says so plainly.** The
  invite form wrote the row and left the database to refuse it, so what came
  back was the catch-all „Die Eingabe ist nicht möglich: Adresse bereits
  vergeben oder verknüpfte Daten vorhanden." — one sentence covering two quite
  different causes. It now says „Diese Adresse hat schon ein Konto.", which is
  what the other way of creating an account has always said.
- **Two setup pages open at once cannot both create the first administrator.**
  The guard counted the administrators inside the transaction that writes the
  row, and a plain count takes no locks — measured with two connections on
  MariaDB 10.11.14: both pages saw an empty portal and it ended with two
  administrators. The count now locks, so the second page waits and the portal
  ends with one.
- **The test suite had been dispatching actions with no transaction open**, so
  every row lock the portal takes while it writes — the locks that stop two
  submissions doing the same thing twice — had been holding nothing at all
  throughout. It now dispatches the way a real request does, which is what makes
  the two items above testable rather than merely written.

### The documents were describing a portal that had moved

- PROJECT.md's status table rated skill assessment „Built, tested" with high
  confidence. Migration 008 dropped its four tables and there are no such
  screens. A status table is read by somebody deciding what to rely on, so its
  figures were counted again rather than remembered: six migrations against
  eighteen, 31 tables against 42, 23 settings against 52, and a header two
  releases behind.
- Three documents were still saying six migrations, and README.md said the
  update guard compares ten tables before and after when it compares nineteen —
  the number somebody reads to decide whether an update is safe to run.
- The by-hand list printed section 4 as 4.6, 4.6d, 4.6e, 4.6g, 4.7, 4.6a, and so
  sent anybody working down the page backwards at item seven. It runs in order
  now.
- `docs/decisions/` writes down the structural decisions that had only ever been
  habits — one per file, with what was rejected and why — and CLAUDE.md now says
  which part of the project each contributor may change.

### Trying it out no longer takes a term's worth of typing

- **The installer offers to fill the portal with example data.** An empty portal
  is unrecognisable: no courses, no children, every page an empty state, and
  anybody deciding whether to use this had to invent a term's worth of data
  first. One tick on the setup page now gives three courses, fifteen children
  with charges, and three sign-ins — a trainer and two families — with the
  password printed on the finish page. Unticked, nothing but the administrator
  is created, which is what a portal about to hold real data wants.
- **`demo:fill` prints the password it set.** It said „the password printed
  above" and printed no password, so the three accounts it had just made could
  not be signed in to at all. It is generated once and never stored in the
  clear, so the fill is the only moment anybody can be told it.
- **An account can be created directly, with a password instead of a link.**
  Inviting needs working SMTP and a released privacy notice, so a portal on its
  first evening had no way to make a second account at all — not for a second
  administrator, not for a trainer standing next to her, not for trying the
  thing out. Administrators only, because handing out a login is more than
  sending an invitation, and the flash says plainly that the address was not
  confirmed. A family account made this way adopts the child at that address, so
  it does not sign in to an empty portal.
- **The layout check can use both roles on an example portal.** Its one
  `--password` could not cover an administrator and the example accounts, which
  have a password of their own; `--family-password` closes that, and the sweep
  went from 100 screens to 120.
- **TESTING.md opens with a twenty-minute script**: install with example data,
  build the real club's course and price list, add a member, watch the 42 €
  come out, issue the invoice, print both sheets, impersonate, sign in as a
  family, make a login without email, and put it all back.

### What a real club's own paperwork found

The portal was set up on MariaDB with the price list, the bank account and the
registration form of a working badminton club, and a member was put through it
from the paper form to a printed invoice. Five things came out of that.

- **A part period is now stated as the part.** A member who joined on 12 November
  and was charged seven weeks of a yearly fee got an invoice reading
  „Leistungszeitraum 01.01. – 31.12." The amount had always been right and the
  sentence under it had not, on a document a family keeps and § 11 Abs 1 Z 3
  lit d UStG asks the period of. A charge now records what it covers beside the
  billing period it belongs to; the period still decides what has been billed,
  so nobody is billed twice by the change.
- **An invoice with nowhere to pay it is refused.** The installer leaves a
  recipient called „Vereinskonto" ready with the account number blank, and makes
  it the default — so a fresh portal produced a finished-looking invoice with no
  IBAN on it, and the first anybody knew was the phone call. **Rechnungen** now
  names it among the things still missing, and issuing refuses and says which
  recipient to fix. Not a corner case: it is the state every portal starts in.
- **The IBAN is printed in groups of four on the invoice**, as it already was on
  the two pages that show it. Twenty characters in one run is what somebody has
  to copy into a banking app.
- **Amounts in a box she types in are written with a comma.** 19,80 € came back
  as 19.80 on a German form, next to a list that said 19,80 €.
- **The names in a „these children still need something" notice are buttons.**
  As a comma-separated sentence they were 17px tall and touching, which on a
  phone is a third of the minimum this project measures against, twice over.

### And the four things that form asked for and the portal could not hold

- **A member has an address and a telephone number of their own.** The paper form
  asks six things and the portal held four. The address had nowhere to go at all,
  and a number could only be recorded by inventing an emergency contact — which
  for an adult member means listing yourself as the person to ring if something
  happens to you. One line for the address, the way the form asks it.
- **Above 400 € an invoice carries the recipient's address**, and is refused
  until there is one. § 11 Abs 1 Z 3 lit b UStG wants the name and the address;
  Abs 6 lets a Kleinbetragsrechnung up to 400 € gross leave both out, which is
  most of a club's invoices — so it is asked for where it matters rather than
  made compulsory on a monthly fee.
- **„Anteilig nach vollen Monaten" is a way of charging a part period.** What a
  club form means by „aliquot": the month somebody joins in is theirs entirely.
  Their own worked example is 42 € for November and December of a 252 € year;
  pro rata by days — the only rule the portal had — would have been 34,52 €, and
  42 € is the number the family signed.
- **The printables carry the price list and no longer address everybody as a
  child.** A blank form now has the fee as lines to tick, taken from the courses
  so paper and portal cannot drift; the heading and the signature line say
  „bei Minderjährigen" rather than assuming one. Still one sheet of A4 with four
  tariffs on it — measured with a real 14mm-margin PDF after the first version
  ran to two pages.
- „ZVR-, Firmenbuch- oder GISA-Nummer": a registered club in Austria has a
  ZVR-Zahl and neither of the other two, and it goes on everything it sends out.
- The skip link („Zum Inhalt") is parked off the top of the screen rather than
  hidden, so it printed across the signature line of both sheets.

### Creating something asks for the basics, and then says what is left

- **The form that creates a child now asks for four things**: a name, a date of
  birth, the email address, and whether they are a member. Not the level, not
  the tariff, not the internal notes, and not the custom fields she has added
  herself — a form of twenty boxes is a form somebody abandons in the middle of
  a training session.
- **And then the child's page says what is still to do**, numbered, at the top:
  an emergency contact, an address or an invitation, a course, a tariff. Each
  one is a link to where it is done, and each disappears when it is. A child
  with no course is a child nobody bills, and four days later nobody remembers
  which of fifteen children that was.
- A newly created course says the same: a training day, and a tariff.

### Two things to print

- **A blank registration form**, for a parent standing in the hall with a biro.
  One letter per box, in block capitals, because a ruled line produces
  handwriting nobody can read back and a date that might be 03/04 or 04/03.
- **A data sheet** for a child whose details the trainer typed in herself, to
  hand back for checking and signing.
- Both are the same layout on purpose: what is asked for on paper is exactly
  what the portal stores — including her own custom fields, and never the ones
  she marked internal. Nothing is collected that has nowhere to go.
- Both fit on one sheet of A4, which was measured by generating the PDF and
  counting its pages. A screenshot said the form fitted; the printer said two.

### Signing in belongs to the child, and a contact is somebody to ring

- **One row was answering two questions.** "Who do I ring when she falls over"
  and "who does the portal write to" were both the standard contact, and they
  came apart in practice: the grandmother who should be rung has no email, the
  father who reads the invoices is never in the hall. The form could not answer
  either without lying about the other.
- **The address is on the child now.** For a child that is a parent's address,
  which is why it is a field rather than a second person: whoever reads the
  invoices is whoever holds the login. **Zugang einladen** on the child's page
  creates the account and sends the invitation there; a second child at the same
  address joins the same account, which is how siblings share a login without a
  second concept for it.
- **Contacts are emergency contacts.** They need a phone number and no longer
  need an email address. Nothing is sent to them.
- An invitation is not sent twice to an account that has already set a password:
  that link would have replaced a password that works.
- The **Schüler** list names the two gaps separately, because they are filled in
  in two different places.
- Nobody is signed out by the update: every account keeps its own address and
  its own password, and the new field is filled from whatever the portal was
  already writing to.
- **A form inside a form** on the student page meant the browser was throwing
  the inner one away: „Bild speichern" was submitting the whole record. Every
  page is now counted for that, for every role.

### One tariff, several ways to pay it

- **Four prices for the same thing used to be four tariffs.** "252 € im Jahr,
  162 € im Halbjahr, 99 € im Quartal, 37 € im Monat" meant four rows with the
  same name and four places to change the price when it goes up. A tariff now
  carries a price per interval, and the enrolment says which of them a child is
  on — chosen from a list that shows what each one costs, so the interval and
  its price are never looked up separately.
- **The welcome discount moved off the price list and onto the agreement.** It
  sat on the tariff, which made "three months at half price for this one child"
  into a tariff nobody else could be put on. The tariff now carries only the
  *shapes* of discount she gives — „Erster Monat gratis", „Geschwisterrabatt,
  dauerhaft −20 %" — and the child carries the one that family actually got,
  under a name that goes on their invoice. Changing a template changes nothing
  for a family who already has one.
- **A tariff can be copied**, with its prices and its templates, because four
  intervals and three templates is twenty minutes of typing to get a second
  tariff that differs in one number — and twenty minutes of typing is where a
  wrong price comes from.
- Nobody's next invoice changes because of the update: every tariff's price
  becomes its first rate, every discount becomes a template *and* is copied onto
  every enrolment that was getting it. That sentence, and "nobody is signed out"
  above it, are now checked rather than asserted: the suite builds a portal as it
  stood before the update, applies the rest, and reads back the prices, the
  discounts and the addresses.
- **Everything she builds by hand can be copied** — a course with its training
  days and its whole price list, a tariff, a level, an age group, a payment
  recipient, an email template, a custom field, a news item. What comes with a
  copy is written down rather than followed from the foreign keys, because a
  course's training days belong to it and the children enrolled in it do not.

### A menu that fits, and a mail test that answers

- **The menu on the left scrolled.** Thirteen destinations in one column are
  958px tall, and a 1920x1080 screen at 110% zoom leaves 873px, so the last
  three were below the fold. Six of them now sit inside three sections —
  **Training**, **Geld** and **System** — and only the section you are working
  in is open, which the browser keeps true by shutting the others. 715px at a
  full window, 541px at the zoom it was reported at. The count of what is
  waiting moves up to the section while the section is shut.
- **„Testmail vormerken" was not a test.** It queued a message and sent the
  operator to the outbox to look for it, where a blocked port, a wrong
  certificate and a rejected password all looked the same: nothing arrived.
  **Einstellungen → SMTP → Verbindung testen** now opens the connection while
  she waits, sends to any address she types in, and writes down every step —
  and says which one failed in a sentence she can act on, with the server's own
  words underneath. The user name and the password are taken back out of the
  transcript, because the whole point of it is to be forwarded to a host.
- The sign-in page no longer explains which address to use. It asks for an email
  address and a password, which is what the form already said.
- **„Etwas funktioniert hier nicht" was under the last card**, which meant it
  was only ever found by somebody who scrolled to the bottom of a page they had
  already given up on. On a desktop screen it is a button in the bottom right
  corner now. On a phone it stays at the end of the page and the **Mehr** menu
  carries a link down to it: the bottom of a phone screen already holds the menu
  bar and the sticky **Speichern** button, and a third thing floating over those
  is how a Save button becomes unreachable.
- **„Wem gehört der Kontakt?"** asked about ownership when it wanted a name —
  and the same box said „Name" on the edit form next to it. The two forms had
  been written out twice and drifted; they are one fieldset now, and it asks for
  „Name der Kontaktperson".
- **A time of day was shown in the language of the device, not of the portal.**
  `<input type="time">` ignores the page and follows the phone or the computer,
  so on a device set to English every training time read "04:00 PM" however the
  portal was set — and a trainer copying 16:00 off a hall timetable should not
  have to translate it. Times are an hour box and a minute box now: 24 hours on
  every device, every five minutes, and a time already stored that is not on
  that grid keeps its place in the list rather than being quietly moved. An hour
  with no minute is refused instead of being stored as "on the hour".
- **Every signed-out page says what happens to the data**: the privacy notice
  applies, only the cookies the portal needs to work are set, there is no
  analytics and no advertising, and nothing is sold or passed to anybody else.
  The same paragraph is in the shipped privacy draft under *Empfänger* —
  a portal installed before this keeps its own edited notice, so add the
  sentence there by hand if you want it.

Her half of the portal: what she runs day to day, in the words she uses for it,
after a round of testing that produced a list of about forty things.

### The same pass, done in a browser on a phone

Every page was opened at 320 and 390 CSS pixels, in light and dark, for the
trainer, a family and a signed-out visitor. What that found, which no test
running on the server could have:

- **The eight colour dots under „Mein Konto → Farbe" were all grey.** They were
  coloured with a style attribute, and the portal's own Content-Security-Policy
  refuses inline styles, so the browser threw every one of them away. They are
  classes now, and the `structure` suite fails if a view ever sets a style
  attribute again.
- **The pinned bar showed an empty square where your picture belongs.** The rule
  that hides your name on a phone hid every direct child of the link, the
  picture included.
- **"Entschuldigt" broke mid-word** inside the attendance control at 320px
  ("Entschuldi / gt"). Two statuses per row below 380px, and the odd one out
  takes the full width.
- **The message box was the smallest thing in the composer** — four controls in
  one row left it about 180px wide, so the placeholder wrapped over four lines.
  It gets its own row on a narrow screen now.
- **A row of tabs wider than the screen had nothing to say it scrolled**, so
  „Anwesenheit" on a child's page was never found. There is a shadow at the edge
  now, and it disappears when the strip is scrolled to the end.
- **Six tap targets were under 44pt**: the language switch, the privacy link in
  both footers, "Passwort vergessen?", "Zur Anmeldung", "Zurück" and the "use
  the default" chip.
- **The example family could not see their own example conversation.** The demo
  data wrote a thread without the row that says who is in it, so the family was
  told they had no messages while the trainer could read them.
- **"Links eine Unterhaltung auswählen"** — there is no left on a phone.
- **A deleted course answered "Kein Zugriff"**, which tells the trainer she is
  not allowed to see her own course. A record that is gone now says „Nicht
  gefunden" and answers 404, while somebody else's child still gets exactly the
  same answer as a child who was deleted.

`node tests/mobile.mjs` is the check itself, kept in the repository: it opens
every page for every role at both widths and fails on anything wider than the
screen, any tap target under 44pt, text under 12px, or a browser error. It
reports 120 screens with nothing to fix; before this pass it found eight.

### A pass over the whole thing, and what it found

Every finding below was turned into a test that fails without the fix, and the
update path was exercised against a real MariaDB rather than reasoned about.

- **A migration that returns rows poisoned the rest of the update.** A SELECT
  inside a migration - to check something before altering it - left its result
  set open on the connection, so the next statement failed with "unbuffered
  queries are active" and blamed the wrong line. Statements are drained now.
- **The backup only noticed a write that failed outright.** A disk filling up
  produces a short write instead, which left a truncated dump named as though it
  were complete. It counts the bytes and flushes before naming the file.
- **The row-count guard covered ten tables** and the portal has grown: it now
  also holds enrolments, attendance, invoices, invoice lines, payment proofs,
  message files and consent records. Proven with a migration that deletes
  attendance: before, it passed; now the portal stays closed and the copy taken
  moments earlier brings the rows back.
- **A charge could be created already overdue.** A child joining in the second
  month of a quarter got one dated to the start of the quarter, with the
  automatic reminder to match. The due date never precedes the month the charge
  is written in.
- **"What happens when somebody joins mid-period" was deciding the leaving case
  too**, silently: a child who left on the 15th cost nothing under "not until
  the next period" and a whole period under "the whole period". Leaving is
  charged by the days they were there, whatever the joining rule says.
- **An invoice for nothing** - a welcome discount that took a charge to zero -
  stayed open for ever and then turned overdue, asking a family to transfer
  0,00 €.
- **The last place in a course could be given away twice**: capacity was checked
  when a family asked and not when the trainer said yes.
- **The problem-report form accepted a post from nobody**, with a file attached.
  It is only ever drawn for somebody signed in; now the action says so, and one
  account cannot fill the disk with reports.
- **Uploaded files were never removed when their record went.** A deleted
  account took its conversation with it and left the voice notes on disk for
  ever. The nightly maintenance sweeps files nothing points at, leaving anything
  younger than an hour alone.
- **A tariff could point at a course that no longer exists** - invisible on every
  course page and absent from the unattached list. A tariff now becomes
  unattached when its course is deleted, because what a charge was priced by has
  to stay readable.
- **One invoice could carry two bank accounts.** Charges from courses that
  collect into different accounts were put on one document, which printed the
  first one's IBAN and quietly billed the rest to it. They have to be issued
  separately now, and the message says so.
- **The automatic charges said "on the 1st" and were not.** The portal has page
  views, not a clock: they are created once a month, on the first page view of
  that month. The setting now says that, and so does the README.
- Downloads are streamed rather than read into memory first, and `bin/update.sh`
  no longer follows the default branch from a checkout pinned to a tag.

The suite grew with it: every page is now opened for every role with data behind
it, a PHP warning in a view is a failure rather than something printed between
two table cells, and the router's four permission lists are checked against a
written-down expectation, so a new page cannot be added without saying who may
open it.

- **Install by `git clone`, update with one command.** `bin/update.sh` pulls,
  installs the dependencies, applies the migrations and prints the status; it
  refuses on an uncommitted change rather than discarding work, and does the
  database half by calling the same console command the browser does. The
  version is now written into the database as well as the files, so
  **Einstellungen → System** can say whether the two agree, and
  `bin/update.sh --check` answers the same question from a shell.
  README says plainly that no ZIP is published anywhere yet, because there is no
  release: somebody with a shell builds it with `bin/release.sh`.
- **Example data, one button.** **Einstellungen → System → „Beispieldaten
  anlegen"** fills a portal with three courses, fifteen children aged 7 to 41,
  contacts, enrolments, charges, payments, attendance, absences, news and a
  conversation, so the app can be tried before it holds anybody real. It refuses
  to run twice, and refuses to mix into real students. „Beispieldaten entfernen"
  takes all of it out again.
- **A rejected form no longer empties itself.** A euro sign in a number field
  used to cost the whole page of typing. What was entered comes back, the error
  sits beside the field that caused it, and passwords are deliberately not
  refilled. A field left blank to inherit a price now shows the price it would
  inherit, marked as a default, with a button that puts the default back and
  names the value it is putting back.
- **Two groupings instead of one detailed one.** Skill assessment with scales
  and dated values is replaced by **Leistungsgruppen** — Anfänger,
  Fortgeschritten, Könner, renameable and extendable, one of them the default a
  new child starts in — and **Altersgruppen** (Unter 12, Jugend, Erwachsene),
  worked out from the date of birth, overridable per child, and warned about
  when the bands leave a gap. Both live under **Verwaltung**, which is the
  trainer's own screen: settings stayed the administrator's.
- **The course is the one place a price lives.** A tariff belongs to exactly one
  course, a course has a timetable rather than a single weekday — several days a
  week, each with its own time and place — and a child can be in several
  courses, billed for each at the tariff their enrolment names.
- **Families can ask, and the trainer decides.** A child's page offers the
  courses with room in them; joining, leaving and changing tariff are requests
  that wait for the trainer's yes, counted beside **Kurse** in the menu.
- **Billing as she bills.** Monthly, every two, three or six months, or yearly;
  a first period prorated, charged whole, or skipped; a discount for a number of
  months (or unlimited) as a percentage or a fixed amount; a due day on the
  tariff that a child can override; and overdue a set number of days after that.
  The preview says what will be created, one line per enrolment, with a reason
  beside anybody skipped.
- **Invoices that hold up in Austria.** The operator's own details are entered
  once under **Einstellungen → Betrieb** and feed both the invoice and the
  privacy notice. An invoice carries what § 11 Abs 1 UStG asks for, numbers
  itself consecutively per year, states the § 6 Abs 1 Z 27 exemption for a
  Kleinunternehmer or shows net, rate and tax when VAT applies, and is generated
  as a PDF on request rather than stored. Open becomes overdue on its own; paid
  is the trainer's word and writes real payments behind it; cancelled keeps its
  number.
- **Attendance where she needs it.** One screen: pick the course, pick the day,
  every child in it, one tap each, one save — reachable from the menu and not
  only from inside a course. A child reported absent for that day carries the
  reason beside their name, as a note rather than a mark. The start page grew a
  timeline of what was, what is on today and what is next.
- **Named views.** A filtered list of children can be saved under a name and
  opened again as a chip, each one saying underneath what it selects.
- **A shell that stays where you put it.** The top bar is pinned, and carries
  the language switch, a notification pane, and who you are — once, instead of
  once at the top and once at the bottom. Profile pictures for accounts and
  children. A default colour set by the administrator that each person can
  override for themselves. Any page can report that something is wrong on it,
  with a screenshot, and the report arrives with the page, the device, the
  address and the version attached. An administrator or trainer can view the
  portal as somebody else to see what they see, with a bar saying so and a way
  back that works from inside the borrowed session; what they change is recorded
  against them, not against the person they were viewing as.
- **Messages in the shape people already know one.** Conversations down one
  side, bubbles down the other, one box with a paper clip and a microphone.
  Pictures, PDFs and voice notes, within a size limit that is never higher than
  what PHP itself accepts. Writing to the trainer needs nobody's permission;
  writing to another family needs theirs, asked for and agreed to. **A
  conversation between two families is private: neither the trainer nor the
  administrator can read it**, which the screen says in words. The bulk tool —
  filters, templates, a review step — is still there, on its own page.
- **The change log informs.** It says what changed, field by field, in the words
  she uses, storing only what actually differed. The undo is gone: a page that
  can put a record back is a page that can put a record back by accident, and
  the cost of keeping it was a copy of every record on every save.
- **The proof of payment is offered where it is easy.** A family with something
  outstanding is asked on the page they land on — „Schon überwiesen?" — with the
  upload one tap away, and told plainly that it is voluntary.
- **What may be customised now says so.** Custom fields are for students only,
  and the screen says why the rest — courses, tariffs, charges, invoices — has
  fixed fields, and where the lists that *are* hers to change live instead.
- **Every child has somebody to ring.** One contact is the standard one — the
  number you reach for and the address an invoice goes to — so it cannot be
  saved without an email, the last contact cannot be removed, and a child
  without one is named on the student list. An invoice for a family with no
  portal account is addressed to that contact.
- **A feature list with steps to test it** — [TESTING.md](TESTING.md) — for the
  administrator to walk after a code change or a release, alongside the
  automated suites, which now run 1957 assertions.

## 0.5.0 — unreleased

Installing and updating without a shell.

- **A browser installer.** `public/setup.php` checks what the server offers,
  takes the four database details the hosting panel handed out, writes
  `config/config.php` with a freshly generated encryption key, creates the
  schema, seeds the defaults and creates the first administrator. One page, one
  button. It refuses to run once an administrator exists, which is what closes
  it afterwards, and a request that arrives before there is a configuration is
  sent to it rather than to a dead end.
  Every failure is reported as an instruction: "the database user name or
  password is not correct", not `SQLSTATE[HY000] [1045]`. Where the server
  answers 1044 for both a missing database and an unassigned user, the message
  says both, because the server deliberately does not distinguish them.
  Re-running it never mints a new `app_key` while a usable one exists, and when
  `config/` is read-only it shows the exact file to create in the file manager
  instead of stopping.
- **Updates are: upload the new files.** The first page view afterwards compares
  the migration files against the ledger and applies anything outstanding, under
  an advisory lock so two visitors arriving together cannot both run them. On the
  common path it costs one file read and no database work. A failure leaves the
  portal closed and names the migration and the statement that stopped; the full
  database error goes to the error log rather than to whoever was looking.
  The migration runner now lives in `app/schema.php` and is the same code the
  console runs, so the two cannot disagree about what has been applied.
- **The web root may point at the project folder.** Shared hosting usually fixes
  it at `public_html` with no way to move it, so the `.htaccess` at the top
  rewrites every request into `public/`, and each folder beside it denies itself
  for hosting without `mod_rewrite`. A root `index.php` redirects when rewriting
  is unavailable entirely. The test suite checks that every directory beside
  `public/` is covered, including one added later.
- **No cron job is required.** Queued email, the nightly cleanup and — when
  switched on — the monthly charges run just after a page has been delivered, at
  most once a minute, behind a lock shared with any real cron job that is also
  configured. Off-switch and status under **Einstellungen → System**, which also
  reports the last background run and any migration still waiting.
- `bin/release.sh` builds the distribution ZIP with the dependencies bundled and
  the repositories composer leaves behind stripped out, so the upload is around
  half a megabyte instead of thirty-four, and proves the packaged autoloader
  still resolves PHPMailer and BaconQrCode before it writes the file.
- Creating the first administrator is one function used by both the installer
  and the console, and it checks that no administrator exists inside the
  transaction that writes the row.
- The escaping rule in the `structure` suite now covers `public/setup.php`, which
  prints before `core.php` exists and therefore carries its own escape function.
  It caught a nested ternary on the first run. A call guarded by its own
  `function_exists()` no longer counts as undefined, which is how a SAPI-only
  function like `fastcgi_finish_request()` can be used honestly.
- The installer's `app_url` is derived from the request that asks for it, and the
  `Host` header is checked against the shape of a host name first: it ends up in
  every invitation and password-reset link, so a visitor-chosen value would send
  those wherever they liked.

### What an update now refuses to do

- **Run against an older package.** A downgrade used to pass in silence: nothing
  is pending, so the update looked like a success and the portal then served old
  code against a newer schema. It is now refused by name, and the release does
  not mark itself as current.
- **Run against a half-finished upload.** The package ships a `MANIFEST` of every
  PHP and SQL file with its checksum. A file manager extracts a ZIP one file at a
  time and an FTP client in text mode rewrites the line endings of everything it
  copies; both leave a directory that lists perfectly. Checked only when a
  migration is pending, so it costs nothing on an ordinary page view, and skipped
  entirely for a git checkout, which ships no manifest.
- **Run without a backup.** A full SQL dump of every table goes to
  `storage/backups` before anything is migrated, written to a `.part` file and
  renamed only once complete so a truncated copy can never look restorable. If it
  cannot be written, nothing is migrated. The last five are kept. The folder
  denies itself over HTTP twice and each name carries four random bytes. An
  operator who has exported the database herself creates `storage/skip-backup`,
  which is consumed on use so it cannot disable the safeguard permanently.
- **Leave with fewer rows than it started with.** Counts across the ten tables a
  family would notice are compared before and after; a drop keeps the portal
  closed. This used to exist only in `console.php update`, so a portal updated by
  page view had no such check at all.

A failure now says what went wrong and what to do, in German and English, and
never prints SQL: that address is public and a parent may be the one looking at
it. `console.php update` runs the same code rather than its own copy.

Found by the MariaDB run and fixed: `backup_prune()` sorted by file name, so
several copies written in the same minute were ordered by their random suffix —
it could have deleted the copy just taken before a migration. Ordering is by age
now, and the names carry seconds rather than minutes.

## 0.4.0

Transactions, undo, and a test suite that runs anywhere.

- Every write is wrapped in one transaction that either completes or leaves
  nothing behind. Nesting uses savepoints, so a helper does not need to know
  whether its caller already opened a transaction, and an inner failure a caller
  chooses to handle no longer discards the outer work. Leaving a request with a
  transaction still open is logged rather than silently rolled back on teardown.
- A repeated form submission is refused by the database, not by a check that two
  simultaneous submissions could both pass.
- Record versioning with undo. Changes to fourteen tables store what the row
  looked like before and after, shown on an "Änderungen" page with a button to
  put each one back. An undo writes back only the columns that change touched,
  so undoing an old edit does not also undo every later one. Deleting a student
  is now a mistake to reverse rather than a restore from backup: the row is
  re-inserted under its original number so everything referring to it lines up
  again. The undo is itself recorded, so the history stays complete.
- Rate-limit counters are kept on a second connection, so a failed action still
  counts against the limit instead of rolling its own counter back.
- Balances for a list of students are resolved in one query. The student list
  went from one query per card — 56 with sixty students — to six for the page.
  The billing preview and run, and the dashboard's absent-students tile, had the
  same shape and were batched the same way.
- A test suite that needs no MySQL: `php tests/run.php` boots the real
  application against a disposable database built from the real migrations, and
  covers dates, transactions, billing, security, attendance, settings, history,
  query counts, the rendered pages and the shape of the source itself. This
  proves the PHP logic, not the MySQL dialect — see AUDIT.md.
- The rule for what counts as a received payment — confirmed and not voided —
  was written out in six places across three files, where every balance, the
  overdue filter and the payments screen each carried their own copy. It is now
  `payment_counts_sql()` / `charge_paid_sql()` in one place, producing the same
  SQL, and a test fails if it is spelled out again.
- One `sql_name()` replaces four hand-written checks on table, column and alias
  names that had drifted into three different patterns; the one guarding
  `lock_row()` rejected any table name containing a digit.
- `console.php version` no longer needs a configuration file. Asking which
  release a directory holds is something you do *before* linking a config into
  it, which is exactly when the old version refused to answer.
- README gained install and update guides as runnable bash, and CLAUDE.md
  records the conventions for changing this code.
- **The migrations and the whole test suite now run against a real database
  engine — MariaDB 10.11.14 — for the first time.** All six migrations apply,
  including the two `ADD CONSTRAINT` statements the SQLite translation had to
  skip; the upgrade path, undo with real row locks, and billing idempotency all
  behave on the real engine. `tests/mariadb-local.sh` repeats it from nothing on
  a machine with no database server. MySQL 8.0 itself is still untried.
- Fixed a defect this uncovered: the test harness could only run against a
  database with no tables. It dropped tables in a hand-kept order, which MySQL
  refuses when a child still references a parent — invisible on a fresh database,
  where every drop is a no-op. It now reads the table list from the database and
  switches the constraints off around the operation, on either engine.
- Two pages no longer grow a query per row: the student payments tab went from
  forty-three queries at thirty-six charges to six, and the skills tab from
  thirty-six at twenty skills to six. `charge_payment_profile()` also had a
  fallback chain that looked lazy but evaluated every candidate first, costing a
  lookup of class 0 on every charge.
- Migration 006.

## 0.3.0 — unreleased

Monthly charges, attendance, and a mobile-first pass.

- Monthly charges on the 1st of each month. The first time the calendar reaches
  a 1st after a student joins, that month is free; billing starts the month
  after. Being away changes nothing - absence and billing are deliberately
  unlinked. The trainer can pause billing for one student without ending their
  membership. Nothing is created until somebody runs it, from the payments
  screen after a preview, or from cron; every generated charge carries a unique
  key so a repeat run creates nothing.
- `billing:plan` shows what would happen and changes nothing; `billing:run`
  does it.
- Attendance per class and session date, with configurable statuses. Built for
  a phone held in one hand: the whole class on one screen, one tap per student,
  one save, and a bulk "everyone present" to correct from. Summary and recent
  sessions on each student's page.
- Mobile-first pass: the attendance control was rebuilt after measuring that
  five operator-defined labels truncated and overlapped at 390px. Choices now
  wrap instead of clipping, "not recorded" moved next to the name so the
  statuses fit one row, and the default status set is three so every label fits
  on one line at a readable size. A student's row went from 370px to 132px.
- Migration 005.

## 0.2.0 — unreleased

Roles, classes, payment QR codes and skill assessment.

- Roles are administrator, trainer and student. Administrators configure
  everything; trainers do the day-to-day work. `manager` is renamed to `trainer`
  by migration and still accepted on read.
- Training classes with a schedule, tariff, bank details and members. A student
  can belong to several; leaving is recorded rather than erased.
- Skill assessment for staff: configurable rating scales, skill areas, skills,
  dated values with notes, inline progress charts, and grouping into bands whose
  thresholds are a setting. Not visible to student accounts.
- Payment profiles with IBAN checked by its mod-97 checksum, and a transfer QR
  code for outstanding amounts. The payload comes from an editable template
  defaulting to the SEPA EPC069-12 format. Generated on the server; no external
  service is contacted.
- Payment reminder email as a third notification category, with its own
  preference and unsubscribe link.
- Online status per account.
- Maintenance mode switchable from the settings screen, with an administrator
  bypass so it cannot lock the operator out.
- `php bin/console.php update`: maintenance on, migrate, compare record counts,
  maintenance off, refusing to reopen if any count dropped. `create-admin` gains
  `--force` for a deliberate second administrator.
- Every operator setting is declared once in `app/defaults.php` with a type and
  a default, and rendered from that declaration, so a missing row is never
  undefined and a new setting needs no migration.
- Plainer wording throughout, and a parent's dashboard reduced to what concerns
  them.
- Migrations 004.

## 0.1.0 review — included in 0.2.0

Review of 0.1.0: bug, security and design findings and their fixes. Full detail
in AUDIT.md, priorities in ROADMAP.md.

Security:

- Rate limit counters are written on a separate connection, so a failed attempt
  no longer rolls back the hit that counts it.
- The unsubscribe category is mapped through a whitelist instead of being
  interpolated into the SET clause.
- Passwords are checked against a small blocklist and a repetition rule, not
  length alone.
- SMTP password redaction no longer aborts a mail run when the app key differs.
- Added Cross-Origin-Opener-Policy, Cross-Origin-Resource-Policy and
  X-Permitted-Cross-Domain-Policies, plus per-directory deny rules.

Fixed:

- Stored UTC timestamps were relabelled rather than converted, so records
  written late in the day showed the previous date.
- Failed email stayed failed; it now retries with backoff, security mail
  excepted.
- current_user() is resolved once per request instead of per call.
- A mail run started from the outbox now respects max_execution_time.
- fmt_date() no longer raises a TypeError on an unparseable value.
- The migration runner no longer splits on a semicolon inside a quoted string.
- A repeated form submission reports a repeated submission.
- An empty student status is rejected on create; account deletion accepts a
  correctly typed email in any capitalisation; `check` works before the first
  migrate.

Added:

- Dark appearance following the device, with per-account appearance and text
  size settings.
- Web app manifest, Apple touch icons and meta tags, so the portal installs to
  a phone home screen as an app.
- Unread markers per account in the sidebar, mobile tab bar and conversation
  list.
- Migration 002 (mail retry state, read markers, indexes for the queries the
  application issues) and migration 003 (appearance preferences).
- Touch targets raised to a 44pt minimum at phone widths.

## 0.1.0 — 2026-09-15

Initial PHP/MySQL release for self-hosting.

- Invitation-only verified accounts, multiple students per account, roles, suspension and account deletion.
- Student records, contacts, dates, absence reporting, configurable fields and archived values.
- Tariffs, individual prices, manual charges and confirmed/partial payments.
- Private messages, filtered recipient previews, editable templates and news subscriptions.
- SMTP queue, outgoing status, encrypted credentials and unsubscribe links.
- German/English responsive interface and editable privacy drafts.
- Migration `001_initial.sql`, maintenance switch, count/total checks and documented update procedure.

The initial migration is for an empty application database. No migration from a previous JavaScript/Sites implementation is included. No live student data has been imported into this release.
