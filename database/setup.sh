#!/usr/bin/env bash
# setup.sh - Run once (and safely re-run) as root to deploy the shared MariaDB container
# on jackson-brain.com. Usage: sudo bash setup.sh
set -euo pipefail

DB_DIR=/opt/shared-mariadb
DATA_DIR=/storage/mariadb/data
BACKUP_DIR=/storage/backups/mysql
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"

# 1. Data + backup directories (bulk storage, not root fs — see repo memory: `/` is tight).
echo "Creating data directories..."
mkdir -p "${DATA_DIR}" "${BACKUP_DIR}"
chmod 750 "${DATA_DIR}"
chmod 700 "${BACKUP_DIR}"

# 2. Deploy dir + subfolders
mkdir -p "${DB_DIR}"/{conf.d,initdb.d,backup}

# 3. Copy compose + tuning config (safe to overwrite — no secrets in these files)
echo "Installing docker-compose.yml and conf.d/z-tuning.cnf..."
cp "${SRC_DIR}/docker-compose.yml" "${DB_DIR}/docker-compose.yml"
cp "${SRC_DIR}/conf.d/z-tuning.cnf" "${DB_DIR}/conf.d/z-tuning.cnf"
cp "${SRC_DIR}/backup/mariadb-backup.sh" "${DB_DIR}/backup/mariadb-backup.sh"
chmod 750 "${DB_DIR}/backup/mariadb-backup.sh"

# 4. Install/keep .env (never overwrite an existing one — it holds real passwords)
if [[ ! -f "${DB_DIR}/.env" ]]; then
    cp "${SRC_DIR}/.env.template" "${DB_DIR}/.env"
    chmod 600 "${DB_DIR}/.env"
    echo ""
    echo "  ACTION REQUIRED: edit ${DB_DIR}/.env and set:"
    echo "    MARIADB_ROOT_PASSWORD, WORDPRESS_DB_PASSWORD, AIRSONIC_DB_PASSWORD, AMPACHE_DB_PASSWORD"
    echo "  (e.g. openssl rand -base64 24 for each), then re-run this script."
    echo ""
    exit 0
else
    echo ".env already exists, skipping."
fi

# 5. Render initdb.d/01-databases.sql from the template using .env values (only takes
#    effect on a truly empty data dir per the MariaDB entrypoint's own convention — for
#    migrating existing data, schemas/users are created here, existing data is imported
#    afterward per database/README.md's migration runbook).
# shellcheck disable=SC1091
source "${DB_DIR}/.env"
for var in MARIADB_ROOT_PASSWORD WORDPRESS_DB_PASSWORD AIRSONIC_DB_PASSWORD AMPACHE_DB_PASSWORD; do
    if [[ -z "${!var:-}" ]]; then
        echo "ERROR: ${var} is empty in ${DB_DIR}/.env — fill in all values before running setup.sh." >&2
        exit 1
    fi
done

echo "Rendering initdb.d/01-databases.sql..."
# '|' delimiter (not '/') since base64-generated passwords can contain '/'
sed -e "s|__WORDPRESS_DB_PASSWORD__|${WORDPRESS_DB_PASSWORD}|" \
    -e "s|__AIRSONIC_DB_PASSWORD__|${AIRSONIC_DB_PASSWORD}|" \
    -e "s|__AMPACHE_DB_PASSWORD__|${AMPACHE_DB_PASSWORD}|" \
    "${SRC_DIR}/initdb.d/01-databases.sql.template" > "${DB_DIR}/initdb.d/01-databases.sql"
# 644, not 600: the mariadb entrypoint re-execs as its internal unprivileged `mysql` user
# before processing /docker-entrypoint-initdb.d, so a root-only-readable file is silently
# skipped (no init runs, no error). The dir itself (0755, root-owned) is only reachable by
# local admins on this host, so world-readable here is an acceptable tradeoff.
chmod 644 "${DB_DIR}/initdb.d/01-databases.sql"

# 6. Backup systemd units
echo "Installing backup service + timer..."
cp "${SRC_DIR}/backup/shared-mariadb-backup.service" /etc/systemd/system/shared-mariadb-backup.service
cp "${SRC_DIR}/backup/shared-mariadb-backup.timer" /etc/systemd/system/shared-mariadb-backup.timer

# 7. Main systemd unit
echo "Installing shared-mariadb.service..."
cp "${SRC_DIR}/shared-mariadb.service" /etc/systemd/system/shared-mariadb.service
systemctl daemon-reload
systemctl enable shared-mariadb.service
systemctl enable --now shared-mariadb-backup.timer

echo ""
echo "Done. Next steps:"
echo "  1. Start the DB: systemctl start shared-mariadb"
echo "  2. Wait for healthcheck: podman ps --filter name=shared-mariadb"
echo "  3. Follow the migration runbook in database/README.md to import existing data"
echo "     (wordpress + airsonic from system MariaDB, ampache from ampache-mariadb) and"
echo "     repoint Ampache onto this container."
echo "  4. Run a manual backup + restore test: ${DB_DIR}/backup/mariadb-backup.sh"
