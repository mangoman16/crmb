---
status: accepted, amended by 0026
date: 2026-10-02
---

# 0022. Course groups, direct messages, and a status emoji

> **Superseded in part by ADR 0026 (2026-10-07).** Copying a course, „An mehrere schreiben" and
> profile pictures are gone. These parts no longer hold:
>
> - in §3, `duplicate_record()` giving a course copied with „Kopieren" its group;
> - in §5, everything about `bulk_send` and „An mehrere schreiben";
> - in §8, the pictures in the chat list and beside others' bubbles, and "Pictures follow ADR 0017":
>   everybody is shown by their initials;
> - in §9, `bulk_send` as the example; the rule it illustrates stands;
> - in §11.4, "Profile pictures keep GIF";
> - in Consequences, `duplicate_record()` beside `demo_fill()`.
>
> The owner gave their word on 0026's † the same day ("Remove all"), so the dot, the chosen status
> and the emoji go too: §6's first paragraph (the member sheet stays), §7 whole, in §8 the emoji and
> the comparison with `presence_touch()`, the refusals of `status_emoji_save` and `presence_save` in
> §9, and the emoji and the dot in Consequences and "In plain words". §10's line for 027 describes a
> column that 035 drops; 027 itself stays as shipped. Everything else stands, the rest of §11 included.

> **Accepted by the owner on 2026-10-05, and amended the same day (§11).** She answered the three
> questions under Consequences and asked for a chat that is "the absolute basics". These parts no
> longer hold as written:
>
> - in §1, the `direct` row of the table: administrators read every `direct` chat, and between two
>   students nobody writes in one any more. Also "Student-to-student chats are otherwise unchanged
>   in this round";
> - in §2, that an administrator reads only `staff_direct` chats beyond her own (she reads every
>   conversation), the name „Alle Direktchats" (now „Alle Einzelchats", holding every two-person
>   chat she is not in), and „a `direct` chat keeps its line saying it is private";
> - in §4, "Whether it should be refused instead is the owner's to decide": she has decided, and
>   such a picture is kept as it came;
> - in §8, "with attachments and voice notes as today": a message is text and photos only. Also,
>   opening a chat marks it read only for a reader whose list the chat is in;
> - in Consequences, the privacy line "administrators can read chats between students and staff"
>   (they read every chat), and "For the owner": answered.
>
> Everything else stands.

> **Updated 2026-10-05**, after the second review round, to say what is built: photos are cleaned by
> a list of what to keep (§4), the view through somebody's eyes writes nothing and quotes nothing
> (§9), and a chat being marked read when it is opened is recorded as the exception to ADR 0003 that
> it is (§8).

## Context

The owner, on 2026-10-02: the chat "should basically be more like whatsapp … there should be groups
for courses, and children in a course will be in it, automatically, cannot leave … trainer and admin
see all groups"; students write directly to trainers or admins; a student's online status is "always
… there, and active"; anybody may pick an emoji, "fun for children too". The portal is in testing,
with no real families. ADR 0021 lands first, with migration 024.

Today a conversation is `staff` (one family and every member of staff) or `direct` (two accounts after
an agreed request, unreadable by staff). Who may read is written three times: `may_read_thread()` and
the SQL of `threads_for()` and `unread_thread_ids()`. `threads.account_id` is `NOT NULL`.

## Decision

### 1. Four kinds of conversation

| `threads.kind` | Who is in it | Who reads it | Who writes |
| --- | --- | --- | --- |
| `course` | one per course, by `threads.class_id` | all staff; a student currently enrolled in the course while it is not archived | the same, while not archived |
| `staff_direct` | one student and one trainer or administrator | the two, and every administrator | the two |
| `direct` | two accounts, as today | the two | the two |
| `staff` | the old family-and-all-staff desk | as before | nobody: closed |

- A group's members are never stored. They are read from `class_students` with
  `current_enrolment_sql()` whenever asked, so leaving the course is leaving the group, and no code
  keeps two lists in step. Only `join_thread()` writes `thread_participants`, and only
  `direct_thread()` calls it.
