-- Invoices that satisfy Austrian law, and the proof a family uploads.
--
-- An invoice is not another kind of charge. Charges are what the portal decides
-- somebody owes; an invoice is the document that says so, frozen at the moment
-- it was issued. That is why snapshot_json exists: § 11 UStG asks for the
-- issuer's and the recipient's details as they were, and a document that quietly
-- rewrites itself when the trainer moves house is not a document.
--
-- Money is not duplicated. An invoice points at the charges it covers, and
-- "paid" means those charges have been paid - so a balance is still worked out
-- in exactly one place and the two can never disagree.

CREATE TABLE invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Consecutive within a year, as § 11 Abs 1 Z 4 UStG requires. Unique in the
    -- database as well as in the code that allocates it, because "consecutive"
    -- is the one property a race can break invisibly.
    number VARCHAR(40) NOT NULL UNIQUE,
    year SMALLINT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    issued_on DATE NOT NULL,
    supplied_from DATE NULL,
    supplied_to DATE NULL,
    due_on DATE NOT NULL,
    overdue_on DATE NOT NULL,
    net_cents INT NOT NULL DEFAULT 0,
    tax_cents INT NOT NULL DEFAULT 0,
    gross_cents INT NOT NULL DEFAULT 0,
    tax_rate INT NOT NULL DEFAULT 0,
    tax_note VARCHAR(255) NOT NULL DEFAULT '',
    -- Everything the document shows, as it was: issuer, recipient, lines,
    -- totals, bank details. The PDF is built from this and never from today's
    -- settings, so re-downloading an invoice from two years ago gives the
    -- document that was sent rather than a new one.
    snapshot_json LONGTEXT NOT NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NOT NULL DEFAULT '',
    sent_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES accounts(id) ON DELETE SET NULL,
    UNIQUE KEY invoice_sequence (year, sequence),
    INDEX invoice_student (student_id, issued_on),
    INDEX invoice_open (overdue_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which charges one invoice covers. A charge belongs to at most one invoice that
-- has not been cancelled; the application enforces that, and this table makes
-- the question answerable in one query from either side.
CREATE TABLE invoice_charges (
    invoice_id BIGINT UNSIGNED NOT NULL,
    charge_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (invoice_id, charge_id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (charge_id) REFERENCES charges(id) ON DELETE RESTRICT,
    INDEX invoice_of_charge (charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A photo of a transfer confirmation, offered after a family says they have
-- paid. Optional on purpose: it speeds the trainer up when it is there and must
-- never be the thing that stops somebody telling her.
CREATE TABLE payment_proofs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charge_id BIGINT UNSIGNED NULL,
    invoice_id BIGINT UNSIGNED NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    stored_name VARCHAR(120) NOT NULL,
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    mime VARCHAR(100) NOT NULL DEFAULT '',
    bytes INT UNSIGNED NOT NULL DEFAULT 0,
    note VARCHAR(255) NOT NULL DEFAULT '',
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (charge_id) REFERENCES charges(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX proof_student (student_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
