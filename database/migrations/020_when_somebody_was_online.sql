-- A status each person chooses for themselves, and thirty days of when each
-- account was online.
--
-- The coloured dot on the avatar was worked out from last_seen_at alone. Now a
-- person can also say "away" or "show me as offline", and trainer and
-- administrator can see when somebody was in the portal over the last month.
-- The reasoning, and who sees what, is in
-- docs/decisions/0015-presence-a-chosen-status-and-thirty-days-of-online-history.md;
-- the owner approved this change.
--
-- Nothing is backfilled, because there is no history to backfill: every
-- existing login starts at 'auto', which shows exactly what it showed before,
-- and its first period online is the next time it opens the portal.
--
-- online_periods is deliberately left off schema_guarded_tables() in
-- app/schema.php. It loses rows every night by design, and a prune landing
-- between an update's before-count and after-count would keep the portal
-- closed for nothing a family would miss. accounts, which gains the column, is
-- guarded already.
--
-- The table comes first and the column last so that a run which stops partway
-- can start again from the first statement, as the next page view does: the
-- table is created only if it is not there yet, and the column is the one
-- statement that cannot run twice, so nothing follows it that a retry would
-- have to get past.

-- One row per stretch of time online, not one per page view: a touch extends
-- the newest period with the same hidden flag while it is still within the
-- online window, and otherwise starts a new one. hidden records whether the
-- person had chosen "Als offline anzeigen" at the time, so a trainer's view of
-- a past period does not change with today's choice.
CREATE TABLE IF NOT EXISTS online_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    hidden TINYINT NOT NULL DEFAULT 0,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX online_period_of_account (account_id, last_seen_at),
    INDEX online_period_age (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- auto    shown from activity
-- away    "Abwesend"
-- hidden  "Als offline anzeigen": offline to trainers, administrators still see
--         when the person was last here
-- Anything else is read as auto by the code, never trusted as a fourth state.
ALTER TABLE accounts ADD COLUMN presence VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER last_seen_at;
