# Updates with minimal risk to existing data

**The short version: upload the new files over the old ones and open the portal.**
The database applies any new migrations on the first page view. There is no
second step, and nothing to remember.

This application does not manage backups; that stays with the operator. The rest
of this document explains what that first page view actually does, what happens
when it fails, and how to run the same thing deliberately on a server that has a
shell.

## Updating an existing portal to 0.6.0

A portal installed fresh from this version can skip this section: its start
checklist covers all of it, and its privacy notice already starts from the new
drafts. A portal that already has families in it needs one thing done
**before** the upload, and then changes in the ways below the moment the new
files are opened.

**Before you upload: add three paragraphs to your privacy notice.** This version
records when each account was in the portal, it switches club news by email
on for new accounts, and it gives every course a group chat. Your privacy
notice has to say all three. The drafts in
the download only fill in the notice of a brand-new portal; yours keeps the
text you saved, so the paragraphs have to be added by hand. Do it while the old
version is still running, so that no family uses the new one under a notice
that does not mention it. Open **Einstellungen → Datenschutz**, and in the
German text:

1. At the end of the section that lists what is processed (in the draft, „3.
   Welche Angaben verarbeitet werden"), add:

   > Das Portal speichert für jedes Konto, wann es zuletzt geöffnet wurde, und
   > für höchstens die letzten 30 Tage, von wann bis wann es geöffnet war – nur
   > Datum und Uhrzeit, keine IP-Adresse und keine aufgerufenen Seiten. Sehen
   > können das nur Trainerinnen und Administratoren; Schülerkonten sehen weder
   > eigene noch fremde Zeiten. Diese Zeiträume werden automatisch gelöscht,
   > sobald sie älter als 30 Tage sind, oder früher, wenn das Portal auf eine
   > kürzere Frist eingestellt ist; das Portal räumt dafür einmal am Tag auf.
   > Der Zeitpunkt des letzten Besuchs bleibt gespeichert, solange das Konto
   > besteht, und wird mit ihm gelöscht. Wer als Trainerin „Als offline
   > anzeigen“ wählt, erscheint für andere Trainerinnen offline;
   > Administratoren sehen die Zeiten weiterhin.
   > [Zweck und Rechtsgrundlage ergänzen]

2. In „4. Rechtsgrundlagen", replace the paragraph that begins „Der Newsletter
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

3. After the paragraph from step 1, add the one about the chat and the
   online dot — it is in `docs/privacy-draft-de.txt` in the download, beginning
   „Ob jemand gerade online ist" and „Nachrichten: Jeder Kurs hat einen
   Gruppenchat". It says that everybody signed in now sees who is online,
   that a course's children and the coaching team read its group, and that
   the administrators can read chats between a child and a trainer, and
   that an uploaded photo is stored without where it was taken.

4. Replace each line in square brackets with your own words, then save.

The old paragraph called club news voluntary and based on consent. That is no
longer true: you decided that club news is information every member needs,
which is why it now starts switched on. Which legal basis that rests on is
yours to decide and to have checked; the bracketed notes mark where it goes. A
notice you have released („… zur Verwendung freigegeben") is not saved while a
note in square brackets is still in it, and the message names the note. The
same three paragraphs in English are in `docs/privacy-draft-en.txt` in the
download, for the English version if you keep one. Saving changes the
**Fassung** number under the notice; nobody is asked to acknowledge it again.

**Migrations 020 and 021 run by themselves** on the first page view, like every
migration before them. 020 gives every account a status, which starts on
„Automatisch" and so shows exactly what it showed before, and adds an empty list
of times online. Nothing from before the update is filled in, so for the first
30 days the history says „Aufgezeichnet wird seit dem …" with the day of the
update. That list is not among the tables whose rows are counted before and
after, because the nightly cleanup deletes from it on purpose. 021 switches
„Neuigkeiten per E-Mail" on for accounts created **from now on**. Every
existing account keeps what it has: a family who had it off still has it off,
and nobody is signed up behind their back.

**Migrations 022 to 027 run by themselves** as well. 022 and 023 gave every
login a username, and 024 takes the usernames away again, as you asked: every
login keeps its address, password and settings, and signs in with its address
exactly as before. 025 gives every course a group chat — an archived course
too, which stays readable for staff — and turns each existing chat between a
child and a trainer or administrator into one the administrators can read. The
shared conversations from before, where a family wrote to every member of
staff at once, are kept to read under „Frühere Unterhaltungen" and take no new
messages. 026 lets staff take a message in a group down and put it back; 027
gives every account a status emoji, which starts as „Keins". No row is
removed, so the check that counts the guarded tables before and after passes;
only the chats grow, by one group per course.

**Brothers and sisters on one login are separated.** From this version one
login belongs to one student, and migration 019 makes the database hold to
that. On a login that holds several children, the child whose record was
created first keeps it. Each of the others is taken off it and keeps everything
else: their record, their courses, charges, invoices and payments, and the
address on their record. Nothing is deleted, so the check that compares the
nineteen guarded tables before and after passes unchanged. Each child taken
off a login gets a line under **Änderungen** saying which login it was on. The
update sends the families no message. Those children cannot sign in until they
have an email address of their own and a login of their own, invited or
created on their own page; until then the overview and the **Schüler** list
name them under „… Kinder brauchen eine eigene E-Mail-Adresse“. A shared login
cannot be restored once the update has run, because the database now refuses
it: the way back is the backup the update writes first.

**After the update, press „Nur Verbindung prüfen“ once.** Invitations,
password-reset links and address confirmations are now only sent when the last
test under **Einstellungen → SMTP** passed; before, a saved SMTP setting was
enough. If the last test there failed, or was never run, then until it passes:
„Einladung senden“ is refused with a sentence saying so, and a family who taps
„Passwort vergessen?“ receives nothing. Open **Einstellungen → SMTP**, press
**„Nur Verbindung prüfen“**, and wait for the green **Erfolgreich**. Changing
the SMTP settings later clears the result, and the test has to pass again.

**The number under the privacy notice changes once.** It is now worked out
from the German and the English text as a pair, so every portal shows a new
**Fassung** after this update although the text is the same. Nobody is asked to
acknowledge it again: the number is recorded, never compared. The English text
is optional from this version; a notice already released in both languages
keeps both.

**What you will notice afterwards.** None of this needs anything done; it is
here so that nothing surprises you.

- **Profile pictures load once**, not again on every page, and a family sees
  only their own child's picture and those of the trainers and administrators.
  Anybody else appears as initials, in **Nachrichten** too. A new picture shows at once; an
  old one can stay in a phone's memory for up to seven days.
- **The bell stays where it is** when you open it, its panel fits on the
  phone's screen, and the number of unread notices is a small badge in the
  portal's colour. Tapping anywhere else, or Escape, closes it.
- **Your picture at the top right opens a menu**: „Mein Konto",
  „Status-Emoji" and „Abmelden", and a coloured dot on the picture: green
  online, blue recently, yellow away, grey offline. For you and the other
  trainers it also holds a status — „Automatisch", „Abwesend", „Als offline
  anzeigen". A family's dot is always automatic, and they choose no status.
- **Nachrichten works like a messenger**: a group for every course, whose
  children are whoever is enrolled now, then the chats with one person. A child
  writes to a trainer or an administrator by name; you write to any child, or
  to a course's group. Group messages send no e-mail. Everybody signed in sees
  who is online right now; when somebody was last here stays with trainers and
  administrators.
- **Signing in is by address only**, and „Per E-Mail einladen" on the
  **Schüler** page invites somebody by their address alone: they fill in their
  own details and then choose a course.
- **When somebody was online**: under **Konten** and on each child's page, a
  line says when the account was last in the portal, and „Wann online? Letzte
  30 Tage" opens the days and times. Only trainers and administrators see it.
  When you view the portal as a family, it is your visit that is recorded, not
  theirs.
- **Club news by email starts switched on** for a new family: the box on the
  invitation page is already ticked, and they can untick it there. The printed
  sign-up form now asks „Bitte keine Neuigkeiten des Vereins per E-Mail
  schicken." instead of asking for a yes.
- **Your club's colours and logo**, under **Einstellungen → Portal**, on the
  cards „Aussehen" and „Logo". Until you set something, the portal looks exactly
  as before. A colour too pale or too dark to read text on is used darker or
  lighter, in the same hue, and the card shows both. A logo can be a PNG, JPEG or WebP
  of at most 1 MB. A photo taken on a phone can be measured the wrong way round
  and refused; saving it again from an image editor, or as a screenshot, fixes
  that.

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
3. **Can a backup be written?** A full SQL dump goes to `storage/backups` before
   anything is migrated. If it cannot be written, nothing is migrated. See
   [Backups](#backups) for the way past this when you have taken your own.
4. Each unrecorded migration is then applied in name order and recorded with the
   checksum of the file it came from. A migration that was edited after being
   applied is refused **by name**, because the checksum no longer matches.
5. **Is everything still there?** Rows in nineteen tables are counted before
   and after: `accounts`, `students`, `contacts`, `field_values`, `absences`,
   `charges`, `payments`, `threads`, `messages`, `message_files`, `news`,
   `class_students`, `attendance`, `invoices`, `invoice_charges`,
   `payment_proofs`, `consent_log`, `tariff_rates` and `tariff_discounts` — the
   list in `schema_guarded_tables()`. A count that fell stops the update. Counts
   do not prove an update was correct, but a count that fell proves it was not —
   and this catches it while the backup is still the newest thing that happened.
6. The seeded defaults are refreshed for anything new, and the page is served.

If any step fails the portal answers 503 and stays closed, saying in German and
English what went wrong and what to do. It never prints SQL: that address is
public and a parent may be the one looking at it. The full database error goes to
the hosting error log.

While maintenance mode is on all of this is skipped, so an operator applying a
migration by hand from a shell cannot race the web request.

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
- **On demand:** `php bin/console.php backup [reason]`, or look at
  **Einstellungen → System**, which lists every copy with its date and size.
- **Restoring** is deliberately not automated. Putting several megabytes of a
  family's data back over a live database is a decision, and phpMyAdmin already
  does it better than anything written here would. The procedure is in
  [INSTALL.md](INSTALL.md#wiederherstellen).
- **When it cannot be written** the update stops. If you have exported the
  database from the panel yourself, create an empty file named `skip-backup` in
  the `storage` folder; the next update proceeds without one and consumes the
  file, so it cannot quietly disable the safeguard for every future update.

A first install writes no backup: an empty database has nothing worth copying.

## Keep these three things separate

On shared hosting this happens by itself: `config/config.php` is not in the
distribution package, so unpacking over the old files cannot touch it, and the
database is untouched apart from the migrations. The table below is the layout
for a server where you keep each release in its own directory.

| Component | Location in the suggested layout | Update behavior |
|---|---|---|
| Source and dependencies | `/srv/badminton/releases/0.1.0/` | Put each new version in a new directory |
| Configuration and encryption key | `/srv/badminton/shared/config.php` | Preserve the file and its `app_key` |
| Students, fields, tariffs, payments, messages | The existing MySQL/MariaDB database | Apply only the new migrations |
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
- Renaming a custom field keeps its numeric ID. Archiving retains its values. A field type with stored data cannot be changed silently.

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
it on from **Einstellungen → System** must not be able to lock you out.

### What re-running is guaranteed to do

The migration ledger records each applied file and its checksum, so the runner
is safe to run repeatedly. Verified behaviour:

| Situation | What happens |
|---|---|
| Opening the portal with nothing new uploaded | One file read, no database work |
| Running `update` again with nothing new | Applies nothing, reopens the portal |
| Uploading an older package over a newer one | Refused by name; the database is not touched |
| An extract that stopped halfway | Refused, naming the files that do not match |
| `storage/` not writable when a migration is pending | Refused; no backup, no migration |
| A migration that removes rows from a guarded table | Refused after the fact; the portal stays closed |
| A new migration added in a later version | Applies only that one |
| A column added later to an existing table | Existing rows get the column's default, never NULL |
| A migration file edited after being applied | Refused by name, with the reason |
| A migration failing partway | Stops at that statement, does **not** record the migration, names the statement number |

The last row is the case that needs you. MySQL cannot roll back DDL, so a
migration that fails at statement 5 of 12 leaves the first four applied and the
migration unrecorded — re-running would start it from the beginning and fail
again on the work already done. The error says exactly which statement stopped
and what to do: restore your backup, or finish that migration by hand and add
its row to `schema_migrations` yourself.

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
`schema_guarded_tables()`. The update refuses one that does, on purpose: no
migration in this project so far has removed a row from a guarded table — 019
takes children off a shared login but deletes none of them — so a count going
down means something went wrong rather than something being cleaned up. A future release that
genuinely has to remove rows — merging duplicates, say — needs that guard
widened deliberately, in the same commit, with the reason written down.

## With release directories: before the maintenance window

Everything from here on describes a server with a shell where releases live in
separate directories and `current` is a symlink. On hosting where you upload
into one folder, the two sections above are the whole procedure.

1. Read the new release’s change notes, including the schema versions it supports.
2. Put the new release in its own directory. Do not unzip it over the running application.
3. Run `composer install --no-dev --prefer-dist --optimize-autoloader` if dependencies are not included.
4. Test the release using a separate database and separate configuration. Do not point the test mail worker at real recipients. The integration suite deliberately creates and deletes test records and must never use the live database.
5. Check the existing hosting recovery arrangement and who can restore it. No database export, restore or automatic backup is performed by this application.

## With release directories: apply an update

Example paths below are a layout template; `0.2.0` is an example of a future release, not an existing version.

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
   ln -s /srv/badminton/shared/config.php /srv/badminton/releases/0.2.0/config/config.php
   php /srv/badminton/releases/0.2.0/bin/console.php migrate
   php /srv/badminton/releases/0.2.0/bin/console.php check > /srv/badminton/shared/after-update.json
   ```

   Stop if a command fails. The migration command does not erase the database or rerun successful migrations. MySQL schema changes may commit individually: a failed multi-statement migration can leave partial changes, so do not assume it rolled back automatically.

4. Compare the before/after student counts, custom-value counts, charges, payments and total cents. Expected changes must be stated in the release notes. Counts alone do not prove completeness: also inspect representative linked students, custom fields, prices, payment periods and conversation ownership in the test deployment.

5. Switch the code using an atomic symlink replacement on the same filesystem:

   ```bash
   ln -s /srv/badminton/releases/0.2.0 /srv/badminton/current-next
   mv -Tf /srv/badminton/current-next /srv/badminton/current
   ```

   This example uses GNU/Linux `mv`. Keep `current-next` unused before running it. Do not use `rsync --delete` over shared data. Reload PHP-FPM or clear its opcode cache through the hosting controls so workers load the new code.

6. Disable maintenance, sign in, open a student, verify their custom fields and payments, and check a student account’s access. Then resume the mail cronjob and send a test mail to your own address.

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

## Planned development sequence

1. **0.1.x:** hosting feedback, accessibility corrections and bug fixes; retain the current data model where practical.
2. **Next feature release:** confirm actual billing rules before implementing recurring charges, including unique billing-period keys to prevent duplicates.
3. **Before any data conversion:** define the mapping, test on a separate copy, report affected rows, preserve source values and verify totals before activation.
4. **Before removing old fields or tables:** use a separate cleanup release with a documented compatibility boundary and an explicit migration review.

The schema updater only ever moves forward: it applies migration files that the
ledger has not recorded, and refuses one whose contents changed after it ran. It
never drops a table, never reverses a migration, and never runs while maintenance
mode is on.
