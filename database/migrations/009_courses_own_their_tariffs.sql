-- The course becomes the one place a price lives, and a course becomes a
-- timetable rather than a single weekday.
--
-- Her complaint, in her words: "it is very confusing to have both tarifs and
-- courses". It was. A tariff sat in Einstellungen, a course pointed at one, a
-- student pointed at another, and the price that actually applied was decided by
-- a rule nobody could see. Now a tariff belongs to a course, a student picks one
-- when they enrol, and the enrolment is the one row that says what this child
-- pays for this course.
--
-- Everything here copies before it removes, in the same file, so no step of the
-- move depends on a later release arriving.

-- ---------------------------------------------------------------------------
-- A tariff belongs to a course, and carries its own billing rules
-- ---------------------------------------------------------------------------
ALTER TABLE tariffs ADD COLUMN class_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE tariffs ADD COLUMN description VARCHAR(300) NOT NULL DEFAULT '' AFTER name;
-- How many months one charge covers: 1, 2, 3, 6 or 12. Periods are anchored to
-- the calendar year, not to the day a child joined, so "the quarter" means the
-- same quarter for everybody and she can answer "what is due in April?" without
-- looking anybody up.
ALTER TABLE tariffs ADD COLUMN interval_months INT NOT NULL DEFAULT 1 AFTER period;
-- Day of the month the money is expected. 1 by default, as she asked. Capped at
-- 28 by the application so that February is never a special case.
ALTER TABLE tariffs ADD COLUMN due_day INT NOT NULL DEFAULT 1 AFTER interval_months;
-- Days after the due date before a charge counts as overdue rather than open.
ALTER TABLE tariffs ADD COLUMN grace_days INT NOT NULL DEFAULT 7 AFTER due_day;
-- What happens when a child joins part-way through a period:
--   prorate  pay for the days they are actually there (the default)
--   full     pay the whole period anyway
--   skip     pay nothing until the next whole period begins
-- A word rather than a flag, because there are three sensible answers and a
-- boolean would have forced the third one to be expressed as a discount.
ALTER TABLE tariffs ADD COLUMN first_period VARCHAR(12) NOT NULL DEFAULT 'prorate' AFTER grace_days;
-- The welcome gift: how many months from joining it covers (0 none, -1 for as
-- long as they stay), and how much comes off each of those months - a
-- percentage, where 100 means free, or a fixed amount in cents.
ALTER TABLE tariffs ADD COLUMN discount_months INT NOT NULL DEFAULT 0 AFTER first_period;
ALTER TABLE tariffs ADD COLUMN discount_kind VARCHAR(10) NOT NULL DEFAULT 'percent' AFTER discount_months;
ALTER TABLE tariffs ADD COLUMN discount_value INT NOT NULL DEFAULT 0 AFTER discount_kind;
ALTER TABLE tariffs ADD COLUMN sort_order INT NOT NULL DEFAULT 0;

-- Each tariff joins the course that already used it. One that no course used
-- keeps class_id NULL and is listed as unattached, rather than disappearing.
UPDATE tariffs SET class_id = (SELECT MIN(c.id) FROM classes c WHERE c.tariff_id = tariffs.id) WHERE class_id IS NULL;

-- period now says only whether a tariff recurs. How often it recurs is
-- interval_months, which is the question the old three words were being asked to
-- answer and could not: "fester Zeitraum" never said how long.
UPDATE tariffs SET interval_months = 1 WHERE period = 'monthly';
UPDATE tariffs SET period = 'recurring' WHERE period = 'monthly';
UPDATE tariffs SET period = 'once' WHERE period = 'fixed';

