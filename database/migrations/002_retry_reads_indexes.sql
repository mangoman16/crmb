-- Backoff state for the mail queue. Before this column a transient SMTP failure
-- parked a job in 'failed' forever, because process_mail only selected 'queued'.
ALTER TABLE mail_jobs ADD COLUMN retry_after DATETIME NULL AFTER error;
CREATE INDEX mail_retry ON mail_jobs (status, retry_after);

-- Per-account read marker, so a reply is visibly unread instead of silently arriving.
CREATE TABLE thread_reads (
    thread_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (thread_id, account_id),
    FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for the queries the application actually runs. The balance and outstanding
-- subqueries ran as full scans of charges and payments on every student card.
CREATE INDEX charge_student ON charges (student_id, cancelled);
CREATE INDEX charge_due ON charges (due_on);
CREATE INDEX payment_charge ON payments (charge_id, voided, confirmed_at);
CREATE INDEX thread_recent ON threads (updated_at);
CREATE INDEX message_thread ON messages (thread_id, id);

-- Supports the nightly `console.php maintenance` deletes, which were full scans.
CREATE INDEX token_expiry ON auth_tokens (expires_at);
CREATE INDEX limit_window ON rate_limits (window_start);
CREATE INDEX request_age ON form_requests (created_at);
CREATE INDEX audit_age ON audit_log (created_at);
