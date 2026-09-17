-- Every child needs somebody to ring, and one of them has to be the one you
-- ring first. Until now the list was flat and "the first one" meant whichever
-- row happened to have the lowest id - fine until somebody deletes it.

ALTER TABLE contacts ADD COLUMN is_primary TINYINT NOT NULL DEFAULT 0;

-- What the code did implicitly, written down: the oldest contact of each child
-- becomes the standard one. Wrapped in a derived table because MySQL will not
-- read from the table it is updating.
UPDATE contacts SET is_primary=1 WHERE id IN (
    SELECT id FROM (SELECT MIN(id) AS id FROM contacts GROUP BY student_id) AS oldest_per_student);
