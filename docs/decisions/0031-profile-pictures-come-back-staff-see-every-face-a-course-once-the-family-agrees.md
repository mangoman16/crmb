---
status: accepted
date: 2026-10-08
---

# 0031. Profile pictures come back: staff see every face, a course once the family agrees

> **Amended on 2026-10-08 by the project manager, from the security review (P4): gd is optional.**
> A host without gd could not install the portal at all, for a feature it can do without, and that
> is worse than a portal without faces. The second kind of extension that §4 and Rejected warned of
> is one flag, on gd's row alone.
>
> - `extension_checks()` marks gd optional. `setup.php` lists it as „eingeschränkt" and installs
>   all the same; Einstellungen → System names it while it is missing.
> - Without gd there are no pictures, and everything else works. Where a picture would be added,
>   the page says instead that photos do not work on this server yet: to an administrator, where
>   to look, to anybody else, that an administrator sets it up (`pictures_unavailable()`). A photo
>   sent anyway is refused in the same words, and the first sign-in goes straight on, without
>   „Dein Foto": a family's banner keeps the welcome, a team member's has none, as before the
>   pictures came back (4 in the note below).
> - `composer.json` keeps `ext-gd` under `require`, on purpose, not under `suggest`. Composer runs
>   on the machine that builds the release, which needs gd anyway, and the check it writes into the
>   release (`vendor/composer/platform_check.php`) tests the PHP version and nothing else, so a
>   portal without gd still starts. On a Git install Composer runs on the host as well: there
>   `bin/update.sh` passes `--ignore-platform-req=ext-gd` (Composer 2.0 or later), which waives gd
>   alone, so a host without gd updates as it installs, and any other missing extension still stops
>   the update, as it stops `setup.php`. The `structure` suite still holds `extension_checks()` and
>   `composer.json` to each other.
> - "Must stay true" gains: gd is the only extension setup installs without, and the only one
>   `bin/update.sh` waives. Test 9 gains, in the `install` suite: without gd, setup lists it and
>   installs, the System page names it, and every other extension still stops setup.
>
> These parts no longer hold: under the title, "one PHP extension the portal now requires"; in §4,
> its title, "Setup refuses to install without it", "anybody else to the club" and the bullet
> "Required, not optional"; in Rejected, "gd as an optional extension"; in Consequences, "gd is a
> PHP extension the portal now requires (§4)" and "that the hosting needs gd"; and in "In plain
> words", that your hosting needs gd. Lines marked *Changed 2026-10-08, by the project manager* say
> so in §4, Rejected and "In plain words", and one in 4 of the note below. Everything else stands.

