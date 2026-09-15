-- Appearance is stored per account rather than in the browser, so it follows her
-- between her phone and a desktop instead of resetting on each device.
ALTER TABLE accounts ADD COLUMN theme VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER locale;
ALTER TABLE accounts ADD COLUMN text_scale VARCHAR(10) NOT NULL DEFAULT 'normal' AFTER theme;