- The kind is fixed when the conversation is made, and `pair_kind()` alone says which: a student and
  a staff member make `staff_direct`, anything else `direct`. A later change of role never changes who
  reads it. The empty chat shown before a first message asks `pair_kind()` too, so it says what the
  chat will be.
- One conversation per pair: `direct_thread()` locks both accounts' rows before it looks.
  Student-to-student chats are otherwise unchanged in this round.
- `threads.account_id` is the account whose deletion deletes the conversation: the student in
  `staff_direct` and `staff`, the starter in `direct`, nobody (`NULL`) in a group. A trainer's deleted
  login never takes a child's conversation with it.

### 2. The rule is written once, in SQL

`thread_listed_sql()` is "in my chat list"; `thread_readable_sql()` adds that staff read an archived
course's group and administrators read every `staff_direct`. The list, the unread badge and
`thread_record()` all use them, so they cannot disagree. `may_read_thread()` and `threads_for()` go.

- An administrator's list and badge hold her own chats and the groups; every trainer's chats there
  would be unread messages meant for somebody else. She opens those under „Alle Direktchats", which
  is read through `thread_seen_sql()` too: what she may open, narrowed to the `staff_direct` chats she
  is not in. Built on the rule rather than beside it, it follows any change to who reads those chats,
  and the narrowing of §9.
- A `staff_direct` chat says „Administratoren können diese Unterhaltung lesen."; a `direct` chat
  keeps its line saying it is private.

### 3. A course has its group from the moment it exists

`course_group_thread()` returns the group, making it if missing with
`INSERT … ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)` on the unique `class_id`: one answer however
often, and however many requests at once, ask. `class_save` calls it when it creates a course, and
`duplicate_record()` when „Kopieren" copies one, inside the copy's transaction: a copy that fails
leaves no group behind, and one that succeeds does not wait for the next update to get its own.
`database/defaults.php` calls `course_groups_fill()` after every update, which gives courses made
before 025 theirs. Example courses get one too, so the owner can try the chat before real families
arrive; it goes when the example data is cleared, with its course (the foreign key cascades). Nothing
creates a group while a page is read (ADR 0003).

- An archived course keeps its group, which leaves the list and takes no messages until the course is
  restored.
- `class_delete` refuses a course whose group has messages and says to archive it instead; an empty
  group goes with the course. Deleting a course must not delete what children wrote.

### 4. Group messages

- No e-mail and no notification entry, only the unread badge: a group must not fill inboxes.
- A photo reaches every child in the course, so no stored picture says where it was taken.
  `store_upload()` stores the copy `image_without_metadata()` returns, for every picture of every kind
  of upload. Each format has a list of what it keeps, not of what it loses, because the next phone
  will write a kind of metadata that no list of losses names yet:
  - a **JPEG** keeps its frame, tables and scans, its colour profile (APP2 `ICC_PROFILE`), and of
    its EXIF only which way is up, in a block of its own, so an iPhone photo does not lie on its
    side. Two headers keep only what a decoder reads: the JFIF header (APP0) its 14 bytes, with the
    thumbnail's width and height set to 0, because the thumbnail an editor leaves behind them can
    show the photo from before it was cropped; Adobe's colour transform (APP14) its 12 bytes. A
    header too short to hold them goes. GPS and the rest of EXIF, XMP, IPTC, comments and every
    other APPn segment go. So does everything after the end of the picture, where a phone puts a
    second picture (MPF) or a motion photo's video. An HDR photo's gain map is such a second
    picture, so an HDR photo loses it and shows at normal brightness;
  - a **PNG** keeps `PNG_KEPT_CHUNKS`: IHDR, PLTE, IDAT, IEND, tRNS, gAMA, cHRM, sRGB, iCCP, sBIT,
    bKGD, pHYs, its animation (acTL, fcTL, fdAT) and how its colours are meant (cICP, mDCV, cLLI). No
    text, no EXIF, no time, nothing private to some program, nothing after IEND;
  - a **WebP** keeps `WEBP_KEPT_CHUNKS`: VP8X, its picture (VP8, VP8L, ALPH), its animation (ANIM,
    ANMF) and its colour profile (ICCP), with VP8X no longer saying that EXIF or XMP follow, and
    nothing past the length its RIFF header gives;
  - a **GIF** is kept as it came: no camera or phone writes a photo as one.

  It is plain PHP, no GD. A picture that cannot be read as far as its image data — a JPEG that goes
  wrong before its first scan, a PNG or WebP that stops short of what its chunks say — is still
  stored as it came, metadata and all, as it always was. Whether it should be refused instead is the
  owner's to decide (Consequences); until she does, nothing changes for it. A JPEG cut off inside
  its picture data keeps what was cleaned before the cut.
