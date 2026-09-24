-- Signing in belongs to the child's record, and a contact is somebody to ring.
--
-- The address a family signed in with lived on the standard contact, which made
-- one row do two jobs that are not the same job: "who do I ring when she falls
-- over" and "who does the portal write to". They came apart in practice - a
-- grandmother who should be rung but has no email, a father who reads the
-- invoices but is never in the hall - and the form could not express either
-- without lying about the other.
--
-- The child's record carries the address now. For a child that is a parent's
-- address, which is the normal case and is why this is one field rather than a
-- separate person: the account it invites is the account that manages the child.
-- Contacts keep their phone numbers and lose their second job.
--
-- Nobody is signed out by this file: every account that exists keeps its own
-- email and its own password, and the column below is filled from what the
-- portal was already writing to.

ALTER TABLE students ADD COLUMN email VARCHAR(254) NOT NULL DEFAULT '' AFTER last_name;

-- First choice: the account that already manages this child. That is the
-- address they actually sign in with, whatever any contact row says.
UPDATE students
   SET email = COALESCE((SELECT a.email FROM accounts a WHERE a.id = students.account_id), '')
 WHERE account_id IS NOT NULL;

-- Second choice: the standard contact, which is where invitations and invoices
-- were being sent for a child who has no account yet.
UPDATE students
   SET email = COALESCE((SELECT c.email FROM contacts c
                          WHERE c.student_id = students.id AND c.email <> ''
                          ORDER BY c.is_primary DESC, c.id LIMIT 1), '')
 WHERE email = '';
