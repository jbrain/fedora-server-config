#!/usr/bin/env bash
# setup.sh - Run once as root to deploy Ampache on jackson-brain.com
# Usage: sudo bash setup.sh
set -euo pipefail

AMPACHE_DIR=/opt/ampache
COMPOSE_SRC="$(dirname "$0")/docker-compose.yml"
ENV_SRC="$(dirname "$0")/.env.template"
SERVICE_SRC="$(dirname "$0")/ampache.service"
VHOST_SRC="$(dirname "$0")/ampache.jackson-brain.com.conf"
VHOST_DEST="/etc/httpd/conf.d/vhosts/musicbox.jackson-brain.com.conf"

# 1. Create persistent data directories
# (DB moved to the shared MariaDB container 2026-08 — see ../database/ — so no local
# mariadb data dir is created for fresh installs. An existing /opt/ampache/mariadb from
# before the migration is left in place as a rollback copy, not deleted here.)
echo "Creating data directories..."
mkdir -p "${AMPACHE_DIR}"/{config,log}
# Ampache app container runs as UID/GID 33 (www-data)
chown -R 33:33 "${AMPACHE_DIR}/config" "${AMPACHE_DIR}/log"

# 2. Copy compose file
echo "Installing docker-compose.yml..."
cp "${COMPOSE_SRC}" "${AMPACHE_DIR}/docker-compose.yml"

# 2a. Copy custom theme + branding assets (bind-mounted volumes, relative to the compose
# file's own directory — see docker-compose.yml volumes for why these must live here).
echo "Installing custom theme + assets..."
rm -rf "${AMPACHE_DIR}/themes" "${AMPACHE_DIR}/assets"
cp -r "$(dirname "$0")/themes/jackbrain" "${AMPACHE_DIR}/themes_tmp"
mkdir -p "${AMPACHE_DIR}/themes"
mv "${AMPACHE_DIR}/themes_tmp" "${AMPACHE_DIR}/themes/jackbrain"
cp -r "$(dirname "$0")/assets" "${AMPACHE_DIR}/assets"
chown -R 33:33 "${AMPACHE_DIR}/themes" "${AMPACHE_DIR}/assets"

# 2b. Copy the theme-preferences.sql disaster-recovery script alongside (referenced in the
# "Next steps" printout below — not applied automatically since the DB schema/preference
# rows may not exist yet on a truly fresh install).
cp "$(dirname "$0")/theme-preferences.sql" "${AMPACHE_DIR}/theme-preferences.sql"

# 3. Install .env if not already present (don't overwrite existing credentials)
if [[ ! -f "${AMPACHE_DIR}/.env" ]]; then
    cp "${ENV_SRC}" "${AMPACHE_DIR}/.env"
    chmod 600 "${AMPACHE_DIR}/.env"
    echo ""
    echo "  ACTION REQUIRED: Edit ${AMPACHE_DIR}/.env and set secure passwords"
    echo "  before starting the service."
    echo ""
else
    echo ".env already exists, skipping."
fi

# 4. Pre-create ampache.cfg.php from the .dist template so Ampache skips the web
#    installer (which fails when the DB already has any data). The config will be
#    populated from .env variables; update the DB password and local_web_path before
#    starting the service.
if [[ ! -f "${AMPACHE_DIR}/config/ampache.cfg.php" ]]; then
    echo "Pre-creating ampache.cfg.php from .dist template..."
    # The .dist file is shipped inside the image; extract it via a temporary container
    podman run --rm --entrypoint cat ampache/ampache:nosql7 \
        /var/www/config/ampache.cfg.php.dist > "${AMPACHE_DIR}/config/ampache.cfg.php"
    # The Subsonic/OpenSubsonic REST API (rest/*.view — required for Subsonic clients like
    # Music Assistant) is gated by AmpConfig::get('subsonic_backend') BEFORE any DB
    # preference lookup happens, so it must be set in the cfg.php file itself — the
    # "subsonic_backend" row in the admin UI's user_preference table is NOT consulted at
    # this early check. The .dist template doesn't even mention this key, so without this,
    # every /rest/*.view request (ping, stream, everything) silently returns the literal
    # body "Disabled" instead of an error. See ampache/README.md Troubleshooting.
    cat >> "${AMPACHE_DIR}/config/ampache.cfg.php" <<'EOF'

; Enable the Subsonic/OpenSubsonic REST API backend (rest/*.view).
; Required for Subsonic-compatible clients (e.g. Music Assistant). This gate is
; checked before any DB preference lookup, so it MUST be set here, not just in
; the "subsonic_backend" user preference in the admin UI.
; DEFAULT: "false"
subsonic_backend = "true"
EOF
    chown 33:33 "${AMPACHE_DIR}/config/ampache.cfg.php"
    chmod 640 "${AMPACHE_DIR}/config/ampache.cfg.php"
    echo "  ACTION REQUIRED: Edit ${AMPACHE_DIR}/config/ampache.cfg.php — set"
    echo "  database_password and local_web_path before starting the service."
else
    echo "ampache.cfg.php already exists, skipping."
fi

# 5. Install systemd service
echo "Installing systemd service..."
cp "${SERVICE_SRC}" /etc/systemd/system/ampache.service
systemctl daemon-reload
systemctl enable ampache.service

# 6. Install Apache vhost — uses the *.jackson-brain.com wildcard cert (no certbot run needed)
echo "Installing Apache vhost..."
cp "${VHOST_SRC}" "${VHOST_DEST}"

# 7. Reload Apache
echo "Reloading Apache..."
httpd -t && systemctl reload httpd

echo ""
echo "Done. Next steps:"
echo "  1. Edit ${AMPACHE_DIR}/.env — set MYSQL_PASS (must match AMPACHE_DB_PASSWORD in"
echo "     ../database/.env) and AMPACHE_ADMIN_PASSWORD"
echo "  1a. Deploy/start the shared MariaDB container first if not already running:"
echo "      see ../database/README.md"
echo "  2. Start service: systemctl start ampache"
echo "  3. Wait for mariadb healthcheck (~30s), then initialize the schema:"
echo "       sudo podman exec ampache sh -c 'mariadb -uampache -p\$AMPACHE_DB_PASSWORD -hmariadb ampache < /var/www/resources/sql/ampache.sql'"
echo "  4. Create admin user:"
echo "       sudo podman exec ampache php /var/www/bin/cli admin:addUser admin --email admin@jackson-brain.com --level 100 --password <password>"
echo "  5. Log in at https://musicbox.jackson-brain.com and add /media as a catalog"
echo "  6. Apply the jackbrain theme + branding DB preferences (site title, logo, favicon):"
echo "       sudo sh -c 'source /opt/shared-mariadb/.env && podman exec -i \\"
echo "         -e MYSQL_PWD=\"\$MARIADB_ROOT_PASSWORD\" shared-mariadb mariadb -u root ampache \\"
echo "         < ${AMPACHE_DIR}/theme-preferences.sql'"
