#!/usr/bin/env bash
# Build the distribution ZIP: the one file the operator uploads.
#
#   bin/release.sh            writes badminton-crm-<version>.zip beside the project
#   bin/release.sh <folder>   writes it into <folder> instead
#   bin/release.sh <file>     writes it as <file>
#
# "Upload and open the portal" only holds if the upload already contains
# everything, so this installs the dependencies, drops a deny file into vendor/
# for hosting without mod_rewrite, and leaves out what belongs to development.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
git -C "$ROOT" rev-parse --verify --quiet HEAD >/dev/null 2>&1 \
    || { echo "Not a git checkout: the package is built from its committed HEAD." >&2; exit 2; }

# The package is HEAD, so its version is HEAD's. Read from the working tree, a
# VERSION changed but not yet committed would name the ZIP after one release and
# put another inside it.
VERSION="$(git -C "$ROOT" show HEAD:VERSION | tr -d '[:space:]')"
[ "$VERSION" = "$(tr -d '[:space:]' < "$ROOT/VERSION")" ] \
    || { echo "VERSION is changed but not committed. The package is built from HEAD, so commit VERSION first." >&2; exit 2; }
# A release is 0.6.0; a package handed out before it is 0.6.0-beta.2. The portal
# only ever compares versions for equality, so the suffix needs nothing else.
RELEASE_OR_BETA='^[0-9]+\.[0-9]+\.[0-9]+(-beta\.[0-9]+)?$'
[[ $VERSION =~ $RELEASE_OR_BETA ]] \
    || { echo "VERSION is \"$VERSION\": neither a release such as 0.6.0 nor a beta such as 0.6.0-beta.2." >&2; exit 2; }

# What ships, named one by one, and what stays behind. Every entry at the top of
# the repository has to be in one of these lists, and a new one stops the build
# until somebody decides which: a list of only what to leave out shipped .claude/,
# the agents' prompts, from the day it was added until somebody noticed. The
# notes that ship are the ones the operator has a use for: what this is, how to
# install and update it, what changed, what has actually been verified, and the
# checks to walk by hand. docs/ ships whole, the decisions and design
# specifications with it: they are part of the source the GPL asks for, README
# links them, and docs/.htaccess keeps them off the web. bin/ ships whole too:
# console.php is what a cron job set up in the hosting panel runs (INSTALL.md),
# update.sh is the update for an owner with a shell, and release.sh is the
# script that builds the source, which the GPL counts as part of it.
SHIP=(.htaccess index.php VERSION LICENSE README.md INSTALL.md UPDATING.md CHANGELOG.md VALIDATION.md TESTING.md
      app bin config database docs public storage views)
BUILD_ONLY=(composer.json composer.lock)   # read by composer below, then removed
# tests/e2e.sh reads this line, and the one below it, to tell a package built here from one that was not.
LEAVE_OUT=(.claude .gitignore CLAUDE.md ROADMAP.md tests)
CONFIG_AND_STORAGE=(config/.htaccess config/config.example.php storage/.htaccess storage/.gitkeep)
UNDECIDED="$(git -C "$ROOT" ls-tree --name-only HEAD \
    | grep -vxF -f <(printf '%s\n' "${SHIP[@]}" "${BUILD_ONLY[@]}" "${LEAVE_OUT[@]}") || true)"
[ -z "$UNDECIDED" ] \
    || { echo "Neither shipped nor left out: ${UNDECIDED//$'\n'/ }. Add each to SHIP or LEAVE_OUT in bin/release.sh." >&2; exit 2; }

# config/ and storage/ hold the database password and the families' data, so
# they ship as their deny files and the example configuration, nothing more.
# Anything else git holds there is already in git's history: leaving it out of
# the package without a word would hide that, so the build stops and says so.
IN_GIT="$(git -C "$ROOT" ls-tree -r --name-only HEAD -- config storage \
    | grep -vxF -f <(printf '%s\n' "${CONFIG_AND_STORAGE[@]}") || true)"
