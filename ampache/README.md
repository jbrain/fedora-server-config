# Ampache Music Server

Ampache runs as two Podman containers managed by a single systemd unit. It shares the same music library as AirSonic (`/media/Music`) mounted read-only into the app container.

The primary reason for adding Ampache alongside AirSonic is **Music Assistant** (Home Assistant add-on) compatibility — Music Assistant supports the Ampache/Subsonic API for library browsing and streaming.

> **Runtime note:** Ampache was originally deployed on Docker (`moby-engine`) and was migrated to
> **Podman** (rootful, system-wide `podman.socket`) on 2026-07-16 so containers can be viewed and
> managed through **Cockpit** (`cockpit-podman`) at `https://192.168.88.251:9090`. The same
> `docker-compose.yml` is reused unchanged — `podman compose` delegates to the `docker-compose`
> CLI as its compose provider, which talks to Podman's Docker-API-compatible socket
> (`/run/podman/podman.sock`) instead of `dockerd`. **Docker (`moby-engine`, `docker-cli`,
> `containerd`, etc.) was fully uninstalled from this host on 2026-07-16** once nothing else
> needed it — Ampache/Podman does not depend on Docker being present at all.
>
> The host's firewall was also migrated from Shorewall to **firewalld** the same day (see
> [shorewall/README.md](../shorewall/README.md)), which removes the need for a manually
> maintained Docker/Podman bridge-glob zone entirely — firewalld's netavark integration handles
> Podman's bridge/NAT rules automatically.

### Docker → Podman migration checklist

The 2026-07-16 migration surfaced several issues that only appeared under Podman despite reusing
the exact same `docker-compose.yml`. If this stack (or another one) is ever migrated between
container runtimes again, check all of these — none of them throw an obvious error, they just
silently break one specific feature:

1. **Inbound connectivity to the published port** — under Shorewall (superseded 2026-07-16, see
   [shorewall/README.md](../shorewall/README.md)), the `dock` zone needed an `interfaces` entry
   matching the new runtime's bridge naming (`podman+`, not just Docker's `br-+`/`docker+`), or
   connections to the published port would time out (not refused) even though `ss -tlnp` showed
   the host-side listener up. This whole class of bug goes away under **firewalld**, which
   integrates with Podman's netavark backend natively — no manual zone/interface config needed.
2. **Outbound self-referential (hairpin NAT) calls** — any app that calls back to its own public
   hostname (streaming URL generation, webhooks, etc.) needs an `extra_hosts` override pinning
   that hostname to the LAN IP; see the Networking note and Troubleshooting section below.
