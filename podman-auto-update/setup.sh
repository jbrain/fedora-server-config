#!/usr/bin/env bash
# setup.sh - Run once as root to enable Podman container image auto-updates on
# jackson-brain.com. Usage: sudo bash setup.sh
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
ACTIONS_DIR=/etc/dnf/plugins/post-transaction-actions.d

echo "Installing python3-dnf-plugin-post-transaction-actions..."
dnf install -y python3-dnf-plugin-post-transaction-actions

echo "Deploying podman-auto-update.action..."
mkdir -p "${ACTIONS_DIR}"
cp "${SRC_DIR}/post-transaction-actions.d/podman-auto-update.action" \
   "${ACTIONS_DIR}/podman-auto-update.action"

echo "Enabling podman-auto-update.timer (daily baseline check)..."
systemctl enable --now podman-auto-update.timer

echo ""
echo "Done. Containers opt in via the docker-compose.yml label:"
echo '  labels: ["io.containers.autoupdate=registry"]'
echo "with a fully-qualified image: reference. See README.md for verification steps."