[ -z "$IN_GIT" ] \
    || { echo "git already holds ${IN_GIT//$'\n'/ }, and nothing in config/ or storage/ may be in it but the deny files and config.example.php. Remove it from git with \"git rm --cached\" and change any password in it: git's history keeps the old one." >&2; exit 2; }

NAME="badminton-crm-$VERSION"
OUT="${1:-$ROOT/../$NAME.zip}"
# Given a folder, the file in it is named after VERSION, like the default.
[ -d "$OUT" ] && OUT="${OUT%/}/$NAME.zip"
# zip runs inside the staging folder below; a relative path would land in it and
# be deleted with it.
case $OUT in /*) ;; *) OUT="$PWD/$OUT" ;; esac
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

command -v composer >/dev/null || { echo "composer not found; it is needed to bundle the dependencies." >&2; exit 2; }
command -v zip >/dev/null || { echo "zip not found." >&2; exit 2; }

COMMIT="$(git -C "$ROOT" rev-parse HEAD)"
echo "Building $NAME from commit ${COMMIT:0:7}"
# A name in SHIP that HEAD no longer has stops git here, so the list cannot rot.
git -C "$ROOT" archive --format=tar HEAD -- "${SHIP[@]}" "${BUILD_ONLY[@]}" | (mkdir -p "$STAGE/$NAME" && tar -x -C "$STAGE/$NAME")
# Which commit the package is, so that a walk of it (tests/e2e.sh with
# CRM_E2E_ZIP) and VALIDATION.md can name one: VERSION alone cannot, since it
# stays the same across many commits. Nothing in the portal reads this file,
# and the root .htaccess denies .txt files to the web.
printf 'commit %s\nbuilt %s UTC\n' "$COMMIT" "$(date -u +'%Y-%m-%d %H:%M')" > "$STAGE/$NAME/BUILD.txt"

# The lock file decides the versions; never resolve them again at build time.
composer install --working-dir="$STAGE/$NAME" --no-dev --prefer-dist --optimize-autoloader --quiet

# composer falls back to cloning when it cannot fetch a dist archive, and the
# repositories it then leaves behind are an order of magnitude larger than the
# code. None of this is loaded by the autoloader, and the operator uploads the
# whole file through a browser.
find "$STAGE/$NAME/vendor" -type d -name '.git' -prune -exec rm -rf {} +
find "$STAGE/$NAME/vendor" -type d \( -name '.github' -o -name 'examples' -o -name 'test' -o -name 'tests' \) -prune -exec rm -rf {} +
echo 'Require all denied' > "$STAGE/$NAME/vendor/.htaccess"
# Prove the package can still send email and draw a QR code after that pruning.
php -r 'require $argv[1];
    foreach (["PHPMailer\\PHPMailer\\PHPMailer", "PHPMailer\\PHPMailer\\SMTP", "BaconQrCode\\Writer"] as $class)
        if (!class_exists($class)) { fwrite(STDERR, "Missing after packaging: $class\n"); exit(1); }' \
    "$STAGE/$NAME/vendor/autoload.php"

# composer has read these.
rm -f "$STAGE/$NAME/composer.json" "$STAGE/$NAME/composer.lock"

# The list the running portal checks itself against before it migrates, so a
# half-finished extract or an FTP client in text mode is caught before it can
# touch the database. Written last, so it covers the tree as it ships.
( cd "$STAGE/$NAME" && find app views public bin database -type f \( -name '*.php' -o -name '*.sql' \) \
    | LC_ALL=C sort | xargs sha256sum > MANIFEST )

# The upload has to arrive with these writable, or the installer cannot finish.
chmod 755 "$STAGE/$NAME/config" "$STAGE/$NAME/storage"

rm -f "$OUT"
(cd "$STAGE" && zip -qr "$OUT" "$NAME")
echo "Wrote $OUT"
unzip -l "$OUT" | tail -1
