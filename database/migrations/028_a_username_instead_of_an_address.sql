-- A login may sign in with a username instead of an address, and a student's
-- login may have neither until somebody gives it one
-- (docs/decisions/0023-every-student-has-a-login-a-wizard-adds-one-and-one-time-sign-in-links.md,
-- §1 to §3).
--
-- The owner decided on 2026-10-05 that a child without an e-mail address can
-- sign in with a username, and that every student has a login, even one that
-- cannot sign in yet: a placeholder, with no address and no username. So
-- accounts.email may now be empty, and a login gets a username column again,
-- after 024 dropped the one 022 had added.
--
-- NULL means "none", for both columns, and has no other meaning. Each column has
-- a unique index, and a unique index lets any number of rows share NULL and
-- nothing else: '' would refuse the second login without an address, or the
-- second without a username (0021 §1).
--
-- Every login written by the previous version keeps its address, byte for byte,
-- and gets username NULL, which is true of it: none has one. The unique index
-- 001 put on the address, which the engine named email, stays, so no two logins
-- share an address. The name account_username is free again since 024.
-- VARCHAR(30) holds what the username pattern allows and no more. MODIFY names
-- no collation, so the address keeps the table's, utf8mb4_unicode_ci, which it
-- has had since 001.
--
-- Nothing is deleted. One statement on one table, so an interrupted update
-- leaves it either applied or not (ADR 0022 §10). It cannot run twice - MySQL
-- 8.0 has no ADD COLUMN IF NOT EXISTS - and nothing follows it in this file.
ALTER TABLE accounts
    MODIFY email VARCHAR(254) NULL DEFAULT NULL,
    ADD COLUMN username VARCHAR(30) NULL DEFAULT NULL AFTER email,
    ADD UNIQUE KEY account_username (username);
