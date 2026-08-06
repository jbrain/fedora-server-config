#!/usr/bin/env bash
# mariadb-backup.sh — logical per-database dump of the shared MariaDB container.
# Run manually or via shared-mariadb-backup.timer (daily).
set -euo pipefail

COMPOSE_DIR=/opt/shared-mariadb
BACKUP_DIR=/storage/backups/mysql
RETENTION_DAYS=14
DATE=$(date +%F)

# shellcheck disable=SC1091
source "${COMPOSE_DIR}/.env"

mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

for db in wordpress ampache; do
    dest="${BACKUP_DIR}/${db}-${DATE}.sql.gz"
    echo "Dumping ${db} -> ${dest}"
    podman exec shared-mariadb sh -c \
        "mariadb-dump --single-transaction --routines --triggers -uroot -p'${MARIADB_ROOT_PASSWORD}' '${db}'" \
        | gzip > "${dest}"
    chmod 600 "${dest}"
done

echo "Pruning dumps older than ${RETENTION_DAYS} days..."
find "${BACKUP_DIR}" -maxdepth 1 -name '*.sql.gz' -mtime +"${RETENTION_DAYS}" -print -delete

echo "Backup complete: $(ls -la "${BACKUP_DIR}"/*"${DATE}"*.sql.gz)"
