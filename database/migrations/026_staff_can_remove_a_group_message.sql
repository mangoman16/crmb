-- Staff can remove a message in a course group, and put it back
-- (docs/decisions/0022-course-groups-direct-messages-and-a-status-emoji.md).
--
-- A group is children writing to each other, so somebody has to be able to take
-- a message down; a removal is a mark rather than a delete, so it can be undone
-- from the same place. Who removed it is in the audit log. Every message that
-- exists starts as not removed. One statement, so an interrupted update has
-- nothing half done.
ALTER TABLE messages ADD COLUMN removed_at DATETIME NULL DEFAULT NULL;
