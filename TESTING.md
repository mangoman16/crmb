# Testing

What this portal does, and how to prove each part of it still works.

Written for the administrator, to be used **after every code change and before
every release**. The checks are in the order you would naturally walk through
the app, and each one says what to do and what you should see. Nothing here
needs a debugger: if a check fails, write down its number, and that number is
enough for somebody else to reproduce it.

German labels are quoted the way the portal shows them, because the portal is
German by default and searching for the English word will not find the button.

---

## The two commands, first

```bash
php tests/run.php                 # the whole suite, no database server needed
tests/mariadb-local.sh            # the same suite against a real MariaDB
```

`php tests/run.php` must end in **`0 failed`**. It takes about ten seconds.
Anything else and the manual sweep below is a waste of your time — fix that
first.

The run ends by printing what its SQLite translation could **not** cover
(foreign keys on three tables, and the MySQL-dialect backup). Those lines are
not a warning, they are the honest edge of the measurement: `tests/mariadb-local.sh`
is what covers them, and it is the run to quote when you say a release works.

| Suite | What it holds the line on |
|---|---|
| `attendance` | Statuses, the suggested training day, the summary figures |
| `billing` | Charge periods, intervals, discounts, proration, due and overdue dates |
| `contacts` | Somebody to ring, one standard contact, and an address to invoice |
| `dates` | UTC to local conversion, money as integer cents |
| `demo` | Example data fills, is recognisable, and comes out again completely |
| `pages` | Every page opens for every role with data behind it, warnings included |
| `enrolment` | Timetables, joining and leaving, who decides |
| `forms` | A rejected form comes back filled in; defaults are visible as defaults |
| `groups` | Levels and age groups, and the difference between them |
| `history` | The change log records what differed, and only that |
| `install` | The browser installer, migrations applying themselves, the refusals |
| `invoices` | § 11 UStG details in the produced document, numbering, status |
| `messaging` | Who may read a conversation and who may write to whom |
| `performance` | Query counts, so a page does not issue one query per row |
| `security` | Authorisation boundaries, credentials, what must not leak |
| `settings` | Every setting has a type and a usable default |
| `shell` | Notifications, impersonation, avatars, themes, feedback |
| `structure` | That no file has been silently destroyed, every value is escaped, every page classified |
| `transactions` | A failed write leaves nothing behind |
| `uploads` | Limits, allowed kinds, and files swept once their record has gone |
| `views` | The real pages render and say what they are supposed to say |

Run one on its own while working: `php tests/run.php billing invoices`.

---

## The five-minute sweep — after any code change

If you changed one thing, these six are the ones that catch a broken deploy.

- [ ] **1.1** Open the portal signed out. The sign-in page appears, styled — not
  a wall of unstyled text, and not a blank page.
- [ ] **1.2** Sign in as administrator. The overview opens with the top bar
  pinned, your name at the right, and no red error strip.
- [ ] **1.3** Open **Schüler** and then one child. Their details show, with no
  empty boxes where a name should be.
- [ ] **1.4** Change something small (a note) and save. The green confirmation
  appears and the new value is on the page after the reload.
- [ ] **1.5** Open **Einstellungen → System**. The version shown there matches
  the version in `VERSION`, and the database says it is on the same one.
- [ ] **1.6** Open **Nachrichten**. The conversation list draws, and a
  conversation opens.

---

## Preparation for the full sweep

- [ ] **2.1** **Einstellungen → System → „Beispieldaten anlegen"** on a portal
  with no real students. It creates three courses, fifteen children aged 7 to
  41, contacts, enrolments, charges, payments, attendance, absences, news and a
  conversation. The page then says example data is present.
- [ ] **2.2** Press it a second time. It refuses, and says so. It does not
  create a second set.
- [ ] **2.3** Note the password the screen gives you for the example accounts —
  you need it for the family checks further down.

