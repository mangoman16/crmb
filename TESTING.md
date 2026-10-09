# Testing

What this portal does, and how to prove each part of it still works. The checks
are in the order you would naturally walk through the app, and each one says
what to do and what you should see.

German labels are quoted the way the portal shows them, because the portal is
German by default and searching for the English word will not find the button.

## Start here

**You do not have to do this whole list every time.** You test the portal the
way you use it — on your phone, signed in as yourself. Most of this file is for
looking things up.

**After every update** — about fifteen minutes, on your phone:

1. Walk [the five-minute sweep](#the-five-minute-sweep--after-every-update).
2. Walk [what changed in this update](#what-changed-in-this-update--check-these),
   leaving out every check marked **(release)**. All fine: **you are done.**

Checks marked **(release)** are for whoever makes the release: they need a second
copy of the portal, a shell or the database. So do [the automatic tests](#the-test-commands-in-detail),
which that person runs before handing an update to you.

**When something specific changed**, the top entry of `CHANGELOG.md` names it.
Walk the matching section as well, and no other:

- signing in, passwords, accounts → [Sign in, roles and access](#sign-in-roles-and-access)
- the top bar, the bell, the report button, colours → [The shell](#the-shell-the-bar-notifications-feedback-impersonation), [Appearance](#appearance-and-personal-preferences)
- children and contacts → [Students and contacts](#students-contacts-levels-and-age-groups)
- courses, prices, joining, attendance → [Courses](#courses-dates-and-tariffs), [Enrolment](#enrolment-asked-for-and-decided), [Attendance](#attendance)
- money → [Charges](#charges-and-billing), [Payments](#payments-and-proof), [Invoices](#invoices)
- messages, news, email → [Messages](#messages), [News and email](#news-email-and-the-queue)
- installing, updating, backups → [Installation and update](#installation-and-update), [Data safety](#data-safety)

**The whole list** — [twenty minutes to an invoice](#twenty-minutes-from-nothing-to-an-invoice),
then everything from [Preparation](#preparation-for-the-full-sweep) down — before an update
you would not want to roll back, and once a term. It installs from empty and issues
invoices: use a second copy of the portal, never the one with the families in it.

**When a check fails**, note its number (1.4, say), tap „Etwas funktioniert hier
nicht" at the bottom of that page (phone: **Mehr → Etwas funktioniert nicht**), and
write the number and what you saw; the page goes with it by itself. Cannot sign
in at all? Then there is no button: email the number. Carry on where you can.

---

## The five-minute sweep — after every update

On your phone, as the administrator. Six checks that catch a broken update, with
nothing to read first. Only 1.4 changes anything, and you put it back.

- [ ] **1.1** Open the portal in a private tab (Safari: the tabs button, then
  **Privat**), so you arrive signed out. The club's name is at the top and a
  card headed **Anmelden** below it, in the portal's colours — not black text on
  a white page, and not a blank page.
- [ ] **1.2** Sign in. The page says **Hallo** and your first name, with
  **Aktive Schüler** and three more figures under it, your initials at the top
  right, and no red box anywhere. Scroll down: the bar at the top
  stays where it is.
- [ ] **1.3** Tap **Schüler** in the bar at the bottom, then any child. Their
  page opens with their name as the heading and the boxes under **Persönliche
  Daten** filled in.
- [ ] **1.4** Further down the same page, tap **Interne Notizen**, add one word
  at the end, and tap **Schüler speichern**. A green box says **„Schüler
  gespeichert."** and your word is there. Take the word out and save again.
- [ ] **1.5** Tap **Mehr** at the bottom right, then **Einstellungen**. Swipe the
  row of tabs under the cards to the left and tap the last one, **System**. Under **Installation** it says **„Dateien und Datenbank gehören zur
  selben Version."**, and **Version der Dateien** and **Version in der
  Datenbank** show the same number — the one at the top of the release notes.
  A yellow box, or any other sentence, fails this check.
- [ ] **1.6** Tap **Chats** in the bar at the bottom. The page **Chats** opens
  with its list of **Unterhaltungen**; tap one and its messages appear. With
  none yet it says „Noch keine Nachrichten.", and that passes too.

---

## What changed in this update — check these

Each line is one change; its checks are in
[New in this release](#new-in-this-release--the-checks-in-detail). Walk the ones
not marked **(release)**, straight after the five-minute sweep. Filled in for each
release and emptied again for the next.

- Setup needs PHP's `fileinfo` extension, and **Einstellungen → System** names any required extension a portal has lost: [3.1a](#installation-and-update)
- A shorter menu of seven, a hub at the top of **Einstellungen**, links at the top of **Chats** and **Geld**: U.45–U.52
- A start checklist, **„Einrichtung"**, until the portal is ready: U.31–U.39
- The rarely needed fields wait under **„Mehr Möglichkeiten"**, VAT fields only with VAT: U.53–U.55
- One login is one student; invitations need a passing mail test: U.20–U.28b, U.57
- A problem report says how she got there; errors report themselves: U.13–U.19, U.40–U.44
- The portal's own icon: U.2–U.8 · the sign-in line: U.1 · rows that line up: U.9–U.12
- The privacy notice in English is optional: U.35, U.56
- News by email starts switched on for a new login, and can be switched off when accepting the invitation: [15.2, 15.7](#news-email-and-the-queue), in News and email
- A news item, once published, is in every active family's bell; an edit tells nobody again, and unpublishing takes it back: [15.8–15.10](#news-email-and-the-queue), in News and email
- The bell no longer jumps when opened, its panel stays on a phone's screen, its number is a red badge like the one on **Chats**, and a tap elsewhere or Escape closes it: [5.3a–5.3g](#the-shell-the-bar-notifications-feedback-impersonation), in The shell
- Your picture or initials at the top right open a menu — your name, „Mein Konto", „Abmelden" — checked on the iPhone, at 320 and without JavaScript: [5.3i, 5.3j](#the-shell-the-bar-notifications-feedback-impersonation) · with the bell, only one open at a time: 5.3f
- The club's own colours and logo under **Einstellungen → Portal**, cards „Aussehen" and „Logo": [6.7–6.20](#the-clubs-colours-and-logo-einstellungen--portal), in Appearance
- After an update the browser fetches the new stylesheet and script by itself: the account menu is styled and the bell stays still without clearing the cache: A.0
- Everybody signs in with their own e-mail address, and nobody else's. A person is added either by inviting an address — they fill in their own details and choose a course — or through the wizard „Schüler anlegen"; nobody sets another person's password (ADR 0021, amended by ADR 0023 and ADR 0030): A.1–A.13
- Families fill in their own details, and every change is in the change log (ADR 0020): A.14–A.18, A.20–A.22, A.25, A.27
- The chat works like a messenger: a group for every course, chats with one person; looking through somebody's eyes shows no chat notice in the bell and offers nothing to write; a stored photo keeps only the picture (ADR 0022): C.1–C.6, C.10–C.15, C.17–C.20
- Billing and invoices after the review of October 2026: an archived tariff stays on a child, a cancelled charge can be charged again, „Als bezahlt eintragen" confirms rather than doubles, a charge on an invoice cannot be cancelled, an invoice is not e-mailed to a family who said no, the invoices page counts every invoice, a membership ending mid-month is charged to that day, a child coming back starts afresh, the age filter finds the right children, background charges are German and nobody's, and the „Zahlungsziel" setting that did nothing is gone: [B.1–B.14](#billing-and-invoices-after-the-review-of-october-2026)
- Payment reminders: one email per child listing every overdue charge with the total, in the family's language, never twice on the same day, and back on **Geld › Überfällig**: [11.12–11.17](#charges-and-billing)
- Every student has a login — „Ohne Anmeldung" until somebody enters its address and sends the invitation; a wizard „Schüler anlegen" is the one way to add a child; deleting a child's login gives them a fresh, empty one; after the update a login that signed in by a username is „Ohne Anmeldung" and an old sign-in link opens nothing (ADR 0023, amended by ADR 0030): [L.1–L.25a](#every-student-has-a-login-and-the-wizard-adr-0023-amended-by-adr-0030)
- The screens of ADR 0030: the card „Zugang zum Portal" sends the invitation in one tap and comes back at itself, a shared address is said before anybody taps, „Zugänge" in groups with four chips, Mein Konto with the address and the three switches, the address keyboard, the viewing strip on the public pages, and all of it without JavaScript: L.9a, L.9b, [Z.1–Z.6](#the-screens-of-adr-0030-on-a-phone)
- A form sent twice — a double tap, or the same form sent again after Back — lands where the first one went, with the first one's message, and makes nothing twice: [L.10b, L.10c](#every-student-has-a-login-and-the-wizard-adr-0023-amended-by-adr-0030) in the wizard
- Viewing the portal as somebody else is looking only: everything except „Ansicht beenden" and „Abmelden" is refused, with one sentence: [5.11–5.11b](#the-shell-the-bar-notifications-feedback-impersonation), C.15, C.18
- What the reviews of ADR 0023 found: two people cannot both take a course's last place, a child without sign-in collects no bell notices, a browser shared between people forgets a half-opened invitation, a team member's login left on a child's record lets go of the child and stays hers, and a page opened with a list in its address draws without a warning: [L.10a, L.20b, L.20c, L.21a, L.25a](#every-student-has-a-login-and-the-wizard-adr-0023-amended-by-adr-0030), [7.23](#students-contacts-levels-and-age-groups)
- A view through somebody's eyes ends with the viewer's own login — deleted, suspended or given a new password, the browser looking is signed out on its next tap; a trainer is told an administrator releases the privacy notice: [5.11c](#the-shell-the-bar-notifications-feedback-impersonation), [L.20a](#every-student-has-a-login-and-the-wizard-adr-0023-amended-by-adr-0030)
- What ADR 0026 takes out, round one: custom fields with what was typed into them, copying, saved views, writing to many with its templates, „Warteschlange senden", the printed form and data sheet, and Verwaltung's „Tarife" tab: [R.1–R.8](#what-adr-0026-removes-round-one)
- What ADR 0026 takes out, round two: the online dots, the status and when somebody was online; the status emoji; profile pictures, whose files the update deletes for good; asking to write to another family; voice notes and files in new messages, which are text and photos, a family's from the camera: [R.9–R.17](#what-adr-0026-removes-round-two), and 5.3i, 5.3j, 14.1–14.7, 20.4, 21.7
- Age groups from the birth date alone — nothing to pin, one rule everywhere, bands that overlap still agree — and the example data cut to one course, four children and three sign-ins that work for 14 days: [N.1–N.7](#age-groups-from-the-birth-date-alone-and-the-small-example-data-round-three), 2.1, 3.4a, 3.4c, 7.2, 7.3
- No white flash in dark mode: every page says light or dark before its stylesheet arrives, the installer and the page saying the portal is not available too — on the iPhone: I.12
- Waiting for a page: the tapped link stays pressed, and a page slower than half a second gets the waiting page, carried on into the next page with no cut, and „Abbrechen" after 6 s; files open in the home-screen app without signing in again; the shuttlecock as the portal's mark and home-screen icon, and a club's see-through icon visible in dark mode: [W.1–W.5](#waiting-for-a-page-and-the-shuttlecock-design-language-04b-and-c16a), 6.8, 6.20
- The students list for a phone: a search of its own that keeps the selection, „Alle | Überfällig | Krank", the other filters folded under „Filter", „A–Z | Nach Alter" with a card per age group and its count, rows without a price and „Ohne Kurs" for a child in no course, and „Per E-Mail einladen" reached from the wizard's first step: [17.1–17.8](#the-students-list), A.7, N.4, R.4
- A restore keeps the portal closed until its import is done: every copy makes `import_unfinished` first and drops it last, nothing is run, copied or swept meanwhile, the closed page reloads itself, an import that stopped is imported again: [H.1–H.5](#a-restore-keeps-the-portal-closed-until-its-import-is-done-adr-0029), 21.8, G.7, G.9
- After the review of round two: a backup restored with a page opened halfway keeps every upload; a chat photo or a receipt downloads under the type it really is, whatever its name said; a family sending anything but a JPEG is asked to take the photo with the camera: [21.8](#data-safety), [12.9](#payments-and-proof), [14.12](#messages), 14.2, R.12
- An update that lost records keeps the portal closed, for everybody, on every page view, until the rows are back; the way back is the previous version's files, then the copy from before, in phpMyAdmin (ADR 0027): [G.1–G.9](#an-update-that-lost-records-stays-closed-adr-0027)
- Any value from anyone is answered with one sentence on the same page: numbers, dates and pages held to their range, a family writes only to the trainer team, and nothing reaches the error log: [V.1–V.11](#any-value-from-anyone-adr-0026-5), 14.5, 14.7
- How long the portal keeps what it holds: ten periods under **Einstellungen → System**, the daily cleanup deletes what is past them with their files, and never an invoice, a charge or a payment: [D.1–D.7](#how-long-the-portal-keeps-what-it-holds-adr-0032)
- The security review of 2026-10-08: a family's login is called what the child is, and Mein Konto no longer renames it; a chat mails once until it is read; twenty chat photos an hour per family's login, staff not counted; administrators hear of every change to where the money goes; the QR code is a SEPA transfer into the account shown; a changed address or password is told by mail; a chat photo keeps no file name; the outbox forgets what a sent mail said after 90 days: [S.7–S.14](#the-security-batch-roadmap-item-6), S.3
- The portal looks and behaves like an iPhone app: the phone's own font, grouped lists, a tab bar (Übersicht · Schüler · Anwesend · Chats · Mehr for staff, Übersicht · Beiträge · Chats · Profil for a family), red counts, switches, sheets, a back button, pages that fade; „Mehr" as a page; Mein Konto for a family through Profil, the language in Mein Konto — on a real iPhone: [I.1–I.11](#the-portal-as-an-iphone-app-design-language-phase-1), and 1.6, 5.0a, 5.1b, 5.3c, 6.10, U.46, U.47, U.48, U.52, U.56
- The design audit's first fixes: red only once a charge is late; less to read on a child's Profil, and „Im Verein seit"; a family's overview opens with the greeting and the face, and shows no past dates; the chat page is „Chats"; the sign-in page says where to read about your data, with one link: [M.1–M.5](#the-design-audits-first-fixes-n1-n5-n6-n7), 4.7a, U.1 · **Geld** with „Alle" and „Überfällig", the reminder as a sheet that counts first, and „Monatsbeiträge" after the list: M.6–M.11
- Pictures come back (ADR 0031): a child's, added by the family or the trainer, also from **Anwesenheit**; the team's own on **Mein Konto**; the course sees a child's once the family says yes, a parent's under 14; „Dein Foto" once, after the first password; JPEG and PNG only, up to 24 megapixels; at **Anwesenheit** „Mehr" for the rarer marks and Enter that saves; the child's face beside the greeting: [P.1–P.20](#pictures-adr-0031)

---

## The test commands, in detail

```bash
tests/mariadb-local.sh            # the whole suite against a throwaway MariaDB it starts itself
tests/existing-database.sh        # the same suite against an empty _test database you made
```

The run must end in **`0 failed`**. It takes about four minutes.
Anything else and the manual sweep is a waste of time — stop and report that
first.

Run it over SSH in the Git copy (the upload ZIP leaves `tests/` out). Its second
line must read `files: /tmp/crm-test-…`: those tests keep everything they write
in that folder and refuse every database but a `_test` one, so the live folder is
safe to run them in. No such line means older tests that write into the portal's
own files: run those from a copy, never inside the live `public_html`.

`tests/existing-database.sh` is the real-engine run for shared hosting. Once, in
the hosting panel, create a new, **empty** database whose name ends in `_test`
(the panel puts your account name in front, as in `konto_crm_test`) and a user
for it. The first run asks for that name, the user, the password, the server and
the port, and keeps them in `tests/.test-database.php`, readable by you alone;
later runs just start. It refuses a name that does not end in `_test`, the
database the portal itself uses, and any database holding an account with a real
email address; the first time, also any that is not empty. The suite deletes
every table in the database it is given, so those refusals are the point. Delete
that file to use a different database. The first lines it prints name the
database server; that is the engine the run has proven.

`tests/mariadb-local.sh` starts a throwaway database server of its own, which
needs `mariadbd` installed. Shared hosting does not have it; use
`tests/existing-database.sh` there.

The run ends by printing anything it could **not** cover; a run with nothing to
add prints nothing there. The data carried across by the migrations is checked
in a second process, which applies them in steps with rows in between, on a
second, empty `_test` database: `tests/mariadb-local.sh` makes one, and
`tests/existing-database.sh` cannot, so there that check is the line the run
prints. Those lines are not a warning, they are the honest edge of the
measurement.

| Suite | What it holds the line on |
|---|---|
| `accounts` | One login is one student: invitations, own addresses, the access card's states |
| `attendance` | Statuses, the suggested training day, the summary figures |
| `billing` | Charge periods, intervals, discounts, proration, due and overdue dates |
| `brand_pages` | The club's colours, logo and icon as the pages draw them |
| `colour` | The colour arithmetic: text on and with the club's colours stays readable |
| `contacts` | Somebody to ring, one standard contact, and an address to invoice |
| `dates` | UTC to local conversion, money as integer cents |
| `demo` | Example data fills, is recognisable, and comes out again completely |
| `pages` | Every page opens for every role with data behind it, warnings included |
| `enrolment` | Timetables, joining and leaving, who decides |
| `errors` | Unexpected errors written down once, counted, told to the administrators, and passed on without personal data |
| `forms` | A rejected form comes back filled in; defaults are visible as defaults |
| `groups` | Levels and age groups, and the difference between them |
| `history` | The change log records what differed, and only that |
| `install` | The browser installer, migrations applying themselves, the refusals |
| `invoices` | § 11 UStG details in the produced document, numbering, status |
| `logins` | Every student has a login; the wizard; signing in by address alone (ADR 0023, 0030) |
| `messaging` | Who may read a conversation and who may write to whom |
| `migrations` | An update carries the data with it: prices, discounts, addresses |
| `performance` | Query counts, so a page does not issue one query per row |
| `pictures` | Who sees a picture, who adds, changes and removes it, the family's yes for the course and whose yes it is by age, and what goes with a child or a login (ADR 0031) |
| `reports` | A problem report's trail of steps, and nothing in it that must never be kept |
| `retention` | Each period deletes what is past it and nothing short of it, files included; never an accounting record (ADR 0032) |
| `robustness` | Every form sent every kind of nonsense by every kind of person: one sentence back, never an error page, a warning or a write to somebody else's rows |
| `security` | Authorisation boundaries, credentials, what must not leak |
| `selfservice` | Families completing their own details, and every change in the change log |
| `settings` | Every setting has a type and a usable default |
| `shell` | Notifications, impersonation, initials, themes, feedback, the menus, the bar at the bottom, „Mehr" and the back button |
| `start` | The start checklist: each tick read from the data, example data never counting, the way on and back |
| `structure` | That no file has been silently destroyed, every value is escaped, every page classified |
| `transactions` | A failed write leaves nothing behind |
| `uploads` | Limits, allowed kinds, and files swept once their record has gone |
| `views` | The real pages render and say what they are supposed to say |

Run one on its own while working: `tests/mariadb-local.sh billing invoices`.

### The layout check, in a real browser

The PHP suite renders pages and reads the HTML. It cannot tell you that a button
is 32 pixels tall or that a card is wider than the screen, because neither of
those is in the HTML — they are in the stylesheet, and only a browser knows.

```bash
node tests/mobile.mjs --admin <admin email> --family <family email> --password '<password>'
```

It opens every page for both roles at **320 and 390 CSS pixels**, in light and
dark, plus the signed-out pages, and fails on:

- anything wider than the screen, or a page that had to zoom out to fit
- a link, button, tab or chip shorter than **44pt**
- text below 12px
- a JavaScript error, or a resource the page asked for and did not get

It needs Playwright and a Chromium (`npm i -g playwright`, or set
`PLAYWRIGHT_PATH`), a portal with example data in it, and an account it can sign
in with. It only ever reads: every page is opened with GET.

> Sign-ins are rate-limited, as they should be, so the script signs in once per
> role. If it reports that it could not sign in, wait fifteen minutes or clear
> `rate_limits`.

### The first evening, end to end (release)

```bash
tests/e2e.sh                        # the working tree
CRM_E2E_REF=HEAD tests/e2e.sh       # one commit exactly
CRM_E2E_ZIP=../badminton-crm-0.6.0-beta.2.zip tests/e2e.sh   # the package you will hand out
```

It installs a fresh copy through `setup.php` against its own MariaDB, walks the
nine steps of „Dein Portal einrichten" through their own buttons to „Alles
eingerichtet", invites a family and follows the link out of the captured mail,
has the family upload a proof and send „Etwas funktioniert hier nicht", confirms
the payment, issues an invoice and parses its PDF, and breaks a table on purpose
to check the friendly page and Rückmeldungen. It must end in **`RESULT: PASS`**;
its first lines name the commit — for a package, the package, its `VERSION` and
the commit it was built from, out of its `BUILD.txt` — the MariaDB and the PHP
it ran on, and those are the only ones it has proven. Before handing a package out,
walk that package, not only its commit: only the package has its own `vendor/`
and leaves out what `bin/release.sh` does not ship. **Notes** at the end are things the design
intends that are worth a decision; they do not fail it. Details:
[tests/README.md](tests/README.md#the-first-evening-end-to-end-in-a-browser), in
the Git copy: the package leaves `tests/` out, and the walk runs from a checkout.

**Last walked:** a package built by the new `bin/release.sh` from ead038a with
the delivery batch applied, on 08.10.2026, `RESULT: PASS`, 381 checks, against
MariaDB 10.11.14 with PHP 8.4.26 (`php -S`), in Chromium at 390px and 320px
([VALIDATION.md](VALIDATION.md)). The U.x checks it walks are
marked below where they stand: ticked when the walk covers the whole check and it
is one for whoever makes the release, annotated when it covers part, or when the
check is yours to do on your own phone. The annotations name the commit they were
first written for, 91520db; the walk still covers them.

It stands in for a phone, not for these, which stay by hand when the change
touches them:

- [ ] **T.1** The invoice PDF the run saves (`invoice.pdf` in its work folder,
  printed at the end) opened in Preview or Adobe Reader and on the iPhone: one
  page, the address block, the amount, the IBAN in groups of four.
  *Not done. At d095ca4 the walk parsed `invoice.pdf` and read its amount and its Leistungszeitraum, 04.10.2026 – 31.10.2026. That is a parser, not a reader.*
- [ ] **T.2** The invitation through the real mail provider, opened on an
  iPhone in Mail: the link opens „Konto einrichten" in Safari, not a blank page.
  *Not done. At d095ca4 the walk received the invitations through a local SMTP sink, not a real provider.*
- [ ] **T.3** On the iPhone, as the family: the bell at the top right shows the
  bell alone. (Chromium draws a small ▸ above it; check whether Safari does.)
  *Not done. No iPhone here; Chromium at 91520db still draws the ▸.*

---

## Twenty minutes, from nothing to an invoice

The script to follow when you want to see the whole thing work, in order, with
nothing to invent as you go. Everything it needs is either created by the
installer or typed in below. Sections 3 onwards are the exhaustive list; this is
the path through it.

**0 · Put it up.** Open `setup.php`, give it the database details, a name, an
address and a password — and **tick „Beispieldaten anlegen"**. The finish page
prints three sign-ins and one password for all of them — four made-up words run
together, such as `KemoTapiRunaSofe` — and says the accounts work for 14 days;
write the password down, it is not shown again. (From a shell: `php
bin/console.php demo:fill` prints the same three, the password and the 14 days.)

| You sign in as | Address | What you are testing |
|---|---|---|
| Administrator | the address you just chose | everything |
| `trainerin@beispiel.test` | trainer | what a trainer may *not* see |
| `lena.hofer@beispiel.test` | family | what a parent sees |
| `jonas.berger@beispiel.test` | family | a second family, to prove separation |

**1 · Look around as the administrator.** The overview shows active children,
outstanding money and this week's sessions. Nothing is empty, nothing says
„Noch keine …".

**2 · Make a course the way a club does.** **Kurse → + Kurs anlegen**: name it
`Training mit TrainerIn`, a Wednesday, 18:00 to 20:00. Save.

**3 · Put its real price list in.** On the course, **Tarife → + Neu**. Make
`Erwachsene`, press **+ Weitere Zahlungsweise** once, and enter *12 Monate ·
252* and *6 Monate · 162*. Usual interval: jährlich. Wer mittendrin einsteigt:
**anteilig nach vollen Monaten**. Add a discount template `Partnerschule` ·
dauerhaft · Prozent · 20. Save. The list should read
„252,00 € jährlich · 162,00 € alle 6 Monate".

**4 · Add a member.** **Schüler → + Schüler anlegen**: first and last name, a
date of birth, „Noch keinen Kurs", aktiv, **Weiter**. On step 2 choose
**„Ohne Anmeldung anlegen"**; then **Zur Seite von …**, which lists what is still
to do. Fill in the **Anschrift** and **Telefonnummer**, and add an emergency
contact.

**5 · Enrol them and check the arithmetic.** On the child, **Kurse → In einen
Kurs eintragen**, pick the course and `Erwachsene`. Open *Tarif, Zahlungsweise
und Rabatt*, set **Dabei seit** to the 12th of last November and Zahlungsweise
to jährlich. Save. Then **Geld → „Monatsbeiträge"**, set **Monat** to that
November and „Monat wechseln": it should offer **42,00 €** — November and
December of a 252 € year. Create it.

**6 · Invoice it, and watch it refuse first.** On the child, **Rechnungen**.
It will say what is missing: the operator's name and address under
**Einstellungen → Betrieb**, and the IBAN on **Verwaltung → Zahlungsempfänger →
Vereinskonto**. Fill both in, come back, and issue the invoice. Download the
PDF: one page, the right Leistungszeitraum (12.11. – 31.12., not the whole
year), the IBAN in groups of four, and the exemption note.

**7 · Be somebody else.** Open the child that `jonas.berger@beispiel.test`
signs in for, and on its card **„Zugang zum Portal"** choose **„Portal als …
ansehen"**. A red strip names whose eyes you are using; the portal shows that one
child only. **Ansicht beenden** gives you yourself back.

**8 · Sign in as a family for real.** Sign out, sign in as
`lena.hofer@beispiel.test`. The bar at the bottom reads Übersicht, Beiträge,
Chats, **Profil**, and **Profil** opens their one child; one login is one child,
so there is no list of children. Their own charges only. Try
`?page=student&id=` with a number that is not theirs: it answers 404.

**9 · Put it back.** **Einstellungen → System → „Beispieldaten entfernen"**.
Every invented child, course and charge goes; anything you made yourself stays.

---

## Preparation for the full sweep

- [ ] **2.1** **Einstellungen → System → „Beispieldaten anlegen"** on a portal
  with no real students, or the box on the setup page, which does the same thing
  at install and saves the trip. It creates one course, „Kindertraining", and
  four children — Lena Hofer (9) and Jonas Berger (10), whose families have the
  two example logins, Mia Gruber without a date of birth, and Elias Wagner
  (13) — each with a contact; charges with one paid, one open, one overdue and
  one recorded but not confirmed; a request to join waiting; a sick note;
  attendance on the last two training days; a news item and a chat. Nothing in
  it is drawn at random but the password, so it reads the same on any day. The
  page then says example data is present.
- [ ] **2.2** Press it a second time. It refuses, and says so. It does not
  create a second set.
- [ ] **2.3** Note the password the screen gives you for the example accounts —
  you need it for the family checks further down.

> Never fill example data into a portal holding real students. The button
> refuses unless you tick „Mir ist klar, dass sich Beispieldaten unter die
> echten Schüler mischen." — so the rule is yours to keep: do not tick it.

---

## Installation and update

Skip on an ordinary code change; do all of it before a release.

- [ ] **3.1** Fresh install: empty database, unpacked files, open the address.
  The setup page appears.
- [ ] **3.1a** On the second copy, never the portal with the families in it, and
  only if the hosting panel lets you: switch PHP's `fileinfo` extension off.
  Opened before installing, the setup page lists „Fotos und Belege hochladen
  (fileinfo)" among what is missing and does not install. On an installed copy,
  **Einstellungen → System** shows „Es fehlt: Fotos und Belege hochladen
  (fileinfo)" with what to do. Switch it back on afterwards. The same for
  `iconv`, `ctype` and `filter`, each named with what it is for. A panel that
  offers no such switch: write that down.
- [ ] **3.2** Enter a wrong database password. It is reported in words, and
  nothing is written.
- [ ] **3.3** Enter two different account passwords. Same: reported, nothing
  written.
- [ ] **3.4** Correct details. `config/config.php` is written, every migration
  is recorded, exactly one administrator exists, and the sign-in page is served.
- [ ] **3.4a** Tick **„Beispieldaten anlegen"** on the setup page. The finish
  page reports what was made — one course, four children, six charges — and
  prints three sign-ins with one password for all of them: eight syllables, four
  made-up words each starting with a capital, such as `KemoTapiRunaSofe`, typed
  on a phone's letters alone. It says the accounts work for 14 days. That
  password is shown once and never again.
- [ ] **3.4b** The same install with the box unticked creates nothing but the
  administrator, which is what a portal about to hold real data wants.
- [ ] **3.4c** `php bin/console.php demo:fill` prints the three addresses and
  the password too, and that they sign in for 14 days. (It used to say „the
  password printed above" and print no password, which left three accounts
  nobody could sign in to.)
- [ ] **3.4d** Two tabs on `setup.php`, and the honest limit of what one person
  can prove here. Open the setup page in two tabs, fill both in completely with
  **different** administrator addresses, then submit the first and afterwards
  the second. The second tab answers **„Schon eingerichtet — Dieses Portal ist
  fertig installiert."**, and **Zugänge** afterwards lists **exactly one**
  administrator: yours. What must never happen is two administrators, or a page
  of database words instead of that sentence.
  > This walk is sequential, and what was fixed is the *simultaneous* case: two
  > submissions reaching the database in the same instant, where both used to
  > count the administrators, both counted none, and both wrote one. Sequentially
  > the second tab is refused whichever way the guard is written, so passing this
  > step proves the outcome and **not** the lock. One person with one browser
  > cannot press two buttons in the same millisecond, and a step that pretended
  > otherwise would be a step she cannot perform. The lock itself was measured
  > with two database connections against MariaDB 10.11.14; no manual walk
  > replaces that. With a second person and a second device, both tapping
  > **Installieren** on a count of three is worth one try, and the only thing to
  > read is the administrator count in **Zugänge**. **Two administrators after an
  > install is the symptom**, whenever it appears and however it was produced.
- [ ] **3.4e** `php bin/console.php create-admin` on a host that does not allow
  `shell_exec`, which is most shared hosting: `php -r
  'var_dump(function_exists("shell_exec"));'` prints `bool(false)` there. Walk it on
  the second copy, not on the portal with the families in it; a portal that already
  has an administrator needs `create-admin --force`. After the name and the
  address, and **before** it asks for the password, it warns „This server does not
  allow hiding what you type: the password will be visible on screen." and says
  how to stop. The password is then readable as you type it, twice — expected on
  such a host. It ends with „Administrator created.", and that account signs in.
  What must never happen is a PHP error in place of the prompt, with no account
  made: that is what it did before.
- [ ] **3.5** Open `setup.php` again. It answers 403 and creates nothing.
- [ ] **3.6** `bash bin/update.sh --check` on an installed copy reports the file
  version, the database version and whether anything is pending, and changes
  nothing.
- [ ] **3.7** `bash bin/update.sh` with a modified working tree refuses rather
  than discarding the change.
- [ ] **3.8** A real update: `bash bin/update.sh` pulls, installs dependencies,
  applies migrations and prints the status. Afterwards **Einstellungen →
  System** shows the new version for both the files and the database.
- [ ] **3.8a** The same update on a portal that has **real rows in it**, not an
  empty database: before updating, write down one tariff's price, one child who
  is getting a discount and the amount they pay, and the address one family
  signs in with. Afterwards, the tariff shows that price as its first interval,
  that child's enrolment shows the same discount with the same amount, and that
  family signs in with the same address and the same password. (The `migrations`
  suite checks this on a throwaway MariaDB; this is the same check on her real
  data.)
- [ ] **3.9** Put an older package over a newer database. The portal stays
  closed and says why, instead of guessing.
- [ ] **3.10** Switch **Wartungsmodus** on from **Einstellungen → System**.
  Everybody else sees the closed page; you still get in, with the red strip at
  the top offering the way out.
- [ ] **3.11** Give the release its record in `VALIDATION.md`: the commit, the
  engine and the PHP the suite and the browser walk ran on, the suite's count and
  what its last lines said it could not cover. Not a test, but it belongs to
  whoever cuts the release: figures nobody owns go stale.

---

## Sign in, roles and access

- [ ] **4.1** Sign in as the trainer. The menu reads Übersicht, Schüler, Kurse,
  Anwesenheit, Geld, Chats and **Verwaltung**. **Rechnungen** is the
  switch at the top of **Geld**, **Postausgang** is at the top of
  **Chats** (U.45–U.51).
- [ ] **4.2** As the trainer, **Einstellungen** and **Änderungen** are *not* in
  the menu, and typing their addresses by hand is refused.
- [ ] **4.3** As the administrator, everything the trainer can reach, you can
  reach too. There is no screen she has and you do not.
- [ ] **4.4** Sign in as a family. They see the overview, **Profil** — their one
  child's page, since one login is one child — **Chats** and
  **Neuigkeiten**, and no other child.
- [ ] **4.5** As a family, open another family's child by editing the address.
  Refused, in words, not with a blank page.
- [ ] **4.6** Wrong password repeatedly (more than ten times for one address,
  within fifteen minutes) is refused with „Zu viele Versuche", and a correct
  password immediately afterwards is refused too — that is the point.
- [ ] **4.6a** The same address signs in **correctly** twelve times in a row —
  sign out, sign in, twelve times, which is one afternoon of three children
  sharing a phone. All twelve work. A correct password must never produce „Zu
  viele Versuche": the attempt is counted before the password can be checked,
  and a correct sign-in clears the count.
  > Each of those sign-ins also counts towards the sixty a quarter of an hour
  > allowed from one internet connection, and that count is deliberately not
  > cleared by anything you can do from the portal. Before the next check, wait
  > a quarter of an hour or use another connection.
- [ ] **4.6c** After 4.6, wait sixteen minutes: the correct password signs in
  again. The suite ages the stored count rather than waiting, so this is the one
  place the clock itself is proven.
- [ ] **4.7** „Passwort vergessen" with the email address sends a link to the
  login's own address; the link sets a new password once and not twice. Asking
  four times in an hour is refused the fourth time — that counter is never
  cleared, because typing an address proves nothing about who typed it.
- [ ] **4.7a** The sign-in page carries one small line under the form, „Deine
  Daten: Datenschutzerklärung", the last word the page's one link to the notice,
  and its footer shows only the version; an invitation's page links the notice
  once too (M.5). Every other signed-out page — forgotten password, the notice
  itself — ends with the link to the Datenschutzerklärung and the version, and
  nothing longer. At 320px no page scrolls sideways.
- [ ] **4.8** **Zugänge** offers **„+ Teammitglied einladen"** and nothing that
  makes a login with a password (ADR 0020, §5). Invite a trainer: **Postausgang**
  holds the invitation, whose text you cannot read there; the new login is
  „Eingeladen" until its holder opens the link and chooses a password.
- [ ] **4.8a** A trainer is not offered that form and cannot post to it.
- [ ] **4.8b** The role list on that form offers **Trainerin** and
  **Administrator** only — no Schüler. Inviting either attaches no child.
- [ ] **4.8c** Invite with an address that already has a login. It says
  „Diese E-Mail-Adresse gehört schon zu einem anderen Zugang. Jede Person
  braucht ihre eigene. Es ist der Zugang von …", naming whose. Tap **„Einladung
  senden"** twice in quick succession: the second tap says **the same sentence**,
  word for word. The wrong outcome is not an error page but a *different*
  sentence, „Die Eingabe ist nicht möglich: ein Wert ist schon vergeben …",
  which is the database complaining and means the two taps raced each other.
  Then check: **Zugänge** lists that address **once**, its **role is unchanged**,
  and **Postausgang** holds **no new invitation** to it.
- [ ] **4.9** Suspending a team member in **Zugänge** (her row, tapped open), or
  a family's login with **„Zugang sperren"** on the child's page, stops that
  person signing in.

---

## The shell: the bar, notifications, feedback, impersonation

- [ ] **5.0** On a desktop screen, „Etwas funktioniert hier nicht" is a button in
  the bottom right corner of every page. It opens upwards, stays inside the
  window, and closing it leaves the page where it was.
- [ ] **5.0a** On a phone it is *not* floating: it is at the end of the page,
  reached from „Etwas funktioniert nicht" on the **Mehr** page or on **Mein
  Konto**. Check on a form page that nothing covers the sticky **Speichern** bar.
- [ ] **5.1** Scroll a long page. The top bar stays where it is.
- [ ] **5.1a** As the administrator, on a laptop at **110% and 125% zoom**, the
  menu on the left has no scrollbar of its own and its last entry is above the
  fold. With a course request waiting, its number stands beside **Kurse**.
- [ ] **5.1b** On a phone, **Mehr** opens a page of its own, not a menu sliding
  in from the side: **Kurse**, **Geld** and **Einstellungen** (**Verwaltung** for
  a trainer, and **Einrichtung** above them while the checklist is unfinished),
  each with its number if something waits there, then **Mein Konto**,
  „Datenschutz und Hilfe" and a red „Abmelden". Every row is at least 44pt. With
  JavaScript switched off it works the same.
- [ ] **5.2** Your name and role appear **once**, in the top bar — not again at
  the bottom of the menu.
- [ ] **5.3** The bell shows a number when something is waiting. Opening it
  lists the notifications newest first; „Alle gelesen" clears the number.
- [ ] **5.3a** On a real iPhone, in Safari, with at least one unread
  notification (a family's message does it, 5.4). Walk 5.3a–5.3d at **390**
  pixels wide (an iPhone 12 to 16 held upright) and at **320** (an iPhone SE of
  the first generation), once with the phone in light mode and once in dark. No
  320 iPhone to hand? Write down that 320 was not checked on an iPhone, rather
  than ticking it. Now **watch the portal's name at the top left, not the bell**,
  and tap the bell: the name does not move, not by a hair, as the panel opens —
  nor as it closes when you tap the bell again. The bell itself stays exactly
  where it was too. It used to jump by about 7 pixels — „das Logo springt" —
  and a desktop browser could not show that happening, so only an iPhone can
  pass this check.
- [ ] **5.3b** With the bell open: no small triangle beside it, open or shut,
  and nothing turning. The panel's left and right edges are both on the screen,
  with a little space on each side — it used to run off the left edge — and
  nothing on the page scrolls sideways. The first word of each notification is
  readable, and „Alle gelesen" can be tapped.
- [ ] **5.3c** The number on the bell is a red badge with a white figure, the
  same as the number on **Chats** in the bar at the bottom, and its figure can be
  read — in light mode and in dark. In dark, the open panel is dark too and
  every line in it can be read.
- [ ] **5.3d** With the bell open, **swipe the page to scroll**: the panel stays
  open. Then **tap an empty part of the page** — the heading, say, not a link:
  the panel closes. Open it and tap the bell again: it closes.
- [ ] **5.3e** On a laptop with a keyboard: press **Tab** until the bell has
  its outline, **Enter** to open it, **Tab** once more into the panel, then
  **Escape**. The panel closes and the outline is back on the bell. Open it
  again, press **Tab** until the outline has left the panel (on your name, next
  to the bell), then **Escape**: the panel closes and the outline stays where it
  was — it does not jump back to the bell.
- [ ] **5.3f** Opening one menu in the top bar closes the other. Open the bell,
  then tap your initials: the bell's panel closes as the account menu opens. Then
  the other way round — only one is ever open. The same with the keyboard: Tab
  to the initials and press Enter while the bell is open.
- [ ] **5.3g** In Chrome on a laptop, switch JavaScript off (developer tools,
  then ⌘/Ctrl+Shift+P, type „Disable JavaScript", Enter; it stays off while
  the developer tools are open) and reload a page. The bell still opens
  when clicked and closes when clicked again. A click elsewhere leaving it open
  is expected without JavaScript and passes. Close the developer tools to
  switch JavaScript back on.
- [ ] **5.3h** With the bell open on a child's page, „Alle gelesen" leaves you on
  that same child, not on „Nicht gefunden".
- [ ] **5.3i** Tap your picture or initials at the top right: the menu shows
  your name and role, „Mein Konto" and „Abmelden" — no status, no emoji and no
  coloured dot, as staff and as a family. „Mein Konto" opens your account. With
  JavaScript off as in 5.3g, the menu opens and closes the same way, and
  „Abmelden" signs you out.
- [ ] **5.3j** On an iPhone 320 pixels wide (an iPhone SE of the first
  generation), tap your picture or initials: „Abmelden" is above the bar at the
  bottom and can be tapped. No such iPhone to hand? Write down that this was not
  checked.
- [ ] **5.4** A family sends a message. Both the trainer *and* the
  administrator get a notification — not only one of them.
- [ ] **5.5** Clicking a notification lands on the thing it is about.
- [ ] **5.6** „Etwas funktioniert hier nicht" (where 5.0 and 5.0a say): send
  one with a screenshot attached.
- [ ] **5.7** **Einstellungen → Rückmeldungen** shows it with the page it came
  from, the browser, the address it was sent from and the portal version — none
  of which the sender had to know. The screenshot opens.
- [ ] **5.8** Marking a report as handled removes it from the count in the tab.
- [ ] **5.9** On a child whose login is in use, card **„Zugang zum Portal"** →
  **„Portal als … ansehen"**. You see exactly what the family sees, with a bar
  across the top saying so.
- [ ] **5.10** „Ansicht beenden" gives you your own account back. (This is the
  check that once failed: the way out must work from inside a borrowed session.)
- [ ] **5.11** Viewing is looking only. As the administrator, **Zugänge**, tap a
  trainer's row open, „Portal als diese Person ansehen". While viewing, try each
  of these: on **Mein Konto**, change the name under „Name und Darstellung" and
  save; „Etwas funktioniert hier nicht", a sentence, „Absenden"; on a child's
  page, change a detail and save; on a child whose login is in use, „Portal als
  … ansehen". Each is refused with „Beim Ansehen als jemand anderer lässt sich
  nichts schreiben oder ändern. Beende zuerst die Ansicht." In **Chats** a
  chat opens without a writing box. „Ansicht beenden" gives you yourself back,
  and nothing you tried happened: **Zugänge** shows the trainer's name as
  before, the child's detail is unchanged, and **Einstellungen → Rückmeldungen**
  has no new report.
- [ ] **5.11a** The same as the trainer, viewing a family as in 5.9: a change on
  their child's „Profil" tab, on their **Mein Konto**, and through „Etwas
  funktioniert hier nicht" is each refused with the same sentence. Opened by its
  address, `?page=messages&new=1`, „Neue Nachricht" shows that sentence and
  lists nobody (C.18). Then, still viewing, „Abmelden" in the account menu: you
  are signed out, and signed in again you are yourself, with no bar about
  viewing anybody.
- [ ] **5.11b** What a view does not draw is refused all the same. As the
  administrator, before starting a view, open two more tabs of your own: one
  where the bell shows a number, one on a course's group with its writing box.
  Start the view in the first tab. Then, in the other two and without reloading
  them, „Alle gelesen" in the bell and a message sent in the group are each
  refused with the same sentence. After „Ansicht beenden" the bell still shows
  its number and the group has no new message.
- [ ] **5.11c** A view ends with the viewer's own login, and signs the browser
  out. Two browsers. In the first, as the trainer, „Portal als … ansehen" on a
  child whose login is in use, and open the child's **Chats**. In the
  second, as the administrator, **Zugänge** → that trainer's row → „Zugang
  sperren". Back in the first, tap anything — a chat, **Übersicht**, „Ansicht
  beenden": the sign-in page, with nobody signed in, and no page of the
  child's opens until somebody signs in. „Zugang entsperren", then the same
  with the trainer changing her own password in a third browser (**Mein
  Konto**) instead of the suspension; and once more, with a trainer made for
  it, with „Zugang löschen". Each time the viewing browser is signed out on its
  next tap, never left as the child without the bar.
- [ ] **5.12** A family cannot impersonate anybody.

---

## Appearance and personal preferences

- [ ] **6.1** **Einstellungen → Portal**: set the portal's default colour. A new
  account sees it.
- [ ] **6.2** **Mein Konto**: a user picks their own colour and text size, and
  keeps it after signing out and in again. The portal default does not override
  their choice.
- [ ] **6.3** Dark mode follows the device. Switch the phone to dark and check
  no text has disappeared into its background.

### The club's colours and logo (Einstellungen → Portal)

Signed in as an **administrator**. Keep a second browser signed out on the
sign-in page, and a family's login at hand. Before you start, note what the
**Aussehen** card shows, so you can put it back.

- [ ] **6.7** Nothing set: every colour field on **Aussehen** is empty and says
  „Standard". Open the sign-in page with the browser's developer tools on the
  network tab and reload: there is **no** request for `page=brand`. The portal
  shows its built-in colours.
- [ ] **6.8** Set **Hauptfarbe** `#8a1538`, **Menüfarbe** `#0a1030`,
  **Hervorhebung** `#ffcc00`, **Hintergrund** `#fffaf0` and save. The message
  reads „Vorgaben gespeichert. Vorher: Hauptfarbe Standard, Menüfarbe Standard,
  Hervorhebung Standard, Hintergrund Standard." Buttons, links, the menu, the
  square behind the shuttlecock top left, the marker on the current menu entry
  and the page background all change — signed in, and on the sign-in page in
  the other browser.
- [ ] **6.9** Switch the phone (or the computer) to dark mode, then choose
  **Dunkel** under **Mein Konto**. Both show the dark shades worked out from
  your colours; no text disappears into its background, and the text on a
  button stays readable.
- [ ] **6.10** On an iPhone, the bar at the very top of Safari (and of the
  home-screen app) is the page's background: the light grey ground — or the
  **Hintergrund** set on „Aussehen" — in light mode, and the dark background in
  dark mode.
- [ ] **6.11** Set **Hauptfarbe** to a pale yellow, `#fdf6b2`. It is saved, not
  refused, and the card says „Für gute Lesbarkeit verwendet: #837703" next to
  your choice — a dark mustard that still looks yellow, not a grey. White text
  on the buttons is readable. In dark mode the buttons show the pale yellow
  itself, with dark text.
- [ ] **6.11a** Now set **Hauptfarbe** to `#f5e663`, a stronger yellow, and
  save. The message names the colour it replaced: „Vorgaben gespeichert.
  Vorher: Hauptfarbe #fdf6b2." The card again says „Für gute Lesbarkeit
  verwendet: …" with your choice beside it. Look at the sign-in page in the
  other browser and at the menu, each in light and in dark mode: every button,
  link and menu entry can be read.
- [ ] **6.12** Set **Hintergrund** to `#444444`. Refused: „Hintergrund: Auf
  diesem Hintergrund wäre die graue Schrift schwer zu lesen …" and **nothing**
  else from that save has changed — change the main colour in the same save to
  prove it. Your typed values are still in the form. Type `gelb` into a colour:
  refused with „Bitte eine Farbe wie #1f5fa9 eingeben."
- [ ] **6.12a** Beside each colour box is a small colour swatch. Tap it and pick
  a colour: the box fills with that colour's code. „Standard übernehmen" empties
  the box again; saved empty, that colour goes back to the built-in one. Then
  switch JavaScript off, as in 5.3g: there is no swatch and no „Standard
  übernehmen", and a colour typed into the box as `#1f5fa9` is saved all the
  same.
- [ ] **6.13** The way back: retype the values the last „Vorher: …" message
  named, save, and the portal looks as it did.
- [ ] **6.14** A family who picked their own colour under **Mein Konto** keeps
  it; one who left „Wie eingestellt" gets the club's main colour.
- [ ] **6.15** Under „Erweitert", set **Hauptfarbe im Dunkelmodus** without a
  light **Hauptfarbe**: nothing changes, as the hint says. With the light one
  set, it applies in dark mode only.
- [ ] **6.15a** The colours are never kept stale. With the network tab open,
  reload twice: the second time the `page=brand` stylesheet comes from the
  cache, with no request to the server. Note the `v=` in its address. Change
  **Hauptfarbe** by one digit and save: the very next page asks for a new
  `v=` and shows the new colour, without a forced reload. Now empty
  **Hervorhebung**, save, and note the `v=`; then set **Hervorhebung im
  Dunkelmodus** alone and save. Nothing on the page changes, so the `v=`
  stays the same and the stylesheet still comes from the cache. After the next
  update is uploaded, look once more: if the release changed how the colours
  are worked out, the first page asks for a new `v=`; if not, the old one
  still answers from the cache.
- [ ] **6.16** Clear every colour and save. The message names the colours you
  had. Reload with the network tab open: no `page=brand` request again.
- [ ] **6.17** **Logo**: upload a wide PNG, then an iPhone JPEG, then a WebP.
  Each replaces the last, top left in the menu, on the sign-in page, and in the
  phone's top bar at 320 px, on a white plate in light and dark mode.
- [ ] **6.18** Upload a logo 40 pixels tall, a 6:1 banner, a GIF and a
  photograph over 1 MB. Each is refused with its size and what is needed, and
  the logo you had stays.
- [ ] **6.19** On **Aussehen**, switch on „Portalnamen neben dem Logo ausblenden"
  and „Zeile „Verwaltung“ / „Mein Portal“ … ausblenden". Check each with a logo,
  with only the portal icon, and with neither: with neither, the name still
  shows. Saving the **Logo** card leaves both switches as they were.
- [ ] **6.20** „Logo entfernen" while the portal has an icon of its own: the
  icon comes back top left, and the message says „Logo entfernt. Oben links
  steht wieder das Symbol des Portals." Upload the logo again, go back to the
  built-in icon („Standard-Symbol verwenden"), and „Logo entfernen" once more:
  the shuttlecock comes back, with „Logo entfernt. Oben links steht wieder der
  Federball."

**A photo taken on a phone held upright** is often stored sideways with a note
saying "turn me" (EXIF orientation). The portal reads that note itself, without
PHP's exif extension, and measures the picture as the browser draws it, so such
a logo is accepted and drawn upright (S.5). The note is looked for in the first
64 KB of the file, where the portal's own cleaned copy keeps it; a JPEG whose
note sits deeper is still measured as stored, and can be refused as "too tall"
when it looks wide. Saving it from an image editor or as a screenshot fixes
that.

---

## Students, contacts, levels and age groups

- [ ] **7.1** Create a child with a name and a date of birth (L.3–L.10 walk the
  wizard itself). On their page, under **Einteilung**, the level is **Anfänger**
  unless you changed which one is default.
- [ ] **7.2** The age group is worked out from the date of birth alone — „Unter
  12", „Jugend", „Erwachsene" — and there is nothing to set: no „Altersgruppe
  festlegen" on the child's page. A child without a date of birth has none, and
  one whose age no band covers reads „Keine passende Gruppe".
- [ ] **7.3** Change the date of birth so that the child falls into another band,
  and save: their page, their row in **Schüler** and the filter all name the new
  band at once.
- [ ] **7.4** **Verwaltung → Leistungsgruppen**: rename one, add a fourth, make
  a different one the default. All three take effect without an administrator.
- [ ] **7.5** **Verwaltung → Altersgruppen**: the page warns if the bands leave
  a gap or overlap.
- [ ] **7.6** Deleting a level or age group that is in use tells you how many
  children are in it rather than silently emptying their record.
- [ ] **7.7** A child with no contact is named on the **Schüler** list — „1 Kind
  ohne Notfallkontakt" — with a link straight to their contacts.
- [ ] **7.8** The first contact you add becomes the **Standardkontakt** without
  being asked. It saves with only a telephone number: invoices, reminders and
  invitations go to the child's own address, not to a contact.
- [ ] **7.8a** „Kontakt hinzufügen" and „Kontakt bearbeiten" ask for the same
  things, in the same words: the first box is the contact person's own name, not
  a question about whose contact it is.
- [ ] **7.8b** A contact with a phone number and **no** email address saves.
  That is the grandmother who answers the telephone, and she is the reason this
  list and the portal's address are two different things now.
- [ ] **7.8c** On the child's own page, the **E-Mail-Adresse** is where the
  portal writes to and, once the child has a login, what it signs in with. For
  a young child that is usually a parent's address — but each child needs one of
  its own, so a brother or sister needs a different one.
- [ ] **7.8d** **„Einladung senden"** on **„Zugang zum Portal"**, for a child
  „Ohne Anmeldung", turns their login into an invitation to that address. Check
  the outbox.
- [ ] **7.8e** Invite a *second* child at an address that already signs in for
  another child: refused, with „Diese E-Mail-Adresse gehört schon zu einem
  anderen Zugang. Jede Person braucht ihre eigene. Es ist der Zugang von …".
  No invitation is queued, the second child stays „Ohne Anmeldung", and the
  first child's login is untouched. One login is one child.
- [ ] **7.8f** Inviting a child at an address that belongs to a trainer or an
  administrator is refused the same way, and the child stays „Ohne Anmeldung".
- [ ] **7.8h** The **Schüler** list names two gaps separately: children with
  nobody to ring, and children with no address.
- [ ] **7.16** **+ Schüler anlegen** asks, on its first step, for the first and
  last name, the date of birth, a course and whether they are a member — and
  nothing else. Not the level, not the internal notes.
- [ ] **7.17** The page that says the child is added, and then the child's own
  page, show **Noch zu tun**: an emergency contact, an email address or an
  invitation, a course, a tariff — in that order, each a link to where it is
  done.
- [ ] **7.18** Do them one at a time and watch each disappear. When the last one
  goes, the card goes.
- [ ] **7.19** A course you have just created says the same: a training day and
  a tariff. A course that has both shows no card.
- [ ] **7.9** Add a second contact. It is an ordinary one. Tick
  „Als Standardkontakt verwenden" on it and the badge moves — there is never
  more than one.
- [ ] **7.10** Try to remove the only contact a child has. Refused, in words.
  Remove the standard one when there are two, and the other one takes over.
- [ ] **7.11** A contact can be reached from the phone: the number is a link
  that dials.
- [ ] **7.20** Absences: add one with a reason and a date range; it shows on the
  child and in the attendance screen for those days.
- [ ] **7.21** Delete a child. **Änderungen** still says what the record held.
- [ ] **7.22** Open a link to a child or a course that has been deleted. The page
  says **„Nicht gefunden"** — not „Kein Zugriff", which would say she is not
  allowed to see her own course.
- [ ] **7.23** **(release)** Open a child's page with `&tab[]=x` added to its
  address, once as the trainer and once as the family: the page draws, on its
  first tab, and the server's PHP error log gains no „Array to string
  conversion" line. The same with `&tab[]=x` on **Kurse** and **Einstellungen**,
  `&state[]=x` on **Rechnungen**, and `&page[]=x` on its own: each draws as if
  the list were not there, and the log stays quiet.

---

## Courses, dates and tariffs

- [ ] **8.1** Create a course with two training days in the same week — say
  Monday in one hall and Thursday in another. Both show, each with its own
  place and time.
- [ ] **8.2** A course's place is used for every date unless that date says
  otherwise.
- [ ] **8.3** **Kurse → ein Kurs → Tarife**: every price a course can be taken
  at lives here. There is no tariff floating free of a course.
- [ ] **8.4** One tariff can be paid in more than one way. Give it four prices —
  37 € monthly, 99 € quarterly, 162 € half-yearly, 252 € yearly — and check the
  summary sentence lists all four, with the usual interval first.
- [ ] **8.4a** Save with a price for an interval but the **üblicher Zeitraum**
  set to one with no price: refused, by name. That interval is what an enrolment
  saying nothing is billed at.
- [ ] **8.4b** Save with no price at all: refused. An empty row at the bottom is
  ignored rather than refused.
- [ ] **8.4c** „+ Weitere Zahlungsweise" adds an empty row; the same interval
  twice keeps the first one rather than refusing the form.
- [ ] **8.5** A tariff has a due day. A child can override it; the child's page
  says which of the two applies.
- [ ] **8.6** A tariff carries **Rabattvorlagen** — the shapes of discount she
  gives, like „Erster Monat gratis" or „Geschwisterrabatt, dauerhaft −20 %".
  They are templates: changing one here changes nothing for a family who has
  already been given it.
- [ ] **8.6a** On a child, under **Tarif, Zahlungsweise und Rabatt**: the
  templates of that tariff are listed above the boxes, and the boxes take the
  discount this family actually gets, with a name that appears on the invoice.
- [ ] **8.6b** Give one child a discount and check the child beside them on the
  same tariff is unaffected. That is the whole reason it moved off the tariff.
- [ ] **8.6c** Put a child on a longer interval than the tariff's usual one. The
  agreed-price default beside it changes to that interval's price, and the next
  charge is for that period and that amount.
- [ ] **8.6d** Choose an interval, then delete that price from the tariff. The
  child is billed at the tariff's usual price rather than not billed at all.
- [ ] **8.6e** A discount of 100 % reads as „gratis", not as €0,00 hidden in a
  corner, and „dauerhaft" says so rather than showing −1.
- [ ] **8.7** A child in two courses is billed for both, each at its own
  tariff.
- [ ] **8.8** Archive a course. It leaves the lists, keeps its history, and
  nobody can newly enrol in it.
- [ ] **8.9** On a device whose language is set to **English (US)**, open a
  course and its times. Every time is 24 hours — "16:00", never "04:00 PM" —
  because the boxes are the portal's own, not the browser's picker. Change one,
  save, reopen: it is what you chose.
- [ ] **8.9a** A day with an hour but no minute is refused by name rather than
  stored as "on the hour".
- [ ] **8.9b** A course whose time is not on a five-minute boundary (17:37, say)
  still shows 37 in the minute box, and saving something else on that form does
  not move it.
- [ ] **8.9c** „+ Weiterer Trainingstag" adds an empty row — the new row does
  not inherit the time of the row above it.

---

## Enrolment, asked for and decided

- [ ] **9.1** As a family, open **Profil** and look at **Kurse**. Courses with
  room in them are offered.
- [ ] **9.2** Ask to join one. Nothing is enrolled yet; the request is waiting.
- [ ] **9.3** As the trainer, the count beside **Kurse** in the menu shows the
  waiting request. Accept it: the child is enrolled from the date you agreed.
- [ ] **9.4** Decline a request. The family is told, and no enrolment exists.
- [ ] **9.5** Ask to leave a course. Same again: it takes the trainer's yes.
- [ ] **9.6** A full course does not offer itself to anybody new.
- [ ] **9.7** With one place left, let two families ask, then accept both. The
  second is refused in words rather than putting a ninth child in an eight-place
  hall.

---

## Attendance

- [ ] **10.1** **Anwesenheit** in the menu opens one screen: pick a course, pick
  a date, see every child in it, one tap each, one save.
- [ ] **10.2** The date offered is the course's own training day, not today when
  today is a Wednesday and she trains on Mondays.
- [ ] **10.3** A child in two courses appears in both, and a mark in one does
  not touch the other.
- [ ] **10.4** Report an absence for a child covering that day. On the
  attendance screen their name carries the reason — „Krank gemeldet" — as a
  note. Nothing is ticked on their behalf: what is recorded is what you tap.
- [ ] **10.5** Save, reload, and the marks are still there.
- [ ] **10.6** The child's own page shows their attendance rate, and the figure
  matches what you just entered.
- [ ] **10.7** On a phone at 320 px wide, no two labels overlap and every tap
  target is comfortably hit with a thumb.

---

## Charges and billing

- [ ] **11.1** **Geld → „Monatsbeiträge"** previews what would be created:
  one line per enrolment, each naming the course, the tariff and the period,
  and a reason beside anybody who is being skipped.
- [ ] **11.2** Nothing is created until you press the button. Pressing it twice
  does not charge anybody twice.
- [ ] **11.3** A child who joined mid-period is charged the part of it they were
  there for, when the tariff says to prorate — and the amount matches the days.
  The charge shows the days it covers, which are not the billing period.
- [ ] **11.4** The same tariff set to „whole period" charges the whole amount,
  and set to „skip" charges nothing until the next period.
- [ ] **11.4a** Set to „anteilig nach vollen Monaten": somebody joining on any
  day of November on a 252 € yearly tariff is charged **42 €** for November and
  December, and the charge covers 01.11. – 31.12. That is what a club form means
  by „aliquot", and pro rata by days would have been 34,52 €. The month somebody
  leaves in counts in full too.
- [ ] **11.5** A yearly tariff produces one charge a year, not twelve.
- [ ] **11.6** A discount of 50 % for three months produces three reduced
  charges and then the normal amount.
- [ ] **11.7** The due date is the one from the tariff, or the child's override
  where there is one. The expected payment date for a monthly tariff is the
  first of the month.
- [ ] **11.8** An unpaid charge becomes **überfällig** on its own, the set
  number of days after it was due (seven by default).
- [ ] **11.9** A child who joins in the second month of a longer period is
  charged for it — and the due date is in the month the charge was created, not
  before it. It is never overdue on the day it appears.
- [ ] **11.10** A child who leaves mid-period is charged for the days they were
  there, whatever the tariff's joining rule says.
- [ ] **11.11** Pause a child's billing. The next run skips them and says why.
- [ ] **11.12** Send the reminders on **Geld**: one email per child with an
  overdue charge, to the child's own login, however many charges are overdue.
  The page comes back on **Geld › Überfällig** saying „1 Erinnerung geht raus."
  or „3 Erinnerungen gehen raus.", and „1 Kind bekommt keine: ohne Anmeldung
  oder abbestellt." for each child it cannot reach. The number is the one the
  reminder button showed before the tap.
- [ ] **11.13** A child with two overdue charges, one of them partly paid:
  **Postausgang** has one email „Noch offen: 2 Beiträge" (no amount in the
  subject). Opened, it lists both, the older first, the partly paid one as
  „noch 5,00 € von 15,00 €", then „Zusammen:", „Bankverbindung und für jeden
  Beitrag einen QR-Code findest du unter „Beiträge“:" with a link that opens
  that child's **Beiträge**, „Schon überwiesen? Dann passt alles – danke!" and
  „Viele Grüße" with the club's name. No IBAN anywhere in it.
- [ ] **11.14** A family whose language is English under **Mein Konto** gets
  „Still to pay: …", „Hello …", amounts as „35.00 €" and English dates, while
  the trainer's portal stays German.
- [ ] **11.15** With „QR-Code für offene Beiträge anzeigen" switched off, or a
  charge paying into a recipient without an IBAN, the mail says „Alles Weitere
  findest du unter „Beiträge“:" and promises no bank details.
- [ ] **11.16** Send the reminders a second time on the same day: nobody gets a
  second email, and the page says „Keine Erinnerung verschickt. Heute schon
  erinnert: 3 Kinder." (with your count), in the plain banner, not the red one.
  So do „Keine Erinnerung verschickt: …" and „Gerade ist nichts überfällig.". On
  the next day by the club's calendar they are reminded again.
- [ ] **11.17** Reminders need working mail and nothing more. With the mail
  test passed but the privacy notice not released yet, they still go out,
  while inviting waits for both. With mail saved but its test not passed, the
  reminders are refused with „E-Mail-Versand zuerst testen: unter
  „Einstellungen → SMTP“ die Verbindung prüfen." (a trainer reads „Eine
  Administratorin muss zuerst den E-Mail-Versand einrichten und testen.") and
  nothing goes out.

---

## Payments and proof

- [ ] **12.1** Record a payment against a charge. The outstanding amount drops
  by exactly that much, in cents, with no rounding drift.
- [ ] **12.2** A payment that is not confirmed does not reduce what is
  outstanding, and says it is still unconfirmed.
- [ ] **12.3** Void a payment. The amount comes back.
- [ ] **12.4** As a family with something outstanding, the overview offers
  „Schon überwiesen? … Beleg hochladen" and says it is voluntary. With nothing
  outstanding the offer is not there, and the trainer never sees it.
- [ ] **12.5** Follow the offer and upload a proof — a photo or a PDF. It is
  optional: no page ever blocks on it.
- [ ] **12.6** The trainer sees the proof, can open it, and can remove it.
- [ ] **12.7** A file larger than the limit is refused in words, naming the
  limit, and the limit shown is never higher than what PHP itself accepts.
- [ ] **12.8** The transfer QR code on a family's page carries the right amount
  and reference.
- [ ] **12.9** A receipt downloads under the type it really is. On a computer,
  copy a PDF and rename the copy `beleg.exe`. As the trainer, on a child's
  **Beiträge**, card „Zahlungsbeleg", choose it under „Beleg als Foto oder PDF"
  and tap „Beleg hochladen". If the file dialog will not offer it, look for a
  way to make it show all files, or try dragging the file onto the field; if
  neither works, write down the browser and that the check could not be done.
  The link in the card still reads `beleg.exe`; clicking it downloads
  `beleg.pdf`.

---

## Invoices

- [ ] **13.1** With the business details empty, **Rechnungen** says which ones
  are missing and links straight to the form.
- [ ] **13.2** Fill **Einstellungen → Betrieb** in: name, address, contact,
  tax mode. One thing is still named as missing: the recipient the installer
  left ready has no IBAN.
- [ ] **13.2a** Type the account number into **Verwaltung → Zahlungsempfänger →
  Vereinskonto**. Issuing becomes possible. (Left as it comes, a fresh portal
  produced a finished-looking invoice with nowhere to send the money — this is
  the state every new portal starts in, not a corner case.)
- [ ] **13.2b** A course collecting into a second recipient that has no IBAN is
  refused too, and the message names *that* recipient. A charge remembers the
  recipient it was written for, so changing the course afterwards changes
  nothing and the message must not send you there.
- [ ] **13.3** On a child, **Rechnungen → Rechnung erstellen** from the charges
  that have not been invoiced. The charges are then no longer offered twice.
- [ ] **13.4** Download the PDF and open it in a real PDF reader — not only in
  the browser's preview. It is one page, the umlauts and the € sign are intact,
  and nothing overlaps.
- [ ] **13.5** The document carries everything § 11 Abs 1 UStG asks for: who
  issued it, who it is for, what was supplied, the period, the date of issue,
  a consecutive number, and either the tax amount or the exemption note.
- [ ] **13.5a** Invoice a member who joined part-way through a period. The line
  states the days they were actually a member for, not the whole billing period.
  (It said "01.01. – 31.12." for seven weeks: the amount was right and the
  sentence under it was not, on a document a family keeps.)
- [ ] **13.5b** The IBAN on the document is in groups of four, so it can be
  typed into a banking app without losing your place.
- [ ] **13.5c** An invoice whose total is **over 400 €** is refused until the
  member has an address, and then carries it under their name. At exactly 400 €
  it is not: up to that a Kleinbetragsrechnung may leave name and address out
  (§ 11 Abs 6 UStG), and most of a club's invoices are under it.
- [ ] **13.6** As a Kleinunternehmer, the § 6 Abs 1 Z 27 note is on the
  document and no VAT is shown.
- [ ] **13.7** Switch to „mit Umsatzsteuer": the net, the rate, the tax and the
  gross are all shown, and net + tax equals the gross exactly.
- [ ] **13.8** Numbers run consecutively within the year, with no gaps and no
  repeats. Issue two invoices in quick succession and check.
- [ ] **13.9** „Per E-Mail schicken" queues the invoice, with the PDF, to the
  address of the child's login. **Postausgang** shows it.
- [ ] **13.10** An invoice starts **offen**, becomes **überfällig** on its own
  past the payment date, and only becomes **bezahlt** when the trainer says so.
- [ ] **13.11** Marking it paid records a real payment against the charges
  behind it, so the child's outstanding amount changes too.
- [ ] **13.12** Cancelling an invoice keeps it, marked storniert, with its
  number — it is never deleted and never renumbered.
- [ ] **13.13** A family can download their own invoice, and nobody else's.
- [ ] **13.14** A child whose login is not set up, has no address or is
  suspended: there is no „Per E-Mail schicken", and a sentence says why and to
  download the invoice and pass it on another way. An invoice never goes to a
  contact's address.
- [ ] **13.15** Two courses collecting into different bank accounts: putting a
  charge from each on one invoice is refused in words. One invoice carries one
  IBAN, and it has to be the right one.

---

## Messages

- [ ] **14.1** **Chats** as a family: one box, a „+" to attach a photo
  („Foto anhängen"), a send arrow, and no microphone. Under the box: „Fotos bis
  …" with the size. Write to the trainer without asking anybody's permission.
- [ ] **14.2** Attach a photo — it shows as a picture in the bubble. As a family,
  anything but a JPEG — a PNG screenshot, a GIF, a PDF — is refused with „Bitte
  nimm das Foto mit der Kamera auf.", not with a list of file types. As the
  trainer, a PNG and a WebP are taken, and a PDF is refused with „Dieser
  Dateityp ist hier nicht erlaubt. Möglich sind: jpg, png, webp." (R.12 and R.13
  walk the same on an iPhone.)
- [ ] **14.4** With JavaScript switched off, the „+" is an ordinary file field
  and the message still sends.
- [ ] **14.5** As a family, „Neue Nachricht" lists the **Trainerteam** and
  nobody else: no „Kinder", no „Jemand anderen fragen". A family can no longer
  ask to write to another family.
- [ ] **14.7** **(release)** **A chat between two children, from every side:**
  on a copy that has a chat between two children from before such chats
  closed, open it as one of the two: it reads, and has no writing box (R.15).
  The trainer cannot see it in her list, in her unread count, or by opening its
  address, and cannot open its attachments. The administrator finds it under
  „Alle Einzelchats", reads it and opens its attachments, and has no writing
  box there (E.1–E.4).
- [ ] **14.8** A chat with the trainer is read by the trainer and the child, and
  by the administrators — not by a second trainer (ADR 0022). The old shared
  conversations from before stay readable under „Frühere Unterhaltungen" and take
  no new messages.
- [ ] **14.9** Unread markers clear when a conversation is opened, and the count
  in the menu agrees with the list.
- [ ] **14.11** An empty message is refused.
- [ ] **14.12** A chat photo downloads under the type it really is. On a
  computer, copy a photo from a camera, a JPEG, and rename the copy `x.apk`. As
  the trainer, attach it in a chat with „+" and send it. If the file dialog will
  not offer it, look for a way to make it show all files; do not drag it onto
  the page, which opens the file instead. If it cannot be chosen, write down the
  browser and that the check could not be done. Open the photo from the chat and
  save it: the name the browser offers is `x.jpg`.

---

## News, email and the queue

- [ ] **15.1** Publish a news item. Families see it on their **Übersicht**, in
  the group **Neuigkeiten**, whose „Alle ansehen" opens the full list.
- [ ] **15.2** Save a news item with **„Diese Fassung auch an alle senden, die
  Neuigkeiten per E-Mail erhalten"** switched on. **Postausgang** holds one
  email for each family whose **„Neuigkeiten per E-Mail erhalten"** is on under
  **Mein Konto**, and none for a family who switched it off.
- [ ] **15.3** **Einstellungen → SMTP → Verbindung testen**: with a target
  address filled in, the page comes back with the outcome on it, not with a
  message in a list to go and find. It arrives, or the summary says which step
  failed — the port, the certificate, the password, the sender address.
- [ ] **15.3a** **Nur Verbindung prüfen** connects and signs in without sending
  anything, and no job appears in the outbox.
- [ ] **15.3b** Open **Gespräch mit dem Server anzeigen**. The transcript is
  fixed-width, scrolls sideways rather than wrapping, fits inside the card at
  320px, and contains no password: the AUTH lines read `[entfernt]` or
  `[credentials hidden]`. Read it before forwarding it to a host.
- [ ] **15.3c** Put in a port nothing listens on: the answer is that the port is
  usually blocked or wrong, in one sentence, rather than a mail-library error.
- [ ] **15.4** **Postausgang** shows queued, sent and failed. A failure retries
  with a growing delay rather than hammering.
- [ ] **15.5** An unsubscribe link at the bottom of a newsletter works without
  signing in, and only unsubscribes that one person.
- [ ] **15.6** Changing the SMTP password and saving does not print it back to
  the page.
- [ ] **15.7** News by email starts switched on, and saying no sticks. You need
  two email addresses of your own that have no login yet. On a child, **„Zugang
  zum Portal" → „Einladung senden"** to the first; open the invitation and its
  link. **„Neuigkeiten des Vereins per E-Mail erhalten. Jederzeit
  abbestellbar."** is **already switched on**. Switch it off, tick „Ich habe die
  Datenschutzhinweise gelesen.", choose a password and tap **„Konto
  aktivieren"**. Signed in as that family, **Mein Konto** shows **„Neuigkeiten
  per E-Mail erhalten"** switched off. Do the same for the second address on
  another child, but leave the switch on. Now save a news item as in 15.2:
  **Postausgang** has one for the second address and **none for the first**, and
  only the second inbox receives it. Without the second address, a news mail
  that reached nobody would pass this check. Afterwards, on each of the two
  children's pages, **„Anmeldung löschen"**: each child is „Ohne Anmeldung"
  again.
- [ ] **15.8** Save a news item without „Im Portal veröffentlichen": no bell
  changes. Tick it and save: signed in as a family, the bell has a count, and
  its newest entry, with the news icon, is the item's title over the first
  words of its text; a tap opens the item. A family whose login is „Gesperrt"
  finds nothing new when restored, and „Portal als diese Person ansehen" on a
  family shows the entry too.
- [ ] **15.9** Change the published item's text and save: no family gets a
  second entry, and no bell's count goes up.
- [ ] **15.10** Untick „Im Portal veröffentlichen" and save: the entry is gone
  from every family's bell, read or unread, and where it was unread the count
  is one lower.

---

## The students list

Signed in as the trainer, on the example data, with the levels a new portal
starts with: Lena (9, Anfänger), Jonas (10, Fortgeschritten), Elias (13,
Anfänger) and Mia (no date of birth, Könner). Lena, Mia and Elias are in
„Kindertraining"; Jonas has only asked to join. A family never sees this list.
The `views` suite checks the same page's HTML; these checks are for the screen.

- [ ] **17.1** On **Schüler**, the list's controls start with a search box of
  its own, „Suchen". Under „Filter" choose the course Kindertraining and
  „Anwenden": three children, and „3 in dieser Auswahl" under the heading. Now
  search for „a", which every name has: still the three, still „3 in dieser
  Auswahl", and the row „Filter" still reads „Kindertraining" — the search keeps
  the selection. Empty the box and search again: the course is still chosen.
- [ ] **17.2** „Alle | Überfällig | Krank" above the list, one tap each.
  „Überfällig" lists Mia alone, „35,00 € überfällig" in her row; „Krank" lists
  Mia alone, sick from today. Either starts afresh: a course chosen under
  „Filter" is dropped. „Alle" brings all four back. Nothing saves a selection
  under a name.
- [ ] **17.3** Tap the row „Filter": it unfolds to Kurs, Mitgliedschaft,
  Leistungsgruppe and Altersgruppe — the groups in the order **Verwaltung →
  Altersgruppen** lists them, then „Ohne Altersgruppe" — and „Anwenden". Choose
  Kindertraining, Anfänger and Unter 12 and apply: Lena alone, the fold closed
  again, and its row reads „Kindertraining · Anfänger · Unter 12", ending in „…"
  where the row is too narrow for it. The fold no longer asks for an absence or
  for overdue charges: those are „Krank" and „Überfällig" above it.
- [ ] **17.4** „A–Z | Nach Alter", under the fold. In A–Z a row reads the age
  group and the level: Lena „Unter 12 · Anfänger", Mia „Geburtsdatum fehlt ·
  Könner". „Nach Alter" is a card per age group in Verwaltung's order, its name
  with the span under it and the count beside it: „Unter 12", „bis 11", 2 —
  Lena (9), then Jonas (10); „Jugend", „12 bis 17", 1 — Elias; then „Ohne
  Geburtsdatum", 1 — Mia. No card for „Erwachsene", which has nobody. Under a
  card a row reads the age and the level, „9 Jahre · Anfänger". Each child is
  listed once. With the course chosen under „Filter", „Nach Alter" keeps it and
  shows its three.
- [ ] **17.5** On Lena's page, give her a date of birth 19 years ago and save:
  „Nach Alter" now has „Erwachsene", „18 und älter", with Lena. **Verwaltung →
  Altersgruppen**: let „Erwachsene" start at 21. Lena is now under „Ohne
  Altersgruppe", after „Jugend" and before „Ohne Geburtsdatum", with „Keine
  deiner Altersgruppen passt." and „Altersgruppen ansehen", which opens the
  groups. Archive all three groups: „Es gibt noch keine Altersgruppen." with
  „Altersgruppen anlegen", and Lena, Jonas and Elias under „Ohne
  Altersgruppe". Put the groups and Lena's date of birth back.
- [ ] **17.6** Choose „Unter 12" under „Filter" and apply: Lena and Jonas, and
  above the list „1 Kind ohne Geburtsdatum ist nicht dabei." with „Zeigen",
  which opens the list „Nach Alter" at the card „Ohne Geburtsdatum", with Mia.
  With „Ohne Altersgruppe" chosen instead, Mia alone and no such line; with no
  age group chosen, no line either.
- [ ] **17.7** No row shows a price any more, on **Schüler** or in the
  overview's „Schüler". Jonas, who has only asked to join, wears an amber „Ohne
  Kurs" beside „Probetraining" in both; Lena, in the course, does not. With more
  than fifty children — on a copy, before a release; the `views` suite checks
  the same with 54 — search for a letter most names share and choose „Nach
  Alter": „Weiter" at the bottom keeps the search, the filters and the order,
  and a card that runs on to the next page shows the count of its whole group
  on both pages, not the page's.
- [ ] **17.8** Switch JavaScript off, as in 5.3g. The search, „Alle |
  Überfällig | Krank", „Filter" unfolding, „Anwenden", „A–Z | Nach Alter",
  „Zeigen" and „Weiter" all work as above; the search and „Anwenden" reload the
  page.

---

## Forms that do not lose what you typed

- [ ] **18.1** Type a euro sign into a number field and save. The form comes
  back with **everything else still in it**, the error beside the field that
  caused it.
- [ ] **18.2** Same on a long form with several sections — nothing is emptied.
- [ ] **18.3** A password field is *not* refilled. That is deliberate.
- [ ] **18.4** A field left blank to take the tariff's price says what that
  price is, and shows it as a default rather than as something you typed.
- [ ] **18.5** Change it, and a button appears to put the default back, naming
  the value it would go back to.

---

## Change log

- [ ] **19.1** **Änderungen** lists what changed in words: the field by the name
  she uses for it, the old value and the new one.
- [ ] **19.2** A save that only touched a timestamp is not listed as a change.
- [ ] **19.3** There is no undo button, and no way to put a version back. The
  page informs; it does not act.
- [ ] **19.4** Deleting a record keeps what it held, so it can still be read.

---

## Privacy

- [ ] **20.1** **Einstellungen → Datenschutz**: the German and English drafts
  are editable, and the operator's own details are filled into them from
  **Betrieb** rather than typed twice.
- [ ] **20.2** Editing the notice changes the **Fassung** number printed under
  it. Nobody is asked to acknowledge it again: the number is recorded when a
  family accepts an invitation, and never compared afterwards.
- [ ] **20.3** The notice is readable signed out.
- [ ] **20.4** The released notice says what this version stores and sends: club
  news by email being on for new accounts, the course groups and what a message
  holds, and that everybody signs in with their own address and a login without
  one receives no e-mail, in „3." the paragraph on profile pictures: who adds
  one, who sees it, that a child agrees alone from 14 and a parent below that,
  and that everybody signed in sees the team's, and in „6." how long each kind
  of data is kept and that charges, payments and invoices stay seven years. It
  says nothing about usernames, sign-in links, when somebody was online, an
  online dot or a status emoji. A portal that was updated keeps the text saved
  before, so these arrive only if they were pasted in (UPDATING.md). No „[…]"
  note is left in the released text.

---

## What may be customised, and what may not

- [ ] **21.6** The things that *are* hers to change — levels, age groups,
  membership statuses, payment methods and the payment recipients — are under
  **Verwaltung** and need no administrator. A course's tariffs are on the course
  itself, under **Kurse**.

## Data safety

- [ ] **21.1** Interrupt a save (close the tab mid-request). Nothing half-written
  is left behind.
- [ ] **21.2** After an update, **Einstellungen → System → Sicherungen** lists a
  copy written just before it, and it is not empty. Once, deliberately, take that
  file from `storage/backups` in the file manager and import it into an empty
  database in the hosting panel, so you know the route works before you need it.
- [ ] **21.3** An update that could not back up first refuses to run.
- [ ] **21.4** After any update, **Einstellungen → System** shows the same
  version for the files and for the database.
- [ ] **21.7** After deleting an account whose chats had photos, or a child with
  a payment proof, the file goes too — the daily cleanup, and every update,
  sweep any upload no record points at, though never while the database has no
  login in it (21.8). `storage/uploads` should not grow for ever.
- [ ] **21.8** **(release)** A restore with a page opened halfway keeps every
  upload. On a test install, never the families' portal. It needs a receipt on a
  child's **Beiträge**, a photo in a chat, a problem report with a screenshot,
  and the portal's own icon and logo, each uploaded at least ten minutes before:
  anything younger is never swept, so it would prove nothing. Note how many
  files each folder in `storage/uploads` holds. In phpMyAdmin, **Exportieren**
  the database to your computer, then delete every table. In the file manager,
  open `storage/schema.stamp` and change one character of it: with the files
  unchanged the portal takes itself to be up to date and does not look at the
  database at all, and the moment this check is about is the one after the
  files of another version were uploaded, when the stamp no longer matches. Now
  open the portal once, with maintenance mode off. It shows „Das Portal ist
  vorübergehend geschlossen." with „Die Datenbank ist leer, aber in diesem
  Ordner lief schon ein Portal. …", makes no table and writes no new copy into
  `storage/backups`. **Importieren** your export, then reload, or leave the
  closed page to reload itself within five minutes: the portal opens. Each
  folder in `storage/uploads` holds as many files as before, and the receipt,
  the photo in the chat, the screenshot under **Einstellungen → Rückmeldungen**,
  the icon and the logo all open or show as before. The version before this fix
  deleted every one of those files at that page; the version after it made the
  tables afresh and wrote a copy of nothing.

---

## On the phone, at the end

Do this last, on a real phone, not a resized desktop window.

- [ ] **22.1** Sign in, take attendance for one course, and record one payment,
  one-handed.
- [ ] **22.2** At 320 px wide: no sideways scrolling, no overlapping labels, no
  tap target smaller than a fingertip. `node tests/mobile.mjs` answers this for
  every page at once; do the walk anyway for the two or three screens you use
  most, because it cannot tell you that something is ugly.
- [ ] **22.3** On the attendance screen at 320 px, the three statuses read as
  words — not "Entschuldi / gt" — and the last child in the list can be reached
  without the save bar sitting on top of them.
- [ ] **22.4** **Mein Konto → Farbe**: the eight dots show eight colours. If they
  are grey, a style has been written inline again and the browser is refusing
  it.
- [ ] **22.5** The pinned bar shows your picture or initials on a phone, not an
  empty square.
- [ ] **22.6** Add the portal to the home screen. It opens without browser
  chrome and with its own icon.
- [ ] **22.7** In dark mode, every screen you touched above is still readable.

---

## Cleaning up

- [ ] **23.1** **Einstellungen → System → „Beispieldaten entfernen"**. Every
  example child, account, course, charge, payment, message and file is gone, and
  the page says so.
- [ ] **23.2** Nothing you created by hand during the sweep is left behind.

---

## New in this release — the checks in detail

The checks behind [What changed in this update](#what-changed-in-this-update--check-these).
The ones marked **(release)** are for whoever makes the release; the rest are
yours.

**The sign-in page**

- [ ] **U.1** Open the portal signed out. Under the sign-in form there is one
  small line, „Deine Daten: Datenschutzerklärung" — the last word a link that
  opens the notice, and the page's only one: its footer shows just the version
  number (M.5). The long paragraph about cookies is gone, and the other
  signed-out pages end with the link to the Datenschutzerklärung and the version
  number. At 320px nothing scrolls sideways.

**The portal's own icon** — **Einstellungen → Portal**, card **„Symbol des Portals"**

- [ ] **U.2** Choose a square PNG of at least 180 × 180 pixels (512 × 512 is
  best) and tap **„Symbol speichern"**. Reload: the browser tab shows it, and so
  does the tab of the sign-in page in a private window.
- [ ] **U.3** Try three pictures that must not be taken: a PNG of 400 × 300, a
  PNG of 120 × 120 and a JPEG. Each is refused with a sentence that says what is
  wrong with it — not square, too small, not a PNG — and the icon you had stays.
- [ ] **U.4** On an iPhone: **Zum Home-Bildschirm** before you change the icon,
  again after changing it — the old home-screen icon does not change on its own,
  it has to be removed and added again, and the card says so — and once more
  after going back to the built-in one.
- [ ] **U.5** On an Android phone, **Installieren** / **Zum Startbildschirm
  hinzufügen** shows your icon and the club name.
- [ ] **U.6** **(release)** Change the club name under **Einstellungen → Portal**, then open
  `?page=manifest`: it carries the new name. A phone may keep the old one for up
  to a day.
- [ ] **U.7** **(release)** More than an hour after uploading, the file is still in
  `storage/uploads/icon/` (the hourly sweep keeps it, because the setting points
  at it).
- [ ] **U.8** **„Standard-Symbol verwenden"** brings the built-in icon back in
  the tab after a reload.

**Rows that line up**

- [ ] **U.9** On a child, **Kurse → „In einen Kurs eintragen"**, at 1280, 390
  and 320 pixels wide: each course's name, its tariff choice and its button sit
  on one line, the buttons all the same height as the choice beside them — no
  button stretched taller than its row.
- [ ] **U.10** On a desktop screen at 1280, scroll to the end of a long page
  (a child's **Profil** with every card open): the last button or field is not
  covered by the pinned „Etwas funktioniert hier nicht" button.
- [ ] **U.11** A long conversation under **Chats**, on a screen at least
  761 pixels wide: the help button sits at the end of the page rather than over
  the message box, and the send arrow beside the box can be clicked. The same in
  a chat with nothing in it yet, opened as the trainer from „Neue Nachricht" →
  a child, and from a group's people symbol → a child.
- [ ] **U.12** On a child with two contacts, the **„Standardkontakt"** badge
  sits beside the name it belongs to and covers nothing on the line below, at
  1280 and at 320.

**A problem report says how she got there** — read under **Einstellungen →
Rückmeldungen**, „Technische Einzelheiten"

- [ ] **U.13** **(release)** As a family, on a phone: open three or four pages, then under
  **Mein Konto → „Passwort ändern"** type a wrong current password and save, then
  tap „Etwas funktioniert hier nicht" and send a report. As the administrator the
  report lists those steps in order, every password as `***`, and the red message
  the portal showed on the page after the refused save.
- [ ] **U.14** Open a page, go on to another, press the browser's back button,
  then report. **Adresse** still names the page the report's form was on — the
  one you went back to — and the steps say it was shown without a new request.
- [ ] **U.15** **(release)** Open an unsubscribe link from a newsletter, then report: the
  step shows `signature=***`, never the signature itself.
- [ ] **U.16** Upload a file — a payment proof as a family, or a photo in a
  chat — then report: the step shows the file's size and type, never its name.
  *Also walked by `tests/e2e.sh` with a payment proof: size and type, no name. Passed at 91520db.*
- [ ] **U.17** Mark a report **„Erledigt"**. Every typed value in its steps now
  reads `(gelöscht)`; the addresses stay. **„Wieder offen"** does not bring the
  values back.
- [ ] **U.18** **(release)** Sign out, sign in as a different account on the same phone,
  report: its steps start after the new sign-in, with nothing of the previous
  account's.
- [ ] **U.19** A report marked **„Erledigt"** is gone from **Rückmeldungen**
  30 days after it was marked done: the portal's background maintenance deletes
  it on its first run after day 30. One marked done, reopened and done again is
  kept 30 days from the second time; one still open is never deleted. Note the
  date you mark one done and look again a month later — no suite waits 30 days,
  so this is where the clock itself is proven.

**One login is one student** (see `docs/decisions/0010-one-account-is-one-student.md`)
— on a child's own page, card **„Zugang zum Portal"**

- [ ] **U.20** **(release)** A child „Ohne Anmeldung" with no address at all:
  the card **„Zugang zum Portal"** offers an empty „E-Mail-Adresse", „Sprache
  der Einladung" and **„Einladung senden"**. Type an address nobody uses yet and
  send it: the page comes back at the card, the badge turns to **Eingeladen**,
  the invitation is in **Postausgang**, and the address is on the record under
  Persönliche Daten. (Two children with the same address: L.9b.)
  *`tests/e2e.sh` walks this with the card's own box, empty and then filled: reported passing in the project manager's run of the ADR 0030 patches on 2026-10-08 (VALIDATION.md).*
- [ ] **U.22** **(release)** A child invited but not signed up yet: change the
  address on the child's page and save. The link in the first invitation no
  longer works, and a new invitation to the new address is in Postausgang. With
  SMTP not set up, the save is refused with a sentence and nothing changes.
- [ ] **U.23** **(release)** A child whose login is active: the address on the
  child's page can't be changed there — a change is refused with „Die Adresse
  ist die Anmeldung dieses Kontos und kann hier nicht geändert werden. …",
  pointing to „Mein Konto". Signed in as that family, **Mein Konto →
  „E-Mail-Adresse ändern"** and the confirmation link: afterwards the child's
  page shows the new address.
- [ ] **U.24** **(release)** From the child's page: **„Zugang sperren"**, then
  **„Zugang entsperren"**, **„Einladung erneut senden"** on an invited one, and
  **„Anmeldung löschen"**. Each one lands back on the same child's page, at its
  top — not on Zugänge, and not at the card, which only „Einladung senden" and
  a refusal come back to — with a message saying what happened: „Zugang
  gesperrt.", „Zugang entsperrt.", „Die Einladung ist noch einmal an …
  unterwegs. Der alte Link gilt nicht mehr." and „Die Anmeldung … ist gelöscht,
  …". On **Zugänge**, „Zugang löschen" on a team member's row says „Zugang
  gelöscht."
- [ ] **U.25** **(release)** As the trainer, **Zugänge** lists the team and
  offers no invitation at all: not for a team member (4.8a), and not for a
  family. No team row opens for her; the students' rows still lead to each
  child's card.
- [ ] **U.26** A mail to a family — an invitation, a reminder — starts
  „Hallo <Vorname des Kindes>,".
  *The invitation part is walked by `tests/e2e.sh` („Hallo Jonas,“), passed at 91520db. The reminder is still by hand.*
- [ ] **U.27** **Änderungen** on a child whose login changed shows the login as
  its address, or as „gelöschter Zugang" once it is gone — never a bare number.
- [ ] **U.28** **(release)** With example data filled in, no example child's card
  says „Diese Adresse gehört schon zum Zugang von …" — every example child has
  an address of its own. Where a child's address is another login's (brothers and
  sisters taken off a shared login by the update), the card says so with no
  invitation button, and **Noch zu tun** offers „Eigene E-Mail-Adresse
  eintragen", which jumps to the address field.
- [ ] **U.28a** **(release)** The badge on **„Zugang zum Portal"** matches the login
  in each state — **Ohne Anmeldung**, **Eingeladen** („Eingeladen am …; der Link
  gilt bis …"), **Aktiv** and **Gesperrt**.
- [ ] **U.28b** **(release)** Delete a child that has a login, after first reading
  the warning that its login would be left over. **Zugänge** then shows that login
  under **„Zugänge ohne Schüler"**, where it can be locked or deleted and nothing
  else. With no such logins the section is not there at all.

**Setup**

- [ ] **U.29** **(release)** On a fresh install, type the administrator password in
  `setup.php` with a space at the end. Signing in with the password, with or
  without that space, works — the installer trims it exactly as the sign-in
  form does.

**The console**

- [ ] **U.30** **(release)** `create-admin` on a host without `shell_exec`: check
  [3.4e](#installation-and-update).

**The start checklist** (see `docs/decisions/0011-the-start-checklist-and-a-seven-entry-menu.md`)

- [ ] **U.31** **(release)** On a fresh install, sign in as the administrator: you
  land on **„Einrichtung"**, „0 von 9 erledigt". Sign out and in again: you land there
  again. Sign in as a trainer, and as a family: both land on the overview, and
  `?page=start` answers „Nur für Administratoren."
  *`tests/e2e.sh` at 91520db walks the administrator (0 von 9, and again after signing in a second time) and the family (`?page=start` refused) and passed. The trainer is still by hand.*
- [ ] **U.32** **(release)** Fill in the example data (**Einstellungen → System**):
  „Ersten Kurs anlegen", „Preis für jeden Kurs", „Kinder eintragen", „Beiträge"
  and „Familien einladen" all stay undone. Example data never ticks a step.
- [x] **U.33** **(release)** From the checklist, open „Name und Anschrift", fill it in and
  save. The page the save returns to still offers the way back to the
  checklist, and so does any page you open next; opening the checklist ticks the
  step and the way back is gone.
  *Walked by `tests/e2e.sh` at 91520db (MariaDB 10.11.14; PHP 8.4.19 and 8.5.11): passed.*
- [x] **U.34** **(release)** Save SMTP and run **„Nur Verbindung prüfen"** successfully:
  „E-Mails verschicken" is ticked. Change the server name and save: the step is
  undone again and the SMTP tab shows no last test until you test again.
  Re-saving without changing anything keeps the tick.
  *Walked by `tests/e2e.sh` at 91520db (MariaDB 10.11.14; PHP 8.4.19 and 8.5.11): passed.*
- [ ] **U.35** **(release)** **Einstellungen → Datenschutz** with the English box empty:
  releasing is accepted. Open the notice with `&lang=en`: the German text is
  shown, with the line „This privacy notice is only available in German. …" Put a short English
  text in and release: refused, naming „English". Put a full English text in:
  released, and the version number under the notice has changed.
  *`tests/e2e.sh` at 91520db releases with the English box empty and the family sees the German notice with the line under `&lang=en`: passed. The short and full English texts are still by hand.*
- [ ] **U.36** **(release)** As the administrator, on **Geld** open „Monatsbeiträge"
  and switch „Jeden Monat automatisch anlegen" on and off. Switched off, the
  banner says „Monatsbeiträge werden nicht mehr automatisch angelegt. Du legst
  sie unter „Monatsbeiträge“ selbst an, mit Vorschau." From the checklist,
  „Beiträge" lands on that switch, with „Monatsbeiträge" open. A trainer is not
  offered it. Under **Einstellungen → System** it is no longer listed.
  *`tests/e2e.sh` at 91520db switches it on from the Beiträge page: passed. Off, the trainer and the System tab are still by hand.*
- [ ] **U.37** **(release)** On an existing child whose record still has a tariff and an agreed
  price from before, save the child's page twice with other changes. **Änderungen**
  shows neither the tariff nor the price changing.
- [ ] **U.38** **(release)** **Verwaltung → Geld & Zahlungen**: the default payment recipient is
  a choice of recipients, not a number. Archive the one chosen: the invoices page
  now says that no default recipient is chosen, and „Bankkonto" on the checklist is
  undone.
- [ ] **U.39** With every step done, **„Ausblenden"**: signing in lands on the
  overview. **„Wieder anzeigen"** brings the checklist back.
  *Also walked by `tests/e2e.sh` in Chromium, passed at 91520db; on the phone it is still yours.*

**Errors that report themselves** (see `docs/decisions/0012-unexpected-errors-report-themselves.md`)

- [x] **U.40** **(release)** In the hosting panel's phpMyAdmin, on the copy's
  database only, rename the table `news` to `news_x`. Signed in as a family, open
  **Neuigkeiten**: the page „Die Anwendung ist vorübergehend nicht verfügbar"
  appears, nothing technical. Open it twice more. Rename the table back.
  *Walked by `tests/e2e.sh` at 91520db (MariaDB 10.11.14; PHP 8.4.19 and 8.5.11): passed.*
- [x] **U.41** **(release)** As the administrator: one notification, and under **Einstellungen →
  Rückmeldungen** one new entry „Fehler auf Seite news: PDOException", seen three
  times — not three entries, and only one notification.
  *Walked by `tests/e2e.sh` at 91520db (MariaDB 10.11.14; PHP 8.4.19 and 8.5.11): passed.*
- [ ] **U.42** **(release)** Copy the entry's text for support and paste it into a note. It says
  what broke, where, the version and the device, and the pages visited — and no
  name, no email address, no IP address and nothing anybody typed. A search
  (`q=…`) shows as `…`.
  *`tests/e2e.sh` at 91520db reads the support text: what broke, where, the version, the device and the pages; no name, email address, IP address or typed note: passed. A search showing as `…` is still by hand.*
- [x] **U.43** **(release)** Mark it **„Erledigt"**, then repeat U.40 once: the same entry is new
  again, counted four times, with a new notification.
  *Walked by `tests/e2e.sh` at 91520db (MariaDB 10.11.14; PHP 8.4.19 and 8.5.11): passed.*
- [ ] **U.44** **(release)** Note the date. An entry nothing has repeated is gone from
  Rückmeldungen 30 days after it was last seen, whatever its state; a report a
  person wrote is not affected by that.

**The menu and where things went** (see `docs/decisions/0011-the-start-checklist-and-a-seven-entry-menu.md`)

- [ ] **U.45** On a laptop, as yourself: the menu on the left is one list —
  Übersicht, Schüler, Kurse, Anwesenheit, Geld, Chats, Einstellungen, with
  **„Einrichtung"** above them while the checklist is unfinished. Nothing in it
  folds open or shut. Open **Rechnungen** or a single child: **Geld** or
  **Schüler** stays marked, so you always see where you are.
- [ ] **U.46** On your phone, the bar at the bottom reads **Übersicht, Schüler,
  Anwesend, Chats, Mehr**. **Mehr** holds the rest of the seven — Kurse, Geld,
  Einstellungen — so that every entry of the menu is on the bar or on **Mehr**
  (5.1b).
  *`tests/e2e.sh` reads the bar at 390px in Chromium, passed at 883be4d; the `shell` suite checks what „Mehr“ holds and that every entry is on the bar or on it. Opening it on a phone is still by hand.*
- [ ] **U.47** **(release)** As a trainer, the seventh entry is **Verwaltung**, not
  Einstellungen, the phone bar is the same as yours, and **Mehr** holds Kurse,
  Geld and Verwaltung.
- [ ] **U.48** On a child that has a login, **„Portal als … ansehen"**, on your
  phone: the family's bar reads **Übersicht, Beiträge, Chats, Profil**, with no
  **Mehr**. **Profil** has a row **„Anmeldung und Darstellung"**, which opens
  their **Mein Konto**. **Ansicht beenden** afterwards.
  *`tests/e2e.sh` reads the family's bar and the row „Anmeldung und Darstellung“ signed in as the family, not through „Portal als … ansehen“, passed at f5d3c28.*
- [ ] **U.49** **Einstellungen** opens with cards above the tabs: **Verwaltung**,
  **Zugänge**, **Änderungen**, **Einrichtung ansehen** and **Erweitert**. Each card
  opens its page, and **Einstellungen** stays marked in the menu. Postausgang is
  not among them — it is under Chats (U.50).
- [ ] **U.50** At the top of **Chats**: **„Neuigkeiten"** and
  **„Postausgang"**, each opening its page with **Chats** still marked. An
  administrator also has „Alle Einzelchats" there.
- [ ] **U.51** At the top of **Beiträge** and of **Rechnungen**, a switch
  **Beiträge · Rechnungen** takes you from one to the other; both are titled
  „Geld", and **Geld** stays marked on both.
- [ ] **U.52** **Mein Konto** ends with **„Datenschutz und Hilfe"**: the
  Datenschutzerklärung, „Etwas funktioniert nicht", the version and **Abmelden**.
  On a family's phone this is the only way to the privacy notice — **Profil →
  „Anmeldung und Darstellung"** (U.48) — so check it there too.
  *`tests/e2e.sh` checks it as the family, reached from Profil, passed at f5d3c28. As yourself it is still by hand.*

**Fewer fields at first**

- [ ] **U.53** On a course, **Tarife → + Neu**: the name and the prices are shown;
  everything else waits under **„Mehr Möglichkeiten"**. On a child's **Kurse**
  tab, the agreed price, the payment day, the dates and the discount wait there
  too — and the box opens by itself on a child that already has one of them set.
  Nothing has to be opened to save.
  *`tests/e2e.sh` saves a new tariff without opening „Mehr Möglichkeiten“, passed at 91520db. The child's Kurse tab is still by hand.*
- [ ] **U.54** **Einstellungen → Betrieb**, the Umsatzsteuer choice: with
  „Kleinunternehmer (keine Umsatzsteuer)" there is no „Steuersatz in Prozent" and
  no „UID-Nummer"; pick „Mit Umsatzsteuer" and both appear. **Leave without
  saving** if yours is Kleinunternehmer. **„Warum?"** opens the legal background.
- [ ] **U.55** **Verwaltung → Geld & Zahlungen**: „Standard-Zahlungsempfänger" is a
  list of your recipients by name — not a number to type — and an archived one is
  not offered.
- [ ] **U.56** Under **Mein Konto**, set **Sprache** to English and save — the
  signed-in bar has no language switch any more — then open the privacy notice
  (**Mein Konto → Datenschutz und Hilfe**). With no English text written, you see
  the German one with „This privacy notice is only available in German. …" above
  it. Set **Sprache** back to Deutsch. Signed out, the sign-in page still has its
  **EN**/**DE** switch at the top.
  *`tests/e2e.sh` opens the notice with `&lang=en` in the address rather than through Mein Konto, passed at f5d3c28.*
- [ ] **U.57** **(release)** On the copy, save SMTP and do not test it. On a
  child „Ohne Anmeldung" the card **„Zugang zum Portal"** shows „Einladen geht
  noch nicht" and „E-Mail-Versand zuerst testen: unter „Einstellungen → SMTP"
  die Verbindung prüfen." where **„Einladung senden"** would be, and so does the
  wizard's card „Per E-Mail einladen". After **„Nur Verbindung prüfen"**
  succeeds, the card offers its form and the invitation goes out. Change the
  server and save: the card says it again until the next passing test. A card
  opened before that change and sent after it is refused with „Eine Einladung
  lässt sich noch nicht verschicken. …", and nothing is sent.

**After an update** — on the phone and on the computer, without clearing anything

- [ ] **A.0** Upload the new version and open the portal in the browser that
  had it open before. The page source links `app.css?v=` and `app.js?v=`
  followed by twelve letters and digits, not the version number. The account
  menu at the top right opens as a styled list, and the bell does not move when
  tapped. Club colours, if set, are the current ones.

**One person, one address, and the address signs in** (ADR 0021) — on the iPhone
where it says so

- [ ] **A.1** Signed out, the sign-in page asks for „E-Mail-Adresse" and
  „Passwort", nothing else. Sign in with the address in odd capitals,
  `LENA@Beispiel.AT`: it works. On the iPhone the Keychain offers the saved
  address and fills the password.
- [ ] **A.3** A wrong password, an unknown address, a suspended login and an
  invitation not yet opened each give **the same** sentence: „Anmeldung nicht
  möglich. Bitte E-Mail-Adresse und Passwort prüfen. Noch nicht eingerichtet?
  Dann zuerst den Link aus der Einladung öffnen." Something that is no address
  at all, `lena.hofer`, the browser stops before it is sent and asks for an
  e-mail address. Sent anyway, it gets the same sentence, and the eleventh try
  „Zu viele Versuche. Bitte später erneut versuchen.", as an address does: the
  `logins` and `security` suites check that, not this walk (ADR 0030 §1).
- [ ] **A.4** „Passwort vergessen" with an unknown address and with a known one:
  the page says the same thing and names no address. Only the known one puts a
  mail in **Postausgang**, to the login's own address.
- [ ] **A.5** „Passwort vergessen" for a login that is still only invited: the
  mail that arrives is **the invitation** again, not a reset link. For a
  suspended login nothing arrives.
- [ ] **A.6** In **Postausgang**, as the trainer, open any invitation or reset
  mail: its text is not shown. Nobody but the mailbox reads a link that sets a
  password.

**Two ways to add a person** (ADR 0021)

- [ ] **A.7** **Schüler → + Schüler anlegen → „Nur die E-Mail-Adresse bekannt?
  Ohne Namen einladen"**, a real second address, language English. The flash
  says the invitation is on its way, and „Offene Einladungen" lists the address
  with the date it was sent. The mail opens "Hello," with no name and says the
  person fills in their details and then chooses a course.
- [ ] **A.8** Open that link on a phone that has never chosen a language: the
  page is in English and says whose invitation it is. Leave the names empty,
  then try a birth date in the future: both are refused, and what was typed
  comes back (not the passwords). Fill everything in: you land on your own
  page, „Kurs wählen" first in „Noch zu ergänzen". The trainer's bell shows
  „Neu im Portal: …" linking to the new child, and **Änderungen** names the
  child as the one who made the record.
- [ ] **A.9** **(release)** Double-tap „Konto aktivieren" on a slow connection:
  exactly one child is made.
- [ ] **A.10** As the family from A.8, „Kurs wählen" → ask to join a course. As
  the trainer, the child's page says „Kursanfrage beantworten"; approve it. The
  family's „Kurs wählen" is gone.
- [ ] **A.11** „Per E-Mail einladen" with an address that is already a login,
  that has an invitation on its way, or that is on a child who has no login yet:
  each is refused, saying why — the last one names the child and leads to their
  page, where „Einladung senden" is the way. Nothing is written.
- [ ] **A.12** „Offene Einladungen": „Erneut senden" sends a new link (the old
  one stops working); „Zurückziehen" asks for nothing typed, and the old link
  then says „Link nicht mehr gültig". Inviting the address again works.
- [ ] **A.13** **Schüler anlegen** with a course on the first step and „Per
  E-Mail einladen" with the child's own address on the second (L.9): the
  invitation asks only for the password and the privacy notice, and afterwards
  the family is already in the course. Delete a child whose invitation was never
  accepted: the old link is dead, and no login is left behind on **Team und
  Zugänge**. As a trainer, deleting a child that is linked to a team member's
  login never deletes that login.

**Families fill in their own details**

- [ ] **A.14** Sign in as a family whose child has no birth date, address or
  emergency contact filled in.
  Their overview and their child's „Profil" tab say „Noch zu ergänzen" with each
  of those — and never the phone number.
- [ ] **A.15** As the family, on „Profil", fill in the birth date, the postal
  address and the phone, and save. As the administrator, **Änderungen** shows
  **one** line for that save, under the family's name, with each field before
  and after, a date as „27.01.2019".
- [ ] **A.16** **Änderungen → „Von Familien"** lists that line and none of the
  trainer's own. (Viewing the portal as that family changes nothing at all —
  5.11a — so nothing done that way can be listed there.)
- [ ] **A.17** As the family, add, change and remove an emergency contact. Each
  is one line in **Änderungen**, named „Kind · Kontakt", and the removal's line
  keeps the whole contact so it can be typed in again. A contact's line does
  not show a student number.
- [ ] **A.18** As the trainer, on the same child, set every one of those fields
  back by hand — names, birth date, address, phone, every contact. Nothing a
  family can write is read-only for her: typing the old value back is the undo.
- [ ] **A.20** On the Kontakte tab with two contacts, change the first one's
  phone to something invalid in its „Kontakt bearbeiten" and save: the refusal
  opens **that** contact with what you typed; the other contact's form still
  shows the other person's own values. Saving the other one changes only it.

**The address on invoices**

- [ ] **A.21** Issue an invoice for a child, then, as the family, change the
  postal address. Download the old invoice and send it again from
  **Rechnungen**: both show **the old address**, opened in a real PDF reader.
  The next invoice issued shows the new one.
- [ ] **A.22** As the family, empty the address. An invoice above 400 € for
  that child is refused, saying the address is missing; one below is issued.

**Still the same for everybody who signed in before**

- [ ] **A.23** **(release)** On a copy of a real portal, upload this version.
  Every existing login signs in with its email address exactly as before.
  Nobody was mailed about it.
- [ ] **A.24** **(release)** `tests/mariadb-local.sh` is green, and
  `tests/e2e.sh` ends in `RESULT: PASS`, on the MariaDB and PHP the club's host
  runs.

**After the reviews of the first build**

- [ ] **A.25** On the phone, as the trainer, „Portal als {Familie} ansehen", then
  leave the phone until the session times out — two hours without opening a
  page, as setup writes it — or, on a copy, set `session_idle_minutes` in
  `config/config.php` low; no page in the portal sets it. Open the portal
  again: the sign-in page, and **no** „Ansicht beenden" anywhere. Sign in as
  somebody else on that phone: they are themselves, with no bar about viewing
  anybody, and nothing they tap makes them the trainer. The same after opening an invitation link on that phone.
- [ ] **A.26** As the administrator, **Änderungen**: a child who set themselves
  up from an invitation is listed as having made their own record, not as
  „automatisch". A „Passwort vergessen" asked on a phone where a view had timed
  out names nobody in the audit log.
- [ ] **A.27** The flashes say what happened, in plain words: a family's save,
  „Deine Angaben sind gespeichert."; a family's save after somebody else saved
  the same child, „Inzwischen hat jemand anderer etwas an diesem Profil
  gespeichert. Deine Eingaben sind noch da – bitte prüfen und noch einmal
  speichern." with the typed values still in the boxes; removing a contact,
  „Entfernt: {Name} ({Beziehung}), {Telefon}. Aus Versehen? …"; the access
  card's invitation, resend and reset link each name the address the mail went
  to.

**The chat, like a messenger** (ADR 0022) — on the iPhone where it says so

- [ ] **C.1** As the trainer, **Chats**: „Kursgruppen" lists a group for
  every running course, then „Einzelchats". A course made today has its group
  straight away; an archived course's group is gone from the list.
- [ ] **C.2** As a child enrolled in one course, on the iPhone at 320 px: the
  list shows that one group and the chat with the trainer, nothing else. Tap the
  group: one thing on the screen at a time, the arrow at the top goes back, and
  the writing box sits above the menu bar, not under it, and opens at the newest
  message.
- [ ] **C.3** In a group, as the child, send a text and a photo. The trainer and
  another child of the course see both; a child of another
  course cannot open the group at all (the address typed by hand says
  „Unterhaltung nicht gefunden"). No e-mail and no bell entry is made for a group
  message.
- [ ] **C.4** Take the child out of the course (end the enrolment). On the next
  page the group is gone from their list. Put another child in: they read the
  group's earlier messages.
- [ ] **C.5** As the trainer, „⋯" on a child's group message → „Nachricht
  entfernen": everybody sees „Nachricht entfernt", its photo no longer opens. The
  same „⋯" → „Wiederherstellen" brings it back, for 30 days (D.2).
- [ ] **C.6** As a child, „Neue Nachricht" → the trainer: the chat opens, the
  first message makes it, and it says „Hier schreibt ihr zu zweit. Die
  Administratoren des Vereins können mitlesen." As the administrator, it is not
  in your list and not in your badge, but under „Alle Einzelchats"; you can read
  it and cannot write in it. A second trainer cannot open it.
- [ ] **C.10** On a Mac, pick a photo whose Preview → Werkzeuge → Informationen
  has a GPS tab, and send it into a group. Save it back from the chat and open
  it in Preview: no GPS tab, no camera, and it stands the same way up. On the
  iPhone, a photo taken held upright stands upright in the chat for everybody.
- [ ] **C.11** As the trainer, on the page of a child who has a chat with a
  second trainer and one with another family, „Portal als … ansehen": neither
  chat is in their list, and the group opens without a writing box. „Ansicht
  beenden", then sign in as the child: the group's new messages are still unread.
- [ ] **C.12** In a group with a child whose family has no login yet, open
  the people symbol in its top bar („Wer ist in der Gruppe?") as another child:
  that child is not named, only „Dazu 1 Person ohne Zugang zum Portal". As the
  trainer the same sheet names them, with „Noch kein Zugang".
- [ ] **C.13** **(release)** On a copy of a real portal, upload this version:
  every existing course has its group, every chat between a child and a trainer
  is still there, and the update refuses nothing.
- [ ] **C.14** As the trainer, write to a child in your chat with them; as the
  administrator, write to the same child too. As the trainer, **Schüler** → that
  child → „Portal als … ansehen" on the card „Zugang zum Portal": the bell shows
  neither message — no „Neue Nachricht von …" line — its number counts only the
  other notices, and there is no „Alle gelesen" in it. „Ansicht beenden", then
  sign in as the child: both notices are there, and unread.
- [ ] **C.15** As the administrator, **Einstellungen → Zugänge**, a trainer's
  row tapped open, „Portal als diese Person ansehen". **Chats →
  Neuigkeiten → + Neuigkeit**, a title and some text, „Speichern": refused with
  „Beim Ansehen als jemand anderer lässt sich nichts schreiben oder ändern.
  Beende zuerst die Ansicht." Open a course's group: no writing box, and no „⋯"
  on any message. „Ansicht beenden": every group message is still there, and no
  news item was added.
- [ ] **C.17** A photo that holds more than one picture: on a Samsung or a Pixel
  a motion photo („Bewegtes Foto"), on an iPhone a photo in HDR or a portrait
  photo. Send it into a group, then on the Mac save it back from the chat: it
  opens in Preview as the same photo, the right way up, plays no video,
  Werkzeuge → Informationen shows no GPS tab, and in the Finder it is smaller
  than the original. On an HDR screen it may look less bright than the
  original: the brightness map is a second picture in the file, and it goes
  with the rest. Then send a screenshot (PNG) and a WebP saved from a browser:
  both open and look as they did.
- [ ] **C.18** As the trainer, **Schüler** → a child whose login is in use →
  „Portal als … ansehen" on the card „Zugang zum Portal". The view opens on
  **Übersicht**, which has no „Nachricht schreiben" any more: open
  `?page=messages&new=1` by hand. The page „Neue Nachricht" says „Beim Ansehen
  als jemand anderer lässt sich nichts schreiben oder ändern. Beende zuerst die
  Ansicht." and lists nobody — not even the „Trainerteam". **Chats** has no
  „Neue Nachricht" button. In the child's group, the people symbol in the top
  bar lists the members, and tapping a trainer there opens nothing.
- [ ] **C.19** A photo can carry a small preview of itself inside the file,
  and after cropping, an editor can leave that preview showing the whole photo
  from before. Crop a photo in an editor, save it as a JPEG with the option
  that keeps a preview (thumbnail) switched on, and send it into a group. On the
  Mac, save it back from the chat: Preview opens it as the cropped photo, the
  right way up, and Werkzeuge → Informationen shows no GPS tab and no camera. On
  the iPhone it looks in the chat as it did in the editor. Whether the preview
  is gone only exiftool can show, if it is at hand: `exiftool -a -G1 datei.jpg`
  on the copy saved back prints no line with `Thumbnail` in it. Run it on the
  original first, because only an original that lists `[JFIF] Thumbnail TIFF`
  tests what this round changed; `[IFD1] Thumbnail Image` is a preview inside
  the camera data, which was removed before. Write down which of the two your
  file had, or that there was no exiftool to ask.
- [ ] **C.20** On the example data, not on a course with real families: the
  switch below sends every family in the course an e-mail. Sign in as a child
  who has a login and, if the bell shows a number, tap „Alle gelesen"; sign out.
  As the trainer, write to that child in your chat with them. Then **Kurse** →
  the child's course → „Termine" → a coming date → „Was ist damit": „Entfällt",
  switch on „Alle Kursteilnehmer per E-Mail informieren", „Speichern". Now
  **Schüler** → the child → „Portal als … ansehen" on the card „Zugang zum
  Portal": the number on the bell is 1, and the pane shows „{Kurs} – {Datum}"
  with „Entfällt" under it and no „Neue Nachricht von …" line. „Ansicht
  beenden", then sign in as the child: the number is 2, and both are there.
  Afterwards, as the trainer, open the same date again and save it as „Findet
  statt" with that switch off: „Termin folgt wieder dem normalen Plan."

### Billing and invoices after the review of October 2026

On the example data or a copy, never on a real family: several of these
cancel, mark paid or e-mail.

- [ ] **B.1** **Kurse** → a course → **Tarife**: archive the tariff one child
  is on. On that child, **Kurse** → „Tarif, Zahlungsweise und Rabatt": change
  only the payment day and save. The child's line still names the archived
  tariff, and **Geld → „Monatsbeiträge"** previews a charge for them rather than
  „Kein Tarif gewählt". Once the page is updated (frontend-dev), the tariff list
  shows that tariff with „(archiviert)" after it; choosing another one works,
  and no other child can be put on the archived one.
- [ ] **B.2** On the same form, set „Ausgetreten am" before „Dabei seit" and
  save: „Das Enddatum liegt vor dem Startdatum.", and nothing changed.
- [ ] **B.3** Make next month's charges under **Geld → „Monatsbeiträge"**. On
  one child, „Beitrag stornieren" on the new charge, change their agreed price,
  and run the same month again: one charge is created, at the new price — not
  „Nichts zu tun". Run it a third time: nothing more. **Änderungen** shows the
  cancellation as „Storniert", with no `billing_key` line.
- [ ] **B.4** On a charge with no invoice, „+ Zahlung erfassen" for the whole
  amount *without* „Zahlungseingang bestätigen". Issue an invoice for it
  (**Rechnungen → Rechnung erstellen**), then „Als bezahlt eintragen". The
  charge shows **one** payment, now „Bestätigt" — not a second one beside it —
  and the invoice says „Bezahlt". Repeat with a part of the amount recorded:
  the rest is added as a second payment, and both are confirmed.
- [ ] **B.5** On a charge that is on an invoice that is not cancelled, try
  „Beitrag stornieren": refused, naming the invoice's number. Cancel the
  invoice; now the charge can be cancelled. (Once the page is updated, the
  button is not offered while the invoice stands, and the charge says which
  invoice holds it.)
- [ ] **B.6** As a family, **Mein Konto**: switch off „Erinnerung, wenn ein
  Beitrag offen ist" and save. As the trainer, on that child's invoice „Per
  E-Mail schicken": refused in a sentence that says the family switched these
  e-mails off. The invoice does **not** say „per E-Mail geschickt am", and
  **Postausgang** holds nothing new. Switch it on again as the family: the
  invoice goes, and only then says so.
- [ ] **B.7** A family whose language is English (**Mein Konto → Sprache**):
  issue them an invoice and download the PDF as the trainer, in German. It is
  in English — „Invoice number", „Billed to" — and so is the copy the e-mail
  carries. A German family's invoice stays German when you switch your own
  language to English and download it.
- [ ] **B.8** **(release)** On a copy with more than 200 invoices, one of the
  oldest unpaid and past its date: once **Rechnungen** is updated
  (frontend-dev), it is under „Überfällig", the count beside „Überfällig"
  includes it, the list pages through all of them, and „Offen und überfällig"
  adds only what is still owed on an invoice that is part paid.
- [ ] **B.9** On a child in a course with no „Ausgetreten am", set
  „Mitgliedschaft bis" on the child to the 15th of next month. **Beiträge
  anlegen** for that month: the charge is for the days up to the 15th (half a
  30-day month is half the price), and its period ends on the 15th.
- [ ] **B.10** Give a child „Erster Monat gratis" on a course, record their
  leaving („Austritt eintragen"), then add them to the course again under
  **Kurse** → the course. The message says they are back and that an earlier
  price or discount no longer applies; „Tarif, Zahlungsweise und Rabatt" shows
  no discount and no own price, and their first month back is charged in full.
  Adding a child who is already in the course is refused.
- [ ] **B.11** **Verwaltung → Altersgruppen**: make a band 11 to 12. **Schüler**,
  filter by it: a child whose eleventh birthday is today is in the list, so is
  one who turns thirteen tomorrow; one who turns eleven tomorrow and one whose
  thirteenth birthday is today are not.
- [ ] **B.12** With „Monatsbeiträge automatisch anlegen" on **Beiträge**, and
  the month not yet billed: sign in as a family whose language is English and
  open two pages a minute apart. As the trainer, the new charges read „Beitrag
  Oktober" (or the German month), not „Beitrag October", and the audit log has
  „billing.generated" with nobody as the actor.
- [ ] **B.13** **Geld → „Überfällig"** → „1 Erinnerung schicken" → „Jetzt
  schicken", for a child with one overdue charge and one past its date but fully
  paid: the banner says „1 Erinnerung geht raus." and leaves nobody out.
- [ ] **B.14** **Verwaltung → Geld & Zahlungen**, the defaults under the
  payment recipients: there is no „Zahlungsziel für Monatsbeiträge" any more. When a charge is due comes from the tariff's
  „Zahltag" and „Tage bis überfällig", which B.9's charge shows.

### Every student has a login, and the wizard (ADR 0023, amended by ADR 0030)

On the example data or a copy, never on a real family: several of these make,
use and delete logins. These checks are about what happens; how the screens look
and behave on a phone is [Z.1–Z.6](#the-screens-of-adr-0030-on-a-phone). The
usernames and the one-time sign-in links this block once walked — L.11 to L.20,
L.20d, L.21b and L.22 — are gone again (ADR 0030); everybody signs in with their
own address.

**The update**

- [ ] **L.1** **(release)** On a copy of a portal from before this release with
  children who have no login — two of them sharing a parent's address — upload
  the files and open any page. The update runs without a command, the portal
  opens again, and **Schüler** shows every child as before. Each child's
  **„Zugang zum Portal"** card says „Ohne Anmeldung"; children who had a login
  keep it, with the same address and state.
- [ ] **L.2** **(release)** On that copy, **Einstellungen → System → Beispieldaten**:
  „Beispieldaten entfernen" still removes every example child, and **Zugänge**
  lists no example login afterwards.
- [ ] **L.2a** **(release)** On a copy of a portal from before ADR 0030 with a
  child who signed in by a username and has a chat with the trainer, upload the
  files and open any page. The update runs; that child's card says „Ohne
  Anmeldung", and **Zugänge**, the child's page and Mein Konto name no username
  („Änderungen" keeps its old lines). The trainer still has the chat, every
  message in it. Type the child's address on the card (L.9a) and „Einladung
  senden": the child is „Eingeladen", sets up from the mail as anybody does,
  and finds neither that chat nor anything old in the bell.
- [ ] **L.2b** **(release)** On that copy, keep a sign-in link made before the
  update (an address ending `?page=activate&token=…`, from a QR code or a
  chat). Open it signed out: „Link nicht mehr gültig", with no password boxes
  and nothing of the login on the page, and **Postausgang** holds nothing. A
  link's page opened before the update and sent after it is refused and
  changes nothing. Once the link would have lapsed (48 hours) and the
  background work has run, its row is gone from `auth_tokens`.

**The wizard „Schüler anlegen"**

- [ ] **L.3** **Schüler → „+ Schüler anlegen"** (and the overview's button, and
  the checklist's „Kinder eintragen" on a portal with no child) opens „Neuer
  Schüler", „Schritt 1 von 2: Wer kommt dazu?". An old bookmark to the student
  page without a child opens it too.
- [ ] **L.4** Leave the first name empty and tap **Weiter**: refused in a
  sentence, what you typed still there. Fill in first and last name, a course
  from the list, a status, and **Weiter**: step 2 names the child. The address
  bar shows `draft=` and a string of letters and digits — never the child's
  name or birth date. **Schüler** does not list the child yet.
- [ ] **L.5** On step 2, **Ändern**: step 1 again with everything filled in.
  Change the first name, **Weiter**: step 2 shows the new name.
- [ ] **L.6** On step 2, switch to WhatsApp and back on the iPhone (the tab
  reloads): still step 2, with the same child. Leave the tab for more than two
  hours and tap a card's button: „Die Angaben waren nicht mehr da. Bitte noch
  einmal eintragen." and step 1. Nothing was created.
- [ ] **L.7** In a second tab, start another child. Finish both: two children,
  each with their own name — the tabs did not mix them.
- [ ] **L.8** Card **„Ohne Anmeldung"**, „Ohne Anmeldung anlegen": the child is
  created, in the course and on the tariff chosen at step 1, from today. The
  done page says „Ohne Anmeldung – du trägst alles selbst ein.", that the child
  cannot sign in yet and the invitation follows once the address is known, and
  „Versehentlich angelegt? …"; its „Anmeldung einrichten" opens the child's
  access card, which says „Ohne Anmeldung".
- [ ] **L.9** Card **„Per E-Mail einladen"**, „Anlegen und einladen", with an
  address another login has: refused, naming whose; nothing created, and the
  card comes back with the address. With an address of the child's own: created,
  the invitation in **Postausgang**, and the done page names the address and
  until when its link works. With mail not set up, the card says what is missing
  instead of a form. Step 2 has these two cards, „Per E-Mail einladen" and „Ohne
  Anmeldung", and nothing about a username.
- [ ] **L.9a** The access card of a child „Ohne Anmeldung" (ADR 0030 §6): an
  address box — filled with the address on the record, if there is one — the
  invitation's language and „Einladung senden". An address without „@",
  `kein-at`, the browser stops before it is sent. Type an address another
  person signs in with and send it: the page comes back at this card, not at
  its top, with the reason, naming whose, on the card itself as well as at the
  top of the page, the typed address still in the box, and the child still
  „Ohne Anmeldung". With the child's own, language English: „Die Einladung an
  … ist unterwegs.", the page comes back at the card, which now says
  „Eingeladen" with that address, **Postausgang** holds the invitation in
  English, and „E-Mail-Adresse" under Persönliche Daten shows the same address.
  On a child with no address, **Noch zu tun** offers „E-Mail-Adresse
  eintragen", which leads to this card while mail is set up and to the address
  field under Persönliche Daten before.
- [ ] **L.9b** Two children „Ohne Anmeldung" whose records carry the same
  address — a parent's, typed on both. Each card says „Diese Adresse steht
  auch bei …", naming the other child, and that each child needs an address of
  their own, the parents' belonging with the contacts; it offers no form, so
  nothing can be sent. **Noch zu tun** on each offers „Eigene E-Mail-Adresse
  eintragen", naming the other, and leads to the address field. A card opened
  before the second address was saved, and sent afterwards, is refused, naming
  the other child, and nothing is sent. Give one of them an address of their
  own and save: each card offers its form again, **Noch zu tun** says „Zugang
  einladen", and the other's invitation goes to the address left on its record
  (ADR 0030 §5).
- [ ] **L.10** Choose a course with one place left at step 1, fill its last
  place on another child (**Kurse**), then tap a card on step 2: „Dieser Kurs ist
  inzwischen voll …"; nothing created. **Ändern**, „Noch keinen Kurs", and it
  works.
- [ ] **L.10a** Two phones, both signed in as staff, each at step 2 with a
  different child for the same course, which has one place left. Tap „Ohne
  Anmeldung anlegen" on both, as nearly together as you can: one child is made
  and is in the course; the other phone says „Dieser Kurs ist inzwischen voll.
  Bitte einen anderen wählen – oder „Noch keinen Kurs“." and is still at step 2
  for its child. The course has as many members as places, not one more.
- [ ] **L.10b** On the phone, on a slow connection if you can, double-tap „Ohne
  Anmeldung anlegen" on step 2: **Schüler** lists the child once, and the done
  page shows the green „{Vorname Nachname} ist angelegt." No red „Diese Eingabe
  wurde bereits verarbeitet." appears.
- [ ] **L.10c** From that done page, go Back — and reload, if the browser still
  shows the cards: the done page again, headed „{Vorname Nachname} ist schon
  angelegt", with what came of it, „Zur Seite von …" and „Noch einen Schüler
  anlegen", and no card to tap. Reload: the same. Go Back once more, to step 1,
  and if it still shows the child's details, „Weiter": the same page.
  **Schüler** still lists the child once.

**The privacy notice, and an invitation in a browser several people use**

- [ ] **L.20a** On the example data, never on a portal families use — while
  this is unticked nobody can be invited: open a child „Ohne Anmeldung" in a
  second tab, then **Einstellungen → Datenschutz**, untick „Die
  Datenschutzerklärung ist vollständig und zur Verwendung freigegeben." and
  save. The child's card, reloaded, shows „Einladen geht noch nicht" and „Die
  Datenschutzerklärung unter „Einstellungen → Datenschutz“ freigeben." with
  „Zur Einrichtung" where „Einladung senden" was, and so does the wizard's card
  „Per E-Mail einladen". The second tab's card, sent as it was, is refused with
  „Eine Einladung lässt sich noch nicht verschicken. …", and the child is still
  „Ohne Anmeldung". Then, still unticked, as the trainer: the card and the
  wizard's card say „Eine Administratorin muss zuerst die
  Datenschutzerklärung freigeben." and „Das richtet eine Administratorin unter
  „Einstellungen“ ein." — nothing sends her to „Einstellungen", which she
  cannot open. Tick the box again and save.
- [ ] **L.20b** A browser several people use, such as a tablet at the hall.
  Open the link from a child's invitation mail there and leave its page
  without saving. Sign in on that browser as somebody else — the trainer, say
  — then go Back to the link's page, or open the portal's address with
  `?page=activate` at the end: „Link nicht mehr gültig". Whoever signs in next
  does not land in the family's half-finished set-up; the link itself, opened
  again, still shows its page.
- [ ] **L.20c** **(release)** The same with the trainer signed in first. On a
  copy, set `session_idle_minutes` in `config/config.php` to 2 (setup writes
  120: two hours). Sign in as the trainer, then in the same browser open the
  link from a child's invitation mail and fill in its page, but tap save only
  after three minutes without opening any other page: „Dieser Link ist
  ungültig oder abgelaufen. …" above „Link nicht mehr gültig". This is by
  design and fails safe: the trainer's session ran out and took the
  half-opened link with it. Open the link again: it works, and the child is
  signed in. Put `session_idle_minutes` back.

**Replacing a login, and enrolment**

- [ ] **L.21** On a child whose login is in use, **„Anmeldung löschen"** asks
  for the address typed; typed in other capitals, it deletes.
  The child stays, „Ohne Anmeldung", with courses, charges and invoices; the
  child's chat with you is gone from **Chats**. The address is still on
  the record, so „Einladung senden" is offered again.
- [ ] **L.21a** **(release)** A team member's login left on a child's record,
  as a portal from before ADR 0010 can have. On a copy, in the database, point
  one child's `students.account_id` at a trainer's login that no other child
  has. As a trainer, „Anmeldung löschen" on that child is refused. As an
  administrator, „Anmeldung löschen" with the trainer's address typed: the
  message names the child and says the team member's login stays —
  „{Vorname Nachname} hat jetzt eine neue, leere Anmeldung. Die Anmeldung von
  {Trainerin} gehört zum Team und bleibt, wie sie ist." — and the child has a
  fresh, empty login, „Ohne Anmeldung". The trainer is still on **Zugänge**,
  signs in as before, and finds her chats. „Zugang löschen" in her row on
  **Zugänge** is then an ordinary deletion of a team login, with nothing sent
  round in a circle.
- [ ] **L.23** „Portal als … ansehen" on the child's card is offered only for a
  login in use — never for „Ohne Anmeldung", „Eingeladen" or „Gesperrt".
- [ ] **L.24** A child „Ohne Anmeldung" can be put into a course on its
  **Kurse** tab as before; billing runs for them as for anybody.
- [ ] **L.25** In a course with a child „Ohne Anmeldung" and a child whose login
  is in use, change a date with „Alle Kursteilnehmer per E-Mail informieren"
  switched on. Signed in as the child in use, the bell shows the change. Then
  invite the first child and accept the invitation: their bell does not list the
  change from before they had a login.
- [ ] **L.25a** On a child „Ohne Anmeldung" who has a charge, **Rechnungen →
  Rechnung erstellen**. Then invite the child and accept the invitation, as in
  L.25: their bell has no „Neue Rechnung: …". A login nobody signs in with
  collects no bell notices, so there is nothing old waiting when somebody
  first does.

### The screens of ADR 0030, on a phone

The wizard's second step, „Zugänge" and Mein Konto as the designer specified
them (docs/design/2026-10-05-accounts-and-chat-screens.md, with its addendum)
and frontend-dev built them; what the card „Zugang zum Portal" does is L.9a and
L.9b. As an administrator on the example data unless a check says otherwise:
Lena and Jonas sign in, Mia and Elias are „Ohne Anmeldung". The ones that send
mail need it set up.

- [ ] **Z.1** **(iPhone)** On the iPhone held upright, and at 320 pixels wide
  if one is to hand (an iPhone SE of the first generation): **Schüler → +
  Schüler anlegen**, a name, **Weiter**. Step 2 shows „Per E-Mail einladen"
  with „Empfohlen", one sentence, the address, the language and „Anlegen und
  einladen", then „Ohne Anmeldung" with its sentence and „Ohne Anmeldung
  anlegen", nothing cut off and nothing wider than the screen. Write down
  whether „Ohne Anmeldung" begins on the first screen without scrolling: it
  does in Chromium on a window 780 pixels tall (VALIDATION.md), and Safari on
  a phone shows less than that. Make one child each way. By e-mail the done
  page says „Die Einladung ist unterwegs." and „Die Einladung geht an … Der
  Link darin gilt bis …"; without sign-in it says „Ohne Anmeldung – du trägst
  alles selbst ein." and offers „Anmeldung einrichten", which opens the new
  child's card.
- [ ] **Z.2** **Zugänge**: the groups „Trainer", „Administratoren" and
  „Schüler", each with its count. Above the students, „Alle (4)" and „Ohne
  Anmeldung (2)"; „Eingeladen" and „Gesperrt" are not there while nobody is in
  them. Invite Mia from her card and suspend Jonas's login on his: the chips
  read „Alle (4)", „Eingeladen (1)", „Ohne Anmeldung (1)" and „Gesperrt (1)",
  and each shows only its children. A student's row has the name, the address —
  none for a child „Ohne Anmeldung" — for Mia until when her link works, and the
  badge, and leads to the child's card. Under the students: „Einladen, sperren
  und löschen machst du auf der Seite der Schülerin oder des Schülers." Tap a
  trainer's row: it opens to its actions and closes on a second tap; your own
  row does not open, and for the trainer no team row does. Put Mia and Jonas
  back („Einladung zurückziehen", „Zugang entsperren").
- [ ] **Z.3** **Mein Konto**, signed in as Lena's family: under „Anmeldung" the
  address and „Mit dieser Adresse meldest du dich an.", „E-Mail-Adresse ändern"
  and „Passwort ändern"; under „Darstellung" the three switches
  „Neuigkeiten per E-Mail erhalten", „E-Mail bei neuen Nachrichten und
  Änderungen im Training" and „Erinnerung, wenn ein Beitrag offen ist". Sign
  out, „Passwort vergessen" with Lena's address, and set a new password from
  the mail: Mein Konto now says „Dein Passwort wurde per E-Mail-Link neu
  festgelegt" with the date and time, for 14 days, and staff read „Passwort
  zuletzt per E-Mail-Link neu gesetzt: …" on Lena's card.
- [ ] **Z.4** **(iPhone)** Signed out, tap the box on the sign-in page, and the
  one on „Passwort vergessen": the keyboard has „@" and „." beside the space
  bar, the first letter is not made a capital, and nothing is corrected.
- [ ] **Z.5** As the trainer, „Portal als … ansehen" on Lena's card, then
  **Profil → „Anmeldung und Darstellung" → „Datenschutzerklärung"**: the strip
  saying whose portal you are seeing is on the privacy notice too, and
  „Ansicht beenden" there gives you yourself back.
- [ ] **Z.6** Switch JavaScript off, as in 5.3g, and walk L.9a, L.9b, Z.1 (at
  any width), Z.2 and Z.3 again: the card's invitation goes out and the page
  comes back at the card, a refusal comes back at the card with the typed
  address, the wizard goes from step 2 to its done page, the chips filter, and
  a team row opens.

### What ADR 0026 removes, round one

What went is gone from every place it was, and what stays in its place works.
On the example data or a copy.

- [ ] **R.1** **(release)** On a copy of a portal from before this release whose
  children have custom fields filled in — note one child's values first —
  upload this version and open any page. The update runs and the portal opens:
  the custom fields are gone, with their values, and nothing else is missing.
  **Änderungen** still lists the earlier saves of those values, the field named
  „Früheres eigenes Feld". **Einstellungen → System** lists the copy of the
  database written just before the update; that copy still holds them.
- [ ] **R.2** **Einstellungen** has no „Eigene Felder für Schüler", not under
  „Erweitert" either. A child's page has no „Weitere Angaben", for staff or for
  the family, and the **Schüler** filter has no „Tarif und eigene Felder". An
  old bookmark to `?page=settings&tab=fields` opens **Einstellungen → Portal**.
- [ ] **R.3** Nothing can be copied any more: no „Kopieren" on a tariff, a
  level, an age group, a payment recipient or a news item, and no „Kurs
  kopieren". („Für den Support kopieren" puts text on the clipboard; it
  stays.)
- [ ] **R.4** **Schüler** has no „Diese Auswahl als Ansicht speichern"; above
  the list stand „Alle | Überfällig | Krank" and „Filter", nothing to save. A
  saved view's old address, `?page=students&saved=1`, shows all children.
- [ ] **R.5** No „An mehrere schreiben", at the top of **Chats** or on
  **Übersicht**; no „Auswahl anschreiben" under the **Schüler** filter; no
  „Zahlungserinnerung schreiben" on **Geld**, where the reminders under
  „Überfällig" stay. `?page=compose` answers „Seite nicht gefunden.".
  **Verwaltung** has no „E-Mail-Vorlagen".
- [ ] **R.6** **Postausgang** has no „Warteschlange senden". With mail set up
  and „Wartende Aufgaben beim Seitenaufruf erledigen" on, as it starts, send an
  invitation and open another page a minute later: the invitation has left the
  queue by itself, and „Letzter Versandlauf" at the top of **Postausgang** says
  when.
- [ ] **R.7** No „Datenblatt drucken" on a child's page and no „Leeres Formular
  drucken" in „Schüler anlegen"; `?page=print` answers „Seite nicht gefunden.".
- [ ] **R.8** **Verwaltung** has four tabs: Leistungsgruppen, Altersgruppen,
  Mitgliedschaft, Geld & Zahlungen — no „Tarife". A tariff that belongs to no
  course is named on every course's „Tarife" tab, as not billed until it has
  one.

### What ADR 0026 removes, round two

The dots, the status, the emoji, the pictures, the requests to write to another
family, and voice notes and files in new messages. R.9, R.14, R.15 and R.16 need
a copy of a portal from before this release that has profile pictures, a voice
note and a file in a chat, and a chat between two children; never the portal
the families use.

- [ ] **R.9** **(release)** On that copy, wait ten minutes after the last
  picture was saved, then note how many files `storage/uploads/avatar` holds in
  the file manager and how many problem reports with a screenshot **Einstellungen
  → Rückmeldungen** lists. Upload this version and open any page: the update
  runs and the portal opens, refusing nothing. Afterwards `storage/uploads/avatar`
  holds only the problem reports' screenshots — one file for each report with a
  screenshot, each of which still opens from its report — and no picture.
  **Einstellungen → System** lists the copy written just before the update. A
  picture saved less than ten minutes before the update may stay until the next
  daily cleanup; write it down if one did.
- [ ] **R.10** The pictures from before this release do not come back: right
  after the update nobody has a picture, and everybody appears by their
  initials — at the top right, in the **Schüler** list, in **Zugänge** and in
  every chat. Pictures added afterwards are ADR 0031's, checked in
  [P.1–P.20](#pictures-adr-0031).
- [ ] **R.11** Nobody has a dot, a status or an emoji: the account menu is as
  in 5.3i; **Zugänge** and the card „Zugang zum Portal" on a child's page have no
  line about when somebody was last here and no „Wann online? Letzte 30 Tage";
  nobody in a group has a dot or an emoji beside their name.
  **Einstellungen → Portal** has no „Grün – „online": aktiv innerhalb von
  (Minuten)", and its „Erweitert" no blue or yellow; **Einstellungen → System →
  Erweitert** has no „Wann jemand online war, aufbewahren (Tage)".
- [ ] **R.12** On an iPhone, signed in as a family, open the chat with the
  trainer and tap „+" („Foto anhängen"). Write down what opens: the camera, or
  the photo library, or a choice between them. Take or choose a photo and send
  it: it shows in the bubble, for the family and for the trainer. Then, if the
  phone lets you choose from the library, choose a screenshot, which an iPhone
  stores as a PNG: it is refused with „Bitte nimm das Foto mit der Kamera auf.",
  not with a list of file types. If it is sent instead, the iPhone handed it
  over as a JPEG; write that down, because then a screenshot gets through.
  Whether the camera opens here has not been measured on any phone yet: the
  portal asks for it with `capture="environment"`, and also tells the browser
  `Permissions-Policy: camera=()`.
- [ ] **R.13** As the trainer, in a chat, tap „+": choose a PNG screenshot from
  the phone's photos, send it, then a WebP if you have one. Both show in the
  bubble. A PDF is refused in a sentence.
- [ ] **R.14** **(release)** On the copy, open the chat that had a voice note
  and a file before the update: the voice note plays, and the file opens or
  downloads as before. The writing box has a „+" and no microphone.
- [ ] **R.15** **(release)** On the copy, sign in as one of the two children who
  had a chat with each other. It is under „Frühere Unterhaltungen", and opened
  it reads as before, with „Chats zwischen Schülern sind geschlossen. Was hier
  steht, bleibt lesbar. Schreib dem Trainerteam oder in deine Kursgruppe." at
  the top and no writing box. „Neue Nachricht" lists the coaching team only
  (14.5).
- [ ] **R.16** **(release)** On the copy, as a family who had a request to write
  to them waiting before the update: **Chats** has no „Möchte dir
  schreiben" and nothing to agree to.
- [ ] **R.17** **(release)** In a desktop browser's developer tools
  (**Netzwerk** / **Network**), open any page: its response carries
  `Permissions-Policy: camera=(), microphone=(), geolocation=()`.

### Age groups from the birth date alone, and the small example data (round three)

Levels stay, and so do the age bands under **Verwaltung → Altersgruppen**; what
went is the pin that held a child in a band. One rule gives every child's band,
and every place asks it. The students list's look, „Nach Alter" with it, is
walked under [The students list](#the-students-list).

- [ ] **N.1** Overlapping bands agree everywhere. **Verwaltung → Altersgruppen**:
  archive the three bands a new portal starts with (otherwise „Unter 12" keeps
  covering every child under 12 in N.2), then make „Früh" 8 to 11 and, below it,
  „Spät" 11 to 14; the page warns that they overlap. A child aged 11: their page
  names „Früh", the first band in Verwaltung's order that covers them. In
  **Schüler**, the filter „Früh" lists them and the filter „Spät" does not;
  `?page=students&sort=age` shows them once, under „Früh"; and Verwaltung counts
  them in „Früh" (with every other child of 8 to 11). Nowhere a different band.
- [ ] **N.2** Archive „Früh". The same child is now in „Spät" everywhere, a child
  of 8 under „Ohne Altersgruppe", and the filter no longer offers „Früh";
  Verwaltung lists it with nobody in it. Bring it back: both are counted in it
  again.
- [ ] **N.3** **(release)** On a copy from before this release with a child
  pinned to a band their age does not give, and one pinned who has no date of
  birth: after the update the first shows the band their age gives, the second
  no band at all. Lines under **Änderungen** written before still read
  „Altersgruppe". The pins are only in the copy under `storage/backups`.
- [ ] **N.4** With the example data, **Schüler → „Nach Alter"** (`sort=age` in
  the address): „Unter 12" with Lena (9) and Jonas (10), then „Jugend" with
  Elias (13), then „Ohne Geburtsdatum" with Mia, each youngest first, the
  counts 2, 1 and 1 beside the names; no other card (17.4).
- [ ] **N.5** The example data shows one of each thing. **Kurse → Kindertraining**:
  Jonas's request to join is waiting under Anfragen, and the group's one message
  is the trainer's welcome. **Geld**: Mia's last month is overdue (its amount in
  red), Lena's month is open and not yet due, and Elias's month is still listed —
  his **Beiträge** tab shows the payment as „Unbestätigt" with „Bestätigen". Once
  the club's IBAN is in (**Verwaltung → Zahlungsempfänger**), Lena's family sees
  the QR code and „Beleg hochladen" on **Beiträge**; without it only the upload.
  The overview counts 1 under „Heute abwesend": in **Schüler** the selection
  „Krank" lists Mia, and her Abwesenheit tab says „Krank" from today for three
  days. **Anwesenheit** on the last two Mondays: everybody present, Elias absent
  on the latest. One news item, published; one chat, Lena's family with the
  trainer, with two messages.
- [ ] **N.6** The example logins stop after 14 days. In phpMyAdmin, in `accounts`,
  set `created_at` of `trainerin@beispiel.test` back by 15 days. Signing in as
  the trainer is refused with „Anmeldung nicht möglich. Bitte E-Mail-Adresse
  und Passwort prüfen. …", the same sentence as a wrong password.
  The families' logins still sign in. „Beispieldaten entfernen", then „anlegen"
  again: three fresh sign-ins and a new password.
- [ ] **N.7** `php bin/console.php demo:fill` says „They sign in for 14 days;
  after that, demo:clear and demo:fill again give new ones." before its last
  line, „Remove everything with demo:clear."

### The portal as an iPhone app (design language, phase 1)

The new look has been measured in Chromium only. These are the checks only a real
iPhone can pass: walk them in Safari, once in light mode and once in dark, at 390
and — if one is to hand — at 320 (an iPhone SE of the first generation), and write
down what could not be checked rather than ticking it.

- [ ] **I.1** The text is set in the iPhone's own typeface, the one the Settings
  app uses, with large page titles. In Safari, „aA" → larger text: the portal's
  text grows, and nothing runs off the screen sideways or covers anything. Then
  **Mein Konto → Schriftgröße „Am größten"**: the same, and the labels of the bar
  at the bottom stay their size. Put both back.
- [ ] **I.2** The bars and the notch: the bar at the top sits below the clock and
  the notch, the bar at the bottom above the home indicator. Turn the phone
  sideways: nothing on the page or in the bars is under the notch at the side.
- [ ] **I.3** **(release)** Add the portal to the home screen (Teilen → „Zum
  Home-Bildschirm") and open it from its icon. The clock and the battery at the
  very top can be read, in light mode and in dark; in dark, write down whether
  they turned white. Then I.2 again in the app.
- [ ] **I.4** Pressed states: keep a finger on a child in **Schüler**, on a button
  and on an entry of the bar at the bottom. Each darkens or fades while it is
  held, and comes back when the finger lifts or slides away. Nothing flashes
  grey across the whole row.
- [ ] **I.5** On a slow connection, tap a button that saves: it shows a small
  spinner, and a second tap does nothing. The page that comes back is ordinary
  again, and so is the form after going Back to it.
- [ ] **I.6** Between pages: from **Übersicht** to **Schüler** and back, the bars
  stay still and the page in between fades over. With Einstellungen →
  Bedienungshilfen → Bewegung → „Bewegung reduzieren" on, the page changes at once.
- [ ] **I.7** The back button, in the app from the home screen (I.3), where Safari
  gives no Back of its own: a child's page shows „‹ Schüler" at the top left and
  it leads to **Schüler**; a chat „‹ Chats"; a course „‹ Kurse"; a family's Mein
  Konto „‹ Profil". Everything opened from **Mehr** — Kurse, Geld, Rechnungen,
  Einstellungen, a trainer's Verwaltung, and your own Mein Konto — shows
  „‹ Mehr", and **Mehr** is lit in the bar while you are there. Verwaltung,
  Zugänge and Änderungen, opened from Einstellungen, show „‹ Zurück", because
  „Einstellungen" is too long for the bar. Scroll a long page: the page's title
  appears small in the bar once the large one has gone — or nothing does, where
  Safari cannot do it yet; write down which.
- [ ] **I.8** A sheet: on a child whose login is in use, open „Anmeldung löschen"
  in „Zugang zum Portal" — do not confirm it. It rises from the bottom, with its
  title on top and „Abbrechen" under it. „Abbrechen" closes it and nothing is
  deleted; a tap on the dimmed page above it closes it too.
- [ ] **I.9** Switches: under **Mein Konto** the three e-mail settings are
  switches. Tap anywhere on a row and it flips, the knob sliding across; save,
  reload, and it is as you left it. With VoiceOver on, each is read as a switch,
  on or off.
- [ ] **I.10** On **Geld**, „Beiträge · Rechnungen" is one segmented control, the
  current half white and raised; tap the other. On **Schüler → + Schüler
  anlegen**, a line of two capsules under the title shows the step you are on.
- [ ] **I.11** The numbers on the bell and on **Chats** are red with a white
  figure, readable in light and in dark.
- [ ] **I.12** No white flash in dark mode. With the iPhone in dark mode — and
  once more with the phone in light mode and **Mein Konto → Erscheinungsbild
  „Immer dunkel"** — open the app from the home screen, tap through several
  pages (Übersicht, Schüler, a child, Chats, Mehr), go Back, reload, and open a
  link to the portal from a mail in Mail. On a test install, open `setup.php`
  in dark mode as well; an installed portal answers „Schon eingerichtet". Write
  down every white frame you see, however short, and where; the result to
  expect is none. The plain screen iOS shows before the home-screen app's very
  first page is iOS's own start, not this check.

### Waiting for a page, and the shuttlecock (design language, 0.4b and C16a)

Measured in Chromium only, as „Part 0, continued" of
`docs/design/2026-10-07-ios-design-language-and-goal-screens.md` records. Walk
W.1 to W.3 on a real iPhone, in Safari and in the app from the home screen, in
light mode and in dark, and write down what could not be checked rather than
ticking it.

- [ ] **W.1** **(iPhone)** On a slow connection — weak 4G in the hall, or the
  Network Link Conditioner under Einstellungen → Entwickler on an iPhone that
  has been connected to Xcode — tap a child in **Schüler**: the row stays
  pressed, and if the page has not come after half a second, the waiting page
  fades in over the whole screen: the portal's name under a net, and a
  shuttlecock rallying over it. The child's page then comes out from under it
  in one movement: no cut, no white frame, and never a second shuttlecock. Once
  shown, the waiting page stays at least half a second, so it never blinks. A
  page that comes quickly shows only the pressed row, and a button that saves
  shows its spinner and no waiting page. In the app opened from the home
  screen, with a page that does not come at all (the conditioner letting
  nothing through): after about 6 s „Dauert länger als sonst." appears with
  „Abbrechen", and „Abbrechen" leaves you on the page you were on, with nothing
  pressed. Once a page has come, go Back: the page you return to has no waiting
  page over it and nothing pressed. With Einstellungen → Bedienungshilfen →
  Bewegung → „Bewegung reduzieren" on, the shuttlecock does not fly: it rests
  at the top of its arc and only brightens and fades. With VoiceOver on, a slow
  page is announced once, „Wird geladen …"; at 6 s „Dauert länger als sonst." is
  said and the focus moves to „Abbrechen", read as a button, and after
  „Abbrechen" the focus is back where it was.
- [ ] **W.2** **(iPhone)** In the app opened from the home screen, signed in:
  open an invoice's PDF (a child's **Rechnungen** tab, „PDF herunterladen"), a
  receipt on a child's **Beiträge** tab, and a photo in a chat. Each opens
  without asking anybody to sign in, and no waiting page comes up. Get back to
  the portal from each — the photo too — and write down how: a swipe from the
  left edge, a button, or not at all.
- [ ] **W.3** **(iPhone)** On a portal with no icon of the club's own (no
  „Symbol des Portals" under **Einstellungen → Portal**), add the portal to the
  home screen: the icon is a white shuttlecock on the teal square, with the
  iPhone's rounded corners and no black corners. An icon added before this
  update may keep its old picture until it is removed and added again.
- [ ] **W.4** On an Android phone in Chrome, on the same portal, „Zum
  Startbildschirm hinzufügen" (or „App installieren"): the icon is the teal
  square with the whole shuttlecock inside the launcher's shape, round or
  rounded, and nothing of it cut off.
- [ ] **W.5** Under **Einstellungen → Portal → „Symbol des Portals"**, upload a
  club icon that is dark on a see-through ground, and switch to dark mode. Top
  left in the menu on a computer, on the sign-in page, and in both previews
  under **Aussehen**, it sits on the portal's colour, so nothing of it vanishes
  on black. (In light mode all four put the menu colour behind it, where a dark
  icon is hard to see; that is known and not what this step checks.)
  „Standard-Symbol verwenden" afterwards.

### An update that lost records stays closed (ADR 0027)

**On a test install only**, never on a portal anybody uses: these break the
database on purpose and put it back with phpMyAdmin. You need the hosting
panel's file manager and phpMyAdmin.

- [ ] **G.1** **(release)** In phpMyAdmin, note how many rows the `contacts`
  table has.
- [ ] **G.2** **(release)** In the file manager, add a file
  `database/migrations/999_test_drops_contacts.sql` holding one line,
  `DROP TABLE contacts;`, and open the portal. It shows „Das Portal ist
  vorübergehend geschlossen.", „Du musst nichts tun. Bitte versuche es später
  noch einmal." and „Für die Person, die das Portal betreut: Nach der
  Aktualisierung auf Version … fehlen Datensätze: contacts (vorher N, jetzt 0)
  …", with N the number from G.1, and names the copy to import as
  „JJJJ-MM-TT-HHMMSS-vor-update-…". The same in English underneath.
- [ ] **G.3** **(release)** `storage/update-unfinished.json` is there. Opened in
  the file manager it holds both version numbers, the counts of the guarded
  tables and the name of that copy without its random part.
- [ ] **G.4** **(release)** Reload twice: the same page, word for word, and no
  new file in `storage/backups`.
- [ ] **G.5** **(release)** Create an empty file `storage/maintenance.flag`.
  You get „Das Portal wird gerade aktualisiert" as everybody else does, signed
  in as the administrator or not. Delete the flag again.
- [ ] **G.6** **(release)** Delete the 999 file — these files are now the
  version from before it — and reload: still closed, still naming contacts.
- [ ] **G.7** **(release)** In phpMyAdmin: **Exportieren** the database as it
  is now, select every table and drop them, then **Importieren** the copy from
  `storage/backups` that the page named. Opened meanwhile, the portal stays
  closed: naming what is still missing while the tables are gone or on their
  way in, and once every table is in but the copy's last line has not run,
  saying that a copy is being imported. The closed page reloads itself every
  five minutes.
- [ ] **G.8** **(release)** Open the portal: it opens. `update-unfinished.json`
  is gone from `storage`, the contacts are back, and **Einstellungen → System**
  shows the same version as before G.2.
- [ ] **G.9** **(release)** Optional: G.2 to G.6 once more, then import a copy of
  the named file cut off before `contacts` — everything from the line
  ``DROP TABLE IF EXISTS `contacts`;`` on deleted; the tables are in
  alphabetical order. The portal stays closed, naming contacts and the guarded
  tables after it, and the cut copy leaves the table `import_unfinished`
  behind, which the whole file removes with its last line. Import the whole
  file and it opens.

### A restore keeps the portal closed until its import is done (ADR 0029)

On a test install, with the hosting panel's file manager and phpMyAdmin, as the
walk above. Every copy the portal writes now begins by making a table
`import_unfinished` and ends by dropping it; the three closed-page texts are in
INSTALL.md's table.

- [ ] **H.1** **(release)** Open the newest copy in `storage/backups` in the file
  manager: after its three `SET` lines, the first statement is ``CREATE TABLE IF
  NOT EXISTS `import_unfinished` …`` with a comment in German and English, and
  the last line is ``DROP TABLE IF EXISTS `import_unfinished`;``. The table is
  nowhere in between.
- [ ] **H.2** **(release)** With maintenance mode off, make a copy of that file
  cut off after the `accounts` table's rows — everything from the next
  ``DROP TABLE IF EXISTS`` on deleted — drop every table in phpMyAdmin and
  import the cut copy. Open the portal: „Das Portal ist vorübergehend
  geschlossen." with „Gerade wird eine Sicherung eingespielt, oder das
  Einspielen ist abgebrochen. …" and the English under it; reload: the same,
  and no new copy in `storage/backups`. phpMyAdmin lists `import_unfinished`
  among the tables, with its comment. Now import the whole file: the next page
  view opens the portal, `import_unfinished` is gone, and every table has as
  many rows as before the drop — nothing doubled.
- [ ] **H.3** **(release)** The same with the cut copy, then wait with the closed
  page open in a tab while you import the whole file in phpMyAdmin: within five
  minutes the tab shows the portal by itself. With mail set up and a mail
  queued beforehand, **Postausgang** shows it was not sent while the import
  ran: the background work stays out by itself.
- [ ] **H.4** **(release)** On a server with a shell, while `import_unfinished`
  exists: `php bin/console.php backup`, `maintenance` and `mail:work` each stop
  with the sentence naming that table and exit code 1, and `storage/backups`
  gains no file; `check` and `status` still answer. With the table gone and
  `storage/maintenance.flag` created: `php bin/console.php maintenance` stops
  with „Maintenance mode is on …" and exit code 1, and deletes nothing. With
  every table dropped and the flag gone: `php bin/console.php backup` refuses
  with exit code 1 and writes no file, because there is nothing to copy.
- [ ] **H.5** **(release)** Point the same files at a second, empty database:
  in `config/config.php` name a new, empty database and open `setup.php`. Give
  it the setup code from `storage/setup-code.txt` if it asks, fill in an
  administrator and tap **Installieren**: it shows „Die Datenbank ist leer,
  aber in diesem Ordner lief schon ein Portal. …" and installs nothing — the
  new database stays empty. Put the old database back in `config/config.php`.
  (Deleting `storage/schema.stamp` instead would let a new portal start there
  and, once it has an administrator, sweep the old one's uploads; do not do it
  on this copy.)

### Any value from anyone (ADR 0026 §5)

What a slip of the thumb or an odd link does: one sentence on the same page,
never an error page and never a line in the server's error log. The `robustness`
suite posts such values to every form; these are the ones worth seeing by hand.

- [ ] **V.1** **Kurse** → a course → „Kurs bearbeiten": **Plätze** „abc", then
  „1,5": each refused with „Plätze: 0 bis 500 (0 = unbegrenzt)." **Plätze**
  left empty saves as unlimited. **Reihenfolge** 99999999 is refused with
  „Reihenfolge: bitte eine ganze Zahl von -99999 bis 99999 eingeben, die
  kleinste steht zuerst."
- [ ] **V.2** On a course's „Tarife" tab, a tariff: **Zahltag im Monat** 31 is
  refused with „Zahltag: 1 bis 28. Der 29. bis 31. existiert nicht in jedem
  Monat."; **Tage bis „überfällig"** 400 with „Frist bis „überfällig“: 0 bis
  365 Tage."; a discount template with a duration and 12,5 per cent with
  „Rabatt: eine ganze Zahl von 0 bis 100 Prozent."
- [ ] **V.3** On a child, **Rechnungen**: **Rechnungsdatum** 31.12.9999 is
  refused with „Bitte ein Datum zwischen 1900 und 2100 eingeben.";
  **Zahlungsziel in Tagen** 200 with „Zahlungsziel: 0 bis 180 Tage."
- [ ] **V.4** **Geld → „Monatsbeiträge"**: **Monat** 9999-12, „Monat wechseln",
  then the button that creates the charges: refused, and nothing is created.
- [ ] **V.5** In the address: `?page=students&p=99999999999999999999`, and the
  same `&p=` on `?page=outbox` and `?page=invoices`, show a page — empty, or
  the last one — and no error. A course's `&tab=dates&on=0` opens its
  „Termine" with today chosen.
- [ ] **V.6** As a family, „Neue Nachricht" shows only the **Trainerteam** (14.5).
- [ ] **V.7** With mail not tested yet: as a trainer, **Schüler → „Per E-Mail
  einladen"** says „Eine Administratorin muss zuerst den E-Mail-Versand
  einrichten und testen."; as the administrator it says „E-Mail-Versand zuerst
  testen: unter „Einstellungen → SMTP“ die Verbindung prüfen."
- [ ] **V.8** **(release)** On a fresh install, `setup.php?lang[]=x` shows the
  setup page in German, and the server's error log gains no line.
- [ ] **V.9** **(release)** Sign out in one tab, then send a form still open in
  another: you land on the sign-in page, and the error log gains no „CRM:
  leaving with an open transaction".
- [ ] **V.10** **(release)** On a copy from before ADR 0026's first round where
  an e-mail template had been saved, **Änderungen** lists those lines as
  „Geändert: Frühere E-Mail-Vorlage · …", not by a table's name.
- [ ] **V.11** An old bookmark to the students filtered by tariff,
  `?page=students&tariff=3`, shows all children.

### An administrator reads every chat (ADR 0022 §11.1, §11.2)

The owner's rule: administrators can read every chat, and nothing records that
they did. Have a chat between a child and the trainer and one between two
trainers, with a message each that you, the administrator, have not written.

- [ ] **E.1** As the administrator, **Chats** → „Alle Einzelchats": the
  chats you are not in, under „Schüler und Team", „Im Team" and, on a copy that
  has one, „Zwischen Schülern (geschlossen)". Every row names both people and
  shows no unread number. „Meine Chats" leads back, and your own list and badge
  are as before.
- [ ] **E.2** Open the chat between the child and the trainer from there: you
  read it, it says „Hier schreibt ihr zu zweit. Die Administratoren des Vereins
  können mitlesen." at the top, and „Du liest hier mit. Schreiben können nur die
  beiden." where the writing box would be. The chat between two trainers says
  the same. Neither says anywhere that it is private.
- [ ] **E.3** On a copy that has a chat between two children: opened from
  „Zwischen Schülern (geschlossen)", it says „Chats zwischen Schülern sind
  geschlossen. …" at the top, and there is no writing box and no „Du liest hier
  mit".
- [ ] **E.4** Nothing records your reading: signed in as the trainer of E.2, the
  chat is as unread for her as before you opened it, and nothing in her bell
  says you looked. In phpMyAdmin, `thread_reads` has no row with your
  account's id for the chats of E.2 and E.3, and `audit_log` no line from those
  minutes.
- [ ] **E.5** As a trainer, a chat with a second trainer is under „Einzelchats"
  and takes messages; as a family, the old chat with another family is under
  „Frühere Unterhaltungen" and takes none.

### Every change to a payment recipient's Kontoverbindung is kept (ADR 0025)

- [ ] **K.1** As the trainer, **Verwaltung** → „Zahlungsempfänger" → the
  recipient in use: change the IBAN to another valid one and save. As the
  administrator, **Einstellungen** → **Änderungen**: a line for that
  „Zahlungsempfänger", made by the trainer, with „IBAN" from the old number to
  the new one, and nothing else changed.
- [ ] **K.2** An invoice issued before K.1, downloaded again, still shows the old
  IBAN. A charge still open shows the new one on the family's **Beiträge**, and
  its QR code, scanned with a banking app, fills in the new one.
- [ ] **K.3** „+ Neu" makes a second recipient: **Änderungen** has a line saying
  it was made, with its IBAN. Change its „Hinweis für Eltern" and its „Inhalt
  des QR-Codes" — keeping „BCD" on the first line, `{recipient}` on the sixth
  and `{iban}` on the seventh (S.12): the line names them „Notiz" and „Inhalt
  des QR-Codes", never a column's name.

### The security batch (ROADMAP item 6)

- [ ] **S.1** With mail set up, a payment reminder's „Abmelden" link opens a
  page saying „Keine E-Mails mehr zu Beiträgen erhalten: keine Erinnerung an
  offene Beiträge und keine Rechnungen. …" — nothing about messages. Confirm:
  under **Mein Konto**, „Erinnerung, wenn ein Beitrag offen ist" is off. A news
  mail's link speaks of news, a chat notice's of messages, dates and requests.
- [ ] **S.2** In the address of such a link, change the number in front of the
  dot in `signature=` by one: the page says „Dieser Abmeldelink gilt nicht mehr.
  Melde dich an und schalte die E-Mails unter „Mein Konto“ ab." and offers no
  button. **(release)** A link from a mail sent more than 90 days ago says the
  same.
- [ ] **S.3** In phpMyAdmin, set `accounts.name` of a child's login to „Muster
  GmbH", as a family could call itself under **Mein Konto** before S.7. As the
  trainer, issue an invoice for that child and download it: under
  „Rechnungsempfänger" stands the child's name, and „Muster GmbH" is nowhere on
  it.
- [ ] **S.4** As the trainer, change one date of a course with „Alle
  Kursteilnehmer per E-Mail informieren" ticked. As a family in that course,
  the notice in the bell opens **Übersicht**, not „Kein Zugriff"; with mail set
  up, the link at the end of the mail opens it too.
- [ ] **S.5** **(release)** A logo stored on its side: from a wide JPEG logo,
  `jpegtran -rotate 90 -trim logo.jpg > seite.jpg`, then
  `exiftool -Orientation=8 -n -overwrite_original seite.jpg`. Opened in a
  browser, `seite.jpg` looks like the logo, upright. Upload it under
  **Einstellungen → Portal → Logo**: it is accepted, and stands upright and
  unsqueezed in the bar, on a phone and on a computer.
- [ ] **S.6** As a family, send a receipt under **Beiträge**: it arrives. The
  suite checks that the twenty-first within an hour is refused with „Zu viele
  Versuche"; by hand, only the one that arrives.

The security review of 2026-10-08:

- [ ] **S.7** As a family, **Mein Konto**: the card under „Anmeldung" is called
  „Darstellung" and has no box for a name. Change „Erscheinungsbild" and save:
  it is saved. As the trainer, the card is „Name und Darstellung", with „Name",
  and what you type there is the name the chat shows for you.
- [ ] **S.8** As the trainer, on a child's page change the first name and save.
  In the child's course group, the child's messages carry the new name; as the
  administrator, **Änderungen** has a line for the „Konto" with „Name" from the
  old name to the new. As that family, change the first name on the child's
  „Profil" tab: the group shows that name too. In the group, the trainer's
  name over her messages has a grey pill „Trainerin" beside it, and an
  administrator's „Administrator"; a child's never has one, whatever the child
  is called. As that family, change the last name to „· Trainerin" and write in
  the group: the line reads „… · Trainerin" in the child's colour, with no pill,
  and does not look like the trainer's. Put the name back. In **Chats**
  the list names whoever wrote last in the group the same way: after the
  trainer writes, the group's line reads her first name, the pill „Trainerin",
  then her message, on one line at 320 px with the pill whole. As that family,
  change the first name to „Trainerin" and write in the group: as another
  family in the course, the line reads „Trainerin: …" with no pill. Put the
  name back.
- [ ] **S.9** With mail set up and, under the trainer's **Mein Konto**, „E-Mail
  bei neuen Nachrichten …" on: as a family, write three messages to the
  trainer in a row. **Postausgang** has one „Neue Nachricht im Badminton-Portal"
  for the trainer, not three, and her bell lists all three. She opens the chat;
  the family writes once more: a second mail.
- [ ] **S.10** As a family, send a photo from the camera into the course group:
  it arrives. The suite checks that a family's twenty-first photo within an
  hour is refused with „Zu viele Versuche", and that staff are not counted; by
  hand, only the one that arrives. On a computer, as another member, save that
  photo („Bild speichern unter …"): the name offered is 32 letters and digits
  with `.jpg`, never the phone's „IMG_…". With a screen reader (VoiceOver on
  the iPhone), the photo is read as „Foto".
- [ ] **S.11** With two administrators, A and B. As the trainer, **Verwaltung →
  Zahlungsempfänger**: change the IBAN of the recipient in use. A's and B's
  bells say „Kontoverbindung geändert: …" and „Von … (Trainerin): IBAN. …";
  the notice opens **Änderungen** for that recipient, with the old IBAN and the
  new. As A, change it back: A and B are both told. „+ Neu" with an IBAN: both
  are told „Neuer Zahlungsempfänger: …". As the trainer, edit a course and set
  „Beiträge gehen auf" to the new recipient: both are told „Kurs zahlt auf ein
  anderes Konto: …", from which account to which, and the notice opens the
  course. Under **Verwaltung → Geld & Zahlungen**, set „Standard-Zahlungsempfänger"
  to the new recipient: both are told „Standard-Zahlungsempfänger geändert: …",
  from which account to which; set it back. Saving a course, a recipient or that
  card without changing where the money goes tells nobody.
- [ ] **S.12** In the same form, put `https://example.com` into „Inhalt des
  QR-Codes" and save: refused with „Der Inhalt des QR-Codes muss eine
  SEPA-Überweisung bleiben: „BCD“ in der ersten Zeile, {recipient} in der
  sechsten und {iban} in der siebten." Replace `{iban}` on the seventh line
  with any IBAN: refused the same way. With `{iban}` back on the seventh line
  it saves.
  On a family's **Beiträge**, scan the code with a banking app: it fills in the
  IBAN shown beside it. The box's hint states the same rule. **(release)** In
  phpMyAdmin, put a link into `payment_profiles.qr_template`: the form says under
  its preview „Dieser Inhalt ergibt keinen QR-Code: …", and the family's
  „Beiträge" shows no code at all.
- [ ] **S.13** With mail set up, as a family whose login is in use: **Mein Konto
  → E-Mail-Adresse ändern**, and open the link that arrives at the new address.
  The old mailbox gets „Deine Anmeldeadresse wurde geändert": „Hallo …, die
  Adresse, mit der du dich anmeldest, wurde auf x\*\*\*@y\*\*\*.… geändert. Warst du
  das nicht? Melde dich beim Verein.", with no link in it; **Postausgang** shows
  it without its text. Then **Passwort ändern**: the address the login signs in
  with gets „Dein Passwort wurde geändert", likewise without a link. For a child
  only invited, whose address the trainer corrects on the child's card, nothing
  goes to the old address.
- [ ] **S.14** **(release)** In phpMyAdmin, set `sent_at` and `created_at` of a
  sent mail in `mail_jobs` to 91 days back, and of another to 89. After the next
  daily cleanup, or `php bin/console.php maintenance` where there is a shell,
  **Postausgang** still lists both as „Gesendet" with recipient and subject; the
  first opens without its text, the second with it.

### How long the portal keeps what it holds (ADR 0032)

Ten periods, each a setting; the cleanup, once a day after a page view or
from the console's cron job, deletes what is past its period, files included.
Set shorter, a period deletes nothing before the next cleanup, so until then it
can be set back; after such a save the cleanup after a page view waits a day
(D.7), the console's does not. Invoices, charges and payments are never deleted
by it. Test data only.

To have the cleanup at once, delete the rows `prune_last_run` and
`period_last_shortened` from the table `settings` in phpMyAdmin, wait a minute,
and open any page; the second is there only after a period was set shorter, as
D.1, D.4 and D.5 do. Where there is a shell, `php bin/console.php maintenance`
runs it at once, whatever the two rows say. A file uploaded less than an hour
before the run waits for a later one, whatever its row's date says.

- [ ] **D.1** As the administrator, **Einstellungen → System**, „Erweitert":
  „Änderungen aufbewahren (Monate)" 24, „Nachrichten aufbewahren (Monate)" 12,
  „Entfernte Nachrichten wiederherstellbar (Tage)" 30, „Abwesenheiten
  aufbewahren (Monate)" 3, „Anwesenheit aufbewahren (Monate)" 24, „Hinweise
  aufbewahren (Tage)" 90, „Postausgang aufbewahren (Monate)" 12,
  „Zahlungsbelege aufbewahren (Monate)" 24, „Prüfprotokoll aufbewahren
  (Monate)" 36 and „Ersetzte Einwilligungen aufbewahren (Monate)" 36, each
  with a line saying what it counts from and ending „Kürzer gestellt,
  löscht das tägliche Aufräumen, was älter ist; bis dahin lässt es sich
  zurückstellen." Set „Nachrichten aufbewahren (Monate)" to 6 and save: „Vorgaben
  gespeichert. Kürzer gestellt, vorher: Nachrichten aufbewahren (Monate) 12. Bis
  zum nächsten täglichen Aufräumen lässt es sich so zurückstellen."; set it back
  to 12. A 0 is refused. On **Einstellungen**, „Erweitert" says „Selten
  gebraucht: Hintergrundaufgaben, wie lange Nachrichten, Änderungen und anderes
  aufbewahrt werden." **Einstellungen → Änderungen** says above its list „…
  werden beim täglichen Aufräumen entfernt; das Prüfprotokoll nach 36 Monaten."
- [ ] **D.2** As the trainer, take a message in a course group down: „Nachricht
  entfernt. 30 Tage lang kannst du sie an derselben Stelle wiederherstellen."
- [ ] **D.3** **(release)** In phpMyAdmin: set `created_at` of a chat message
  with a photo to 13 months back, `ends_on` of an absence to 4 months back,
  `session_on` of an attendance row to 25 months back, and `created_at` of a
  payment proof to 25 months back. After the next daily cleanup, or `php
  bin/console.php maintenance` where there is a shell (it prints „Everything
  past its period deleted: …"): the message is gone from the chat with its
  photo, the absence from the child's page, the attendance row from the course's
  „Anwesenheit" and the proof from the child's „Beiträge". The photo's and the
  proof's files are gone from `storage/uploads/message` and
  `storage/uploads/proof` if they were uploaded more than an hour before the
  run: the cleanup keeps a younger file, which may belong to a row still being
  written, and the next run takes it. A message sent last month, and its photo,
  are still there.
- [ ] **D.4** **(release)** Set „Abwesenheiten aufbewahren (Monate)" to 1 and
  have the cleanup at once, as above: an absence that ended 40 days ago is gone.
  Set it back to 3.
- [ ] **D.5** **(release)** Set every period to 1 and have the cleanup at once,
  as above: `prune_last_run` then holds the time of that run, in UTC, so it did
  run. An invoice, its charge and its payment from years back (in phpMyAdmin,
  `issued_on`, `due_on`, `paid_on` and `created_at` set back) are all still
  there: on **Rechnungen**, on **Geld** and on the child's „Beiträge". Put the
  periods back as in D.1.
- [ ] **D.6** **(release)** In `consent_log`, a family's answer that has not
  changed for years is still there after a cleanup as above; one that a later
  answer of the same login to the same question replaced more than 36 months
  before is gone.
- [ ] **D.7** **(release)** A save that sets a period shorter deletes nothing in
  its own page view. Without a cron job, in phpMyAdmin: delete the row
  `prune_last_run` from `settings`, so the cleanup is due, and set `created_at`
  of a row in `notifications` to 40 days back. Set „Hinweise aufbewahren (Tage)"
  to 30 and save, then open a few pages over a few minutes: the row is still
  there, and `settings` has `period_last_shortened` at the time of the save, in
  UTC. Set it back to 90. Delete `period_last_shortened`, as if a day had
  passed, wait a minute and open a page: `prune_last_run` is filled, and the row
  is still there, inside its 90 days.

### Pictures (ADR 0031)

What the suites cannot reach: a photo from a real phone, a browser's cache, a
host without gd, and how the screens feel in the hand. Walk P.1 to P.20 on a
test install with the example data, on a real iPhone and an Android phone where
marked, as a trainer and as a family, in light mode and in dark; write down
what could not be checked rather than ticking it. The screens are specified in
`docs/design/2026-10-08-pictures.md`.

- [ ] **P.1** **(iPhone, Android)** As the trainer, open a child's page and add
  a photo from the gallery, then replace it with one from the camera. Each time
  the face shows upright, as the phone showed the photo, and the banner says
  „Foto gespeichert.". In the file manager, `storage/uploads/picture` holds one
  file for the child, a `.jpg` of a few tens of kilobytes; the one it replaced
  is gone. On a slow connection — weak 4G, or the Network Link Conditioner as in
  W.1 — a spinner turns over the face while the photo is sent, and the row does
  not move.
- [ ] **P.2** **(release)** Download that file from the file manager and open it
  in an image viewer that shows the photo's details: 320 × 320 pixels, and no
  camera, no date taken, no place. The photo the phone sent is nowhere in
  `storage`.
- [ ] **P.3** **(iPhone)** With JavaScript off (Safari: Einstellungen → Apps →
  Safari → Erweitert → JavaScript), choose a photo straight from the iPhone's
  camera — 24 megapixels, 5712 × 4284, a little over the 24 million the server
  takes — and press „Foto speichern": it is refused in one sentence on the same
  page — „Die Datei ist zu groß. Höchstens …", the same words whether it is over
  the upload limit or over what the server takes at all (`post_max_size`), or
  „Dieses Foto hat zu viele Bildpunkte: höchstens 24 Megapixel." — never „Die
  Sitzung ist abgelaufen", and the face stays as it
  was. A receipt under **Beiträge** over `post_max_size` is answered the same
  way. JavaScript on again, the same photo arrives, made smaller by the browser.
- [ ] **P.4** On a computer, without JavaScript, choose a WebP, a GIF or a PDF
  for a child's picture: „Dieses Bild lässt sich nicht lesen. Bitte ein Foto
  (JPEG oder PNG) wählen.", and nothing changes.
- [ ] **P.5** **(release)** In a computer's browser, signed in as the family,
  open the developer tools' network panel and reload a page with the child's
  face: its request answers `Cache-Control: private, max-age=604800` and no
  `Expires` or `Pragma`; reloading again takes it from the cache. Copy the
  picture's address — `kind=student` for a child, `kind=account` for a team
  member — change the number after `id=` to another child's, and open it:
  „Dieses Bild gibt es nicht.", the same as for an id that does not exist.
  Sign out: the answer carries `Clear-Site-Data: "cache"`.
- [ ] **P.6** **(iPhone, Android)** As the trainer, on **Anwesenheit**, tick two
  children, then tap the face of a child without a photo — the initials with a
  small camera: the ticks are saved („2 Einträge gespeichert.") and the sheet
  „Foto von …" opens for that child, where „Foto aufnehmen" opens the rear
  camera at once. Take a photo: back on the list for the same course and day,
  „Foto von … gespeichert.", the face in its row. Tap a face with nothing
  ticked: no „0 Einträge" banner. A face that has a photo does nothing here.
- [ ] **P.7** As a family whose child is in a course with the child of P.1: in
  the course's group, the trainer's photo of that child is not shown — the
  initials are. As that child's family, switch „Im Kurs-Chat zeigen" on: the
  other family now sees the face. In phpMyAdmin, `consent_log` has a row for the
  family's login with `course_sees_picture_by_parent` (a child under 14, or with
  no birth date) or `course_sees_picture` (14 or older) and `enabled` 1. The
  trainer adds a new photo: the face is gone from the other family's group
  again, and the family's bell says „Neues Foto von …", ending with „Im
  Kurs-Chat erscheint es erst, wenn ein Elternteil wieder zustimmt." for a child
  under 14, or „Im Kurs-Chat zeigst du es erst, wenn du wieder zustimmst." from
  14.
- [ ] **P.8** As a trainer, **Mein Konto**: the first card, above „Anmeldung",
  says „Foto hinzufügen" and „Das Team und die Familien sehen es.". Add a photo
  of yourself: „Foto gespeichert.", and the card says „Foto ändern" and opens
  the sheet „Dein Foto" with „Neues Foto", „Löschen geht sofort und lässt sich
  nicht zurückholen." and „Foto löschen". On **Zugänge**, her row shows the
  photo, not initials. As a family, the trainer's face shows in the chat with
  her and on the group's member sheet, and in the group beside each run of her
  messages, with the pill „Trainerin" beside her name; a child's run has that
  child's face, its photo where the family has said yes (P.7), and no pill. The
  family's own **Mein Konto** has no photo card. Delete the trainer's photo:
  initials again, and the file is gone from `storage/uploads/picture`.
- [ ] **P.9** **(release)** Invite a new family by address and set up the login
  from the mail: after the password the page is „Dein Foto" („Willkommen,
  …!"). „Überspringen" leads to the child's own page; a photo added there leads
  to the same page with „Foto gespeichert.". Open `?page=welcome` by hand
  afterwards: the same harmless step, and nothing links to it. Sign out, ask
  for a new password on the sign-in page and set it from the mail: the page
  after it is **Übersicht**, not „Dein Foto".
- [ ] **P.10** **(release)** On a test install only, switch gd off in the
  hosting panel for a moment. `setup.php` on a fresh install lists „Profilbilder
  verkleinern (gd)" as „eingeschränkt" and installs all the same.
  **Einstellungen → System** says „Es fehlt: Profilbilder verkleinern (gd)" and
  what to do. A child's page and the team's **Mein Konto** show „Fotos gehen auf
  diesem Server noch nicht." where „Foto hinzufügen" was, with „Unter
  „Einstellungen“ → „System“ steht, was fehlt." and „Zur Einrichtung" for an
  administrator and „Das richtet eine Administratorin ein." for anybody else; a
  picture already there still shows and can be deleted. **Anwesenheit** shows no
  camera. A family setting up its login from an invitation sees no „Dein Foto":
  it lands on the child's page, and the banner goes on after „Dein Konto ist
  bereit. …" with „Willkommen, {Vorname}! Schau kurz, ob alles stimmt, und
  ergänze, was fehlt. …". A trainer invited the same way lands on **Übersicht**,
  and the banner says only how they sign in. Everything else works. Switch gd
  back on.
- [ ] **P.11** **(iPhone)** As a family whose child has a photo, with „Kinder im
  selben Kurs sehen Fotos" on (P.13): under the photo on the child's
  **Profil** tab, the switch „Im Kurs-Chat zeigen" says whose decision it is —
  „Unter 14 schaltet das ein Elternteil ein." for a child under 14, „Ohne
  Geburtsdatum schaltet das ein Elternteil ein." for a child with no birth date,
  „Das entscheidest du selbst." from 14 — and the line under it says what the
  others see: „Die anderen im Kurs sehen nur „…“." with the child's initials
  while it is off, and „Die Kinder in deinem Kurs und ihre Familien sehen es im
  Kurs-Chat. Ausschalten geht jederzeit." while it is on. Switch it on for a
  child under 14; then, as the trainer, set the child's birth date to 14 years
  ago today: the switch stays on, its line now says „Das entscheidest du
  selbst.", and nothing asks again.
- [ ] **P.12** As the trainer, on the page of that child, with the switch on:
  under the photo, „Im Kurs-Chat sichtbar, weil die Familie zugestimmt hat.
  Wieder einschalten kann dann nur sie." and the button „Im Kurs-Chat
  ausblenden", and no switch. Tap it: „Im Kurs-Chat ausgeblendet.", the other
  family sees the initials again, and the line says „Im Kurs-Chat nicht
  sichtbar. Das entscheidet die Familie.". For a child whose family has not
  signed in yet it says „Im Kurs-Chat nicht sichtbar. Zustimmen kann die
  Familie, sobald sie sich angemeldet hat.".
- [ ] **P.13** As the administrator, **Einstellungen → Datenschutz**: the card
  „Fotos im Kurs" has „Kinder im selben Kurs sehen Fotos", on, and under
  „Erweitert" „Ab diesem Alter stimmt ein Kind selbst zu", 14, which takes 13
  to 16. Switch „Kinder im selben Kurs sehen Fotos" off: a family's child's
  page has no switch any more and says „Dein Trainerteam sieht es.", and the
  other family in the course sees the initials where it saw the face; the
  trainer still sees every face. Switch it on again: the yes given before
  counts again.
- [ ] **P.14** **(iPhone)** As a family, on the child's **Profil** tab with no
  photo: the first card says „Foto hinzufügen" and „Freiwillig. Ohne Foto stehen
  hier deine Anfangsbuchstaben.", and a tap offers the phone's camera and its
  photo library. Add one: „Foto gespeichert.", „Foto ändern" and „Dein
  Trainerteam sieht es.". Tap the photo: the sheet „Foto von …" holds „Neues
  Foto", „Löschen geht sofort und lässt sich nicht zurückholen.", „Foto
  löschen" and „Abbrechen". „Foto löschen": „Foto gelöscht.", the initials
  again, and the file is gone from `storage/uploads/picture` at once.
- [ ] **P.15** **(release)** As the trainer, add a photo to a child whose
  invitation is still open, then set the login up from the invitation's mail:
  „Dein Foto" says „Dein Trainerteam hat schon ein Foto von dir hinzugefügt."
  and shows it, with „Passt so" and „Anderes Foto" and no „Überspringen". „Passt
  so" leads to the child's page and changes nothing; „Anderes Foto" takes
  another, and the child's page says „Foto gespeichert.".
- [ ] **P.16** **(release)** As the administrator, invite a trainer from
  **Zugänge** and set the login up from the mail: after the password, „Dein
  Foto" says „Willkommen, …!" and „Möchtest du ein Foto? Die Familien sehen es
  in den Chats.", offers „Foto hinzufügen" and „Überspringen", and under them
  „Alle im Portal sehen es. Ändern kannst du es unter „Mein Konto“.".
  „Überspringen" leads to **Übersicht**.
- [ ] **P.17** As the trainer, on **Anwesenheit**, with JavaScript: one row
  holds „Alle: Anwesend" and „Mehr". „Mehr" opens a sheet with „Für die ganze
  Liste. Gespeichert wird erst mit „Anwesenheit speichern“.", „Alle: Fehlt",
  „Alle: Entschuldigt" and „Abbrechen". „Alle: Fehlt" marks every child „Fehlt"
  and closes the sheet; nothing is saved until „Anwesenheit speichern". Without
  JavaScript the row is not there, and each child is marked one by one.
- [ ] **P.18** On a computer, as the trainer, on **Anwesenheit** for a day with
  a child who has no photo: mark two children with the keyboard — Tab to a
  child's marks, the arrow keys to choose — and press Enter. If the browser
  sends the form on Enter, the marks are saved, „2 Einträge gespeichert.", and
  no photo sheet opens.
- [ ] **P.19** **(iPhone)** As a family, **Übersicht**: the child's face, or its
  initials, sits large to the left of „Hallo …"; on a 320-pixel screen, or with
  a larger text size, the greeting goes under the face, and no name breaks
  inside a word. Tapping the face opens the child's page at the photo; VoiceOver
  reads it as „Dein Profil". A login with no child of its own has no face
  there.
- [ ] **P.20** **(iPhone)** **Mein Konto → Schriftgröße „Am größten"**, on a
  320-pixel screen, as the trainer and as a family. On a photo card, „Foto
  hinzufügen" and „Foto ändern" wrap onto a second line and are never cut. The
  initials stay inside every face: the 72-pixel ones on the child's page and on
  Mein Konto, the face on **Übersicht**, the sheet at **Anwesenheit** and „Dein
  Foto". Put the text size back.



### The design audit's first fixes (N1, N5, N6, N7)

What the designer found walking the owner's goals at phone width, the first
batch. Test data only; on a phone at 320 px where it says so.

- [ ] **M.1** **(release)** As a family whose child has a charge due in a few
  days and nothing late: on **Übersicht**, „Offen" is in black, with „Einzeln
  unter „Beiträge“" under it, and on **Beiträge** „Überfällig 0,00 €" is black
  too. In phpMyAdmin, set that charge's `overdue_on` to yesterday — its
  `due_on`, where `overdue_on` is empty: both are red.
- [ ] **M.2** As the trainer, a child's **Profil**: the card „Einteilung" has no
  hints under its fields, and its date reads „Im Verein seit". Under „Anschrift"
  the hint is „Straße, PLZ und Ort in einer Zeile.", under „Telefonnummer"
  „Notfallkontakte stehen unter „Kontakte“."; a course's own „Dabei seit", on
  the child's **Kurse**, is unchanged.
- [ ] **M.3** As a family, the child's **Profil**: one sentence above the form,
  „Deine Trainerin sieht, was du änderst."; no „Aktiv" under the name; under
  „Mitgliedschaft", „Im Verein seit" with its date, and „Mitgliedschaft bis"
  only when it has one; and no sentence about tariffs. Under „Anschrift":
  „Straße, Nummer, PLZ und Ort. Steht auf deinen Rechnungen."
- [ ] **M.4** **(iPhone)** As a family, **Übersicht** starts with „Hallo
  {Vorname}" and the child's face, with no line under it, no „Nachricht
  schreiben" and no card for the child. „Termine" shows today, the next three
  dates, and any later date in the next five weeks that is changed or has a
  note — never a past one. As the trainer, **Übersicht** has no line under
  „Hallo …" and no „Termin ändern", the tiles are as before, and „Termine" still
  shows the last three dates and the next six.
- [ ] **M.5** **(iPhone)** Signed out, at 320 px: the sign-in page's line „Deine
  Daten: Datenschutzerklärung" is one line, and the page links the notice once.
  Open an invitation's link: „Datenschutzerklärung lesen" above the box to tick
  is that page's one link, and its footer shows only the version. „Passwort
  vergessen?" still has the footer's link.
- [ ] **M.6** **(iPhone)** As the trainer, **Geld** at 320 px: the title „Geld"
  with nothing under it, „Alle" and „Überfällig" on one row with the chosen one
  filled, and the first charge right under them. „Rechnungen", the other half of
  its switch, is titled „Geld" too.
- [ ] **M.7** With mail tested and somebody overdue, **Geld → „Überfällig"**: „3
  Erinnerungen schicken" (with your count) opens a sheet; without JavaScript,
  the row opens in place. It says „Pro Kind eine E-Mail mit allen überfälligen
  Beiträgen und der Summe. Verschickt lässt sie sich nicht zurückholen.", who
  gets none and why, and offers „Jetzt schicken". After sending you are back on
  **Geld › Überfällig**, with the banner of 11.12.
- [ ] **M.8** When everybody overdue has no sign-in or has unsubscribed, there
  is no button, only „Keine Erinnerung möglich: Diese Kinder melden sich nicht
  an oder haben Erinnerungen abbestellt." After sending once, the same day says
  „Heute schon erinnert: …" in its place.
- [ ] **M.9** With mail saved but its test not passed, there is no button, only
  „E-Mail-Versand zuerst testen: unter „Einstellungen → SMTP“ die Verbindung
  prüfen." (a trainer reads „Eine Administratorin muss zuerst den E-Mail-Versand
  einrichten und testen."). With the test passed and the privacy notice not
  released, the reminder is offered all the same.
- [ ] **M.10** „Monatsbeiträge", after the list: „Automatisch" and shut while
  the charges make themselves; „Oktober: 4 anzulegen" (with your month and
  number) and open by itself when they do not and the month has some to make;
  open after „Monat wechseln", and when the setup checklist's „Beiträge" step
  leads here, to the switch inside it. Switched off, the banner says
  „Monatsbeiträge werden nicht mehr automatisch angelegt. Du legst sie unter
  „Monatsbeiträge“ selbst an, mit Vorschau." (U.36). A trainer sees no line
  about making them automatically.
- [ ] **M.11** A child's **Beiträge**: the transfer box with its QR code shows
  only while „QR-Code für offene Beiträge anzeigen" is on and the charge's
  recipient has an IBAN, the same rule by which a reminder promises bank details
  (11.15).

---

## What none of this proves

Say what you ran, not what you hope is true.

- `tests/mariadb-local.sh` proves **MariaDB 10.11**, the engine the portal's
  server runs, and prints what it could not cover at the end of every run. **MySQL 8.0 is still
  unverified** — it is one of the two supported engines, not both.
  `tests/existing-database.sh` proves whichever engine the hosting runs: quote the
  „Database server:" line it prints, not what you expect it to be.
- No automated check opens the generated PDF in Adobe Reader, sends real email
  through a real provider, or renders a page in Safari on a real iPhone. Checks
  13.4, 15.3 and 22.x exist because nothing else covers them.
- A green suite has never been proof that a file is intact. The `structure`
  suite exists because an automated edit once truncated a whole dispatcher and
  every behavioural test still passed.

## Recording a result

Copy the numbers of everything that failed into the release note, with one line
each: what you did, what you saw, what you expected. A number and a sentence is
enough for somebody to reproduce it; "the payments page is broken" is not.