- Staff can remove any group message (`message_remove`): everybody sees „Nachricht entfernt", its
  attachments are no longer served, and staff can put it back, so a mistake has a way back. The audit
  log records who did it.
- A child who joins a course reads the group's earlier messages too: membership is read, not stored.

### 5. The desk conversations close

Existing `staff` threads stay readable by the same people, with no composer; nothing is deleted, so
the update's row-count guard is unchanged, and nothing makes a new one. `bulk_send` posts into each
student's `staff_direct` chat with the sender, the subject as the first line, and is renamed
„An mehrere schreiben", because „Gruppe" now means a course chat. `demo_fill()`'s example is a
`staff_direct` chat, made by `direct_thread()` like a real one: built by hand, it once lacked its
participants, and the example family was told they had no chats. 025 turns existing `direct` chats
between a student and staff into `staff_direct`; otherwise an adult keeps a channel with a child that
the club cannot see.

### 6. Everybody has an online dot

`presence_visible_to()` is true for every signed-in viewer. `presence_details_visible_to()` is
`is_staff()` and guards last-online times, history and `presence_line()`: students see dots, never
times. Dots appear in the chat list, a group's member list, the conversation header and on one's own
avatar. A student's status is always automatic; staff keep the three choices, without the line that
explained them.

A group's member sheet shows staff every child enrolled, marking who has no login yet. A child sees
the people who read the group and only a number for the others: a classmate whose family is not in
the portal is not named to them.

### 7. A status emoji

- `accounts.status_emoji` holds a key from `status_emojis()` in `app/presence.php`, or `''`. Sixteen,
  each one character with a German and an English name, as `ui-ux-designer` chose them:
  🏸 💪 🏆 ⭐ 😀 😎 🤔 😴 🎉 🍀 🚀 📚 🐱 🐶 🦊 🦄. Nothing about health — 🤒 was proposed and left
  out: whether a child is ill is not the whole course's business. `status_emoji()` ignores a stored
  value outside the set.
- Chosen in the account menu: one form, a button per emoji and „Keins", posting `status_emoji_save`
  (beside `presence_save`). Refused while viewing as somebody else; not tracked, like the status.
- `chat_name()` in `app/ui.php` prints name and emoji wherever the chat names a person.

### 8. The chat screen

Groups first, then direct chats, then closed ones, each with picture, name and emoji, last message,
time and unread badge. Bubbles: your own on the right, others on the left with name and picture in
groups. The composer is pinned at the bottom, with attachments and voice notes as today. Pictures
follow ADR 0017: another child shows as coloured initials. New messages show when the page is opened
or reloaded. It works without JavaScript, at 320 px, with 44 px targets; `ui-ux-designer` specifies it.

**Opening a chat marks it read: a named exception to ADR 0003.** A GET otherwise never changes data.
`views/messages.php` calls `mark_thread_read()` when it shows a conversation, because reading it is
what makes it read: a „Gelesen" button is a step no messenger asks for, and a badge that stays after
the chat was read is one she learns to ignore. Like `presence_touch()` (ADR 0015), which notes on
every request that somebody was here, it writes only what the request itself is — that this reader
has seen this chat — and nothing else: one `thread_reads` row, in one statement, which is its own
transaction and only ever moves forward (`GREATEST`), so a reload changes nothing. A browser that
fetched a chat ahead of time would mark it read unseen; nothing here asks one to, and the cost would
be a badge, not data. It is skipped while staff view as somebody (§9): the child has not read it.
Nothing else in the chat writes on a GET.

