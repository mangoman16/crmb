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
# The default suite runs on a SQLite translation of the schema, which proves the
# PHP logic but not the SQL dialect. This is how you prove the dialect.
set -euo pipefail

WORK="${CRM_MARIADB_WORK:-${TMPDIR:-/tmp}/crm-mariadb}"
PORT="${CRM_MARIADB_PORT:-3307}"
DB=badminton_crm_test
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

command -v mariadbd >/dev/null || { echo "mariadbd not found. Install mariadb-server first." >&2; exit 2; }

mkdir -p "$WORK/data" "$WORK/run"
if [ ! -d "$WORK/data/mysql" ]; then
    echo "Initialising a database in $WORK"
    mariadb-install-db --user="$(id -un)" --datadir="$WORK/data" \
        --auth-root-authentication-method=normal > "$WORK/install.log" 2>&1
fi

SOCK="$WORK/run/mysql.sock"
if ! mariadb --socket="$SOCK" -e "SELECT 1" >/dev/null 2>&1; then
    echo "Starting mariadbd on port $PORT"
    mariadbd --user="$(id -un)" --datadir="$WORK/data" --socket="$SOCK" \
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
# name is fixed and ends in _test — the harness refuses anything else.
mariadb --socket="$SOCK" -e "
    CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'crm'@'localhost' IDENTIFIED BY 'crmpass';
    GRANT ALL ON \`$DB\`.* TO 'crm'@'localhost';
    FLUSH PRIVILEGES;"

CONFIG="$WORK/config.php"
cat > "$CONFIG" <<PHP
<?php
declare(strict_types=1);
return [
    'app_url' => 'http://127.0.0.1:4192',
    'app_key' => '$(php "$ROOT/bin/console.php" key)',
    'db' => ['host'=>'127.0.0.1','port'=>$PORT,'database'=>'$DB','username'=>'crm','password'=>'crmpass'],
    'timezone' => 'Europe/Vienna',
    'secure_cookies' => false,
    'session_idle_minutes' => 120,
    'maintenance_file' => '$WORK/maintenance.flag',
];
PHP

echo "MariaDB: $(mariadb --socket="$SOCK" -sN -e 'SELECT VERSION()')"
echo
set +e
CRM_TEST_DRIVER=mysql CRM_CONFIG="$CONFIG" php "$ROOT/tests/run.php" "$@"
STATUS=$?
set -e

if [ "${STARTED_HERE:-0}" = "1" ]; then
    echo
    echo "Stopping the server this script started."
    mariadb --socket="$SOCK" -e "SHUTDOWN" 2>/dev/null || kill "$(cat "$WORK/run/mysqld.pid")" 2>/dev/null || true
fi
exit $STATUS
