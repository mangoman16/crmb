-- Every change to a tracked row, with enough of the old state to put it back.
--
-- This is the undo history, not the audit log. audit_log records that something
-- happened; this records what the row looked like before and after, so a change
-- can be inspected and reversed. They are kept separate because an audit trail
-- that can be rewritten is not an audit trail.
CREATE TABLE record_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity VARCHAR(40) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    operation VARCHAR(10) NOT NULL,
    label VARCHAR(160) NOT NULL DEFAULT '',
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    actor_id BIGINT UNSIGNED NULL,
    reverted_at DATETIME NULL,
    reverted_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (actor_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (reverted_by) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX version_entity (entity, entity_id, id),
    INDEX version_recent (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
