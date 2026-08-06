# Shared MariaDB (`database/`)

A single, secure `mariadb:lts` Podman container (`shared-mariadb`) hosting three databases —
`wordpress`, `airsonic`, `ampache` — each with its own least-privilege user, on an
**internal-only** Podman network (`db-backend`, no published host port). Deployed to
`/opt/shared-mariadb` on the server; data + backups live on `/storage` (not `/`, which is
tight on space). Part of the [containerization plan](../plans/containerization-2026-08/README.md)
(Phase 1 / Task 01 — Gate A).

## Why one shared container

Before this, three real apps persisted to two separate places:
- **System MariaDB 10.11** (`127.0.0.1:3306`, native) hosted `wordpress` and `airsonic`.
- **Ampache** had its own private `ampache-mariadb` container (see [ampache/](../ampache/)).

Consolidating gives every app one final DB endpoint, one backup/restore story, and lets
Nginx (Task 04) and future work not have to reason about two different DB stacks.
**phpMyAdmin is intentionally not part of this** (owner decision) — admin is via
`podman exec` / CLI only.

## Deploy

```bash
cd database
sudo bash setup.sh          # first run: creates .env, tells you to fill in passwords, exits
# edit /opt/shared-mariadb/.env — set MARIADB_ROOT_PASSWORD, WORDPRESS_DB_PASSWORD,
# AIRSONIC_DB_PASSWORD, AMPACHE_DB_PASSWORD (e.g. `openssl rand -base64 24` each)
sudo bash setup.sh          # second run: renders initdb.d/01-databases.sql, installs units
sudo systemctl start shared-mariadb
sudo podman ps --filter name=shared-mariadb   # wait for "healthy"
```

The init SQL only runs on a **truly empty** data directory (MariaDB entrypoint convention).
For a fresh install with no existing data, that's it — `wordpress`/`airsonic`/`ampache`
schemas + least-privilege users now exist, empty, ready for each app to point at.

For migrating **existing** data from the system MariaDB + `ampache-mariadb`, follow the
runbook below instead of skipping straight to pointing apps at the new container.

## Migration runbook (existing data → shared container)

Do this during a low-traffic window. **Do not delete any source data** — it's the rollback
until Gate E of the master plan.

1. **Backup sources first** (outside the container, from the app-specific credentials — no
   root password needed for any of these, each app's own DB user has full rights on its own
   schema):
   ```bash
   mkdir -p /storage/backups/mysql
   mariadb-dump -h localhost -u wordpress -p'<wp pw>' --single-transaction wordpress \
     | gzip > /storage/backups/mysql/pre-migrate-wordpress-$(date +%F).sql.gz
   mariadb-dump -h 127.0.0.1 -u airsonic -p'<airsonic pw>' --single-transaction airsonic \
     | gzip > /storage/backups/mysql/pre-migrate-airsonic-$(date +%F).sql.gz
   sudo podman exec ampache-mariadb sh -c \
     'mariadb-dump --single-transaction -uroot -p"$MARIADB_ROOT_PASSWORD" ampache' \
     | gzip > /storage/backups/mysql/pre-migrate-ampache-$(date +%F).sql.gz
   ```
   (WordPress DB creds: `/etc/wordpress/wp-config.php`. Airsonic: `/var/airsonic/airsonic.properties`
   — note it connects as `airsonic@127.0.0.1`, **not** `@localhost`, over TCP. Ampache: its own
   `MARIADB_ROOT_PASSWORD` env inside `ampache-mariadb`.)

2. **Deploy the shared container empty** (per Deploy above) — it initializes the three empty
   schemas + users.

3. **Import each dump** into the shared container:
   ```bash
   for db in wordpress airsonic ampache; do
     gunzip -c /storage/backups/mysql/pre-migrate-${db}-<date>.sql.gz \
       | sudo podman exec -i shared-mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "${db}"
   done
   sudo podman exec shared-mariadb mariadb-upgrade -uroot -p"$MARIADB_ROOT_PASSWORD" --all-databases
   ```
   (System MariaDB was 10.11, `ampache-mariadb`/`shared-mariadb` are `mariadb:lts` = 12.3.x —
   forward import is fine; `mariadb-upgrade` reconciles system tables/collations.)

4. **Repoint Ampache** (the only consumer that fully cuts over in this phase):
   - Edit `ampache/docker-compose.yml` — remove the `mariadb` service + its `depends_on`,
     change `DB_HOST`/`AMPACHE_DB_USER`/`AMPACHE_DB_PASSWORD` to the shared DB (`DB_HOST=shared-mariadb`,
     same `ampache` user/password created in step 2, matching `AMPACHE_DB_PASSWORD` in
     `database/.env`), attach the `ampache` service to the external `db-backend` network,
     and keep the existing `extra_hosts` hairpin-NAT fix (see ampache/README.md).
   - `sudo systemctl restart ampache`.
   - Verify: `curl 'http://127.0.0.1:8081/rest/ping.view?...'` → `{"status":"ok"}`, and
     `/rest/stream.view` → HTTP 200 with real audio.
   - **Leave `/opt/ampache/mariadb` on disk** — do not delete (rollback until Gate E).

5. **WordPress and Airsonic stay on the system MariaDB for now.** Their data was copied into
   the shared container in step 3 for integrity verification, but their live DB pointer
   (`wp-config.php` / `airsonic.properties`) is **not** changed here — that's Task 02 (WordPress
   container) and Task 03 (Airsonic container) respectively, once each app joins the
   `db-backend` network as part of its own containerization. Do **not** shut down the system
   `mariadb.service` in this phase.

