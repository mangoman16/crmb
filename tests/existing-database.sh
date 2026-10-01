#!/usr/bin/env bash
# Run the test suite against an empty database you created in the hosting panel.
#
#   tests/existing-database.sh            # the whole suite
#   tests/existing-database.sh billing    # one suite
#
# For shared hosting, where tests/mariadb-local.sh stops because there is no
# mariadbd to start, but the panel will create another database. Create one
# whose name ends in _test (the panel usually puts the account name in front,
# as in konto_crm_test) and a user that may use it. Run this from the git
# checkout that serves the portal: the release ZIP carries no tests/.
#
# The first run asks for the database name, user, password, server and port,
# and keeps them in tests/.test-database.php, readable by you alone and ignored
# by git. Later runs reuse them. Delete that file to use a different database.
#
# The suite drops and recreates every table in that database on each run. Which
# databases it will take is decided in tests/existing-database.php; the portal's
# own database is never connected to.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SAVED="$ROOT/tests/.test-database.php"
# Found by the same rule as config_path() in app/install.php, so the database
# compared against is the one the portal really uses.
PORTAL_CONFIG="${CRM_CONFIG:-$ROOT/config/config.php}"

# Private to this run and removed afterwards, configuration included.
WORK="$(mktemp -d "${TMPDIR:-/tmp}/crm-test.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

MODE=reuse
if [ ! -f "$SAVED" ]; then
    MODE=setup
    [ -t 0 ] || { echo "The first run asks for the database details. Start it from a terminal." >&2; exit 2; }
    echo "Which database should the tests use? It must be empty, and its name must end in _test."
    IFS= read -rp "Database name: " CRM_DB_NAME
    # test_database_name_allowed() in tests/database-name.php, repeated here so
    # that a wrong name is refused before the password is typed. Change together.
    NAME_RULE='^[A-Za-z0-9_]+_test$'
    [[ $CRM_DB_NAME =~ $NAME_RULE ]] || { echo "Refusing: the name must end in _test (letters, digits and underscores only)." >&2; exit 2; }
    IFS= read -rp "Database user: " CRM_DB_USER
    IFS= read -rsp "Password (not shown): " CRM_DB_PASSWORD; echo
    IFS= read -rp "Server [localhost]: " CRM_DB_HOST
    IFS= read -rp "Port [3306]: " CRM_DB_PORT
    export CRM_DB_NAME CRM_DB_USER CRM_DB_PASSWORD CRM_DB_HOST CRM_DB_PORT
fi

VERSION="$(php "$ROOT/tests/existing-database.php" "$MODE" "$SAVED" "$PORTAL_CONFIG" "$WORK")"
unset CRM_DB_NAME CRM_DB_USER CRM_DB_PASSWORD CRM_DB_HOST CRM_DB_PORT

echo "Database server: $VERSION"
echo
CRM_CONFIG="$WORK/config.php" php "$ROOT/tests/run.php" "$@"
