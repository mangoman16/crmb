#!/usr/bin/env bash
# Install or update the portal from Git, and bring the database with it.
#
#   bin/update.sh                     update the checkout this script lives in, from its origin
#   bin/update.sh --ref v0.6.0        update and pin to a tag
#   bin/update.sh --clone /var/www/crm    first install into an empty directory
#   bin/update.sh --clone /var/www/crm --repo URL    the same, from another copy of the repository
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

REPO_URL=""
REF=""
TARGET=""
CLONE=0
CHECK=0
RUN_COMPOSER=1

die() { printf '\n%s\n' "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
# An address as it is shown: without a user name or token written into it,
# since what this prints can end up in a cron job's mail. Up to the last '@'
# before the path, as a password may hold a raw '@' of its own.
shown_url() { printf '%s' "$1" | sed -E 's#^([A-Za-z][A-Za-z0-9+.-]*://)[^/]*@#\1#'; }

while [ $# -gt 0 ]; do
    # A value that starts with '-' is the next option, not this one's: with the
    # tag forgotten, "--ref --check" took --check as the tag, skipped the dry run
    # and handed it to git checkout, which read it as an option of its own.
    case "$1" in
        --clone)       CLONE=1; TARGET="${2:-}"; case $TARGET in ''|-*) die "--clone needs a directory.";; esac; shift 2 ;;
        --ref)         REF="${2:-}"; case $REF in ''|-*) die "--ref needs a tag or branch.";; esac; shift 2 ;;
        --repo)        REPO_URL="${2:-}"; case $REPO_URL in ''|-*) die "--repo needs a URL.";; esac; shift 2 ;;
        --check)       CHECK=1; shift ;;
        --no-composer) RUN_COMPOSER=0; shift ;;
        # The comment at the top, up to the first line of code. A fixed range of
        # lines printed the code after it as well.
        -h|--help)     awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"; exit 0 ;;
        *)             die "Unknown option: $1. Try --help." ;;
    esac
done
# An update fetches from the checkout's own origin, where --repo would change
# nothing but the line on the screen: refused rather than ignored.
[ -z "$REPO_URL" ] || [ "$CLONE" -eq 1 ] \
    || die "--repo goes with --clone. An update fetches from this checkout's own origin; to change that: git remote set-url origin <url>"

command -v git >/dev/null || die "git is not installed."
command -v php >/dev/null || die "php is not installed."

# ---------------------------------------------------------------------------
# Where the portal lives
# ---------------------------------------------------------------------------
if [ "$CLONE" -eq 1 ]; then
    if [ -e "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null || true)" ]; then
        die "$TARGET exists and is not empty. To update an existing checkout, run bin/update.sh inside it."
    fi
    # A first install has no origin yet, so it comes from the portal's home
    # unless --repo names another copy, a club's own for instance. The clone
    # keeps that address as its origin, which every later update fetches.
    REPO_URL="${REPO_URL:-https://github.com/mangoman16/crmb}"
    step "Cloning $(shown_url "$REPO_URL") into $TARGET"
    [ "$CHECK" -eq 1 ] && { say "would clone; stopping because of --check"; exit 0; }
    git clone ${REF:+--branch "$REF"} -- "$REPO_URL" "$TARGET"
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

    # The checkout's own origin, which on another club's server is that club's
    # repository rather than ours: the line names what is fetched, as git has it.
    # git config rather than git remote get-url, which needs git 2.7.
    ORIGIN_URL="$(git config --get remote.origin.url)" \
        || die "This checkout has no remote called origin to fetch from. Add one with: git remote add origin <url>"
    step "Fetching from origin, $(shown_url "$ORIGIN_URL")"
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
            # gd is the one requirement waived here. composer.json requires ext-gd
            # for the machine that builds the release, but the portal installs and
            # runs without it, with no pictures (ADR 0031, the amendment "gd is
            # optional"). Composer checks every requirement against this host's PHP
            # before it installs anything, so without the flag a host without gd
            # would stop here, where setup.php would have installed. Only gd: a
            # host missing anything else the portal needs stops, as setup.php does.
            # The flag takes one name since Composer 2.0.
            composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --ignore-platform-req=ext-gd
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
