<?php
declare(strict_types=1);

/**
 * Whether a database name is one the tests may use: letters, digits and
 * underscores, ending in _test.
 *
 * The suite drops every table in the database it is given, so this is the rule
 * standing between a mistyped setting and her families' data. "Ends in _test"
 * alone is not enough: the name is put into a PDO connection string, and
 * "x;dbname=portal;y=z_test" ends in _test while PDO connects to the last dbname
 * it reads - the live one. Allowing only these characters means the name cannot
 * change the meaning of the string it is put into.
 *
 * Used by tests/harness.php, tests/db.php and tests/existing-database.php. The
 * one other copy is NAME_RULE in tests/existing-database.sh, in bash, so that a
 * wrong name is refused before the password is typed; change them together.
 */
function test_database_name_allowed(string $name): bool {
    return preg_match('/^[A-Za-z0-9_]+_test$/D', $name) === 1;
}