### 9. Looking through a child's eyes

Staff may view the portal as a family sees it, and an administrator as a trainer does. That view
shows only what both may read: `thread_seen_sql()` narrows the reader's rule by the impersonator's, so
a trainer does not read a child's chat with another trainer, or with another family, that way. A chat
in that list still opens by its id, to read. Nothing is written in that view, and nothing it shows
quotes what it hides:

- **Every action of `dispatch_messages()` is refused**, before its `switch` rather than by a list of
  actions the next one added would be missing from. An administrator viewing as a trainer would
  otherwise write as her: `bulk_send` alone posts into every chosen child's chat. `may_write_thread()`
  is false too, and the page offers no composer, no „Neue Nachricht" and no „Nachricht entfernen".
- **`notifications_read` is refused:** „Alle gelesen" would mark the family's notices read before they
  saw them. `status_emoji_save` (§7) and `presence_save` (ADR 0015) are refused as well.
- **The bell shows only the kinds `notice_kinds_shown_while_viewing()` lists**, asked by
  `notifications_seen_sql()` for the pane and its count alike: payments, dates (`schedule`),
  requests, and problem reports only when the person looking, not the bell's owner, is an
  administrator. A chat notice, and a contact request, quotes the message, and would hand staff the
  words that `thread_seen_sql()` keeps from them in the chat. As with photos (§4) the list says what
  is shown, not what is hidden, so a kind added later stays hidden until somebody has decided it
  quotes nothing private.
- **„Neue Nachricht" and `?with=` give one answer whoever is asked for:** no requests, no contacts and
  no other families listed, and no difference between a chat that exists and one that does not.
  Before, `?with=` said „nicht gefunden" where the child had a chat this view did not show and opened
  an empty one where they had none, which told staff whom a child writes to.
- **Opening a chat does not mark it read** for the child (§8).

### 10. Migrations 025, 026, 027: one `ALTER` each

MySQL 8.0 has no `ADD COLUMN IF NOT EXISTS` and ADR 0021 §6 rules out MariaDB-only SQL, so a file with
two `ALTER`s that stopped between them could never start again.

- `025_a_group_for_every_course.sql`: the `UPDATE` of §5, which can run twice, then one
  `ALTER TABLE threads` that makes `account_id` nullable and adds
  `class_id BIGINT UNSIGNED NULL DEFAULT NULL`, `UNIQUE KEY thread_of_course (class_id)` and
  `CONSTRAINT thread_course FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE`.
- `026_staff_can_remove_a_group_message.sql`: `messages.removed_at DATETIME NULL DEFAULT NULL`.
- `027_a_status_emoji.sql`: `accounts.status_emoji VARCHAR(16) NOT NULL DEFAULT '' AFTER presence`.

### 11. Amended 2026-10-05: who reads, and a chat cut to its basics

The owner, on 2026-10-05, answering the questions under Consequences:

- "administrators can read everything";
- "chats should be readable by admin if needed in case there are problems";
- asked whether an administrator reading somebody's chat should be recorded: "no it should not be
  recorded if an admin does something";
- "file uploads (images) can only be done via camera for students, admins and trainers can do all";
- "make the database change if you deem it necessary".

In her next message: "the chat function should be the absolute basics that will be enough, nothing
too complex. and nothing that risks infections or injections. the program is ai made and should keep
the highest security standards".

The project manager read "absolute basics … nothing that risks infections" as **text and photos
only**, and her original request (2026-10-02, Context) as naming only two kinds of chat: between a
student and staff, and the course groups. This section records that reading as decided. The owner has been told that closing the chats between students can be undone. The screens are the designer's
(specification of 2026-10-05, `docs/design/2026-10-05-accounts-and-chat-screens.md` §7); this section gives the rules they show.

