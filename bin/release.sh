#!/usr/bin/env bash
# Build the distribution ZIP: the one file the operator uploads.
#
#   bin/release.sh            writes badminton-crm-<version>.zip beside the project
#
# "Upload and open the portal" only holds if the upload already contains
# everything, so this installs the dependencies, drops a deny file into vendor/
# for hosting without mod_rewrite, and leaves out what belongs to development.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
NAME="badminton-crm-$VERSION"
OUT="${1:-$ROOT/../$NAME.zip}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

command -v composer >/dev/null || { echo "composer not found; it is needed to bundle the dependencies." >&2; exit 2; }
command -v zip >/dev/null || { echo "zip not found." >&2; exit 2; }

echo "Building $NAME"
git -C "$ROOT" archive --format=tar HEAD | (mkdir -p "$STAGE/$NAME" && tar -x -C "$STAGE/$NAME")

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

# Development-only, and a config nobody should receive pre-filled. The notes
# that stay are the ones the operator has a use for: what this is, how to install
# it, how to update it, what changed and what has actually been verified.
rm -rf "$STAGE/$NAME/tests" "$STAGE/$NAME/.github" "$STAGE/$NAME/.gitignore" "$STAGE/$NAME/.gitattributes"
rm -f  "$STAGE/$NAME/config/config.php" "$STAGE/$NAME/composer.json" "$STAGE/$NAME/composer.lock"
rm -f  "$STAGE/$NAME/AUDIT.md" "$STAGE/$NAME/CLAUDE.md" "$STAGE/$NAME/PROJECT.md" \
       "$STAGE/$NAME/ROADMAP.md" "$STAGE/$NAME/GITHUB.md"

# The upload has to arrive with these writable, or the installer cannot finish.
chmod 755 "$STAGE/$NAME/config" "$STAGE/$NAME/storage"

rm -f "$OUT"
(cd "$STAGE" && zip -qr "$OUT" "$NAME")
echo "Wrote $OUT"
unzip -l "$OUT" | tail -1
