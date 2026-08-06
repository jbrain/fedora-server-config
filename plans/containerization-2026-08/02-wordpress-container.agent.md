# Task 02 — Containerize WordPress

> **Agent role:** You are a separate agent executing **Phase 2** of the plan in
> [README.md](README.md). Read the master plan first. **Prerequisite: Gate A is green** — the
> shared MariaDB container (Task 01) exists with a `wordpress` database and a least-privilege
> `wordpress` DB user on the `db-backend` Podman network. **Stop at Gate B and report.**

## Objective

Replace the native RPM WordPress (`/usr/share/wordpress`, served by Apache) with a **Podman
container** for WordPress, following the Ampache pattern, and point it at the **shared MariaDB
container** from Task 01. Apache remains the TLS front for now (Nginx is Task 04) — only the
WordPress backend changes.

## Current state to reconcile (verify live first)

- WordPress is an **RPM** at `/usr/share/wordpress`; `wordpress.conf` aliases `/wordpress` and
  the `jackson-brain.com` vhost `DocumentRoot` is `/var/www/vhosts/jackson-brain.com`. **These
  don't obviously line up** — determine the real docroot, where `wp-config.php` lives, and where
  `wp-content` (themes, plugins, **uploads**) actually resides on disk before migrating. Do not
  assume; inspect.
- WordPress DB is in system MariaDB (`wordpress` schema) → its data was copied into the shared
  DB during Task 01. Confirm it's current; if the site has taken writes since, re-sync the
  `wordpress` schema immediately before cutover.
- Note the PHP version in use and any required PHP extensions (the RPM stack + `php-fpm`).

## Design to implement

Create repo folder `wordpress/` mirroring `ampache/`:

- `docker-compose.yml` — service `wordpress` using the **official `wordpress:<pinned>` image**
  (Apache+PHP variant, or `wordpress:fpm` if you prefer Nginx-fpm later — but for parity and
  simplicity while Apache still fronts, the `wordpress:php8.x-apache` image published to
  `127.0.0.1:<port>` is simplest):
  - Publish to `127.0.0.1:8082:80` (or next free loopback port; check the port map).
  - Attach to the external `db-backend` network (shared DB) **and** a front network if needed.
  - `environment`: `WORDPRESS_DB_HOST=shared-mariadb`, `WORDPRESS_DB_NAME=wordpress`,
    `WORDPRESS_DB_USER=wordpress`, `WORDPRESS_DB_PASSWORD=${WORDPRESS_DB_PASSWORD}`,
    plus `WORDPRESS_TABLE_PREFIX` matching the existing DB (discover it — likely `wp_`, but the
    RPM may differ), and `WORDPRESS_CONFIG_EXTRA` for any custom constants (e.g. `WP_HOME`/
    `WP_SITEURL=https://jackson-brain.com`, `FORCE_SSL_ADMIN`, reverse-proxy HTTPS detection:
    `$_SERVER['HTTPS']='on'` when `X-Forwarded-Proto=https`).
  - Volumes (SELinux `:Z`): persist `wp-content` (themes/plugins/uploads) on `/storage`
    (e.g. `/storage/wordpress/wp-content:/var/www/html/wp-content:Z`). Migrate the existing
    `wp-content` (especially **uploads**) from the RPM path into this volume. Decide whether to
    let the image manage core files (recommended) and only bind-mount `wp-content`, or bind the
    whole docroot.
  - `restart: unless-stopped`, healthcheck (HTTP 200 on `/`), memory limit, json-file log caps.
- `.env.template` — `WORDPRESS_DB_PASSWORD` (must equal the value Task 01 set for the
  `wordpress` DB user).
- `wordpress.service` — systemd unit (rootful `podman compose up`), like `ampache.service`.
- `setup.sh` — create `/storage/wordpress/wp-content`, copy existing uploads/themes/plugins in
  with correct ownership (image uses `www-data` UID 33), install compose/env/unit, enable.
- `README.md` — deploy, migration of `wp-content`, reverse-proxy/HTTPS notes, troubleshooting
  (login redirect loops behind proxy, mixed-content, upload size `client_max_body_size`/PHP
  `upload_max_filesize`).

