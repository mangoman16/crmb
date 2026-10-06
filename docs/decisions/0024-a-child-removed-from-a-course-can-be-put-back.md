---
status: accepted
date: 2026-10-06
---

# 0024. A child removed from a course can be put back

> **Accepted** on 2026-10-06. The owner approved the schema change in advance ("make the database
> change if you deem it necessary"), and migration 031, one column on `class_students`, is the one this
> record needs (`CLAUDE.md`, "Stop and ask"). The behaviour is her answer of 2026-10-06; the column is
> how it is built.

## Context

The owner, on 2026-10-06, about „Aus Kurs entfernen": "child can undo but needs to be accepted by
trainer".

What the button does today, read from the code:

- **One tap deletes the enrolment.** `views/classes.php` offers it on every member's row, current or
  past, without a confirmation. It posts `class_member_remove` with `mode=forget`, which runs
  `DELETE FROM class_students WHERE class_id=? AND student_id=?`.
- **The deleted row was the child's terms.** Gone with it:
  - the tariff and the agreed price with its note;
  - the payment interval and the due day;
  - the discount and its note;
  - `joined_on`, the day billing counts from.
- **Adding the child again starts over.** The insert's `ON DUPLICATE KEY UPDATE` sets
  `joined_on` to today, and every term she agreed has to be typed again.
- **Nothing records it.** `class_member_remove` is audited, not `tracked()`. Nothing that writes
  `class_students` is tracked today: adding, changing the terms, leaving, deciding a request.
  `class_students` cannot be an entity of its own in the change log, because it has a two-column key
  and no `id`. `field_values` has the same shape, and 0020 §7 solved it by making each value part of
  the student's snapshot.
- **„Austritt eintragen" (`mode=leave`) is different.** It sets `left_on` to today. Billing charges
  up to that day, and the row stays as a past membership.
- **Requests already exist.** `enrolment_requests` holds a family's join, leave and tariff requests
  (009), and `kind` is a `VARCHAR(20)`.
  - `request_enrolment()` refuses a second open request for the same course.
  - `decide_request()` applies an approval in one transaction, and checks the course's places again.
  - A staff member's own request is decided at once and recorded as hers.
- **The portal has no undo.** `tracked()` records history only. A way back has to be an action of its
  own.

The project manager read her answer as follows:

- removing a child no longer deletes the enrolment; it is ended and kept with its terms;
- staff restore it exactly as it was („Wieder aufnehmen");
- the family sees the ended course and can ask for it back. Staff accept or decline that request, as
  they do „Kurs wählen" (ADR 0021). On accept, the old enrolment comes back with its price, discount
  and `joined_on`.

The project manager asked for a billing rule that keeps a mistaken enrolment from creating charges.

## Decision

### 1. „Entfernt" is a fact on the row: `class_students.removed_on`

Migration `031_a_removed_enrolment_is_kept.sql`, one statement:

```sql
ALTER TABLE class_students ADD COLUMN removed_on DATE NULL DEFAULT NULL AFTER left_on;
```

- **`NULL` means not removed.** Every row written by the previous version was not removed, so that is
  true of all of them. A `DATE` matches `left_on`, and lets the pages say „entfernt am …".
- **No `removed_by` column.** The change log names who did it (§5), and so does a decided request.
  0022 rejected `removed_by` on messages for the same reason.
- **No row is deleted, and the guard is unchanged.** `class_students` is in `schema_guarded_tables()`;
  after this, staff never delete one of its rows at all. Only deleting the student or the course
  removes one, through the foreign keys, as today.
- **Numbering.** 031 follows ADR 0023's 028–030. If this record ships first, `database-engineer`
  takes the next free number.

### 2. What „entfernt" means, and how it differs from leaving

| | „Austritt eintragen" (`left_on`) | „Aus Kurs entfernen" (`removed_on`) |
| --- | --- | --- |
| Meant for | the child stopped coming | the child should not have been in it, or not any more, and the course should forget them until put back |
| In the course, its group chat, attendance lists and the place count | until `left_on` | no, from the moment of removal, for every date |
| Billed | up to `left_on`, part periods prorated (`billing_end()`) | **never, for any period, while removed** (§3) |
| Shown | as a past membership | under „Entfernt", with „Wieder aufnehmen" |
| The way back | add the child again | „Wieder aufnehmen", exactly as it was (§4) |

- **The two are independent.** A past membership can be removed as well. Restoring it brings back a
  past membership, with its `left_on`.
- **„Current" is spelled once.** `enrolment_is_current()` and `current_enrolment_sql()` become
  `left_on IS NULL AND removed_on IS NULL`. Seventeen places spell `left_on IS NULL` or
  `left_on === null` by hand today:
  - `app/enrolment.php`, `classes.php`, `mail.php`, `demo.php` and `ui.php`;
  - `actions_config.php`, in three places;
  - `views/classes.php`, `dashboard.php`, `print.php`, `student.php` and `_class_attendance.php`.

  Each of them now asks one of those two functions. `_class_attendance.php` asks a third,
  `enrolment_covers(array $row, string $date)`, which excludes a removed enrolment. A rule written
  seventeen times would be wrong in the one place that forgets `removed_on`. Changing the rule is
  the moment to write it once.

### 3. Billing: a removed enrolment creates no charge

- **`billing_plan()` skips an enrolment with `removed_on` set, for every period.** The billing
  preview gives the reason „Aus dem Kurs entfernt", as it does for every other skip.
- **Removed on or before its first billed day: nothing is ever charged** while it stays removed. A
  child put into the wrong course and removed before billing runs leaves no charge behind.
- **Removed after a charge was made: the charge stays.**
  - Billing creates no further one.
  - The removal's message says how many charges exist for that child and course, and links to them.
  - If they were a mistake she cancels them with `charge_cancel`, which is tracked.
  - Nothing cancels or deletes a charge on its own. A charge may already be paid or invoiced, and a
    change to money that nobody looked at is the kind of mistake this portal is built against.
- **Restored: billed again from the next billing run, by the ordinary rules.**
  - A period already covered by a charge is skipped („Bereits abgerechnet").
  - Months that passed while it was removed are not charged unless she runs billing for those months
    herself.
  - `joined_on` is the old one, so the current period is billed as for any long-standing member.

### 4. Who may do what

| | Who | How |
| --- | --- | --- |
| Remove | staff | `class_member_remove`, `mode=remove`. A page from before that still posts `forget` gets the same: nothing deletes a row any more. |
| Record leaving | staff | `mode=leave`, unchanged |
| Restore at once | staff | „Wieder aufnehmen" posts `enrolment_request` with the new kind `rejoin`. Like every request a staff member makes, it is decided at once and recorded as hers. |
| Ask for it back | the family, for its own student (`student()`'s scoping) | „Wieder aufnehmen lassen" on their Kurse tab posts `enrolment_request`, `kind=rejoin`, with an optional message. Staff are notified, as for every request. |
| Accept or decline | staff | `enrolment_decide`, unchanged. On accept the enrolment is restored (below), and the family is told either way, as today. |

- **`rejoin` is the fourth request kind**, „Wieder aufnehmen", in `request_kinds()`. No column and no
  table are added for it.
  - `request_enrolment()` takes it only for a course where this student has a removed enrolment, and
    the course is not archived.
  - The existing rule still refuses a second open request for the same course.
- **Restoring is one function, `restore_enrolment()`** (`app/enrolment.php`), called by
  `decide_request()` for `rejoin`:
  - it locks the row;
  - when the restored row would be current, it checks the course's places as a join does;
  - it sets `removed_on` back to `NULL`;
  - nothing else changes: tariff, price, interval, discount, due day, notes, `joined_on` and
    `left_on` stay as they were.
- **A removed enrolment is not joined again from scratch.**
  - `enrol_student()` (ADR 0023, the one copy of the insert) refuses a course where the student has
    a removed enrolment, and names „Wieder aufnehmen".
  - `courses_open_to()` leaves such a course out of the list of courses to join.
  - A `join` request for it is refused. Otherwise its `ON DUPLICATE KEY UPDATE` would reset
    `joined_on` and leave `removed_on` behind.
- **The family sees** a removed course on its own Kurse tab: „Entfernt am …", with the button, or
  „Wartet auf die Trainerin" while a request is open. They do not see it on the course itself.

### 5. Every change to an enrolment is in the change log

The audit rule as relayed by the project manager: nothing about reading is recorded, and data
changes are tracked as everywhere else.

- **Each enrolment is part of the student's snapshot**, as `course:<class_id>`. The value is a
  readable line of its terms: tariff, price, interval, discount, due day, `joined_on`, `left_on`,
  `removed_on`. This is 0020 §7's pattern for `field:<id>`:
  - `history_field_label()` names it after the course, through a lookup made at request time only;
  - `history_value()` shows the line;
  - a `course:` key never reaches SQL, because there is no undo to write it back.
- **Every write to `class_students` runs inside `tracked('students', $studentId, …)`:**
  - adding (`enrol_student()`);
  - the terms (`enrolment_save`);
  - leaving;
  - removing;
  - restoring;
  - each kind a decided request applies.

  The change log then shows one line for each: removed on this day, by this person; put back on
  that day, by that person, or by a decision on the family's request. It also shows the terms
  before and after.
- **A deleted student's last snapshot keeps their enrolments**, as it keeps their custom values.
- Every write keeps its audit entry as well, as today.

## Rejected

- **Keeping the `DELETE`, and relying on the change log to put the row back.** The portal has no
  undo. Typing a price, a discount and a joining date back in from a log line is not a way back she
  would find in time.
- **Writing removal into `left_on`, with no new column.** Leaving bills up to that day; a removal
  bills nothing. A row that has already left could not also be removed without losing its leaving
  date. Restoring could not tell a removal from a departure on the same day.
- **A `removed_by` column.** The change log and the request already name who did it.
- **An `id` on `class_students`, to track enrolments as an entity of their own.** It is a migration
  that rebuilds the key of a guarded table, for a log. 0020 rejected the same for `field_values`; the
  student's snapshot already carries them.
- **Cancelling the enrolment's charges automatically on removal.** A charge may be paid or invoiced
  already. Money changed by a side effect is a mistake nobody sees until a bank statement does.
- **Restoring with `joined_on` set to today.** That is what adding again does today, and it is the
  loss she asked to undo.
- **A new action for staff's restore.** `enrolment_request` already decides a staff member's own
  request at once, with a record of who decided; a `rejoin` request is that.
- **Letting the family restore without staff.** The owner: "needs to be accepted by trainer".
  Rejoining bills (ADR 0021).
- **A confirmation box on „Aus Kurs entfernen".** With a way back, the button needs none
  (`CLAUDE.md`: a way back, not just a confirmation box).
- **Leaving the other enrolment writes untracked, and tracking only removal and restore.** A price
  changed on an enrolment is a money change like any other. Once the student's snapshot carries the
  enrolments, tracking them all costs one wrapper around each write.

## Consequences

- **Actions.** None added. `class_member_remove` loses its `DELETE`. `enrolment_request` and
  `enrolment_decide` learn `rejoin`.
- **Load order.** No new file. `restore_enrolment()` and `enrolment_covers()` go in
  `app/enrolment.php`. `history.php` calls the course-name lookup at request time only, with the same
  kind of comment as `field_label()`.
- **Schema.** 031: one statement, never edited once shipped. No row is deleted, the guard is
  unchanged, and the update needs no command.
- **`ui-ux-designer`** specifies:
  - the course page's „Entfernt" section with „Wieder aufnehmen";
  - the family's removed course and its „Wieder aufnehmen lassen";
  - the request's label in the requests list;
  - the removal message that names existing charges;
  - wording that tells „Austritt eintragen" and „Aus Kurs entfernen" apart where both are offered.
- **`database-engineer`:** 031, and a `tests/migration-data.php` case. Rows written before it all
  read as not removed, and the `class_students` count is unchanged.
- **`qa-tester`**, breaking each once:
  - removing keeps the row, with every term intact;
  - a removed enrolment is not current anywhere: the group chat, attendance, the place count and the
    dashboard;
  - billing skips it with its reason, for a period before, during and after its `joined_on`;
  - a charge made before removal stays;
  - restoring brings back the exact row, refuses a full course, and is billed from the next run;
  - a family's `rejoin` waits for staff, and a second one is refused;
  - `join` and `enrol_student()` refuse a course with a removed enrolment;
  - every write to `class_students` leaves one version row naming its actor;
  - `structure`:
    - no `DELETE FROM class_students` in `app/`;
    - no raw `left_on IS NULL` or `left_on === null` outside `app/enrolment.php`.
- **`mobile-tester`:** the course page's two buttons and the „Entfernt" section at 320 px; the
  family's Kurse tab with a removed course.
- **`docs-writer`:** `CHANGELOG` and `TESTING.md`.
- **Must stay true:**
  - nothing in `app/` deletes a `class_students` row; removal sets `removed_on`;
  - whether an enrolment is current, or covers a date, is asked only of `enrolment_is_current()`,
    `current_enrolment_sql()` and `enrolment_covers()`, and a removed one is neither;
  - billing never charges a removed enrolment and never cancels a charge on its own;
  - restoring changes `removed_on` and nothing else;
  - every write to `class_students` is inside `tracked('students', …)`;
  - 031 is never edited.

## In plain words, for the owner

- „Aus Kurs entfernen" no longer throws the child's course details away. The child disappears from
  the course, its group chat and its lists, and is not charged for it. The agreed price, the discount
  and the joining date are kept.
- You can put the child back with „Wieder aufnehmen", exactly as before. The child or the family can
  ask for it too, and you accept or decline, as with any course request.
- If the child was already charged for that course, the charge stays until you cancel it. The message
  after removing tells you.
- The change log shows who removed and who put back, and when.
- The update needs nothing from you except your yes to the small database change.
