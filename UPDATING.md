# Updates with minimal risk to existing data

**The short version: upload the new files over the old ones and open the portal.**
The database applies any new migrations on the first page view. There is no
second step, and nothing to remember.

The portal writes a full copy of its database before it applies a migration;
keeping regular backups is up to whoever runs it. The rest of this document
explains what that first page view actually does, what happens when it fails,
and how to run the same thing deliberately on a server that has a shell.

## Updating an existing portal to 0.6.0

A portal installed fresh from this version can skip this section: its start
checklist covers all of it, and its privacy notice already starts from the new
drafts. A portal that already has families in it needs one thing done
**before** the upload — two, if anything in its custom fields is worth keeping —
and then changes in the ways below the moment the new files are opened.

**Before you upload: add three paragraphs to your privacy notice.** This
version switches club news by email on for new accounts, it gives every course
a group chat, and a student can sign in with a username and a one-time sign-in
link. Your privacy notice has to say all three. The drafts in the download only
fill in the notice of a brand-new portal; yours keeps the text you saved, so the
paragraphs have to be added by hand. Do it while the old version is still
running, so that no family uses the new one under a notice that does not
mention it. Open **Einstellungen → Datenschutz**, and in the German text:

1. In „4. Rechtsgrundlagen", replace the paragraph that begins „Der Newsletter
   wird nur an Konten mit aktiviertem Newsletter-Abonnement gesendet" with:

   > Neuigkeiten des Vereins werden als Vereinsinformation per E-Mail
   > verschickt, etwa wenn sich eine Trainingszeit ändert oder die Halle
   > geschlossen ist. Bei neuen Konten ist das eingeschaltet. Abschalten lässt
   > es sich schon beim Aktivieren des Kontos, jederzeit später in „Mein Konto“
   > und über den Link in jeder dieser E-Mails; im Portal bleiben die
   > Neuigkeiten lesbar.
   > [Rechtsgrundlage ergänzen – z. B. berechtigtes Interesse]
   > E-Mail-Hinweise auf private Nachrichten haben eine eigene Einstellung.
   > Sicherheitsmails wie Einladungen und Passwortlinks dienen der
   > Bereitstellung des Zugangs.

