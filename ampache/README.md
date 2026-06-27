# Ampache Music Server

Ampache runs as two Docker containers managed by a single systemd unit. It shares the same music library as AirSonic (`/media/Music`) mounted read-only into the app container.

The primary reason for adding Ampache alongside AirSonic is **Music Assistant** (Home Assistant add-on) compatibility — Music Assistant supports the Ampache/Subsonic API for library browsing and streaming.

## URLs

- External: `https://musicbox.jackson-brain.com`
- Internal: `http://127.0.0.1:8081`

## Architecture

```
Home Assistant / Music Assistant
        │  Subsonic API
        ▼
https://musicbox.jackson-brain.com   (Let's Encrypt TLS)
        │
        ▼
Apache httpd (reverse proxy)
        │  http://127.0.0.1:8081
        ▼
Docker container: ampache (ampache/ampache:nosql7)
  ├── Apache2 + PHP   ← web server inside the container
  └── cron            ← DISABLE_INOTIFYWAIT_CLEAN=1
        │ TCP 3306 (Docker network)
        ▼
Docker container: ampache-mariadb (mariadb:lts)
        │
        ▼ read-only
/media/Music  (591 GB, on MegaRAID sdb1)
```

Both containers are defined in `/opt/ampache/docker-compose.yml` and started together by `systemctl start ampache`.

## Persistent Volumes

All data lives in `/opt/ampache/` on the host:

| Host path | Container | Container path | Purpose |
|---|---|---|---|
| `/media/Music` | `ampache` | `/media` | Music library (read-only) |
| `/opt/ampache/config` | `ampache` | `/var/www/config` | `ampache.cfg.php` |
| `/opt/ampache/log` | `ampache` | `/var/log/ampache` | Application logs |
| `/opt/ampache/mariadb` | `ampache-mariadb` | `/var/lib/mysql` | MariaDB data files |

## Deployment

### First-time setup

```bash
# Clone this repo to the server, then:
sudo bash ampache/setup.sh
```

`setup.sh` will:
1. Create `/opt/ampache/{config,mariadb,log}` with correct ownership
2. Install `docker-compose.yml` and `.env` template to `/opt/ampache/`
3. Install and enable the systemd service
4. Install the Apache vhost and reload Apache

### Set passwords before starting

```bash
sudo nano /opt/ampache/.env
```

Set strong values for all three variables (generate with `openssl rand -base64 24`):
```
MYSQL_ROOT_PASS=<strong-password>
MYSQL_PASS=<strong-password>
AMPACHE_ADMIN_PASSWORD=<strong-password>
```

### Start the service

```bash
sudo systemctl start ampache
# Wait for mariadb healthcheck to pass (~30s)
sudo docker ps
```

### Initialize the database (required — auto-installer is unreliable)

The built-in auto-installer often fails with "Database already exists" when the MariaDB container has pre-existing data. Use the CLI approach instead:

```bash
# Import the schema
sudo docker exec ampache sh -c \
  'mariadb -uampache -p"${AMPACHE_DB_PASSWORD}" -hmariadb ampache \
   < /var/www/resources/sql/ampache.sql'

# Create admin user (level 100 = superadmin)
sudo docker exec ampache php /var/www/bin/cli admin:addUser admin \
  --email admin@jackson-brain.com \
  --level 100 \
  --password "<AMPACHE_ADMIN_PASSWORD from .env>"

# Verify schema was created
sudo docker exec ampache-mariadb mariadb -uampache -p"${MYSQL_PASS}" \
  ampache -e "SHOW TABLES;" | wc -l
# Should return ~73 (72 tables + header)
```

### ampache.cfg.php

`setup.sh` pre-creates `/opt/ampache/config/ampache.cfg.php` from the `.dist` template so Ampache skips the web installer entirely. The key settings that must be correct:

```ini
database_hostname = "mariadb"
database_name     = "ampache"
database_username = "ampache"
database_password = "<MYSQL_PASS>"
local_web_path    = "https://musicbox.jackson-brain.com"
force_ssl         = "true"

; MUST be changed from the default — used to sign sessions and tokens
secret_key        = "<random 48-char hex: openssl rand -hex 24>"

; Secure cookies (required when force_ssl = true)
session_cookiesecure = 1

; In-memory query result cache — 2-3x speedup for large catalogs
memory_cache      = "true"
```