> Never fill example data into a portal holding real students. The button
> refuses on its own, but the rule is yours to keep as well.

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
- [ ] **3.5** Open `setup.php` again. It answers 403 and creates nothing.
- [ ] **3.6** `bash bin/update.sh --check` on an installed copy reports the file
  version, the database version and whether anything is pending, and changes
  nothing.
- [ ] **3.7** `bash bin/update.sh` with a modified working tree refuses rather
  than discarding the change.
- [ ] **3.8** A real update: `bash bin/update.sh` pulls, installs dependencies,
  applies migrations and prints the status. Afterwards **Einstellungen →
  System** shows the new version for both the files and the database.
- [ ] **3.9** Put an older package over a newer database. The portal stays
  closed and says why, instead of guessing.
- [ ] **3.10** Switch **Wartungsmodus** on from **Einstellungen → System**.
  Everybody else sees the closed page; you still get in, with the red strip at
  the top offering the way out.

---

## Sign in, roles and access

- [ ] **4.1** Sign in as the trainer. **Verwaltung**, **Kurse**,
  **Anwesenheit**, **Beiträge**, **Rechnungen**, **Konten** and **Postausgang**
  are all in the menu.
- [ ] **4.2** As the trainer, **Einstellungen** and **Änderungen** are *not* in
  the menu, and typing their addresses by hand is refused.
- [ ] **4.3** As the administrator, everything the trainer can reach, you can
  reach too. There is no screen she has and you do not.
- [ ] **4.4** Sign in as a family. They see the overview, their own children,
  **Nachrichten** and **Neuigkeiten** — and no other child.
- [ ] **4.5** As a family, open another family's child by editing the address.
  Refused, in words, not with a blank page.
- [ ] **4.6** Wrong password repeatedly (more than ten times for one address,
  within fifteen minutes) is refused with „Zu viele Versuche", and a correct
  password immediately afterwards is refused too — that is the point.
- [ ] **4.7** „Passwort vergessen" sends a link; the link sets a new password
  once and not twice.
- [ ] **4.8** Suspending an account in **Konten** stops that person signing in.

---

## The shell: the bar, notifications, feedback, impersonation

- [ ] **5.1** Scroll a long page. The top bar stays where it is.
- [ ] **5.2** Your name and role appear **once**, in the top bar — not again at
  the bottom of the menu.
- [ ] **5.3** The bell shows a number when something is waiting. Opening it
  lists the notifications newest first; „Alle gelesen" clears the number.
- [ ] **5.4** A family sends a message. Both the trainer *and* the
  administrator get a notification — not only one of them.
- [ ] **5.5** Clicking a notification lands on the thing it is about.
- [ ] **5.6** At the bottom of any page: „Etwas funktioniert hier nicht".
  Send one with a screenshot attached.
- [ ] **5.7** **Einstellungen → Rückmeldungen** shows it with the page it came
  from, the browser, the address it was sent from and the portal version — none
  of which the sender had to know. The screenshot opens.
- [ ] **5.8** Marking a report as handled removes it from the count in the tab.
- [ ] **5.9** **Konten → „Portal als diese Person ansehen"** on a family. You
  see exactly what they see, with a bar across the top saying so.
- [ ] **5.10** „Ansicht beenden" gives you your own account back. (This is the
  check that once failed: the way out must work from inside a borrowed session.)
- [ ] **5.11** While impersonating, change something. **Änderungen** records
  *you* as the person who did it, not the person you were viewing as.
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

---

## Students, contacts, levels and age groups

- [ ] **7.1** Create a child with a name, a date of birth and a level. The level
  offered by default is **Anfänger** unless you changed which one is default.
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
- [ ] **7.7** A child with no contact is named on the **Schüler** list — "1 Kind
  ohne Standardkontakt" — with a link straight to their contacts.
- [ ] **7.8** The first contact you add becomes the **Standardkontakt** without
  being asked, and it cannot be saved without an email address: that is where
  invoices and reminders go.
