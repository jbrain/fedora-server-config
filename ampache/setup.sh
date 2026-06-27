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
echo "Creating data directories..."
mkdir -p "${AMPACHE_DIR}"/{config,mariadb,log}
# Ampache app container runs as UID/GID 33 (www-data)
chown -R 33:33 "${AMPACHE_DIR}/config" "${AMPACHE_DIR}/log"
# mariadb dir: MariaDB container initializes ownership itself; just lock down perms
chmod 750 "${AMPACHE_DIR}/mariadb"

# 2. Copy compose file
echo "Installing docker-compose.yml..."
cp "${COMPOSE_SRC}" "${AMPACHE_DIR}/docker-compose.yml"

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
    docker run --rm --entrypoint cat ampache/ampache:nosql7 \
        /var/www/config/ampache.cfg.php.dist > "${AMPACHE_DIR}/config/ampache.cfg.php"
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
echo "  1. Edit ${AMPACHE_DIR}/.env — set MYSQL_ROOT_PASS, MYSQL_PASS, AMPACHE_ADMIN_PASSWORD"
echo "  2. Start service: systemctl start ampache"
echo "  3. Wait for mariadb healthcheck (~30s), then initialize the schema:"
echo "       sudo docker exec ampache sh -c 'mariadb -uampache -p\$AMPACHE_DB_PASSWORD -hmariadb ampache < /var/www/resources/sql/ampache.sql'"
echo "  4. Create admin user:"
echo "       sudo docker exec ampache php /var/www/bin/cli admin:addUser admin --email admin@jackson-brain.com --level 100 --password <password>"
echo "  5. Log in at https://musicbox.jackson-brain.com and add /media as a catalog"
