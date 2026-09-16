#!/usr/bin/env bash
# Install or update the portal from Git, and bring the database with it.
#
#   bin/update.sh                     update the checkout this script lives in
#   bin/update.sh --ref v0.6.0        update and pin to a tag
#   bin/update.sh --clone /var/www/crm    first install into an empty directory
#   bin/update.sh --check             say what would happen, change nothing
#
# The portal can also update itself from a file upload - see UPDATING.md. This
# script is for a server that has a shell, where "git pull" is less work than
# unpacking a ZIP, and where a wrong step should stop rather than continue.
#
# Nothing here re-implements the update. Every safeguard - refusing older files,
# refusing an incomplete checkout, the backup, the row-count comparison - lives
# in app/schema.php, and this calls the same console command a hosting cron job
# would. A second implementation is how the two start protecting her differently.
set -euo pipefail

REPO_URL="https://github.com/mangoman16/crmb"
REF=""
TARGET=""
CLONE=0
CHECK=0
RUN_COMPOSER=1

die() { printf '\n%s\n' "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }

while [ $# -gt 0 ]; do
    case "$1" in
        --clone)       CLONE=1; TARGET="${2:-}"; [ -n "$TARGET" ] || die "--clone needs a directory."; shift 2 ;;
        --ref)         REF="${2:-}"; [ -n "$REF" ] || die "--ref needs a tag or branch."; shift 2 ;;
        --repo)        REPO_URL="${2:-}"; [ -n "$REPO_URL" ] || die "--repo needs a URL."; shift 2 ;;
        --check)       CHECK=1; shift ;;
        --no-composer) RUN_COMPOSER=0; shift ;;
        -h|--help)     sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)             die "Unknown option: $1. Try --help." ;;
    esac
done

command -v git >/dev/null || die "git is not installed."
command -v php >/dev/null || die "php is not installed."

# ---------------------------------------------------------------------------
# Where the portal lives
# ---------------------------------------------------------------------------
if [ "$CLONE" -eq 1 ]; then
    if [ -e "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null || true)" ]; then
        die "$TARGET exists and is not empty. To update an existing checkout, run bin/update.sh inside it."
    fi
    step "Cloning $REPO_URL into $TARGET"
    [ "$CHECK" -eq 1 ] && { say "would clone; stopping because of --check"; exit 0; }
    git clone ${REF:+--branch "$REF"} "$REPO_URL" "$TARGET"
    ROOT="$(cd "$TARGET" && pwd)"
else
    ROOT="$(cd "$(dirname "$0")/.." && pwd)"
    [ -d "$ROOT/.git" ] || die "$ROOT is not a Git checkout. For a first install use: bin/update.sh --clone /path/to/directory"
fi

cd "$ROOT"
FROM_VERSION="$(tr -d '[:space:]' < VERSION 2>/dev/null || echo unknown)"
say "Portal:  $ROOT"
say "Version: $FROM_VERSION"

# ---------------------------------------------------------------------------
# Refuse to update over work that has not been saved
# ---------------------------------------------------------------------------
# config/config.php and storage/ are ignored by Git, so a pull never touches
# them. Anything else that differs is an edit somebody made on the server, and
# overwriting it silently is how a fix disappears without anyone noticing.
if [ "$CLONE" -eq 0 ]; then
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        say ""
        git status --short --untracked-files=no
        die "There are uncommitted changes in $ROOT. Commit, stash or revert them first; this script will not overwrite them."
    fi

    step "Fetching $REPO_URL"
    git fetch --tags --prune origin

    if [ -n "$REF" ]; then
        TARGET_REF="$REF"
    elif BRANCH="$(git symbolic-ref --quiet --short HEAD)"; then
        TARGET_REF="origin/$BRANCH"
    else
        # A checkout pinned with --ref sits on a detached HEAD. Following
        # "origin/HEAD" from there would quietly move the server onto whatever
        # the default branch happens to be today, which is the opposite of what
        # pinning to a tag was for.
        die "This checkout is pinned to $(git describe --tags --always HEAD) and is not on a branch.
Say which release you want:  bin/update.sh --ref <tag>
or put it back on a branch:  git checkout main"
    fi
    if [ "$(git rev-parse HEAD)" = "$(git rev-parse "$TARGET_REF^{commit}")" ]; then
        say "Already at $TARGET_REF."
    elif [ "$CHECK" -eq 1 ]; then
        say "Would move from $(git rev-parse --short HEAD) to $(git rev-parse --short "$TARGET_REF"):"
        git --no-pager log --oneline HEAD.."$TARGET_REF" | sed 's/^/    /'
    else
        step "Updating the files to $TARGET_REF"
        # --ff-only rather than a merge: a checkout on a server should be a copy
        # of a release, and anything that cannot fast-forward means it is not.
        if [ -n "$REF" ]; then git checkout --quiet "$REF"; else git merge --ff-only "$TARGET_REF"; fi
    fi
fi

TO_VERSION="$(tr -d '[:space:]' < VERSION)"

# ---------------------------------------------------------------------------
# Dependencies
# ---------------------------------------------------------------------------
# A Git checkout has no vendor/ - the ZIP release is the one that ships it - so
# email and the payment QR code do not work until this has run.
if [ "$RUN_COMPOSER" -eq 1 ] && [ -f composer.json ]; then
    if command -v composer >/dev/null; then
        if [ "$CHECK" -eq 1 ]; then
            say "Would run composer install."
        else
            step "Installing the dependencies"
            composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
        fi
    elif [ ! -d vendor ]; then
        say ""
        say "WARNING: composer is not installed and vendor/ is missing."
        say "         The portal will run, but it cannot send email or draw a payment QR code."
        say "         Install Composer, or use the ZIP release, which bundles both."
    fi
fi

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------
if [ ! -f config/config.php ] && [ -z "${CRM_CONFIG:-}" ]; then
    step "No configuration yet"
    say "The files are in place. Open the portal's address in a browser to finish"
    say "the installation; it asks for the database details and the first account."
    exit 0
fi

if [ "$CHECK" -eq 1 ]; then
    step "Database status"
    # status exits non-zero when the files and the database disagree, which is
    # the answer --check exists to give rather than a failure of this script.
    if php bin/console.php status; then
        say ""
        say "Nothing to do."
    else
        say ""
        say "An update is outstanding. Run bin/update.sh without --check."
    fi
    exit 0
fi

step "Updating the database"
# console.php update switches maintenance mode on, takes a backup, migrates,
# compares the row counts and switches maintenance mode off again. If it fails
# it leaves the portal closed on purpose, so stop here rather than reporting
# success over the top of it.
php bin/console.php update

step "Status"
# Same here: a disagreement after an update is worth reporting, not worth
# swallowing the summary below for.
php bin/console.php status || say "The files and the database do not agree. Read the lines above."
say ""
if [ "$FROM_VERSION" != "$TO_VERSION" ]; then
    say "Updated from $FROM_VERSION to $TO_VERSION."
else
    say "Still on $TO_VERSION; nothing about the files changed."
fi
