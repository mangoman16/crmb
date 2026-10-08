#!/usr/bin/env bash
# Run the test suite against a throwaway MariaDB, on a machine that has none.
#
#   tests/mariadb-local.sh
#
# Starts a server in a temporary directory on port 3307, creates a database
# whose name ends in _test, runs the suite against it, and stops the server
# again. Nothing outside $WORK is touched and no existing MySQL or MariaDB
# installation is used, so this cannot reach a real database.
#
# The one-command way to run the suite: it needs a server, and this brings its
# own. On shared hosting, where there is none to start, existing-database.sh
# uses an empty database made in the hosting panel instead.
set -euo pipefail

WORK="${CRM_MARIADB_WORK:-${TMPDIR:-/tmp}/crm-mariadb}"
PORT="${CRM_MARIADB_PORT:-3307}"
DB=badminton_crm_test
# The second database: tests/migration-data.php stops the migrations half way
# with a portal's data written in between, which needs one of its own that is
# empty when it starts.
MIGRATION_DB=badminton_crm_migrations_test
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Shared hosting has no mariadbd and never will, so saying "install it" there
# sends the reader nowhere. Name the way that does work on such a host.
command -v mariadbd >/dev/null || {
    echo "mariadbd not found, so there is no server this script can start." >&2
    echo "On your own machine: install mariadb-server and run this again." >&2
    echo "On shared hosting: create an empty database whose name ends in _test in the" >&2
    echo "hosting panel, then run tests/existing-database.sh instead." >&2
    exit 2; }

mkdir -p "$WORK/data" "$WORK/run" "$WORK/tmp"
if [ ! -d "$WORK/data/mysql" ]; then
    echo "Initialising a database in $WORK"
    mariadb-install-db --user="$(id -un)" --datadir="$WORK/data" \
        --auth-root-authentication-method=normal > "$WORK/install.log" 2>&1
fi

SOCK="$WORK/run/mysql.sock"
if ! mariadb --socket="$SOCK" -e "SELECT 1" >/dev/null 2>&1; then
    echo "Starting mariadbd on port $PORT"
    # Its own temp folder: a server sharing /tmp with other runs once had a temp
    # table's file deleted from under it, and the suite aborted part-way.
    mariadbd --user="$(id -un)" --datadir="$WORK/data" --socket="$SOCK" --tmpdir="$WORK/tmp" \
        --port="$PORT" --bind-address=127.0.0.1 --pid-file="$WORK/run/mysqld.pid" \
        > "$WORK/server.log" 2>&1 &
    for _ in $(seq 1 30); do
        mariadb --socket="$SOCK" -e "SELECT 1" >/dev/null 2>&1 && break
        sleep 1
    done
    STARTED_HERE=1
fi
mariadb --socket="$SOCK" -e "SELECT 1" >/dev/null 2>&1 || { echo "Server did not start; see $WORK/server.log" >&2; exit 2; }

# The suite drops and recreates every table in this database on each run, so the
# name is fixed and ends in _test — the harness refuses anything else. The
# migrations database is made afresh each time, because migration-data.php
# refuses one that is not empty and a run killed part-way leaves tables in it;
# the server is this script's own, under $WORK, so nobody else's is dropped.
mariadb --socket="$SOCK" -e "
    CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DROP DATABASE IF EXISTS \`$MIGRATION_DB\`;
    CREATE DATABASE \`$MIGRATION_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'crm'@'localhost' IDENTIFIED BY 'crmpass';
    GRANT ALL ON \`$DB\`.* TO 'crm'@'localhost';
    GRANT ALL ON \`$MIGRATION_DB\`.* TO 'crm'@'localhost';
    FLUSH PRIVILEGES;"

# Written by the helper existing-database.sh uses too, so the two cannot drift
# apart, and $WORK reaches the PHP source quoted rather than pasted in.
write_config() {
    php -r 'require $argv[1];
        write_run_config($argv[2], ["host" => "127.0.0.1", "port" => (int)$argv[3], "database" => $argv[4],
                                    "username" => "crm", "password" => "crmpass"], $argv[5]);' \
        "$ROOT/tests/run-config.php" "$1" "$PORT" "$2" "$WORK"
}
CONFIG="$WORK/config.php"
MIGRATION_CONFIG="$WORK/migration-config.php"
write_config "$CONFIG" "$DB"
write_config "$MIGRATION_CONFIG" "$MIGRATION_DB"

echo "MariaDB: $(mariadb --socket="$SOCK" -sN -e 'SELECT VERSION()')"
echo
set +e
CRM_CONFIG="$CONFIG" CRM_MIGRATION_CONFIG="$MIGRATION_CONFIG" php "$ROOT/tests/run.php" "$@"
STATUS=$?
set -e

if [ "${STARTED_HERE:-0}" = "1" ]; then
    echo
    echo "Stopping the server this script started."
    mariadb --socket="$SOCK" -e "SHUTDOWN" 2>/dev/null || kill "$(cat "$WORK/run/mysqld.pid")" 2>/dev/null || true
fi
exit $STATUS