#### 11.1 An administrator reads every conversation

- **The rule.** `thread_readable_sql()` gives an administrator every thread, of every kind. For a
  trainer and for a family it is unchanged.
- **Her list and badge are unchanged:** her own chats, the groups and the closed desk threads. A chat
  she is not in never shows in her list and never counts as unread for her.
- **„Alle Direktchats" becomes „Alle Einzelchats".** It is still read through `thread_seen_sql()`:
  what she may open, narrowed to the two-person chats (`staff_direct` and `direct`) she is not in.
  That now includes chats between two members of staff and the earlier chats between two students.
  The designer's sections are „Schüler und Team", „Im Team" and „Zwischen Schülern (geschlossen)".
  Each row shows both names and no unread count.
- **She only reads.** She never writes in a chat she is not in. `may_write_thread()` asks whether she
  is in it, as it does today. Where the composer would be, the chat says that only the two of them
  write.
- **The view through somebody's eyes (§9) is unchanged.** `thread_seen_sql()` still narrows by both
  rules. An administrator viewing as a trainer sees what the trainer may read.
- **What a chat says at the top**, in the designer's words:
  - a `staff_direct` chat, and a `direct` chat between two members of staff: the two write, and the
    club's administrators can read along;
  - a chat between two students: chats between students have closed, what is there can still be
    read, and the child is pointed to the coach or the course group.

  No chat says it is private any more.

#### 11.2 Nothing is recorded about her reading

- **No read mark for her.** Opening a chat writes `thread_reads` only for a reader in whose list the
  chat is: `thread_seen_sql($user, true)`, the rule the list and the badge already use. When an
  administrator opens a chat she is not in, nothing is written:
  - no `thread_reads` row, whose `updated_at` would say when she read it;
  - no audit entry and no notice;
  - nothing the two people in the chat can see.

  §8's exception to ADR 0003 becomes narrower than it was.
- **What the portal does not control.** The web server keeps its own access log of the addresses
  requested, for every page. The portal does not write that log, read it or show it. Saying "nothing
  is recorded" without this sentence would claim more than is true.
- **Only her reading is unrecorded.** A message she writes in her own chats, a group message taken
  down, and a view through somebody's eyes (`impersonation.started`) are recorded as before.

#### 11.3 Chats between two students close

- **No new chat between two students.** `may_message()` lets a student write to staff only, and staff
  to anybody, so `direct_thread()` refuses a pair of students. `pair_kind()` is unchanged.
- **The contact requests go:**
  - the cases `contact_request` and `contact_decide`;
  - `request_contact()`, `decide_contact()`, `contact_requests_for()` and `pending_contact_count()`;
  - the agreed-contacts half of `contacts_for()`;
  - the picker's „Kinder", „Möchte dir schreiben" and „Jemand anderen fragen", and the „neue
    Anfragen" chip.

  A student's „Neue Nachricht" lists the coaching team only.
- **Existing chats between two students stay, closed.**
  - The two and every administrator can still read them.
  - They take no messages: `may_write_thread()` lets a `direct` chat take a message only from a
    member of staff who is in it. `pair_kind()` fixed each pair's kind when the chat was made, so a
    `direct` chat with staff in it is a chat between two members of staff, which keeps working.
  - In a student's list they move under „Frühere Unterhaltungen", beside the closed desk threads.
- **Nothing is deleted:** not the chats, not their messages, not the `contact_requests` rows. The
  update's guard is unchanged. Opening chats between students again is a change of code, and the
  table is still there for it.

#### 11.4 Text and photos, nothing else

- **What a message may hold.** A message is text, a photo, or both.
  - No voice notes, no PDFs, no other files: a file is how malware reaches a phone.
  - A voice recorder needs the microphone, and it brings a player for a format the server cannot
    look inside.
