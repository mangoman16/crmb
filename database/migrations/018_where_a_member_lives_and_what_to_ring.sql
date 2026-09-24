-- The two things the paper form asks for that the portal had nowhere to put.
--
-- A club's registration form asks six things: first name, last name, address,
-- date of birth, telephone, email. The portal held four of them. The address had
-- no field at all, and a telephone number could only be recorded by inventing an
-- emergency contact - which for an adult member means listing themselves as the
-- person to ring if something happens to them.
--
-- The address is one line, the way the form asks it ("Hauptstraße 5, 7000
-- Eisenstadt"), rather than a street, a postcode and a town in three boxes. It
-- is typed once, printed once, and never sorted on; three boxes would be three
-- chances to tab past one.
--
-- It belongs on the member for the same reason the email does: for a child it is
-- a parent's address, and it is where the invoice goes. § 11 Abs 1 Z 3 lit b
-- UStG wants the recipient's name and address on an invoice; up to 400 € gross
-- a Kleinbetragsrechnung may leave them out, which is why this is a field she
-- fills in when it matters rather than one that blocks the form.
--
-- Empty rather than NULL, so nothing written by the previous version leaves the
-- new code a value it has to guess about.

ALTER TABLE students ADD COLUMN address VARCHAR(200) NOT NULL DEFAULT '' AFTER email;
ALTER TABLE students ADD COLUMN phone   VARCHAR(60)  NOT NULL DEFAULT '' AFTER address;
