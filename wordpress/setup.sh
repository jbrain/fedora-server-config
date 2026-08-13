#!/usr/bin/env bash
# setup.sh - Run once (and safely re-run) as root to deploy WordPress on jackson-brain.com
# Usage: sudo bash setup.sh
set -euo pipefail

WP_DIR=/opt/wordpress
CONTENT_DIR=/storage/wordpress/wp-content
SRC_WP_CONTENT=/usr/share/wordpress/wp-content
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
# Official wordpress:*-apache image runs as UID/GID 33 (www-data), NOT this host's native
# `apache` user (UID/GID 48) — ownership must be translated during migration, not assumed equal.
WWW_UID=33
WWW_GID=33

# 1. Persistent content dir (bulk storage, not root fs)
echo "Creating ${CONTENT_DIR}..."
mkdir -p "${CONTENT_DIR}"

# 2. One-time migration of existing wp-content (themes/plugins/uploads) — only if the
#    destination is still empty, so re-running this script is safe.
if [[ -z "$(ls -A "${CONTENT_DIR}" 2>/dev/null)" && -d "${SRC_WP_CONTENT}" ]]; then
    echo "Migrating existing wp-content from ${SRC_WP_CONTENT}..."
    cp -a "${SRC_WP_CONTENT}/." "${CONTENT_DIR}/"
    chown -R "${WWW_UID}:${WWW_GID}" "${CONTENT_DIR}"
    echo "Migrated. Original at ${SRC_WP_CONTENT} left untouched (rollback)."
else
    echo "${CONTENT_DIR} already has content, skipping migration."
fi

# 3. Deploy dir + compose files
mkdir -p "${WP_DIR}"
echo "Installing docker-compose.yml and uploads.ini..."
cp "${SRC_DIR}/docker-compose.yml" "${WP_DIR}/docker-compose.yml"
cp "${SRC_DIR}/uploads.ini" "${WP_DIR}/uploads.ini"

# 4. Install/keep .env (never overwrite an existing one)
if [[ ! -f "${WP_DIR}/.env" ]]; then
    cp "${SRC_DIR}/.env.template" "${WP_DIR}/.env"
    chmod 600 "${WP_DIR}/.env"
    echo ""
    echo "  ACTION REQUIRED: edit ${WP_DIR}/.env — set WORDPRESS_DB_PASSWORD to match"
    echo "  WORDPRESS_DB_PASSWORD in ../database/.env (the shared MariaDB 'wordpress' user)."
    echo ""
else
    echo ".env already exists, skipping."
fi

# 5. systemd unit
echo "Installing wordpress.service..."
cp "${SRC_DIR}/wordpress.service" /etc/systemd/system/wordpress.service
echo "Installing wordpress-wp-cron service/timer..."
cp "${SRC_DIR}/wordpress-wp-cron.service" /etc/systemd/system/wordpress-wp-cron.service
cp "${SRC_DIR}/wordpress-wp-cron.timer" /etc/systemd/system/wordpress-wp-cron.timer
systemctl daemon-reload
systemctl enable wordpress.service
systemctl enable --now wordpress-wp-cron.timer

echo ""
echo "Done. Next steps:"
echo "  1. Confirm ${WP_DIR}/.env is filled in."
echo "  2. Start: systemctl start wordpress"
echo "  3. Confirm timer: systemctl status wordpress-wp-cron.timer"
echo "  4. Wait for healthcheck: podman ps --filter name=wordpress"
echo "  5. Smoke-test directly: curl -I http://127.0.0.1:8082/"
echo "  6. Follow wordpress/README.md to repoint the Apache vhost."
