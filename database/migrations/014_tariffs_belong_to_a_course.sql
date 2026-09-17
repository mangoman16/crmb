-- A tariff belongs to exactly one course. That was the rule 009 introduced, but
-- the column it added carried no constraint, so deleting a course left its
-- tariffs pointing at an id that no longer exists: invisible on the course page
-- (nothing joins) and absent from the unattached list (class_id is not NULL),
-- which is a row that exists and can be reached from nowhere.

-- Anything already pointing at a course that has gone becomes unattached, which
-- is the state the interface already has a place for. Done first, because the
-- constraint below refuses to be created while such a row exists.
UPDATE tariffs SET class_id=NULL
 WHERE class_id IS NOT NULL AND class_id NOT IN (SELECT id FROM classes);

-- SET NULL rather than CASCADE: a tariff is what a charge was priced by, and
-- deleting a course must not quietly delete the record of what its price was.
ALTER TABLE tariffs
    ADD CONSTRAINT tariff_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL;
