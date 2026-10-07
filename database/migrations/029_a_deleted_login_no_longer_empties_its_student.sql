-- Deleting a login no longer quietly leaves its student without one: the first
-- half of making a student's login impossible to delete
-- (docs/decisions/0023-every-student-has-a-login-a-wizard-adds-one-and-one-time-sign-in-links.md, §4).
--
-- 001 declared students.account_id a foreign key ON DELETE SET NULL, so
-- deleting a login emptied its student's account_id and said nothing. From now
-- on every student has a login, and a student's login is only ever replaced,
-- never removed. This file drops that key; 030 adds it back as ON DELETE
-- RESTRICT under a name of its own, student_login, so that the database refuses
-- to delete a login a student points to. Two files, because MySQL 8.0 cannot
-- drop and add a foreign key in one ALTER while it has to copy the table, which
-- it does while foreign-key checks are on (ADR 0021 §6).
--
-- The key is found by what it is rather than by its name. 001 gave it none, so
-- the engine named it: students_ibfk_1 on MariaDB 10.11.14, read from
-- information_schema before this file was written. MySQL 8.0's documentation
-- names an unnamed key the same way (tbl_name_ibfk_N), which has not been
-- verified on a MySQL 8.0 server; and a database restored by a tool that names
-- keys its own way would differ too. A literal name that did not match would
-- stop the update with the portal closed, on a server whose owner has no shell.
-- So the first statement builds the ALTER from information_schema: it drops
-- every key from students.account_id to accounts that empties the column when a
-- login is deleted, whatever it is called, and nothing else. student_level,
-- student_age_group and the key on tariff_id are other columns and stay.
--
-- When there is no such key, the statement is DO 0, which does nothing. That is
-- the case for a run that stopped after the key was dropped and before the
-- update recorded this file, and is started again from the top on the next page
-- view: each of the four statements can run again, so the file always can. The
-- key 030 adds is RESTRICT, so this can never find it and drop it.
--
-- Only the rule goes. student_one_account (019), the unique index the key used,
-- stays, and with it the rule that one login holds one child. Between this file
-- and 030 nothing in the database ties a student to a login that exists; the
-- update holds the migration lock and keeps the portal closed until 030 has run
-- as well. No row is deleted or changed.
SET @emptying_login_keys = COALESCE((
    SELECT CONCAT('ALTER TABLE students ', GROUP_CONCAT(CONCAT('DROP FOREIGN KEY `', k.CONSTRAINT_NAME, '`') SEPARATOR ', '))
      FROM information_schema.KEY_COLUMN_USAGE k
      JOIN information_schema.REFERENTIAL_CONSTRAINTS r
        ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
     WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'students' AND k.COLUMN_NAME = 'account_id'
       AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'accounts'
       AND r.DELETE_RULE = 'SET NULL'), 'DO 0');
PREPARE drop_emptying_login_keys FROM @emptying_login_keys;
EXECUTE drop_emptying_login_keys;
DEALLOCATE PREPARE drop_emptying_login_keys;
