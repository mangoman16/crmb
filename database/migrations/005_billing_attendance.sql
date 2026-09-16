-- Monthly automatic charges, and attendance per training session.

-- Billing can be paused for one student without ending their membership or
-- changing their status, because "still training, not being charged this month"
-- is a real situation that neither of those expresses.
ALTER TABLE students ADD COLUMN billing_paused TINYINT NOT NULL DEFAULT 0 AFTER price_note;
ALTER TABLE students ADD COLUMN billing_note VARCHAR(255) NOT NULL DEFAULT '' AFTER billing_paused;

-- origin separates what the trainer typed from what the monthly run created, so
-- an automatic charge can be recognised and explained in the interface.
ALTER TABLE charges ADD COLUMN origin VARCHAR(10) NOT NULL DEFAULT 'manual' AFTER label;

-- The idempotency key for automatic charges: one row per student per period.
-- Manual charges leave it NULL, and MySQL permits any number of NULLs in a
-- unique index, so a repeat run of the monthly job cannot double-charge while
-- hand-entered charges stay unconstrained.
ALTER TABLE charges ADD COLUMN billing_key VARCHAR(60) NULL AFTER origin;
CREATE UNIQUE INDEX charge_billing_key ON charges (billing_key);

-- One row per student per class per session date. The unique key makes a second
-- save for the same session a correction rather than a duplicate, which is what
-- a trainer fixing a mistaken tap actually wants.
CREATE TABLE attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    session_on DATE NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'present',
    note VARCHAR(255) NOT NULL DEFAULT '',
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY attendance_unique (class_id, student_id, session_on),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX attendance_session (class_id, session_on),
    INDEX attendance_student (student_id, session_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