- **Which photos, by who sends them.** One function decides it, `message_upload_types(array $user)`.
  `store_upload()`'s check and the composer's `accept` attribute both ask it, so the form never
  offers what the server refuses.
  - **Staff:** JPEG, PNG and WebP, from the camera or the gallery.
  - **Students:** JPEG only, from the camera. The student's photo button carries
    `capture="environment"`, which asks the phone to open its camera rather than its gallery.

  The designer's specification assumed JPEG, PNG and WebP for everybody (its A9). For students this
  record narrows that to JPEG. The owner said "only via camera for students", and JPEG is the type
  the server can hold to (next bullet).
- **What the server can check, plainly.** It reads the type from the bytes, as it does today, and
  refuses anything else.
  - It cannot tell a photo taken just now from one chosen out of the gallery.
  - `capture` is a request to the browser. A desktop browser ignores it, and a JPEG picked another
    way gets through.
  - "JPEG only" is the part that holds. It is what a phone camera hands over, and it keeps out
    screenshots (PNG), animations and documents.
  - It is measured on an iPhone and an Android phone, not assumed, which `accept` value makes the
    phone open the camera **and** hand over a JPEG. So is whether the response header
    `Permissions-Policy: camera=()` lets the file input's camera open. A phone that hands over HEIC
    gets a refusal it can act on.
- **No GIF in a chat, for anybody.**
  - No camera writes one.
  - It is the one picture `store_upload()` keeps uncleaned (§4).
  - An animation is not a photo.

  Profile pictures keep GIF.
- **`upload_types()` names every kind and refuses the rest.** Its `default` branch today gives any
  kind nobody named the widest list there is. It becomes the message kind by name, and an unnamed kind
  is refused. A list that fails open is how the next upload kind would have taken PDFs and audio
  without anybody deciding it.
- **The voice recorder goes**, from the composer and from `app.js`. `attachment_seconds` is no longer
  read. The response header becomes `microphone=()`, and its comment, which says the microphone is
  there for voice notes, goes with it.
- **Voice notes and files sent before stay**, stored and shown as they are. Deleting them would delete
  what somebody sent; nobody can add a new one.
- **The words stay plain text**, printed through `e()`:
  - no HTML and no Markdown;
  - no address turned into a link;
  - the server never fetches an address somebody wrote, so there are no link previews.

  Each of these would be a way for one child's message to do something on another child's phone.

#### 11.5 Pictures that cannot be read through

The third question under Consequences is answered: **such a picture is kept as it came**, as §4
built it. A student's picture is a camera JPEG (11.4), which the cleaner reads through.

#### 11.6 No migration

Who reads and who writes are rules in code. Every chat keeps the kind it has. On "make the database
change if you deem it necessary": none is needed.

## Rejected

- **Group members as `thread_participants` rows.** A second copy of enrolment, and sync code that
  will one day miss a case.
- **Making a group the first time it is opened.** A write on a GET, and not one a reader would
  expect from reading.
- **One migration with three `ALTER`s.** It cannot restart.
- **Reusing `staff` for the new chats, or turning desk threads into them.** Every trainer reads
  `staff`, and a desk thread has no single member of staff.
- **Deleting desk threads.** Data lost and the guard widened, for nothing.
- **Deleting or blanking a removed message.** No way back, and a child's words gone without trace.
  A `removed_by` column is not needed: the audit log has it.
- **Storing the emoji itself, or free text.** Typed text is a moderation job; a key is ASCII.
- **`ON DELETE SET NULL` on `class_id`.** A group nobody can find.
- **Live updates.** A socket needs a server process she cannot run; polling makes every open phone
  ask the server.
- **A list of what to take out of a photo.** It is right until a phone writes something it does not
  name, and then it keeps that without anybody noticing. A list of what to keep fails safe.
- **Keeping what follows a JPEG's end.** The second picture or the video there has metadata of its
  own, and cleaning it means a second cleaner inside the first. Normal brightness is the price.
- **Refusing, in `dispatch_messages()`, a list of actions while viewing as somebody.** The next action
  added is the one that would not be on it.
