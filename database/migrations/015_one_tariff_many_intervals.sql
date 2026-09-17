-- One tariff, several ways to pay it - and the discount where the decision is
-- actually made.
--
-- A tariff held one price and one interval, so "252 € im Jahr, 162 € im
-- Halbjahr, 99 € im Quartal, 37 € im Monat" was four tariffs with the same name
-- and four places to change the price when it goes up. It is one tariff with
-- four rates now, and the enrolment says which of them a child is on.
--
-- The welcome discount moves the other way. It sat on the tariff, which made it
-- a property of the price list rather than of an agreement with one family:
-- giving one child three months at half price meant a tariff nobody else could
-- be put on. The tariff now carries only the shapes of discount she gives -
-- "dauerhaft -20 %", "erster Monat frei" - and the enrolment carries the one
-- this family actually got.
--
-- Nobody's next invoice changes because of this file: every tariff's rate
-- becomes its first row, and every enrolment inherits exactly the discount its
-- tariff was giving it.

CREATE TABLE tariff_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_id BIGINT UNSIGNED NOT NULL,
    interval_months INT NOT NULL,
    price_cents INT NOT NULL DEFAULT 0,
    UNIQUE KEY rate_per_interval (tariff_id, interval_months),
    FOREIGN KEY (tariff_id) REFERENCES tariffs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What every tariff charges today, as the first of its rates.
INSERT INTO tariff_rates (tariff_id, interval_months, price_cents)
    SELECT id, interval_months, price_cents FROM tariffs;

CREATE TABLE tariff_discounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    months INT NOT NULL DEFAULT 0,
    kind VARCHAR(10) NOT NULL DEFAULT 'percent',
    value INT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (tariff_id) REFERENCES tariffs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A tariff that was giving a discount keeps it, as the template it now is, so
-- the next child put on that tariff can be given the same thing with one tap.
INSERT INTO tariff_discounts (tariff_id, name, months, kind, value)
    SELECT id, 'Willkommensrabatt', discount_months, discount_kind, discount_value
      FROM tariffs WHERE discount_months <> 0 AND discount_value > 0;

ALTER TABLE class_students ADD COLUMN interval_months INT NOT NULL DEFAULT 0;
ALTER TABLE class_students ADD COLUMN discount_months INT NOT NULL DEFAULT 0;
ALTER TABLE class_students ADD COLUMN discount_kind VARCHAR(10) NOT NULL DEFAULT 'percent';
ALTER TABLE class_students ADD COLUMN discount_value INT NOT NULL DEFAULT 0;
ALTER TABLE class_students ADD COLUMN discount_note VARCHAR(120) NOT NULL DEFAULT '';

-- Everybody already enrolled keeps exactly the gift their tariff was giving
-- them. Written as correlated subqueries rather than an UPDATE ... JOIN, which
-- is MySQL's own spelling and not SQL anybody else understands.
UPDATE class_students SET
    discount_months = COALESCE((SELECT t.discount_months FROM tariffs t WHERE t.id = class_students.tariff_id), 0),
    discount_kind   = COALESCE((SELECT t.discount_kind   FROM tariffs t WHERE t.id = class_students.tariff_id), 'percent'),
    discount_value  = COALESCE((SELECT t.discount_value  FROM tariffs t WHERE t.id = class_students.tariff_id), 0),
    discount_note   = 'Willkommensrabatt'
WHERE tariff_id IN (SELECT id FROM tariffs WHERE discount_months <> 0 AND discount_value > 0);

-- Dropped rather than left behind: a column the code no longer reads is a
-- second answer waiting to be believed, and the rows above are the first.
ALTER TABLE tariffs DROP COLUMN discount_months;
ALTER TABLE tariffs DROP COLUMN discount_kind;
ALTER TABLE tariffs DROP COLUMN discount_value;
ALTER TABLE tariffs DROP COLUMN price_cents;