-- ---------------------------------------------------------------------------
-- A course meets on several days, each with its own time and place
-- ---------------------------------------------------------------------------
CREATE TABLE class_days (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT NOT NULL,
    starts_at TIME NULL,
    ends_at TIME NULL,
    -- Empty means "wherever the course normally is", so changing the hall
    -- changes it for every day that has not been given one of its own.
    location VARCHAR(160) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    INDEX class_day_listing (class_id, weekday, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO class_days (class_id, weekday, starts_at, ends_at, location, sort_order)
    SELECT id, weekday, starts_at, ends_at, '', 0 FROM classes WHERE weekday IS NOT NULL;

ALTER TABLE classes DROP COLUMN weekday;
ALTER TABLE classes DROP COLUMN starts_at;
ALTER TABLE classes DROP COLUMN ends_at;

-- One dated meeting. Only written when it differs from the weekly pattern or
-- carries a note, so a course that runs as planned stores nothing and the table
-- never grows for the sake of it.
CREATE TABLE class_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id BIGINT UNSIGNED NOT NULL,
    session_on DATE NOT NULL,
    starts_at TIME NULL,
    ends_at TIME NULL,
    location VARCHAR(160) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'planned',
    note VARCHAR(500) NOT NULL DEFAULT '',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY class_session_unique (class_id, session_on),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX session_calendar (session_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- The enrolment says what this child pays for this course
-- ---------------------------------------------------------------------------
ALTER TABLE class_students ADD COLUMN tariff_id BIGINT UNSIGNED NULL;
-- An agreed price of their own, and the reason for it. NULL means the tariff's
-- price, which is the ordinary case and must stay distinguishable from a
-- deliberate zero.
ALTER TABLE class_students ADD COLUMN price_cents INT NULL;
ALTER TABLE class_students ADD COLUMN price_note VARCHAR(255) NOT NULL DEFAULT '';
-- 0 means "follow the tariff". A family that is paid on the 15th is the reason
-- this can be overridden at all.
ALTER TABLE class_students ADD COLUMN due_day INT NOT NULL DEFAULT 0;
ALTER TABLE class_students ADD CONSTRAINT enrolment_tariff FOREIGN KEY (tariff_id) REFERENCES tariffs(id) ON DELETE SET NULL;

-- Carry across what each student already had, so no enrolment loses its price
-- in the move.
UPDATE class_students SET tariff_id = (SELECT s.tariff_id FROM students s WHERE s.id = class_students.student_id) WHERE tariff_id IS NULL;

-- Only a price that actually differs from the tariff's is carried over. The old
-- student form filled the agreed price in from the tariff whenever it was left
-- blank, so nearly every student holds a frozen copy of whatever the tariff cost
-- on the day they were entered. Copying all of those onto the enrolments would
-- pin today's prices in place for ever and make the next price change do
-- nothing, which is the opposite of what changing a tariff is for.
UPDATE class_students SET price_cents = (SELECT s.price_cents FROM students s WHERE s.id = class_students.student_id)
    WHERE price_cents IS NULL
      AND (SELECT s.price_cents FROM students s WHERE s.id = class_students.student_id) IS NOT NULL
      AND (SELECT s.price_cents FROM students s WHERE s.id = class_students.student_id)
          <> COALESCE((SELECT t.price_cents FROM tariffs t WHERE t.id = class_students.tariff_id), -1);
UPDATE class_students SET price_note = (SELECT s.price_note FROM students s WHERE s.id = class_students.student_id)
    WHERE price_note = '' AND price_cents IS NOT NULL;

-- A day of the month for this one family, overriding every tariff they are on.
-- 0 means "follow the tariff", which is almost everybody.
ALTER TABLE students ADD COLUMN billing_due_day INT NOT NULL DEFAULT 0;

-- students.tariff_id, students.price_cents and students.price_note are no longer
-- read when charges are created: the enrolment above is the only source. They
-- are left in place rather than dropped because dropping a column that carries a
-- foreign key is the kind of change that fails halfway through on MySQL, which
-- cannot roll back a DDL statement. A later release removes them, once the
-- copies above have been in a real database for a while.
-- Nothing is lost quietly: the billing preview names every student it is not
-- charging and says why, and "in no course with a tariff" is one of the reasons.

-- ---------------------------------------------------------------------------
-- Joining, leaving and changing tariff are asked for and decided
-- ---------------------------------------------------------------------------
CREATE TABLE enrolment_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL,
    tariff_id BIGINT UNSIGNED NULL,
    message VARCHAR(500) NOT NULL DEFAULT '',
    state VARCHAR(20) NOT NULL DEFAULT 'pending',
    decision_note VARCHAR(500) NOT NULL DEFAULT '',
    requested_by BIGINT UNSIGNED NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (tariff_id) REFERENCES tariffs(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (decided_by) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX request_open (state, created_at),
    INDEX request_student (student_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- A charge records what produced it, and when it turns overdue
-- ---------------------------------------------------------------------------
ALTER TABLE charges ADD COLUMN tariff_id BIGINT UNSIGNED NULL AFTER class_id;
-- The price before the gift came off, and what came off. Kept apart so a parent
-- can see what they were given rather than only a number that is lower than the
-- one on the tariff.
ALTER TABLE charges ADD COLUMN gross_cents INT NOT NULL DEFAULT 0 AFTER amount_cents;
ALTER TABLE charges ADD COLUMN discount_cents INT NOT NULL DEFAULT 0 AFTER gross_cents;
ALTER TABLE charges ADD COLUMN discount_note VARCHAR(160) NOT NULL DEFAULT '' AFTER discount_cents;
-- The day it stops being merely open. Nullable rather than defaulted, because a
-- DATE cannot carry "the due date plus seven days" as a default; the application
-- reads it as COALESCE(overdue_on, due_on), so a row from before this migration
-- behaves exactly as it did.
ALTER TABLE charges ADD COLUMN overdue_on DATE NULL AFTER due_on;
ALTER TABLE charges ADD CONSTRAINT charge_tariff FOREIGN KEY (tariff_id) REFERENCES tariffs(id) ON DELETE SET NULL;

UPDATE charges SET gross_cents = amount_cents WHERE gross_cents = 0;
UPDATE charges SET overdue_on = due_on WHERE overdue_on IS NULL;

CREATE INDEX charge_overdue ON charges (overdue_on);
