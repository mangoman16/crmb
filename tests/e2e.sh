#!/usr/bin/env bash
# The owner's first evening, end to end, in a real browser against a real engine.
#
#   tests/e2e.sh                 # PHP from PATH
#   CRM_E2E_REF=HEAD tests/e2e.sh                         # exactly one commit
#   CRM_E2E_ZIP=../badminton-crm-0.6.0.zip tests/e2e.sh   # a built package, as the owner unpacks it
#   CRM_E2E_PHP=php8.5 tests/e2e.sh
#   CRM_E2E_KEEP=1 tests/e2e.sh  # leave the servers and the work dir up afterwards
#
# Copies the checkout, one commit or a built package into a fresh work directory
# (what an upload does), starts a throwaway MariaDB, a local SMTP sink and
# `php -S`, then runs tests/e2e.mjs: setup.php, the nine setup steps, the
# family's invitation from the captured mail, a payment proof, a problem report,
# an invoice PDF and a provoked error. Nothing outside the work directory is
# touched and no real mail leaves.
#
# Ports: CRM_E2E_PORT (web, 8765), +1 SMTP, +2 MariaDB unless CRM_E2E_DB_PORT says.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${CRM_E2E_PHP:-php}"
PORT="${CRM_E2E_PORT:-8765}"
SMTP_PORT=$((PORT + 1))
DB_PORT="${CRM_E2E_DB_PORT:-$((PORT + 2))}"
WORK="${CRM_E2E_WORK:-${TMPDIR:-/tmp}/crm-e2e-$PORT}"
DB=crm_e2e

command -v mariadbd >/dev/null || { echo "mariadbd not found; this test needs a real MariaDB." >&2; exit 2; }
command -v "$PHP" >/dev/null || { echo "$PHP not found." >&2; exit 2; }
command -v node >/dev/null || { echo "node not found (Playwright runs under it)." >&2; exit 2; }

cleanup() {
    [ "${CRM_E2E_KEEP:-0}" = "1" ] && { echo "Left running; work dir $WORK"; return; }
    [ -n "${WEB_PID:-}" ] && kill "$WEB_PID" 2>/dev/null || true
    [ -n "${SMTP_PID:-}" ] && kill "$SMTP_PID" 2>/dev/null || true
    mariadb --socket="$WORK/run/mysql.sock" -uroot -e "SHUTDOWN" 2>/dev/null || true
}
trap cleanup EXIT

# A server left over from an earlier run (CRM_E2E_KEEP=1, or one killed hard)
# would answer in place of this run's, serving files and a mailbox this run has
# replaced - and every check would be about the wrong thing. Refuse instead.
busy() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }
for p in "$PORT" "$SMTP_PORT" "$DB_PORT"; do
    if busy "$p"; then echo "Port $p is in use - a server from an earlier run? Stop it, or set CRM_E2E_PORT or CRM_E2E_DB_PORT." >&2; exit 2; fi
done

# A fresh work dir every run: a second run must not find the first one's portal.
if [ -d "$WORK" ]; then
    mariadb --socket="$WORK/run/mysql.sock" -uroot -e "SHUTDOWN" 2>/dev/null || true
    sleep 1
    rm -rf "$WORK"
fi
mkdir -p "$WORK/data" "$WORK/run" "$WORK/tmp" "$WORK/mail" "$WORK/site" "$WORK/shots"