> **Amended on 2026-10-08: the owner's answers.** The owner, the same day:
>
> - "we are based in austria, use austrian law"
> - "trainer at least should always see them, and the admin"
> - "No legal basis? idk? use is just for my mom, the trainer to see who is in course and help her
>   remember her name"
> - "yea definitely admin and trainer picture too makes it more personal"
> - "yea we can definitely do it that a child can also add a photo, but also so that trainer too"
>
> What they decide:
>
> 1. **Team members have pictures again, as this record first had them.** The owner reversed the
>    project manager's veto. `041_a_team_members_picture.sql` is one statement, alone in its file,
>    and writes no row:
>    `ALTER TABLE accounts ADD COLUMN picture_name VARCHAR(40) NOT NULL DEFAULT '' AFTER name;`.
>    - A team member adds, replaces and removes their own on Mein Konto, through the one writer;
>      nobody changes another team member's.
>    - Staff and families see every team member's picture: `may_see_picture()` takes a person again,
>      a child or a team member, and a team member's is seen by every signed-in viewer. The route's
>      branch takes `kind` (`student` or `account`) beside `id` and `v`.
>    - A student's login never has one: the writer refuses it, and a family's top bar shows the
>      child's.
>    - `delete_login()` deletes a team member's file at once, `upload_references()` names
>      `accounts.picture_name` beside `students.picture_name`, and `demo_clear()` takes the example
>      trainer's.
>
>    These parts no longer hold:
>
>    - under the title, "the project manager kept the pictures to children", "for children" in what
>      it reverses of 0026 §8, and one migration and one setting: there are two of each (041, and
>      `consent_age` below);
>    - in §1, "Only children have one"; in §5, `array $student`, the table's initials for the team
>      and "The team is shown by initials everywhere"; in §6, the route by the student's id alone;
>      in §7 and §9, a writer and deletions for children only; and §10's list, which gains
>      `delete_login()`, Mein Konto and „Dein Foto" among the views, and 041;
>    - in Rejected, "Pictures for staff";
>    - in Consequences, "Schema: 040", "Settings: one" and the `ROADMAP.md` line; in "Must stay
>      true", "a picture is a child's", which now reads: a child's picture is on the student, a team
>      member's on their account, and a student's login has none; and the one writer and "040 is
>      never edited" take in `accounts.picture_name` and 041;
>    - in Tests, 4's team member drawn as initials and 12's "no `accounts` column holds a picture";
>      in "In plain words", the team without pictures.
> 2. **Who says yes for a child is Austrian law's** (§ 4 Abs. 4 DSG): from 14 the child alone,
>    under 14 a person with parental responsibility. The family's login holds the switch either way.
>    - `needs_a_parents_yes(array $student): bool`, in `app/uploads.php` beside `may_see_picture()`,
>      is the one rule: true under the age, or without a birth date, by `student_age()`
>      (`app/groups.php`, loaded before). The switch is then worded for a parent, and
>      `record_consent()` writes the purpose `course_sees_picture_by_parent`; from the age on it is
>      the child's own yes, written as `course_sees_picture`. The rule reads the column alone; the
>      log says who said it.
>    - The age is a setting, `consent_age`: 14 by default, from 13 to 16, the range Art. 8 DSGVO
>      leaves to each country. *The architect's call*: another club's country is then a setting
>      away (`CLAUDE.md`, "Another club could run it too").
>    - **A parent's yes stays valid when the child turns 14, and nobody is asked again.** It was
>      given by whoever could give it at the time; at 14 the child holds the same switch and can
>      take it back at once; and asking again is the asking §8 rules out.
>    - Not chosen: asking again at 14, or ending the parent's yes then, which would take from the
>      course what was validly given; and a column in `consent_log` for who agreed, which the
>      purpose (`VARCHAR(40)`) already says without a migration.
>    - Whether a switch worded for a parent is enough where a child under 14 holds the login with
>      their own address (Art. 8 Abs. 2 DSGVO, reasonable efforts) is part of the check in 3.
> 3. **The legal basis for staff seeing a child's picture**, the project manager's reading,
>    recorded as such and to be checked before real families: the club's legitimate interest in
>    recognising who is in the course, for attendance and the children's safety (Art. 6 Abs. 1 lit. f
>    DSGVO), with the weighing that a child's data asks for written down. The privacy notice says so,
>    and that the family can remove the picture at any time. A family's own upload is their choice;
>    the course seeing a picture stays consent (§8); a team member's picture is their own upload.
>    This and 2 answer §11's two brackets.
>    - Not chosen: consent for staff seeing too. The trainer's sight of a child would then hang on a
>      yes that a family could withhold, and the owner wants the trainer always to see them.
> 4. **The first sign-in offers „Dein Foto",** one optional step right after the password is set on
>    the invitation's page: the child's picture for a family, one's own for a team member.
>    - The invitation's activation lands on it, once, so it is never asked again and needs no column
>      or flag. Saved or skipped („Überspringen"), it goes on to where the activation landed
>      before: a family to its child's page, with the welcome (0023 §5), a team member to
>      `landing_after_sign_in()` (0011). A reset and a confirmed address land as before. This
>      record now amends 0011, 0021 and 0023 too, and their notes say so.
>      *Changed 2026-10-08, by the designer, in the specification of „Dein Foto":* the family goes
>      on without the welcome, on purpose: the step's own title already welcomes. The activation's
>      banner keeps only „Dein Konto ist bereit. Du meldest dich ab jetzt mit {login} an.", so
>      "with the welcome (0023 §5)" above no longer holds.
>      *Changed 2026-10-08, by the project manager, from the code review:* without gd there is no
>      step (the note at the top), and a family's banner keeps the welcome, as 0023 §5 has it. A
>      family is welcomed exactly once, by the step or else by the banner; a team member without gd
>      gets the banner without a welcome, as before the pictures came back.
>    - It posts `picture_save`, the one writer, and offers the picture only, never the course switch,
>      which waits on the child's page (§8).
>    - Every invitation has something to put a picture on: a team member's account, or the child,
>      whom an invitation by address makes in the same activation (0021 §3). The administrator
>      that setup makes has no invitation, and adds theirs on Mein Konto.
>    - A page of its own, or the card's page opened as a step, is the designer's call; either way it
>      is reached from where it belongs, as 0011's check asks of every page.
>    - Not chosen: a card on the page the activation lands on, shown once by a flag in the session.
>      On a family's child's page it would sit beside the picture card, and it is not a step.
> 5. **The trainer still adds or changes a child's picture at any time** (§7), and a child can add
>    their own: both as recorded.
>
> Lines marked *Owner, 2026-10-08* say so in §1, §2, §5, §8, §11, Rejected, Consequences, Tests and
> "In plain words".

> **Accepted** on 2026-10-08. The owner asked for profile pictures back and answered three questions
> the same day (Context). Decided by the architect at the project manager's request, from the working
> tree as read that day; the project manager kept the pictures to children (Rejected). It reverses
> ADR 0026 §8's row for profile pictures, for children, and amends 0016, 0020, 0022 and 0026. 0017
> stays superseded; what this record takes from it is restated here. It needs one migration (040),
> one setting and one PHP extension the portal now requires (gd), and no new file in `app/` and no
> dependency.

## Context

### What the owner said

The owner, on 2026-10-08:

> "customization is very important for the brain of the young, so profile picture would still be
> nice, specially good for the trainer to see faces and not only names to know who people are, so
> for anwesenheit imagine how hard it is for someone to start teaching and remember who people are"

Their answers the same day, each with the option they chose as it was put to them:

1. **Who sees a child's picture:** "Also others in the same course". The option read: "Children in a
   course also see each other's pictures, e.g. in the course group chat. More social for the kids,
   but every family in the course then sees every child's photo, which needs explicit consent from
   each family." The other option was staff and the child's own family only.
2. **Who adds or changes it:** "Family and trainer". The option read: "The family picks or takes one
   on its phone; the trainer can also take one at training (e.g. for a child without one). The family
   always sees it and can replace or remove it."
3. **A child without an address yet:** "Yes, invite later". ADR 0030's placeholder stands as
   written, and that record's note says so.

Round 2 (`8e5ce48`) removed pictures as "photos of children that the club does not need" (0026 §8).
The owner now says the club needs them: the trainer has to learn the faces, at attendance first.

### What the code is today

Read from the working tree on 2026-10-08, not assumed:

- **Nothing draws a picture.** `avatar()` (`app/shell.php`) prints initials. The attendance row
  draws its own initials by hand (`views/_class_attendance.php`), not through `avatar()`.
- **No column holds one.** 035 and 036 dropped `avatar_name` from `accounts` and `students`, and the
  update's step (`prune_uploads(600)` in `database/defaults.php`) deleted the files. The `avatar`
  upload folder now holds only the problem reports' screenshots (ADR 0012), under its old name.
- **What a picture was before.** `avatar_save`, `may_see_account_picture()`, `avatar_for_download()`
  and `avatar_cache_control()` (ADR 0017), in git before `8e5ce48`. A phone's photo was stored as it
  came, cleaned of its metadata, at full size. An iPhone photo is 12 to 24 megapixels and 2 to 5 MB,
  so a list of twenty faces was 40 to 100 MB.
- **Pictures are cleaned without GD.** `store_upload()` stores `image_without_metadata()`'s copy of
  every picture (0022 §4), in plain PHP, and `exif_orientation()` reads which way is up. Nothing in
  `app/` decodes an image. `extension_checks()` (`app/install.php`) lists what setup refuses to
  install without, and the `structure` suite holds it and `composer.json` to each other.
- **A login belongs to its holder; the student is the child.** `replace_login_with_placeholder()`
  gives a student a fresh login and deletes the old one, so that a next holder never reads the old
  holder's chats. A student deleted after their login was set up leaves that login behind on Zugänge
  (`login_goes_with_student()`).
- **Consent has a pattern.** A mail switch is a column on `accounts`, and each change of it is
  `record_consent($accountId, $purpose, $enabled)`: a row in `consent_log` with the privacy notice's
  version (`app/auth.php`). `consent_log` is guarded.
- **Who is in a course's group** is whoever has a current enrolment in a course that is not
  archived: `current_enrolment_sql()` (`app/enrolment.php`), with the archive condition spelled in
  `thread_listed_sql()` (`app/messaging.php`).
- **The rest.** `upload_max_kb` is 4096 by default, clamped to what PHP accepts. `notify()` writes
  nothing for a placeholder. Sign-out no longer sends `Clear-Site-Data`; it did while pictures were
  cached (0017).

## Decision

### 1. Where a picture lives

- **A child's picture is the student's:** `students.picture_name`, not the login's. A login is
  replaced when an invitation is withdrawn or a login deleted (`replace_login_with_placeholder()`),
  and it outlives a child whose login was set up. A picture on the login would be lost in the first
  case and would outlive the child in the second. The student is what describes the child, beside
  the name and the birth date. It exists from the wizard's first step, so the trainer can give a face
  to a child whose family has not signed in yet (answer 3).
- **Only children have one.** Trainers and administrators are shown by their initials, as now: the
  owner asked for the children's faces, and nobody asked for the team's (Rejected). A family's top
  bar shows the child's picture, and a team member's shows their initials. A login with no student
  shows initials: an invitation by address not yet taken up, or a login left behind on Zugänge.
  *Owner, 2026-10-08:* no longer: a team member has a picture too, on their account (the note at
  the top). A student's login still never has one.

### 2. Migration 040

```sql
-- 040_a_childs_picture_and_whether_the_course_sees_it.sql
ALTER TABLE students ADD COLUMN picture_name VARCHAR(40) NOT NULL DEFAULT '' AFTER last_name, ADD COLUMN course_sees_picture TINYINT(1) NOT NULL DEFAULT 0 AFTER picture_name;
```

*Decided 2026-10-08, before 040 ships:* `course_sees_picture` is plain `TINYINT`, the house style
ADR 0015 names for flag columns, not `TINYINT(1)`. `database-engineer` raised it; the project
manager decided it.
*Owner, 2026-10-08:* and `041_a_team_members_picture.sql` adds `accounts.picture_name`, alone in its
file (the note at the top).

- **One statement, alone in its file**, because an `ALTER` cannot run twice (MySQL 8.0 has no
  `ADD COLUMN IF NOT EXISTS`), as in 035 and 036.
- **`NOT NULL DEFAULT ''`, not `DEFAULT NULL`** as the brief suggested. The empty string is how this
  code says "no file" everywhere: `feedback.screenshot_name`, the icon and logo settings, the old
  `avatar_name`; `upload_references()` asks for `<>''`. A second spelling of "none" is how a sweep
  comes to delete a file that is in use. A row written by the previous version reads `''` and `0`:
  no picture, not shown to the course. Nothing is left to guess (`CLAUDE.md`).
- **40 characters.** A stored name is 32 hex digits and `.jpg`; `STORED_UPLOAD_NAME` allows 38 at
  most.
- **Rows and counts.** No row is written or removed, so the guard, which counts `students`, has
  nothing to compare. No PHP step.
- **Order.** This is the next free number after 0030's 039. If 039 lands later, the number moves;
  the order is what matters, as 0026 §11 says.
- **The old pictures do not come back.** Their files went with 035 and 036's update, and they were
  test data. 035 and 036 stay as shipped, and `accounts.avatar_name` stays dropped.
- **Engines.** MariaDB 10.11.14 runs it. MySQL 8.0 stays unverified.

### 3. One kind of upload, one small square, made on the server

- **A kind of its own, `picture`,** in `storage/uploads/picture`. Not the `avatar` folder: that holds
  the screenshots, which only administrators open (0012), and one folder would carry two rules.
- **What may be sent:** JPEG, PNG and WebP (`upload_types('picture')`), from the camera or the
  gallery, which is what a phone hands over. The chat's "camera only for students" (0022 §11.4) is
  about what a child sends to other children. A profile picture is the family's own choice, and the
  family always sees it. The list is narrowed to what this server's gd reads (`imagetypes()`), so the
  form's `accept` never offers what the server refuses. No GIF: its animation would be lost on the
  way to a still. No HEIC, which gd cannot read. An iPhone hands Safari a JPEG when `accept` does not
  name HEIC; that is measured on an iPhone, not assumed.
  *Decided 2026-10-08, by the project manager, from the security audit:* JPEG and PNG only; a WebP
  is refused in words, as a GIF is. gd is the first native decoder a family can reach here, and
  CVE-2023-4863 was in libwebp.
- **How big:** the limit every upload has, `upload_limit()`, from `upload_max_kb`, whose hint names
  profile pictures again. There is no second setting. Whether an iPhone photo fits in the 4 MB
  default is measured; if it does not, the setting is the owner's to raise.
  *Decided 2026-10-08, by the project manager:* the browser shrinks a chosen photo before sending
  it. A 24-megapixel iPhone photo is often over the 4 MB default, and the trainer is on weak 4G in
  the hall. It is `frontend-dev`'s, in `app.js`, and sends a JPEG drawn upright, as the phone shows
  it, because the smaller copy carries no EXIF for the server to turn it by; it is drawn straight at
  the smaller size, never at the photo's own, which a phone's browser may refuse. Without
  JavaScript, or where shrinking fails, the photo goes as it is. The server still enforces every
  limit this record names, for a page without JavaScript: the upload limit, the types,
  `PICTURE_MAX_PIXELS`, the memory check, and the square it makes itself. `TESTING.md` walks both:
  with JavaScript, a 24-megapixel photo arrives; without it, one over the limit is refused in words.
- **What is stored:** one square JPEG of `PICTURE_SIDE` = 320 pixels, cut from the middle of the
  photo, turned upright by its EXIF orientation (`exif_orientation()`, which exists), on white where
  the picture was transparent, then passed through `image_without_metadata()` like every stored
  picture (0022 §4). The phone's photo is never stored. A new picture made from the pixels keeps
  nothing else: no place, no time, no camera, no thumbnail, no second picture.
  - 320, because the largest face drawn today is 40 pt, which a 3× phone draws with 120 pixels; 320
    leaves room for a face of about 100 pt on the child's page. Each is about 20 to 30 KB, so twenty
    faces are about half a megabyte the first time, and come from the browser's cache after (§6).
  - `ponytail:` the square is cut from the middle, with no step to choose it. Its ceiling: a face at
    the edge of a photo is cut off. The way up: a crop step in `app.js`, with the middle as what a
    page without JavaScript gets.
  - gd drops the colour profile, so a wide-colour photo shows a little paler. At 320 pixels this is
    accepted.
- **One way in.** `store_upload()` with the kind `picture` stores `square_picture()`'s JPEG, named
  `.jpg`, where the other kinds store the cleaned original. Its checks, its random name and its write
  stay the one place an upload passes through.
- **`square_picture(string $bytes, string $mime): string`**, in `app/uploads.php` beside
  `image_without_metadata()`. It never ends in a 500 or a warning:
  - it reads the size from the header first, with `getimagesizefromstring()`, which decodes nothing,
    and refuses in words a picture with no size or with more than `PICTURE_MAX_PIXELS`, about 50
    million: a 48-megapixel photo stays under it;
  - decoding takes about 5 bytes a pixel. When that does not fit in what `memory_limit` leaves, the
    request raises its own limit as far as 256 MB where the host allows it, as WordPress does for
    images, and otherwise refuses in words. A 12-megapixel photo needs about 60 MB;
  - a file gd cannot read is a refusal in words, never gd's warning;
  - the decoded pictures are released before the file is written.
  - *Decided 2026-10-08, by the project manager, from the security audit:* it decodes with
    `imagecreatefromjpeg()` or `imagecreatefrompng()`, whichever the type read from the bytes names,
    and never with `imagecreatefromstring()`, which tries every decoder gd has; a header naming
    another type is refused. Both read a file, so it takes the path of the upload `store_upload()`
    has typed, not its bytes, and reads the header with `getimagesize()`. `PICTURE_MAX_PIXELS` is
    24 million, not about 50: a tiny one-colour PNG can claim 50, and a few sessions at once could
    push a shared host over its memory. Without JavaScript a 48-megapixel photo is refused in
    words; with it, the browser has shrunk it first.
- **Twenty an hour** per login, as for `proof_upload` (`throttle('picture', …, 20, 3600)`). Decoding
  is the most expensive thing a request here can ask for.
  *Decided 2026-10-08, by the project manager, from the security audit:* the throttle is counted
  before anything is decoded, and every refusal counts, so twenty refused files use up the hour
  too.

### 4. gd, an extension the portal now requires

gd comes with PHP, and nearly every host has it switched on. It is not a dependency in `CLAUDE.md`'s
sense: no code of anybody else's is added to the release. What it replaces: nothing, since nothing in
the portal decodes an image today. What it costs to patch: nothing beyond PHP's own updates, which
the host applies. If it were abandoned: it is part of PHP's own source, and without it a picture is
refused in words while everything else works.

- **`extension_checks()` gains `gd`** („Profilbilder verkleinern"), and `composer.json` gains
  `ext-gd`, with `composer.lock`'s hash in the same commit. Setup refuses to install without it.
  Einstellungen → System names it when a PHP version switched in the hosting panel has lost it. The
  `structure` suite holds the two lists to each other.
  *Changed 2026-10-08, by the project manager:* setup installs without gd (the note at the top).
- **It is guarded where it is called**, as fileinfo is in `uploaded_file_type()`. Without gd, a
  picture is refused with the sentence that sends an administrator to Einstellungen → System and
  anybody else to the club. Nothing else in the portal changes.
  *Changed 2026-10-08, by the project manager:* the words are `pictures_unavailable()`'s: anybody
  else is told that an administrator sets it up (the note at the top).
- **Required, not optional.** `extension_checks()` is one list, "what the code calls". An optional
  class of extension would be a new idea, for one feature. A host without gd cannot install until it
  is switched on, and setup says how.
  *Changed 2026-10-08, by the project manager, from the security review:* optional after all; setup
  installs without gd, and only pictures are missing (the note at the top).

### 5. Who sees a picture: one rule

`may_see_picture(array $viewer, array $student): bool`, in `app/uploads.php` beside the route that
serves pictures. It is true when:

1. **the viewer is staff:** every child's picture. That is the owner's reason, "for anwesenheit";
2. **the picture is the viewer's own child's** (`students.account_id` is the viewer);
3. **the child's family has said yes** (`course_sees_picture`, §8), **the club allows it**
   (`pictures_in_course`, below), **and the viewer's child is in a running course with the child
   now.** A running course is a current enrolment in a course that is not archived: the condition
   that puts both children in that course's group (0022 §1). It is written once, beside
   `current_enrolment_sql()` in `app/enrolment.php`, and `thread_listed_sql()` uses it too, so a
   group's members and a picture's audience cannot come to disagree.

Otherwise it is false, and nobody signed out sees any picture.
*Owner, 2026-10-08:* and a fourth: **the person is a team member**, whose picture every signed-in
viewer sees. In the table, a team member's picture shows wherever the team does: a family sees it in
a chat between two, and the top bar shows one's own (the note at the top).

- **Not in return.** A family that does not share its child's picture still sees the pictures of
  those who do. Seeing in exchange for showing would put a price on the yes, and a consent with a
  price is not freely given (GDPR Art. 7(4)).
- **A setting, `pictures_in_course`:** a bool in `app/defaults.php`, on by default, which is the
  owner's answer 1. When it is off, rule 3 is false and families are not asked. Their answers stay as
  given and count again once it is on. It is a setting because what the children of a course see of
  each other is a club's policy, and another club may want staff only (`CLAUDE.md`, "Another club
  could run it too"). An operator whose released privacy notice does not say it yet can also keep it
  off until it does (§11).

**Where pictures show:**

| Where | Staff | The child's own family | A family in a running course with the child |
| --- | --- | --- | --- |
| Attendance, the students list, a course's members, Zugänge | every child's picture | – | – |
| The child's page | the picture, and the card: add, replace, remove, switch the course view off | the picture, and the card: add, replace, remove, and the switch | – (they cannot open it) |
| A course's group: bubbles and the member sheet | every child's picture | their child's, and others' by rule 3 | the child's, by rule 3 |
| A chat between two | the child's | – (the team member shows as initials) | an old, closed chat between two children: rule 3 |
| The top bar | their own initials | their child's | – |

- **The team** is shown by initials everywhere, to everybody.
  *Owner, 2026-10-08:* no longer; the team has pictures (above).
- **An administrator reading along** (0022 §11.1) sees every child's picture: she is staff.
- **Viewing as somebody** (0022 §9): the rule asks `current_user()`, who is the person viewed, so the
  view shows what they see. Staff see more than any family, so the view adds nothing.
- **Never** in a mail, a notice in the bell, an invoice, an export, or a page for somebody signed
  out. The problem report's trail keeps an upload's size and type, as it does for every upload. A
  database copy holds the names, never the files.

### 6. Drawing and serving

- **One helper draws a person: `avatar()`.** It draws a child's picture when there is one, its file
  is there (`is_file()`, as `portal_icon()` checks) and `may_see_picture()` holds, and the initials
  otherwise. Every place that draws a person goes through it, the attendance row's hand-made initials
  included. The image has `alt=""`, because the name is beside it, a width and a height, and
  `loading="lazy"` everywhere but the top bar.
- **No query per face.** The rows a page draws carry what the rule needs: the picture's name, the
  switch, the student's id and the role, the chat's person columns (`CHAT_PERSON_COLUMNS`) included.
  Whether a child is in a running course with the viewer's child is asked once per request.
- **One branch serves pictures:** `serve_download()`'s `what=picture`, with the student's `id` and
  `v`. `picture_for_download()` reads only the columns the rule needs and asks `may_see_picture()`.
  It does not go through `student()`: a family that may see a classmate's face may see nothing else
  of the child, and `student()` hands over the whole record. Nobody, no picture and not allowed are
  the same 404, so the address cannot be used to learn which ids exist (0017).
- **Cached as 0017 had it, for its reasons:** `private, max-age=604800` when `v` names the picture
  stored now, and `private, no-store` otherwise; an older address is answered, not refused. Never
  `public` or `immutable`. `send_cache_control()` drops `Expires` and `Pragma`. Twenty faces fetched
  again on every page is the problem this solves, and a week bounds a copy on a borrowed phone.
- **The session lock.** The branch closes the session for writing once it knows who is asking
  (`session_write_close()`), so twenty faces load side by side rather than one after another.
- **Sign-out sends `Clear-Site-Data: "cache"` again,** best effort, as 0017 had it.
- **A picture withdrawn or removed** is drawn on no page from the next page view on. A browser that
  already showed it keeps its copy for up to a week, shown nowhere. That is accepted, and the privacy
  notice says it.

### 7. Who adds, changes and removes

The owner: "Family and trainer".

- **The family**, the child's own login, adds, replaces and removes its child's picture on the
  child's page, from the camera or the gallery.
- **Staff**, trainers and administrators, do the same for every child, at training too: the designer
  places it, the attendance page first. Staff can also switch the course view off (§8).
- **One writer** writes `students.picture_name`, and the actions call it:
  - inside `tracked()`, so „Änderungen" shows who changed it, labelled „Profilbild", with the value
    shown as „Bild" or „–", never as the file's name;
  - **a picture staff put on a child sets `course_sees_picture` to 0 in the same statement,** and
    puts a notice in the bell of the child's login (`notify()`, which writes nothing for a
    placeholder). The course sees a picture only once the family has seen that picture and said yes:
    answer 2, "The family always sees it";
  - the family's own picture leaves its answer as it is;
  - **the old file is deleted at once**, last in the action, as the icon and the logo are
    (`app/actions_settings.php`). A commit that fails after that leaves a row naming a missing file,
    which draws initials (`is_file()`), and the nightly prune is the backstop;
  - `audit()`.
- **The actions:** `picture_save`, with a file or with `remove`, and `picture_consent` (§8), in
  `app/actions_config.php`, in the shell's section where `avatar_save` was. Every action is refused
  while viewing as somebody.

### 8. The family's yes, for the course

- **Asked where the picture is**, on the child's page: one switch, off until the family turns it on,
  with one sentence saying who then sees the picture (the children in the child's courses and their
  families) and that it can be turned off at any time. The words are the designer's. From what age a
  child may say yes alone is the owner's to answer before real families use the portal (§11).
  *Owner, 2026-10-08:* answered by Austrian law: from 14 the child's own yes, under 14 or without a
  birth date a parent's, by `needs_a_parents_yes()` and the setting `consent_age`; a parent's yes
  stays valid past 14 (the note at the top).
- **Only the child's own login turns it on.** Staff can turn it off, for a family that asks on the
  phone or a picture that should not be shown, and never on: nobody says yes in a family's place. It
  is the one value a family writes that staff can only take back, and 0020's "Must not" gains that
  exception.
- **It is recorded three ways**, each for its reader: the column, which the rule reads;
  `record_consent($login, 'course_sees_picture', $on)`, the proof, with the notice's version and the
  time, as for the mail switches; and `tracked()`, with who changed it.
- **It ends** when the family turns it off; when staff put a new picture on the child (§7); when the
  child's login is replaced, because `replace_login_with_placeholder()` sets it to 0 in the statement
  that moves the student to the new login: whoever said yes no longer holds the login, and could no
  longer take it back; and with the child. Removing a picture leaves it as it is. The family's next
  picture is shown as they agreed, and a picture staff put on ends it anyway.
- **Not asked on the activation page.** A yes asked together with setting a password is not a free
  one, and the family has not seen any picture yet. **Not asked again** by a card or a notice: a yes
  asked for until it is given is not freely given either. The switch waits beside the picture.
- **Until then, the course sees initials.**

### 9. The way back, and deletion

- **Replaced:** the new picture shows at once, and the old file is deleted at once.
- **Removed:** deleted at once. The change log says that there was a picture and who removed it,
  and keeps no picture. The way back is a new picture. `CLAUDE.md`'s "destructive actions need a way
  back" gives way here on purpose: a family that removes a photo of their child must be able to trust
  that it is gone, and the photo is theirs to take again. The designer may ask once before removing.
- **The child deleted** (`student_delete`): the file goes at once, with the row. The login left
  behind on Zugänge carries no picture (§1).
- **The example data cleared** (`demo_clear()`): the pictures of the example children go at once.
  `demo_fill()` adds none.
- **The prune.** `upload_references()` names `students.picture_name` for the kind `picture`, so
  whatever an action missed goes at night, and after every update (`prune_uploads(600)` in
  `database/defaults.php`, whose comment gains a line saying the pictures are back, in a folder of
  their own). ADR 0029's gate holds for this kind as for every other.

### 10. Files and load order

No new file, and the load order does not change.

- **`app/uploads.php`:** the kind `picture` in `upload_types()` and `upload_references()`;
  `PICTURE_SIDE` and `PICTURE_MAX_PIXELS`; `square_picture()`; `store_upload()`'s branch for the
  kind; `may_see_picture()`; `picture_for_download()` and the route's branch; the cache rule. They
  need `is_staff()` and `current_user()` from `app/auth.php`, and `current_enrolment_sql()` and the
  running-course condition from `app/enrolment.php`, all loaded before. `avatar()` in `app/shell.php`,
  also loaded before, calls into this file at request time only, and `app/bootstrap.php` says so
  again above the line that requires it, as it did before `8e5ce48`.
- **`app/enrolment.php`:** the running-course condition, beside `current_enrolment_sql()`.
  `thread_listed_sql()` in `app/messaging.php` uses it.
- **`app/shell.php`:** `avatar()`.
- **`app/actions_config.php`:** `picture_save`, `picture_consent` and their writer.
- **`app/actions.php`:** the reset in `replace_login_with_placeholder()`, the file in
  `student_delete`, and sign-out's header.
- **`app/demo.php`:** `demo_clear()`.
- **`app/history.php`:** the labels, and readable values, of `picture_name` and `course_sees_picture`.
- **`app/defaults.php`:** `pictures_in_course`, and `upload_max_kb`'s hint.
- **`app/install.php`:** `gd` in `extension_checks()`. **`composer.json`** and **`composer.lock`.**
- **`database/migrations/040_…`;** `database/defaults.php`: the comment.
- **The views:** the child's page, attendance, the lists, the chat and the top bar, and the
  setting's place, once `ui-ux-designer` has specified them.

### 11. The privacy notice

- **The drafts gain a paragraph** on profile pictures, in German and English, in „3." beside the
  chat's:
  - who adds one (the family; staff, also at training), that it is never required, and what is
    kept: a small square copy without where, when or with what it was taken. The photo itself is not
    kept;
  - who sees it: the club's trainers and administrators; the child's own login; with the family's
    yes, the children in the child's courses and their families, in the course chat;
  - the yes: given and taken back on the child's page at any time, with effect at once. A picture
    staff add is shown to the course only after a new yes. A browser that already showed a picture
    keeps its copy for up to a week, shown nowhere;
  - deletion: a removed or replaced picture is deleted at once, and so is a child's with the child;
  - the legal basis for the course view: consent (Art. 6(1)(a) GDPR), as the owner's answer 1 says;
  - **two brackets, the owner's to answer before real families use the portal:** from what age a
    child may say yes alone, under the law that applies; and on what legal basis staff see a picture
    the trainer took. The project manager puts both to the owner. Until the owner has written those
    sentences, the brackets stay in the drafts and in the notice.
    *Owner, 2026-10-08:* both answered (the note at the top, 2 and 3): from 14, under § 4 Abs. 4
    DSG, held in `consent_age`; and the project manager's reading of lit. f, to be checked before
    real families. The paragraph also says that every signed-in person sees the team's pictures.
- **The chat paragraph** „In der Gruppe sieht man voneinander Namen und Initialen" gains „und, wenn
  die Familie zugestimmt hat, das Profilbild", and the English one its translation.
- **UPDATING**, for an operator whose notice is released: add both before the upload and release
  the notice again, as for the chat paragraph, or keep `pictures_in_course` off until the notice says
  it. Saving changes the „Fassung"; nobody is asked to acknowledge the notice again.

## Rejected

- **The picture on the login.** It would be lost when an invitation is withdrawn or a login deleted
  (`replace_login_with_placeholder()`), and kept after the child is gone, on a login left behind on
  Zugänge. A login is whoever holds it; the picture is the child's.
- **Pictures for staff.** Vetoed by the project manager on 2026-10-08: not asked for. The owner asked
  for the children's faces, so that the trainer learns who is who. It stays one line in `ROADMAP.md`
  until somebody asks; the mechanism allows it later with one column, `accounts.picture_name`, and
  one branch in `may_see_picture()`.
  *Owner, 2026-10-08:* no longer rejected. The owner asked for them: "yea definitely admin and
  trainer picture too makes it more personal" (the note at the top).
- **Only staff and the child's own family see a picture**, the owner's other option. The owner chose
  the course.
- **A picture shown to the course without the family's yes, and a picture staff put on a child
  shown to the course on the family's earlier yes.** The owner's words ask for explicit consent from
  each family, and a yes is for a picture the family has seen.
- **Seeing the others only in return for showing one's own.** A consent with a price is not freely
  given.
- **Asking on the activation page, or asking again by a card or a notice.** §8.
- **The yes on the login (`accounts`)**, which a replaced login would end by itself. A picture staff
  put on has to end it in the same statement that writes the picture, on the student's row, and the
  rule reads both from one row. One line in `replace_login_with_placeholder()` is the cost.
- **A yes per picture, stored as the name of the file agreed to.** A second file name to keep in
  step. Resetting the yes when staff write does the same with one boolean.
- **An emoji, a drawing or a colour instead of a face.** The owner's reason is faces: the trainer
  learning who is who. The status emoji went in round 2 (0026), and the initials already carry the
  colour.
- **Storing the phone's photo, as before, or beside the square.** Twenty phone photos on one page,
  and a file that holds more than a face needs.
- **Two sizes, a thumbnail and a large one.** Two files for one picture, and two names for the prune
  to keep in step. One square of 320 serves every place.
- **Making the square in the browser only.** Every page works without JavaScript, and a family
  without it could not send a phone's photo.
- **Imagick, or an image library through Composer.** Imagick is less common on shared hosting and
  brings ImageMagick's many decoders, each a way in. A library through Composer is a dependency to
  patch, and decoding in PHP is slow.
- **gd as an optional extension.** A second kind of extension, for one feature (§4).
  *Changed 2026-10-08, by the project manager:* chosen after all (the note at the top).
- **The `avatar` folder.** It holds the screenshots, which only administrators open.
- **`DEFAULT NULL`.** §2.
- **A setting for pictures as a whole.** A picture is never required, so a club that wants none adds
  none. The course view reaches other families, so that is the part a club must be able to switch
  off.
- **Leaving a removed or replaced picture to the nightly prune.** The prune runs only when the
  background work does, and "removed" has to mean removed.
- **A way back from removing a picture**, such as a bin or a delay. §9.
- **0017's rule as it was**, under which a family never saw another family's picture. The owner
  chose the course.
- **Bringing the old pictures back.** Their files are gone, and they were test data.

## Consequences

- **Records.**
  - 0016, 0020, 0022 and 0026 are amended by this record, each with a dated note under its title;
    0026 also gains dated lines in §8, §10 and §11.
  - 0017 stays `superseded by 0026`, with a note saying what this record takes from it.
  - 0030's note records the owner's answer 3.
  - `docs/decisions/README.md`: the rows of those six, and this record's.
- **Actions:** two more, `picture_save` and `picture_consent`. **Pages:** none; `download` gains a
  branch. **Settings:** one, `pictures_in_course`. **Schema:** 040. **Dependencies:** none; gd is a
  PHP extension the portal now requires (§4). **Load order:** unchanged.
- **`ROADMAP.md`**, by the project manager: one line under „Later, not scheduled", pictures for the
  team, if somebody asks (Rejected).
  *Owner, 2026-10-08:* no such line: the team has pictures. **Schema:** 040 and 041. **Settings:**
  two, `pictures_in_course` and `consent_age`. **Records:** 0011, 0021 and 0023 are amended too, for
  „Dein Foto", and 0016, 0017, 0022 and 0026 gain a dated line for the team's pictures.
  `ui-ux-designer` adds Mein Konto's card for a team member, the switch's wording for a parent and
  for a child, „Dein Foto", and where `consent_age` sits; `docs-writer`'s drafts say Austrian law
  and the age of 14, the project manager's reading of lit. f, and that every signed-in person sees
  the team's pictures.
- **The owner is told** by the project manager what to test (below), and that the hosting needs gd.
  **Two questions are the owner's to answer before real families use the portal:** from what age a
  child may switch the course view on alone, and on what legal basis staff see a picture the trainer
  took. The project manager puts them to the owner; the privacy drafts carry both in brackets until
  the owner has answered (§11).
  *Owner, 2026-10-08:* answered (the note at the top). What remains before real families is to
  check the project manager's reading of lit. f, with its weighing for children written down.
- **`ui-ux-designer`** specifies before anything is built: the picture card on the child's page for
  both roles, with the switch and its sentence; the faces on the attendance page, and taking a
  picture there; the students list, Zugänge, the chat's list, bubbles and member sheet, and the top
  bar; the bell's notice; the question before removing; and where `pictures_in_course` sits in
  Einstellungen.
- **`database-engineer`:** 040, `tests/migration-data.php`, and the run on MariaDB 10.11.14.
- **`backend-dev`:** §3 to §10 in `app/`, `composer.json` and `composer.lock`.
- **`frontend-dev`:** the views, `.avatar img` in `app.css`, and the attendance row through
  `avatar()`.
- **`qa-tester`:** Tests, and TESTING.md with the walks below.
- **`mobile-tester`:** attendance with twenty faces at 320 and 390, light and dark, as staff; the
  picture card as staff and as a family; the chat as a family with and without the classmates' yes;
  and taking a picture from the attendance page on an iPhone and an Android phone. Whether
  `Permissions-Policy: camera=()` lets the file input's camera open is still unmeasured
  (`ROADMAP.md`), and this depends on it as round 2 does.
- **`security-reviewer`:** the rule and its one running-course condition; the route's 404s and the
  columns it reads; `square_picture()` against huge and broken files; the yes only from the family;
  viewing as somebody; the cache; nothing in a mail.
- **`code-reviewer`:** one writer, one rule, one helper that draws a person, no hand-made initials,
  and the comment in `database/defaults.php`.
- **`docs-writer`:** the privacy drafts (§11), the owner's two brackets included; UPDATING, with §11's
  step, gd, and that the old pictures do not come back; INSTALL, with gd among the extensions;
  CHANGELOG; VALIDATION.md, with the engine and the gd version actually run; TESTING.md with
  `qa-tester`.
- **`devops-engineer`:** the machine that builds the release has gd, because composer checks
  `ext-gd`. Nothing for the owner to run.
- **Consent records**, like every consent's, go with a deleted login (`consent_log` cascades). How
  long they are kept is the question `ROADMAP.md` keeps open.
  *ADR 0032, 2026-10-08:* answered there: a replaced answer goes three years after it was replaced.
- **Must stay true:**
  - a picture is a child's, on the student, and no login carries one;
  - who sees a picture is decided only by `may_see_picture()`, which `avatar()` and the route both
    ask, and a running course is spelled once;
  - `course_sees_picture` becomes 1 only by the child's own login; staff, a picture staff put on, a
    replaced login and the child's deletion only ever end it;
  - a picture is stored only as `square_picture()`'s JPEG, through `store_upload()` and
    `image_without_metadata()`, and no phone's photo is kept;
  - every write of `students.picture_name` goes through the one writer, inside `tracked()`, and
    deletes the file it replaces at once;
  - no picture's address is in a mail, a notice, a PDF or a page for somebody signed out;
  - a picture is served `private`, for a week at its current address and not otherwise, never
    `public`;
  - `gd` is in `extension_checks()` and in `composer.json`;
  - 040 is never edited.

### Tests

Each rule is broken once on purpose and seen to fail; then the whole suite, `tests/mariadb-local.sh`.

1. **040, with data in place** (`tests/migration-data.php`). Before it, students of every kind and
   their logins; after it, the two columns with their types and defaults (`information_schema`), `''`
   and `0` on every row, the counts unchanged, and every other value unchanged byte for byte. Run
   again, it is refused and changes nothing. *Break:* give `picture_name` no default; the previous
   version's insert of a student (`create_student()`) fails.
2. **The rule**, as a table. Staff and administrators see every child's picture. A family sees its
   own child's, and another child's only with that child's family's yes, the setting on, and both
   children in a running course now. Not after one of them has left the course, not once the course
   is archived, not with the setting off, not without the yes, and never a placeholder child's, for
   whom nobody can say yes. Signed out, nothing. *Break:* drop the yes from rule 3; a classmate sees
   the picture.
3. **The route.** A picture the viewer may see comes as `image/jpeg`, with
   `private, max-age=604800` at the current `v`, `private, no-store` at an older one, and no
   `Expires` or `Pragma`. Nobody, no picture and not allowed give the same status and the same body.
   `picture_for_download()` never calls `student()` and reads no column the rule does not need.
   *Break:* serve without asking the rule; another family's child's picture is served.
4. **Drawing.** `avatar()` prints an image exactly where the rule holds and the file exists, and
   initials otherwise, with the address escaped; a team member is drawn as initials for everybody. No
   view draws initials by hand (`structure`, the attendance row included). A page of twenty faces
   makes no query per face. *Break:* put the attendance row's own initials back.
5. **The square** (`square_picture()`, in the `uploads` suite, from bytes). A JPEG, a PNG with
   transparency and a WebP each come back as a 320 × 320 JPEG holding only what
   `image_without_metadata()` keeps: no EXIF, XMP, comment or GPS. A JPEG with orientation 6 comes
   back upright, a pixel of a known colour where it belongs. A header claiming 30,000 × 30,000 is
   refused in words before anything is decoded, with the memory peak unchanged. A truncated JPEG, a
   text file, an empty string, a GIF and HEIC bytes are each refused in words. No case leaves a PHP
   warning. *Break:* skip the size check; the 30,000 × 30,000 case runs out of memory, in a process
   of its own.
   *Decided 2026-10-08, from the security audit:* a WebP is refused in words like the GIF; a
   5,000 × 5,000 one-colour PNG, a few hundred bytes that claim 25 megapixels, is refused before
   anything is decoded; and nothing in `app/` calls `imagecreatefromstring()` (`structure`).
6. **The writer.** A picture staff put on a child sets `course_sees_picture` to 0 and puts one notice
   in the family's bell, and none for a placeholder. The family's own picture leaves the answer. The
   old file is gone the moment the action returns. Removing empties the column and deletes the file.
   „Änderungen" has one line with the actor, labelled „Profilbild", naming no file. The twenty-first
   picture in an hour is refused. *Break:* keep the old file; it is still there after the action.
   *Decided 2026-10-08, from the security audit:* refusals count toward the twenty, and the
   twenty-first attempt in an hour is refused before anything is decoded.
7. **The yes.** Only the child's own login turns it on. Staff are refused turning it on and may turn
   it off. While viewing as somebody, both are refused. Each change writes a `consent_log` row with
   the purpose and the notice's version. After `replace_login_with_placeholder()` it is 0. With
   `pictures_in_course` off, the switch is refused and rule 3 is false. *Break:* let staff turn it
   on.
8. **Deletion.** `student_delete` and `demo_clear()` each delete the files at once. The prune deletes
   a picture nothing names once past its grace, keeps a named one, and deletes nothing during a
   restore (ADR 0029). *Break:* drop the file from `student_delete`; it is still there until the
   prune.
9. **gd.** `extension_checks()` names `gd`, and `composer.json` requires `ext-gd` (the `structure`
   case that holds the two together). Without gd, passed in as `uploaded_file_type()` takes
   `$readable`, a picture is refused with the administrator's sentence or the family's, and nothing
   is written.
10. **Robustness.** The `robustness` suite finds `picture_save` and `picture_consent` from the
    dispatch lists and sends them every class of value as every role: one sentence, never a 500, and
    never a change to the other family's child. `?page=download&what=picture` with arrays, huge and
    negative ids and the other family's ids answers 404 in words. The file itself cannot be reached in
    process (`is_uploaded_file()`), so test 5 covers the bytes, and one real upload goes through the
    router out of process, as the install suite runs setup.
11. **Nothing leaves.** No mail, notice text or PDF holds a picture's address: in the `structure`
    suite, `what=picture` appears only in `avatar()` and the route. Sign-out sends
    `Clear-Site-Data: "cache"`.
12. **`structure`.** `students.picture_name` is written only by the writer; `course_sees_picture` is
    set to 1 in one place, which refuses staff; no `accounts` column holds a picture;
    `upload_references()` names `picture`, and the case that keeps a test run out of the portal's
    folder lists it.

*Owner, 2026-10-08:* 4's team member drawn as initials and 12's "no `accounts` column holds a
picture" no longer hold. Added, each broken once:

13. **041, with data in place:** `accounts.picture_name` with its type and default, `''` on every row,
    the counts unchanged; run again, refused. A team member's picture is seen by every signed-in
    viewer and by nobody signed out; a picture on a student's login is refused; `accounts.picture_name`
    is written only by the writer. *Break:* let the writer take a student's login.
14. **A team member's file goes** with `delete_login()` and with `demo_clear()`, at once, and the
    prune keeps a named one. *Break:* drop the file from `delete_login()`.
15. **The age.** `needs_a_parents_yes()` is true the day before the 14th birthday and without a birth
    date, false on the birthday, and follows `consent_age`; the yes is logged as
    `course_sees_picture_by_parent` or as `course_sees_picture` to match; a parent's yes still counts
    the day the child turns 14, and nothing asks again. *Break:* treat a missing birth date as 14.
16. **„Dein Foto".** The invitation's activation lands on it once, for a family and for a team
    member; „Überspringen" lands where the activation landed before; a later sign-in and a reset
    never show it; it offers no course switch. *Break:* land a reset on it.

**TESTING.md**, on a real iPhone and an Android phone, with test data only: a photo from the gallery
and one from the camera each arrive as an upright JPEG; a 24-megapixel photo against the 4 MB limit;
the trainer photographs a child without an address from the attendance page; two family logins in one
course, with and without the yes, and the yes taken back; a removed picture is gone from
`storage/uploads/picture`.

## In plain words, for the owner

- Children's pictures are back. The trainer sees every child's face: at attendance, in the students
  list, on the child's page and in the chats.
- A family adds its child's picture from the phone, from the gallery or the camera, and can change or
  remove it at any time. The trainer can take one at training, for a child without one; the family
  sees it straight away and can change or remove it.
- Other children in the course, and their families, see a child's picture in the course chat only if
  the child's family switches that on. It starts off. A picture the trainer took is shown to the
  course only after the family has switched it on for that picture. A family that keeps it off still
  sees the others.
- The team has no pictures for now: families see you and the trainer by your initials, as today. If
  you want team pictures later, say so; it is a small addition.
  *Owner, 2026-10-08:* you asked for them: you, the trainer and the other staff each add your own on
  Mein Konto, and everybody signed in sees them.
- The portal keeps a small square copy, without where or when it was taken; the phone's photo itself
  is not kept. A removed picture is gone at once, and so is a child's picture when the child is
  deleted.
- Your hosting needs PHP's „gd" switched on; Einstellungen → System says so if it is not. A setting
  lets you switch the course view off for the whole club.
  *Changed 2026-10-08, by the project manager:* the portal installs and runs without „gd" too;
  only pictures need it, and Einstellungen → System says so while it is missing.
- Before real families use the portal, the privacy notice gets a paragraph about pictures, and you
  answer two questions for it: from what age a child may switch the course view on alone, and on what
  legal basis staff see a photo the trainer took. The project manager will ask you.
  *Owner, 2026-10-08:* answered. Austrian law: a child of 14 or over says yes alone, and a parent says
  it for a younger child, and that yes stays when the child turns 14. Staff seeing the pictures rests
  on the club's interest in knowing who is in the course; that reading is checked before real
  families. Whoever sets their password from an invitation is offered „Dein Foto" once, and can skip
  it; you set the portal up yourself, so yours is on Mein Konto.