- [ ] **7.9** Add a second contact. It is an ordinary one. Tick
  „Als Standardkontakt verwenden" on it and the badge moves — there is never
  more than one.
- [ ] **7.10** Try to remove the only contact a child has. Refused, in words.
  Remove the standard one when there are two, and the other one takes over.
- [ ] **7.11** A contact can be reached from the phone: the number is a link
  that dials.
- [ ] **7.12** Absences: add one with a reason and a date range; it shows on the
  child and in the attendance screen for those days.
- [ ] **7.13** Delete a child. **Änderungen** still says what the record held.

---

## Courses, dates and tariffs

- [ ] **8.1** Create a course with two training days in the same week — say
  Monday in one hall and Thursday in another. Both show, each with its own
  place and time.
- [ ] **8.2** A course's place is used for every date unless that date says
  otherwise.
- [ ] **8.3** **Kurse → ein Kurs → Tarife**: every price a course can be taken
  at lives here. There is no tariff floating free of a course.
- [ ] **8.4** A tariff has an interval: monthly, every two, three or six months,
  or yearly. Pick a non-monthly one and check the summary sentence under it
  describes what will actually be charged.
- [ ] **8.5** A tariff has a due day. A child can override it; the child's page
  says which of the two applies.
- [ ] **8.6** A tariff can carry a discount — a number of months (or unlimited)
  at a percentage or a fixed amount off. 100 % reads as free, not as €0,00
  hidden in a corner.
- [ ] **8.7** A child in two courses is billed for both, each at its own
  tariff.
- [ ] **8.8** Archive a course. It leaves the lists, keeps its history, and
  nobody can newly enrol in it.

---

## Enrolment, asked for and decided

- [ ] **9.1** As a family, open a child and look at **Kurse**. Courses with room
  in them are offered.
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
- [ ] **11.4** The same tariff set to „whole period" charges the whole amount,
  and set to „skip" charges nothing until the next period.
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
  family, not one per charge.

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
  tax mode. Issuing becomes possible.
- [ ] **13.3** On a child, **Rechnungen → Rechnung erstellen** from the charges
  that have not been invoiced. The charges are then no longer offered twice.
- [ ] **13.4** Download the PDF and open it in a real PDF reader — not only in
  the browser's preview. It is one page, the umlauts and the € sign are intact,
  and nothing overlaps.
- [ ] **13.5** The document carries everything § 11 Abs 1 UStG asks for: who
  issued it, who it is for, what was supplied, the period, the date of issue,
  a consecutive number, and either the tax amount or the exemption note.
- [ ] **13.6** As a Kleinunternehmer, the § 6 Abs 1 Z 27 note is on the
  document and no VAT is shown.
- [ ] **13.7** Switch to „mit Umsatzsteuer": the net, the rate, the tax and the
  gross are all shown, and net + tax equals the gross exactly.
- [ ] **13.8** Numbers run consecutively within the year, with no gaps and no
  repeats. Issue two invoices in quick succession and check.
- [ ] **13.9** „Per E-Mail schicken" queues the invoice to the family's standard
  contact. **Postausgang** shows it.
- [ ] **13.10** An invoice starts **offen**, becomes **überfällig** on its own
  past the payment date, and only becomes **bezahlt** when the trainer says so.
- [ ] **13.11** Marking it paid records a real payment against the charges
  behind it, so the child's outstanding amount changes too.
- [ ] **13.12** Cancelling an invoice keeps it, marked storniert, with its
  number — it is never deleted and never renumbered.
- [ ] **13.13** A family can download their own invoice, and nobody else's.
- [ ] **13.14** A child whose family has no portal account: the invoice is
  addressed to their standard contact, and can be emailed there.
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
- [ ] **14.8** A conversation with the trainer is readable by *every* member of
  staff — that is what it is for.
- [ ] **14.9** Unread markers clear when a conversation is opened, and the count
  in the menu agrees with the list.
- [ ] **14.10** **„An eine Gruppe schreiben"**: the bulk tool with filters,
  templates and a review step still works, and is on its own page — writing one
  message never goes through it.
