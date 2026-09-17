-- Two groupings a trainer actually uses, and the removal of one she did not.
--
-- What a child is grouped by, in her words: how far along they are, and how old
-- they are. Both are lists she can rename, extend or reorder, because a portal
-- for one trainer should not need a developer to add "Wettkampfgruppe".
--
-- What goes: the skill tree. Areas, skills, named scales and dated assessments
-- were four tables and a sub-application to record what the levels below record
-- in one field. Nothing read them outside their own tab. The pre-update backup
-- in storage/backups holds every assessment as it stood, which is the copy to
-- go to if a value entered there is ever wanted back.

-- How far along a child is. Exactly one row carries is_default=1; a new student
-- starts there rather than starting blank, because "not set" is not a real
-- answer to this question and blank fields are how a list stops being useful.
--
-- The rows themselves are written by database/defaults.php, which runs after
-- every migration and creates only what is missing. Seeding them here as well
-- would be a second copy of the same three names, and the two would drift.
CREATE TABLE levels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(300) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    is_default TINYINT NOT NULL DEFAULT 0,
    archived TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX level_listing (archived, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Age bands, in whole years and inclusive at both ends. max_age NULL means "and
-- upwards", which is what the oldest band always is and what a number there
-- could only get wrong.
CREATE TABLE age_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    min_age INT NOT NULL DEFAULT 0,
    max_age INT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    archived TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX age_group_listing (archived, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- level_id NULL only ever means "written before this existed"; the application
-- fills it with the default on the next save. age_group_id NULL is a real state
-- and stays one: it means "work it out from the date of birth", which is right
-- for almost everybody and stays right as they have birthdays.
ALTER TABLE students ADD COLUMN level_id BIGINT UNSIGNED NULL AFTER status;
ALTER TABLE students ADD COLUMN age_group_id BIGINT UNSIGNED NULL AFTER level_id;
ALTER TABLE students ADD CONSTRAINT student_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE SET NULL;
ALTER TABLE students ADD CONSTRAINT student_age_group FOREIGN KEY (age_group_id) REFERENCES age_groups(id) ON DELETE SET NULL;

DROP TABLE assessments;
DROP TABLE skills;
DROP TABLE skill_areas;
DROP TABLE rating_scales;