# --- the upload: the files she would put on the server, nothing else -------
# CRM_E2E_ZIP=<package> walks a package from bin/release.sh, unpacked the way
# the operator unpacks it: what the owner is handed, vendor/ included, rather
# than what git holds. CRM_E2E_REF=<commit> tests that commit exactly, whatever
# the working tree holds; with neither, the working tree as it is now. For those
# two vendor/ is not in git, so it comes from the checkout.
if [ -n "${CRM_E2E_ZIP:-}" ]; then
    [ -z "${CRM_E2E_REF:-}" ] || { echo "Set CRM_E2E_ZIP or CRM_E2E_REF, not both." >&2; exit 2; }
    [ -f "$CRM_E2E_ZIP" ] || { echo "There is no package at $CRM_E2E_ZIP." >&2; exit 2; }
    command -v unzip >/dev/null || { echo "unzip not found." >&2; exit 2; }
    unzip -q "$CRM_E2E_ZIP" -d "$WORK/unpacked"
    # One folder, as bin/release.sh makes it; what is inside goes on the server.
    TOP="$(ls -A "$WORK/unpacked")"
    [ -n "$TOP" ] && [ -d "$WORK/unpacked/$TOP" ] \
        || { echo "$CRM_E2E_ZIP does not hold the one folder bin/release.sh makes." >&2; exit 2; }
    rmdir "$WORK/site"
    mv "$WORK/unpacked/$TOP" "$WORK/site"
    # Checked against bin/release.sh's own lists, so the two cannot disagree. A
    # package that holds what it leaves out is not one it builds, and is not
    # walked as if it were. Anything in config/ or storage/ beyond what it ships
    # there would be somebody's password or a family's data: the other two ways
    # drop a config/config.php below without a word, but here the walk stops.
    release_list() { sed -n "s/^$1=(\(.*\))\$/\1/p" "$ROOT/bin/release.sh"; }
    LEAVE_OUT="$(release_list LEAVE_OUT)"
    CONFIG_AND_STORAGE="$(release_list CONFIG_AND_STORAGE)"
    [ -n "$LEAVE_OUT" ] && [ -n "$CONFIG_AND_STORAGE" ] \
        || { echo "bin/release.sh has no LEAVE_OUT or CONFIG_AND_STORAGE list to check the package against." >&2; exit 2; }
    HELD=""
    for entry in $LEAVE_OUT; do [ ! -e "$WORK/site/$entry" ] || HELD="$HELD $entry"; done
    [ -z "$HELD" ] || { echo "$CRM_E2E_ZIP holds$HELD, which bin/release.sh leaves out: this is not a package it builds." >&2; exit 1; }
    SHIPPED=()
    for entry in $CONFIG_AND_STORAGE; do SHIPPED+=(! -path "$entry"); done
    CARRIED="$(cd "$WORK/site" && find config storage -mindepth 1 "${SHIPPED[@]}")"
    [ -z "$CARRIED" ] \
        || { echo "$CRM_E2E_ZIP carries ${CARRIED//$'\n'/ }, and config/ and storage/ ship with nothing but their deny files and config.example.php." >&2; exit 1; }
    # bin/release.sh writes the commit into BUILD.txt; VERSION alone cannot name one.
    BUILT="$(sed -n 's/^commit \([0-9a-f]\{7\}\).*/, commit \1/p' "$WORK/site/BUILD.txt" 2>/dev/null || true)"
    SOURCE="package $(basename "$CRM_E2E_ZIP") (VERSION $(tr -d '[:space:]' < "$WORK/site/VERSION")$BUILT, sha256 $(sha256sum "$CRM_E2E_ZIP" | cut -c1-12))"
elif [ -n "${CRM_E2E_REF:-}" ]; then
    git -C "$ROOT" archive "$CRM_E2E_REF" | tar -C "$WORK/site" -xf -
    rm -rf "$WORK/site/tests" "$WORK/site/.claude"
    cp -a "$ROOT/vendor" "$WORK/site/vendor"
    SOURCE="commit $(git -C "$ROOT" rev-parse --short "$CRM_E2E_REF")"
else
    tar -C "$ROOT" --exclude=./.git --exclude=./tests --exclude=./.claude \
        --exclude='./storage/*' --exclude=./config/config.php -cf - . | tar -C "$WORK/site" -xf -
    SOURCE="working tree at $(git -C "$ROOT" rev-parse --short HEAD)"
    [ -n "$(git -C "$ROOT" status --porcelain -- app views public database bin vendor 2>/dev/null)" ] && SOURCE="$SOURCE + uncommitted changes"
fi
rm -f "$WORK/site/config/config.php"
mkdir -p "$WORK/site/storage"
# A way to break the copy on purpose - never the checkout - and watch the walk
# notice: CRM_E2E_AFTER_COPY='echo ".card{min-width:600px}" >> public/assets/app.css'
if [ -n "${CRM_E2E_AFTER_COPY:-}" ]; then
    echo "Sabotage:  $CRM_E2E_AFTER_COPY"
    (cd "$WORK/site" && bash -c "$CRM_E2E_AFTER_COPY")
