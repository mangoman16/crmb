-- Everybody may show one emoji beside their name in the chat
-- (docs/decisions/0022-course-groups-direct-messages-and-a-status-emoji.md).
--
-- The column holds a key from a fixed list (status_emojis()), never the emoji
-- typed in, so nothing anybody writes ends up beside their name. '' is "none",
-- which every login starts with. One statement, so an interrupted update has
-- nothing half done.
ALTER TABLE accounts ADD COLUMN status_emoji VARCHAR(16) NOT NULL DEFAULT '' AFTER presence;
