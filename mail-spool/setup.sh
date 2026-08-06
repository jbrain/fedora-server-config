#!/usr/bin/env bash
# setup.sh - Run once as root to prune local mail spool files (root, jack, etc.) so
# accumulated cron/netdata/systemd notification mail doesn't grow unbounded on disk.
# Usage: sudo bash setup.sh
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
DEST=/etc/logrotate.d/mail-spool

echo "Deploying ${DEST}..."
cp "${SRC_DIR}/logrotate.d/mail-spool.conf" "${DEST}"
sed -i 's/\r$//' "${DEST}"   # defensive: strip CRLF in case this repo was checked out on Windows
chmod 644 "${DEST}"

echo "Validating logrotate config..."
logrotate -d "${DEST}"

echo "Forcing an initial rotation to prune any mailboxes already oversized..."
logrotate -f "${DEST}"

echo ""
echo "Done. /var/spool/mail/* will now rotate weekly (or immediately past 10M), keeping"
echo "4 compressed generations. logrotate.timer (ships with the OS) runs this daily."
