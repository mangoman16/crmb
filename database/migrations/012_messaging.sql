-- Messages people recognise: attachments, voice notes, and talking to somebody
-- other than the trainer once they have agreed to it.

-- What is attached to one message. kind separates the three things the
-- interface has to do differently - show a picture, play a voice note, offer a
-- file - rather than making every view guess from the media type.
CREATE TABLE message_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'file',
    stored_name VARCHAR(120) NOT NULL,
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    mime VARCHAR(100) NOT NULL DEFAULT '',
    bytes INT UNSIGNED NOT NULL DEFAULT 0,
    seconds INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    INDEX file_of_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A thread used to be one family talking to whoever was on duty, so the family
-- was a column on it. A conversation between two families has no such column,
-- so who is in a thread becomes a table.
--
-- kind is what decides who may read it, and it is not a preference:
--   staff   a family and whoever is on duty; every member of staff can read it
--   direct  two accounts, and nobody else, the trainer included
CREATE TABLE thread_participants (
    thread_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL,
    PRIMARY KEY (thread_id, account_id),
    FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX threads_of_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE threads ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'staff' AFTER account_id;

-- Every thread that already exists is a family talking to staff, and its owner
-- is its participant. Written now so that nothing has to fall back to the old
-- shape at read time.
INSERT INTO thread_participants (thread_id, account_id, joined_at)
    SELECT id, account_id, updated_at FROM threads;

-- Writing to another family has to be agreed to first. Writing to the trainer
-- never does: she is the person the portal exists to reach.
CREATE TABLE contact_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_account_id BIGINT UNSIGNED NOT NULL,
    to_account_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'pending',
    message VARCHAR(300) NOT NULL DEFAULT '',
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY contact_pair (from_account_id, to_account_id),
    FOREIGN KEY (from_account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX contact_inbox (to_account_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
