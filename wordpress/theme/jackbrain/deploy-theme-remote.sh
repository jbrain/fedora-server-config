#!/bin/bash
# Runs ON jackson-brain.com (invoked via sudo by deploy-theme.ps1, never directly).
# Args: $1 = staging directory containing the files to deploy (mirrors the theme's own
#            directory structure, e.g. staging/style.css, staging/js/jbrain.js)
#       $2 = "1" to restart the wordpress container after deploying, "0" to skip it
set -euo pipefail

STAGING_DIR="${1:?staging directory argument required}"
DO_RESTART="${2:-1}"
THEME_DIR="/storage/wordpress/wp-content/themes/jackbrain"
BACKUP_DIR="/storage/backups/wordpress-theme-jackbrain-bak"
TS=$(date +%Y%m%d-%H%M%S)

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run with sudo/as root." >&2
    exit 1
fi

if [ ! -d "$STAGING_DIR" ]; then
    echo "Staging directory $STAGING_DIR not found." >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"

echo "== Deploying jackbrain theme files from $STAGING_DIR =="
DEPLOYED=0
while IFS= read -r -d '' STAGED_FILE; do
    REL_PATH="${STAGED_FILE#"$STAGING_DIR"/}"
    LIVE_FILE="$THEME_DIR/$REL_PATH"
    LIVE_DIR=$(dirname "$LIVE_FILE")

    # New subdirectories must match the theme dir's own ownership/mode, not root's
    # default umask, or the container's www-data-equivalent user can't read them.
    mkdir -p "$LIVE_DIR"
    chown --reference="$THEME_DIR" "$LIVE_DIR"
    chmod 755 "$LIVE_DIR"

    if [ -f "$LIVE_FILE" ]; then
        BACKUP_NAME="$(echo "$REL_PATH" | tr '/' '_').bak-$TS"
        cp -p "$LIVE_FILE" "$BACKUP_DIR/$BACKUP_NAME"
        echo "  backed up $REL_PATH -> $BACKUP_DIR/$BACKUP_NAME"
    fi

    cp "$STAGED_FILE" "$LIVE_FILE"
    chown --reference="$THEME_DIR" "$LIVE_FILE"
    chmod 644 "$LIVE_FILE"
    echo "  deployed $REL_PATH"
    DEPLOYED=$((DEPLOYED + 1))
done < <(find "$STAGING_DIR" -type f -print0)

echo "== $DEPLOYED file(s) deployed =="

if [ "$DO_RESTART" = "1" ]; then
    # Required, not optional: the wp-content bind mount uses SELinux :Z, which only
    # relabels to match the container's current MCS category on a fresh container
    # start - files copied in while the container keeps running can otherwise become
    # unreadable (see repo memory's "SELinux MCS mismatch" gotcha from 2026-08-16).
    echo "== Restarting wordpress container (forces a fresh SELinux :Z relabel) =="
    systemctl restart wordpress
    sleep 8
else
    echo "== Skipping container restart (restart=0) - changes will NOT take effect, and may not even be readable, until the next restart =="
fi

echo "== Verifying site health =="
sleep 2
HOME_CODE=$(curl -s -o /dev/null -w '%{http_code}' https://jackson-brain.com/ || echo "000")
ABOUT_CODE=$(curl -s -o /dev/null -w '%{http_code}' https://jackson-brain.com/about/ || echo "000")
echo "  home:  $HOME_CODE"
echo "  about: $ABOUT_CODE"

if [ "$HOME_CODE" != "200" ] || [ "$ABOUT_CODE" != "200" ]; then
    echo "WARNING: site did not return 200 after deploy. Backups for this deploy are in" >&2
    echo "$BACKUP_DIR with the '.bak-$TS' suffix - restore them manually if needed." >&2
    exit 2
fi

echo "== Deploy verified OK =="
rm -rf "$STAGING_DIR"
