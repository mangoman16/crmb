-- What a pro-rated charge actually paid for.
--
-- A charge carried the whole billing period and nothing else, so a member who
-- joined on 12 November and was charged seven weeks of a yearly fee got an
-- invoice reading "Leistungszeitraum: 01.01. - 31.12." The amount was right and
-- the sentence underneath it was not, on a document that has to state the period
-- of the supply (§ 11 Abs 1 Z 3 lit d UStG) and that a family keeps.
--
-- The period stays: it is what the billing run uses to know a period has been
-- charged already, and moving it would make the run bill somebody twice. What
-- was covered is recorded beside it.
--
-- Existing rows are filled from the period they carry, which is what they were
-- claiming, so no row is left for the new code to guess about. A manual charge
-- with no period keeps none here either.

ALTER TABLE charges ADD COLUMN covered_from DATE DEFAULT NULL AFTER period_to;
ALTER TABLE charges ADD COLUMN covered_to   DATE DEFAULT NULL AFTER covered_from;

UPDATE charges SET covered_from = period_from, covered_to = period_to
 WHERE period_from IS NOT NULL AND period_to IS NOT NULL;
