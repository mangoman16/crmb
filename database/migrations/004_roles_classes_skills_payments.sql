-- Roles, classes, payment profiles with QR payloads, and skill assessments.
--
-- Every column carries a usable DEFAULT wherever the type allows, so a row
-- written by an older code path can never leave a NULL that a newer one has to
-- guess about. Nullable columns are only used where "not set" is a real state
-- with its own meaning (no class yet, no end date, never signed in).

-- "manager" becomes "trainer". Affects 0 rows on a fresh install and is safe to
-- re-run, so the migration stays idempotent.
UPDATE accounts SET role = 'trainer' WHERE role = 'manager';

-- Online status, and a per-account switch for payment notices.
ALTER TABLE accounts ADD COLUMN last_seen_at DATETIME NULL AFTER verified_at;
ALTER TABLE accounts ADD COLUMN payment_notices TINYINT NOT NULL DEFAULT 1 AFTER notifications;
CREATE INDEX account_seen ON accounts (last_seen_at);

-- Bank details used to build the transfer QR code shown to a parent who owes
-- money. qr_template is operator-editable so the payload standard can change
-- without a code change; the default is EPC069-12 (SEPA credit transfer).
CREATE TABLE payment_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    recipient VARCHAR(140) NOT NULL DEFAULT '',
    iban VARCHAR(42) NOT NULL DEFAULT '',
    bic VARCHAR(11) NOT NULL DEFAULT '',
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    qr_template TEXT NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    archived TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A training group. weekday/starts_at are for display and planning only; this
-- release does not generate sessions from them.
CREATE TABLE classes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    weekday TINYINT NULL,
    starts_at TIME NULL,
    ends_at TIME NULL,
    location VARCHAR(160) NOT NULL DEFAULT '',
    trainer_id BIGINT UNSIGNED NULL,
    tariff_id BIGINT UNSIGNED NULL,
    payment_profile_id BIGINT UNSIGNED NULL,
    capacity INT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    archived TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (trainer_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (tariff_id) REFERENCES tariffs(id) ON DELETE SET NULL,
    FOREIGN KEY (payment_profile_id) REFERENCES payment_profiles(id) ON DELETE SET NULL,
    INDEX class_listing (archived, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A student may belong to several classes at once.
CREATE TABLE class_students (
    class_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    joined_on DATE NULL,
    left_on DATE NULL,
    PRIMARY KEY (class_id, student_id),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX member_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A charge can name the class it is for and the account to pay into. Both are
-- nullable: charges created before this migration have neither, and the lookup
-- falls back to the class, then to the configured default profile.
ALTER TABLE charges ADD COLUMN class_id BIGINT UNSIGNED NULL AFTER student_id;
ALTER TABLE charges ADD COLUMN payment_profile_id BIGINT UNSIGNED NULL AFTER class_id;
ALTER TABLE charges ADD CONSTRAINT charge_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL;
ALTER TABLE charges ADD CONSTRAINT charge_profile FOREIGN KEY (payment_profile_id) REFERENCES payment_profiles(id) ON DELETE SET NULL;

-- How a skill is scored. Numeric range plus optional labels, so 0-10, 1-5 or a
-- named set are all the same mechanism and can be changed without a code change.
CREATE TABLE rating_scales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    min_value DECIMAL(6,2) NOT NULL DEFAULT 0,
    max_value DECIMAL(6,2) NOT NULL DEFAULT 10,
    step DECIMAL(6,2) NOT NULL DEFAULT 1,
    labels_json TEXT NOT NULL,
    archived TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skills are grouped into areas (Technik, Kondition, Taktik ...) so students can
-- be compared and banded per area rather than on one overall number.
CREATE TABLE skill_areas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    archived TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_id BIGINT UNSIGNED NOT NULL,
    scale_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    archived TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (area_id) REFERENCES skill_areas(id) ON DELETE CASCADE,
    FOREIGN KEY (scale_id) REFERENCES rating_scales(id) ON DELETE RESTRICT,
    INDEX skill_listing (archived, area_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One recorded value per student, skill and date. The unique key makes a repeat
-- entry on the same day a correction rather than a duplicate, which is what a
-- trainer fixing a typo actually wants.
CREATE TABLE assessments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    skill_id BIGINT UNSIGNED NOT NULL,
    value DECIMAL(6,2) NOT NULL,
    note VARCHAR(500) NOT NULL DEFAULT '',
    assessed_on DATE NOT NULL,
    assessed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY assessment_unique (student_id, skill_id, assessed_on),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE,
    FOREIGN KEY (assessed_by) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX assessment_history (skill_id, assessed_on),
    INDEX assessment_student (student_id, assessed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