3. **SELinux volume labels** — check `ls -laZ` on every bind-mounted host directory. Podman
   (unlike this host's Docker setup) actively relabels bind mounts per the `:z`/`:Z` compose
   flag; a directory holding `container_file_t:s0` with NO MCS category (vs. `s0:cNNN,cMMM` on
   correctly-relabeled ones) may or may not still be readable depending on the specific
   container's context — worth a quick check even if things look like they're working, since it
   was a red herring here but easily could not have been.
4. **CLI/exec ergonomics are unaffected** — `podman exec`/`podman logs`/`podman ps` are drop-in
   compatible with their `docker` equivalents for this stack; no command syntax changes needed.

## URLs

- External: `https://musicbox.jackson-brain.com`
- Internal: `http://127.0.0.1:8081`
- Container management UI: `https://192.168.88.251:9090` (Cockpit → Podman containers)

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
Podman container: ampache (ampache/ampache:nosql7)
  ├── Apache2 + PHP   ← web server inside the container
  └── cron            ← DISABLE_INOTIFYWAIT_CLEAN=1
        │ TCP 3306 (`db-backend` external Podman network)
        ▼
Podman container: shared-mariadb (mariadb:lts) — see ../database/
        │
        ▼ read-only
/media/Music  (591 GB, on MegaRAID sdb1)
```

> **DB note (2026-08):** Ampache's database moved from its own private `ampache-mariadb`
> container into the **shared MariaDB container** (`../database/`), which also hosts the
> `wordpress` and `airsonic` schemas. See [../database/README.md](../database/README.md) for
> the container itself, migration runbook, backups, and tuning. The `ampache` service joins
> the external `db-backend` Podman network to reach it (`DB_HOST=shared-mariadb`).

The `ampache` container is defined in `/opt/ampache/docker-compose.yml` and started by
`systemctl start ampache`, which runs `podman compose up` (rootful — the systemd unit runs as
root, so it automatically uses the system-wide `/run/podman/podman.sock`, not a per-user
rootless socket). The shared MariaDB container is managed independently by
`shared-mariadb.service` (see `../database/`) and must be running first.

> **Networking note:** the `ampache` service has an `extra_hosts` entry pinning
> `musicbox.jackson-brain.com` to the LAN IP (`192.168.88.251`). This is required because
> Ampache's stream handler makes a server-side call back to its own public hostname
> (`local_web_path`) while serving `/rest/stream.view`. Without the override, that call has to
> round-trip out through the WAN/ISP router and back in (NAT hairpin/loopback), which hung
> indefinitely under Podman's networking (see Troubleshooting below) even though it apparently
> worked under Docker. Do not remove this entry.

## Persistent Volumes

All data lives in `/opt/ampache/` on the host:

| Host path | Container | Container path | Purpose |
|---|---|---|---|
| `/media/Music` | `ampache` | `/media` | Music library (read-only) |
| `/opt/ampache/config` | `ampache` | `/var/www/config` | `ampache.cfg.php` |
| `/opt/ampache/log` | `ampache` | `/var/log/ampache` | Application logs |

MariaDB data now lives under `/storage/mariadb/data` (shared container, see `../database/`).
`/opt/ampache/mariadb` is kept on disk as the pre-migration rollback copy until the master
plan's Gate E — do not delete it before then.

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
5. Install the custom `jackbrain` theme + branding assets to `/opt/ampache/{themes,assets}`
   (bind-mounted into the container — see `themes/README.md` for what this theme is and why)

### Custom theme + branding (jackbrain)

The site uses a custom theme (`themes/jackbrain/`) and branding assets (`assets/`), both
bind-mounted into the container by `docker-compose.yml` — they survive container recreates
and image updates on their own (see `themes/README.md` for the full research/rationale).
The theme/assets files alone aren't enough, though: the site title, logo, and favicon are
wired up via **database preferences**, not theme files (see `themes/README.md` for why).
After a **fresh install or a database restore from an older backup**, reapply them with:

```bash
sudo sh -c 'source /opt/shared-mariadb/.env && podman exec -i \
  -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" shared-mariadb mariadb -u root ampache \
  < ampache/theme-preferences.sql'
```

This is idempotent — safe to re-run any time to confirm/restore the expected values.

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
sudo podman ps
```

### Initialize the database (required — auto-installer is unreliable)

The built-in auto-installer often fails with "Database already exists" when the MariaDB container has pre-existing data. Use the CLI approach instead:

```bash
# Import the schema
sudo podman exec ampache sh -c \
  'mariadb -uampache -p"${AMPACHE_DB_PASSWORD}" -hmariadb ampache \
   < /var/www/resources/sql/ampache.sql'

# Create admin user (level 100 = superadmin)
sudo podman exec ampache php /var/www/bin/cli admin:addUser admin \
  --email admin@jackson-brain.com \
  --level 100 \
  --password "<AMPACHE_ADMIN_PASSWORD from .env>"

# Verify schema was created
sudo podman exec shared-mariadb mariadb -uampache -p"${MYSQL_PASS}" \
  ampache -e "SHOW TABLES;" | wc -l
# Should return ~73 (72 tables + header)
```

**CLI gotchas:**
- Only `admin:addUser` accepts `--password`. It FAILS with "User creation failed" (no overwrite)
  if the username already exists.
- `admin:updateUser` does **not** accept a `--password` flag at all ("Option \"--password\" not
  registered"), despite being the obvious command for resetting an existing user's password.
  To reset a password: delete the row first, then re-run `admin:addUser`:
  ```bash
  sudo podman exec shared-mariadb mariadb -uampache -p"${MYSQL_PASS}" ampache \
    -e "DELETE FROM user WHERE username='<user>';"
  sudo podman exec ampache php /var/www/bin/cli admin:addUser <user> --email <email> --level <level> --password '<password>'
  ```
- Get the full CLI command list with a bare `sudo podman exec ampache php /var/www/bin/cli` (no args).

### ampache.cfg.php

`setup.sh` pre-creates `/opt/ampache/config/ampache.cfg.php` from the `.dist` template so Ampache skips the web installer entirely. The key settings that must be correct:

```ini
database_hostname = "shared-mariadb"
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

**Note:** this Ampache image (7.9.8/8.0.0, `nosql7`) implements the Subsonic-compatible API under
the standard OpenSubsonic path `/rest/*.view` (e.g. `/rest/ping.view`) — NOT the older
`/server/subsonic.view` path referenced in some older Ampache docs. Music Assistant's Subsonic
provider targets `/rest/...` itself; you only ever configure the base server URL, not the path.
To sanity-check credentials directly: `curl "http://127.0.0.1:8081/rest/ping.view?u=<user>&p=<apikey>&v=1.16.1&c=diag&f=json"`
should return `"status": "ok"`.

**If Music Assistant (or any Subsonic client) suddenly can't authenticate after no config change
on the client side:** the user's `apikey` in the `user` table may have been regenerated (e.g. by a
database reinit/reinstall). Re-fetch the current key
(`SELECT apikey FROM user WHERE username='...'`, see below) and re-enter it as the client's
password — the account/username stays the same, only the key value changes.

## Day-to-day Operations

```bash
# Service management
sudo systemctl start ampache
sudo systemctl stop ampache
sudo systemctl restart ampache
sudo systemctl status ampache

# View logs
sudo podman logs -f ampache
sudo journalctl -u ampache -f

# Update image
cd /opt/ampache
sudo podman compose pull
sudo systemctl restart ampache

# Shell into container
sudo podman exec -it ampache bash

# Or manage/inspect visually via Cockpit
# https://192.168.88.251:9090 → Podman containers
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
Set `local_web_path` and `force_ssl` in `ampache.cfg.php` — see post-install section above. Also
double check you're hitting `/rest/*.view`, not the older `/server/subsonic.view` path (see note
under "Create Music Assistant API user" above).

**Every `/rest/*.view` request (ping, stream, everything) returns the literal body `Disabled`
with HTTP 200 (not JSON, not an error) — nothing plays, but nothing looks like a clear failure
either:**
This means the `subsonic_backend` config key is off. `SubsonicApiApplication::run()` checks
`AmpConfig::get('subsonic_backend')` as the very first thing it does, **before** `Preference::init()`
ever runs — so it only ever sees the value from `ampache.cfg.php`, never the `subsonic_backend`
row in the `user_preference` DB table (the one shown/toggled in the admin UI's Preferences page).
A DB preference showing enabled (`SELECT value FROM user_preference WHERE preference=(SELECT id
FROM preference WHERE name='subsonic_backend')` returning `1`) does **not** mean the API is
actually enabled — check the cfg.php file, not the DB, when diagnosing this. Ampache's own
`.dist` template doesn't even mention this key, so a fresh install via `setup.sh`'s old behavior
(extracting `.dist` verbatim) silently shipped with the Subsonic API disabled; `setup.sh` now
appends `subsonic_backend = "true"` automatically. To fix an existing deployment:
```bash
echo 'subsonic_backend = "true"' | sudo tee -a /opt/ampache/config/ampache.cfg.php
sudo systemctl restart ampache
curl -s "http://127.0.0.1:8081/rest/ping.view?u=<user>&p=<apikey>&v=1.16.1&c=diag&f=json"
# should return {"subsonic-response":{"status":"ok",...}}, not the bare word "Disabled"
```

**Streaming hangs forever (auth/search/images all work fine, only `/rest/stream.view` never
responds):**
This is the NAT hairpin/loopback issue described in the Networking note above. Diagnostic
signature: `curl "http://127.0.0.1:8081/rest/stream.view?..."` returns `HTTP:000` (no response at
all, only resolved by curl's own `--max-time`); `sudo podman exec ampache ss -tnp` during the hang
shows a stuck `SYN-SENT` connection from the container to your server's own public IP on port 443;
`sudo tcpdump -i eno1 -n host <public-ip>` shows the SYN never actually leaving the WAN interface.
Ruled out along the way (don't re-check these first): `/media/Music` file permissions/SELinux
(host and in-container `dd` reads are both instant), the Last.fm scrobble plugin (clearing
`lastfm_api_key` alone does not fix it), Shorewall's `dock` zone policy (already `ACCEPT`).
**Fix:** the `extra_hosts` entry in `docker-compose.yml` (see Networking note above) — pin your
public hostname to its LAN IP so the self-call never leaves the network. After editing
`docker-compose.yml`, redeploy with:
```bash
sudo systemctl restart ampache
# wait ~30-40s for the mariadb healthcheck, then verify:
sudo podman exec ampache getent hosts musicbox.jackson-brain.com   # should print the LAN IP
curl -s -o /dev/null -w "%{http_code}\n" --max-time 10 \
  "http://127.0.0.1:8081/rest/stream.view?u=<user>&p=<apikey>&v=1.16.1&c=diag&id=<song-id>"
# should be 200, not a timeout
```

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

**Every request suddenly takes a fixed ~10 seconds (root page, `musicbox` proxy, everything —
but it always eventually returns the correct response, never a true timeout):**
Observed once, immediately after removing Docker and Shorewall from the host in the same
session while the Ampache/mariadb containers kept running the whole time (never restarted).
`sudo podman exec ampache getent hosts github.com` showed the same ~10s delay, while `dig
@10.89.0.1 github.com` run directly on the host resolved in ~13ms — DNS forwarding through
aardvark-dns was structurally fine, but something in the container's request/exec path was
eating a fixed ~10s. Extensive live diagnosis (netavark's own nftables masquerade rules,
firewalld zone `masquerade: no` — a red herring, netavark manages its own independent NAT
table) did not turn up a root cause. **A full host reboot completely fixed it** — every
request immediately dropped to ~30-40ms and stayed there. Root cause was never conclusively
identified but is suspected to be a stale netavark/aardvark-dns process, conntrack entry, or
network-namespace artifact left over from live-removing Docker (which shares netfilter
hooks/tables with Podman's netavark) without ever restarting the Podman containers or
rebooting. **Lesson:** after removing Docker or making other live netfilter/network-stack
changes on a host with Podman containers that must keep working, restart those containers
(`sudo systemctl restart ampache`) or reboot the host — don't rely on a live "it still returns
the right status code" check, since a consistent multi-second per-request delay is a real
symptom even when the end result eventually succeeds.
