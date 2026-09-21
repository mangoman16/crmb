# Changelog

## 0.6.0 — unreleased

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
