-- The things around the edges of every page: what is waiting, who you are, what
-- it looks like, and how to say that something is broken.

-- One row per thing somebody should know about. Deliberately not a copy of the
-- record it refers to: it carries a sentence and a link, so a notification that
-- outlives the thing it points at is a dead link rather than a wrong fact.
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(40) NOT NULL DEFAULT 'info',
    title VARCHAR(180) NOT NULL,
    body VARCHAR(500) NOT NULL DEFAULT '',
    link_page VARCHAR(40) NOT NULL DEFAULT '',
    link_params VARCHAR(255) NOT NULL DEFAULT '',
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX notification_inbox (account_id, read_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "This page is broken", with everything needed to work out why without asking
-- the person any follow-up questions. Kept in the database rather than emailed,
-- because the one time this matters is when email is the thing that is broken.
CREATE TABLE feedback (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NULL,
    page VARCHAR(60) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    context_json LONGTEXT NOT NULL,
    screenshot_name VARCHAR(120) NOT NULL DEFAULT '',
    state VARCHAR(20) NOT NULL DEFAULT 'new',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX feedback_open (state, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A picture, and a colour. Both empty by default, which means "the initials" and
-- "whatever the administrator chose" - so a portal nobody has customised looks
-- exactly as it did before.
ALTER TABLE accounts ADD COLUMN avatar_name VARCHAR(120) NOT NULL DEFAULT '' AFTER name;
ALTER TABLE accounts ADD COLUMN accent VARCHAR(20) NOT NULL DEFAULT '' AFTER theme;
ALTER TABLE students ADD COLUMN avatar_name VARCHAR(120) NOT NULL DEFAULT '' AFTER last_name;