- [ ] **14.11** An empty message is refused.

---

## News, email and the queue

- [ ] **15.1** Publish a news item. Families see it under **Neuigkeiten**.
- [ ] **15.2** With the newsletter ticked, an email is queued per subscriber.
- [ ] **15.3** **Einstellungen → SMTP → Testmail**: it either arrives or the
  error says what the server actually replied.
- [ ] **15.4** **Postausgang** shows queued, sent and failed. A failure retries
  with a growing delay rather than hammering.
- [ ] **15.5** An unsubscribe link at the bottom of a newsletter works without
  signing in, and only unsubscribes that one person.
- [ ] **15.6** Changing the SMTP password and saving does not print it back to
  the page.

---

## Email templates

- [ ] **16.1** **Verwaltung → E-Mail-Vorlagen**: create one. The list of values
  you may use, and what each one means, is on the same screen as the box you
  type into — not in a manual.
- [ ] **16.2** Use a placeholder, send to yourself, and check it was replaced.
- [ ] **16.3** A placeholder that does not exist is left visible rather than
  silently emptied, so the mistake is findable.

---

## Saved views and filters

- [ ] **17.1** **Schüler**: filter by level, age group, course and status. The
  page says in a sentence what you are currently looking at.
- [ ] **17.2** Save it as a view with a name. It appears as a chip above the
  list.
- [ ] **17.3** Open the view tomorrow: the same filter, the current children.
- [ ] **17.4** Delete a view. The children are untouched.

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
- [ ] **20.2** Editing the notice asks each person to acknowledge the new
  version once, and records that they did.
- [ ] **20.3** The notice is readable signed out.

---

## What may be customised, and what may not

- [ ] **21.5** **Einstellungen → Eigene Felder** says in words that custom
  fields are for students only, and why the rest — courses, tariffs, invoices,
  charges — has fixed fields.
- [ ] **21.6** The things that *are* hers to change — levels, age groups,
  membership statuses, payment methods, tariffs, email templates — are all under
  **Verwaltung** and need no administrator.

## Data safety

- [ ] **21.1** Interrupt a save (close the tab mid-request). Nothing half-written
  is left behind.
- [ ] **21.2** **Einstellungen → System**: take a backup before an update. The
  file exists and is not empty. Import it into an empty database in the hosting
  panel once, deliberately, so you know the route works before you need it.
- [ ] **21.7** After deleting an account or a child that had a picture, an
  attachment or a proof, the file goes too — the nightly maintenance sweeps
  anything no record points at. `storage/uploads` should not grow for ever.
- [ ] **21.3** An update that could not back up first refuses to run.
- [ ] **21.4** After any update, **Einstellungen → System** shows the same
  version for the files and for the database.

---

## On the phone, at the end

Do this last, on a real phone, not a resized desktop window.

- [ ] **22.1** Sign in, take attendance for one course, and record one payment,
  one-handed.
- [ ] **22.2** At 320 px wide: no sideways scrolling, no overlapping labels, no
  tap target smaller than a fingertip.
- [ ] **22.3** Add the portal to the home screen. It opens without browser
  chrome and with its own icon.
- [ ] **22.4** In dark mode, every screen you touched above is still readable.

---

## Cleaning up

- [ ] **23.1** **Einstellungen → System → „Beispieldaten entfernen"**. Every
  example child, account, course, charge, payment, message and file is gone, and
  the page says so.
- [ ] **23.2** Nothing you created by hand during the sweep is left behind.

---

## What none of this proves

Say what you ran, not what you hope is true.

- `php tests/run.php` runs against a **SQLite translation** of the schema. It
  proves the PHP logic. It does not prove the SQL dialect, and it prints what it
  could not cover at the end of every run.
- `tests/mariadb-local.sh` proves **MariaDB 10.11**. **MySQL 8.0 is still
  unverified** — it is one of the two supported engines, not both.
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
