# Changelog

## 0.6.0 — unreleased

### Before you upload this over a portal that has families in it

A portal installed fresh from this version can skip this. An existing one needs
one thing done before the upload — two, if anything in its custom fields is
worth keeping — and changes in several ways the first time it is opened;
[UPDATING.md](UPDATING.md#updating-an-existing-portal-to-060) has them in full.

**First, while the old version is still running, add three paragraphs to the
privacy notice** under **Einstellungen → Datenschutz**: one replacing the old
newsletter paragraph, because club news by email now starts switched on and is
no longer described as voluntary consent; one about the course groups, what a
message holds, and the administrators reading chats between a child and a
trainer; and one saying that everybody signs in with their own address and that
a login without one receives no e-mail. Replace its „6." too, with the draft's,
because the portal now deletes data once its period is over. UPDATING.md has the
first to copy and says where in the drafts the others are. The drafts shipped
with this version only fill in the notice of a new portal; an existing one keeps
the text it had. Where the legal basis goes there is a note in square brackets,
which is the operator's to decide and have checked, and a released notice is not
saved until the notes are replaced.

**Second, if anything typed into the custom fields is worth keeping, write it
down.** This version deletes the custom fields, with every value in them. After
the update, take them out of the privacy notice too.

Migrations 019 to 039 then run by themselves. 019 takes every child but the
first off a login they shared; nothing is deleted, and each change is written
under **Änderungen**. 020 gives every account a status and an empty list of
times online, which 034 and 035 take away again. 021 switches news by email on
for accounts created from now on; every existing account keeps its choice. 022
to 024 give usernames and take them away again; 028 brings them back, for
students only, and lets a login have no address; 039 takes them away for good.
025 to 027 give every course
its group chat, let staff take a group message down, and add the status emoji,
which 035 takes away again; the old shared conversations stay readable and
closed. 029 and 030 make a student's login impossible to delete: it is replaced
instead. 031 prepares keeping a child taken out of a course; nothing uses it
yet. 032 deletes the custom fields with their values, and 033 the saved views of
the **Schüler** list and the e-mail templates; the custom fields' values are no
longer among the tables counted before and after an update, so their going does
not refuse it. 034 to 037 delete the times online, each login's status, emoji
and picture, each child's picture, and every request one family made to write to
another. 038 takes away the pin that held a child in an age group: the band
now comes from the date of birth alone. 039 drops the username column: a test
login that signed in with a username — invited, in use or suspended — is „Ohne
Anmeldung" afterwards and needs an address and an invitation, and its side of
its chats, its read marks and its bell notices go, while the chats stay for the
other side. Nothing of this shows under „Änderungen", and a portal updated
from 0.4.0 has no such login.
After the files, the stored profile pictures are deleted, **for good**:
the copy the update takes first is of the database, and brings back their names
but not the pictures. Also after the files, every student without a login — the
children 019 took off a shared one among them — is given a placeholder that
nobody signs in with until staff enter its address and send the invitation.

**The portal now deletes data past its period**, in a daily cleanup. Its first
run comes within a day of the upload, or with the first page if nobody opened
the portal the day before, and deletes at once whatever is already older: chat
messages more than a year old, absences that ended more than three months ago,
and the rest „Each kind of data is kept for a set time" lists below. To choose
other periods before it runs, UPDATING.md says what to switch off first.

Invitations and „Passwort vergessen?“ links wait until **„Nur Verbindung
prüfen“** under **Einstellungen → SMTP** has passed once. Everybody is signed
out once, because sign-ins are now kept in the portal's own folder. A portal
whose address starts with `https://` is only served over https from now on. And
the version number printed under the privacy notice changes once although the
text does not; nobody is asked to acknowledge it again.

### What this version no longer has

What the portal carries has been cut to what the trainer and the families need
(ADR 0026). These are gone, and the ones that held data take it with them.

- **Custom fields**, with everything typed into them. **Einstellungen** has no
  „Eigene Felder für Schüler", and a child's page no „Weitere Angaben". Lines
  under **Änderungen** written before stay readable, the field named „Früheres
  eigenes Feld".
- **Copying**: „Kopieren" on a tariff, a level, an age group, a payment
  recipient or a news item, and „Kurs kopieren".
- **Saved views** of the **Schüler** list, which the update deletes. The two
  selections stay, „Überfällig" and „Krank" beside „Alle" above the list, and
  so do the filters, except „Tarif und eigene Felder".
- **Writing to many at once** — „An mehrere schreiben", „Auswahl anschreiben"
  under the **Schüler** filter and „Zahlungserinnerung schreiben" on **Geld** —
  with its **e-mail templates** under **Verwaltung**, which the update deletes.
  A course's group, the news, „Alle überfälligen per E-Mail erinnern" on
  **Geld** and the chat with one person are there for what it was used for.
- **„Warteschlange senden"** on **Postausgang**. Mail goes out by itself just
  after a page has been served, at most once a minute, or by the cron job where
  one is set up.
- **The printed form and data sheet**: „Leeres Formular drucken" in the wizard
  and „Datenblatt drucken" on a child's page. The wizard and a family's own
  set-up from their invitation take the place of paper.
- **Verwaltung's „Tarife" tab**, which only pointed to the courses. A course's
  tariffs are on its own „Tarife" tab, which also names any tariff that belongs
  to no course.
- **The online dots, the chosen status and when somebody was online**: the dot
  on everybody's picture, „Automatisch", „Abwesend" and „Als offline anzeigen",
  „Wann online? Letzte 30 Tage" under **Konten** and on a child's page, and
  their four settings. The update deletes the times it had kept, and the
  privacy notice no longer has to mention them.
- **The status emoji.**
- **Profile pictures**, of the team and of the children. Everybody appears by
  their initials. Once the update has passed, the stored pictures are deleted
  from the server; the copy of the database taken before the update holds only
  their names, so they cannot be brought back.
- **Asking to write to another family**, with every request asked or answered,
  which the update deletes. A chat between two children that such a request led
  to stays, for the two of them to read, and takes no new messages.
- **Voice notes and files in new messages.** A message is text and photos.
  Voice notes and files sent before stay, and open as they did.

### The portal looks and behaves like an iPhone app

The owner asked for it to be "as intuitive and easy to use as a modern ios app".
This is the first phase of the design language in
`docs/design/2026-10-07-ios-design-language-and-goal-screens.md`.

- **A family's bar at the bottom reads Übersicht · Beiträge · Chats · Profil.**
  „Neues" is gone from it: the news is on the overview. „Konto" is gone too:
  **Mein Konto** — password, e-mail address, language, colour and the privacy
  notice — is the row „Anmeldung und Darstellung" on **Profil**.
- **Staff's bar reads Übersicht · Schüler · Anwesend · Chats · Mehr.** „Post" is
  „Chats" now. The numbers on the bar and on the bell are red with a white
  figure, in light and in dark, where they were in the portal's colour.
- **„Mehr" is a page**, no longer a menu sliding in from the side (ADR 0028):
  Kurse, Geld and Einstellungen — Verwaltung for a trainer, and „Einrichtung"
  above them while the checklist is unfinished — each with its number when
  something waits, then
  Mein Konto, „Datenschutz und Hilfe" and „Abmelden". On a computer the menu on
  the left is unchanged.
- **The language is set under Mein Konto**, „Sprache", once signed in; the EN/DE
  switch is no longer in the bar at the top. The sign-in pages keep it.
- **The phone's own font**, and the reader's own text size: the page no longer
  fixes its text at 16 pixels. „Schriftgröße" under Mein Konto makes the text an
  eighth, a quarter or a half larger.
- **White grouped lists on a grey ground**, without borders or shadows; buttons
  as capsules. Every tap shows: a row darkens, a button fades. While a form is
  sending, its button shows a spinner and a second tap does nothing.
- **On/off settings are switches**, such as the three e-mail settings under
  Mein Konto. Two or three choices side by side, such as „Beiträge ·
  Rechnungen", are one segmented control. Deleting something opens as a sheet
  from the bottom of the screen with „Abbrechen" under it. „Schüler anlegen"
  shows how many steps it has.
- **A back button with the name of the page above**, such as „‹ Schüler" on a
  child's page, and — where the browser supports it — the page's title in the
  bar once its large title has scrolled away. In the app on the home screen
  there was no way back at all before. Everything opened from „Mehr" — Kurse,
  Geld, Rechnungen, Einstellungen, a trainer's Verwaltung and staff's Mein
  Konto — shows „‹ Mehr", and „Mehr" stays lit in the bar.
- **Pages fade into each other** where the browser can; nothing moves for
  anybody who has asked their phone for less motion.
- **The bar at the very top of the browser** takes the page's background — the
  light grey, or the club's „Hintergrund" — instead of the menu colour.
- **No white flash in dark mode.** Every page now says in its first lines
  whether it is drawn light or dark — as chosen under Mein Konto, „Immer hell"
  or „Immer dunkel", and otherwise as the phone is set — so the browser can
  paint it dark from the first moment instead of white until the colours
  arrive. The installer and the page saying the portal is not available do the
  same.
- **A page that is slow to come gets a waiting page.** The row, button or tab
  tapped keeps its pressed look, and if the next page has not come after half a
  second, a waiting page fades in over the whole screen, on the page's own
  background, light or dark: the portal's name under a net, with a shuttlecock
  rallying over it. The next page carries it on from the same point and fades
  it out, so a slow change of page is one movement, not a cut. Once shown it
  stays at least half a second, so it never blinks; a page that comes just after
  half a second therefore appears about half a second later than it would have.
  After 6 s it says „Dauert länger als sonst." and offers „Abbrechen", which
  stops the page that has not come — in the app on the home screen nothing else
  can. A form that sends shows its button's spinner instead, never both. With
  less motion asked for, the shuttlecock rests and only pulses; a screen reader
  hears „Wird geladen …" once, and the longer wait when it is said, with the
  focus on „Abbrechen". Without JavaScript nothing changes.
- **The portal's own mark is a shuttlecock**, where the club has set no logo
  or icon of its own: top left, in the installer, in the browser tab and on
  the home screen. An iPhone's home-screen icon is square now, without the
  see-through corners an iPhone showed black, and Android gets an icon of its
  own for its launcher's shape, with the whole shuttlecock inside it. An
  iPhone keeps an icon already on its home screen until the portal is removed
  and added again.
- The portal's built-in teal is a shade darker, so that it reads on the grey
  ground. Every text is at least 4.5:1 against what is behind it in both
  themes, as frontend-dev measured it in Chromium.
- Without JavaScript the folds open in place, as before, and everything works.

Not in this phase: a child's page and a course's page as lists to tap through,
and the screens for the owner's goals. **Seen in Chromium only**, at 320 and 390
pixels, as each role, light and dark; not yet on a real iPhone, which TESTING.md
I.1–I.12 and W.1–W.3 walk, with W.4 on an Android phone. Chromium cannot show
whether Safari still flashes white.

### A slow or silent mail server no longer holds up the next page

- **The next page no longer waits for the mail.** The background work after a
  page, the queued mail among it, now lets go of the visitor's session before it
  begins, so the same phone's next page and its stylesheet no longer wait behind
  a mail server that is slow to answer.
- **A mail server that takes the connection and then says nothing costs a send
  fifteen seconds, not five minutes:** a send waits at most 15 seconds for each
  answer. One that runs out counts as a failed attempt and is tried again later,
  as a refused one is — except an invitation or a password link, which stays in
  **Postausgang** as failed for staff to send again, because its link may have
  lapsed by then.

### Each kind of data is kept for a set time, then deleted

- **The daily cleanup deletes what is past its period** (ADR 0032, the periods
  the owner accepted): chat messages with their photos, voice notes and files
  12 months after they were sent, and one taken down in a group 30 days after
  it was taken down; absences, sickness included, 3 months after they ended;
  attendance 24 months after the training; the bell's notices after 90 days;
  the outbox's record of a mail 12 months after it was queued; payment proofs
  24 months after the upload; the audit log after 36 months; and a consent a
  newer answer replaced, 36 months after that — the answer in force stays as
  long as its login. Their files go in the same cleanup, except one uploaded
  less than an hour before it, which waits for the next.
- **Each period is a setting**, under **Einstellungen → System**, in
  „Erweitert", with a line under it saying what it keeps or counts from. A 0, or
  a number past a setting's limit, is refused. A period set shorter deletes
  nothing at once: the cleanup after a page view waits a day after the save, and
  the save names what the period was — „Kürzer gestellt, vorher: …" — so it can
  be set back meanwhile. Where a cron job runs the console's `maintenance`, its
  next nightly run deletes. The change log's older period, „Änderungen
  aufbewahren (Monate)", now has the same line and the same wait: „Änderungen"
  has no undo, so this is its way back.
- **Charges, payments and invoices are never deleted by it**: the law asks for
  seven years from the end of the year they concern, and longer while a
  proceeding needs them (§ 132 BAO).
- **„Nachricht entfernt" says for how long it can be put back**: „30 Tage lang
  kannst du sie an derselben Stelle wiederherstellen."
- What it deletes from the database stays in the copies in `storage/backups`
  until five newer ones have replaced them; the photos and files are gone. The
  cleanup never runs while an update could be counting the records — inside an
  update, before files newer than the database have been brought up to date — or
  while a copy is imported; it leaves its work for the next run. Run from the
  console, `php bin/console.php maintenance` says what it deleted, or that it
  deleted nothing and why. Where other entries here say that something stays —
  old chats, voice notes and files, or whom a sent mail went to — it stays until
  its period is over.

### What the security review of 8 October found

- **A family's login is called what the child is.** Mein Konto has no box for a
  family's name any more, and renaming a child renames its login, recorded under
  **Änderungen**: a child could call itself „Trainerin Anna" and write in a
  course's group under that name. A name a family gave itself before stays until
  the child's record is next saved. Staff choose their own name as before. In a
  course's group, and in the list on **Nachrichten** where it names who wrote
  last there, a staff member's name has a small grey pill beside it, „Trainerin"
  or „Administrator", which no name typed into a box can make; a child's never
  has one, whatever the child is called.
- **A chat sends one e-mail until it is read.** Forty messages were forty mails;
  now a message is mailed about only when everything before it was read. The
  bell still lists each one.
- **Twenty chat photos an hour from a family's login**, as for receipts, so
  that one login cannot fill the server's disk; staff are not counted. A chat
  photo is stored without the name the phone gave it — „IMG_2041.jpg", or a
  family's own words — and downloads under its stored name.
- **Every administrator hears of a change to where the families' money goes**:
  a recipient's IBAN, the name on the account or the QR code's contents, a new
  recipient, a course paying into another recipient, and another
  „Standard-Zahlungsempfänger", each with who made it and a link to
  **Änderungen**, the course or **Geld & Zahlungen**. Trainers still change the
  IBAN (ADR 0025, amended).
- **The QR code pays the recipient's own account, by SEPA transfer.** „Inhalt
  des QR-Codes" must keep „BCD" on its first line, `{recipient}` on its sixth
  and `{iban}` on its seventh; anything else is refused when saved, and a
  template saved before that is not one draws no code, while the recipient's
  form says why under its preview: „Dieser Inhalt ergibt keinen QR-Code: …".
  The box's own hint states the rule. A line break in the recipient's name can
  no longer move the IBAN.
- **A changed sign-in address or password is told by mail**: the old address
  hears of the new one, shown only in part, and the login's address of a new
  password, so a change made by somebody else does not go unseen. Neither mail
  has a link, the queue does not try it again, and its text is not kept.
- **What a sent mail said goes from Postausgang after 90 days**; who it went to,
  its subject and when stay. A mail that could not be sent keeps its text, so
  that it can be sent again. A security mail's text goes as it is sent, as
  before.
- **The closed page names no folder and no database error** when no copy could
  be taken before an update. Both could name the hosting account; the hosting's
  error log has the reason.
- **The setup page is sent with the portal's security headers**: no framing, no
  scripts or styles from elsewhere, no referrer. It answers before there is a
  configuration and so had none of them.

### An update that lost records stays closed until they are back

- **Before, the portal closed for one page view and then opened as if nothing
  had happened.** An update whose migration lost rows from a guarded table was
  refused only for the page view that ran it; the next one found nothing left to
  do, counted after the loss and served the portal with the rows missing.
- **Now the numbers from before an update are kept** in
  `storage/update-unfinished.json` from its first migration until a run passes.
  Every page view counts again first, and while a guarded table has fewer rows
  than before, nothing more runs — not a newer upload, not a retry — and every
  page answers the closed page (ADR 0027).
- **The closed page speaks to both.** A family reads „Du musst nichts tun.
  Bitte versuche es später noch einmal." Whoever looks after the portal reads
  which table lost what, the copy to import, and the order: first the files of
  the version before, then that copy, as INSTALL.md's „Wiederherstellen" now
  says. The portal counts again and opens by itself once nothing is missing.
  Keep the ZIP of the version you are running until the next update has opened
  the portal.
- **While it is closed nobody gets in**, administrators included, maintenance
  mode or not, and no mail, charge or cleanup runs: anything added meanwhile
  could hide what is missing. The console runs only `check`, `status`,
  `migrate`, `update`, `maintenance:on` and `maintenance:off`, and says why for
  anything else.
- **One copy per update.** No further copy is written while an update is
  unfinished, so the copy from before is never pruned away. A `storage` folder
  that cannot hold the numbers from before refuses the update before any copy
  is taken, so a page view that is refused writes none either.
- `php bin/console.php check` names a missing guarded table in one sentence
  that points to UPDATING.md, and ends with exit code 1.
- A `skip-backup` file the portal cannot delete now refuses the update, instead
  of skipping the copy before every later one too. A migration that keeps
  failing reports how far the update got, not where the last retry stopped.
- UPDATING.md's new „A refused update" says what to do, including the last way
  out and what it costs.

### A restore keeps the uploads, and a file downloads as what it is

- **Restoring a backup no longer costs the uploads.** INSTALL.md restores a
  copy by deleting every table, then importing it. Before this fix, a page
  opened in between, once the files of another version had been uploaded, made
  the tables afresh and ran the update, and the update's sweep for files no row
  names found no row at all: it deleted every receipt, chat photo,
  problem-report screenshot, icon and logo older than ten minutes. A copy holds
  the rows, never the files. The sweep, after an update and at night, now does
  nothing while the database has no login in it — and, since the section
  below, not while a copy is being imported either.
- **A chat photo or a receipt downloads as what it is.** The name the browser
  is given ends in the type the portal read from the file's bytes when it
  stored it: a photo sent as `x.apk` downloads as `x.jpg`, a receipt sent as
  `beleg.exe` as `beleg.pdf`. The receipt's link still reads the name it was
  sent with.
- **A child who sends anything but a JPEG**, which is what a phone's camera
  hands over, reads „Bitte nimm das Foto mit der Kamera auf." instead of a list
  of file types. Staff are still told which types are possible.
- Smaller: the sweep finds its folders when the path to `storage/` holds a `[`,
  `*` or `?`, which it used to read as a pattern, missing the folder's own files
  and sweeping another folder's that matched. The icon and the logo are checked
  by the same rule as every other stored file, and accept the same files as
  before. What the account menu holds and the number of queries the page
  listing the team makes lost their tests when the online status went, and have
  them again; the `Permissions-Policy` header has one for the first time.

### A restore keeps the portal closed until its import is done

- **Every copy the portal writes now says when its import is done.** It begins
  by making a table `import_unfinished`, with a comment phpMyAdmin shows, and
  drops it with its last statement. While that table exists the portal is
  closed to everybody and nothing is run, copied or swept — not by a page view,
  not by the background work, not by the console — so a restore cannot be
  caught halfway by an update, a copy of a half-imported database, or a sweep
  that finds no rows naming the uploads (ADR 0029). Before, a page opened
  while the tables were gone could make them afresh, run the update on nothing,
  and write a copy of the empty database that pushed an older, fuller copy
  out.
- **The portal reopens by itself** once the whole copy is in: the closed page
  reloads itself every five minutes, and any page view counts and opens. An
  import that stopped halfway leaves the portal closed and the page saying to
  import the same file again; each table in a copy is dropped before it is
  made, so nothing is doubled.
- **The closed page says what to do in phpMyAdmin's words**, one of three
  sentences, in German and English: a copy is being imported or stopped; the
  database has data but no list of migrations (a copy from before this
  release, or one from the panel, on its way in — or `config/config.php`
  naming a database that is not the portal's); the database is empty but the
  folder belongs to a portal (a restore between deleting the tables and
  importing, or setup pointed at a new database over an old `storage/`). The
  last one also says how a new, empty portal starts there: delete
  `storage/schema.stamp`. INSTALL.md's table has the three.
- **A copy from before this release, or one exported in the hosting panel,
  has no marker** and is judged by its ledger: while `schema_migrations` is
  missing and tables have rows, the portal stays closed; once the ledger is in,
  the database counts as the portal's own, whatever the copy still has to
  bring. Restore such a copy with the files of its own version and with
  maintenance mode on until the import has finished; the sweep's rule that
  nothing goes while there is no login still holds then.
- **No copy is written of a database that holds nothing**, by an update or by
  `php bin/console.php backup`, which refuses it: it would have nothing to
  restore and would push out one that has. The background work — mail, charges,
  the daily cleanup — stays out by itself while a copy is being imported,
  with or without maintenance mode.
- **`php bin/console.php maintenance` stops with exit 1 while
  `storage/maintenance.flag` exists**, as `mail:work` does, so a cron job set up
  for it fails each run during a restore and sweeps nothing. INSTALL.md's
  „Wiederherstellen" now starts with maintenance mode on and ends with it off.
  Setup shows a refusal's own sentence, in the page's language, instead of its
  log line.

### Age groups come from the birth date alone, and the example data is one course

- **Levels and age groups stay**, the owner's later answer: „Skill levels were
  good to have / age levels will also be needed, but it would be enough if the
  app can dynamically output in which age group one falls in". A level is still
  the trainer's choice for a child; the bands are still edited under
  **Verwaltung → Altersgruppen**.
- **Nobody pins a child to a band any more.** „Altersgruppe festlegen" is gone
  from the child's page, and migration 038 drops what it stored. A child's band
  is the first one, in Verwaltung's order, that covers their age today; a child
  without a date of birth has none, and one no band covers reads „Keine
  passende Gruppe". A child who was pinned shows the band their age gives after
  the update; a pinned child without a date of birth shows none.
- **One rule, everywhere.** The child's page, the row in the students list, the
  list's headers, its filter and Verwaltung's counts all ask the same rule, so
  they agree even where two bands overlap: before, the filter worked the band
  out again from the band's ages, in SQL, and could list a child under one band
  while their page named another. An archived band places nobody: its children
  fall to the next band that covers them, or to „Ohne Altersgruppe".
- **The students list is built for a phone.** A search box of its own at the
  top, which keeps the rest of the selection; „Alle | Überfällig | Krank", one
  tap each, starting afresh; the other filters — Kurs, Mitgliedschaft,
  Leistungsgruppe and Altersgruppe, with „Ohne Altersgruppe" — folded under one
  row, „Filter", that names what is chosen; and „A–Z | Nach Alter". Every
  control is a link or a plain form, so the list works without JavaScript and
  its address says what is shown. A row reads the age group and the level,
  „Unter 12 · Anfänger", and wears „Ohne Kurs" in amber when the child is in no
  running course. The price left the rows, here and in the overview's
  „Schüler", and stays on the child's page and on **Geld**. The fold no longer
  asks for an absence reason or „Nur überfällige Beiträge": „Krank" and
  „Überfällig" are those selections. The heading of **Schüler** has one button,
  „+ Schüler anlegen"; „Per E-Mail einladen" is reached from the wizard's
  first step, „Nur die E-Mail-Adresse bekannt? Ohne Namen einladen".
- **„Nach Alter"** (`sort=age` in the address) is a card per age group in
  Verwaltung's order, its name with the span under it — „bis 11", „12 bis 17",
  „18 und älter" — and the count beside it: each child once, under the first
  group that covers their age, the youngest first, a row reading the age,
  „9 Jahre · Anfänger". Then „Ohne Altersgruppe" for the children no group
  covers, with „Keine deiner Altersgruppen passt." and the way to the groups,
  then „Ohne Geburtsdatum". A group with nobody in it draws no card; with no
  group at all the list says „Es gibt noch keine Altersgruppen." and offers to
  make them. A chosen group says whom it leaves out, „1 Kind ohne Geburtsdatum
  ist nicht dabei.", with „Zeigen" to them. On a list longer than a page,
  „Weiter" keeps the search, the filters and the order, and a group that runs
  on keeps the count of the whole group.
- **The example data is as little as shows each screen** (ADR 0026 §9): one
  course, „Kindertraining", four children — Lena (9) and Jonas (10), whose
  families have the two example logins, Mia without a date of birth, Elias (13)
  — a paid, an open, an overdue and an unconfirmed charge, a request to join,
  a sick note, two days of attendance, a news item and a chat. Nothing in it is
  drawn at random but the password, so it reads the same on any day. Before it
  was three courses and fifteen children aged 7 to 41, with random dates.
- **The example password is eight syllables**, four made-up words each starting
  with a capital, such as `KemoTapiRunaSofe`, typed on a phone's letters alone;
  the two words and four digits it replaces were far fewer passwords. **The
  example logins work for 14 days**, and are refused at sign-in afterwards with
  the sentence a wrong password gets: the trainer's example address is
  published, and a login forgotten on a portal on the internet should not stay
  a way in. The finish page and `demo:fill` say so.

### Any value from anyone is answered in one sentence

- **A new test suite, `robustness`, sends every form nonsense as every kind of
  person** — lists where a word belongs, 100,000 characters, broken characters,
  huge and negative numbers, impossible dates, another family's ids, a form sent
  twice — and fails on any error page, PHP warning or write to somebody else's
  rows. What it found is fixed where the value enters, in the one place every
  form reads it from.
- **What you may notice:** a number outside its range is refused with a
  sentence naming the range — places 0 to 500, a payment day 1 to 28, a payment
  term 0 to 180 days, a discount as a whole per cent, a list position, the SMTP
  port. Dates are held to 1900 to 2100 everywhere, the month for charges too. A
  huge page number in an address shows an empty or the last page, not an error.
  Text that is not valid UTF-8 is refused, and a refusal quotes at most sixty
  characters of what was sent.
- **A family can no longer ask to write to another family.** „Neue Nachricht"
  lists the trainer team only (ADR 0022 §11). A request sent before can still
  be answered.
- „Änderungen" names lines about something that has gone as what it was —
  „Frühere E-Mail-Vorlage", „Früheres eigenes Feld" — rather than by a table's
  name.
- A trainer told that mail is not set up yet is told that an administrator does
  it, rather than sent to a settings page she cannot open.
- A form sent after signing out lands on the sign-in page without a line in the
  server's error log, and `setup.php` reads its language safely.

### Signing in, and adding a student

- **Every student has a login from the moment they exist** (ADR 0023). Until
  the child's own address is entered on their page and the invitation sent, it
  is a placeholder nobody signs in with, shown as „Ohne Anmeldung" on the
  child's card „Zugang zum Portal"; the invitation turns it into a login.
- **Everybody signs in with their own e-mail address** — one person, one
  address, one login (ADR 0030): a parent with two children has two logins
  with two addresses, and the parent's own address goes on the children's
  emergency contacts. Usernames and one-time sign-in links, added while this
  version was being built and never released, are gone again; the invitation
  and „Passwort vergessen" are the two ways in.
- **„Schüler anlegen" is a wizard in two steps**: who is joining and into which
  course, then how they sign in — an invitation by e-mail, or no sign-in for
  now. Nothing is written before the second step, and a course that filled up
  or was archived in between is refused with everything typed still there. The
  done page says where the invitation went and until when its link works, or,
  without sign-in, leads to the child's card with „Anmeldung einrichten".
- **„Anmeldung löschen" gives the child a fresh, empty login** instead of
  leaving them without one. The child's private chats go with the old login;
  the record, the courses, charges and invoices stay. On a child whose record
  still points to a team member's login, from before one login was one
  student, it gives the child a fresh login and leaves the team member's login,
  her chats and her role as they are.
- **A login nobody signs in to collects no notices.** A child „Ohne Anmeldung"
  is sent nothing in the portal — no new invoice, no moved or cancelled training
  date, no decided request — so the invitation that later gives them a login
  does not hand the family a list of old news.
- **A browser several people use forgets what somebody left half done.**
  Signing in, or a session running out, drops an invitation somebody opened
  and did not finish, the wizard's half-typed children and any view of
  somebody else. An invitation opened in a browser whose signed-in session
  then runs out is refused once, „Dieser Link ist ungültig oder abgelaufen",
  and works when it is opened again.
- **„Per E-Mail einladen"**, from the wizard's first step („Nur die
  E-Mail-Adresse bekannt? Ohne Namen einladen"): type an address and a
  language. The person fills in their name and birth date, sets a password and
  lands on their own page with „Kurs wählen" first; choosing a course is a
  request the trainer answers. Staff are told in the bell when somebody new has
  set themselves up. Open invitations are listed on **Schüler**, to send again
  or withdraw without typing anything, and on **Zugänge** under „Einladungen
  ohne Namen". Nobody sets another person's password.
- A birth date in the future or more than a hundred years ago is refused,
  wherever it is typed.

### Zugänge, the access card and Mein Konto

- **„Konten" is „Zugänge"**, and lists everybody who can sign in, in three
  groups with their counts: Trainer, Administratoren and Schüler. An
  administrator taps a team member's row open for what she can do with it; her
  own row, and every row for a trainer, is a row to read. A student's row shows
  the address, where the login stands and, for an invitation, until when its
  link works, and leads to the child's card „Zugang zum Portal": nothing on the
  page acts on a student, as a line under the list says. Chips „Alle ·
  Eingeladen · Ohne Anmeldung · Gesperrt" filter the students, each with its
  count, a chip nobody is in left out, without JavaScript. „Einladungen ohne
  Namen" and „Zugänge ohne Schüler" are groups of their own.
- **The card „Zugang zum Portal" invites in one tap.** For a child without
  sign-in it is one form: the address from the record, which can be changed
  there, the invitation's language and „Einladung senden". An address that is
  somebody's login, or that a brother's or sister's record also carries, is
  said before anybody taps, with no form; while mail cannot go out, the card
  says what is missing instead. „Einladung senden" brings the page back to the
  card, which then says „Eingeladen", and a refusal of any of the card's
  buttons comes back to the card and is said there as well as at the top. The
  others — „Einladung erneut senden", „Einladung zurückziehen", „Link zum
  Zurücksetzen senden", „Zugang sperren", „Zugang entsperren" and „Anmeldung
  löschen" — land at the top of the child's page, where the message says what
  happened, such as how long a reset link works. Suspending and restoring a
  login say it in the button's own words, „Zugang gesperrt." and „Zugang
  entsperrt.", and deleting a team member's login on **Zugänge** says „Zugang
  gelöscht."; all three said „Konto aktualisiert." before.
- **Mein Konto** shows the address you sign in with, each time a mailed link set
  the password in the last 14 days, „E-Mail-Adresse ändern", and the three mail
  switches, for everybody. The one for notices is called after what it sends,
  „E-Mail bei neuen Nachrichten und Änderungen im Training", and the switch on
  the invitation page names the same.
- **The address box** on the sign-in page and on „Passwort vergessen" is an
  e-mail box: a phone shows the keyboard with „@", and the browser checks that
  what was typed is an address before it is sent.
- **How long a link lasts is said from one place**, the same the links are made
  with, so no page can promise a link longer than it lasts: an invitation 48
  hours, a password link one hour — on the link's page, on the child's card and
  with the open invitations.
- **The strip saying whose portal somebody is viewing** is on the public pages
  too, the privacy notice among them, with „Ansicht beenden".

### A double tap does nothing twice, and viewing changes nothing

- **A form sent twice lands where the first one went.** A double tap on a slow
  phone, or the same form sent again after Back, shows the page and the message
  the first one led to, and nothing is done a second time. It used to replace
  the first one's „gespeichert" with a red „Diese Eingabe wurde bereits
  verarbeitet.", so it looked as if it had failed, and she did it again. In the
  wizard, Back from the page that says the child is added shows that page
  again, headed „{Name} ist schon angelegt", not the form. A form sent
  again after ten newer ones, or from another browser, is still refused with
  „bereits verarbeitet".
- **Viewing the portal as somebody else is for looking only.** Everything but
  „Ansicht beenden" and „Abmelden" is refused, with one sentence: „Beim Ansehen
  als jemand anderer lässt sich nichts schreiben oder ändern. Beende zuerst die
  Ansicht." Before, some things went through in the name of the person being
  viewed — a consent changed under **Mein Konto**, a problem report — and an
  administrator viewing a trainer could start a view of her own from there and
  be left signed in as the trainer.
- **A view ends with its viewer's login.** If the viewer's own login is
  deleted, suspended, demoted or given a new password while she is viewing,
  the view ends and that browser is signed out. Before, a trainer whose login
  was deleted during a view kept the child's session, with no bar and nothing
  held back.
- **The last place in a course goes to one child.** Two people taking it at the
  same moment — in the wizard, with „Schüler hinzufügen" on the course, or by
  accepting a request — could both get it. Now the second is told the course
  is full.
- A value in the address that is a list instead of a word, such as
  `?tab[]=x`, is dropped before any page reads it, so a mangled link no longer
  writes PHP warnings into the server's log.

### The chat works like a messenger

- **A group for every course.** Its children are whoever is enrolled now — a
  child who joins can read what came before, one who leaves loses it — and the
  trainers and administrators are in every group. A course has its group from
  the moment it exists. Group messages
  send no e-mail. Staff can take a message down from „⋯" and put it back from
  the same place.
- **Chats with one person.** A child writes to a trainer or an administrator by
  name, and staff to any child. A family writes to the coaching team only and
  can no longer ask to write to another family. A chat between two children
  from before stays to read, says that chats between children have closed, and
  takes no new messages.
- **The administrators can read every chat** — a child's with a trainer, one
  between two trainers, and the chats between two children from before — and
  write only in their own: where the writing box would be, an administrator
  reading along reads „Du liest hier mit. Schreiben können nur die beiden."
  Every open chat between two people says „Hier schreibt ihr zu zweit. Die
  Administratoren des Vereins können mitlesen." at the top, and no chat says
  it is private any more. A second trainer reads what she did before: her own
  chats and the groups (ADR 0022 §11.1).
- **Nothing records that an administrator read a chat**: no read mark, so the
  two in it see it as unread as before, no line under „Änderungen" or in the
  audit log, and no notice (ADR 0022 §11.2). What the hosting provider's web
  server writes into its access log is outside the portal, and the privacy
  drafts say so.
- **„Alle Einzelchats"**, which was „Alle Direktchats", lists for an
  administrator every chat between two people that she is not in, under
  „Schüler und Team", „Im Team" and „Zwischen Schülern (geschlossen)", with
  both names on each row and nothing counted as unread. Her own list and her
  badge are as they were.
- A chat between two members of staff closes once one of them has become a
  student, so no chat stays open between an adult and a child. A family told
  they cannot write to somebody is pointed to the coaching team and their
  course group; staff are told only that it cannot be done.
- **A message is text and photos.** A child's „+" asks the phone to open its
  camera and takes only a JPEG, which keeps out screenshots, animations and
  documents; staff can also send a PNG or a WebP from the phone's photos. No
  GIF, for anybody. There is no microphone and no other file any more; voice
  notes and files sent before stay in their chats and open as they did, and
  the portal tells every browser it never uses the microphone.
- **A photo keeps only the picture**, because a picture in a group reaches
  every child in the course. Where it was taken, its camera and its time are
  removed before it is stored, and so is whatever a phone puts after the
  picture: a second photo, or a motion photo's short video, which can carry a
  location of its own. A small preview that an editor can keep inside the file
  goes too, because after cropping it can still show the whole photo from
  before. It stays the right way up. An HDR photo shows at normal
  brightness, because what makes it brighter is one of those second pictures. A
  picture whose file the portal cannot make sense of is stored as it came, and
  whether to refuse that instead is still hers to decide.
- In a group's „Wer ist in der Gruppe?", a child sees the classmates who read
  it; one whose family has no login yet is only counted. Staff see everybody.
- While a trainer or an administrator views the portal as somebody else, she
  sees only the chats she may read herself, and the bell leaves out that
  person's chat notices, because each one quotes the message. It shows only the
  kinds known to quote nothing private — payments, dates and requests, and
  problem reports when an administrator is looking — so a kind of notice added
  later stays out of it until somebody has decided otherwise. Nothing can be
  written, asked for, taken down or marked read in that view, „Alle gelesen"
  included, so their unread messages and notices stay unread: the view changes
  nothing at all (below). „Neue Nachricht" says so, and lists nobody.
- On a phone the writing box sits above the menu bar instead of under it, and a
  chat opens at its newest message. The help button moves off a chat exactly
  where the chat has a writing box, and stays put on one that can only be read.

### Billing charges what is owed, once, and the invoices page counts everything

- **A cancelled charge can be made again.** It kept its place in the month, so
  after a correction the run said „Nichts zu tun". Cancelling now frees it, and
  charges cancelled earlier are freed on the next run.
- **„Als bezahlt eintragen" no longer pays a charge twice.** It counted only
  confirmed payments while recording a payment counted unconfirmed ones too.
  There is one rule now, and marking paid confirms a waiting payment instead of
  adding a second one.
- **A child on an archived tariff keeps it**, marked „(archiviert)" in the form,
  and billing goes on charging them. Nobody is newly put on an archived tariff.
- **A membership ending part-way through a period** („Mitgliedschaft bis") is
  charged to the day it ends.
- **The age-group filter** missed a whole year at its lower bound, so filtering
  the students by an age group missed those children. It now agrees with a
  child's age on every day, birthdays and 29 February included.
- **Background work no longer borrows the visitor's language and name.** Charges
  were written „Beitrag October" after an English-speaking family's page view,
  and the log named that family. They are now written in the portal's language,
  by nobody, in one transaction; an invoice PDF is in its family's language.
- **A charge on an invoice that stands cannot be cancelled**; it names the
  invoice instead, and the link leads there.
- **An invoice e-mailed to a family who switched payment e-mails off** used to
  be dropped without a word. It is refused up front, with the reason in place of
  the button, and the date is set only when the mail is queued. The same for a
  child whose login is not set up or has no address.
- **The invoices page counts and totals every invoice**, not the newest 200,
  fifty to a page with „Seite x von y"; a part-paid invoice counts only what is
  left.
- **Coming back to a course starts a new agreement.** Re-joining reused the old
  price and discount, giving „Erster Monat gratis" twice.
- Leaving before joining is refused, a reminder run no longer counts a paid
  charge as skipped, and a charge inside its grace days is no longer red.
- **„Zahlungsziel für Monatsbeiträge" is gone**: it was shown and never read.
  When a charge is due comes from the tariff.
- Refusals that were English only are German and English now, and the mail page
  no longer tells her to run a command she cannot run.

### A trainer's change to the IBAN is kept

- **Every change to a payment recipient is in „Änderungen"**: who made it,
  when, and each field from its old value to its new one — Empfänger, IBAN,
  BIC, Währung, Inhalt des QR-Codes, Notiz and Archiviert, named as the form
  names them. A new recipient leaves a line with what it was made with. Before,
  saving a recipient left only an audit line saying that it was saved: who had
  changed the account was there, what it had been was nowhere (ADR 0025).
- Nothing else changes. Trainers may still edit recipients, an invoice issued
  before keeps the IBAN it was issued with, and an open charge's QR code shows
  the new one.

### What the security review of the beta found

- **An unsubscribe link works for 90 days from the mail it came in.** Before,
  it worked for ever, so a link that had travelled on — in a forwarded mail, a
  screenshot — could switch somebody's mail off years later. **Links in mail
  sent before this update stop working the moment the new files are opened**,
  the owner's own test mails included; anybody still getting such mail has a
  working link in the next one. A link past its day shows „Dieser Abmeldelink
  gilt nicht mehr. Melde dich an und schalte die E-Mails unter „Mein Konto“
  ab." and no button, and switches nothing.
- **The unsubscribe page says what it stops.** A payment reminder's link
  reads „Keine E-Mails mehr zu Beiträgen erhalten: keine Erinnerung an offene
  Beiträge und keine Rechnungen." — it used to offer to stop „Hinweise für
  private Nachrichten". A chat notice's link names messages, changed training
  dates and answers to requests; a news mail's names the news.
- **An invoice is made out to the student**, by the name staff entered, never
  to the name of the login, which its holder can type under **Mein Konto** and
  which could have put „Muster GmbH" on a document under § 11 UStG. An invoice
  issued before is reprinted as it was, and says whom it is for where the two
  names differ.
- **A changed training date leads a family to their overview**, in the bell
  and at the end of the mail. Both led to the course's page, which is staff's,
  so a family landed on „Kein Zugriff".
- **A family can send twenty receipts an hour.** The twenty-first is refused
  with „Zu viele Versuche. Bitte später erneut versuchen." before the file is
  looked at. There was no limit: one login could have filled the disk.
- **A logo photographed on a phone held upright is accepted and stands
  upright.** Such a JPEG is stored sideways with a note saying which way is
  up; the portal used to measure it as stored and refused it as too tall, or
  drew it into a box of the wrong shape. It now reads that note itself —
  PHP's exif extension is not needed — and measures the picture as a browser
  draws it. Only the first 64 KB are read for the note, which is where the
  portal's own cleaned copy keeps it.

### Setup cannot be taken over, and an https portal stays on https

- **Setup no longer mistakes a database that is down for an unfinished
  installation.** On an installed portal, a request arriving while the database
  did not answer ran the migrations without their safeguards, and the page
  showed the database's name and server to anyone. Setup now says only „Die
  Datenbank antwortet gerade nicht", and the migration runner refuses to skip
  its safeguards unless the database is empty.
- **Finishing an installation whose configuration was already written needs a
  setup code** from `storage/setup-code.txt`, which only somebody with access to
  the files can read; the browser that started the installation carries it, so
  trying again there needs nothing. Before, after a refused first administrator,
  the next stranger to open `setup.php` could have become administrator.
- **Installing over plain `http://` on a real host is refused**, with a button
  to open the page over `https://`. A portal installed with an `https://`
  address sends `http://` page views to `https://` itself, refuses a form sent
  over `http://`, and tells browsers to stay on https. A portal with an
  `http://` address works exactly as before.
- **Sign-ins are kept in the portal's own folder**, `storage/sessions`, instead
  of one the host may share with other customers. Everybody is signed out once
  by the update.
- The nginx example passes `setup.php` to PHP, so a portal on nginx can be
  installed from the browser.

### Setup checks the PHP extensions, and the package carries only the portal

- **Setup needs PHP's `fileinfo`, `iconv`, `ctype` and `filter` extensions**,
  besides the four it already checked, and does not install without them. A
  PHP without one installed cleanly and broke later, somewhere else: without
  fileinfo every photo and receipt is refused, with a sentence about the file
  that is not true; without iconv no invoice opens as a PDF and every page with
  a QR code shows an error; without ctype the student list, the invoices, the
  outbox and every page with a QR code show an error, and no IBAN can be saved;
  without filter no page opens at all. **Einstellungen → System** names any of
  the eight a running portal has lost — after the PHP version was switched in
  the hosting panel, say — with what it is for and what to do. The suite holds
  the list to what the code and the dependencies declare they need. A new
  install on a PHP without iconv, ctype or filter is refused where before it
  went through; a portal already running on such a PHP is not refused and keeps
  running — what breaks there broke before too — and shows the warning under
  **Einstellungen → System** until the extension is switched on.
- **The package lists what it ships**, one by one: the portal, the notes for
  whoever runs it (README, INSTALL, UPDATING, CHANGELOG, VALIDATION and TESTING)
  and the scripts. The prompts the project's agents work from (`.claude/`),
  CLAUDE.md, ROADMAP.md and `tests/` stay out, and an entry at the top of the
  repository that is on neither list stops the build until somebody decides. The
  script before listed only what to leave out, and so put `.claude/` into every
  package built after that folder was added. A `.claude` folder an earlier
  package left on a server can be deleted in the file manager; the portal never
  reads it.
- **A package is built from what is committed**, and named after its
  `VERSION`: `0.6.0-beta.N` for the packages of the beta, `0.6.0` for the
  release. `bin/release.sh` refuses a `VERSION` that is changed but not
  committed, and takes a folder to write the package into. Every package
  carries a `BUILD.txt` naming the commit it was built from and when, which the
  web server denies like every `.txt` file, so a package can be named by its
  commit, where `VERSION` stays the same across many. `config/` and `storage/`
  ship with nothing but their deny files and `config.example.php`; the script
  refuses to build while git tracks anything else there, because such a file is
  in git's history already.
- **A package can be walked** before it is handed out: `tests/e2e.sh` with
  `CRM_E2E_ZIP` unpacks it as the owner does and names the package, its
  `VERSION` and its commit in its first line. It refuses a package that holds
  anything `bin/release.sh` leaves out, read from that script's own list, or
  anything in `config/` or `storage/` beyond what ships there.

### After an update, the browser fetches what changed

- The stylesheet and script were linked with the version number, which did not
  change between uploads, so a browser kept the old ones: the account menu
  opened as plain text and buttons over the page, and the bell still jumped.
  Every file is now linked with a fingerprint of its own contents, the club's
  colours too.

### Tests run on MariaDB only

- The throwaway MariaDB that `tests/mariadb-local.sh` and `tests/e2e.sh`
  start uses a temp folder of its own: a server sharing `/tmp` with other runs
  once had a temp table's file deleted from under it, and the run aborted
  part-way.

- The SQLite translation the tests used to run on is gone. `tests/mariadb-local.sh`
  starts a throwaway MariaDB; `tests/e2e.sh` walks the first evening in a
  browser and now also an invitation by address. Run on MariaDB 10.11.14 with
  PHP 8.4.26; MySQL 8.0 is not verified.
- Two tests that broke when the calendar moved on — one on 2 October, one due
  on 1 January — now take their dates from the clock.

### The top bar: a steady bell, and a menu behind your initials

- **Opening the bell no longer moves anything.** On a phone the whole bar used
  to jump and the panel ran off the left edge of the screen. The panel now
  stays on the screen, the number of unread notices is a red badge, like the
  one on **Chats**, and Escape, a tap anywhere else or opening the other menu
  closes it.
- **Tapping your initials opens a menu** with your name, „Mein Konto" and
  „Abmelden", the same for everybody. It works without JavaScript (ADR 0016).
- On the attendance list, the text on the marks for present and absent is
  readable in dark mode again; it now comes from the same colour as the text on
  every button.

### Club news by email starts switched on

- The owner decided that club news is information every member needs, not
  advertising (ADR 0018). New accounts therefore start with „Neuigkeiten per
  E-Mail" switched on (migration 021). Existing accounts keep what they had.
- The invitation page shows „Neuigkeiten des Vereins per E-Mail erhalten.
  Jederzeit abbestellbar." already switched on. A family can switch it off
  there, later under **Mein Konto**, or through the link in every such email; a
  no is kept, with a record of when it was given.
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
- **„Logo"** replaces the mark top left, in the menu, on the sign-in page and in
  the phone's top bar: a PNG, JPEG or WebP of at most 1 MB, at least 88 pixels
  tall, at most 2048 on either side, and no more than five times as wide as it
  is tall or twice as tall as it is wide. Two switches on
  „Aussehen" hide the portal's name and the „Verwaltung" line beside it.
  Removing the logo brings back the portal icon, or else the shuttlecock. (ADR
  0014.)
- A portal with no colours set loads nothing extra. A logo photographed on a
  phone held upright is measured the way it is shown (see „What the security
  review of the beta found", above).

### One login is one student

- **A student's address, when they have one, is their own login.** Brothers and
  sisters each need an address of their own. Access is invited, suspended, sent
  again or replaced on the student's own page, in a card „Zugang zum Portal“
  whose badge says **Ohne Anmeldung**, **Eingeladen**, **Aktiv** or
  **Gesperrt**. An address that is already
  somebody's login is refused with „Diese E-Mail-Adresse gehört schon zu einem
  anderen Zugang. Jede Person braucht ihre eigene.“ before anything is written,
  and a unique index in the database refuses it too, so no later mistake in the
  code can put two children on one login again (ADR 0010).
- **Migration 019 separates the logins that were shared.** The child whose
  record was created first keeps the login; the others keep their record,
  courses, charges, invoices, payments and address, and get a line under
  **Änderungen** saying which login they were on. Each of them then has a
  placeholder login, and their card says the address on the record is already
  somebody else's.
- **Konten is „Zugänge" now**: trainers, administrators and students, in groups
  („Zugänge, the access card and Mein Konto", above). A student login that no
  student points to any more is listed there under „Zugänge ohne Schüler“, to
  lock or delete. A family's menu has
  **Profil**, which goes straight to their child's page; **Mein Konto** keeps
  the sign-in settings. Every mail to a family opens with the child's first
  name.
- **An address can be changed by the trainer only while the invitation is
  still open**: the old link stops working and a new one goes to the new
  address. Once the family has signed up, the address is theirs to change under
  **Mein Konto**, confirmed from the new mailbox. Only a student's login can be
  re-addressed this way; a trainer's or administrator's pending invitation
  cannot.
- **Deleting a student no longer promises an undo** that was removed earlier in
  this release. The message now says that **Änderungen** shows what was deleted
  and that it cannot be restored.

### A new portal walks the administrator through its setup

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
  „Neuigkeiten“ and „Postausgang“ from the top of
  Nachrichten; Verwaltung, Zugänge, Änderungen, Einrichtung ansehen and
  Erweitert as cards at the top of Einstellungen. The entry a page belongs to
  stays marked while it is open.
- **The phone bar** got the order and the names it has now in „The portal
  looks and behaves like an iPhone app“, above, where „Mehr“ is a page. A
  family's way to the privacy notice and the version is at the end of **Mein
  Konto**, under „Datenschutz und Hilfe“.
- **The price box on a child's page is gone.** Its tariff and agreed price
  billed nobody: what bills is the price of the course the child is in. The
  stored values are kept and a save no longer changes them. „Dabei seit“ and
  „Mitgliedschaft bis“ moved to „Einteilung“.
  Automatic monthly charges are switched on and off on the **Beiträge** page,
  by an administrator.
- **Forms ask for less before they are saved.** A course's price form shows the
  name and the amounts; everything else waits under „Mehr Möglichkeiten“, as
  do the rarer course fields on a child's page. The default payment recipient
  is chosen from a list rather than typed as a number. The VAT rate and UID
  number only appear once „Mit Umsatzsteuer“ is picked — with JavaScript off,
  all of them show. Rarely needed settings sit under „Erweitert“.

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
- **„Für den Support kopieren“** gives a block of plain text for whoever helps:
  what broke and where, the version, the device, and the pages visited
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
- **Only the exact address reaches a login, and the count follows what was
  typed.** The database compares addresses under a collation that treats
  accents, ß and ss, ligatures and full-width letters as the same, so
  `familie@beispiel.at` and `familie@beispiel.át` were one login and two
  spellings, each with its own ten guesses. An address is now looked up only
  when it is plain ASCII once lower-cased, and only a login holding exactly that
  address answers, so the other spellings reach nothing. The attempts are
  counted against what was typed, never against the login it names, which keeps
  the form from telling anybody whether an address has a login (ADR 0020, 0021).
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
  budget; TESTING.md 4.6a says what that looks like by hand.

### An account created twice, from two directions

- **Inviting somebody whose address already has one now says so plainly.** The
  invite form wrote the row and left the database to refuse it, so what came
  back was the catch-all „Die Eingabe ist nicht möglich: Adresse bereits
  vergeben oder verknüpfte Daten vorhanden." — one sentence covering two quite
  different causes. It now says „Diese E-Mail-Adresse gehört schon zu einem
  anderen Zugang. Jede Person braucht ihre eigene.", naming whose login it is to
  staff — the same sentence every way of giving out an address uses.
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

### Trying it out no longer takes a term's worth of typing

- **The installer offers to fill the portal with example data.** An empty portal
  is unrecognisable: no courses, no children, every page an empty state, and
  anybody deciding whether to use this had to invent a term's worth of data
  first. One tick on the setup page now gives example data — since round three
  of ADR 0026 one course and four children with charges, above — and three
  sign-ins — a trainer and two families — with the password printed on the
  finish page. Unticked, nothing but the administrator
  is created, which is what a portal about to hold real data wants.
- **`demo:fill` prints the password it set.** It said „the password printed
  above" and printed no password, so the three accounts it had just made could
  not be signed in to at all. It is generated once and never stored in the
  clear, so the fill is the only moment anybody can be told it.
- **The layout check can use both roles on an example portal.** Its one
  `--password` could not cover an administrator and the example accounts, which
  have a password of their own; `--family-password` closes that, and the sweep
  went from 100 screens to 120.
- **TESTING.md opens with a twenty-minute script**: install with example data,
  build the real club's course and price list, add a member, watch the 42 €
  come out, issue the invoice, print both sheets, impersonate, sign in as a
  family, and put it all back.

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
- „ZVR-, Firmenbuch- oder GISA-Nummer": a registered club in Austria has a
  ZVR-Zahl and neither of the other two, and it goes on everything it sends out.

### Creating something asks for the basics, and then says what is left

- **Creating a child asks for the basics first**: the names, a date of birth, a
  course and whether they are a member, then how they sign in. Not the level
  and not the internal notes — a form of twenty boxes is a form somebody
  abandons in the middle of a training session.
- **And then the child's page says what is still to do**, numbered, at the top:
  an emergency contact, an address or an invitation, a course, a tariff. Each
  one is a link to where it is done, and each disappears when it is. A child
  with no course is a child nobody bills, and four days later nobody remembers
  which of fifteen children that was.
- A newly created course says the same: a training day, and a tariff.

### Signing in belongs to the child, and a contact is somebody to ring

- **One row was answering two questions.** "Who do I ring when she falls over"
  and "who does the portal write to" were both the standard contact, and they
  came apart in practice: the grandmother who should be rung has no email, the
  father who reads the invoices is never in the hall. The form could not answer
  either without lying about the other.
- **The address is on the child now**, and it is the child's own: whoever reads
  the invoices is whoever holds the login. **„Einladung senden"** on the child's
  page sends the invitation there. A parent's address belongs on the contacts.
- **Contacts are emergency contacts.** They need a phone number and no longer
  need an email address. Nothing is sent to them.
- An invitation is not sent twice to an account that has already set a password:
  that link would have replaced a password that works.
- The **Schüler** list names the two gaps separately, because they are filled in
  in two different places.
- Every account keeps its own address and its own password, and the new field
  is filled from whatever the portal was already writing to.
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
- Nobody's next invoice changes because of the update: every tariff's price
  becomes its first rate, every discount becomes a template *and* is copied onto
  every enrolment that was getting it. That sentence, and "every account keeps
  its own address and password" above it, are checked rather than asserted: the suite builds a portal as it
  stood before the update, applies the rest, and reads back the prices, the
  discounts and the addresses.

### A mail test that answers

- **„Testmail vormerken" was not a test.** It queued a message and sent the
  operator to the outbox to look for it, where a blocked port, a wrong
  certificate and a rejected password all looked the same: nothing arrived.
  **Einstellungen → SMTP → Verbindung testen** now opens the connection while
  she waits, sends to any address she types in, and writes down every step —
  and says which one failed in a sentence she can act on, with the server's own
  words underneath. The user name and the password are taken back out of the
  transcript, because the whole point of it is to be forwarded to a host.
- The sign-in page no longer carries a paragraph about what to type; the boxes
  say it.
- **„Etwas funktioniert hier nicht" was under the last card**, which meant it
  was only ever found by somebody who scrolled to the bottom of a page they had
  already given up on. On a desktop screen it is a button in the bottom right
  corner now. On a phone it stays at the end of the page, and **Mehr** and
  **Mein Konto** carry a link down to it: the bottom of a phone screen already holds the menu
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
- **The shipped privacy draft says what happens to the data** under
  *Empfänger*: nothing is sold, rented or passed to anybody else for
  advertising, and it is used only for running the training. A portal installed
  before this keeps its own edited notice, so add the sentence there by hand if
  you want it.

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
screen, any tap target under 44pt, text under 12px, or a browser error. After
this pass it reported 120 screens with nothing to fix; before it, it found
eight.

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
  attendance: before, it passed; now the portal stays closed, and the copy
  taken moments earlier brings the rows back.
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
  ever. The daily cleanup sweeps files nothing points at, leaving anything
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
  anlegen"** fills a portal with a course, children, contacts, enrolments,
  charges, payments, attendance, an absence, news and a conversation (one
  course and four children since round three of ADR 0026, above), so the app
  can be tried before it holds anybody real. It refuses
  to run twice, and mixes into real students only when that is ticked as
  understood. „Beispieldaten entfernen" takes all of it out again.
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
- **A shell that stays where you put it.** The top bar is pinned, and carries
  a notification pane and who you are — once, instead of once at the top and
  once at the bottom. A default colour set by the administrator that each person can
  override for themselves. Any page can report that something is wrong on it,
  with a screenshot, and the report arrives with the page, the device, the
  address and the version attached. An administrator or trainer can view the
  portal as somebody else to see what they see, with a bar saying so and a way
  back that works from inside the borrowed session; nothing can be changed in
  that view.
- **Messages in the shape people already know one.** Conversations down one
  side, bubbles down the other, one box with a „+" for a photo. Text and
  photos, within a size limit that is never higher than what PHP itself
  accepts. Writing to the trainer needs nobody's permission. A chat between
  two children from before stays readable for the two of them and for the
  administrators, who can read every chat (ADR 0022 §11.1), and takes no new
  messages.
- **The change log informs.** It says what changed, field by field, in the words
  she uses, storing only what actually differed. The undo is gone: a page that
  can put a record back is a page that can put a record back by accident, and
  the cost of keeping it was a copy of every record on every save.
- **The proof of payment is offered where it is easy.** A family with something
  outstanding is asked on the page they land on — „Schon überwiesen?" — with the
  upload one tap away, and told plainly that it is voluntary.
- **Every child has somebody to ring.** One contact is the standard one — the
  number you reach for first —, the last contact cannot be removed, and a child
  without one is named on the student list.
- **A feature list with steps to test it** — [TESTING.md](TESTING.md) — for the
  administrator to walk after a code change or a release, alongside the
  automated suites, which then ran 1957 assertions.

### What this was checked on

At `d095ca4`, on MariaDB 10.11.14 with PHP 8.4.26, the whole suite passes —
6703 assertions in a git checkout, 6702 in an exported copy, which has no
`.git/` for one check to look at — with all thirty-one migrations applying and
the data they carry across checked on a second database. The browser walk
passes, 372 checks, with no PHP warning, no browser error and no layout failure.
**MySQL 8.0 has never been run**, nor Safari on a real iPhone, a real mail
provider, a PDF reader rather than a parser, or a real hosting account, and the
sweep at phone width was last run before the wizard and the sign-in screens
existed. VALIDATION.md has the details, and says which runs were whose.

Since then, on the same engine and PHP, in exported copies: at `bfeb592`, where an
update that lost records stays closed, 6835 passed, 0 failed, with all
thirty-three migrations; at `aa5b1b7`, with the robustness suite, 7608 passed,
0 failed; at `f5d3c28`, with the new look, 7665 passed, 0 failed, and the browser
walk 377 checks, 0 failed, in Chromium; at `883be4d`, with „Mehr" as a page, 7915
passed, 0 failed, and the walk 381 checks, 0 failed; and with round two of the
removals, on the working tree before it was committed, 7762 passed, 0 failed,
with all thirty-seven migrations, and the walk 381 checks, 0 failed; with round
two's follow-ups applied to `159de33`, 7812 passed, 0 failed, where a restore
with a page opened halfway, played through in a scratch check, kept every
upload, and at `159de33` alone lost all of them; and with the chat's reading
rules, the kept IBAN changes and the security batch applied to `ead038a`, 7857
passed, 0 failed. Since `1ad0488` the suite can make a file the portal cannot
delete even as root, which the earlier runs could not; in the run at `f5d3c28`
both refusals for such a file ran and passed. The refused update with its
restore in phpMyAdmin (TESTING.md G.1–G.9) has not been walked, and the new look
has not been seen in Safari or on an iPhone (TESTING.md I.1–I.11).

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
  proves the PHP logic, not the MySQL dialect — see AUDIT.md, the review of
  v0.1.0, which git keeps.
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
was in AUDIT.md, which git keeps; what is still open is in ROADMAP.md.

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