## Migration steps (on the server)

1. **Freeze/checkpoint:** take a fresh `wordpress` DB dump and a `tar` of the current
   `wp-content`/uploads to `/storage/backups/`.
2. Re-sync the `wordpress` schema into the shared DB if it changed since Task 01.
3. Deploy the WordPress container (backend only; **do not** touch the Apache vhost yet). Bring it
   up and smoke-test directly on `127.0.0.1:8082`.
4. Repoint Apache's `jackson-brain.com` vhost `DocumentRoot`/proxy: change it from serving the
   RPM files to **reverse-proxying `http://127.0.0.1:8082/`** (mirror the `musicbox` vhost proxy
   pattern; preserve `SetEnvIf Authorization`, CSP/headers, `X-Forwarded-Proto`). `apachectl
   configtest && systemctl reload httpd`.
5. Verify the site end-to-end via `https://jackson-brain.com` (Apache → WP container → shared DB).
6. **Disable** the native RPM WordPress path (stop serving `/usr/share/wordpress` files) but do
   **not** `dnf remove` the package yet (Gate E).

## Reverse-proxy / HTTPS correctness (important)

WordPress behind a TLS-terminating proxy commonly breaks with redirect loops or `http://` asset
URLs. Ensure:
- `WORDPRESS_CONFIG_EXTRA` sets `WP_HOME`/`WP_SITEURL` to `https://jackson-brain.com` and honors
  `X-Forwarded-Proto` (`if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $_SERVER['HTTPS']='on';`).
- The Apache (and later Nginx) vhost sends `X-Forwarded-Proto https`, `X-Forwarded-Host`,
  `X-Real-IP`.
- Upload limits aligned across proxy + PHP (`upload_max_filesize`, `post_max_size`,
  `client_max_body_size` when Nginx arrives in Task 04).

## Security checklist

- [ ] Container runs as non-root web user (image default `www-data`); `wp-content` perms minimal.
- [ ] DB user is the least-priv `wordpress` user (no root), password in `600` `.env`, not committed.
- [ ] Deny PHP execution in `wp-content/uploads` (carry over the existing `wordpress.conf` rule
      into the proxy/app config).
- [ ] Admin only over HTTPS (`FORCE_SSL_ADMIN`).
- [ ] Pinned image tag; auto-updates disabled/controlled.
- [ ] No secrets, no DB dumps committed.

> **fail2ban note:** a `[wordpress]` jail already exists on the host but is misconfigured (stale
> `iptables-multiport` action + no `logpath`, so it reads `/var/log/secure` and never matches).
> Its proper fix (point at the WordPress web access log, use the firewalld banaction) is done in
> **Task 04** once Nginx is fronting and its log paths exist — don't rewire it here while Apache
> still fronts. Just be aware it's currently ineffective, and keep the `ignoreip` LAN whitelist.

## Verification (Gate B checklist)

- [ ] `https://jackson-brain.com` loads (front page + a post) with correct HTTPS asset URLs.
- [ ] `/wp-admin` login works; no redirect loop.
- [ ] Media **upload** succeeds and displays (uploads volume persisted on `/storage`).
- [ ] Existing themes/plugins present and active; permalinks resolve.
- [ ] A new post/comment writes to the **shared DB** (confirm via `podman exec shared-mariadb`).
- [ ] TLS still served by Apache; other vhosts unaffected.
- [ ] Native RPM WordPress no longer serving, but package still installed (rollback).

## Deliverables

- New repo folder `wordpress/` (compose, `.env.template`, systemd unit, `setup.sh`, `README.md`).
- Updated `apache/vhosts/jackson-brain.com.conf` (now a reverse proxy to `127.0.0.1:8082`).
- Root `README.md` service table + repo memory updated.
- **Gate B status report.**

## Rollback

- Re-point the `jackson-brain.com` vhost back to the RPM docroot, `systemctl reload httpd`, stop
  the WordPress container. DB rollback per Task 01 (system MariaDB still holds `wordpress`).

**When done: STOP. Report Gate B results and wait for review before Task 03/04.**