- **Leaving chat notices out of the bell by name while viewing as somebody.** As with photos, the
  next kind that quotes something private would be shown before anybody noticed.
- **A „Gelesen" button, or marking read by a POST from JavaScript.** A step nobody expects, or a
  badge that never clears without JavaScript. Naming the one exception keeps ADR 0003 checkable.
- **Answering `?with=` truthfully while viewing as somebody.** „Nicht gefunden" for a hidden chat and
  an empty one otherwise is an answer about a child's private chats.

*Added on 2026-10-05, with §11:*

- **Recording when an administrator reads a chat**, in the audit log or as „von der Administratorin
  gelesen" in the chat. The owner said no.
- **Marking a chat read for her when she opens one she is not in.** It is a dated row saying that
  she read it, which is the record she declined. Her badge never counts those chats anyway.
- **Keeping voice notes or PDFs for staff only.** A child receives what staff send, and a file
  infects whoever opens it. The basics she asked for are words and pictures.
- **Requiring a camera's EXIF (make, model, a recent time) to prove a student's photo is fresh.** Any
  program writing a file can write any EXIF, and the cleaner throws it away anyway (§4). It would be
  a check that proves nothing.
- **Accepting PNG and WebP from students** (the specification's A9). No phone camera writes either.
  Allowing them would let screenshots and edited pictures through, which is what "only via camera"
  rules out.
- **Deleting the chats between students, or the contact requests.** Data lost and the guard widened.
  Closing them is the reversible half of what she asked.
- **Giving the closed chats between students a kind of their own by migration.** Who may write is a
  rule the code already knows from the writer's role and the pair's fixed kind. A data migration is
  one more thing to test against data, for nothing.
- **Hiding voice notes and files sent before.** They are from testing, with no real families. Hiding
  them is a half-deletion with no rule for when it ends.
- **Refusing a picture that cannot be read through.** The owner chose to keep such pictures as they
  came.
- **Making addresses in messages clickable, or showing a preview of them.** A link is how a message
  carries something other than words, and a preview is the server fetching what a child typed.

## Consequences

- **Actions:** two more, `message_remove` and `status_emoji_save`. No new file; load order unchanged.
  `mark_thread_read()`, `unread_thread_ids()` and `unread_count()` move to `app/messaging.php`.
  `duplicate_record()` and `demo_fill()` call `course_group_thread()` and `direct_thread()` there,
  in a file loaded after theirs, at request time only; `app/bootstrap.php` says so where it requires
  `messaging.php`.
- **Privacy notice:** children in a course see each other's names, initials, emoji and dot;
  administrators can read chats between students and staff. `docs-writer` drafts, the owner releases.
- **Must stay true:**
  - nothing stores who is in a group, and only `course_group_thread()` makes a `course` thread;
  - every way a course is made calls `course_group_thread()` — the `structure` suite finds each one
    and fails a new one until it does;
  - only `direct_thread()` calls `join_thread()`, and only `join_thread()` writes
    `thread_participants` — the `structure` suite checks both;
  - which kind a pair makes is decided only in `pair_kind()`;
  - who may read is decided only in `thread_listed_sql()` and `thread_readable_sql()`, narrowed only
    by `thread_seen_sql()`, through which the list, „Alle Direktchats", the badge and
    `thread_record()` all read;
  - nothing in `dispatch_messages()` runs while staff view as somebody, and the bell then shows only
    the kinds `notice_kinds_shown_while_viewing()` lists, which is the one copy of that list;
  - the one write on a GET in the chat is `mark_thread_read()`, and never while viewing as somebody;
  - a group message sends no mail;
  - every picture `store_upload()` stores is `image_without_metadata()`'s copy, and each format's list
    says what it keeps, never what it loses;
  - only `status_emoji_save` writes `status_emoji`; 025–027 are never edited.
- **For the owner.** This record is `proposed` until she approves the schema change. Three questions
  are hers, built as below meanwhile; no answer needs a migration.
  1. Should administrators read chats between a student and a trainer? Built: yes.
  2. Should chats between students stay closed to staff? Built: yes.
  3. Should a picture that cannot be read through be refused, rather than stored as it came with
     whatever it carries? Built: stored as it came, as before.

*Added on 2026-10-05, with §11:*

- **The owner's answers.**
  1. Administrators read chats between a student and a trainer: yes, and every other chat as well.
  2. Chats between students: administrators read them too. No new ones can be started, and the
     existing ones are closed.
  3. A picture that cannot be read through is kept as it came.

  Her approval of 2026-10-05 makes this record `accepted`.
- **Actions:** two fewer, `contact_request` and `contact_decide`. There are 75 cases today, counted
  across the four action files; there are 73 after this.
- **Schema:** none. The `contact_requests` table and its rows stay, unused. Dropping it later would be
  a migration with the guard reasoned about, and only if she says she will not want the requests
  back.
- **Privacy notice, before any family arrives:**
  - administrators can read every chat;
  - that includes the chats between students written before 2026-10-05, under a line that said
    nobody else read them. The portal is in testing, and no real family wrote one;
  - their reading is not recorded;
  - chat photos lose where and when they were taken.

  `docs-writer` drafts the sentences and the owner releases them. This touches how her families' data
  is handled, so the decision is hers and not the project's.
- **Must stay true, in addition:**
  - an administrator reads every conversation and writes only in the chats she is in;
  - opening a chat writes `thread_reads` only for a reader whose list the chat is in. Opening one she
    is not in writes nothing at all;
  - „Alle Einzelchats" is read through `thread_seen_sql()`, like every other list;
  - no code lets a student start a chat with another student, and a `direct` chat takes messages only
    from staff who are in it;
  - what a message may carry is decided only by `message_upload_types()`, and it is photos only. The
    composer's `accept` asks the same function;
  - `upload_types()` refuses a kind it does not name;
  - message text is printed through `e()` and nothing else: no HTML, no links, no fetching.
- **`qa-tester`**, breaking each rule once:
  - an administrator opens a student–student chat and a trainer–trainer chat;
  - after either, `thread_reads` and `audit_log` have no new row, and neither chat is in her list or
    her badge;
  - a trainer cannot open either chat;
  - a student cannot start a chat with another student, by `message_send` with `to=` or by `?with=`;
  - a post to `contact_request` is refused as an unknown action;
  - a student's PNG, PDF, audio file and GIF are each refused, and a JPEG is accepted;
  - a staff member's PNG and WebP are accepted, and their PDF and audio are refused;
  - an unknown upload kind is refused;
  - `structure`: neither contact case exists, there is no `capture`-less student composer, and
    `Permissions-Policy` says `microphone=()`.
- **`mobile-tester`**, on an iPhone and an Android phone:
  - the student's photo button opens the camera, and the photo arrives as an upright JPEG;
  - staff can pick from the gallery;
  - no microphone button is shown;
  - the composer still fits at 320 px.
- **`security-reviewer`** reviews the read path: an administrator's GET of a chat she is not in
  writes nothing.
- **`docs-writer`:** `TESTING.md`, `CHANGELOG`, and the privacy draft above.

## In plain words, for the owner

- Every course has a group chat. Its children are in it automatically; you and the trainer are in all.
- A child can write to you or the trainer directly; you can write to any child, alone or in the group.
- Photos are stored without where or when they were taken. A bright HDR photo from a newer phone
  shows at normal brightness.
- Everybody has an online dot and may pick a fun emoji. The update needs nothing from you.

*Added on 2026-10-05:*

- As an administrator you can read every chat, including ones you are not in. You find them under
  „Alle Einzelchats". You only read there; you cannot write in them. Nothing records that you looked.
- Children can no longer start chats with each other. Their earlier chats stay readable and closed,
  and nothing is deleted, so this can be undone.
- The chat is words and photos. There are no voice messages and no files. Children send photos from
  the camera, and you and the trainers can also send them from the gallery.
- The update needs nothing from you. The privacy notice needs your release, once `docs-writer` has
  drafted the new sentences.
