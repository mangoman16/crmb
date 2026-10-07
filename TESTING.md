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
  **Aktive Schüler** and three more figures under it, your picture or initials
  at the top right, and no red box anywhere. Scroll down: the bar at the top
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
- [ ] **1.6** Tap **Post** in the bar at the bottom. **Nachrichten** opens with
  its list of **Unterhaltungen**; tap one and its messages appear. With none yet
  it says „Noch keine Nachrichten.", and that passes too.

---

## What changed in this update — check these

Each line is one change; its checks are in
[New in this release](#new-in-this-release--the-checks-in-detail). Walk the ones
not marked **(release)**, straight after the five-minute sweep. Filled in for each
release and emptied again for the next.

- A shorter menu of seven, a hub at the top of **Einstellungen**, links at the top of **Nachrichten** and **Geld**: U.45–U.52
- A start checklist, **„Einrichtung"**, until the portal is ready: U.31–U.39
- The rarely needed fields wait under **„Mehr Möglichkeiten"**, VAT fields only with VAT: U.53–U.55
- One login is one student; invitations need a passing mail test: U.20–U.28b, U.57
- A problem report says how she got there; errors report themselves: U.13–U.19, U.40–U.44
- The portal's own icon: U.2–U.8 · the sign-in line: U.1 · rows that line up: U.9–U.12
- The privacy notice in English is optional: U.35, U.56
- A profile picture is kept by the browser instead of fetched on every page, and another family's is never shown: U.58–U.62
- News by email starts switched on for a new login, and can be unticked when accepting the invitation: [15.2, 15.7](#news-email-and-the-queue), in News and email
- The bell no longer jumps when opened, its panel stays on a phone's screen, its number is a badge in the portal's colour like the one on **Post**, and a tap elsewhere or Escape closes it: [5.3a–5.3g](#the-shell-the-bar-notifications-feedback-impersonation), in The shell
- A status to choose — „Automatisch", „Abwesend", „Als offline anzeigen" — for trainers and administrators, a coloured dot, and when each account was online over the last 30 days: [P.1–P.10](#online-status-and-when-somebody-was-online)
- Your picture at the top right opens a menu — „Mein Konto", your status, „Abmelden" — checked on the iPhone, at 320 and without JavaScript: [P.11–P.16](#online-status-and-when-somebody-was-online) · with the bell, only one open at a time: 5.3f
- The club's own colours and logo under **Einstellungen → Portal**, cards „Aussehen" and „Logo": [6.7–6.20](#the-clubs-colours-and-logo-einstellungen--portal), in Appearance
- After an update the browser fetches the new stylesheet and script by itself: the account menu is styled and the bell stays still without clearing the cache: A.0
- Everybody signs in with their own e-mail address — a student may have a username instead (L.11–L.16). A person is added either by inviting an address — they fill in their own details and choose a course — or through the wizard „Schüler anlegen"; nobody sets another person's password (ADR 0021, amended by ADR 0023): A.1–A.13
- Families fill in their own details, and every change is in the change log (ADR 0020): A.14–A.18, A.20–A.22, A.25, A.27
- The chat works like a messenger: a group for every course, chats with one person, a dot for everybody and a status emoji; looking through somebody's eyes shows no chat notice in the bell and offers nothing to write; a stored photo keeps only the picture (ADR 0022): C.1–C.6, C.8–C.15, C.17–C.20
- Billing and invoices after the review of October 2026: an archived tariff stays on a child, a cancelled charge can be charged again, „Als bezahlt eintragen" confirms rather than doubles, a charge on an invoice cannot be cancelled, an invoice is not e-mailed to a family who said no, the invoices page counts every invoice, a membership ending mid-month is charged to that day, a child coming back starts afresh, the age filter finds the right children, background charges are German and nobody's, and the „Zahlungsziel" setting that did nothing is gone: [B.1–B.14](#billing-and-invoices-after-the-review-of-october-2026)
- Every student has a login — „Ohne Anmeldung" until somebody gives it an address or a username; a wizard „Schüler anlegen" is the one way to add a child; a username signs in in the same box as an address; a one-time „Anmeldelink" with a QR code for a child without an e-mail address; deleting a child's login gives them a fresh, empty one (ADR 0023): [L.1–L.25a](#every-student-has-a-login-the-wizard-and-sign-in-links-adr-0023)
- A form sent twice — a double tap, or the same form sent again after Back — lands where the first one went, with the first one's message, and makes nothing twice: [L.10b, L.10c](#every-student-has-a-login-the-wizard-and-sign-in-links-adr-0023) in the wizard
- Viewing the portal as somebody else is looking only: everything except „Ansicht beenden" and „Abmelden" is refused, with one sentence: [5.11–5.11b](#the-shell-the-bar-notifications-feedback-impersonation), C.15, C.18
- What the reviews of ADR 0023 found: two people cannot both take a course's last place, a child without sign-in collects no bell notices, a sign-in link dies with its child and waits for the privacy notice, a browser shared between people forgets a half-opened link, a team member's login left on a child's record lets go of the child and stays hers, and a page opened with a list in its address draws without a warning: [L.10a, L.18a, L.20a–L.20c, L.21a, L.25a](#every-student-has-a-login-the-wizard-and-sign-in-links-adr-0023), [7.23](#students-contacts-levels-and-age-groups)
- A view through somebody's eyes ends with the viewer's own login — deleted, suspended or given a new password, the browser looking is signed out on its next tap; a sign-in link's page asks what the link may still do before it shows anything of the login; a trainer is told an administrator releases the privacy notice; a stale „Einladung senden" says the child has a login: [5.11c](#the-shell-the-bar-notifications-feedback-impersonation), [L.20a, L.20d, L.21b](#every-student-has-a-login-the-wizard-and-sign-in-links-adr-0023)
- What ADR 0026 takes out, round one: custom fields with what was typed into them, copying, saved views, writing to many with its templates, „Warteschlange senden", the printed form and data sheet, and Verwaltung's „Tarife" tab: [R.1–R.8](#what-adr-0026-removes-round-one)

---

## The test commands, in detail

```bash
tests/mariadb-local.sh            # the whole suite against a throwaway MariaDB it starts itself
tests/existing-database.sh        # the same suite against an empty _test database you made
```

The run must end in **`0 failed`**. It takes about three minutes.
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
| `logins` | Every student has a login; the wizard; usernames; sign-in links that work once (ADR 0023) |
| `messaging` | Who may read a conversation and who may write to whom |
| `migrations` | An update carries the data with it: prices, discounts, addresses |
| `performance` | Query counts, so a page does not issue one query per row |
| `presence` | The dot, the status staff choose, when somebody was online, and who may see it |
| `presence_pages` | The same on the pages, and the account menu in the top bar |
| `reports` | A problem report's trail of steps, and nothing in it that must never be kept |
| `security` | Authorisation boundaries, credentials, what must not leak |
| `selfservice` | Families completing their own details, and every change in the change log |
| `settings` | Every setting has a type and a usable default |
| `shell` | Notifications, impersonation, avatars, themes, feedback |
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
```

It installs a fresh copy through `setup.php` against its own MariaDB, walks the
nine steps of „Dein Portal einrichten" through their own buttons to „Alles
eingerichtet", invites a family and follows the link out of the captured mail,
has the family upload a proof and send „Etwas funktioniert hier nicht", confirms
the payment, issues an invoice and parses its PDF, and breaks a table on purpose
to check the friendly page and Rückmeldungen. It must end in **`RESULT: PASS`**;
its first lines name the commit, the MariaDB and the PHP it ran on, and those
are the only ones it has proven. **Notes** at the end are things the design
intends that are worth a decision; they do not fail it. Details:
[tests/README.md](tests/README.md#the-first-evening-end-to-end-in-a-browser).

**Last walked:** the working tree at d095ca4 on 07.10.2026, `RESULT: PASS`, 372
checks, against MariaDB 10.11.14 with PHP 8.4.26 (`php -S`), in Chromium at
390px and 320px ([VALIDATION.md](VALIDATION.md)). The U.x checks it walks are
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
prints three sign-ins and one password for all of them; write the password down,
it is not shown again. (From a shell: `php bin/console.php demo:fill` prints the
same three and the password.)

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
to jährlich. Save. Then **Beiträge → Beiträge anlegen** for that November: it
should offer **42,00 €** — November and December of a 252 € year. Create it.

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
`lena.hofer@beispiel.test`. Four menu entries — Übersicht, **Profil**,
Nachrichten, Neuigkeiten — and **Profil** opens their one child; one login is
one child, so there is no list of children. Their own charges only. Try
`?page=student&id=` with a number that is not theirs: it answers 404.

**9 · Put it back.** **Einstellungen → System → „Beispieldaten entfernen"**.
Every invented child, course and charge goes; anything you made yourself stays.

---

## Preparation for the full sweep

- [ ] **2.1** **Einstellungen → System → „Beispieldaten anlegen"** on a portal
  with no real students, or the box on the setup page, which does the same thing
  at install and saves the trip. It creates three courses, fifteen children aged 7 to
  41, contacts, enrolments, charges, payments, attendance, absences, news and a
  conversation. The page then says example data is present.
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
- [ ] **3.2** Enter a wrong database password. It is reported in words, and
  nothing is written.
- [ ] **3.3** Enter two different account passwords. Same: reported, nothing
  written.
- [ ] **3.4** Correct details. `config/config.php` is written, every migration
  is recorded, exactly one administrator exists, and the sign-in page is served.
- [ ] **3.4a** Tick **„Beispieldaten anlegen"** on the setup page. The finish
  page reports what was made and prints three sign-ins with one password for all
  of them. That password is shown once and never again.
- [ ] **3.4b** The same install with the box unticked creates nothing but the
  administrator, which is what a portal about to hold real data wants.
- [ ] **3.4c** `php bin/console.php demo:fill` prints the three addresses and
  the password too. (It used to say „the password printed above" and print no
  password, which left three accounts nobody could sign in to.)
- [ ] **3.4d** Two tabs on `setup.php`, and the honest limit of what one person
  can prove here. Open the setup page in two tabs, fill both in completely with
  **different** administrator addresses, then submit the first and afterwards
  the second. The second tab answers **„Schon eingerichtet — Dieses Portal ist
  fertig installiert."**, and **Konten** afterwards lists **exactly one**
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
  > read is the administrator count in **Konten**. **Two administrators after an
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
  Anwesenheit, Geld, Nachrichten and **Verwaltung**. **Rechnungen** is the
  switch at the top of **Geld**, **Postausgang** is at the top of
  **Nachrichten** (U.45–U.51).
- [ ] **4.2** As the trainer, **Einstellungen** and **Änderungen** are *not* in
  the menu, and typing their addresses by hand is refused.
- [ ] **4.3** As the administrator, everything the trainer can reach, you can
  reach too. There is no screen she has and you do not.
- [ ] **4.4** Sign in as a family. They see the overview, **Profil** — their one
  child's page, since one login is one child — **Nachrichten** and
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
- [ ] **4.7a** The sign-in page carries one small line under the form, „Mit der
  Anmeldung akzeptierst du die Datenschutzerklärung.", the last word a link to the
  notice. Every signed-out page — sign in, forgotten password, invitation, the
  notice itself — ends with the link to the Datenschutzerklärung and the version,
  and nothing longer. At 320px no page scrolls sideways.
- [ ] **4.8** **Konten** offers **„+ Teammitglied einladen"** and nothing that
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
  Then check: **Konten** lists that address **once**, its **role is unchanged**,
  and **Postausgang** holds **no new invitation** to it.
- [ ] **4.9** Suspending a team member in **Konten**, or a family's login with
  **„Zugang sperren"** on the child's page, stops that person signing in.

---

## The shell: the bar, notifications, feedback, impersonation

- [ ] **5.0** On a desktop screen, „Etwas funktioniert hier nicht" is a button in
  the bottom right corner of every page. It opens upwards, stays inside the
  window, and closing it leaves the page where it was.
- [ ] **5.0a** On a phone it is *not* floating: it is at the end of the page,
  reached from „Etwas funktioniert nicht" in the **Mehr** menu. Check on a form
  page that nothing covers the sticky **Speichern** bar.
- [ ] **5.1** Scroll a long page. The top bar stays where it is.
- [ ] **5.1a** As the administrator, on a laptop at **110% and 125% zoom**, the
  menu on the left has no scrollbar of its own and its last entry is above the
  fold. With a course request waiting, its number stands beside **Kurse**.
- [ ] **5.1b** On a phone the menu is the drawer behind **Mehr**, and every row
  in it is 44pt.
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
- [ ] **5.3c** The number on the bell is a badge in the portal's colour, the
  same as the number on **Post** in the bar at the bottom, and its figure can be
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
  then tap your picture: the bell's panel closes as the account menu opens. Then
  the other way round — only one is ever open. The same with the keyboard: Tab
  to the picture and press Enter while the bell is open.
- [ ] **5.3g** In Chrome on a laptop, switch JavaScript off (developer tools,
  then ⌘/Ctrl+Shift+P, type „Disable JavaScript", Enter; it stays off while
  the developer tools are open) and reload a page. The bell still opens
  when clicked and closes when clicked again. A click elsewhere leaving it open
  is expected without JavaScript and passes. Close the developer tools to
  switch JavaScript back on.
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
- [ ] **5.11** Viewing is looking only. As the administrator, **Konten** →
  „Portal als diese Person ansehen" beside a trainer. While viewing, try each of
  these: on **Mein Konto**, change the name under „Name und Darstellung" and
  save; „Etwas funktioniert hier nicht", a sentence, „Absenden"; on a child's
  page, change a detail and save; on a child whose login is in use, „Portal als
  … ansehen". Each is refused with „Beim Ansehen als jemand anderer lässt sich
  nichts schreiben oder ändern. Beende zuerst die Ansicht." In **Nachrichten** a
  chat opens without a writing box. „Ansicht beenden" gives you yourself back,
  and nothing you tried happened: **Konten** shows the trainer's name as before,
  the child's detail is unchanged, and **Einstellungen → Rückmeldungen** has no
  new report.
- [ ] **5.11a** The same as the trainer, viewing a family as in 5.9: a change on
  their child's „Profil" tab, on their **Mein Konto**, and through „Etwas
  funktioniert hier nicht" is each refused with the same sentence. On
  **Übersicht**, „Nachricht schreiben" opens „Neue Nachricht", which shows that
  sentence and lists nobody (C.18). Then, still viewing, „Abmelden" in the
  account menu: you are signed out, and signed in again you are yourself, with
  no bar about viewing anybody.
- [ ] **5.11b** What a view does not draw is refused all the same. As the
  administrator, before starting a view, open two more tabs of your own: one
  where the bell shows a number, one on a course's group with its writing box.
  Start the view in the first tab. Then, in the other two and without reloading
  them, „Alle gelesen" in the bell and a message sent in the group are each
  refused with the same sentence. After „Ansicht beenden" the bell still shows
  its number and the group has no new message. A status chosen in such a tab's
  account menu is refused the same way.
- [ ] **5.11c** A view ends with the viewer's own login, and signs the browser
  out. Two browsers. In the first, as the trainer, „Portal als … ansehen" on a
  child whose login is in use, and open the child's **Nachrichten**. In the
  second, as the administrator, **Konten** → „Zugang sperren" beside that
  trainer. Back in the first, tap anything — a chat, **Übersicht**, „Ansicht
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
- [ ] **6.4** **Mein Konto**: upload a profile picture. It appears in the top
  bar, in conversations and in the account list. „Bild entfernen" puts the
  initials back.
- [ ] **6.5** A child's picture on their page shows in the student list.
- [ ] **6.6** Upload something that is not a picture. Refused in words, and the
  form still holds everything else you had typed.

### The club's colours and logo (Einstellungen → Portal)

Signed in as an **administrator**. Keep a second browser signed out on the
sign-in page, and a family's login at hand. Before you start, note what the
**Aussehen** card shows, so you can put it back.

- [ ] **6.7** Nothing set: every colour field on **Aussehen** is empty and says
  „Standard". Open the sign-in page with the browser's developer tools on the
  network tab and reload: there is **no** request for `page=brand`. The portal
  looks exactly as before the update.
- [ ] **6.8** Set **Hauptfarbe** `#8a1538`, **Menüfarbe** `#0a1030`,
  **Hervorhebung** `#ffcc00`, **Hintergrund** `#fffaf0` and save. The message
  reads „Vorgaben gespeichert. Vorher: Hauptfarbe Standard, Menüfarbe Standard,
  Hervorhebung Standard, Hintergrund Standard." Buttons, links, the menu, the dot
  on the „B" and the page background all change — signed in, and on the sign-in
  page in the other browser.
- [ ] **6.9** Switch the phone (or the computer) to dark mode, then choose
  **Dunkel** under **Mein Konto**. Both show the dark shades worked out from
  your colours; no text disappears into its background, and the text on a
  button stays readable.
- [ ] **6.10** On an iPhone, the bar at the very top of Safari (and of the
  home-screen app) is the menu colour in light mode and the dark background in
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
- [ ] **6.19** On **Aussehen**, tick „Portalnamen neben dem Logo ausblenden" and
  „Zeile „Verwaltung“ / „Mein Portal“ … ausblenden". Check each with a logo, with
  only the portal icon, and with neither: with neither, the name still shows.
  Saving the **Logo** card leaves both ticks as they were.
- [ ] **6.20** „Logo entfernen": the portal icon comes back top left, and after
  removing the icon too, the „B". The message says which.

**Known limit, not a failure:** a photo taken on a phone held upright is often
stored sideways with a note saying "turn me" (EXIF orientation). The browser
turns it, but the portal measures the picture as stored, without the turn. Such
a logo can be refused as "too tall" when it looks wide, or drawn with its width
and height swapped. Reading that note needs PHP's exif extension, which shared
hosting does not promise. The way round is to save the logo from an image
editor or as a screenshot, which stores it the right way up. A logo from a
designer is not affected.

---

## Online status and when somebody was online

You need two phones or browsers: one signed in as an **administrator**, one as a
**trainer**, and a family's login you can view through (**Portal als diese
Person ansehen**). The dot and the status block are in the account menu — tap
your picture at the top right.

- [ ] **P.1** As the trainer, open the account menu. Under **Status** the three
  rows read „Automatisch", „Abwesend", „Als offline anzeigen", and the one you
  have now is marked, not a button. Tap „Abwesend": the page you were on comes
  back — try it on a child's page with a tab open, and the same child and tab
  are still showing — with „Dein Status ist jetzt „Abwesend"." Your dot is
  yellow.
- [ ] **P.2** Tap „Automatisch": „Dein Status richtet sich wieder nach deiner
  Aktivität." and the dot is green again. Nothing new appears under
  **Einstellungen → Änderungen**: a status is not a change worth recording.
- [ ] **P.3** Sign in as a family. The account menu has „Mein Konto",
  „Status-Emoji" and „Abmelden", and no status to choose; their own picture
  carries its dot, which follows what they do (C.8).
- [ ] **P.4** As the trainer, view the portal as that family. The account menu
  has no status block. Stop viewing, then open the family's page: their „zuletzt
  online" has **not** moved to just now — it was you in the portal, not them.
- [ ] **P.5** As the trainer, choose „Als offline anzeigen": „Du wirst jetzt als
  offline angezeigt." Keep using the portal for a few minutes. On the
  administrator's phone, under **Konten**, the trainer shows the true time and
  „(als offline angezeigt)". Have a second trainer look instead (or view the
  portal as one): the dot is grey and „zuletzt" stays at the moment you hid.
- [ ] **P.6** As the administrator, open a child's page whose family has signed
  in this month: **„Wann online? Letzte 30 Tage"** opens to the days they were
  in, newest first, with times in your own time zone. A visit that ran past
  midnight is listed once, under the evening it began. A period while
  somebody appeared offline is marked „(als offline angezeigt)"; a trainer
  looking at the same account does not see it at all.
- [ ] **P.7** In the first month after this update the history also says
  „Aufgezeichnet wird seit dem …" with the day of the update. Nothing before
  that day is shown as „nicht online".
- [ ] **P.8** **Einstellungen → Portal**: the online field reads „Grün –
  „online": aktiv innerhalb von (Minuten)". Under **Erweitert**, blue in
  minutes and yellow in hours. **Einstellungen → System → Erweitert**: „Wann
  jemand online war, aufbewahren (Tage)" refuses 31 and accepts 30 or less.
- [ ] **P.9** With the bell open on a child's page, „Alle gelesen" leaves you on
  that same child, not on „Nicht gefunden".
- [ ] **P.10** **(release)** In the database, set one `online_periods` row's
  `last_seen_at` to 31 days ago and let the nightly cleanup run (or
  `php bin/console.php maintenance`): that row is gone and one from 29 days ago is
  still there.
- [ ] **P.11** On a real iPhone, in Safari, as the trainer — once in light mode
  and once in dark: tap your picture at the top right. Under **Status** the one
  you have now is marked with a tick, not a button. Tap „Abwesend": the page
  comes back with „Dein Status ist jetzt „Abwesend"." at the top, and the dot on
  your picture has turned from green to yellow. „Als offline anzeigen" turns it
  grey. Put it back to „Automatisch".
- [ ] **P.12** On the iPhone, signed in as a family, tap the picture: the menu
  holds „Mein Konto", „Status-Emoji" and „Abmelden" — P.3, on the phone.
- [ ] **P.13** As the administrator, on a child whose login is in use, **„Portal
  als … ansehen"**, then tap the picture: no **Status**, no „Status-Emoji" and no
  dot — they are the reader's own. (P.4 is the same for the trainer.) „Ansicht
  beenden" afterwards.
- [ ] **P.14** On an iPhone 320 pixels wide (an iPhone SE of the first
  generation), as the trainer, tap your picture: „Abmelden" is above the bar at
  the bottom, or can be reached by scrolling inside the menu — never hidden
  behind the bar. Safari's own bars leave a small iPhone less height than a
  desktop browser set to 320 has, so only the phone can pass this; a menu that
  scrolls passes as long as „Abmelden" can be tapped. Tap it: you are signed
  out. No such iPhone to hand? Write down that this was not checked.
- [ ] **P.15** **Konten**, and a child's page whose family has a login: open
  **„Wann online? Letzte 30 Tage"** on your own row. The days and times match
  when you really used the portal this week. Then the trainer who chose „Als
  offline anzeigen" in P.5: as the administrator, those times are listed and
  marked „(als offline angezeigt)"; through a second trainer's eyes they are not
  listed at all.
- [ ] **P.16** With JavaScript switched off, as in 5.3g: tap your picture — the
  menu opens, and tapping the picture again closes it. Choose „Abwesend": the
  message appears and the dot is yellow; put it back to „Automatisch". Then
  „Abmelden": you are signed out.

---

## Students, contacts, levels and age groups

- [ ] **7.1** Create a child with a name and a date of birth (L.3–L.10 walk the
  wizard itself). On their page, under **Einteilung**, the level is **Anfänger**
  unless you changed which one is default.
- [ ] **7.2** The age group is worked out from the date of birth — „Unter 12",
  „Jugend", „Erwachsene" — and says it was worked out, not chosen.
- [ ] **7.3** Set the age group by hand. It stays set, and says it was set by
  hand, even after a birthday would have moved it.
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

- [ ] **11.1** **Beiträge → Beiträge anlegen** previews what would be created:
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
- [ ] **11.12** „Alle überfälligen per E-Mail erinnern" queues one email per
  overdue charge, to the child's own login, and counts as skipped every child it
  cannot reach by e-mail. (Whether one per family would be better is open in
  ROADMAP.md.)

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

- [ ] **14.1** **Nachrichten** as a family: one box, a paper clip, a microphone,
  a send arrow. Write to the trainer without asking anybody's permission.
- [ ] **14.2** Attach a photo — it shows as a picture in the bubble. Attach a
  PDF — it shows as a file to open.
- [ ] **14.3** Record a voice message with the microphone and send it. It plays
  back in the bubble with its length beside it. (Needs a browser that can
  record; where it cannot, the microphone is simply not there and the paper clip
  still works.)
- [ ] **14.4** With JavaScript switched off, the paper clip is an ordinary file
  field and the message still sends.
- [ ] **14.5** One family asks another to be in touch. Nothing can be written
  until that other family agrees.
- [ ] **14.6** Once they agree, both directions work. Declining keeps it shut.
- [ ] **14.7** **The private one, and check it from every side:** open a
  conversation between two families. The trainer cannot see it in her list, in
  her unread count, or by opening its address. Neither can the administrator.
  Nor can either of them open its attachments.
- [ ] **14.8** A chat with the trainer is read by the trainer and the child, and
  by the administrators — not by a second trainer (ADR 0022). The old shared
  conversations from before stay readable under „Frühere Unterhaltungen" and take
  no new messages.
- [ ] **14.9** Unread markers clear when a conversation is opened, and the count
  in the menu agrees with the list.
- [ ] **14.11** An empty message is refused.

---

## News, email and the queue

- [ ] **15.1** Publish a news item. Families see it under **Neuigkeiten**.
- [ ] **15.2** Save a news item with **„Diese Fassung auch an alle senden, die
  Neuigkeiten per E-Mail erhalten"** ticked. **Postausgang** holds one email for
  each family whose **„Neuigkeiten per E-Mail erhalten"** is on under **Mein
  Konto**, and none for a family who switched it off.
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
  two email addresses of your own that have no login yet. On a child,
  **„Zugang zum Portal" → „Einladung senden"** to the first; open the invitation
  and its link. **„Neuigkeiten des Vereins per E-Mail erhalten. Jederzeit
  abbestellbar."** is **already ticked**. Untick it, tick „Ich habe die
  Datenschutzhinweise gelesen.", choose a password and tap **„Konto
  aktivieren"**. Signed in as that family, **Mein Konto** shows **„Neuigkeiten
  per E-Mail erhalten"** switched off. Do the same for the second address on
  another child, but leave the box ticked. Now save a news item as in 15.2:
  **Postausgang** has one for the second address and **none for the first**, and
  only the second inbox receives it. Without the second address, a news mail
  that reached nobody would pass this check. Afterwards, on each of the two
  children's pages, **„Anmeldung löschen"**: each child is „Ohne Anmeldung"
  again.

---

## Filters

- [ ] **17.1** **Schüler**: filter by level, age group, course and status. The
  list shows the children that match, and the line under the heading counts
  them.
- [ ] **17.2** The chips above the list, „Überfällige Beiträge" and „Aktuell
  krank", each open that selection with one tap; „Alle Schüler" goes back to
  everybody. There is nothing to save a selection under a name with.

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
- [ ] **20.4** The released notice says what this version stores and sends: when
  each account was online (last visit, 30 days of periods, seen only by trainers
  and administrators), club news by email being on for new accounts, the course
  groups and the online dot, and usernames and sign-in links. A portal that was
  updated keeps the text saved before, so these arrive only if they were pasted
  in (UPDATING.md). No „[…]" note is left in the released text.

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
- [ ] **21.7** After deleting an account or a child that had a picture, an
  attachment or a proof, the file goes too — the nightly maintenance sweeps
  anything no record points at. `storage/uploads` should not grow for ever.

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
  small line, „Mit der Anmeldung akzeptierst du die Datenschutzerklärung." —
  the last word a link that opens the notice. The long paragraph about cookies
  is gone, and at the bottom of every signed-out page there is only the link to
  the Datenschutzerklärung and the version number. At 320px nothing scrolls
  sideways.

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
- [ ] **U.11** A long conversation under **Nachrichten**, on a screen at least
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
- [ ] **U.16** Upload a picture (a profile picture will do), then report: the
  step shows the file's size and type, never its name.
  *Also walked by `tests/e2e.sh` with a payment proof instead of a picture: size and type, no name. Passed at 91520db.*
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

- [ ] **U.20** **(release)** A child „Ohne Anmeldung" with no address at all
  shows „Trag oben zuerst eine E-Mail-Adresse ein und speichere." on **„Zugang
  zum Portal"**, and no button. Enter an address nobody uses yet, save, and tap
  **„Einladung senden"**: the badge turns to **Eingeladen** and the invitation is
  in **Postausgang**. (A brother with the same address: L.9.)
  *`tests/e2e.sh` walks this at d095ca4 and it passed: „Ohne Anmeldung“, the sentence with no button, the invitation and Postausgang.*
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
  **„Anmeldung löschen"**. Each one lands back on the same child's page, not on
  Konten.
- [ ] **U.25** **(release)** As the trainer, **Konten** lists the team and offers
  no invitation at all: not for a team member (4.8a), and not for a family.
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
  gilt bis …"), **Noch nicht angemeldet** (a username waiting for its first
  sign-in), **Aktiv** and **Gesperrt**.
- [ ] **U.28b** **(release)** Delete a child that has a login, after first reading
  the warning that its login would be left over. **Konten** then shows that login
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
- [ ] **U.36** **(release)** As the administrator, switch **„Monatsbeiträge automatisch
  anlegen"** on and off on the **Beiträge** page. A trainer is not offered it. Under **Einstellungen → System** it is no
  longer listed.
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
  Übersicht, Schüler, Kurse, Anwesenheit, Geld, Nachrichten, Einstellungen, with
  **„Einrichtung"** above them while the checklist is unfinished. Nothing in it
  folds open or shut. Open **Rechnungen** or a single child: **Geld** or
  **Schüler** stays marked, so you always see where you are.
- [ ] **U.46** On your phone, the bar at the bottom reads **Übersicht, Schüler,
  Post, Anwesend, Mehr**. **Mehr** opens the same seven.
  *`tests/e2e.sh` reads the bar at 390px in Chromium, passed at 91520db. „Mehr“ opening the seven is still by hand.*
- [ ] **U.47** **(release)** As a trainer, the seventh entry is **Verwaltung**, not
  Einstellungen, and the phone bar is the same as yours.
- [ ] **U.48** On a child that has a login, **„Portal als … ansehen"**, on your
  phone: the family's bar reads **Übersicht, Profil, Post, Neues, Konto**, with no
  **Mehr**. **Konto** opens their **Mein Konto**. **Ansicht beenden** afterwards.
  *`tests/e2e.sh` reads the family's bar signed in as the family, not through „Portal als … ansehen“, passed at 91520db.*
- [ ] **U.49** **Einstellungen** opens with cards above the tabs: **Verwaltung**,
  **Konten**, **Änderungen**, **Einrichtung ansehen** and **Erweitert**. Each card
  opens its page, and **Einstellungen** stays marked in the menu. Postausgang is
  not among them — it is under Nachrichten (U.50).
- [ ] **U.50** At the top of **Nachrichten**: **„Neuigkeiten"** and
  **„Postausgang"**, each opening its page with **Nachrichten** still marked. An
  administrator also has „Alle Direktchats" there.
- [ ] **U.51** At the top of **Beiträge** and of **Rechnungen**, a switch
  **Beiträge · Rechnungen** takes you from one to the other; **Geld** stays marked
  on both.
- [ ] **U.52** **Mein Konto** ends with **„Datenschutz und Hilfe"**: the
  Datenschutzerklärung, „Etwas funktioniert nicht", the version and **Abmelden**.
  On a family's phone (U.48) this is the only way to the privacy notice, so check
  it there too.
  *`tests/e2e.sh` checks it as the family, passed at 91520db. As yourself it is still by hand.*

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
- [ ] **U.56** Switch the portal to **EN** at the top, open the privacy notice
  (**Mein Konto → Datenschutz und Hilfe**). With no English text written, you see
  the German one with „This privacy notice is only available in German. …" above
  it. Switch back to **DE**.
  *Also walked by `tests/e2e.sh` as the family, passed at 91520db.*
- [ ] **U.57** **(release)** On the copy, save SMTP and do not test it. **„Einladung
  senden"** on a child is refused with „Eine Einladung lässt sich noch nicht
  verschicken. E-Mail-Versand zuerst testen: unter „Einstellungen → SMTP" die
  Verbindung prüfen." After **„Nur Verbindung prüfen"** succeeds, the invitation
  goes out. Change the server and save: refused again until the next passing test.

**A profile picture is kept by the browser, and shown only to who may see it**

- [ ] **U.58** With a profile picture of your own, move between four or five
  pages on the phone: the picture in the top bar is there the moment each page
  appears, not blank for a moment and then filled in.
- [ ] **U.59** **Mein Konto**: upload a different picture. The top bar shows the
  new one on the very next page — never the old one — and so does a child's
  page after changing the child's picture. „Bild entfernen" puts the initials
  back on the next page.
- [ ] **U.60** **(release)** In a desktop browser's developer tools
  (**Netzwerk** / **Network**), open a page: the picture's request answers with
  `Cache-Control: private, max-age=604800` and no `Pragma` or `Expires`. Go to
  another page: it comes „from memory cache" / „from disk cache". An invoice PDF
  or a message attachment still answers `private, no-store`. Sign out: the
  logout's response carries `Clear-Site-Data: "cache"`. (Safari may ignore that
  header; the picture's copy then runs out on its own within a week.)
- [ ] **U.61** **(release)** On the copy, signed in as family A, open
  `?page=download&what=avatar&kind=student&id=` with the id of a child of
  another family who has a picture: „Nicht gefunden", no picture. The same with
  `kind=account` and another family's account id. Your own child's, your own
  and the trainer's still show.
- [ ] **U.62** As a family, **Nachrichten → Neue Nachricht**: other families in
  the list show their initials, never their photograph; the trainer shows her
  picture. As the trainer, every family shows its picture.

**After an update** — on the phone and on the computer, without clearing anything

- [ ] **A.0** Upload the new version and open the portal in the browser that
  had it open before. The page source links `app.css?v=` and `app.js?v=`
  followed by twelve letters and digits, not the version number. The account
  menu at the top right opens as a styled list, and the bell does not move when
  tapped. Club colours, if set, are the current ones.

**One person, one address, and the address signs in** (ADR 0021) — on the iPhone
where it says so

- [ ] **A.1** Signed out, the sign-in page asks for „E-Mail oder Benutzername"
  and „Passwort", nothing else. Sign in with the address in odd capitals,
  `LENA@Beispiel.AT`: it works. On the iPhone the Keychain offers the saved
  address and fills the password.
- [ ] **A.3** A wrong password, an unknown address, a suspended login and an
  invitation not yet opened each give **the same** sentence: „Anmeldung nicht
  möglich. Bitte E-Mail bzw. Benutzernamen und Passwort prüfen. Noch nicht
  eingerichtet? Dann zuerst den Link öffnen, den du bekommen hast."
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

- [ ] **A.7** **Schüler → „Per E-Mail einladen"**, a real second address,
  language English. The flash says the invitation is on its way, and „Offene
  Einladungen" lists the address with the date it was sent. The mail opens
  "Hello," with no name and says the person fills in their details and then
  chooses a course.
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

- [ ] **C.1** As the trainer, **Nachrichten**: „Kursgruppen" lists a group for
  every running course, then „Einzelchats". A course made today has its group
  straight away; an archived course's group is gone from the list.
- [ ] **C.2** As a child enrolled in one course, on the iPhone at 320 px: the
  list shows that one group and the chat with the trainer, nothing else. Tap the
  group: one thing on the screen at a time, the arrow at the top goes back, and
  the writing box sits above the menu bar, not under it, and opens at the newest
  message.
- [ ] **C.3** In a group, as the child, send a text, a photo and a voice note.
  The trainer and another child of the course see all three; a child of another
  course cannot open the group at all (the address typed by hand says
  „Unterhaltung nicht gefunden"). No e-mail and no bell entry is made for a group
  message.
- [ ] **C.4** Take the child out of the course (end the enrolment). On the next
  page the group is gone from their list. Put another child in: they read the
  group's earlier messages.
- [ ] **C.5** As the trainer, „⋯" on a child's group message → „Nachricht
  entfernen": everybody sees „Nachricht entfernt", its photo no longer opens. The
  same „⋯" → „Wiederherstellen" brings it back.
- [ ] **C.6** As a child, „Neue Nachricht" → the trainer: the chat opens, the
  first message makes it, and it says the administrators can read it. As the
  administrator, it is not in your list and not in your badge, but under „Alle
  Direktchats"; you can read it and cannot write in it. A second trainer cannot
  open it.
- [ ] **C.8** The account menu, as a child: their dot on their picture, no
  status choice, and „Status-Emoji". Pick 🦊 with JavaScript off: it saves with
  one tap and stands beside their name in the menu and in a group. „Keins"
  removes it. As the trainer, the three status choices are there without the
  sentence that used to explain them.
- [ ] **C.9** A child sees other children's dots and emojis in the group, never
  when anybody was last online, and never anybody's online history.
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
- [ ] **C.14** First, two children who may write to each other: as the second
  child, „Neue Nachricht" → „Jemand anderen fragen" → „Anfragen" beside the
  first; as the first, „Neue Nachricht" → „Zustimmen" under „Möchte dir
  schreiben". Then, as the trainer, write to the first child in your chat with
  them, and as the second child, write to them too. As the trainer, **Schüler**
  → the first child → „Portal als … ansehen" on the card „Zugang zum Portal":
  the bell shows neither message — no „Neue Nachricht von …" line — its number
  counts only the other notices, and there is no „Alle gelesen" in it.
  „Ansicht beenden", then sign in as the first child: both notices are there,
  and unread.
- [ ] **C.15** As the administrator, **Einstellungen → Konten**, „Portal als
  diese Person ansehen" beside a trainer. **Nachrichten → Neuigkeiten →
  + Neuigkeit**, a title and some text, „Speichern": refused with „Beim Ansehen
  als jemand anderer lässt sich nichts schreiben oder ändern. Beende zuerst die
  Ansicht." Open a course's group: no writing box, and no „⋯" on any message.
  „Ansicht beenden": every group message is still there, and no news item was
  added.
- [ ] **C.17** A photo that holds more than one picture: on a Samsung or a Pixel
  a motion photo („Bewegtes Foto"), on an iPhone a photo in HDR or a portrait
  photo. Send it into a group, then on the Mac save it back from the chat: it
  opens in Preview as the same photo, the right way up, plays no video,
  Werkzeuge → Informationen shows no GPS tab, and in the Finder it is smaller
  than the original. On an HDR screen it may look less bright than the
  original: the brightness map is a second picture in the file, and it goes
  with the rest. Then send a screenshot (PNG) and a WebP saved from a browser:
  both open and look as they did.
- [ ] **C.18** As the trainer, **Schüler** → a child who has a request from
  another child still waiting (C.14's first step, without „Zustimmen") →
  „Portal als … ansehen" on the card „Zugang zum Portal". The view opens on
  **Übersicht**: tap „Nachricht schreiben". The page „Neue Nachricht" says
  „Beim Ansehen als jemand anderer lässt sich nichts schreiben oder ändern.
  Beende zuerst die Ansicht." and lists nobody — no „Trainerteam", no
  „Kinder", no „Möchte dir schreiben" with the request's text, no „Jemand
  anderen fragen". **Nachrichten** has no „Neue Nachricht" button and no „1 neue
  Anfrage" at the top. In the child's group, the people symbol in the top bar
  lists the members, and tapping a trainer there opens nothing.
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
  tick below sends every family in the course an e-mail. Sign in as a child who
  has a login and, if the bell shows a number, tap „Alle gelesen"; sign out.
  As the trainer, write to that child in your chat with them. Then **Kurse** →
  the child's course → „Termine" → a coming date → „Was ist damit": „Entfällt",
  tick „Alle Kursteilnehmer per E-Mail informieren", „Speichern". Now
  **Schüler** → the child → „Portal als … ansehen" on the card „Zugang zum
  Portal": the number on the bell is 1, and the pane shows „{Kurs} – {Datum}"
  with „Entfällt" under it and no „Neue Nachricht von …" line. „Ansicht
  beenden", then sign in as the child: the number is 2, and both are there.
  Afterwards, as the trainer, open the same date again and save it as „Findet
  statt" without the tick: „Termin folgt wieder dem normalen Plan."

### Billing and invoices after the review of October 2026

On the example data or a copy, never on a real family: several of these
cancel, mark paid or e-mail.

- [ ] **B.1** **Kurse** → a course → **Tarife**: archive the tariff one child
  is on. On that child, **Kurse** → „Tarif, Zahlungsweise und Rabatt": change
  only the payment day and save. The child's line still names the archived
  tariff, and **Beiträge → Beiträge anlegen** previews a charge for them rather
  than „Kein Tarif gewählt". Once the page is updated (frontend-dev), the
  tariff list shows that tariff with „(archiviert)" after it; choosing another
  one works, and no other child can be put on the archived one.
- [ ] **B.2** On the same form, set „Ausgetreten am" before „Dabei seit" and
  save: „Das Enddatum liegt vor dem Startdatum.", and nothing changed.
- [ ] **B.3** Run **Beiträge anlegen** for next month. On one child, „Beitrag
  stornieren" on the new charge, change their agreed price, and run the same
  month again: one charge is created, at the new price — not „Nichts zu tun".
  Run it a third time: nothing more. **Änderungen** shows the cancellation as
  „Storniert", with no `billing_key` line.
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
- [ ] **B.6** As a family, **Mein Konto**: untick „Erinnerung, wenn ein Beitrag
  offen ist" and save. As the trainer, on that child's invoice „Per E-Mail
  schicken": refused in a sentence that says the family switched these e-mails
  off. The invoice does **not** say „per E-Mail geschickt am", and
  **Postausgang** holds nothing new. Tick the box again as the family: the
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
  thirteenth birthday is today are not. (Children with a pinned age group are
  listed by the pin, as before.)
- [ ] **B.12** With „Monatsbeiträge automatisch anlegen" on **Beiträge**, and
  the month not yet billed: sign in as a family whose language is English and
  open two pages a minute apart. As the trainer, the new charges read „Beitrag
  Oktober" (or the German month), not „Beitrag October", and the audit log has
  „billing.generated" with nobody as the actor.
- [ ] **B.13** **Beiträge** → „Alle überfälligen per E-Mail erinnern" for a
  child with one overdue charge and one past its date but fully paid: the
  message counts one reminder and says nothing is „übersprungen".
- [ ] **B.14** **Verwaltung → Geld & Zahlungen**, the defaults under the
  payment recipients: there is no „Zahlungsziel für Monatsbeiträge" any more. When a charge is due comes from the tariff's
  „Zahltag" and „Tage bis überfällig", which B.9's charge shows.

### Every student has a login, the wizard and sign-in links (ADR 0023)

On the example data or a copy, never on a real family: several of these make,
use and delete logins. The screens are backend-dev's working minimum until
frontend-dev builds the designer's (docs/design/2026-10-05-accounts-and-chat-screens.md);
the checks are about what happens, and hold for both.

**The update**

- [ ] **L.1** **(release)** On a copy of a portal from before this release with
  children who have no login — two of them sharing a parent's address — upload
  the files and open any page. The update runs without a command, the portal
  opens again, and **Schüler** shows every child as before. Each child's
  **„Zugang zum Portal"** card says „Ohne Anmeldung"; children who had a login
  keep it, with the same address and state.
- [ ] **L.2** **(release)** On that copy, **Einstellungen → System → Beispieldaten**:
  „Beispieldaten entfernen" still removes every example child, and **Konten**
  lists no example login afterwards.

**The wizard „Schüler anlegen"**

- [ ] **L.3** **Schüler → „+ Schüler anlegen"** (and the overview's button, and
  the checklist's „Kinder eintragen" on a portal with no child) opens „Neuer
  Schüler – Schritt 1 von 2". An old bookmark to the student page without a
  child opens it too.
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
- [ ] **L.8** Card **„Ohne Anmeldung anlegen"**: the child is created, in the
  course and on the tariff chosen at step 1, from today. The done page says
  „Ohne Anmeldung – du trägst alles selbst ein." and „Versehentlich angelegt?
  …"; the child's access card says „Ohne Anmeldung".
- [ ] **L.9** Card **„Per E-Mail einladen"** with an address another login has:
  refused, naming whose; nothing created, and the card comes back with the
  address. With an address of the child's own: created, the invitation in
  **Postausgang**, and the done page names the address and until when its link
  works. With mail not set up, the card says what is missing instead of a form.
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
  shows the cards: „{Vorname Nachname} ist schon angelegt.", with „Weiter",
  which opens the done page, and „Noch einen Schüler anlegen", and no card to
  tap. Reload: the same. Go Back once more, to step 1, and if it still shows
  the child's details, „Weiter": the same sentence. **Schüler** still lists the
  child once.

**Usernames and the „Anmeldelink"**

- [ ] **L.11** Card **„Ohne E-Mail, mit Benutzername"**: the box suggests
  first name, dot, last name (`lena.hofer`, umlauts as ae/oe/ue, ß as ss). Try
  `lena_hofer` and `lena@hofer`: refused, saying the rule. Try a username
  another child has, in capitals: refused, naming a free one. Accept the
  suggestion: the done page shows a QR code, the link, the username and until
  when it works (48 hours), and the warning to send it only to the child or the
  parents. **Postausgang** holds nothing for this child.
- [ ] **L.12** **(iPhone)** Scan the QR code from the screen with a second
  phone's camera: the link opens. Send the link to yourself on WhatsApp: the
  preview appears, and the link still works afterwards — the preview used
  nothing up. (Once frontend-dev adds „Teilen", it opens the share sheet.)
- [ ] **L.13** Open the link signed out: the page shows the username, read-only,
  asks for a new password twice and for the privacy tick, and offers no e-mail
  ticks. Tap save without a password, with two different ones, and without the
  tick: each refused, and the link still works. Then do it properly: you land
  on the child's own page, told „Du meldest dich ab jetzt mit lena.hofer an.
  Willkommen, Lena! …". **(iPhone)** iCloud Keychain offers to save the password
  under `lena.hofer`.
- [ ] **L.14** Open the same link again: „Link nicht mehr gültig", with the
  sentence that a link from the trainer works only once.
- [ ] **L.15** Sign out and sign in with `Lena.Hofer` in the box „E-Mail oder
  Benutzername", as an iPhone capitalises it: it works. Sign in with an address
  in the same box: it works.
- [ ] **L.16** „Passwort vergessen" with `lena.hofer`: the same answer as for
  any address, the page says a login without an address gets a new link from
  the trainer, and **Postausgang** holds nothing.
- [ ] **L.17** As the trainer, on that child's access card (the login is now in
  use): no „Anmeldelink erstellen", no „Link zum Zurücksetzen senden". As an
  administrator: „Anmeldelink erstellen" is there. Make one, then make another:
  the first link no longer works. Use the second: only the password changes;
  the card says who made the link and that it was used, and the child's old
  password no longer signs in.
- [ ] **L.18** Make a link, then „Link zurückziehen": the link no longer works.
  Make one and „Zugang sperren": the link no longer works; „Zugang entsperren"
  makes no new one.
- [ ] **L.18a** As an administrator, on a child whose login is in use and who
  has no charges, „Anmeldelink erstellen" (or „Neuen Link erstellen") → „Link
  erstellen", and keep the link. Delete the child at the bottom of their page,
  typing the full name. Open the link: „Link nicht mehr gültig". The login is
  still on **Konten**, under „Zugänge ohne Schüler".
- [ ] **L.19** A link made by one member of staff is not shown to another, nor
  to the same person after signing out and in again; it can then only be made
  anew.
- [ ] **L.20** A child invited by e-mail and not set up yet: no
  „Anmeldelink erstellen" for anybody. A trainer's or an administrator's login
  never has one either.
- [ ] **L.20a** On the example data, never on a portal families use — while
  this is unticked nobody can be invited either: **Einstellungen →
  Datenschutz**, untick „Die Datenschutzerklärung ist vollständig und zur
  Verwendung freigegeben." and save. On a child „Ohne Anmeldung",
  „Anmeldelink erstellen" with a username, „Link erstellen": refused with „Ein
  Anmeldelink geht erst, wenn die Datenschutzerklärung freigegeben ist – sie
  wird bei der ersten Anmeldung bestätigt. Die Datenschutzerklärung unter
  „Einstellungen → Datenschutz“ freigeben.", and the child is still „Ohne
  Anmeldung". The same refusal on a child with a username still waiting for
  the first sign-in (made as in L.11 before unticking). In the wizard, the card
  „Ohne E-Mail, mit Benutzername" shows „Die Datenschutzerklärung unter
  „Einstellungen → Datenschutz“ freigeben." instead of its form. Then, still
  unticked, as the trainer: the same „Link erstellen" on another child „Ohne
  Anmeldung" is refused with „… bei der ersten Anmeldung bestätigt. Eine
  Administratorin muss zuerst die Datenschutzerklärung freigeben." — nothing
  sends her to „Einstellungen", which she cannot open — and the wizard's card
  says the same sentence. Tick the box again and save.
- [ ] **L.20b** A browser several people use, such as a tablet at the hall.
  Open a child's sign-in link there and leave its page without saving. Sign in
  on that browser as somebody else — the trainer, say — then go Back to the
  link's page, or open the portal's address with `?page=activate` at the end:
  „Link nicht mehr gültig". Whoever signs in next does not land in the
  family's half-finished set-up; the link itself, opened again, still shows
  its page.
- [ ] **L.20c** **(release)** The same with the trainer signed in first. On a
  copy, set `session_idle_minutes` in `config/config.php` to 2 (setup writes
  120: two hours). Sign in as the trainer, then in the same browser open a
  child's sign-in link and fill in its page, but tap save only after three
  minutes without opening any other page: „Dieser Link ist ungültig oder
  abgelaufen. Bitte eine neue Einladung bzw. einen neuen Link anfordern." above
  „Link nicht mehr gültig". This is by design and fails safe: the trainer's
  session ran out and took the half-opened link with it. Open the link again:
  it works, and the child is signed in. Put `session_idle_minutes` back.
- [ ] **L.20d** **(release)** A sign-in link that can no longer be used shows
  nothing of its login. On a copy: give a child a username as in L.11 and keep
  the link. In the database, give that login an address nobody confirmed
  (`UPDATE accounts SET email='neu@example.test' WHERE username='…'`). Open the
  link signed out: „Link nicht mehr gültig", with no password boxes, and
  neither the address nor the username anywhere on the page. Before this
  release the page offered the form with the address above it, and only the
  save was refused.

**Replacing a login, and enrolment**

- [ ] **L.21** On a child whose login is in use, **„Anmeldung löschen"** asks
  for the address (or the username) typed; typed in other capitals, it deletes.
  The child stays, „Ohne Anmeldung", with courses, charges and invoices; the
  child's chat with you is gone from **Nachrichten**. The address is still on
  the record, so „Einladung senden" is offered again.
- [ ] **L.21a** **(release)** A team member's login left on a child's record,
  as a portal from before ADR 0010 can have. On a copy, in the database, point
  one child's `students.account_id` at a trainer's login that no other child
  has. As a trainer, „Anmeldung löschen" on that child is refused. As an
  administrator, „Anmeldung löschen" with the trainer's address typed: the
  message names the child and says the team member's login stays —
  „{Vorname Nachname} hat jetzt eine neue, leere Anmeldung. Die Anmeldung von
  {Trainerin} gehört zum Team und bleibt, wie sie ist." — and the child has a
  fresh, empty login, „Ohne Anmeldung". The trainer is still on **Konten**,
  signs in as before, and finds her chats. „Zugang löschen" beside her on
  **Konten** is then an ordinary deletion of a team login, with nothing sent
  round in a circle.
- [ ] **L.21b** A card opened before a child got a login. Open a child „Ohne
  Anmeldung" whose record has an address, in two tabs. In the first, clear the
  child's „E-Mail-Adresse" and save, then give the child a username
  („Anmeldelink erstellen", „Link erstellen"). In the second, still offering
  „Einladung senden", tap it: refused with „Dieses Kind hat schon eine eigene
  Anmeldung. Zugang, Einladung und Adresse werden dort verwaltet." — not with
  a sentence about the address — and **Postausgang** holds no invitation.
- [ ] **L.22** On a child waiting for a first sign-in with a username,
  „Benutzernamen zurückziehen": „Ohne Anmeldung" again, and the same username
  can be given to them again.
- [ ] **L.23** „Ansehen" (view the portal as the child) is offered only for a
  login in use — never for „Ohne Anmeldung", „Eingeladen" or „Noch nicht
  angemeldet".
- [ ] **L.24** A child „Ohne Anmeldung" can be put into a course on its
  **Kurse** tab as before; billing runs for them as for anybody.
- [ ] **L.25** In a course with a child „Ohne Anmeldung" and a child whose
  login is in use, change a date with „Alle Kursteilnehmer per E-Mail
  informieren" ticked. Signed in as the child in use, the bell shows the change.
  Then invite the first child and accept the invitation: their bell does not
  list the change from before they had a login.
- [ ] **L.25a** On a child „Ohne Anmeldung" who has a charge, **Rechnungen →
  Rechnung erstellen**. Then invite the child and accept the invitation, as in
  L.25: their bell has no „Neue Rechnung: …". A login nobody signs in with
  collects no bell notices, so there is nothing old waiting when somebody
  first does.

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
  kopieren". („Kopieren" beside a sign-in link and „Für den Support kopieren"
  put text on the clipboard; they stay.)
- [ ] **R.4** **Schüler** has no „Diese Auswahl als Ansicht speichern", and its
  chips are „Alle Schüler", „Überfällige Beiträge" and „Aktuell krank", nothing
  else. A saved view's old address, `?page=students&saved=1`, shows all
  children.
- [ ] **R.5** No „An mehrere schreiben", at the top of **Nachrichten** or on
  **Übersicht**; no „Auswahl anschreiben" under the **Schüler** filter; no
  „Zahlungserinnerung schreiben" on **Geld**, where „Alle überfälligen per
  E-Mail erinnern" stays. `?page=compose` answers „Seite nicht gefunden.".
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
