---
status: proposed
date: 2026-10-02
---

# 0022. Course groups, direct messages, and a status emoji

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
  - a **JPEG** keeps its frame, tables and scans, the JFIF header, its colour profile (APP2
    `ICC_PROFILE`) and Adobe's colour transform (APP14), and of its EXIF only which way is up, in a
    block of its own, so an iPhone photo does not lie on its side. GPS and the rest of EXIF, XMP,
    IPTC, comments and every other APPn segment go. So does everything after the end of the picture,
    where a phone puts a second picture (MPF) or a motion photo's video. An HDR photo's gain map is
    such a second picture, so an HDR photo loses it and shows at normal brightness;
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
- **The bell leaves out chat notices** (`notifications_seen_sql()`, for the pane and its count alike):
  a chat notice, and a contact request, quotes the message, and would hand staff the words that
  `thread_seen_sql()` keeps from them in the chat.
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
- **A „Gelesen" button, or marking read by a POST from JavaScript.** A step nobody expects, or a
  badge that never clears without JavaScript. Naming the one exception keeps ADR 0003 checkable.
- **Answering `?with=` truthfully while viewing as somebody.** „Nicht gefunden" for a hidden chat and
  an empty one otherwise is an answer about a child's private chats.

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
  - only `direct_thread()` calls `join_thread()`, and only `join_thread()` writes
    `thread_participants` — the `structure` suite checks both;
  - which kind a pair makes is decided only in `pair_kind()`;
  - who may read is decided only in `thread_listed_sql()` and `thread_readable_sql()`, narrowed only
    by `thread_seen_sql()`, through which the list, „Alle Direktchats", the badge and
    `thread_record()` all read;
  - nothing in `dispatch_messages()` runs while staff view as somebody, and the bell shows no chat
    notice then;
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

## In plain words, for the owner

- Every course has a group chat. Its children are in it automatically; you and the trainer are in all.
- A child can write to you or the trainer directly; you can write to any child, alone or in the group.
- Photos are stored without where or when they were taken. A bright HDR photo from a newer phone
  shows at normal brightness.
- Everybody has an online dot and may pick a fun emoji. The update needs nothing from you.