fi

# --- MariaDB --------------------------------------------------------------
mariadb-install-db --user="$(id -un)" --datadir="$WORK/data" \
    --auth-root-authentication-method=normal > "$WORK/install-db.log" 2>&1
# Its own temp folder: a server sharing /tmp with other runs once had a temp
# table's file deleted from under it, and the run aborted part-way.
mariadbd --user="$(id -un)" --datadir="$WORK/data" --socket="$WORK/run/mysql.sock" --tmpdir="$WORK/tmp" \
    --port="$DB_PORT" --bind-address=127.0.0.1 --pid-file="$WORK/run/mysqld.pid" \
    > "$WORK/mariadb.log" 2>&1 &
for _ in $(seq 1 30); do mariadb --socket="$WORK/run/mysql.sock" -uroot -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
mariadb --socket="$WORK/run/mysql.sock" -uroot -e "SELECT 1" >/dev/null 2>&1 || { echo "MariaDB did not start; see $WORK/mariadb.log" >&2; exit 2; }
mariadb --socket="$WORK/run/mysql.sock" -uroot -e "
    CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER 'crm'@'127.0.0.1' IDENTIFIED BY 'e2e-db-pass';
    GRANT ALL ON \`$DB\`.* TO 'crm'@'127.0.0.1';
    FLUSH PRIVILEGES;"

# --- SMTP sink with a certificate only this run trusts ---------------------
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" \
    -keyout "$WORK/smtp-key.pem" -out "$WORK/smtp-cert.pem" >/dev/null 2>&1
python3 "$ROOT/tests/e2e_smtp.py" "$SMTP_PORT" "$WORK/smtp-cert.pem" "$WORK/smtp-key.pem" "$WORK/mail" \
    > "$WORK/smtp.log" 2>&1 &
SMTP_PID=$!
for _ in $(seq 1 20); do busy "$SMTP_PORT" && break; sleep 0.25; done
kill -0 "$SMTP_PID" 2>/dev/null && busy "$SMTP_PORT" || { echo "The SMTP sink did not start; see $WORK/smtp.log" >&2; cat "$WORK/smtp.log" >&2; exit 2; }

# --- the web server, logging every warning and notice ------------------------
# Several workers, so the background tick after a response cannot hold up the
# next page the way a single-threaded php -S would.
PHP_CLI_SERVER_WORKERS=4 "$PHP" \
    -d display_errors=0 -d log_errors=1 -d error_reporting=-1 \
    -d error_log="$WORK/php-error.log" -d openssl.cafile="$WORK/smtp-cert.pem" \
    -d upload_max_filesize=10M -d post_max_size=12M \
    -S "127.0.0.1:$PORT" -t "$WORK/site/public" > "$WORK/web.log" 2>&1 &
WEB_PID=$!
for _ in $(seq 1 20); do busy "$PORT" && break; sleep 0.25; done
kill -0 "$WEB_PID" 2>/dev/null || { echo "php -S did not start; see $WORK/web.log" >&2; cat "$WORK/web.log" >&2; exit 2; }

MARIADB_VERSION="$(mariadb --socket="$WORK/run/mysql.sock" -uroot -sN -e 'SELECT VERSION()')"
PHP_VERSION="$("$PHP" -r 'echo PHP_VERSION;')"
echo "Source:   $SOURCE"
echo "Work dir: $WORK"
echo "Engine:   MariaDB $MARIADB_VERSION"
echo "PHP:      $PHP_VERSION (php -S)"
echo

set +e
CRM_E2E_BASE="http://127.0.0.1:$PORT" CRM_E2E_WORK="$WORK" CRM_E2E_DB_PORT="$DB_PORT" \
CRM_E2E_SMTP_PORT="$SMTP_PORT" CRM_E2E_SOCKET="$WORK/run/mysql.sock" CRM_E2E_DB="$DB" \
CRM_E2E_ENGINE="MariaDB $MARIADB_VERSION" CRM_E2E_PHP_VERSION="$PHP_VERSION" CRM_E2E_SOURCE="$SOURCE" \
    node "$ROOT/tests/e2e.mjs" "$@"
STATUS=$?
set -e
exit $STATUS
