-- Every course gets a group chat, and a chat between a student and a member of
-- staff becomes one the club's administrators can read
-- (docs/decisions/0022-course-groups-direct-messages-and-a-status-emoji.md).
--
-- A group belongs to its course, not to a person, so threads.account_id may now
-- be empty and threads.class_id names the course: unique, so a course has one
-- group however often it is asked for, and deleted with its course. Who is in a
-- group is read from the enrolments, never stored here.
--
-- The UPDATE first, because it can run twice and the ALTER cannot: an update
-- that stopped between them starts again from the top. It turns every existing
-- two-person chat between a student and staff into 'staff_direct', owned by the
-- student, so a trainer's login being deleted no longer takes a child's
-- conversation with it. Chats between two students stay 'direct', and the old
-- „staff desk" threads stay as they are. No row is removed.
UPDATE threads SET kind='staff_direct',
    account_id=(SELECT p.account_id FROM thread_participants p JOIN accounts a ON a.id=p.account_id
                WHERE p.thread_id=threads.id AND a.role='student' ORDER BY p.account_id LIMIT 1)
WHERE kind='direct'
  AND (SELECT COUNT(*) FROM thread_participants p WHERE p.thread_id=threads.id)=2
  AND EXISTS (SELECT 1 FROM thread_participants p JOIN accounts a ON a.id=p.account_id
              WHERE p.thread_id=threads.id AND a.role='student')
  AND EXISTS (SELECT 1 FROM thread_participants p JOIN accounts a ON a.id=p.account_id
              WHERE p.thread_id=threads.id AND a.role IN ('admin','trainer','manager'));

ALTER TABLE threads
    MODIFY account_id BIGINT UNSIGNED NULL,
    ADD COLUMN class_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER kind,
    ADD UNIQUE KEY thread_of_course (class_id),
    ADD CONSTRAINT thread_course FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE;