### Add music catalog

1. Log in at `https://musicbox.jackson-brain.com`
2. Go to **Admin → Add a Catalog**
3. Set path to `/media` (the container-internal mount point for `/media/Music`)
4. Trigger a full scan

### Create Music Assistant API user

1. Log in to Ampache at `https://musicbox.jackson-brain.com` as `admin`
2. Go to **Admin → Manage Users → Add User**; create a user named `music-assistant`, access level **User (25)**
3. Note the **API key** from that user's profile page (Admin → Manage Users → click username)

**Important:** Music Assistant's Subsonic provider uses token authentication — it computes `md5(password_you_enter + salt)`. Ampache verifies this token against `md5(apikey + salt)`, **not** against the account password. You must enter the **API key** (not the account password) as the password in Music Assistant.

4. In Music Assistant, add a **Subsonic** provider:
   - **Server URL**: `https://musicbox.jackson-brain.com`
   - **Username**: `music-assistant`
   - **Password**: *(the API key from step 3, not the account password)*

The API key can also be used as a plain `p=` parameter in direct Subsonic requests if needed.

## Day-to-day Operations

```bash
# Service management
sudo systemctl start ampache
sudo systemctl stop ampache
sudo systemctl restart ampache
sudo systemctl status ampache

# View logs
sudo docker logs -f ampache
sudo journalctl -u ampache -f

# Update image
cd /opt/ampache
sudo docker compose pull
sudo systemctl restart ampache

# Shell into container
sudo docker exec -it ampache bash
```

## Files in This Directory

| File | Deployed to |
|---|---|
| `docker-compose.yml` | `/opt/ampache/docker-compose.yml` |
| `.env.template` | `/opt/ampache/.env` (copy and edit) |
| `ampache.service` | `/etc/systemd/system/ampache.service` |
| `ampache.jackson-brain.com.conf` | `/etc/httpd/conf.d/vhosts/musicbox.jackson-brain.com.conf` |
| `setup.sh` | Run once as root |

## Troubleshooting

**`secret_key` still default:**
Ampache's `.dist` template ships `secret_key = "abcdefghijklmnoprqstuvwyz0123456"`. This MUST be replaced before first login:
```bash
new_key=$(openssl rand -hex 24)
sudo sed -i "s|^secret_key = \"abcdefghijklmnoprqstuvwyz0123456\"|secret_key = \"${new_key}\"|" /opt/ampache/config/ampache.cfg.php
```

**Memcached not available in `nosql7` image:**
The host's memcached daemon is running, but `ampache/ampache:nosql7` does not include the PHP `memcached` extension (`php -m` confirms). To enable memcached caching, a custom image would be needed. As an alternative, `memory_cache = "true"` in `ampache.cfg.php` enables Ampache's in-process PHP object cache (no extension required) and provides a similar benefit for catalog browsing.

**Subsonic API streams return 404:**
Set `local_web_path` and `force_ssl` in `ampache.cfg.php` — see post-install section above.

**Container fails to start (DB errors):**
Check that `/opt/ampache/mariadb` is not corrupt. For a fresh start:
```bash
sudo systemctl stop ampache
sudo rm -rf /opt/ampache/mariadb /opt/ampache/config
sudo mkdir -p /opt/ampache/{mariadb,config,log}
sudo chown 33:33 /opt/ampache/config /opt/ampache/log
sudo systemctl start ampache
# Then re-run the database initialization steps above
```

**"Unable to query the database" on first visit:**
This means the database schema (tables) have not been created yet. The web installer at `/install.php` may also fail with "Database already exists". Use the CLI init procedure in the First-time Setup section above instead.

**inotifywait clean removing catalog entries:**
`DISABLE_INOTIFYWAIT_CLEAN=1` is already set in `docker-compose.yml`. This is important because the `/media/Music` bind mount is on a RAID volume — if the mount is briefly unavailable, we do not want Ampache to delete the catalog.

**Permission denied on config or log dirs:**
The container's web server runs as UID/GID 33 (`www-data`):
```bash
sudo chown -R 33:33 /opt/ampache/config /opt/ampache/log
```
