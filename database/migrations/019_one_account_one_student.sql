-- One login belongs to one member, and a brother or sister needs an address of
-- their own.
--
-- Until now one login could hold several children: a parent signed in once and
-- saw all of them. The owner has decided against that. A student account is one
-- member's own access to the portal, so a login shows one child's record and
-- "whose account is this" has one answer. The header of 016 reasoned from the
-- other model ("the account it invites is the account that manages the child",
-- with brothers and sisters on the same login). That reasoning is replaced by
-- docs/decisions/0010-one-account-is-one-student.md; 016 stays as it shipped.
--
-- What happens to a family sharing a login today: the child whose record was
-- created first - the lowest record number on that login - keeps it. The others
-- are taken off it and keep everything else: their record, their courses, their
-- invoices and payments, and the address on their record. Without a login no
-- mail reaches them, so that address waits until they have a login of their
-- own. They cannot sign in until the trainer enters an address of their own
-- and invites them. Nothing is deleted.
--
-- Each child taken off a login gets a line in the change log saying which login
-- it was on, with no person as its author because the update wrote it. The link
-- itself cannot be put back, because the index at the end forbids it; the copy
-- of the database taken before the update is the way back.
--
-- A child who keeps a login is given that login's address, so the portal holds
-- one address per member rather than two that can disagree. Where they differed,
-- the change log says what the old one was.
--
-- Every statement can run again without doing anything twice: a run that stops
-- partway starts again from the first statement on the next page view, and a
-- child must not appear in the change log twice for one change. Each change
-- also raises the record's revision, so a form left open during the update is
-- refused on saving rather than writing the old login or address back.

-- 1. Write down which login each brother or sister is on, before they are taken
--    off it. The keeper on each login is its lowest id; see statement 2 for why
--    that is read through a grouped derived table. The change-log lines are
--    compared as bytes through HEX(): MySQL compares a JSON value with a text
--    column as JSON rather than as text, so a plain = would never find the line
--    an interrupted run already wrote.
INSERT INTO record_versions (entity, entity_id, operation, label, before_json, after_json, actor_id, created_at)
SELECT 'students', s.id, 'update', SUBSTRING(CONCAT(s.first_name, ' ', s.last_name), 1, 160),
       JSON_OBJECT('account_id', s.account_id), JSON_OBJECT('account_id', NULL), NULL, UTC_TIMESTAMP()
  FROM students s
 WHERE s.account_id IS NOT NULL
   AND s.id NOT IN (SELECT keeper FROM (SELECT MIN(id) AS keeper FROM students
                                         WHERE account_id IS NOT NULL GROUP BY account_id) AS keepers)
   AND NOT EXISTS (SELECT 1 FROM record_versions v
                    WHERE v.entity = 'students' AND v.entity_id = s.id AND v.actor_id IS NULL
                      AND HEX(v.before_json) = HEX(JSON_OBJECT('account_id', s.account_id))
                      AND HEX(v.after_json) = HEX(JSON_OBJECT('account_id', NULL)));

-- 2. Take them off it. MySQL refuses an UPDATE whose subquery reads the table
--    being updated (error 1093) unless that subquery is materialised first, and
--    a grouped derived table always is.
UPDATE students
   SET account_id = NULL, revision = revision + 1, updated_at = UTC_TIMESTAMP()
 WHERE account_id IS NOT NULL
   AND id NOT IN (SELECT keeper FROM (SELECT MIN(id) AS keeper FROM students
                                       WHERE account_id IS NOT NULL GROUP BY account_id) AS keepers);

-- 3. Write down the address each remaining child had, where it is not the one
--    they sign in with. Compared as bytes: under the tables' case-insensitive
--    collation "Familie@..." equals "familie@...", and the two would stay
--    spelled differently.
INSERT INTO record_versions (entity, entity_id, operation, label, before_json, after_json, actor_id, created_at)
SELECT 'students', s.id, 'update', SUBSTRING(CONCAT(s.first_name, ' ', s.last_name), 1, 160),
       JSON_OBJECT('email', s.email), JSON_OBJECT('email', a.email), NULL, UTC_TIMESTAMP()
  FROM students s
  JOIN accounts a ON a.id = s.account_id
 WHERE HEX(s.email) <> HEX(a.email)
   AND NOT EXISTS (SELECT 1 FROM record_versions v
                    WHERE v.entity = 'students' AND v.entity_id = s.id AND v.actor_id IS NULL
                      AND HEX(v.before_json) = HEX(JSON_OBJECT('email', s.email))
                      AND HEX(v.after_json) = HEX(JSON_OBJECT('email', a.email)));

-- 4. And give them the login's address.
UPDATE students
   SET email = (SELECT a.email FROM accounts a WHERE a.id = students.account_id),
       revision = revision + 1, updated_at = UTC_TIMESTAMP()
 WHERE account_id IS NOT NULL
   AND HEX(email) <> HEX((SELECT a.email FROM accounts a WHERE a.id = students.account_id));

-- 5. The rule itself. A child with no login is NULL here, and any number of
--    NULLs are allowed, by InnoDB and by SQLite alike.
CREATE UNIQUE INDEX student_one_account ON students (account_id);