6. **Backups + restore test (required for Gate A):**
   ```bash
   sudo /opt/shared-mariadb/backup/mariadb-backup.sh   # run once manually
   ls -la /storage/backups/mysql                        # confirm dumps landed
   # Restore test into a scratch DB, then drop it:
   sudo podman exec shared-mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" \
     -e "CREATE DATABASE restore_test;"
   gunzip -c /storage/backups/mysql/wordpress-<date>.sql.gz \
     | sudo podman exec -i shared-mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" restore_test
   sudo podman exec shared-mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" \
     -e "SELECT COUNT(*) FROM restore_test.wp_posts; DROP DATABASE restore_test;"
   ```

## Performance review & tuning

Measured on the live server (2026-08-03), via each app's own least-privilege DB user —
no root password needed:

| Schema | Size (data+index) |
|---|---|
| `wordpress` | 11.3 MB |
| `airsonic` | 53.3 MB |
| `ampache` | 2110.8 MB (dominated by the music library's song/art metadata) |
| **Total** | **~2.13 GB** |

Host: 16 GB RAM total, ~9.4 GB available at measurement time (other consumers: Airsonic's
JVM heap ~700 MB, the `ampache`/`ampache-mariadb` containers at 512 MB each, Netdata, etc.).

Chosen tuning (`conf.d/z-tuning.cnf`), sized to the **measured working set**, not a blind
"70–80% of RAM" rule (this host runs many other services on the same 16 GB):

- `innodb_buffer_pool_size = 2304M` — comfortably above the measured ~2.13 GB so the entire
  working set can be cached in RAM, with room for catalog growth (new music, new posts).
- Container `deploy.resources.limits.memory: 3072M` (docker-compose.yml) — kept above the
  buffer pool for connection/thread buffers, temp tables, and OS overhead.
- `innodb_log_file_size = 512M` (~25% of the buffer pool — verified via
  `mariadbd --verbose --help` that this build (12.3.2) still uses this parameter, not
  `innodb_redo_log_capacity`, which doesn't exist in this image).
- `innodb_flush_method = O_DIRECT`, `innodb_flush_log_at_trx_commit = 1` (durable; no
  measured need yet to trade durability for write throughput).
- `max_connections = 150` — sum of realistic per-app pools (WordPress php-fpm workers +
  Airsonic pool + Ampache) with headroom, without ballooning per-thread buffer memory.
- `character-set-server = utf8mb4` / `collation-server = utf8mb4_unicode_ci`.
- `skip-name-resolve` — apps connect by container name/IP over `db-backend`, not hostname;
  avoids reverse-DNS connection stalls.
- `slow_query_log` enabled (`long_query_time = 1`) inside the data volume, for the
  post-migration re-measure pass below.

**Re-measure after load** (once Ampache — and later WordPress/Airsonic — have driven real
traffic): `SHOW ENGINE INNODB STATUS`, `SHOW GLOBAL STATUS LIKE 'Threads_connected'`,
`'Aborted_connects'`, buffer-pool hit rate, and optionally a read-only `mysqltuner` pass.
Record findings here as an update to this section.

## Backup / restore

- **Automated:** `shared-mariadb-backup.timer` runs `backup/mariadb-backup.sh` daily at
  03:30 (±15 min jitter). Dumps each of the three schemas separately (`--single-transaction`,
  gzip) to `/storage/backups/mysql/<db>-<date>.sql.gz`, `600` perms, 14-day retention.
- **Manual run:** `sudo /opt/shared-mariadb/backup/mariadb-backup.sh`
- **Restore a single DB:**
  ```bash
  gunzip -c /storage/backups/mysql/<db>-<date>.sql.gz \
    | sudo podman exec -i shared-mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" <db>
  ```
- Always test-restore into a scratch DB first when validating a backup (see migration
  runbook step 6) rather than restoring directly over a live schema.

## Security notes

- No `ports:` published by the container — reachable only via the internal `db-backend`
  Podman network. If temporary host access is ever needed mid-migration, prefer
  `podman exec -i shared-mariadb mariadb ...` over publishing a port; if a port is
  unavoidable, bind it to `127.0.0.1:3307` and remove it again before Gate A.
- Each app has its own DB user, scoped with `GRANT ALL PRIVILEGES` on **its own schema
  only** — no app uses `root`, and no user has cross-schema access.
- `.env` is `600`, real passwords never committed — only `.env.template` with placeholders.
- Data dir `/storage/mariadb/data` is `750`; backups dir is `700`.
- phpMyAdmin dropped entirely — no browser-based DB admin surface exists for this container.

## Troubleshooting

- **`ERROR 1045 Access denied for user 'root'@'localhost' (using password: NO)`** on the
  *system* MariaDB: the pre-existing root account there requires a password (not
  unix_socket auth) and no root password was documented/found on this host. Not a blocker —
  each app's own DB user (from its config file) has full rights on its own schema, which is
  all that's needed for pre-flight sizing and backups.
- **`airsonic` user connects via `127.0.0.1`, not `localhost`**: its JDBC URL
  (`jdbc:mariadb://localhost:3306/airsonic`) resolves via TCP, so its grant is
  `airsonic@127.0.0.1`. The `mariadb` CLI treats the literal string `localhost` specially
  (Unix socket) — use `-h 127.0.0.1` for this particular user or you'll get an unrelated
  "access denied" that looks like a wrong password.
- **`ampache.service`'s `After=` still references `shorewall.service`**: harmless (a no-op
  dependency on a unit that no longer exists post-firewalld-migration) but worth cleaning up
  next time that unit file is touched; not addressed here since it's out of this task's scope.
