---
status: proposed
date: 2026-10-02
---

# 0022. Course groups, direct messages, and a status emoji

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
  keeps two lists in step. Only `direct_thread()` writes `thread_participants`.
- The kind is fixed when the conversation is made: a student and a staff member make
  `staff_direct`, anything else `direct`. A later change of role never changes who reads it.
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
  would be unread messages meant for somebody else. She opens those under „Alle Direktchats".
- A `staff_direct` chat says „Administratoren können diese Unterhaltung lesen."; a `direct` chat
  keeps its line saying it is private.

### 3. A course has its group from the moment it exists

`course_group_thread()` returns the group, making it if missing with
`INSERT … ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)` on the unique `class_id`: one answer however
often, and however many requests at once, ask. `class_save` calls it when it creates a course;
`database/defaults.php` calls `course_groups_fill()` after every update, which gives courses made
before 025 theirs. Example courses get one too, so the owner can try the chat before real families
arrive; it goes when the example data is cleared, with its course (the foreign key cascades). Nothing
creates a group while a page is read: a GET never writes (ADR 0003).

- An archived course keeps its group, which leaves the list and takes no messages until the course is
  restored.
- `class_delete` refuses a course whose group has messages and says to archive it instead; an empty
  group goes with the course. Deleting a course must not delete what children wrote.

### 4. Group messages

- No e-mail and no notification entry, only the unread badge: a group must not fill inboxes.
- A photo reaches every child in the course, so no stored picture says where it was taken.
  `store_upload()` stores the copy `image_without_metadata()` returns, for every kind of upload: a
  JPEG keeps only its orientation, a PNG loses its text and EXIF chunks, a WebP its EXIF and XMP. It
  is plain PHP, no GD; a file that does not parse as its format is kept as it came.
- Staff can remove any group message (`message_remove`): everybody sees „Nachricht entfernt", its
  attachments are no longer served, and staff can put it back, so a mistake has a way back. The audit
  log records who did it.
- A child who joins a course reads the group's earlier messages too: membership is read, not stored.

### 5. The desk conversations close

Existing `staff` threads stay readable by the same people, with no composer; nothing is deleted, so
the update's row-count guard is unchanged, and nothing makes a new one. `bulk_send` posts into each
student's `staff_direct` chat with the sender, the subject as the first line, and is renamed
„An mehrere schreiben", because „Gruppe" now means a course chat. `demo_fill()`'s example is a
`staff_direct` chat. 025 turns existing `direct` chats between a student and staff into
`staff_direct`; otherwise an adult keeps a channel with a child that the club cannot see.

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

### 9. Looking through a child's eyes

Staff may view the portal as a family sees it. That view shows only what both may read:
`thread_seen_sql()` narrows the reader's rule by the impersonator's, so a trainer does not read a
child's chat with another trainer, or with another family, that way. Nothing is written in that view:
`may_write_thread()` is false, `message_send`, `contact_request` and `contact_decide` are refused,
and opening a chat does not mark it read for the child.

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
- **Making a group the first time it is opened.** A write on a GET.
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

## Consequences

- **Actions:** two more, `message_remove` and `status_emoji_save`. No new file; load order unchanged.
  `mark_thread_read()`, `unread_thread_ids()` and `unread_count()` move to `app/messaging.php`.
- **Privacy notice:** children in a course see each other's names, initials, emoji and dot;
  administrators can read chats between students and staff. `docs-writer` drafts, the owner releases.
- **Must stay true:** nothing stores who is in a group; only `course_group_thread()` makes a `course`
  thread and only `direct_thread()` writes participants (`demo_fill()` excepted); who may read is
  decided only in `thread_listed_sql()` and `thread_readable_sql()`, narrowed only by
  `thread_seen_sql()`; a group message sends no mail; every upload is stored through
  `image_without_metadata()`; only `status_emoji_save` writes `status_emoji`; 025–027 are never edited.
- **For the owner.** This record is `proposed` until she approves the schema change. Two questions
  are hers, built as above meanwhile; either answer needs no migration. Should administrators read
  chats between a student and a trainer? Should chats between students stay closed to staff?

## In plain words, for the owner

- Every course has a group chat. Its children are in it automatically; you and the trainer are in all.
- A child can write to you or the trainer directly; you can write to any child, alone or in the group.
- Everybody has an online dot and may pick a fun emoji. The update needs nothing from you.