2. At the end of the section that lists what is processed (in the draft, „3.
   Welche Angaben verarbeitet werden"), add the paragraph about the chat, with
   the note „[Zweck und Rechtsgrundlage ergänzen]" after it. It is in
   `docs/privacy-draft-de.txt` in the download, beginning „Nachrichten: Jeder
   Kurs hat einen Gruppenchat". It says that a course's children and the
   coaching team read its group, that the administrators can read chats
   between a child and a trainer, that a message is text and photos, that the
   chats between two children from before stay for the two of them to read and
   take no new messages, and that an uploaded photo is stored without where it
   was taken.

3. After the sentence „SMTP-Passwörter und versandbereite E-Mail-Inhalte sind
   in der Anwendungsdatenbank verschlüsselt.", add the paragraph from
   `docs/privacy-draft-de.txt` beginning „Angemeldet wird mit der
   E-Mail-Adresse". It says that a student can sign in with a username, that a
   login without an address receives no e-mail, and what a sign-in link is and
   what is kept of it. In „2.", replace the sentence „Jeder Schüler hat
   höchstens ein eigenes Konto, …" with the draft's, which begins „Jeder
   Schüler hat ein eigenes Konto".

4. Replace each line in square brackets with your own words, then save.

The old paragraph called club news voluntary and based on consent. That is no
longer true: you decided that club news is information every member needs,
which is why it now starts switched on. Which legal basis that rests on is
yours to decide and to have checked; the bracketed notes mark where it goes. A
notice you have released („… zur Verwendung freigegeben") is not saved while a
note in square brackets is still in it, and the message names the note. The
same paragraphs in English are in `docs/privacy-draft-en.txt` in the
download, for the English version if you keep one. Saving changes the
**Fassung** number under the notice; nobody is asked to acknowledge it again.

**Before you upload, if anything in the custom fields matters, write it down.**
This version deletes the custom fields — „Weitere Angaben" on a child's page —
with everything typed into them (migration 032, ADR 0026). Nothing in the portal
shows them afterwards. The copy of the database the update writes first, in
`storage/backups`, still holds them until five newer copies have pushed it out.

**Migrations 020 and 021 run by themselves** on the first page view, like every
migration before them. 020 gives every account a status and adds an empty list
of times online; 034 and 035 below take both away again in the same update, so
neither is ever seen. 021 switches „Neuigkeiten per E-Mail" on for accounts
created **from now on**. Every existing account keeps what it has: a family who
had it off still has it off, and nobody is signed up behind their back.

**Migrations 022 to 027 run by themselves** as well. 022 and 023 gave every
login a username, and 024 takes the usernames away again, as you asked: every
login keeps its address, password and settings, and signs in with its address
exactly as before. 025 gives every course a group chat — an archived course too,
which stays readable for staff — and turns each existing chat between a child
and a trainer or administrator into one the administrators can read. The shared
conversations from before, where a family wrote to every member of staff at
once, are kept to read under „Frühere Unterhaltungen" and take no new messages.
026 lets staff take a message in a group down and put it back; 027 adds a status
emoji, which 035 takes away again. No row is removed, so the check that counts
the guarded tables before and after passes; only the chats grow, by one group
per course.

**Migrations 028 to 031 follow.** 028 lets a login have a username instead of an
address; every existing login keeps its address and gets no username. 029 and
030 make a student's login impossible to delete: the database refuses it, and
the portal gives the student a fresh login in its place instead. 031 adds what
will keep a child taken out of a course rather than deleting the enrolment;
nothing uses it yet. After the files, every student without a login is given
one, a placeholder that nobody can sign in with until staff give it an address
or a username. No row is removed; the logins grow by one for each such
student.

**Migrations 032 and 033 delete what this version no longer has.** 032 deletes
the custom fields, with every value typed into them. 033 deletes the saved views
of the **Schüler** list and the e-mail templates, including the two examples a
new portal started with. No other table loses a row. The table of custom-field
values used to be among those counted before and after an update; it leaves
that list with 032, so its loss does not refuse this update (step 6 below).
Lines under **Änderungen** written before about a custom field stay, the field
named „Früheres eigenes Feld".

**Migrations 034 to 037 delete the rest of what goes.** 034 deletes the list of
times each account was in the portal. 035 takes from every login when it was
last seen, its chosen status, its status emoji and the name of its profile
picture; 036 takes the name of each child's picture. 037 deletes every request
one family made to write to another, whatever became of it. No login and no
child is lost, so the check that counts the guarded tables before and after
passes; the list of times and the requests were never among them. Chats between
two children stay, to be read by the two of them, and take no new messages.
Voice notes and files sent before stay where they are and open as before; a new
message is text and photos.

**The profile pictures are deleted for good.** Once the update has passed, the
portal deletes the stored pictures from `storage/uploads/avatar`. The problem
reports' screenshots, kept in the same folder, stay. The copy the update writes
first is of the database only: it holds the pictures' names, not the pictures.
Importing it, or any older copy, brings the names back without the files, and
no picture comes back. If one matters to somebody, save it from the portal
before you upload.

**Brothers and sisters on one login are separated.** From this version one
login belongs to one student, and migration 019 makes the database hold to
that. On a login that holds several children, the child whose record was
created first keeps it. Each of the others is taken off it and keeps everything
else: their record, their courses, charges, invoices and payments, and the
address on their record. Nothing is deleted, so the check that compares the
guarded tables before and after passes unchanged. Each child taken
off a login gets a line under **Änderungen** saying which login it was on. The
update sends the families no message. Each of those children is then given a
placeholder login (see 028 to 031 above) and cannot sign in until staff give
it, on the child's page, an address of the child's own with an invitation, or a
username with a sign-in link. A shared login cannot be restored once the
update has run, because the database now refuses it: the way back is the
backup the update writes first.

**After the update, press „Nur Verbindung prüfen“ once.** Invitations,
password-reset links and address confirmations are now only sent when the last
test under **Einstellungen → SMTP** passed; before, a saved SMTP setting was
enough. If the last test there failed, or was never run, then until it passes:
„Einladung senden“ is refused with a sentence saying so, and a family who taps
„Passwort vergessen?“ receives nothing. Open **Einstellungen → SMTP**, press
**„Nur Verbindung prüfen“**, and wait for the green **Erfolgreich**. Changing
the SMTP settings later clears the result, and the test has to pass again.

**After the update, take the custom fields out of your privacy notice.** If it
names them — the draft's „sowie die im Formular ausdrücklich erhobenen weiteren
Angaben" in „3.", and whatever you wrote in place of its note about them — take
that out under **Einstellungen → Datenschutz**, in English too if you keep it:
the portal no longer holds them. The drafts in the download no longer mention
them.

**The number under the privacy notice changes once.** It is now worked out
from the German and the English text as a pair, so every portal shows a new
**Fassung** after this update although the text is the same. Nobody is asked to
acknowledge it again: the number is recorded, never compared. The English text
is optional from this version; a notice already released in both languages
keeps both.

**What you will notice afterwards.** None of this needs anything done; it is
here so that nothing surprises you.

- **A portal with an `https://` address is only served over HTTPS.** If
  `'app_url'` in `config/config.php` starts with `https://`, as it does for a
  portal set up over `https://`, a page opened over plain `http://` — an old
  bookmark, a typed address — opens the same page over `https://` instead, and
  a form sent over `http://` is refused with a sentence asking to open the page
  again with `https://`. A portal whose address starts with `http://` works
  exactly as before. To give it the same protection, switch on the SSL
  certificate for the domain in the hosting panel, check that the sign-in page
  opens with `https://` in front, then in `config/config.php` change `'app_url'`
  to start with `https://` and set `'secure_cookies'` to `true`.
- **Everybody is signed out once.** Sign-ins are now kept in the portal's own
  folder, `storage/sessions`, instead of the folder the host shares between its
  customers, so the first page view after the upload asks you and every family
  to sign in again. Nothing is lost. If `storage/` cannot be written, sign-ins
  stay where they were and the hosting error log says so.
- **The bell stays where it is** when you open it, its panel fits on the
  phone's screen, and the number of unread notices is a small red badge.
  Tapping anywhere else, or Escape, closes it.
- **It looks and behaves like an iPhone app**: the phone's own font, white
  grouped lists on a grey ground, switches, sheets from the bottom for anything
  that deletes, and a back button with the name of the page above. Tell the
  families what moved in their bar at the bottom, which now reads Übersicht ·
  Beiträge · Chats · Profil: their **Mein Konto** is the row „Anmeldung und
  Darstellung" on **Profil**, and the news is on the overview. Yours reads
  Übersicht · Schüler · Anwesend · Chats · Mehr; „Post" is „Chats", and „Mehr"
  is a page with Kurse, Geld, Einstellungen and your Mein Konto. The language
  is chosen under **Mein Konto → Sprache**; the EN/DE switch is only on the
  sign-in pages now.
- **Everybody appears by their initials**, and your initials at the top right
  open a menu with „Mein Konto" and „Abmelden".
- **Nachrichten works like a messenger**: a group for every course, whose
  children are whoever is enrolled now, then the chats with one person. A child
  writes to a trainer or an administrator by name; you write to any child, or to
  a course's group. Group messages send no e-mail. A message is text and photos:
  a family's „+" asks the phone to open its camera and takes only a JPEG, and
  you can also choose a PNG or WebP from the phone's photos. Voice notes and
  files sent before this update stay in their chats and open as they did; the
  portal now tells every browser that it never uses the microphone
  (`Permissions-Policy: microphone=()`), as it already did for the camera. A
  chat between two children from before reads as it did, says that chats between
  children have closed, and takes no new messages.
- **Signing in is by address, or for a student by username.** „Schüler
  anlegen" is a wizard in two steps: who is joining and into which course, then
  how they sign in — an invitation by e-mail, a username with a sign-in link
  that works once within 48 hours, shown as a QR code to scan or copied to
  send, or no sign-in for now. A child's page shows „Ohne Anmeldung" until one
  of those is done. „Per E-Mail einladen" on the **Schüler** page still invites
  somebody by their address alone: they fill in their own details and then
  choose a course.
- **Club news by email starts switched on** for a new family: the switch on
  the invitation page is already on, and they can switch it off there, or later
  under **Mein Konto**.
- **Your club's colours and logo**, under **Einstellungen → Portal**, on the
  cards „Aussehen" and „Logo". Until you set something, the portal keeps its
  built-in colours. A colour too pale or too dark to read text on is used darker
  or lighter, in the same hue, and the card shows both. A logo can be a PNG, JPEG or WebP
  of at most 1 MB. A photo taken on a phone can be measured the wrong way round
  and refused; saving it again from an image editor, or as a screenshot, fixes
  that.
- **No longer in the portal**: custom fields, copying, saved views of the
  **Schüler** list, „An mehrere schreiben" with its e-mail templates,
  „Warteschlange senden", the printed form and data sheet, Verwaltung's „Tarife"
  tab, the online dots with the status and „Wann online?", the status emoji,
  profile pictures, asking to write to another family, and voice notes and files
  in new messages. Mail goes out by itself just after a page has been served, as
  before; [CHANGELOG.md](CHANGELOG.md) says what takes the place of the rest.

Everything else in this version is either new or moved to another place in the
menu; [CHANGELOG.md](CHANGELOG.md) lists it.

## What the first page view after an upload does

Each request compares the migration files on disk against what the database
records as applied. On the common path that is one file read and nothing else.
When they differ, an advisory database lock is taken first, so two visitors
arriving together cannot both migrate — the second one waits, then finds nothing
left to do. Then, inside that lock and **before the database is touched at all**:

1. **Are these files newer than the database?** If the database records
   migrations these files do not contain, this is a downgrade: the wrong package,
   or an older one put back. It stops. Without this check nothing would be
   pending, the update would look like a success, and the portal would serve old
   code against a newer schema — the shape of problem that loses data quietly
   rather than failing loudly.
2. **Did the whole upload arrive?** The package ships a `MANIFEST` of every PHP
   and SQL file with its checksum. A file manager extracts a ZIP one file at a
   time and an FTP client in text mode rewrites the line endings of everything it
   copies; both leave a directory that lists perfectly. A mismatch stops the
   update and names the files. A git checkout ships no manifest and is skipped.
3. **Can a backup be written?** First the portal checks that `storage/` can hold
   the file of step 4; if it cannot, the update is refused here, before any copy
   is taken, so a page view that is refused never writes a copy and never prunes
   an older one. Then a full SQL dump goes to `storage/backups` before anything
   is migrated. If it cannot be written, nothing is migrated. See
   [Backups](#backups) for the way past this when you have taken your own.
4. **The numbers from before are written down.** The rows of the eighteen tables
   in `schema_guarded_tables()` are counted — `accounts`, `students`,
   `contacts`, `absences`, `charges`, `payments`, `threads`, `messages`,
   `message_files`, `news`, `class_students`, `attendance`, `invoices`,
   `invoice_charges`, `payment_proofs`, `consent_log`, `tariff_rates` and
   `tariff_discounts` — and kept in `storage/update-unfinished.json`, with both
   version numbers and which copy step 3 wrote, until the update has passed. If
   that file cannot be written, nothing is migrated.
5. Each unrecorded migration is then applied in name order and recorded with the
   checksum of the file it came from. A migration that was edited after being
   applied is refused **by name**, because the checksum no longer matches.
6. **Is everything still there?** Every table still on the list is counted again
   and compared with the numbers from step 4; a table that is gone counts as
   empty. A count that fell stops the update. Counts do not prove an update was
   correct, but a count that fell proves it was not.
7. What the portal cannot work without is filled in where it is missing — the
   lists it needs, a group chat for every course, a login for every student.
   Every stored upload that no record names any more and that is more than ten
   minutes old is deleted, as the nightly cleanup does with an hour's grace: in
   this version, the profile pictures (see
   [Updating an existing portal to 0.6.0](#updating-an-existing-portal-to-060)),
   and any receipt or chat photo
   whose record went before the nightly cleanup found it. Then the file from
   step 4 is deleted, and the page is served.

If any step fails the portal answers 503 and stays closed. The page tells a
family there is nothing for them to do, and tells whoever looks after the portal,
in German and English, what went wrong and what to do. It never prints SQL: that
address is public and a parent may be the one looking at it. The full database
error goes to the hosting error log.

Steps 1 to 4 change nothing in the database, so when one of them refuses, the
next page view starts again once the cause is put right: the newest files,
uploaded completely, and a `storage/` folder the portal can write to. From step 4
on, the update is **unfinished** until step 7 has run, and every page view starts
by counting again and comparing with the numbers in the file:

- While a table still on the list has fewer rows than before, nothing more runs
  and the portal stays closed — on every page view, not only the first. See
  [A refused update](#a-refused-update).
- Otherwise the update carries on where it stopped. A migration that stopped
  partway is tried again from its first statement, against the same numbers, and
  no second copy is written: the one from step 3 stays the newest.

While maintenance mode is on all of this is skipped, so an operator applying a
migration by hand from a shell cannot race the web request. While an update is
unfinished, maintenance mode lets nobody in, administrators included: only the
update may change the rows the numbers describe. Deleting the maintenance flag,
`storage/maintenance.flag`, lets the next page view count again.

## A refused update

When an update finds fewer rows in a guarded table than before, the portal stays
closed until one of two things is true. It counts again on every page view and
opens by itself once one of them is; there is nothing to press. Keep the ZIP of
the version you are running until the next update has opened the portal: the
first way back needs it.

**The rows come back.** The way when the loss was a mistake, and the safe way
whenever you are not sure which it was:

1. Upload the files of the version the closed page names — the one you came
   from — over the new ones. The portal stays closed meanwhile.
2. Import the copy the closed page names, from `storage/backups`, as
   [INSTALL.md](INSTALL.md#wiederherstellen) describes.
3. Open the portal. It counts every guarded table again and opens once none has
   fewer rows than before the update. If one still has, the page says which: the
   import stopped partway, or it was a different file.

In this order, because the newer files, left in place, apply their migrations
again on the next page view and take the same rows again. If that happens anyway,
nothing more is lost: no copy is written while an update is unfinished, so the
one the page names is still there. Upload the files and import it again.

**A release says the rows may go.** If a migration removed rows on purpose and
its table was not taken off `schema_guarded_tables()` in the same commit, the
release has a bug. The release that fixes it takes the table off the list, with
the reason beside it, and uploading it opens the portal without importing
anything: the tables still on the list are compared, and they have their rows.
Whether a loss is intended is decided in the code, by whoever makes the
releases — never by the portal.

**While it is closed** nobody gets in: families, trainers and administrators
alike, and maintenance mode does not change that. The console runs only `check`,
`status`, `migrate`, `update`, `maintenance:on` and `maintenance:off`; mail,
billing, the nightly cleanup and `backup` wait. Any other command stops with one
sentence saying that an update is unfinished, and exits with 1; a cron job set
up in the panel fails the same way each time it runs. Anything else that added
rows could make up a count that fell and hide what is missing. On a server with
a shell, `php bin/console.php check` is the quickest look: it prints the row
counts, `null` for a table that is gone, and then names the missing table in one
sentence that points here, ending with exit code 1.

**The last way out.** `storage/update-unfinished.json` holds the numbers from
before. Deleting it tells the portal to accept the database as it is: the next
page view counts afresh and opens, and whatever is missing stays missing. Do it
only when the missing rows are meant to be gone or are back some other way — for
instance after importing a copy you exported yourself before the update (with
`skip-backup`), which can be older than the numbers in the file, so that the
portal cannot tell it from a loss. Deleting the whole `storage/` folder does the
same, and takes the copies with it.

## Backups

A full SQL dump of every table, written before any migration runs. Plain SQL
because that is what the import screen in every hosting panel accepts.

- **Where:** `storage/backups`, beside the maintenance flag, so a deployment with
  separate release folders keeps them across a switch. The folder denies itself
  over HTTP twice — `storage/.htaccess` and a second deny file written into the
  folder itself — and each name carries four random bytes, so a server that ever
  failed to deny it still could not be walked.
- **How many:** the last five. Older ones are removed as new ones arrive, because
  a portal nobody prunes eventually fills the disk quota, which is its own outage.
- **One per update.** While an update is unfinished no further copy is written,
  by a page view or by `php bin/console.php backup`, so pruning never removes the
  copy taken before it.
- **On demand:** `php bin/console.php backup [reason]`, or look at
  **Einstellungen → System**, which lists every copy with its date and size.
- **Restoring** is deliberately not automated. Putting several megabytes of a
  family's data back over a live database is a decision, and phpMyAdmin already
  does it better than anything written here would. The procedure is in
  [INSTALL.md](INSTALL.md#wiederherstellen).
- **When it cannot be written** the update stops. If you have exported the
  database from the panel yourself, create an empty file named `skip-backup` in
  the `storage` folder; the next update proceeds without one and consumes the
  file, so it cannot quietly disable the safeguard for every future update. A
  `skip-backup` the portal cannot delete refuses the update instead, saying so:
  left in place, it would skip the copy before every later update too.

A first install writes no backup: an empty database has nothing worth copying.

## Keep these three things separate

On shared hosting this happens by itself: `config/config.php` is not in the
distribution package, so unpacking over the old files cannot touch it, and the
database is untouched apart from the migrations. The table below is the layout
for a server where you keep each release in its own directory.

| Component | Location in the suggested layout | Update behavior |
|---|---|---|
| Source and dependencies | `/srv/badminton/releases/0.6.0/` | Put each new version in a new directory |
| Configuration and encryption key | `/srv/badminton/shared/config.php` | Preserve the file and its `app_key` |
| Students, tariffs, payments, messages | The existing MySQL/MariaDB database | Apply only the new migrations |
| Active web root | `/srv/badminton/current/public/` | `current` points to the selected release |

Each release can contain a symlink `config/config.php` to the shared configuration. Set one shared `maintenance_file` path in that configuration. Do not keep a different maintenance flag inside each release, because a directory switch would then bypass the pause.

## Versioning policy

- `VERSION`, the Git tag, the ZIP filename and the version line at the top of
  `README.md` identify the same release. Only the ZIP filename follows on its
  own — `bin/release.sh` reads `VERSION` to build it. The tag and the README
  line are typed, so check both against `VERSION` before tagging: the README
  line is the first thing anybody reads and nothing derives it.
- `CHANGELOG.md` records behavior and any compatibility notes.
- `composer.lock` fixes dependency versions. Deployment uses **install**, never **update**.
- Database migrations are ordered SQL files. The migration ledger stores each file’s checksum. Do not edit a migration that has already been applied; add a new file.
- Future schema changes should first add compatible structures, then migrate values and verify them. Remove obsolete structures only in a separate later release after checking that no current code needs them.

## The same thing from a shell

On a server with a command line, where you want the record counts compared
before and after:

```bash
php bin/console.php update
```

It switches maintenance mode on, applies any new migrations, compares record
counts before and after, and only then switches maintenance off. If anything
fails it stops and **leaves the portal closed**. It runs exactly the same
migration code the web request does, so the two cannot disagree about what has
been applied.

An administrator can still sign in while maintenance mode is on, and a banner
at the top of every page offers to switch it off. That is deliberate: switching
it on from **Einstellungen → System** must not be able to lock you out. The one
exception is an unfinished update ([A refused update](#a-refused-update)): then
nobody is let in, and deleting `storage/maintenance.flag` in the file manager, or
`php bin/console.php maintenance:off`, switches maintenance mode off.

### What re-running is guaranteed to do

The migration ledger records each applied file and its checksum, so the runner
is safe to run repeatedly. Verified behaviour:

| Situation | What happens |
|---|---|
| Opening the portal with nothing new uploaded | One file read, no database work |
| Running `update` again with nothing new | Applies nothing and reopens the portal — unless an update is unfinished and a guarded table still has fewer rows than before it |
| Uploading an older package over a newer one | Refused by name; the database is not touched |
| An extract that stopped halfway | Refused, naming the files that do not match |
| `storage/` not writable when a migration is pending | Refused; no backup, no migration |
| A migration that removes rows from a guarded table | Refused after the fact; the portal stays closed on every page view until the rows are back or a release takes the table off the list |
| A page view, or a newer upload, after that refusal | Counts again; runs nothing and stays closed while a guarded table has fewer rows than before the update |
| The previous version's files and the copy from before, after that refusal | Counts again and opens |
| A migration that drops a guarded table | Counted as emptied, and refused the same way |
| A new migration added in a later version | Applies only that one |
| A column added later to an existing table | Existing rows get the column's default, never NULL |
| A migration file edited after being applied | Refused by name, with the reason |
| A migration failing partway | Stops at that statement, does **not** record the migration, names the statement number; each page view tries it again from its first statement, against the numbers from before the update, without writing another copy |

A migration failing partway is the case that needs you. MySQL cannot roll back
DDL, so a migration that fails at statement 5 of 12 leaves the first four applied
and the migration unrecorded — trying it again starts it from the beginning, and
fails again on the work already done unless each of its statements can run
twice. The error names the file and says how far the update got — „(5/12)" on
the closed page — counting the furthest statement any attempt reached, even when
a later attempt stopped earlier, on what the first one had already done. The way
back is the one for [a refused update](#a-refused-update): the
previous version's files, then the copy from before. Finishing the migration by
hand in phpMyAdmin and adding its row to `schema_migrations` works too; the next
page view then compares and opens.

## Adding a feature after going live

New settings do **not** need a migration. Every operator setting is declared in
`app/defaults.php` with a type and a default, so a key with no stored row reads
as its declared default. Adding one there makes it appear in the settings form
automatically, with an existing install picking up the default on first read.

Schema changes do need a migration. Add a new numbered file in
`database/migrations/`; never edit one that has shipped. Give every new column a
`DEFAULT` where the type allows, so rows written by the previous version cannot
leave a NULL the new code has to guess about.

A migration must not reduce the row count of any table in
`schema_guarded_tables()`. The update refuses one that does, on purpose, and the
portal stays closed until the rows are back: a count going down means something
went wrong rather than something being cleaned up. A release that genuinely has
to remove rows — merging duplicates, say — takes the table off that list in the
same commit, with the reason written beside it. A release that forgot to is fixed
by the release that does: uploading it reopens the portal its predecessor closed
([A refused update](#a-refused-update)). So far one has: 032, which deletes the
custom fields with everything typed into them (ADR 0026), took `field_values` off
the list.

## With release directories: before the maintenance window

Everything from here on describes a server with a shell where releases live in
separate directories and `current` is a symlink. On hosting where you upload
into one folder, the two sections above are the whole procedure.

1. Read the new release’s change notes, including the schema versions it supports.
2. Put the new release in its own directory. Do not unzip it over the running application.
3. Run `composer install --no-dev --prefer-dist --optimize-autoloader` if dependencies are not included.
4. Test the release using a separate database and separate configuration. Do not point the test mail worker at real recipients. The test suites create and delete records of their own and must never be pointed at the live database; `tests/run.php` refuses a database whose name does not end in `_test`, and the one `config/config.php` gives the portal.
5. Check the existing hosting recovery arrangement and who can restore it. The portal writes a copy of the database before it migrates, but it never restores one: that is done by hand, as described in [INSTALL.md](INSTALL.md#wiederherstellen).

## With release directories: apply an update

Example paths below are a layout template; `0.7.0` stands for whichever release you are moving to.

1. Pause the mail cronjob. Enable maintenance in the active release:

   ```bash
   php /srv/badminton/current/bin/console.php maintenance:on
   ```

   New web requests receive HTTP 503; new mail workers refuse to start. Already-running requests must finish before migration. Wait for the current PHP requests and mail worker to finish, using the process manager or hosting panel.

2. Record the current counts and totals:

   ```bash
   php /srv/badminton/current/bin/console.php check > /srv/badminton/shared/before-update.json
   ```

   This is a verification report, not a backup.

3. Link the shared configuration into the new release, then apply its pending migrations:

   ```bash
   ln -s /srv/badminton/shared/config.php /srv/badminton/releases/0.7.0/config/config.php
   php /srv/badminton/releases/0.7.0/bin/console.php migrate
   php /srv/badminton/releases/0.7.0/bin/console.php check > /srv/badminton/shared/after-update.json
   ```

   Stop if a command fails. The migration command does not erase the database or rerun successful migrations. MySQL schema changes may commit individually: a failed multi-statement migration can leave partial changes, so do not assume it rolled back automatically.

4. Compare the before/after student counts, charges, payments and total cents. Expected changes must be stated in the release notes: moving to 0.6.0, the custom fields and their values are gone (migration 032), and the new release's `check` no longer counts them. Counts alone do not prove completeness: also inspect representative linked students, prices, payment periods and conversation ownership in the test deployment.

5. Switch the code using an atomic symlink replacement on the same filesystem:

   ```bash
   ln -s /srv/badminton/releases/0.7.0 /srv/badminton/current-next
   mv -Tf /srv/badminton/current-next /srv/badminton/current
   ```

   This example uses GNU/Linux `mv`. Keep `current-next` unused before running it. Do not use `rsync --delete` over shared data. Reload PHP-FPM or clear its opcode cache through the hosting controls so workers load the new code.

6. Disable maintenance, sign in, open a student, verify their details and payments, and check a student account’s access. Then resume the mail cronjob and send a test mail to your own address.

   ```bash
   php /srv/badminton/current/bin/console.php maintenance:off
   ```

7. Keep the previous release directory until the new release is accepted. Document the version, migration IDs, verification result and deployment time.

## If something goes wrong

- Keep maintenance enabled and mail processing paused while investigating.
- If only code changed, or the new database schema is explicitly compatible with the previous release, switch the `current` symlink back and reload PHP-FPM.
- Do not reverse a migration by guessing SQL, importing the initial schema over existing tables, or dropping/recreating the database.
- If a schema change is incompatible, use a reviewed forward fix or the operator’s existing recovery procedure. Restoring older data may discard newer records; identify that time window and preserve/reconcile legitimate new writes before considering a restore.
- A lost or replaced `app_key` prevents decryption of existing encrypted mail settings and queue contents. Restore the correct configuration rather than generating a new key during an update.
- SMTP cannot guarantee exactly-once delivery across a crash after server acceptance. Inspect uncertain/failed sends before manually retrying; a duplicate email is possible in that failure window.

The schema updater only ever moves forward: it applies migration files that the
ledger has not recorded, and refuses one whose contents changed after it ran. It
drops nothing of its own accord — a table goes only when a migration file says
so, as 032 and 033 do in 0.6.0 — never reverses a migration, and never runs
while maintenance mode is on.
