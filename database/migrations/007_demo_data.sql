-- Example data that can be told apart from real data, and removed again.
--
-- A portal nobody can try out is a portal nobody checks before real families are
-- in it. The flag is a column rather than a list of ids kept somewhere, so the
-- question "is this row make-believe?" can be answered from the row itself - by
-- the clean-up, by a report, and by anyone looking at the table directly.
--
-- Default 0: every row that already exists, and every row written by code that
-- has never heard of this column, is real. That is the safe direction, because
-- the clean-up only ever deletes rows where it is 1.
ALTER TABLE accounts ADD COLUMN is_demo TINYINT NOT NULL DEFAULT 0;
ALTER TABLE students ADD COLUMN is_demo TINYINT NOT NULL DEFAULT 0;
ALTER TABLE classes  ADD COLUMN is_demo TINYINT NOT NULL DEFAULT 0;
ALTER TABLE tariffs  ADD COLUMN is_demo TINYINT NOT NULL DEFAULT 0;
ALTER TABLE news     ADD COLUMN is_demo TINYINT NOT NULL DEFAULT 0;

CREATE INDEX account_demo ON accounts (is_demo);
CREATE INDEX student_demo ON students (is_demo);
